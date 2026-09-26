# Feature: Per-item booking status

**From build-plan:** feature 18
**Build attempt:** 1
**Branch:** feature/per-item-booking-status
**Status:** verified

## Goal

Move booking status (and its transition timestamps/actors) off `bookings` and
onto `booking_items`, so a booking's items can progress independently once
they reach Cooking. Regroup the admin queues into Draft / Pending / Cooking /
Ready / Completed / Cancelled, and change the admin bookings list to one row
per item instead of per booking.

## In scope

- Move `status`, `approved_at`/`approved_by`, `confirmed_at`/`confirmed_by`,
  `weighed_at`, `cooking_started_at`, `est_ready_at`, `completed_at`, and
  `reject_reason` from `bookings` to `booking_items`.
- Add `booking_item_id` to `booking_status_logs` (kept alongside the existing
  `booking_id`); every status-change log row is written per item.
- `BookingStatusEngine::transition()` now operates on a `BookingItem`
  (`isAllowed`/`initialStatusFor` are unchanged - they were already keyed only
  by `is_order` + status strings).
- Booking-level admin actions (approve, reject, weigh-in, confirm-order,
  reject-order, no-show, cancel) still act once per booking, transitioning
  every one of its items together - these statuses never diverge before
  Cooking, so this stays a single admin action.
- Cooking-onward actions (start-cooking, ready, out-for-delivery, complete)
  move to a per-item endpoint so one item can be started, marked ready, or
  completed while a sibling item on the same booking is still cooking.
- Customer booking creation, cancellation, and walk-in creation (roasting and
  shop) write/transition per item instead of per booking.
- Admin queue `counts` and list regroup the raw statuses into six groups -
  Draft (Pending review, Pending confirmation, Awaiting drop-off), Pending
  (Confirmed), Cooking, Ready (Ready, Out for delivery), Completed, and a
  separate Cancelled tab (Rejected, Cancelled, No-show) - and the list returns
  one row per booking item.
- Admin sidebar booking sub-menu links to the six regrouped tabs (still
  bookmarkable URLs on the same queues page).
- Sales dashboard's `bookings_by_status`, `active_queue`, and `top_items`
  read from `booking_items` instead of `bookings`.
- Payment eligibility on a booking is re-derived from its items' statuses
  (payment eligible once every item has reached Confirmed or later - see
  Data / contracts).
- Customer-facing booking status timeline: label each log line with the
  item's service name when a booking has more than one item, since a
  transition that used to write one log row per booking now writes one per
  item.

## Out of scope

- Any change to the status *values* themselves or the transition graph
  (`BookingStatusEngine::TRANSITIONS`) - only where the status lives changes.
- Splitting `dropoff_at` / `preferred_dropoff_at` per item - drop-off
  scheduling stays a whole-booking concept.
- Any new admin ability to force one item's status independently of the
  others *before* Cooking - divergence is only possible from Cooking on.
- Reworking payments into multiple partial/per-item payments - `total_amount`
  and payment recording stay at the booking level.

## Build loop

Work through Build steps in order. This feature's `stepReview` is `feature`:
implement all steps, then present one combined review packet (no per-step
approval pauses). Checkpoint commits are disabled for this feature.

## Build steps

