<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemActivityLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'action', 'description', 'ip_address', 'user_agent', 'created_at'];

    public function user() { return $this->belongsTo(User::class); }
}
