# RIVA-137: profile-p (patient chart view): admin viewer gets a 403 because loadProfile() only branches doctor vs caregiver

- **ID:** RIVA-137
- **Title:** profile-p (patient chart view): admin viewer gets a 403 because loadProfile() only branches doctor vs caregiver
- **Status:** INBOX
- **Risk Level:** LOW
- **Assigned:** UNASSIGNED
- **Reviewer:** UNASSIGNED
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-25T01:34:33.693Z
- **Updated:** 2026-08-25T01:34:33.693Z

## Objective
Fix a real access bug affecting the admin role on the patient profile page

## Business Reason
app.routes.ts allows admin to open profile-p/:id, but the component always calls /dashboard/doctor for any non-caregiver role, which is role:doctor-gated server-side — an admin viewing a patient's profile gets a failed load with no real content

## Technical Requirements
profile-p.component.ts's loadProfile() branches only on role === caregiver vs everything else defaulting to GET /dashboard/doctor; DashboardController::doctor() does abort_unless(user->doctor, 403). GET /patients/{id}/daily-status already correctly supports admin via DailyStatusController, so only the profile-fetch branch is broken

## Acceptance Criteria
- [x] Admin viewers get a real profile fetch path (new admin-scoped endpoint, or reuse an existing
      admin patient-lookup endpoint) instead of hitting the doctor-only route. DONE 2026-08-25:
      new `GET /patients/{patient}/profile` (`DashboardController::patientProfile()`), mirroring
      the same relationship-gating pattern already tested in
      `DailyStatusController::patientStatuses()` — admin always full access, doctor/caregiver need
      an active or accepted `PatientDoctorRelationship`/`PatientCaregiverRelationship` (NOT merely
      pending, which stays a 403 here — a pending relationship gets a preview elsewhere, e.g.
      `ReportController`, not the full record). `profile-p.component.ts`'s `loadProfile()` now
      calls this single endpoint for all three roles instead of branching
      doctor-dashboard-vs-caregiver-dashboard and searching the returned list client-side — fixes
      the admin 403 and is a real efficiency win too (one small record instead of a whole
      dashboard payload just to read one patient). 7/7 new tests in
      `PatientProfileAccessTest.php` passing; full backend suite (275 tests, 877 assertions)
      regression-checked clean.
