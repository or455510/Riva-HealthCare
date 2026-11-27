# Riva Engineering Implementation Journal

This is a chronological engineering journal, not an audit. Each entry
documents one coherent implementation slice as it was actually built: what
was inspected, what changed, what was tested, what was not, and what
remains. Entries 1–3 are written retroactively (the mandatory-journal
directive was issued after those slices completed); their file lists, test
names, and commit hashes are the real ones from this session's actual work,
not reconstructed from memory. Entry 4 was written as the slice was built.

Anyone starting a new session on this codebase should read this file, then
`FINAL_HANDOFF.md` when it exists, then the current git log and working
tree — and then read the actual code before changing anything. This journal
is a navigation aid, not a substitute for that.

---

## Slice 1 — Signed File URLs

### 1. Objective
Chat attachments and doctor-verification documents were served through an
`auth:sanctum`-protected `/api/files/{path}` route. That route is correctly
authorized, but it is architecturally unusable from the places the frontend
actually needs it: an `<img src="...">` tag, a chat bubble's attachment
thumbnail, or a downloaded PDF link cannot attach an `Authorization: Bearer`
header — browsers only send headers on requests initiated by fetch/XHR, not
on plain resource loads. The practical effect was that every such image or
document either 401'd or had to be proxied through JavaScript in a way nothing
in the codebase actually did. This slice replaces bearer-token file access
with signed, time-limited URLs the browser can load directly, while keeping
per-viewer authorization intact. No prior functionality depended on the old
raw-URL behavior other than the (dead) `resolveStorageUrl()` frontend helper,
confirmed via grep before starting.

### 2. Before State
`StorageFileController::show()` existed and correctly checked ownership, but
was reachable only behind `auth:sanctum` at `/api/files/{path}`. `Message`
and `DoctorVerification` exposed raw `Storage::disk('public')->url($path)`
values (world-readable public-disk URLs, no authorization at all once
guessed) or the bearer-token route depending on the code path. `ChatController::upload()`
returned `asset('storage/' . $path)`.

### 3. Files Inspected
- `app/Http/Controllers/StorageFileController.php`
- `app/Models/Message.php`
- `app/Models/DoctorVerification.php`
- `app/Http/Controllers/ChatController.php`
- `routes/api.php`
- Frontend: grepped for `resolveStorageUrl` usage (confirmed dead code, zero
  call sites, before deciding no frontend change was needed for this slice)

### 4. Files Created
- `tests/Feature/SignedFileUrlTest.php` — 5 feature tests covering the new
  signed-URL flow end to end (see §12).

### 5. Files Modified
- `app/Http/Controllers/StorageFileController.php` — added
  `showSigned(Request $request, string $path)`, validating
  `hasValidSignature()` and resolving the `uid` query param to a `User`
  before delegating to the existing authorization check. Added
  `public static function signedUrl(string $path, int $viewerId, int $minutes = 30): string`
  wrapping `URL::temporarySignedRoute()`. Extracted `resolvePath()` and
  `authorizeAccess()` as private methods shared by both `show()` (still
  present, still bearer-token-gated) and the new `showSigned()`.
- `app/Models/Message.php` — added a `getFileUrlAttribute()` accessor that
  normalizes any legacy full-URL or bare-path value and returns a signed URL
  scoped to `auth()->id()`, or `null` if unauthenticated.
- `app/Models/DoctorVerification.php` — rewrote `publicUrl()` the same way,
  scoped to the requesting viewer.
- `app/Http/Controllers/ChatController.php` — `upload()` now returns the bare
  disk-relative path instead of a public asset URL; the signed URL is minted
  later, per-viewer, by the `Message` accessor above — never at upload time,
  since the uploader is not necessarily who will view it later.
- `routes/api.php` — added a public (pre-`auth:sanctum`) route:
  `GET /files/signed/{path}`, named `files.signed`, constrained by Laravel's
  built-in `signed` middleware.

### 6. Database Changes
No database change required.

### 7. Backend Business Logic
1. Whenever a `Message` or `DoctorVerification` is serialized to JSON, its
   file-URL accessor calls `StorageFileController::signedUrl()` with the
   *currently authenticated user's* ID as the viewer.
2. `signedUrl()` mints a Laravel signed route URL (`URL::temporarySignedRoute`)
   valid for 30 minutes, embedding `path` and `uid` as query parameters that
   are themselves part of the HMAC signature.
3. When that URL is loaded (e.g. by an `<img>` tag, no auth header possible),
   `showSigned()` first lets Laravel's `signed` middleware verify the
   signature and expiry — a tampered `uid`, `path`, or `expires` value fails
   here before any application code runs.
4. `showSigned()` then loads the `User` named by `uid` and runs the *same*
   `authorizeAccess()` check `show()` always used (sender/receiver for chat
   files, owning doctor or admin for verification docs) — the signature
   proves the URL wasn't tampered with, but authorization is still
   re-checked against that specific user, not merely "someone with a valid
   signature."

### 8. Authorization / Security
- CORRECT USER: sender or receiver of a message, or the owning doctor /
  an admin for verification docs — signed URL resolves and serves the file.
- WRONG USER: a signed URL minted for user A, if somehow presented with a
  different `uid`, fails re-authorization (not just signature checking).
- WRONG CLINIC: not applicable to this slice — file access is scoped by
  message/relationship ownership, not clinic membership.
- WRONG RELATIONSHIP: a chat file between two other users cannot be viewed
  by a third party even with a guessed path, because `uid` is bound into the
  signature and re-checked against `authorizeAccess()`.
- MANIPULATED ID: changing `uid` or `path` in the query string invalidates
  the HMAC signature — verified directly in
  `SignedFileUrlTest::test_tampering_the_uid_query_param_invalidates_the_signature`.
- UNAUTHORIZED ROLE: not role-gated: authorization here is
  relationship/ownership-based, matching the underlying resource.
- INVALID INPUT: an expired URL (`travel(31)->minutes()`) is rejected by the
  `signed` middleware before reaching application code.

### 9. API Changes
| Method | Path | Auth | Request | Response | Errors |
|---|---|---|---|---|---|
| GET | `/api/files/signed/{path}` | Signed URL (no bearer token) | Query: `expires`, `signature`, `uid` | 200, file stream | 403 invalid/expired signature or unauthorized viewer |

No existing endpoint's request/response contract changed; `Message.file_url`
and `DoctorVerification`'s serialized URL field now contain signed URLs
instead of raw/bearer-gated ones — same field names, different URL shape.

### 10. Frontend Changes
None required. `resolveStorageUrl()` in `constants.ts` was confirmed (by
grep, zero call sites) to be dead code before concluding this. The frontend
already renders `message.file_url` and the verification document URL
directly as `<img>`/`<a>` targets, which now simply works because the URL
itself carries authorization. CONNECTED TO REAL API — no mock data involved.

### 11. User Workflow
A patient or doctor viewing a chat thread sees attachment thumbnails render
directly (previously broken/401 for `<img>` tags). An admin reviewing doctor
verification documents can open the document link directly. Both are backed
by the real signed-URL endpoint, not a stub.

### 12. Tests Added
`tests/Feature/SignedFileUrlTest.php`, 5 tests:
- `chat_upload_response_returns_a_signed_url_not_a_raw_storage_url`
- `message_list_signed_url_is_usable_by_sender_and_receiver_but_not_a_stranger`
- `tampering_the_uid_query_param_invalidates_the_signature`
- `expired_signed_url_is_rejected`
- `doctor_verification_signed_url_scoped_to_owning_doctor_and_admin`

### 13. Test Commands
```
php artisan test --filter=SignedFileUrlTest
php artisan test
```

### 14. Test Results
All 5 new tests passed on first stable run (after adding the
`makeRelatedPatientAndDoctor()` helper — see §Errors below). Full suite
passed at commit time.

### 15. Not Tested
Browser-level rendering of an `<img src>` pointed at a signed URL was not
verified in an actual browser this session (no `device_bash`/browser
automation used for this slice) — verified only via HTTP-level feature
tests.

### 16. Environment Blockers
None specific to this slice.

### 17. Regression Check
Full suite run at commit time passed with no other test files affected.

### 18. Git
Commit `ec9c425` — `feat(files): replace raw storage URLs with signed,
ownership-checked file URLs`. Synced to the real device and verified via
`device_list_dir` size/mtime check after write.

### 19. Current System State
Backend implemented and feature tests pass. Frontend already consumes the
changed field with no code change needed. Not verified in a real browser.

### 20. Next Slice
Proceeded to Slice 2 (payment audit trail).

---

## Slice 2 — Payment Audit Trail + Gateway Abstraction

### 1. Objective
`AppointmentController::pay()` previously flipped an appointment's payment
status directly with no persisted record of the transaction itself — no
amount-at-time-of-charge snapshot, no gateway reference, no failure reason,
no way to answer "what actually happened to this payment" after the fact.
This slice adds a real `payments` audit-trail table and a gateway
abstraction boundary, so that (a) every charge/failure attempt is a
permanent row, and (b) swapping in a real payment processor later (Stripe,
Paymob, etc.) means writing one new class, not touching controller logic.

### 2. Before State
`AppointmentController::pay()` and `failPayment()` mutated
`appointments.payment_status` directly with no supporting table. No gateway
abstraction existed — "payment" meant an admin manually flipping a status
enum.

### 3. Files Inspected
- `app/Http/Controllers/AppointmentController.php`
- `app/Models/Appointment.php`
- `app/Providers/AppServiceProvider.php`
- `tests/Feature/AppointmentPaymentFlowTest.php` (pre-existing)

### 4. Files Created
- `database/migrations/2026_08_23_000007_create_payments_table.php`
- `app/Models/Payment.php`
- `app/Services/Payments/PaymentGatewayResult.php`
- `app/Services/Payments/PaymentGatewayInterface.php`
- `app/Services/Payments/ManualPaymentGateway.php`
- `app/Services/Payments/PaymentService.php`

### 5. Files Modified
- `app/Providers/AppServiceProvider.php` — bound `PaymentGatewayInterface`
  to `ManualPaymentGateway` in `register()`.
- `app/Models/Appointment.php` — added `payments(): HasMany`.
- `app/Http/Controllers/AppointmentController.php` — constructor now also
  injects `PaymentService`; `pay()` calls `paymentService->charge()` and
  returns 422 with the failure reason if the gateway result was not a
  success; `failPayment()` calls `paymentService->recordFailure()`;
  `paymentStatus()` response now includes the full `payments` audit list.
- `tests/Feature/AppointmentPaymentFlowTest.php` — extended with an
  audit-trail assertion on the existing confirm-payment test, plus 2 new
  tests (see §12).

### 6. Database Changes
New `payments` table: `id`, `appointment_id` (FK→appointments,
cascadeOnDelete), `amount` decimal(10,2), `currency` char(3) default
`'EGP'`, `status` enum[pending,succeeded,failed,refunded] default
`'pending'`, `gateway` varchar(32) default `'manual'`, `gateway_reference`
nullable string, `failure_reason` nullable text, `refunded_amount`
decimal(10,2) default 0, `recorded_by` FK→users nullOnDelete, timestamps,
composite index `[appointment_id, status]`. Applied and verified only
against sqlite via `RefreshDatabase` in the test suite — **not yet run
against the real device's MySQL database** (`php artisan migrate` cannot
reach it from this sandbox; see §16).

### 7. Backend Business Logic
1. `PaymentService::charge()` opens a DB transaction, creates a `pending`
   `Payment` row snapshotting the appointment's current `amount` (so a later
   price change never rewrites history), then calls the bound
   `PaymentGatewayInterface::charge()`.
2. Any exception thrown by the gateway is caught inside a private
   `attemptCharge()` wrapper and converted into a failure result — a gateway
   integration bug becomes a recorded failed payment, never an uncaught
   500.
3. The `Payment` row and the parent `Appointment` (`payment_status`,
   `status`) are updated together based on the gateway result, still inside
   the same transaction.
4. `recordFailure()` is a separate, simpler path for an admin explicitly
   marking a payment failed (no gateway call at all) — creates a `failed`
   `Payment` row directly.
5. `ManualPaymentGateway` (the only current binding) performs zero external
   I/O and always returns success with a synthetic reference
   (`manual:{payment_id}:{timestamp}`) — an honest reflection of Phase 0's
   existing admin-attestation-only payment model, not a fake "real" gateway.

