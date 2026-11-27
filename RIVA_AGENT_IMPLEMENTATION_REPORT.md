# RIVA Agent Control Layer — Implementation Report

Companion to `docs/RIVA_AGENT_WORKFLOW_AUDIT.md` (written first, audit-only)
and `docs/RIVA_AGENT_WORKFLOW.md` (the operating workflow this layer
supports). This report is what actually shipped.

**No file under `backend/` or `Riva-master/` was created, modified, or
deleted by this task.** Everything below is new infrastructure/documentation
living beside those two codebases.

---

## Files created

```
CLAUDE.md
AGENTS.md
RIVA_AGENT_IMPLEMENTATION_REPORT.md   (this file)
docs/RIVA_PRODUCT.md
docs/RIVA_ARCHITECTURE.md
docs/RIVA_BUSINESS_RULES.md
docs/RIVA_COST_MODEL.md
docs/RIVA_SECURITY.md
docs/RIVA_DATABASE.md
docs/RIVA_API_CONTRACT.md
docs/RIVA_DECISIONS.md
docs/RIVA_CURRENT_STATE.md
docs/RIVA_AGENT_WORKFLOW.md
docs/RIVA_AGENT_WORKFLOW_AUDIT.md
agent-control/README.md
agent-control/bin/riva-agent.mjs
agent-control/lib/workspace.mjs
agent-control/lib/git.mjs
agent-control/lib/tasks.mjs
agent-control/lib/restricted.mjs
agent-control/lib/report.mjs
agent-control/config/workspace.json
agent-control/templates/TASK_TEMPLATE.md
agent-control/reports/REPORT-<timestamp>.md   (generated output, one example committed)
tasks/README.md
tasks/inbox/.gitkeep
tasks/active/RIVA-000-example-wire-object-storage.md
tasks/review/.gitkeep
tasks/blocked/.gitkeep
tasks/done/.gitkeep
```

29 files total (28 hand-authored + 1 generated report). All paths are 1–3
folders deep from the repo root — well within the device bridge's staging
depth limit encountered in prior sessions.

## Files modified

None. No existing file (product or otherwise) was changed.

## Dependencies added

**Zero.** `agent-control/bin/riva-agent.mjs` and its `lib/` modules use only
Node's built-in `fs`, `path`, `child_process`, and `url` modules. No
`package.json` was created for `agent-control/` and none is needed — there
is nothing to `npm install`. Backend `test`/`lint` commands invoke `php`
and `git`, both already required by the existing project.

## Commands implemented

| Command | Category | What it does |
|---|---|---|
| `status` | READ | Git state (branch, uncommitted changes, recent commits) for root/backend/frontend, plus task board counts |
| `branch <task-id> [--target] [--kind]` | SAFE | Creates `agent/<id>` or `review/<id>`, refuses on dirty tree, no repo, or protected branch name |
| `diff [--target] [--staged]` | READ | `git diff` wrapper |
| `test [--target]` | SAFE | Backend: `php artisan test`. Frontend: explicitly refuses to guess between Karma/Vitest, tells you to run it yourself |
| `lint [--target]` | SAFE | Backend: `php vendor/bin/pint --test` (non-mutating check). Frontend: reports that no linter is configured |
| `report` | SAFE | Writes a timestamped git+task-board snapshot to `agent-control/reports/` |
| `doctor` | READ | Self-check: workspace root, target existence, secret-path guard sanity, confirms no HTTP server |
| `task new/list/show/move` | SAFE | Task board CRUD |
| `restricted <key>` | — | Always refuses; explains why |

## Security controls (implemented, not just documented)

- **No HTTP server / no listening socket** — the strongest possible reading
  of "must not expose an unrestricted HTTP API": there is no API to expose.
- **Workspace confinement** (`lib/workspace.mjs`): every path is
  `path.resolve()`d and checked against the repo root before use.
- **Secret-path guard**: `.env`/`.env.*`/`*secret*`/`*credential*`/`*.pem`/
  `*.key`/`id_rsa*` refused for read or write, independent of the
  workspace-root check.
- **No shell-string command execution** — every `git`/`php` call uses
  `execFileSync` with a fixed argv array.
- **Task-ID sanitization** — strips anything outside `[A-Za-z0-9._-]`,
  collapses `..` runs, strips leading dots/hyphens.
- **Restricted operations refused unconditionally** — no bypass flag exists.
- **Git safety** — branch creation refuses on: no repository, dirty working
  tree, or a protected branch name (`main`/`master`).

## Tests performed (this session, against the sandbox mirror — see
Remaining Limitations for why not the live Windows machine)

All run via `node agent-control/bin/riva-agent.mjs <command>` unless noted:

1. `help` — prints the command reference. ✅
2. `doctor` — confirmed ROOT resolves correctly, all three targets detected,
   secret-path guard sane, HTTP server absent. ✅
3. `status` — correctly reported: root has no git repo, backend has git
   with 2 uncommitted files (matching known prior-session state exactly),
   frontend has no git repo, task board empty. ✅ (matches ground truth
   independently confirmed via the device bridge earlier in this session)
4. `task new` / `task list` / `task show` / `task move` — created
   `RIVA-000`, listed it, showed its full rendered content, moved it
   `inbox → active`; confirmed the file's `Status`/`Updated` fields and its
   folder location both changed correctly. ✅
