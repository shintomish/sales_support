<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProjectMailSource;
use App\Services\ClaudeService;
use App\Services\ProjectMailMatchingService;
use App\Services\ProjectMailScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProjectMailController extends Controller
{
    public function __construct(
        private ProjectMailScoringService  $scoringService,
        private ProjectMailMatchingService $matchingService,
        private ClaudeService              $claudeService,
    ) {}

    // 一覧
    public function index(Request $request)
    {
        $perPage   = $request->integer('per_page', 30);
        $status    = $request->string('status');    // new / review / proposed / interview / won / lost / excluded
        $scoreMin  = $request->integer('score_min', 0);
        $scoreMax  = $request->integer('score_max', 100);
        $search    = $request->string('search');

        $query = ProjectMailSource::with(['email:id,subject,from_name,from_address,body_text,received_at'])
            ->whereBetween('score', [$scoreMin, $scoreMax])
            ->orderByDesc('received_at');

        if ($status) {
            $query->where('status', $status);
        } else {
            // デフォルト: excluded は除外
            $query->whereNotIn('status', ['excluded']);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('work_location', 'like', "%{$search}%")
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
        $pms = ProjectMailSource::with([
            'email.attachments',
        ])->findOrFail($id);

        return response()->json($pms);
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

    // 未処理メールを手動で一括スコアリング
    public function scoreAll()
    {
        $count = $this->scoringService->scorePending();
        return response()->json([
            'message' => "{$count}件をスコアリングしました",
            'count'   => $count,
        ]);
    }

    // 既存レコードを全件再スコアリング＋再抽出
    public function rescoreAll()
    {
        $count = $this->scoringService->rescoreAll();
        return response()->json([
            'message' => "{$count}件を再スコアリングしました",
            'count'   => $count,
        ]);
    }

    // 既存レコードの抽出情報を一括再計算
    public function reextractAll()
    {
        $count = $this->scoringService->reextractAll();
        return response()->json([
            'message' => "{$count}件の抽出情報を更新しました",
            'count'   => $count,
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
        } catch (\Exception $e) {
            Log::error("generateProposal failed mail_id={$id}: " . $e->getMessage());
            return response()->json(['message' => 'メール生成に失敗しました'], 500);
        }
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
                'engineer_id'      => $r['engineer']->id,
                'engineer_name'    => $r['engineer']->name,
                'affiliation'      => $r['engineer']->affiliation,
                'affiliation_type' => $r['engineer']->affiliation_type,
                'age'              => $r['engineer']->age,
                'score'            => $r['score'],
                'breakdown'        => $r['breakdown'],
                'reasons'          => $r['reasons'],
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
}
