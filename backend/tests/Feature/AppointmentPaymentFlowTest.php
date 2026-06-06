<?php

namespace Tests\Feature;

use App\Mail\GenericNotificationMail;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

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

    public function test_patient_payment_confirms_appointment_and_notifies_both_sides(): void
    {
        Mail::fake();
        [$patientUser] = $this->makePatient();
        [$doctorUser, $doctor] = $this->makeDoctor();
        Sanctum::actingAs($patientUser);

        $appointment = $this->createAppointment($doctor->id);

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
        Mail::assertSent(GenericNotificationMail::class, 2);
    }

    public function test_non_owner_cannot_pay_and_duplicate_payment_is_blocked(): void
    {
        [$patientUser] = $this->makePatient();
        [$doctorUser, $doctor] = $this->makeDoctor();
        Sanctum::actingAs($patientUser);
        $appointment = $this->createAppointment($doctor->id);

        Sanctum::actingAs($doctorUser);
        $this->postJson("/api/payments/{$appointment->id}/pay")
            ->assertForbidden()
            ->assertJsonPath('message', 'Only the patient who owns this appointment can pay for it.');

        Sanctum::actingAs($patientUser);
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

        $this->postJson("/api/payments/{$appointment->id}/fail")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.payment_status', 'failed');
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
            'email' => uniqid('patient') . '@riva.test',
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
            'email' => uniqid('doctor') . '@riva.test',
            'password' => 'password',
            'role' => 'doctor',
            'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Doctor::create(['user_id' => $user->id])];
    }
}
