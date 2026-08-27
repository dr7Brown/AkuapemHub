<?php
/**
 * Accommodation admin — listings approval/verification, type & facility
 * management, and reports queue. Mirrors admin/marketplace.php's ?tab=
 * convention and admin/promotions.php's simple inline-CRUD-form pattern.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../accommodation_functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: index.php'); exit; }
require_mod_permission('manage_accommodation');
$adminUser = current_user();

$tab = in_array($_GET['tab'] ?? '', ['listings','types','facilities','reports'], true) ? $_GET['tab'] : 'listings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action   = $_POST['action'] ?? '';
    $returnTo = ($_POST['return_to'] ?? '') === 'detail' ? true : false;

    // ── Listing moderation ──────────────────────────────────────────────
    if (in_array($action, ['approve_listing','reject_listing','verify_listing','unverify_listing','archive_listing','delete_listing'], true)) {
        $lid = (int)($_POST['listing_id'] ?? 0);
        $row = $pdo->prepare('SELECT al.*, u.id AS owner_id, u.name AS owner_name FROM accommodation_listings al JOIN users u ON al.user_id=u.id WHERE al.id=?');
        $row->execute([$lid]);
        $listing = $row->fetch();

        if ($listing) {
            $redirectTo = $returnTo ? "../accommodation_detail.php?id=$lid" : 'accommodation.php?tab=listings';

            if ($action === 'approve_listing') {
                $pdo->prepare("UPDATE accommodation_listings SET status='approved', rejection_reason=NULL, updated_at=NOW() WHERE id=?")->execute([$lid]);
                notify_user((int)$listing['owner_id'], '✅ Listing Approved', '"'.$listing['title'].'" is now live on Accommodation.', 'success', '../accommodation_detail.php?id='.$lid);
                log_audit_action($adminUser['id'], 'accommodation_approved', "Approved listing #{$lid}: {$listing['title']}");
                log_mod_activity($adminUser['id'], 'accommodation', 'approve_listing', $lid, $listing['title']);
                flash('Listing approved.', 'success');
            } elseif ($action === 'reject_listing') {
                $reason = trim($_POST['rejection_reason'] ?? '');
                $pdo->prepare("UPDATE accommodation_listings SET status='rejected', rejection_reason=?, updated_at=NOW() WHERE id=?")->execute([$reason, $lid]);
                notify_user((int)$listing['owner_id'], 'Listing Rejected', '"'.$listing['title'].'" was rejected. Reason: '.$reason, 'error', '../accommodation_form.php?id='.$lid);
                log_audit_action($adminUser['id'], 'accommodation_rejected', "Rejected listing #{$lid}: {$listing['title']}. Reason: {$reason}");
                flash('Listing rejected.', 'info');
            } elseif ($action === 'verify_listing') {
                $pdo->prepare("UPDATE accommodation_listings SET verification_status='approved', updated_at=NOW() WHERE id=?")->execute([$lid]);
                notify_user((int)$listing['owner_id'], '✓ Listing Verified', '"'.$listing['title'].'" has been verified.', 'success', '../accommodation_detail.php?id='.$lid);
                log_audit_action($adminUser['id'], 'accommodation_verified', "Verified listing #{$lid}: {$listing['title']}");
                flash('Listing verified.', 'success');
            } elseif ($action === 'unverify_listing') {
                $pdo->prepare("UPDATE accommodation_listings SET verification_status='none', updated_at=NOW() WHERE id=?")->execute([$lid]);
                flash('Verification removed.', 'info');
            } elseif ($action === 'archive_listing') {
                $pdo->prepare("UPDATE accommodation_listings SET status='archived', updated_at=NOW() WHERE id=?")->execute([$lid]);
                flash('Listing archived.', 'info');
            } elseif ($action === 'delete_listing') {
                $pdo->prepare('DELETE FROM accommodation_listings WHERE id=?')->execute([$lid]);
                log_audit_action($adminUser['id'], 'accommodation_deleted', "Deleted listing #{$lid}: {$listing['title']}");
                flash('Listing deleted.', 'success');
                $redirectTo = 'accommodation.php?tab=listings'; // detail page no longer exists
            }
            header('Location: ' . $redirectTo); exit;
        }
        header('Location: accommodation.php?tab=listings'); exit;
    }

    // ── Types CRUD ───────────────────────────────────────────────────────
    if ($action === 'save_type') {
        $id       = (int)($_POST['id'] ?? 0);
        $name     = trim($_POST['name'] ?? '');
        $category = in_array($_POST['category'] ?? '', ['room_house','hotel'], true) ? $_POST['category'] : 'room_house';
        $icon     = trim($_POST['icon'] ?? '');
        $status   = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $maxImages = max(1, min(50, (int)($_POST['max_images'] ?? 10)));
        if ($name === '') {
            flash('Type name is required.', 'error');
        } else {
            $slug = mp_unique_slug_fallback($name, 'accommodation_types', 'slug', $pdo, $id);
            if ($id > 0) {
                $pdo->prepare('UPDATE accommodation_types SET name=?, category=?, icon=?, status=?, max_images=? WHERE id=?')->execute([$name, $category, $icon ?: null, $status, $maxImages, $id]);
                flash('Type updated.', 'success');
            } else {
                $pdo->prepare('INSERT INTO accommodation_types (category, name, slug, icon, status, max_images) VALUES (?,?,?,?,?,?)')->execute([$category, $name, $slug, $icon ?: null, $status, $maxImages]);
                flash('Type added.', 'success');
            }
        }
        header('Location: accommodation.php?tab=types'); exit;
    }
    if ($action === 'delete_type') {
        $id = (int)($_POST['id'] ?? 0);
        $inUse = (int)$pdo->query("SELECT COUNT(*) FROM accommodation_listings WHERE accommodation_type_id=$id")->fetchColumn();
        if ($inUse > 0) {
            flash("Can't delete — {$inUse} listing(s) still use this type. Deactivate it instead.", 'error');
        } else {
            $pdo->prepare('DELETE FROM accommodation_types WHERE id=?')->execute([$id]);
            flash('Type deleted.', 'success');
        }
        header('Location: accommodation.php?tab=types'); exit;
    }

    // ── Facilities CRUD ──────────────────────────────────────────────────
    if ($action === 'save_facility') {
        $id     = (int)($_POST['id'] ?? 0);
        $name   = trim($_POST['name'] ?? '');
        $icon   = trim($_POST['icon'] ?? '');
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        if ($name === '') {
            flash('Facility name is required.', 'error');
        } else {
            $slug = mp_unique_slug_fallback($name, 'accommodation_facilities', 'slug', $pdo, $id);
            if ($id > 0) {
                $pdo->prepare('UPDATE accommodation_facilities SET name=?, icon=?, status=? WHERE id=?')->execute([$name, $icon ?: null, $status, $id]);
                flash('Facility updated.', 'success');
            } else {
                $pdo->prepare('INSERT INTO accommodation_facilities (name, slug, icon, status) VALUES (?,?,?,?)')->execute([$name, $slug, $icon ?: null, $status]);
                flash('Facility added.', 'success');
            }
        }
        header('Location: accommodation.php?tab=facilities'); exit;
    }
    if ($action === 'delete_facility') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM accommodation_facilities WHERE id=?')->execute([$id]);
        flash('Facility deleted.', 'success');
        header('Location: accommodation.php?tab=facilities'); exit;
    }

    // ── Reports ──────────────────────────────────────────────────────────
    if (in_array($action, ['resolve_report','dismiss_report'], true)) {
        $rid = (int)($_POST['report_id'] ?? 0);
        $newStatus = $action === 'resolve_report' ? 'reviewed' : 'dismissed';
        $pdo->prepare('UPDATE accommodation_reports SET status=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?')->execute([$newStatus, $adminUser['id'], $rid]);
        flash('Report updated.', 'success');
        header('Location: accommodation.php?tab=reports'); exit;
    }
}

/** Local slug-uniqueness helper — same shape as marketplace_functions.php's
 *  mp_unique_slug() but kept local so this admin page has no marketplace
 *  dependency for a two-line lookup table CRUD. */
