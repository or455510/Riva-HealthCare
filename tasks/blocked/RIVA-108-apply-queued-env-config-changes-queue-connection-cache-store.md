# RIVA-108: Apply queued .env config changes (QUEUE_CONNECTION, CACHE_STORE, LOG_STACK)

- **ID:** RIVA-108
- **Title:** Apply queued .env config changes (QUEUE_CONNECTION, CACHE_STORE, LOG_STACK)
- **Status:** BLOCKED
- **Risk Level:** LOW
- **Assigned:** UNASSIGNED
- **Reviewer:** Codex
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-24T12:23:53.830Z
- **Updated:** 2026-08-24T12:23:53.936Z

## Objective
Manually change QUEUE_CONNECTION=sync->database, CACHE_STORE=file->database (or redis), LOG_STACK=single->daily in backend/.env; run queue:table+migrate if needed; start a queue worker.

## Business Reason
Audit P0-2/P2-6: RIVA-102's queued jobs only run async once QUEUE_CONNECTION leaves sync; unrotated single log file grows forever.

## Technical Requirements
backend/.env only. BLOCKED for an agent: the device bridge has a hard platform-level guard against writing .env. Human must edit directly.

## Acceptance Criteria
Queued jobs (medication reminders, notification emails) process asynchronously via queue:work; log file rotates daily
