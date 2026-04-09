<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToTenant;

class EmailBodyTemplate extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'name',
        'name_en',
        'department',
        'position',
        'email',
        'mobile',
    ];
}
