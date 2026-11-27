<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientDoctorRelationship;
use App\Models\Report;
use App\Models\User;
use App\Services\RecordAccessAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * SECURITY (Phase 0 / S5) regression coverage: RecordAccessAuditService and its call
 * sites in ReportController/MedicationController. This closes the test-coverage gap
 * flagged in the Phase 0 verification report — the audit-logging feature shipped
 * without any test asserting rows actually get written, or that a failure to write
 * one is truly fire-and-forget (never breaks the underlying read).
 */
class RecordAccessAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewing_a_report_writes_an_audit_row(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();
        [, $patient] = $this->makePatient();
        PatientDoctorRelationship::create([
            'doctor_id' => $doctor->id,
            'patient_id' => $patient->id,
            'status' => 'active',
        ]);
        $report = Report::create([
            'doctor_id' => $doctor->id,
            'patient_id' => $patient->id,
            'title' => 'Audit Report',
            'summary' => 'Patient is stable.',
        ]);

        Sanctum::actingAs($doctorUser);
        $this->getJson("/api/reports/{$report->id}")->assertOk();

        $this->assertDatabaseHas('record_access_logs', [
            'user_id' => $doctorUser->id,
            'actor_role' => 'doctor',
            'resource_type' => 'report',
            'resource_id' => $report->id,
            'patient_id' => $patient->id,
            'action' => 'view',
        ]);
    }

    public function test_viewing_a_patients_report_list_writes_an_audit_row(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();
        [, $patient] = $this->makePatient();
        PatientDoctorRelationship::create([
            'doctor_id' => $doctor->id,
            'patient_id' => $patient->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($doctorUser);
        $this->getJson("/api/patients/{$patient->id}/reports")->assertOk();

        $this->assertDatabaseHas('record_access_logs', [
            'user_id' => $doctorUser->id,
            'resource_type' => 'patient_reports_list',
            'resource_id' => $patient->id,
            'patient_id' => $patient->id,
            'action' => 'view',
        ]);
    }

    public function test_denied_report_access_writes_no_audit_row(): void
    {
        // An unrelated doctor is forbidden from viewing this report — the audit
        // call sits after authorizeReportAccess() in the controller, so a denied
        // request must not fabricate a "viewed" audit trail for access that never
        // actually happened.
        [$doctorUser] = $this->makeDoctor();
        [, $patient] = $this->makePatient();
        [, $otherDoctor] = $this->makeDoctor();
        $report = Report::create([
            'doctor_id' => $otherDoctor->id,
            'patient_id' => $patient->id,
            'title' => 'Private Report',
            'summary' => 'Not this doctor\'s patient.',
        ]);

        Sanctum::actingAs($doctorUser);
        $this->getJson("/api/reports/{$report->id}")->assertForbidden();

        $this->assertDatabaseCount('record_access_logs', 0);
    }

    public function test_audit_write_failure_never_breaks_the_underlying_request(): void
    {
        // Fire-and-forget guarantee (see RecordAccessAuditService docblock): drop the
        // table out from under the service to force a real QueryException on write,
        // and confirm the caller still gets a normal successful response rather than
        // a 500 — plus that the failure is logged rather than silently lost.
        Schema::drop('record_access_logs');

        [$doctorUser, $doctor] = $this->makeDoctor();
        [, $patient] = $this->makePatient();
        PatientDoctorRelationship::create([
            'doctor_id' => $doctor->id,
            'patient_id' => $patient->id,
            'status' => 'active',
        ]);
        $report = Report::create([
            'doctor_id' => $doctor->id,
            'patient_id' => $patient->id,
            'title' => 'Audit Report',
            'summary' => 'Patient is stable.',
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'failed to write audit log row'));

        Sanctum::actingAs($doctorUser);
        $this->getJson("/api/reports/{$report->id}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Audit Report');
    }

    public function test_service_directly_swallows_exceptions_and_logs_a_warning(): void
    {
        Schema::drop('record_access_logs');

        $user = User::create([
            'first_name' => 'Doctor', 'last_name' => uniqid('Audit'),
            'name' => 'Doctor Audit', 'email' => uniqid('doctor').'@riva.test',
            'password' => 'password', 'role' => 'doctor', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        Log::shouldReceive('warning')->once();

        // Must not throw despite the missing table.
        (new RecordAccessAuditService)->logView($user, 'report', 999, 1, '127.0.0.1');
        $this->assertTrue(true);
    }

    private function makePatient(): array
    {
        $user = User::create([
            'first_name' => 'Patient', 'last_name' => uniqid('Audit'),
            'name' => 'Patient Audit', 'email' => uniqid('patient').'@riva.test',
            'password' => 'password', 'role' => 'patient', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Patient::create(['user_id' => $user->id])];
    }

    private function makeDoctor(): array
    {
        $user = User::create([
            'first_name' => 'Doctor', 'last_name' => uniqid('Audit'),
            'name' => 'Doctor Audit', 'email' => uniqid('doctor').'@riva.test',
            'password' => 'password', 'role' => 'doctor', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Doctor::create(['user_id' => $user->id])];
    }
}
