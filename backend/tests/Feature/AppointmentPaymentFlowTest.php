<?php

namespace Tests\Feature;

use App\Mail\GenericNotificationMail;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicUser;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Updated for Phase 0: the payment endpoints no longer trust the client (see
 * AppointmentController::pay()/failPayment()). Patients and doctors can no
 * longer self-declare a payment successful.
 *
 * Updated again for Phase C (Queue slice, principal-engineering directive):
 * the interim "only a platform admin" restriction has been superseded now
 * that Phase 2 (clinic RBAC) actually exists — see
 * AppointmentController::canRecordPayment()'s docblock. A clinic
 * owner/admin, or a staff member explicitly granted
 * ClinicUser::PERMISSION_RECORD_PAYMENTS, in the SAME clinic as the
 * appointment's doctor, can now record a payment too. A patient, an
 * unaffiliated doctor, or a staff member without that specific grant still
 * cannot — see the tests below.
 */
class AppointmentPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_appointment_starts_pending_payment_and_cannot_be_confirmed_before_payment(): void
    {
        [$patientUser] = $this->makePatient();
        [, $doctor] = $this->makeDoctor();
        Sanctum::actingAs($patientUser);

        $appointment = $this->createAppointment($doctor->id);

        $this->assertSame('pending_payment', $appointment->status);
        $this->assertSame('unpaid', $appointment->payment_status);

        $this->postJson("/api/appointments/{$appointment->id}/confirm")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Payment must be completed before confirming this appointment.');
    }

    public function test_admin_recorded_payment_confirms_appointment_and_notifies_both_sides(): void
    {
        Mail::fake();
        [$patientUser] = $this->makePatient();
        [$doctorUser, $doctor] = $this->makeDoctor();
        Sanctum::actingAs($patientUser);

        $appointment = $this->createAppointment($doctor->id);

        Sanctum::actingAs($this->makeAdmin());
        $this->postJson("/api/payments/{$appointment->id}/pay")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.payment_status', 'paid');

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'status' => 'confirmed',
            'payment_status' => 'paid',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $doctorUser->id,
            'type' => 'appointment_confirmed',
            'category' => 'appointment',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $patientUser->id,
            'type' => 'payment_success',
            'category' => 'appointment',
        ]);
        // RIVA-102: NotificationService::sendEmail() now queues instead of
        // sending synchronously (see docs/RIVA_DECISIONS.md) — MailFake
        // tracks queued mail separately from sent mail, so this asserts the
        // new, intentional behavior rather than the old blocking one.
        Mail::assertQueued(GenericNotificationMail::class, 2);

        // Payment architecture (Slice 12): a successful pay() must leave a real,
        // auditable Payment row behind — not just flip appointments.payment_status.
        $this->assertDatabaseHas('payments', [
            'appointment_id' => $appointment->id,
            'amount' => number_format($appointment->amount, 2, '.', ''),
            'status' => 'succeeded',
            'gateway' => 'manual',
        ]);
    }

    public function test_payment_status_endpoint_includes_the_transaction_audit_trail(): void
    {
        [$patientUser] = $this->makePatient();
        [, $doctor] = $this->makeDoctor();
        Sanctum::actingAs($patientUser);
        $appointment = $this->createAppointment($doctor->id);

        Sanctum::actingAs($this->makeAdmin());
        $this->postJson("/api/payments/{$appointment->id}/pay")->assertOk();

        Sanctum::actingAs($patientUser);
        $this->getJson("/api/payments/{$appointment->id}")
            ->assertOk()
            ->assertJsonPath('data.payments.0.status', 'succeeded')
            ->assertJsonPath('data.payments.0.gateway', 'manual')
            ->assertJsonCount(1, 'data.payments');
    }

    public function test_failed_payment_also_leaves_an_audit_trail_row(): void
    {
        [$patientUser] = $this->makePatient();
        [, $doctor] = $this->makeDoctor();
        Sanctum::actingAs($patientUser);
        $appointment = $this->createAppointment($doctor->id);

        Sanctum::actingAs($this->makeAdmin());
        $this->postJson("/api/payments/{$appointment->id}/fail")->assertOk();

        $this->assertDatabaseHas('payments', [
            'appointment_id' => $appointment->id,
            'status' => 'failed',
        ]);
    }

    public function test_patient_can_no_longer_self_declare_payment(): void
    {
        [$patientUser] = $this->makePatient();
        [, $doctor] = $this->makeDoctor();
        Sanctum::actingAs($patientUser);
        $appointment = $this->createAppointment($doctor->id);

        $this->postJson("/api/payments/{$appointment->id}/pay")
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have permission to record a payment for this appointment.');
    }

    public function test_doctor_cannot_declare_payment_either(): void
    {
        [$patientUser] = $this->makePatient();
        [$doctorUser, $doctor] = $this->makeDoctor();
        Sanctum::actingAs($patientUser);
        $appointment = $this->createAppointment($doctor->id);

        Sanctum::actingAs($doctorUser);
        $this->postJson("/api/payments/{$appointment->id}/pay")->assertForbidden();
    }

    public function test_clinic_owner_can_record_payment_for_their_own_clinic_doctor(): void
    {
        [$patientUser] = $this->makePatient();
        [$doctorUser, $doctor] = $this->makeDoctorWithSoloClinic();
        Sanctum::actingAs($patientUser);
        $appointment = $this->createAppointment($doctor->id);

        Sanctum::actingAs($doctorUser);
        $this->postJson("/api/payments/{$appointment->id}/pay")
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid');
    }

    public function test_staff_member_with_payments_record_permission_can_record_payment(): void
    {
        [$patientUser] = $this->makePatient();
        [, $doctor, $clinic] = $this->makeDoctorWithSoloClinic();
        Sanctum::actingAs($patientUser);
        $appointment = $this->createAppointment($doctor->id);

        $staffUser = $this->makeStaffMember($clinic, [ClinicUser::PERMISSION_RECORD_PAYMENTS]);
        Sanctum::actingAs($staffUser);
        $this->postJson("/api/payments/{$appointment->id}/pay")
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid');
    }

    public function test_staff_member_without_payments_record_permission_cannot_record_payment(): void
    {
        [$patientUser] = $this->makePatient();
        [, $doctor, $clinic] = $this->makeDoctorWithSoloClinic();
        Sanctum::actingAs($patientUser);
        $appointment = $this->createAppointment($doctor->id);

        $staffUser = $this->makeStaffMember($clinic, [ClinicUser::PERMISSION_MANAGE_QUEUE]);
        Sanctum::actingAs($staffUser);
        $this->postJson("/api/payments/{$appointment->id}/pay")->assertForbidden();
    }

    public function test_duplicate_admin_payment_is_blocked(): void
    {
        [$patientUser] = $this->makePatient();
        [, $doctor] = $this->makeDoctor();
        Sanctum::actingAs($patientUser);
        $appointment = $this->createAppointment($doctor->id);

        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);
        $this->postJson("/api/payments/{$appointment->id}/pay")->assertOk();
        $this->postJson("/api/payments/{$appointment->id}/pay")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This appointment is already paid.');
    }

    public function test_failed_payment_keeps_appointment_pending_payment(): void
    {
        [$patientUser] = $this->makePatient();
        [, $doctor] = $this->makeDoctor();
        Sanctum::actingAs($patientUser);
        $appointment = $this->createAppointment($doctor->id);

        Sanctum::actingAs($this->makeAdmin());
        $this->postJson("/api/payments/{$appointment->id}/fail")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.payment_status', 'failed');
    }

    public function test_patient_cannot_bypass_payment_gate_via_generic_update(): void
    {
        // SECURITY (Phase 0) regression test: PUT /appointments/{id} with a 'status'
        // field used to let the owning patient set status=confirmed directly,
        // skipping the payment_status check that confirm() enforces.
        [$patientUser] = $this->makePatient();
        [, $doctor] = $this->makeDoctor();
        Sanctum::actingAs($patientUser);
        $appointment = $this->createAppointment($doctor->id);

        $this->putJson("/api/appointments/{$appointment->id}", ['status' => 'confirmed'])
            ->assertOk();

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'status' => 'pending_payment', // unchanged: 'status' was silently ignored for a non-admin
            'payment_status' => 'unpaid',
        ]);
    }

    private function createAppointment(int $doctorId): Appointment
    {
        $response = $this->postJson('/api/appointments', [
            'doctor_id' => $doctorId,
            'appointment_date' => now()->addDay()->toDateString(),
            'appointment_time' => '10:30',
            'type' => 'online',
            'amount' => 300,
            'notes' => 'Routine follow-up',
        ]);

        $response->assertCreated();

        return Appointment::findOrFail($response->json('data.id'));
    }

    private function makePatient(): array
    {
        $user = User::create([
            'first_name' => 'Patient',
            'last_name' => uniqid('Pay'),
            'name' => 'Patient Pay',
            'email' => uniqid('patient').'@riva.test',
            'password' => 'password',
            'role' => 'patient',
            'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Patient::create(['user_id' => $user->id])];
    }

    private function makeDoctor(): array
    {
        $user = User::create([
            'first_name' => 'Doctor',
            'last_name' => uniqid('Pay'),
            'name' => 'Doctor Pay',
            'email' => uniqid('doctor').'@riva.test',
            'password' => 'password',
            'role' => 'doctor',
            'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Doctor::create(['user_id' => $user->id])];
    }

    /** @return array{0: User, 1: Doctor, 2: Clinic} */
    private function makeDoctorWithSoloClinic(): array
    {
        [$doctorUser, $doctor] = $this->makeDoctor();

        $clinic = Clinic::create([
            'name' => "{$doctorUser->name}'s Practice",
            'slug' => 'pay-test-clinic-'.uniqid(),
            'owner_user_id' => $doctorUser->id,
            'status' => 'active',
        ]);
        ClinicUser::create([
            'clinic_id' => $clinic->id,
            'user_id' => $doctorUser->id,
            'role' => ClinicUser::ROLE_OWNER,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$doctorUser, $doctor, $clinic];
    }

    private function makeStaffMember(Clinic $clinic, array $permissions): User
    {
        $staffUser = User::create([
            'first_name' => 'Staff',
            'last_name' => uniqid('Pay'),
            'name' => 'Staff Pay',
            'email' => uniqid('staff').'@riva.test',
            'password' => 'password',
            'role' => 'patient',
            'is_active' => true,
            'profile_completed_at' => now(),
        ]);

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

    private function makeAdmin(): User
    {
        return User::create([
            'first_name' => 'Admin',
            'last_name' => uniqid('Pay'),
            'name' => 'Admin Pay',
            'email' => uniqid('admin').'@riva.test',
            'password' => 'password',
            'role' => 'admin',
            'is_active' => true,
            'profile_completed_at' => now(),
        ]);
    }
}
