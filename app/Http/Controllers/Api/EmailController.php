<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Mail\ReplyMail;
use App\Models\DeliveryCampaign;
use App\Models\DeliverySendHistory;
use App\Models\Email;
use App\Models\GmailToken;
use App\Models\RescoreJob;
use App\Services\GmailService;
use App\Support\TenantExistsRule;
use App\Traits\UsesSenderDisplayName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;
class EmailController extends Controller
{
    use UsesSenderDisplayName;

    public function __construct(
        private GmailService $gmailService,
    ) {}

    #[OA\Get(
        path: '/api/v1/emails',
        summary: 'メール一覧取得',
        security: [['bearerAuth' => []]],
        tags: ['Emails'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, description: '件名・送信者・本文で検索', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'unread', in: 'query', required: false, description: '未読のみ', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'category', in: 'query', required: false, description: 'カテゴリ', schema: new OA\Schema(type: 'string', enum: ['engineer', 'project', 'other'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: '1ページ件数', schema: new OA\Schema(type: 'integer', default: 30)),
        ],
        responses: [
            new OA\Response(response: 200, description: '成功'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    // メール一覧
    public function index(Request $request)
    {
        $perPage    = $request->integer('per_page', 30);
        $search     = $request->input('search');
        $searchBody = $request->boolean('search_body');
        $unread     = $request->boolean('unread');
        $category   = $request->input('category');   // engineer / project / null
        // 本文検索ガード: pg_trgm GIN index は trigram (3-char window) ベースのため、
        // 検索語が 3 文字未満だと index が物理的に使えず Seq Scan で全件走査となる
        // (本案件で日本語 2 文字検索が 14-98 秒の暴走を観測)。subject/from 検索は維持。
        if ($searchBody && mb_strlen((string) $search) < 3) {
            $searchBody = false;
        }
        $query = Email::query()
            ->orderBy('received_at', 'desc');
        if ($search) {
            $query->where(function ($q) use ($search, $searchBody) {
                // PostgreSQL では ilike で大文字小文字を区別しない部分一致（他コントローラと統一）
                $q->where('subject', 'ilike', "%{$search}%")
                  ->orWhere('from_address', 'ilike', "%{$search}%")
                  ->orWhere('from_name', 'ilike', "%{$search}%");
                if ($searchBody) {
                    $q->orWhere('body_text', 'ilike', "%{$search}%");
                }
            });
        }
        if ($unread) {
            $query->where('is_read', false);
        }
        // 自社/他社スコープ（営業打ち合わせ 2026-05-25 §要望1）。
        // 担当者タブ: to_address の aizen ローカル部 = 担当者、outsource@ は除外 (個別宛のみ)。
        // 全自社タブ: category='self' (Kagoya 取込の isSelfFrom / isInternalTo で判定済) を採用。
        //   これにより outsource@ → outsource@ の loop-back コピー (自社が配信した案件メールが
        //   戻ってきたもの) も「全自社」で見える ([[project_self_loopback_visibility]] 2026-06-01)。
        $selfOwner = preg_replace('/[^A-Za-z0-9._\-]/', '', (string) $request->input('self_owner')); // 自社の特定担当者
        $mailScope = $request->input('mail_scope'); // 'self'(自社全担当者) | 'customer'(他社)
        if ($selfOwner !== '') {
            // selfOwners と同一定義に揃える: to の先頭 aizen ローカル部 = 担当者（outsource@ も含む）。
            // 2026-06-02: 自社タブが category='self' ベースに変わり outsource@ loop-back が見えるように
            // なったため、ドロップダウンでも outsource を選んで絞れるよう除外を撤回 ([[project_self_loopback_visibility]]).
            $query->whereRaw("lower(substring(to_address from '([A-Za-z0-9._%+\\-]+)@aizen-sol\\.co\\.jp')) = ?", [mb_strtolower($selfOwner)]);
        } elseif ($mailScope === 'self') {
            $query->where('category', 'self');
        }
        // 自社ビューでは spam（subject 前置 "[spam]"）を除外（営業打ち合わせ §要望1）
        if ($selfOwner !== '' || $mailScope === 'self') {
            $query->where(function ($q) {
                $q->where('subject', 'not ilike', '[spam]%')->orWhereNull('subject');
            });
        }

        if ($category) {
            $query->where('category', $category);
        } else {
            // カテゴリ未指定時は bounce / 手動登録 (manual_project, manual_engineer) を暗黙除外。
            // manual_* は /project-mails と /engineer-mails の手動登録フローで作られるダミー emails 行で、
            // /emails の通常リストには載せない (検索ノイズ削減・E-3)。
            $query->where(function ($q) {
                $q->whereNotIn('category', ['bounce', 'manual_project', 'manual_engineer'])
                  ->orWhereNull('category');
            });
        }
        $paginator = $query->withCount('attachments')->paginate($perPage);

        // 自社スコープ時のみ、各メールがどの一斉配信(delivery_campaigns)への返信か逆引きして注入。
        // DeliverySendHistory.reply_email_id = email.id を whereIn 一括取得(N+1 回避・30件/page なので軽量)。
        // /emails 自社タブのバッジ表示用 (営業打ち合わせ 2026-05-25 派生機能)。
        if ($selfOwner !== '' || $mailScope === 'self') {
            $ids = collect($paginator->items())->pluck('id')->all();
            if (!empty($ids)) {
                $replyMap = DeliverySendHistory::whereIn('reply_email_id', $ids)
                    ->whereNotNull('reply_email_id')
                    ->with('campaign:id,subject,sent_at')
                    ->get()
                    ->keyBy('reply_email_id');
                foreach ($paginator->items() as $em) {
                    $h = $replyMap->get($em->id);
                    $em->reply_to_campaign_id      = $h?->campaign?->id;
                    $em->reply_to_campaign_subject = $h?->campaign?->subject;
                }
            }
        }

        return response()->json($paginator);
    }

    // 自社メールの担当者(to のローカル部)一覧 + 件数。
    // 自社 = to_address が当社 xxx@aizen-sol.co.jp（outsource@ catch-all も「担当者」の1つとして含める）。
    // 2026-06-02: 自社タブが category='self' ベースに変わり outsource@ loop-back が一覧に乗る
    // ようになったため、ドロップダウン側でも outsource を選んで絞れるよう除外を撤回。
    // spam（subject 前置 "[spam]"）は除外。/emails の自社モード専用（営業打ち合わせ 2026-05-25 §要望1）。
    public function selfOwners()
    {
        $owners = Email::where('to_address', 'ilike', '%@aizen-sol.co.jp%')
            ->where(function ($q) {
                $q->where('subject', 'not ilike', '[spam]%')->orWhereNull('subject');
            })
            ->selectRaw("lower(substring(to_address from '([A-Za-z0-9._%+\\-]+)@aizen-sol\\.co\\.jp')) as owner, count(*) as count")
            ->groupByRaw('1')
            ->orderByDesc('count')
            ->get()
            ->filter(fn ($r) => !empty($r->owner))
            ->values();
        return response()->json(['owners' => $owners]);
    }

    #[OA\Get(
        path: '/api/v1/emails/{id}',
        summary: 'メール詳細取得（自動既読）',
        security: [['bearerAuth' => []]],
        tags: ['Emails'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'メールID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: '成功'),
            new OA\Response(response: 404, description: '見つかりません'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    // メール詳細（自動既読）
    public function show(int $id)
    {
        $email = Email::with(['contact', 'deal', 'customer', 'attachments'])
            ->findOrFail($id);

        if (!$email->is_read) {
            $email->update(['is_read' => true]);
            // Gmail既読はレスポンス後に非同期実行
            defer(function () use ($email) {
                $token = GmailToken::where('tenant_id', $email->tenant_id)->first();
                if ($token && !str_starts_with($email->gmail_message_id, 'imap-')) {
                    try {
                        $this->gmailService->markAsRead($token, $email->gmail_message_id);
                    } catch (\Exception $e) {
                        Log::warning("markAsRead失敗: " . $e->getMessage());
                    }
                }
            });
        }

        return response()->json($email);
    }

    #[OA\Delete(
        path: '/api/v1/emails/{id}',
        summary: 'メール削除（関連レコードも一緒に削除）',
        security: [['bearerAuth' => []]],
        tags: ['Emails'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: '削除成功'),
            new OA\Response(response: 404, description: '見つかりません'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    public function destroy(int $id)
    {
        $email = Email::findOrFail($id);
        $this->ensureSameTenantForDestructive($email);

        DB::transaction(function () use ($email) {
            // 関連: スコアリング/ソース系
            \App\Models\EngineerMailSource::where('email_id', $email->id)->delete();
            \App\Models\ProjectMailSource::where('email_id', $email->id)->delete();
            // 関連: 添付
            $email->attachments()->delete();
            // 本体
            $email->delete();
        });

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/emails/sync',
        summary: 'Gmailから最新メールを同期',
        security: [['bearerAuth' => []]],
        tags: ['Emails'],
        responses: [
            new OA\Response(response: 200, description: '同期成功'),
            new OA\Response(response: 401, description: 'Gmailトークン失効'),
            new OA\Response(response: 422, description: 'Gmail未接続'),
            new OA\Response(response: 500, description: '同期失敗'),
        ]
    )]
    // Gmail から最新メールを同期
    public function sync()
    {
        $user  = auth()->user();
        $token = GmailToken::where('tenant_id', $user->tenant_id)->first();
        if (!$token) {
            return response()->json(['message' => 'Gmail未接続です'], 422);
        }
        try {
            $count = $this->gmailService->fetchAndStoreEmails($token);

            return response()->json([
                'message' => "{$count}件の新着メールを取得しました",
                'count'   => $count,
            ]);
        } catch (\Exception $e) {
            Log::error('Email sync failed: ' . $e->getMessage());
            $msg = $e->getMessage();
            // Gmail トークン失効
            if (str_contains($msg, 'invalid_grant')) {
                return response()->json([
                    'message'       => 'Gmailトークンが失効しました。再接続してください。',
                    'token_expired' => true,
                ], 401);
            }
            // Gmail スコープ不足（同意画面で必要権限を外して承認した等）。再連携で復旧するため 401 で返す
            if (str_contains($msg, 'ACCESS_TOKEN_SCOPE_INSUFFICIENT')
                || str_contains($msg, 'insufficientPermissions')
                || str_contains($msg, 'Insufficient Permission')) {
                return response()->json([
                    'message'       => 'Gmailの権限が不足しています。再接続して、同意画面でGmailの権限をすべて許可してください。',
                    'token_expired' => true,
                ], 401);
            }
            return response()->json(['message' => 'メール同期に失敗しました'], 500);
        }
    }

    #[OA\Patch(
        path: '/api/v1/emails/{id}/link',
        summary: 'メールを担当者・商談・顧客に紐付け',
        security: [['bearerAuth' => []]],
        tags: ['Emails'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'メールID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: '成功'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    // 担当者・商談への紐付け
    public function link(Request $request, int $id)
    {
        $request->validate([
            'contact_id'  => ['nullable', TenantExistsRule::for('contacts')],
            'deal_id'     => ['nullable', TenantExistsRule::for('deals')],
            'customer_id' => ['nullable', TenantExistsRule::for('customers')],
        ]);
        $email = Email::findOrFail($id);
        $email->update($request->only(['contact_id', 'deal_id', 'customer_id']));
        return response()->json($email->load(['contact', 'deal', 'customer']));
    }

    #[OA\Get(
        path: '/api/v1/emails/unread-count',
        summary: '未読メール件数取得',
        security: [['bearerAuth' => []]],
        tags: ['Emails'],
        responses: [
            new OA\Response(response: 200, description: '成功'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    // 未読件数 (is_read 系 index 削除に伴い Seq Scan 化したため 60s キャッシュで I/O 抑制)
    // 5分毎ポーリングのバッジ表示用途で 60s 遅延は許容範囲。
    // markAllRead 完了時は RescoreJobRunner::runMarkReadTick が Cache::forget で即時 invalidate する。
    public function unreadCount()
    {
        $tenantId = auth()->user()->tenant_id;
        $count = \Illuminate\Support\Facades\Cache::remember(
            "emails:unread_count:tenant:{$tenantId}",
            60,
            fn () => Email::where('is_read', false)->count()
        );
        return response()->json(['count' => $count]);
    }

    // ── 添付ファイルダウンロード ──────────────────────────────

    public function downloadAttachment(int $emailId, int $attachmentId)
    {
        $email      = Email::findOrFail($emailId);
        $attachment = $email->attachments()->findOrFail($attachmentId);
        $filename   = $attachment->filename ?: 'attachment';
        $mimeType   = $attachment->mime_type ?? 'application/octet-stream';
        $ext        = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Storage保存済み → Supabaseから取得
        if ($attachment->storage_path) {
            $supabaseUrl = config('services.supabase.url');
            $serviceKey  = config('services.supabase.service_role_key');
            $bucket      = config('services.supabase.bucket');

            $pattern  = "/storage\/v1\/object\/public\/{$bucket}\//";
            $path     = preg_replace($pattern, '', parse_url($attachment->storage_path, PHP_URL_PATH));
            $response = \Illuminate\Support\Facades\Http::withHeaders(['Authorization' => "Bearer {$serviceKey}"])
                ->get("{$supabaseUrl}/storage/v1/object/{$bucket}/{$path}");

            if ($response->successful()) {
                return response($response->body())
                    ->header('Content-Type', $mimeType)
                    ->header('Content-Disposition', 'attachment; filename="' . rawurlencode($filename) . '"')
                    ->header('Content-Length', strlen($response->body()));
            }
        }

        // IMAP経由メール → KagoyaMailServiceから再取得
        if (str_starts_with($email->gmail_message_id ?? '', 'imap-')) {
            try {
                $imapUid = (int) str_replace('imap-', '', $email->gmail_message_id);
                $kagoya  = app(\App\Services\KagoyaMailService::class);
                // part_index があれば優先 (同名添付の衝突を回避)。無ければ filename フォールバック。
                $binary  = $attachment->part_index !== null
                    ? $kagoya->fetchAttachmentByPartIndex($imapUid, (int) $attachment->part_index)
                    : $kagoya->fetchAttachmentByUid($imapUid, $filename);
                if ($binary) {
                    try {
                        $base        = preg_replace('/[^\w\-\.]/u', '_', pathinfo($filename, PATHINFO_FILENAME));
                        $base        = preg_replace('/[^\x00-\x7F]/u', '', $base) ?: substr(md5($filename), 0, 8);
                        // path に attachment id を含めて同一メール内の同名添付衝突を防ぐ
                        $storagePath = "attachments/{$email->tenant_id}/{$email->id}/{$attachment->id}_{$base}.{$ext}";
                        $storage     = app(\App\Services\SupabaseStorageService::class);
                        $url         = $storage->uploadBinary($binary, $storagePath, $mimeType);
                        $attachment->update(['storage_path' => $url]);
                    } catch (\Throwable $e) {
                        Log::debug("[EmailController] IMAP添付Storage保存失敗 att_id={$attachment->id}: " . $e->getMessage());
                    }
                    return response($binary)
                        ->header('Content-Type', $mimeType)
                        ->header('Content-Disposition', 'attachment; filename="' . rawurlencode($filename) . '"')
                        ->header('Content-Length', strlen($binary));
                }
            } catch (\Throwable $e) {
                Log::warning("[EmailController] IMAP添付再取得失敗 att_id={$attachment->id}: " . $e->getMessage());
            }
        }

        // Gmail APIから取得
        $token = GmailToken::where('tenant_id', auth()->user()->tenant_id)->first();
        if (!$token || !$email->gmail_message_id || !$attachment->gmail_attachment_id) {
            return response()->json(['message' => '添付ファイルを取得できませんでした'], 404);
        }

        try {
            $data = $this->gmailService->fetchAttachmentData(
                $token,
                $email->gmail_message_id,
                $attachment->gmail_attachment_id
            );

            return response($data)
                ->header('Content-Type', $mimeType)
                ->header('Content-Disposition', 'attachment; filename="' . rawurlencode($filename) . '"')
                ->header('Content-Length', strlen($data));

        } catch (\Exception $e) {
            Log::error("Attachment download failed: {$e->getMessage()}");
            return response()->json(['message' => 'ダウンロードに失敗しました'], 500);
        }
    }

    #[OA\Post(
        path: '/api/v1/emails/mark-all-read',
        summary: '全メールを既読にする',
        security: [['bearerAuth' => []]],
        tags: ['Emails'],
        responses: [
            new OA\Response(response: 200, description: '成功'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    // 全件既読 (非同期ジョブ化版 / rescore-all と同パターン)
    //
    // 未読が数千件あると同期 UPDATE では nginx の fastcgi_read_timeout (5min) や
    // statement_timeout を超え 502/500 で UI が「失敗」表示になる事象が頻発したため
    // (詳細: [[project_markallread_sync_timeout]])、本エンドポイントは job 登録のみ行い 202 を返す。
    // 実処理は毎分の Schedule::call('rescore-jobs-tick') が RescoreJobRunner::runMarkReadTick で進める。
    // フロントは /emails/mark-read-status をポーリングして進捗を表示する。
    public function markAllRead()
    {
        $tenantId = auth()->user()->tenant_id;

        // 既に未完了の同種ジョブがあればそれを返す (二重起動防止)
        $existing = RescoreJob::where('type', RescoreJob::TYPE_MARK_READ)
            ->whereIn('status', RescoreJob::ACTIVE_STATUSES)
            ->orderByDesc('id')
            ->first();
        if ($existing) {
            return response()->json([
                'message' => '既読処理は既に実行中です',
                'job'     => $existing,
            ], 202);
        }

        $total = Email::where('tenant_id', $tenantId)
            ->where('is_read', false)
            ->count();

        if ($total === 0) {
            return response()->json([
                'message' => '未読メールはありません',
                'count'   => 0,
                'job'     => null,
            ]);
        }

        $job = RescoreJob::create([
            'type'         => RescoreJob::TYPE_MARK_READ,
            'status'       => RescoreJob::STATUS_PENDING,
            'total_count'  => $total,
            'requested_by' => auth()->id(),
        ]);

        return response()->json([
            'message' => "{$total}件の既読化を開始しました（バックグラウンドで処理されます）",
            'job'     => $job,
        ], 202);
    }

    // 直近の全件既読ジョブの進捗を返す（フロントのポーリング用）
    public function markReadStatus(): JsonResponse
    {
        $job = RescoreJob::where('type', RescoreJob::TYPE_MARK_READ)
            ->orderByDesc('id')
            ->first();

        return response()->json(['job' => $job]);
    }

    /**
     * 受信メールへの返信送信。SelfMailsView の「返信」フォームから呼ばれる。
     * POST /api/v1/emails/{id}/reply
     *
     * - 受信メール (emails.id) を元に Re: 返信を送信する
     * - In-Reply-To/References ヘッダで RFC822 スレッドを維持
     *   (rfc_message_id が null = 2026-05-27 migration 以前の受信メールはヘッダ省略)
     * - 送信履歴は delivery_campaigns / delivery_send_histories に send_type='delivery' で記録
     *   (送信元 emails.id は別途追跡不可だが、subject/body/到達日時で検索できる前提)
     * - 添付は一時保存→送信→削除 (ProposalMail と同パターン)
     *
     * 営業打ち合わせ 2026-05-25 §要望1+E-4 の中核実装。
     */
    public function reply(Request $request, int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $email    = Email::where('tenant_id', $tenantId)->findOrFail($id);

        $v = $request->validate([
            'to'            => 'required|email',
            'cc'            => 'nullable|array',
            'cc.*'          => 'email',
            'bcc'           => 'nullable|array',
            'bcc.*'         => 'email',
            'subject'       => 'required|string|max:500',
            'body'          => 'required|string',
            'attachments'   => 'nullable|array',
            'attachments.*' => 'file|max:10240',
        ]);

        $userId      = auth()->id();
        $senderName  = auth()->user()->name  ?? '';
        // Reply-To は送信者本人の email を使う (営業担当宛に直接返信が戻るように)。
        // 未設定なら config の reply_to → from へフォールバック。
        $senderEmail = auth()->user()->email
            ?? config('mail.reply_to.address', config('mail.from.address'))
            ?? '';

        // 添付ファイルを一時ディレクトリに保存（ProposalMail と同じ流儀）
        $attachmentPaths = [];
        if ($request->hasFile('attachments')) {
            $dir = storage_path('app/temp/replies/' . uniqid());
            @mkdir($dir, 0755, true);
            foreach ($request->file('attachments') as $file) {
                $dest = $dir . '/' . $file->getClientOriginalName();
                $file->move($dir, $file->getClientOriginalName());
                $attachmentPaths[] = $dest;
            }
        }

        // send_type='delivery'（単発送信）: 提案スレッドには載せない、自社返信の証跡として記録。
        // project_mail_id / engineer_mail_source_id は紐づかないため null のまま。
        $campaign = DeliveryCampaign::create([
            'tenant_id'     => $tenantId,
            'send_type'     => 'delivery',
            'user_id'       => $userId,
            'subject'       => $v['subject'],
            'body'          => $v['body'],
            'total_count'   => 1,
            'success_count' => 0,
            'failed_count'  => 0,
            'sent_at'       => now(),
        ]);

        $messageId = '<' . Str::uuid() . '@aizen-sol.co.jp>';
        $inReplyTo = $email->rfc_message_id; // null の場合 ReplyMail 側でヘッダを省略

        try {
            $mailable = new ReplyMail(
                mailSubject: $v['subject'],
                body: $v['body'],
                senderName: $senderName,
                senderEmail: $senderEmail,
                uploadedFiles: $attachmentPaths,
                messageId: $messageId,
                inReplyTo: $inReplyTo,
                fromDisplayName: $this->senderDisplayName(),
            );

            $send = Mail::to($v['to']);
            if (!empty($v['cc']))  { $send->cc($v['cc']); }
            if (!empty($v['bcc'])) { $send->bcc($v['bcc']); }
            $send->send($mailable);

            DeliverySendHistory::create([
                'tenant_id'      => $tenantId,
                'campaign_id'    => $campaign->id,
                'email'          => $v['to'],
                'name'           => null,
                'status'         => 'sent',
                'ses_message_id' => $messageId,
            ]);
            $campaign->update(['success_count' => 1]);

            Log::info("[EmailReply] email_id={$id} to={$v['to']} cc=" . count($v['cc'] ?? []) . " bcc=" . count($v['bcc'] ?? []) . " attachments=" . count($attachmentPaths));
            return response()->json(['message' => '返信を送信しました']);
        } catch (\Exception $e) {
            DeliverySendHistory::create([
                'tenant_id'      => $tenantId,
                'campaign_id'    => $campaign->id,
                'email'          => $v['to'],
                'name'           => null,
                'status'         => 'failed',
                'ses_message_id' => $messageId,
                'error_message'  => $e->getMessage(),
            ]);
            $campaign->update(['failed_count' => 1]);
            Log::error("[EmailReply] 送信失敗 email_id={$id}: " . $e->getMessage());
            return response()->json(['message' => 'メール送信に失敗しました'], 500);
        } finally {
            foreach ($attachmentPaths as $path) { if (is_file($path)) @unlink($path); }
            if ($attachmentPaths) {
                $dir = dirname($attachmentPaths[0]);
                if (is_dir($dir) && count(array_diff(scandir($dir), ['.', '..'])) === 0) @rmdir($dir);
            }
        }
    }
}
