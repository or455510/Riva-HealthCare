# RIVA_DECISIONS.md

An architecture-decision log for the agent control layer and the docs set
around it. Product-code decisions from earlier engineering work live in
`backend/docs/engineering/IMPLEMENTATION_JOURNAL.md`; this file is scoped to
the control-layer/documentation infrastructure created in this task.

---

### D1 — CLI, not an HTTP server (this phase)

**Decision**: No HTTP server, no listening socket at all, in this phase.
**Why**: The task brief's own security requirements (no unrestricted HTTP
API, localhost-only if one ever exists, no unauthenticated remote execution)
are trivially and completely satisfied by not having a server. A CLI
invoked directly by a human/agent process achieves every "READ"/"SAFE
OPERATIONS" capability requested without that risk surface.
**Revisit when**: a real need emerges for something *other than the
operator's own machine* to reach this tool (e.g. a genuinely remote
ChatGPT-orchestrated flow) — at that point, add the local HTTP layer
deliberately, bound to `127.0.0.1`, and re-read `docs/RIVA_SECURITY.md`
first.

### D2 — Node.js for the CLI, zero new dependencies

**Decision**: `agent-control/bin/riva-agent.mjs`, using only Node's built-in
`fs`/`path`/`child_process` modules.
**Why**: The operator's machine is Windows (`platform: win32`, confirmed via
the device bridge this session) — a bash script would not run natively
there. Node is already required for the Angular frontend, so this adds zero
new runtime dependency. PHP was considered (also already required) but
Node's cross-platform path handling and native JSON support fit a
filesystem-based task board better.
**Alternative considered and rejected**: a PHP Artisan command inside
`backend/` — rejected because it would live *inside* the product codebase
the brief explicitly says this tool must stay separate from, and because it
couldn't cleanly operate on `Riva-master/` as well.

### D3 — Restricted operations are refused unconditionally, not gated by an approval token

**Decision**: `agent-control/lib/restricted.mjs` always refuses; there is no
flag, config value, or token this tool recognizes as "approved."
**Why**: An in-tool approval mechanism is itself new attack surface (a
stolen/guessed token, a misconfigured default-approve, etc.). The simplest
correct design is: the tool never does the dangerous thing, ever; a human
does it themselves, outside the tool, when they've decided to.
**Trade-off accepted**: this means the tool cannot show a full "approved →
executed" audit trail for restricted operations, only a "here's what was
refused and when" one (nothing to log for the actual execution, since the
tool never performs it).

### D4 — Root-level `docs/` for cross-cutting docs, `backend/docs/engineering/` untouched

**Decision**: The 10 required `RIVA_*.md` docs plus the audit live in a new
`docs/` at the repo root (`rivatest-main/docs/`), not inside `backend/docs/`.
**Why**: These docs cover both `backend/` and `Riva-master/` plus the agent
workflow itself — putting them inside `backend/` would misrepresent their
scope, and `backend/docs/engineering/` already has its own established
convention (`IMPLEMENTATION_JOURNAL.md`, the cost audit) from prior
engineering work that this task should not disturb.

### D5 — Git initialization is not performed by this task

**Decision**: The real repository has no `.git` anywhere (see
`RIVA_CURRENT_STATE.md`). This task documents that finding and gives the
control layer defensive handling for it, but does **not** run `git init`
itself.
**Why**: Deciding *what* to initialize (one repo at the `rivatest-main` root
covering both subprojects? two separate repos? what should the first commit
contain, given the stray `.zip` snapshots already sitting in `backend/`?) is
a real, repo-shaping decision the operator should make deliberately, not one
that should be an implicit side effect of a documentation/tooling task.
**Recommendation for the human**: initialize a single git repository at the
`rivatest-main` root (covering `backend/`, `Riva-master/`, `agent-control/`,
`docs/`, `tasks/` together) so the git-aware branch workflow this control
layer is built around actually has something to operate on. This is a
recommendation, not an action taken.

### D6 — Task board is Markdown files on disk, not a database

**Decision**: `tasks/{inbox,active,review,blocked,done}/*.md`.
**Why**: Zero new infrastructure, human-readable without tooling,
naturally diffable once git exists, and matches the brief's own suggested
structure exactly.
