<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EngineerMailSource;
use App\Services\EngineerMailScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $status   = $request->string('status');
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

    // 未処理メールを手動で一括スコアリング
    public function scoreAll(): JsonResponse
    {
        $count = $this->scoringService->scorePending();
        return response()->json([
            'message' => "{$count}件をスコアリングしました",
            'count'   => $count,
        ]);
    }

    // 既存レコードを全件再スコアリング＋再抽出
    public function rescoreAll(): JsonResponse
    {
        $count = $this->scoringService->rescoreAll();
        return response()->json([
            'message' => "{$count}件を再スコアリングしました",
            'count'   => $count,
        ]);
    }
}
