# RIVA-127: Clinic Patients: no list/browse, no edit, no chart-link endpoints (front desk is lookup+intake only)

- **ID:** RIVA-127
- **Title:** Clinic Patients: no list/browse, no edit, no chart-link endpoints (front desk is lookup+intake only)
- **Status:** INBOX
- **Risk Level:** LOW
- **Assigned:** UNASSIGNED
- **Reviewer:** UNASSIGNED
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-25T00:53:50.081Z
- **Updated:** 2026-08-25T00:53:50.134Z

## Objective
Found during the same page-by-page "ERP audit" as RIVA-126/128. The Clinic
Patients (Front Desk) page's two real backend actions — exact-match
email/phone search and new-patient registration
(`ClinicPatientController::search()`/`register()`,
`backend/routes/api.php:323-324`) — are fully and correctly wired, no dead
UI. The sidebar label "Patients (Front Desk)" implies a browsable roster,
but architecturally this screen is genuinely only a **lookup + intake
tool**, by deliberate backend design (see `ClinicPatientController.php:14-27`
docblock: avoiding PII exposure via a fuzzy/browsable list). This is a real,
documented product-scope gap, not a frontend bug — the frontend audit
correctly did not fabricate a fake roster/table to paper over it.

## Gap 1: no list/browse endpoint
No `GET /clinic/patients` index route exists. A front desk cannot currently
see "who are our patients" as a roster — only look up one at a time by exact
email/phone, or register a new one.

## Gap 2: no edit-patient endpoint
No `PATCH /clinic/patients/{id}` — once found or registered, front-desk
staff cannot correct/update a patient's demographics from this screen.

## Gap 3: no safe chart/detail link from a search result
`patient_id` is returned by `serializePatient()`, but there is no
clinic-scoped detail/chart route this screen can safely link to.
`patient-cards` (the doctor's own list) is populated via
`PatientDoctorRelationship`, which this controller explicitly does not
create — linking there would imply a relationship that doesn't exist.

## Product decision needed, not just an engineering one
Before building a roster endpoint, decide whether "browsable clinic patient
list" is actually wanted given the PII-exposure concern the current design
deliberately avoids (per the blueprint's own C.3/C.4 tenant-isolation and
consent sections) — e.g. paginate + clinic-scope it + gate it behind the
same `services.manage`-style permission tier, rather than opening it to any
active member.

## Acceptance Criteria
- [ ] Explicit decision recorded: build a scoped/paginated/permissioned
      roster endpoint, or intentionally keep this lookup-only (in which case
      the sidebar label/page copy should stop implying a roster exists).
- [ ] If building: `GET clinic/patients` (paginated, clinic-scoped,
      permission-gated), `PATCH clinic/patients/{id}`, and a real
      clinic-scoped detail route the frontend can link to.
- [ ] Frontend updated to use them — no fabricated roster/table until then.
