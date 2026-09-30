<?php
/**
 * FM Stations / Online Radio module — shared helper functions.
 * Include with: require_once __DIR__ . '/fm_functions.php';
 */

// ── Slugs ────────────────────────────────────────────────────────────────────
// Mirrors ev_unique_slug()/fa_unique_slug() (event_edit.php/funeral_edit.php)
// — each module keeps its own tiny copy rather than sharing one, matching
// this codebase's existing convention.
function fm_unique_slug(PDO $pdo, string $base, int $excludeId = 0): string {
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($base)));
    $slug = trim($slug, '-') ?: 'station';
    $test = $slug;
    $i = 2;
    while (true) {
        $stmt = $pdo->prepare('SELECT id FROM fm_stations WHERE slug = ? AND id != ?');
        $stmt->execute([$test, $excludeId]);
        if (!$stmt->fetch()) return $test;
        $test = $slug . '-' . $i++;
    }
}

/**
 * A "programme" for page purposes is really every fm_programmes row that
 * shares the same name at one station (e.g. "Morning Drive" airing Mon-Fri
 * is 5 rows, 1 page). When admin adds a new day for a name that already
 * exists at this station, we reuse that slug instead of minting a new one,
 * so all its time-slots resolve to the same fm_programme.php page. Slug
 * uniqueness is scoped per-station (enforced by the station_id+slug unique
 * key), not global — mirrors fm_unique_slug()'s shape otherwise.
 */
function fm_programme_slug_for(PDO $pdo, int $stationId, string $name, int $excludeId = 0): string {
    $stmt = $pdo->prepare(
        'SELECT slug FROM fm_programmes WHERE station_id = ? AND LOWER(name) = LOWER(?) AND id != ? AND slug IS NOT NULL LIMIT 1'
    );
    $stmt->execute([$stationId, $name, $excludeId]);
    $existing = $stmt->fetchColumn();
    if ($existing) return $existing;

    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($name)));
    $slug = trim($slug, '-') ?: 'programme';
    $test = $slug;
    $i = 2;
    while (true) {
        $check = $pdo->prepare('SELECT id FROM fm_programmes WHERE station_id = ? AND slug = ? AND id != ?');
        $check->execute([$stationId, $test, $excludeId]);
        if (!$check->fetch()) return $test;
        $test = $slug . '-' . $i++;
    }
}

/** Mirrors fm_unique_slug() but scoped per-station, for presenter profile URLs. */
function fm_presenter_unique_slug(PDO $pdo, int $stationId, string $base, int $excludeId = 0): string {
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($base)));
    $slug = trim($slug, '-') ?: 'presenter';
    $test = $slug;
    $i = 2;
    while (true) {
        $stmt = $pdo->prepare('SELECT id FROM fm_presenters WHERE station_id = ? AND slug = ? AND id != ?');
        $stmt->execute([$stationId, $test, $excludeId]);
        if (!$stmt->fetch()) return $test;
        $test = $slug . '-' . $i++;
    }
}

// ── Stream URL validation ────────────────────────────────────────────────────
// Only http/https may ever be stored — blocks javascript:/data: and anything
// else that isn't a real external stream.
function fm_is_valid_stream_url(string $url): bool {
    if ($url === '') return true; // optional field
    $parts = parse_url($url);
    return isset($parts['scheme'], $parts['host']) && in_array(strtolower($parts['scheme']), ['http', 'https'], true);
}

/**
 * Per-station brand colour → {base, dark, soft, rgb} shades for the public
 * station page (hero/player/chips/progress bar). Mirrors mkt_color_shades()
 * (functions.php) — same validation, same default-green fallback, same
 * reuse of adjust_hex_brightness() — just different shade percentages tuned
 * for this page's light-tint (soft) + dark-gradient-stop (dark) needs.
 */
function fm_color_shades(?string $baseHex): array {
    $default = '#2f8f5b';
    $hex = ($baseHex && preg_match('/^#[0-9a-f]{6}$/i', $baseHex)) ? $baseHex : $default;
    [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
    return [
        'base' => $hex,
        'dark' => adjust_hex_brightness($hex, -0.22),
        'soft' => adjust_hex_brightness($hex, 0.82),
        'rgb'  => "$r,$g,$b",
    ];
}

function fm_stream_type_labels(): array {
    return [
        'mp3'       => 'MP3 stream',
        'aac'       => 'AAC stream',
        'hls'       => 'HLS (m3u8)',
        'icecast'   => 'Icecast',
        'shoutcast' => 'Shoutcast',
        'other'     => 'Other',
    ];
}

// ── Live status ──────────────────────────────────────────────────────────────
// Distinguishes "the station record is active on AkuapemConnect" (status)
// from "the stream is actually broadcasting right now" (live). An admin can
// force 'live' or 'offline'; 'auto' does a short, cached, HEAD-only probe of
// the stream URL so we're never guessing without checking, but also never
// hammering the stream — apcu_remember() caches the result for 60s
// regardless of how many visitors hit the page in that window (same pattern
// marketplace_functions.php uses for its avg-view-count cache).
function fm_station_is_live(array $station): bool {
    if ($station['live_mode'] === 'live') return true;
    if ($station['live_mode'] === 'offline') return false;
    if (empty($station['stream_url'])) return false;

    $cacheKey = 'fm_live_' . md5($station['stream_url']);
    return (bool)apcu_remember($cacheKey, 60, function () use ($station) {
        $ch = curl_init($station['stream_url']);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true, // HEAD-equivalent — never downloads the stream itself
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => false, // many community radio Icecast boxes run self-signed/expired certs
        ]);
        curl_exec($ch);
        $ok = curl_errno($ch) === 0 && curl_getinfo($ch, CURLINFO_HTTP_CODE) < 400;
        curl_close($ch);
        return $ok;
    });
}

