# RIVA-133: Profile page: no delete-photo endpoint, no password-change UI, caregiver bio silently discarded, admin gets phantom Patient row

- **ID:** RIVA-133
- **Title:** Profile page: no delete-photo endpoint, no password-change UI, caregiver bio silently discarded, admin gets phantom Patient row
- **Status:** INBOX
- **Risk Level:** LOW
- **Assigned:** UNASSIGNED
- **Reviewer:** UNASSIGNED
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-25T01:22:50.454Z
- **Updated:** 2026-08-25T01:22:50.454Z

## Objective
Fix four real gaps found auditing the redesigned /myprofile page

## Business Reason
Password change is a real backend feature nobody can reach from the UI; caregivers silently lose their Bio text on every save; admins get a bogus Patient record created

## Technical Requirements
ProfileController::changePassword exists but no frontend wires it; updateCaregiverProfile() writes bio into the about column as a fallback so it is overwritten whenever about is also submitted; saveProfile()'s role match defaults to Patient for any non-doctor/non-caregiver role; no delete-avatar endpoint exists so removeImage() only works pre-save

## Acceptance Criteria
Password-change form added to Personal tab and wired to PUT /profile/password; caregiver bio persisted to its own column; admin save no longer creates a Patient row; delete-avatar endpoint added or the remove-photo action is removed entirely if intentionally out of scope
