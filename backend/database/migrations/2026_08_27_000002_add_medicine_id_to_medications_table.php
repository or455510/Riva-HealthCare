<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RIVA-132 follow-up (product-owner request, recorded 2026-08-25): "shows
 * them the nearest pharmacies that have the medication they need." Until
 * now, PrescriptionScanController::register() computed a best-effort
 * Medicine-catalog match at creation time but never persisted it — the link
 * only existed in that one API response and was lost the moment the doctor
 * navigated away. This adds a real, nullable `medicine_id` so any medication
 * (scan-registered OR manually added by the patient via
 * MedicationController::store()) can be re-matched against the real
 * pharmacy catalog every time the patient views their medication list, not
 * only once at creation. Nullable + nullOnDelete: a medication with no
 * catalog match, or whose matched catalog entry is later removed, honestly
 * has no medicine_id rather than a fabricated one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medications', function (Blueprint $table) {
            $table->foreignId('medicine_id')->nullable()->after('name')->constrained('medicines')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('medications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('medicine_id');
        });
    }
};
