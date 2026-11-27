# RIVA-138: Booking flow said 'Payment successful' with no real payment gateway behind it — fixed to honest pending-payment copy; patients have no self-service cancel; no real payment gateway exists

- **ID:** RIVA-138
- **Title:** Booking flow said 'Payment successful' with no real payment gateway behind it — fixed to honest pending-payment copy; patients have no self-service cancel; no real payment gateway exists
- **Status:** INBOX
- **Risk Level:** MEDIUM
- **Assigned:** UNASSIGNED
- **Reviewer:** UNASSIGNED
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-25T01:42:27.905Z
- **Updated:** 2026-08-25T01:42:27.905Z

## Objective
Confirm the fake-success copy fix and scope real payment/cancel capability

## Business Reason
The booking confirmation screen was telling patients their payment succeeded when nothing was ever charged and payment_status stayed unpaid — the exact kind of fake success message this project forbids. Fixed to honest copy this session. Also: no real payment gateway (Stripe/Paymob/Fawry) exists anywhere — only a manual-attestation ManualPaymentGateway that clinic staff/admin use outside the patient app; and patients have no self-service cancel button because cancelAppointment() in the TS never calls the real cancel endpoint

## Technical Requirements
booking.component.html previously hardcoded 'Payment successful / Appointment confirmed' static text on step 3, contradicting the real payment_status column and PaymentService::charge()'s ManualPaymentGateway (no network call, just an audited DB record); POST /appointments/{id}/cancel exists and is real (AppointmentController) but booking.component.ts's cancelAppointment() only resets local wizard state

## Acceptance Criteria
Copy fix already shipped and verified via build+sync; separately decide/scope a real payment gateway integration (this overlaps RIVA-131) and wire booking's cancel button to the real cancel endpoint
