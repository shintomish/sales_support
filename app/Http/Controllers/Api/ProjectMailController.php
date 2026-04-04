<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProjectMailSource;
use App\Services\ProjectMailScoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProjectMailController extends Controller
{
    public function __construct(
        private ProjectMailScoringService $scoringService,
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
}
