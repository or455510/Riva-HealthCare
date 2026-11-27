<?php

namespace Tests\Feature;

use App\Models\Caregiver;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientCaregiverRelationship;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * SECURITY (RIVA-106 / audit §22 P1-3, "unmatched role") regression
 * coverage for ReportController::index(). See the docblock on that method
 * for the full finding: a role that matched none of patient/doctor/
 * caregiver/admin's explicit branches — including a patient/doctor/
 * caregiver user whose profile relation is unexpectedly missing — used to
 * fall through to zero where() filters and receive every medical report in
 * the entire system. Mirrors AppointmentListScopingTest's coverage of the
 * identical, already-fixed pattern in AppointmentController::index().
 */
class ReportListScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_caregiver_only_sees_reports_of_their_related_patients(): void
    {
        [, $relatedPatient] = $this->makePatient();
        [, $unrelatedPatient] = $this->makePatient();
        [, $doctor] = $this->makeDoctor();
        [$caregiverUser, $caregiver] = $this->makeCaregiver();

        PatientCaregiverRelationship::create([
            'caregiver_id' => $caregiver->id,
            'patient_id' => $relatedPatient->id,
            'status' => 'active',
        ]);

        $relatedReport = $this->makeReport($relatedPatient, $doctor);
        $unrelatedReport = $this->makeReport($unrelatedPatient, $doctor);

        Sanctum::actingAs($caregiverUser);
        $response = $this->getJson('/api/reports')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($relatedReport->id, $ids);
        $this->assertNotContains($unrelatedReport->id, $ids, "Caregiver must not see an unrelated patient's report.");
    }

    public function test_caregiver_with_no_relationships_at_all_sees_an_empty_list_not_everything(): void
    {
        [, $patient] = $this->makePatient();
        [, $doctor] = $this->makeDoctor();
        $this->makeReport($patient, $doctor);

        [$caregiverUser] = $this->makeCaregiver();

        Sanctum::actingAs($caregiverUser);
        $response = $this->getJson('/api/reports')->assertOk();

        $this->assertCount(0, $response->json('data'), 'A caregiver with zero relationships must not see any report.');
    }

    public function test_patient_only_sees_their_own_reports(): void
    {
        [$patientUser, $patient] = $this->makePatient();
        [, $otherPatient] = $this->makePatient();
        [, $doctor] = $this->makeDoctor();

        $ownReport = $this->makeReport($patient, $doctor);
        $otherReport = $this->makeReport($otherPatient, $doctor);

        Sanctum::actingAs($patientUser);
        $response = $this->getJson('/api/reports')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($ownReport->id, $ids);
        $this->assertNotContains($otherReport->id, $ids);
    }

    public function test_doctor_only_sees_their_own_reports(): void
    {
        [, $patient] = $this->makePatient();
        [$doctorUser, $doctor] = $this->makeDoctor();
        [, $otherDoctor] = $this->makeDoctor();

        $ownReport = $this->makeReport($patient, $doctor);
        $otherReport = $this->makeReport($patient, $otherDoctor);

        Sanctum::actingAs($doctorUser);
        $response = $this->getJson('/api/reports')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($ownReport->id, $ids);
        $this->assertNotContains($otherReport->id, $ids);
    }

    public function test_admin_sees_every_report_intentionally(): void
    {
        [, $patient] = $this->makePatient();
        [, $doctor] = $this->makeDoctor();
        $report = $this->makeReport($patient, $doctor);

        $admin = User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'name' => 'Admin User',
            'email' => uniqid('admin').'@riva.test', 'password' => 'password',
            'role' => 'admin', 'is_active' => true, 'profile_completed_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/reports')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($report->id, $ids);
    }

    public function test_a_role_with_no_matching_profile_relation_sees_an_empty_list_not_everything(): void
    {
        // A user whose role claims 'doctor' but has no Doctor row at all —
        // a data-integrity edge case, not a hypothetical role value. Before
        // the else-branch fix, $user->role === 'doctor' && $user->doctor
        // being false meant every elseif failed and the query ran with no
        // WHERE clause at all: every report in the system.
        [, $patient] = $this->makePatient();
        [, $doctor] = $this->makeDoctor();
        $this->makeReport($patient, $doctor);

        $brokenDoctorUser = User::create([
            'first_name' => 'Broken', 'last_name' => 'Doctor', 'name' => 'Broken Doctor',
            'email' => uniqid('brokendoctor').'@riva.test', 'password' => 'password',
            'role' => 'doctor', 'is_active' => true, 'profile_completed_at' => now(),
        ]);

        Sanctum::actingAs($brokenDoctorUser);
        $response = $this->getJson('/api/reports')->assertOk();

        $this->assertCount(0, $response->json('data'));
    }

    private function makeReport(Patient $patient, Doctor $doctor): Report
    {
        return Report::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'title' => 'Test Report',
            'summary' => 'Summary',
            'final_report' => 'Final report content',
        ]);
    }

    private function makePatient(): array
    {
        $user = User::create([
            'first_name' => 'Patient', 'last_name' => uniqid('RL'),
            'name' => 'Patient RL', 'email' => uniqid('patient').'@riva.test',
            'password' => 'password', 'role' => 'patient', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Patient::create(['user_id' => $user->id])];
    }

    private function makeDoctor(): array
    {
        $user = User::create([
            'first_name' => 'Doctor', 'last_name' => uniqid('RL'),
            'name' => 'Doctor RL', 'email' => uniqid('doctor').'@riva.test',
            'password' => 'password', 'role' => 'doctor', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Doctor::create(['user_id' => $user->id, 'fee' => 300])];
    }

    private function makeCaregiver(): array
    {
        $user = User::create([
            'first_name' => 'Caregiver', 'last_name' => uniqid('RL'),
            'name' => 'Caregiver RL', 'email' => uniqid('caregiver').'@riva.test',
            'password' => 'password', 'role' => 'caregiver', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Caregiver::create(['user_id' => $user->id])];
    }
}
