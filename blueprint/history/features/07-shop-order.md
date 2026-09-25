# Feature: Shop order

**From build-plan:** feature 7
**Build attempt:** 1
**Status:** verified
**Branch:** `feature/shop-order`

## Goal

A logged-in customer orders one or more items from the shop's own stock: picks
shop-supplied services, enters a quantity per piece, chooses pickup or
delivery, adds an optional note, and submits. Unlike feature 6's estimate,
the price is **final** at placement (per-piece price x quantity), and stock is
**reserved atomically with the order** so the shop never oversells. This
feature also adds the "choose booking type" entry point that feature 6
deferred, since there are now two real booking types to choose between.

## In scope

- `POST /api/v1/orders`, customer-only, creating a `shop_supplied` booking
  with one or more items, reserving stock for each inside one locked
  transaction, and computing the final `total_amount` server-side.
- Extracting the booking-code generator (`RS-####`, advisory-lock based) out
  of feature 6's `BookingController` into a small shared
  `BookingCodeGenerator` service, since a second controller now needs the
  identical mechanism - this is real duplication, not a speculative
  abstraction.
- A customer-facing "choose booking type" page at `/book`, with the existing
  bring-your-own form moved to `/book/roasting` and a new shop-order form at
  `/book/shop`.
- Focused backend and frontend tests for the new logic.

## Out of scope

- Shop order confirmation, rejection, and stock release (feature 11).
- My bookings, admin queues, cooking/fulfillment, payments, dashboard
  (features 8-15) - this feature only places the order and reserves stock.
- Any change to `bookings`/`booking_items`/`booking_status_logs` schema;
  feature 6 already sized the `notes`/`delivery_address` columns correctly
  and the `status`/`source_type` enums already include the shop-supplied
  values this feature uses (`pending_confirmation`).
- `preferred_dropoff_at`: the plan's shop-order scope ("in-stock items +
  quantity -> final price... pickup or delivery, notes") never mentions a
  preferred date/time for a shop order, unlike bring-your-own's "preferred
  drop-off date/time" for raw food. A shop order ships from existing stock,
  so there is nothing to schedule a drop-off for. This feature leaves
  `preferred_dropoff_at` null for shop-supplied bookings.

## Build loop

Per `blueprint/config.json`: `workflow.stepReview` is `feature` and
`workflow.checkpointCommits` is `disabled` for the normal workflow. This
build is running under Continuous Mode, which replaces per-step pauses with
self-review and keeps work uncommitted until one local feature commit at the
end (`../../continuous/SKILL.md`).

## Build steps

- [x] 1. Extract `App\Services\Booking\BookingCodeGenerator` (a `next(): string`
  method holding the `pg_advisory_xact_lock` + last-code-lookup logic
  currently inline in `BookingController@store`) and update
  `BookingController` to use it. **Done when:** `composer test` passes with
  no behavior change (existing `BookingManagementTest` still green).
