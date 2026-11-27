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
 * RIVA-130 follow-up (product-owner request, recorded 2026-08-25): "what's
 * the benefit of browsing every report I've ever written — open the
 * patient's profile and see the reports written for THEM there... keep any
 * report a patient shared with another doctor physically separate, isolated
 * between my reports and any other doctor's reports."
 *
 * GET /patients/{patient}/reports (report.component.ts, reached from the
 * patient's own profile) previously returned $patient->reports() completely
 * unfiltered by author — any doctor with an active relationship to a
 * patient could read every OTHER doctor's reports about that same patient,
 * a real cross-doctor privacy leak this request explicitly asked to close.
 * It's now scoped to the calling doctor's own-authored reports only; a
 * report another doctor wrote lives exclusively at GET /reports/shared-
 * with-me once the patient explicitly shares it — never mixed into this
 * per-patient view.
 */
class PatientReportsIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_doctor_only_sees_their_own_authored_reports_on_a_shared_patient(): void
    {
        [, $patient] = $this->makePatient();
        [$doctorAUser, $doctorA] = $this->makeDoctor();
        [, $doctorB] = $this->makeDoctor();
        $this->linkDoctorToPatient($doctorA, $patient);
        $this->linkDoctorToPatient($doctorB, $patient);

        $ownReport = $this->makeReport($patient, $doctorA, 'Written by A');
        $otherDoctorsReport = $this->makeReport($patient, $doctorB, 'Written by B');

        Sanctum::actingAs($doctorAUser);
        $response = $this->getJson("/api/patients/{$patient->id}/reports")->assertOk();
        $ids = collect($response->json('data.reports'))->pluck('id')->all();

        $this->assertContains($ownReport->id, $ids);
        $this->assertNotContains($otherDoctorsReport->id, $ids, 'Doctor A must never see a report Doctor B wrote for the same patient.');
    }

    public function test_an_explicitly_shared_report_still_does_not_leak_into_the_per_patient_view(): void
    {
        [, $patient] = $this->makePatient();
        [$doctorAUser, $doctorA] = $this->makeDoctor();
        [, $doctorB] = $this->makeDoctor();
        $this->linkDoctorToPatient($doctorA, $patient);
        $this->linkDoctorToPatient($doctorB, $patient);

        $sharedReport = $this->makeReport($patient, $doctorB, 'Shared With A');
        ReportShare::create(['report_id' => $sharedReport->id, 'doctor_id' => $doctorA->id]);

        Sanctum::actingAs($doctorAUser);
        $response = $this->getJson("/api/patients/{$patient->id}/reports")->assertOk();
        $ids = collect($response->json('data.reports'))->pluck('id')->all();

        $this->assertNotContains(
            $sharedReport->id,
            $ids,
            'A shared report belongs only in GET /reports/shared-with-me, kept physically separate from this doctor\'s own-authored reports.'
        );
    }

    public function test_shared_with_me_returns_only_reports_explicitly_shared_with_this_doctor(): void
    {
        [, $patientOne] = $this->makePatient();
        [, $patientTwo] = $this->makePatient();
        [$doctorAUser, $doctorA] = $this->makeDoctor();
        [, $doctorB] = $this->makeDoctor();

        $sharedReport = $this->makeReport($patientOne, $doctorB, 'Shared Report');
        $unsharedReport = $this->makeReport($patientTwo, $doctorB, 'Never Shared');
        ReportShare::create(['report_id' => $sharedReport->id, 'doctor_id' => $doctorA->id]);

        Sanctum::actingAs($doctorAUser);
        $response = $this->getJson('/api/reports/shared-with-me')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($sharedReport->id, $ids);
        $this->assertNotContains($unsharedReport->id, $ids);
    }

    public function test_a_patient_cannot_call_shared_with_me(): void
    {
        [$patientUser] = $this->makePatient();
        Sanctum::actingAs($patientUser);

        $this->getJson('/api/reports/shared-with-me')->assertStatus(403);
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

    private function linkDoctorToPatient(Doctor $doctor, Patient $patient): void
    {
        PatientDoctorRelationship::create([
            'doctor_id' => $doctor->id, 'patient_id' => $patient->id, 'status' => 'active',
        ]);
    }

    private function makePatient(): array
    {
        $user = User::create([
            'first_name' => 'Patient', 'last_name' => uniqid('Iso'),
            'name' => 'Patient Iso', 'email' => uniqid('patient').'@riva.test',
            'password' => 'password', 'role' => 'patient', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Patient::create(['user_id' => $user->id])];
    }

    private function makeDoctor(): array
    {
        $user = User::create([
            'first_name' => 'Doctor', 'last_name' => uniqid('Iso'),
            'name' => 'Doctor Iso', 'email' => uniqid('doctor').'@riva.test',
            'password' => 'password', 'role' => 'doctor', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Doctor::create(['user_id' => $user->id, 'fee' => 300])];
    }
}
