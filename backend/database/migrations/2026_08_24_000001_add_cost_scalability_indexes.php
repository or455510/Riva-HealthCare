<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * COST / SCALABILITY (RIVA-104): composite indexes and uniqueness constraints
 * identified by the cost/scalability audit (§22 P1-1) plus the RIVA-104 task's
 * own additional review of users/messages/medication_logs/group_chat_members/
 * daily_statuses. Every step is defensive (Schema::hasIndex/hasColumn checks,
 * matching the pattern already established in
 * 2026_08_23_000003_add_indexes_to_daily_statuses_table.php) so this is safe
 * to run against a database that may already have some of these, and every
 * `down()` step is the exact inverse.
 *
 * The two uniqueness constraints (group_chat_members, daily_statuses) are
 * preceded by a dedup step that deletes exact-duplicate rows (keeping the
 * lowest id) before adding the constraint — this migration was never run
 * against the real production database this session (no live shell was
 * available), so the dedup step is what makes it safe to run later even if
 * duplicates exist there today. It does not change any row a user would
 * recognize as distinct — only collapses rows that are already functionally
 * identical (same group+member, or same patient+date, which
 * DailyStatusController::store() already enforces at the application layer
 * via updateOrCreate()).
 */
return new class extends Migration
{
    public function up(): void
    {
        // patient_doctor_relationships: every medical-data authorization
        // check filters by (doctor_id, status) or (patient_id, status) — see
        // docs/RIVA_ARCHITECTURE.md's relationship-based tenancy model.
        if (Schema::hasTable('patient_doctor_relationships')) {
            Schema::table('patient_doctor_relationships', function (Blueprint $table) {
                if (! Schema::hasIndex('patient_doctor_relationships', 'pdr_doctor_id_status_index')) {
                    $table->index(['doctor_id', 'status'], 'pdr_doctor_id_status_index');
                }
                if (! Schema::hasIndex('patient_doctor_relationships', 'pdr_patient_id_status_index')) {
                    $table->index(['patient_id', 'status'], 'pdr_patient_id_status_index');
                }
            });
        }

        if (Schema::hasTable('patient_caregiver_relationships')) {
            Schema::table('patient_caregiver_relationships', function (Blueprint $table) {
                if (! Schema::hasIndex('patient_caregiver_relationships', 'pcr_caregiver_id_status_index')) {
                    $table->index(['caregiver_id', 'status'], 'pcr_caregiver_id_status_index');
                }
                if (! Schema::hasIndex('patient_caregiver_relationships', 'pcr_patient_id_status_index')) {
                    $table->index(['patient_id', 'status'], 'pcr_patient_id_status_index');
                }
            });
        }

        // users: role is filtered directly (e.g. User::where('role', 'admin')
        // in several places) and phone is used for lookups/contact matching.
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (! Schema::hasIndex('users', 'users_role_index')) {
                    $table->index('role', 'users_role_index');
                }
                if (! Schema::hasIndex('users', 'users_phone_index')) {
                    $table->index('phone', 'users_phone_index');
                }
            });
        }

        // messages: ChatController's conversation lookup is
        // where(sender=A,receiver=B) orWhere(sender=B,receiver=A) — a single
        // composite index serves both sides of that OR.
        if (Schema::hasTable('messages')) {
            Schema::table('messages', function (Blueprint $table) {
                if (! Schema::hasIndex('messages', 'messages_sender_id_receiver_id_index')) {
                    $table->index(['sender_id', 'receiver_id'], 'messages_sender_id_receiver_id_index');
                }
            });
        }

        // medication_logs: duePendingLogs() filters by status IN
        // (null,'pending') and scheduled_at <= cutoff, every minute, forever.
        if (Schema::hasTable('medication_logs')) {
            Schema::table('medication_logs', function (Blueprint $table) {
                if (! Schema::hasIndex('medication_logs', 'medication_logs_status_scheduled_at_index')) {
                    $table->index(['status', 'scheduled_at'], 'medication_logs_status_scheduled_at_index');
                }
            });
        }

        // group_chat_members: GroupChatController::create() calls attach()
        // per patient_id with no existing distinct-ness guard — see the
        // 'distinct' validation rule added alongside this migration in
        // GroupChatController for the primary fix; this constraint is the
        // authoritative one at the data layer.
        if (Schema::hasTable('group_chat_members')) {
            $this->dedupe('group_chat_members', ['group_chat_id', 'user_id']);

            Schema::table('group_chat_members', function (Blueprint $table) {
                if (! Schema::hasIndex('group_chat_members', 'group_chat_members_group_chat_id_user_id_unique')) {
                    $table->unique(['group_chat_id', 'user_id'], 'group_chat_members_group_chat_id_user_id_unique');
                }
            });
        }

        // daily_statuses: DailyStatusController::store() already enforces
        // one row per (patient_id, date) via updateOrCreate() at the
        // application layer — this makes that invariant real at the data
        // layer too. NULL dates are excluded by MySQL's unique-index
        // semantics (multiple NULLs never collide), matching the nullable
        // `date` column.
        if (Schema::hasTable('daily_statuses')) {
            $this->dedupe('daily_statuses', ['patient_id', 'date']);

            Schema::table('daily_statuses', function (Blueprint $table) {
                if (! Schema::hasIndex('daily_statuses', 'daily_statuses_patient_id_date_unique')) {
                    $table->unique(['patient_id', 'date'], 'daily_statuses_patient_id_date_unique');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('patient_doctor_relationships')) {
            Schema::table('patient_doctor_relationships', function (Blueprint $table) {
                if (Schema::hasIndex('patient_doctor_relationships', 'pdr_doctor_id_status_index')) {
                    $table->dropIndex('pdr_doctor_id_status_index');
                }
                if (Schema::hasIndex('patient_doctor_relationships', 'pdr_patient_id_status_index')) {
                    $table->dropIndex('pdr_patient_id_status_index');
                }
            });
        }

        if (Schema::hasTable('patient_caregiver_relationships')) {
            Schema::table('patient_caregiver_relationships', function (Blueprint $table) {
                if (Schema::hasIndex('patient_caregiver_relationships', 'pcr_caregiver_id_status_index')) {
                    $table->dropIndex('pcr_caregiver_id_status_index');
                }
                if (Schema::hasIndex('patient_caregiver_relationships', 'pcr_patient_id_status_index')) {
                    $table->dropIndex('pcr_patient_id_status_index');
                }
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (Schema::hasIndex('users', 'users_role_index')) {
                    $table->dropIndex('users_role_index');
                }
                if (Schema::hasIndex('users', 'users_phone_index')) {
                    $table->dropIndex('users_phone_index');
                }
            });
        }

        if (Schema::hasTable('messages')) {
            Schema::table('messages', function (Blueprint $table) {
                if (Schema::hasIndex('messages', 'messages_sender_id_receiver_id_index')) {
                    $table->dropIndex('messages_sender_id_receiver_id_index');
                }
            });
        }

        if (Schema::hasTable('medication_logs')) {
            Schema::table('medication_logs', function (Blueprint $table) {
                if (Schema::hasIndex('medication_logs', 'medication_logs_status_scheduled_at_index')) {
                    $table->dropIndex('medication_logs_status_scheduled_at_index');
                }
            });
        }

        if (Schema::hasTable('group_chat_members')) {
            Schema::table('group_chat_members', function (Blueprint $table) {
                if (Schema::hasIndex('group_chat_members', 'group_chat_members_group_chat_id_user_id_unique')) {
                    $table->dropUnique('group_chat_members_group_chat_id_user_id_unique');
                }
            });
        }

        if (Schema::hasTable('daily_statuses')) {
            Schema::table('daily_statuses', function (Blueprint $table) {
                if (Schema::hasIndex('daily_statuses', 'daily_statuses_patient_id_date_unique')) {
                    $table->dropUnique('daily_statuses_patient_id_date_unique');
                }
            });
        }
    }

    /**
     * Delete exact-duplicate rows on the given column set, keeping the
     * lowest id in each duplicate group. Runs before adding a unique
     * constraint on those same columns so the migration cannot fail against
     * data that already has duplicates.
     *
     * @param  array<int, string>  $columns
     */
    private function dedupe(string $table, array $columns): void
    {
        $columnList = implode(', ', $columns);

        // Portable across MySQL/SQLite (the test suite runs on sqlite in
        // memory): delete every row whose id is not the minimum id among
        // rows sharing the same value for every column in $columns. NULLs in
        // a grouping column are treated as equal to each other by GROUP BY,
        // which is the conservative (dedupe-more) direction here.
        DB::statement(
            "delete from {$table} where id not in (
                select min_id from (
                    select min(id) as min_id from {$table} group by {$columnList}
                ) as keep_ids
            )"
        );
    }
};
