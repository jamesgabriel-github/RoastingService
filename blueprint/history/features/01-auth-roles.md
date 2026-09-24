# Feature: Auth & roles

**From build-plan:** feature 1
**Build attempt:** 1
**Status:** verified
**Branch:** feature/auth-roles

## Goal

Stand up real authentication and authorization for both sides of the app:
admin (email + password, two tiers with per-module permissions) and customer
(full sign-up, mobile-number-only login in v1). This is the foundational
feature everything else depends on - Sanctum, the roles/permissions data
model, and the login surfaces all get built here.

## In scope

- Install and configure Laravel Sanctum for SPA cookie auth (backend +
  frontend on different local ports), including CORS for credentialed
  requests.
- `users` table gains `phone`, `role` (`super_admin` | `admin` | `customer`,
  default `customer`), `is_active` (default `true`).
- New `admin_permissions` table: `user_id`, `module`, `granted_by`, unique on
  (`user_id`, `module`).
- Seed exactly one `super_admin` account.
- Admin auth: separate login (email + password), logout, gated by role +
  `is_active`.
- Customer auth: registration (name, email, phone, password - full profile),
  login by mobile number only (no password check in v1), logout.
- `GET /api/v1/me` - current session's user + role + effective module
  permissions.
- Super-admin-only admin-account management: list admin accounts, create an
  admin account, enable/disable an admin account, replace an admin account's
  module permissions.
- A reusable per-module permission check (`User::hasModulePermission()`) that
  later features (Services, Inventory, Bookings, Payments, Dashboard) will
  apply to their own routes. This feature builds and unit-tests the
  primitive; no other module route exists yet to apply it to.
