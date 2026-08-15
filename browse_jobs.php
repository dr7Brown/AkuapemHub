<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_module_enabled('jobs', 'Jobs & Services');

// Logged-in users go to their dashboard
if (current_user()) {
    header('Location: jobs.php');
    exit;
}

$categories      = get_categories();
$categoryFilter  = $_GET['category']    ?? '';
$locationFilter  = trim($_GET['location']  ?? '');
$searchQuery     = trim($_GET['q']         ?? '');
$jobTypeFilter   = $_GET['job_type']    ?? '';
$budgetMin       = ($_GET['budget_min'] ?? '') !== '' ? (float)$_GET['budget_min'] : null;
$budgetMax       = ($_GET['budget_max'] ?? '') !== '' ? (float)$_GET['budget_max'] : null;
$paymentFilter   = $_GET['payment']     ?? '';   // escrow_paid | escrow | paid | unpaid
$sortFilter      = $_GET['sort']        ?? 'newest'; // newest | budget_high | budget_low | featured

// Build job query
$where  = ["sr.status IN (" . public_job_statuses_sql() . ")", "sr.posting_fee_status != 'pending'", "u.banned = 0"];
$params = [];

if ($categoryFilter) {
    $where[] = 'sr.category_id = ?'; $params[] = $categoryFilter;
}
if ($locationFilter) {
    $where[] = 'sr.location LIKE ?'; $params[] = '%' . $locationFilter . '%';
}
if ($jobTypeFilter && in_array($jobTypeFilter, ['on_site','remote','hybrid'], true)) {
    $where[] = 'sr.job_type = ?'; $params[] = $jobTypeFilter;
}
if ($searchQuery) {
    $where[] = '(sr.title LIKE ? OR sr.description LIKE ? OR sc.name LIKE ? OR sr.location LIKE ?)';
    $like = '%' . $searchQuery . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($budgetMin !== null) { $where[] = 'sr.budget_amount >= ?'; $params[] = $budgetMin; }
if ($budgetMax !== null) { $where[] = 'sr.budget_amount <= ?'; $params[] = $budgetMax; }
if ($paymentFilter === 'escrow_paid') { $where[] = "sr.payment_mode='escrow' AND sr.payment_status='paid'"; }
elseif ($paymentFilter === 'escrow')  { $where[] = "sr.payment_mode='escrow'"; }
elseif ($paymentFilter === 'paid')    { $where[] = "sr.payment_status='paid'"; }
elseif ($paymentFilter === 'unpaid')  { $where[] = "sr.payment_status='unpaid'"; }

$featExpr = '(sr.featured=1 AND (sr.featured_end_date IS NULL OR sr.featured_end_date>=CURDATE()))';
$orderBy = match($sortFilter) {
    'budget_high' => "$featExpr DESC, sr.budget_amount DESC",
    'budget_low'  => "$featExpr DESC, sr.budget_amount ASC",
    'oldest'      => 'sr.created_at ASC',
    default       => "$featExpr DESC, sr.created_at DESC",
};

$jobs = $pdo->prepare(
    'SELECT sr.id, sr.title, sr.description, sr.location, sr.budget, sr.budget_amount, sr.price_unit,
            sr.status, sr.featured, sr.featured_end_date, sr.created_at,
            sr.workers_needed, sr.workers_approved, sr.job_type,
            sr.payment_status, sr.payment_mode,
            sc.name AS category_name
     FROM service_requests sr
     JOIN service_categories sc ON sr.category_id = sc.id
     JOIN users u ON sr.customer_id = u.id
     WHERE ' . implode(' AND ', $where) . "
     ORDER BY $orderBy LIMIT 80"
);
$jobs->execute($params);
$jobs = $jobs->fetchAll();

// Active filter summary for chips
$activeFilters = array_filter([
    'q'          => $searchQuery,
    'location'   => $locationFilter,
    'category'   => $categoryFilter ? (array_filter($categories, fn($c) => $c['id'] == $categoryFilter)[0]['name'] ?? '') : '',
    'job_type'   => $jobTypeFilter  ? ['on_site'=>'On-site','remote'=>'Remote','hybrid'=>'Hybrid'][$jobTypeFilter] ?? '' : '',
    'budget_min' => $budgetMin !== null ? 'Min GH₵ ' . number_format($budgetMin, 2) : '',
    'budget_max' => $budgetMax !== null ? 'Max GH₵ ' . number_format($budgetMax, 2) : '',
    'payment'    => $paymentFilter  ? ['escrow_paid'=>'🔒 Escrow Paid','escrow'=>'🔒 Escrow','paid'=>'✓ Paid','unpaid'=>'Unpaid'][$paymentFilter] ?? '' : '',
    'sort'       => $sortFilter !== 'newest' ? ['budget_high'=>'Budget ↓','budget_low'=>'Budget ↑','oldest'=>'Oldest first'][$sortFilter] ?? '' : '',
]);

// Quick stats
$totalOpen  = (int)$pdo->query("SELECT COUNT(*) FROM service_requests WHERE status IN (" . public_job_statuses_sql() . ") AND posting_fee_status != 'pending'")->fetchColumn();
$totalCats  = (int)$pdo->query("SELECT COUNT(*) FROM service_categories")->fetchColumn();
$totalWorkers = (int)$pdo->query("SELECT COUNT(*) FROM worker_profiles")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <?php echo seo_meta([
        'title'       => 'Browse Jobs & Service Requests in the Akuapem Area — ' . APP_NAME,
        'description' => 'Find open jobs and service requests posted by customers across the Akuapem area. Filter by category, location, and budget — apply as a verified worker today.',
        'url'         => rtrim(BASE_URL, '/') . '/browse_jobs.php',
        // Filtered/search views are variants of the same page — keep only the
        // canonical unfiltered listing indexed to avoid duplicate-content dilution.
        'noindex'     => !empty($_GET) ,
    ]); ?>
    <link rel="stylesheet" href="assets/css/style.css" />
    <style>
        /* ── Page reset ── */
        * { box-sizing: border-box; }

        /* ── Top bar ── */
        .bj-topbar {
            position: sticky; top: 0; z-index: 100;
            background: var(--surface, #fff);
            border-bottom: 1px solid var(--border, #e5e7eb);
            padding: 0 16px;
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; height: 56px;
        }
        .bj-logo { font-weight: 900; font-size: 1.15rem; color: var(--primary, #0f766e); text-decoration: none; }
        .bj-nav  { display: flex; align-items: center; gap: 8px; }
        .bj-nav a.link { font-size: .85rem; color: var(--muted, #6b7280); text-decoration: none; font-weight: 600; padding: 4px 6px; }
        .bj-nav a.link:hover { color: var(--primary, #0f766e); }

        /* ── Hero ── */
        .bj-hero {
            background: linear-gradient(135deg, rgba(15,118,110,.80) 0%, rgba(6,95,70,.88) 100%),
                        url('assets/images/heroes/hero-jobs.jpg') center/cover no-repeat;
            color: #fff;
            padding: 60px 20px 48px;
            text-align: center;
        }
        .bj-hero h1 { font-size: clamp(1.5rem, 4vw, 2.2rem); margin: 0 0 10px; font-weight: 800; text-shadow: 0 2px 8px rgba(0,0,0,.25); }
        .bj-hero p  { margin: 0 0 24px; opacity: .92; font-size: .95rem; text-shadow: 0 1px 4px rgba(0,0,0,.2); }
        .bj-stats   { display: flex; justify-content: center; gap: 28px; flex-wrap: wrap; margin-top: 24px; }
        .bj-stat    { text-align: center; }
        .bj-stat strong { display: block; font-size: 1.5rem; font-weight: 800; }
        .bj-stat span   { font-size: .78rem; opacity: .8; }
        .bj-hero-btns { display: flex; justify-content: center; gap: 10px; flex-wrap: wrap; }
        .bj-hero-btns a { padding: 10px 22px; border-radius: 8px; font-weight: 700; font-size: .9rem; text-decoration: none; }
        .bj-hero-btns .primary   { background: #fff; color: var(--primary, #0f766e); }
        .bj-hero-btns .secondary { background: rgba(255,255,255,.15); color: #fff; border: 1px solid rgba(255,255,255,.4); }
        .bj-hero-btns a:hover { opacity: .9; }

        /* ── Filter bar ── */
        .bj-filters { background:var(--surface,#fff); border-bottom:1px solid var(--border,#e5e7eb); padding:14px 16px; }
        .bj-filter-wrap { max-width:920px; margin:0 auto; }
        .bj-filter-row { display:flex; gap:8px; flex-wrap:wrap; }
        .bj-filter-row + .bj-filter-row { margin-top:8px; }
        .bj-filter-row select,
        .bj-filter-row input[type="text"],
        .bj-filter-row input[type="number"] {
            flex:1; min-width:120px; padding:9px 12px;
            border:1px solid var(--border,#d1d5db); border-radius:8px; font-size:.86rem;
            background:var(--bg,#f9fafb); color:var(--text,#111827);
        }
        .bj-filter-row select:focus,
        .bj-filter-row input:focus { outline:2px solid var(--primary,#0f766e); border-color:transparent; }
        .btn-search { padding:9px 18px; background:var(--primary,#0f766e); color:#fff;
                      border:none; border-radius:8px; font-weight:700; font-size:.88rem; cursor:pointer; white-space:nowrap; }
        .btn-search:hover { opacity:.9; }
        .bj-filter-clear { font-size:.82rem; color:var(--muted,#6b7280); text-decoration:none; align-self:center; white-space:nowrap; }
        /* Active filter chips */
        .bj-chips { display:flex; gap:6px; flex-wrap:wrap; margin-top:10px; }
        .bj-chip  { display:inline-flex; align-items:center; gap:5px; padding:3px 10px; border-radius:20px;
                    background:var(--primary-soft,#d1fae5); color:var(--primary-dark,#065f46);
                    font-size:.74rem; font-weight:700; }
        .bj-chip a { color:inherit; text-decoration:none; font-weight:900; margin-left:2px; opacity:.7; }
        .bj-chip a:hover { opacity:1; }

        /* ── Content area ── */
        .bj-content { max-width: 920px; margin: 0 auto; padding: 20px 16px 80px; }
        .bj-results-bar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 8px; }
        .bj-results-bar p { margin: 0; font-size: .88rem; color: var(--muted, #6b7280); }

        /* ── Job card ── */
        .bj-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px; }
        .bj-card {
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 12px; padding: 16px;
            display: flex; flex-direction: column; gap: 10px;
            transition: box-shadow .15s, transform .15s;
        }
        .bj-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,.08); transform: translateY(-2px); }
        .bj-card.featured { border-color: #f59e0b; border-width: 2px; background: #fffbeb; }
        .job-type-badge { display: inline-flex; align-items: center; gap: 3px; padding: 2px 7px; border-radius: 20px; font-size: .7rem; font-weight: 700; }
        .job-type-badge.remote  { background: #dbeafe; color: #1e40af; }
        .job-type-badge.on-site { background: #d1fae5; color: #065f46; }
        .job-type-badge.hybrid  { background: #ede9fe; color: #5b21b6; }
        .bj-card-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
        .bj-card-title { font-size: .97rem; font-weight: 700; margin: 0 0 3px; color: var(--text, #111827); line-height: 1.3; }
        .bj-card-meta  { font-size: .78rem; color: var(--muted, #6b7280); }
        .bj-card-desc  { font-size: .84rem; color: var(--text, #374151); line-height: 1.55; flex: 1; min-height: 0;
                         display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;
                         max-height: calc(1.55em * 3); word-break: break-word; }
        .bj-card-budget { font-size: .9rem; font-weight: 700; color: var(--primary, #0f766e); }
        .bj-card-actions { display: flex; gap: 8px; margin-top: 4px; }
        .bj-btn { padding: 8px 14px; border-radius: 7px; font-size: .82rem; font-weight: 600; text-decoration: none; text-align: center; cursor: pointer; display: inline-block; white-space: nowrap; }
        .bj-btn-primary  { background: var(--primary, #0f766e); color: #fff; flex: 1; }
        .bj-btn-primary:hover { opacity: .9; }
        .bj-btn-outline  { background: transparent; color: var(--primary, #0f766e); border: 1px solid var(--primary, #0f766e); }
        .bj-btn-outline:hover { background: var(--primary-soft, #ccfbf1); }
        .feat-tag { display: inline-block; background: #fef9c3; color: #92400e; border-radius: 4px; padding: 1px 6px; font-size: .7rem; font-weight: 700; margin-left: 4px; vertical-align: middle; }
        .bj-status { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: .7rem; font-weight: 700; white-space: nowrap; flex-shrink: 0; }
        .bj-status.open    { background: #d1fae5; color: #065f46; }
        .bj-status.partial { background: #fef9c3; color: #92400e; }

        /* ── Empty state ── */
        .bj-empty { text-align: center; padding: 60px 20px; color: var(--muted, #6b7280); }
        .bj-empty h3 { margin: 0 0 8px; color: var(--text, #374151); }
        .bj-empty p  { margin: 0 0 20px; }

        /* ── CTA footer ── */
        .bj-cta {
            background: var(--primary-soft, #ccfbf1);
            border: 1px solid var(--primary, #0f766e);
            border-radius: 14px; padding: 28px 20px;
            text-align: center; margin-top: 32px;
        }
        .bj-cta h2 { margin: 0 0 8px; font-size: 1.15rem; color: var(--primary, #0f766e); }
        .bj-cta p  { margin: 0 0 18px; font-size: .88rem; color: var(--muted, #6b7280); }
        .bj-cta-btns { display: flex; justify-content: center; gap: 10px; flex-wrap: wrap; }
    </style>
</head>
<body>

<!-- ── Top bar ─────────────────────────────────────────────────────────────── -->
<header class="bj-topbar">
    <a href="index.php" class="bj-logo">← Back Home</a>
    <nav class="bj-nav">
        <a href="find_workers.php" class="link">Workers</a>
        <a href="community.php"    class="link">Community</a>
        <a href="login.php?redirect=<?php echo urlencode(current_request_path()); ?>" class="button button-secondary button-small">Sign in</a>
    </nav>
</header>

<!-- ── Hero ────────────────────────────────────────────────────────────────── -->
<section class="bj-hero">
    <h1>Find Jobs &amp; Services in Akuapem</h1>
    <p>Browse hundreds of open service requests — plumbing, tutoring, carpentry, events &amp; more</p>
    <div class="bj-hero-btns">
        <a href="register.php" class="primary">Create free account</a>
        <a href="login.php?redirect=<?php echo urlencode(current_request_path()); ?>" class="secondary">Sign in</a>
    </div>
    <div class="bj-stats">
        <div class="bj-stat">
            <strong><?php echo number_format($totalOpen); ?></strong>
            <span>Open Jobs</span>
        </div>
        <div class="bj-stat">
            <strong><?php echo number_format($totalCats); ?></strong>
            <span>Categories</span>
        </div>
        <div class="bj-stat">
            <strong><?php echo number_format($totalWorkers); ?>+</strong>
            <span>Workers</span>
        </div>
    </div>
</section>

<!-- ── Filters ──────────────────────────────────────────────────────────────── -->
<div class="bj-filters">
    <form method="get" class="bj-filter-wrap">
        <!-- Row 1: search + location + category + type -->
        <div class="bj-filter-row">
            <input type="text" name="q"        value="<?php echo sanitize($searchQuery); ?>"   placeholder="Search jobs…" style="min-width:160px;" />
            <input type="text" name="location" value="<?php echo sanitize($locationFilter); ?>" placeholder="Location…" style="min-width:120px;" />
            <select name="category" style="min-width:140px;">
                <option value="">All categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo $categoryFilter == $cat['id'] ? 'selected' : ''; ?>>
                        <?php echo sanitize($cat['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="job_type" style="min-width:120px;">
                <option value="">Any type</option>
                <option value="on_site" <?php echo $jobTypeFilter==='on_site' ?'selected':''; ?>>📍 On-site</option>
                <option value="remote"  <?php echo $jobTypeFilter==='remote'  ?'selected':''; ?>>💻 Remote</option>
                <option value="hybrid"  <?php echo $jobTypeFilter==='hybrid'  ?'selected':''; ?>>🔄 Hybrid</option>
            </select>
        </div>
        <!-- Row 2: budget range + payment + sort + submit -->
        <div class="bj-filter-row">
            <input type="number" name="budget_min" value="<?php echo $budgetMin !== null ? sanitize($budgetMin) : ''; ?>"
                   placeholder="Min budget (GH₵)" min="0" step="1" style="min-width:140px;" />
            <input type="number" name="budget_max" value="<?php echo $budgetMax !== null ? sanitize($budgetMax) : ''; ?>"
                   placeholder="Max budget (GH₵)" min="0" step="1" style="min-width:140px;" />
            <select name="payment" style="min-width:150px;">
                <option value="">Any payment</option>
                <option value="escrow_paid" <?php echo $paymentFilter==='escrow_paid'?'selected':''; ?>>🔒 Escrow Paid</option>
                <option value="escrow"      <?php echo $paymentFilter==='escrow'     ?'selected':''; ?>>🔒 Escrow</option>
                <option value="paid"        <?php echo $paymentFilter==='paid'       ?'selected':''; ?>>✓ Paid</option>
                <option value="unpaid"      <?php echo $paymentFilter==='unpaid'     ?'selected':''; ?>>Unpaid</option>
            </select>
            <select name="sort" style="min-width:140px;">
                <option value="newest"      <?php echo $sortFilter==='newest'      ?'selected':''; ?>>Newest first</option>
                <option value="budget_high" <?php echo $sortFilter==='budget_high' ?'selected':''; ?>>Budget: high → low</option>
                <option value="budget_low"  <?php echo $sortFilter==='budget_low'  ?'selected':''; ?>>Budget: low → high</option>
                <option value="oldest"      <?php echo $sortFilter==='oldest'      ?'selected':''; ?>>Oldest first</option>
            </select>
            <button type="submit" class="btn-search">Search</button>
            <?php if ($activeFilters): ?>
                <a href="browse_jobs.php" class="bj-filter-clear">✕ Clear all</a>
            <?php endif; ?>
        </div>
        <!-- Active filter chips -->
        <?php if ($activeFilters): ?>
        <div class="bj-chips">
            <?php
            $chipRemoveKeys = ['q','location','category','job_type','budget_min','budget_max','payment','sort'];
            $currentParams  = array_filter([
                'q'          => $searchQuery,
                'location'   => $locationFilter,
                'category'   => $categoryFilter,
                'job_type'   => $jobTypeFilter,
                'budget_min' => $budgetMin !== null ? $budgetMin : '',
                'budget_max' => $budgetMax !== null ? $budgetMax : '',
                'payment'    => $paymentFilter,
                'sort'       => $sortFilter !== 'newest' ? $sortFilter : '',
            ]);
            foreach ($activeFilters as $key => $label):
                if (!$label) continue;
                $removeParams = $currentParams;
                unset($removeParams[$key]);
                $removeUrl = 'browse_jobs.php' . ($removeParams ? '?' . http_build_query($removeParams) : '');
            ?>
            <span class="bj-chip"><?php echo sanitize($label); ?> <a href="<?php echo sanitize($removeUrl); ?>">✕</a></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </form>
</div>

<!-- ── Results ──────────────────────────────────────────────────────────────── -->
<div class="bj-content">
    <div class="bj-results-bar">
        <p>
            <?php echo count($jobs); ?> job<?php echo count($jobs) !== 1 ? 's' : ''; ?>
            <?php echo $activeFilters ? 'match your filters' : 'available'; ?>
            <?php if (count($jobs) === 80): ?><span style="color:var(--muted);font-size:.8rem;"> (showing first 80)</span><?php endif; ?>
        </p>
        <a href="register.php" class="bj-btn bj-btn-primary" style="padding:6px 14px;font-size:.8rem;">Post a Job →</a>
    </div>

    <?php if ($jobs): ?>
    <div class="bj-grid">
        <?php foreach ($jobs as $job):
            $isFeatured = !empty($job['featured']) && (empty($job['featured_end_date']) || $job['featured_end_date'] >= date('Y-m-d'));
            $isPartial  = $job['status'] === 'partially_staffed';
        ?>
        <article class="bj-card <?php echo $isFeatured ? 'featured' : ''; ?>">
            <?php
                $jtIcon  = ['on_site' => '📍', 'remote' => '💻', 'hybrid' => '🔄'];
                $jtLabel = ['on_site' => 'On-site', 'remote' => 'Remote', 'hybrid' => 'Hybrid'];
                $jtClass = ['on_site' => 'on-site', 'remote' => 'remote', 'hybrid' => 'hybrid'];
                $jt      = $job['job_type'] ?? 'on_site';
            ?>
            <div class="bj-card-head">
                <div style="min-width:0;">
                    <h2 class="bj-card-title">
                        <?php echo sanitize($job['title']); ?>
                        <?php if ($isFeatured): ?><span class="feat-tag">⭐ Featured</span><?php endif; ?>
                    </h2>
                    <p class="bj-card-meta">
                        <?php echo sanitize($job['category_name']); ?>
                        <?php if ($job['location']): ?> · <?php echo sanitize($job['location']); ?><?php endif; ?>
                        · <?php echo time_ago($job['created_at']); ?>
                    </p>
                </div>
                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex-shrink:0;">
                    <span class="bj-status <?php echo $isPartial ? 'partial' : 'open'; ?>">
                        <?php echo $isPartial ? 'PARTIAL' : 'OPEN'; ?>
                    </span>
                    <span class="job-type-badge <?php echo $jtClass[$jt]; ?>"><?php echo $jtIcon[$jt]; ?> <?php echo $jtLabel[$jt]; ?></span>
                </div>
            </div>

            <div class="bj-card-desc"><?php echo render_rich($job['description']); ?></div>

            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <?php if ($job['budget_amount']): ?>
                        <span class="bj-card-budget">GH₵ <?php echo number_format((float)$job['budget_amount'], 2); ?><?php if (!empty($job['price_unit'])): ?> / <?php echo sanitize($job['price_unit']); ?><?php endif; ?></span>
                    <?php elseif ($job['budget']): ?>
                        <span class="bj-card-budget"><?php echo sanitize(mb_substr($job['budget'], 0, 30)); ?><?php if (!empty($job['price_unit'])): ?> / <?php echo sanitize($job['price_unit']); ?><?php endif; ?></span>
                    <?php else: ?>
                        <span class="bj-card-meta">Budget: negotiable</span>
                    <?php endif; ?>
                    <?php
                    $pMode   = $job['payment_mode']   ?? 'direct';
                    $pStatus = $job['payment_status'] ?? 'unpaid';
                    if ($pMode === 'escrow' && $pStatus === 'paid'): ?>
                        <span style="font-size:.68rem;font-weight:800;padding:2px 8px;border-radius:20px;background:#d1fae5;color:#065f46;">🔒 Escrow Paid</span>
                    <?php elseif ($pMode === 'escrow'): ?>
                        <span style="font-size:.68rem;font-weight:800;padding:2px 8px;border-radius:20px;background:#fef3c7;color:#92400e;">🔒 Escrow</span>
                    <?php elseif ($pStatus === 'paid'): ?>
                        <span style="font-size:.68rem;font-weight:800;padding:2px 8px;border-radius:20px;background:#dbeafe;color:#1e40af;">✓ Paid</span>
                    <?php else: ?>
                        <span style="font-size:.68rem;font-weight:800;padding:2px 8px;border-radius:20px;background:#f3f4f6;color:#6b7280;">Unpaid</span>
                    <?php endif; ?>
                </div>
                <?php if (($job['workers_needed'] ?? 1) > 1): ?>
                    <span class="bj-card-meta"><?php echo (int)($job['workers_approved'] ?? 0); ?>/<?php echo (int)$job['workers_needed']; ?> hired</span>
                <?php endif; ?>
            </div>

            <div class="bj-card-actions">
                <a href="login.php?redirect=<?php echo urlencode('request_detail.php?id=' . $job['id']); ?>" class="bj-btn bj-btn-primary">Apply Now</a>
                <a href="request_detail.php?id=<?php echo (int)$job['id']; ?>" class="bj-btn bj-btn-outline">Details</a>
            </div>
        </article>
        <?php endforeach; ?>
    </div>

    <?php else: ?>
    <div class="bj-empty">
        <h3>No jobs match your search</h3>
        <p>Try adjusting your filters or check back later for new listings.</p>
        <a href="browse_jobs.php" class="bj-btn bj-btn-primary" style="display:inline-block;">Clear filters</a>
    </div>
    <?php endif; ?>

    <!-- ── Register CTA ─────────────────────────────────────────────────────── -->
    <div class="bj-cta">
        <h2>Ready to start working?</h2>
        <p>Create a free account to apply for jobs, post your own service requests, and connect with the Akuapem community.</p>
        <div class="bj-cta-btns">
            <a href="register.php?type=worker"   class="bj-btn bj-btn-primary">Join as a Worker</a>
            <a href="register.php?type=customer" class="bj-btn bj-btn-outline">Post a Job</a>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/site_footer.php'; ?>
</body>
</html>
