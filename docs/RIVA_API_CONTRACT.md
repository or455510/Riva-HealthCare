# RIVA_API_CONTRACT.md

## Existing documentation (primary source — do not duplicate it, point to it)

`Riva-master/API_ENDPOINTS.md` (17.2 KB, present in the frontend repo) is
the pre-existing, hand-maintained endpoint reference. It was **not
re-audited line-by-line this session** — treat it as the primary API
reference and this file as orientation around it, not a replacement.

## Conventions confirmed this session / prior audit

- **Auth**: Laravel Sanctum bearer tokens. The frontend's functional HTTP
  interceptor (`auth.interceptor.ts`) attaches `Authorization: Bearer
  <token>` automatically; the prior cost audit found ~23 places in the
  frontend that also set this header manually (redundant with the
  interceptor — a cleanup candidate, not a bug — see the full audit §22
  P3-4).
- **Response envelope**: success responses are `{success, message, data}`;
  error responses are `{success, message, errors}`, produced via a base
  `Controller` helper (`Controller::success()`/`error()`) so this shape
  should be consistent across the 30 controllers in `backend/app/Http/Controllers/`.
- **Base URL**: the frontend reads it from an `API_BASE_URL` constant
  (`Riva-master/src/app/constants`) rather than hardcoding it per call.
- **Public vs. authenticated routes**: some endpoints are deliberately
  public (e.g. `GET doctors/{doctor}/services`, mirroring the existing
  public visibility of `Doctor::fee`) — public-endpoint status is a
  deliberate per-route decision, not a default, and should be treated as
  such when adding a new route.

## Restricted vs. safe with respect to the API surface

Changing an existing API contract (route shape, request/response fields,
auth requirements) is not in the CLI's restricted-operations list verbatim,
but the task brief that led to this control layer explicitly calls out "no
API contract changes unless necessary" as a hard constraint on any
cost/optimization-motivated work. Treat a contract change as requiring the
same human-approval flow as a restricted operation
(`docs/RIVA_AGENT_WORKFLOW.md`) even though the CLI won't mechanically block
it the way it blocks, say, a database migration.

## What's genuinely unverified

A full request/response schema audit (every field, every status code) was
not performed this session. `UNKNOWN / REQUIRES VERIFICATION` for anything
not explicitly stated above — read `Riva-master/API_ENDPOINTS.md` and the
relevant controller directly before building against a specific endpoint.
