<?php
/**
 * cleanup_images.php — Orphan image cleanup script.
 *
 * Run from CLI only:  php cleanup_images.php [--dry-run]
 *
 * Deletes:
 *  - uploads/chat/ files not referenced in chat_messages.file_path or thumb_path
 *  - uploads/profiles/<id>/ files not referenced in users.profile_photo
 *  - uploads/worker_ids/<id>/ files not referenced in worker_profiles.id_document_path
 *  - uploads/completions/<id>/ files not referenced in completion_photos.file_path
 *  - Files older than 24h in uploads/chat/ whose DB row is deleted (soft-deleted messages)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/db.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$deleted = 0;
$freed   = 0;

function scan_dir(string $dir): array {
    if (!is_dir($dir)) return [];
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile()) $files[] = $f->getPathname();
    }
    return $files;
}

function delete_file(string $path, bool $dryRun, int &$deleted, int &$freed): void {
    global $dryRun;
    $size = filesize($path);
    if (!$dryRun) {
        @unlink($path);
    }
    echo ($dryRun ? '[DRY] Would delete' : 'Deleted') . ': ' . $path . ' (' . number_format($size / 1024, 1) . ' KB)' . PHP_EOL;
    $deleted++;
    $freed += $size;
}

// ── 1. Chat images ────────────────────────────────────────────────────────────
echo "== Chat images ==" . PHP_EOL;
$chatDir = __DIR__ . '/uploads/chat/';
$chatFiles = scan_dir($chatDir);

$refStmt = $pdo->query("SELECT file_path, thumb_path FROM chat_messages WHERE file_path IS NOT NULL OR thumb_path IS NOT NULL");
$chatRefs = [];
foreach ($refStmt->fetchAll() as $row) {
    if ($row['file_path'])  $chatRefs[realpath(__DIR__ . '/' . $row['file_path'])  ?: ''] = true;
    if ($row['thumb_path']) $chatRefs[realpath(__DIR__ . '/' . $row['thumb_path']) ?: ''] = true;
}

foreach ($chatFiles as $file) {
    $real = realpath($file);
    if (!isset($chatRefs[$real])) {
        delete_file($file, $dryRun, $deleted, $freed);
    }
}

// ── 2. Profile photos ─────────────────────────────────────────────────────────
echo PHP_EOL . "== Profile photos ==" . PHP_EOL;
$profDir = __DIR__ . '/uploads/profiles/';
$profFiles = scan_dir($profDir);

$profStmt = $pdo->query("SELECT profile_photo FROM users WHERE profile_photo IS NOT NULL");
$profRefs  = [];
foreach ($profStmt->fetchAll(PDO::FETCH_COLUMN) as $p) {
    $profRefs[realpath(__DIR__ . '/' . $p) ?: ''] = true;
}

foreach ($profFiles as $file) {
    if (!isset($profRefs[realpath($file)])) {
        delete_file($file, $dryRun, $deleted, $freed);
    }
}

// ── 3. Worker ID documents ────────────────────────────────────────────────────
echo PHP_EOL . "== Worker ID documents ==" . PHP_EOL;
$idDir   = __DIR__ . '/uploads/worker_ids/';
$idFiles = scan_dir($idDir);

$idStmt = $pdo->query("SELECT id_document_path FROM worker_profiles WHERE id_document_path IS NOT NULL");
$idRefs = [];
foreach ($idStmt->fetchAll(PDO::FETCH_COLUMN) as $p) {
    $idRefs[realpath(__DIR__ . '/' . $p) ?: ''] = true;
}

foreach ($idFiles as $file) {
    if (!isset($idRefs[realpath($file)])) {
        delete_file($file, $dryRun, $deleted, $freed);
    }
}

// ── 4. Completion photos ──────────────────────────────────────────────────────
echo PHP_EOL . "== Completion photos ==" . PHP_EOL;
$compDir   = __DIR__ . '/uploads/completions/';
$compFiles = scan_dir($compDir);

try {
    $compStmt = $pdo->query("SELECT file_path FROM completion_photos WHERE file_path IS NOT NULL");
    $compRefs = [];
    foreach ($compStmt->fetchAll(PDO::FETCH_COLUMN) as $p) {
        $compRefs[realpath(__DIR__ . '/' . $p) ?: ''] = true;
    }
    foreach ($compFiles as $file) {
        if (!isset($compRefs[realpath($file)])) {
            delete_file($file, $dryRun, $deleted, $freed);
        }
    }
} catch (Exception $e) {
    echo "Skipped (completion_photos table not found)." . PHP_EOL;
}

// ── Summary ───────────────────────────────────────────────────────────────────
echo PHP_EOL . ($dryRun ? '[DRY RUN] ' : '') . "Done. $deleted file(s) " . ($dryRun ? 'would be' : '') . " deleted, " . number_format($freed / 1024, 1) . " KB freed." . PHP_EOL;
