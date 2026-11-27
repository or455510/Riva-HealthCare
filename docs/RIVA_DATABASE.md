# RIVA_DATABASE.md

## Verified facts (this session)

- `DB_CONNECTION=mysql`, single host, database name `riva_clean`, no read
  replica, no connection pooler configured — read directly from
  `backend/.env`.
- **34 Eloquent models** in `backend/app/Models/`, **34 migration files**
  in `backend/database/migrations/` — counted directly this session.
- Testing uses `DB_CONNECTION=sqlite` with `:memory:`
  (`backend/phpunit.xml`) — the test suite never touches the real MySQL
  database.
- Schema evolves through Laravel migrations only — there is currently no
  git history backing those migrations on the real machine (see
  `RIVA_CURRENT_STATE.md` §Git), so the migration files themselves are, for
  now, the only record of schema history.

## Key tables involved in the two authorization models (`RIVA_ARCHITECTURE.md`)

- `patient_doctor_relationships`, `patient_caregiver_relationships` — gate
  medical-data access via a `status` column (`active`/`accepted`).
- `clinics`, `clinic_user` (pivot) — gate clinic-operational-data access;
  `clinic_user` carries `role`, `status`, and a `permissions` JSON array.
- `clinic_services` — priced services a clinic offers, optionally referenced
  by an `appointments.service_id`.

Full column-level schema for these or any other table is **`UNKNOWN /
REQUIRES VERIFICATION`** — this document does not reproduce an ERD, since
doing so without reading every migration file this session would risk
staleness the moment a migration changes. An implementation agent should
read the actual migration file(s) for a table before writing code against
it, not rely on this summary for column names/types.

## Known database-cost findings (see the full audit for detail and citations)

`backend/docs/engineering/RIVA_COST_AND_SCALABILITY_AUDIT.md` §4 documents:
missing composite indexes on the two relationship tables above (the
highest-ROI single index fix identified in that audit), several controllers
loading unbounded/unpaginated result sets, and one recurring scheduled
command (`medications:mark-missed`) that reloads and rebuilds data
unchunked on every run.

## Restricted operations that touch this file's subject matter

Per `docs/RIVA_AGENT_WORKFLOW.md`, **database migrations and any destructive
database operation (drop, truncate, force-seed outside the testing
environment) are restricted operations** — the agent control layer refuses
to automate them (`node agent-control/bin/riva-agent.mjs restricted
db:migrate` / `db:destructive`). A new index migration is a good example of
something *additive and safe* that this document's cost-audit findings
recommend, but it should still go through the task-board + human-approval
flow like any schema change, not be run directly by an agent.
