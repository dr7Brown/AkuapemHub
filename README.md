# AkuapemHub

A mobile-first community platform for Ghana — connecting skilled workers with customers, while serving as a digital hub for local news, events, funeral announcements, and community life across the Akuapem area.

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

### Community
- **Events** — submit, browse, and promote local events; admin review before publication
- **Funeral Announcements** — post burial/wake-keeping/thanksgiving notices; admin moderation
- **News & Articles** — submit local news; admin-reviewed before going live
- **Advertisements** — banner and sponsored ad slots managed by admin

### Moderation & Rejection Workflow
- Admins (and managers) approve or reject jobs, events, funerals, and news
- Rejections include a reason shown directly to the user
- Users can edit a rejected item and resubmit without paying again
- Admin audit log tracks every moderation action

### Roles
| Role | Access |
|------|--------|
| Customer | Post jobs, hire workers, manage escrow, rate workers |
| Worker | Browse & apply for jobs, manage profile, earn ratings |
| Manager | Approve job applications + community content (cannot access full admin panel) |
| Admin | Full platform management, analytics, user management, payments, disputes |

### Payments (Paystack)
- Escrow payments for service jobs
- Optional publishing fees for job posts, events, news, and funeral announcements
- Optional featured-job and featured-worker promotion fees
- Optional worker verification badge fee
- All keys and modes configured via the admin panel (no code changes needed)

### Referrals & Points
- Unique referral codes per user
- Points awarded for: registration, email verification, profile completion, referrals, hiring, completing jobs, 5-star ratings
- Points wallet and transaction history
- All point values configurable from the admin panel

### Messaging & Notifications
- In-platform chat between job owner and applicant / hired worker
- Admin-granted direct messaging
- In-app notification centre (info / success / warning / error)
- Email notifications (opt-out per user)
- Business WhatsApp/SMS event logging

### Security
- bcrypt password hashing
- Email verification with OTP-style token
- Password reset via email OTP
- CSRF protection on all forms
- Prepared statements throughout
- Session hardening (HttpOnly, SameSite, 2-hour idle timeout)
- HTTP security headers
- Spam/fraud signals on request review (rapid posting, shared contact info, repeated low-budget requests)

---

## Setup (fresh install)

### Requirements
- XAMPP (PHP 8.x, MariaDB 10.4+, Apache)
- A Paystack account (test keys are fine to start)

### Steps

1. **Clone / place files**
   ```
   c:\xampp\htdocs\AkuapemHub\
   ```

2. **Create the database**
   Open phpMyAdmin → New → name it `akuapemhub` → Create.

3. **Run the master migration**
   Import `install.sql` via phpMyAdmin (SQL tab) **or** run from the command line:
   ```
   mysql -u root -p akuapemhub < install.sql
   ```
   This single file creates all tables, seeds categories, towns, default packages, and platform settings in the correct order (v001–v010). It is idempotent — safe to re-run on an existing database.

4. **Configure the app**
   Edit `config.php`:
   ```php
   define('DB_HOST',     'localhost');
   define('DB_NAME',     'akuapemhub');
   define('DB_USER',     'root');
   define('DB_PASS',     '');
   define('APP_NAME',    'AkuapemHub');
   define('APP_URL',     'http://localhost/AkuapemHub');
   define('MAIL_FROM',   'noreply@akuapemhub.com');
   ```

5. **Set Paystack keys**
   Log in as admin → Settings → enter your Paystack public key, secret key, webhook secret, and set mode to `test` or `live`.

6. **Create the first admin**
   Register a normal account, then in phpMyAdmin set `role = 'admin'` in the `users` table for that user.

7. **Visit the site**
   ```
   http://localhost/AkuapemHub
   ```

---

## Key Pages

### Public (no login required)
| Page | Purpose |
|------|---------|
| `index.php` | Home — jobs, events, news, funeral announcements |
| `browse_jobs.php` | Public job listings with search & filter |
| `find_workers.php` | Browse worker profiles |
| `events.php` / `event.php` | Event listings and detail |
| `funerals.php` / `funeral.php` | Funeral announcement listings and detail |
| `news.php` / `news_article.php` | News listings and full article |
| `about.php` | Platform overview and how-it-works |
| `support.php` | FAQ and contact information |
| `terms.php` | Terms of Service |
| `privacy.php` | Privacy Policy |
| `login.php` / `register.php` | Authentication |

