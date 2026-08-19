<?php
/**
 * Promotions / Special Offers — admin CRUD. Free-access enforcement lives
 * in user_has_complimentary_access() (functions.php) and discount
 * enforcement lives in initializePayment() (paystack.php) — this page only
 * manages the promotions/promotion_claims rows and the one-shot bulk
 * "you got a free offer" notification.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: index.php'); exit; }
$user = current_user();
require_mod_permission('manage_promotions');

$PROMO_TYPES = [
    'free_package'          => 'Free Package',
    'free_listing'          => 'Free Listing',
    'free_featured_listing' => 'Free Featured Listing',
    'discount'               => 'Discount',
    'free_service'           => 'Free Service',
];
$FEATURE_KEYS = all_complimentary_features();

// Lazy-expire — only keeps the admin list's displayed status fresh; the
// real gate (user_has_complimentary_access()) always computes from
// expiry_date directly and never depends on this column.
$pdo->exec("UPDATE promotions SET status='expired' WHERE status='active' AND ends_at IS NOT NULL AND ends_at < CURDATE()");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id            = (int)($_POST['id'] ?? 0);
        $name          = trim($_POST['name'] ?? '');
        $description   = trim($_POST['description'] ?? '');
        $type          = $_POST['type'] ?? '';
        $featureKey    = $_POST['feature_key'] ?? '';
        $durationDays  = max(1, (int)($_POST['duration_days'] ?? 0));
        $discountPct   = trim($_POST['discount_percent'] ?? '') !== '' ? (int)$_POST['discount_percent'] : null;
        $promoCode     = trim($_POST['promo_code'] ?? '');
        $promoCode     = $promoCode !== '' ? strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', $promoCode)) : null;
        $maxClaims     = trim($_POST['max_claims'] ?? '') !== '' ? max(1, (int)$_POST['max_claims']) : null;
        $startsAt      = trim($_POST['starts_at'] ?? '') ?: date('Y-m-d');
        $endsAt        = trim($_POST['ends_at'] ?? '') ?: null;
        $status        = in_array($_POST['status'] ?? '', ['draft', 'active', 'disabled'], true) ? $_POST['status'] : 'draft';
        $notifyMessage = trim($_POST['notify_message'] ?? '') ?: null;

        $error = null;
        if ($name === '') {
            $error = 'Promotion name is required.';
        } elseif (!isset($PROMO_TYPES[$type])) {
            $error = 'Please choose a valid promotion type.';
        } elseif (!isset($FEATURE_KEYS[$featureKey])) {
            $error = 'Please choose a valid applicable feature.';
        } elseif ($type === 'discount' && ($discountPct === null || $discountPct < 1 || $discountPct > 100)) {
            $error = 'Discount % is required (1–100) for Discount-type promotions.';
        } elseif ($type === 'discount' && feature_key_to_payment_type($featureKey) === null) {
            $error = 'That feature does not have a matching payment type, so a discount can\'t be applied to it. Choose a different feature.';
        } elseif ($endsAt && $endsAt < $startsAt) {
            $error = 'End date can\'t be before the start date.';
        }

        if (!$error) {
            try {
                if ($id > 0) {
                    $pdo->prepare(
                        'UPDATE promotions SET name=?, description=?, type=?, feature_key=?, duration_days=?, discount_percent=?, promo_code=?, max_claims=?, starts_at=?, ends_at=?, status=?, notify_message=? WHERE id=?'
                    )->execute([$name, $description ?: null, $type, $featureKey, $durationDays, $discountPct, $promoCode, $maxClaims, $startsAt, $endsAt, $status, $notifyMessage, $id]);
                    log_audit_action($user['id'], 'promotion_edited', "Edited promotion #{$id}: {$name}");
                    flash('Promotion updated.', 'success');
                } else {
                    $pdo->prepare(
                        'INSERT INTO promotions (name, description, type, feature_key, duration_days, discount_percent, promo_code, max_claims, starts_at, ends_at, status, notify_message, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
                    )->execute([$name, $description ?: null, $type, $featureKey, $durationDays, $discountPct, $promoCode, $maxClaims, $startsAt, $endsAt, $status, $notifyMessage, $user['id']]);
                    $id = (int)$pdo->lastInsertId();
                    log_audit_action($user['id'], 'promotion_created', "Created promotion #{$id}: {$name}");
                    flash('Promotion created.', 'success');
                }
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    flash('That promo code is already in use by another promotion.', 'error');
                } else {
                    flash('Could not save the promotion.', 'error');
                }
                header('Location: promotions.php'); exit;
            }
        } else {
            flash($error, 'error');
            header('Location: promotions.php'); exit;
        }
        header('Location: promotions.php'); exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $row = $pdo->prepare('SELECT name FROM promotions WHERE id=?');
            $row->execute([$id]);
            $name = $row->fetchColumn();
            $pdo->prepare('DELETE FROM promotions WHERE id=?')->execute([$id]);
            log_audit_action($user['id'], 'promotion_deleted', "Deleted promotion #{$id}: {$name}");
            flash('Promotion deleted.', 'success');
        }
        header('Location: promotions.php'); exit;
    }

    if ($action === 'toggle_status') {
        $id     = (int)($_POST['id'] ?? 0);
        $target = $_POST['target'] ?? '';
        $row = $pdo->prepare('SELECT * FROM promotions WHERE id=?');
        $row->execute([$id]);
        $promo = $row->fetch();

        if ($promo && in_array($target, ['active', 'disabled', 'draft'], true)) {
            $pdo->prepare('UPDATE promotions SET status=? WHERE id=?')->execute([$target, $id]);
            log_audit_action($user['id'], 'promotion_status_changed', "Promotion #{$id} ({$promo['name']}) → {$target}");

            // One-shot bulk notify on the FIRST draft→active transition —
            // guarded by notified_at so re-activating later doesn't spam.
            if ($target === 'active' && $promo['status'] !== 'active' && $promo['notified_at'] === null) {
                promo_notify_all_users($pdo, $promo);
                $pdo->prepare('UPDATE promotions SET notified_at=NOW() WHERE id=?')->execute([$id]);
            }
            flash('Promotion status updated.', 'success');
        }
        header('Location: promotions.php'); exit;
    }

    if ($action === 'notify_now') {
        $id = (int)($_POST['id'] ?? 0);
        $row = $pdo->prepare('SELECT * FROM promotions WHERE id=?');
        $row->execute([$id]);
        $promo = $row->fetch();
        if ($promo) {
            promo_notify_all_users($pdo, $promo);
            $pdo->prepare('UPDATE promotions SET notified_at=NOW() WHERE id=?')->execute([$id]);
            log_audit_action($user['id'], 'promotion_notify_resent', "Manually re-sent notification for promotion #{$id}: {$promo['name']}");
            flash('Notification sent to all users.', 'success');
        }
        header('Location: promotions.php'); exit;
    }
}

/**
 * One SQL statement, any user count — no PHP loop over users. Matches the
 * banned=0 filter already used by every bulk-recipient query in this
 * codebase (see broadcast_recipient_ids()).
 */