### 8. Authorization / Security
Unchanged from the pre-existing `pay()`/`failPayment()` authorization (admin
or the clinic member with `payments.record` permission, per the existing
appointment/clinic policies) — this slice did not alter who may call these
endpoints, only what happens when they do and what gets recorded.

### 9. API Changes
| Method | Path | Response change |
|---|---|---|
| POST | `/api/payments/{appointment}/pay` | Now returns 422 with a real failure reason if the gateway result is not success, instead of always succeeding |
| POST | `/api/payments/{appointment}/fail` | Now creates a real audit-trail `Payment` row via `recordFailure()` |
| GET | `/api/payments/{appointment}` | Response now includes `payments`: the full audit trail array (latest first) |

### 10. Frontend Changes
None made this slice — the existing payment-status screen (if any) was not
touched. The `payments` array added to the response is additive and
backward compatible; no existing frontend code reads or breaks on it.
NOT CONNECTED to a dedicated audit-trail UI — this remains a backend-only
enhancement of an existing endpoint's payload.

### 11. User Workflow
An admin (or permitted staff member) recording a payment now leaves a real,
queryable transaction record behind, including the exact amount charged at
that moment and (for the manual gateway) a synthetic reference string. A
failed payment is now also a permanent row, not just a status flip.

### 12. Tests Added
- Extended `test_admin_recorded_payment_confirms_appointment_and_notifies_both_sides`
  with an audit-trail assertion.
- New `test_payment_status_endpoint_includes_the_transaction_audit_trail`.
- New `test_failed_payment_also_leaves_an_audit_trail_row`.

### 13. Test Commands
```
php artisan test --filter=AppointmentPaymentFlowTest
php artisan test
```

### 14. Test Results
All tests in the file passed. One self-introduced bug was caught and fixed
during this slice: the new audit-trail test initially hit
`GET /api/payments/{appointment}/status`, a path that does not exist;
corrected to `GET /api/payments/{appointment}` (no `/status` suffix) after
a 404 failure.

### 15. Not Tested
No dedicated frontend payment-history UI exists to connect or test.
Real gateway integration (Stripe/Paymob/etc.) does not exist — only the
manual, zero-I/O gateway is bound; this is accurately reflected in the
`ManualPaymentGateway` docblock, not hidden.

### 16. Environment Blockers
The new migration has not been applied to the real device's MySQL database
— `php artisan migrate` fails from this sandbox
(`SQLSTATE[HY000] [2002] Connection refused`, no MySQL reachable). Exact
manual action required: on the real device, run `php artisan migrate` from
`D:\cv\project-riva\rivatest-main\backend`.

### 17. Regression Check
Full suite passed at commit time.

### 18. Git
Commit `738f76e` — `feat(payments): add transaction-record audit trail +
gateway abstraction boundary`. Synced to the real device and verified via
`device_list_dir`.

### 19. Current System State
Backend implemented and feature tests pass (against sqlite). Migration not
yet applied to the real device's MySQL database. No frontend consumes the
new audit-trail data yet.

### 20. Next Slice
Proceeded to Slice 3 (RBAC / staff permissions).

---

## Slice 3 — RBAC / Staff Permission Model

### 1. Objective
Clinic membership (`clinic_user`) previously distinguished only
owner/admin/doctor/staff by role, with no finer-grained control over what a
`staff` (reception/secretary) member could actually do. This slice adds an
explicit, backend-enforced permission list for staff members — deliberately
excluding anything medical, so a secretary can be granted every operational
permission that exists without that ever implying medical-data access.
Medical authorization remains governed exclusively by
`PatientDoctorRelationship`/`PatientCaregiverRelationship`, architecturally
separate from this permission system.

### 2. Before State
`clinic_user` had `role` and `status` only. There was no way to grant a
`staff` member any specific capability short of promoting them to
`admin` (which would have also granted full membership-management rights,
an unwanted escalation).

### 3. Files Inspected
- `app/Models/ClinicUser.php`
- `app/Policies/ClinicPolicy.php`
- `app/Http/Controllers/ClinicMembershipController.php`
- `routes/api.php`

### 4. Files Created
- `database/migrations/2026_08_23_000008_add_permissions_to_clinic_user_table.php`
- `tests/Feature/ClinicStaffPermissionsTest.php`

### 5. Files Modified
- `app/Models/ClinicUser.php` — added the 5 permission constants and
  `ALL_PERMISSIONS`; added `permissions` to `$fillable` and cast as
  `array`; added `hasPermission(string $permission): bool` (owner/admin
  always true, doctor membership never gated by this list, only `staff` is
  actually checked against the stored array, suspended members always
  false).
- `app/Policies/ClinicPolicy.php` — added `updateMemberStatus()` (delegates
  to the existing `removeMember()` last-owner-protection logic) and
  `updateMemberPermissions()`.
- `app/Http/Controllers/ClinicMembershipController.php` — added
  `updateStatus()` and `updatePermissions()` actions; `serializeMember()`
  now includes `permissions`.
- `routes/api.php` — added
  `PATCH clinic/members/{member}/status` and
  `PATCH clinic/members/{member}/permissions` inside the existing
  `clinic.context` route group.

### 6. Database Changes
New nullable `json` column `permissions` on `clinic_user`, added guarded by
`Schema::hasColumn()` so it is safe to re-run. Same MySQL-application
caveat as Slice 2 — validated only via sqlite `RefreshDatabase`, not yet
applied to the real device.

### 7. Backend Business Logic
1. `hasPermission()` short-circuits to `false` for any inactive
   (suspended/invited, not `active`) membership regardless of role.
2. Owner and admin always return `true` for every permission check — the
   permission list only meaningfully restricts `staff`.
3. A `doctor`-role membership is explicitly excluded from ever passing a
   permission check via this path — a doctor's own capabilities come from
   being the doctor (elsewhere in the codebase), never from this list.
4. `updatePermissions()` validates every submitted value against
   `ClinicUser::ALL_PERMISSIONS` — an unknown permission string is rejected
   with 422 before ever reaching the database.
5. `updateStatus()` reuses the exact last-active-owner protection that
   `removeMember()` already enforced, so suspending is never a backdoor
   around that protection.

### 8. Authorization / Security
- CORRECT USER: an active owner/admin granting/revoking permissions on a
  staff member of their own clinic.
- WRONG USER / UNAUTHORIZED ROLE: a non-manager staff member attempting to
  grant themselves permissions is rejected (403) —
  `test_non_manager_staff_member_cannot_grant_themselves_permissions`.
- WRONG CLINIC: a manager of clinic A cannot set permissions of a clinic B
  member — `test_manager_of_clinic_a_cannot_set_permissions_of_a_clinic_b_member`
  (404, per `assertMemberBelongsToClinic()`).
- MANIPULATED ID: same cross-clinic 404 protection applies to a
  clinic_user id from a different clinic.
- INVALID INPUT: an unknown permission string is rejected with 422 —
  `test_unknown_permission_string_is_rejected`.
- Additional coverage: last active owner cannot be suspended; a suspended
  member immediately loses all effective permissions even if the stored
  array is untouched; owner/admin have full access regardless of the stored
  list; a doctor-role membership never gets staff permissions even if the
  column is populated.

### 9. API Changes
| Method | Path | Auth | Request | Response | Errors |
|---|---|---|---|---|---|
| PATCH | `/api/clinic/members/{member}/status` | Bearer, manager role | `{status: 'active'\|'suspended'}` | 200, serialized member | 403 non-manager, 403 last-owner, 404 cross-clinic |
| PATCH | `/api/clinic/members/{member}/permissions` | Bearer, manager role | `{permissions: string[]}` (values ⊆ `ALL_PERMISSIONS`) | 200, serialized member | 403 non-manager, 404 cross-clinic, 422 unknown permission |

### 10. Frontend Changes
None this slice — this was deliberately backend-only, becoming the
lowest-risk, already-fully-tested foundation for the Angular screen built in
Slice 4.

### 11. User Workflow
A clinic owner/admin can now grant a receptionist exactly the operational
capabilities they need (e.g. `patients.register`, `queue.manage`) without
granting anything medical, and can suspend/reactivate a staff member without
losing their invite/join history.

### 12. Tests Added
`tests/Feature/ClinicStaffPermissionsTest.php`, 9 tests: grant/revoke,
unknown-permission-rejected, non-manager-self-grant-denied,
cross-clinic-member-rejected, suspend/reactivate, last-owner-cannot-be-
suspended, suspended-member-loses-permissions,
owner/admin-always-full-access, doctor-membership-never-gets-staff-
permissions.

### 13. Test Commands
```
php artisan test --filter=ClinicStaffPermissionsTest
php artisan test
```

### 14. Test Results
All 9 tests passed on first run.

### 15. Not Tested
No frontend UI existed yet to exercise this at the time of this slice (built
in Slice 4). Not applied to the real device's MySQL database.

### 16. Environment Blockers
Same MySQL-unreachable-from-sandbox blocker as Slice 2; migration not yet
applied to the real device.

### 17. Regression Check
Full suite passed at commit time.

### 18. Git
Commit `eb8f2eb` — `feat(rbac): add permission-based staff/secretary access
+ member suspend`. Synced to the real device and verified via
`device_list_dir`.

### 19. Current System State
Backend implemented and feature tests pass (against sqlite). Migration not
yet applied to the real device's MySQL database. Frontend not connected at
the time this slice closed (closed in Slice 4).

### 20. Next Slice
Investigated the Angular frontend for the first time this session in order
to build the intended vertical slice (a Clinic Staff Management screen
consuming this backend) — which surfaced the clinic auto-provisioning bug
documented in Slice 4.

---

## Slice 4 — Clinic Auto-Provisioning Fix + Clinic Team Angular Screen

### 1. Objective
This slice is the direct response to the standing "do not over-focus on
backend" instruction: Slices 1–3 were backend-only. The actual deliverable
here is a real, connected Angular screen — "Clinic Team" — letting a doctor
manage their clinic's staff using the Slice 3 backend. While building it, a
real, previously-undiscovered production bug was found and is fixed as part
of this same slice rather than deferred, per the standing "don't fear
discovering new bugs mid-slice, fix them" instruction: the one-time
"clinic of one" backfill migration
(`2026_08_23_000006_backfill_clinic_of_one_for_doctors.php`) only ever
provisioned a solo clinic for doctors that already existed at the moment it
ran. Any doctor registering afterward gets no clinic at all and is
permanently rejected (403 "You are not an active member of any clinic.") by
`EnsureClinicContext` on every clinic-scoped route — including the very
screen this slice was built to deliver. Without this fix, the Clinic Team
screen would be unreachable for every doctor who signs up from now on.

### 2. Before State
`ProfileController::saveProfile()` created a `Doctor` row for a first-time
doctor profile completion, but nothing provisioned a clinic. No Angular
service, component, or route existed for clinic staff management at all.

### 3. Files Inspected
- `app/Http/Controllers/ProfileController.php`
- `database/migrations/2026_08_23_000006_backfill_clinic_of_one_for_doctors.php`
  (read in full to replicate its exact naming/idempotency logic)
- `app/Http/Controllers/ClinicMembershipController.php`
- `app/Models/ClinicUser.php`
- `app/Http/Controllers/Controller.php` (response envelope: `{success,
  message, data}` / `{success, message, errors}`)
- `app/Http/Middleware/EnsureClinicContext.php`
- `app/Services/Tenancy/ClinicContextService.php` (clinic-resolution rules,
  including the 409 multi-clinic-membership case)
- Frontend: `app.routes.ts`, `auth.service.ts`, `auth.interceptor.ts`,
  `auth-guard.ts`, `components/sidebar.ts`,
  `notifications.component.ts`/`.html` (read as a styling/structure
  reference), `doctors-follow-request.component.ts` (read, rejected as a
  template — it is a payment/checkout form despite its name)
- Full recursive directory listing of `Riva-master/src/app` (from earlier in
  this session, before compaction)

### 4. Files Created
- `app/Services/Tenancy/ClinicProvisioningService.php` — backend. Ongoing
  equivalent of the one-time backfill migration:
  `ensureSoloClinicForDoctor(User $user, int $doctorId)` skips if the user
  is already a member of any clinic (identical idempotency rule to the
  migration), otherwise creates a `Clinic` named `"{name}'s Practice"` and a
  `ClinicUser` row with `role: owner, status: active`.
