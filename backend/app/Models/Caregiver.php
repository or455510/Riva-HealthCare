<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    // NOTE (Phase 0 / A.3): a Caregiver::patients() belongsToMany() through a
    // 'caregiver_patient' pivot table used to live here. No migration ever created a
    // 'caregiver_patient' table (confirmed via full-codebase search), so calling it
    // would throw a QueryException. Nothing calls it. The real, working
    // caregiver<->patient relationship data lives in PatientCaregiverRelationship,
    // exposed below via patientRelationships().

    public function patientRelationships(): HasMany
    {
        return $this->hasMany(PatientCaregiverRelationship::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(CaregiverReview::class);
    }
}
