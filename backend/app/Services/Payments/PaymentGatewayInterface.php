<?php

namespace App\Services\Payments;

use App\Models\Payment;

/**
 * Payment architecture (principal-engineering directive): the abstraction
 * boundary a real gateway integration (Stripe/Paymob/Fawry — Egyptian-market
 * gateways are the realistic near-term choice given GEMINI/WhatsApp/Twilio are
 * already the app's other third-party integrations) slots into later without
 * touching AppointmentController or PaymentService. No real gateway credentials
 * exist yet, so ManualPaymentGateway is the only binding today — see its
 * docblock for why that is an honest interim design, not a fake one.
 *
 * Never call a gateway implementation directly from a controller — always go
 * through PaymentService, which is the only thing that persists a Payment row
 * from a PaymentGatewayResult.
 */
interface PaymentGatewayInterface
{
    /**
     * Attempt to charge for $payment (already persisted with status=pending and
     * an amount snapshot before this is called — see PaymentService::charge()).
     */
    public function charge(Payment $payment): PaymentGatewayResult;

    /**
     * Attempt to refund $amount (or the full remaining charged amount if null)
     * against an already-succeeded $payment.
     */
    public function refund(Payment $payment, ?float $amount = null): PaymentGatewayResult;
}
