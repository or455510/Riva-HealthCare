<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SECURITY / RELIABILITY (Phase 0 / S1): EmergencyAlertController could previously
 * fail to deliver on every channel (email + WhatsApp) while still returning success
 * to the patient, with the only evidence being a warning-level log line. Delivery is
 * now handled by a queued, retrying job; these columns let a fully-exhausted
 * delivery failure be queried and surfaced instead of only existing in log files.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('emergency_alerts')) {
            return;
        }

        Schema::table('emergency_alerts', function (Blueprint $table) {
            if (! Schema::hasColumn('emergency_alerts', 'delivery_attempts')) {
                $table->unsignedTinyInteger('delivery_attempts')->default(0)->after('whatsapp_sent_at');
            }

            if (! Schema::hasColumn('emergency_alerts', 'delivery_failed_at')) {
                $table->timestamp('delivery_failed_at')->nullable()->after('delivery_attempts');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('emergency_alerts')) {
            return;
        }

        Schema::table('emergency_alerts', function (Blueprint $table) {
            foreach (['delivery_failed_at', 'delivery_attempts'] as $column) {
                if (Schema::hasColumn('emergency_alerts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
