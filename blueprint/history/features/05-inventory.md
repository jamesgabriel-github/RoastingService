# Feature: Inventory

**From build-plan:** feature 5
**Build attempt:** 1
**Status:** verified
**Branch:** `feature/inventory`

## Goal

Let an admin (or super admin) with `inventory` module permission restock and
adjust the shop-stock quantity of shop-supplied services, with every change
written to a full inventory log for accountability, and a low-stock indicator
so admins can see at a glance which items need restocking. Stock changes must
be safe under concurrent requests (locked database transactions), matching the
build plan's "inside locked transactions" requirement.

This only covers manual stock changes by an admin. Automatic stock movement
from orders (`reserve`/`release`) belongs to Feature 7 (Shop order) and later
fulfillment features; this feature only writes `restock` and `adjust` log
entries.

## In scope

- Backend: new `inventory_logs` table exactly as documented in
  `project-overview.md`: `id`, `service_id` -> `services`, `change_qty` (int,
  +/-), `reason` (enum: `restock` | `reserve` | `release` | `adjust` - full
  enum defined now to match the documented data model; this feature only ever
  writes `restock` and `adjust`), `booking_id` (nullable -> `bookings`,
  unused until Feature 6/7 exist), `remarks` (nullable string), `created_by`
  -> `users`, `created_at` (no `updated_at`; a log entry is never edited).
- Backend: add a NOT NULL `low_stock_threshold` integer column to `services`
  (default `5`) via a new migration, plus a `low_stock_threshold` field on the
  existing admin Service create/edit form (`ServicesPage.tsx`, Feature 4).
  Per-service (not a single global number) because items differ wildly in
  typical stock size (e.g. whole roasted turkeys vs. individual pieces).
  Assumption: default `5` when not set; the admin can change it per service at
  creation or edit time, same as any other service field.
- Backend: `InventoryLog` model (no factory needed beyond tests' own
  `InventoryLog::create`), with a `belongsTo` to `Service` and `User` (creator).
- Backend: `Api\Admin\InventoryController` under
  `role:admin,super_admin` + `module:inventory` (the existing
  `EnsureModulePermission` middleware from Feature 4, applied to a new
  `inventory` module group):
  - `GET /api/v1/admin/inventory` - list shop-supplied services
    (`allow_shop_supplied = true`) with current `stock_qty`,
    `low_stock_threshold`, and a computed `is_low_stock` flag
    (`stock_qty <= low_stock_threshold`).
  - `POST /api/v1/admin/inventory/{service}/restock` - body: `qty` (required,
    integer, `min:1`), `remarks` (nullable string). Adds `qty` to `stock_qty`
    and writes a `restock` log row with `change_qty = qty`.
  - `POST /api/v1/admin/inventory/{service}/adjust` - body: `change_qty`
    (required, integer, non-zero, either sign), `remarks` (nullable string).
    Adds `change_qty` to `stock_qty` and writes an `adjust` log row. Rejected
    (422) if the resulting `stock_qty` would go below `0`.
  - `GET /api/v1/admin/inventory/logs` - paginated list of all inventory log
    rows (default Laravel pagination), newest first, each with service name,
    `change_qty`, `reason`, `remarks`, creator name, `created_at`.
  - Both `restock` and `adjust` return 404 for a service where
    `allow_shop_supplied` is `false` (nothing to restock) or that doesn't
    exist; both run inside `DB::transaction` with
    `Service::lockForUpdate()` to serialize concurrent stock changes on the
    same row.
- Backend: `InventoryLogResource` and add `low_stock_threshold` /
  `is_low_stock` to the existing `ServiceResource` (admin-only resource;
  `PublicServiceResource` is untouched and keeps exposing only `in_stock`).
- Frontend: extend `frontend/src/features/services/` types/api/hooks/form
  minimally for the new `low_stock_threshold` field (Feature 4 files), and add
  a new `frontend/src/features/inventory/` module (types, api, hooks) plus an
  `InventoryPage.tsx`: a table of shop-supplied services (name, stock, low-stock
  badge when `is_low_stock`), inline restock and adjust forms per row, and a
  paginated log table below it.
- Frontend: `/admin/inventory` route, gated the same way as `/admin/services`
  (`RoleRoute allow={['admin','super_admin']} requireModule="inventory"`), plus
  an "Inventory" nav link in `AdminLayout` shown to `super_admin` or an admin
  with the `inventory` permission (mirrors the existing Services link).

