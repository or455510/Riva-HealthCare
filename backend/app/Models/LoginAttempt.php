<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginAttempt extends Model
{
    public $timestamps = false;

    protected $fillable = ['email', 'ip_address', 'user_agent', 'successful', 'reason', 'created_at'];

    protected $casts = ['successful' => 'boolean'];
}
