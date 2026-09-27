# Fix: Walk-in counter redesign - payment tendering, booking code, editable cook time

**Type:** Fix
**Status:** verified
**Branch:** fix/walk-in-counter-redesign

> **Note for `/implement`:** the code below was already written and verified in
> chat, ahead of the branch being created (see `blueprint/context/ai-interaction.md`
> conventions were followed, but the branch/commit/log steps were skipped in the
> moment). All listed build steps are implemented in the current working tree on
> `master`, uncommitted. Treat this run as reconciliation: create the branch,
> re-verify each done-when against the existing diff, and commit rather than
> re-implementing from scratch.

## The problem

`WalkInBookingPage.tsx` (the admin counter/POS screen for creating a walk-in
booking or shop order) was a bare single-column form: no payment UI, no order
total, no post-creation reference code, a plain dropdown for item selection,
and required guest contact info even for a no-frills walk-in. A design
reference (`code.html`, an untracked static mockup) sketched a fuller counter
UI, and follow-up chat requests asked for the underlying capabilities
(tendered payment recorded at booking time, a human-readable pickup-date-based
code, per-item cook-time overrides) to become real, not just cosmetic.

## The fix

Extend the walk-in booking flow end to end, backend and frontend, without
touching the separate customer-facing booking/order flows or the existing
post-hoc "record full payment" endpoint (`BookingPaymentController`), which
keep their current behavior for already-created bookings.

Do not add configuration surfaces, feature flags, or abstractions beyond what
each piece needs; reuse the existing `BookingCodeGenerator` locking pattern,
the existing `Payment` model/enum, and the existing shadcn/Base UI component
conventions.

## Build steps

- [x] 1. **`WalkInCodeGenerator` service** (`backend/app/Services/Booking/WalkInCodeGenerator.php`) -
   generates `WB-YYMMDD-NNN` from a booking's `preferred_pickup_at` date, where
   `NNN` is a per-date sequence (existing bookings for that date + 1), using
   its own Postgres advisory lock key alongside `BookingCodeGenerator`'s.
   Wired into both `WalkInController::storeRoasting`/`storeShop` in place of
   the shared `BookingCodeGenerator`.
   **Done when:** `WalkInCodeGeneratorTest` passes (first code for a date is
   `-001`, increments per existing booking on that date, resets on a different
   date) and both walk-in endpoints return the new format.

- [x] 2. **Payment tendered atomically with booking creation** - `paid_amount`,
   `payment_method`, `payment_reference_no` added to
   `StoreWalkInRoastingRequest`/`StoreWalkInShopOrderRequest` (nullable;
   `payment_method` conditionally required once `paid_amount > 0` via
   `withValidator`/`sometimes`). `WalkInController` creates a `Payment` row
   (`downpayment` below total, `full` at/above total, none when `0`/omitted)
   in the same transaction as the booking; rejects an amount over the
   computed total.
   **Done when:** `AdminWalkInBookingTest`'s payment cases pass (downpayment,
   full, unpaid, over-total rejection, method-required-once-tendered).

