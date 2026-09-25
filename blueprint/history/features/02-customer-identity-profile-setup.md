# Feature: Customer identity & profile setup

**From build-plan:** feature 2
**Build attempt:** 1
**Status:** verified
**Branch:** feature/customer-identity-profile-setup

## Goal

Remove email and password from customer identity entirely, along with the
standalone registration page. Customer login becomes the only entry point: an
unrecognized phone number silently creates the customer account (the same v1
no-verification trust model mobile-only login already has), and a customer
with no name saved yet is routed to a one-time profile-setup step (first
name, optional middle name, last name, address) before reaching their
account page. This resolves the open question left after Feature 1's
independent review ("customer password field's purpose is unclear") by
removing the field.

## In scope

- Backend: `users` migration making `name`, `email`, `password` nullable
  (admin/super-admin only from here on) and adding nullable `first_name`,
  `middle_name`, `last_name`, `address` (free text).
- Backend: `AuthController::login()` auto-creates a customer row when the
  phone doesn't match any existing user; preserves today's reject behavior
  when the phone belongs to a non-customer or disabled row. Returns `200` +
  the user payload instead of `204` either way (creation and existing-login
  look identical to the client).
- Backend: remove `POST /api/v1/register` entirely (route, request, and the
  controller method).
- Backend: new `POST /api/v1/profile/complete` (auth:sanctum, customer-only)
  saving first/middle/last name + address.
- `UserResource` includes the four new fields.
- Frontend: remove the registration page, its route, its link on the public
  layout, and its hook/API function.
- Frontend: new profile-setup page and route guard - an authenticated
  customer with no name on file is redirected there; a customer whose
  profile is already complete (or any admin) is redirected away from it.
- Frontend: login navigates to `/profile-setup` or `/account` depending on
  profile completeness; the account page displays a customer's first/last
  name instead of the now-empty `name` field.

## Out of scope

- Editing the profile after initial setup - that's Feature 3 (Customer
  profile), specced separately later.
- SMS one-time-code verification (v2, per the plan) - login/creation stays
  trust-based in v1, same as before.
- Any change to admin login, admin accounts, or admin permissions - untouched.
- Structured address (street/city/etc.) - a single free-text field, per the
  plan's `delivery_address` on bookings already being separate and per-order.
- Closing the theoretical race where two simultaneous first-logins on the
  same brand-new number could both attempt to create a row (the second would
  hit the `phone` unique constraint as a 500 instead of the generic error).
  Accepted as the same class of leniency already built into the v1 trust
  model; not fixed here.

## Build loop

Per `blueprint/config.json`: `stepReview: "feature"` (one review packet with
the full diff after all steps below pass) and `checkpointCommits: "disabled"`.
`/complete` makes the final feature commit.

## Build steps

- [x] 1. **Migration + model.** New migration making `users.name`,
      `users.email`, `users.password` nullable, and adding nullable
      `first_name`, `middle_name`, `last_name` (strings) and `address`
      (text). Postgres's unique index on `email` still permits multiple
      NULLs, so nullifying it doesn't collide across customer rows. Add the
      four new fields to `User`'s `#[Fillable([...])]` attribute.
      **Done when:** `php artisan migrate:fresh --seed` runs clean against
      the real local Postgres database; `composer test` still passes
      unchanged (43/43).
- [x] 2. **Remove customer registration (backend).** Delete
      `Requests/Customer/RegisterRequest.php`; delete
      `AuthController::register()` and its now-unused import; delete the
      `POST /register` route. Delete the six registration tests in
      `CustomerAuthTest.php` (`test_customer_can_register`,
      `test_registration_normalizes_intl_phone_format`,
      `test_registration_rejects_invalid_phone_format`,
      `test_registration_rejects_duplicate_email`,
      `test_registration_rejects_duplicate_email_case_insensitively`,
      `test_registration_rejects_duplicate_phone`).
      **Done when:** `POST /api/v1/register` 404s; the suite passes with
      those six tests gone and nothing else broken.
