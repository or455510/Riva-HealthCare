# RIVA-126: Clinic Queue: no walk-in check-in endpoint, no wait-time computation

- **ID:** RIVA-126
- **Title:** Clinic Queue: no walk-in check-in endpoint, no wait-time computation
- **Status:** INBOX
- **Risk Level:** MEDIUM
- **Assigned:** UNASSIGNED
- **Reviewer:** UNASSIGNED
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-25T00:53:49.971Z
- **Updated:** 2026-08-25T00:53:50.025Z

## Objective
Found during a page-by-page "ERP audit" of the clinic-facing screens (user
request: verify each page's function/elements are correct and complete,
compare against real practice-management products). The Clinic Queue page's
frontend (`clinic-queue.component.ts`) and its 8 real backend actions
(check-in/call/start/complete/no-show/skip/remove, all in
`QueueController.php`, routes `backend/routes/api.php:311-318`) are fully
and correctly wired — no dead buttons, no fake data. Two real product gaps
exist in the **backend**, not fixable from the frontend alone.

## Gap 1: no walk-in / add-to-queue endpoint
`QueueController::checkIn()` (`QueueController.php:67-94`) only operates on
an *existing* `Appointment` scheduled for today. There is no endpoint to
register a walk-in patient (no prior appointment) directly into today's
queue. Real queue products (Qminder, Zenoti's check-in board) treat "add
walk-in" as a first-class action — this is a genuine, expected feature this
page currently cannot offer, honestly, because there is nothing for the
frontend to call.

**Proposed fix direction:** a new endpoint, e.g.
`POST clinic/queue/walk-in`, that creates an `Appointment` for the given
(searched/registered) patient scheduled for "now" and immediately checks it
in — reusing `ClinicPatientController`'s existing search/register flow
(RIVA-127) for finding/creating the patient first.

## Gap 2: no estimated/average wait-time computation
The controller returns raw timestamps only (`checked_in_at`, `called_at`,
`queue_completed_at`) and computes no wait-time aggregate anywhere. The
frontend audit deliberately did NOT add a "~12 min wait" figure to the UI,
since that would be fabricated data — violates this project's standing
no-fake-data rule. If a wait estimate is wanted (the product blueprint's own
Section C.5 calls for "an estimated wait derived from a rolling average of
actual historical visit durations, explicitly labeled as an estimate"), it
needs a real backend computation first.

## Minor (not blocking): no search/filter by patient/doctor name
Cheap to add client-side once the list is real (no backend change needed) —
left out of the current pass to stay strictly within "only wire what's
demonstrably backed."

## Acceptance Criteria
- [ ] `POST clinic/queue/walk-in` (or equivalent) implemented, clinic-scoped,
      permission-gated the same way `checkIn()` already is.
- [ ] A real wait-time computation exists server-side (rolling average per
      doctor/clinic, with a safe fallback default until enough history
      exists, per the blueprint's own explicit requirement) — never a
      frontend-fabricated number.
- [ ] Frontend wired to both once they exist; both remain untouched (no fake
      data) until then.
