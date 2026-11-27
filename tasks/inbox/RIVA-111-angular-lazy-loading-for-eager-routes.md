# RIVA-111: Angular lazy loading for eager routes

- **ID:** RIVA-111
- **Title:** Angular lazy loading for eager routes
- **Status:** INBOX
- **Risk Level:** LOW
- **Assigned:** UNASSIGNED
- **Reviewer:** Codex
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-24T12:23:54.098Z
- **Updated:** 2026-08-24T12:23:54.098Z

## Objective
Convert the ~60% of Riva-master/src/app/app.routes.ts routes that are still eagerly bundled (including the heaviest screens) to loadComponent.

## Business Reason
Audit P1-6: directly shrinks first-load bundle for every user.

## Technical Requirements
Riva-master/src/app/app.routes.ts. Needs the Angular frontend staged/built to verify — not done from this backend-only sandbox mirror.

## Acceptance Criteria
ng build succeeds; initial bundle size measurably smaller; no broken routes
