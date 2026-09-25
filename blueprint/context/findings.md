# Findings

> **Generated file.** The findings ledger: review findings raised by `/audit`
> against the work in progress, each with a durable ID, severity (P0-P3), and
> status. `/implement` marks repaired findings `fixed`, a later `/audit` pass
> moves them to `closed`, and `/complete` refuses to merge while any P0 or P1
> finding is `open` or `fixed`, then archives resolved findings with the work
> and resets this file.

### F-04 [P3] open - All throttle:6,1 auth routes share one per-IP rate-limit bucket

**File:** backend/routes/api.php:12
**Found:** 2026-09-24 by /audit independent (scope: current; lens: security)
**Why it matters:** Unnamed `throttle:6,1` keys guests only by `domain|ip` (`ThrottleRequests::resolveRequestSignature`), with no route in the key. Admin login (line 12) and customer login (line 22) still count against the same 6/minute budget, so users behind a shared NAT share one budget. The limit still caps brute force, so this is not a security gap.
**Suggested fix:** Define named limiters in `AppServiceProvider` with `RateLimiter::for(...)`, for example keyed by IP plus route or plus the submitted phone or email, and use `throttle:admin-login` and similar on each route.
**Resolution:** Re-examined 2026-09-25 by /audit independent (Feature 2 review; scope: current; lens: security). Feature 2 removed `POST /api/v1/register` entirely, so the shared bucket now covers only admin login and customer login (previously also customer register). The core finding still holds for those two routes; line numbers updated to match the current file. Still open. Re-examined 2026-09-25 by /audit independent (Feature 12 review of `fddfa20`; scope: current; all lenses). Feature 12 added six admin booking routes at `api.php:50-55` but did not touch the auth routes or their throttle; lines 12 and 22 are unchanged. Still open. Re-examined 2026-09-25 by /audit independent (Feature 13 review of `28ddbc2`; scope: current; all lenses). Feature 13 added one import and three walk-in routes to `api.php`. It did not touch the auth routes. The two `throttle:6,1` login routes are now at `api.php:19` and `:63` and still share the unnamed per-IP bucket. Still open.

### F-08 [P3] unverified - Admin login timing may reveal whether an admin email exists

**File:** backend/app/Http/Controllers/Api/Admin/AuthController.php:18
**Found:** 2026-09-24 by /audit independent (scope: current; lens: security)
**Why it matters:** The error message is generic as the spec requires, but `Hash::check` runs only when the user exists and is an active admin. The short-circuit makes an unknown email, customer email, or disabled account respond measurably faster than an active admin with a wrong password. That could leak which emails are active admins, which the spec's generic-error rule aims to prevent. Not measured, and the throttle limits sampling.
**Suggested fix:** Always run one hash check, for example against a fixed dummy hash when the user is missing or ineligible, before returning the generic error.
**Resolution:**

### F-09 [P3] open - A failed CSRF-cookie bootstrap is cached for the rest of the page session

**File:** frontend/src/lib/api.ts:18
**Found:** 2026-09-24 by /audit independent (scope: current; lens: quality)
**Why it matters:** `csrfCookiePromise ??= axios.get(...)` stores the promise even when it rejects, and `??=` never replaces a rejected promise. If the first `/sanctum/csrf-cookie` call fails (backend not up yet, a network blip), every later login, register, logout, and admin-account mutation awaits that same rejected promise. They all fail with the generic error until a full page reload, even after the backend recovers.
**Suggested fix:** Clear the cached promise on failure, for example `.catch((error) => { csrfCookiePromise = null; throw error })`, so the next mutation retries the bootstrap.
**Resolution:**

### F-10 [P3] open - Admin-accounts list query and both logout buttons still fail silently

**File:** frontend/src/features/admin-accounts/AdminAccountsPage.tsx:85
**Found:** 2026-09-24 by /audit independent (scope: current; lens: quality)
**Why it matters:** `useAdminAccounts()` reads only `data` and `isLoading`. On a 5xx or network error the page shows the heading and the create form with no table and no message. The logout buttons in `components/layouts/AdminLayout.tsx:23` and `features/auth/AccountPage.tsx:17` pass no `onError`, so a failed logout (for example a 419 after the session expired) just re-enables the button. The coding standards say to surface errors rather than fail silently. F-01 fixed the create form, row actions, and login forms, but not these paths.
**Suggested fix:** Render an inline `getGenericErrorMessage(error)` when the accounts query has `isError`, and add an `onError` inline message to both logout mutations.
**Resolution:** Re-examined 2026-09-25 by /audit independent (Feature 2 review; scope: current; lens: quality). Feature 2 added a `displayName` computation above the logout button in `AccountPage.tsx`, shifting the logout button to line 17; the missing `onError` itself is unchanged. Still open.

### F-11 [P3] open - /me permissions ignore is_active, drifting from hasModulePermission

