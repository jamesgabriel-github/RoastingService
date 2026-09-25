# Feature: Shop order confirmation

**From build-plan:** feature 11
**Build attempt:** 1
**Status:** verified
**Branch:** feature/shop-order-confirmation

## Goal

Let an admin confirm a shop-supplied booking (`pending_confirmation` ->
`confirmed`, finalizing the order) or reject it with a reason
(`pending_confirmation` -> `rejected`), releasing the stock that was reserved
at order placement.

## In scope

- Two new admin actions on a shop-supplied booking: confirm, and reject with a
  required reason.
- Confirm sets `confirmed_at`/`confirmed_by` and transitions the booking via
  `BookingStatusEngine` (the `shop_supplied` transition table already allows
  `pending_confirmation -> confirmed`, added in feature 10).
- Reject sets `reject_reason`, transitions to `rejected`, and releases the
  reserved stock: for every `booking_items` row, increments the matching
  `services.stock_qty` by `qty` and writes an `inventory_logs` row
  (`reason: 'release'`, `change_qty: +qty`, `booking_id` set, `created_by` the
  acting admin) — the exact inverse of the `reason: 'reserve'` rows written at
  order placement (`OrderController::store`).
- Both actions are gated the same way as approve/reject/weigh-in: `bookings`
  module permission, only valid from `pending_confirmation`, and only for
  `source_type: shop_supplied` (a customer-supplied booking must reach
  `confirmed`/`rejected` only through approve -> weigh-in or the existing
  BYO-only reject).
- Admin booking detail page: confirm/reject controls shown only for a shop
  order (`source_type: shop_supplied`) in `pending_confirmation`, mirroring
  the existing approve/reject block's layout and error handling.

## Out of scope

- Cooking/fulfillment, payments, walk-ins, dashboard (later features).
- Any change to the customer-supplied approve/reject/weigh-in flow or its
  routes.
- Any change to shop order placement (`OrderController::store`) or the
  reservation it already performs.
