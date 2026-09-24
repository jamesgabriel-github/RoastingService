# Project Plan

> One of the two planning docs you provide. Use as much detail as the project
> needs, including rationale, constraints, examples, edge cases, and explicit
> exclusions that should guide later feature work. Draft it directly, develop it
> through any AI conversation, or optionally run `/discovery` for a guided deep
> planning session. The content is always yours to direct. When it is filled in,
> run `/overview` to generate the project overview from this plus `build-plan.md`.

## 1. Problem - What problem are we solving?

A roasting shop takes two kinds of requests:

- **Roasting bookings:** customers bring their own raw food (lechon/pig head,
  turkey, liempo, chicken) to be roasted.
- **Shop orders:** customers buy roasted items from the shop's own stock.

Today these are handled manually (walk-ins, calls, chats), which causes:

- No single view of incoming requests, what's cooking and what's ready
- Unclear pricing for roasting bookings, since the price depends on the raw
  weight that is only known at drop-off
- No stock tracking, so the shop can oversell items it doesn't have
- Customers have no way to check their order's status without contacting the
  shop
- No reliable sales records

**Roasting Service** gives customers a way to book and track their orders, and
gives the shop one place to review bookings, weigh in, manage stock and
monitor sales.

## 2. Users - Who is this for?

| User | Needs |
|---|---|
| **Customer** | Book a roasting service or order from the shop, choose pickup or delivery, see estimated/final price, track status |
| **Admin (shop staff)** | Review and confirm bookings, weigh in raw food, update cooking status, manage services and inventory, add walk-ins, record payments, monitor sales - scoped to whichever modules they've been granted |
| **Super admin (shop owner/manager)** | Everything a regular admin can do, plus create/enable/disable admin accounts and assign each one's per-module permissions |
| **Visitor (public)** | Learn about the shop, services, prices and how booking works, then register |

## 3. Features - What does the MVP need?

The ordered, spec-ready feature list lives in `build-plan.md`. This section
carries the product rules and edge cases that list doesn't capture.

### Booking types

