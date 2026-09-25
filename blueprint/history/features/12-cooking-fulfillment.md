# Feature: Cooking & fulfillment

**From build-plan:** feature 12
**Build attempt:** 1
**Status:** verified
**Branch:** feature/cooking-fulfillment

## Goal

Give admin the remaining booking-lifecycle actions for both booking types:
start cooking (sets an estimated ready time), mark ready for pickup or out for
delivery, complete, mark a bring-your-own booking a no-show, and cancel -
releasing reserved shop stock on cancel. This closes every transition
`BookingStatusEngine` already defines but that no endpoint reaches yet.

## In scope

- Admin action `start-cooking`: `confirmed -> cooking` for either source type.
  Sets `cooking_started_at = now()` and `est_ready_at = cooking_started_at +`
  the **longest** `services.est_minutes` among the booking's items (a booking
  with several items is treated as one batch; the slowest item sets the
  ready time - not the sum, not weighted by quantity).
- Admin action `ready`: `cooking -> ready`, only when `fulfillment ===
  'pickup'`; otherwise `422`.
- Admin action `out-for-delivery`: `cooking -> out_for_delivery`, only when
  `fulfillment === 'delivery'`; otherwise `422`.
- Admin action `complete`: `ready -> completed` or `out_for_delivery ->
  completed`. Sets `completed_at = now()`.
- Admin action `no-show`: `approved -> no_show`, bring-your-own only (mirrors
  the existing `guardTransition` BYO-only pattern). Optional `remarks`.
- Admin action `cancel`: moves any booking from a status
  `BookingStatusEngine` already allows into `cancelled` (customer-supplied:
  `pending_review`, `approved`, `confirmed`; shop-supplied:
  `pending_confirmation`, `confirmed`). Optional `remarks`. For a
  `shop_supplied` booking, releases the reserved stock exactly like the
  customer's own cancel endpoint does (`Customer\BookingController::cancel`):
  increments each item's `services.stock_qty` by `qty` in ascending
  `service_id` order and writes a matching `inventory_logs` row
  (`reason: 'release'`).
- `AdminBookingResource`: add `cooking_started_at`, `est_ready_at`,
  `completed_at` so the admin UI can show them.
- Admin booking detail page: a quick action per reachable status (start
  cooking on `confirmed`; ready/out-for-delivery on `cooking`, whichever
  matches `fulfillment`; complete on `ready`/`out_for_delivery`; no-show
  alongside the existing weigh-in block on `approved` for BYO bookings) plus a
  cancel control wherever cancel is currently allowed, mirroring the
  customer app's own cancellable-status list.
- Feature tests for every new transition (allowed and disallowed), the
  fulfillment guard on ready/out-for-delivery, the BYO-only guard on no-show,
  and stock release on cancel.

## Out of scope