- [x] 3. **Login auto-creates; returns the user.** Rewrite
      `AuthController::login()`: look up by phone; if found, keep today's
      reject-if-wrong-role-or-disabled check unchanged; if not found,
      `User::create(['phone' => $phone])` (role/is_active come from the
      column defaults). Return `200` + `UserResource` instead of `204`
      either way. Add the four new fields to `UserResource::toArray()`.
      Rewrite `test_login_rejects_unknown_phone` into
      `test_login_auto_creates_customer_for_unknown_phone` (asserts `200`,
      a new row with `role=customer`, `is_active=true`, null names,
      `assertAuthenticated`); add an equivalent intl-phone-format
      auto-create test; update
      `test_customer_can_log_in_with_registered_phone` and
      `test_customer_can_log_in_with_intl_phone_format` from
      `assertNoContent()` to `assertOk()` plus a body check. Leave
      `test_login_rejects_admin_phone` and
      `test_login_rejects_disabled_customer` assertions as they are.
      **Done when:** the suite passes; posting a fresh phone number to
      `/api/v1/login` returns `200` with a new customer id.
- [x] 4. **Profile-completion endpoint.** New
      `Requests/Customer/CompleteProfileRequest` (`first_name`/`last_name`
      required, `middle_name` nullable, `address` required; `authorize()`
      restricted to `role === 'customer'`). New
      `Api/Customer/ProfileController::complete()` (`$user->update(...)`,
      returns `UserResource`). Route: `POST /api/v1/profile/complete`,
      `auth:sanctum`. New `tests/Feature/Auth/ProfileSetupTest.php`: happy
      path; missing `first_name`/`last_name` rejected (422); omitted
      `middle_name` accepted; unauthenticated rejected (401); an
      authenticated admin rejected (403).
      **Done when:** the suite passes including the new file.
- [x] 5. **Remove customer registration (frontend).** Delete
      `RegisterPage.tsx`; the `/register` route in `App.tsx`; `useRegister()`
      in `hooks.ts` and its `registerCustomer` import; `registerCustomer()`
      and `RegisterPayload` in `api.ts`; the register link in
      `PublicLayout.tsx`.
      **Done when:** `npm run build` passes with no dangling imports.
- [x] 6. **Types/API/hooks for the new contract.** Widen `Me.name` and
      `Me.email` to `string | null`; add `first_name`, `middle_name`,
      `last_name`, `address: string | null`. New `features/auth/profile.ts`
      exporting `isProfileComplete(me): boolean` (`Boolean(me.first_name &&
      me.last_name)`), plus `profile.test.ts` (Vitest) covering
      both-set/missing-first/missing-last/both-null. `loginCustomer` returns
      `Promise<Me>` now; add `completeProfile()` + `CompleteProfilePayload`.
      `useLoginCustomer`'s `onSuccess` switches from `invalidateQueries` to
      `setQueryData(['me'], me)`; add `useCompleteProfile()`.
      **Done when:** `npm run test` and `npm run build` pass.
- [x] 7. **Profile-setup page + route guards.** New `ProfileSetupPage.tsx`
      (RHF + Zod: `first_name` required, `middle_name` optional, `last_name`
      required, `address` required; same 422 field-mapping pattern the old
      `RegisterPage` used). New `routes/ProfileSetupRoute.tsx`: unauthenticated
      -> `/login`; non-customer -> `/admin`; complete-profile customer ->
      `/account`; otherwise render the outlet. Extend `ProtectedRoute.tsx`:
      an authenticated customer with an incomplete profile redirects to
      `/profile-setup` before rendering its outlet. Wire `/profile-setup`
      into `App.tsx` under `ProfileSetupRoute` + the existing `CustomerLayout`
      (confirmed to have no profile-dependent rendering).
      **Done when:** `npm run build` passes; manual walkthrough (below)
      confirms both redirect directions.
- [x] 8. **Login navigation + account display.** `LoginPage`'s submit
      success and its already-logged-in early-redirect both branch on
      `isProfileComplete(me)` (`/account` vs `/profile-setup`) instead of
      always `/account`. `AccountPage` shows `first_name + last_name` for a
      customer, `name` for an admin, instead of unconditionally `me?.name`.
      **Done when:** manual walkthrough (below) passes end to end.

## Files / areas

Backend:
- `backend/database/migrations/*_add_profile_fields_to_users_table.php` (new)
- `backend/app/Models/User.php`
- `backend/app/Http/Controllers/Api/Customer/AuthController.php`
- `backend/app/Http/Controllers/Api/Customer/ProfileController.php` (new)
- `backend/app/Http/Requests/Customer/RegisterRequest.php` (deleted)
- `backend/app/Http/Requests/Customer/CompleteProfileRequest.php` (new)
- `backend/app/Http/Resources/UserResource.php`
- `backend/routes/api.php`
- `backend/tests/Feature/Auth/CustomerAuthTest.php`
- `backend/tests/Feature/Auth/ProfileSetupTest.php` (new)

