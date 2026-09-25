# Feature: Walk-in bookings

**From build-plan:** feature 13
**Build attempt:** 1
**Branch:** feature/walk-in-bookings
**Status:** verified

## Goal

Let an admin record a walk-in at the counter: either booking type, for a
registered customer or a guest (name + phone only, no account), with the
food/items already physically on hand. Because nothing needs to wait, both
paths land the booking directly at `confirmed` in one request - a
bring-your-own walk-in is weighed in immediately (the build-plan's "instant
weigh-in when the food is on hand"), and a shop walk-in is treated as an
already-complete in-person sale. No new `BookingStatusEngine` transitions are
needed: every hop used here (`null -> pending_review -> approved -> confirmed`
for roasting, `null -> pending_confirmation -> confirmed` for shop) is already
legal today, just never chained by one caller before.

## In scope

- `GET /admin/bookings/customers?search=` - autocomplete search over
  registered customers (`role = customer`) by phone or first/last name
  (`ilike`, same pattern as `AdminBookingController::index`'s search), for
  picking an existing customer instead of typing a guest. Requires
  `search` (min 2 chars), returns up to 10 `{ id, name, phone }` rows.
- `POST /admin/bookings/walk-in-roasting` - create a `customer_supplied`
  walk-in with final weights already known (not estimates) and instantly move
  it through `pending_review -> approved -> confirmed`, exactly like a normal
  booking's `approve` + `weigh-in` would, but in one call with no separate
  drop-off scheduling. `dropoff_at`, `approved_at`, `weighed_at`,
  `confirmed_at` are all set to the creation time; `approved_by` and
  `confirmed_by` are the acting admin.
- `POST /admin/bookings/walk-in-shop` - create a `shop_supplied` walk-in
  order (services with `allow_shop_supplied`, in stock), reserving stock
  exactly like `Customer\OrderController::store`, and instantly move it
  through `pending_confirmation -> confirmed`. `confirmed_at`/`confirmed_by`
  set at creation.
- Either endpoint accepts **exactly one** of a registered `customer_id` or a
  `guest_name` + `guest_phone` pair (both required together, no account
  created for a guest - this only fills `bookings.guest_name`/`guest_phone`,
  which the schema and `AdminBookingResource` already support since feature
  9).
- Both endpoints accept `fulfillment` (`pickup`/`delivery`) and
  `delivery_address` (required when `delivery`), same validation as the two
  existing customer-facing store endpoints - a walk-in customer can still ask
  for delivery of the finished roast or a delivered item order.
- Full status-log trail: each intermediate hop still writes its own
  `booking_status_logs` row via `BookingStatusEngine::transition()`, so a
  walk-in's timeline looks identical to a booking that went through the
  stages one at a time, just all logged within the same request.
- Admin walk-in form (`/admin/bookings/walk-in`, `bookings` module gated,
  same as the rest of `/admin/bookings/*`): booking-type toggle (bring your
  own / shop items), registered-customer search-and-pick vs. guest
  name+phone fields, an item picker per type (final weight per item for
  roasting, qty per item for shop - reusing the existing active-services
  data), pickup/delivery + address, notes, and a submit that routes to the
  new booking's detail page on success.
- Nav link to the new page from the admin bookings area.
- Feature tests for both walk-in types (registered customer and guest),
  validation, insufficient stock, the search endpoint, and the `bookings`
  module/role/auth matrix.

## Out of scope

- Any change to `BookingStatusEngine::TRANSITIONS` - every transition this
  feature chains already exists there.
- A "weigh in later" walk-in variant (scheduling a future drop-off for a
  walk-in makes it a regular booking, not a walk-in; an admin can already
  create that by having the customer book normally, feature 6).
- Creating or updating a customer account from the guest fields - a guest
  walk-in never becomes a `users` row; it only ever populates
  `bookings.guest_name`/`guest_phone`, same as an already-supported search
  hit but with no account behind it.
- Editing, cancelling, or rejecting a walk-in after creation - it lands on
  `confirmed`, which the existing admin cancel action (feature 12) already
  covers; no new action needed.
- A "walk-in" badge/indicator or a separate queue tab - not requested; a
  walk-in booking is indistinguishable in the queues from any other booking
  that reached `confirmed`, except for a null `customer_id` when it's a
  guest (already rendered via `guest_name`/`guest_phone`).
- Payments (feature 14).

## Build loop

Config: `workflow.stepReview = "feature"`, `workflow.checkpointCommits =
"disabled"`. Implement all build steps in one pass; the user reviews once at
the end of the full diff instead of after each step. No checkpoint commits
between steps; `/complete` makes the one feature commit.

## Build steps

- [x] 1. **Backend: `Api\Admin\WalkInController` and its form requests**
   - New `backend/app/Http/Requests/Admin/StoreWalkInRoastingRequest.php`
     (`authorize(): true`, mirrors
     `Customer\StoreBookingRequest`'s `items`/`fulfillment`/
     `delivery_address`/`notes` rules but with `items.*.final_weight_kg`
     instead of `est_weight_kg` - same `['required','numeric','decimal:0,2',
     'min:0.01','max:1000']`), plus:
     ```php
     'customer_id' => ['nullable', 'integer',
         Rule::exists('users', 'id')->where(fn ($q) => $q->where('role', 'customer')),
         'required_without_all:guest_name,guest_phone', 'prohibits:guest_name,guest_phone'],
     'guest_name' => ['nullable', 'string', 'max:255', 'required_with:guest_phone', 'prohibits:customer_id'],
     'guest_phone' => ['nullable', 'string', 'max:32', 'required_with:guest_name', 'prohibits:customer_id'],
     ```
     No `preferred_dropoff_at` field - the walk-in has no future drop-off to
     schedule.
   - New `backend/app/Http/Requests/Admin/StoreWalkInShopOrderRequest.php`:
     same `customer_id`/`guest_name`/`guest_phone` block, plus
     `Customer\StoreShopOrderRequest`'s `items.*.service_id`/`items.*.qty`/
     `fulfillment`/`delivery_address`/`notes` rules unchanged.
   - New `backend/app/Http/Controllers/Api/Admin/WalkInController.php`:
     - `searchCustomers(Request $request): JsonResponse` - validate
       `['search' => ['required', 'string', 'min:2', 'max:255']]`; query
       `User::where('role', 'customer')->where(fn ($q) => $q->where('phone',
       'ilike', $like)->orWhereRaw("concat_ws(' ', first_name, last_name)
       ilike ?", [$like]))->orderBy('first_name')->limit(10)->get(['id',
       'first_name', 'last_name', 'phone'])`; map to `{ id, name: trim("{$c
       ->first_name} {$c->last_name}"), phone }` (same trim shape
       `AdminBookingResource` already uses for a registered customer's name -
       no `middle_name`), return as a plain JSON array.
     - `storeRoasting(StoreWalkInRoastingRequest $request, BookingStatusEngine
       $statusEngine, BookingCodeGenerator $codeGenerator):
       AdminBookingResource` - inside `DB::transaction`: load the requested
       services (`allow_customer_supplied` + `is_active` already enforced by
       the request's `Rule::exists`), build `items` data with
       `rate = roasting_rate_per_kg`, `est_weight_kg = final_weight_kg =
       $item['final_weight_kg']`, `subtotal = round(rate * final_weight_kg,
       2)`, sum into `$totalAmount`. Create the `Booking` with `code`,
       `customer_id`/`guest_name`/`guest_phone` from validated input,
       `source_type: 'customer_supplied'`, `fulfillment`,
       `delivery_address`, `shipping_fee: 0`, `preferred_dropoff_at: now()`,
       `dropoff_at: now()`, `estimated_total: round($totalAmount, 2)`,
       `total_amount: round($totalAmount, 2)`, `notes`,
       `approved_at: now()`, `approved_by: $request->user()->id`,
       `weighed_at: now()`, `confirmed_at: now()`,
       `confirmed_by: $request->user()->id` (all fillable already). Create
       each `booking_items` row. Then call
       `$statusEngine->transition($booking, 'pending_review',
       $request->user()->id)`, `->transition($booking, 'approved', ...)`,
       `->transition($booking, 'confirmed', ...)` in that order (each hop is
       already legal in `BookingStatusEngine::TRANSITIONS['customer_supplied']`,
       so this needs no engine changes). Return `new AdminBookingResource
       ($booking->load([...same eager-load list as
       `AdminBookingController::detailEagerLoads()`...]))`.
     - `storeShop(StoreWalkInShopOrderRequest $request, BookingStatusEngine
       $statusEngine, BookingCodeGenerator $codeGenerator):
       AdminBookingResource` - inside `DB::transaction`: same
       lock-distinct-services-ascending-by-id + per-item stock check +
       decrement pattern as `Customer\OrderController::store` (throw
       `ValidationException` on `items.{index}.qty` when stock is
       insufficient), sum `$totalAmount`, save each service. Create the
       `Booking` with `source_type: 'shop_supplied'`, `estimated_total:
       round($totalAmount, 2)`, `total_amount: round($totalAmount, 2)`,
       `confirmed_at: now()`, `confirmed_by: $request->user()->id`, plus the
       same `customer_id`/`guest_name`/`guest_phone`/`fulfillment`/
       `delivery_address`/`notes` fields. Create each `booking_items` row and
       a matching `inventory_logs` row (`reason: 'reserve'`, `change_qty:
       -qty`, `created_by: $request->user()->id` - the acting admin, not the
       walk-in customer), identical to the customer order flow. Then
       `->transition($booking, 'pending_confirmation', ...)`,
       `->transition($booking, 'confirmed', ...)`. Return the loaded
       resource the same way as `storeRoasting`.
   - Done when: `composer test` passes for the new tests below.

- [x] 2. **Backend: routes**
   - In `backend/routes/api.php`, inside the existing `module:bookings`
     group, right after the `/bookings` index route and before the
     `{id}`-parameterized routes, add:
     - `GET /admin/bookings/customers` -> `WalkInController::searchCustomers`
     - `POST /admin/bookings/walk-in-roasting` -> `WalkInController::storeRoasting`
     - `POST /admin/bookings/walk-in-shop` -> `WalkInController::storeShop`
   - Done when: `php artisan route:list` shows all three new routes.

- [x] 3. **Backend tests**
   - New `backend/tests/Feature/Admin/AdminWalkInBookingTest.php` (same
     `loginAsSuperAdmin`/`loginAsAdminWithoutBookingsPermission`/
     `withHeader('Referer', ...)` setup as the existing admin booking
     tests), covering:
     - Roasting walk-in for a registered customer: `201`, `customer_id` set,
       `guest_name`/`guest_phone` null, `status: 'confirmed'`,
       `source_type: 'customer_supplied'`, `approved_at`/`weighed_at`/
       `confirmed_at`/`dropoff_at` all set, `total_amount` equals
       `rate * final_weight_kg` (rounded), and exactly three
       `booking_status_logs` rows in order `pending_review`, `approved`,
       `confirmed`.
     - Roasting walk-in for a guest: `customer_id` null,
       `guest_name`/`guest_phone` set as given.
     - Shop walk-in for a registered customer and for a guest: `201`,
       `status: 'confirmed'`, `source_type: 'shop_supplied'`, stock
       decremented by each item's `qty`, a matching `inventory_logs` row per
       item (`reason: 'reserve'`), `total_amount` equals
       `sum(shop_price * qty)`, two `booking_status_logs` rows
       (`pending_confirmation`, `confirmed`).
     - Shop walk-in with insufficient stock: `422` on `items.{index}.qty`,
       no `Booking` row created, `Service.stock_qty` unchanged (transaction
       rolled back).
     - Validation: neither `customer_id` nor guest pair -> `422`; both a
       `customer_id` and a guest pair -> `422`; `guest_name` without
       `guest_phone` (or vice versa) -> `422`; unknown/non-customer
       `customer_id` -> `422`; a service that doesn't allow the given
       booking type or is inactive -> `422`; `delivery` fulfillment without
       `delivery_address` -> `422`.
     - `GET /admin/bookings/customers`: matches by phone substring and by
       name substring, excludes admin/super_admin rows, respects the 10-row
       limit, `422` when `search` is missing or under 2 characters.
     - Admin without the `bookings` permission is `403` on all three
       endpoints; customer role is forbidden; unauthenticated is rejected.
   - Done when: `composer test` is green, including this new file.

- [x] 4. **Frontend: API, hooks, and types**
   - `frontend/src/features/admin-bookings/types.ts`: add
     `WalkInCustomer { id: number; name: string; phone: string }` and
     `WalkInGuestOrCustomer` payload fields (`customer_id: number | null`,
     `guest_name: string | null`, `guest_phone: string | null`) shared by
     the two new payload interfaces below.
   - `frontend/src/features/admin-bookings/api.ts`: add
     `searchWalkInCustomers(search: string): Promise<WalkInCustomer[]>` (`GET
     /admin/bookings/customers`), `WalkInRoastingPayload` (`...guest fields,
     items: { service_id: number; final_weight_kg: number }[], fulfillment,
     delivery_address: string | null, notes: string | null`) with
     `createWalkInRoastingBooking(payload)` (`POST
     /admin/bookings/walk-in-roasting`, `ensureCsrfCookie()` first), and the
     shop equivalents `WalkInShopPayload`/`createWalkInShopOrder(payload)`
     (`POST /admin/bookings/walk-in-shop`), each returning
     `Promise<AdminBooking>`.
   - `frontend/src/features/admin-bookings/hooks.ts`: add
     `useSearchWalkInCustomers(search: string)` (`useQuery`, `queryKey:
     ['walk-in-customers', search]`, `enabled: search.trim().length >= 2`),
     `useCreateWalkInRoastingBooking()` and `useCreateWalkInShopOrder()`
     (mutations reusing the existing `useBookingActionInvalidation()`
     helper, invalidating with the returned booking's `id`).
   - Done when: `npm run lint` passes.

- [x] 5. **Frontend: walk-in form page**
   - New `frontend/src/features/admin-bookings/WalkInBookingPage.tsx`,
     structured like `NewBookingPage.tsx`/`NewShopOrderPage.tsx`
     (`react-hook-form` + `zod`, same error-handling pattern: 422 field
     errors via `getGenericErrorMessage`/`isAxiosError`):
     - A booking-type toggle (`Bring your own` / `Shop items`) that swaps
       the item-picker shape and the services source
       (`useBookableServices()` from `@/features/bookings/hooks` for
       roasting - `final_weight_kg` input instead of `est_weight_kg`;
       `useShoppableServices()` from `@/features/orders/hooks` for shop -
       `qty` input, unchanged).
     - A customer-or-guest toggle: "Registered customer" shows a search
       `Input` wired to `useSearchWalkInCustomers`, a result list to click
       and select (storing `customer_id`, showing the picked name/phone with
       a way to clear/change it); "Guest" shows `guest_name` +
       `guest_phone` inputs. Exactly one of the two is submitted, matching
       the backend's exclusivity rule; a client-side `superRefine` mirrors
       it (require a selection or both guest fields) for immediate feedback,
       same style as the existing delivery-address `superRefine`.
     - Pickup/delivery radios + conditional delivery address, and a notes
       field - same markup as `NewBookingPage.tsx`.
     - On submit, call `createWalkInRoastingBooking`/`createWalkInShopOrder`
       depending on the type toggle; on success, navigate to
       `/admin/bookings/${booking.id}` (`useNavigate`) instead of showing an
       inline confirmation, since this is an admin workflow that continues
       into the booking detail/queue rather than "book another".
   - `frontend/src/App.tsx`: add `<Route path="/admin/bookings/walk-in"
     element={<WalkInBookingPage />} />` inside the existing
     `requireModule="bookings"` `RoleRoute` block, alongside
     `/admin/bookings` and `/admin/bookings/:id` (register it before the
     `:id` route since `walk-in` would otherwise never be reached as a
     literal segment if matched after a catch-all - React Router matches by
     specificity, but keep the literal route first for clarity).
   - `frontend/src/features/admin-bookings/AdminBookingsPage.tsx`: add a
     `Link to="/admin/bookings/walk-in"` button near the page heading (same
     `Button`/`Link` pattern as the existing "View" links).
   - Done when: `npm run lint` and `npm run build` pass, and a manual check
     in the running app: create one bring-your-own walk-in for a guest
     (appears in the `Confirmed` queue, weighed and priced, full status
     timeline on its detail page) and one shop walk-in for a registered
     customer found via search (stock decreases on the services page,
     booking appears in `Confirmed`); also submit each form with neither
     customer nor guest filled in and confirm the inline validation error.

## Files / areas

- `backend/app/Http/Controllers/Api/Admin/WalkInController.php` (new)
- `backend/app/Http/Requests/Admin/StoreWalkInRoastingRequest.php` (new)
- `backend/app/Http/Requests/Admin/StoreWalkInShopOrderRequest.php` (new)
- `backend/routes/api.php`
- `backend/tests/Feature/Admin/AdminWalkInBookingTest.php` (new)
- `frontend/src/features/admin-bookings/api.ts`
- `frontend/src/features/admin-bookings/hooks.ts`
- `frontend/src/features/admin-bookings/types.ts`
- `frontend/src/features/admin-bookings/WalkInBookingPage.tsx` (new)
- `frontend/src/features/admin-bookings/AdminBookingsPage.tsx`
- `frontend/src/App.tsx`

## Data / contracts

- No schema changes - `bookings.customer_id` (nullable), `guest_name`,
  `guest_phone` already exist (feature 9's schema anticipated walk-ins), and
  every status this feature uses is already a valid `bookings.status` value.
- `GET /api/v1/admin/bookings/customers?search=<string>` - `search` required,
  min 2 chars. `200` with up to 10 `{ id: number, name: string, phone:
  string }` rows, `role = customer` only, matched by phone or first+last
  name substring. `422` when `search` is missing/too short. `403` outside
  the `bookings` module.
- `POST /api/v1/admin/bookings/walk-in-roasting` - body:
  ```json
  {
    "customer_id": null,
    "guest_name": "Jane Dela Cruz",
    "guest_phone": "09171234567",
    "items": [{ "service_id": 1, "final_weight_kg": 2.5 }],
    "fulfillment": "pickup",
    "delivery_address": null,
    "notes": null
  }
  ```
  Exactly one of `customer_id` or `guest_name`+`guest_phone`. `201` with the
  `AdminBookingResource` shape (`status: "confirmed"`, `source_type:
  "customer_supplied"`, `approved_at`/`weighed_at`/`confirmed_at`/
  `dropoff_at` set, `total_amount` = sum of `rate * final_weight_kg`, three
  status-log entries). `422` on validation failure (see test list above).
- `POST /api/v1/admin/bookings/walk-in-shop` - body:
  ```json
  {
    "customer_id": 42,
    "guest_name": null,
    "guest_phone": null,
    "items": [{ "service_id": 3, "qty": 2 }],
    "fulfillment": "delivery",
    "delivery_address": "123 Sample St.",
    "notes": null
  }
  ```
  `201` with `status: "confirmed"`, `source_type: "shop_supplied"`,
  `total_amount` = sum of `shop_price * qty`, stock reserved (decremented +
  `inventory_logs` `reason: "reserve"` rows), two status-log entries. `422`
  when any item exceeds available stock (`items.{index}.qty`) or the
  customer/guest rule fails.
- All three: `401` unauthenticated, `403` for a `customer`-role session or an
  admin without the `bookings` module permission, same shapes as every other
  admin booking endpoint.

## Testing

- Backend (PHPUnit, `composer test`): new `AdminWalkInBookingTest` covers
  both walk-in types for a registered customer and a guest, the
  customer/guest exclusivity validation, insufficient-stock rejection with
  no partial writes, the customer-search endpoint's matching and limit, and
  the standard permission/auth matrix - all logic with a real right/wrong
  answer, per the project's testing scope rule.
- Frontend: no new pure-logic units (totals are server-computed, same as the
  existing booking/order flows); covered by `npm run lint`, `npm run build`,
  and the manual browser check in step 5. No `Browser tests` command is
  declared, so the walk-in form is verified with the dev server plus API
  responses, per `coding-standards.md`.

## Notes for the AI

- Chaining three (`storeRoasting`) or two (`storeShop`)
  `BookingStatusEngine::transition()` calls inside one `DB::transaction()`
  is the intended design - it reuses the existing, already-audited
  transition rules and writes the normal `booking_status_logs` trail, rather
  than adding a new "instant" branch to the engine's transition table. Do
  not add `null -> approved` or `null -> confirmed` entries to
  `BookingStatusEngine::TRANSITIONS`.
- The mutual-exclusivity validation (`required_without_all` +
  `prohibits` + `required_with`, symmetric on both sides) is the whole
  guard for "registered customer or guest, never both, never neither" -
  no additional controller-level branching is needed beyond passing
  whichever fields are set straight into `Booking::create()`.
- `storeShop` must reuse `Customer\OrderController::store`'s exact locking
  order (`whereIn` the distinct service ids, `orderBy('id')`,
  `lockForUpdate()`) so a walk-in shop sale and a concurrent customer order
  or admin cancel can't deadlock.
- `guest_name`/`guest_phone` walk-ins never create a `users` row - this is
  deliberate per the build-plan's "guest (name + phone)" phrasing; only a
  registered customer found via search gets `customer_id` set.
- The customer-search name shape (`trim("{first} {last}")`, no
  `middle_name`) must match `AdminBookingResource::toArray()`'s existing
  convention exactly, so a walk-in's picked customer name reads the same as
  that same customer's name everywhere else in the admin UI.
- Reuse `useBookableServices`/`useShoppableServices` from the customer-facing
  `bookings`/`orders` feature folders rather than duplicating the
  `/services`-fetch-and-filter logic a third time.
- Duplicating the same eager-load array from
  `AdminBookingController::detailEagerLoads()` into the new
  `WalkInController` (rather than extracting a shared helper for two
  call sites) matches this project's proportional-engineering default.

<!-- blueprint:completion {"schemaVersion":1,"specBytes":22396,"specSha256":"c6ef18b30b7aa6cfc1203208e460185a87e5d05c7c979c99e26cc1fde3044cee","branch":"refs/heads/feature/walk-in-bookings","head":"28ddbc2e0f2f743c42adc22e99d224d5c22dbfd4","baseRef":"refs/heads/master","baseCommit":"c79e5c492d68f5bfc1af90e8413b1a6ae9221f09","sourceTree":"28c3d40b1d14b368dc48ed267cd43279cca39077","absentOptional":[]} -->

## Independent review

**Status:** passed
**Target commit:** 28ddbc2e0f2f743c42adc22e99d224d5c22dbfd4
**Base commit:** c79e5c492d68f5bfc1af90e8413b1a6ae9221f09
**Base ref:** master
**Spec hash:** c6ef18b30b7aa6cfc1203208e460185a87e5d05c7c979c99e26cc1fde3044cee
**Prepared by:** claude
**Builder model:** claude-sonnet-5
**Requested reviewer:** claude
**Requested model:** claude-opus-5-5
**Requested execution:** automatic
**Requested at:** 2026-09-25T15:40:00Z
**Workflow:** regular
**Check required:** no
**Reviewer adapter:** claude
**Reviewer model:** claude-opus-5-5
**Reviewer context:** fresh subagent
**Actual execution:** automatic
**Reviewed at:** 2026-09-25T15:45:03Z
**Scope:** current
**Lenses:** quality, security, performance, tests
**Verdict:** passed
**Check result:** not-required

## Commands

- `git rev-parse HEAD` / `git merge-base master HEAD` / `git show HEAD:blueprint/context/current-feature.md | sha256sum` / `git status --porcelain`: pass (target, base, and spec hash match the request; only `blueprint/context/review.md` differed)
- `composer test` (backend): pass (227 tests, 1002 assertions)
- `npm run lint` (frontend): pass (0 errors; warnings are the existing react-hook-form `incompatible-library` pattern plus one pre-existing `button.tsx` warning)
- `npx tsc -b` (frontend): pass (exit 0)
- `npm run test` (frontend Vitest): pass (7 files, 33 tests)
- `npm run build` (frontend): pass (only the existing chunk-size warning)

## Evidence

- Reviewed the full `c79e5c4..28ddbc2` delta: `WalkInController.php`, both walk-in form requests, `routes/api.php`, `AdminWalkInBookingTest.php`, `WalkInBookingPage.tsx`, and the admin-bookings `api.ts`/`hooks.ts`/`types.ts`/`AdminBookingsPage.tsx`/`App.tsx` changes. Compared them against `BookingStatusEngine`, `Admin\BookingController` (approve/weigh-in/confirm-order), `Customer\BookingController::store`, and `NewShopOrderPage.tsx`.
- Security: all three routes sit inside the existing `auth:sanctum` + `role:admin,super_admin` + `module:bookings` group, and tests prove 403/403/401 for no-permission admin, customer, and unauthenticated users. `customer_id` is restricted to `role = customer` by `Rule::exists`. `Booking::create` receives only explicit validated fields. The search uses bound parameters, and React escapes guest text.
- Correctness: each hop goes through `BookingStatusEngine::transition()` along existing legal transitions, so no engine change was needed. The customer/guest exclusivity rules (`required_without_all` + `prohibits` + `required_with`) behave as specified, and tests cover neither, both, and half-pair. Totals use the same `round(rate * weight, 2)` as weigh-in.
- Concurrency: `storeShop` locks the distinct service ids in ascending order with `lockForUpdate()`, matching `OrderController::store`. The shared-instance decrement handles duplicate lines, and the insufficient-stock test proves full rollback.
- Frontend: the literal `/admin/bookings/walk-in` route is registered before `:id` inside the `bookings` module `RoleRoute`. Mutations reuse `useBookingActionInvalidation()`. The 422 handling matches `NewShopOrderPage.tsx`.

## Findings

- F-56 [P3] open: walk-in form number messages are wrong past the upper bound, and the decimal and integer rules are not mirrored on the client
- F-57 [P3] open: the walk-in customer search and services queries fail silently, and an empty search result shows nothing
- F-58 [P3] open: guest phone is stored as typed, not normalized like customer phones
- F-59 [P3] open: walk-in tests skip status-log order, roasting item rows, and duplicate shop lines
- Re-examined, still open: F-04, F-44, F-46
- No P0 or P1 findings

## Remaining risk

- Check was not required and was not run, so the walk-in form was not exercised in a running browser. No `Browser tests` command is declared.
- The customer search fires one request per keystroke of 2 or more characters, with no debounce. Each request runs a leading-wildcard `ilike` over `users`. This is fine at current scale but was not measured.
- The search and the `customer_id` rule do not filter on `is_active`, so an admin can record a walk-in for a disabled customer. The spec does not say whether that is intended. This is a product decision, not a confirmed defect.
- `%`/`_` in the search term act as SQL wildcards. This matches the existing admin queue search and is reachable only by a permitted admin.
