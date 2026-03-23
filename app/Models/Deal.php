<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Traits\BelongsToTenant;

class Deal extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'customer_id', 'contact_id', 'user_id',
        'title', 'amount', 'status', 'probability',
        'expected_close_date', 'actual_close_date', 'notes',
        // SES拡張フィールド
        'deal_type',
        'project_number',
        'end_client',
        'nearest_station',
        'change_type',
        'affiliation',
        'affiliation_contact',
        'invoice_number',
    ];

    protected $casts = [
        'amount'               => 'decimal:2',
        'expected_close_date'  => 'date',
        'actual_close_date'    => 'date',
        'project_number'       => 'integer',
    ];

    // ── 既存リレーション ──────────────────────────────
    public function customer()   { return $this->belongsTo(Customer::class); }
    public function contact()    { return $this->belongsTo(Contact::class); }
    public function user()       { return $this->belongsTo(User::class); }
    public function activities() { return $this->hasMany(Activity::class); }
    public function tasks()      { return $this->hasMany(Task::class); }

    // ── SES拡張リレーション ───────────────────────────

    /** SES精算条件（1:1） */
    public function sesContract(): HasOne
    {
        return $this->hasOne(SesContract::class);
    }

    /** 月次勤怠・請求記録（1:N） */
    public function workRecords(): HasMany
    {
        return $this->hasMany(WorkRecord::class)->orderByDesc('year_month');
    }

    /** 最新月の WorkRecord */
    public function latestWorkRecord(): HasOne
    {
        return $this->hasOne(WorkRecord::class)->latestOfMany('year_month');
    }

    // ── スコープ ──────────────────────────────────────

    /** SES案件のみ絞り込む */
    public function scopeSes($query)
    {
        return $query->where('deal_type', 'ses');
    }

    /** 契約期間終了が N日以内に迫っている案件 */
    public function scopeExpiringWithin($query, int $days = 30)
    {
        return $query->whereHas('sesContract', function ($q) use ($days) {
            $q->whereBetween('contract_period_end', [
                now()->toDateString(),
                now()->addDays($days)->toDateString(),
            ]);
        });
    }

    /** 期限切れ案件 */
    public function scopeExpiredContracts($query)
    {
        return $query->whereHas('sesContract', function ($q) {
            $q->where('contract_period_end', '<', now()->toDateString());
        });
    }
}
