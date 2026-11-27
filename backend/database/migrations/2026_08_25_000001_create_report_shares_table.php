<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RIVA-142: patient-controlled cross-doctor report sharing. A row here means
 * "the patient who owns this report explicitly chose to let this doctor see
 * it" — distinct from PatientDoctorRelationship, which already grants a
 * doctor access to every report THEY wrote for a patient. This table only
 * ever grants access to reports written by a DIFFERENT doctor. Only the
 * owning patient can create/delete rows here (enforced in
 * ReportController::shareReport()/unshareReport()), never a doctor or
 * caregiver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('reports')->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['report_id', 'doctor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_shares');
    }
};
