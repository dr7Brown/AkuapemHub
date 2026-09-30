<?php
/**
 * Server-side push dispatch for the Android/iOS app shell (mobile-app/).
 *
 * Both senders below are safe no-ops until real credentials exist on disk —
 * every function checks for its config file first and simply returns false
 * if it's missing, so nothing here can break the site before those
 * credentials are in place. See mobile-app/README.md for how to obtain them:
 *   - Android: a Firebase service account JSON (Firebase Console → Project
 *     Settings → Service Accounts → "Generate new private key").
 *   - iOS: an APNs Auth Key (.p8) from developer.apple.com → Certificates,
 *     Identifiers & Profiles → Keys, plus its Key ID and your Team ID.
 *
 * Neither file is ever committed — see config/.gitignore.
 */

define('PUSH_FCM_SERVICE_ACCOUNT', __DIR__ . '/config/firebase-service-account.json');
define('PUSH_APNS_KEY_PATH', __DIR__ . '/config/apns-auth-key.p8');
define('PUSH_APNS_BUNDLE_ID', 'com.akuapemconnect.app');

/**
 * Sends a push notification to every device this user has registered
 * (assets/js/push-bridge.js → register_push_token.php). Call this from
 * notify_user() call sites once you want push, not just the in-app bell —
 * it's deliberately a separate function rather than baked into
 * notify_user() itself, so push stays opt-in per call site while both
 * credentials files are still placeholders.
 */
function push_notify_user(int $userId, string $title, string $body, ?string $link = null): void {
    global $pdo;
    $stmt = $pdo->prepare('SELECT platform, token FROM push_tokens WHERE user_id = ?');
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        try {
            if ($row['platform'] === 'android') {
                push_send_fcm_v1($row['token'], $title, $body, $link);
            } else {
                push_send_apns($row['token'], $title, $body, $link);
            }
        } catch (Throwable $e) {
            error_log('[push] send failed for user ' . $userId . ' (' . $row['platform'] . '): ' . $e->getMessage());
        }
    }
}

// ── Android — Firebase Cloud Messaging, HTTP v1 API ─────────────────────────
// Google retired the legacy server-key API in June 2024; v1 requires a
// short-lived OAuth2 access token minted by signing a JWT with the service
// account's own RSA private key (no external library needed — openssl_sign
// does RS256 natively).

function push_send_fcm_v1(string $token, string $title, string $body, ?string $link): bool {
    if (!file_exists(PUSH_FCM_SERVICE_ACCOUNT)) return false;
    $sa = json_decode(file_get_contents(PUSH_FCM_SERVICE_ACCOUNT), true);
    if (empty($sa['client_email']) || empty($sa['private_key']) || empty($sa['project_id'])) return false;

    $accessToken = push_fcm_access_token($sa);
    if (!$accessToken) return false;

    $payload = [
        'message' => [
            'token'        => $token,
            'notification' => ['title' => $title, 'body' => $body],
            'data'         => $link ? ['link' => (string)$link] : new stdClass(),
            'android'      => ['priority' => 'high'],
        ],
    ];

    $ch = curl_init("https://fcm.googleapis.com/v1/projects/{$sa['project_id']}/messages:send");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code >= 400) { error_log("[push] FCM error {$code}: {$resp}"); return false; }
    return true;
}

/** Mints (and caches for the rest of this request) a Google OAuth2 access
 *  token scoped to Firebase Messaging, via the service account's JWT. */
function push_fcm_access_token(array $sa): ?string {
    static $cached = null;
    $now = time();
    if ($cached && $cached['exp'] > $now + 60) return $cached['token'];

    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claims = [
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ];
    $signingInput = push_b64url(json_encode($header)) . '.' . push_b64url(json_encode($claims));

    $privateKey = openssl_pkey_get_private($sa['private_key']);
    if (!$privateKey) return null;
    if (!openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) return null;
    $jwt = $signingInput . '.' . push_b64url($signature);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode((string)$resp, true);
    if (empty($data['access_token'])) return null;

    $cached = ['token' => $data['access_token'], 'exp' => $now + (int)($data['expires_in'] ?? 3600)];
    return $cached['token'];
}

