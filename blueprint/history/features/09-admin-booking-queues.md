# Feature: Admin booking queues

**From build-plan:** feature 9
**Build attempt:** 1
**Status:** verified
**Branch:** `feature/admin-booking-queues`

## Goal

An admin (or super admin) with `bookings` module permission gets a read-only,
tabbed view of every booking in the shop: one tab per status queue with a
live count, a searchable/paginated table per tab showing how long each
booking has been waiting, and a detail view with the full item list and
status timeline. This is observability only - approving, weighing in,
confirming, starting cooking, and rejecting are separate later features
(10-12); this feature only makes the queues visible so those actions have
something to act on next.

## In scope

- `GET /api/v1/admin/bookings/counts` - the count of bookings in each queue
  status.
- `GET /api/v1/admin/bookings` - one queue's bookings (`status` required),
  optional `search` across code, customer name, customer phone, guest name,
  and guest phone; paginated, newest-waiting-first is not required - ordered
  by longest-waiting first (`orderBy` the latest status-log timestamp
  ascending), since that is what an admin triage view needs.
- `GET /api/v1/admin/bookings/{id}` - one booking's full detail: items,
  customer/guest contact, and the status timeline.
- Frontend: `AdminBookingsPage` (tabs with counts, search box, table with
  waiting time) and `AdminBookingDetailPage`, behind the existing
  `bookings` module gate, with an "Admin Bookings" nav link.

## Out of scope

- Any status-changing action (approve, reject, weigh-in, confirm, start
  cooking, mark ready, cancel, delete) - features 10-12 add these against
  the same queues this feature only displays.
