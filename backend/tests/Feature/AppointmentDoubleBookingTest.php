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
 * CORRECTNESS (RIVA-116, P1) regression coverage for AppointmentController
 * ::store()'s double-booking guard. Before this fix, two different patients
 * could book the identical doctor_id/appointment_date/appointment_time —
 * even an already confirmed+paid slot — with no error, no warning, and no
 * conflict of any kind surfaced to either patient.
 */
class AppointmentDoubleBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_second_patient_cannot_book_the_same_doctor_slot_as_an_existing_pending_appointment(): void
    {
        [, $doctor] = $this->makeDoctor();
        [$firstPatientUser] = $this->makePatient();
        [$secondPatientUser] = $this->makePatient();

        Sanctum::actingAs($firstPatientUser);
        $this->postJson('/api/appointments', [
            'doctor_id' => $doctor->id,
            'appointment_date' => today()->addDay()->toDateString(),
            'appointment_time' => '10:00',
        ])->assertCreated();

        Sanctum::actingAs($secondPatientUser);
        $this->postJson('/api/appointments', [
            'doctor_id' => $doctor->id,
            'appointment_date' => today()->addDay()->toDateString(),
            'appointment_time' => '10:00',
        ])->assertStatus(409);

        $this->assertSame(1, Appointment::where('doctor_id', $doctor->id)->count());
    }

    public function test_a_different_time_slot_the_same_day_is_not_blocked(): void
    {
        [, $doctor] = $this->makeDoctor();
        [$firstPatientUser] = $this->makePatient();
        [$secondPatientUser] = $this->makePatient();

        Sanctum::actingAs($firstPatientUser);
        $this->postJson('/api/appointments', [
            'doctor_id' => $doctor->id,
            'appointment_date' => today()->addDay()->toDateString(),
            'appointment_time' => '10:00',
        ])->assertCreated();

        Sanctum::actingAs($secondPatientUser);
        $this->postJson('/api/appointments', [
            'doctor_id' => $doctor->id,
            'appointment_date' => today()->addDay()->toDateString(),
            'appointment_time' => '11:00',
        ])->assertCreated();

        $this->assertSame(2, Appointment::where('doctor_id', $doctor->id)->count());
    }

    public function test_a_cancelled_appointment_frees_the_slot_back_up(): void
    {
        [, $doctor] = $this->makeDoctor();
        [$firstPatientUser, $firstPatient] = $this->makePatient();
        [$secondPatientUser] = $this->makePatient();

        $cancelled = Appointment::create([
            'patient_id' => $firstPatient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => today()->addDay()->toDateString(),
            'appointment_time' => '10:00',
            'type' => 'online',
            'amount' => 100,
            'payment_status' => 'unpaid',
            'status' => 'cancelled',
        ]);

        Sanctum::actingAs($secondPatientUser);
        $this->postJson('/api/appointments', [
            'doctor_id' => $doctor->id,
            'appointment_date' => today()->addDay()->toDateString(),
            'appointment_time' => '10:00',
        ])->assertCreated();

        $this->assertSame(2, Appointment::where('doctor_id', $doctor->id)->count());
        $this->assertSame('cancelled', $cancelled->fresh()->status);
    }

    public function test_the_same_patient_can_still_book_a_different_doctor_at_the_identical_slot(): void
    {
        [, $doctorA] = $this->makeDoctor();
        [, $doctorB] = $this->makeDoctor();
        [$patientUser] = $this->makePatient();

        Sanctum::actingAs($patientUser);
        $this->postJson('/api/appointments', [
            'doctor_id' => $doctorA->id,
            'appointment_date' => today()->addDay()->toDateString(),
            'appointment_time' => '10:00',
        ])->assertCreated();

        $this->postJson('/api/appointments', [
            'doctor_id' => $doctorB->id,
            'appointment_date' => today()->addDay()->toDateString(),
            'appointment_time' => '10:00',
        ])->assertCreated();
    }

    private function makePatient(): array
    {
        $user = User::create([
            'first_name' => 'Booking', 'last_name' => uniqid('Patient'),
            'name' => 'Booking Patient', 'email' => uniqid('bookingpatient').'@riva.test',
            'password' => 'password', 'role' => 'patient', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Patient::create(['user_id' => $user->id])];
    }

    private function makeDoctor(): array
    {
        $user = User::create([
            'first_name' => 'Booking', 'last_name' => uniqid('Doctor'),
            'name' => 'Booking Doctor', 'email' => uniqid('bookingdoctor').'@riva.test',
            'password' => 'password', 'role' => 'doctor', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Doctor::create(['user_id' => $user->id, 'specialty' => 'General', 'fee' => 100, 'is_active' => true])];
    }
}
