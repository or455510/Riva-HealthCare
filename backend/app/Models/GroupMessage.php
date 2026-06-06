<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GroupMessage extends Model
{
    protected $fillable = ['group_chat_id', 'sender_id', 'body'];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function group()
    {
        return $this->belongsTo(GroupChat::class, 'group_chat_id');
    }
}