- Payments, the unpaid-balance guard on complete ("Decisions to make during
  the build" in `roastingservice-build-plan.md` - feature 14, not built yet).
- Walk-in bookings (feature 13).
- Scheduled/automatic no-show after a drop-off cutoff (backlog item, needs a
  queue/scheduler that doesn't exist).
- Any change to `BookingStatusEngine`'s transition table - every transition
  this feature needs already exists there (added in feature 10's build, left
  unreachable on purpose).
- A generic "Cancelled/Rejected/Completed" history tab or an "All" queue tab
  (not asked for by this build-plan item; `QUEUE_STATUSES` stays as-is).
- Showing `est_ready_at` to the customer (not requested by this item; the
  customer status timeline already shows the `cooking` log entry generically
  via `humanizeStatus`).

## Build loop

Config: `workflow.stepReview = "feature"`, `workflow.checkpointCommits =
"disabled"`. Implement all build steps in one pass; the user reviews once at
the end of the full diff instead of after each step. No checkpoint commits
between steps; `/complete` makes the one feature commit.

## Build steps

- [x] 1. **Backend: fulfillment actions on `Api\Admin\BookingController`**
   - Add `startCooking(Request, BookingStatusEngine, int $id)`: transaction,
     `Booking::with('items.service')->lockForUpdate()->findOrFail($id)`, guard
     with `isAllowed($booking->source_type, $booking->status, 'cooking')`
     (both source types reach `cooking` from `confirmed`, so no source-type
     restriction like `guardTransition`/`guardOrderTransition` - add a third
     private guard, `guardAnyTransition(Booking, BookingStatusEngine, string
     $to, string $action)`, that only checks `isAllowed`, and reuse it here).
     Compute `$minutes = $booking->items->max(fn ($item) =>
     $item->service->est_minutes)`, then set `cooking_started_at = now()`,
     `est_ready_at = $booking->cooking_started_at->addMinutes($minutes)`,
     save, then `$statusEngine->transition($booking, 'cooking',
     $request->user()->id)`.
   - Add `ready(Request, BookingStatusEngine, int $id)`: same lock/guard via
     `guardAnyTransition(..., 'ready', 'marked ready')`, plus a check that
     `$booking->fulfillment === 'pickup'` (else throw the same `status`
     `ValidationException` shape used elsewhere) before transitioning.
   - Add `outForDelivery(...)`: mirrors `ready`, target `out_for_delivery`,
     requires `fulfillment === 'delivery'`.
   - Add `complete(...)`: guard via `guardAnyTransition(..., 'completed',
     'completed')`, set `completed_at = now()`, transition.
   - Add `noShow(Request, BookingStatusEngine, int $id)`: guard via the
     existing `guardTransition` (BYO-only) with target `no_show`; validate
     `remarks` inline (`$request->validate(['remarks' => ['nullable',
     'string', 'max:255']])`); transition with that remarks value.
   - Add `cancel(Request, BookingStatusEngine, int $id)`: guard via
     `guardAnyTransition(..., 'cancelled', 'cancelled')`; validate `remarks`
     the same way as `noShow`; when `$booking->source_type === 'shop_supplied'`,
     eager-load `items` and release stock exactly like
     `Customer\BookingController::cancel` (`$booking->items->sortBy
     ('service_id')`, atomic `increment('stock_qty', $item->qty)` per row,
     matching `inventory_logs` row with `reason: 'release'`,
     `created_by: $request->user()->id`); then transition to `cancelled`
     with the remarks.
   - All six actions return `new AdminBookingResource($booking->load
     (self::detailEagerLoads()))`, matching the existing action methods.
   - Done when: `composer test` passes for the new tests below.

- [x] 2. **Backend: `AdminBookingResource` and routes**
   - Add `cooking_started_at`, `est_ready_at`, `completed_at` to
     `AdminBookingResource::toArray()`, next to the other timestamp fields.
   - In `backend/routes/api.php`, inside the existing `module:bookings` group,
     right after `reject-order`, add (each with the existing
     `where('id', '[0-9]{1,18}')` constraint):
     - `POST /admin/bookings/{id}/start-cooking` -> `startCooking`
     - `POST /admin/bookings/{id}/ready` -> `ready`
     - `POST /admin/bookings/{id}/out-for-delivery` -> `outForDelivery`
     - `POST /admin/bookings/{id}/complete` -> `complete`
     - `POST /admin/bookings/{id}/no-show` -> `noShow`
     - `POST /admin/bookings/{id}/cancel` -> `cancel`
   - Done when: `php artisan route:list` shows all six new routes.

- [x] 3. **Backend tests**
   - Add `backend/tests/Feature/Admin/AdminBookingFulfillmentTest.php`
     (same `loginAsSuperAdmin`/`loginAsAdminWithoutBookingsPermission`/
     `withHeader('Referer', ...)` setup as `AdminBookingApprovalTest`),
     covering, for both source types where a case applies:
     - `start-cooking` from `confirmed` succeeds: status `cooking`,
       `cooking_started_at` set, `est_ready_at` equals
       `cooking_started_at` plus the **longest** item's `est_minutes` (seed
       two items with different `est_minutes` on one booking and assert the
       longer one wins, not the sum).
     - `start-cooking` from any other status is `422` and leaves the booking
       unchanged.
     - `ready` from `cooking` on a `pickup` booking succeeds (status
       `ready`); on a `delivery` booking it is `422` and status is unchanged.
     - `out-for-delivery` from `cooking` on a `delivery` booking succeeds
       (status `out_for_delivery`); on a `pickup` booking it is `422`.
     - `complete` from `ready` succeeds (status `completed`,
       `completed_at` set); from `out_for_delivery` succeeds the same way;
       from any earlier status is `422`.
     - `no-show` from `approved` on a `customer_supplied` booking succeeds
       (status `no_show`, log `remarks` set when provided); on a
       `shop_supplied` booking (which never reaches `approved`) or from any
       other BYO status it is `422`.
     - `cancel` succeeds from every status the engine allows for each source
       type (`pending_review`/`approved`/`confirmed` BYO,
       `pending_confirmation`/`confirmed` shop) and is `422` from `cooking`,
       `ready`, `out_for_delivery`, `completed`, `rejected`, `no_show`, and
       already-`cancelled`.
     - `cancel` on a `shop_supplied` booking releases stock: seed 1-2
       `booking_items` against real `Service` rows with known `stock_qty`,
       assert each increases by exactly its item's `qty` and a matching
       `inventory_logs` row exists (`reason: release`); a BYO cancel leaves
       `Service.stock_qty` and `inventory_logs` untouched.
     - Admin without the `bookings` permission is forbidden on all six
       actions; customer is forbidden; unauthenticated is rejected; unknown
       id is `404`.
   - Done when: `composer test` is green, including this new file.

- [x] 4. **Frontend: API + hooks**
   - `frontend/src/features/admin-bookings/api.ts`: add
     `startCooking(id)`, `markReady(id)`, `markOutForDelivery(id)`,
     `completeBooking(id)` (all no body), and `noShowBooking(id, payload:
     { remarks?: string })`, `cancelBooking(id, payload: { remarks?: string
     })`, each calling `ensureCsrfCookie()` first and posting to the matching
     route above, returning `Promise<AdminBooking>` like the existing
     actions.
   - `frontend/src/features/admin-bookings/hooks.ts`: add
     `useStartCooking()`, `useMarkReady()`, `useMarkOutForDelivery()`,
     `useCompleteBooking()`, `useNoShowBooking()`, `useCancelBooking()`
     mutations, all via the existing `useBookingActionInvalidation()`
     helper.
   - `frontend/src/features/admin-bookings/types.ts`: add `cooking_started_at:
     string | null`, `est_ready_at: string | null`, `completed_at: string |
     null` to `AdminBooking`; add a small `CANCELLABLE_STATUSES` map (same
     shape and values as `frontend/src/features/bookings/status.ts`'s, kept
     local to this feature folder like the rest of `AdminBooking` already
     is) and an `isAdminCancellable(booking)` helper.
   - Done when: `npm run lint` passes.

- [x] 5. **Frontend: fulfillment actions UI**
   - In `AdminBookingDetailPage.tsx`, add small action components mirroring
     `ConfirmRejectOrderActions`'s shape (a bordered card, a button per
     action, `getActionErrorMessage` on failure):
     - `StartCookingAction` - one button, rendered when `booking.status ===
       'confirmed'`.
     - `CookingActions` - rendered when `booking.status === 'cooking'`; shows
       "Mark ready" when `booking.fulfillment === 'pickup'`, or "Out for
       delivery" when `'delivery'`.
     - `CompleteAction` - one button, rendered when `booking.status ===
       'ready' || booking.status === 'out_for_delivery'`.
     - `NoShowAction` - a remarks `Input` (optional) + button, rendered
       alongside the existing `WeighInActions` when `isRoasting &&
       booking.status === 'approved'`.
     - A `CancelAction` - a remarks `Input` (optional) + button, rendered
       whenever `isAdminCancellable(booking)` is true, placed after the
       other conditional action blocks.
   - Also render `cooking_started_at`, `est_ready_at`, and `completed_at`
     (when set) in the existing customer/status info card, matching the
     existing `approved_at`/`weighed_at` display style.
   - Done when: `npm run lint` and `npm run build` pass, and a manual check
     in the running app: take one bring-your-own booking and one shop order
     from `Confirmed` through `Cooking` -> (`Ready` or `Out for delivery`
     depending on fulfillment) -> `Completed`; separately, approve a BYO
     booking and mark it `No-show`; separately, cancel a shop order from
     `Confirmed` and confirm its stock is back up on the services page.

