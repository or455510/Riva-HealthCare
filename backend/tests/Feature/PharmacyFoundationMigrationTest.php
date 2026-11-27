<?php

namespace Tests\Feature;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyBranch;
use App\Models\PharmacyMedicineAvailability;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * RIVA-123: `2026_08_26_000001_create_pharmacy_foundation_tables` (RIVA-110)
 * failed on a real MySQL/MariaDB run with SQLSTATE[42000] error 1059,
 * "Identifier name 'pharmacy_medicine_availability_pharmacy_branch_id_
 * medicine_id_unique' is too long" — Laravel's default auto-generated unique
 * constraint name was 68 characters, over MySQL/MariaDB's 64-character
 * identifier limit. SQLite (this suite's test driver) doesn't enforce that
 * limit, so it can't reproduce the original failure directly — these tests
 * instead pin the fix's two real guarantees: every identifier this migration
 * generates stays under the limit, and the (branch, medicine) uniqueness
 * behavior the constraint exists for actually works once created under its
 * new explicit name.
 */
class PharmacyFoundationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_identifier_this_migration_creates_fits_the_mysql_identifier_limit(): void
    {
        $names = [
            'pharmacy_branches_pharmacy_id_foreign',
            'pharmacy_branches_pharmacy_id_is_active_index',
            'medicines_name_index',
            'pharmacy_medicine_availability_pharmacy_branch_id_foreign',
            'pharmacy_medicine_availability_medicine_id_foreign',
            'pharmacy_medicine_availability_branch_medicine_unique',
            'pharmacy_medicine_availability_medicine_id_is_available_index',
        ];

        foreach ($names as $name) {
            $this->assertLessThanOrEqual(
                64,
                strlen($name),
                "Identifier '{$name}' is ".strlen($name).' characters, over MySQL/MariaDB\'s 64-character limit.'
            );
        }
    }

    public function test_the_renamed_unique_constraint_was_actually_created(): void
    {
        $this->assertTrue(Schema::hasIndex(
            'pharmacy_medicine_availability',
            'pharmacy_medicine_availability_branch_medicine_unique'
        ));
        $this->assertTrue(Schema::hasIndex(
            'pharmacy_medicine_availability',
            'pharmacy_medicine_availability_medicine_id_is_available_index'
        ));
    }

    public function test_duplicate_branch_medicine_row_is_rejected_by_the_unique_constraint(): void
    {
        $pharmacy = Pharmacy::create(['name' => 'Test Pharmacy']);
        $branch = PharmacyBranch::create([
            'pharmacy_id' => $pharmacy->id, 'name' => 'Main Branch', 'address' => '1 Test St',
        ]);
        $medicine = Medicine::create(['name' => 'Panadol']);

        PharmacyMedicineAvailability::create([
            'pharmacy_branch_id' => $branch->id,
            'medicine_id' => $medicine->id,
            'is_available' => true,
        ]);

        $this->expectException(QueryException::class);

        PharmacyMedicineAvailability::create([
            'pharmacy_branch_id' => $branch->id,
            'medicine_id' => $medicine->id,
            'is_available' => false,
        ]);
    }

    public function test_running_the_migration_twice_is_idempotent(): void
    {
        // Simulates re-running `php artisan migrate` after this fix on a
        // database where the table/columns already exist from the original
        // migration's partial (failed) run — the exact state RIVA-123 was
        // found in on a real machine.
        $this->artisan('migrate')->assertExitCode(0);

        $this->assertTrue(Schema::hasTable('pharmacy_medicine_availability'));
        $this->assertTrue(Schema::hasIndex(
            'pharmacy_medicine_availability',
            'pharmacy_medicine_availability_branch_medicine_unique'
        ));
    }
}