- `tests/Feature/DoctorClinicProvisioningTest.php` — backend, 4 tests
  (§12).
- `Riva-master/src/app/service/clinic.service.ts` — Angular. Thin,
  fully-typed wrapper over the 6 real `ClinicMembershipController` routes
  (`listMembers`, `inviteMember`, `updateRole`, `updateStatus`,
  `updatePermissions`, `removeMember`), plus the `CLINIC_PERMISSIONS`
  label list matching `ClinicUser::ALL_PERMISSIONS` exactly and the
  `ClinicMember`/`ClinicApiEnvelope` types matching
  `serializeMember()`'s real field names.
- `Riva-master/src/app/core/components/auth/clinic-staff/clinic-staff.component.ts` —
  Angular, standalone component: the "Clinic Team" screen itself (§10–11).

### 5. Files Modified
- `app/Http/Controllers/ProfileController.php` — constructor now also
  injects `ClinicProvisioningService`; in `saveProfile()`, immediately
  before the existing `match ($user->role)` block, a new check
  (`if ($user->role === 'doctor' && !$user->doctor)`) creates the `Doctor`
  row (same as before) and now additionally calls
  `ensureSoloClinicForDoctor()`. Separately, `updateDoctorProfile()` was
  rewritten to fix an unrelated but adjacent bug found while writing the new
  test (see "Errors and fixes" below) — every `$data['key']` array access
  changed to `$data['key'] ?? null`.
- `Riva-master/src/app/app.routes.ts` — added one new route:
  `{ path: 'clinic-staff', loadComponent: () => import(...).then(m =>
  m.ClinicStaffComponent), canActivate: [AuthGuard], data: { roles:
  ['doctor'] } }`, placed beside the other dashboard routes.
- `Riva-master/src/app/components/sidebar.ts` — added one entry to the
  `doctor` role's menu array: `{ icon: 'fa-solid fa-users', route:
  '/clinic-staff', title: 'Clinic Team' }`. Without this, the route would
  have existed but been undiscoverable — a real navigation entry point was
  treated as part of "connected," not optional polish.

### 6. Database Changes
No new migration. `ClinicProvisioningService` writes through the existing
`clinics`/`clinic_user` tables established in Phase 1.

### 7. Backend Business Logic
1. On a doctor's first profile save (`Doctor` row does not yet exist for
   this user), a `Doctor` row is created exactly as before.
2. Immediately after, `ensureSoloClinicForDoctor()` checks whether this user
   is already a member of *any* clinic (covers the case of a doctor invited
   as staff into someone else's clinic before ever completing their own
   profile — that case must NOT get a redundant solo clinic).
3. If not already a member of anything, a new `Clinic` (`"{name}'s
   Practice"`) and an `owner`/`active` `ClinicUser` row are created for
   them, identically shaped to what the old one-time migration would have
   produced.
4. A second profile save for the same doctor does not re-run this logic —
   `$user->doctor` already exists by then, so the entire block is skipped.

### 8. Authorization / Security
- CORRECT USER: a doctor completing their own profile provisions their own
  clinic; verified end to end via the newly-provisioned doctor immediately
  calling `GET /api/clinic/members` successfully
  (`test_newly_provisioned_doctor_can_immediately_use_clinic_scoped_routes`).
- WRONG USER / MANIPULATED ID: not applicable — this logic has no
  client-supplied clinic or user identifier; it always acts on
  `$request->user()`.
- WRONG RELATIONSHIP: a doctor already invited into someone else's clinic
  (as `staff`, `status: invited`) does not get a second, redundant solo
  clinic — `test_doctor_already_invited_into_another_clinic_does_not_get_a_redundant_solo_clinic`.
  This preserves the exact idempotency guarantee the original migration
  had.
- INVALID INPUT: covered by the separate `updateDoctorProfile()` fix below
  — a profile-completion request omitting optional fields no longer 500s.

### 9. API Changes
No new routes. `POST /api/profile/complete` and `POST /api/profile` (doctor
role) now have a side effect (clinic provisioning) they did not have
before; the response shape is unchanged.

### 10. Frontend Changes
- **New service** `clinic.service.ts` — CONNECTED TO REAL API. Every method
  maps 1:1 to a real, already-tested backend route; field names copied
  directly from `ClinicMembershipController::serializeMember()`, not
  guessed.
- **New component** `ClinicStaffComponent` (`/clinic-staff`, doctor-only
  route) — CONNECTED TO REAL API. On init, calls `listMembers()` and
  renders: a loading spinner; an explicit error state surfacing the real
  backend error message (including the honest, unhandled case of a 409
  "you belong to multiple clinics" response — the frontend does not attempt
  to fabricate a clinic picker for that case, since no "list my clinics"
  endpoint exists yet; it just shows the real error text); an explicit
  empty state; and, for the normal case, a responsive card grid (1 column
  on mobile, 2 on `lg:` and up — no wide table that would overflow on a
  phone) of real member data. A manager (owner/admin, determined by finding
  the caller's own row in the real response, not assumed) sees an invite
  form and per-member role/status/remove controls; a `staff`-role member's
  card includes a permission checklist matching `CLINIC_PERMISSIONS`
  exactly, with change-detection (`hasPermissionDraftChanged`) so "Save
  permissions" is disabled until something is actually different. Every
  action (invite, role change, suspend/reactivate, remove, permission save)
  calls the real endpoint, disables that row while in flight, and surfaces
  the real backend error message on failure rather than assuming success.
- **New route** in `app.routes.ts`: `/clinic-staff`, `AuthGuard`-protected,
  `data: { roles: ['doctor'] }` — consistent with every other role-gated
  route in the file.
- **New nav entry** in `components/sidebar.ts`'s doctor menu — makes the
  screen actually reachable, not just a URL nobody would type.

### 11. User Workflow
A doctor signs in, sees "Clinic Team" in their sidebar, opens it, and sees
their own auto-provisioned solo clinic with themselves listed as owner. They
can type a teammate's email, choose a role, and send a real invite (subject
to the real backend rule that only an owner may grant the owner role, and
that the invited email must already belong to a Riva account — the backend
returns a real error otherwise, which the UI displays). For an existing
`staff` member, they can check/uncheck specific operational permissions and
save them; the backend enforces that only the 5 real permission values are
ever accepted. They can suspend or reactivate a member, or remove one
outright — the backend's last-active-owner protection is not duplicated in
the frontend; if it fires, the real 403 message is shown in that member's
card.

### 12. Tests Added
`tests/Feature/DoctorClinicProvisioningTest.php`, 4 tests:
- `test_completing_profile_as_a_new_doctor_provisions_a_solo_clinic`
- `test_completing_profile_a_second_time_does_not_create_a_duplicate_clinic`
- `test_doctor_already_invited_into_another_clinic_does_not_get_a_redundant_solo_clinic`
- `test_newly_provisioned_doctor_can_immediately_use_clinic_scoped_routes`

No Angular tests were added — this codebase has no established Angular unit
test harness exercised this session (see §15).

### 13. Test Commands
```
php artisan test --filter=DoctorClinicProvisioningTest
php -l app/Services/Tenancy/ClinicProvisioningService.php
php -l app/Http/Controllers/ProfileController.php
php -l tests/Feature/DoctorClinicProvisioningTest.php
php artisan test
php artisan route:list --path=api
```

### 14. Test Results
- First run of the new test file: **4 failures**, 3 of them
  `Expected response status code [200] but received 500` on
  `POST /api/profile/complete`.
- Root cause (see "Errors and fixes"): `updateDoctorProfile()` threw
  `Undefined array key "experience_years"` — confirmed by reading
  `storage/logs/laravel.log` after clearing it and re-running the single
  failing test.
- After the fix (`$data['key'] ?? null` throughout `updateDoctorProfile()`):
  **all 4 tests passed**, 19 assertions.
- `php -l` clean on all 3 touched/new backend files.
- Full suite: **135 passed, 408 assertions**, 0 failures — includes
  `RegisterProfileDashboardTest` (patient profile completion, unaffected by
  the constructor-injection change) and every prior slice's tests.
- `php artisan route:list --path=api` confirms all 6
  `clinic/members*` routes and both `profile`/`profile/complete` routes are
  registered exactly as expected.

### 15. Not Tested
- Angular build/typecheck/lint (`npm install`, `ng build`, `ng lint`) has
  not been run in any environment this session — `device_bash` on the real
  device reports the isolated Linux workspace failed to start, and no full
  local mirror of the Angular project exists in this sandbox to build
  against. This is a genuine, standing gap, not something quietly assumed
  to be fine.
- No browser-level verification (opening `/clinic-staff` in an actual
  browser, clicking through invite/suspend/permission flows) was performed.
- The 409 "multiple clinic memberships" case is handled honestly in the UI
  (real error text shown) but has no dedicated test or clinic-picker UI —
  correctly out of scope for this slice, not silently ignored.
- `php artisan migrate` has still not been run against the real device's
  MySQL database this session for any prior slice's migrations either (see
  Slices 2–3).

### 16. Environment Blockers
- `device_bash` unavailable on the real device (`"Workspace unavailable.
  The isolated Linux environment on this device failed to start."`) —
  blocks any live Angular build or `php artisan serve` verification.
  Exact manual action: on the real device, from
  `D:\cv\project-riva\rivatest-main\Riva-master`, run `npm install` then
  `ng build` (or `ng serve`) to verify the new component compiles; from
  `D:\cv\project-riva\rivatest-main\backend`, run `php artisan migrate` to
  apply the Slice 2/3 migrations (`payments` table, `clinic_user.permissions`
  column) that remain unapplied to the real MySQL database.
- `.env`'s `APP_URL` fix (flagged in an earlier slice this session:
  `APP_URL=http://https://riva-healthcare-tm.gamer.gd` should be
  `APP_URL=http://127.0.0.1:8000`) remains unconfirmed as done —
  `device_commit_files` hard-refuses to write `.env` files
  (`"Writing to .env is not permitted via remote tools."`), an unbypassable
  safety guard. Exact manual action: edit
  `D:\cv\project-riva\rivatest-main\backend\.env` line 6 directly, then run
  `php artisan optimize:clear`.

### 17. Regression Check
Full backend suite (135 tests, 408 assertions) passed after this slice's
changes, including every test file from Slices 1–3 and the pre-existing
`RegisterProfileDashboardTest` (patient profile completion — verifies the
`ProfileController` constructor signature change did not break the
non-doctor path). `route:list` confirms no route was removed or renamed.
No frontend regression test exists to run; the new route/component/nav
entry are additive (new route path, new sidebar entry) and do not modify
any existing route, component, or service.

### 18. Git
Backend commit `d46a533` — `Fix: provision solo clinic for doctors who
register after the one-time backfill` (files: `ProfileController.php`
modified, `ClinicProvisioningService.php` and
`DoctorClinicProvisioningTest.php` new). Synced to the real device and
verified via `device_list_dir` (exact byte-size match:
`ProfileController.php` 11737 bytes, `ClinicProvisioningService.php` 2285
bytes, `DoctorClinicProvisioningTest.php` 4713 bytes, matching the sandbox
originals exactly).

Frontend has no git repository on the real device or in this sandbox (only
a partial, stale local mirror existed in `/tmp/work`, not used as a source
of truth — the real device's current file content, freshly staged before
each edit, was used instead). The 4 changed/new frontend files
(`clinic.service.ts`, `clinic-staff.component.ts`, `app.routes.ts`,
`sidebar.ts`) were synced to the real device using the same
stage-diff-write-verify discipline as the backend: fresh-staged immediately
before editing, edited, sent, committed with an `expectedMtimeMs` guard on
the two modified files, and re-verified via `device_list_dir` afterward
(all 4 show updated mtimes and expected byte sizes on the device).

