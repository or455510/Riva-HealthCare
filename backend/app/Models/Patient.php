<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Patient extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'gender',
        'age',
        'blood_type',
        'emergency_contact',
        'chronic_conditions',
        'medical_history',
        'about',
    ];

    protected $casts = [
        'age' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ✅ العلاقات
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dailyStatuses(): HasMany
    {
        return $this->hasMany(DailyStatus::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function medications(): HasMany
    {
        return $this->hasMany(Medication::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function diagnoses(): HasMany
    {
        return $this->hasMany(Diagnosis::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(DoctorReview::class);
    }

    public function followRequests(): HasMany
    {
        return $this->hasMany(FollowRequest::class, 'patient_id');
    }

    public function doctors()
    {
        return $this->belongsToMany(Doctor::class, 'follows', 'patient_id', 'doctor_id')
            ->withTimestamps();
    }

    public function caregivers()
    {
        return $this->belongsToMany(Caregiver::class, 'caregiver_patient', 'patient_id', 'caregiver_id')
            ->withTimestamps();
    }

    public function doctorRelationships(): HasMany
    {
        return $this->hasMany(PatientDoctorRelationship::class);
    }

    public function caregiverRelationships(): HasMany
    {
        return $this->hasMany(PatientCaregiverRelationship::class);
    }
}
