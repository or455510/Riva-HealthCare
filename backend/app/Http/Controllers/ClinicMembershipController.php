<?php

namespace App\Http\Controllers;

use App\Models\Clinic;
use App\Models\ClinicUser;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Tenancy\ClinicContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * PHASE 1 (Identity + Tenancy + RBAC): the first real feature built on the
 * clinic/clinic_user foundation — a clinic owner/admin managing their own
 * clinic's members. Deliberately the proof-of-concept for the tenancy pattern:
 * every action here resolves the CURRENT clinic from EnsureClinicContext (never
 * from a client-supplied clinic_id in the payload), and every action on a
 * specific member additionally verifies that member actually belongs to that
 * same clinic before checking the policy — a clinic_user id from a different
 * clinic is rejected outright, not just permission-denied.
 */
class ClinicMembershipController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly ClinicContextService $clinicContext,
    ) {}

    /**
     * Phase C (Queue slice, principal-engineering directive): see
     * routes/api.php's docblock for why this exists outside 'clinic.context'.
     * Never aborts — a user with no clinic membership at all (the common
     * case for patients/caregivers/most doctors-in-training) gets a plain
     * `has_clinic: false`, not an error, so the frontend can safely call
     * this on every page load to decide whether to render clinic-staff
     * navigation.
     */
    public function myContext(Request $request)
    {
        $memberships = $this->clinicContext->activeMemberships($request->user());

        if ($memberships->isEmpty()) {
            return $this->success(['has_clinic' => false]);
        }

        return $this->success([
            'has_clinic' => true,
            'ambiguous' => $memberships->count() > 1,
            'clinics' => $memberships->map(fn (ClinicUser $m) => [
                'clinic_id' => $m->clinic_id,
                'clinic_name' => $m->clinic?->name,
                'role' => $m->role,
                'permissions' => $m->permissions ?? [],
            ])->values(),
        ]);
    }

    public function index(Request $request)
    {
        $clinic = $this->currentClinic($request);
        Gate::authorize('viewMembers', $clinic);

        $members = $clinic->memberships()->with('user')->orderBy('role')->get();

        return $this->success($members->map(fn (ClinicUser $m) => $this->serializeMember($m)));
    }

    /**
     * RIVA-143 (product decision, recorded 2026-08-25): previously required
     * `exists:users,email` -- a clinic owner could only invite someone who
     * already had a Riva account, which made inviting a brand-new
     * secretary/receptionist impossible (the reported bug). Now, when the
     * email has no existing account, a real invite-link flow kicks in: a
     * minimal placeholder User is created (mirrors
     * ClinicPatientController::register()'s walk-in pattern -- an unusable
     * random password, profile_completed_at left null) and a single-use,
     * expiring token is returned so the invitee can claim it themselves and
     * set their own password -- never the public role selector (User.role
     * has no 'staff' value by deliberate design; this account's real
     * authority comes entirely from the ClinicUser row, not User.role -- see
     * ClinicUser::hasPermission()).
     */
    public function store(Request $request)
    {
        $clinic = $this->currentClinic($request);
        Gate::authorize('manageMembers', $clinic);

        $data = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', 'in:owner,admin,doctor,staff'],
        ]);

        $actorMembership = $request->attributes->get('clinic_membership');

        // Only an existing owner may grant the owner role to someone else — an
        // admin manager should not be able to mint a co-owner of a clinic they
        // don't fully own.
        if ($data['role'] === ClinicUser::ROLE_OWNER && $actorMembership->role !== ClinicUser::ROLE_OWNER) {
            return $this->error('Only a clinic owner can grant the owner role.', 403);
        }

        $invitedUser = User::where('email', $data['email'])->first();
        $isNewAccount = ! $invitedUser;

        if ($isNewAccount) {
            $invitedUser = User::create([
                'first_name' => 'Invited',
                'last_name' => 'Member',
                'name' => 'Invited Member',
                'email' => $data['email'],
                'role' => 'patient', // harmless default -- see docblock above.
                'password' => Str::random(40),
                'profile_completed_at' => null,
            ]);
        }

        $existing = ClinicUser::where('clinic_id', $clinic->id)->where('user_id', $invitedUser->id)->first();
        if ($existing) {
            return $this->error('This user is already a member of this clinic.', 409);
        }

        $inviteToken = $isNewAccount ? Str::random(48) : null;

        $membership = ClinicUser::create([
            'clinic_id' => $clinic->id,
            'user_id' => $invitedUser->id,
            'role' => $data['role'],
            'status' => 'invited',
            'invited_at' => now(),
            'invite_token' => $inviteToken,
            'invite_token_expires_at' => $inviteToken ? now()->addDays(7) : null,
        ]);

        if ($isNewAccount) {
            // No outbound email delivery is configured in this environment
            // (the same honest, already-documented limitation as
            // ClinicPatientController::register()'s walk-in claim flow) — the
            // invite link is handed back directly in this response so the
            // inviting owner/admin can send it to the new hire themselves
            // until real email delivery exists.
        } else {
            $this->notificationService->notify(
                $invitedUser,
                'clinic_membership_invited',
                'You were invited to a clinic',
                "You have been invited to join {$clinic->name} as {$data['role']}.",
                [
                    'clinic_id' => $clinic->id,
                    'clinic_name' => $clinic->name,
                    'role' => $data['role'],
                ],
                '/clinics/'.$clinic->id,
                false
            );
        }

        $payload = $this->serializeMember($membership->fresh('user'));
        $payload['invite_link'] = $inviteToken ? $this->buildInviteLink($inviteToken) : null;
        $payload['is_new_account'] = $isNewAccount;

        return $this->success($payload, 'Member invited successfully', 201);
    }

    /**
     * RIVA-143: public (unauthenticated) — lets the invite-accept page show
     * "You're invited to join {clinic} as {role}" before the invitee has any
     * credentials at all.
     */
    public function inviteDetails(Request $request, string $token)
    {
        $membership = ClinicUser::where('invite_token', $token)->first();
        abort_if(! $membership, 404, 'This invite link is invalid or has already been used.');
        $this->assertInviteNotExpired($membership);

        return $this->success([
            'clinic_name' => $membership->clinic?->name,
            'role' => $membership->role,
            'email' => $membership->user?->email,
        ]);
    }

    /**
     * RIVA-143: public (unauthenticated) — the invitee claims the
     * placeholder account store() created, sets their own name/password, and
     * is immediately signed in as an active clinic member. Consumes the
     * token (single use) and issues a real Sanctum token, matching
     * AuthController::register()/login()'s response shape so the frontend
     * can reuse the same "store token, redirect" logic.
     */
    public function acceptInvite(Request $request, string $token)
    {
        $membership = ClinicUser::where('invite_token', $token)->first();
        abort_if(! $membership, 404, 'This invite link is invalid or has already been used.');
        $this->assertInviteNotExpired($membership);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $membership->user;
        $user->update([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'name' => trim($data['first_name'].' '.$data['last_name']),
            'password' => $data['password'],
            'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        $membership->update([
            'status' => 'active',
            'joined_at' => now(),
            'invite_token' => null,
            'invite_token_expires_at' => null,
        ]);

        $authToken = $user->createToken('auth-token')->plainTextToken;

        return $this->success([
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'profile_completed' => (bool) $user->profile_completed_at,
                'profile_completed_at' => $user->profile_completed_at,
            ],
            'token' => $authToken,
            'clinic_name' => $membership->clinic?->name,
            'clinic_role' => $membership->role,
        ], 'Welcome! Your account is ready.', 201);
    }

    private function assertInviteNotExpired(ClinicUser $membership): void
    {
        abort_if(
            $membership->invite_token_expires_at && $membership->invite_token_expires_at->isPast(),
            410,
            'This invite link has expired. Ask the clinic to send a new one.'
        );
    }

    private function buildInviteLink(string $token): string
    {
        $frontendUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return "{$frontendUrl}/accept-clinic-invite/{$token}";
    }

    public function updateRole(Request $request, ClinicUser $member)
    {
        $clinic = $this->currentClinic($request);
        $this->assertMemberBelongsToClinic($member, $clinic);
        Gate::authorize('updateMemberRole', [$clinic, $member]);

        $data = $request->validate([
            'role' => ['required', 'in:owner,admin,doctor,staff'],
        ]);

        $actorMembership = $request->attributes->get('clinic_membership');
        if ($data['role'] === ClinicUser::ROLE_OWNER && $actorMembership->role !== ClinicUser::ROLE_OWNER) {
            return $this->error('Only a clinic owner can grant the owner role.', 403);
        }

        $member->update(['role' => $data['role']]);

        return $this->success($this->serializeMember($member->fresh('user')), 'Member role updated successfully');
    }

    public function destroy(Request $request, ClinicUser $member)
    {
        $clinic = $this->currentClinic($request);
        $this->assertMemberBelongsToClinic($member, $clinic);
        Gate::authorize('removeMember', [$clinic, $member]);

        $member->delete();

        return $this->success(null, 'Member removed successfully');
    }

    /**
     * Phase B (RBAC / staff permissions): activate/suspend a member without
     * fully removing their row (removing loses invite/join history; suspending
     * preserves it and is reversible). Shares removeMember()'s last-active-owner
     * protection via ClinicPolicy::updateMemberStatus().
     */
    public function updateStatus(Request $request, ClinicUser $member)
    {
        $clinic = $this->currentClinic($request);
        $this->assertMemberBelongsToClinic($member, $clinic);
        Gate::authorize('updateMemberStatus', [$clinic, $member]);

        $data = $request->validate([
            'status' => ['required', 'in:active,suspended'],
        ]);

        $member->update(['status' => $data['status']]);

        return $this->success($this->serializeMember($member->fresh('user')), 'Member status updated successfully');
    }

    /**
     * Phase B (RBAC / staff permissions): set the explicit operational
     * capability list for a 'staff' (reception/secretary) member. Only
     * ClinicUser::ALL_PERMISSIONS values are accepted — this can never grant a
     * permission that doesn't exist, and the list deliberately contains nothing
     * medical (see that constant's docblock).
     */
    public function updatePermissions(Request $request, ClinicUser $member)
    {
        $clinic = $this->currentClinic($request);
        $this->assertMemberBelongsToClinic($member, $clinic);
        Gate::authorize('updateMemberPermissions', [$clinic, $member]);

        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'in:'.implode(',', ClinicUser::ALL_PERMISSIONS)],
        ]);

        $member->update(['permissions' => array_values(array_unique($data['permissions']))]);

        return $this->success($this->serializeMember($member->fresh('user')), 'Member permissions updated successfully');
    }

    private function currentClinic(Request $request): Clinic
    {
        $membership = $request->attributes->get('clinic_membership');
        abort_unless($membership, 403, 'No active clinic context.');

        return $membership->clinic;
    }

    private function assertMemberBelongsToClinic(ClinicUser $member, Clinic $clinic): void
    {
        abort_unless($member->clinic_id === $clinic->id, 404);
    }

    private function serializeMember(ClinicUser $member): array
    {
        return [
            'id' => $member->id,
            'user_id' => $member->user_id,
            'name' => $member->user?->name,
            'email' => $member->user?->email,
            'role' => $member->role,
            'status' => $member->status,
            'permissions' => $member->permissions ?? [],
            'invited_at' => optional($member->invited_at)->toISOString(),
            'joined_at' => optional($member->joined_at)->toISOString(),
        ];
    }
}