5. `branch RIVA-000 --target backend` — **correctly refused** because the
   backend working tree is genuinely dirty (a pre-existing
   `storage/logs/laravel.log` modification plus an untracked audit file from
   a prior session) — this is the intended safety behavior, not a failure. ✅
6. `restricted db:migrate` — printed the refusal message, exited 1. ✅
7. `restricted nonsense` (unknown key) — errored clearly rather than
   silently no-op'ing. ✅
8. `diff --target root` (no git repo) — reported "nothing to diff" rather
   than crashing. ✅
9. Direct unit probes of `workspace.mjs` (not via the CLI, via a small
   inline Node script): `sanitizeId('../../etc/passwd')` → `'etc-passwd'`;
   `assertInsideWorkspace('/etc/passwd')` → threw `WorkspaceViolation`;
   `assertInsideWorkspace('.../backend/.env')` → threw `WorkspaceViolation`
   even though that path is inside the workspace root. ✅
10. `lint --target backend` (`php vendor/bin/pint --test`) — ran
    successfully, non-mutating, reported pre-existing style findings across
    ~100 files (not fixed — out of scope, see `docs/RIVA_CURRENT_STATE.md`).
    Confirmed via `git status --short` immediately after that **no files
    were modified** by the lint check. ✅
11. `test --target backend` (`php artisan test`) — **169 passed, 500
    assertions**, matching the last known-good count exactly, confirming
    zero regression from anything in this task. ✅
12. `report` — generated a correct snapshot; regenerated after fixing a
    real bug found during testing (see below). ✅

**A real bug was found and fixed during this testing pass**: the initial
`tasks/{inbox,review,blocked,done}/.gitkeep.md` placeholder files were
picked up by `listTasks()`/`boardCounts()`'s `.md`-extension filter and
miscounted as real tasks. Fixed by renaming them to `.gitkeep` (no
extension) and re-verified `task list`/`report` show correct counts
(0/1/0/0/0 for inbox/active/review/blocked/done, matching the single real
task, `RIVA-000`, sitting in `active/`).

## Remaining limitations

- **`device_bash` (a live shell on the operator's actual Windows machine)
  was unavailable this entire session** ("Workspace unavailable"). Every
  test above ran against a disposable sandbox mirror of `backend/` (which
  has the same PHP/Composer/git toolchain, just not the real machine) —
  `Riva-master/` and the new `agent-control/`/`docs/`/`tasks/` trees were
  authored and delivered but their CLI paths were only exercised against
  the sandbox copy of the repo layout, not literally on `D:\`. **The
  operator should run `node agent-control/bin/riva-agent.mjs status` on
  their own machine once this is synced, as a final live smoke-test.**
- **The real project has no git repository.** `branch`/`diff` against
  `root` or `frontend` will report "no git repository" until a human
  initializes one — this is documented behavior, not a bug (see
  `docs/RIVA_DECISIONS.md` D5).
- **Frontend `test`/`lint` are intentionally not automated** — the test
  runner is ambiguous (Karma vs. Vitest) and no linter is configured at
  all. The CLI says so rather than guessing.
- **No approval-token mechanism exists for restricted operations** — by
  design (`docs/RIVA_DECISIONS.md` D3), not as a gap to fill in later.
- **This report and the docs it accompanies have not yet been committed to
  any git history**, since none exists at the root — they exist on disk
  only, pending the operator's decision on D5.

## How to start the local agent

There's nothing to start — it's a CLI. From the repo root:
```
node agent-control/bin/riva-agent.mjs help
```

## How to stop it

Nothing to stop — no background process, no daemon.

## How to create a task

```
node agent-control/bin/riva-agent.mjs task new --id RIVA-001 \
  --title "Add composite index to patient_doctor_relationships" \
  --objective "Speed up the most common medical-data authorization check" \
  --business "P1-1 in the cost audit — highest-ROI index fix identified" \
  --tech "Additive migration only, see docs/RIVA_DATABASE.md" \
  --acceptance "Migration adds (doctor_id,status) and (patient_id,status) composite indexes; existing test suite still passes" \
  --risk LOW --assigned Claude --reviewer Codex
```

## How to execute a task

1. `node agent-control/bin/riva-agent.mjs task move RIVA-001 active`
2. `node agent-control/bin/riva-agent.mjs branch RIVA-001 --target backend`
   (requires git to exist first — see Remaining Limitations)
3. Implement, respecting `CLAUDE.md`/`AGENTS.md`.
4. `node agent-control/bin/riva-agent.mjs test --target backend`
5. `node agent-control/bin/riva-agent.mjs lint --target backend`
6. `node agent-control/bin/riva-agent.mjs task move RIVA-001 review`

## How human approval works

There is no in-tool approval flow. For a restricted operation
(`node agent-control/bin/riva-agent.mjs restricted <key>` lists and explains
all eight), the tool refuses unconditionally and the human runs the real
command themselves, outside this tool, when they've decided to. For a
normal task, "approval" is the human merging the reviewed branch — this
tool does not merge anything itself.

## Future MCP integration point

Not implemented. See `docs/RIVA_AGENT_WORKFLOW.md` ("Future MCP
Integration") for exactly where it would attach and what it must preserve
(binding to `127.0.0.1` only, wrapping the existing `lib/` functions rather
than reimplementing them, still refusing every restricted operation).
**No MCP connection exists in this repository today.**
