<?php

namespace Tests\Feature;

use App\Jobs\SendEmergencyAlertNotifications;
use App\Models\Caregiver;
use App\Models\Doctor;
use App\Models\Notification;
use App\Models\Patient;
use App\Models\PatientCaregiverRelationship;
use App\Models\PatientDoctorRelationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * RIVA — Phase 1 Slice 2 / section 19: emergency alert cross-clinic isolation.
 * EmergencyAlertController::careTeamUsers() is relationship-scoped (only the
 * patient's own active doctor/caregiver relationships), not clinic-scoped —
 * proves that an unrelated doctor in a different clinic never gets an in-app
 * notification for a patient they have no relationship with, even though
 * both patients might otherwise look alike (same platform, same alert flow).
 */
class EmergencyAlertClinicIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unrelated_doctor_in_another_clinic_receives_no_notification_for_the_alert(): void
    {
        Queue::fake();

        [$patientUser, $patient] = $this->makePatient();
        [$ownDoctorUser, $ownDoctor] = $this->makeDoctor();
        [$unrelatedDoctorUser] = $this->makeDoctor();
        [$ownCaregiverUser, $ownCaregiver] = $this->makeCaregiver();
        [$unrelatedCaregiverUser] = $this->makeCaregiver();

        PatientDoctorRelationship::create([
            'doctor_id' => $ownDoctor->id, 'patient_id' => $patient->id, 'status' => 'active',
        ]);
        PatientCaregiverRelationship::create([
            'caregiver_id' => $ownCaregiver->id, 'patient_id' => $patient->id, 'status' => 'active',
        ]);

        Sanctum::actingAs($patientUser);
        $this->postJson('/api/emergency-alert', [
            'severity' => 'critical',
            'message' => 'Chest pain',
        ])->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $ownDoctorUser->id,
            'type' => 'emergency_alert',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $ownCaregiverUser->id,
            'type' => 'emergency_alert',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $unrelatedDoctorUser->id,
            'type' => 'emergency_alert',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $unrelatedCaregiverUser->id,
            'type' => 'emergency_alert',
        ]);

        Queue::assertPushed(SendEmergencyAlertNotifications::class);
    }

    public function test_only_a_patient_can_trigger_an_emergency_alert(): void
    {
        [$doctorUser] = $this->makeDoctor();

        Sanctum::actingAs($doctorUser);
        $this->postJson('/api/emergency-alert', ['severity' => 'critical'])->assertForbidden();
    }

    private function makePatient(): array
    {
        $user = User::create([
            'first_name' => 'Patient', 'last_name' => uniqid('EA'),
            'name' => 'Patient EA', 'email' => uniqid('patient').'@riva.test',
            'password' => 'password', 'role' => 'patient', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Patient::create(['user_id' => $user->id])];
    }

    private function makeDoctor(): array
    {
        $user = User::create([
            'first_name' => 'Doctor', 'last_name' => uniqid('EA'),
            'name' => 'Doctor EA', 'email' => uniqid('doctor').'@riva.test',
            'password' => 'password', 'role' => 'doctor', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Doctor::create(['user_id' => $user->id])];
    }

    private function makeCaregiver(): array
    {
        $user = User::create([
            'first_name' => 'Caregiver', 'last_name' => uniqid('EA'),
            'name' => 'Caregiver EA', 'email' => uniqid('caregiver').'@riva.test',
            'password' => 'password', 'role' => 'caregiver', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Caregiver::create(['user_id' => $user->id])];
    }
}
