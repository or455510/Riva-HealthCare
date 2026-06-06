<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    protected $fillable = [
        'patient_id', 'doctor_id', 'hospital_id', 'appointment_date', 'appointment_time',
        'type', 'amount', 'payment_status', 'status', 'notes',
    ];

    public function patient() { return $this->belongsTo(Patient::class); }
    public function doctor() { return $this->belongsTo(Doctor::class); }
    public function hospital() { return $this->belongsTo(Hospital::class); }
    public function logs() { return $this->hasMany(AppointmentBookingLog::class); }
}
