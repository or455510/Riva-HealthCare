# RIVA-113: Dockerfile + CI pipeline (no Kubernetes/microservices)

- **ID:** RIVA-113
- **Title:** Dockerfile + CI pipeline (no Kubernetes/microservices)
- **Status:** INBOX
- **Risk Level:** LOW
- **Assigned:** UNASSIGNED
- **Reviewer:** Codex
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-24T12:23:54.201Z
- **Updated:** 2026-08-24T12:23:54.201Z

## Objective
Author a production Dockerfile (OPcache enabled) and a CI pipeline (lint, test, build) for the existing Laravel monolith + Angular frontend.

## Business Reason
Directive §18 / audit §19-20: no containerized deploy path or CI today.

## Technical Requirements
New Dockerfile, docker-compose for local dev, .github/workflows or equivalent CI config. Explicitly no Kubernetes, no microservices split.

## Status: PARTIALLY DONE

Written and synced to the real project this session:
- `backend/Dockerfile` (multi-stage, php-fpm-alpine, OPcache enabled) + `backend/docker/` (nginx config, php.ini/opcache.ini, entrypoint.sh, a dev-mode opcache override)
- `Riva-master/Dockerfile` (Node SSR build, matches the app's actual `serve:ssr:project-riva` script — this frontend is SSR, not a static build)
- `docker-compose.yml` at the repo root (mysql, redis, backend, nginx, frontend — local dev orchestration)
- `.dockerignore` for both build contexts

**Two things remain outstanding, both disclosed, neither silently skipped:**

1. **`.github/workflows/ci.yml` could not be written to the real project** — the device bridge refused it: *"is a protected file and cannot be written via remote tools"* (the same category of hard platform guard as `.env`, presumably to stop an agent from silently altering CI/CD). The finished file was delivered to you as a download in the conversation — please place it at `.github/workflows/ci.yml` yourself.

2. **No `docker build` was actually executed against these Dockerfiles.** This sandbox's Docker Hub registry access is blocked (`403 Forbidden` on any image pull, even a bare `alpine`), so I could only manually review the Dockerfile logic and validate `docker-compose.yml`'s YAML structure — not build-verify either image. Real verification needs to happen either in the GitHub Actions workflow once it's in place (which does have real registry access) or on your own machine.

Building `ci.yml` also surfaced two genuine pre-existing test bugs — `AiAssistantKeyCacheTest` and one case in `EmergencyAlertJobTest` only passed because the local `.env` happens to carry real credentials, and would have failed in CI against a clean `.env.example`. Both are fixed (see the `RIVA-113 (backend half)` commit in the backend git mirror) and verified by round-tripping `.env`/`.env.example` locally — full suite passes identically against both (196/196).

Also surfaced: there is no `pint.json`, and Pint's default style preset disagrees with ~100+ pre-existing files across the whole codebase (not touched by this session). `ci.yml` runs Pint as informational-only (`continue-on-error: true`) rather than a blocking gate, to avoid CI being permanently red for reasons unrelated to correctness. A real fix — either a reviewed repo-wide `pint` reformat, or a `pint.json` that matches the codebase's actual style — is its own task (RIVA-114), not something to do silently as a side effect of adding CI.

## Acceptance Criteria
- [x] Dockerfiles + docker-compose authored, reviewed, synced to the real project
- [ ] `.github/workflows/ci.yml` placed at that path by a human (blocked for the agent — protected path)
- [ ] `docker build` actually executed and confirmed working (blocked for the agent this session — registry access unavailable in the sandbox)
