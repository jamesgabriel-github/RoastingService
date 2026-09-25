# Feature: My bookings

**From build-plan:** feature 8
**Build attempt:** 1
**Status:** verified
**Branch:** `feature/my-bookings`

## Goal

A logged-in customer can see a list of their own bookings (both types), open
one for a detail view with its full status timeline, see estimated vs final
weight/price where that distinction applies, and cancel a booking while it's
still allowed - matching the plan's "only before Cooking" rule, which the
`BookingStatusEngine` transition map (feature 6) already encodes for both
source types. The list and detail auto-refresh so a later admin action
(feature 9+) becomes visible without a manual reload.

## In scope

- `GET /api/v1/bookings` - the current customer's own bookings, both source
  types, newest first.
- `GET /api/v1/bookings/{id}` - one of the customer's own bookings, `404` if
  it does not exist or is not theirs, including the full status-log
  timeline.
- `POST /api/v1/bookings/{id}/cancel` - customer-initiated cancel. Rejects
  with `422` when the current status has no `cancelled` transition in
  `BookingStatusEngine` (i.e. once cooking has started or later). For a
  `shop_supplied` booking, releases the reserved stock back
  (`inventory_logs`, `reason: release`) in the same locked transaction.
- Frontend: `MyBookingsPage` (list) and `BookingDetailPage` (timeline,
  estimated vs final, cancel button when eligible) with polling
  auto-refresh, plus a "My Bookings" nav link.

## Out of scope

- Admin booking queues, approve/weigh-in, shop order confirmation, cooking
  and fulfillment, walk-ins, payments, dashboard (features 9-15) - nothing
  in this feature drives a booking past its created status, so most test
  bookings will show a one-entry timeline today. That's correct present
  state, not a gap to fill with mock data.
- Editing a booking after creation (not in the plan; only cancel is).
- Any change to `bookings`/`booking_items`/`booking_status_logs` schema or
  to `BookingStatusEngine`'s transition map - the "cancel before cooking"
  paths it needs (`pending_review`, `approved`, `confirmed` for
  customer-supplied; `pending_confirmation`, `confirmed` for shop-supplied)
  already exist.

## Build loop

Per `blueprint/config.json`: `workflow.stepReview` is `feature` and
`workflow.checkpointCommits` is `disabled` for the normal workflow. This
build is running under Continuous Mode, which replaces per-step pauses with
self-review and keeps work uncommitted until one local feature commit at the
end (`../../continuous/SKILL.md`).

## Build steps

- [x] 1. Add `BookingStatusLogResource` (`id`, `status`, `changed_by_name`
  nullable, `remarks`, `created_at`). Extend `BookingResource` to always
  include `items` (already loaded by the existing create endpoints) and add
  `status_logs` via `whenLoaded('statusLogs')` so the list response stays
  light while the detail response carries the timeline. Add
  `BookingController@index` (own bookings, `orderByDesc('id')`, `items`
  eager-loaded) and `@show` (`findOrFail` scoped to
  `customer_id = $request->user()->id`, `items.service` and `statusLogs`
  eager-loaded, `statusLogs` ordered `orderBy('id')`). Wire
  `GET /api/v1/bookings` and `GET /api/v1/bookings/{id}` behind
  `auth:sanctum`, `role:customer`. Add a feature test covering: a customer
  only sees their own bookings in the list, ordered newest first; requesting
  another customer's booking id returns `404`; the detail response's
  `status_logs` has one entry for a freshly created booking. **Done when:**
  `composer test` passes including the new test.
