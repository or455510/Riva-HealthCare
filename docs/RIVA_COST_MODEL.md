# RIVA_COST_MODEL.md

This is a summary. The full analysis — with file:line citations, explicit
stated assumptions, and a 5-scenario simulation table — lives at
`backend/docs/engineering/RIVA_COST_AND_SCALABILITY_AUDIT.md`. Read that
file before making any cost-motivated change; this page exists so an agent
picking up a task doesn't have to re-derive the philosophy from scratch.

## Cost philosophy (binding on every task in this repo)

> **Design for scale, deploy for current usage.**

Concretely: don't introduce a managed service, a new paid API, a queue
cluster, Kubernetes, or a microservice split unless something *measured*
requires it. The product's actual current infrastructure is a single-server
setup (`docs/RIVA_ARCHITECTURE.md`), and that is the *correct* choice today
— the risk is what breaks first as usage grows, not that anything today is
over-provisioned.

## Business model

SaaS: doctors/clinics pay a recurring subscription. The actual
billing/subscription enforcement mechanism is `UNKNOWN / REQUIRES
VERIFICATION` — no such table or integration was found in the backend.

## The five things that actually drive cost at scale (from the full audit)

1. **No API rate limiting** — the highest-leverage risk, because it's the
   one gap that caps nothing on the endpoint that spends real money per call
   (`/ai-assistant/message`, external Gemini API).
2. **No caching layer in use anywhere** (`CACHE_STORE=file`, zero
   `Cache::remember()` calls in `app/`).
3. **`QUEUE_CONNECTION=sync`** — every background job runs inline in the web
   request today; this is a reliability cost more than a dollar cost right
   now, but blocks the async patterns that keep dollar cost down at scale.
4. **All files on local disk**, not object storage — this is what actually
   blocks horizontal scaling, since a second app server wouldn't see the
   first server's uploaded files.
5. **An unchunked per-minute scheduled command**
   (`medications:mark-missed`) — the fastest-growing recurring compute cost
   identified in the whole audit.

## Cost simulation (headline numbers only — see the full audit §18 for
assumptions and methodology)

| Scenario | Doctors | Patients | Estimated infra cost/mo | Cost/doctor/mo |
|---|---|---|---|---|
| 1 | 10 | 1,000 | ~$30–60 | ~$4.50 |
| 2 | 50 | 25,000 | ~$180–350 | ~$5.30 |
| 3 | 100 | 100,000 | ~$600–1,100 | ~$8.50 |
| 4 | 500 | 1,000,000 | ~$4,500–7,500 | ~$12.00 |
| 5 | 1,000 | 5,000,000 | ~$20,000–32,000 | ~$26.00 |

All figures are order-of-magnitude planning estimates, not vendor quotes,
and assume the P0/P1 fixes below are applied — see the full audit for what
happens to Scenario 4–5's numbers if they aren't.

## Prioritized fixes (P0/P1 from the full audit — see it for P2/P3 and per-item detail)

**P0 — before any real production load**: API rate limiting; move
`QUEUE_CONNECTION` off `sync` and wrap the emergency-alert dispatch in a
try/catch; chunk `medications:mark-missed`; wire the already-defined `s3`
disk; fix the hardcoded WhatsApp recipient + queue the medication-reminder
sends.

**P1 — high**: composite indexes on the two relationship tables; paginate
`AdminController`'s 8 unbounded methods; paginate/fix `AppointmentController::index()`
(admin branch) and `ReportController::index()`'s unmatched-role fallthrough;
fix the N+1 + eager-loaded-reviews pattern in `DoctorController::index()`;
queue `ContactMessageController::store` and `NotificationService::sendEmail`;
convert the 32 eagerly-bundled Angular routes to `loadComponent`; route chat
through the already-installed real-time channel instead of polling.

## Any task in this repo that touches cost should

1. Cite this file or the full audit for *why* it matters, in the task's
   Business Reason field (`agent-control/templates/TASK_TEMPLATE.md`).
2. Prefer a config-only or additive fix (see the full audit's Migration
   Roadmap, §21) over a rewrite.
3. Not introduce a new paid service without writing the justification in
   `docs/RIVA_DECISIONS.md` first.
