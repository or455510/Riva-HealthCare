# RIVA_ARCHITECTURE.md

Facts here were verified directly against the repository this session
(`backend/composer.json`, `Riva-master/package.json`, `backend/.env`,
`backend/phpunit.xml`) or the prior cost/scalability audit
(`backend/docs/engineering/RIVA_COST_AND_SCALABILITY_AUDIT.md`), which itself
cites real file:line locations. Nothing here is inferred without a source.

## Two codebases, one product

- **Backend**: `backend/` — Laravel Framework **11.54.0** installed
  (`composer.json` requires `laravel/framework: ^11.31`), **PHP ^8.2**
  required. Sanctum `^4.3` (bearer-token API auth), Socialite `^5.27`
  (Google OAuth), Reverb `^1.10` (broadcasting config present; not confirmed
  as the actual real-time transport — see below). 34 Eloquent models, 30
  controllers, 34 migrations, exactly 1 queued Job class
  (`SendEmergencyAlertNotifications`).
- **Frontend**: `Riva-master/` — Angular **21.0.x**, standalone components,
  Angular CLI `^21.0.1`, TypeScript `~5.9.2`, npm `11.6.2` declared as the
  package manager. `laravel-echo` + `pusher-js` are the packages actually
  used for real-time WebRTC signaling — **Reverb's `BROADCAST_CONNECTION`
  being set does not by itself confirm Reverb is what's driving that
  feature**; this was flagged as unconfirmed by the cost audit and has not
  been traced further since.
- These are **independent build/deploy units today** — no monorepo tooling,
  no shared root `package.json`/`composer.json`, no CI wiring either. See
  `docs/RIVA_AGENT_WORKFLOW_AUDIT.md` §1/§3.

## Backend shape: modular monolith

A single Laravel app, not services. Controllers are organized by domain
(`app/Http/Controllers/`, with an `Admin/` subfolder), and cross-cutting
concerns are pulled into services under `app/Services/` (e.g.
`app/Services/Payments/PaymentService.php`,
`app/Services/Tenancy/ClinicProvisioningService.php`,
`app/Services/NotificationService.php`,
`app/Services/MedicationAdherenceService.php`). Response shape follows a
consistent envelope pattern (`{success, message, data}` on success,
`{success, message, errors}` on failure) via a base `Controller` helper.

**This is deliberately the recommended target shape, not just the current
one** — the cost audit's own recommendation (§20) is to keep this monolith
rather than introduce microservices/Kubernetes, since nothing found in that
audit argues otherwise at any of the product's planned scale targets.

## Two authorization/tenancy models (both real, both enforced, serving different data)

1. **Relationship-based** — for medical data (appointments, reports,
   medications, chat, calls, daily status). Gated by `PatientDoctorRelationship`
   / `PatientCaregiverRelationship` rows with `status IN (active, accepted)`.
   Clinic co-membership is deliberately **not** sufficient authorization for
   this category of data.
2. **Clinic-scoped** — for operational data (queue, patient
   registration/search, staff management, services/prices). Gated by
   `clinics` + `clinic_user` pivot (`ClinicUser` model: role, status, a
   `permissions` JSON array), resolved per request by the
   `EnsureClinicContext` middleware / `ClinicContextService`, which only
   honors a client-supplied clinic context if it matches the caller's own
   active membership.

## Infrastructure as actually configured today (`backend/.env`, read this session)

- `QUEUE_CONNECTION=sync` — no queue worker; the one `ShouldQueue` job runs
  inline in the HTTP request.
- `CACHE_STORE=file` — no shared/distributed cache; zero `Cache::remember()`
  calls exist in `app/` per the cost audit.
- `FILESYSTEM_DISK=public` — uploaded files live on local application-server
  disk; an `s3` disk is defined in `config/filesystems.php` but has no
  credentials and is never invoked.
- `SESSION_DRIVER=file` — not centralized; matters only if the app ever runs
  on more than one instance (Sanctum bearer-token auth makes this less
  critical than cookie-session auth would be, but is still a precondition
  for horizontal scaling).
- `DB_CONNECTION=mysql`, single host, no read replica, no pooler.
- `BROADCAST_CONNECTION=reverb` — see the caveat above.

**Read as**: a correct, coherent single-server setup appropriate for current
usage. The infrastructure gaps here are about what breaks first under load,
not about anything being broken today — see
`backend/docs/engineering/RIVA_COST_AND_SCALABILITY_AUDIT.md` for the full
scale analysis and `docs/RIVA_COST_MODEL.md` for the summary.

## Testing infrastructure

`backend/phpunit.xml` overrides the test environment to `DB_CONNECTION=sqlite`
(`:memory:`), `CACHE_STORE=array`, `MAIL_MAILER=array`, `SESSION_DRIVER=array`,
`QUEUE_CONNECTION=sync` — the test suite is self-contained and does not touch
the real MySQL database, send real mail, or send real SMS/WhatsApp. As of the
last engineering-journal entry (Slice 8), the suite reports **169 tests
passed, 500 assertions**; this was re-confirmed independently this session
via the agent control layer's `test` command (see
`RIVA_AGENT_IMPLEMENTATION_REPORT.md`).

## API documentation that already exists

`Riva-master/API_ENDPOINTS.md` (17.2 KB) is a pre-existing, hand-maintained
endpoint reference. It was not re-audited line-by-line this session — see
`docs/RIVA_API_CONTRACT.md` for how it relates to this documentation set.

## What this document does not cover

Database schema detail → `docs/RIVA_DATABASE.md`. Business/authorization
rules in plain language → `docs/RIVA_BUSINESS_RULES.md`. Cost/scale
analysis → `docs/RIVA_COST_MODEL.md` and the full audit it summarizes.
