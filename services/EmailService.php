<?php
/**
 * EmailService — unified email delivery.
 *
 * - If SMTP is configured in platform_settings (smtp_enabled=1), sends via SMTP.
 * - Otherwise falls back to PHP's mail().
 * - No external libraries required.
 */
class EmailService
{
    private static ?array $cfg = null;

    // ── Configuration ─────────────────────────────────────────────────────────

    private static function config(): array
    {
        if (self::$cfg !== null) return self::$cfg;

        $defaults = [
            'enabled'    => false,
            'host'       => '',
            'port'       => 587,
            'encryption' => 'tls',     // tls | ssl | none
            'username'   => '',
            'password'   => '',
            'from_email' => defined('MAIL_FROM') ? MAIL_FROM : 'noreply@example.com',
            'from_name'  => defined('APP_NAME')  ? APP_NAME  : 'AkuapemConnect',
        ];

        try {
            global $pdo;
            $rows = $pdo->query(
                "SELECT setting_key, setting_value FROM platform_settings
                 WHERE setting_key LIKE 'smtp_%'"
            )->fetchAll(PDO::FETCH_KEY_PAIR);

            self::$cfg = [
                'enabled'    => ($rows['smtp_enabled']    ?? '0') === '1' && !empty($rows['smtp_host']),
                'host'       => $rows['smtp_host']       ?? '',
                'port'       => (int)($rows['smtp_port'] ?? 587),
                'encryption' => $rows['smtp_encryption'] ?? 'tls',
                'username'   => $rows['smtp_username']   ?? '',
                'password'   => $rows['smtp_password']   ?? '',
                'from_email' => $rows['smtp_from_email'] ?? $defaults['from_email'],
                'from_name'  => $rows['smtp_from_name']  ?? $defaults['from_name'],
            ];
        } catch (Throwable $e) {
            self::$cfg = $defaults;
        }

        return self::$cfg;
    }

    /** Force re-read of settings on next call (call after admin saves). */
    public static function resetConfig(): void { self::$cfg = null; }

    // ── Main send method ──────────────────────────────────────────────────────

    /**
     * Send an email.  Checks user notification preferences when $userId provided.
     *
     * @param string $html  May be plain-text or HTML. Auto-wrapped if plain.
     */
    public static function send(
        string $to,
        string $subject,
        string $html,
        ?int   $userId = null
    ): bool {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

        // Respect per-user opt-out
        if ($userId !== null) {
            try {
                global $pdo;
                $pref = $pdo->prepare('SELECT email_notifications_enabled FROM users WHERE id=?');
                $pref->execute([$userId]);
                if ($pref->fetchColumn() === '0') return false;
            } catch (Throwable $e) { /* proceed */ }
        }

        $cfg = self::config();
        return $cfg['enabled']
            ? self::sendSmtp($to, $subject, $html, $cfg)
            : self::sendNative($to, $subject, $html, $cfg);
    }

    // ── Native mail() fallback ────────────────────────────────────────────────

    private static function sendNative(string $to, string $subject, string $html, array $cfg): bool
    {
        $from    = "{$cfg['from_name']} <{$cfg['from_email']}>";
        $headers = "From: {$from}\r\nReply-To: {$cfg['from_email']}\r\n"
                 . "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
        $sent = mail($to, $subject, $html, $headers);
        if (!$sent) error_log("[Email] mail() failed → {$to}");
        return $sent;
    }

    // ── Native SMTP (no external library) ─────────────────────────────────────

