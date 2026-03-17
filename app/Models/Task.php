<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToTenant;

class Task extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'user_id', 'customer_id', 'deal_id',
        'title', 'description', 'due_date', 'status', 'priority',
    ];

    protected $casts = ['due_date' => 'date'];

    public function user()     { return $this->belongsTo(User::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function deal()     { return $this->belongsTo(Deal::class); }
}