Frontend:
- `frontend/src/features/auth/RegisterPage.tsx` (deleted)
- `frontend/src/features/auth/ProfileSetupPage.tsx` (new)
- `frontend/src/features/auth/profile.ts`, `profile.test.ts` (new)
- `frontend/src/features/auth/{types,api,hooks}.ts`
- `frontend/src/features/auth/{LoginPage,AccountPage}.tsx`
- `frontend/src/routes/ProtectedRoute.tsx`
- `frontend/src/routes/ProfileSetupRoute.tsx` (new)
- `frontend/src/components/layouts/PublicLayout.tsx`
- `frontend/src/App.tsx`

## Data / contracts

- `users`: `name`, `email`, `password` become nullable (admin/super-admin
  only going forward); `+first_name` (nullable), `+middle_name` (nullable),
  `+last_name` (nullable), `+address` (nullable, text).
- `POST /api/v1/login` -> `{phone}` => `200` + `UserResource` (whether the
  row already existed or was just created), or `422` generic invalid-login
  error (unknown-role/disabled phone still rejected the same way).
- `POST /api/v1/profile/complete` -> `{first_name, middle_name?, last_name,
  address}` => `200` + `UserResource`, or `422` field errors. `401` if
  unauthenticated, `403` if not a customer.
- `GET /api/v1/me` -> adds `first_name`, `middle_name`, `last_name`,
  `address` to the existing payload.
- `POST /api/v1/register` removed.

## Testing

Backend test gate is already on; every step above that adds logic ships
feature/unit tests in the same step - see each step's "Done when." Frontend
logic gets a Vitest unit test (`profile.test.ts`) per the same gate; the
setup/routing steps (5, 7, 8) are verified by build success plus the manual
browser walkthrough below, matching how Feature 1 verified its UI steps.

**Manual walkthrough:** start both dev servers -> log in with a brand-new PH
number -> land on `/profile-setup` -> fill first/last name + address, leave
middle blank -> land on `/account` showing the name -> log out -> log back in
with the same number -> skip straight to `/account` with the same name ->
while logged in, navigate directly to `/profile-setup` -> bounced to
`/account` -> log out, navigate directly to `/profile-setup` -> bounced to
`/login` -> log in as the seeded super admin, navigate to `/profile-setup` ->
bounced to `/admin` -> confirm `/register` no longer renders a form.

## Notes for the AI

- This is a bigger change to Feature 1's already-shipped auth, done as its
  own build-plan entry per this project's workflow - Feature 1's archive at
  `blueprint/history/features/01-auth-roles.md` is not touched.
- `blueprint/build-plan.md` and `blueprint/project-plan.md` were already
  updated (as part of proposing this feature) to describe the new flow;
  `blueprint/context/project-overview.md` still needs a `/overview` run to
  regenerate from those - do that (or ask the user to) rather than
  hand-editing the generated overview.
- `role` and `is_active` never need to be set explicitly on the new
  customer row created by login - they come from the migration's column
  defaults (`customer`, `true`), same as the deleted `register()` relied on.
- Keep the `Customer/ProfileController::complete()` endpoint separate from
  `/me` and separate from whatever edit contract Feature 3 (Customer
  profile) designs later - this one is a one-time setup action, not a
  general profile editor.
- Admin accounts, admin login, and `hasModulePermission()` are entirely
  unaffected - `name`, `email`, `password` stay required in practice for
  admin/super-admin rows (enforced by `AdminAccountController`'s validation,
  not the DB), only the column-level NOT NULL constraint is relaxed.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":12827,"specSha256":"e8d80943ad4a9ebc50291696c5e00d2581a11cde23033d1b2bc7710bb6c46e88","branch":"refs/heads/feature/customer-identity-profile-setup","head":"4d633f7692cf4107c863d1050e90f58b158393be","baseRef":"refs/heads/master","baseCommit":"4fa45a77a7b0fba896fa7807c8c4e525002dd021","sourceTree":"21caa36b88d96f8f03f06c97042bf7743d319203","absentOptional":[]} -->

## Independent review

