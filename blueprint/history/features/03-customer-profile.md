# Feature: Customer profile

**From build-plan:** feature 3
**Build attempt:** 1
**Status:** verified
**Branch:** `feature/customer-profile`

## Goal

Let a customer view and edit their own first/middle/last name and address after
the one-time profile-setup step (Feature 2) has already filled them in. Phone
stays the non-editable login identity; customers still have no email or
password. This also closes ledger finding F-15: `ProfileController::complete()`
currently has no guard against being re-invoked after the profile is already
complete, which Feature 2's own notes reserved for this feature to resolve.

## In scope

- Backend: new `PATCH /api/v1/profile` (auth:sanctum, customer-only) updating
  `first_name`, `middle_name`, `last_name`, `address` on the authenticated
  user, reusing the existing `CompleteProfileRequest` validation contract
  (identical shape: first/last/address required, middle name nullable).
- Backend: guard `POST /api/v1/profile/complete` to return `409` when the
  profile is already complete (`first_name` and `last_name` both already set),
  so the one-time endpoint can no longer double as a general editor.
- Frontend: `AccountPage`'s customer branch gains an editable form, pre-filled
  with the current profile, using the same inline-error pattern (422 field
  mapping + generic fallback) already used by `ProfileSetupPage` and
  `AdminAccountsPage`.
- Frontend: a small `toNullableField` helper (+ test) so a cleared optional
  middle name is sent as `null`, not an empty string, avoiding the class of
  bug recorded in finding F-17.

## Out of scope

- Editing the phone number - it's the login identity and is never editable
  here.
- Any change to admin login, admin accounts, or the admin branch of
  `AccountPage` - untouched.
- Wiring the profile `address` into `bookings.delivery_address` - the project
  overview lists this as a separate, undecided idea; a booking's delivery
  address stays entered per-order.
- Retroactively fixing F-17 in `ProfileSetupPage.tsx` - that's a separate,
  pre-existing finding on different code; this feature's new form avoids
  repeating the same bug class but does not touch that file.
- SMS verification and any other v2 concern - unrelated to this feature.

## Build loop

Per `blueprint/config.json`: `stepReview: "feature"` (one review packet with
the full diff after all steps below pass) and `checkpointCommits: "disabled"`.
`/complete` makes the final feature commit.

## Build steps

- [x] 1. **Profile update endpoint + one-time guard (backend).** Add
      `ProfileController::update(CompleteProfileRequest $request)`: same body
      as `complete()` (`$user->update($request->validated())`, return
      `UserResource`). New route:
      `Route::patch('/profile', [ProfileController::class, 'update'])->middleware('auth:sanctum')`.
      In `complete()`, add a guard at the top: if `$user->first_name !== null
      && $user->last_name !== null`, abort with `409` before touching the
      request body. Add a `completeProfile()` state to `UserFactory` (sets
      `first_name`, `middle_name`, `last_name`, `address` to sample values) for
      tests that need an already-set-up customer. New
      `tests/Feature/Auth/ProfileUpdateTest.php`: happy path (200, updated
      fields in the response and DB); clearing `middle_name` by sending
      explicit `null` (200, DB shows null); missing `first_name`/`last_name`/
      `address` rejected (422); unauthenticated rejected (401); an
      authenticated admin rejected (403). Add
      `test_profile_completion_rejects_when_already_complete` to
      `ProfileSetupTest.php` (customer via the new factory state, `POST
      /api/v1/profile/complete` again, expect `409`).
      **Done when:** `composer test` passes including the new/updated tests;
      a complete customer gets `200` from `PATCH /api/v1/profile` and `409`
      from `POST /api/v1/profile/complete`.
- [x] 2. **API client + hook (frontend).** In `api.ts`, add
      `UpdateProfilePayload` (`first_name: string`, `middle_name: string |
      null`, `last_name: string`, `address: string`) and `updateProfile(payload)`
      posting `PATCH /profile`. In `hooks.ts`, add `useUpdateProfile()`
      mirroring `useCompleteProfile()` (`onSuccess` sets the `['me']` query
      cache to the returned user).
      **Done when:** `npm run build` passes with no dangling imports.
