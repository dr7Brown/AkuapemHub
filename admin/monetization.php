<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin()) {
    header('Location: index.php');
    exit;
}

$success = '';
$error = '';
$tab = $_GET['tab'] ?? 'settings';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $mode = $_POST['monetization_mode'] ?? 'free';
        if (!in_array($mode, ['free', 'hybrid', 'paid'], true)) $mode = 'free';
        set_platform_setting('monetization_mode', $mode);
        foreach (['enable_paid_featured_jobs', 'enable_paid_featured_workers', 'enable_paid_verification_badges'] as $key) {
            set_platform_setting($key, isset($_POST[$key]) ? '1' : '0');
        }
        $prevMode = get_platform_setting('monetization_mode', 'free');
        log_audit_action($user['id'] ?? 0, 'monetization_updated', "Monetization mode set to '{$mode}'");
        $success = 'Monetization settings saved.';
        $tab = 'settings';

    } elseif ($action === 'save_package') {
        $pkgType = $_POST['pkg_type'] ?? '';
        $pkgId = intval($_POST['pkg_id'] ?? 0);
        $pkgName = trim($_POST['pkg_name'] ?? '');
        $pkgPrice = max(0, (float)($_POST['pkg_price'] ?? 0));
        $pkgDays = max(1, intval($_POST['pkg_days'] ?? 0));
        $pkgStatus = ($_POST['pkg_status'] ?? '') === 'active' ? 'active' : 'inactive';

        $tableMap = [
            'featured_job' => 'featured_job_packages',
            'featured_worker' => 'worker_promotion_packages',
            'verification' => 'verification_packages',
        ];
        $table = $tableMap[$pkgType] ?? '';
        if ($table && $pkgName !== '') {
            if ($pkgId > 0) {
                if ($pkgType === 'verification') {
                    $pdo->prepare("UPDATE $table SET name = ?, price = ?, status = ? WHERE id = ?")
                        ->execute([$pkgName, $pkgPrice, $pkgStatus, $pkgId]);
                } else {
                    $pdo->prepare("UPDATE $table SET name = ?, duration_days = ?, price = ?, status = ? WHERE id = ?")
                        ->execute([$pkgName, $pkgDays, $pkgPrice, $pkgStatus, $pkgId]);
                }
                log_audit_action($user['id'] ?? 0, 'package_edited', "Edited {$pkgType} package ID {$pkgId}: '{$pkgName}'");
            } else {
                if ($pkgType === 'verification') {
                    $pdo->prepare("INSERT INTO $table (name, price, status) VALUES (?, ?, ?)")
                        ->execute([$pkgName, $pkgPrice, $pkgStatus]);
                } else {
                    $pdo->prepare("INSERT INTO $table (name, duration_days, price, status) VALUES (?, ?, ?, ?)")
                        ->execute([$pkgName, $pkgDays, $pkgPrice, $pkgStatus]);
                }
                log_audit_action($user['id'] ?? 0, 'package_created', "Created {$pkgType} package: '{$pkgName}'");
            }
            $success = 'Package saved.';
        } else {
            $error = 'Package name is required.';
        }
        $tab = $pkgType === 'featured_job' ? 'featured_jobs' : ($pkgType === 'featured_worker' ? 'featured_workers' : 'verification');

    } elseif ($action === 'delete_package') {
        $pkgType = $_POST['pkg_type'] ?? '';
        $pkgId = intval($_POST['pkg_id'] ?? 0);
        $tableMap = [
            'featured_job' => 'featured_job_packages',
            'featured_worker' => 'worker_promotion_packages',
            'verification' => 'verification_packages',
        ];
        $table = $tableMap[$pkgType] ?? '';
        if ($table && $pkgId > 0) {
            $pdo->prepare("DELETE FROM $table WHERE id = ?")->execute([$pkgId]);
            log_audit_action($user['id'] ?? 0, 'package_deleted', "Deleted {$pkgType} package ID {$pkgId}");
            $success = 'Package deleted.';
        }
        $tab = $pkgType === 'featured_job' ? 'featured_jobs' : ($pkgType === 'featured_worker' ? 'featured_workers' : 'verification');

    } elseif ($action === 'confirm_payment') {
        $paymentId = intval($_POST['payment_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM platform_payments WHERE id = ? AND status = ?');
        $stmt->execute([$paymentId, 'pending']);
        $payment = $stmt->fetch();
        if ($payment) {
            $pdo->prepare('UPDATE platform_payments SET status = ?, paid_at = NOW() WHERE id = ?')
                ->execute(['paid', $paymentId]);
            // Activate the feature based on payment type
            if ($payment['payment_type'] === 'featured_job' && $payment['reference_id']) {
                $pkg = $pdo->prepare('SELECT duration_days FROM featured_job_packages WHERE id = ?');
                $pkg->execute([$payment['package_id']]);
                $pkg = $pkg->fetch();
                $days = $pkg ? $pkg['duration_days'] : 7;
                $pdo->prepare('UPDATE service_requests SET featured = 1, featured_start_date = CURDATE(), featured_end_date = DATE_ADD(CURDATE(), INTERVAL ? DAY) WHERE id = ?')
                    ->execute([$days, $payment['reference_id']]);
                notify_user($payment['user_id'], 'Job featured', 'Your job has been featured and will appear at the top of listings.', 'success');
            } elseif ($payment['payment_type'] === 'featured_worker' && $payment['reference_id']) {
                $pkg = $pdo->prepare('SELECT duration_days FROM worker_promotion_packages WHERE id = ?');
                $pkg->execute([$payment['package_id']]);
                $pkg = $pkg->fetch();
                $days = $pkg ? $pkg['duration_days'] : 7;
                $pdo->prepare('UPDATE worker_profiles SET is_featured = 1, featured_start_date = CURDATE(), featured_end_date = DATE_ADD(CURDATE(), INTERVAL ? DAY) WHERE user_id = ?')
                    ->execute([$days, $payment['user_id']]);
                notify_user($payment['user_id'], 'Profile featured', 'Your worker profile is now featured in search results.', 'success');
            } elseif ($payment['payment_type'] === 'verification') {
                $pkg = $pdo->prepare('SELECT * FROM verification_packages WHERE id = ?');
                $pkg->execute([$payment['package_id']]);
                $pkg = $pkg->fetch();
                $pdo->prepare('UPDATE worker_profiles SET is_verified = 1, verification_date = CURDATE(), verification_expiry = DATE_ADD(CURDATE(), INTERVAL 365 DAY) WHERE user_id = ?')
                    ->execute([$payment['user_id']]);
                notify_user($payment['user_id'], 'Verification approved', 'Your worker profile is now verified. The badge will appear on your profile and search results.', 'success');
            }
            $success = 'Payment confirmed and feature activated.';
        }
        $tab = 'payments';

    } elseif ($action === 'verify_worker_free') {
        $workerId = intval($_POST['worker_user_id'] ?? 0);
        if ($workerId > 0) {
            $pdo->prepare('UPDATE worker_profiles SET is_verified = 1, verification_date = CURDATE(), verification_expiry = DATE_ADD(CURDATE(), INTERVAL 365 DAY) WHERE user_id = ?')
                ->execute([$workerId]);
            notify_user($workerId, 'Verification approved', 'Your worker profile is now verified. The badge will appear on your profile and search results.', 'success');
            log_audit_action($user['id'] ?? 0, 'worker_verified', "Verified worker user ID {$workerId}");
            $success = 'Worker verified.';
        }
        $tab = 'verification';

    } elseif ($action === 'revoke_verification') {
        $workerId = intval($_POST['worker_user_id'] ?? 0);
        if ($workerId > 0) {
            $pdo->prepare('UPDATE worker_profiles SET is_verified = 0, verification_date = NULL, verification_expiry = NULL WHERE user_id = ?')
                ->execute([$workerId]);
            notify_user($workerId, 'Verification revoked', 'Your worker verification badge has been revoked. Contact support for more information.', 'warning');
            log_audit_action($user['id'] ?? 0, 'verification_revoked', "Revoked verification for worker user ID {$workerId}");
            $success = 'Verification revoked.';
        }
        $tab = 'verification';
    }

    header('Location: monetization.php?tab=' . urlencode($tab) . ($success ? '&msg=' . urlencode($success) : ($error ? '&err=' . urlencode($error) : '')));
    exit;
}

