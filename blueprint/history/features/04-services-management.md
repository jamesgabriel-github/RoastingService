# Feature: Services management

**From build-plan:** feature 4
**Build attempt:** 1
**Status:** verified
**Branch:** `feature/services-management`

## Goal

Let an admin (or super admin) create, edit, and activate/deactivate the shop's
roastable/sellable items (rate per kg, shop price, cook time, which booking
types each item supports), and let the public see the active list. This is the
`services` table and its first admin surface - nothing here yet manages stock
levels or inventory logs (Feature 5).

## In scope

- Backend: `services` table (id, name, description, `roasting_rate_per_kg`,
  `shop_price`, `est_minutes`, `allow_customer_supplied`,
  `allow_shop_supplied`, `stock_qty` default `0`, `is_active` default `true`),
  the `Service` model, and a factory.
- Backend: a small sample-services seed (Lechon Head, Whole Turkey, Liempo,
  Roast Chicken) added to `DatabaseSeeder`, matching the build plan's example
  list, so admin and public listings aren't empty in local/manual testing.
  Illustrative placeholder rates only - not a pricing decision.
- Backend: a reusable `module` route middleware (`EnsureModulePermission`)
  that checks `User::hasModulePermission($module)`, the first admin endpoint
  group to need per-module scoping (existing endpoints only needed
  role-level or self-only checks).
- Backend: admin endpoints, `role:admin,super_admin` + `module:services`,
  each in `Api\Admin\ServiceController`:
  - `GET /api/v1/admin/services` - list all services (any status)
  - `POST /api/v1/admin/services` - create
  - `PUT /api/v1/admin/services/{id}` - full update
  - `PATCH /api/v1/admin/services/{id}/toggle` - flip `is_active`
- Backend: public `GET /api/v1/services` - active services only, in
  `Api\ServiceController`, no auth required.
- Backend validation: `roasting_rate_per_kg` required when
  `allow_customer_supplied` is true, `shop_price` required when
  `allow_shop_supplied` is true, and at least one of the two booking-type
  flags must be true (a service supporting neither type would be unbookable
  and unlisted anywhere - see Notes for the AI).
- Frontend: `features/services` module (types, api, hooks) and an admin
  `ServicesPage` - a table of services plus one reusable create/edit form
  below it (same layout pattern as `AdminAccountsPage`), and a per-row
  toggle button.
- Frontend: extend `RoleRoute` with an optional `requireModule` check so
  `/admin/services` is reachable by `super_admin` or an `admin` whose `/me`
  permissions include `services`, and add the nav link in `AdminLayout`
  under the same condition.

## Out of scope

- Anything about stock levels beyond the `stock_qty` column existing and
  defaulting to `0`: restocking, adjusting, reserving, releasing, the
  `inventory_logs` table, the inventory log page, and the low-stock badge are
  all Feature 5.
- Deleting a service - the build plan explicitly says no delete, only the
  active toggle.
- The public landing page's services cards UI - that's Feature 16. This
  feature only ships the public `GET /api/v1/services` endpoint.
- Anything under bookings, payments, or the dashboard modules.
- Any admin-account or permission-granting change - Feature 1's
  `/admin/accounts` UI already lets a super admin grant the `services`
  module; this feature only makes that grant meaningful.

## Build loop

Per `blueprint/config.json`: `stepReview: "feature"` (one review packet with
the full diff after all steps below pass) and `checkpointCommits: "disabled"`.
`/complete` makes the final feature commit.

## Build steps

