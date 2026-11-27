<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RIVA-122: the pre-dose ("30 minutes before") medication reminder previously
 * had no server-side trigger at all — it existed only as a client-side
 * setTimeout in dashboard-p.component.ts, armed once per dashboard page load
 * and lost entirely if the patient closed/refreshed the tab before the
 * computed delay elapsed. This column lets a new scheduled command
 * (medications:send-reminders, see routes/console.php) track which dose logs
 * have already had a reminder sent, mirroring missed_notification_sent_at's
 * existing idempotency pattern for medications:mark-missed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('medication_logs', 'reminder_sent_at')) {
                $table->dateTime('reminder_sent_at')->nullable()->after('missed_notification_sent_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('medication_logs', function (Blueprint $table) {
            if (Schema::hasColumn('medication_logs', 'reminder_sent_at')) {
                $table->dropColumn('reminder_sent_at');
            }
        });
    }
};
