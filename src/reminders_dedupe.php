<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * reminders.dedupe_existing slår ihop öppna dubbletter på contact_id och produkt.
 * Tom produkt grupperas på contact_id och assignee, och bara när raderna är
 * skapade inom 24 timmar eller har samma anteckning. En tom produkt slås
 * inte ihop med en rad som har produkt.
 * Äldsta raden behålls, eller den som redan har en order eller bokning.
 * Anteckning, tryckhistorik och länk flyttas dit. Dubbletten stängs, den raderas inte.
 * En order eller bokning flyttas bara när den behållna raden saknar samma länk.
 * Två olika ordrar eller två olika bokningar lämnas orörda.
 * dry=1 är standard och skriver inget. dry=0 skriver en ångringslogg.
 * reminders.dedupe_undo läser den loggen. reminders.get läser id utan personnummer.
 */

function jeab_reminders_dedupe_wants_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $dry = $input['dry'];

    return !($dry === false || $dry === 0 || $dry === '0' || $dry === 0.0);
}

function jeab_reminders_dedupe_ensure_log(PDO $pdo): void
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS reminder_dedupe_log (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                batch_id VARCHAR(64) NOT NULL,
                reminder_id INT NOT NULL,
                kept_id INT NOT NULL,
                role VARCHAR(16) NOT NULL,
                contact_id VARCHAR(64) NOT NULL DEFAULT \'\',
                product VARCHAR(32) NOT NULL DEFAULT \'\',
                old_note TEXT,
                old_completed_at DATETIME DEFAULT NULL,
                old_booking_id INT DEFAULT NULL,
                old_thread_id VARCHAR(64) DEFAULT NULL,
                old_ringt INT NOT NULL DEFAULT 0,
                old_pratat INT NOT NULL DEFAULT 0,
                old_mailat INT NOT NULL DEFAULT 0,
                old_smsat INT NOT NULL DEFAULT 0,
                order_moved TINYINT(1) NOT NULL DEFAULT 0,
                booking_ids TEXT,
                undone TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY reminder_dedupe_log_batch (batch_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } else {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS reminder_dedupe_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                batch_id TEXT NOT NULL,
                reminder_id INTEGER NOT NULL,
                kept_id INTEGER NOT NULL,
                role TEXT NOT NULL,
                contact_id TEXT NOT NULL DEFAULT \'\',
                product TEXT NOT NULL DEFAULT \'\',
                old_note TEXT,
                old_completed_at TEXT,
                old_booking_id INTEGER,
                old_thread_id TEXT,
                old_ringt INTEGER NOT NULL DEFAULT 0,
                old_pratat INTEGER NOT NULL DEFAULT 0,
                old_mailat INTEGER NOT NULL DEFAULT 0,
                old_smsat INTEGER NOT NULL DEFAULT 0,
                order_moved INTEGER NOT NULL DEFAULT 0,
                booking_ids TEXT,
                undone INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS reminder_dedupe_log_batch ON reminder_dedupe_log (batch_id)');
    }
}

/**
 * @return array<int, true>
 */