**File:** backend/app/Http/Resources/UserResource.php:32
**Found:** 2026-09-24 by /audit independent (scope: current; lens: security)
**Why it matters:** `UserResource` rebuilds the permission list from `role` alone (all modules for `super_admin`, granted rows for `admin`). After the F-06 repair, `hasModulePermission()` returns false for a disabled user, but `GET /api/v1/me` (guarded only by `auth:sanctum`) still returns 200 with the full permission list for a disabled admin with a live session. `RoleRoute` then admits them to the admin shell. Backend admin routes still 403 through `EnsureRole`, so no data is exposed today. The risk is that later module UIs will trust `/me.permissions`, and the permission rule now lives in two places.
**Suggested fix:** Derive the list from the primitive, for example `array_values(array_filter(User::MODULES, fn ($m) => $this->hasModulePermission($m)))`, or return `[]` when `! is_active`. Optionally add a `/me` test for a disabled admin.
**Resolution:** Re-examined 2026-09-25 by /audit independent (Feature 2 review; scope: current; lens: security). Feature 2 added four new fields above the `permissions` match block, shifting it from line 28 to line 32; the permissions logic itself is unchanged. Still open. Re-examined 2026-09-25 by /audit independent (Feature 4 review; scope: current; lens: security). Feature 4's `RoleRoute requireModule` and the `AdminLayout` Services link now trust `/me.permissions`, so a disabled admin with a live session would see the Services nav link and page shell. The new backend `module:services` middleware uses `hasModulePermission()`, and `EnsureRole` still returns 403, so no service data or write is exposed. Severity unchanged. Still open. Re-examined 2026-09-25 by /audit independent (Feature 5 review; scope: current; lens: security). Feature 5's `/admin/inventory` `RoleRoute requireModule="inventory"` and the `AdminLayout` Inventory link also trust `/me.permissions`. The backend `module:inventory` middleware and `EnsureRole`'s `is_active` check still return 403, so no inventory data or stock change is exposed. Severity unchanged. Still open.

### F-14 [P3] open - Coding standards still name `php artisan test` as the backend test command

**File:** blueprint/context/coding-standards.md:99
**Found:** 2026-09-25 by /audit independent (scope: current; lens: quality)
**Why it matters:** `f04c249` changed the backend Test command in `AGENTS.md` to `composer test`. It warns against running `php artisan test` directly because only the composer script's `config:clear` stops a cached config from bypassing `phpunit.xml`'s forced test-database overrides. `coding-standards.md:99` calls `php artisan test` the backend test runner, and its Stack binding at `:144` repeats it. Agents read that file before changing code. No config cache exists today, so the risk is not reachable now. This is documentation drift, not a code defect.
**Suggested fix:** Replace `php artisan test` with `composer test` at `coding-standards.md:99` and `:144`, or point those lines to the `AGENTS.md` command. Code changes: None. No current requirement is lost.
**Resolution:** Re-examined 2026-09-25 by /audit independent (Feature 2 review; scope: current; lens: quality). Feature 2 replaced `current-feature.md` with a fresh spec that no longer contains the stale `php artisan test` line the original finding cited there (that pointer is now moot). `coding-standards.md:99` and `:144` are untouched by Feature 2 and still name `php artisan test`, so the finding stands for those two locations. Still open.

### F-16 [P3] open - Login's generic 422 message is now inaccurate for every remaining trigger case

**File:** frontend/src/features/auth/LoginPage.tsx:44
**Found:** 2026-09-25 by /audit independent (scope: current; lens: quality)
**Why it matters:** `setError('phone', { message: 'We could not find an account with that number.' })` fires whenever `POST /api/v1/login` returns 422. Before Feature 2, that happened for a genuinely unregistered phone (message was true) and, more rarely, for an admin phone or a disabled customer (message was already slightly off). After Feature 2, an unrecognized phone number auto-creates an account and never reaches this branch, so the 422 branch is now reached only for an admin's phone or a disabled customer's phone - cases where an account does exist. A disabled customer trying to log back in is now always told "we could not find an account with that number," which is false and could mislead them into thinking they mistyped their number rather than that their account was disabled.
**Suggested fix:** Change the copy to something accurate for the remaining cases, for example "We couldn't log you in with that number," or branch on server error detail if one becomes available.
**Resolution:**

### F-17 [P3] open - Blank optional middle name is submitted as an empty string, not null/omitted

**File:** frontend/src/features/auth/ProfileSetupPage.tsx:15,69
**Found:** 2026-09-25 by /audit independent (scope: current; lens: quality/tests)
**Why it matters:** `middle_name: z.string().optional()` combined with an uncontrolled `<Input id="middle_name" {...register('middle_name')} />` and no `defaultValues` means that when a real user leaves the field blank, React Hook Form reads the DOM value as `''`, not `undefined`. `completeProfile()` then POSTs `middle_name: ''`, which `CompleteProfileRequest`'s `'middle_name' => ['nullable', 'string', 'max:255']` rule accepts as a valid string (not null), so `$user->update(...)` stores `''` rather than `null`. The data model and `UserResource` treat `middle_name` as nullable, and the backend test (`test_profile_completion_accepts_omitted_middle_name`) only exercises the key being fully omitted from the request body, so this real-UI path (empty string, not omission) is untested and produces a different stored value (`''`) than the tested path (`null`). Low practical impact since both are falsy for display purposes, but it is a genuine, reachable data-model deviation traceable directly to this diff's new form.
**Suggested fix:** Either normalize `''` to `undefined`/omit the key before submitting (e.g. in `onSubmit`, `middle_name: values.middle_name || undefined`), or have the backend coerce an empty string to `null` for this nullable column.
**Resolution:**

