<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ProposalMail;
use App\Models\DeliveryCampaign;
use App\Models\DeliverySendHistory;
use App\Models\Engineer;
use App\Models\EngineerMailSource;
use App\Models\EngineerProfile;
use App\Models\EngineerSkill;
use App\Models\Email;
use App\Models\ProjectMailSource;
use App\Models\RescoreJob;
use App\Models\Skill;
use App\Services\ClaudeService;
use App\Services\DeliveryCampaignService;
use App\Services\FreshMailMatchingService;
use App\Services\ProjectMailMatchingService;
use App\Services\EngineerMailScoringService;
use App\Services\ProjectMailScoringService;
use App\Services\ProposalStatusService;
use App\Services\RequirementMatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ProjectMailController extends Controller
{
    use \App\Traits\UsesSenderDisplayName;

    public function __construct(
        private ProjectMailScoringService    $scoringService,
        private ProjectMailMatchingService   $matchingService,
        private ClaudeService                $claudeService,
        private FreshMailMatchingService     $freshMatching,
        private ProposalStatusService        $proposalStatus,
        private RequirementMatchingService   $requirementMatching,
    ) {}

    // 一覧
    public function index(Request $request)
    {
        $perPage   = $request->integer('per_page', 30);
        $status    = $request->input('status');    // new / review / proposed / interview / won / lost / excluded
        $scoreMin  = $request->integer('score_min', 0);
        $scoreMax  = $request->integer('score_max', 100);
        $search    = $request->input('search');
        // 取り込み元フィルタ (E-3 2026-05-29)。
        // 既定は 'imap' (通常メール取込) で手動登録を除外。
        // 'manual' を渡すと /project-mails/manual ページが手動登録だけを表示する。
        $source    = $request->input('source', 'imap');

        // 並び・表示は arrived_at (Kagoya 着信時刻) 基準。received_at (送信時刻) は送信表示用に併せて取得。
        // sort はホワイトリスト方式（任意カラム injection 防止）。既定は arrived_at 降順。
        $sortable = ['arrived_at', 'score', 'unit_price_max', 'received_at'];
        $sort     = in_array($request->input('sort'), $sortable, true) ? $request->input('sort') : 'arrived_at';
        $order    = strtolower($request->input('order', 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = ProjectMailSource::with(['email:id,subject,from_name,from_address,received_at,arrived_at'])
            ->whereBetween('score', [$scoreMin, $scoreMax])
            ->where('source', $source);
        // 死にデータ（本文・HTML本文ともに NULL/空 = 分類30日超で本文 purge 済み）を既定で隠す。
        // 本文が無いと提案も再抽出もできず一覧のノイズになるため (2026-06-22 営業要望)。
        // include_purged=1 で従来どおり全件表示に戻せる（可逆）。
        if (!$request->boolean('include_purged')) {
            $query->whereHas('email', function ($eq) {
                $eq->where(fn ($q) => $q->whereNotNull('body_text')->where('body_text', '<>', ''))
                   ->orWhere(fn ($q) => $q->whereNotNull('body_html')->where('body_html', '<>', ''));
            });
        }
        // NULL（単価なし等）を常に末尾へ。降順だと Postgres は NULL を最大扱いで先頭に出すため。
        // $sort/$order はホワイトリスト済みなので raw 補間は安全。pgsql 以外は NULLS 構文非対応なので素の orderBy。
        if (DB::connection()->getDriverName() === 'pgsql') {
            $query->orderByRaw("{$sort} {$order} NULLS LAST");
        } else {
            $query->orderBy($sort, $order);
        }
        $query->orderByDesc('id'); // tie-break（同値時のページング安定化）

        if ($status) {
            $query->where('status', $status);
        }
        // status 未指定（「全て」タブ）は excluded も含む全件表示（2026-06-06 仕様変更）。

        if ($search) {
            $searchBody = $request->boolean('search_body');
            // 本文検索ガード (2026-06-02 GIN index 撤去後): body_text は Seq Scan フォールバック。
            // 5 文字未満は全件走査で暴走するためここで弾く。
            if ($searchBody && mb_strlen((string) $search) < 5) {
                $searchBody = false;
            }
            $query->where(function ($q) use ($search, $searchBody) {
                $q->where('title', 'ilike', "%{$search}%")
                  ->orWhere('customer_name', 'ilike', "%{$search}%")
                  ->orWhere('work_location', 'ilike', "%{$search}%")
                  ->orWhere('sales_contact', 'ilike', "%{$search}%")
                  ->orWhereHas('email', function ($eq) use ($search, $searchBody) {
                      $eq->where('from_address', 'ilike', "%{$search}%")
                         ->orWhere('from_name', 'ilike', "%{$search}%")
                         ->orWhere('subject', 'ilike', "%{$search}%");
                      if ($searchBody) {
                          $eq->orWhere('body_text', 'ilike', "%{$search}%");
                      }
                  });
            });
        }

        return response()->json($query->paginate($perPage));
    }

    // 技術者メールへ移動（案件として誤分類されたメールの手動再分類）。
    // 元 email の category を engineer に変え、技術者スコアリングで ems を生成し、
    // 案件側 pms を削除する（email 本体・添付は保持）。営業要望 2026-06-22 #1。
    public function moveToEngineer(int $id, EngineerMailScoringService $engineerScoring): JsonResponse
    {
        $pms   = ProjectMailSource::with('email')->findOrFail($id);
        $email = $pms->email;
        if (!$email) {
            return response()->json(['message' => '元メールが見つからないため移動できません'], 422);
        }

        $ems = DB::transaction(function () use ($pms, $email, $engineerScoring) {
            $email->update(['category' => 'engineer']);
            // 過去に技術者→案件へ移動した履歴があると ems が soft-delete で残っており、
            // score() の updateOrCreate が trashed 行を見ずに重複 INSERT する。先に restore して更新に寄せる。
            EngineerMailSource::withTrashed()->where('email_id', $email->id)->restore();
            $created = $engineerScoring->score($email); // updateOrCreate(email_id) で冪等
            $pms->delete(); // soft delete（email 本体・添付は保持）
            return $created;
        });

        Log::info("[move] project→engineer email_id={$email->id} pms_id={$id} ems_id={$ems->id}");

        return response()->json([
            'message'          => '技術者メールへ移動しました',
            'engineer_mail_id' => $ems->id,
        ]);
    }

    // 詳細（元メール・添付含む）
    public function show(int $id)
    {
        $pms = ProjectMailSource::with([
            'email.attachments',
        ])->findOrFail($id);

        return response()->json($pms);
    }

    // 手動登録: ユーザーが LINE や個別メール等から受け取った案件情報を直接登録する
    // (E-3 営業打ち合わせ 2026-05-25)。ダミー emails 行を作成して既存スコアリング・
    // マッチング・提案送信ロジックをそのまま流用する。
    public function storeManual(Request $request): JsonResponse
    {
        $user     = auth()->user();
        $tenantId = $user->tenant_id;

        $v = $request->validate([
            'customer_name'    => 'required|string|max:200',
            'sales_contact'    => 'nullable|string|max:100',
            'phone'            => 'nullable|string|max:50',
            'from_address'     => 'nullable|email|max:255',
            'title'            => 'required|string|max:300',
            'required_skills'  => 'nullable|array',
            'required_skills.*'=> 'string|max:100',
            'preferred_skills' => 'nullable|array',
            'preferred_skills.*'=> 'string|max:100',
            'process'          => 'nullable|array',
            'process.*'        => 'string|max:100',
            'work_location'    => 'nullable|string|max:200',
            'remote_ok'        => 'nullable|boolean',
            'unit_price_min'   => 'nullable|numeric|min:0',
            'unit_price_max'   => 'nullable|numeric|min:0',
            'age_limit'        => 'nullable|string|max:50',
            'nationality_ok'   => 'nullable|boolean',
            'contract_type'    => 'nullable|string|max:50',
            'start_date'       => 'nullable|string|max:50',
            'supply_chain'     => 'nullable|integer|min:1|max:9',
            'body_text'        => 'nullable|string',
        ]);

        // スコアリングの calcScore は body_text に対する語彙マッチで点数を出すため、
        // 手入力された構造化フィールドを本文相当のテキストに整形して食わせる。
        // ユーザーが追加の自由記述 body_text を入れた場合は連結する。
        $syntheticBody = $this->buildProjectBody($v);

        $pms = DB::transaction(function () use ($v, $tenantId, $user, $syntheticBody) {
            // from_address を自社ドメインにすると ProjectMailScoringService::isExcluded で
            // EXCLUDE_DOMAIN にひっかかり score=0 + status=excluded で保存される。
            // 手動登録の意図に反するので、未指定時は外部ダミードメインを使う。
            $email = Email::create([
                'tenant_id'        => $tenantId,
                'gmail_message_id' => 'manual-project-' . Str::uuid()->toString(),
                'subject'          => $v['title'],
                'from_address'     => $v['from_address'] ?? 'manual+' . $tenantId . '@manual.invalid',
                'from_name'        => $v['customer_name'],
                'to_address'       => $user->email,
                'body_text'        => $syntheticBody,
                'received_at'      => now(),
                'arrived_at'       => now(),
                'is_read'          => true,
                'category'         => 'manual_project',
            ]);

            // 既存スコアリング (rule engine) を流して PMS を生成
            $created = $this->scoringService->score($email);

            // 自動抽出で取りこぼした手入力値で上書き (スコアと email_id は保持)。
            // status は手動登録の意図を尊重して 'new' に強制 (低スコアでも全件 + 新着で表示)。
            // source='manual' で /project-mails (通常) と /project-mails/manual を分離する。
            $created->update([
                'source'           => 'manual',
                'status'           => 'new',
                'customer_name'    => $v['customer_name'],
                'sales_contact'    => $v['sales_contact']    ?? $created->sales_contact,
                'phone'            => $v['phone']            ?? $created->phone,
                'title'            => $v['title'],
                'required_skills'  => $v['required_skills']  ?? $created->required_skills,
                'preferred_skills' => $v['preferred_skills'] ?? $created->preferred_skills,
                'process'          => $v['process']          ?? $created->process,
                'work_location'    => $v['work_location']    ?? $created->work_location,
                'remote_ok'        => array_key_exists('remote_ok', $v)      ? $v['remote_ok']      : $created->remote_ok,
                'unit_price_min'   => $v['unit_price_min']   ?? $created->unit_price_min,
                'unit_price_max'   => $v['unit_price_max']   ?? $created->unit_price_max,
                'age_limit'        => $v['age_limit']        ?? $created->age_limit,
                'nationality_ok'   => array_key_exists('nationality_ok', $v) ? $v['nationality_ok'] : $created->nationality_ok,
                'contract_type'    => $v['contract_type']    ?? $created->contract_type,
                'start_date'       => $v['start_date']       ?? $created->start_date,
                'supply_chain'     => $v['supply_chain']     ?? $created->supply_chain,
            ]);

            return $created->fresh('email');
        });

        return response()->json($pms, 201);
    }

    private function buildProjectBody(array $v): string
    {
        $lines = [];
        $lines[] = '案件ご紹介';
        $lines[] = '顧客名: ' . $v['customer_name'];
        if (!empty($v['sales_contact'])) $lines[] = '担当: ' . $v['sales_contact'];
        if (!empty($v['phone']))         $lines[] = 'TEL: '  . $v['phone'];
        $lines[] = 'タイトル: ' . $v['title'];
        if (!empty($v['required_skills']))  $lines[] = '必須スキル: '  . implode(', ', $v['required_skills']);
        if (!empty($v['preferred_skills'])) $lines[] = '尚可スキル: '  . implode(', ', $v['preferred_skills']);
        if (!empty($v['process']))          $lines[] = '工程: '        . implode(', ', $v['process']);
        if (!empty($v['work_location']))    $lines[] = '勤務地: '      . $v['work_location'];
        if (array_key_exists('remote_ok', $v) && $v['remote_ok'] !== null) {
            $lines[] = 'リモート: ' . ($v['remote_ok'] ? '可' : '不可');
        }
        $min = $v['unit_price_min'] ?? null;
        $max = $v['unit_price_max'] ?? null;
        if ($min !== null && $max !== null) $lines[] = "単価: {$min}〜{$max}万";
        elseif ($min !== null)              $lines[] = "単価: {$min}万";
        elseif ($max !== null)              $lines[] = "単価: 〜{$max}万";
        if (!empty($v['age_limit']))     $lines[] = '年齢: ' . $v['age_limit'];
        if (!empty($v['contract_type'])) $lines[] = '契約: ' . $v['contract_type'];
        if (!empty($v['start_date']))    $lines[] = '開始時期: ' . $v['start_date'];
        if (!empty($v['supply_chain'])) {
            $map = [1 => '一次', 2 => '二次', 3 => '三次'];
            $lines[] = '商流: ' . ($map[$v['supply_chain']] ?? "{$v['supply_chain']}次") . '請け';
        }
        if (!empty($v['body_text'])) {
            $lines[] = '';
            $lines[] = $v['body_text'];
        }
        return implode("\n", $lines);
    }

    // 抽出情報の手動修正
    public function update(Request $request, int $id)
    {
        $pms = ProjectMailSource::findOrFail($id);

        $v = $request->validate([
            'customer_name'    => 'nullable|string|max:200',
            'sales_contact'    => 'nullable|string|max:100',
            'phone'            => 'nullable|string|max:50',
            'title'            => 'nullable|string|max:300',
            'required_skills'  => 'nullable|array',
            'required_skills.*'=> 'string|max:100',
            'preferred_skills' => 'nullable|array',
            'preferred_skills.*'=> 'string|max:100',
            'process'          => 'nullable|array',
            'process.*'        => 'string|max:100',
            'work_location'    => 'nullable|string|max:200',
            'remote_ok'        => 'nullable|boolean',
            'unit_price_min'   => 'nullable|numeric|min:0',
            'unit_price_max'   => 'nullable|numeric|min:0',
            'age_limit'        => 'nullable|string|max:50',
            'nationality_ok'   => 'nullable|boolean',
            'contract_type'    => 'nullable|string|max:50',
            'start_date'       => 'nullable|string|max:50',
            'supply_chain'     => 'nullable|integer|min:1|max:9',
        ]);

        $pms->update($v);

        return response()->json($pms->fresh());
    }

    // 右ペインから「メモ・備考」を関連 email の本文末尾に追記する (手動登録のみ)。
    // 手動登録はダミー email を持つため本文書き換えが安全。IMAP 受信メールの本文は
    // 改変しないよう source='manual' 以外は拒否する。再スコアは行わない (メモ用途)。
    public function appendMemo(Request $request, int $id): JsonResponse
    {
        $v = $request->validate(['body_text' => 'required|string|max:10000']);

        $pms = ProjectMailSource::with('email')->findOrFail($id);
        if ($pms->source !== 'manual') {
            return response()->json(['message' => '手動登録の案件のみメモを追記できます'], 422);
        }
        if (!$pms->email) {
            return response()->json(['message' => '関連メールがありません'], 422);
        }

        $memo = trim($v['body_text']);
        $pms->email->body_text = rtrim((string) $pms->email->body_text) . "\n\n" . $memo;
        $pms->email->save();

        return response()->json($pms->fresh(['email.attachments']));
    }

    // 案件メール(ProjectMailSource)を論理削除する。
    // SoftDeletes により一覧(global scope)から除外される。実メール(emails)・添付は
    // 保持する（emails.email_id は onDelete cascade のため email 本体には触らない）。復元可能。
    public function destroy(int $id)
    {
        $pms = ProjectMailSource::findOrFail($id);
        $this->ensureSameTenantForDestructive($pms);

        $pms->delete();

        return response()->json(null, 204);
    }

    // ステータス変更
    public function updateStatus(Request $request, int $id)
    {
        $pms = ProjectMailSource::findOrFail($id);

        $v = $request->validate([
            'status'      => 'required|in:new,review,proposed,interview,won,lost,excluded',
            'lost_reason' => 'nullable|string',
        ]);

        $pms->update($v);

        return response()->json($pms->fresh());
    }

    // 再スコアリング（手動トリガー）
    public function rescore(int $id)
    {
        $pms = ProjectMailSource::with('email')->findOrFail($id);

        try {
            $updated = $this->scoringService->score($pms->email);
            return response()->json($updated->fresh());
        } catch (\Exception $e) {
            Log::error("Rescore failed email_id={$pms->email_id}: " . $e->getMessage());
            return response()->json(['message' => '再スコアリングに失敗しました'], 500);
        }
    }

    // 全件再スコアリングを非同期ジョブとして登録（Schedule tick が処理。docs #4）
    /**
     * Shadow rescore (UPDATE 無し・診断専用)。
     *
     * ルール変更ブランチで実行 → どれだけの行が status を変えるか定量化。
     * 半日かかる本番 rescore 失敗からの後追い修正を避けるための pre-deploy 検証 (docs/730 #1)。
     *
     * パラメータ:
     *   - limit (int, optional): 1 リクエストで処理する件数
     *   - offset (int, optional): バッチ開始位置
     *   - tenant_id (int, optional): 特定テナントのみ
     */
    public function rescoreAllShadow(Request $request): JsonResponse
    {
        $user = auth()->user();
        if (!$user || !in_array($user->role, ['tenant_admin', 'super_admin'], true)) {
            return response()->json(['message' => '管理者権限が必要です'], 403);
        }
        set_time_limit(300);
        ini_set('memory_limit', '768M');

        $limit    = $request->integer('limit') ?: null;
        $offset   = $request->integer('offset', 0);
        $tenantId = $request->integer('tenant_id') ?: $user->tenant_id;

        $t0 = microtime(true);
        $stats = $this->scoringService->rescoreAllShadow($limit, $offset, $tenantId);
        $stats['elapsed_ms'] = (int) round((microtime(true) - $t0) * 1000);

        return response()->json($stats);
    }

    public function rescoreAll(Request $request): JsonResponse
    {
        // 既に未完了の同種ジョブがあればそれを返す（二重起動防止）
        $existing = RescoreJob::where('type', RescoreJob::TYPE_PROJECT)
            ->whereIn('status', RescoreJob::ACTIVE_STATUSES)
            ->orderByDesc('id')
            ->first();
        if ($existing) {
            return response()->json([
                'message' => '再スコアリングは既に実行中です',
                'job'     => $existing,
            ], 202);
        }

        $total = ProjectMailSource::whereNotNull('email_id')->count();
        $job = RescoreJob::create([
            'type'         => RescoreJob::TYPE_PROJECT,
            'status'       => RescoreJob::STATUS_PENDING,
            'total_count'  => $total,
            'requested_by' => auth()->id(),
        ]);

        return response()->json([
            'message' => '再スコアリングを開始しました（バックグラウンドで処理されます）',
            'job'     => $job,
        ], 202);
    }

    // 直近の再スコアリングジョブの進捗を返す（フロントのポーリング用。docs #4）
    public function rescoreStatus(): JsonResponse
    {
        $job = RescoreJob::where('type', RescoreJob::TYPE_PROJECT)
            ->orderByDesc('id')
            ->first();

        return response()->json(['job' => $job]);
    }

    // 既存レコードの抽出情報を一括再計算（バッチ処理対応）
    public function reextractAll(Request $request): JsonResponse
    {
        set_time_limit(120);
        ini_set('memory_limit', '512M');
        $batchSize = 300;
        $offset    = $request->integer('offset', 0);
        $count     = $this->scoringService->reextractAll($batchSize, $offset);
        $total     = ProjectMailSource::whereNotNull('email_id')->count();
        $remaining = max(0, $total - ($offset + $count));

        return response()->json([
            'message'   => "{$count}件の抽出情報を更新しました",
            'count'     => $count,
            'remaining' => $remaining,
            'offset'    => $offset + $count,
        ]);
    }

    /**
     * 提案メール草稿を生成
     * POST /v1/project-mails/{id}/generate-proposal
     */
    public function generateProposal(Request $request, int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $mail     = ProjectMailSource::with('email')->where('tenant_id', $tenantId)->findOrFail($id);

        $v = $request->validate(['engineer_id' => 'required|integer']);

        $engineer = \App\Models\Engineer::with(['profile', 'engineerSkills.skill'])
            ->where('tenant_id', $tenantId)
            ->findOrFail($v['engineer_id']);

        $mailData = [
            'title'           => $mail->title,
            'email_subject'   => $mail->email?->subject,
            'from_address'    => $mail->email?->from_address,
            'from_name'       => $mail->email?->from_name,
            'sales_contact'   => $mail->sales_contact,
            'required_skills' => $mail->required_skills ?? [],
            'work_location'   => $mail->work_location,
            'unit_price_min'  => $mail->unit_price_min,
            'unit_price_max'  => $mail->unit_price_max,
        ];

        $engineerData = [
            'name'                    => $engineer->name,
            'age'                     => $engineer->age,
            'affiliation'             => $engineer->affiliation,
            'availability_status'     => $engineer->profile?->availability_status,
            'available_from'          => $engineer->profile?->available_from,
            'desired_unit_price_min'  => $engineer->profile?->desired_unit_price_min,
            'desired_unit_price_max'  => $engineer->profile?->desired_unit_price_max,
            'skills' => $engineer->engineerSkills->map(fn($es) => [
                'name'             => $es->skill?->name,
                'experience_years' => $es->experience_years,
            ])->values()->toArray(),
        ];

        try {
            $draft = $this->claudeService->generateProposal($mailData, $engineerData);
            return response()->json($draft);
        } catch (\App\Exceptions\ClaudeOverloadedException $e) {
            Log::warning("generateProposal overloaded mail_id={$id}: " . $e->getMessage());
            return response()->json([
                'message' => 'Claude API が混雑しています。しばらく待ってから再試行してください。',
                'code'    => 'claude_overloaded',
            ], 503);
        } catch (\Exception $e) {
            Log::error("generateProposal failed mail_id={$id}: " . $e->getMessage());
            return response()->json(['message' => 'メール生成に失敗しました'], 500);
        }
    }

    /**
     * 提案メール送信
     * POST /v1/project-mails/{id}/send-proposal
     */
    public function sendProposal(Request $request, int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        ProjectMailSource::where('tenant_id', $tenantId)->findOrFail($id);

        $v = $request->validate([
            'to'            => 'required|email',
            'to_name'       => 'nullable|string|max:255',
            'subject'       => 'required|string|max:500',
            'body'          => 'required|string',
            'attachments'   => 'nullable|array',
            'attachments.*' => 'file|max:10240',
        ]);

        $service = new DeliveryCampaignService(
            tenantId: $tenantId,
            userId:   auth()->id(),
        );
        $result = $service->sendSingleProposal([
            'send_type'         => 'proposal',
            'project_mail_id'   => $id,
            'to'                => $v['to'],
            'to_name'           => $v['to_name'] ?? null,
            'subject'           => $v['subject'],
            'body'              => $v['body'],
            'attachment_paths'  => $this->moveProposalAttachments($request),
            'sender_name'       => auth()->user()->name ?? '',
            'sender_email'      => $this->replyToAddress(),
            'from_display_name' => $this->senderDisplayName(),
            'log_context'       => ['project_mail_id' => $id],
        ]);

        return $result['success']
            ? response()->json(['message' => '送信しました'])
            : response()->json(['message' => 'メール送信に失敗しました'], 500);
    }

    /** request->file('attachments') を一時ディレクトリへ移動してパス配列を返す。 */
    private function moveProposalAttachments(Request $request, string $subdir = 'proposals'): array
    {
        if (!$request->hasFile('attachments')) return [];
        $dir = storage_path("app/temp/{$subdir}/" . uniqid());
        @mkdir($dir, 0755, true);
        $paths = [];
        foreach ($request->file('attachments') as $file) {
            $dest = $dir . '/' . $file->getClientOriginalName();
            $file->move($dir, $file->getClientOriginalName());
            $paths[] = $dest;
        }
        return $paths;
    }

    /**
     * 一斉配信
     * POST /v1/project-mails/{id}/send-bulk
     */
    public function sendBulk(Request $request, int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        ProjectMailSource::where('tenant_id', $tenantId)->findOrFail($id);

        $v = $request->validate([
            'recipients'         => 'required|array|min:1|max:100',
            'recipients.*.to'   => 'required|email',
            'recipients.*.name' => 'nullable|string|max:200',
            'subject'            => 'required|string|max:500',
            'body'               => 'required|string',
        ]);

        $service = new DeliveryCampaignService(
            tenantId: $tenantId,
            userId:   auth()->id(),
        );
        $result = $service->sendBulkProposal([
            'send_type'         => 'bulk',
            'project_mail_id'   => $id,
            'recipients'        => $v['recipients'],
            'subject'           => $v['subject'],
            'body'              => $v['body'],
            'sender_name'       => auth()->user()->name ?? '',
            'sender_email'      => $this->replyToAddress(),
            'from_display_name' => $this->senderDisplayName(),
            'log_context'       => ['project_mail_id' => $id],
        ]);

        return response()->json([
            'message' => "{$result['sent']}件送信しました",
            'sent'    => $result['sent'],
            'failed'  => $result['failed'],
        ]);
    }

    /**
     * スレッド会話履歴
     * GET /v1/project-mails/{id}/thread
     */
    public function thread(int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        ProjectMailSource::where('tenant_id', $tenantId)->findOrFail($id);

        $campaigns = DeliveryCampaign::with(['sendHistories' => function ($q) {
                // deliveryタイプは返信ありのみロード（大量送信履歴の対策）
                $q->with(['replyEmail.attachments']);
            }])
            ->where('tenant_id', $tenantId)
            ->where('project_mail_id', $id)
            ->whereIn('send_type', DeliveryCampaign::PROJECT_PROPOSAL_TYPES)
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
                // 一斉配信: 送信サマリー1件 + 返信のみ
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
                // 個別提案: 従来通り
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

    /**
     * 案件メールに対するマッチング技術者一覧
     * GET /v1/project-mails/{id}/matched-engineers
     */
    public function matchedEngineers(int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $mail = ProjectMailSource::where('tenant_id', $tenantId)->findOrFail($id);

        $results = $this->matchingService->matchEngineers($mail, 20);

        return response()->json([
            'data' => $results->map(fn($r) => [
                'engineer_id'         => $r['engineer']->id,
                'engineer_name'       => $r['engineer']->name,
                'email'               => $r['engineer']->email,
                'affiliation'         => $r['engineer']->affiliation,
                'affiliation_contact' => $r['engineer']->affiliation_contact,
                'affiliation_email'   => $r['engineer']->affiliation_email,
                'affiliation_type'    => $r['engineer']->affiliation_type,
                'engineer_mail_source_id' => $r['engineer']->engineer_mail_source_id,
                'age'                 => $r['engineer']->age,
                'score'            => $r['score'],
                'breakdown'        => $r['breakdown'],
                'reasons'          => $r['reasons'],
                'flags'            => $r['flags'] ?? null,
                'availability_status'    => $r['engineer']->profile?->availability_status,
                'available_from'         => $r['engineer']->profile?->available_from,
                'work_style'             => $r['engineer']->profile?->work_style,
                'desired_unit_price_min' => $r['engineer']->profile?->desired_unit_price_min,
                'desired_unit_price_max' => $r['engineer']->profile?->desired_unit_price_max,
                'skills' => $r['engineer']->engineerSkills->map(fn($es) => [
                    'name'             => $es->skill?->name,
                    'experience_years' => $es->experience_years,
                ])->values(),
            ]),
        ]);
    }

    /**
     * 鮮度マッチング: 過去N日の EngineerMailSource を案件メールに対してスコアリング
     * GET /v1/project-mails/{id}/fresh-engineer-mails?days=7
     * docs/470_fresh_mail_matching.md §8.4
     */
    public function freshEngineerMails(Request $request, int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $mail = ProjectMailSource::where('tenant_id', $tenantId)->findOrFail($id);

        $v = $request->validate([
            'days'      => 'nullable|integer|min:1|max:30',
            'limit'     => 'nullable|integer|min:1|max:200',
            'min_score' => 'nullable|integer|in:50,60,70',
        ]);
        $days     = $v['days']      ?? 7;
        $limit    = $v['limit']     ?? 50;
        $minScore = $v['min_score'] ?? \App\Services\FreshMailMatchingService::RESULT_SCORE_FLOOR;

        $results = $this->freshMatching->freshEngineerMails($mail, $days, $limit, $minScore);
        $statusMap = $this->proposalStatus->buildEmsStatusMap(
            $results->pluck('ems'),
            $mail,
        );

        return response()->json([
            'days'      => $days,
            'min_score' => $minScore,
            'count'     => $results->count(),
            'data'      => $results->map(function ($r) use ($statusMap) {
                $ems    = $r['ems'];
                $status = $statusMap[$ems->id] ?? ['badge' => 'new', 'engineer_id' => null];
                return [
                    'engineer_mail_source_id' => $ems->id,
                    'name'                    => $ems->name,
                    'age'                     => $ems->age,
                    'affiliation'             => $ems->affiliation,
                    'affiliation_type'        => $ems->affiliation_type,
                    'nearest_station'         => $ems->nearest_station,
                    'skills'                  => $ems->skills,
                    'unit_price_min'          => $ems->unit_price_min,
                    'unit_price_max'          => $ems->unit_price_max,
                    'available_from'          => $ems->available_from,
                    'received_at'             => $ems->received_at?->toIso8601String(),
                    'email_from_address'      => $ems->email?->from_address,
                    'email_subject'           => $ems->email?->subject,
                    'email_body'              => EngineerMailController::pickMailBody($ems->email),
                    'score'                   => $r['score'],
                    'breakdown'               => $r['breakdown'],
                    'reasons'                 => $r['reasons'],
                    'flags'                   => $r['flags'] ?? null,
                    'badge'                   => $status['badge'],
                    'registered_engineer_id'  => $status['engineer_id'],
                ];
            }),
        ]);
    }

    /**
     * 鮮度マッチング: EMS を起点に Engineer 化（重複検出）→ 提案メール送信
     * POST /v1/project-mails/{id}/send-proposal-from-ems
     * docs/470_fresh_mail_matching.md §8.5
     */
    public function sendProposalFromEms(Request $request, int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $mail = ProjectMailSource::where('tenant_id', $tenantId)->findOrFail($id);

        $v = $request->validate([
            'engineer_mail_source_id' => 'required|integer',
            'to'      => 'required|email',
            'to_name' => 'nullable|string|max:255',
            'subject' => 'required|string|max:500',
            'body'    => 'required|string',
        ]);

        $ems = EngineerMailSource::with('email')
            ->where('tenant_id', $tenantId)
            ->findOrFail($v['engineer_mail_source_id']);

        // ── 1. Engineer 化（重複検出 → 既存再利用 or 新規作成）────
        $engineer = DB::transaction(function () use ($ems, $tenantId) {
            $existing = $this->findExistingEngineer($ems, $tenantId);
            if ($existing) return $existing;
            return $this->createEngineerFromEms($ems);
        });

        // ── 2. 提案メール送信（トランザクション外）────
        $service = new DeliveryCampaignService(
            tenantId: $tenantId,
            userId:   auth()->id(),
        );
        $result = $service->sendSingleProposal([
            'send_type'               => 'matching_proposal',
            'project_mail_id'         => $id,
            'engineer_mail_source_id' => $ems->id,
            'engineer_id'             => $engineer->id,
            'to'                      => $v['to'],
            'to_name'                 => $v['to_name'] ?? null,
            'subject'                 => $v['subject'],
            'body'                    => $v['body'],
            'sender_name'             => auth()->user()->name ?? '',
            'sender_email'            => $this->replyToAddress(),
            'from_display_name'       => $this->senderDisplayName(),
            'log_context'             => ['project_mail_id' => $id, 'ems_id' => $ems->id, 'engineer_id' => $engineer->id],
        ]);

        return $result['success']
            ? response()->json([
                'message'     => '送信しました',
                'engineer_id' => $engineer->id,
                'created_new' => $engineer->wasRecentlyCreated,
            ])
            : response()->json(['message' => 'メール送信に失敗しました'], 500);
    }

    /**
     * EMS と既存 Engineer の照合（dedup キー: email + name + affiliation）
     */
    private function findExistingEngineer(EngineerMailSource $ems, int $tenantId): ?Engineer
    {
        // (1) engineer_mail_source_id でリンク済を優先
        $linked = Engineer::where('tenant_id', $tenantId)
            ->where('engineer_mail_source_id', $ems->id)
            ->first();
        if ($linked) return $linked;

        // (2) email + name + affiliation の3項目完全一致
        $email = $ems->email?->from_address;
        $name  = $ems->name;
        if (!$email || !$name) return null;

        $key = $this->proposalStatus->dedupKey($email, $name, $ems->affiliation);
        $candidates = Engineer::where('tenant_id', $tenantId)
            ->where('email', $email)
            ->get();
        foreach ($candidates as $eng) {
            if ($this->proposalStatus->dedupKey($eng->email, $eng->name, $eng->affiliation) === $key) {
                return $eng;
            }
        }
        return null;
    }

    /**
     * EMS から Engineer マスタへ転記（既存 EngineerMailController::registerEngineer 相当）
     */
    private function createEngineerFromEms(EngineerMailSource $ems): Engineer
    {
        $engineer = Engineer::create([
            'name'                    => $ems->name ?? '（名前未取得）',
            'email'                   => $ems->email?->from_address,
            'affiliation'             => $ems->affiliation,
            'affiliation_type'        => $ems->affiliation_type,
            'affiliation_email'       => $ems->email?->from_address,
            'nearest_station'         => $ems->nearest_station,
            'age'                     => $ems->age,
            'engineer_mail_source_id' => $ems->id,
        ]);

        EngineerProfile::create([
            'tenant_id'              => $engineer->tenant_id,
            'engineer_id'            => $engineer->id,
            'desired_unit_price_min' => $ems->unit_price_min,
            'desired_unit_price_max' => $ems->unit_price_max,
        ]);

        foreach ((array) ($ems->skills ?? []) as $skillName) {
            $skillName = trim((string) $skillName);
            if ($skillName === '') continue;
            $skill = Skill::firstOrCreate(['name' => $skillName], ['category' => 'other']);
            EngineerSkill::firstOrCreate([
                'tenant_id'   => $engineer->tenant_id,
                'engineer_id' => $engineer->id,
                'skill_id'    => $skill->id,
            ]);
        }

        $ems->update(['status' => 'registered']);
        return $engineer;
    }

    private function replyToAddress(): string
    {
        return config('mail.reply_to.address', config('mail.from.address')) ?? '';
    }

    // ── 案件要件 × 技術者スキル 対照表 (docs/480 §5) ─────────────────────────

    /**
     * PMS の構造化要件取得。無ければ Claude Stage 1 で自動生成・キャッシュ。
     * GET /v1/project-mails/{id}/requirements
     */
    public function requirements(int $id): JsonResponse
    {
        $this->ensureFeatureEnabled();

        $pms = ProjectMailSource::with('email')->findOrFail($id);
        $requirements = $this->requirementMatching->extractRequirements($pms);

        return response()->json([
            'project_mail_source_id'       => $pms->id,
            'requirements'                 => $requirements,
            'ai_requirements_generated_at' => $pms->ai_requirements_generated_at,
        ]);
    }

    /**
     * PMS 要件の強制再生成。
     * POST /v1/project-mails/{id}/requirements/regenerate
     */
    public function regenerateRequirements(int $id): JsonResponse
    {
        $this->ensureFeatureEnabled();

        $pms = ProjectMailSource::with('email')->findOrFail($id);
        $requirements = $this->requirementMatching->extractRequirements($pms, forceRefresh: true);

        return response()->json([
            'project_mail_source_id'       => $pms->id,
            'requirements'                 => $requirements,
            'ai_requirements_generated_at' => $pms->ai_requirements_generated_at,
        ]);
    }

    /**
     * PMS × EMS|Engineer の対照表取得。無ければ Stage 2 で生成。
     * GET /v1/project-mails/{id}/requirement-match?ems_id=N (or engineer_id=N)
     */
    public function requirementMatch(Request $request, int $id): JsonResponse
    {
        $this->ensureFeatureEnabled();

        $v = $request->validate([
            'ems_id'      => 'nullable|integer|exists:engineer_mail_sources,id',
            'engineer_id' => 'nullable|integer|exists:engineers,id',
        ]);
        if (empty($v['ems_id']) && empty($v['engineer_id'])) {
            return response()->json(['message' => 'ems_id または engineer_id のいずれかが必要です'], 422);
        }

        $pms = ProjectMailSource::with('email')->findOrFail($id);
        $candidate = !empty($v['ems_id'])
            ? EngineerMailSource::findOrFail($v['ems_id'])
            : Engineer::findOrFail($v['engineer_id']);

        $result = $this->requirementMatching->getOrGenerate($pms, $candidate);
        return response()->json($result);
    }

    /**
     * PMS × EMS|Engineer の対照表強制再生成。
     * POST /v1/project-mails/{id}/requirement-match/regenerate
     */
    public function regenerateRequirementMatch(Request $request, int $id): JsonResponse
    {
        $this->ensureFeatureEnabled();

        $v = $request->validate([
            'ems_id'      => 'nullable|integer|exists:engineer_mail_sources,id',
            'engineer_id' => 'nullable|integer|exists:engineers,id',
        ]);
        if (empty($v['ems_id']) && empty($v['engineer_id'])) {
            return response()->json(['message' => 'ems_id または engineer_id のいずれかが必要です'], 422);
        }

        $pms = ProjectMailSource::with('email')->findOrFail($id);
        $candidate = !empty($v['ems_id'])
            ? EngineerMailSource::findOrFail($v['ems_id'])
            : Engineer::findOrFail($v['engineer_id']);

        $result = $this->requirementMatching->regenerate($pms, $candidate);
        return response()->json($result);
    }

    /**
     * 複数候補をまとめて判定 (上限ガード付き)。
     * POST /v1/project-mails/{id}/requirement-match-batch
     * body: { ems_ids: [..], engineer_ids: [..] }
     *
     * - 上限は config('services.anthropic.requirement_match_max_per_request') (デフォルト 5)
     * - 既に DB キャッシュにあるものは Claude を呼ばない
     * - エラーで失敗した個別候補があってもまとめて返す (results[i].error にメッセージ)
     */
    public function requirementMatchBatch(Request $request, int $id): JsonResponse
    {
        $this->ensureFeatureEnabled();

        $v = $request->validate([
            'ems_ids'        => 'nullable|array',
            'ems_ids.*'      => 'integer|exists:engineer_mail_sources,id',
            'engineer_ids'   => 'nullable|array',
            'engineer_ids.*' => 'integer|exists:engineers,id',
        ]);

        $emsIds      = $v['ems_ids'] ?? [];
        $engineerIds = $v['engineer_ids'] ?? [];
        if (empty($emsIds) && empty($engineerIds)) {
            return response()->json(['message' => 'ems_ids または engineer_ids を指定してください'], 422);
        }

        $max = (int) config('services.anthropic.requirement_match_max_per_request', 5);
        $total = count($emsIds) + count($engineerIds);
        if ($total > $max) {
            return response()->json([
                'message' => "1 リクエストあたりの上限 ({$max}件) を超えています (要求={$total}件)",
                'max'     => $max,
            ], 422);
        }

        $pms = ProjectMailSource::with('email')->findOrFail($id);

        $results = [];
        foreach ($emsIds as $emsId) {
            try {
                $ems = EngineerMailSource::findOrFail($emsId);
                $r   = $this->requirementMatching->getOrGenerate($pms, $ems);
                $results[] = ['ems_id' => $emsId, 'result' => $r];
            } catch (\Throwable $e) {
                $results[] = ['ems_id' => $emsId, 'error' => $e->getMessage()];
            }
        }
        foreach ($engineerIds as $engId) {
            try {
                $eng = Engineer::findOrFail($engId);
                $r   = $this->requirementMatching->getOrGenerate($pms, $eng);
                $results[] = ['engineer_id' => $engId, 'result' => $r];
            } catch (\Throwable $e) {
                $results[] = ['engineer_id' => $engId, 'error' => $e->getMessage()];
            }
        }

        return response()->json([
            'project_mail_source_id' => $pms->id,
            'results'                => $results,
        ]);
    }

    /** Feature flag チェック。テナント単位で無効なら 403。 */
    private function ensureFeatureEnabled(): void
    {
        $tenant = auth()->user()->tenant;
        if (!$tenant || !$tenant->feature_requirement_matching) {
            abort(403, '要件マッチング機能はこのテナントで無効です (feature_requirement_matching=false)');
        }
    }
}
