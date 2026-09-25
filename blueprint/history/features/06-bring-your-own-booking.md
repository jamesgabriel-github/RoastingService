# Feature: Bring-your-own booking

**From build-plan:** feature 6
**Build attempt:** 1
**Status:** verified
**Branch:** `feature/bring-your-own-booking`

## Goal

A logged-in customer books roasting for their own raw food: picks one or more
services that allow customer-supplied roasting, enters an estimated raw weight
per item, chooses a preferred drop-off date/time, chooses pickup or delivery
(with an address for delivery), adds an optional note, sees a running
server-trustworthy **estimated** total, and submits. The backend creates the
booking with a rate snapshot per item, assigns a unique code, sets its initial
status, and logs that status change. This introduces the shared booking status
engine, rate-snapshot pattern, and status-log table that later booking features
(shop order, my bookings, admin queues, approve/weigh-in, ...) build on.

## In scope

- `bookings`, `booking_items`, `booking_status_logs` tables.
- Finishing the `inventory_logs.booking_id` foreign key: the Feature 5
  migration left it an unconstrained `unsignedBigInteger` with a comment that
  `bookings` didn't exist yet. Add the constraint now in a new migration.
- A small booking status engine (`App\Services\Booking\BookingStatusEngine`)
  that defines the full known status set and allowed transitions for both
  booking types (from `project-plan.md`'s status flows) and writes
  `booking_status_logs` rows on every status change. This feature only drives
  it for the initial `customer_supplied` creation transition; later features
  reuse it for approve/reject/confirm/etc.
- `POST /api/v1/bookings`, customer-only, creating a `customer_supplied`
  booking with one or more items, computing the estimated total server-side.
- Customer-facing booking form at `/book` (items -> pickup/delivery -> review,
  as one page with clear sections - see Notes for the AI) and a nav link to
  reach it from `CustomerLayout`.
- Focused backend and frontend tests for the new logic.

## Out of scope

- Shop orders (feature 7), My bookings list/detail (feature 8), admin queues
  (feature 9), approve/weigh-in (feature 10), and every other status
  transition beyond the initial one. The status engine's transition map
  includes them for later reuse, but no route or UI exercises them yet.
- Stock/inventory effects: customer-supplied bookings don't touch
  `services.stock_qty` (see the booking-types table in `project-plan.md`).
- Any change to the public `GET /api/v1/services` endpoint; it already
  returns `allow_customer_supplied` and `roasting_rate_per_kg`, which is
  everything this form needs.
- A guided multi-screen wizard or a "choose booking type" step - see Notes.

## Build loop

Per `blueprint/config.json`: `workflow.stepReview` is `feature` and
`workflow.checkpointCommits` is `disabled` for the normal workflow. This build
is running under Continuous Mode, which replaces per-step pauses with
self-review and keeps work uncommitted until one local feature commit at the
end (`../../continuous/SKILL.md`).

## Build steps

- [x] 1. Add the three new migrations (`bookings`, `booking_items`,
  `booking_status_logs`) and the `inventory_logs.booking_id` foreign-key
  migration. **Done when:** `composer test` passes (RefreshDatabase applies
  all migrations cleanly) with no other change.
- [x] 2. Add `Booking`, `BookingItem`, `BookingStatusLog` Eloquent models
  (fillable, casts, relationships) and their factories. **Done when:**
  `composer test` passes.
- [x] 3. Add `BookingStatusEngine` with the full transition map and a unit
  test covering the allowed initial transition and a rejected invalid one.
  **Done when:** `composer test` passes including the new unit test.
- [x] 4. Add `StoreBookingRequest`, `BookingResource`, and
  `Api/Customer/BookingController@store`, wired at
  `POST /api/v1/bookings` behind `auth:sanctum` (customer-only, enforced by
  the request's `authorize()`, matching the existing `CompleteProfileRequest`
  pattern). The controller creates the booking, its items (with the rate
  snapshot and computed subtotal), assigns the next `RS-####` code under a
  Postgres advisory lock, and drives the status engine's initial transition,
  all inside one DB transaction. Add a feature test covering: happy path
  (correct code, snapshot, estimated total, one status log row, no stock
  change); a service that doesn't allow customer-supplied or is inactive
  (422); delivery without an address (422); a past `preferred_dropoff_at`
  (422); an admin/super_admin request (403). **Done when:** `composer test`
  passes including the new feature test.
- [x] 5. Add the frontend `bookings` feature module (`types.ts`, `api.ts`,
  `hooks.ts`, a pure `computeEstimatedTotal` helper with a Vitest test) and
  `NewBookingPage` at `/book` (`ProtectedRoute` + `CustomerLayout`), plus a nav
  link from `CustomerLayout` to reach it. **Done when:** `npm run test` and
  `npm run lint` pass, `npm run build` succeeds, and a dev-server run shows: a
  logged-in customer can select a bring-your-own service, enter a weight, see
  the estimate update live, choose delivery and get an inline required-address
  error if left blank, submit, and see the returned booking code with status
  "Pending review".

## Files / areas

Backend:
- `backend/database/migrations/2026_09_25_060000_create_bookings_table.php`
- `backend/database/migrations/2026_09_25_060001_create_booking_items_table.php`
- `backend/database/migrations/2026_09_25_060002_create_booking_status_logs_table.php`
- `backend/database/migrations/2026_09_25_060003_add_booking_foreign_to_inventory_logs_table.php`
- `backend/app/Models/Booking.php`, `BookingItem.php`, `BookingStatusLog.php`
- `backend/database/factories/BookingFactory.php`, `BookingItemFactory.php`
- `backend/app/Services/Booking/BookingStatusEngine.php`
- `backend/app/Http/Requests/Customer/StoreBookingRequest.php`
- `backend/app/Http/Resources/BookingResource.php`, `BookingItemResource.php`
- `backend/app/Http/Controllers/Api/Customer/BookingController.php`
- `backend/routes/api.php` (add the route)
- `backend/tests/Unit/Booking/BookingStatusEngineTest.php`
- `backend/tests/Feature/Customer/BookingManagementTest.php`

Frontend:
- `frontend/src/features/bookings/types.ts`, `api.ts`, `hooks.ts`, `estimate.ts`, `estimate.test.ts`
- `frontend/src/features/bookings/NewBookingPage.tsx`
- `frontend/src/components/layouts/CustomerLayout.tsx` (add nav link)
- `frontend/src/App.tsx` (add the `/book` route)

## Data / contracts

### `bookings`

`id`, `code` (string, unique, `RS-####` zero-padded), `customer_id` (FK
`users`, not null for this feature - walk-ins with a null `customer_id` are
feature 13), `guest_name`/`guest_phone` (nullable, unused here),
`source_type` (`customer_supplied` this feature; enum also has
`shop_supplied` for feature 7), `fulfillment` (`pickup`|`delivery`),
`delivery_address` (nullable string, required when `fulfillment=delivery`),
`shipping_fee` (decimal 10,2, default `0`), `status` (enum - full list below),
`preferred_dropoff_at` (timestamp, required, must be in the future),
`dropoff_at`/`approved_at`/`approved_by`/`confirmed_at`/`confirmed_by`/
`weighed_at`/`cooking_started_at`/`est_ready_at`/`completed_at` (all
nullable, set by later features), `estimated_total` (decimal 10,2, server
computed = sum of item subtotals + `shipping_fee`), `total_amount` (nullable
decimal 10,2, set at weigh-in by feature 10), `notes` (nullable string),
`reject_reason` (nullable string), timestamps.

`status` enum (shared by both source types, from `project-plan.md`'s status
flows): `pending_review`, `pending_confirmation`, `approved`, `confirmed`,
`cooking`, `ready`, `out_for_delivery`, `completed`, `rejected`, `no_show`,
`cancelled`. This feature only ever writes `pending_review`.

### `booking_items`

`id`, `booking_id` (FK, cascade delete), `service_id` (FK, restrict delete),
`qty` (int, default `1` - see Notes), `est_weight_kg` (nullable decimal 8,2),
`final_weight_kg` (nullable decimal 8,2, set at weigh-in), `rate` (decimal
10,2, snapshot of `services.roasting_rate_per_kg` at booking time),
`subtotal` (decimal 10,2 = `est_weight_kg * rate` for this feature).

### `booking_status_logs`

`id`, `booking_id` (FK, cascade delete), `status` (same enum as
`bookings.status`), `changed_by` (nullable FK `users` - null for
system/customer-initiated changes, set for admin actions in later features),
`remarks` (nullable string), `created_at`.

### `POST /api/v1/bookings` (auth:sanctum, role:customer)

Request:

```json
{
  "items": [{ "service_id": 1, "est_weight_kg": "3.50" }],
  "fulfillment": "pickup",
  "delivery_address": null,
  "preferred_dropoff_at": "2026-10-01T09:00:00",
  "notes": null
}
```

Validation: `items` required array, min 1; `items.*.service_id` must exist,
be `is_active=true` and `allow_customer_supplied=true`;
`items.*.est_weight_kg` required numeric, `min:0.01`, `max:1000` (bounds the
decimal(8,2) column and matches the F-19/F-23 lesson of never letting an
unbounded numeric reach a fixed-precision column); `fulfillment` required,
`in:pickup,delivery`; `delivery_address` `required_if:fulfillment,delivery`,
string, `max:500`; `preferred_dropoff_at` required, valid date, `after:now`;
`notes` nullable, string, `max:1000`.

Response `201`: the created booking (code, status, estimated_total, items
with rate/subtotal, fulfillment, delivery_address, preferred_dropoff_at,
notes, created_at) via `BookingResource`.

### `BookingStatusEngine`

`initialStatusFor(string $sourceType): string` returns `pending_review` for
`customer_supplied` and `pending_confirmation` for `shop_supplied`.
`transition(Booking $booking, string $to, ?int $changedBy, ?string $remarks): void`
checks the move against a transition map keyed by `[sourceType][fromStatus]`
(from-status `null` means "creation"), throws on an invalid move, otherwise
sets `$booking->status` and creates a `BookingStatusLog` row, all expected to
run inside the caller's transaction.

## Testing

Backend (PHPUnit, `composer test`): `BookingStatusEngineTest` (unit) for
valid/invalid transitions; `BookingManagementTest` (feature) for the create
endpoint's happy path, validation failures, and role enforcement, per Build
step 4.

Frontend (Vitest, `npm run test`): `estimate.test.ts` for
`computeEstimatedTotal` (sum of qty-1 line subtotals, empty list, and
rounding to 2 decimals).

No `Browser tests` command is declared in `AGENTS.md`, so the `/book` flow is
verified with the dev server plus the API response (booking code and status),
per `coding-standards.md`'s Browser Verification section.

**Verification actually performed:** no interactive browser tool was
available in this session, so the create-booking contract was proven against
the real running `php artisan serve` dev database (not just PHPUnit) via
direct HTTP calls: a fresh customer login, a successful booking creation
(verified code `RS-0001`, snapshot rate, `estimated_total`, one
`booking_status_logs` row with `changed_by = null`, and unchanged
`stock_qty`), a delivery-without-address 422, and an unauthenticated 401.
Test data created during this check was deleted afterward. The interactive
point-and-click walkthrough of `NewBookingPage` itself (clicking through the
form in a real browser) was not performed and remains open for a manual
check.

## Notes for the AI

- **Contact number:** the project-plan prose lists "contact number" for a new
  booking, but the frozen data model (`project-overview.md`) has no
  `contact_number` column on `bookings` - only `delivery_address`. A
  customer's phone (their login identity, already on `users`) is the contact
  number; no new field is added. This keeps the concrete schema authoritative
  over the looser prose per this skill's rules.
- **`qty` is fixed at 1** for every `customer_supplied` item this feature
  creates. Pricing is per kilogram, not per piece, so there's no customer
  input that would make `qty` anything else yet; `qty` exists on the shared
  `booking_items` table because shop orders (feature 7) need it per piece.
- **No type-selection step.** Section 7 of `project-plan.md` is explicitly
  "proposed direction, to refine during design," not a locked contract, and
  shop orders don't exist yet. `/book` goes straight into the
  bring-your-own flow; feature 7 is the natural place to add a type chooser
  once there are two real options.
- **Single-page sectioned form, not a router-driven wizard.** Same reasoning:
  the plan's "step flow" is a proposal, and a sectioned form (items, then
  pickup/delivery, then a review/submit section) delivers the same reviewable
  flow with far less code. Revisit if a real design reference shows up later.