## Files / areas

- `backend/app/Http/Controllers/Api/Admin/BookingController.php`
- `backend/app/Http/Resources/AdminBookingResource.php`
- `backend/routes/api.php`
- `backend/tests/Feature/Admin/AdminBookingFulfillmentTest.php` (new)
- `frontend/src/features/admin-bookings/api.ts`
- `frontend/src/features/admin-bookings/hooks.ts`
- `frontend/src/features/admin-bookings/types.ts`
- `frontend/src/features/admin-bookings/AdminBookingDetailPage.tsx`

## Data / contracts

- No schema changes. `bookings.cooking_started_at`/`est_ready_at`/
  `completed_at` already exist (nullable, unused until now);
  `booking_status_logs.status` already allows `cooking`/`ready`/
  `out_for_delivery`/`completed`/`no_show`/`cancelled`;
  `inventory_logs.reason` already allows `release`.
- `POST /api/v1/admin/bookings/{id}/start-cooking` - no body. `200` with the
  updated resource (`status: "cooking"`, `cooking_started_at`, `est_ready_at`
  set). `422 {errors: {status: [...]}}` when not `confirmed`.
- `POST /api/v1/admin/bookings/{id}/ready` - no body. `200`
  (`status: "ready"`). `422` when not `cooking`, or when `fulfillment !==
  'pickup'`.
