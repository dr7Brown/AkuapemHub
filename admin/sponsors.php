<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: index.php'); exit; }
$user = current_user();
require_mod_permission('approve_sponsors');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'])) {
    csrf_check();
    $sid = (int)$_POST['id'];
    $row = $pdo->prepare("SELECT * FROM sponsors WHERE id=? LIMIT 1");
    $row->execute([$sid]);
    $sp = $row->fetch();

    if ($sp) {
        switch ($_POST['action']) {
            case 'approve':
                $pdo->prepare("UPDATE sponsors SET status='active', rejection_reason=NULL, updated_at=NOW() WHERE id=?")->execute([$sid]);
                log_audit_action($user['id'], 'sponsor_approve', "Approved sponsor #{$sid}: {$sp['name']}");
                log_mod_activity($user['id'], 'sponsors', 'approve_sponsor', $sid);
                if ($sp['user_id']) {
                    notify_user($sp['user_id'], '✅ You\'re now a sponsor!',
                        "\"{$sp['name']}\" has been approved and is now live on the homepage.", 'success');
                }
                break;
            case 'reject':
                $reason = trim($_POST['rejection_reason'] ?? '');
                $pdo->prepare("UPDATE sponsors SET status='rejected', rejection_reason=?, updated_at=NOW() WHERE id=?")
                    ->execute([$reason ?: null, $sid]);
                log_audit_action($user['id'], 'sponsor_reject', "Rejected sponsor #{$sid}: {$sp['name']}");
                log_mod_activity($user['id'], 'sponsors', 'reject_sponsor', $sid);
                if ($sp['user_id']) {
                    $body = "\"{$sp['name']}\" was not approved.";
                    if ($reason) $body .= "\n\nReason: {$reason}";
                    notify_user($sp['user_id'], '❌ Sponsor Submission Rejected', $body, 'error');
                }
                break;
            case 'delete':
                $pdo->prepare("DELETE FROM sponsors WHERE id=?")->execute([$sid]);
                log_audit_action($user['id'], 'sponsor_delete', "Deleted sponsor #{$sid}: {$sp['name']}");
                break;
        }
    }
    header('Location: sponsors.php'); exit;
}

// Manually add a sponsor — comp'd/partner sponsors that skip the paid
// become_sponsor.php flow entirely and go live immediately, no owning
// platform account or purchased package required.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_sponsor'])) {
    csrf_check();
    $name         = trim($_POST['name'] ?? '');
    $packageId    = (int)($_POST['package_id'] ?? 0) ?: null;
    $websiteUrl   = trim($_POST['website_url'] ?? '') ?: null;
    $description  = trim($_POST['description'] ?? '') ?: null;
    $contactEmail = trim($_POST['contact_email'] ?? '') ?: null;
    $contactPhone = trim($_POST['contact_phone'] ?? '') ?: null;
    $durationDays = trim($_POST['duration_days'] ?? '') !== '' ? max(1, (int)$_POST['duration_days']) : null;
    $endDate      = $durationDays ? date('Y-m-d', strtotime("+{$durationDays} days")) : null;

    if ($name === '') {
        flash('Sponsor name is required.', 'error');
    } elseif (empty($_FILES['logo']['name'])) {
        flash('Please upload a logo.', 'error');
    } elseif (!is_valid_image_upload($_FILES['logo'])) {
        flash('Logo must be a JPEG, PNG, or WEBP image under 5MB.', 'error');
    } else {
        $pdo->prepare("INSERT INTO sponsors (user_id, package_id, name, logo_path, website_url, description, contact_email, contact_phone, status, start_date, end_date, created_at)
            VALUES (NULL, ?, ?, '', ?, ?, ?, ?, 'active', CURDATE(), ?, NOW())")
            ->execute([$packageId, $name, $websiteUrl, $description, $contactEmail, $contactPhone, $endDate]);
        $newId = (int)$pdo->lastInsertId();

        $logoPath = save_uploaded_image($_FILES['logo'], 'uploads/sponsors/' . $newId, 600);
        if ($logoPath) {
            $pdo->prepare("UPDATE sponsors SET logo_path = ? WHERE id = ?")->execute([$logoPath, $newId]);
            log_audit_action($user['id'], 'sponsor_add', "Manually added sponsor #{$newId}: {$name}");
            flash('Sponsor added and is now live on the homepage.', 'success');
        } else {
            $pdo->prepare("DELETE FROM sponsors WHERE id = ?")->execute([$newId]);
            flash('Could not save the logo. Please try a different image.', 'error');
        }
    }
    header('Location: sponsors.php'); exit;
}

