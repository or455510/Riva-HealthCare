# RIVA-142: Reports: doctor's own-report scoping and patient-sees-all already work correctly, but reports are not organized per-patient anywhere and there is no patient-controlled cross-doctor sharing toggle

- **ID:** RIVA-142
- **Title:** Reports: doctor's own-report scoping and patient-sees-all already work correctly, but reports are not organized per-patient anywhere and there is no patient-controlled cross-doctor sharing toggle
- **Status:** INBOX
- **Risk Level:** MEDIUM
- **Assigned:** UNASSIGNED
- **Reviewer:** UNASSIGNED
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-25T12:34:06.393Z
- **Updated:** 2026-08-25T12:34:06.393Z

## Objective
Restructure report access around the patient (not one flat list) and add patient-controlled report visibility

## Business Reason
A doctor opening my-reports sees every report they ever wrote across all patients in one flat list instead of finding a patient and seeing that patient's reports from the patient's own profile; a patient cannot choose to make one specific report visible to a doctor other than the one who wrote it, even though they may want a second opinion

## Technical Requirements
ReportController::index() already correctly scopes by doctor_id for doctors and by patient_id (no doctor filter) for patients (verified directly in the controller this session) — the access-control logic the user was worried about is real and correct. What's missing: no route/UI shows one patient's reports from within that patient's own profile-p page, and no visibility/sharing column or endpoint exists on the Report model to let a patient opt a specific report into cross-doctor visibility

## Acceptance Criteria
- [x] profile-p gets a real 'Reports' section scoped to that one patient. CORRECTION 2026-08-25:
      on re-reading the actual code before starting the sharing work, this already existed —
      `profile-p.component.html`'s "View Report" button (`goToReport()`) already navigates to
      `/report/:patientId`, and `ReportComponent` already calls the pre-existing
      `GET /patients/{patient}/reports` (`ReportController::patientReports()`), which is already
      scoped to exactly that one patient with a real access-mode (full/preview/none) and
      write/history tabs. The original task text above (written before this file was re-verified)
      incorrectly listed this as missing — leaving that text unedited above for the record, this
      line is the correction. No frontend change was needed for this criterion.
- [x] A patient-facing report-sharing toggle is designed and built (new column + endpoint + UI),
      defaulting to doctor-only visibility so nothing becomes more exposed than today without
      explicit patient action. DONE 2026-08-25: new `report_shares` table + `ReportShare` model;
      `POST /reports/{report}/share` and `DELETE /reports/{report}/share/{doctorId}`, patient-only
      (403 for anyone else, including the report's own author doctor), rejects sharing with the
      report's own author as redundant (422). `ReportController::index()`/`show()` updated so a
      doctor a report was shared with sees it (`is_shared_with_me: true` on that report) without
      gaining any access to the patient's other reports — proven in
      `ReportSharingTest.php` (9/9 passing) by asserting a second, un-shared report from the same
      patient/doctor pair stays invisible to the shared-with doctor. `authorizeReportAccess()`'s
      doctor branch now accepts either an active `PatientDoctorRelationship` (unchanged, pre-
      existing rule) OR a `ReportShare` row — never broadens beyond that one report. Frontend:
      `my-reports.component.ts/html` (used by patient/doctor/caregiver alike) — for a patient
      viewing their own report, a new "Share with another doctor" panel lists their care-team
      doctors (`GET /patient/my-doctors`, excluding the report's author and anyone already
      shared), lets them share/revoke inline; a "Shared with you" badge appears on the receiving
      doctor's report card/list. Regression-checked: `ReportAccessMatrixTest` (4/4) and
      `ReportListScopingTest` (6/6) still pass unchanged.

## Follow-up (not done, out of scope for this pass)
- No UI groups a doctor's flat `/my-reports` list by patient — a doctor with many patients still
  sees one chronological list (with the new "Shared with you" badge for shared ones, but no
  per-patient grouping/filter). Not requested explicitly by the product owner; flagging as a
  possible future improvement once the canonical patient-list merge (RIVA-141) lands, since that
  page would be the natural place to link into "this patient's reports" from a patient card.