// ── Programme schedule ───────────────────────────────────────────────────────

/** All active programmes for a station on one day, ordered by start time. */
function fm_get_day_programmes(int $stationId, int $dayOfWeek): array {
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT * FROM fm_programmes WHERE station_id = ? AND day_of_week = ? AND status = 'active'
         ORDER BY start_time ASC"
    );
    $stmt->execute([$stationId, $dayOfWeek]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Every active programme for a station, grouped by day (0=Sunday..6=Saturday). */
function fm_get_weekly_schedule(int $stationId): array {
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT * FROM fm_programmes WHERE station_id = ? AND status = 'active'
         ORDER BY day_of_week ASC, start_time ASC"
    );
    $stmt->execute([$stationId]);
    $byDay = array_fill(0, 7, []);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byDay[(int)$row['day_of_week']][] = $row;
    }
    return $byDay;
}

/**
 * The programme airing right now, or null if nothing is scheduled — never
 * fabricates a result. Handles a programme that crosses midnight (e.g.
 * 11:00 PM – 1:00 AM) by also checking *yesterday's* schedule for a slot
 * that wraps into today.
 */
function fm_get_current_programme(int $stationId): ?array {
    $now      = date('H:i:s');
    $today    = (int)date('w');
    $yesterday = ($today + 6) % 7;

    foreach (fm_get_day_programmes($stationId, $today) as $p) {
        if ($p['start_time'] <= $p['end_time']) {
            // Normal same-day slot.
            if ($now >= $p['start_time'] && $now < $p['end_time']) return $p;
        } else {
            // Crosses midnight (e.g. 23:00–01:00) — the part of it that
            // falls on *today* is from midnight up to end_time.
            if ($now < $p['end_time']) return $p;
        }
    }
    foreach (fm_get_day_programmes($stationId, $yesterday) as $p) {
        if ($p['start_time'] > $p['end_time'] && $now >= $p['start_time']) {
            return $p; // yesterday's overnight slot is still running into today
        }
    }
    return null;
}

/**
 * The next programme coming up after right now, across the rest of today and
 * up to 6 days ahead — used for the "Up Next" hint under On Air Now. Takes
 * an already-fetched weekly schedule (fm_get_weekly_schedule()) rather than
 * querying again. Returns null if nothing is scheduled at all. Adds
 * '_days_ahead' (0 = later today, 1 = tomorrow, etc.) so the view can label it.
 */
function fm_get_next_programme(array $weeklySchedule, int $today): ?array {
    $now = date('H:i:s');
    for ($offset = 0; $offset <= 6; $offset++) {
        $day = ($today + $offset) % 7;
        foreach ($weeklySchedule[$day] ?? [] as $p) {
            if ($offset === 0 && $p['start_time'] <= $now) continue; // already started or passed today
            $p['_days_ahead'] = $offset;
            return $p;
        }
    }
    return null;
}

/**
 * Returns a warning string if $newStart/$newEnd would overlap an existing
 * active programme on the same station+day (excluding $excludeId when
 * editing) — or null if there's no conflict. A warning, not a hard block,
 * per the spec: the admin can still save deliberately overlapping slots.
 */
function fm_check_schedule_overlap(int $stationId, int $dayOfWeek, string $newStart, string $newEnd, int $excludeId = 0): ?string {
    foreach (fm_get_day_programmes($stationId, $dayOfWeek) as $p) {
        if ((int)$p['id'] === $excludeId) continue;
        $existingWraps = $p['start_time'] > $p['end_time'];
        $newWraps      = $newStart > $newEnd;
        // Simple, conservative overlap test. Midnight-crossing slots are rare
        // enough here that we just flag them against every other slot that
        // day rather than doing full modular-time overlap math — a false
        // positive just means the admin double-checks a slot that's
        // probably fine, which is safe; a false negative would let an actual
        // conflict slip through, which is the shape we want to avoid.
        if ($existingWraps || $newWraps) {
            return "Heads up: \"{$p['name']}\" (" . format_time_range($p['start_time'], $p['end_time']) . ") crosses midnight and may overlap this slot — please double-check.";
        }
        if ($newStart < $p['end_time'] && $newEnd > $p['start_time']) {
            return "This overlaps \"{$p['name']}\" (" . format_time_range($p['start_time'], $p['end_time']) . ") on " . get_weekday_names()[$dayOfWeek] . ".";
        }
    }
    return null;
}

