# RIVA-141: No search/filter anywhere patients or doctors are browsed: doctor-cards has no specialty/location filter, clinic-patients is exact-match-only with no name search or live results, three separate patient-listing surfaces are never consolidated

- **ID:** RIVA-141
- **Title:** No search/filter anywhere patients or doctors are browsed: doctor-cards has no specialty/location filter, clinic-patients is exact-match-only with no name search or live results, three separate patient-listing surfaces are never consolidated
- **Status:** MOSTLY DONE — one item blocked on a required user action
- **Risk Level:** MEDIUM
- **Assigned:** claude
- **Reviewer:** UNASSIGNED
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-25T12:34:06.305Z
- **Updated:** 2026-08-25T00:00:00.000Z

## Objective
Add real search/filter capability across every patient/doctor browsing surface, and reduce patient-listing redundancy

## Business Reason
User cannot find a doctor by specialty or proximity on doctor-cards (it just lists everyone); front-desk staff must know a patient's exact email/phone to look them up on clinic-patients even though they're standing in front of them; there are three separate places a doctor sees patients (patient-cards, clinic-patients, the dashboard mini-list) with no clear reason why, and the doctor dashboard itself has no patient filter

## Technical Requirements
DoctorController::index() and CaregiverController::index() ignore all query params (confirmed in RIVA-140); ClinicPatientController::search() is deliberately exact-match on email/phone only, no name search, no live/incremental results, by original PII-avoidance design (RIVA-127); patient-cards.component.ts vs clinic-patients vs the doctor dashboard's own patient widget serve overlapping but not identical purposes and were never rationalized against each other

## Product Decision (recorded 2026-08-25, confirmed directly by the product owner)
Option chosen: **merge all three into one canonical "My Patients" page.** patient-cards becomes
the single source of truth; the dashboard's mini patient list is replaced with a link/summary
pointing at it (not a second live query); clinic-patients (Front Desk) keeps its distinct
search+intake role (finding-or-registering a walk-in) but its "register new patient" success path
should land the user on the same canonical page, not a third, separate list.

## Acceptance Criteria
- [x] Doctor/caregiver directories (doctor-cards/caregiver-cards) support specialty + location
      query params with a real UI filter — no client-side-only fake filtering. DONE 2026-08-25:
      `DoctorController::index()` / `CaregiverController::index()` now accept `specialty` and
      `location` query params (backed by `DirectoryFilterTest.php`, 5/5 passing); both
      `doctor-cards` and `caregiver-cards` components got a real debounced filter bar wired to
      those params (no client-side filtering).

      **Follow-up, DONE 2026-08-25 (direct product-owner feedback: "the filtering is just text
      boxes — there's no dropdown menu suggesting options to pick from"):** added
      `GET /doctors/filter-options` and `GET /caregivers/filter-options`
      (`DoctorController::filterOptions()` / `CaregiverController::filterOptions()`, both public,
      registered BEFORE their respective `{doctor}`/`{caregiver}` show routes to avoid a
      route-model-binding collision) returning the real, distinct specialty and address values
      that already exist among active doctors/caregivers — never a fabricated taxonomy, and
      excludes inactive rows and blanks. Backed by `FilterOptionsTest.php` (3/3 passing,
      12 assertions), full suite re-verified at 288 passed/915 assertions afterward. The frontend
      binds these via native HTML `<datalist>` (`list="doctor-specialty-options"` etc.) on the
      existing filter inputs — a real browser-native dropdown of suggestions appears as the user
      types or focuses the field, built from actual data, while free typing still works for a
      specialty/area no one has entered yet. Build-verified (`ng build --configuration
      production`, clean, only pre-existing warning classes) and synced to the real device.
- [x] clinic-patients gets name search (balancing PII exposure vs usability — e.g. require 3+
      characters and stay clinic-scoped) with live-updating results as the user types, alongside
      the existing exact email/phone lookup. DONE 2026-08-25: new `GET
      /clinic/patients/search-by-name` endpoint (`ClinicPatientController::searchByName()`),
      scoped to patients with a real `PatientDoctorRelationship` to a doctor who is an active
      member of this clinic (intentionally intersects ALL active `ClinicUser` members against the
      real `Doctor` table rather than filtering by `role='doctor'`, to correctly include a
      solo-clinic's owner-doctor — caught via `test_search_by_name_finds_a_patient_with_a_relationship_to_this_clinics_doctor`
      using the clinic owner as the doctor). Requires 3+ characters, min-length enforced (422
      below that), permission-gated identically to the existing `search()` endpoint. 4 new tests,
      12/12 passing in `ClinicPatientRegistrationTest.php`. Frontend: `clinic-patients.component.ts`
      got a new "Quick find by name" card above the existing exact email/phone search, with a
      debounced (350ms) live-results list, an explicit empty-state note explaining the scoping
      (so a genuinely new walk-in isn't mistaken for "not in the system"), and clicking a result
      populates the same "Patient found" card the exact search uses.
- [x] A condition/age filter added to the canonical patient list once backend data supports it.
      DONE 2026-08-25: `PatientCardsComponent` already loads its full, bounded patient list
      (a doctor/caregiver's own assigned patients — not the global directory, so client-side
      filtering over already-fully-loaded real data is honest here, unlike doctor-cards which
      filters server-side over a large public list) — added `ageMinFilter`/`ageMaxFilter` (numeric
      range) and `conditionFilter` (text input with a `<datalist>` dropdown built from the REAL,
      distinct chronic-condition tokens already present among the loaded patients — never a
      fabricated taxonomy) plus a `filteredPatients` getter the grid now renders. Two empty states:
      "no patients at all" (unchanged) vs. a new "no patients match your filters" with a Clear
      action. Build-verified clean, synced to the real device.
- [ ] The doctor dashboard's own "my patients" widget is replaced with a summary + link into the
      canonical patient-cards page rather than a third independent query/render of the same data.
      STILL BLOCKED: dashboard.component.ts lives 8 folders below the connected root and cannot be
      staged from the user's device (device_stage_files depth limit) until the user connects
      `Riva-master/src/app/core/components/auth/dashboard/dashboard` directly as an additional
      folder. This is the single highest-priority remaining blocker in the whole project — please
      connect that folder via the Claude Desktop app's connect-folder UI so this can proceed.
- [x] clinic-patients' register/search flow links into the same canonical page instead of staying
      a dead-end third list. DONE 2026-08-25: rather than routing into `patient-cards` (which has
      no single-patient view, only a grid), the "Patient found" card in `clinic-patients` — shown
      both after an existing-patient search AND immediately after a successful new-patient
      registration (both set the same `searchResult` state) — now includes a real "View full
      profile →" link to `/profile-p/:patient_id`, the actual canonical single-patient page (see
      RIVA-137, this window). Only shown when a real `Patient` row exists (`patient_id` present —
      a brand-new placeholder account has none yet). `profile-p` already handles a 403 gracefully
      with a clear message (e.g. front-desk staff with no personal doctor/caregiver relationship to
      that specific patient), so offering the link unconditionally is safe rather than guessing
      access client-side. Build-verified clean, synced to the real device.
