# AkuapemHub

A mobile-first PHP service marketplace for Ghana.

## Goal

Create a unified system for errands, skilled work, and micro jobs with distinct customer, worker, and admin roles.

## Setup with XAMPP

1. Place the `AkuapemHub` folder inside XAMPP's `htdocs` directory.
2. Start Apache and MySQL from the XAMPP control panel.
3. Open phpMyAdmin and import `schema.sql` to create the database.
4. Confirm `config.php` has the correct database credentials:
   - `DB_HOST`
   - `DB_NAME`
   - `DB_USER`
   - `DB_PASS`
5. Open `http://localhost/AkuapemHub` in your browser.

## Key pages

- `index.php` — landing page
- `register.php` / `login.php` — user auth
- `dashboard.php` — customer/worker dashboard
- `notifications.php` — view app alerts and notifications
- `request.php` — create service requests
- `worker_profile.php` — worker profile management
- `rate_job.php` — customer rating flow
- `toggle_payment.php` — payment status toggle
- `admin/index.php` — admin dashboard
- `admin/users.php` — user management
- `admin/requests.php` — request approval and moderation

## Admin user

Create an admin by setting `role = 'admin'` in the `users` table for a registered user.

## Notes

- New requests are created as `pending` until approved by an admin.
- Workers can accept `open` jobs.
- Workers can accept open jobs and mark them as completed.
- Customers can mark jobs as paid/unpaid and payment changes are logged.
- Customers can rate completed jobs.
- Workers and customers can share requests via WhatsApp.
- The app attempts simple PHP email notifications for new requests, acceptances, and completions.
- Users can view a notification center to see alerts from admin and system events.

## Future enhancements

- Improve worker subscription and premium listing features
- Add a dedicated notification center
- Add real location distance matching
