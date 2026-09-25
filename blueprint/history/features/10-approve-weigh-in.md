# Feature: Approve & weigh-in

**From build-plan:** feature 10
**Build attempt:** 1
**Status:** verified
**Branch:** `feature/approve-weigh-in`

## Goal

An admin (or super admin) with `bookings` module permission moves a bring-your-own
(`customer_supplied`) booking through its two remaining pre-cooking decision
points: first approve it with a scheduled drop-off time (or reject it with a
reason), then, once the raw food is dropped off and weighed, lock in the final
price from the actual weight and confirm the booking. This is the first feature
to change booking status from the admin side - feature 9 only displayed queues.

## In scope

- `POST /api/v1/admin/bookings/{id}/approve` - customer-supplied booking only,
  `pending_review` -> `approved`. Body carries the admin-scheduled drop-off
  time. Records who approved it and when.
- `POST /api/v1/admin/bookings/{id}/reject` - customer-supplied booking only,
  `pending_review` -> `rejected`. Body carries a required reason, stored on the
  booking and as the status-log entry's remarks.
- `POST /api/v1/admin/bookings/{id}/weigh-in` - customer-supplied booking only,
  `approved` -> `confirmed`. Body carries the actual weighed kg for every item
  on the booking. Server recomputes each item's subtotal from its
  already-snapshotted rate and the final weight, sums them into the booking's
  locked `total_amount`, and records who confirmed it and when.
- Extend `AdminBookingResource` with the fields these actions produce
  (`dropoff_at`, `approved_at`, `approved_by_name`, `reject_reason`,
  `confirmed_at`, `confirmed_by_name`, `weighed_at`) so the detail page can
  show the outcome.
- Frontend: an Actions area on `AdminBookingDetailPage` - approve (drop-off
  date/time picker) and reject (reason) forms when the booking is
  `pending_review`; a per-item weigh-in form when it is `approved`; and display
  of the new fields once they are set.

## Out of scope

- Shop order confirmation (`pending_confirmation` -> `confirmed` for
  `shop_supplied` bookings) - feature 11.
- Starting cooking, ready/out-for-delivery, completion, no-show, and
  cancellation handling - feature 12. `approved` -> `no_show` already exists in
  `BookingStatusEngine` but no build-plan item asks for it yet, so no UI or
  endpoint is added for it here.
- Walk-ins (13), payments (14), dashboard (15).
- Editing an already-scheduled drop-off time, editing a submitted final weight,
  or any way to undo a confirm - none is asked for, and `BookingStatusEngine`
  has no transition back out of `confirmed` toward `approved`.

## Build loop

Per `blueprint/config.json`: `workflow.stepReview` is `feature` and
`workflow.checkpointCommits` is `disabled`. Build all steps, self-review, and
present one review packet; `/complete` makes the final commit.

## Build steps

