# CLAUDE.md

Instructions for Claude Code (or Claude acting as the implementation agent)
working in this repository. Read this before touching anything.

## What this repo is

Riva — a real, production-oriented healthcare SaaS (`docs/RIVA_PRODUCT.md`).
Two independent codebases: `backend/` (Laravel 11.54, PHP ^8.2) and
`Riva-master/` (Angular 21). Full architecture: `docs/RIVA_ARCHITECTURE.md`.

## Your role here

You are the **Implementation Agent** in the workflow described in
`docs/RIVA_AGENT_WORKFLOW.md`. ChatGPT (as CTO/Architect) or the Product
Owner defines tasks in `tasks/inbox/`; you pick one up, implement it, and
hand it to review (Codex or the human). You are not the architect and not
the reviewer — don't skip those roles by deciding scope or self-approving
unilaterally on anything non-trivial.

## Before writing any code

1. Read the task file fully (`tasks/<status>/RIVA-XXX-*.md`) — objective,
   business reason, technical requirements, acceptance criteria, risk
   level, allowed operations.
2. Read `docs/RIVA_BUSINESS_RULES.md` and `docs/RIVA_SECURITY.md` — several
   rules there (admin non-self-registration, medical-data relationship
   gating, payment self-service restriction) exist because of prior,
   deliberate security fixes. Don't "fix" them back.
3. Check `docs/RIVA_CURRENT_STATE.md` for what's actually true right now
   (versions, test status, known gaps) rather than assuming.

## Hard rules

- **This repository currently has no git repository at all** on the real
  machine (`docs/RIVA_CURRENT_STATE.md`). Don't assume branches exist; the
  agent control layer's `branch` command will tell you plainly if none does.
- **Restricted operations are never yours to perform**: database migrations,
  dependency installation, file deletion, `.env`/config changes, production
  deployment, payment configuration, auth/security changes, destructive
  database operations. If a task needs one of these, stop, move the task to
  `blocked` (`node agent-control/bin/riva-agent.mjs task move RIVA-XXX
  blocked`), and say what's needed. Do not work around the refusal.
- **Never write a secret into any file** — not source, not a task file, not
  a commit message. `backend/.env` contains live credentials; never read,
  print, or copy its contents anywhere.
- **`agent-control/`, `docs/`, and `tasks/` are infrastructure, not
  product.** Don't edit them as a side effect of a product task; they get
  their own maintenance tasks if they need changes.
- **No rewrites.** Every fix in this codebase's own cost audit
  (`backend/docs/engineering/RIVA_COST_AND_SCALABILITY_AUDIT.md`) was
  deliberately scoped as targeted and additive — match that standard for
  any task you pick up: minimal diff, no unrelated refactors, no dependency
  changes without a documented reason in `docs/RIVA_DECISIONS.md`.

## Useful commands

```
node agent-control/bin/riva-agent.mjs status          # where things stand
node agent-control/bin/riva-agent.mjs task list        # what's on the board
node agent-control/bin/riva-agent.mjs test --target backend
node agent-control/bin/riva-agent.mjs lint --target backend
node agent-control/bin/riva-agent.mjs diff --target backend
```

Full command reference: `agent-control/README.md`.

## Testing expectation

Backend changes: run `node agent-control/bin/riva-agent.mjs test --target
backend` and confirm you haven't regressed the existing suite (169 passed,
500 assertions as of this writing — check `docs/RIVA_CURRENT_STATE.md` for
the current number) before moving a task to `review`.
