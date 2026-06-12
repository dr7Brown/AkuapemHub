<?php

class EmailService
{
    /**
     * Send an HTML password-reset OTP email to the user.
     */
    public static function sendPasswordResetOtp(
        string $toEmail,
        string $name,
        string $otp,
        string $expiresAt
    ): bool {
        $appName  = defined('APP_NAME')  ? APP_NAME  : 'AkuapemHub';
        $mailFrom = defined('MAIL_FROM') ? MAIL_FROM : 'noreply@example.com';
        $subject  = "{$appName} — Your Password Reset Code";

        $html = self::buildTemplate($appName, $name, $otp);

        $boundary = md5(uniqid('', true));
        $headers  = "From: {$appName} <{$mailFrom}>\r\n"
                  . "Reply-To: {$mailFrom}\r\n"
                  . "MIME-Version: 1.0\r\n"
                  . "Content-Type: text/html; charset=UTF-8\r\n"
                  . "X-Mailer: PHP/" . phpversion() . "\r\n";

        $sent = mail($toEmail, $subject, $html, $headers);
        if (!$sent) {
            error_log("[EmailService] Failed to send OTP email to {$toEmail}");
        }
        return $sent;
    }

    // ── Email verification ────────────────────────────────────────────────────

    public static function sendVerificationEmail(string $toEmail, string $name, string $token): bool
    {
        $appName  = defined('APP_NAME')  ? APP_NAME  : 'AkuapemHub';
        $mailFrom = defined('MAIL_FROM') ? MAIL_FROM : 'noreply@example.com';
        $baseUrl  = defined('BASE_URL')  ? BASE_URL  : '';
        $subject  = "Verify your {$appName} email address";
        $link     = $baseUrl . '/verify_email.php?token=' . urlencode($token);

        $safeName = htmlspecialchars($name,    ENT_QUOTES, 'UTF-8');
        $safeApp  = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
        $safeLink = htmlspecialchars($link,    ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"/><title>Verify your email — {$safeApp}</title></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:40px 0;">
    <tr><td align="center">
      <table width="480" cellpadding="0" cellspacing="0"
             style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
        <tr>
          <td style="background:#2563eb;padding:28px 32px;text-align:center;">
            <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;">{$safeApp}</h1>
            <p style="margin:6px 0 0;color:#bfdbfe;font-size:13px;">Email Verification</p>
          </td>
        </tr>
        <tr>
          <td style="padding:36px 32px;">
            <p style="margin:0 0 18px;font-size:15px;color:#1e293b;">Hi <strong>{$safeName}</strong>,</p>
            <p style="margin:0 0 24px;font-size:15px;color:#475569;line-height:1.6;">
              Welcome to {$safeApp}! Please verify your email address to activate all features of your account.
            </p>
            <div style="text-align:center;margin:0 0 28px;">
              <a href="{$safeLink}"
                 style="display:inline-block;background:#2563eb;color:#ffffff;font-size:16px;
                        font-weight:600;padding:14px 32px;border-radius:8px;text-decoration:none;">
                Verify my email address
              </a>
            </div>
            <p style="margin:0 0 8px;font-size:13px;color:#64748b;line-height:1.6;">
              This link expires in <strong>24 hours</strong>. If you did not create this account, you can ignore this email.
            </p>
            <p style="margin:0 0 24px;font-size:12px;color:#94a3b8;word-break:break-all;">
              If the button above doesn't work, copy and paste this link: {$safeLink}
            </p>
            <hr style="border:none;border-top:1px solid #e2e8f0;margin:0 0 20px;"/>
            <p style="margin:0;font-size:11px;color:#94a3b8;text-align:center;">
              This is an automated message from {$safeApp}.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

        $headers  = "From: {$appName} <{$mailFrom}>\r\n"
                  . "Reply-To: {$mailFrom}\r\n"
                  . "MIME-Version: 1.0\r\n"
                  . "Content-Type: text/html; charset=UTF-8\r\n";

        $sent = mail($toEmail, $subject, $html, $headers);
        if (!$sent) {
            error_log("[EmailService] Failed to send verification email to {$toEmail}");
        }
        return $sent;
    }

    // ── Password reset OTP ────────────────────────────────────────────────────

    private static function buildTemplate(string $appName, string $name, string $otp): string
    {
        $safeName = htmlspecialchars($name,    ENT_QUOTES, 'UTF-8');
        $safeOtp  = htmlspecialchars($otp,     ENT_QUOTES, 'UTF-8');
        $safeApp  = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
        $expMin   = OtpService::EXPIRY_MINUTES;

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>{$safeApp} Password Reset</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:40px 0;">
    <tr><td align="center">
      <table width="480" cellpadding="0" cellspacing="0"
             style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
        <tr>
          <td style="background:#2563eb;padding:28px 32px;text-align:center;">
            <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;">{$safeApp}</h1>
            <p style="margin:6px 0 0;color:#bfdbfe;font-size:13px;">Password Reset Request</p>
          </td>
        </tr>
        <tr>
          <td style="padding:36px 32px;">
            <p style="margin:0 0 18px;font-size:15px;color:#1e293b;">Hi <strong>{$safeName}</strong>,</p>
            <p style="margin:0 0 24px;font-size:15px;color:#475569;line-height:1.6;">
              We received a request to reset your {$safeApp} password.
              Use the verification code below — it expires in
              <strong>{$expMin} minutes</strong>.
            </p>

            <div style="background:#eff6ff;border:2px solid #2563eb;border-radius:10px;
                        padding:28px 20px;text-align:center;margin:0 0 28px;">
              <p style="margin:0 0 8px;font-size:11px;color:#64748b;
                         text-transform:uppercase;letter-spacing:2px;font-weight:600;">
                Your Reset Code
              </p>
              <p style="margin:0;font-size:42px;font-weight:800;color:#2563eb;
                         letter-spacing:12px;font-family:monospace;">
                {$safeOtp}
              </p>
            </div>

            <p style="margin:0 0 8px;font-size:13px;color:#64748b;line-height:1.6;">
              This code is valid for <strong>{$expMin} minutes</strong> and can only be used once.
              Maximum 5 verification attempts are allowed.
            </p>
            <p style="margin:0 0 28px;font-size:13px;color:#64748b;line-height:1.6;">
              If you did not request a password reset, you can safely ignore this email.
              Your password will not change.
            </p>

            <hr style="border:none;border-top:1px solid #e2e8f0;margin:0 0 20px;"/>
            <p style="margin:0;font-size:11px;color:#94a3b8;text-align:center;">
              This is an automated security message from {$safeApp}.
              Do not reply to this email.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }
}
