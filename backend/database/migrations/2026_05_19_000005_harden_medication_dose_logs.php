<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medications', function (Blueprint $table) {
            if (!Schema::hasColumn('medications', 'start_date')) {
                $table->date('start_date')->nullable()->after('frequency');
            }
            if (!Schema::hasColumn('medications', 'end_date')) {
                $table->date('end_date')->nullable()->after('start_date');
            }
        });

        Schema::table('medication_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('medication_logs', 'snoozed_until')) {
                $table->dateTime('snoozed_until')->nullable()->after('missed_at');
            }
            if (!Schema::hasColumn('medication_logs', 'missed_notification_sent_at')) {
                $table->dateTime('missed_notification_sent_at')->nullable()->after('snoozed_until');
            }
        });

        DB::table('medication_logs')
            ->whereNotIn('id', function ($query) {
                $query->from('medication_logs')
                    ->selectRaw('MIN(id)')
                    ->groupBy('patient_id', 'medication_id')
                    ->groupByRaw('COALESCE(scheduled_for_date, DATE(scheduled_at))')
                    ->groupByRaw('COALESCE(scheduled_time, TIME(scheduled_at))');
            })
            ->delete();

        if (!Schema::hasIndex('medication_logs', 'medication_logs_unique_dose')) {
            Schema::table('medication_logs', function (Blueprint $table) {
                $table->unique(
                    ['patient_id', 'medication_id', 'scheduled_for_date', 'scheduled_time'],
                    'medication_logs_unique_dose'
                );
            });
        }
    }

    public function down(): void
    {
        //
    }
};
