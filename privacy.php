<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
$user = current_user();
$lastUpdated = '23 June 2026';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Privacy Policy — <?php echo sanitize(APP_NAME); ?></title>
    <link rel="stylesheet" href="assets/css/style.css" />
    <style>
        .legal-shell { max-width: 760px; margin: 0 auto; padding: 24px 16px 60px; }
        .legal-shell h1 { font-size: 1.7rem; margin: 0 0 4px; }
        .legal-shell h2 { font-size: 1.1rem; margin: 32px 0 10px; border-bottom: 1px solid var(--border,#e5e7eb); padding-bottom: 6px; }
        .legal-shell h3 { font-size: .95rem; margin: 18px 0 6px; }
        .legal-shell p, .legal-shell li { line-height: 1.75; color: #374151; font-size: .95rem; }
        .legal-shell ul { padding-left: 20px; margin: 8px 0; }
        .legal-toc { background: var(--surface-muted,#f8fafc); border: 1px solid var(--border,#e5e7eb); border-radius: 8px; padding: 16px 20px; margin: 20px 0; }
        .legal-toc ol { margin: 0; padding-left: 20px; line-height: 2; font-size: .9rem; }
    </style>
</head>
<body <?php echo $user ? 'class="has-bottom-nav"' : ''; ?>>
    <header class="app-topbar">
        <a href="<?php echo $user ? 'jobs.php' : 'index.php'; ?>" class="button button-secondary button-small">← Back</a>
        <span class="brand"><?php echo sanitize(APP_NAME); ?></span>
    </header>
    <div class="legal-shell">
        <h1>Privacy Policy</h1>
        <p class="meta" style="margin:0 0 8px;">Last updated: <?php echo $lastUpdated; ?></p>
        <p>This Privacy Policy explains how <strong><?php echo sanitize(APP_NAME); ?></strong> ("we", "us", or "our") collects, uses, and protects information about you when you use our platform. By using <?php echo sanitize(APP_NAME); ?>, you agree to the practices described here.</p>

        <div class="legal-toc">
            <strong>Contents</strong>
            <ol>
                <li><a href="#info-we-collect">Information we collect</a></li>
                <li><a href="#how-we-use">How we use your information</a></li>
                <li><a href="#sharing">Sharing your information</a></li>
                <li><a href="#payments">Payment data</a></li>
                <li><a href="#cookies">Cookies and storage</a></li>
                <li><a href="#data-security">Data security</a></li>
                <li><a href="#your-rights">Your rights</a></li>
                <li><a href="#retention">Data retention</a></li>
                <li><a href="#children">Children's privacy</a></li>
                <li><a href="#changes">Changes to this policy</a></li>
                <li><a href="#contact-privacy">Contact</a></li>
            </ol>
        </div>

        <h2 id="info-we-collect">1. Information we collect</h2>
        <h3>Information you provide directly</h3>
        <ul>
            <li><strong>Account details:</strong> name, username, email address, phone number, town/location.</li>
            <li><strong>Profile information:</strong> profile photo, skills, availability, worker bio.</li>
            <li><strong>Identity verification:</strong> ID type (Ghana Card, passport, or other), ID number, and uploaded document photos (required for workers seeking a verified badge).</li>
            <li><strong>Job postings:</strong> job title, description, location, category, budget, payment mode, and scheduling preferences.</li>
            <li><strong>Community events:</strong> event title, description, date, time, venue, organiser name, ticket information, and any featured image you upload.</li>
            <li><strong>Funeral announcements:</strong> name of the deceased, dates, biography, venue, organiser contact details, photograph of the deceased, and funeral poster image.</li>
            <li><strong>News articles:</strong> article title, body content, summary, and any featured image you submit for publication.</li>
            <li><strong>Messages:</strong> content of in-platform conversations between users.</li>
            <li><strong>Ratings and reviews:</strong> star ratings and written feedback you leave after a completed job.</li>
            <li><strong>Referrals:</strong> referral code usage and the email or username of the person you invited.</li>
            <li><strong>Delivery requests:</strong> pickup location, drop-off location, optional Google Maps links, item description, contact name and phone for pickup and drop-off, preferred date and time, and delivery notes.</li>
            <li><strong>Delivery agent profile:</strong> vehicle type, vehicle registration number, driver's or rider's licence number, service area, selfie photograph, and any additional ID documents submitted for the Verified Rider badge.</li>
            <li><strong>Marketplace shop:</strong> shop name, description, logo and banner images, phone number, email, and town/region.</li>
            <li><strong>Marketplace products:</strong> product name, description, category, price, stock quantity, condition, and up to five product photographs.</li>
            <li><strong>Marketplace orders:</strong> delivery address (including any Google Maps link you provide), receiver name and phone number, payment method, and order notes.</li>
            <li><strong>Shop verification documents:</strong> Ghana Card photo and optional business registration certificate submitted to obtain a Verified Seller badge.</li>
        </ul>
        <h3>Information collected automatically</h3>
        <ul>
            <li><strong>Usage data:</strong> pages visited, actions taken, timestamps.</li>
            <li><strong>Device information:</strong> browser type, operating system, IP address.</li>
            <li><strong>Location:</strong> approximate location inferred from IP; precise location only if you grant browser permission.</li>
            <li><strong>Session data:</strong> stored in a secure server-side session.</li>
        </ul>

        <h2 id="how-we-use">2. How we use your information</h2>
        <ul>
            <li>Create and manage your account.</li>
            <li>Match customers with suitable workers based on skills, location, and availability.</li>
            <li>Process payments and hold escrow funds securely.</li>
            <li>Send transactional notifications (job updates, payment confirmations, application status, submission approvals or rejections).</li>
            <li>Send OTP codes for password reset via email.</li>
            <li>Review and moderate community submissions — events, funeral announcements, and news articles — before they go live.</li>
            <li>Display approved community content (events, funeral notices, articles) publicly on the Platform.</li>
            <li>Review worker and delivery agent identity documents for verification badge issuance.</li>
            <li>Operate the Delivery Services module — display delivery requests to eligible riders, notify selected riders, track delivery status, and generate receipts.</li>
            <li>Operate the Marketplace — display approved products to buyers, process checkout orders, notify sellers of new orders, and trigger delivery requests when sellers mark orders ready for dispatch.</li>
            <li>Display approved marketplace products and seller shop pages publicly (name, photo, price, shop description, region).</li>
            <li>Calculate and display leaderboard rankings based on completed jobs and ratings.</li>
            <li>Track referral activity to award points and manage the referral programme.</li>
            <li>Detect and prevent fraud, spam, and prohibited activities (including fraud signals on delivery and marketplace listings).</li>
            <li>Improve the platform through aggregated, anonymous analytics.</li>
            <li>Comply with legal obligations under Ghanaian law.</li>
        </ul>

        <h2 id="sharing">3. Sharing your information</h2>
        <p>We do <strong>not</strong> sell your personal data. We may share information with:</p>
        <ul>
            <li><strong>Other users:</strong> your public profile (name, username, photo, skills, location area, rating) is visible to other users. Your full address, phone number, and ID details are never publicly displayed.</li>
            <li><strong>General public (unauthenticated visitors):</strong> approved events, funeral announcements, published news articles, approved marketplace products, and seller shop pages (shop name, description, region, product listings) are visible to anyone visiting the Platform without an account.</li>
            <li><strong>Delivery riders:</strong> when you create an approved delivery request, approved riders see your pickup and drop-off location text and the item description. Your name and phone number are shared only with the rider you select to carry out the delivery.</li>
            <li><strong>Marketplace sellers:</strong> when you place an order, the seller receives your delivery address, receiver name, receiver phone, and payment method. Your full account email and other profile data are not shared unless you include them voluntarily in order notes.</li>
            <li><strong>Service providers:</strong> Paystack (payment processing) and, where configured, WhatsApp or SMS gateway providers. These providers process data only as instructed by us.</li>
            <li><strong>Law enforcement:</strong> if required by a valid legal order or to protect the safety of users and the platform.</li>
            <li><strong>Business transfers:</strong> in the event of a merger or acquisition, user data may transfer to the successor entity, with notice provided to you.</li>
        </ul>

        <h2 id="payments">4. Payment data</h2>
        <p>All payment card processing is handled exclusively by <strong>Paystack</strong>. <?php echo sanitize(APP_NAME); ?> does not store card numbers, CVVs, or bank account details. We store payment transaction records (reference numbers, amounts, status, payment type) in our database to provide receipts, resolve disputes, and comply with financial reporting requirements.</p>
        <p>Escrow funds are held by Paystack on behalf of <?php echo sanitize(APP_NAME); ?> until the conditions for release are met.</p>
        <p>Mobile money payments for delivery subscriptions, sponsored listings, marketplace boost orders, and verification fees require you to provide your mobile money number, which is stored with the transaction record for admin confirmation purposes and is not shared with third parties beyond the payment gateway.</p>

        <h2 id="cookies">5. Cookies and storage</h2>
        <p>We use a single session cookie (HTTP-only, SameSite=Lax) to maintain your login session. We do not use advertising cookies or third-party tracking cookies. JavaScript from Paystack (<code>js.paystack.co</code>) is loaded during checkout and may set its own cookies subject to Paystack's privacy policy.</p>

        <h2 id="data-security">6. Data security</h2>
        <p>We protect your data with:</p>
        <ul>
            <li>HTTPS encryption for all data in transit.</li>
            <li>Passwords stored as bcrypt hashes — never in plain text.</li>
            <li>Prepared statements throughout to prevent SQL injection.</li>
            <li>HTTP security headers (X-Frame-Options, X-Content-Type-Options, CSP).</li>
            <li>Session hardening (strict mode, HttpOnly cookies, 2-hour idle timeout).</li>
        </ul>
        <p>No system is 100% secure. In the event of a data breach affecting your personal information, we will notify affected users within a reasonable time and take steps to mitigate harm.</p>

        <h2 id="your-rights">7. Your rights</h2>
        <p>You have the right to:</p>
        <ul>
            <li><strong>Access:</strong> request a copy of the personal data we hold about you.</li>
            <li><strong>Correction:</strong> update inaccurate information via your profile settings at any time.</li>
            <li><strong>Deletion:</strong> request deletion of your account and associated data (subject to legal retention obligations for financial records).</li>
            <li><strong>Portability:</strong> request your data in a machine-readable format.</li>
            <li><strong>Objection:</strong> object to processing of your data for specific purposes.</li>
        </ul>
        <p>To exercise any of these rights, contact us at <a href="mailto:<?php echo sanitize(MAIL_FROM); ?>"><?php echo sanitize(MAIL_FROM); ?></a>. We will respond within 30 days.</p>

        <h2 id="retention">8. Data retention</h2>
        <p>We retain your account data for as long as your account is active. If you delete your account, we remove your personal profile within 30 days. Community content (events, funeral announcements, articles) that has been published will be removed from public view within 30 days of account closure; content that was never published is deleted immediately. Financial transaction records are retained for a minimum of 7 years as required by Ghanaian financial regulations. Anonymised usage analytics may be retained indefinitely.</p>

        <h2 id="children">9. Children's privacy</h2>
        <p><?php echo sanitize(APP_NAME); ?> is intended for users aged 18 and older. We do not knowingly collect personal information from children under 18. If you believe a child has registered without parental consent, contact us immediately and we will delete the account.</p>

        <h2 id="changes">10. Changes to this policy</h2>
        <p>We may update this Privacy Policy from time to time. Material changes will be communicated via an in-app notification or email. The date at the top of this page reflects the most recent revision. Continued use of the platform after changes take effect constitutes acceptance of the updated policy.</p>

        <h2 id="contact-privacy">11. Contact</h2>
        <p>Questions about this Privacy Policy? Contact us at:</p>
        <p>
            <strong><?php echo sanitize(APP_NAME); ?></strong><br>
            Email: <a href="mailto:<?php echo sanitize(MAIL_FROM); ?>"><?php echo sanitize(MAIL_FROM); ?></a><br>
            Location: Akuapem Area, Eastern Region, Ghana
        </p>

        <p class="meta" style="margin-top:32px;padding-top:16px;border-top:1px solid var(--border,#e5e7eb);">
            <a href="terms.php">Terms of Service</a> &nbsp;·&nbsp;
            <a href="support.php">Help &amp; Support</a> &nbsp;·&nbsp;
            <a href="about.php">About</a> &nbsp;·&nbsp;
            <a href="contact.php">Contact us</a>
        </p>
    </div>
    <?php if ($user): ?>
        <?php $activeNav = 'settings'; require __DIR__ . '/partials/bottom_nav.php'; ?>
    <?php else: ?>
        <footer style="text-align:center;padding:20px 16px 32px;font-size:.8rem;color:#6b7280;border-top:1px solid #e5e7eb;margin-top:40px;">
            &copy; <?php echo date('Y'); ?> <?php echo sanitize(APP_NAME); ?> &nbsp;·&nbsp;
            <a href="contact.php">Contact</a> &nbsp;·&nbsp;
            <a href="terms.php">Terms</a>
        </footer>
    <?php endif; ?>
</body>
</html>
