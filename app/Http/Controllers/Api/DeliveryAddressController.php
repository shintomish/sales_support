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
        $query = DeliveryAddress::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $addresses = $query->orderBy('name')->paginate(100);

        return response()->json($addresses);
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
}
