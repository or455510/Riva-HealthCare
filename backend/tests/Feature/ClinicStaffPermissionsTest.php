<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\ClinicUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase B (RBAC / staff permissions, principal-engineering directive): a
 * secretary/reception member (role=staff) must be grantable operational
 * capabilities (register patients, manage queue, etc.) WITHOUT that ever
 * implying medical-data access — see ClinicUser::hasPermission() and
 * ALL_PERMISSIONS. This suite proves the permission list is actually
 * enforced, that only clinic managers (owner/admin) can set it, that it
 * can't be poisoned with an arbitrary string, and that suspend/reactivate
 * carries the same last-active-owner protection as removal.
 */
class ClinicStaffPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_grant_and_revoke_staff_permissions(): void
    {
        [$owner, $clinic] = $this->makeClinicWithOwner();
        $staffUser = $this->makeUser();
        $staffMember = $this->makeMembership($clinic, $staffUser, ClinicUser::ROLE_STAFF);

        Sanctum::actingAs($owner);
        $this->patchJson("/api/clinic/members/{$staffMember->id}/permissions", [
            'permissions' => [ClinicUser::PERMISSION_REGISTER_PATIENTS, ClinicUser::PERMISSION_MANAGE_QUEUE],
        ])
            ->assertOk()
            ->assertJsonPath('data.permissions', [
                ClinicUser::PERMISSION_REGISTER_PATIENTS,
                ClinicUser::PERMISSION_MANAGE_QUEUE,
            ]);

        $this->assertTrue($staffMember->fresh()->hasPermission(ClinicUser::PERMISSION_REGISTER_PATIENTS));
        $this->assertFalse($staffMember->fresh()->hasPermission(ClinicUser::PERMISSION_RECORD_PAYMENTS));

        // Revoke down to nothing.
        $this->patchJson("/api/clinic/members/{$staffMember->id}/permissions", ['permissions' => []])
            ->assertOk()
            ->assertJsonPath('data.permissions', []);
        $this->assertFalse($staffMember->fresh()->hasPermission(ClinicUser::PERMISSION_REGISTER_PATIENTS));
    }

    public function test_unknown_permission_string_is_rejected(): void
    {
        [$owner, $clinic] = $this->makeClinicWithOwner();
        $staffMember = $this->makeMembership($clinic, $this->makeUser(), ClinicUser::ROLE_STAFF);

        Sanctum::actingAs($owner);
        $this->patchJson("/api/clinic/members/{$staffMember->id}/permissions", [
            'permissions' => ['medical_records.edit'],
        ])->assertStatus(422);

        $this->assertNull($staffMember->fresh()->permissions);
    }

    public function test_non_manager_staff_member_cannot_grant_themselves_permissions(): void
    {
        [, $clinic] = $this->makeClinicWithOwner();
        $staffUser = $this->makeUser();
        $staffMember = $this->makeMembership($clinic, $staffUser, ClinicUser::ROLE_STAFF);

        Sanctum::actingAs($staffUser);
        $this->patchJson("/api/clinic/members/{$staffMember->id}/permissions", [
            'permissions' => [ClinicUser::PERMISSION_RECORD_PAYMENTS],
        ])->assertForbidden();
    }

    public function test_manager_of_clinic_a_cannot_set_permissions_of_a_clinic_b_member(): void
    {
        [$ownerA, $clinicA] = $this->makeClinicWithOwner();
        [, $clinicB] = $this->makeClinicWithOwner();
        $staffB = $this->makeMembership($clinicB, $this->makeUser(), ClinicUser::ROLE_STAFF);

        Sanctum::actingAs($ownerA);
        $this->patchJson("/api/clinic/members/{$staffB->id}/permissions", [
            'permissions' => [ClinicUser::PERMISSION_REGISTER_PATIENTS],
        ])->assertNotFound();
    }

    public function test_owner_can_suspend_and_reactivate_a_staff_member(): void
    {
        [$owner, $clinic] = $this->makeClinicWithOwner();
        $staffMember = $this->makeMembership($clinic, $this->makeUser(), ClinicUser::ROLE_STAFF);

        Sanctum::actingAs($owner);
        $this->patchJson("/api/clinic/members/{$staffMember->id}/status", ['status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $this->assertFalse($staffMember->fresh()->isActive());

        $this->patchJson("/api/clinic/members/{$staffMember->id}/status", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    public function test_last_active_owner_cannot_be_suspended(): void
    {
        [$owner, $clinic] = $this->makeClinicWithOwner();
        $ownerMembership = ClinicUser::where('clinic_id', $clinic->id)->where('user_id', $owner->id)->firstOrFail();

        Sanctum::actingAs($owner);
        $this->patchJson("/api/clinic/members/{$ownerMembership->id}/status", ['status' => 'suspended'])
            ->assertForbidden();

        $this->assertTrue($ownerMembership->fresh()->isActive());
    }

    public function test_suspended_staff_member_loses_all_permission_grants(): void
    {
        [$owner, $clinic] = $this->makeClinicWithOwner();
        $staffMember = $this->makeMembership($clinic, $this->makeUser(), ClinicUser::ROLE_STAFF);
        $staffMember->update(['permissions' => [ClinicUser::PERMISSION_REGISTER_PATIENTS]]);

        $this->assertTrue($staffMember->fresh()->hasPermission(ClinicUser::PERMISSION_REGISTER_PATIENTS));

        Sanctum::actingAs($owner);
        $this->patchJson("/api/clinic/members/{$staffMember->id}/status", ['status' => 'suspended'])->assertOk();

        $this->assertFalse($staffMember->fresh()->hasPermission(ClinicUser::PERMISSION_REGISTER_PATIENTS));
    }

    public function test_owner_and_admin_have_every_permission_regardless_of_stored_list(): void
    {
        [$owner, $clinic] = $this->makeClinicWithOwner();
        $adminUser = $this->makeUser();
        $admin = $this->makeMembership($clinic, $adminUser, ClinicUser::ROLE_ADMIN);

        $ownerMembership = ClinicUser::where('clinic_id', $clinic->id)->where('user_id', $owner->id)->firstOrFail();

        foreach (ClinicUser::ALL_PERMISSIONS as $permission) {
            $this->assertTrue($ownerMembership->hasPermission($permission));
            $this->assertTrue($admin->hasPermission($permission));
        }
    }

    public function test_doctor_membership_never_gets_staff_permissions(): void
    {
        [, $clinic] = $this->makeClinicWithOwner();
        $doctorUser = $this->makeUser();
        $doctor = $this->makeMembership($clinic, $doctorUser, ClinicUser::ROLE_DOCTOR);
        $doctor->update(['permissions' => ClinicUser::ALL_PERMISSIONS]);

        foreach (ClinicUser::ALL_PERMISSIONS as $permission) {
            $this->assertFalse($doctor->fresh()->hasPermission($permission));
        }
    }

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
            'first_name' => 'Staff',
            'last_name' => uniqid('User'),
            'name' => 'Staff User',
            'email' => uniqid('staff').'@riva.test',
            'password' => 'password',
            'role' => 'doctor',
            'is_active' => true,
            'profile_completed_at' => now(),
        ]);
    }
}
