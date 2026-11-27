<?php

namespace Tests\Feature;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyBranch;
use App\Models\PharmacyMedicineAvailability;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * RIVA-110 (directive §16): public, read-only pharmacy directory + medicine
 * availability search. The core product invariant under test is that a
 * medicine with NO availability row anywhere is never reported as
 * available — the architecture must not fabricate real inventory data.
 */
class PharmacyDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_only_active_pharmacies_with_their_active_branches(): void
    {
        $active = Pharmacy::create(['name' => 'Active Pharmacy', 'is_active' => true]);
        PharmacyBranch::create(['pharmacy_id' => $active->id, 'name' => 'Main', 'address' => '123 Main St', 'is_active' => true]);
        PharmacyBranch::create(['pharmacy_id' => $active->id, 'name' => 'Closed Branch', 'address' => '123 Main St', 'is_active' => false]);
        Pharmacy::create(['name' => 'Inactive Pharmacy', 'is_active' => false]);

        $response = $this->getJson('/api/pharmacies')->assertOk();

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('Active Pharmacy', $names);
        $this->assertNotContains('Inactive Pharmacy', $names);

        $branches = collect($response->json('data'))->firstWhere('name', 'Active Pharmacy')['branches'];
        $this->assertCount(1, $branches);
        $this->assertSame('Main', $branches[0]['name']);
    }

    public function test_show_returns_404_for_an_inactive_pharmacy(): void
    {
        $pharmacy = Pharmacy::create(['name' => 'Hidden Pharmacy', 'is_active' => false]);

        $this->getJson("/api/pharmacies/{$pharmacy->id}")->assertNotFound();
    }

    public function test_branches_endpoint_only_returns_active_branches(): void
    {
        $pharmacy = Pharmacy::create(['name' => 'Branch Test Pharmacy', 'is_active' => true]);
        $open = PharmacyBranch::create(['pharmacy_id' => $pharmacy->id, 'name' => 'Open', 'address' => '123 Main St', 'is_active' => true]);
        PharmacyBranch::create(['pharmacy_id' => $pharmacy->id, 'name' => 'Closed', 'address' => '123 Main St', 'is_active' => false]);

        $response = $this->getJson("/api/pharmacies/{$pharmacy->id}/branches")->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$open->id], $ids);
    }

    public function test_medicines_search_matches_name_or_generic_name(): void
    {
        Medicine::create(['name' => 'Panadol', 'generic_name' => 'Paracetamol', 'is_active' => true]);
        Medicine::create(['name' => 'Amoxil', 'generic_name' => 'Amoxicillin', 'is_active' => true]);
        Medicine::create(['name' => 'Hidden', 'generic_name' => 'Paracetamol', 'is_active' => false]);

        $byName = $this->getJson('/api/medicines?search=panad')->assertOk();
        $this->assertCount(1, $byName->json('data'));
        $this->assertSame('Panadol', $byName->json('data.0.name'));

        $byGeneric = $this->getJson('/api/medicines?search=paracetamol')->assertOk();
        $names = collect($byGeneric->json('data'))->pluck('name')->all();
        $this->assertContains('Panadol', $names);
        $this->assertNotContains('Hidden', $names);
    }

    public function test_availability_only_lists_branches_with_an_explicit_available_row(): void
    {
        $medicine = Medicine::create(['name' => 'Augmentin', 'is_active' => true]);
        $otherMedicine = Medicine::create(['name' => 'Other', 'is_active' => true]);

        $pharmacy = Pharmacy::create(['name' => 'Availability Pharmacy', 'is_active' => true]);
        $availableBranch = PharmacyBranch::create(['pharmacy_id' => $pharmacy->id, 'name' => 'Has Stock', 'address' => '123 Main St', 'is_active' => true]);
        $unavailableBranch = PharmacyBranch::create(['pharmacy_id' => $pharmacy->id, 'name' => 'No Stock', 'address' => '123 Main St', 'is_active' => true]);
        $inactiveBranch = PharmacyBranch::create(['pharmacy_id' => $pharmacy->id, 'name' => 'Closed Branch', 'address' => '123 Main St', 'is_active' => false]);
        $noRowBranch = PharmacyBranch::create(['pharmacy_id' => $pharmacy->id, 'name' => 'No Row At All', 'address' => '123 Main St', 'is_active' => true]);

        PharmacyMedicineAvailability::create([
            'pharmacy_branch_id' => $availableBranch->id, 'medicine_id' => $medicine->id,
            'is_available' => true, 'price' => 12.50, 'reported_at' => now(),
        ]);
        PharmacyMedicineAvailability::create([
            'pharmacy_branch_id' => $unavailableBranch->id, 'medicine_id' => $medicine->id,
            'is_available' => false, 'reported_at' => now(),
        ]);
        PharmacyMedicineAvailability::create([
            'pharmacy_branch_id' => $inactiveBranch->id, 'medicine_id' => $medicine->id,
            'is_available' => true, 'reported_at' => now(),
        ]);
        // A row for an entirely different medicine must never leak in here.
        PharmacyMedicineAvailability::create([
            'pharmacy_branch_id' => $noRowBranch->id, 'medicine_id' => $otherMedicine->id,
            'is_available' => true, 'reported_at' => now(),
        ]);

        $response = $this->getJson("/api/medicines/{$medicine->id}/availability")->assertOk();

        $branchNames = collect($response->json('data.available_at'))->pluck('branch_name')->all();
        $this->assertSame(['Has Stock'], $branchNames);
        $this->assertSame('12.50', (string) $response->json('data.available_at.0.price'));
    }

    public function test_availability_is_empty_when_no_row_exists_for_the_medicine_at_all(): void
    {
        $medicine = Medicine::create(['name' => 'Never Reported', 'is_active' => true]);

        $response = $this->getJson("/api/medicines/{$medicine->id}/availability")->assertOk();

        $this->assertSame([], $response->json('data.available_at'));
    }

    public function test_public_endpoints_do_not_require_authentication(): void
    {
        $this->getJson('/api/pharmacies')->assertOk();
        $this->getJson('/api/medicines')->assertOk();
    }

    public function test_admin_write_routes_are_not_reachable_as_a_patient(): void
    {
        $user = User::create([
            'first_name' => 'Regular', 'last_name' => 'Patient', 'name' => 'Regular Patient',
            'email' => uniqid('pharmacypatient').'@riva.test', 'password' => 'password',
            'role' => 'patient', 'is_active' => true, 'profile_completed_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/admin/pharmacies', ['name' => 'Should Not Exist'])
            ->assertForbidden();

        $this->assertDatabaseMissing('pharmacies', ['name' => 'Should Not Exist']);
    }
}
