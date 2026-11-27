# RIVA_BUSINESS_RULES.md

Rules below are grouped by how they were verified: **[verified this
session]** means directly re-checked in the repository during this task;
**[from engineering journal]** means established and tested in a prior
session per `backend/docs/engineering/IMPLEMENTATION_JOURNAL.md` and not
independently re-run this session, but treated as authoritative since it is
backed by passing tests.

## Authentication & account creation

- **Admin accounts cannot be self-registered.** `'admin'` was deliberately
  removed from the accepted values on the public registration endpoint;
  admin accounts are created only via `php artisan riva:create-admin`
  (`app/Console/Commands/CreateAdminUser.php`), which is a server-console
  operation, not reachable over the API. **[verified this session]** —
  confirmed by reading the guard comment in
  `backend/app/Http/Controllers/AuthController.php`.
- Google OAuth (Socialite) and email/password are both supported login
  paths. **[from engineering journal]**

## Medical-data access

- A patient's medical data (appointments, reports, medication, chat, daily
  status) is visible to a doctor/caregiver **only** through an explicit
  `PatientDoctorRelationship` / `PatientCaregiverRelationship` with
  `status IN (active, accepted)`. Belonging to the same clinic is
  **not**, by itself, sufficient to see this data. **[from engineering
  journal, architecture confirmed this session — see `RIVA_ARCHITECTURE.md`]**

## Clinic operations

- Clinic-scoped actions (registering a patient at the clinic, managing the
  queue, recording a payment, managing services/prices) require an active
  `ClinicUser` membership **and** the specific permission for that action:
  `patients.register`, `appointments.manage`, `queue.manage`,
  `payments.record`, `services.manage`. **[verified this session]** —
  confirmed the permission constants directly in
  `backend/app/Models/ClinicUser.php`.
- A clinic can define priced services (`ClinicService`); an appointment may
  reference one, deriving its `amount` from the service price instead of the
  doctor's flat fee — with validation that the service is active and
  actually offered by that doctor's clinic. **[from engineering journal]**

## Payments

- Payment recording is **not** patient self-service. The gateway
  (`ManualPaymentGateway`) always reports success unconditionally — it's an
  honest "staff attestation" mechanism, not a real payment processor — so
  `canRecordPayment()` restricts who may call it to admin or clinic staff
  holding `payments.record`. A patient calling the pay endpoint themselves
  is expected to receive 403. **[from engineering journal]** — this rule
  was specifically the subject of a prior-session frontend bug fix (the
  booking UI previously implied patient self-pay was possible; it was
  rewritten to reflect this backend rule honestly rather than the backend
  being loosened).

## Medication

- A dose is considered missed after a **15-minute grace period**
  (`MEDICATION_MISSED_GRACE_MINUTES=15` in `backend/.env`), evaluated by a
  recurring `medications:mark-missed` scheduled command. **[verified this
  session]** — read directly from `.env`.

## AI assistant

- The Gemini API key (`GEMINI_API_KEY`) is a backend-only secret; the AI
  assistant is called from the backend and the key is never exposed to the
  frontend. **[from engineering journal / prior cost audit API pass]**

## What is explicitly NOT a business rule (common misreadings to avoid)

- Pharmacies are a **named future user type**, not a currently-enforced
  business domain — there is no pharmacy-related authorization rule to
  document because no pharmacy feature exists yet.
- There is no confirmed subscription/billing enforcement rule (e.g. "a
  clinic with a lapsed subscription loses queue access") — no such
  mechanism was found in the backend. `UNKNOWN / REQUIRES VERIFICATION`.

## Where these rules are enforced (for an agent about to touch this code)

Business rules above are enforced in application code, not merely
documented. Before an implementation agent changes anything touching
authentication, payments, or clinic/medical authorization, treat that as a
**restricted operation** per `docs/RIVA_AGENT_WORKFLOW.md` — it requires a
human decision, not an autonomous edit.
