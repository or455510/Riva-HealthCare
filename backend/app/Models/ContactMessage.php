<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactMessage extends Model
{
    protected $fillable = [
        'full_name',
        'email',
        'subject',
        'message',
        'admin_email_sent_at',
        'whatsapp_sent_at',
    ];

    protected $casts = [
        'admin_email_sent_at' => 'datetime',
        'whatsapp_sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
