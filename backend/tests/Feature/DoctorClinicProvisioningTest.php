<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\ClinicUser;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase B (Identity/Clinic/RBAC, principal-engineering directive): regression
 * coverage for the bug found and fixed while building the clinic staff UI — a
 * doctor registering after the one-time backfill migration had no clinic at
 * all and was locked out of every clinic-scoped route. See
 * ClinicProvisioningService's docblock and ProfileController::saveProfile()'s
 * new hook for the fix.
 */
class DoctorClinicProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_completing_profile_as_a_new_doctor_provisions_a_solo_clinic(): void
    {
        $user = $this->makeUnprofiledDoctorUser();
        Sanctum::actingAs($user);

        $this->assertNull($user->doctor);
        $this->assertSame(0, ClinicUser::where('user_id', $user->id)->count());

        $this->postJson('/api/profile/complete', ['specialty' => 'Cardiology'])->assertOk();

        $doctor = Doctor::where('user_id', $user->id)->first();
        $this->assertNotNull($doctor, 'Doctor row should have been created.');

        $membership = ClinicUser::where('user_id', $user->id)->first();
        $this->assertNotNull($membership, 'Doctor should now have a clinic membership.');
        $this->assertSame(ClinicUser::ROLE_OWNER, $membership->role);
        $this->assertSame('active', $membership->status);

        $clinic = Clinic::find($membership->clinic_id);
        $this->assertNotNull($clinic);
        $this->assertSame($user->id, $clinic->owner_user_id);
        $this->assertStringContainsString("'s Practice", $clinic->name);
    }

    public function test_completing_profile_a_second_time_does_not_create_a_duplicate_clinic(): void
    {
        $user = $this->makeUnprofiledDoctorUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/profile/complete', ['specialty' => 'Cardiology'])->assertOk();
        $this->assertSame(1, ClinicUser::where('user_id', $user->id)->count());

        // Second completion call (e.g. the user edits their profile again) must
        // not provision a second clinic — Doctor already exists by then.
        $this->postJson('/api/profile', ['specialty' => 'Cardiology, updated'])->assertOk();
        $this->assertSame(1, ClinicUser::where('user_id', $user->id)->count());
    }

    public function test_doctor_already_invited_into_another_clinic_does_not_get_a_redundant_solo_clinic(): void
    {
        // A doctor who was invited as staff into an existing clinic BEFORE ever
        // completing their own doctor profile — the exact idempotency rule the
        // backfill migration used ("already a member of ANY clinic → skip") must
        // still hold here.
        $user = $this->makeUnprofiledDoctorUser();
        $existingClinic = Clinic::create([
            'name' => 'Someone Else\'s Clinic',
            'slug' => 'someone-else-clinic-'.uniqid(),
            'owner_user_id' => $this->makeUnprofiledDoctorUser()->id,
            'status' => 'active',
        ]);
        ClinicUser::create([
            'clinic_id' => $existingClinic->id,
            'user_id' => $user->id,
            'role' => ClinicUser::ROLE_STAFF,
            'status' => 'invited',
            'invited_at' => now(),
        ]);

        Sanctum::actingAs($user);
        $this->postJson('/api/profile/complete', ['specialty' => 'Cardiology'])->assertOk();

        $memberships = ClinicUser::where('user_id', $user->id)->get();
        $this->assertCount(1, $memberships, 'No second (solo) clinic should have been created.');
        $this->assertSame($existingClinic->id, $memberships->first()->clinic_id);
    }

    public function test_newly_provisioned_doctor_can_immediately_use_clinic_scoped_routes(): void
    {
        $user = $this->makeUnprofiledDoctorUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/profile/complete', ['specialty' => 'Cardiology'])->assertOk();

        // Before the fix this would 403 "No active clinic context" for every
        // doctor who registered after the one-time backfill migration.
        $this->getJson('/api/clinic/members')->assertOk();
    }

    private function makeUnprofiledDoctorUser(): User
    {
        return User::create([
            'first_name' => 'New',
            'last_name' => 'Doctor',
            'name' => 'New Doctor',
            'email' => uniqid('newdoctor').'@riva.test',
            'password' => 'password',
            'role' => 'doctor',
            'is_active' => true,
        ]);
    }
}
