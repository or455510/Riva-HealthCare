<?php

namespace Tests\Feature;

use App\Models\Caregiver;
use App\Models\DailyStatus;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientCaregiverRelationship;
use App\Models\PatientDoctorRelationship;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportAccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepted_doctor_has_full_report_access_and_can_write(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();
        [, $patient] = $this->makePatient();
        PatientDoctorRelationship::create([
            'doctor_id' => $doctor->id,
            'patient_id' => $patient->id,
            'status' => 'active',
        ]);
        Report::create([
            'doctor_id' => $doctor->id,
            'patient_id' => $patient->id,
            'title' => 'Initial Report',
            'summary' => 'Patient is stable.',
        ]);

        Sanctum::actingAs($doctorUser);

        $this->getJson("/api/patients/{$patient->id}/reports")
            ->assertOk()
            ->assertJsonPath('data.access_mode', 'full')
            ->assertJsonCount(1, 'data.reports');

        $this->postJson('/api/reports', [
            'patient_id' => $patient->id,
            'title' => 'Follow-up',
            'summary' => 'Continue monitoring.',
        ])->assertCreated()
            ->assertJsonPath('message', 'Report created successfully');
    }

    public function test_pending_doctor_gets_preview_only_without_reports(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();
        [, $patient] = $this->makePatient();
        PatientDoctorRelationship::create([
            'doctor_id' => $doctor->id,
            'patient_id' => $patient->id,
            'status' => 'pending',
        ]);
        DailyStatus::create([
            'patient_id' => $patient->id,
            'mood' => 'tired',
            'pain_level' => 5,
            'risk_level' => 'attention_needed',
            'symptoms' => 'Dizziness',
        ]);

        Sanctum::actingAs($doctorUser);

        $this->getJson("/api/patients/{$patient->id}/reports")
            ->assertOk()
            ->assertJsonPath('data.access_mode', 'preview')
            ->assertJsonCount(0, 'data.reports')
            ->assertJsonPath('data.preview.latest_check_in.symptoms', 'Dizziness');

        $this->postJson('/api/reports', [
            'patient_id' => $patient->id,
            'title' => 'Blocked',
            'summary' => 'Should not save.',
        ])->assertForbidden();
    }

    public function test_accepted_caregiver_has_full_view_but_cannot_write_report(): void
    {
        [, $doctor] = $this->makeDoctor();
        [$caregiverUser, $caregiver] = $this->makeCaregiver();
        [, $patient] = $this->makePatient();
        PatientCaregiverRelationship::create([
            'caregiver_id' => $caregiver->id,
            'patient_id' => $patient->id,
            'status' => 'active',
        ]);
        Report::create([
            'doctor_id' => $doctor->id,
            'patient_id' => $patient->id,
            'title' => 'Doctor Report',
            'summary' => 'Shared with caregiver.',
        ]);

        Sanctum::actingAs($caregiverUser);

        $this->getJson("/api/patients/{$patient->id}/reports")
            ->assertOk()
            ->assertJsonPath('data.access_mode', 'full')
            ->assertJsonCount(1, 'data.reports');

        $this->postJson('/api/reports', [
            'patient_id' => $patient->id,
            'title' => 'Caregiver Report',
            'summary' => 'Caregiver should not write here.',
        ])->assertForbidden();
    }

    public function test_unrelated_doctor_and_caregiver_are_denied(): void
    {
        [$doctorUser] = $this->makeDoctor();
        [$caregiverUser] = $this->makeCaregiver();
        [, $patient] = $this->makePatient();

        Sanctum::actingAs($doctorUser);
        $this->getJson("/api/patients/{$patient->id}/reports")->assertForbidden();

        Sanctum::actingAs($caregiverUser);
        $this->getJson("/api/patients/{$patient->id}/reports")->assertForbidden();
    }

    private function makePatient(): array
    {
        $user = User::create([
            'first_name' => 'Patient',
            'last_name' => uniqid('Report'),
            'name' => 'Patient Report',
            'email' => uniqid('patient') . '@riva.test',
            'password' => 'password',
            'role' => 'patient',
            'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Patient::create(['user_id' => $user->id, 'chronic_conditions' => 'Diabetes, Hypertension'])];
    }

    private function makeDoctor(): array
    {
        $user = User::create([
            'first_name' => 'Doctor',
            'last_name' => uniqid('Report'),
            'name' => 'Doctor Report',
            'email' => uniqid('doctor') . '@riva.test',
            'password' => 'password',
            'role' => 'doctor',
            'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Doctor::create(['user_id' => $user->id])];
    }

    private function makeCaregiver(): array
    {
        $user = User::create([
            'first_name' => 'Caregiver',
            'last_name' => uniqid('Report'),
            'name' => 'Caregiver Report',
            'email' => uniqid('caregiver') . '@riva.test',
            'password' => 'password',
            'role' => 'caregiver',
            'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Caregiver::create(['user_id' => $user->id])];
    }
}
