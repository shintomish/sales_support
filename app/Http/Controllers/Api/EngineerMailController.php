<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ProposalMail;
use App\Models\DeliveryCampaign;
use App\Models\DeliverySendHistory;
use App\Models\EmailAttachment;
use App\Models\Engineer;
use App\Models\EngineerMailSource;
use App\Models\EngineerSkill;
use App\Models\GmailToken;
use App\Models\PublicProject;
use App\Models\Skill;
use App\Services\ClaudeService;
use App\Services\EngineerMailScoringService;
use App\Services\FreshMailMatchingService;
use App\Services\GmailService;
use App\Services\ProposalStatusService;
use App\Services\SupabaseStorageService;
use App\Models\ProjectMailSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EngineerMailController extends Controller
{
    public function __construct(
        private EngineerMailScoringService $scoringService,
        private FreshMailMatchingService   $freshMatching,
        private ProposalStatusService      $proposalStatus,
    ) {}

    // 一覧
    public function index(Request $request)
    {
        $perPage  = $request->integer('per_page', 30);
        $status   = $request->input('status');
        $scoreMin = $request->integer('score_min', 0);
        $scoreMax = $request->integer('score_max', 100);
        $search   = $request->input('search');

        $query = EngineerMailSource::with(['email:id,subject,from_name,from_address,received_at'])
            ->whereBetween('score', [$scoreMin, $scoreMax])
            ->orderByDesc('received_at');

        if ($status) {
            $query->where('status', $status);
        } else {
            $query->whereNotIn('status', ['excluded']);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('nearest_station', 'ilike', "%{$search}%")
                  ->orWhere('affiliation_type', 'ilike', "%{$search}%")
                  ->orWhere('affiliation', 'ilike', "%{$search}%")
                  ->orWhere('skills', 'ilike', "%{$search}%")
                  ->orWhereHas('email', fn($eq) => $eq
                      ->where('from_address', 'ilike', "%{$search}%")
                      ->orWhere('from_name', 'ilike', "%{$search}%")
                      ->orWhere('subject', 'ilike', "%{$search}%"));
            });
        }

        return response()->json($query->paginate($perPage));
    }

    // 詳細（元メール・添付含む）
    public function show(int $id)
    {
        $ems = EngineerMailSource::with([
            'email.attachments',
        ])->findOrFail($id);

        return response()->json($ems);
    }

    // 抽出情報の手動修正
    public function update(Request $request, int $id)
    {
        $ems = EngineerMailSource::findOrFail($id);

        $v = $request->validate([
            'name'             => 'nullable|string|max:100',
            'affiliation_type' => 'nullable|string|max:50',
            'available_from'   => 'nullable|string|max:50',
            'nearest_station'  => 'nullable|string|max:100',
            'skills'           => 'nullable|array',
            'skills.*'         => 'string|max:100',
            'unit_price_min'   => 'nullable|integer|min:0',
            'unit_price_max'   => 'nullable|integer|min:0',
        ]);

        $ems->update($v);

        return response()->json($ems->fresh());
    }

    // ステータス変更
    public function updateStatus(Request $request, int $id)
    {
        $ems = EngineerMailSource::findOrFail($id);

        $v = $request->validate([
            'status' => 'required|in:review,new,registered,proposing,working,excluded',
        ]);

        $ems->update($v);

        return response()->json($ems->fresh());
    }

    // ── P1: EngineerMailSource → Engineerマスタへワンクリック登録 ─────────────

    public function registerEngineer(int $id): JsonResponse
    {
        $ems = EngineerMailSource::with('email')->findOrFail($id);

        if ($ems->status === 'registered') {
            return response()->json(['message' => 'すでに登録済みです'], 422);
        }

        DB::transaction(function () use ($ems) {
            $engineer = Engineer::create([
                'name'              => $ems->name ?? '（名前未取得）',
                'affiliation_type'  => $ems->affiliation_type,
                'nearest_station'   => $ems->nearest_station,
                'affiliation_email' => $ems->email?->from_address,
            ]);

            // スキル名からSkillレコードを取得/作成してEngineerSkillに登録
            foreach ((array) ($ems->skills ?? []) as $skillName) {
                $skillName = trim((string) $skillName);
                if ($skillName === '') {
                    continue;
                }
                $skill = Skill::firstOrCreate(
                    ['name' => $skillName],
                    ['category' => 'other']
                );
                EngineerSkill::firstOrCreate([
                    'tenant_id'   => $engineer->tenant_id,
                    'engineer_id' => $engineer->id,
                    'skill_id'    => $skill->id,
                ]);
            }

            $ems->update(['status' => 'registered']);
        });

        $ems->refresh();

        return response()->json([
            'message' => 'Engineerマスタに登録しました',
            'ems'     => $ems,
        ], 201);
    }

    // ── P2: EngineerMailSourceのスキルと自社公開案件のマッチング ─────────────

    public function matchedProjects(int $id): JsonResponse
    {
        $ems = EngineerMailSource::findOrFail($id);

        // EMS の抽出スキルを小文字正規化してセット化
        $emsSkills = collect((array) ($ems->skills ?? []))
            ->map(fn($s) => mb_strtolower(trim((string) $s)))
            ->filter()
            ->flip(); // O(1) lookup 用

        // テナントのオープン案件を必要スキルと一緒に取得
        $projects = PublicProject::with(['requiredSkills.skill', 'postedByCustomer.contacts'])
            ->published()
            ->open()
            ->get();

        // 技術者の希望単価（単価上限を基準にフィルタリング）
        $engineerPrice = $ems->unit_price_max ?? $ems->unit_price_min;

        // ▼元メール本文用に PMS をまとめて prefetch
        // 第一候補: public_projects.project_mail_source_id (直接FK)
        // 第二候補: posted_by_customer_id 経由で同顧客の直近 PMS
        $directPmsIds = $projects->pluck('project_mail_source_id')->filter()->unique()->values()->all();
        $directPms = !empty($directPmsIds)
            ? ProjectMailSource::with('email')->whereIn('id', $directPmsIds)->get()->keyBy('id')
            : collect();

        $customerIdsForFallback = $projects
            ->whereNull('project_mail_source_id')
            ->pluck('posted_by_customer_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $fallbackPmsByCustomer = collect();
        if (!empty($customerIdsForFallback)) {
            // 顧客名で照合: posted_by_customer の name == PMS.customer_name となるレコードの最新を一括取得
            $customers = \App\Models\Customer::whereIn('id', $customerIdsForFallback)->get();
            foreach ($customers as $cust) {
                $latest = ProjectMailSource::with('email')
                    ->where('customer_name', $cust->name)
                    ->orderByDesc('received_at')
                    ->first();
                if ($latest) $fallbackPmsByCustomer[$cust->id] = $latest;
            }
        }

        $results = $projects->map(function (PublicProject $project) use ($emsSkills, $directPms, $fallbackPmsByCustomer) {
            $required = $project->requiredSkills;
            $total    = $required->count();

            $matched = $required->filter(
                fn($rs) => $emsSkills->has(mb_strtolower(trim((string) ($rs->skill?->name ?? ''))))
            );

            $matchScore = $total > 0 ? round($matched->count() / $total * 100) : 0;

            $contact       = $project->postedByCustomer?->contacts->first();
            $toEmail       = $contact?->email ?? '';
            $salesContact  = $contact?->name ?? $project->postedByCustomer?->name ?? '';

            // 元 PMS の取得
            $pms = null;
            if ($project->project_mail_source_id && $directPms->has($project->project_mail_source_id)) {
                $pms = $directPms->get($project->project_mail_source_id);
            } elseif ($project->posted_by_customer_id && $fallbackPmsByCustomer->has($project->posted_by_customer_id)) {
                $pms = $fallbackPmsByCustomer->get($project->posted_by_customer_id);
            }

            // 案件提供者宛て (PMS送信者) のフォールバック: contacts が無い場合は PMS.from を使う
            if ($toEmail === '' && $pms?->email) {
                $toEmail      = $pms->email->from_address ?? '';
                $salesContact = $salesContact ?: ($pms->sales_contact ?: ($pms->email->from_name ?? ''));
            }

            return [
                'project_id'       => $project->id,
                'project_title'    => $project->title,
                'status'           => $project->status,
                'work_style'       => $project->work_style,
                'nearest_station'  => $project->nearest_station,
                'unit_price_min'   => $project->unit_price_min,
                'unit_price_max'   => $project->unit_price_max,
                'match_score'      => $matchScore,
                'matched_count'    => $matched->count(),
                'total_skills'     => $total,
                'required_skills'  => $required->map(fn($rs) => [
                    'name'         => $rs->skill?->name,
                    'is_required'  => $rs->is_required,
                    'matched'      => $emsSkills->has(mb_strtolower(trim((string) ($rs->skill?->name ?? '')))),
                ])->values(),
                'to_email'         => $toEmail,
                'sales_contact'    => $salesContact,
                // ▼元メール本文 (個別提案モーダルのアコーディオン用)
                'pms_id'                => $pms?->id,
                'pms_email_subject'     => $pms?->email?->subject,
                'pms_email_from_address'=> $pms?->email?->from_address,
                'pms_email_body'        => self::pickMailBody($pms?->email),
            ];
        })
        // 技術者の希望単価が案件の単価上限を超える場合は除外
        // 案件に単価情報がない場合は表示する
        ->filter(function ($item) use ($engineerPrice) {
            if ($engineerPrice === null) return true;
            $projectMax = $item['unit_price_max'];
            if ($projectMax === null) return true;
            return (float) $projectMax >= $engineerPrice;
        })
        ->sortByDesc('match_score')
        ->values()
        ->take(20);

        return response()->json(['data' => $results]);
    }

    // 添付ファイルのmagic bytesチェック（壊れたファイルを検出）
    private function isValidFileBinary(string $binary, string $ext): bool
    {
        if (strlen($binary) < 8) return false;
        return match($ext) {
            'pdf'         => str_starts_with($binary, '%PDF'),
            'xlsx', 'docx' => str_starts_with($binary, "PK\x03\x04"),
            'xls', 'doc'  => str_starts_with($binary, "\xD0\xCF\x11\xE0"),
            default       => true,
        };
    }

    // 添付ファイルダウンロード
    public function downloadAttachment(int $id, int $attachmentId): Response
    {
        $ems = EngineerMailSource::with('email')->findOrFail($id);
        $att = EmailAttachment::where('id', $attachmentId)
            ->where('email_id', $ems->email_id)
            ->firstOrFail();

        $filename = $att->filename ?: 'attachment';
        $mimeType = $att->mime_type ?: 'application/octet-stream';
        $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Storage保存済み → Supabaseから取得してmagic bytes検証
        if ($att->storage_path) {
            $supabaseUrl = config('services.supabase.url');
            $serviceKey  = config('services.supabase.service_role_key');
            $bucket      = config('services.supabase.bucket');

            $pattern  = "/storage\/v1\/object\/public\/{$bucket}\//";
            $path     = preg_replace($pattern, '', parse_url($att->storage_path, PHP_URL_PATH));
            $response = Http::withHeaders(['Authorization' => "Bearer {$serviceKey}"])
                ->get("{$supabaseUrl}/storage/v1/object/{$bucket}/{$path}");

            if ($response->successful()) {
                $binary = $response->body();
                if ($this->isValidFileBinary($binary, $ext)) {
                    return response($binary, 200, [
                        'Content-Type'        => $mimeType,
                        'Content-Disposition' => 'attachment; filename="' . rawurlencode($filename) . '"',
                    ]);
                }
                // magic bytes 不正（二重デコードバグによる破損ファイル）→ Gmail APIから再取得
                Log::warning("[EngineerMailController] Storage上のファイルが破損、Gmail APIから再取得 att_id={$att->id}");
            }
        }

        // storage_pathなし or 破損検出 → IMAP経由で再取得（Kagoya受信メール）
        if (str_starts_with($ems->email->gmail_message_id ?? '', 'imap-')) {
            try {
                $imapUid = (int) str_replace('imap-', '', $ems->email->gmail_message_id);
                $kagoya  = app(\App\Services\KagoyaMailService::class);
                $binary  = $kagoya->fetchAttachmentByUid($imapUid, $att->filename);
                if ($binary) {
                    // Storageに保存
                    try {
                        $base        = preg_replace('/[^\w\-\.]/u', '_', pathinfo($filename, PATHINFO_FILENAME));
                        $base        = preg_replace('/[^\x00-\x7F]/u', '', $base) ?: substr(md5($filename), 0, 8);
                        $storagePath = "attachments/{$ems->email->tenant_id}/{$ems->email_id}/{$base}.{$ext}";
                        $storage     = app(SupabaseStorageService::class);
                        $url         = $storage->uploadBinary($binary, $storagePath, $mimeType);
                        $att->update(['storage_path' => $url]);
                    } catch (\Throwable $e) {
                        Log::debug("[EngineerMailController] IMAP添付Storage保存失敗 att_id={$att->id}: " . $e->getMessage());
                    }
                    return response($binary, 200, [
                        'Content-Type'        => $mimeType,
                        'Content-Disposition' => 'attachment; filename="' . rawurlencode($filename) . '"',
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning("[EngineerMailController] IMAP添付再取得失敗 att_id={$att->id}: " . $e->getMessage());
            }
        }

        // Gmail APIから取得
        $gmailToken = GmailToken::where('tenant_id', $ems->email->tenant_id)->first();
        if (!$gmailToken || !$ems->email->gmail_message_id || !$att->gmail_attachment_id) {
            abort(404, '添付ファイルを取得できませんでした');
        }

        $gmailService = app(GmailService::class);
        // fetchAttachmentData() はbase64デコード済みバイナリを返す（二重デコード不要）
        $binary = $gmailService->fetchAttachmentData(
            $gmailToken,
            $ems->email->gmail_message_id,
            $att->gmail_attachment_id
        );

        if (!$binary) {
            abort(404, '添付ファイルを取得できませんでした');
        }

        // Storageに正しいバイナリで上書き保存
        try {
            $base        = preg_replace('/[^\w\-\.]/u', '_', pathinfo($filename, PATHINFO_FILENAME));
            $base        = preg_replace('/[^\x00-\x7F]/u', '', $base) ?: substr(md5($filename), 0, 8);
            $storagePath = "attachments/{$ems->email->tenant_id}/{$ems->email_id}/{$base}.{$ext}";
            $storage     = app(SupabaseStorageService::class);
            $url         = $storage->uploadBinary($binary, $storagePath, $mimeType);
            $att->update(['storage_path' => $url]);
        } catch (\Throwable $e) {
            Log::debug("[EngineerMailController] 添付Storage保存失敗 att_id={$att->id}: " . $e->getMessage());
        }

        return response($binary, 200, [
            'Content-Type'        => $mimeType,
            'Content-Disposition' => 'attachment; filename="' . rawurlencode($filename) . '"',
        ]);
    }

    // 既存レコードを全件再スコアリング＋再抽出（バッチ処理対応）
    public function rescoreAll(Request $request): JsonResponse
    {
        set_time_limit(120);
        ini_set('memory_limit', '512M');
        $batchSize = 300;
        $offset    = $request->integer('offset', 0);
        $count     = $this->scoringService->rescoreAll($batchSize, $offset);
        $total     = EngineerMailSource::whereNotNull('email_id')->count();
        $remaining = max(0, $total - ($offset + $count));

        return response()->json([
            'message'   => "{$count}件を再スコアリングしました",
            'count'     => $count,
            'remaining' => $remaining,
            'offset'    => $offset + $count,
        ]);
    }

    /**
     * スレッド会話履歴
     * GET /v1/engineer-mails/{id}/thread
     */
    public function thread(int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        EngineerMailSource::where('tenant_id', $tenantId)->findOrFail($id);

        $campaigns = DeliveryCampaign::with(['sendHistories' => function ($q) {
                $q->with(['replyEmail.attachments']);
            }])
            ->where('tenant_id', $tenantId)
            ->where('engineer_mail_source_id', $id)
            ->whereIn('send_type', ['engineer_proposal', 'engineer_proposal_bulk'])
            ->orderBy('sent_at')
            ->get();

        $thread = [];

        $mapAttachments = fn($email) => $email?->attachments
            ? $email->attachments->map(fn($a) => [
                'id'        => $a->id,
                'filename'  => $a->filename,
                'mime_type' => $a->mime_type,
                'size'      => $a->size,
            ])->values()->all()
            : [];

        foreach ($campaigns as $campaign) {
            $isDelivery = $campaign->send_type === 'delivery';

            if ($isDelivery) {
                $thread[] = [
                    'type'          => 'sent',
                    'campaign_id'   => $campaign->id,
                    'to'            => "一斉配信（{$campaign->success_count}件）",
                    'to_name'       => null,
                    'subject'       => $campaign->subject,
                    'body'          => str_replace('<%Name%>', '（各配信先名）', $campaign->body),
                    'sent_at'       => $campaign->sent_at?->toIso8601String(),
                    'status'        => 'sent',
                    'total_count'   => $campaign->total_count,
                    'success_count' => $campaign->success_count,
                    'failed_count'  => $campaign->failed_count,
                ];
                foreach ($campaign->sendHistories->filter(fn($h) => $h->replyEmail) as $history) {
                    $reply = $history->replyEmail;
                    $thread[] = [
                        'type'        => 'received',
                        'email_id'    => $reply->id,
                        'from'        => $reply->from_address,
                        'from_name'   => $reply->from_name,
                        'subject'     => $reply->subject,
                        'body_text'   => $reply->body_text,
                        'received_at' => $reply->received_at?->toIso8601String(),
                        'attachments' => $mapAttachments($reply),
                    ];
                }
                continue;
            }

            foreach ($campaign->sendHistories as $history) {
                $thread[] = [
                    'type'          => 'sent',
                    'campaign_id'   => $campaign->id,
                    'history_id'    => $history->id,
                    'to'            => $history->email,
                    'to_name'       => $history->name,
                    'subject'       => $campaign->subject,
                    'body'          => $campaign->body,
                    'sent_at'       => $history->created_at?->toIso8601String(),
                    'resent_at'     => $history->resent_at?->toIso8601String(),
                    'status'        => $history->status,
                    'total_count'   => $campaign->total_count,
                    'success_count' => $campaign->success_count,
                    'failed_count'  => $campaign->failed_count,
                ];

                if ($history->replyEmail) {
                    $reply = $history->replyEmail;
                    $thread[] = [
                        'type'        => 'received',
                        'email_id'    => $reply->id,
                        'from'        => $reply->from_address,
                        'from_name'   => $reply->from_name,
                        'subject'     => $reply->subject,
                        'body_text'   => $reply->body_text,
                        'received_at' => $reply->received_at?->toIso8601String(),
                        'attachments' => $mapAttachments($reply),
                    ];
                }
            }
        }

        // 時系列ソート
        usort($thread, function ($a, $b) {
            $aTime = $a['sent_at'] ?? $a['received_at'] ?? '';
            $bTime = $b['sent_at'] ?? $b['received_at'] ?? '';
            return strcmp($aTime, $bTime);
        });

        return response()->json(['thread' => $thread]);
    }

    // ── 技術者メール → マッチ案件への提案文生成 ─────────────────────────────

    public function generateProposal(Request $request, int $id): JsonResponse
    {
        $v = $request->validate(['project_id' => 'required|integer']);

        $ems     = EngineerMailSource::with('email')->findOrFail($id);
        $project = PublicProject::with('requiredSkills.skill')->findOrFail($v['project_id']);

        $mailData = [
            'title'           => $project->title,
            'email_subject'   => $project->title,
            'required_skills' => $project->requiredSkills->map(fn($rs) => $rs->skill?->name)->filter()->values()->toArray(),
            'work_location'   => $project->work_location ?? '',
            'unit_price_min'  => $project->unit_price_min,
            'unit_price_max'  => $project->unit_price_max,
            'sales_contact'   => '',
            'from_address'    => '',
            'from_name'       => '',
        ];

        $engineerData = [
            'name'                   => $ems->name ?? '技術者',
            'age'                    => $ems->age,
            'skills'                 => collect($ems->skills ?? [])->map(fn($s) => ['name' => $s, 'experience_years' => null])->toArray(),
            'availability_status'    => $ems->available_from ? 'scheduled' : 'available',
            'available_from'         => $ems->available_from,
            'desired_unit_price_min' => null,
            'desired_unit_price_max' => null,
            'affiliation'            => $ems->email?->from_name ?? '',
        ];

        try {
            $result = app(ClaudeService::class)->generateProposal($mailData, $engineerData);
        } catch (\App\Exceptions\ClaudeOverloadedException $e) {
            \Log::warning("EM generateProposal overloaded ems_id={$id}: " . $e->getMessage());
            return response()->json([
                'message' => 'Claude API が混雑しています。しばらく待ってから再試行してください。',
                'code'    => 'claude_overloaded',
            ], 503);
        } catch (\Exception $e) {
            \Log::error("EM generateProposal failed ems_id={$id}: " . $e->getMessage());
            return response()->json(['message' => 'メール生成に失敗しました'], 500);
        }

        return response()->json([
            'subject' => $result['subject'],
            'body'    => $result['body'],
        ]);
    }

    // ── 技術者メール → マッチ案件への提案メール送信 ──────────────────────────

    public function sendProposal(Request $request, int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        EngineerMailSource::where('tenant_id', $tenantId)->findOrFail($id);

        $v = $request->validate([
            'project_id'    => 'nullable|integer',
            'to'            => 'required|email',
            'to_name'       => 'nullable|string|max:255',
            'subject'       => 'required|string|max:500',
            'body'          => 'required|string',
            'attachments'   => 'nullable|array',
            'attachments.*' => 'file|max:10240',
        ]);

        $userId      = auth()->id();
        $senderName  = auth()->user()->name ?? '';
        $senderEmail = $this->replyToAddress();

        $campaign = DeliveryCampaign::create([
            'tenant_id'               => $tenantId,
            'send_type'               => 'engineer_proposal',
            'engineer_mail_source_id' => $id,
            'user_id'                 => $userId,
            'subject'                 => $v['subject'],
            'body'                    => $v['body'],
            'total_count'             => 1,
            'success_count'           => 0,
            'failed_count'            => 0,
            'sent_at'                 => now(),
        ]);

        $messageId = '<' . Str::uuid() . '@aizen-sol.co.jp>';
        try {
            $uploadedFiles = $request->file('attachments') ?? [];
            Mail::to($v['to'])->send(new ProposalMail($v['subject'], $v['body'], $senderName, $senderEmail, $uploadedFiles, $messageId));
            DeliverySendHistory::create([
                'tenant_id'         => $tenantId,
                'campaign_id'       => $campaign->id,
                'public_project_id' => $v['project_id'],
                'email'             => $v['to'],
                'name'              => $v['to_name'] ?? null,
                'status'            => 'sent',
                'ses_message_id'    => $messageId,
            ]);
            $campaign->update(['success_count' => 1]);
            Log::info("技術者提案メール送信 engineer_mail_id={$id} to={$v['to']}");
            return response()->json(['message' => '送信しました']);
        } catch (\Exception $e) {
            DeliverySendHistory::create([
                'tenant_id'         => $tenantId,
                'campaign_id'       => $campaign->id,
                'public_project_id' => $v['project_id'],
                'email'             => $v['to'],
                'name'              => $v['to_name'] ?? null,
                'status'            => 'failed',
                'ses_message_id'    => $messageId,
                'error_message'     => $e->getMessage(),
            ]);
            $campaign->update(['failed_count' => 1]);
            Log::error("技術者提案メール送信失敗 engineer_mail_id={$id}: " . $e->getMessage());
            return response()->json(['message' => 'メール送信に失敗しました'], 500);
        }
    }

    /**
     * 技術者メールの前向きコメント生成
     * POST /v1/engineer-mails/{id}/generate-comment
     */
    public function generateComment(int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $em = EngineerMailSource::where('tenant_id', $tenantId)->findOrFail($id);

        $info = "氏名：{$em->name}\n"
            . "年齢：{$em->age}歳\n"
            . "スキル：" . implode('、', $em->skills ?? []) . "\n"
            . "最寄駅：{$em->nearest_station}\n"
            . "稼働可能日：{$em->available_from}";

        $prompt = <<<PROMPT
あなたはSES営業担当です。以下の技術者を取引先に紹介する配信メールに添える、前向きな推薦コメントを2〜3文で作成してください。

重要なルール:
- 技術者の強みや経験を最大限にアピールしてください
- 否定的な表現は絶対に使わないでください
- 「即戦力」「豊富な経験」「高いコミュニケーション力」など、前向きな表現を使ってください
- 敬体（です・ます）で書いてください

【技術者情報】
{$info}

コメントのみを出力してください。
PROMPT;

        try {
            $claude = app(\App\Services\ClaudeService::class);
            $comment = $claude->ask($prompt);
            return response()->json(['comment' => $comment]);
        } catch (\Exception $e) {
            Log::error("generateComment failed engineer_mail_id={$id}: " . $e->getMessage());
            return response()->json(['comment' => ''], 500);
        }
    }

    /**
     * 鮮度マッチング: 過去N日の ProjectMailSource を技術者メールに対してスコアリング
     * GET /v1/engineer-mails/{id}/fresh-project-mails?days=7
     * docs/470_fresh_mail_matching.md §8.4
     */
    public function freshProjectMails(Request $request, int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $ems = EngineerMailSource::with('email')->where('tenant_id', $tenantId)->findOrFail($id);

        $v = $request->validate([
            'days'      => 'nullable|integer|min:1|max:30',
            'limit'     => 'nullable|integer|min:1|max:200',
            'min_score' => 'nullable|integer|in:50,60,70',
        ]);
        $days     = $v['days']      ?? 7;
        $limit    = $v['limit']     ?? 50;
        $minScore = $v['min_score'] ?? \App\Services\FreshMailMatchingService::RESULT_SCORE_FLOOR;

        $results = $this->freshMatching->freshProjectMails($ems, $days, $limit, $minScore);
        $statusMap = $this->proposalStatus->buildPmsStatusMap(
            $results->pluck('pms'),
            $ems,
        );

        return response()->json([
            'days'      => $days,
            'min_score' => $minScore,
            'count'     => $results->count(),
            'data'      => $results->map(function ($r) use ($statusMap) {
                $pms    = $r['pms'];
                $status = $statusMap[$pms->id] ?? ['badge' => 'new'];
                return [
                    'project_mail_id'    => $pms->id,
                    'customer_name'      => $pms->customer_name,
                    'sales_contact'      => $pms->sales_contact,
                    'title'              => $pms->title,
                    'required_skills'    => $pms->required_skills,
                    'work_location'      => $pms->work_location,
                    'remote_ok'          => $pms->remote_ok,
                    'unit_price_min'     => $pms->unit_price_min,
                    'unit_price_max'     => $pms->unit_price_max,
                    'start_date'         => $pms->start_date,
                    'contract_type'      => $pms->contract_type,
                    'received_at'        => $pms->received_at?->toIso8601String(),
                    'email_from_address' => $pms->email?->from_address,
                    'email_subject'      => $pms->email?->subject,
                    'email_body'         => self::pickMailBody($pms->email),
                    'score'              => $r['score'],
                    'breakdown'          => $r['breakdown'],
                    'reasons'            => $r['reasons'],
                    'badge'              => $status['badge'],
                ];
            }),
        ]);
    }

    /**
     * 鮮度マッチング: PMS を起点に提案メール送信
     * POST /v1/engineer-mails/{id}/send-proposal-from-pms
     * docs/470_fresh_mail_matching.md §8.5
     */
    public function sendProposalFromPms(Request $request, int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $ems = EngineerMailSource::where('tenant_id', $tenantId)->findOrFail($id);

        $v = $request->validate([
            'project_mail_id' => 'required|integer',
            'to'      => 'required|email',
            'to_name' => 'nullable|string|max:255',
            'subject' => 'required|string|max:500',
            'body'    => 'required|string',
        ]);

        $pms = ProjectMailSource::where('tenant_id', $tenantId)->findOrFail($v['project_mail_id']);

        $userId      = auth()->id();
        $senderName  = auth()->user()->name  ?? '';
        $senderEmail = $this->replyToAddress();

        $campaign = DeliveryCampaign::create([
            'tenant_id'               => $tenantId,
            'send_type'               => 'engineer_proposal',
            'project_mail_id'         => $pms->id,
            'engineer_mail_source_id' => $ems->id,
            'user_id'                 => $userId,
            'subject'                 => $v['subject'],
            'body'                    => $v['body'],
            'total_count'             => 1,
            'success_count'           => 0,
            'failed_count'            => 0,
            'sent_at'                 => now(),
        ]);

        $messageId = '<' . Str::uuid() . '@aizen-sol.co.jp>';
        try {
            Mail::to($v['to'])->send(new ProposalMail($v['subject'], $v['body'], $senderName, $senderEmail, [], $messageId));
            DeliverySendHistory::create([
                'tenant_id'      => $tenantId,
                'campaign_id'    => $campaign->id,
                'email'          => $v['to'],
                'name'           => $v['to_name'] ?? null,
                'status'         => 'sent',
                'ses_message_id' => $messageId,
            ]);
            $campaign->update(['success_count' => 1]);
            Log::info("鮮度マッチング(EM側) 提案送信 ems_id={$id} project_mail_id={$pms->id} to={$v['to']}");
            return response()->json(['message' => '送信しました']);
        } catch (\Exception $e) {
            DeliverySendHistory::create([
                'tenant_id'      => $tenantId,
                'campaign_id'    => $campaign->id,
                'email'          => $v['to'],
                'name'           => $v['to_name'] ?? null,
                'status'         => 'failed',
                'ses_message_id' => $messageId,
                'error_message'  => $e->getMessage(),
            ]);
            $campaign->update(['failed_count' => 1]);
            Log::error("鮮度マッチング(EM側) 提案失敗 ems_id={$id} project_mail_id={$pms->id}: " . $e->getMessage());
            return response()->json(['message' => 'メール送信に失敗しました'], 500);
        }
    }

    private function replyToAddress(): string
    {
        return config('mail.reply_to.address', config('mail.from.address')) ?? '';
    }

    /**
     * まとめて提案: 技術者所属(BP=EMS送信者) 宛てに複数案件をまとめて送信
     * POST /v1/engineer-mails/{id}/send-bulk-to-bp
     * /matching/[id] の sendBulk と対称。EMS起点・PMS群を本文に列挙して 1通送る。
     */
    public function sendBulkToBp(Request $request, int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        EngineerMailSource::where('tenant_id', $tenantId)->findOrFail($id);

        $v = $request->validate([
            'recipients'         => 'required|array|min:1|max:100',
            'recipients.*.to'    => 'required|email',
            'recipients.*.name'  => 'nullable|string|max:200',
            'subject'            => 'required|string|max:500',
            'body'               => 'required|string',
            // 履歴用 (任意): まとめた案件 id 群。public_projects と project_mail_sources 双方を受け付ける
            'project_ids'        => 'nullable|array|max:50',
            'project_ids.*'      => 'integer',
            'project_mail_ids'   => 'nullable|array|max:50',
            'project_mail_ids.*' => 'integer',
        ]);

        $sent        = 0;
        $failed      = [];
        $userId      = auth()->id();
        $senderName  = auth()->user()->name  ?? '';
        $senderEmail = $this->replyToAddress();

        $campaign = DeliveryCampaign::create([
            'tenant_id'               => $tenantId,
            'send_type'               => 'engineer_proposal_bulk',
            'engineer_mail_source_id' => $id,
            'user_id'                 => $userId,
            'subject'                 => $v['subject'],
            'body'                    => $v['body'],
            'total_count'             => count($v['recipients']),
            'success_count'           => 0,
            'failed_count'            => 0,
            'sent_at'                 => now(),
        ]);

        foreach ($v['recipients'] as $recipient) {
            $messageId = '<' . Str::uuid() . '@aizen-sol.co.jp>';
            try {
                Mail::to($recipient['to'])->send(new ProposalMail($v['subject'], $v['body'], $senderName, $senderEmail, [], $messageId));
                DeliverySendHistory::create([
                    'tenant_id'      => $tenantId,
                    'campaign_id'    => $campaign->id,
                    'email'          => $recipient['to'],
                    'name'           => $recipient['name'] ?? null,
                    'status'         => 'sent',
                    'ses_message_id' => $messageId,
                ]);
                $sent++;
            } catch (\Exception $e) {
                DeliverySendHistory::create([
                    'tenant_id'      => $tenantId,
                    'campaign_id'    => $campaign->id,
                    'email'          => $recipient['to'],
                    'name'           => $recipient['name'] ?? null,
                    'status'         => 'failed',
                    'ses_message_id' => $messageId,
                    'error_message'  => $e->getMessage(),
                ]);
                Log::error("BP一括提案送信失敗 engineer_mail_id={$id} to={$recipient['to']}: " . $e->getMessage());
                $failed[] = $recipient['to'];
            }
        }

        $campaign->update([
            'success_count' => $sent,
            'failed_count'  => count($failed),
        ]);

        Log::info("BP一括提案送信完了 engineer_mail_id={$id} sent={$sent} failed=" . count($failed));

        return response()->json([
            'message' => "{$sent}件送信しました",
            'sent'    => $sent,
            'failed'  => $failed,
        ]);
    }

    /**
     * 元メール本文取得: body_text 優先、空なら body_html を strip-tags してフォールバック
     * HTML-only で取り込まれたメール (Kagoya IMAP 経由など、約7%) を表示するための共通処理
     */
    public static function pickMailBody(?\App\Models\Email $email): ?string
    {
        if (!$email) return null;
        $text = trim((string) ($email->body_text ?? ''));
        if ($text !== '') return $text;
        $html = (string) ($email->body_html ?? '');
        if (trim($html) === '') return null;
        $stripped = preg_replace('#<style[\s\S]*?</style>#i', '', $html);
        $stripped = preg_replace('#<script[\s\S]*?</script>#i', '', $stripped);
        // <br>, <br/>, <br style="..."> など属性付きも改行に
        $stripped = preg_replace('#<br\b[^>]*>#i', "\n", $stripped);
        $stripped = preg_replace('#</(td|th)>#i', "\t", $stripped);
        $stripped = preg_replace('#</(tr|thead|tbody|table|p|div|li|h[1-6])>#i', "\n", $stripped);
        $stripped = strip_tags($stripped);
        $stripped = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stripped = preg_replace("/[ \t]+\n/", "\n", $stripped);
        $stripped = preg_replace("/\n{3,}/", "\n\n", $stripped);
        $stripped = trim($stripped);
        return $stripped !== '' ? $stripped : null;
    }
}
