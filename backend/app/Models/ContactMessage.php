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
        'status',
        'handled_at',
        'admin_email_sent_at',
        'whatsapp_sent_at',
    ];

    protected $casts = [
        'handled_at' => 'datetime',
        'admin_email_sent_at' => 'datetime',
        'whatsapp_sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
