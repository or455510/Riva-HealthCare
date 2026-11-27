# RIVA — Agent Control Layer: Pre-Implementation Audit

**Status: AUDIT ONLY. Written before any control-layer code was created.**
Scope: the real repository at `D:\cv\project-riva\rivatest-main\` on the operator's Windows machine (`backend/` = Laravel, `Riva-master/` = Angular), inspected directly via the device bridge and a disposable sandbox git mirror of `backend/` used only for read/diff purposes. Nothing in this document is inferred or assumed — every claim below was checked against the real files this session; anything not directly checkable is marked `UNKNOWN / REQUIRES VERIFICATION`.

---

## 1. Current Architecture

- **Backend**: Laravel Framework **11.54.0** installed (`composer.json` requires `laravel/framework: ^11.31`), **PHP ^8.2** required. Single monolithic Laravel app at `backend/`. Sanctum (`^4.3`) for API auth, Socialite (`^5.27`) for Google OAuth, Reverb (`^1.10`) configured for broadcasting. Standard Laravel 11 skeleton layout (`app/`, `routes/`, `database/`, `config/`, `tests/`).
- **Frontend**: Angular **21.0.x** (`@angular/core: ^21.0.0`), Angular CLI `^21.0.1`, TypeScript `~5.9.2`, standalone-component style. Declared package manager: **npm 11.6.2** (`"packageManager": "npm@11.6.2"` in `Riva-master/package.json`).
- **Two separate codebases, two separate toolchains, no build-time coupling** — `backend/` is a pure API (Vite here only builds backend-served assets, not the Angular app), `Riva-master/` is a standalone Angular CLI project. They are developed and deployed independently today.
- **Runtime versions actually installed on the operator's Windows machine** (PHP CLI version, Node version, npm version as actually run on `D:\`): `UNKNOWN / REQUIRES VERIFICATION` — this session's device bridge (`device_bash`) reported "Workspace unavailable" throughout, so no command could be executed live on the real machine. The sandbox mirror used for read/diff work runs PHP 8.4.21 and Node v22.22.2, which satisfy the declared constraints, but this is **not proof of what actually runs on the Windows box**.

## 2. Existing AI Tooling

- **No `CLAUDE.md`, `AGENTS.md`, `.claude/`, `.codex/`, MCP config, or any other AI-agent configuration file exists anywhere in the repository** — not at the `rivatest-main` root, not in `backend/`, not in `Riva-master/`. Confirmed by a full non-recursive listing of all three root locations plus targeted search inside `backend/`.
- The operator's Windows **user home directory** (outside the repository entirely — outside `rivatest-main`) contains a `.codex` folder and an `.agents` folder among many other tool-cache directories (`.cache`, `.continue`, `.copilot`, `.wallaby`, etc.). These are OS-user-level and were not opened — they are out of scope for this repo audit and are **not** evidence of any existing per-repo agent configuration. Noted only so it isn't mistaken for repo-level tooling later.
- `docs/engineering/IMPLEMENTATION_JOURNAL.md` and `docs/engineering/RIVA_COST_AND_SCALABILITY_AUDIT.md` (inside `backend/docs/engineering/`) are prior-session human-readable engineering records, not agent configuration or tooling.

## 3. Existing Development Tooling

- **Backend package manager**: Composer. `require-dev` includes `laravel/pint` (formatter/linter — usable via `vendor/bin/pint`), `phpunit/phpunit`, `mockery/mockery`, `fakerphp/faker`, `laravel/sail`, `laravel/pail`, `nunomaduro/collision`. **No PHPStan/Larastan or other static-analysis tool is installed.** No `pint.json` — Pint runs on Laravel's default preset.
- **Backend tests**: `tests/Unit` and `tests/Feature`, run via `phpunit.xml`. Test environment already uses `sqlite`/`:memory:` for `DB_CONNECTION`, `array` for `CACHE_STORE`/`MAIL_MAILER`/`SESSION_DRIVER`, and `sync` for `QUEUE_CONNECTION` — the suite is **self-contained and does not touch the real MySQL database or send real mail/SMS**, which makes `php artisan test` genuinely safe to automate. (Prior-session runs report 169 passed / 500 assertions as of the last backend change; not re-verified this session.)
- **Backend scripts**: `backend/scripts/` contains two ad-hoc manual QA scripts (`qa_email_test.php`, `qa_latest_notification.php`) — not part of any automated pipeline.
- **Frontend package manager**: npm. `devDependencies` include `@angular/cli`, `karma` + `karma-jasmine` (the wired `ng test` runner) **and separately** `vitest` as a devDependency with no visible config file or npm script pointing at it. **Which test runner is actually used is ambiguous — `UNKNOWN / REQUIRES VERIFICATION`.**
- **Frontend lint**: no ESLint config, no lint-related devDependency, and no `lint` script in `package.json`. **There is currently no frontend linter wired at all.** (Prettier config exists inline in `package.json`, which formats but does not lint.)
- **No Docker, no `docker-compose`, no `Dockerfile`, no `.dockerignore`** anywhere in `rivatest-main` (root, `backend/`, or `Riva-master/`).
- **No CI/CD** — no `.github/` directory, no other CI config file found anywhere in the tree.

## 4. Existing Git State

**No `.git` directory exists anywhere in the real project on the operator's machine** — confirmed by listing `D:\cv\project-riva\rivatest-main` (top level: `.sixth`, `backend`, `RIVA_Master_Blueprint.docx`, `Riva-master` — no `.git`) and `D:\cv\project-riva\rivatest-main\backend` directly (dotfiles like `.env`, `.gitignore`, `.gitattributes` are present and visible, but no `.git`). **The real repository is currently unversioned.**

The git history referenced in prior engineering-journal entries (Slice 5–8 commits, etc.) exists **only inside this session's disposable sandbox mirror** (`/tmp/work/rivatest-main/backend`, a throwaway clone-equivalent used purely so I could produce clean diffs while editing) — it has no remote configured and was never pushed anywhere. **It is not present on the real machine and does not represent real version control for this project.**

Other git-adjacent observations:
- `backend/.gitignore` is present and reasonable (excludes `/vendor`, `/node_modules`, `.env`, `/storage/pail`, etc.).
- `Riva-master/.gitignore` is present.
- `backend/` contains several stray snapshot archives sitting in the working directory root — `app.zip`, `config.zip`, `database.zip`, `resources.zip`, `routes.zip` — which look like manual ad-hoc backups of specific folders, consistent with a project that has been relying on manual copies instead of version control.

**This is the single most important audit finding for the control layer's design**: every "git-aware" capability the task requests (current branch, uncommitted changes, `agent/RIVA-XXX` branches, never touching `main`) has no repository to operate on yet. The control layer must detect this state explicitly and refuse branch/diff operations with a clear message rather than silently failing or, worse, assuming a branch exists. Initializing git is treated in this audit as a **recommended but not yet executed** step — see §7 and §9 — because it is a real, repo-wide, irreversible-in-spirit decision (what root to init at, what to include) that the operator should confirm rather than have decided implicitly by a first CLI run.

## 5. Risks

1. **No version control on the real project at all.** Every "AI agent implements a change" workflow the operator described depends on branches and diffs that cannot exist until git is initialized. Until then, any code change made directly to the working tree (by a human or an agent) is unrecoverable except via the stray `.zip` backups, which are partial and out of date.
2. **Secrets live in plaintext `.env`** (`backend/.env` — confirmed present, contains live `MAIL_PASSWORD`, `TWILIO_AUTH_TOKEN`, `GEMINI_API_KEY`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_CLIENT_ID`). Any tool that shells out or reads files in this repo must treat `.env`/`.env.*` as untouchable-and-unreadable by default. This is the highest-severity thing the control layer's workspace guard must get right.
3. **No frontend linting exists at all**, and it is genuinely unclear which frontend test runner (Karma or Vitest) is authoritative — a control-layer "run lint"/"run tests" command for the frontend risks silently doing the wrong thing (or nothing) if this isn't explicit and verified with the operator first.
4. **Two independent codebases with no shared root tooling.** A control layer that assumes one unified `git status`/one unified `npm test` will not match reality; it must treat `backend/` and `Riva-master/` as distinct targets.
5. **The device bridge used for this audit has no live shell this session** (`device_bash` reports "Workspace unavailable"). Anything built here can be authored and delivered to the real machine, but **cannot be executed live against the real machine this session** — only against the sandbox mirror. This must be stated plainly in the implementation report rather than implied to have been fully verified end-to-end.
6. **Ad-hoc `.zip` snapshots inside `backend/`** are a sign of prior manual, undocumented backup behavior — a future automated tool must not assume these are safe to delete or treat as its own backup mechanism.