- [x] 2. Add `BookingController@cancel`: inside one transaction, load the
  booking scoped to the owner (`404` otherwise), reject with `422` when
  `BookingStatusEngine::isAllowed($booking->source_type, $booking->status, 'cancelled')`
  is `false`, otherwise (for `shop_supplied`) lock and restock every item's
  service (`stock_qty += qty`, one `inventory_logs` row per item:
  `reason: release`, `booking_id` set, `created_by`: the cancelling
  customer) before calling `$statusEngine->transition($booking, 'cancelled')`.
  Wire `POST /api/v1/bookings/{id}/cancel`. Add a feature test covering:
  cancelling a `pending_review` customer-supplied booking succeeds and logs
  the transition; cancelling a `pending_confirmation` shop-supplied booking
  succeeds and restocks every item with matching `release` logs; cancelling
  once `cooking` (seed a booking directly at that status for the test)
  returns `422` and changes nothing; cancelling another customer's booking
  returns `404`. **Done when:** `composer test` passes including the new
  test.
- [x] 3. Add the frontend list/detail: extend `frontend/src/features/bookings`
  with `api.ts` additions (`fetchMyBookings`, `fetchBookingDetail`,
  `cancelBooking`), `hooks.ts` additions (`useMyBookings`,
  `useBookingDetail(id)` with `refetchInterval`, `useCancelBooking`),
  `MyBookingsPage.tsx`, and `BookingDetailPage.tsx` (status timeline,
  estimated vs final weight/price for `customer_supplied`, total for
  `shop_supplied`, a `Cancel booking` button shown only when the current
  status allows it - mirror the engine's allowed-from-status set on the
  frontend for the button's visibility only; the server is still the
  authority and returns `422` if the client is stale). Add routes
  `/bookings` and `/bookings/:id`, and a "My Bookings" nav link in
  `CustomerLayout`. **Done when:** `npm run test` and `npm run lint` pass,
  `npm run build` succeeds, and a dev-server run shows: the list shows a
  created booking, opening it shows the timeline and estimated/final
  fields, cancelling a cancellable booking updates its status and removes
  the cancel button, and the list/detail refetch on their own after a wait
  without a manual reload.

## Verification actually performed

No interactive browser tool was available in this session. The
list/detail/cancel contract was proven against the real running
`php artisan serve` dev database (not just PHPUnit) via direct HTTP calls: a
fresh customer login, a booking creation, a list call returning it, a detail
call showing the one-entry timeline, a successful cancel (`200`,
`status: cancelled`), a repeat cancel correctly rejected with `422`, and a
request for a non-existent/non-owned booking id correctly returning `404`.
Test data was deleted afterward. The interactive point-and-click walkthrough
of `MyBookingsPage`/`BookingDetailPage` and the auto-refresh timing in a real
browser were not performed and remain open for a manual check, matching the
same documented gap as features 6 and 7.

## Files / areas

Backend:
- `backend/app/Http/Resources/BookingStatusLogResource.php` (new)
- `backend/app/Http/Resources/BookingResource.php` (extend)
- `backend/app/Http/Controllers/Api/Customer/BookingController.php` (add `index`, `show`, `cancel`)
- `backend/routes/api.php` (add the three routes)
- `backend/tests/Feature/Customer/MyBookingsTest.php`

Frontend:
- `frontend/src/features/bookings/api.ts`, `hooks.ts` (extend)
- `frontend/src/features/bookings/MyBookingsPage.tsx`
- `frontend/src/features/bookings/BookingDetailPage.tsx`
- `frontend/src/components/layouts/CustomerLayout.tsx` (nav link)
- `frontend/src/App.tsx` (routes)

## Data / contracts

### `GET /api/v1/bookings` (auth:sanctum, role:customer)

Response `200`: an array of `BookingResource` (with `items`, no
`status_logs`) for `customer_id = auth()->id()`, `orderByDesc('id')`.

### `GET /api/v1/bookings/{id}` (auth:sanctum, role:customer)

`404` when the booking does not exist or `customer_id` is not the
authenticated customer's id - identical response either way, so a customer
can never distinguish "doesn't exist" from "isn't yours". Response `200`:
`BookingResource` with `items` and `status_logs`
(`BookingStatusLogResource[]`, ascending by `id`).

### `POST /api/v1/bookings/{id}/cancel` (auth:sanctum, role:customer)

