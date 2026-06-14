<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
$user = current_user();

$sent    = false;
$fError  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fname   = trim($_POST['fullname'] ?? '');
    $femail  = trim($_POST['email'] ?? '');
    $fsubj   = trim($_POST['subject'] ?? '');
    $fmsg    = trim($_POST['message'] ?? '');

    if (!$fname || !$femail || !$fsubj || !$fmsg) {
        $fError = 'All fields are required.';
    } elseif (!filter_var($femail, FILTER_VALIDATE_EMAIL)) {
        $fError = 'Please enter a valid email address.';
    } elseif (strlen($fmsg) < 20) {
        $fError = 'Message is too short (minimum 20 characters).';
    } else {
        $body = "Name: {$fname}\nEmail: {$femail}\nSubject: {$fsubj}\n\n{$fmsg}";
        @mail(ADMIN_EMAIL, '[AkuapemHub Contact] ' . $fsubj, $body, "From: " . MAIL_FROM . "\r\nReply-To: {$femail}");
        $sent = true;
    }
}

$pageTitle = 'Contact Us — ' . APP_NAME;

// Contact info from admin-managed settings, with sensible fallbacks
$ci = [
    'email'   => get_platform_setting('contact_email')    ?: MAIL_FROM,
    'phone'   => get_platform_setting('contact_phone')    ?: '',
    'whatsapp'=> get_platform_setting('contact_whatsapp') ?: '',
    'address' => get_platform_setting('contact_address')  ?: 'Akuapem Area, Eastern Region, Ghana',
    'hours'   => get_platform_setting('contact_hours')    ?: 'Monday – Saturday, 8:00 AM – 6:00 PM GMT',
    'tagline' => get_platform_setting('contact_tagline')  ?: "We're here to help. Reach out through any of the channels below, or fill in the form and we'll get back to you.",
];
$waLink = $ci['whatsapp'] ? 'https://wa.me/' . preg_replace('/[^0-9]/', '', $ci['whatsapp']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo sanitize($pageTitle); ?></title>
    <link rel="stylesheet" href="assets/css/style.css" />
    <style>
        .static-shell { max-width: 760px; margin: 0 auto; padding: 24px 16px 60px; }
        .contact-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 24px; }
        @media (max-width: 580px) { .contact-grid { grid-template-columns: 1fr; } }
        .info-item { display: flex; gap: 14px; align-items: flex-start; margin-bottom: 20px; }
        .info-icon { width: 42px; height: 42px; border-radius: 10px; background: var(--primary-soft, #e6f4f1); display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
        .info-item h4 { margin: 0 0 2px; font-size: .9rem; color: var(--text-muted, #6b7280); text-transform: uppercase; letter-spacing: .05em; }
        .info-item p  { margin: 0; font-size: .95rem; line-height: 1.5; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        @media (max-width: 480px) { .form-row { grid-template-columns: 1fr; } }
    </style>
</head>
<body <?php echo $user ? 'class="has-bottom-nav"' : ''; ?>>
    <header class="app-topbar">
        <a href="<?php echo $user ? 'dashboard.php' : 'index.php'; ?>" class="button button-secondary button-small">← Back</a>
        <span class="brand"><?php echo sanitize(APP_NAME); ?></span>
    </header>
    <div class="static-shell">
        <h1 style="margin:0 0 6px;font-size:1.6rem;">Contact Us</h1>
        <p class="meta" style="margin:0 0 24px;"><?php echo sanitize($ci['tagline']); ?></p>

        <div class="contact-grid">
            <!-- Contact info -->
            <div>
                <div class="card" style="padding:22px;">
                    <h2 style="margin-top:0;font-size:1.05rem;">Get in touch</h2>

                    <div class="info-item">
                        <div class="info-icon">📧</div>
                        <div>
                            <h4>Email</h4>
                            <p><a href="mailto:<?php echo sanitize($ci['email']); ?>"><?php echo sanitize($ci['email']); ?></a></p>
                        </div>
                    </div>

                    <?php if ($ci['phone']): ?>
                    <div class="info-item">
                        <div class="info-icon">📞</div>
                        <div>
                            <h4>Phone</h4>
                            <p><a href="tel:<?php echo sanitize(preg_replace('/[^0-9+]/', '', $ci['phone'])); ?>"><?php echo sanitize($ci['phone']); ?></a></p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($ci['whatsapp']): ?>
                    <div class="info-item">
                        <div class="info-icon">💬</div>
                        <div>
                            <h4>WhatsApp support</h4>
                            <p><a href="<?php echo sanitize($waLink); ?>" target="_blank" rel="noopener"><?php echo sanitize($ci['whatsapp']); ?></a><br><span style="font-size:.82rem;color:var(--text-muted);">Faster response during business hours</span></p>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="info-item">
                        <div class="info-icon">💬</div>
                        <div>
                            <h4>WhatsApp support</h4>
                            <p>Reach us on WhatsApp for faster response during business hours.</p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="info-item">
                        <div class="info-icon">📍</div>
                        <div>
                            <h4>Location</h4>
                            <p><?php echo nl2br(sanitize($ci['address'])); ?></p>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-icon">🕐</div>
                        <div>
                            <h4>Support hours</h4>
                            <p><?php echo nl2br(sanitize($ci['hours'])); ?></p>
                        </div>
                    </div>
                </div>

                <div class="card" style="padding:18px;margin-top:16px;background:var(--surface-muted,#f8fafc);">
                    <h3 style="margin:0 0 8px;font-size:.95rem;">Quick help links</h3>
                    <ul style="margin:0;padding-left:18px;line-height:2;">
                        <?php if ($user): ?>
                        <li><a href="my_payments.php">Payment history</a></li>
                        <li><a href="notifications.php">Notifications</a></li>
                        <?php if ($user['role'] === 'worker'): ?>
                        <li><a href="worker_profile.php">My worker profile</a></li>
                        <?php endif; ?>
                        <li><a href="dashboard.php">Dashboard</a></li>
                        <?php else: ?>
                        <li><a href="register.php">Create an account</a></li>
                        <li><a href="login.php">Sign in</a></li>
                        <li><a href="find_workers.php">Find workers</a></li>
                        <?php endif; ?>
                        <li><a href="privacy.php">Privacy Policy</a></li>
                        <li><a href="terms.php">Terms of Service</a></li>
                    </ul>
                </div>
            </div>

            <!-- Contact form -->
            <div>
                <div class="card" style="padding:22px;">
                    <h2 style="margin-top:0;font-size:1.05rem;">Send us a message</h2>

                    <?php if ($sent): ?>
                        <div class="alert alert-success" style="margin-bottom:0;">
                            <strong>Message sent!</strong> We'll reply to <strong><?php echo sanitize($femail); ?></strong> as soon as possible. Usually within 1–2 business days.
                        </div>
                    <?php else: ?>

                        <?php if ($fError): ?>
                            <div class="alert alert-error" style="margin-bottom:14px;"><?php echo sanitize($fError); ?></div>
                        <?php endif; ?>

                        <form method="post" action="contact.php">
                            <div class="form-row" style="margin-bottom:14px;">
                                <div>
                                    <label for="cf-name" style="display:block;font-size:.85rem;margin-bottom:4px;font-weight:600;">Full name *</label>
                                    <input type="text" id="cf-name" name="fullname" class="form-control"
                                           value="<?php echo sanitize($_POST['fullname'] ?? ($user['name'] ?? '')); ?>" required />
                                </div>
                                <div>
                                    <label for="cf-email" style="display:block;font-size:.85rem;margin-bottom:4px;font-weight:600;">Email address *</label>
                                    <input type="email" id="cf-email" name="email" class="form-control"
                                           value="<?php echo sanitize($_POST['email'] ?? ($user['email'] ?? '')); ?>" required />
                                </div>
                            </div>
                            <div style="margin-bottom:14px;">
                                <label for="cf-subject" style="display:block;font-size:.85rem;margin-bottom:4px;font-weight:600;">Subject *</label>
                                <select id="cf-subject" name="subject" class="form-control" required>
                                    <option value="">— Select a topic —</option>
                                    <option value="Payment issue" <?php echo ($_POST['subject'] ?? '') === 'Payment issue' ? 'selected' : ''; ?>>Payment issue</option>
                                    <option value="Account help" <?php echo ($_POST['subject'] ?? '') === 'Account help' ? 'selected' : ''; ?>>Account help</option>
                                    <option value="Worker verification" <?php echo ($_POST['subject'] ?? '') === 'Worker verification' ? 'selected' : ''; ?>>Worker verification</option>
                                    <option value="Job dispute" <?php echo ($_POST['subject'] ?? '') === 'Job dispute' ? 'selected' : ''; ?>>Job dispute</option>
                                    <option value="Report a user" <?php echo ($_POST['subject'] ?? '') === 'Report a user' ? 'selected' : ''; ?>>Report a user</option>
                                    <option value="General enquiry" <?php echo ($_POST['subject'] ?? '') === 'General enquiry' ? 'selected' : ''; ?>>General enquiry</option>
                                    <option value="Other" <?php echo ($_POST['subject'] ?? '') === 'Other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                            <div style="margin-bottom:16px;">
                                <label for="cf-message" style="display:block;font-size:.85rem;margin-bottom:4px;font-weight:600;">Message *</label>
                                <textarea id="cf-message" name="message" class="form-control" rows="6" required placeholder="Describe your issue or question in detail…"><?php echo sanitize($_POST['message'] ?? ''); ?></textarea>
                            </div>
                            <button type="submit" class="button button-primary" style="width:100%;">Send message</button>
                        </form>

                        <p class="meta" style="margin:12px 0 0;font-size:.8rem;text-align:center;">
                            Or email us directly at <a href="mailto:<?php echo sanitize($ci['email']); ?>"><?php echo sanitize($ci['email']); ?></a>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php if ($user): ?>
        <?php $activeNav = 'settings'; require __DIR__ . '/partials/bottom_nav.php'; ?>
    <?php else: ?>
        <footer style="text-align:center;padding:20px 16px 32px;font-size:.8rem;color:#6b7280;border-top:1px solid #e5e7eb;margin-top:40px;">
            &copy; <?php echo date('Y'); ?> <?php echo sanitize(APP_NAME); ?> &nbsp;·&nbsp;
            <a href="privacy.php">Privacy</a> &nbsp;·&nbsp;
            <a href="terms.php">Terms</a>
        </footer>
    <?php endif; ?>
</body>
</html>
