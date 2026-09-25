# Feature: Public landing page

**From build-plan:** feature 16
**Build attempt:** 1
**Branch:** feature/public-landing-page
**Status:** verified

## Goal

Replace the `/` placeholder (`<div>Roasting Service</div>` in `App.tsx`) with a
real public landing page: a hero with a Book Now call-to-action, a short about
section, a services card grid pulling from the existing public services
endpoint, a how-it-works section, and a location/contact section - so a visitor
can understand and start using the shop without logging in first.

## In scope

- A new `/` page rendered inside the existing `PublicLayout`, composed of:
  - **Hero** - headline, one-line value proposition, and a single "Book Now"
    CTA linking to the existing `/book` route (the `BookingTypePage` chooser).
    `/book` is already behind `ProtectedRoute`, which already redirects an
    anonymous visitor to `/login`; this feature does not add or change any
    auth/redirect behavior.
  - **About** - a short static paragraph describing the two things the shop
    does (roast a customer's own raw food, or sell in-stock roasted items),
    with pickup or delivery, grounded in the overview's Problem/Users copy.
  - **Services** - a responsive card grid fetched from the existing public
    `GET /api/v1/services` endpoint (shipped in Feature 4, no auth, already
    scoped to `is_active = true`). Each card shows the service name,
    description (when present), cook time, and whichever rate(s) apply
    (roasting rate per kg for bring-your-own, shop price per piece for shop
    stock), matching the build-plan line's "services cards with rates and
    cook times."
  - **How it works** - a short static ordered list grounded in the already-
    shipped booking/order status flows (book or order online, wait for
    admin approval/confirmation, we cook, pick up or get it delivered).
  - **Location/contact** - a static block for the shop's address, phone, and
    email, using clearly-labeled placeholder text (see Notes for the AI - no
    such data exists anywhere in this project yet).
- Loading, empty, and error states for the services card grid (the only part
  of the page that fetches data).
- Warm accent styling for this page only, using Tailwind's built-in `amber` /
  `orange` / `stone` palette directly in the new components - no changes to
  the shared shadcn theme tokens in `index.css` (see Notes for the AI).

## Out of scope

- Any backend change. `GET /api/v1/services` and `PublicServiceResource`
  already return everything this page needs; this is a frontend-only feature.
- Changing `PublicLayout`'s header/nav (still just the "Log in" link) or any
  other route.
- A "remember where I was going" return-to-`/book` flow after login -
  `LoginPage` already always routes to `/account` or `/profile-setup`; adding
  a return URL is a change to existing auth behavior, not this feature.
- Real shop address/phone/hours/social links, or any backend field/`settings`
  entry to store them - none exist today (see Notes for the AI).
- A contact form, map embed, testimonials, or gallery - not named in the
  build-plan line or the overview's UI/UX screens list.
- Retrofitting the shared warm-earthy-tone theme onto already-shipped pages
  (admin, customer, login) - out of this feature's blast radius.

## Build loop

Two build steps below. Per `blueprint/config.json`
(`workflow.stepReview: "feature"`), this spec is reviewed as one packet after
both steps are implemented, not paused after each step
(`workflow.checkpointCommits: "disabled"`, so no intermediate checkpoint
commits either). `/complete` creates the final commit after review.

## Build steps

- [x] 1. Landing data layer, hero, and about section
  - `frontend/src/features/landing/types.ts`: `LandingService` interface
    mirroring `PublicServiceResource` exactly - `id: number`, `name: string`,
    `description: string | null`, `roasting_rate_per_kg: string | null`,
    `shop_price: string | null`, `est_minutes: number`,
    `allow_customer_supplied: boolean`, `allow_shop_supplied: boolean`,
    `in_stock: boolean | null`.
  - `frontend/src/features/landing/api.ts`: `fetchActiveServices(): Promise<LandingService[]>`
    calling `api.get<LandingService[]>('/services')` - no filtering; the
    endpoint already scopes to active services.
  - `frontend/src/features/landing/hooks.ts`: `useActiveServices()` via
    `useQuery({ queryKey: ['landing-services'], queryFn: fetchActiveServices })`.
  - `frontend/src/features/landing/HeroSection.tsx`: headline + value prop +
    `<Button render={<Link to="/book" />}>Book Now</Button>` (same
    `render={<Link .../>}` pattern already used in `BookingTypePage.tsx`),
    styled with the `amber`/`orange`/`stone` Tailwind palette.
  - `frontend/src/features/landing/AboutSection.tsx`: static paragraph per
    the In-scope description above.
  - `frontend/src/features/landing/LandingPage.tsx`: composes
    `<HeroSection />` and `<AboutSection />` for now (Services/How-it-
    works/Contact are added in step 2).
  - Edit `frontend/src/App.tsx`: replace
    `<Route path="/" element={<div>Roasting Service</div>} />` with
    `<Route path="/" element={<LandingPage />} />`.
  - No new unit test: presentational composition plus a direct pass-through
    query, no new pure logic (per this project's test-scope rule).
  - Done when: visiting `/` on the dev server shows the hero with a working
    Book Now link (navigates to `/book`, which - unchanged - redirects an
    anonymous visitor to `/login` via the existing `ProtectedRoute`) and the
    about section renders; `npm run lint` and `npm run build` pass.

- [x] 2. Services cards, how it works, and location/contact
  - `frontend/src/features/landing/ServicesSection.tsx`: uses
    `useActiveServices()`. Renders a responsive grid of `Card`/`CardHeader`/
    `CardContent` per service: `name`, `description` (if present), cook time
    as `` `${service.est_minutes} min` `` (same convention as
    `ServicesPage.tsx`), and rate lines built with the existing
    `formatCurrency` from `lib/currency.ts` - `` `${formatCurrency(roasting_rate_per_kg)} / kg (bring your own)` ``
    when `allow_customer_supplied`, `` `${formatCurrency(shop_price)} / piece` ``
    when `allow_shop_supplied`, and a muted "Out of stock" badge when
    `allow_shop_supplied && in_stock === false`.
    - Loading (`isLoading`): a "Loading services…" placeholder, no layout
      shift into an empty grid.
    - Empty (`services?.length === 0`): "No services available right now."
    - Error (`isError`): "We couldn't load our services. Please try again
      shortly."
  - `frontend/src/features/landing/HowItWorksSection.tsx`: static ordered
    steps per the In-scope description above - no invented hours, fees, or
    policy details.
  - `frontend/src/features/landing/ContactSection.tsx`: static block with
    clearly-labeled placeholder copy (`[Shop address]`, `[Phone number]`,
    `[Email address]`) and a short code comment flagging it as placeholder
    content for the shop owner to fill in (see Notes for the AI - this is
    not a fabricated business address).
  - Edit `LandingPage.tsx`: compose the full page order - Hero, About,
    Services, How it works, Contact.
  - No new unit test: same reasoning as step 1.
  - Done when: `/` on the dev server shows service cards matching whatever
    active services exist in the local database (name, cook time, correct
    rate line(s), out-of-stock badge when applicable), the loading state is
    visible on first load, the empty state renders when no active services
    exist, and the error state renders when the request fails (verified by
    briefly stopping the backend or forcing a query error); how-it-works and
    contact sections render; `npm run lint` and `npm run build` pass.

## Files / areas

- New: `frontend/src/features/landing/types.ts`
- New: `frontend/src/features/landing/api.ts`
- New: `frontend/src/features/landing/hooks.ts`
- New: `frontend/src/features/landing/HeroSection.tsx`
- New: `frontend/src/features/landing/AboutSection.tsx`
- New: `frontend/src/features/landing/ServicesSection.tsx`
- New: `frontend/src/features/landing/HowItWorksSection.tsx`
- New: `frontend/src/features/landing/ContactSection.tsx`
- New: `frontend/src/features/landing/LandingPage.tsx`
- Edit: `frontend/src/App.tsx` (swap the `/` placeholder for `<LandingPage />`)

## Data / contracts

No backend or contract change. Reuses the existing, already-shipped
`GET /api/v1/services` -> `PublicServiceResource` shape unmodified:

```json
[
  {
    "id": 1,
    "name": "Whole Chicken",
    "description": "Marinated and roasted whole.",
    "roasting_rate_per_kg": "150.00",
    "shop_price": "450.00",
    "est_minutes": 45,
    "allow_customer_supplied": true,
    "allow_shop_supplied": true,
    "in_stock": true
  }
]
```

## Testing

- No new frontend unit tests. This feature is presentational composition of
  static content plus one direct pass-through query (`GET /services` ->
  `LandingService[]`, no new pure logic to test), consistent with
  `coding-standards.md`'s scope rule ("what not to test: UI components...
  verify by running the app with a screenshot and the build").
- Verified manually against the running dev server (`npm run dev`) with real
  seeded/active services data, plus `npm run lint` and `npm run build` for
  both steps. No browser test harness is configured in this project.

## Notes for the AI

- Reuse `formatCurrency` (`lib/currency.ts`) rather than hand-formatting
  currency, and the exact `` `${est_minutes} min` `` convention already used
  in `ServicesPage.tsx` rather than inventing a duration formatter.
- Reuse the `<Button render={<Link to="..." />}>` pattern already established
  in `BookingTypePage.tsx` for the Book Now CTA - this project's `Button`
  wraps `@base-ui/react/button`, which does not support a shadcn-style
  `asChild` prop.
- Warm "earthy tones" styling: use Tailwind's built-in `amber`/`orange`/
  `stone` color utilities directly on the new landing components. Do not add
  or change any `--color-*` custom property in `index.css` - those shared
  shadcn tokens currently render as plain grayscale and are used by every
  already-shipped screen (admin, customer, login); retheming them is a much
  larger, separate change outside this feature's scope and blast radius.
- Location/contact content: no shop address, phone number, business hours, or
  social links exist anywhere in this repository's data model, config, or
  planning docs. Render clearly-marked placeholder text instead of inventing
  real-looking contact details; do not wire this to the `settings` table
  (already flagged in the overview's Open questions as out of v1 scope for
  anything beyond the downpayment toggle).
- `GET /api/v1/services` is unauthenticated and already filters to
  `is_active = true` server-side (`ServiceController::index`); do not add a
  client-side active filter or re-request with credentials.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":10982,"specSha256":"e31d115dfb3fdb6b678d3244fd6c2e1df3a553bb5a8789e50d57448f5d9ae26c","branch":"refs/heads/feature/public-landing-page","head":"fb45f3d60d2c9221fb1ea49464a629f13868ed3c","baseRef":"refs/heads/master","baseCommit":"fb45f3d60d2c9221fb1ea49464a629f13868ed3c","sourceTree":"80f793011878e42c796b0ffa62fab18e022ba1b2","absentOptional":[]} -->
