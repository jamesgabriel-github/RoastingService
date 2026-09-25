# Feature: Sales dashboard

**From build-plan:** feature 15
**Build attempt:** 1
**Branch:** feature/sales-dashboard
**Status:** verified

## Goal

Give an admin with the `dashboard` module (or a super admin) a single screen
showing sales today/week/month split by booking type, a live count of bookings
per status, today's cooking/ready queue, top-selling items, and low-stock
alerts, so they no longer need to eyeball separate queues and the payments
list to gauge how the shop is doing.

## In scope

- One read-only aggregate endpoint, `GET /api/v1/admin/dashboard`, gated by
  `role:admin,super_admin` + `module:dashboard` (the `admin_permissions.module`
  enum already includes `dashboard`; only the route group is new).
- Sales totals for today, this current week, and this current month, each
  split by `bookings.source_type` (`customer_supplied` / `shop_supplied`) plus
  a combined total per period.
- A count of bookings per `bookings.status` value (all 11 statuses), reflecting
  current pipeline state, not date-scoped.
- The live cooking/ready queue: bookings currently in `cooking` or `ready`,
  each with code, customer/guest name, fulfillment, `cooking_started_at`,
  `est_ready_at`, and waiting minutes since that status started (same
  `waiting_minutes` convention as the existing booking queues).
- Top 5 items by quantity sold, counted from `booking_items` on `completed`
  bookings only (real fulfilled demand, not rejected/cancelled noise).
- Low-stock alert list: active, shop-supplied services where
  `stock_qty <= low_stock_threshold` (same rule as `Service::isLowStock()`).
- A new `/admin/dashboard` frontend route and page rendering all of the above
  as plain stat/table sections (matching this project's existing
  no-chart-library, table-first admin UI).
- A `Dashboard` nav link in `AdminLayout`, shown under the same
  `role === 'super_admin' || permissions.includes('dashboard')` rule already
  used for the other module links.

## Out of scope

- Charts/graphs (no charting library in this project; plain numbers and
  tables only, consistent with every other admin screen).
- Custom date ranges or a date picker; only the fixed today/week/month windows.
- CSV/export of dashboard data.
- Changing the `/admin` root route (stays the existing placeholder); dashboard
  lives at its own `/admin/dashboard` path.
- Auto-refresh/polling beyond TanStack Query's normal defaults (no dashboard-
  specific live-refresh requirement was stated).
- Any change to `payments.status` handling, refunds, or the `settings` table.
- Admin-account/permission management UI (the `dashboard` module toggle
  already exists in the admin-accounts screen from Feature 1).

## Build loop

Two build steps below. Per `blueprint/config.json`
(`workflow.stepReview: "feature"`), this spec is reviewed as one packet after
both steps are implemented, not paused after each step
(`workflow.checkpointCommits: "disabled"`, so no intermediate checkpoint
commits either). `/complete` creates the final commit after review.

## Build steps

