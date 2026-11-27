# AGENTS.md

Generic agent-instructions file for this repository — read by tools that
look for `AGENTS.md` by convention (e.g. Codex-style CLI agents), alongside
`CLAUDE.md` for Claude Code specifically. Both files describe the same
workflow; this one is written role-agnostically.

## Repository

Riva — a real healthcare SaaS platform. `backend/` (Laravel 11.54, PHP
^8.2), `Riva-master/` (Angular 21). See `docs/RIVA_PRODUCT.md` and
`docs/RIVA_ARCHITECTURE.md` before making any assumption about how this is
built.

## Agent roles in this repository

| Role | Identity | Notes |
|---|---|---|
| Product Owner | human | final approval authority |
| CTO / Architect | ChatGPT | task definition |
| Implementation Agent | Claude Code | picks up tasks, implements, tests |
| Review Agent | OpenAI Codex (or another independent reviewer) | reviews implementation-agent output before human approval |

Full workflow: `docs/RIVA_AGENT_WORKFLOW.md`. **These roles are never
collapsed into one identity** — a review agent reviewing its own
implementation, or an implementation agent self-approving a restricted
operation, defeats the point of having the roles at all.

## The task board

`tasks/{inbox,active,review,blocked,done}/*.md`. Use
`node agent-control/bin/riva-agent.mjs task ...` to read/create/move tasks
rather than hand-editing files, so status stays consistent with which
folder a task lives in. Template: `agent-control/templates/TASK_TEMPLATE.md`.

## What every agent must do before implementing anything

1. Read the task file completely.
2. Read `docs/RIVA_BUSINESS_RULES.md` and `docs/RIVA_SECURITY.md`.
3. Confirm the task's "Allowed Operations" field doesn't require something
   restricted (below) — if it does, the task should not have left `inbox`
   without a human's explicit sign-off on that specific operation.

## Restricted operations — refused by the tooling, not just by convention

Database migrations, dependency installation, file deletion, environment
configuration changes, production deployment, payment configuration
changes, authentication/authorization changes, destructive database
operations. `node agent-control/bin/riva-agent.mjs restricted <key>`
explains each and always refuses — there is no override this tool accepts.
A human performs these directly, outside any agent's control.

## Git

As of this writing, **the real project has no git repository at all**
(`docs/RIVA_CURRENT_STATE.md`). Every git-dependent command in
`agent-control/` detects this and explains it rather than failing silently.
Once a human initializes git (a deliberate, not-yet-taken step — see
`docs/RIVA_DECISIONS.md` D5), the intended branch shape is:

```
main
 |
 +-- agent/RIVA-XXX     (implementation agent's work)
 |
 +-- review/RIVA-XXX    (review agent's branch, if it makes its own changes)
```

No agent commits directly to `main`/`master`, ever — the control layer's
branch-creation command refuses to create a branch with either of those
names as defense in depth, but the underlying rule is: don't.

## Secrets

`backend/.env` contains live credentials. No agent may read, print, log, or
copy its contents anywhere, for any reason, including debugging.

## Everything else

`docs/RIVA_AGENT_WORKFLOW.md` has the full step-by-step flow, the safe vs.
restricted operation split, and the future MCP integration point (not yet
implemented — see that file before assuming any MCP connection exists).
