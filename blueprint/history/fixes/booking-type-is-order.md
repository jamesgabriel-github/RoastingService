# Fix: Booking type as is_order boolean

**Type:** Fix
**Status:** verified
**Branch:** fix/booking-type-is-order

## The problem

`bookings.source_type` is a two-value enum (`customer_supplied` | `shop_supplied`)
that stands for "bring your own" vs. "shop item" bookings. It's read and
displayed in ~20 backend files and ~10 frontend files, including the booking
status engine (which keys the two different status-transition flows off it)
and several admin/customer-facing badges that currently render the literal
labels "Bring your own" / "Shop" / "Shop order".

The business asked for this to become a plain order flag instead of a named
source-type enum: **is a booking an order (shop item) or not (customer
supplied)?** Rather than keep the enum and just relabel it, replace it with a
boolean `is_order` column: `true` = shop item (order), `false` = customer
supplied (not an order).

## The fix

Rename `bookings.source_type` (`customer_supplied` | `shop_supplied`) to
`bookings.is_order` (boolean), and update every reader/writer of that column
plus the booking-type badges that display it. No other schema or status-flow
behavior changes: the same two transition tables in `BookingStatusEngine` stay,
just keyed by `true`/`false` instead of the two enum strings.

Mapping used everywhere below:
- `source_type: 'shop_supplied'` -> `is_order: true` ("Is order")
- `source_type: 'customer_supplied'` -> `is_order: false` ("Not order")

Out of scope (do not touch):
- `services.allow_customer_supplied` / `services.allow_shop_supplied` - a
  different, unrelated boolean pair on `services`, not on `bookings`.
- Product-flow copy that names the booking flow itself rather than tagging a
  record's type: `NewBookingPage.tsx`'s "Bring your own booking" heading/body,
  and `WalkInBookingPage.tsx`'s "Bring your own" flow-toggle button label.
- `blueprint/history/**`, `blueprint/project-plan.md`, `blueprint/build-plan.md`,
  `roastingservice-project-plan.md`, `roastingservice-build-plan.md` - historical
  and planning records, not live code.

## Build steps

### Step 1 - backend: rename the column and everything reading it

- New migration: add `is_order` boolean, backfill from `source_type`
  (`shop_supplied` -> `true`, `customer_supplied` -> `false`), make it
  not-nullable, drop `source_type`. Write a matching `down()` that reverses it
  (recreate the enum, backfill from `is_order`, drop `is_order`).
- `app/Models/Booking.php`: replace `source_type` with `is_order` in the
  `#[Fillable]` list; no cast needed beyond Eloquent's automatic boolean cast
  for the column (add an explicit `'is_order' => 'boolean'` cast for clarity).
- `app/Services/Booking/BookingStatusEngine.php`: re-key `TRANSITIONS` from
  `'customer_supplied'`/`'shop_supplied'` to `false`/`true`; change
  `initialStatusFor(string $sourceType)` to `initialStatusFor(bool $isOrder)`,
  `isAllowed(string $sourceType, ...)` to `isAllowed(bool $isOrder, ...)`, and
  `transition()` to read `$booking->is_order`. Update the class/method doc
  comments that talk about "source type" to talk about the order flag instead.
- `app/Http/Resources/BookingResource.php`, `AdminBookingResource.php`,
  `PaymentResource.php`: change the `'source_type' => $this->source_type` (or
  `$this->booking->source_type`) line to `'is_order' => $this->is_order`.
- `app/Http/Controllers/Api/Customer/BookingController.php`: `store()` creates
  with `'is_order' => false` and calls `initialStatusFor(false)`; `cancel()`
  reads `$booking->is_order` instead of comparing `source_type` to
  `'shop_supplied'`, and passes `$booking->is_order` to `isAllowed()`.
- `app/Http/Controllers/Api/Customer/OrderController.php`: `store()` creates
  with `'is_order' => true` and calls `initialStatusFor(true)`.
- `app/Http/Controllers/Api/Admin/WalkInController.php`: `storeRoasting()`
  creates with `'is_order' => false`; `storeShop()` creates with
  `'is_order' => true`.
