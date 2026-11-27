# RIVA_AGENT_WORKFLOW.md

How the operator is meant to use the agent control layer, day to day. See
`docs/RIVA_AGENT_WORKFLOW_AUDIT.md` for the audit that preceded and shaped
this design, and `RIVA_AGENT_IMPLEMENTATION_REPORT.md` for exactly what was
built.

## Roles

These are distinct identities. The control layer never pretends two of them
are the same thing, and neither should any documentation or task file.

| Role | Who | Responsibility |
|---|---|---|
| **Product Owner** | The human operator | Defines requirements, grants approval for restricted operations, has final say on everything |
| **CTO / Architect** | ChatGPT | Converts a requirement into a well-scoped task (fills out the fields in `agent-control/templates/TASK_TEMPLATE.md`) |
| **Implementation Agent** | Claude Code | Picks up a task from `tasks/inbox/`, creates a branch, implements, runs tests |
| **Review Agent** | OpenAI Codex (or another independent reviewer) | Reviews the implementation agent's work independently before it reaches the human |

## The intended end-to-end flow

1. Owner defines a requirement (in conversation, in an issue, however it
   naturally arises).
2. CTO/architect (ChatGPT) converts it into a task: runs (or asks a human to
   run) `node agent-control/bin/riva-agent.mjs task new --id RIVA-XXX
   --title "..." --objective "..." --business "..." --tech "..."
   --acceptance "..." --risk LOW|MEDIUM|HIGH`.
3. The task lands in `tasks/inbox/`, status `INBOX`.
4. Implementation agent (Claude Code) picks it up:
   `node agent-control/bin/riva-agent.mjs task move RIVA-XXX active`.
5. Agent creates a branch: `node agent-control/bin/riva-agent.mjs branch
   RIVA-XXX --target backend` (or `frontend`) — this refuses automatically
   if there's no git repository yet or the working tree is dirty (see
   `docs/RIVA_CURRENT_STATE.md` — as of this writing there is no git
   repository at all on the real machine; this step is currently blocked
   until a human initializes one, see `docs/RIVA_DECISIONS.md` D5).
6. Agent implements the change **inside `backend/` or `Riva-master/` only**
   — never inside `agent-control/`, `docs/`, or `tasks/` as part of a
   product task (those are infrastructure, edited only by control-layer
   maintenance tasks).
7. Agent runs tests: `node agent-control/bin/riva-agent.mjs test --target
   backend`. Runs lint: `node agent-control/bin/riva-agent.mjs lint --target
   backend`.
8. Agent moves the task to review: `task move RIVA-XXX review`.
9. Independent reviewer (Codex, or a human) reviews the diff:
   `node agent-control/bin/riva-agent.mjs diff --target backend`.
10. Human approves. Changes merge (a human action — this tool does not
    merge branches itself; merging isn't in the READ/SAFE operation list
    the brief defined, and isn't automated here).

If a task turns out to require a restricted operation partway through, move
it to `blocked` (`task move RIVA-XXX blocked`) and describe what's needed —
don't attempt to route around the refusal.

## Restricted operations (never automated by this tool)

`node agent-control/bin/riva-agent.mjs restricted <key>` explains each of
these; running it always refuses and exits non-zero:

| Key | Category |
|---|---|
| `db:migrate` | Database migrations |
| `deps:install` | Dependency installation (composer/npm install or require) |
| `file:delete` | Deleting files |
| `env:modify` | Modifying environment configuration (`.env` / `config/*.php`) |
| `deploy:prod` | Production deployment |
| `payment:config` | Payment configuration changes |
| `auth:modify` | Authentication/authorization changes |
| `db:destructive` | Destructive database operations |

These require a human to run the real command themselves, deliberately,
outside this tool. There is no approval flag the CLI understands — see
`docs/RIVA_DECISIONS.md` D3 for why that's a deliberate design choice, not
an oversight.

## Safe operations (automated, no approval needed)

Repository/task status, branch creation (git-safety-checked), running the
backend test suite, running the backend lint check, git diff, and
engineering-report generation. See `agent-control/README.md` for the exact
command list.

## Future MCP integration point

**Not implemented in this phase.** The task brief is explicit that MCP
should only be added if actually required by installed tooling, and none
was found (`docs/RIVA_AGENT_WORKFLOW_AUDIT.md` §2). The intended future
shape, when it *is* needed:

```
ChatGPT
   ↓
Secure MCP / approved connector
   ↓
Riva Agent Control Layer   <- this repo's agent-control/, extended with an MCP server
   ↓
Claude Code
   ↓
Riva
```

When that future MCP server is added, it should: wrap the existing
`agent-control/lib/*.mjs` functions rather than reimplement them, bind to
`127.0.0.1` only if it needs a local transport at all, continue refusing
every restricted operation exactly as `lib/restricted.mjs` does today, and
get its own entry in `docs/RIVA_DECISIONS.md` justifying the addition
before any MCP SDK dependency is installed. **This connection does not
exist yet — nothing in this repository currently talks to ChatGPT or Codex
programmatically.**

## Removing this entirely

Everything described here is additive and separable:
`rm -rf agent-control tasks CLAUDE.md AGENTS.md docs/RIVA_PRODUCT.md
docs/RIVA_ARCHITECTURE.md docs/RIVA_BUSINESS_RULES.md docs/RIVA_COST_MODEL.md
docs/RIVA_SECURITY.md docs/RIVA_DATABASE.md docs/RIVA_API_CONTRACT.md
docs/RIVA_DECISIONS.md docs/RIVA_CURRENT_STATE.md docs/RIVA_AGENT_WORKFLOW.md
docs/RIVA_AGENT_WORKFLOW_AUDIT.md RIVA_AGENT_IMPLEMENTATION_REPORT.md`
removes it completely, with zero effect on `backend/` or `Riva-master/`.
