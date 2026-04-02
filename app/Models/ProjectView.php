<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectView extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'project_id',
        'viewer_user_id',
    ];

    protected $casts = [
        'viewed_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(PublicProject::class, 'project_id');
    }

    public function viewer()
    {
        return $this->belongsTo(User::class, 'viewer_user_id');
    }
}