- `app/Http/Controllers/Api/Admin/BookingController.php`: `guardTransition()`
  (bring-your-own-only actions) checks `! $booking->is_order`;
  `guardOrderTransition()` (shop-order-only actions) checks `$booking->is_order`;
  `guardAnyTransition()` and `cancel()` pass/read `$booking->is_order` instead
  of the `source_type` string. Update the three guard methods' doc comments.
- `app/Http/Controllers/Api/Admin/DashboardController.php`: `salesSince()`
  groups by `bookings.is_order` instead of `bookings.source_type` and returns
  keys `not_order` / `is_order` / `total` instead of
  `customer_supplied`/`shop_supplied`/`total`; `activeQueue()` returns
  `'is_order' => $booking->is_order` instead of `'source_type' => ...`.
- `database/factories/BookingFactory.php`: default state becomes
  `'is_order' => false`.
- Sweep `backend/tests/**` for every `source_type` / `customer_supplied` /
  `shop_supplied` reference (factory overrides, JSON assertions, direct engine
  calls in `BookingStatusEngineTest.php`) and update to the `is_order`
  boolean per the mapping above.

Done when: `composer test` is green with no remaining `source_type` reference
under `backend/app` or `backend/tests`.

### Step 2 - frontend: rename the field and the type-tag labels

- `frontend/src/features/bookings/types.ts`,
  `frontend/src/features/admin-bookings/types.ts`,
  `frontend/src/features/dashboard/types.ts` (`ActiveQueueEntry`),
  `frontend/src/features/admin-payments/types.ts`: change
  `source_type: 'customer_supplied' | 'shop_supplied'` to `is_order: boolean`.
- `frontend/src/features/dashboard/types.ts` `SalesPeriod`: rename
  `customer_supplied`/`shop_supplied` fields to `not_order`/`is_order`
  (matches the backend response rename in Step 1).
- `frontend/src/features/bookings/status.ts` and
  `frontend/src/features/admin-bookings/types.ts`'s `CANCELLABLE_STATUSES` +
  `isAdminCancellable`/`isCancellable`: re-key the lookup table by
  `true`/`false` instead of the two enum strings, and index it with
  `booking.is_order`.
- Update every booking-type badge/label that reads the old field, replacing
  the displayed text with "Is order" / "Not order":
  - `frontend/src/features/admin-bookings/AdminBookingsPage.tsx:79` (queue
    table type column, and the amount-source ternary on the next line).
  - `frontend/src/features/admin-bookings/AdminBookingDetailPage.tsx:394,404`
    (`isRoasting` derived flag and its label - rename the flag to something
    like `isOrder = booking.is_order`).
  - `frontend/src/features/bookings/MyBookingsPage.tsx:27,34` (list row type
    label and the amount-source ternary).
  - `frontend/src/features/bookings/BookingDetailPage.tsx:25,31` (`isRoasting`
    derived flag and its label).
  - `frontend/src/features/dashboard/DashboardPage.tsx`'s `SalesCard`: labels
    "Bring your own" / "Shop" become "Not order" / "Is order", reading
    `period.not_order` / `period.is_order`.
- `frontend/src/features/bookings/status.test.ts`: update the boolean-keyed
  cases to match.

Done when: `npm run lint`, `npm run test`, and `npm run build` are green with
no remaining `source_type` reference under `frontend/src`.

## Verify

- Backend: `composer test` passes.
- Frontend: `npm run lint`, `npm run test`, `npm run build` pass.
- Manual: as an admin, open the booking queues and confirm the type column now
  shows "Is order" for a shop order and "Not order" for a bring-your-own
  booking, in both the queue table and a booking's detail page; check the
  sales-dashboard cards show the same two labels; as a customer, confirm
  "My bookings" still shows the right type label and cancel/status behavior is
  unchanged for both booking types.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":7612,"specSha256":"f8abfcd1c7d280a70e29c82714e2dae3ec675ac2c099c7a083d34e24029df65c","branch":"refs/heads/fix/booking-type-is-order","head":"0c87974e3236a583f71d294888df4fe96433860f","baseRef":"refs/heads/master","baseCommit":"7eed3504e9fdb7264ae80c92d636ec5bd0dc21b1","sourceTree":"3e0e993c6e720b99bd525f9bb891e219f9d898a6","absentOptional":[]} -->