- [x] 1. **Services data layer (backend).** Migration
      `create_services_table`: `id`, `string('name')`,
      `text('description')->nullable()`,
      `decimal('roasting_rate_per_kg', 10, 2)->nullable()`,
      `decimal('shop_price', 10, 2)->nullable()`,
      `unsignedInteger('est_minutes')`,
      `boolean('allow_customer_supplied')->default(false)`,
      `boolean('allow_shop_supplied')->default(false)`,
      `integer('stock_qty')->default(0)`,
      `boolean('is_active')->default(true)`, timestamps. `Service` model
      (`app/Models/Service.php`) with `#[Fillable([...])]` listing every
      column above except `id`/timestamps, and `casts()` returning
      `roasting_rate_per_kg`/`shop_price` as `decimal:2`, the two
      `allow_*`/`is_active` as `boolean`, `stock_qty` as `integer`.
      `database/factories/ServiceFactory.php`: `definition()` produces a
      valid customer-supplied-only service (`allow_customer_supplied: true`,
      `allow_shop_supplied: false`, a fake rate, `shop_price: null`,
      `stock_qty: 0`, `is_active: true`); add `shopSupplied()` (flips to
      shop-supplied with a fake `shop_price`, nulls the rate) and
      `bothTypes()` (both flags true, both prices set) states for tests. In
      `DatabaseSeeder::run()`, seed the four sample services with
      `Service::firstOrCreate(['name' => ...], [...])` (same idempotent
      pattern as the admin-user seed): Lechon Head (customer-supplied only,
      rate 180.00, 240 min), Whole Turkey (both types, rate 200.00, price
      1800.00, 180 min), Liempo (customer-supplied only, rate 150.00, 90
      min), Roast Chicken (shop-supplied only, price 350.00, 60 min).
      **Done when:** `composer test` still passes; add
      `test_seeding_creates_the_sample_services` to `DatabaseSeederTest.php`
      asserting all four names exist and seeding twice still yields exactly
      four rows.
