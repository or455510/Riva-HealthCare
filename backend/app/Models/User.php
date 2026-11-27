<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'email',
        'password',
        'first_name',
        'last_name',
        'name',
        'phone',
        'address',
        'profile_image',
        'google_id',
        'google_avatar',
        'profile_completed_at',
        'role',
        'is_active',
        'status',
        'doctor_verified_at',
        'verification_status',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'doctor_verified_at' => 'datetime',
        'profile_completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'profile_image_url',
    ];

    // ✅ العلاقات
    public function patient(): HasOne
    {
        return $this->hasOne(Patient::class);
    }

    public function doctor(): HasOne
    {
        return $this->hasOne(Doctor::class);
    }

    public function caregiver(): HasOne
    {
        return $this->hasOne(Caregiver::class);
    }

    public function notifications()
    {
        return $this->morphMany(Notification::class, 'notifiable');
    }

    /**
     * PHASE 1 (Identity + Tenancy + RBAC): every clinic this user belongs to, and
     * as what role. A user may belong to more than one clinic — see the
     * clinic_user migration docblock for why this isn't a single fixed column.
     */
    public function clinicMemberships(): HasMany
    {
        return $this->hasMany(ClinicUser::class);
    }

    public function clinics(): BelongsToMany
    {
        return $this->belongsToMany(Clinic::class, 'clinic_user')
            ->withPivot(['role', 'status', 'invited_at', 'joined_at'])
            ->withTimestamps();
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }

    // ✅ Accessors
    public function getFullNameAttribute(): string
    {
        return trim(($this->first_name ?? '').' '.($this->last_name ?? ''));
    }

    /**
     * RIVA image-bug audit (product-owner request, recorded 2026-08-25):
     * this used to return null when a user had no uploaded photo and no
     * Google avatar. Every frontend screen that renders an avatar already
     * has its OWN client-side fallback to a generated ui-avatars.com
     * initials image (`photo || 'https://ui-avatars.com/...'`) — but at
     * least one screen (the doctor dashboard's "Patient Follow Requests"
     * widget) binds an <img> directly to this field with no such fallback,
     * so a null value there rendered a broken-image icon instead. Making
     * this accessor itself never return null closes that gap for EVERY
     * consumer — including screens this session cannot directly edit due
     * to a folder-depth limitation — without changing what's shown
     * anywhere a client-side fallback already existed (that fallback
     * simply never triggers now, since this URL is already valid).
     */
    public function getProfileImageUrlAttribute(): ?string
    {
        if ($this->profile_image) {
            return str_starts_with($this->profile_image, 'http')
                ? $this->profile_image
                : asset('storage/'.$this->profile_image);
        }

        if ($this->google_avatar) {
            return $this->google_avatar;
        }

        $name = trim((string) $this->full_name) ?: 'User';

        return 'https://ui-avatars.com/api/?name='.urlencode($name).'&background=0052FF&color=fff&size=128&bold=true&rounded=true';
    }

    protected function password(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value && ! str_starts_with($value, '$2y$') && ! str_starts_with($value, '$argon2')
                ? bcrypt($value)
                : $value
        );
    }

    // ✅ Methods
    public function isDoctor(): bool
    {
        return $this->role === 'doctor';
    }

    public function isPatient(): bool
    {
        return $this->role === 'patient';
    }

    public function isCaregiver(): bool
    {
        return $this->role === 'caregiver';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
