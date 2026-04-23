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
use App\Services\GmailService;
use App\Services\SupabaseStorageService;
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
    ) {}

    // 一覧
    public function index(Request $request)
    {
        $perPage  = $request->integer('per_page', 30);
        $status   = $request->input('status');
        $scoreMin = $request->integer('score_min', 0);
        $scoreMax = $request->integer('score_max', 100);
        $search   = $request->string('search');

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
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('nearest_station', 'like', "%{$search}%")
                  ->orWhere('affiliation_type', 'like', "%{$search}%")
                  ->orWhere('skills', 'like', "%{$search}%");
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

        $results = $projects->map(function (PublicProject $project) use ($emsSkills) {
            $required = $project->requiredSkills;
            $total    = $required->count();

            $matched = $required->filter(
                fn($rs) => $emsSkills->has(mb_strtolower(trim((string) ($rs->skill?->name ?? ''))))
            );

            $matchScore = $total > 0 ? round($matched->count() / $total * 100) : 0;

            $contact       = $project->postedByCustomer?->contacts->first();
            $toEmail       = $contact?->email ?? '';
            $salesContact  = $contact?->name ?? $project->postedByCustomer?->name ?? '';

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

        // storage_pathなし or 破損検出 → Gmail APIから取得
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

        $result = app(ClaudeService::class)->generateProposal($mailData, $engineerData);

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
            'project_id'    => 'required|integer',
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

    private function replyToAddress(): string
    {
        return config('mail.reply_to.address', config('mail.from.address')) ?? '';
    }
}