## Out of scope

- Automatic stock `reserve`/`release` from shop orders or fulfillment
  (Features 7, 11, 12).
- Sales-dashboard low-stock alerts (Feature 15) - this feature only stores and
  exposes the per-service threshold and flag it will read.
- Filtering/searching the inventory log by service, date, or reason.
- Any change to customer-supplied-only services' stock (they have none).

## Build loop

Per-step review (`workflow.stepReview: "feature"`): implement all steps below
in one pass, then present the full diff for review. Checkpoint commits are
disabled; `/complete` creates the final commit after review.

## Build steps

- [x] 1. **Data model**: migration adding `inventory_logs` table and
      `services.low_stock_threshold` (NOT NULL int, default `5`); `Service`
      model's `casts()`/fillable updated for `low_stock_threshold`; new
      `InventoryLog` model with `belongsTo(Service::class)` and
      `belongsTo(User::class, 'created_by')`; `Service::isLowStock(): bool`
      helper. `Done when`: `php artisan migrate:status` shows both migrations
      run cleanly against the test database and `composer test` still passes.
- [x] 2. **Admin inventory endpoints**: `InventoryController` with `index`,
      `restock`, `adjust`, `logs`; `StoreRestockRequest`/`StoreAdjustRequest`
      form requests; `InventoryLogResource`; `ServiceResource` gains
      `low_stock_threshold`/`is_low_stock`; routes registered under
      `role:admin,super_admin` + `module:inventory`. `Done when`: a new
      `backend/tests/Feature/Admin/InventoryManagementTest.php` covers
      restock success, adjust success (both directions), adjust rejected
      below zero, non-shop-supplied service rejected, permission-denied for
      an admin without `inventory`, and the low-stock flag flips at the
      threshold; `composer test` passes.
- [x] 3. **Services form threshold field**: add `low_stock_threshold` to
      `frontend/src/features/services/{types,api,hooks}.ts` and the
      create/edit form in `ServicesPage.tsx`. `Done when`: `npm run build`
      and `npm run lint` pass; a manual create/edit round-trip shows the
      field persisting (screenshot or dev-server check during `/check`).
- [x] 4. **Inventory admin page**: new `frontend/src/features/inventory/`
      (`types.ts`, `api.ts`, `hooks.ts`, `InventoryPage.tsx`) with the stock
      table, low-stock badge, restock/adjust row forms, and the log table;
      wire `/admin/inventory` route in `App.tsx` and the nav link in
      `AdminLayout.tsx`. `Done when`: `npm run build` and `npm run lint` pass;
      a manual pass during `/check` confirms restock/adjust update the table
      and log without a page reload.

## Files / areas

- `backend/database/migrations/` (two new migrations)
- `backend/app/Models/Service.php`, new `backend/app/Models/InventoryLog.php`
- `backend/app/Http/Controllers/Api/Admin/InventoryController.php` (new)
- `backend/app/Http/Requests/Admin/StoreRestockRequest.php`,
  `StoreAdjustRequest.php` (new)
- `backend/app/Http/Resources/InventoryLogResource.php` (new),
  `ServiceResource.php` (edit)
- `backend/routes/api.php`
- `backend/tests/Feature/Admin/InventoryManagementTest.php` (new)
- `frontend/src/features/services/{types,api,hooks}.ts`,
  `ServicesPage.tsx`
- `frontend/src/features/inventory/` (new: `types.ts`, `api.ts`, `hooks.ts`,
  `InventoryPage.tsx`)
- `frontend/src/App.tsx`, `frontend/src/components/layouts/AdminLayout.tsx`

## Data / contracts

- `inventory_logs`: `id`, `service_id` (FK, cascade delete not specified by
  the plan - restrict delete since services aren't hard-deleted anywhere
  today, only deactivated), `change_qty` (int), `reason` (enum: `restock` |
  `reserve` | `release` | `adjust`), `booking_id` (nullable FK, unused this
  feature), `remarks` (nullable string), `created_by` (FK to `users`),
  `created_at` only (no `updated_at`).
- `services.low_stock_threshold`: NOT NULL integer, default `5`.
- `POST /admin/inventory/{service}/restock` body: `{ qty: number (>=1) }`,
  `remarks` optional. Response: updated `ServiceResource`.
- `POST /admin/inventory/{service}/adjust` body:
  `{ change_qty: number (non-zero) }`, `remarks` optional. Response: updated
  `ServiceResource`, or 422 with a validation error when the result would be
  negative.