function jeab_reminders_dedupe_order_ids(PDO $pdo): array
{
    $ids = [];
    if (!jeab_reminders_sql_table($pdo, 'purchases') || !jeab_reminders_sql_column($pdo, 'purchases', 'reminder_id')) {
        return $ids;
    }
    try {
        $stmt = $pdo->query('SELECT reminder_id FROM purchases');
        foreach ($stmt ? $stmt->fetchAll() : [] as $row) {
            $id = (int) (is_array($row) ? ($row['reminder_id'] ?? 0) : 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
    } catch (Throwable) {
        return [];
    }

    return $ids;
}

/**
 * Bokningsrader som pekar på en påminnelse. Kolumnen finns inte i varje lager.
 *
 * @return array<int, list<int>>
 */
function jeab_reminders_dedupe_booking_links(PDO $pdo): array
{
    $map = [];
    if (!jeab_reminders_sql_column($pdo, 'bookings', 'reminder_id')) {
        return $map;
    }
    try {
        $stmt = $pdo->query('SELECT id, reminder_id FROM bookings WHERE reminder_id > 0');
        foreach ($stmt ? $stmt->fetchAll() : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $reminderId = (int) ($row['reminder_id'] ?? 0);
            $bookingId = (int) ($row['id'] ?? 0);
            if ($reminderId <= 0 || $bookingId <= 0) {
                continue;
            }
            $map[$reminderId][] = $bookingId;
        }
    } catch (Throwable) {
        return [];
    }
    foreach ($map as $reminderId => $bookingIds) {
        $bookingIds = array_values(array_unique(array_map('intval', $bookingIds)));
        sort($bookingIds);
        $map[$reminderId] = $bookingIds;
    }

    return $map;
}

/**
 * @return list<array<string, mixed>>
 */
function jeab_reminders_dedupe_open_rows(PDO $pdo): array
{
    $sql = 'SELECT * FROM reminders WHERE id > 0 AND (completed_at IS NULL OR completed_at = \'\') AND contact_id IS NOT NULL AND contact_id != \'\'';
    if (jeab_reminders_sql_column($pdo, 'reminders', 'deleted_at')) {
        $sql .= ' AND (deleted_at IS NULL OR deleted_at = \'\')';
    }
    $stmt = $pdo->query($sql);
    $rows = [];
    foreach ($stmt ? $stmt->fetchAll() : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $hydrated = jeab_hydrate_reminder($row);
        if (jeab_reminder_is_trashed($hydrated) || trim((string) ($hydrated['completed_at'] ?? '')) !== '') {
            continue;
        }
        if (trim((string) ($hydrated['contact_id'] ?? '')) === '') {
            continue;
        }
        $rows[] = $hydrated;
    }

    return $rows;
}

/**
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 * @return array<string, mixed>
 */
function jeab_reminders_dedupe_older(array $a, array $b): array
{
    $ca = trim((string) ($a['created_at'] ?? ''));
    $cb = trim((string) ($b['created_at'] ?? ''));
    if ($ca === '') {
        $ca = '9999-99-99 99:99:99';
    }
    if ($cb === '') {
        $cb = '9999-99-99 99:99:99';
    }
    if ($ca < $cb) {
        return $a;
    }
    if ($cb < $ca) {
        return $b;
    }

    return (int) ($a['id'] ?? 0) <= (int) ($b['id'] ?? 0) ? $a : $b;
}

/**
 * @param array<string, mixed> $row
 * @param array<int, true> $orders
 * @param array<int, list<int>> $bookingLinks
 */
function jeab_reminders_dedupe_is_linked(array $row, array $orders, array $bookingLinks): bool
{
    $id = (int) ($row['id'] ?? 0);
    if ($id > 0 && isset($orders[$id])) {
        return true;
    }
    if ((int) ($row['booking_id'] ?? 0) > 0) {
        return true;
    }

    return $id > 0 && isset($bookingLinks[$id]) && $bookingLinks[$id] !== [];
}

function jeab_reminders_dedupe_close_line(int $keptId): string
{
    return 'sammanslagen med #' . $keptId;
}

function jeab_reminders_dedupe_append_close(string $note, int $keptId): string
{
    $line = jeab_reminders_dedupe_close_line($keptId);
    $note = trim($note);
    if ($note === '') {
        return $line;
    }
    if (str_contains($note, $line)) {
        return $note;
    }

    return $note . "\n" . $line;
}

/**
 * @param array<int, true> $orders
 * @param array<int, list<int>> $bookingLinks
 * @param list<array<string, mixed>> $rows
 * @return array<string, mixed>|null
 */
function jeab_reminders_dedupe_plan_group(array $rows, array $orders, array $bookingLinks): ?array
{
    if (count($rows) < 2) {
        return null;
    }
    $linked = [];
    foreach ($rows as $row) {
        if (jeab_reminders_dedupe_is_linked($row, $orders, $bookingLinks)) {
            $linked[] = $row;
        }
    }
    $pool = $linked !== [] ? $linked : $rows;
    $kept = $pool[0];
    foreach ($pool as $row) {
        $kept = jeab_reminders_dedupe_older($kept, $row);
    }
    $keptId = (int) ($kept['id'] ?? 0);
    if ($keptId <= 0) {
        return null;
    }
    $note = trim((string) ($kept['note'] ?? ''));
    $taps = jeab_reminder_tap_counts($kept);
    $thread = trim((string) ($kept['thread_id'] ?? ''));
    $bookingId = (int) ($kept['booking_id'] ?? 0);
    $hasOrder = isset($orders[$keptId]);
    $contact = trim((string) ($kept['contact_id'] ?? ''));
    $product = function_exists('jeab_inkorg_reminder_offer') ? jeab_inkorg_reminder_offer($kept) : '';
    $closed = [];
    $held = [];
    $others = $rows;
    usort($others, static fn (array $a, array $b): int => ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0)));
    foreach ($others as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0 || $id === $keptId) {
            continue;
        }
        $dupBooking = (int) ($row['booking_id'] ?? 0);
        $dupOrder = isset($orders[$id]);
        if (($hasOrder && $dupOrder) || ($bookingId > 0 && $dupBooking > 0 && $bookingId !== $dupBooking)) {
            $held[] = $id;
            continue;
        }
        $merged = function_exists('jeab_reminder_merge_hs_note')
            ? jeab_reminder_merge_hs_note($note, (string) ($row['note'] ?? ''))
            : trim($note . "\n" . trim((string) ($row['note'] ?? '')));
        $note = trim((string) $merged);
        foreach (jeab_reminder_tap_counts($row) as $key => $count) {
            $taps[$key] = (int) ($taps[$key] ?? 0) + (int) $count;
        }
        $dupThread = trim((string) ($row['thread_id'] ?? ''));
        if ($thread === '' && $dupThread !== '') {
            $thread = $dupThread;
        }
        $clearBooking = false;
        if ($dupBooking > 0 && $bookingId === 0) {
            $bookingId = $dupBooking;
            $clearBooking = true;
        } elseif ($dupBooking > 0 && $bookingId === $dupBooking) {
            $clearBooking = true;
        }
        $moveOrder = $dupOrder && !$hasOrder;
        if ($moveOrder) {
            $hasOrder = true;
        }
        $bookingRows = $bookingLinks[$id] ?? [];
        $closed[] = [
            'id' => $id,
            'old_note' => trim((string) ($row['note'] ?? '')),
            'old_completed_at' => trim((string) ($row['completed_at'] ?? '')),
            'old_booking_id' => $dupBooking,
            'old_thread_id' => $dupThread,
            'old_taps' => jeab_reminder_tap_counts($row),
            'note' => jeab_reminders_dedupe_append_close((string) ($row['note'] ?? ''), $keptId),
            'clear_booking' => $clearBooking,
            'move_order' => $moveOrder,
            'booking_rows' => $bookingRows,
        ];
    }
    if ($closed === []) {
        return null;
    }
    $ids = [$keptId];
    foreach ($closed as $item) {
        $ids[] = (int) $item['id'];
    }
    sort($ids);
    sort($held);

    return [
        'ids' => $ids,
        'kept' => $keptId,
        'contact' => $contact,
        'product' => $product,
        'held' => $held,
        'kept_before' => [
            'note' => trim((string) ($kept['note'] ?? '')),
            'booking_id' => (int) ($kept['booking_id'] ?? 0),
            'thread_id' => trim((string) ($kept['thread_id'] ?? '')),
            'taps' => jeab_reminder_tap_counts($kept),
        ],
        'kept_after' => [
            'note' => $note,
            'booking_id' => $bookingId,
            'thread_id' => $thread,
            'taps' => $taps,
        ],
        'closed' => $closed,
    ];
}

