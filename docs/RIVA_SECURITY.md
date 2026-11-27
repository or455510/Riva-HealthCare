# RIVA_SECURITY.md

Two things live under this filename in spirit: the security posture of the
**Riva product itself** (backend/frontend), and the security posture of the
**agent control layer** (`agent-control/`). They're kept in one file because
an agent picking up any task needs both before touching anything.

## Product security — known state (not re-audited line-by-line this session; sourced from the prior cost/security audit unless marked otherwise)

- **Secrets live in `backend/.env` in plaintext** — confirmed present this
  session: `MAIL_PASSWORD`, `TWILIO_AUTH_TOKEN`, `GEMINI_API_KEY`,
  `GOOGLE_CLIENT_SECRET`, `GOOGLE_CLIENT_ID`. Standard for a Laravel `.env`,
  but it means **any tool that reads files in this repo must never print,
  log, diff, or otherwise surface this file's contents.** The agent control
  layer enforces this in code — see below.
- Admin accounts cannot self-register (`RIVA_BUSINESS_RULES.md`) — verified
  this session.
- File access uses signed URLs with per-prefix ownership checks and
  path-traversal guards (`StorageFileController` +
  `tests/Feature/StorageFileAccessTest.php`, `SignedFileUrlTest.php`) — from
  the prior audit, corroborated by the backend test suite passing this
  session (`StorageFileAccessTest`'s "path traversal outside public disk is
  rejected" case ran and passed).
- **No API-wide rate limiting exists today** outside hand-rolled
  login/forgot-password limiters — flagged as the top finding in
  `backend/docs/engineering/RIVA_COST_AND_SCALABILITY_AUDIT.md` §12/§22
  (both a cost and an abuse-surface risk).
- No CORS misconfiguration or mass-assignment/IDOR findings beyond what's
  already listed in that audit's Security/Abuse Risks section (§12) — refer
  there for the full, current list rather than duplicating it here, since it
  will go stale if only one copy is kept current.

## Agent control layer security — what's actually implemented and why

- **No HTTP server, no listening socket, in this phase.** The strongest
  possible posture for "must not expose an unrestricted HTTP API" is to not
  have an API. If a local HTTP interface is ever added later it must bind to
  `127.0.0.1` only, and that is a deliberate future decision — see
  `docs/RIVA_AGENT_WORKFLOW.md` ("Future MCP Integration").
- **Workspace confinement** (`agent-control/lib/workspace.mjs`): every path
  the CLI touches is `path.resolve()`d and checked to remain inside the repo
  root before any read/write. Attempting to operate outside it raises a
  `WorkspaceViolation` and the command aborts. Verified this session with a
  direct probe against `/etc/passwd` (refused) and against
  `backend/.env` (refused as a secret-like path even though it's inside the
  workspace) — see `RIVA_AGENT_IMPLEMENTATION_REPORT.md` for the transcript.
- **No shell string interpolation.** Every `git`/`php` invocation
  (`agent-control/lib/git.mjs`, `bin/riva-agent.mjs`) uses
  `execFileSync` with a fixed argv array, never a shell command built from
  user input — so there is no command-injection surface regardless of what
  a task ID or branch name contains.
- **Task-ID sanitization** (`sanitizeId` in `workspace.mjs`) strips anything
  outside `[A-Za-z0-9._-]`, collapses `..` runs, and strips leading
  dots/hyphens, before a task ID is ever used to build a filesystem path or
  git branch name. Verified this session: `sanitizeId("../../etc/passwd")`
  → `"etc-passwd"`.
- **Restricted operations are refused unconditionally, not gated by an
  approval flag.** `agent-control/lib/restricted.mjs` recognizes 8
  categories (migrations, dependency installs, file deletion, env/config
  changes, prod deploy, payment config, auth changes, destructive DB ops)
  and always refuses, pointing to `docs/RIVA_AGENT_WORKFLOW.md`. There is no
  bypass this tool understands — approval means a human runs the real
  command themselves. This was a deliberate simplicity choice: an in-tool
  approval mechanism would itself be new attack surface to secure.
- **Git safety**: branch creation refuses on a dirty working tree, refuses
  if no git repository exists yet (see `RIVA_CURRENT_STATE.md` — the real
  project currently has none), and refuses to name a branch `main`/`master`.
- **Secret-path guard**: any filename matching `.env`/`.env.*`/`*secret*`/
  `*credential*`/`*.pem`/`*.key`/`id_rsa*` is refused for read or write by
  the workspace guard, independent of the workspace-root check.

## Rules for any agent (human-directed or AI) working in this repo

1. Never write a secret into a source file, a task file, a generated report,
   or a commit message. If a task genuinely requires a new secret, it's
   inherently a restricted operation (`env:modify`) — stop and ask a human.
2. Treat authentication, authorization, payment, and admin-account rules
   (`RIVA_BUSINESS_RULES.md`) as requiring explicit human sign-off before
   any change, even a "fix."
3. Prefer the CLI's `diff`/`status`/`report` commands to inspect state —
   never `cat`/print `.env` directly, even for debugging.
