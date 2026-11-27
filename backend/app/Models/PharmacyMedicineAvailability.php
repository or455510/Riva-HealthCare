<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (pharmacy_branch, medicine): whether that branch currently
 * reports the medicine as available, and at what price. This is
 * self-reported data entered through the admin/pharmacy CRUD endpoints —
 * there is no live integration with any real pharmacy system yet, and
 * nothing in this codebase seeds or fabricates a value here. `price` being
 * null means "not published," never "free" — callers must not coerce it.
 */
class PharmacyMedicineAvailability extends Model
{
    // Explicit: Eloquent's Str::plural() guess for this class name does not
    // match the migration's actual table name (it pluralizes "availability"
    // to "availabilities", but the migration deliberately keeps the
    // uncountable-reading "pharmacy_medicine_availability").
    protected $table = 'pharmacy_medicine_availability';

    protected $fillable = [
        'pharmacy_branch_id', 'medicine_id', 'is_available', 'price', 'reported_at',
    ];

    protected $casts = [
        'is_available' => 'boolean',
        'price' => 'decimal:2',
        'reported_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(PharmacyBranch::class, 'pharmacy_branch_id');
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }
}
