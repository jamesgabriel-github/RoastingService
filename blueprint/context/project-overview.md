# Roasting Service - Project Overview

<!-- blueprint:source-hash 76ea2640e00452bc5c29e167398fc0a3b08fbb8003f9659683e869e88dd2af44 -->

> A booking and inventory system for a roasting shop: customers book roasting
> for their own raw food or order roasted items from shop stock, and admins run
> the whole lifecycle - approve, weigh in, cook, fulfill, get paid - from one
> place.

## Problem

The shop currently handles roasting bookings and shop orders manually (walk-ins,
calls, chats). That means no single view of what's incoming, cooking, or ready;
unclear pricing for roasting bookings (final price depends on a raw weight only
known at drop-off); no stock tracking (risk of overselling); no way for
customers to check status without contacting the shop; and no reliable sales
record.

## Users

- **Customer** - books a roasting service or a shop order, chooses pickup or
  delivery, sees estimated vs final price, tracks status. No sign-up step:
  logging in with a phone number that doesn't match an existing account
  creates one automatically, then routes to a one-time profile-setup step
  (first/middle/last name, address) before the account page. Login stays
  mobile-number-only in v1 (see Open questions for the trust tradeoff).
- **Admin** - reviews/confirms bookings, weighs in raw food, updates cooking
  status, manages services and inventory, adds walk-ins, records payments,
  monitors sales - scoped to whichever modules a super admin has granted.
- **Super admin** (shop owner/manager) - everything an admin can do, plus
  creates/enables/disables admin accounts and assigns their per-module
  permissions. Always has full access.
- **Visitor (public, anonymous)** - browses services, prices, and how booking
  works, then logs in, which creates their account automatically on first use.

## Usage model

Single shop, not multi-tenant. Admins are a small trusted internal group;
customers are the public, with accounts created on demand at login (no
approval gate). No stated scale, compliance, or availability requirements -
treat as unknown rather than assuming enterprise or hostile-user constraints.

## Features

Headline feature: **Bring-your-own booking** (6) plus its shared booking-status
engine - this is the core of the product and everything downstream (queues,
weigh-in, cooking, payments, dashboard) is built on it. Feature 18 moves that
status from the booking onto each booking item.

1. **Auth & roles** *(shipped)* - separate `/admin` login (email + password,
   seeded accounts) and customer login (mobile-number-only, v2 adds SMS
   one-time code); two admin tiers where super admin manages admin accounts
   and per-module permissions.
2. **Customer identity & profile setup** - no customer email/password or
   separate registration; an unrecognized phone number silently creates the
   account at login; a customer with no name saved yet is routed to a
   profile-setup step (first/middle/last name, address) before their account.
3. **Customer profile** - view/edit first, middle, last name and address
   (phone is the login identity, not editable here; no email or password).
4. **Services management** - admin CRUD for roastable/sellable items (rate, price, cook time, allowed booking types, active toggle); public active-services list.
5. **Inventory** - admin restock/adjust per-piece stock inside locked transactions, full log, low-stock indicator.
6. **Bring-your-own booking** - customer books roasting with estimated raw kg + drop-off; introduces the booking status engine, rate snapshots, status logs.
7. **Shop order** - customer orders in-stock items per piece; stock reserved atomically, total is final at placement.
8. **My bookings** - customer list/detail with status timeline, estimated vs final weight/price, auto-refresh, cancel before cooking.
9. **Admin booking queues** - tabbed status queues with counts, search, waiting time, detail view.
10. **Approve & weigh-in** - admin approves (schedules drop-off) or rejects bring-your-own bookings, then weighs in to lock final price and confirm.
11. **Shop order confirmation** - admin confirms (final) or rejects shop orders, releasing reserved stock on rejection.
12. **Cooking & fulfillment** - start cooking (sets est. ready time), ready/out-for-delivery, complete, no-show and cancellation with stock release.
13. **Walk-in bookings** - admin creates either booking type for a registered customer or guest (name + phone), with instant weigh-in.
14. **Payments** - admin records full payments (cash, GCash, card); paid vs balance shown; filterable payments list.
15. **Sales dashboard** - sales today/week/month by booking type, bookings by status, today's cooking/ready queue, top items, low-stock alerts.
16. **Public landing page** - hero + Book Now, about, services cards, how it works, location/contact.
17. **Admin sidebar navigation** - persistent left sidebar (Dashboard, Booking with a status sub-menu, Services, Inventory, Payments, Admin accounts) replacing the admin topbar, reusing existing per-module permission gating; booking status sub-menu items link to bookmarkable URLs on the existing bookings queue page.
18. **Per-item booking status** - status moves from the booking to each booking item, so a booking's items can progress independently from Cooking onward; admin queues regroup the raw statuses into Draft, Pending, Cooking, Ready, Completed, and a separate Cancelled tab, with the admin bookings list showing one row per item instead of per booking.

