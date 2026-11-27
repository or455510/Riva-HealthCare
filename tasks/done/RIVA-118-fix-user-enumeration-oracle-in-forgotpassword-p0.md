# RIVA-118: Fix user-enumeration oracle in forgotPassword (P0)

- **ID:** RIVA-118
- **Title:** Fix user-enumeration oracle in forgotPassword (P0)
- **Status:** DONE
- **Risk Level:** MEDIUM
- **Assigned:** Claude
- **Reviewer:** Codex
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-24T13:11:38.441Z
- **Updated:** 2026-08-24T13:11:38.499Z

## Objective
forgotPassword() returned a distinct 503 on mail-send failure, but only for emails with a real account -- letting an attacker distinguish existing vs nonexistent accounts by response code alone, with no email ever needing to arrive.

## Business Reason
Directive section 15 security master audit: password-reset user enumeration is an explicit OWASP-relevant checklist item.

## Technical Requirements
Wrapped Mail::raw()+notify() so failure is logged only, never surfaced; every forgotPassword() call now returns an identical response regardless of account existence or mail outcome.

## Acceptance Criteria
New PasswordResetEnumerationTest (2 cases) passing; full suite 228/228.
