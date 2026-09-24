# Findings

> **Generated file.** The findings ledger: review findings raised by `/audit`
> against the work in progress, each with a durable ID, severity (P0-P3), and
> status. `/implement` marks repaired findings `fixed`, a later `/audit` pass
> moves them to `closed`, and `/complete` refuses to merge while any P0 or P1
> finding is `open` or `fixed`, then archives resolved findings with the work
> and resets this file.

### F-04 [P3] open - All throttle:6,1 auth routes share one per-IP rate-limit bucket

**File:** backend/routes/api.php:11
**Found:** 2026-09-24 by /audit independent (scope: current; lens: security)
**Why it matters:** Unnamed `throttle:6,1` keys guests only by `domain|ip` (`ThrottleRequests::resolveRequestSignature`), with no route in the key. Admin login (line 11), customer register (21), and customer login (22) all count against the same 6/minute budget. A user who mistypes their phone a few times can then get blocked from registering, and users behind a shared NAT share one budget. The limit still caps brute force, so this is not a security gap.
**Suggested fix:** Define named limiters in `AppServiceProvider` with `RateLimiter::for(...)`, for example keyed by IP plus route or plus the submitted phone or email, and use `throttle:admin-login` and similar on each route.
**Resolution:**

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
**Why it matters:** `useAdminAccounts()` reads only `data` and `isLoading`. On a 5xx or network error the page shows the heading and the create form with no table and no message. The logout buttons in `components/layouts/AdminLayout.tsx:23` and `features/auth/AccountPage.tsx:14` pass no `onError`, so a failed logout (for example a 419 after the session expired) just re-enables the button. The coding standards say to surface errors rather than fail silently. F-01 fixed the create form, row actions, and login forms, but not these paths.
**Suggested fix:** Render an inline `getGenericErrorMessage(error)` when the accounts query has `isError`, and add an `onError` inline message to both logout mutations.
**Resolution:**

### F-11 [P3] open - /me permissions ignore is_active, drifting from hasModulePermission

**File:** backend/app/Http/Resources/UserResource.php:28
**Found:** 2026-09-24 by /audit independent (scope: current; lens: security)
**Why it matters:** `UserResource` rebuilds the permission list from `role` alone (all modules for `super_admin`, granted rows for `admin`). After the F-06 repair, `hasModulePermission()` returns false for a disabled user, but `GET /api/v1/me` (guarded only by `auth:sanctum`) still returns 200 with the full permission list for a disabled admin with a live session. `RoleRoute` then admits them to the admin shell. Backend admin routes still 403 through `EnsureRole`, so no data is exposed today. The risk is that later module UIs will trust `/me.permissions`, and the permission rule now lives in two places.
**Suggested fix:** Derive the list from the primitive, for example `array_values(array_filter(User::MODULES, fn ($m) => $this->hasModulePermission($m)))`, or return `[]` when `! is_active`. Optionally add a `/me` test for a disabled admin.
**Resolution:**

### F-14 [P3] open - Coding standards and spec still name `php artisan test` as the backend test command

**File:** blueprint/context/coding-standards.md:99
**Found:** 2026-09-25 by /audit independent (scope: current; lens: quality)
**Why it matters:** `f04c249` changed the backend Test command in `AGENTS.md` to `composer test`. It warns against running `php artisan test` directly because only the composer script's `config:clear` stops a cached config from bypassing `phpunit.xml`'s forced test-database overrides. Two other docs still name `php artisan test`. `coding-standards.md:99` calls it the backend test runner, and its Stack binding at `:144` repeats it. Agents read that file before changing code. The spec's Testing section (`current-feature.md:320`) also still says "`php artisan test` passes today". An agent following those lines on a machine where `php artisan config:cache` was run would get the dev database from the cached config, and `RefreshDatabase` would then wipe it. No config cache exists today, so the risk is not reachable now. This is documentation drift, not a code defect.
**Suggested fix:** Replace `php artisan test` with `composer test` at `coding-standards.md:99` and `:144` and `current-feature.md:320`, or point those lines to the `AGENTS.md` command. Code changes: None. No current requirement is lost.
**Resolution:**