- `POST /api/v1/admin/bookings/{id}/out-for-delivery` - no body. `200`
  (`status: "out_for_delivery"`). `422` when not `cooking`, or when
  `fulfillment !== 'delivery'`.
- `POST /api/v1/admin/bookings/{id}/complete` - no body. `200`
  (`status: "completed"`, `completed_at` set). `422` when not `ready`/
  `out_for_delivery`.
- `POST /api/v1/admin/bookings/{id}/no-show` - body `{ remarks?: string
  (max 255) }`. `200` (`status: "no_show"`). `422` when not a
  `customer_supplied` booking in `approved`.
- `POST /api/v1/admin/bookings/{id}/cancel` - body `{ remarks?: string
  (max 255) }`. `200` (`status: "cancelled"`). `422` when the current
  status/source-type pair has no `cancelled` transition in
  `BookingStatusEngine`. Releases stock for `shop_supplied` only, same
  shape as the existing customer cancel and `rejectOrder`.
- All six: `403` outside the `bookings` module, `404` for an unknown id,
  same shapes as every other admin booking action.
- `AdminBookingResource` gains `cooking_started_at`, `est_ready_at`,
  `completed_at` (all nullable ISO timestamps, `null` until set).

## Testing

- Backend (PHPUnit, `composer test`): new `AdminBookingFulfillmentTest`
  covers every new transition (allowed and disallowed), the fulfillment
  guard on ready/out-for-delivery, the max-not-sum `est_ready_at` rule, the
  BYO-only guard on no-show, stock release correctness on cancel, and the
  standard permission/auth/not-found matrix - all logic with a real
  right/wrong answer, per the project's testing scope rule.
- Frontend: no new pure-logic units beyond `isAdminCancellable`, a direct
  port of the existing `isCancellable`'s lookup-table shape; covered by
  `npm run lint`, `npm run build`, and the manual browser check in step 5.
  No `Browser tests` command is declared, so the fulfillment flow is
  verified with the dev server plus API responses, per
  `coding-standards.md`.

## Notes for the AI

- `BookingStatusEngine::TRANSITIONS` already has every edge this feature
  needs (`confirmed -> cooking`, `cooking -> ready|out_for_delivery`,
  `ready|out_for_delivery -> completed`, `approved -> no_show`, and
  `cancelled` from every currently-reachable pre-cooking status) - do not
  edit it.
- `est_ready_at` uses the **longest** item's `est_minutes`, confirmed with
  the user during spec review (not the sum, not weighted by quantity): a
  booking's items are treated as one roasting batch, so the slowest item
  sets the ready time.
- `ready` and `out-for-delivery` are guarded by `fulfillment`, not by
  source type - both booking types share the same fulfillment field and the
  same rule.
- `no_show` is BYO-only because `BookingStatusEngine::TRANSITIONS
  ['shop_supplied']` has no `approved` key at all; `guardTransition` (BYO
  check + `isAllowed`) already exists for this, reuse it rather than adding
  a fourth guard.
- The new `guardAnyTransition` is for actions valid across both source
  types (`start-cooking`, `ready`, `out-for-delivery`, `complete`,
  `cancel`) - it is deliberately not a replacement for `guardTransition`/
  `guardOrderTransition`, which stay BYO-only/shop-only for approve/reject/
  weigh-in/confirm-order/reject-order.
- Mirror `Customer\BookingController::cancel`'s stock-release ordering
  exactly (`sortBy('service_id')`, atomic `increment()`) so an admin cancel
  and a concurrent customer order or cancel can't deadlock.
