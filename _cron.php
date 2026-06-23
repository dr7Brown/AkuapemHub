<?php
/**
 * _cron.php — scheduled expiry sweep (H3)
 *
 * Run via Windows Task Scheduler or a web cron service.
 * CLI: php _cron.php
 * Web: GET _cron.php?token=<CRON_TOKEN>   (set CRON_TOKEN in config or below)
 */

// ── Access guard ────────────────────────────────────────────────────────────
define('CRON_TOKEN', getenv('CRON_TOKEN') ?: 'changeme-set-a-strong-token');

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $supplied = $_GET['token'] ?? '';
    if (!hash_equals(CRON_TOKEN, $supplied)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

// ── Run sweep ────────────────────────────────────────────────────────────────
$before = microtime(true);

// Count rows before so we can report what changed
$beforeCounts = [
    'expired_featured_jobs'      => (int)$pdo->query("SELECT COUNT(*) FROM service_requests WHERE featured = 1 AND featured_end_date IS NOT NULL AND featured_end_date < CURDATE()")->fetchColumn(),
    'expired_featured_workers'   => (int)$pdo->query("SELECT COUNT(*) FROM worker_profiles WHERE is_featured = 1 AND featured_end_date IS NOT NULL AND featured_end_date < CURDATE()")->fetchColumn(),
    'expired_verifications'      => (int)$pdo->query("SELECT COUNT(*) FROM worker_profiles WHERE is_verified = 1 AND verification_expiry IS NOT NULL AND verification_expiry < CURDATE()")->fetchColumn(),
    'expired_service_listings'   => (int)$pdo->query("SELECT COUNT(*) FROM worker_profiles WHERE service_fee_status = 'paid' AND service_fee_expiry IS NOT NULL AND service_fee_expiry < CURDATE()")->fetchColumn(),
    'renewal_notices_pending'    => (int)$pdo->query("SELECT COUNT(*) FROM worker_profiles WHERE service_fee_status = 'paid' AND service_fee_expiry IS NOT NULL AND service_fee_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND service_renewal_notice_sent = 0")->fetchColumn(),
    'expired_mp_products'        => (int)$pdo->query("SELECT COUNT(*) FROM mp_products WHERE (is_featured = 1 AND featured_end < CURDATE()) OR (is_sponsored = 1 AND sponsored_end < CURDATE())")->fetchColumn(),
    'expired_mp_shops'           => (int)$pdo->query("SELECT COUNT(*) FROM mp_shops WHERE (is_featured = 1 AND featured_end < CURDATE()) OR (is_sponsored = 1 AND sponsored_end < CURDATE())")->fetchColumn(),
    'expired_delivery_premium'   => (int)$pdo->query("SELECT COUNT(*) FROM delivery_agents WHERE is_premium = 1 AND premium_end IS NOT NULL AND premium_end < CURDATE()")->fetchColumn(),
    'expired_delivery_sponsored' => (int)$pdo->query("SELECT COUNT(*) FROM delivery_agents WHERE is_sponsored = 1 AND sponsored_end IS NOT NULL AND sponsored_end < CURDATE()")->fetchColumn(),
];

sweep_expired_featured();
$expiredJobs      = sweep_expired_jobs();
$remindersSent    = send_job_deadline_reminders();

$elapsed = round((microtime(true) - $before) * 1000);

$summary = [
    'ran_at'                      => date('Y-m-d H:i:s'),
    'elapsed_ms'                  => $elapsed,
    'expired_featured_jobs'       => $beforeCounts['expired_featured_jobs'],
    'expired_featured_workers'    => $beforeCounts['expired_featured_workers'],
    'expired_verifications'       => $beforeCounts['expired_verifications'],
    'expired_service_listings'    => $beforeCounts['expired_service_listings'],
    'renewal_notices_sent'        => $beforeCounts['renewal_notices_pending'],
    'jobs_auto_expired'           => $expiredJobs,
    'deadline_reminders_sent'     => $remindersSent,
];

// Log to audit_logs
log_audit_action(
    0, // system action, no admin user
    'cron_sweep',
    'Automated expiry sweep completed in ' . $elapsed . 'ms. ' .
    'Jobs expired: ' . $summary['expired_featured_jobs'] . '. ' .
    'Workers expired: ' . $summary['expired_featured_workers'] . '. ' .
    'Verifications expired: ' . $summary['expired_verifications'] . '. ' .
    'Service listings expired: ' . $summary['expired_service_listings'] . '. ' .
    'Renewal notices sent: ' . $summary['renewal_notices_sent'] . '. ' .
    'Job vacancies auto-expired: ' . $expiredJobs . '. ' .
    'Deadline reminders sent: ' . $remindersSent . '.'
);

// ── Output ───────────────────────────────────────────────────────────────────
if ($isCli) {
    echo "Cron sweep completed at " . $summary['ran_at'] . " ({$elapsed}ms)\n";
    foreach ($summary as $key => $val) {
        if ($key === 'ran_at' || $key === 'elapsed_ms') continue;
        echo "  {$key}: {$val}\n";
    }
} else {
    header('Content-Type: application/json');
    echo json_encode($summary, JSON_PRETTY_PRINT);
}
