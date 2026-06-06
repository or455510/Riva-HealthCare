<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicationLog extends Model
{
    protected $fillable = [
        'medication_id',
        'patient_id',
        'scheduled_at',
        'scheduled_for_date',
        'scheduled_time',
        'taken_at',
        'missed_at',
        'snoozed_until',
        'missed_notification_sent_at',
        'status',
        'confirmed_by',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'scheduled_for_date' => 'date',
        'taken_at' => 'datetime',
        'missed_at' => 'datetime',
        'snoozed_until' => 'datetime',
        'missed_notification_sent_at' => 'datetime',
    ];

    public function medication() { return $this->belongsTo(Medication::class); }
    public function patient() { return $this->belongsTo(Patient::class); }
    public function confirmer() { return $this->belongsTo(User::class, 'confirmed_by'); }
}