// Edit an existing sponsor's info — name, package, links, contact details,
// end date, and optionally a replacement logo (kept unchanged if none is
// uploaded, same as the manual-add form above).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_sponsor') {
    csrf_check();
    $sid = (int)($_POST['id'] ?? 0);
    $row = $pdo->prepare("SELECT * FROM sponsors WHERE id=? LIMIT 1");
    $row->execute([$sid]);
    $sp = $row->fetch();

    if (!$sp) {
        flash('Sponsor not found.', 'error');
        header('Location: sponsors.php'); exit;
    }

    $name         = trim($_POST['name'] ?? '');
    $packageId    = (int)($_POST['package_id'] ?? 0) ?: null;
    $websiteUrl   = trim($_POST['website_url'] ?? '') ?: null;
    $description  = trim($_POST['description'] ?? '') ?: null;
    $contactEmail = trim($_POST['contact_email'] ?? '') ?: null;
    $contactPhone = trim($_POST['contact_phone'] ?? '') ?: null;
    $endDate      = trim($_POST['end_date'] ?? '') ?: null;

    if ($name === '') {
        flash('Sponsor name is required.', 'error');
    } elseif (!empty($_FILES['logo']['name']) && !is_valid_image_upload($_FILES['logo'])) {
        flash('Logo must be a JPEG, PNG, or WEBP image under 5MB.', 'error');
    } else {
        $logoPath = $sp['logo_path'];
        if (!empty($_FILES['logo']['name'])) {
            $uploaded = save_uploaded_image($_FILES['logo'], 'uploads/sponsors/' . $sid, 600);
            if ($uploaded) {
                $logoPath = $uploaded;
            } else {
                flash('Could not save the new logo — kept the existing one.', 'error');
            }
        }
        $pdo->prepare("UPDATE sponsors SET name=?, package_id=?, logo_path=?, website_url=?, description=?, contact_email=?, contact_phone=?, end_date=?, updated_at=NOW() WHERE id=?")
            ->execute([$name, $packageId, $logoPath, $websiteUrl, $description, $contactEmail, $contactPhone, $endDate, $sid]);
        log_audit_action($user['id'], 'sponsor_edit', "Edited sponsor #{$sid}: {$name}");
        flash('Sponsor updated.', 'success');
    }
    header('Location: sponsors.php'); exit;
}

$statusFilter = in_array($_GET['status'] ?? '', ['pending_payment','pending_approval','active','rejected','expired']) ? $_GET['status'] : '';
$search       = trim($_GET['q'] ?? '');

$where  = '1';
$params = [];
if ($statusFilter) { $where .= " AND s.status=?"; $params[] = $statusFilter; }
if ($search)       { $where .= " AND s.name LIKE ?"; $params[] = "%$search%"; }

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset  = ($page - 1) * $perPage;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM sponsors s WHERE $where");
$countStmt->execute($params);
$sponsorsTotal      = (int)$countStmt->fetchColumn();
$sponsorsTotalPages = max(1, (int)ceil($sponsorsTotal / $perPage));

