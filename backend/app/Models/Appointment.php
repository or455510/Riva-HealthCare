<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    protected $fillable = [
        'patient_id', 'doctor_id', 'hospital_id', 'service_id', 'appointment_date', 'appointment_time',
        'type', 'amount', 'payment_status', 'status', 'notes',
        'queue_status', 'queue_position', 'checked_in_at', 'called_at', 'queue_completed_at',
    ];

    protected $casts = [
        'checked_in_at' => 'datetime',
        'called_at' => 'datetime',
        'queue_completed_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor()
    {
        return $this->belongsTo(Doctor::class);
    }

    public function hospital()
    {
        return $this->belongsTo(Hospital::class);
    }

    public function service()
    {
        return $this->belongsTo(ClinicService::class, 'service_id');
    }

    public function logs()
    {
        return $this->hasMany(AppointmentBookingLog::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
