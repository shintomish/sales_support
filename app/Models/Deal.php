<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToTenant;

class Deal extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'customer_id', 'contact_id', 'user_id',
        'title', 'amount', 'status', 'probability',
        'expected_close_date', 'actual_close_date', 'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expected_close_date' => 'date',
        'actual_close_date'   => 'date',
    ];

    public function customer()   { return $this->belongsTo(Customer::class); }
    public function contact()    { return $this->belongsTo(Contact::class); }
    public function user()       { return $this->belongsTo(User::class); }
    public function activities() { return $this->hasMany(Activity::class); }
    public function tasks()      { return $this->hasMany(Task::class); }
}
