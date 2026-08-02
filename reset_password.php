<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/services/OtpService.php';

if (current_user()) {
    header('Location: jobs.php');
    exit;
}

if (empty($_SESSION['reset_verified'])
    || empty($_SESSION['reset_user_id'])
    || empty($_SESSION['reset_otp_id_used'])) {
    flash('Reset session expired. Please start again.', 'error');
    header('Location: forgot_password.php');
    exit;
}

$userId = (int) $_SESSION['reset_user_id'];
$otpId  = (int) $_SESSION['reset_otp_id_used'];
$error  = '';

function validate_new_password(string $password, string $confirm): string
{
    if (strlen($password) < 8)             return 'Password must be at least 8 characters.';
    if ($password !== $confirm)            return 'Passwords do not match.';
    if (!preg_match('/[A-Z]/', $password)) return 'Password must contain at least one uppercase letter.';
    if (!preg_match('/[a-z]/', $password)) return 'Password must contain at least one lowercase letter.';
    if (!preg_match('/[0-9]/', $password)) return 'Password must contain at least one number.';
    return '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $ip        = client_ip();
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
    $password  = $_POST['password']         ?? '';
    $confirm   = $_POST['password_confirm'] ?? '';

    $validationError = validate_new_password($password, $confirm);
    if ($validationError) {
        $error = $validationError;
    } else {
        // Confirm the OTP record is still pending (not already used or expired)
        $stmt = $pdo->prepare(
            "SELECT status FROM password_reset_otps WHERE id = ? AND user_id = ? AND status = 'pending'"
        );
        $stmt->execute([$otpId, $userId]);
        if (!$stmt->fetch()) {
            unset(
                $_SESSION['reset_verified'],
                $_SESSION['reset_user_id'],
                $_SESSION['reset_otp_id_used']
            );
            flash('Reset session expired. Please start again.', 'error');
            header('Location: forgot_password.php');
            exit;
        }

        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        $hash = password_hash($password, $algo);

        $pdo->prepare(
            'UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?'
        )->execute([$hash, $userId]);

        OtpService::markUsed($pdo, $otpId);
        log_security_event($userId, 'password_reset_completed', $ip, $userAgent);

        // Clear all reset session state
        unset(
            $_SESSION['reset_verified'],
            $_SESSION['reset_user_id'],
            $_SESSION['reset_otp_id_used'],
            $_SESSION['reset_started']
        );

        flash('Password reset successfully. Please log in with your new password.', 'success');
        header('Location: login.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Reset Password — AkuapemConnect</title>
  <link rel="stylesheet" href="assets/css/style.css"/>
</head>
<body>
  <main class="page-shell small-shell">
    <div style="text-align:center;margin-bottom:var(--space-4);">
      <span style="display:inline-flex;align-items:center;justify-content:center;width:56px;height:56px;
                   font-size:28px;border-radius:50%;background:var(--primary-soft);margin-bottom:10px;">🔑</span>
      <h1 style="margin:0;">Set new password</h1>
      <p class="meta">Choose a strong password for your account.</p>
    </div>

    <form class="card form-card" method="post" action="reset_password.php">
      <?php echo csrf_field(); ?>
      <?php if ($error): ?>
        <div class="alert alert-error"><?php echo sanitize($error); ?></div>
      <?php endif; ?>

      <label>New password</label>
      <input type="password" name="password" required autocomplete="new-password" minlength="8" />
      <p class="meta" style="font-size:0.8rem;margin-top:4px;">
        Min 8 characters — uppercase, lowercase, and a number required.
      </p>

      <label>Confirm password</label>
      <input type="password" name="password_confirm" required autocomplete="new-password" />

      <button type="submit" class="button button-primary">Reset password</button>
    </form>
  </main>

  <script>
    // Client-side strength hint (non-blocking — server validates authoritatively)
    document.querySelector('input[name="password"]').addEventListener('input', function () {
      var p = this.value;
      var ok = p.length >= 8 && /[A-Z]/.test(p) && /[a-z]/.test(p) && /[0-9]/.test(p);
      this.style.borderColor = p.length === 0 ? '' : (ok ? 'var(--success, green)' : 'var(--error, #e53e3e)');
    });
  </script>
</body>
</html>
