# RIVA-132: No link between a prescribed medication and a specific pharmacy/branch fulfilling it

- **ID:** RIVA-132
- **Title:** No link between a prescribed medication and a specific pharmacy/branch fulfilling it
- **Status:** PARTIALLY DONE
- **Risk Level:** LOW
- **Assigned:** claude
- **Reviewer:** UNASSIGNED
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-25T01:13:25.131Z
- **Updated:** 2026-08-25T00:00:00.000Z

## Objective
Investigate and scope prescription-to-pharmacy fulfillment linking

## Business Reason
User asked how patients/services link to pharmacies; today the pharmacy directory is just a read-only catalog, not connected to any patient's actual prescription

## Technical Requirements
PharmacyController/PharmacyAdminController expose a real pharmacy+medicine+availability directory (RIVA-110), but no route or model connects a MedicationLog/prescription to a chosen pharmacy or branch for fulfillment

## Acceptance Criteria
- [x] Decision + scoped plan recorded

  **What was built (as part of RIVA-130's prescription-scan feature,
  2026-08-25):** `PrescriptionScanController::register()` now
  best-effort matches every newly-registered `Medication` against the
  real `medicines` catalog (fuzzy `LIKE` on name/generic_name, honestly
  `null` when nothing matches — see RIVA-130). The frontend
  (`prescription-scan.component.ts`, step 3 "Registered/Done") then
  calls the ALREADY-EXISTING, already-tested
  `GET /medicines/{medicine}/availability`
  (`PharmacyController::availability()`) for each matched medication and
  lists every real pharmacy branch that has reported it available —
  this is the requested "show the nearest pharmacies that have the
  medication" feature. This is a genuine `Medication` → `Medicine` →
  `pharmacy_medicine_availability` link, satisfying this task's core
  ask (a prescribed medication now leads a patient/doctor to the real
  pharmacies carrying it).

  **Follow-up, DONE 2026-08-25:** the match above was only ever returned in
  one API response and never saved — the moment the doctor navigated away
  from the scan-review screen, the link was gone, so a patient viewing their
  own medication list later had no way to find nearby pharmacies for a
  medication a doctor had already matched. Fixed properly:
  - New migration `2026_08_27_000002_add_medicine_id_to_medications_table.php`
    — real, nullable `medicine_id` FK on `medications` (nullOnDelete; honestly
    null when nothing matches or the catalog entry is later removed).
  - `PrescriptionScanController::register()` now persists the match it was
    already computing (previously discarded).
  - `MedicationController::store()`/`update()` — patient-added medications
    (not just scan-registered ones) now get the SAME real fuzzy-match
    against the `medicines` catalog, so "find nearby pharmacies" isn't a
    scan-only feature. `update()` recomputes the match when the name
    changes.
  - `MedicationController::serializeMedication()` now returns `medicine_id`
    + `matched_medicine_name` on every medication response.
  - Frontend: `add-new-medication.component.ts`/`.html` (the patient's own
    medication list) — a "Find pharmacies" button appears per medication
    row whenever it has a real `medicine_id`, calling the existing
    `GET /medicines/{id}/availability` and expanding an inline results row
    (pharmacy name, branch, address, phone, price) or an honest "no
    pharmacy has reported this as available yet" message.
  - `tests/Feature/MedicationCatalogLinkTest.php` (new, 4/4 passing, 12
    assertions): proves a patient-added medication gets a real match when
    one exists, honestly null when none does, a rename recomputes the
    match, and — the actual regression this guards — the prescription-scan
    flow's match is genuinely persisted to the database row, not just
    returned once. Full backend suite re-verified afterward: 292
    passed/927 assertions, zero regressions.

  **What was deliberately NOT built (still open — this is why the task
  is PARTIALLY DONE, not DONE):**
  - **True distance-based "nearest" sorting.** `pharmacy_branches` has
    nullable `latitude`/`longitude` columns, so real distance
    calculation is technically wired for once real coordinate data
    exists — but NO pharmacy/branch data is seeded anywhere in this
    codebase by design (per the pharmacy-foundation migration's own
    docblock: "no seeded data anywhere... every row here can only come
    from a real pharmacy/admin"). Today's "nearest pharmacies" list is
    every branch with a reported-available row, unsorted by distance,
    because there is no real geographic/patient-location data to sort by
    honestly. Sorting by distance should be added once real branch
    lat/long data exists in production — do NOT fabricate coordinates or
    a sort order to make this "look" done.
  - **No persistent link between a specific `Medication` row and a
    specific pharmacy branch the patient chose to fulfill it at** (e.g.
    no `medication_fulfillments` table recording "patient picked branch
    X for this prescription"). Today's flow is look-up-only: show real
    availability, let the patient/doctor see it, but nothing is written
    recording a fulfillment choice. This would need a new migration +
    model + a "I'll pick this one up here" action if the product wants
    that recorded (e.g. for the "remind them about the medication"
    request — a future reminder could reference the chosen branch/price).
  - **Automated stock/price freshness** — `pharmacy_medicine_availability`
    rows are only ever entered by a real pharmacy/admin (per RIVA-110's
    existing design); this task did not add or change how that data gets
    populated, only how a patient's own registered medication surfaces it.
