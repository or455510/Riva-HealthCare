<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientDoctorRelationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * SECURITY (Phase 1 Slice 2 / relationship-boundary IDOR) regression coverage
 * for GroupChatController::create(). See that method's docblock for the full
 * finding: patient_ids used to be validated only against exists:users,id, so
 * a doctor could add any user in the system into a group chat with zero
 * relationship check.
 */
class GroupChatRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_cannot_create_a_group_with_an_unrelated_patient(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();
        [$relatedPatientUser, $relatedPatient] = $this->makePatient();
        [$unrelatedPatientUser] = $this->makePatient();

        PatientDoctorRelationship::create([
            'doctor_id' => $doctor->id,
            'patient_id' => $relatedPatient->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($doctorUser);
        $this->postJson('/api/groups', [
            'name' => 'Care team',
            'patient_ids' => [$relatedPatientUser->id, $unrelatedPatientUser->id],
        ])->assertForbidden();

        $this->assertDatabaseCount('group_chats', 0);
    }

    public function test_doctor_can_create_a_group_with_only_related_patients(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();
        [$relatedPatientUser, $relatedPatient] = $this->makePatient();

        PatientDoctorRelationship::create([
            'doctor_id' => $doctor->id,
            'patient_id' => $relatedPatient->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($doctorUser);
        $response = $this->postJson('/api/groups', [
            'name' => 'Care team',
            'patient_ids' => [$relatedPatientUser->id],
        ])->assertOk();

        $groupId = $response->json('data.group.id');
        $this->assertDatabaseHas('group_chat_members', [
            'group_chat_id' => $groupId,
            'user_id' => $relatedPatientUser->id,
        ]);
    }

    public function test_pending_relationship_does_not_authorize_adding_a_patient_to_a_group(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();
        [$pendingPatientUser, $pendingPatient] = $this->makePatient();

        PatientDoctorRelationship::create([
            'doctor_id' => $doctor->id,
            'patient_id' => $pendingPatient->id,
            'status' => 'pending',
        ]);

        Sanctum::actingAs($doctorUser);
        $this->postJson('/api/groups', [
            'name' => 'Care team',
            'patient_ids' => [$pendingPatientUser->id],
        ])->assertForbidden();
    }

    public function test_non_doctor_cannot_create_a_group(): void
    {
        [$patientUser] = $this->makePatient();

        Sanctum::actingAs($patientUser);
        $this->postJson('/api/groups', [
            'name' => 'Not allowed',
            'patient_ids' => [$patientUser->id],
        ])->assertForbidden();
    }

    private function makePatient(): array
    {
        $user = User::create([
            'first_name' => 'Patient', 'last_name' => uniqid('GC'),
            'name' => 'Patient GC', 'email' => uniqid('patient').'@riva.test',
            'password' => 'password', 'role' => 'patient', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Patient::create(['user_id' => $user->id])];
    }

    private function makeDoctor(): array
    {
        $user = User::create([
            'first_name' => 'Doctor', 'last_name' => uniqid('GC'),
            'name' => 'Doctor GC', 'email' => uniqid('doctor').'@riva.test',
            'password' => 'password', 'role' => 'doctor', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Doctor::create(['user_id' => $user->id])];
    }
}
