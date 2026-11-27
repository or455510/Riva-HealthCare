# RIVA-102: Queue email sends + fix medication reminder recipient bug

- **ID:** RIVA-102
- **Title:** Queue email sends + fix medication reminder recipient bug
- **Status:** DONE
- **Risk Level:** MEDIUM
- **Assigned:** Claude
- **Reviewer:** Codex
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-24T12:23:29.453Z
- **Updated:** 2026-08-24T12:23:32.988Z

## Objective
Convert NotificationService::sendEmail and EmergencyAlertController dispatch to be queued/resilient; fix MedicationReminderController's hardcoded WhatsApp recipient.

## Business Reason
Audit P0-2/P0-5: sync mail blocks workers; emergency-alert dispatch failure could 500 a successfully-saved alert; medication reminders went to a hardcoded wrong number.

## Technical Requirements
NotificationService.php, EmergencyAlertController.php, MedicationReminderController.php, new Jobs/SendMedicationReminder.php.

## Acceptance Criteria
tests/Feature/MedicationReminderQueueTest.php, EmergencyAlertJobTest.php pass; full suite green
