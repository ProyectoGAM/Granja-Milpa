# Egg Stock physical count

## Objective

Finish and verify the current Stock de huevos implementation against the local API and the requested wide-screen history layout. Preserve the existing physical-count flow, apply the pending local schema migration through the normal Laravel path, make the desktop history a readable table, refresh the balance after a stale-balance conflict, and add regression coverage for the real `12381 -> 1` case.

## Problem and rationale

The specialized physical-count code and tests already exist, but the local development database had the `add_physical_count_to_egg_stock_transactions` migration pending. The real request for Granja El Ombú therefore reached the action and failed with `SQLSTATE[42703]` when Laravel inserted `counted_quantity`. The current screen also still constrains `.stock-page.egg-page` to `1360px` and renders desktop history as labeled stacked fields instead of the requested spacious table.

## Authorized scope and constraints

- Authorized repositories: `GAM-Backend` and `GAM-Frontend`.
- Backend changes are limited to the specialized Egg Stock count API, its persistence/resource/audit path, and relevant tests/contracts.
- Frontend changes are limited to the Egg Stock page/API/history/detail and affected tests/docs.
- Preserve pre-existing worktree changes, including the Phase 1 navigation changes in `GAM-Frontend` and root `compose.dev.yaml`.
- Do not change generic inventory, StockLocation, production, delivery/reparto, navigation, roles, permissions, or shared-session behavior. Do not use generic stock adjustment. Do not modify/delete historical transactions. Do not commit or push.
- Any backend test must explicitly use `sga_backend_testing`; never migrate, clean, or test against `sga_backend`.
- A count equal to the current balance should retain a count/audit history record with zero difference and no ledger movement. This is an implementation inference to satisfy equal-count coverage while keeping movement history semantically accurate.
- Do not define approval or treatment of differences at reparto close; that remains pending with the client.

## Acceptance criteria

- An authorized Egg Stock user can submit counted eggs, reason, date, and idempotency key; actor comes only from the authenticated session.
- The server locks and reads the current balance in the transaction, rejects a stale preview, calculates the signed difference, and records the count/audit. A nonzero difference adds a compensating Egg Stock ledger movement without changing production or prior movements.
- Repeated identical commands are idempotent; conflicting replay is rejected. Concurrent counts cannot silently apply a preview to a changed balance.
- The existing historical correction/cancellation flow remains separate and does not mis-handle physical-count records.
- Stock de huevos displays theoretical balance, count input, reason/date, and signed difference before confirmation. Success refreshes balance and history. Count history/detail shows date, reason, direction, quantity, and actor.
- Existing access checks remain unchanged. No generic-inventory or navigation behavior is altered.
- Relevant backend/frontend tests, lint, build, and `git diff --check` results are recorded, including full Vitest worker failure text and process exit codes if it fails.
- The local `sga_backend` migration is applied once through `php artisan migrate`; no reset or data deletion is used.
- The real local request `Granja El Ombú / 12381 -> 1 / 2026-09-28` succeeds with difference `-12380`, final balance `1`, one compensating effect, and one history record.
- A `409` refreshes the current balance/history while retaining the unsubmitted form; `403`, `422`, and safe `500` handling remain explicit.
- On wide desktop the Egg Stock page uses the available shell width with a roughly 40/60 form/history split, table headers once, readable actor/reason cells, right-aligned signed quantities, and an independent pagination footer; tablet/mobile stack without horizontal overflow.

## Resolved verification configuration

- TDD: off, standard functional verification. Existing project task files cite the explicit selection in `develop:odd/tasks/inventory-usability-and-production-units.md`.
- Backend runner: `php artisan test`, with test DB selected explicitly as `sga_backend_testing`.
- Frontend runner: `npm run test:ci`.
- Delivery strategy: `ask-on-risk`; forecast exceeds about 400 authored lines across both repos. The user's explicit no-commit/no-push constraint applies to this phase, so no work-unit commit or PR is authorized.
- Receipt-driven development status observed as on by default. No review candidate is created because this phase must remain uncommitted.

