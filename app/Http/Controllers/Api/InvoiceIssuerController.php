<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\SupabaseStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 請求書発行元情報の取得・更新（Phase C）
 *
 * tenants テーブル上の invoice_issuer_* を扱う。
 * tenant_admin / super_admin のみ更新可能。
 */
class InvoiceIssuerController extends Controller
{
    public function __construct(
        private readonly SupabaseStorageService $storage,
    ) {}

    private const FIELDS = [
        'invoice_issuer_name',
        'invoice_issuer_postal_code',
        'invoice_issuer_address',
        'invoice_issuer_tel',
        'invoice_issuer_fax',
        'invoice_issuer_logo_path',
        'invoice_issuer_seal_path',
        'invoice_issuer_url',
        'invoice_issuer_invoice_number',
        'invoice_issuer_bank_name',
        'invoice_issuer_bank_branch',
        'invoice_issuer_bank_account_type',
        'invoice_issuer_bank_account_number',
        'invoice_issuer_bank_account_holder',
    ];

    /** GET /api/v1/settings/invoice-issuer */
    public function show(): JsonResponse
    {
        $tenant = Auth::user()->tenant;
        return response()->json($tenant->only(self::FIELDS));
    }

    /** PUT /api/v1/settings/invoice-issuer */
    public function update(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!in_array($user->role, ['super_admin', 'tenant_admin'], true)) {
            return response()->json(['message' => '権限がありません'], 403);
        }

        $validated = $request->validate([
            'invoice_issuer_name'                => ['nullable', 'string', 'max:255'],
            'invoice_issuer_postal_code'         => ['nullable', 'string', 'max:20'],
            'invoice_issuer_address'             => ['nullable', 'string', 'max:500'],
            'invoice_issuer_tel'                 => ['nullable', 'string', 'max:50'],
            'invoice_issuer_fax'                 => ['nullable', 'string', 'max:50'],
            'invoice_issuer_url'                 => ['nullable', 'string', 'max:255'],
            'invoice_issuer_invoice_number'      => ['nullable', 'string', 'max:30'],
            'invoice_issuer_bank_name'           => ['nullable', 'string', 'max:100'],
            'invoice_issuer_bank_branch'         => ['nullable', 'string', 'max:100'],
            'invoice_issuer_bank_account_type'   => ['nullable', 'string', 'max:20'],
            'invoice_issuer_bank_account_number' => ['nullable', 'string', 'max:30'],
            'invoice_issuer_bank_account_holder' => ['nullable', 'string', 'max:100'],
        ]);

        $tenant = Tenant::query()->findOrFail($user->tenant_id);
        $tenant->fill($validated);
        $tenant->save();

        return response()->json($tenant->only(self::FIELDS));
    }

    /**
     * POST /api/v1/settings/invoice-issuer/logo
     * 請求書発行元ロゴ画像をアップロードして tenants.invoice_issuer_logo_path に設定。
     * 既存のロゴが設定されている場合は Storage 上のファイルも削除する。
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!in_array($user->role, ['super_admin', 'tenant_admin'], true)) {
            return response()->json(['message' => '権限がありません'], 403);
        }

        $request->validate([
            'logo' => ['required', 'file', 'mimes:png,jpg,jpeg,gif,webp', 'max:2048'],
        ]);

        $tenant = Tenant::query()->findOrFail($user->tenant_id);

        if ($tenant->invoice_issuer_logo_path) {
            try { $this->storage->delete($tenant->invoice_issuer_logo_path); }
            catch (\Throwable $e) { report($e); }
        }

        $url = $this->storage->upload(
            $request->file('logo'),
            sprintf('invoice-issuer-logos/%d', $tenant->id),
            'logo',
        );

        $tenant->invoice_issuer_logo_path = $url;
        $tenant->save();

        return response()->json(['invoice_issuer_logo_path' => $url]);
    }

    /**
     * DELETE /api/v1/settings/invoice-issuer/logo
     */
    public function deleteLogo(): JsonResponse
    {
        $user = Auth::user();
        if (!in_array($user->role, ['super_admin', 'tenant_admin'], true)) {
            return response()->json(['message' => '権限がありません'], 403);
        }

        $tenant = Tenant::query()->findOrFail($user->tenant_id);
        if ($tenant->invoice_issuer_logo_path) {
            try { $this->storage->delete($tenant->invoice_issuer_logo_path); }
            catch (\Throwable $e) { report($e); }
            $tenant->invoice_issuer_logo_path = null;
            $tenant->save();
        }

        return response()->json(null, 204);
    }

    /**
     * POST /api/v1/settings/invoice-issuer/seal
     * 電子印画像をアップロードして tenants.invoice_issuer_seal_path に設定
     */
    public function uploadSeal(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!in_array($user->role, ['super_admin', 'tenant_admin'], true)) {
            return response()->json(['message' => '権限がありません'], 403);
        }

        $request->validate([
            'seal' => ['required', 'file', 'mimes:png,jpg,jpeg,gif,webp', 'max:2048'],
        ]);

        $tenant = Tenant::query()->findOrFail($user->tenant_id);

        if ($tenant->invoice_issuer_seal_path) {
            try { $this->storage->delete($tenant->invoice_issuer_seal_path); }
            catch (\Throwable $e) { report($e); }
        }

        $url = $this->storage->upload(
            $request->file('seal'),
            sprintf('invoice-issuer-seals/%d', $tenant->id),
            'seal',
        );

        $tenant->invoice_issuer_seal_path = $url;
        $tenant->save();

        return response()->json(['invoice_issuer_seal_path' => $url]);
    }

    /** DELETE /api/v1/settings/invoice-issuer/seal */
    public function deleteSeal(): JsonResponse
    {
        $user = Auth::user();
        if (!in_array($user->role, ['super_admin', 'tenant_admin'], true)) {
            return response()->json(['message' => '権限がありません'], 403);
        }

        $tenant = Tenant::query()->findOrFail($user->tenant_id);
        if ($tenant->invoice_issuer_seal_path) {
            try { $this->storage->delete($tenant->invoice_issuer_seal_path); }
            catch (\Throwable $e) { report($e); }
            $tenant->invoice_issuer_seal_path = null;
            $tenant->save();
        }

        return response()->json(null, 204);
    }
}
