# RIVA-131: Payment is a state machine (pay/fail/status), not a real payment gateway

- **ID:** RIVA-131
- **Title:** Payment is a state machine (pay/fail/status), not a real payment gateway
- **Status:** INBOX
- **Risk Level:** MEDIUM
- **Assigned:** UNASSIGNED
- **Reviewer:** UNASSIGNED
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-25T01:13:25.080Z
- **Updated:** 2026-08-25T01:13:25.080Z

## Objective
Investigate and scope real payment gateway integration

## Business Reason
User asked where the real payment gateway is; doctors will not adopt a product that cannot actually charge patients

## Technical Requirements
AppointmentController::pay()/failPayment()/paymentStatus() + PaymentService only flip payment_status and log a Payment row locally — no Stripe/Paymob/Fawry/etc integration exists

## Acceptance Criteria
Decision + scoped plan recorded (candidate gateway chosen, sandbox keys, webhook handling)
