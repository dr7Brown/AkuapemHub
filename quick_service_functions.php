<?php
/**
 * Quick Services module — shared helper functions.
 * Include with: require_once __DIR__ . '/quick_service_functions.php';
 */

// ── Manager assignment ──────────────────────────────────────────────────────
// Mirrors the market manager-assignment pattern exactly (admin=all-access,
// manager needs both the permission AND an explicit quick_service_managers
// row for that specific service).

function user_can_manage_quick_service(int $userId, int $serviceId): bool {
    global $pdo;
    $roleSt = $pdo->prepare('SELECT role FROM users WHERE id=?');
    $roleSt->execute([$userId]);
    if ($roleSt->fetchColumn() === 'admin') return true;

    if (!in_array('manage_quick_service_requests', get_user_mod_permissions($userId), true)) return false;
    $st = $pdo->prepare('SELECT 1 FROM quick_service_managers WHERE service_id=? AND user_id=?');
    $st->execute([$serviceId, $userId]);
    return (bool)$st->fetchColumn();
}

/** Quick Service IDs a given manager is assigned to (empty for admins, who see all). */
function get_managed_quick_service_ids(int $userId): array {
    global $pdo;
    $st = $pdo->prepare('SELECT service_id FROM quick_service_managers WHERE user_id=?');
    $st->execute([$userId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** User IDs assigned to manage a given service (for fan-out notifications). */
function qs_service_manager_ids(int $serviceId): array {
    global $pdo;
    $stmt = $pdo->prepare('SELECT user_id FROM quick_service_managers WHERE service_id = ?');
    $stmt->execute([$serviceId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// ── Reference & status badges ───────────────────────────────────────────────

/** Human-readable Quick Services transaction reference, e.g. QS-000123. */
function qs_reference(int $id): string {
    return 'QS-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
}

/** Icon/label/CSS-class for a transaction's payment_status — kept deliberately
 *  separate from processing_status per the module's design. */
function qs_payment_badge(string $status): array {
    $map = [
        'pending'  => ['⏳', 'PENDING', 'pending'],
        'paid'     => ['🟢', 'PAID', 'completed'],
        'failed'   => ['🔴', 'FAILED', 'failed'],
        'refunded' => ['⚪', 'REFUNDED', 'cancelled'],
    ];
    return $map[$status] ?? ['⚪', strtoupper($status), 'pending'];
}

/** Icon/label/CSS-class for a transaction's processing_status. */
function qs_processing_badge(string $status): array {
    $map = [
        'awaiting_assignment' => ['⏳', 'AWAITING ASSIGNMENT', 'pending'],
        'assigned'            => ['🟡', 'ASSIGNED', 'pending'],
        'processing'          => ['🟡', 'PROCESSING', 'processing'],
        'completed'           => ['🟢', 'COMPLETED', 'completed'],
        'failed'              => ['🔴', 'FAILED', 'failed'],
        'cancelled'           => ['⚪', 'CANCELLED', 'cancelled'],
    ];
    return $map[$status] ?? ['⚪', strtoupper($status), 'pending'];
}

// ── Catalog lookups ──────────────────────────────────────────────────────────

function qs_get_service(int $id): ?array {
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM quick_services WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function qs_get_service_by_slug(string $slug): ?array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM quick_services WHERE slug = ? AND status = 'active'");
    $stmt->execute([$slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Active networks, each with its active bundles nested under ['bundles']. */
/** Admin-configurable order (see admin/quick_services.php) — always grouped
 *  by network; this only controls the order of bundles within each network. */
function qs_bundle_order_by(): string {
    $sort = get_platform_setting('qs_bundle_sort', 'default');
    return [
        'label'   => 'b.label',
        'price'   => 'b.price',
        'default' => 'b.display_order, b.price',
    ][$sort] ?? 'b.display_order, b.price';
}

function qs_get_networks_with_bundles(): array {
    global $pdo;
    $networks = $pdo->query("SELECT * FROM quick_data_networks WHERE status='active' ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC);
    $orderBy = qs_bundle_order_by();
    $bundles = $pdo->query("SELECT * FROM quick_data_bundles b WHERE status='active' ORDER BY {$orderBy}")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($networks as &$n) {
        $n['bundles'] = array_values(array_filter($bundles, fn($b) => (int)$b['network_id'] === (int)$n['id']));
    }
    unset($n);
    return $networks;
}

function qs_get_bundle(int $id): ?array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT b.*, n.name AS network_name FROM quick_data_bundles b JOIN quick_data_networks n ON n.id=b.network_id WHERE b.id=? AND b.status='active'");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Active flat-priced options for a service (e.g. Result Services' 4 checker/check choices). */
function qs_get_service_options(int $serviceId): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM quick_service_options WHERE service_id=? AND status='active' ORDER BY display_order, price");
    $stmt->execute([$serviceId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function qs_get_option(int $id): ?array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM quick_service_options WHERE id=? AND status='active'");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// ── Transactions ─────────────────────────────────────────────────────────────

function qs_get_transaction(int $id): ?array {
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT qt.*, qs.name AS service_name, qs.slug AS service_slug, qs.service_type
         FROM quick_transactions qt JOIN quick_services qs ON qs.id = qt.service_id WHERE qt.id = ?"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function qs_get_transaction_by_reference(string $reference): ?array {
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT qt.*, qs.name AS service_name, qs.slug AS service_slug, qs.service_type
         FROM quick_transactions qt JOIN quick_services qs ON qs.id = qt.service_id WHERE qt.reference = ?"
    );
    $stmt->execute([$reference]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Refunds the real Paystack payment behind a Quick Services transaction (if
 * any — one approved with no linked platform_payments row simply has
 * nothing to call Paystack for) and marks that payment 'refunded'. Reuses
 * the generic paystack_refund() rather than a parallel implementation.
 *
 * @return array{ok:bool, error:?string}
 */
function qs_process_refund(array $transaction): array {
    global $pdo;
    if (empty($transaction['platform_payment_id'])) {
        return ['ok' => true, 'error' => null];
    }
    $payStmt = $pdo->prepare('SELECT * FROM platform_payments WHERE id=?');
    $payStmt->execute([$transaction['platform_payment_id']]);
    $payment = $payStmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment || $payment['status'] === 'refunded') {
        return ['ok' => true, 'error' => null];
    }

    if (!empty($payment['paystack_transaction_id'])) {
        require_once __DIR__ . '/paystack.php';
        $result = paystack_refund((string)$payment['paystack_transaction_id']);
        if (!$result['success']) {
            return ['ok' => false, 'error' => $result['error'] ?? 'Refund request failed.'];
        }
    }
    $pdo->prepare("UPDATE platform_payments SET status='refunded' WHERE id=?")->execute([$payment['id']]);
    return ['ok' => true, 'error' => null];
}

// ── Secure result file storage ──────────────────────────────────────────────

/**
 * Saves a Quick Services result PDF into a directory that is NOT publicly
 * web-servable (protected by a sibling .htaccess with "Deny from all") —
 * exam results are sensitive, so unlike normal uploads/ files this must
 * never be reachable by a guessed/leaked direct URL. Served only through
 * qs_result.php, which checks the requester is the owning customer, an
 * assigned manager, or an admin before streaming it.
 */
function qs_save_result_file(array $file): ?string {
    $allowedMimes = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    $maxBytes = 10 * 1024 * 1024;

    if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > $maxBytes) return null;

    $mimeAliases = ['image/x-png' => 'image/png', 'image/pjpeg' => 'image/jpeg', 'image/jpg' => 'image/jpeg'];
    $mimeType = mime_content_type($file['tmp_name']);
    $mimeType = $mimeAliases[$mimeType] ?? $mimeType;
    if (!isset($allowedMimes[$mimeType])) return null;

    $dir = __DIR__ . '/private_uploads/quick_service_results';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) return null;

    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) file_put_contents($htaccess, "Deny from all\nRequire all denied\n");

    $filename = bin2hex(random_bytes(16)) . '.' . $allowedMimes[$mimeType];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) return null;

    return $filename; // stored bare — qs_result.php resolves it against the private dir
}