| | Customer-supplied (roasting booking) | Shop-supplied (shop order) |
|---|---|---|
| Raw food from | Customer | Shop inventory |
| Pricing | Per kilo x roasting rate | Per piece x shop price |
| Price at booking | Estimate (customer's estimated raw weight) | Final |
| Final price set | At the shop's raw weigh-in | At admin confirmation |
| Admin steps | Approve, then Confirm after weigh-in | Confirm only (final) |
| Stock | Not affected | Reserved on placement |
| Fulfillment | Pickup or delivery | Pickup or delivery |

### Status flows

**Customer-supplied**
```
Booked -> Pending review -> Approved (drop-off scheduled)
  -> Confirmed (weighed in, final price) -> Cooking
  -> Ready (pickup) / Out for delivery -> Completed
Exits: Rejected, No-show, Cancelled (before Cooking)
```

**Shop-supplied**
```
Placed (stock reserved) -> Pending confirmation -> Confirmed (final)
  -> Cooking -> Ready (pickup) / Out for delivery -> Completed
Exits: Rejected / Cancelled -> stock released
```

### Public

- Welcome / hero, about
- Services with rates, prices and cook times (1-3 hrs)
- How it works
- Location / contact
- Login / register

### Customer

- **New booking:** choose "Bring my own raw food" or "Order from the shop"
  - Bring own: item(s), estimated raw weight, preferred drop-off date/time ->
    estimated price
  - Shop order: in-stock items + quantity -> final price
  - Pickup or delivery (address, contact number), notes
- **My bookings:** list + detail with status timeline, estimated vs final
  weight/price, estimated ready time
- **Profile:** name, email, contact number, password

### Admin

- **Services:** add/update; roasting rate per kg, shop price per piece,
  estimated cook time, which types it's available for, active toggle
  (deactivate, don't delete)
- **Inventory:** stock per piece, restock/adjust, full change log, low-stock
  indicator
- **Booking management:**
  - Queues: Pending review, Pending confirmation, Awaiting drop-off, Cooking,
    Ready, Out for delivery
  - Approve (set drop-off) / reject (with reason)
  - Weigh-in: enter actual raw kg -> final price -> Confirmed
  - Confirm shop orders
  - Update status and notes; add walk-ins (customer or guest name + phone)
  - Delete only unprocessed/rejected entries; otherwise cancel
- **Payments:** record full payment at pickup/delivery (cash, GCash, etc.)
- **Dashboard:** sales today/week/month, split by booking type; bookings by
  status; top items; today's queue; low stock
- **Admin accounts (super admin only):** create admin accounts, enable/disable
  them, and assign each one permissions per module - Services, Inventory,
  Bookings, Payments, Dashboard. An admin without a module's permission can't
  reach that module's pages or endpoints at all.

### Business rules

1. Customer-entered weight is an **estimated raw weight**; the final price
   uses the shop's raw weigh-in.
2. The customer can decline the final price at weigh-in -> Cancelled.
3. Shop stock is reserved on placement and released on reject/cancel.
4. Admin queues show how long each booking has been waiting.
5. No shipping fee for now (field kept, default 0).
6. Rates are snapshotted on each booking item so price changes don't alter
   history.
7. Admin login is separate from customer login (its own page/route) and uses
   a normal per-admin identity - email + password - so audit fields
   (`approved_by`, `confirmed_by`, `created_by`, `recorded_by`, `changed_by`)
   attribute to a real admin. No public admin signup; admin accounts are
   created by seeder/by another admin, same as before.
8. Customer login is by **mobile number only** in v1 - no password, no code,
   just the registered number. This is a deliberate v1 simplification, not an
   oversight: it means anyone who knows a customer's registered mobile number
   can access that account until v2's SMS verification ships. Customer
   sign-up still collects full personal information (name, email, phone) as
   before; only the login step is simplified.
9. Two admin tiers: **super admin** and **admin**. Only a super admin can
   create, enable/disable, or change the permissions of an admin account.
   A disabled admin account can't log in even with correct credentials. A
   regular admin can only reach the modules (Services, Inventory, Bookings,
   Payments, Dashboard) they've been explicitly granted; super admin always
   has every permission and doesn't need them assigned.

### Not in v1

- Downpayment (designed as a setting, **off** by default; 25% when enabled,
  via Stripe)
- Shipping fees
- SMS/email notifications
- Roaster capacity / time-slot checking
- Public "track by code" page, reports export, mobile app
- Customer login verification: after entering their mobile number, send a
  one-time SMS code (e.g. via Semaphore) to that number and require it before
  granting access, closing the v1 gap in rule 8

## 4. Data - What are we storing?

### `users`
id, name, email, phone, password, role (`super_admin` | `admin` | `customer`),
is_active (default true), timestamps

### `admin_permissions`
id, user_id (-> `users`, an `admin` row), module (`services` | `inventory` |
`bookings` | `payments` | `dashboard`), granted_by (-> `users`, the super
admin who granted it), timestamps

> Admin rows (`role = admin` or `super_admin`) log in with email + password on
> a separate `/admin` page/route; no public admin signup - only a super admin
> creates other admin accounts. `is_active = false` blocks login outright even
> with correct credentials. A `super_admin` row needs no `admin_permissions`
> rows (always full access); an `admin` row can only reach the modules it has
> a row for. `approved_by`, `confirmed_by`, `created_by`, `recorded_by`, and
> `changed_by` below are normal `users.id` FKs pointing at the acting admin.
>
> Customer rows (`role = customer`) log in with **mobile number only** in v1
> - `password` is collected at sign-up but not checked at login yet. v2 adds
> an SMS one-time code sent to that number (see Not in v1); whether that code
> lives in a new table or an ephemeral cache entry is a `/feature`-time
> implementation decision, not fixed here.

### `services`
id, name, description, roasting_rate_per_kg (nullable), shop_price (nullable,
per piece), est_minutes, allow_customer_supplied, allow_shop_supplied,
stock_qty, is_active, timestamps

### `inventory_logs`
id, service_id, change_qty (+/-), reason (`restock` | `reserve` | `release` |
`adjust`), booking_id (nullable), remarks, created_by, created_at

### `bookings`
id, code (e.g. `RS-0001`), customer_id (nullable for walk-ins), guest_name,
guest_phone, source_type (`customer_supplied` | `shop_supplied`), fulfillment
(`pickup` | `delivery`), delivery_address, shipping_fee (default 0), status,
preferred_dropoff_at, dropoff_at, approved_at, approved_by, confirmed_at,
confirmed_by, weighed_at, cooking_started_at, est_ready_at, completed_at,
estimated_total, total_amount, notes, reject_reason, timestamps

### `booking_items`
id, booking_id, service_id, qty, est_weight_kg, final_weight_kg, rate
(snapshot), subtotal

### `booking_status_logs`
id, booking_id, status, changed_by, remarks, created_at

### `payments`
id, booking_id, type (`full` | `downpayment` | `balance`), amount, method
(`cash` | `gcash` | `card` | ...), reference_no, status (`pending` | `paid` |
`refunded`), paid_at, recorded_by, timestamps

### `settings`
key, value - e.g. `downpayment_enabled` (false), `downpayment_percent` (25)

*Later, with Stripe: `payments.stripe_checkout_session_id`,
`stripe_payment_intent_id`, `refunded_at`; `bookings.downpayment_due`,
`payment_expires_at`.*

## 5. Tech - What stack are we using?

| Layer | Choice |
|---|---|
| Backend | **Laravel** (REST API) |
| Frontend | **React + TypeScript**, **Tailwind CSS**, **shadcn/ui** |
| Auth | **Laravel Sanctum** |
| Database | **PostgreSQL** |

### Notes

- **Sanctum mode:** use SPA cookie-based auth for the web app (frontend and
  API on the same top-level domain, e.g. `app.example.com` +
  `api.example.com`; set `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN` and CORS
  `supports_credentials`). Use API tokens later for mobile/desktop clients.
- **Admin login** is a separate `/admin` page/route from customer login, but
  still a normal email + password check against a `users` row (`role =
  admin` or `super_admin`); no public admin signup, only a super admin
  creates other admin accounts. `is_active = false` blocks login outright.
- **Customer login** in v1 only asks for a mobile number - no password check.
  v2 adds SMS one-time-code verification to that number (e.g. via Semaphore)
  before granting the session; sign-up still collects full customer details
  regardless of the login method.
- **Roles & permissions:** `role` column (`super_admin` | `admin` |
  `customer`) plus middleware/policies for admin vs customer routes. Within
  admin, per-module access (Services, Inventory, Bookings, Payments,
  Dashboard) is checked against `admin_permissions`; a policy/gate per module
  is the natural place for this in Laravel, keeping it out of individual
  controllers.
- **Status changes** go through one service/action class that validates
  allowed transitions and writes `booking_status_logs`, so the rules live in
  one place.
- **Stock changes** run in DB transactions with row locking
  (`lockForUpdate`) to prevent overselling.
- **PostgreSQL:** use `numeric` for money and weights (never float), and
  enums or check constraints for statuses.
- **Frontend:** a data-fetching library (e.g. TanStack Query) for server
  state; form validation with a schema library (e.g. Zod) to match Laravel's
  validation.
- **Later:** Laravel queues + scheduler for notifications and no-show/expiry
  jobs; Stripe behind a payment interface so PayMongo/Xendit can be swapped
  in (confirm Stripe availability for a PH business first).

> Current actual state (per Feature 1): Sanctum, PostgreSQL, and the
> frontend's router/data-fetching/form libraries are all wired in. Later
> features can assume this baseline exists.

## 6. Monetize - How will this make money?

N/A - this is an internal operations tool for a single roasting shop, not a
monetized product. No ads, subscriptions, or transaction fees are planned.

## 7. UI/UX - How should this look and feel?

*Proposed direction, to refine during design.*

### Overall feel

- **Warm and appetizing:** earthy tones (roasted browns, ember orange, cream
  backgrounds) with a clean, modern layout. It should feel like a trusted
  local shop, not a generic SaaS.
- **Simple and clear:** most customers will book from their phones, so fewer
  fields, big tap targets and plain language (Taglish-friendly labels are
  fine).
- shadcn/ui components themed with custom Tailwind tokens; light mode first.

### Public page

- Hero with food photography and a clear "Book Now" call to action
- Services as cards (photo, rate per kg / price per piece, cook time)
- 3-step "How it works" strip: Book -> Drop off / Order -> Pickup or Delivery

### Customer

- **Mobile-first.**
- Booking as a short step flow: **type -> items -> pickup/delivery -> review**
- Always show a running price; label it clearly as **Estimated** (roasting
  booking) or **Total** (shop order)
- Booking detail with a **vertical status timeline** and clear color-coded
  status badges
- After weigh-in, highlight the change from estimated -> final weight and
  price

### Admin

- **Desktop/tablet-first**, sidebar navigation
- Dashboard: summary cards (sales, pending, cooking, ready, low stock) + sales
  chart
- Booking management as **tabbed queues** by status with counts, a data table
  with search/filters, and quick actions (Approve, Weigh in, Confirm, Start
  cooking, Mark ready)
- Weigh-in as a focused dialog: kg input -> live final price -> confirm
- Show waiting time on pending items and warn on low stock

### Consistency

- One status -> one badge color, used everywhere (e.g. pending = amber,
  approved = blue, confirmed = indigo, cooking = orange, ready = green,
  completed = gray, rejected/cancelled = red)
- Toast feedback on every action; confirmation dialogs for reject/cancel/delete
- Currency formatted as PHP (Peso) with two decimals; weights in kg with up to
  two decimals

## 8. Deployment - Where and how will this ship?

> TODO: not decided yet. `roastingservice-build-plan.md` M10 lists the open
> choices: hosting provider and domain (e.g. Laravel Forge / Ploi / Railway for
> the backend, a static host on a sibling subdomain for the frontend so
> Sanctum's cookie auth works across `app.` + `api.` subdomains), build/start
> commands, env vars, and database/storage needs.

## 9. Usage model and constraints (optional)

> Not yet established beyond what's implied above: single shop (not
> multi-tenant), trusted internal admin users plus public customer
> registration, no stated scale or compliance requirements. Leave as unknown
> rather than assuming enterprise or hostile-user constraints.