- **Initial status is `pending_review`, not a separate `Booked` status.** The
  plan's arrow notation (`Booked -> Pending review`) reads as the customer's
  action leading into the first queued state, matching the admin queue list
  in `project-plan.md` ("Queues: Pending review, Pending confirmation, ...")
  which never lists "Booked" as a queue. Same reasoning applies to
  `Placed -> Pending confirmation` for feature 7.
- **Code generation:** a plain `lockForUpdate()` on the last `bookings` row
  doesn't actually serialize two concurrent creators (neither request updates
  that row, so both can hold the lock on it in sequence and read the same
  "last" value). Use a Postgres transaction-scoped advisory lock
  (`pg_advisory_xact_lock`) around the read-max-and-insert instead - one
  extra line, auto-released at commit/rollback, and it actually prevents the
  race. Proportional for a single-shop, low-volume booking system.
- Keep `getGenericErrorMessage` plus a 422 field-error branch on the new
  form, matching the existing pattern in `ServicesPage.tsx`.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":13743,"specSha256":"7ea4ec16d642b73cf810dabc8236d0864c21860c3872522bb912eb083f9107e0","branch":"refs/heads/feature/bring-your-own-booking","head":"e70713a84482365cad03c85fdf1549ac050f3661","baseRef":"refs/heads/master","baseCommit":"58a35ef24889097ca1ffa9d0faf45a478d12d439","sourceTree":"560f1f78770bdcc8a15e5f3fd5b032b587c0f6fc","absentOptional":[]} -->