## Tasks

| ID | Work | Route and trigger evidence | Status |
|---|---|---|---|
| EC-1 | Add specialized backend count request/action/route/resource/persistence/audit and backend tests; preserve existing correction/cancellation behavior. | Delegated writer. Mapping spans 4+ backend files and the implementation touches multiple non-trivial files. | Complete |
| EC-2 | Add Egg Stock count API/form/preview/history/detail presentation and frontend tests/docs, without navigation or role changes. | Delegated writer. Mapping spans 4+ frontend files and the implementation touches multiple non-trivial files. | Complete |
| EC-3 | Run guarded backend verification, affected frontend tests, full Vitest with a controlled retry if useful, lint, build, and diff checks; reconcile final scope/status. | Direct verification after writers finish; independent checks can run in parallel. | Complete |
| EC-4 | Apply the pending physical-count migration to local `sga_backend`, capture the real request/response/log evidence, and add a `12381 -> 1` backend regression with idempotency/conflict assertions. | Direct backend verification plus one focused regression edit after mapping; no backend production-code change indicated by the root cause. | Complete |
| EC-5 | Replace the Egg Stock desktop history presentation with a fluid wide layout/table, keep responsive cards on mobile, refresh after `409`, and cover the visible/error behavior. | Delegated writer. The current request spans shared scoped styles, template, page behavior, and focused tests (4+ non-trivial frontend files). | Complete |
| EC-6 | Run the requested backend/frontend checks, Pint, diff checks, and a visual/browser smoke check at 2048/1366/390 widths; record exact outcomes and reconcile unrelated worktree changes. | Direct verification after EC-4/EC-5; no commit, push, reset, or stash. | Complete |

## Progress and evidence

- Baseline: `GAM-Backend` branch `feature/inventory`, HEAD `4ecb4618429b8282b311d2b4e41eb06051f53f18`, clean; `GAM-Frontend` branch `feature/inventory`, HEAD `a643d5fdc8eb476e843d53e475f4467992d61fee`, with three pre-existing Phase 1 navigation/routing modifications; root branch `main`, HEAD `dd26e0172ea6526cb869165570c7618898301d4b`, with pre-existing submodule pointer and `compose.dev.yaml` modifications.
- Functional evidence: CU-INV-01/02 in the supplied PDF and meeting transcripts; prior code mapping confirmed current correction edits historical transaction data and no physical-count command exists.
- `php artisan model:show App\Models\Inventory\EggStockTransaction` failed on the host because PostgreSQL host `postgres` did not resolve (`SQLSTATE[08006]`). It succeeded in the already-running API container with `APP_ENV=testing` and `DB_DATABASE=sga_backend_testing`, before and after the new migration. The post-migration output showed bigint `quantity`, `balance_before`, `counted_quantity`, and `difference` columns, with model casts/relations matching the migration.
- The existing `sga_backend_testing` database is reachable from the API container. Backend verification ran there with `DB_DATABASE` explicitly set; no development database was used.
- EC-2 implementation completed in 12 frontend files: Egg Stock interface/API/page and detail, their tests, and `docs/inventory-frontend.md`. Existing three Phase 1 navigation/routing modifications were left untouched. Focused CI command passed (3 specs, 25 tests), `npm run lint` passed, `npm run build` passed with SCSS budget warnings, and frontend `git diff --check` passed.
- EC-1 implementation completed in 15 backend files: specialized request/action/route/resource/migration/audit, list/detail integration, historical edit guards, OpenAPI contract, and endpoint plus process-concurrency tests. `php artisan test tests/Feature/Inventory` passed against `sga_backend_testing`: 34 tests, 216 assertions, exit 0. Pint passed on 14 changed PHP files. PHP has no configured build script in `composer.json`.
- Frontend full CI command `npm --prefix GAM-Frontend run test:ci` passed: 43 files, 244 tests, exit 0. No Vitest worker error occurred, so no retry was needed. `npm --prefix GAM-Frontend run lint` exited 0. `npm --prefix GAM-Frontend run build` exited 0 with Angular SCSS budget warnings, including the modified Egg Stock and movement-detail styles.
- `git diff --check` exited 0 in the root workspace and both repositories. Git emitted only the normal LF-to-CRLF notices for frontend worktree files.
- Initial backend verification attempts exposed three test/setup issues before the passing run: a redundant global `RuntimeException` import promoted to an error, missing `egg-stock.view` in the stale-balance test actor, and the guarded migration rollback rejecting its own test count fixture. These were corrected; the rollback fixture cleanup is limited to `sga_backend_testing`.
- Implementation and requested checks are complete. No commit or push was made. Engram mirror remains pending because no Engram/memory tool is available; the full local task document is preserved here.