### 19. Current System State
- Backend: implemented and feature tests pass (135/135, including this
  slice's 4 new tests). Not yet applied to the real device's MySQL database
  (no new migration in this slice, but Slices 2–3's migrations remain
  pending there).
- Frontend: implemented and connected to the real backend API. Not
  build-verified (no `ng build` run this session in any environment).
- Authorization: real (delegates entirely to the already-tested
  `ClinicMembershipController`/`ClinicPolicy` backend; no authorization
  logic duplicated or reimplemented in the frontend).
- Responsive: implemented for mobile (1-column stacked cards) through
  desktop (2-column grid); not manually verified in a real viewport this
  session.
- This is the first slice this session where PRODUCT COMPLETION RULE's
  "Frontend: CONNECTED, UI: USABLE, Responsive: IMPLEMENTED" bars are met
  for a real screen, alongside the backend/API/authorization bars carried
  over from Slice 3 — the remaining gap against full IMPLEMENTED status is
  build/browser verification only (§15–16), not missing functionality.

### 20. Next Slice
The Riva product surface (Phases C–S per the standing product directive)
remains almost entirely unbuilt beyond what Slices 1–4 cover. The next
concrete, connected vertical slice should be Queue/Appointment management
for the same clinic-staff persona just built (a secretary using their
granted `queue.manage`/`appointments.manage` permissions from Slice 3 to do
real work) — continuing the same clinic identity this slice established
rather than starting an unrelated phase. Concretely: inspect whether a
`Queue` model/migration exists yet (flagged NOT IMPLEMENTED in the earlier
handoff report); if not, build it as a full vertical slice (migration +
model + controller + policy + tests + Angular service + component + route +
nav entry) rather than backend-only, per this same directive.

---

## Slice 5 — Queue Operational Workflow + Clinic-Wide Frontend Access Fix

### 1. Objective
Direct continuation of Slice 4's own "next slice" plan and the master
autonomous-implementation directive's priority order (Queue + Appointment
operational workflow first). Before writing anything, the existing
`Appointment` domain was inspected in full — no `Queue` model, migration, or
controller existed anywhere (confirmed by grep across `app/`, `database/`,
`routes/`); appointments reference `hospital_id`, not `clinic_id`, and
`CrossClinicResourceIsolationTest`'s own docblock documents, as a deliberate
prior architectural decision, that appointment/report/medication
authorization is relationship-based (`PatientDoctorRelationship`), not
clinic-membership-based — clinic co-membership must never be treated as
sufficient authorization for medical data. Queue management is a different,
genuinely clinic-scoped operational concern (a receptionist needs to see
and advance every doctor's patients for the day), so it is built as its own
controller rather than folded into the relationship-scoped
`AppointmentController`, exposing only operational fields (name, time,
status) — never diagnoses, prescriptions, or other medical content.

While inspecting `AppointmentController::pay()`/`failPayment()` to
understand the existing payment-recording authorization, a real,
previously-undiscovered bug was found: `canRecordPayment()` hardcoded
`role === 'admin'` only, completely ignoring the
`ClinicUser::PERMISSION_RECORD_PAYMENTS` permission Slice 3 already built —
a staff member granted that permission still could not record a single
payment, because nothing anywhere ever checked it. Fixed immediately in
this same slice per the standing "fix it now" rule, since it directly
blocks the "secretary... payment recording where explicitly permitted"
workflow this same master directive requires.

A second gap was found while wiring the frontend: `/clinic-staff` (Slice 4)
was gated to platform role `doctor` only, and the sidebar's clinic-staff nav
entry only ever appeared for that role. But a clinic "secretary" has no
dedicated platform role in this system at all — they are whatever platform
role they registered as (patient/doctor/caregiver) plus a `clinic_user`
membership added later. That gate would have made both the existing Clinic
Team screen and the new Queue screen permanently unreachable for the exact
persona they exist to serve. Fixed in this same slice (see §10).

### 2. Before State
No queue concept existed at all. `AppointmentController::index()` returned
nothing for any role other than admin/patient/doctor/caregiver-with-a-
relationship — a clinic staff member calling it got an empty, hard-coded
`whereRaw('1 = 0')` result (by design, for medical-data safety — this
slice does not change that; queue visibility is a separate, new,
operationally-scoped endpoint). `canRecordPayment()` was admin-only despite
Slice 3's RBAC permission existing. `/clinic-staff` was reachable only by
platform role `doctor`.

### 3. Files Inspected
- `app/Models/Appointment.php`, its migration inside
  `2026_04_26_000100_create_riva_domain_tables.php` (status/payment_status
  enum values, `hospital_id` vs `clinic_id`)
- `app/Http/Controllers/AppointmentController.php` (full read — index,
  store, update, confirm/cancel/complete, pay/failPayment,
  `authorizeAppointment()`, `canRecordPayment()`)
- `app/Models/User.php`, `app/Models/Clinic.php` (`clinicMemberships()`,
  `members()` relations, to confirm how to resolve "doctors of this
  clinic")
- `tests/Feature/CrossClinicResourceIsolationTest.php` in full, specifically
  its docblock explaining the deliberate relationship-based (not
  clinic-based) authorization model for medical resources
- `app/Http/Controllers/ClinicMembershipController.php`,
  `app/Http/Controllers/Controller.php` (response envelope reuse)
- `routes/api.php` (existing `clinic.context` group and outer
  `auth:sanctum` group structure, to place the new `my-context` probe
  correctly outside `clinic.context`)
- Frontend: re-read `app.routes.ts` and `components/sidebar.ts` (freshly
  staged from the device to confirm zero drift before editing again)

### 4. Files Created
- `database/migrations/2026_08_23_000009_add_queue_fields_to_appointments_table.php`
- `app/Http/Controllers/QueueController.php`
- `tests/Feature/QueueManagementTest.php` — 13 tests (§12)
- `Riva-master/src/app/service/queue.service.ts`
- `Riva-master/src/app/core/components/auth/clinic-queue/clinic-queue.component.ts`

### 5. Files Modified
- `app/Models/Appointment.php` — added the 5 new queue columns to
  `$fillable` and cast the 3 new timestamp columns as `datetime`.
- `app/Http/Controllers/QueueController.php` — n/a (created, not modified).
- `app/Http/Controllers/AppointmentController.php` — `canRecordPayment()`
  rewritten (see §1/§8) to check platform admin OR an active clinic
  membership shared with the appointment's doctor carrying
  `payments.record`; `pay()`/`failPayment()` updated to pass `$appointment`
  into the new signature and to use an updated, more accurate error
  message (no longer literally "Only an administrator...").
- `app/Http/Controllers/ClinicMembershipController.php` — constructor now
  also injects `ClinicContextService`; added `myContext()` (§9).
- `routes/api.php` — added `GET /clinic/my-context` (outside
  `clinic.context`) and the 8 `clinic/queue*` routes (inside
  `clinic.context`, alongside the existing membership routes).
- `tests/Feature/AppointmentPaymentFlowTest.php` — updated the stale
  admin-only error-message assertion; added 3 new tests (§12); updated the
  class docblock to reflect that the admin-only restriction is superseded.
- `Riva-master/src/app/service/clinic.service.ts` — added `ClinicContext`/
  `ClinicContextEntry` types and `myContext()`.
- `Riva-master/src/app/app.routes.ts` — widened `/clinic-staff`'s
  `data.roles` from `['doctor']` to `['doctor', 'patient', 'caregiver']`;
  added `/clinic-queue` with the same widened gating.
- `Riva-master/src/app/components/sidebar.ts` — added a static "Queue" item
  to the doctor menu (beside the existing "Clinic Team" item); added
  `CLINIC_STAFF_ITEMS` and async `ngOnInit()` logic that appends both items
  to a patient/caregiver's menu only when `ClinicService.myContext()`
  confirms real clinic access — never shown speculatively.

### 6. Database Changes
New nullable columns on `appointments`: `queue_status` (enum: waiting,
called, in_service, completed, no_show, skipped), `queue_position`
(unsigned int), `checked_in_at`/`called_at`/`queue_completed_at`
(timestamps), plus a composite index `[doctor_id, appointment_date,
queue_status]`. Deliberately modeled as fields on the existing `Appointment`
record rather than a new table — see the migration's own docblock for why
(exactly one queue entry per appointment, on the day it's served; a
separate table would only add join overhead for no modeling benefit and
risk becoming a second, parallel appointment concept). Validated only
against sqlite via `RefreshDatabase`; not yet applied to the real device's
MySQL database (same standing blocker as Slices 2–3).

### 7. Backend Business Logic
1. `QueueController::index()` resolves the current clinic's active member
   user_ids, finds today's non-cancelled appointments for doctors among
   them, and returns them sorted by queue urgency (in_service, called,
   waiting, not-yet-checked-in, then everything else) then position then
   time.
2. `checkIn()` requires: appointment not cancelled, `appointment_date`
   equals today, and `queue_status` not already active/completed. Assigns
   the next `queue_position` (max existing position across the clinic's
   doctors for today, plus one) and stamps `checked_in_at`.
3. `call()`/`start()`/`complete()`/`noShow()`/`skip()` each require the
   appointment to currently be in one of a specific set of prior
   `queue_status` values (enforced by `requireQueueStatus()`) before
   transitioning — an out-of-order action (e.g. completing an appointment
   that was never checked in) is rejected with 422, not silently accepted.
4. `complete()` additionally sets the existing `appointments.status` column
   to `'completed'` — reusing the existing lifecycle field rather than
   inventing a second "done" concept.
5. `remove()` clears all queue fields back to null from any state, as an
   explicit undo/escape hatch; it does not revert `appointments.status` if
   `complete()` had already changed it — documented as a known limitation,
   not silently glossed over.
6. Every manage action first calls `assertAppointmentBelongsToClinic()` —
   the appointment's doctor must be an active member of the resolved
   current clinic, or the request 404s (matching `ClinicMembershipController`'s
   existing cross-clinic pattern) — before the permission check even runs.
7. `AppointmentController::canRecordPayment()` now finds every clinic the
   appointment's doctor actively belongs to, finds the calling user's
   active membership in any of those same clinics, and delegates to that
   membership's `hasPermission(PERMISSION_RECORD_PAYMENTS)` — owner/admin
   always pass, a staff member only if explicitly granted, a platform admin
   always passes regardless of clinic membership.
8. `ClinicMembershipController::myContext()` returns `{has_clinic: false}`
   for a user with zero active clinic memberships (the common case),
   or `{has_clinic: true, ambiguous: <count > 1>, clinics: [...]}`
   otherwise — never aborts, unlike every other clinic route.

### 8. Authorization / Security
- CORRECT USER: an owner/admin/permitted-staff member of the SAME clinic as
  the appointment's doctor can view and advance the queue; view is open to
  every active member (including a plain doctor-role member) so a doctor
  can see today's board without needing an explicit grant.
- WRONG USER / UNAUTHORIZED ROLE: `test_plain_doctor_member_can_view_but_not_manage_the_queue`
  — a `ClinicUser::ROLE_DOCTOR` membership can call `index()` but gets 403
  on every manage action, matching `hasPermission()`'s existing rule that a
  plain doctor membership never passes any permission check.
  `test_staff_member_without_queue_manage_permission_is_denied` — a staff
  member granted a different permission (`patients.register`) is still
  denied `queue.manage` actions.
- WRONG CLINIC: `test_manager_of_clinic_a_cannot_manage_clinic_b_appointment_queue`
  — an owner of clinic A gets 404 (not 403) attempting to check in clinic
  B's appointment, matching the existing membership-controller convention
  of not even acknowledging the resource exists across the boundary.
- MANIPULATED ID: covered by the same clinic-membership assertion — an
  appointment ID from another clinic is rejected regardless of the caller's
  own role.
- INVALID INPUT / STATE: `test_future_dated_appointment_cannot_be_checked_in`,
  `test_cancelled_appointment_cannot_be_checked_in`,
  `test_checking_in_the_same_appointment_twice_is_rejected`,
  `test_completing_a_visit_before_check_in_is_rejected` — every queue
  state-machine edge case is enforced server-side, not merely assumed from
  UI button visibility.
- Payment permission fix: `test_clinic_owner_can_record_payment_for_their_own_clinic_doctor`,
  `test_staff_member_with_payments_record_permission_can_record_payment`,
  `test_staff_member_without_payments_record_permission_cannot_record_payment`
  — plus the pre-existing `test_doctor_cannot_declare_payment_either` (an
  unaffiliated doctor, no clinic at all) and
  `test_admin_of_clinic_a_cannot_use_payment_endpoints_...` (clinic A's
  owner cannot pay for clinic B's appointment) from
  `CrossClinicResourceIsolationTest`, both re-verified to still pass
  unchanged against the new `canRecordPayment()` logic.

### 9. API Changes
| Method | Path | Auth | Role | Response | Errors |
|---|---|---|---|---|---|
| GET | `/api/clinic/my-context` | Bearer | any authenticated | `{has_clinic, ambiguous?, clinics?}` | never aborts |
| GET | `/api/clinic/queue` | Bearer, active clinic member | any | today's queue entries | 403 no clinic, 409 ambiguous multi-clinic |
| POST | `/api/clinic/queue/{appointment}/check-in` | Bearer, `queue.manage` | owner/admin/permitted staff | updated entry | 404 cross-clinic, 422 not-today/cancelled/already-in-queue, 403 no permission |
| POST | `/api/clinic/queue/{appointment}/call` | same | same | updated entry | 422 wrong prior state |
| POST | `/api/clinic/queue/{appointment}/start` | same | same | updated entry | 422 wrong prior state |
| POST | `/api/clinic/queue/{appointment}/complete` | same | same | updated entry (+ appointment status = completed) | 422 wrong prior state |
| POST | `/api/clinic/queue/{appointment}/no-show` | same | same | updated entry | 422 wrong prior state |
| POST | `/api/clinic/queue/{appointment}/skip` | same | same | updated entry | 422 wrong prior state |
| POST | `/api/clinic/queue/{appointment}/remove` | same | same | updated entry | 409 not in queue |

`POST /api/payments/{appointment}/pay` and `.../fail`: same request/response
shape as before; authorization now also accepts a permitted clinic
member, not only a platform admin; the 403 message text changed (see §5).

### 10. Frontend Changes
- **New service** `queue.service.ts` — CONNECTED TO REAL API, field names
  and action names matching `QueueController::serialize()` and its routes
  exactly.
- **New component** `ClinicQueueComponent` (`/clinic-queue`) — CONNECTED TO
  REAL API. Loading/error/empty states; a responsive card grid (1 column
  mobile, up to 3 on `xl:`); every state-machine button is conditionally
  shown based on the entry's real `queue_status` (matching the backend's
  own `requireQueueStatus()` rules, so a user is never shown a button that
  would 422); a `canManageQueue` flag derived from
  `ClinicService.myContext()` hides manage buttons for a view-only member
  as a UX convenience — the real gate is still 100% backend-enforced (a
  hidden button is not the security boundary; a failed action still shows
  the real backend error per row).
- **Clinic access fix**: `/clinic-staff`'s route guard widened from
  `['doctor']` to `['doctor', 'patient', 'caregiver']`; new `/clinic-queue`
  route uses the same widened gating. The sidebar now asynchronously probes
  `myContext()` for any non-doctor, non-admin platform role and appends
  "Clinic Team"/"Queue" nav items only when real clinic access is
  confirmed — the doctor menu keeps its existing static entries
  unconditionally (a doctor always has at least their own auto-provisioned
  solo clinic, so no probe is needed there).

### 11. User Workflow
A receptionist — regardless of their own platform account type — who has
been invited as clinic staff with `queue.manage` now sees "Queue" appear in
their sidebar automatically, opens it, sees today's appointments across
every doctor in the clinic, and can check patients in, call them, start
their visit, mark it complete (which also closes out the underlying
appointment), or record a no-show/skip. A clinic owner or a staff member
with `payments.record` can now record a payment themselves through the
existing payment endpoint without needing a platform admin to do it for
them — closing the gap the Phase 0 security changelog had explicitly
flagged as blocked on "Phase 2 clinic staff... landing."

### 12. Tests Added
`tests/Feature/QueueManagementTest.php`, 13 tests: owner full happy-path
(check-in → call → start → complete), staff-with-permission check-in,
staff-without-permission denied, plain-doctor-member view-but-not-manage,
cross-clinic 404, future-dated rejected, cancelled rejected, double-check-in
rejected, complete-before-check-in rejected, no-show/skip transitions
(including re-check-in after skip), remove clears state, my-context for a
patient with no clinic, my-context for an owner.

`tests/Feature/AppointmentPaymentFlowTest.php`: updated 1 stale assertion,
added `test_clinic_owner_can_record_payment_for_their_own_clinic_doctor`,
`test_staff_member_with_payments_record_permission_can_record_payment`,
`test_staff_member_without_payments_record_permission_cannot_record_payment`.

No Angular tests were added — same standing gap as Slice 4 (no Angular unit
test harness exercised this session).

### 13. Test Commands
```
php artisan test --filter=QueueManagementTest
php artisan test --filter=AppointmentPaymentFlowTest
php -l <each changed/new PHP file>
php artisan test
php artisan route:list --path=api
```

### 14. Test Results
- `QueueManagementTest`: 13 passed, 41 assertions, first run — no failures
  to debug this slice.
- `AppointmentPaymentFlowTest`: 12 passed, 47 assertions (9 pre-existing +
  3 new), first run after updating the one stale message assertion.
- `php -l` clean on all 8 changed/new backend files.
- Full suite: **151 passed, 457 assertions**, 0 failures (up from 135/408
  before this slice) — no regressions.
- `route:list --path=api` confirms all 9 new routes
  (`clinic/my-context` + 8 `clinic/queue*`) registered exactly as intended.

### 15. Not Tested
- Angular build/typecheck (`npm install`, `ng build`) still not run in any
  environment this session — same standing gap as Slice 4, `device_bash`
  still unavailable on the real device.
- No browser-level verification of the Queue screen or the sidebar's
  dynamic clinic-nav behavior.
- The 409 "ambiguous multi-clinic" case for `GET /api/clinic/queue` has no
  dedicated test in this slice (covered generically by the pre-existing
  `ClinicTenantIsolationTest` for the membership endpoints, and the same
  `ClinicContextService` resolution logic is shared) — not re-proven
  specifically against the queue endpoint.
- `remove()`'s documented limitation (does not revert `appointments.status`
  after a `complete()`) has no explicit regression test.

### 16. Environment Blockers
Same two standing blockers as Slices 2–4: `php artisan migrate` not run
against the real device's MySQL database (this migration adds to the
pending list), and the `.env` `APP_URL` fix still unconfirmed. No new
blockers introduced by this slice.

