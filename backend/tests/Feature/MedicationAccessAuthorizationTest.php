<?php

namespace Tests\Feature;

use App\Models\Caregiver;
use App\Models\Doctor;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\PatientCaregiverRelationship;
use App\Models\PatientDoctorRelationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * SECURITY (Phase 0 / IDOR) regression coverage: MedicationController::show() must
 * require an active relationship for doctors/caregivers, not blanket-allow them.
 * See the docblock on authorizeMedicationAccess() for the finding this closes.
 */
class MedicationAccessAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unrelated_doctor_cannot_view_a_patients_medication(): void
    {
        [, $patient] = $this->makePatient();
        $medication = $this->makeMedication($patient);
        [$doctorUser] = $this->makeDoctor();

        Sanctum::actingAs($doctorUser);
        $this->getJson("/api/medications/{$medication->id}")->assertForbidden();
    }

    public function test_unrelated_caregiver_cannot_view_a_patients_medication(): void
    {
        [, $patient] = $this->makePatient();
        $medication = $this->makeMedication($patient);
        [$caregiverUser] = $this->makeCaregiver();

        Sanctum::actingAs($caregiverUser);
        $this->getJson("/api/medications/{$medication->id}")->assertForbidden();
    }

    public function test_assigned_doctor_can_view_the_medication(): void
    {
        [, $patient] = $this->makePatient();
        $medication = $this->makeMedication($patient);
        [$doctorUser, $doctor] = $this->makeDoctor();
        PatientDoctorRelationship::create([
            'doctor_id' => $doctor->id,
            'patient_id' => $patient->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($doctorUser);
        $this->getJson("/api/medications/{$medication->id}")->assertOk();
    }

    public function test_assigned_caregiver_can_view_the_medication(): void
    {
        [, $patient] = $this->makePatient();
        $medication = $this->makeMedication($patient);
        [$caregiverUser, $caregiver] = $this->makeCaregiver();
        PatientCaregiverRelationship::create([
            'caregiver_id' => $caregiver->id,
            'patient_id' => $patient->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($caregiverUser);
        $this->getJson("/api/medications/{$medication->id}")->assertOk();
    }

    public function test_pending_doctor_relationship_does_not_grant_access(): void
    {
        [, $patient] = $this->makePatient();
        $medication = $this->makeMedication($patient);
        [$doctorUser, $doctor] = $this->makeDoctor();
        PatientDoctorRelationship::create([
            'doctor_id' => $doctor->id,
            'patient_id' => $patient->id,
            'status' => 'pending',
        ]);

        Sanctum::actingAs($doctorUser);
        $this->getJson("/api/medications/{$medication->id}")->assertForbidden();
    }

    public function test_other_patient_cannot_view_this_patients_medication(): void
    {
        [, $patient] = $this->makePatient();
        $medication = $this->makeMedication($patient);
        [$otherPatientUser] = $this->makePatient();

        Sanctum::actingAs($otherPatientUser);
        $this->getJson("/api/medications/{$medication->id}")->assertForbidden();
    }

    public function test_owning_patient_can_view_their_own_medication(): void
    {
        [$patientUser, $patient] = $this->makePatient();
        $medication = $this->makeMedication($patient);

        Sanctum::actingAs($patientUser);
        $this->getJson("/api/medications/{$medication->id}")->assertOk();
    }

    private function makeMedication(Patient $patient): Medication
    {
        return Medication::create([
            'patient_id' => $patient->id,
            'name' => 'Amoxicillin',
            'dosage' => '500mg',
            'frequency' => 'daily',
            'start_date' => today()->toDateString(),
            'is_active' => true,
        ]);
    }

    private function makePatient(): array
    {
        $user = User::create([
            'first_name' => 'Patient', 'last_name' => uniqid('Med'),
            'name' => 'Patient Med', 'email' => uniqid('patient').'@riva.test',
            'password' => 'password', 'role' => 'patient', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Patient::create(['user_id' => $user->id])];
    }

    private function makeDoctor(): array
    {
        $user = User::create([
            'first_name' => 'Doctor', 'last_name' => uniqid('Med'),
            'name' => 'Doctor Med', 'email' => uniqid('doctor').'@riva.test',
            'password' => 'password', 'role' => 'doctor', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Doctor::create(['user_id' => $user->id])];
    }

    private function makeCaregiver(): array
    {
        $user = User::create([
            'first_name' => 'Caregiver', 'last_name' => uniqid('Med'),
            'name' => 'Caregiver Med', 'email' => uniqid('caregiver').'@riva.test',
            'password' => 'password', 'role' => 'caregiver', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Caregiver::create(['user_id' => $user->id])];
    }
}
