<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PHASE 1 (Identity + Tenancy + RBAC): one row = "this user has this role in
 * this clinic." See the create_clinic_user_table migration docblock for why
 * patients are deliberately not modeled here.
 */
class ClinicUser extends Model
{
    use HasFactory;

    protected $table = 'clinic_user';

    public const ROLE_OWNER = 'owner';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_DOCTOR = 'doctor';

    public const ROLE_STAFF = 'staff';

    /** Roles that may manage clinic membership (invite, change roles, remove members). */
    public const MANAGER_ROLES = [self::ROLE_OWNER, self::ROLE_ADMIN];

    /**
     * Phase B (RBAC / staff permissions): the operational capabilities a 'staff'
     * (reception/secretary) member can be explicitly granted. Deliberately does
     * NOT include anything medical (prescriptions, diagnoses, medical records,
     * medication plans) — those stay authorized through PatientDoctorRelationship
     * / doctor-only checks elsewhere, never through clinic staff permissions, so
     * granting every operational permission to a secretary can never accidentally
     * grant medical-data access too.
     */
    public const PERMISSION_REGISTER_PATIENTS = 'patients.register';

    public const PERMISSION_MANAGE_APPOINTMENTS = 'appointments.manage';

    public const PERMISSION_MANAGE_QUEUE = 'queue.manage';

    public const PERMISSION_RECORD_PAYMENTS = 'payments.record';

    public const PERMISSION_MANAGE_SERVICES = 'services.manage';

    public const ALL_PERMISSIONS = [
        self::PERMISSION_REGISTER_PATIENTS,
        self::PERMISSION_MANAGE_APPOINTMENTS,
        self::PERMISSION_MANAGE_QUEUE,
        self::PERMISSION_RECORD_PAYMENTS,
        self::PERMISSION_MANAGE_SERVICES,
    ];

    protected $fillable = [
        'clinic_id',
        'user_id',
        'role',
        'status',
        'permissions',
        'invited_at',
        'joined_at',
        'invite_token',
        'invite_token_expires_at',
    ];

    protected $casts = [
        'permissions' => 'array',
        'invited_at' => 'datetime',
        'joined_at' => 'datetime',
        'invite_token_expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // RIVA-143: hide the raw token from any serialized ClinicUser — it must
    // only ever leave the backend via the one-time 'invite_link' returned
    // from store() at invite creation time, never from a later GET.
    protected $hidden = [
        'invite_token',
    ];

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function canManageMembers(): bool
    {
        return $this->isActive() && in_array($this->role, self::MANAGER_ROLES, true);
    }

    /**
     * Phase B (RBAC / staff permissions): owner/admin are always full-access by
     * role (matches canManageMembers() and every existing clinic-scoped policy).
     * 'doctor' membership never grants operational staff permissions either way
     * — a doctor's own capabilities come from being the doctor, not from this
     * list. Only 'staff' is actually gated by the explicit permissions column.
     */
    public function hasPermission(string $permission): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        if (in_array($this->role, self::MANAGER_ROLES, true)) {
            return true;
        }

        if ($this->role !== self::ROLE_STAFF) {
            return false;
        }

        return in_array($permission, $this->permissions ?? [], true);
    }
}
