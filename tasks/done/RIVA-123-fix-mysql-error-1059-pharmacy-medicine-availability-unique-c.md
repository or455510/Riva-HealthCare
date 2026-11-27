# RIVA-123: Fix MySQL error 1059: pharmacy_medicine_availability unique constraint name too long

- **ID:** RIVA-123
- **Title:** Fix MySQL error 1059: pharmacy_medicine_availability unique constraint name too long
- **Status:** DONE
- **Risk Level:** LOW
- **Assigned:** UNASSIGNED
- **Reviewer:** UNASSIGNED
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-25T00:19:15.299Z
- **Updated:** 2026-08-25T00:19:15.359Z

## Objective
Reported directly by the user running `php artisan migrate` for real, for the
first time this session, against their actual dev database (unblocking
RIVA-120). 11 of 12 pending migrations applied; the last one,
`2026_08_26_000001_create_pharmacy_foundation_tables` (RIVA-110), failed:
```
SQLSTATE[42000]: Syntax error or access violation: 1059 Identifier name
'pharmacy_medicine_availability_pharmacy_branch_id_medicine_id_unique'
is too long (Connection: mysql, SQL: alter table
`pharmacy_medicine_availability` add unique
`pharmacy_medicine_availability_pharmacy_branch_id_medicine_id_unique`
(`pharmacy_branch_id`, `medicine_id`))
```
Root cause: `$table->unique(['pharmacy_branch_id', 'medicine_id'])` with no
explicit name lets Laravel auto-generate one from the table + column names —
68 characters here, over MySQL/MariaDB's hard 64-character identifier limit.
SQLite (used by this repo's test suite) doesn't enforce that limit, which is
why the existing suite never caught this — it only surfaced against a real
MySQL server.

## Real-world complication this fix also had to handle
Because the migration failed partway through, `pharmacy_medicine_availability`
was left existing on the real database with its columns and foreign keys but
without the unique constraint or the plain `(medicine_id, is_available)`
index (both were declared inside the same `Schema::create()` closure, which
only got as far as the failing `unique()` call before throwing). The original
code's `if (! Schema::hasTable(...))` guard means a naive re-run would skip
`Schema::create()` entirely on retry (table already exists) and never apply
the missing indexes at all.

## Fix
`database/migrations/2026_08_26_000001_create_pharmacy_foundation_tables.php`:
- The unique constraint now has an explicit, short name:
  `pharmacy_medicine_availability_branch_medicine_unique` (53 chars).
- Both the unique constraint and the plain index were moved out of the
  `Schema::create()` closure into their own idempotent, `Schema::hasIndex()`
  -guarded steps — so this migration now correctly self-heals a database
  left in the exact partially-created state the user's real run produced,
  not just a from-scratch install.
- Checked every other identifier this migration generates (all 4 tables'
  foreign keys and indexes) against the 64-character limit — everything else
  was already safely under it (max 61 chars); only this one constraint name
  was over.

## Verification performed (sandbox, real Laravel app)
- New `tests/Feature/PharmacyFoundationMigrationTest.php` (4 tests): every
  identifier this migration generates is asserted ≤64 chars (a portable,
  DB-engine-independent regression guard for the actual root cause, since
  SQLite can't reproduce the MySQL-specific failure directly); the renamed
  unique index and the plain index are both confirmed to actually exist
  after migrating; the (branch, medicine) uniqueness behavior the constraint
  exists for is confirmed to actually work (`QueryException` on a duplicate
  insert); running `migrate` twice in a row is confirmed idempotent
  (simulating exactly the retry-after-partial-failure scenario this was
  found in).
- Full backend suite: **243/243 passing** (239 + these 4 new), up from the
  236/236 baseline earlier this session.
- `./vendor/bin/pint --test`: passed.
- Synced to the real project via the device bridge; the real fix has **not
  yet been re-verified by actually re-running `php artisan migrate` against
  the user's real MySQL/MariaDB server** — that's the next immediate manual
  step (see Acceptance Criteria).

## Acceptance Criteria
- [x] Root cause identified from the user's real, actual error output (not
      guessed).
- [x] Fix makes the migration idempotent against a database left in the
      exact partial-failure state this bug produces, not just a clean
      install.
- [x] Every identifier this migration generates verified under MySQL's
      64-character limit.
- [x] Full suite green (243/243) + Pint clean, in the sandbox.
- [x] Synced to the real project on the user's machine.
- [ ] Re-verified by the user actually re-running `php artisan migrate`
      against their real database and confirming it now completes all 12
      migrations with no FAIL line.
