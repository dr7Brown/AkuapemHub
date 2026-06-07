# AkuapemHub

A mobile-first PHP service marketplace for Ghana.

## Goal

Create a unified system for errands, skilled work, and micro jobs with distinct customer, worker, and admin roles.

## Setup with XAMPP

1. Place the `AkuapemHub` folder inside XAMPP's `htdocs` directory.
2. Start Apache and MySQL from the XAMPP control panel.
3. Open phpMyAdmin and create a database named `akuapemhub` (or run `schema.sql` to import it fully).
4. Confirm `config.php` has the correct database credentials:
   - `DB_HOST`
   - `DB_NAME`
   - `DB_USER`
   - `DB_PASS`
5. Open `http://localhost/AkuapemHub` in your browser.
6. If you encounter database errors, visit `http://localhost/AkuapemHub/migrate.php` to auto-create missing tables.

## Key pages

- `index.php` — landing page
- `register.php` / `login.php` — user auth
- `dashboard.php` — customer/worker dashboard
- `notifications.php` — view app alerts and notifications
- `request.php` — create service requests
- `request_detail.php` — view full request details and actions
- `worker_profile.php` — worker profile management
- `worker_history.php` — worker job history and earnings stats
- `customer_payments.php` — customer payment history
- `messages.php` — worker-customer messaging thread
- `rate_job.php` — customer rating flow
- `toggle_payment.php` — payment status toggle
- `admin/index.php` — admin dashboard
- `admin/users.php` — user management
- `admin/requests.php` — request approval and moderation
- `admin/analytics.php` — detailed platform analytics

## Admin user

Create an admin by setting `role = 'admin'` in the `users` table for a registered user.

## Notes

- New requests are created as `pending` until approved by an admin.
- Workers can accept `open` jobs and mark them as completed.
- Workers can upgrade their profile to a premium subscription status.
- Workers can view their job history, completed jobs count, average rating, and paid earnings.
- Customers can mark jobs as paid/unpaid and view payment history with detailed records.
- Workers and customers can send messages for each request to communicate directly.
- Users can view full request details and share them via WhatsApp.
- Workers and customers can share requests via WhatsApp.
- The app attempts simple PHP email notifications for new requests, acceptances, and completions.
- Users can view a notification center to see alerts from admin and system events.
- Admin can view detailed analytics including user counts, request status breakdown, ratings, revenue, top workers, and popular categories.

## Recently shipped

- Real geolocation distance matching (browser geolocation capture + Haversine distance display/sorting)
- Dispute/refund handling system (admin dispute queue with resolution workflow)
- Worker availability/schedule calendar (weekly availability slots on worker profiles)
- Job completion photo/evidence upload
- SMS/WhatsApp business integration (key-event messages logged via `business_messages`, pluggable provider in `config.php`, admin log at `admin/business_messages.php`)
- Worker leaderboard and trending jobs view
- Invoice/receipt PDF export (browser print-to-PDF receipts)
