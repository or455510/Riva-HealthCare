# RIVA-110: Pharmacy foundation (minimal)

- **ID:** RIVA-110
- **Title:** Pharmacy foundation (minimal)
- **Status:** DONE
- **Risk Level:** MEDIUM
- **Assigned:** UNASSIGNED
- **Reviewer:** Codex
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-24T12:23:54.043Z
- **Updated:** 2026-08-24T12:59:57.170Z

## Objective
Add pharmacies/pharmacy_branches/pharmacy_medication_availability tables and minimal CRUD — no fake inventory, no full pharmacy ERP.

## Business Reason
Audit §11: pharmacy integration is currently unstarted; directive explicitly scopes this to a minimal foundation only.

## Technical Requirements
New migrations + models + a thin controller. Must not fabricate availability/inventory data.

## Acceptance Criteria
Migrations run cleanly; basic pharmacy/branch CRUD tested; zero fabricated medical/inventory facts

## Completion Report (2026-08-24)

**Implementation.** 4 new tables via one guarded migration
(`2026_08_26_000001_create_pharmacy_foundation_tables.php`, `Schema::hasTable()`
guards, full symmetric `down()`): `pharmacies`, `pharmacy_branches`,
`medicines` (a general catalog entry — deliberately kept separate from the
pre-existing `Medication` model, which is a patient's personal
prescription record; naming was chosen specifically to avoid that
collision), `pharmacy_medicine_availability` (one row per branch+medicine
pair, `unique(['pharmacy_branch_id','medicine_id'])`, `price` nullable and
never coerced to free/zero, no seeded rows anywhere).

4 new Eloquent models with explicit `$fillable`/`$casts`/relationships
(`PharmacyMedicineAvailability` declares `$table` explicitly since
Eloquent's pluralization guess doesn't match the migration's table name).

Two controllers: `PharmacyController` (public, read-only — index/show/
branches/medicine-search/availability-lookup) and
`Admin\PharmacyAdminController` (admin-only, the sole write surface — full
CRUD across all 4 resources, `storeAvailability()` uses `updateOrCreate()`
keyed on the branch+medicine pair so re-reporting never duplicates).
Routes wired in `routes/api.php`: public routes alongside the existing
diseases/hospitals public routes; admin routes inside the existing
`admin`-middleware group.

**Zero fabricated data.** No seeder, factory, or migration in this change
inserts a single pharmacy/branch/medicine/availability row. Every value the
public endpoints can ever return came from an admin actually entering it
through `PharmacyAdminController`. `PharmacyController::availability()`'s
docblock and its regression test both make the core invariant explicit: a
medicine/branch pair with no availability row is "unknown," and is never
presented as confirmed available or confirmed unavailable.

**Tests (14 new, all passing).**
- `PharmacyDirectoryTest` (8): active-only filtering on the pharmacy/branch
  listings, medicine search by name or generic name, the no-row-means-
  unreported invariant (checked both for a medicine with zero rows
  anywhere and one with only non-qualifying rows — wrong medicine, wrong
  branch, inactive branch), public endpoints require no authentication,
  admin write routes reject a patient.
- `PharmacyAdminCrudTest` (6): full create/update/delete per resource,
  the availability update-in-place behavior verified end-to-end through
  the public endpoint (confirms exactly one row after two reports of the
  same branch+medicine pair), every admin write route rejected for
  patient/doctor/caregiver roles (7 endpoints × 3 roles), unauthenticated
  requests rejected.

Full backend suite re-run from scratch (not assumed) after this change:
**219/219 passing** (was 205 immediately before this task; +14 here).

**Security.** Admin-only write access enforced by the same
`EnsureUserIsAdmin` middleware used by every other admin controller in
this app (401 unauthenticated, 403 non-admin) — verified by dedicated
regression tests, not just by route placement. No secrets, PHI, or
patient-identifying data touch any of these 4 tables.

**Known limitations / explicitly out of scope for this task.** No real
pharmacy integration exists — this is a foundation only, matching the
directive's explicit instruction not to pretend otherwise. No search-by-
location/distance (branches have nullable lat/long but there's no
geospatial query yet). No pharmacy-side self-service portal — availability
is entered by admins only, not by a pharmacy's own staff account; that
would be a reasonable follow-up task if/when a real pharmacy-partner login
is wanted. No Angular frontend consumes these endpoints yet — that is
separate frontend work (RIVA-111/112 track), not this task.

**Sync status:** VERIFIED — migration + 4 models + 2 controllers + routes
+ 2 test files committed to the sandbox git mirror and synced to the real
device (10/10 files written, 0 rejected, `routes/api.php` guarded with its
prior mtime).