- Partial rejection of individual items (rejecting a shop order releases the
  whole order's reserved stock).

## Build loop

Config: `workflow.stepReview = "feature"`, `workflow.checkpointCommits =
"disabled"`. Implement all build steps in one pass; the user reviews once at
the end of the full diff instead of after each step. No checkpoint commits
between steps; `/complete` makes the one feature commit.

## Build steps

- [x] 1. **Backend: confirm and reject-order actions**
   - Add `confirmOrder` and `rejectOrder` to
     `App\Http\Controllers\Api\Admin\BookingController`, following the
     existing `approve`/`reject`/`weighIn` pattern (transaction,
     `Booking::lockForUpdate()`, `detailEagerLoads()` on the response).
   - Add a private `guardOrderTransition(Booking $booking, BookingStatusEngine
     $statusEngine, string $to, string $action)` mirroring the existing
     `guardTransition`, but requiring `source_type === 'shop_supplied'`
     instead of `customer_supplied`.
   - `confirmOrder` needs no request body — no new Form Request; take a plain
     `Illuminate\Http\Request` only for `$request->user()->id`.
   - `rejectOrder` reuses the existing `App\Http\Requests\Admin\
     RejectBookingRequest` (`reason`: required string, max 255) — same rule
     set as the BYO reject, no new request class.
   - Inside `rejectOrder`, eager-load `items` on the locked booking and, for
     each item, `Service::whereKey($item->service_id)->increment('stock_qty',
     $item->qty)` plus an `InventoryLog::create([...])` row as described
     above. Use an atomic `increment()` per row (not a read-modify-write
     loop) so this doesn't need the multi-row lock ordering `OrderController::
     store` uses for reservation.
   - Done when: `composer test` passes for the new backend tests below.

- [x] 2. **Backend: routes**
   - Add, inside the existing `auth:sanctum` + `role:admin,super_admin` +
     `module:bookings` group in `backend/routes/api.php`, right after the
     existing `weigh-in` route:
     - `POST /admin/bookings/{id}/confirm-order` -> `confirmOrder`
     - `POST /admin/bookings/{id}/reject-order` -> `rejectOrder`
     (both with the same `where('id', '[0-9]{1,18}')` constraint). Distinct
     paths from `/approve`, `/reject`, `/weigh-in` are required: those three
     stay BYO-only, proven by the existing
     `test_reject_on_a_shop_supplied_booking_is_rejected` test.
   - Done when: `php artisan route:list` shows both new routes; existing
     `AdminBookingApprovalTest` still passes unchanged (BYO-only guard is
     untouched).

- [x] 3. **Backend tests**
   - Add `backend/tests/Feature/Admin/AdminOrderConfirmationTest.php`
     (mirrors `AdminBookingApprovalTest`'s structure: `loginAsSuperAdmin`,
     `loginAsAdminWithoutBookingsPermission`, same `withHeader('Referer', ...)`
     setup) covering:
     - Confirm succeeds from `pending_confirmation` on a `shop_supplied`
       booking: response `status: confirmed`, `confirmed_by_name` set;
       DB assertions on `confirmed_at`/`confirmed_by` and a
       `booking_status_logs` row.
     - Confirm on a booking not in `pending_confirmation` is rejected (422,
       `status` error).
     - Confirm on a `customer_supplied` booking is rejected (422, `status`
       error) — proves the BYO/shop split.
     - Reject succeeds from `pending_confirmation`: response `status:
       rejected`, `reject_reason` set; DB assertions on `booking_status_logs`
       (`remarks` = reason). Build the booking with 1-2 `booking_items`
       against real `Service` rows with a known starting `stock_qty`, then
       assert each service's `stock_qty` increased by exactly its item's
       `qty` and a matching `inventory_logs` row exists
       (`reason: release`, `change_qty: +qty`, `booking_id` set).
     - Reject on a booking not in `pending_confirmation` is rejected, and
       stock is unchanged.
     - Reject on a `customer_supplied` booking is rejected (422, `status`
       error), stock unchanged.
     - Missing `reason` on reject is a validation error.
     - Admin without `bookings` permission is forbidden on both actions.
     - Customer is forbidden on both actions.
     - Unauthenticated is rejected on both actions.
     - Unknown/non-numeric id is not found on both actions.
   - Done when: `composer test` is green, including this new file.

- [x] 4. **Frontend: API + hooks**
   - `frontend/src/features/admin-bookings/api.ts`: add `confirmOrder(id):
     Promise<AdminBooking>` (POST `/admin/bookings/{id}/confirm-order`, no
     body) and `rejectOrder(id, payload: RejectPayload): Promise<AdminBooking>`
     (POST `/admin/bookings/{id}/reject-order`), both with `ensureCsrfCookie()`
     first, matching `approveBooking`/`rejectBooking`.
   - `frontend/src/features/admin-bookings/hooks.ts`: add `useConfirmOrder()`
     and `useRejectOrder()` mutations using the existing
     `useBookingActionInvalidation()` helper, matching `useApproveBooking`/
     `useRejectBooking`.
   - Done when: `npm run lint` passes.

- [x] 5. **Frontend: order confirm/reject UI**
   - In `AdminBookingDetailPage.tsx`, add a `ConfirmRejectOrderActions({
     booking })` component mirroring `ApproveRejectActions`: a reason `Input`
     + "Reject" button, and a "Confirm order" button needing no input. Reuse
     `getActionErrorMessage` for surfacing 422s.
   - Render it when `!isRoasting && booking.status === 'pending_confirmation'`,
     next to the existing `isRoasting && booking.status === 'pending_review'`
     line.
   - Done when: `npm run lint` and `npm run build` pass, and manual check in
     the running app: place a shop order as a customer, open it in
     `/admin/bookings` under "Pending confirmation", confirm one order (status
     moves to Confirmed, service stock unchanged) and reject another
     (status moves to Rejected, reason shown, service stock_qty back up by
     the ordered qty, and an inventory log row exists for it).

## Files / areas

- `backend/app/Http/Controllers/Api/Admin/BookingController.php`
- `backend/routes/api.php`
- `backend/tests/Feature/Admin/AdminOrderConfirmationTest.php` (new)
- `frontend/src/features/admin-bookings/api.ts`
- `frontend/src/features/admin-bookings/hooks.ts`
- `frontend/src/features/admin-bookings/AdminBookingDetailPage.tsx`

## Data / contracts

- No schema changes. `bookings.confirmed_at`/`confirmed_by`/`reject_reason`,
  `booking_status_logs`, and `inventory_logs.reason` (`release` already an
  allowed value) all already exist from earlier features.
- `POST /api/v1/admin/bookings/{id}/confirm-order` - no body. `200` with the
  updated `AdminBookingResource` (`status: "confirmed"`). `422 {errors:
  {status: [...]}}` when not a `shop_supplied` booking in
  `pending_confirmation`. `403` outside the `bookings` module. `404` for an
  unknown id.
- `POST /api/v1/admin/bookings/{id}/reject-order` - body `{ reason: string
  (required, max 255) }`. `200` with the updated resource (`status:
  "rejected"`, `reject_reason` set). Same `422`/`403`/`404` shapes as confirm.
- `AdminBookingResource` is unchanged - `confirmed_at`, `confirmed_by_name`,
  `reject_reason` already exist in its output and already cover this flow.

## Testing

- Backend (PHPUnit, `composer test`): new
  `AdminOrderConfirmationTest` covers confirm/reject success, the
  BYO/shop source-type split, invalid-status transitions, stock release
  correctness (including the `inventory_logs` row), validation, permissions,
  auth, and not-found - all logic with a real right/wrong answer, per the
  project's testing scope rule.
- Frontend: no new pure-logic units introduced (the new UI is a thin
  mutation + conditional render, same shape as the existing
  `ApproveRejectActions`); covered by `npm run lint`, `npm run build`, and
  the manual browser check in step 5.

## Notes for the AI

- `BookingStatusEngine`'s transition table already allows
  `shop_supplied: pending_confirmation -> confirmed|rejected|cancelled` (added
  in feature 10) - do not touch `BookingStatusEngine`.
- The existing `guardTransition` in `BookingController` already documents (in
  its own doc comment) that shop-supplied orders reach `confirmed`/`rejected`
  through this feature's own actions instead - `guardOrderTransition` is that
  promised flow, not a workaround.
- Mirror `OrderController::store`'s reservation bookkeeping exactly in
  reverse: same `inventory_logs` shape, just `reason: 'release'` and a
  positive `change_qty`.
- Keep `confirmOrder`/`rejectOrder` next to `approve`/`reject`/`weighIn` in
  the same controller - these are all "admin acts on one booking" actions on
  the same `bookings` module permission, not a separate resource.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":10658,"specSha256":"402a6eba9228c9bbc71df40ef7b38fe09ae16c5e89ed4b80bdf8584177c77227","branch":"refs/heads/feature/shop-order-confirmation","head":"0ce0a5adf691a2417fffebd2d09d0787bbe14157","baseRef":"refs/heads/master","baseCommit":"7581a082a742bd955305c697c3b9331610581c48","sourceTree":"850c3ed35b21bbb3af424f64b1784d5cc2b1880d","absentOptional":[]} -->

## Independent review

**Status:** passed
**Target commit:** 0ce0a5adf691a2417fffebd2d09d0787bbe14157
**Base commit:** 7581a082a742bd955305c697c3b9331610581c48
**Base ref:** master
**Spec hash:** 402a6eba9228c9bbc71df40ef7b38fe09ae16c5e89ed4b80bdf8584177c77227
**Prepared by:** claude
**Builder model:** claude-sonnet-5
**Requested reviewer:** claude
**Requested model:** claude-opus-5-5
**Requested execution:** automatic
**Requested at:** 2026-09-25T13:02:46Z
**Workflow:** regular
**Check required:** no
**Reviewer adapter:** claude
**Reviewer model:** claude-opus-5-5
**Reviewer context:** fresh subagent
**Actual execution:** automatic
**Reviewed at:** 2026-09-25T13:04:41Z
**Scope:** current
**Lenses:** quality, security, performance, tests
**Verdict:** passed
**Check result:** not-required

### Commands

- `git rev-parse HEAD`: pass (equals Target commit)
- `git merge-base master HEAD`: pass (equals Base commit)
- `git status --porcelain --untracked-files=all`: pass (only `blueprint/context/review.md` modified)
- `sha256sum blueprint/context/current-feature.md`: pass (equals Spec hash; spec is tracked, no snapshot needed)
- `git diff 7581a08..0ce0a5a`: reviewed in full (7 files, +621/-8)
- `composer test` (backend): pass (184 tests, 696 assertions)
- `vendor/bin/pint --test` (backend): pass
- `npm run lint` (frontend): pass (0 errors; 4 pre-existing warnings in files outside this delta)
- `npm run test -- --run` (frontend): pass (7 files, 33 tests)
- `npm run build` (frontend): pass (pre-existing >500 kB chunk-size warning)

### Evidence

- `BookingController::confirmOrder`/`rejectOrder` use a transaction, `lockForUpdate()` on the booking, `guardOrderTransition` (`source_type === 'shop_supplied'` plus `BookingStatusEngine::isAllowed`), and return `detailEagerLoads()`. This matches the spec's build step 1 and the existing approve/reject pattern.
- `rejectOrder` reuses `RejectBookingRequest`, sets `reject_reason`, and writes one `release` `InventoryLog` (`+qty`, `booking_id`, `created_by` = admin) with an atomic `increment` per item. This is the inverse of `OrderController::store`'s `reserve` rows.
- Both routes are inside the existing `auth:sanctum` + `role:admin,super_admin` + `module:bookings` group with the `[0-9]{1,18}` id constraint (`routes/api.php:48-49`). The BYO routes are unchanged.
- `AdminOrderConfirmationTest` covers every case in spec step 3: success, invalid status, source-type split, stock plus log release, missing reason, missing permission, customer, unauthenticated, and 404/non-numeric id. All pass.
- Frontend: `confirmOrder`/`rejectOrder` call `ensureCsrfCookie()` first. The hooks use `useBookingActionInvalidation`. `ConfirmRejectOrderActions` renders only for `!isRoasting && status === 'pending_confirmation'` and surfaces 422 errors through `getActionErrorMessage`.
- Security: no client-controlled ownership or actor fields; `confirmed_by`/`created_by` come from `$request->user()`. The reason is validated `max:255` and rendered as React text, so it is not an XSS vector.

### Findings

- F-52 [P2] open: reject-order releases stock in unsorted item order, unlike `cancel`'s `sortBy('service_id')`. This risks a deadlock and a 500 under concurrent orders. It does not block.
- F-53 [P3] open: the tests do not prove that confirm and failed rejects leave `inventory_logs` and stock untouched. It does not block.
- F-44 [P3] re-examined: the line reference was updated and the finding is still open.
- No P0 or P1 findings.

### Remaining risk

- Check was not required, so there was no live browser walkthrough of confirm/reject in the running app (spec step 5's manual check). Dev servers were not started.
- The F-52 deadlock interleaving was found by reading the code. It was not reproduced under concurrent load.
- No frontend component tests cover `ConfirmRejectOrderActions`; frontend verification is lint and build only, as the spec intends.
- No dependency vulnerability scan was run; no scanner command is declared.
- The dashboard activity helper was not invoked, because the caller limited this reviewer to writing only `findings.md` and `review.md`.