- Keep all six new actions in the existing `Api\Admin\BookingController`,
  next to `approve`/`reject`/`weighIn`/`confirmOrder`/`rejectOrder` - same
  controller, same `bookings` module permission, not a new resource.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":17135,"specSha256":"5a35b4a325be80a207368947e3266ea6296c6ee26cee1fd4df26cc30c4f95f19","branch":"refs/heads/feature/cooking-fulfillment","head":"fddfa203f6435be282d1b77eba5d95700db303d6","baseRef":"refs/heads/master","baseCommit":"62cdaec76561b50d6db90d6bac5e89165e24aaf7","sourceTree":"d9954e016bf842af5336e0b58052a11ed000c215","absentOptional":[]} -->

## Independent review

**Status:** passed
**Target commit:** fddfa203f6435be282d1b77eba5d95700db303d6
**Base commit:** 62cdaec76561b50d6db90d6bac5e89165e24aaf7
**Base ref:** master
**Spec hash:** 5a35b4a325be80a207368947e3266ea6296c6ee26cee1fd4df26cc30c4f95f19
**Prepared by:** claude
**Builder model:** claude-sonnet-5
**Requested reviewer:** claude
**Requested model:** claude-opus-5-5
**Requested execution:** automatic
**Requested at:** 2026-09-25T14:47:30Z
**Workflow:** regular
**Check required:** no
**Reviewer adapter:** claude
**Reviewer model:** claude-opus-5-5
**Reviewer context:** fresh subagent
**Actual execution:** automatic
**Reviewed at:** 2026-09-25T14:51:17Z
**Scope:** current
**Lenses:** quality, security, performance, tests
**Verdict:** passed
**Check result:** not-required

### Commands

- `git rev-parse HEAD`, `git merge-base master HEAD`, `git status --porcelain`, `sha256sum blueprint/context/current-feature.md`: pass (preconditions held)
- `composer test` (backend): pass (206 tests, 862 assertions)
- `npm run lint` (frontend): pass (4 pre-existing warnings, none in changed files)
- `npx tsc -p tsconfig.app.json --noEmit` (frontend typecheck): pass
- `npm run test` (frontend Vitest): pass (33 tests)
- `npm run build`: not run (writes build output; typecheck run instead)

### Evidence

- Reviewed the full `62cdaec..fddfa20` delta: admin `BookingController` (six new actions plus `guardAnyTransition`), `AdminBookingResource`, `routes/api.php`, `AdminBookingFulfillmentTest.php`, and the admin-bookings `api.ts`/`hooks.ts`/`types.ts`/`AdminBookingDetailPage.tsx`.
- Checked against `BookingStatusEngine::TRANSITIONS`: every new action is guarded by `isAllowed` under a `lockForUpdate` transaction. `ready`/`out-for-delivery` enforce `fulfillment`, and `no-show` reuses the BYO-only `guardTransition`.
- `est_ready_at` uses the longest item `est_minutes`. The `datetime` casts make `cooking_started_at->addMinutes()` operate on a copy, so the start time is not mutated. `est_minutes` is non-null and services are delete-restricted.
- Admin cancel releases stock only for `shop_supplied`, in `service_id` order, matching `Customer\BookingController::cancel`. The release runs after the guard, inside the same locked transaction.
- All six routes sit inside the existing `auth:sanctum` + `role:admin,super_admin` + `module:bookings` group with the numeric id constraint. Tests cover 403/401/404.
- The frontend `CANCELLABLE_STATUSES` matches the engine and `bookings/status.ts`. Each new action surfaces mutation errors, and invalidation reuses `useBookingActionInvalidation`.

### Findings

- New: F-54 [P3] open (fulfillment test coverage gaps), F-55 [P3] open (section-divider comments in new test file)
- Re-examined, still open: F-04 [P3], F-44 [P3], F-46 [P3], F-51 [P3], F-52 [P2] (reject-order still releases unsorted; release loop now copied three times)
- No P0 or P1 findings

### Remaining risk

- `npm run build` (vite build) was not run in the reviewer session. The TypeScript typecheck passed, but the bundle was not produced.
- No `Browser tests` command is declared, so the admin fulfillment UI flow was not exercised in a browser. Check was not required.
- Concurrency (deadlock) behavior of the stock-release paths was reviewed by reading only, not reproduced under load (see F-52).
- Admin cancel and no-show are single-click and irreversible, with no confirmation step. This matches the existing project pattern and is not recorded as a finding.