### 17. Regression Check
Full suite (151 tests, 457 assertions) passed after this slice, including
every test file from Slices 1–4 unchanged in behavior. `route:list`
confirms no existing route was removed, renamed, or had its controller
action changed unexpectedly. The `canRecordPayment()` rewrite was
specifically re-verified against the two existing cross-clinic payment
tests in `CrossClinicResourceIsolationTest` (still pass, unchanged) to
confirm the widened permission model did not create a new cross-clinic
leak.

### 18. Git
Backend commit `e2b7918` — `feat(queue): add clinic-scoped Queue
operational workflow + fix payments.record RBAC gap`. Synced to the real
device using the stage-diff-write-verify discipline (5 modified files
confirmed byte-identical to the sandbox's pre-edit state before writing; 3
new files written directly); all 8 files re-verified via `device_list_dir`
size match after write.

Frontend: 5 files (2 new, 3 modified) synced the same way — `app.routes.ts`
and `sidebar.ts` re-staged fresh immediately before editing (confirmed
matching their Slice 4 post-sync sizes exactly, i.e. zero drift) and
written with an `expectedMtimeMs` guard; `clinic.service.ts` similarly
re-staged and diffed before its additive edit. All 5 re-verified via
`device_list_dir` size match after write. No frontend git repository
exists on the real device or in this sandbox (same as Slice 4).

### 19. Current System State
- Backend: implemented and feature tests pass (151/151, including this
  slice's 16 new/updated tests). Not yet applied to the real device's
  MySQL database.
- Frontend: implemented and connected to the real backend API. Not
  build-verified (no `ng build` run this session in any environment).
- Authorization: real, backend-enforced (queue.manage and payments.record
  both now genuinely gate real functionality, closing two gaps where the
  Slice 3 permission model existed but had no actual consumer).
- Responsive: implemented for mobile (1-column) through wide desktop
  (3-column); not manually verified in a real viewport.
- Cross-cutting fix: the Clinic Team screen from Slice 4, previously
  unreachable by any platform role other than 'doctor', is now reachable by
  the personas who actually need it, with the real backend permission
  check as the only actual security boundary (per "never rely only on
  frontend guards").

### 20. Next Slice
Per the master directive's priority order, Patient registration/search is
next, followed by Clinic services + prices (needed before a service can be
attached to an appointment/payment, which the current `Appointment.amount`
still derives solely from `Doctor::fee` — no per-service pricing exists
yet). Concretely: inspect the existing `Patient` model/controller and
`RelationshipController` for what already exists before building anything
new, then determine whether `patients.register` (an already-defined but
still-unconsumed Slice 3 permission, same gap pattern as `queue.manage` and
`payments.record` were before this slice) needs a similar real backend
consumer — a clinic staff member registering a walk-in patient — as the
next connected vertical slice.

---

## Slice 6 — Patient Registration / Search (Front Desk)

### 1. Objective
Direct continuation of Slice 5's own "next slice" plan: `patients.register`
was, until this slice, the last of the three originally-defined Slice 3
permissions with no real backend consumer at all (`queue.manage` and
`payments.record` were wired up in Slice 5). Before writing anything, the
existing `Patient` model and `RelationshipController` were inspected in
full: there is no `PatientController` anywhere in this codebase; "patient
discovery" today is entirely patient-initiated (a patient sends a follow
request to a doctor via `RelationshipController::sendDoctorFollowRequest()`)
— there is no doctor/staff-initiated "search for or register a patient"
flow of any kind. This slice adds exactly that, scoped narrowly per the
master directive's own "build the level actually specified" instruction:
it ensures a real, findable `Patient` record exists for a walk-in — it does
NOT create appointments or `PatientDoctorRelationship` rows, which remains
`AppointmentController::store()`'s existing job.

### 2. Before State
No clinic-facing patient search or registration endpoint existed. A
walk-in patient with no prior Riva account could only be onboarded via the
public `/api/auth/register` endpoint (self-service, requiring the patient
to already be present with a device and pick their own password) — there
was no front-desk-assisted path at all.

### 3. Files Inspected
- `app/Models/Patient.php` (full read — confirmed fillable columns; no
  `date_of_birth` column exists despite `ProfileController::updatePatientProfile()`
  validating one — a separate, pre-existing, minor dead-field discrepancy
  noted but deliberately left out of scope, since it does not block or
  relate to this slice)
- `app/Http/Controllers/RelationshipController.php` (full read — confirmed
  patient discovery is entirely patient-initiated today)
- `app/Http/Controllers/AuthController.php`'s `register()` method (to
  match validation rules and understand the `User` password-hashing
  mutator before reusing the same pattern)
- `app/Models/User.php` (confirmed a `password` `Attribute` mutator
  auto-hashes on set, same as `AuthController::register()` relies on)

### 4. Files Created
- `app/Http/Controllers/ClinicPatientController.php`
- `tests/Feature/ClinicPatientRegistrationTest.php` — 8 tests (§12)
- `Riva-master/src/app/service/clinic-patient.service.ts`
- `Riva-master/src/app/core/components/auth/clinic-patients/clinic-patients.component.ts`

### 5. Files Modified
- `routes/api.php` — added `GET clinic/patients/search` and
  `POST clinic/patients` inside the existing `clinic.context` group.
- `Riva-master/src/app/app.routes.ts` — added `/clinic-patients` with the
  same widened `['doctor', 'patient', 'caregiver']` role gating established
  in Slice 5.
- `Riva-master/src/app/components/sidebar.ts` — added a "Patients (Front
  Desk)" item to both the doctor's static menu and the dynamic
  `CLINIC_STAFF_ITEMS` array shown to a patient/caregiver confirmed to have
  clinic access.

### 6. Database Changes
No database change required — reuses the existing `users`/`patients`
tables and their existing columns.

### 7. Backend Business Logic
1. `search()` requires exactly an email OR a phone number (422 if neither
   is given) and performs an EXACT match only — never a partial/fuzzy name
   search — against `users.email`/`users.phone` scoped to `role = 'patient'`.
   Returns `{found: false}` for no match, or the patient's operational
   fields for a match.
2. `register()` first checks whether any `User` (any role) already owns
   that email; if so, returns 409 rather than silently reusing or updating
   the existing account — the front desk is expected to have already
   called `search()` first.
