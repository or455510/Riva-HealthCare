<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (Schema::hasTable('patient_doctor_relationships')) {
            DB::statement("ALTER TABLE patient_doctor_relationships MODIFY status ENUM('pending','active','accepted','rejected','ended') NOT NULL DEFAULT 'pending'");
        }

        if (Schema::hasTable('patient_caregiver_relationships')) {
            DB::statement("ALTER TABLE patient_caregiver_relationships MODIFY status ENUM('pending','active','accepted','rejected','ended') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (Schema::hasTable('patient_doctor_relationships')) {
            DB::table('patient_doctor_relationships')->where('status', 'accepted')->update(['status' => 'active']);
            DB::statement("ALTER TABLE patient_doctor_relationships MODIFY status ENUM('pending','active','rejected','ended') NOT NULL DEFAULT 'pending'");
        }

        if (Schema::hasTable('patient_caregiver_relationships')) {
            DB::table('patient_caregiver_relationships')->where('status', 'accepted')->update(['status' => 'active']);
            DB::statement("ALTER TABLE patient_caregiver_relationships MODIFY status ENUM('pending','active','rejected','ended') NOT NULL DEFAULT 'pending'");
        }
    }
};
