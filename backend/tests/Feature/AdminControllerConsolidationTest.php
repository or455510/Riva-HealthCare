<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * SECURITY (Phase 0 / D5) regression coverage. Closes the test-coverage gap flagged
 * in the Phase 0 verification report: AdminController::updateUserStatus() and
 * verifyDoctor() were patched to match Admin\AdminAreaController's behavior (a
 * self-deactivation guard, and a consistent full field-set update on doctor
 * verification), but shipped without a dedicated test asserting either fix actually
 * works end-to-end through the route.
 */
class AdminControllerConsolidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cannot_deactivate_their_own_account_via_legacy_endpoint(): void
    {
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$admin->id}/status", ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Admin users cannot deactivate or suspend themselves.');

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_admin_can_deactivate_a_different_user_via_legacy_endpoint(): void
    {
        $admin = $this->makeAdmin();
        $other = User::create([
            'first_name' => 'Other', 'last_name' => 'User', 'name' => 'Other User',
            'email' => uniqid('other').'@riva.test', 'password' => 'password',
            'role' => 'patient', 'is_active' => true, 'profile_completed_at' => now(),
        ]);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$other->id}/status", ['is_active' => false])
            ->assertOk();

        $fresh = $other->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertSame('inactive', $fresh->status);
    }

    public function test_verify_doctor_sets_full_consistent_field_set_on_approval(): void
    {
        $admin = $this->makeAdmin();
        [, $doctor] = $this->makeDoctor();
        $doctor->forceFill(['rejection_reason' => 'previously rejected'])->save();

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/doctors/{$doctor->id}/verify", ['verification_status' => 'verified'])
            ->assertOk();

        $fresh = $doctor->fresh();
        $this->assertSame('verified', $fresh->verification_status);
        $this->assertTrue((bool) $fresh->is_verified);
        $this->assertNotNull($fresh->verified_at);
        $this->assertNull($fresh->rejection_reason);
    }

    public function test_verify_doctor_sets_full_consistent_field_set_on_rejection(): void
    {
        $admin = $this->makeAdmin();
        [, $doctor] = $this->makeDoctor();
        $doctor->forceFill(['verification_status' => 'verified', 'is_verified' => true, 'verified_at' => now()])->save();

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/doctors/{$doctor->id}/verify", ['verification_status' => 'rejected'])
            ->assertOk();

        $fresh = $doctor->fresh();
        $this->assertSame('rejected', $fresh->verification_status);
        $this->assertFalse((bool) $fresh->is_verified);
        $this->assertNull($fresh->verified_at);
    }

    public function test_non_admin_cannot_reach_legacy_admin_endpoints(): void
    {
        $patient = User::create([
            'first_name' => 'Patient', 'last_name' => 'User', 'name' => 'Patient User',
            'email' => uniqid('patient').'@riva.test', 'password' => 'password',
            'role' => 'patient', 'is_active' => true, 'profile_completed_at' => now(),
        ]);
        [, $doctor] = $this->makeDoctor();
        Sanctum::actingAs($patient);

        $this->patchJson("/api/admin/users/{$patient->id}/status", ['is_active' => false])
            ->assertForbidden();
        $this->patchJson("/api/admin/doctors/{$doctor->id}/verify", ['verification_status' => 'verified'])
            ->assertForbidden();
    }

    private function makeAdmin(): User
    {
        return User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'name' => 'Admin User',
            'email' => uniqid('admin').'@riva.test', 'password' => 'password',
            'role' => 'admin', 'is_active' => true, 'profile_completed_at' => now(),
        ]);
    }

    private function makeDoctor(): array
    {
        $user = User::create([
            'first_name' => 'Doctor', 'last_name' => uniqid('D5'),
            'name' => 'Doctor D5', 'email' => uniqid('doctor').'@riva.test',
            'password' => 'password', 'role' => 'doctor', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Doctor::create(['user_id' => $user->id])];
    }
}
