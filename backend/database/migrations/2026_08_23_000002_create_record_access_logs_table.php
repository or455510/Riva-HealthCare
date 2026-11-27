<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SECURITY (Phase 0 / S5): write-side activity logging already existed in places
 * (system_activity_logs, reviewed_by/reviewed_at columns) but there was no record
 * of who *read* a medical record — a doctor or caregiver whose relationship has
 * ended, or an admin, viewing a patient's reports/medications leaves no trace
 * today. This is a minimal, intentionally lightweight audit table: enough to
 * answer "who looked at this patient's data, and when" after the fact. It is not
 * a full accountable-audit/versioning system (that is Phase 1+ scope per the
 * Master Blueprint's Section F.10) — this is the Phase 0 floor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('actor_role', 32);
            $table->string('resource_type', 64);
            $table->unsignedBigInteger('resource_id');
            $table->unsignedBigInteger('patient_id')->nullable();
            $table->string('action', 32)->default('view');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['resource_type', 'resource_id']);
            $table->index(['patient_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_access_logs');
    }
};
