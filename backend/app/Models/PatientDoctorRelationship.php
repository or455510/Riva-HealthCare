<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PatientDoctorRelationship extends Model
{
    protected $fillable = [
        'patient_id', 'doctor_id', 'status', 'requested_at', 'responded_at', 'ended_at',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'responded_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function patient() { return $this->belongsTo(Patient::class); }
    public function doctor() { return $this->belongsTo(Doctor::class); }
}
