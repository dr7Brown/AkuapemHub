<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/services/OtpService.php';

if (current_user()) {
    header('Location: jobs.php');
    exit;
}

// Require an active reset session
if (empty($_SESSION['reset_otp_id']) || empty($_SESSION['reset_user_id'])) {
    flash('Please request a password reset first.', 'error');
    header('Location: forgot_password.php');
    exit;
}

// Session-level timeout (defence in depth — OTP itself has its own expiry)
if ((time() - (int)($_SESSION['reset_started'] ?? 0)) > 900) {
    unset($_SESSION['reset_otp_id'], $_SESSION['reset_user_id'], $_SESSION['reset_started']);
    flash('Reset session expired. Please request a new code.', 'error');
    header('Location: forgot_password.php');
    exit;
}

$otpId  = (int) $_SESSION['reset_otp_id'];
$userId = (int) $_SESSION['reset_user_id'];
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $ip        = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
    $submitted = preg_replace('/\D/', '', trim($_POST['otp'] ?? ''));

    if (strlen($submitted) !== 6) {
        $error = 'Please enter the 6-digit code exactly as received.';
    } else {
        $result = OtpService::validate($pdo, $otpId, $submitted);

        if ($result['valid']) {
            log_security_event($userId, 'otp_verified', $ip, $userAgent);
            $_SESSION['reset_verified']    = true;
            $_SESSION['reset_otp_id_used'] = $otpId;
            unset($_SESSION['reset_otp_id']); // consumed — prevent re-use of this OTP ID in verify page
            header('Location: reset_password.php');
            exit;
        }

        log_security_event($userId, 'otp_failed', $ip, $userAgent);
        $error = $result['error'];
    }
}

// Mask contact info for display
$maskedEmail = '';
$maskedPhone = '';
$stmt = $pdo->prepare('SELECT email, phone FROM users WHERE id = ?');
$stmt->execute([$userId]);
$contactUser = $stmt->fetch(PDO::FETCH_ASSOC);
if ($contactUser) {
    $emailParts = explode('@', (string)($contactUser['email'] ?? ''));
    if (count($emailParts) === 2) {
        $local       = $emailParts[0];
        $masked      = substr($local, 0, min(2, strlen($local))) . str_repeat('*', max(0, strlen($local) - 2));
        $maskedEmail = $masked . '@' . $emailParts[1];
    }
    if (!empty($contactUser['phone'])) {
        $p           = $contactUser['phone'];
        $maskedPhone = substr($p, 0, 3) . str_repeat('*', max(0, strlen($p) - 6)) . substr($p, -3);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Enter Reset Code — AkuapemConnect</title>
  <link rel="stylesheet" href="assets/css/style.css"/>
</head>
<body>
  <main class="page-shell small-shell">
    <div style="text-align:center;margin-bottom:var(--space-4);">
      <span style="display:inline-flex;align-items:center;justify-content:center;width:56px;height:56px;
                   font-size:28px;border-radius:50%;background:var(--primary-soft);margin-bottom:10px;">📲</span>
      <h1 style="margin:0;">Enter your code</h1>
      <?php if ($maskedEmail || $maskedPhone): ?>
        <p class="meta">
          Code sent to
          <?php if ($maskedEmail): ?><strong><?php echo sanitize($maskedEmail); ?></strong><?php endif; ?>
          <?php if ($maskedEmail && $maskedPhone): ?> and WhatsApp <?php elseif ($maskedPhone): ?> <?php endif; ?>
          <?php if ($maskedPhone): ?><strong><?php echo sanitize($maskedPhone); ?></strong><?php endif; ?>
        </p>
      <?php endif; ?>
    </div>

    <form class="card form-card" method="post" action="verify_otp.php">
      <?php echo csrf_field(); ?>
      <?php if ($error): ?>
        <div class="alert alert-error"><?php echo sanitize($error); ?></div>
      <?php endif; ?>
      <label>6-digit reset code</label>
      <input type="text" name="otp" maxlength="6" minlength="6" pattern="[0-9]{6}"
             inputmode="numeric" autocomplete="one-time-code" required autofocus
             style="font-size:1.6rem;letter-spacing:10px;text-align:center;padding:14px;"
             placeholder="000000" />
      <p class="meta" style="font-size:0.8rem;margin-top:4px;">
        Expires in <?php echo OtpService::EXPIRY_MINUTES; ?> minutes &mdash;
        max <?php echo OtpService::MAX_ATTEMPTS; ?> attempts.
      </p>
      <button type="submit" class="button button-primary">Verify code</button>
      <p class="small-note">
        <a href="forgot_password.php">← Request a new code</a>
      </p>
    </form>
  </main>
</body>
</html>