3. On a genuinely new email, creates a `User` (`role: patient`) and a
   `Patient` row together, with a random 40-character password that is
   never returned or logged anywhere — the account is real and immediately
   usable for clinic operations (queue check-in, appointment booking by
   staff) but the patient themselves cannot log in until they use the
   existing "forgot password" flow to claim it.
4. Every successful registration is written to `ActivityLogService` with
   the acting user, the patient's email, and the clinic name — for the
   same operational audit-trail reasons Slice 2's payment audit trail
   exists.

### 8. Authorization / Security
- CORRECT USER / WRONG USER: `test_owner_can_register_a_new_walk_in_patient`
  and `test_staff_with_register_permission_can_register_a_patient` (owner
  always passes by role; staff only with the explicit grant).
- UNAUTHORIZED ROLE: `test_staff_without_register_permission_cannot_register_a_patient`
  — a staff member granted a different permission (`queue.manage`) is
  denied, and the test additionally asserts no `User` row was created as a
  side effect of the failed attempt (`assertDatabaseMissing`).
- WRONG CLINIC / NO RELATIONSHIP: not directly applicable — patients are
  not clinic-owned entities, so there is no cross-clinic leak surface here
  by construction; the real boundary is "does the caller have an active
  clinic membership with this specific permission at all," proven by
  `test_user_with_no_clinic_membership_cannot_register_a_patient`.
- MANIPULATED ID: not applicable — no ID is accepted as input to either
  endpoint.
- INVALID INPUT: `test_search_requires_an_identifier` (422 with neither
  email nor phone), `test_registering_a_duplicate_email_is_rejected` (409).
- DATA EXPOSURE: `test_search_never_leaks_a_patient_via_partial_name` proves
  there is no way to discover a patient's existence except by already
  knowing their exact email or phone — the single most important test in
  this slice, since a front-desk "patient lookup" feature is exactly the
  kind of feature that can quietly become a PII enumeration tool if search
  is too permissive.

### 9. API Changes
| Method | Path | Auth | Role | Request | Response | Errors |
|---|---|---|---|---|---|---|
| GET | `/api/clinic/patients/search` | Bearer, `patients.register` | owner/admin/permitted staff | `?email=` or `?phone=` (exactly one) | `{found, patient?}` | 422 neither given, 403 no permission |
| POST | `/api/clinic/patients` | Bearer, `patients.register` | owner/admin/permitted staff | `first_name, last_name, email` (required), `phone, gender, age, blood_type, emergency_contact` (optional) | serialized patient, 201 | 409 duplicate email, 403 no permission, 422 validation |

