<?php

namespace App\Services\Tenancy;

use App\Models\Clinic;
use App\Models\ClinicUser;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Phase B (Identity/Clinic/RBAC, principal-engineering directive): bug found
 * and fixed mid-slice while building the clinic staff-management UI. The
 * "clinic of one" backfill migration (2026_08_23_000006) only ever ran ONCE,
 * against doctors that already existed at that moment — it created no ongoing
 * mechanism for a doctor who registers afterward. Every doctor who signs up
 * from that point on has NO clinic at all, so EnsureClinicContext rejects them
 * with 403 on every clinic-scoped route (including the staff-management screen
 * this service was built to support) — a real, until-now-undiscovered gap in
 * the tenancy rollout, not a hypothetical one.
 *
 * This service is the ongoing equivalent of that migration's logic, called from
 * ProfileController the first time a Doctor row is created for a user (their
 * first profile completion) — same idempotency rule as the migration (skip if
 * the user is already a member of ANY clinic, e.g. they were invited as staff
 * into someone else's clinic before ever completing their own doctor profile),
 * same naming convention, so a doctor who existed before this fix and a doctor
 * who signs up after it end up in an identically-shaped clinic.
 */
class ClinicProvisioningService
{
    public function ensureSoloClinicForDoctor(User $user, int $doctorId): void
    {
        $alreadyMember = ClinicUser::where('user_id', $user->id)->exists();

        if ($alreadyMember) {
            return;
        }

        $displayName = trim((string) ($user->name ?: $user->first_name ?: 'Doctor'));
        $clinicName = $displayName !== '' ? "{$displayName}'s Practice" : "Doctor #{$doctorId}'s Practice";

        $clinic = Clinic::create([
            'name' => $clinicName,
            'slug' => Str::slug($clinicName.'-'.$doctorId),
            'owner_user_id' => $user->id,
            'status' => 'active',
        ]);

        ClinicUser::create([
            'clinic_id' => $clinic->id,
            'user_id' => $user->id,
            'role' => ClinicUser::ROLE_OWNER,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }
}
