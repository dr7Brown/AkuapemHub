<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');

if (!module_enabled('news')) { echo json_encode(['error' => 'module_disabled']); exit; }

$user   = current_user();
$action = $_POST['action'] ?? '';
$newsId = (int)($_POST['news_id'] ?? 0);

if (!$action || !$newsId) { echo json_encode(['error' => 'bad_request']); exit; }
if (!$user)                { echo json_encode(['error' => 'login_required']); exit; }
csrf_check();

// Verify article exists
$art = $pdo->prepare("SELECT id FROM news WHERE id=? AND status='published' LIMIT 1");
$art->execute([$newsId]);
if (!$art->fetch()) { echo json_encode(['error' => 'not_found']); exit; }

switch ($action) {
    case 'like':
        $exists = $pdo->prepare("SELECT id FROM news_likes WHERE news_id=? AND user_id=? LIMIT 1");
        $exists->execute([$newsId, $user['id']]);
        if ($exists->fetch()) {
            $pdo->prepare("DELETE FROM news_likes WHERE news_id=? AND user_id=?")->execute([$newsId, $user['id']]);
            $liked = false;
        } else {
            $pdo->prepare("INSERT IGNORE INTO news_likes (news_id, user_id) VALUES (?,?)")->execute([$newsId, $user['id']]);
            $liked = true;
        }
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM news_likes WHERE news_id=?");
        $countStmt->execute([$newsId]);
        echo json_encode(['liked' => $liked, 'count' => (int)$countStmt->fetchColumn()]);
        break;

    case 'save':
        $exists = $pdo->prepare("SELECT id FROM news_saves WHERE news_id=? AND user_id=? LIMIT 1");
        $exists->execute([$newsId, $user['id']]);
        if ($exists->fetch()) {
            $pdo->prepare("DELETE FROM news_saves WHERE news_id=? AND user_id=?")->execute([$newsId, $user['id']]);
            $saved = false;
        } else {
            $pdo->prepare("INSERT IGNORE INTO news_saves (news_id, user_id) VALUES (?,?)")->execute([$newsId, $user['id']]);
            $saved = true;
        }
        echo json_encode(['saved' => $saved]);
        break;

    case 'comment':
        $text = trim($_POST['comment'] ?? '');
        if (strlen($text) < 2)  { echo json_encode(['error' => 'Comment is too short.']); exit; }
        if (strlen($text) > 1000) { echo json_encode(['error' => 'Comment is too long (max 1000 chars).']); exit; }
        $pdo->prepare("INSERT INTO news_comments (news_id, user_id, comment) VALUES (?,?,?)")->execute([$newsId, $user['id'], $text]);
        $initial = mb_strtoupper(mb_substr($user['name'], 0, 1));
        $avatar  = $user['profile_photo']
            ? '<img src="' . sanitize($user['profile_photo']) . '" alt="">'
            : $initial;
        $html = '<div class="nc-comment">
            <div class="nc-com-av">' . $avatar . '</div>
            <div class="nc-com-body">
                <span class="nc-com-name">' . sanitize($user['name']) . '</span>
                <span class="nc-com-date">&nbsp;·&nbsp;just now</span>
                <p class="nc-com-text">' . nl2br(sanitize($text)) . '</p>
            </div>
        </div>';
        echo json_encode(['success' => true, 'html' => $html]);
        break;

    default:
        echo json_encode(['error' => 'unknown_action']);
}
