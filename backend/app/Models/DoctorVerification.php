<?php

namespace App\Models;

use App\Http\Controllers\StorageFileController;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoctorVerification extends Model
{
    use HasFactory;

    protected $fillable = [
        'doctor_id',
        'national_id_file',
        'medical_license_file',
        'syndicate_card_file',
        'certificate_file',
        'medical_license_number',
        'syndicate_number',
        'specialization',
        'clinic_or_hospital_name',
        'years_of_experience',
        'status',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'years_of_experience' => 'integer',
        'reviewed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = [
        'national_id_file_url',
        'medical_license_file_url',
        'syndicate_card_file_url',
        'certificate_file_url',
    ];

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function getNationalIdFileUrlAttribute(): ?string
    {
        return $this->publicUrl($this->national_id_file);
    }

    public function getMedicalLicenseFileUrlAttribute(): ?string
    {
        return $this->publicUrl($this->medical_license_file);
    }

    public function getSyndicateCardFileUrlAttribute(): ?string
    {
        return $this->publicUrl($this->syndicate_card_file);
    }

    public function getCertificateFileUrlAttribute(): ?string
    {
        return $this->publicUrl($this->certificate_file);
    }

    /**
     * File-serving architecture (principal-engineering directive): these files
     * (national ID, medical license, syndicate card, certificate scans) are the
     * most sensitive category served by this app. Previously returned a raw
     * Storage::disk('public')->url() — unauthenticated and reachable by anyone
     * who obtained the URL, bypassing StorageFileController's ownership check
     * (owning doctor or admin only) entirely. Now mints a short-lived signed URL
     * scoped to whoever is currently authenticated and reading this record
     * (auth()->id()) — see StorageFileController::signedUrl()/showSigned() and
     * its class docblock for why a signed URL (not a raw URL or an auth:sanctum
     * route) is required for something rendered via a plain <img src>.
     */
    private function publicUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $viewerId = auth()->id();
        if (! $viewerId) {
            return null;
        }

        // Backward-compat: rows written before this change stored a full
        // Storage::disk('public')->url() (e.g. http://host/storage/doctor-
        // verifications/x.pdf) rather than a bare path — normalize either shape
        // to a bare path before signing.
        $normalized = preg_replace('#^https?://[^/]+/storage/#', '', $path);
        $normalized = preg_replace('#^/?storage/#', '', $normalized);

        return StorageFileController::signedUrl($normalized, (int) $viewerId);
    }
}