## Findings

### 6/F-29 [P1] closed - Booking notes and delivery address over 255 characters return 500

**File:** backend/database/migrations/2026_09_25_060000_create_bookings_table.php:22,49; backend/app/Http/Requests/Customer/StoreBookingRequest.php:37,39
**Found:** 2026-09-25 by /audit independent (scope: current; lens: quality/security/tests)
**Why it matters:** The spec contract and `StoreBookingRequest` accept `notes` up to `max:1000` and `delivery_address` up to `max:500`. Both columns are declared with `$table->string(...)`, which is `varchar(255)` because the project keeps Laravel's default string length. A value of 256 to 1000 (notes) or 256 to 500 (address) characters passes validation and then fails at the database. A scratch probe against the test database confirmed it: a 300-character note and a 300-character delivery address each returned `500`. Any customer can reach this through the `/book` form, which has no length limit on either input. They see a generic error and cannot submit the booking. This is the F-19/F-23 lesson the spec cites (validated input overflowing a fixed-size column). The transaction rolls back, so no partial data is written.
**Suggested fix:** Make the columns match the validated bounds. Because this migration has not shipped, edit it to `$table->text('notes')->nullable()` and `$table->string('delivery_address', 500)->nullable()` (or `text`). Add feature tests that post a 1000-character note and a 500-character address and expect `201`, plus one over each bound that expects `422`. Optionally mirror the limits in the zod schema in `NewBookingPage.tsx`. Current requirement lost: None.
**Resolution:** Fixed 2026-09-25: `notes` is now `$table->text('notes')`, `delivery_address` is now `$table->string('delivery_address', 500)`. Migration had not shipped, so it was edited in place and reapplied. Added `test_notes_and_delivery_address_up_to_the_validated_bound_are_accepted` asserting `201` for a 1000-char note and 500-char address. Closed 2026-09-25 by /audit independent (re-review of `e70713a`; scope: current; all lenses). The migration at `:22` is `string('delivery_address', 500)` and at `:49` is `text('notes')`, matching `StoreBookingRequest.php:37,39` (`max:500`, `max:1000`). The bound test passes in `composer test` (106/106). Rules above the bounds still return 422 through validation. No new defect in this area.

