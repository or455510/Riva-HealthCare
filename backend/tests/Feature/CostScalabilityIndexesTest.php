<?php

namespace Tests\Feature;

use App\Models\DailyStatus;
use App\Models\Doctor;
use App\Models\GroupChat;
use App\Models\Patient;
use App\Models\PatientDoctorRelationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * COST / SCALABILITY (RIVA-104) regression coverage for the composite
 * indexes and uniqueness constraints added in
 * database/migrations/2026_08_24_000001_add_cost_scalability_indexes.php —
 * see the cost/scalability audit §22 P1-1.
 */
class CostScalabilityIndexesTest extends TestCase
{
    use RefreshDatabase;

    public function test_expected_indexes_exist_on_every_targeted_table(): void
    {
        $this->assertTrue(Schema::hasIndex('patient_doctor_relationships', 'pdr_doctor_id_status_index'));
        $this->assertTrue(Schema::hasIndex('patient_doctor_relationships', 'pdr_patient_id_status_index'));
        $this->assertTrue(Schema::hasIndex('patient_caregiver_relationships', 'pcr_caregiver_id_status_index'));
        $this->assertTrue(Schema::hasIndex('patient_caregiver_relationships', 'pcr_patient_id_status_index'));
        $this->assertTrue(Schema::hasIndex('users', 'users_role_index'));
        $this->assertTrue(Schema::hasIndex('users', 'users_phone_index'));
        $this->assertTrue(Schema::hasIndex('messages', 'messages_sender_id_receiver_id_index'));
        $this->assertTrue(Schema::hasIndex('medication_logs', 'medication_logs_status_scheduled_at_index'));
        $this->assertTrue(Schema::hasIndex('group_chat_members', 'group_chat_members_group_chat_id_user_id_unique'));
        $this->assertTrue(Schema::hasIndex('daily_statuses', 'daily_statuses_patient_id_date_unique'));
    }

    public function test_group_chat_create_with_duplicate_patient_ids_is_rejected_by_validation(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();
        [, $patient] = $this->makePatient();
        PatientDoctorRelationship::create([
            'doctor_id' => $doctor->id,
            'patient_id' => $patient->id,
            'status' => 'active',
        ]);
        Sanctum::actingAs($doctorUser);

        $patientUserId = $patient->user_id;

        $this->postJson('/api/groups', [
            'name' => 'Test Group',
            'patient_ids' => [$patientUserId, $patientUserId],
        ])->assertStatus(422);
    }

    public function test_group_chat_create_still_works_normally_with_distinct_patients(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();
        [, $patientA] = $this->makePatient();
        [, $patientB] = $this->makePatient();

        foreach ([$patientA, $patientB] as $patient) {
            PatientDoctorRelationship::create([
                'doctor_id' => $doctor->id,
                'patient_id' => $patient->id,
                'status' => 'active',
            ]);
        }

        Sanctum::actingAs($doctorUser);

        $response = $this->postJson('/api/groups', [
            'name' => 'Test Group',
            'patient_ids' => [$patientA->user_id, $patientB->user_id],
        ]);

        $response->assertOk();
        $group = GroupChat::sole();
        $this->assertSame(3, $group->members()->count()); // doctor + 2 patients
    }

    public function test_daily_status_unique_constraint_matches_the_apps_own_updateorcreate_invariant(): void
    {
        [$user, $patient] = $this->makePatient();
        Sanctum::actingAs($user);

        $this->postJson('/api/daily-status', ['mood' => 'good', 'date' => '2026-01-01'])->assertStatus(201);
        $this->postJson('/api/daily-status', ['mood' => 'better', 'date' => '2026-01-01'])->assertStatus(201);

        // updateOrCreate() means the second call updates, not duplicates —
        // the unique constraint makes that invariant real at the DB layer.
        // whereDate() (not where()) is used here deliberately: the 'date'
        // cast stores a full datetime string (see the fromDateTime() fix in
        // DailyStatusController::store()), so a raw where('date', '...')
        // string-equality check would suffer the exact same mismatch this
        // test exists to guard against.
        $this->assertSame(1, DailyStatus::where('patient_id', $patient->id)->whereDate('date', '2026-01-01')->count());
        $this->assertSame('better', DailyStatus::where('patient_id', $patient->id)->whereDate('date', '2026-01-01')->first()->mood);
    }

    private function makePatient(): array
    {
        $user = User::create([
            'first_name' => 'Idx', 'last_name' => uniqid('Patient'),
            'name' => 'Idx Patient', 'email' => uniqid('idxpatient').'@riva.test',
            'password' => 'password', 'role' => 'patient', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Patient::create(['user_id' => $user->id])];
    }

    private function makeDoctor(): array
    {
        $user = User::create([
            'first_name' => 'Idx', 'last_name' => uniqid('Doctor'),
            'name' => 'Idx Doctor', 'email' => uniqid('idxdoctor').'@riva.test',
            'password' => 'password', 'role' => 'doctor', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Doctor::create(['user_id' => $user->id, 'specialty' => 'General', 'fee' => 100])];
    }
}
