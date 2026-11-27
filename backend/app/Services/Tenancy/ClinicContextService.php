<?php

namespace App\Services\Tenancy;

use App\Models\ClinicUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * PHASE 1 (Identity + Tenancy): resolves "which clinic, and as what role, is
 * this request acting in" for the authenticated user. This is the single place
 * that decision is made — controllers and middleware call this rather than each
 * reinventing their own clinic-selection logic, which is exactly how a
 * cross-tenant leak would otherwise creep in one endpoint at a time.
 *
 * Rules, deliberately conservative:
 *  - No active clinic membership at all -> 403. Most of today's users (patients,
 *    caregivers, the platform admin role) are not clinic members and never call
 *    a clinic-scoped route in the first place.
 *  - A specific clinic is requested (X-Clinic-Id header or ?clinic_id= query) ->
 *    it is ONLY honored if it matches one of the user's own ACTIVE memberships.
 *    A clinic id belonging to someone else's clinic is rejected outright, never
 *    silently ignored or substituted — this is the actual tenant-isolation
 *    control, not a formality.
 *  - No clinic requested and the user has exactly one active membership ->
 *    default to it (the common case today: a solo doctor's clinic-of-one).
 *  - No clinic requested and the user has more than one active membership ->
 *    409, the caller must say which clinic. Guessing here would risk showing
 *    one clinic's data under another clinic's context.
 */
class ClinicContextService
{
    public function activeMemberships(User $user): Collection
    {
        return $user->clinicMemberships()
            ->where('status', 'active')
            ->with('clinic')
            ->get();
    }

    public function resolve(Request $request, User $user): ClinicUser
    {
        $memberships = $this->activeMemberships($user);

        abort_if($memberships->isEmpty(), 403, 'You are not an active member of any clinic.');

        $requestedClinicId = $request->header('X-Clinic-Id') ?? $request->query('clinic_id');

        if ($requestedClinicId !== null) {
            $membership = $memberships->firstWhere('clinic_id', (int) $requestedClinicId);
            abort_unless($membership, 403, 'You are not an active member of the requested clinic.');

            return $membership;
        }

        if ($memberships->count() === 1) {
            return $memberships->first();
        }

        abort(409, 'You belong to multiple clinics; specify which one via the X-Clinic-Id header.');
    }
}
