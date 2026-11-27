# RIVA-135: Caregiver dashboard: hardcoded Online status, chat does not deep-link to clicked patient, 3 AI action buttons have no distinct backend behind them

- **ID:** RIVA-135
- **Title:** Caregiver dashboard: hardcoded Online status, chat does not deep-link to clicked patient, 3 AI action buttons have no distinct backend behind them
- **Status:** INBOX
- **Risk Level:** LOW
- **Assigned:** UNASSIGNED
- **Reviewer:** UNASSIGNED
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-25T01:29:33.257Z
- **Updated:** 2026-08-25T01:29:33.257Z

## Objective
Fix a fake status field and scope real per-button AI/chat behavior

## Business Reason
Caregiver dashboard showed a permanently-fake 'Online' status; clicking a specific patient does not open that patient's conversation; three AI quick-action buttons (Trends/Risk/Drug-Interactions) all just open the same generic assistant

## Technical Requirements
currentCaregiver.status is hardcoded 'Online' in dashboard-caregiver.component.ts; ChatCComponent has no route/query-param handling for a target patient id; no AI trend-analysis/risk-assessment/drug-interaction endpoint exists, only the general assistant

## Acceptance Criteria
Real presence/status wired or the status pill removed; chat deep-links to the clicked patient's thread; each AI button either gets a real distinct backend capability or is honestly relabeled as one general assistant entry point