### Authenticated Users
| Page | Purpose |
|------|---------|
| `jobs.php` | Job dashboard (role-aware — workers see open jobs, customers see their posts) |
| `request.php` | Create / edit a service request |
| `request_detail.php` | Full job view with apply / hire / complete / escrow actions |
| `my_events.php` | Manage submitted events |
| `my_funerals.php` | Manage submitted funeral announcements |
| `my_news.php` | Manage submitted news articles |
| `worker_profile.php` | Edit worker profile, skills, availability |
| `worker_profile_public.php` | Public-facing worker profile |
| `manage_applicants.php` | Review and approve/reject job applicants |
| `messages.php` | Per-job messaging thread |
| `chat.php` | In-platform direct chat |
| `notifications.php` | Notification centre |
| `referrals.php` | Referral code, stats, points wallet |
| `settings.php` | Account settings (name, email, phone, location, password) |
| `rate_job.php` | Post-job rating flow |
| `pay_*.php` | Paystack checkout pages (escrow, job post, event, news, funeral) |

### Admin Panel (`admin/`)
| Page | Purpose |
|------|---------|
| `index.php` | Admin dashboard — key stats and quick actions |
| `users.php` | User management, role assignment, ban/unban |
| `requests.php` | Job moderation — approve, reject, flag |
| `events.php` / `event_edit.php` | Event moderation and editing |
| `funerals.php` / `funeral_edit.php` | Funeral announcement moderation |
| `news.php` / `news_edit.php` | News moderation and editing |
| `ads.php` / `ads_edit.php` | Advertisement management |
| `payments.php` | Platform payments log (escrow, fees, promotions) |
| `analytics.php` | Detailed platform analytics |
| `disputes.php` | Dispute queue and resolution workflow |
| `workers.php` | Worker profile management, verification approval |
| `referrals.php` | Referral programme overview |
| `settings.php` | Platform settings — Paystack keys, fees, chat rules, monetization mode |
| `business_messages.php` | WhatsApp/SMS event log |
| `audit_log.php` | Full admin action audit trail |

---

## Migration Versioning

`install.sql` is the single source of truth. The 10 internal versions it covers:

| Version | What it adds |
|---------|-------------|
| v001 | All 29 core tables + seeds (towns, categories, packages, settings) |
| v002 | Password reset OTP tables + `users.password_changed_at` |
| v003 | Email verification columns + backfills existing accounts as verified |
| v004 | Escrow: extends `service_requests` ENUMs, adds `escrow_payments` |
| v005 | `platform_payments.admin_notes` + `flagged` columns |
| v006 | 5 referral/points tables + 18 `platform_settings` seeds |
| v007 | Community tables: `news`, `events`, `funeral_announcements`, `advertisements` |
| v008 | Extends `payment_type` ENUM + adds `pending_payment` to news/events status |
| v009 | `locations` table + `location_id` FK on events, funerals, service requests |
| v010 | `rejection_reason VARCHAR(500)` on 4 tables + `draft`/`rejected` status values |

> The individual `migrate*.php` scripts remain in the repo for reference but are superseded by `install.sql`.

---

## Configuration Reference

Key constants in `config.php`:

| Constant | Purpose |
|----------|---------|
| `DB_*` | Database connection |
| `APP_NAME` | Displayed site name |
| `APP_URL` | Base URL (no trailing slash) |
| `MAIL_FROM` | Sender address for system emails |
| `UPLOAD_MAX_MB` | Max file upload size |

Paystack keys and all monetization toggles are stored in the `platform_settings` table and managed via **Admin → Settings** — no code changes required after initial setup.

---

## Folder Structure

```
AkuapemHub/
├── admin/              Admin panel pages
├── assets/
│   ├── css/            Global stylesheet (style.css)
│   └── images/
│       └── heroes/     Hero background photos (hero-home, hero-about, hero-jobs)
├── modules/
│   └── referrals/      Referral & points module (service.php, migrate.php)
├── partials/           Shared UI fragments (bottom_nav, etc.)
├── uploads/            User-uploaded files (photos, ID docs, event images…)
├── config.php          App configuration
├── db.php              PDO connection
├── auth.php            Session & auth helpers
├── functions.php       Shared helper functions
├── EmailService.php    Email sending wrapper
└── install.sql         Master database migration (run once on a fresh DB)
```

---

## Launch Checklist

- [ ] Set `APP_URL` to production domain in `config.php`
- [ ] Set `MAIL_FROM` to a verified sending address
- [ ] Enter live Paystack keys via Admin → Settings and set mode to `live`
- [ ] Set up Paystack webhook pointing to `{APP_URL}/paystack.php`
- [ ] Enable HTTPS on the server
- [ ] Set `uploads/` directory permissions to allow web-server writes
- [ ] Remove or password-protect the individual `migrate*.php` scripts
- [ ] Review platform fee settings (all default to `0` / free)
