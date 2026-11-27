# RIVA-106: SECURITY: fix ReportController::index() unmatched-role IDOR

- **ID:** RIVA-106
- **Title:** SECURITY: fix ReportController::index() unmatched-role IDOR
- **Status:** DONE
- **Risk Level:** HIGH
- **Assigned:** Claude
- **Reviewer:** Codex
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-24T12:23:29.741Z
- **Updated:** 2026-08-24T12:23:33.634Z

## Objective
Add explicit admin branch + deny-by-default else to ReportController::index(), mirroring the already-fixed AppointmentController::index() pattern.

## Business Reason
Audit P1-3: an unmatched role (or a matched role with a missing profile relation) fell through to an unfiltered Report query — every patient's medical report exposed to that caller.

## Technical Requirements
ReportController.php.

## Acceptance Criteria
tests/Feature/ReportListScopingTest.php passes; full suite green
