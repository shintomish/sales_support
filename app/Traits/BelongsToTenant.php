<?php

namespace App\Traits;

use App\Scopes\TenantScope;
use Illuminate\Support\Facades\Auth;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::creating(function ($model) {
            if (!$model->tenant_id && Auth::check()) {
                $model->tenant_id = Auth::user()->tenant_id;
            }
        });

        static::addGlobalScope(new TenantScope());
    }
}
