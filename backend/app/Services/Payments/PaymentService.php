<?php

namespace App\Services\Payments;

use App\Models\Appointment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Payment architecture (principal-engineering directive): the only place that
 * turns a PaymentGatewayResult into a persisted Payment row and reconciles
 * appointments.payment_status/status against it — AppointmentController calls
 * this instead of writing to those columns directly, so the audit trail (one row
 * per attempt) and the appointment's summary status can never drift apart.
 */
class PaymentService
{
    public function __construct(private readonly PaymentGatewayInterface $gateway) {}

    /**
     * Records and attempts a charge for $appointment. Snapshots the amount to
     * charge from the appointment at the moment of the call (not trusted from
     * the client — callers must not accept an amount from the request).
     */
    public function charge(Appointment $appointment, User $recordedBy): Payment
    {
        return DB::transaction(function () use ($appointment, $recordedBy) {
            $payment = Payment::create([
                'appointment_id' => $appointment->id,
                'amount' => $appointment->amount,
                'currency' => 'EGP',
                'status' => 'pending',
                'gateway' => 'manual',
                'recorded_by' => $recordedBy->id,
            ]);

            $result = $this->attemptCharge($payment);

            $payment->update($result->succeeded
                ? ['status' => 'succeeded', 'gateway_reference' => $result->gatewayReference]
                : ['status' => 'failed', 'failure_reason' => $result->failureReason]);

            $appointment->update([
                'payment_status' => $result->succeeded ? 'paid' : 'failed',
                'status' => $result->succeeded ? 'confirmed' : 'pending_payment',
            ]);

            return $payment->fresh();
        });
    }

    /**
     * Records a payment attempt as failed without ever calling the gateway —
     * used by AppointmentController::failPayment() for an admin explicitly
     * marking a payment as failed (e.g. a manual attestation that did not
     * actually happen), as distinct from charge() failing on its own.
     */
    public function recordFailure(Appointment $appointment, User $recordedBy, string $reason): Payment
    {
        return DB::transaction(function () use ($appointment, $recordedBy, $reason) {
            $payment = Payment::create([
                'appointment_id' => $appointment->id,
                'amount' => $appointment->amount,
                'currency' => 'EGP',
                'status' => 'failed',
                'gateway' => 'manual',
                'failure_reason' => $reason,
                'recorded_by' => $recordedBy->id,
            ]);

            $appointment->update([
                'payment_status' => 'failed',
                'status' => 'pending_payment',
            ]);

            return $payment;
        });
    }

    private function attemptCharge(Payment $payment): PaymentGatewayResult
    {
        try {
            return $this->gateway->charge($payment);
        } catch (\Throwable $e) {
            // A gateway driver throwing (network error, malformed response, etc.)
            // must never bubble up as an unhandled 500 that leaves the caller
            // unsure whether money moved — treat it as an explicit failed
            // attempt, logged for investigation, same as any other decline.
            Log::error('Payment gateway threw during charge attempt', [
                'payment_id' => $payment->id,
                'appointment_id' => $payment->appointment_id,
                'exception' => $e->getMessage(),
            ]);

            return PaymentGatewayResult::failure('Gateway error: '.$e->getMessage());
        }
    }
}