// ── iOS — APNs provider API (token-based auth, HTTP/2) ──────────────────────
// Needs curl built with HTTP/2 support, which XAMPP's bundled curl and any
// modern PHP build both have.

function push_send_apns(string $token, string $title, string $body, ?string $link): bool {
    if (!file_exists(PUSH_APNS_KEY_PATH)) return false;
    $keyId  = getenv('APNS_KEY_ID')  ?: '';
    $teamId = getenv('APNS_TEAM_ID') ?: '';
    if ($keyId === '' || $teamId === '') return false;

    $jwt = push_apns_jwt($keyId, $teamId);
    if (!$jwt) return false;

    $payload = array_filter([
        'aps'  => ['alert' => ['title' => $title, 'body' => $body], 'sound' => 'default'],
        'link' => $link,
    ], fn($v) => $v !== null);

    // getenv('APNS_ENV') = 'sandbox' for a debug/TestFlight build signed with
    // a development provisioning profile; 'production' (default) for the
    // real App Store build — sending to the wrong one silently fails.
    $host = (getenv('APNS_ENV') === 'sandbox') ? 'api.sandbox.push.apple.com' : 'api.push.apple.com';

    $ch = curl_init("https://{$host}/3/device/{$token}");
    curl_setopt_array($ch, [
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'authorization: bearer ' . $jwt,
            'apns-topic: ' . PUSH_APNS_BUNDLE_ID,
            'apns-push-type: alert',
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT    => 10,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code >= 400) { error_log("[push] APNs error {$code}: {$resp}"); return false; }
    return true;
}

/** Mints (and caches for the rest of this request) an APNs provider JWT,
 *  signed with the .p8 Auth Key using ES256 — JWT's ES256 needs the raw
 *  r||s signature, not the DER format openssl_sign() produces natively, so
 *  push_der_to_jose() re-packs it. */
function push_apns_jwt(string $keyId, string $teamId): ?string {
    static $cached = null;
    $now = time();
    if ($cached && $cached['exp'] > $now + 60) return $cached['token'];

    $privateKey = openssl_pkey_get_private(file_get_contents(PUSH_APNS_KEY_PATH));
    if (!$privateKey) return null;

    $header = ['alg' => 'ES256', 'kid' => $keyId];
    $claims = ['iss' => $teamId, 'iat' => $now];
    $signingInput = push_b64url(json_encode($header)) . '.' . push_b64url(json_encode($claims));

    if (!openssl_sign($signingInput, $derSignature, $privateKey, OPENSSL_ALGO_SHA256)) return null;
    $jwt = $signingInput . '.' . push_b64url(push_der_to_jose($derSignature));

    $cached = ['token' => $jwt, 'exp' => $now + 3000]; // Apple allows up to 1hr; refresh a bit early
    return $jwt;
}

function push_b64url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/** Converts an openssl DER-encoded ECDSA signature (SEQUENCE of two
 *  INTEGERs, r and s) into the raw, fixed-width r||s concatenation that the
 *  JOSE/JWT ES256 spec requires — the two formats are not interchangeable,
 *  and APNs will reject a DER signature outright. */
function push_der_to_jose(string $der): string {
    $offset = 1; // skip SEQUENCE tag (0x30)
    $len = ord($der[$offset]); $offset++;
    if ($len & 0x80) $offset += ($len & 0x7F); // skip long-form length bytes, if any

    $offset++; // skip INTEGER tag (0x02) for r
    $rLen = ord($der[$offset]); $offset++;
    $r = substr($der, $offset, $rLen); $offset += $rLen;

    $offset++; // skip INTEGER tag (0x02) for s
    $sLen = ord($der[$offset]); $offset++;
    $s = substr($der, $offset, $sLen);

    // DER integers are signed (a leading 0x00 pad byte if the high bit would
    // otherwise look negative) — strip that, then pad both back out to
    // exactly 32 bytes each (P-256 coordinate width) for the fixed-width
    // JOSE format.
    $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    return $r . $s;
}
