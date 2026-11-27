<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientDoctorRelationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * COST (RIVA-107) regression coverage for DoctorController::index() —
 * see the cost/scalability audit §22 P1-4. transformDoctor() used to run
 * its own PatientDoctorRelationship query per doctor whenever the viewer
 * was an authenticated patient, an N+1 on this public, unpaginated
 * doctor-listing endpoint.
 */
class DoctorListingCostTest extends TestCase
{
    use RefreshDatabase;

    public function test_query_count_does_not_scale_with_the_number_of_doctors_listed(): void
    {
        [$patientUser, $patient] = $this->makePatient();

        $doctors = collect(range(1, 8))->map(fn () => $this->makeDoctor());
        // Give the viewing patient a relationship with half of them, to
        // make sure the batch query path is actually exercised. 'active'
        // (not 'accepted') — the enum's base values are portable across
        // MySQL/SQLite; 'accepted' is only added by a MySQL-only migration
        // (2026_05_23_000002) and isn't a valid SQLite CHECK value.
        foreach ($doctors->take(4) as $doctor) {
            PatientDoctorRelationship::create([
                'doctor_id' => $doctor->id,
                'patient_id' => $patient->id,
                'status' => 'active',
            ]);
        }

        Sanctum::actingAs($patientUser);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson('/api/doctors')->assertOk();
        $queryCountFor8 = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Add several more doctors and confirm the query count does not
        // grow proportionally — a real N+1 here would add ~1 query per
        // doctor (8 -> 16 doctors would roughly double the count).
        foreach (range(1, 8) as $i) {
            $this->makeDoctor();
        }

        // disableQueryLog() does not clear the accumulated log — without
        // this flush, $queryCountFor16 would double-count the first call's
        // queries too and produce a false N+1 failure.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/doctors')->assertOk();
        $queryCountFor16 = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            $queryCountFor8 + 2,
            $queryCountFor16,
            "Query count grew from {$queryCountFor8} to {$queryCountFor16} when doctor count doubled — looks like an N+1."
        );
    }

    public function test_follow_status_is_correctly_paired_per_doctor_not_mixed_up_across_doctors(): void
    {
        [$patientUser, $patient] = $this->makePatient();

        $relatedActive = $this->makeDoctor();
        $relatedPending = $this->makeDoctor();
        $unrelated = $this->makeDoctor();

        PatientDoctorRelationship::create([
            'doctor_id' => $relatedActive->id,
            'patient_id' => $patient->id,
            'status' => 'active',
        ]);
        PatientDoctorRelationship::create([
            'doctor_id' => $relatedPending->id,
            'patient_id' => $patient->id,
            'status' => 'pending',
        ]);

        Sanctum::actingAs($patientUser);

        $response = $this->getJson('/api/doctors')->assertOk();
        $byId = collect($response->json('data'))->keyBy('id');

        $this->assertSame('active', $byId[$relatedActive->id]['follow_status']);
        $this->assertSame('pending', $byId[$relatedPending->id]['follow_status']);
        $this->assertNull($byId[$unrelated->id]['follow_status']);
    }

    public function test_guest_viewer_still_works_with_zero_relationship_queries(): void
    {
        $this->makeDoctor();
        $this->makeDoctor();

        $response = $this->getJson('/api/doctors')->assertOk();
        $statuses = collect($response->json('data'))->pluck('follow_status')->unique()->values();

        $this->assertSame([null], $statuses->all());
    }

    private function makePatient(): array
    {
        $user = User::create([
            'first_name' => 'Doc', 'last_name' => uniqid('Listing'),
            'name' => 'Doc Listing', 'email' => uniqid('doclisting').'@riva.test',
            'password' => 'password', 'role' => 'patient', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Patient::create(['user_id' => $user->id])];
    }

    private function makeDoctor(): Doctor
    {
        $user = User::create([
            'first_name' => 'Doc', 'last_name' => uniqid('D'),
            'name' => 'Doc D', 'email' => uniqid('doc').'@riva.test',
            'password' => 'password', 'role' => 'doctor', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return Doctor::create(['user_id' => $user->id, 'specialty' => 'General', 'fee' => 100, 'is_active' => true]);
    }
}