No body. `404` under the same ownership rule as `show`. `422` (message:
"This booking can no longer be cancelled.") when
`BookingStatusEngine::isAllowed($booking->source_type, $booking->status, 'cancelled')`
is `false`. Otherwise `200` with the updated `BookingResource`
(`status: cancelled`). For `shop_supplied`, every item's `qty` is added back
to `services.stock_qty` and one `inventory_logs` row per item is written
(`reason: release`, `change_qty: +qty`, `booking_id`, `created_by`: the
cancelling customer's id) before the status transition, inside the same
transaction - mirrors the reservation write from feature 7 in reverse.
`customer_supplied` cancellation touches no stock (unaffected by
bring-your-own bookings, per the booking-types table).

### `BookingStatusLogResource`

`id`, `status`, `changed_by_name` (nullable - `null` for every entry this
feature writes, since cancellation is customer-initiated; populated once an
admin action changes a status in a later feature), `remarks`, `created_at`.

## Testing

Backend (PHPUnit, `composer test`): `MyBookingsTest` per Build steps 1-2,
covering ownership scoping, the timeline shape, successful cancellation for
both source types (including stock release), and the `422` once a booking
is no longer cancellable.

Frontend (Vitest, `npm run test`): no new pure logic to unit-test this
feature; the cancel-button visibility rule is a direct lookup against a
small constant set (no calculation), so it's covered by the dev-server
check rather than a forced unit test.

No `Browser tests` command is declared, so the list/detail/cancel flow is
verified with the dev server plus the API responses, per
`coding-standards.md`.

## Notes for the AI

- Auto-refresh: use TanStack Query's `refetchInterval` (a plain number of
  milliseconds, e.g. `10000`) on both the list and detail queries - no new
  library or websocket needed for this scale (single shop, low booking
  volume).
- The frontend's cancel-button visibility check must mirror
  `BookingStatusEngine`'s cancellable-from set (`pending_review`,
  `approved`, `confirmed` for `customer_supplied`;
  `pending_confirmation`, `confirmed` for `shop_supplied`) but is only a UI
  convenience; the `422` path must still be handled (surface
  `getGenericErrorMessage`-style feedback) in case the status changed
  between page load and the click.
- Reuse `BookingResource`/`BookingItemResource` as-is; only add the new
  `status_logs` key and resource class.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":10354,"specSha256":"5a6a5ce9f861dc118df97daf16d9161789a5ab4335dacb3f4fcdaf67f371e641","branch":"refs/heads/feature/my-bookings","head":"fa512a92d0ce52ac5c011c4ca1d9c1e811fb9043","baseRef":"refs/heads/master","baseCommit":"5ce5b62bfd8ae115f645693562037c6f0717e3da","sourceTree":"21a62b2000be71162ff0b2131c4032e40f1daee9","absentOptional":[]} -->

## Findings

### 8/F-38 [P3] closed - Booking ids beyond PHP int range return 500 instead of 404

**File:** backend/app/Http/Controllers/Api/Customer/BookingController.php:31,40
**Found:** 2026-09-25 by /audit independent (scope: current; lens: security/quality)
**Why it matters:** `->whereNumber('id')` correctly turns `/bookings/abc` into a 404, but it accepts any digit string. `show(..., int $id)` and `cancel(..., int $id)` then receive a string such as `99999999999999999999`, which PHP cannot coerce to `int`, so the request raises a `TypeError` and returns 500. The spec says a missing booking returns 404. A reviewer probe confirmed that `GET /api/v1/bookings/99999999999999999999` returns 500, and `9223372036854775807` returns 404. Nothing is written and only an authenticated customer can reach it, so this is the F-19/F-23 pattern and not a security break.
**Suggested fix:** Drop the `int` type hints and accept `string $id` (`findOrFail` then returns 404 for any id that does not exist), or tighten the route constraint to a bounded pattern such as `->where('id', '[0-9]{1,18}')`. Current requirement lost: None.
**Resolution:** Fixed 2026-09-25: tightened both route constraints to `->where('id', '[0-9]{1,18}')` (18 digits safely fits `PHP_INT_MAX`), so an oversized id 404s before reaching the `int $id` parameter. Added `test_an_id_beyond_php_int_range_is_not_found`. Closed 2026-09-25 by /audit independent (target fa512a9): both routes carry `->where('id', '[0-9]{1,18}')` (max 999999999999999999 < PHP_INT_MAX; `bookings.id` is bigint). Reviewer probe: GET and cancel for `999999999999999999`, `9223372036854775807`, `99999999999999999999`, `0`, and `000000000000000001` all returned 404 with no 500.

