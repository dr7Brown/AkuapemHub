<?php

/**
 * Provider-agnostic WhatsApp messaging service.
 *
 * Supported providers (set WHATSAPP_PROVIDER in config.php):
 *   'meta'   — Meta Cloud API (requires WHATSAPP_PHONE_NUMBER_ID + WHATSAPP_PROVIDER_TOKEN)
 *   'twilio' — Twilio WhatsApp (requires WHATSAPP_TWILIO_ACCOUNT_SID + WHATSAPP_PROVIDER_TOKEN + WHATSAPP_TWILIO_FROM)
 *   ''       — Disabled; message is only written to error_log for development
 */
class WhatsAppService
{
    public static function sendOtp(string $phone, string $otp): bool
    {
        $phone    = self::normalizePhone($phone);
        $appName  = defined('APP_NAME') ? APP_NAME : 'AkuapemConnect';
        $expMin   = OtpService::EXPIRY_MINUTES;
        $message  = "Your {$appName} Password Reset Code is: {$otp}\n\n"
                  . "Valid for {$expMin} minutes. Do not share this code.";

        $provider = defined('WHATSAPP_PROVIDER') ? strtolower(trim(WHATSAPP_PROVIDER)) : '';

        switch ($provider) {
            case 'meta':
                return self::sendViaMeta($phone, $message);
            case 'twilio':
                return self::sendViaTwilio($phone, $message);
            default:
                // No provider configured — log for development visibility
                error_log("[WhatsAppService] Provider not set. Would send to +{$phone}: {$message}");
                return false;
        }
    }

    // ── Meta Cloud API ────────────────────────────────────────────────────────

    private static function sendViaMeta(string $phone, string $message): bool
    {
        $phoneNumberId = defined('WHATSAPP_PHONE_NUMBER_ID') ? WHATSAPP_PHONE_NUMBER_ID : '';
        $accessToken   = defined('WHATSAPP_PROVIDER_TOKEN')  ? WHATSAPP_PROVIDER_TOKEN  : '';

        if (!$phoneNumberId || !$accessToken) {
            error_log('[WhatsAppService][Meta] WHATSAPP_PHONE_NUMBER_ID or WHATSAPP_PROVIDER_TOKEN not set');
            return false;
        }

        $url     = "https://graph.facebook.com/v18.0/{$phoneNumberId}/messages";
        $payload = json_encode([
            'messaging_product' => 'whatsapp',
            'to'                => $phone,
            'type'              => 'text',
            'text'              => ['body' => $message],
        ]);

        return self::httpPost($url, $payload, [
            "Authorization: Bearer {$accessToken}",
            'Content-Type: application/json',
        ]);
    }

    // ── Twilio WhatsApp ───────────────────────────────────────────────────────

    private static function sendViaTwilio(string $phone, string $message): bool
    {
        $accountSid = defined('WHATSAPP_TWILIO_ACCOUNT_SID') ? WHATSAPP_TWILIO_ACCOUNT_SID : '';
        $authToken  = defined('WHATSAPP_PROVIDER_TOKEN')     ? WHATSAPP_PROVIDER_TOKEN     : '';
        $from       = defined('WHATSAPP_TWILIO_FROM')        ? WHATSAPP_TWILIO_FROM        : '';

        if (!$accountSid || !$authToken || !$from) {
            error_log('[WhatsAppService][Twilio] Missing WHATSAPP_TWILIO_ACCOUNT_SID, WHATSAPP_PROVIDER_TOKEN, or WHATSAPP_TWILIO_FROM');
            return false;
        }

        $url     = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json";
        $payload = http_build_query([
            'From' => $from,
            'To'   => 'whatsapp:+' . $phone,
            'Body' => $message,
        ]);

        return self::httpPost(
            $url,
            $payload,
            ['Content-Type: application/x-www-form-urlencoded'],
            $accountSid . ':' . $authToken
        );
    }

    // ── HTTP helper ───────────────────────────────────────────────────────────

    private static function httpPost(
        string $url,
        string $payload,
        array  $headers,
        string $basicAuth = ''
    ): bool {
        if (!function_exists('curl_init')) {
            error_log('[WhatsAppService] cURL not available');
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
        if ($basicAuth !== '') {
            curl_setopt($ch, CURLOPT_USERPWD, $basicAuth);
        }

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            error_log("[WhatsAppService] cURL error: {$err}");
            return false;
        }

        $ok = $code >= 200 && $code < 300;
        if (!$ok) {
            error_log("[WhatsAppService] HTTP {$code}: " . substr((string) $body, 0, 300));
        }
        return $ok;
    }

    // ── Phone normalisation ───────────────────────────────────────────────────

    /**
     * Strip non-digits and convert Ghanaian local format to E.164 (without leading +).
     * 0241234567 → 233241234567
     */
    public static function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);
        if (strlen($phone) === 10 && $phone[0] === '0') {
            $phone = '233' . substr($phone, 1);
        }
        return $phone;
    }
}
