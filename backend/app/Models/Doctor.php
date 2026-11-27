<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Doctor extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'specialty',
        'years_of_experience',
        'fee',
        'bio',
        'about',
        'license_number',
        'contact_info',
        'available_days',
        'is_verified',
        'verification_status',
        'verified_at',
        'rejection_reason',
    ];

    protected $casts = [
        'fee' => 'decimal:2',
        'years_of_experience' => 'integer',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ✅ العلاقات
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // NOTE (Phase 0 / A.3): a Doctor::patients() belongsToMany() through a 'follows'
    // pivot table used to live here. No migration ever created a 'follows' table, so
    // calling it would throw a QueryException — confirmed via full-codebase search
    // (grep for "'follows'", "follows" migrations) that nothing calls it. The real,
    // working doctor<->patient relationship data lives in PatientDoctorRelationship
    // (see doctorRelationships() below and Patient::doctorRelationships()), which is
    // what every controller/service actually uses. Removed rather than left as dead
    // weight that silently breaks if anyone ever calls it.

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(DoctorReview::class);
    }

    // Renamed from followRequests() (Phase 0 / A.3): the old name was misleading —
    // it returned PatientDoctorRelationship rows, not FollowRequest rows (that model
    // doesn't exist; see the removed Patient::followRequests() below). Renamed to
    // match Caregiver::patientRelationships()'s naming convention. Confirmed via
    // full-codebase search that nothing outside this file called ->followRequests()
    // on a Doctor, so this rename has no call-site impact.
    public function patientRelationships(): HasMany
    {
        return $this->hasMany(PatientDoctorRelationship::class);
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(DoctorAvailability::class);
    }

    public function hospitals(): BelongsToMany
    {
        return $this->belongsToMany(Hospital::class, 'doctor_hospitals')->withTimestamps();
    }

    public function verification(): HasOne
    {
        return $this->hasOne(DoctorVerification::class)->latestOfMany();
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(DoctorPaymentMethod::class);
    }

    // RIVA audit (Section 2 — public professional credentials, 2026-08-28):
    // deliberately separate from verification() above — that relation is the
    // doctor's private, admin-reviewed identity/license documents; this one
    // is the doctor's own public credentials/certificates list, only ever
    // shown on the profile for rows the doctor has explicitly marked public
    // (see DoctorCredential::getFileUrlAttribute() and
    // DoctorCredentialController::forDoctor()).
    public function credentials(): HasMany
    {
        return $this->hasMany(DoctorCredential::class);
    }
}
