<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * SECURITY (RIVA-115, P0) regression coverage for a real role
 * self-escalation vulnerability: AuthController::setRole() and
 * completeGoogleProfile() had no check that a role change was still the
 * user's first-time, pre-onboarding choice. Any already-onboarded
 * authenticated user of any role could call POST /api/auth/set-role at any
 * later time to flip their own role — e.g. patient -> doctor — which
 * immediately creates a real Doctor row (is_active=true) that appears in
 * the public doctor listing and can write reports/diagnoses on any patient
 * with an accepted relationship, completely bypassing
 * DoctorVerificationController's admin-approval pipeline.
 */
class RoleEscalationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_pre_onboarding_user_can_still_set_their_role_once(): void
    {
        // Mirrors a fresh Google OAuth signup: role defaults to 'patient',
        // profile_completed_at is null — this must keep working.
        $user = User::create([
            'first_name' => 'Fresh', 'last_name' => 'User', 'name' => 'Fresh User',
            'email' => uniqid('fresh').'@riva.test', 'password' => 'password',
            'role' => 'patient', 'is_active' => true, 'profile_completed_at' => null,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/auth/set-role', ['role' => 'doctor'])
            ->assertOk()
            ->assertJsonPath('user.role', 'doctor');

        $this->assertSame('doctor', $user->fresh()->role);
        $this->assertNotNull(Doctor::where('user_id', $user->id)->first());
    }

    public function test_an_already_onboarded_patient_cannot_escalate_to_doctor(): void
    {
        $patientUser = User::create([
            'first_name' => 'Real', 'last_name' => 'Patient', 'name' => 'Real Patient',
            'email' => uniqid('realpatient').'@riva.test', 'password' => 'password',
            'role' => 'patient', 'is_active' => true, 'profile_completed_at' => now(),
        ]);

        Sanctum::actingAs($patientUser);

        $this->postJson('/api/auth/set-role', ['role' => 'doctor'])
            ->assertStatus(403);

        $this->assertSame('patient', $patientUser->fresh()->role);
        $this->assertNull(Doctor::where('user_id', $patientUser->id)->first());
    }

    public function test_an_already_onboarded_doctor_cannot_reassign_themselves_to_caregiver(): void
    {
        $doctorUser = User::create([
            'first_name' => 'Real', 'last_name' => 'Doctor', 'name' => 'Real Doctor',
            'email' => uniqid('realdoctor').'@riva.test', 'password' => 'password',
            'role' => 'doctor', 'is_active' => true, 'profile_completed_at' => now(),
        ]);

        Sanctum::actingAs($doctorUser);

        $this->postJson('/api/auth/set-role', ['role' => 'caregiver'])
            ->assertStatus(403);

        $this->assertSame('doctor', $doctorUser->fresh()->role);
    }

    public function test_complete_google_profile_also_rejects_an_already_onboarded_user(): void
    {
        $user = User::create([
            'first_name' => 'Already', 'last_name' => 'Onboarded', 'name' => 'Already Onboarded',
            'email' => uniqid('onboarded').'@riva.test', 'password' => 'password',
            'role' => 'patient', 'is_active' => true, 'profile_completed_at' => now(),
        ]);
        $token = $user->createToken('google-auth-token')->plainTextToken;

        $this->postJson('/api/auth/google/complete-profile', [
            'token' => $token,
            'role' => 'doctor',
        ])->assertStatus(403);

        $this->assertSame('patient', $user->fresh()->role);
        $this->assertNull(Doctor::where('user_id', $user->id)->first());
    }

    public function test_complete_google_profile_still_works_for_a_fresh_pre_onboarding_user(): void
    {
        $user = User::create([
            'first_name' => 'Fresh', 'last_name' => 'Oauth', 'name' => 'Fresh Oauth',
            'email' => uniqid('freshoauth').'@riva.test', 'password' => 'password',
            'role' => 'patient', 'is_active' => true, 'profile_completed_at' => null,
        ]);
        $token = $user->createToken('google-auth-token')->plainTextToken;

        $this->postJson('/api/auth/google/complete-profile', [
            'token' => $token,
            'role' => 'doctor',
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertSame('doctor', $fresh->role);
        $this->assertNotNull($fresh->profile_completed_at);
        $this->assertNotNull(Doctor::where('user_id', $user->id)->first());
    }
}
