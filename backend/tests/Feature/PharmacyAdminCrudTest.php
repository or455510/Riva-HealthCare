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
 * RIVA-110 (directive §16): admin-only write access for the pharmacy
 * foundation (Admin\PharmacyAdminController). Covers full CRUD across all
 * four resources plus the authorization boundary (non-admin roles must be
 * rejected, matching every other admin-prefixed controller in this app).
 */
class PharmacyAdminCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_update_and_delete_a_pharmacy(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $create = $this->postJson('/api/admin/pharmacies', [
            'name' => 'City Pharmacy', 'phone' => '123456',
        ])->assertCreated();

        $id = $create->json('data.id');
        $this->assertDatabaseHas('pharmacies', ['id' => $id, 'name' => 'City Pharmacy', 'is_active' => 1]);

        $this->putJson("/api/admin/pharmacies/{$id}", ['name' => 'City Pharmacy Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'City Pharmacy Renamed');

        $this->deleteJson("/api/admin/pharmacies/{$id}")->assertOk();
        $this->assertDatabaseMissing('pharmacies', ['id' => $id]);
    }

    public function test_admin_can_create_update_and_delete_a_branch_under_a_pharmacy(): void
    {
        Sanctum::actingAs($this->makeAdmin());
        $pharmacy = Pharmacy::create(['name' => 'Branch Owner Pharmacy', 'is_active' => true]);

        $create = $this->postJson("/api/admin/pharmacies/{$pharmacy->id}/branches", [
            'name' => 'Downtown', 'address' => '1 Market St', 'city' => 'Metropolis',
        ])->assertCreated();

        $branchId = $create->json('data.id');
        $this->assertDatabaseHas('pharmacy_branches', ['id' => $branchId, 'pharmacy_id' => $pharmacy->id]);

        $this->putJson("/api/admin/pharmacy-branches/{$branchId}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->deleteJson("/api/admin/pharmacy-branches/{$branchId}")->assertOk();
        $this->assertDatabaseMissing('pharmacy_branches', ['id' => $branchId]);
    }

    public function test_admin_can_create_update_and_delete_a_medicine(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $create = $this->postJson('/api/admin/medicines', [
            'name' => 'Ibuprofen 200mg', 'generic_name' => 'Ibuprofen',
        ])->assertCreated();

        $id = $create->json('data.id');
        $this->putJson("/api/admin/medicines/{$id}", ['strength' => '200mg'])
            ->assertOk()
            ->assertJsonPath('data.strength', '200mg');

        $this->deleteJson("/api/admin/medicines/{$id}")->assertOk();
        $this->assertDatabaseMissing('medicines', ['id' => $id]);
    }

    public function test_admin_can_report_and_update_availability_and_it_appears_in_the_public_endpoint(): void
    {
        Sanctum::actingAs($this->makeAdmin());
        $pharmacy = Pharmacy::create(['name' => 'Availability Admin Pharmacy', 'is_active' => true]);
        $branch = PharmacyBranch::create(['pharmacy_id' => $pharmacy->id, 'name' => 'Main', 'address' => '123 Main St', 'is_active' => true]);
        $medicine = Medicine::create(['name' => 'Reported Medicine', 'is_active' => true]);

        $this->postJson('/api/admin/pharmacy-availability', [
            'pharmacy_branch_id' => $branch->id,
            'medicine_id' => $medicine->id,
            'is_available' => true,
            'price' => 9.99,
        ])->assertCreated();

        $public = $this->getJson("/api/medicines/{$medicine->id}/availability")->assertOk();
        $this->assertCount(1, $public->json('data.available_at'));

        // Re-reporting the SAME branch+medicine pair must update the single
        // existing row, not create a duplicate (enforced by the migration's
        // unique constraint and the controller's updateOrCreate()).
        $this->postJson('/api/admin/pharmacy-availability', [
            'pharmacy_branch_id' => $branch->id,
            'medicine_id' => $medicine->id,
            'is_available' => false,
        ])->assertCreated();

        $this->assertSame(1, PharmacyMedicineAvailability::count());

        $afterUnavailable = $this->getJson("/api/medicines/{$medicine->id}/availability")->assertOk();
        $this->assertSame([], $afterUnavailable->json('data.available_at'));

        $row = PharmacyMedicineAvailability::first();
        $this->putJson("/api/admin/pharmacy-availability/{$row->id}", ['is_available' => true, 'price' => 5])
            ->assertOk();

        $this->deleteJson("/api/admin/pharmacy-availability/{$row->id}")->assertOk();
        $this->assertDatabaseMissing('pharmacy_medicine_availability', ['id' => $row->id]);
    }

    public function test_non_admin_roles_cannot_reach_any_pharmacy_admin_write_endpoint(): void
    {
        $pharmacy = Pharmacy::create(['name' => 'Locked Pharmacy', 'is_active' => true]);
        $branch = PharmacyBranch::create(['pharmacy_id' => $pharmacy->id, 'name' => 'Locked Branch', 'address' => '123 Main St', 'is_active' => true]);
        $medicine = Medicine::create(['name' => 'Locked Medicine', 'is_active' => true]);

        foreach (['patient', 'doctor', 'caregiver'] as $role) {
            $user = User::create([
                'first_name' => 'NoAccess', 'last_name' => uniqid($role),
                'name' => 'No Access', 'email' => uniqid('noaccess'.$role).'@riva.test',
                'password' => 'password', 'role' => $role, 'is_active' => true,
                'profile_completed_at' => now(),
            ]);
            Sanctum::actingAs($user);

            $this->postJson('/api/admin/pharmacies', ['name' => 'Blocked'])->assertForbidden();
            $this->putJson("/api/admin/pharmacies/{$pharmacy->id}", ['name' => 'Blocked'])->assertForbidden();
            $this->deleteJson("/api/admin/pharmacies/{$pharmacy->id}")->assertForbidden();
            $this->postJson("/api/admin/pharmacies/{$pharmacy->id}/branches", ['name' => 'Blocked'])->assertForbidden();
            $this->putJson("/api/admin/pharmacy-branches/{$branch->id}", ['name' => 'Blocked'])->assertForbidden();
            $this->postJson('/api/admin/medicines', ['name' => 'Blocked'])->assertForbidden();
            $this->postJson('/api/admin/pharmacy-availability', [
                'pharmacy_branch_id' => $branch->id, 'medicine_id' => $medicine->id, 'is_available' => true,
            ])->assertForbidden();
        }

        $this->assertDatabaseHas('pharmacies', ['id' => $pharmacy->id, 'name' => 'Locked Pharmacy']);
        $this->assertDatabaseMissing('pharmacies', ['name' => 'Blocked']);
        $this->assertSame(0, PharmacyMedicineAvailability::count());
    }

    public function test_unauthenticated_requests_are_rejected_not_silently_allowed(): void
    {
        $this->postJson('/api/admin/pharmacies', ['name' => 'Nope'])->assertUnauthorized();
    }

    private function makeAdmin(): User
    {
        return User::create([
            'first_name' => 'Pharmacy', 'last_name' => 'Admin', 'name' => 'Pharmacy Admin',
            'email' => uniqid('pharmacyadmin').'@riva.test', 'password' => 'password',
            'role' => 'admin', 'is_active' => true, 'profile_completed_at' => now(),
        ]);
    }
}
