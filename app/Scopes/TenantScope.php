<?php
namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if (!$user) return;

        // super_admin は全テナントのデータを見られる
        if ($user->role === 'super_admin') return;

        // それ以外は自分のテナントのみ
        $builder->where($model->getTable() . '.tenant_id', $user->tenant_id);
    }
}