**Status:** passed
**Target commit:** 4d633f7692cf4107c863d1050e90f58b158393be
**Base commit:** 4fa45a77a7b0fba896fa7807c8c4e525002dd021
**Base ref:** master
**Spec hash:** e8d80943ad4a9ebc50291696c5e00d2581a11cde23033d1b2bc7710bb6c46e88
**Prepared by:** claude
**Builder model:** claude-sonnet-5
**Requested reviewer:** claude
**Requested model:** runtime default (exact model not known until reviewer starts)
**Requested execution:** automatic
**Requested at:** 2026-09-25T02:17:25Z
**Workflow:** regular
**Check required:** no
**Reviewer adapter:** claude
**Reviewer model:** claude-sonnet-5
**Reviewer context:** fresh subagent
**Actual execution:** automatic
**Reviewed at:** 2026-09-25T02:25:41Z
**Scope:** current
**Lenses:** quality, security, performance, tests
**Verdict:** passed
**Check result:** not-required

### Commands

- `cd backend && composer test`: pass (43/43 tests, 150 assertions)
- `cd frontend && npm run build`: pass (tsc -b && vite build, no errors)
- `cd frontend && npm run lint`: pass (oxlint; one pre-existing warning in `src/components/ui/button.tsx:57`, outside this diff)
- `cd frontend && npm run test`: pass (vitest, 2 files, 8 tests)

### Evidence

- Verified `HEAD` (`4d633f7692cf4107c863d1050e90f58b158393be`) equals Target commit; `git merge-base HEAD master` equals Base commit; working tree clean except `blueprint/context/review.md`.
- Verified SHA-256 of raw `blueprint/context/current-feature.md` bytes equals Spec hash via `sha256sum`.
- Reviewed the complete `4fa45a77a7b0fba896fa7807c8c4e525002dd021..4d633f7692cf4107c863d1050e90f58b158393be` diff (28 files, 803 insertions / 385 deletions) across all four lenses: backend auth/profile controllers, requests, resources, model, migration, routes, and tests; frontend auth pages, routes, hooks, api, types, and the new `profile.ts`/`profile.test.ts`.
- Confirmed each build step's stated "Done when" evidence: migration nullability, register route/tests fully removed with no dangling `register`/`Register` references in frontend or backend source, login auto-create + 200 response, profile-complete endpoint + auth/role checks (401/403), frontend route guards in both directions (`ProfileSetupRoute`, extended `ProtectedRoute`), login/account display branching on `isProfileComplete`.
- Confirmed no dead code left from the deleted registration flow (`RegisterPage`, `useRegister`, `RegisterPayload`, `registerCustomer`, the `/register` route and link) via targeted greps.
- Confirmed the spec's accepted out-of-scope race (two simultaneous first-logins on the same new number) is genuinely unaddressed in `AuthController::login()`, matching the spec's explicit acceptance.
- Confirmed admin login/authorization (`Admin/AuthController.php`, `AdminAccountController`, `hasModulePermission()`) is untouched by this diff.

### Findings

- F-15 [P2] open - `ProfileController::complete()` has no server-side guard against being re-invoked after the profile is already complete (only a frontend route redirect enforces "one-time"), contradicting the spec's stated design intent. Self-data only, not a cross-user exposure.
- F-16 [P3] open - `LoginPage.tsx:44`'s generic 422 message ("could not find an account") is now inaccurate for every remaining trigger case (disabled customer or admin phone), since unrecognized phones no longer 422 after this feature.
- F-17 [P3] open - `ProfileSetupPage.tsx` submits an empty string for a blank optional `middle_name` rather than omitting it/sending null, diverging from the nullable data model; untested by the backend suite, which only exercises full omission.
- F-18 [P3] unverified - Customer login's auto-create branch does more DB work (SELECT+INSERT+refresh) than the existing-account branch (SELECT only), a plausible unmeasured timing side channel against the spec's "identical to the client" goal for creation vs. existing login.
- Also re-examined and updated (line/context only, no status change): F-04, F-10, F-11, F-14 - all remain `open`; none of this diff's changes fixed or invalidated them.

### Remaining risk

- F-18's timing-channel hypothesis was not measured (no profiling or load test run); recorded as `unverified`.
- No browser/manual walkthrough was performed in this review (Check gate is `manual` and was not selected for this request per `Check required: no`); the spec's own manual walkthrough steps (redirect directions, `/register` no longer rendering) were verified by code/route inspection only, not a live browser session.
- `npm run lint`'s one warning (`button.tsx:57`, fast-refresh/only-export-components) is pre-existing and outside this diff's file list; left unaddressed as out of scope for this review.
