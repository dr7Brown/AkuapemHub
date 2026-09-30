<?php

/**
 * SMS notifications via Arkesel (https://sms.arkesel.com) — Ghana-focused
 * SMS gateway. Mirrors WhatsAppService's shape (httpPost helper, phone
 * normalization) but reads its credentials from platform_settings, same
 * as EmailService's SMTP config — so an admin sets the API key from
 * Admin → SMS Settings instead of editing config.php on the server.
 * config.php's ARKESEL_API_KEY/ARKESEL_SENDER_ID constants are only a
 * fallback default when nothing has been saved in the dashboard yet.
 */
class SmsService
{
    private static ?array $cfg = null;

    private static function config(): array
    {
        if (self::$cfg !== null) return self::$cfg;

        $defaults = [
            'enabled'   => false,
            'api_key'   => defined('ARKESEL_API_KEY')   ? trim(ARKESEL_API_KEY)   : '',
            'sender_id' => defined('ARKESEL_SENDER_ID') ? trim(ARKESEL_SENDER_ID) : 'AkuapemCn',
        ];

        try {
            global $pdo;
            $rows = $pdo->query(
                "SELECT setting_key, setting_value FROM platform_settings WHERE setting_key LIKE 'arkesel_%'"
            )->fetchAll(PDO::FETCH_KEY_PAIR);

            $apiKey = $rows['arkesel_api_key'] ?? '';
            self::$cfg = [
                'enabled'   => ($rows['arkesel_enabled'] ?? '1') === '1' && ($apiKey !== '' || $defaults['api_key'] !== ''),
                'api_key'   => $apiKey !== '' ? $apiKey : $defaults['api_key'],
                'sender_id' => ($rows['arkesel_sender_id'] ?? '') !== '' ? $rows['arkesel_sender_id'] : $defaults['sender_id'],
            ];
        } catch (Throwable $e) {
            self::$cfg = $defaults;
        }

        return self::$cfg;
    }

    /** Force re-read of settings on next call (call after admin saves). */
    public static function resetConfig(): void { self::$cfg = null; }

    public static function send(string $phone, string $message): bool
    {
        $cfg   = self::config();
        $phone = WhatsAppService::normalizePhone($phone);

        if (!$cfg['enabled'] || !$cfg['api_key']) {
            error_log("[SmsService] Not configured or disabled. Would send to +{$phone}: {$message}");
            return false;
        }
        if (!$phone) {
            error_log('[SmsService] No valid phone number to send to.');
            return false;
        }

        $url     = 'https://sms.arkesel.com/api/v2/sms/send';
        $payload = json_encode([
            'sender'     => $cfg['sender_id'],
            'message'    => $message,
            'recipients' => [$phone],
        ]);

        return self::httpPost($url, $payload, [
            'api-key: ' . $cfg['api_key'],
            'Content-Type: application/json',
        ]);
    }

    // ── HTTP helper — same shape as WhatsAppService::httpPost() ─────────────────

    private static function httpPost(string $url, string $payload, array $headers): bool
    {
        if (!function_exists('curl_init')) {
            error_log('[SmsService] cURL not available');
            return false;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            error_log("[SmsService] cURL error: {$err}");
            return false;
        }

        $ok = $code >= 200 && $code < 300;
        if (!$ok) {
            error_log("[SmsService] HTTP {$code}: " . substr((string) $body, 0, 300));
        }
        return $ok;
    }
}
