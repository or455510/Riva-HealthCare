<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * File-serving slice's sibling gap, from the same principal-engineering directive:
 * AppointmentController::pay() (see its docblock, Phase 0) already closed the
 * client-trust hole — only an admin can attest a payment was received — but that
 * fix left appointments.payment_status as the ONLY record of a payment ever
 * happening: no transaction record, no amount-at-time-of-charge snapshot separate
 * from the appointment's current amount, no gateway/reference/failure-reason
 * trail, nothing to refund against. This table is that missing layer: one row per
 * attempted charge (not per appointment), so retries, failures, and eventual
 * refunds are all independently auditable instead of overwriting a single status
 * column. See app/Services/Payments/PaymentGatewayInterface.php for the
 * corresponding abstraction boundary (a swappable interface so a real gateway —
 * Stripe/Paymob/Fawry — can be wired in later without touching controllers).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payments')) {
            return;
        }

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained('appointments')->cascadeOnDelete();
            // Snapshot of the amount actually charged on THIS attempt — deliberately
            // separate from appointments.amount (which reflects the doctor's current
            // fee and could theoretically change between attempts), so a payment
            // record always reflects what was actually charged at charge time.
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('EGP');
            $table->enum('status', ['pending', 'succeeded', 'failed', 'refunded'])->default('pending');
            // 'manual' today (the admin-attestation interim design) — a real
            // integration would set this to 'stripe'/'paymob'/etc. Never client-set;
            // always chosen server-side by which PaymentGatewayInterface binding is
            // active.
            $table->string('gateway', 32)->default('manual');
            $table->string('gateway_reference')->nullable();
            $table->text('failure_reason')->nullable();
            $table->decimal('refunded_amount', 10, 2)->default(0);
            // Who attested/triggered this charge (the admin recording a manual
            // payment today; a real gateway driver may leave this null for a
            // webhook-driven confirmation later).
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['appointment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
