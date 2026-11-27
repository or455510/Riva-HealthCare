# RIVA-109: Wire S3-compatible object storage (P0-4)

- **ID:** RIVA-109
- **Title:** Wire S3-compatible object storage (P0-4)
- **Status:** BLOCKED
- **Risk Level:** MEDIUM
- **Assigned:** UNASSIGNED
- **Reviewer:** Codex
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-24T12:23:53.991Z
- **Updated:** 2026-08-24T13:08:51.074Z

## Objective
Install an S3-compatible Composer driver, provision object storage (Cloudflare R2/DO Spaces/AWS S3), switch ChatController/ProfileController/DoctorVerificationController upload paths, backfill existing local files without deleting until verified.

## Business Reason
Audit P0-4: all files on local app-server disk today; blocks horizontal scaling; no backup strategy for medical documents.

## Technical Requirements
config/filesystems.php s3 disk, composer.json (new dependency), the three upload controllers, a backfill migration/command.

## Acceptance Criteria
Uploads land in object storage; signed URLs still work (see SignedFileUrlTest.php); old local files retained until backfill verified

## Investigation (2026-08-24) — why this is genuinely blocked, not just deferred

**Attempted:** `composer require league/flysystem-aws-s3-v3` in the sandbox
git mirror, to at least prepare a verified `composer.json`/`composer.lock`
diff the operator could apply with a plain `composer install`.

**Result:** blocked. `composer update` failed with `curl error 56 ...
CONNECT tunnel failed, response 403` against `repo.packagist.org` — this
sandbox's network egress blocks Packagist, the same class of restriction
already documented in the RIVA-113 task file for Docker Hub. This is not a
transient failure; retrying will not help. (The `--no-update --dry-run`
composer.json edit that preceded this was reverted immediately — verified
clean via `git status` — rather than leaving an unverified, never-resolved
dependency line sitting in the file.)

**No real object storage account exists yet either** — `config/
filesystems.php`'s `s3` disk stub reads `AWS_ACCESS_KEY_ID` /
`AWS_SECRET_ACCESS_KEY` / `AWS_BUCKET` / etc. from `.env`, none of which are
set (confirmed without printing `.env` — see `RIVA_SECURITY.md`'s rule
against ever doing that). Even a successfully-installed driver would have
nothing real to connect to.

**Why this wasn't attempted as a blind, untested code change anyway:**
`StorageFileController` is the one system in this codebase that already
protects every doctor-verification document and chat attachment behind
ownership checks + signed URLs, and it currently has 100% passing coverage
(`StorageFileAccessTest`, `SignedFileUrlTest`). Its `resolvePath()` uses
`realpath($disk->path(...))` for path-traversal defense and
`response()->file($fullPath, ...)` to stream the result — both are
local-filesystem-specific; an S3-backed disk needs a materially different
implementation (stream via `readStream()` or the disk's own
`temporaryUrl()`, no `realpath()` path-traversal check because there's no
local path to traverse). Rewriting that logic without a real S3-compatible
endpoint to test against — package not installed, no credentials, no
sandbox network path to either — would mean shipping unverified changes to
the one part of this app explicitly built to prevent medical-document
leakage. That is a correctness/security regression risk this task's own
acceptance criteria ("signed URLs still work") cannot be honestly checked
off without a real target to run the suite against.

**What IS safe and already true:** every upload path (`ProfileController`,
`DoctorVerificationController`, `ChatController`) already stores only a
disk-relative path string in the database, never a raw public URL — see
`StorageFileController`'s class docblock. That means the actual disk swap
(local `public` → `s3`), when it happens, is a bounded, mechanical change
concentrated in those 4 files' `Storage::disk('public')` calls plus
`StorageFileController::resolvePath()`/`show()`/`showSigned()` — not a
schema or data-model change. Nothing about today's local-disk storage
blocks that migration later.

**Exact manual actions required to unblock, in order:**
1. Choose a provider and create the bucket (Cloudflare R2 recommended for
   a low-egress-cost medical-file workload; AWS S3 or DigitalOcean Spaces
   both work identically via the same driver since they're S3-API
   compatible).
2. On the real machine: `cd backend && composer require
   league/flysystem-aws-s3-v3`.
3. Add to `backend/.env` (values only the operator can supply — never
   generated or guessed by an agent): `AWS_ACCESS_KEY_ID`,
   `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`,
   `AWS_ENDPOINT` (for R2/Spaces — omit for real AWS S3),
   `AWS_USE_PATH_STYLE_ENDPOINT=true` (R2/Spaces), `FILESYSTEM_DISK=s3`.
4. Re-open this task for an agent session that has genuine network access
   to the chosen provider's API, so `StorageFileController`'s
   S3-compatible `resolvePath()`/streaming logic can actually be written
   *and exercised* against it before being trusted with real medical
   documents — not designed blind.

**Status: BLOCKED — environment (sandbox network + no device shell this
session) and missing real credentials, not a design gap.** Per the
directive's own "continue with other work" instruction for this exact
scenario, work continued on other P0/P1 items this session rather than
stalling here.
