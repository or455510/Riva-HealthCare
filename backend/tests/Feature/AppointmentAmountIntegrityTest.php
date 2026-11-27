<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * SECURITY (Phase 0 / mass-assignment audit) regression coverage: an appointment's
 * price must always come from Doctor::fee, never from client input, either at
 * booking time or through the generic update endpoint. See the docblocks on
 * AppointmentController::store()/update() for the full finding.
 */
class AppointmentAmountIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_amount_is_derived_from_doctor_fee_not_client_input(): void
    {
        [$patientUser] = $this->makePatient();
        [, $doctor] = $this->makeDoctor(250.00);
        Sanctum::actingAs($patientUser);

        $response = $this->postJson('/api/appointments', [
            'doctor_id' => $doctor->id,
            'appointment_date' => now()->addDay()->toDateString(),
            'appointment_time' => '10:30',
            'type' => 'online',
            'amount' => 1, // attacker-controlled: tries to pay 1 instead of the real 250 fee
            'notes' => 'Routine follow-up',
        ])->assertCreated();

        $appointment = Appointment::findOrFail($response->json('data.id'));
        $this->assertEquals(250.00, (float) $appointment->amount);
    }

    public function test_patient_cannot_change_amount_via_generic_update(): void
    {
        [$patientUser] = $this->makePatient();
        [, $doctor] = $this->makeDoctor(300.00);
        Sanctum::actingAs($patientUser);

        $appointment = $this->createAppointment($doctor->id);
        $this->assertEquals(300.00, (float) $appointment->amount);

        $this->putJson("/api/appointments/{$appointment->id}", [
            'amount' => 1,
            'notes' => 'Trying to reprice',
        ])->assertOk();

        $this->assertEquals(300.00, (float) $appointment->fresh()->amount);
    }

    public function test_doctor_cannot_change_amount_via_generic_update(): void
    {
        [$patientUser] = $this->makePatient();
        [$doctorUser, $doctor] = $this->makeDoctor(300.00);
        Sanctum::actingAs($patientUser);
        $appointment = $this->createAppointment($doctor->id);

        Sanctum::actingAs($doctorUser);
        $this->putJson("/api/appointments/{$appointment->id}", [
            'amount' => 0,
        ])->assertOk();

        $this->assertEquals(300.00, (float) $appointment->fresh()->amount);
    }

    public function test_amount_cannot_be_changed_via_update_even_after_payment_is_recorded(): void
    {
        [$patientUser] = $this->makePatient();
        [, $doctor] = $this->makeDoctor(300.00);
        $admin = User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'name' => 'Admin User',
            'email' => uniqid('admin').'@riva.test', 'password' => 'password',
            'role' => 'admin', 'is_active' => true, 'profile_completed_at' => now(),
        ]);

        Sanctum::actingAs($patientUser);
        $appointment = $this->createAppointment($doctor->id);

        Sanctum::actingAs($admin);
        $this->postJson("/api/payments/{$appointment->id}/pay")->assertOk();
        $this->assertEquals('paid', $appointment->fresh()->payment_status);

        Sanctum::actingAs($patientUser);
        $this->putJson("/api/appointments/{$appointment->id}", ['amount' => 0.01])->assertOk();

        $this->assertEquals(300.00, (float) $appointment->fresh()->amount);
        $this->assertEquals('paid', $appointment->fresh()->payment_status);
    }

    private function createAppointment(int $doctorId): Appointment
    {
        $response = $this->postJson('/api/appointments', [
            'doctor_id' => $doctorId,
            'appointment_date' => now()->addDay()->toDateString(),
            'appointment_time' => '10:30',
            'type' => 'online',
            'notes' => 'Routine follow-up',
        ]);

        $response->assertCreated();

        return Appointment::findOrFail($response->json('data.id'));
    }

    private function makePatient(): array
    {
        $user = User::create([
            'first_name' => 'Patient', 'last_name' => uniqid('Amt'),
            'name' => 'Patient Amt', 'email' => uniqid('patient').'@riva.test',
            'password' => 'password', 'role' => 'patient', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Patient::create(['user_id' => $user->id])];
    }

    private function makeDoctor(float $fee): array
    {
        $user = User::create([
            'first_name' => 'Doctor', 'last_name' => uniqid('Amt'),
            'name' => 'Doctor Amt', 'email' => uniqid('doctor').'@riva.test',
            'password' => 'password', 'role' => 'doctor', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Doctor::create(['user_id' => $user->id, 'fee' => $fee])];
    }
}
