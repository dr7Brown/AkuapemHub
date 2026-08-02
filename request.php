<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_module_enabled('jobs', 'Jobs & Services');
require_login();
$user = current_user();

if (requires_verified_email('job_post') && !is_email_verified()) {
    flash('Please verify your email address before posting a job. Check your inbox.', 'error');
    header('Location: jobs.php');
    exit;
}

require_not_banned_from('jobs');

$categories = get_categories();
$error = '';

// Platform charge settings (DB-backed, fall back to compile-time defaults)
$commRate        = (float)get_platform_setting('escrow_commission_rate', DEFAULT_COMMISSION);
$autoReleaseDays = max(1, (int)get_platform_setting('escrow_auto_release_days', 7));
$escrowMinBudget = (float)get_platform_setting('escrow_min_budget', 0);

// Load existing draft for editing
$draft  = null;
$editId = intval($_GET['edit'] ?? 0);
if ($editId > 0) {
    $dStmt = $pdo->prepare("SELECT * FROM service_requests WHERE id = ? AND status IN ('draft','rejected') AND customer_id = ?");
    $dStmt->execute([$editId, $user['id']]);
    $draft = $dStmt->fetch();
    if (!$draft) {
        flash('Draft not found.', 'error');
        header('Location: jobs.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $rawCategoryInput   = trim($_POST['category_id'] ?? '');
    $customCategoryName = trim($_POST['other_category_name'] ?? '');
    $categoryId  = ($rawCategoryInput === '__other__') ? -1 : intval($rawCategoryInput);
    $location    = trim($_POST['location'] ?? '');
    $budget      = trim($_POST['budget'] ?? '');
    $contactInfo = trim($user['phone'] ?? '');
    $latitude    = ($_POST['latitude'] ?? '') !== '' ? (float)$_POST['latitude'] : null;
    $longitude   = ($_POST['longitude'] ?? '') !== '' ? (float)$_POST['longitude'] : null;
    $googleMapsLink = trim($_POST['google_maps_link'] ?? '') ?: null;
    $skillsNeeded = trim($_POST['skills_needed'] ?? '');
    $hiringType   = $_POST['hiring_type'] ?? 'single';
    $workersNeeded = $hiringType === 'multiple' ? max(1, intval($_POST['workers_needed'] ?? 1)) : 1;
    $jobTypeRaw = $_POST['job_type'] ?? 'on_site';
    $jobType    = in_array($jobTypeRaw, ['on_site','remote','hybrid'], true) ? $jobTypeRaw : 'on_site';

    // New fields
    $paymentModeRaw = $_POST['payment_mode'] ?? 'direct';
    $paymentMode = in_array($paymentModeRaw, ['escrow', 'direct'], true) ? $paymentModeRaw : 'direct';

    $deadlineValueRaw = intval($_POST['deadline_value'] ?? 0);
    $deadlineUnitRaw  = $_POST['deadline_unit'] ?? '';
    $deadlineUnit  = in_array($deadlineUnitRaw, ['hours', 'days', 'months'], true) ? $deadlineUnitRaw : null;
    $deadlineValue = ($deadlineValueRaw > 0 && $deadlineUnit) ? $deadlineValueRaw : null;
    if (!$deadlineValue) $deadlineUnit = null;

    $deadlineDate = null;
    if ($deadlineValue && $deadlineUnit) {
        $dt = new DateTime();
        if ($deadlineUnit === 'hours')  $dt->add(new DateInterval('PT' . $deadlineValue . 'H'));
        if ($deadlineUnit === 'days')   $dt->add(new DateInterval('P' . $deadlineValue . 'D'));
        if ($deadlineUnit === 'months') $dt->add(new DateInterval('P' . $deadlineValue . 'M'));
        $deadlineDate = $dt->format('Y-m-d H:i:s');
    }

    $submitType  = in_array($_POST['submit_type'] ?? '', ['draft', 'publish'], true) ? $_POST['submit_type'] : 'publish';
    $postEditId  = intval($_POST['edit_id'] ?? 0);

    // ── Save as Draft ─────────────────────────────────────────────────────────
    if ($submitType === 'draft') {
        if ($title === '') {
            $error = 'A title is required to save a draft.';
        } else {
            $draftCatId = $categoryId > 0 ? $categoryId : null;
            if ($rawCategoryInput === '__other__') {
                if ($customCategoryName !== '') {
                    $description = "Type of work: {$customCategoryName}\n\n{$description}";
                }
                $othStmt = $pdo->prepare("SELECT id FROM service_categories WHERE name = 'Other'");
                $othStmt->execute();
                $othCat = $othStmt->fetch();
                if ($othCat) {
                    $draftCatId = (int)$othCat['id'];
                } else {
                    $pdo->prepare("INSERT INTO service_categories (name) VALUES ('Other')")->execute();
                    $draftCatId = (int)$pdo->lastInsertId();
                }
            }
            $draftBudgetClean  = preg_replace('/[^0-9.]/', '', $budget);
            $draftBudgetAmount = is_numeric($draftBudgetClean) && (float)$draftBudgetClean > 0
                ? round((float)$draftBudgetClean, 2) : null;

            if ($postEditId > 0) {
                $pdo->prepare("UPDATE service_requests SET
                    title=?,description=?,category_id=?,location=?,latitude=?,longitude=?,google_maps_link=?,
                    budget=?,budget_amount=?,skills_needed=?,workers_needed=?,payment_mode=?,job_type=?,
                    deadline_value=?,deadline_unit=?,deadline_date=?,updated_at=NOW()
                    WHERE id=? AND status IN ('draft','rejected') AND customer_id=?")
                    ->execute([$title,$description,$draftCatId,$location ?: null,$latitude,$longitude,$googleMapsLink,
                        $budget,$draftBudgetAmount,$skillsNeeded ?: null,$workersNeeded,$paymentMode,$jobType,
                        $deadlineValue,$deadlineUnit,$deadlineDate,$postEditId,$user['id']]);
                flash('Draft updated.', 'info');
            } else {
                $pdo->prepare("INSERT INTO service_requests
                    (customer_id,title,description,category_id,location,latitude,longitude,google_maps_link,budget,budget_amount,
                     contact_info,skills_needed,workers_needed,payment_mode,job_type,deadline_value,deadline_unit,deadline_date,
                     status,payment_status,commission_percent,featured,posting_fee_status,created_at,updated_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'draft','unpaid',?,0,'free',NOW(),NOW())")
                    ->execute([$user['id'],$title,$description,$draftCatId,$location ?: null,$latitude,$longitude,$googleMapsLink,
                        $budget,$draftBudgetAmount,$contactInfo,$skillsNeeded ?: null,$workersNeeded,$paymentMode,$jobType,
                        $deadlineValue,$deadlineUnit,$deadlineDate,$commRate]);
                flash('Draft saved. Publish it from your dashboard whenever you are ready.', 'info');
            }
            header('Location: jobs.php');
            exit;
        }
    }
    // ─────────────────────────────────────────────────────────────────────────

    // Numeric budget required for escrow
    $budgetAmount = null;
    if ($paymentMode === 'escrow' && $error === '') {
        $budgetClean = preg_replace('/[^0-9.]/', '', $budget);
        if (!is_numeric($budgetClean) || (float)$budgetClean <= 0) {
            $error = 'Escrow payment requires a numeric budget amount (e.g. 500).';
        } else {
            $budgetAmount = round((float)$budgetClean, 2);
            $budget = number_format($budgetAmount, 2, '.', '');
            if ($escrowMinBudget > 0 && $budgetAmount < $escrowMinBudget) {
                $error = 'Minimum budget for escrow jobs is GH₵ ' . number_format($escrowMinBudget, 2) . '.';
            }
        }
    }

    if ($error === '' && ($title === '' || $description === '' || $categoryId === 0 || $location === '' || $budget === '')) {
        $error = 'All fields are required.';
    } elseif ($error === '' && $rawCategoryInput === '__other__' && $customCategoryName === '') {
        $error = 'Please describe the type of work for the "Other" category.';
    } elseif ($error === '' && $contactInfo === '') {
        $error = 'Add a phone number to your account on your profile before posting a request.';
    }

    if ($error === '') {
        if ($rawCategoryInput === '__other__') {
            $description = "Type of work: {$customCategoryName}\n\n{$description}";
            $othStmt = $pdo->prepare("SELECT id FROM service_categories WHERE name = 'Other'");
            $othStmt->execute();
            $othCat = $othStmt->fetch();
            if ($othCat) {
                $categoryId = (int)$othCat['id'];
            } else {
                $pdo->prepare("INSERT INTO service_categories (name) VALUES ('Other')")->execute();
                $categoryId = (int)$pdo->lastInsertId();
            }
        }

        $postingFeePaid = is_feature_paid('enable_paid_job_posting');
        $initialStatus    = ($paymentMode === 'escrow') ? 'pending_payment' : 'pending';
        $postingFeeStatus = $postingFeePaid ? 'pending' : 'free';

        if ($postEditId > 0) {
            // Publishing an existing draft or rejected request — update in place
            $pdo->prepare("UPDATE service_requests SET
                title=?,description=?,category_id=?,location=?,latitude=?,longitude=?,google_maps_link=?,
                budget=?,budget_amount=?,contact_info=?,skills_needed=?,workers_needed=?,
                payment_mode=?,job_type=?,deadline_value=?,deadline_unit=?,deadline_date=?,
                status=?,payment_status='unpaid',commission_percent=?,posting_fee_status=?,
                rejection_reason=NULL,updated_at=NOW()
                WHERE id=? AND status IN ('draft','rejected') AND customer_id=?")
                ->execute([$title,$description,$categoryId,$location,$latitude,$longitude,$googleMapsLink,
                    $budget,$budgetAmount,$contactInfo,$skillsNeeded ?: null,$workersNeeded,
                    $paymentMode,$jobType,$deadlineValue,$deadlineUnit,$deadlineDate,
                    $initialStatus,$commRate,$postingFeeStatus,$postEditId,$user['id']]);
            $newJobId = $postEditId;
        } else {
            $stmt = $pdo->prepare('INSERT INTO service_requests
                (customer_id, title, description, category_id, location, latitude, longitude, google_maps_link,
                 budget, budget_amount, contact_info, skills_needed, workers_needed,
                 payment_mode, job_type, deadline_value, deadline_unit, deadline_date,
                 status, payment_status, commission_percent, featured, posting_fee_status,
                 created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
            $stmt->execute([
                $user['id'], $title, $description, $categoryId, $location, $latitude, $longitude, $googleMapsLink,
                $budget, $budgetAmount, $contactInfo,
                $skillsNeeded !== '' ? $skillsNeeded : null, $workersNeeded,
                $paymentMode, $jobType, $deadlineValue, $deadlineUnit, $deadlineDate,
                $initialStatus, 'unpaid', $commRate, 0, $postingFeeStatus,
            ]);
            $newJobId = (int)$pdo->lastInsertId();
        }

        $categoryName = 'Unknown';
        foreach ($categories as $category) {
            if ($category['id'] === $categoryId) {
                $categoryName = $category['name'];
                break;
            }
        }

        if ($paymentMode === 'escrow') {
            $commAmount  = round($budgetAmount * $commRate / 100, 2);
            $grossAmount = round($budgetAmount + $commAmount, 2);

            $pdo->prepare('INSERT INTO escrow_payments
                (job_id, client_id, budget_amount, commission_rate, commission_amount, gross_amount, net_amount, status, auto_release_days, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,NOW())')
                ->execute([$newJobId, $user['id'], $budgetAmount, $commRate, $commAmount, $grossAmount, $budgetAmount, 'awaiting_payment', $autoReleaseDays]);

            flash('Job saved. Complete the escrow payment to submit it for review.', 'info');
            header('Location: escrow_checkout.php?id=' . $newJobId);
            exit;
        }

        // Direct payment — existing posting fee flow
        if ($postingFeePaid) {
            if (consume_job_post_credit($user['id'], $newJobId)) {
                $creditsLeft = get_job_post_credits_remaining($user['id']);
                flash('Job posted using your credit bundle.' . ($creditsLeft > 0 ? ' You have ' . $creditsLeft . ' post credit(s) remaining.' : ''), 'success');
            } else {
                notify_admins_and_managers(
                    'New job — posting fee required',
                    display_name($user) . ' posted "' . $title . '" but it requires a posting fee before approval. See Monetization → Pending Payments.',
                    'info'
                );
                flash('Job saved. Please complete the posting fee payment to submit it for review.', 'info');
                header('Location: pay_job_post.php?id=' . $newJobId);
                exit;
            }
        }

        $adminMessage = "New service request created by {$user['name']} ({$user['email']}):\n\n" .
                        "Title: {$title}\n" .
                        "Category: {$categoryName}\n" .
                        "Location: {$location}\n" .
                        "Budget: GH₵ {$budget}\n" .
                        "Contact: {$contactInfo}\n\n" .
                        "Please review and approve the request in the admin panel.";
        send_email_notification(ADMIN_EMAIL, 'New AkuapemConnect service request', $adminMessage);
        notify_moderators('approve_jobs', 'New Job Request Pending',
            display_name($user) . ' posted "' . $title . '" — awaiting approval.');

        flash('Service request created successfully. Admin will approve it before workers can accept.');
        header('Location: jobs.php');
        exit;
    }
}

$postingFeeEnabled = is_feature_paid('enable_paid_job_posting');
$availableCredits  = $postingFeeEnabled ? get_job_post_credits_remaining($user['id']) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>New Request — AkuapemConnect</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="has-bottom-nav">
    <header class="app-topbar">
        <span class="brand"><span class="brand-icon">📝</span> Post a Job</span>
        <a href="logout.php" class="button button-secondary button-small">Logout</a>
    </header>
    <main class="page-shell small-shell">
        <?php if ($postingFeeEnabled && $availableCredits > 0): ?>
            <div class="alert alert-success" style="margin-bottom:12px;">
                🎟️ You have <strong><?php echo $availableCredits; ?> post credit<?php echo $availableCredits !== 1 ? 's' : ''; ?></strong> — this job will be posted using a credit from your bundle.
            </div>
        <?php elseif ($postingFeeEnabled): ?>
            <div class="alert alert-info" style="margin-bottom:12px;">
                💳 A posting fee is required to submit this job for review. You will be prompted to pay after submitting.
            </div>
        <?php endif; ?>
        <form class="card form-card" method="post" action="request.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="submit_type" id="submit-type-input" value="publish" />
            <?php if ($draft): ?>
                <input type="hidden" name="edit_id" value="<?php echo (int)$draft['id']; ?>" />
                <?php if ($draft['status'] === 'rejected'): ?>
                <div class="alert alert-error" style="margin-bottom:8px;">
                    <strong>Not approved.</strong>
                    <?php if ($draft['rejection_reason']): ?>
                        Reason: <?php echo sanitize($draft['rejection_reason']); ?>
                    <?php endif; ?>
                    Please update your listing and resubmit.
                </div>
                <?php else: ?>
                <div class="alert alert-info" style="margin-bottom:8px;">Editing draft — publish when you're ready.</div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo sanitize($error); ?></div>
            <?php endif; ?>
            <label>Title</label>
            <input type="text" name="title" required value="<?php echo $draft ? sanitize($draft['title']) : ''; ?>" />
            <label>Description</label>
            <textarea name="description" class="rich-editor" rows="4" required><?php echo $draft ? $draft['description'] : ''; ?></textarea>
            <label>Category</label>
            <select name="category_id" required>
                <option value="">Select category</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?php echo $category['id']; ?>" <?php echo ($draft && (int)$draft['category_id'] === (int)$category['id']) ? 'selected' : ''; ?>><?php echo sanitize($category['name']); ?></option>
                <?php endforeach; ?>
                <option value="__other__">Other (specify)</option>
            </select>
            <div id="other-category-wrap" style="display:none; margin-top:6px;">
                <input type="text" name="other_category_name" id="other-category-input" placeholder="Describe the type of work (e.g. Pool cleaning, Car detailing)" />
            </div>
            <p class="meta" id="category-suggestion-note"></p>

            <label>Skills needed <span class="meta">(optional — helps us match the right workers)</span></label>
            <select id="skill-name-select" disabled>
                <option value="">Select a category first</option>
            </select>
            <div id="other-skill-wrap" style="display:none; margin-top:6px;">
                <input type="text" id="other-skill-input" placeholder="Describe the skill or task (e.g. Borehole drilling)" />
            </div>
            <button type="button" id="add-skill-button" class="button button-secondary button-small" style="margin-top:8px;">+ Add skill</button>
            <ul id="skill-list" style="list-style: none; padding: 0; margin: 8px 0; display: flex; flex-wrap: wrap; gap: 8px;"></ul>
            <input type="hidden" name="skills_needed" id="skills-needed-input" />

            <label>Location</label>
            <select id="town-select">
                <option value="">Select your town</option>
                <?php $currentDistrict = null; ?>
                <?php foreach (get_towns() as $town): ?>
                    <?php if ($town['district'] !== $currentDistrict): ?>
                        <?php if ($currentDistrict !== null): ?></optgroup><?php endif; ?>
                        <optgroup label="<?php echo sanitize($town['district']); ?>">
                        <?php $currentDistrict = $town['district']; ?>
                    <?php endif; ?>
                    <option value="<?php echo sanitize($town['name']); ?>"><?php echo sanitize($town['name']); ?></option>
                <?php endforeach; ?>
                <?php if ($currentDistrict !== null): ?></optgroup><?php endif; ?>
                <option value="__other__">Other (specify)</option>
            </select>
            <input type="text" name="location" id="location-input" required placeholder="City, neighbourhood" value="<?php echo $draft ? sanitize($draft['location']) : ''; ?>" <?php echo ($draft && $draft['location']) ? '' : 'readonly'; ?> />
            <input type="hidden" name="latitude"    id="latitude"    value="<?php echo $draft ? sanitize($draft['latitude']    ?? '') : ''; ?>" />
            <input type="hidden" name="longitude"   id="longitude"   value="<?php echo $draft ? sanitize($draft['longitude']   ?? '') : ''; ?>" />
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px;">
                <button type="button" id="use-my-location" class="button button-secondary button-small">Use my current location</button>
            </div>
            <p class="meta" id="location-status">Sharing your location helps nearby workers find your job faster.</p>
            <label style="margin-top:8px;">Google Maps Link <span style="font-weight:400;color:var(--muted)">(optional)</span></label>
            <p class="meta">Paste a Google Maps share link so workers can get directions to the job site.</p>
            <input type="url" name="google_maps_link" class="form-control" style="margin-bottom:4px;"
                   value="<?php echo $draft ? sanitize($draft['google_maps_link'] ?? '') : ''; ?>"
                   placeholder="https://maps.google.com/…">
            <label>Hiring type</label>
            <?php $draftHiringType = ($draft && ($draft['workers_needed'] ?? 1) > 1) ? 'multiple' : 'single'; ?>
            <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:4px;" id="hiring-type-group">
                <label style="display:flex;align-items:center;gap:6px;font-weight:normal;cursor:pointer;">
                    <input type="radio" name="hiring_type" value="single" <?php echo $draftHiringType === 'single' ? 'checked' : ''; ?> onchange="toggleWorkersNeeded(this)"> Single worker
                </label>
                <label style="display:flex;align-items:center;gap:6px;font-weight:normal;cursor:pointer;">
                    <input type="radio" name="hiring_type" value="multiple" <?php echo $draftHiringType === 'multiple' ? 'checked' : ''; ?> onchange="toggleWorkersNeeded(this)"> Multiple workers
                </label>
            </div>
            <div id="workers-needed-wrap" style="display:<?php echo $draftHiringType === 'multiple' ? 'block' : 'none'; ?>;margin-bottom:4px;">
                <label>Number of workers needed</label>
                <input type="number" name="workers_needed" id="workers-needed-input" min="2" max="50" value="<?php echo $draft ? max(2, (int)($draft['workers_needed'] ?? 2)) : 2; ?>" style="width:100px;" />
            </div>

            <label style="margin-top:8px;">Work location type</label>
            <?php $draftJobType = $draft['job_type'] ?? 'on_site'; ?>
            <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:4px;">
                <label style="display:flex;align-items:center;gap:6px;font-weight:normal;cursor:pointer;">
                    <input type="radio" name="job_type" value="on_site" <?php echo $draftJobType === 'on_site' ? 'checked' : ''; ?>> 📍 On-site
                </label>
                <label style="display:flex;align-items:center;gap:6px;font-weight:normal;cursor:pointer;">
                    <input type="radio" name="job_type" value="remote" <?php echo $draftJobType === 'remote' ? 'checked' : ''; ?>> 💻 Remote
                </label>
                <label style="display:flex;align-items:center;gap:6px;font-weight:normal;cursor:pointer;">
                    <input type="radio" name="job_type" value="hybrid" <?php echo $draftJobType === 'hybrid' ? 'checked' : ''; ?>> 🔄 Hybrid
                </label>
            </div>

            <label style="margin-top:8px;">Payment mode</label>
            <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:4px;">
                <label style="display:flex;gap:10px;border:2px solid var(--primary);border-radius:var(--radius-sm);padding:12px;cursor:pointer;background:var(--surface-muted);" id="mode-escrow-label">
                    <input type="radio" name="payment_mode" value="escrow" id="mode-escrow" <?php echo (!$draft || ($draft['payment_mode'] ?? 'escrow') === 'escrow') ? 'checked' : ''; ?> onchange="onModeChange()">
                    <div>
                        <strong>Escrow Payment <span style="background:var(--primary);color:#fff;border-radius:4px;padding:1px 6px;font-size:0.75rem;vertical-align:middle;">Recommended</span></strong>
                        <p class="meta" style="margin:2px 0 0;">Your funds are held securely by AkuapemConnect. Released to the worker only after you confirm satisfactory completion.</p>
                    </div>
                </label>
                <label style="display:flex;gap:10px;border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px;cursor:pointer;" id="mode-direct-label">
                    <input type="radio" name="payment_mode" value="direct" id="mode-direct" <?php echo ($draft && ($draft['payment_mode'] ?? '') === 'direct') ? 'checked' : ''; ?> onchange="onModeChange()">
                    <div>
                        <strong>Direct Payment</strong>
                        <p class="meta" style="margin:2px 0 0;">You pay the worker directly. AkuapemConnect only tracks job completion, not the payment itself.</p>
                    </div>
                </label>
            </div>

            <label id="budget-label">Budget <span class="meta" id="budget-meta">(numeric amount, e.g. 300)</span></label>
            <input type="text" name="budget" id="budget-input" required placeholder="e.g. 300" value="<?php echo $draft ? sanitize($draft['budget']) : ''; ?>" oninput="updateCommissionPreview()" />
            <p class="meta" id="budget-suggestion"></p>

            <div id="commission-preview" style="display:none;background:var(--surface-muted);border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px;margin-top:4px;">
                <p style="margin:0 0 8px;font-weight:600;">Payment summary</p>
                <table style="width:100%;border-collapse:collapse;font-size:0.9rem;">
                    <tr>
                        <td style="padding:3px 0;">Worker receives</td>
                        <td style="text-align:right;font-weight:600;" id="cp-worker">—</td>
                    </tr>
                    <tr>
                        <td style="padding:3px 0;">Platform fee (<span id="cp-rate"><?php echo $commRate; ?>%</span>)</td>
                        <td style="text-align:right;" id="cp-commission">—</td>
                    </tr>
                    <tr style="border-top:1px solid var(--border);">
                        <td style="padding:6px 0 0;font-weight:600;">You pay today</td>
                        <td style="text-align:right;font-weight:700;font-size:1.05rem;color:var(--primary);" id="cp-total">—</td>
                    </tr>
                </table>
                <p class="meta" style="margin:8px 0 0;">Funds are held securely. Auto-released to the worker <?php echo $autoReleaseDays; ?> day<?php echo $autoReleaseDays !== 1 ? 's' : ''; ?> after job completion if you don't act sooner.</p>
            </div>

            <div id="direct-warning" style="display:none;background:#fffbeb;border:1px solid #f59e0b;border-radius:var(--radius-sm);padding:12px;margin-top:4px;">
                <strong style="color:#92400e;">Direct Payment — Important</strong>
                <p class="meta" style="margin:4px 0 0;color:#78350f;">You will arrange payment directly with the worker. AkuapemConnect is not responsible for payment disputes, fraud, or non-payment in direct-payment jobs.</p>
            </div>

            <label style="margin-top:12px;">Deadline <span class="meta">(optional)</span></label>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <input type="number" name="deadline_value" id="deadline-value" min="1" max="999" placeholder="e.g. 3" style="width:80px;" value="<?php echo $draft ? sanitize($draft['deadline_value'] ?? '') : ''; ?>" />
                <select name="deadline_unit" id="deadline-unit" style="flex:1;min-width:140px;">
                    <option value="">No deadline</option>
                    <option value="hours" <?php echo ($draft && ($draft['deadline_unit'] ?? '') === 'hours') ? 'selected' : ''; ?>>Hour(s)</option>
                    <option value="days" <?php echo ($draft && ($draft['deadline_unit'] ?? '') === 'days') ? 'selected' : ''; ?>>Day(s)</option>
                    <option value="months" <?php echo ($draft && ($draft['deadline_unit'] ?? '') === 'months') ? 'selected' : ''; ?>>Month(s)</option>
                </select>
            </div>
            <p class="meta" id="deadline-hint" style="margin-top:4px;"></p>

            <label style="margin-top:8px;">Contact info</label>
            <?php if (!empty($user['phone'])): ?>
                <p class="meta">📱 Workers will reach you on your registered number: <strong><?php echo sanitize($user['phone']); ?></strong></p>
            <?php else: ?>
                <p class="alert alert-error">No phone number on file. Please add one to your account before posting — contact support to update it.</p>
            <?php endif; ?>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:4px;">
                <button type="submit" class="button button-primary" id="submit-btn" <?php echo empty($user['phone']) ? 'disabled' : ''; ?>>Pay & Publish</button>
                <button type="button" class="button button-secondary" id="save-draft-btn" onclick="saveDraft()">Save as Draft</button>
            </div>
        </form>
    </main>
    <script>
        var COMMISSION_RATE = <?php echo (float)$commRate; ?>;
        var DRAFT_SKILLS    = <?php echo $draft ? json_encode(array_filter(array_map('trim', explode(',', $draft['skills_needed'] ?? '')))) : '[]'; ?>;

        function saveDraft() {
            document.getElementById('submit-type-input').value = 'draft';
            document.querySelector('form.form-card').submit();
        }

        function onModeChange() {
            var mode = document.querySelector('input[name="payment_mode"]:checked').value;
            var escrowLabel = document.getElementById('mode-escrow-label');
            var directLabel = document.getElementById('mode-direct-label');
            escrowLabel.style.border = mode === 'escrow' ? '2px solid var(--primary)' : '1px solid var(--border)';
            escrowLabel.style.background = mode === 'escrow' ? 'var(--surface-muted)' : '';
            directLabel.style.border = mode === 'direct' ? '2px solid var(--primary)' : '1px solid var(--border)';
            directLabel.style.background = mode === 'direct' ? 'var(--surface-muted)' : '';

            var budgetMeta = document.getElementById('budget-meta');
            if (mode === 'escrow') {
                budgetMeta.textContent = '(numeric amount, e.g. 300 — required for escrow)';
                document.getElementById('budget-input').placeholder = 'e.g. 300';
            } else {
                budgetMeta.textContent = '(e.g. GH₵ 100 or Negotiable)';
                document.getElementById('budget-input').placeholder = 'GH₵ 100 or Negotiable';
            }

            updateCommissionPreview();
        }

        function updateCommissionPreview() {
            var mode = document.querySelector('input[name="payment_mode"]:checked').value;
            var commSection = document.getElementById('commission-preview');
            var directWarn  = document.getElementById('direct-warning');
            var submitBtn   = document.getElementById('submit-btn');

            if (mode !== 'escrow') {
                commSection.style.display = 'none';
                directWarn.style.display  = '';
                submitBtn.textContent = 'Publish request';
                return;
            }
            directWarn.style.display = 'none';

            var raw = document.getElementById('budget-input').value.replace(/[^0-9.]/g, '');
            var budget = parseFloat(raw);
            if (isNaN(budget) || budget <= 0) {
                commSection.style.display = 'none';
                submitBtn.textContent = 'Pay & Publish';
                return;
            }

            var commission = parseFloat((budget * COMMISSION_RATE / 100).toFixed(2));
            var gross = parseFloat((budget + commission).toFixed(2));

            document.getElementById('cp-worker').textContent     = 'GH₵ ' + budget.toFixed(2);
            document.getElementById('cp-commission').textContent = 'GH₵ ' + commission.toFixed(2);
            document.getElementById('cp-total').textContent      = 'GH₵ ' + gross.toFixed(2);
            commSection.style.display = '';
            submitBtn.textContent = 'Pay GH₵ ' + gross.toFixed(2) + ' & Publish';
        }

        function toggleWorkersNeeded(radio) {
            document.getElementById('workers-needed-wrap').style.display = radio.value === 'multiple' ? 'block' : 'none';
            var inp = document.getElementById('workers-needed-input');
            inp.required = radio.value === 'multiple';
        }

        document.getElementById('use-my-location').addEventListener('click', function () {
            var status = document.getElementById('location-status');
            if (!navigator.geolocation) {
                status.textContent = 'Geolocation is not supported by your browser.';
                return;
            }
            status.textContent = 'Locating…';
            navigator.geolocation.getCurrentPosition(function (position) {
                document.getElementById('latitude').value = position.coords.latitude;
                document.getElementById('longitude').value = position.coords.longitude;
                status.textContent = 'Location captured: ' + position.coords.latitude.toFixed(5) + ', ' + position.coords.longitude.toFixed(5) + '.';
            }, function () {
                status.textContent = 'Unable to retrieve your location. Please allow location access and try again.';
            });
        });

        var townSelect = document.getElementById('town-select');
        var locationInputEl = document.getElementById('location-input');
        townSelect.addEventListener('change', function () {
            if (this.value === '__other__') {
                locationInputEl.value = '';
                locationInputEl.readOnly = false;
                locationInputEl.placeholder = 'Enter your location';
                locationInputEl.focus();
            } else if (this.value) {
                locationInputEl.value = this.value;
                locationInputEl.readOnly = true;
            } else {
                locationInputEl.value = '';
                locationInputEl.readOnly = true;
                locationInputEl.placeholder = 'City, neighbourhood';
            }
            locationInputEl.dispatchEvent(new Event('input'));
        });

        var categorySelect = document.querySelector('select[name="category_id"]');
        var locationInput  = document.querySelector('input[name="location"]');
        var budgetSuggestion = document.getElementById('budget-suggestion');
        var suggestionTimer  = null;

        var titleInput       = document.querySelector('input[name="title"]');
        var descriptionInput = document.querySelector('textarea[name="description"]');
        var categoryNote     = document.getElementById('category-suggestion-note');
        var categoryManuallyChanged = false;
        var categoryTimer = null;

        categorySelect.addEventListener('change', function () {
            categoryManuallyChanged = true;
            categoryNote.textContent = '';
            var otherWrap = document.getElementById('other-category-wrap');
            if (otherWrap) otherWrap.style.display = this.value === '__other__' ? 'block' : 'none';
        });

        function fetchCategorySuggestion() {
            if (categoryManuallyChanged) return;
            var title = titleInput.value.trim();
            var description = descriptionInput.value.trim();
            if (title === '' && description === '') { categoryNote.textContent = ''; return; }
            var url = 'suggest_category.php?title=' + encodeURIComponent(title) + '&description=' + encodeURIComponent(description);
            fetch(url)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (categoryManuallyChanged || !data.suggestion) return;
                    var s = data.suggestion;
                    categorySelect.value = String(s.category_id);
                    categoryNote.textContent = '✨ We pre-selected "' + s.category_name + '" based on your description. Change it if that\'s not right.';
                })
                .catch(function () {});
        }

        titleInput.addEventListener('input', function () {
            clearTimeout(categoryTimer);
            categoryTimer = setTimeout(fetchCategorySuggestion, 600);
        });
        descriptionInput.addEventListener('input', function () {
            clearTimeout(categoryTimer);
            categoryTimer = setTimeout(fetchCategorySuggestion, 600);
        });

        function fetchBudgetSuggestion() {
            var categoryId = categorySelect.value;
            if (!categoryId) { budgetSuggestion.textContent = ''; return; }
            var url = 'suggest_budget.php?category_id=' + encodeURIComponent(categoryId) + '&location=' + encodeURIComponent(locationInput.value);
            fetch(url)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.suggestion) { budgetSuggestion.textContent = ''; return; }
                    var s = data.suggestion;
                    var scopeText = s.scope === 'nearby' ? 'Similar jobs near this location' : 'Similar jobs across AkuapemConnect';
                    budgetSuggestion.textContent = '💡 ' + scopeText + ' typically went for GH₵ ' + Math.round(s.min) + '–' + Math.round(s.max) +
                        ' (avg GH₵ ' + Math.round(s.avg) + ') based on ' + s.count + ' job' + (s.count === 1 ? '' : 's') + '.';
                })
                .catch(function () { budgetSuggestion.textContent = ''; });
        }

        var categorySkillMap = <?php
            $skillTaxonomy = get_skill_taxonomy();
            $categoryTaxonomyOverrides = ['Errand' => 'Errand & Support Services'];
            $categorySkillMap = [];
            foreach ($categories as $category) {
                $taxonomyKey = $categoryTaxonomyOverrides[$category['name']] ?? $category['name'];
                $categorySkillMap[$category['id']] = $skillTaxonomy[$taxonomyKey] ?? [];
            }
            echo json_encode($categorySkillMap);
        ?>;
        var skillNameSelect = document.getElementById('skill-name-select');
        var addSkillButton  = document.getElementById('add-skill-button');
        var skillList       = document.getElementById('skill-list');
        var skillsNeededInput = document.getElementById('skills-needed-input');
        var selectedSkills    = [];

        function refreshSkillOptions() {
            var skills = categorySkillMap[categorySelect.value] || [];
            skillNameSelect.innerHTML = '';
            selectedSkills = [];
            renderSkillList();
            if (!skills.length) {
                skillNameSelect.innerHTML = '<option value="">No specific skills for this category</option>';
                skillNameSelect.disabled = true;
                return;
            }
            skillNameSelect.disabled = false;
            var placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = 'Select a skill';
            skillNameSelect.appendChild(placeholder);
            skills.forEach(function (skillName) {
                var option = document.createElement('option');
                option.value = skillName;
                option.textContent = skillName;
                skillNameSelect.appendChild(option);
            });
            var otherOpt = document.createElement('option');
            otherOpt.value = '__other__';
            otherOpt.textContent = 'Other (specify)';
            skillNameSelect.appendChild(otherOpt);
        }

        categorySelect.addEventListener('change', refreshSkillOptions);

        skillNameSelect.addEventListener('change', function () {
            var isOther = this.value === '__other__';
            document.getElementById('other-skill-wrap').style.display = isOther ? 'block' : 'none';
            if (isOther) document.getElementById('other-skill-input').focus();
        });

        function renderSkillList() {
            skillList.innerHTML = '';
            selectedSkills.forEach(function (skillName, index) {
                var li = document.createElement('li');
                li.className = 'badge';
                li.style.display = 'inline-flex';
                li.style.alignItems = 'center';
                li.style.gap = '6px';
                li.textContent = skillName;
                var removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.textContent = '×';
                removeBtn.setAttribute('aria-label', 'Remove ' + skillName);
                removeBtn.style.border = 'none';
                removeBtn.style.background = 'transparent';
                removeBtn.style.cursor = 'pointer';
                removeBtn.addEventListener('click', function () {
                    selectedSkills.splice(index, 1);
                    renderSkillList();
                });
                li.appendChild(removeBtn);
                skillList.appendChild(li);
            });
            skillsNeededInput.value = selectedSkills.join(', ');
        }

        addSkillButton.addEventListener('click', function () {
            var skillName = skillNameSelect.value;
            if (skillName === '__other__') {
                skillName = document.getElementById('other-skill-input').value.trim();
                if (!skillName) return;
                document.getElementById('other-skill-input').value = '';
                document.getElementById('other-skill-wrap').style.display = 'none';
            }
            if (!skillName || selectedSkills.indexOf(skillName) !== -1) return;
            selectedSkills.push(skillName);
            renderSkillList();
            skillNameSelect.value = '';
        });

        categorySelect.addEventListener('change', fetchBudgetSuggestion);
        locationInput.addEventListener('input', function () {
            clearTimeout(suggestionTimer);
            suggestionTimer = setTimeout(fetchBudgetSuggestion, 500);
        });

        // Deadline hint
        var deadlineValueInput = document.getElementById('deadline-value');
        var deadlineUnitSelect = document.getElementById('deadline-unit');
        function updateDeadlineHint() {
            var val  = parseInt(deadlineValueInput.value, 10);
            var unit = deadlineUnitSelect.value;
            var hint = document.getElementById('deadline-hint');
            if (!val || !unit) { hint.textContent = ''; return; }
            var now = new Date();
            if (unit === 'hours')  now.setHours(now.getHours() + val);
            if (unit === 'days')   now.setDate(now.getDate() + val);
            if (unit === 'months') now.setMonth(now.getMonth() + val);
            hint.textContent = 'Deadline: ' + now.toLocaleString();
        }
        deadlineValueInput.addEventListener('input', updateDeadlineHint);
        deadlineUnitSelect.addEventListener('change', updateDeadlineHint);

        // Init state
        onModeChange();

        // Pre-fill skills from draft
        if (DRAFT_SKILLS.length) {
            DRAFT_SKILLS.forEach(function (skill) {
                if (skill && selectedSkills.indexOf(skill) === -1) {
                    selectedSkills.push(skill);
                }
            });
            renderSkillList();
        }
    </script>
    <?php $activeNav = 'home'; require __DIR__ . '/partials/bottom_nav.php'; ?>
</body>
</html>
