<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientDoctorRelationship;
use App\Models\Report;
use App\Models\ReportShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * RIVA-142: the patient-controlled cross-doctor report-sharing feature.
 * A patient may let a SPECIFIC doctor other than the one who wrote a report
 * see that one report, without granting that doctor any other access to the
 * patient's records — proven here by also checking an un-shared report from
 * the same patient stays invisible to the shared-with doctor.
 */
class ReportSharingTest extends TestCase
{
    use RefreshDatabase;

    public function test_patient_can_share_their_report_with_another_doctor(): void
    {
        [$patientUser, $patient] = $this->makePatient();
        [, $authorDoctor] = $this->makeDoctor();
        [$otherDoctorUser, $otherDoctor] = $this->makeDoctor();
        $report = $this->makeReport($patient, $authorDoctor);

        Sanctum::actingAs($patientUser);
        $this->postJson("/api/reports/{$report->id}/share", ['doctor_id' => $otherDoctor->id])
            ->assertCreated();

        $this->assertDatabaseHas('report_shares', [
            'report_id' => $report->id,
            'doctor_id' => $otherDoctor->id,
        ]);

        Sanctum::actingAs($otherDoctorUser);
        $this->getJson("/api/reports/{$report->id}")
            ->assertOk()
            ->assertJsonPath('data.is_shared_with_me', true);
    }

    public function test_shared_report_appears_in_the_receiving_doctors_report_list(): void
    {
        [$patientUser, $patient] = $this->makePatient();
        [, $authorDoctor] = $this->makeDoctor();
        [$otherDoctorUser, $otherDoctor] = $this->makeDoctor();
        $sharedReport = $this->makeReport($patient, $authorDoctor, 'Shared Report');
        $unsharedReport = $this->makeReport($patient, $authorDoctor, 'Never Shared Report');

        Sanctum::actingAs($patientUser);
        $this->postJson("/api/reports/{$sharedReport->id}/share", ['doctor_id' => $otherDoctor->id])
            ->assertCreated();

        Sanctum::actingAs($otherDoctorUser);
        $response = $this->getJson('/api/reports')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($sharedReport->id, $ids, 'The explicitly shared report must be visible.');
        $this->assertNotContains($unsharedReport->id, $ids, 'A DIFFERENT report by the same patient/doctor must stay invisible — sharing is per-report, not blanket access.');
    }

    public function test_unrelated_doctor_without_a_share_still_cannot_see_the_report(): void
    {
        [, $patient] = $this->makePatient();
        [, $authorDoctor] = $this->makeDoctor();
        [$strangerDoctorUser] = $this->makeDoctor();
        $report = $this->makeReport($patient, $authorDoctor);

        Sanctum::actingAs($strangerDoctorUser);
        $this->getJson("/api/reports/{$report->id}")->assertForbidden();
    }

    public function test_patient_cannot_share_a_report_they_do_not_own(): void
    {
        [, $patient] = $this->makePatient();
        [$otherPatientUser] = $this->makePatient();
        [, $authorDoctor] = $this->makeDoctor();
        [, $otherDoctor] = $this->makeDoctor();
        $report = $this->makeReport($patient, $authorDoctor);

        Sanctum::actingAs($otherPatientUser);
        $this->postJson("/api/reports/{$report->id}/share", ['doctor_id' => $otherDoctor->id])
            ->assertForbidden();
    }

    public function test_a_doctor_cannot_call_the_share_endpoint(): void
    {
        [, $patient] = $this->makePatient();
        [$authorDoctorUser, $authorDoctor] = $this->makeDoctor();
        [, $otherDoctor] = $this->makeDoctor();
        $report = $this->makeReport($patient, $authorDoctor);

        Sanctum::actingAs($authorDoctorUser);
        $this->postJson("/api/reports/{$report->id}/share", ['doctor_id' => $otherDoctor->id])
            ->assertForbidden();
    }

    public function test_sharing_with_the_reports_own_author_is_rejected_as_redundant(): void
    {
        [$patientUser, $patient] = $this->makePatient();
        [, $authorDoctor] = $this->makeDoctor();
        $report = $this->makeReport($patient, $authorDoctor);

        Sanctum::actingAs($patientUser);
        $this->postJson("/api/reports/{$report->id}/share", ['doctor_id' => $authorDoctor->id])
            ->assertStatus(422);
    }

    public function test_patient_can_revoke_a_previously_shared_report(): void
    {
        [$patientUser, $patient] = $this->makePatient();
        [, $authorDoctor] = $this->makeDoctor();
        [$otherDoctorUser, $otherDoctor] = $this->makeDoctor();
        $report = $this->makeReport($patient, $authorDoctor);
        ReportShare::create(['report_id' => $report->id, 'doctor_id' => $otherDoctor->id]);

        Sanctum::actingAs($patientUser);
        $this->deleteJson("/api/reports/{$report->id}/share/{$otherDoctor->id}")->assertOk();

        $this->assertDatabaseMissing('report_shares', [
            'report_id' => $report->id,
            'doctor_id' => $otherDoctor->id,
        ]);

        Sanctum::actingAs($otherDoctorUser);
        $this->getJson("/api/reports/{$report->id}")->assertForbidden();
    }

    public function test_owning_patient_sees_the_shared_with_list_on_the_report(): void
    {
        [$patientUser, $patient] = $this->makePatient();
        [, $authorDoctor] = $this->makeDoctor();
        [, $otherDoctor] = $this->makeDoctor();
        $report = $this->makeReport($patient, $authorDoctor);
        ReportShare::create(['report_id' => $report->id, 'doctor_id' => $otherDoctor->id]);

        Sanctum::actingAs($patientUser);
        $response = $this->getJson("/api/reports/{$report->id}")->assertOk();

        $sharedIds = collect($response->json('data.shared_with'))->pluck('doctor_id')->all();
        $this->assertContains($otherDoctor->id, $sharedIds);
    }

    public function test_a_doctor_viewing_the_report_does_not_receive_the_shared_with_list(): void
    {
        [, $patient] = $this->makePatient();
        [$authorDoctorUser, $authorDoctor] = $this->makeDoctor();
        PatientDoctorRelationship::create([
            'doctor_id' => $authorDoctor->id, 'patient_id' => $patient->id, 'status' => 'active',
        ]);
        $report = $this->makeReport($patient, $authorDoctor);

        Sanctum::actingAs($authorDoctorUser);
        $response = $this->getJson("/api/reports/{$report->id}")->assertOk();

        $this->assertArrayNotHasKey('shared_with', $response->json('data'));
    }

    private function makeReport(Patient $patient, Doctor $doctor, string $title = 'Test Report'): Report
    {
        return Report::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'title' => $title,
            'summary' => 'Summary',
            'final_report' => 'Final report content',
        ]);
    }

    private function makePatient(): array
    {
        $user = User::create([
            'first_name' => 'Patient', 'last_name' => uniqid('Share'),
            'name' => 'Patient Share', 'email' => uniqid('patient').'@riva.test',
            'password' => 'password', 'role' => 'patient', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Patient::create(['user_id' => $user->id])];
    }

    private function makeDoctor(): array
    {
        $user = User::create([
            'first_name' => 'Doctor', 'last_name' => uniqid('Share'),
            'name' => 'Doctor Share', 'email' => uniqid('doctor').'@riva.test',
            'password' => 'password', 'role' => 'doctor', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Doctor::create(['user_id' => $user->id, 'fee' => 300])];
    }
}
