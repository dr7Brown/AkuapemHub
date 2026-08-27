<?php
/**
 * Sign in with Google — tests the account match/link/create logic in
 * functions.php directly, with a stubbed Google profile payload. A full
 * browser OAuth round-trip needs real Google Cloud credentials and a public
 * redirect URI, so it can't be exercised from here — this covers the
 * security/logic-critical part instead: exactly what google_callback.php
 * does with whatever Google's userinfo endpoint would have returned.
 *
 * Run from CLI: php tests/google_auth_test.php
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

global $pdo;
$passed = 0; $failed = 0;

function assert_eq($label, $expected, $actual) {
    global $passed, $failed;
    if ($expected === $actual) { echo "[PASS] {$label}\n"; $passed++; }
    else { echo "[FAIL] {$label}\n       Expected: " . var_export($expected, true) . "\n       Actual:   " . var_export($actual, true) . "\n"; $failed++; }
}
function assert_true($label, $cond) { assert_eq($label, true, (bool)$cond); }

function cleanup_test_users() {
    global $pdo;
    $pdo->exec("DELETE FROM users WHERE email LIKE 'gtest_%@example.com'");
}
cleanup_test_users();

// ── New account creation ─────────────────────────────────────────────────
echo "\n=== Brand-new Google account ===\n";
$profile1 = ['sub' => 'gtest-sub-1', 'email' => 'gtest_new@example.com', 'email_verified' => true, 'name' => 'Test Newuser'];
$r1 = google_find_or_create_user($profile1);
assert_true('Create succeeds', $r1['ok']);
assert_true('Flagged as new account', $r1['is_new']);
assert_eq('Role defaults to customer', 'customer', $r1['user']['role'] ?? null);
assert_eq('Email carried over', 'gtest_new@example.com', $r1['user']['email'] ?? null);
assert_eq('Name carried over', 'Test Newuser', $r1['user']['name'] ?? null);
assert_eq('Email marked verified (Google already verified it)', '1', (string)($r1['user']['email_verified'] ?? ''));
assert_eq('Username left NULL for complete_profile.php', null, $r1['user']['username']);
assert_eq('Phone left NULL', null, $r1['user']['phone']);
assert_eq('Town left NULL', null, $r1['user']['town_id']);
assert_true('needs_profile_completion() is true for a fresh Google account', needs_profile_completion($r1['user']));

$dbRow = $pdo->prepare('SELECT google_id, auth_provider, password_hash FROM users WHERE email = ?');
$dbRow->execute(['gtest_new@example.com']);
$dbRow = $dbRow->fetch();
assert_eq('google_id stored', 'gtest-sub-1', $dbRow['google_id'] ?? null);
assert_eq('auth_provider stored as google', 'google', $dbRow['auth_provider'] ?? null);
assert_true('password_hash is a real bcrypt hash (unguessable placeholder), not empty', !empty($dbRow['password_hash']) && password_verify('', $dbRow['password_hash']) === false);

// ── Repeat sign-in by the same Google account ────────────────────────────
echo "\n=== Repeat sign-in (already linked) ===\n";
$r2 = google_find_or_create_user($profile1);
assert_true('Second call succeeds', $r2['ok']);
assert_eq('Not flagged as new the second time', false, $r2['is_new']);
assert_eq('Resolves to the same user id', $r1['user']['id'], $r2['user']['id']);

$countCheck = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE email='gtest_new@example.com'")->fetchColumn();
assert_eq('No duplicate row created on repeat sign-in', 1, $countCheck);

// ── Linking to an existing local (password) account by email ────────────
echo "\n=== Linking an existing local account by verified email ===\n";
$localHash = password_hash('irrelevant-for-this-test', PASSWORD_BCRYPT);
$pdo->prepare("INSERT INTO users (name, email, password_hash, role, email_verified, created_at) VALUES (?,?,?,?,0,NOW())")
    ->execute(['Existing Local User', 'gtest_local@example.com', $localHash, 'customer']);
$localUserId = (int)$pdo->lastInsertId();

$profile3 = ['sub' => 'gtest-sub-3', 'email' => 'gtest_local@example.com', 'email_verified' => true, 'name' => 'Existing Local User'];
$r3 = google_find_or_create_user($profile3);
assert_true('Link succeeds', $r3['ok']);
assert_eq('Not flagged as new — this is a link, not a create', false, $r3['is_new']);
assert_eq('Resolves to the pre-existing local user id', $localUserId, $r3['user']['id']);

$linkedRow = $pdo->prepare('SELECT google_id, email_verified FROM users WHERE id = ?');
$linkedRow->execute([$localUserId]);
$linkedRow = $linkedRow->fetch();
assert_eq('google_id now set on the local account', 'gtest-sub-3', $linkedRow['google_id']);
assert_eq('email_verified flipped to 1 by the Google link', '1', (string)$linkedRow['email_verified']);

// ── Banned account rejection (both match paths) ──────────────────────────
echo "\n=== Banned account rejected ===\n";
$pdo->prepare('UPDATE users SET banned = 1 WHERE id = ?')->execute([$localUserId]);
$r4 = google_find_or_create_user($profile3); // now matches by google_id (already linked above)
assert_eq('Banned account: ok=false', false, $r4['ok']);
assert_true('Banned account: error message present', !empty($r4['error']));
assert_eq('Banned account: no user returned', null, $r4['user']);

// ── Rejects an unverified Google email ────────────────────────────────────
echo "\n=== Unverified Google email rejected ===\n";
$profile5 = ['sub' => 'gtest-sub-5', 'email' => 'gtest_unverified@example.com', 'email_verified' => false, 'name' => 'Unverified'];
$r5 = google_find_or_create_user($profile5);
assert_eq('Unverified email: ok=false', false, $r5['ok']);
$existsCheck = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE email='gtest_unverified@example.com'")->fetchColumn();
assert_eq('No account created for an unverified email', 0, $existsCheck);

// ── Rejects a malformed profile ────────────────────────────────────────────
echo "\n=== Malformed profile rejected ===\n";
$r6 = google_find_or_create_user(['sub' => '', 'email' => 'not-an-email', 'email_verified' => true]);
assert_eq('Missing sub / invalid email: ok=false', false, $r6['ok']);

// ── needs_profile_completion() ────────────────────────────────────────────
echo "\n=== needs_profile_completion() ===\n";
assert_true('True when username/phone/town all missing', needs_profile_completion(['username' => null, 'phone' => null, 'town_id' => null]));
assert_true('True when only phone missing', needs_profile_completion(['username' => 'x', 'phone' => null, 'town_id' => 1]));
assert_eq('False when all three present', false, needs_profile_completion(['username' => 'x', 'phone' => '0244000000', 'town_id' => 1]));

// ── Cleanup ────────────────────────────────────────────────────────────────
cleanup_test_users();
$remaining = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'gtest_%@example.com'")->fetchColumn();
assert_eq('Cleanup verified — 0 test users remain', 0, $remaining);

echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
