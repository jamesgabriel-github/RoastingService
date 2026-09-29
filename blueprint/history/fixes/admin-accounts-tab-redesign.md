# Fix: Admin accounts tab redesign - table + create/view/edit modal

**Type:** Fix
**Status:** verified
**Branch:** fix/admin-accounts-tab-redesign

> **Note for `/implement`:** the code below was already written and verified
> in chat, ahead of the branch being created (see
> `blueprint/context/ai-interaction.md` conventions). All build steps below
> were implemented directly on `master`, uncommitted, then reconciled onto
> this branch: create the branch, re-verify each done-when against the
> existing diff, and commit rather than re-implementing from scratch.

## The problem

`AdminAccountsPage.tsx` (the admin Accounts tab) still had the old shape both
the Services (`blueprint/history/fixes/services-tab-redesign.md`) and
Inventory (`blueprint/history/fixes/inventory-tab-redesign.md`) tabs started
from before their redesigns: a plain table with no search, permissions edited
inline per-row via raw checkboxes plus a "Save permissions" button, status
shown as plain text, and an always-visible inline create form below the table
instead of a modal. The user asked for the same table + modal pattern already
applied to Services and Inventory to be adopted here too.

## The fix

Reworked `AdminAccountsPage.tsx` in place to match the established pattern,
using this project's existing shadcn/Base UI primitives (`Dialog`, `Button`,
`Input`, `Label`) - no backend changes, no new dependencies. The existing
`AdminAccount` type and `useAdminAccounts`/`useCreateAdminAccount`/
`useUpdateAdminAccount` hooks already covered everything needed; the update
endpoint only accepts `is_active` and `permissions` (confirmed via
`UpdateAdminAccountRequest`), so name/email stay create-only and are disabled
outside create mode.

## Build steps

- [x] 1. **Dialog-driven create/view/edit modal** - replaced the always-visible
  inline create form and the per-row inline permission checkboxes with a
  single `Dialog` reused for all three modes via a
  `mode: 'create' | 'view' | 'edit'` state plus `activeAccount: AdminAccount |
  null` (`null` = create), mirroring `ServicesPage.tsx`'s
  `openCreate`/`openView`/`openEdit`/`switchToEdit`/`closeModal` functions.
  Create mode exposes name/email/password plus permissions checkboxes (the
  API already accepted `permissions` on create; the old UI never exposed it).
  View mode disables every field and has no password field. Edit mode keeps
  name/email disabled (the backend can't update them) and only permissions
  are editable. 422 field errors map the same way as the prior create flow.
  **Done when:** `npx tsc --noEmit -p tsconfig.app.json` passes and manual
  create/view/edit/switch-to-edit flows behave as specced (see Verify).

- [x] 2. **Search + status pill on the table** - added a client-side search
  `Input` filtering the already-fetched `accounts` list by name/email, and
  replaced the plain-text Active/Disabled cell with an inline status pill
  (`bg-primary/10 text-primary` / `bg-muted text-muted-foreground`,
  self-contained in this file, same convention as `ServicesPage.tsx`'s
  `StatusPill` - no new shared Badge component). The Permissions column now
  shows a comma-joined module list read from the account record.
  **Done when:** typing in the search box filters rows by name/email
  client-side; the status pill renders correctly for both states.

- [x] 3. **Row-level Enable/Disable stays outside the modal** - kept the
  `is_active` toggle as a direct row action (submits immediately via
  `useUpdateAdminAccount`), matching Services' Activate/Deactivate. Dropped
  the old "Save permissions" row button since permissions now live in the
  modal.
  **Done when:** clicking Enable/Disable on a row toggles status without
  opening the modal.

- [x] 4. **Transient success feedback** - added a page-level `successMessage`
  string cleared by a 3s `setTimeout`, shown as a small `bg-primary/10`
  banner under the page heading on successful create/update (same pattern as
  Services and Inventory - not a toast system/library).
  **Done when:** creating or updating an account shows the banner and it
  clears itself after ~3s.

## Verify

- `npx tsc --noEmit -p tsconfig.app.json` (from `frontend/`): pass, no errors.
- `npm run lint` (from `frontend/`): pass - only pre-existing
  `incompatible-library` warnings already present on sibling pages (Services,
  bookings, orders, walk-in counter).
- `npm run test -- --run` (from `frontend/`): pass - 9 test files, 53/53
  tests (unchanged from before this fix; no existing test targets this page).
- `npm run build` (from `frontend/`): pass, production build compiles clean.
- Manual (not yet performed - no browser automation tool was available in
  this session): run `php artisan serve` + `npm run dev`, open
  `/admin/accounts` as an admin/super_admin account, and walk through search
  filtering, create (with permissions selected), view, view -> switch-to-edit,
  edit permissions (including a 422 case), and Enable/Disable. Flag as a
  `/check guide` candidate if a human walkthrough hasn't happened yet.

## Independent review

Not requested. `qualityGates.regular.independentReview` is `when-sensitive`
and this change carries none of the sensitive-work triggers (no auth,
payments, security boundary, or shared-data-integrity surface touched) - it's
a self-contained frontend UI rework of one already-permission-gated admin
page, reusing existing hooks/endpoints unchanged. `/audit independent current`
remains available on request.

## Files touched

- `frontend/src/features/admin-accounts/AdminAccountsPage.tsx`

Reference-only (untracked, not part of this commit, matching the
`services-tab-redesign`/`inventory-tab-redesign` fixes' convention):
`generic_table_view.html`.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":5718,"specSha256":"2bb8ea9293bf214c2fc4071090bd19828bd1550699159c48ab58fb39967493be","branch":"refs/heads/fix/admin-accounts-tab-redesign","head":"549fede61c47f75efe0080a0a7501b6c9c688ed6","baseRef":"refs/heads/master","baseCommit":"549fede61c47f75efe0080a0a7501b6c9c688ed6","sourceTree":"4438dbe4a657960b523f865ffb2170c31251faae","absentOptional":[]} -->
