<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('medication_logs') || DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("
            ALTER TABLE medication_logs
            MODIFY status ENUM('pending', 'taken', 'missed', 'snoozed', 'skipped', 'need_help') NULL
        ");
    }

    public function down(): void
    {
        //
    }
};
