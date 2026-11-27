<?php

namespace App\Services\Payments;

/**
 * Value object a PaymentGatewayInterface implementation returns for a charge or
 * refund attempt — deliberately separate from the Payment Eloquent model so a
 * gateway driver never has direct write access to the database; PaymentService
 * is the only thing that turns a result into a persisted Payment row.
 */
final class PaymentGatewayResult
{
    public function __construct(
        public readonly bool $succeeded,
        public readonly ?string $gatewayReference = null,
        public readonly ?string $failureReason = null,
    ) {}

    public static function success(?string $gatewayReference = null): self
    {
        return new self(true, $gatewayReference, null);
    }

    public static function failure(string $reason): self
    {
        return new self(false, null, $reason);
    }
}
