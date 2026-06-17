<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/services/OtpService.php';
require_once __DIR__ . '/services/EmailService.php';
require_once __DIR__ . '/services/WhatsAppService.php';

if (current_user()) {
    header('Location: jobs.php');
    exit;
}

$error = '';
$sent  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Clear any stale reset session state from a previous attempt
    unset(
        $_SESSION['reset_otp_id'],
        $_SESSION['reset_user_id'],
        $_SESSION['reset_started'],
        $_SESSION['reset_verified'],
        $_SESSION['reset_otp_id_used']
    );

    $ip        = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
    $input     = trim($_POST['identifier'] ?? '');

    if ($input === '') {
        $error = 'Please enter your email address or phone number.';
    } else {
        $user = null;

        if (filter_var($input, FILTER_VALIDATE_EMAIL)) {
            $stmt = $pdo->prepare(
                'SELECT id, name, email, phone FROM users WHERE email = ? AND banned = 0 LIMIT 1'
            );
            $stmt->execute([$input]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } else {
            // Phone lookup — match on last 9 digits to handle any local/international format
            $digits   = preg_replace('/\D/', '', $input);
            $lastNine = strlen($digits) >= 9 ? substr($digits, -9) : $digits;
            if ($lastNine !== '') {
                $stmt = $pdo->prepare(
                    'SELECT id, name, email, phone FROM users
                     WHERE phone LIKE ? AND banned = 0 LIMIT 1'
                );
                $stmt->execute(['%' . $lastNine]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }

        // Always show the same confirmation message to prevent user enumeration
        if ($user && !OtpService::isRateLimited($pdo, $user['id'], $ip)) {
            $created = OtpService::create($pdo, $user['id'], $user['email'], $user['phone']);

            EmailService::sendPasswordResetOtp(
                $user['email'],
                $user['name'],
                $created['otp'],
                $created['expires_at']
            );

            if (!empty($user['phone'])) {
                WhatsAppService::sendOtp($user['phone'], $created['otp']);
            }

            $_SESSION['reset_otp_id']  = $created['otp_id'];
            $_SESSION['reset_user_id'] = $user['id'];
            $_SESSION['reset_started'] = time();

            log_security_event($user['id'], 'otp_requested', $ip, $userAgent);
        } elseif ($user) {
            log_security_event($user['id'], 'otp_rate_limited', $ip, $userAgent);
        } else {
            log_security_event(null, 'otp_requested_unknown', $ip, $userAgent);
        }

        $sent = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Forgot Password — AkuapemConnect</title>
  <link rel="stylesheet" href="assets/css/style.css"/>
</head>
<body>
  <main class="page-shell small-shell">
    <div style="text-align:center;margin-bottom:var(--space-4);">
      <span style="display:inline-flex;align-items:center;justify-content:center;width:56px;height:56px;
                   font-size:28px;border-radius:50%;background:var(--primary-soft);margin-bottom:10px;">🔐</span>
      <h1 style="margin:0;">Forgot password?</h1>
      <p class="meta">We'll send a 6-digit reset code to your email and WhatsApp.</p>
    </div>

    <?php if ($sent): ?>
      <div class="card" style="text-align:center;padding:32px 24px;">
        <p style="font-size:2.4rem;margin:0 0 12px;">📬</p>
        <h2 style="margin:0 0 10px;">Check your email &amp; WhatsApp</h2>
        <p class="meta" style="margin:0 0 24px;">
          If an account matches your details, a 6-digit code has been sent to your
          registered email address and WhatsApp number.
          It expires in <strong><?php echo OtpService::EXPIRY_MINUTES; ?> minutes</strong>.
        </p>
        <a href="verify_otp.php" class="button button-primary">Enter my reset code</a>
        <p class="meta" style="margin-top:16px;font-size:0.8rem;">
          Didn't receive it?
          <a href="forgot_password.php">Try again</a>
          (max 3 requests per hour)
        </p>
      </div>
    <?php else: ?>
      <form class="card form-card" method="post" action="forgot_password.php">
        <?php echo csrf_field(); ?>
        <?php if ($error): ?>
          <div class="alert alert-error"><?php echo sanitize($error); ?></div>
        <?php endif; ?>
        <label>Email address or phone number</label>
        <input type="text" name="identifier"
               placeholder="e.g. you@email.com or 0241234567"
               autocomplete="username" required autofocus />
        <button type="submit" class="button button-primary">Send reset code</button>
        <p class="small-note"><a href="login.php">← Back to login</a></p>
      </form>
    <?php endif; ?>
  </main>
</body>
</html>
