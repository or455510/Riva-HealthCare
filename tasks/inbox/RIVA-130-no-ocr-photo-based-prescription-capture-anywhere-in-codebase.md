# RIVA-130: No OCR/photo-based prescription capture anywhere in codebase

- **ID:** RIVA-130
- **Title:** No OCR/photo-based prescription capture anywhere in codebase
- **Status:** DONE
- **Risk Level:** MEDIUM
- **Assigned:** claude
- **Reviewer:** UNASSIGNED
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-25T01:13:25.028Z
- **Updated:** 2026-08-25T00:00:00.000Z

## Objective
Investigate and scope real prescription-photo-to-medication OCR

## Business Reason
User asked directly where to photograph/scan a prescription to auto-fill medications; this does not exist

## Technical Requirements
No OCR/vision library or endpoint anywhere in backend or frontend; confirmed via grep across the full backend tree

## Acceptance Criteria
- [x] Decision + scoped plan recorded (candidate: Google Vision / Tesseract, human-review step before saving extracted meds)

  **Decision:** reused the ALREADY-CONFIGURED, already-working Gemini
  vision integration (`GEMINI_API_KEY` in `.env`, `gemini-2.5-flash`,
  same credential `AiAssistantController` already uses for the AI chat
  feature) instead of Google Vision or Tesseract — zero new credentials,
  zero new server dependencies, deployable immediately on the real
  Windows server.

- [x] **Backend** — `app/Http/Controllers/PrescriptionScanController.php` (new):
  - `POST /api/prescriptions/scan` (doctor-only, `ensureAssignedPatient`
    guard — 403 for any doctor without an active/accepted relationship to
    the patient): accepts a multipart `image` (jpeg/jpg/png/webp, max
    8MB) + `patient_id`; base64-encodes the image; sends it to Gemini's
    `generateContent` endpoint with a `responseSchema`-constrained JSON
    output (`medications[]` with name/dosage/frequency/duration/
    instructions, plus `scan_notes`). **Writes NOTHING to the database —
    this is a read-only preview.** Returns the extracted (unsaved) list
    for the doctor to review.
  - `POST /api/prescriptions/register` (doctor-only, same assigned-patient
    guard): accepts the doctor's REVIEWED/corrected `medications[]` array
    (from the scan, or fully manual, or a mix) and is the ONLY method
    that ever creates real `Medication::create()` rows. Each created
    medication automatically flows through the pre-existing
    `MedicationAdherenceService`/reminder system — no new wiring needed,
    that engine already works generically off the `medications` table.
    Also does a best-effort fuzzy `LIKE` match against the real
    `medicines` pharmacy catalog (returns `matched_medicine_id: null`
    honestly when nothing matches — never guesses) and sends the patient
    a real notification.
  - **Honest-failure discipline (per the "no fake data" directive)**:
    missing Gemini key → 503 with a clear message; connection failure →
    502; Gemini HTTP failure → 502; unparseable/non-JSON Gemini output →
    502; all four cases explicitly tell the doctor "you can add
    medications manually below" and NEVER fabricate a medication. A
    legitimately empty scan (blurry/unreadable image) returns `200` with
    an empty `medications: []` array and an explanatory `scan_notes` —
    this is treated as a valid, non-error outcome, not silently hidden.
  - `tests/Feature/PrescriptionScanTest.php` (new, 10 tests / 26
    assertions, all passing): covers successful extraction, 403 for
    unrelated doctor, 503 for missing key, 502 for unparseable Gemini
    output (asserts zero `medications` rows written), empty-scan-is-not-
    an-error, real row creation with correct `dosage`/`frequency`
    defaults (`'As directed'`/`'daily'`, matching
    `MedicationController::store()`'s existing convention) and combined
    `instructions` (`duration` + free-text instructions concatenated),
    catalog-match-found, catalog-match-honestly-null, 403 for register on
    an unrelated patient, and a patient account being forbidden from both
    endpoints entirely (doctor-only feature).
  - Full backend regression suite re-run after adding this feature:
    **285 passed / 903 assertions**, zero regressions.

- [x] **Frontend** — `core/components/auth/prescription-scan/prescription-scan.component.ts`
  (new, standalone, matches `report.component`'s sidebar-less visual
  style since it's launched directly from that page): 3-step flow —
  (1) **Upload**: doctor picks/photographs an image, previews it, hits
  "Scan"; a "skip straight to manual entry" option is always available.
  (2) **Review/Edit**: every extracted medication (or a blank manual row
  if the scan found nothing or failed) is shown in an editable form —
  name/dosage/frequency/duration/instructions — so the doctor can correct
  any misread field before anything is saved, exactly as requested; rows
  can be added/removed freely. Any `scanError`/`scanNotes` message from
  the backend is shown verbatim, never hidden. (3) **Registered/Done**:
  after `POST /prescriptions/register` succeeds, each newly-created
  medication is shown with an inline "Find nearby pharmacies" action
  (calls the existing, real `GET /medicines/{id}/availability` — see
  RIVA-132 below) when a catalog match was found, or an honest "not in
  our pharmacy catalog yet" note when it wasn't.
  - Linked from `report.component.html`: a "📷 Scan Prescription" button
    (`*ngIf="canWriteReport"`, matching the page's existing write-access
    gate) routes to `/prescription-scan/:patientId`.
  - Routed in `app.routes.ts`: `prescription-scan/:patientId`,
    `canActivate: [AuthGuard]`, `data: { roles: ['doctor'] }` (matches
    the controller's doctor-only server-side authorization).
  - Build-verified: `ng build --configuration production` completes
    clean with the new `prescription-scan-component` lazy chunk
    (16.15 kB / 4.21 kB transfer) — only pre-existing warning classes
    present, no new errors.
  - **Synced to the real device** (`D:\cv\project-riva\rivatest-main\`):
    `PrescriptionScanController.php`, `routes/api.php`,
    `PrescriptionScanTest.php`, `report.component.html`,
    `prescription-scan.component.ts`, `app.routes.ts` — all confirmed
    written with `{"rejected":[]}`.

  **Deployment note:** requires `php artisan migrate` is up to date (no
  new migration for this feature — it only adds a controller/routes) and
  the real server's `.env` `GEMINI_API_KEY` to remain valid; both were
  already confirmed present and working pre-session (reused, not newly
  added).

  **Not built (out of scope for this task, tracked separately):**
  medication reminders to the patient already exist via the pre-existing
  adherence/reminder engine (`MedicationAdherenceService`) once a
  `Medication` row exists — this task did not need to build reminders,
  only ensure new rows flow into that existing system, which they do
  automatically via the standard `medications` table. Nearest-pharmacy
  *distance* sorting (using `latitude`/`longitude`) was not built — see
  RIVA-132's notes for why.
