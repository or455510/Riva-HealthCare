<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecordAccessLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'actor_role',
        'resource_type',
        'resource_id',
        'patient_id',
        'action',
        'ip_address',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
