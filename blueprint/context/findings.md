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
**Resolution:** Re-examined 2026-09-25 by /audit independent (Feature 2 review; scope: current; lens: security). Feature 2 removed `POST /api/v1/register` entirely, so the shared bucket now covers only admin login and customer login (previously also customer register). The core finding still holds for those two routes; line numbers updated to match the current file. Still open.

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