- `GET /admin/inventory` -> array of `ServiceResource` filtered to
  `allow_shop_supplied = true`.
- `GET /admin/inventory/logs` -> Laravel's default paginated envelope of
  `InventoryLogResource` (`data`, `links`, `meta`).
- `InventoryLogResource`: `id`, `service_id`, `service_name`, `change_qty`,
  `reason`, `remarks`, `created_by_name`, `created_at`.
- `ServiceResource` (admin) adds: `low_stock_threshold`, `is_low_stock`.
  `PublicServiceResource` unchanged.

## Testing

- Backend (`composer test`, PHPUnit + `RefreshDatabase`, same pattern as
  `ServiceManagementTest`):
  - Super admin and an admin with `inventory` permission can restock and
    adjust; an admin without `inventory` gets 403; unauthenticated gets 401;
    a customer gets 403 (mirrors the existing `ServiceManagementTest`
    coverage for `services`).
  - Restock increases `stock_qty` and writes a `restock` log row with the
    correct `change_qty`.
  - Adjust with a positive and a negative `change_qty` both succeed and log
    correctly; an adjust that would take `stock_qty` below `0` is rejected
    with a 422 and no log row written.
  - Restock/adjust on a service with `allow_shop_supplied = false` returns
    404.
  - `GET /admin/inventory` only returns shop-supplied services and reflects
    `is_low_stock` correctly at, above, and below the threshold.
  - `GET /admin/inventory/logs` returns entries newest-first.
