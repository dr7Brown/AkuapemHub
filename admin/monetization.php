<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../functions.php';

require_login();
if (!is_admin()) {
    header('Location: index.php');
    exit;
}

$user = current_user();
$success = '';
$error = '';
$tab = $_GET['tab'] ?? 'settings';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_paystack_settings') {
        $psMode   = in_array($_POST['paystack_mode'] ?? '', ['test', 'live'], true) ? $_POST['paystack_mode'] : 'test';
        $psPub    = trim($_POST['paystack_public_key'] ?? '');
        $psSecret = trim($_POST['paystack_secret_key'] ?? '');
        set_platform_setting('paystack_mode', $psMode);
        set_platform_setting('paystack_public_key', $psPub);
        // Only update secret if non-empty (prevents blanking it if field left empty)
        if ($psSecret !== '') set_platform_setting('paystack_secret_key', $psSecret);
        log_audit_action($user['id'], 'paystack_settings_updated', "Paystack settings updated — mode: {$psMode}");
        $success = 'Paystack settings saved.';
        $tab = 'settings';

    } elseif ($action === 'save_settings') {
        $prevMode = get_platform_setting('monetization_mode', 'free');
        $mode = $_POST['monetization_mode'] ?? 'free';
        if (!in_array($mode, ['free', 'hybrid', 'paid'], true)) $mode = 'free';
        set_platform_setting('monetization_mode', $mode);
        foreach (['enable_paid_featured_jobs', 'enable_paid_featured_workers', 'enable_paid_verification_badges', 'enable_paid_job_posting', 'enable_paid_worker_service'] as $key) {
            set_platform_setting($key, ($_POST[$key] ?? '0') === '1' ? '1' : '0');
        }
        if ($prevMode !== $mode) {
            log_audit_action($user['id'], 'monetization_updated', "Monetization mode changed from '{$prevMode}' to '{$mode}'");
        } else {
            log_audit_action($user['id'], 'monetization_updated', "Monetization feature toggles updated (mode: '{$mode}')");
        }
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
            'featured_job'   => 'featured_job_packages',
            'featured_worker'=> 'worker_promotion_packages',
            'verification'   => 'verification_packages',
            'job_posting'    => 'job_posting_packages',
            'worker_service' => 'worker_service_packages',
        ];
        $table = $tableMap[$pkgType] ?? '';
        if ($table && $pkgName !== '') {
            if ($pkgType === 'verification') {
                if ($pkgId > 0) {
                    $pdo->prepare("UPDATE $table SET name = ?, price = ?, status = ? WHERE id = ?")
                        ->execute([$pkgName, $pkgPrice, $pkgStatus, $pkgId]);
                } else {
                    $pdo->prepare("INSERT INTO $table (name, price, status) VALUES (?, ?, ?)")
                        ->execute([$pkgName, $pkgPrice, $pkgStatus]);
                }
            } elseif ($pkgType === 'job_posting') {
                $pkgPostCount = intval($_POST['pkg_post_count'] ?? -1);
                $pkgDesc      = trim($_POST['pkg_description'] ?? '') ?: null;
                if ($pkgId > 0) {
                    $pdo->prepare("UPDATE $table SET name = ?, description = ?, post_count = ?, price = ?, status = ? WHERE id = ?")
                        ->execute([$pkgName, $pkgDesc, $pkgPostCount, $pkgPrice, $pkgStatus, $pkgId]);
                } else {
                    $pdo->prepare("INSERT INTO $table (name, description, post_count, price, status) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$pkgName, $pkgDesc, $pkgPostCount, $pkgPrice, $pkgStatus]);
                }
            } elseif ($pkgType === 'worker_service') {
                $pkgDesc = trim($_POST['pkg_description'] ?? '') ?: null;
                if ($pkgId > 0) {
                    $pdo->prepare("UPDATE $table SET name = ?, description = ?, duration_days = ?, price = ?, status = ? WHERE id = ?")
                        ->execute([$pkgName, $pkgDesc, $pkgDays, $pkgPrice, $pkgStatus, $pkgId]);
                } else {
                    $pdo->prepare("INSERT INTO $table (name, description, duration_days, price, status) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$pkgName, $pkgDesc, $pkgDays, $pkgPrice, $pkgStatus]);
                }
            } else {
                if ($pkgId > 0) {
                    $pdo->prepare("UPDATE $table SET name = ?, duration_days = ?, price = ?, status = ? WHERE id = ?")
                        ->execute([$pkgName, $pkgDays, $pkgPrice, $pkgStatus, $pkgId]);
                } else {
                    $pdo->prepare("INSERT INTO $table (name, duration_days, price, status) VALUES (?, ?, ?, ?)")
                        ->execute([$pkgName, $pkgDays, $pkgPrice, $pkgStatus]);
                }
            }
            $op = $pkgId > 0 ? 'Edited' : 'Created';
            log_audit_action($user['id'] ?? 0, $pkgId > 0 ? 'package_edited' : 'package_created', "{$op} {$pkgType} package" . ($pkgId > 0 ? " ID {$pkgId}" : '') . ": '{$pkgName}'");
            $success = 'Package saved.';
        } else {
            $error = 'Package name is required.';
        }
        $tabMap = ['featured_job' => 'featured_jobs', 'featured_worker' => 'featured_workers', 'verification' => 'verification', 'job_posting' => 'job_posting', 'worker_service' => 'worker_service'];
        $tab = $tabMap[$pkgType] ?? 'settings';

    } elseif ($action === 'delete_package') {
        $pkgType = $_POST['pkg_type'] ?? '';
        $pkgId = intval($_POST['pkg_id'] ?? 0);
        $tableMap = [
            'featured_job'   => 'featured_job_packages',
            'featured_worker'=> 'worker_promotion_packages',
            'verification'   => 'verification_packages',
            'job_posting'    => 'job_posting_packages',
            'worker_service' => 'worker_service_packages',
        ];
        $table = $tableMap[$pkgType] ?? '';
        if ($table && $pkgId > 0) {
            $pdo->prepare("DELETE FROM $table WHERE id = ?")->execute([$pkgId]);
            log_audit_action($user['id'] ?? 0, 'package_deleted', "Deleted {$pkgType} package ID {$pkgId}");
            $success = 'Package deleted.';
        }
        $tabMap2 = ['featured_job' => 'featured_jobs', 'featured_worker' => 'featured_workers', 'verification' => 'verification', 'job_posting' => 'job_posting', 'worker_service' => 'worker_service'];
        $tab = $tabMap2[$pkgType] ?? 'settings';

    } elseif ($action === 'confirm_payment') {
        $paymentId     = intval($_POST['payment_id'] ?? 0);
        $paymentMethod = trim($_POST['payment_method'] ?? '');
        $allowedMethods = ['cash', 'mobile_money', 'bank_transfer', 'other'];
        if (!in_array($paymentMethod, $allowedMethods, true)) $paymentMethod = 'other';
        $stmt = $pdo->prepare('SELECT * FROM platform_payments WHERE id = ? AND status = ?');
        $stmt->execute([$paymentId, 'pending']);
        $payment = $stmt->fetch();
        if ($payment && ($payment['gateway'] ?? 'manual') === 'paystack') {
            $error = 'Paystack payments are confirmed automatically. Manual confirmation is not allowed.';
            $tab = 'payments';
        } elseif ($payment) {
            $pdo->prepare('UPDATE platform_payments SET status = ?, paid_at = NOW(), confirmed_by_user_id = ?, payment_method = ? WHERE id = ?')
                ->execute(['paid', $user['id'], $paymentMethod, $paymentId]);
            if ($payment['payment_type'] === 'featured_job' && $payment['reference_id']) {
                $pkg = $pdo->prepare('SELECT duration_days FROM featured_job_packages WHERE id = ?');
                $pkg->execute([$payment['package_id']]);
                $pkg = $pkg->fetch();
                $days = $pkg ? $pkg['duration_days'] : 30;
                $pdo->prepare('UPDATE service_requests SET featured = 1, featured_start_date = CURDATE(), featured_end_date = DATE_ADD(CURDATE(), INTERVAL ? DAY) WHERE id = ?')
                    ->execute([$days, $payment['reference_id']]);
                notify_user($payment['user_id'], 'Job featured', 'Your job has been featured and will appear at the top of listings.', 'success');
                log_audit_action($user['id'], 'payment_confirmed', "Confirmed featured_job payment ref {$payment['reference_code']} (GH₵{$payment['amount']}) for user ID {$payment['user_id']}");
            } elseif ($payment['payment_type'] === 'featured_worker' && $payment['reference_id']) {
                $pkg = $pdo->prepare('SELECT duration_days FROM worker_promotion_packages WHERE id = ?');
                $pkg->execute([$payment['package_id']]);
                $pkg = $pkg->fetch();
                $days = $pkg ? $pkg['duration_days'] : 30;
                $pdo->prepare('UPDATE worker_profiles SET is_featured = 1, featured_start_date = CURDATE(), featured_end_date = DATE_ADD(CURDATE(), INTERVAL ? DAY) WHERE user_id = ?')
                    ->execute([$days, $payment['user_id']]);
                notify_user($payment['user_id'], 'Profile featured', 'Your worker profile is now featured in search results.', 'success');
                log_audit_action($user['id'], 'payment_confirmed', "Confirmed featured_worker payment ref {$payment['reference_code']} (GH₵{$payment['amount']}) for user ID {$payment['user_id']}");
            } elseif ($payment['payment_type'] === 'verification') {
                $pdo->prepare("UPDATE worker_profiles SET is_verified = 1, verification_status = 'approved', verification_date = CURDATE(), verification_expiry = DATE_ADD(CURDATE(), INTERVAL 365 DAY), verification_rejection_reason = NULL WHERE user_id = ?")
                    ->execute([$payment['user_id']]);
                notify_user($payment['user_id'], 'Verification approved', 'Your worker profile is now verified. The ✓erified badge will appear on your profile and search results.', 'success');
                log_audit_action($user['id'], 'payment_confirmed', "Confirmed verification payment ref {$payment['reference_code']} (GH₵{$payment['amount']}) for user ID {$payment['user_id']}");
            } elseif ($payment['payment_type'] === 'job_post' && $payment['reference_id']) {
                $pdo->prepare("UPDATE service_requests SET posting_fee_status = 'paid' WHERE id = ?")
                    ->execute([$payment['reference_id']]);
                // Create bundle credits if post_count > 1
                if ($payment['package_id']) {
                    $pkgInfo = $pdo->prepare('SELECT post_count FROM job_posting_packages WHERE id = ?');
                    $pkgInfo->execute([$payment['package_id']]);
                    $pkgInfo = $pkgInfo->fetch();
                    if ($pkgInfo && $pkgInfo['post_count'] > 1) {
                        $pdo->prepare('INSERT INTO job_post_credits (user_id, payment_id, posts_total, posts_remaining, created_at) VALUES (?, ?, ?, ?, NOW())')
                            ->execute([$payment['user_id'], $payment['id'], $pkgInfo['post_count'], $pkgInfo['post_count'] - 1]);
                    }
                }
                notify_user($payment['user_id'], 'Job posting fee confirmed', 'Your job posting fee has been confirmed. Your job is now submitted for admin review.', 'success');
                log_audit_action($user['id'], 'payment_confirmed', "Confirmed job_post payment ref {$payment['reference_code']} (GH₵{$payment['amount']}) for user ID {$payment['user_id']}, job ID {$payment['reference_id']}");
            } elseif ($payment['payment_type'] === 'worker_service' && $payment['reference_id']) {
                $pkgRow = $pdo->prepare('SELECT duration_days FROM worker_service_packages WHERE id = ?');
                $pkgRow->execute([$payment['package_id']]);
                $pkgRow = $pkgRow->fetch();
                $days = $pkgRow ? (int)$pkgRow['duration_days'] : 30;
                $pdo->prepare("UPDATE worker_profiles SET service_fee_status = 'paid', service_fee_expiry = DATE_ADD(CURDATE(), INTERVAL ? DAY), service_renewal_notice_sent = 0 WHERE id = ?")
                    ->execute([$days, $payment['reference_id']]);
                notify_user($payment['user_id'], 'Service listing confirmed', 'Your worker service listing is now active. You will appear in search results for ' . $days . ' days.', 'success');
                log_audit_action($user['id'], 'payment_confirmed', "Confirmed worker_service payment ref {$payment['reference_code']} (GH₵{$payment['amount']}) for user ID {$payment['user_id']}, worker profile ID {$payment['reference_id']}");
            }
            $success = 'Payment confirmed and feature activated.';
        }
        $tab = 'payments';

    } elseif ($action === 'reject_payment') {
        $paymentId = intval($_POST['payment_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM platform_payments WHERE id = ? AND status = ?');
        $stmt->execute([$paymentId, 'pending']);
        $payment = $stmt->fetch();
        if ($payment && ($payment['gateway'] ?? 'manual') === 'paystack') {
            $error = 'Paystack payments cannot be manually rejected. They resolve automatically.';
            $tab = 'payments';
        } elseif ($payment) {
            $pdo->prepare('UPDATE platform_payments SET status = ? WHERE id = ?')
                ->execute(['failed', $paymentId]);
            $typeLabel = ucwords(str_replace('_', ' ', $payment['payment_type']));
            notify_user($payment['user_id'], 'Payment not confirmed', "Your {$typeLabel} payment (ref {$payment['reference_code']}) could not be confirmed. Please contact support.", 'warning');
            log_audit_action($user['id'], 'payment_rejected', "Rejected payment ref {$payment['reference_code']} (GH₵{$payment['amount']}) type {$payment['payment_type']} for user ID {$payment['user_id']}");
            $success = 'Payment marked as failed.';
        }
        $tab = 'payments';

    } elseif ($action === 'verify_worker_free') {
        $workerId = intval($_POST['worker_user_id'] ?? 0);
        if ($workerId > 0) {
            $pdo->prepare("UPDATE worker_profiles SET is_verified = 1, verification_status = 'approved', verification_date = CURDATE(), verification_expiry = DATE_ADD(CURDATE(), INTERVAL 365 DAY), verification_rejection_reason = NULL WHERE user_id = ?")
                ->execute([$workerId]);
            notify_user($workerId, 'Verification approved', 'Your worker profile is now verified. The ✓erified badge will appear on your profile and search results.', 'success');
            log_audit_action($user['id'] ?? 0, 'worker_verified', "Verified worker user ID {$workerId}");
            $success = 'Worker verified.';
        }
        $tab = 'verification';

    } elseif ($action === 'approve_verification') {
        $workerUserId = intval($_POST['worker_user_id'] ?? 0);
        if ($workerUserId > 0) {
            $pdo->prepare("UPDATE worker_profiles SET is_verified = 1, verification_status = 'approved', verification_date = CURDATE(), verification_expiry = DATE_ADD(CURDATE(), INTERVAL 365 DAY), verification_rejection_reason = NULL WHERE user_id = ?")
                ->execute([$workerUserId]);
            notify_user($workerUserId, 'Verification approved', 'Your worker profile is now verified. The ✓erified badge will appear on your profile and search results.', 'success');
            log_audit_action($user['id'] ?? 0, 'worker_verified', "Verified worker user ID {$workerUserId} (admin approved)");
            $success = 'Worker verified.';
        }
        $tab = 'verification';

    } elseif ($action === 'reject_verification') {
        $workerUserId = intval($_POST['worker_user_id'] ?? 0);
        $reason       = trim($_POST['rejection_reason'] ?? '');
        if ($workerUserId > 0) {
            $pdo->prepare("UPDATE worker_profiles SET verification_status = 'rejected', verification_rejection_reason = ? WHERE user_id = ?")
                ->execute([$reason ?: null, $workerUserId]);
            $msg = 'Your verification request was not approved.' . ($reason ? ' Reason: ' . $reason : ' Please contact support for details.');
            notify_user($workerUserId, 'Verification request rejected', $msg, 'warning');
            log_audit_action($user['id'] ?? 0, 'verification_rejected', "Rejected verification for user ID {$workerUserId}" . ($reason ? " — reason: {$reason}" : ''));
            $success = 'Verification request rejected.';
        }
        $tab = 'verification';

    } elseif ($action === 'request_resubmission') {
        $workerUserId = intval($_POST['worker_user_id'] ?? 0);
        $reason       = trim($_POST['rejection_reason'] ?? '');
        if ($workerUserId > 0) {
            $pdo->prepare("UPDATE worker_profiles SET verification_status = 'resubmission_requested', verification_rejection_reason = ? WHERE user_id = ?")
                ->execute([$reason ?: null, $workerUserId]);
            $msg = 'Your verification documents need updating before we can approve your badge.' . ($reason ? ' Notes: ' . $reason : '') . ' Please resubmit via your profile.';
            notify_user($workerUserId, 'Verification — resubmission requested', $msg, 'warning');
            log_audit_action($user['id'] ?? 0, 'verification_resubmission_requested', "Requested resubmission for verification user ID {$workerUserId}" . ($reason ? " — notes: {$reason}" : ''));
            $success = 'Resubmission requested. Worker notified.';
        }
        $tab = 'verification';

    } elseif ($action === 'unfeature_job') {
        $jobId = intval($_POST['job_id'] ?? 0);
        if ($jobId > 0) {
            $pdo->prepare('UPDATE service_requests SET featured = 0, featured_end_date = CURDATE() WHERE id = ?')
                ->execute([$jobId]);
            log_audit_action($user['id'], 'job_unfeatured', "Removed featured status for job ID {$jobId}");
            $success = 'Job featured status removed.';
        }
        $tab = 'featured_jobs';

    } elseif ($action === 'unfeature_worker') {
        $workerId = intval($_POST['worker_user_id'] ?? 0);
        if ($workerId > 0) {
            $pdo->prepare('UPDATE worker_profiles SET is_featured = 0, featured_end_date = NULL WHERE user_id = ?')
                ->execute([$workerId]);
            log_audit_action($user['id'], 'worker_unfeatured', "Removed featured status for worker user ID {$workerId}");
            $success = 'Featured status removed.';
        }
        $tab = 'featured_workers';

    } elseif ($action === 'revoke_verification') {
        $workerId = intval($_POST['worker_user_id'] ?? 0);
        if ($workerId > 0) {
            $pdo->prepare("UPDATE worker_profiles SET is_verified = 0, verification_status = 'none', verification_date = NULL, verification_expiry = NULL, verification_rejection_reason = NULL WHERE user_id = ?")
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

$msgFlash = $_GET['msg'] ?? '';
$errFlash = $_GET['err'] ?? '';

// Revenue filter params (GET, only affect the payments tab)
$filterFrom   = trim($_GET['filter_from'] ?? '');
$filterTo     = trim($_GET['filter_to'] ?? '');
$filterType   = trim($_GET['filter_type'] ?? '');
$filterSearch = trim($_GET['filter_search'] ?? '');

$monetizationMode = get_platform_setting('monetization_mode', 'free');
$enableFeaturedJobs = get_platform_setting('enable_paid_featured_jobs', '0');
$enableFeaturedWorkers = get_platform_setting('enable_paid_featured_workers', '0');
$enableVerification = get_platform_setting('enable_paid_verification_badges', '0');
$enablePaidJobPosting = get_platform_setting('enable_paid_job_posting', '0');
$enablePaidWorkerService = get_platform_setting('enable_paid_worker_service', '0');

$psMode    = get_platform_setting('paystack_mode', 'test');
$psPubKey  = get_platform_setting('paystack_public_key', '');
$psConfigured = get_platform_setting('paystack_secret_key', '') !== '';

$featuredJobPackages = get_active_packages('featured_job_packages');
$allFeaturedJobPackages = $pdo->query("SELECT * FROM featured_job_packages ORDER BY price ASC")->fetchAll();
$workerPromoPackages = get_active_packages('worker_promotion_packages');
$allWorkerPromoPackages = $pdo->query("SELECT * FROM worker_promotion_packages ORDER BY price ASC")->fetchAll();
$verificationPackages = get_active_packages('verification_packages');
$allVerificationPackages = $pdo->query("SELECT * FROM verification_packages ORDER BY price ASC")->fetchAll();
$allJobPostingPackages = $pdo->query("SELECT * FROM job_posting_packages ORDER BY price ASC")->fetchAll();
$allWorkerServicePackages = $pdo->query("SELECT * FROM worker_service_packages ORDER BY price ASC")->fetchAll();

// Pending payments with context
$pendingPayments = $pdo->query("
    SELECT pp.*, u.name AS user_name, u.username,
        COALESCE(pp.gateway, 'manual') AS gateway,
        sr.title AS job_title,
        CASE pp.payment_type
            WHEN 'featured_job'   THEN COALESCE(fp.name, '—')
            WHEN 'featured_worker'THEN COALESCE(wp2.name, '—')
            WHEN 'verification'   THEN COALESCE(vp.name, '—')
            WHEN 'job_post'       THEN COALESCE(jp.name, '—')
            WHEN 'worker_service' THEN COALESCE(ws.name, '—')
        END AS package_name
    FROM platform_payments pp
    JOIN users u ON pp.user_id = u.id
    LEFT JOIN service_requests sr ON pp.payment_type IN ('featured_job','job_post') AND sr.id = pp.reference_id
    LEFT JOIN featured_job_packages fp ON pp.payment_type = 'featured_job' AND fp.id = pp.package_id
    LEFT JOIN worker_promotion_packages wp2 ON pp.payment_type = 'featured_worker' AND wp2.id = pp.package_id
    LEFT JOIN verification_packages vp ON pp.payment_type = 'verification' AND vp.id = pp.package_id
    LEFT JOIN job_posting_packages jp ON pp.payment_type = 'job_post' AND jp.id = pp.package_id
    LEFT JOIN worker_service_packages ws ON pp.payment_type = 'worker_service' AND ws.id = pp.package_id
    WHERE pp.status = 'pending'
    ORDER BY pp.created_at DESC
")->fetchAll();

// Build filterable WHERE conditions (apply to revenue summary and payment history)
$revWhere  = [];
$revParams = [];
if ($filterFrom)   { $revWhere[] = "DATE(pp.created_at) >= ?"; $revParams[] = $filterFrom; }
if ($filterTo)     { $revWhere[] = "DATE(pp.created_at) <= ?"; $revParams[] = $filterTo; }
if ($filterType)   { $revWhere[] = "pp.payment_type = ?";      $revParams[] = $filterType; }
if ($filterSearch) { $revWhere[] = "(u.name LIKE ? OR u.username LIKE ? OR pp.reference_code LIKE ?)"; $revParams[] = "%{$filterSearch}%"; $revParams[] = "%{$filterSearch}%"; $revParams[] = "%{$filterSearch}%"; }
$revWhereSQL = $revWhere ? 'AND ' . implode(' AND ', $revWhere) : '';

// Revenue summary (filtered; pending counts excluded when a type filter is active since we want just that type's pending too)
$revSumParams = array_filter([$filterFrom ?: null, $filterFrom ? $filterFrom : null], fn($v) => $v !== null);
$revSumWhere  = [];
$revSumParams = [];
if ($filterFrom) { $revSumWhere[] = "DATE(created_at) >= ?"; $revSumParams[] = $filterFrom; }
if ($filterTo)   { $revSumWhere[] = "DATE(created_at) <= ?"; $revSumParams[] = $filterTo; }
if ($filterType) { $revSumWhere[] = "payment_type = ?";      $revSumParams[] = $filterType; }
$revSumWhereSQL = $revSumWhere ? 'WHERE ' . implode(' AND ', $revSumWhere) : '';

$revStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) AS total_paid,
        COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS total_pending,
        COUNT(CASE WHEN status = 'paid' THEN 1 END) AS count_paid,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) AS count_pending,
        COALESCE(SUM(CASE WHEN payment_type = 'featured_job' AND status = 'paid' THEN amount ELSE 0 END), 0) AS featured_job_revenue,
        COALESCE(SUM(CASE WHEN payment_type = 'featured_worker' AND status = 'paid' THEN amount ELSE 0 END), 0) AS featured_worker_revenue,
        COALESCE(SUM(CASE WHEN payment_type = 'verification' AND status = 'paid' THEN amount ELSE 0 END), 0) AS verification_revenue,
        COALESCE(SUM(CASE WHEN payment_type = 'job_post' AND status = 'paid' THEN amount ELSE 0 END), 0) AS job_post_revenue,
        COALESCE(SUM(CASE WHEN payment_type = 'worker_service' AND status = 'paid' THEN amount ELSE 0 END), 0) AS worker_service_revenue
    FROM platform_payments $revSumWhereSQL
");
$revStmt->execute($revSumParams);
$revenueSummary = $revStmt->fetch();

// Full payment history (paid + failed, with filters)
$histStmt = $pdo->prepare("
    SELECT pp.*, u.name AS user_name, u.username, sr.title AS job_title
    FROM platform_payments pp
    JOIN users u ON pp.user_id = u.id
    LEFT JOIN service_requests sr ON pp.payment_type IN ('featured_job','job_post') AND sr.id = pp.reference_id
    WHERE pp.status IN ('paid', 'failed') {$revWhereSQL}
    ORDER BY pp.created_at DESC LIMIT 200
");
$histStmt->execute($revParams);
$paymentHistory = $histStmt->fetchAll();

// Currently active featured jobs
$activeFeaturedJobs = $pdo->query("
    SELECT sr.id, sr.title, sr.location, sr.featured_start_date, sr.featured_end_date,
           u.name AS customer_name, u.username AS customer_username
    FROM service_requests sr
    JOIN users u ON sr.customer_id = u.id
    WHERE sr.featured = 1 AND (sr.featured_end_date IS NULL OR sr.featured_end_date >= CURDATE())
    ORDER BY sr.featured_end_date ASC
")->fetchAll();

// Currently active featured workers
$activeFeaturedWorkers = $pdo->query("
    SELECT u.id, u.name, u.username, wp.featured_start_date, wp.featured_end_date
    FROM users u JOIN worker_profiles wp ON u.id = wp.user_id
    WHERE wp.is_featured = 1 AND (wp.featured_end_date IS NULL OR wp.featured_end_date >= CURDATE())
    ORDER BY wp.featured_end_date ASC
")->fetchAll();

$allWorkers = $pdo->query("SELECT u.id, u.name, u.username, wp.is_verified, wp.verification_status, wp.verification_date, wp.verification_expiry, wp.verification_rejection_reason FROM users u JOIN worker_profiles wp ON u.id = wp.user_id WHERE u.role = 'worker' AND u.banned = 0 ORDER BY wp.is_verified ASC, u.name ASC")->fetchAll();

$pendingVerificationPayments = $pdo->query("
    SELECT
        wp.user_id,
        u.name AS user_name, u.username,
        wp.id_type, wp.id_number, wp.id_document_path, wp.contact_phone,
        pp.id AS payment_id,
        pp.amount,
        pp.reference_code,
        pp.created_at,
        COALESCE(pp.gateway, 'manual') AS gateway
    FROM worker_profiles wp
    JOIN users u ON wp.user_id = u.id
    LEFT JOIN platform_payments pp ON pp.id = (
        SELECT pp2.id FROM platform_payments pp2
        WHERE pp2.user_id = wp.user_id
          AND pp2.payment_type = 'verification'
          AND pp2.status IN ('paid', 'pending')
        ORDER BY pp2.id DESC LIMIT 1
    )
    WHERE wp.verification_status = 'pending'
    ORDER BY COALESCE(pp.created_at, NOW()) ASC
")->fetchAll();
$pendingVerificationUserIds = array_column($pendingVerificationPayments, 'user_id');

$auditLogs = $pdo->query("SELECT al.*, COALESCE(u.name, 'System') AS admin_name FROM audit_logs al LEFT JOIN users u ON al.admin_id = u.id ORDER BY al.created_at DESC LIMIT 50")->fetchAll();
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
            <button class="mono-tab <?php echo $tab === 'job_posting' ? 'active' : ''; ?>" data-tab="job_posting">Job Posting Pkgs</button>
            <button class="mono-tab <?php echo $tab === 'worker_service' ? 'active' : ''; ?>" data-tab="worker_service">Listing Pkgs</button>
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
                    <div id="feature-toggles-wrapper" style="margin-top: 24px;">
                        <h2>Individual feature settings <span class="meta">(apply in Hybrid Mode only)</span></h2>
                        <div id="feature-toggles-overlay" style="display:none; padding: 12px; border-radius: 8px; background: var(--surface); border: 1px solid var(--border); margin-bottom: 12px; color: var(--text-muted); font-size: 0.9rem;"></div>
                        <table class="pkg-table" id="feature-toggles-table">
                            <thead><tr><th>Feature</th><th>Status (Hybrid Mode)</th></tr></thead>
                            <tbody>
                                <tr>
                                    <td><strong>Featured Job Posts</strong><br><span class="meta">Charge users to feature their job posts</span></td>
                                    <td>
                                        <label style="margin-right:16px;"><input type="radio" name="enable_paid_featured_jobs" value="0" <?php echo !$enableFeaturedJobs ? 'checked' : ''; ?>> Free</label>
                                        <label><input type="radio" name="enable_paid_featured_jobs" value="1" <?php echo $enableFeaturedJobs ? 'checked' : ''; ?>> Paid</label>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Featured Worker Profiles</strong><br><span class="meta">Charge workers to appear at top of search</span></td>
                                    <td>
                                        <label style="margin-right:16px;"><input type="radio" name="enable_paid_featured_workers" value="0" <?php echo !$enableFeaturedWorkers ? 'checked' : ''; ?>> Free</label>
                                        <label><input type="radio" name="enable_paid_featured_workers" value="1" <?php echo $enableFeaturedWorkers ? 'checked' : ''; ?>> Paid</label>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Verification Badges</strong><br><span class="meta">Charge workers for the verified badge</span></td>
                                    <td>
                                        <label style="margin-right:16px;"><input type="radio" name="enable_paid_verification_badges" value="0" <?php echo !$enableVerification ? 'checked' : ''; ?>> Free</label>
                                        <label><input type="radio" name="enable_paid_verification_badges" value="1" <?php echo $enableVerification ? 'checked' : ''; ?>> Paid</label>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Job Posting</strong><br><span class="meta">Charge customers a fee to post a job</span></td>
                                    <td>
                                        <label style="margin-right:16px;"><input type="radio" name="enable_paid_job_posting" value="0" <?php echo !$enablePaidJobPosting ? 'checked' : ''; ?>> Free</label>
                                        <label><input type="radio" name="enable_paid_job_posting" value="1" <?php echo $enablePaidJobPosting ? 'checked' : ''; ?>> Paid</label>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Worker Service Listing</strong><br><span class="meta">Charge workers to appear in search results</span></td>
                                    <td>
                                        <label style="margin-right:16px;"><input type="radio" name="enable_paid_worker_service" value="0" <?php echo !$enablePaidWorkerService ? 'checked' : ''; ?>> Free</label>
                                        <label><input type="radio" name="enable_paid_worker_service" value="1" <?php echo $enablePaidWorkerService ? 'checked' : ''; ?>> Paid</label>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <button type="submit" class="button button-primary" style="margin-top: 16px;">Save settings</button>
                </form>
            </section>

            <!-- Paystack settings -->
            <section class="panel">
                <h2>Paystack Payment Gateway</h2>
                <?php if ($psConfigured): ?>
                    <div class="alert alert-success" style="margin-bottom:12px;">
                        ✓ Paystack is configured (<?php echo $psMode === 'live' ? '<strong style="color:#22a06b;">Live mode</strong>' : '<strong style="color:#f59e0b;">Test/Sandbox mode</strong>'; ?>).
                        Payments from users will be processed through Paystack automatically.
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning" style="margin-bottom:12px;">
                        ⚠️ Paystack secret key not set. Paid features will show an error to users until you configure it.
                    </div>
                <?php endif; ?>
                <p class="meta">Get your keys from <a href="https://dashboard.paystack.com/#/settings/developer" target="_blank" rel="noopener">Paystack Dashboard → Settings → API Keys & Webhooks</a>.<br>
                Set webhook URL to: <code><?php echo sanitize(rtrim(BASE_URL, '/') . '/paystack_webhook.php'); ?></code></p>
                <form method="post" action="monetization.php">
                    <input type="hidden" name="action" value="save_paystack_settings" />
                    <div style="margin-bottom:14px;">
                        <label><strong>Environment</strong></label>
                        <div style="display:flex;gap:16px;margin-top:6px;">
                            <label><input type="radio" name="paystack_mode" value="test" <?php echo $psMode !== 'live' ? 'checked' : ''; ?>> Test / Sandbox</label>
                            <label><input type="radio" name="paystack_mode" value="live" <?php echo $psMode === 'live' ? 'checked' : ''; ?>> Live (real money)</label>
                        </div>
                    </div>
                    <label>Public key <span class="meta">(pk_test_... or pk_live_...)</span></label>
                    <input type="text" name="paystack_public_key" value="<?php echo sanitize($psPubKey); ?>" placeholder="pk_test_xxxxxxxxxxxxxxxx" autocomplete="off" />
                    <label>Secret key <span class="meta">(leave blank to keep existing)</span></label>
                    <input type="password" name="paystack_secret_key" placeholder="sk_test_xxxxxxxxxxxxxxxx — leave blank to keep current" autocomplete="new-password" />
                    <button type="submit" class="button button-primary" style="margin-top:12px;">Save Paystack settings</button>
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
            <?php if (!empty($activeFeaturedJobs)): ?>
            <section class="panel">
                <h2 style="margin-top:0;">Currently Featured Jobs <span class="meta">(<?php echo count($activeFeaturedJobs); ?> active)</span></h2>
                <table class="pkg-table">
                    <thead><tr><th>Job</th><th>Posted by</th><th>Location</th><th>Featured from</th><th>Expires</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($activeFeaturedJobs as $fj): ?>
                            <tr>
                                <td><a href="../request_detail.php?id=<?php echo $fj['id']; ?>" style="color:var(--primary);"><?php echo sanitize(substr($fj['title'], 0, 40)); ?></a></td>
                                <td><?php echo sanitize($fj['customer_username'] ?: $fj['customer_name']); ?></td>
                                <td><?php echo sanitize($fj['location'] ?: '—'); ?></td>
                                <td><?php echo sanitize($fj['featured_start_date'] ?: '—'); ?></td>
                                <td><?php echo $fj['featured_end_date'] ? sanitize($fj['featured_end_date']) : '∞'; ?></td>
                                <td>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Remove featured status for this job?')">
                                        <input type="hidden" name="action" value="unfeature_job" />
                                        <input type="hidden" name="job_id" value="<?php echo $fj['id']; ?>" />
                                        <button type="submit" class="button button-small button-secondary">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
            <?php endif; ?>
        </div>

        <!-- FEATURED WORKER PACKAGES TAB -->
        <div class="tab-panel <?php echo $tab === 'featured_workers' ? 'active' : ''; ?>" id="tab-featured_workers">
            <?php if (!empty($activeFeaturedWorkers)): ?>
            <section class="panel" style="margin-bottom:16px;">
                <h2 style="margin-top:0;">Currently Featured Workers <span class="meta">(<?php echo count($activeFeaturedWorkers); ?> active)</span></h2>
                <table class="pkg-table">
                    <thead><tr><th>Worker</th><th>Featured from</th><th>Expires</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($activeFeaturedWorkers as $fw): ?>
                            <tr>
                                <td><a href="../worker_profile_public.php?id=<?php echo $fw['id']; ?>" style="color:var(--primary);"><?php echo sanitize($fw['username'] ?: $fw['name']); ?></a><br><span class="meta"><?php echo sanitize($fw['name']); ?></span></td>
                                <td><?php echo sanitize($fw['featured_start_date'] ?: '—'); ?></td>
                                <td><?php echo $fw['featured_end_date'] ? sanitize($fw['featured_end_date']) : '∞'; ?></td>
                                <td>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Remove featured status for this worker?')">
                                        <input type="hidden" name="action" value="unfeature_worker" />
                                        <input type="hidden" name="worker_user_id" value="<?php echo $fw['id']; ?>" />
                                        <button type="submit" class="button button-small button-secondary">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
            <?php endif; ?>
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
                <?php if (!empty($pendingVerificationPayments)): ?>
                    <h3 style="margin-top: 24px;">Pending Verification Requests <span class="badge" style="background:var(--primary);color:#fff;"><?php echo count($pendingVerificationPayments); ?></span></h3>
                    <p class="meta">Review each worker's ID documents, then approve, reject, or request resubmission.</p>
                    <?php foreach ($pendingVerificationPayments as $vp): ?>
                        <div class="panel" style="border:1px solid var(--border);margin-bottom:16px;padding:16px;">
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;">
                                <div>
                                    <strong><?php echo sanitize(display_name($vp)); ?></strong>
                                    <?php if (!empty($vp['reference_code'])): ?>
                                        <span class="meta" style="margin-left:8px;">Ref: <code><?php echo sanitize($vp['reference_code']); ?></code></span>
                                    <?php endif; ?>
                                    <?php if (!empty($vp['amount'])): ?>
                                        <span class="meta" style="margin-left:8px;">GH₵ <?php echo number_format($vp['amount'], 2); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($vp['gateway']) && $vp['gateway'] === 'paystack'): ?>
                                        <span class="badge" style="margin-left:6px;background:#0ba4db;color:#fff;font-size:0.75rem;padding:2px 7px;border-radius:20px;">🔒 Paystack</span>
                                    <?php endif; ?>
                                    <span class="meta" style="margin-left:8px;"><?php echo sanitize($vp['created_at'] ?? ''); ?></span>
                                </div>
                                <?php if ($vp['contact_phone']): ?>
                                    <span class="meta">📞 <?php echo sanitize($vp['contact_phone']); ?></span>
                                <?php endif; ?>
                            </div>
                            <div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start;">
                                <div>
                                    <p class="meta" style="margin:0 0 4px;">ID Type</p>
                                    <strong><?php echo sanitize($vp['id_type'] ? strtoupper(str_replace('_', ' ', $vp['id_type'])) : '—'); ?></strong>
                                </div>
                                <div>
                                    <p class="meta" style="margin:0 0 4px;">ID Number</p>
                                    <strong><?php echo sanitize($vp['id_number'] ?: '—'); ?></strong>
                                </div>
                                <?php if ($vp['id_document_path']): ?>
                                    <div>
                                        <p class="meta" style="margin:0 0 4px;">Document</p>
                                        <a href="../<?php echo sanitize($vp['id_document_path']); ?>" target="_blank">
                                            <img src="../<?php echo sanitize($vp['id_document_path']); ?>" alt="ID Document" style="height:80px;width:auto;border-radius:6px;border:1px solid var(--border);object-fit:cover;" />
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div style="margin-top:12px;display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;">
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="action" value="approve_verification" />
                                    <input type="hidden" name="worker_user_id" value="<?php echo $vp['user_id']; ?>" />
                                    <button type="submit" class="button button-small button-primary">✓ Approve + verify</button>
                                </form>
                                <form method="post" class="inline-form" style="display:flex;gap:6px;align-items:flex-end;">
                                    <input type="hidden" name="action" value="reject_verification" />
                                    <input type="hidden" name="worker_user_id" value="<?php echo $vp['user_id']; ?>" />
                                    <input type="text" name="rejection_reason" placeholder="Rejection reason (optional)" style="font-size:0.85rem;padding:5px 8px;border:1px solid var(--border);border-radius:6px;width:220px;" />
                                    <button type="submit" class="button button-small button-secondary" onclick="return confirm('Reject this verification request?')">✗ Reject</button>
                                </form>
                                <form method="post" class="inline-form" style="display:flex;gap:6px;align-items:flex-end;">
                                    <input type="hidden" name="action" value="request_resubmission" />
                                    <input type="hidden" name="worker_user_id" value="<?php echo $vp['user_id']; ?>" />
                                    <input type="text" name="rejection_reason" placeholder="What to fix (optional)" style="font-size:0.85rem;padding:5px 8px;border:1px solid var(--border);border-radius:6px;width:220px;" />
                                    <button type="submit" class="button button-small button-secondary">↩ Request resubmission</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <h3 style="margin-top: 24px;">Worker Verification Status</h3>
                <table class="pkg-table">
                    <thead><tr><th>Worker</th><th>Status</th><th>Expires</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($allWorkers as $w): ?>
                            <?php
                                $hasPendingPayment = in_array($w['id'], $pendingVerificationUserIds, true);
                                $vstatus = $w['verification_status'] ?? ($w['is_verified'] ? 'approved' : 'none');
                                $vstatusLabels = ['none'=>'Unverified','pending'=>'Pending','approved'=>'Verified ✓','rejected'=>'Rejected','resubmission_requested'=>'Resubmission needed','expired'=>'Expired'];
                                $vstatusColors = ['none'=>'#888','pending'=>'#f59e0b','approved'=>'#22a06b','rejected'=>'#ef4444','resubmission_requested'=>'#f59e0b','expired'=>'#ef4444'];
                            ?>
                            <tr>
                                <td>
                                    <?php echo sanitize(display_name($w)); ?>
                                    <span class="meta">(<?php echo sanitize($w['name']); ?>)</span>
                                    <?php if ($hasPendingPayment): ?>
                                        <span class="badge" style="background:#f59e0b;color:#fff;font-size:0.75rem;margin-left:6px;">Payment pending</span>
                                    <?php endif; ?>
                                </td>
                                <td><span style="color:<?php echo $vstatusColors[$vstatus] ?? '#888'; ?>;font-weight:600;"><?php echo sanitize($vstatusLabels[$vstatus] ?? ucfirst($vstatus)); ?></span>
                                    <?php if (!empty($w['verification_rejection_reason'])): ?>
                                        <br><span class="meta" style="font-size:0.8rem;">Reason: <?php echo sanitize($w['verification_rejection_reason']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $w['verification_expiry'] ? sanitize($w['verification_expiry']) : '—'; ?></td>
                                <td>
                                    <?php if (!$w['is_verified']): ?>
                                        <form method="post" class="inline-form">
                                            <input type="hidden" name="action" value="verify_worker_free" />
                                            <input type="hidden" name="worker_user_id" value="<?php echo $w['id']; ?>" />
                                            <button type="submit" class="button button-small button-primary">Verify now</button>
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

        <!-- JOB POSTING PACKAGES TAB -->
        <div class="tab-panel <?php echo $tab === 'job_posting' ? 'active' : ''; ?>" id="tab-job_posting">
            <section class="panel">
                <h2>Job Posting Packages</h2>
                <table class="pkg-table">
                    <thead><tr><th>Name</th><th>Post count</th><th>Price (GH₵)</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($allJobPostingPackages as $pkg): ?>
                            <tr>
                                <td><?php echo sanitize($pkg['name']); ?></td>
                                <td><?php echo $pkg['post_count'] == -1 ? 'Unlimited' : $pkg['post_count']; ?></td>
                                <td><?php echo number_format($pkg['price'], 2); ?></td>
                                <td><span class="status status-<?php echo $pkg['status'] === 'active' ? 'open' : 'cancelled'; ?>"><?php echo strtoupper($pkg['status']); ?></span></td>
                                <td>
                                    <button class="button button-small button-secondary" onclick="editJobPostPkg(<?php echo $pkg['id']; ?>, '<?php echo sanitize($pkg['name']); ?>', '<?php echo sanitize($pkg['description'] ?? ''); ?>', <?php echo $pkg['post_count']; ?>, <?php echo $pkg['price']; ?>, '<?php echo $pkg['status']; ?>')">Edit</button>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this package?')">
                                        <input type="hidden" name="action" value="delete_package" />
                                        <input type="hidden" name="pkg_type" value="job_posting" />
                                        <input type="hidden" name="pkg_id" value="<?php echo $pkg['id']; ?>" />
                                        <button type="submit" class="button button-small button-secondary">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <h3 style="margin-top: 20px;">Add / Edit Package</h3>
                <form method="post" action="monetization.php" id="form-job_posting">
                    <input type="hidden" name="action" value="save_package" />
                    <input type="hidden" name="pkg_type" value="job_posting" />
                    <input type="hidden" name="pkg_id" id="job_posting_id" value="0" />
                    <label>Package name</label>
                    <input type="text" name="pkg_name" id="job_posting_name" required placeholder="e.g. Single Post" />
                    <label>Description <span class="meta">(shown to customers — optional)</span></label>
                    <textarea name="pkg_description" id="job_posting_description" rows="2" placeholder="e.g. Post one job to the marketplace. Credits never expire." style="resize:vertical;"></textarea>
                    <label>Post count (-1 for unlimited)</label>
                    <input type="number" name="pkg_post_count" id="job_posting_post_count" required value="1" min="-1" />
                    <label>Price (GH₵)</label>
                    <input type="number" name="pkg_price" id="job_posting_price" required min="0" step="0.01" value="0" />
                    <label>Status</label>
                    <select name="pkg_status" id="job_posting_status"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                    <div style="display:flex;gap:8px;margin-top:8px;">
                        <button type="submit" class="button button-primary">Save package</button>
                        <button type="button" class="button button-secondary" onclick="document.getElementById('job_posting_id').value=0; document.getElementById('form-job_posting').reset();">Clear</button>
                    </div>
                </form>
            </section>
        </div>

        <!-- WORKER SERVICE LISTING PACKAGES TAB -->
        <div class="tab-panel <?php echo $tab === 'worker_service' ? 'active' : ''; ?>" id="tab-worker_service">
            <section class="panel">
                <h2>Worker Service Listing Packages</h2>
                <table class="pkg-table">
                    <thead><tr><th>Name</th><th>Duration</th><th>Price (GH₵)</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($allWorkerServicePackages as $pkg): ?>
                            <tr>
                                <td><?php echo sanitize($pkg['name']); ?></td>
                                <td><?php echo $pkg['duration_days']; ?> days</td>
                                <td><?php echo number_format($pkg['price'], 2); ?></td>
                                <td><span class="status status-<?php echo $pkg['status'] === 'active' ? 'open' : 'cancelled'; ?>"><?php echo strtoupper($pkg['status']); ?></span></td>
                                <td>
                                    <button class="button button-small button-secondary" onclick="editPackage('worker_service', <?php echo $pkg['id']; ?>, '<?php echo sanitize($pkg['name']); ?>', '<?php echo sanitize($pkg['description'] ?? ''); ?>', <?php echo $pkg['duration_days']; ?>, <?php echo $pkg['price']; ?>, '<?php echo $pkg['status']; ?>')">Edit</button>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this package?')">
                                        <input type="hidden" name="action" value="delete_package" />
                                        <input type="hidden" name="pkg_type" value="worker_service" />
                                        <input type="hidden" name="pkg_id" value="<?php echo $pkg['id']; ?>" />
                                        <button type="submit" class="button button-small button-secondary">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <h3 style="margin-top: 20px;">Add / Edit Package</h3>
                <form method="post" action="monetization.php" id="form-worker_service">
                    <input type="hidden" name="action" value="save_package" />
                    <input type="hidden" name="pkg_type" value="worker_service" />
                    <input type="hidden" name="pkg_id" id="worker_service_id" value="0" />
                    <label>Package name</label>
                    <input type="text" name="pkg_name" id="worker_service_name" required placeholder="e.g. Monthly Listing" />
                    <label>Description <span class="meta">(shown to workers — optional)</span></label>
                    <textarea name="pkg_description" id="worker_service_description" rows="2" placeholder="e.g. Appear in Find Workers for 30 days. Renew anytime." style="resize:vertical;"></textarea>
                    <label>Duration (days)</label>
                    <input type="number" name="pkg_days" id="worker_service_days" required min="1" value="30" />
                    <label>Price (GH₵)</label>
                    <input type="number" name="pkg_price" id="worker_service_price" required min="0" step="0.01" value="0" />
                    <label>Status</label>
                    <select name="pkg_status" id="worker_service_status"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                    <div style="display:flex;gap:8px;margin-top:8px;">
                        <button type="submit" class="button button-primary">Save package</button>
                        <button type="button" class="button button-secondary" onclick="resetForm('worker_service')">Clear</button>
                    </div>
                </form>
            </section>
        </div>

        <!-- PENDING PAYMENTS TAB -->
        <div class="tab-panel <?php echo $tab === 'payments' ? 'active' : ''; ?>" id="tab-payments">

            <!-- Revenue filters -->
            <?php $filtersActive = $filterFrom || $filterTo || $filterType || $filterSearch; ?>
            <section class="panel" style="margin-bottom:12px;padding:14px 16px;">
                <form method="get" action="monetization.php" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
                    <input type="hidden" name="tab" value="payments" />
                    <div style="display:flex;flex-direction:column;gap:4px;">
                        <label style="font-size:0.82rem;font-weight:600;">From</label>
                        <input type="date" name="filter_from" value="<?php echo sanitize($filterFrom); ?>" style="padding:6px 10px;font-size:0.9rem;" />
                    </div>
                    <div style="display:flex;flex-direction:column;gap:4px;">
                        <label style="font-size:0.82rem;font-weight:600;">To</label>
                        <input type="date" name="filter_to" value="<?php echo sanitize($filterTo); ?>" style="padding:6px 10px;font-size:0.9rem;" />
                    </div>
                    <div style="display:flex;flex-direction:column;gap:4px;">
                        <label style="font-size:0.82rem;font-weight:600;">Type</label>
                        <select name="filter_type" style="padding:6px 10px;font-size:0.9rem;">
                            <option value="">All types</option>
                            <option value="featured_job"   <?php echo $filterType === 'featured_job'    ? 'selected' : ''; ?>>Featured Job</option>
                            <option value="featured_worker"<?php echo $filterType === 'featured_worker' ? 'selected' : ''; ?>>Featured Worker</option>
                            <option value="verification"   <?php echo $filterType === 'verification'    ? 'selected' : ''; ?>>Verification</option>
                            <option value="job_post"       <?php echo $filterType === 'job_post'        ? 'selected' : ''; ?>>Job Posting</option>
                            <option value="worker_service" <?php echo $filterType === 'worker_service'  ? 'selected' : ''; ?>>Service Listing</option>
                        </select>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:4px;">
                        <label style="font-size:0.82rem;font-weight:600;">Search user / ref</label>
                        <input type="text" name="filter_search" value="<?php echo sanitize($filterSearch); ?>" placeholder="name, username, or ref code" style="padding:6px 10px;font-size:0.9rem;min-width:190px;" />
                    </div>
                    <button type="submit" class="button button-primary button-small">Apply</button>
                    <?php if ($filtersActive): ?>
                        <a href="monetization.php?tab=payments" class="button button-secondary button-small">Clear</a>
                    <?php endif; ?>
                </form>
                <?php if ($filtersActive): ?>
                    <p class="meta" style="margin-top:8px;">
                        Showing filtered results
                        <?php if ($filterFrom || $filterTo): ?>— <?php echo $filterFrom ?: '…'; ?> to <?php echo $filterTo ?: 'now'; ?><?php endif; ?>
                        <?php if ($filterType): ?>— type: <strong><?php echo sanitize(ucwords(str_replace('_', ' ', $filterType))); ?></strong><?php endif; ?>
                        <?php if ($filterSearch): ?>— search: <strong><?php echo sanitize($filterSearch); ?></strong><?php endif; ?>
                    </p>
                <?php endif; ?>
            </section>

            <!-- Revenue summary -->
            <section class="panel" style="margin-bottom:16px;">
                <h2 style="margin-top:0;">Revenue Overview<?php if ($filtersActive): ?> <span class="meta" style="font-size:0.85rem;">(filtered)</span><?php endif; ?></h2>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:16px;">
                    <div class="stat-card"><h2>GH₵ <?php echo number_format($revenueSummary['total_paid'], 2); ?></h2><p>Total confirmed</p></div>
                    <div class="stat-card"><h2>GH₵ <?php echo number_format($revenueSummary['total_pending'], 2); ?></h2><p>Awaiting confirmation</p></div>
                    <div class="stat-card"><h2><?php echo (int)$revenueSummary['count_paid']; ?></h2><p>Paid transactions</p></div>
                    <div class="stat-card"><h2><?php echo (int)$revenueSummary['count_pending']; ?></h2><p>Pending</p></div>
                </div>
                <?php if (!$filterType): ?>
                <table class="pkg-table">
                    <thead><tr><th>Feature</th><th>Confirmed Revenue</th></tr></thead>
                    <tbody>
                        <tr><td>Featured Job Posts</td><td>GH₵ <?php echo number_format($revenueSummary['featured_job_revenue'], 2); ?></td></tr>
                        <tr><td>Featured Worker Profiles</td><td>GH₵ <?php echo number_format($revenueSummary['featured_worker_revenue'], 2); ?></td></tr>
                        <tr><td>Verification Badges</td><td>GH₵ <?php echo number_format($revenueSummary['verification_revenue'], 2); ?></td></tr>
                        <tr><td>Job Posting Fees</td><td>GH₵ <?php echo number_format($revenueSummary['job_post_revenue'], 2); ?></td></tr>
                        <tr><td>Worker Service Listings</td><td>GH₵ <?php echo number_format($revenueSummary['worker_service_revenue'], 2); ?></td></tr>
                    </tbody>
                </table>
                <?php endif; ?>
            </section>

            <!-- Pending payments -->
            <section class="panel" style="margin-bottom:16px;">
                <h2 style="margin-top:0;">Pending Payments <?php if ($pendingPayments): ?><span style="background:var(--primary);color:#fff;border-radius:10px;padding:1px 8px;font-size:0.82rem;margin-left:6px;"><?php echo count($pendingPayments); ?></span><?php endif; ?></h2>
                <p class="meta">Manual payments: confirm once received. Paystack payments are confirmed automatically — no action needed.</p>
                <?php if (empty($pendingPayments)): ?>
                    <div class="empty-state">No pending payments.</div>
                <?php else: ?>
                    <table class="pkg-table">
                        <thead><tr><th>User</th><th>For</th><th>Package</th><th>Amount</th><th>Reference</th><th>Gateway</th><th>Submitted</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($pendingPayments as $pay): ?>
                                <?php $payIsPaystack = ($pay['gateway'] ?? 'manual') === 'paystack'; ?>
                                <tr>
                                    <td><?php echo sanitize(display_name($pay)); ?><br><span class="meta"><?php echo sanitize($pay['user_name']); ?></span></td>
                                    <td>
                                        <strong><?php echo sanitize(ucwords(str_replace('_', ' ', $pay['payment_type']))); ?></strong>
                                        <?php if ($pay['job_title']): ?>
                                            <br><span class="meta">📋 <?php echo sanitize(substr($pay['job_title'], 0, 35)); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo sanitize($pay['package_name']); ?></td>
                                    <td><strong>GH₵ <?php echo number_format($pay['amount'], 2); ?></strong></td>
                                    <td><code><?php echo sanitize($pay['reference_code'] ?: '—'); ?></code></td>
                                    <td>
                                        <?php if ($payIsPaystack): ?>
                                            <span style="display:inline-flex;align-items:center;gap:4px;background:#00c3f7;color:#fff;border-radius:4px;padding:2px 7px;font-size:0.78rem;font-weight:600;">🔒 Paystack</span>
                                        <?php else: ?>
                                            <span style="background:#6b7280;color:#fff;border-radius:4px;padding:2px 7px;font-size:0.78rem;">Manual</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo sanitize(date('d M Y', strtotime($pay['created_at']))); ?></td>
                                    <td style="white-space:nowrap;">
                                        <?php if ($payIsPaystack): ?>
                                            <span class="meta" style="font-size:0.8rem;">Auto-confirmed by Paystack</span>
                                        <?php else: ?>
                                            <form method="post" class="inline-form" style="display:inline-flex;align-items:center;gap:4px;margin-right:4px;">
                                                <input type="hidden" name="action" value="confirm_payment" />
                                                <input type="hidden" name="payment_id" value="<?php echo $pay['id']; ?>" />
                                                <select name="payment_method" style="font-size:0.8rem;padding:3px 6px;border:1px solid var(--border);border-radius:4px;">
                                                    <option value="cash">Cash</option>
                                                    <option value="mobile_money">Mobile Money</option>
                                                    <option value="bank_transfer">Bank Transfer</option>
                                                    <option value="other">Other</option>
                                                </select>
                                                <button type="submit" class="button button-small button-primary">✓ Confirm</button>
                                            </form>
                                            <form method="post" class="inline-form" style="display:inline-block;" onsubmit="return confirm('Reject this payment? The user will be notified.')">
                                                <input type="hidden" name="action" value="reject_payment" />
                                                <input type="hidden" name="payment_id" value="<?php echo $pay['id']; ?>" />
                                                <button type="submit" class="button button-small button-secondary" style="color:#c0392b;border-color:#c0392b;">✗ Reject</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <!-- Payment history -->
            <section class="panel">
                <h2 style="margin-top:0;">Payment History <span class="meta">(<?php echo $filtersActive ? count($paymentHistory) . ' results' : 'last 200'; ?>)</span></h2>
                <?php if (empty($paymentHistory)): ?>
                    <div class="empty-state">No completed or failed payments yet.</div>
                <?php else: ?>
                    <table class="pkg-table">
                        <thead><tr><th>User</th><th>Type</th><th>Amount</th><th>Reference</th><th>Method</th><th>Status</th><th>Date</th></tr></thead>
                        <tbody>
                            <?php foreach ($paymentHistory as $ph): ?>
                                <tr>
                                    <td><?php echo sanitize(display_name($ph)); ?></td>
                                    <td>
                                        <?php echo sanitize(ucwords(str_replace('_', ' ', $ph['payment_type']))); ?>
                                        <?php if ($ph['job_title']): ?><br><span class="meta"><?php echo sanitize(substr($ph['job_title'], 0, 30)); ?></span><?php endif; ?>
                                    </td>
                                    <td>GH₵ <?php echo number_format($ph['amount'], 2); ?></td>
                                    <td><code><?php echo sanitize($ph['reference_code'] ?: '—'); ?></code></td>
                                    <td class="meta"><?php echo sanitize($ph['payment_method'] ? ucwords(str_replace('_', ' ', $ph['payment_method'])) : (($ph['gateway'] ?? '') === 'paystack' ? 'Paystack' : '—')); ?></td>
                                    <td>
                                        <?php if ($ph['status'] === 'paid'): ?>
                                            <span class="status status-open">PAID</span>
                                        <?php else: ?>
                                            <span class="status status-cancelled">FAILED</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo sanitize(date('d M Y', strtotime($ph['created_at']))); ?></td>
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

        // Mode card radio highlight + feature-toggles dimming
        function applyModeUi(mode) {
            var overlay = document.getElementById('feature-toggles-overlay');
            var table   = document.getElementById('feature-toggles-table');
            if (mode === 'free') {
                overlay.style.display = 'block';
                overlay.textContent = 'Individual settings are ignored in Free Mode — all features are free for everyone.';
                table.style.opacity = '0.4';
                table.style.pointerEvents = 'none';
            } else if (mode === 'paid') {
                overlay.style.display = 'block';
                overlay.textContent = 'Individual settings are ignored in Paid Mode — all features require payment.';
                table.style.opacity = '0.4';
                table.style.pointerEvents = 'none';
            } else {
                overlay.style.display = 'none';
                table.style.opacity = '';
                table.style.pointerEvents = '';
            }
        }

        document.querySelectorAll('.mode-card input[type="radio"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                document.querySelectorAll('.mode-card').forEach(function (c) { c.classList.remove('selected'); });
                radio.closest('.mode-card').classList.add('selected');
                applyModeUi(radio.value);
            });
        });

        // Apply on page load
        (function () {
            var checked = document.querySelector('.mode-card input[type="radio"]:checked');
            if (checked) applyModeUi(checked.value);
        })();

        function editPackage(type, id, name, desc, days, price, status) {
            document.getElementById(type + '_id').value = id;
            document.getElementById(type + '_name').value = name;
            var descEl = document.getElementById(type + '_description');
            if (descEl) descEl.value = desc;
            document.getElementById(type + '_days').value = days;
            document.getElementById(type + '_price').value = price;
            document.getElementById(type + '_status').value = status;
            document.getElementById('form-' + type).scrollIntoView({ behavior: 'smooth' });
        }

        function resetForm(type) {
            document.getElementById(type + '_id').value = 0;
            document.getElementById('form-' + type).reset();
        }

        function editJobPostPkg(id, name, desc, postCount, price, status) {
            document.getElementById('job_posting_id').value = id;
            document.getElementById('job_posting_name').value = name;
            document.getElementById('job_posting_description').value = desc;
            document.getElementById('job_posting_post_count').value = postCount;
            document.getElementById('job_posting_price').value = price;
            document.getElementById('job_posting_status').value = status;
            document.getElementById('form-job_posting').scrollIntoView({ behavior: 'smooth' });
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