### F-18 [P3] unverified - Customer login's auto-create branch may be a timing side channel for phone-number enumeration

**File:** backend/app/Http/Controllers/Api/Customer/AuthController.php:19-30
**Found:** 2026-09-25 by /audit independent (scope: current; lens: security)
**Why it matters:** The spec deliberately makes an existing-login and a new-account-creation return the identical `200` + `UserResource` shape "so creation and existing-login look identical to the client." At the database level, though, the existing-account branch only does a `SELECT` (`User::where('phone', $phone)->first()`), while the new-account branch does that same `SELECT` plus an `INSERT` (`User::create(...)`) plus a `refresh()` (another `SELECT`). The extra write work on the creation path is a plausible timing side channel that could let an attacker distinguish "this phone is already a customer" from "this phone was just created" by measuring response latency, undermining the stated identical-response goal. Not measured in this review, and the shared `throttle:6,1` on `/api/v1/login` (see F-04) limits how much an attacker can sample. This sits in the same class as F-08's admin-login timing observation.
**Suggested fix:** If closing this matters before v2, consider adding constant-ish work to the read path (e.g. an equivalent dummy query) or accept it as part of the v1 trust model already documented in the spec's Out of scope (the accepted create-create race) and Open Questions (no verification in v1). No code change is required to ship this feature; recording as unverified so it can be measured or explicitly accepted later.
**Resolution:**

### F-19 [P3] open - Out-of-range service input and non-numeric ids return 500 instead of 422/404

**File:** backend/app/Http/Requests/Admin/StoreServiceRequest.php:29,32-33; backend/app/Http/Controllers/Api/Admin/ServiceController.php:33,41
**Found:** 2026-09-25 by /audit independent (scope: current; lens: quality/security)
**Why it matters:** `roasting_rate_per_kg` and `shop_price` are validated only as `numeric|min:0`, but the columns are `decimal(10,2)` (max 99,999,999.99). `est_minutes` is `integer|min:1` against a Postgres `integer` column (max 2,147,483,647). Larger values pass validation and fail at the database with a numeric overflow, so the admin gets a generic 500 instead of a field error. Separately, `update(int $id)` and `toggle(int $id)` receive the raw route segment; a non-numeric id (for example `/admin/services/abc/toggle`) raises a PHP `TypeError` (confirmed with `php -r` coercion check, no `declare(strict_types)` needed) and returns 500, where the spec contract says `404` for a missing service. Reachable only by an authorized admin, so not a security break. The `int $id` pattern already exists in `AdminAccountController::update`.
**Suggested fix:** Add `max:99999999.99` to both money rules and a sane `max` (for example `max:10080`) to `est_minutes`. Constrain the routes with `->whereNumber('id')` so a non-numeric id is a 404. Current requirement lost: None.
**Resolution:**

### F-20 [P3] open - Admin services list fails silently when the query errors

**File:** frontend/src/features/services/ServicesPage.tsx:132
**Found:** 2026-09-25 by /audit independent (scope: current; lens: quality)
**Why it matters:** `useServices()` reads only `data` and `isLoading`. On a 403 (for example a disabled admin whose `/me` still lists permissions, see F-11), a 5xx, or a network failure, the page shows the heading and the create form with no table and no message. The coding standards say to surface errors rather than fail silently. This repeats the F-10 pattern in a new page.
**Suggested fix:** Destructure `isError`/`error` and render an inline `getGenericErrorMessage(error)` when the query fails. Current requirement lost: None.
**Resolution:** Re-examined 2026-09-25 by /audit independent (Feature 5 review; scope: current; lens: quality). Feature 5 added the threshold field above the query, shifting `useServices()` from line 128 to line 132; the silent-failure path is unchanged. Still open.

### F-21 [P3] open - Service tests skip the update-path normalization, 404s, and permitted-admin update/toggle

**File:** backend/tests/Feature/Admin/ServiceManagementTest.php:95
**Found:** 2026-09-25 by /audit independent (scope: current; lens: tests)
**Why it matters:** The spec says the non-applicable rate/price is always stored as `null` on both `store()` and `update()`, and that `PUT` and `toggle` return `404` for a missing id. Only the create-path normalization is tested. No test switches an existing service from customer-supplied to shop-supplied via `PUT` and asserts the old rate is cleared, and no test covers a missing id. `test_admin_with_services_permission_can_manage_services` covers only create and list, although the spec's Step 2 says a permitted admin "can do the same" as a super admin. The code paths look correct on reading, so this is a coverage gap, not a known defect.
**Suggested fix:** Add a test that updates a customer-supplied service to shop-supplied-only and asserts `roasting_rate_per_kg` is `null` in the response and database. Add `404` assertions for `PUT` and `PATCH .../toggle` on a missing id. Extend the permitted-admin test to cover update and toggle. Current requirement lost: None.
**Resolution:**

