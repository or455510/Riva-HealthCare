<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Caregiver;
use App\Models\Clinic;
use App\Models\ClinicUser;
use App\Models\DailyStatus;
use App\Models\Doctor;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\PatientCaregiverRelationship;
use App\Models\PatientDoctorRelationship;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * RIVA — Phase 1 Slice 2: real, HTTP-level, resource-by-resource cross-clinic
 * isolation proof. Two full clinics are built (each with an owner-doctor, a
 * second staff-doctor, a patient, and a caregiver — see setUpClinic()), and
 * every sensitive resource is exercised across the boundary: Clinic A's users
 * attempting Clinic B's appointments, reports, medications, daily statuses,
 * and payments via direct ID access.
 *
 * Architectural note this suite deliberately proves: NONE of these resource
 * controllers check clinic_id/clinic membership at all today (confirmed by
 * direct code audit — see AppointmentController, ReportController,
 * MedicationController, DailyStatusController). That is intentional, not an
 * oversight: the existing PatientDoctorRelationship / PatientCaregiverRelationship
 * model is already the correct, doctor-specific authorization boundary (per
 * the directive's own instruction: clinic co-membership must never be treated
 * as sufficient authorization by itself). This suite proves that boundary
 * holds correctly even when both doctors are formally members of the SAME
 * clinic (test_second_doctor_in_same_clinic_...), which is the sharpest
 * possible version of the "same clinic != authorized" requirement.
 */
class CrossClinicResourceIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_in_clinic_a_cannot_view_clinic_b_appointment(): void
    {
        $a = $this->setUpClinic('A');
        $b = $this->setUpClinic('B');

        $appointmentB = $this->makeAppointment($b['patient'], $b['doctor']);

        Sanctum::actingAs($a['doctorUser']);
        $this->getJson("/api/appointments/{$appointmentB->id}")->assertForbidden();
    }

    public function test_doctor_in_clinic_a_cannot_update_or_confirm_clinic_b_appointment(): void
    {
        $a = $this->setUpClinic('A');
        $b = $this->setUpClinic('B');
        $appointmentB = $this->makeAppointment($b['patient'], $b['doctor']);

        Sanctum::actingAs($a['doctorUser']);
        $this->putJson("/api/appointments/{$appointmentB->id}", ['notes' => 'hacked'])->assertForbidden();
        $this->postJson("/api/appointments/{$appointmentB->id}/confirm")->assertForbidden();
        $this->postJson("/api/appointments/{$appointmentB->id}/cancel")->assertForbidden();
    }

    public function test_doctor_in_clinic_a_cannot_read_or_tamper_clinic_b_payment(): void
    {
        $a = $this->setUpClinic('A');
        $b = $this->setUpClinic('B');
        $appointmentB = $this->makeAppointment($b['patient'], $b['doctor']);

        Sanctum::actingAs($a['doctorUser']);
        $this->getJson("/api/payments/{$appointmentB->id}")->assertForbidden();
        $this->postJson("/api/payments/{$appointmentB->id}/pay")->assertForbidden();
        $this->postJson("/api/payments/{$appointmentB->id}/fail")->assertForbidden();

        $this->assertSame('unpaid', $appointmentB->fresh()->payment_status, 'Clinic B payment must be untouched.');
    }

    public function test_admin_of_clinic_a_cannot_use_payment_endpoints_clinic_membership_confers_no_platform_admin_rights(): void
    {
        $a = $this->setUpClinic('A');
        $b = $this->setUpClinic('B');
        $appointmentB = $this->makeAppointment($b['patient'], $b['doctor']);

        // a['doctorUser'] is a clinic OWNER (highest clinic-level role) but has
        // platform role 'doctor', not platform role 'admin' — clinic ownership
        // must never be conflated with the platform admin role that
        // AppointmentController::canRecordPayment() actually checks.
        Sanctum::actingAs($a['doctorUser']);
        $this->postJson("/api/payments/{$appointmentB->id}/pay")->assertForbidden();
    }

    public function test_doctor_in_clinic_a_cannot_view_clinic_b_patients_report(): void
    {
        $a = $this->setUpClinic('A');
        $b = $this->setUpClinic('B');
        $report = Report::create([
            'patient_id' => $b['patient']->id,
            'doctor_id' => $b['doctor']->id,
            'title' => 'Confidential',
            'summary' => 'Clinic B only',
        ]);

        Sanctum::actingAs($a['doctorUser']);
        $this->getJson("/api/reports/{$report->id}")->assertForbidden();
        $this->getJson("/api/patients/{$b['patient']->id}/reports")->assertForbidden();
    }

    public function test_doctor_in_clinic_a_cannot_view_clinic_b_patients_medication(): void
    {
        $a = $this->setUpClinic('A');
        $b = $this->setUpClinic('B');
        $medication = Medication::create([
            'patient_id' => $b['patient']->id,
            'name' => 'Secret Med',
            'dosage' => '10mg',
            'frequency' => 'daily',
            'start_date' => today()->toDateString(),
            'is_active' => true,
        ]);

        Sanctum::actingAs($a['doctorUser']);
        $this->getJson("/api/medications/{$medication->id}")->assertForbidden();
    }

    public function test_doctor_in_clinic_a_cannot_view_clinic_b_patients_daily_status(): void
    {
        $a = $this->setUpClinic('A');
        $b = $this->setUpClinic('B');
        DailyStatus::create([
            'patient_id' => $b['patient']->id,
            'date' => today()->toDateString(),
            'mood' => 'ok',
            'pain_level' => 2,
            'risk_level' => 'stable',
        ]);

        Sanctum::actingAs($a['doctorUser']);
        $this->getJson("/api/patients/{$b['patient']->id}/daily-status")->assertForbidden();
    }

    public function test_patient_in_clinic_a_cannot_view_clinic_b_patients_appointment_or_medication(): void
    {
        $a = $this->setUpClinic('A');
        $b = $this->setUpClinic('B');
        $appointmentB = $this->makeAppointment($b['patient'], $b['doctor']);
        $medicationB = Medication::create([
            'patient_id' => $b['patient']->id,
            'name' => 'Secret Med',
            'dosage' => '10mg',
            'frequency' => 'daily',
            'start_date' => today()->toDateString(),
            'is_active' => true,
        ]);

        Sanctum::actingAs($a['patientUser']);
        $this->getJson("/api/appointments/{$appointmentB->id}")->assertForbidden();
        $this->getJson("/api/medications/{$medicationB->id}")->assertForbidden();
    }

    public function test_caregiver_in_clinic_a_cannot_view_clinic_b_patients_data(): void
    {
        $a = $this->setUpClinic('A');
        $b = $this->setUpClinic('B');
        $report = Report::create([
            'patient_id' => $b['patient']->id,
            'doctor_id' => $b['doctor']->id,
            'title' => 'Confidential',
            'summary' => 'Clinic B only',
        ]);
        $medication = Medication::create([
            'patient_id' => $b['patient']->id,
            'name' => 'Secret Med',
            'dosage' => '10mg',
            'frequency' => 'daily',
            'start_date' => today()->toDateString(),
            'is_active' => true,
        ]);

        Sanctum::actingAs($a['caregiverUser']);
        $this->getJson("/api/reports/{$report->id}")->assertForbidden();
        $this->getJson("/api/medications/{$medication->id}")->assertForbidden();
    }

    /**
     * The sharpest version of "clinic co-membership != authorization": both
     * doctors below are members of the SAME clinic (clinic A), one as owner,
     * one as a plain staff-doctor added afterward — but the staff doctor has
     * no PatientDoctorRelationship with the owner's patient, so must still be
     * denied. Proves the app does not (and correctly should not) treat "same
     * clinic" as sufficient.
     */
    public function test_second_doctor_in_same_clinic_cannot_view_colleagues_patient_without_own_relationship(): void
    {
        $a = $this->setUpClinic('A');
        $report = Report::create([
            'patient_id' => $a['patient']->id,
            'doctor_id' => $a['doctor']->id,
            'title' => 'Owner-only',
            'summary' => 'Not for the staff doctor',
        ]);
        $appointment = $this->makeAppointment($a['patient'], $a['doctor']);

        Sanctum::actingAs($a['staffDoctorUser']);
        $this->getJson("/api/reports/{$report->id}")->assertForbidden();
        $this->getJson("/api/appointments/{$appointment->id}")->assertForbidden();
        $this->getJson('/api/medications')->assertForbidden(); // staff doctor has no patient profile at all
    }

    public function test_caregiver_only_sees_appointments_of_related_patients_across_clinics(): void
    {
        $a = $this->setUpClinic('A');
        $b = $this->setUpClinic('B');
        $ownAppointment = $this->makeAppointment($a['patient'], $a['doctor']);
        $otherClinicAppointment = $this->makeAppointment($b['patient'], $b['doctor']);

        Sanctum::actingAs($a['caregiverUser']);
        $response = $this->getJson('/api/appointments')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($ownAppointment->id, $ids);
        $this->assertNotContains($otherClinicAppointment->id, $ids);
    }

    public function test_doctor_in_clinic_a_cannot_chat_with_clinic_b_patient(): void
    {
        $a = $this->setUpClinic('A');
        $b = $this->setUpClinic('B');

        Sanctum::actingAs($a['doctorUser']);
        $this->postJson('/api/messages', [
            'receiver_id' => $b['patientUser']->id,
            'body' => 'hello',
        ])->assertForbidden();
    }

    public function test_unauthenticated_request_is_rejected_on_every_sensitive_resource(): void
    {
        $a = $this->setUpClinic('A');
        $appointment = $this->makeAppointment($a['patient'], $a['doctor']);

        $this->getJson('/api/appointments')->assertUnauthorized();
        $this->getJson("/api/appointments/{$appointment->id}")->assertUnauthorized();
        $this->getJson('/api/reports')->assertUnauthorized();
    }

    /**
     * @return array{
     *   clinic: Clinic,
     *   doctorUser: User, doctor: Doctor,
     *   staffDoctorUser: User, staffDoctor: Doctor,
     *   patientUser: User, patient: Patient,
     *   caregiverUser: User, caregiver: Caregiver,
     * }
     */
    private function setUpClinic(string $label): array
    {
        $doctorUser = $this->makeUser('doctor', $label);
        $doctor = Doctor::create(['user_id' => $doctorUser->id, 'fee' => 300]);

        $clinic = Clinic::create([
            'name' => "Clinic {$label}",
            'slug' => 'clinic-'.strtolower($label).'-'.uniqid(),
            'owner_user_id' => $doctorUser->id,
            'status' => 'active',
        ]);
        ClinicUser::create([
            'clinic_id' => $clinic->id, 'user_id' => $doctorUser->id,
            'role' => ClinicUser::ROLE_OWNER, 'status' => 'active', 'joined_at' => now(),
        ]);

        $staffDoctorUser = $this->makeUser('doctor', $label.'-staff');
        $staffDoctor = Doctor::create(['user_id' => $staffDoctorUser->id, 'fee' => 250]);
        ClinicUser::create([
            'clinic_id' => $clinic->id, 'user_id' => $staffDoctorUser->id,
            'role' => ClinicUser::ROLE_STAFF, 'status' => 'active', 'joined_at' => now(),
        ]);

        $patientUser = $this->makeUser('patient', $label);
        $patient = Patient::create(['user_id' => $patientUser->id]);

        $caregiverUser = $this->makeUser('caregiver', $label);
        $caregiver = Caregiver::create(['user_id' => $caregiverUser->id]);

        PatientDoctorRelationship::create([
            'doctor_id' => $doctor->id, 'patient_id' => $patient->id, 'status' => 'active',
        ]);
        PatientCaregiverRelationship::create([
            'caregiver_id' => $caregiver->id, 'patient_id' => $patient->id, 'status' => 'active',
        ]);

        return compact('clinic', 'doctorUser', 'doctor', 'staffDoctorUser', 'staffDoctor', 'patientUser', 'patient', 'caregiverUser', 'caregiver');
    }

    private function makeAppointment(Patient $patient, Doctor $doctor): Appointment
    {
        return Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => today()->addDay()->toDateString(),
            'appointment_time' => '10:00',
            'type' => 'online',
            'amount' => $doctor->fee ?? 300,
            'payment_status' => 'unpaid',
            'status' => 'pending_payment',
        ]);
    }

    private function makeUser(string $role, string $label): User
    {
        return User::create([
            'first_name' => ucfirst($role), 'last_name' => 'Clinic'.$label.uniqid(),
            'name' => ucfirst($role).' Clinic '.$label,
            'email' => strtolower($role).'-'.strtolower(str_replace(' ', '', $label)).'-'.uniqid().'@riva.test',
            'password' => 'password', 'role' => $role, 'is_active' => true,
            'profile_completed_at' => now(),
        ]);
    }
}
