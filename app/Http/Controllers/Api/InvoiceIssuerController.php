<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
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
    private const FIELDS = [
        'invoice_issuer_name',
        'invoice_issuer_postal_code',
        'invoice_issuer_address',
        'invoice_issuer_tel',
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
}
