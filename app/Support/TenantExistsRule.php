<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * テナント分離を考慮した exists: バリデーション。
 *
 * 通常の `exists:customers,id` は Eloquent の TenantScope を経由しないため、
 * 他テナントの主キー値を渡すと素通りしてしまう (cross-tenant IDOR)。
 *
 * 使用例:
 *   'customer_id' => ['required', TenantExistsRule::for('customers')]
 */
class TenantExistsRule
{
    public static function for(string $table, string $column = 'id'): Exists
    {
        $tenantId = Auth::user()?->tenant_id;
        return Rule::exists($table, $column)
            ->where(fn ($q) => $q->where('tenant_id', $tenantId));
    }
}
