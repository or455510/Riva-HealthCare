<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
protected $fillable = ['sender_id', 'receiver_id', 'body', 'is_read', 'file_url', 'file_type', 'file_name'];
    protected $casts = ['is_read' => 'boolean'];

    public function sender() { return $this->belongsTo(User::class, 'sender_id'); }
    public function receiver() { return $this->belongsTo(User::class, 'receiver_id'); }
}
