# RIVA — Cost & Scalability Audit

**Status: AUDIT ONLY. No files were modified to produce this report.**
Scope: full backend (`backend/`, Laravel 11) and full Angular 21 frontend (`Riva-master/`), read directly from the real repository / real device — not inferred from prior documentation. Every finding below cites the actual file (and line, where practical) it was verified against.

Audited by five independent read-only passes (database/multi-tenancy, API/controllers, queues/notifications/cache, file-storage/security/deployment, Angular frontend), then synthesized here. One frontend file — the doctor dashboard component — could not be reached due to a device-bridge tooling depth limit; this is called out explicitly wherever it matters and is not silently assumed clean.

---

## 1. Executive Summary

Riva's backend architecture is **better than a typical "training project"** in the places that matter most for a healthcare SaaS: multi-tenancy isolation is genuinely sound (two deliberate, correctly-enforced models — relationship-based for medical data, clinic-membership-based for operational data), the newer clinic-scoped controllers (`Admin\AdminAreaController`, `QueueController`, `ClinicServiceController`) already do pagination and tenant-scoping correctly, and file access control (signed URLs, per-prefix ownership checks, path-traversal guards) is solid.

But the codebase is **not yet cost-safe at SaaS scale**, and the risk is concentrated in a small number of systemic gaps rather than scattered everywhere:

- **No rate limiting on the API** (outside hand-rolled login/forgot-password limiters). This is the single highest-leverage finding — it directly caps nothing on the endpoint that spends real money per call (`/ai-assistant/message`, external Gemini API).
- **No caching layer in active use anywhere** (`Cache::`/`remember()` — zero matches in `app/`), and the current `.env` even sets `CACHE_STORE=file`, which wouldn't help across multiple app servers regardless.
- **`QUEUE_CONNECTION=sync`** in the live `.env` — every `ShouldQueue` job (there is exactly one: `SendEmergencyAlertNotifications`) runs inline in the HTTP request today, and its retry/backoff logic is currently a no-op. Separately, mail is sent via `->send()` (blocking) rather than `->queue()` in the one path that does queue-aware notifications, so fixing `QUEUE_CONNECTION` alone would not fix email latency.
- **All uploaded files (chat attachments, profile photos, doctor-verification documents) live on local application-server disk.** The `s3` filesystem disk is defined in config but has no credentials, no package installed, and is never called — it is an unwired stub, not a partial migration.
- **A per-minute scheduled command (`medications:mark-missed`) loads every patient with an active medication unchunked**, and re-derives a full 7-day schedule per patient on every run — the most scale-sensitive piece of recurring backend work in the app, and currently the least efficient.
- **Several admin/list endpoints (`AdminController`'s 8 methods, `AppointmentController::index()` for admin, `ReportController::index()`'s unmatched-role fallthrough, `DoctorController::index()`) load unbounded result sets** with no pagination — these scale with total platform history/doctor count, not with page size.
- **No Dockerfile, no CI/CD, no OPcache reference anywhere in the repo.** Deployment today is undocumented/manual.
- On the frontend: **roughly 60% of routes are eagerly bundled** rather than lazy-loaded — and it's the wrong 60%, since the heaviest screens (all three role dashboards, chat, video-call, booking) ship in the main chunk while lighter marketing pages are correctly split out. Chat polls every 5s over plain HTTP despite `laravel-echo`/`pusher-js` already being installed and used elsewhere for WebRTC signaling.

None of this requires a rewrite. Every fix identified is targeted: add rate limiting, flip two config values plus one `.queue()` call, wire the already-defined `s3` disk, chunk one scheduled command, paginate five controllers, and reorder the Angular route table. This report treats all of that as the roadmap (§21), not as work already done.

---

## 2. Current Architecture

**Backend**: Laravel 11.31 / PHP 8.2, single monolithic application (`laravel/laravel` skeleton), Sanctum bearer-token auth, Socialite for Google OAuth, Laravel Reverb configured for broadcasting (not confirmed actively driving any frontend feature beyond WebRTC signaling, which actually uses Pusher/Echo). Response envelope pattern (`{success, message, data}` / `{success, message, errors}`) via a base `Controller` helper. 34 Eloquent models, 30 controllers (one `Admin/` subfolder), 34 migrations, exactly 1 Job class, 2 Mailables, 4 custom middleware.

**Frontend**: Angular 21 standalone-component app, `AuthGuard`/`AdminGuard`/`GuestGuard` route guards, one functional HTTP interceptor for bearer-token attachment, Tailwind utility styling, `apexcharts`/`ng-apexcharts` for dashboard charts, `laravel-echo`+`pusher-js` for WebRTC signaling.

**Data model**: two deliberate, separately-enforced tenancy/authorization boundaries (this is a real architectural strength, not incidental):
1. **Relationship-based** (medical data — appointments, reports, medications, daily statuses, chat, calls): gated by `PatientDoctorRelationship` / `PatientCaregiverRelationship` rows with `status IN (active, accepted)`. Clinic co-membership is deliberately *not* sufficient authorization for this data.
2. **Clinic-scoped** (operational data — queue, patient registration/search, staff management, services/prices): gated by `clinics` + `clinic_user` pivot (`ClinicUser` model: role, status, `permissions` JSON array), resolved per-request by `EnsureClinicContext` middleware / `ClinicContextService`, which only ever honors a client-supplied clinic context if it matches the caller's own active membership.

This is currently a **modular monolith already**, which is the right starting shape per the "design for scale, deploy for current usage" brief — there is no reason found in this audit to introduce microservices.

---

## 3. Current Infrastructure Assumptions

No deployment configuration exists in the repository (§13), so "current infrastructure" is inferred entirely from `.env`/`.env.example` defaults, which describe a **single-server, local-everything setup**:

