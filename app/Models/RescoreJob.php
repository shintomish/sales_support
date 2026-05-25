<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class RescoreJob extends Model
{
    use BelongsToTenant;

    public const TYPE_PROJECT  = 'project_mail';
    public const TYPE_ENGINEER = 'engineer_mail';

    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_FAILED     = 'failed';

    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_PROCESSING];

    protected $fillable = [
        'tenant_id',
        'type',
        'status',
        'total_count',
        'processed_count',
        'cursor_offset',
        'error_message',
        'requested_by',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'total_count'     => 'integer',
        'processed_count' => 'integer',
        'cursor_offset'   => 'integer',
        'started_at'      => 'datetime',
        'finished_at'     => 'datetime',
    ];
}
