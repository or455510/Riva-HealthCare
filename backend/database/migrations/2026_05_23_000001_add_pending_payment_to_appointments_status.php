<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE appointments MODIFY status ENUM('pending_payment','pending','confirmed','cancelled','completed') NOT NULL DEFAULT 'pending_payment'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("UPDATE appointments SET status = 'pending' WHERE status = 'pending_payment'");
            DB::statement("ALTER TABLE appointments MODIFY status ENUM('pending','confirmed','cancelled','completed') NOT NULL DEFAULT 'pending'");
        }
    }
};
