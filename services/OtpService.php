<?php

class OtpService
{
    public const EXPIRY_MINUTES = 10;
    public const MAX_ATTEMPTS   = 5;
    public const RATE_LIMIT     = 3;    // max OTP requests per window per account/IP
    public const RATE_WINDOW    = 3600; // 1 hour

    // ── Generation ───────────────────────────────────────────────────────────

    public static function generate(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public static function hashOtp(string $otp): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_hash($otp, $algo);
    }

    public static function verifyOtp(string $otp, string $hash): bool
    {
        return password_verify($otp, $hash);
    }

    // ── Rate limiting ─────────────────────────────────────────────────────────

    public static function isRateLimited(PDO $pdo, int $userId, string $ip): bool
    {
        $since = date('Y-m-d H:i:s', time() - self::RATE_WINDOW);

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM password_reset_otps WHERE user_id = ? AND created_at >= ?'
        );
        $stmt->execute([$userId, $since]);
        if ((int) $stmt->fetchColumn() >= self::RATE_LIMIT) {
            return true;
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM security_logs
             WHERE action = 'otp_requested' AND ip_address = ? AND created_at >= ?"
        );
        $stmt->execute([$ip, $since]);
        return (int) $stmt->fetchColumn() >= self::RATE_LIMIT;
    }

    // ── Create OTP record ─────────────────────────────────────────────────────

    public static function create(PDO $pdo, int $userId, ?string $email, ?string $phone): array
    {
        // Expire any existing pending OTPs for this user
        $pdo->prepare(
            "UPDATE password_reset_otps SET status = 'expired' WHERE user_id = ? AND status = 'pending'"
        )->execute([$userId]);

        $otp       = self::generate();
        $hash      = self::hashOtp($otp);
        $expiresAt = date('Y-m-d H:i:s', time() + self::EXPIRY_MINUTES * 60);

        $now = date('Y-m-d H:i:s');
        $pdo->prepare(
            "INSERT INTO password_reset_otps
                (user_id, otp_hash, email, phone, status, attempts, expires_at, created_at)
             VALUES (?, ?, ?, ?, 'pending', 0, ?, ?)"
        )->execute([$userId, $hash, $email, $phone, $expiresAt, $now]);

        return [
            'otp_id'     => (int) $pdo->lastInsertId(),
            'otp'        => $otp,
            'expires_at' => $expiresAt,
        ];
    }

    // ── Validate OTP ──────────────────────────────────────────────────────────

    public static function validate(PDO $pdo, int $otpId, string $submitted): array
    {
        $stmt = $pdo->prepare(
            "SELECT * FROM password_reset_otps WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([$otpId]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            return ['valid' => false, 'error' => 'Reset session expired. Please request a new OTP.'];
        }

        if (strtotime($record['expires_at']) < time()) {
            $pdo->prepare(
                "UPDATE password_reset_otps SET status = 'expired' WHERE id = ?"
            )->execute([$otpId]);
            return ['valid' => false, 'error' => 'OTP has expired. Please request a new one.'];
        }

        $currentAttempts = (int) $record['attempts'];
        if ($currentAttempts >= self::MAX_ATTEMPTS) {
            $pdo->prepare(
                "UPDATE password_reset_otps SET status = 'expired' WHERE id = ?"
            )->execute([$otpId]);
            return ['valid' => false, 'error' => 'Too many incorrect attempts. Please request a new OTP.'];
        }

        // Increment attempts before verifying (prevents timing side-channels on attempt count)
        $pdo->prepare(
            'UPDATE password_reset_otps SET attempts = attempts + 1 WHERE id = ?'
        )->execute([$otpId]);
        $newAttempts = $currentAttempts + 1;

        if (!self::verifyOtp($submitted, $record['otp_hash'])) {
            if ($newAttempts >= self::MAX_ATTEMPTS) {
                $pdo->prepare(
                    "UPDATE password_reset_otps SET status = 'expired' WHERE id = ?"
                )->execute([$otpId]);
                return ['valid' => false, 'error' => 'Too many incorrect attempts. Please request a new OTP.'];
            }
            $remaining = self::MAX_ATTEMPTS - $newAttempts;
            return ['valid' => false, 'error' => "Incorrect OTP. {$remaining} attempt(s) remaining."];
        }

        return ['valid' => true, 'record' => $record];
    }

    public static function markUsed(PDO $pdo, int $otpId): void
    {
        $now = date('Y-m-d H:i:s');
        $pdo->prepare(
            "UPDATE password_reset_otps SET status = 'used', used_at = ? WHERE id = ?"
        )->execute([$now, $otpId]);
    }
}
