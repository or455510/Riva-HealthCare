<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase C (Queue + Appointment operational workflow, principal-engineering
 * directive): the day-of-visit queue lifecycle is deliberately modeled as
 * additional state on the existing Appointment record rather than a new
 * "Queue" concept/table. An appointment already IS the thing that gets
 * queued — there is exactly one queue entry per appointment, on the day it
 * is served, so a separate table would only add join overhead for no real
 * modeling benefit and would risk becoming a second, parallel appointment
 * concept (explicitly forbidden by the directive).
 *
 * queue_status is intentionally a separate field from the existing
 * `status` (payment/confirmation lifecycle: pending_payment -> pending ->
 * confirmed -> completed, or cancelled at any point) — a confirmed
 * appointment scheduled for next week has no queue state at all yet;
 * queue_status only becomes non-null once a patient is actually checked in
 * on the day of their visit. When a queue visit concludes normally,
 * QueueController also sets the existing `status` column to 'completed' —
 * reusing the existing field rather than duplicating "done" as a second
 * concept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            if (! Schema::hasColumn('appointments', 'queue_status')) {
                $table->enum('queue_status', ['waiting', 'called', 'in_service', 'completed', 'no_show', 'skipped'])
                    ->nullable()
                    ->after('status');
            }
            if (! Schema::hasColumn('appointments', 'queue_position')) {
                $table->unsignedInteger('queue_position')->nullable()->after('queue_status');
            }
            if (! Schema::hasColumn('appointments', 'checked_in_at')) {
                $table->timestamp('checked_in_at')->nullable()->after('queue_position');
            }
            if (! Schema::hasColumn('appointments', 'called_at')) {
                $table->timestamp('called_at')->nullable()->after('checked_in_at');
            }
            if (! Schema::hasColumn('appointments', 'queue_completed_at')) {
                $table->timestamp('queue_completed_at')->nullable()->after('called_at');
            }
            $table->index(['doctor_id', 'appointment_date', 'queue_status'], 'appointments_queue_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex('appointments_queue_lookup_index');
            $table->dropColumn(['queue_status', 'queue_position', 'checked_in_at', 'called_at', 'queue_completed_at']);
        });
    }
};
