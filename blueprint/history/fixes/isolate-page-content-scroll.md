# Fix: Isolate page content scroll so the sidebar nav and logout button stay put

**Type:** Fix
**Status:** verified
**Branch:** fix/isolate-page-content-scroll

## The problem

`AdminLayout` (`frontend/src/components/layouts/AdminLayout.tsx`) wraps the
sidebar (`<aside>`) and the page content (`<main>`) in a `flex min-h-svh`
row. `min-h-svh` only sets a *minimum* height, so once a page's content is
taller than the viewport, the flex row grows to fit it and (by the default
`align-items: stretch`) the `<aside>` stretches to match that full height too.
The whole document then scrolls together, and the sidebar's "Log out" button
— pinned to the bottom of the `<aside>` via `flex flex-1` on the nav above it
— ends up pushed far down the page instead of staying visible near the
viewport bottom. The nav links have the same problem: they scroll out of
view with the page instead of staying in place.

`CustomerLayout` (`frontend/src/components/layouts/CustomerLayout.tsx`) has
the same root pattern (header nav + `<main>` in a document that scrolls as
one), so its top nav scrolls away with long page content too.

## The fix

Make each layout's outer container a fixed viewport-height flex box
(`h-svh` instead of `min-h-svh`) and give only the `<main>` content region
its own scroll (`overflow-y-auto`). The nav chrome (sidebar in `AdminLayout`,
header in `CustomerLayout`) then stays fixed within the viewport — never
stretching or scrolling away — while long page content scrolls inside its
own region.

- `AdminLayout.tsx`: change the outer `div` from `flex min-h-svh` to
  `flex h-svh overflow-hidden` (or equivalent), keep `<aside>` as a
  fixed-height column that does not stretch past the viewport, and add
  `overflow-y-auto` to `<main>`.
- `CustomerLayout.tsx`: same pattern — outer `div` fixed to viewport height,
  header stays put, `<main>` scrolls independently.
- Must not break: existing nav active-state styling, the admin sidebar's
  permission-gated links, the logout button's disabled/pending state, and
  responsiveness of the admin sidebar width (`w-56`).
- No new dependencies, abstractions, or components — this is a Tailwind
  class change to two existing layout files.

## Build steps

1. [x] Update `AdminLayout.tsx` and `CustomerLayout.tsx` so the nav chrome is
   viewport-fixed and `<main>` scrolls independently.
   **Done when:** on a page with content taller than the viewport (e.g. a
   long admin table), the sidebar (nav links + Log out button) and the
   customer header stay fully visible and un-scrolled while only the page
   content scrolls.

## Verify

- Open an admin page with a long list (e.g. Inventory or Admin accounts)
  with enough rows to overflow the viewport. Scroll the content: the
  sidebar nav and "Log out" button must stay visible and fixed in place,
  and the "Log out" button must still work (redirects to `/admin/login`).
- Open a customer page with long content (e.g. My Bookings with several
  bookings). Scroll the content: the header ("My Account" + nav links) must
  stay visible and fixed in place.
- Confirm no page that previously fit within the viewport now shows an
  unwanted scrollbar or clipped content.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":3196,"specSha256":"6efe912e5e01070c631e8f2ccb923a4d6d9ce0ed32ad8c229c5e54d79652f28e","branch":"refs/heads/fix/isolate-page-content-scroll","head":"f6309448545e427d2d427961871f09258ba9d1bb","baseRef":"refs/heads/master","baseCommit":"f6309448545e427d2d427961871f09258ba9d1bb","sourceTree":"70345a8bfc34b21876c208fb7410ce4016642bb5","absentOptional":[]} -->
