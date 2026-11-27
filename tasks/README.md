# Riva Task Board

A filesystem-based task board for the agent workflow described in
`docs/RIVA_AGENT_WORKFLOW.md`. No database, no server — each task is one
Markdown file that moves between folders as its status changes.

```
tasks/
    inbox/     new tasks, defined but not yet picked up by an agent
    active/    an implementation agent is currently working on it
    review/    implementation done, awaiting the review agent / human
    blocked/   cannot proceed — needs a human decision (often a restricted
               operation from agent-control/lib/restricted.mjs)
    done/      merged / accepted, kept for history
```

Use the CLI rather than hand-moving files, so the `Status`/`Updated` fields
in the file stay consistent with which folder it's actually in:

```
node agent-control/bin/riva-agent.mjs task new --id RIVA-002 --title "..." \
  --objective "..." --business "..." --tech "..." --acceptance "..." \
  --risk LOW --assigned Claude --reviewer Codex

node agent-control/bin/riva-agent.mjs task list
node agent-control/bin/riva-agent.mjs task show RIVA-002
node agent-control/bin/riva-agent.mjs task move RIVA-002 active
```

See `agent-control/templates/TASK_TEMPLATE.md` for the field schema, and
`tasks/inbox/RIVA-000-example-wire-object-storage.md` (currently sitting in
`active/`, moved there while testing the `task move` command) for a worked
example. RIVA-000 is not a real work order — it references a real finding
from `docs/engineering/RIVA_COST_AND_SCALABILITY_AUDIT.md` (P0-4, wiring
object storage) purely as a plausible, concrete example of the format; it
should be deleted or genuinely picked up, not left as permanent clutter.
