<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A general medicine CATALOG entry — deliberately distinct from the
 * `Medication` model, which is a patient's personal prescription record.
 * See the pharmacy foundation migration's docblock for the full reasoning.
 */
class Medicine extends Model
{
    protected $fillable = [
        'name', 'generic_name', 'form', 'strength', 'requires_prescription', 'is_active',
    ];

    protected $casts = [
        'requires_prescription' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function availability(): HasMany
    {
        return $this->hasMany(PharmacyMedicineAvailability::class);
    }
}