### 6/F-30 [P2] closed - Weights with more than 2 decimals store a subtotal that does not match the stored weight

**File:** backend/app/Http/Controllers/Api/Customer/BookingController.php:30,36; backend/app/Http/Requests/Customer/StoreBookingRequest.php:35
**Found:** 2026-09-25 by /audit independent (scope: current; lens: quality/tests)
**Why it matters:** `items.*.est_weight_kg` is validated only as `numeric|min:0.01|max:1000`, so `2.555` is accepted. The controller computes `subtotal = round(rate * 2.555, 2)` from the raw value, but Postgres rounds the stored `decimal(8,2)` weight to `2.56`. The probe confirmed a persisted item with `est_weight_kg: "2.56"`, `rate: "150.00"`, and `subtotal: "383.25"` (2.56 x 150 = 384.00), and `estimated_total` 383.25. The persisted rate-snapshot record contradicts the spec's `subtotal = est_weight_kg * rate`. It is an estimate that weigh-in replaces later, and the UI's `step="0.01"` blocks this in normal browser use, so the impact is limited to direct API calls.
**Suggested fix:** Add Laravel's `decimal:0,2` rule to `items.*.est_weight_kg`, or round the weight to 2 decimals before both storing and computing. Add a test for a 3-decimal weight. Current requirement lost: None.
**Resolution:** Fixed 2026-09-25: added the `decimal:0,2` rule to `items.*.est_weight_kg`, rejecting more than 2 decimal places at validation instead of silently rounding. Added `test_a_weight_with_more_than_two_decimals_is_rejected` asserting `422`. Closed 2026-09-25 by /audit independent (re-review of `e70713a`; scope: current; all lenses). `StoreBookingRequest.php:35` is `['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:1000']`, so every accepted weight fits `decimal(8,2)` exactly and `BookingController.php:30,36` computes the subtotal from the same value that is stored. The 3-decimal test passes. The frontend `step="0.01"` still matches. No new defect in this area.

