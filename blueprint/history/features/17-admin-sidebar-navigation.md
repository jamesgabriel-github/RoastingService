# Feature: Admin sidebar navigation

**From build-plan:** feature 17
**Build attempt:** 1
**Branch:** feature/admin-sidebar-navigation
**Status:** verified

## Goal

Replace the admin topbar with a persistent left sidebar in a fixed order
(Dashboard, Booking with a status sub-menu, Services, Inventory, Payments,
Admin accounts), reusing the existing per-module permission gating, and make
the booking status sub-menu link to bookmarkable URLs on the existing bookings
queue page.

## In scope

- Rebuild `AdminLayout` as a persistent left sidebar (not a collapsible or
  toggleable one) containing, in this fixed order: brand link to `/admin`,
  Dashboard, Booking (with a status sub-menu), Services, Inventory, Payments,
  Admin accounts.
- Reuse the exact `me.role` / `me.permissions` checks already in the current
  topbar to decide which top-level items render, one check per item (a
  `super_admin` always sees all six; an `admin` sees only the modules in
  `me.permissions`).
- Booking's sub-menu lists the seven queue statuses already defined in
  `QUEUE_TABS` (`pending_review`, `pending_confirmation`, `approved`,
  `confirmed`, `cooking`, `ready`, `out_for_delivery`) and links to
  `/admin/bookings?status=<status>`.
- Make `AdminBookingsPage` read its active tab from the `status` query
  parameter (falling back to `pending_review` when missing or invalid) and
  push tab changes into the URL, so the sidebar links and a bookmarked/shared
  URL land on the right tab.
- Move the existing log out button into the sidebar since the topbar it lived
  in is removed.

## Out of scope

- Any change to the `/admin` root placeholder, dashboard content, or the
  internals of Services/Inventory/Payments/Admin accounts pages beyond being
  rendered under the new layout.
- Collapsible/toggleable sidebar behavior, a mobile hamburger menu, or new
  responsive breakpoints; the project overview already treats admin as
  desktop/tablet-first.
- Booking counts or badges in the sidebar sub-menu; the plan item only asks
  for links, and counts are not part of this contract.
- Making the bookings page's search box or date filter URL-driven; only the
  status tab needs to be bookmarkable.
- Any backend or API change; this is routing and layout only.

## Build loop

One reviewed step at a time per `blueprint/config.json`
(`workflow.stepReview: "feature"`, `workflow.checkpointCommits: "disabled"`):
implement both steps below, then present one combined review packet. No
per-step checkpoint commits; `/complete` creates the final feature commit.

## Build steps

- [x] 1. Make the bookings queue status bookmarkable via URL
  - Add a small pure helper (e.g. in `frontend/src/features/admin-bookings/types.ts`)
    that validates a raw `status` string against `QUEUE_TABS` and returns it,
    or `pending_review` when missing/invalid.
  - Wire `AdminBookingsPage` to `useSearchParams` from `react-router-dom`:
    initialize the active tab from the `status` param through that helper, and
    call `setSearchParams` (not just local state) when a tab is clicked.
  - Add a unit test for the helper: a valid status passes through unchanged;
    an invalid or missing value falls back to `pending_review`.
  - Done when: opening `/admin/bookings?status=cooking` directly loads the
    Cooking tab, clicking a different tab updates the URL to match, and
    `npm run test` passes.

- [x] 2. Replace the admin topbar with a persistent left sidebar
  - Rebuild `AdminLayout` (`frontend/src/components/layouts/AdminLayout.tsx`)
    as a fixed-width left sidebar plus a right-hand content area rendering
    `Outlet`, in place of the current `<header>` topbar.
  - Sidebar contents, top to bottom: brand link to `/admin`, Dashboard,
    Booking (expanded, with its 7 status sub-links), Services, Inventory,
    Payments, Admin accounts, then the log out button at the bottom.
  - Reuse the current topbar's exact gating conditions per item (same
    `me.role`/`me.permissions` checks already present), so visible items are
    unchanged by role/permissions, only their layout and grouping.
  - Done when: a super_admin account sees all six items in the fixed order; an
    admin account scoped to a subset of modules sees only its permitted
    top-level items (verified against seeded accounts via the dev server);
    each booking sub-link opens `/admin/bookings` on the matching tab; and
    `npm run build` succeeds.

## Files / areas

- `frontend/src/components/layouts/AdminLayout.tsx` - rebuild as sidebar
- `frontend/src/features/admin-bookings/AdminBookingsPage.tsx` - URL-driven tab
- `frontend/src/features/admin-bookings/types.ts` - status parse/validate helper
- `frontend/src/features/admin-bookings/types.test.ts` (new) - helper unit test

## Data / contracts

- New frontend-only URL contract: `/admin/bookings` accepts an optional
  `status` query parameter, one of the `QueueStatus` values in `QUEUE_TABS`;
  any other value or its absence behaves as `pending_review`. No backend or
  API change.

## Testing

- Unit test (Vitest, `npm run test`) for the new status-parsing helper:
  valid value passes through, invalid/missing value defaults to
  `pending_review`.
- No browser-automation coverage: no `Browser tests` command is declared in
  `AGENTS.md`. Verify the sidebar and gating manually against the running dev
  server (`/check`) using the seeded super_admin and a scoped admin account.

## Notes for the AI

- No shadcn `sidebar` primitive is installed (`frontend/src/components/ui/`
  only has `button`, `card`, `input`, `label`). Build the layout with existing
  Tailwind utility classes and the existing `Button` component; do not add a
  new dependency for this.
- `QUEUE_TABS` in `frontend/src/features/admin-bookings/types.ts` is the
  existing single source of truth for queue statuses, labels, and order; reuse
  it for the sidebar sub-menu instead of duplicating the list.
- Use a query parameter (`?status=`), not a path segment, for the booking
  status: `/admin/bookings/:id` is already a registered route for the booking
  detail page, so a path segment like `/admin/bookings/cooking` would collide
  with it.
- This is nav-link visibility only; the actual authorization boundary is
  unchanged and stays enforced by `RoleRoute` (route-level) and the backend
  policy/gate per module. Do not weaken or duplicate that check in the
  sidebar.
- The current topbar's "Admin" brand link, the six existing route paths, and
  `RoleRoute`'s `allow`/`requireModule` wiring in `App.tsx` do not need to
  change; only their presentation moves from a topbar into a sidebar.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":6604,"specSha256":"a92f1c6ff986ef718ecb0695929b314f1d9454a5041374d6cec10cf841b486ab","branch":"refs/heads/feature/admin-sidebar-navigation","head":"e2f3b1eb87476bd484328b43752cfc4c5750fd24","baseRef":"refs/heads/master","baseCommit":"e2f3b1eb87476bd484328b43752cfc4c5750fd24","sourceTree":"cc99421b28a489f3c52c2bc93e86d98783283d6b","absentOptional":[]} -->