function mp_unique_slug_fallback(string $base, string $table, string $column, PDO $pdo, int $excludeId = 0): string {
    $slug = trim(preg_replace('/[\s-]+/', '-', preg_replace('/[^a-z0-9\s-]/', '', strtolower(trim($base)))), '-') ?: 'item';
    $try = $slug; $i = 0;
    do {
        $st = $pdo->prepare("SELECT id FROM $table WHERE $column = ? AND id != ?");
        $st->execute([$try, $excludeId]);
        if (!$st->fetchColumn()) break;
        $try = $slug . '-' . (++$i);
    } while (true);
    return $try;
}

// ── Data for each tab ────────────────────────────────────────────────────
$statusFilter = $_GET['sf'] ?? 'pending_approval';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$listings = $types = $facilities = $reports = [];
$total = $totalPages = 1;

if ($tab === 'listings') {
    $lWhere = [];
    $lParams = [];
    if ($statusFilter !== 'all') { $lWhere[] = 'al.status = ?'; $lParams[] = $statusFilter; }
    $lWhereClause = $lWhere ? 'WHERE ' . implode(' AND ', $lWhere) : '';

    $countSt = $pdo->prepare("SELECT COUNT(*) FROM accommodation_listings al $lWhereClause");
    $countSt->execute($lParams);
    $total = (int)$countSt->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));

    $lSt = $pdo->prepare(
        "SELECT al.*, at.name AS type_name, u.name AS owner_name
         FROM accommodation_listings al
         JOIN accommodation_types at ON al.accommodation_type_id = at.id
         JOIN users u ON al.user_id = u.id
         $lWhereClause
         ORDER BY FIELD(al.status,'pending_approval','approved','draft','rejected','archived'), al.created_at DESC
         LIMIT $perPage OFFSET $offset"
    );
    $lSt->execute($lParams);
    $listings = $lSt->fetchAll();
} elseif ($tab === 'types') {
    $types = $pdo->query('SELECT * FROM accommodation_types ORDER BY category, sort_order, name')->fetchAll();
} elseif ($tab === 'facilities') {
    $facilities = $pdo->query('SELECT * FROM accommodation_facilities ORDER BY sort_order, name')->fetchAll();
} elseif ($tab === 'reports') {
    $reports = $pdo->query(
        "SELECT ar.*, al.title AS listing_title, u.name AS reporter_name
         FROM accommodation_reports ar
         JOIN accommodation_listings al ON ar.listing_id = al.id
         JOIN users u ON ar.reporter_id = u.id
         ORDER BY FIELD(ar.status,'pending','reviewed','dismissed'), ar.created_at DESC LIMIT 100"
    )->fetchAll();
}

$pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM accommodation_listings WHERE status='pending_approval'")->fetchColumn();
$activeCount  = (int)$pdo->query("SELECT COUNT(*) FROM accommodation_listings WHERE status='approved'")->fetchColumn();
$reportCount  = (int)$pdo->query("SELECT COUNT(*) FROM accommodation_reports WHERE status='pending'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accommodation — Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .aa-shell  { max-width:1100px; margin:0 auto; padding:20px 16px 60px; }
        .aa-tabs   { display:flex; gap:6px; margin-bottom:16px; flex-wrap:wrap; }
        .aa-tab    { padding:7px 14px; border-radius:20px; border:1px solid var(--border); font-size:.82rem; font-weight:700; text-decoration:none; color:var(--text-muted,#6b7280); }
        .aa-tab.active { background:var(--primary-soft,#d1fae5); border-color:var(--primary,#0f766e); color:var(--primary,#0f766e); }
        .aa-stats  { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:16px; }
        .aa-stat   { background:var(--surface,#fff); border:1px solid var(--border); border-radius:10px; padding:10px 16px; text-align:center; min-width:110px; }
        .aa-stat strong { display:block; font-size:1.3rem; }
        .aa-stat span   { font-size:.74rem; color:var(--text-muted); }
        .aa-table  { width:100%; border-collapse:collapse; font-size:.85rem; }
        .aa-table th { background:var(--surface-muted,#f9fafb); padding:9px 12px; text-align:left; font-size:.75rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--text-muted); border-bottom:1px solid var(--border); }
        .aa-table td { padding:10px 12px; border-bottom:1px solid var(--border,#e5e7eb); vertical-align:middle; }
        .aa-badge  { display:inline-block; font-size:.65rem; font-weight:800; padding:2px 8px; border-radius:20px; }
        .aa-actions { display:flex; gap:5px; flex-wrap:wrap; }
        .aa-filter { display:flex; gap:6px; margin-bottom:14px; flex-wrap:wrap; }
        .aa-filter a { padding:5px 12px; border-radius:16px; border:1px solid var(--border); font-size:.78rem; text-decoration:none; color:var(--text-muted,#6b7280); }
        .aa-filter a.active { background:var(--primary,#0f766e); color:#fff; border-color:var(--primary,#0f766e); }
        .aa-form-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:10px; align-items:end; }
        .aa-form-grid label { font-weight:600; font-size:.8rem; display:block; margin-bottom:4px; }
        .aa-form-grid input, .aa-form-grid select { width:100%; padding:6px 8px; border:1px solid var(--border); border-radius:8px; font-size:.82rem; box-sizing:border-box; }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="index.php" class="button button-secondary button-small">← Admin</a>
        <h1>🏠 Accommodation</h1>
        <a href="../accommodation.php" target="_blank" rel="noopener" class="button button-primary button-small">View Live</a>
    </header>

    <div class="aa-shell">
        <?php if ($flash = get_flash()): ?><div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin-bottom:12px;"><?php echo sanitize($flash['message']); ?></div><?php endif; ?>

        <div class="aa-tabs">
            <a href="?tab=listings" class="aa-tab <?php echo $tab==='listings'?'active':''; ?>">Listings<?php if($pendingCount): ?> (<?php echo $pendingCount; ?>)<?php endif; ?></a>
            <a href="?tab=types" class="aa-tab <?php echo $tab==='types'?'active':''; ?>">Types</a>
            <a href="?tab=facilities" class="aa-tab <?php echo $tab==='facilities'?'active':''; ?>">Facilities</a>
            <a href="?tab=reports" class="aa-tab <?php echo $tab==='reports'?'active':''; ?>">Reports<?php if($reportCount): ?> (<?php echo $reportCount; ?>)<?php endif; ?></a>
        </div>

        <div class="aa-stats">
            <div class="aa-stat"><strong><?php echo $activeCount; ?></strong><span>Active Listings</span></div>
            <div class="aa-stat"><strong><?php echo $pendingCount; ?></strong><span>Pending Review</span></div>
            <div class="aa-stat"><strong><?php echo $reportCount; ?></strong><span>Open Reports</span></div>
        </div>

        <?php if ($tab === 'listings'): ?>
        <div class="aa-filter">
            <?php foreach (['pending_approval'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','draft'=>'Draft','archived'=>'Archived','all'=>'All'] as $v=>$l): ?>
            <a href="?tab=listings&sf=<?php echo $v; ?>" class="<?php echo $statusFilter===$v?'active':''; ?>"><?php echo $l; ?></a>
            <?php endforeach; ?>
        </div>
        <div style="overflow-x:auto;background:var(--surface,#fff);border:1px solid var(--border);border-radius:12px;">
            <table class="aa-table">
                <thead><tr><th>Listing</th><th>Type</th><th>Owner</th><th>Status</th><th>Verified</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($listings as $l): ?>
                <tr>
                    <td><a href="../accommodation_detail.php?id=<?php echo $l['id']; ?>" target="_blank"><?php echo sanitize($l['title']); ?></a></td>
                    <td style="font-size:.8rem;"><?php echo sanitize($l['type_name']); ?></td>
                    <td style="font-size:.8rem;"><?php echo sanitize($l['owner_name']); ?></td>
                    <td><span class="aa-badge" style="background:<?php echo accommodation_status_color($l['status']); ?>22;color:<?php echo accommodation_status_color($l['status']); ?>;"><?php echo accommodation_status_label($l['status']); ?></span></td>
                    <td>
                        <?php if ($l['verification_status']==='approved'): ?><span style="color:#10b981;">✓ Verified</span>
                        <?php else: ?><span style="color:var(--text-muted,#6b7280);">—</span><?php endif; ?>
                    </td>
                    <td>
                        <div class="aa-actions">
                            <?php if ($l['status']==='pending_approval'): ?>
                            <form method="post" class="inline-form"><?php echo csrf_field(); ?><input type="hidden" name="action" value="approve_listing"><input type="hidden" name="listing_id" value="<?php echo $l['id']; ?>"><button class="button button-small button-primary">Approve</button></form>
                            <form method="post" class="inline-form" style="display:flex;gap:4px;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="reject_listing"><input type="hidden" name="listing_id" value="<?php echo $l['id']; ?>"><input type="text" name="rejection_reason" placeholder="Reason" required style="font-size:.75rem;padding:3px 6px;width:100px;"><button class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Reject</button></form>
                            <?php endif; ?>
                            <?php if ($l['status']==='approved' && $l['verification_status']!=='approved'): ?>
                            <form method="post" class="inline-form"><?php echo csrf_field(); ?><input type="hidden" name="action" value="verify_listing"><input type="hidden" name="listing_id" value="<?php echo $l['id']; ?>"><button class="button button-small button-secondary">Verify</button></form>
                            <?php elseif ($l['verification_status']==='approved'): ?>
                            <form method="post" class="inline-form"><?php echo csrf_field(); ?><input type="hidden" name="action" value="unverify_listing"><input type="hidden" name="listing_id" value="<?php echo $l['id']; ?>"><button class="button button-small button-secondary">Unverify</button></form>
                            <?php endif; ?>
                            <?php if ($l['status']==='approved'): ?>
                            <form method="post" class="inline-form"><?php echo csrf_field(); ?><input type="hidden" name="action" value="archive_listing"><input type="hidden" name="listing_id" value="<?php echo $l['id']; ?>"><button class="button button-small button-secondary">Archive</button></form>
                            <?php endif; ?>
                            <form method="post" class="inline-form" onsubmit="return confirm('Delete this listing permanently?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_listing"><input type="hidden" name="listing_id" value="<?php echo $l['id']; ?>"><button class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Delete</button></form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$listings): ?><tr><td colspan="6" style="text-align:center;padding:32px;color:var(--text-muted);">No listings.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
        <div style="display:flex;gap:8px;justify-content:center;margin-top:16px;flex-wrap:wrap;">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a href="?tab=listings&sf=<?php echo $statusFilter; ?>&page=<?php echo $p; ?>" class="button button-small <?php echo $p===$page?'button-primary':'button-secondary'; ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

        <?php elseif ($tab === 'types'): ?>
        <div style="overflow-x:auto;background:var(--surface,#fff);border:1px solid var(--border);border-radius:12px;margin-bottom:20px;">
            <table class="aa-table">
                <thead><tr><th>Icon</th><th>Name</th><th>Category</th><th>Max Photos</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($types as $t): $tJson = htmlspecialchars(json_encode($t), ENT_QUOTES, 'UTF-8'); ?>
                <tr>
                    <td><?php echo $t['icon']; ?></td>
                    <td><strong><?php echo sanitize($t['name']); ?></strong></td>
                    <td style="font-size:.8rem;"><?php echo $t['category']==='hotel'?'Hotel/Guest House':'Room/House'; ?></td>
                    <td style="font-size:.8rem;"><?php echo (int)$t['max_images']; ?></td>
                    <td><span class="aa-badge" style="background:<?php echo $t['status']==='active'?'#ecfdf5':'#f3f4f6'; ?>;color:<?php echo $t['status']==='active'?'#065f46':'#6b7280'; ?>;"><?php echo ucfirst($t['status']); ?></span></td>
                    <td class="aa-actions">
                        <button type="button" class="button button-small button-secondary" onclick='editType(<?php echo $tJson; ?>)'>Edit</button>
                        <form method="post" class="inline-form" onsubmit="return confirm('Delete this type?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_type"><input type="hidden" name="id" value="<?php echo $t['id']; ?>"><button class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Delete</button></form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="background:var(--surface,#fff);border:1px solid var(--border);border-radius:12px;padding:18px;">
            <h3 id="type-form-title" style="margin:0 0 14px;">Add Type</h3>
            <form method="post" class="aa-form-grid">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_type">
                <input type="hidden" name="id" id="type_id" value="0">
                <div><label>Name</label><input type="text" name="name" id="type_name" required></div>
                <div><label>Category</label><select name="category" id="type_category"><option value="room_house">Room/House</option><option value="hotel">Hotel/Guest House</option></select></div>
                <div><label>Icon (emoji)</label><input type="text" name="icon" id="type_icon" maxlength="10"></div>
                <div><label>Max Photos</label><input type="number" name="max_images" id="type_max_images" min="1" max="50" value="10"></div>
                <div><label>Status</label><select name="status" id="type_status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                <div><button type="submit" class="button button-primary">Save</button> <button type="button" class="button button-secondary" onclick="resetTypeForm()">Clear</button></div>
            </form>
        </div>
        <script>
        function editType(t) {
            document.getElementById('type_id').value = t.id;
            document.getElementById('type_name').value = t.name;
            document.getElementById('type_category').value = t.category;
            document.getElementById('type_icon').value = t.icon || '';
            document.getElementById('type_max_images').value = t.max_images || 10;
            document.getElementById('type_status').value = t.status;
            document.getElementById('type-form-title').textContent = 'Edit Type — ' + t.name;
        }
        function resetTypeForm() {
            document.getElementById('type_id').value = 0;
            document.getElementById('type_name').value = '';
            document.getElementById('type_icon').value = '';
            document.getElementById('type_max_images').value = 10;
            document.getElementById('type-form-title').textContent = 'Add Type';
        }
        </script>

        <?php elseif ($tab === 'facilities'): ?>
        <div style="overflow-x:auto;background:var(--surface,#fff);border:1px solid var(--border);border-radius:12px;margin-bottom:20px;">
            <table class="aa-table">
                <thead><tr><th>Icon</th><th>Name</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($facilities as $f): $fJson = htmlspecialchars(json_encode($f), ENT_QUOTES, 'UTF-8'); ?>
                <tr>
                    <td><?php echo $f['icon']; ?></td>
                    <td><strong><?php echo sanitize($f['name']); ?></strong></td>
                    <td><span class="aa-badge" style="background:<?php echo $f['status']==='active'?'#ecfdf5':'#f3f4f6'; ?>;color:<?php echo $f['status']==='active'?'#065f46':'#6b7280'; ?>;"><?php echo ucfirst($f['status']); ?></span></td>
                    <td class="aa-actions">
                        <button type="button" class="button button-small button-secondary" onclick='editFacility(<?php echo $fJson; ?>)'>Edit</button>
                        <form method="post" class="inline-form" onsubmit="return confirm('Delete this facility?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_facility"><input type="hidden" name="id" value="<?php echo $f['id']; ?>"><button class="button button-small" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">Delete</button></form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="background:var(--surface,#fff);border:1px solid var(--border);border-radius:12px;padding:18px;">
            <h3 id="facility-form-title" style="margin:0 0 14px;">Add Facility</h3>
            <form method="post" class="aa-form-grid">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_facility">
                <input type="hidden" name="id" id="facility_id" value="0">
                <div><label>Name</label><input type="text" name="name" id="facility_name" required></div>
                <div><label>Icon (emoji)</label><input type="text" name="icon" id="facility_icon" maxlength="10"></div>
                <div><label>Status</label><select name="status" id="facility_status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                <div><button type="submit" class="button button-primary">Save</button> <button type="button" class="button button-secondary" onclick="resetFacilityForm()">Clear</button></div>
            </form>
        </div>
        <script>
        function editFacility(f) {
            document.getElementById('facility_id').value = f.id;
            document.getElementById('facility_name').value = f.name;
            document.getElementById('facility_icon').value = f.icon || '';
            document.getElementById('facility_status').value = f.status;
            document.getElementById('facility-form-title').textContent = 'Edit Facility — ' + f.name;
        }
        function resetFacilityForm() {
            document.getElementById('facility_id').value = 0;
            document.getElementById('facility_name').value = '';
            document.getElementById('facility_icon').value = '';
            document.getElementById('facility-form-title').textContent = 'Add Facility';
        }
        </script>

        <?php elseif ($tab === 'reports'): ?>
        <div style="overflow-x:auto;background:var(--surface,#fff);border:1px solid var(--border);border-radius:12px;">
            <table class="aa-table">
                <thead><tr><th>Listing</th><th>Reported By</th><th>Reason</th><th>Details</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($reports as $r): ?>
                <tr>
                    <td><a href="../accommodation_detail.php?id=<?php echo $r['listing_id']; ?>" target="_blank"><?php echo sanitize($r['listing_title']); ?></a></td>
                    <td style="font-size:.8rem;"><?php echo sanitize($r['reporter_name']); ?></td>
                    <td style="font-size:.8rem;"><?php echo sanitize(ucfirst(str_replace('_',' ',$r['reason']))); ?></td>
                    <td style="font-size:.78rem;max-width:220px;"><?php echo sanitize(mb_substr($r['details'] ?? '', 0, 120)); ?></td>
                    <td><span class="aa-badge" style="background:<?php echo $r['status']==='pending'?'#fef3c7':'#f3f4f6'; ?>;color:<?php echo $r['status']==='pending'?'#b45309':'#6b7280'; ?>;"><?php echo ucfirst($r['status']); ?></span></td>
                    <td class="aa-actions">
                        <?php if ($r['status']==='pending'): ?>
                        <form method="post" class="inline-form"><?php echo csrf_field(); ?><input type="hidden" name="action" value="resolve_report"><input type="hidden" name="report_id" value="<?php echo $r['id']; ?>"><button class="button button-small button-primary">Mark Reviewed</button></form>
                        <form method="post" class="inline-form"><?php echo csrf_field(); ?><input type="hidden" name="action" value="dismiss_report"><input type="hidden" name="report_id" value="<?php echo $r['id']; ?>"><button class="button button-small button-secondary">Dismiss</button></form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$reports): ?><tr><td colspan="6" style="text-align:center;padding:32px;color:var(--text-muted);">No reports.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
