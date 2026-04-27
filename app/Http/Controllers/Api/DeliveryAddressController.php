<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryAddress;
use App\Models\DeliveryAddressStateSnapshot;
use App\Services\DeliveryAddressImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;

class DeliveryAddressController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $baseQuery = DeliveryAddress::query();
        if ($request->filled('search')) {
            $search = $request->input('search');
            $baseQuery->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%");
            });
        }

        // 件数表示用（is_active フィルタは無視して、検索条件下の全件 / 有効件数）
        $totalCount  = (clone $baseQuery)->count();
        $activeCount = (clone $baseQuery)->where('is_active', true)->count();

        $query = clone $baseQuery;
        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'asc');
        $allowedSorts = ['id', 'name', 'email', 'occupation', 'is_active'];
        if (!in_array($sortBy, $allowedSorts)) $sortBy = 'id';
        if (!in_array($sortOrder, ['asc', 'desc'])) $sortOrder = 'asc';

        $addresses = $query->orderBy($sortBy, $sortOrder)->paginate($request->input('per_page', 100));

        $snapshot = DeliveryAddressStateSnapshot::where('tenant_id', $tenantId)->first();

        return response()->json([
            ...$addresses->toArray(),
            'all_count'    => $totalCount,
            'active_count' => $activeCount,
            'saved_state'  => $snapshot ? [
                'label'      => $snapshot->label,
                'created_at' => $snapshot->created_at?->toIso8601String(),
                'count'      => is_array($snapshot->data) ? count($snapshot->data) : 0,
            ] : null,
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => [
                'required',
                File::types(['csv', 'txt'])->max(5 * 1024),
            ],
        ], [
            'file.required' => 'CSVファイルを選択してください。',
            'file.max'      => 'ファイルサイズは5MB以下にしてください。',
        ]);

        $uploadedFile = $request->file('file');
        $tmpPath      = $uploadedFile->store('imports/tmp', 'local');
        $fullTmpPath  = Storage::disk('local')->path($tmpPath);

        try {
            $service = new DeliveryAddressImportService(
                tenantId: auth()->user()->tenant_id,
            );
            $result = $service->import($fullTmpPath);
        } finally {
            Storage::disk('local')->delete($tmpPath);
        }

        return response()->json([
            'message'      => "インポートが完了しました（登録: {$result['imported']}件、スキップ: {$result['skipped']}件）",
            'imported'     => $result['imported'],
            'skipped'      => $result['skipped'],
            'total'        => $result['total'],
            'skipped_list' => $result['skipped_list'],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'      => 'required|email|max:255',
            'name'       => 'nullable|string|max:255',
            'occupation' => 'nullable|string|max:255',
        ], [
            'email.required' => 'メールアドレスは必須です。',
            'email.email'    => '有効なメールアドレスを入力してください。',
        ]);

        $tenantId = auth()->user()->tenant_id;

        $existing = DeliveryAddress::where('tenant_id', $tenantId)
            ->where('email', $validated['email'])
            ->first();

        if ($existing) {
            return response()->json(['message' => 'このメールアドレスはすでに登録されています。'], 422);
        }

        $address = DeliveryAddress::create([
            'tenant_id'  => $tenantId,
            'email'      => $validated['email'],
            'name'       => $validated['name'] ?? null,
            'occupation' => $validated['occupation'] ?? null,
            'is_active'  => true,
        ]);

        return response()->json($address, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $address = DeliveryAddress::findOrFail($id);

        $validated = $request->validate([
            'is_active' => 'sometimes|boolean',
            'name'      => 'sometimes|nullable|string|max:255',
        ]);

        $address->update($validated);

        return response()->json($address);
    }

    public function importProgress(): JsonResponse
    {
        $service  = new DeliveryAddressImportService(tenantId: auth()->user()->tenant_id);
        $progress = Cache::get($service->progressKey(), ['current' => 0, 'total' => 0, 'done' => false]);

        return response()->json($progress);
    }

    public function destroy(int $id): JsonResponse
    {
        $address = DeliveryAddress::findOrFail($id);
        $address->delete();

        return response()->json(null, 204);
    }

    /** 全件の is_active を一括更新 */
    public function bulkSetActive(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'is_active' => 'required|boolean',
        ]);

        $tenantId = auth()->user()->tenant_id;
        $updated  = DeliveryAddress::where('tenant_id', $tenantId)
            ->update(['is_active' => $validated['is_active']]);

        return response()->json([
            'message' => "{$updated}件を更新しました",
            'updated' => $updated,
        ]);
    }

    /** 現在の有効/無効状態をスナップショット保存（テナントあたり1件のみ・上書き） */
    public function saveState(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'label' => 'nullable|string|max:100',
        ]);

        $tenantId = auth()->user()->tenant_id;
        $label    = $validated['label'] ?? 'A';

        $rows = DeliveryAddress::where('tenant_id', $tenantId)
            ->select('id', 'is_active')
            ->get()
            ->map(fn($a) => ['id' => $a->id, 'is_active' => (bool) $a->is_active])
            ->values()
            ->all();

        // 単一スナップショット運用: 既存を削除して再作成
        DeliveryAddressStateSnapshot::where('tenant_id', $tenantId)->delete();

        $snapshot = DeliveryAddressStateSnapshot::create([
            'tenant_id'  => $tenantId,
            'label'      => $label,
            'data'       => $rows,
            'created_by' => auth()->id(),
            'created_at' => now(),
        ]);

        return response()->json([
            'message'     => "状態「{$label}」を保存しました",
            'snapshot_id' => $snapshot->id,
            'label'       => $label,
            'count'       => count($rows),
        ], 201);
    }

    /** 保存済みスナップショットを一括復元 */
    public function restoreState(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $snapshot = DeliveryAddressStateSnapshot::where('tenant_id', $tenantId)->first();

        if (!$snapshot) {
            return response()->json(['message' => '保存された状態がありません'], 404);
        }

        $data = is_array($snapshot->data) ? $snapshot->data : [];
        $activeIds   = [];
        $inactiveIds = [];
        foreach ($data as $row) {
            if (!isset($row['id'])) continue;
            if (!empty($row['is_active'])) $activeIds[]   = (int) $row['id'];
            else                            $inactiveIds[] = (int) $row['id'];
        }

        $updated = 0;
        if ($activeIds) {
            $updated += DeliveryAddress::where('tenant_id', $tenantId)
                ->whereIn('id', $activeIds)
                ->update(['is_active' => true]);
        }
        if ($inactiveIds) {
            $updated += DeliveryAddress::where('tenant_id', $tenantId)
                ->whereIn('id', $inactiveIds)
                ->update(['is_active' => false]);
        }

        return response()->json([
            'message' => "状態「{$snapshot->label}」を復元しました（{$updated}件更新）",
            'updated' => $updated,
            'label'   => $snapshot->label,
        ]);
    }
}
