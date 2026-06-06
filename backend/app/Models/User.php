<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
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

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }

    // ✅ Accessors
    public function getFullNameAttribute(): string
    {
        return trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));
    }

    public function getProfileImageUrlAttribute(): ?string
    {
        if ($this->profile_image) {
            return str_starts_with($this->profile_image, 'http')
                ? $this->profile_image
                : asset('storage/' . $this->profile_image);
        }

        return $this->google_avatar;
    }

    protected function password(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value && !str_starts_with($value, '$2y$') && !str_starts_with($value, '$argon2')
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
