<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmergencyAlert extends Model
{
    protected $fillable = [
        'patient_id',
        'severity',
        'status',
        'message',
        'pain_level',
        'symptoms',
        'admin_email_sent_at',
        'whatsapp_sent_at',
        'delivery_attempts',
        'delivery_failed_at',
        'resolved_at',
    ];

    protected $casts = [
        'pain_level' => 'integer',
        'admin_email_sent_at' => 'datetime',
        'whatsapp_sent_at' => 'datetime',
        'delivery_attempts' => 'integer',
        'delivery_failed_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }
}
