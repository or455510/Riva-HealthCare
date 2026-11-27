# RIVA-134: Patient dashboard: daily check-in file upload silently discarded, no appointments/messages widgets

- **ID:** RIVA-134
- **Title:** Patient dashboard: daily check-in file upload silently discarded, no appointments/messages widgets
- **Status:** INBOX
- **Risk Level:** MEDIUM
- **Assigned:** UNASSIGNED
- **Reviewer:** UNASSIGNED
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-25T01:29:33.185Z
- **Updated:** 2026-08-25T01:29:33.185Z

## Objective
Fix a real data-loss bug and scope two missing patient-portal widgets

## Business Reason
Patients attaching a photo/PDF to their daily check-in believe it was saved; it is not. Real patient portals also show upcoming appointments and messages on the home screen

## Technical Requirements
DailyStatusController::store's validator has no attachment/file rule and DailyStatus has no file column or storage call — the FormData attachment sent by dashboard-p.component.ts is silently dropped server-side

## Acceptance Criteria
Either DailyStatus gains real file storage + validation, or the upload control is removed from the UI until it does; appointments/messages widgets scoped as separate follow-up work if wanted