$user = current_user();
$msgFlash = $_GET['msg'] ?? '';
$errFlash = $_GET['err'] ?? '';

$monetizationMode = get_platform_setting('monetization_mode', 'free');
$enableFeaturedJobs = get_platform_setting('enable_paid_featured_jobs', '0');
$enableFeaturedWorkers = get_platform_setting('enable_paid_featured_workers', '0');
$enableVerification = get_platform_setting('enable_paid_verification_badges', '0');

$featuredJobPackages = get_active_packages('featured_job_packages');
$allFeaturedJobPackages = $pdo->query("SELECT * FROM featured_job_packages ORDER BY price ASC")->fetchAll();
$workerPromoPackages = get_active_packages('worker_promotion_packages');
$allWorkerPromoPackages = $pdo->query("SELECT * FROM worker_promotion_packages ORDER BY price ASC")->fetchAll();
$verificationPackages = get_active_packages('verification_packages');
$allVerificationPackages = $pdo->query("SELECT * FROM verification_packages ORDER BY price ASC")->fetchAll();

$pendingPayments = $pdo->query("SELECT pp.*, u.name AS user_name, u.username FROM platform_payments pp JOIN users u ON pp.user_id = u.id WHERE pp.status = 'pending' ORDER BY pp.created_at DESC")->fetchAll();

