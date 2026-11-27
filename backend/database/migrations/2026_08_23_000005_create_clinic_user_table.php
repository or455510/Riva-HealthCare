<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 1 (Identity + Tenancy + RBAC): membership + role-per-clinic. This is the
 * source of truth for "which clinics can this user act in, and as what role" —
 * deliberately NOT a single clinic_id column on users/doctors, because a person
 * can belong to more than one clinic (a doctor consulting at two practices, a
 * platform admin, a staff member helping cover another location). A user's
 * *current* clinic while using the app is a per-request context (see
 * ClinicContextService), not a fixed column.
 *
 * Patients are intentionally NOT modeled as clinic_user rows. Patient access to
 * medical data is already governed by PatientDoctorRelationship /
 * PatientCaregiverRelationship (existing, unchanged) — a patient's data becomes
 * visible to a clinic through their relationship with a doctor who is a member
 * of that clinic, not through direct clinic membership. Forcing patients into a
 * single-clinic membership model would break the existing (valid, preserved)
 * ability for one patient to have relationships with doctors across different
 * clinics.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clinic_user')) {
            Schema::create('clinic_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->enum('role', ['owner', 'admin', 'doctor', 'staff'])->default('staff');
                $table->enum('status', ['active', 'invited', 'suspended'])->default('active');
                $table->timestamp('invited_at')->nullable();
                $table->timestamp('joined_at')->nullable();
                $table->timestamps();

                $table->unique(['clinic_id', 'user_id']);
                $table->index(['user_id', 'status']);
                $table->index(['clinic_id', 'role']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_user');
    }
};
