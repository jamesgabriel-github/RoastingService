# Fix: preferred pickup/delivery date-time on bookings

**Type:** Fix
**Status:** verified
**Branch:** fix/booking-pickup-delivery-datetime

## The problem

`bookings` already stores `preferred_dropoff_at` (when the customer will drop
off raw food for a bring-your-own booking) and `est_ready_at` (the admin's
estimate of when cooking finishes), but nothing captures when the customer
actually wants to pick up or receive delivery of the finished product - not on
the customer booking forms, not on the admin walk-in form, and not anywhere in
the API responses or detail pages.

Separately, the admin bookings list has no way to see what's due on a given
day: each queue tab shows every booking in that status regardless of date.

Confirmed against `blueprint/context/project-overview.md`'s data model and the
booking controllers/requests/resources/frontend pages - both are real gaps.

## The fix

**1. Add `preferred_pickup_at`** - the customer's (or, for a walk-in, the
counter admin's) requested date-time for picking up or receiving delivery of
the finished item(s). Applies to both booking types and both the customer and
walk-in flow, since all four already carry `fulfillment`.

- **Migration**: nullable `preferred_pickup_at` timestamp on `bookings`,
  alongside `preferred_dropoff_at`.
- **Model**: add to `Booking`'s fillable list, cast to `datetime`.
- **Validation**:
  - `StoreBookingRequest` (bring-your-own): required, valid date, after
    `preferred_dropoff_at` (can't collect a finished item before dropping off
    the raw one).
  - `StoreShopOrderRequest`: required, valid date, after now.
  - `StoreWalkInRoastingRequest` / `StoreWalkInShopOrderRequest`: required,
    valid date, `after_or_equal` now (a walk-in can be picked up immediately,
    unlike a scheduled booking).
- **Controllers**: `BookingController::store`, `OrderController::store`
  (customer), and `WalkInController::storeRoasting`/`storeShop` (admin)
  persist `preferred_pickup_at` from the validated request.
- **Resources**: add `preferred_pickup_at` to `BookingResource` and
  `AdminBookingResource`.
- **Frontend types/api**: add `preferred_pickup_at: string | null` to
  `Booking`/`AdminBooking`, and `preferred_pickup_at: string` to
  `NewBookingPayload`/`NewOrderPayload`/`WalkInRoastingPayload`/`WalkInShopPayload`.
- **Frontend forms**: add a "Preferred pickup/delivery" `datetime-local` field
  to `NewBookingPage.tsx`, `NewShopOrderPage.tsx`, and `WalkInBookingPage.tsx`
  (defaulted to now for the walk-in form, since the customer is at the
  counter), submitted the same way `preferred_dropoff_at` already is
  (`new Date(...).toISOString()`).
- **Frontend display**: show the scheduled pickup/delivery date-time on
  `BookingDetailPage.tsx` (customer) and `AdminBookingDetailPage.tsx` (admin),
  next to the existing fulfillment/delivery-address line.

**2. Add a date filter to the admin bookings list** (`AdminBookingsPage.tsx`),
filtering to bookings whose `preferred_pickup_at` falls on the selected date -
"what do we need to hand over on this day." Defaults to today.

- **Backend**: `BookingController::index` accepts an optional `date` param
  (`Y-m-d`, default today) and adds `whereDate('preferred_pickup_at', $date)`
  to the query, combined with the existing status/search filters.
- **Frontend**: `AdminBookingsPage.tsx` gets a native `date` input next to the
  search box, defaulting to today's date, wired through `useAdminBookings` /
  `fetchAdminBookings` to the new `date` param; changing it resets `page` to 1
  like the status tabs and search already do.

Do not touch the drop-off flow, the cooking/ready/out-for-delivery status
engine, or the booking-counts endpoint (counts stay status-only, not
date-scoped) - none of that is broken or in scope.

## Build steps

1. [x] **Backend: schema, validation, persistence, API output, list filter.**
   Migration + `Booking` model + all four `Store*Request` classes + all four
   creating controllers (`BookingController`, `OrderController`,
   `WalkInController`) + both resources + `BookingController::index`'s new
   `date` param. Update `BookingFactory` if it seeds `preferred_dropoff_at`,
   so factory-built bookings stay consistent. Update existing tests that
   assert on booking/order/walk-in creation payloads to send
   `preferred_pickup_at`, add one validation test per customer-facing request
   (missing field rejected; bring-your-own pickup-before-dropoff rejected),
   and a test for the admin list's date filter (a booking outside the
   selected date is excluded).
   Done when: `composer test` is green and a booking/order/walk-in created via
   the API returns `preferred_pickup_at`, and `GET /admin/bookings?date=...`
   only returns bookings scheduled that day.

2. [x] **Frontend: customer + walk-in forms, and detail-page display.**
   `types.ts`/`api.ts` in `features/bookings`, `features/orders`, and
   `features/admin-bookings` + `NewBookingPage.tsx`/`NewShopOrderPage.tsx`/
   `WalkInBookingPage.tsx` forms + `BookingDetailPage.tsx`/
   `AdminBookingDetailPage.tsx` display.
   Done when: submitting any of the three forms without a pickup/delivery
   date-time shows a validation error, submitting with one succeeds, and the
   value is visible on both the customer and admin booking detail pages.

