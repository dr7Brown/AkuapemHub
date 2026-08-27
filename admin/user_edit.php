<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin_or_manager()) { header('Location: ../jobs.php'); exit; }
require_mod_permission('manage_users');

$adminUser = current_user();
$targetId  = (int)($_GET['id'] ?? 0);
if (!$targetId) { header('Location: users.php'); exit; }

// Load target user
$userStmt = $pdo->prepare('SELECT * FROM users WHERE id=?');
$userStmt->execute([$targetId]);
$target = $userStmt->fetch();
if (!$target) { flash('User not found.', 'error'); header('Location: users.php'); exit; }

$flash = get_flash();
$error = '';

// ── POST actions ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // Protect admins from being edited by managers
    if ($target['role'] === 'admin' && !is_admin()) {
        flash('You cannot manage administrator accounts.', 'error');
        header('Location: user_edit.php?id=' . $targetId); exit;
    }

    // Edit profile
    if ($action === 'edit_profile') {
        $name     = trim($_POST['name']     ?? '');
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email']    ?? '');
        $phone    = trim($_POST['phone']    ?? '');

        if (!$name)   $error = 'Name is required.';
        elseif (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Valid email is required.';
        elseif ($username && !preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) $error = 'Username must be 3–30 chars: letters, numbers, underscores.';

        if (!$error) {
            // Check uniqueness
            if ($email !== $target['email']) {
                $chk = $pdo->prepare('SELECT id FROM users WHERE email=? AND id!=?');
                $chk->execute([$email,$targetId]);
                if ($chk->fetch()) $error = 'Email already used by another account.';
            }
            if (!$error && $username && $username !== ($target['username']??'')) {
                $chk = $pdo->prepare('SELECT id FROM users WHERE username=? AND id!=?');
                $chk->execute([$username,$targetId]);
                if ($chk->fetch()) $error = 'Username already taken.';
            }
            if (!$error && $phone && $phone !== ($target['phone']??'')) {
                $chk = $pdo->prepare('SELECT id FROM users WHERE phone=? AND id!=?');
                $chk->execute([$phone,$targetId]);
                if ($chk->fetch()) $error = 'Phone number already used by another account.';
            }
        }

        if (!$error) {
            $pdo->prepare('UPDATE users SET name=?,username=?,email=?,phone=? WHERE id=?')
                ->execute([$name, $username?:null, $email, $phone?:null, $targetId]);
            log_audit_action($adminUser['id'], 'user_edit_profile', "Edited profile for {$target['name']} (#{$targetId})");
            flash('Profile updated.', 'success');
            header('Location: user_edit.php?id=' . $targetId); exit;
        }
    }

    // Change role
    if ($action === 'change_role') {
        $newRole = $_POST['role'] ?? '';
        $validRoles = is_admin() ? ['customer','worker','manager','admin'] : ['customer','worker','manager'];
        if (in_array($newRole, $validRoles, true) && $newRole !== $target['role']) {
            $pdo->prepare('UPDATE users SET role=? WHERE id=?')->execute([$newRole, $targetId]);
            if ($newRole !== 'manager') revoke_all_mod_permissions($targetId);

            // Promoting straight to worker bypasses become_worker.php, so make
            // sure a worker_profiles row exists — otherwise the user is stuck
            // seeing "no worker profile yet" with no way to create one (they
            // can't reach become_worker.php since it requires role=customer).
            if ($newRole === 'worker') {
                $hasProfile = $pdo->prepare('SELECT id FROM worker_profiles WHERE user_id=?');
                $hasProfile->execute([$targetId]);
                if (!$hasProfile->fetchColumn()) {
                    $pdo->prepare(
                        'INSERT INTO worker_profiles (user_id, bio, location, contact_phone, availability, created_at)
                         VALUES (?, ?, ?, ?, ?, NOW())'
                    )->execute([$targetId, '', get_user_town_display($target) ?: '', $target['phone'] ?: '', 'available']);
                }
            }

            log_audit_action($adminUser['id'], 'user_change_role', "Changed role of {$target['name']} (#{$targetId}) from {$target['role']} to $newRole");
            flash('Role changed to ' . ucfirst($newRole) . '.', 'success');
        }
        header('Location: user_edit.php?id=' . $targetId); exit;
    }

    // Manage Restrictions — whole-system ban and/or specific feature bans
    if ($action === 'set_ban') {
        $wholeSystem = isset($_POST['whole_system']);
        $features    = (array)($_POST['features'] ?? []);
        $reason      = trim($_POST['reason'] ?? '');
        $result = apply_feature_ban_update($targetId, $adminUser['id'], $wholeSystem, $features, $reason ?: null);

        $summary = [];
        if ($result['whole_changed']) $summary[] = $wholeSystem ? 'whole-system ban applied' : 'whole-system ban lifted';
        if ($result['granted']) $summary[] = count($result['granted']) . ' restriction(s) added';
        if ($result['revoked']) $summary[] = count($result['revoked']) . ' restriction(s) lifted';
        flash($summary ? implode(', ', $summary) . '.' : 'No changes.', 'info');
        header('Location: user_edit.php?id=' . $targetId); exit;
    }

    // Verify email manually
    if ($action === 'verify_email') {
        $pdo->prepare('UPDATE users SET email_verified=1 WHERE id=?')->execute([$targetId]);
        log_audit_action($adminUser['id'], 'user_verify_email', "Manually verified email for {$target['name']} (#{$targetId})");
        flash('Email marked as verified.', 'success');
        header('Location: user_edit.php?id=' . $targetId); exit;
    }

    // Send password reset
    if ($action === 'send_password_reset') {
        // Expire any existing OTPs and send a fresh reset link
        $pdo->prepare("UPDATE password_reset_otps SET status='expired' WHERE user_id=?")->execute([$targetId]);
        notify_user($targetId, 'Password Reset Requested',
            'An admin has requested a password reset for your account. Use the Forgot Password link to set a new password.',
            'warning');
        log_audit_action($adminUser['id'], 'user_password_reset', "Sent password reset notification to {$target['name']} (#{$targetId})");
        flash('Password reset notification sent.', 'success');
        header('Location: user_edit.php?id=' . $targetId); exit;
    }

    // Force set new password (admin sets it directly)
    if ($action === 'set_password' && is_admin()) {
        $newPwd = $_POST['new_password'] ?? '';
        if (strlen($newPwd) < 6) {
            $error = 'Password must be at least 6 characters.';
        } else {
            $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')
                ->execute([password_hash($newPwd, PASSWORD_DEFAULT), $targetId]);
            notify_user($targetId, 'Password Changed by Admin',
                'An administrator has changed your account password. If this wasn\'t you, contact support immediately.', 'warning');
            log_audit_action($adminUser['id'], 'user_set_password', "Admin set new password for {$target['name']} (#{$targetId})");
            flash('Password changed successfully.', 'success');
            header('Location: user_edit.php?id=' . $targetId); exit;
        }
    }

    // Send a custom notification (optionally also by email)
    if ($action === 'send_notification') {
        $title    = trim($_POST['notif_title'] ?? '');
        $body     = trim($_POST['notif_body']  ?? '');
        $type     = $_POST['notif_type'] ?? 'info';
        $alsoMail = isset($_POST['notif_email']);
        $validTypes = ['info','success','warning','error'];
        if (!in_array($type, $validTypes, true)) $type = 'info';

        if (!$title || !$body) {
            $error = 'Title and message are required.';
        } else {
            notify_user($targetId, $title, $body, $type, null, $adminUser['id']);
            if ($alsoMail && $target['email']) {
                send_email_notification($target['email'], $title, render_rich($body), $targetId);
            }
            log_audit_action($adminUser['id'], 'user_notify', "Sent notification to {$target['name']} (#{$targetId}): \"$title\"");
            flash('Notification sent.', 'success');
            header('Location: user_edit.php?id=' . $targetId); exit;
        }
    }

    // Chat restrictions
    if ($action === 'chat_restrict') {
        $canSend    = isset($_POST['can_send'])    ? 1 : 0;
        $canReceive = isset($_POST['can_receive'])  ? 1 : 0;
        $banUntil   = $_POST['chat_ban_until'] ?? '';
        $pdo->prepare('UPDATE users SET can_send_messages=?,can_receive_messages=?,chat_ban_until=? WHERE id=?')
            ->execute([$canSend, $canReceive, $banUntil ?: null, $targetId]);
        log_audit_action($adminUser['id'], 'user_chat_restrict', "Updated chat restrictions for {$target['name']} (#{$targetId})");
        flash('Chat restrictions updated.', 'success');
        header('Location: user_edit.php?id=' . $targetId); exit;
    }

    // Delete account
    if ($action === 'delete_account' && is_admin()) {
        if ($target['role'] === 'admin') {
            flash('Cannot delete an admin account.', 'error');
        } else {
            $name = $target['name'];
            $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$targetId]);
            log_audit_action($adminUser['id'], 'user_delete', "Deleted account: $name (#{$targetId})");
            flash("Account of $name has been permanently deleted.", 'info');
            header('Location: users.php'); exit;
        }
        header('Location: user_edit.php?id=' . $targetId); exit;
    }

    // Log in as this user — silent, audit-logged impersonation for support/moderation
    if ($action === 'login_as_user' && is_admin()) {
        if ($target['role'] === 'admin') {
            flash('Cannot impersonate another admin.', 'error');
            header('Location: user_edit.php?id=' . $targetId); exit;
        }
        log_audit_action($adminUser['id'], 'user_impersonation_start', "Logged in as {$target['name']} (#{$targetId})");
        session_regenerate_id(true);
        $_SESSION['impersonator_admin_id']   = $adminUser['id'];
        $_SESSION['impersonator_admin_name'] = $adminUser['name'];
        $_SESSION['impersonator_return_id']  = $targetId; // so "Exit to Admin" lands back on this user's page
        unset($target['password_hash']);
        $_SESSION['user'] = $target;
        $_SESSION['session_started_at'] = time();
        unset($_SESSION['_pw_check_at']);
        header('Location: ../index.php'); exit;
    }

    // Complimentary membership — grant/revoke. This one-click button always
    // grants 'full' access; for picking specific paid features instead, use
    // admin/complimentary_members.php's Grant/Edit modal.
    if ($action === 'grant_complimentary' && is_admin()) {
        grant_complimentary_features($targetId, ['full'], $adminUser['id']);
        log_audit_action($adminUser['id'], 'complimentary_granted', "Granted complimentary membership (full access) to {$target['name']} (#{$targetId})");
        notify_user($targetId, '⭐ Complimentary Membership Granted', 'You now have free access to every paid feature on the platform.', 'success');
        flash('Complimentary membership granted.', 'success');
        header('Location: user_edit.php?id=' . $targetId); exit;
    }
    if ($action === 'revoke_complimentary' && is_admin()) {
        revoke_complimentary_access($targetId);
        log_audit_action($adminUser['id'], 'complimentary_revoked', "Revoked complimentary membership from {$target['name']} (#{$targetId})");
        notify_user($targetId, 'Complimentary Membership Ended', 'Your complimentary membership has ended. Paid features now require payment as normal.', 'info');
        flash('Complimentary membership revoked.', 'success');
        header('Location: user_edit.php?id=' . $targetId); exit;
    }
}

