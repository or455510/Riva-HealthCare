<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 1 (Identity + Tenancy): the tenant root. Nothing in the current schema
 * represents a real tenant boundary — Hospital is an optional, admin-managed
 * physical-location reference (a doctor's "workplace" label shown on their
 * profile), not a data-isolation boundary; nothing scopes queries by it, and it
 * has no ownership/membership concept. Clinic is deliberately a new, separate
 * concept rather than repurposing Hospital, so existing Hospital rows and their
 * meaning are left completely untouched.
 *
 * A "solo doctor" (today's only real user type that owns patients) becomes a
 * clinic-of-one: see the backfill migration immediately after this one. This
 * migration only creates the table — additive, no existing table is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clinics')) {
            Schema::create('clinics', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->enum('status', ['active', 'suspended'])->default('active');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('clinics');
    }
};