3. [x] **Frontend: admin bookings list date filter.**
   `AdminBookingsPage.tsx` + `hooks.ts`/`api.ts` date param plumbing.
   Done when: the list defaults to today and only shows bookings with a
   matching `preferred_pickup_at`; picking another date updates the list and
   resets to page 1.

## Verify

- `composer test` and `npm run test` both pass.
- In the browser: start a bring-your-own booking, a shop order, and a walk-in
  booking as their respective users - all three require (or, for walk-in,
  pre-fill) a pickup/delivery date-time before submitting. Open the resulting
  booking on the customer detail page and the admin booking detail page: the
  chosen date-time shows on both.
- On the admin bookings list, confirm it opens showing today's date and only
  today's scheduled bookings, then pick a different date and confirm the list
  updates to match.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":6170,"specSha256":"8a62a54acac9d35edca4811414f53b7bd31941e9be089f0186aa9fa4c9199073","branch":"refs/heads/fix/booking-pickup-delivery-datetime","head":"af39b458f4d3197411238a6895bf37bd4a5099a7","baseRef":"refs/heads/master","baseCommit":"869fb882302a17855aa12aca580bd89a8f98e5b5","sourceTree":"25e1e1d0f3db7fdcc103b380598e64f3d835457c","absentOptional":[]} -->

## Independent review

**Status:** passed
**Target commit:** af39b458f4d3197411238a6895bf37bd4a5099a7
**Base commit:** 869fb882302a17855aa12aca580bd89a8f98e5b5
**Base ref:** refs/heads/master
**Spec hash:** 8a62a54acac9d35edca4811414f53b7bd31941e9be089f0186aa9fa4c9199073
**Prepared by:** claude
**Builder model:** claude-sonnet-5
**Requested reviewer:** claude
**Requested model:** claude-sonnet-5
**Requested execution:** automatic
**Requested at:** 2026-09-26T03:39:07Z
**Workflow:** regular
**Check required:** no
**Reviewer adapter:** claude
**Reviewer model:** claude-sonnet-5
**Reviewer context:** fresh subagent
**Actual execution:** automatic
**Reviewed at:** 2026-09-26T03:44:45Z
**Scope:** current
**Lenses:** quality, security, performance, tests
**Verdict:** passed
**Check result:** not-required

### Commands

- `cd backend && composer test`: pass (254 tests, 1104 assertions)
- `cd frontend && npm run test -- --run`: pass (7 files, 33 tests)
- `cd frontend && npm run build`: pass (`tsc -b && vite build` succeeded)
- `cd frontend && npm run lint`: pass (oxlint, exit 0; only pre-existing warnings in unrelated/unchanged code paths, no errors)
- `cd backend && vendor/bin/pint --test`: pass

### Evidence

- Read the complete `869fb88..af39b458` delta: migration `2026_09_26_010000_add_preferred_pickup_at_to_bookings_table.php`, `Booking` model fillable/casts, `BookingFactory`, all four `Store*Request` validation classes, `Customer\BookingController::store`, `Customer\OrderController::store`, `Admin\WalkInController::storeRoasting`/`storeShop`, `Admin\BookingController::index`'s new `date` filter, `BookingResource`/`AdminBookingResource`, and the frontend types/api/hooks/forms/detail pages across `features/bookings`, `features/orders`, and `features/admin-bookings`.
- Confirmed the only caller of `fetchAdminBookings`/`useAdminBookings` is `AdminBookingsPage.tsx`, and its updated 4-arg signature is used consistently there.
- Confirmed `bookings` has no index on `preferred_dropoff_at`, `status`, or the new `preferred_pickup_at`; the new `whereDate('preferred_pickup_at', ...)` filter matches the table's existing unindexed-date-column pattern rather than introducing a new one, so no new performance finding was raised for it.
- Confirmed each `Store*Request`'s `preferred_pickup_at` rule matches the spec per booking type: `after:preferred_dropoff_at` (bring-your-own), `after:now` (shop order), `after_or_equal:now` (both walk-in requests).
- Confirmed `test_status_filter_returns_only_that_queue` and `test_waiting_minutes_is_a_non_negative_whole_number` (pre-existing, unchanged assertions) still pass only because `BookingFactory` now defaults `preferred_pickup_at` to `now()`, incidentally exercising the list's default-to-today path.

### Findings

- F-64 [P3] open (new) - `preferred_pickup_at` repeats F-51's unnormalized-timestamp assignment in four new create paths (`Customer\BookingController::store`, `Customer\OrderController::store`, `Admin\WalkInController::storeRoasting`/`storeShop`)
- F-65 [P3] open (new) - No test covers an invalid `date` query param on the admin bookings list, or asserts the default-to-today behavior directly
- F-51 [P3] open - reviewed for direct relevance (same raw-timestamp pattern); left unchanged since this diff does not touch `Admin\BookingController::approve` (F-51's cited file/line); the new instances of the pattern are recorded separately as F-64

### Remaining risk

- No browser/manual verification of the three forms (bring-your-own, shop order, walk-in) or the admin date filter was performed; Check was not required for this request, so this was not exercised.
- F-64: a non-SPA API caller sending a non-UTC offset for `preferred_pickup_at` would have it stored shifted by that offset, same as the pre-existing F-51 risk; the shipped SPA is unaffected.
- F-65: default-to-today and invalid-date-format behavior on `GET /admin/bookings` are not directly asserted by any test.
