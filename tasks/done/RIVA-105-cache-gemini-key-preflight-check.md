# RIVA-105: Cache Gemini key preflight check

- **ID:** RIVA-105
- **Title:** Cache Gemini key preflight check
- **Status:** DONE
- **Risk Level:** LOW
- **Assigned:** Claude
- **Reviewer:** Codex
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-24T12:23:29.650Z
- **Updated:** 2026-08-24T12:23:33.475Z

## Objective
Cache AiAssistantController::checkGeminiKey()'s verdict (10 min valid / 30s invalid) instead of hitting Gemini on every chat message.

## Business Reason
Audit P2-1: halves external Gemini calls per chat turn.

## Technical Requirements
AiAssistantController.php.

## Acceptance Criteria
tests/Feature/AiAssistantKeyCacheTest.php passes; full suite green
