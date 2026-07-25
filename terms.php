<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
$user = current_user();
$lastUpdated = '17 June 2025';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Terms of Service — <?php echo sanitize(APP_NAME); ?></title>
    <link rel="stylesheet" href="assets/css/style.css" />
    <style>
        .legal-shell { max-width: 760px; margin: 0 auto; padding: 24px 16px 60px; }
        .legal-shell h1 { font-size: 1.7rem; margin: 0 0 4px; }
        .legal-shell h2 { font-size: 1.1rem; margin: 32px 0 10px; border-bottom: 1px solid var(--border,#e5e7eb); padding-bottom: 6px; }
        .legal-shell h3 { font-size: .95rem; margin: 18px 0 6px; }
        .legal-shell p, .legal-shell li { line-height: 1.75; color: #374151; font-size: .95rem; }
        .legal-shell ul, .legal-shell ol { padding-left: 20px; margin: 8px 0; }
        .legal-toc { background: var(--surface-muted,#f8fafc); border: 1px solid var(--border,#e5e7eb); border-radius: 8px; padding: 16px 20px; margin: 20px 0; }
        .legal-toc ol { margin: 0; padding-left: 20px; line-height: 2; font-size: .9rem; }
        .highlight-box { background: #fffbeb; border: 1px solid #f59e0b; border-radius: 8px; padding: 14px 16px; margin: 16px 0; font-size: .9rem; }
    </style>
</head>
<body <?php echo $user ? 'class="has-bottom-nav"' : ''; ?>>
    <header class="app-topbar">
        <a href="<?php echo $user ? 'jobs.php' : 'index.php'; ?>" class="button button-secondary button-small">← Back</a>
        <span class="brand"><?php echo sanitize(APP_NAME); ?></span>
    </header>
    <div class="legal-shell">
        <h1>Terms of Service</h1>
        <p class="meta" style="margin:0 0 8px;">Last updated: <?php echo $lastUpdated; ?></p>
        <p>These Terms of Service ("Terms") govern your use of <strong><?php echo sanitize(APP_NAME); ?></strong> ("Platform", "we", "us"). By creating an account or using the Platform, you agree to be bound by these Terms. If you do not agree, do not use the Platform.</p>

        <div class="legal-toc">
            <strong>Contents</strong>
            <ol>
                <li><a href="#eligibility">Eligibility and accounts</a></li>
                <li><a href="#user-roles">User roles</a></li>
                <li><a href="#community">Community features</a></li>
                <li><a href="#job-posting">Job posting</a></li>
                <li><a href="#applications">Applications and hiring</a></li>
                <li><a href="#payments">Payments and fees</a></li>
                <li><a href="#escrow">Escrow</a></li>
                <li><a href="#verification">Worker verification</a></li>
                <li><a href="#disputes">Disputes</a></li>
                <li><a href="#prohibited">Prohibited activities</a></li>
                <li><a href="#content">User content</a></li>
                <li><a href="#ip">Intellectual property</a></li>
                <li><a href="#liability">Limitation of liability</a></li>
                <li><a href="#indemnity">Indemnification</a></li>
                <li><a href="#termination">Termination</a></li>
                <li><a href="#law">Governing law</a></li>
                <li><a href="#changes">Changes to terms</a></li>
                <li><a href="#contact-terms">Contact</a></li>
            </ol>
        </div>

        <h2 id="eligibility">1. Eligibility and accounts</h2>
        <ul>
            <li>You must be at least 18 years old to use the Platform.</li>
            <li>You must provide accurate, current, and complete information when registering.</li>
            <li>Each person may hold only one account. Creating duplicate accounts may result in permanent suspension.</li>
            <li>You are responsible for maintaining the security of your account credentials. We are not liable for losses resulting from unauthorised access due to your failure to protect your credentials.</li>
            <li>Accounts are personal and non-transferable.</li>
        </ul>

        <h2 id="user-roles">2. User roles</h2>
        <h3>Customers</h3>
        <p>Customers are individuals or businesses who post service requests and hire workers. Customers are responsible for providing accurate job descriptions, paying agreed amounts, and treating workers with respect.</p>
        <h3>Workers</h3>
        <p>Workers are individuals who offer services, apply for jobs, and complete tasks. Workers are responsible for the accuracy of their profiles, the quality of their work, and their conduct toward customers and other users.</p>
        <h3>Managers and Admins</h3>
        <p>Managers and Admins are Platform staff responsible for approving job postings, managing disputes, verifying identities, and maintaining platform integrity. Their decisions on disputes and verifications are final.</p>

        <h2 id="community">3. Community features</h2>
        <p>In addition to the jobs marketplace, the Platform provides community features including <strong>Events</strong>, <strong>Funeral Announcements</strong>, <strong>News &amp; Articles</strong>, and <strong>In-Platform Messaging</strong>. The following rules apply to all community submissions.</p>

        <h3>Events</h3>
        <ul>
            <li>Event submissions must accurately describe the event title, date, time, venue, and organiser. Providing false information about an event may result in its removal and your account being suspended.</li>
            <li>All events are subject to admin review before publication. We reserve the right to reject events that are misleading, inappropriate, or outside the scope of the community.</li>
            <li>A publishing fee may apply at the discretion of the Platform admin. Fees are non-refundable if an event is rejected, unless required by law.</li>
            <li>If your event is rejected, you will be notified with a reason. You may edit and resubmit the same event without incurring an additional fee.</li>
            <li>Organisers are solely responsible for the accuracy, legality, and safe conduct of their events. The Platform is not responsible for events listed by users.</li>
        </ul>

        <h3>Funeral Announcements</h3>
        <ul>
            <li>Funeral announcements must relate to a genuine, verifiable bereavement. Posting false or fabricated funeral notices is strictly prohibited and will result in immediate account termination.</li>
            <li>All announcements are subject to admin review. We may request verification if a submission appears suspicious.</li>
            <li>A publishing fee may apply. If your announcement is rejected, you may edit and resubmit without additional charge.</li>
            <li>The family or individual submitting the announcement is responsible for the accuracy of all details, including funeral dates, times, and venue.</li>
            <li>Photos uploaded with a funeral announcement (photograph of the deceased, funeral poster) must be appropriate for a public memorial context.</li>
        </ul>

        <h3>News &amp; Articles</h3>
        <ul>
            <li>Articles must be factually accurate to the best of the author's knowledge. Deliberate misinformation, defamatory content, or articles designed to harm individuals or organisations are prohibited.</li>
            <li>All articles are reviewed by a Platform admin before publication. Submissions may be rejected if they do not meet community standards.</li>
            <li>A publishing fee may apply. Rejected articles may be edited and resubmitted without additional charge.</li>
            <li>By submitting an article, you confirm that the content is your original work (or that you have the right to publish it) and that it does not infringe any third-party copyright.</li>
            <li>Once published, articles may be unpublished or removed by admins if they are found to violate these Terms.</li>
        </ul>

        <h3>In-Platform Messaging</h3>
        <ul>
            <li>Messages may only be sent to users with whom you have a legitimate platform relationship (e.g., a job you have applied for or a job you have posted).</li>
            <li>Unsolicited bulk messages, spam, and harassment via the messaging system are prohibited.</li>
            <li>Message content is stored on our servers to facilitate the conversation and may be reviewed by admins if a dispute or abuse complaint is raised.</li>
        </ul>

        <h2 id="job-posting">4. Job posting</h2>
        <ul>
            <li>Job postings must accurately describe the work to be done, the location, and the budget.</li>
            <li>All job postings are subject to admin approval before going live. We reserve the right to reject postings that violate these Terms or applicable law.</li>
            <li>A non-refundable job posting fee may apply, as set by the admin. The fee is charged at the time of submission and is not refunded if the job is rejected (except where required by law).</li>
            <li>Jobs saved as drafts are not visible to workers and may be deleted after 90 days of inactivity.</li>
            <li>Customers may not apply for their own job postings.</li>
        </ul>

        <h2 id="applications">5. Applications and hiring</h2>
        <ul>
            <li>Workers may apply for open jobs by submitting an application through the Platform.</li>
            <li>Customers and managers review applications and may approve or reject them. Approved applicants proceed to the job.</li>
            <li>Once the required number of workers is hired, the job is marked as fully staffed.</li>
            <li>Workers whose applications are approved must be available to perform the job as described. Repeated no-shows may result in account suspension.</li>
        </ul>

        <h2 id="payments">6. Payments and fees</h2>
        <h3>Platform fees</h3>
        <p>The Platform may charge fees for certain services, including:</p>
        <ul>
            <li><strong>Job posting fee:</strong> a one-time fee to submit a job for admin review, deducted at the time of posting.</li>
            <li><strong>Worker service listing fee:</strong> a periodic fee for workers to appear in the "Find Workers" directory.</li>
            <li><strong>Featured listing:</strong> optional fee to boost the visibility of a job or worker profile.</li>
            <li><strong>Identity verification fee:</strong> a one-time fee to initiate admin review of your verification documents.</li>
        </ul>
        <p>All fees are displayed before payment and are charged in Ghana Cedis (GH₵) via Paystack. Fees are subject to change with 7 days' notice.</p>
        <h3>Platform commission</h3>
        <p>When escrow is used, the Platform deducts a commission from the total amount paid by the customer before releasing funds to the worker. The commission rate is set by the admin and displayed clearly on the escrow checkout page.</p>

        <h2 id="escrow">7. Escrow</h2>
        <div class="highlight-box">
            Escrow protects both customers and workers. Funds are held securely by the Platform and only released to the worker when the customer confirms satisfactory job completion.
        </div>
        <ul>
            <li>When a customer selects escrow as the payment mode, the agreed job amount (plus platform commission) is charged via Paystack at the time the job is submitted.</li>
            <li>Escrow funds are held by the Platform and are not accessible to the worker until the job is marked complete by the customer.</li>
            <li>If the customer does not confirm completion within <strong>7 days</strong> of the job being marked complete by the worker, funds are automatically released to the worker.</li>
            <li>Disputes over escrow funds are resolved by Platform admins. Their decision is final.</li>
            <li>Escrow payments are non-refundable except where the job is cancelled before a worker begins work and both parties agree to a refund, or where required by Ghanaian consumer protection law.</li>
        </ul>

        <h2 id="verification">8. Worker verification</h2>
        <ul>
            <li>Workers may submit identity documents (Ghana Card, passport, or other government-issued ID) to obtain a verified badge.</li>
            <li>Submission of a verification fee initiates admin review of your documents. Payment does not guarantee approval.</li>
            <li>We reserve the right to reject verification requests that do not meet our standards.</li>
            <li>Providing false or fraudulent identity documents will result in immediate account termination and may be reported to relevant authorities.</li>
        </ul>

        <h2 id="disputes">9. Disputes</h2>
        <ul>
            <li>Disputes between customers and workers should first be attempted to be resolved directly through in-platform messaging.</li>
            <li>If a resolution cannot be reached, either party may file a formal dispute through the Platform's dispute resolution process.</li>
            <li>Platform admins will review the evidence submitted by both parties and issue a decision within a reasonable time.</li>
            <li>The Platform's dispute decision is final and binding with respect to escrow fund release.</li>
            <li>The Platform acts as a neutral facilitator and does not guarantee outcomes in disputes.</li>
        </ul>

        <h2 id="prohibited">10. Prohibited activities</h2>
        <p>You may not use the Platform to:</p>
        <ul>
            <li>Post false, misleading, or fraudulent job listings, profiles, events, announcements, or articles.</li>
            <li>Submit a funeral announcement for a person who has not died.</li>
            <li>Publish defamatory, hateful, obscene, or discriminatory content in any community section.</li>
            <li>Harass, threaten, or harm other users — including through the messaging system.</li>
            <li>Circumvent Platform payments by taking transactions off-platform to avoid fees.</li>
            <li>Create multiple accounts or impersonate another person or organisation.</li>
            <li>Upload harmful content (malware, viruses, illegal material) including in profile photos, event images, or article images.</li>
            <li>Post or solicit illegal services.</li>
            <li>Violate any applicable law of Ghana or international law.</li>
            <li>Attempt to reverse-engineer, scrape, or interfere with the Platform's technical infrastructure.</li>
            <li>Use the referral or points system in a fraudulent or abusive manner (e.g. creating fake accounts to earn referral rewards).</li>
        </ul>
        <p>Violation of any of these prohibitions may result in immediate account suspension or permanent ban without refund.</p>

        <h2 id="content">11. User content</h2>
        <p>You retain ownership of content you post, including job descriptions, messages, profile photos, event details, funeral announcement information, and news articles. By posting content, you grant <?php echo sanitize(APP_NAME); ?> a non-exclusive, royalty-free licence to use, display, and reproduce that content solely for operating the Platform — for example, displaying your event or article publicly on the site. You are responsible for ensuring you have the right to post any content you submit, including images uploaded with events, funeral notices, or articles.</p>

        <h2 id="ip">12. Intellectual property</h2>
        <p>All Platform code, design, trademarks, and branding are owned by <?php echo sanitize(APP_NAME); ?>. You may not copy, reproduce, or create derivative works from the Platform without written permission.</p>

        <h2 id="liability">13. Limitation of liability</h2>
        <p>To the maximum extent permitted by Ghanaian law:</p>
        <ul>
            <li><?php echo sanitize(APP_NAME); ?> provides the Platform "as is" without warranties of any kind, express or implied.</li>
            <li>We are not liable for the quality, safety, or legality of jobs or services listed.</li>
            <li>We are not liable for disputes between users.</li>
            <li>Our total liability to you for any claim arising from use of the Platform shall not exceed the total fees you paid to the Platform in the 3 months preceding the claim.</li>
        </ul>

        <h2 id="indemnity">14. Indemnification</h2>
        <p>You agree to indemnify and hold <?php echo sanitize(APP_NAME); ?>, its administrators, and staff harmless from any claims, damages, or expenses (including legal fees) arising from your violation of these Terms, your use of the Platform, or your interactions with other users.</p>

        <h2 id="termination">15. Termination</h2>
        <p>We reserve the right to suspend or terminate your account at any time for violation of these Terms, illegal activity, or for any reason we deem necessary to protect the Platform and its users. You may delete your account at any time via your account settings. Termination does not relieve you of obligations incurred prior to termination.</p>

        <h2 id="law">16. Governing law</h2>
        <p>These Terms are governed by and construed in accordance with the laws of the Republic of Ghana. Any disputes arising from these Terms that cannot be resolved through the Platform's dispute process shall be submitted to the courts of Ghana.</p>

        <h2 id="changes">17. Changes to terms</h2>
        <p>We may update these Terms at any time. Material changes will be communicated via in-app notification or email at least 7 days before taking effect. Continued use of the Platform after changes take effect constitutes your acceptance of the updated Terms.</p>

        <h2 id="contact-terms">18. Contact</h2>
        <p>Questions about these Terms? Contact us at:</p>
        <p>
            <strong><?php echo sanitize(APP_NAME); ?></strong><br>
            Email: <a href="mailto:<?php echo sanitize(MAIL_FROM); ?>"><?php echo sanitize(MAIL_FROM); ?></a><br>
            <a href="contact.php">Contact form →</a>
        </p>

        <p class="meta" style="margin-top:32px;padding-top:16px;border-top:1px solid var(--border,#e5e7eb);">
            <a href="privacy.php">Privacy Policy</a> &nbsp;·&nbsp;
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
            <a href="privacy.php">Privacy</a>
        </footer>
    <?php endif; ?>
</body>
</html>