## Data model

### `users`
- `id`
- `name`, `email` (unique), `password` - all nullable; populated only for
  `admin`/`super_admin` rows
- `phone` (nullable, unique) - the customer login identity
- `first_name`, `middle_name`, `last_name` (nullable), `address` (nullable,
  free text) - customer profile fields, null until first-login setup
- `role` (enum: `super_admin` | `admin` | `customer`)
- `is_active` (bool, default true) - `false` blocks login outright, even with correct credentials (used to disable admins)

### `admin_permissions`
- `id`, `user_id` -> `users` (an `admin` row)
- `module` (enum: `services` | `inventory` | `bookings` | `payments` | `dashboard`)
- `granted_by` -> `users` (the super admin who granted it)

> A `super_admin` needs no rows here (always full access). An `admin` can only
> reach the modules it has a row for. A customer row logging in for the first
> time on an unrecognized phone number gets `first_name`/`middle_name`/
> `last_name`/`address` all null until profile setup completes them.

### `services`
- `id`, `name`, `description`
- `roasting_rate_per_kg` (numeric, nullable), `shop_price` (numeric, nullable, per piece)
- `est_minutes`, `allow_customer_supplied` (bool), `allow_shop_supplied` (bool)
- `stock_qty` (int), `is_active` (bool)

### `inventory_logs`
- `id`, `service_id` -> `services`
- `change_qty` (int, +/-), `reason` (enum: `restock` | `reserve` | `release` | `adjust`)
- `booking_id` (nullable -> `bookings`), `remarks`, `created_by` -> `users`, `created_at`

### `bookings`
- `id`, `code` (unique, e.g. `RS-0001`)
- `customer_id` (nullable -> `users`, for walk-ins), `guest_name`, `guest_phone`
- `source_type` (enum: `customer_supplied` | `shop_supplied`)
- `fulfillment` (enum: `pickup` | `delivery`), `delivery_address`, `shipping_fee` (numeric, default 0)
- `preferred_dropoff_at`, `dropoff_at`
- `estimated_total` (numeric), `total_amount` (numeric), `notes`

### `booking_items`
- `id`, `booking_id` -> `bookings`, `service_id` -> `services`
- `qty` (int), `est_weight_kg` (numeric, nullable), `final_weight_kg` (numeric, nullable)
- `rate` (numeric, snapshot of the service rate/price at booking time), `subtotal` (numeric)
- `status` (enum, see status flows below)
- `approved_at`, `approved_by` -> `users`, `confirmed_at`, `confirmed_by` -> `users`
- `weighed_at`, `cooking_started_at`, `est_ready_at`, `completed_at`, `reject_reason`

> Status and its transition timestamps/actors live here, not on `bookings`
> (feature 18): a booking's items intake together but can be at different
> stages independently from Cooking onward.

### `booking_status_logs`
- `id`, `booking_id` -> `bookings`, `booking_item_id` -> `booking_items`, `status`, `changed_by` -> `users`, `remarks`, `created_at`

### `payments`
- `id`, `booking_id` -> `bookings`
- `type` (enum: `full` | `downpayment` | `balance`), `amount` (numeric)
- `method` (enum: `cash` | `gcash` | `card` | ...), `reference_no`
- `status` (enum: `pending` | `paid` | `refunded`), `paid_at`, `recorded_by` -> `users`

### `settings`
- `key`, `value` - e.g. `downpayment_enabled` (false), `downpayment_percent` (25)

> Lock: rates/prices are snapshotted onto `booking_items` at booking time so
> later price changes never rewrite history. Money and weight columns are
> `numeric`, never float. `approved_by`/`confirmed_by`/`created_by`/
> `recorded_by`/`changed_by` are all real `users.id` FKs to the acting admin.
> `delivery_address` on `bookings` is separate from a customer's profile
> `address` - entered per-order, not reused from the profile (yet).

