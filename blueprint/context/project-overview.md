# Roasting Service - Project Overview

<!-- blueprint:source-hash 1d19bab8a0a934f112bee08b38c96299aae516a9a4b7a1cc43011aaf93540d76 -->

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
  delivery, sees estimated vs final price, tracks status.
- **Admin (shop staff)** - reviews/confirms bookings, weighs in raw food,
  updates cooking status, manages services and inventory, adds walk-ins,
  records payments, monitors sales.
- **Visitor (public, anonymous)** - browses services, prices, and how booking
  works, then registers.

## Features

Headline feature: **Bring-your-own booking** (5) plus its shared booking-status
engine - this is the core of the product and everything downstream (queues,
weigh-in, cooking, payments, dashboard) is built on it.

1. **Auth & roles** - customer register/login/logout via Sanctum SPA cookies, seeded admin account, role-based middleware/layouts.
2. **Customer profile** - view/edit name, email, phone; change password.
3. **Services management** - admin CRUD for roastable/sellable items (rate, price, cook time, allowed booking types, active toggle); public active-services list.
4. **Inventory** - admin restock/adjust per-piece stock inside locked transactions, full log, low-stock indicator.
5. **Bring-your-own booking** - customer books roasting with estimated raw kg + drop-off; introduces the booking status engine, rate snapshots, status logs.
6. **Shop order** - customer orders in-stock items per piece; stock reserved atomically, total is final at placement.
7. **My bookings** - customer list/detail with status timeline, estimated vs final weight/price, auto-refresh, cancel before cooking.
8. **Admin booking queues** - tabbed status queues with counts, search, waiting time, detail view.
9. **Approve & weigh-in** - admin approves (schedules drop-off) or rejects bring-your-own bookings, then weighs in to lock final price and confirm.
10. **Shop order confirmation** - admin confirms (final) or rejects shop orders, releasing reserved stock on rejection.
11. **Cooking & fulfillment** - start cooking (sets est. ready time), ready/out-for-delivery, complete, no-show and cancellation with stock release.
12. **Walk-in bookings** - admin creates either booking type for a registered customer or guest (name + phone), with instant weigh-in.
13. **Payments** - admin records full payments (cash, GCash, card); paid vs balance shown; filterable payments list.
14. **Sales dashboard** - sales today/week/month by booking type, bookings by status, today's cooking/ready queue, top items, low-stock alerts.
15. **Public landing page** - hero + Book Now, about, services cards, how it works, location/contact.

## Data model

### `users`
- `id`, `name`, `email`, `phone`, `password`
- `role` (enum: `admin` | `customer`)

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
- `status` (enum, see status flows below)
- `preferred_dropoff_at`, `dropoff_at`, `approved_at`, `approved_by` -> `users`
- `confirmed_at`, `confirmed_by` -> `users`, `weighed_at`
- `cooking_started_at`, `est_ready_at`, `completed_at`
- `estimated_total` (numeric), `total_amount` (numeric), `notes`, `reject_reason`

### `booking_items`
- `id`, `booking_id` -> `bookings`, `service_id` -> `services`
- `qty` (int), `est_weight_kg` (numeric, nullable), `final_weight_kg` (numeric, nullable)
- `rate` (numeric, snapshot of the service rate/price at booking time), `subtotal` (numeric)

### `booking_status_logs`
- `id`, `booking_id` -> `bookings`, `status`, `changed_by` -> `users`, `remarks`, `created_at`

### `payments`
- `id`, `booking_id` -> `bookings`
- `type` (enum: `full` | `downpayment` | `balance`), `amount` (numeric)
- `method` (enum: `cash` | `gcash` | `card` | ...), `reference_no`
- `status` (enum: `pending` | `paid` | `refunded`), `paid_at`, `recorded_by` -> `users`

### `settings`
- `key`, `value` - e.g. `downpayment_enabled` (false), `downpayment_percent` (25)

> Lock: rates/prices are snapshotted onto `booking_items` at booking time so
> later price changes never rewrite history. Money and weight columns are
> `numeric`, never float.

**Status flows** (`bookings.status`):
- Customer-supplied: `Booked -> Pending review -> Approved -> Confirmed -> Cooking -> Ready|Out for delivery -> Completed`, with exits to `Rejected`, `No-show`, `Cancelled` (only before Cooking).
- Shop-supplied: `Placed -> Pending confirmation -> Confirmed -> Cooking -> Ready|Out for delivery -> Completed`, with exits to `Rejected`/`Cancelled` (stock released).

## Tech stack

- **Laravel** - REST API backend, one JSON API under `/api/v1` once routes exist.
- **React + TypeScript + Tailwind + shadcn/ui** - the SPA frontend.
- **Laravel Sanctum** - SPA cookie-based auth (frontend + API on sibling subdomains); API tokens later for mobile/desktop.
- **PostgreSQL** - `numeric` for money/weight, enums/check constraints for statuses.
- TanStack Query + Zod (frontend data-fetching/validation) - planned, not yet installed.

> TODO / current gap: the scaffolded backend is still stock `laravel/laravel`
> on SQLite with no Sanctum installed, and the frontend has no router or
> data-fetching library yet. Feature 1 (Auth & roles) is where Sanctum and the
> real database driver actually get wired in - don't assume they're already in
> place.

## Monetization

Not applicable - this is an internal operations tool for a single shop, not a
monetized product. No ads, subscriptions, or transaction fees.

## UI/UX

Warm, earthy tones (roasted browns, ember orange, cream), light mode first,
shadcn/ui themed with custom Tailwind tokens. Mobile-first for customers,
desktop/tablet-first for admin (sidebar nav). One status = one badge color
everywhere; toasts on every action; confirmation dialogs for reject/cancel/delete.
Currency as PHP (Peso) with two decimals; weights in kg with up to two decimals.

Main screens (exact route paths not yet decided):
- Public: landing/hero, services list, how-it-works, location/contact, login/register.
- Customer: new-booking step flow (type -> items -> pickup/delivery -> review), my-bookings list + detail (status timeline), profile.
- Admin: tabbed booking queues, booking detail, services table, inventory log, walk-in form, payments list, sales dashboard.

## Deployment

> TODO - not decided yet. Open items: hosting provider (e.g. Laravel Forge /
> Ploi / Railway for the backend), a static host for the frontend on a sibling
> subdomain so Sanctum's cookie auth works (`app.` + `api.`), build/start
> commands, env vars, and database/storage provisioning.

## Open questions

- **Monetization and Deployment weren't in the original project-plan draft.**
  I've filled Monetization as N/A (internal shop tool) and left Deployment as
  an open TODO - confirm these are right rather than gaps I should chase down.
- **Tech stack vs. current code:** the plan targets PostgreSQL + Sanctum, but
  the scaffolded backend is still SQLite with no Sanctum installed. Feature 1
  (Auth & roles) needs to cover that migration as part of its scope, not treat
  it as already done.
- **`settings` table's only documented use** (`downpayment_enabled/percent`)
  backs the downpayment feature, which is explicitly out of v1. Worth
  confirming whether `settings` ships in v1 at all, or waits until downpayment
  is built.