### 6/F-31 [P2] closed - Unbounded booking items array overflows estimated_total and runs one query per item

**File:** backend/app/Http/Requests/Customer/StoreBookingRequest.php:27
**Found:** 2026-09-25 by /audit independent (scope: current; lens: performance/security)
**Why it matters:** `items` is `required|array|min:1` with no maximum. Each item is individually bounded (1000 kg), but the sum is not. With a normal rate of 150/kg, 700 items at 1000 kg total 105,000,000, which exceeds `bookings.estimated_total decimal(10,2)`. The probe confirmed `500` for that payload, after about 703 queries (one `exists` validation query per item, then the insert work) in about 1.2 s. Any logged-in customer can send large arrays to force this unbounded per-request database work and a 500 instead of a 422. The transaction rolls back, so nothing is written. This repeats the fixed-precision overflow pattern the spec asked to avoid.
**Suggested fix:** Add a realistic `max` to `items` (for example `max:20`), and optionally mirror it in the UI. Add a test that expects `422` just above the cap. Current requirement lost: None.
**Resolution:** Fixed 2026-09-25: added `max:20` to the `items` rule. Added `test_more_than_twenty_items_is_rejected` asserting `422` for 21 items. Closed 2026-09-25 by /audit independent (re-review of `e70713a`; scope: current; all lenses). `StoreBookingRequest.php:27` is `['required', 'array', 'min:1', 'max:20']`. Per-request work is now capped at 20 `exists` queries plus 20 item inserts, and the 21-item test passes. The worst-case total is 20 x 1000 kg x rate, which stays inside `decimal(10,2)` for any rate below 5,000/kg. The residual overflow at admin-set rates of 5,000/kg or more is noted as remaining risk in the receipt, not as a defect. No new defect in this area.