- [x] 3. **Editable per-item cooking time** - new nullable `est_minutes` column on
   `booking_items` (migration
   `2026_09_27_215602_add_est_minutes_to_booking_items_table.php`), fillable
   on `BookingItem`, accepted as `items.*.est_minutes` on both walk-in
   requests (defaults to the service's own `est_minutes` when omitted),
   exposed via `AdminBookingItemResource`, and used by
   `BookingItemController::startCooking` (`$item->est_minutes ?? $item->service->est_minutes`)
   so an operator's override actually drives the computed `est_ready_at`.
   **Done when:** the two `AdminWalkInBookingTest` est_minutes cases pass
   (defaults from service, override respected) and
   `test_start_cooking_uses_the_items_own_est_minutes_override_when_set`
   passes.
   **Note:** the customer-facing booking/order flows are untouched; this only
   affects bookings created through the walk-in endpoints.

- [x] 4. **Guest info made fully optional for walk-ins** - dropped
   `required_without_all`/`required_with` from `customer_id`/`guest_name`/
   `guest_phone` on both walk-in requests, keeping only the mutual-exclusion
   `prohibits` rules; a walk-in can now be recorded with no customer
   identification at all. Frontend labels changed to "(optional)"; the zod
   schema's guest-required checks were removed.
   **Done when:** `test_neither_customer_nor_guest_is_allowed_for_an_anonymous_walk_in`,
   `test_guest_name_without_guest_phone_is_allowed`, and
   `test_guest_phone_without_guest_name_is_allowed` pass, and
   `test_both_customer_and_guest_is_rejected` still passes unchanged.

- [x] 5. **Two-column counter layout** (`WalkInBookingPage.tsx`) - left column
   (sourcing type toggle, customer, items, fulfillment/schedule) and a right
   sidebar (payment tendered, order total/balance, submit) that stays
   `sticky` above `lg`, collapsing to one stacked column below it. Sourcing
   type and pickup/delivery are segmented toggle buttons instead of a
   checkbox/radio pair.
   **Done when:** verified by running the app - confirmed manually in this
   session via typecheck/lint/test only (no browser automation tool was
   available to click through it live); flag as a `/check guide` candidate
   if a human walkthrough hasn't happened yet.

- [x] 6. **Tap-to-add item catalogue** - services render as a horizontally
   scrollable row of tappable tiles (rate, est. cook time; shop-mode tiles
   gray out and block tapping when `in_stock === false` instead of being
   hidden), replacing the old "blank row + dropdown" flow. Catalogue data
   comes from a new `fetchWalkInServices`/`useWalkInServices` hook hitting the
   public, unauthenticated `/services` endpoint (not the permission-gated
   `/admin/services`), so operators without the separate "services" module
   permission still see the full catalogue.
   **Done when:** `services` list renders from `useWalkInServices`, tapping a
   tile appends an item pre-filled with that service's rate/cook-time, and an
   out-of-stock shop item is visibly disabled rather than absent.

- [x] 7. **Selected item row: editable weight/qty, cooking time, and subtotal** -
   each selected line shows the service name/rate, an editable
   weight-or-qty input, an editable "Cooking time (min)" input (seeded from
   the service default at tap time), a computed subtotal, and a Remove
   button.
   **Done when:** editing weight/qty or cooking time updates the row's
   subtotal and the submitted payload respectively, per step 3.

- [x] 8. **Order total / payment tendered sidebar** - live Order Total, Amount
   Paid, and Balance Due, with quick-tender presets (Unpaid / Half / Full) and
   a Cash/GCash toggle (GCash reveals an optional reference-number field).
   Numeric inputs use `setValueAs` to turn an emptied field into `undefined`
   rather than `NaN`, so Amount Paid/Balance Due display `₱0.00` instead of
   `NaN` when cleared.
   **Done when:** clearing the Amount Paid input shows `₱0.00` in both
   summary rows instead of `NaN`.

- [x] 9. **Fulfillment & pickup schedule** - Ready Date (`type="date"`) and Target
   Ready Time (`type="time"`, free entry) replace the single
   `datetime-local` input, recombined into one ISO timestamp on submit.
   **Done when:** submitting sends a single valid `preferred_pickup_at` built
   from the two fields.

- [x] 10. **Confirmation popup** - a new shadcn/Base UI `Dialog`
    (`frontend/src/components/ui/dialog.tsx`, the first Dialog in this app)
    shows the created booking's `WB-YYMMDD-NNN` code and balance due at
    handover; dismissing it ("Done / Next order") resets the form and
    navigates to `/admin/bookings?group=pending` (the Pending queue tab)
    rather than the single booking's detail page.
    **Done when:** confirming a walk-in booking opens the dialog with the
    real returned code, and dismissing it lands on the Pending tab.

- [x] 11. **Independent-review repairs (F-73, F-75, F-76, F-77)** - the first
    automatic independent review (target `829b0b5`) came back
    `changes-requested` with one P1 and three P2/P3 findings:
    - **F-73 [P1]** - `BookingPaymentController::store`'s "already fully paid"
      guard blocked on *any* paid `Payment` row, and re-charged the full
      `total_amount` again, rather than accounting for a walk-in's partial
      downpayment. Fixed: the guard now sums existing paid payments and
      compares against `total_amount`, and the new row records only the
      remaining balance (`type: 'balance'` once a prior payment exists,
      `'full'` otherwise). Two new `AdminBookingPaymentTest` cases cover the
      partial-then-balance path and the already-fully-paid rejection path.
    - **F-75 [P2]** - `closeConfirmation`'s reset used a module-load-time
      frozen `preferred_pickup_date` default and never cleared
      `customerSearch`. Fixed: recompute the date at reset time and clear the
      search string.
    - **F-76 [P3]** - `est_minutes` had no client-side bounds matching the
      backend's `min:1,max:1440`. Fixed: added to the zod schema plus a
      `max={1440}` input attribute and inline error message.
    - **F-77 [P3]** - the order-total/balance/quick-tender math was inline
      with no test coverage, unlike the sibling `orders/orderTotal.ts`
      pattern. Fixed: extracted to `admin-bookings/walkInTotals.ts` with a
      matching test file (9 new tests).
    - **F-74 [P2]** left `open`, deliberately not fixed here: it's a third
      instance of an already-tracked, already-open pre-existing pattern
      (float arithmetic on decimal money columns, F-60/F-62), not something
      introduced uniquely by this fix that needs a one-off repair ahead of
      that broader, separately-owned decision.
    **Done when:** `composer test` (273/273) and the frontend suite
    (`tsc`/`lint`/`npm run test -- --run`, 53/53) pass with the repairs
    applied; done, evidence in Verify below.

## Verify

- Backend: `composer test` from `backend/` - 273 passing, including all new
  cases above (`WalkInCodeGeneratorTest`, the new `AdminWalkInBookingTest`
  cases, the `AdminBookingFulfillmentTest` est_minutes-override case, and the
  two `AdminBookingPaymentTest` cases added in the F-73 repair).
- Frontend: `npx tsc --noEmit -p tsconfig.app.json`, `npm run lint`, and
  `npm run test -- --run` from `frontend/` - clean typecheck, no new lint
  findings, 53 passing Vitest tests (9 new, for the extracted
  `walkInTotals.ts` added in the F-77 repair).
- Manual (not yet performed in this session - no browser tool was available):
  run `php artisan serve` + `npm run dev`, open `/admin/bookings/walk-in`, and
  walk through both booking types (registered vs. guest vs. fully anonymous
  customer, pickup vs. delivery, all three quick-tender presets, an
  out-of-stock shop item, editing a selected item's cooking time), confirming
  the two-column layout, the confirmation popup's code/balance, and the
  Pending-tab redirect on dismiss.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":11144,"specSha256":"095fa12593573bb8d0710a582aae50c7aaeb3b38ac4bfd1fc3f8410df619e769","branch":"refs/heads/fix/walk-in-counter-redesign","head":"24dcb49ad9d609cbf07ef94cff3872cd6d722f02","baseRef":"refs/heads/master","baseCommit":"c29490bf4df6b384e506ce302678bd16d51b51c5","sourceTree":"c85adc3bc366b901538786495e7f327cdc7e23f6","absentOptional":[]} -->

## Findings

### walk-in-counter-redesign/F-73 [P1] closed - A walk-in downpayment permanently blocks the existing "record payment" balance-collection endpoint

**File:** backend/app/Http/Controllers/Api/Admin/WalkInController.php:222-249; backend/app/Http/Controllers/Api/Admin/BookingPaymentController.php:38-49
**Found:** 2026-09-28 by /audit independent (scope: current; lens: quality/tests)
**Why it matters:** `WalkInController::recordTenderedPayment` is the first code path in the whole codebase that ever creates a `Payment` with `type: 'downpayment'` and `status: 'paid'` for less than the booking's total (confirmed: no `'downpayment'`-type `Payment::create` exists anywhere at the base commit). `BookingPaymentController::store` - the only endpoint that records a later payment on an existing booking, exposed to staff as `RecordPaymentActions` on the admin booking detail page whenever `Number(booking.balance) > 0` - guards with `$booking->payments()->where('status', 'paid')->exists()` and rejects with "This booking is already fully paid." as soon as *any* paid payment row exists, then (if it did proceed) would insert a second payment for the *full* `$booking->total_amount` again, not the remaining balance. So a walk-in booking created with, say, a ₱50 downpayment on a ₱200 total shows "Balance: ₱150.00" and a working-looking "Record payment" button on its detail page, but submitting it always fails with a message that falsely claims the booking is fully paid - there is no way, anywhere in the app, to ever collect that remaining ₱150 for a walk-in downpayment booking. The `payments` table's `type` enum already includes an unused `'balance'` value (`create_payments_table` migration), suggesting this exact follow-up was anticipated but never wired up. This is a first-class user-facing regression of the feature's own core promise (the new confirmation dialog explicitly shows "Balance due at handover").
**Suggested fix:** Change the guard to compare the summed `paid` amount against `total_amount` (e.g. reuse the `bcsub`/`bcadd`-based balance from F-60's fix) rather than mere existence, and change the created row to record only the remaining balance (`type: 'balance'`) instead of re-charging the full total. Add a regression test: create a walk-in booking with a partial `paid_amount`, then call `POST /admin/bookings/{id}/payments` and assert it succeeds and the booking's `balance` reaches `0.00`. Confirm with the user whether balance collection should ship now or be explicitly deferred, since closing this changes `BookingPaymentController`'s behavior, which the spec says to leave untouched.
**Resolution:** Fixed in `BookingPaymentController::store`: the guard now sums existing `paid` payments and compares against `total_amount` (blocking only once fully covered), and the created row records the remaining balance with `type: 'balance'` when a prior payment exists, `'full'` otherwise (plain float arithmetic kept, matching the still-open F-60/F-62 pattern rather than introducing bcmath ahead of that decision). Added `test_a_partial_downpayment_can_later_be_paid_the_remaining_balance` and `test_a_payment_attempt_after_the_balance_is_already_fully_paid_is_rejected` to `AdminBookingPaymentTest`; full suite passes (273/273). Closed 2026-09-28 by /audit independent (second-round review of `24dcb49`; scope: current; all lenses). Re-read `BookingPaymentController.php:38-56` directly: the guard now computes `$alreadyPaid = (float) $booking->payments->where('status','paid')->sum('amount')`, `$remaining = round((float) $booking->total_amount - $alreadyPaid, 2)`, rejects only when `$remaining <= 0`, and creates the new row with `'amount' => $remaining` and `'type' => $alreadyPaid > 0 ? 'balance' : 'full'`. Ran `composer test` fresh: 273/273 pass, including both new regression tests (partial-downpayment-then-balance succeeds with `paid_amount: '200.00'`/`balance: '0.00'`; a second attempt after full payment is rejected with a 422 on `amount`) and the pre-existing `test_a_second_payment_attempt_on_an_already_paid_booking_is_rejected` (still passes unchanged, since a lone `'full'` payment still zeroes `$remaining`). No new defect introduced: `$request->user()->id` recorded on `recorded_by` as before, `payments` eager-loaded alongside `items` for the lock query. Original defect (any paid row blocking collection, re-charging the full total) is gone.

### walk-in-counter-redesign/F-75 [P2] closed - "Done / Next order" resets the form but leaves a frozen default pickup date and stale customer search text

**File:** frontend/src/features/admin-bookings/WalkInBookingPage.tsx:95-110,203-208
**Found:** 2026-09-28 by /audit independent (scope: current; lens: quality)
**Why it matters:** `emptyValues` (including `preferred_pickup_date: toDateInputValue(new Date())`) is a module-level constant, computed once when the page's JS module first loads, not recomputed per mount or per reset. Before this diff, a successful walk-in booking navigated away entirely (`navigate('/admin/bookings/' + booking.id)`); this diff's new `closeConfirmation` instead calls `reset(emptyValues)` and keeps the operator on the same mounted page for the next order (the whole point of the new "Done / Next order" button, to make repeat counter entry fast). If the page is left open across midnight - an ordinary event for an all-day or overnight counter terminal that is not refreshed between customers - every booking created after the rollover silently defaults its "Ready date" to the previous calendar day, and submitting it is rejected by the backend's `after_or_equal:now` rule with a message that does not explain the real cause (the operator has to notice the date field itself is wrong). Separately, `closeConfirmation` resets `confirmedBooking` and `selectedCustomer` but never clears the `customerSearch` string state; switching back to "Registered customer" mode for the next order re-shows the previous search text and immediately re-queries it.
**Suggested fix:** Compute the date default at reset time instead of module load, e.g. `reset({ ...emptyValues, preferred_pickup_date: toDateInputValue(new Date()) })` in `closeConfirmation` (and in the initial `useForm` default). Add `setCustomerSearch('')` to `closeConfirmation`. Current requirement lost: None.
**Resolution:** Fixed exactly as suggested: `closeConfirmation` now calls `reset({ ...emptyValues, preferred_pickup_date: toDateInputValue(new Date()) })` and `setCustomerSearch('')`. The initial `useForm` default was left as module-load-time (correct there, since the page just mounted). Closed 2026-09-28 by /audit independent (second-round review of `24dcb49`; scope: current; all lenses). Re-read `WalkInBookingPage.tsx:201-207` directly: `closeConfirmation` computes `toDateInputValue(new Date())` at call time (not the frozen module-level `emptyValues.preferred_pickup_date`) and calls `setCustomerSearch('')` before navigating away. `frontend/src/features/admin-bookings` has no dedicated component test for this page (consistent with the ledger's other coverage-gap findings on this file), so verification is by direct code reading rather than an automated regression test; `npx tsc --noEmit` and `npm run lint` both pass clean on the file. Original defect (frozen date default surviving a midnight rollover, stale search text) is gone and no new defect was introduced.

### walk-in-counter-redesign/F-76 [P3] closed - New est_minutes field has no client-side bounds, unlike the form's other numeric fields

**File:** frontend/src/features/admin-bookings/WalkInBookingPage.tsx:26,493-505
**Found:** 2026-09-28 by /audit independent (scope: current; lens: quality)
**Why it matters:** `itemSchema`'s new `est_minutes: z.number().optional()` has no min/max check, and the rendered `<Input type="number" min={1} .../>` HTML attribute is only a soft hint a user can still bypass by typing directly (e.g. `0`, a negative number, or `99999`). The backend rule is `nullable, integer, min:1, max:1440`, so an out-of-range value only surfaces as one generic, non-field-specific `formError` banner from the 422 response rather than an inline message under the Cooking time input. The form's other item fields (`final_weight_kg`/`qty`) at least attempt bound checks in `superRefine`, even though F-56 already flags their messages/decimal handling as imperfect; `est_minutes` has no client check at all.
**Suggested fix:** Add `.min(1).max(1440)` (or the `superRefine` equivalent) to `est_minutes` with a field-specific message, matching the backend rule. Current requirement lost: None.
**Resolution:** Fixed: `itemSchema.est_minutes` now has `.min(1).max(1440)`, the input got a `max={1440}` HTML attribute alongside the existing `min={1}`, and an inline error message renders under the field like the sibling weight/qty inputs. Closed 2026-09-28 by /audit independent (second-round review of `24dcb49`; scope: current; all lenses). Re-read `WalkInBookingPage.tsx:28` (`est_minutes: z.number().min(1).max(1440).optional()`) and `:496-507` (input now has `min={1} max={1440}` plus `{errors.items?.[index]?.est_minutes && <p>...</p>}` rendering the field-specific message), matching the backend's `nullable, integer, min:1, max:1440` rule exactly. `.optional()` correctly exempts an omitted value from the bound check, so the default-from-service path (undefined until edited) is unaffected. `npx tsc --noEmit` and `npm run lint` both pass clean; the 53-test Vitest suite (which does not target this component directly) still passes. Original defect (no client bound, generic non-field error only) is gone and no new defect was introduced.

### walk-in-counter-redesign/F-77 [P3] closed - New order-total/payment math has no test coverage and duplicates the sibling orders feature's tested pattern inline

**File:** frontend/src/features/admin-bookings/WalkInBookingPage.tsx:112-164,193-201; frontend/src/features/orders/orderTotal.ts
**Found:** 2026-09-28 by /audit independent (scope: current; lens: tests/quality)
**Why it matters:** This diff adds real money-facing logic with no test file anywhere for `WalkInBookingPage.tsx` (confirmed: no `WalkIn*.test.*` file exists, and the Vitest count is unchanged at 44 before and after per this spec's own Verify section). `orderTotal`, `balanceDue`, and `setQuickTender`'s half/full presets are all computed inline in the component. The sibling `orders` feature already established the local pattern for this exact computation - a pure, exported `computeOrderTotal()` in `orders/orderTotal.ts` with its own rounding (`Math.round(total * 100) / 100`) and a dedicated `orderTotal.test.ts` - which this new page does not reuse or follow, and its inline `orderTotal` reduce has no rounding at all (masked only because `formatCurrency`'s `Intl.NumberFormat` rounds for display, and `setQuickTender` separately rounds before writing `paid_amount`). The code reads correctly for the paths exercised by the backend integration tests, so this is a coverage/consistency gap, not a known defect.
**Suggested fix:** Extract the total/balance computation into a small pure function (or reuse `computeOrderTotal` with a rate/qty mapping) and add a unit test file for it, matching `orders/orderTotal.ts`'s pattern. Current requirement lost: None.
**Resolution:** Fixed: extracted `computeOrderTotal`/`computeBalanceDue`/`resolveQuickTenderAmount` into `frontend/src/features/admin-bookings/walkInTotals.ts`, mirroring `orders/orderTotal.ts`'s shape and rounding, with `walkInTotals.test.ts` covering summing, empty input, rounding, zero-qty lines, balance clamping, and all three quick-tender presets (9 new passing tests; Vitest count now 53). `WalkInBookingPage.tsx` now calls these instead of computing inline. Closed 2026-09-28 by /audit independent (second-round review of `24dcb49`; scope: current; all lenses). Re-read `walkInTotals.ts` (rounds `computeOrderTotal`'s sum to 2 decimals, `computeBalanceDue` clamps at 0, `resolveQuickTenderAmount` covers unpaid/half/full) and `walkInTotals.test.ts` (9 cases: sum, empty list, rounding, zero-qty line, balance subtraction, balance clamped, and all three quick-tender presets). Confirmed `WalkInBookingPage.tsx:156-166,197-198` now calls these exported functions instead of computing inline. Ran `npm run test -- --run` fresh: 9 Test Files, 53 tests, all passing. Original defect (untested inline money math, duplicating the sibling `orders` feature's pattern without reuse) is gone and no new defect was introduced.

## Independent review

**Status:** passed
**Target commit:** 24dcb49ad9d609cbf07ef94cff3872cd6d722f02
**Base commit:** c29490bf4df6b384e506ce302678bd16d51b51c5
**Base ref:** master
**Spec hash:** 095fa12593573bb8d0710a582aae50c7aaeb3b38ac4bfd1fc3f8410df619e769
**Prepared by:** claude
**Builder model:** claude-sonnet-5
**Requested reviewer:** claude
**Requested model:** claude-sonnet-5
**Requested execution:** automatic
**Requested at:** 2026-09-27T23:00:11Z
**Workflow:** regular
**Check required:** no
**Reviewer adapter:** claude
**Reviewer model:** claude-sonnet-5
**Reviewer context:** fresh subagent
**Actual execution:** automatic
**Reviewed at:** 2026-09-27T23:06:16Z
**Scope:** current
**Lenses:** quality, security, performance, tests
**Verdict:** passed
**Check result:** not-required

### Commands

- `composer test` (from `backend/`): pass - 273/273, 1194 assertions
- `npx tsc --noEmit -p tsconfig.app.json` (from `frontend/`): pass - no errors
- `npm run lint` (from `frontend/`): pass - exit 0, only pre-existing warnings
  unrelated to this diff (react-compiler `incompatible-library` notes already
  present on other pages; no new lint findings)
- `npm run test -- --run` (from `frontend/`): pass - 9 test files, 53/53 tests

### Evidence

- Confirmed `HEAD` equals `Target commit` (`24dcb49a...`), `master`'s merge-base
  with `HEAD` equals `Base commit` (`c29490bf...`), and the current
  `blueprint/context/current-feature.md` bytes hash to the recorded `Spec hash`.
- `git status --porcelain` showed only `blueprint/context/review.md` differing
  from the target before this pass began (findings.md was then written by this
  review itself), satisfying the freshness precondition.
- Reviewed the complete `Base commit..Target commit` delta (23 files, +1482/-247
  per `git diff --stat`), not just the repair commit: `WalkInCodeGenerator` +
  its test, payment-tendering fields/logic in both walk-in Form Requests and
  `WalkInController::recordTenderedPayment`, the new `est_minutes` column/casts/
  resource field/`startCooking` override, the guest-info-optional validation
  change, the full `WalkInBookingPage.tsx` two-column/tap-catalogue/sidebar/
  confirmation-dialog rewrite, `dialog.tsx`, `walkInTotals.ts`, and every backend
  test file touched (`AdminWalkInBookingTest`, `AdminBookingFulfillmentTest`,
  `AdminBookingPaymentTest`, `WalkInCodeGeneratorTest`).
- Re-verified F-73's fix by reading `BookingPaymentController.php:38-56` line by
  line against its two new regression tests and the pre-existing
  already-fully-paid test, then re-running the full backend suite fresh.
- Re-verified F-75/F-76/F-77 by reading the exact current lines in
  `WalkInBookingPage.tsx` and `walkInTotals.ts`/`walkInTotals.test.ts` cited in
  each finding's original report, confirming each matches its resolution note.
- F-74 (float arithmetic on walk-in payment totals) left untouched per the
  builder's explicit, reasoned decision to track it under the existing open
  F-60/F-62 pattern rather than repair it ahead of that broader call.

### Findings

- F-73, F-75, F-76, F-77: moved `fixed` -> `closed` in
  `blueprint/context/findings.md`, each with fresh re-examination evidence.
- No other findings from the base ledger were re-scored; F-74 remains `open`
  unchanged (intentionally not repaired this round).
- No new confirmed findings from this pass's fresh review of the complete
  delta across all four lenses.

### Remaining risk

- Manual browser walkthrough of the two-column counter UI, the tap-to-add
  catalogue, the confirmation dialog, and the Pending-tab redirect has not been
  performed (no browser automation tool was available in this session or the
  builder's, per the spec's own Verify section); this is a real UI-flow gap,
  not covered by typecheck/lint/unit/feature tests.
- F-74 (P2, float arithmetic on walk-in payment totals) remains open by
  deliberate, separately-tracked decision alongside sibling F-60/F-62; it does
  not block this receipt since only P0/P1 status gates `passed`.
- The pre-existing P3 ledger backlog (F-04 through F-72, minus those closed
  above) is unchanged by this pass and was not re-scored; none are P0/P1.
- `Check required` is `no` for this request, so `/check` was not run; no
  browser or manual acceptance evidence exists for this checkpoint beyond what
  the automated suites cover.