function promo_notify_all_users(PDO $pdo, array $promo): void {
    $title = '🎁 Special Offer: ' . $promo['name'];
    $body  = $promo['notify_message'] ?: ('You have a new offer available on AkuapemConnect: ' . $promo['name'] . '. Check it out!');
    $pdo->prepare(
        "INSERT INTO notifications (user_id, title, body, type, link, created_at)
         SELECT id, ?, ?, 'info', 'promotions.php', NOW() FROM users WHERE banned = 0"
    )->execute([$title, $body]);
}

// ── View a single promotion's claims ────────────────────────────────────
$viewClaimsId = (int)($_GET['view_claims'] ?? 0);
$viewClaimsPromo = null;
$claimRows = [];
$claimsTotalPages = 1;
if ($viewClaimsId > 0) {
    $vc = $pdo->prepare('SELECT * FROM promotions WHERE id=?');
    $vc->execute([$viewClaimsId]);
    $viewClaimsPromo = $vc->fetch();
    if ($viewClaimsPromo) {
        $cPage = max(1, (int)($_GET['cpage'] ?? 1));
        $cPer  = 30;
        $cOff  = ($cPage - 1) * $cPer;
        $cCountStmt = $pdo->prepare('SELECT COUNT(*) FROM promotion_claims WHERE promotion_id=?');
        $cCountStmt->execute([$viewClaimsId]);
        $claimsTotalPages = max(1, (int)ceil(((int)$cCountStmt->fetchColumn()) / $cPer));
        $cStmt = $pdo->prepare(
            "SELECT pc.*, u.name AS user_name, u.email AS user_email
             FROM promotion_claims pc JOIN users u ON pc.user_id = u.id
             WHERE pc.promotion_id = ?
             ORDER BY pc.claimed_at DESC LIMIT $cPer OFFSET $cOff"
        );
        $cStmt->execute([$viewClaimsId]);
        $claimRows = $cStmt->fetchAll();
    }
}

