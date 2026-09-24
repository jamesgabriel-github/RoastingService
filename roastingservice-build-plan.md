# Roasting Service — Build Plan

Companion to `roastingservice-project-plan.md`. This file covers **how and in what order** v1 gets built.

**Stack:** Laravel (API) · React + TypeScript + Tailwind + shadcn/ui (SPA) · Laravel Sanctum (SPA cookie auth) · PostgreSQL

---

## 0. Repository & conventions

```
roasting-service/
├── backend/     # Laravel API
├── frontend/    # React + Vite + TS
└── docs/        # project plan, build plan, API notes
```

- **API:** REST, JSON, prefixed `/api/v1`. Laravel API Resources for all responses; Form Requests for all validation.
- **Money & weight:** PostgreSQL `numeric(10,2)` / `numeric(8,2)`; never floats. Send them to the frontend as strings.
- **Statuses:** PHP backed enums (`BookingStatus`, `SourceType`, `Fulfillment`, `PaymentType`…) mirrored as TS union types.
- **Business logic** lives in Action/Service classes (e.g. `ApproveBooking`, `WeighInBooking`, `ReserveStock`), not in controllers.
- **Frontend structure:** `features/<module>` folders (api hooks, components, pages); TanStack Query for server state; React Hook Form + Zod for forms; React Router for routing.
- **Git:** `main` (stable) + feature branches per milestone task; small PRs.

---

## Milestone overview

| # | Milestone | Outcome |
|---|---|---|
| M1 | Project setup | Both apps run locally and talk to each other |
| M2 | Auth & roles | Register, login, logout, role-based access |
| M3 | Database & domain core | All tables, models, enums, seeders |
| M4 | Services & inventory (admin) | Admin can manage items and stock |
| M5 | Booking engine (backend) | All booking flows and status rules work via API |
| M6 | Customer app | Customers can book and track |
| M7 | Admin booking management | Admin can process every booking end to end |
| M8 | Payments & dashboard | Payments recorded, sales dashboard live |
| M9 | Public pages & polish | Landing page, UX polish, responsive checks |
| M10 | Testing, hardening & deploy | v1 in production |

Order matters up to M5; M6 and M7 can run in parallel once the booking API exists.

---

## M1 — Project setup

**Backend**
- [ ] Create Laravel project in `backend/`, configure PostgreSQL in `.env`
- [ ] Install Sanctum (`php artisan install:api`)
- [ ] Configure CORS (`supports_credentials: true`, allowed origin = frontend URL)
- [ ] Set `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`, `SESSION_DRIVER=database` (or redis)
- [ ] Add Laravel Pint (code style) and Larastan (static analysis)
- [ ] Health check route `GET /api/v1/health`

**Frontend**
- [ ] Vite + React + TS in `frontend/`
- [ ] Tailwind + shadcn/ui init; set brand tokens (brown / ember orange / cream)
- [ ] Install TanStack Query, React Router, React Hook Form, Zod, axios
- [ ] Axios instance: `baseURL`, `withCredentials: true`, `withXSRFToken: true`
- [ ] ESLint + Prettier; path alias `@/`

**Done when:** the frontend calls `/api/v1/health` successfully with cookies enabled.

---

## M2 — Auth & roles

**Backend**
- [ ] `users` table: add `phone`, `role` (`admin` | `customer`, default `customer`)
- [ ] Endpoints: `POST /register`, `POST /login`, `POST /logout`, `GET /api/v1/me` (after `GET /sanctum/csrf-cookie`)
- [ ] Middleware `role:admin` / `role:customer`
- [ ] Profile: `PUT /api/v1/me` (name, email, phone), `PUT /api/v1/me/password`
- [ ] Rate-limit login; admin account created by seeder only (no public admin signup)

**Frontend**
- [ ] Login and register pages
- [ ] `useAuth` hook (current user via `/me`), `ProtectedRoute` + `RoleRoute`
- [ ] Layouts: `PublicLayout`, `CustomerLayout`, `AdminLayout` (sidebar)
- [ ] Redirect after login by role (`/admin` vs `/account`)
- [ ] Profile page (customer)

**Done when:** a customer and an admin can log in and each only reaches their own area.

---

## M3 — Database & domain core