$stmt = $pdo->prepare(
    "SELECT s.*, sp.name AS package_name FROM sponsors s
     LEFT JOIN sponsor_packages sp ON s.package_id = sp.id
     WHERE $where
     ORDER BY FIELD(s.status,'pending_approval','pending_payment','active','rejected','expired'), s.created_at DESC, s.id DESC
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$counts = $pdo->query("SELECT status, COUNT(*) FROM sponsors GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$sponsorPackages = $pdo->query("SELECT * FROM sponsor_packages WHERE status='active' ORDER BY price ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sponsors — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .as-shell  { max-width:1060px; margin:0 auto; padding:20px 16px 60px; }
        .as-stats  { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:20px; }
        .as-stat   { background:var(--surface,#fff); border:1px solid var(--border); border-radius:10px; padding:10px 16px; text-align:center; min-width:90px; text-decoration:none; color:inherit; }
        .as-stat strong { display:block; font-size:1.3rem; }
        .as-stat span   { font-size:.74rem; color:var(--text-muted); }
        .as-toolbar { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:16px; }
        .as-toolbar form { display:flex; gap:6px; flex:1; min-width:200px; }
        .as-toolbar input  { flex:1; padding:8px 12px; border-radius:8px; border:1px solid var(--border); font-size:.85rem; }
        .as-toolbar select { padding:8px 10px; border-radius:8px; border:1px solid var(--border); font-size:.85rem; }
        .as-table  { width:100%; border-collapse:collapse; font-size:.85rem; }
        .as-table th { background:var(--surface-muted,#f9fafb); padding:9px 12px; text-align:left; font-size:.75rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--text-muted); border-bottom:1px solid var(--border); }
        .as-table td { padding:10px 12px; border-bottom:1px solid var(--border,#e5e7eb); vertical-align:middle; }
        .as-table tr:last-child td { border-bottom:none; }
        .as-table tr:hover td { background:var(--surface-muted,#f9fafb); }
        .as-logo   { width:56px; height:56px; border-radius:8px; object-fit:contain; background:#f8fafc; border:1px solid var(--border); }
        .as-badge  { display:inline-block; font-size:.65rem; font-weight:800; padding:2px 8px; border-radius:20px; }
        .as-badge-active           { background:#ecfdf5; color:#065f46; }
        .as-badge-pending_approval { background:#fef3c7; color:#92400e; }
        .as-badge-pending_payment  { background:#f3f4f6; color:#6b7280; }
        .as-badge-rejected         { background:#fee2e2; color:#991b1b; }
        .as-badge-expired          { background:#f3f4f6; color:#9ca3af; }
        .as-actions { display:flex; gap:5px; flex-wrap:wrap; }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="index.php" class="button button-secondary button-small">← Admin</a>
        <h1>Sponsors</h1>
        <a href="monetization.php?tab=community" class="button button-primary button-small">Manage Packages</a>
    </header>

    <div class="as-shell">
        <?php if ($flash = get_flash()): ?><div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin-bottom:12px;"><?php echo sanitize($flash['message']); ?></div><?php endif; ?>

        <details style="background:var(--surface,#fff);border:1px solid var(--border);border-radius:12px;margin-bottom:16px;">
            <summary style="padding:12px 16px;font-weight:800;font-size:.9rem;cursor:pointer;">➕ Add Sponsor Manually</summary>
            <form method="post" action="sponsors.php" enctype="multipart/form-data" style="padding:0 16px 16px;display:flex;flex-direction:column;gap:10px;max-width:460px;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="add_sponsor" value="1">
                <p class="small-note" style="margin:0;color:var(--text-muted,#6b7280);">For comp'd or partner sponsors — skips payment and goes live immediately. No account needed for the sponsor.</p>
                <div>
                    <label style="font-size:.82rem;font-weight:700;display:block;margin-bottom:3px;">Sponsor / business name</label>
                    <input type="text" name="name" required style="width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid var(--border);border-radius:8px;">
                </div>
                <div>
                    <label style="font-size:.82rem;font-weight:700;display:block;margin-bottom:3px;">Logo</label>
                    <input type="file" name="logo" accept="image/jpeg,image/png,image/webp" required>
                </div>
                <div>
                    <label style="font-size:.82rem;font-weight:700;display:block;margin-bottom:3px;">Website URL <span style="font-weight:400;color:var(--text-muted,#6b7280);">(optional)</span></label>
                    <input type="url" name="website_url" placeholder="https://" style="width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid var(--border);border-radius:8px;">
                </div>
                <div>
                    <label style="font-size:.82rem;font-weight:700;display:block;margin-bottom:3px;">Description <span style="font-weight:400;color:var(--text-muted,#6b7280);">(optional)</span></label>
                    <textarea name="description" rows="2" maxlength="500" style="width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid var(--border);border-radius:8px;"></textarea>
                </div>
                <div style="display:flex;gap:10px;">
                    <div style="flex:1;">
                        <label style="font-size:.82rem;font-weight:700;display:block;margin-bottom:3px;">Contact email <span style="font-weight:400;color:var(--text-muted,#6b7280);">(optional)</span></label>
                        <input type="email" name="contact_email" style="width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid var(--border);border-radius:8px;">
                    </div>
                    <div style="flex:1;">
                        <label style="font-size:.82rem;font-weight:700;display:block;margin-bottom:3px;">Contact phone <span style="font-weight:400;color:var(--text-muted,#6b7280);">(optional)</span></label>
                        <input type="text" name="contact_phone" style="width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid var(--border);border-radius:8px;">
                    </div>
                </div>
                <div style="display:flex;gap:10px;">
                    <div style="flex:1;">
                        <label style="font-size:.82rem;font-weight:700;display:block;margin-bottom:3px;">Package <span style="font-weight:400;color:var(--text-muted,#6b7280);">(optional, for records)</span></label>
                        <select name="package_id" style="width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid var(--border);border-radius:8px;">
                            <option value="">— None —</option>
                            <?php foreach ($sponsorPackages as $pkg): ?>
                            <option value="<?php echo $pkg['id']; ?>"><?php echo sanitize($pkg['name']); ?> (<?php echo (int)$pkg['duration_days']; ?>d)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="flex:1;">
                        <label style="font-size:.82rem;font-weight:700;display:block;margin-bottom:3px;">Duration in days <span style="font-weight:400;color:var(--text-muted,#6b7280);">(blank = never expires)</span></label>
                        <input type="number" name="duration_days" min="1" style="width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid var(--border);border-radius:8px;">
                    </div>
                </div>
                <button type="submit" class="button button-primary" style="align-self:flex-start;">Add Sponsor — Go Live</button>
            </form>
        </details>

        <div class="as-stats">
            <?php if ((int)($counts['pending_approval'] ?? 0) > 0): ?>
            <a href="sponsors.php?status=pending_approval" class="as-stat" style="border-color:#f59e0b;">
                <strong style="color:#f59e0b;"><?php echo (int)($counts['pending_approval'] ?? 0); ?></strong><span>⏳ Awaiting Review</span>
            </a>
            <?php endif; ?>
            <a href="sponsors.php?status=active"          class="as-stat"><strong><?php echo (int)($counts['active'] ?? 0); ?></strong><span>Active</span></a>
            <a href="sponsors.php?status=pending_payment"  class="as-stat"><strong><?php echo (int)($counts['pending_payment'] ?? 0); ?></strong><span>Awaiting Payment</span></a>
            <a href="sponsors.php?status=rejected"         class="as-stat"><strong style="color:#dc2626;"><?php echo (int)($counts['rejected'] ?? 0); ?></strong><span>Rejected</span></a>
            <a href="sponsors.php?status=expired"          class="as-stat"><strong><?php echo (int)($counts['expired'] ?? 0); ?></strong><span>Expired</span></a>
            <div class="as-stat"><strong><?php echo array_sum($counts); ?></strong><span>Total</span></div>
        </div>

        <div class="as-toolbar">
            <form method="get" action="sponsors.php">
                <?php if ($statusFilter): ?><input type="hidden" name="status" value="<?php echo sanitize($statusFilter); ?>"><?php endif; ?>
                <input type="search" name="q" placeholder="Search sponsors…" value="<?php echo sanitize($search); ?>">
                <button class="button button-small">Search</button>
            </form>
            <select onchange="window.location='sponsors.php?status='+this.value+'<?php echo $search ? '&q='.urlencode($search) : ''; ?>'">
                <option value="">All statuses</option>
                <option value="pending_approval" <?php echo $statusFilter==='pending_approval' ? 'selected':'' ?>>⏳ Awaiting Review</option>
                <option value="active"           <?php echo $statusFilter==='active'           ? 'selected':'' ?>>Active</option>
                <option value="pending_payment"  <?php echo $statusFilter==='pending_payment'  ? 'selected':'' ?>>Awaiting Payment</option>
                <option value="rejected"         <?php echo $statusFilter==='rejected'         ? 'selected':'' ?>>Rejected</option>
                <option value="expired"          <?php echo $statusFilter==='expired'          ? 'selected':'' ?>>Expired</option>
            </select>
        </div>

        <div style="overflow-x:auto;background:var(--surface,#fff);border:1px solid var(--border);border-radius:12px;">
            <table class="as-table">
                <thead>
                    <tr>
                        <th>Logo</th>
                        <th>Name</th>
                        <th>Package</th>
                        <th>Website</th>
                        <th>Status</th>
                        <th>Ends</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $sp): $spJson = htmlspecialchars(json_encode($sp, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>
                    <tr>
                        <td>
                            <?php if ($sp['logo_path']): ?>
                            <img src="../<?php echo sanitize($sp['logo_path']); ?>" class="as-logo" alt="">
                            <?php else: ?>
                            <div class="as-logo" style="display:flex;align-items:center;justify-content:center;font-size:1.3rem;">🤝</div>
                            <?php endif; ?>
                        </td>
                        <td><strong><?php echo sanitize($sp['name']); ?></strong></td>
                        <td style="font-size:.8rem;color:var(--text-muted);"><?php echo sanitize($sp['package_name'] ?? '—'); ?></td>
                        <td style="font-size:.8rem;"><?php if ($sp['website_url']): ?><a href="<?php echo sanitize($sp['website_url']); ?>" target="_blank" rel="noopener"><?php echo sanitize(mb_substr($sp['website_url'], 0, 30)); ?></a><?php else: ?>—<?php endif; ?></td>
                        <td><span class="as-badge as-badge-<?php echo $sp['status']; ?>"><?php echo ucwords(str_replace('_', ' ', $sp['status'])); ?></span></td>
                        <td style="font-size:.8rem;"><?php echo $sp['end_date'] ? date('d M Y', strtotime($sp['end_date'])) : '—'; ?></td>
                        <td>
                            <div class="as-actions">
                                <?php if ($sp['status'] === 'pending_approval'): ?>
                                <form method="post" action="sponsors.php"><input type="hidden" name="id" value="<?php echo (int)$sp['id']; ?>"><input type="hidden" name="action" value="approve"><?php echo csrf_field(); ?><button class="button button-small" style="background:#ecfdf5;color:#065f46;border-color:#6ee7b7;">Approve</button></form>
                                <button type="button" class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;" onclick="openRejectModal(<?php echo (int)$sp['id']; ?>)">Reject</button>
                                <?php endif; ?>
                                <?php if ($sp['status'] === 'active'): ?>
                                <button type="button" class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;" onclick="openRejectModal(<?php echo (int)$sp['id']; ?>)">Remove</button>
                                <?php endif; ?>
                                <button type="button" class="button button-small button-secondary" onclick='openEditSponsorModal(<?php echo $spJson; ?>)'>Edit</button>
                                <form method="post" action="sponsors.php" onsubmit="return confirm('Delete this sponsor submission?')"><input type="hidden" name="id" value="<?php echo (int)$sp['id']; ?>"><input type="hidden" name="action" value="delete"><?php echo csrf_field(); ?><button class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Delete</button></form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                    <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text-muted);">No sponsors found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($sponsorsTotalPages > 1): ?>
        <div style="display:flex;gap:8px;justify-content:center;margin-top:16px;flex-wrap:wrap;">
            <?php for ($p = 1; $p <= $sponsorsTotalPages; $p++): ?>
            <a href="sponsors.php?page=<?php echo $p; ?><?php echo $statusFilter ? '&status='.urlencode($statusFilter) : ''; ?><?php echo $search ? '&q='.urlencode($search) : ''; ?>"
               class="button button-small <?php echo $p === $page ? 'button-primary' : 'button-secondary'; ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Reject modal -->
    <div id="reject-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9900;align-items:center;justify-content:center;padding:16px;" onclick="if(event.target===this)closeRejectModal()">
        <div style="background:#fff;border-radius:14px;padding:24px;max-width:460px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
            <h3 style="margin:0 0 6px;font-size:1rem;">Reject / Remove Sponsor</h3>
            <p style="font-size:.85rem;color:#6b7280;margin:0 0 14px;">Optionally explain why. The sponsor will see this.</p>
            <form method="post" action="sponsors.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="id" id="reject-target-id" value="">
                <textarea name="rejection_reason" rows="4" placeholder="e.g. Logo image quality was too low — please resubmit with a higher-resolution logo."
                    style="width:100%;box-sizing:border-box;padding:10px;border:1px solid #d1d5db;border-radius:8px;font-size:.9rem;resize:vertical;margin-bottom:12px;"></textarea>
                <div style="display:flex;gap:8px;">
                    <button type="submit" class="button" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Confirm</button>
                    <button type="button" class="button button-secondary" onclick="closeRejectModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <script>
    function openRejectModal(id) {
        document.getElementById('reject-target-id').value = id;
        var m = document.getElementById('reject-modal');
        m.style.display = 'flex';
        m.querySelector('textarea').value = '';
        m.querySelector('textarea').focus();
    }
    function closeRejectModal() {
        document.getElementById('reject-modal').style.display = 'none';
    }
    </script>

    <!-- Edit Sponsor modal -->
    <div id="edit-sponsor-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9900;align-items:center;justify-content:center;padding:16px;overflow-y:auto;" onclick="if(event.target===this)closeEditSponsorModal()">
        <div style="background:#fff;border-radius:14px;padding:24px;max-width:460px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.3);margin:20px 0;">
            <h3 id="edit-sponsor-title" style="margin:0 0 14px;font-size:1rem;">Edit Sponsor</h3>
            <form method="post" action="sponsors.php" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:10px;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="edit_sponsor">
                <input type="hidden" name="id" id="es_id" value="">
                <div>
                    <label style="font-size:.82rem;font-weight:700;display:block;margin-bottom:3px;">Sponsor / business name</label>
                    <input type="text" name="name" id="es_name" required style="width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid var(--border);border-radius:8px;">
                </div>
                <div>
                    <label style="font-size:.82rem;font-weight:700;display:block;margin-bottom:3px;">Logo <span style="font-weight:400;color:var(--text-muted,#6b7280);">(leave blank to keep the current logo)</span></label>
                    <img id="es_logo_preview" src="" alt="" style="width:56px;height:56px;object-fit:contain;background:#f8fafc;border:1px solid var(--border);border-radius:8px;margin-bottom:6px;display:none;">
                    <input type="file" name="logo" accept="image/jpeg,image/png,image/webp">
                </div>
                <div>
                    <label style="font-size:.82rem;font-weight:700;display:block;margin-bottom:3px;">Website URL <span style="font-weight:400;color:var(--text-muted,#6b7280);">(optional)</span></label>
                    <input type="url" name="website_url" id="es_website_url" placeholder="https://" style="width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid var(--border);border-radius:8px;">
                </div>
                <div>
                    <label style="font-size:.82rem;font-weight:700;display:block;margin-bottom:3px;">Description <span style="font-weight:400;color:var(--text-muted,#6b7280);">(optional)</span></label>
                    <textarea name="description" id="es_description" rows="2" maxlength="500" style="width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid var(--border);border-radius:8px;"></textarea>
                </div>
                <div style="display:flex;gap:10px;">
                    <div style="flex:1;">
                        <label style="font-size:.82rem;font-weight:700;display:block;margin-bottom:3px;">Contact email <span style="font-weight:400;color:var(--text-muted,#6b7280);">(optional)</span></label>
                        <input type="email" name="contact_email" id="es_contact_email" style="width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid var(--border);border-radius:8px;">
                    </div>
                    <div style="flex:1;">
                        <label style="font-size:.82rem;font-weight:700;display:block;margin-bottom:3px;">Contact phone <span style="font-weight:400;color:var(--text-muted,#6b7280);">(optional)</span></label>
                        <input type="text" name="contact_phone" id="es_contact_phone" style="width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid var(--border);border-radius:8px;">
                    </div>
                </div>
                <div style="display:flex;gap:10px;">
                    <div style="flex:1;">
                        <label style="font-size:.82rem;font-weight:700;display:block;margin-bottom:3px;">Package</label>
                        <select name="package_id" id="es_package_id" style="width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid var(--border);border-radius:8px;">
                            <option value="">— None —</option>
                            <?php foreach ($sponsorPackages as $pkg): ?>
                            <option value="<?php echo $pkg['id']; ?>"><?php echo sanitize($pkg['name']); ?> (<?php echo (int)$pkg['duration_days']; ?>d)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="flex:1;">
                        <label style="font-size:.82rem;font-weight:700;display:block;margin-bottom:3px;">Ends on <span style="font-weight:400;color:var(--text-muted,#6b7280);">(blank = never)</span></label>
                        <input type="date" name="end_date" id="es_end_date" style="width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid var(--border);border-radius:8px;">
                    </div>
                </div>
                <div style="display:flex;gap:8px;margin-top:4px;">
                    <button type="submit" class="button button-primary">Save Changes</button>
                    <button type="button" class="button button-secondary" onclick="closeEditSponsorModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <script>
    function openEditSponsorModal(sp) {
        document.getElementById('es_id').value = sp.id;
        document.getElementById('es_name').value = sp.name || '';
        document.getElementById('es_website_url').value = sp.website_url || '';
        document.getElementById('es_description').value = sp.description || '';
        document.getElementById('es_contact_email').value = sp.contact_email || '';
        document.getElementById('es_contact_phone').value = sp.contact_phone || '';
        document.getElementById('es_package_id').value = sp.package_id || '';
        document.getElementById('es_end_date').value = sp.end_date || '';
        document.getElementById('edit-sponsor-title').textContent = 'Edit Sponsor — ' + sp.name;
        var preview = document.getElementById('es_logo_preview');
        if (sp.logo_path) {
            preview.src = '../' + sp.logo_path;
            preview.style.display = '';
        } else {
            preview.style.display = 'none';
        }
        var m = document.getElementById('edit-sponsor-modal');
        m.style.display = 'flex';
        m.querySelector('form').querySelector('input[name="logo"]').value = '';
    }
    function closeEditSponsorModal() {
        document.getElementById('edit-sponsor-modal').style.display = 'none';
    }
    </script>
</body>
</html>
