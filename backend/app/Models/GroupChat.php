<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GroupChat extends Model
{
    protected $fillable = ['name', 'created_by'];

    public function members()
    {
        return $this->belongsToMany(User::class, 'group_chat_members');
    }

    public function messages()
    {
        return $this->hasMany(GroupMessage::class);
    }

    public function lastMessage()
    {
        return $this->hasOne(GroupMessage::class)->latestOfMany();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}