# RIVA-119: Fix critical account takeover via Google OAuth email auto-link

- **ID:** RIVA-119
- **Title:** Fix critical account takeover via Google OAuth email auto-link
- **Status:** DONE
- **Risk Level:** HIGH
- **Assigned:** Claude
- **Reviewer:** Codex
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-24T13:14:41.222Z
- **Updated:** 2026-08-24T13:14:41.278Z

## Objective
googleCallback() used firstOrCreate() keyed only on email, silently logging a real Google user into any pre-existing password-only account with a matching email -- letting an attacker who pre-registers a victim's email hijack the account once the real victim signs in with Google.

## Business Reason
Directive section 15 / section 6: authentication bypass and patient-privacy-violation are the two highest P0 categories. This is a full account-takeover chain requiring no password guessing.

## Technical Requirements
googleCallback() now rejects (409 JSON / redirect with error=account_exists) when an existing user has no google_id yet, instead of silently adopting that account; brand-new emails and already-linked returning Google users are unaffected.

## Acceptance Criteria
New GoogleOAuthAccountTakeoverTest (3 cases, mocked Socialite provider) passing -- attacker-planted account rejected with zero token issued, new account still works, returning linked user still works. Full suite 231/231.