- [x] 3. **`toNullableField` helper (frontend).** In `profile.ts`, add
      `toNullableField(value: string | undefined): string | null` - returns
      `null` for an empty or whitespace-only value, otherwise the trimmed
      string. Add cases to `profile.test.ts`: empty string -> `null`,
      whitespace-only -> `null`, a real value -> the trimmed value unchanged.
      **Done when:** `npm run test` passes including the new cases.
- [x] 4. **Editable account form (frontend).** Rewrite the customer branch of
      `AccountPage.tsx`: an RHF + Zod form (same field set and validation as
      `ProfileSetupPage`) with `defaultValues` pre-filled from `me`
      (`middle_name` defaults to `''` for the input), a "Save changes" button
      calling `useUpdateProfile()` with `middle_name` passed through
      `toNullableField` before submit, the same 422-field-mapping +
      `getGenericErrorMessage` fallback pattern as the other forms, and a
      brief inline success message after a successful save. Keep the phone
      number displayed read-only above the form for context, and leave the
      logout button and the admin branch (`me.role !== 'customer'`) exactly as
      they are today.
      **Done when:** `npm run build` passes; manual walkthrough (below)
      confirms the edit/save round-trip, the cleared-middle-name case, and the
      422/error paths.

## Files / areas

Backend:
- `backend/app/Http/Controllers/Api/Customer/ProfileController.php`
- `backend/routes/api.php`
- `backend/database/factories/UserFactory.php`
- `backend/tests/Feature/Auth/ProfileSetupTest.php`
- `backend/tests/Feature/Auth/ProfileUpdateTest.php` (new)

Frontend:
- `frontend/src/features/auth/{api,hooks,types}.ts` (`types.ts` unchanged
  unless a mismatch turns up)
- `frontend/src/features/auth/profile.ts`, `profile.test.ts`
- `frontend/src/features/auth/AccountPage.tsx`

## Data / contracts

- `PATCH /api/v1/profile` -> `{first_name, middle_name: string | null,
  last_name, address}` => `200` + `UserResource`, or `422` field errors.
  `401` if unauthenticated, `403` if not a customer. Always operates on the
  authenticated user - no id parameter, no cross-user access is possible.
- `POST /api/v1/profile/complete` -> unchanged request/response shape, now
  additionally returns `409` (no body assumptions beyond that status) when
  `first_name` and `last_name` are both already set on the authenticated
  user.

## Testing

Backend test gate is already on; steps 1 ships feature tests in the same step
- see its "Done when." Frontend logic gets a Vitest unit test (step 3); the
form/routing step (4) is verified by build success plus the manual browser
walkthrough below, matching how Features 1 and 2 verified their UI steps. No
Browser tests command is configured, so no automated browser coverage is
added.

**Manual walkthrough:** log in as a customer who already completed profile
setup -> land on `/account` -> see the current first/middle/last name and
address pre-filled in the form, phone shown read-only above it -> clear the
middle name, change the last name, save -> see the updated name reflected on
the page and a success message -> refresh the page -> confirm the changes
persisted (middle name still blank) -> log out.

## Notes for the AI

- `PATCH /api/v1/profile` and `POST /api/v1/profile/complete` intentionally
  share `CompleteProfileRequest` - their validation contract is identical;
  only the controller-level guard differs. Don't create a second, duplicate
  request class.
- Step 1 closes ledger finding F-15. Mark it `fixed` (not `closed`) when this
  step lands; a later `/audit` re-review closes it.
- The new `toNullableField` helper exists so this new form doesn't repeat the
  bug class recorded in F-17 (blank optional field submitted as `''`). It is
  not a retroactive fix for `ProfileSetupPage.tsx`, which is untouched.
- No toast library is installed anywhere in this codebase; keep the inline
  message pattern already used by `LoginPage`, `ProfileSetupPage`, and
  `AdminAccountsPage` rather than introducing one.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":8456,"specSha256":"bcce9c11aa836a2e8e00e105a0e29819610ef937624541fa4d77ed82bde60096","branch":"refs/heads/feature/customer-profile","head":"1caa5496f5b793742a94c1af1b11fe2b0ea69b3a","baseRef":"refs/heads/master","baseCommit":"18a5ca541cc4bc5f3d6a8e1bffce6c9848270d0d","sourceTree":"43bea781a3e02de91cc487b6027dd6a87704d455","absentOptional":[]} -->

