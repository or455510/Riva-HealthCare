<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PharmacyBranch extends Model
{
    protected $fillable = [
        'pharmacy_id', 'name', 'address', 'city', 'phone',
        'latitude', 'longitude', 'is_24_hours', 'is_active',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'is_24_hours' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function medicineAvailability(): HasMany
    {
        return $this->hasMany(PharmacyMedicineAvailability::class);
    }
}