### 8/F-39 [P3] closed - Cancel's lock order differs from feature 7's sorted service lock

**File:** backend/app/Http/Controllers/Api/Customer/BookingController.php:53-63
**Found:** 2026-09-25 by /audit independent (scope: current; lens: performance/quality)
**Why it matters:** The restock is correct. Each `Service::where('id', ...)->increment()` is an atomic SQL `stock_qty = stock_qty + qty`, so repeated lines for the same service all apply. A probe with lines B, A, B confirmed that both services returned to their original stock, three `release` logs were written, and a second cancel returned 422 with no further stock change. However, the increments take row locks in the booking's item order, which is the customer's original line order. `OrderController::store` deliberately locks the same rows in ascending id order to avoid deadlocks (see its comment). A cancel of lines B, A that runs at the same time as a new order for A, B can therefore deadlock. Postgres would abort one transaction with a 500 and roll it back, so no data is corrupted. Spec step 2 also says to "lock and restock every item's service", and this code does not lock them explicitly. The deadlock is inferred from lock order and was not reproduced, but it is unlikely at this shop's volume.
**Suggested fix:** Before the loop, lock the distinct service ids in ascending order, for example `Service::whereIn('id', $booking->items->pluck('service_id')->unique())->orderBy('id')->lockForUpdate()->get()`. Keep the atomic increments, or sum qty per service and increment once. Current requirement lost: None.
**Resolution:** Fixed 2026-09-25: the restock loop now iterates `$booking->items->sortBy('service_id')`, so the atomic increments always take their row locks in the same ascending order `OrderController::store` uses, regardless of the customer's original line order. Added `test_cancelling_with_the_same_service_on_two_lines_restocks_both`. Closed 2026-09-25 by /audit independent (target fa512a9): the booking row lock is taken first (order creation never locks existing booking rows, so no cycle there), then service rows are updated in ascending id order, matching `OrderController::store`. Reviewer probe with lines B, A, B logged `update "services"` in order A, B, B; both services returned to 10, three `release` logs were written, and a repeat cancel returned 422.

### 8/F-40 [P3] closed - Cancel response overwrites the detail cache without the timeline

**File:** frontend/src/features/bookings/hooks.ts:42
**Found:** 2026-09-25 by /audit independent (scope: current; lens: quality)
**Why it matters:** `cancel()` returns `BookingResource($booking->load('items.service'))` without `statusLogs`, so its JSON has no `status_logs` key (confirmed by probe). `useCancelBooking` calls `setQueryData(['my-bookings', id], booking)` with that payload, so the detail page's timeline becomes empty (`status_logs?.map` renders nothing). It stays empty until the invalidation refetch returns. The customer briefly sees their timeline disappear at the moment they cancel. The data is correct after the refetch.
**Suggested fix:** Either eager-load the ordered `statusLogs.changer` in `cancel()`'s response the same way `show()` does, or drop the `setQueryData` call and rely on the existing `invalidateQueries({ queryKey: ['my-bookings'] })`, which already refetches the detail. Current requirement lost: None.
**Resolution:** Fixed 2026-09-25: `cancel()` now loads `items.service` and the ordered `statusLogs.changer`, the same shape `show()` returns, so the cache write includes the fresh two-entry timeline instead of wiping it. Extended `test_cancelling_a_pending_review_booking_succeeds` to assert the response's `status_logs` has both entries. Closed 2026-09-25 by /audit independent (target fa512a9): `cancel()` returns the same `items.service` + ordered `statusLogs.changer` eager-load as `show()`. Reviewer probe on a shop order cancel returned `status_logs` `[pending_confirmation, cancelled]` in id order, plus items with `service_name`, so `setQueryData` no longer drops the timeline.