// Reload fresh data after any POST
$userStmt->execute([$targetId]);
$target = $userStmt->fetch();

// Complimentary grant keys, for an accurate "what does this cover" message.
$targetGrantKeys = [];
if (!empty($target['is_complimentary'])) {
    $cgStmt = $pdo->prepare('SELECT feature_key FROM complimentary_grants WHERE user_id=?');
    $cgStmt->execute([$targetId]);
    $targetGrantKeys = $cgStmt->fetchAll(PDO::FETCH_COLUMN);
}

// ── Stats ─────────────────────────────────────────────────────────────────────
$jobsPosted      = (int)$pdo->prepare('SELECT COUNT(*) FROM service_requests WHERE customer_id=?')->execute([$targetId]) ?
    (function() use ($pdo,$targetId){ $s=$pdo->prepare('SELECT COUNT(*) FROM service_requests WHERE customer_id=?');$s->execute([$targetId]);return(int)$s->fetchColumn(); })() : 0;
// cleaner:
$s = $pdo->prepare('SELECT COUNT(*) FROM service_requests WHERE customer_id=?'); $s->execute([$targetId]); $jobsPosted=(int)$s->fetchColumn();
$s = $pdo->prepare('SELECT COUNT(*) FROM applications WHERE worker_id=?'); $s->execute([$targetId]); $appsSubmitted=(int)$s->fetchColumn();
$s = $pdo->prepare('SELECT COUNT(*) FROM service_requests WHERE customer_id=? AND status="completed"'); $s->execute([$targetId]); $jobsCompleted=(int)$s->fetchColumn();
$s = $pdo->prepare('SELECT COUNT(*) FROM mp_orders WHERE customer_id=?'); $s->execute([$targetId]); $mpOrders=(int)$s->fetchColumn();

