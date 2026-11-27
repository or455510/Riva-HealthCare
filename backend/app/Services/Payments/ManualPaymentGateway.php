<?php

namespace App\Services\Payments;

use App\Models\Payment;

/**
 * Payment architecture (principal-engineering directive): the only
 * PaymentGatewayInterface binding today, because no real gateway credentials
 * (Stripe/Paymob/Fawry, or equivalent) exist to integrate against. This is
 * deliberately NOT a fake gateway pretending to be real — it is an honest
 * representation of the app's actual current payment model, carried over
 * unchanged from AppointmentController::pay()'s Phase 0 fix: only an
 * authenticated platform admin can call this path (enforced by
 * AppointmentController::canRecordPayment(), unchanged by this slice), acting as
 * a manual attestation that money was received outside the system (cash, bank
 * transfer, etc.) — there is no card/wallet number, no gateway API call, and
 * charge() below performs no external network I/O. What changes in THIS slice
 * is that the attestation now produces a real, auditable Payment row (via
 * PaymentService) instead of only flipping appointments.payment_status, and the
 * gateway_reference recorded here makes it unambiguous, later, that this
 * particular charge was manually attested rather than gateway-confirmed.
 *
 * Swapping in a real gateway later means writing a new class implementing
 * PaymentGatewayInterface (its charge() would call the gateway's API/SDK using
 * real credentials and return success/failure from the gateway's actual
 * response) and changing one service-provider binding — no controller,
 * PaymentService, or Payment-model change required.
 */
class ManualPaymentGateway implements PaymentGatewayInterface
{
    public function charge(Payment $payment): PaymentGatewayResult
    {
        return PaymentGatewayResult::success('manual:'.$payment->id.':'.now()->timestamp);
    }

    public function refund(Payment $payment, ?float $amount = null): PaymentGatewayResult
    {
        return PaymentGatewayResult::success('manual-refund:'.$payment->id.':'.now()->timestamp);
    }
}
