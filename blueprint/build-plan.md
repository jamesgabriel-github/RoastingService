# Build Plan

List the features that make up your project, high level and in rough build order.
Keep each item to one line; the details come later in `/feature`.

Plain bullets are fine. When both planning docs are ready, run `/overview`.
It adds tracking numbers and checkboxes to your feature list before generating
the project overview.

Run `/feature` to spec the next unchecked item, or `/feature 2` to pick one.
Keep completed items checked and append new features as the project grows.
Do not renumber completed features; their archived specs refer to those IDs.

Scaffolding the app and prototyping its look are pre-build steps, not features.
Start with your first real slice of functionality.

## Your features

Replace these examples with your own features:

- [ ] 1. **Auth & roles** - customer register/login/logout with Sanctum SPA cookies, seeded admin account, admin vs customer middleware, role-based redirects and layouts
- [ ] 2. **Customer profile** - customer views and edits name, email and phone (PH mobile format), and changes password
- [ ] 3. **Services management** - admin creates, edits and deactivates roastable/sellable items with roasting rate per kg, shop price per piece, cook time and allowed booking types; public active-services endpoint
- [ ] 4. **Inventory** - admin restocks and adjusts shop stock per piece inside locked transactions, with a full inventory log and low-stock indicator
- [ ] 5. **Bring-your-own booking** - customer books roasting for their own raw food (estimated raw kg, preferred drop-off, pickup/delivery) and sees a server-calculated estimate; introduces the booking status engine, rate snapshots and status logs
- [ ] 6. **Shop order** - customer orders in-stock items per piece with pickup/delivery; stock is reserved atomically with the order and the total is final
- [ ] 7. **My bookings** - customer list and detail with status timeline, estimated vs final weight and price, auto-refresh, and cancel before cooking
- [ ] 8. **Admin booking queues** - tabbed status queues with counts, search by code/name/phone, waiting time, and a booking detail view
- [ ] 9. **Approve & weigh-in** - admin approves bring-your-own bookings with a drop-off schedule or rejects with a reason, then weighs in the raw food to lock the final price and confirm
- [ ] 10. **Shop order confirmation** - admin confirms shop orders as final or rejects them with a reason, releasing reserved stock
- [ ] 11. **Cooking & fulfillment** - admin starts cooking (sets estimated ready time), marks ready for pickup or out for delivery, completes, and handles no-shows and cancellations with stock release
- [ ] 12. **Walk-in bookings** - admin creates either booking type for a registered customer or a guest (name + phone), with instant weigh-in when the food is on hand
- [ ] 13. **Payments** - admin records full payments (cash, GCash, card) against confirmed bookings, with paid vs balance shown on the booking and a filterable payments list
- [ ] 14. **Sales dashboard** - sales today/week/month from payments split by booking type, bookings by status, today's cooking/ready queue, top items and low-stock alerts
- [ ] 15. **Public landing page** - hero with Book Now, about, services cards with rates and cook times, how it works, and location/contact

