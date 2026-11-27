<?php

namespace Tests\Feature;

use App\Models\Caregiver;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RIVA-141 follow-up (product-owner request, recorded 2026-08-25): "the
 * filtering is just text boxes, there's no dropdown suggesting options to
 * pick from." Proves DoctorController::filterOptions() and
 * CaregiverController::filterOptions() return only REAL, distinct values
 * that already exist in the data (no fabricated taxonomy), exclude
 * inactive/blank rows, and de-duplicate correctly.
 */
class FilterOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_filter_options_returns_distinct_real_specialties_and_locations(): void
    {
        $this->makeDoctor('Cardiology', '12 Nile St, Cairo');
        $this->makeDoctor('Cardiology', '12 Nile St, Cairo'); // exact duplicate — must collapse
        $this->makeDoctor('Dermatology', '5 Tahrir Sq, Giza');
        $this->makeDoctor(null, null); // blank specialty/address — must be excluded, never fabricated
        $inactive = $this->makeDoctor('Neurology', '9 Corniche, Alexandria');
        // is_active isn't mass-assignable — forceFill() is this codebase's
        // established convention for writing non-fillable columns directly
        // (see e.g. MedicationController::destroy(), AuthController).
        $inactive->forceFill(['is_active' => false])->save();

        $response = $this->getJson('/api/doctors/filter-options')->assertOk();

        $specialties = $response->json('data.specialties');
        $locations = $response->json('data.locations');

        $this->assertEqualsCanonicalizing(['Cardiology', 'Dermatology'], $specialties);
        $this->assertEqualsCanonicalizing(['12 Nile St, Cairo', '5 Tahrir Sq, Giza'], $locations);
        $this->assertNotContains('Neurology', $specialties);
        $this->assertNotContains('', $specialties);
    }

    public function test_caregiver_filter_options_returns_distinct_real_specialties_and_locations(): void
    {
        $this->makeCaregiver('Elderly Care', '3 Zamalek St, Cairo');
        $this->makeCaregiver('Elderly Care', '3 Zamalek St, Cairo');
        $this->makeCaregiver('Postpartum Care', '18 Maadi Rd, Cairo');

        $response = $this->getJson('/api/caregivers/filter-options')->assertOk();

        $this->assertEqualsCanonicalizing(['Elderly Care', 'Postpartum Care'], $response->json('data.specialties'));
        $this->assertEqualsCanonicalizing(['18 Maadi Rd, Cairo', '3 Zamalek St, Cairo'], $response->json('data.locations'));
    }

    public function test_doctor_filter_options_route_does_not_collide_with_show_route(): void
    {
        // Regression guard: doctors/filter-options must resolve to the real
        // handler, not be swallowed by implicit route-model binding on
        // doctors/{doctor} (which would 404 trying to find a Doctor with
        // id/key "filter-options").
        $this->makeDoctor('Pediatrics', 'Somewhere');

        $this->getJson('/api/doctors/filter-options')
            ->assertOk()
            ->assertJsonStructure(['data' => ['specialties', 'locations']]);
    }

    private function makeDoctor(?string $specialty, ?string $address): Doctor
    {
        $user = User::create([
            'first_name' => 'Doc', 'last_name' => uniqid('Filter'),
            'name' => 'Doc Filter', 'email' => uniqid('docfilter').'@riva.test',
            'password' => 'password', 'role' => 'doctor', 'is_active' => true,
            'profile_completed_at' => now(), 'address' => $address,
        ]);

        return Doctor::create(['user_id' => $user->id, 'specialty' => $specialty, 'fee' => 100, 'is_active' => true]);
    }

    private function makeCaregiver(?string $specialty, ?string $address): Caregiver
    {
        $user = User::create([
            'first_name' => 'Care', 'last_name' => uniqid('Filter'),
            'name' => 'Care Filter', 'email' => uniqid('carefilter').'@riva.test',
            'password' => 'password', 'role' => 'caregiver', 'is_active' => true,
            'profile_completed_at' => now(), 'address' => $address,
        ]);

        return Caregiver::create(['user_id' => $user->id, 'specialty' => $specialty, 'fee' => 80, 'is_active' => true]);
    }
}