## Findings

### 3/F-15 [P2] closed - Profile-completion endpoint has no guard against being re-invoked after the profile is already complete

**File:** backend/app/Http/Controllers/Api/Customer/ProfileController.php:9-14
**Found:** 2026-09-25 by /audit independent (scope: current; lens: security/quality)
**Why it matters:** The spec's Notes for the AI say `ProfileController::complete()` "is a one-time setup action, not a general profile editor," and Out of scope explicitly excludes "Editing the profile after initial setup - that's Feature 3." `ProfileController::complete()` (`$user->update($request->validated())`) and its route (`auth:sanctum` only, `backend/routes/api.php:24`) never check whether `first_name`/`last_name` are already set. The only place that enforces "one-time" is the frontend route guard (`ProfileSetupRoute.tsx`), which redirects a complete-profile customer away from `/profile-setup` in the browser. A customer who calls `POST /api/v1/profile/complete` directly (curl, devtools, a replayed request) after their profile is already complete can freely rewrite their own name and address at any time, ahead of and outside whatever contract Feature 3 is meant to define for profile edits. This does not expose another user's data (self-only), so it is not a P0/P1 authorization break, but it is a missing guard against a behavior the spec explicitly scoped out.
**Suggested fix:** In `ProfileController::complete()` (or `CompleteProfileRequest::authorize()`), reject the request (409 or 422) when `$user->first_name` and `$user->last_name` are already set, or explicitly decide this endpoint is allowed to double as an editor until Feature 3 ships and update the spec's Out-of-scope/Notes sections to match reality.
**Resolution:** Re-examined 2026-09-25 by /audit independent (Feature 3 "Customer profile" review; scope: current; lens: security). `ProfileController::complete()` (backend/app/Http/Controllers/Api/Customer/ProfileController.php:11-22) now guards at the top of the method: `if ($user->first_name !== null && $user->last_name !== null) { abort(409, 'Profile is already complete.'); }`, before `$user->update(...)` ever runs. `$user` is `$request->user()`, the same freshly authenticated-request model the update call itself uses, so the check cannot read stale or cached data, and it cannot be bypassed by request-body content since it runs before the update regardless of payload. The route (`backend/routes/api.php:24`) is unchanged (`auth:sanctum`), and `CompleteProfileRequest::authorize()` (customer-only) still gates the controller method before it executes, so an unauthorized caller gets 403 before ever reaching the 409 branch - no information leak. `backend/tests/Feature/Auth/ProfileSetupTest.php:101-118` (`test_profile_completion_rejects_when_already_complete`) exercises exactly this path: a customer created via the new `UserFactory::completeProfile()` state calls `POST /api/v1/profile/complete` again with different data, asserts `409`, and asserts the database still holds the original name (`Juan Dela Cruz`), confirming no partial write occurred. `composer test` passes (49/49) including this test. The original defect is gone and the repair introduces no new one (self-only scope preserved, no cross-user access, no new duplicate request class). Closed.

## Independent review

**Status:** passed
**Target commit:** 1caa5496f5b793742a94c1af1b11fe2b0ea69b3a
**Base commit:** 18a5ca541cc4bc5f3d6a8e1bffce6c9848270d0d
**Base ref:** master
**Spec hash:** bcce9c11aa836a2e8e00e105a0e29819610ef937624541fa4d77ed82bde60096
**Prepared by:** claude
**Builder model:** claude-sonnet-5
**Requested reviewer:** claude
**Requested model:** runtime default (exact model not known until reviewer starts)
**Requested execution:** automatic
**Requested at:** 2026-09-25T03:14:03Z
**Workflow:** regular
**Check required:** no
**Reviewer adapter:** claude
**Reviewer model:** claude-sonnet-5
**Reviewer context:** fresh subagent
**Actual execution:** automatic
**Reviewed at:** 2026-09-25T03:17:35Z
**Scope:** current
**Lenses:** quality, security, performance, tests
**Verdict:** passed
**Check result:** not-required

