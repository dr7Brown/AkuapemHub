<?php
/**
 * functionalities.php — AkuapemConnect System Documentation
 * Complete catalogue of every feature and functionality by service.
 * Access: admin only.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_login();
if (!is_admin()) { header('Location: jobs.php'); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>System Functionalities — AkuapemConnect</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;font-size:14px;color:#1a2230;background:#f5f7fa;line-height:1.6}
a{color:#0f766e;text-decoration:none}a:hover{text-decoration:underline}

/* Banner */
.banner{background:linear-gradient(135deg,#0f766e 0%,#065f46 100%);color:#fff;padding:28px 36px 22px}
.banner h1{font-size:1.5rem;font-weight:900;margin:0 0 4px}
.banner p{font-size:.9rem;opacity:.82;margin:0}
.banner-meta{display:flex;gap:28px;margin-top:14px;flex-wrap:wrap}
.banner-meta span{font-size:.78rem;opacity:.75}
.banner-meta strong{display:block;font-size:1.2rem;font-weight:900}

/* Sidebar + content */
.layout{display:grid;grid-template-columns:260px 1fr;min-height:calc(100vh - 120px)}
.sidebar{background:#fff;border-right:1px solid #e5e7eb;padding:20px 0;position:sticky;top:0;height:100vh;overflow-y:auto}
.sidebar-title{font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#9ca3af;padding:0 18px 8px}
.sidebar a{display:flex;align-items:center;gap:8px;padding:8px 18px;font-size:.84rem;font-weight:600;color:#374151;transition:background .1s}
.sidebar a:hover{background:#f0fdf4;color:#0f766e;text-decoration:none}
.sidebar a.active{background:#d1fae5;color:#065f46;border-right:3px solid #0f766e}
.sidebar-section{margin-top:10px;padding-top:10px;border-top:1px solid #f1f5f9}

/* Main */
.main{padding:32px 40px 80px;max-width:1000px}
.service{margin-bottom:56px;scroll-margin-top:20px}
.service-header{display:flex;align-items:center;gap:12px;margin-bottom:20px;padding-bottom:12px;border-bottom:2px solid #e5e7eb}
.service-icon{font-size:1.8rem;flex-shrink:0}
.service-title{font-size:1.25rem;font-weight:900;color:#1a2230}
.service-subtitle{font-size:.84rem;color:#6b7280;margin-top:2px}

/* Feature groups */
.feat-group{margin-bottom:18px}
.feat-group-title{font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#9ca3af;margin-bottom:8px;display:flex;align-items:center;gap:6px}
.feat-group-title::after{content:'';flex:1;height:1px;background:#f1f5f9}

/* Feature list */
.feat-list{list-style:none;display:flex;flex-direction:column;gap:5px}
.feat-list li{display:flex;align-items:baseline;gap:8px;padding:5px 10px;border-radius:7px;font-size:.86rem;background:#fafafa;border:1px solid #f1f5f9}
.feat-list li .dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;margin-top:4px}
.feat-list li .tag{font-size:.65rem;font-weight:800;padding:1px 6px;border-radius:10px;white-space:nowrap;flex-shrink:0}
.tag-user   {background:#dbeafe;color:#1e40af}
.tag-admin  {background:#fce7f3;color:#9d174d}
.tag-mod    {background:#fef9c3;color:#854d0e}
.tag-system {background:#f3e8ff;color:#6b21a8}
.tag-cron   {background:#d1fae5;color:#065f46}
.tag-pay    {background:#fff7ed;color:#9a3412}

/* Files */
.files{margin-top:8px;display:flex;flex-wrap:wrap;gap:5px}
.file-chip{font-size:.68rem;font-family:monospace;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:5px;padding:1px 7px;color:#4b5563}

/* Status */
.status-ok   {background:#d1fae5;color:#065f46;font-size:.7rem;font-weight:800;padding:2px 8px;border-radius:10px}
.status-note {background:#fef3c7;color:#92400e;font-size:.7rem;font-weight:800;padding:2px 8px;border-radius:10px}

@media(max-width:840px){
    .layout{grid-template-columns:1fr}
    .sidebar{display:none}
    .main{padding:20px 18px 60px}
}
</style>
</head>
<body>

<div class="banner">
    <h1>⚙️ AkuapemConnect — System Functionalities</h1>
    <p>Complete catalogue of every feature, flow, notification, and background process by service.</p>
    <div class="banner-meta">
        <div><strong>17</strong><span>Services & Modules</span></div>
        <div><strong><?php echo date('d M Y'); ?></strong><span>Last generated</span></div>
        <div>
            <span style="display:inline-block;margin-right:10px;"><span class="tag tag-user">USER</span> User action</span>
            <span style="display:inline-block;margin-right:10px;"><span class="tag tag-admin">ADMIN</span> Admin only</span>
            <span style="display:inline-block;margin-right:10px;"><span class="tag tag-mod">MOD</span> Admin or Moderator</span>
            <span style="display:inline-block;margin-right:10px;"><span class="tag tag-system">SYSTEM</span> Auto / backend</span>
            <span style="display:inline-block;"><span class="tag tag-cron">CRON</span> Scheduled task</span>
        </div>
    </div>
</div>

<div class="layout">
<nav class="sidebar">
    <div class="sidebar-title">Services</div>
    <a href="#auth">🔐 Authentication</a>
    <a href="#jobs">💼 Jobs & Requests</a>
    <a href="#workers">👷 Worker Profiles</a>
    <a href="#news">📰 News</a>
    <a href="#events">📅 Events</a>
    <a href="#funerals">🕊️ Funerals</a>
    <a href="#marketplace">🛍️ Marketplace</a>
    <a href="#delivery">🚚 Delivery</a>
    <a href="#payments">💳 Payments</a>
    <a href="#notifications">🔔 Notifications</a>
    <a href="#chat">💬 Chat</a>
    <div class="sidebar-section">
    <div class="sidebar-title">Platform</div>
    <a href="#moderation">🛡️ Moderation & COI</a>
    <a href="#admin">⚙️ Admin Dashboard</a>
    <a href="#monetization">💰 Monetization</a>
    <a href="#referrals">🎁 Referrals & Points</a>
    <a href="#cron">⏰ Cron & Automation</a>
    </div>
</nav>

<main class="main">

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="service" id="auth">
    <div class="service-header">
        <div class="service-icon">🔐</div>
        <div>
            <div class="service-title">Authentication & User Management</div>
            <div class="service-subtitle">Registration, login, email verification, password reset, sessions</div>
        </div>
    </div>

    <div class="feat-group">
        <div class="feat-group-title">Registration</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Register with name, username, email, phone, password and optional profile photo. Role defaults to <code>customer</code>.</li>
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Register as worker — same form with role=worker; creates a <code>worker_profiles</code> record automatically.</li>
            <li><div class="dot" style="background:#8b5cf6"></div><span class="tag tag-system">SYSTEM</span> On register: verification email sent, referral code applied if present, points wallet created, referral code generated.</li>
        </ul>
    </div>

    <div class="feat-group">
        <div class="feat-group-title">Email Verification</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#10b981"></div><span class="tag tag-system">SYSTEM</span> Token emailed on register. User clicks link → <code>email_verified=1</code>.</li>
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Can resend verification email from settings. Only blocks job-posting and job-applying until verified.</li>
        </ul>
    </div>

    <div class="feat-group">
        <div class="feat-group-title">Login & Security</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Login by email + password. Banned accounts cannot login.</li>
            <li><div class="dot" style="background:#8b5cf6"></div><span class="tag tag-system">SYSTEM</span> Rate limiting: 5 failed attempts per IP per 15 min triggers a lockout.</li>
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Forgot password: enters email → OTP sent → enters OTP → sets new password. OTP expires after 15 min.</li>
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Change password from Settings (requires current password).</li>
        </ul>
    </div>

    <div class="feat-group">
        <div class="feat-group-title">Admin User Management</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> Search, filter, view all users. Filter by role, banned status, verified status.</li>
            <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> Ban / unban individual users. Bulk ban/unban/delete.</li>
            <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> Promote user to Manager role (moderator). Demote back to customer/worker.</li>
            <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> Grant/revoke granular moderator permissions per manager account.</li>
        </ul>
    </div>

    <div class="files"><span class="file-chip">login.php</span><span class="file-chip">register.php</span><span class="file-chip">verify_email.php</span><span class="file-chip">verify_otp.php</span><span class="file-chip">settings.php</span><span class="file-chip">admin/users.php</span><span class="file-chip">admin/moderators.php</span></div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="service" id="jobs">
    <div class="service-header">
        <div class="service-icon">💼</div>
        <div>
            <div class="service-title">Jobs & Service Requests</div>
            <div class="service-subtitle">Post jobs, apply, hire, escrow, complete, rate — core marketplace loop</div>
        </div>
    </div>

    <div class="feat-group">
        <div class="feat-group-title">Posting a Job</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Customer fills: title, description, category, location (Akuapem towns or custom), budget (text + numeric), skills needed, workers needed (1–many), job type (on-site/remote/hybrid), deadline, payment mode (direct or escrow).</li>
            <li><div class="dot" style="background:#8b5cf6"></div><span class="tag tag-system">SYSTEM</span> If posting fee enabled: status → <code>pending_payment</code>, user redirected to pay. After payment: status → <code>pending</code>.</li>
            <li><div class="dot" style="background:#8b5cf6"></div><span class="tag tag-system">SYSTEM</span> If escrow mode: user redirected to escrow checkout. Escrow payment held → status → <code>pending</code>.</li>
            <li><div class="dot" style="background:#8b5cf6"></div><span class="tag tag-system">SYSTEM</span> Workers with matching skills receive a notification when a matching job is approved and posted.</li>
        </ul>
    </div>

    <div class="feat-group">
        <div class="feat-group-title">Moderation</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#f59e0b"></div><span class="tag tag-mod">MOD</span> Admin/moderator approves job → status <code>open</code>. Workers can now see and apply.</li>
            <li><div class="dot" style="background:#f59e0b"></div><span class="tag tag-mod">MOD</span> Reject with reason → status <code>rejected</code>. Customer notified with reason + direct link to edit.</li>
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Customer edits rejected job and resubmits → status back to <code>pending</code>, admin notified.</li>
            <li><div class="dot" style="background:#ef4444"></div><span class="tag tag-system">SYSTEM</span> COI enforced: moderator cannot approve/reject their own job post.</li>
        </ul>
    </div>

    <div class="feat-group">
        <div class="feat-group-title">Applying & Hiring</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Worker applies to an open job. Customer receives notification.</li>
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Customer reviews applicants, approves one or more workers. Workers notified.</li>
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Job status: <code>partially_staffed</code> (some positions filled) or <code>fully_staffed</code>.</li>
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Worker can withdraw application before approval.</li>
        </ul>
    </div>

    <div class="feat-group">
        <div class="feat-group-title">Completion & Escrow Release</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Worker marks job complete → customer notified to confirm.</li>
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Customer confirms completion → job status <code>completed</code>.</li>
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> For escrow jobs: customer manually releases funds, OR auto-release fires after <code>auto_release_days</code>.</li>
            <li><div class="dot" style="background:#10b981"></div><span class="tag tag-cron">CRON</span> Daily: auto-release escrow if <code>auto_release_at</code> has passed and job is completed. Both parties notified.</li>
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Customer rates worker (1–5 stars + comment) after completion.</li>
        </ul>
    </div>

    <div class="feat-group">
        <div class="feat-group-title">Featured Jobs</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Customer pays to feature job → appears at top of listings with ⭐ badge.</li>
            <li><div class="dot" style="background:#10b981"></div><span class="tag tag-cron">CRON</span> Daily: <code>featured=0</code> when <code>featured_end_date</code> passes. 2-day advance warning sent.</li>
        </ul>
    </div>

    <div class="feat-group">
        <div class="feat-group-title">Deadlines & Expiry</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#10b981"></div><span class="tag tag-cron">CRON</span> Reminder notifications: 7 days, 3 days, 1 day before deadline (each fires once via flag column).</li>
            <li><div class="dot" style="background:#10b981"></div><span class="tag tag-cron">CRON</span> Job auto-expires after deadline → status <code>expired</code>, open applications closed, workers notified.</li>
        </ul>
    </div>

    <div class="files"><span class="file-chip">request.php</span><span class="file-chip">request_detail.php</span><span class="file-chip">jobs.php</span><span class="file-chip">browse_jobs.php</span><span class="file-chip">apply_job.php</span><span class="file-chip">my_applications.php</span><span class="file-chip">escrow_checkout.php</span><span class="file-chip">feature_job.php</span><span class="file-chip">pay_job_post.php</span><span class="file-chip">admin/requests.php</span></div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="service" id="workers">
    <div class="service-header">
        <div class="service-icon">👷</div>
        <div>
            <div class="service-title">Worker Profiles & Find Workers</div>
            <div class="service-subtitle">Profile creation, skills, verification, service listing, featured placement</div>
        </div>
    </div>

    <div class="feat-group">
        <div class="feat-group-title">Profile</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Worker creates profile: bio, location, skills (multi-select), contact phone, availability status (available / busy / offline).</li>
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Uploads ID document for verification request.</li>
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Sets availability slots (days/times) per week.</li>
        </ul>
    </div>

    <div class="feat-group">
        <div class="feat-group-title">Service Listing (Pay to appear in Find Workers)</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> If service listing fee enabled: worker pays to activate listing. Status: <code>free → paid</code>.</li>
            <li><div class="dot" style="background:#10b981"></div><span class="tag tag-cron">CRON</span> 7-day expiry warning sent (once). Listing reverts to free on expiry date.</li>
        </ul>
    </div>

    <div class="feat-group">
        <div class="feat-group-title">Verification Badge</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Worker submits ID via <code>request_verification.php</code>. Status → <code>pending</code>.</li>
            <li><div class="dot" style="background:#f59e0b"></div><span class="tag tag-mod">MOD</span> Admin approves → <code>is_verified=1</code>, worker notified with badge.</li>
            <li><div class="dot" style="background:#f59e0b"></div><span class="tag tag-mod">MOD</span> Admin rejects with reason → worker notified.</li>
            <li><div class="dot" style="background:#10b981"></div><span class="tag tag-cron">CRON</span> Badge auto-expires on <code>verification_expiry</code> date → status <code>expired</code>.</li>
        </ul>
    </div>

    <div class="feat-group">
        <div class="feat-group-title">Featured Profile</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Worker buys featured package → appears at top of Find Workers with ⭐.</li>
            <li><div class="dot" style="background:#10b981"></div><span class="tag tag-cron">CRON</span> <code>is_featured=0</code> on expiry. 2-day advance warning notification sent.</li>
        </ul>
    </div>

    <div class="feat-group">
        <div class="feat-group-title">Find Workers (Public)</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Browse workers filtered by skill category, location, availability, rating. Featured workers sorted to top.</li>
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> View individual worker profile, ratings, completed jobs, verification badge.</li>
        </ul>
    </div>

    <div class="files"><span class="file-chip">worker_profile.php</span><span class="file-chip">find_workers.php</span><span class="file-chip">request_verification.php</span><span class="file-chip">feature_worker.php</span><span class="file-chip">pay_worker_service.php</span><span class="file-chip">become_worker.php</span></div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="service" id="news">
    <div class="service-header">
        <div class="service-icon">📰</div>
        <div>
            <div class="service-title">Community News</div>
            <div class="service-subtitle">User-submitted articles, admin publishing, featured placement</div>
        </div>
    </div>
    <div class="feat-group">
        <div class="feat-group-title">Submission & Publishing</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Submit article: title, summary, full content (rich text), featured image.</li>
            <li><div class="dot" style="background:#8b5cf6"></div><span class="tag tag-system">SYSTEM</span> If submission fee enabled → Paystack checkout. After payment → status <code>draft</code>.</li>
            <li><div class="dot" style="background:#f59e0b"></div><span class="tag tag-mod">MOD</span> Admin/moderator publishes (toggle) → visible on news.php. All users notified once on first publish.</li>
            <li><div class="dot" style="background:#f59e0b"></div><span class="tag tag-mod">MOD</span> Reject with reason → user sees ❌ card + Fix & Resubmit button. Admin notified on resubmit.</li>
            <li><div class="dot" style="background:#ef4444"></div><span class="tag tag-system">SYSTEM</span> COI enforced: moderator cannot publish their own article.</li>
        </ul>
    </div>
    <div class="feat-group">
        <div class="feat-group-title">Featured Articles</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Pay to feature a published article → ⭐ banner, sorted to top of feed.</li>
            <li><div class="dot" style="background:#10b981"></div><span class="tag tag-cron">CRON</span> <code>featured=0</code> on expiry. 2-day advance warning notification sent.</li>
        </ul>
    </div>
    <div class="feat-group">
        <div class="feat-group-title">Public Reading</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Browse news feed (latest/popular filter). View count increments per visit. Like and save articles.</li>
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Comment on articles. Comments moderated.</li>
            <li><div class="dot" style="background:#8b5cf6"></div><span class="tag tag-system">SYSTEM</span> Non-logged-in visitors can read all published articles.</li>
        </ul>
    </div>
    <div class="files"><span class="file-chip">news.php</span><span class="file-chip">news_article.php</span><span class="file-chip">my_news.php</span><span class="file-chip">feature_news.php</span><span class="file-chip">pay_news.php</span><span class="file-chip">admin/news.php</span><span class="file-chip">admin/news_edit.php</span></div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="service" id="events">
    <div class="service-header">
        <div class="service-icon">📅</div>
        <div>
            <div class="service-title">Community Events</div>
            <div class="service-subtitle">Event listings, ticket types, approval, featuring</div>
        </div>
    </div>
    <div class="feat-group">
        <div class="feat-group-title">Submission & Approval</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Submit event: title, description, venue, dates/times, organiser, ticket type (free/paid/registration), optional Google Maps link.</li>
            <li><div class="dot" style="background:#8b5cf6"></div><span class="tag tag-system">SYSTEM</span> If event fee enabled → Paystack checkout. After payment → status <code>draft</code> for admin review.</li>
            <li><div class="dot" style="background:#f59e0b"></div><span class="tag tag-mod">MOD</span> Admin publishes → visible on events.php. Admin/mod rejects with reason → user notified + Fix & Resubmit.</li>
            <li><div class="dot" style="background:#ef4444"></div><span class="tag tag-system">SYSTEM</span> COI enforced: moderator cannot publish their own event.</li>
        </ul>
    </div>
    <div class="feat-group">
        <div class="feat-group-title">Featured Events</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Pay to feature a published event → ⭐ badge, sorted to top.</li>
            <li><div class="dot" style="background:#10b981"></div><span class="tag tag-cron">CRON</span> <code>featured=0</code> on expiry. 2-day advance warning sent.</li>
        </ul>
    </div>
    <div class="feat-group">
        <div class="feat-group-title">Public Browsing</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Filter events by search, date/month, location. Non-logged-in visitors can browse.</li>
        </ul>
    </div>
    <div class="files"><span class="file-chip">events.php</span><span class="file-chip">event.php</span><span class="file-chip">my_events.php</span><span class="file-chip">feature_event.php</span><span class="file-chip">pay_event.php</span><span class="file-chip">admin/events.php</span><span class="file-chip">admin/event_edit.php</span></div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="service" id="funerals">
    <div class="service-header">
        <div class="service-icon">🕊️</div>
        <div>
            <div class="service-title">Funeral Announcements</div>
            <div class="service-subtitle">Obituaries, burial notices, featuring for community reach</div>
        </div>
    </div>
    <div class="feat-group">
        <div class="feat-group-title">Submission & Approval</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Submit: deceased name, gender, age, dates (death/burial/wake/thanksgiving), venue, biography, photos, organiser details, Google Maps link.</li>
            <li><div class="dot" style="background:#8b5cf6"></div><span class="tag tag-system">SYSTEM</span> If fee enabled → Paystack checkout. After payment → status <code>pending</code>.</li>
            <li><div class="dot" style="background:#f59e0b"></div><span class="tag tag-mod">MOD</span> Admin approves → visible on funerals.php. Rejects with reason → user notified + Fix & Resubmit. Admin notified on resubmission.</li>
        </ul>
    </div>
    <div class="feat-group">
        <div class="feat-group-title">Featured Announcements</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Pay to feature → ⭐ badge, sorted to top. Reaches more community members during mourning period.</li>
            <li><div class="dot" style="background:#10b981"></div><span class="tag tag-cron">CRON</span> <code>featured=0</code> on expiry. 2-day advance warning sent.</li>
        </ul>
    </div>
    <div class="feat-group">
        <div class="feat-group-title">Public Browsing</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Filter by search, gender, month/year, venue/location. Sort by burial date or newest. Load-more pagination.</li>
        </ul>
    </div>
    <div class="files"><span class="file-chip">funerals.php</span><span class="file-chip">funeral.php</span><span class="file-chip">my_funerals.php</span><span class="file-chip">feature_funeral.php</span><span class="file-chip">pay_funeral.php</span><span class="file-chip">admin/funerals.php</span><span class="file-chip">admin/funeral_edit.php</span></div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="service" id="marketplace">
    <div class="service-header">
        <div class="service-icon">🛍️</div>
        <div>
            <div class="service-title">Marketplace</div>
            <div class="service-subtitle">Shops, products, cart, orders, boosts, subscriptions</div>
        </div>
    </div>
    <div class="feat-group">
        <div class="feat-group-title">Shop Setup</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Seller creates shop: name, description, logo, banner, phone, email, region. Slug auto-generated.</li>
            <li><div class="dot" style="background:#f59e0b"></div><span class="tag tag-mod">MOD</span> Admin verifies shop (reviews ID/business reg). Verified badge shown on shop.</li>
        </ul>
    </div>
    <div class="feat-group">
        <div class="feat-group-title">Products</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Seller adds product: name, description, category, price, discount price, stock, SKU, condition (new/used/refurbished), up to 5 images, delivery availability.</li>
            <li><div class="dot" style="background:#f59e0b"></div><span class="tag tag-mod">MOD</span> Admin approves product → visible in marketplace. Rejects with reason → seller notified with link to edit and resubmit.</li>
            <li><div class="dot" style="background:#ef4444"></div><span class="tag tag-system">SYSTEM</span> COI enforced: moderator cannot approve their own product.</li>
            <li><div class="dot" style="background:#8b5cf6"></div><span class="tag tag-system">SYSTEM</span> When product resubmitted after rejection: <code>rejection_reason</code> cleared, moderators notified.</li>
        </ul>
    </div>
    <div class="feat-group">
        <div class="feat-group-title">Buying Flow</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Browse products, filter by category/price/condition. Add to cart, save products.</li>
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Checkout: enter delivery address, receiver name/phone, payment method. Order placed → seller notified.</li>
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Seller updates order status: pending → confirmed → processing → ready for delivery → (auto: delivery request created) → delivered.</li>
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Buyer leaves product/seller review after delivery.</li>
        </ul>
    </div>
    <div class="feat-group">
        <div class="feat-group-title">Boosts (Featured / Sponsored)</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Seller chooses boost type: Featured Product, Sponsored Product, Featured Shop, Sponsored Shop. Selects duration package. Pays via Paystack.</li>
            <li><div class="dot" style="background:#8b5cf6"></div><span class="tag tag-system">SYSTEM</span> On payment confirmed: boost activated automatically. <code>is_featured / is_sponsored</code> flags set on product/shop.</li>
            <li><div class="dot" style="background:#10b981"></div><span class="tag tag-cron">CRON</span> Daily: expired boost flags cleared. Boost orders marked <code>expired</code>. 2-day advance warning sent.</li>
            <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> Admin can manually activate pending boost orders (for non-Paystack payments).</li>
        </ul>
    </div>
    <div class="feat-group">
        <div class="feat-group-title">Seller Subscriptions</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Seller subscribes to Starter / Growth / Pro plan → <code>mp_shops.is_subscribed=1</code>. Benefits per plan (product limits, priority in search).</li>
            <li><div class="dot" style="background:#10b981"></div><span class="tag tag-cron">CRON</span> Daily: expired subscriptions reset, <code>is_subscribed=0</code>, seller notified to renew.</li>
        </ul>
    </div>
    <div class="files"><span class="file-chip">marketplace.php</span><span class="file-chip">product.php</span><span class="file-chip">shop.php</span><span class="file-chip">cart.php</span><span class="file-chip">seller_dashboard.php</span><span class="file-chip">seller_product_form.php</span><span class="file-chip">seller_boost.php</span><span class="file-chip">pay_mp_boost.php</span><span class="file-chip">pay_mp_subscription.php</span><span class="file-chip">admin/marketplace.php</span></div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="service" id="delivery">
    <div class="service-header">
        <div class="service-icon">🚚</div>
        <div>
            <div class="service-title">Delivery Services</div>
            <div class="service-subtitle">Rider registration, delivery requests, tracking, premium/sponsored/verification</div>
        </div>
    </div>
    <div class="feat-group">
        <div class="feat-group-title">Rider Registration</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Register as delivery agent: vehicle type, service area, bio, ID upload.</li>
            <li><div class="dot" style="background:#f59e0b"></div><span class="tag tag-mod">MOD</span> Admin approves rider → status <code>approved</code>, rider can now accept deliveries. COI enforced.</li>
        </ul>
    </div>
    <div class="feat-group">
        <div class="feat-group-title">Delivery Request Flow</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Customer creates request: pickup/dropoff address + Google Maps links, item description, category, fee, preferred date/time, receiver details.</li>
            <li><div class="dot" style="background:#f59e0b"></div><span class="tag tag-mod">MOD</span> Admin approves request → visible to riders. COI enforced.</li>
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Rider applies. Customer selects rider. Status updates: picked up → in transit → delivered.</li>
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Customer and rider rate each other after delivery.</li>
        </ul>
    </div>
    <div class="feat-group">
        <div class="feat-group-title">Rider Monetization</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> <strong>Premium subscription</strong>: monthly/quarterly/yearly → priority in listings, premium badge.</li>
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> <strong>Sponsored listing</strong>: pay for 7/30/90-day boost → appears at top of rider search results.</li>
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> <strong>Verified Rider badge</strong>: submit Ghana card + driving licence → admin approves → badge shown.</li>
            <li><div class="dot" style="background:#10b981"></div><span class="tag tag-cron">CRON</span> Premium and sponsored expirations cleared daily. 3-day warning sent to rider.</li>
        </ul>
    </div>
    <div class="files"><span class="file-chip">delivery.php</span><span class="file-chip">delivery_detail.php</span><span class="file-chip">delivery_agent_jobs.php</span><span class="file-chip">delivery_subscribe.php</span><span class="file-chip">delivery_sponsor.php</span><span class="file-chip">delivery_verify.php</span><span class="file-chip">delivery_ajax.php</span><span class="file-chip">admin/delivery.php</span></div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="service" id="payments">
    <div class="service-header">
        <div class="service-icon">💳</div>
        <div>
            <div class="service-title">Payments & Receipts</div>
            <div class="service-subtitle">Paystack checkout, callbacks, webhook, receipts, resume flow</div>
        </div>
    </div>
    <div class="feat-group">
        <div class="feat-group-title">Payment Types</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-pay">PAY</span> Featured job · Featured worker · Verification badge · Job posting fee · Worker service listing</li>
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-pay">PAY</span> Escrow payment · Escrow + posting fee combined</li>
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-pay">PAY</span> News publishing fee · Event publishing fee · Funeral announcement fee</li>
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-pay">PAY</span> Featured news · Featured event · Featured funeral</li>
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-pay">PAY</span> Marketplace boost · Marketplace seller subscription</li>
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-pay">PAY</span> Delivery subscription · Delivery sponsored listing · Rider verification fee</li>
        </ul>
    </div>
    <div class="feat-group">
        <div class="feat-group-title">Checkout Flow</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#8b5cf6"></div><span class="tag tag-system">SYSTEM</span> <code>initializePayment()</code> creates <code>platform_payments</code> record (status=pending), stores unique reference (<code>AH-TYPE-TIMESTAMP-RANDOM6</code>) and <code>authorization_url</code>, redirects to Paystack.</li>
            <li><div class="dot" style="background:#8b5cf6"></div><span class="tag tag-system">SYSTEM</span> On success: Paystack calls <code>paystack_callback.php</code> → <code>verifyPayment()</code> → activates feature, notifies user, shows receipt.</li>
            <li><div class="dot" style="background:#8b5cf6"></div><span class="tag tag-system">SYSTEM</span> Webhook (<code>paystack_webhook.php</code>) provides secondary confirmation for missed callbacks.</li>
        </ul>
    </div>
    <div class="feat-group">
        <div class="feat-group-title">Resume Abandoned Payments</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Abandoned checkout stays as pending. My Payments page shows "Continue Checkout" or "Restart Payment" button.</li>
            <li><div class="dot" style="background:#8b5cf6"></div><span class="tag tag-system">SYSTEM</span> <code>resume_payment.php</code>: if URL < 45 min old → redirect to stored URL. Otherwise re-initialise via Paystack API with new reference. Old URL invalidated.</li>
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Cancel pending → status <code>abandoned</code>, redirected to start fresh on original payment page.</li>
        </ul>
    </div>
    <div class="feat-group">
        <div class="feat-group-title">Receipts & History</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> My Payments: full history with status labels, reference codes, receipt links. Pending payments show action buttons.</li>
            <li><div class="dot" style="background:#8b5cf6"></div><span class="tag tag-system">SYSTEM</span> Platform receipt generated for every confirmed payment with full breakdown.</li>
            <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> Payments dashboard: revenue stats (total/today/month/week), revenue chart, transaction health donut, top payers, recent transactions. CSV export.</li>
        </ul>
    </div>
    <div class="files"><span class="file-chip">paystack.php</span><span class="file-chip">paystack_callback.php</span><span class="file-chip">paystack_webhook.php</span><span class="file-chip">my_payments.php</span><span class="file-chip">resume_payment.php</span><span class="file-chip">platform_receipt.php</span><span class="file-chip">admin/payments.php</span><span class="file-chip">admin/transactions.php</span></div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="service" id="notifications">
    <div class="service-header">
        <div class="service-icon">🔔</div>
        <div>
            <div class="service-title">Notifications</div>
            <div class="service-subtitle">Per-notification read, bell badge, grouped feed</div>
        </div>
    </div>
    <div class="feat-list" style="margin-bottom:12px">
        <li><div class="dot" style="background:#8b5cf6"></div><span class="tag tag-system">SYSTEM</span> Notification bell fixed at top-right of every page (via <code>bottom_nav.php</code>). Badge shows unread count.</li>
        <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Clicking the bell navigates to notifications.php. Badge clears optimistically on click.</li>
        <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Notification cards expandable — click to open full message. Tapping "View →" opens the linked page.</li>
        <li><div class="dot" style="background:#8b5cf6"></div><span class="tag tag-system">SYSTEM</span> Mark-as-read is per-notification via AJAX only when expanded — not bulk on page load. Unread = bold; read = faded.</li>
        <li><div class="dot" style="background:#8b5cf6"></div><span class="tag tag-system">SYSTEM</span> Grouped by: Today / Yesterday / This Week / This Month. Colour-coded left border by type.</li>
        <li><div class="dot" style="background:#8b5cf6"></div><span class="tag tag-system">SYSTEM</span> Types: info (blue) · success (green) · warning (amber) · error (red). Error notifications used for rejections.</li>
    </div>
    <div class="files"><span class="file-chip">notifications.php</span><span class="file-chip">ajax.php (mark_notification_read)</span><span class="file-chip">partials/bottom_nav.php</span></div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="service" id="chat">
    <div class="service-header">
        <div class="service-icon">💬</div>
        <div>
            <div class="service-title">Chat & Messages</div>
            <div class="service-subtitle">Job-scoped conversations, admin controls, flagging</div>
        </div>
    </div>
    <div class="feat-list" style="margin-bottom:12px">
        <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Chat opens between job poster and approved applicant/hired worker. Conversation scoped to the job.</li>
        <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Unread message count shown in bottom nav badge.</li>
        <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Flag a message for admin review.</li>
        <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> Enable/disable chat globally. Configure who can chat (applicants, hired workers, worker-to-worker, all-to-all).</li>
        <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> Restrict/unrestrict individual user messaging. Chat ban with expiry date.</li>
        <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> Review flagged messages. Chat audit log.</li>
    </div>
    <div class="files"><span class="file-chip">chat.php</span><span class="file-chip">admin/chat.php</span></div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="service" id="moderation">
    <div class="service-header">
        <div class="service-icon">🛡️</div>
        <div>
            <div class="service-title">Moderation System & Conflict of Interest</div>
            <div class="service-subtitle">Granular permissions, review queue, performance tracking, rewards, COI enforcement</div>
        </div>
    </div>
    <div class="feat-group">
        <div class="feat-group-title">Permissions</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> 16 granular permissions per manager: approve_jobs, approve_events, approve_funerals, approve_news, approve_products, approve_shops, approve_delivery_requests, approve_delivery_agents, approve_verifications, manage_users, manage_disputes, manage_referrals, manage_ads, manage_communication, manage_boosts, view_reports.</li>
            <li><div class="dot" style="background:#8b5cf6"></div><span class="tag tag-system">SYSTEM</span> Admins bypass all permission checks. Managers see only what they're permitted to act on.</li>
        </ul>
    </div>
    <div class="feat-group">
        <div class="feat-group-title">Review Queue</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#f59e0b"></div><span class="tag tag-mod">MOD</span> Dashboard queue shows pending jobs, products, events, funerals, news, delivery requests, rider applications — per permission.</li>
            <li><div class="dot" style="background:#f59e0b"></div><span class="tag tag-mod">MOD</span> One-click Approve/Reject from queue. Reject opens modal for reason. Reason sent to user via notification with direct edit link.</li>
            <li><div class="dot" style="background:#ef4444"></div><span class="tag tag-system">SYSTEM</span> COI: before any action, system compares moderator ID with record creator ID. Match → action blocked, violation logged, admin notified.</li>
            <li><div class="dot" style="background:#ef4444"></div><span class="tag tag-system">SYSTEM</span> COI applies to: jobs, products, events, funerals, news, delivery requests, delivery agents. Blocks at: mod_action.php (all 14 actions), individual admin pages (publish/approve buttons), and UI (approve button hidden and replaced with "⚠ Yours" badge).</li>
            <li><div class="dot" style="background:#8b5cf6"></div><span class="tag tag-system">SYSTEM</span> Every approval/rejection: <code>approved_by</code> stored on record, <code>log_mod_activity()</code> earns points per action per configured table.</li>
        </ul>
    </div>
    <div class="feat-group">
        <div class="feat-group-title">Performance & Rewards</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#f59e0b"></div><span class="tag tag-mod">MOD</span> Points earned per action (configurable). Dashboard shows: points this month, point balance, GHS equivalent, rank, approval rate.</li>
            <li><div class="dot" style="background:#f59e0b"></div><span class="tag tag-mod">MOD</span> Request reward payout: select points to redeem, enter MoMo number. Admin processes via Rewards tab.</li>
            <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> Performance page: leaderboard, individual scorecard, reward request management, point config CRUD, auto-flag thresholds.</li>
            <li><div class="dot" style="background:#8b5cf6"></div><span class="tag tag-system">SYSTEM</span> Auto-flagging: low accuracy %, high approval rate (rubber-stamping), inactivity days — thresholds configurable.</li>
        </ul>
    </div>
    <div class="files"><span class="file-chip">admin/mod_action.php</span><span class="file-chip">admin/moderators.php</span><span class="file-chip">admin/mod_performance.php</span><span class="file-chip">admin/mod_reward_request.php</span></div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="service" id="admin">
    <div class="service-header">
        <div class="service-icon">⚙️</div>
        <div>
            <div class="service-title">Admin Dashboard</div>
            <div class="service-subtitle">AJAX panel, quick cards, audit log, analytics, theme, email, contact</div>
        </div>
    </div>
    <div class="feat-list" style="margin-bottom:12px">
        <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> AJAX dashboard: sidebar nav loads sub-pages inline. No full-page reload between sections.</li>
        <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> Platform overview: total users, active jobs, total revenue, pending items.</li>
        <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> Theme colours: 12 CSS variable slots (primary, secondary, surfaces, text, borders). Live preview. Applied site-wide via PHP output-buffer injection on every page.</li>
        <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> SMTP email settings: host, port, encryption, credentials, from-name. Test email button.</li>
        <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> Contact settings: phone, WhatsApp number, office address, support hours.</li>
        <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> Audit log: every admin/mod action logged with timestamp, actor, description.</li>
        <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> Analytics: user growth, job post stats, revenue trends, conversion rates.</li>
        <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> Content management: News, Events, Funerals, Marketplace, Delivery — approve/reject/delete/feature from dedicated pages.</li>
        <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> Advertisements: create/manage banner and sponsored ad slots with date ranges.</li>
    </div>
    <div class="files"><span class="file-chip">admin/index.php</span><span class="file-chip">admin/theme.php</span><span class="file-chip">admin/email_settings.php</span><span class="file-chip">admin/contact_settings.php</span><span class="file-chip">admin/analytics.php</span><span class="file-chip">admin/audit_logs.php</span><span class="file-chip">admin/ads.php</span></div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="service" id="monetization">
    <div class="service-header">
        <div class="service-icon">💰</div>
        <div>
            <div class="service-title">Monetization Settings</div>
            <div class="service-subtitle">Enable/disable fees, package management, revenue stats per service</div>
        </div>
    </div>
    <div class="feat-list" style="margin-bottom:12px">
        <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> Global monetization mode: free / hybrid / paid. Individual toggles per feature (free vs paid).</li>
        <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> <strong>Jobs:</strong> posting fee, featured job packages (CRUD: name, days, price, active/inactive).</li>
        <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> <strong>Workers:</strong> service listing packages, verification fee, featured worker packages.</li>
        <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> <strong>News:</strong> submission fee (enable/disable, amount), featured packages CRUD, revenue stats.</li>
        <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> <strong>Events:</strong> submission fee, featured packages CRUD, pending payment count, revenue stats.</li>
        <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> <strong>Funerals:</strong> announcement fee, featured packages CRUD, revenue stats.</li>
        <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> <strong>Marketplace:</strong> boost packages (4 types), seller subscription plans, verified seller fee. All with enable/disable toggle and revenue stats.</li>
        <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> <strong>Delivery:</strong> premium subscription prices (monthly/quarterly/yearly), sponsored listing prices, verification fee.</li>
        <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> Escrow settings: commission rate %, auto-release window (days), minimum budget threshold.</li>
        <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> Paystack keys: test/live mode toggle, public key, secret key.</li>
    </div>
    <div class="files"><span class="file-chip">admin/monetization.php</span><span class="file-chip">admin/events.php (fee panel)</span><span class="file-chip">admin/funerals.php (fee panel)</span><span class="file-chip">admin/news.php (fee panel)</span><span class="file-chip">admin/marketplace.php</span></div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="service" id="referrals">
    <div class="service-header">
        <div class="service-icon">🎁</div>
        <div>
            <div class="service-title">Referrals & Points</div>
            <div class="service-subtitle">Referral codes, conversion tracking, points wallet, reward events</div>
        </div>
    </div>
    <div class="feat-list" style="margin-bottom:12px">
        <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Every user gets a unique referral link/code. Share to invite friends.</li>
        <li><div class="dot" style="background:#8b5cf6"></div><span class="tag tag-system">SYSTEM</span> Referral visit tracked (IP, conversion). On new user registering via code: referral record created.</li>
        <li><div class="dot" style="background:#8b5cf6"></div><span class="tag tag-system">SYSTEM</span> Points events (all configurable by admin): registration, email verification, profile photo, hiring a worker, completing a job, leaving a review, 5-star rating, referral conversions.</li>
        <li><div class="dot" style="background:#3b82f6"></div><span class="tag tag-user">USER</span> Points wallet shows balance, total earned, transaction history.</li>
        <li><div class="dot" style="background:#ec4899"></div><span class="tag tag-admin">ADMIN</span> Configure point values per event. Enable/disable referral programme.</li>
    </div>
    <div class="files"><span class="file-chip">settings.php (referral section)</span><span class="file-chip">functions.php (award_points, get_referral_stats)</span></div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="service" id="cron">
    <div class="service-header">
        <div class="service-icon">⏰</div>
        <div>
            <div class="service-title">Cron Jobs & Background Automation</div>
            <div class="service-subtitle">All scheduled tasks — run <code>_cron.php</code> daily at midnight</div>
        </div>
    </div>

    <div class="feat-group">
        <div class="feat-group-title">Jobs & Workers</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#10b981"></div><span class="tag tag-cron">CRON</span> Featured jobs expired → <code>featured=0</code>. 2-day advance warning sent to customer.</li>
            <li><div class="dot" style="background:#10b981"></div><span class="tag tag-cron">CRON</span> Featured worker profiles expired → <code>is_featured=0</code>. 2-day advance warning sent.</li>
            <li><div class="dot" style="background:#10b981"></div><span class="tag tag-cron">CRON</span> Worker verification badges expired → <code>is_verified=0</code>, status <code>expired</code>.</li>
            <li><div class="dot" style="background:#10b981"></div><span class="tag tag-cron">CRON</span> Worker service listings expired → <code>service_fee_status='free'</code>. 7-day warning sent once (flag prevents repeat).</li>
            <li><div class="dot" style="background:#10b981"></div><span class="tag tag-cron">CRON</span> Job vacancies past deadline → status <code>expired</code>. Open applications auto-closed, workers notified.</li>
            <li><div class="dot" style="background:#10b981"></div><span class="tag tag-cron">CRON</span> Job deadline reminders: 7 days / 3 days / 1 day before (each fires once via flag column).</li>
        </ul>
    </div>

    <div class="feat-group">
        <div class="feat-group-title">Escrow</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#10b981"></div><span class="tag tag-cron">CRON</span> Escrow payments past <code>auto_release_at</code> on completed jobs → status <code>released</code>. Job <code>payment_status='paid'</code>. Worker and customer both notified. Logged to audit.</li>
        </ul>
    </div>

    <div class="feat-group">
        <div class="feat-group-title">Marketplace</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#10b981"></div><span class="tag tag-cron">CRON</span> Featured/sponsored products expired → <code>is_featured=0</code>, <code>is_sponsored=0</code>.</li>
            <li><div class="dot" style="background:#10b981"></div><span class="tag tag-cron">CRON</span> Featured/sponsored shops expired → same flags cleared.</li>
            <li><div class="dot" style="background:#10b981"></div><span class="tag tag-cron">CRON</span> Boost orders past end_date → <code>status='expired'</code>. 2-day advance warning sent to seller.</li>
            <li><div class="dot" style="background:#10b981"></div><span class="tag tag-cron">CRON</span> Seller subscriptions expired → <code>status='expired'</code>, <code>is_subscribed=0</code>. Seller notified to renew.</li>
        </ul>
    </div>

    <div class="feat-group">
        <div class="feat-group-title">Community Content</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#10b981"></div><span class="tag tag-cron">CRON</span> Featured events expired → <code>featured=0</code>. 2-day advance warning sent.</li>
            <li><div class="dot" style="background:#10b981"></div><span class="tag tag-cron">CRON</span> Featured funeral announcements expired → <code>featured=0</code>. 2-day advance warning sent.</li>
            <li><div class="dot" style="background:#10b981"></div><span class="tag tag-cron">CRON</span> Featured news articles expired → <code>featured=0</code>. 2-day advance warning sent.</li>
        </ul>
    </div>

    <div class="feat-group">
        <div class="feat-group-title">Delivery</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#10b981"></div><span class="tag tag-cron">CRON</span> Rider premium subscriptions expired → <code>is_premium=0</code>. Subscription records marked <code>expired</code>. 3-day warning sent.</li>
            <li><div class="dot" style="background:#10b981"></div><span class="tag tag-cron">CRON</span> Rider sponsored listings expired → <code>is_sponsored=0</code>. Records marked <code>expired</code>.</li>
        </ul>
    </div>

    <div class="feat-group">
        <div class="feat-group-title">Cron Reporting</div>
        <ul class="feat-list">
            <li><div class="dot" style="background:#8b5cf6"></div><span class="tag tag-system">SYSTEM</span> Every run: counts expired items BEFORE sweep, logs full summary to <code>audit_logs</code>. Returns JSON (web) or plaintext (CLI) with all counts and elapsed time.</li>
            <li><div class="dot" style="background:#8b5cf6"></div><span class="tag tag-system">SYSTEM</span> Access: CLI (<code>php _cron.php</code>) or web (<code>_cron.php?token=CRON_TOKEN</code>). Token required for web access.</li>
        </ul>
    </div>

    <div class="files"><span class="file-chip">_cron.php</span><span class="file-chip">functions.php (sweep_expired_featured, sweep_expired_jobs, sweep_expired_escrow, send_job_deadline_reminders)</span></div>
</div>

</main>
</div>

<script>
// Highlight active sidebar link on scroll
var sections = document.querySelectorAll('.service');
var links     = document.querySelectorAll('.sidebar a');
window.addEventListener('scroll', function() {
    var scrollY = window.scrollY + 60;
    sections.forEach(function(sec) {
        var top = sec.offsetTop, bottom = top + sec.offsetHeight;
        if (scrollY >= top && scrollY < bottom) {
            links.forEach(function(l) { l.classList.remove('active'); });
            var link = document.querySelector('.sidebar a[href="#' + sec.id + '"]');
            if (link) link.classList.add('active');
        }
    });
});
</script>
</body>
</html>
