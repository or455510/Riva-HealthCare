# RIVA-107: Fix DoctorController::index() N+1

- **ID:** RIVA-107
- **Title:** Fix DoctorController::index() N+1
- **Status:** DONE
- **Risk Level:** LOW
- **Assigned:** Claude
- **Reviewer:** Codex
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-24T12:23:29.807Z
- **Updated:** 2026-08-24T12:23:33.784Z

## Objective
Batch-load the viewing patient's PatientDoctorRelationship rows once instead of per-doctor in transformDoctor().

## Business Reason
Audit P1-4: the single worst N+1 in the codebase, on a public, unpaginated endpoint.

## Technical Requirements
DoctorController.php.

## Acceptance Criteria
tests/Feature/DoctorListingCostTest.php passes; full suite green
