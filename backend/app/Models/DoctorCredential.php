<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * See create_doctor_credentials_table's migration docblock for the critical
 * distinction from DoctorVerification: this is a PUBLIC professional
 * credential (degree/certificate) the doctor has explicitly chosen to
 * display, never a private verification document.
 */
class DoctorCredential extends Model
{
    use HasFactory;

    protected $fillable = [
        'doctor_id',
        'title',
        'credential_type',
        'issuing_organization',
        'year',
        'description',
        'file_path',
        'is_public',
    ];

    protected $casts = [
        'year' => 'integer',
        'is_public' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = ['file_url'];

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /**
     * Only ever resolves for a credential the doctor has marked public —
     * RULE 4 (never expose private verification documents publicly) does
     * not apply here since this table never stores a verification document,
     * but a credential the doctor has NOT marked public still should not
     * leak a browsable file URL either.
     */
    public function getFileUrlAttribute(): ?string
    {
        if (! $this->file_path || ! $this->is_public) {
            return null;
        }

        return Storage::disk('public')->url($this->file_path);
    }
}
