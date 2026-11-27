<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SECURITY/PERFORMANCE (Phase 0 / D2 follow-up): daily_statuses had no index on
 * risk_level or on (patient_id, created_at) despite both being queried directly and
 * repeatedly:
 *   - DailyStatus::where('risk_level', 'high_risk')->count() (DashboardController,
 *     Admin\AdminAreaController - admin-wide "high risk cases" counters)
 *   - DailyStatus::where('risk_level', 'high_risk')->distinct('patient_id')->count()
 *   - Admin\AdminAreaController::index()'s risk filter (whereIf('risk_level', ...))
 *   - $patient->dailyStatuses()->latest() (DashboardController, ReportController,
 *     DailyStatusController) — "most recent status per patient" is the single most
 *     common access pattern in this table and was doing a full table scan ordered by
 *     created_at with only a patient_id foreign-key index to narrow it.
 * This does not change any query results — it only makes the existing queries above
 * cheaper as the table grows past dev-scale row counts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('daily_statuses')) {
            return;
        }

        Schema::table('daily_statuses', function (Blueprint $table) {
            if (! Schema::hasIndex('daily_statuses', 'daily_statuses_risk_level_index')) {
                $table->index('risk_level');
            }

            if (! Schema::hasIndex('daily_statuses', 'daily_statuses_patient_id_created_at_index')) {
                $table->index(['patient_id', 'created_at']);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('daily_statuses')) {
            return;
        }

        Schema::table('daily_statuses', function (Blueprint $table) {
            if (Schema::hasIndex('daily_statuses', 'daily_statuses_risk_level_index')) {
                $table->dropIndex('daily_statuses_risk_level_index');
            }

            if (Schema::hasIndex('daily_statuses', 'daily_statuses_patient_id_created_at_index')) {
                $table->dropIndex('daily_statuses_patient_id_created_at_index');
            }
        });
    }
};