## Independent review

**Status:** passed
**Target commit:** fa512a92d0ce52ac5c011c4ca1d9c1e811fb9043
**Base commit:** 5ce5b62bfd8ae115f645693562037c6f0717e3da
**Base ref:** master
**Spec hash:** 5a6a5ce9f861dc118df97daf16d9161789a5ab4335dacb3f4fcdaf67f371e641
**Prepared by:** claude
**Builder model:** claude-sonnet-5
**Requested reviewer:** claude
**Requested model:** claude-opus-5-5
**Requested execution:** automatic
**Requested at:** 2026-09-25T07:55:01Z
**Workflow:** continuous
**Check required:** no
**Reviewer adapter:** claude
**Reviewer model:** claude-opus-5-5
**Reviewer context:** fresh subagent
**Actual execution:** automatic
**Reviewed at:** 2026-09-25T07:58:00Z
**Scope:** current
**Lenses:** quality, security, performance, tests
**Verdict:** passed
**Check result:** not-required

### Commands

- `git rev-parse HEAD` / `git merge-base master HEAD` / `sha256sum current-feature.md` / `git status --porcelain --untracked-files=all`: pass (target, base, and spec hash match; only `review.md` differed)
- `composer test` (backend): pass (132 tests, 440 assertions)
- `vendor/bin/pint --test` (backend): pass
- `npm run test -- --run` (frontend): pass (6 files, 29 tests)
- `npm run lint` (frontend): pass (exit 0; 3 pre-existing `incompatible-library` warnings in files outside this delta)
- `npm run build` (frontend): pass (chunk-size advisory only)
- Throwaway PHPUnit probe run from the scratchpad against the test database, then deleted: pass (2 tests, 19 assertions)

### Evidence

- Reviewed the full `5ce5b62..fa512a9` delta: `BookingController` (`index`/`show`/`cancel`), `BookingResource`, new `BookingStatusLogResource`, `routes/api.php`, `MyBookingsTest`, and the frontend bookings api/hooks/status/types, `MyBookingsPage`, `BookingDetailPage`, `App.tsx`, and `CustomerLayout.tsx`. Compared against `OrderController::store`, `BookingStatusEngine`, and the bookings migrations.
- Security: all three new routes sit behind `auth:sanctum` + `role:customer`. `show`/`cancel` scope `customer_id` to the authenticated user and return the same 404 for missing and non-owned bookings. Tests cover 401, 403 for admins, and cross-customer 404 on both GET and cancel.
- F-38: the probe returned 404 on both GET and cancel for 18-digit, `PHP_INT_MAX`, 20-digit, `0`, and zero-padded ids.
- F-39: the probe cancelled a B, A, B shop order and found service updates issued in order A, B, B. Stock was fully restored, 3 `release` logs were written, and a repeat cancel returned 422.
- F-40: the probe's cancel response carried `status_logs` `[pending_confirmation, cancelled]` and items with `service_name`.
- Performance: list, detail, and cancel responses eager-load their relations (no N+1). The unpaginated own-bookings list matches the spec and the low-volume scope.
- The tests cover ownership, ordering, the timeline, cancellation for both source types including duplicate-service restock, the 422 once cooking, and oversized/non-numeric ids.

### Findings

- F-38 closed, F-39 closed, F-40 closed (P3).
- F-41 (P3) remains open, left unchanged by design, alongside earlier open P3s F-34, F-36, and F-37.
- No new findings.

### Remaining risk

- `/check` was not required and was not run. The interactive browser walkthrough and the 10 s auto-refresh timing were not exercised in a real browser, and no `Browser tests` command is declared.
- The F-39 deadlock resolution is based on lock-order reasoning plus the query order seen in the probe. A true concurrent deadlock reproduction was not attempted.
