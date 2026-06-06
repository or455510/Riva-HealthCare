<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    ];

    protected $casts = [
        'fee' => 'decimal:2',
        'years_of_experience' => 'integer',
        'is_verified' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ✅ العلاقات
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function patients()
    {
        return $this->belongsToMany(Patient::class, 'follows', 'doctor_id', 'patient_id')
            ->withTimestamps();
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(DoctorReview::class);
    }

    public function followRequests(): HasMany
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
}
