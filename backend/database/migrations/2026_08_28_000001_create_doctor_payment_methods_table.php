<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RIVA-130 follow-up (product-owner request, recorded 2026-08-25): "let the
 * DOCTOR be the one who chooses which payment method(s) they personally
 * accept, based on what's actually available to them — never a fixed set
 * shown to every patient." There is no real payment gateway integration
 * anywhere in this codebase (see PaymentGatewayInterface's docblock and
 * ManualPaymentGateway) — this table does not process payments, it records
 * which real-world payment rails (Vodafone Cash, Orange Cash, Etisalat
 * Cash, InstaPay, card, cash-at-clinic) a doctor personally accepts and the
 * account detail (phone number / handle) a patient should send to, so the
 * booking/payment page can show honest, specific instructions instead of a
 * vague "pay at clinic" line. PaymentMethodController::CATALOG is the fixed,
 * real list of Egyptian payment rails the app KNOWS about; this table is
 * which of those a specific doctor has chosen to expose to patients, and
 * with what account detail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->string('method');
            $table->string('account_label')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['doctor_id', 'method']);
            $table->index(['doctor_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_payment_methods');
    }
};
