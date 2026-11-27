<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\ClinicUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PHASE 1 (Identity + Tenancy + RBAC) — real, HTTP-level proof that clinic
 * scoping actually isolates tenants. Per the execution directive: cross-tenant
 * access must be attempted for real (ID substitution, spoofed X-Clinic-Id
 * header, role tampering), not assumed safe because the frontend hides IDs.
 *
 * Covers: no-membership lockout, header-spoofing rejection, non-manager
 * privilege boundaries, defense-in-depth member-id cross-clinic rejection
 * (ClinicMembershipController::assertMemberBelongsToClinic), last-active-owner
 * protection, and the owner-only owner-role-grant restriction.
 */
class ClinicTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_no_clinic_membership_is_forbidden_on_every_clinic_route(): void
    {
        $user = $this->makeUser();

        // A real, existing membership row (belonging to a clinic the caller has
        // nothing to do with) so route-model-binding resolves successfully and
        // the request actually reaches EnsureClinicContext — a nonexistent id
        // would 404 at binding time regardless of auth state, which would test
        // routing, not authorization.
        [, $otherClinic] = $this->makeClinicWithOwner();
        $someoneElse = $this->makeUser();
        $unrelatedMembership = $this->makeMembership($otherClinic, $someoneElse, ClinicUser::ROLE_STAFF);

        Sanctum::actingAs($user);

        $this->getJson('/api/clinic/members')->assertForbidden();
        $this->postJson('/api/clinic/members', ['email' => $user->email, 'role' => 'staff'])->assertForbidden();
        $this->patchJson("/api/clinic/members/{$unrelatedMembership->id}/role", ['role' => 'admin'])->assertForbidden();
        $this->deleteJson("/api/clinic/members/{$unrelatedMembership->id}")->assertForbidden();
    }

    public function test_member_of_clinic_a_cannot_use_spoofed_header_to_view_clinic_b_members(): void
    {
        [$ownerA, $clinicA] = $this->makeClinicWithOwner();
        [, $clinicB] = $this->makeClinicWithOwner();

        Sanctum::actingAs($ownerA);

        $this->getJson('/api/clinic/members', ['X-Clinic-Id' => (string) $clinicB->id])
            ->assertForbidden();
    }

    public function test_member_of_clinic_a_cannot_use_query_param_to_view_clinic_b_members(): void
    {
        [$ownerA] = $this->makeClinicWithOwner();
        [, $clinicB] = $this->makeClinicWithOwner();

        Sanctum::actingAs($ownerA);

        $this->getJson("/api/clinic/members?clinic_id={$clinicB->id}")
            ->assertForbidden();
    }

    public function test_user_belonging_to_two_clinics_without_specifying_one_gets_409(): void
    {
        $user = $this->makeUser();
        $clinicA = $this->makeClinic();
        $clinicB = $this->makeClinic();
        $this->makeMembership($clinicA, $user, ClinicUser::ROLE_OWNER);
        $this->makeMembership($clinicB, $user, ClinicUser::ROLE_OWNER);

        Sanctum::actingAs($user);

        $this->getJson('/api/clinic/members')->assertStatus(409);
    }

    public function test_user_belonging_to_two_clinics_can_disambiguate_via_header(): void
    {
        $user = $this->makeUser();
        $clinicA = $this->makeClinic();
        $clinicB = $this->makeClinic();
        $this->makeMembership($clinicA, $user, ClinicUser::ROLE_OWNER);
        $this->makeMembership($clinicB, $user, ClinicUser::ROLE_OWNER);

        Sanctum::actingAs($user);

        $this->getJson('/api/clinic/members', ['X-Clinic-Id' => (string) $clinicB->id])
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_non_manager_member_can_view_but_not_invite_members(): void
    {
        [, $clinic] = $this->makeClinicWithOwner();
        $staffUser = $this->makeUser();
        $this->makeMembership($clinic, $staffUser, ClinicUser::ROLE_STAFF);

        Sanctum::actingAs($staffUser);

        $this->getJson('/api/clinic/members')->assertOk();

        $invitee = $this->makeUser();
        $this->postJson('/api/clinic/members', ['email' => $invitee->email, 'role' => 'staff'])
            ->assertForbidden();
    }

    public function test_non_manager_member_cannot_update_another_members_role(): void
    {
        [, $clinic] = $this->makeClinicWithOwner();
        $staffUser = $this->makeUser();
        $staffMembership = $this->makeMembership($clinic, $staffUser, ClinicUser::ROLE_STAFF);
        $otherStaffUser = $this->makeUser();
        $otherMembership = $this->makeMembership($clinic, $otherStaffUser, ClinicUser::ROLE_STAFF);

        Sanctum::actingAs($staffUser);

        $this->patchJson("/api/clinic/members/{$otherMembership->id}/role", ['role' => 'admin'])
            ->assertForbidden();
    }

    public function test_non_manager_member_cannot_remove_another_member(): void
    {
        [, $clinic] = $this->makeClinicWithOwner();
        $staffUser = $this->makeUser();
        $this->makeMembership($clinic, $staffUser, ClinicUser::ROLE_STAFF);
        $otherStaffUser = $this->makeUser();
        $otherMembership = $this->makeMembership($clinic, $otherStaffUser, ClinicUser::ROLE_STAFF);

        Sanctum::actingAs($staffUser);

        $this->deleteJson("/api/clinic/members/{$otherMembership->id}")->assertForbidden();
    }

    public function test_manager_of_clinic_a_cannot_update_role_of_a_clinic_b_member_by_id(): void
    {
        [$ownerA, $clinicA] = $this->makeClinicWithOwner();
        [, $clinicB] = $this->makeClinicWithOwner();
        $memberB = $this->makeUser();
        $membershipB = $this->makeMembership($clinicB, $memberB, ClinicUser::ROLE_STAFF);

        Sanctum::actingAs($ownerA);

        // Owner A is a manager of THEIR OWN clinic, but membershipB.clinic_id
        // belongs to clinic B. assertMemberBelongsToClinic() must reject this
        // as 404 before the policy is even consulted — a manager of one clinic
        // must never be able to act on another clinic's membership rows by
        // guessing/iterating numeric ids.
        $this->patchJson("/api/clinic/members/{$membershipB->id}/role", ['role' => 'admin'])
            ->assertNotFound();

        $this->assertSame(ClinicUser::ROLE_STAFF, $membershipB->fresh()->role);
    }

    public function test_manager_of_clinic_a_cannot_remove_a_clinic_b_member_by_id(): void
    {
        [$ownerA] = $this->makeClinicWithOwner();
        [, $clinicB] = $this->makeClinicWithOwner();
        $memberB = $this->makeUser();
        $membershipB = $this->makeMembership($clinicB, $memberB, ClinicUser::ROLE_STAFF);

        Sanctum::actingAs($ownerA);

        $this->deleteJson("/api/clinic/members/{$membershipB->id}")->assertNotFound();

        $this->assertNotNull(ClinicUser::find($membershipB->id));
    }

    public function test_last_active_owner_cannot_remove_themselves(): void
    {
        [$owner, $clinic] = $this->makeClinicWithOwner();
        $ownerMembership = ClinicUser::where('clinic_id', $clinic->id)->where('user_id', $owner->id)->firstOrFail();

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/clinic/members/{$ownerMembership->id}")->assertForbidden();
        $this->assertNotNull(ClinicUser::find($ownerMembership->id));
    }

    public function test_last_active_owner_cannot_be_demoted_by_another_admin(): void
    {
        [$owner, $clinic] = $this->makeClinicWithOwner();
        $ownerMembership = ClinicUser::where('clinic_id', $clinic->id)->where('user_id', $owner->id)->firstOrFail();
        $adminUser = $this->makeUser();
        $this->makeMembership($clinic, $adminUser, ClinicUser::ROLE_ADMIN);

        Sanctum::actingAs($adminUser);

        $this->patchJson("/api/clinic/members/{$ownerMembership->id}/role", ['role' => 'admin'])
            ->assertForbidden();
        $this->assertSame(ClinicUser::ROLE_OWNER, $ownerMembership->fresh()->role);
    }

    public function test_owner_can_be_removed_when_another_active_owner_remains(): void
    {
        [$ownerOne, $clinic] = $this->makeClinicWithOwner();
        $ownerOneMembership = ClinicUser::where('clinic_id', $clinic->id)->where('user_id', $ownerOne->id)->firstOrFail();
        $ownerTwoUser = $this->makeUser();
        $this->makeMembership($clinic, $ownerTwoUser, ClinicUser::ROLE_OWNER);

        Sanctum::actingAs($ownerTwoUser);

        $this->deleteJson("/api/clinic/members/{$ownerOneMembership->id}")->assertOk();
        $this->assertNull(ClinicUser::find($ownerOneMembership->id));
    }

    public function test_admin_manager_cannot_grant_owner_role_to_another_member(): void
    {
        [, $clinic] = $this->makeClinicWithOwner();
        $adminUser = $this->makeUser();
        $this->makeMembership($clinic, $adminUser, ClinicUser::ROLE_ADMIN);
        $invitee = $this->makeUser();

        Sanctum::actingAs($adminUser);

        $this->postJson('/api/clinic/members', ['email' => $invitee->email, 'role' => 'owner'])
            ->assertStatus(403);
    }

    public function test_owner_can_grant_owner_role_to_another_member(): void
    {
        [$owner, $clinic] = $this->makeClinicWithOwner();
        $invitee = $this->makeUser();

        Sanctum::actingAs($owner);

        $this->postJson('/api/clinic/members', ['email' => $invitee->email, 'role' => 'owner'])
            ->assertStatus(201);

        $this->assertDatabaseHas('clinic_user', [
            'clinic_id' => $clinic->id,
            'user_id' => $invitee->id,
            'role' => 'owner',
        ]);
    }

    public function test_admin_manager_cannot_use_role_update_to_self_promote_to_owner(): void
    {
        [, $clinic] = $this->makeClinicWithOwner();
        $adminUser = $this->makeUser();
        $adminMembership = $this->makeMembership($clinic, $adminUser, ClinicUser::ROLE_ADMIN);

        Sanctum::actingAs($adminUser);

        $this->patchJson("/api/clinic/members/{$adminMembership->id}/role", ['role' => 'owner'])
            ->assertStatus(403);
        $this->assertSame(ClinicUser::ROLE_ADMIN, $adminMembership->fresh()->role);
    }

    /** @return array{0: User, 1: Clinic} */
    private function makeClinicWithOwner(): array
    {
        $owner = $this->makeUser();
        $clinic = $this->makeClinic($owner);
        $this->makeMembership($clinic, $owner, ClinicUser::ROLE_OWNER);

        return [$owner, $clinic];
    }

    private function makeClinic(?User $owner = null): Clinic
    {
        return Clinic::create([
            'name' => 'Clinic '.uniqid(),
            'slug' => 'clinic-'.uniqid(),
            'owner_user_id' => $owner?->id,
            'status' => 'active',
        ]);
    }

    private function makeMembership(Clinic $clinic, User $user, string $role, string $status = 'active'): ClinicUser
    {
        return ClinicUser::create([
            'clinic_id' => $clinic->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => $status,
            'joined_at' => now(),
        ]);
    }

    private function makeUser(): User
    {
        return User::create([
            'first_name' => 'Tenant',
            'last_name' => uniqid('User'),
            'name' => 'Tenant User',
            'email' => uniqid('tenant').'@riva.test',
            'password' => 'password',
            'role' => 'doctor',
            'is_active' => true,
            'profile_completed_at' => now(),
        ]);
    }
}