## Findings

### booking-type-is-order/F-64 [P1] closed - Admin booking queue table lost its "Is order"/"Not order" type column and now renders one row per item instead of per booking

**File:** frontend/src/features/admin-bookings/AdminBookingsPage.tsx:65,72-90; backend/app/Http/Controllers/Api/Admin/BookingController.php:55
**Found:** 2026-09-26 by /audit independent (scope: current; lens: quality/tests)
**Why it matters:** `blueprint/context/current-feature.md`'s Step 2 names this exact file as an in-scope rename target only: "`AdminBookingsPage.tsx:79` (queue table type column, and the amount-source ternary on the next line)," and the spec's Verify section requires "the type column now shows 'Is order' for a shop order and 'Not order' for a bring-your-own booking, in both the queue table and a booking's detail page." The shipped diff instead deleted the `Type` column and its `is_order`/`source_type` ternary entirely, replacing it with an `Item` column, and changed the table from one row per booking to `data.data.flatMap((booking) => (booking.items ?? []).map(...))`, one row per booking item, with the `Total` column now showing `item.subtotal` instead of the booking-level `estimated_total`/`total_amount`. The companion backend change (`BookingController.php:55`, adding `'items.service'` to the admin `index()` eager load) exists only to support this restructuring and is likewise outside the spec's described backend Step 1 changes. There is now no order/type indicator anywhere in the queue table, so the spec's own manual verify step for the queue table cannot pass as written; this was confirmed by reading the shipped file, not inferred. A booking with an empty `items` collection (for example a shop order whose only item was later removed, if that ever becomes reachable) would also silently disappear from the queue entirely, since `flatMap` over an empty array yields zero rows and the page's only empty-state check is `data.data.length === 0` on the booking list, not the flattened row list.
**Suggested fix:** Restore one row per booking with a `Type` column rendering `booking.is_order ? 'Is order' : 'Not order'` and the existing amount ternary keyed off `is_order`, matching the pattern already used correctly in `AdminBookingDetailPage.tsx`, `MyBookingsPage.tsx`, and `BookingDetailPage.tsx`. Revert the `'items.service'` eager load in `BookingController::index` if nothing else needs it. If a genuine line-item breakdown for the queue is wanted, raise it as its own reviewed feature rather than folding it into this rename fix. Current requirement lost: none by reverting; the current shipped state is what loses the spec's required queue-table type indicator.
**Resolution:** Repaired 2026-09-26 during `/complete` on `fix/booking-type-is-order`: `AdminBookingsPage.tsx` restored to one row per booking with a `Type` column rendering `booking.is_order ? 'Is order' : 'Not order'` and the amount ternary keyed off `is_order` (`total_amount` for an order, `estimated_total` otherwise). Reverted the unrequested `'items.service'` eager load in `BookingController::index` back to `['customer', 'latestStatusLog']`. `composer test` (250/250) and the frontend build/test suite were re-run and pass. Awaiting re-review to close. Closed 2026-09-26 by /audit independent (fresh re-review of `0c87974`; scope: current; all lenses). Read the shipped `AdminBookingsPage.tsx:62-90` directly: the table is one `<tr>` per `data.data.map((booking) => ...)`, the `Type` column renders `booking.is_order ? 'Is order' : 'Not order'`, and the `Total` column renders `formatCurrency(booking.is_order ? (booking.total_amount ?? 0) : booking.estimated_total)`, matching the mapping and the pattern already used in `AdminBookingDetailPage.tsx`/`MyBookingsPage.tsx`/`BookingDetailPage.tsx`. Diffed `BookingController.php:55` against the pre-regression base (`7eed350`) byte-for-byte: `->with(['customer', 'latestStatusLog'])` is restored exactly, with no other change to `index()`. No empty-items disappearing-row risk remains, since the table no longer flattens by item. No new defect introduced by the repair. `composer test` re-run: 250/250 passed. Frontend `npm run test`: 33/33 passed. `npm run build`: typecheck and build succeeded.

