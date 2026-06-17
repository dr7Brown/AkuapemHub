<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/services/EmailService.php';

$token = trim($_GET['token'] ?? '');
$error = '';
$success = false;

if ($token === '') {
    $error = 'Invalid verification link.';
} else {
    // Token valid for 24 hours
    $stmt = $pdo->prepare(
        "SELECT id, name, email FROM users
         WHERE email_verification_token = ?
           AND email_verified = 0
           AND email_verification_sent_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
         LIMIT 1"
    );
    $stmt->execute([$token]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        // Could be expired, already verified, or invalid token
        $already = $pdo->prepare(
            "SELECT id FROM users WHERE email_verification_token = ? AND email_verified = 1 LIMIT 1"
        );
        $already->execute([$token]);
        if ($already->fetch()) {
            $error = 'This email address is already verified. You can log in normally.';
        } else {
            $error = 'This verification link has expired or is invalid. Please request a new one.';
        }
    } else {
        // Mark verified
        $pdo->prepare(
            "UPDATE users SET email_verified = 1, email_verification_token = NULL WHERE id = ?"
        )->execute([$target['id']]);

        // Points: email verification + referral milestone
        require_once __DIR__ . '/modules/referrals/service.php';
        award_points((int)$target['id'], 'email_verification');
        handle_referral_milestone((int)$target['id'], 'email_verified');

        // Update the live session if this user is logged in
        $sessionUser = current_user();
        if ($sessionUser && $sessionUser['id'] === $target['id']) {
            $_SESSION['user']['email_verified'] = 1;
        }

        $success = true;
        log_security_event($target['id'], 'email_verified', $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', '');
    }
}

$isLoggedIn = (bool) current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Email Verification — AkuapemConnect</title>
  <link rel="stylesheet" href="assets/css/style.css"/>
</head>
<body>
  <main class="page-shell small-shell">
    <div style="text-align:center;margin-bottom:var(--space-4);">
      <span style="display:inline-flex;align-items:center;justify-content:center;width:64px;height:64px;
                   font-size:32px;border-radius:50%;background:var(--primary-soft);margin-bottom:12px;">
        <?php echo $success ? '✅' : '❌'; ?>
      </span>
      <h1 style="margin:0;"><?php echo $success ? 'Email verified!' : 'Verification failed'; ?></h1>
    </div>

    <?php if ($success): ?>
      <div class="card" style="text-align:center;padding:28px 24px;">
        <p style="margin:0 0 20px;color:var(--text);">
          Your email address has been verified. You now have full access to AkuapemConnect.
        </p>
        <?php if ($isLoggedIn): ?>
          <a href="jobs.php" class="button button-primary">Go to dashboard</a>
        <?php else: ?>
          <a href="login.php" class="button button-primary">Log in to your account</a>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="card" style="text-align:center;padding:28px 24px;">
        <div class="alert alert-error" style="text-align:left;"><?php echo sanitize($error); ?></div>
        <?php if ($isLoggedIn): ?>
          <p style="margin:16px 0 0;">
            <form method="post" action="resend_verification.php" style="display:inline;">
              <?php echo csrf_field(); ?>
              <button type="submit" class="button button-primary">Resend verification email</button>
            </form>
          </p>
        <?php else: ?>
          <p style="margin:16px 0 0;">
            <a href="login.php" class="button button-secondary">Log in to resend verification</a>
          </p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </main>
</body>
</html>
