<?php

namespace Tests\Feature;

use App\Models\Caregiver;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RIVA-141: real specialty + location filtering on the doctor/caregiver
 * directories (DoctorController::index() / CaregiverController::index()).
 * These are genuine backend query filters — not client-side-only fake
 * filtering — so this coverage lives against the real endpoints.
 */
class DirectoryFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_directory_filters_by_specialty(): void
    {
        $this->makeDoctor('Cardiology', 'Cairo');
        $this->makeDoctor('Dermatology', 'Giza');

        $response = $this->getJson('/api/doctors?specialty=Cardio')->assertOk();
        $names = collect($response->json('data'))->pluck('specialty');

        $this->assertCount(1, $names);
        $this->assertSame('Cardiology', $names->first());
    }

    public function test_doctor_directory_filters_by_location_substring_on_user_address(): void
    {
        $this->makeDoctor('Cardiology', 'Downtown Cairo');
        $this->makeDoctor('Cardiology', 'Giza');

        $response = $this->getJson('/api/doctors?location=Cairo')->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_doctor_directory_specialty_and_location_combine_as_and(): void
    {
        $this->makeDoctor('Cardiology', 'Cairo');
        $this->makeDoctor('Dermatology', 'Cairo');
        $this->makeDoctor('Cardiology', 'Giza');

        $response = $this->getJson('/api/doctors?specialty=Cardio&location=Cairo')->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_doctor_directory_with_no_filters_returns_everyone_unchanged(): void
    {
        $this->makeDoctor('Cardiology', 'Cairo');
        $this->makeDoctor('Dermatology', 'Giza');

        $response = $this->getJson('/api/doctors')->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_caregiver_directory_filters_by_specialty_and_location(): void
    {
        $this->makeCaregiver('Elderly care', 'Cairo');
        $this->makeCaregiver('Pediatric care', 'Cairo');
        $this->makeCaregiver('Elderly care', 'Alexandria');

        $response = $this->getJson('/api/caregivers?specialty=Elderly&location=Cairo')->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    private function makeDoctor(string $specialty, string $address): Doctor
    {
        $user = User::create([
            'first_name' => 'Doc', 'last_name' => uniqid('D'),
            'name' => 'Doc D', 'email' => uniqid('doc') . '@riva.test',
            'password' => 'password', 'role' => 'doctor', 'is_active' => true,
            'address' => $address, 'profile_completed_at' => now(),
        ]);

        return Doctor::create([
            'user_id' => $user->id, 'specialty' => $specialty, 'fee' => 100, 'is_active' => true,
        ]);
    }

    private function makeCaregiver(string $specialty, string $address): Caregiver
    {
        $user = User::create([
            'first_name' => 'Care', 'last_name' => uniqid('C'),
            'name' => 'Care C', 'email' => uniqid('caregiver') . '@riva.test',
            'password' => 'password', 'role' => 'caregiver', 'is_active' => true,
            'address' => $address, 'profile_completed_at' => now(),
        ]);

        return Caregiver::create([
            'user_id' => $user->id, 'specialty' => $specialty, 'is_active' => true,
        ]);
    }
}
