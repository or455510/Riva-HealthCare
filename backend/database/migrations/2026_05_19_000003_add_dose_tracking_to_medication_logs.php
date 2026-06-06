<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('medication_logs', 'scheduled_for_date')) {
                $table->date('scheduled_for_date')->nullable()->after('scheduled_at');
            }
            if (!Schema::hasColumn('medication_logs', 'scheduled_time')) {
                $table->time('scheduled_time')->nullable()->after('scheduled_for_date');
            }
            if (!Schema::hasColumn('medication_logs', 'missed_at')) {
                $table->dateTime('missed_at')->nullable()->after('taken_at');
            }
        });
    }

    public function down(): void
    {
        //
    }
};
