# RIVA-XXX: <short title>

- **ID:** RIVA-XXX
- **Title:** <short title>
- **Status:** INBOX
- **Risk Level:** LOW | MEDIUM | HIGH
- **Assigned:** <Claude | Codex | UNASSIGNED>
- **Reviewer:** <Codex | Claude | UNASSIGNED>
- **Allowed Operations:** <comma-separated: read, safe-operations, or specific restricted keys if a human has pre-approved them for this task — see docs/RIVA_AGENT_WORKFLOW.md>
- **Created:** <ISO 8601 timestamp>
- **Updated:** <ISO 8601 timestamp>

## Objective
What outcome this task produces, in one or two sentences.

## Business Reason
Why this matters to the product/business — tie back to a real user, cost, or risk. Cite `docs/RIVA_COST_MODEL.md` / `docs/RIVA_BUSINESS_RULES.md` where relevant.

## Technical Requirements
Concrete implementation constraints: which files/subsystems this touches, what must NOT change, any relevant prior audit finding (cite file:line where possible).

## Acceptance Criteria
- [ ] Specific, checkable condition 1
- [ ] Specific, checkable condition 2

---
Generated with `node agent-control/bin/riva-agent.mjs task new`. Do not hand-edit the fields block above without also updating Status via `task move`, so the file stays consistent with which folder it lives in.