### F-23 [P3] open - Unbounded stock quantities and non-numeric service ids return 500

**File:** backend/app/Http/Controllers/Api/Admin/InventoryController.php:25,36,63
**Found:** 2026-09-25 by /audit independent (scope: current; lens: quality/security)
**Why it matters:** `qty` (`StoreRestockRequest.php:26`) and `change_qty` (`StoreAdjustRequest.php:26`) have no upper bound, while `services.stock_qty` is a Postgres `integer`. A restock or adjust whose result exceeds 2,147,483,647 fails at the database with a numeric overflow and returns a generic 500 instead of a 422. The transaction rolls back, so no data is corrupted. `restock(..., int $service)` and `adjust(..., int $service)` also receive the raw route segment, so `/admin/inventory/abc/restock` raises a `TypeError` and returns 500, where the spec contract says 404. This repeats the F-19 pattern. Reachable only by an authorized admin, so it is not a security break.
**Suggested fix:** Add a sane `max` to both quantity rules (for example `max:100000` and `between:-100000,100000`), and constrain the two routes with `->whereNumber('service')`. Current requirement lost: None.
**Resolution:** Re-examined 2026-09-25 by /audit independent (re-review of `54d781e`; scope: current; all lenses). `InventoryController.php:25,36` still take `int $service` with no route constraint (`routes/api.php:412-413`), and `StoreRestockRequest.php:26`/`StoreAdjustRequest.php:26` still have no upper bound. `applyChange` now starts at line 62. Still open.

### F-24 [P3] open - Inventory page and log table fail silently when their queries error

**File:** frontend/src/features/inventory/InventoryPage.tsx:94,154
**Found:** 2026-09-25 by /audit independent (scope: current; lens: quality)
**Why it matters:** `useInventory()` and `useInventoryLogs(page)` read only `data` and `isLoading`. On a 403 (for example a disabled admin whose `/me` still lists permissions, see F-11), a 5xx, or a network failure, the page shows headings with no table and no message. The coding standards say to surface errors rather than fail silently. This is the same pattern as F-10 and F-20, on a new page. The row mutations do surface errors correctly.
**Suggested fix:** Destructure `isError`/`error` from both queries and render an inline `getGenericErrorMessage(error)` when a query fails. Current requirement lost: None.
**Resolution:** Re-examined 2026-09-25 by /audit independent (re-review of `54d781e`; scope: current; all lenses). `InventoryPage.tsx:94` and `:154` still read only `data`/`isLoading`. Still open.

### F-27 [P3] open - Inventory adjust-below-zero rejection shows a generic "try again" message

**File:** frontend/src/features/inventory/InventoryPage.tsx:44
**Found:** 2026-09-25 by /audit independent (scope: current; lens: quality)
**Why it matters:** The spec's one expected business rejection on this page is the 422 when an adjust would take stock below zero. The backend returns a field message, "This change would take stock below zero." (`InventoryController.php:72-74`). The row mutation's `onError` passes every error to `getGenericErrorMessage()`, which has no 422 branch (`frontend/src/lib/errors.ts`), so the admin sees "Something went wrong. Please try again." That message is misleading: retrying fails the same way, and it hides why. Every other form in the app branches on `status === 422` to show the server's field message (`ServicesPage.tsx:171`, `AdminAccountsPage.tsx:102`, and the auth pages), so this also drifts from the local pattern. The backend guard still holds, so no data is affected.
**Suggested fix:** In `onAdjust`'s (and `onRestock`'s) `onError`, when `isAxiosError(error) && error.response?.status === 422`, show the first message from `error.response.data.errors` (for example `change_qty[0]`), otherwise fall back to `getGenericErrorMessage(error)`. Current requirement lost: None.
**Resolution:**

### F-28 [P3] open - low_stock_threshold has no upper bound, so large values return 500

**File:** backend/app/Http/Requests/Admin/StoreServiceRequest.php:34
**Found:** 2026-09-25 by /audit independent (scope: current; lens: quality/security)
**Why it matters:** The F-22 repair added `'low_stock_threshold' => ['required', 'integer', 'min:0']` with no `max`. The column is a Postgres `integer` (`unsignedInteger` maps to signed `integer` on Postgres; max 2,147,483,647). A value such as 3000000000 passes Laravel's `integer` rule on 64-bit PHP and then fails at the database with a numeric overflow, so the admin gets a generic 500 instead of a field error. The frontend zod schema (`ServicesPage.tsx:23`) also has no max. This is the F-19 and F-23 pattern on a new field. Reachable only by an authorized admin, so it is not a security break, and nothing is written.
**Suggested fix:** Add a sane `max` (for example `max:1000000`) to the rule, and optionally mirror it in the zod schema. Current requirement lost: None.
**Resolution:**

### F-34 [P3] open - Booking form's services query fails silently

