<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\Medication;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\PatientDoctorRelationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * RIVA-130 (product-owner request, recorded 2026-08-25): real photo-based
 * prescription capture, reusing the same already-configured Gemini
 * integration as the AI assistant chat (see AiAssistantKeyCacheTest for the
 * existing Http::fake() convention this mirrors). Proves: scan() never
 * fabricates data when Gemini is unreachable/misconfigured/returns garbage
 * (a doctor always falls back to manual entry, honestly); register() is the
 * only thing that ever writes a Medication row, and only for a patient the
 * doctor actually has an active relationship with; and the best-effort
 * Medicine-catalog match is honestly null when nothing matches, never
 * guessed.
 */
class PrescriptionScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_scan_returns_the_reviewed_list_gemini_extracted(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();
        [, $patient] = $this->makePatient();
        $this->linkDoctorToPatient($doctor, $patient);

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'medications' => [
                                    ['name' => 'Panadol', 'dosage' => '500mg', 'frequency' => 'twice daily', 'duration' => '5 days', 'instructions' => 'after meals'],
                                ],
                                'scan_notes' => 'Handwriting mostly clear.',
                            ]),
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        Sanctum::actingAs($doctorUser);
        $response = $this->postJson('/api/prescriptions/scan', [
            'patient_id' => $patient->id,
            'image' => UploadedFile::fake()->image('rx.jpg'),
        ])->assertOk();

        $response->assertJsonPath('data.medications.0.name', 'Panadol')
            ->assertJsonPath('data.medications.0.dosage', '500mg')
            ->assertJsonPath('data.scan_notes', 'Handwriting mostly clear.');

        // scan() must never write anything by itself.
        $this->assertDatabaseCount('medications', 0);
    }

    public function test_scan_is_forbidden_for_a_doctor_with_no_relationship_to_the_patient(): void
    {
        [$doctorUser] = $this->makeDoctor();
        [, $patient] = $this->makePatient();

        Sanctum::actingAs($doctorUser);
        $this->postJson('/api/prescriptions/scan', [
            'patient_id' => $patient->id,
            'image' => UploadedFile::fake()->image('rx.jpg'),
        ])->assertForbidden();
    }

    public function test_scan_without_a_configured_gemini_key_fails_honestly_not_silently(): void
    {
        config(['services.gemini.key' => null]);

        [$doctorUser, $doctor] = $this->makeDoctor();
        [, $patient] = $this->makePatient();
        $this->linkDoctorToPatient($doctor, $patient);

        Sanctum::actingAs($doctorUser);
        $this->postJson('/api/prescriptions/scan', [
            'patient_id' => $patient->id,
            'image' => UploadedFile::fake()->image('rx.jpg'),
        ])->assertStatus(503);

        $this->assertDatabaseCount('medications', 0);
    }

    public function test_scan_with_unparseable_gemini_output_returns_an_honest_error_not_fake_medications(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();
        [, $patient] = $this->makePatient();
        $this->linkDoctorToPatient($doctor, $patient);

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'not valid json at all']]]]],
            ], 200),
        ]);

        Sanctum::actingAs($doctorUser);
        $this->postJson('/api/prescriptions/scan', [
            'patient_id' => $patient->id,
            'image' => UploadedFile::fake()->image('rx.jpg'),
        ])->assertStatus(502);

        $this->assertDatabaseCount('medications', 0);
    }

    public function test_scan_with_no_readable_medications_returns_an_empty_list_not_an_error(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();
        [, $patient] = $this->makePatient();
        $this->linkDoctorToPatient($doctor, $patient);

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => json_encode(['medications' => [], 'scan_notes' => 'Image too blurry to read.']),
                    ]]],
                ]],
            ], 200),
        ]);

        Sanctum::actingAs($doctorUser);
        $response = $this->postJson('/api/prescriptions/scan', [
            'patient_id' => $patient->id,
            'image' => UploadedFile::fake()->image('rx.jpg'),
        ])->assertOk();

        $this->assertCount(0, $response->json('data.medications'));
    }

    public function test_register_creates_real_medication_rows_for_the_patient(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();
        [, $patient] = $this->makePatient();
        $this->linkDoctorToPatient($doctor, $patient);

        Sanctum::actingAs($doctorUser);
        $response = $this->postJson('/api/prescriptions/register', [
            'patient_id' => $patient->id,
            'medications' => [
                ['name' => 'Amoxicillin', 'dosage' => '250mg', 'frequency' => 'three times daily', 'duration' => '7 days', 'instructions' => 'with food'],
                ['name' => 'Ibuprofen', 'dosage' => '200mg'],
            ],
        ])->assertCreated();

        $response->assertJsonCount(2, 'data.registered');
        $this->assertDatabaseCount('medications', 2);
        $this->assertDatabaseHas('medications', [
            'patient_id' => $patient->id,
            'name' => 'Amoxicillin',
            'dosage' => '250mg',
        ]);

        $amoxicillin = Medication::where('name', 'Amoxicillin')->firstOrFail();
        $this->assertStringContainsString('Duration: 7 days', (string) $amoxicillin->instructions);
        $this->assertStringContainsString('with food', (string) $amoxicillin->instructions);
    }

    public function test_register_attaches_a_real_medicine_catalog_match_when_one_exists(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();
        [, $patient] = $this->makePatient();
        $this->linkDoctorToPatient($doctor, $patient);
        $catalogEntry = Medicine::create(['name' => 'Panadol Extra', 'is_active' => true]);

        Sanctum::actingAs($doctorUser);
        $response = $this->postJson('/api/prescriptions/register', [
            'patient_id' => $patient->id,
            'medications' => [['name' => 'Panadol', 'dosage' => '500mg']],
        ])->assertCreated();

        $response->assertJsonPath('data.registered.0.matched_medicine_id', $catalogEntry->id);
    }

    public function test_register_honestly_reports_no_catalog_match_instead_of_guessing(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();
        [, $patient] = $this->makePatient();
        $this->linkDoctorToPatient($doctor, $patient);

        Sanctum::actingAs($doctorUser);
        $response = $this->postJson('/api/prescriptions/register', [
            'patient_id' => $patient->id,
            'medications' => [['name' => 'SomeVeryObscureMedicineNotInCatalog', 'dosage' => '10mg']],
        ])->assertCreated();

        $this->assertNull($response->json('data.registered.0.matched_medicine_id'));
    }

    public function test_register_is_forbidden_for_a_doctor_with_no_relationship_to_the_patient(): void
    {
        [$doctorUser] = $this->makeDoctor();
        [, $patient] = $this->makePatient();

        Sanctum::actingAs($doctorUser);
        $this->postJson('/api/prescriptions/register', [
            'patient_id' => $patient->id,
            'medications' => [['name' => 'Aspirin']],
        ])->assertForbidden();

        $this->assertDatabaseCount('medications', 0);
    }

    public function test_a_patient_cannot_call_either_prescription_scan_endpoint(): void
    {
        [$patientUser, $patient] = $this->makePatient();

        Sanctum::actingAs($patientUser);
        $this->postJson('/api/prescriptions/scan', [
            'patient_id' => $patient->id,
            'image' => UploadedFile::fake()->image('rx.jpg'),
        ])->assertForbidden();
        $this->postJson('/api/prescriptions/register', [
            'patient_id' => $patient->id,
            'medications' => [['name' => 'Aspirin']],
        ])->assertForbidden();
    }

    private function linkDoctorToPatient(Doctor $doctor, Patient $patient): void
    {
        PatientDoctorRelationship::create([
            'doctor_id' => $doctor->id, 'patient_id' => $patient->id, 'status' => 'active',
        ]);
    }

    private function makePatient(): array
    {
        $user = User::create([
            'first_name' => 'Patient', 'last_name' => uniqid('Rx'),
            'name' => 'Patient Rx', 'email' => uniqid('patient').'@riva.test',
            'password' => 'password', 'role' => 'patient', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Patient::create(['user_id' => $user->id])];
    }

    private function makeDoctor(): array
    {
        $user = User::create([
            'first_name' => 'Doctor', 'last_name' => uniqid('Rx'),
            'name' => 'Doctor Rx', 'email' => uniqid('doctor').'@riva.test',
            'password' => 'password', 'role' => 'doctor', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Doctor::create(['user_id' => $user->id, 'fee' => 300])];
    }
}
