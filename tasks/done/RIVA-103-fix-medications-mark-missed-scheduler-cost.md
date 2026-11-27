# RIVA-103: Fix medications:mark-missed scheduler cost

- **ID:** RIVA-103
- **Title:** Fix medications:mark-missed scheduler cost
- **Status:** DONE
- **Risk Level:** MEDIUM
- **Assigned:** Claude
- **Reviewer:** Codex
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-24T12:23:29.517Z
- **Updated:** 2026-08-24T12:23:33.167Z

## Objective
Replace todaySchedule()'s 7-day-lookback pre-pass with ensureTodayLogs(); chunk the patient query; add withoutOverlapping().

## Business Reason
Audit P0-3: unchunked full-scan + 7-day schedule rebuild every 60 seconds forever — the highest-growth-rate cost risk in the audit.

## Technical Requirements
MedicationAdherenceService::ensureTodayLogs(), routes/console.php medications:mark-missed.

## Acceptance Criteria
tests/Feature/MedicationSchedulerCostTest.php passes; full suite green
