<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('daily_statuses')) {
            Schema::create('daily_statuses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
                $table->timestamps();
            });
        }

        Schema::table('daily_statuses', function (Blueprint $table) {
            if (!Schema::hasColumn('daily_statuses', 'date')) {
                $table->date('date')->nullable()->after('patient_id');
            }
            if (!Schema::hasColumn('daily_statuses', 'sleep_hours')) {
                $table->integer('sleep_hours')->nullable();
            }
            if (!Schema::hasColumn('daily_statuses', 'weight')) {
                $table->decimal('weight', 5, 2)->nullable();
            }
            if (!Schema::hasColumn('daily_statuses', 'blood_pressure_systolic')) {
                $table->integer('blood_pressure_systolic')->nullable();
            }
            if (!Schema::hasColumn('daily_statuses', 'blood_pressure_diastolic')) {
                $table->integer('blood_pressure_diastolic')->nullable();
            }
            if (!Schema::hasColumn('daily_statuses', 'temperature')) {
                $table->decimal('temperature', 4, 1)->nullable();
            }
            if (!Schema::hasColumn('daily_statuses', 'heart_rate')) {
                $table->integer('heart_rate')->nullable();
            }
            if (!Schema::hasColumn('daily_statuses', 'additional_data')) {
                $table->json('additional_data')->nullable();
            }
        });
    }

    public function down(): void
    {
        // The base domain migration owns the table lifecycle.
    }
};