    private static function sendSmtp(string $to, string $subject, string $html, array $cfg): bool
    {
        $host       = $cfg['host'];
        $port       = $cfg['port'];
        $encryption = strtolower($cfg['encryption']);
        $username   = $cfg['username'];
        $password   = $cfg['password'];
        $fromEmail  = $cfg['from_email'];
        $fromName   = $cfg['from_name'];

        try {
            // 1. Connect
            $scheme = ($encryption === 'ssl') ? 'ssl' : 'tcp';
            $addr   = "{$scheme}://{$host}:{$port}";
            $ctx    = stream_context_create(['ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ]]);
            $sock   = @stream_socket_client($addr, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
            if (!$sock) throw new RuntimeException("Connect failed: {$errstr}");
            stream_set_timeout($sock, 15);

            // Helper: read response
            $read = function () use ($sock): string {
                $resp = '';
                while ($line = fgets($sock, 512)) {
                    $resp .= $line;
                    if (strlen($line) >= 4 && $line[3] === ' ') break;
                }
                return $resp;
            };

            // Helper: send command
            $cmd = function (string $line) use ($sock, $read): string {
                fwrite($sock, $line . "\r\n");
                return $read();
            };

            // 2. Greeting
            $read();

            // 3. EHLO
            $myHost = gethostname() ?: 'localhost';
            $cmd("EHLO {$myHost}");

            // 4. STARTTLS if needed
            if ($encryption === 'tls') {
                $r = $cmd('STARTTLS');
                if (strpos($r, '220') !== 0) throw new RuntimeException("STARTTLS refused: {$r}");
                if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('TLS negotiation failed');
                }
                $cmd("EHLO {$myHost}");  // re-EHLO after TLS
            }

            // 5. Auth
            if ($username) {
                $r = $cmd('AUTH LOGIN');
                if (strpos($r, '334') === false) throw new RuntimeException("AUTH LOGIN: {$r}");
                $cmd(base64_encode($username));
                $r = $cmd(base64_encode($password));
                if (strpos($r, '235') === false) throw new RuntimeException("Auth failed: {$r}");
            }

            // 6. Envelope
            $r = $cmd("MAIL FROM:<{$fromEmail}>");
            if (strpos($r, '250') === false) throw new RuntimeException("MAIL FROM: {$r}");
            $r = $cmd("RCPT TO:<{$to}>");
            if (strpos($r, '250') === false && strpos($r, '251') === false) {
                throw new RuntimeException("RCPT TO: {$r}");
            }

            // 7. DATA
            $r = $cmd('DATA');
            if (strpos($r, '354') === false) throw new RuntimeException("DATA: {$r}");

            $msgId  = '<' . time() . '.' . md5(uniqid()) . '@' . $myHost . '>';
            $safeSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $headers = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>\r\n"
                     . "To: <{$to}>\r\n"
                     . "Subject: {$safeSubject}\r\n"
                     . "Date: " . date('r') . "\r\n"
                     . "Message-ID: {$msgId}\r\n"
                     . "MIME-Version: 1.0\r\n"
                     . "Content-Type: text/html; charset=UTF-8\r\n"
                     . "Content-Transfer-Encoding: base64\r\n";

            $body = chunk_split(base64_encode($html));
            fwrite($sock, $headers . "\r\n" . $body . "\r\n.\r\n");
            $r = $read();
            if (strpos($r, '250') === false) throw new RuntimeException("Message rejected: {$r}");

            $cmd('QUIT');
            fclose($sock);
            return true;

        } catch (Throwable $e) {
            error_log("[Email SMTP] Error → {$e->getMessage()} (to: {$to})");
            if (isset($sock) && is_resource($sock)) fclose($sock);
            // Fallback to mail()
            return self::sendNative($to, $subject, $html, $cfg);
        }
    }

    // ── Specific email templates ──────────────────────────────────────────────

    public static function sendPasswordResetOtp(
        string $toEmail, string $name, string $otp, string $expiresAt
    ): bool {
        $appName = defined('APP_NAME') ? APP_NAME : 'AkuapemConnect';
        $subject = "{$appName} — Your Password Reset Code";
        return self::send($toEmail, $subject, self::buildOtpTemplate($appName, $name, $otp));
    }

    public static function sendVerificationEmail(string $toEmail, string $name, string $token): bool
    {
        $appName = defined('APP_NAME') ? APP_NAME : 'AkuapemConnect';
        $baseUrl = defined('BASE_URL') ? BASE_URL : '';
        $subject = "Verify your {$appName} email address";
        $link    = $baseUrl . '/verify_email.php?token=' . urlencode($token);
        return self::send($toEmail, $subject, self::buildVerificationTemplate($appName, $name, $link));
    }

    public static function sendReceipt(
        string $toEmail, string $name, string $receiptNumber,
        string $description, float $amount, string $date, ?int $userId = null
    ): bool {
        $appName = defined('APP_NAME') ? APP_NAME : 'AkuapemConnect';
        $subject = "{$appName} — Payment Receipt {$receiptNumber}";
        $safeName   = htmlspecialchars($name,          ENT_QUOTES, 'UTF-8');
        $safeApp    = htmlspecialchars($appName,        ENT_QUOTES, 'UTF-8');
        $safeRef    = htmlspecialchars($receiptNumber,  ENT_QUOTES, 'UTF-8');
        $safeDesc   = htmlspecialchars($description,    ENT_QUOTES, 'UTF-8');
        $safeAmt    = 'GHS ' . number_format($amount, 2);
        $safeDate   = htmlspecialchars($date,           ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"/></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="padding:40px 0;background:#f1f5f9;">
<tr><td align="center">
<table width="520" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;">
<tr><td style="background:#0f766e;padding:24px 32px;text-align:center;">
  <h1 style="margin:0;color:#fff;font-size:20px;">{$safeApp}</h1>
  <p style="margin:4px 0 0;color:#a7f3d0;font-size:13px;">Payment Receipt</p>
</td></tr>
<tr><td style="padding:32px;">
  <p style="margin:0 0 16px;font-size:15px;color:#111;">Hi <strong>{$safeName}</strong>,</p>
  <p style="margin:0 0 24px;font-size:14px;color:#475569;">Thank you for your payment. Here is your receipt:</p>
  <table width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #e5e7eb;">
    <tr><td style="padding:10px 0;font-size:13px;color:#6b7280;border-bottom:1px solid #f1f5f9;">Receipt #</td>
        <td style="padding:10px 0;font-size:13px;text-align:right;font-weight:600;border-bottom:1px solid #f1f5f9;">{$safeRef}</td></tr>
    <tr><td style="padding:10px 0;font-size:13px;color:#6b7280;border-bottom:1px solid #f1f5f9;">Date</td>
        <td style="padding:10px 0;font-size:13px;text-align:right;border-bottom:1px solid #f1f5f9;">{$safeDate}</td></tr>
    <tr><td style="padding:10px 0;font-size:13px;color:#6b7280;">Description</td>
        <td style="padding:10px 0;font-size:13px;text-align:right;">{$safeDesc}</td></tr>
    <tr><td style="padding:14px 0;font-size:16px;font-weight:700;color:#0f766e;border-top:2px solid #e5e7eb;">Total Paid</td>
        <td style="padding:14px 0;font-size:16px;font-weight:700;color:#0f766e;text-align:right;border-top:2px solid #e5e7eb;">{$safeAmt}</td></tr>
  </table>
  <p style="margin:20px 0 0;font-size:12px;color:#94a3b8;text-align:center;">This is an automated receipt from {$safeApp}.</p>
</td></tr>
</table>
</td></tr></table>
</body></html>
HTML;
        return self::send($toEmail, $subject, $html, $userId);
    }

    // ── Private template builders ─────────────────────────────────────────────

    private static function buildOtpTemplate(string $appName, string $name, string $otp): string
    {
        $safeName = htmlspecialchars($name,    ENT_QUOTES, 'UTF-8');
        $safeOtp  = htmlspecialchars($otp,     ENT_QUOTES, 'UTF-8');
        $safeApp  = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
        $expMin   = defined('OtpService::EXPIRY_MINUTES') ? OtpService::EXPIRY_MINUTES : 15;
        return <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"/></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="padding:40px 0;background:#f1f5f9;"><tr><td align="center">
<table width="480" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;">
<tr><td style="background:#0f766e;padding:28px 32px;text-align:center;">
  <h1 style="margin:0;color:#fff;font-size:22px;">{$safeApp}</h1>
  <p style="margin:6px 0 0;color:#a7f3d0;font-size:13px;">Password Reset</p>
</td></tr>
<tr><td style="padding:36px 32px;">
  <p style="margin:0 0 16px;font-size:15px;color:#111;">Hi <strong>{$safeName}</strong>,</p>
  <p style="margin:0 0 24px;font-size:14px;color:#475569;line-height:1.6;">Use the code below to reset your password. It expires in <strong>{$expMin} minutes</strong>.</p>
  <div style="background:#f0fdf4;border:2px solid #0f766e;border-radius:10px;padding:28px 20px;text-align:center;margin:0 0 24px;">
    <p style="margin:0 0 8px;font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:2px;">Reset Code</p>
    <p style="margin:0;font-size:42px;font-weight:800;color:#0f766e;letter-spacing:12px;font-family:monospace;">{$safeOtp}</p>
  </div>
  <p style="margin:0;font-size:12px;color:#94a3b8;text-align:center;">If you didn't request this, ignore this email.</p>
</td></tr></table>
</td></tr></table></body></html>
HTML;
    }

    private static function buildVerificationTemplate(string $appName, string $name, string $link): string
    {
        $safeName = htmlspecialchars($name,    ENT_QUOTES, 'UTF-8');
        $safeApp  = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
        $safeLink = htmlspecialchars($link,    ENT_QUOTES, 'UTF-8');
        return <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"/></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="padding:40px 0;background:#f1f5f9;"><tr><td align="center">
<table width="480" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;">
<tr><td style="background:#0f766e;padding:28px 32px;text-align:center;">
  <h1 style="margin:0;color:#fff;font-size:22px;">{$safeApp}</h1>
  <p style="margin:6px 0 0;color:#a7f3d0;font-size:13px;">Email Verification</p>
</td></tr>
<tr><td style="padding:36px 32px;">
  <p style="margin:0 0 16px;font-size:15px;color:#111;">Hi <strong>{$safeName}</strong>,</p>
  <p style="margin:0 0 24px;font-size:14px;color:#475569;line-height:1.6;">Welcome to {$safeApp}! Please verify your email to activate your account.</p>
  <div style="text-align:center;margin:0 0 24px;">
    <a href="{$safeLink}" style="display:inline-block;background:#0f766e;color:#fff;font-size:15px;font-weight:600;padding:14px 32px;border-radius:8px;text-decoration:none;">Verify my email address</a>
  </div>
  <p style="margin:0;font-size:12px;color:#94a3b8;text-align:center;">This link expires in 24 hours. If you didn't create this account, ignore this email.</p>
</td></tr></table>
</td></tr></table></body></html>
HTML;
    }
}