### booking-type-is-order/F-65 [P1] accepted - Walk-in booking-type toggle was rewritten, removing the exact "Bring your own"/"Shop items" labels the spec named as out of scope

**File:** frontend/src/features/admin-bookings/WalkInBookingPage.tsx:214-229
**Found:** 2026-09-26 by /audit independent (scope: current; lens: quality)
**Why it matters:** `current-feature.md`'s Out of scope section explicitly lists "`WalkInBookingPage.tsx`'s 'Bring your own' flow-toggle button label" as something not to touch, grouped with `NewBookingPage.tsx`'s heading as "Product-flow copy that names the booking flow itself rather than tagging a record's type." The shipped diff replaced the two-button toggle (labeled "Bring your own" and "Shop items") with a single checkbox labeled "Is order (customer is buying a shop item)" plus a new helper line "Leave unchecked for a bring-your-own booking," removing both named-out-of-scope labels and changing the control's interaction pattern without being asked. Reading `selectBookingType` and the surrounding form state confirms the underlying `bookingType` value and behavior (`'roasting'`/`'shop'`) are unchanged, and no test asserts this control's copy, so this is not a functional break, but it is a direct, verifiable violation of the spec's own explicit boundary rather than a judgment call.
**Suggested fix:** Revert this control to the original two-button "Bring your own" / "Shop items" toggle with its original labels, and confine this fix's frontend changes to the files and lines the spec enumerates. If relabeling this control is wanted, get it approved as its own explicit decision rather than bundling it into a mechanical rename fix. Current requirement lost: none; this restores wording the spec explicitly protected.
**Resolution:** Accepted 2026-09-26 by the user's explicit decision during `/complete` on `fix/booking-type-is-order`: shown this exact deviation (the toggle-to-checkbox rewrite and the removed "Bring your own"/"Shop items" labels) and asked whether to revert to the spec's original toggle or keep the new checkbox; the user chose to keep the checkbox as shipped. Re-examined 2026-09-26 by /audit independent (fresh re-review of `0c87974`; scope: current; all lenses), checking only for a new functional defect in the control itself, not re-litigating the accepted wording/control-type decision. `WalkInBookingPage.tsx:207-224`: `selectBookingType` is unchanged by this diff and still sets both `bookingType` and resets `items` to one blank row; the checkbox's `checked={bookingType === 'shop'}` and `onChange` correctly call it with `'shop'`/`'roasting'`. The raw `<input type="checkbox">` wrapped in a `<label>` also matches the project's existing pattern for boolean toggles (`ServicesPage.tsx:254-256,269-272`'s `allow_customer_supplied`/`allow_shop_supplied` checkboxes), so it is not a new component-consistency drift. No new defect found; status remains `accepted`.

## Independent review

**Status:** passed
**Target commit:** 0c87974e3236a583f71d294888df4fe96433860f
**Base commit:** 7eed3504e9fdb7264ae80c92d636ec5bd0dc21b1
**Base ref:** master
**Spec hash:** f8abfcd1c7d280a70e29c82714e2dae3ec675ac2c099c7a083d34e24029df65c
**Prepared by:** claude
**Builder model:** claude-sonnet-5
**Requested reviewer:** claude
**Requested model:** claude-sonnet-5
**Requested execution:** automatic
**Requested at:** 2026-09-26T02:38:54Z
**Workflow:** regular
**Check required:** no
**Reviewer adapter:** claude
**Reviewer model:** claude-sonnet-5
**Reviewer context:** fresh subagent
**Actual execution:** automatic
**Reviewed at:** 2026-09-26T11:00:00Z
**Scope:** current
**Lenses:** quality, security, performance, tests
**Verdict:** passed
**Check result:** not-required

### Commands

- `git rev-parse HEAD` / `git merge-base master HEAD` / `sha256sum blueprint/context/current-feature.md` / `git status --porcelain` / `git diff --name-status HEAD`: pass (confirmed HEAD == Target commit, merge-base == Base commit, spec hash match, only `blueprint/context/review.md` differs from the target)
- `git diff 7eed350..0c87974` (full stat + per-area content, backend/app, backend/database, backend/tests, frontend/src): pass (read in full)
- `composer test` (backend): pass - 250/250, 1091 assertions
- `npm run lint` (frontend, oxlint): pass - only pre-existing warnings unrelated to this diff (react-compiler incompatible-library notices on `ServicesPage.tsx`, `NewShopOrderPage.tsx`, `NewBookingPage.tsx`, `WalkInBookingPage.tsx`; a fast-refresh notice on `components/ui/button.tsx`), none touching `is_order`/`source_type` code
- `npm run test -- --run` (frontend, Vitest): pass - 33/33
- `npm run build` (frontend, tsc -b + vite build): pass - typecheck and production build succeeded
- `grep` sweep for `source_type`/`customer_supplied`/`shop_supplied` under `backend/app`, `backend/tests`, `frontend/src`: pass - zero remaining references to the booking column or its old enum values (all remaining hits are the unrelated, explicitly out-of-scope `services.allow_customer_supplied`/`allow_shop_supplied` pair)
- `grep` sweep for `is_order` under `backend`: pass - exactly the 21 files the spec's Step 1/2 file list names, plus the new migration

### Evidence

- Full `<base>..<target>` delta read directly (38 files, +392/-165): backend controllers, resources, model, status engine, factory, new migration, and their tests; frontend types, status helpers, and every booking-type badge/label page
- `BookingStatusEngine::TRANSITIONS` re-keyed `0`/`1` (PHP normalizes a `bool` array key to `0`/`1`), `initialStatusFor(bool)`/`isAllowed(bool, ...)` signatures, and `transition()` all verified against the two unchanged transition tables and cross-checked against `BookingStatusEngineTest.php`'s 7 boolean-keyed cases (all pass)
- `Admin/BookingController.php` guard methods (`guardTransition`, `guardOrderTransition`, `guardAnyTransition`, `cancel`) verified line-by-line against the pre-rename logic for equivalence (`is_order` true == old `shop_supplied`, false == old `customer_supplied`)
- New migration `2026_09_26_000000_replace_bookings_source_type_with_is_order.php` read in full: `up()` adds nullable `is_order`, backfills from `source_type` per the spec's mapping, sets `NOT NULL`, drops `source_type`; `down()` exactly reverses it (recreates the enum, backfills, restores `NOT NULL`, drops `is_order`); the original `create_bookings_table` migration confirms `source_type` was non-nullable, matching `down()`'s restored constraint
- F-64 repair verified by direct diff against both the regression commit (`60cd762`) and the original pre-regression base (`7eed350`): `AdminBookingsPage.tsx` is one `<tr>` per booking with `Type` rendering `booking.is_order ? 'Is order' : 'Not order'` and the amount ternary `booking.is_order ? (booking.total_amount ?? 0) : booking.estimated_total`; `BookingController::index()`'s eager load (`['customer', 'latestStatusLog']`) is byte-identical to the pre-regression base
- F-65 checkbox re-checked for new defects only (wording/control-type deviation is an already-accepted decision, out of scope to re-litigate): `selectBookingType` is unchanged by this diff, the checkbox's `checked`/`onChange` wiring correctly drives it, and the raw `<input type="checkbox">` pattern matches the project's existing `ServicesPage.tsx` boolean-toggle convention (no new drift)
- Dashboard sales aggregation (`DashboardController::salesSince`) re-keyed to `bookings.is_order::int`/`not_order`/`is_order`/`total` traced end to end through `AdminDashboardTest.php`'s exact-value assertions (all pass); the pre-existing float-arithmetic pattern (F-62) is unchanged by this diff, only the grouping key and output keys changed

### Findings

- F-64: closed (repair verified correct and complete against the fresh review; no new defect introduced)
- F-65: accepted status unchanged; re-examined for new defects, none found
- None new

### Remaining risk

- Pre-existing open findings unrelated to this diff (F-04, F-08 through F-63 excluding F-64/F-65) are untouched by this delta and remain at their recorded status; none are P0/P1
- Manual browser verification of the queue table, detail page, dashboard cards, and My Bookings labels (the spec's Verify section) was not performed in this pass; Check was not required for this request, and no automated equivalent covers the rendered visual output beyond the frontend unit/build evidence above
