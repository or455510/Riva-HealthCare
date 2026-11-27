# RIVA_PRODUCT.md

## What Riva is

Riva is a smart healthcare SaaS platform connecting five kinds of user:
**doctors, patients, caregivers, pharmacies, and administrators.** It is
built and operated as a real, ongoing product — not a training exercise —
and is monetized as a SaaS where doctors/clinics pay a recurring
subscription (see `RIVA_COST_MODEL.md` for the cost side of that model;
the actual pricing/billing mechanism is `UNKNOWN / REQUIRES VERIFICATION` —
no billing/subscription table or Stripe-equivalent integration was found in
the backend during the cost audit, see `backend/docs/engineering/RIVA_COST_AND_SCALABILITY_AUDIT.md` §17).

## Core product surface (confirmed present in the codebase this session or the prior engineering journal)

- **Appointments & clinic services/prices** — patients book appointments with
  doctors; clinics can define priced services (`ClinicService`) that an
  appointment can reference instead of the doctor's flat fee.
- **Medication tracking** — scheduled doses, adherence logging, a
  "missed dose" grace period of **15 minutes**
  (`MEDICATION_MISSED_GRACE_MINUTES=15` in `backend/.env`), and a recurring
  `medications:mark-missed` scheduled command.
- **Emergency alerts** — patients can trigger an emergency alert that
  notifies their care circle (job: `SendEmergencyAlertNotifications`).
- **Chat, calls, group chats** — including WebRTC signaling for calls
  (`WebRTCSignal` event, Pusher/Echo on the frontend).
- **Reports** — clinical reports with a role-based access matrix (doctor,
  patient, caregiver visibility rules), plus report comments.
- **Notifications** — in-app, email (SMTP via Gmail per `backend/.env`),
  and WhatsApp (Twilio) channels.
- **Google OAuth** login (Socialite) alongside standard email/password.
- **AI assistant** — backed by Google Gemini, called **only from the
  backend** (`GEMINI_API_KEY` lives in `backend/.env`, never sent to the
  frontend — confirmed by the prior cost audit's API pass).
- **Clinic operations** — a clinic-scoped queue/check-in workflow, patient
  registration/search scoped to a clinic, staff membership with granular
  permissions (`ClinicUser`: `patients.register`, `appointments.manage`,
  `queue.manage`, `payments.record`, `services.manage`).
- **Admin area** — platform administration, deliberately **not**
  self-registerable (see `RIVA_SECURITY.md`).
- **Pharmacies** — named in the product's scaling targets and business
  model, but **no pharmacy feature exists in the codebase yet** (zero
  matches for "pharmac*" across `app/`, `database/`, `routes/`, `config/` as
  of the last cost audit pass). Treat pharmacy as a planned, unbuilt domain,
  not a partially-built one.

## Roles

| Role | Can do (high level) |
|---|---|
| Patient | book appointments, track medication, message their doctor/caregiver, trigger emergency alerts, view their own reports |
| Doctor | manage their appointments/patients, write reports, prescribe/track medication, participate in a clinic's queue and services |
| Caregiver | linked to a patient via an accepted relationship; view/assist within the scope that relationship grants |
| Clinic staff (non-doctor) | operates within a clinic via `ClinicUser` membership + explicit permission grants — registering patients, managing the queue, recording payments, managing services, per the granted permission set |
| Administrator | platform-level administration; account creation is deliberately restricted (console command only, not public registration) |
| Pharmacy | named in the target user base; **not yet implemented** |

## Scale targets (business intent, not current reality)

The product's own cost/scalability planning (see
`backend/docs/engineering/RIVA_COST_AND_SCALABILITY_AUDIT.md` §18) explicitly
targets a growth path from roughly 10 doctors up to 1,000 doctors with
5,000 patients each — these are **targets used for capacity planning**, not
a description of the platform's current user base, which was not measured
this session.

## Non-goals for this document

This file describes what Riva *is*, not how it's built (`RIVA_ARCHITECTURE.md`)
or what it costs to run (`RIVA_COST_MODEL.md`). Anything not directly
observed in the codebase or the existing engineering journal is marked
`UNKNOWN / REQUIRES VERIFICATION` rather than assumed.