// Worker profile
$wpStmt = $pdo->prepare('SELECT * FROM worker_profiles WHERE user_id=?');
$wpStmt->execute([$targetId]);
$workerProfile = $wpStmt->fetch() ?: null;

// Delivery agent
$daStmt = $pdo->prepare('SELECT * FROM delivery_agents WHERE user_id=?');
$daStmt->execute([$targetId]);
$deliveryAgent = null;
try { $daStmt->execute([$targetId]); $deliveryAgent = $daStmt->fetch() ?: null; } catch(Exception $e){}

// Shops — a seller may have more than one (regular + one per market)
$shops = [];
try {
    $shopStmt = $pdo->prepare(
        'SELECT ms.id, ms.shop_name, ms.status, ms.rating, ms.total_sales, mk.name AS market_name
         FROM mp_shops ms LEFT JOIN markets mk ON ms.market_id = mk.id
         WHERE ms.user_id=? ORDER BY (ms.market_id IS NULL) DESC, ms.shop_name'
    );
    $shopStmt->execute([$targetId]);
    $shops = $shopStmt->fetchAll();
} catch(Exception $e){}

// Recent audit activity by this user
$actStmt = $pdo->prepare('SELECT action, description, created_at FROM audit_logs WHERE admin_id=? ORDER BY created_at DESC LIMIT 10');
$actStmt->execute([$targetId]);
$recentActivity = $actStmt->fetchAll();