- [x] 1. Backend: `GET /api/v1/admin/dashboard` aggregate endpoint
  - Add `App\Http\Controllers\Api\Admin\DashboardController@index`.
  - Sales: sum `payments.amount` where `payments.status = 'paid'`, grouped by
    the parent booking's `source_type`, for three `paid_at` windows computed
    from `now()` (app timezone, currently UTC - no shop-local timezone setting
    exists yet): `startOfDay()`, `startOfWeek()`, `startOfMonth()` through
    `now()`. Each period returns `customer_supplied`, `shop_supplied`, and
    `total` as decimal strings (`number_format(..., 2, '.', '')`, matching
    `AdminBookingResource`'s money formatting).
  - Status counts: one query grouping all `bookings` by `status`, returned as
    an object keyed by every value in the `bookings.status` enum (0 for any
    status with no rows), not just the existing `QUEUE_STATUSES` subset.
  - Active queue: bookings where `status` in `['cooking', 'ready']`, each
    entry with `id`, `code`, `source_type`, `status`, `customer_name` (same
    customer-or-guest fallback as `AdminBookingResource`), `fulfillment`,
    `cooking_started_at`, `est_ready_at`, and `waiting_minutes` (minutes since
    the latest status log, or `created_at` if none - same formula as
    `AdminBookingResource::waiting_minutes`). Build this as a plain array in
    the controller (no new resource class needed for five fields).
  - Top items: `booking_items` joined to `bookings` where
    `bookings.status = 'completed'`, grouped by `service_id`, summing `qty`,
    ordered descending, limited to 5, each entry with `service_id`, `name`,
    `qty_sold`.
  - Low stock: `Service::where('allow_shop_supplied', true)->where('is_active', true)->whereColumn('stock_qty', '<=', 'low_stock_threshold')->get()`,
    each entry with `id`, `name`, `stock_qty`, `low_stock_threshold`.
  - Route: add a `module:dashboard` group in `routes/api.php` next to the
    other module groups, containing
    `Route::get('/dashboard', [DashboardController::class, 'index'])`.
  - Test: `backend/tests/Feature/Admin/AdminDashboardTest.php` seeding
    payments across today/this-week-but-not-today/this-month-but-not-this-week/
    older, both `source_type`s, a mix of booking statuses including
    `cooking`/`ready`, a completed booking with items to prove top-items
    ranking, and a service under/over its low-stock threshold. Assert each
    section of the response shape and values, and that a `403` still applies
    for an admin without the `dashboard` permission (reuses the existing
    `module` middleware, but confirms this route is wired into it).
  - Done when: `composer test` passes including the new test, and the route
    only responds for an authenticated admin/super admin with the `dashboard`
    module.

- [x] 2. Frontend: dashboard page, route, and nav link
  - `frontend/src/features/dashboard/types.ts`, `api.ts` (one `fetchDashboard`
    call to `/admin/dashboard`), `hooks.ts` (`useDashboard` via
    `useQuery(['admin-dashboard'], fetchDashboard)`), and `DashboardPage.tsx`
    rendering: three sales-period stat blocks (today/week/month, each showing
    customer-supplied, shop-supplied, and total, formatted with the existing
    `formatCurrency` from `lib/currency.ts`), a status-count table, the active
    queue as a table (code, customer, fulfillment, waiting minutes), a top-
    items table, and a low-stock list (reusing the same "Low stock" badge
    styling already used in `InventoryPage.tsx`).
  - Loading state renders nothing but a loading indicator (`useQuery`
    `isLoading`); an empty section (e.g. no active queue, no low-stock items)
    renders a plain "None right now" line instead of an empty table.
  - Add `<Route path="/admin/dashboard" element={<DashboardPage />} />` inside
    a `<RoleRoute allow={['admin', 'super_admin']} requireModule="dashboard" />`
    group in `App.tsx`, matching the existing module route groups.
  - Add the `Dashboard` link in `AdminLayout.tsx` under
    `me?.role === 'super_admin' || me?.permissions.includes('dashboard')`.
  - No new frontend unit test: this step only wires up existing, already-
    tested formatting/query/routing patterns with no new pure logic to test
    (per the project's test scope rule).
  - Done when: an admin account with the `dashboard` permission (or a super
    admin) navigating to `/admin/dashboard` on the running dev server sees
    populated sales/status/queue/top-items/low-stock sections matching
    backend fixture data, and an admin without that permission is blocked by
    `RoleRoute`. Verified manually against the dev server and the backend
    test's fixture shape (no browser test harness is configured in this
    project).

## Files / areas

- New: `backend/app/Http/Controllers/Api/Admin/DashboardController.php`
- New: `backend/tests/Feature/Admin/AdminDashboardTest.php`
- Edit: `backend/routes/api.php` (new `module:dashboard` group)
- New: `frontend/src/features/dashboard/{types.ts,api.ts,hooks.ts,DashboardPage.tsx}`
- Edit: `frontend/src/App.tsx` (new route)
- Edit: `frontend/src/components/layouts/AdminLayout.tsx` (new nav link)

## Data / contracts

`GET /api/v1/admin/dashboard` response shape:

```json
{
  "sales": {
    "today": { "customer_supplied": "0.00", "shop_supplied": "0.00", "total": "0.00" },
    "week": { "customer_supplied": "0.00", "shop_supplied": "0.00", "total": "0.00" },
    "month": { "customer_supplied": "0.00", "shop_supplied": "0.00", "total": "0.00" }
  },
  "bookings_by_status": {
    "pending_review": 0, "pending_confirmation": 0, "approved": 0, "confirmed": 0,
    "cooking": 0, "ready": 0, "out_for_delivery": 0, "completed": 0,
    "rejected": 0, "no_show": 0, "cancelled": 0
  },
  "active_queue": [
    { "id": 1, "code": "RS-0001", "source_type": "customer_supplied", "status": "cooking",
      "customer_name": "Jane Doe", "fulfillment": "pickup",
      "cooking_started_at": "2026-09-26T02:00:00Z", "est_ready_at": "2026-09-26T03:00:00Z",
      "waiting_minutes": 12 }
  ],
  "top_items": [
    { "service_id": 3, "name": "Whole Chicken", "qty_sold": 42 }
  ],
  "low_stock": [
    { "id": 5, "name": "Pork Belly", "stock_qty": 2, "low_stock_threshold": 5 }
  ]
}
```

All money fields are decimal strings, matching `AdminBookingResource`. Sales
periods use `payments.paid_at` (always set when a payment is created; the
current payment flow only ever creates `status = 'paid'` rows) against
`now()` in the app's configured timezone (UTC).

## Testing

- Backend: `backend/tests/Feature/Admin/AdminDashboardTest.php` (PHPUnit,
  `composer test`) - covers sales-period bucketing and type split, full
  status-count coverage, active-queue membership and waiting-minutes math,
  top-items ranking scoped to completed bookings, low-stock filtering, and
  the `module:dashboard` authorization gate.
- Frontend: none new: this feature only composes already-tested `formatCurrency`
  and standard TanStack Query/React Router wiring; verified via the dev
  server per the done-when above.

## Notes for the AI

- Reuse `AdminBookingResource`'s exact formulas for `customer_name` (customer
  first+last name, falling back to `guest_name`) and `waiting_minutes`
  (`now()->diffInMinutes(latestStatusLog?->created_at ?? created_at, absolute: true)`)
  rather than inventing new ones.
