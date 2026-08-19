<?php
/**
 * Promotions module tests.
 *
 * Run from CLI: php tests/promotions_test.php
 *
 * Proves:
 *   1. Claiming an active promotion grants free access via
 *      user_has_complimentary_access() with no other checkpoint changes.
 *   2. Duplicate claims, expired promotions, disabled/draft promotions, and
 *      exhausted max_claims are all rejected server-side.
 *   3. Discount-type claims are keyed by payment_type (not feature_key) and
 *      correctly reduce the amount at the same lookup initializePayment()
 *      performs, without leaking into unrelated payment types.
 *   4. Promo-code lookup resolves to the same claim_promotion() path.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

global $pdo;

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

function cleanup_test_promotions() {
    global $pdo;
    $pdo->exec("DELETE FROM promotion_claims WHERE promotion_id IN (SELECT id FROM promotions WHERE name LIKE 'PTest%')");
    $pdo->exec("DELETE FROM promotions WHERE name LIKE 'PTest%'");
}

cleanup_test_promotions();

$admin = $pdo->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetchColumn();
$customers = $pdo->query("SELECT id FROM users WHERE role='customer' LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
if (!$admin || count($customers) < 3) {
    echo "SKIPPED: need at least 1 admin and 3 customer users in the DB to run these tests.\n";
    exit(0);
}
[$u1, $u2, $u3] = $customers;

function make_promotion(array $overrides = []): int {
    global $pdo, $admin;
    $defaults = [
        'name' => 'PTest Free Worker Service',
        'type' => 'free_service',
        'feature_key' => 'enable_paid_worker_service',
        'duration_days' => 7,
        'discount_percent' => null,
        'promo_code' => null,
        'max_claims' => null,
        'starts_at' => date('Y-m-d'),
        'ends_at' => null,
        'status' => 'active',
    ];
    $p = array_merge($defaults, $overrides);
    $pdo->prepare(
        'INSERT INTO promotions (name, type, feature_key, duration_days, discount_percent, promo_code, max_claims, starts_at, ends_at, status, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([$p['name'], $p['type'], $p['feature_key'], $p['duration_days'], $p['discount_percent'], $p['promo_code'], $p['max_claims'], $p['starts_at'], $p['ends_at'], $p['status'], $admin]);
    return (int)$pdo->lastInsertId();
}

// ─────────────────────────────────────────────
echo "\n=== Free-access claim grants the same gate complimentary access uses ===\n";
$pid = make_promotion(['name' => 'PTest Free Access']);
assert_eq('Before claim: no free access', false, user_has_complimentary_access((int)$u1, 'enable_paid_worker_service'));
$r = claim_promotion((int)$u1, $pid);
assert_eq('Claim succeeds', true, $r['ok']);
assert_eq('After claim: free access granted', true, user_has_complimentary_access((int)$u1, 'enable_paid_worker_service'));
assert_eq('Unrelated feature still paid', false, user_has_complimentary_access((int)$u1, 'enable_paid_job_posting'));
assert_eq('Different user unaffected', false, user_has_complimentary_access((int)$u2, 'enable_paid_worker_service'));

// ─────────────────────────────────────────────
echo "\n=== Duplicate claims blocked ===\n";
$dup = claim_promotion((int)$u1, $pid);
assert_eq('Duplicate claim rejected', false, $dup['ok']);
assert_eq('Duplicate claim reason mentions "already claimed"', true, strpos($dup['error'], 'already claimed') !== false);

// ─────────────────────────────────────────────
echo "\n=== Expired / disabled / draft promotions blocked ===\n";
$expiredId = make_promotion(['name' => 'PTest Expired', 'starts_at' => '2020-01-01', 'ends_at' => '2020-01-31']);
$rExp = claim_promotion((int)$u1, $expiredId);
assert_eq('Expired promotion rejected', false, $rExp['ok']);

$disabledId = make_promotion(['name' => 'PTest Disabled', 'status' => 'disabled']);
$rDis = claim_promotion((int)$u1, $disabledId);
assert_eq('Disabled promotion rejected', false, $rDis['ok']);

$draftId = make_promotion(['name' => 'PTest Draft', 'status' => 'draft']);
$rDraft = claim_promotion((int)$u1, $draftId);
assert_eq('Draft promotion rejected', false, $rDraft['ok']);

// ─────────────────────────────────────────────
echo "\n=== Max claims enforced atomically ===\n";
$maxId = make_promotion(['name' => 'PTest MaxClaims', 'max_claims' => 2]);
$m1 = claim_promotion((int)$u1, $maxId);
$m2 = claim_promotion((int)$u2, $maxId);
$m3 = claim_promotion((int)$u3, $maxId);
assert_eq('Claim 1 of 2 succeeds', true, $m1['ok']);
assert_eq('Claim 2 of 2 succeeds', true, $m2['ok']);
assert_eq('Claim 3 (over max) rejected', false, $m3['ok']);
assert_eq('claims_count stops at 2, not 3', 2, (int)$pdo->query("SELECT claims_count FROM promotions WHERE id=$maxId")->fetchColumn());

// ─────────────────────────────────────────────
echo "\n=== Discount claims: payment_type mapping + amount reduction ===\n";
$discId = make_promotion(['name' => 'PTest Discount', 'type' => 'discount', 'feature_key' => 'enable_paid_job_posting', 'discount_percent' => 20]);
$rd = claim_promotion((int)$u1, $discId);
assert_eq('Discount claim succeeds', true, $rd['ok']);

$claimRow = $pdo->prepare('SELECT payment_type, discount_percent FROM promotion_claims WHERE promotion_id=? AND user_id=?');
$claimRow->execute([$discId, $u1]);
$claimRow = $claimRow->fetch();
assert_eq('feature_key mapped to correct payment_type', 'job_post', $claimRow['payment_type']);
assert_eq('discount_percent stored on claim', 20, (int)$claimRow['discount_percent']);

// Replicate initializePayment()'s exact lookup (paystack.php) without hitting Paystack.
function discount_lookup($userId, $paymentType) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT discount_percent FROM promotion_claims WHERE user_id=? AND payment_type=? AND status='active' AND expiry_date >= CURDATE() AND discount_percent IS NOT NULL LIMIT 1");
    $stmt->execute([$userId, $paymentType]);
    return $stmt->fetchColumn();
}
$pct = discount_lookup($u1, 'job_post');
$amount = 100.0;
if ($pct) $amount = round($amount * (1 - $pct / 100), 2);
assert_eq('100.00 correctly reduced to 80.00 for job_post', 80.0, $amount);
assert_eq('Discount does not leak to a different payment_type', false, discount_lookup($u1, 'worker_premium'));

// ─────────────────────────────────────────────
echo "\n=== Promo code resolves to the same claim path ===\n";
$codeId = make_promotion(['name' => 'PTest Code', 'promo_code' => 'PTESTCODE2026']);
$found = $pdo->prepare('SELECT id FROM promotions WHERE promo_code=?');
$found->execute(['PTESTCODE2026']);
assert_eq('Promo code resolves to the right promotion id', $codeId, (int)$found->fetchColumn());
$rCode = claim_promotion((int)$u2, $codeId);
assert_eq('Claim via resolved code succeeds', true, $rCode['ok']);

// ─────────────────────────────────────────────
cleanup_test_promotions();
$remaining = (int)$pdo->query("SELECT COUNT(*) FROM promotions WHERE name LIKE 'PTest%'")->fetchColumn();
assert_eq('Cleanup verified — 0 test promotions remain', 0, $remaining);

echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
