<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToTenant;

class Activity extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'customer_id', 'deal_id', 'contact_id', 'user_id',
        'type', 'subject', 'content', 'activity_date',
    ];

    protected $casts = ['activity_date' => 'date'];

    public function customer() { return $this->belongsTo(Customer::class); }
    public function deal()     { return $this->belongsTo(Deal::class); }
    public function contact()  { return $this->belongsTo(Contact::class); }
    public function user()     { return $this->belongsTo(User::class); }
}
