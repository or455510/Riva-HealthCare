# Riva Doctor ERP Dashboard — Specification

**Status: SPECIFICATION ONLY. No Angular file was created or modified to produce this document. No backend file was modified either — this phase is design, not implementation.**

This document follows `DOCTOR_ERP_DISCOVERY.md` (same folder), which established what actually exists in the codebase today. Nothing in this spec re-derives facts already established there — it cites that document by section number and builds the dashboard's information architecture, component tree, data contracts, and gap list on top of it. Where this document's design requires a backend change, it says so explicitly and does not pretend the change already exists.

---

## 1. Executive Purpose

The Doctor Dashboard is the doctor's command center for one working day at one clinic. Its job is to answer seven questions the moment the doctor logs in — *what is happening now, who is waiting, who is next, who needs my attention, what happened today, how is my clinic performing, what should I do next* — using only data Riva's backend can actually produce today or is explicitly scoped here as a near-term backend addition. It is not a decorative landing page; every element on it must trace to one of those seven questions or it does not belong on the page (per the anti-decoration rule in the task brief).

## 2. Doctor Persona

Grounded in the actual data model rather than invented: a Riva doctor is a `User` with a `Doctor` profile, who owns or belongs to at least one `Clinic` (every doctor gets an auto-provisioned "clinic-of-one" the moment their profile completes — Discovery §1, §4). They may be the sole staff member of their own solo practice, or one of several doctors/staff in a real multi-person clinic. They see patients through booked appointments and clinic walk-ins, are the clinical decision-maker for their own assigned patients (never another doctor's, even inside the same clinic — Discovery §6's `CrossClinicResourceIsolationTest` finding), and are simultaneously responsible for three distinct concerns the dashboard must all serve without conflating them:

1. **Clinical** — is this specific patient okay, what does their history/monitoring data say, what do they need today.
2. **Operational** — who is physically waiting right now, in what order, and what does the front desk/queue look like.
3. **Business** — is the clinic making money, how many people were actually seen, what needs following up on.

## 3. Doctor Daily Workflow

Real workflow, reconstructed from the actual code paths documented in Discovery §6, §17, §18 — not the idealized version until validated against what the backend can support (validation notes inline):

```
DOCTOR OPENS RIVA
  -> Sees clinic identity + (GAP: operating status — see §7 Clinic Operating Status)
  -> Sees today's schedule                          [REAL: dashboard/doctor today_* fields]
  -> Sees patients already checked in                [REAL: clinic/queue, queue_status != null]
  -> Sees live queue                                 [REAL: clinic/queue, full state machine]
  -> Sees current patient (in_service) / next patient (first waiting)  [REAL, derived client-side]
  -> Opens patient                                   [REAL: existing patient profile page]
  -> Reviews relevant medical context                [REAL: Reports, DailyStatus, Medications — but
                                                        scattered across separate pages/endpoints;
                                                        NO unified chart/timeline exists — Discovery §7]
  -> Performs consultation                           [GAP: no Consultation/Encounter model exists at
                                                        all — Discovery §6, §21.2. The only real event
                                                        is QueueController::complete(), which flips two
                                                        status columns and nothing else]
  -> Creates/updates medical record                  [REAL: Report/Diagnosis — but not linked by any FK
                                                        to the specific appointment/encounter]
  -> Creates prescription if required                [REAL: PrescriptionScanController, OCR-assisted]
  -> Completes consultation                           [REAL: QueueController::complete() + Appointment
                                                        status -> completed]
  -> Payment/service recorded per actual workflow      [REAL: manual attestation only, no live gateway —
                                                        Discovery §12]
  -> Patient moves forward, doctor returns to queue    [REAL: queue auto-reorders]
```

**In parallel** (the "clinical attention flow" the brief asks the dashboard to also support): `EmergencyAlert` rows can be created by a patient at any time; `DailyStatus` submissions with elevated `risk_level` can arrive; `MedicationLog` rows can flip to `missed`. None of these interrupt the operational queue flow above — they are a second, independent stream the dashboard must surface without forcing the doctor to leave the operational view to notice them. This is why the Information Architecture (§8) keeps "NOW/TODAY" (operational) and "ATTENTION" (clinical) as separate, side-by-side concerns rather than one merged list — merging them would bury a patient-triggered emergency alert under routine queue chatter, which is exactly the kind of failure this task's anti-decoration rule is meant to prevent.

## 4. Riva-Specific Workflow

What makes this dashboard different from a generic clinic ERP dashboard, grounded in Discovery §20's "Riva-specific requirements" list:

- The clinical-attention stream (daily monitoring risk, medication adherence, emergency alerts) is **real, already-computed backend data that has never been surfaced on any doctor-facing page before** — `DashboardController::doctor()` already returns `patient_status_distribution`, `average_adherence`, `missed_medications`, and `recent_daily_statuses`, but no frontend page currently renders any of it (the dashboard component doesn't exist — Discovery §15). This is the single highest-value, zero-new-backend-work section of this whole spec.
- The queue is clinic-scoped, not doctor-scoped, by deliberate design (Discovery §6, §13) — a multi-doctor clinic's queue shows every doctor's patients together, ordered by clinic-wide arrival/position. The dashboard must derive a **doctor-specific view** (my current patient, my next patient, how many of my patients are waiting) from this shared clinic-wide feed, client-side, by filtering on `doctor_id` — this is a design decision this spec makes explicitly (§9.3) because the backend was never asked to do it and doesn't need to be.
- Payment "revenue" reflects attested collections, not processed transactions, because no real payment gateway exists (Discovery §12) — the dashboard must never phrase revenue figures in a way that implies real-time payment-gateway settlement.

## 5. Competitor Research

Ten systems have now been researched across this task and the preceding Discovery document — the eight from Discovery §19 (OpenEMR, Medesk, Zoho for Healthcare, DrChrono, athenahealth, Practice Fusion, MEDOC, Vezeeta) plus two more fetched specifically for this spec at the user's explicit request:

**NexCare** ([nexcare.io](https://nexcare.io/)) — an "Analytical Dashboard" is explicitly framed as the daily habit replacing email-checking ("check your dashboard... to see how the day's going"); a single unified patient chart ("every patient's history, notes, labs and prescriptions... search it in seconds"); billing described as claims-and-payment-chasing elimination; staff/schedule management framed around multi-branch cloud access.

**ClinicPro+** ([clinicpro.care](https://clinicpro.care/)) — a "Financial Dashboard" with real-time sales/expenses/profit and date-range filtering; live pharmacy billing with inventory; an "AI Voice to Prescription" feature; staff tracking with salary-expense reporting; WhatsApp-based automated follow-ups for no-show/reminder reduction.

Two of the four specifically named products could not be used as sources: **Tabbaba** ([tabbaba.com](https://tabbaba.com/en/)) returned a redirect loop this session and its content could not be fetched; **Health Vision/Inovexis** returned no matching, substantive product in search results (results were all unrelated "vision" = eyesight EHR products). Per the standing no-invention rule, neither is described further here rather than guessed at.

Combined with Discovery §19/§20, the **common industry patterns** most relevant to a dashboard specifically (as opposed to the whole ERP) are: (1) the dashboard/analytics view is explicitly positioned as the doctor's *daily habitual entry point*, replacing ad hoc checking (NexCare, Medesk); (2) financial summary with time-range framing (today/this-month) is a first-class dashboard citizen, not buried in a separate reports page (ClinicPro+, Medesk, Practice Fusion); (3) automated reminder/follow-up messaging is consistently tied to reducing no-shows and is dashboard-visible as a metric even where the messaging itself lives elsewhere (ClinicPro+, DrChrono, Vezeeta, MEDOC).

## 6. Lessons Extracted from Competitor Products

| Pattern | Problem it solves | Why the doctor needs it | Where it belongs in Riva | Existing Riva feature it connects to |
|---|---|---|---|---|
| Dashboard as daily habitual entry point (NexCare) | Doctor doesn't have to separately check five different screens to know how the day is going | Reduces cognitive load at the start of every working session | The whole page's framing (§8 Level 1–2) | `GET dashboard/doctor` (already real) |
| Time-scoped financial summary on the dashboard itself (ClinicPro+, Medesk) | Revenue visibility shouldn't require navigating to a separate reports module | A cash/wallet-pay solo doctor needs to know today's money at a glance, not dig for it | Level 5 (Business) | `revenue_total`, `revenue_this_month`, `weekly_revenue` (already real, shipped this session) |
| Unified patient chart, one place, not scattered folders (NexCare, Medesk, MEDOC) | Doctor shouldn't have to remember which of five pages holds which fact about a patient | Reduces the exact "scattered across Reports/DailyStatus/Medications/Appointments" gap this Discovery flagged (§7) | **Not the dashboard's job to fix** — flagged as a future, separate page-level task (a unified patient chart is its own page, not a dashboard widget); the dashboard should link to the patient profile, not attempt to replicate a chart inline |
| Check-in as a discrete, visible operational event (OpenEMR) | Front desk and doctor share one source of truth for "who has arrived" | Prevents the doctor calling a patient who hasn't checked in yet, or missing one who has | Level 1 (Operational Workspace) | `QueueController` (already real, already implements exactly this state machine) |
| Named role-specific dashboards, e.g. athenahealth's Clinical Inbox vs. Workflow Dashboard | Front-desk tasks and clinical tasks are different jobs and shouldn't be one undifferentiated list | Prevents an emergency alert getting buried under routine scheduling noise | The Level 1–2 (operational) vs. Level 3 (attention) split (§3, §8) | `EmergencyAlert`, `DailyStatus`, queue data — kept visually and structurally separate |
| Automated reminder/no-show metrics visible on the dashboard (DrChrono, ClinicPro+, Vezeeta) | Doctor should see the *result* of reminder automation (fewer no-shows), not just that reminders exist | Ties an operational investment to a visible outcome | Level 2 (Today) — `cancelled_this_month`/no-show count | Partially real (`cancelled_this_month` exists); a same-day no-show count is a small, explicit gap (§13) |

## 7. Information Architecture

The brief's proposed 6-level hierarchy is **validated against real Riva data and kept, with three explicit modifications** (each justified against actual backend capability, not preference):

1. **Level 1 (NOW)** — kept as proposed, but "Clinic operating status" is explicitly marked a backend gap (§13, §16) — the widget slot exists in the IA, but ships as a documented placeholder/absent element until the backend concept is designed and built as its own task, per this task's own anti-fabrication rule ("no placeholder frontend-only status").
2. **Level 2 (TODAY)** — "Today's activity" is narrowed in scope: the only real activity data today is `AppointmentBookingLog` with exactly one action type (`created`) written only at booking time (verified directly in `AppointmentController.php:162-167` — no other controller method writes to this table). A "today's activity" widget populated from this table today would only ever show "N patients booked appointments" and nothing about confirmations, cancellations, payments, or queue transitions. This is flagged as a partial/misleading data source and is **combined into Level 6** rather than kept as a separate Level 2 concept, so it isn't presented as more complete than it is.
3. **Level 3 (ATTENTION)** — kept as proposed, and confirmed buildable almost entirely from data `DashboardController::doctor()` **already returns today** (`patient_status_distribution`, `average_adherence`, `missed_medications`, `recent_daily_statuses`) — this is the one section where "already have the data, just never displayed it" is true across the board, except Emergency Alerts (see §13, gap #2).
4. **Level 4 (QUICK ACTIONS)** and **Level 5 (BUSINESS)** — kept as proposed, unchanged; both map cleanly onto real, existing pages/endpoints (§9).
5. **Level 6 (ACTIVITY)** — narrowed from "recent clinic activity + notifications + staff actions" to just **Notifications** (fully real, `NotificationController`) plus an honestly-scoped "Recent bookings" mini-list sourced from `AppointmentBookingLog` (labeled for what it actually is, not oversold as a full activity feed). "Staff actions" is dropped entirely from this dashboard's v1 scope — no endpoint exists that would supply it (`SystemActivityLog` is admin-scoped, not clinic/doctor-scoped — Discovery §14 area), and inventing one wasn't authorized by this task (spec-only, no backend design work beyond documenting the gap, §16).

Final hierarchy:

```
LEVEL 1 — NOW            : Clinic identity + status(GAP) · Today's appointments · Live queue snapshot · Current patient · Next patient
LEVEL 2 — TODAY          : Completed checkups · Waiting count · No-shows(derived) · Cancellations · Today's revenue
LEVEL 3 — ATTENTION      : High-risk patients · Daily monitoring alerts · Medication adherence alerts · Emergency alerts(GAP) · Review queue(composed)
LEVEL 4 — QUICK ACTIONS  : Add patient · Book appointment · Open queue · Call next patient · Review daily reports · Create prescription(contextual) · Manage services
LEVEL 5 — BUSINESS       : Revenue (today/month/avg-per-visit) · Pending payments · Services & prices link · Clinic team link
LEVEL 6 — ACTIVITY       : Notifications (real) · Recent bookings (honestly-scoped, not a full activity feed)
```

## 8. Page Hierarchy

```
/dashboard  (doctor role only, AuthGuard)
  |
  +-- links out to (does not duplicate):
  |     /clinic-queue         (full queue management)
  |     /clinic-patients      (front-desk registration/search)
  |     /clinic-services      (services & prices CRUD)
  |     /clinic-staff         (staff/RBAC management)
  |     /shared-reports       (cross-doctor shared reports)
  |     /patient-cards        (assigned patient list, existing page)
  |     /profile-p/:id        (individual patient profile, existing page)
  |     /prescription-scan/:patientId  (contextual, from current/next patient card)
  |     /notifications        (full notification list)
  |     /doctor-settings      (existing settings page)
  |
  +-- owns (rendered inline, no navigation):
        header/status strip, stat tiles, attention cards, quick-action buttons
```

The dashboard is explicitly a **hub, not a duplicate** (Discovery §28) — every "owns" item above is a small, real-data summary; every full workflow lives on its already-real dedicated page and is one click away.

## 9. Component Tree

```
DoctorDashboardPage
|
+-- DoctorDashboardHeader
|     +-- Greeting (doctor name, from AuthService.getUser())
|     +-- ClinicIdentity (clinic name/role, from ClinicService.myContext())
|     +-- ClinicStatusBadge   [GAP — renders nothing / omitted until backend ships §13 gap #1]
|
+-- NowStrip (Level 1)
|     +-- TodayAppointmentsTile
|     +-- CurrentPatientCard      (derived: clinic/queue entry with queue_status='in_service' AND doctor_id=self)
|     +-- NextPatientCard         (derived: first clinic/queue entry with queue_status='waiting' AND doctor_id=self, ordered by queue_position)
|     +-- WaitingCountTile        (derived: count of clinic/queue entries, doctor_id=self, queue_status in [waiting,called])
|
+-- TodaySummary (Level 2)
|     +-- CompletedCheckupsTile   (dashboard/doctor: today_completed_checkups)
|     +-- NoShowTile             (derived: clinic/queue entries, doctor_id=self, queue_status='no_show')
|     +-- CancelledTile          (GAP — needs "cancelled today" field, see §13 gap #4; falls back to cancelled_this_month with a "this month" label if not built)
|     +-- TodayRevenueTile       (dashboard/doctor: revenue_this_month is monthly; a true "today" figure is §13 gap #5 — falls back to today's completed-checkups x avg as an explicitly-labeled estimate is NOT allowed by the no-fabrication rule, so this tile shows revenue_this_month labeled "This month" until the gap is closed)
|
+-- AttentionCenter (Level 3)
|     +-- PatientStatusDistributionCard  (dashboard/doctor: patient_status_distribution — stable/attention/critical)
|     +-- DailyMonitoringAlertList       (dashboard/doctor: recent_daily_statuses, filtered to non-stable, rendered as the "Review Required" card pattern in §14)
|     +-- MedicationAdherenceAlertTile   (dashboard/doctor: missed_medications, average_adherence)
|     +-- EmergencyAlertBanner           [GAP — no EmergencyAlert data in this endpoint today, see §13 gap #2; omitted, not fabricated]
|
+-- QuickActions (Level 4)
|     +-- AddPatientButton        -> /clinic-patients
|     +-- BookAppointmentButton   -> existing booking flow (doctor-initiated booking path — confirm existence before wiring, see §31 note)
|     +-- OpenQueueButton         -> /clinic-queue
|     +-- CallNextPatientButton   (direct action: QueueService.call(nextPatient.id), only enabled if a next patient exists)
|     +-- ReviewDailyReportsButton -> /clinic-queue or a patient's report view (contextual)
|     +-- ManageServicesButton    -> /clinic-services
|
+-- BusinessSummary (Level 5)
|     +-- RevenueSummaryCard      (revenue_total, revenue_this_month, average_revenue_per_visit, weekly_revenue sparkline)
|     +-- PendingPaymentsTile     (pending_payments_count)
|     +-- ServicesLinkCard        -> /clinic-services
|     +-- StaffLinkCard           -> /clinic-staff
|
+-- ActivityFooter (Level 6)
      +-- NotificationsSummary    (unread count + latest 3, -> /notifications)
      +-- RecentBookingsList      (AppointmentBookingLog, action='created' only, explicitly labeled "Recent bookings" not "Activity")
```

## 10. Every Dashboard Section — Full Specification

Each section below follows the exact 12-point checklist the task requires. Sections whose data is 100% real today are marked **[REAL]**; sections requiring a documented backend change are marked **[GAP — see §13]**.

### 10.1 NowStrip — Current & Next Patient **[REAL, client-derived]**
1. **Purpose**: show the doctor who is in front of them right now and who's next, without opening the full queue page.
2. **Question answered**: "Who is waiting? Who is next?"
3. **Data source**: `GET /api/clinic/queue` (today-scoped, clinic-scoped, already excludes cancelled — Discovery §17, verified `QueueController::index()` lines 45-64 this session).
4. **Existing endpoint**: `GET /api/clinic/queue` — fully real.
5. **Required backend changes**: none. Filtering to "my patients" (`doctor_id === self`) and picking `in_service`/first-`waiting`-by-position happens client-side, since the endpoint already returns `doctor_id` per entry.
6. **Frontend component**: `CurrentPatientCard`, `NextPatientCard`.
7. **User actions**: click through to patient profile; "Call" button (only on Next) invoking `QueueService.call()`.
8. **Authorization**: inherits `clinic.context` + active membership (any active member can view — Discovery §6); the Call action requires `PERMISSION_MANAGE_QUEUE`, which a plain `doctor`-role clinic membership does **not** automatically have (Discovery §6) — **the dashboard must hide/disable the Call button for a doctor who lacks this permission**, and the backend will correctly 403 if it's attempted anyway (defense in depth, never trust the frontend hide alone).
9. **Loading state**: skeleton card, no numbers.
10. **Empty state**: "No patient waiting" / "Queue is empty today" — a real, expected state, not an error.
11. **Error state**: "Couldn't load the queue" with a retry action; never silently show stale/fabricated data.
12. **Mobile behavior**: both cards stack full-width; Call button remains a full-width thumb-sized tap target.

### 10.2 NowStrip — Waiting Count / Today's Appointments **[REAL]**
Same data source as 10.1 (waiting count) and `GET /api/dashboard/doctor` (`today_appointments_count`) respectively. Purpose: "what does today look like overall." No backend change needed. Authorization: view-only, same as 10.1. Empty state: "No appointments today." Mobile: two small tiles side by side, collapsing to stacked on narrow screens.

### 10.3 TodaySummary — Completed Checkups, No-Shows **[REAL]**
1. **Purpose**: end-of-day-so-far operational tally.
2. **Question**: "What happened today?"
3. **Data source**: `today_completed_checkups` (dashboard/doctor) for completed; `clinic/queue` filtered `queue_status='no_show'` for no-shows.
4. **Existing endpoints**: both real, listed above.
5. **Backend changes**: none for these two tiles specifically.
6. **Component**: `CompletedCheckupsTile`, `NoShowTile`.
7. **Actions**: click-through to a filtered queue view (optional, non-blocking nice-to-have — not required for v1).
8. **Authorization**: view-only, same clinic-membership gate.
9–11. **States**: skeleton on load; `0` is a valid, real empty state (not hidden); error state shows a retry, never a fabricated `0` disguised as real.
12. **Mobile**: 2x2 tile grid on tablet, single column stack on phone.

### 10.4 TodaySummary — Cancellations, Today's Revenue **[GAP — see §13 gaps #4, #5]**
1. **Purpose**: same as 10.3.
2. **Question**: "What happened today?" / "How much did I make today?"
3. **Data source (today)**: `cancelled_this_month` and `revenue_this_month` — both real but **month-scoped, not day-scoped**.
4. **Existing endpoint**: `GET /api/dashboard/doctor` (already returns the month-scoped versions).
5. **Required backend changes**: add `today_cancelled_count` (mirrors the existing `today_completed_checkups` pattern exactly — same `clinicAnalytics()` method, same `$todayAppointments` collection already computed, just one more `->where('status','cancelled')->count()` line) and `revenue_today` (mirrors `revenue_this_month`, changing the date filter from `$monthStart` to `now()->toDateString()`). Both are small, low-risk additions to a method that already exists and is already tested — explicitly **not** built in this spec-only phase, but scoped precisely enough to implement in minutes once this spec is approved.
6. **Component**: `CancelledTile`, `TodayRevenueTile`.
7. **Actions**: none required for v1.
8. **Authorization**: view-only.
9. **Loading**: skeleton.
10. **Empty**: `0 today` is valid and expected, especially early in the day.
11. **Error**: retry, never fabricate.
12. **Mobile**: same tile grid as 10.3.
13. **Until the gap is closed**: these tiles render the month-scoped numbers with an explicit **"This month" label**, never silently relabeled as "today" — this is the honest-labeling rule from the anti-fabrication section applied literally.

### 10.5 AttentionCenter — Patient Status / Daily Monitoring / Medication Adherence **[REAL]**
1. **Purpose**: surface Riva's actual differentiator — continuous monitoring — on the page a doctor opens every day, which has never happened before (Discovery §15, §20).
2. **Question**: "Who needs my attention?"
3. **Data source**: `patient_status_distribution`, `recent_daily_statuses`, `average_adherence`, `missed_medications` — all already returned by `GET /api/dashboard/doctor` today.
4. **Existing endpoint**: same, real, no change needed.
5. **Backend changes**: **none required for a first version**, but two data-quality issues from Discovery should be resolved before this section ships, or the section will surface them to doctors: (a) the `DailyStatus.risk_level` vocabulary mismatch between validation code and the DB enum (Discovery §9, §21.6) — if left unresolved, a "high-risk count" tile risks under/over-counting depending on which code path wrote a given row; (b) `recent_daily_statuses` is capped at the doctor's **most recent 10 statuses across all patients**, not "10 statuses that need review" — a doctor with many stable patients could have this list pushed out by routine stable check-ins. This spec recommends (as a backend task, not built here) that the query be changed to prioritize non-stable `risk_level` values first, same ordering technique already used in `QueueController::index()`'s `orderByRaw` (Discovery §17) — a proven, low-risk pattern already in this codebase.
6. **Component**: `PatientStatusDistributionCard`, `DailyMonitoringAlertList`, `MedicationAdherenceAlertTile`.
7. **Actions**: click a monitoring alert -> patient's report/daily-status detail (existing page).
8. **Authorization**: view-only, doctor-scoped by the endpoint itself (no client-side filtering needed — `DashboardController::doctor()` already scopes to `PatientDoctorRelationship::where('doctor_id', $doctor->id)`, Discovery §17).
9. **Loading**: skeleton list.
10. **Empty**: "No patients need review right now" — genuinely good news, phrased as such, not as an error or missing-data state.
11. **Error**: retry.
12. **Mobile**: list items become full-width cards, one per row, matching the exact "Review Required / Ahmed Hassan / ..." card format specified in the task brief (§14 below).

### 10.6 AttentionCenter — Emergency Alerts **[GAP — see §13 gap #2]**
1. **Purpose**: the single highest-urgency item that could exist on this page.
2. **Question**: "Is there an emergency I need to know about right now?"
3. **Data source needed**: `EmergencyAlert` where the patient has an active/accepted relationship with this doctor and `status='active'` (mirroring the exact relationship-gating pattern already used everywhere else in this codebase — Discovery §1's architecture summary).
4. **Existing endpoint**: none — `DashboardController::doctor()` does not query `EmergencyAlert` at all today (confirmed by direct read of the controller in Discovery §17 and re-confirmed in this session's own edit of that file, §21 of Discovery).
5. **Required backend change**: add an `active_emergency_alerts` field to `clinicAnalytics()` (or a new top-level key on `doctor()`), doctor-scoped the same way `patient_status_distribution` already is. This is a real, explicitly-scoped, small addition — not built in this phase.
6. **Component**: `EmergencyAlertBanner` — rendered with the highest visual priority on the page (top of Attention Center, or even above the fold entirely) once real, once the backend field exists.
7. **Actions**: click -> patient profile / the existing emergency-alert handling surface (**UNKNOWN** whether a dedicated emergency-alert detail view exists on the frontend today — not confirmed by either discovery pass; verify before wiring an action, don't assume one exists).
8. **Authorization**: doctor-scoped via relationship, same pattern as the rest of this endpoint.
9–12. **States**: not applicable until the backend field exists — **this component must not render at all (not even an empty state) until real data is available**, per the explicit "no placeholder frontend-only status" instruction in the task brief. Rendering a permanently-empty "No emergencies" banner before the backend can ever populate it risks training the doctor to trust a signal that isn't real yet.

### 10.7 QuickActions **[REAL, all link out to existing pages/actions]**
1. **Purpose**: one-tap access to the actions a doctor performs most often, without hunting through the sidebar.
2. **Question**: "What should I do next?"
3–4. **Data/endpoint**: none needed — these are navigation/action triggers, not data displays.
5. **Backend changes**: none.
6. **Component**: `QuickActions` (a row/grid of buttons).
7. **Actions**: Add Patient -> `/clinic-patients`; Open Queue -> `/clinic-queue`; Call Next Patient -> direct `QueueService.call()` call (same permission gate as §10.1); Manage Services -> `/clinic-services`; "Book Appointment" -> **verify a doctor-initiated booking entry point exists before wiring this button** (the existing `BookingComponent` at `/booking/:id` is reached from the *patient* side per Discovery §17's route table; a doctor-side "book on behalf of a walk-in patient" flow was not confirmed to exist as a distinct entry point in either discovery pass — flagged here explicitly rather than assumed, per §31).
8. **Authorization**: each button individually respects the permission of the action it triggers (queue actions need `PERMISSION_MANAGE_QUEUE`, patient registration needs `PERMISSION_REGISTER_PATIENTS`, services need `PERMISSION_MANAGE_SERVICES` — Discovery §6/§13); a button whose permission the current user lacks should be **hidden, not just disabled-and-unexplained**, consistent with how the rest of this codebase already treats permission-gated UI (e.g., `ClinicServiceController`'s staff-without-permission test expectations).
9–12. **States**: not data-driven; standard button disabled/loading states during an in-flight action (e.g., Call Next Patient shows a spinner while the API call is in flight).

### 10.8 BusinessSummary **[REAL]**
1. **Purpose**: business/financial visibility without leaving the dashboard.
2. **Question**: "How is my clinic performing?"
3. **Data source**: `revenue_total`, `revenue_this_month`, `average_revenue_per_visit`, `pending_payments_count`, `weekly_revenue`, `currency` — all real, all shipped this session (Discovery §12).
4. **Existing endpoint**: `GET /api/dashboard/doctor`.
5. **Backend changes**: none required; §13 gap #5 (`revenue_today`) would enhance but is not required for this section to be real and useful.
6. **Component**: `RevenueSummaryCard` (with a small sparkline from `weekly_revenue`, reusing the existing `ng-apexcharts` dependency already used by `DashboardCaregiverComponent` — Discovery §2), `PendingPaymentsTile`, `ServicesLinkCard`, `StaffLinkCard`.
7. **Actions**: click-through to `/clinic-services`, `/clinic-staff`.
8. **Authorization**: view-only; the underlying `payment-methods`/`clinic/services` pages the links lead to already enforce their own permission gates independently.
9. **Loading**: skeleton, including a placeholder sparkline shape.
10. **Empty**: `revenue_total: 0` for a brand-new doctor with no completed/paid appointments yet is a real, valid, expected state — must read as "no revenue yet," not as an error.
11. **Error**: retry.
12. **Mobile**: sparkline either scrolls horizontally within its own bounded container or is replaced by a simple 7-number row — never causes page-level horizontal scroll (matches the existing artifact-wide responsive rule already followed elsewhere in this codebase's Tailwind usage).

### 10.9 ActivityFooter **[REAL, narrowly scoped]**
1. **Purpose**: light-touch awareness of recent bookings and unread notifications without duplicating the full Notifications page.
2. **Question**: "What happened recently that I should know about?"
3. **Data source**: `NotificationController` (real, existing) for notifications; `AppointmentBookingLog` (real but `action='created'` only — §7 modification #2) for "Recent bookings."
4. **Existing endpoints**: `GET /api/notifications`; **no existing endpoint exposes `AppointmentBookingLog` to the frontend today** — confirmed by the route table in Discovery §17 (no route references this model/table). This is the one small backend addition this section needs.
5. **Required backend change**: a doctor-scoped `GET` endpoint (or a new field on `dashboard/doctor`) returning the most recent N `AppointmentBookingLog` rows for this doctor's appointments — small, additive, not built in this phase.
6. **Component**: `NotificationsSummary`, `RecentBookingsList`.
7. **Actions**: click-through to `/notifications`.
8. **Authorization**: doctor-scoped (join through `Appointment.doctor_id`), same pattern as everything else in this endpoint.
9–12. **States**: standard skeleton/empty ("No recent bookings")/error/retry; mobile stacks both lists full-width beneath everything else on the page (lowest priority, bottom of scroll).

## 11–20. Cross-Cutting: Data, Endpoints, Backend/DB Changes, Authorization, States, Actions

Consolidated rather than repeated a third time (each was already covered per-section in §10 above, as the task's own 12-point-per-section checklist requires) — this section is the single-place summary for anyone scanning just the gaps:

**All backend changes this spec identifies as required, in one list** (none built in this phase):
1. `today_cancelled_count` on `clinicAnalytics()` (§10.4) — trivial, same method, same pattern as `today_completed_checkups`.
2. `revenue_today` on `clinicAnalytics()` (§10.4) — trivial, same method, same pattern as `revenue_this_month` with a different date filter.
3. `active_emergency_alerts` (count + minimal detail) on `doctor()`, relationship-scoped like the rest of the endpoint (§10.6) — new query, new relationship-gating logic modeled on existing patterns, moderate size.
4. A doctor-scoped recent-bookings read endpoint or field over `AppointmentBookingLog` (§10.9) — small, new.
5. (Recommended, not required for v1) Re-order `recent_daily_statuses` to prioritize non-stable risk levels first, reusing the `orderByRaw` pattern already proven in `QueueController::index()` (§10.5).
6. (Flagged, not required for the dashboard itself, but noted because the dashboard will make it more visible) Resolve the `DailyStatus.risk_level` vocabulary mismatch (Discovery §9, §21.6) before shipping any "high-risk count" that depends on it being counted consistently.

**No database migrations are strictly required** for gaps #1, #2, #4, #6 (they read existing columns differently or add a read path over an existing table). Gap #3 (Emergency Alerts) needs no new migration either — `EmergencyAlert` already exists with everything needed (Discovery §10); it only needs a new query added to an existing controller method.

**Authorization** for every section is either (a) inherited automatically from the already-real `GET /api/dashboard/doctor` endpoint's own doctor-scoping, (b) inherited from `GET /api/clinic/queue`'s clinic-membership gate, or (c) a specific existing `ClinicUser` permission for any action button (§10.1, §10.7) — no new authorization concept is required anywhere in this dashboard; every rule it needs already exists and is already tested (Discovery §13).

## 21. Loading, Empty, Error, Permission States — General Rules

Beyond the per-section detail in §10:
- **Loading**: every section renders its own independent skeleton — the page must not block entirely on the slowest of the two source calls (`dashboard/doctor`, `clinic/queue`). Sections fed by `dashboard/doctor` can render together once that one call resolves; the queue-derived cards (§10.1) resolve independently.
- **Empty ≠ Error**: a `0` or an empty list from a successful API call is always a real, calm empty state with encouraging/neutral copy — never styled or worded like an error.
- **Error**: any failed call shows a scoped retry for just that section, never a full-page crash — one section's failure (e.g., queue endpoint down) must not prevent the rest of the dashboard (e.g., revenue, attention center) from rendering.
- **Permission states**: an action the current user's role/clinic-permission doesn't allow is hidden, not shown-disabled-with-no-explanation (§10.7) — matching this codebase's existing convention of never surfacing a control the backend would reject anyway.

## 22. User Actions

Consolidated list (all detailed with their authorization gate in §10): open patient profile, call next patient, check-in is **not** performed from the dashboard (it belongs to the front-desk/queue page and duplicating it here would violate the "hub, not a duplicate" rule, §8), add patient, open queue, manage services, review a monitoring alert, view all notifications, book an appointment (pending the existence-check in §10.7/§31).

## 23. Navigation Relationships

Fully enumerated in §8 (Page Hierarchy). The dashboard is a leaf in the sidebar's own navigation (Discovery §16) and a hub for everything it links to — no circular or redundant navigation paths are introduced.

## 24. Responsive Behavior

**Desktop** (the primary information-hierarchy target per the task brief): the six levels render top-to-bottom in priority order (Now -> Today -> Attention -> Quick Actions -> Business -> Activity), with Now/Today/Attention using multi-column tile grids and Quick Actions as a horizontal button row.

**Tablet**: tile grids reduce from 4-across to 2-across; Quick Actions row may wrap to two rows.

**Mobile**: every grid collapses to a single column; the existing sidebar (Discovery §2, §16) already has its own mobile pattern this page inherits unchanged (no new mobile navigation shell is introduced by this spec); tables are avoided entirely in this design (the dashboard has no tabular data — queue/patient tables live on their own dedicated pages, §8) so the "tables become cards or controlled scroll" rule from the brief doesn't apply here by construction.

## 25. Mobile Behavior

Critical actions (Call Next Patient, in particular) remain full-width, thumb-height tap targets on mobile, per the task brief's explicit instruction — this is called out again here because it's the one action on this page with real time-sensitivity (a doctor tapping this between patients, one-handed).

## 26. Accessibility

- Every stat tile's number must have an accompanying text label in the DOM (not conveyed by color/icon alone) — relevant given this codebase's existing convention of color-as-status (Discovery §2's palette findings show status colors like red/amber/green already used elsewhere; this page must not introduce color-only meaning).
- The Emergency Alert banner (§10.6), once built, must not rely on color alone (e.g., "red banner") — it needs an explicit textual severity label, consistent with the task brief's own "never claim to diagnose, but never bury a real emergency" spirit.
- All interactive elements (cards that link out, buttons) must be real focusable/keyboard-activatable elements, not `div`s with click handlers — a baseline not otherwise confirmed as a pattern across this codebase's existing components (**UNKNOWN** — neither discovery pass audited accessibility specifically; this spec sets the requirement rather than assuming prior compliance).

## 27. Performance Considerations

- Two network calls drive almost the entire page (`GET dashboard/doctor`, `GET clinic/queue`) — both already exist and are already used elsewhere, so no new performance risk is introduced by this design; the page should not introduce a third redundant call for data either endpoint already returns.
- `GET clinic/queue` is today-scoped and clinic-scoped at the database level already (Discovery §17, confirmed `whereDate`/`whereHas` in the controller) — it does not scale with total historical appointment count, so polling it periodically for a "live" feel (if desired later) is safe; this spec does not mandate polling/websocket behavior, since neither exists today for this data and introducing one is a separate decision outside a dashboard-content spec.
- The `weekly_revenue` sparkline (§10.8) is a fixed 7-element array already computed server-side — no client-side heavy computation needed.

## 28. Security Considerations

- Every number on this page must come from a doctor-scoped or clinic-membership-scoped backend response — never from a client-supplied `doctor_id`/`clinic_id` (matching the explicit "never trust frontend clinic_id/doctor_id/patient_id/price/status/role" rule in the task brief, and matching how every existing endpoint this spec relies on already behaves — Discovery §6, §13).
- The Call-Next-Patient action (§10.1, §10.7) must still be enforced server-side by `PERMISSION_MANAGE_QUEUE` even though the frontend hides the button for users who lack it — this is already how the real `QueueController` behaves today (Discovery §6), so no new backend security work is needed, only correct frontend button-visibility logic.
- Once built, the Emergency Alert section (§10.6) must use the same relationship-gating as every other medical-data surface in this codebase (Discovery §1's two-boundary architecture) — it must never become reachable via mere clinic co-membership, matching the explicit, tested rule that clinic membership is insufficient for medical data (`CrossClinicResourceIsolationTest`).

## 29. Testing Requirements

- Any new backend field (§11's list) must ship with Feature tests following the exact pattern already established this session for `DoctorDashboardClinicAnalyticsTest` (Discovery §12) — doctor-scoping proven with a second, unrelated doctor whose data must never appear in the first doctor's response.
- Any new Angular component must ship with a real, buildable spec file — and, per Discovery §21.10's finding that only one spec file is currently wired into `ng test`, whoever implements this dashboard should flag (not silently accept) that a new spec won't run in CI unless that separate, pre-existing configuration gap is also addressed; this spec does not resolve that gap itself (out of scope for a dashboard-content spec) but repeats the flag so it isn't lost.
- Each section's three data states (loading/empty/error, §21) should have at least one corresponding test or story once real code exists.

## 30. Acceptance Criteria

Adopts Discovery §29's criteria and adds dashboard-spec-specific ones:
1. Every widget on the page traces to a real backend field or an explicitly-labeled, honestly-scoped fallback (§10.4's "This month" labeling rule) — nothing is fabricated.
2. The Emergency Alert section either shows real data or does not render at all — never a fake "no emergencies" placeholder ahead of the backend supporting it (§10.6).
3. The doctor's original complaint — an unfiltered flat patient list — is not on this dashboard at all in this design (the patient worklist itself is explicitly out of this dashboard's Level 1-6 scope per the validated IA in §7; Discovery §25 step 6 already resolves it on the existing patient-list surface, not by duplicating a searchable list here) — **note this explicitly to the product owner as a scope call this spec is making**, since the original complaint could be read as "put a searchable patient list on the dashboard" rather than "link to the existing one." This spec chooses the latter (hub-not-duplicate, §8) but flags the alternative reading here rather than silently deciding it.
4. Every action button respects real backend permission gates, hidden (not just disabled) when the current user lacks the permission (§10.7).
5. `php artisan test` continues to pass at its current count (313, Discovery §3) or higher for any backend gap closed as a follow-up task.
6. No Angular file exists yet as a result of this spec-writing phase — verified true as of this document (§31/§32 below).

## 31. Explicit List of Things That MUST NOT Be Implemented Yet

- The Emergency Alert widget, until `active_emergency_alerts` is a real field (§10.6).
- A "today's cancellations"/"today's revenue" tile labeled as "today" using month-scoped data — must be labeled "this month" until gaps #1/#2 (§11) are closed.
- A "recent activity" feed implying full lifecycle coverage (confirm/cancel/pay/queue events) — only "recent bookings" (created-only) may ship, honestly labeled (§10.9).
- A doctor-initiated "Book Appointment" quick-action button, until it's confirmed whether a doctor-side booking entry point actually exists distinct from the patient-facing `BookingComponent` (§10.7) — wiring a button to the wrong/nonexistent flow would itself be a fabrication of functionality.
- Any exact wait-time-in-minutes display for the queue — the task brief's own instruction ("3 patients before you," not "23 minutes," unless defensible) applies directly, and no per-patient average-consultation-duration data exists anywhere in this codebase to make a minutes estimate defensible.
- Any consultation/encounter detail view or "start consultation" screen beyond the existing queue "Call"/"Complete" actions — no such model exists (Discovery §21.2), and this spec does not design one (out of scope: that's a Consultation-page spec, not a Dashboard spec).
- Any clinic-operating-status toggle UI — the backend concept doesn't exist; a frontend-only status would be exactly the "inconsistent frontend-only status" the task brief explicitly forbids.
- Any code at all — Angular or Laravel. This phase produces only this document.

## 32. Implementation Order (for the next phase, once this spec is approved)

1. Confirm current real state of `dashboard.component.ts/.html/.css` (Discovery §15's caveat — another session may have already staged content; reconcile before writing anything new).
2. Backend: close gaps #1 and #2 (§11) — trivial, same method, immediately unlocks honest "today" labels for §10.4.
3. Backend: close gap #4 (§11) — recent-bookings read path, unlocks §10.9 fully.
4. Frontend: build `DoctorDashboardHeader`, `NowStrip`, `TodaySummary` (Levels 1-2) — 100% real data already, or real as of step 2, highest-confidence section to build first.
5. Frontend: build `AttentionCenter` minus Emergency Alerts (Level 3 partial) — 100% real data already, second-highest priority per Discovery §20's "this is Riva's real differentiator and has never been shown before."
6. Frontend: build `QuickActions` and `BusinessSummary` (Levels 4-5) — real data/real links, straightforward.
7. Frontend: build `ActivityFooter` (Level 6) using the step-3 endpoint.
8. Backend (separate, larger task): design and build gap #3 (Emergency Alerts) as its own reviewed task per this repo's own task-board workflow (`docs/RIVA_AGENT_WORKFLOW.md`, Discovery §1) — then wire `EmergencyAlertBanner` as the final piece.
9. Backend (separate, larger task, lower priority): resolve the `DailyStatus` risk-level vocabulary mismatch and the `recent_daily_statuses` ordering improvement (§10.5) — can happen before or after step 8, does not block shipping the rest of the dashboard.

Steps 4-7 can ship as a real, honest, non-decorative dashboard on their own, with steps 8-9 as clearly-communicated follow-ups — this matches the task brief's own instruction that an honest "not yet available" beats a fabricated feature, and avoids blocking the entire page on the largest, least-scoped gap (Emergency Alerts).