## 6. Missing Components

- No `.git` repository (see §4).
- No CI/CD pipeline of any kind.
- No Docker/containerization.
- No static analysis tool for PHP (Pint exists but is a formatter, not a static analyzer).
- No linter for the Angular frontend.
- No existing task/issue-tracking mechanism inside the repo (no `tasks/`, no `TODO.md`, no issue templates).
- No existing agent/AI configuration of any kind (`CLAUDE.md`, `AGENTS.md`, MCP config) at the repo level.
- No documented environment-setup or "how to run this locally" beyond the default Laravel `README.md` boilerplate (not independently re-verified this session, but nothing more specific was found).

## 7. Recommended Agent Control Layer Architecture

Given §1–§6, the safest and simplest architecture that satisfies "local-first, secure-by-default, minimal dependencies, minimal infrastructure" is:

- **A CLI, not a server.** No HTTP endpoint of any kind in this phase — nothing to bind to `127.0.0.1`, nothing to secure against remote access, because it simply doesn't listen on a socket. This removes an entire class of the risks the brief explicitly warns about, at zero cost.
- **Node.js**, using only Node's built-in modules (`fs`, `path`, `child_process`, `readline`) — no `npm install`, no new `package.json` dependency, anywhere. Node was chosen over a shell script because the operator's machine is Windows (`platform: win32` per the device bridge), and a `.sh`/bash-based tool would not run natively there, whereas Node already runs on this machine (it's required for the Angular frontend) and is genuinely cross-platform for the filesystem/process operations this tool needs. PHP was considered too (also already required) but Node's cross-platform path handling and JSON-native task files made it the better fit; this is a judgment call, documented here rather than hidden.
- **Workspace-confined by construction**: the CLI computes its allowed root as the parent of its own install directory (`agent-control/..`) and refuses to touch any path that resolves outside that root, or inside `.env`/`.env.*`/anything matching a secret-like filename pattern.
- **Task board on the filesystem** (`tasks/inbox|active|review|blocked|done/*.md`) — plain Markdown files with a fixed field schema, human-readable, diffable once git exists, requires no database, no server, no new dependency.
- **Restricted operations (migrations, dependency installs, file deletion, env/config changes, deploy, payment/auth changes, destructive DB ops) are not automated at all in this phase** — the CLI recognizes the categories and actively refuses them with an explanatory message, rather than implementing an in-tool "approval" flow that would itself be new attack surface. A human runs those commands themselves, deliberately, outside the tool. This is the simplest possible reading of "must require explicit human approval" that introduces zero new risk.
- **Git-awareness is defensive, not assumed**: every command that touches git first checks whether `.git` exists in the resolved root and, if not, explains that a repository must be initialized (via an explicit, separate, human-invoked step) before branch/diff features work.

## 8. Proposed File Structure

```
rivatest-main/                         (repo root — currently unversioned, see §4)
├── CLAUDE.md                          (new — root-level operator/agent guide)
├── AGENTS.md                          (new — role definitions: Owner/CTO-Architect/Implementer/Reviewer)
├── RIVA_AGENT_IMPLEMENTATION_REPORT.md (new — delivered at the end of this task)
├── docs/                              (new — cross-cutting product/architecture docs, root-level; does not touch backend/docs/engineering)
│   ├── RIVA_PRODUCT.md
│   ├── RIVA_ARCHITECTURE.md
│   ├── RIVA_BUSINESS_RULES.md
│   ├── RIVA_COST_MODEL.md
│   ├── RIVA_SECURITY.md
│   ├── RIVA_DATABASE.md
│   ├── RIVA_API_CONTRACT.md
│   ├── RIVA_DECISIONS.md
│   ├── RIVA_CURRENT_STATE.md
│   ├── RIVA_AGENT_WORKFLOW.md
│   └── RIVA_AGENT_WORKFLOW_AUDIT.md   (this file)
├── agent-control/                     (new — the control layer itself; not part of the Riva product)
│   ├── README.md
│   ├── bin/riva-agent.mjs
│   ├── lib/
│   │   ├── workspace.mjs
│   │   ├── git.mjs
│   │   ├── tasks.mjs
│   │   ├── restricted.mjs
│   │   └── report.mjs
│   ├── config/workspace.json
│   └── templates/TASK_TEMPLATE.md
├── tasks/                             (new — task board)
│   ├── README.md
│   ├── inbox/RIVA-000-example-task.md
│   ├── active/
│   ├── review/
│   ├── blocked/
│   └── done/
├── backend/                           (existing — untouched)
└── Riva-master/                       (existing — untouched)
```

Nothing under `backend/` or `Riva-master/` is created or modified by this plan.

## 9. Security Considerations

- **Secrets**: `.env`/`.env.*` in either subproject must never be read, printed, diffed, or included in any generated report by the control layer. Enforced in code (§7), not just by convention.
- **No listening sockets**: with no HTTP server in this phase, "bind to 127.0.0.1 only" has nothing to violate — this is the strongest possible posture for this phase, and is called out explicitly so a future contributor doesn't casually add an HTTP layer without re-reading `docs/RIVA_SECURITY.md`.
- **No arbitrary shell execution from input**: every `child_process` call the CLI makes uses a fixed, hardcoded argv array (e.g. `['artisan', 'test']`), never string-interpolated user input passed to a shell. Task titles/IDs used in file paths are sanitized (alphanumeric + hyphen only) before being used to construct filesystem paths, to prevent path-traversal via a malicious task ID.
- **Workspace confinement**: every path the CLI touches is `path.resolve()`d and checked to still be inside the computed root before any read/write.
- **Restricted-operation refusal is unconditional** in this phase (§7) — there is no bypass flag, no config toggle, no "approved" token the CLI recognizes. Approval means a human runs the real command themselves.
- **No git repository yet (§4) means no branch-protection to enforce today.** Once `git init` happens (a human-invoked, separate decision — see §7), the CLI's branch-creation command still independently refuses to create branches named `main`/`master` or to commit directly while checked out on either, as defense-in-depth even though those branches don't exist yet.

## 10. Cost Considerations

- **Zero new paid services, zero new infrastructure.** Everything proposed runs as local processes on the operator's existing machine using tooling (Node, PHP, Composer, npm, git) that is either already required by the project or already installed.
- **No new npm/composer dependencies** are added by this phase — the CLI uses only Node built-ins. If a future phase genuinely needs a dependency (e.g., a proper YAML/TOML parser, or a real MCP SDK), that should be justified in `docs/RIVA_DECISIONS.md` before being added, per the brief's own instruction.
- **Test/lint commands the CLI wraps are already free to run** — `php artisan test` uses `sqlite`/`array` drivers (§3), so it costs nothing and touches nothing external; Pint is a local formatter. No command this CLI can invoke makes an external paid API call (Gemini, Twilio, Google OAuth are all product-runtime concerns the CLI never touches).
- **This tool itself never runs unattended or on a schedule** in this phase — it is invoked by a human, on demand, so it adds no standing compute cost.

---

*Per the brief: this audit precedes and gates implementation. §7–§8 above describe what was then built; see `RIVA_AGENT_IMPLEMENTATION_REPORT.md` at the repo root for what actually shipped and how it differs, if at all, from this plan.*
