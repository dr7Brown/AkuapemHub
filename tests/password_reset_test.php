<?php
/**
 * Password Reset System Tests
 * Run via CLI: php tests/password_reset_test.php
 */

if (php_sapi_name() !== 'cli') {
    die("Run via CLI only: php tests/password_reset_test.php\n");
}

require_once __DIR__ . '/../services/OtpService.php';

$passed = 0;
$failed = 0;

function ok(bool $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) {
        echo "\033[32m[PASS]\033[0m {$label}\n";
        $passed++;
    } else {
        echo "\033[31m[FAIL]\033[0m {$label}\n";
        $failed++;
    }
}

// ── In-memory SQLite DB ───────────────────────────────────────────────────────

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec("CREATE TABLE password_reset_otps (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER,
    otp_hash   TEXT,
    email      TEXT,
    phone      TEXT,
    status     TEXT DEFAULT 'pending',
    attempts   INTEGER DEFAULT 0,
    expires_at TEXT,
    created_at TEXT,
    used_at    TEXT
)");
$pdo->exec("CREATE TABLE security_logs (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER,
    action     TEXT,
    ip_address TEXT DEFAULT '',
    user_agent TEXT,
    created_at TEXT DEFAULT (datetime('now'))
)");

// ── 1. OTP Generation ─────────────────────────────────────────────────────────

echo "\n=== OTP Generation ===\n";

$otp = OtpService::generate();
ok(strlen($otp) === 6,             'generate() produces exactly 6 characters');
ok(ctype_digit($otp),              'generate() produces only digits');
ok((int)$otp >= 0 && (int)$otp <= 999999, 'generate() within valid range');

// Generate 50 samples — check for basic distribution
$samples = array_map(fn() => OtpService::generate(), range(1, 50));
$unique  = count(array_unique($samples));
ok($unique >= 45, "generate() has sufficient randomness ({$unique}/50 unique from 50 samples)");

// ── 2. Hash and Verify ────────────────────────────────────────────────────────

echo "\n=== Hash & Verify ===\n";

$plainOtp = '482951';
$hash     = OtpService::hashOtp($plainOtp);

ok($hash !== $plainOtp,                             'hashOtp() does not return plaintext');
ok(strlen($hash) > 20,                              'hashOtp() produces a non-trivial hash');
ok(OtpService::verifyOtp($plainOtp, $hash),         'verifyOtp() accepts correct OTP');
ok(!OtpService::verifyOtp('000000', $hash),         'verifyOtp() rejects wrong OTP');
ok(!OtpService::verifyOtp('482952', $hash),         'verifyOtp() rejects off-by-one OTP');
ok(!OtpService::verifyOtp('',       $hash),         'verifyOtp() rejects empty string');

// ── 3. Create + Validate (happy path) ────────────────────────────────────────

echo "\n=== Create & Validate (happy path) ===\n";

$created = OtpService::create($pdo, 1, 'user@example.com', '+233241234567');
ok(isset($created['otp_id'], $created['otp'], $created['expires_at']), 'create() returns expected keys');
ok(is_int($created['otp_id']) && $created['otp_id'] > 0,               'create() returns a valid otp_id');
ok(strlen($created['otp']) === 6 && ctype_digit($created['otp']),       'create() plaintext OTP is 6 digits');

$result = OtpService::validate($pdo, $created['otp_id'], $created['otp']);
ok($result['valid'] === true,  'validate() accepts correct OTP');
ok(isset($result['record']),   'validate() returns record on success');

// ── 4. Expired OTP ───────────────────────────────────────────────────────────

echo "\n=== Expired OTP ===\n";

$expiredOtp  = '111111';
$expiredHash = OtpService::hashOtp($expiredOtp);
$pastTime    = date('Y-m-d H:i:s', time() - 660); // 11 minutes ago
$pdo->prepare(
    "INSERT INTO password_reset_otps
        (user_id, otp_hash, email, phone, status, attempts, expires_at, created_at)
     VALUES (2, ?, NULL, NULL, 'pending', 0, ?, ?)"
)->execute([$expiredHash, $pastTime, $pastTime]);
$expiredId = (int) $pdo->lastInsertId();

$result = OtpService::validate($pdo, $expiredId, $expiredOtp);
ok($result['valid'] === false,                      'Expired OTP is rejected');
ok(str_contains($result['error'], 'expired'),       'Correct error message for expired OTP');

// Confirm status updated to 'expired'
$row = $pdo->query("SELECT status FROM password_reset_otps WHERE id = {$expiredId}")->fetch();
ok($row['status'] === 'expired',                    'Expired OTP record status updated to expired');

// ── 5. Already-used OTP ───────────────────────────────────────────────────────

echo "\n=== Already-used OTP ===\n";

$usedId = OtpService::create($pdo, 3, null, null)['otp_id'];
$pdo->exec("UPDATE password_reset_otps SET status = 'used' WHERE id = {$usedId}");
$result = OtpService::validate($pdo, $usedId, '999999');
ok($result['valid'] === false, 'Used OTP is rejected (status not pending)');

// ── 6. Max attempt lockout ────────────────────────────────────────────────────

echo "\n=== Max Attempt Lockout ===\n";

$lockCreated = OtpService::create($pdo, 4, null, null);
$lockId  = $lockCreated['otp_id'];
$lockOtp = $lockCreated['otp'];
$wrong   = ($lockOtp === '000000') ? '111111' : '000000';