- Frontend: no new pure-logic unit (forms and tables are integration-level
  per the project's test scope rule); verified with `npm run build`,
  `npm run lint`, and a manual `/check` pass against the dev server.

## Notes for the AI

- Reuse `EnsureModulePermission` and the `module:<name>` route middleware
  alias exactly as Feature 4 wired them for `services`; only the module
  string changes.
- Reuse the `DB::transaction` + `lockForUpdate()` pattern for the two
  mutating endpoints; `AdminAccountController::syncPermissions` already uses
  `DB::transaction` (without row locking) as a precedent for transactional
  writes in this codebase.
- Match `ServiceManagementTest`'s login helpers
  (`loginAsSuperAdmin`/`loginAsAdminWith...Permission`) rather than
  reinventing auth setup in the new test file.
- `low_stock_threshold` default of `5` is a placeholder assumption, same
  spirit as Feature 4's placeholder seed rates - not a pricing/business
  decision, just a starting value the admin can change per service.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":11136,"specSha256":"e68ab82672229da2552a4116f401b6957c1b3fa79b52884870dcabeca5c589a2","branch":"refs/heads/feature/inventory","head":"54d781ec2a1918f3e4e8c098901d303239e17c08","baseRef":"refs/heads/master","baseCommit":"b7668d305e1e52daf28e51ec0d9f350613a579e2","sourceTree":"319fe5f964f6d32db28cdc458273213c179fc148","absentOptional":[]} -->

## Findings

### 5/F-22 [P1] closed - Service create/edit silently drops low_stock_threshold

**File:** backend/app/Http/Requests/Admin/StoreServiceRequest.php:26
**Found:** 2026-09-25 by /audit independent (scope: current; lens: quality/tests)
**Why it matters:** The spec (In scope, Build step 3) requires the admin to set `low_stock_threshold` per service at create or edit time, and step 3's done-when is a create/edit round-trip showing the field persisting. The frontend now sends `low_stock_threshold` (`frontend/src/features/services/ServicesPage.tsx:84`), but `StoreServiceRequest::rules()` (inherited unchanged by `UpdateServiceRequest`) has no rule for it. `ServiceController::store()` and `update()` pass only `$request->validated()` to `Service::create`/`update`, and `validated()` returns only keys that have rules, so the value is discarded. Every service keeps the migration default of `5`. The admin sees a successful save while the form resets to `5` on the next edit. The Inventory low-stock flag, and Feature 15's dashboard alerts that read it, can never be tuned. No backend test posts the field through the services endpoints, so the suite stays green.
**Suggested fix:** Add `'low_stock_threshold' => ['sometimes', 'integer', 'min:0', 'max:1000000']` (or `required`, matching the always-sent form payload) to `StoreServiceRequest::rules()`. Add a `ServiceManagementTest` case that creates and updates a service with a non-default threshold and asserts it in the response and database. Current requirement lost: None.
**Resolution:** Fixed 2026-09-25 by /implement. Added `'low_stock_threshold' => ['required', 'integer', 'min:0']` to `StoreServiceRequest::rules()` (matches the form, which always sends a value). Added `test_low_stock_threshold_is_saved_on_create_and_update` to `ServiceManagementTest`, and gave `validPayload()` a default so the new required field doesn't break existing cases. Not yet re-reviewed. Closed 2026-09-25 by /audit independent (re-review of `54d781e`; scope: current; all lenses). `StoreServiceRequest.php:34` now validates `low_stock_threshold` as `required|integer|min:0`; `UpdateServiceRequest` inherits it, and `ServiceController::store()`/`update()` pass it through `validated()` into the fillable column (`Service.php` fillable and `integer` cast). The frontend form always sends it (`ServicesPage.tsx:84`, zod `int().min(0)`). `ServiceManagementTest::test_low_stock_threshold_is_saved_on_create_and_update` asserts 8 on create and 12 on update in the response and database, and `composer test` passes (81 tests). The silent-drop defect is gone. The missing upper bound on the new rule is recorded separately as F-28 (P3).

### 5/F-25 [P3] closed - Inventory tests miss the at-threshold boundary, validation rejects, and threshold persistence

**File:** backend/tests/Feature/Admin/InventoryManagementTest.php:165
**Found:** 2026-09-25 by /audit independent (scope: current; lens: tests)
**Why it matters:** The spec's Testing section asks that `is_low_stock` be checked "at, above, and below the threshold". The index test covers only below (2 vs 5) and above (20 vs 5), so a `<` versus `<=` regression in `Service::isLowStock()` would pass. The suite never exercises the Form Request rejections (`qty: 0`, `change_qty: 0`), never asserts `created_by` on a log row, and never round-trips `low_stock_threshold` through the services endpoints. That last gap is why F-22 passed the suite.
**Suggested fix:** Add a service with `stock_qty` equal to its threshold and assert `is_low_stock: true`. Add 422 assertions for `qty: 0` and `change_qty: 0`, and assert `created_by` in one `assertDatabaseHas`. The threshold round-trip test belongs with the F-22 repair. Current requirement lost: None.
**Resolution:** Fixed 2026-09-25 by /implement. Added `test_stock_at_the_threshold_counts_as_low_stock`, `test_zero_quantity_is_rejected`, and `test_log_records_the_acting_admin` to `InventoryManagementTest`; the threshold round-trip landed in `ServiceManagementTest` with the F-22 repair. Not yet re-reviewed. Closed 2026-09-25 by /audit independent (re-review of `54d781e`; scope: current; all lenses). `InventoryManagementTest.php:165` asserts `is_low_stock: true` at `stock_qty == low_stock_threshold` (5/5), catching a `<` regression in `Service::isLowStock()`; `:176` asserts 422 with field errors for `qty: 0` and `change_qty: 0`; `:190` asserts `created_by` equals the acting admin. The threshold round-trip is covered by `ServiceManagementTest.php:199`. All pass under `composer test`. No new defect in the added tests.

### 5/F-26 [P3] closed - low_stock_threshold column is NOT NULL, while the spec says nullable

**File:** backend/database/migrations/2026_09_25_050000_add_low_stock_threshold_to_services_table.php:15
**Found:** 2026-09-25 by /audit independent (scope: current; lens: quality)
**Why it matters:** The spec's In scope and Data / contracts sections define `services.low_stock_threshold` as a "nullable integer, default `5`". The migration creates `unsignedInteger(...)->default(5)` without `->nullable()`, which on Postgres is a NOT NULL `integer`. The NOT NULL choice is arguably safer: `isLowStock()` and the frontend `number` type both assume a value. The spec and the schema still disagree, and later features (Feature 15) will read the spec.
**Suggested fix:** Keep the NOT NULL column and update the spec's data contract to say NOT NULL with default `5`. Code changes: None. Alternatively, add `->nullable()` and handle `null` in `isLowStock()` and the frontend type. Current requirement lost: None.
**Resolution:** Fixed 2026-09-25 by /implement. Took the suggested spec-only fix: `current-feature.md`'s In scope and Data / contracts sections now say NOT NULL with default `5`, matching the migration. No code changed. Not yet re-reviewed. Closed 2026-09-25 by /audit independent (re-review of `54d781e`; scope: current; all lenses). `current-feature.md:31`, `:95`, and `:149` now all say NOT NULL with default `5`, and the migration (`2026_09_25_050000_add_low_stock_threshold_to_services_table.php:15`) creates a NOT NULL column with default 5. `Service::isLowStock()`, `ServiceResource`, and the frontend `number` type all assume a value, so spec and schema agree.

## Independent review

**Status:** passed
**Target commit:** 54d781ec2a1918f3e4e8c098901d303239e17c08
**Base commit:** b7668d305e1e52daf28e51ec0d9f350613a579e2
**Base ref:** master
**Spec hash:** e68ab82672229da2552a4116f401b6957c1b3fa79b52884870dcabeca5c589a2
**Prepared by:** claude
**Builder model:** claude-sonnet-5
**Requested reviewer:** claude
**Requested model:** claude-opus-5-5
**Requested execution:** automatic
**Requested at:** 2026-09-25T04:44:33Z
**Workflow:** regular
**Check required:** no
**Reviewer adapter:** claude
**Reviewer model:** claude-opus-5-5
**Reviewer context:** fresh subagent
**Actual execution:** automatic
**Reviewed at:** 2026-09-25T04:46:05Z
**Scope:** current
**Lenses:** quality, security, performance, tests
**Verdict:** passed
**Check result:** not-required

### Commands

- `composer test` (backend): pass (81 tests, 302 assertions)
- `vendor/bin/pint --test` (backend): pass
- `npm run build` (frontend): pass (existing >500 kB chunk-size warning only)
- `npm run lint` (frontend): pass (2 pre-existing warnings, none in inventory files)
- `npm run test -- --run` (frontend): pass (2 files, 12 tests)

### Evidence

- Preconditions verified: `HEAD` = `54d781ec2a1918f3e4e8c098901d303239e17c08`; `git merge-base master HEAD` = `b7668d305e1e52daf28e51ec0d9f350613a579e2`; SHA-256 of `blueprint/context/current-feature.md` matches the Spec hash; the only working-tree difference was `blueprint/context/review.md`.
- Reviewed the full `b7668d3..54d781e` delta (27 files) against the spec: migrations, `Service`/`InventoryLog` models, `InventoryController`, both Form Requests, `StoreServiceRequest`, both Resources, routes, both feature test files, and the frontend inventory module, `ServicesPage`, `App.tsx`, and `AdminLayout.tsx`.
- Security: all four inventory routes sit under `auth:sanctum` + `role:admin,super_admin` + `module:inventory` (`backend/routes/api.php:409-414`); tests cover 401, a customer 403, and an admin without the permission getting 403 on every endpoint. `created_by` comes from `$request->user()`, not the body.
- Concurrency: `applyChange` locks the shop-supplied row with `lockForUpdate()->findOrFail()` inside `DB::transaction` and computes the new stock from the locked value before the below-zero guard (`InventoryController.php:62-90`). The 422 is thrown inside the transaction, so no log row is written (tested).
- Performance: the logs endpoint eager-loads `service` and `creator` and paginates. The index returns all shop-supplied services unpaginated, which fits the current catalogue size.
- The F-22 repair holds (`StoreServiceRequest.php:34`, round-trip test at `ServiceManagementTest.php:199`). The F-25 tests exist and pass. F-26 spec and migration now agree on NOT NULL.

### Findings

- F-22 [P1]: closed (repair verified)
- F-25 [P3]: closed (tests verified)
- F-26 [P3]: closed (spec/schema agree)
- F-23 [P3]: still open (re-examined, unchanged)
- F-24 [P3]: still open (re-examined, unchanged)
- F-27 [P3]: new, open. The adjust-below-zero 422 shows a generic "try again" message (`frontend/src/features/inventory/InventoryPage.tsx:44`)
- F-28 [P3]: new, open. `low_stock_threshold` has no upper bound, so a large value returns 500 (`backend/app/Http/Requests/Admin/StoreServiceRequest.php:34`)
- No P0 or P1 finding is open or fixed

### Remaining risk

- Concurrent restock/adjust serialization is correct on reading but was not exercised by a parallel-request test.
- No browser run: the restock/adjust table refresh and the threshold field round-trip in the UI were not exercised (Check was not required; no browser test command is declared).
- `ServiceResource.is_low_stock` is also computed for customer-supplied-only services (stock 0 at or below the threshold reads `true`). That matches the spec's formula, but Feature 15 should filter to shop-supplied services before alerting.
- Dashboard activity (`blueprint/.state/run.json`) was not written by this reviewer, because the reviewer was restricted to writing only the findings and review files.
