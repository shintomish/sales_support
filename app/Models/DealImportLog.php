<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DealImportLog Model
 *
 * ExcelインポートAPIの実行履歴・結果を管理する。
 *
 * @property int         $id
 * @property int         $tenant_id
 * @property int         $imported_by
 * @property string      $original_filename
 * @property string      $file_type
 * @property int         $total_rows
 * @property int         $created_count
 * @property int         $updated_count
 * @property int         $skipped_count
 * @property int         $error_count
 * @property array|null  $error_details
 * @property string      $status            processing / completed / failed
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 */
class DealImportLog extends Model
{
    use BelongsToTenant;

    protected $table = 'deal_import_logs';

    protected $fillable = [
        'tenant_id',
        'imported_by',
        'original_filename',
        'file_type',
        'total_rows',
        'created_count',
        'updated_count',
        'skipped_count',
        'error_count',
        'error_details',
        'status',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'error_details' => 'array',
        'started_at'    => 'datetime',
        'completed_at'  => 'datetime',
    ];

    // ── リレーション ──────────────────────────────────

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    // ── ヘルパーメソッド ──────────────────────────────

    /**
     * インポート完了を記録する
     */
    public function markCompleted(array $counts, array $errors = []): void
    {
        $this->update(array_merge($counts, [
            'error_details' => empty($errors) ? null : $errors,
            'status'        => empty($errors) ? 'completed' : 'completed',
            'completed_at'  => now(),
        ]));
    }

    /**
     * インポート失敗を記録する
     */
    public function markFailed(string $reason): void
    {
        $this->update([
            'status'        => 'failed',
            'error_details' => [['reason' => $reason]],
            'completed_at'  => now(),
        ]);
    }

    // ── スコープ ──────────────────────────────────────

    public function scopeLatest($query)
    {
        return $query->orderByDesc('created_at');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }
}