- [x] 2. **Admin CRUD + module middleware (backend).**
      `app/Http/Middleware/EnsureModulePermission.php`: `handle($request,
      $next, string $module)` aborts `403` unless
      `$request->user()?->hasModulePermission($module)`; register the
      `module` alias next to `role` in `bootstrap/app.php`.
      `app/Http/Requests/Admin/StoreServiceRequest.php`: `authorize()` true;
      `rules()` - `name` required/string/max:255, `description`
      nullable/string, `est_minutes` required/integer/min:1,
      `allow_customer_supplied`/`allow_shop_supplied` required/boolean,
      `roasting_rate_per_kg` nullable/numeric/min:0/`required_if:allow_customer_supplied,true`,
      `shop_price` nullable/numeric/min:0/`required_if:allow_shop_supplied,true`;
      `withValidator()` adds an error on `allow_shop_supplied` ("At least one
      booking type must be enabled.") when both flags are false.
      `app/Http/Requests/Admin/UpdateServiceRequest.php` extends
      `StoreServiceRequest` unchanged (a `PUT` fully replaces the service
      with the same shape as create - no partial-update rule set needed).
      `app/Http/Resources/ServiceResource.php`: all columns, decimals as
      strings via the cast.
      `app/Http/Controllers/Api/Admin/ServiceController.php`: `index()`
      returns `ServiceResource::collection(Service::all())`; `store()` and
      `update()` both take the validated data and, before saving, null out
      `roasting_rate_per_kg` when `allow_customer_supplied` is false and
      `shop_price` when `allow_shop_supplied` is false (a rate for a type the
      service doesn't support would be stale/misleading); `store()` creates
      and returns `201` + resource, `update()` finds-or-fails and returns the
      resource; `toggle(int $id)` finds-or-fails, flips `is_active`, saves,
      returns the resource. Routes in `routes/api.php` under
      `Route::middleware(['auth:sanctum', 'role:admin,super_admin',
      'module:services'])` nested inside the existing `admin` prefix group.
      New `tests/Feature/Admin/ServiceManagementTest.php`: super admin can
      list/create/update/toggle; an admin granted the `services` permission
      can do the same; an admin without it gets `403` on every route; a
      customer gets `403`; missing rate when customer-supplied is `422`;
      missing price when shop-supplied is `422`; both flags false is `422`;
      creating a customer-supplied-only service with a `shop_price` in the
      payload stores it as `null`; `stock_qty` in the request body is
      ignored (new service always starts at `0`); unauthenticated is `401`.
      **Done when:** `composer test` passes including the new file.
- [x] 3. **Public listing endpoint (backend).**
      `app/Http/Resources/PublicServiceResource.php`: `id`, `name`,
      `description`, `roasting_rate_per_kg`, `shop_price`, `est_minutes`,
      `allow_customer_supplied`, `allow_shop_supplied`, and `in_stock`
      (`null` when `allow_shop_supplied` is false - stock doesn't apply to a
      customer-supplied-only item - otherwise `stock_qty > 0`). No raw
      `stock_qty` or `is_active` field (the list is already active-only).
      `app/Http/Controllers/Api/ServiceController.php`: `index()` returns
      `PublicServiceResource::collection(Service::where('is_active',
      true)->get())`. Route: `Route::get('/services', [ServiceController::class,
      'index'])` at the top level (no auth). New
      `tests/Feature/ServicesPublicTest.php`: returns only active services
      (an inactive one is absent), a shop-supplied service with `stock_qty:
      0` shows `in_stock: false`, a customer-supplied-only service shows
      `in_stock: null`, response omits `stock_qty` and `is_active` keys, no
      authentication required.
      **Done when:** `composer test` passes including the new file.
- [x] 4. **Admin Services page (frontend).**
      `src/features/services/types.ts`: `Service` interface matching
      `ServiceResource`. `src/features/services/api.ts`: `fetchServices`,
      `createService`, `updateService(id, payload)`, `toggleService(id)`,
      following the `ensureCsrfCookie` + axios pattern in
      `features/admin-accounts/api.ts`. `src/features/services/hooks.ts`:
      `useServices`, `useCreateService`, `useUpdateService`,
      `useToggleService` (TanStack Query, each mutation invalidates the
      services query key on success). `src/features/services/ServicesPage.tsx`:
      a table (name, booking types supported, rate, price, cook time, stock,
      active/inactive, actions) using the same plain `<table>` + Tailwind
      classes as `AdminAccountsPage`; one RHF + Zod form below the table used
      for both create and edit (Zod schema mirrors the backend rules,
      including the "at least one type" check via `.superRefine`); clicking
      "Edit" on a row loads that service into the form and switches its
      submit button to update mode; a "Deactivate"/"Activate" button per row
      calls `useToggleService`; the same 422-field-mapping +
      `getGenericErrorMessage` fallback pattern as the other forms.
      `src/routes/RoleRoute.tsx`: add an optional `requireModule?: Module`
      prop (reusing `Module` from `features/admin-accounts/types.ts`); when
      set, also require `me.permissions.includes(requireModule)`, reusing
      the same "not authorized" message. Register
      `<Route element={<RoleRoute allow={['admin', 'super_admin']}
      requireModule="services" />}><Route path="/admin/services"
      element={<ServicesPage />} /></Route>` inside the existing
      `AdminLayout` route group in `App.tsx`. Add the nav link in
      `AdminLayout.tsx` next to the existing "Admin accounts" link, shown
      when `me?.role === 'super_admin' || me?.permissions.includes('services')`.
      **Done when:** `npm run build` and `npm run test` pass; manual
      walkthrough (below) confirms create, edit, toggle, and the permission
      gate.

## Files / areas

Backend:
- `backend/database/migrations/<timestamp>_create_services_table.php` (new)
- `backend/app/Models/Service.php` (new)
- `backend/database/factories/ServiceFactory.php` (new)
- `backend/database/seeders/DatabaseSeeder.php`
- `backend/app/Http/Middleware/EnsureModulePermission.php` (new)
- `backend/bootstrap/app.php`
- `backend/app/Http/Requests/Admin/StoreServiceRequest.php` (new)
- `backend/app/Http/Requests/Admin/UpdateServiceRequest.php` (new)
- `backend/app/Http/Resources/ServiceResource.php` (new)
- `backend/app/Http/Resources/PublicServiceResource.php` (new)
- `backend/app/Http/Controllers/Api/Admin/ServiceController.php` (new)
- `backend/app/Http/Controllers/Api/ServiceController.php` (new)
- `backend/routes/api.php`
- `backend/tests/Feature/DatabaseSeederTest.php`
- `backend/tests/Feature/Admin/ServiceManagementTest.php` (new)
- `backend/tests/Feature/ServicesPublicTest.php` (new)

Frontend:
- `frontend/src/features/services/{types,api,hooks}.ts` (new)
- `frontend/src/features/services/ServicesPage.tsx` (new)
- `frontend/src/routes/RoleRoute.tsx`
- `frontend/src/components/layouts/AdminLayout.tsx`
- `frontend/src/App.tsx`

## Data / contracts

- `GET /api/v1/admin/services` -> `200` + array of `ServiceResource`
  (`id, name, description, roasting_rate_per_kg, shop_price, est_minutes,
  allow_customer_supplied, allow_shop_supplied, stock_qty, is_active`),
  decimals as strings. `401`/`403` per the role + module gate.
- `POST /api/v1/admin/services` -> body `{name, description?, est_minutes,
  allow_customer_supplied, allow_shop_supplied, roasting_rate_per_kg?,
  shop_price?}` => `201` + `ServiceResource`, or `422`. `stock_qty` always
  starts at `0`; not accepted from the request.
- `PUT /api/v1/admin/services/{id}` -> same body shape => `200` +
  `ServiceResource`, `404` if missing, `422` on the same rules.
- `PATCH /api/v1/admin/services/{id}/toggle` -> no body => `200` +
  `ServiceResource` with `is_active` flipped, `404` if missing.
- `GET /api/v1/services` -> `200` + array of `PublicServiceResource`
  (`id, name, description, roasting_rate_per_kg, shop_price, est_minutes,
  allow_customer_supplied, allow_shop_supplied, in_stock`), active services
  only, no auth.
- A service must have at least one of `allow_customer_supplied` /
  `allow_shop_supplied` true; the non-applicable rate/price is always stored
  as `null` regardless of what the request sends for it.

## Testing

Backend test gate is on; steps 1-3 ship feature/seeder tests in the same
step - see each step's "Done when." Frontend logic (the Zod schema's
"at least one type" rule and the create/edit field mapping) doesn't need a
new pure-logic unit outside what RHF/Zod already validate inline; step 4's
UI is verified by build + test success plus the manual browser walkthrough
below, matching how Features 1-3 verified their UI steps. No Browser tests
command is configured, so no automated browser coverage is added.

**Manual walkthrough:** log in as the seeded super admin -> open
`/admin/services` -> see the four sample services -> create a new
customer-supplied-only service without a rate -> see the inline validation
error -> add a rate, submit -> see it appear in the table -> click Edit on
it, change its cook time, save -> see the change reflected -> click
Deactivate -> see its status flip -> log in as an admin with no `services`
permission -> confirm `/admin/services` shows "not authorized" and no nav
link appears -> grant that admin the `services` permission from
`/admin/accounts` -> confirm the link and page now work for them.

## Notes for the AI

- `stock_qty` exists on the table now because it's part of the same
  `services` row in the data model, but nothing in this feature writes to it
  except the `0` default. Feature 5 owns every write path (restock, adjust,
  reserve, release) and the `inventory_logs` table.
- The "at least one booking type must be true" rule isn't stated verbatim in
  the build plan, but a service with both flags false could never be booked
  and would never appear anywhere - it's a data-integrity guard on the same
  level as the existing "rate required if customer-supplied" rule, not a new
  product decision.
- `UpdateServiceRequest extends StoreServiceRequest` deliberately (unlike
  `UpdateAdminAccountRequest`, which is a genuinely partial `PATCH`) - `PUT`
  here always replaces the full record with the same shape as create.
- No toast library or dialog component exists anywhere in this codebase;
  keep the inline-message, inline-form pattern already used by
  `AdminAccountsPage` rather than introducing either.
- `EnsureModulePermission` is the first per-module gate in the app; reuse
  `User::hasModulePermission()` as-is rather than duplicating its
  is_active/role/permission logic in the middleware.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":16213,"specSha256":"917529e45d94588bdbd6e87c489301aca8f12b06ed30e9d8606b2ab4d999fa8f","branch":"refs/heads/feature/services-management","head":"fa9653ef70f3e287a8da0c9cf945fa2c850b00d7","baseRef":"refs/heads/master","baseCommit":"7211d50b5220e271ea00f013c168c895e640f54a","sourceTree":"f19d21f450323597d6076d0091c3cd574019a3cb","absentOptional":[]} -->

## Independent review

**Status:** passed
**Target commit:** fa9653ef70f3e287a8da0c9cf945fa2c850b00d7
**Base commit:** 7211d50b5220e271ea00f013c168c895e640f54a
**Base ref:** master
**Spec hash:** 917529e45d94588bdbd6e87c489301aca8f12b06ed30e9d8606b2ab4d999fa8f
**Prepared by:** claude
**Builder model:** claude-sonnet-5
**Requested reviewer:** claude
**Requested model:** claude-opus-5-5
**Requested execution:** automatic
**Requested at:** 2026-09-25T04:04:47Z
**Workflow:** regular
**Check required:** no
**Reviewer adapter:** claude
**Reviewer model:** claude-opus-5-5
**Reviewer context:** fresh subagent
**Actual execution:** automatic
**Reviewed at:** 2026-09-25T04:06:56Z
**Scope:** current
**Lenses:** quality, security, performance, tests
**Verdict:** passed
**Check result:** not-required

### Commands

- `git rev-parse HEAD`, `git merge-base master HEAD`, `sha256sum blueprint/context/current-feature.md`, `git status --short --untracked-files=all`: pass (HEAD, merge base, and spec hash match the request; only `blueprint/context/review.md` modified)
- `cd backend && composer test`: pass (67 tests, 228 assertions)
- `cd frontend && npm run build`: pass (existing >500 kB chunk-size warning only)
- `cd frontend && npm run test`: pass (2 files, 12 tests)
- `cd frontend && npm run lint`: pass (exit 0; 2 warnings: existing `button.tsx` only-export-components, and `ServicesPage.tsx:146` React Compiler `incompatible-library` notice for RHF `watch`)

### Evidence

- Reviewed the full `7211d50..fa9653e` delta (24 files) against the verified spec: migration, `Service` model/factory, seeder, `EnsureModulePermission`, `bootstrap/app.php` alias, Store/Update requests, both resources, both controllers, routes, three backend test files, and the frontend services module, `RoleRoute`, `AdminLayout`, and `App.tsx`.
- Security: admin routes stack `auth:sanctum` + `role:admin,super_admin` + `module:services`; middleware reuses `User::hasModulePermission()` (is_active, super_admin, granted row). `stock_qty` and `is_active` are fillable but excluded from `validated()`, so neither is client-writable on create or update. The public resource omits `stock_qty` and `is_active`, and the public query filters `is_active = true`.
- Frontend gate: `/me.permissions` returns `User::MODULES` for super_admin (`UserResource.php:32`), so `RoleRoute requireModule` admits super admins correctly.
- Performance: small admin-managed table, unpaginated `Service::all()` is proportionate; one permission `exists()` query per admin request.

### Findings

- F-19 [P3] open - out-of-range numeric input and non-numeric route ids return 500 instead of 422/404
- F-20 [P3] open - admin services list query fails silently on error
- F-21 [P3] open - tests skip update-path normalization, 404s, and permitted-admin update/toggle
- F-11 [P3] re-examined, still open (now consumed by `RoleRoute requireModule`; backend still 403s)
- No P0 or P1 findings.

### Remaining risk

- `/check` and the spec's manual browser walkthrough were not run (Check not required for this request); UI create/edit/toggle and the nav/permission gate are verified only by build, lint, and code reading.
- No Browser tests command is configured, so there is no automated browser coverage of `ServicesPage`.
- The dashboard activity helper (`run-state.mjs`) was not invoked because this reviewer was restricted to writing only `findings.md` and `review.md`.
