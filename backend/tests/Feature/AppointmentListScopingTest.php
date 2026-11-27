<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Caregiver;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientCaregiverRelationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * SECURITY (Phase 1 Slice 2 / CRITICAL IDOR) regression coverage for
 * AppointmentController::index(). See the docblock on that method for the
 * full finding: any role other than 'patient'/'doctor' (in practice,
 * 'caregiver') previously fell through to zero where() filters and received
 * every appointment in the entire system — every patient, every doctor, every
 * amount and payment_status, across every clinic.
 */
class AppointmentListScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_caregiver_only_sees_appointments_of_their_related_patients(): void
    {
        [$relatedPatientUser, $relatedPatient] = $this->makePatient();
        [$unrelatedPatientUser, $unrelatedPatient] = $this->makePatient();
        [, $doctor] = $this->makeDoctor();
        [$caregiverUser, $caregiver] = $this->makeCaregiver();

        PatientCaregiverRelationship::create([
            'caregiver_id' => $caregiver->id,
            'patient_id' => $relatedPatient->id,
            'status' => 'active',
        ]);

        $relatedAppointment = $this->makeAppointment($relatedPatient, $doctor);
        $unrelatedAppointment = $this->makeAppointment($unrelatedPatient, $doctor);

        Sanctum::actingAs($caregiverUser);
        $response = $this->getJson('/api/appointments')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($relatedAppointment->id, $ids);
        $this->assertNotContains($unrelatedAppointment->id, $ids, 'Caregiver must not see an unrelated patient\'s appointment.');
    }

    public function test_caregiver_with_no_relationships_at_all_sees_an_empty_list_not_everything(): void
    {
        [$patientUser, $patient] = $this->makePatient();
        [, $doctor] = $this->makeDoctor();
        $this->makeAppointment($patient, $doctor);

        [$caregiverUser] = $this->makeCaregiver();

        Sanctum::actingAs($caregiverUser);
        $response = $this->getJson('/api/appointments')->assertOk();

        $this->assertCount(0, $response->json('data'), 'A caregiver with zero relationships must not see any appointment.');
    }

    public function test_patient_only_sees_their_own_appointments(): void
    {
        [$patientUser, $patient] = $this->makePatient();
        [$otherPatientUser, $otherPatient] = $this->makePatient();
        [, $doctor] = $this->makeDoctor();

        $ownAppointment = $this->makeAppointment($patient, $doctor);
        $otherAppointment = $this->makeAppointment($otherPatient, $doctor);

        Sanctum::actingAs($patientUser);
        $response = $this->getJson('/api/appointments')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($ownAppointment->id, $ids);
        $this->assertNotContains($otherAppointment->id, $ids);
    }

    public function test_doctor_only_sees_their_own_appointments(): void
    {
        [$patientUser, $patient] = $this->makePatient();
        [$doctorUser, $doctor] = $this->makeDoctor();
        [, $otherDoctor] = $this->makeDoctor();

        $ownAppointment = $this->makeAppointment($patient, $doctor);
        $otherAppointment = $this->makeAppointment($patient, $otherDoctor);

        Sanctum::actingAs($doctorUser);
        $response = $this->getJson('/api/appointments')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($ownAppointment->id, $ids);
        $this->assertNotContains($otherAppointment->id, $ids);
    }

    public function test_admin_sees_every_appointment_intentionally(): void
    {
        [, $patient] = $this->makePatient();
        [, $doctor] = $this->makeDoctor();
        $appointment = $this->makeAppointment($patient, $doctor);

        $admin = User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'name' => 'Admin User',
            'email' => uniqid('admin').'@riva.test', 'password' => 'password',
            'role' => 'admin', 'is_active' => true, 'profile_completed_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/appointments')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($appointment->id, $ids);
    }

    private function makeAppointment(Patient $patient, Doctor $doctor): Appointment
    {
        return Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => today()->addDay()->toDateString(),
            'appointment_time' => '10:00',
            'type' => 'online',
            'amount' => 300,
            'payment_status' => 'unpaid',
            'status' => 'pending_payment',
        ]);
    }

    private function makePatient(): array
    {
        $user = User::create([
            'first_name' => 'Patient', 'last_name' => uniqid('AL'),
            'name' => 'Patient AL', 'email' => uniqid('patient').'@riva.test',
            'password' => 'password', 'role' => 'patient', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Patient::create(['user_id' => $user->id])];
    }

    private function makeDoctor(): array
    {
        $user = User::create([
            'first_name' => 'Doctor', 'last_name' => uniqid('AL'),
            'name' => 'Doctor AL', 'email' => uniqid('doctor').'@riva.test',
            'password' => 'password', 'role' => 'doctor', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Doctor::create(['user_id' => $user->id, 'fee' => 300])];
    }

    private function makeCaregiver(): array
    {
        $user = User::create([
            'first_name' => 'Caregiver', 'last_name' => uniqid('AL'),
            'name' => 'Caregiver AL', 'email' => uniqid('caregiver').'@riva.test',
            'password' => 'password', 'role' => 'caregiver', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Caregiver::create(['user_id' => $user->id])];
    }
}