- [x] 1. **Move status onto `booking_items`.** Add a migration that: adds
      `status` (same enum values as today's `bookings.status`, nullable),
      `approved_at`, `approved_by` (FK to `users`, nullable), `confirmed_at`,
      `confirmed_by` (FK to `users`, nullable), `weighed_at`,
      `cooking_started_at`, `est_ready_at`, `completed_at`, `reject_reason` to
      `booking_items`; adds `booking_item_id` (FK to `booking_items`,
      `cascadeOnDelete`) to `booking_status_logs`; drops all of the above from
      `bookings` (keep `dropoff_at`/`preferred_dropoff_at` there). Update
      `Booking` (drop the moved fillable/casts, `approver()`, `confirmer()`,
      `latestStatusLog()`); update `BookingItem` (add the moved
      fillable/casts, `approver()`, `confirmer()`, `statusLogs()`,
      `latestStatusLog()`); update `BookingStatusLog` (add `booking_item_id`
      to fillable, add `item(): BelongsTo<BookingItem>`).
      Change `BookingStatusEngine::transition()` to take a `BookingItem`
      instead of a `Booking` (write `$item->status`/save, then create the
      `booking_status_logs` row with both `booking_id` (via
      `$item->booking_id`) and `booking_item_id`). `isAllowed`/
      `initialStatusFor` are unchanged.
      Done when: `composer test -- --filter=BookingStatusEngineTest` passes
      unchanged, and `php artisan migrate:fresh` runs cleanly against the
      local test database (this is a destructive column move with no
      production data yet - `migrate:fresh`, not an incremental `migrate`, is
      the expected path locally).

- [x] 2. **Factories.** Remove `status` from `BookingFactory::definition()`
      (the column no longer exists on `bookings`). Add `status` (default
      `'pending_review'`) to `BookingItemFactory::definition()`.
      Done when: both factories reference only columns that exist on their
      model after step 1.

- [x] 3. **Customer booking creation, cancellation, and walk-ins.** Update
      `Customer\BookingController::store`/`cancel` and
      `Admin\WalkInController::storeRoasting`/`storeShop` to call
      `$statusEngine->transition($item, ...)` once per created item instead of
      once per booking (all items of one booking still move together, in the
      same transaction). Update `BookingResource` (customer) to expose a
      derived `status`: the shared status when every item's status matches,
      `null` once items have diverged (only possible from Cooking on - a
      customer never triggers cooking-onward transitions, but the field must
      stay correct once an admin does). Update `frontend/src/features/
      bookings/status.ts`'s `isCancellable` to derive that same "every item
      shares one status" value from `booking.items` instead of reading
      `booking.status`, and gate on it being non-null. Add `service_name`
      (via the item's service, `whenLoaded`) to `BookingStatusLogResource`;
      update `BookingDetailPage.tsx`'s status timeline to prefix each line
      with the service name when `booking.items.length > 1`.
      Done when: `composer test -- --filter=BookingManagementTest`,
      `--filter=MyBookingsTest`, and `--filter=AdminWalkInBookingTest` pass
      (rewritten where they assert on `booking.status` or a single
      `booking_status_logs` row per transition), and
      `npm run test -- status.test` passes.

- [x] 4. **Whole-booking admin actions.** Rewrite
      `Admin\BookingController::approve`/`reject`/`weighIn`/`confirmOrder`/
      `rejectOrder`/`noShow`/`cancel` to load `items` and transition every
      item in the same call (same guard rule as today - all of a booking's
      items must currently share the `from` status, which they always do
      before Cooking). Replace `AdminBookingResource`'s single `status` and
      moved timestamp/actor/`reject_reason` fields with an `items` array of a
      new `AdminBookingItemResource` (service_name, qty, est/final weight,
      rate, subtotal, status, approved_at, approved_by_name, confirmed_at,
      confirmed_by_name, weighed_at, cooking_started_at, est_ready_at,
      completed_at, reject_reason, waiting_minutes computed from that item's
      own latest status log). Keep `AdminBookingResource`'s booking-level
      fields (code, is_order, fulfillment, delivery_address, customer_name/
      phone, estimated_total, total_amount, paid_amount, balance,
      preferred_dropoff_at/preferred_pickup_at, dropoff_at, notes,
      status_logs, created_at) unchanged.
      Done when: `composer test -- --filter=AdminBookingApprovalTest`,
      `--filter=AdminBookingWeighInTest` pass (rewritten to assert on
      `booking->items->first()->status` etc. and on a `booking_status_logs`
      row per item).

- [x] 5. **Per-item fulfillment.** Replace the booking-scoped `start-cooking`/
      `ready`/`out-for-delivery`/`complete` routes and controller methods with
      item-scoped ones: `POST /admin/booking-items/{id}/start-cooking`,
      `/ready`, `/out-for-delivery`, `/complete` (same `module:bookings`
      middleware group, same `[0-9]{1,18}` id constraint). Each loads one
      `BookingItem` (`lockForUpdate`), guards the transition on that item's
      own status, and - for start-cooking - uses that item's own service
      `est_minutes` (no longer the max across the booking's items, since
      items now start independently). Return the item's parent
      `AdminBookingResource` (reload it) so the detail page refreshes with
      the item's new status in place.
      Done when: `AdminBookingFulfillmentTest` is rewritten for item-scoped
      routes and passes, including a new case proving two items on the same
      booking can be at different fulfillment stages at once (e.g. one
      `cooking`, the sibling still `confirmed`, then the first moves to
      `ready` while the second is still `cooking`).

- [x] 6. **Admin queue regrouping.** Replace `BookingController::
      QUEUE_STATUSES` with a `GROUPS` map (`draft` => pending_review,
      pending_confirmation, approved; `pending` => confirmed; `cooking` =>
      cooking; `ready` => ready, out_for_delivery; `completed` => completed;
      `cancelled` => rejected, cancelled, no_show). `counts()` returns one
      count per group, queried against `booking_items`. `index()` takes a
      `group` query param (replacing `status`), queries `booking_items`
      joined to `bookings` for the date filter and search, orders by each
      item's own latest status log, and returns one row per item via a new
      `AdminBookingQueueItemResource` (item id, booking_id, code, is_order,
      status, service_name, qty, est_weight_kg, final_weight_kg, subtotal,
      fulfillment, customer_name, customer_phone, waiting_minutes).
      Done when: `AdminBookingQueuesTest` is rewritten for the six groups and
      one-row-per-item shape, and passes.

- [x] 7. **Dashboard.** Update `DashboardController::bookingsByStatus`,
      `activeQueue`, and `topItems` to query `BookingItem::status` instead of
      `Booking::status` (drop the now-unnecessary `bookings` join in each).
      Response shapes are unchanged (`bookings_by_status` keeps the same 11
      raw-status keys; `active_queue` entries now represent one item each,
      same fields).
      Done when: `AdminDashboardTest` passes against the new query source.

- [x] 8. **Payment eligibility.** Update
      `BookingPaymentController::PAYMENT_ELIGIBLE_STATUSES` check to require
      every one of the booking's items to have a status in that set (a
      booking is payable once every item has reached Confirmed or later;
      an item still in Draft blocks payment, matching today's behavior for
      the common case where all items share one status).
      Done when: `AdminBookingPaymentTest` passes, including a case where one
      item has already reached `cooking` and a sibling is still `confirmed`
      (payable) versus one still `pending_review` (not payable).

- [x] 9. **Admin frontend.** In `admin-bookings/types.ts`: rename
      `QueueStatus` to `QueueGroup` (six values), update `QUEUE_TABS` to the
      six grouped labels, rename `parseQueueStatus` to `parseQueueGroup`
      (default `'draft'`). Split the list-row shape (new
      `AdminBookingQueueRow`, matching `AdminBookingQueueItemResource`) from
      the detail shape (`AdminBooking` keeps its booking-level fields plus
      `items: AdminBookingItem[]`, each item gaining `status`, `approved_at`,
      `approved_by_name`, `confirmed_at`, `confirmed_by_name`, `weighed_at`,
      `cooking_started_at`, `est_ready_at`, `completed_at`, `reject_reason`,
      `waiting_minutes`). Update `api.ts`/`hooks.ts`: `fetchAdminBookings`
      takes/returns the row shape with a `group` param; add
      `startCooking`/`markReady`/`markOutForDelivery`/`completeBooking`
      calls against `/admin/booking-items/{id}/...`. `AdminBookingsPage.tsx`:
      six group tabs, one table row per item (add a Service column). Add a
      shared `getCommonStatus(items)` helper (in `admin-bookings/types.ts`)
      used by `AdminBookingDetailPage.tsx` in place of `booking.status` for
      the whole-booking action panels (approve/reject/weigh-in/confirm-order/
      reject-order/no-show/cancel) and by `isAdminCancellable`; render
      start-cooking/cooking/complete actions once per item, keyed on that
      item's own status, calling the item-scoped mutations. Update
      `AdminLayout.tsx`'s booking sub-menu to the six grouped links.
      Done when: `npm run test` and `npm run lint` pass, including a
      rewritten `admin-bookings/types.test.ts`.

- [x] 10. **Final verify.** Run `composer test` and `npm run test` /
      `npm run lint` for the whole changed surface; grep for any remaining
      reference to `booking.status`/`bookings.status` outside `bookings`'s
      own untouched columns (`dropoff_at`, `preferred_dropoff_at`) to confirm
      nothing was missed.
      Done when: both suites are green.

- [x] 11. **Fix F-66 (P1, independent review blocker).** `isAdminCancellable`
      in `frontend/src/features/admin-bookings/types.ts` takes
      `Pick<AdminBooking, 'is_order' | 'items'>`, which requires full
      `AdminBookingItem` objects and breaks `types.test.ts`'s `{ status }`-only
      item literals (6 `TS2740` errors), failing the `tsc -b` step of
      `npm run build`. Narrow the parameter the same way
      `frontend/src/features/bookings/status.ts`'s `isCancellable` already
      does: `Pick<AdminBooking, 'is_order'> & { items: Pick<AdminBookingItem,
      'status'>[] }`.
      Done when: `cd frontend && npx tsc -p tsconfig.app.json --noEmit` and
      `npm run build` pass, and `npm run test` still passes.

- [x] 12. **Fix F-67 (P2, concurrency).** Item-level actions in
      `BookingItemController::transitionItem` lock only the `booking_items`
      row, while every whole-booking admin action (`approve`, `reject`,
      `weighIn`, `confirmOrder`, `rejectOrder`, `noShow`, `cancel`) locks the
      parent `bookings` row via `Booking::with('items')->lockForUpdate()`. A
      concurrent per-item transition and whole-booking action can therefore
      interleave and let one overwrite the other's item write (for example, a
      cancel reading an item as still `confirmed` while a `start-cooking` call
      moves it to `cooking`). Make `transitionItem` also lock the parent
      `Booking` row before locking the item, matching the whole-booking
      actions' lock target so the two paths serialize on the same row:
      read the item's `booking_id` unlocked, `Booking::lockForUpdate()
      ->findOrFail($bookingId)`, then `BookingItem::lockForUpdate()
      ->findOrFail($id)` and attach the already-locked booking via
      `setRelation('booking', $booking)` before the existing guard/transition
      logic.
      Done when: `AdminBookingFulfillmentTest` still passes unchanged, proving
      the added lock does not change any existing behavior.

- [x] 13. **Fix F-68 (P2, spec compliance).** Step 8's own `Done when` required
      an `AdminBookingPaymentTest` case proving mixed item statuses are
      evaluated per item (cooking + confirmed payable; cooking +
      pending_review not payable), but no such test was added. Add a
      `bookingWithItemStatuses(array $statuses, array $bookingAttributes =
      [])` helper alongside the existing `bookingWithItemStatus` and two new
      tests: one booking with items `['cooking', 'confirmed']` that pays
      successfully, and one with items `['cooking', 'pending_review']` that
      gets a 422 with no payment recorded.
      Done when: `composer test -- --filter=AdminBookingPaymentTest` passes,
      including the two new cases.

- [x] 14. **Fix F-69 (P3, UI regression).** `AdminBookingItemResource` already
      returns `approved_at`, `approved_by_name`, `weighed_at`, and
      `confirmed_by_name` per item, and the frontend type carries them, but
      `AdminBookingDetailPage.tsx`'s item block stopped rendering them (only
      `cooking_started_at`/`est_ready_at`/`completed_at` render today). Add an
      "Approved" line (`item.approved_at`, suffixed with `by
      {item.approved_by_name}` when present) and a "Weighed in" line
      (`item.weighed_at`, suffixed with `- confirmed by
      {item.confirmed_by_name}` when present) to each item's card, matching
      the wording the pre-feature booking-level version used, placed between
      the existing "Status" line and the "Cooking started" line.
      Done when: `npm run test` passes and a manual check of an approved item
      on the admin detail page shows its approver and, once weighed in, its
      confirmer.

## Files / areas

- `backend/database/migrations/` (new migration)
- `backend/app/Models/Booking.php`, `BookingItem.php`, `BookingStatusLog.php`
- `backend/database/factories/BookingFactory.php`, `BookingItemFactory.php`
- `backend/app/Services/Booking/BookingStatusEngine.php`
- `backend/app/Http/Controllers/Api/Admin/BookingController.php`,
  `DashboardController.php`, `BookingPaymentController.php`,
  `WalkInController.php`
- `backend/app/Http/Controllers/Api/Customer/BookingController.php`
- `backend/app/Http/Resources/AdminBookingResource.php`,
  `BookingItemResource.php` (new `AdminBookingItemResource.php`, new
  `AdminBookingQueueItemResource.php`), `BookingResource.php`,
  `BookingStatusLogResource.php`
- `backend/routes/api.php`
- `backend/tests/Unit/Booking/BookingStatusEngineTest.php` (verify only, no
  change expected), `backend/tests/Feature/Admin/*BookingApprovalTest.php`,
  `*WeighInTest.php`, `*FulfillmentTest.php`, `*QueuesTest.php`,
  `*DashboardTest.php`, `*PaymentTest.php`, `*WalkInBookingTest.php`,
  `backend/tests/Feature/Customer/BookingManagementTest.php`,
  `MyBookingsTest.php`
- `frontend/src/features/admin-bookings/types.ts`, `types.test.ts`, `api.ts`,
  `hooks.ts`, `AdminBookingsPage.tsx`, `AdminBookingDetailPage.tsx`
- `frontend/src/features/bookings/status.ts`, `status.test.ts`,
  `BookingDetailPage.tsx`
- `frontend/src/components/layouts/AdminLayout.tsx`

## Data / contracts

- `booking_items` gains: `status` (same 11-value enum as today's
  `bookings.status`, nullable), `approved_at` (timestamp, nullable),
  `approved_by` (FK `users`, nullable), `confirmed_at` (timestamp, nullable),
  `confirmed_by` (FK `users`, nullable), `weighed_at`, `cooking_started_at`,
  `est_ready_at`, `completed_at` (timestamps, nullable), `reject_reason`
  (string, nullable).
- `booking_status_logs` gains `booking_item_id` (FK `booking_items`,
  `cascadeOnDelete`, not nullable) alongside the existing `booking_id`; every
  write goes through `BookingStatusEngine::transition()`, one row per item.
- `bookings` drops: `status`, `approved_at`, `approved_by`, `confirmed_at`,
  `confirmed_by`, `weighed_at`, `cooking_started_at`, `est_ready_at`,
  `completed_at`, `reject_reason`. Keeps `dropoff_at`/`preferred_dropoff_at`
  (drop-off scheduling is still whole-booking).
- Admin queue groups (raw statuses grouped):
  `draft` = pending_review, pending_confirmation, approved;
  `pending` = confirmed; `cooking` = cooking; `ready` = ready,
  out_for_delivery; `completed` = completed; `cancelled` = rejected,
  cancelled, no_show.
- `GET /api/v1/admin/bookings/counts` -> `{draft, pending, cooking, ready,
  completed, cancelled}` (was one count per raw status).
- `GET /api/v1/admin/bookings?group=<draft|pending|cooking|ready|completed|
  cancelled>&search=&date=` -> one row per `booking_item` (was one row per
  booking); `status` query param is renamed to `group`.
- `POST /api/v1/admin/booking-items/{id}/start-cooking|ready|
  out-for-delivery|complete` replace the former
  `/api/v1/admin/bookings/{id}/...` routes for the same four actions.
- Payment eligibility: a booking accepts payment when every one of its items
  has a status in `{confirmed, cooking, ready, out_for_delivery, completed}`
  (unchanged rule, just evaluated per item instead of on the booking).
- Customer `BookingResource.status`: the shared status across all of a
  booking's items, or `null` once they've diverged (Cooking-on only).

## Testing

- Backend: PHPUnit feature tests listed under Files / areas, rewritten for
  the moved columns and item-scoped routes; the `BookingStatusEngineTest`
  unit test needs no change since `isAllowed`/`initialStatusFor` keep their
  existing signature.
- Frontend: Vitest for `admin-bookings/types.test.ts` (grouping helpers) and
  `bookings/status.test.ts` (`isCancellable` against an items array).
- No new browser-test coverage - this project has no configured Browser
  tests command.

## Notes for the AI

- This is a schema-moving refactor with no independent user-facing slices to
  split into separate build-plan features - every consumer of
  `bookings.status` breaks together and must be updated together. Steps are
  ordered to keep each layer (schema -> engine -> booking-level actions ->
  item-level actions -> queues -> dashboard -> payments -> frontend)
  individually testable, not to keep the app deployable mid-feature.
  `workflow.stepReview` is `feature`, so there is one combined review at the
  end regardless.
- Local dev/test databases hold no real customer data yet - use
  `php artisan migrate:fresh` (already how `composer test` resets the test
  database per `phpunit.xml`) rather than trying to write a safe incremental
  backfill migration.
- Every current test that does `Booking::factory()->create(['status' =>
  'x'])` needs to instead create the booking, then
  `BookingItem::factory()->for($booking)->create(['status' => 'x', ...])`
  (with a `service_id`), and assert against the item's status instead of the
  booking's. This mechanical rewrite touches most of the files listed above.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":22152,"specSha256":"6a20a4e1f84ea319296bca08c41cf422603f51c459b68e4c54b5487424480cfc","branch":"refs/heads/feature/per-item-booking-status","head":"0d0ca7b7b76d922081cf856ffa1e563adca11ea9","baseRef":"refs/heads/master","baseCommit":"bd879db1dd85f54308e08fc8987c7105dff95d3e","sourceTree":"2b5f614d06a4a1d53544b378637d08b522abe504","absentOptional":[]} -->

## Findings

### 18/F-66 [P1] closed - Frontend typecheck fails, so `npm run build` is broken by the rewritten admin-bookings test

**File:** frontend/src/features/admin-bookings/types.ts:119; frontend/src/features/admin-bookings/types.test.ts:30
**Found:** 2026-09-26 by /audit independent (scope: current; lens: quality/tests)
**Why it matters:** `isAdminCancellable` now takes `Pick<AdminBooking, 'is_order' | 'items'>`, which requires full `AdminBookingItem[]`, but the new `isAdminCancellable` tests pass `items: [{ status: '...' }]`. `tsconfig.app.json` includes all of `src`, so `npx tsc -p tsconfig.app.json --noEmit` reports 6 `TS2740` errors (types.test.ts lines 30, 34, 38, 42, 49 twice), and `npm run build` (`tsc -b && vite build`) fails on this checkpoint. Vitest and oxlint do not typecheck, so the spec's `npm run test`/`npm run lint` done-whens stayed green and hid it. The base commit's test only called `parseQueueStatus` with strings, so this delta introduced the failure.
**Suggested fix:** Narrow the parameter the way `bookings/status.ts` already does, for example `booking: Pick<AdminBooking, 'is_order'> & { items: Pick<AdminBookingItem, 'status'>[] }`, then rerun `npx tsc -p tsconfig.app.json --noEmit` (or `npm run build`). Current requirement lost: None.
**Resolution:** Fixed 2026-09-26 by /implement (feature 18 build step 11). `isAdminCancellable` now takes `Pick<AdminBooking, 'is_order'> & { items: Pick<AdminBookingItem, 'status'>[] }`, exactly as suggested. `npx tsc -p tsconfig.app.json --noEmit` and `npm run build` both pass; `npm run test` still passes (44/44). Closed 2026-09-27 by /audit independent (review of `0d0ca7b`; scope: current; all lenses). Re-examined `types.ts:118-125`: the parameter is `Pick<AdminBooking, 'is_order'> & { items: Pick<AdminBookingItem, 'status'>[] }`, the only caller (`AdminBookingDetailPage.tsx:435`) passes a full `AdminBooking`, and the `{ status }`-only test literals now typecheck. Reran `npx tsc -p tsconfig.app.json --noEmit` (exit 0) and `npm run build` (exit 0, only the pre-existing chunk-size warning). No new defect introduced.

### 18/F-67 [P2] closed - Item-level fulfillment no longer shares the booking row lock, so a concurrent cancel can overwrite a started item

**File:** backend/app/Http/Controllers/Api/Admin/BookingItemController.php:77; backend/app/Http/Controllers/Api/Admin/BookingController.php:274; backend/app/Http/Controllers/Api/Customer/BookingController.php:45
**Found:** 2026-09-26 by /audit independent (scope: current; lens: quality/security)
**Why it matters:** Before this delta every status transition locked the same `bookings` row, so transitions on one booking were serialized. Now whole-booking actions lock only the booking row (`Booking::with('items')->lockForUpdate()`, whose eager-loaded items are read without a lock), while `BookingItemController::transitionItem` locks only the item row. Under Postgres READ COMMITTED, an admin or customer cancel that reads the items as `confirmed` while an admin starts cooking one of them then writes `cancelled` over the now-`cooking` item. The engine check uses the stale in-memory `confirmed`, so it passes. The item ends `cooking -> cancelled`, which `BookingStatusEngine::TRANSITIONS` forbids, and a shop order's stock is released for an item already cooking. This needs two actors acting within the same short window, so it is unlikely, but it breaks the invariant the engine exists to protect. It was not reproduced at runtime.
**Suggested fix:** In `transitionItem`, lock the parent booking before the item, for example `Booking::lockForUpdate()->findOrFail($item->booking_id)` after reading the item's `booking_id`, or lock the booking first and then the item. That serializes item and whole-booking transitions on one booking again. Alternatively load `items` with `lockForUpdate()` in the whole-booking actions. Current requirement lost: None.
**Resolution:** Fixed 2026-09-26 by /implement (feature 18 build step 12). `transitionItem` now reads the item's `booking_id` unlocked, locks the parent `Booking` row (`Booking::lockForUpdate()->findOrFail($bookingId)`), then locks the item row and attaches the already-locked booking via `setRelation`, matching the lock target every whole-booking action already takes. `AdminBookingFulfillmentTest` (23 tests) still passes unchanged. Closed 2026-09-27 by /audit independent (review of `0d0ca7b`; scope: current; all lenses). Re-examined `BookingItemController.php:76-102`: it reads the immutable `booking_id` unlocked, 404s when missing, then locks `bookings` before `booking_items`. Every other status writer (admin `BookingController` whole-booking actions, customer `BookingController::cancel`, `BookingPaymentController::store`) locks the same booking row first, so item and whole-booking transitions on one booking serialize again, and the lock order (booking, then item or services) is consistent, so no new deadlock path. The `ready`/`outForDelivery` guards now read `fulfillment` from the locked booking instance. The missing-id path is covered by `test_a_missing_or_non_numeric_id_is_not_found`. No new defect introduced. The race itself is not runtime-tested (not practical in this suite).

### 18/F-68 [P2] closed - Payment tests omit the spec's mixed item-status cases

**File:** backend/tests/Feature/Admin/AdminBookingPaymentTest.php:48
**Found:** 2026-09-26 by /audit independent (scope: current; lens: tests)
**Why it matters:** Build step 8's done-when requires `AdminBookingPaymentTest` to include a booking with one item `cooking` and a sibling `confirmed` (payable) versus a sibling still `pending_review` (not payable). The test's `bookingWithItemStatus` helper creates exactly one item, and no case seeds two items with different statuses. The new `every()` rule in `BookingPaymentController::store` is therefore only exercised with single-item bookings, so a regression to "any item eligible" or "first item eligible" would pass. The code reads correctly, so this is a coverage gap against an explicit done-when, not a known defect.
**Suggested fix:** Add the two mixed-item cases the spec names: `cooking` + `confirmed` returns 200, and `cooking` + `pending_review` returns 422 with no payment row. Current requirement lost: None.
**Resolution:** Fixed 2026-09-26 by /implement (feature 18 build step 13). Added a `bookingWithItemStatuses` helper and the two named cases: `cooking`+`confirmed` pays successfully, `cooking`+`pending_review` returns 422 with no payment row. `AdminBookingPaymentTest` passes (11 tests). Closed 2026-09-27 by /audit independent (review of `0d0ca7b`; scope: current; all lenses). Re-examined `AdminBookingPaymentTest.php:62-109`: `bookingWithItemStatuses` seeds one item per status on one booking; the `cooking`+`confirmed` case asserts 200, the paid amount, and a `paid` payment row; the `cooking`+`pending_review` case asserts 422 on `status` and no payment row. A regression to "any item" or "first item" eligibility would now fail the second case. Full `composer test` passes. No new defect introduced.

### 18/F-69 [P3] closed - Admin detail page no longer shows approver, weigh-in, or confirmer details

**File:** frontend/src/features/admin-bookings/AdminBookingDetailPage.tsx:469
**Found:** 2026-09-26 by /audit independent (scope: current; lens: quality)
**Why it matters:** The base page showed "approved by X" next to the drop-off line and "Weighed in: <time> - confirmed by Y". The delta moved those fields onto each item (`approved_at`, `approved_by_name`, `weighed_at`, `confirmed_at`, `confirmed_by_name`), and the backend and `AdminBookingItem` type still carry them, but the per-item block renders only status, reject reason, cooking start, estimated ready, and completed. Staff lose visible accountability data that was shown before. The spec did not explicitly remove it.
**Suggested fix:** Render `weighed_at`/`confirmed_by_name` and `approved_by_name` in each item block, or once at booking level from the first item, since these never diverge before Cooking. Alternatively, drop the unused fields from the resource and type if the loss is intended. Current requirement lost: None.
**Resolution:** Fixed 2026-09-26 by /implement (feature 18 build step 14). Added "Approved" (with approver name) and "Weighed in" (with confirmer name) lines to each item's card in `AdminBookingDetailPage.tsx`, between the Status line and the Cooking-started line, matching the pre-feature booking-level wording. `npm run test` passes. Closed 2026-09-27 by /audit independent (review of `0d0ca7b`; scope: current; all lenses). Re-examined `AdminBookingDetailPage.tsx:472-483`: each item card renders "Approved: <time> by <name>" and "Weighed in: <time> - confirmed by <name>", fed by `AdminBookingItemResource`'s `approved_at`/`approved_by_name`/`weighed_at`/`confirmed_by_name` with `items.approver`/`items.confirmer` eager-loaded in `detailEagerLoads()`. This restores the base page's accountability data (the base page also showed the confirmer only on the weigh-in line, so shop-order confirmers were never shown; parity holds). Typecheck, lint, and build pass. No new defect introduced. The spec's manual-check half of the step 14 done-when was not run here (Check not required for this receipt).

## Independent review

**Status:** passed
**Target commit:** 0d0ca7b7b76d922081cf856ffa1e563adca11ea9
**Base commit:** bd879db1dd85f54308e08fc8987c7105dff95d3e
**Base ref:** master
**Spec hash:** 6a20a4e1f84ea319296bca08c41cf422603f51c459b68e4c54b5487424480cfc
**Prepared by:** claude
**Builder model:** claude-sonnet-5
**Requested reviewer:** claude
**Requested model:** claude-opus-5-5
**Requested execution:** automatic
**Requested at:** 2026-09-26T16:46:14Z
**Workflow:** regular
**Check required:** no
**Reviewer adapter:** claude
**Reviewer model:** claude-opus-5-5
**Reviewer context:** fresh subagent
**Actual execution:** automatic
**Reviewed at:** 2026-09-27T01:05:00Z
**Scope:** current
**Lenses:** quality, security, performance, tests
**Verdict:** passed
**Check result:** not-required

### Commands

- `git rev-parse HEAD` / `git rev-parse master` / `git merge-base master 0d0ca7b`: pass (match Target, Base ref, and Base commit)
- `Get-FileHash blueprint/context/current-feature.md -Algorithm SHA256`: pass (matches Spec hash)
- `git diff 0d0ca7b --stat` plus untracked listing: pass (only findings.md and review.md differ)
- `cd backend && composer test`: first run fail (1 of 259, pre-existing time-boundary flake in `AdminWalkInBookingTest`, see F-71); immediate rerun pass (259/259)
- `cd frontend && npx tsc -p tsconfig.app.json --noEmit`: pass
- `cd frontend && npm run lint`: pass (0 errors, 5 pre-existing warnings in files outside this delta)
- `cd frontend && npm run test -- --run`: pass (44/44)
- `cd frontend && npm run build`: pass (pre-existing chunk-size warning only)

### Evidence

- Reviewed the complete `bd879db..0d0ca7b` delta (48 files): migration, `Booking`/`BookingItem`/`BookingStatusLog` models, `BookingStatusEngine`, admin `BookingController`/`BookingItemController`/`BookingPaymentController`/`DashboardController`/`WalkInController`, customer `BookingController`/`OrderController`, the four changed and two new resources, `routes/api.php`, factories, rewritten feature tests, and the admin-bookings, bookings, and layout frontend files.
- F-66: `types.ts:118-125` narrows `isAdminCancellable`'s parameter; typecheck and build now pass. Closed.
- F-67: `BookingItemController.php:76-102` locks the parent booking before the item, matching every other status writer's lock target and order. Closed.
- F-68: `AdminBookingPaymentTest.php:62-109` adds the two mixed item-status cases the spec names. Closed.
- F-69: `AdminBookingDetailPage.tsx:472-483` renders approver and weigh-in/confirmer lines per item, matching base wording. Closed.
- Security: all item routes stay inside the `auth:sanctum` + admin role + `module:bookings` group with the `[0-9]{1,18}` id constraint; customer show/cancel still scope by `customer_id`; the queue search keeps parameterized `ilike`/`whereRaw` bindings.

### Findings at review time

- F-66 [P1] closed, F-67 [P2] closed, F-68 [P2] closed, F-69 [P3] closed
- F-70 [P3] open - no backend test for whole-booking actions or customer status on a diverged booking
- F-71 [P3] open - walk-in tests flake on a second boundary against `after_or_equal:now` (observed once during this review)
- F-72 [P3] open - Completed/Cancelled tab counts are all-time while the list is date-scoped

### Remaining risk

- Check not run (not required for this receipt), so no browser or manual evidence; step 14's manual done-when for F-69 was not exercised.
- The F-67 race is closed by code inspection only; there is no concurrent runtime test.
- `booking_status_logs.booking_item_id` (like the existing `booking_id`) has only an FK and no explicit index, and Postgres does not index FK columns automatically. The queue's per-row latest-log ordering subquery and `latestOfMany` eager load may slow down as logs grow (not measured).
- Whole-booking admin actions lazy-load `$item->booking` once per item inside `BookingStatusEngine::transition` (small N+1, bounded by items per booking).
- The destructive migration requires `migrate:fresh`; per the spec there is no production data yet, and any environment with existing rows would fail on the non-null `booking_item_id`.
- Backend suite green result depends on F-71's flake not triggering.
