<?php

namespace Tests\Feature;

use App\Models\Caregiver;
use App\Models\Doctor;
use App\Models\Message;
use App\Models\Patient;
use App\Models\PatientCaregiverRelationship;
use App\Models\PatientDoctorRelationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * SECURITY (RIVA-117, P0) regression coverage for ChatController::upload().
 * Before this fix, store() (text messages) required canChatWith() — an
 * actual accepted patient/doctor or patient/caregiver relationship, or
 * admin — but upload() (file messages) had NO such check: any authenticated
 * user could send a file "message" to an arbitrary receiver_id with zero
 * relationship, bypassing the exact boundary the text-message endpoint
 * enforces one method away in the same controller.
 */
class ChatUploadAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_a_patient_cannot_upload_a_file_to_an_unrelated_doctor(): void
    {
        [$patientUser] = $this->makePatient();
        [$unrelatedDoctorUser] = $this->makeDoctor();

        Sanctum::actingAs($patientUser);
        $this->postJson('/api/messages/upload', [
            'receiver_id' => $unrelatedDoctorUser->id,
            'file' => UploadedFile::fake()->image('scan.jpg'),
        ])->assertForbidden();

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_a_patient_can_upload_a_file_to_their_own_related_doctor(): void
    {
        [$patientUser, $patient] = $this->makePatient();
        [$doctorUser, $doctor] = $this->makeDoctor();

        PatientDoctorRelationship::create([
            'patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'status' => 'active',
        ]);

        Sanctum::actingAs($patientUser);
        $this->postJson('/api/messages/upload', [
            'receiver_id' => $doctorUser->id,
            'file' => UploadedFile::fake()->image('scan.jpg'),
        ])->assertCreated();

        $this->assertDatabaseCount('messages', 1);
        $this->assertSame($doctorUser->id, Message::first()->receiver_id);
    }

    public function test_a_doctor_cannot_upload_a_file_to_an_unrelated_patient(): void
    {
        [$doctorUser] = $this->makeDoctor();
        [$unrelatedPatientUser] = $this->makePatient();

        Sanctum::actingAs($doctorUser);
        $this->postJson('/api/messages/upload', [
            'receiver_id' => $unrelatedPatientUser->id,
            'file' => UploadedFile::fake()->image('scan.jpg'),
        ])->assertForbidden();

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_a_caregiver_cannot_upload_a_file_to_an_unrelated_patient(): void
    {
        [$caregiverUser] = $this->makeCaregiver();
        [$unrelatedPatientUser] = $this->makePatient();

        Sanctum::actingAs($caregiverUser);
        $this->postJson('/api/messages/upload', [
            'receiver_id' => $unrelatedPatientUser->id,
            'file' => UploadedFile::fake()->image('scan.jpg'),
        ])->assertForbidden();

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_a_related_caregiver_can_upload_a_file_to_their_patient(): void
    {
        [$caregiverUser, $caregiver] = $this->makeCaregiver();
        [$patientUser, $patient] = $this->makePatient();

        PatientCaregiverRelationship::create([
            'patient_id' => $patient->id, 'caregiver_id' => $caregiver->id, 'status' => 'active',
        ]);

        Sanctum::actingAs($caregiverUser);
        $this->postJson('/api/messages/upload', [
            'receiver_id' => $patientUser->id,
            'file' => UploadedFile::fake()->image('scan.jpg'),
        ])->assertCreated();

        $this->assertDatabaseCount('messages', 1);
    }

    public function test_a_nonexistent_receiver_id_is_rejected_with_a_validation_error_not_a_server_error(): void
    {
        [$patientUser] = $this->makePatient();

        Sanctum::actingAs($patientUser);
        $this->postJson('/api/messages/upload', [
            'receiver_id' => 999999,
            'file' => UploadedFile::fake()->image('scan.jpg'),
        ])->assertStatus(422);

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_an_admin_can_upload_a_file_to_any_user(): void
    {
        $admin = User::create([
            'first_name' => 'Chat', 'last_name' => 'Admin', 'name' => 'Chat Admin',
            'email' => uniqid('chatadmin').'@riva.test', 'password' => 'password',
            'role' => 'admin', 'is_active' => true, 'profile_completed_at' => now(),
        ]);
        [$patientUser] = $this->makePatient();

        Sanctum::actingAs($admin);
        $this->postJson('/api/messages/upload', [
            'receiver_id' => $patientUser->id,
            'file' => UploadedFile::fake()->image('scan.jpg'),
        ])->assertCreated();
    }

    private function makePatient(): array
    {
        $user = User::create([
            'first_name' => 'Chat', 'last_name' => uniqid('Patient'),
            'name' => 'Chat Patient', 'email' => uniqid('chatpatient').'@riva.test',
            'password' => 'password', 'role' => 'patient', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Patient::create(['user_id' => $user->id])];
    }

    private function makeDoctor(): array
    {
        $user = User::create([
            'first_name' => 'Chat', 'last_name' => uniqid('Doctor'),
            'name' => 'Chat Doctor', 'email' => uniqid('chatdoctor').'@riva.test',
            'password' => 'password', 'role' => 'doctor', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Doctor::create(['user_id' => $user->id, 'specialty' => 'General', 'fee' => 100, 'is_active' => true])];
    }

    private function makeCaregiver(): array
    {
        $user = User::create([
            'first_name' => 'Chat', 'last_name' => uniqid('Caregiver'),
            'name' => 'Chat Caregiver', 'email' => uniqid('chatcaregiver').'@riva.test',
            'password' => 'password', 'role' => 'caregiver', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Caregiver::create(['user_id' => $user->id])];
    }
}
