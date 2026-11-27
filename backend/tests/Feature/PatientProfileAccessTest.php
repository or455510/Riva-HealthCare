<?php

namespace Tests\Feature;

use App\Models\Caregiver;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientCaregiverRelationship;
use App\Models\PatientDoctorRelationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * RIVA-137: profile-p (the single-patient record page) had no dedicated
 * backend endpoint — it always called the doctor-only dashboard()->doctor
 * endpoint regardless of viewer role, which 403'd for an admin (no Doctor
 * row) even though ReportController/DailyStatusController already treat
 * admin as always-full-access to any patient. This suite proves the new
 * DashboardController::patientProfile() endpoint gets all three viewer
 * roles right, including admin.
 */
class PatientProfileAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_any_patients_profile(): void
    {
        [, $patient] = $this->makePatient();
        $admin = User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'name' => 'Admin User',
            'email' => uniqid('admin').'@riva.test', 'password' => 'password',
            'role' => 'admin', 'is_active' => true, 'profile_completed_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $this->getJson("/api/patients/{$patient->id}/profile")
            ->assertOk()
            ->assertJsonPath('data.patient.id', $patient->id);
    }

    public function test_doctor_with_active_relationship_can_view_the_profile(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();
        [, $patient] = $this->makePatient();
        PatientDoctorRelationship::create([
            'doctor_id' => $doctor->id, 'patient_id' => $patient->id, 'status' => 'active',
        ]);

        Sanctum::actingAs($doctorUser);
        $this->getJson("/api/patients/{$patient->id}/profile")
            ->assertOk()
            ->assertJsonPath('data.patient.id', $patient->id);
    }

    public function test_doctor_without_a_relationship_is_forbidden(): void
    {
        [$doctorUser] = $this->makeDoctor();
        [, $patient] = $this->makePatient();

        Sanctum::actingAs($doctorUser);
        $this->getJson("/api/patients/{$patient->id}/profile")->assertForbidden();
    }

    public function test_doctor_with_only_a_pending_relationship_is_forbidden(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();
        [, $patient] = $this->makePatient();
        PatientDoctorRelationship::create([
            'doctor_id' => $doctor->id, 'patient_id' => $patient->id, 'status' => 'pending',
        ]);

        Sanctum::actingAs($doctorUser);
        $this->getJson("/api/patients/{$patient->id}/profile")->assertForbidden();
    }

    public function test_caregiver_with_active_relationship_can_view_the_profile(): void
    {
        [$caregiverUser, $caregiver] = $this->makeCaregiver();
        [, $patient] = $this->makePatient();
        PatientCaregiverRelationship::create([
            'caregiver_id' => $caregiver->id, 'patient_id' => $patient->id, 'status' => 'active',
        ]);

        Sanctum::actingAs($caregiverUser);
        $this->getJson("/api/patients/{$patient->id}/profile")
            ->assertOk()
            ->assertJsonPath('data.patient.id', $patient->id);
    }

    public function test_caregiver_without_a_relationship_is_forbidden(): void
    {
        [$caregiverUser] = $this->makeCaregiver();
        [, $patient] = $this->makePatient();

        Sanctum::actingAs($caregiverUser);
        $this->getJson("/api/patients/{$patient->id}/profile")->assertForbidden();
    }

    public function test_a_patient_cannot_view_this_endpoint_at_all(): void
    {
        [$patientUser, $patient] = $this->makePatient();

        Sanctum::actingAs($patientUser);
        $this->getJson("/api/patients/{$patient->id}/profile")->assertForbidden();
    }

    private function makePatient(): array
    {
        $user = User::create([
            'first_name' => 'Patient', 'last_name' => uniqid('Profile'),
            'name' => 'Patient Profile', 'email' => uniqid('patient').'@riva.test',
            'password' => 'password', 'role' => 'patient', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Patient::create(['user_id' => $user->id, 'gender' => 'female', 'age' => 40])];
    }

    private function makeDoctor(): array
    {
        $user = User::create([
            'first_name' => 'Doctor', 'last_name' => uniqid('Profile'),
            'name' => 'Doctor Profile', 'email' => uniqid('doctor').'@riva.test',
            'password' => 'password', 'role' => 'doctor', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Doctor::create(['user_id' => $user->id, 'fee' => 300])];
    }

    private function makeCaregiver(): array
    {
        $user = User::create([
            'first_name' => 'Caregiver', 'last_name' => uniqid('Profile'),
            'name' => 'Caregiver Profile', 'email' => uniqid('caregiver').'@riva.test',
            'password' => 'password', 'role' => 'caregiver', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Caregiver::create(['user_id' => $user->id])];
    }
}
