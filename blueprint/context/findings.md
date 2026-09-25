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
**Resolution:** Re-examined 2026-09-25 by /audit independent (Feature 2 review; scope: current; lens: security). Feature 2 added four new fields above the `permissions` match block, shifting it from line 28 to line 32; the permissions logic itself is unchanged. Still open.

### F-14 [P3] open - Coding standards still name `php artisan test` as the backend test command

**File:** blueprint/context/coding-standards.md:99
**Found:** 2026-09-25 by /audit independent (scope: current; lens: quality)
**Why it matters:** `f04c249` changed the backend Test command in `AGENTS.md` to `composer test`. It warns against running `php artisan test` directly because only the composer script's `config:clear` stops a cached config from bypassing `phpunit.xml`'s forced test-database overrides. `coding-standards.md:99` calls `php artisan test` the backend test runner, and its Stack binding at `:144` repeats it. Agents read that file before changing code. No config cache exists today, so the risk is not reachable now. This is documentation drift, not a code defect.
**Suggested fix:** Replace `php artisan test` with `composer test` at `coding-standards.md:99` and `:144`, or point those lines to the `AGENTS.md` command. Code changes: None. No current requirement is lost.
**Resolution:** Re-examined 2026-09-25 by /audit independent (Feature 2 review; scope: current; lens: quality). Feature 2 replaced `current-feature.md` with a fresh spec that no longer contains the stale `php artisan test` line the original finding cited there (that pointer is now moot). `coding-standards.md:99` and `:144` are untouched by Feature 2 and still name `php artisan test`, so the finding stands for those two locations. Still open.

### F-15 [P2] open - Profile-completion endpoint has no guard against being re-invoked after the profile is already complete

**File:** backend/app/Http/Controllers/Api/Customer/ProfileController.php:9-14
**Found:** 2026-09-25 by /audit independent (scope: current; lens: security/quality)
**Why it matters:** The spec's Notes for the AI say `ProfileController::complete()` "is a one-time setup action, not a general profile editor," and Out of scope explicitly excludes "Editing the profile after initial setup - that's Feature 3." `ProfileController::complete()` (`$user->update($request->validated())`) and its route (`auth:sanctum` only, `backend/routes/api.php:24`) never check whether `first_name`/`last_name` are already set. The only place that enforces "one-time" is the frontend route guard (`ProfileSetupRoute.tsx`), which redirects a complete-profile customer away from `/profile-setup` in the browser. A customer who calls `POST /api/v1/profile/complete` directly (curl, devtools, a replayed request) after their profile is already complete can freely rewrite their own name and address at any time, ahead of and outside whatever contract Feature 3 is meant to define for profile edits. This does not expose another user's data (self-only), so it is not a P0/P1 authorization break, but it is a missing guard against a behavior the spec explicitly scoped out.
**Suggested fix:** In `ProfileController::complete()` (or `CompleteProfileRequest::authorize()`), reject the request (409 or 422) when `$user->first_name` and `$user->last_name` are already set, or explicitly decide this endpoint is allowed to double as an editor until Feature 3 ships and update the spec's Out-of-scope/Notes sections to match reality.
**Resolution:**

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
