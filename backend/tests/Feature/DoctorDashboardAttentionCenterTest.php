<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentBookingLog;
use App\Models\DailyStatus;
use App\Models\Doctor;
use App\Models\EmergencyAlert;
use App\Models\Patient;
use App\Models\PatientDoctorRelationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Doctor ERP Dashboard spec (DOCTOR_ERP_DASHBOARD_SPEC.md §10.5/§10.6/§10.9,
 * gaps #3 and #4): proves the three Attention Center / Activity additions
 * this implementation phase closed —
 *
 *  1. `active_emergency_alerts` is real, relationship-scoped (never
 *     clinic-membership-scoped — see CrossClinicResourceIsolationTest's
 *     documented rule that clinic co-membership is never sufficient
 *     authorization for medical data), and never leaks another doctor's
 *     patient's alert.
 *  2. `recent_daily_statuses` surfaces each patient's most recent status
 *     ONCE, non-stable-first, instead of the previous raw "first 10 of the
 *     latest 50 rows" behavior that could let one chatty stable patient
 *     crowd out a genuinely concerning one.
 *  3. `recent_bookings` reads the one real activity-log table that exists
 *     (`AppointmentBookingLog`, action='created' only) and scopes it to the
 *     calling doctor.
 */
class DoctorDashboardAttentionCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_emergency_alerts_are_scoped_to_the_doctors_own_related_patients(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();
        [, $otherDoctor] = $this->makeDoctor();
        $myPatient = $this->makePatientRecord();
        $otherDoctorsPatient = $this->makePatientRecord();
        $unrelatedPatient = $this->makePatientRecord();

        $this->linkDoctorToPatient($doctor, $myPatient);
        $this->linkDoctorToPatient($otherDoctor, $otherDoctorsPatient);
        // No relationship at all links $doctor to $unrelatedPatient.

        EmergencyAlert::create(['patient_id' => $myPatient->id, 'severity' => 'high', 'status' => 'active', 'message' => 'Severe chest pain']);
        EmergencyAlert::create(['patient_id' => $otherDoctorsPatient->id, 'severity' => 'high', 'status' => 'active', 'message' => 'Other doctor patient alert']);
        EmergencyAlert::create(['patient_id' => $unrelatedPatient->id, 'severity' => 'high', 'status' => 'active', 'message' => 'Unrelated patient alert']);
        // A resolved alert on my own patient must not count as "active".
        EmergencyAlert::create(['patient_id' => $myPatient->id, 'severity' => 'high', 'status' => 'resolved', 'message' => 'Old, resolved']);

        Sanctum::actingAs($doctorUser);
        $data = $this->getJson('/api/dashboard/doctor')->assertOk()->json('data');

        $this->assertSame(1, $data['active_emergency_alerts']['count']);
        $this->assertCount(1, $data['active_emergency_alerts']['items']);
        $this->assertSame($myPatient->id, $data['active_emergency_alerts']['items'][0]['patient_id']);
        $this->assertSame('Severe chest pain', $data['active_emergency_alerts']['items'][0]['message']);
    }

    public function test_a_doctor_with_no_related_patients_gets_an_empty_alerts_summary_not_an_error(): void
    {
        [$doctorUser] = $this->makeDoctor();

        Sanctum::actingAs($doctorUser);
        $data = $this->getJson('/api/dashboard/doctor')->assertOk()->json('data');

        $this->assertSame(0, $data['active_emergency_alerts']['count']);
        $this->assertSame([], $data['active_emergency_alerts']['items']);
    }

    public function test_recent_daily_statuses_surfaces_each_patient_once_with_non_stable_first(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();
        $stablePatient = $this->makePatientRecord();
        $highRiskPatient = $this->makePatientRecord();
        $this->linkDoctorToPatient($doctor, $stablePatient);
        $this->linkDoctorToPatient($doctor, $highRiskPatient);

        // The stable patient checked in twice (more recently than the high-risk
        // patient) — under the old "first 10 of latest 50" behavior both of
        // these rows could crowd out the high-risk patient's single, older row.
        DailyStatus::create(['patient_id' => $stablePatient->id, 'risk_level' => 'stable', 'date' => today()]);
        DailyStatus::create(['patient_id' => $stablePatient->id, 'risk_level' => 'stable', 'date' => today()->subDay()]);
        DailyStatus::create(['patient_id' => $highRiskPatient->id, 'risk_level' => 'high_risk', 'date' => today()->subDays(2)]);

        Sanctum::actingAs($doctorUser);
        $data = $this->getJson('/api/dashboard/doctor')->assertOk()->json('data');

        $statuses = $data['recent_daily_statuses'];
        $this->assertCount(2, $statuses, 'One row per patient, not one row per submission.');
        $this->assertSame('high_risk', $statuses[0]['risk_level'], 'The high-risk patient must be first despite being chronologically older.');
        $this->assertSame($highRiskPatient->id, $statuses[0]['patient_id']);
        $this->assertSame('stable', $statuses[1]['risk_level']);
    }

    public function test_recent_bookings_are_scoped_to_the_doctors_own_appointments(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();
        [, $otherDoctor] = $this->makeDoctor();
        $patient = $this->makePatientRecord();

        $myAppointment = $this->makeAppointment($doctor, $patient);
        AppointmentBookingLog::create(['appointment_id' => $myAppointment->id, 'user_id' => $patient->user_id, 'action' => 'created']);

        $otherAppointment = $this->makeAppointment($otherDoctor, $patient);
        AppointmentBookingLog::create(['appointment_id' => $otherAppointment->id, 'user_id' => $patient->user_id, 'action' => 'created']);

        Sanctum::actingAs($doctorUser);
        $data = $this->getJson('/api/dashboard/doctor')->assertOk()->json('data');

        $this->assertCount(1, $data['recent_bookings']);
        $this->assertSame($myAppointment->id, $data['recent_bookings'][0]['appointment_id']);
        $this->assertSame('created', $data['recent_bookings'][0]['action']);
    }

    /**
     * Phase 4 reconciliation (DOCTOR_ERP_DASHBOARD_SPEC.md security
     * checklist §5): the route's `role:doctor` middleware
     * (RoleMiddleware, routes/api.php) was never directly asserted against
     * this specific endpoint by an existing test — every other case here
     * proves data scoping, not the role boundary itself. This closes that
     * gap without touching the middleware, which was already correct.
     */
    public function test_a_non_doctor_cannot_access_the_doctor_dashboard(): void
    {
        $patientUser = User::create([
            'first_name' => 'Not', 'last_name' => 'ADoctor',
            'name' => 'Not ADoctor', 'email' => uniqid('notdoctor').'@riva.test',
            'password' => 'password', 'role' => 'patient', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        Sanctum::actingAs($patientUser);
        $this->getJson('/api/dashboard/doctor')->assertStatus(403);
    }

    public function test_an_unauthenticated_request_cannot_access_the_doctor_dashboard(): void
    {
        $this->getJson('/api/dashboard/doctor')->assertStatus(401);
    }

    private function makeAppointment(Doctor $doctor, Patient $patient): Appointment
    {
        return Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => today()->toDateString(),
            'appointment_time' => '10:00',
            'type' => 'offline',
            'amount' => 100,
            'payment_status' => 'unpaid',
            'status' => 'pending',
        ]);
    }

    private function linkDoctorToPatient(Doctor $doctor, Patient $patient): void
    {
        PatientDoctorRelationship::create([
            'doctor_id' => $doctor->id, 'patient_id' => $patient->id, 'status' => 'active',
        ]);
    }

    private function makePatientRecord(): Patient
    {
        $user = User::create([
            'first_name' => 'Patient', 'last_name' => uniqid('Attn'),
            'name' => 'Patient Attn', 'email' => uniqid('patient').'@riva.test',
            'password' => 'password', 'role' => 'patient', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return Patient::create(['user_id' => $user->id]);
    }

    private function makeDoctor(): array
    {
        $user = User::create([
            'first_name' => 'Doctor', 'last_name' => uniqid('Attn'),
            'name' => 'Doctor Attn', 'email' => uniqid('doctor').'@riva.test',
            'password' => 'password', 'role' => 'doctor', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Doctor::create(['user_id' => $user->id, 'fee' => 300])];
    }
}
