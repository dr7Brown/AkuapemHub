<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

// Logged-in users go to their dashboard
if (current_user()) {
    header('Location: jobs.php');
    exit;
}

$categories     = get_categories();
$categoryFilter = $_GET['category'] ?? '';
$locationFilter = trim($_GET['location'] ?? '');
$searchQuery    = trim($_GET['q'] ?? '');
$jobTypeFilter  = $_GET['job_type'] ?? '';

// Build job query
$where  = ["sr.status IN ('open','partially_staffed')", "sr.posting_fee_status != 'pending'"];
$params = [];
if ($categoryFilter) { $where[] = 'sr.category_id = ?';            $params[] = $categoryFilter; }
if ($locationFilter) { $where[] = 'sr.location LIKE ?';            $params[] = '%' . $locationFilter . '%'; }
if ($jobTypeFilter && in_array($jobTypeFilter, ['on_site','remote','hybrid'], true)) {
    $where[] = 'sr.job_type = ?';
    $params[] = $jobTypeFilter;
}
if ($searchQuery)    {
    $where[]  = '(sr.title LIKE ? OR sr.description LIKE ? OR sc.name LIKE ? OR sr.location LIKE ?)';
    $params[] = '%' . $searchQuery . '%';
    $params[] = '%' . $searchQuery . '%';
    $params[] = '%' . $searchQuery . '%';
    $params[] = '%' . $searchQuery . '%';
}

$jobs = $pdo->prepare(
    'SELECT sr.id, sr.title, sr.description, sr.location, sr.budget, sr.budget_amount,
            sr.status, sr.featured, sr.featured_end_date, sr.created_at,
            sr.workers_needed, sr.workers_approved, sr.job_type,
            sc.name AS category_name
     FROM service_requests sr
     JOIN service_categories sc ON sr.category_id = sc.id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY sr.featured DESC, sr.created_at DESC LIMIT 80'
);
$jobs->execute($params);
$jobs = $jobs->fetchAll();

// Quick stats
$totalOpen  = (int)$pdo->query("SELECT COUNT(*) FROM service_requests WHERE status IN ('open','partially_staffed') AND posting_fee_status != 'pending'")->fetchColumn();
$totalCats  = (int)$pdo->query("SELECT COUNT(*) FROM service_categories")->fetchColumn();
$totalWorkers = (int)$pdo->query("SELECT COUNT(*) FROM worker_profiles")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Browse Jobs — AkuapemHub</title>
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
        .bj-filters {
            background: var(--surface, #fff);
            border-bottom: 1px solid var(--border, #e5e7eb);
            padding: 14px 16px;
        }
        .bj-filter-form {
            display: flex; gap: 8px; flex-wrap: wrap; max-width: 860px; margin: 0 auto;
        }
        .bj-filter-form select,
        .bj-filter-form input[type="text"] {
            flex: 1; min-width: 130px;
            padding: 9px 12px;
            border: 1px solid var(--border, #d1d5db);
            border-radius: 8px; font-size: .88rem;
            background: var(--bg, #f9fafb);
            color: var(--text, #111827);
        }
        .bj-filter-form select:focus,
        .bj-filter-form input:focus { outline: 2px solid var(--primary, #0f766e); border-color: transparent; }
        .bj-filter-form .btn-search {
            padding: 9px 18px; background: var(--primary, #0f766e); color: #fff;
            border: none; border-radius: 8px; font-weight: 700; font-size: .88rem; cursor: pointer;
        }
        .bj-filter-form .btn-search:hover { opacity: .9; }
        .bj-filter-clear { font-size: .82rem; color: var(--muted, #6b7280); text-decoration: none; align-self: center; white-space: nowrap; }

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
        .bj-card-desc  { font-size: .84rem; color: var(--text, #374151); line-height: 1.55; flex: 1;
                         display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
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
    <a href="index.php" class="bj-logo">AkuapemHub</a>
    <nav class="bj-nav">
        <a href="find_workers.php" class="link">Workers</a>
        <a href="community.php"    class="link">Community</a>
        <a href="login.php"    class="button button-secondary button-small">Sign in</a>
        <a href="register.php" class="button button-primary  button-small">Register</a>
    </nav>
</header>

<!-- ── Hero ────────────────────────────────────────────────────────────────── -->
<section class="bj-hero">
    <h1>Find Jobs &amp; Services in Akuapem</h1>
    <p>Browse hundreds of open service requests — plumbing, tutoring, carpentry, events &amp; more</p>
    <div class="bj-hero-btns">
        <a href="register.php" class="primary">Create free account</a>
        <a href="login.php"    class="secondary">Sign in</a>
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
    <form method="get" class="bj-filter-form">
        <select name="category">
            <option value="">All categories</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?php echo $cat['id']; ?>" <?php echo $categoryFilter == $cat['id'] ? 'selected' : ''; ?>>
                    <?php echo sanitize($cat['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="q"        value="<?php echo sanitize($searchQuery); ?>"   placeholder="Search jobs…" />
        <input type="text" name="location" value="<?php echo sanitize($locationFilter); ?>" placeholder="Location…" />
        <select name="job_type">
            <option value="">Any type</option>
            <option value="on_site"  <?php echo $jobTypeFilter === 'on_site'  ? 'selected' : ''; ?>>On-site</option>
            <option value="remote"   <?php echo $jobTypeFilter === 'remote'   ? 'selected' : ''; ?>>Remote</option>
            <option value="hybrid"   <?php echo $jobTypeFilter === 'hybrid'   ? 'selected' : ''; ?>>Hybrid</option>
        </select>
        <button type="submit" class="btn-search">Search</button>
        <?php if ($searchQuery || $locationFilter || $categoryFilter || $jobTypeFilter): ?>
            <a href="browse_jobs.php" class="bj-filter-clear">Clear filters</a>
        <?php endif; ?>
    </form>
</div>

<!-- ── Results ──────────────────────────────────────────────────────────────── -->
<div class="bj-content">
    <div class="bj-results-bar">
        <p>
            <?php if ($searchQuery || $locationFilter || $categoryFilter): ?>
                <?php echo count($jobs); ?> result<?php echo count($jobs) !== 1 ? 's' : ''; ?> for your search
            <?php else: ?>
                <?php echo count($jobs); ?> open job<?php echo count($jobs) !== 1 ? 's' : ''; ?> available
            <?php endif; ?>
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

            <p class="bj-card-desc"><?php echo sanitize($job['description']); ?></p>

            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
                <?php if ($job['budget'] || $job['budget_amount']): ?>
                    <span class="bj-card-budget">GH₵ <?php echo sanitize($job['budget'] ?: number_format((float)$job['budget_amount'], 2)); ?></span>
                <?php else: ?>
                    <span class="bj-card-meta">Budget: negotiable</span>
                <?php endif; ?>
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

</body>
</html>
