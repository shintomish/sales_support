<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryAddress;
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
        $baseQuery = DeliveryAddress::query();
        if ($request->filled('search')) {
            $search = $request->input('search');
            $baseQuery->where(function ($q) use ($search) {
                $q->where('email', 'ilike', "%{$search}%")
                  ->orWhere('name', 'ilike', "%{$search}%");
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
        $allowedSorts = ['id', 'name', 'email', 'occupation', 'is_active', 'unsubscribe_reason'];
        if (!in_array($sortBy, $allowedSorts)) $sortBy = 'id';
        if (!in_array($sortOrder, ['asc', 'desc'])) $sortOrder = 'asc';

        $addresses = $query->orderBy($sortBy, $sortOrder)->paginate($request->input('per_page', 100));

        return response()->json([
            ...$addresses->toArray(),
            'all_count'    => $totalCount,
            'active_count' => $activeCount,
        ]);
    }

    /**
     * 配信先一覧を CSV でエクスポート（正規フォーマット・フルカラム / UTF-8 BOM）。
     * 列: e-mail, Name, ZipCode, Prefecture, Address, Tel, Occupation, IsActive
     * index と同じ search / is_active フィルタを尊重する。
     */
    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $query = DeliveryAddress::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('email', 'ilike', "%{$search}%")
                  ->orWhere('name', 'ilike', "%{$search}%");
            });
        }
        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $filename = 'DeliveryAddress_' . now()->format('Ymd') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            // Excel 互換のため UTF-8 BOM を付与
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['e-mail', 'Name', 'ZipCode', 'Prefecture', 'Address', 'Tel', 'Occupation', 'IsActive']);
            $query->orderBy('email')->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $a) {
                    fputcsv($out, [
                        $a->email,
                        $a->name,
                        $a->zip_code,
                        $a->prefecture,
                        $a->address,
                        $a->tel,
                        $a->occupation,
                        $a->is_active ? 'true' : 'false',
                    ]);
                }
            });
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
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
            'email'              => 'sometimes|email|max:255',
            'name'               => 'sometimes|nullable|string|max:255',
            'zip_code'           => 'sometimes|nullable|string|max:20',
            'prefecture'         => 'sometimes|nullable|string|max:50',
            'address'            => 'sometimes|nullable|string|max:500',
            'tel'                => 'sometimes|nullable|string|max:50',
            'occupation'         => 'sometimes|nullable|string|max:255',
            'is_active'          => 'sometimes|boolean',
            'unsubscribe_reason' => 'sometimes|nullable|string|max:50',
        ]);

        // メールアドレス変更時は同テナント内の重複を確認
        if (isset($validated['email']) && $validated['email'] !== $address->email) {
            $duplicate = DeliveryAddress::where('tenant_id', $address->tenant_id)
                ->where('email', $validated['email'])
                ->where('id', '!=', $id)
                ->exists();
            if ($duplicate) {
                return response()->json(['message' => 'このメールアドレスはすでに登録されています。'], 422);
            }
        }

        // 状態変更時は停止理由・停止日時を自動記録/クリア
        // ただしリクエストに unsubscribe_reason が明示指定されている場合はそちらを優先
        $reasonExplicit = array_key_exists('unsubscribe_reason', $validated);
        if (array_key_exists('is_active', $validated)) {
            if ($validated['is_active'] === false && $address->is_active) {
                if (!$reasonExplicit) {
                    $validated['unsubscribe_reason'] = 'operator_disabled';
                }
                $validated['unsubscribed_at'] = now();
            } elseif ($validated['is_active'] === true && !$address->is_active) {
                if (!$reasonExplicit) {
                    $validated['unsubscribe_reason'] = null;
                }
                $validated['unsubscribed_at'] = null;
                // 再有効化時はバウンス累積をリセット(担当者が有効と判断したので再度チャンスを与える)
                $validated['soft_bounce_count'] = 0;
            }
        }

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
        $payload  = ['is_active' => $validated['is_active']];

        // 一括で無効化する場合は user_disabled として理由を残す
        if ($validated['is_active'] === false) {
            $payload['unsubscribe_reason'] = 'operator_disabled';
            $payload['unsubscribed_at']    = now();
        } else {
            $payload['unsubscribe_reason'] = null;
            $payload['unsubscribed_at']    = null;
            $payload['soft_bounce_count']  = 0;
        }

        $updated = DeliveryAddress::where('tenant_id', $tenantId)
            ->update($payload);

        return response()->json([
            'message' => "{$updated}件を更新しました",
            'updated' => $updated,
        ]);
    }

}
