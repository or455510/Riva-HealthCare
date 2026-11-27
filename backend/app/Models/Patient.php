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

    // NOTE (Phase 0 / A.3): three broken relations used to live here —
    // followRequests() (returned App\Models\FollowRequest, a class that does not
    // exist anywhere in this codebase — calling it would throw a fatal
    // "Class not found" error), doctors() and caregivers() (belongsToMany() through
    // 'follows' and 'caregiver_patient' pivot tables, neither of which any migration
    // ever creates — calling either would throw a QueryException). Confirmed via
    // full-codebase search (app/, database/, tests/, routes/) that nothing calls any
    // of the three. The real, working relationship data lives in
    // PatientDoctorRelationship / PatientCaregiverRelationship, exposed below via
    // doctorRelationships() and caregiverRelationships() — which is what every
    // controller (RelationshipController, DoctorController, CaregiverController,
    // ChatController, AppointmentController, etc.) actually uses.

    public function doctorRelationships(): HasMany
    {
        return $this->hasMany(PatientDoctorRelationship::class);
    }

    public function caregiverRelationships(): HasMany
    {
        return $this->hasMany(PatientCaregiverRelationship::class);
    }
}