## Current follow-up evidence

- Real local reproduction before the fix: `POST /api/v1/production-units/1/egg-stock/counts`, authenticated admin, UUID `Idempotency-Key`, payload `{counted_quantity:1, expected_balance:12381, reason:"Conteo físico de diagnóstico", occurred_at:"2026-09-28"}` returned HTTP `500`.
- Laravel container log: `SQLSTATE[42703] Undefined column`, PostgreSQL reported that `counted_quantity` does not exist on `egg_stock_transactions`; the insert was issued by `RecordEggStockPhysicalCountAction::execute()` at line 93. Trace id from the gateway response was `a7e6f410-55ef-4388-a202-04abf0151bd5`.
- `php artisan migrate:status` showed `2026_09_28_115956_add_physical_count_to_egg_stock_transactions` pending on `sga_backend` and already ran on `sga_backend_testing`.
- `php artisan model:show App\\Models\\Inventory\\EggStockTransaction` in the API container against `sga_backend_testing` confirmed the model's `counted_quantity`, `balance_before`, and `difference` casts/columns match the migration.

## Final verification

- Applied the pending migration to the normal local `sga_backend` database once with `php artisan migrate --force --no-interaction`; no reset, fresh migration, deletion, or rollback was used. `php artisan migrate:status` then reported the migration as `Ran`.
- Replayed the real request after migration: `POST http://localhost:8080/api/v1/production-units/1/egg-stock/counts` with `counted_quantity: 1`, `expected_balance: 12381`, `reason: "Conteo fisico de diagnostico"`, and `occurred_at: "2026-09-28"` returned `201`; it recorded `quantity: 12380`, `balance_before: 12381`, `difference: -12380`, and final balance `1`.
- Replaying the same idempotency key returned the same transaction id; replaying it with a different count returned `409` with `La clave de idempotencia ya fue utilizada con otros datos.` PostgreSQL confirmed one physical-count transaction, one compensating inventory movement/line, one command, and the final balance `1`; actor and audit data were retained.
- Added backend regression coverage and verified the focused endpoint suite: `10 tests, 91 assertions`; Inventory feature suite: `35 tests, 235 assertions`; both exit 0 against `sga_backend_testing`. File-specific Pint passed.
- Updated the Egg Stock view with a fluid desktop shell, approximately 40/60 form/history split, a seven-column history table, readable actor/reason cells, right-aligned signed quantities, independent pagination, responsive mobile cards, and `409` balance/history refresh while retaining the form. Frontend focused tests passed (`11 tests`); full CI passed (`43 files, 247 tests`), lint passed, and build passed with existing SCSS budget warnings.
- Browser smoke review covered 2048x1152, 1366x768, and 390x844. The wide layouts used the available shell width without visible table overlap or horizontal overflow; mobile stacked as intended. Compose config validation and root/backend/frontend `git diff --check` passed.
- No commit, push, reset, or stash was performed. The local task document is current; the Engram mirror remains pending because no Engram/memory tool is available.