**File:** frontend/src/features/bookings/NewBookingPage.tsx:68
**Found:** 2026-09-25 by /audit independent (scope: current; lens: quality)
**Why it matters:** `useBookableServices()` reads only `data` and `isLoading`. If `GET /api/v1/services` fails (a 5xx or a network error), the page renders an item dropdown with only "Select an item". There is no message, so the customer cannot book and is not told why. The coding standards say to surface errors rather than fail silently. This is the F-10/F-20/F-24 pattern on the first customer-facing form. The submit mutation surfaces errors correctly.
**Suggested fix:** Destructure `isError`/`error` from `useBookableServices()` and render an inline `getGenericErrorMessage(error)` in the Items section when the query fails. Current requirement lost: None.
**Resolution:**

### F-36 [P3] open - Shop order form's services query fails silently

**File:** frontend/src/features/orders/NewShopOrderPage.tsx:66
**Found:** 2026-09-25 by /audit independent (scope: current; lens: quality)
**Why it matters:** `useShoppableServices()` reads only `data` and `isLoading`. If `GET /api/v1/services` fails, the item dropdown shows only "Select an item" with no message, so the customer cannot order and is not told why. The coding standards say to surface errors rather than fail silently. This repeats F-34 on the new feature's form.
**Suggested fix:** Destructure `isError`/`error` from `useShoppableServices()` and render an inline `getGenericErrorMessage(error)` in the Items section when the query fails. Current requirement lost: None.
**Resolution:**

### F-37 [P3] open - No test covers duplicate-service lines reaching the order controller