### 10. Frontend Changes
- **New service** `clinic-patient.service.ts` — CONNECTED TO REAL API,
  intentionally offering only `searchByEmail()`/`searchByPhone()` (no
  generic/fuzzy search method exists, matching the backend's design).
- **New component** `ClinicPatientsComponent` (`/clinic-patients`) —
  CONNECTED TO REAL API. Search-first UX: a found patient renders as a real
  data card (name, email, phone, gender, age, profile-completion status); a
  confirmed "not found" reveals a "Register new patient" action, which
  opens a form pre-filled with the searched identifier; a successful
  registration shows the real backend success message verbatim, including
  its "forgot password" guidance, rather than a generic "success" toast
  that would hide that important caveat from the person using the screen.
  Loading/error states on both search and register reflect real backend
  responses (a 403, a 409, a 422 all render their real message).
- Both the doctor's static sidebar menu and the dynamic clinic-staff menu
  (Slice 5) gained a "Patients (Front Desk)" entry pointing here — kept
  visually distinct from the existing doctor-only "Patients" entry
  (`/patient-cards`, the medical relationship-based patient list), since
  the two are genuinely different features serving different purposes.

### 11. User Workflow
A receptionist searches by the phone number or email the walk-in patient
gives them. If Riva already has that person, their basic info appears
immediately, with no duplicate ever created. If not, one form creates a
real account and patient record in a few seconds, ready to be used for
booking today's appointment and later checked into the queue.

### 12. Tests Added
`tests/Feature/ClinicPatientRegistrationTest.php`, 8 tests: owner
registers a new patient, duplicate email rejected, staff
without/with the permission denied/allowed, exact-match search by email
and by phone, no partial-name leak, missing-identifier rejected, no-clinic-
membership rejected.

No Angular tests were added — same standing gap as Slices 4–5.

### 13. Test Commands
```
php artisan test --filter=ClinicPatientRegistrationTest
php -l app/Http/Controllers/ClinicPatientController.php
php -l routes/api.php
php artisan test
php artisan route:list --path=api
```

### 14. Test Results
- `ClinicPatientRegistrationTest`: 8 passed, 19 assertions, first run.
- `php -l` clean on both changed/new PHP files.
- Full suite: **159 passed, 476 assertions**, 0 failures (up from
  151/457 before this slice) — no regressions.
- `route:list --path=api` confirms both new routes registered exactly as
  intended.

### 15. Not Tested
- Angular build/typecheck still not run in any environment this session —
  same standing gap as Slices 4–5.
- No browser-level verification of the search → register UX flow.
- The "forgot password" claim-account flow was not exercised end-to-end in
  this slice (it is pre-existing functionality from before this session,
  not newly built) — whether a staff-registered patient can actually
  complete it depends on email delivery being configured, which is an
  existing, separately-flagged gap (see the Notifications area of the
  master directive), not something this slice newly introduces or claims
  to have verified.

### 16. Environment Blockers
None new. Same standing `.env`/MySQL-migration blockers as prior slices
(this slice adds no new migration, so nothing new is pending against the
real database specifically from this slice).

### 17. Regression Check
Full suite (159 tests, 476 assertions) passed after this slice, including
every test file from Slices 1–5 unchanged in behavior.

### 18. Git
Backend commit `4b3c4a9` — `feat(patients): add clinic-scoped patient
registration + search`. Synced to the real device using the standard
stage-diff-write-verify discipline (the one modified file, `routes/api.php`,
confirmed byte-identical to its pre-edit state before writing).

Frontend: 4 files (2 new, 2 modified) synced the same way — `app.routes.ts`
and `sidebar.ts` re-staged fresh immediately before editing (confirmed
matching their Slice 5 post-sync sizes exactly, i.e. zero drift). All 4
files re-verified via `device_list_dir` size match after write.

### 19. Current System State
- Backend: implemented and feature tests pass (159/159, including this
  slice's 8 new tests). No new migration to apply.
- Frontend: implemented and connected to the real backend API. Not
  build-verified.
- Authorization: real, backend-enforced. `patients.register` is now the
  third and final originally-unconsumed Slice 3 permission to gain a real
  consumer.
- Responsive: single-column, max-width layout appropriate for a
  search/form-heavy screen on both mobile and desktop; not manually
  verified in a real viewport.

### 20. Next Slice
Per the master directive's priority order, Clinic services + prices is
next — `Appointment.amount` still derives solely from `Doctor::fee`, with
no concept of distinct bookable services or per-service pricing at all.
This is also a prerequisite for `ClinicUser::PERMISSION_MANAGE_SERVICES`
(the RBAC permission Slice 3 defined but has no model/table/controller to
even attach to yet — a different kind of gap than `queue.manage`/
`payments.record`/`patients.register`, which had the permission but no
consumer; this one has neither). Concretely: design a `Service` model
(name, price, duration, clinic-scoped) and a `Service::class` — `Appointment`
relationship (nullable `service_id`, so existing appointments booked before
this concept existed remain valid), before building the
`ClinicServiceController` CRUD and its Angular counterpart.

## Slice 7 — Services & Prices

### 1. Objective
Direct continuation of Slice 6's own "next slice" plan and the master
directive's priority order: give `ClinicUser::PERMISSION_MANAGE_SERVICES`
(defined in Slice 3, unconsumed ever since — the last of the five
`ALL_PERMISSIONS` with no backend behind it at all) something real to
manage, and give `Appointment.amount` a second, clinic-defined source of
truth beyond the flat `Doctor::fee` it has used since Phase 0. Before
writing anything, grepped the whole codebase for any existing
service/pricing concept (`grep -rli "service" database/migrations/`,
`grep -rn "'services'\|clinic_service\|doctor_service"`) — confirmed zero
hits beyond incidental class-name matches (e.g. `NotificationService`),
so this is genuinely new, not a duplicate of anything.

### 2. Before State
No `ClinicService` model, table, or controller existed anywhere.
`AppointmentController::store()` derived `amount` unconditionally from
`Doctor::fee`; there was no way for a clinic to define multiple priced,
named offerings (e.g. "General Consultation" vs. "Follow-up" vs. "Blood
Pressure Check") at different price points. `services.manage` had been a
listed permission in `ClinicUser::ALL_PERMISSIONS` and the Angular
`CLINIC_PERMISSIONS` label list since Slice 3/4, fully assignable to a
staff member via the Clinic Team screen, but toggling it had zero
observable effect anywhere in the product.

### 3. Files Inspected
- `app/Models/Appointment.php`, `app/Http/Controllers/AppointmentController.php`
  (full read of `store()`, `index()`, `show()` — confirmed exactly where
  `Doctor::fee` is read and where eager-loads needed a `service` addition)
- `app/Models/ClinicUser.php` (confirmed `PERMISSION_MANAGE_SERVICES`
  already existed in `ALL_PERMISSIONS`, unconsumed)
- `app/Models/Clinic.php` (confirmed existing relation style —
  `memberships()`/`members()` — to match with a new `services()` relation)
- `app/Http/Controllers/ClinicPatientController.php` and
  `tests/Feature/QueueManagementTest.php` (as the two most recent,
  representative examples of this codebase's clinic-scoped
  controller/test style, to match precisely rather than invent a new
  pattern)
- `app/Http/Controllers/DoctorController.php` (full read, to find the
  right insertion point and match the existing public-doctor-data
  visibility pattern — `fee` is already public via `transformDoctor()`,
  so a public services list is a consistent, non-novel exposure)
- `routes/api.php` (full read, to find the exact `clinic.context` group
  and public-doctor-routes block to extend)

### 4. Files Created
- `database/migrations/2026_08_23_000010_create_clinic_services_table.php`
  — `clinic_services` (clinic_id FK cascadeOnDelete, name, description,
  price decimal(10,2), duration_minutes, is_active default true,
  timestamps, composite index on `[clinic_id, is_active]`), plus a
  nullable `service_id` FK on `appointments` (`after('doctor_id')`,
  `nullOnDelete()` — every appointment booked before this concept existed
  stays valid with no backfill, `service_id` simply stays null).
- `app/Models/ClinicService.php` — `belongsTo(Clinic)`,
  `hasMany(Appointment::class, 'service_id')`, `price` cast as
  `decimal:2`.
- `app/Http/Controllers/ClinicServiceController.php` — `index()` (any
  active clinic member — staff need to see prices to quote a walk-in even
  without `services.manage`), `store()`/`update()` (gated by
  `services.manage`), `destroy()` (gated by `services.manage`; returns
  409 with an explicit message when the service has appointment history,
  directing to deactivate via `update(['is_active' => false])` instead —
  see Section 7).
- `tests/Feature/ClinicServiceManagementTest.php` — 10 tests (see
  Section 12).

### 5. Files Modified
- `app/Models/Appointment.php` — added `service_id` to `$fillable`, added
  `service(): BelongsTo` to `ClinicService`.
- `app/Models/Clinic.php` — added `services(): HasMany` to `ClinicService`.
- `app/Http/Controllers/AppointmentController.php` — `store()` now
  accepts an optional `service_id` (`exists:clinic_services,id`); when
  present, validates the service `is_active` and belongs to a clinic
  where the target doctor has an active `ClinicUser` membership (422 with
  an explicit message otherwise — never a silent fallback), and derives
  `amount` from `service->price` instead of `doctor->fee`. Omitting
  `service_id` is byte-for-byte the same behavior as before this slice.
  `index()` and `show()` eager-loads gained `'service'`.
- `app/Http/Controllers/DoctorController.php` — added a new public
  `services(Doctor $doctor)` method (mirrors `hospitals()`/`availability()`
  in visibility level): returns active `ClinicService` rows for clinics
  where the doctor has an active membership, so a patient's booking
  screen can eventually offer a specific priced service instead of only
  the flat fee.
- `routes/api.php` — added `use ClinicServiceController;`, added
  `GET doctors/{doctor}/services` (public), added inside the existing
  `clinic.context` group: `GET/POST clinic/services`,
  `PATCH/DELETE clinic/services/{service}`.

### 6. Database Changes
One new table (`clinic_services`) and one new nullable, cascading-safe
column (`appointments.service_id`). Both validated only against SQLite in
this sandbox — **not yet applied to the real device's MySQL database**
(see Section 16 — this is now the 4th pending migration).

### 7. Business Logic
- A `ClinicService` is a clinic-scoped, named, priced, optionally-timed
  bookable offering — distinct from `Doctor::fee`, which remains the
  fallback price when no specific service is selected at booking time.
  This is additive: no existing booking flow, appointment, or payment
  record is affected by a clinic never creating a single service.
- Historical financial integrity (master directive §14/§28, matching the
  same principle already applied to `Payment`/`Appointment.amount`
  snapshotting): a service that has ever been booked cannot be
  hard-deleted, because doing so would null out `service_id` on every
  historical `Appointment` row that references it (the migration's
  `nullOnDelete()`), silently erasing which priced offering a past,
  possibly already-paid appointment was actually for. `destroy()` checks
  `$service->appointments()->exists()` and returns 409 instead,
  explicitly directing the caller to deactivate
  (`PATCH .../services/{id} {"is_active": false}`) — which keeps full
  historical integrity while hiding the service from new bookings.
- `AppointmentController::store()`'s `amount` derivation is now
  three-way: (1) valid, active, same-clinic `service_id` → `service->price`;
  (2) invalid/inactive/cross-clinic `service_id` → explicit 422, booking
  rejected outright (never a silent fallback to `Doctor::fee`, which
  would let a patient silently pay the wrong, unintended price); (3) no
  `service_id` at all → unchanged `Doctor::fee` fallback, byte-identical
  to pre-slice behavior.

### 8. Authorization / Security
- `ClinicServiceController::index()` — any active clinic member (owner,
  admin, doctor, or staff regardless of specific permissions) can view
  the price list. This is deliberate: a staff member without
  `services.manage` still needs to see and quote real prices to a walk-in
  at the front desk; only *changing* prices requires the permission.
- `store()`/`update()`/`destroy()` — gated by
  `ClinicUser::hasPermission(PERMISSION_MANAGE_SERVICES)` (owner/admin
  always pass; staff only with the explicit grant; doctor-role membership
  never gets it merely by being a doctor, matching every other
  clinic-scoped permission in this codebase).
- Cross-clinic isolation: `assertServiceBelongsToClinic()` returns 404
  (not 403 — matching `QueueController`'s and
  `ClinicPatientController`'s existing precedent of not even confirming
  another clinic's resource exists) when a caller's own active clinic
  membership does not own the target `ClinicService`.
- `AppointmentController::store()`'s new `service_id` branch never trusts
  the client's clinic assumption — it independently re-derives which
  clinic(s) the target doctor actually, currently, actively belongs to
  via `ClinicUser`, and only accepts a service from one of those. A
  patient cannot pay clinic B's price for a clinic A doctor's
  appointment, and cannot resurrect a service that was deactivated.
- `DoctorController::services()` is intentionally public (no auth
  middleware) — same visibility tier as `Doctor::fee` itself; only
  `is_active` services are ever returned, so a deactivated/retired
  service is invisible to new bookings via this endpoint too, not just
  via the clinic-scoped list.

### 9. API Changes
New: `GET /api/doctors/{doctor}/services` (public). New, inside
`clinic.context`: `GET /api/clinic/services`, `POST /api/clinic/services`,
`PATCH /api/clinic/services/{service}`, `DELETE /api/clinic/services/{service}`.
Modified: `POST /api/appointments` now accepts an optional `service_id`
field; `GET /api/appointments`, `GET /api/appointments/{appointment}`
responses now include an eager-loaded `service` relation (null when none
was selected).

### 10. Frontend Changes
- `Riva-master/src/app/service/clinic-services.service.ts` — new.
  `ClinicPricedService`/`ClinicServicePayload`/`DoctorService` interfaces
  (field names matching `ClinicServiceController::serialize()` exactly),
  `ClinicServicesService` class with `list()`, `create()`, `update()`,
  `remove()`, and a public `listForDoctor()` wrapping
  `GET doctors/{doctor}/services` for future booking-flow use.
- `Riva-master/src/app/core/components/auth/clinic-services/clinic-services.component.ts`
  — new. `ClinicServicesComponent` — loading/error/empty states, a
  responsive card grid (1 col mobile / 2 col `sm:`), a create form and
  per-card inline edit form, deactivate/reactivate toggle, delete (with
  the real backend 409-history message surfaced verbatim, not
  paraphrased), all gated to `canManage` computed the same way
  `ClinicQueueComponent` computes `canManageQueue` — via
  `ClinicService.myContext()` (owner/admin always true; staff only with
  `services.manage` in their permissions array) — a UX convenience only,
  never the real security boundary, which stays 100% backend-enforced.
- `Riva-master/src/app/app.routes.ts` — added `/clinic-services` with the
  same widened `['doctor', 'patient', 'caregiver']` role gating used by
  every other clinic-scoped route since Slice 5 (a clinic "secretary" has
  no dedicated platform role in this system).
- `Riva-master/src/app/components/sidebar.ts` — added a "Services &
  Prices" item to both the doctor's static `MENU` array and the dynamic
  `CLINIC_STAFF_ITEMS` array (shown to non-doctor clinic staff via the
  same `myContext()` probe already wired in Slice 5).

### 11. User Workflow
A clinic owner/admin (or a staff member granted `services.manage`) opens
"Services & Prices" from the sidebar, sees the clinic's current price
list (or an empty state on a brand-new clinic), and can add a new named,
priced service, edit an existing one inline, deactivate/reactivate it, or
delete it outright if it has never been booked (a 409 with a clear
explanation is shown instead if it has). Any other active clinic member —
including staff without the permission — can open the same screen and see
prices read-only, with a small note explaining why the management
controls are hidden. A patient booking an appointment can now,
server-side, have their `amount` derived from one of these real services
instead of only ever the doctor's flat fee, once a `service_id` is
threaded through the booking request (see Section 15 for what remains
unconnected on the booking UI itself).

### 12. Tests
`tests/Feature/ClinicServiceManagementTest.php` — 10 tests, all passed on
first run: owner full CRUD happy path (create → list → update price →
delete); staff without `services.manage` denied on create; staff with
`services.manage` can create; staff without the permission can still view
the price list; clinic A's owner gets 404 trying to update or delete
clinic B's service; deleting a service with appointment history is
blocked (409) and deactivating it via update instead succeeds; booking
with a valid service correctly derives `amount` from the service's price;
booking with no `service_id` still falls back to `Doctor::fee` unchanged
(regression proof); booking with an inactive service is rejected (422);
booking with another clinic's service is rejected (422).

### 13. Test Commands
```
php -l app/Models/Appointment.php app/Models/Clinic.php app/Models/ClinicService.php \
  app/Http/Controllers/AppointmentController.php app/Http/Controllers/ClinicServiceController.php \
  app/Http/Controllers/DoctorController.php routes/api.php \
  database/migrations/2026_08_23_000010_create_clinic_services_table.php \
  tests/Feature/ClinicServiceManagementTest.php
php artisan test --filter=ClinicServiceManagementTest
php artisan test
php artisan route:list --path=api
```

### 14. Test Results
- `php -l`: clean on all 9 changed/new PHP files.
- `ClinicServiceManagementTest`: 10 passed, 24 assertions, first run.
- Full suite: **169 passed, 500 assertions**, 0 failures (up from
  159/476 before this slice) — no regressions across any of Slices 1–6's
  tests, including `AppointmentPaymentFlowTest` and
  `CrossClinicResourceIsolationTest`, both re-run as part of the full
  suite despite not being directly touched this slice.
- `route:list --path=api` confirms all 5 new routes registered exactly as
  intended (`GET doctors/{doctor}/services`, `GET/POST clinic/services`,
  `PATCH/DELETE clinic/services/{service}`).

### 15. Not Tested
- Angular build/typecheck still not run in any environment this session
  — same standing gap as Slices 4–6. A manual brace/backtick-balance
  sanity check was run against both new `.ts` files (`node` script
  counting `{}/()/[]` depth and backtick parity) as a partial, honest
  substitute — this is not a real compile and does not catch type errors.
- The patient-facing booking screen (`BookingComponent`, a large
  pre-existing ~25KB `.ts` + ~26KB `.html` legacy component) was
  deliberately NOT modified to add a service-selector UI this slice. The
  backend fully supports `service_id` end-to-end (tested), and clinic
  staff/owners can already manage the price list via the new
  `/clinic-services` screen — but a patient cannot yet actually pick a
  specific service when booking through the UI; `ClinicServicesService.listForDoctor()`
  was added in anticipation of this integration but is not yet called
  from anywhere. This was an explicit, reasoned scope decision (not a
  bug or oversight): deep-integrating into a large, unfamiliar legacy
  component was judged disproportionate scope risk for this slice
  relative to continuing through the master directive's own priority
  order, versus the alternative of leaving it as a clearly-documented,
  discrete follow-up. No regression was introduced — booking without a
  service_id is byte-identical to pre-slice behavior.
- No browser-level verification of the Services & Prices screen.

### 16. Environment Blockers
`php artisan migrate` still not run against the real device's MySQL
database — `2026_08_23_000010_create_clinic_services_table.php` is now
the 4th pending migration (alongside `create_payments_table`,
`add_permissions_to_clinic_user_table`, and
`add_queue_fields_to_appointments_table`), all validated only against
SQLite in this sandbox. `.env` `APP_URL` fix remains blocked by
`device_commit_files`'s hard `.env`-write refusal. `device_bash` remains
unavailable on the real device, so no `npm install`/`ng build`/`ng lint`
was attempted this slice either — the same standing gap as every prior
slice.

### 17. Regression Check
Full suite (169 tests, 500 assertions) passed after this slice, including
every test file from Slices 1–6 unchanged in behavior — most notably
`AppointmentPaymentFlowTest` (proving the `service_id` addition to
`store()` did not disturb the existing `Doctor::fee`-derived
`amount`/payment flow) and `CrossClinicResourceIsolationTest` (proving
the new clinic-scoped `services.manage` gate did not weaken the
pre-existing relationship-based medical-resource isolation model).

### 18. Git
Backend commit `9d8ec35` — `Slice 7 (backend): Clinic Services & Prices`.
Synced to the real device using the standard stage-diff-write-verify
discipline — all 5 modified files (`AppointmentController.php`,
`DoctorController.php`, `Appointment.php`, `Clinic.php`, `routes/api.php`)
confirmed byte-identical to their pre-edit committed state (`git show
HEAD~1:<path>` diffed against the freshly-staged device copy) before
writing; all 9 changed/new files re-verified via `device_list_dir` size
match after write.

Frontend: 4 files (2 new — `clinic-services.service.ts`,
`clinic-services.component.ts` — plus `app.routes.ts` and `sidebar.ts`
modified) synced the same way. Note: the sandbox-local plain-file frontend
mirror (`/tmp/work/rivatest-main/Riva-master`) was discovered to be stale
for `app.routes.ts` (missing every clinic-* route from Slices 4–6 entirely)
and `sidebar.ts` (file did not exist in the mirror at all) — both were
instead edited directly against a freshly re-staged copy of the real
device files (confirmed current via byte-size match against the device
listing immediately before editing), not the stale local mirror. This is
a one-off mirror-staleness finding, not a device-side issue — the device
itself remained the correct, current source of truth throughout, and all
4 files were re-verified via `device_list_dir` size match after write
(`clinic-services.service.ts` 3217, `clinic-services.component.ts` 15451,
`app.routes.ts` 16981, `sidebar.ts` 8438 bytes).

### 19. Current System State
- Backend: implemented and feature tests pass (169/169, including this
  slice's 10 new tests). One new migration pending against the real
  MySQL database (not yet applied — see Section 16).
- Frontend: implemented and connected to the real backend API for
  clinic-side price-list management. Not build-verified. The
  patient-facing booking screen does not yet offer a service selector
  (see Section 15).
- Authorization: real, backend-enforced. `services.manage` is now the
  fifth and final originally-unconsumed Slice 3 permission to gain a real
  consumer — every permission in `ClinicUser::ALL_PERMISSIONS` now has at
  least one real, tested, backend-enforced consumer.
- Responsive: card-grid layout (1 col mobile / 2 col `sm:` and wider),
  consistent with `ClinicQueueComponent`'s established responsive
  pattern; not manually verified in a real viewport.

### 20. Next Slice
Per the master directive's priority order, the Doctor operational
dashboard is next: inspect the existing `/dashboard` (doctor)
screen/`DashboardController::doctor()` to determine what is already real
vs. still placeholder, and connect it to the now-much-larger set of real
clinic-scoped data this and the prior three slices have built (queue
state, today's patient count, staff roster size, service/price list) —
the master directive's own priority order places this immediately after
Clinic services + prices. A secondary, explicitly-scoped follow-up
(deliberately deferred rather than folded into this dashboard work,
unless it turns out to be trivial once the dashboard is being touched
anyway) is wiring `ClinicServicesService.listForDoctor()` into
`BookingComponent`'s existing service/type selection UI so a patient can
actually choose a priced service at booking time — completing the loop
this slice's backend already supports end-to-end.

## Slice 8 — Bug Fix: Patient Booking's Payment Step Was a Dead, Guaranteed-403 Button

### 1. Objective
Direct response to a fresh master-directive re-issue explicitly demanding
an API-contract audit ("for every endpoint verify... frontend
consumption... fix mismatches from either side") and zero dead
buttons/fake data. Attempting to trace the doctor dashboard's next-slice
plan led first through `BookingComponent` (patient-facing booking) while
checking for any existing payment UI to avoid duplicating one — that
inspection surfaced a real, currently-live defect unrelated to the
dashboard task, and per the standing "fix it now" bug-discovery rule this
was fixed immediately rather than deferred.

### 2. Before State (the bug)
`BookingComponent.completePayment()` called `POST /api/payments/{id}/pay`
as the signed-in **patient**, behind a UI literally labeled "Cash / Demo
Pay" and "Card" (the HTML's own copy: *"Choose cash for demo confirmation
or enter card details"*; the failure-path button read "Simulate failed
payment"). Since `AppointmentController::canRecordPayment()`'s Phase 0
security fix (pre-dating this session) and unchanged by Slice 5's RBAC
update, that endpoint only accepts an admin or a clinic staff member with
`payments.record` — never the appointment's own patient. That restriction
is deliberate and correct: `ManualPaymentGateway::charge()` always
"succeeds" unconditionally with zero real verification (it is an honest
stand-in for a human clinic-side attestation that money changed hands
outside the system, not a real gateway), so letting a patient call it
directly would let anyone self-attest their own appointment as paid —
exactly the fraud hole Phase 0 closed. The practical effect: **every real
patient who finished the booking wizard and clicked "Pay & confirm
appointment" received a 403 "You do not have permission..."**, with no
path to actually complete the flow the UI promised. The "card" fields
were purely decorative even before this — `completePayment()` never sent
them in the request body at all, so real card data was already discarded
client-side regardless of the 403.

### 3. Files Inspected
- `Riva-master/src/app/core/components/auth/booking/booking.component.ts`
  (full read — found `completePayment()`, `markPaymentFailed()`, and the
  card-field signals/formatters)
- `Riva-master/src/app/core/components/auth/booking/booking.component.html`
  (full read of the step-2 "Payment method" panel — confirmed the "demo"/
  "simulate" framing and that no card field is bound into the actual HTTP
  call)
- `app/Http/Controllers/AppointmentController.php`'s `canRecordPayment()`
  and `pay()` (re-read to confirm the exact, current, intentional
  restriction — this session did not touch this method, Slice 5 already
  fixed its RBAC gap; only its docblock and Phase 0 history needed
  re-confirming)
- `app/Services/Payments/ManualPaymentGateway.php` and
  `PaymentGatewayInterface.php` (confirmed `charge()` always returns
  success unconditionally with no real verification — this is what makes
  restricting *who* may call it security-critical, not merely a
  permissions nicety)
- `tests/Feature/AppointmentPaymentFlowTest.php`'s
  `test_patient_can_no_longer_self_declare_payment` (confirms this
  restriction is intentional, tested, and must NOT be reverted)
- `Riva-master/src/app/core/components/auth/appointments/appointment-detail.component.ts`
  (read while checking for any other patient-facing payment UI; found a
  separate, smaller gap — see Section 7)

### 4. Root Cause
A UI/API contract drift: the payment-restriction security fix (Phase 0,
prior session) correctly changed backend authorization, but the
patient-facing booking wizard was never updated to match — it kept
calling the now-forbidden path as if patient self-pay still worked. This
is exactly the "backend changed, frontend wasn't updated to match"
failure mode the master directive's API-contract-audit section describes.

### 5. Files Modified
- `Riva-master/src/app/core/components/auth/booking/booking.component.ts`
  — `completePayment()` no longer calls the doomed endpoint. It now
  clears the pending-booking `localStorage` entry, sets an honest success
  message ("Appointment reserved. Payment is completed at the clinic —
  your doctor's clinic will confirm it and notify you once recorded."),
  advances to the confirmation step, and navigates to the patient
  dashboard — synchronously, with no network call that would only ever
  fail. `markPaymentFailed()` (which called the equally-forbidden
  `POST /payments/{id}/fail` as the patient) was removed outright, since
  nothing legitimate remains for it to do from this screen. The
  card/cash-method signals (`paymentMethod`, `cardNumber`, etc.) and their
  formatter methods were left declared but are no longer referenced from
  the template — deliberately minimal-diff, since removing them serves no
  functional purpose and risked an unverifiable syntax mistake in a file
  this session cannot compile-check.
- `Riva-master/src/app/core/components/auth/booking/booking.component.html`
  — the entire step-2 "Payment method" panel (method selector, card
  fields, "Simulate failed payment" button) was replaced with an honest
  "Reservation confirmed" panel explaining payment is completed at the
  clinic, plus a single "Confirm reservation" button wired to the same
  (now-fixed) `completePayment()`. The appointment-summary sidebar
  (price/provider/date/time — all real, already-correct bound data) was
  left untouched.
- `Riva-master/src/app/core/components/auth/appointments/appointment-detail.component.ts`
  — a smaller, related gap found in the same inspection pass: this
  read-only appointment detail screen showed status/payment-status badges
  but never displayed `amount` or the Slice 7 `service` relation at all,
  even though `GET /appointments/{id}` already returns both. Added two
  more detail cards (Service, Amount) to the existing responsive grid — a
  pure display addition, no new API call, no behavior change.

### 6. Business Logic
No backend business logic changed in this slice — the fix is entirely
that the frontend now honestly reflects the backend's real, correct,
already-tested authorization model instead of presenting a payment flow
that could never succeed for the role it was shown to.

### 7. Authorization / Security
No authorization change. This slice explicitly preserves the Phase 0
restriction (`canRecordPayment()` unchanged) rather than loosening it to
make the old UI "work" — loosening it would have reopened a real fraud
hole (patient self-attesting payment via a gateway that always
"succeeds"). The correct fix was always on the frontend, matching what
the backend already, correctly enforces.

### 8. API Changes
None. No route, controller, or request/response contract changed.

### 9. Frontend Changes
See Section 5.

### 10. User Workflow
A patient booking a consultation now sees an honest "Reservation
confirmed" step after choosing a slot, explaining that payment happens at
the clinic rather than being shown a payment form that was guaranteed to
fail. Clicking through finalizes locally and returns them to their
dashboard. Actual payment recording remains exactly where it already
correctly lives: an admin, or clinic staff with `payments.record`
(built in Slice 5), completing it via the existing, tested
`POST /payments/{id}/pay` flow — e.g. front-desk staff collecting cash at
check-in.

### 11. Tests
No backend tests were affected or needed (no backend code changed).
`tests/Feature/AppointmentPaymentFlowTest.php`'s existing
`test_patient_can_no_longer_self_declare_payment` continues to be the
test that proves the restriction this frontend fix now correctly respects.
No Angular tests exist for `BookingComponent`
(`booking.component.spec.ts` is the default unmodified Angular CLI
scaffold, not a real test suite, unchanged by this session).

### 12. Test Commands
```
php artisan test --filter=AppointmentPaymentFlowTest
php artisan test
```
(Confirms the fix required no backend change and introduced no
regression — see Section 13.)

### 13. Test Results
Full suite: **169 passed, 500 assertions**, 0 failures — identical to the
post-Slice-7 baseline, since no backend file was touched this slice.
`AppointmentPaymentFlowTest` specifically: unchanged, still passing,
still proving patient self-pay is (correctly) forbidden.

### 14. Not Tested
- Angular build/typecheck still not run in any environment this session.
  A manual brace-balance check (`{}/()/[]` depth via a small `node`
  script) was run against the modified `.ts` file as a partial substitute
  — confirmed balanced.
- No browser-level verification of the new "Reservation confirmed" step
  or the appointment-detail Service/Amount cards.
- `markPaymentFailed()`'s removal was verified by grepping the full HTML
  template for any remaining reference (none found) rather than a real
  compiler — a genuine TypeScript "unused/undefined method" check was not
  possible in this environment.

### 15. Environment Blockers
New this slice: the device bridge's file-staging tool has a hard depth
limit (7 folders below the connected root) that a small number of
existing Angular files exceed — specifically
`core/components/auth/dashboard/dashboard/dashboard.component.ts`
(the doctor dashboard, which sits one level deeper than every sibling
role-dashboard component due to its `dashboard/dashboard/` double-nested
folder name) at 8 folders deep. `device_request_folder_access` cannot
work around this: requesting a folder already inside the connected root
reports `alreadyGranted` without establishing a new, shallower
depth-counting root — confirmed by testing it directly this slice. This
blocked the originally-planned "Doctor operational dashboard" slice from
proceeding as scoped; work was redirected to this payment-flow bug fix
instead, which used only files at or under the 7-folder limit. Fixing
this for real requires either the user connecting the `dashboard` folder
directly from the desktop app, or `device_bash` becoming available
(still reporting "Workspace unavailable" this slice) so the file could be
copied to a shallower path first. Documented precisely rather than
silently skipped, per the standing "distinguish infrastructure failure
from code failure and continue" rule.

### 16. Regression Check
Full suite (169 tests, 500 assertions) unchanged — this was a
frontend-only fix with zero backend surface area.

### 17. Git
No backend commit (no backend files changed). Frontend: 3 files modified
(`booking.component.ts`, `booking.component.html`,
`appointment-detail.component.ts`), synced directly to the real device
via `device_stage_files` → `Edit` → `SendUserFile` →
`device_commit_files`, each re-verified via `device_list_dir` byte-size
match after write (`booking.component.ts` 24122,
`booking.component.html` 23338, `appointment-detail.component.ts` 5564
bytes). No frontend git repository exists to commit against (a standing,
previously-documented limitation of this environment, unchanged).

### 18. Current System State
- Backend: unchanged, still 169/169 tests passing.
- Frontend: the patient booking flow no longer presents a payment step
  that is guaranteed to fail; the appointment detail screen now shows
  service and amount. The doctor dashboard frontend remains unreachable
  this slice due to the staging depth limit (Section 15).
- Authorization: unchanged, still 100% backend-enforced; this slice made
  the frontend stop contradicting it.

### 19. Next Slice
Two items carry forward: (1) the Doctor operational dashboard slice,
blocked pending either the user connecting the `dashboard` component
folder directly or `device_bash` recovering — worth a fresh attempt at
the start of the next slice in case either has changed; if still blocked,
pivot to dashboard-adjacent work reachable at normal depth (e.g.
`DashboardController::doctor()` itself, which is already substantially
real and could still be extended backend-side with the new clinic-scoped
counts even without frontend access this session). (2) Continue the
master directive's re-issued full-system audit into other listed areas
not yet inspected this session: Google OAuth flow, Gemini/AI integration
key handling, WhatsApp/email notification delivery configuration, Admin
workflow completeness, and the Pharmacy foundation (currently entirely
absent from the codebase — genuinely missing, not merely incomplete;
needs a scoping decision before implementation, since the master
directive explicitly warns against building an unrelated full pharmacy
ERP beyond whatever "the level actually specified" turns out to mean once
inspected).
