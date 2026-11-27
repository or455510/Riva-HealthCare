# RIVA-104: Composite indexes + uniqueness constraints

- **ID:** RIVA-104
- **Title:** Composite indexes + uniqueness constraints
- **Status:** DONE
- **Risk Level:** MEDIUM
- **Assigned:** Claude
- **Reviewer:** Codex
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-24T12:23:29.580Z
- **Updated:** 2026-08-24T12:23:33.322Z

## Objective
Add indexes on patient_doctor_relationships/patient_caregiver_relationships/users/messages/medication_logs and unique constraints on group_chat_members/daily_statuses; fix the updateOrCreate date-matching bug the constraint exposed.

## Business Reason
Audit P1-1: highest-ROI index fix, benefits nearly every authenticated request.

## Technical Requirements
database/migrations/2026_08_24_000001_add_cost_scalability_indexes.php, GroupChatController.php, DailyStatusController.php.

## Acceptance Criteria
tests/Feature/CostScalabilityIndexesTest.php passes; full suite green
