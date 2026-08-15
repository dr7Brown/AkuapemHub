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

        $featureKeys = [
            'enable_paid_featured_jobs', 'enable_paid_featured_workers',
            'enable_paid_verification_badges', 'enable_paid_job_posting',
            'enable_paid_worker_service', 'enable_paid_worker_premium',
            'enable_paid_featured_events', 'enable_paid_featured_funerals', 'enable_paid_featured_news',
        ];
        $anyFeaturePaid = false;
        foreach ($featureKeys as $key) {
            if (($_POST[$key] ?? '0') === '1') { $anyFeaturePaid = true; break; }
        }
        // In Free mode every individual toggle is ignored (is_feature_paid()
        // short-circuits to false) — so turning a specific feature to Paid
        // while still in Free mode would silently do nothing. Auto-upgrade to
        // Hybrid so the admin's explicit choice actually takes effect.
        $autoUpgraded = false;
        if ($mode === 'free' && $anyFeaturePaid) {
            $mode = 'hybrid';
            $autoUpgraded = true;
        }

        set_platform_setting('monetization_mode', $mode);
        foreach ($featureKeys as $key) {
            set_platform_setting($key, ($_POST[$key] ?? '0') === '1' ? '1' : '0');
        }
        if ($prevMode !== $mode) {
            log_audit_action($user['id'], 'monetization_updated', "Monetization mode changed from '{$prevMode}' to '{$mode}'" . ($autoUpgraded ? ' (auto-upgraded from Free because a feature was set to Paid)' : ''));
        } else {
            log_audit_action($user['id'], 'monetization_updated', "Monetization feature toggles updated (mode: '{$mode}')");
        }
        $success = $autoUpgraded
            ? 'Monetization settings saved. Switched to Hybrid mode automatically since you enabled a paid feature — Free mode ignores individual toggles.'
            : 'Monetization settings saved.';
        $tab = 'settings';

    } elseif ($action === 'save_verification_settings') {
        foreach ([
            'require_verified_email_login',
            'require_verified_email_job_post', 'require_verified_email_job_apply',
            'require_verified_email_news_post', 'require_verified_email_event_post',
            'require_verified_email_funeral_post', 'require_verified_email_shop_create',
            'require_verified_email_product_post', 'require_verified_email_delivery_request',
            'require_verified_email_delivery_agent',
        ] as $key) {
            set_platform_setting($key, ($_POST[$key] ?? '0') === '1' ? '1' : '0');
        }
        log_audit_action($user['id'], 'verification_requirements_updated', 'Updated email verification requirements');
        $success = 'Verification requirements saved.';
        $tab = 'settings';

    } elseif ($action === 'save_module_toggles') {
        foreach (['mp', 'jobs', 'events', 'news', 'funerals', 'delivery', 'markets', 'quick_services'] as $modKey) {
            set_platform_setting("{$modKey}_enabled", isset($_POST["{$modKey}_enabled"]) ? '1' : '0');
        }
        log_audit_action($user['id'], 'module_toggles_updated', 'Updated platform module availability');
        $success = 'Module availability saved.';
        $tab = 'settings';

    } elseif ($action === 'save_job_listing_settings') {
        set_platform_setting('jobs_list_staffed_completed', ($_POST['jobs_list_staffed_completed'] ?? '0') === '1' ? '1' : '0');
        log_audit_action($user['id'], 'job_listing_settings_updated', 'Updated job listing visibility settings');
        $success = 'Job listing settings saved.';
        $tab = 'settings';

    } elseif ($action === 'save_delivery_feed_settings') {
        set_platform_setting('homepage_show_marketplace_deliveries', ($_POST['homepage_show_marketplace_deliveries'] ?? '0') === '1' ? '1' : '0');
        set_platform_setting('homepage_show_personal_deliveries', ($_POST['homepage_show_personal_deliveries'] ?? '0') === '1' ? '1' : '0');
        $audience = ($_POST['homepage_delivery_feed_audience'] ?? 'everyone') === 'agents_only' ? 'agents_only' : 'everyone';
        set_platform_setting('homepage_delivery_feed_audience', $audience);
        log_audit_action($user['id'], 'delivery_feed_settings_updated', 'Updated homepage delivery feed visibility settings');
        $success = 'Delivery feed settings saved.';
        $tab = 'settings';

    } elseif ($action === 'save_session_settings') {
        foreach (['customer', 'worker', 'manager', 'admin'] as $role) {
            $minutes = max(0, (int)($_POST["session_timeout_{$role}"] ?? 120));
            set_platform_setting("session_timeout_{$role}", (string)$minutes);
        }
        log_audit_action($user['id'], 'session_settings_updated', 'Updated per-role session timeout settings');
        $success = 'Session settings saved.';
        $tab = 'settings';

    } elseif ($action === 'save_mp_settings') {
        csrf_check();
        foreach (['mp_boost_requires_payment','mp_featured_product_enabled','mp_sponsored_product_enabled',
                  'mp_featured_shop_enabled','mp_sponsored_shop_enabled','mp_subscription_enabled'] as $k) {
            set_platform_setting($k, ($_POST[$k]??'0')==='1'?'1':'0');
        }
        set_platform_setting('mp_verified_seller_fee', max(0,(float)($_POST['mp_verified_seller_fee']??0)));
        log_audit_action($user['id'],'mp_settings_update','Updated marketplace monetization settings');
        $success = 'Marketplace settings saved.'; $tab = 'marketplace';

    } elseif ($action === 'save_mp_boost_pkg') {
        csrf_check();
        $pkgId    = (int)($_POST['pkg_id']??0);
        $bType    = $_POST['boost_type']??'';
        $pName    = trim($_POST['pkg_name']??'');
        $pDays    = max(1,(int)($_POST['pkg_days']??7));
        $pPrice   = max(0,(float)($_POST['pkg_price']??0));
        $pStatus  = ($_POST['pkg_status']??'active')==='active'?'active':'inactive';
        $validBT  = ['featured_product','sponsored_product','featured_shop','sponsored_shop'];
        if ($pName && in_array($bType,$validBT,true)) {
            if ($pkgId > 0) {
                $pdo->prepare("UPDATE mp_boost_packages SET name=?,boost_type=?,duration_days=?,price=?,status=? WHERE id=?")->execute([$pName,$bType,$pDays,$pPrice,$pStatus,$pkgId]);
            } else {
                $pdo->prepare("INSERT INTO mp_boost_packages (boost_type,name,duration_days,price,status) VALUES (?,?,?,?,?)")->execute([$bType,$pName,$pDays,$pPrice,$pStatus]);
            }
            log_audit_action($user['id'],'mp_pkg_save',"Saved boost package: $bType $pName");
            $success = 'Boost package saved.';
        } else { $error = 'Name and boost type required.'; }
        $tab = 'marketplace';

    } elseif ($action === 'delete_mp_boost_pkg') {
        csrf_check();
        $pkgId = (int)($_POST['pkg_id']??0);
        if ($pkgId) { $pdo->prepare("DELETE FROM mp_boost_packages WHERE id=?")->execute([$pkgId]); $success='Package deleted.'; }
        $tab = 'marketplace';

    } elseif ($action === 'save_mp_sub_plan') {
        csrf_check();
        $planId    = (int)($_POST['plan_id']??0);
        $planName  = trim($_POST['plan_name']??'');
        $planDesc  = trim($_POST['plan_desc']??'');
        $planDays  = max(1,(int)($_POST['plan_days']??30));
        $planPrice = max(0,(float)($_POST['plan_price']??0));
        $planLimit = (int)($_POST['plan_limit']??-1);
        $planSt    = ($_POST['plan_status']??'active')==='active'?'active':'inactive';
        if ($planName) {
            if ($planId > 0) {
                $pdo->prepare("UPDATE mp_seller_subscription_plans SET name=?,description=?,duration_days=?,price=?,product_limit=?,status=? WHERE id=?")->execute([$planName,$planDesc?:null,$planDays,$planPrice,$planLimit,$planSt,$planId]);
            } else {
                $pdo->prepare("INSERT INTO mp_seller_subscription_plans (name,description,duration_days,price,product_limit,status) VALUES (?,?,?,?,?,?)")->execute([$planName,$planDesc?:null,$planDays,$planPrice,$planLimit,$planSt]);
            }
            $success = 'Subscription plan saved.';
        } else { $error = 'Plan name required.'; }
        $tab = 'marketplace';

    } elseif ($action === 'delete_mp_sub_plan') {
        csrf_check();
        $planId = (int)($_POST['plan_id']??0);
        if ($planId) { $pdo->prepare("DELETE FROM mp_seller_subscription_plans WHERE id=?")->execute([$planId]); $success='Plan deleted.'; }
        $tab = 'marketplace';

    } elseif ($action === 'activate_boost') {
        csrf_check();
        $boostId = (int)($_POST['boost_id']??0);
        if ($boostId) {
            require_once __DIR__ . '/../marketplace_functions.php';
            $b = $pdo->prepare("SELECT mb.*, ms.shop_name FROM mp_boost_orders mb JOIN mp_shops ms ON mb.shop_id=ms.id WHERE mb.id=?")->execute([$boostId]) ? null : null;
            $bSt = $pdo->prepare("SELECT mb.*, ms.shop_name FROM mp_boost_orders mb JOIN mp_shops ms ON mb.shop_id=ms.id WHERE mb.id=?"); $bSt->execute([$boostId]); $b = $bSt->fetch();
            if ($b) {
                $pdo->prepare("UPDATE mp_boost_orders SET status='active', activated_by=?, activated_at=NOW() WHERE id=?")->execute([$user['id'],$boostId]);
                $isSponsored = str_contains($b['boost_type'],'sponsored');
                $isProduct   = str_contains($b['boost_type'],'product');
                if ($isProduct && $b['product_id']) {
                    $pdo->prepare("UPDATE mp_products SET is_featured=?,featured_end=?,is_sponsored=?,sponsored_end=?,updated_at=NOW() WHERE id=?")->execute([$isSponsored?0:1,$isSponsored?null:$b['end_date'],$isSponsored?1:0,$isSponsored?$b['end_date']:null,$b['product_id']]);
                } else {
                    $pdo->prepare("UPDATE mp_shops SET is_featured=?,featured_end=?,is_sponsored=?,sponsored_end=?,updated_at=NOW() WHERE id=?")->execute([$isSponsored?0:1,$isSponsored?null:$b['end_date'],$isSponsored?1:0,$isSponsored?$b['end_date']:null,$b['shop_id']]);
                }
                $ownerUid = $pdo->prepare("SELECT user_id FROM mp_shops WHERE id=?")->execute([$b['shop_id']]) ? null : null;
                $oSt = $pdo->prepare("SELECT user_id FROM mp_shops WHERE id=?"); $oSt->execute([$b['shop_id']]); $ownerUid = $oSt->fetchColumn();
                if ($ownerUid) notify_user((int)$ownerUid, '⚡ Boost Activated!', ucwords(str_replace('_',' ',$b['boost_type'])).' for '.$b['shop_name'].' is now live until '.date('d M Y',strtotime($b['end_date'])).'.','success');
                log_audit_action($user['id'],'mp_boost_activate',"Activated boost #{$boostId}: {$b['boost_type']} for {$b['shop_name']}");
                $success = 'Boost activated.';
            }
        }
        $tab = 'marketplace';

    } elseif ($action === 'save_package') {
        $pkgType = $_POST['pkg_type'] ?? '';
        $pkgId = intval($_POST['pkg_id'] ?? 0);
        $pkgName = trim($_POST['pkg_name'] ?? '');
        $pkgPrice = max(0, (float)($_POST['pkg_price'] ?? 0));
        $pkgDays = max(1, intval($_POST['pkg_days'] ?? 0));
        $pkgStatus = ($_POST['pkg_status'] ?? '') === 'active' ? 'active' : 'inactive';

        $tableMap = [
            'featured_job'    => 'featured_job_packages',
            'featured_worker' => 'worker_promotion_packages',
            'verification'    => 'verification_packages',
            'job_posting'     => 'job_posting_packages',
            'worker_service'  => 'worker_service_packages',
            'worker_premium'  => 'worker_premium_packages',
            'featured_event'  => 'featured_event_packages',
            'featured_funeral'=> 'featured_funeral_packages',
            'featured_news'   => 'featured_news_packages',
            'sponsor'         => 'sponsor_packages',
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
            } elseif ($pkgType === 'worker_service' || $pkgType === 'worker_premium') {
                $pkgDesc = trim($_POST['pkg_description'] ?? '') ?: null;
                if ($pkgId > 0) {
                    $pdo->prepare("UPDATE $table SET name = ?, description = ?, duration_days = ?, price = ?, status = ? WHERE id = ?")
                        ->execute([$pkgName, $pkgDesc, $pkgDays, $pkgPrice, $pkgStatus, $pkgId]);
                } else {
                    $pdo->prepare("INSERT INTO $table (name, description, duration_days, price, status) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$pkgName, $pkgDesc, $pkgDays, $pkgPrice, $pkgStatus]);
                }
            } elseif ($pkgType === 'sponsor') {
                // Benefits comes from the shared rich-editor.js component — it's
                // admin-authored HTML, rendered back out via render_rich().
                $pkgBenefits = trim($_POST['pkg_benefits'] ?? '') ?: null;
                if ($pkgId > 0) {
                    $pdo->prepare("UPDATE $table SET name = ?, duration_days = ?, price = ?, status = ?, benefits = ? WHERE id = ?")
                        ->execute([$pkgName, $pkgDays, $pkgPrice, $pkgStatus, $pkgBenefits, $pkgId]);
                } else {
                    $pdo->prepare("INSERT INTO $table (name, duration_days, price, status, benefits) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$pkgName, $pkgDays, $pkgPrice, $pkgStatus, $pkgBenefits]);
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
        $tabMap = [
            'featured_job'    => 'job_pkgs',
            'featured_worker' => 'worker_pkgs',
            'verification'    => 'worker_pkgs',
            'job_posting'     => 'job_pkgs',
            'worker_service'  => 'worker_pkgs',
            'worker_premium'  => 'worker_pkgs',
            'featured_event'  => 'community',
            'featured_funeral'=> 'community',
            'featured_news'   => 'community',
            'sponsor'         => 'community',
        ];
        $tab = $tabMap[$pkgType] ?? 'settings';

    } elseif ($action === 'delete_package') {
        $pkgType = $_POST['pkg_type'] ?? '';
        $pkgId = intval($_POST['pkg_id'] ?? 0);
        $tableMap = [
            'featured_job'    => 'featured_job_packages',
            'featured_worker' => 'worker_promotion_packages',
            'verification'    => 'verification_packages',
            'job_posting'     => 'job_posting_packages',
            'worker_service'  => 'worker_service_packages',
            'worker_premium'  => 'worker_premium_packages',
            'featured_event'  => 'featured_event_packages',
            'featured_funeral'=> 'featured_funeral_packages',
            'featured_news'   => 'featured_news_packages',
            'sponsor'         => 'sponsor_packages',
        ];
        $table = $tableMap[$pkgType] ?? '';
        if ($table && $pkgId > 0) {
            $pdo->prepare("DELETE FROM $table WHERE id = ?")->execute([$pkgId]);
            log_audit_action($user['id'] ?? 0, 'package_deleted', "Deleted {$pkgType} package ID {$pkgId}");
            $success = 'Package deleted.';
        }
        $tabMap2 = [
            'featured_job'    => 'job_pkgs',
            'featured_worker' => 'worker_pkgs',
            'verification'    => 'worker_pkgs',
            'job_posting'     => 'job_pkgs',
            'worker_service'  => 'worker_pkgs',
            'worker_premium'  => 'worker_pkgs',
            'featured_event'  => 'community',
            'featured_funeral'=> 'community',
            'featured_news'   => 'community',
            'sponsor'         => 'community',
        ];
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
            } elseif ($payment['payment_type'] === 'worker_premium' && $payment['reference_id']) {
                $pkgRow = $pdo->prepare('SELECT duration_days FROM worker_premium_packages WHERE id = ?');
                $pkgRow->execute([$payment['package_id']]);
                $pkgRow = $pkgRow->fetch();
                $days = $pkgRow ? (int)$pkgRow['duration_days'] : 30;
                $pdo->prepare("UPDATE worker_profiles SET subscription_status = 'premium', premium_expiry = DATE_ADD(CURDATE(), INTERVAL ? DAY), premium_renewal_notice_sent = 0 WHERE id = ?")
                    ->execute([$days, $payment['reference_id']]);
                notify_user($payment['user_id'], 'Premium confirmed', 'Your Premium subscription is now active for ' . $days . ' days. You will rank higher in search results.', 'success');
                log_audit_action($user['id'], 'payment_confirmed', "Confirmed worker_premium payment ref {$payment['reference_code']} (GH₵{$payment['amount']}) for user ID {$payment['user_id']}, worker profile ID {$payment['reference_id']}");
            } elseif ($payment['payment_type'] === 'featured_event' && $payment['reference_id']) {
                $pkgRow = $pdo->prepare('SELECT duration_days FROM featured_event_packages WHERE id = ?');
                $pkgRow->execute([$payment['package_id']]);
                $pkgRow = $pkgRow->fetch();
                $days = $pkgRow ? (int)$pkgRow['duration_days'] : 30;
                $pdo->prepare("UPDATE events SET featured=1, featured_end_date=DATE_ADD(CURDATE(),INTERVAL ? DAY) WHERE id=?")
                    ->execute([$days, $payment['reference_id']]);
                notify_user($payment['user_id'], '⭐ Event is now featured!', "Your event is featured for {$days} days and will appear at the top of listings.", 'success');
                log_audit_action($user['id'], 'payment_confirmed', "Confirmed featured_event payment ref {$payment['reference_code']} for user ID {$payment['user_id']}");
            } elseif ($payment['payment_type'] === 'featured_funeral' && $payment['reference_id']) {
                $pkgRow = $pdo->prepare('SELECT duration_days FROM featured_funeral_packages WHERE id = ?');
                $pkgRow->execute([$payment['package_id']]);
                $pkgRow = $pkgRow->fetch();
                $days = $pkgRow ? (int)$pkgRow['duration_days'] : 30;
                $pdo->prepare("UPDATE funeral_announcements SET featured=1, featured_end_date=DATE_ADD(CURDATE(),INTERVAL ? DAY) WHERE id=?")
                    ->execute([$days, $payment['reference_id']]);
                notify_user($payment['user_id'], '⭐ Announcement is now featured!', "The announcement is featured for {$days} days.", 'success');
                log_audit_action($user['id'], 'payment_confirmed', "Confirmed featured_funeral payment ref {$payment['reference_code']} for user ID {$payment['user_id']}");
            } elseif ($payment['payment_type'] === 'featured_news' && $payment['reference_id']) {
                $pkgRow = $pdo->prepare('SELECT duration_days FROM featured_news_packages WHERE id = ?');
                $pkgRow->execute([$payment['package_id']]);
                $pkgRow = $pkgRow->fetch();
                $days = $pkgRow ? (int)$pkgRow['duration_days'] : 30;
                $pdo->prepare("UPDATE news SET featured=1, featured_end_date=DATE_ADD(CURDATE(),INTERVAL ? DAY) WHERE id=?")
                    ->execute([$days, $payment['reference_id']]);
                notify_user($payment['user_id'], '⭐ Article is now featured!', "Your article is featured for {$days} days and will appear at the top of the news feed.", 'success');
                log_audit_action($user['id'], 'payment_confirmed', "Confirmed featured_news payment ref {$payment['reference_code']} for user ID {$payment['user_id']}");
            } elseif ($payment['payment_type'] === 'mp_subscription' && $payment['reference_id']) {
                $subR = $pdo->prepare("SELECT mss.*, msp.name AS plan_name, ms.user_id AS owner_id, ms.shop_name FROM mp_seller_subscriptions mss JOIN mp_seller_subscription_plans msp ON mss.plan_id=msp.id JOIN mp_shops ms ON mss.shop_id=ms.id WHERE mss.id=?");
                $subR->execute([$payment['reference_id']]);
                $sub = $subR->fetch();
                if ($sub) {
                    $pdo->prepare("UPDATE mp_seller_subscriptions SET status='active', payment_id=?, activated_at=NOW() WHERE id=?")->execute([$payment['id'], $sub['id']]);
                    $pdo->prepare("UPDATE mp_shops SET is_subscribed=1, subscription_plan_id=?, subscription_end=?, updated_at=NOW() WHERE id=?")->execute([$sub['plan_id'], $sub['end_date'], $sub['shop_id']]);
                    notify_user((int)$sub['owner_id'], '⭐ Subscription Activated!', $sub['plan_name'] . ' for ' . $sub['shop_name'] . ' is active until ' . date('d M Y', strtotime($sub['end_date'])) . '.', 'success');
                    log_audit_action($user['id'], 'payment_confirmed', "Confirmed mp_subscription payment ref {$payment['reference_code']} for user ID {$payment['user_id']}");
                }
            } elseif ($payment['payment_type'] === 'sponsor' && $payment['reference_id']) {
                $pkgRow = $pdo->prepare('SELECT duration_days FROM sponsor_packages WHERE id = ?');
                $pkgRow->execute([$payment['package_id']]);
                $pkgRow = $pkgRow->fetch();
                $days = $pkgRow ? (int)$pkgRow['duration_days'] : 30;
                $spRow = $pdo->prepare('SELECT name FROM sponsors WHERE id = ?');
                $spRow->execute([$payment['reference_id']]);
                $spName = $spRow->fetch()['name'] ?? "Sponsor #{$payment['reference_id']}";
                $pdo->prepare("UPDATE sponsors SET status='pending_approval', start_date=CURDATE(), end_date=DATE_ADD(CURDATE(), INTERVAL ? DAY), updated_at=NOW() WHERE id=? AND status='pending_payment'")
                    ->execute([$days, $payment['reference_id']]);
                notify_user($payment['user_id'], '✅ Sponsorship payment confirmed', "Payment for \"{$spName}\" was received. Your sponsor listing is now queued for admin review.", 'success');
                log_audit_action($user['id'], 'payment_confirmed', "Confirmed sponsor payment ref {$payment['reference_code']} for user ID {$payment['user_id']}");
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
        $tab = 'worker_pkgs';

    } elseif ($action === 'approve_verification') {
        $workerUserId = intval($_POST['worker_user_id'] ?? 0);
        if ($workerUserId > 0) {
            $pdo->prepare("UPDATE worker_profiles SET is_verified = 1, verification_status = 'approved', verification_date = CURDATE(), verification_expiry = DATE_ADD(CURDATE(), INTERVAL 365 DAY), verification_rejection_reason = NULL WHERE user_id = ?")
                ->execute([$workerUserId]);
            notify_user($workerUserId, 'Verification approved', 'Your worker profile is now verified. The ✓erified badge will appear on your profile and search results.', 'success');
            log_audit_action($user['id'] ?? 0, 'worker_verified', "Verified worker user ID {$workerUserId} (admin approved)");
            $success = 'Worker verified.';
        }
        $tab = 'worker_pkgs';

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
        $tab = 'worker_pkgs';

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
        $tab = 'worker_pkgs';

    } elseif ($action === 'unfeature_job') {
        $jobId = intval($_POST['job_id'] ?? 0);
        if ($jobId > 0) {
            $pdo->prepare('UPDATE service_requests SET featured = 0, featured_end_date = CURDATE() WHERE id = ?')
                ->execute([$jobId]);
            log_audit_action($user['id'], 'job_unfeatured', "Removed featured status for job ID {$jobId}");
            $success = 'Job featured status removed.';
        }
        $tab = 'job_pkgs';

    } elseif ($action === 'unfeature_worker') {
        $workerId = intval($_POST['worker_user_id'] ?? 0);
        if ($workerId > 0) {
            $pdo->prepare('UPDATE worker_profiles SET is_featured = 0, featured_end_date = NULL WHERE user_id = ?')
                ->execute([$workerId]);
            log_audit_action($user['id'], 'worker_unfeatured', "Removed featured status for worker user ID {$workerId}");
            $success = 'Featured status removed.';
        }
        $tab = 'worker_pkgs';

    } elseif ($action === 'revoke_verification') {
        $workerId = intval($_POST['worker_user_id'] ?? 0);
        if ($workerId > 0) {
            $pdo->prepare("UPDATE worker_profiles SET is_verified = 0, verification_status = 'none', verification_date = NULL, verification_expiry = NULL, verification_rejection_reason = NULL WHERE user_id = ?")
                ->execute([$workerId]);
            notify_user($workerId, 'Verification revoked', 'Your worker verification badge has been revoked. Contact support for more information.', 'warning');
            log_audit_action($user['id'] ?? 0, 'verification_revoked', "Revoked verification for worker user ID {$workerId}");
            $success = 'Verification revoked.';
        }
        $tab = 'worker_pkgs';
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
$enableFeaturedJobs      = get_platform_setting('enable_paid_featured_jobs', '0');
$enableFeaturedWorkers   = get_platform_setting('enable_paid_featured_workers', '0');
$enableVerification      = get_platform_setting('enable_paid_verification_badges', '0');
$enablePaidJobPosting    = get_platform_setting('enable_paid_job_posting', '0');
$enablePaidWorkerService = get_platform_setting('enable_paid_worker_service', '0');
$enablePaidWorkerPremium = get_platform_setting('enable_paid_worker_premium', '0');
$enableFeaturedEvents    = get_platform_setting('enable_paid_featured_events', '0');
$enableFeaturedFunerals  = get_platform_setting('enable_paid_featured_funerals', '0');
$enableFeaturedNews      = get_platform_setting('enable_paid_featured_news', '0');

$verifyReqs = [
    'login'             => get_platform_setting('require_verified_email_login', '1') === '1',
    'job_post'          => get_platform_setting('require_verified_email_job_post', '1') === '1',
    'job_apply'         => get_platform_setting('require_verified_email_job_apply', '1') === '1',
    'news_post'         => get_platform_setting('require_verified_email_news_post', '0') === '1',
    'event_post'        => get_platform_setting('require_verified_email_event_post', '0') === '1',
    'funeral_post'      => get_platform_setting('require_verified_email_funeral_post', '0') === '1',
    'shop_create'       => get_platform_setting('require_verified_email_shop_create', '0') === '1',
    'product_post'      => get_platform_setting('require_verified_email_product_post', '0') === '1',
    'delivery_request'  => get_platform_setting('require_verified_email_delivery_request', '0') === '1',
    'delivery_agent'    => get_platform_setting('require_verified_email_delivery_agent', '0') === '1',
];

$moduleToggles = [
    'mp'       => ['label' => 'Marketplace',           'desc' => 'Buy & sell products locally'],
    'jobs'     => ['label' => 'Jobs & Services',       'desc' => 'Post and browse job requests'],
    'events'   => ['label' => 'Events',                'desc' => 'Community events & programs'],
    'news'     => ['label' => 'News & Updates',        'desc' => 'Articles & platform news'],
    'funerals' => ['label' => 'Funeral Announcements', 'desc' => 'Memorial notices'],
    'delivery' => ['label' => 'Delivery Services',      'desc' => 'Send & receive parcels'],
    'markets'  => ['label' => 'Nearby Markets',        'desc' => 'Ofie Market, Nkurakan Market & other scheduled markets'],
    'quick_services' => ['label' => 'Quick Services',    'desc' => 'Airtime, ECG, exam results & other paid service requests'],
];
foreach ($moduleToggles as $modKey => &$modInfo) {
    $modInfo['enabled'] = module_enabled($modKey);
}
unset($modInfo);

$jobsListStaffedCompleted = get_platform_setting('jobs_list_staffed_completed', '0') === '1';

$showMarketplaceDeliveriesOnHome = get_platform_setting('homepage_show_marketplace_deliveries', '1') === '1';
$showPersonalDeliveriesOnHome    = get_platform_setting('homepage_show_personal_deliveries', '1') === '1';
$deliveryFeedAudience            = get_platform_setting('homepage_delivery_feed_audience', 'everyone');

$sessionTimeouts = [
    'customer' => (int)get_platform_setting('session_timeout_customer', '120'),
    'worker'   => (int)get_platform_setting('session_timeout_worker', '120'),
    'manager'  => (int)get_platform_setting('session_timeout_manager', '120'),
    'admin'    => (int)get_platform_setting('session_timeout_admin', '120'),
];

$psMode    = get_platform_setting('paystack_mode', 'test');
$psPubKey  = get_platform_setting('paystack_public_key', '');
$psConfigured = get_platform_setting('paystack_secret_key', '') !== '';

$featuredJobPackages = get_active_packages('featured_job_packages');
$allFeaturedJobPackages = $pdo->query("SELECT * FROM featured_job_packages ORDER BY price ASC")->fetchAll();
$workerPromoPackages = get_active_packages('worker_promotion_packages');
$allWorkerPromoPackages = $pdo->query("SELECT * FROM worker_promotion_packages ORDER BY price ASC")->fetchAll();
$verificationPackages = get_active_packages('verification_packages');
$allVerificationPackages = $pdo->query("SELECT * FROM verification_packages ORDER BY price ASC")->fetchAll();
$allJobPostingPackages   = $pdo->query("SELECT * FROM job_posting_packages ORDER BY price ASC")->fetchAll();
$allWorkerServicePackages = $pdo->query("SELECT * FROM worker_service_packages ORDER BY price ASC")->fetchAll();
$allWorkerPremiumPackages = $pdo->query("SELECT * FROM worker_premium_packages ORDER BY price ASC")->fetchAll();
$allFeaturedEventPackages   = $pdo->query("SELECT * FROM featured_event_packages ORDER BY price ASC")->fetchAll();
$allFeaturedFuneralPackages = $pdo->query("SELECT * FROM featured_funeral_packages ORDER BY price ASC")->fetchAll();
$allFeaturedNewsPackages    = $pdo->query("SELECT * FROM featured_news_packages ORDER BY price ASC")->fetchAll();
$allSponsorPackages         = $pdo->query("SELECT * FROM sponsor_packages ORDER BY price ASC")->fetchAll();

// Pending payments with context
$pendingPayments = $pdo->query("
    SELECT pp.*, u.name AS user_name, u.username,
        COALESCE(pp.gateway, 'manual') AS gateway,
        sr.title AS job_title,
        CASE pp.payment_type
            WHEN 'featured_job'    THEN COALESCE(fp.name, '—')
            WHEN 'featured_worker' THEN COALESCE(wp2.name, '—')
            WHEN 'verification'    THEN COALESCE(vp.name, '—')
            WHEN 'job_post'        THEN COALESCE(jp.name, '—')
            WHEN 'worker_service'  THEN COALESCE(ws.name, '—')
            WHEN 'featured_event'  THEN COALESCE(fep.name, '—')
            WHEN 'featured_funeral'THEN COALESCE(ffp.name, '—')
            WHEN 'featured_news'   THEN COALESCE(fnp.name, '—')
            WHEN 'mp_subscription' THEN COALESCE(msp.name, '—')
            WHEN 'mp_boost'        THEN COALESCE(mbp.name, '—')
            WHEN 'sponsor'         THEN COALESCE(spp.name, '—')
            ELSE '—'
        END AS package_name
    FROM platform_payments pp
    JOIN users u ON pp.user_id = u.id
    LEFT JOIN service_requests sr ON pp.payment_type IN ('featured_job','job_post') AND sr.id = pp.reference_id
    LEFT JOIN featured_job_packages fp      ON pp.payment_type = 'featured_job'    AND fp.id  = pp.package_id
    LEFT JOIN worker_promotion_packages wp2 ON pp.payment_type = 'featured_worker' AND wp2.id = pp.package_id
    LEFT JOIN verification_packages vp      ON pp.payment_type = 'verification'    AND vp.id  = pp.package_id
    LEFT JOIN job_posting_packages jp       ON pp.payment_type = 'job_post'        AND jp.id  = pp.package_id
    LEFT JOIN worker_service_packages ws    ON pp.payment_type = 'worker_service'  AND ws.id  = pp.package_id
    LEFT JOIN featured_event_packages fep   ON pp.payment_type = 'featured_event'  AND fep.id = pp.package_id
    LEFT JOIN featured_funeral_packages ffp ON pp.payment_type = 'featured_funeral'AND ffp.id = pp.package_id
    LEFT JOIN featured_news_packages fnp    ON pp.payment_type = 'featured_news'   AND fnp.id = pp.package_id
    LEFT JOIN mp_seller_subscription_plans msp ON pp.payment_type = 'mp_subscription' AND msp.id = pp.package_id
    LEFT JOIN mp_boost_packages mbp         ON pp.payment_type = 'mp_boost'        AND mbp.id = pp.package_id
    LEFT JOIN sponsor_packages spp          ON pp.payment_type = 'sponsor'         AND spp.id = pp.package_id
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
$histPage    = max(1, (int)($_GET['hpage'] ?? 1));
$histPerPage = 30;
$histOffset  = ($histPage - 1) * $histPerPage;

$histCountStmt = $pdo->prepare("
    SELECT COUNT(*) FROM platform_payments pp
    JOIN users u ON pp.user_id = u.id
    WHERE pp.status IN ('paid', 'failed') {$revWhereSQL}
");
$histCountStmt->execute($revParams);
$histTotal      = (int)$histCountStmt->fetchColumn();
$histTotalPages = max(1, (int)ceil($histTotal / $histPerPage));

$histStmt = $pdo->prepare("
    SELECT pp.*, u.name AS user_name, u.username, sr.title AS job_title
    FROM platform_payments pp
    JOIN users u ON pp.user_id = u.id
    LEFT JOIN service_requests sr ON pp.payment_type IN ('featured_job','job_post') AND sr.id = pp.reference_id
    WHERE pp.status IN ('paid', 'failed') {$revWhereSQL}
    ORDER BY pp.created_at DESC LIMIT {$histPerPage} OFFSET {$histOffset}
");
$histStmt->execute($revParams);
$paymentHistory = $histStmt->fetchAll();

function mono_hist_qstr(array $overrides = []): string {
    $base = ['tab' => 'payments'];
    foreach (['filter_from', 'filter_to', 'filter_type', 'filter_search', 'hpage'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') $base[$k] = $_GET[$k];
    }
    $merged = array_filter(array_merge($base, $overrides), fn($v) => $v !== null);
    return 'monetization.php?' . http_build_query($merged);
}

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

// ── Marketplace Monetization Data ─────────────────────────────────────────────
// Revenue by boost type
$mpBoostRevenue = [];
try {
    $btr = $pdo->query("SELECT boost_type, COUNT(*) cnt, SUM(price_paid) rev FROM mp_boost_orders WHERE status IN('active','expired') GROUP BY boost_type")->fetchAll();
    foreach ($btr as $b) $mpBoostRevenue[$b['boost_type']] = ['cnt'=>(int)$b['cnt'],'rev'=>(float)$b['rev']];
} catch(Exception $e){}

$mpTotalRevenue   = array_sum(array_column($mpBoostRevenue,'rev'));
$mpSubRevenue     = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM platform_payments WHERE payment_type='mp_subscription' AND status='paid'")->fetchColumn();

// Boost packages
$mpBoostPackages = $pdo->query("SELECT * FROM mp_boost_packages ORDER BY boost_type, duration_days")->fetchAll();
$mpBoostPkgByType = [];
foreach ($mpBoostPackages as $pk) $mpBoostPkgByType[$pk['boost_type']][] = $pk;

// Subscription plans
$mpSubPlans = $pdo->query("SELECT * FROM mp_seller_subscription_plans ORDER BY price")->fetchAll();

// Active boosts
$mpActiveBoosts = $pdo->query(
    "SELECT mb.*, ms.shop_name, u.name AS owner_name
     FROM mp_boost_orders mb JOIN mp_shops ms ON mb.shop_id=ms.id JOIN users u ON ms.user_id=u.id
     WHERE mb.status='active' AND mb.end_date>=CURDATE()
     ORDER BY mb.end_date ASC LIMIT 40"
)->fetchAll();

// Pending boost orders (not yet activated)
$mpPendingBoosts = $pdo->query(
    "SELECT mb.*, ms.shop_name, u.name AS owner_name
     FROM mp_boost_orders mb JOIN mp_shops ms ON mb.shop_id=ms.id JOIN users u ON ms.user_id=u.id
     WHERE mb.status='pending'
     ORDER BY mb.created_at ASC LIMIT 30"
)->fetchAll();

// Settings
$mpSettings = [];
foreach (['mp_boost_requires_payment','mp_featured_product_enabled','mp_sponsored_product_enabled',
          'mp_featured_shop_enabled','mp_sponsored_shop_enabled','mp_subscription_enabled','mp_verified_seller_fee'] as $k) {
    $mpSettings[$k] = get_platform_setting($k, '1');
}
$mpSettings['mp_verified_seller_fee'] = get_platform_setting('mp_verified_seller_fee','0.00');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Monetization — AkuapemConnect Admin</title>
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
        .pagination { display:flex; gap:4px; flex-wrap:wrap; align-items:center; margin-top:14px; }
        .pagination a, .pagination span { padding:5px 10px; border-radius:6px; border:1px solid var(--border); text-decoration:none; font-size:.82rem; color:var(--text); }
        .pagination a:hover { background:var(--surface-muted,#f9fafb); }
        .pagination .current { background:var(--primary,#0f766e); color:#fff; border-color:var(--primary,#0f766e); }
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
            <button class="mono-tab <?php echo $tab === 'job_pkgs' ? 'active' : ''; ?>" data-tab="job_pkgs">📋 Job Pkgs</button>
            <button class="mono-tab <?php echo $tab === 'worker_pkgs' ? 'active' : ''; ?>" data-tab="worker_pkgs">👷 Worker Pkgs <?php if (!empty($pendingVerificationPayments)): ?><span style="background:#f59e0b;color:#fff;border-radius:10px;padding:1px 7px;font-size:0.8rem;"><?php echo count($pendingVerificationPayments); ?></span><?php endif; ?></button>
            <button class="mono-tab <?php echo $tab === 'marketplace' ? 'active' : ''; ?>" data-tab="marketplace">🛍️ Marketplace <?php if (count($mpPendingBoosts)): ?><span style="background:#f59e0b;color:#fff;border-radius:10px;padding:1px 7px;font-size:0.8rem;"><?php echo count($mpPendingBoosts); ?></span><?php endif; ?></button>
            <button class="mono-tab <?php echo $tab === 'community' ? 'active' : ''; ?>" data-tab="community">📢 Community Pkgs</button>
            <button class="mono-tab <?php echo $tab === 'payments' ? 'active' : ''; ?>" data-tab="payments">Pending Payments <?php if ($pendingPayments): ?><span style="background:var(--primary);color:#fff;border-radius:10px;padding:1px 7px;font-size:0.8rem;"><?php echo count($pendingPayments); ?></span><?php endif; ?></button>
            <button class="mono-tab <?php echo $tab === 'audit' ? 'active' : ''; ?>" data-tab="audit">Audit Log</button>
        </nav>

        <!-- SETTINGS TAB -->
        <div class="tab-panel <?php echo $tab === 'settings' ? 'active' : ''; ?>" id="tab-settings">
            <section class="panel">
                <h2>Module Availability</h2>
                <p class="meta">Switch a module off to take it offline for everyone — its pages redirect to the community home, and it disappears from navigation there.</p>
                <form method="post" action="monetization.php">
                    <input type="hidden" name="action" value="save_module_toggles" />
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;margin-top:10px;">
                        <?php foreach ($moduleToggles as $modKey => $modInfo): ?>
                        <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;border:1px solid var(--border);border-radius:8px;padding:10px 12px;">
                            <input type="checkbox" name="<?php echo $modKey; ?>_enabled" value="1" <?php echo $modInfo['enabled'] ? 'checked' : ''; ?> style="margin-top:3px;" />
                            <span>
                                <strong style="display:block;font-size:.88rem;"><?php echo sanitize($modInfo['label']); ?></strong>
                                <span class="meta" style="font-size:.76rem;"><?php echo sanitize($modInfo['desc']); ?></span>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="button button-primary" style="margin-top:14px;">Save Module Availability</button>
                </form>
            </section>

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
                                <tr>
                                    <td><strong>Worker Premium Subscription</strong><br><span class="meta">Charge workers for a search-ranking boost</span></td>
                                    <td>
                                        <label style="margin-right:16px;"><input type="radio" name="enable_paid_worker_premium" value="0" <?php echo !$enablePaidWorkerPremium ? 'checked' : ''; ?>> Free</label>
                                        <label><input type="radio" name="enable_paid_worker_premium" value="1" <?php echo $enablePaidWorkerPremium ? 'checked' : ''; ?>> Paid</label>
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="2" style="background:var(--surface-muted,#f8fafc);font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--muted,#6b7280);padding:8px 12px;">Community Content Featuring</td>
                                </tr>
                                <tr>
                                    <td><strong>Event Featuring</strong><br><span class="meta">Charge event organisers to pin events at the top</span></td>
                                    <td>
                                        <label style="margin-right:16px;"><input type="radio" name="enable_paid_featured_events" value="0" <?php echo !$enableFeaturedEvents ? 'checked' : ''; ?>> Free</label>
                                        <label><input type="radio" name="enable_paid_featured_events" value="1" <?php echo $enableFeaturedEvents ? 'checked' : ''; ?>> Paid</label>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Funeral Announcement Featuring</strong><br><span class="meta">Charge families to pin announcements at the top</span></td>
                                    <td>
                                        <label style="margin-right:16px;"><input type="radio" name="enable_paid_featured_funerals" value="0" <?php echo !$enableFeaturedFunerals ? 'checked' : ''; ?>> Free</label>
                                        <label><input type="radio" name="enable_paid_featured_funerals" value="1" <?php echo $enableFeaturedFunerals ? 'checked' : ''; ?>> Paid</label>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>News Article Featuring</strong><br><span class="meta">Charge writers to pin articles at the top of the news feed</span></td>
                                    <td>
                                        <label style="margin-right:16px;"><input type="radio" name="enable_paid_featured_news" value="0" <?php echo !$enableFeaturedNews ? 'checked' : ''; ?>> Free</label>
                                        <label><input type="radio" name="enable_paid_featured_news" value="1" <?php echo $enableFeaturedNews ? 'checked' : ''; ?>> Paid</label>
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

            <!-- Job listing visibility -->
            <section class="panel">
                <h2>Job Listing Visibility</h2>
                <p class="meta">Controls whether jobs stay visible in public job listings (jobs page, browse jobs, homepage, similar jobs) once they're no longer accepting applicants.</p>
                <form method="post" action="monetization.php">
                    <input type="hidden" name="action" value="save_job_listing_settings" />
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.9rem;">
                        <input type="checkbox" name="jobs_list_staffed_completed" value="1" <?php echo $jobsListStaffedCompleted ? 'checked' : ''; ?> />
                        Keep fully-staffed and completed jobs listed publicly
                    </label>
                    <p class="meta" style="margin-top:6px;">Unchecked (default): only Open and Partially Staffed jobs appear in listings.</p>
                    <button type="submit" class="button button-primary" style="margin-top:12px;">Save job listing settings</button>
                </form>
            </section>

            <!-- Homepage delivery feed visibility -->
            <section class="panel">
                <h2>Delivery Feed Visibility</h2>
                <p class="meta">Controls which open delivery requests appear in the "Open Delivery Requests" section on the homepage. This only affects the homepage — delivery agents can still browse and apply to every open job from their own dashboard regardless of these settings.</p>
                <form method="post" action="monetization.php">
                    <input type="hidden" name="action" value="save_delivery_feed_settings" />
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.9rem;">
                        <input type="checkbox" name="homepage_show_marketplace_deliveries" value="1" <?php echo $showMarketplaceDeliveriesOnHome ? 'checked' : ''; ?> />
                        Show marketplace order deliveries (auto-created when a seller ships an order)
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.9rem;margin-top:8px;">
                        <input type="checkbox" name="homepage_show_personal_deliveries" value="1" <?php echo $showPersonalDeliveriesOnHome ? 'checked' : ''; ?> />
                        Show personal delivery requests (submitted directly by users via "Request Delivery")
                    </label>

                    <p class="meta" style="margin-top:16px;margin-bottom:6px;font-weight:700;">Who can see this feed?</p>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.9rem;">
                        <input type="radio" name="homepage_delivery_feed_audience" value="everyone" <?php echo $deliveryFeedAudience !== 'agents_only' ? 'checked' : ''; ?> />
                        Everyone — all visitors and users see it on the homepage
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.9rem;margin-top:8px;">
                        <input type="radio" name="homepage_delivery_feed_audience" value="agents_only" <?php echo $deliveryFeedAudience === 'agents_only' ? 'checked' : ''; ?> />
                        Delivery agents only — customers and other users won't see this section at all
                    </label>

                    <button type="submit" class="button button-primary" style="margin-top:12px;">Save delivery feed settings</button>
                </form>
            </section>

            <!-- Session timeout settings -->
            <section class="panel">
                <h2>Session Settings</h2>
                <p class="meta">How long a session stays valid after the last activity, per account type. Set to 0 to disable idle timeout for that role. A session is also always force-ended immediately when its user changes their password.</p>
                <form method="post" action="monetization.php">
                    <input type="hidden" name="action" value="save_session_settings" />
                    <table class="pkg-table">
                        <thead><tr><th>Account type</th><th style="width:160px;">Idle timeout (minutes)</th></tr></thead>
                        <tbody>
                            <tr>
                                <td><strong>Customer</strong></td>
                                <td><input type="number" name="session_timeout_customer" min="0" max="10080" value="<?php echo (int)$sessionTimeouts['customer']; ?>" /></td>
                            </tr>
                            <tr>
                                <td><strong>Worker</strong></td>
                                <td><input type="number" name="session_timeout_worker" min="0" max="10080" value="<?php echo (int)$sessionTimeouts['worker']; ?>" /></td>
                            </tr>
                            <tr>
                                <td><strong>Manager</strong></td>
                                <td><input type="number" name="session_timeout_manager" min="0" max="10080" value="<?php echo (int)$sessionTimeouts['manager']; ?>" /></td>
                            </tr>
                            <tr>
                                <td><strong>Admin</strong></td>
                                <td><input type="number" name="session_timeout_admin" min="0" max="10080" value="<?php echo (int)$sessionTimeouts['admin']; ?>" /></td>
                            </tr>
                        </tbody>
                    </table>
                    <button type="submit" class="button button-primary" style="margin-top:12px;">Save session settings</button>
                </form>
            </section>

            <!-- Email verification requirements -->
            <section class="panel">
                <h2>Email Verification Requirements</h2>
                <p class="meta">Choose which actions require a user to have verified their email address first. Unchecked = allowed even if unverified.</p>
                <form method="post" action="monetization.php">
                    <input type="hidden" name="action" value="save_verification_settings" />
                    <table class="pkg-table">
                        <tbody>
                            <tr>
                                <td><strong>Login to the platform</strong><br><span class="meta">Blocks unverified accounts from logging in at all</span></td>
                                <td style="width:80px;text-align:center;">
                                    <input type="checkbox" name="require_verified_email_login" value="1" <?php echo $verifyReqs['login'] ? 'checked' : ''; ?> />
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Post a Job</strong><br><span class="meta">Customer must verify email before posting a job</span></td>
                                <td style="width:80px;text-align:center;">
                                    <input type="checkbox" name="require_verified_email_job_post" value="1" <?php echo $verifyReqs['job_post'] ? 'checked' : ''; ?> />
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Apply for a Job</strong><br><span class="meta">Worker must verify email before applying to a job</span></td>
                                <td style="width:80px;text-align:center;">
                                    <input type="checkbox" name="require_verified_email_job_apply" value="1" <?php echo $verifyReqs['job_apply'] ? 'checked' : ''; ?> />
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Submit News Article</strong><br><span class="meta">Author must verify email before submitting an article</span></td>
                                <td style="width:80px;text-align:center;">
                                    <input type="checkbox" name="require_verified_email_news_post" value="1" <?php echo $verifyReqs['news_post'] ? 'checked' : ''; ?> />
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Submit Event</strong><br><span class="meta">Organiser must verify email before submitting an event</span></td>
                                <td style="width:80px;text-align:center;">
                                    <input type="checkbox" name="require_verified_email_event_post" value="1" <?php echo $verifyReqs['event_post'] ? 'checked' : ''; ?> />
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Submit Funeral Announcement</strong><br><span class="meta">Family/organiser must verify email before submitting an announcement</span></td>
                                <td style="width:80px;text-align:center;">
                                    <input type="checkbox" name="require_verified_email_funeral_post" value="1" <?php echo $verifyReqs['funeral_post'] ? 'checked' : ''; ?> />
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Create Marketplace Shop</strong><br><span class="meta">Seller must verify email before creating a shop</span></td>
                                <td style="width:80px;text-align:center;">
                                    <input type="checkbox" name="require_verified_email_shop_create" value="1" <?php echo $verifyReqs['shop_create'] ? 'checked' : ''; ?> />
                                </td>
                            </tr>
                            <tr>
                                <td><strong>List Marketplace Product</strong><br><span class="meta">Seller must verify email before listing a new product</span></td>
                                <td style="width:80px;text-align:center;">
                                    <input type="checkbox" name="require_verified_email_product_post" value="1" <?php echo $verifyReqs['product_post'] ? 'checked' : ''; ?> />
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Create Delivery Request</strong><br><span class="meta">Customer must verify email before requesting a delivery</span></td>
                                <td style="width:80px;text-align:center;">
                                    <input type="checkbox" name="require_verified_email_delivery_request" value="1" <?php echo $verifyReqs['delivery_request'] ? 'checked' : ''; ?> />
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Register as Delivery Agent</strong><br><span class="meta">Rider must verify email before registering</span></td>
                                <td style="width:80px;text-align:center;">
                                    <input type="checkbox" name="require_verified_email_delivery_agent" value="1" <?php echo $verifyReqs['delivery_agent'] ? 'checked' : ''; ?> />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <button type="submit" class="button button-primary" style="margin-top:12px;">Save verification requirements</button>
                </form>
            </section>
        </div>

        <!-- ═══════════════ JOB PACKAGES TAB (Featured, Posting) ═══════════════ -->
        <div class="tab-panel <?php echo $tab === 'job_pkgs' ? 'active' : ''; ?>" id="tab-job_pkgs">
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

            <section class="panel" style="margin-top:20px;">
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

        <!-- ═══════════════ WORKER PACKAGES TAB (Featured, Verification, Listing, Premium) ═══════════════ -->
        <div class="tab-panel <?php echo $tab === 'worker_pkgs' ? 'active' : ''; ?>" id="tab-worker_pkgs">
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
            <section class="panel" style="margin-bottom:20px;">
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

            <section class="panel" style="margin-bottom:20px;">
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
                                    <strong><?php
                                        if ($vp['id_type'] === 'other' && !empty($vp['id_type_custom'])) {
                                            echo 'Other: ' . sanitize($vp['id_type_custom']);
                                        } else {
                                            echo sanitize($vp['id_type'] ? strtoupper(str_replace('_', ' ', $vp['id_type'])) : '—');
                                        }
                                    ?></strong>
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

            <section class="panel" style="margin-bottom:20px;">
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

            <section class="panel">
                <h2>Worker Premium Subscription Packages</h2>
                <table class="pkg-table">
                    <thead><tr><th>Name</th><th>Duration</th><th>Price (GH₵)</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($allWorkerPremiumPackages as $pkg): ?>
                            <tr>
                                <td><?php echo sanitize($pkg['name']); ?></td>
                                <td><?php echo $pkg['duration_days']; ?> days</td>
                                <td><?php echo number_format($pkg['price'], 2); ?></td>
                                <td><span class="status status-<?php echo $pkg['status'] === 'active' ? 'open' : 'cancelled'; ?>"><?php echo strtoupper($pkg['status']); ?></span></td>
                                <td>
                                    <button class="button button-small button-secondary" onclick="editPackage('worker_premium', <?php echo $pkg['id']; ?>, '<?php echo sanitize($pkg['name']); ?>', '<?php echo sanitize($pkg['description'] ?? ''); ?>', <?php echo $pkg['duration_days']; ?>, <?php echo $pkg['price']; ?>, '<?php echo $pkg['status']; ?>')">Edit</button>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this package?')">
                                        <input type="hidden" name="action" value="delete_package" />
                                        <input type="hidden" name="pkg_type" value="worker_premium" />
                                        <input type="hidden" name="pkg_id" value="<?php echo $pkg['id']; ?>" />
                                        <button type="submit" class="button button-small button-secondary">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <h3 style="margin-top: 20px;">Add / Edit Package</h3>
                <form method="post" action="monetization.php" id="form-worker_premium">
                    <input type="hidden" name="action" value="save_package" />
                    <input type="hidden" name="pkg_type" value="worker_premium" />
                    <input type="hidden" name="pkg_id" id="worker_premium_id" value="0" />
                    <label>Package name</label>
                    <input type="text" name="pkg_name" id="worker_premium_name" required placeholder="e.g. Monthly Premium" />
                    <label>Description <span class="meta">(shown to workers — optional)</span></label>
                    <textarea name="pkg_description" id="worker_premium_description" rows="2" placeholder="e.g. Rank higher in search results for 30 days." style="resize:vertical;"></textarea>
                    <label>Duration (days)</label>
                    <input type="number" name="pkg_days" id="worker_premium_days" required min="1" value="30" />
                    <label>Price (GH₵)</label>
                    <input type="number" name="pkg_price" id="worker_premium_price" required min="0" step="0.01" value="0" />
                    <label>Status</label>
                    <select name="pkg_status" id="worker_premium_status"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                    <div style="display:flex;gap:8px;margin-top:8px;">
                        <button type="submit" class="button button-primary">Save package</button>
                        <button type="button" class="button button-secondary" onclick="resetForm('worker_premium')">Clear</button>
                    </div>
                </form>
            </section>
        </div>

        <!-- ═══════════════ COMMUNITY PACKAGES TAB ═══════════════ -->
        <div class="tab-panel <?php echo $tab === 'community' ? 'active' : ''; ?>" id="tab-community">

            <?php
            $communityPackageSections = [
                ['type' => 'featured_event',   'label' => 'Featured Event Packages',   'icon' => '📅', 'packages' => $allFeaturedEventPackages],
                ['type' => 'featured_funeral',  'label' => 'Featured Funeral Packages', 'icon' => '🕊️', 'packages' => $allFeaturedFuneralPackages],
                ['type' => 'featured_news',     'label' => 'Featured News Packages',    'icon' => '📰', 'packages' => $allFeaturedNewsPackages],
                ['type' => 'sponsor',           'label' => 'Sponsor Packages',          'icon' => '🤝', 'packages' => $allSponsorPackages],
            ];
            foreach ($communityPackageSections as $sec):
            ?>
            <section class="panel" style="margin-bottom:20px;">
                <h2 style="margin-top:0;"><?php echo $sec['icon']; ?> <?php echo $sec['label']; ?></h2>
                <table class="pkg-table">
                    <thead><tr><th>Name</th><th>Duration</th><th>Price (GH₵)</th><th>Status</th><?php if ($sec['type'] === 'sponsor'): ?><th>Benefits</th><?php endif; ?><th>Actions</th></tr></thead>
                    <tbody>
                        <?php if (empty($sec['packages'])): ?>
                        <tr><td colspan="<?php echo $sec['type'] === 'sponsor' ? 6 : 5; ?>" style="text-align:center;color:var(--muted,#6b7280);padding:18px;">No packages yet — add one below.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($sec['packages'] as $pkg): ?>
                        <tr>
                            <td><?php echo sanitize($pkg['name']); ?></td>
                            <td><?php echo (int)$pkg['duration_days']; ?> days</td>
                            <td><?php echo number_format((float)$pkg['price'], 2); ?></td>
                            <td><span class="status status-<?php echo $pkg['status'] === 'active' ? 'open' : 'cancelled'; ?>"><?php echo strtoupper($pkg['status']); ?></span></td>
                            <?php if ($sec['type'] === 'sponsor'): ?>
                            <td style="font-size:.8rem;color:var(--muted,#6b7280);"><?php echo !empty($pkg['benefits']) ? '✓ set' : '—'; ?></td>
                            <?php endif; ?>
                            <td>
                                <button class="button button-small button-secondary" onclick="editCommPkg('<?php echo $sec['type']; ?>', <?php echo $pkg['id']; ?>, '<?php echo addslashes(sanitize($pkg['name'])); ?>', <?php echo (int)$pkg['duration_days']; ?>, <?php echo (float)$pkg['price']; ?>, '<?php echo $pkg['status']; ?>', '<?php echo htmlspecialchars(addslashes($pkg['benefits'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>')">Edit</button>
                                <form method="post" class="inline-form" onsubmit="return confirm('Delete this package?')">
                                    <input type="hidden" name="action" value="delete_package">
                                    <input type="hidden" name="pkg_type" value="<?php echo $sec['type']; ?>">
                                    <input type="hidden" name="pkg_id" value="<?php echo $pkg['id']; ?>">
                                    <button type="submit" class="button button-small button-secondary">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <h3 style="margin-top:20px;">Add / Edit Package</h3>
                <form method="post" action="monetization.php" id="form-<?php echo $sec['type']; ?>">
                    <input type="hidden" name="action" value="save_package">
                    <input type="hidden" name="pkg_type" value="<?php echo $sec['type']; ?>">
                    <input type="hidden" name="pkg_id" id="<?php echo $sec['type']; ?>_id" value="0">
                    <label>Package name</label>
                    <input type="text" name="pkg_name" id="<?php echo $sec['type']; ?>_name" required placeholder="e.g. 7-Day Spotlight">
                    <label>Duration (days)</label>
                    <input type="number" name="pkg_days" id="<?php echo $sec['type']; ?>_days" required min="1" value="7">
                    <label>Price (GH₵)</label>
                    <input type="number" name="pkg_price" id="<?php echo $sec['type']; ?>_price" required min="0" step="0.01" value="0">
                    <label>Status</label>
                    <select name="pkg_status" id="<?php echo $sec['type']; ?>_status">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                    <?php if ($sec['type'] === 'sponsor'): ?>
                    <label>Benefits <span style="font-weight:400;color:var(--muted,#6b7280);">(shown on become_sponsor.php — format as a bullet list, add bold, links, etc.)</span></label>
                    <textarea name="pkg_benefits" id="sponsor_benefits" class="rich-editor" rows="8" placeholder="⭐ Premium Gold Sponsor badge&#10;🏆 Recognition on the homepage&#10;..."></textarea>
                    <?php endif; ?>
                    <div style="display:flex;gap:8px;margin-top:8px;">
                        <button type="submit" class="button button-primary">Save package</button>
                        <button type="button" class="button button-secondary" onclick="resetCommPkg('<?php echo $sec['type']; ?>')">Clear</button>
                    </div>
                </form>
            </section>
            <?php endforeach; ?>

            <script>
            /** Pushes new content into a rich-editor.js field, whether or not
             *  it has finished wrapping the textarea yet (RichEditor exposes
             *  itself as `ta._rte`, with `.ed` the visible editable div and
             *  `._sync()` to push that back into the real textarea value —
             *  see assets/js/rich-editor.js). Falls back to a plain .value
             *  set if the field isn't a rich-editor (or hasn't init'd yet). */
            function setRichEditorValue(id, html) {
                var ta = document.getElementById(id);
                if (!ta) return;
                if (ta._rte) {
                    ta._rte.ed.innerHTML = html || '<p><br></p>';
                    ta._rte._sync();
                } else {
                    ta.value = html || '';
                }
            }
            function editCommPkg(type, id, name, days, price, status, benefits) {
                document.getElementById(type+'_id').value   = id;
                document.getElementById(type+'_name').value = name;
                document.getElementById(type+'_days').value = days;
                document.getElementById(type+'_price').value = price;
                document.getElementById(type+'_status').value = status;
                if (type === 'sponsor') setRichEditorValue('sponsor_benefits', benefits);
                document.getElementById('form-'+type).scrollIntoView({behavior:'smooth',block:'center'});
            }
            function resetCommPkg(type) {
                document.getElementById(type+'_id').value    = 0;
                document.getElementById(type+'_name').value  = '';
                document.getElementById(type+'_days').value  = 7;
                document.getElementById(type+'_price').value = 0;
                document.getElementById(type+'_status').value = 'active';
                if (type === 'sponsor') setRichEditorValue('sponsor_benefits', '');
            }
            </script>
        </div>

        <!-- ═══════════════ MARKETPLACE TAB ═══════════════ -->
        <div class="tab-panel <?php echo $tab === 'marketplace' ? 'active' : ''; ?>" id="tab-marketplace">

            <?php
            $boostTypeLabels = [
                'featured_product'  => ['icon'=>'⭐','label'=>'Featured Product'],
                'sponsored_product' => ['icon'=>'🌟','label'=>'Sponsored Product'],
                'featured_shop'     => ['icon'=>'🏆','label'=>'Featured Shop'],
                'sponsored_shop'    => ['icon'=>'💎','label'=>'Sponsored Shop'],
            ];
            $settingKeys = [
                'featured_product'  => 'mp_featured_product_enabled',
                'sponsored_product' => 'mp_sponsored_product_enabled',
                'featured_shop'     => 'mp_featured_shop_enabled',
                'sponsored_shop'    => 'mp_sponsored_shop_enabled',
            ];
            ?>

            <!-- Revenue Dashboard -->
            <section class="panel" style="margin-bottom:20px;">
                <h2 style="margin-top:0;font-size:1rem;">📊 Marketplace Revenue</h2>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin-bottom:16px;">
                    <div style="background:var(--primary-soft,#d1fae5);border-radius:12px;padding:14px;text-align:center;">
                        <strong style="display:block;font-size:1.4rem;font-weight:900;color:var(--primary,#0f766e);">GH₵ <?php echo number_format($mpTotalRevenue,2); ?></strong>
                        <span style="font-size:.72rem;color:var(--primary-dark,#065f46);">Total Boost Revenue</span>
                    </div>
                    <?php foreach ($boostTypeLabels as $bt => $bl): ?>
                    <div style="background:var(--surface-muted,#f8fafc);border:1px solid var(--border);border-radius:12px;padding:12px;text-align:center;">
                        <strong style="display:block;font-size:1.2rem;font-weight:900;color:var(--primary,#0f766e);">GH₵ <?php echo number_format($mpBoostRevenue[$bt]['rev']??0,2); ?></strong>
                        <span style="font-size:.68rem;color:var(--muted,#6b7280);"><?php echo $bl['icon'].' '.$bl['label']; ?></span>
                        <div style="font-size:.66rem;color:var(--muted,#6b7280);"><?php echo $mpBoostRevenue[$bt]['cnt']??0; ?> orders</div>
                    </div>
                    <?php endforeach; ?>
                    <?php if ((float)$mpSubRevenue > 0): ?>
                    <div style="background:var(--surface-muted,#f8fafc);border:1px solid var(--border);border-radius:12px;padding:12px;text-align:center;">
                        <strong style="display:block;font-size:1.2rem;font-weight:900;color:#8b5cf6;">GH₵ <?php echo number_format($mpSubRevenue,2); ?></strong>
                        <span style="font-size:.68rem;color:var(--muted,#6b7280);">Subscriptions</span>
                    </div>
                    <?php endif; ?>
                    <div style="background:var(--surface-muted,#f8fafc);border:1px solid var(--border);border-radius:12px;padding:12px;text-align:center;">
                        <strong style="display:block;font-size:1.2rem;font-weight:900;"><?php echo count($mpActiveBoosts); ?></strong>
                        <span style="font-size:.68rem;color:var(--muted,#6b7280);">Active Boosts</span>
                    </div>
                </div>
            </section>

            <!-- Global Settings -->
            <section class="panel" style="margin-bottom:20px;">
                <h2 style="margin-top:0;font-size:1rem;">⚙️ Global Settings</h2>
                <form method="post" action="monetization.php?tab=marketplace">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="save_mp_settings">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;">
                        <?php foreach ($boostTypeLabels as $bt => $bl): ?>
                        <label style="display:flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid var(--border);border-radius:8px;cursor:pointer;font-size:.86rem;">
                            <input type="checkbox" name="<?php echo $settingKeys[$bt]; ?>" value="1" <?php echo ($mpSettings[$settingKeys[$bt]]??'1')==='1'?'checked':''; ?>>
                            <?php echo $bl['icon'].' Enable '.$bl['label']; ?>
                        </label>
                        <?php endforeach; ?>
                        <label style="display:flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid var(--border);border-radius:8px;cursor:pointer;font-size:.86rem;">
                            <input type="checkbox" name="mp_boost_requires_payment" value="1" <?php echo ($mpSettings['mp_boost_requires_payment']??'1')==='1'?'checked':''; ?>>
                            🔒 Require Paystack payment for boosts
                        </label>
                        <label style="display:flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid var(--border);border-radius:8px;cursor:pointer;font-size:.86rem;">
                            <input type="checkbox" name="mp_subscription_enabled" value="1" <?php echo ($mpSettings['mp_subscription_enabled']??'0')==='1'?'checked':''; ?>>
                            ⭐ Enable seller subscriptions
                        </label>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
                        <label style="font-size:.86rem;font-weight:600;">Verified Seller Fee (GH₵ — 0 = free)</label>
                        <input type="number" name="mp_verified_seller_fee" min="0" step="0.01"
                               value="<?php echo htmlspecialchars($mpSettings['mp_verified_seller_fee']??'0.00'); ?>"
                               style="width:100px;padding:7px 10px;border:1px solid var(--border);border-radius:8px;">
                    </div>
                    <button type="submit" class="button button-primary">Save Settings</button>
                </form>
            </section>

            <!-- Boost Packages per Type -->
            <?php foreach ($boostTypeLabels as $bt => $bl): ?>
            <section class="panel" style="margin-bottom:16px;">
                <h3 style="margin-top:0;font-size:.95rem;"><?php echo $bl['icon'].' '.$bl['label']; ?> Packages</h3>
                <?php $typePkgs = $mpBoostPkgByType[$bt] ?? []; ?>
                <?php if ($typePkgs): ?>
                <table class="pkg-table" style="margin-bottom:12px;">
                    <thead><tr><th>Name</th><th>Days</th><th>Price</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($typePkgs as $pk): ?>
                    <tr style="<?php echo $pk['status']==='inactive'?'opacity:.5':''; ?>">
                        <td><?php echo sanitize($pk['name']); ?></td>
                        <td><?php echo (int)$pk['duration_days']; ?></td>
                        <td style="font-weight:700;color:var(--primary);">GH₵ <?php echo number_format((float)$pk['price'],2); ?></td>
                        <td><span style="font-size:.68rem;font-weight:800;padding:2px 8px;border-radius:20px;background:<?php echo $pk['status']==='active'?'#d1fae5':'#f3f4f6'; ?>;color:<?php echo $pk['status']==='active'?'#065f46':'#6b7280'; ?>;"><?php echo ucfirst($pk['status']); ?></span></td>
                        <td>
                            <button type="button" class="button button-small button-secondary" style="font-size:.7rem;padding:2px 8px;"
                                onclick="editBoostPkg('<?php echo $bt; ?>', <?php echo $pk['id']; ?>, '<?php echo addslashes(sanitize($pk['name'])); ?>', <?php echo (int)$pk['duration_days']; ?>, <?php echo (float)$pk['price']; ?>, '<?php echo $pk['status']; ?>')">Edit</button>
                            <form method="post" style="display:inline;">
                                <?php echo csrf_field(); ?><input type="hidden" name="action" value="save_mp_boost_pkg">
                                <input type="hidden" name="pkg_id" value="<?php echo $pk['id']; ?>">
                                <input type="hidden" name="boost_type" value="<?php echo $bt; ?>">
                                <input type="hidden" name="pkg_name" value="<?php echo htmlspecialchars($pk['name']); ?>">
                                <input type="hidden" name="pkg_days" value="<?php echo $pk['duration_days']; ?>">
                                <input type="hidden" name="pkg_price" value="<?php echo $pk['price']; ?>">
                                <input type="hidden" name="pkg_status" value="<?php echo $pk['status']==='active'?'inactive':'active'; ?>">
                                <button type="submit" class="button button-small button-secondary" style="font-size:.7rem;padding:2px 8px;"><?php echo $pk['status']==='active'?'Disable':'Enable'; ?></button>
                            </form>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete?')">
                                <?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_mp_boost_pkg"><input type="hidden" name="pkg_id" value="<?php echo $pk['id']; ?>">
                                <button type="submit" class="button button-small" style="font-size:.7rem;padding:2px 8px;background:#fee2e2;color:#991b1b;border-color:transparent;">Del</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                <!-- Add / Edit package -->
                <form method="post" id="form-boost-<?php echo $bt; ?>" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                    <?php echo csrf_field(); ?><input type="hidden" name="action" value="save_mp_boost_pkg"><input type="hidden" name="boost_type" value="<?php echo $bt; ?>"><input type="hidden" name="pkg_id" id="boost-<?php echo $bt; ?>-id" value="0">
                    <div><label style="font-size:.72rem;font-weight:700;display:block;margin-bottom:3px;">Name</label><input type="text" name="pkg_name" id="boost-<?php echo $bt; ?>-name" placeholder="e.g. 7 Days" style="padding:6px 10px;border:1px solid var(--border);border-radius:8px;width:110px;"></div>
                    <div><label style="font-size:.72rem;font-weight:700;display:block;margin-bottom:3px;">Days</label><input type="number" name="pkg_days" id="boost-<?php echo $bt; ?>-days" min="1" value="7" style="padding:6px 10px;border:1px solid var(--border);border-radius:8px;width:70px;"></div>
                    <div><label style="font-size:.72rem;font-weight:700;display:block;margin-bottom:3px;">Price (GH₵)</label><input type="number" name="pkg_price" id="boost-<?php echo $bt; ?>-price" min="0" step="0.01" value="0" style="padding:6px 10px;border:1px solid var(--border);border-radius:8px;width:90px;"></div>
                    <input type="hidden" name="pkg_status" id="boost-<?php echo $bt; ?>-status" value="active">
                    <button type="submit" class="button button-primary button-small" id="boost-<?php echo $bt; ?>-submit">+ Add Package</button>
                    <button type="button" class="button button-secondary button-small" onclick="resetBoostPkgForm('<?php echo $bt; ?>')">Clear</button>
                </form>
            </section>
            <?php endforeach; ?>

            <!-- Seller Subscription Plans -->
            <section class="panel" style="margin-bottom:20px;">
                <h2 style="margin-top:0;font-size:1rem;">⭐ Seller Subscription Plans</h2>
                <?php if ($mpSubPlans): ?>
                <table class="pkg-table" style="margin-bottom:14px;">
                    <thead><tr><th>Plan</th><th>Days</th><th>Price</th><th>Product Limit</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($mpSubPlans as $plan): ?>
                    <tr style="<?php echo $plan['status']==='inactive'?'opacity:.5':''; ?>">
                        <td><strong><?php echo sanitize($plan['name']); ?></strong><?php if ($plan['description']): ?><br><span style="font-size:.76rem;color:var(--muted,#6b7280);"><?php echo sanitize(mb_substr($plan['description'],0,60)); ?></span><?php endif; ?></td>
                        <td><?php echo (int)$plan['duration_days']; ?></td>
                        <td style="font-weight:700;color:var(--primary);">GH₵ <?php echo number_format((float)$plan['price'],2); ?></td>
                        <td><?php echo $plan['product_limit']==-1?'Unlimited':(int)$plan['product_limit']; ?></td>
                        <td><span style="font-size:.68rem;font-weight:800;padding:2px 8px;border-radius:20px;background:<?php echo $plan['status']==='active'?'#d1fae5':'#f3f4f6'; ?>;color:<?php echo $plan['status']==='active'?'#065f46':'#6b7280'; ?>;"><?php echo ucfirst($plan['status']); ?></span></td>
                        <td>
                            <button type="button" class="button button-small button-secondary" style="font-size:.7rem;padding:2px 8px;"
                                onclick="editMpSubPlan(<?php echo $plan['id']; ?>, '<?php echo addslashes(sanitize($plan['name'])); ?>', '<?php echo addslashes(sanitize($plan['description'] ?? '')); ?>', <?php echo (int)$plan['duration_days']; ?>, <?php echo (float)$plan['price']; ?>, <?php echo (int)$plan['product_limit']; ?>, '<?php echo $plan['status']; ?>')">Edit</button>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete plan?')">
                                <?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_mp_sub_plan"><input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
                                <button type="submit" class="button button-small" style="font-size:.7rem;padding:2px 8px;background:#fee2e2;color:#991b1b;border-color:transparent;">Del</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                <details id="mp-sub-plan-details" style="margin-top:10px;">
                    <summary style="font-size:.84rem;font-weight:700;cursor:pointer;color:var(--primary);">+ Add / Edit Subscription Plan</summary>
                    <form method="post" id="form-mp-sub-plan" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px;">
                        <?php echo csrf_field(); ?><input type="hidden" name="action" value="save_mp_sub_plan"><input type="hidden" name="plan_id" id="mp-sub-plan-id" value="0">
                        <div><label style="font-size:.78rem;font-weight:700;display:block;margin-bottom:3px;">Plan Name *</label><input type="text" name="plan_name" id="mp-sub-plan-name" required placeholder="e.g. Growth" style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:8px;"></div>
                        <div><label style="font-size:.78rem;font-weight:700;display:block;margin-bottom:3px;">Duration (days)</label><input type="number" name="plan_days" id="mp-sub-plan-days" min="1" value="30" style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:8px;"></div>
                        <div><label style="font-size:.78rem;font-weight:700;display:block;margin-bottom:3px;">Price (GH₵)</label><input type="number" name="plan_price" id="mp-sub-plan-price" min="0" step="0.01" value="0" style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:8px;"></div>
                        <div><label style="font-size:.78rem;font-weight:700;display:block;margin-bottom:3px;">Product Limit (-1 = unlimited)</label><input type="number" name="plan_limit" id="mp-sub-plan-limit" value="-1" style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:8px;"></div>
                        <div style="grid-column:1/-1;"><label style="font-size:.78rem;font-weight:700;display:block;margin-bottom:3px;">Description</label><input type="text" name="plan_desc" id="mp-sub-plan-desc" placeholder="Describe what sellers get..." style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:8px;"></div>
                        <div><label style="font-size:.78rem;font-weight:700;display:block;margin-bottom:3px;">Status</label><select name="plan_status" id="mp-sub-plan-status" style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:8px;"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                        <div style="grid-column:1/-1;display:flex;gap:8px;"><button type="submit" class="button button-primary" id="mp-sub-plan-submit">Save Plan</button><button type="button" class="button button-secondary" onclick="resetMpSubPlanForm()">Clear</button></div>
                    </form>
                </details>
            </section>

            <!-- Pending Boost Orders -->
            <?php if ($mpPendingBoosts): ?>
            <section class="panel" style="margin-bottom:20px;">
                <h2 style="margin-top:0;font-size:1rem;color:#f59e0b;">⏳ Pending Boost Orders (<?php echo count($mpPendingBoosts); ?>)</h2>
                <p style="font-size:.8rem;color:var(--muted,#6b7280);margin:0 0 14px;">These orders are awaiting payment confirmation or manual activation.</p>
                <?php foreach ($mpPendingBoosts as $b): ?>
                <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:10px;">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;">
                        <div>
                            <div style="font-weight:800;font-size:.9rem;"><?php echo sanitize($b['shop_name']); ?> <span style="font-size:.72rem;color:var(--muted,#6b7280);">by <?php echo sanitize($b['owner_name']); ?></span></div>
                            <div style="font-size:.78rem;color:var(--muted,#6b7280);margin-top:3px;">
                                <?php echo sanitize($boostTypeLabels[$b['boost_type']]['icon']??''); ?> <?php echo sanitize($boostTypeLabels[$b['boost_type']]['label']??$b['boost_type']); ?> &nbsp;·&nbsp;
                                <?php echo (int)$b['package_days']; ?> days &nbsp;·&nbsp;
                                <strong>GH₵ <?php echo number_format((float)$b['price_paid'],2); ?></strong> &nbsp;·&nbsp;
                                <?php echo sanitize($b['payment_method']); ?> <?php if ($b['mobi_number']): ?>(<?php echo sanitize($b['mobi_number']); ?>)<?php endif; ?>
                            </div>
                            <div style="font-size:.72rem;color:var(--muted,#6b7280);">Would run <?php echo date('d M Y',strtotime($b['start_date'])); ?> → <?php echo date('d M Y',strtotime($b['end_date'])); ?></div>
                        </div>
                        <form method="post" style="margin:0;">
                            <?php echo csrf_field(); ?><input type="hidden" name="action" value="activate_boost"><input type="hidden" name="boost_id" value="<?php echo $b['id']; ?>">
                            <button type="submit" class="button button-primary button-small">✓ Activate</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </section>
            <?php endif; ?>

            <!-- Active Boosts -->
            <?php if ($mpActiveBoosts): ?>
            <section class="panel">
                <h2 style="margin-top:0;font-size:1rem;">✅ Active Boosts (<?php echo count($mpActiveBoosts); ?>)</h2>
                <table class="pkg-table">
                    <thead><tr><th>Shop</th><th>Type</th><th>Duration</th><th>Expires</th></tr></thead>
                    <tbody>
                    <?php foreach ($mpActiveBoosts as $b): ?>
                    <tr>
                        <td><strong><?php echo sanitize($b['shop_name']); ?></strong><br><span style="font-size:.74rem;color:var(--muted,#6b7280);"><?php echo sanitize($b['owner_name']); ?></span></td>
                        <td><?php echo sanitize($boostTypeLabels[$b['boost_type']]['icon']??''); ?> <?php echo sanitize($boostTypeLabels[$b['boost_type']]['label']??$b['boost_type']); ?></td>
                        <td><?php echo (int)$b['package_days']; ?> days</td>
                        <td><?php
                        $daysLeft = (int)ceil((strtotime($b['end_date'])-time())/86400);
                        $col = $daysLeft<=3?'#ef4444':($daysLeft<=7?'#f59e0b':'#10b981');
                        ?><span style="color:<?php echo $col; ?>;font-weight:700;"><?php echo date('d M Y',strtotime($b['end_date'])); ?></span>
                        <span style="font-size:.72rem;color:var(--muted,#6b7280);"> (<?php echo $daysLeft; ?>d left)</span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
            <?php else: ?>
            <div class="empty-state">No active boosts right now.</div>
            <?php endif; ?>

        </div>
        <!-- END MARKETPLACE TAB -->

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
                            <optgroup label="Jobs &amp; Workers">
                            <option value="featured_job"    <?php echo $filterType === 'featured_job'    ? 'selected' : ''; ?>>Featured Job</option>
                            <option value="featured_worker" <?php echo $filterType === 'featured_worker' ? 'selected' : ''; ?>>Featured Worker</option>
                            <option value="verification"    <?php echo $filterType === 'verification'    ? 'selected' : ''; ?>>Verification</option>
                            <option value="job_post"        <?php echo $filterType === 'job_post'        ? 'selected' : ''; ?>>Job Posting</option>
                            <option value="worker_service"  <?php echo $filterType === 'worker_service'  ? 'selected' : ''; ?>>Service Listing</option>
                            <option value="escrow_payment"  <?php echo $filterType === 'escrow_payment'  ? 'selected' : ''; ?>>Escrow Payment</option>
                            </optgroup>
                            <optgroup label="Community">
                            <option value="featured_event"  <?php echo $filterType === 'featured_event'  ? 'selected' : ''; ?>>Featured Event</option>
                            <option value="featured_funeral"<?php echo $filterType === 'featured_funeral' ? 'selected' : ''; ?>>Featured Funeral</option>
                            <option value="featured_news"   <?php echo $filterType === 'featured_news'   ? 'selected' : ''; ?>>Featured News</option>
                            <option value="event_post"      <?php echo $filterType === 'event_post'      ? 'selected' : ''; ?>>Event Post Fee</option>
                            <option value="funeral_post"    <?php echo $filterType === 'funeral_post'    ? 'selected' : ''; ?>>Funeral Post Fee</option>
                            <option value="news_post"       <?php echo $filterType === 'news_post'       ? 'selected' : ''; ?>>News Post Fee</option>
                            <option value="sponsor"         <?php echo $filterType === 'sponsor'         ? 'selected' : ''; ?>>Sponsorship</option>
                            </optgroup>
                            <optgroup label="Marketplace">
                            <option value="mp_boost"        <?php echo $filterType === 'mp_boost'        ? 'selected' : ''; ?>>Marketplace Boost</option>
                            <option value="mp_subscription" <?php echo $filterType === 'mp_subscription' ? 'selected' : ''; ?>>Seller Subscription</option>
                            </optgroup>
                            <optgroup label="Delivery">
                            <option value="delivery_subscription" <?php echo $filterType === 'delivery_subscription' ? 'selected' : ''; ?>>Rider Subscription</option>
                            <option value="delivery_sponsored"    <?php echo $filterType === 'delivery_sponsored'    ? 'selected' : ''; ?>>Rider Sponsored</option>
                            <option value="delivery_verification" <?php echo $filterType === 'delivery_verification' ? 'selected' : ''; ?>>Rider Verification</option>
                            </optgroup>
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
                <h2 style="margin-top:0;">Payment History <span class="meta">(<?php echo $histTotal; ?> <?php echo $filtersActive ? 'matching' : 'total'; ?>)</span></h2>
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
                    <?php if ($histTotalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($histPage > 1): ?><a href="<?php echo mono_hist_qstr(['hpage' => $histPage - 1]); ?>">‹ Prev</a><?php endif; ?>
                        <?php
                        $hpStart = max(1, $histPage - 3);
                        $hpEnd   = min($histTotalPages, $histPage + 3);
                        if ($hpStart > 1) echo '<span>…</span>';
                        for ($hp = $hpStart; $hp <= $hpEnd; $hp++): ?>
                            <?php if ($hp === $histPage): ?><span class="current"><?php echo $hp; ?></span>
                            <?php else: ?><a href="<?php echo mono_hist_qstr(['hpage' => $hp]); ?>"><?php echo $hp; ?></a><?php endif; ?>
                        <?php endfor;
                        if ($hpEnd < $histTotalPages) echo '<span>…</span>';
                        ?>
                        <?php if ($histPage < $histTotalPages): ?><a href="<?php echo mono_hist_qstr(['hpage' => $histPage + 1]); ?>">Next ›</a><?php endif; ?>
                        <span style="color:var(--text-muted,#6b7280);border:none;padding-left:4px;">Page <?php echo $histPage; ?> of <?php echo $histTotalPages; ?></span>
                    </div>
                    <?php endif; ?>
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

        function editBoostPkg(type, id, name, days, price, status) {
            document.getElementById('boost-' + type + '-id').value = id;
            document.getElementById('boost-' + type + '-name').value = name;
            document.getElementById('boost-' + type + '-days').value = days;
            document.getElementById('boost-' + type + '-price').value = price;
            document.getElementById('boost-' + type + '-status').value = status;
            document.getElementById('boost-' + type + '-submit').textContent = 'Save Changes';
            document.getElementById('form-boost-' + type).scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        function resetBoostPkgForm(type) {
            document.getElementById('boost-' + type + '-id').value = 0;
            document.getElementById('form-boost-' + type).reset();
            document.getElementById('boost-' + type + '-status').value = 'active';
            document.getElementById('boost-' + type + '-submit').textContent = '+ Add Package';
        }

        function editMpSubPlan(id, name, desc, days, price, limit, status) {
            document.getElementById('mp-sub-plan-details').open = true;
            document.getElementById('mp-sub-plan-id').value = id;
            document.getElementById('mp-sub-plan-name').value = name;
            document.getElementById('mp-sub-plan-desc').value = desc;
            document.getElementById('mp-sub-plan-days').value = days;
            document.getElementById('mp-sub-plan-price').value = price;
            document.getElementById('mp-sub-plan-limit').value = limit;
            document.getElementById('mp-sub-plan-status').value = status;
            document.getElementById('mp-sub-plan-submit').textContent = 'Save Changes';
            document.getElementById('form-mp-sub-plan').scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        function resetMpSubPlanForm() {
            document.getElementById('mp-sub-plan-id').value = 0;
            document.getElementById('form-mp-sub-plan').reset();
            document.getElementById('mp-sub-plan-submit').textContent = 'Save Plan';
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
    <script src="../assets/js/rich-editor.js" defer></script>
</body>
</html>
