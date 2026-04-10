<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailAttachment;
use App\Models\Engineer;
use App\Models\EngineerMailSource;
use App\Models\EngineerSkill;
use App\Models\GmailToken;
use App\Models\PublicProject;
use App\Models\Skill;
use App\Services\EngineerMailScoringService;
use App\Services\GmailService;
use App\Services\SupabaseStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
                  ->orWhereHas('email', fn($eq) =>
                      $eq->where('subject', 'like', "%{$search}%")
                         ->orWhere('from_name', 'like', "%{$search}%")
                  );
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
        $projects = PublicProject::with(['requiredSkills.skill'])
            ->published()
            ->open()
            ->get();

        $results = $projects->map(function (PublicProject $project) use ($emsSkills) {
            $required = $project->requiredSkills;
            $total    = $required->count();

            $matched = $required->filter(
                fn($rs) => $emsSkills->has(mb_strtolower(trim((string) ($rs->skill?->name ?? ''))))
            );

            $matchScore = $total > 0 ? round($matched->count() / $total * 100) : 0;

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
            ];
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

    // 未処理メールを手動で一括スコアリング（1回50件ずつ処理）
    public function scoreAll(): JsonResponse
    {
        set_time_limit(120);
        $batchSize = 50;
        $count     = $this->scoringService->scorePending($batchSize);
        $remaining = $this->scoringService->pendingCount();

        return response()->json([
            'message'   => "{$count}件をスコアリングしました",
            'count'     => $count,
            'remaining' => $remaining,
        ]);
    }

    // 既存レコードを全件再スコアリング＋再抽出（500件バッチ）
    public function rescoreAll(): JsonResponse
    {
        set_time_limit(300);
        $batchSize = 500;
        $total     = EngineerMailSource::whereNotNull('email_id')->count();
        $count     = $this->scoringService->rescoreAll($batchSize);
        $remaining = max(0, $total - $count);

        return response()->json([
            'message'   => "{$count}件を再スコアリングしました",
            'count'     => $count,
            'remaining' => $remaining,
        ]);
    }
}
