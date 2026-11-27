# RIVA-143: Clinic staff invite requires the invited email to already be a registered Riva account under patient/doctor/caregiver — no real staff-only invite/signup flow exists

- **ID:** RIVA-143
- **Title:** Clinic staff invite requires the invited email to already be a registered Riva account under patient/doctor/caregiver — no real staff-only invite/signup flow exists
- **Status:** INBOX
- **Risk Level:** MEDIUM
- **Assigned:** UNASSIGNED
- **Reviewer:** UNASSIGNED
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-25T12:34:06.468Z
- **Updated:** 2026-08-25T12:34:06.468Z

## Objective
Design and build a real invite-to-join flow for clinic staff that does not require pre-existing patient/doctor/caregiver registration

## Business Reason
The clinic owner cannot add a secretary/receptionist unless that person already has an unrelated Riva account (as a patient, doctor, or caregiver) under the exact email being invited — confirmed as the literal validation rule, not a bug. There is no signup path for 'staff' at all today, by design, and the user does not want a 4th role added to the public register screen either

## Technical Requirements
ClinicMembershipController's invite validation is 'email' => ['required','email','exists:users,email'] (line 75) — hard-blocks any email that is not already a User row. The public /register screen only ever creates patient/doctor/caregiver accounts (RegisterComponent role selector), so there is structurally no way to create a 'just staff' account

## Product Decision (recorded 2026-08-25, confirmed directly by the product owner)
Option chosen: **real invite link**. Inviting an email that has no existing account creates a
pending `ClinicMembership` row keyed by that email (no `exists:users,email` requirement). The
invitee receives a real signup-via-invite-link: they set a password, land directly in the clinic
as staff, and never see the public role selector (Patient/Doctor/Caregiver) at all — staff is not
added to that selector, exactly as the product owner asked. If the email later *is* one used by
an existing patient/doctor/caregiver account, invite creation should still work today (unchanged
path) — this only fixes the case where the invitee has no account yet.

## Acceptance Criteria
- [x] `ClinicMembershipController`'s invite validation no longer hard-requires `exists:users,email`.
      DONE 2026-08-25.
- [x] A new pending-invite record (email + clinic + role) is created when the email has no account.
      DONE 2026-08-25: implemented as a real (not placeholder-only) `User` row — role stays the
      harmless enum default `'patient'` since `User.role` has no `'staff'` value by design and
      clinic authority is entirely governed by the `ClinicUser` row/permissions, never by
      `User.role` (matches the existing tested pattern — see `ClinicPatientRegistrationTest`'s
      `addStaffMember()`, which already creates staff test users with `role='patient'`). No
      `Patient` row is created for them (they are not a patient — creating one would pollute real
      patient counts/rosters). `clinic_user` gets new `invite_token` (single-use, hidden from
      every serialized response — see `ClinicUser::$hidden`) and `invite_token_expires_at`
      (7 days) columns via migration `2026_08_25_000002_add_invite_token_to_clinic_user_table`.
- [x] A real accept-invite link/token flow lets the invitee set a password and create a User scoped
      directly as clinic staff — no role selector shown, no patient/doctor/caregiver role implied.
      DONE 2026-08-25: `GET /clinic-invites/{token}` (public, shows clinic/role/email) and
      `POST /clinic-invites/{token}/accept` (public, sets name+password, activates the
      membership, signs the user straight in with a real Sanctum token) —
      `ClinicMembershipController::inviteDetails()`/`acceptInvite()`. Frontend:
      `AcceptClinicInviteComponent` at `/accept-clinic-invite/:token` (public route, no
      `AuthGuard`) — collects only name + password, never shows Patient/Doctor/Caregiver, then
      routes straight to `/clinic-patients` (staff) or `/clinic-staff` (any other invited role).
      `clinic-staff.component.ts`'s invite form now shows the generated link inline with a copy
      button when the invitee has no prior account (no email delivery is configured in this
      environment, matching the already-documented limitation on the walk-in-patient flow); its
      misleading "must already have a Riva account" copy is fixed.
- [x] Existing behavior (inviting an email that already has an account) is unaffected. DONE
      2026-08-25 — proven by `test_inviting_an_email_with_an_existing_account_is_unchanged_no_token_issued`.
      Full suite (`ClinicStaffInviteLinkTest`, 7/7; regression-checked `ClinicStaffPermissionsTest`
      9/9 and `ClinicTenantIsolationTest` 16/16, all unchanged) passing.

## Deployment note — action needed on the real server
`ClinicMembershipController::buildInviteLink()` reads a new `frontend_url` config value
(`config/app.php`, backed by the `FRONTEND_URL` env var, falling back to `APP_URL` if unset). The
sandbox `.env` already had `FRONTEND_URL=http://localhost:4200`, but the real deployment's `.env`
was not touched by this sync (secrets/environment files are deliberately never synced). **Please
confirm `FRONTEND_URL` is set correctly in the real `backend/.env`** (pointing at wherever the
Angular app is actually served) — otherwise invite links will fall back to the backend's own URL
instead of the frontend, and won't open the accept-invite page. Also remember to run
`php artisan migrate` for the two new migrations in this batch (`create_report_shares_table` and
`add_invite_token_to_clinic_user_table`), same as the still-outstanding `clinic_user` migration
from earlier in this session.
