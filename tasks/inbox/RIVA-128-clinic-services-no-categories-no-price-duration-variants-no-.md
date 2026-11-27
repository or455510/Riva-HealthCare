# RIVA-128: Clinic Services: no categories, no price/duration variants, no pagination/search, no name-uniqueness

- **ID:** RIVA-128
- **Title:** Clinic Services: no categories, no price/duration variants, no pagination/search, no name-uniqueness
- **Status:** INBOX
- **Risk Level:** LOW
- **Assigned:** UNASSIGNED
- **Reviewer:** UNASSIGNED
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-25T00:53:50.187Z
- **Updated:** 2026-08-25T00:53:50.238Z

## Objective
Found during the same page-by-page "ERP audit" as RIVA-126/127. The Clinic
Services & Prices page's CRUD (list/create/update/delete, including the
409-on-delete-with-appointment-history guard) is fully and correctly wired
end to end — `ClinicServiceController.php`, verified against
`ClinicServiceManagementTest.php`'s real test cases. Compared against real
service-catalog products (Fresha, Zenoti service menus — see sources in
RIVA-125's build report), four gaps exist, all schema/backend-level, none
fixable from the frontend alone.

## Gap 1: no service categories
`clinic_services` table (migration `2026_08_23_000010_create_clinic_services_table.php`)
has no `category` column; `ClinicService::$fillable` has none either. Real
catalogs group services (e.g. "Consultations" / "Diagnostics"). This
clinic's catalog cannot do that today — needs a migration + fillable +
validation change, then real frontend grouping (not fabricated).

## Gap 2: no price/duration variants per service
Real products (Fresha) support e.g. "30 min" vs "60 min" pricing of the same
service. Current schema is a strict single price + single duration per row.

## Gap 3: no pagination or server-side sort/search on index()
`index()` returns the entire clinic's list, unpaginated, ordered by name
only, every call. The frontend audit added a client-side search filter over
the already-fetched array (fine at current scale) but this won't scale to a
large catalog without a real backend change.

## Gap 4: no name-uniqueness constraint
`validated()` has no `unique` rule — the backend will silently accept two
identically-named services in the same clinic. Not enforced client-side
either, since the backend doesn't reject it (enforcing only on the frontend
would just produce a confusing, easily-bypassed error).

## Acceptance Criteria
- [ ] Decide whether categories/variants are in scope for the current phase
      (per the blueprint's own MVP-vs-later scoping — this is a "SHOULD",
      not a "MUST," per Section P) before building either.
- [ ] If building categories: migration + fillable + validation + frontend
      grouping/filter, all wired to real data.
- [ ] If building variants: schema redesign (likely a child
      `clinic_service_prices` table, not new columns on the existing row) —
      scope carefully, this is a bigger change than it sounds.
- [ ] Add pagination to `index()` once the catalog size in practice
      justifies it — not urgent at pilot scale.
- [ ] Add a `unique` validation rule (scoped per clinic) for service name,
      with a real 422 message surfaced in the existing `rowError`/form-error
      UI pattern.