### 6/F-32 [P3] closed - Booking endpoint does not re-check is_active, so a disabled customer with a live session can book

**File:** backend/routes/api.php:45; backend/app/Http/Requests/Customer/StoreBookingRequest.php:17
**Found:** 2026-09-25 by /audit independent (scope: current; lens: security)
**Why it matters:** The route uses only `auth:sanctum`, and `authorize()` checks only `role === 'customer'`. The project's `EnsureRole` middleware (`role:` alias) re-checks `is_active` on every request so that disabling an account takes effect without waiting for logout. The spec's contract heading names `(auth:sanctum, role:customer)`. The probe confirmed that a customer set to `is_active = false` after logging in still gets `201`. Customer login already rejects disabled customers, and there is no customer-disable UI yet, so reachability today is limited. The endpoint also does not require a completed profile; only the frontend `ProtectedRoute` redirects incomplete profiles.
**Suggested fix:** Put the route behind `['auth:sanctum', 'role:customer']`, and either keep or remove the now-redundant `authorize()` check. Add a disabled-customer `403` test. Current requirement lost: None.
**Resolution:** Fixed 2026-09-25: the route now uses `['auth:sanctum', 'role:customer']`; kept the existing `authorize()` check for defense in depth (matches `CompleteProfileRequest`'s pattern). Added `test_a_disabled_customer_is_forbidden` asserting `403`. Closed 2026-09-25 by /audit independent (re-review of `e70713a`; scope: current; all lenses). `routes/api.php:45` uses `['auth:sanctum', 'role:customer']`. `EnsureRole` (`app/Http/Middleware/EnsureRole.php`, registered as `role` in `bootstrap/app.php:20`) aborts 403 when the role does not match or `! $user->is_active`. The disabled-customer test passes, and the admin and super-admin 403 tests still pass. The endpoint still does not require a completed profile; the spec does not require that either, so it is noted as remaining risk only. No new defect in this area.

### 6/F-33 [P3] closed - Status transition map omits cancellation from the pending states

**File:** backend/app/Services/Booking/BookingStatusEngine.php:24,33
**Found:** 2026-09-25 by /audit independent (scope: current; lens: quality)
**Why it matters:** `project-overview.md` defines `Cancelled` as an exit "only before Cooking" for customer-supplied bookings, and as an exit for shop-supplied bookings. Feature 8 plans "cancel before cooking". The map allows `cancelled` only from `approved` and `confirmed`. It does not allow it from `pending_review` or `pending_confirmation`, which are the states a newly created booking is in. The spec says the map is built now for later reuse, so feature 8 would inherit a rule that blocks the most common cancellation. This feature does not exercise the path, so nothing breaks today.
**Suggested fix:** Add `'cancelled'` to `pending_review` and `pending_confirmation` (after confirming the intended rule), with a unit test for each. Current requirement lost: None.
**Resolution:** Fixed 2026-09-25: added `'cancelled'` to both `pending_review` and `pending_confirmation` in the transition map. Added a unit test for each new allowed transition. Closed 2026-09-25 by /audit independent (re-review of `e70713a`; scope: current; all lenses). `BookingStatusEngine.php:24` (`pending_review => ['approved', 'rejected', 'cancelled']`) and `:33` (`pending_confirmation => ['confirmed', 'rejected', 'cancelled']`) now allow cancellation from both initial states. Cancellation is still blocked from `cooking` onward, matching "only before Cooking" in the overview. Both new unit tests pass. No new defect in this area.

## Independent review

**Status:** passed
**Target commit:** e70713a84482365cad03c85fdf1549ac050f3661
**Base commit:** 58a35ef24889097ca1ffa9d0faf45a478d12d439
**Base ref:** master
**Spec hash:** 7ea4ec16d642b73cf810dabc8236d0864c21860c3872522bb912eb083f9107e0
**Prepared by:** claude
**Builder model:** claude-sonnet-5
**Requested reviewer:** claude
**Requested model:** claude-opus-5-5
**Requested execution:** automatic
**Requested at:** 2026-09-25T06:57:57Z
**Workflow:** continuous
**Check required:** no
**Reviewer adapter:** claude
**Reviewer model:** claude-opus-5-5
**Reviewer context:** fresh subagent
**Actual execution:** automatic
**Reviewed at:** 2026-09-25T07:00:54Z
**Scope:** current
**Lenses:** quality, security, performance, tests
**Verdict:** passed
**Check result:** not-required

### Commands

- `git rev-parse HEAD` / `git merge-base master HEAD` / `sha256sum blueprint/context/current-feature.md` / `git status --porcelain`: pass (HEAD, base, and spec hash match the request; the only dirty path was `blueprint/context/review.md`)
- `composer test` (backend): pass (106 tests, 355 assertions)
- `vendor/bin/pint --test` (backend): pass
- `npm run test -- --run` (frontend): pass (4 files, 19 tests)
- `npm run lint` (frontend): pass (exit 0; 3 warnings, 1 of them new: `react(incompatible-library)` on `NewBookingPage.tsx:86` for `watch`, the same warning already present on `ServicesPage.tsx`)
- `npm run build` (frontend): pass (existing >500 kB chunk-size warning)

### Evidence

- Reviewed the complete `58a35ef..e70713a` product delta across all four lenses: 4 migrations, 3 models plus factories, `BookingStatusEngine`, `StoreBookingRequest`, `BookingResource`/`BookingItemResource`, `Customer/BookingController`, the route, the feature and unit tests, and the frontend `bookings` module, `NewBookingPage`, `currency`, `App.tsx`, and `CustomerLayout.tsx`.
- F-29: `create_bookings_table.php:22` is `string('delivery_address', 500)` and `:49` is `text('notes')`, matching the `max:500`/`max:1000` rules. The at-bound test returns 201 in the suite.
- F-30: `items.*.est_weight_kg` carries `decimal:0,2`, and the 3-decimal test returns 422.
- F-31: `items` carries `max:20`, and the 21-item test returns 422.
- F-32: the route is behind `role:customer`. `EnsureRole` aborts 403 on a role mismatch or `! is_active`, and the disabled-customer test returns 403.
- F-33: the transition map allows `cancelled` from `pending_review` and `pending_confirmation`, and both unit tests pass.
- Security: customer-only access is enforced by both the middleware and `authorize()`. The owner comes from `$request->user()`, never from input. The service must exist and be active and customer-supplied, and the rate is read server-side. The code is generated under `pg_advisory_xact_lock` inside one transaction.
- The app timezone is `UTC` (`config/app.php:68`), so the frontend's `toISOString()` drop-off time stores without a shift.

### Findings

- F-29, F-30, F-31, F-32, F-33: moved `fixed` -> `closed` after re-examining the current code and passing tests.
- F-34 [P3] open (new): the booking form's services query fails silently (`NewBookingPage.tsx:68`).
- No P0 or P1 finding is `open` or `fixed`.

### Remaining risk

- Check was not required. No interactive browser walkthrough of `/book` was performed in this review, and no `Browser tests` command is declared.
- `estimated_total` and a per-item `subtotal` (`decimal(10,2)`) can still overflow into a 500 when an admin sets a roasting rate of about 5,000/kg or more (20 items x 1000 kg) or 100,000/kg or more (one item). This is not reachable with realistic rates and is not verified at runtime.
- `POST /api/v1/bookings` does not require a completed customer profile on the server. Only the frontend `ProtectedRoute` redirects incomplete profiles. The spec does not require a server-side check.
- There is no concurrency test for booking-code generation. The advisory-lock design was reviewed by reading only.
