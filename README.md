# AkuapemConnect

A mobile-first community platform for Ghana — connecting skilled workers with customers, buyers with sellers, travellers with hosts, and neighbours across the Akuapem area, while serving as a digital hub for local news, events, funeral announcements, and community life.

---

## Features

### Jobs & Services

- Post service requests with budget, location, skills needed, job type (on-site / remote / hybrid), and optional escrow payment
- Workers browse and apply for jobs; customers (or managers) review and approve applicants
- Multi-worker jobs with `workers_needed` / `workers_approved` tracking
- Escrow payment flow: funds held by Paystack, released on job completion or admin decision
- Job completion photo upload as evidence
- Smart worker matching — ranks jobs by skill overlap, distance, rating, and availability
- Haversine GPS distance sorting
- Auto-category suggestion while typing a request title
- Auto-suggested budget hints based on category + location history
- Featured job posts

### Worker Profiles

- Multi-step registration wizard: account details → ID verification → skill picker
- 12-category / 70+ skill taxonomy with category → skill grouping
- Ghana Card or Passport ID upload for verification badge
- Worker availability calendar (weekly time slots)
- Featured profile promotion
- Worker leaderboard

### Marketplace

- Sellers open a free shop, list products with photos, price, stock, and condition — subject to admin approval
- Buyers browse by category, price, condition, or location, add to cart across multiple shops, and check out via Paystack
- Seller earnings are held as a **pending balance**, then move to **available balance** after delivery is confirmed and an admin-configurable confirmation window passes (paused automatically if a delivery complaint is filed)
- Sellers withdraw to a saved Mobile Money or Bank account, paid out via Paystack Transfers (manual admin approval or fully automatic, admin's choice)
- **Fast Payout** (opt-in, admin-gated): eligible sellers can link a Paystack subaccount so their share settles straight to their bank once the same confirmation window closes — no manual withdrawal step. Admin controls both *who* can see the option (per-shop eligibility) and whether enabling it requires admin approval
- **Nearby Markets** — a scheduled/periodic-market variant of the Marketplace (e.g. Ofie Market, Nkurakan Market): orders are placed ahead of market day and picked up from a storehouse, gated by each market's own open/closed schedule
- **Quick Services** — a lightweight, platform-run service desk (airtime, ECG/utility bills, exam results checkers, Ghana Card/passport assistance) fulfilled directly by staff rather than a third-party seller
- Featured & Sponsored boosts for products and shops
- Saved products / wishlist

### Delivery Services

- Customers post a delivery request (pickup, drop-off, item, date); reviewed by admin or auto-approved for trusted customers
- Verified independent riders apply with their own offered fee; the customer selects one
- Live status tracking: Pending → Accepted → Picked Up → In Transit → Delivered
- Delivery fee is agreed and paid directly between customer and rider — the platform doesn't hold delivery fees in escrow
- Verified Rider badge, Premium/Sponsored rider listings
- Delivery complaints pause a linked marketplace order's payout release until resolved
- A marketplace order marked "Ready for Delivery" automatically creates a delivery request

### Accommodation

- Hosts list rooms, houses, hotels, and guest houses (two categories sharing one browse/search engine)
- Buyers filter by town, price, guests, and facilities, then message the host via in-app chat (Contact Owner / Request Viewing / Send Booking Enquiry)
- **The platform does not process bookings or accommodation payments** — deliberately by design. Any booking, deposit, or rent is arranged directly between customer and host, off-platform
- Publishing a listing may require an active listing subscription (admin-configurable Free / Hybrid / Paid monetization mode, same pattern as Marketplace shops)
- Featured listing promotion; listing reports for fraud/abuse

### Community

- **Events** — submit, browse, and promote local events; admin review before publication
- **Funeral Announcements** — post burial/wake-keeping/thanksgiving notices; admin moderation
- **News & Articles** — submit local news; admin-reviewed before going live
- **Advertisements** — banner and sponsored ad slots managed by admin
- **Sponsors** — paid sponsor packages with rich-text benefits, admin-managed

### Special Offers & Promotions

- Admin-defined, time-limited promotions: percentage discounts on a paid feature, complimentary (free) access, or a redeemable promo code
- Per-account claim limits and expiry, enforced centrally wherever a payment is initialized

### Moderation & Rejection Workflow

- Admins (and managers) approve or reject jobs, events, funerals, news, products, and accommodation listings
- Rejections include a reason shown directly to the user
- Users can edit a rejected item and resubmit without paying again
- Admin audit log tracks every moderation action

### Roles

| Role            | Access                                                                              |
| --------------- | ------------------------------------------------------------------------------------ |
| Customer        | Post jobs, hire workers, shop the marketplace, request deliveries, rate workers      |
| Worker          | Browse & apply for jobs, manage profile, earn ratings                                |
| Seller          | Open a shop, list products, fulfil marketplace orders, manage wallet/payouts         |
| Delivery Agent  | Apply for delivery jobs, manage availability, earn a rating and verified badge       |
| Accommodation Host | List rooms/houses/hotels/guest houses, respond to enquiries                       |
| Manager         | Approve job applications + community content (cannot access full admin panel)        |
| Admin           | Full platform management, analytics, user management, payments, disputes            |

A single account can hold several of these roles at once (e.g. a Customer who also runs a Marketplace shop).

### Sign in with Google

- "Continue with Google" on login/register, alongside standard email/password accounts
- First-time Google sign-ups are routed to a one-time profile-completion step (username, phone, town) before continuing, since Google's response can't supply those

### Payments (Paystack)

- Escrow payments for service jobs
- Marketplace order checkout, with platform commission and an optional buyer-side checkout charge, both admin-configurable
- Optional publishing fees for job posts, events, news, and funeral announcements
- Optional featured-job, featured-worker, featured-product, featured-shop, and featured-accommodation promotion fees
- Optional worker verification badge fee
- Optional accommodation listing subscriptions
- Automated seller payouts via Paystack Transfers (manual or automatic mode), plus opt-in Fast Payout via Paystack subaccounts for eligible sellers
- All keys, modes, and fees configured via the admin panel (no code changes needed)

### Referrals & Points

- Unique referral codes per user
- Points awarded for: registration, email verification, profile completion, referrals, hiring, completing jobs, 5-star ratings
- Points wallet and transaction history
- All point values configurable from the admin panel

### Messaging & Notifications

- In-platform chat between job owner and applicant/hired worker, and between accommodation hosts and enquirers
- Admin-granted direct messaging
- In-app notification centre (info / success / warning / error)
- Email notifications (opt-out per user)
- Business WhatsApp/SMS event logging

### Security

- bcrypt password hashing
- Google OAuth as an alternative to password-based accounts
- Email verification with OTP-style token
- Password reset via email OTP
- CSRF protection on all forms
- Prepared statements throughout
- Session hardening (HttpOnly, SameSite, admin-configurable idle timeout up to 7 days)
- HTTP security headers, including a restrictive Content-Security-Policy
- Spam/fraud signals on request review (rapid posting, shared contact info, repeated low-budget requests)

---

## Setup (fresh install)

### Requirements

- XAMPP (PHP 8.x, MariaDB 10.4+, Apache)
- A Paystack account (test keys are fine to start)

### Steps

1. **Clone / place files**

   ```
   c:\xampp\htdocs\Akuapemconnect\
   ```

2. **Create the database**
   Open phpMyAdmin → New → name it (e.g. `akuapemhub`) → Create.

3. **Run the master migration**
   Import `install.sql` via phpMyAdmin (SQL tab) **or** run from the command line:

   ```
   mysql -u root -p akuapemhub < install.sql
   ```

   This single file creates and updates every table, seeds categories, towns, default packages, and platform settings, in strict version order. It is fully idempotent (`CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE`, `ADD COLUMN IF NOT EXISTS`) — safe to re-run on an existing database at any time.

4. **Configure the app**
   `config.php` holds the live configuration and is **not** committed to git (it contains real secrets). Copy `config_local.php` for a local dev setup, or `config_production.php` as a starting template for a live deployment, to `config.php`, then adjust:

   ```php
   define('DB_HOST',   'localhost');
   define('DB_NAME',   'akuapemhub');
   define('DB_USER',   'root');
   define('DB_PASS',   '');
   define('APP_NAME',  'AkuapemConnect');
   define('BASE_URL',  '/Akuapemconnect');   // no trailing slash; full https:// origin in production
   define('MAIL_FROM', 'noreply@akuapemconnect.com');
   ```

5. **Set Paystack keys**
   Log in as admin → Monetization → enter your Paystack public key, secret key, webhook secret, and set mode to `test` or `live`. Register the webhook URL shown there (`{BASE_URL}/paystack_webhook.php`) in your Paystack dashboard.

6. **Create the first admin**
   Register a normal account, then in phpMyAdmin set `role = 'admin'` in the `users` table for that user.

7. **Visit the site**
   ```
   http://localhost/Akuapemconnect
   ```

---

## Key Pages

### Public (no login required)

| Page                             | Purpose                                          |
| --------------------------------- | ------------------------------------------------ |
| `index.php`                       | Home — jobs, events, news, funeral announcements |
| `browse_jobs.php`                 | Public job listings with search & filter         |
| `find_workers.php`                | Browse worker profiles                           |
| `marketplace.php`                 | Marketplace product browsing                     |
| `markets.php` / `market_view.php` | Nearby Markets listing and detail                |
| `quick_services.php`              | Quick Services directory                         |
| `accommodation.php`               | Accommodation category chooser                   |
| `accommodation_listings.php`      | Accommodation browse/search/filter                |
| `events.php` / `event.php`        | Event listings and detail                        |
| `funerals.php` / `funeral.php`    | Funeral announcement listings and detail         |
| `news.php` / `news_article.php`   | News listings and full article                   |
| `about.php`                       | Platform overview and how-it-works               |
| `support.php`                     | FAQ and contact information                      |
| `terms.php`                       | Terms of Service                                 |
| `privacy.php`                     | Privacy Policy                                   |
| `login.php` / `register.php`      | Authentication (incl. Sign in with Google)       |

### Authenticated Users

| Page                          | Purpose                                                                       |
| ------------------------------ | ------------------------------------------------------------------------------ |
| `jobs.php`                     | Job dashboard (role-aware — workers see open jobs, customers see their posts) |
| `request.php`                  | Create / edit a service request                                               |
| `request_detail.php`           | Full job view with apply / hire / complete / escrow actions                   |
| `cart.php` / `checkout.php`    | Marketplace cart and checkout                                                 |
| `orders.php`                   | Marketplace order history                                                     |
| `seller_dashboard.php`         | Seller shop, products, wallet, and settings                                   |
| `seller_payout_accounts.php`   | Seller payout accounts + Fast Payout opt-in                                   |
| `delivery.php` / `delivery_request.php` | Delivery Services dashboard and request creation                     |
| `my_accommodation.php`         | Manage own accommodation listings                                             |
| `accommodation_detail.php`     | Listing detail + enquiry buttons                                              |
| `promotions.php`               | Special Offers — browse, claim, redeem a promo code                           |
| `my_events.php`                | Manage submitted events                                                       |
| `my_funerals.php`              | Manage submitted funeral announcements                                        |
| `my_news.php`                  | Manage submitted news articles                                                |
| `worker_profile.php`           | Edit worker profile, skills, availability                                     |
| `worker_profile_public.php`    | Public-facing worker profile                                                  |
| `manage_applicants.php`        | Review and approve/reject job applicants                                      |
| `messages.php`                 | Per-job messaging thread                                                      |
| `chat.php`                     | In-platform direct chat                                                       |
| `notifications.php`            | Notification centre                                                           |
| `referrals.php`                | Referral code, stats, points wallet                                           |
| `settings.php`                 | Account settings (name, email, phone, location, password)                     |
| `rate_job.php`                 | Post-job rating flow                                                          |
| `pay_*.php` / `*_checkout.php` | Paystack checkout pages (escrow, job post, event, news, funeral, subscriptions) |

### Admin Panel (`admin/`)

| Page                                | Purpose                                                                |
| ------------------------------------ | ------------------------------------------------------------------------ |
| `index.php`                          | Admin dashboard — key stats and quick actions                          |
| `users.php`                          | User management, role assignment, ban/unban                            |
| `requests.php`                       | Job moderation — approve, reject, flag                                 |
| `marketplace.php`                    | Marketplace product/shop moderation                                    |
| `mp_payouts.php`                     | Seller payout requests, analytics, settings, and Fast Payout controls  |
| `markets.php` / `market_orders.php` / `market_deliveries.php` | Nearby Markets management                     |
| `quick_services.php` / `quick_service_requests.php` | Quick Services catalogue and request queue               |
| `accommodation.php`                  | Accommodation listing moderation                                       |
| `promotions.php`                     | Create and manage Special Offers / promo codes                         |
| `events.php` / `event_edit.php`      | Event moderation and editing                                           |
| `funerals.php` / `funeral_edit.php`  | Funeral announcement moderation                                        |
| `news.php` / `news_edit.php`         | News moderation and editing                                            |
| `sponsors.php`                       | Sponsor package management                                             |
| `ads.php` / `ads_edit.php`           | Advertisement management                                               |
| `payments.php`                       | Platform payments log (escrow, fees, promotions)                      |
| `analytics.php`                      | Detailed platform analytics                                            |
| `disputes.php`                       | Dispute queue and resolution workflow                                  |
| `workers.php`                        | Worker profile management, verification approval                      |
| `delivery.php`                       | Delivery Services management                                           |
| `referrals.php`                      | Referral programme overview                                            |
| `monetization.php`                   | Paystack keys, monetization mode, fees                                 |
| `business_messages.php`              | WhatsApp/SMS event log                                                 |
| `audit_logs.php`                     | Full admin action audit trail                                          |

---

## Migration Versioning

`install.sql` is the single source of truth for the database schema — currently at **v079**. Every migration is additive and idempotent (`CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE`, `ADD COLUMN IF NOT EXISTS`, repeatable `ALTER ... MODIFY`), so the file can be safely re-run against an existing database at any time, in any environment. Broad strokes of what's landed, in order:

- **v001–v010** — core schema: users, jobs, applications, escrow, referrals/points, community content (news/events/funerals/ads), locations
- **v011–v032** — Marketplace core: shops, products, categories, cart, orders, reviews
- **v033–v038** — Marketplace seller wallet: pending/available balance, automated Paystack Transfer payouts, payout accounts
- **v039–v058** — Delivery Services: requests, ratings, riders, verification, premium/sponsored rider listings
- **v059–v068** — Nearby Markets: periodic-market shops, schedules, delivery towns, system charges
- **v069–v077** — Quick Services, Sponsor Packages, Promotions, marketplace customer-side checkout charge
- **v073–v076** — Accommodation module, Sign in with Google
- **v078–v079** — Fast Payout (Paystack subaccounts): opt-in per-shop payout automation, plus admin eligibility/approval controls

New migrations always go at the bottom of `install.sql` under the next version block — see individual version comments in the file for full detail on any specific change.

> The individual `migrate*.php` scripts remain in the repo for historical reference but are superseded entirely by `install.sql`.

---

## Configuration Reference

Key constants in `config.php` (not committed — see Setup step 4):

| Constant        | Purpose                                          |
| --------------- | ------------------------------------------------- |
| `DB_*`          | Database connection                               |
| `APP_NAME`      | Displayed site name                               |
| `BASE_URL`      | Base URL, no trailing slash (path locally, full origin in production) |
| `MAIL_FROM`     | Sender address for system emails                  |
| `ADMIN_EMAIL`   | Fallback contact address shown across the site    |
| `WHATSAPP_*` / `SMS_*` | Optional WhatsApp/SMS OTP provider credentials |

Paystack keys and all monetization toggles (commission rates, checkout charges, subscription pricing, Fast Payout controls, etc.) are stored in the `platform_settings` table and managed via the **Admin panel** — no code changes required after initial setup.

---

## Folder Structure

```
Akuapemconnect/
├── admin/                    Admin panel pages
├── assets/
│   ├── css/                  Global stylesheet (style.css) + theme.php
│   ├── js/                   Shared JS, incl. rich-editor.js
│   └── images/                Logos, heroes, icons
├── modules/
│   └── referrals/             Referral & points module (service.php, migrate.php)
├── services/                  Email/OTP/WhatsApp service wrappers
├── partials/                  Shared UI fragments (bottom_nav, google_icon, etc.)
├── tests/                     Standalone smoke-test scripts
├── uploads/                   User-uploaded files (photos, ID docs, listing images…)
├── config.php                 Live app configuration (gitignored)
├── config_local.php           Local-dev config template
├── config_production.php      Production config template
├── db.php                     PDO connection + shared head/body injection
├── auth.php                   Session & auth helpers
├── functions.php              Shared helper functions + cron sweep functions
├── marketplace_functions.php  Marketplace/Fast-Payout business logic
├── accommodation_functions.php Accommodation business logic
├── delivery_functions.php     Delivery Services business logic
├── paystack.php               Paystack API layer (payments, transfers, subaccounts)
├── _cron.php                  Scheduled expiry/release sweep entrypoint
└── install.sql                Master, idempotent database migration
```

---

## Launch Checklist

- [ ] Set `BASE_URL` to the production origin (e.g. `https://akuapemconnect.com`) in `config.php`
- [ ] Set `MAIL_FROM` / `ADMIN_EMAIL` to verified sending/contact addresses
- [ ] Enter live Paystack keys via Admin → Monetization and set mode to `live`
- [ ] Set up the Paystack webhook pointing to `{BASE_URL}/paystack_webhook.php`
- [ ] Enable HTTPS on the server
- [ ] Set `uploads/` directory permissions to allow web-server writes
- [ ] Schedule `_cron.php` (Windows Task Scheduler / web cron) to run regularly — it releases marketplace payouts, cancels abandoned orders, expires featured listings, and sweeps escrow/Fast Payout settlement
- [ ] Remove or password-protect the individual `migrate*.php` scripts
- [ ] Review platform fee settings (most default to `0` / free until an admin opts in)
- [ ] If Fast Payout will be offered, verify subaccount creation and the `transaction_charge` split against Paystack **test mode** before enabling the module switch for real sellers

---

## Operational Notes

**Cloudflare / caching.** This app is session- and CSRF-token-driven (`csrf_field()` on every form), so caching a dynamic page would risk serving one visitor's session state or CSRF token to another — a broken form ("Security check failed") at best, a session leak at worst. Don't enable "Cache Everything" or aggressive page-rule caching for logged-in pages. Cloudflare's default behavior (respecting `Set-Cookie`, not caching HTML by default — see the `Cache-Control: no-store` header already sent by `config.php`) is the safe mode; only extend caching to genuinely static paths like `assets/*`.
