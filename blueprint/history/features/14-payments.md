# Feature: Payments

**From build-plan:** feature 14
**Build attempt:** 1
**Branch:** feature/payments
**Status:** verified

## Goal

Let an admin with the `payments` module permission record a full payment
(cash, GCash, or card) against a booking once its total is locked, see paid
vs. balance on that booking, and browse a filterable list of all recorded
payments.

## In scope

- `payments` table, `Payment` model, and `Booking::payments()` relation, per
  the documented data model (`type`, `amount`, `method`, `reference_no`,
  `status`, `paid_at`, `recorded_by`).
- Admin endpoint to record one full payment against a booking whose total is
  final (`confirmed`, `cooking`, `ready`, `out_for_delivery`, or `completed`),
  gated by the existing `module:payments` permission check
  (`hasModulePermission`), same as `services`/`inventory`/`bookings`.
- `paid_amount` and `balance` shown on the admin booking detail page.
- Admin endpoint + page for a filterable, paginated list of payment records
  (filter by method, search by booking code / customer or guest name /
  phone), reusing the existing admin list/pagination/search conventions from
  `AdminBookingController::index` and `InventoryController::logs`.
- Nav link and route guard for the new `/admin/payments` page, matching the
  existing `services`/`inventory`/`bookings` pattern in `AdminLayout` and
  `App.tsx`.

## Out of scope

- Downpayment and balance-type payments (`payments.type` values
  `downpayment`/`balance`) and the `settings` table - the project overview's
  Open Questions mark this explicitly out of v1. This feature only ever
  writes `type = 'full'`.
- Refunds or any use of `payments.status = 'refunded'`, and editing/deleting
  a recorded payment. Only creating a `paid` payment is built here.
- Any change to booking status transitions, cooking/fulfillment, or the
  sales dashboard (feature 15) - payments are recorded independently of
  status and do not move a booking forward or back.
- Partial payments / multiple payment rows per booking. See design decision
  below.

## Build loop

Per `blueprint/config.json`: `workflow.stepReview` is `"feature"` (one
combined review packet after all steps below, not a pause after each one)
and `workflow.checkpointCommits` is `"disabled"` (no intermediate commits).
`/complete` creates the single final commit for this feature after review.

## Build steps

- [x] 1. **Payments data model** - migration creates the `payments` table
      exactly as documented (`booking_id` FK cascade-on-delete like
      `booking_items`, `type` enum `full|downpayment|balance`, `amount`
      decimal(10,2), `method` enum `cash|gcash|card`, `reference_no`
      nullable string, `status` enum `pending|paid|refunded`, `paid_at`
      nullable timestamp, `recorded_by` FK to `users` null-on-delete like
      `approved_by`). Add the `Payment` model (casts `amount` to
      `decimal:2`, `paid_at` to `datetime`; `booking()` and `recorder()`
      belongs-to) and `Booking::payments(): HasMany`.
      **Done when:** `php artisan migrate` runs clean and `composer test`
      still passes (no behavior change yet).

