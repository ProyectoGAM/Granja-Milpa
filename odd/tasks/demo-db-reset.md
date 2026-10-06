# Demo database reset after merged backend PRs

## Objective

After each backend PR merged into `main`, deploy that exact merge commit to the demo VPS, make and verify a database backup, reset and reseed the demo database once, and verify service health. Keep the reset opt-in and fail closed if the target cannot be proven to be GAM's demo VPS and database.

## Problem and rationale

The backend release workflow currently runs on every push to `main`, creates a version tag, and deploys the pushed SHA. It also deploys direct pushes and manual runs. Backend and frontend deployments use independent workflow concurrency groups, so they can overlap. Production Compose runs ordinary migrations, while its `DatabaseSeeder` omits local demo data outside `APP_ENV=local`; the admin seeder also requires `ADMIN_PASSWORD`, which Compose does not currently pass. Spatie Backup is installed but no tracked backup configuration, destination, or running command exists.

## Authorized scope and constraints

- Authorized local changes: `GAM-Backend` deployment/seed configuration and tests, `GAM-Frontend` deployment serialization, and this repository task record.
- Preserve all existing staged changes and submodule branch state.
- Never connect to or mutate the demo VPS during implementation or verification. Do not execute `migrate:fresh` against any live database. Do not commit or push.
- Keep automatic tag creation and exact-SHA deployment. Only an exact merged PR commit targeting `main` may request a reset; direct pushes, `develop`, CI, manual dispatch, and unmerged PR activity must not.
- Serialize all checked-in VPS deployment workflows with a host-side lock that remains held across deployment and reset; arrival of another event must wait rather than cancel an active reset.
- Reset remains disabled unless the GitHub `production` environment variable `DEMO_DB_RESET_ENABLED` is exactly `true`. Require `DEMO_VPS_HOST` and `DEMO_DB_DATABASE` there; compare the deploy target and live container settings against them. Reject `gam_testing` and any `_testing` database regardless of case.
- Keep `ADMIN_PASSWORD` only in the VPS `.env.production`; never pass or print it from GitHub Actions. The current VPS host and database values are not tracked in this repository, so automation must abort until explicit expected values are configured.
- The existing remote backend deploy script is referenced by `VPS_BACKEND_DEPLOY_SCRIPT` and is not tracked here. Treat its successful exit plus the post-deploy exact-image and health checks as deployment success; do not probe the VPS to inspect it.
- No configured backup destination exists in the repo. Create and verify a `pg_dump` custom-format backup on the demo VPS before reset, without printing backup bytes or credentials.

## Resolved workflow and delivery

- TDD: off; source: existing project ODD task records (`odd/tasks/egg-stock-physical-count.md` and related history). Use ordinary requested verification.
- Backend runner: `php artisan test <focused-file>` against explicitly selected `gam_testing`; never use the demo database. Shell workflow simulation: Bash (`bash.exe` is available locally).
- Route: delegated direct. Mapping trigger evidence: deployment logic spans both release workflows, production Compose, Laravel seed/config paths, backup behavior, and existing tests (4+ files). Writer trigger applies because implementation changes multiple non-trivial files. Delegate reads and implementation only after this document is read back.
- Forecast: about 675 authored changed lines, excluding generated files. This exceeds the planning heuristic because the offline simulation exercises merge qualification, every destructive guard, backup verification, retry/idempotency, and failure ordering without touching a live database. Delivery strategy: `ask-on-risk` (default); the user's no-commit/no-push instruction remains authoritative.
- Engram mirror: unavailable in this runtime; keep this complete local document as the recovery record and mark the mirror pending.

## Acceptance criteria

- The version deployed is the exact SHA for the qualifying merge, using the existing release-tag flow; a failed deploy prevents backup and reset.
- A reset is requested only for a push whose exact SHA is the merge commit of a PR with base `main` and `merged_at` set. Other pushes/workflows do not reset.
- Backend and frontend deployments share a VPS-side exclusive lock; active deployment/reset work is never canceled by later events.
- Before backup/reset, the live API is the expected GAM API service and image, healthy, `APP_ENV=production`, `APP_NAME=GAM`, `DB_HOST=postgres`, and its database name matches the explicit demo allowlist. The matching Postgres service must report the same database. Any mismatch aborts.
- A verified, non-empty database dump exists before `migrate:fresh --seed --force --no-interaction` runs in the exact deployed API container.
- With the opt-in reset flag set for the command, the existing demo seeder produces usable demo data in production, including an active admin with role/permissions. The password comes from the VPS environment only.
- With the flag unset/false, deployments retain normal migration behavior and do not erase data.
- Documentation names the GitHub environment variables, VPS-only admin password configuration, backup location, merge/deploy/backup/reset/health order, and explicitly says to set `DEMO_DB_RESET_ENABLED=false` before moving GAM to the client VPS.
- Offline simulation covers merge eligibility, disabled opt-in, target/name/environment mismatch, backup-before-reset ordering, and deploy-failure stopping before reset. Existing admin and demo-seeder tests pass using the isolated testing database.

## Tasks

