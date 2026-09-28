# Fix: Services tab redesign - table + create/view/edit modal

**Type:** Fix
**Status:** verified
**Branch:** fix/services-tab-redesign

> **Note for `/implement`:** the code below was already written and verified
> in chat, ahead of the branch being created (see
> `blueprint/context/ai-interaction.md` conventions). All build steps below
> were implemented directly on `master`, uncommitted, then reconciled onto
> this branch: create the branch, re-verify each done-when against the
> existing diff, and commit rather than re-implementing from scratch.

## The problem

`ServicesPage.tsx` (the admin Services tab) rendered a plain, always-visible
inline form below the table shared for both create and edit, with no search,
no status badge, and no read-only "view" mode at all. The user shared a static
design reference (`generic_table_view.html`, untracked, Material 3-style
mockup showing a table + search bar + unified create/view/edit modal pattern)
and asked for that pattern adapted into the Services tab.

## The fix

Rework `ServicesPage.tsx` in place to match the reference pattern using this
project's existing shadcn/Base UI primitives (`Dialog`, `Button`, `Input`,
`Label`) instead of the mockup's Material 3 styling. No backend changes: the
existing `Service` type, and the existing `useServices`/`useCreateService`/
`useUpdateService`/`useToggleService` hooks already cover everything needed.
No new dependencies (no toast library, no new Select/Textarea/Checkbox/Table/
Badge components) - reused the codebase's existing raw-checkbox and
inline-pill conventions instead.

## Build steps

- [x] 1. **Dialog-driven create/view/edit modal** - replaced the always-visible
  inline form with a single `Dialog` reused for all three modes via a
  `mode: 'create' | 'view' | 'edit'` state plus `activeService: Service | null`
  (`null` = create). `openCreate`/`openView`/`openEdit` set mode and reset the
  form; `switchToEdit` flips an open view dialog to edit without re-fetching;
  `closeModal` (wired to `Dialog`'s `onOpenChange`) resets all modal state.
  View mode disables every field (reusing `Input`'s existing disabled
  styling) and swaps the footer for Close/"Switch to Edit"; create/edit keep
  the existing zod schema, conditional rate/price fields, and 422-to-field
  error mapping unchanged. View/edit also show a read-only stock/status
  block (`stock_qty`, `is_low_stock`, `is_active`) that isn't part of the
  editable payload.
  **Done when:** `npx tsc --noEmit -p tsconfig.app.json` passes and manual
  create/view/edit/switch-to-edit flows behave as specced (see Verify).

- [x] 2. **Search + status pill on the table** - added a client-side search
  `Input` filtering the already-fetched `services` list by name and type
  label, and replaced the plain-text Active/Inactive cell with an inline
  status pill (`bg-primary/10 text-primary` / `bg-muted text-muted-foreground`,
  self-contained in this file - no new shared Badge component, no precedent
  for one elsewhere in the app). Added a "Low" stock flag next to the stock
  count. Kept the row-level Activate/Deactivate action outside the modal,
  unchanged from before. No pagination added (list is already small and
  unpaginated on the backend).
  **Done when:** typing in the search box filters rows by name/type
  client-side; the status pill renders correctly for both states.

- [x] 3. **Transient success feedback** - added a page-level
  `successMessage` string cleared by a 3s `setTimeout`, shown as a small
  `bg-primary/10` banner under the page heading on successful create/update
  (not a toast system/library - no precedent or need for one here).
  **Done when:** creating or updating a service shows the banner and it
  clears itself after ~3s.

- [x] 4. **Removed the Types column** - follow-up request: dropped the
  "Types" column from the table (header + row cell) since it was redundant
  with the Rate/kg and Price columns already showing which mode(s) a service
  supports; search still matches on type text under the hood via the
  existing `typesLabel()` helper, it's just no longer a visible column.
  **Done when:** the table renders without a Types column and search by
  "bring your own"/"shop stock" still filters correctly.

## Verify

- `npx tsc --noEmit -p tsconfig.app.json` (from `frontend/`): pass, no errors.
- `npm run lint` (from `frontend/`): pass - only pre-existing warnings
  unrelated to this diff (the same `incompatible-library` react-compiler note
  already present on sibling `react-hook-form` pages).
- `npm run test -- --run` (from `frontend/`): pass - 9 test files, 53/53
  tests (unchanged from before this fix; no existing test targets this page).
- `npm run build` (from `frontend/`): pass, production build compiles clean.
- Manual (not yet performed - no browser automation tool was available in
  this session or the builder's): run `php artisan serve` + `npm run dev`,
  open `/admin/services` as an admin/super_admin account, and walk through
  create, view, view -> switch-to-edit, edit (including a 422 case), search,
  and activate/deactivate. Flag as a `/check guide` candidate if a human
  walkthrough hasn't happened yet.

## Independent review

Not requested. `qualityGates.regular.independentReview` is `when-sensitive`
and this change carries none of the sensitive-work triggers (no auth,
payments, security boundary, or shared-data-integrity surface touched) - it's
a self-contained frontend UI rework of one already-permission-gated admin
page, reusing existing hooks/endpoints unchanged. `/audit independent current`
remains available on request.

## Files touched

- `frontend/src/features/services/ServicesPage.tsx`

Reference-only (untracked, not part of this commit, matching the
`walk-in-counter-redesign` fix's `code.html` convention):
`generic_table_view.html`.
