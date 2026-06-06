<?php

namespace Tests\Feature;

use App\Models\Caregiver;
use App\Models\DailyStatus;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientCaregiverRelationship;
use App\Models\PatientDoctorRelationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RegisterProfileDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_returns_incomplete_profile_until_completion(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'first_name' => 'Riva',
            'last_name' => 'Patient',
            'email' => uniqid('register').'@riva.test',
            'phone' => '01000000000',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'patient',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.role', 'patient')
            ->assertJsonPath('user.profile_completed', false);

        $token = $response->json('token');
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.profile_completed', false);
    }

    public function test_complete_profile_marks_user_complete_and_saves_patient_fields(): void
    {
        [$user] = $this->makePatient(false);
        Sanctum::actingAs($user);

        $this->postJson('/api/profile/complete', [
            'first_name' => 'Updated',
            'last_name' => 'Patient',
            'phone' => '01111111111',
            'address' => 'Cairo',
            'gender' => 'Female',
            'age' => 68,
            'blood_type' => 'O+',
            'emergency_contact' => '01012345678',
            'about' => 'Needs daily monitoring',
        ])->assertOk()
            ->assertJsonPath('data.profile_completed', true)
            ->assertJsonPath('data.user.first_name', 'Updated')
            ->assertJsonPath('data.role_profile.age', 68);

        $this->assertNotNull($user->fresh()->profile_completed_at);
        $this->assertDatabaseHas('patients', [
            'user_id' => $user->id,
            'gender' => 'Female',
            'age' => 68,
            'blood_type' => 'O+',
        ]);
    }

    public function test_doctor_dashboard_has_zero_analytics_without_accepted_patients(): void
    {
        [$doctorUser] = $this->makeDoctor();
        Sanctum::actingAs($doctorUser);

        $this->getJson('/api/dashboard/doctor')
            ->assertOk()
            ->assertJsonPath('data.total_patients', 0)
            ->assertJsonPath('data.patient_status_distribution.stable', 0)
            ->assertJsonPath('data.patient_status_distribution.attention', 0)
            ->assertJsonPath('data.patient_status_distribution.critical', 0)
            ->assertJsonPath('data.weekly_patients.0', 0)
            ->assertJsonPath('data.average_adherence', 0);
    }

    public function test_caregiver_dashboard_has_zero_analytics_without_accepted_patients(): void
    {
        [$caregiverUser] = $this->makeCaregiver();
        Sanctum::actingAs($caregiverUser);

        $this->getJson('/api/dashboard/caregiver')
            ->assertOk()
            ->assertJsonPath('data.assigned_patients_count', 0)
            ->assertJsonPath('data.total_patients', 0)
            ->assertJsonPath('data.patient_status_distribution.stable', 0)
            ->assertJsonPath('data.patient_status_distribution.attention', 0)
            ->assertJsonPath('data.patient_status_distribution.critical', 0)
            ->assertJsonPath('data.weekly_patients.0', 0)
            ->assertJsonPath('data.average_adherence', 0);
    }

    public function test_dashboard_analytics_use_only_accepted_relationships(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();
        [, $caregiver] = $this->makeCaregiver();
        [, $acceptedPatient] = $this->makePatient();
        [, $pendingPatient] = $this->makePatient();

        PatientDoctorRelationship::create([
            'patient_id' => $acceptedPatient->id,
            'doctor_id' => $doctor->id,
            'status' => 'active',
        ]);
        PatientDoctorRelationship::create([
            'patient_id' => $pendingPatient->id,
            'doctor_id' => $doctor->id,
            'status' => 'pending',
        ]);
        PatientCaregiverRelationship::create([
            'patient_id' => $acceptedPatient->id,
            'caregiver_id' => $caregiver->id,
            'status' => 'active',
        ]);

        DailyStatus::create([
            'patient_id' => $acceptedPatient->id,
            'mood' => 'neutral',
            'pain_level' => 0,
            'risk_level' => 'high_risk',
        ]);
        DailyStatus::create([
            'patient_id' => $pendingPatient->id,
            'mood' => 'neutral',
            'pain_level' => 0,
            'risk_level' => 'stable',
        ]);

        Sanctum::actingAs($doctorUser);
        $this->getJson('/api/dashboard/doctor')
            ->assertOk()
            ->assertJsonPath('data.total_patients', 1)
            ->assertJsonPath('data.patient_status_distribution.stable', 0)
            ->assertJsonPath('data.patient_status_distribution.critical', 1);
    }

    private function makePatient(bool $completed = true): array
    {
        $user = User::create([
            'first_name' => 'Patient',
            'last_name' => uniqid('Test'),
            'name' => 'Patient Test',
            'email' => uniqid('patient').'@riva.test',
            'password' => 'password',
            'role' => 'patient',
            'is_active' => true,
            'profile_completed_at' => $completed ? now() : null,
        ]);

        return [$user, Patient::create(['user_id' => $user->id])];
    }

    private function makeDoctor(): array
    {
        $user = User::create([
            'first_name' => 'Doctor',
            'last_name' => uniqid('Test'),
            'name' => 'Doctor Test',
            'email' => uniqid('doctor').'@riva.test',
            'password' => 'password',
            'role' => 'doctor',
            'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Doctor::create(['user_id' => $user->id])];
    }

    private function makeCaregiver(): array
    {
        $user = User::create([
            'first_name' => 'Caregiver',
            'last_name' => uniqid('Test'),
            'name' => 'Caregiver Test',
            'email' => uniqid('caregiver').'@riva.test',
            'password' => 'password',
            'role' => 'caregiver',
            'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Caregiver::create(['user_id' => $user->id])];
    }
}
