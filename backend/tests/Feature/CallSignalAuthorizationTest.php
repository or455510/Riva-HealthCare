<?php

namespace Tests\Feature;

use App\Events\WebRTCSignal;
use App\Models\Caregiver;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientCaregiverRelationship;
use App\Models\PatientDoctorRelationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * SECURITY (Phase 1 Slice 2 / relationship-boundary) regression coverage for
 * CallController::signal(). See that method's docblock for the finding: any
 * authenticated user could previously trigger a WebRTC call signal at any
 * other user with zero relationship check.
 */
class CallSignalAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([WebRTCSignal::class]);
    }

    public function test_doctor_cannot_signal_an_unrelated_patient(): void
    {
        [$doctorUser] = $this->makeDoctor();
        [$unrelatedPatientUser] = $this->makePatient();

        Sanctum::actingAs($doctorUser);
        $this->postJson('/api/call/signal', [
            'type' => 'offer',
            'data' => ['sdp' => 'fake'],
            'to_user_id' => $unrelatedPatientUser->id,
        ])->assertForbidden();

        Event::assertNotDispatched(WebRTCSignal::class);
    }

    public function test_doctor_can_signal_a_related_patient(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();
        [$patientUser, $patient] = $this->makePatient();

        PatientDoctorRelationship::create([
            'doctor_id' => $doctor->id,
            'patient_id' => $patient->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($doctorUser);
        $this->postJson('/api/call/signal', [
            'type' => 'offer',
            'data' => ['sdp' => 'fake'],
            'to_user_id' => $patientUser->id,
        ])->assertOk();

        Event::assertDispatched(WebRTCSignal::class);
    }

    public function test_caregiver_cannot_signal_an_unrelated_patient(): void
    {
        [$caregiverUser] = $this->makeCaregiver();
        [$unrelatedPatientUser] = $this->makePatient();

        Sanctum::actingAs($caregiverUser);
        $this->postJson('/api/call/signal', [
            'type' => 'offer',
            'to_user_id' => $unrelatedPatientUser->id,
        ])->assertForbidden();
    }

    public function test_caregiver_can_signal_a_related_patient(): void
    {
        [$caregiverUser, $caregiver] = $this->makeCaregiver();
        [$patientUser, $patient] = $this->makePatient();

        PatientCaregiverRelationship::create([
            'caregiver_id' => $caregiver->id,
            'patient_id' => $patient->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($caregiverUser);
        $this->postJson('/api/call/signal', [
            'type' => 'offer',
            'to_user_id' => $patientUser->id,
        ])->assertOk();
    }

    public function test_two_unrelated_doctors_cannot_signal_each_other(): void
    {
        [$doctorAUser] = $this->makeDoctor();
        [$doctorBUser] = $this->makeDoctor();

        Sanctum::actingAs($doctorAUser);
        $this->postJson('/api/call/signal', [
            'type' => 'offer',
            'to_user_id' => $doctorBUser->id,
        ])->assertForbidden();
    }

    public function test_user_cannot_signal_themselves(): void
    {
        [$doctorUser] = $this->makeDoctor();

        Sanctum::actingAs($doctorUser);
        $this->postJson('/api/call/signal', [
            'type' => 'offer',
            'to_user_id' => $doctorUser->id,
        ])->assertForbidden();
    }

    private function makePatient(): array
    {
        $user = User::create([
            'first_name' => 'Patient', 'last_name' => uniqid('CS'),
            'name' => 'Patient CS', 'email' => uniqid('patient').'@riva.test',
            'password' => 'password', 'role' => 'patient', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Patient::create(['user_id' => $user->id])];
    }

    private function makeDoctor(): array
    {
        $user = User::create([
            'first_name' => 'Doctor', 'last_name' => uniqid('CS'),
            'name' => 'Doctor CS', 'email' => uniqid('doctor').'@riva.test',
            'password' => 'password', 'role' => 'doctor', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Doctor::create(['user_id' => $user->id])];
    }

    private function makeCaregiver(): array
    {
        $user = User::create([
            'first_name' => 'Caregiver', 'last_name' => uniqid('CS'),
            'name' => 'Caregiver CS', 'email' => uniqid('caregiver').'@riva.test',
            'password' => 'password', 'role' => 'caregiver', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Caregiver::create(['user_id' => $user->id])];
    }
}
