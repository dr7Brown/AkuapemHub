<?php
/**
 * FM Stations — small AJAX endpoint, mirroring news_ajax.php's shape.
 * Currently just handles programme comments (listener reactions already
 * work via a plain form POST on fm_programme.php itself).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/fm_functions.php';

header('Content-Type: application/json');

if (!module_enabled('fm')) { echo json_encode(['error' => 'module_disabled']); exit; }

$user          = current_user();
$action        = $_POST['action'] ?? '';
$stationId     = (int)($_POST['station_id'] ?? 0);
$programmeSlug = trim($_POST['programme_slug'] ?? '');

if (!$action || !$stationId || $programmeSlug === '') { echo json_encode(['error' => 'bad_request']); exit; }
if (!$user) { echo json_encode(['error' => 'login_required']); exit; }
csrf_check();

// Verify the station is active and the programme actually exists there.
$stmt = $pdo->prepare("SELECT id FROM fm_stations WHERE id=? AND status='active' LIMIT 1");
$stmt->execute([$stationId]);
if (!$stmt->fetch()) { echo json_encode(['error' => 'not_found']); exit; }

$stmt = $pdo->prepare("SELECT id FROM fm_programmes WHERE station_id=? AND slug=? AND status='active' LIMIT 1");
$stmt->execute([$stationId, $programmeSlug]);
if (!$stmt->fetch()) { echo json_encode(['error' => 'not_found']); exit; }

switch ($action) {
    case 'comment':
        $text = trim($_POST['comment'] ?? '');
        if (strlen($text) < 2)    { echo json_encode(['error' => 'Comment is too short.']); exit; }
        if (strlen($text) > 1000) { echo json_encode(['error' => 'Comment is too long (max 1000 chars).']); exit; }

        $pdo->prepare("INSERT INTO fm_programme_comments (station_id, programme_slug, user_id, comment) VALUES (?,?,?,?)")
            ->execute([$stationId, $programmeSlug, $user['id'], $text]);

        $initial = mb_strtoupper(mb_substr($user['name'], 0, 1));
        $avatar  = !empty($user['profile_photo'])
            ? '<img src="' . sanitize($user['profile_photo']) . '" alt="">'
            : $initial;
        $html = '<div class="fmp-comment">
            <div class="fmp-com-av">' . $avatar . '</div>
            <div class="fmp-com-body">
                <span class="fmp-com-name">' . sanitize($user['name']) . '</span>
                <span class="fmp-com-date">&nbsp;·&nbsp;just now</span>
                <p class="fmp-com-text">' . nl2br(sanitize($text)) . '</p>
            </div>
        </div>';
        echo json_encode(['success' => true, 'html' => $html]);
        break;

    default:
        echo json_encode(['error' => 'unknown_action']);
}
