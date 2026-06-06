<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Caregiver extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'specialty',
        'experience_years',
        'salary',
        'bio',
        'about',
        'is_available',
    ];

    protected $casts = [
        'salary' => 'decimal:2',
        'experience_years' => 'integer',
        'is_available' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ✅ العلاقات
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function patients(): BelongsToMany
    {
        return $this->belongsToMany(Patient::class, 'caregiver_patient', 'caregiver_id', 'patient_id')
            ->withTimestamps();
    }

    public function patientRelationships(): HasMany
    {
        return $this->hasMany(PatientCaregiverRelationship::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(CaregiverReview::class);
    }
}
