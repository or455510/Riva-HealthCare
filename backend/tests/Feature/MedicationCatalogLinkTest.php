<?php

namespace Tests\Feature;

use App\Models\Medication;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * RIVA-132 follow-up (product-owner request, recorded 2026-08-25): "shows
 * them the nearest pharmacies that have the medication they need."
 * PrescriptionScanController::register() used to compute a best-effort
 * Medicine-catalog match but never persist it — the link only survived in
 * that one API response. This proves `medicine_id` is now genuinely saved
 * on the `medications` row (so the patient's medication list can re-offer
 * "Find nearby pharmacies" any time they view it, not only right after
 * creation), that MedicationController::store()/update() apply the SAME
 * matching for patient-added medications (not just scan-registered ones),
 * and that a name with no real catalog match honestly gets a null
 * medicine_id — never a guessed one.
 */
class MedicationCatalogLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_patient_adding_a_medication_gets_a_real_catalog_match_when_one_exists(): void
    {
        $catalogEntry = Medicine::create(['name' => 'Panadol Extra', 'is_active' => true]);
        [$user] = $this->makePatient();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/medications', [
            'drug_name' => 'Panadol',
            'dosage' => '500mg',
        ])->assertCreated();

        $response->assertJsonPath('data.medicine_id', $catalogEntry->id)
            ->assertJsonPath('data.matched_medicine_name', 'Panadol Extra');

        $this->assertDatabaseHas('medications', ['name' => 'Panadol', 'medicine_id' => $catalogEntry->id]);
    }

    public function test_patient_adding_a_medication_with_no_catalog_match_honestly_gets_a_null_medicine_id(): void
    {
        [$user] = $this->makePatient();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/medications', [
            'drug_name' => 'SomeVeryObscureMedicineNotInCatalog',
            'dosage' => '10mg',
        ])->assertCreated();

        $this->assertNull($response->json('data.medicine_id'));
        $this->assertNull($response->json('data.matched_medicine_name'));
    }

    public function test_renaming_a_medication_recomputes_its_catalog_match(): void
    {
        [$user, $patient] = $this->makePatient();
        $catalogEntry = Medicine::create(['name' => 'Amoxil', 'is_active' => true]);
        $medication = Medication::create([
            'patient_id' => $patient->id, 'name' => 'SomethingElse', 'dosage' => '1', 'frequency' => 'daily', 'is_active' => true,
        ]);
        Sanctum::actingAs($user);

        $this->putJson("/api/medications/{$medication->id}", ['drug_name' => 'Amoxil'])
            ->assertOk()
            ->assertJsonPath('data.medicine_id', $catalogEntry->id);
    }

    public function test_prescription_scan_registration_persists_the_catalog_match_not_just_returns_it(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();
        [, $patient] = $this->makePatient();
        \App\Models\PatientDoctorRelationship::create([
            'doctor_id' => $doctor->id, 'patient_id' => $patient->id, 'status' => 'active',
        ]);
        $catalogEntry = Medicine::create(['name' => 'Augmentin', 'is_active' => true]);

        Sanctum::actingAs($doctorUser);
        $this->postJson('/api/prescriptions/register', [
            'patient_id' => $patient->id,
            'medications' => [['name' => 'Augmentin', 'dosage' => '625mg']],
        ])->assertCreated();

        // The real regression this test guards: medicine_id must be saved on
        // the row itself, not only present in the one-time API response.
        $this->assertDatabaseHas('medications', [
            'patient_id' => $patient->id,
            'name' => 'Augmentin',
            'medicine_id' => $catalogEntry->id,
        ]);

        $saved = Medication::where('patient_id', $patient->id)->where('name', 'Augmentin')->firstOrFail();
        $this->assertSame($catalogEntry->id, $saved->medicine_id);
    }

    private function makePatient(): array
    {
        $user = User::create([
            'first_name' => 'Patient', 'last_name' => uniqid('Cat'),
            'name' => 'Patient Cat', 'email' => uniqid('patientcat').'@riva.test',
            'password' => 'password', 'role' => 'patient', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Patient::create(['user_id' => $user->id])];
    }

    private function makeDoctor(): array
    {
        $user = User::create([
            'first_name' => 'Doctor', 'last_name' => uniqid('Cat'),
            'name' => 'Doctor Cat', 'email' => uniqid('doctorcat').'@riva.test',
            'password' => 'password', 'role' => 'doctor', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, \App\Models\Doctor::create(['user_id' => $user->id, 'fee' => 300])];
    }
}
