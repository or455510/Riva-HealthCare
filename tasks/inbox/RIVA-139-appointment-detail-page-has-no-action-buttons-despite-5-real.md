# RIVA-139: Appointment detail page has no action buttons despite 5 real wired backend actions existing (confirm/cancel/complete/pay/failPayment)

- **ID:** RIVA-139
- **Title:** Appointment detail page has no action buttons despite 5 real wired backend actions existing (confirm/cancel/complete/pay/failPayment)
- **Status:** INBOX
- **Risk Level:** LOW
- **Assigned:** UNASSIGNED
- **Reviewer:** UNASSIGNED
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-25T01:42:27.958Z
- **Updated:** 2026-08-25T01:42:27.958Z

## Objective
Wire the appointment detail page to the real actions its backend already supports

## Business Reason
A doctor/patient/staff member opening a specific appointment cannot confirm, cancel, complete, or record payment from that screen at all, despite every one of those actions being a real, permission-checked, working backend endpoint

## Technical Requirements
AppointmentController exposes confirm/cancel/complete/pay/failPayment (routes/api.php), all real and permission-gated via authorizeAppointment()/canRecordPayment(); appointment-detail.component.ts's show() call never triggers any of them; show() also doesn't eager-load the payments relation, so no payment history/audit trail can be shown here even though Payment rows exist

## Acceptance Criteria
Real action buttons added (permission-gated to match the backend's own checks, not just hidden client-side), payments relation eager-loaded if a payment history section is wanted
