# RIVA-112: Route chat through realtime channel instead of 5s HTTP poll

- **ID:** RIVA-112
- **Title:** Route chat through realtime channel instead of 5s HTTP poll
- **Status:** INBOX
- **Risk Level:** MEDIUM
- **Assigned:** UNASSIGNED
- **Reviewer:** Codex
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-24T12:23:54.149Z
- **Updated:** 2026-08-24T12:23:54.149Z

## Objective
Investigate actual Echo/Pusher/Reverb wiring before touching; replace chat.component.ts's 5s poll with the existing realtime channel.

## Business Reason
Audit P1-7: eliminates a continuous per-open-chat request stream; Pusher/Echo is already installed but unused for this.

## Technical Requirements
Riva-master chat.component.ts + whatever Echo/Reverb config already exists. Directive explicitly warns: do not blindly remove Reverb without confirming actual usage first.

## Acceptance Criteria
Chat updates arrive via realtime push, not polling; no regression in message delivery reliability