// ── Misc ─────────────────────────────────────────────────────────────────────

function fm_get_station(string $slug): ?array {
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT fs.*, t.name AS town_name FROM fm_stations fs
         LEFT JOIN towns t ON t.id = fs.town_id
         WHERE fs.slug = ? LIMIT 1"
    );
    $stmt->execute([$slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// ── Announcements ────────────────────────────────────────────────────────────

/** Active banner-style notices for a station right now — status + optional date window, same shape as `promotions`. */
function fm_get_active_announcements(int $stationId): array {
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT * FROM fm_station_announcements
         WHERE station_id = ? AND status = 'active'
           AND (starts_at IS NULL OR starts_at <= CURDATE())
           AND (ends_at IS NULL OR ends_at >= CURDATE())
         ORDER BY created_at DESC"
    );
    $stmt->execute([$stationId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Presenters ───────────────────────────────────────────────────────────────

function fm_get_presenter_by_id(?int $id): ?array {
    if (!$id) return null;
    global $pdo;
    $stmt = $pdo->prepare("SELECT id, station_id, name, slug FROM fm_presenters WHERE id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function fm_get_presenter(int $stationId, string $slug): ?array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM fm_presenters WHERE station_id = ? AND slug = ? LIMIT 1");
    $stmt->execute([$stationId, $slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Distinct programmes (by slug) a presenter hosts at their station, for their profile page. */
function fm_get_presenter_programmes(int $presenterId): array {
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT slug, name, MIN(image_path) AS image_path FROM fm_programmes
         WHERE presenter_id = ? AND status = 'active' AND slug IS NOT NULL
         GROUP BY slug, name ORDER BY name"
    );
    $stmt->execute([$presenterId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Programme pages ──────────────────────────────────────────────────────────

/**
 * A "programme" page aggregates every fm_programmes row sharing one slug at
 * one station (its weekly time-slots) into a single result: the identity
 * fields (name/description/image/presenter) from the first row, plus a
 * 'slots' list of every day/time it airs. Returns null if the slug matches
 * no active programme at this station.
 */
function fm_get_programme(int $stationId, string $slug): ?array {
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT * FROM fm_programmes WHERE station_id = ? AND slug = ? AND status = 'active'
         ORDER BY day_of_week ASC, start_time ASC"
    );
    $stmt->execute([$stationId, $slug]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return null;

    $first = $rows[0];
    foreach ($rows as $r) {
        if (!empty($r['description'])) { $first['description'] = $r['description']; break; }
    }
    foreach ($rows as $r) {
        if (!empty($r['image_path'])) { $first['image_path'] = $r['image_path']; break; }
    }
    $first['slots'] = array_map(fn($r) => [
        'day_of_week' => (int)$r['day_of_week'],
        'start_time'  => $r['start_time'],
        'end_time'    => $r['end_time'],
    ], $rows);
    return $first;
}

// ── Listener reactions ───────────────────────────────────────────────────────
// Session-deduped, no accounts required — matches the module's existing
// view-count dedup pattern (fm_station.php's $_SESSION['viewed_fm_station_…'])
// and the spec's explicit "no listener accounts yet" constraint.

function fm_reaction_types(): array {
    return ['heart' => '❤️', 'fire' => '🔥', 'laugh' => '😂', 'clap' => '👏'];
}

function fm_get_reaction_counts(int $stationId, string $programmeSlug): array {
    global $pdo;
    $counts = array_fill_keys(array_keys(fm_reaction_types()), 0);
    $stmt = $pdo->prepare(
        "SELECT reaction_type, COUNT(*) AS n FROM fm_programme_reactions
         WHERE station_id = ? AND programme_slug = ? GROUP BY reaction_type"
    );
    $stmt->execute([$stationId, $programmeSlug]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($counts[$row['reaction_type']])) $counts[$row['reaction_type']] = (int)$row['n'];
    }
    return $counts;
}

/** Records one reaction tap; silently no-ops if this session already reacted this way (UNIQUE key). */
function fm_record_reaction(int $stationId, string $programmeSlug, string $reactionType, string $sessionKey): void {
    global $pdo;
    if (!array_key_exists($reactionType, fm_reaction_types())) return;
    $pdo->prepare(
        "INSERT IGNORE INTO fm_programme_reactions (station_id, programme_slug, reaction_type, session_key) VALUES (?,?,?,?)"
    )->execute([$stationId, $programmeSlug, $reactionType, $sessionKey]);
}

function fm_session_key(): string {
    if (empty($_SESSION['fm_session_key'])) {
        $_SESSION['fm_session_key'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['fm_session_key'];
}