$allWorkers = $pdo->query("SELECT u.id, u.name, u.username, wp.is_verified, wp.verification_date, wp.verification_expiry FROM users u JOIN worker_profiles wp ON u.id = wp.user_id WHERE u.role = 'worker' AND u.banned = 0 ORDER BY wp.is_verified ASC, u.name ASC")->fetchAll();

$auditLogs = $pdo->query("SELECT al.*, u.name AS admin_name FROM audit_logs al JOIN users u ON al.admin_id = u.id ORDER BY al.created_at DESC LIMIT 50")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Monetization — AkuapemHub Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css" />
    <style>
        .pkg-table { width: 100%; border-collapse: collapse; font-size: 0.95rem; }
        .pkg-table th, .pkg-table td { padding: 8px 10px; border-bottom: 1px solid var(--border); text-align: left; }
        .pkg-table th { font-weight: 600; background: var(--surface); }
        .mono-tabs { display: flex; gap: 0; border-bottom: 2px solid var(--border); margin-bottom: 20px; flex-wrap: wrap; }
        .mono-tab { padding: 8px 16px; cursor: pointer; font-size: 0.9rem; border: none; background: none; border-bottom: 2px solid transparent; margin-bottom: -2px; }
        .mono-tab.active { border-bottom-color: var(--primary); color: var(--primary); font-weight: 600; }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }
        .mode-cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 20px; }
        .mode-card { border: 2px solid var(--border); border-radius: 10px; padding: 14px; cursor: pointer; transition: border-color 0.15s; }
        .mode-card.selected { border-color: var(--primary); background: var(--primary-soft); }
        .mode-card h3 { margin: 0 0 4px; font-size: 1rem; }
        .mode-card p { margin: 0; font-size: 0.85rem; color: var(--text-muted); }
        .inline-form { display: inline; }
        @media (max-width: 600px) { .mode-cards { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <header class="topbar">
        <a href="index.php" class="button button-secondary button-small">Back</a>
        <h1>Monetization Settings</h1>
        <a href="../logout.php" class="button button-secondary button-small">Logout</a>
    </header>
    <main class="page-shell">
        <?php if ($msgFlash): ?>
            <div class="alert alert-success"><?php echo sanitize($msgFlash); ?></div>
        <?php endif; ?>
        <?php if ($errFlash): ?>
            <div class="alert alert-error"><?php echo sanitize($errFlash); ?></div>
        <?php endif; ?>

        <nav class="mono-tabs">
            <button class="mono-tab <?php echo $tab === 'settings' ? 'active' : ''; ?>" data-tab="settings">Settings</button>
            <button class="mono-tab <?php echo $tab === 'featured_jobs' ? 'active' : ''; ?>" data-tab="featured_jobs">Featured Jobs</button>
            <button class="mono-tab <?php echo $tab === 'featured_workers' ? 'active' : ''; ?>" data-tab="featured_workers">Featured Workers</button>
            <button class="mono-tab <?php echo $tab === 'verification' ? 'active' : ''; ?>" data-tab="verification">Verification</button>
            <button class="mono-tab <?php echo $tab === 'payments' ? 'active' : ''; ?>" data-tab="payments">Pending Payments <?php if ($pendingPayments): ?><span style="background:var(--primary);color:#fff;border-radius:10px;padding:1px 7px;font-size:0.8rem;"><?php echo count($pendingPayments); ?></span><?php endif; ?></button>
            <button class="mono-tab <?php echo $tab === 'audit' ? 'active' : ''; ?>" data-tab="audit">Audit Log</button>
        </nav>

        <!-- SETTINGS TAB -->
        <div class="tab-panel <?php echo $tab === 'settings' ? 'active' : ''; ?>" id="tab-settings">
            <section class="panel">
                <h2>Global monetization mode</h2>
                <form method="post" action="monetization.php">
                    <input type="hidden" name="action" value="save_settings" />
                    <div class="mode-cards">
                        <label class="mode-card <?php echo $monetizationMode === 'free' ? 'selected' : ''; ?>">
                            <input type="radio" name="monetization_mode" value="free" <?php echo $monetizationMode === 'free' ? 'checked' : ''; ?> style="display:none;" />
                            <h3>Free Mode</h3>
                            <p>All features are free. Individual feature settings below are ignored.</p>
                        </label>
                        <label class="mode-card <?php echo $monetizationMode === 'hybrid' ? 'selected' : ''; ?>">
                            <input type="radio" name="monetization_mode" value="hybrid" <?php echo $monetizationMode === 'hybrid' ? 'checked' : ''; ?> style="display:none;" />
                            <h3>Hybrid Mode</h3>
                            <p>Individual settings below apply — some features can be paid, others free.</p>
                        </label>
                        <label class="mode-card <?php echo $monetizationMode === 'paid' ? 'selected' : ''; ?>">
                            <input type="radio" name="monetization_mode" value="paid" <?php echo $monetizationMode === 'paid' ? 'checked' : ''; ?> style="display:none;" />
                            <h3>Paid Mode</h3>
                            <p>All monetizable features require payment, regardless of individual settings.</p>
                        </label>
                    </div>
                    <h2 style="margin-top: 24px;">Individual feature settings <span class="meta">(apply in Hybrid Mode)</span></h2>
                    <table class="pkg-table">
                        <thead><tr><th>Feature</th><th>Status</th></tr></thead>
                        <tbody>
                            <tr>
                                <td><strong>Featured Job Posts</strong><br><span class="meta">Charge users to feature their job posts</span></td>
                                <td>
                                    <label style="margin-right:16px;"><input type="radio" name="enable_paid_featured_jobs" value="0" <?php echo !$enableFeaturedJobs ? 'checked' : ''; ?>> Free</label>
                                    <label><input type="radio" name="enable_paid_featured_jobs" value="1" <?php echo $enableFeaturedJobs ? 'checked' : ''; ?>> Paid</label>
                                    <input type="hidden" name="enable_paid_featured_jobs" value="0" />
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Featured Worker Profiles</strong><br><span class="meta">Charge workers to appear at top of search</span></td>
                                <td>
                                    <label style="margin-right:16px;"><input type="radio" name="enable_paid_featured_workers" value="0" <?php echo !$enableFeaturedWorkers ? 'checked' : ''; ?>> Free</label>
                                    <label><input type="radio" name="enable_paid_featured_workers" value="1" <?php echo $enableFeaturedWorkers ? 'checked' : ''; ?>> Paid</label>
                                    <input type="hidden" name="enable_paid_featured_workers" value="0" />
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Verification Badges</strong><br><span class="meta">Charge workers for the verified badge</span></td>
                                <td>
                                    <label style="margin-right:16px;"><input type="radio" name="enable_paid_verification_badges" value="0" <?php echo !$enableVerification ? 'checked' : ''; ?>> Free</label>
                                    <label><input type="radio" name="enable_paid_verification_badges" value="1" <?php echo $enableVerification ? 'checked' : ''; ?>> Paid</label>
                                    <input type="hidden" name="enable_paid_verification_badges" value="0" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <button type="submit" class="button button-primary" style="margin-top: 16px;">Save settings</button>
                </form>
            </section>
        </div>

        <!-- FEATURED JOB PACKAGES TAB -->
        <div class="tab-panel <?php echo $tab === 'featured_jobs' ? 'active' : ''; ?>" id="tab-featured_jobs">
            <section class="panel">
                <h2>Featured Job Packages</h2>
                <table class="pkg-table">
                    <thead><tr><th>Name</th><th>Duration</th><th>Price (GH₵)</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($allFeaturedJobPackages as $pkg): ?>
                            <tr>
                                <td><?php echo sanitize($pkg['name']); ?></td>
                                <td><?php echo $pkg['duration_days']; ?> days</td>
                                <td><?php echo number_format($pkg['price'], 2); ?></td>
                                <td><span class="status status-<?php echo $pkg['status'] === 'active' ? 'open' : 'cancelled'; ?>"><?php echo strtoupper($pkg['status']); ?></span></td>
                                <td>
                                    <button class="button button-small button-secondary" onclick="editPackage('featured_job', <?php echo $pkg['id']; ?>, '<?php echo sanitize($pkg['name']); ?>', <?php echo $pkg['duration_days']; ?>, <?php echo $pkg['price']; ?>, '<?php echo $pkg['status']; ?>')">Edit</button>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this package?')">
                                        <input type="hidden" name="action" value="delete_package" />
                                        <input type="hidden" name="pkg_type" value="featured_job" />
                                        <input type="hidden" name="pkg_id" value="<?php echo $pkg['id']; ?>" />
                                        <button type="submit" class="button button-small button-secondary">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <h3 style="margin-top: 20px;">Add / Edit Package</h3>
                <form method="post" action="monetization.php" id="form-featured_job">
                    <input type="hidden" name="action" value="save_package" />
                    <input type="hidden" name="pkg_type" value="featured_job" />
                    <input type="hidden" name="pkg_id" id="featured_job_id" value="0" />
                    <label>Package name</label>
                    <input type="text" name="pkg_name" id="featured_job_name" required placeholder="e.g. 14 Days" />
                    <label>Duration (days)</label>
                    <input type="number" name="pkg_days" id="featured_job_days" required min="1" value="7" />
                    <label>Price (GH₵)</label>
                    <input type="number" name="pkg_price" id="featured_job_price" required min="0" step="0.01" value="0" />
                    <label>Status</label>
                    <select name="pkg_status" id="featured_job_status"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                    <div style="display:flex;gap:8px;margin-top:8px;">
                        <button type="submit" class="button button-primary">Save package</button>
                        <button type="button" class="button button-secondary" onclick="resetForm('featured_job')">Clear</button>
                    </div>
                </form>
            </section>
        </div>

        <!-- FEATURED WORKER PACKAGES TAB -->
        <div class="tab-panel <?php echo $tab === 'featured_workers' ? 'active' : ''; ?>" id="tab-featured_workers">
            <section class="panel">
                <h2>Worker Promotion Packages</h2>
                <table class="pkg-table">
                    <thead><tr><th>Name</th><th>Duration</th><th>Price (GH₵)</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($allWorkerPromoPackages as $pkg): ?>
                            <tr>
                                <td><?php echo sanitize($pkg['name']); ?></td>
                                <td><?php echo $pkg['duration_days']; ?> days</td>
                                <td><?php echo number_format($pkg['price'], 2); ?></td>
                                <td><span class="status status-<?php echo $pkg['status'] === 'active' ? 'open' : 'cancelled'; ?>"><?php echo strtoupper($pkg['status']); ?></span></td>
                                <td>
                                    <button class="button button-small button-secondary" onclick="editPackage('featured_worker', <?php echo $pkg['id']; ?>, '<?php echo sanitize($pkg['name']); ?>', <?php echo $pkg['duration_days']; ?>, <?php echo $pkg['price']; ?>, '<?php echo $pkg['status']; ?>')">Edit</button>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this package?')">
                                        <input type="hidden" name="action" value="delete_package" />
                                        <input type="hidden" name="pkg_type" value="featured_worker" />
                                        <input type="hidden" name="pkg_id" value="<?php echo $pkg['id']; ?>" />
                                        <button type="submit" class="button button-small button-secondary">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <h3 style="margin-top: 20px;">Add / Edit Package</h3>
                <form method="post" action="monetization.php" id="form-featured_worker">
                    <input type="hidden" name="action" value="save_package" />
                    <input type="hidden" name="pkg_type" value="featured_worker" />
                    <input type="hidden" name="pkg_id" id="featured_worker_id" value="0" />
                    <label>Package name</label>
                    <input type="text" name="pkg_name" id="featured_worker_name" required placeholder="e.g. 30 Days" />
                    <label>Duration (days)</label>
                    <input type="number" name="pkg_days" id="featured_worker_days" required min="1" value="7" />
                    <label>Price (GH₵)</label>
                    <input type="number" name="pkg_price" id="featured_worker_price" required min="0" step="0.01" value="0" />
                    <label>Status</label>
                    <select name="pkg_status" id="featured_worker_status"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                    <div style="display:flex;gap:8px;margin-top:8px;">
                        <button type="submit" class="button button-primary">Save package</button>
                        <button type="button" class="button button-secondary" onclick="resetForm('featured_worker')">Clear</button>
                    </div>
                </form>
            </section>
        </div>

        <!-- VERIFICATION TAB -->
        <div class="tab-panel <?php echo $tab === 'verification' ? 'active' : ''; ?>" id="tab-verification">
            <section class="panel">
                <h2>Verification Packages</h2>
                <table class="pkg-table">
                    <thead><tr><th>Name</th><th>Price (GH₵)</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($allVerificationPackages as $pkg): ?>
                            <tr>
                                <td><?php echo sanitize($pkg['name']); ?></td>
                                <td><?php echo number_format($pkg['price'], 2); ?></td>
                                <td><span class="status status-<?php echo $pkg['status'] === 'active' ? 'open' : 'cancelled'; ?>"><?php echo strtoupper($pkg['status']); ?></span></td>
                                <td>
                                    <button class="button button-small button-secondary" onclick="editVerifPackage(<?php echo $pkg['id']; ?>, '<?php echo sanitize($pkg['name']); ?>', <?php echo $pkg['price']; ?>, '<?php echo $pkg['status']; ?>')">Edit</button>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this package?')">
                                        <input type="hidden" name="action" value="delete_package" />
                                        <input type="hidden" name="pkg_type" value="verification" />
                                        <input type="hidden" name="pkg_id" value="<?php echo $pkg['id']; ?>" />
                                        <button type="submit" class="button button-small button-secondary">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <h3 style="margin-top: 20px;">Add / Edit Verification Package</h3>
                <form method="post" action="monetization.php" id="form-verification">
                    <input type="hidden" name="action" value="save_package" />
                    <input type="hidden" name="pkg_type" value="verification" />
                    <input type="hidden" name="pkg_id" id="verification_id" value="0" />
                    <label>Package name</label>
                    <input type="text" name="pkg_name" id="verification_name" required placeholder="e.g. Verified Worker Badge" />
                    <label>Price (GH₵)</label>
                    <input type="number" name="pkg_price" id="verification_price" required min="0" step="0.01" value="0" />
                    <label>Status</label>
                    <select name="pkg_status" id="verification_status"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                    <div style="display:flex;gap:8px;margin-top:8px;">
                        <button type="submit" class="button button-primary">Save package</button>
                        <button type="button" class="button button-secondary" onclick="resetVerifForm()">Clear</button>
                    </div>
                </form>
                <h3 style="margin-top: 24px;">Worker Verification Status</h3>
                <table class="pkg-table">
                    <thead><tr><th>Worker</th><th>Status</th><th>Expires</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($allWorkers as $w): ?>
                            <tr>
                                <td><?php echo sanitize(display_name($w)); ?> <span class="meta">(<?php echo sanitize($w['name']); ?>)</span></td>
                                <td><?php echo $w['is_verified'] ? '<span class="status status-open">VERIFIED ✓</span>' : '<span class="status status-pending">Unverified</span>'; ?></td>
                                <td><?php echo $w['verification_expiry'] ? sanitize($w['verification_expiry']) : '—'; ?></td>
                                <td>
                                    <?php if (!$w['is_verified']): ?>
                                        <form method="post" class="inline-form">
                                            <input type="hidden" name="action" value="verify_worker_free" />
                                            <input type="hidden" name="worker_user_id" value="<?php echo $w['id']; ?>" />
                                            <button type="submit" class="button button-small button-primary">Verify</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" class="inline-form" onsubmit="return confirm('Revoke verification for this worker?')">
                                            <input type="hidden" name="action" value="revoke_verification" />
                                            <input type="hidden" name="worker_user_id" value="<?php echo $w['id']; ?>" />
                                            <button type="submit" class="button button-small button-secondary">Revoke</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </div>

        <!-- PENDING PAYMENTS TAB -->
        <div class="tab-panel <?php echo $tab === 'payments' ? 'active' : ''; ?>" id="tab-payments">
            <section class="panel">
                <h2>Pending Platform Payments</h2>
                <?php if (empty($pendingPayments)): ?>
                    <div class="empty-state">No pending payments.</div>
                <?php else: ?>
                    <table class="pkg-table">
                        <thead><tr><th>User</th><th>Type</th><th>Amount</th><th>Ref</th><th>Date</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php foreach ($pendingPayments as $pay): ?>
                                <tr>
                                    <td><?php echo sanitize(display_name($pay)); ?></td>
                                    <td><?php echo sanitize(ucwords(str_replace('_', ' ', $pay['payment_type']))); ?></td>
                                    <td>GH₵ <?php echo number_format($pay['amount'], 2); ?></td>
                                    <td><?php echo sanitize($pay['reference_code'] ?: '—'); ?></td>
                                    <td><?php echo sanitize($pay['created_at']); ?></td>
                                    <td>
                                        <form method="post" class="inline-form">
                                            <input type="hidden" name="action" value="confirm_payment" />
                                            <input type="hidden" name="payment_id" value="<?php echo $pay['id']; ?>" />
                                            <button type="submit" class="button button-small button-primary">Confirm paid</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        </div>

        <!-- AUDIT LOG TAB -->
        <div class="tab-panel <?php echo $tab === 'audit' ? 'active' : ''; ?>" id="tab-audit">
            <section class="panel">
                <h2>Audit Log <span class="meta">(last 50 actions)</span></h2>
                <?php if (empty($auditLogs)): ?>
                    <div class="empty-state">No audit log entries yet.</div>
                <?php else: ?>
                    <table class="pkg-table">
                        <thead><tr><th>Admin</th><th>Action</th><th>Description</th><th>Date</th></tr></thead>
                        <tbody>
                            <?php foreach ($auditLogs as $log): ?>
                                <tr>
                                    <td><?php echo sanitize($log['admin_name']); ?></td>
                                    <td><?php echo sanitize($log['action']); ?></td>
                                    <td><?php echo sanitize($log['description']); ?></td>
                                    <td><?php echo sanitize($log['created_at']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        </div>
    </main>
    <script>
        // Tab switching
        document.querySelectorAll('.mono-tab').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var t = btn.getAttribute('data-tab');
                document.querySelectorAll('.mono-tab').forEach(function (b) { b.classList.remove('active'); });
                document.querySelectorAll('.tab-panel').forEach(function (p) { p.classList.remove('active'); });
                btn.classList.add('active');
                document.getElementById('tab-' + t).classList.add('active');
            });
        });

        // Mode card radio highlight
        document.querySelectorAll('.mode-card input[type="radio"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                document.querySelectorAll('.mode-card').forEach(function (c) { c.classList.remove('selected'); });
                radio.closest('.mode-card').classList.add('selected');
            });
        });

        function editPackage(type, id, name, days, price, status) {
            document.getElementById(type + '_id').value = id;
            document.getElementById(type + '_name').value = name;
            document.getElementById(type + '_days').value = days;
            document.getElementById(type + '_price').value = price;
            document.getElementById(type + '_status').value = status;
            document.getElementById('form-' + type).scrollIntoView({ behavior: 'smooth' });
        }

        function resetForm(type) {
            document.getElementById(type + '_id').value = 0;
            document.getElementById('form-' + type).reset();
        }

        function editVerifPackage(id, name, price, status) {
            document.getElementById('verification_id').value = id;
            document.getElementById('verification_name').value = name;
            document.getElementById('verification_price').value = price;
            document.getElementById('verification_status').value = status;
            document.getElementById('form-verification').scrollIntoView({ behavior: 'smooth' });
        }

        function resetVerifForm() {
            document.getElementById('verification_id').value = 0;
            document.getElementById('form-verification').reset();
        }
    </script>
</body>
</html>