// ── List ─────────────────────────────────────────────────────────────────
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$total      = (int)$pdo->query('SELECT COUNT(*) FROM promotions')->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$stmt = $pdo->query("SELECT * FROM promotions ORDER BY FIELD(status,'active','draft','disabled','expired'), created_at DESC LIMIT $perPage OFFSET $offset");
$promotions = $stmt->fetchAll();

$activeCount = (int)$pdo->query("SELECT COUNT(*) FROM promotions WHERE status='active'")->fetchColumn();
$totalClaims = (int)$pdo->query('SELECT COUNT(*) FROM promotion_claims')->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Promotions — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .ap-shell  { max-width:1060px; margin:0 auto; padding:20px 16px 60px; }
        .ap-stats  { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:20px; }
        .ap-stat   { background:var(--surface,#fff); border:1px solid var(--border); border-radius:10px; padding:10px 16px; text-align:center; min-width:110px; }
        .ap-stat strong { display:block; font-size:1.3rem; }
        .ap-stat span   { font-size:.74rem; color:var(--text-muted); }
        .ap-table  { width:100%; border-collapse:collapse; font-size:.85rem; }
        .ap-table th { background:var(--surface-muted,#f9fafb); padding:9px 12px; text-align:left; font-size:.75rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--text-muted); border-bottom:1px solid var(--border); }
        .ap-table td { padding:10px 12px; border-bottom:1px solid var(--border,#e5e7eb); vertical-align:middle; }
        .ap-table tr:last-child td { border-bottom:none; }
        .ap-table tr:hover td { background:var(--surface-muted,#f9fafb); }
        .ap-badge  { display:inline-block; font-size:.65rem; font-weight:800; padding:2px 8px; border-radius:20px; }
        .ap-badge-active   { background:#ecfdf5; color:#065f46; }
        .ap-badge-draft    { background:#f3f4f6; color:#6b7280; }
        .ap-badge-expired  { background:#f3f4f6; color:#9ca3af; }
        .ap-badge-disabled { background:#fee2e2; color:#991b1b; }
        .ap-actions { display:flex; gap:5px; flex-wrap:wrap; }
        .ap-form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px,1fr)); gap: 12px; }
        .ap-form-grid label { font-weight: 600; font-size: .82rem; display: block; margin-bottom: 4px; }
        .ap-form-grid input, .ap-form-grid select, .ap-form-grid textarea { width: 100%; padding: 7px 9px; border: 1px solid var(--border); border-radius: 8px; font-size: .84rem; box-sizing: border-box; }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="index.php" class="button button-secondary button-small">← Admin</a>
        <h1>🎁 Promotions</h1>
        <a href="../promotions.php" target="_blank" rel="noopener" class="button button-primary button-small">View Live Page</a>
    </header>

    <div class="ap-shell">
        <?php if ($flash = get_flash()): ?><div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin-bottom:12px;"><?php echo sanitize($flash['message']); ?></div><?php endif; ?>

        <?php if ($viewClaimsPromo): ?>
        <div style="background:var(--surface,#fff);border:1px solid var(--border);border-radius:12px;padding:18px;margin-bottom:20px;">
            <h2 style="margin:0 0 4px;">Claims — <?php echo sanitize($viewClaimsPromo['name']); ?></h2>
            <p style="color:var(--text-muted,#6b7280);font-size:.85rem;margin:0 0 14px;">
                <?php echo (int)$viewClaimsPromo['claims_count']; ?> claim<?php echo (int)$viewClaimsPromo['claims_count'] === 1 ? '' : 's'; ?>
                <?php if ($viewClaimsPromo['max_claims']): ?> of <?php echo (int)$viewClaimsPromo['max_claims']; ?> max<?php endif; ?>
                — <a href="promotions.php">← Back to all promotions</a>
            </p>
            <?php if (!$claimRows): ?>
            <p style="text-align:center;padding:24px;color:var(--text-muted);">No one has claimed this promotion yet.</p>
            <?php else: ?>
            <table class="ap-table">
                <thead><tr><th>User</th><th>Claimed</th><th>Expires</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($claimRows as $c):
                        $displayStatus = ($c['status'] === 'active' && $c['expiry_date'] < date('Y-m-d')) ? 'expired' : $c['status'];
                    ?>
                    <tr>
                        <td><strong><?php echo sanitize($c['user_name']); ?></strong><br><span style="font-size:.76rem;color:var(--text-muted);"><?php echo sanitize($c['user_email']); ?></span></td>
                        <td style="font-size:.8rem;"><?php echo date('d M Y', strtotime($c['claimed_at'])); ?></td>
                        <td style="font-size:.8rem;"><?php echo date('d M Y', strtotime($c['expiry_date'])); ?></td>
                        <td><span class="ap-badge ap-badge-<?php echo $displayStatus === 'active' ? 'active' : 'disabled'; ?>"><?php echo ucfirst($displayStatus); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($claimsTotalPages > 1): ?>
            <div style="display:flex;gap:8px;justify-content:center;margin-top:16px;flex-wrap:wrap;">
                <?php for ($p = 1; $p <= $claimsTotalPages; $p++): ?>
                <a href="promotions.php?view_claims=<?php echo $viewClaimsId; ?>&cpage=<?php echo $p; ?>" class="button button-small <?php echo $p === $cPage ? 'button-primary' : 'button-secondary'; ?>"><?php echo $p; ?></a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="ap-stats">
            <div class="ap-stat"><strong><?php echo $activeCount; ?></strong><span>Active Promotions</span></div>
            <div class="ap-stat"><strong><?php echo $totalClaims; ?></strong><span>Total Claims</span></div>
            <div class="ap-stat"><strong><?php echo $total; ?></strong><span>All Promotions</span></div>
        </div>

        <div style="overflow-x:auto;background:var(--surface,#fff);border:1px solid var(--border);border-radius:12px;margin-bottom:20px;">
            <table class="ap-table">
                <thead>
                    <tr><th>Promotion</th><th>Type</th><th>Duration</th><th>Claims</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($promotions as $p): $pJson = htmlspecialchars(json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>
                    <tr>
                        <td><strong><?php echo sanitize($p['name']); ?></strong><?php if ($p['promo_code']): ?><br><span style="font-size:.72rem;color:var(--text-muted);font-family:monospace;"><?php echo sanitize($p['promo_code']); ?></span><?php endif; ?></td>
                        <td style="font-size:.8rem;"><?php echo sanitize($PROMO_TYPES[$p['type']] ?? $p['type']); ?></td>
                        <td style="font-size:.8rem;"><?php echo (int)$p['duration_days']; ?> days</td>
                        <td style="font-size:.8rem;"><a href="promotions.php?view_claims=<?php echo $p['id']; ?>"><?php echo (int)$p['claims_count']; ?><?php echo $p['max_claims'] ? '/' . (int)$p['max_claims'] : ''; ?></a></td>
                        <td><span class="ap-badge ap-badge-<?php echo $p['status']; ?>"><?php echo strtoupper($p['status']); ?></span></td>
                        <td>
                            <div class="ap-actions">
                                <button type="button" class="button button-small button-secondary" onclick='editPromo(<?php echo $pJson; ?>)'>Edit</button>
                                <?php if ($p['status'] !== 'active'): ?>
                                <form method="post" action="promotions.php" class="inline-form"><?php echo csrf_field(); ?><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="id" value="<?php echo $p['id']; ?>"><input type="hidden" name="target" value="active"><button class="button button-small" style="background:#ecfdf5;color:#065f46;border-color:#6ee7b7;">Activate</button></form>
                                <?php else: ?>
                                <form method="post" action="promotions.php" class="inline-form"><?php echo csrf_field(); ?><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="id" value="<?php echo $p['id']; ?>"><input type="hidden" name="target" value="disabled"><button class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Deactivate</button></form>
                                <form method="post" action="promotions.php" class="inline-form"><?php echo csrf_field(); ?><input type="hidden" name="action" value="notify_now"><input type="hidden" name="id" value="<?php echo $p['id']; ?>"><button class="button button-small button-secondary" title="Re-send the offer notification to every user">🔔 Notify</button></form>
                                <?php endif; ?>
                                <form method="post" action="promotions.php" class="inline-form" onsubmit="return confirm('Delete this promotion? Existing claims are removed too.')"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $p['id']; ?>"><button class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Delete</button></form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$promotions): ?>
                    <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--text-muted);">No promotions yet — add one below.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
        <div style="display:flex;gap:8px;justify-content:center;margin-bottom:24px;flex-wrap:wrap;">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a href="promotions.php?page=<?php echo $p; ?>" class="button button-small <?php echo $p === $page ? 'button-primary' : 'button-secondary'; ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

        <div style="background:var(--surface,#fff);border:1px solid var(--border);border-radius:12px;padding:18px;">
            <h3 id="ap-form-title" style="margin:0 0 14px;">Add Promotion</h3>
            <form method="post" action="promotions.php" id="ap-form">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="ap_id" value="0">
                <div class="ap-form-grid">
                    <div>
                        <label>Promotion Name</label>
                        <input type="text" name="name" id="ap_name" required placeholder="e.g. Free 30-Day Worker Premium">
                    </div>
                    <div>
                        <label>Promotion Type</label>
                        <select name="type" id="ap_type" onchange="apToggleDiscount()">
                            <?php foreach ($PROMO_TYPES as $tk => $tl): ?>
                            <option value="<?php echo $tk; ?>"><?php echo sanitize($tl); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Applicable Feature</label>
                        <select name="feature_key" id="ap_feature_key">
                            <?php foreach ($FEATURE_KEYS as $fk => $fl): ?>
                            <option value="<?php echo $fk; ?>"><?php echo sanitize($fl); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Number of Free Days</label>
                        <input type="number" name="duration_days" id="ap_duration_days" required min="1" value="30">
                    </div>
                    <div id="ap_discount_wrap" style="display:none;">
                        <label>Discount %</label>
                        <input type="number" name="discount_percent" id="ap_discount_percent" min="1" max="100" placeholder="e.g. 20">
                    </div>
                    <div>
                        <label>Promo Code <span style="font-weight:400;color:var(--text-muted,#6b7280);">(optional)</span></label>
                        <input type="text" name="promo_code" id="ap_promo_code" placeholder="e.g. AKUAPEM2026" style="text-transform:uppercase;">
                    </div>
                    <div>
                        <label>Max Claims <span style="font-weight:400;color:var(--text-muted,#6b7280);">(optional)</span></label>
                        <input type="number" name="max_claims" id="ap_max_claims" min="1" placeholder="Unlimited">
                    </div>
                    <div>
                        <label>Start Date</label>
                        <input type="date" name="starts_at" id="ap_starts_at" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div>
                        <label>End Date <span style="font-weight:400;color:var(--text-muted,#6b7280);">(optional)</span></label>
                        <input type="date" name="ends_at" id="ap_ends_at">
                    </div>
                    <div>
                        <label>Status</label>
                        <select name="status" id="ap_status">
                            <option value="draft">Draft</option>
                            <option value="active">Active</option>
                            <option value="disabled">Disabled</option>
                        </select>
                    </div>
                </div>
                <div style="margin-top:12px;">
                    <label style="font-weight:600;font-size:.82rem;display:block;margin-bottom:4px;">Short Description</label>
                    <textarea name="description" id="ap_description" rows="2" placeholder="Shown to users on the offers page"></textarea>
                </div>
                <div style="margin-top:10px;">
                    <label style="font-weight:600;font-size:.82rem;display:block;margin-bottom:4px;">Notification Message <span style="font-weight:400;color:var(--text-muted,#6b7280);">(optional — sent to every user when this promotion goes live)</span></label>
                    <textarea name="notify_message" id="ap_notify_message" rows="2" placeholder="e.g. You have received a FREE 30-day Worker Premium subscription on AkuapemConnect!"></textarea>
                </div>
                <div style="display:flex;gap:8px;margin-top:14px;">
                    <button type="submit" class="button button-primary">Save Promotion</button>
                    <button type="button" class="button button-secondary" onclick="resetPromoForm()">Clear</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function apToggleDiscount() {
        document.getElementById('ap_discount_wrap').style.display = document.getElementById('ap_type').value === 'discount' ? '' : 'none';
    }
    function editPromo(p) {
        document.getElementById('ap_id').value = p.id;
        document.getElementById('ap_name').value = p.name || '';
        document.getElementById('ap_type').value = p.type;
        document.getElementById('ap_feature_key').value = p.feature_key;
        document.getElementById('ap_duration_days').value = p.duration_days;
        document.getElementById('ap_discount_percent').value = p.discount_percent || '';
        document.getElementById('ap_promo_code').value = p.promo_code || '';
        document.getElementById('ap_max_claims').value = p.max_claims || '';
        document.getElementById('ap_starts_at').value = p.starts_at;
        document.getElementById('ap_ends_at').value = p.ends_at || '';
        document.getElementById('ap_status').value = p.status;
        document.getElementById('ap_description').value = p.description || '';
        document.getElementById('ap_notify_message').value = p.notify_message || '';
        document.getElementById('ap-form-title').textContent = 'Edit Promotion — ' + p.name;
        apToggleDiscount();
        document.getElementById('ap-form').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    function resetPromoForm() {
        document.getElementById('ap-form').reset();
        document.getElementById('ap_id').value = 0;
        document.getElementById('ap-form-title').textContent = 'Add Promotion';
        apToggleDiscount();
    }
    apToggleDiscount();
    </script>
</body>
</html>