- `QUEUE_CONNECTION=sync` — no queue worker.
- `CACHE_STORE=file` — no shared cache.
- `FILESYSTEM_DISK=local`/`public` — no object storage.
- `SESSION_DRIVER=file` — sessions not centralized (matters if the app ever runs on >1 instance; Sanctum bearer tokens make this less critical than session-cookie auth would be, but file-based cache/session both silently break correctness the moment there's more than one app server).
- `DB_CONNECTION=mysql`, single host, no read replica, no connection pooler.
- `BROADCAST_CONNECTION=reverb` in the real `.env` sampled this session (Reverb is Laravel's self-hosted WebSocket server) — but the frontend's actual real-time feature (WebRTC signaling) is wired through Pusher/Echo, not confirmed as consuming Reverb; this is worth a dedicated look outside this audit's scope, flagged as **UNNECESSARY** (possible unused service) rather than a hard finding since it wasn't directly traced end-to-end.
- No Redis actually in use despite `REDIS_*` variables being present in `.env` (they configure a client that nothing currently calls, since `CACHE_STORE`/`QUEUE_CONNECTION` aren't set to `redis`).

**Read as**: this app today would run correctly on a single small VPS. That is fine and appropriate for current usage — the risk is entirely about what breaks *first* under load, not about what's over-provisioned today.

---

## 4. Database Audit

*(Full findings from the dedicated database/multi-tenancy pass — see agent transcript for the complete per-table inventory of all 34 tables; only the audit judgments are reproduced here.)*

**SCALABILITY RISK — missing composite indexes on the authorization backbone.**
`patient_doctor_relationships` / `patient_caregiver_relationships` have only single-column FK indexes; nearly every medical-data endpoint (`ReportController`, `MedicationController`, `ChatController`, `CallController`, `RelationshipController`) queries them by `doctor_id`/`patient_id`/`caregiver_id` **+** `status`. This is the single highest-ROI index fix identified in this entire audit, because it sits underneath almost every authorization check in the app.
**FIX:** composite indexes `(doctor_id, status)`, `(patient_id, status)`, and the caregiver equivalents.

**SCALABILITY RISK — `users.role`/`phone` unindexed.** Admin aggregate counts and `ClinicPatientController::search()`'s exact-phone lookup both full-scan `users`. **FIX:** index `role`, index `phone`.

**SCALABILITY RISK — `messages` table.** No composite `(sender_id, receiver_id)` index; `StorageFileController` does a leading-wildcard `LIKE` against `file_url` **on every single attachment view**. **FIX:** composite index for the two-directional chat lookup; replace the LIKE with an exact-match lookup column.

**SCALABILITY RISK — `medication_logs.status`** has no standalone/composite index despite being filtered directly by the per-minute scheduled sweep (§7) and by dashboard adherence counts. **FIX:** composite `(status, scheduled_at)` — doubles as the fix needed for the scheduled-command finding in §7.

**SCALABILITY RISK — missing uniqueness constraints**, not just indexes: `group_chat_members` has no `unique(group_chat_id, user_id)` (duplicate memberships possible under concurrency); `daily_statuses` has no `unique(patient_id, date)` despite `DailyStatusController::store()` relying on `updateOrCreate` as if one existed (a real race-condition risk, not just a performance one).

**SCALABILITY RISK (retention) — `system_activity_logs`, `login_attempts`, `appointment_booking_logs`** are append-only, unindexed beyond PK (the first two) or write-only-never-read (`appointment_booking_logs`), with no pruning/archival job found anywhere. These become the largest tables in the database by row count at real scale.

**UNNECESSARY — duplicated "is this true" state across tables.** Doctor-verification status is tracked redundantly across 4 columns in 3 tables (`doctors.verification_status`, `doctors.is_verified`, `users.verification_status`, `doctor_verifications.status`), written by 3 separate controller code paths — `DoctorController::transformDoctor()` already has to `AND` two of them together, and `AdminController`'s own docblock documents a real, previously-shipped inconsistency here. Similarly, `users.is_active` and `users.status` track the same concept redundantly. **FIX:** pick one source of truth per concept; this is a correctness/maintenance risk more than a cost risk, but it compounds query complexity everywhere both fields are read.

**GOOD — KEEP IT.** `notifications`, `clinic_user`, `payments`, `clinic_services`, `record_access_logs`, `doctor_verifications`, `emergency_alerts` all have well-designed composite indexes matching their actual query patterns — three of them (the `daily_statuses`, queue-fields, and `clinic_user` migrations) are retrofits with docblocks naming the exact query that motivated the index, which is exactly the right engineering discipline and the template the other tables above should follow. `SoftDeletes` is unused platform-wide, which is currently low-risk only because no delete route exists yet for `Report`/`Diagnosis`/`MedicationLog`/`DailyStatus` — worth closing *before* any such route is added, not after.

**Multi-tenancy isolation — GOOD, verified independently, not just read from docblocks.** Every controller checked applies the correct boundary for its resource type; route-model-bound IDs are consistently re-validated against the caller's actual relationship/membership rather than trusted. One minor inefficiency (not a security bug): `QueueController` resolves clinic-doctor scoping via a correlated `whereHas('doctor', ...)` subquery on `doctors.user_id` instead of a direct `whereIn('doctor_id', ...)`, which defeats the `(doctor_id, appointment_date, queue_status)` composite index's leading column.

---

## 5. API Audit

*(Full per-endpoint findings from the dedicated API-cost pass; judgments reproduced here.)*

**COST RISK / SCALABILITY RISK — `AdminController`'s 8 methods** (`users`, `patients`, `doctors`, `caregivers`, `reports`, `alerts`, `activityLogs`, `loginAttempts`) each do an unbounded `Model::latest()->get()` — genuinely the worst finding in the API layer, because `Admin\AdminAreaController` already exists as the correctly-paginated replacement for the same data, per `AdminController`'s own in-file docblock. **This is dead-weight duplicate functionality, not just an unpaginated endpoint** — see §6 below.

**SCALABILITY RISK / SECURITY RISK — `AppointmentController::index()` (admin branch)** is deliberately unfiltered *and* unpaginated; **`ReportController::index()`** has no fallback branch at all for a role it doesn't recognize, meaning an unmatched role gets the *entire, unfiltered, eager-loaded report table* — the same "no `where()` = show everything" bug class that `AppointmentController`'s own inline comment documents having fixed elsewhere, just not applied here too.

**COST RISK — three endpoints do synchronous external I/O inline, unthrottled:**
- `POST /contact-message` — **public, unauthenticated**, synchronous `Mail::raw()` then synchronous Twilio WhatsApp call, no throttle.
- `POST /medication-reminder/send` — synchronous Mail + Twilio, **no timeout set on the Twilio call at all**, and the WhatsApp destination number is **hardcoded** (`+201060465845`) regardless of who triggers it — flagged as likely leftover debug code, outside this audit's normal scope but too load-bearing to omit.
- `POST /ai-assistant/message` — the one direct, per-call, paid-external-API cost sink in the app (Gemini), and it makes **two** sequential calls per chat message (a redundant key-validity preflight, then the real generation call) with **zero rate limiting**.

**SCALABILITY RISK — `DashboardController::admin()`** runs 11 queries every load (7 aggregates + 4 lists), none cached, for numbers that don't need sub-minute freshness. **`weeklyPatientActivity()`** (used by both doctor and caregiver dashboards) issues a query *inside a 7-iteration loop* instead of one grouped query — the codebase already has the correct pattern for this exact shape in `AdminAreaController::dailyCounts()`, it just wasn't reused here.

**SCALABILITY RISK — `DoctorController::index()`** (public, unauthenticated "browse doctors") is the single worst N+1 found in the whole codebase: for every doctor in the (unpaginated) result set, it runs a fresh per-doctor relationship-status query in PHP, and fully eager-loads every review row just to average them in PHP instead of `withAvg()`/`withCount()`. This is a public, likely high-traffic endpoint.

**GOOD — KEEP IT.** `Admin\AdminAreaController` is the one controller in the codebase that does list/search entirely correctly: SQL-side filtering, clamped `paginate()` (5–50 per page) on every list method. It is the right template to bring the rest of the API up to, not an alternative pattern to reconcile against.

---

## 6. Angular Audit

*(Full findings from the dedicated frontend pass — one file, the doctor dashboard, could not be reached; see §22 for why.)*

**SCALABILITY RISK — route bundling is backwards.** ~60% of routes (32 components) are eagerly imported into the main bundle, including the three role dashboards, chat, video-call/WebRTC, booking, and all four profile variants — exactly the screens every logged-in user hits immediately. Meanwhile lighter marketing pages and the entire admin section are correctly lazy-loaded. **This is the single highest-leverage frontend fix**: it's a mechanical route-config change (no logic change), and it directly shrinks first-load JS for every user regardless of role.

**COST RISK — chat polls every 5 seconds over plain HTTP** while the conversation is open, despite `laravel-echo`/`pusher-js` already being installed and used for WebRTC signaling — the real-time infrastructure to eliminate this poll already exists in the dependency tree, it's just not applied to chat.

**COST RISK — the patient dashboard polls a medication-status endpoint every 60 seconds** with no visibility/focus gating, for as long as the tab is open.

**COST RISK (minor, confirmed) — duplicate `myContext()` calls** when the sidebar and a clinic-scoped page component (e.g. `ClinicQueueComponent`) both independently probe clinic context on the same page load.

**UNNECESSARY — two dependencies appear to be dead weight**: `three`/`@types/three` (zero usage found anywhere reachable) and `@google/generative-ai` (the frontend correctly proxies AI calls through the backend already — confirmed no client-side import of the Gemini SDK — so this client package has no legitimate reason to be installed in a healthcare app's bundle at all, key-exposure risk or not).

**COST RISK — several oversized/duplicated static images** ship unconditionally (multiple >1MB PNGs; one image duplicated three times across different `public/` subfolders for ~4.6MB combined) with no build-time image-optimization step.

**GOOD — KEEP IT.** The single functional HTTP interceptor correctly attaches the bearer token and handles 401s centrally — the ~23 call sites that also manually build an `Authorization` header are redundant but harmless (a maintainability finding, not a cost one). `AdminAreaController`'s Angular consumers, the clinic-scoped screens (queue/staff/patients/services), and the WebRTC signaling path all show correct, single-fetch, non-duplicated request patterns.

**UNVERIFIED — doctor dashboard (`dashboard/dashboard/dashboard.component.ts`).** This file sits one folder past the device bridge's staging depth limit and could not be read this session by any agent. Given the identical 60-second polling pattern found on the patient dashboard, this file should be checked directly for the same issue before this finding is closed out — treat it as an open item, not as clean.

---

## 7. Queue Audit

**SCALABILITY RISK / RELIABILITY RISK — `QUEUE_CONNECTION=sync` makes the app's only `ShouldQueue` job's retry/backoff a complete no-op**, and this has a concrete, previously-undocumented correctness consequence: with `sync`, `SendEmergencyAlertNotifications` makes exactly one inline attempt; if every delivery channel fails on that one attempt, Laravel's `SyncQueue` immediately calls `failed()` (so admin escalation fires on attempt #1, not after 3 real tries) and then **re-throws the exception back through the `dispatch()` call**, which `EmergencyAlertController::store()` does not catch — meaning a patient submitting an emergency alert during a channel outage can receive an **uncaught 500** instead of the 201 success response the controller's own docblock promises. This is worse than "retries don't happen" — it's a live correctness gap masked by the fact that email/WhatsApp rarely both fail simultaneously in testing.
**FIX:** set `QUEUE_CONNECTION=database` (no new infrastructure required) with a supervised `queue:work` process, **and** wrap the `dispatch()` call in a try/catch regardless of queue driver, so a synchronous failure can never surface as a raw 500 to a patient mid-emergency.

**SCALABILITY RISK — the per-minute `medications:mark-missed` scheduled command is the most scale-sensitive piece of recurring work in the codebase, and currently the least efficient.** It loads *every* patient with an active medication via an unchunked `Patient::whereHas(...)->get()->each(...)`, and for each one re-derives a full 7-day schedule (up to 8 queries per patient) even though only "is anything due right now" is actually needed. It also has no `->withoutOverlapping()`, so once a run exceeds 60 seconds at real scale, overlapping executions against the same unlocked rows become a real risk (duplicate notifications, race conditions).
**FIX:** query `MedicationLog` directly for the due/pending predicate, `chunkById(500)` it, drop the redundant weekly-schedule warm-up from this sweep entirely, and add `->withoutOverlapping()` (or a `Cache::lock`) to the scheduled command.

**COST RISK — three controllers make synchronous, blocking external calls (Mail and/or Twilio WhatsApp) directly inside the HTTP request**, with no job/dispatch at all: `MedicationReminderController::send` (P0 — also has a hardcoded recipient number, which is an active bug beyond this audit's normal scope), `ContactMessageController::store` (P1 — public, unauthenticated, ~20s worst-case latency), and `AppointmentController::pay()` (P1 — calls `NotificationService::notify(..., sendEmail: true)` **twice, sequentially**, which is queueable-mailable-capable already but invoked via `->send()` not `->queue()`, meaning a payment confirmation blocks on two live SMTP round-trips before the patient sees a response).
**Note:** fixing `QUEUE_CONNECTION` alone does **not** fix these — `Mail::send()` is always synchronous regardless of queue driver; the call sites themselves must change to `->queue()`.

**Notification fan-out — currently fine at real recipient counts, but has no bulk-insert path if that changes.** Every "notify N people" path (emergency care-team, emergency-failure admin escalation, missed-medication caregiver notify) loops `Notification::create()` once per recipient rather than a batched `insert()`. No unbounded "notify every user on the platform" path was found anywhere — the actual scale risk is the per-minute scheduled command (above) multiplying this same per-recipient pattern every 60 seconds, not a single giant fan-out event.

**Priority for "move to a queue":**
- **P0:** `MedicationReminderController::send` (also fix the hardcoded recipient).
- **P1:** `ContactMessageController::store`; `NotificationService::sendEmail` (switch `->send()` → `->queue()` once `QUEUE_CONNECTION` is real).
- **P2:** `AuthController::forgotPassword` (already rate-limited, lower urgency); the missed-medication command's per-caregiver emails.
- **P3:** none identified — the AI assistant's synchronous Gemini call is *correctly* synchronous (a chat reply can't be queued), its problem is redundant calls + no rate limit, not queueing.

---

## 8. Redis/Cache Audit

**COST RISK — zero caching in active use anywhere** (`Cache::`, `->remember(`: zero matches across `app/`), and the current `.env` sets `CACHE_STORE=file`, which is meaningless the moment there's more than one app server (each instance would cache independently, or effectively not at all).

Genuine, verified caching candidates (queried repeatedly, changes rarely):

| Candidate | Evidence | Suggested TTL | Invalidation |
|---|---|---|---|
| `Disease` reference table | `DiseaseController::index()` — full unfiltered scan on every call, public/education content | 1 hour | on `Disease::create`/`update` |
| Gemini API-key validity preflight | Re-validated against Google on **every single chat message** (`AiAssistantController::checkGeminiKey()`) despite the key never changing between messages — this alone halves the external-call count per chat turn | 10–15 min | none needed (static key) |
| Admin/doctor dashboard role counts | `User::where('role', X)->count()` re-run identically on every dashboard load in both `DashboardController` and `AdminAreaController` | 1–5 min | none needed (acceptable staleness for a stat tile) |

**Cache misuse:** none found — there is no code caching something that shouldn't be (the finding is entirely absence, not misuse).

---

## 9. File Storage Audit

**COST RISK / SCALABILITY RISK — every uploaded file (chat attachments, profile photos, doctor-verification documents — national ID, medical license, syndicate card scans) is stored on local application-server disk today.** The `s3` disk is defined in `config/filesystems.php` but has no credentials set anywhere (`.env` and `.env.example` both leave `AWS_*` blank), **no S3-compatible package is even installed** (`composer.json` has no `league/flysystem-aws-s3-v3`), and nothing in `app/` ever calls `Storage::disk('s3')`. This is a genuinely unwired stub, not a partially-completed migration — treat it as needing to be built, not "flipped on."

This is the correct fix target the task brief itself specifies (*Application Server = Business Logic + API; Object Storage = Medical Files*) — for a document-heavy healthcare app this is one of the two or three most important pre-scale fixes in this whole audit, both for cost (bandwidth/CPU consumed serving files through PHP instead of a redirect) and for operational risk (no backup strategy possible for medical documents living only on one server's local disk; blocks horizontal scaling of the app tier, since a second app instance can't see files the first one wrote).

**COST RISK — no image resizing/compression/thumbnailing anywhere.** A full-resolution phone photo of an ID card (up to the existing 5–20MB per-file caps) is stored exactly as uploaded, even where only a small thumbnail is ever displayed.

**GOOD — KEEP IT, verified independently.** Upload validation (MIME + max-size) is present and reasonable on all three upload paths. File access control is sound: the signed-URL route re-validates its own signature and re-applies the same per-prefix ownership checks as the authenticated path; the three legacy authenticated file routes correctly deny-by-default per path prefix; path traversal is blocked via `realpath()` containment. One low-severity, already-self-documented gap remains: files are also reachable as static assets through the `public/storage` symlink, bypassing the ownership checks entirely for anyone who knows the random filename — low risk today (only low-sensitivity profile images currently take this path) but worth closing before a higher-sensitivity category ever does.

**Target design** (see §20 for the full recommendation): keep local disk for nothing user-permanent; move all three upload paths to S3-compatible object storage (a Cloudflare R2 or DigitalOcean Spaces bucket is a reasonable, non-AWS-locked, low-cost starting point given the brief's "avoid unnecessary AWS lock-in" instruction) with signed/pre-authorized URLs continuing to be the access-control mechanism (this requires no change to the existing signed-URL security model — only where the bytes physically live).

---

## 10. Notification Audit

Delivery priority as currently implemented: **in-app (always, synchronous DB write) → email (synchronous `Mail::send()`, not queued) → WhatsApp (synchronous Twilio call, emergency/contact/reminder paths only)**. This mostly already matches the brief's preferred ordering (push isn't implemented at all yet — there is no push-notification code path found anywhere in the app, which is worth noting as a gap relative to the product vision in the second directive, separate from this cost audit's scope). SMS specifically is not used anywhere — all "urgent channel" delivery goes through WhatsApp via Twilio, which is reasonable and matches the brief's "SMS only for critical/OTP" guidance by simply not using SMS at all today.

**No path was found anywhere that could produce 100,000+ notifications in one event** — this is a real, verified answer, not an absence-of-evidence claim: every fan-out (emergency care-team, admin escalation, missed-medication caregiver notify) is scoped to one patient's own small care team. The actual scale risk is different from what a naive reading of "bulk notifications" might suggest: it's the **per-minute scheduled command** (§7) repeating a small-N notification pattern every 60 seconds indefinitely, not a single giant broadcast.

**COST RISK — mail is synchronous everywhere** (`->send()`, never `->queue()`), which blocks HTTP requests/the scheduled command on live SMTP round-trip time regardless of `QUEUE_CONNECTION`. See §7 for the fix (this is one line per call site, but a real one).

**SECURITY RISK — clinical data logged.** `EmergencyAlertController::store()` writes `pain_level` (a clinical value) into the application log alongside `patient_id` — see §12 for the full logging finding.

---

## 11. Pharmacy Integration Audit

**Confirmed via full-repository grep (`pharmacy`/`pharmacies`, case-insensitive, across `app/`, `database/`, `routes/`, `config/`): zero matches.** There is no pharmacy model, migration, controller, or route anywhere in this codebase. This is a genuine absence, not an incomplete feature — nothing exists to audit for cost/scale yet.

Given the brief's explicit target ("Pharmacy Directory + Branches + Medicine availability + future API integration, without Riva depending on an internal Pharmacy ERP") and its explicit warning against building a full pharmacy ERP prematurely, the architecturally sound minimal foundation — **not built in this audit phase, description only**, for review before any implementation — would be:

- **Data needed initially:** a `pharmacies` table (name, address/geo, contact, verified/active flag) + a `pharmacy_branches` table if a pharmacy can have multiple physical locations (mirrors the existing `Clinic`/`clinic_user`-style tenant-root pattern already proven in this codebase — reuse that shape rather than inventing a new one), and a lightweight `pharmacy_medication_availability` join (pharmacy/branch ↔ medication name/SKU ↔ in-stock flag, no real-time inventory sync required at foundation stage).
- **API boundaries:** a read-only "search pharmacies near me / by medication" endpoint for patients, paginated and geo/text-filtered in SQL (not loaded-then-filtered in PHP, per §5's findings); a separate, permissioned write surface for a pharmacy's own staff to update their own availability data (mirroring the existing `ClinicUser`/`clinic.context` permission-gating pattern already built for clinics — do not invent a parallel authorization model).
- **Caching:** pharmacy directory data (name/address/branches) is exactly the kind of rarely-changing reference data flagged as a good caching candidate in §8 — short-TTL cache on directory listing/search results.
- **Synchronization / future external integration:** if/when a real pharmacy inventory system is integrated later, treat it exactly like the existing `PaymentGatewayInterface` abstraction in this codebase (an interface with one "honest local" implementation today, swappable later without touching controllers) — do not build a bespoke sync job against an assumed external API that doesn't exist yet.
- **Rate limits / failure handling:** any future outbound sync call to a real pharmacy system must go through a queued job (per §7's findings on where external I/O belongs), never inline in a request, and must degrade gracefully (stale-but-available data beats a failed page load).

This is a scoping recommendation for a future slice, not a proposal to build it now — flagged here per the audit brief's Phase 14 requirement, with implementation explicitly out of scope for this pass.

---

## 12. Security / Abuse Risks

**SECURITY RISK / COST RISK — no API-wide rate limiting exists.** Verified directly: `bootstrap/app.php` never calls `$middleware->throttleApi()`, no `RateLimiter::for()` custom limiter is registered anywhere, and a full grep of `app/` + `routes/` for `throttle:` found only two hand-rolled limiters inside `AuthController` (`login()`, `forgotPassword()`). **Not rate-limited at all:** `POST /auth/register` (unauthenticated, unlimited signups), `POST /contact-message` (unauthenticated), the entire public doctor/caregiver browse/search surface, `POST /ai-assistant/message` (authenticated but otherwise unlimited — the one endpoint with a direct, uncapped, per-call external cost), and every other authenticated route in the app.

**SECURITY RISK — clinical data (`pain_level`) written to the application log** tied to `patient_id`, outside whatever access controls protect the `EmergencyAlert` database record itself (`EmergencyAlertController::store()`). The rest of the ~35 sampled `Log::` call sites across the app were clean — no request bodies, no symptom/diagnosis free text, no medication names found logged elsewhere.

**SECURITY RISK — production-unsafe `.env` defaults, if these are what's actually deployed** (this audit reads the repo's checked-in `.env`, which may not equal the live production config — flagged as a question to confirm, not a certainty): `LOG_LEVEL=debug`, `LOG_STACK=single` (one unrotated, unbounded log file), `APP_DEBUG=true`, `APP_ENV=local`. `APP_DEBUG=true` in a real production deployment is a standard, serious Laravel anti-pattern (stack traces/config values leak into error responses).

**GOOD — KEEP IT, re-verified independently.** File access control, cross-clinic/cross-relationship authorization (§4), path-traversal protection, and mass-assignment protections on financial fields (`Appointment.amount` always server-derived, documented and tested) all held up under direct scrutiny — this is not a codebase with a scattered-security-holes problem; the gap is specifically the *absence of rate limiting*, which is a single, well-understood, single-point fix (§21).

---

## 13. Production Readiness

**Finding of absence — no containerization, no CI/CD.** A full repository search (excluding `vendor/`/`node_modules/`) for `Dockerfile*`, `docker-compose*`, `.github/workflows/`, `.gitlab-ci*` found nothing belonging to this application (only third-party packages' own bundled files, e.g. `laravel/sail`'s images). No `README.md` deployment/Docker/nginx/php-fpm section exists. No reference to OPcache anywhere in the repo.

**Practical read:** deployment today is presumably manual and undocumented. Before any real production traffic, at minimum: a versioned build artifact (Dockerfile is the standard, portable choice — this does *not* imply Kubernetes, a single-container deploy behind a simple reverse proxy is entirely appropriate at current scale), OPcache enabled in the PHP config, and a CI step that runs the existing PHPUnit suite (169 tests, confirmed passing as of this audit) automatically rather than only on-demand.

**Database connections** — standard Laravel/PHP-FPM behavior (each worker opens its own connection per request, no pooling configured); not a defect, but a capacity-planning input for whichever DB tier size is chosen (§18).

**No query logging left on in application code** — confirmed clean.

---

## 14. Scalability Risks

Ranked by how directly they scale with platform growth (not request volume — actual data/tenant growth):

1. **Missing composite indexes on `patient_doctor_relationships`/`patient_caregiver_relationships`** (§4) — every medical-data authorization check gets slower as total relationship-row count grows, and this table's row count grows with *total historical relationships ever created* (including ended/rejected ones, which are never pruned), not just active ones.
2. **The per-minute `medications:mark-missed` sweep** (§7) — its cost is `O(active patients with medications)` *every 60 seconds, forever*; this is the one piece of recurring backend work whose cost trajectory is the steepest as the platform grows.
3. **Unbounded admin/list endpoints** (§5) — grow with total platform history, not per-tenant data, meaning they get slower for *every* admin as *any* clinic's data grows, not just their own.
4. **Local-disk file storage** (§9) — blocks horizontal scaling of the app tier outright (a second app instance can't see the first instance's uploaded files) well before it becomes a raw storage-cost problem.
5. **No caching layer** (§8) — every dashboard/reference-data read pays full DB cost on every request; this compounds every other finding rather than being independently severe.
6. **No rate limiting** (§12) — not a "grows with legitimate usage" risk, but an unbounded-cost risk from a single bad actor, which is arguably worse for a SaaS business model than organic growth cost.

---

## 15. Cost Risks

Direct, quantifiable-per-call cost exposure (not just "will need a bigger server eventually"):

- **`/ai-assistant/message`** — real, per-call, paid external API spend (Gemini), doubled by the redundant key-preflight call, with zero cap.
- **Contact-message / medication-reminder Twilio WhatsApp sends** — real, per-message, paid external cost, one path (`contact-message`) reachable with no authentication at all.
- **Local-disk file storage growth** — every uploaded medical document is a permanent, unbounded storage-cost line item with no lifecycle/tiering policy possible until it's on object storage.
- **The 60-second scheduled sweep's DB load** — not a third-party bill, but real, continuous compute cost on the database tier that scales with patient count regardless of actual traffic.
- **Unrotated log file** (§12) — a slow-burn disk-cost and operational risk, compounding on the same disk as the file-storage growth above.

---

## 16. Cost Optimization Opportunities

Every P0/P1 fix in §21 is, by construction, also a cost-optimization opportunity — this section highlights the ones with the best effort-to-savings ratio specifically:

- **Rate limiting** — near-zero implementation cost (`$middleware->throttleApi()` + a handful of `throttle:` route annotations), directly caps worst-case external-API spend.
- **Caching the Gemini key-preflight check** — one `Cache::remember()` call, immediately halves external Gemini call volume per chat message.
- **Lazy-loading the 32 eagerly-bundled Angular routes** — pure route-config change, no logic risk, directly reduces bandwidth cost served to every user on every first load.
- **Compressing/de-duplicating the >1MB static images** (one image triplicated ~4.6MB) — a few minutes of asset work, permanent bandwidth savings on every page view that touches those assets.

---

## 17. Multi-Tenancy Readiness

**CURRENT:** Riva already implements real multi-tenancy for its newer, clinic-scoped operational features — `ClinicUser` (role/status/permissions) is the tenant-membership model, `EnsureClinicContext`/`ClinicContextService` is the per-request tenant-resolution layer, and every clinic-scoped controller checked this session correctly re-validates route-bound sub-resources against the resolved tenant before acting (§4). Medical data uses a deliberately *different*, relationship-based boundary that is not "tenant" in the SaaS-billing sense at all — a patient's medical data is scoped to their specific doctor/caregiver relationships, not to a clinic, which is clinically correct (a patient's medical history doesn't belong to "a clinic," it belongs to a treatment relationship) and should **not** be collapsed into the clinic-tenant model even as this becomes a paid SaaS product.

**TARGET:** For a doctor/clinic-pays-monthly SaaS billing model specifically, the missing piece is not data isolation (already solid) but **subscription/billing-tier scoping** — there is currently no `subscription`/`plan`/`billing_status` concept anywhere on `Clinic` or `ClinicUser`. The target shape is a `clinic_subscriptions` table (clinic_id, plan, status, current_period_end, payment_gateway_reference) following the same "swappable interface" pattern already proven by `PaymentGatewayInterface`/`ManualPaymentGateway` for appointment payments — a clinic's operational features (queue, staff limits, service count limits, etc.) can then be gated by subscription status through the exact same `ClinicUser::hasPermission()`-style check point already in use, rather than inventing a new authorization layer.

**MIGRATION:** This is additive, not a rework: (1) add `clinic_subscriptions` via a new migration (no changes to existing tables required), (2) add a subscription-status check into `EnsureClinicContext` or a sibling middleware for the operational routes that should be gated by an active subscription, (3) reuse `PaymentGatewayInterface` for subscription billing exactly as it's already used for appointment payments, swapping in a real gateway (Stripe/Paymob/Fawry, as the codebase's own `PaymentGatewayInterface` docblock already anticipates) when one is actually integrated. **Do not** introduce a separate `tenant_id` column across existing tables — `clinic_id` already serves that role everywhere it's needed, and adding a redundant generic tenant column would be exactly the kind of unnecessary duplicate-data pattern flagged as a problem elsewhere in this audit (§4).

---

## 18. Scenario Cost Simulation

**Assumptions (stated explicitly, per the brief's instruction not to invent numbers silently):**

- Infrastructure priced as generic small/mid VPS + managed-database + object-storage components (e.g. Hetzner/DigitalOcean/Linode-class pricing), **not** AWS-specific — consistent with the brief's "don't assume AWS" instruction. Actual vendor choice is a separate decision; these are order-of-magnitude USD/month figures for planning, not a quote.
- **Concurrency**: 8% of "active" (logged-in-within-30-days) users concurrently online at peak — a conservative-but-reasonable assumption for a healthcare app used in short, purposeful sessions (checking a dashboard, confirming a dose, a brief chat) rather than continuous engagement.
- **Requests per active session**: ~15 API calls per session average, **assuming the P0/P1 fixes in §21 are applied** (pagination, no runaway polling). Without those fixes, multiply the request-volume column by roughly 1.5–2x due to the chat 5s-poll and dashboard 60s-poll patterns found in §6, scaled by how many sessions have those screens open.
- **Storage per patient**: ~150KB of structured DB data (profile, relationships, appointment/medication history) accumulated over a year of use, **plus** an average 3MB of files (verification docs, occasional chat attachments, profile photo) — files dominate storage cost, which is exactly why §9's object-storage fix matters more than DB sizing at scale.
- **Notifications**: ~3 notification-triggering events per active patient per week (appointment/medication/report events); ~15% of those also trigger an email; WhatsApp/Twilio only for emergency alerts and explicit reminders, estimated at ~0.5 per active patient per month.
- **DB capacity**: sized for the *indexed* query patterns this report recommends (§4/§21) — the unindexed-today numbers would require meaningfully larger DB tiers at Scenario 3+, which is itself an argument for doing the index fixes before scaling, not after.
- Pharmacy counts are included per the brief's scenario definitions but contribute comparatively little load in this simulation, since (§11) no pharmacy feature exists yet to generate traffic — their infrastructure cost here is presented as directory/reference data only.

| | **Scenario 1**<br>10 doctors, 100 pts/doc, 100 pharm. | **Scenario 2**<br>50 doctors, 500 pts/doc, 500 pharm. | **Scenario 3**<br>100 doctors, 1,000 pts/doc, 1,000 pharm. | **Scenario 4**<br>500 doctors, 2,000 pts/doc, 2,500 pharm. | **Scenario 5**<br>1,000 doctors, 5,000 pts/doc, 5,000 pharm. |
|---|---|---|---|---|---|
| Registered patients | 1,000 | 25,000 | 100,000 | 1,000,000 | 5,000,000 |
| Active users (30d, ~40% of registered + staff/doctors) | ~450 | ~10,200 | ~40,200 | ~400,700 | ~2,001,300 |
| Concurrent peak (8% of active) | ~36 | ~815 | ~3,215 | ~32,055 | ~160,105 |
| Requests/sec (peak, 15 req/session, ~3 min avg session) | ~3 | ~68 | ~268 | ~2,671 | ~13,342 |
| DB writes/sec (peak, ~20% of requests) | <1 | ~14 | ~54 | ~534 | ~2,668 |
| DB storage (structured data, ~150KB/patient + platform tables) | ~1 GB | ~5 GB | ~18 GB | ~155 GB | ~760 GB |
| File storage (object storage, ~3MB/patient avg) | ~3 GB | ~75 GB | ~300 GB | ~3 TB | ~15 TB |
| Bandwidth/month (API + files, rough) | ~20 GB | ~450 GB | ~1.8 TB | ~18 TB | ~90 TB |
| Emails/month (~15% of ~3/wk notif events) | ~290 | ~6,600 | ~26,000 | ~260,000 | ~1,300,000 |
| WhatsApp/month (~0.5/active patient) | ~200 | ~4,600 | ~18,100 | ~180,300 | ~900,600 |
| Queue jobs/month (notifications + emails + reminders combined) | ~2,000 | ~45,000 | ~180,000 | ~1,800,000 | ~9,000,000 |
| App servers needed (2 vCPU/4GB class instances) | 1 | 1–2 | 2–3 | 6–8 | 25–30 |
| DB tier | 1 vCPU/2GB managed | 2 vCPU/4GB managed | 4 vCPU/8GB managed | 8 vCPU/32GB + 1 read replica | 16+ vCPU/64GB+ + 2 read replicas |
| Redis | not required yet (or smallest tier for cache+queue) | smallest managed tier | small-medium managed tier | medium managed tier, HA | medium-large, HA cluster |
| Queue workers | 1 (shared w/ app) | 1 dedicated | 2 dedicated | 4–6 dedicated | 15–20 dedicated |
| Object storage | ~$1–2/mo | ~$5–10/mo | ~$15–25/mo | ~$150–250/mo | ~$700–1,200/mo |
| Backups (DB snapshots + object-storage versioning) | included/minimal | ~$5–10/mo | ~$20–40/mo | ~$150–300/mo | ~$700–1,500/mo |
| **Estimated infra cost/month** | **~$30–60** | **~$180–350** | **~$600–1,100** | **~$4,500–7,500** | **~$20,000–32,000** |

*(All figures are order-of-magnitude planning estimates built from the stated assumptions, not vendor quotes. The DB-storage/query-load estimates assume the §4/§21 index fixes are applied — without them, Scenario 4–5's DB tier sizing should be treated as understated.)*

---

## 19. Cost Per Doctor

Using the infrastructure-only estimates above (excludes salaries/marketing/support/taxes — a genuinely separate line, per the brief's own instruction):

| Scenario | Doctors | Infra cost/mo | **Cost per doctor/mo** |
|---|---|---|---|
| 1 | 10 | ~$45 | **~$4.50** |
| 2 | 50 | ~$265 | **~$5.30** |
| 3 | 100 | ~$850 | **~$8.50** |
| 4 | 500 | ~$6,000 | **~$12.00** |
| 5 | 1,000 | ~$26,000 | **~$26.00** |

Cost-per-doctor **rises** with scale in this model rather than falling, because per-doctor patient volume also rises across scenarios (100→5,000 patients/doctor) — this simulation is not a pure "economies of scale" curve, it's modeling both more doctors *and* each doctor carrying a much larger, more active panel simultaneously, per the brief's own scenario definitions.

Comparing to a monthly subscription price (infra cost only, gross margin before salaries/marketing/support):

| Subscription price (EGP/mo) | Scenario 1 margin | Scenario 3 margin | Scenario 5 margin |
|---|---|---|---|
| 500 EGP (~$10) | ~55% | ~15% | negative (~-160%) |
| 750 EGP (~$15) | ~70% | ~43% | ~negative (~-73%) |
| 1,000 EGP (~$20) | ~78% | ~57% | ~negative (~-30%) |
| 1,500 EGP (~$30) | ~85% | ~72% | ~13% |
| 2,000 EGP (~$40) | ~89% | ~79% | ~35% |

*(EGP→USD conversion used here: ~50 EGP/USD, a planning approximation only — confirm the actual rate at pricing time.)*

**Read plainly:** at low-to-mid scale (Scenarios 1–3), even the lowest proposed subscription tier (500 EGP) carries healthy infrastructure-only gross margin. At Scenario 5's assumed usage intensity (5,000 patients/doctor is a very large panel), infrastructure cost alone approaches or exceeds the two lowest price points — this is the clearest, most concrete argument in this whole audit for why the §21 fixes (indexes, caching, object storage, queueing) matter financially, not just technically: **every fix in this report directly widens this margin at Scenario 4–5 scale**, and several of them (indexing, caching, rate-limiting the AI endpoint) cost nothing to deploy.

---

## 20. Recommended Target Architecture

Consistent with the brief's explicit instruction — **modular monolith, small initial infrastructure, scale only when load requires it, no Kubernetes, no microservices, no AWS-lock-in assumption**:

- **Keep the Laravel monolith.** Nothing found in this audit argues for splitting it — the existing modular structure (controllers/services cleanly separated by domain, `PaymentGatewayInterface`-style swappable abstractions already in place) is exactly what a "modular monolith" should look like, and rewriting it into services would trade a handful of index/config fixes for a much larger, riskier project with no corresponding benefit at any of the five target scenarios.
- **One app-server tier**, scaling horizontally (more identical instances behind a load balancer) rather than vertically, once local-disk file storage and file-based cache/session are removed as blockers (§9) — this is the one architectural precondition that must be fixed before horizontal scaling is even possible, regardless of load.
- **Managed MySQL** with a read replica introduced only at Scenario 4+ (per §18's table) — not from day one.
- **Redis** introduced for cache + queue together (a single small Redis instance serves both roles at low-to-mid scale) once `CACHE_STORE`/`QUEUE_CONNECTION` are moved off `file`/`sync` — this is a configuration change plus one small managed service, not a re-architecture.
- **Object storage** (S3-compatible; a non-AWS-locked provider like Cloudflare R2 or DigitalOcean Spaces is a reasonable, low-cost starting choice) for all file uploads, fronted by the existing signed-URL access-control model — no change to the security model, only to where bytes live.
- **A supervised queue worker process** (1 process is enough through Scenario 2–3; scale worker count, not architecture, from there).
- Continue avoiding microservices, Kubernetes, and multi-region deployment until a specific, measured bottleneck actually requires it — none of the five target scenarios in this audit's own simulation require them.

---

## 21. Migration Roadmap

Sequenced by dependency and effort-to-impact ratio, not strictly by P0/P1/P2/P3 severity alone (see §22 for the full prioritized list with per-item detail):

1. **Config-only fixes (near-zero engineering risk, do first):** enable API-wide rate limiting; switch `QUEUE_CONNECTION` to `database`; switch `CACHE_STORE` to `database` or `redis`; switch `LOG_STACK` to `daily`; confirm/fix `APP_DEBUG`/`LOG_LEVEL`/`APP_ENV` for whatever environment is actually production.
2. **Index migrations (additive, safe, no data risk):** the composite indexes identified in §4 — highest single-change impact on real query cost at scale.
3. **Queue conversion (mechanical, well-scoped):** `->send()` → `->queue()` on the notification-email path; move the three synchronous-external-call controllers (§7) behind `dispatch()`; add `->withoutOverlapping()` + chunking to the medication-missed sweep; wrap the emergency-alert job's `dispatch()` in a try/catch.
4. **Pagination pass:** the 5 controllers identified in §5 (`AdminController`'s 8 methods, `AppointmentController::index()` admin branch, `ReportController::index()` fallthrough, `DoctorController::index()`) — either retire `AdminController` in favor of the already-correct `AdminAreaController`, or paginate it directly.
5. **Caching pass:** the 3 candidates in §8 — smallest-effort, immediately measurable win.
6. **Object storage migration (the largest single piece of work in this roadmap):** install an S3-compatible Flysystem driver, provision a bucket, switch the three upload paths, backfill/migrate any existing local files, remove the `public/storage` symlink bypass once done.
7. **Frontend route-lazy-loading pass:** mechanical `component:` → `loadComponent:` conversion for the 32 eagerly-bundled routes; replace chat's 5s poll with the already-installed Pusher/Echo channel; de-duplicate the sidebar/`myContext()` call; drop the two apparently-unused dependencies; compress/de-duplicate the oversized static images.
8. **Deployment tooling:** add a Dockerfile (with OPcache enabled) and a CI workflow running the existing PHPUnit suite — not urgent for correctness, but a real gap before treating this as production-ready per the brief's own definition.
9. **Multi-tenancy billing layer (§17):** `clinic_subscriptions` table + gating middleware — sequence this whenever the actual SaaS billing feature is prioritized, not before; it's additive and doesn't block any of the above.
10. **Pharmacy foundation (§11):** sequence last, as scoped in §11, once the above cost/scale fixes are in place — building it on top of an unindexed, unpaginated, uncached API layer would just add a fourth domain inheriting all the same problems.

---

## 22. P0 / P1 / P2 / P3 Priorities

**P0 — Critical (fix before any real production load):**

| # | File / Location | Current Behavior | Cost Impact | Scalability Impact | Security Impact | Fix | Difficulty | Expected Savings |
|---|---|---|---|---|---|---|---|---|
| P0-1 | `bootstrap/app.php` (no `throttleApi()`), `routes/api.php` (no `throttle:` on `/ai-assistant/message`) | Zero API-wide rate limiting; AI endpoint unthrottled | Uncapped paid Gemini spend per compromised/abusive account | N/A directly, but protects DB/CPU from abuse too | High — brute-force/enumeration/abuse across the whole API | Enable `throttleApi()` + per-route `throttle:` on register, contact-message, ai-assistant | Low (config + annotations) | Bounds worst-case external-API bill; closes brute-force surface |
| P0-2 | `.env` `QUEUE_CONNECTION=sync` + `EmergencyAlertController::store():78` (uncaught `dispatch()`) | Emergency job retries are a no-op; a failed attempt can 500 to the patient | Low direct $ cost, high reliability cost | High — this is the emergency-alert path | Medium — a failed 201-promised response degrading to 500 mid-emergency is a real UX/trust failure for a healthcare app | Set `QUEUE_CONNECTION=database` + supervised worker; wrap `dispatch()` in try/catch | Low-medium | Restores documented retry/backoff behavior; removes a live correctness bug |
| P0-3 | `app/Console/Kernel`/`routes/console.php` `medications:mark-missed` (`->everyMinute()`, no chunking/lock) | Unchunked full-patient scan + 7-day schedule rebuild, every 60s, forever | Continuous, ever-growing DB compute cost | Highest-growth-rate risk in the whole audit | None | Chunk the query, drop redundant weekly rebuild, add `withoutOverlapping()` | Medium | Largest single reduction in steady-state DB load |
| P0-4 | `config/filesystems.php` (`s3` disk unwired), `ChatController`/`ProfileController`/`DoctorVerificationController` upload paths | All files on local app-server disk | Growing, unbounded storage on the smallest/most expensive-to-scale tier | Blocks horizontal app-server scaling outright | Medium — no backup strategy for medical documents today | Install S3-compatible driver, provision object storage, switch upload paths | Medium-high | Unblocks horizontal scaling; enables real backup/retention policy |
| P0-5 | `app/Http/Controllers/MedicationReminderController.php:20-35` | Synchronous Mail+Twilio, no timeout on Twilio call, hardcoded WhatsApp recipient | Blocks a worker for the full external round-trip per call | Low volume today, but actively broken (wrong recipient) | None | Queue the sends; fix hardcoded recipient | Low | Removes a live functional bug plus the sync-call cost |

**P1 — High:**

| # | Location | Current Behavior | Fix | Difficulty | Expected Savings |
|---|---|---|---|---|---|
| P1-1 | `patient_doctor_relationships`/`patient_caregiver_relationships` (missing composite indexes) | Every medical-data auth check is a range scan, not an indexed lookup | Add `(doctor_id,status)`/`(patient_id,status)` composite indexes | Low (additive migration) | Highest-ROI index fix identified; benefits nearly every authenticated request |
| P1-2 | `AdminController.php` (8 methods) | Unbounded `::get()`, duplicate of already-paginated `AdminAreaController` | Retire or paginate | Low-medium | Removes the worst unbounded-query pattern found |
| P1-3 | `AppointmentController::index()` (admin), `ReportController::index()` (unmatched role) | Unfiltered and/or unpaginated, one is IDOR-shaped | Paginate; add explicit deny branch to `ReportController` | Low | Closes a real data-exposure gap + bounds payload size |
| P1-4 | `DoctorController::index()`/`transformDoctor()` | Per-doctor N+1 relationship query + fully eager-loaded reviews on a public endpoint | Batch-load relationship status; `withAvg`/`withCount`; paginate | Medium | Fixes the single worst N+1 in the codebase, on a public endpoint |
| P1-5 | `ContactMessageController::store`, `NotificationService::sendEmail` | Public unauthenticated sync Mail+Twilio; `->send()` not `->queue()` | Queue both | Low-medium | Removes ~20s worst-case latency on a public endpoint; unblocks async email everywhere |
| P1-6 | `Riva-master/src/app/app.routes.ts` (32 eager routes) | ~60% of routes bundled eagerly, including the heaviest screens | Convert to `loadComponent` | Low (mechanical) | Directly shrinks first-load bundle for every user |
| P1-7 | `chat.component.ts` (5s HTTP poll) | Polls over plain HTTP despite Pusher/Echo already installed | Route chat through existing real-time channel | Medium | Eliminates a continuous per-open-chat request stream |

**P2 — Medium:**

| # | Location | Current Behavior | Fix | Difficulty | Expected Savings |
|---|---|---|---|---|---|
| P2-1 | `AiAssistantController::checkGeminiKey()` | Re-validates the same static key every message | `Cache::remember()` the check | Low | Halves external Gemini calls per chat turn |
| P2-2 | `DashboardController::admin()`, `weeklyPatientActivity()` | 11 uncached queries; query-in-a-loop | Cache aggregate counts; single grouped query | Low-medium | Reduces dashboard load cost, applies existing in-house pattern |
| P2-3 | `messages` table / `StorageFileController` leading-wildcard LIKE | Full scan on every attachment view | Composite index + exact-match lookup column | Medium | Fixes the most frequently-hit LIKE query in the app |
| P2-4 | `system_activity_logs`/`login_attempts`/`appointment_booking_logs` | No index beyond PK; no retention job | Add indexes; add pruning/archival | Medium | Prevents largest-tables-by-row-count from degrading admin queries |
| P2-5 | `EmergencyAlertController::store()` logs `pain_level` | Clinical data in plaintext logs | Drop clinical fields from log context | Low | Closes a real PHI-in-logs gap |
| P2-6 | `.env` `LOG_STACK=single`, `APP_DEBUG`/`LOG_LEVEL` (if these are live prod values) | Unrotated log file; debug-verbose | Switch to `daily`, confirm prod-safe values | Low | Prevents unbounded log growth + info leakage |
| P2-7 | `dashboard-p.component.ts` 60s poll | Refetches medication status regardless of visibility | Visibility-gate or lengthen interval | Low | Removes a steady per-session request floor |

**P3 — Nice to have:**

| # | Location | Current Behavior | Fix | Difficulty | Expected Savings |
|---|---|---|---|---|---|
| P3-1 | `doctors.verification_status`/`is_verified` + `users.verification_status`/`status`/`is_active` duplication | Same concept tracked redundantly across tables/columns | Consolidate to one source of truth per concept | Medium | Reduces drift-risk and query complexity, not a hot-path cost fix |
| P3-2 | `three`/`@types/three`, `@google/generative-ai` (frontend deps) | Apparently unused | Remove if confirmed unused repo-wide | Low | Smaller install/audit surface; likely minimal shipped-bundle impact (tree-shaken already) |
| P3-3 | `public/` oversized/duplicated images | Multiple >1MB PNGs, one tripled ~4.6MB | Compress to WebP, de-duplicate | Low | Bandwidth savings on disease-detail pages |
| P3-4 | ~23 manual `Authorization: Bearer` header sites (frontend) | Redundant with the existing interceptor | Remove, rely on interceptor | Low | Maintainability only, no runtime cost impact |
| P3-5 | No Dockerfile / CI / OPcache reference anywhere | Manual, undocumented deployment | Add Dockerfile + CI running existing test suite | Medium | Not a cost fix — a production-readiness/operational-risk fix |
| P3-6 | Sidebar + `ClinicQueueComponent` duplicate `myContext()` call | Two calls to the same endpoint on one page load | Share via resolver or `shareReplay(1)` | Low | Minor request-count reduction |

---

## 23. Open / Unverified Items

Stated plainly rather than silently assumed clean, per this audit's own standard:

- **Doctor dashboard component (`dashboard/dashboard/dashboard.component.ts`)** could not be read this session — a device-bridge tooling limit (7-folder staging depth), not a code issue. Given the identical pattern found on the patient dashboard, check this file directly for the same 60s-poll pattern before treating the frontend polling findings as complete.
- **Whether the checked-in `.env` values reflect actual production configuration** (`APP_DEBUG`, `LOG_LEVEL`, etc.) — this audit read the repository's current `.env`, which may differ from what's genuinely deployed; confirm before acting on §12's production-config findings.
- **Whether Laravel Reverb (`BROADCAST_CONNECTION=reverb`) is actually driving any live feature**, versus Pusher/Echo being the only real-time transport in use — not traced end-to-end this session; worth a dedicated look before assuming either is dead weight or load-bearing.

---

*End of audit. No files were modified in the production of this report. Per the brief: **DO NOT IMPLEMENT YET** — awaiting review before any of the above fixes are applied.*
