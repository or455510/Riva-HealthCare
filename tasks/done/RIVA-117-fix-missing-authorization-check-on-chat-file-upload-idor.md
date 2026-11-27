# RIVA-117: Fix missing authorization check on chat file upload (IDOR)

- **ID:** RIVA-117
- **Title:** Fix missing authorization check on chat file upload (IDOR)
- **Status:** DONE
- **Risk Level:** HIGH
- **Assigned:** Claude
- **Reviewer:** Codex
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-24T13:04:48.426Z
- **Updated:** 2026-08-24T13:04:50.951Z

## Objective
ChatController::upload() let any authenticated user send a file message to an arbitrary receiver_id with zero relationship check, bypassing the canChatWith() authorization that store() (text messages) already enforced.

## Business Reason
Directive §6/§23: broken access control on medical-context messaging is a P0 security class; this was discovered during the file-upload security audit, not part of the original task board.

## Technical Requirements
Added exists:users,id validation + canChatWith() check to upload() before the file is stored or the Message is created; aligned variable usage with the validated receiver.

## Acceptance Criteria
New ChatUploadAuthorizationTest (7 cases) passing; full suite 226/226; pre-existing SignedFileUrlTest updated to use a related patient/doctor pair to match the newly-enforced check.
