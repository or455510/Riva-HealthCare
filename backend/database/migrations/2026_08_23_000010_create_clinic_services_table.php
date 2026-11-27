<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase C (Services & Prices, principal-engineering directive): the first
 * real backing for ClinicUser::PERMISSION_MANAGE_SERVICES — Slice 3 defined
 * the permission but nothing to manage existed yet. A clinic_services row
 * is a bookable, priced offering ("General Consultation", "Follow-up",
 * "Blood Pressure Check") distinct from Doctor::fee, which remains the
 * fallback price when no specific service is selected at booking time (see
 * AppointmentController::store()) — this is additive, not a breaking
 * change to the existing booking flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['clinic_id', 'is_active']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            // Nullable and appended after existing columns so every
            // appointment booked before this concept existed remains valid
            // with no backfill required — service_id simply stays null for
            // them, and the frontend/backend both already treat "no
            // service" as a normal, real state (falls back to Doctor::fee).
            $table->foreignId('service_id')->nullable()->after('doctor_id')
                ->constrained('clinic_services')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_id');
        });
        Schema::dropIfExists('clinic_services');
    }
};