// Recent jobs
$recentJobs = $pdo->prepare('SELECT id, title, status, created_at FROM service_requests WHERE customer_id=? ORDER BY created_at DESC LIMIT 5');
$recentJobs->execute([$targetId]);
$recentJobs = $recentJobs->fetchAll();

$roleColors = ['admin'=>['#7c3aed','#ede9fe'],'manager'=>['#0891b2','#cffafe'],'worker'=>['#059669','#d1fae5'],'customer'=>['#6b7280','#f3f4f6']];
[$rCol,$rBg] = $roleColors[$target['role']] ?? ['#6b7280','#f3f4f6'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo sanitize($target['name']); ?> — User Management</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .ue-shell { max-width:960px; margin:0 auto; padding:18px 16px 60px; }
        .ue-layout { display:grid; grid-template-columns:280px 1fr; gap:20px; }
        @media(max-width:700px){ .ue-layout { grid-template-columns:1fr; } }

        /* Left card */
        .ue-profile-card { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:20px; }
        .ue-av-lg { width:72px; height:72px; border-radius:50%; background:var(--primary-soft,#d1fae5); display:flex; align-items:center; justify-content:center; font-size:1.8rem; font-weight:900; color:var(--primary,#0f766e); margin:0 auto 12px; overflow:hidden; border:3px solid var(--border); }
        .ue-av-lg img { width:100%; height:100%; object-fit:cover; }
        .ue-name { font-size:1rem; font-weight:800; text-align:center; }
        .ue-meta { font-size:.76rem; color:var(--text-muted,#6b7280); text-align:center; margin-bottom:14px; line-height:1.7; }
        .ue-divider { border:none; border-top:1px solid var(--border); margin:14px 0; }
        .ue-info-row { display:flex; justify-content:space-between; font-size:.82rem; padding:4px 0; }
        .ue-info-row span:first-child { color:var(--text-muted,#6b7280); }
        .ue-info-row span:last-child  { font-weight:600; text-align:right; word-break:break-all; }

        /* Right sections */
        .ue-section { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:14px; }
        .ue-section-title { font-size:.74rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted,#6b7280); margin:0 0 14px; }
        label { font-weight:600; font-size:.86rem; display:block; margin-bottom:4px; }
        .form-group { margin-bottom:12px; }
        .form-hint  { font-size:.74rem; color:var(--text-muted,#6b7280); margin-top:3px; }
        .ue-grid2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        @media(max-width:480px){ .ue-grid2 { grid-template-columns:1fr; } }

        /* Stats */
        .ue-stats { display:grid; grid-template-columns:repeat(auto-fill,minmax(100px,1fr)); gap:10px; margin-bottom:14px; }
        .ue-stat  { background:var(--surface-muted,#f8fafc); border:1px solid var(--border); border-radius:10px; padding:10px; text-align:center; }
        .ue-stat strong { display:block; font-size:1.3rem; font-weight:900; color:var(--primary,#0f766e); }
        .ue-stat span   { font-size:.7rem; color:var(--text-muted,#6b7280); }

        /* Danger zone */
        .ue-danger { border-color:#fca5a5; }
        .ue-danger .ue-section-title { color:#c0392b; }
    </style>
</head>
<body>

<header class="topbar">
    <a href="users.php" class="button button-secondary button-small">← Users</a>
    <h1 style="margin:0;font-size:1rem;font-weight:800;">Manage: <?php echo sanitize($target['name']); ?></h1>
    <div style="display:flex;align-items:center;gap:10px;">
        <span style="font-size:.78rem;color:var(--text-muted,#6b7280);">ID #<?php echo $targetId; ?></span>
        <?php if (is_admin() || has_mod_permission('view_reports')): ?>
        <a href="user_statement.php?id=<?php echo $targetId; ?>" class="button button-primary button-small">📄 Statement</a>
        <?php endif; ?>
    </div>
</header>

<main class="ue-shell">

    <?php if (is_admin() || has_mod_permission('view_reports')): ?>
    <div style="display:flex;justify-content:flex-end;margin-bottom:14px;">
        <a href="user_statement.php?id=<?php echo $targetId; ?>" class="button button-primary button-small">📄 Statement</a>
    </div>
    <?php endif; ?>

    <?php if ($flash): ?>
    <div class="alert alert-<?php echo sanitize($flash['type']); ?>" style="margin-bottom:14px;"><?php echo sanitize($flash['message']); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-error" style="margin-bottom:14px;"><?php echo sanitize($error); ?></div>
    <?php endif; ?>

    <div class="ue-layout">

        <!-- ── LEFT: Profile Card ── -->
        <aside>
            <div class="ue-profile-card">
                <div class="ue-av-lg">
                    <?php if (!empty($target['profile_photo'])): ?>
                        <img src="<?php echo sanitize('../'.ltrim($target['profile_photo'],'/')); ?>" alt="">
                    <?php else: ?>
                        <?php echo strtoupper(substr(display_name(['name'=>$target['name'],'username'=>$target['username']]),0,1)); ?>
                    <?php endif; ?>
                </div>
                <div class="ue-name"><?php echo sanitize($target['name']); ?></div>
                <?php if ($target['username']): ?><div style="text-align:center;font-size:.78rem;color:var(--text-muted,#6b7280);">@<?php echo sanitize($target['username']); ?></div><?php endif; ?>
                <div style="text-align:center;margin:8px 0;">
                    <span style="background:<?php echo $rBg; ?>;color:<?php echo $rCol; ?>;font-size:.72rem;font-weight:800;padding:3px 10px;border-radius:10px;"><?php echo ucfirst($target['role']); ?></span>
                    &nbsp;
                    <?php if ($target['banned']): ?>
                    <span style="background:#fee2e2;color:#c0392b;font-size:.72rem;font-weight:800;padding:3px 10px;border-radius:10px;">Banned</span>
                    <?php else: ?>
                    <span style="background:#d1fae5;color:#065f46;font-size:.72rem;font-weight:800;padding:3px 10px;border-radius:10px;">Active</span>
                    <?php endif; ?>
                </div>

                <hr class="ue-divider">

                <div class="ue-info-row"><span>Email</span><span><?php echo sanitize($target['email']); ?></span></div>
                <div class="ue-info-row"><span>Email verified</span><span><?php echo $target['email_verified']?'✅ Yes':'❌ No'; ?></span></div>
                <?php if ($target['phone']): ?><div class="ue-info-row"><span>Phone</span><span><?php echo sanitize($target['phone']); ?></span></div><?php endif; ?>
                <div class="ue-info-row"><span>Joined</span><span><?php echo date('d M Y', strtotime($target['created_at'])); ?></span></div>
                <?php if (!empty($target['chat_ban_until'])): ?>
                <div class="ue-info-row"><span>Chat ban until</span><span style="color:#f59e0b;"><?php echo date('d M Y', strtotime($target['chat_ban_until'])); ?></span></div>
                <?php endif; ?>
                <div class="ue-info-row"><span>Can send msgs</span><span><?php echo $target['can_send_messages']?'✅':'❌'; ?></span></div>
                <div class="ue-info-row"><span>Can receive</span><span><?php echo $target['can_receive_messages']?'✅':'❌'; ?></span></div>

                <?php if ($workerProfile): ?>
                <hr class="ue-divider">
                <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted,#6b7280);margin-bottom:6px;">Worker Profile</div>
                <div class="ue-info-row"><span>Availability</span><span><?php echo ucfirst($workerProfile['availability']); ?></span></div>
                <div class="ue-info-row"><span>Verification</span><span><?php echo ucfirst($workerProfile['verification_status']); ?></span></div>
                <div class="ue-info-row"><span>Featured</span><span><?php echo $workerProfile['is_featured']?'Yes':'No'; ?></span></div>
                <div class="ue-info-row"><span>Profile Views</span><span>👁️ <?php echo number_format((int)$workerProfile['view_count']); ?></span></div>
                <?php endif; ?>

                <?php if ($deliveryAgent): ?>
                <hr class="ue-divider">
                <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted,#6b7280);margin-bottom:6px;">Delivery Agent</div>
                <div class="ue-info-row"><span>Vehicle</span><span><?php echo ucfirst($deliveryAgent['vehicle_type']); ?></span></div>
                <div class="ue-info-row"><span>Status</span><span><?php echo ucfirst($deliveryAgent['verification_status']); ?></span></div>
                <div class="ue-info-row"><span>Rating</span><span><?php echo $deliveryAgent['rating']>0?number_format((float)$deliveryAgent['rating'],1).'★':'—'; ?></span></div>
                <?php endif; ?>

                <?php if ($shops): ?>
                <hr class="ue-divider">
                <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted,#6b7280);margin-bottom:6px;">Marketplace Shop<?php echo count($shops) > 1 ? 's' : ''; ?></div>
                <?php foreach ($shops as $shIdx => $sh): ?>
                <?php if ($shIdx > 0): ?><hr class="ue-divider" style="margin:8px 0;"><?php endif; ?>
                <div class="ue-info-row"><span><?php echo $sh['market_name'] ? '🏬 ' . sanitize($sh['market_name']) : 'Shop'; ?></span><span><?php echo sanitize($sh['shop_name']); ?></span></div>
                <div class="ue-info-row"><span>Status</span><span><?php echo ucfirst($sh['status']); ?></span></div>
                <div class="ue-info-row"><span>Sales</span><span><?php echo number_format((int)$sh['total_sales']); ?></span></div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </aside>

        <!-- ── RIGHT: Sections ── -->
        <div>

            <!-- Activity Stats -->
            <div class="ue-section">
                <p class="ue-section-title">Activity Overview</p>
                <div class="ue-stats">
                    <div class="ue-stat"><strong><?php echo $jobsPosted; ?></strong><span>Jobs Posted</span></div>
                    <div class="ue-stat"><strong><?php echo $jobsCompleted; ?></strong><span>Completed</span></div>
                    <div class="ue-stat"><strong><?php echo $appsSubmitted; ?></strong><span>Applications</span></div>
                    <div class="ue-stat"><strong><?php echo $mpOrders; ?></strong><span>Orders</span></div>
                </div>
                <?php if ($recentJobs): ?>
                <div style="font-size:.76rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted,#6b7280);margin-bottom:6px;">Recent Jobs</div>
                <?php foreach ($recentJobs as $j): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid var(--border);font-size:.82rem;">
                    <a href="../request_detail.php?id=<?php echo $j['id']; ?>" target="_blank" style="color:var(--primary,#0f766e);text-decoration:none;font-weight:600;"><?php echo sanitize(mb_substr($j['title'],0,50)); ?></a>
                    <span style="font-size:.72rem;color:var(--text-muted,#6b7280);"><?php echo ucfirst($j['status']); ?> · <?php echo date('d M', strtotime($j['created_at'])); ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Edit Profile -->
            <div class="ue-section">
                <p class="ue-section-title">Edit Profile</p>
                <form method="post" action="user_edit.php?id=<?php echo $targetId; ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="edit_profile">
                    <div class="ue-grid2">
                        <div class="form-group">
                            <label>Full Name *</label>
                            <input type="text" name="name" required value="<?php echo sanitize($target['name']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Username</label>
                            <input type="text" name="username" value="<?php echo sanitize($target['username']??''); ?>" placeholder="Optional">
                        </div>
                        <div class="form-group">
                            <label>Email *</label>
                            <input type="email" name="email" required value="<?php echo sanitize($target['email']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Phone</label>
                            <input type="tel" name="phone" value="<?php echo sanitize($target['phone']??''); ?>" placeholder="+233...">
                        </div>
                    </div>
                    <button type="submit" class="button button-primary button-small">Save Profile Changes</button>
                </form>
            </div>

            <!-- Send Notification -->
            <div class="ue-section">
                <p class="ue-section-title">Send Notification</p>
                <form method="post" action="user_edit.php?id=<?php echo $targetId; ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="send_notification">
                    <div class="ue-grid2">
                        <div class="form-group">
                            <label>Title *</label>
                            <input type="text" name="notif_title" required maxlength="150" placeholder="e.g. Account update">
                        </div>
                        <div class="form-group">
                            <label>Type</label>
                            <select name="notif_type">
                                <option value="info">Info</option>
                                <option value="success">Success</option>
                                <option value="warning">Warning</option>
                                <option value="error">Error</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Message *</label>
                        <textarea name="notif_body" class="rich-editor" rows="4" required placeholder="Message shown in this user's notifications"></textarea>
                    </div>
                    <div class="form-group">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
                            <input type="checkbox" name="notif_email" value="1">
                            Also send by email<?php echo $target['email'] ? ' (' . sanitize($target['email']) . ')' : ''; ?>
                        </label>
                    </div>
                    <button type="submit" class="button button-primary button-small">📨 Send Notification</button>
                </form>
            </div>

            <!-- Role -->
            <div class="ue-section">
                <p class="ue-section-title">Role & Permissions</p>
                <?php if ($target['role'] === 'admin' && !is_admin()): ?>
                <p style="font-size:.85rem;color:var(--text-muted,#6b7280);">Administrator accounts cannot be modified by managers.</p>
                <?php else: ?>
                <form method="post" action="user_edit.php?id=<?php echo $targetId; ?>" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="change_role">
                    <div class="form-group" style="margin:0;">
                        <label>Role</label>
                        <select name="role">
                            <option value="customer" <?php echo $target['role']==='customer'?'selected':''; ?>>Customer</option>
                            <option value="worker"   <?php echo $target['role']==='worker'?'selected':''; ?>>Worker</option>
                            <option value="manager"  <?php echo $target['role']==='manager'?'selected':''; ?>>Manager (Moderator)</option>
                            <?php if (is_admin()): ?><option value="admin" <?php echo $target['role']==='admin'?'selected':''; ?>>Admin</option><?php endif; ?>
                        </select>
                        <p class="form-hint">Changing to <strong>Manager</strong> grants admin dashboard access. Use the <a href="moderators.php">Moderators page</a> to set their specific permissions.</p>
                    </div>
                    <button type="submit" class="button button-primary button-small">Change Role</button>
                </form>
                <?php if ($target['role']==='manager'): ?>
                <div style="margin-top:10px;">
                    <a href="moderators.php" class="button button-secondary button-small">🛡️ Manage Permissions →</a>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Manage Restrictions (whole-system ban and/or specific features) -->
            <?php
            $targetFeatureBans   = get_user_feature_bans($targetId);
            $targetFeatureDetail = get_user_feature_ban_details($targetId);
            ?>
            <div class="ue-section">
                <p class="ue-section-title">Manage Restrictions</p>
                <form method="post" action="user_edit.php?id=<?php echo $targetId; ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="set_ban">
                    <div class="form-group">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <input type="checkbox" name="whole_system" <?php echo $target['banned']?'checked':''; ?>>
                            <strong>Whole System</strong> — blocks login entirely
                        </label>
                    </div>
                    <?php foreach (all_ban_features() as $fKey => $fLabel):
                        $hasIt = in_array($fKey, $targetFeatureBans, true);
                    ?>
                    <div class="form-group">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <input type="checkbox" name="features[]" value="<?php echo $fKey; ?>" <?php echo $hasIt?'checked':''; ?>>
                            <?php echo sanitize($fLabel); ?>
                        </label>
                        <?php if ($hasIt && !empty($targetFeatureDetail[$fKey]['reason'])): ?>
                        <p class="form-hint">Current reason: <?php echo sanitize($targetFeatureDetail[$fKey]['reason']); ?></p>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <div class="form-group">
                        <label>Reason (optional, shown to user)</label>
                        <input type="text" name="reason" placeholder="Violation reason, spam, etc.">
                    </div>
                    <button type="submit" class="button button-primary button-small">Save Restrictions</button>
                </form>
            </div>

            <!-- Chat Restrictions -->
            <div class="ue-section">
                <p class="ue-section-title">Chat Restrictions</p>
                <form method="post" action="user_edit.php?id=<?php echo $targetId; ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="chat_restrict">
                    <div class="ue-grid2">
                        <div class="form-group">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                <input type="checkbox" name="can_send" value="1" <?php echo $target['can_send_messages']?'checked':''; ?>>
                                Can send messages
                            </label>
                        </div>
                        <div class="form-group">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                <input type="checkbox" name="can_receive" value="1" <?php echo $target['can_receive_messages']?'checked':''; ?>>
                                Can receive messages
                            </label>
                        </div>
                        <div class="form-group">
                            <label>Chat ban until (optional)</label>
                            <input type="date" name="chat_ban_until"
                                   value="<?php echo $target['chat_ban_until'] ? date('Y-m-d', strtotime($target['chat_ban_until'])) : ''; ?>"
                                   min="<?php echo date('Y-m-d'); ?>">
                            <p class="form-hint">Leave blank to remove any time-limited chat ban.</p>
                        </div>
                    </div>
                    <button type="submit" class="button button-secondary button-small">Save Chat Settings</button>
                </form>
            </div>

            <!-- Security -->
            <div class="ue-section">
                <p class="ue-section-title">Security & Access</p>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
                    <?php if (!$target['email_verified']): ?>
                    <form method="post" action="user_edit.php?id=<?php echo $targetId; ?>" style="margin:0;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="verify_email">
                        <button type="submit" class="button button-secondary button-small">✅ Verify Email Manually</button>
                    </form>
                    <?php endif; ?>
                    <form method="post" action="user_edit.php?id=<?php echo $targetId; ?>" style="margin:0;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="send_password_reset">
                        <button type="submit" class="button button-secondary button-small">🔑 Notify: Reset Password</button>
                    </form>
                </div>
                <?php if (is_admin()): ?>
                <form method="post" action="user_edit.php?id=<?php echo $targetId; ?>" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="set_password">
                    <div class="form-group" style="margin:0;flex:1;">
                        <label>Set New Password (Admin Override)</label>
                        <input type="password" name="new_password" placeholder="Min 6 characters" autocomplete="new-password">
                        <p class="form-hint">Use with care. The user will be notified. Their session will NOT be invalidated automatically.</p>
                    </div>
                    <button type="submit" class="button button-small" style="background:#8b5cf6;color:#fff;border-color:transparent;"
                            onclick="return confirm('Set a new password for this user?');">Set Password</button>
                </form>
                <?php endif; ?>
            </div>

            <!-- Activity Log -->
            <?php if ($recentActivity): ?>
            <div class="ue-section">
                <p class="ue-section-title">Recent Admin Actions by This User</p>
                <?php foreach ($recentActivity as $log): ?>
                <div style="display:flex;justify-content:space-between;align-items:flex-start;padding:6px 0;border-bottom:1px solid var(--border);font-size:.82rem;gap:10px;">
                    <div>
                        <span style="background:var(--primary-soft,#d1fae5);color:var(--primary,#0f766e);font-size:.65rem;font-weight:800;padding:1px 6px;border-radius:8px;margin-right:5px;"><?php echo sanitize($log['action']); ?></span>
                        <?php echo sanitize(mb_substr($log['description'],0,100)); ?>
                    </div>
                    <span style="font-size:.72rem;color:var(--text-muted,#6b7280);white-space:nowrap;"><?php echo time_ago($log['created_at']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Complimentary Membership -->
            <?php if (is_admin()): ?>
            <div class="ue-section">
                <p class="ue-section-title">Complimentary Membership</p>
                <?php if (!empty($target['is_complimentary'])): ?>
                <?php $allFeatures = all_complimentary_features(); $isFullGrant = in_array('full', $targetGrantKeys, true); ?>
                <p style="font-size:.85rem;color:var(--text-muted,#6b7280);margin:0 0 12px;">
                    ⭐ This user has free access to
                    <?php echo $isFullGrant ? 'every paid feature on the platform' : sanitize(implode(', ', array_map(fn($k) => $allFeatures[$k] ?? $k, $targetGrantKeys))); ?>
                    <?php if (!empty($target['complimentary_granted_at'])): ?> since <?php echo date('d M Y', strtotime($target['complimentary_granted_at'])); ?><?php endif; ?>.
                    Manage exactly which features are granted from
                    <a href="complimentary_members.php">Complimentary Memberships</a>.
                </p>
                <form method="post" action="user_edit.php?id=<?php echo $targetId; ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="revoke_complimentary">
                    <button type="submit" class="button button-small" style="background:#ef4444;color:#fff;border-color:transparent;"
                            onclick="return confirm('Revoke <?php echo sanitize(addslashes($target['name'])); ?>\'s complimentary membership? Paid features will require payment again.');">
                        Revoke Complimentary Membership
                    </button>
                </form>
                <?php else: ?>
                <p style="font-size:.85rem;color:var(--text-muted,#6b7280);margin:0 0 12px;">Grant free access to every currently-paid feature (job posting, featured listings, verification, marketplace subscriptions, delivery premium, etc.) until revoked. To grant only specific features instead, use <a href="complimentary_members.php">Complimentary Memberships</a>.</p>
                <form method="post" action="user_edit.php?id=<?php echo $targetId; ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="grant_complimentary">
                    <button type="submit" class="button button-small" style="background:#f59e0b;color:#fff;border-color:transparent;"
                            onclick="return confirm('Grant <?php echo sanitize(addslashes($target['name'])); ?> full complimentary access?');">
                        ⭐ Grant Complimentary Membership (Full)
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Log in as this user -->
            <?php if (is_admin() && $target['role'] !== 'admin'): ?>
            <div class="ue-section">
                <p class="ue-section-title">Support Access</p>
                <p style="font-size:.85rem;color:var(--text-muted,#6b7280);margin:0 0 12px;">See the app exactly as this user does — useful for reproducing a reported issue. Logged in the audit trail; the user is not notified.</p>
                <form method="post" action="user_edit.php?id=<?php echo $targetId; ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="login_as_user">
                    <button type="submit" class="button button-small" style="background:#8b5cf6;color:#fff;border-color:transparent;"
                            onclick="return confirm('Log in as <?php echo sanitize(addslashes($target['name'])); ?>? You will see the app exactly as they do until you exit.');">
                        🔑 Log In As This User
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <!-- Danger Zone -->
            <?php if (is_admin() && $target['role'] !== 'admin'): ?>
            <div class="ue-section ue-danger">
                <p class="ue-section-title">Danger Zone</p>
                <p style="font-size:.85rem;color:var(--text-muted,#6b7280);margin:0 0 12px;">Permanently delete this account. All associated data (jobs, applications, orders, reviews, chat history) will also be deleted. <strong>This cannot be undone.</strong></p>
                <form method="post" action="user_edit.php?id=<?php echo $targetId; ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="delete_account">
                    <button type="submit" class="button button-small" style="background:#ef4444;color:#fff;border-color:transparent;"
                            onclick="return confirm('PERMANENTLY delete <?php echo sanitize(addslashes($target['name'])); ?>\'s account? This CANNOT be undone.');">
                        Delete Account Permanently
                    </button>
                </form>
            </div>
            <?php endif; ?>

        </div><!-- /right -->
    </div><!-- /layout -->
</main>
<script src="../assets/js/rich-editor.js" defer></script>
<script src="../assets/js/username-input.js"></script>
</body>
</html>