- [ ] Migrations: `services`, `inventory_logs`, `bookings`, `booking_items`, `booking_status_logs`, `payments`, `settings`
- [ ] Check constraints / enums for status, source_type, fulfillment, payment type/method
- [ ] Indexes: `bookings(status)`, `bookings(customer_id)`, `bookings(source_type, status)`, `payments(paid_at)`
- [ ] Models + relationships + enum casts + `decimal:2` casts
- [ ] Booking code generator (`RS-0001`), unique, generated in a transaction
- [ ] Seeders: admin user, sample services (lechon head, turkey, liempo, chicken), settings (`downpayment_enabled=false`, `downpayment_percent=25`)
- [ ] Factories for all models (for tests)

**Done when:** `php artisan migrate:fresh --seed` builds a working dataset.

---

## M4 — Services & inventory (admin)

**Backend**
- [ ] `GET/POST/PUT /api/v1/admin/services`, `PATCH .../services/{id}/toggle` (activate/deactivate; no delete)
- [ ] Validation: roasting rate required if `allow_customer_supplied`; shop price required if `allow_shop_supplied`
- [ ] `POST /api/v1/admin/services/{id}/stock` — restock or adjust (`change_qty`, `reason`, `remarks`)
- [ ] `GET /api/v1/admin/inventory-logs` (filter by service, reason, date)
- [ ] `InventoryService` with `reserve`, `release`, `restock`, `adjust` — each in a DB transaction with `lockForUpdate`, always writing a log row; stock can never go below 0
- [ ] Public `GET /api/v1/services` (active only, no stock numbers beyond "in stock")

**Frontend (admin)**
- [ ] Services table (shadcn DataTable) + create/edit dialog
- [ ] Active toggle, low-stock badge
- [ ] Restock/adjust dialog; inventory log page with filters

**Done when:** admin can manage items and every stock change appears in the log.

---

## M5 — Booking engine (backend)

The core of the app. Build and test this before the UIs depend on it.

- [ ] **State machine:** a `BookingStatus` transition map per source type; a single `TransitionBooking` action that validates the move, sets the timestamp fields, writes `booking_status_logs`, and fires events (for notifications later)
- [ ] **Pricing service:** estimate (est kg × rate), final (final kg × rate), shop total (qty × price); rates snapshotted onto `booking_items`
- [ ] **Customer endpoints**
  - `POST /api/v1/bookings` — customer-supplied (items with est kg, preferred drop-off) or shop-supplied (items with qty; reserves stock in the same transaction)
  - `GET /api/v1/bookings`, `GET /api/v1/bookings/{id}` (own only, via Policy)
  - `POST /api/v1/bookings/{id}/cancel` (only before Cooking; releases stock)
- [ ] **Admin endpoints**
  - `GET /api/v1/admin/bookings` — filters: status, source type, date, search (code, name, phone); includes waiting time
  - `GET /api/v1/admin/bookings/{id}`
  - `POST .../{id}/approve` (drop-off schedule), `POST .../{id}/reject` (reason; releases stock)
  - `POST .../{id}/weigh-in` (final kg per item → final price → Confirmed)
  - `POST .../{id}/confirm` (shop-supplied)
  - `POST .../{id}/start-cooking` (sets `est_ready_at` from item cook times), `.../ready`, `.../out-for-delivery`, `.../complete`, `.../no-show`, `.../cancel`
  - `POST /api/v1/admin/bookings` — walk-in (registered customer or guest name + phone)
  - `DELETE .../{id}` — only Rejected / unprocessed
- [ ] Feature tests for **every** allowed and disallowed transition, stock reserve/release, and pricing

**Done when:** the full lifecycle of both booking types passes in feature tests.

---

## M6 — Customer app

- [ ] **New booking wizard** (mobile-first): type → items → pickup/delivery → review → submit
  - Bring own: item picker, estimated raw kg, preferred drop-off date/time, note "final price is based on the shop's weigh-in"
  - Shop order: in-stock items only, qty capped at availability
  - Running price: "Estimated" vs "Total"
- [ ] **My bookings** list with status badges and filters (active / completed)
- [ ] **Booking detail:** vertical status timeline, items, estimated vs final weight & price, drop-off schedule, estimated ready time, cancel button when allowed
- [ ] Polling (TanStack Query `refetchInterval`) on active bookings so status updates appear without refresh
- [ ] Empty, loading and error states