### Commands

- `cd backend && composer test`: pass (49 tests, 164 assertions)
- `cd frontend && npm run build`: pass (`tsc -b && vite build`)
- `cd frontend && npm run lint`: pass (one pre-existing `oxlint` warning in `src/components/ui/button.tsx:57`, unrelated to this diff and not touched by it)
- `cd frontend && npm run test`: pass (2 files, 12 tests)

### Evidence

- `backend/app/Http/Controllers/Api/Customer/ProfileController.php:11-30`: `update()` reuses `CompleteProfileRequest` (no duplicate request class); `complete()`'s new guard reads `$user->first_name`/`last_name` from `$request->user()` (the same fresh per-request model the update call uses) and aborts 409 before any write.
- `backend/app/Http/Requests/Customer/CompleteProfileRequest.php:13-16`: `authorize()` (`role === 'customer'`) gates both `complete()` and `update()` since both type-hint this request; an unauthorized caller gets 403 before the 409 branch is reachable, so no information leak.
- `backend/routes/api.php:24-25`: `PATCH /api/v1/profile` and `POST /api/v1/profile/complete` both only carry `auth:sanctum`; both controller methods operate solely on `$request->user()` with no id parameter, so no cross-user access is possible.
- `backend/tests/Feature/Auth/ProfileUpdateTest.php:20-99`: covers 200 happy path, explicit-null clears `middle_name` (200 + DB null), missing required fields (422), unauthenticated (401), admin (403) - matches the spec's Data/contracts section.
- `backend/tests/Feature/Auth/ProfileSetupTest.php:101-118`: `test_profile_completion_rejects_when_already_complete` uses the new `UserFactory::completeProfile()` state, re-invokes `/profile/complete`, asserts 409, and asserts the DB still holds the original name (no partial write).
- `backend/database/factories/UserFactory.php:62-71`: `completeProfile()` state used consistently by both new/updated test files.
- `frontend/src/features/auth/profile.ts:6-9` and `profile.test.ts:38-56`: `toNullableField` has real assertions (empty string, whitespace-only, real value trimmed, undefined) against its own black-box behavior, not implementation-mirroring.
- `frontend/src/features/auth/AccountPage.tsx:46`: `middle_name: toNullableField(values.middle_name)` is routed through the helper before `updateProfile.mutate(...)`, avoiding the F-17 bug class (blank field submitted as `''`) in this new form; the field is also pre-filled with `me.middle_name ?? ''` as a controlled default, unlike the uncontrolled-empty-string case F-17 describes in `ProfileSetupPage.tsx` (untouched by this diff, correctly out of scope).
- `frontend/src/features/auth/AccountPage.tsx:52-63` matches the 422-field-mapping + `getGenericErrorMessage` fallback pattern in `ProfileSetupPage.tsx:39-49` and `AdminAccountsPage.tsx` (`isAxiosError` + 422 `errors` map + `setError`, else generic fallback).
- `frontend/src/features/auth/api.ts:36-48` and `hooks.ts:42-51`: `UpdateProfilePayload`/`updateProfile()` and `useUpdateProfile()` mirror the existing `completeProfile`/`useCompleteProfile` shape; `onSuccess` sets the `['me']` query cache as specified. No dangling imports; `npm run build` is clean.
- No unrelated files outside the 11-file diff were reviewed.

### Findings

- F-15 (closed this pass)

### Remaining risk

- Frontend `AccountPage.tsx` step 4's manual browser walkthrough (edit/save round-trip, cleared-middle-name persistence across refresh, 422/error paths) was not run in this review; `Check required` is `no` for this request and the project's Check gate is `manual`, so this is expected, not a gap in this receipt. `npm run build` plus the backend/frontend automated evidence above cover the reachable logic paths.
- `ProfileController::complete()` validates the request body (via `CompleteProfileRequest`) before the controller body's 409 guard runs, so a complete-profile customer sending an invalid payload directly to `POST /api/v1/profile/complete` gets `422` rather than `409`. This is standard Laravel FormRequest ordering, not a spec violation (the spec does not define precedence between the two), and it does not affect authorization or data integrity, so it is not recorded as a ledger finding.
