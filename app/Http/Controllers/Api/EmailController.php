<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Email;
use App\Models\Engineer;
use App\Models\EngineerProfile;
use App\Models\EngineerSkill;
use App\Models\GmailToken;
use App\Models\PublicProject;
use App\Models\ProjectRequiredSkill;
use App\Models\Skill;
use App\Services\EmailExtractionService;
use App\Services\EmailMatchPreviewService;
use App\Services\GmailService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
class EmailController extends Controller
{
    public function __construct(
        private GmailService $gmailService,
        private EmailExtractionService $extractionService,
    ) {}

    // メール一覧
    public function index(Request $request)
    {
        $perPage  = $request->integer('per_page', 30);
        $search   = $request->string('search');
        $unread   = $request->boolean('unread');
        $category = $request->string('category');   // engineer / project / ''
        $query = Email::query()
            ->orderBy('received_at', 'desc');
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                  ->orWhere('from_address', 'like', "%{$search}%")
                  ->orWhere('from_name', 'like', "%{$search}%")
                  ->orWhere('body_text', 'like', "%{$search}%");
            });
        }
        if ($unread) {
            $query->where('is_read', false);
        }
        if ($category) {
            $query->where('category', $category);
        }
        return response()->json(
            $query->withCount('attachments')->paginate($perPage)
        );
    }

    // メール詳細（自動既読）
    public function show(int $id)
    {
        $email = Email::findOrFail($id);
        // 既読にする
        if (!$email->is_read) {
            $token = GmailToken::where('tenant_id', auth()->user()->tenant_id)->first();
            if ($token) {
                $this->gmailService->markAsRead($token, $email->gmail_message_id);
            }
            $email->update(['is_read' => true]);
        }
        return response()->json($email->load(['contact', 'deal', 'customer', 'attachments']));
    }

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

            // 同期直後に新着分のみ即時分類（タイムアウト防止のため最大20件）
            $classified = 0;
            try {
                $classified = app(\App\Services\EmailClassificationService::class)->classifyPending(20);
            } catch (\Throwable $e) {
                Log::warning('classify after sync failed: ' . $e->getMessage());
            }

            // 分類後に即時抽出（最大3件・Claude API速度上限のため少量）
            $extracted = 0;
            try {
                $extracted = $this->extractionService->extractPending(3);
            } catch (\Throwable $e) {
                Log::warning('extract after sync failed: ' . $e->getMessage());
            }

            return response()->json([
                'message'    => "{$count}件の新着メールを取得しました",
                'count'      => $count,
                'classified' => $classified,
                'extracted'  => $extracted,
            ]);
        } catch (\Exception $e) {
            Log::error('Email sync failed: ' . $e->getMessage());
            if (str_contains($e->getMessage(), 'invalid_grant')) {
                return response()->json([
                    'message'       => 'Gmailトークンが失効しました。再接続してください。',
                    'token_expired' => true,
                ], 401);
            }
            return response()->json(['message' => 'メール同期に失敗しました'], 500);
        }
    }

    // 担当者・商談への紐付け
    public function link(Request $request, int $id)
    {
        $request->validate([
            'contact_id'  => 'nullable|exists:contacts,id',
            'deal_id'     => 'nullable|exists:deals,id',
            'customer_id' => 'nullable|exists:customers,id',
        ]);
        $email = Email::findOrFail($id);
        $email->update($request->only(['contact_id', 'deal_id', 'customer_id']));
        return response()->json($email->load(['contact', 'deal', 'customer']));
    }

    // 未読件数
    public function unreadCount()
    {
        $count = Email::where('is_read', false)->count();
        return response()->json(['count' => $count]);
    }

    // ── 添付ファイルダウンロード ──────────────────────────────

    public function downloadAttachment(int $emailId, int $attachmentId)
    {
        $email      = Email::findOrFail($emailId);
        $attachment = $email->attachments()->findOrFail($attachmentId);

        $token = GmailToken::where('tenant_id', auth()->user()->tenant_id)->first();
        if (!$token) {
            return response()->json(['message' => 'Gmail未接続です'], 422);
        }

        try {
            $data = $this->gmailService->fetchAttachmentData(
                $token,
                $email->gmail_message_id,
                $attachment->gmail_attachment_id
            );

            return response($data)
                ->header('Content-Type', $attachment->mime_type ?? 'application/octet-stream')
                ->header('Content-Disposition', 'attachment; filename="' . rawurlencode($attachment->filename) . '"')
                ->header('Content-Length', strlen($data));

        } catch (\Exception $e) {
            Log::error("Attachment download failed: {$e->getMessage()}");
            return response()->json(['message' => 'ダウンロードに失敗しました'], 500);
        }
    }

    // ── マッチングプレビュー ──────────────────────────────────

    public function matchPreview(int $id)
    {
        $email = Email::findOrFail($id);

        if (empty($email->extracted_data['result'])) {
            return response()->json(['message' => '先にClaude抽出を実行してください'], 422);
        }

        $result = app(EmailMatchPreviewService::class)->preview($email);

        return response()->json($result);
    }

    // ── Claude抽出（手動トリガー）─────────────────────────────

    public function extract(int $id)
    {
        $email = Email::findOrFail($id);

        if (empty($email->category)) {
            // 未分類なら分類から実行
            app(\App\Services\EmailClassificationService::class)->classify($email);
            $email->refresh();
        }

        $this->extractionService->extract($email);
        $email->refresh();

        // Claudeが抽出したスキル文字列を既存skillsテーブルと照合して候補を返す
        $result   = $email->extracted_data['result'] ?? [];
        $skillMap = $this->resolveSkills($result['skills'] ?? []);

        return response()->json([
            'email'     => $email,
            'result'    => $result,
            'skill_map' => $skillMap,  // [{name, skill_id, matched}]
        ]);
    }

    // ── 技術者として登録 ─────────────────────────────────────

    public function registerEngineer(Request $request, int $id)
    {
        $email    = Email::findOrFail($id);
        $tenantId = auth()->user()->tenant_id;

        $v = $request->validate([
            'name'                   => 'required|string|max:100',
            'name_kana'              => 'nullable|string|max:100',
            'affiliation'            => 'nullable|string|max:100',
            'affiliation_contact'    => 'nullable|string|max:100',
            'desired_unit_price_min' => 'nullable|numeric|min:0',
            'desired_unit_price_max' => 'nullable|numeric|min:0',
            'available_from'         => 'nullable|date',
            'work_style'             => 'nullable|in:remote,office,hybrid',
            'preferred_location'     => 'nullable|string|max:100',
            'self_introduction'      => 'nullable|string',
            'skills'                 => 'nullable|array',
            'skills.*.skill_id'      => 'required_with:skills|integer|exists:skills,id',
            'skills.*.experience_years' => 'nullable|numeric|min:0|max:50',
        ]);

        $engineer = DB::transaction(function () use ($v, $tenantId, $email) {
            $engineer = Engineer::create([
                'tenant_id'           => $tenantId,
                'name'                => $v['name'],
                'name_kana'           => $v['name_kana'] ?? null,
                'affiliation'         => $v['affiliation'] ?? null,
                'affiliation_contact' => $v['affiliation_contact'] ?? null,
            ]);

            EngineerProfile::create([
                'tenant_id'              => $tenantId,
                'engineer_id'            => $engineer->id,
                'desired_unit_price_min' => $v['desired_unit_price_min'] ?? null,
                'desired_unit_price_max' => $v['desired_unit_price_max'] ?? null,
                'available_from'         => $v['available_from'] ?? null,
                'work_style'             => $v['work_style'] ?? null,
                'preferred_location'     => $v['preferred_location'] ?? null,
                'self_introduction'      => $v['self_introduction'] ?? null,
                'is_public'              => false,
            ]);

            foreach ($v['skills'] ?? [] as $skill) {
                EngineerSkill::create([
                    'tenant_id'        => $tenantId,
                    'engineer_id'      => $engineer->id,
                    'skill_id'         => $skill['skill_id'],
                    'experience_years' => $skill['experience_years'] ?? 0,
                ]);
            }

            $email->update([
                'registered_at'         => Carbon::now(),
                'registered_engineer_id'=> $engineer->id,
            ]);

            return $engineer;
        });

        return response()->json([
            'message'  => '技術者を登録しました',
            'engineer' => $engineer->load(['profile', 'engineerSkills.skill']),
        ], 201);
    }

    // ── 案件として登録 ───────────────────────────────────────

    public function registerProject(Request $request, int $id)
    {
        $email    = Email::findOrFail($id);
        $tenantId = auth()->user()->tenant_id;

        $v = $request->validate([
            'title'                    => 'required|string|max:200',
            'description'              => 'nullable|string',
            'end_client'               => 'nullable|string|max:200',
            'unit_price_min'           => 'nullable|numeric|min:0',
            'unit_price_max'           => 'nullable|numeric|min:0',
            'contract_type'            => 'nullable|in:準委任,派遣,請負',
            'contract_period_months'   => 'nullable|integer|min:1',
            'start_date'               => 'nullable|date',
            'work_location'            => 'nullable|string|max:200',
            'nearest_station'          => 'nullable|string|max:100',
            'work_style'               => 'nullable|in:remote,office,hybrid',
            'remote_frequency'         => 'nullable|string|max:50',
            'required_experience_years'=> 'nullable|integer|min:0',
            'interview_count'          => 'nullable|integer|min:0',
            'skills'                   => 'nullable|array',
            'skills.*.skill_id'        => 'required_with:skills|integer|exists:skills,id',
            'skills.*.is_required'     => 'nullable|boolean',
            'skills.*.min_experience_years' => 'nullable|numeric|min:0',
        ]);

        $project = DB::transaction(function () use ($v, $tenantId, $email) {
            $project = PublicProject::create([
                'tenant_id'                 => $tenantId,
                'posted_by_user_id'         => auth()->id(),
                'status'                    => 'open',
                'title'                     => $v['title'],
                'description'               => $v['description'] ?? null,
                'end_client'                => $v['end_client'] ?? null,
                'unit_price_min'            => $v['unit_price_min'] ?? null,
                'unit_price_max'            => $v['unit_price_max'] ?? null,
                'contract_type'             => $v['contract_type'] ?? null,
                'contract_period_months'    => $v['contract_period_months'] ?? null,
                'start_date'                => $v['start_date'] ?? null,
                'work_location'             => $v['work_location'] ?? null,
                'nearest_station'           => $v['nearest_station'] ?? null,
                'work_style'                => $v['work_style'] ?? null,
                'remote_frequency'          => $v['remote_frequency'] ?? null,
                'required_experience_years' => $v['required_experience_years'] ?? null,
                'interview_count'           => $v['interview_count'] ?? null,
                'published_at'              => now(),
            ]);

            foreach ($v['skills'] ?? [] as $skill) {
                ProjectRequiredSkill::create([
                    'project_id'           => $project->id,
                    'skill_id'             => $skill['skill_id'],
                    'is_required'          => $skill['is_required'] ?? true,
                    'min_experience_years' => $skill['min_experience_years'] ?? null,
                ]);
            }

            $email->update([
                'registered_at'          => Carbon::now(),
                'registered_project_id'  => $project->id,
            ]);

            return $project;
        });

        return response()->json([
            'message' => '案件を登録しました',
            'project' => $project->load(['requiredSkills.skill']),
        ], 201);
    }

    // ── スキル名→skill_id照合 ────────────────────────────────

    private function resolveSkills(array $skillNames): array
    {
        if (empty($skillNames)) {
            return [];
        }

        $skills = Skill::all()->keyBy(fn($s) => mb_strtolower($s->name));

        return array_map(function (string $name) use ($skills) {
            $key     = mb_strtolower($name);
            $matched = $skills->get($key);
            return [
                'name'     => $name,
                'skill_id' => $matched?->id,
                'matched'  => $matched !== null,
            ];
        }, $skillNames);
    }

    // 全件既読
    public function markAllRead()
    {
        $tenantId = auth()->user()->tenant_id;
        $updated = Email::where('tenant_id', $tenantId)
            ->where('is_read', false)
            ->update(['is_read' => true]);
        return response()->json([
            'message' => "{$updated}件を既読にしました",
            'count'   => $updated,
        ]);
    }
}