**Done when:** a customer can place both booking types on a phone and follow them to Completed.

---

## M7 — Admin booking management

- [ ] Booking page with **tabbed queues** + counts: Pending review · Pending confirmation · Awaiting drop-off · Cooking · Ready · Out for delivery · All
- [ ] DataTable: code, customer, type, items, total, waiting time, status; search + date filter
- [ ] Row quick actions depending on status (Approve, Reject, Weigh in, Confirm, Start cooking, Mark ready, Out for delivery, Complete)
- [ ] **Approve dialog:** set drop-off date/time
- [ ] **Reject dialog:** reason (required)
- [ ] **Weigh-in dialog:** kg per item → live final price vs estimate → Confirm (or Cancel if customer declines)
- [ ] Booking detail drawer/page: timeline, items, customer info, notes, payments
- [ ] **Walk-in form:** search existing customer or enter guest name + phone
- [ ] Confirmation dialogs for destructive actions; toasts on every action

**Done when:** admin can take any booking from placement to Completed without touching the database.

---

## M8 — Payments & dashboard

**Payments**
- [ ] `POST /api/v1/admin/bookings/{id}/payments` — `type=full`, amount, method, reference no.
- [ ] Guard: can't complete a booking with an unpaid balance (or allow with a warning — decide in build)
- [ ] Payment section in the booking detail; payments list page with date filter
- [ ] Keep a `PaymentGateway` interface stub (`createDownpayment`, `handleWebhook`, `refund`) for the future Stripe work — no implementation yet

**Dashboard**
- [ ] `GET /api/v1/admin/dashboard?range=today|week|month`
  - Sales total (from `payments`), split by source type
  - Bookings by status, today's queue (cooking / due / ready)
  - Top items, low-stock items
- [ ] Summary cards + sales chart (shadcn charts / Recharts)

**Done when:** the dashboard numbers match the payments recorded in test data.

---

## M9 — Public pages & polish

- [ ] Landing page: hero + "Book Now", about, services cards (from `GET /services`), how it works, location/contact
- [ ] Status badge colors applied consistently (one component, one color map)
- [ ] ₱ currency and kg formatting helpers used everywhere
- [ ] Responsive checks: customer flows on mobile, admin on tablet/desktop
- [ ] Accessibility pass: labels, focus states, keyboard use in dialogs
- [ ] 404 / unauthorized pages; session-expired handling (401 → login)

---

## M10 — Testing, hardening & deploy

**Testing**
- [ ] Backend: Pest/PHPUnit feature tests for auth, policies, every endpoint, state machine, stock concurrency
- [ ] Frontend: Vitest + React Testing Library for the booking wizard and weigh-in dialog
- [ ] Optional: one Playwright end-to-end run per booking type

**Hardening**
- [ ] Policies on every customer route (customers only see their own bookings)
- [ ] Rate limits on auth and booking creation
- [ ] DB backups; `APP_DEBUG=false`; secure/same-site cookie settings for production
- [ ] Error logging (e.g. Laravel logs + Sentry)

**Deploy**
- [ ] Backend: VPS or managed host (e.g. Laravel Forge / Ploi / Railway) with PHP, PostgreSQL, HTTPS
- [ ] Frontend: static build on the same domain family (e.g. `app.` + `api.` subdomains) so Sanctum cookies work
- [ ] Environment files for local / staging / production
- [ ] Deploy to staging → run through both booking flows → production

**Done when:** v1 is live and both booking types work end to end in production.

---

## After v1 (backlog)

1. **Downpayment + Stripe** — turn on `downpayment_enabled`; Stripe Checkout, webhook-confirmed payments, session expiry releases stock, refunds on rejection (verify PH Stripe account + GCash support first)
2. **Notifications** — SMS (e.g. Semaphore) / email on Approved, Confirmed, Ready, Out for delivery, via Laravel queues
3. **Scheduled jobs** — auto no-show after X hours past drop-off
4. **Shipping fees** — per area
5. **Roaster capacity / time slots**
6. **Public "track by code" page**, reports export
7. **Mobile/desktop app** using Sanctum API tokens

---

## Decisions to make during the build
- Allow completing a booking with an unpaid balance?
- No-show cutoff (hours after the scheduled drop-off)
- Low-stock threshold (global or per item)
- Hosting provider and domain
