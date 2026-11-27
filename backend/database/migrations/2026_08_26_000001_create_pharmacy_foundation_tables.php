<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RIVA-110 (directive §16, minimal foundation only — explicitly NOT a full
 * pharmacy ERP). Four tables:
 *
 * - pharmacies: the pharmacy organization/chain itself.
 * - pharmacy_branches: physical locations belonging to a pharmacy.
 * - medicines: a general medicine CATALOG entry (name/form/strength) —
 *   deliberately separate from the existing `medications` table, which is a
 *   PATIENT's personal prescription record (dosage schedule, frequency,
 *   tied to a specific patient_id). Conflating the two would be wrong:
 *   "Panadol 500mg tablets exists as a catalog item" and "patient #42 takes
 *   Panadol at 08:00 daily" are different concepts.
 * - pharmacy_medicine_availability: whether a given branch currently
 *   reports a given medicine as available, and at what price. This is
 *   deliberately named "medicine" not "medication" for the same reason —
 *   and deliberately has NO seeded data anywhere in this codebase. Every
 *   row here can only come from a real pharmacy/admin actually entering it
 *   through the CRUD endpoints this migration's models support — there is
 *   no fabricated inventory, and the architecture is built so a future real
 *   pharmacy integration can populate/sync this same table without a
 *   schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pharmacies')) {
            Schema::create('pharmacies', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('phone')->nullable();
                $table->string('email')->nullable();
                $table->string('website')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('pharmacy_branches')) {
            Schema::create('pharmacy_branches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pharmacy_id')->constrained('pharmacies')->cascadeOnDelete();
                $table->string('name');
                $table->string('address');
                $table->string('city')->nullable();
                $table->string('phone')->nullable();
                // Nullable on purpose: a branch can exist in the directory
                // before its exact map coordinates are known — this is a
                // foundation, not a maps-integration deliverable.
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->boolean('is_24_hours')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['pharmacy_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('medicines')) {
            Schema::create('medicines', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('generic_name')->nullable();
                // e.g. tablet, syrup, injection, cream — a free-text form
                // field rather than an enum, since this is a foundation and
                // the real, complete set of forms a pharmacy might carry
                // isn't something to hard-code prematurely.
                $table->string('form')->nullable();
                $table->string('strength')->nullable();
                $table->boolean('requires_prescription')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index('name');
            });
        }

        if (! Schema::hasTable('pharmacy_medicine_availability')) {
            Schema::create('pharmacy_medicine_availability', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pharmacy_branch_id')->constrained('pharmacy_branches')->cascadeOnDelete();
                $table->foreignId('medicine_id')->constrained('medicines')->cascadeOnDelete();
                $table->boolean('is_available')->default(false);
                // Nullable: a branch may report "available" without
                // publishing a price yet (e.g. "call to confirm price") —
                // null must never be silently treated as "free"/zero by
                // any caller of this data.
                $table->decimal('price', 10, 2)->nullable();
                $table->timestamp('reported_at')->nullable();
                $table->timestamps();
            });
        }

        // RIVA-123: Laravel's default auto-generated name for this unique
        // constraint ("pharmacy_medicine_availability_pharmacy_branch_id_
        // medicine_id_unique", 68 characters) exceeds MySQL/MariaDB's
        // 64-character identifier limit and fails with SQLSTATE[42000]
        // error 1059 ("Identifier name ... is too long") — confirmed on a
        // real MySQL run, not a theoretical concern. Given an explicit short
        // name, and applied as a separate idempotent step (not inline in the
        // create() block above) so this also self-heals a database where an
        // earlier run of this same migration already created the table's
        // columns/foreign keys before failing on the too-long identifier —
        // exactly the state this migration was left in on first run.
        if (! Schema::hasIndex('pharmacy_medicine_availability', 'pharmacy_medicine_availability_branch_medicine_unique')) {
            Schema::table('pharmacy_medicine_availability', function (Blueprint $table) {
                // One row per (branch, medicine) — a branch reports a
                // single current availability state per medicine, updated
                // in place, not accumulated as a history log.
                $table->unique(
                    ['pharmacy_branch_id', 'medicine_id'],
                    'pharmacy_medicine_availability_branch_medicine_unique'
                );
            });
        }

        if (! Schema::hasIndex('pharmacy_medicine_availability', 'pharmacy_medicine_availability_medicine_id_is_available_index')) {
            Schema::table('pharmacy_medicine_availability', function (Blueprint $table) {
                $table->index(['medicine_id', 'is_available']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_medicine_availability');
        Schema::dropIfExists('medicines');
        Schema::dropIfExists('pharmacy_branches');
        Schema::dropIfExists('pharmacies');
    }
};