**Status flows** (`booking_items.status`, moved from `bookings` in feature 18 -
a booking's items intake together but diverge independently from Cooking on):
- Customer-supplied: `Booked -> Pending review -> Awaiting drop-off -> Confirmed -> Cooking -> Ready|Out for delivery -> Completed`, with exits to `Rejected`, `No-show`, `Cancelled` (only before Cooking).
- Shop-supplied: `Placed -> Pending confirmation -> Confirmed -> Cooking -> Ready|Out for delivery -> Completed`, with exits to `Rejected`/`Cancelled` (stock released).

Admin queue groups: **Draft** (Pending review, Pending confirmation, Awaiting
drop-off), **Pending** (Confirmed - walk-ins land here by default),
**Cooking**, **Ready** (Ready, Out for delivery), **Completed**, and a
separate **Cancelled** tab (Rejected, Cancelled, No-show). The admin bookings
list shows one row per item, not per booking.

## Tech stack

- **Laravel** - REST API backend, one JSON API under `/api/v1`.
- **React + TypeScript + Tailwind + shadcn/ui** - the SPA frontend.
- **Laravel Sanctum** - SPA cookie-based sessions for both admin and customer.
- **PostgreSQL** - `numeric` for money/weight, enums/check constraints for statuses.
- **TanStack Query, React Router, React Hook Form + Zod** - frontend data-fetching, routing, and validation.
- **Vitest** - frontend unit tests for pure logic.

Auth specifics:
- Admin: separate `/admin` login, email + password against a `users` row (`admin`/`super_admin`), no public signup, `is_active` gate.
- Customer: mobile-number-only login, no password. An unrecognized number
  silently creates the customer account (same v1 trust model as login itself).
  A customer with no name saved yet is routed to profile setup
  (first/middle/last name, address) before reaching their account. v2 adds an
  SMS one-time code before granting the session or creating the account,
  closing this v1 gap for both.
- Admin authorization: `role` distinguishes the three tiers; per-module access for `admin` rows is checked against `admin_permissions` (a policy/gate per module, not inline in controllers).

> Current actual state (Feature 1 shipped, Feature 2 shipped): Sanctum,
> PostgreSQL, and the frontend's router/data-fetching/form/test libraries are
> all wired in. Customer registration no longer exists - login auto-creates
> and profile setup collects the name/address fields. Later features can
> assume this baseline exists.

## Monetization

Not applicable - this is an internal operations tool for a single shop, not a
monetized product. No ads, subscriptions, or transaction fees.

## UI/UX

Warm, earthy tones (roasted browns, ember orange, cream), light mode first,
shadcn/ui themed with custom Tailwind tokens. Mobile-first for customers,
desktop/tablet-first for admin (sidebar nav). One status = one badge color
everywhere; toasts on every action; confirmation dialogs for reject/cancel/delete.
Currency as PHP (Peso) with two decimals; weights in kg with up to two decimals.

Main screens (exact route paths not yet decided for unbuilt features):
- Public: landing/hero, services list, how-it-works, location/contact, customer login (`/login`).
- Customer: profile setup (`/profile-setup`, first login only), new-booking step flow (type -> items -> pickup/delivery -> review), my-bookings list + detail (status timeline), account/profile (`/account`).
- Admin: separate `/admin` login, tabbed booking queues grouped by status (Draft/Pending/Cooking/Ready/Completed/Cancelled, one row per item), booking detail, services table, inventory log, walk-in form, payments list, sales dashboard, admin-account management (super admin only).

## Deployment

> TODO - not decided yet. Open items: hosting provider (e.g. Laravel Forge /
> Ploi / Railway for the backend), a static host for the frontend on a sibling
> subdomain so Sanctum's cookie auth works (`app.` + `api.`), build/start
> commands, env vars, and database/storage provisioning.

## Open questions

- **Monetization and Deployment weren't in the original project-plan draft.**
  Monetization is filled as N/A (internal shop tool); Deployment is an open
  TODO - confirm these are right rather than gaps to chase down.
- **`settings` table's only documented use** (`downpayment_enabled/percent`)
  backs the downpayment feature, which is explicitly out of v1. Confirm
  whether `settings` ships in v1 at all, or waits until downpayment is built.
- **Profile `address` isn't wired to bookings yet.** `bookings.delivery_address`
  is entered per-order and stays separate from the customer's profile
  `address` for now; a future feature could prefill delivery address from the
  profile, but that's not decided.
- **Customer login has no verification in v1**, for both signing in and
  creating an account: anyone who knows or guesses a phone number in the
  right format gets in (existing account) or gets one created (new number).
  This is a deliberate, explicit v1 simplification (business rule 8), not an
  oversight - v2's SMS one-time code closes it for both paths.
