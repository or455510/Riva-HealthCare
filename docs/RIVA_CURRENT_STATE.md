# RIVA_CURRENT_STATE.md

Snapshot as of **2026-08-24**, taken directly from the real repository and
device bridge this session. This file is meant to be refreshed periodically
(e.g. via `node agent-control/bin/riva-agent.mjs report`) rather than trusted
indefinitely — check the date above before relying on it for anything
time-sensitive.

## Versions

| Component | Version | Source |
|---|---|---|
| Laravel | 11.54.0 installed (`^11.31` required) | `backend/composer.json`, confirmed installed |
| PHP | `^8.2` required | `backend/composer.json`; actual interpreter version on the operator's machine: `UNKNOWN / REQUIRES VERIFICATION` (device shell unavailable this session) |
| Angular | `^21.0.0` | `Riva-master/package.json` |
| npm | `11.6.2` declared | `Riva-master/package.json` `packageManager` field |
| Node (operator's machine) | `UNKNOWN / REQUIRES VERIFICATION` | device shell unavailable this session |

## Git

**No `.git` repository exists anywhere in the real project** on the
operator's machine (`D:\cv\project-riva\rivatest-main\`) — confirmed by
listing the root and `backend/` directly; dotfiles like `.env`/`.gitignore`
are visible in those listings, `.git` is not. See `RIVA_DECISIONS.md` (D5)
for what this means for the agent control layer's design.

## Tests

Backend: **169 passed, 500 assertions** (`php artisan test`, run this
session via the agent control layer — see
`RIVA_AGENT_IMPLEMENTATION_REPORT.md`). Frontend: no test run performed this
session; the runner to use is itself ambiguous (Karma vs. Vitest both
present) — see `docs/RIVA_AGENT_WORKFLOW_AUDIT.md` §3.

## Lint

Backend: `php vendor/bin/pint --test` run this session, non-mutating —
reports a large number of pre-existing style deviations across
migrations/models/controllers (formatting only, e.g. `braces_position`,
`concat_space`, `ordered_imports`). **Not fixed as part of this task** — the
brief's own rule is no business-logic-unrelated changes without a task/human
decision, and bulk-reformatting 100+ files is exactly the kind of change
that belongs in its own reviewed task, not a side effect of building the
agent tooling. See `tasks/inbox/` for how to open that as a proper task.
Frontend: no linter configured at all (no ESLint, no lint script).

## Known product gaps (from the prior cost/scalability audit, not
re-verified line-by-line this session — see that document for detail)

No API rate limiting, no caching layer in use, `QUEUE_CONNECTION=sync`, all
file uploads on local disk, no CI/CD, no Docker, pharmacy feature entirely
unbuilt. Full detail:
`backend/docs/engineering/RIVA_COST_AND_SCALABILITY_AUDIT.md`.

## What exists in this repo as of this task

- `agent-control/` — the CLI described in
  `RIVA_AGENT_IMPLEMENTATION_REPORT.md`.
- `tasks/` — the task board, seeded with one worked example (`RIVA-000`).
- `docs/RIVA_*.md` — this documentation set.
- `CLAUDE.md`, `AGENTS.md` — root-level operator/agent guides.

Nothing under `backend/` or `Riva-master/` was modified by this task.