- Frontend: install React Router, TanStack Query, axios, React Hook Form,
  Zod (all already named in the plan's tech stack, none installed yet).
  Axios client wired for Sanctum's CSRF-cookie flow.
- Frontend pages: customer register, customer login, admin login, a minimal
  authenticated shell for each of Public/Customer/Admin, and the
  super-admin-only admin-accounts screen (list, create, enable/disable,
  per-module permission checkboxes).
- Route guards: authenticated-only and role-based redirects.
- Switch the backend's database driver from the scaffold's default SQLite to
  PostgreSQL, per the plan's Tech section.

## Out of scope

- Creating additional `super_admin` accounts through the API - v1 has exactly
  one, seeded. The admin-accounts endpoints only ever operate on `role =
  admin` rows.
- SMS one-time-code verification for customer login (v2, per the plan).
- Any actual Services/Inventory/Bookings/Payments/Dashboard pages or
  endpoints - later features. This feature only builds the permission
  primitive they'll use.
- Customer profile view/edit and password change (Feature 2).
- Visual polish beyond a functional layout - the plan's detailed UI/UX
  direction (brand colors, cards, etc.) is for later passes, not this
  foundational feature.
- Deploying or any hosting concern (still open per the plan's Deployment TODO).

## Build loop

Per `blueprint/config.json`: `stepReview: "feature"` (one review packet with
the full diff after all steps below pass, not per-step approval) and
`checkpointCommits: "disabled"` (no optional checkpoint commits along the
way). `/complete` makes the final feature commit.

## Build steps

- [x] 1. **Sanctum + CORS setup.** Run `php artisan install:api` (adds
      Sanctum, `routes/api.php`, the API middleware group). Publish/configure
      `config/cors.php` for credentialed requests (`supports_credentials:
      true`, `allowed_origins` from a new `FRONTEND_URL` env var, default
      `http://localhost:5173`). Set `SANCTUM_STATEFUL_DOMAINS=localhost:5173`
      in `.env`/`.env.example`. No new behavior yet.
      **Done when:** `php artisan route:list` shows `sanctum/csrf-cookie` and
      an `api/v1` prefix is reachable; `php artisan test` still passes.
- [x] 2. **Roles/permissions schema.** New migration adding `phone` (nullable,
      unique), `role` (enum, default `customer`), `is_active` (default
      `true`) to `users`. New migration + model `AdminPermission` (`user_id`
      -> `users`, `module` enum `services|inventory|bookings|payments|
      dashboard`, `granted_by` -> `users`, unique on `user_id`+`module`).
      Update `User` model: fillable/casts for the new columns, `permissions()`
      relationship, `hasModulePermission(string $module): bool` (true for
      `super_admin` always, checked against `admin_permissions` for `admin`,
      false for `customer`).
      **Done when:** `php artisan migrate:fresh` runs clean; a unit test
      covers `hasModulePermission()` for all three roles.
- [x] 3. **Seed the super admin.** `DatabaseSeeder` creates exactly one
      `super_admin` using `ADMIN_EMAIL`/`ADMIN_PASSWORD` env vars, falling
      back to documented local-dev defaults (`admin@example.com` /
      `password`) when unset, with the password hashed normally.
      **Done when:** `php artisan migrate:fresh --seed` produces exactly one
      `super_admin` row, queryable and login-able with those credentials.
- [x] 4. **Admin auth endpoints.** `POST /api/v1/admin/login` (email +
      password; rejects wrong credentials, non-admin roles, and
      `is_active = false` accounts, each with a generic "invalid
      credentials" message so account existence/status isn't leaked),
      `POST /api/v1/admin/logout`. Throttle login at 6 attempts/minute
      (Laravel's built-in `throttle` middleware).
      **Done when:** feature tests cover: correct login succeeds and creates
      a session; wrong password, disabled account, and customer-role account
      are all rejected the same way; logout ends the session.
- [x] 5. **Customer auth endpoints.** `POST /api/v1/register` (name, email,
      phone, password; email and phone both validated unique; phone
      validated as PH mobile - `09XXXXXXXXX` or `+639XXXXXXXXX`, normalized
      to `09XXXXXXXXX` for storage; password required and hashed even though
      login won't check it yet). `POST /api/v1/login` (phone only, normalized
      the same way, looks up an active `customer` row and starts a session -
      no password prompt). `POST /api/v1/logout`. Throttle both endpoints at
      6/minute.
      **Done when:** feature tests cover: successful registration; duplicate
      email/phone rejected; invalid phone format rejected; login with a
      known customer phone succeeds; login with an unknown phone, an admin's
      phone, or a disabled customer fails.
- [x] 6. **`/me` endpoint.** `GET /api/v1/me` returns the authenticated
      user's id/name/email/phone/role and a `permissions` array of module
      strings (`super_admin` gets all five modules, `admin` gets its granted
      modules, `customer` gets an empty array); `401` when unauthenticated.
      **Done when:** feature tests cover all three roles' payload shape and
      the unauthenticated 401.
- [x] 7. **Admin-account management endpoints (super admin only).**
      `GET /api/v1/admin/accounts` (list `role = admin` rows with their
      permissions), `POST /api/v1/admin/accounts` (create a `role = admin`
      account: name, email, password, optional initial `permissions[]`),
      `PATCH /api/v1/admin/accounts/{id}` (update `is_active` and/or fully
      replace its `permissions[]` - delete rows not in the new list, insert
      the rest with `granted_by` = the acting super admin). All three
      `403` for anyone who isn't `super_admin`; the list/show/update queries
      are scoped to `role = admin` so a `super_admin` row can never be
      targeted.
      **Done when:** feature tests cover: super admin can list/create/enable/
      disable/reassign permissions; a plain admin gets 403 on all four;
      permission replacement persists correctly (extra removed, missing
      added).
- [x] 8. **Frontend foundation.** Install `react-router-dom`,
      `@tanstack/react-query`, `axios`, `react-hook-form`, `zod`,
      `@hookform/resolvers`. Add `src/lib/api.ts` (axios instance,
      `withCredentials: true`, CSRF-cookie bootstrap before mutating
      requests). Wrap `main.tsx` in `QueryClientProvider` + `BrowserRouter`.
      Replace the placeholder `App.tsx` with a route table and three minimal
      layout shells (`PublicLayout`, `CustomerLayout`, `AdminLayout` - nav +
      outlet, no visual polish yet).
      **Done when:** `npm run build` passes; the dev server renders the
      layout shells at their routes with no console errors.
- [x] 9. **Customer auth UI.** Register page (name, email, phone, password;
      RHF + Zod matching the backend rules), login page (phone only), a
      `useMe` query hook, logout action, and route guards (`ProtectedRoute`,
      redirect unauthenticated customers to `/login`, redirect authenticated
      customers away from `/login`/`/register`).
      **Done when:** manual browser walkthrough - register a customer, get
      redirected in, refresh and stay logged in, log out and get redirected
      to `/login`.
- [x] 10. **Admin auth + admin-accounts UI.** Admin login page at a separate
      route (email + password). `RoleRoute` restricting `/admin/*` to
      `admin`/`super_admin`. Super-admin-only admin-accounts page: table of
      admin accounts, create-admin form, enable/disable toggle, per-module
      permission checkboxes, wired to the step 7 endpoints.
      **Done when:** manual browser walkthrough - log in as the seeded super
      admin, create a new admin account, toggle its permissions and disabled
      state, confirm the changes persist on reload; confirm a regular admin
      account (once one exists) cannot reach the admin-accounts page.
- [x] 11. **Switch backend to PostgreSQL.** Point `DB_CONNECTION` at `pgsql`
      in `.env`/`.env.example` (host/port/database/username from the actual
      local Postgres instance; `.env.example` gets safe placeholder values,
      never a real password). Confirm the enum-based migrations (`role`,
      `admin_permissions.module`) and all constraints apply cleanly on
      Postgres, not just SQLite.
      **Done when:** `php artisan migrate:fresh --seed` succeeds against the
      real Postgres database and creates exactly one super admin; `php
      artisan test` still passes (it runs against its own isolated in-memory
      SQLite per `phpunit.xml`, unaffected by this change - that's the
      existing, correct Laravel testing convention, not a gap).

The following steps repair findings from the independent review
(`blueprint/context/findings.md`). Each closes with `Resolution:` noting the
fix; the finding itself moves to `fixed` here and to `closed` only on a later
`/audit` re-review.

- [x] 12. **F-01: surface mutation errors in the UI.** Admin-accounts create
      form maps 422 field errors the same way `RegisterPage` already does; row
      actions (permission save, enable/disable) and all three login/register
      forms show a short inline error for any non-2xx response (422 field
      errors where applicable, a generic message otherwise - e.g. 429 "Too
      many attempts, try again shortly").
      **Done when:** `npm run build` passes; each mutation hook's `onError`
      path renders a visible message instead of failing silently.
- [x] 13. **F-02: test the admin-accounts scoping boundary.** Extend
      `test_super_admin_can_list_admin_accounts` to also create a customer and
      a second super admin, then assert the response contains only the
      `role = admin` row(s) and not the others. Add a seeder test asserting
      `migrate:fresh --seed` produces exactly one `super_admin` account that
      can log in.
      **Done when:** both new/extended tests pass and fail if the scoping
      regresses (verified by temporarily removing the `where('role', 'admin')`
      clause and confirming the test catches it, then restoring it).
- [x] 14. **F-03: validate + transact admin-account writes.** Add `distinct`
      to `permissions.*` in both `StoreAdminAccountRequest` and
      `UpdateAdminAccountRequest`. Wrap `AdminAccountController::store`'s user
      creation, role assignment, and permission sync in one `DB::transaction`.
      **Done when:** a feature test posting a duplicate module (e.g.
      `['services','services']`) gets a `422`, not a `500`; existing admin
      creation tests still pass.
- [x] 15. **F-05: admin logout and navigation.** `AdminLayout` gets a logout
      button (using the existing `useAdminLogout` hook, then navigate to
      `/admin/login`) and a link to `/admin/accounts` for super admins.
      **Done when:** `npm run build` passes; `useAdminLogout` is no longer
      dead code.
- [x] 16. **F-06: `hasModulePermission()` respects `is_active`.** Return
      `false` immediately when the user is disabled, before checking role.
      **Done when:** a new unit test covers a disabled admin/super admin
      getting `false` for every module.
- [x] 17. **F-07: case-insensitive email identity.** Lowercase `email` in
      `prepareForValidation()` for `Customer\RegisterRequest`,
      `Admin\LoginRequest`, and `Admin\StoreAdminAccountRequest`; lowercase it
      in the admin login lookup and the seeder's `config('roasting.admin_email')`
      use.
      **Done when:** a feature test confirms registering `Juan@x.com` then
      registering `juan@x.com` is rejected as a duplicate, and an admin can
      log in with different-case email than they registered with.
- [x] 18. **Postgres test connection.** The independent review's remaining-risk
      note flagged that the full suite only ever ran on in-memory SQLite, so no
      automated run ever exercised the real Postgres driver (enum/constraint
      behavior, etc.). Point `backend/phpunit.xml` itself at Postgres:
      `DB_CONNECTION=pgsql` and `DB_DATABASE=roasting_service_test`, each set
      as both a forced `<env>` and a forced `<server>` entry, since PHPUnit's
      `force="true"` only overrides `putenv()`/`$_ENV`, while Laravel's
      `env()` reads `$_SERVER` first - a shell-set `DB_*` value would
      otherwise still win over just `<env>` (host/port/credentials still come
      from `.env`, so no secret is committed). Run tests via `composer test`,
      not `php artisan test` directly: the composer script clears the config
      cache first, and a cached config bypasses `phpunit.xml`'s overrides
      entirely. The default test command now runs against real Postgres; no
      separate config or script.
      **Done when:** `composer test` passes all 43 existing tests against a
      real local `roasting_service_test` Postgres database and exits 0, and a
      probe test confirms the resolved connection stays `pgsql` /
      `roasting_service_test` even with `DB_CONNECTION`/`DB_DATABASE`/`DB_URL`
      all overridden in the shell.
- [x] 19. **F-12: frontend unit test runner (Vitest).** The independent
      review's remaining-risk note also flagged that the frontend had no unit
      test runner, so auth UI logic could only be checked by reading code.
      Add Vitest as a dev dependency, wire a `test: { include:
      ['src/**/*.test.ts'] }` block into the existing `vite.config.ts`, add
      `npm run test` / `npm run test:watch` scripts, and add one real example
      test (`frontend/src/lib/errors.test.ts`) covering
      `getGenericErrorMessage`'s 429/419/5xx/fallback branches. Document the
      command in `AGENTS.md` and update `coding-standards.md`'s Testing
      section, which said "no frontend test runner exists yet."
      **Done when:** `npm run test` passes, `npm run build` still passes, and
      both docs reflect the real command.

## Files / areas

Backend:
- `backend/bootstrap/app.php`, `backend/routes/api.php` (new)
- `backend/config/cors.php` (new/published), `backend/config/sanctum.php`
- `backend/.env`, `backend/.env.example` (`FRONTEND_URL`,
  `SANCTUM_STATEFUL_DOMAINS`, `ADMIN_EMAIL`, `ADMIN_PASSWORD`)
- `backend/database/migrations/*_add_role_fields_to_users_table.php` (new)
- `backend/database/migrations/*_create_admin_permissions_table.php` (new)
- `backend/database/seeders/DatabaseSeeder.php`
- `backend/app/Models/User.php`, `backend/app/Models/AdminPermission.php` (new)
- `backend/app/Http/Middleware/EnsureRole.php` (new, role gate)
- `backend/app/Http/Controllers/Api/Admin/AuthController.php` (new)
- `backend/app/Http/Controllers/Api/Admin/AdminAccountController.php` (new)
- `backend/app/Http/Controllers/Api/Customer/AuthController.php` (new)
- `backend/app/Http/Controllers/Api/MeController.php` (new)
- `backend/app/Http/Requests/*` (register, admin login, customer login, store
  admin account, update admin account)
- `backend/app/Http/Resources/UserResource.php` (new)
- `backend/tests/Feature/Auth/*`, `backend/tests/Unit/UserPermissionTest.php`
- `backend/phpunit.xml` (points `DB_CONNECTION`/`DB_DATABASE` at Postgres)

Frontend:
- `frontend/package.json`
- `frontend/src/main.tsx`, `frontend/src/App.tsx`
- `frontend/src/lib/api.ts`, `frontend/src/lib/queryClient.ts` (new)
- `frontend/src/components/layouts/{Public,Customer,Admin}Layout.tsx` (new)
- `frontend/src/routes/{ProtectedRoute,RoleRoute}.tsx` (new)
- `frontend/src/features/auth/*` (pages, `useMe`, `useLogin`, `useRegister`,
  `useLogout`)
- `frontend/src/features/admin-accounts/*` (new)
- `frontend/vite.config.ts` (Vitest `test` block), `frontend/src/lib/errors.test.ts` (new)

## Data / contracts

- `users`: `+phone` (string, nullable, unique), `+role` (enum
  `super_admin|admin|customer`, default `customer`), `+is_active` (bool,
  default `true`).
- `admin_permissions`: `id`, `user_id` FK, `module` (enum
  `services|inventory|bookings|payments|dashboard`), `granted_by` FK,
  timestamps; unique (`user_id`, `module`).
- Phone format: accept `09XXXXXXXXX` (11 digits) or `+639XXXXXXXXX`; normalize
  to `09XXXXXXXXX` before storing or looking up, so registration and login
  always match regardless of which format was typed.
- `POST /api/v1/admin/login` -> `{email, password}` => `204` + session
  cookie, or `422` generic invalid-credentials error.
- `POST /api/v1/register` -> `{name, email, phone, password}` => `201` +
  session, or `422` field errors.
- `POST /api/v1/login` -> `{phone}` => `204` + session, or `422` generic
  invalid-login error (unknown/disabled/non-customer phone all look the
  same).
- `GET /api/v1/me` -> `{id, name, email, phone, role, permissions: string[]}`
  or `401`.
- `GET /api/v1/admin/accounts` -> list of `{id, name, email, is_active,
  permissions: string[]}` for `role = admin` rows.
- `POST /api/v1/admin/accounts` -> `{name, email, password, permissions?:
  string[]}` => `201` with the created account.
- `PATCH /api/v1/admin/accounts/{id}` -> `{is_active?: bool, permissions?:
  string[]}` => `200` with the updated account; `permissions` is a full
  replace, not a merge.

## Testing

Backend test gate is already on (`php artisan test` passes today), and since
step 18 the suite runs directly against real local Postgres, not SQLite. Every
step above that adds logic ships feature/unit tests in the same step - see
each step's "Done when."
Steps 8-10 predate the frontend test runner and are verified by build success
plus a manual browser walkthrough, not unit tests; Vitest (step 19) now
covers pure frontend logic going forward (`npm run test`).

## Notes for the AI

- Single Sanctum `web` guard for both admin and customer (one `users` table,
  role-based middleware) - matches the plan's Tech notes exactly. No second
  guard.
- Generic error messages on both login endpoints are deliberate: don't leak
  whether an email/phone exists, is disabled, or has the wrong role.
- `hasModulePermission()` has no real consumer route in this feature (no
  module pages exist yet) - test it directly against the `User` model, don't
  invent a throwaway route.
- Admin-account endpoints are hard-scoped to `role = admin` specifically so a
  `super_admin` row can never be listed, disabled, or have its permissions
  edited through this interface - that's what keeps a lockout impossible
  without adding extra checks.
- Seeded admin credentials come from env with a documented local-dev
  fallback; this is a reversible seed-time default, not a product decision.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":20244,"specSha256":"e0b78c9beba4c92c0fd403adcbd34cb569501fa2131d9865997343d59ddb8c65","branch":"refs/heads/feature/auth-roles","head":"f04c2491eb5c1554a7ebe9b56b269b33f7cd3401","baseRef":"refs/heads/master","baseCommit":"42c5cbc022b28715958fbcd6e263157dfe91405f","sourceTree":"b7e6bce5f0982175edc005e230571fbeab4e3177","absentOptional":[]} -->

## Findings

### 1/F-01 [P2] closed - Admin-accounts UI and auth forms fail silently on non-422 or create errors

**File:** frontend/src/features/admin-accounts/AdminAccountsPage.tsx:82
**Found:** 2026-09-24 by /audit independent (scope: current; lens: quality)
**Why it matters:** `createAdmin.mutate(values, { onSuccess })` has no error handling and the page renders no mutation error, so a backend 422 (for example a duplicate email) leaves the form looking unsubmitted with no message. The row actions (`update.mutate` at lines 53 and 61) are also silent on failure. The login pages only handle 422, so a 429 throttle, 419 CSRF, or 5xx response shows nothing (`frontend/src/features/auth/LoginPage.tsx:37`, `AdminLoginPage.tsx`, `RegisterPage.tsx`). The coding standards say to surface errors inline or with a toast, not fail silently.
**Suggested fix:** Map 422 `errors` onto the create form fields the same way `RegisterPage` does, and show a short inline message for other failures on the create form, row actions, and login forms (at least 429 "too many attempts, try again shortly").
**Resolution:** Added `frontend/src/lib/errors.ts` (`getGenericErrorMessage`) and wired it into the create-admin form (422 field mapping + generic fallback), both row actions (new `rowError` state), and all three login/register forms (429/419/5xx now show an inline message instead of nothing). Fixed by `/implement`, step 12. Closed 2026-09-24 by /audit independent re-review (fd042df): create form maps 422 `errors` onto fields with a generic fallback (`AdminAccountsPage.tsx:97-113`), row actions set `rowError` (`:32-37`, rendered `:78`), and all three auth forms render `formError` from `getGenericErrorMessage` for 429/419/5xx. Original defect gone; no new defect in the repaired paths. Remaining silent failures outside this finding's scope (list query, logout buttons) are tracked separately as F-10.

### 1/F-02 [P2] closed - Admin-accounts list scoping to role = admin is not asserted by any test

**File:** backend/tests/Feature/Auth/AdminAccountManagementTest.php:45
**Found:** 2026-09-24 by /audit independent (scope: current; lens: tests)
**Why it matters:** The spec's security boundary is that the admin-accounts endpoints only ever touch `role = admin` rows. `update` scoping is covered by a 404 test, but `test_super_admin_can_list_admin_accounts` only asserts an admin appears. Nothing fails if `index` starts returning the super admin or customer rows. The step 3 done-when (seeder produces exactly one `super_admin`) also has no automated test.
**Suggested fix:** In the list test, create a customer and a second super admin, and assert the response contains only `role = admin` rows (for example `assertJsonCount` plus `assertJsonMissing` on the other emails). Optionally add a seeder test asserting `migrate:fresh --seed` creates exactly one `super_admin` that can log in.
**Resolution:** Extended the list test with a second super admin + a customer, plus `assertJsonCount(1)` and `assertJsonMissing` on all three excluded rows; verified it actually catches the regression by temporarily dropping the `where('role','admin')` clause (test failed 4 vs 1 as expected), then restored it. Added `DatabaseSeederTest` covering exactly-one-super-admin and idempotent reseeding. Fixed by `/implement`, step 13. Closed 2026-09-24 by /audit independent re-review (fd042df): `test_super_admin_can_list_admin_accounts` now seeds the acting super admin, a second super admin, and a customer, and asserts `assertJsonCount(1)` plus `assertJsonMissing` for each excluded row, which fails if `index` drops the `role = admin` scope. `DatabaseSeederTest` asserts exactly one `super_admin`, the configured email, a matching password hash, and idempotent reseeding. Both pass in the 43-test run.

### 1/F-03 [P3] closed - Duplicate module in permissions[] causes a 500, and create is not atomic

**File:** backend/app/Http/Requests/Admin/StoreAdminAccountRequest.php:32
**Found:** 2026-09-24 by /audit independent (scope: current; lens: quality)
**Why it matters:** `permissions.*` uses only `Rule::in`, with no `distinct`. A payload like `["services","services"]` passes validation, then the second `AdminPermission::create` hits the (`user_id`, `module`) unique index and throws a QueryException (500). The same rule is in `UpdateAdminAccountRequest.php:30`. In `AdminAccountController::store` (lines 26-34), `User::create` and the `role` update run outside the permission transaction, so a failed sync leaves a created account behind and a retry then fails with "email taken". Only a super admin can reach it, and the UI never sends duplicates.
**Suggested fix:** Add `distinct` to both `permissions.*` rules, and wrap the create, role assignment, and permission sync in one `DB::transaction`.
**Resolution:** Added `distinct` to `permissions.*` (not the parent `permissions` array - Laravel's `distinct` rule applies per-item) in both requests; a duplicate module now returns 422 with `permissions.0`/`permissions.1` errors instead of a 500 (covered by a new test). Wrapped `store()`'s user creation, role assignment, and permission sync in one `DB::transaction`. Fixed by `/implement`, step 14. Closed 2026-09-24 by /audit independent re-review (fd042df): `permissions.*` carries `distinct` in both `StoreAdminAccountRequest.php:40` and `UpdateAdminAccountRequest.php:30`, `test_admin_account_creation_rejects_duplicate_permission_module` gets 422, and `AdminAccountController::store` (lines 26-38) wraps create, role assignment, and the permission sync (a nested savepoint) in one `DB::transaction`. No new defect found.

### 1/F-05 [P3] closed - Admin shell has no logout or navigation; useAdminLogout is dead code

**File:** frontend/src/features/auth/hooks.ts:57
**Found:** 2026-09-24 by /audit independent (scope: current; lens: quality)
**Why it matters:** `useAdminLogout` and `adminLogout` are never used. `AdminLayout` renders only a header label, with no logout action and no link to `/admin/accounts`, so an admin cannot end their session from the UI. Spec step 8 asks for "nav + outlet" shells.
**Suggested fix:** Add a logout button (using `useAdminLogout`, then navigate to `/admin/login`) and a link to `/admin/accounts` for super admins in `AdminLayout`. If that is deferred, delete the unused hook and API function.
**Resolution:** `AdminLayout` now has a logout button (`useAdminLogout` -> navigate to `/admin/login`) and an "Admin accounts" nav link shown only when `me.role === 'super_admin'`. Fixed by `/implement`, step 15. Closed 2026-09-24 by /audit independent re-review (fd042df): `AdminLayout.tsx:17` shows the accounts link only for `super_admin`, and `:19-26` logs out through `useAdminLogout` and then navigates to `/admin/login`. `useAdminLogout` is no longer dead code. The logout button's missing `onError` feedback is the same silent-failure pattern as the customer `AccountPage` logout. It is recorded as F-10 and does not reopen this finding.

### 1/F-06 [P3] closed - hasModulePermission ignores is_active

**File:** backend/app/Models/User.php:47
**Found:** 2026-09-24 by /audit independent (scope: current; lens: security)
**Why it matters:** The primitive returns true for a disabled admin or super admin. It is safe today only because `EnsureRole` re-checks `is_active`. Later module routes that guard with `auth:sanctum` plus `hasModulePermission()`, without `role:` middleware, would let a disabled admin with a live session keep module access. No route uses it yet, so this cannot be reached now.
**Suggested fix:** Return false when `! $this->is_active`, and add a unit test for it. Otherwise, document that every module route must also use `role:admin,super_admin`.
**Resolution:** `hasModulePermission()` now returns `false` immediately when `! is_active`, before checking role. Added unit tests for a disabled super admin and a disabled admin with a granted permission. Fixed by `/implement`, step 16. Closed 2026-09-24 by /audit independent re-review (fd042df): `User::hasModulePermission` (`User.php:47-62`) returns false before the role checks when `! is_active`, and `UserPermissionTest` covers a disabled super admin across all modules and a disabled admin with a granted row. The `/me` payload still derives permissions separately without `is_active`. That lives in `UserResource.php` and is recorded as F-11.

### 1/F-07 [P3] closed - Email identity is case-sensitive on PostgreSQL

**File:** backend/app/Http/Requests/Customer/RegisterRequest.php:39
**Found:** 2026-09-24 by /audit independent (scope: current; lens: quality)
**Why it matters:** `unique:users,email` and the admin login lookup `User::where('email', ...)` (`Api/Admin/AuthController.php:18`) compare emails exactly. On PostgreSQL, `Juan@x.com` and `juan@x.com` can both register, and an admin who types their email with different casing gets the generic invalid-credentials error.
**Suggested fix:** Lowercase `email` in `prepareForValidation` for the register, admin login, and store-admin requests. The seeder already takes the email from config and needs the same normalization.
**Resolution:** Added `App\Http\Requests\Concerns\LowercasesEmail` trait and applied it in `prepareForValidation()` for `Customer\RegisterRequest`, `Admin\LoginRequest`, and `Admin\StoreAdminAccountRequest`; lowercased the seeder's `config('roasting.admin_email')` use. Added tests: case-insensitive duplicate-email rejection on registration, and admin login succeeding with a different-case email than stored. Fixed by `/implement`, step 17. Closed 2026-09-24 by /audit independent re-review (fd042df): `LowercasesEmail` runs in `prepareForValidation` for register, admin login, and store-admin, so both `unique:users,email` and the admin lookup see lowercased input. The seeder lowercases the configured email, and no other write path accepts an email (update does not). The case-insensitive duplicate-registration and mixed-case admin login tests both pass. No new defect found.

### 1/F-12 [P3] closed - Frontend Vitest setup is outside the spec, and the spec's Testing section now contradicts it

**File:** blueprint/context/current-feature.md:303
**Found:** 2026-09-25 by /audit independent (scope: current; lens: quality)
**Why it matters:** Checkpoint `b9784de` adds Vitest (`frontend/package.json` `test`/`test:watch`, a `test` block in `frontend/vite.config.ts`, and `frontend/src/lib/errors.test.ts`). It also rewrites the Testing section of `coding-standards.md` and the frontend Commands in `AGENTS.md`. The spec for this work item does not mention any of it. Step 18 covers only the Postgres connection, "Files / areas" does not list these frontend files, and the spec's Testing section (line 303) still says "No frontend test runner exists yet". `/complete` will archive a spec that disagrees with both the merged code and the project standards. The new `coding-standards.md:104` line says the runner was "set up via `/tests`", but nothing in the spec records that. The tooling itself works: `npm run test` passes 4 real assertions.
**Suggested fix:** Record the Vitest setup in the spec. Either add it to step 18 or add a new step, list the four frontend files under "Files / areas", and replace line 303 with the current state. Code changes: None. Alternatively, move the Vitest setup into its own `/tests` work item. That would remove the runner from this branch, so it needs an explicit user decision.
**Resolution:** Added build step 19 documenting the Vitest setup (dependency, `vite.config.ts` test block, scripts, example test, doc updates), listed `frontend/vite.config.ts` and `frontend/src/lib/errors.test.ts` under "Files / areas", and rewrote the Testing section to describe the current state instead of "no frontend test runner exists yet". No code changed. Fixed by direct spec edit; awaiting `/audit` re-review to close.
Closed 2026-09-25 by /audit independent (scope: current, target `4391806`). Re-examined `current-feature.md`. Step 19 records the Vitest dependency, the `vite.config.ts` test block, the scripts, the example test, and the doc updates. "Files / areas" lists `frontend/vite.config.ts` and `frontend/src/lib/errors.test.ts`, and `frontend/package.json` was already listed. The Testing section now describes the actual runner. `AGENTS.md` and `coding-standards.md` agree with the spec. `4391806` touched no frontend files: `npm run test` passes 4/4, and `npm run build` and `npm run lint` exit 0. The repair introduced no new defect.

### 1/F-13 [P2] closed - Forced `<env>` entries do not isolate the test database; shell `DB_*` values still win

**File:** backend/phpunit.xml:26
**Found:** 2026-09-25 by /audit independent (scope: current; lens: security)
**Why it matters:** The suite uses `RefreshDatabase`, which runs `migrate:fresh` (drop all tables) on whichever database it resolves. `phpunit.pgsql.xml` sets `DB_CONNECTION`/`DB_DATABASE` with plain `<env>` entries. Without `force="true"`, PHPUnit keeps any value already in the process environment. The protection against a cached config is `config:clear` in the composer script, so it applies only through `composer test:pgsql`. If a shell, IDE run configuration, or future CI job exports `DB_DATABASE` pointing at the dev database, or if someone runs `vendor/bin/phpunit -c phpunit.pgsql.xml` directly with `bootstrap/cache/config.php` present, the suite would wipe the dev database. This review checked the current environment: no `DB_*` process variables and no `.env.testing`. After the run, `roasting_service_test` held the migrated schema with 0 users and `roasting_service_db` still held its seeded row. The risk has not been reproduced. The file holds no committed secret.
**Suggested fix:** Add `force="true"` to the `DB_CONNECTION`, `DB_DATABASE`, and `DB_URL` entries in `phpunit.pgsql.xml` so the test database always wins. No current requirement is lost.
**Resolution:** Added `force="true"` to all three entries (`DB_CONNECTION`, `DB_DATABASE`, `DB_URL`). By the user's explicit decision, the separate `phpunit.pgsql.xml` was then superseded: `backend/phpunit.xml` itself now points at Postgres (`roasting_service_test`), with the same three entries forced, and the file was deleted along with the `composer test:pgsql` script. `composer test` still passes 43 tests, 154 assertions, exit 0, now unconditionally against `roasting_service_test` regardless of any pre-set shell `DB_*` variable or cached config. Fixed by direct edit; awaiting `/audit` re-review to close.
Reopened 2026-09-25 by /audit independent (scope: current, target `4391806`); severity raised P3 -> P2. The repair does not remove the defect. `force="true"` is valid PHPUnit 12.5 syntax, but PHPUnit's `PhpHandler::handleEnvVariables` forces only `putenv()` and `$_ENV`. Laravel's `env()` goes through phpdotenv's default adapters, which read `$_SERVER` first, and on the CLI `$_SERVER` still holds the shell's original values. A probe test in the scratchpad (outside the repo, no DB access) printed the resolved config under shell overrides. `DB_DATABASE=probe_only_db` gave `pgsql.database=probe_only_db`. `DB_CONNECTION=sqlite` gave `database.default=sqlite`. `DB_URL=pgsql://...` gave that `pgsql.url`. In each case `$_ENV` and `getenv()` showed the forced value, but config used the `$_SERVER` value. So `DB_DATABASE=roasting_service_db composer test` would run `RefreshDatabase`'s `migrate:fresh` on the dev database. This was not run. With `backend/phpunit.xml` now the only test config, this path is reached by every backend test run, and spec step 18's statement that a pre-set shell/CI `DB_*` variable "can never override it" is false. The Resolution's "regardless of ... cached config" also holds only for `composer test`, which runs `config:clear`. The documented `php artisan test` does not clear the config cache, and `LoadConfiguration` then ignores all env values. That cache hazard predates this delta and also applied to the old SQLite config. No precondition is present today: there are no `DB_*` process variables and no `bootstrap/cache/config.php`, and `roasting_service_db` still held its 1 user after the suite ran.
**Suggested fix (revised):** Add matching `<server name="DB_CONNECTION" value="pgsql" force="true"/>`, `<server name="DB_DATABASE" value="roasting_service_test" force="true"/>`, and `<server name="DB_URL" value="" force="true"/>` entries to `backend/phpunit.xml`, keeping the `<env>` entries. A scratch copy of `phpunit.xml` with these three lines resolved `pgsql`/`roasting_service_test`/empty URL even with all three variables overridden in the shell. Correct step 18's wording to match. Also, either document `composer test` (which clears the config cache) as the backend Test command in `AGENTS.md`, or state the cached-config caveat. No current requirement is lost.
**Resolution:** Added the three forced `<server>` entries alongside the existing forced `<env>` entries in `backend/phpunit.xml`. Verified with a temporary probe test (`tests/Unit/EnvProbeTest.php`, removed after use) run via `vendor/bin/phpunit` with `DB_CONNECTION=sqlite DB_DATABASE=roasting_service_db DB_URL=sqlite::memory:` set in the shell: the resolved config still showed `connection=pgsql database=roasting_service_test`. `composer test` still passes 43 tests, 154 assertions, exit 0. Corrected step 18's wording (dropped the false "can never override" claim) and changed `AGENTS.md`'s documented backend Test command from `php artisan test` to `composer test`, since only the composer script clears the config cache that would otherwise bypass these overrides entirely. Fixed by direct edit; awaiting `/audit` re-review to close.
Closed 2026-09-25 by /audit independent (scope: current, target `f04c249`). `backend/phpunit.xml:29-31` now sets `DB_CONNECTION`, `DB_DATABASE`, and `DB_URL` as forced `<server>` entries next to the forced `<env>` entries. This review ran its own read-only probe (`tests/Unit/_ReviewProbeTest.php`, extending `Tests\TestCase` with no traits and no DB access, deleted afterwards). With `DB_CONNECTION=sqlite DB_DATABASE=roasting_service_db DB_URL=sqlite::memory:` set in the shell, `vendor/bin/phpunit` resolved `database.default=pgsql`, `pgsql.database=roasting_service_test`, and an empty `pgsql.url`. `$_SERVER`, `$_ENV`, and `getenv()` all held the forced values. A `pgsql://...` `DB_URL` override gave the same result. The same overrides through `composer test -- --filter=...` also resolved `pgsql`/`roasting_service_test`, after the script printed "Configuration cache cleared". Negative control: a scratch copy of `phpunit.xml` with only the `<server>` lines removed resolved `default=sqlite` and `pgsql.database=probe_only_db` under the same kind of override. So the `<server>` entries are what closes the gap. `composer.json`'s `test` script runs `config:clear` before `artisan test`. No `bootstrap/cache/config.php` and no `.env.testing` exist, and the shell has no `DB_*` variables. `composer test` passes 43 tests and 154 assertions. The repair introduced no new defect. The cached-config bypass through a bare `php artisan test` still exists by design, and `AGENTS.md` now documents it. Two docs still name `php artisan test`; that drift is recorded as F-14.

## Independent review

**Status:** passed
**Target commit:** f04c2491eb5c1554a7ebe9b56b269b33f7cd3401
**Base commit:** 42c5cbc022b28715958fbcd6e263157dfe91405f
**Base ref:** master
**Spec hash:** e0b78c9beba4c92c0fd403adcbd34cb569501fa2131d9865997343d59ddb8c65
**Prepared by:** claude
**Builder model:** claude-sonnet-5
**Requested reviewer:** claude
**Requested model:** claude-opus-5-5
**Requested execution:** automatic
**Requested at:** 2026-09-24T18:11:28Z
**Workflow:** regular
**Check required:** no
**Reviewer adapter:** claude
**Reviewer model:** claude-opus-5-5
**Reviewer context:** fresh subagent
**Actual execution:** automatic
**Reviewed at:** 2026-09-24T18:16:49Z
**Scope:** current
**Lenses:** quality, security, performance, tests
**Verdict:** passed
**Check result:** not-required

### Commands

- `git rev-parse HEAD` / `git merge-base master HEAD` / `sha256sum blueprint/context/current-feature.md` / `git status --short`: pass (target, base, and spec hash match; only `blueprint/context/review.md` differed)
- `vendor/bin/phpunit --filter test_review_probe_resolved_db_config` (temporary read-only probe, no shell overrides): pass, resolved `pgsql` / `roasting_service_test`
- `DB_CONNECTION=sqlite DB_DATABASE=roasting_service_db DB_URL=sqlite::memory: vendor/bin/phpunit --filter ...`: pass, still resolved `pgsql` / `roasting_service_test` / empty URL
- `DB_URL=pgsql://... DB_DATABASE=roasting_service_db vendor/bin/phpunit --filter ...`: pass, still resolved `pgsql` / `roasting_service_test` / empty URL
- `DB_CONNECTION=sqlite DB_DATABASE=roasting_service_db DB_URL=sqlite::memory: composer test -- --filter=...`: pass, config cache cleared first, then resolved `pgsql` / `roasting_service_test`
- Negative control: `vendor/bin/phpunit -c <scratch copy of phpunit.xml without the <server> lines>` with shell overrides: resolved `sqlite` / `probe_only_db`, which confirms the `<server>` entries are the working fix
- `composer test` (backend, full suite): pass, 43 tests, 154 assertions
- `vendor/bin/pint --test`: pass
- `npm run test` (frontend): pass, 4/4
- `npm run lint` (frontend): pass (exit 0; one existing `only-export-components` warning in `src/components/ui/button.tsx`, outside this delta)
- `npm run build` (frontend): pass (only the chunk-size advisory)

### Evidence

- `backend/phpunit.xml:29-31` adds forced `<server>` entries for `DB_CONNECTION`, `DB_DATABASE`, and `DB_URL` next to the forced `<env>` entries. The probe showed that `$_SERVER`, `$_ENV`, and `getenv()` all hold the forced values under shell overrides.
- `backend/composer.json` `test` script: `@php artisan config:clear --ansi @no_additional_args` runs before `@php artisan test`.
- `backend/bootstrap/cache/` holds only `packages.php` and `services.php`, with no `config.php`. No `backend/.env.testing` exists, and the shell has no `DB_*` variables.
- `tests/TestCase.php` uses no traits. The probe class used no `RefreshDatabase` and made no DB calls. The probe file was deleted, and `git status --short` afterwards showed only the review-evidence paths.
- `AGENTS.md` Test command and spec step 18 now describe the `<env>`+`<server>` combination and `composer test`. Code in `backend/app`, `backend/tests`, and `frontend/` is unchanged since `4391806`. That code was re-read for the auth routes, `EnsureRole`, the controllers, `UserResource`, the request classes, and the frontend auth plumbing. No new defect was found beyond the existing ledger entries.
- The real `roasting_service_db` database was not queried or touched.

### Findings

- F-13 [P2] closed: independent probe plus negative control confirm the fix
- F-14 [P3] open (new): `coding-standards.md:99`/`:144` and `current-feature.md:320` still name `php artisan test`
- Carried forward unchanged: F-04 [P3] open, F-08 [P3] unverified, F-09 [P3] open, F-10 [P3] open, F-11 [P3] open
- No P0 or P1 finding is open or fixed

### Remaining risk

- A bare `php artisan test` or `vendor/bin/phpunit` run with a cached config (`bootstrap/cache/config.php`) still ignores `phpunit.xml` overrides and would use the cached (dev) database. Only `composer test` guards against this, and F-14 tracks the docs that still point elsewhere.
- Parallel testing (`--parallel`) and CI were not exercised; no `Verify` command or CI workflow exists.
- Check was not required and was not run; no browser flow was re-verified in this pass.
- No dependency vulnerability scan was run (no scanner is declared).