// Submit MAX_ATTEMPTS - 1 wrong attempts
for ($i = 0; $i < OtpService::MAX_ATTEMPTS - 1; $i++) {
    OtpService::validate($pdo, $lockId, $wrong);
}
// Attempt count is now MAX_ATTEMPTS - 1; one more allowed
$result = OtpService::validate($pdo, $lockId, $wrong);
ok($result['valid'] === false,                         'Last attempt fails with wrong OTP');
ok(str_contains($result['error'], 'Too many'),         'Locked-out error message shown after max attempts');

// Now record is 'expired'
$row = $pdo->query("SELECT status FROM password_reset_otps WHERE id = {$lockId}")->fetch();
ok($row['status'] === 'expired',                       'OTP record expired after max failed attempts');

// ── 7. markUsed ───────────────────────────────────────────────────────────────

echo "\n=== markUsed ===\n";

$mu = OtpService::create($pdo, 5, null, null);
OtpService::markUsed($pdo, $mu['otp_id']);
$row = $pdo->query("SELECT status, used_at FROM password_reset_otps WHERE id = {$mu['otp_id']}")->fetch();
ok($row['status'] === 'used',    'markUsed() sets status to used');
ok(!empty($row['used_at']),      'markUsed() sets used_at timestamp');

// ── 8. Rate limiting ─────────────────────────────────────────────────────────

echo "\n=== Rate Limiting ===\n";

$testUserId = 99;
$testIp     = '10.0.0.1';
$since      = date('Y-m-d H:i:s', time() - OtpService::RATE_WINDOW);

// Not rate limited initially
ok(!OtpService::isRateLimited($pdo, $testUserId, $testIp), 'Not rate limited initially');

// Insert RATE_LIMIT OTP records for this user
$nowStr      = date('Y-m-d H:i:s');
$futureStr   = date('Y-m-d H:i:s', time() + 600);
$insertOtp   = $pdo->prepare(
    "INSERT INTO password_reset_otps
        (user_id, otp_hash, email, phone, status, attempts, expires_at, created_at)
     VALUES (?, 'x', NULL, NULL, 'expired', 0, ?, ?)"
);
for ($i = 0; $i < OtpService::RATE_LIMIT; $i++) {
    $insertOtp->execute([$testUserId, $futureStr, $nowStr]);
}
ok(OtpService::isRateLimited($pdo, $testUserId, '1.2.3.4'), 'Rate limited after ' . OtpService::RATE_LIMIT . ' OTP creations per user');

// IP-based rate limiting
$testIp2    = '192.168.1.100';
$insertLog  = $pdo->prepare(
    "INSERT INTO security_logs (user_id, action, ip_address, created_at) VALUES (NULL, 'otp_requested', ?, ?)"
);
for ($i = 0; $i < OtpService::RATE_LIMIT; $i++) {
    $insertLog->execute([$testIp2, $nowStr]);
}
ok(OtpService::isRateLimited($pdo, 9999, $testIp2), 'Rate limited by IP after ' . OtpService::RATE_LIMIT . ' requests');

// ── 9. Password validation ────────────────────────────────────────────────────

echo "\n=== Password Validation ===\n";

function validate_new_password(string $p, string $c): string
{
    if (strlen($p) < 8)             return 'Password must be at least 8 characters.';
    if ($p !== $c)                  return 'Passwords do not match.';
    if (!preg_match('/[A-Z]/', $p)) return 'Password must contain at least one uppercase letter.';
    if (!preg_match('/[a-z]/', $p)) return 'Password must contain at least one lowercase letter.';
    if (!preg_match('/[0-9]/', $p)) return 'Password must contain at least one number.';
    return '';
}

ok(validate_new_password('Valid1Pass', 'Valid1Pass') === '',     'Valid password passes');
ok(validate_new_password('short1A',   'short1A')    !== '',     'Too-short password rejected');
ok(validate_new_password('Valid1Pass', 'Different')  !== '',     'Mismatched confirm rejected');
ok(validate_new_password('nouppercase1', 'nouppercase1') !== '', 'No uppercase rejected');
ok(validate_new_password('NOLOWERCASE1', 'NOLOWERCASE1') !== '', 'No lowercase rejected');
ok(validate_new_password('NoNumbers!A', 'NoNumbers!A')   !== '', 'No number rejected');
ok(validate_new_password('Password1',   'Password1')     === '', 'Minimum-valid password passes');

// ── 10. OTP Reuse after Verify ────────────────────────────────────────────────

echo "\n=== OTP Single-use ===\n";

$suCreated = OtpService::create($pdo, 6, null, null);
$suId  = $suCreated['otp_id'];
$suOtp = $suCreated['otp'];

$first = OtpService::validate($pdo, $suId, $suOtp);
ok($first['valid'] === true, 'First use of OTP succeeds');

OtpService::markUsed($pdo, $suId);
$second = OtpService::validate($pdo, $suId, $suOtp);
ok($second['valid'] === false, 'Second use of same OTP rejected after markUsed');

// ── Summary ───────────────────────────────────────────────────────────────────

echo "\n" . str_repeat('─', 40) . "\n";
echo "Results: \033[32m{$passed} passed\033[0m, " . ($failed > 0 ? "\033[31m{$failed} failed\033[0m" : "0 failed") . "\n";
if ($failed > 0) {
    exit(1);
}
