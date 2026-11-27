# RIVA-140: patient-cards: avatar URLs hardcoded to 127.0.0.1:8000, will break in any non-local environment; doctor/caregiver directories have no server-side search

- **ID:** RIVA-140
- **Title:** patient-cards: avatar URLs hardcoded to 127.0.0.1:8000, will break in any non-local environment; doctor/caregiver directories have no server-side search
- **Status:** INBOX
- **Risk Level:** LOW
- **Assigned:** UNASSIGNED
- **Reviewer:** UNASSIGNED
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-25T01:47:37.635Z
- **Updated:** 2026-08-25T01:47:37.635Z

## Objective
Fix a real hardcoded-localhost bug and scope directory search

## Business Reason
Patient photos on the doctor's 'My Patients' list are built from a hardcoded http://127.0.0.1:8000/storage/ prefix instead of the app's real STORAGE_BASE_URL constant — this will silently break every patient photo the moment the app runs anywhere other than localhost. Separately, neither the doctor directory nor the caregiver directory supports search/filtering server-side, so no real search box can be added to those pages yet

## Technical Requirements
patient-cards.component.ts's resolveImageUrl() hardcodes the 127.0.0.1:8000 origin instead of importing STORAGE_BASE_URL from constants.ts, unlike every other redesigned page; DoctorController::index() and CaregiverController::index() both ignore all query params

## Acceptance Criteria
resolveImageUrl() uses the shared STORAGE_BASE_URL constant; decide whether search/filter is in scope for the doctor/caregiver directories and add query-param support server-side if so
