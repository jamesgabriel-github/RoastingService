# Fix: Inventory tab redesign - table + view/restock/adjust modal

**Type:** Fix
**Status:** verified
**Branch:** fix/inventory-tab-redesign

## The problem

`frontend/src/features/inventory/InventoryPage.tsx` renders the admin
Inventory tab as two plain tables: the top one (`InventoryRow`, lines 8-90)
has always-visible inline `qty`/`remarks` inputs plus Restock/Adjust buttons
on every row, with no search and no status styling beyond a plain text
low-stock label. The user shared a static design reference
(`generic_table_view.html`, untracked, Material 3-style mockup showing a
table + search bar + unified create/view/edit modal pattern) and asked for
that pattern adapted into Inventory, matching the precedent already set by
`blueprint/history/fixes/services-tab-redesign.md`.

Inventory items are not created here - they're existing `Service` records
with `stock_qty`/`low_stock_threshold`, created via the Services tab. So the
mockup's Create mode doesn't apply; the modal's three modes are **View /
Restock / Adjust** instead of Create/View/Edit.

## The fix

Rework the top table + its inline row form in `InventoryPage.tsx` into a
search-filtered table with a status pill and a single Dialog-driven modal,
using this project's existing shadcn/Base UI primitives (`Dialog`, `Button`,
`Input`, `Label`) - no new dependencies, no toast library, no new shared
Table/Modal/Badge component. Reuse the existing `useInventory`/restock/adjust
hooks and `fetchInventory`/`restockService`/`adjustService` API calls in
`frontend/src/features/inventory/api.ts` unchanged; no backend changes.

The bottom `InventoryLogTable` (lines 92-151, paginated read-only log) has no
equivalent in the mockup or in Services and stays as a separate table below,
untouched beyond incidental layout alignment.

## Build steps

- [x] 1. **Dialog-driven view/restock/adjust modal** - replaced each row's
  always-visible inline `qty`/`remarks` inputs and Restock/Adjust buttons
  with three row actions (View, Restock, Adjust) that open a single `Dialog`
  reused across modes via a `mode: 'view' | 'restock' | 'adjust'` state plus
  `activeService: Service | null`. View mode shows read-only service name,
  current stock, status pill, and threshold, with footer actions "Switch to
  Restock" / "Switch to Adjust" / Close. Restock mode shows a
  quantity-to-add number input and optional remarks, submitting via the
  existing `restockService` call. Adjust mode shows a change-in-quantity
  number input (positive to add, negative to reduce - matches the existing
  `AdjustPayload.change_qty` delta contract, which has no `reason` field) and
  optional remarks, submitting via the existing `adjustService` call.
  Closing the modal resets all modal state. Kept the existing raw-state
  qty/remarks validation from the old `InventoryRow` instead of introducing
  react-hook-form/zod, since the form is a simple two-field one (no need for
  the heavier pattern `ServicesPage.tsx` uses for its larger form).
  **Done when:** `npx tsc --noEmit -p tsconfig.app.json` passes and manual
  view/restock/adjust/switch-mode flows behave as specced (see Verify).
  **Status:** done - typecheck passes.

- [x] 2. **Search + status pill on the table** - added a client-side search
  `Input` filtering the already-fetched inventory list by service name, and
  replaced the plain-text low-stock indicator with an inline status pill
  (following the same self-contained pill convention as
  `ServicesPage.tsx`'s `StatusPill` - no new shared Badge component).
  **Done when:** typing in the search box filters rows by name client-side;
  the status pill renders correctly for both low-stock and in-stock states.
  **Status:** done - implemented together with step 1 in the same file
  rewrite; typecheck passes.

- [x] 3. **Transient success feedback** - added a page-level
  `successMessage` string cleared by a 3s `setTimeout`, shown as a small
  banner under the page heading on successful restock/adjust (not a toast
  system - matches the Services fix's precedent).
  **Done when:** restocking or adjusting a service shows the banner and it
  clears itself after ~3s.
  **Status:** done - implemented together with step 1 in the same file
  rewrite; typecheck passes.

## Verify

- `npx tsc --noEmit -p tsconfig.app.json` (from `frontend/`): pass, no errors.
- `npm run lint` (from `frontend/`): pass - only the same pre-existing
  `incompatible-library`/`only-export-components` warnings on unrelated files
  that existed before this change.
- `npm run test -- --run` (from `frontend/`): pass - 9 test files, 53/53
  tests (unchanged; no existing test targets this page).
- `npm run build` (from `frontend/`): pass, production build compiles clean
  (pre-existing chunk-size warning only, unrelated to this change).
- Manual (not yet performed - no browser automation tool available in this
  session): run `php artisan serve` + `npm run dev`, open `/admin/inventory`
  as an admin/super_admin account, and walk through view, view ->
  switch-to-restock, view -> switch-to-adjust, restock, adjust (including a
  validation error case, e.g. qty 0), and search. Confirm the inventory log
  table below still renders and paginates correctly. Flag as a `/check guide`
  candidate if a human walkthrough hasn't happened yet.

## Independent review

Not required. `qualityGates.regular.independentReview` is `when-sensitive`;
this change touches no auth, payments, or shared-data-integrity surface
beyond the existing, already-permission-gated restock/adjust endpoints -
it's a frontend UI rework reusing existing hooks unchanged. `/audit
independent current` remains available on request.

## Files touched

- `frontend/src/features/inventory/InventoryPage.tsx`

Reference-only (untracked, not part of this commit, matching the
`services-tab-redesign`/`walk-in-counter-redesign` fixes' convention):
`generic_table_view.html`.
