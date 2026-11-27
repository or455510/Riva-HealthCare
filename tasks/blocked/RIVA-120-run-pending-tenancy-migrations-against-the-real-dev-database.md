# RIVA-120: Run pending tenancy migrations against the real dev database (clinic_user missing)

- **ID:** RIVA-120
- **Title:** Run pending tenancy migrations against the real dev database (clinic_user missing)
- **Status:** BLOCKED
- **Risk Level:** BLOCKED
- **Assigned:** UNASSIGNED
- **Reviewer:** UNASSIGNED
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-24T13:44:29.800Z
- **Updated:** 2026-08-24T13:44:45.235Z

## Objective
Run `php artisan migrate` against the real dev database (`riva_clean` on the
user's machine) so the schema actually matches what the Laravel code — and
the already-built Angular pages that call it — expect. No code change is
needed; this is a "commands were never executed against this specific
database" gap, not a defect.

## Business Reason
Root-caused this session from the reported live bug: visiting `/clinic-services`
(and equally `/clinic-staff`, `/clinic-queue`, `/clinic-patients`, and — more
broadly — landing on ANY authenticated dashboard page, since the sidebar
calls `GET /api/clinic/my-context` on every page load to decide whether to
render clinic-staff navigation) throws:

```
SQLSTATE[42S02]: Base table or view not found: 1146 Table 'riva_clean.clinic_user' doesn't exist
```

Traced the full chain end-to-end before writing this task, per the master
directive's instruction not to hide/paper over a reported bug:

- Angular: `/clinic-services` route → `ClinicServicesComponent` → `ClinicServicesService.list()`
  → `GET /api/clinic/services`. Confirmed this route IS correctly registered
  in the real `Riva-master/src/app/app.routes.ts` (an earlier stale local
  sandbox copy of this file briefly made it look unregistered — re-staged
  the file fresh from the real device and confirmed it's correctly wired;
  no frontend routing bug here, that earlier read was wrong and is retracted).
- Also, independently: `SidebarComponent` calls `ClinicService.myContext()` →
  `GET /api/clinic/my-context` — deliberately designed to never throw for a
  user with no clinic membership — on effectively every authenticated page,
  to decide whether to show clinic-staff nav items. This is likely the
  *actual* proximate trigger for a "site-wide" feeling failure, not only the
  one specific route.
- Laravel: both routes resolve through `EnsureClinicContext` middleware /
  `ClinicContextService::activeMemberships()` (`clinic/my-context` is
  deliberately outside the context-enforcing middleware but still queries
  the same table) → `User::clinicMemberships()` → real Eloquent query against
  the `clinic_user` table.
- Migrations: `backend/database/migrations/2026_08_23_000005_create_clinic_user_table.php`
  (plus `_000006_backfill_clinic_of_one_for_doctors.php`,
  `_000008_add_permissions_to_clinic_user_table.php`, and the newer
  `2026_08_23_000004_create_clinics_table.php` / `..._000010_create_clinic_services_table.php`,
  `2026_08_24_000001_add_cost_scalability_indexes.php`,
  `2026_08_26_000001_create_pharmacy_foundation_tables.php`) all exist,
  read correctly, are idempotent (`hasTable`/`hasColumn` guards), and pass
  in the sandbox's own SQLite test database (231/231 backend tests green,
  including tenant-isolation and pharmacy tests that depend on these exact
  tables). The code is correct.

**Conclusion: this is not a bug in the application.** It is that these
migrations have never actually been executed against the real `riva_clean`
MySQL database on the user's machine — almost certainly because most of
this session's (and recent prior sessions') work was done through the
device-file-bridge, which can write files but cannot run `php artisan`
commands (`device_bash` reports "Workspace unavailable" every time it was
tried, both this session and the prior one). No commit/session before this
one that added these migrations was ever followed by an actual `migrate`
run on this specific database.

## Technical Requirements
On the real machine, from the `backend/` directory, with the correct DB
credentials already configured in `.env` (do not change `.env` — this task
does not require it):

```
php artisan migrate:status
```

to see exactly which of the ~45 migrations are already applied vs. pending
(this alone will likely make the gap visible), then:

```
php artisan migrate
```

to apply everything pending. This is additive/idempotent — every migration
in this codebase that creates a table guards with `Schema::hasTable(...)`
first, and the one data migration in this set
(`2026_08_23_000006_backfill_clinic_of_one_for_doctors.php`) explicitly
skips any doctor who already has a `clinic_user` row — so it is safe to run
even if some subset of these migrations already partially applied by some
other means. It does **not** require `--force` unless `APP_ENV=production`
in that `.env` (if it does prompt, that's expected and safe to confirm).

No destructive operation is involved — nothing here drops or truncates any
existing table.

## Acceptance Criteria
- `php artisan migrate:status` shows every migration as `Ran`.
- Loading `/clinic-services`, `/clinic-staff`, `/clinic-queue`,
  `/clinic-patients` as a clinic-member user (or simply loading any
  authenticated dashboard, which triggers the sidebar's `my-context` call)
  no longer throws the `clinic_user` SQLSTATE error.
- `GET /api/clinic/my-context` returns `{"has_clinic": false}` for a normal
  patient/caregiver, and a real clinic list for a doctor/staff member,
  instead of a 500.

## Notes for the next agent session
Once this is unblocked, verify (this could not be checked without DB
access): whether `2026_08_23_000006_backfill_clinic_of_one_for_doctors.php`
actually gave every pre-existing real Doctor row a clinic-of-one — if any
doctor accounts predate this migration and still end up with zero clinic
membership after `migrate`, they will not be able to reach the clinic-scoped
screens at all (`ClinicContextService::resolve()` 403s with "not an active
member of any clinic"), which would be a second, follow-up gap worth its own
task at that point — but this cannot be verified from here without seeing
real production data.
