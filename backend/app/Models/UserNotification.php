<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserNotification extends Model
{
    protected $fillable = [
        'user_id', 'type', 'title', 'message', 'severity', 'is_read', 'related_type', 'related_id',
    ];

    protected $casts = ['is_read' => 'boolean'];

    public function user() { return $this->belongsTo(User::class); }
}