- [x] 2. Add `StoreShopOrderRequest`, `Api\Customer\OrderController@store`, and
  the `POST /api/v1/orders` route (`auth:sanctum`, `role:customer`, matching
  feature 6's pattern). Inside one DB transaction: lock each ordered
  service, verify it exists, `is_active`, `allow_shop_supplied`, and has
  enough `stock_qty`; decrement stock and write an `inventory_logs` row
  (`reason: reserve`, `booking_id` set, `created_by`: the ordering customer)
  per item; create the booking (`source_type: shop_supplied`,
  `status: pending_confirmation` via `BookingStatusEngine`) and its items
  (`qty`, `rate`: `shop_price` snapshot, `subtotal: qty * rate`); set
  `total_amount` to the summed subtotal (final, not an estimate - see Data /
  contracts). Any insufficient-stock or ineligible-service item rejects the
  whole order with `422` and reserves nothing. Add a feature test covering:
  happy path (stock decremented, one `reserve` log per item with the new
  `booking_id`, correct total, `pending_confirmation` status); insufficient
  stock (422, stock and logs unchanged); a customer-supplied-only or
  inactive service (422); delivery without an address (422); an
  admin/disabled-customer request (403); an over-bound quantity or item
  count (422). **Done when:** `composer test` passes including the new test.
- [x] 3. Add the frontend `orders` feature module (`types.ts`, `api.ts`,
  `hooks.ts`, a pure `computeOrderTotal` helper with a Vitest test) and
  `NewShopOrderPage`. Add `BookingTypePage` at `/book` linking to
  `/book/roasting` (feature 6's form, moved) and `/book/shop` (this
  feature's form). **Done when:** `npm run test` and `npm run lint` pass,
  `npm run build` succeeds, and a dev-server run shows: `/book` offers both
  types, the shop-order form lists only shop-supplied in-stock items, shows
  a live running total labeled "Total", submits, and shows the returned
  order code with status "Pending confirmation".

## Verification actually performed

No interactive browser tool was available in this session. The create-order
contract was proven against the real running `php artisan serve` dev
database (not just PHPUnit) via direct HTTP calls with a temporary
shop-supplied service: a fresh customer login, a successful order (code
`RS-0001`, `total_amount: 750.00` for 3 x 250, `pending_confirmation`
status, `stock_qty` decremented from 10 to 7, one `inventory_logs` row with
`reason: reserve`, `booking_id` set, and `created_by` the ordering
customer), an insufficient-stock 422, and a delivery-without-address 422.
Test data (the temporary service, booking, and customer) was deleted
afterward. The interactive point-and-click walkthrough of `/book`,
`BookingTypePage`, and `NewShopOrderPage` in a real browser was not
performed and remains open for a manual check, matching feature 6's same
documented gap.

## Files / areas

Backend:
- `backend/app/Services/Booking/BookingCodeGenerator.php` (new)
- `backend/app/Http/Controllers/Api/Customer/BookingController.php` (use the extracted generator)
- `backend/app/Http/Requests/Customer/StoreShopOrderRequest.php`
- `backend/app/Http/Controllers/Api/Customer/OrderController.php`
- `backend/routes/api.php` (add the route)
- `backend/tests/Feature/Customer/ShopOrderManagementTest.php`

Frontend:
- `frontend/src/features/bookings/BookingTypePage.tsx`
- `frontend/src/features/orders/types.ts`, `api.ts`, `hooks.ts`, `orderTotal.ts`, `orderTotal.test.ts`
- `frontend/src/features/orders/NewShopOrderPage.tsx`
- `frontend/src/App.tsx` (`/book` chooser, `/book/roasting`, `/book/shop`)

## Data / contracts

### `POST /api/v1/orders` (auth:sanctum, role:customer)

Request:

```json
{
  "items": [{ "service_id": 3, "qty": 2 }],
  "fulfillment": "pickup",
  "delivery_address": null,
  "notes": null
}
```

Validation: `items` required array, `min:1`, `max:20` (mirrors feature 6's
F-31 fix); `items.*.service_id` must exist, `is_active=true`,
`allow_shop_supplied=true`; `items.*.qty` required integer, `min:1`,
`max:1000` (bounds a `decimal(10,2)` subtotal against any realistic
`shop_price`, mirroring feature 6's F-19/F-23/F-30 lesson); `fulfillment`
required, `in:pickup,delivery`; `delivery_address`
`required_if:fulfillment,delivery`, string, `max:500`; `notes` nullable,
string, `max:1000`. Stock sufficiency is **not** a Form Request rule - it can
only be checked correctly inside the locked transaction (a Form Request
check would race a concurrent order), so an insufficient-stock item throws a
`ValidationException` from inside `OrderController@store`, matching the
existing pattern in `InventoryController::applyChange`.

Response `201`: the created booking (`code`, `status: pending_confirmation`,
`total_amount`, items with `qty`/`rate`/`subtotal`, `fulfillment`,
`delivery_address`, `notes`, `created_at`) via the existing `BookingResource`
(`estimated_total` stays `0` since a shop order has no estimate phase).

### Stock reservation

For each item, inside the order's transaction: `Service::lockForUpdate()`
the row, require `allow_shop_supplied && is_active && stock_qty >= qty`
(otherwise throw `ValidationException` on `items.<index>.qty`), then
`stock_qty -= qty` and create one `inventory_logs` row: `change_qty: -qty`,
`reason: 'reserve'`, `booking_id`: the new booking's id, `created_by`: the
ordering customer's id. `created_by` is a plain `users.id` FK (not
admin-only), so recording the customer here is consistent with the existing
schema; only the `project-overview.md` prose describes it as "the acting
admin" because no customer-initiated log existed before this feature.

### Money

`booking_items.rate` snapshots `services.shop_price` (never
`roasting_rate_per_kg`). `subtotal = qty * rate`. `bookings.total_amount` is
the sum of subtotals plus `shipping_fee` (`0`) - this is the **final** price
per the plan's booking-types table ("Price at booking: Final" for
shop-supplied), not an estimate; `bookings.estimated_total` is left at its
default `0` for shop-supplied bookings since there is no estimate phase to
record. Feature 11's later "Confirm shop orders (final)" step changes
`status`, not this already-final total.

## Testing

Backend (PHPUnit, `composer test`): `ShopOrderManagementTest` (feature) per
Build step 2. No new unit test for `BookingCodeGenerator` - its logic is a
direct extraction with identical behavior, already covered by the existing
and new feature tests exercising code generation end to end.

Frontend (Vitest, `npm run test`): `orderTotal.test.ts` for
`computeOrderTotal` (sum of `qty * rate` per line, empty list, rounding).

No `Browser tests` command is declared, so the `/book` flow is verified with
the dev server plus the API response, per `coding-standards.md`.

## Notes for the AI

- Reuse feature 6's established patterns exactly: `role:customer` +
  `auth:sanctum` on the route (not just `authorize()` alone - F-32 from
  feature 6's review), `decimal:0,2`-style bounding on money-adjacent inputs,
  and the `BookingResource`/`BookingItemResource` response shape (no new
  resource classes needed).
- `BookingCodeGenerator` must produce the same `RS-####` sequence shared
  across both booking types (one counter, not per-type), since `bookings.code`
  is one unique column regardless of `source_type`.
- The `/book` chooser is a small addition, not a redesign: two links/cards,
  "Bring your own" -> `/book/roasting`, "Order from shop" -> `/book/shop`.
  `CustomerLayout`'s existing "Book Now" nav link already points at `/book`,
  so it needs no change.
- Keep the same all-or-nothing transaction shape as
  `InventoryController::applyChange`: any single item failing rolls back the
  whole order, matching the plan's implicit "stock reserved atomically with
  the order" requirement for the multi-item case too.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":10824,"specSha256":"8e045470e82516a8862efc847540efb7fef529d30e1b11764f6286e843546b0a","branch":"refs/heads/feature/shop-order","head":"597782d8b95d148571c4857870a237bae4508df0","baseRef":"refs/heads/master","baseCommit":"181939996d54a6a411c8fa59ef2df9db79324cbb","sourceTree":"dfa283e1c6e4fbd82bfb96f172c5be82cd9a7654","absentOptional":[]} -->

## Findings

### 7/F-35 [P3] closed - Shop orders lock service rows in request order, so crossed concurrent orders can deadlock

**File:** backend/app/Http/Controllers/Api/Customer/OrderController.php:29-33
**Found:** 2026-09-25 by /audit independent (scope: current; lens: performance/quality)
**Why it matters:** `store` takes `lockForUpdate()` on each service row in the order the customer listed the items. Two concurrent orders for the same two services listed in opposite order (A then B, and B then A) each hold one row lock and wait for the other. Postgres detects the cycle and aborts one transaction with a deadlock error, which `DB::transaction` (single attempt) surfaces as a generic 500 instead of a success or a 422. The transaction rolls back, so no stock or booking data is corrupted; the cost is a spurious failed order under contention. Reasoned from the code path and standard Postgres lock behavior; not reproduced at runtime, and no concurrency test exists. `InventoryController::applyChange` locks only one row, so it has no ordering hazard to copy.
**Suggested fix:** Lock rows in a deterministic order before the loop, for example `Service::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id')`, then check and decrement per line against that set (re-reading the in-memory `stock_qty` so duplicate lines still see earlier decrements). Current requirement lost: None.
**Resolution:** Fixed 2026-09-25: `store` now locks all distinct requested service rows in one query, `orderBy('id')` before `lockForUpdate()`, into a keyed collection; the per-line loop reads and decrements the shared in-memory `Service` instance (so duplicate lines still see earlier decrements) and rows are saved once after the loop. `composer test` still passes (120/120), including the existing duplicate-service-id coverage. Closed 2026-09-25 by /audit independent (re-review of `597782d`; scope: current; all lenses). `OrderController.php:30-37` locks every distinct requested id in one `whereIn(...)->orderBy('id')->lockForUpdate()` query; a Postgres `EXPLAIN` of that shape shows `LockRows` above `Sort` on `id`, so locks are taken in ascending id order regardless of request order. A throwaway scratch probe (deleted afterward, not in the repo) confirmed: lines B, A, B were decremented through the shared instance (B 10->3, A 10->8, three logs and items, total correct); B 3+3 against stock 5 failed on `items.2.qty` with zero stock, log, booking, item, or status-log writes; string ids still resolved against the int-keyed collection. No new defect found in this path. Correction to the note above: the only existing duplicate-id test (`test_more_than_twenty_items_is_rejected`) is rejected by Form Request validation before the controller runs, so the duplicate-line path has no repo test. That gap is recorded separately as F-37.

## Independent review

**Status:** passed
**Target commit:** 597782d8b95d148571c4857870a237bae4508df0
**Base commit:** 181939996d54a6a411c8fa59ef2df9db79324cbb
**Base ref:** master
**Spec hash:** 8e045470e82516a8862efc847540efb7fef529d30e1b11764f6286e843546b0a
**Prepared by:** claude
**Builder model:** claude-sonnet-5
**Requested reviewer:** claude
**Requested model:** claude-opus-5-5
**Requested execution:** automatic
**Requested at:** 2026-09-25T07:27:50Z
**Workflow:** continuous
**Check required:** no
**Reviewer adapter:** claude
**Reviewer model:** claude-opus-5-5
**Reviewer context:** fresh subagent
**Actual execution:** automatic
**Reviewed at:** 2026-09-25T07:29:53Z
**Scope:** current
**Lenses:** quality, security, performance, tests
**Verdict:** passed
**Check result:** not-required

### Commands

- `git rev-parse HEAD`, `git merge-base master HEAD`, `sha256sum blueprint/context/current-feature.md`, `git status --porcelain -uall`: pass (all preconditions match; only `blueprint/context/review.md` dirty)
- `composer test` (backend): pass (120/120, 399 assertions)
- `vendor/bin/pint --test` (backend): pass
- `npm run test -- --run` (frontend): pass (23/23, 5 files)
- `npm run lint` (frontend): pass (0 errors; 4 existing-pattern warnings, one on the new `NewShopOrderPage.tsx:84` `watch` use, same as `NewBookingPage.tsx`)
- `npm run build` (frontend): pass (existing >500 kB chunk warning)
- Throwaway scratch PHPUnit probe run against the test database, outside the repo and deleted afterward: pass (4/4)

### Evidence

- Reviewed the full `1819399..597782d` delta: `OrderController`, `StoreShopOrderRequest`, `BookingCodeGenerator` extraction and `BookingController` refactor, the `POST /api/v1/orders` route (`auth:sanctum` + `role:customer`), `ShopOrderManagementTest`, `App.tsx` routes, `BookingTypePage`, and the `orders` module (`api`, `hooks`, `types`, `orderTotal` + test, `NewShopOrderPage`).
- F-35 repair: one `whereIn(...)->orderBy('id')->lockForUpdate()` query. A Postgres `EXPLAIN` shows `LockRows` above `Sort` on `id`, so row locks are taken in ascending order. The probe confirmed that B, A, B lines share decrements, that duplicate lines exceeding stock roll back with zero writes, and that string ids still resolve.
- Lock interplay: the order path takes service row locks and then the booking-code advisory lock. The bring-your-own path takes only the advisory lock, and inventory takes one row lock. There is no cycle.
- Security: ownership comes from `$request->user()`. Price and eligibility are rechecked server-side under the lock, and the frontend total is labeled preview-only.
- Spec conformance: 422 all-or-nothing, `reserve` logs with `booking_id`/`created_by`, final `total_amount`, `estimated_total` 0, and one shared `RS-####` sequence.

### Findings

- F-35 [P3]: fixed -> closed (repair re-examined and confirmed)
- F-37 [P3]: new, open (no repo test covers duplicate-service lines reaching the controller)
- F-36 [P3]: still open (unchanged, non-blocking)
- No P0 or P1 findings are open or fixed.

### Remaining risk

- Real concurrent-order deadlock was not reproduced at runtime. The ordering guarantee is inferred from the query plan and standard Postgres lock behavior.
- The interactive browser walkthrough of `/book`, `/book/roasting`, and `/book/shop` was not performed. No `Browser tests` command is declared, and Check was not required.
- F-36 and F-37 remain open P3s.
