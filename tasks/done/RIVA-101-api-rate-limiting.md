# RIVA-101: API rate limiting

- **ID:** RIVA-101
- **Title:** API rate limiting
- **Status:** DONE
- **Risk Level:** LOW
- **Assigned:** Claude
- **Reviewer:** Codex
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-24T12:23:29.373Z
- **Updated:** 2026-08-24T12:23:32.807Z

## Objective
Add named rate limiters (api, ai-assistant, auth-sensitive, public-write) and apply them to routes/api.php + bootstrap/app.php throttleApi().

## Business Reason
Audit P0-1: zero API-wide rate limiting; AI endpoint unthrottled risked uncapped Gemini spend and brute-force abuse.

## Technical Requirements
AppServiceProvider::registerRateLimiters(), bootstrap/app.php, routes/api.php. See docs/engineering/RIVA_COST_AND_SCALABILITY_AUDIT.md P0-1.

## Acceptance Criteria
tests/Feature/ApiRateLimitTest.php passes; full suite green