**File:** backend/tests/Feature/Customer/ShopOrderManagementTest.php:209
**Found:** 2026-09-25 by /audit independent (scope: current; lens: tests)
**Why it matters:** The F-35 repair changed `OrderController::store` to decrement one shared in-memory `Service` per id and save each row once after the loop. The correctness of repeated lines (for example B, A, B) now depends on that shared instance. The only test with repeated `service_id`s is `test_more_than_twenty_items_is_rejected`, which fails Form Request validation (`max:20`) before the controller runs. No repo test proves that a second line sees the first line's decrement, or that duplicate lines together exceeding stock roll back the whole order. The F-35 note says existing tests covered this, but they do not. A reviewer probe confirmed the current behavior is correct, so this is a coverage gap, not a defect.
**Suggested fix:** Add two feature tests: a successful order with lines B, A, B (assert both final `stock_qty` values, three `reserve` logs, and the total), and a duplicate-line order that exceeds stock (assert 422 on the later line's `qty` and no stock, log, or booking writes). Current requirement lost: None.
**Resolution:**

### F-41 [P3] open - My Bookings list and detail do not surface query errors

**File:** frontend/src/features/bookings/MyBookingsPage.tsx:7; frontend/src/features/bookings/BookingDetailPage.tsx:13-23
**Found:** 2026-09-25 by /audit independent (scope: current; lens: quality)
**Why it matters:** Both pages read only `data`/`isLoading`. If `GET /bookings` fails, the list page shows only its heading with no message. On the detail page, a 5xx or network failure ends in "Booking not found.", which is misleading. The shared `QueryClient` also uses the default of 3 retries (`lib/queryClient.ts`), so a real 404 shows "Loading…" for several seconds before that message. The coding standards say to surface errors rather than fail silently. This repeats F-34/F-36 on the new pages.
**Suggested fix:** Destructure `isError`/`error` and render `getGenericErrorMessage(error)`. Show "Booking not found." only for an Axios 404, and consider `retry: false` for 404s on the detail query. Current requirement lost: None.
**Resolution:**

### F-44 [P3] open - Admin booking queue and detail pages do not surface query errors

**File:** frontend/src/features/admin-bookings/AdminBookingsPage.tsx:15-16; frontend/src/features/admin-bookings/AdminBookingDetailPage.tsx:8-15
**Found:** 2026-09-25 by /audit independent (scope: current; lens: quality)
**Why it matters:** The pages read only `data`/`isLoading`. If the counts query fails, every tab shows `(0)`, which looks like an empty shop. If the list query fails, the page shows no table and no message. On the detail page, a 403, 5xx, or network failure shows "Booking not found." The coding standards say to surface errors rather than fail silently. This repeats F-41 on the new admin pages.
**Suggested fix:** Destructure `isError`/`error` from each hook and render `getGenericErrorMessage(error)`. Show "Booking not found." only for an Axios 404. Current requirement lost: None.
**Resolution:** Re-examined 2026-09-25 by /audit independent (re-review of `d261bb0`; scope: current; all lenses). `d261bb0` did not touch the frontend; `AdminBookingsPage.tsx:15-16` and `AdminBookingDetailPage.tsx:8-15` still read only `data`/`isLoading`. Also, a non-numeric URL such as `/admin/bookings/abc` becomes `Number('abc')` = `NaN`, requests `/admin/bookings/NaN`, retries the 404 with the default policy, then shows "Booking not found."; the same repair covers it. Still open. Re-examined 2026-09-25 by /audit independent (Feature 11 review of `0ce0a5a`; scope: current; all lenses). Feature 11 added `ConfirmRejectOrderActions` and grew the hooks import, so the detail query now sits at `AdminBookingDetailPage.tsx:196`; it still reads only `data`/`isLoading`. The new order actions themselves surface mutation errors correctly. Still open. Re-examined 2026-09-25 by /audit independent (Feature 12 review of `fddfa20`; scope: current; all lenses). Feature 12 added five fulfillment action components; each surfaces its mutation error through `getActionErrorMessage`. The detail query moved to `AdminBookingDetailPage.tsx:329` and still reads only `data`/`isLoading`. Still open. Re-examined 2026-09-25 by /audit independent (Feature 13 review of `28ddbc2`; scope: current; all lenses). Feature 13 changed only the `AdminBookingsPage.tsx` heading, adding the "New walk-in" link. `useBookingCounts()` and `useAdminBookings()` at `AdminBookingsPage.tsx:14-15` still read only `data`/`isLoading`. Still open.

### F-46 [P3] open - No test covers the guest-contact fallback or the waiting-time created_at fallback

**File:** backend/tests/Feature/Admin/AdminBookingQueuesTest.php:66; backend/app/Http/Resources/AdminBookingResource.php:28-31,36
**Found:** 2026-09-25 by /audit independent (scope: current; lens: tests)
**Why it matters:** The spec's Notes for the AI require `customer_name`/`customer_phone` to fall back to `guest_name`/`guest_phone` now, before feature 13 creates guest bookings, and the spec lists guest name and guest phone among the `search` fields. No test creates a booking with `customer_id` null and guest fields, so the resource fallback and the `guest_name`/`guest_phone` search clauses (`BookingController.php:53-54`) are unexercised. Every test booking with a status log also has one, so `waiting_minutes`' fallback to `created_at` is untested. `test_counts_reflect_seeded_bookings_per_status` uses `assertJson` (a subset match) and seeds no terminal-status bookings, so it would not catch a `completed` key or count leaking into the response. The code reads correctly, so this is a coverage gap, not a known defect; feature 13 will rely on these paths.
**Suggested fix:** Add a guest booking (`customer_id` null, `guest_name`/`guest_phone` set) and assert its `customer_name`/`customer_phone` in the list and that `search` matches by guest name and by guest phone. Add a booking with no status log and assert `waiting_minutes` reflects `created_at`. In the counts test, seed one `completed` booking and use `assertExactJson`. Current requirement lost: None.
**Resolution:** Re-examined 2026-09-25 by /audit independent (Feature 12 review of `fddfa20`; scope: current; all lenses). Feature 12 added three timestamp fields to `AdminBookingResource` above `waiting_minutes`, which is now at line 46. Feature 12 makes `completed` bookings reachable, so the counts test's subset match is now more relevant. The fallbacks are still untested. Still open. Re-examined 2026-09-25 by /audit independent (Feature 13 review of `28ddbc2`; scope: current; all lenses). Feature 13 now creates real guest bookings. `AdminWalkInBookingTest.php:85-103` and `:154-172` check `guest_name`/`guest_phone` only on the database row. They never check the response's `customer_name`/`customer_phone` fallback or the queue search by guest name or phone. Still open.

### F-51 [P3] open - An approve `dropoff_at` with a non-UTC offset is stored shifted by that offset

**File:** backend/app/Http/Controllers/Api/Admin/BookingController.php:92
**Found:** 2026-09-25 by /audit independent (scope: current; lens: quality)
**Why it matters:** `approve` assigns the raw validated string to the `datetime`-cast `dropoff_at`. The column is `timestamp` (no zone), and the app timezone is `UTC`. Eloquent's `asDateTime` parses an offset string with `Date::parse`, keeps its offset, and `fromDateTime` formats it as `Y-m-d H:i:s` without converting to UTC. So the spec's own contract example, `"2026-10-01T10:00:00+08:00"`, passes `after:now` (which compares correctly) but is stored as `10:00` UTC, 8 hours later than intended. The shipped SPA sends `toISOString()` (a `Z` value), so the UI path is correct and only a direct API caller using an offset is affected. `Customer\BookingController::store` already does the same with `preferred_dropoff_at`, so this repeats an existing pattern and is not a regression.
**Suggested fix:** Normalize before assigning, for example `Carbon::parse($request->validated('dropoff_at'))->utc()`, and do the same for `preferred_dropoff_at` in `Customer\BookingController::store`. Add a test that posts an offset value and asserts the stored UTC instant. Current requirement lost: None.
**Resolution:** Re-examined 2026-09-25 by /audit independent (Feature 12 review of `fddfa20`; scope: current; all lenses). Feature 12 did not change `approve`. The assignment is at `BookingController.php:94` and still stores the raw validated string. The new timestamps (`cooking_started_at`, `est_ready_at`, `completed_at`) come from `now()`, not from client input, so they are not affected. Still open.

### F-52 [P2] open - reject-order releases stock in unsorted item order, unlike the existing cancel release

**File:** backend/app/Http/Controllers/Api/Admin/BookingController.php:201
**Found:** 2026-09-25 by /audit independent (scope: current; lens: performance/quality)
**Why it matters:** `rejectOrder` runs `foreach ($booking->items as $item)` with one `increment('stock_qty')` per row. `Booking::items()` has no `orderBy`, and `OrderController::store` creates items in request order, so a two-service order placed as B then A is released B, then A. Each `UPDATE` takes a row lock held until commit. `OrderController::store` locks the same `services` rows in ascending id order (`orderBy('id')->lockForUpdate()`, commented as its deadlock guard). A rejection that locks B and then waits on A can interleave with a new order that holds A and waits on B. Postgres then aborts one transaction with a deadlock error. `DB::transaction` runs one attempt, so the admin or customer gets a 500. The rollback keeps data intact, but the request fails. The same codebase already solved this: `Customer\BookingController::cancel` (lines 53-67) releases with `$booking->items->sortBy('service_id')` and a comment explaining that it matches the reservation lock order. The spec's note that atomic `increment()` removes the need for lock ordering is incorrect, because each increment still holds its row lock. The release loop is now also duplicated between cancel and reject-order. The interleaving was found by reading the code and not reproduced, and it needs concurrent traffic on shared services.
**Suggested fix:** Iterate `$booking->items->sortBy('service_id')` in `rejectOrder`, matching `cancel`. Optionally, move the shared release loop (increment plus `release` log) into one helper that both call. Current requirement lost: None.
**Resolution:** Re-examined 2026-09-25 by /audit independent (Feature 12 review of `fddfa20`; scope: current; all lenses). `rejectOrder` is unchanged: `BookingController.php:201` still iterates `$booking->items` unsorted. The new admin `cancel` release loop correctly uses `sortBy('service_id')`, matching the customer cancel. So the admin controller now has two release loops with different lock orders. The same release loop is now copied three times (customer cancel, admin reject-order, admin cancel), which makes the optional shared helper more worthwhile. Still open.

### F-53 [P3] open - Order-confirmation tests do not prove that confirm and failed rejects leave inventory logs untouched

**File:** backend/tests/Feature/Admin/AdminOrderConfirmationTest.php:55,158
**Found:** 2026-09-25 by /audit independent (scope: current; lens: tests)
**Why it matters:** The spec says a confirmed order leaves service stock unchanged. `test_confirm_succeeds_from_pending_confirmation` creates a booking with no `booking_items`, so a confirm that released stock by mistake would still pass. The two guarded reject-failure tests (`:158` and the customer-supplied case) check that `stock_qty` did not change, but they do not check for stray `inventory_logs` rows. The current code guards before its loop, so it behaves correctly. This is a coverage gap for a later refactor of the stock path, not a defect.
**Suggested fix:** Give the confirm test one item on a service with a known `stock_qty`, then assert that the stock is unchanged and that no `inventory_logs` row exists for the booking. In the failure tests, add `assertDatabaseMissing('inventory_logs', ['booking_id' => $booking->id])`. Current requirement lost: None.
**Resolution:**

### F-54 [P3] open - Fulfillment tests leave the shop-order cancel guard, confirmed-order release, and cancel remarks unexercised

**File:** backend/tests/Feature/Admin/AdminBookingFulfillmentTest.php:107,279,309,326
**Found:** 2026-09-25 by /audit independent (scope: current; lens: tests)
**Why it matters:** The spec asks for disallowed-transition tests "for both source types where a case applies". The spec also says cancel is `422` from `cooking` onward. `test_cancel_from_a_disallowed_status_is_rejected` (`:309`) uses only customer-supplied bookings with no items. So nothing proves that a shop order in `cooking`/`ready`/`out_for_delivery`/`completed` is refused *and* keeps its stock. That is the case where a guard-after-release regression would lose inventory. The release test (`:326`) cancels only from `pending_confirmation`. The shop `confirmed -> cancelled` path, which the spec's manual check names, is covered only for status (`:295`, no items). Cancel sends `remarks` (`:286`) but never asserts them on the status log, although the contract says remarks are recorded. `no-show`/`cancel` `remarks` `max:255` validation is untested. `start-cooking` rejection is checked only from `approved` (`:107`), not from a shop status. The current code guards before its release loop and passes remarks through, so it behaves correctly. This is a coverage gap, not a defect.
**Suggested fix:** Add a shop-order cancel-rejection case from `cooking` (and optionally `completed`) with one item. Assert `422`, unchanged `stock_qty`, and no `inventory_logs` row for the booking. Run the release test from `confirmed` as well, or change it to use `confirmed`. Assert the cancel log's `remarks`. Add one 256-character `remarks` case per endpoint. Current requirement lost: None.
**Resolution:**

### F-55 [P3] open - New fulfillment test file uses section-divider comments the standards forbid

**File:** backend/tests/Feature/Admin/AdminBookingFulfillmentTest.php:60,122,184,231,277,390
**Found:** 2026-09-25 by /audit independent (scope: current; lens: quality)
**Why it matters:** `coding-standards.md` (Comments) says: "No banner/header blocks, section dividers". The new file adds six `// --- start-cooking ---...`-style dividers. No other backend test file uses them, so this is drift from the local pattern. The descriptive test method names already group the cases.
**Suggested fix:** Delete the six divider comment lines. Current requirement lost: None.
**Resolution:**

### F-56 [P3] open - Walk-in form number checks show the wrong message past the upper bound and skip decimal and integer rules

**File:** frontend/src/features/admin-bookings/WalkInBookingPage.tsx:58-72
**Found:** 2026-09-25 by /audit independent (scope: current; lens: quality)
**Why it matters:** The `superRefine` rejects `final_weight_kg > 1000` with "Weight must be greater than 0" and `qty > 1000` with "Quantity must be at least 1". Both messages are false for the case that triggers them. The client also does not check the backend's `decimal:0,2` rule on weight (`StoreWalkInRoastingRequest.php:44`) or the `integer` rule on qty (`StoreWalkInShopOrderRequest.php:44`). A value such as `2.555` kg or `1.5` qty passes the form, and the server's 422 appears only as one form-level message with no item named. `NewShopOrderPage.tsx:17` already uses separate `.int(...)`, `.min(...)`, and `.max(1000, 'Quantity seems too high')` messages, so this form drifts from the local pattern. The backend still rejects the bad values, so no bad data is stored.
**Suggested fix:** Give the upper bound its own message (for example "Weight seems too high" / "Quantity seems too high"). Add a whole-number check for `qty` and a two-decimal check for `final_weight_kg`, matching the backend rules. Current requirement lost: None.
**Resolution:**

### F-57 [P3] open - Walk-in customer search and services queries fail silently, and a search with no matches shows nothing

**File:** frontend/src/features/admin-bookings/WalkInBookingPage.tsx:96-97,103,278
**Found:** 2026-09-25 by /audit independent (scope: current; lens: quality)
**Why it matters:** `useBookableServices()`, `useShoppableServices()`, and `useSearchWalkInCustomers()` read only `data` (and `isFetching`). If `GET /services` fails, the item dropdown shows only "Select an item" with no message. If the customer search fails (a 403, 5xx, or network error), or returns an empty array, the result list at line 278 renders nothing. The admin cannot tell "no customer with that name or phone" from "the search broke", so they may wrongly record a registered customer as a guest. The coding standards say to surface errors rather than fail silently. This repeats the F-34/F-36/F-44 pattern on the new page.
**Suggested fix:** Destructure `isError`/`error` from the three queries and render `getGenericErrorMessage(error)` inline. Show a short "No customers found" line when the search has finished with an empty result. Current requirement lost: None.
**Resolution:**

### F-58 [P3] open - Walk-in guest phone is stored as typed, not normalized like every customer phone

**File:** backend/app/Http/Requests/Admin/StoreWalkInRoastingRequest.php:35; backend/app/Http/Requests/Admin/StoreWalkInShopOrderRequest.php:35
**Found:** 2026-09-25 by /audit independent (scope: current; lens: quality)
**Why it matters:** `guest_phone` accepts any string up to 32 characters and is saved as typed. Customer phones go through `App\Support\PhoneNumber::normalize()` and `regex:/^09\d{9}$/` (`Customer/LoginRequest.php:25,38`), so every registered phone is stored as `09XXXXXXXXX`. A counter entry of `+639171234567` or `0917 123 4567` is stored in that form. The admin queue search (`Admin/BookingController.php` `guest_phone ilike`) then misses it when the admin types the canonical `0917...` form, and a typo such as `0917` is accepted. The spec sets the `string|max:32` rule, so this matches the spec. It is a data-consistency drift, not a spec violation.
**Suggested fix:** In both walk-in requests, add a `prepareForValidation()` that runs `PhoneNumber::normalize()` on `guest_phone`, as `Customer\LoginRequest` does, and validate it with the same `regex:/^09\d{9}$/`. Add one test with a `+63` value. Current requirement lost: None. It changes the spec'd accepted input, so confirm with the user first.
**Resolution:**

### F-59 [P3] open - Walk-in tests skip status-log order, roasting item rows, and duplicate shop lines

**File:** backend/tests/Feature/Admin/AdminWalkInBookingTest.php:79-82,105-151
**Found:** 2026-09-25 by /audit independent (scope: current; lens: tests)
**Why it matters:** The spec asks for "exactly three `booking_status_logs` rows in order `pending_review`, `approved`, `confirmed`". Lines 79-82 use `assertDatabaseHas` plus a count, which proves the rows exist but not their order. The `pending_review` row's `changed_by` is not checked. No test checks the roasting `booking_items` row (`final_weight_kg`, `est_weight_kg`, `rate`, `subtotal`). A regression that stored only the booking total would pass. `storeShop` copies `OrderController::store`'s shared-instance decrement for repeated `service_id` lines, and no walk-in test sends duplicate lines (the same gap as F-37). The code reads correctly and all 227 tests pass, so this is a coverage gap, not a known defect.
**Suggested fix:** Assert `$booking->statusLogs()->orderBy('id')->pluck('status')->all() === ['pending_review', 'approved', 'confirmed']`. Assert the roasting item row's weights, rate, and subtotal. Add one shop walk-in with lines A, B, A: check the final stock, three `reserve` logs, and the total. Add one duplicate-line case that exceeds stock and rolls back. Current requirement lost: None.
**Resolution:**
