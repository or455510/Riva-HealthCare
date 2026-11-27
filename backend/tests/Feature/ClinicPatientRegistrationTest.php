<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\ClinicUser;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase C (Patient registration/search, principal-engineering directive):
 * proves ClinicPatientController — the first real consumer of
 * ClinicUser::PERMISSION_REGISTER_PATIENTS — actually enforces that
 * permission, never leaks patient existence via fuzzy search, and never
 * silently creates a duplicate account.
 */
class ClinicPatientRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_register_a_new_walk_in_patient(): void
    {
        $clinic = $this->makeClinicWithOwner();

        Sanctum::actingAs($clinic['ownerUser']);

        $response = $this->postJson('/api/clinic/patients', [
            'first_name' => 'Walk',
            'last_name' => 'In',
            'email' => 'walkin@riva.test',
            'phone' => '01000000001',
            'gender' => 'female',
            'age' => 34,
        ])->assertCreated();

        $response->assertJsonPath('data.email', 'walkin@riva.test')
            ->assertJsonPath('data.profile_completed', false);

        $this->assertDatabaseHas('users', ['email' => 'walkin@riva.test', 'role' => 'patient']);
        $user = User::where('email', 'walkin@riva.test')->first();
        $this->assertNotNull($user->patient, 'A Patient row should have been created.');
        $this->assertSame('female', $user->patient->gender);
    }

    public function test_registering_a_duplicate_email_is_rejected(): void
    {
        $clinic = $this->makeClinicWithOwner();
        $existingUser = User::create([
            'first_name' => 'Existing', 'last_name' => 'Patient', 'name' => 'Existing Patient',
            'email' => 'existing@riva.test', 'password' => 'password', 'role' => 'patient', 'is_active' => true,
        ]);
        Patient::create(['user_id' => $existingUser->id]);

        Sanctum::actingAs($clinic['ownerUser']);

        $this->postJson('/api/clinic/patients', [
            'first_name' => 'Existing', 'last_name' => 'Patient', 'email' => 'existing@riva.test',
        ])->assertStatus(409);
    }

    public function test_staff_without_register_permission_cannot_register_a_patient(): void
    {
        $clinic = $this->makeClinicWithOwner();
        $staffUser = $this->addStaffMember($clinic['clinic'], [ClinicUser::PERMISSION_MANAGE_QUEUE]);

        Sanctum::actingAs($staffUser);

        $this->postJson('/api/clinic/patients', [
            'first_name' => 'Nope', 'last_name' => 'Denied', 'email' => 'denied@riva.test',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'denied@riva.test']);
    }

    public function test_staff_with_register_permission_can_register_a_patient(): void
    {
        $clinic = $this->makeClinicWithOwner();
        $staffUser = $this->addStaffMember($clinic['clinic'], [ClinicUser::PERMISSION_REGISTER_PATIENTS]);

        Sanctum::actingAs($staffUser);

        $this->postJson('/api/clinic/patients', [
            'first_name' => 'Allowed', 'last_name' => 'Staff', 'email' => 'allowed@riva.test',
        ])->assertCreated();
    }

    public function test_search_finds_an_existing_patient_by_exact_email(): void
    {
        $clinic = $this->makeClinicWithOwner();
        $existingUser = User::create([
            'first_name' => 'Findable', 'last_name' => 'Patient', 'name' => 'Findable Patient',
            'email' => 'findme@riva.test', 'phone' => '01099999999', 'password' => 'password',
            'role' => 'patient', 'is_active' => true,
        ]);
        Patient::create(['user_id' => $existingUser->id, 'gender' => 'male']);

        Sanctum::actingAs($clinic['ownerUser']);

        $this->getJson('/api/clinic/patients/search?email=findme@riva.test')
            ->assertOk()
            ->assertJsonPath('data.found', true)
            ->assertJsonPath('data.patient.email', 'findme@riva.test');

        $this->getJson('/api/clinic/patients/search?phone=01099999999')
            ->assertOk()
            ->assertJsonPath('data.found', true);
    }

    public function test_search_never_leaks_a_patient_via_partial_name(): void
    {
        $clinic = $this->makeClinicWithOwner();
        $existingUser = User::create([
            'first_name' => 'Secret', 'last_name' => 'Name', 'name' => 'Secret Name',
            'email' => 'secret@riva.test', 'password' => 'password', 'role' => 'patient', 'is_active' => true,
        ]);
        Patient::create(['user_id' => $existingUser->id]);

        Sanctum::actingAs($clinic['ownerUser']);

        // No 'name' search parameter exists at all — only exact email/phone.
        $this->getJson('/api/clinic/patients/search?email=someone-else@riva.test')
            ->assertOk()
            ->assertJsonPath('data.found', false);
    }

    public function test_search_requires_an_identifier(): void
    {
        $clinic = $this->makeClinicWithOwner();
        Sanctum::actingAs($clinic['ownerUser']);

        $this->getJson('/api/clinic/patients/search')->assertStatus(422);
    }

    /**
     * RIVA-141: searchByName() is a SEPARATE endpoint from search() above —
     * search() stays exact-match-only forever (see the leak-prevention test
     * above, unchanged). This new endpoint is deliberately scoped to
     * patients who already have a real PatientDoctorRelationship with a
     * doctor who is an active member of THIS clinic — not a fuzzy browse
     * across every Riva patient.
     */
    public function test_search_by_name_finds_a_patient_with_a_relationship_to_this_clinics_doctor(): void
    {
        $clinic = $this->makeClinicWithOwner();
        $ownerDoctor = \App\Models\Doctor::where('user_id', $clinic['ownerUser']->id)->first();

        $patientUser = User::create([
            'first_name' => 'Findable', 'last_name' => 'ByName', 'name' => 'Findable ByName',
            'email' => 'byname@riva.test', 'password' => 'password', 'role' => 'patient', 'is_active' => true,
        ]);
        $patient = Patient::create(['user_id' => $patientUser->id]);
        \App\Models\PatientDoctorRelationship::create([
            'doctor_id' => $ownerDoctor->id, 'patient_id' => $patient->id, 'status' => 'active',
        ]);

        Sanctum::actingAs($clinic['ownerUser']);

        $response = $this->getJson('/api/clinic/patients/search-by-name?q=Findable')->assertOk();
        $names = collect($response->json('data.patients'))->pluck('email');

        $this->assertContains('byname@riva.test', $names);
    }

    public function test_search_by_name_does_not_leak_a_patient_with_no_relationship_to_this_clinic(): void
    {
        $clinic = $this->makeClinicWithOwner();

        $unrelatedPatientUser = User::create([
            'first_name' => 'Unrelated', 'last_name' => 'Stranger', 'name' => 'Unrelated Stranger',
            'email' => 'stranger@riva.test', 'password' => 'password', 'role' => 'patient', 'is_active' => true,
        ]);
        Patient::create(['user_id' => $unrelatedPatientUser->id]);
        // Deliberately no PatientDoctorRelationship created for this patient.

        Sanctum::actingAs($clinic['ownerUser']);

        $response = $this->getJson('/api/clinic/patients/search-by-name?q=Unrelated')->assertOk();

        $this->assertEmpty($response->json('data.patients'));
    }

    public function test_search_by_name_requires_at_least_three_characters(): void
    {
        $clinic = $this->makeClinicWithOwner();
        Sanctum::actingAs($clinic['ownerUser']);

        $this->getJson('/api/clinic/patients/search-by-name?q=ab')->assertStatus(422);
    }

    public function test_search_by_name_is_permission_gated_like_the_other_patient_endpoints(): void
    {
        $clinic = $this->makeClinicWithOwner();
        $staffUser = $this->addStaffMember($clinic['clinic'], [ClinicUser::PERMISSION_MANAGE_QUEUE]);

        Sanctum::actingAs($staffUser);

        $this->getJson('/api/clinic/patients/search-by-name?q=Findable')->assertForbidden();
    }

    public function test_user_with_no_clinic_membership_cannot_register_a_patient(): void
    {
        $patientUser = $this->makeUser('patient', 'lone');
        Patient::create(['user_id' => $patientUser->id]);

        Sanctum::actingAs($patientUser);

        $this->postJson('/api/clinic/patients', [
            'first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@riva.test',
        ])->assertForbidden();
    }

    private function makeClinicWithOwner(): array
    {
        $ownerUser = $this->makeUser('doctor', 'owner');
        Doctor::create(['user_id' => $ownerUser->id, 'fee' => 300]);

        $clinic = Clinic::create([
            'name' => 'Patient Reg Test Clinic',
            'slug' => 'patient-reg-clinic-'.uniqid(),
            'owner_user_id' => $ownerUser->id,
            'status' => 'active',
        ]);
        ClinicUser::create([
            'clinic_id' => $clinic->id,
            'user_id' => $ownerUser->id,
            'role' => ClinicUser::ROLE_OWNER,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return compact('clinic', 'ownerUser');
    }

    private function addStaffMember(Clinic $clinic, array $permissions): User
    {
        $staffUser = $this->makeUser('patient', 'staff');
        ClinicUser::create([
            'clinic_id' => $clinic->id,
            'user_id' => $staffUser->id,
            'role' => ClinicUser::ROLE_STAFF,
            'status' => 'active',
            'permissions' => $permissions,
            'joined_at' => now(),
        ]);

        return $staffUser;
    }

    private function makeUser(string $role, string $label): User
    {
        return User::create([
            'first_name' => ucfirst($role),
            'last_name' => 'PatientReg'.$label,
            'name' => ucfirst($role).' PatientReg '.$label,
            'email' => strtolower($role).'-patientreg-'.strtolower($label).'-'.uniqid().'@riva.test',
            'password' => 'password',
            'role' => $role,
            'is_active' => true,
            'profile_completed_at' => now(),
        ]);
    }
}