function jeab_reminders_dedupe_created_ts(array $row): int
{
    $raw = trim((string) ($row['created_at'] ?? ''));
    if ($raw === '') {
        return 0;
    }
    $ts = strtotime($raw);

    return $ts === false ? 0 : $ts;
}

function jeab_reminders_dedupe_note_key(array $row): string
{
    $note = str_replace("\r\n", "\n", trim((string) ($row['note'] ?? '')));

    return str_replace("\r", "\n", $note);
}

/**
 * Tom produkt, redan samma kontakt och assignee.
 * En tidsklunga ryms inom 24 timmar från den äldsta raden, även när
 * anteckningarna skiljer sig. Samma anteckning slår ihop det som blir kvar
 * när tiden är längre. En rad med produkt hör inte hemma här.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<list<array<string, mixed>>>
 */
function jeab_reminders_dedupe_empty_clusters(array $rows): array
{
    if (count($rows) < 2) {
        return [];
    }
    usort($rows, static function (array $a, array $b): int {
        $ta = jeab_reminders_dedupe_created_ts($a);
        $tb = jeab_reminders_dedupe_created_ts($b);
        if ($ta !== $tb) {
            return $ta <=> $tb;
        }

        return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
    });
    $used = [];
    $out = [];
    $count = count($rows);
    $index = 0;
    while ($index < $count) {
        $start = jeab_reminders_dedupe_created_ts($rows[$index]);
        $next = $index + 1;
        while ($next < $count) {
            $ts = jeab_reminders_dedupe_created_ts($rows[$next]);
            if ($start <= 0 || $ts <= 0 || ($ts - $start) > 86400) {
                break;
            }
            $next++;
        }
        if ($next - $index >= 2) {
            $cluster = [];
            for ($cursor = $index; $cursor < $next; $cursor++) {
                $row = $rows[$cursor];
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $cluster[] = $row;
                $used[$id] = true;
            }
            if (count($cluster) >= 2) {
                $out[] = $cluster;
            }
        }
        $index = $next > $index ? $next : $index + 1;
    }
    $byNote = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0 || isset($used[$id])) {
            continue;
        }
        $byNote[jeab_reminders_dedupe_note_key($row)][] = $row;
    }
    foreach ($byNote as $cluster) {
        if (count($cluster) >= 2) {
            $out[] = $cluster;
        }
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $rows
 * @param array<int, true> $orders
 * @param array<int, list<int>> $bookingLinks
 * @return list<array<string, mixed>>
 */
function jeab_reminders_dedupe_plan(array $rows, array $orders, array $bookingLinks): array
{
    $productRows = [];
    $emptyRows = [];
    foreach ($rows as $row) {
        $contact = trim((string) ($row['contact_id'] ?? ''));
        if ($contact === '' || !function_exists('jeab_inkorg_reminder_offer')) {
            continue;
        }
        $product = jeab_inkorg_reminder_offer($row);
        if ($product === '') {
            $assignee = trim((string) ($row['assignee'] ?? ''));
            $emptyRows[$contact . "\n" . $assignee][] = $row;
            continue;
        }
        $productRows[$contact . "\n" . $product][] = $row;
    }
    $plans = [];
    foreach ($productRows as $bucket) {
        $plan = jeab_reminders_dedupe_plan_group($bucket, $orders, $bookingLinks);
        if ($plan !== null) {
            $plans[] = $plan;
        }
    }
    foreach ($emptyRows as $bucket) {
        foreach (jeab_reminders_dedupe_empty_clusters($bucket) as $cluster) {
            $plan = jeab_reminders_dedupe_plan_group($cluster, $orders, $bookingLinks);
            if ($plan !== null) {
                $plans[] = $plan;
            }
        }
    }
    usort($plans, static fn (array $a, array $b): int => ((int) $a['kept']) <=> ((int) $b['kept']));

    return $plans;
}

/**
 * @param array<string, mixed> $plan
 * @return array<string, mixed>
 */
function jeab_reminders_dedupe_public_group(array $plan): array
{
    $out = [
        'ids' => array_values(array_map('intval', $plan['ids'] ?? [])),
        'kept' => (int) ($plan['kept'] ?? 0),
        'contact' => (string) ($plan['contact'] ?? ''),
        'product' => (string) ($plan['product'] ?? ''),
    ];
    $held = array_values(array_map('intval', $plan['held'] ?? []));
    if ($held !== []) {
        $out['held'] = $held;
    }

    return $out;
}

/**
 * Byter bara reminder_id. Övrig payload, inklusive personnummer, skrivs om tecken för tecken.
 */
function jeab_reminders_dedupe_rekey_order(PDO $pdo, int $from, int $to): bool
{
    if ($from <= 0 || $to <= 0 || $from === $to) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT payload FROM purchases WHERE reminder_id = ?');
    $stmt->execute([$from]);
    $payload = $stmt->fetchColumn();
    if (!is_string($payload)) {
        return false;
    }
    $taken = $pdo->prepare('SELECT 1 FROM purchases WHERE reminder_id = ?');
    $taken->execute([$to]);
    if ($taken->fetchColumn()) {
        return false;
    }
    $next = preg_replace(
        '/("reminder_id"\s*:\s*"?)' . $from . '(?!\d)/',
        '${1}' . $to,
        $payload,
        -1,
        $count
    );
    if (preg_match('/"reminder_id"\s*:/', $payload) === 1 && (!is_string($next) || $count < 1)) {
        return false;
    }
    if (!is_string($next)) {
        $next = $payload;
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    $hasList = function_exists('jeab_index_column_ok') && jeab_index_column_ok($pdo, 'purchases', 'list_json', $driver);
    if ($hasList && function_exists('jeab_sql_purchases_list_json')) {
        $decoded = json_decode($next, true);
        $listJson = is_array($decoded) ? jeab_sql_purchases_list_json($decoded) : $next;
        $updated = $pdo->prepare('UPDATE purchases SET reminder_id = ?, payload = ?, list_json = ? WHERE reminder_id = ?');
        $updated->execute([$to, $next, $listJson, $from]);
    } else {
        $updated = $pdo->prepare('UPDATE purchases SET reminder_id = ?, payload = ? WHERE reminder_id = ?');
        $updated->execute([$to, $next, $from]);
    }

    return $updated->rowCount() === 1;
}

/**
 * @param list<int> $bookingIds
 */
function jeab_reminders_dedupe_repoint_bookings(PDO $pdo, array $bookingIds, int $from, int $to): void
{
    if ($bookingIds === [] || !jeab_reminders_sql_column($pdo, 'bookings', 'reminder_id')) {
        return;
    }
    $stmt = $pdo->prepare('UPDATE bookings SET reminder_id = ? WHERE id = ? AND reminder_id = ?');
    foreach ($bookingIds as $bookingId) {
        $bookingId = (int) $bookingId;
        if ($bookingId <= 0) {
            continue;
        }
        $stmt->execute([$to, $bookingId, $from]);
        if ($stmt->rowCount() === 1) {
            continue;
        }
        $check = $pdo->prepare('SELECT reminder_id FROM bookings WHERE id = ?');
        $check->execute([$bookingId]);
        $now = (int) $check->fetchColumn();
        if ($now !== $to) {
            throw new RuntimeException('booking_link');
        }
    }
}

/**
 * @param array<string, int> $taps
 */
function jeab_reminders_dedupe_write_row(
    PDO $pdo,
    int $id,
    ?string $note,
    ?string $completedAt,
    ?int $bookingId,
    ?string $threadId,
    array $taps,
    bool $touchCompleted
): void {
    $sets = [
        'note = ?',
        'booking_id = ?',
        'thread_id = ?',
        'ringt = ?',
        'pratat = ?',
        'mailat = ?',
        'smsat = ?',
        'updated_at = ?',
    ];
    $args = [
        $note !== null && $note !== '' ? $note : null,
        $bookingId !== null && $bookingId > 0 ? $bookingId : null,
        $threadId !== null && $threadId !== '' ? $threadId : null,
        max(0, (int) ($taps['ringt'] ?? 0)),
        max(0, (int) ($taps['pratat'] ?? 0)),
        max(0, (int) ($taps['mailat'] ?? 0)),
        max(0, (int) ($taps['smsat'] ?? 0)),
        function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s'),
    ];
    if ($touchCompleted) {
        $sets[] = 'completed_at = ?';
        $args[] = $completedAt !== null && $completedAt !== '' ? $completedAt : null;
    }
    $args[] = $id;
    $pdo->prepare('UPDATE reminders SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
}

/**
 * @param array<string, mixed> $plan
 */
function jeab_reminders_dedupe_log_row(PDO $pdo, string $batch, array $plan, array $item, string $role): void
{
    $taps = is_array($item['old_taps'] ?? null) ? $item['old_taps'] : jeab_reminder_tap_counts([]);
    $bookingIds = $item['booking_rows'] ?? [];
    $bookingText = is_array($bookingIds) && $bookingIds !== []
        ? implode(',', array_map('intval', $bookingIds))
        : null;
    $completed = trim((string) ($item['old_completed_at'] ?? ''));
    $pdo->prepare(
        'INSERT INTO reminder_dedupe_log (
            batch_id, reminder_id, kept_id, role, contact_id, product,
            old_note, old_completed_at, old_booking_id, old_thread_id,
            old_ringt, old_pratat, old_mailat, old_smsat,
            order_moved, booking_ids, undone, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'
    )->execute([
        $batch,
        (int) ($item['id'] ?? $plan['kept'] ?? 0),
        (int) ($plan['kept'] ?? 0),
        $role,
        (string) ($plan['contact'] ?? ''),
        (string) ($plan['product'] ?? ''),
        (string) ($item['old_note'] ?? ''),
        $completed !== '' ? $completed : null,
        (int) ($item['old_booking_id'] ?? 0) > 0 ? (int) $item['old_booking_id'] : null,
        trim((string) ($item['old_thread_id'] ?? '')) !== '' ? trim((string) $item['old_thread_id']) : null,
        max(0, (int) ($taps['ringt'] ?? 0)),
        max(0, (int) ($taps['pratat'] ?? 0)),
        max(0, (int) ($taps['mailat'] ?? 0)),
        max(0, (int) ($taps['smsat'] ?? 0)),
        !empty($item['move_order']) ? 1 : 0,
        $bookingText,
        function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s'),
    ]);
}

/**
 * @param list<array<string, mixed>> $plans
 * @return list<int>
 */
function jeab_reminders_dedupe_apply(PDO $pdo, array $plans, string $batch): array
{
    $closedIds = [];
    $open = $pdo->prepare('SELECT completed_at FROM reminders WHERE id = ?');
    foreach ($plans as $plan) {
        $keptId = (int) ($plan['kept'] ?? 0);
        $open->execute([$keptId]);
        $keptState = $open->fetchColumn();
        if ($keptState === false || trim((string) $keptState) !== '') {
            throw new RuntimeException('stale');
        }
        $before = is_array($plan['kept_before'] ?? null) ? $plan['kept_before'] : [];
        $after = is_array($plan['kept_after'] ?? null) ? $plan['kept_after'] : [];
        $keptTaps = is_array($after['taps'] ?? null) ? $after['taps'] : [];
        jeab_reminders_dedupe_write_row(
            $pdo,
            $keptId,
            (string) ($after['note'] ?? ''),
            null,
            (int) ($after['booking_id'] ?? 0),
            (string) ($after['thread_id'] ?? ''),
            $keptTaps,
            false
        );
        jeab_reminders_dedupe_log_row($pdo, $batch, $plan, [
            'id' => $keptId,
            'old_note' => (string) ($before['note'] ?? ''),
            'old_completed_at' => '',
            'old_booking_id' => (int) ($before['booking_id'] ?? 0),
            'old_thread_id' => (string) ($before['thread_id'] ?? ''),
            'old_taps' => is_array($before['taps'] ?? null) ? $before['taps'] : [],
            'move_order' => false,
            'booking_rows' => [],
        ], 'kept');
        foreach ($plan['closed'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (int) ($item['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $open->execute([$id]);
            $dupState = $open->fetchColumn();
            if ($dupState === false || trim((string) $dupState) !== '') {
                throw new RuntimeException('stale');
            }
            if (!empty($item['move_order']) && !jeab_reminders_dedupe_rekey_order($pdo, $id, $keptId)) {
                throw new RuntimeException('order_link');
            }
            $bookingRows = is_array($item['booking_rows'] ?? null) ? $item['booking_rows'] : [];
            jeab_reminders_dedupe_repoint_bookings($pdo, $bookingRows, $id, $keptId);
            $oldTaps = is_array($item['old_taps'] ?? null) ? $item['old_taps'] : [];
            $bookingLeft = !empty($item['clear_booking']) ? 0 : (int) ($item['old_booking_id'] ?? 0);
            jeab_reminders_dedupe_write_row(
                $pdo,
                $id,
                (string) ($item['note'] ?? ''),
                function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s'),
                $bookingLeft,
                (string) ($item['old_thread_id'] ?? ''),
                $oldTaps,
                true
            );
            jeab_reminders_dedupe_log_row($pdo, $batch, $plan, $item, 'closed');
            $closedIds[] = $id;
        }
    }
    sort($closedIds);

    return $closedIds;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_reminders_dedupe_existing(JeabStore $store, array $input = []): array
{
    $dry = jeab_reminders_dedupe_wants_dry($input);
    if (!$store instanceof JeabPdoStore) {
        return ['ok' => false, 'error' => 'Databasen är inte öppen.', 'status' => 400, 'sent' => 0];
    }
    $pdo = $store->pdo();
    if (!jeab_reminders_sql_table($pdo, 'reminders')) {
        return ['ok' => false, 'error' => 'Databasen är inte öppen.', 'status' => 400, 'sent' => 0];
    }
    $plans = jeab_reminders_dedupe_plan(
        jeab_reminders_dedupe_open_rows($pdo),
        jeab_reminders_dedupe_order_ids($pdo),
        jeab_reminders_dedupe_booking_links($pdo)
    );
    $groups = array_map('jeab_reminders_dedupe_public_group', $plans);
    $batch = null;
    $closed = [];
    if (!$dry && $plans !== []) {
        if (function_exists('jeab_data_write_guard')) {
            try {
                jeab_data_write_guard();
            } catch (Throwable) {
                return ['ok' => false, 'error' => 'Underhåll pågår. Försök igen om en stund.', 'status' => 503, 'sent' => 0];
            }
        }
        try {
            jeab_reminders_dedupe_ensure_log($pdo);
            $batch = bin2hex(random_bytes(16));
            $closed = jeab_pdo_transaction($pdo, static function (PDO $pdo) use ($plans, $batch): array {
                return jeab_reminders_dedupe_apply($pdo, $plans, $batch);
            });
        } catch (Throwable) {
            if (function_exists('jeab_purchase_cache_forget')) {
                jeab_purchase_cache_forget();
            }

            return ['ok' => false, 'error' => 'Sammanslagningen gick inte att spara.', 'status' => 500, 'sent' => 0];
        }
        if (function_exists('jeab_purchase_cache_forget')) {
            jeab_purchase_cache_forget();
        }
    }

    return [
        'ok' => true,
        'action' => 'reminders.dedupe_existing',
        'dry' => $dry ? 1 : 0,
        'read_only' => $dry,
        'sent' => 0,
        'batch' => $closed === [] ? null : $batch,
        'groups' => $groups,
        'count' => count($groups),
        'closed' => $closed,
        'status' => 200,
    ];
}

/**
 * @return list<int>
 */
function jeab_reminders_dedupe_booking_id_list(?string $raw): array
{
    $ids = [];
    foreach (preg_split('/\s*,\s*/', trim((string) $raw)) ?: [] as $part) {
        $id = (int) $part;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    $ids = array_values(array_unique($ids));
    sort($ids);

    return $ids;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_reminders_dedupe_undo(JeabStore $store, array $input = []): array
{
    $batch = trim((string) ($input['batch'] ?? $input['batch_id'] ?? ''));
    if (preg_match('/\A[a-f0-9]{16,64}\z/', $batch) !== 1) {
        return ['ok' => false, 'error' => 'batch krävs.', 'status' => 422, 'sent' => 0];
    }
    $dry = jeab_reminders_dedupe_wants_dry($input);
    if (!$store instanceof JeabPdoStore) {
        return ['ok' => false, 'error' => 'Databasen är inte öppen.', 'status' => 400, 'sent' => 0];
    }
    $pdo = $store->pdo();
    $empty = [
        'ok' => true,
        'action' => 'reminders.dedupe_undo',
        'dry' => $dry ? 1 : 0,
        'read_only' => $dry,
        'sent' => 0,
        'batch' => $batch,
        'restored' => [],
        'order_missing' => [],
        'status' => 200,
    ];
    if (!jeab_reminders_sql_table($pdo, 'reminder_dedupe_log')) {
        return $empty;
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM reminder_dedupe_log WHERE batch_id = ? AND undone = 0 ORDER BY id ASC'
    );
    $stmt->execute([$batch]);
    $logs = [];
    foreach ($stmt->fetchAll() as $row) {
        if (is_array($row)) {
            $logs[] = $row;
        }
    }
    if ($logs === []) {
        return $empty;
    }
    $restored = [];
    foreach ($logs as $row) {
        $id = (int) ($row['reminder_id'] ?? 0);
        if ($id > 0) {
            $restored[$id] = $id;
        }
    }
    $restored = array_values($restored);
    sort($restored);
    if ($dry) {
        $empty['restored'] = $restored;

        return $empty;
    }
    if (function_exists('jeab_data_write_guard')) {
        try {
            jeab_data_write_guard();
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'Underhåll pågår. Försök igen om en stund.', 'status' => 503, 'sent' => 0];
        }
    }
    $missing = [];
    try {
        $missing = jeab_pdo_transaction($pdo, static function (PDO $pdo) use ($logs, $batch): array {
            $gone = [];
            foreach ($logs as $row) {
                $id = (int) ($row['reminder_id'] ?? 0);
                $keptId = (int) ($row['kept_id'] ?? 0);
                $role = (string) ($row['role'] ?? '');
                if ($id <= 0) {
                    continue;
                }
                $taps = [
                    'ringt' => (int) ($row['old_ringt'] ?? 0),
                    'pratat' => (int) ($row['old_pratat'] ?? 0),
                    'mailat' => (int) ($row['old_mailat'] ?? 0),
                    'smsat' => (int) ($row['old_smsat'] ?? 0),
                ];
                $completed = $row['old_completed_at'] ?? null;
                $completed = is_string($completed) ? trim($completed) : '';
                jeab_reminders_dedupe_write_row(
                    $pdo,
                    $id,
                    (string) ($row['old_note'] ?? ''),
                    $role === 'closed' ? $completed : null,
                    (int) ($row['old_booking_id'] ?? 0),
                    trim((string) ($row['old_thread_id'] ?? '')),
                    $taps,
                    $role === 'closed'
                );
                if ((int) ($row['order_moved'] ?? 0) === 1 && $keptId > 0 && $keptId !== $id) {
                    $has = $pdo->prepare('SELECT 1 FROM purchases WHERE reminder_id = ?');
                    $has->execute([$keptId]);
                    $onKept = (bool) $has->fetchColumn();
                    $has->execute([$id]);
                    $onOwn = (bool) $has->fetchColumn();
                    if ($onKept && $onOwn) {
                        throw new RuntimeException('order_link');
                    }
                    if ($onKept && !$onOwn && !jeab_reminders_dedupe_rekey_order($pdo, $keptId, $id)) {
                        throw new RuntimeException('order_link');
                    }
                    if (!$onKept && !$onOwn) {
                        $gone[] = $id;
                    }
                }
                $bookingIds = jeab_reminders_dedupe_booking_id_list((string) ($row['booking_ids'] ?? ''));
                if ($bookingIds !== [] && $keptId > 0) {
                    jeab_reminders_dedupe_repoint_bookings($pdo, $bookingIds, $keptId, $id);
                }
            }
            $pdo->prepare('UPDATE reminder_dedupe_log SET undone = 1 WHERE batch_id = ? AND undone = 0')->execute([$batch]);

            return $gone;
        });
    } catch (Throwable) {
        if (function_exists('jeab_purchase_cache_forget')) {
            jeab_purchase_cache_forget();
        }

        return ['ok' => false, 'error' => 'Ångringen gick inte att spara.', 'status' => 500, 'sent' => 0];
    }
    if (function_exists('jeab_purchase_cache_forget')) {
        jeab_purchase_cache_forget();
    }
    sort($missing);

    return [
        'ok' => true,
        'action' => 'reminders.dedupe_undo',
        'dry' => 0,
        'read_only' => false,
        'sent' => 0,
        'batch' => $batch,
        'restored' => $restored,
        'order_missing' => $missing,
        'status' => 200,
    ];
}

/**
 * @param mixed $raw
 * @return list<int>
 */
function jeab_reminders_dedupe_id_list(mixed $raw): array
{
    $parts = [];
    if (is_array($raw)) {
        $parts = $raw;
    } elseif (is_string($raw) || is_int($raw)) {
        $parts = preg_split('/\s*,\s*/', trim((string) $raw)) ?: [];
    }
    $ids = [];
    foreach ($parts as $part) {
        if (is_array($part)) {
            continue;
        }
        $id = (int) $part;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_reminders_get(JeabStore $store, array $input = []): array
{
    $ids = jeab_reminders_dedupe_id_list($input['ids'] ?? $input['id'] ?? null);
    if ($ids === []) {
        return ['ok' => false, 'error' => 'id krävs.', 'status' => 422, 'sent' => 0, 'reminders' => []];
    }
    if (count($ids) > 100) {
        return ['ok' => false, 'error' => 'för många id.', 'status' => 422, 'sent' => 0, 'reminders' => []];
    }
    $found = [];
    if ($store instanceof JeabPdoStore) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $store->pdo()->prepare('SELECT * FROM reminders WHERE id IN (' . $marks . ')');
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $hydrated = jeab_hydrate_reminder($row);
            $found[(int) ($hydrated['id'] ?? 0)] = $hydrated;
        }
    } else {
        foreach ($ids as $id) {
            $row = $store->getReminder($id);
            if (is_array($row)) {
                $found[(int) ($row['id'] ?? 0)] = $row;
            }
        }
    }
    $reminders = [];
    $missing = [];
    foreach ($ids as $id) {
        $row = $found[$id] ?? null;
        if (!is_array($row)) {
            $missing[] = $id;
            continue;
        }
        $status = 'open';
        if (jeab_reminder_is_trashed($row)) {
            $status = 'trashed';
        } elseif (trim((string) ($row['completed_at'] ?? '')) !== '') {
            $status = 'done';
        }
        $reminders[] = [
            'id' => $id,
            'created' => (string) ($row['created_at'] ?? ''),
            'product' => function_exists('jeab_inkorg_reminder_offer') ? jeab_inkorg_reminder_offer($row) : '',
            'status' => $status,
            'contact_id' => trim((string) ($row['contact_id'] ?? '')),
            'assignee' => trim((string) ($row['assignee'] ?? '')),
        ];
    }

    return [
        'ok' => true,
        'action' => 'reminders.get',
        'dry' => 1,
        'read_only' => true,
        'sent' => 0,
        'reminders' => $reminders,
        'missing' => $missing,
        'status' => 200,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_reminders_dedupe_dispatch(JeabStore $store, array $input = []): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $action = (string) ($input['action'] ?? '');
    if ($action === 'reminders.get') {
        $result = jeab_reminders_get($store, $input);
    } elseif ($action === 'reminders.dedupe_undo') {
        $result = jeab_reminders_dedupe_undo($store, $input);
    } elseif ($action === 'reminders.dedupe_existing') {
        $result = jeab_reminders_dedupe_existing($store, $input);
    } else {
        $result = ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    }
    $result['status'] = (int) ($result['status'] ?? (!empty($result['ok']) ? 200 : 400));

    return $result;
}
