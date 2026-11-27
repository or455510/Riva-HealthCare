# RIVA-136: Admin overview: fabricated sparklines/trend percentages removed; no drill-in for 2 metrics; no real time-series data

- **ID:** RIVA-136
- **Title:** Admin overview: fabricated sparklines/trend percentages removed; no drill-in for 2 metrics; no real time-series data
- **Status:** INBOX
- **Risk Level:** LOW
- **Assigned:** UNASSIGNED
- **Reviewer:** UNASSIGNED
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-25T01:29:33.322Z
- **Updated:** 2026-08-25T01:29:33.322Z

## Objective
Confirm removal of fake data and scope real time-series/drill-in support

## Business Reason
Admin overview cards displayed literal hardcoded fake sparkline arrays and fake +10%/+12% trend percentages with zero backend data behind them — removed this session, but the underlying capability (real historical metrics) still does not exist

## Technical Requirements
AdminAreaController::overview() has no per-metric time-series, only 3 aggregate daily-count series; missed-medications-today and active-doctors metrics have no matching detail page to drill into

## Acceptance Criteria
Decide whether real per-metric history is worth building (needed for any real sparkline/trend) and whether missed-medications/active-doctors need their own detail pages
