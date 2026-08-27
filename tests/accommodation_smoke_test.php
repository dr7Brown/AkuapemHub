<?php
/**
 * Accommodation module smoke test. Run from CLI: php tests/accommodation_smoke_test.php
 * Exercises: create listing -> submit for approval -> admin approve ->
 * appears in public query -> enquiry creates a chat conversation + notifies
 * the owner -> report creates a report row. Cleans up after itself.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../marketplace_functions.php';
require_once __DIR__ . '/../accommodation_functions.php';
require_once __DIR__ . '/../chat_functions.php';

global $pdo;
$passed = 0; $failed = 0;

function assert_eq($label, $expected, $actual) {
    global $passed, $failed;
    if ($expected === $actual) { echo "[PASS] {$label}\n"; $passed++; }
    else { echo "[FAIL] {$label}\n       Expected: " . var_export($expected, true) . "\n       Actual:   " . var_export($actual, true) . "\n"; $failed++; }
}
function assert_true($label, $cond) { assert_eq($label, true, (bool)$cond); }

function cleanup() {
    global $pdo;
    $pdo->exec("DELETE FROM accommodation_reports WHERE listing_id IN (SELECT id FROM accommodation_listings WHERE title LIKE 'ACCTest%')");
    $pdo->exec("DELETE FROM conversations WHERE accommodation_listing_id IN (SELECT id FROM accommodation_listings WHERE title LIKE 'ACCTest%')");
    $pdo->exec("DELETE FROM accommodation_listings WHERE title LIKE 'ACCTest%'");
}
cleanup();

$admin = $pdo->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetchColumn();
$owner = $pdo->query("SELECT id FROM users WHERE role='customer' LIMIT 1")->fetchColumn();
$enquirer = $pdo->query("SELECT id FROM users WHERE role='customer' AND id != $owner LIMIT 1")->fetchColumn();
if (!$admin || !$owner || !$enquirer) { echo "SKIPPED: need an admin + 2 customers.\n"; exit(0); }

$roomType = $pdo->query("SELECT id FROM accommodation_types WHERE category='room_house' LIMIT 1")->fetchColumn();
$wifiId   = $pdo->query("SELECT id FROM accommodation_facilities WHERE slug='wifi' LIMIT 1")->fetchColumn();
assert_true('Seeded room_house type exists', $roomType);
assert_true('Seeded Wi-Fi facility exists', $wifiId);

// ── Create + submit listing ─────────────────────────────────────────────
echo "\n=== Listing lifecycle ===\n";
$slug = mp_unique_slug('ACCTest Self-Contained Room', 'accommodation_listings', 'slug', $pdo);
$pdo->prepare(
    "INSERT INTO accommodation_listings (user_id, accommodation_type_id, title, slug, description, area, price, price_period, facilities, status)
     VALUES (?,?,?,?,?,?,?,?,?, 'pending_approval')"
)->execute([$owner, $roomType, 'ACCTest Self-Contained Room', $slug, 'A nice room.', 'Test Area', 500, 'month', json_encode([(int)$wifiId])]);
$listingId = (int)$pdo->lastInsertId();
assert_true('Listing created', $listingId > 0);

$fetched = get_accommodation_listing($listingId);
assert_eq('get_accommodation_listing() returns the row', 'ACCTest Self-Contained Room', $fetched['title']);
assert_eq('Type join resolves category', 'room_house', $fetched['type_category']);

$publicWhere = accommodation_public_where();
$stillHidden = (int)$pdo->query("SELECT COUNT(*) FROM accommodation_listings al WHERE al.id=$listingId AND $publicWhere")->fetchColumn();
assert_eq('Pending listing not yet publicly visible', 0, $stillHidden);

$pdo->prepare("UPDATE accommodation_listings SET status='approved' WHERE id=?")->execute([$listingId]);
$nowVisible = (int)$pdo->query("SELECT COUNT(*) FROM accommodation_listings al WHERE al.id=$listingId AND $publicWhere")->fetchColumn();
assert_eq('Approved listing now publicly visible', 1, $nowVisible);

// ── Enquiry -> chat integration ─────────────────────────────────────────
echo "\n=== Enquiry creates a conversation + notifies owner ===\n";
$before = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id=$owner")->fetchColumn();
$result = get_or_create_conversation_ex((int)$enquirer, (int)$owner, 'accommodation_enquiry', null, $listingId);
assert_true('Conversation created', $result['created']);
notify_user((int)$owner, '🏠 New accommodation enquiry', 'Test enquiry body.', 'info', 'chat.php?id=' . $result['id']);
$after = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id=$owner")->fetchColumn();
assert_eq('Owner notification count incremented by 1', $before + 1, $after);

$convRow = $pdo->prepare('SELECT conversation_type, accommodation_listing_id, job_id FROM conversations WHERE id=?');
$convRow->execute([$result['id']]);
$convRow = $convRow->fetch();
assert_eq('Conversation type is accommodation_enquiry', 'accommodation_enquiry', $convRow['conversation_type']);
assert_eq('Conversation linked to the listing', $listingId, (int)$convRow['accommodation_listing_id']);
assert_eq('job_id left null (not a job conversation)', null, $convRow['job_id']);

// Repeat enquiry from the same user should reuse the conversation, not duplicate it.
$result2 = get_or_create_conversation_ex((int)$enquirer, (int)$owner, 'accommodation_enquiry', null, $listingId);
assert_eq('Repeat enquiry reuses the same conversation', $result['id'], $result2['id']);
assert_eq('Repeat enquiry is NOT flagged as newly created', false, $result2['created']);

$convos = get_user_conversations((int)$owner);
$match = null;
foreach ($convos as $c) { if ((int)$c['id'] === $result['id']) { $match = $c; break; } }
assert_true('get_user_conversations() includes the accommodation conversation', $match !== null);
assert_eq('get_user_conversations() resolves accommodation_title via the new JOIN', 'ACCTest Self-Contained Room', $match['accommodation_title'] ?? null);

// ── Report ───────────────────────────────────────────────────────────────
echo "\n=== Report Listing ===\n";
$pdo->prepare('INSERT INTO accommodation_reports (listing_id, reporter_id, reason, details) VALUES (?,?,?,?)')
    ->execute([$listingId, $enquirer, 'wrong_info', 'Test report.']);
$reportCount = (int)$pdo->query("SELECT COUNT(*) FROM accommodation_reports WHERE listing_id=$listingId AND status='pending'")->fetchColumn();
assert_eq('Report row created with pending status', 1, $reportCount);

// ── Cleanup ──────────────────────────────────────────────────────────────
cleanup();
$remaining = (int)$pdo->query("SELECT COUNT(*) FROM accommodation_listings WHERE title LIKE 'ACCTest%'")->fetchColumn();
assert_eq('Cleanup verified — 0 test listings remain', 0, $remaining);

echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