- [x] 2. **Record-payment endpoint** - `StorePaymentRequest` (`method`
      required, `in:cash,gcash,card`; `reference_no` nullable string
      max:255). `BookingPaymentController::store` on
      `POST /admin/bookings/{id}/payments`, in a new route group under
      `module:payments` (alongside `auth:sanctum`, `role:admin,super_admin`,
      matching the existing `module:bookings` group's shape). Inside a
      `DB::transaction`, lock the booking row, reject with 422 when its
      status isn't one of `confirmed`/`cooking`/`ready`/`out_for_delivery`/
      `completed` ("This booking cannot accept payment right now."), reject
      with 422 when it already has a `paid` payment ("This booking is
      already fully paid."), otherwise create a `Payment` with
      `type = 'full'`, `amount = booking.total_amount`, the submitted
      `method`/`reference_no`, `status = 'paid'`, `paid_at = now()`,
      `recorded_by = $request->user()->id`. Extend `AdminBookingResource`
      with `paid_amount` (sum of that booking's `paid` payments, `"0.00"`
      when none) and `balance` (`total_amount - paid_amount`, `null` while
      `total_amount` is null); eager-load `payments` in
      `detailEagerLoads()` and in this action's response.
      **Done when:** a new `AdminBookingPaymentTest` covers: a `confirmed`
      customer-supplied booking and a `confirmed` shop-supplied booking can
      each be paid in full; a booking not yet confirmed (e.g.
      `pending_review`) is rejected; a second payment attempt on an
      already-paid booking is rejected; an admin without the `payments`
      permission gets 403; `composer test` passes.

- [x] 3. **Filterable payments list endpoint** - `PaymentResource` (`id`,
      `booking_id`, `booking_code`, `customer_name`, `customer_phone`,
      `source_type`, `type`, `amount`, `method`, `reference_no`, `status`,
      `paid_at`, `recorded_by_name`, `created_at`). `PaymentController::index`
      on `GET /admin/payments` in the same `module:payments` group;
      validates optional `method` (`in:cash,gcash,card`) and `search`
      (matched against the related booking's `code`, `guest_name`,
      `guest_phone`, or customer name/phone the same way
      `AdminBookingController::index` does), orders newest first
      (`orderByDesc('id')`), paginates, eager-loads `booking.customer` and
      `recorder`.
      **Done when:** a new `AdminPaymentsListTest` covers: filtering by
      method, searching by booking code and by customer/guest name/phone,
      pagination, and 403 without the `payments` permission; `composer test`
      passes.

- [x] 4. **Booking detail "Record payment" UI** - extend
      `features/admin-bookings/types.ts` (`AdminBooking.paid_amount`,
      `AdminBooking.balance`), `api.ts` (`recordPayment(id, payload)`), and
      `hooks.ts` (`useRecordPayment`, reusing
      `useBookingActionInvalidation`). In `AdminBookingDetailPage.tsx`, show
      Paid / Balance under the existing total block, and add a
      `RecordPaymentActions` component (method select: Cash/GCash/Card,
      optional reference number input, submit button) rendered only when
      the booking's status is payment-eligible, `balance > 0`, and the
      logged-in admin has the `payments` permission (`super_admin` or
      `me.permissions.includes('payments')`), mirroring the existing
      `ApproveRejectActions`/`WeighInActions` structure and error handling.
      **Done when:** running the app and recording a payment against a
      confirmed booking updates Paid/Balance and hides the form;
      `npm run lint` and `npm run test` pass.

- [x] 5. **Payments list page & nav** - new `features/admin-payments/`
      folder (`types.ts`, `api.ts`, `hooks.ts`, `PaymentsListPage.tsx`)
      rendering a method filter, a search box, a paginated table (booking
      code, customer/guest, amount, method, reference no., paid at,
      recorded by), each row linking to its booking detail, mirroring
      `AdminBookingsPage`'s list/filter/pagination structure. Add the
      `/admin/payments` route in `App.tsx` under
      `<RoleRoute allow={['admin','super_admin']} requireModule="payments" />`
      and a "Payments" link in `AdminLayout` gated the same way as the
      existing nav links.
      **Done when:** running the app shows the list, the method filter and
      search narrow results, an admin without the `payments` permission is
      redirected away from `/admin/payments` and doesn't see the nav link;
      `npm run lint` and `npm run build` succeed.

## Files / areas

Backend:
- `backend/database/migrations/*_create_payments_table.php` (new)
- `backend/app/Models/Payment.php` (new)
- `backend/app/Models/Booking.php` (add `payments()`)
- `backend/app/Http/Requests/Admin/StorePaymentRequest.php` (new)
- `backend/app/Http/Controllers/Api/Admin/BookingPaymentController.php` (new)
- `backend/app/Http/Controllers/Api/Admin/PaymentController.php` (new)
- `backend/app/Http/Resources/AdminBookingResource.php` (add `paid_amount`, `balance`)
- `backend/app/Http/Resources/PaymentResource.php` (new)
- `backend/routes/api.php` (new `module:payments` group)
- `backend/database/factories/PaymentFactory.php` (new, for tests)
- `backend/tests/Feature/Admin/AdminBookingPaymentTest.php` (new)
- `backend/tests/Feature/Admin/AdminPaymentsListTest.php` (new)

Frontend:
- `frontend/src/features/admin-bookings/types.ts`, `api.ts`, `hooks.ts` (extend)
- `frontend/src/features/admin-bookings/AdminBookingDetailPage.tsx` (extend)
- `frontend/src/features/admin-payments/types.ts` (new)
- `frontend/src/features/admin-payments/api.ts` (new)
- `frontend/src/features/admin-payments/hooks.ts` (new)
- `frontend/src/features/admin-payments/PaymentsListPage.tsx` (new)
- `frontend/src/components/layouts/AdminLayout.tsx` (nav link)
- `frontend/src/App.tsx` (route)

## Data / contracts

`payments` table (as already documented in the project overview):
- `id`, `booking_id` -> `bookings` (cascade on delete)
- `type` (enum: `full` | `downpayment` | `balance`) - this feature only ever writes `full`
- `amount` (numeric 10,2)
- `method` (enum: `cash` | `gcash` | `card`)
- `reference_no` (nullable string) - optional for every method; not required by any documented rule
- `status` (enum: `pending` | `paid` | `refunded`) - this feature only ever writes `paid`
- `paid_at` (timestamp), `recorded_by` -> `users` (null on delete)

`POST /api/v1/admin/bookings/{id}/payments`
- Auth: `admin`/`super_admin` with the `payments` module permission.
- Body: `{ method: 'cash'|'gcash'|'card', reference_no?: string|null }`.
- 422 `{ status: [...] }` when the booking's status isn't payment-eligible.
- 422 `{ amount: [...] }` when the booking is already fully paid.
- 200: refreshed `AdminBookingResource`, including `paid_amount`/`balance`.

`GET /api/v1/admin/payments?method=&search=&page=`
- Auth: `admin`/`super_admin` with the `payments` module permission.
- 200: paginated `PaymentResource` collection, newest first.

`AdminBookingResource` additions: `paid_amount` (string, e.g. `"0.00"`),
`balance` (string or `null` when `total_amount` isn't set yet).

## Testing

- Backend: `composer test` - new `AdminBookingPaymentTest` and
  `AdminPaymentsListTest` covering the cases listed in build steps 2 and 3
  (happy path for both booking source types, ineligible status, double
  payment, missing permission, filtering, searching, pagination).
- Frontend: `npm run lint` and `npm run test` (no new pure logic beyond
  display, so no new Vitest cases are required unless step 4/5 introduces
  any). No "Browser tests" command is configured in this project, so steps
  4 and 5 verify manually against the running dev servers instead of
  automated browser coverage.

## Notes for the AI

- **Why "full payments" means one payment per booking:** the build-plan
  line reads "records **full** payments," the `payments.type` enum
  separates `full` from `downpayment`/`balance`, and the project overview's
  Open Questions explicitly park the `downpayment_enabled`/`downpayment_percent`
  `settings` feature as out of v1. So in this feature the admin doesn't
  type an arbitrary amount - the server always charges the booking's whole
  `total_amount` in one `Payment` row, and a booking that already has a
  `paid` payment can't be paid again. Don't build a partial-amount input or
  a running-balance-across-many-payments UI; `balance` will only ever be
  `total_amount` or `0`.
- **Why `confirmed` and later, not only `confirmed`:** `total_amount` only
  becomes final at `confirmed` for both source types (weigh-in sets it for
  bring-your-own; it's set at order placement and finalized at
  confirmation for shop orders - see `AdminBookingController::weighIn`/
  `confirmOrder`), and walk-ins land directly on `confirmed`
  (`WalkInController`). Shops commonly collect payment at pickup/delivery,
  which can be `ready`/`out_for_delivery`/`completed`, so gate on that
  whole tail of the status list, not the single `confirmed` value.
- Follow the existing lock-then-transaction pattern from
  `AdminBookingController` (`Booking::lockForUpdate()` inside
  `DB::transaction`) for the record-payment action, even though this
  action doesn't call `BookingStatusEngine::transition` (payments don't
  move booking status).
- Gate the new endpoints with `module:payments`, matching
  `module:services`/`module:inventory`/`module:bookings` already in
  `routes/api.php` - no new middleware needed, `EnsureModulePermission` and
  `User::hasModulePermission` are already generic over the module name.
- Reuse `formatCurrency` (`frontend/src/lib/currency`) for all money
  display, and keep amounts as `decimal`/numeric strings end-to-end -
  never `float` (per `coding-standards.md`).
- Reuse `useBookingActionInvalidation` for the new record-payment mutation
  so the booking detail and list caches refresh, the same way every other
  booking action already does.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":13055,"specSha256":"71644ec263c1abfae053f9ccebe533f82072d9343991d3f5741ff5880110bf5d","branch":"refs/heads/feature/payments","head":"ab8b29aa53c6ff82b0d78bd10d071c4e7464d792","baseRef":"refs/heads/master","baseCommit":"90487521985a4aac7fc74ca1dd1489b63b91ab61","sourceTree":"3df69d090914b380be2c7e4ed8f96e2b58024ca9","absentOptional":[]} -->

## Independent review

**Status:** passed
**Target commit:** ab8b29aa53c6ff82b0d78bd10d071c4e7464d792
**Base commit:** 90487521985a4aac7fc74ca1dd1489b63b91ab61
**Base ref:** master
**Spec hash:** 71644ec263c1abfae053f9ccebe533f82072d9343991d3f5741ff5880110bf5d
**Prepared by:** claude
**Builder model:** claude-sonnet-5
**Requested reviewer:** claude
**Requested model:** claude-sonnet-5
**Requested execution:** automatic
**Requested at:** 2026-09-25T17:00:00Z
**Workflow:** regular
**Check required:** no
**Reviewer adapter:** claude
**Reviewer model:** claude-sonnet-5
**Reviewer context:** fresh subagent
**Actual execution:** automatic
**Reviewed at:** 2026-09-26T00:00:00Z
**Scope:** current
**Lenses:** quality, security, performance, tests
**Verdict:** passed
**Check result:** not-required

## Commands

- `cd backend && composer test`: pass (245 tests, 1073 assertions)
- `cd frontend && npm run lint`: pass (oxlint; only pre-existing warnings in files outside this feature's diff - `WalkInBookingPage.tsx`, `button.tsx`, `ServicesPage.tsx`, `NewShopOrderPage.tsx`, `NewBookingPage.tsx` - no new warnings or errors in the payments files)
- `cd frontend && npm run test -- --run`: pass (7 test files, 33 tests; no new Vitest cases required or added, matching the spec's Testing section)
- `cd frontend && npm run build`: pass (`tsc -b && vite build`; typecheck and production build both succeed)

## Evidence

- Reviewed the complete `90487521..ab8b29aa` delta (24 files, +1173/-9) across backend (migration, `Payment` model, `Booking::payments()`, `StorePaymentRequest`, `BookingPaymentController::store`, `PaymentController::index`, `PaymentResource`, `AdminBookingResource` additions, routes, factory, two new feature test files) and frontend (`admin-bookings` types/api/hooks/detail-page additions, new `admin-payments` feature folder, `AdminLayout` nav link, `App.tsx` route).
- Confirmed the `module:payments` route group matches the existing `module:services`/`module:inventory`/`module:bookings` shape (`auth:sanctum`, `role:admin,super_admin`, `module:payments`), and that `User::MODULES` already includes `payments`.
- Confirmed `BookingPaymentController::store` locks the booking row (`Booking::lockForUpdate()`) inside `DB::transaction`, checks the payment-eligible status list and the existing-`paid`-payment guard before creating the `Payment`, which correctly serializes concurrent double-payment attempts through the row lock.
- Confirmed `PaymentController::index`'s search clause builds `ilike` conditions through Eloquent parameter binding (`where(..., 'ilike', $like)` and `whereRaw('... ilike ?', [$like])`), with no raw string interpolation of user input - no injection risk, and the pattern matches `AdminBookingController::index` exactly.
- Confirmed the new `/admin/bookings/{id}/payments` and existing route pattern both constrain `{id}` with `->where('id', '[0-9]{1,18}')`.
- Confirmed `AdminBookingPaymentTest` and `AdminPaymentsListTest` cover the build steps' Done-when cases: both source types paid in full, an ineligible status rejected, a second payment attempt rejected, missing-permission 403, method filter, search by booking code/guest name/guest phone/customer name/phone, pagination, and ordering.
- Traced `paid_amount`/`balance` are only consumed by `AdminBookingDetailPage.tsx`, whose data source (`show`/`store` via `detailEagerLoads()`) eager-loads `payments`, so the `(float)`-cast computation in `AdminBookingResource` (see F-60) does not currently produce an incorrect displayed amount.
- Re-read `blueprint/context/coding-standards.md`, `ai-interaction.md`, and `project-overview.md`; no drift found beyond the findings recorded below.

## Findings

- F-60 (new, P2): `paid_amount`/`balance` computed via `(float)` casts on decimal money values in `AdminBookingResource.php`, against both the coding standards and this feature's own spec note.
- F-61 (new, P3): `PaymentsListPage`/`usePayments()` does not surface query errors, repeating the F-10/F-20/F-24/F-34/F-36/F-41/F-44/F-57 pattern.
- F-44, F-46, F-51, F-52 (existing, re-examined): files this feature's diff touched (`AdminBookingDetailPage.tsx`, `AdminBookingResource.php`, `BookingController.php`); all four remain open/unchanged, resolutions updated with current line numbers and confirmation the underlying issues are untouched by this feature.

## Remaining risk

- F-60 and F-61 are new P2/P3 items, tracked open; neither blocks this receipt (only open/fixed P0/P1 findings block).
- No browser/manual evidence was gathered for the new "Record payment" UI or the payments list page beyond static review, lint, and build (`npm run build` proves the TypeScript compiles and bundles); the spec itself calls for manual verification here since no `Browser tests` command is configured in this project.
- Ledger-wide pre-existing P2/P3 findings (for example F-52) remain open; none are P0/P1, so none block this receipt.
