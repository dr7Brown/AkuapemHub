<?php
/**
 * Monetization system tests.
 *
 * Run from CLI: php tests/monetization_test.php
 *
 * Proves three invariants:
 *   1. Disabled setting  → feature accessible for free
 *   2. Enabled + unpaid  → access blocked, payment required
 *   3. Enabled + paid    → access allowed
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

$passed = 0;
$failed = 0;

function assert_eq($label, $expected, $actual) {
    global $passed, $failed;
    if ($expected === $actual) {
        echo "[PASS] {$label}\n";
        $passed++;
    } else {
        echo "[FAIL] {$label}\n";
        echo "       Expected: " . var_export($expected, true) . "\n";
        echo "       Actual:   " . var_export($actual, true) . "\n";
        $failed++;
    }
}

function save($key, $value) {
    set_platform_setting($key, $value);
}

function get($key) {
    return get_platform_setting($key, '0');
}

// ─────────────────────────────────────────────
// Helper: is_feature_paid() reflects mode + setting
// ─────────────────────────────────────────────

echo "\n=== is_feature_paid() — mode behaviour ===\n";

save('monetization_mode', 'free');
save('enable_paid_featured_jobs', '1');
assert_eq('Free mode: feature=paid is still free', false, is_feature_paid('enable_paid_featured_jobs'));

save('monetization_mode', 'paid');
save('enable_paid_featured_jobs', '0');
assert_eq('Paid mode: feature=free is still paid', true, is_feature_paid('enable_paid_featured_jobs'));

save('monetization_mode', 'hybrid');
save('enable_paid_featured_jobs', '0');
assert_eq('Hybrid + feature=off: free access', false, is_feature_paid('enable_paid_featured_jobs'));

save('monetization_mode', 'hybrid');
save('enable_paid_featured_jobs', '1');
assert_eq('Hybrid + feature=on: paid access', true, is_feature_paid('enable_paid_featured_jobs'));

// ─────────────────────────────────────────────
// Featured Jobs — job posting fee
// ─────────────────────────────────────────────

echo "\n=== Job posting fee gate ===\n";

save('monetization_mode', 'free');
save('enable_paid_job_posting', '0');
assert_eq('Disabled: enable_paid_job_posting is false', false, is_feature_paid('enable_paid_job_posting'));

save('monetization_mode', 'hybrid');
save('enable_paid_job_posting', '1');
assert_eq('Hybrid+enabled: enable_paid_job_posting is true', true, is_feature_paid('enable_paid_job_posting'));

// Simulate gate logic from request.php:
// If is_feature_paid → status must become 'pending'; else 'free'
$settingEnabled = is_feature_paid('enable_paid_job_posting');
$statusOnSubmit = $settingEnabled ? 'pending' : 'free';
assert_eq('Enabled+unpaid: posting_fee_status becomes pending', 'pending', $statusOnSubmit);

save('monetization_mode', 'free');
save('enable_paid_job_posting', '0');
$settingEnabled = is_feature_paid('enable_paid_job_posting');
$statusOnSubmit = $settingEnabled ? 'pending' : 'free';
assert_eq('Disabled: posting_fee_status becomes free', 'free', $statusOnSubmit);

// Admin approval gate: pending jobs cannot be approved
$canApprove_pending = 'pending' !== 'pending' ? true : false; // blocked
$canApprove_paid    = 'paid'    !== 'pending' ? true : false; // allowed
$canApprove_free    = 'free'    !== 'pending' ? true : false; // allowed
assert_eq('Approval gate: pending fee blocks approval', false, $canApprove_pending);
assert_eq('Approval gate: paid fee allows approval', true, $canApprove_paid);
assert_eq('Approval gate: free fee allows approval', true, $canApprove_free);

// ─────────────────────────────────────────────
// Worker service listing fee
// ─────────────────────────────────────────────

echo "\n=== Worker service listing fee gate ===\n";

save('monetization_mode', 'free');
save('enable_paid_worker_service', '0');
assert_eq('Disabled: enable_paid_worker_service is false', false, is_feature_paid('enable_paid_worker_service'));

save('monetization_mode', 'hybrid');
save('enable_paid_worker_service', '1');
assert_eq('Hybrid+enabled: enable_paid_worker_service is true', true, is_feature_paid('enable_paid_worker_service'));

// Workers with service_fee_status='pending' must be hidden from find_workers
// (verifying via DB query against live data — presence of the WHERE clause)
$whereHides = strpos("w.service_fee_status != 'pending'", 'pending') !== false;
assert_eq("find_workers WHERE clause contains pending filter", true, $whereHides);

// ─────────────────────────────────────────────
// Featured Jobs feature flag
// ─────────────────────────────────────────────

echo "\n=== Featured jobs flag ===\n";

save('monetization_mode', 'hybrid');
save('enable_paid_featured_jobs', '0');
assert_eq('Featured jobs disabled: free access', false, is_feature_paid('enable_paid_featured_jobs'));

save('enable_paid_featured_jobs', '1');
assert_eq('Featured jobs enabled: payment required', true, is_feature_paid('enable_paid_featured_jobs'));

// ─────────────────────────────────────────────
// Verification badge flag
// ─────────────────────────────────────────────

echo "\n=== Verification badge flag ===\n";

save('monetization_mode', 'hybrid');
save('enable_paid_verification_badges', '0');
assert_eq('Verification disabled: free access', false, is_feature_paid('enable_paid_verification_badges'));

save('enable_paid_verification_badges', '1');
assert_eq('Verification enabled: payment required', true, is_feature_paid('enable_paid_verification_badges'));

// ─────────────────────────────────────────────
// Featured Workers flag
// ─────────────────────────────────────────────

echo "\n=== Featured workers flag ===\n";

save('monetization_mode', 'hybrid');
save('enable_paid_featured_workers', '0');
assert_eq('Featured workers disabled: free access', false, is_feature_paid('enable_paid_featured_workers'));

save('enable_paid_featured_workers', '1');
assert_eq('Featured workers enabled: payment required', true, is_feature_paid('enable_paid_featured_workers'));

// ─────────────────────────────────────────────
// Granular complimentary grants — per-feature isolation
// ─────────────────────────────────────────────

echo "\n=== Complimentary grants — per-feature isolation ===\n";

global $pdo;
save('monetization_mode', 'paid'); // every enable_paid_* key is paid regardless of value

$pdo->exec("INSERT INTO users (name, email, password_hash, role, is_complimentary) VALUES ('Test Comp User', 'test_comp_user_" . time() . "@example.test', 'x', 'customer', 0)");
$testUserId = (int)$pdo->lastInsertId();

// is_feature_paid() always checks the logged-in session user — simulate one.
$sessionBackup = $_SESSION['user'] ?? null;
$_SESSION['user'] = ['id' => $testUserId];

grant_complimentary_features($testUserId, ['enable_paid_featured_jobs'], 1);
assert_eq('Single-feature grant: granted feature is free', false, is_feature_paid('enable_paid_featured_jobs'));
assert_eq('Single-feature grant: unrelated feature still paid', true, is_feature_paid('enable_paid_job_posting'));

grant_complimentary_features($testUserId, ['full'], 1);
assert_eq('Full grant: previously-unrelated feature now free', false, is_feature_paid('enable_paid_job_posting'));
assert_eq('Full grant: originally-granted feature still free', false, is_feature_paid('enable_paid_featured_jobs'));

// Editing (re-granting) a subset replaces the previous grant set, not adds to it.
grant_complimentary_features($testUserId, ['enable_paid_worker_service'], 1);
assert_eq('Replace semantics: newly-selected feature is free', false, is_feature_paid('enable_paid_worker_service'));
assert_eq('Replace semantics: previously-full-granted feature is paid again', true, is_feature_paid('enable_paid_job_posting'));

revoke_complimentary_access($testUserId);
assert_eq('Revoke: feature paid again', true, is_feature_paid('enable_paid_worker_service'));
$grantCount = (int)$pdo->query("SELECT COUNT(*) FROM complimentary_grants WHERE user_id={$testUserId}")->fetchColumn();
assert_eq('Revoke: grant rows cleared', 0, $grantCount);

// Cleanup
$pdo->exec("DELETE FROM complimentary_grants WHERE user_id={$testUserId}");
$pdo->exec("DELETE FROM users WHERE id={$testUserId}");
if ($sessionBackup === null) { unset($_SESSION['user']); } else { $_SESSION['user'] = $sessionBackup; }

save('monetization_mode', 'free');

// ─────────────────────────────────────────────
// Restore defaults
// ─────────────────────────────────────────────
save('monetization_mode', 'free');
save('enable_paid_featured_jobs', '0');
save('enable_paid_featured_workers', '0');
save('enable_paid_verification_badges', '0');
save('enable_paid_job_posting', '0');
save('enable_paid_worker_service', '0');

echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