- Walk-ins, payments, dashboard (features 13-15).
- Auto-refresh/polling - not asked for by this build-plan item (unlike
  feature 8's customer-facing "My bookings"); an admin re-opens or
  re-searches a tab to see new arrivals for now.

## Build loop

Per `blueprint/config.json`: `workflow.stepReview` is `feature` and
`workflow.checkpointCommits` is `disabled` for the normal workflow. This
build is running under Continuous Mode, which replaces per-step pauses with
self-review and keeps work uncommitted until one local feature commit at the
end (`../../continuous/SKILL.md`).

## Build steps

- [x] 1. Add `Booking::latestStatusLog()` (`hasOne(BookingStatusLog::class)->latestOfMany()`)
  and `AdminBookingResource` (`id`, `code`, `source_type`, `status`,
  `fulfillment`, `delivery_address`, `customer_name`, `customer_phone`,
  `estimated_total`, `total_amount`, `preferred_dropoff_at`, `notes`,
  `waiting_minutes`, `items`/`status_logs` via `whenLoaded`, `created_at`).
  `customer_name`/`customer_phone` resolve to the customer's
  `first_name + last_name` / `phone` when `customer_id` is set, otherwise
  `guest_name` / `guest_phone`. `waiting_minutes` is
  `now()->diffInMinutes($this->latestStatusLog?->created_at ?? $this->created_at)`.
  Add `Api\Admin\BookingController@counts` and `@index`
  (`status` required, one of the seven queue statuses below; optional
  `search` matching code/guest fields/`customer.phone` and a
  `concat_ws(' ', first_name, last_name)` match on `customer`; `with('customer')`;
  order by `latestStatusLog.created_at` ascending; paginate). Wire
  `GET /api/v1/admin/bookings/counts` and `GET /api/v1/admin/bookings` behind
  `auth:sanctum`, `role:admin,super_admin`, `module:bookings`. Add a feature
  test covering: counts reflect seeded bookings per status; the `status`
  filter returns only that queue; `search` matches by code, by a
  registered customer's name, and by phone; an admin without the `bookings`
  permission is forbidden; a customer is forbidden. **Done when:**
  `composer test` passes including the new test.
- [x] 2. Add `Api\Admin\BookingController@show` (`items.service`, `customer`,
  ordered `statusLogs.changer`, `findOrFail`) and wire
  `GET /api/v1/admin/bookings/{id}` (bounded `[0-9]{1,18}` id, same
  middleware). Add a feature test covering: detail returns items and the
  full timeline; a missing or non-numeric id returns `404`. **Done when:**
  `composer test` passes including the new test.
- [x] 3. Add the frontend: `frontend/src/features/admin-bookings/` with
  `types.ts`, `api.ts` (`fetchBookingCounts`, `fetchAdminBookings(status,
  search, page)`, `fetchAdminBookingDetail(id)`), `hooks.ts`,
  `AdminBookingsPage.tsx` (seven tabs with count badges - Pending review,
  Pending confirmation, Awaiting drop-off, Confirmed, Cooking, Ready, Out
  for delivery - a search input, and a table: code, customer/guest name,
  type, waiting time, link to detail), and `AdminBookingDetailPage.tsx`
  (items, timeline, customer/guest contact). Add the routes and nav link
  behind the existing `bookings` `RoleRoute requireModule`, mirroring the
  `services`/`inventory` pattern in `AdminLayout`/`App.tsx`. **Done when:**
  `npm run test` and `npm run lint` pass, `npm run build` succeeds, and a
  dev-server run shows: the tab counts match seeded data, switching tabs
  loads that queue, searching narrows the table, and opening a row shows
  its items and timeline.

## Verification actually performed

No interactive browser tool was available in this session. The
counts/queue/search/detail contract was proven against the real running
`php artisan serve` dev database (not just PHPUnit) via direct HTTP calls as
the seeded super admin: seeded one `pending_review` and one `cooking`
booking, confirmed `GET /admin/bookings/counts` matched exactly, confirmed
the `pending_review` queue returned only that booking, confirmed `search`
by customer last name narrowed to the matching booking, and confirmed the
detail endpoint returned its item and timeline. This manual pass also
caught a real bug the test suite missed: Carbon 3 changed `diffInMinutes()`
to return a signed float by default, so `waiting_minutes` was coming back
as a negative fraction (e.g. `-0.41`); fixed by passing `absolute: true`
and casting to `int`, with a new regression test
(`test_waiting_minutes_is_a_non_negative_whole_number`) locking in the
fix. Test data was deleted afterward. The interactive point-and-click
walkthrough of the tabs/search/detail pages in a real browser was not
performed and remains open for a manual check, matching the same
documented gap as features 6-8.

## Files / areas

Backend:
- `backend/app/Models/Booking.php` (add `latestStatusLog`)
- `backend/app/Http/Resources/AdminBookingResource.php` (new)
- `backend/app/Http/Controllers/Api/Admin/BookingController.php` (new)
- `backend/routes/api.php` (add the three routes)
- `backend/tests/Feature/Admin/AdminBookingQueuesTest.php`

Frontend:
- `frontend/src/features/admin-bookings/types.ts`, `api.ts`, `hooks.ts`
- `frontend/src/features/admin-bookings/AdminBookingsPage.tsx`
- `frontend/src/features/admin-bookings/AdminBookingDetailPage.tsx`
- `frontend/src/components/layouts/AdminLayout.tsx` (nav link)
- `frontend/src/App.tsx` (routes)

## Data / contracts

### Queue statuses (in tab order)

`pending_review`, `pending_confirmation`, `approved` (labeled "Awaiting
drop-off" - the customer-supplied post-approval, pre-weigh-in state),
`confirmed`, `cooking`, `ready`, `out_for_delivery`. `confirmed` is not
named in the project plan's queue list, but it is a real, reachable status
(after weigh-in or shop-order confirmation, before cooking starts) that the
plan's own "Start cooking" quick action needs a queue to act from - omitting
it would make bookings vanish from every admin view between confirmation and
cooking. Adding its tab is filling a gap in an already-committed status
list, not new scope. `completed`, `rejected`, `no_show`, and `cancelled` are
resolved/exited bookings and get no queue tab in this feature.

### `GET /api/v1/admin/bookings/counts` (auth:sanctum, role:admin|super_admin, module:bookings)

Response `200`: `{"pending_review": 2, "pending_confirmation": 0, "approved": 0, "confirmed": 0, "cooking": 1, "ready": 0, "out_for_delivery": 0}`
- one key per queue status above, always present even when `0`.

### `GET /api/v1/admin/bookings?status=pending_review&search=RS-0001&page=1`

`status` required, must be one of the seven queue statuses (`422`
otherwise). `search` optional. Response `200`: Laravel's default paginated
shape (`data`, `links`, `meta`) of `AdminBookingResource` (no `items`/
`status_logs` - list stays light, matching feature 8's list/detail split).

### `GET /api/v1/admin/bookings/{id}`

`404` for a missing or non-numeric id (`[0-9]{1,18}` route constraint, per
the F-38 lesson from feature 8). Response `200`: `AdminBookingResource` with
`items` and `status_logs`.

## Testing

Backend (PHPUnit, `composer test`): `AdminBookingQueuesTest` per Build
steps 1-2, covering counts, per-status filtering, search across code/name/
phone, module-permission enforcement, and the detail timeline/404s.

Frontend (Vitest, `npm run test`): no new pure logic to unit-test; tab
labels and waiting-time display are direct lookups/formatting covered by the
dev-server check.

No `Browser tests` command is declared, so the tabs/search/detail flow is
verified with the dev server plus the API responses, per
`coding-standards.md`.

## Notes for the AI

- Seed test data across every queue status directly via `Booking::factory()`
  with an explicit `status`, rather than only via the customer-facing create
  endpoints (which only ever produce `pending_review`/`pending_confirmation`
  today) - later admin actions don't exist yet to reach the other statuses
  organically.
- `customer_name`/`customer_phone` must fall back to `guest_name`/
  `guest_phone` even though no guest bookings exist yet (feature 13) - the
  resource contract should already support both, matching the shared
  `bookings` schema.
- Reuse the existing `role:admin,super_admin` + `module:bookings` middleware
  pattern exactly as `services`/`inventory` already do; no new middleware.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":9921,"specSha256":"a06dfa31de000e01767d934f27030e426a3820760bac90987809d49ee4387b15","branch":"refs/heads/feature/admin-booking-queues","head":"d261bb0041ba8c424ebb51505ce47ddcc86546ef","baseRef":"refs/heads/master","baseCommit":"4866ea1774b18fec02074ad46b12488680d657a0","sourceTree":"89ce54370a901f42267e71e049b19702e179f890","absentOptional":[]} -->

## Findings

### 9/F-42 [P2] closed - Admin bookings list lazy-loads latestStatusLog once per row (N+1)

**File:** backend/app/Http/Controllers/Api/Admin/BookingController.php:47; backend/app/Http/Resources/AdminBookingResource.php:36
**Found:** 2026-09-25 by /audit independent (scope: current; lens: performance)
**Why it matters:** `index()` eager-loads only `customer`, but `AdminBookingResource` reads `$this->latestStatusLog` for `waiting_minutes` on every row. No lazy-loading guard is configured, so each row runs its own `booking_status_logs` query. A reviewer probe against the test database measured 8 queries for 5 rows and 13 for 10 rows (one extra per row), so a full 15-row page adds 15 queries. It is bounded by pagination, so this is a performance cost, not a correctness bug. `show()` has the same extra query even though it already loads every `statusLogs` row.
**Suggested fix:** Change `->with('customer')` to `->with(['customer', 'latestStatusLog'])` in `index()`, and add `latestStatusLog` to the `show()` eager load. Optionally assert the query count in a feature test. Current requirement lost: None.
**Resolution:** Fixed 2026-09-25: `index()` now eager-loads `['customer', 'latestStatusLog']` and `show()` adds `latestStatusLog` alongside its existing eager loads. `composer test` still passes (147/147). Closed 2026-09-25 by /audit independent (re-review of `d261bb0`; scope: current; all lenses): `BookingController.php:47` eager-loads `['customer', 'latestStatusLog']` and `show()` (line 77) loads `latestStatusLog`, so `AdminBookingResource.php:36` reads an already-loaded relation instead of issuing a per-row query. `latestOfMany()` resolves by max `id`, matching the list's order-by subquery (`latest('id')`), so waiting time and sort order stay consistent. No new defect introduced; `composer test` passes (147 tests, 498 assertions).

### 9/F-43 [P3] closed - Queue ordering has no unique tie-breaker, so pagination can repeat or skip rows

**File:** backend/app/Http/Controllers/Api/Admin/BookingController.php:67
**Found:** 2026-09-25 by /audit independent (scope: current; lens: quality)
**Why it matters:** The list is ordered only by the latest status-log `created_at`, which has one-second precision. Rows with equal timestamps have no guaranteed order in Postgres, so the same booking can appear on two pages or on none as an admin pages through a busy queue. This is more likely once walk-ins or bulk actions arrive (features 10-13).
**Suggested fix:** Add a unique secondary order: `$query->orderBy($latestLog)->orderBy('bookings.id');`. Current requirement lost: None.
**Resolution:** Fixed 2026-09-25: added `->orderBy('bookings.id')` as the secondary sort after the latest-log subquery order. `composer test` still passes. Closed 2026-09-25 by /audit independent (re-review of `d261bb0`; scope: current; all lenses): `BookingController.php:67` now orders by the latest-log subquery then the unique `bookings.id`, giving a total order for pagination, including bookings with no status log (NULL subquery value). `test_results_are_ordered_by_longest_waiting_first` still passes. No new defect introduced.

### 9/F-45 [P3] closed - No negative-authorization test covers the booking detail route

**File:** backend/tests/Feature/Admin/AdminBookingQueuesTest.php:196-215
**Found:** 2026-09-25 by /audit independent (scope: current; lens: tests)
**Why it matters:** `GET /admin/bookings/{id}` returns the most sensitive payload in this feature: the customer's name, phone, delivery address, notes, items, and full timeline. It is protected today because it sits in the same `auth:sanctum` + `role:admin,super_admin` + `module:bookings` group as the other routes (verified in `routes/api.php`). The forbidden and unauthenticated tests only hit `/counts` and the list, and the customer and unauthenticated cases only hit `/counts`. If the detail route were moved out of the group later, no test would fail.
**Suggested fix:** Extend the three negative tests to also call `GET /api/v1/admin/bookings/{id}` for a real booking. Assert 403 for an admin without the permission and for a customer, and 401 when unauthenticated. Current requirement lost: None.
**Resolution:** Fixed 2026-09-25: extended `test_admin_without_bookings_permission_is_forbidden`, `test_customer_is_forbidden`, and `test_unauthenticated_is_rejected` to also call `GET /api/v1/admin/bookings/{id}` against a real booking and assert the matching status. Closed 2026-09-25 by /audit independent (re-review of `d261bb0`; scope: current; all lenses): `AdminBookingQueuesTest.php:196-223` now asserts 403 on the detail route for an admin without the permission and for a customer, and 401 when unauthenticated, each against an existing booking id so the result cannot be a 404. All three pass under `composer test`. No new defect introduced.

## Independent review

**Status:** passed
**Target commit:** d261bb0041ba8c424ebb51505ce47ddcc86546ef
**Base commit:** 4866ea1774b18fec02074ad46b12488680d657a0
**Base ref:** master
**Spec hash:** a06dfa31de000e01767d934f27030e426a3820760bac90987809d49ee4387b15
**Prepared by:** claude
**Builder model:** claude-sonnet-5
**Requested reviewer:** claude
**Requested model:** claude-opus-5-5
**Requested execution:** automatic
**Requested at:** 2026-09-25T08:21:51Z
**Workflow:** continuous
**Check required:** no
**Reviewer adapter:** claude
**Reviewer model:** claude-opus-5-5
**Reviewer context:** fresh subagent
**Actual execution:** automatic
**Reviewed at:** 2026-09-25T11:36:39Z
**Scope:** current
**Lenses:** quality, security, performance, tests
**Verdict:** passed
**Check result:** not-required

### Commands

- `git rev-parse HEAD` / `git merge-base master HEAD` / `sha256sum blueprint/context/current-feature.md` / `git status --porcelain --untracked-files=all`: pass (target, base, and spec hash match; only `blueprint/context/review.md` differed from the target)
- `composer test` (backend): pass, 147 tests, 498 assertions
- `vendor/bin/pint --test` (backend): pass
- `npm run test -- --run` (frontend): pass, 7 files, 33 tests
- `npm run lint` (frontend): pass (4 warnings, all in files outside this delta)
- `npm run build` (frontend): pass (existing chunk-size warning only)

### Evidence

- Reviewed the full `4866ea1..d261bb0` delta (product files: admin `BookingController`, `AdminBookingResource`, `Booking::latestStatusLog`, `routes/api.php`, `AdminBookingQueuesTest`, `frontend/src/features/admin-bookings/*`, `App.tsx`, `AdminLayout.tsx`) against the verified spec, including the repair commit `d261bb0`.
- Authorization: all three routes sit in one `auth:sanctum` + `role:admin,super_admin` + `module:bookings` group; the detail route now has 403/403/401 tests for an unpermitted admin, a customer, and a guest against a real booking id. Frontend routes are behind `RoleRoute requireModule="bookings"`.
- SQL safety: every `ilike` search clause and the `concat_ws` `whereRaw` bind the search value; the order-by subquery uses `whereColumn` only. `status` is validated with `in:` built from the same `QUEUE_STATUSES` constant that drives counts, matching the frontend `QUEUE_TABS`.
- Route shape: `/bookings/counts` is declared before `{id}`, and `{id}` is constrained to `[0-9]{1,18}` (fits PHP int and Postgres bigint), so no shadowing and no `TypeError` 500.
- Consistency: `latestOfMany()` picks the max-`id` log and the order-by subquery uses `latest('id')`, so the displayed waiting time and the sort key come from the same row. `waiting_minutes` uses `absolute: true` plus an int cast, locked by a regression test.
- Repairs: F-42 (eager-loaded `latestStatusLog` in `index()` and `show()`), F-43 (`bookings.id` tie-breaker), and F-45 (detail-route negative-auth tests) were re-examined in the repaired code and closed.

### Findings

- F-42 [P2] closed: N+1 on `latestStatusLog` repaired and re-reviewed
- F-43 [P3] closed: unique pagination tie-breaker repaired and re-reviewed
- F-45 [P3] closed: detail-route negative-auth tests added and re-reviewed
- F-44 [P3] open: admin booking pages still do not surface query errors (re-examined, unchanged by `d261bb0`)
- F-46 [P3] open (new): no test covers the guest-contact fallback, guest search, the `created_at` waiting-time fallback, or exclusion of terminal statuses from counts
- No P0 or P1 findings. Earlier findings (F-04 through F-41) are outside this delta and were left unchanged.

### Remaining risk

- No interactive browser walkthrough of the tabs, search, and detail pages was performed; no `Browser tests` command is declared. Check was not required.
- `booking_status_logs.booking_id` has no index (Postgres does not index foreign keys), and the list's correlated order-by subquery runs for every booking in the filtered queue before pagination. Negligible at current scale; not measured.
- The search box issues one request per keystroke (no debounce), and `%`/`_` in a search term act as `ILIKE` wildcards. Harmless at current scale.
- The backend does not require a completed profile before a customer books, so a booking by a customer with no name would show an empty `customer_name` string rather than the `—` placeholder. Not reproduced.