- Reuse `Service::isLowStock()`'s exact comparison (`stock_qty <= low_stock_threshold`)
  for the low-stock query.
- Do not add a resource class for the five-field active-queue rows; a plain
  array matches this codebase's existing lightweight-response pattern (see
  `BookingController::counts()`).
- `payments.status` can technically be `pending` or `refunded`, but no current
  endpoint creates or transitions to either; only filter `status = 'paid'` and
  do not add handling for the other two values in this feature.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":10871,"specSha256":"bde3e7bc0b77861473b4b2103d5d69fafd765c93ce6a26c45a7436bb708b925d","branch":"refs/heads/feature/sales-dashboard","head":"1fd1ef0a632b31be532d8dab829afd56fc31947f","baseRef":"refs/heads/master","baseCommit":"8ae832ff1c0dec4cac83a3f4ba84d6932b4f4cad","sourceTree":"804a651fccac8aa5b46bd458fcee58ae49f70392","absentOptional":[]} -->

## Independent review

**Status:** passed
**Target commit:** 1fd1ef0a632b31be532d8dab829afd56fc31947f
**Base commit:** 8ae832ff1c0dec4cac83a3f4ba84d6932b4f4cad
**Base ref:** master
**Spec hash:** bde3e7bc0b77861473b4b2103d5d69fafd765c93ce6a26c45a7436bb708b925d
**Prepared by:** claude
**Builder model:** claude-sonnet-5
**Requested reviewer:** claude
**Requested model:** claude-sonnet-5
**Requested execution:** automatic
**Requested at:** 2026-09-25T17:32:05Z
**Workflow:** regular
**Check required:** no
**Reviewer adapter:** claude
**Reviewer model:** claude-sonnet-5
**Reviewer context:** fresh subagent
**Actual execution:** automatic
**Reviewed at:** 2026-09-26T17:40:00Z
**Scope:** current
**Lenses:** quality, security, performance, tests
**Verdict:** passed
**Check result:** not-required

## Commands

- `composer test` (backend/): pass - 250 tests, 1091 assertions, including the 5 new `AdminDashboardTest` cases
- `npm run lint` (frontend/, oxlint): pass - only pre-existing warnings in unrelated files (`button.tsx`, `NewBookingPage.tsx`, `ServicesPage.tsx`, `NewShopOrderPage.tsx`, `WalkInBookingPage.tsx`); nothing in the new `dashboard` feature
- `npm run build` (frontend/, `tsc -b && vite build`): pass - new dashboard files typecheck and build cleanly

## Evidence

- Full `8ae832f..1fd1ef0` delta read: `DashboardController.php`, `routes/api.php`, `AdminDashboardTest.php`, `frontend/src/features/dashboard/{types,api,hooks,DashboardPage}.tsx`, `App.tsx`, `AdminLayout.tsx`
- Route authorization matches the established `module:<name>` group pattern exactly (compared against the existing services/inventory/bookings/payments groups in `routes/api.php`); `User::MODULES` already lists `dashboard`, confirming no new authorization surface was invented
- `Booking::customer()` and `Booking::latestStatusLog()` relations exist and are eager-loaded in `activeQueue()`, so no N+1 query risk
- Confirmed `payments.amount` is `decimal(10,2)` in `2026_09_25_163925_create_payments_table.php`, which grounds the float-cast finding below
- `AdminDashboardTest` covers sales-period bucketing and source-type split, full 11-status count coverage (`assertJson`), active-queue membership and `waiting_minutes` math, top-items ranking scoped to `completed` bookings, low-stock filtering (including an inactive service correctly excluded), and all four authorization cases (401 unauthenticated, 403 no-permission admin, 403 customer, 200 permitted admin/super admin)
- Frontend has no new unit test, matching the spec's stated scope (wiring-only, no new pure logic) and the project's documented test-scope rule

## Findings

- F-62, F-63

## Remaining risk

- F-62 [P2]: `DashboardController::salesSince()` casts summed `decimal(10,2)` payments to `float` before formatting, repeating the F-60 anti-pattern in a new file; not reachable as an incorrect total at current volumes (rounding absorbs the float error), but a real, growing standards violation
- F-63 [P3]: `DashboardPage` returns `null` (a blank page, no heading) when its query errors, a more visible instance of the fail-silent pattern already open nine other times in this ledger (F-10, F-20, F-24, F-34, F-36, F-41, F-44, F-57, F-61)
- Step 2's frontend done-when ("an admin sees populated sales/status/queue/top-items/low-stock sections matching backend fixture data" on the running dev server) was recorded by the builder as manually verified; this review did not start the dev server or re-verify it in a browser, since `Check required: no` and no browser test harness is configured in this project
- Pre-existing open ledger findings (F-04, F-08, F-09, F-11, F-14, F-16 through F-61 excluding F-60/F-61 already covered) are outside this feature's diff and were not re-examined; they are carried as context only, per Audit's rule that the ledger is never the review scope