- [x] 1. Add `Admin\ApproveBookingRequest` (`dropoff_at` => `required, date,
  after:now`, matching `StoreBookingRequest`'s `preferred_dropoff_at` rule) and
  `Admin\RejectBookingRequest` (`reason` => `required, string, max:255`,
  matching the `reject_reason` column). Add `BookingController@approve` and
  `@reject`, each wrapped in `DB::transaction`, locking the booking
  (`Booking::lockForUpdate()->findOrFail($id)`), pre-checking
  `BookingStatusEngine::isAllowed($booking->source_type, $booking->status,
  'approved'|'rejected')` and throwing
  `ValidationException::withMessages(['status' => 'This booking cannot be
  approved right now.'])` (or the reject equivalent) when not allowed -
  mirroring `Customer\BookingController::cancel`. On success: `approve` sets
  `dropoff_at`, `approved_at = now()`, `approved_by = $request->user()->id`
  then calls `$statusEngine->transition($booking, 'approved', $request->user()->id)`;
  `reject` sets `reject_reason` then calls `$statusEngine->transition($booking,
  'rejected', $request->user()->id, $reason)` so the reason is also the log
  entry's remarks. Both reload and return
  `new AdminBookingResource($booking->load(['items.service', 'customer',
  'latestStatusLog', 'statusLogs' => fn ($q) => $q->orderBy('id')->with('changer'),
  'approver', 'confirmer']))`. Add the two fields (`dropoff_at`, `approved_at`,
  `approved_by_name` via `whenLoaded('approver')`, `reject_reason`) to
  `AdminBookingResource`. Wire `POST /api/v1/admin/bookings/{id}/approve` and
  `/reject` in the existing `auth:sanctum, role:admin,super_admin,
  module:bookings` group with the same `->where('id', '[0-9]{1,18}')`
  constraint as the other booking routes. Add
  `backend/tests/Feature/Admin/AdminBookingApprovalTest.php` covering: approve
  succeeds from `pending_review` and sets `dropoff_at`/`approved_at`/
  `approved_by`/status/log entry; approve on a booking not in `pending_review`
  (e.g. already `approved`) returns `422`; approve on a `shop_supplied` booking
  returns `422` (proves the `customer_supplied`-only scoping via
  `isAllowed`); a missing or past `dropoff_at` returns `422`; reject succeeds
  and sets `reject_reason`/status/log remarks; a missing `reason` returns
  `422`; an admin without the `bookings` permission and a customer are
  forbidden on both actions; a missing or non-numeric id returns `404`. **Done
  when:** `composer test` passes including the new test.
- [x] 2. Add `Admin\WeighInBookingRequest` (`items` => `required, array`,
  `items.*.id` => `required, integer`, `items.*.final_weight_kg` =>
  `required, numeric, decimal:0,2, min:0.01, max:1000`, matching
  `StoreBookingRequest`'s `est_weight_kg` rule). Add
  `BookingController@weighIn`, same transaction/`isAllowed(...,
  'confirmed')` pattern as step 1, but lock with items loaded
  (`Booking::with('items')->lockForUpdate()->findOrFail($id)`, matching
  `Customer\BookingController::cancel`) since the submitted item ids must be
  checked against them: they must be exactly the booking's own item ids (no
  more, no fewer) or throw
  `ValidationException::withMessages(['items' => 'Every item on this booking
  needs a final weight.'])`. For each item, set `final_weight_kg` and
  `subtotal = round($item->rate * $finalWeightKg, 2)`, save; sum the new
  subtotals into `$booking->total_amount = round($sum, 2)`; set
  `weighed_at = now()`, `confirmed_at = now()`,
  `confirmed_by = $request->user()->id`; call `$statusEngine->transition($booking,
  'confirmed', $request->user()->id)`. Reload and return the resource the same
  way as step 1. Add `confirmed_at`, `weighed_at`, and `confirmed_by_name`
  (via `whenLoaded('confirmer')`) to `AdminBookingResource`. Wire
  `POST /api/v1/admin/bookings/{id}/weigh-in` in the same route group/
  constraint. Extend `AdminBookingApprovalTest` (or a new
  `AdminBookingWeighInTest`) covering: weigh-in succeeds from `approved`,
  correctly computes each item's `subtotal` and the booking's `total_amount`
  for a multi-item booking, and sets `weighed_at`/`confirmed_at`/
  `confirmed_by`/status/log entry; weigh-in on a booking not in `approved`
  returns `422`; submitting an item id that isn't on the booking, or omitting
  one of the booking's items, returns `422`; a missing, zero, or out-of-range
  `final_weight_kg` returns `422`; permission/role/auth/404 cases mirror step
  1. **Done when:** `composer test` passes including the new/extended test.
- [x] 3. Frontend: extend `frontend/src/features/admin-bookings/types.ts`'s
  `AdminBooking` with `dropoff_at`, `approved_at`, `approved_by_name`,
  `reject_reason`, `confirmed_at`, `confirmed_by_name`, `weighed_at` (all
  `string | null`). Add `approveBooking`, `rejectBooking`, `weighInBooking` to
  `api.ts` (each `await ensureCsrfCookie()` then `api.post`, matching
  `ApprovePayload { dropoff_at: string }`, `RejectPayload { reason: string }`,
  `WeighInPayload { items: { id: number; final_weight_kg: number }[] }`,
  following `restockService`/`adjustService`'s shape). Add
  `useApproveBooking`/`useRejectBooking`/`useWeighInBooking` mutations in
  `hooks.ts`, each invalidating `['admin-booking-counts']`, `['admin-bookings']`,
  and `['admin-bookings', 'detail', id]` on success (matching
  `useRestockService`). In `AdminBookingDetailPage.tsx`, add an Actions card:
  when `status === 'pending_review'` (customer-supplied only), render an
  approve form (`type="datetime-local"` input, matching `NewBookingPage`'s
  drop-off picker) and a reject form (reason input), each with its own submit
  button and local error state; when `status === 'approved'`, render one
  numeric input per booking item (labeled with `service_name`) plus a single
  "Confirm weigh-in" submit disabled until every item has a value `> 0`. Every
  submit's `onError` follows `ServicesPage`'s pattern: when
  `isAxiosError(error) && error.response?.status === 422`, show the first
  message from `error.response.data.errors`, otherwise
  `getGenericErrorMessage(error)`. Display the new fields where set: the
  scheduled drop-off time and "Approved by X" once `approved_at` is set, the
  rejection reason when `status === 'rejected'`, and "Weighed in by X" once
  `weighed_at` is set. **Done when:** `npm run test` and `npm run lint` pass,
  `npm run build` succeeds, and a dev-server run shows: approving a
  `pending_review` booking with a drop-off time moves it to the "Awaiting
  drop-off" tab and shows the schedule and approver on its detail page;
  rejecting one shows the reason and removes it from the queue tabs;
  weighing in an `approved` booking with per-item weights moves it to the
  "Confirmed" tab and shows the final per-item and total price.
- [x] 4. Repair independent-review findings F-47/F-48 [P1]: `reject` and
  `weighIn` scoped a transition only through `BookingStatusEngine::isAllowed`,
  but `shop_supplied` bookings can also reach `rejected` (from
  `pending_confirmation`) and `confirmed`, so a `shop_supplied` booking could
  be rejected (losing its reserved stock with no release) or weighed-in
  (overwriting its per-piece price with a roasting-weight formula) through
  these bring-your-own-only endpoints. Added a shared `guardTransition()` that
  also requires `source_type === 'customer_supplied'` before the `isAllowed`
  check, used by `approve`, `reject`, and `weighIn` alike. Also repaired
  F-49 [P3] (duplicate submitted item ids silently collapsed before the
  exact-match check - added `distinct` to `items.*.id`) and F-50 [P3] (the
  "Confirm weigh-in" button was never actually wired to the already-computed
  `canSubmit` check). **Done when:** `composer test` passes including new
  tests for a `shop_supplied` booking getting `422` from `reject` and
  `weigh-in`, and for duplicate item ids being rejected; `npm run build`
  succeeds.

## Verification actually performed

No interactive browser tool was available in this session. The approve/
reject/weigh-in contract was proven against the real running
`php artisan serve` dev database (not just PHPUnit) via direct HTTP calls as
the seeded super admin: seeded three `customer_supplied` bookings (two
`pending_review`, one `approved` with an item), confirmed `GET
/admin/bookings/counts` matched (`pending_review: 2`, `approved: 1`),
approved the first (`dropoff_at`/`approved_at`/`approved_by_name` came back
set, status moved to `approved`), rejected the second (`reject_reason` set,
status log remarks matched the reason), weighed in the third's single item
(180.00 rate x 3.5 kg final weight = 630.00 subtotal and `total_amount`,
status moved to `confirmed`), confirmed the resulting counts
(`pending_review: 0`, `approved: 1`, `confirmed: 1`), and confirmed
re-approving the now-`approved` booking correctly returned `422` with the
same message `BookingStatusEngine::isAllowed` produces. Test data was deleted
afterward. The interactive point-and-click walkthrough of the new Actions
forms in a real browser was not performed and remains open for a manual
check, matching the same documented gap as features 6-9.

## Files / areas

Backend:
- `backend/app/Http/Requests/Admin/ApproveBookingRequest.php` (new)
- `backend/app/Http/Requests/Admin/RejectBookingRequest.php` (new)
- `backend/app/Http/Requests/Admin/WeighInBookingRequest.php` (new)
- `backend/app/Http/Controllers/Api/Admin/BookingController.php` (add `approve`, `reject`, `weighIn`)
- `backend/app/Http/Resources/AdminBookingResource.php` (add the new fields)
- `backend/routes/api.php` (add the three routes)
- `backend/tests/Feature/Admin/AdminBookingApprovalTest.php` (new; may also hold the weigh-in tests, or split into a second file)

Frontend:
- `frontend/src/features/admin-bookings/types.ts`
- `frontend/src/features/admin-bookings/api.ts`
- `frontend/src/features/admin-bookings/hooks.ts`
- `frontend/src/features/admin-bookings/AdminBookingDetailPage.tsx`

## Data / contracts

All three routes: `auth:sanctum`, `role:admin,super_admin`, `module:bookings`,
`{id}` constrained to `[0-9]{1,18}`, `404` for a missing or non-numeric id.

### `POST /api/v1/admin/bookings/{id}/approve`

Request: `{"dropoff_at": "2026-10-01T10:00:00+08:00"}` - required, a valid
date, and after now (same rule as booking creation's `preferred_dropoff_at`).
`422` when the booking is not `customer_supplied` in `pending_review`
(`BookingStatusEngine::isAllowed` is the single source of truth for this).
`200`: `AdminBookingResource` with `status: "approved"`, `dropoff_at`,
`approved_at`, `approved_by_name` set.

### `POST /api/v1/admin/bookings/{id}/reject`

Request: `{"reason": "Raw food quantity too small for this service"}` -
required, string, max 255. `422` under the same not-`pending_review` (or not
`customer_supplied`) condition. `200`: `AdminBookingResource` with
`status: "rejected"`, `reject_reason` set.

### `POST /api/v1/admin/bookings/{id}/weigh-in`

Request: `{"items": [{"id": 12, "final_weight_kg": 3.4}, {"id": 13, "final_weight_kg": 1.2}]}`
- `items` must list every one of the booking's own item ids, each with a
`final_weight_kg` of `0.01`-`1000` (two decimals, same bound as booking
creation's `est_weight_kg`). `422` when the booking is not `customer_supplied`
in `approved`, or when the item ids don't exactly match the booking's items.
`200`: `AdminBookingResource` with `status: "confirmed"`, each item's
`final_weight_kg`/`subtotal` updated, `total_amount` set to their sum,
`weighed_at`, `confirmed_at`, `confirmed_by_name` set.

### Total-lock formula

Mirrors booking creation's `subtotal = round(rate * est_weight_kg, 2)`
(`Customer\BookingController::store`), substituting the final weight and
reusing the same `booking_items.rate` snapshot taken at booking time (never a
re-fetched service rate, so a later service rate change never rewrites this
booking): `final_subtotal = round(rate * final_weight_kg, 2)`;
`total_amount = round(sum(final_subtotal), 2)`.

## Testing

Backend (PHPUnit, `composer test`): per Build steps 1-2, covering approve,
reject, and weigh-in success paths, wrong-status/wrong-source-type `422`s,
validation `422`s (bad drop-off time, missing reason, mismatched or
out-of-range item weights), module-permission/role/auth forbidden cases, and
missing/non-numeric-id `404`s.

Frontend (Vitest, `npm run test`): no new pure logic to unit-test (the total
is server-computed, not previewed client-side); the new forms and field
display are covered by the dev-server check.

No `Browser tests` command is declared, so the approve/reject/weigh-in flow is
verified with the dev server plus the API responses, per `coding-standards.md`.

## Notes for the AI

- `BookingStatusEngine::transition()` writes the `booking_status_logs` row
  itself (status + `changed_by` + `remarks`); it does not touch
  `approved_at`/`approved_by`/`dropoff_at`/`confirmed_at`/`confirmed_by`/
  `weighed_at`/`reject_reason` - the controller must set those directly on
  the model before calling it, exactly like `Customer\BookingController::store`
  already does for `estimated_total`/`preferred_dropoff_at`.
- Reuse `Booking::approver()`/`confirmer()` (already defined on the model,
  currently unused) via `whenLoaded()` in the resource - do not add new
  relations.
- Seed test bookings directly via `Booking::factory()->create(['status' =>
  'pending_review', 'source_type' => 'customer_supplied', ...])` plus
  `->items()->create([...])` for a known `rate`/`est_weight_kg`, the same
  approach feature 9's tests already use, since no earlier feature can drive a
  booking into `approved` yet.
- `no_show` is a real transition from `approved` in `BookingStatusEngine`, but
  nothing in this build-plan item asks for it - leave it unreachable from the
  API until a later feature specs it, rather than adding an endpoint for it
  here.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":16559,"specSha256":"9bf996549606998d121c0dfcc7f35fb14690a1fd9fcf1d90d137010c05c733da","branch":"refs/heads/feature/approve-weigh-in","head":"6580a1e0c8def863009765aae8749ac2ee940545","baseRef":"refs/heads/master","baseCommit":"d1e8a8a215646737b11eb0e8582595be05c88000","sourceTree":"972871bb634d6a93331586266c73eda90ffdd367","absentOptional":[]} -->

## Findings

### 10/F-47 [P1] closed - Reject endpoint also rejects shop orders and leaks their reserved stock

**File:** backend/app/Http/Controllers/Api/Admin/BookingController.php:114-118
**Found:** 2026-09-25 by /audit independent (scope: current; lens: security, quality, tests)
**Why it matters:** The spec's contract says reject is customer-supplied only and returns `422` when the booking is not `customer_supplied` in `pending_review`. The only guard is `isAllowed($booking->source_type, $booking->status, 'rejected')`, and `BookingStatusEngine::TRANSITIONS['shop_supplied']['pending_confirmation']` includes `rejected`. So `POST /admin/bookings/{id}/reject` on a `shop_supplied` `pending_confirmation` order succeeds and moves it to `rejected`. That pulls shop-order confirmation/rejection (feature 11) into this endpoint. It also breaks inventory: `Customer\OrderController` decrements `stock_qty` when the order is placed, and only `Customer\BookingController::cancel` gives it back. This reject path gives back nothing and writes no inventory log, so the reserved stock is lost for good. The UI hides the form for shop orders (`isRoasting` gate), but any admin with the `bookings` module can call the API directly. No test covers reject on a shop-supplied booking. The approve test covers this case only because `approved` happens not to be a shop-supplied target.
**Suggested fix:** In `reject` (and `approve`, for symmetry), add an explicit `$booking->source_type !== 'customer_supplied'` check that throws the same `status` `ValidationException` before `isAllowed`. Add a test that rejecting a `shop_supplied` `pending_confirmation` booking returns `422` and leaves the status and stock unchanged. Current requirement lost: None.
**Resolution:** Fixed 2026-09-25: added a shared `guardTransition()` that requires `$booking->source_type === 'customer_supplied'` in addition to `isAllowed`, used by `approve`, `reject`, and `weighIn` alike. Added `test_reject_on_a_shop_supplied_booking_is_rejected`, asserting `422` and that the booking's status is unchanged. `composer test` passes (173/173).
Closed 2026-09-25 by /audit independent (re-review of `6580a1e`; scope: current; all lenses). `BookingController::guardTransition()` (`BookingController.php:175-185`) now throws the `status` `ValidationException` when `source_type !== 'customer_supplied'`, before `isAllowed`, and `reject` calls it right after `lockForUpdate()->findOrFail()` and before any write (`:110`). A `shop_supplied` `pending_confirmation` booking therefore gets `422` with no status change, no log, and no stock touched (this path never touches inventory), and the transaction rolls back. `test_reject_on_a_shop_supplied_booking_is_rejected` uses exactly that state (where `isAllowed` alone would return true), so it exercises the new check. `composer test` re-run: 173/173 pass. No new defect in the repair.

### 10/F-48 [P1] closed - Weigh-in confirms shop orders and rewrites their prices from shop_price x weight

**File:** backend/app/Http/Controllers/Api/Admin/BookingController.php:138-172
**Found:** 2026-09-25 by /audit independent (scope: current; lens: security, quality, tests)
**Why it matters:** The spec says weigh-in returns `422` unless the booking is `customer_supplied` in `approved`. The guard is only `isAllowed(..., 'confirmed')`, and `shop_supplied` allows `pending_confirmation -> confirmed`. So `POST /admin/bookings/{id}/weigh-in` on a pending shop order, with its own item ids, passes both checks and confirms it (this is feature 11's transition). The call also overwrites each item's `subtotal` with `round(rate * final_weight_kg, 2)`, where `rate` is the snapshotted `shop_price` per unit and the real formula is `rate * qty`. It then overwrites the order's `total_amount` and sets `final_weight_kg`/`weighed_at` on a product order. For example, a 3 x 250.00 order (total 750.00) weighed in at 1 kg becomes total 250.00. The UI hides the form for shop orders, but the API reaches it directly. No test covers weigh-in on a shop-supplied booking.
**Suggested fix:** Require `$booking->source_type === 'customer_supplied'` before the `isAllowed` check, or require the exact `approved` from-status. Throw the existing `status` `ValidationException` otherwise. Add a test that weigh-in on a `shop_supplied` `pending_confirmation` booking returns `422` and leaves `status`, `total_amount`, and item subtotals unchanged. Current requirement lost: None.
**Resolution:** Fixed 2026-09-25: same shared `guardTransition()` as F-47 (`source_type === 'customer_supplied'` required before `isAllowed`). Added `test_weigh_in_on_a_shop_supplied_booking_is_rejected`, asserting `422` and that the booking's status and the item's subtotal are unchanged. `composer test` passes (173/173).
Closed 2026-09-25 by /audit independent (re-review of `6580a1e`; scope: current; all lenses). `weighIn` calls `guardTransition($booking, $statusEngine, 'confirmed', 'weighed in')` (`BookingController.php:130`) before the item-id check and before any item or booking write, so a `shop_supplied` booking cannot be confirmed or repriced here. `test_weigh_in_on_a_shop_supplied_booking_is_rejected` seeds `shop_supplied`/`pending_confirmation` (which `isAllowed(..., 'confirmed')` accepts) with a 3 x 250.00 item, submits its own item id, and asserts `422`, unchanged status, and unchanged 750.00 subtotal. `composer test` re-run: 173/173 pass. No new defect in the repair.

### 10/F-49 [P3] closed - Weigh-in silently accepts duplicate item ids, keeping the last weight

**File:** backend/app/Http/Requests/Admin/WeighInBookingRequest.php:27; backend/app/Http/Controllers/Api/Admin/BookingController.php:144-151
**Found:** 2026-09-25 by /audit independent (scope: current; lens: quality, tests)
**Why it matters:** `keyBy` collapses repeated ids before the exact-match check. A payload such as `[{id: A, 3.5}, {id: B, 1.8}, {id: A, 9.0}]` therefore passes the check and locks item A at 9.0 kg. It should be rejected as ambiguous. Only admins can reach this, and the shipped UI sends each id once, so the risk is low. It is still an ambiguous input to a money-locking, irreversible action.
**Suggested fix:** Add `distinct` to `items.*.id` in `WeighInBookingRequest`, plus one test that submits a duplicate id and gets `422`. Current requirement lost: None.
**Resolution:** Fixed 2026-09-25: added `distinct` to `items.*.id`. Added `test_duplicate_item_ids_are_rejected`, asserting `422` on both duplicate entries. `composer test` passes (173/173).
Closed 2026-09-25 by /audit independent (re-review of `6580a1e`; scope: current; all lenses). `WeighInBookingRequest.php:27` is `['required', 'integer', 'distinct']`, so a repeated id fails validation before the controller's `keyBy` can collapse it. The test asserts errors on `items.0.id` and `items.1.id`. No new defect.

### 10/F-50 [P3] closed - Confirm weigh-in button is not disabled until every weight is entered

**File:** frontend/src/features/admin-bookings/AdminBookingDetailPage.tsx:131
**Found:** 2026-09-25 by /audit independent (scope: current; lens: quality)
**Why it matters:** Spec step 3 says the single "Confirm weigh-in" submit is "disabled until every item has a value `> 0`". The button is disabled only while the request is pending (`disabled={weighIn.isPending}`). `canSubmit` is used only to show an error after a click. The behavior is still safe because the server validates, but it drifts from the approved spec.
**Suggested fix:** Use `disabled={weighIn.isPending || !canSubmit}` (keeping the click-time message is harmless), or amend the spec to describe the click-time validation. Current requirement lost: None.
**Resolution:** Fixed 2026-09-25: `disabled={weighIn.isPending || !canSubmit}`, matching the suggested fix exactly. `npm run build` succeeds.
Closed 2026-09-25 by /audit independent (re-review of `6580a1e`; scope: current; all lenses). `AdminBookingDetailPage.tsx:131` is wired to `weighIn.isPending || !canSubmit`, and `canSubmit` requires `Number(weights[item.id]) > 0` for every item, matching spec step 3. The click-time message branch is now unreachable but harmless (as the suggested fix allowed). `npm run lint`, `npm run test` (33/33), and `npm run build` pass. No new defect.

## Independent review

**Status:** passed
**Target commit:** 6580a1e0c8def863009765aae8749ac2ee940545
**Base commit:** d1e8a8a215646737b11eb0e8582595be05c88000
**Base ref:** master
**Spec hash:** 9bf996549606998d121c0dfcc7f35fb14690a1fd9fcf1d90d137010c05c733da
**Prepared by:** claude
**Builder model:** claude-sonnet-5
**Requested reviewer:** claude
**Requested model:** claude-opus-5-5
**Requested execution:** automatic
**Requested at:** 2026-09-25T12:28:02Z
**Workflow:** regular
**Check required:** no
**Reviewer adapter:** claude
**Reviewer model:** claude-opus-5-5
**Reviewer context:** fresh subagent
**Actual execution:** automatic
**Reviewed at:** 2026-09-25T12:30:25Z
**Scope:** current
**Lenses:** quality, security, performance, tests
**Verdict:** passed
**Check result:** not-required

### Commands

- `git rev-parse HEAD`, `git merge-base master HEAD`, `sha256sum blueprint/context/current-feature.md`, `git status --porcelain --untracked-files=all`: pass (target, base, spec hash match; only review.md and findings.md differ)
- `composer test` (backend): pass (173/173, 634 assertions)
- `vendor/bin/pint --test` on the changed backend files: pass
- `npm run lint` (frontend): pass (exit 0; warnings only, none in admin-bookings files)
- `npm run test` (frontend): pass (33/33)
- `npm run build` (frontend): pass (chunk-size warning only)

### Evidence

- Reviewed the full `d1e8a8a..6580a1e` delta: Admin `BookingController` (approve/reject/weighIn/guardTransition/detailEagerLoads), three new FormRequests, `AdminBookingResource`, `routes/api.php`, `AdminBookingApprovalTest`, `AdminBookingWeighInTest`, and frontend `types.ts`, `api.ts`, `hooks.ts`, `AdminBookingDetailPage.tsx`, against the verified spec.
- Security: all three routes sit in the existing `auth:sanctum`, `role:admin,super_admin`, `module:bookings` group with the `[0-9]{1,18}` id constraint; tests cover no-permission admin, customer, unauthenticated, and 404 cases for each action.
- `guardTransition()` requires `source_type === 'customer_supplied'` and `BookingStatusEngine::isAllowed` before any write, inside `DB::transaction` with `lockForUpdate()`; shop-supplied reject and weigh-in now return 422 with status and subtotal unchanged (tests seed `pending_confirmation`, where `isAllowed` alone passes).
- Weigh-in item ids must exactly match the booking's own items (strict sorted-id compare, plus `distinct`); subtotal and total use the snapshotted `booking_items.rate`, per the spec's total-lock formula; the multi-item test asserts 525.00 + 180.00 = 705.00.
- Performance: per-item saves are bounded by one booking's items; no N+1 in the detail reload (single eager-load list shared with `show`).
- Frontend: forms render only for `customer_supplied` in the matching status; 422 messages are surfaced; mutations invalidate counts, lists, and detail; the Confirm button is disabled until every weight is greater than 0.

### Findings

- F-47 [P1]: closed (repair verified)
- F-48 [P1]: closed (repair verified)
- F-49 [P3]: closed (repair verified)
- F-50 [P3]: closed (repair verified)
- F-51 [P3]: new, open (an approve `dropoff_at` with a non-UTC offset is stored shifted; the SPA path sends UTC and is unaffected)

### Remaining risk

- No browser test harness is declared, so the new Actions forms were not exercised in a real browser during this review (Check not required).
- F-44 [P3] (admin booking detail page does not surface query errors) remains open in a file this delta touched; this delta did not change that code.
- F-51 [P3] open: direct API callers sending an offset `dropoff_at` get a shifted stored time.
