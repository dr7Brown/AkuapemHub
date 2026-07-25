<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
$user = current_user();

// Load notifications (up to 50, unread first)
$notifStmt = $pdo->prepare(
    'SELECT * FROM notifications WHERE user_id = ?
     ORDER BY is_read ASC, created_at DESC LIMIT 50'
);
$notifStmt->execute([$user['id']]);
$notifications = $notifStmt->fetchAll();

$unreadCount = count(array_filter($notifications, fn($n) => !$n['is_read']));
// Individual mark-as-read is handled via AJAX when each notification is expanded.

// Group by date
function notif_date_group(string $dt): string {
    $ts   = strtotime($dt);
    $diff = (int)floor((time() - $ts) / 86400);
    if ($diff === 0)  return 'Today';
    if ($diff === 1)  return 'Yesterday';
    if ($diff < 7)    return 'This Week';
    if ($diff < 30)   return 'This Month';
    return date('F Y', $ts);
}

$typeIcon = ['info'=>'ℹ️','success'=>'✅','warning'=>'⚠️','error'=>'❌'];
$typeBorder = ['info'=>'#3b82f6','success'=>'#10b981','warning'=>'#f59e0b','error'=>'#ef4444'];
$typeBg     = ['info'=>'#eff6ff','success'=>'#f0fdf4','warning'=>'#fffbeb','error'=>'#fef2f2'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .nf-shell { max-width:680px; margin:0 auto; padding:14px 16px 80px; }

        .nf-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; }
        .nf-header h1 { font-size:1.1rem; font-weight:900; margin:0; }
        .nf-unread-badge { background:var(--primary,#0f766e); color:#fff; font-size:.72rem; font-weight:800; padding:3px 9px; border-radius:20px; }

        .nf-group-label { font-size:.7rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:18px 0 8px; }

        /* Notification card */
        .nf-card {
            background:var(--surface);
            border:1px solid var(--border,#e5e7eb);
            border-left:4px solid var(--border,#e5e7eb);
            border-radius:12px;
            margin-bottom:8px;
            overflow:hidden;
            transition:box-shadow .12s;
        }
        .nf-card.unread {
            border-left-color: var(--primary,#0f766e);
            background:var(--primary-soft,#f0fdf4);
        }
        .nf-card.unread .nf-title { font-weight:800; }

        /* Read state — faded like iOS/Gmail */
        .nf-card:not(.unread) { opacity:.55; }
        .nf-card:not(.unread):hover { opacity:.85; transition:opacity .15s; }
        .nf-card:not(.unread) .nf-title { font-weight:400; color:var(--muted,#6b7280); }
        .nf-card-inner {
            display:flex;
            align-items:flex-start;
            gap:10px;
            padding:12px 14px;
            cursor:pointer;
        }
        .nf-card-inner:hover { background:rgba(0,0,0,.02); }
        .nf-icon { font-size:1.25rem; flex-shrink:0; margin-top:1px; }
        .nf-content { flex:1; min-width:0; }
        .nf-title { font-size:.88rem; line-height:1.4; margin:0 0 3px; }
        .nf-preview { font-size:.78rem; color:var(--text-muted,#6b7280); line-height:1.5; margin:0;
                      overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
        .nf-time { font-size:.7rem; color:var(--text-muted,#9ca3af); white-space:nowrap; flex-shrink:0; margin-top:2px; }

        /* Expanded body */
        .nf-expanded { display:none; padding:0 14px 12px 44px; }
        .nf-expanded.open { display:block; }
        .nf-body-full { font-size:.86rem; color:#374151; line-height:1.7; margin:0 0 10px; background:rgba(0,0,0,.02); border-radius:8px; padding:10px 12px; }
        .nf-action-link {
            display:inline-flex; align-items:center; gap:5px;
            background:var(--primary,#0f766e); color:#fff;
            font-size:.8rem; font-weight:700; padding:6px 14px;
            border-radius:8px; text-decoration:none; transition:opacity .1s;
        }
        .nf-action-link:hover { opacity:.88; }

        /* Empty state */
        .nf-empty { text-align:center; padding:56px 20px; color:var(--text-muted,#6b7280); }
        .nf-empty-icon { font-size:3rem; opacity:.35; margin-bottom:12px; }

        /* Unread dot */
        .nf-dot { width:8px; height:8px; border-radius:50%; background:var(--primary,#0f766e); flex-shrink:0; margin-top:6px; }
    </style>
</head>
<body class="has-bottom-nav">

<header class="app-topbar">
    <a href="javascript:history.back()" class="button button-secondary button-small" style="font-size:.8rem;">← Back</a>
    <span class="brand"><span class="brand-icon">🔔</span> Notifications</span>
    <?php if ($unreadCount > 0): ?>
    <span class="nf-unread-badge"><?php echo $unreadCount; ?> new</span>
    <?php else: ?>
    <span style="font-size:.78rem;color:var(--text-muted,#6b7280);">All read</span>
    <?php endif; ?>
</header>

<main class="nf-shell">

    <?php if (!$notifications): ?>
    <div class="nf-empty">
        <div class="nf-empty-icon">🔔</div>
        <p style="margin:0;font-size:.9rem;font-weight:700;">No notifications yet</p>
        <p style="margin:6px 0 0;font-size:.82rem;">Updates about your jobs, orders, and activity will appear here.</p>
    </div>
    <?php else: ?>

    <div class="nf-header">
        <h1><?php echo count($notifications); ?> Notification<?php echo count($notifications)!==1?'s':''; ?></h1>
    </div>

    <?php
    $lastGroup = null;
    foreach ($notifications as $i => $n):
        $group = notif_date_group($n['created_at']);
        $icon  = $typeIcon[$n['type']] ?? 'ℹ️';
        $border= $typeBorder[$n['type']] ?? '#3b82f6';
        $isUnread = !$n['is_read'];
        $cardId = 'nf-' . $n['id'];
    ?>
    <?php if ($group !== $lastGroup): ?>
        <?php $lastGroup = $group; ?>
        <p class="nf-group-label"><?php echo sanitize($group); ?></p>
    <?php endif; ?>

    <div class="nf-card <?php echo $isUnread ? 'unread' : ''; ?>"
         style="border-left-color:<?php echo $border; ?>;">
        <!-- Summary row — click to expand -->
        <div class="nf-card-inner" onclick="toggleNotif('<?php echo $cardId; ?>',<?php echo (int)$n['id']; ?>)" role="button" tabindex="0"
             onkeydown="if(event.key==='Enter')toggleNotif('<?php echo $cardId; ?>',<?php echo (int)$n['id']; ?>)">
            <span class="nf-icon"><?php echo $icon; ?></span>
            <div class="nf-content">
                <p class="nf-title"><?php echo sanitize($n['title']); ?></p>
                <p class="nf-preview"><?php echo sanitize($n['body']); ?></p>
            </div>
            <span class="nf-time"><?php echo time_ago($n['created_at']); ?></span>
        </div>

        <!-- Expanded detail — body + link -->
        <div class="nf-expanded" id="<?php echo $cardId; ?>">
            <div class="nf-body-full"><?php echo nl2br(sanitize($n['body'])); ?></div>
            <?php if (!empty($n['link'])): ?>
            <a href="<?php echo sanitize($n['link']); ?>" class="nf-action-link">
                View →
            </a>
            <?php endif; ?>
            <div style="margin-top:8px;font-size:.72rem;color:var(--text-muted,#9ca3af);">
                <?php echo date('d M Y, g:i A', strtotime($n['created_at'])); ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>

</main>

<?php $activeNav = 'home'; require __DIR__ . '/partials/bottom_nav.php'; ?>

<script>
var CSRF_NOTIF = <?php echo json_encode(csrf_token()); ?>;

function toggleNotif(id, notifId) {
    var el = document.getElementById(id);
    if (!el) return;
    var isOpen = el.classList.contains('open');

    // Close all others
    document.querySelectorAll('.nf-expanded.open').forEach(function(e) { e.classList.remove('open'); });

    // Toggle this one
    if (!isOpen) {
        el.classList.add('open');

        // Mark this notification as read if it was unread
        var card = el.closest('.nf-card');
        if (card && card.classList.contains('unread')) {
            card.classList.remove('unread');
            // Update the dot
            var dot = card.querySelector('.nf-dot');
            if (dot) dot.style.visibility = 'hidden';
            // Send AJAX
            fetch('ajax.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_notification_read&notification_id=' + encodeURIComponent(notifId) + '&csrf_token=' + encodeURIComponent(CSRF_NOTIF)
            }).catch(function() {});
            // Decrement the bell badge in nav
            decrementNotifBadge();
        }
    }
}

function decrementNotifBadge() {
    var badge = document.querySelector('#notif-bell .nav-badge');
    if (!badge) return;
    var count = parseInt(badge.getAttribute('data-count') || '0', 10) - 1;
    if (count <= 0) {
        badge.classList.remove('nav-badge');
        badge.removeAttribute('data-count');
    } else {
        badge.setAttribute('data-count', count);
    }
}

// Auto-expand if only one unread notification
var unreadCards = document.querySelectorAll('.nf-card.unread');
if (unreadCards.length === 1) {
    var inner = unreadCards[0].querySelector('.nf-card-inner');
    if (inner) inner.click();
}
</script>
</body>
</html>
