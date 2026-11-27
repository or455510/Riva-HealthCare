<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PHASE 1 (Identity + Tenancy): the tenant root. See the create_clinics_table
 * migration docblock for why this is a new concept distinct from Hospital.
 */
class Clinic extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'owner_user_id',
        'status',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ClinicUser::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'clinic_user')
            ->withPivot(['role', 'status', 'invited_at', 'joined_at'])
            ->withTimestamps();
    }

    public function services(): HasMany
    {
        return $this->hasMany(ClinicService::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