| ID | Work | Route and trigger evidence | Status |
| --- | --- | --- | --- |
| DBR-1 | Add merge-qualified reset orchestration and shared deployment lock; enforce demo host/environment/database guards and verified backup; enable the existing demo seeder only for explicit reset; pass the VPS admin password safely; document setup/disablement; add safe simulations and focused seeder coverage. | Delegated direct. Mapping trigger applied because exploration crossed both release workflows, prod Compose, Laravel seed/config/test paths, and backup behavior (4+ files); multi-file writer trigger applied. User explicitly prohibited commits/pushes, so leave the work uncommitted. | Complete |

## Progress and evidence

- Read both release workflows, both production Compose files, backend `.env.example`, `Dockerfile`, `config/auth.php`, `DatabaseSeeder`, `LocalDemoDataSeeder`, `AdminUserSeeder`, existing admin/demo seeder tests, and backend README/libraries docs.
- Verified backend release currently triggers on `push: main` and `workflow_dispatch`, creates `v0.1.<run_number>`, and deploys `github.sha`. Backend CI separately targets `develop`/`main` and uses `APP_ENV=testing`, `DB_DATABASE=gam_testing`.
- Verified production backend Compose service names `api` and `postgres`, app name `GAM`, `APP_ENV=production`, DB host `postgres`, database supplied by VPS `.env.production`, and API image `gam-backend:${RELEASE_SHA}`. Actual VPS host/database values are absent from the repo.
- Verified demo records are currently local-only. Admin seeding requires `ADMIN_PASSWORD`; production Compose does not pass it. Spatie Backup is a dependency and `pg_dump` client is installed in the image, but backup is not configured or scheduled in the tracked app.
- Verified the configured remote deploy script path is provided by GitHub variable `VPS_BACKEND_DEPLOY_SCRIPT` and its contents are not present in the repo. No VPS command or live database access has been used.
- Implemented reset qualification only for a `push` to `main` whose exact SHA is a merged PR's `merge_commit_sha` with base `main`; manual runs, direct pushes, other branches, and PR activity cannot request a reset. Preserved automatic release tags and exact-SHA deployment.
- Serialized checked-in backend/frontend production deployments with `flock` on the VPS; GitHub workflow groups are per repository, while the shared host lock spans each deploy and reset. Added completed/in-progress SHA markers to prevent duplicate resets and automatic retries after an interrupted destructive attempt.
- Added fail-closed checks for host, app/environment, API image, PostgreSQL service and live database, reset toggle, and VPS-only admin password. Added private custom-format backups with nonempty, TOC, and full archive-parse checks before the destructive command.
- Enabled demo seeders in production only when `DEMO_DB_RESET_ENABLED=true`; added coverage for both disabled and enabled cases, including the active admin role/permission and sample lot.
- Added setup and client-migration disablement instructions in `GAM-Backend/README.md`. Actual `DEMO_VPS_HOST` and `DEMO_DB_DATABASE` values are not in the checkout, so a qualifying run fails closed until those exact values are configured in the GitHub `production` environment and VPS `.env.production`.

## Verification and closure

- Passed: `ops/tests/demo-db-reset-simulation.sh` (merge/direct-push/PR/flag cases, target/environment/database guards, deploy failure, verified backup ordering, interrupted retry, and duplicate SHA behavior), using fake `gh` and `docker` commands only.
- Passed: `php artisan test tests/Feature/IdentityAndAccess/AdminSeederTest.php tests/Feature/Lots/LotsDemoSeederTest.php` inside `gam-dev`, with `APP_ENV=testing` and `DB_DATABASE=gam_testing` (8 tests, 49 assertions).
- Passed: Pint on every modified PHP file; production Compose `config --quiet` using dummy interpolation values; js-yaml parsing of both release workflows; `git diff --check` in backend and frontend.
- Passed: exercised actual PostgreSQL custom-format backup, `pg_restore --list`, and full parse to `/dev/null` against isolated `gam_testing`; no database writes or reset occurred.
- Initial test attempt failed because the new tests invoked `db:seed` in simulated production without `--force`, causing an interactive confirmation mock error; updated them to run `db:seed --force`, and the final combined run passed. The first combined run also reported a future-date `LotsConflict`, which did not recur in the final combined run or isolated lot test.
- A late simulation expansion initially failed because the fake current-database value carried into the backup-failure case; resetting the fake environment per case fixed it, and the final full simulation passed.
- Unavailable: `actionlint` is not installed; workflow files were parsed with the installed `js-yaml` package. The VPS-specific remote deploy script and live host/database configuration remain uninspected because no remote operation was authorized or performed.
- No `migrate:fresh` was run on the demo VPS. No commit or push was made. The Engram mirror remains pending because no Engram memory tools are available in this runtime.
- Next: before activation, configure the exact demo host and database in GitHub's `production` environment and VPS `.env.production`, set `DEMO_DB_RESET_ENABLED=true` on both sides, and keep `ADMIN_PASSWORD` only on the VPS. Set the flag to `false` in both locations before moving GAM to the client VPS.
