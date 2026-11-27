# Riva Doctor ERP Discovery

**Status: READ-ONLY DISCOVERY. No backend, frontend, database, or route code was modified to produce this document.**

Scope: full backend (`backend/`, Laravel 11) and full Angular 21 frontend (`Riva-master/`), read directly from the real repository via its local mirror, plus real external research on comparable clinic-management/EMR products. Every finding below is cited to an actual file (and line, where practical) or an actual fetched source URL. Where something could not be verified from the source, it is marked **UNKNOWN** rather than assumed. Numbers superseded by a fresh run in this session (test count, route count) are called out explicitly against any older written-down figure.

Prepared 2026-08-25, in response to the explicit product-owner directive: *"do a real internet search on real EMR systems like ours, determine what's missing/matching/required, and do the backend and frontend work and connect them — but first, understand the actual existing application before designing or coding anything."* This document is that understanding step. Nothing has been designed or built yet.

---

## 1. Current Riva Architecture

Riva is a single Angular 21 SPA backed by a single Laravel 11 monolith, using role- and relationship-driven UI rather than separate apps per role (per the product directive: one app, not a Doctor App / Patient App / Secretary App split).

**Backend**: Laravel 11.54 (`^11.31` required), PHP `^8.2`, Sanctum bearer-token auth, Socialite (Google OAuth), a hand-rolled `{success, message, data|errors}` JSON envelope via a base `Controller` helper. 40 Eloquent models, 34 controllers, 41 migrations, 55 Feature test files. **No `app/Http/Requests` directory and no `app/Http/Resources` directory exist anywhere in the codebase** — all validation is inline `$request->validate([...])`, all serialization is hand-built arrays. **Exactly one Policy class exists** (`app/Policies/ClinicPolicy.php`); every other authorization check is done ad hoc inside controller methods (`abort_unless`, direct relationship-table queries).

**Frontend**: Angular 21, standalone components (no NgModules except a routing shim), fetch-based `HttpClient`, one functional interceptor (`authInterceptor`), three route guards (`AuthGuard`, `GuestGuard`, `AdminGuard`), Tailwind v4 (CSS-first, no `tailwind.config.js`), Font Awesome icons, `apexcharts`/`ng-apexcharts` for charts (used only in the caregiver dashboard today), `laravel-echo`/`pusher-js` for WebRTC call signaling (hardcoded to `127.0.0.1:6001`, would not work against a non-local deployment without a code change).

**Two deliberate, separately-enforced authorization boundaries** — this is a genuine architectural strength found by this discovery, not incidental:
1. **Relationship-based** (medical data — appointments, reports, medications, daily statuses, chat, prescriptions): gated by `PatientDoctorRelationship` / `PatientCaregiverRelationship` rows with `status IN (active, accepted)`. Clinic co-membership is deliberately **not** sufficient authorization for this data (proven by `CrossClinicResourceIsolationTest` — a same-clinic staff-doctor with no direct relationship to a patient is still denied).
2. **Clinic-scoped** (operational data — queue, patient front-desk registration/search, staff management, services/prices): gated by `clinics` + `clinic_user` pivot (`ClinicUser`: role + status + `permissions` JSON array), resolved per-request by `EnsureClinicContext` middleware / `ClinicContextService`.

**Multi-tenancy model**: every doctor gets an auto-provisioned "clinic-of-one" the moment their profile is completed (`DoctorClinicProvisioningTest`), so the same `ClinicUser`/`ClinicService`/`QueueController` machinery serves both a solo doctor and a doctor who later invites real staff — there is no separate code path for "solo doctor" vs "clinic with staff."

---

## 2. Current Angular Structure

Full route table, guards, interceptor, services, models, navigation, and per-component real/mock classification are documented in full in **§16** and **§17** below (pulled from a complete, file-by-file frontend read). Key structural facts up front:

- `src/app/models/` contains exactly **one** file (`chat.model.ts`) — there is no shared domain-model layer; every other interface (`User`, `ClinicMember`, `QueueEntry`, `ClinicPricedService`, `ClinicPatient`, etc.) is declared ad hoc at the top of whichever service/component uses it.
- `src/app/components/sidebar.ts` is the single role-based navigation surface (rendered per-page, not in a shared app shell); there is no separate topbar component anywhere in the codebase.
- No shared UI component library (`shared/` or `ui/` folder) exists — every screen re-implements cards/buttons/badges inline with Tailwind utility classes.
- The configured `ng test` target runs **only one spec file** (`src/app/riva-final-qa.spec.ts`) via Karma, even though 46 `*.spec.ts` files exist on disk — the other 45 are not part of any CI-visible test run (`angular.json`'s `test` architect target, confirmed by direct read).
- Dead/unused code found during this pass: `core/services/flowbite/flowbite.service.ts` (a duplicate, unused `ProfileService` despite its misleading name/path), `CallComponent` (superseded by `VideoCallComponent`, not referenced in routes), `Welcome2Component` (superseded by `Welcome1Component`). None of these are wired into anything a Doctor ERP would touch, but they should not be mistaken for real functionality during the buildout.

## 3. Current Backend Structure

Complete data-layer inventory (models/migrations/policies) is in **§7–§14**; complete route/controller/business-rule inventory is in **§17**. Structural facts up front:

- 215 distinct `Route::` registrations counted directly in `backend/routes/api.php` (98 GET / 81 POST / 12 PUT / 11 PATCH / 13 DELETE). **This supersedes a previously-cited figure of "164 routes"** — that number does not match a direct count of the route file in this checkout; 215 is the real, current count.
- **313 tests passing, 983 assertions** — fresh `php artisan test` run this session. **This supersedes both a previously-cited "169 passed, 500 assertions" (dated 2026-08-24, `docs/RIVA_CURRENT_STATE.md`) and a "131 passing" figure mentioned in a handoff** — neither matches the current checkout; 313/983 is today's real number, and it includes the 4 new tests added this session for the dashboard clinic-analytics fields (`DoctorDashboardClinicAnalyticsTest`).
- No API rate limiting outside hand-rolled login/forgot-password limiters; no caching layer in active use anywhere (`Cache::`/`remember()` has zero matches in `app/` outside the one appointment-double-booking lock); `QUEUE_CONNECTION` and file-storage posture are documented in the existing `backend/docs/engineering/RIVA_COST_AND_SCALABILITY_AUDIT.md` and were not re-verified line-by-line in this pass (out of scope for a Doctor-ERP-specific discovery, but relevant context for anyone scheduling the build).

---

## 4. Existing Doctor Capabilities

Reconstructed directly from `Doctor` model/migrations and every controller that touches a doctor's own data (full citations in §7 and §17):

- **Identity/profile**: `specialty, years_of_experience, fee, bio, about, license_number, contact_info, available_days` (`Doctor` model, `app/Models/Doctor.php:16-30`). `salary` and `is_active` are real DB columns but are **not** in `$fillable`/`$casts` — a real gap if either is ever meant to be doctor-editable.
- **Verification**: a document-submission workflow (`DoctorVerification` model — national ID, medical license, syndicate card, optional certificate, all served as viewer-scoped signed URLs, never raw public paths) feeding an admin approve/reject flow. **Three separate status-bearing columns exist for "is this doctor verified"** (`doctors.verification_status`, `users.verification_status`, `doctor_verifications.status`), with inconsistent literal values across them (`verified` vs `approved` for the same real-world state, confirmed in `Admin\AdminAreaController::approveDoctorVerification()`). No single unambiguous source of truth was found.
- **Clinic ownership**: every doctor auto-owns a "clinic-of-one" the moment their profile completes (`DoctorClinicProvisioningTest`), giving them `ClinicUser::ROLE_OWNER` and every clinic-scoped capability below without any extra setup step.
- **Patients**: view own assigned patients (`PatientDoctorRelationship`-scoped), view/write patient reports (own-authored only, per the RIVA-130/142 isolation fix — see §21), view/write diagnoses, view daily-status/medication data for patients with an active/accepted relationship, prescribe via prescription-scan-and-register (real OCR via Gemini, writes to `Medication`).
- **Clinic operations** (via clinic-of-one or a real multi-staff clinic): manage staff (invite/role/status/permissions), run the appointment queue (check-in → call → start → complete/no-show/skip), register/search front-desk walk-in patients (exact-match only, no free-text browse), manage services & prices, configure which payment rails they accept (`DoctorPaymentMethod` against a fixed real-world catalog — Vodafone Cash, Orange Cash, WE Pay, InstaPay, card, cash).
- **Financial visibility**: as of this session's backend work (see §21), `GET /api/dashboard/doctor` now returns real revenue/consultation/checkup figures computed from the `payments` table — this did not exist before this session.
- **Dashboard**: **does not exist** as a frontend page — see §15. This is the single largest gap in "what a doctor can currently do."
- **Chat/notifications/profile**: full parity with other roles (1:1 chat with file upload, AI assistant proxy, notifications list, profile edit/password/avatar).

## 5. Existing Patient Capabilities

- Profile: `gender, age, blood_type, emergency_contact, chronic_conditions, medical_history, about` (`Patient` model). **`date_of_birth` is a real column but is absent from `$fillable`** — cannot be mass-assigned without `forceFill()`.
- Book appointments with a chosen doctor (price always server-derived — never trusts a client-supplied amount), pay via the current manual/admin-attested flow (no real payment gateway — see §12), cancel their own appointment.
- Daily self-monitoring (`DailyStatus` — mood, pain, sleep, vitals, symptoms, server-computed `risk_level`), medication tracking (mark taken/snooze/missed/need-help), one-time-per-day submission enforced by a unique `(patient_id, date)` constraint.
- View own reports, explicitly share a specific report with a specific other doctor (and revoke), receive emergency-alert delivery to caregivers/doctors when relevant.
- Chat with any doctor/caregiver they have an active/accepted relationship with; AI assistant; notifications.
- **Not implemented / mocked**: `AddNewMedicationComponent.addMedicalRecord()` only pushes into a local in-memory array with a comment translating to "simulated addition — should really be wired to a file-upload API" — patient-side medical-record file upload is not connected to any backend endpoint (frontend agent finding, §17 component table).

## 6. Existing Appointment Capabilities

Full controller-level detail is in §17 (`AppointmentController`, `QueueController`). Summary:

- **Status machine**: `pending_payment → pending → confirmed → cancelled | completed` (exact enum, `appointments` table). **Queue status** is a separate, parallel machine: `null → waiting → called → in_service → completed`, with `no_show`/`skipped` reachable from `waiting`/`called`, and `remove()` resetting to `null` from any state (`QueueController.php:28-32` docblock, `requireQueueStatus()` enforces valid transitions).
- **Price is always server-authoritative**: derived from `ClinicService::price` if a service was selected (after verifying it's active and belongs to a clinic the doctor actively belongs to) or falling back to `Doctor::fee`; client-supplied `amount` is silently ignored on both create and update, even after payment is recorded (`AppointmentAmountIntegrityTest`, 4/4 tests confirm this end-to-end).
- **Double-booking prevention**: a `Cache::lock` around the check-then-insert for the same doctor/date/time (`AppointmentController.php:134`), proven race-safe by `AppointmentDoubleBookingTest`.
- **Who can do what** (from actual code, not assumption): patient books for themselves only; doctor/patient/admin can view+update their own; only admin or a clinic member with `PERMISSION_RECORD_PAYMENTS` can record/fail a payment (not the patient, not an unaffiliated doctor); `confirm()`/`complete()` both hard-gate on `payment_status==='paid'` first.
- **Check-in/queue is clinic-scoped, not relationship-scoped**: viewing the queue requires active clinic membership; *advancing* it requires `PERMISSION_MANAGE_QUEUE` (owner/admin always; plain `doctor`-role clinic membership **never** passes this check on its own — confirmed by `QueueManagementTest::test_plain_doctor_member_can_view_but_not_manage_the_queue`). Check-in is also date-gated (must be today) and idempotent (can't check in twice).
- **Consultation**: there is no dedicated "consultation" or "encounter" concept/model — `QueueController::complete()` is the closest equivalent, and it just flips `queue_status` and the appointment's own `status` to `completed`. No SOAP-note-equivalent, no structured visit-summary capture beyond the existing `Report`/`Diagnosis` models (which a doctor writes manually, not tied 1:1 to a specific appointment/encounter — **UNKNOWN whether any FK links a `Report` to the `Appointment` it resulted from**; `Report`'s `$fillable` is `patient_id, doctor_id, title, summary, final_report` with no `appointment_id`).

## 7. Existing Medical Data Capabilities

- **Report / ReportComment / ReportShare**: a doctor writes a `Report` (title/summary/final_report) for a patient they have an active/accepted relationship with; caregivers/doctors can comment; the owning patient alone can share a report with another specific doctor or revoke that share. Two isolation fixes exist in this exact area from prior work this session: `patientReports()` (reached from a patient's own profile) is scoped to the calling doctor's own-authored reports only, and a separately-shared report never leaks into that view — it lives only in `GET /reports/shared-with-me` (`PatientReportsIsolationTest`, `ReportSharingTest`).
- **Diagnosis**: exists as a model/endpoint (`ReportController::storeDiagnosis`) — **UNKNOWN** beyond its existence; not deep-dived by either backend agent pass (out of the requested domain list, flagged here for completeness rather than silently omitted).
- **No dedicated "medical record" / "chart" / "patient timeline" model exists.** A patient's medical picture is assembled from several separate tables (Report, DailyStatus, Medication/MedicationLog, Appointment history) rather than one unified encounter-linked record — this matches a real gap noted independently by an existing task file, `tasks/inbox/RIVA-129-no-patient-case-encounter-timeline-diseasecontroller-is-a-st...md` (title alone read; not opened in this pass, flagged as directly relevant prior art the CTO/Architect role already identified).

## 8. Existing Medication Capabilities

- `Medication` (patient's personal prescription record) + `MedicationLog` (dose-tracking, statuses `pending/taken/missed/snoozed/skipped/need_help`) + `Medicine` (a catalog, best-effort name-matched, not authoritative). **No `Prescription`/`PrescriptionScan` model or table exists** — `PrescriptionScanController::scan()` calls Gemini OCR and returns extracted data for doctor review but **persists nothing**; only `register()` writes, and it writes directly into `Medication` rows, with no scan-history/audit table. This is confirmed, not inferred (`PrescriptionScanTest::assertDatabaseCount('medications', 0)` after `scan()` in every scan test).
- Adherence: `MedicationAdherenceService` computes today's schedule and adherence percentage per patient; a scheduled command (`medications:mark-missed`) auto-marks overdue doses; reminders dispatch async jobs.
- A unique `(patient_id, medication_id, scheduled_for_date, scheduled_time)` constraint prevents duplicate dose-log rows.

## 9. Existing Daily Monitoring Capabilities

- `DailyStatus`: one row per `(patient_id, date)` (enforced unique constraint + `updateOrCreate`), capturing mood/pain/sleep/vitals/symptoms, with `risk_level` computed server-side by `RiskAssessmentService::classify()` — never client-supplied.
- **A real, confirmed data-modeling defect found in this pass**: `DailyStatus::rules()` and the model's own helper methods (`isHighRisk()`, `calculateRiskLevel()`) validate/compare against `'stable'/'moderate'/'high'`, while the actual DB column enum is `stable, attention_needed, moderate_risk, high_risk` — two different vocabularies coexist in the same model file. Which one callers actually rely on was not traced further in this pass (flagged, not fixed — this is a discovery document).
- Notifications fan out to caregivers for any non-stable status, and additionally to doctors specifically for `moderate_risk`/`high_risk`.

## 10. Existing Emergency Capabilities

- `EmergencyAlert`: patient-triggered only (doctor gets 403 attempting to trigger one — `EmergencyAlertClinicIsolationTest`). `severity`/`status` are **plain string columns, not DB enums** — no schema-level constraint on valid values. Delivery tracking exists (`admin_email_sent_at`, `whatsapp_sent_at`, `delivery_attempts`, `delivery_failed_at`) suggesting a real multi-channel delivery attempt with retry bookkeeping.
- Isolation confirmed: an unrelated doctor/caregiver (different clinic or no relationship) never gets notified of another patient's emergency alert.
- No separate generic "Alert" model exists — `EmergencyAlert` is the only alert concept in the system.

## 11. Existing Notification Capabilities

Two parallel systems coexist with no code-level indication of which is authoritative for which caller:
1. `Notification` — polymorphic (`morphTo notifiable`), category-tagged, tracks `email_status`/`whatsapp_status` alongside `read_at`.
2. `UserNotification` — older, direct `user_id` FK, `severity` + `is_read` boolean, `related_type`/`related_id` for polymorphic-style linking without an actual morph column pair.

`NotificationController`'s ownership check (`notifiable_id !== user()->id`) does **not** additionally verify `notifiable_type` — a real, if currently probably-theoretical (no second `notifiable_type` value was found in this pass), authorization gap worth a dedicated look before this becomes a security assumption an ERP effort leans on.

Frontend has a **third**, purely client-side notification concept: `NotificationService` (`src/app/service/notification.service.ts`) is an in-memory `Subject`/array toast bus with **no HTTP calls at all** — distinct from the real, backend-driven `NotificationsComponent`. Do not confuse the two when wiring ERP alerts.

## 12. Existing Payment Capabilities

- `Payment`: one row **per charge attempt** (not per appointment) — `status ∈ pending, succeeded, failed, refunded`, `refunded_amount` (defaults to 0, never null). This is the only correct source for real revenue math; `appointments.payment_status` is a legacy single-column status that does not distinguish a since-refunded charge.
- `PaymentGatewayInterface` exists as a clean abstraction boundary, but **the only binding is `ManualPaymentGateway`** — there is no real payment gateway (Stripe/Paymob/Fawry) wired in anywhere in this codebase, confirmed by direct search. All "payment" today is an admin/authorized-staff-member manual attestation that money changed hands outside the app (cash, wallet transfer confirmed by phone/receipt, etc.).
- `DoctorPaymentMethod` against a fixed real-world catalog (`vodafone_cash, orange_cash, we_pay, instapay, card, cash`) is "which rails does this doctor accept," purely informational for the patient at booking time — not a payment-processing integration.
- Authorization for *recording* a payment is clinic-permission-gated (`PERMISSION_RECORD_PAYMENTS`) or admin — never the patient, never an unaffiliated doctor (`AppointmentPaymentFlowTest`, 11 tests).
- **New this session** (already implemented, tested, and shipped to the real device — see §21): `DashboardController::doctor()` now returns `today_appointments_count, today_completed_checkups, today_pending_appointments, total_consultations, monthly_consultations, cancelled_this_month, revenue_total, revenue_this_month, average_revenue_per_visit, pending_payments_count, currency, weekly_revenue` — all computed from real `Appointment`/`Payment` rows, correctly doctor-scoped, covered by 4 new passing tests.

## 13. Existing Staff/RBAC Capabilities

- `ClinicUser` (table `clinic_user`) is the whole model: `role ∈ owner, admin, doctor, staff`; `status ∈ active, invited, suspended`; a JSON `permissions` array for the `staff` role only (`patients.register, appointments.manage, queue.manage, payments.record, services.manage`). Owner/admin always pass every permission check regardless of the stored list; a plain `doctor`-role membership **never** gets staff-style permissions even if force-set.
- Invite flow: single-use, 48-char token, 7-day expiry, `hidden` on the model (never leaks via any GET response — proven by test), public accept-by-token endpoint that sets a real password and signs the invitee in immediately.
- Last-active-owner protection is real and tested: the sole active owner of a clinic cannot be removed, demoted, or suspended by anyone, closing off an accidental-lockout class of bug.
- Cross-clinic tampering is blocked defense-in-depth: every mutating clinic endpoint re-verifies the target resource belongs to the *caller's own* active clinic membership (404, not 403, for a cross-clinic id — deliberately not leaking existence).
- This is genuinely the most mature, most fully tested subsystem in the backend for this domain (13+ dedicated Feature test files touch it).

## 14. Existing File Capabilities

- Chat attachments, doctor-verification documents, and profile images are all served through `StorageFileController`, never as raw public URLs — every serving path is either a short-lived HMAC-signed URL (`GET /files/signed/{path}`) or an authenticated, ownership-checked stream (`GET /files/{path}` / `/storage/{path}`, `auth:sanctum`).
- `Message.file_url` and `DoctorVerification`'s four file accessors both mint a **viewer-scoped** signed URL on read (recomputed per request, not stored) — confirmed by `SignedFileUrlTest` (tampered/expired signatures rejected) and `StorageFileAccessTest` (path-traversal and cross-user access rejected).
- All uploads live on local application-server disk today (documented pre-existing gap in `RIVA_COST_AND_SCALABILITY_AUDIT.md`, not re-verified in this pass — the `s3` disk is configured but unwired).

## 15. Existing Doctor Dashboard

**NOT IMPLEMENTED — and this is a stronger statement than "placeholder."**

`app.routes.ts` registers the doctor's `/dashboard` route (the doctor's entire post-login landing page, gated `roles:['doctor']`) as importing `DashboardComponent` from `./core/components/auth/dashboard/dashboard/dashboard.component`. **That file does not exist.** The directory `src/app/core/components/auth/dashboard/dashboard/` is genuinely empty — confirmed by directory listing, full-repo grep for `class DashboardComponent`, and a check for any Angular build-cache trace. This is not mocked data behind a real UI; it is a missing source file that would currently fail the Angular build/compile if the app were built fresh in this exact state.

*(Session context: this exact file is also the one this session's device-bridge tooling could not reach for several turns due to a folder-depth limit — the user has since staged its real content directly into a different working session. That session's current content, and this discovery's characterization of the file as "missing" as of the last full-repo mirror check, may now be one step ahead of each other; treat "does not exist" as the state confirmed by this repo mirror, and reconcile against whatever the user's live session has staged before writing new code.)*

The closest existing template for what it should become is `DashboardCaregiverComponent` — a real, backend-wired dashboard (donut + area chart via `ng-apexcharts`, bound to actual API-derived series, no charting exists anywhere else in the app).

## 16. Existing Angular Role Navigation

`src/app/components/sidebar.ts` — the one role-based nav, hardcoded as `MENU: Record<Role, SidebarItem[]>`:

| Role | Nav items (route) |
|---|---|
| patient | Dashboard (`/dashboard-p`) · Medications (`/add-new-medication`) · Doctors (`/doctor-cards`) · Caregivers (`/caregiver-cards`) · Reports (`/my-reports`) · Chat (`/chat`) · AI Assistant (`/ai-chat`) · Notifications (`/notifications`) · Profile (`/myprofile`) |
| doctor | Dashboard (`/dashboard`) · Patients (`/patient-cards`) · Clinic Team (`/clinic-staff`) · Queue (`/clinic-queue`) · Patients Front Desk (`/clinic-patients`) · Services & Prices (`/clinic-services`) · Shared With Me (`/shared-reports`) · Chat · AI Assistant · Notifications · Profile · **Settings** (`/doctor-settings`) |
| caregiver | Dashboard (`/dashboard-caregiver`) · Patients (`/patient-cards`) · Chat · AI Assistant · Notifications · Profile |
| admin | Dashboard, Users, Doctors, Caregivers, Patients (all four literally point at the same `/admin/users` route — no nav-level per-role split observed) · AI Assistant · Notifications |

A non-doctor/non-admin account additionally gets `Clinic Team / Queue / Patients Front Desk / Services & Prices` appended dynamically **only if** `ClinicService.myContext()` confirms real clinic access — never shown speculatively (this is how a "clinic secretary," who has no dedicated platform role, gets clinic-staff nav items).

Full route table (path / component / lazy-or-eager / guard / inferred role) is reproduced in the frontend agent's report; the doctor-relevant subset is already folded into §4–§15 above rather than duplicated here.

## 17. Backend → Frontend Connectivity

Doctor-relevant components, each verified by opening the actual `.ts` (and `.html` where needed) and citing the real HTTP call or the real hardcoded literal:

| Feature | Backend | API | Angular Service | Angular Component | Status |
|---|---|---|---|---|---|
| Doctor dashboard | `DashboardController::doctor()` | `GET dashboard/doctor` | none dedicated (component would call `HttpClient` directly, per existing pattern) | **missing file** | **NOT IMPLEMENTED (frontend)** |
| Patients (assigned list) | `DashboardController::doctor/caregiver()` | `GET dashboard/doctor`\|`caregiver` | — | `patient-cards.component.ts` | (A) real, wired |
| Clinic staff/team | `ClinicMembershipController` | `clinic/members*` | `ClinicService` | `clinic-staff.component.ts` | (A) real, wired |
| Queue | `QueueController` | `clinic/queue*` | `QueueService` | `clinic-queue.component.ts` | (A) real, wired |
| Front-desk patients | `ClinicPatientController` | `clinic/patients*` | `ClinicPatientService` | `clinic-patients.component.ts` | (A) real, wired |
| Services & prices | `ClinicServiceController` | `clinic/services*` | `ClinicServicesService` | `clinic-services.component.ts` | (A) real, wired |
| Doctor settings / payment methods | `PaymentMethodController` | `payment-methods/*`, `doctor/payment-methods*` | inline `HttpClient` in component | `doctor-settings.component.ts` | (A) real, wired |
| Booking (patient-facing, doctor-relevant for revenue) | `AppointmentController`, `ClinicServiceController`, `PaymentMethodController` | `appointments`, `doctors/:id/services`, `doctors/:id/payment-methods` | inline | `booking.component.ts` | **(C) partial** — time slots are a hardcoded literal array, never fetched; `completePayment()` deliberately no longer calls any gateway (none exists) |
| Prescription scan | `PrescriptionScanController` | `prescriptions/scan`, `prescriptions/register` | inline | `prescription-scan.component.ts` | (A) real, wired |
| Shared reports | `ReportController::sharedWithMe()` | `reports/shared-with-me` | inline | `shared-reports.component.ts` | (A) real, wired |
| Per-patient reports | `ReportController` | `patients/:id/reports`, `reports*` | inline | `report.component.ts` | (A) real, wired |
| Chat | `ChatController`, `GroupChatController` | `messages*`, `groups*` | inline | `chat.component.ts` | (A) real, wired — most fully-built integration in the app |
| Notifications | `NotificationController` | `notifications*` | inline | `notifications.component.ts` | (A) real, wired |
| Profile / verification | `ProfileController`, `DoctorVerificationController` | `profile*`, `doctor/verification/*` | `ProfileService` | `profile.component.ts` | (A) real, wired |

## 18. Doctor Daily Workflow

Reconstructed strictly from the code paths above (no invented steps):

1. **Login** → `AuthGuard` routes to `/dashboard`. EXISTS as a route registration; **MISSING** as an actual page (§15).
2. **See today's operational picture** — MISSING today (no dashboard); backend data now exists (§12) to support today's appointment count, checkups, revenue, but nothing renders it.
3. **Work the queue** — EXISTS and is fully real: `clinic-queue` page, `QueueController`, full state machine, permission-gated advancement.
4. **Look up / register a patient at the front desk** — EXISTS and is fully real (`clinic-patients`), exact-match search by design (privacy-motivated, not a bug).
5. **Open a patient's record** — PARTIAL: `patient-cards` → profile exists; there is no single unified chart/encounter timeline (§7) — the doctor must separately check Reports, Daily Status, Medications, Appointment history.
6. **Consultation** — PARTIAL/MISSING as a distinct concept: no encounter model; the closest real event is `QueueController::complete()`, which only flips two status columns. No structured visit note is captured tied to that specific appointment.
7. **Medical information / diagnosis / report** — EXISTS: doctor can write a `Report`/`Diagnosis` for an assigned patient.
8. **Prescription** — EXISTS: real OCR-assisted scan-and-review-and-register flow, backed by a real `Medicine` catalog for best-effort matching; no persisted scan-audit trail.
9. **Payment** — PARTIAL: real audit-trail (`Payment` rows), real revenue rollups (new this session), but no real payment gateway — every charge is a manual attestation.
10. **Services & prices** — EXISTS and is fully real.
11. **Staff** — EXISTS and is fully real, and is the most mature subsystem found in this discovery.
12. **Settings** — EXISTS (payment-method configuration; a "Settings" nav entry exists specifically because the missing dashboard file couldn't otherwise host a settings button, per a prior session's own commit history).

## 19. Competitor ERP Research

Eight systems were researched via live `WebSearch`/`WebFetch` (exceeding the 5-system minimum), prioritizing ones with real, fetchable workflow documentation rather than marketing copy alone, and specifically including two Egypt/MENA-region competitors since this product's real competitive set is regional, not US EHR vendors.

### OpenEMR (open-source)
Sources: [A Generic Medical Encounter Workflow in OpenEMR 6.1](https://www.open-emr.org/wiki/index.php/A_Generic_Medical_Encounter_Workflow_in_OpenEMR_6.1), [Patient Flow Board](https://www.open-emr.org/wiki/index.php/Patient_Flow_Board). Five-stage workflow: Receptionist books → checks in ("@Arrived" auto-creates the encounter) → Clinician documents a SOAP note on the flowboard → Provider completes clinical forms + fee-sheet coding → Accountant checks out (discount, payment method, receipt). A real-time, color-coded **Patient Flow Board** shows room, status, and elapsed wait time, auto-refreshing every 20s.

### Medesk (cloud practice management)
Sources: [homepage](https://www.medesk.net/en/), [feature blog](https://www.medesk.net/en/blog/medical-software-features/). Unified EHR (notes+results+documents in one profile), one-click video consultation, e-prescribing direct to pharmacy, 80+ ready-made reports (appointments/revenue/staff performance) plus a custom report builder, and — notably vendor-specific — integrated staff payroll/commission calculation.

### Zoho for Healthcare
Sources: [appointment creation help article](https://help.zoho.com/portal/en/kb/zoho-for-healthcare/practice-management-solution/patient-appointment/articles/create-a-new-appointment-for-patients), [treatment/procedure help article](https://help.zoho.com/portal/en/kb/zoho-for-healthcare/practice-management-solution/patient-record/articles/record-treatment-plan-and-procedure). A structured four-part **Treatment tab** per visit (Treatment Plan / Procedure Request / Procedures / Progress Note) is the most granular documented encounter-documentation model of any system researched.

### DrChrono
Source: [EHR product page](https://www.drchrono.com/ehr/), [practice management page](https://www.drchrono.com/practice-management/). Online self-scheduling + self-check-in with pre-visit intake and balance payment; a "No-Show Predictor"; Surescripts e-prescribing with real-time interaction checks; an ambient AI scribe that drafts the clinical note during the visit.

### athenahealth (athenaOne)
Source: [AI-native EHR practice-management workflows blog](https://www.athenahealth.com/resources/blog/athenaone-efficient-practice-management-workflows). Explicitly names two role-specific dashboards: a **Clinical Inbox** for clinicians (urgent tasks, critical labs, follow-ups, new messages) and a separate **Workflow Dashboard** for front-desk/billing staff (unverified insurance, outstanding payments, cancellations, no-shows, scheduling tasks) — the clearest documented example of role-differentiated dashboards among all systems researched.

### Practice Fusion
Sources: [charting product page](https://www.practicefusion.com/emr-medical-charting/), [reporting features blog](https://www.practicefusion.com/blog/practice-fusion-reporting-features/). Three distinct report categories: Quality Reporting (US-regulatory, not transferable), Clinical Reporting (chart notes, prescriptions, patient lists), Practice Management Reporting (appointment/eligibility, billing/superbills, activity/audit).

### MEDOC (medoc.care) — Egypt-based
Sources: [clinic management product page](https://medoc.care/en/solution/clinic-management-system), [Arabic blog](https://medoc.care/ar/blog/clinic-management-system), [FAQ](https://medoc.care/en/pages/faqs). Booking module + call-center module (phone-booked appointments are explicitly first-class, not just self-service), patient filing, e-prescriptions, an accounting module tracking income/expenses, and summary reports by branch/doctor/service. **No mention of Vodafone Cash/InstaPay/Fawry or any specific mobile-wallet payment method anywhere in MEDOC's own public documentation** — checked explicitly and confirmed absent.

### Vezeeta — Egypt/MENA (patient-booking brand with a doctor-side app)
Sources: [Vezeeta For Doctors, Google Play listing](https://play.google.com/store/apps/details?id=com.drbridge.Doctors&hl=en_US), [Innovator Profile, Center for Health Market Innovations](https://www.innovationsinhealthcare.org/Vezeeta_InnovatorProfile.pdf). Multi-clinic schedule management from one account, a patient database view, a recently-added invoices/payments view, and appointment-confirmation messaging explicitly aimed at reducing no-shows. A genuinely useful regional data point: the Innovator Profile notes Egyptian doctors *"initially resisted"* digital records because they *"did not have an incentive to integrate digital patient records into their daily practices"* — a real regional-adoption-friction fact, not a UI pattern.

*(Other suggested regional names — Tabibi24, DoctoRoom, Health Vision, ClinicPro, Tabbaba, and Monazm — were searched; none yielded substantive, fetchable workflow documentation beyond generic marketing copy, so no unsupported claims about them are included here.)*

## 20. Common ERP Workflow Patterns

**COMMON INDUSTRY PATTERNS** (appeared across most/all systems researched — treat as must-have for the Doctor ERP):
- Appointment-centric calendar as the operational spine (all 8 systems).
- Check-in as a discrete, status-changing event that gates the clinical workflow (OpenEMR, Practice Fusion, DrChrono).
- A unified, persistent patient record reused across visits rather than recreated per visit (Medesk, Zoho, MEDOC, athenahealth, Practice Fusion).
- E-prescribing tied to the encounter, printable/shareable to a pharmacy (Medesk, DrChrono, MEDOC, implied in Practice Fusion).
- Billing/financial data captured at the point of encounter completion, not a bolted-on separate system (OpenEMR, Medesk, MEDOC, athenahealth).
- Role-based access differentiating front-desk/administrative staff from clinical staff (OpenEMR, Medesk, MEDOC, athenahealth).
- Appointment reminders / no-show reduction as a named, marketed feature (DrChrono, Vezeeta, MEDOC, athenahealth).
- Reports/analytics segmented into clinical vs. operational/financial categories, generally by-provider and by-service (Medesk, Practice Fusion, MEDOC).

**RIVA-SPECIFIC REQUIREMENTS** (not from any competitor — these come from Riva's own already-built, differentiated capabilities, and must not be forced into generic dashboard cards borrowed from the research above):
- Daily patient self-monitoring with server-computed risk levels (`DailyStatus`) — no competitor researched documents an equivalent patient-initiated, risk-scored daily check-in.
- Medication adherence tracking with dose-level logs and automated missed-dose detection — closer to a chronic-disease-management feature than a generic EMR's medication list.
- Emergency alerts with multi-channel delivery tracking, patient-triggered.
- A caregiver role as a first-class relationship type, distinct from both patient and staff — not seen in any researched system's documented role model.
- Patient-controlled cross-doctor report sharing (patient decides, not the clinic) — the isolation model here (own-authored vs. explicitly-shared, kept physically separate) is a Riva-specific privacy design, not an industry pattern.
- Cash/mobile-wallet payment configuration per doctor (no researched system, including the two Egypt-specific ones, documents a mobile-wallet-specific payment workflow — this is a confirmed gap in competitors' public documentation, i.e. a real differentiation opportunity, not something to copy).
- Multi-tenancy via a "clinic-of-one" that transparently scales into a real multi-staff clinic without a data-model change.

**Where these two lists intersect and where the Doctor ERP dashboard specifically should draw from each**: the *dashboard's stat-card content* (today's appointments, checkups, revenue, consultations) should follow the common industry pattern (§20, first four bullets) since that's genuinely universal; the *dashboard's differentiator content* (adherence alerts, risk-level patient counts, emergency-alert status) should surface Riva's own already-built daily-monitoring/emergency data, since no competitor's dashboard pattern would tell us to build that — it already exists in Riva and nothing currently surfaces it on a doctor's landing page.

## 21. Riva Doctor ERP Gaps

Only gaps actually confirmed by reading the code (not speculative):

1. **The doctor dashboard frontend file does not exist.** (§15) — the single largest gap.
2. **No encounter/consultation model.** `Appointment` has status fields; nothing captures a structured visit record tied 1:1 to a specific appointment (§6, §18).
3. **No real payment gateway.** Every "payment" is a manual attestation (§12) — fine for the current business (cash/wallet handled outside the app) but worth being explicit that "revenue" numbers reflect attested collections, not processed transactions.
4. **No prescription/scan audit trail.** `PrescriptionScanController::scan()` persists nothing; only the final `register()` writes `Medication` rows directly, with no record of what was originally scanned or reviewed (§8).
5. **Two parallel, unreconciled notification systems** (`Notification` vs `UserNotification`) and **a notification-ownership check that doesn't verify `notifiable_type`** (§11).
6. **`DailyStatus` risk-level vocabulary mismatch** between validation/helper code and the actual DB enum (§9) — a latent correctness bug, not an ERP feature gap per se, but directly relevant if a dashboard widget is going to surface "patients needing attention" counts sourced from this field.
7. **Doctor verification status is split across three columns/tables with inconsistent literal values** (§4) — a dashboard "verification status" widget would need to pick one source of truth, which does not currently exist.
8. **No dedicated staff-facing "front desk" dashboard/workflow-inbox equivalent** to athenahealth's Workflow Dashboard — the closest existing thing is the queue page itself, which is real but is a queue view, not a task/inbox view.
9. **No formal domain-model/interface layer on the frontend** (§2) — a Doctor ERP effort introducing new shared widgets will need to either introduce one or continue the existing ad-hoc-interface-per-file pattern; this is an architectural decision to make deliberately, not a gap to silently inherit.
10. **The Angular test suite's configured run only covers one file out of 46** (§2) — any new Doctor ERP components will not be covered by `ng test` as currently configured unless that configuration is also fixed, which is itself a decision outside a discovery document's scope to make unilaterally.

## 22. Dependency Graph

Adjusted from the requested template based on what actually exists in the code (a "Consultation" node is drawn as a dashed/gap node since no such concept is implemented; "Prescription" draws directly from Queue/Patient rather than from a non-existent Consultation node, matching the real `PrescriptionScanController::ensureAssignedPatient()` check, which depends on the relationship, not on any encounter record):

```
Clinic (clinic-of-one, auto-provisioned)
  |
  +-- ClinicUser (staff/RBAC: role + permissions)
  |     |
  |     +-- Queue (check-in -> called -> in_service -> completed/no_show/skipped)
  |     +-- ClinicPatientController (front-desk register/search)
  |     +-- ClinicService (prices)
  |
Doctor
  |
  +-- PatientDoctorRelationship (status: pending/active/accepted/ended)
  |     |
  |     +-- Appointment (status: pending_payment -> pending -> confirmed -> cancelled/completed)
  |     |     |
  |     |     +-- Payment (one row per charge attempt; succeeded/failed/refunded)
  |     |     +-- [ GAP: no Consultation/Encounter record tied to a specific Appointment ]
  |     |
  |     +-- Report / Diagnosis (doctor-authored, own-patient only)
  |     |     |
  |     |     +-- ReportShare (patient-controlled, cross-doctor, isolated)
  |     |     +-- ReportComment
  |     |
  |     +-- Medication (via PrescriptionScanController::register(), or patient-entered)
  |     |     |
  |     |     +-- MedicationLog (dose adherence tracking)
  |     |
  |     +-- DailyStatus (patient-submitted, server-computed risk_level)
  |     +-- EmergencyAlert (patient-triggered)
  |
  +-- PatientCaregiverRelationship (parallel structure, caregiver-facing views of the same data)
  |
  +-- Notification / UserNotification (fan-out from most of the above)
  +-- Chat / Message (gated by the same relationship rows)
```

## 23. Missing Backend Concepts

Only concepts confirmed absent by direct search (not assumed):

- `Prescription` / `PrescriptionScan` persisted model (§8, §21.4).
- Any `Consultation`/`Encounter` model linking a `Report`/visit outcome to the specific `Appointment` it came from (§6, §21.2).
- `FormRequest` classes (none exist anywhere) and `JsonResource` classes (none exist anywhere) — an architectural gap in the whole backend, not Doctor-ERP-specific, but worth naming since new ERP endpoints will otherwise continue the inline-validate/hand-serialize pattern by default.
- Policy classes for anything other than `Clinic` — every other authorization rule is inline, ad hoc code rather than centralized, testable policy logic.
- A single source of truth for doctor verification status (three columns/tables currently, §21.7).
- A real payment gateway binding (only `ManualPaymentGateway` exists, §12).

## 24. Missing Frontend Concepts

- The doctor dashboard component itself (§15, §21.1).
- Any shared domain-model/interface layer (§2, §21.9).
- Any shared UI component library / design-token source — colors and radii are consistent by convention (repeated literal Tailwind arbitrary values) but not centrally defined (frontend agent's §10 finding).
- A working, backend-driven time-slot list for booking (currently a hardcoded array, §17).
- A real file-upload path for patient-entered medical records (currently a local-only mock, §5).
- A CI-visible test configuration that actually runs more than one spec file (§2, §21.10).

## 25. Recommended Build Order

Built in dependency order per the actual graph in §22, not as a page list:

1. **Foundation**: reconcile/confirm the exact JSON contract for `GET /dashboard/doctor` (already extended this session, §12) as the one and only data source for the dashboard — do not introduce a second endpoint.
2. **Layout**: (re)create `dashboard.component.ts/.html/.css` as a real Angular component at its registered route path, matching the existing sidebar/page-shell conventions already used by `clinic-queue`/`clinic-staff`/`clinic-services` (real, tested, and styling-consistent — the strongest existing template to copy structurally, alongside `DashboardCaregiverComponent` for chart patterns).
3. **Doctor identity/clinic context**: surface clinic name/role/verification-status prominently, since the dashboard is also effectively the doctor's "home base" for clinic identity — this is free, already-available data (`ClinicService.myContext()`).
4. **Operational day** (the "today" stat cards): today's appointments, today's completed checkups, today's pending appointments — already backed by real data (§12).
5. **Schedule/queue summary**: a lightweight live view or link into the existing, fully-real `clinic-queue` page — do not duplicate queue logic on the dashboard; surface counts and deep-link.
6. **Patient worklist**: replace the current unfiltered flat list (the user's original complaint) with search/filter over the same `patients` array `DashboardController::doctor()` already returns — no new backend endpoint needed for basic filtering, since the full relationship list with patient names is already in the payload.
7. **Patient profile** deep-link (already exists elsewhere in the app, per earlier session work — the dashboard's patient list items should link there, not duplicate profile UI).
8. **Consultation** — explicitly NOT buildable yet at the data layer (§26); the dashboard should not fabricate a "consultations today" widget beyond the completed-appointment-count proxy already implemented, until a real encounter concept exists.
9. **Prescription** — link out to the existing, real prescription-scan flow; don't rebuild it on the dashboard.
10. **Daily monitoring / medication adherence alerts** — genuinely available real data (`patient_status_distribution`, `average_adherence`, `missed_medications` are already in the existing payload) and should be a first-class dashboard section per Riva's own differentiation (§20) — this is real data already computed, just never surfaced on a doctor-facing landing page before.
11. **Revenue** — already backed by real data (§12); render as the financial section.
12. **Services** — link out to the existing, real `clinic-services` page.
13. **Staff** — link out to the existing, real `clinic-staff` page.
14. **Reports** — link out to `shared-reports` / per-patient reports; do not rebuild.
15. **Settings** — link already exists in the sidebar; no change needed here.

## 26. What MUST NOT Be Built Yet

Screens/widgets that depend on backend concepts that do not exist:

- A "today's consultations" widget that implies a structured encounter/visit-note concept — no `Consultation` model exists (§21.2); the existing "completed appointments today" proxy is honest, a dedicated consultation-detail view is not yet supportable.
- A prescription-scan history/audit widget — no persisted scan record exists (§21.4); only currently-active `Medication` rows can be shown, not "what was scanned and reviewed on this date."
- Any widget claiming a single "doctor verification status" without first picking one of the three inconsistent source columns as authoritative (§21.7) — surfacing all three risks showing contradictory badges.
- A "patients needing attention" count sourced naively from `DailyStatus.risk_level` without resolving the vocabulary mismatch first (§21.6) — could silently undercount or miscount depending on which literal values a given caller actually writes.
- Any real payment-gateway-dependent feature (auto-reconciliation, refund initiation from the dashboard) — no gateway is wired in (§21.3); only the existing manual-attestation flow is real.

## 27. Risks

- **Technical**: the file-depth/device-bridge issue that blocked this exact dashboard file for multiple turns this session is a process risk, not a code risk, but it means whoever builds this next must confirm they're starting from the real current file content, not this document's "does not exist" snapshot, before writing anything (see the caveat in §15).
- **Product**: the two unreconciled notification systems and the three-column verification status are the kind of inconsistency that a new, more prominent dashboard will make *more* visible to the doctor, not less — worth a deliberate decision (even if "pick one, document it, migrate later") before building UI on top of either.
- **Scope creep**: the competitor research (§19) surfaces many real, well-documented features (AI ambient scribes, no-show prediction, insurance-claims automation) that are explicitly **not** applicable to Riva's cash/wallet, no-insurance model (§20) — the highest risk from this research is over-indexing on US-EHR feature lists rather than the two Egypt-specific sources and Riva's own already-built differentiators.
- **Testing**: since the Angular test runner only executes one spec file today (§21.10), a new dashboard component's own spec (if written) will not run in CI unless that configuration gap is separately addressed — new frontend work here should not be assumed "tested" just because a `.spec.ts` file exists alongside it.

## 28. Recommended Doctor ERP Architecture

Not a design (colors/cards/charts are explicitly out of scope per the directive), but the structural shape this discovery supports:

- **One dashboard endpoint, already extended, already tested** (`GET /dashboard/doctor`) remains the single source of truth — no parallel "ERP summary" endpoint should be introduced; extend this one further as new real data becomes available (e.g., once an encounter model exists).
- **The dashboard is a hub, not a duplicate.** Every mature existing clinic page (queue, front-desk patients, services, staff, shared-reports) should be linked from the dashboard, not reimplemented on it — this matches the existing app's own pattern (sidebar nav to dedicated pages) and avoids the exact kind of drift that produced two notification systems and three verification-status columns.
- **New shared frontend primitives are worth introducing now, deliberately**, given §21.9/§24's finding that no shared UI/domain-model layer exists: at minimum, a shared stat-card component and a shared TypeScript interface for the `DashboardController::doctor()` response shape, so the next ERP page doesn't repeat the "ad hoc interface per file" pattern found everywhere else in this codebase.
- **Anything touching the unresolved data-model issues (§21.6, §21.7, §21.11) should be raised as its own task** in `tasks/inbox/` per this repo's own established workflow (`docs/RIVA_AGENT_WORKFLOW.md`) rather than silently patched as a side effect of dashboard work — consistent with `CLAUDE.md`'s "no rewrites, minimal diff, no unrelated refactors" rule already governing this repo.

## 29. Acceptance Criteria

"Doctor ERP [dashboard phase] implemented" means, concretely and only:

1. `dashboard.component.ts/.html/.css` exists, compiles, and renders at `/dashboard` for a `doctor`-role user without any hardcoded/fabricated data — every number shown traces to a real field in the `GET /dashboard/doctor` response.
2. The existing, already-shipped clinic-analytics fields (§12) are all visibly surfaced somewhere on the page (today's appointments/checkups, consultations, revenue, pending payments) — not just available in the API and unused.
3. The original user complaint that triggered this whole discovery — an unfiltered flat patient list with "no filtering, search, or anything else" — is resolved using the real `patients` array already in the payload, with no new backend endpoint required for basic name/status filtering.
4. Every other existing, real clinic capability (queue, front desk, services, staff, shared reports) is reachable from the dashboard via a link/summary tile, not reimplemented.
5. Nothing on the page claims data that does not exist yet (§26) — an honest empty/"not yet available" state is acceptable; a fabricated number is not.
6. `php artisan test` continues to pass at 313/313 (or higher, if new backend work is added) and any new Angular component ships with a real, buildable spec file — acknowledging (§21.10) that CI-wiring of that spec is a separate, explicitly-flagged follow-up if the existing `ng test` configuration isn't also touched.

---

## Appendix: Complete file/route inventories referenced above

The full 40-file `app/Models/` listing, the full 41-file `database/migrations/` listing, the complete route table (all 215 registrations grouped by domain), and the per-test one-line summaries for all 55 Feature test files were produced during this discovery and are available in the discovery session's agent transcripts; they are summarized by domain throughout §4–§21 above rather than reproduced in full a second time here, to keep this document navigable. Anyone needing the raw listings can regenerate them in seconds with the same commands used here: `find backend/app/Models -name "*.php"`, `find backend/database/migrations -name "*.php" | sort`, `grep -oE "Route::(get|post|put|patch|delete)\(" backend/routes/api.php`, `find backend/tests/Feature -name "*Test.php"`.
