<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Offertkön som SQL-utbox. En rad per tråd och produkt.
 * Raden lämnar kön först när utskicket är bekräftat eller ett slutligt hopp är loggat.
 * JSON-filen är kvar som återgång när backend tvingas till json.
 */

function jeab_quote_queue_backend(): string
{
    $force = (string) ($GLOBALS['_JEAB_QUOTE_QUEUE_FORCE'] ?? '');
    if ($force === 'json' || $force === 'sql') {
        return $force;
    }
    if (function_exists('jeab_cutover_forces_json') && jeab_cutover_forces_json('quote_queue')) {
        return 'json';
    }
    if (function_exists('jeab_cutover_sql') && jeab_cutover_sql('quote_queue')) {
        return 'sql';
    }
    if (function_exists('jeab_data_pdo') && jeab_data_pdo() instanceof PDO) {
        return 'sql';
    }

    return 'json';
}

function jeab_quote_outbox_pdo(): ?PDO
{
    if (!function_exists('jeab_data_pdo')) {
        return null;
    }
    $pdo = jeab_data_pdo();
    if (!$pdo instanceof PDO) {
        return null;
    }
    if (function_exists('jeab_ensure_quote_outbox')) {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        try {
            jeab_ensure_quote_outbox($pdo, $driver);
        } catch (Throwable) {
            return null;
        }
    }

    return $pdo;
}

function jeab_quote_outbox_driver(PDO $pdo): string
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
}

function jeab_quote_outbox_now(): string
{
    return function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
}

/** Tar bort adresser och telefonliknande tal. Tråd-id hör hemma i egen kolumn. */
function jeab_quote_outbox_reason(string $reason): string
{
    $reason = preg_replace('/[^\s@]+@[^\s@]+\.[^\s@]+/u', '', $reason) ?? '';
    $reason = preg_replace('/\+?\d[\d\s().-]{6,}\d/u', '', $reason) ?? '';
    $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
    if (function_exists('mb_substr')) {
        return mb_substr($reason, 0, 160);
    }

    return substr($reason, 0, 160);
}

function jeab_quote_outbox_idem(string $tid, string $product, string $given = ''): string
{
    $tid = trim($tid);
    $product = trim($product);
    $given = trim($given);
    if ($given !== '' && !str_contains($given, '@') && str_starts_with($given, $tid . '|')) {
        return function_exists('mb_substr') ? mb_substr($given, 0, 255) : substr($given, 0, 255);
    }
    $key = $tid . '|' . $product;

    return function_exists('mb_substr') ? mb_substr($key, 0, 255) : substr($key, 0, 255);
}

/**
 * Väntande rader, en per tråd. Skickade och hoppade rader syns inte.
 *
 * @return array<string, array<string, mixed>>
 */
function jeab_quote_outbox_load_map(): array
{
    $pdo = jeab_quote_outbox_pdo();
    if (!$pdo instanceof PDO) {
        return [];
    }
    try {
        $stmt = $pdo->query(
            "SELECT idem_key, thread_id, product, status, attempts, next_attempt_at, queued_at, manual_send
             FROM quote_outbox
             WHERE status IN ('pending', 'retry', 'sending')"
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable) {
        return [];
    }
    $rank = ['pending' => 0, 'retry' => 1, 'sending' => 2];
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $tid = trim((string) ($row['thread_id'] ?? ''));
        if ($tid === '' || str_contains($tid, '@')) {
            continue;
        }
        $status = (string) ($row['status'] ?? 'pending');
        $candidate = [
            'at' => (int) ($row['queued_at'] ?? 0),
            'product' => (string) ($row['product'] ?? ''),
            'idem' => (string) ($row['idem_key'] ?? ''),
            'attempts' => (int) ($row['attempts'] ?? 0),
            'next_attempt_at' => (int) ($row['next_attempt_at'] ?? 0),
            'status' => $status,
            'manual_send' => (int) ($row['manual_send'] ?? 0) === 1 ? 1 : 0,
        ];
        if (!isset($out[$tid])) {
            $out[$tid] = $candidate;
            continue;
        }
        $have = (string) ($out[$tid]['status'] ?? 'pending');
        $better = ($rank[$status] ?? 9) < ($rank[$have] ?? 9);
        $newer = ($rank[$status] ?? 9) === ($rank[$have] ?? 9) && $candidate['at'] >= (int) ($out[$tid]['at'] ?? 0);
        if ($better || $newer) {
            $out[$tid] = $candidate;
        }
    }

    return $out;
}

/**
 * @return array<string, true>
 */
function jeab_quote_outbox_pending_keys(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT idem_key FROM quote_outbox WHERE status IN ('pending', 'retry', 'sending')");
    $keys = [];
    foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = (string) ($row['idem_key'] ?? '');
        if ($key !== '') {
            $keys[$key] = true;
        }
    }

    return $keys;
}

function jeab_quote_outbox_lock_acquire(PDO $pdo): bool
{
    if (jeab_quote_outbox_driver($pdo) === 'mysql') {
        try {
            $stmt = $pdo->query("SELECT GET_LOCK('jeab_quote_outbox', 5)");
            $got = $stmt ? (int) $stmt->fetchColumn() : 0;
        } catch (Throwable) {
            return false;
        }
        if ($got !== 1) {
            return false;
        }
        $GLOBALS['_JEAB_QUOTE_OUTBOX_LOCK_MYSQL'] = true;

        return true;
    }
    $path = jeab_data_dir() . '/quote-outbox.lock';
    $fh = @fopen($path, 'c');
    if ($fh === false) {
        return false;
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);

        return false;
    }
    $GLOBALS['_JEAB_QUOTE_OUTBOX_LOCK_FH'] = $fh;

    return true;
}

function jeab_quote_outbox_lock_release(PDO $pdo): void
{
    if (!empty($GLOBALS['_JEAB_QUOTE_OUTBOX_LOCK_MYSQL'])) {
        try {
            $pdo->query("SELECT RELEASE_LOCK('jeab_quote_outbox')");
        } catch (Throwable) {
        }
        unset($GLOBALS['_JEAB_QUOTE_OUTBOX_LOCK_MYSQL']);
    }
    $fh = $GLOBALS['_JEAB_QUOTE_OUTBOX_LOCK_FH'] ?? null;
    if (is_resource($fh)) {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
    unset($GLOBALS['_JEAB_QUOTE_OUTBOX_LOCK_FH']);
}

/**
 * Ett lås och en transaktion. Återinträde delar den yttersta.
 *
 * @template T
 * @param callable(PDO): T $fn
 * @return T
 */
function jeab_quote_outbox_locked(PDO $pdo, callable $fn): mixed
{
    $depth = (int) ($GLOBALS['_JEAB_QUOTE_OUTBOX_LOCK_DEPTH'] ?? 0);
    $release = false;
    if ($depth === 0) {
        $release = jeab_quote_outbox_lock_acquire($pdo);
        if (!$release) {
            throw new RuntimeException('offertkön kunde inte låsas');
        }
    }
    $GLOBALS['_JEAB_QUOTE_OUTBOX_LOCK_DEPTH'] = $depth + 1;
    try {
        if ($depth > 0) {
            return $fn($pdo);
        }

        return jeab_pdo_transaction($pdo, static function (PDO $pdo) use ($fn): mixed {
            return $fn($pdo);
        });
    } finally {
        $left = (int) ($GLOBALS['_JEAB_QUOTE_OUTBOX_LOCK_DEPTH'] ?? 1) - 1;
        if ($left <= 0) {
            unset($GLOBALS['_JEAB_QUOTE_OUTBOX_LOCK_DEPTH']);
            if ($release) {
                jeab_quote_outbox_lock_release($pdo);
            }
        } else {
            $GLOBALS['_JEAB_QUOTE_OUTBOX_LOCK_DEPTH'] = $left;
        }
    }
}

/**
 * Ersätter väntande rader med ögonblicksbilden.
 * Skickade och hoppade rader rörs inte.
 * Bara nycklar som fanns i före-bilden och som saknas i bilden tas bort,
 * så en rad som kommer till efter före-bilden får stå.
 * Vakten kastas innan minnet uppdateras.
 *
 * @param array<string, mixed> $all
 */
function jeab_quote_outbox_replace_pending(array $all): void
{
    if (function_exists('jeab_data_write_guard')) {
        jeab_data_write_guard();
    }
    $pdo = jeab_quote_outbox_pdo();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('offertkön saknar SQL');
    }
    $driver = jeab_quote_outbox_driver($pdo);
    $now = jeab_quote_outbox_now();
    $rows = [];
    foreach ($all as $tid => $row) {
        if (!is_string($tid) && !is_int($tid)) {
            continue;
        }
        $thread = trim((string) $tid);
        if ($thread === '' || str_contains($thread, '@')) {
            continue;
        }
        $item = is_array($row) ? $row : [];
        $status = (string) ($item['status'] ?? '');
        if ($status === 'sent' || $status === 'skipped' || $status === 'waiting') {
            continue;
        }
        if (!in_array($status, ['pending', 'retry', 'sending'], true)) {
            $status = 'pending';
        }
        $product = trim((string) ($item['product'] ?? ''));
        if (function_exists('mb_substr')) {
            $product = mb_substr($product, 0, 32);
        }
        $idem = jeab_quote_outbox_idem($thread, $product, (string) ($item['idem'] ?? ''));
        $rows[$idem] = [
            'idem' => $idem,
            'thread' => function_exists('mb_substr') ? mb_substr($thread, 0, 191) : substr($thread, 0, 191),
            'product' => $product,
            'at' => (int) ($item['at'] ?? time()),
            'attempts' => max(0, (int) ($item['attempts'] ?? 0)),
            'next' => max(0, (int) ($item['next_attempt_at'] ?? 0)),
            'status' => $status,
            'manual' => (int) ($item['manual_send'] ?? 0) === 1 ? 1 : 0,
        ];
    }
    $upsert = $driver === 'mysql'
        ? 'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at, manual_send)
           VALUES (?, ?, ?, ?, ?, ?, \'\', ?, ?, ?)
           ON DUPLICATE KEY UPDATE
             thread_id = IF(status IN (\'sent\', \'skipped\', \'waiting\'), thread_id, VALUES(thread_id)),
             product = IF(status IN (\'sent\', \'skipped\', \'waiting\'), product, VALUES(product)),
             attempts = IF(status IN (\'sent\', \'skipped\', \'waiting\'), attempts, VALUES(attempts)),
             next_attempt_at = IF(status IN (\'sent\', \'skipped\', \'waiting\'), next_attempt_at, VALUES(next_attempt_at)),
             reason = IF(status IN (\'sent\', \'skipped\', \'waiting\'), reason, \'\'),
             queued_at = IF(status IN (\'sent\', \'skipped\', \'waiting\'), queued_at, VALUES(queued_at)),
             updated_at = IF(status IN (\'sent\', \'skipped\', \'waiting\'), updated_at, VALUES(updated_at)),
             manual_send = IF(status IN (\'sent\', \'skipped\', \'waiting\'), manual_send, VALUES(manual_send)),
             status = IF(status IN (\'sent\', \'skipped\', \'waiting\'), status, VALUES(status))'
        : 'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at, manual_send)
           VALUES (?, ?, ?, ?, ?, ?, \'\', ?, ?, ?)
           ON CONFLICT(idem_key) DO UPDATE SET thread_id = excluded.thread_id, product = excluded.product, status = excluded.status,
             attempts = excluded.attempts, next_attempt_at = excluded.next_attempt_at, reason = \'\', queued_at = excluded.queued_at, updated_at = excluded.updated_at,
             manual_send = excluded.manual_send
           WHERE quote_outbox.status IN (\'pending\', \'retry\', \'sending\')';
    jeab_quote_outbox_locked($pdo, static function (PDO $pdo) use ($rows, $upsert, $now): void {
        $stored = $GLOBALS['_JEAB_QUOTE_OUTBOX_BEFORE'] ?? null;
        $before = is_array($stored) ? $stored : jeab_quote_outbox_pending_keys($pdo);
        $mid = $GLOBALS['_JEAB_QUOTE_OUTBOX_AFTER_BEFORE'] ?? null;
        if (is_callable($mid)) {
            $mid($pdo);
        }
        $stmt = $pdo->prepare($upsert);
        foreach ($rows as $row) {
            $stmt->execute([
                $row['idem'],
                $row['thread'],
                $row['product'],
                $row['status'],
                $row['attempts'],
                $row['next'],
                $row['at'] > 0 ? $row['at'] : time(),
                $now,
                $row['manual'],
            ]);
        }
        $drop = [];
        foreach ($before as $key => $_own) {
            $key = (string) $key;
            if ($key !== '' && !isset($rows[$key])) {
                $drop[] = $key;
            }
        }
        foreach (array_chunk($drop, 200) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $del = $pdo->prepare(
                "DELETE FROM quote_outbox WHERE status IN ('pending', 'retry', 'sending') AND idem_key IN ({$marks})"
            );
            $del->execute($chunk);
        }
    });
    unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
    $GLOBALS['_JEAB_QUOTE_QUEUE_MEM'] = jeab_quote_outbox_load_map();
}

/**
 * Rader som får tömmas nu.
 *
 * @return list<array{thread_id:string,product:string,idem:string,attempts:int,at:int,manual_send:int}>
 */
function jeab_quote_outbox_due(int $limit): array
{
    $limit = max(1, $limit);
    $now = time();
    if (jeab_quote_queue_backend() === 'sql') {
        $pdo = jeab_quote_outbox_pdo();
        if (!$pdo instanceof PDO) {
            return [];
        }
        try {
            $stmt = $pdo->prepare(
                "SELECT idem_key, thread_id, product, attempts, queued_at, manual_send
                 FROM quote_outbox
                 WHERE status IN ('pending', 'retry', 'sending')
                   AND (next_attempt_at = 0 OR next_attempt_at <= ?)
                 ORDER BY queued_at ASC
                 LIMIT " . $limit
            );
            $stmt->execute([$now]);
            $fetched = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
        $out = [];
        foreach ($fetched as $row) {
            if (!is_array($row)) {
                continue;
            }
            $tid = trim((string) ($row['thread_id'] ?? ''));
            if ($tid === '') {
                continue;
            }
            $out[] = [
                'thread_id' => $tid,
                'product' => (string) ($row['product'] ?? ''),
                'idem' => (string) ($row['idem_key'] ?? ''),
                'attempts' => (int) ($row['attempts'] ?? 0),
                'at' => (int) ($row['queued_at'] ?? 0),
                'manual_send' => (int) ($row['manual_send'] ?? 0) === 1 ? 1 : 0,
            ];
        }

        return $out;
    }
    unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
    $all = function_exists('jeab_inbox_quote_queue_load') ? jeab_inbox_quote_queue_load() : [];
    $out = [];
    foreach ($all as $tid => $row) {
        if (!is_string($tid) && !is_int($tid)) {
            continue;
        }
        $item = is_array($row) ? $row : [];
        $next = (int) ($item['next_attempt_at'] ?? 0);
        if ($next > $now) {
            continue;
        }
        $status = (string) ($item['status'] ?? 'pending');
        if (!in_array($status, ['pending', 'retry', 'sending', ''], true)) {
            continue;
        }
        $thread = (string) $tid;
        $product = (string) ($item['product'] ?? '');
        $out[] = [
            'thread_id' => $thread,
            'product' => $product,
            'idem' => jeab_quote_outbox_idem($thread, $product, (string) ($item['idem'] ?? '')),
            'attempts' => (int) ($item['attempts'] ?? 0),
            'at' => (int) ($item['at'] ?? 0),
            'manual_send' => (int) ($item['manual_send'] ?? 0) === 1 ? 1 : 0,
        ];
        if (count($out) >= $limit) {
            break;
        }
    }

    return $out;
}


/** Avbruten eller släppt väntan ska kollas mot already_out innan ett nytt mejl. */
function jeab_quote_outbox_claim_seen(string $status, string $reason): string
{
    if ($status === 'retry' && in_array($reason, ['aborted', 'send_guard_dropped'], true)) {
        return 'sending';
    }

    return $status;
}

/**
 * Tar leaset med en villkorad UPDATE.
 * Träffar pending eller retry där next_attempt_at redan är inne,
 * och ett utgånget sending-lease (next_attempt_at <= nu).
 * Raden blir sending, attempts ökar med ett, och next_attempt_at blir nu+600.
 * En rad som redan har åtta försök hoppas med max_attempts och skickas inte.
 * Bara anropet som fick raden får skicka.
 */
function jeab_quote_outbox_claim(string $idem): bool
{
    $idem = trim($idem);
    $GLOBALS['_JEAB_QUOTE_OUTBOX_CLAIM_PREV'] = '';
    if ($idem === '') {
        return false;
    }
    $now = time();
    $until = $now + 600;
    if (jeab_quote_queue_backend() === 'sql') {
        $pdo = jeab_quote_outbox_pdo();
        if (!$pdo instanceof PDO) {
            return false;
        }
        $prev = '';
        try {
            $claimed = jeab_quote_outbox_locked($pdo, static function (PDO $pdo) use ($idem, $now, $until, &$prev): bool {
                $look = $pdo->prepare('SELECT status, reason FROM quote_outbox WHERE idem_key = ?');
                $look->execute([$idem]);
                $got = $look->fetch(PDO::FETCH_ASSOC);
                $prev = is_array($got) ? (string) ($got['status'] ?? '') : '';
                // En avbruten rad kan redan ha gått ut. reclaim_done kollar already_out före nytt mejl.
                $prev = jeab_quote_outbox_claim_seen($prev, is_array($got) ? (string) ($got['reason'] ?? '') : '');
                $stmt = $pdo->prepare(
                    "UPDATE quote_outbox
                     SET status = 'sending', attempts = attempts + 1, next_attempt_at = ?, updated_at = ?
                     WHERE idem_key = ?
                       AND next_attempt_at <= ?
                       AND attempts < 8
                       AND status IN ('pending', 'retry', 'sending')"
                );
                $stmt->execute([$until, jeab_quote_outbox_now(), $idem, $now]);
                if ($stmt->rowCount() > 0) {
                    return true;
                }
                jeab_quote_outbox_skip_max_attempts($pdo, $idem, $now);

                return false;
            });
        } catch (Throwable) {
            $GLOBALS['_JEAB_QUOTE_OUTBOX_CLAIM_PREV'] = $prev;

            return false;
        }
        $GLOBALS['_JEAB_QUOTE_OUTBOX_CLAIM_PREV'] = $prev;
        unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);

        return $claimed;
    }
    if (!function_exists('jeab_inbox_quote_queue_load') || !function_exists('jeab_inbox_quote_queue_save')) {
        return false;
    }
    unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
    $all = jeab_inbox_quote_queue_load();
    $hit = false;
    $skipped = null;
    foreach ($all as $tid => $row) {
        if (!is_array($row)) {
            continue;
        }
        $rowIdem = jeab_quote_outbox_idem((string) $tid, (string) ($row['product'] ?? ''), (string) ($row['idem'] ?? ''));
        if ($rowIdem !== $idem) {
            continue;
        }
        $status = (string) ($row['status'] ?? 'pending');
        if ($status === '') {
            $status = 'pending';
        }
        $next = (int) ($row['next_attempt_at'] ?? 0);
        // Samma som SQL: avbruten rad kollar already_out före nytt mejl.
        $GLOBALS['_JEAB_QUOTE_OUTBOX_CLAIM_PREV'] = jeab_quote_outbox_claim_seen($status, (string) ($row['reason'] ?? ''));
        if (!in_array($status, ['pending', 'retry', 'sending'], true) || $next > $now) {
            return false;
        }
        $attempts = (int) ($row['attempts'] ?? 0);
        if ($attempts >= 8) {
            unset($all[$tid]);
            $skipped = [
                'thread' => (string) $tid,
                'product' => (string) ($row['product'] ?? ''),
                'attempts' => $attempts,
            ];
            break;
        }
        $row['status'] = 'sending';
        $row['next_attempt_at'] = $until;
        $row['attempts'] = $attempts + 1;
        $row['idem'] = $rowIdem;
        $all[$tid] = $row;
        $hit = true;
        break;
    }
    if ($skipped !== null) {
        jeab_inbox_quote_queue_save($all);
        unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
        jeab_quote_outbox_log($skipped['thread'], $skipped['product'], 'skipped', 'max_attempts', $skipped['attempts']);

        return false;
    }
    if (!$hit) {
        return false;
    }
    jeab_inbox_quote_queue_save($all);
    unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);

    return true;
}

/** Status före senaste claim. Tom när claim inte träffade en rad. */
function jeab_quote_outbox_claim_prev(): string
{
    return (string) ($GLOBALS['_JEAB_QUOTE_OUTBOX_CLAIM_PREV'] ?? '');
}

/**
 * Idem som den här processen har claimat och inte avslutat.
 * Shutdown markerar dem retry med aborted, utan att räkna ett försök till.
 */
function jeab_quote_outbox_inflight_arm(): void
{
    if (!empty($GLOBALS['_JEAB_QUOTE_OUTBOX_INFLIGHT_ARMED'])) {
        return;
    }
    $GLOBALS['_JEAB_QUOTE_OUTBOX_INFLIGHT_ARMED'] = true;
    register_shutdown_function(static function (): void {
        jeab_quote_outbox_inflight_abort();
    });
}

function jeab_quote_outbox_inflight_add(string $idem, string $tid, string $product): void
{
    $idem = trim($idem);
    if ($idem === '') {
        return;
    }
    jeab_quote_outbox_inflight_arm();
    if (!isset($GLOBALS['_JEAB_QUOTE_OUTBOX_INFLIGHT']) || !is_array($GLOBALS['_JEAB_QUOTE_OUTBOX_INFLIGHT'])) {
        $GLOBALS['_JEAB_QUOTE_OUTBOX_INFLIGHT'] = [];
    }
    $GLOBALS['_JEAB_QUOTE_OUTBOX_INFLIGHT'][$idem] = [
        'thread_id' => $tid,
        'product' => $product,
    ];
}

function jeab_quote_outbox_inflight_clear(string $idem): void
{
    $idem = trim($idem);
    if ($idem === '' || !isset($GLOBALS['_JEAB_QUOTE_OUTBOX_INFLIGHT']) || !is_array($GLOBALS['_JEAB_QUOTE_OUTBOX_INFLIGHT'])) {
        return;
    }
    unset($GLOBALS['_JEAB_QUOTE_OUTBOX_INFLIGHT'][$idem]);
}

/** Claimad rad som fortfarande är sending blir retry med kort backoff. Försöken rörs inte. */
function jeab_quote_outbox_inflight_abort(): void
{
    $open = $GLOBALS['_JEAB_QUOTE_OUTBOX_INFLIGHT'] ?? [];
    $GLOBALS['_JEAB_QUOTE_OUTBOX_INFLIGHT'] = [];
    if (!is_array($open) || $open === []) {
        return;
    }
    foreach ($open as $idem => $meta) {
        $idem = trim((string) $idem);
        if ($idem === '' || !is_array($meta)) {
            continue;
        }
        try {
            $row = jeab_quote_outbox_idem_row($idem);
            if (!is_array($row) || (string) ($row['status'] ?? '') !== 'sending') {
                continue;
            }
            $attempts = (int) ($row['attempts'] ?? 0);
            // Med 0 tar den avbrutna raden första platsen i varje drain och raderna bakom väljs aldrig.
            $next = time() + min(900, 30 * max(1, $attempts));
            jeab_quote_outbox_mark($idem, 'retry', $attempts, $next, 'aborted');
            jeab_quote_outbox_log(
                (string) ($meta['thread_id'] ?? ''),
                (string) ($meta['product'] ?? $row['product'] ?? ''),
                'retry',
                'aborted',
                $attempts
            );
        } catch (Throwable) {
        }
    }
}

/** Försök 8 är redan gjort. Raden hoppas och loggas, den skickas inte. */
function jeab_quote_outbox_skip_max_attempts(PDO $pdo, string $idem, int $now): void
{
    $skip = $pdo->prepare(
        "UPDATE quote_outbox
         SET status = 'skipped', reason = 'max_attempts', next_attempt_at = 0, updated_at = ?
         WHERE idem_key = ?
           AND next_attempt_at <= ?
           AND attempts >= 8
           AND status IN ('pending', 'retry', 'sending')"
    );
    $skip->execute([jeab_quote_outbox_now(), $idem, $now]);
    if ($skip->rowCount() < 1) {
        return;
    }
    $info = $pdo->prepare('SELECT thread_id, product, attempts FROM quote_outbox WHERE idem_key = ?');
    $info->execute([$idem]);
    $got = $info->fetch(PDO::FETCH_ASSOC);
    if (!is_array($got)) {
        return;
    }
    jeab_quote_outbox_log(
        (string) ($got['thread_id'] ?? ''),
        (string) ($got['product'] ?? ''),
        'skipped',
        'max_attempts',
        (int) ($got['attempts'] ?? 0)
    );
}

function jeab_quote_outbox_log(string $tid, string $product, string $status, string $reason, int $attempts): void
{
    $entry = [
        'thread_id' => $tid,
        'product' => $product,
        'status' => $status,
        'reason' => jeab_quote_outbox_reason($reason),
        'attempts' => $attempts,
    ];
    $GLOBALS['_JEAB_QUOTE_OUTBOX_LOG'][] = $entry;
    $pdo = jeab_quote_outbox_pdo();
    if (!$pdo instanceof PDO) {
        return;
    }
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO quote_outbox_event (created_at, thread_id, product, status, reason, attempts) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            jeab_quote_outbox_now(),
            function_exists('mb_substr') ? mb_substr($tid, 0, 191) : substr($tid, 0, 191),
            function_exists('mb_substr') ? mb_substr($product, 0, 32) : substr($product, 0, 32),
            function_exists('mb_substr') ? mb_substr($status, 0, 16) : substr($status, 0, 16),
            $entry['reason'],
            $attempts,
        ]);
    } catch (Throwable) {
    }
}

/** @return list<array{status:string,reason:string,attempts:int,product:string}> */
function jeab_quote_outbox_events(string $tid): array
{
    $pdo = jeab_quote_outbox_pdo();
    $out = [];
    if ($pdo instanceof PDO && $tid !== '') {
        try {
            $stmt = $pdo->prepare(
                'SELECT product, status, reason, attempts FROM quote_outbox_event WHERE thread_id = ? ORDER BY id ASC'
            );
            $stmt->execute([$tid]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $out[] = [
                    'product' => (string) ($row['product'] ?? ''),
                    'status' => (string) ($row['status'] ?? ''),
                    'reason' => (string) ($row['reason'] ?? ''),
                    'attempts' => (int) ($row['attempts'] ?? 0),
                ];
            }
        } catch (Throwable) {
            $out = [];
        }
    }
    if ($out !== []) {
        return $out;
    }
    foreach ((array) ($GLOBALS['_JEAB_QUOTE_OUTBOX_LOG'] ?? []) as $row) {
        if (!is_array($row) || (string) ($row['thread_id'] ?? '') !== $tid) {
            continue;
        }
        $out[] = [
            'product' => (string) ($row['product'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'reason' => (string) ($row['reason'] ?? ''),
            'attempts' => (int) ($row['attempts'] ?? 0),
        ];
    }

    return $out;
}

function jeab_quote_outbox_attempts(string $idem): int
{
    if ($idem === '') {
        return 0;
    }
    if (jeab_quote_queue_backend() !== 'sql') {
        $map = function_exists('jeab_inbox_quote_queue_load') ? jeab_inbox_quote_queue_load() : [];
        foreach ($map as $row) {
            if (is_array($row) && (string) ($row['idem'] ?? '') === $idem) {
                return (int) ($row['attempts'] ?? 0);
            }
        }

        return 0;
    }
    $pdo = jeab_quote_outbox_pdo();
    if (!$pdo instanceof PDO) {
        return 0;
    }
    try {
        $stmt = $pdo->prepare('SELECT attempts FROM quote_outbox WHERE idem_key = ?');
        $stmt->execute([$idem]);

        return (int) $stmt->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function jeab_quote_outbox_state(string $tid, string $product = ''): string
{
    $tid = trim($tid);
    $product = trim($product);
    if ($tid === '') {
        return 'absent';
    }
    if (jeab_quote_queue_backend() === 'sql') {
        $pdo = jeab_quote_outbox_pdo();
        if (!$pdo instanceof PDO) {
            return 'absent';
        }
        try {
            if ($product !== '') {
                $stmt = $pdo->prepare('SELECT status FROM quote_outbox WHERE idem_key = ?');
                $stmt->execute([jeab_quote_outbox_idem($tid, $product)]);
                $status = $stmt->fetchColumn();
                if (is_string($status) && $status !== '') {
                    return $status;
                }
            }
            $stmt = $pdo->prepare(
                "SELECT status FROM quote_outbox WHERE thread_id = ? ORDER BY CASE status
                    WHEN 'waiting' THEN 0 WHEN 'sending' THEN 0 WHEN 'pending' THEN 1 WHEN 'retry' THEN 2
                    WHEN 'sent' THEN 3 WHEN 'skipped' THEN 4 ELSE 5 END, queued_at DESC LIMIT 1"
            );
            $stmt->execute([$tid]);
            $status = $stmt->fetchColumn();

            return is_string($status) && $status !== '' ? $status : 'absent';
        } catch (Throwable) {
            return 'absent';
        }
    }
    $map = function_exists('jeab_inbox_quote_queue_load') ? jeab_inbox_quote_queue_load() : [];
    $row = $map[$tid] ?? null;
    if (!is_array($row)) {
        return 'absent';
    }
    if ($product !== '' && (string) ($row['product'] ?? '') !== '' && (string) ($row['product'] ?? '') !== $product) {
        return 'absent';
    }
    $status = (string) ($row['status'] ?? 'pending');

    return $status !== '' ? $status : 'pending';
}

function jeab_quote_outbox_mark(string $idem, string $status, int $attempts, int $nextAt, string $reason): void
{
    $reason = jeab_quote_outbox_reason($reason);
    if (jeab_quote_queue_backend() === 'sql') {
        $pdo = jeab_quote_outbox_pdo();
        if ($pdo instanceof PDO && $idem !== '') {
            try {
                jeab_quote_outbox_locked($pdo, static function (PDO $pdo) use ($idem, $status, $attempts, $nextAt, $reason): void {
                    $stmt = $pdo->prepare(
                        'UPDATE quote_outbox SET status = ?, attempts = ?, next_attempt_at = ?, reason = ?, updated_at = ? WHERE idem_key = ?'
                    );
                    $stmt->execute([$status, $attempts, $nextAt, $reason, jeab_quote_outbox_now(), $idem]);
                });
            } catch (Throwable) {
            }
        }
        unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);

        return;
    }
    if (!function_exists('jeab_inbox_quote_queue_load') || !function_exists('jeab_inbox_quote_queue_save')) {
        return;
    }
    unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
    $all = jeab_inbox_quote_queue_load();
    foreach ($all as $tid => $row) {
        if (!is_array($row)) {
            continue;
        }
        $rowIdem = jeab_quote_outbox_idem((string) $tid, (string) ($row['product'] ?? ''), (string) ($row['idem'] ?? ''));
        if ($rowIdem !== $idem) {
            continue;
        }
        $row['status'] = $status;
        $row['attempts'] = $attempts;
        $row['next_attempt_at'] = $nextAt;
        $row['idem'] = $rowIdem;
        $row['reason'] = $reason;
        $all[$tid] = $row;
    }
    jeab_inbox_quote_queue_save($all);
    unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
}

function jeab_quote_outbox_finalize(string $tid, string $product, string $idem, string $status, string $reason): void
{
    $attempts = jeab_quote_outbox_attempts($idem);
    jeab_quote_outbox_log($tid, $product, $status, $reason, $attempts);
    if (jeab_quote_queue_backend() === 'sql') {
        $pdo = jeab_quote_outbox_pdo();
        if ($pdo instanceof PDO && $idem !== '') {
            try {
                $clean = jeab_quote_outbox_reason($reason);
                jeab_quote_outbox_locked($pdo, static function (PDO $pdo) use ($idem, $status, $clean): void {
                    $stmt = $pdo->prepare(
                        'UPDATE quote_outbox SET status = ?, reason = ?, next_attempt_at = 0, updated_at = ? WHERE idem_key = ?'
                    );
                    $stmt->execute([$status, $clean, jeab_quote_outbox_now(), $idem]);
                });
            } catch (Throwable) {
            }
        }
        unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);

        return;
    }
    if (!function_exists('jeab_inbox_quote_queue_load') || !function_exists('jeab_inbox_quote_queue_save')) {
        return;
    }
    unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
    $all = jeab_inbox_quote_queue_load();
    foreach ($all as $key => $row) {
        if ((string) $key === $tid) {
            unset($all[$key]);
        }
    }
    jeab_inbox_quote_queue_save($all);
    unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
}

function jeab_quote_drain_class(array $res, int $attempts): string
{
    if (!empty($res['queued']) || ($res['action'] ?? '') === 'waiting') {
        return 'waiting';
    }
    if (!empty($res['sent']) || ($res['action'] ?? '') === 'quoted') {
        return 'sent';
    }
    $action = (string) ($res['action'] ?? '');
    if (!empty($res['already_sent']) || $action === 'closed' || $action === 'blocked_duplicate') {
        return 'skip';
    }
    $why = function_exists('mb_strtolower')
        ? mb_strtolower(trim((string) ($res['why'] ?? $res['error'] ?? '')))
        : strtolower(trim((string) ($res['why'] ?? $res['error'] ?? '')));
    $final = [
        'redan skickad',
        'already sent',
        'testlead',
        'offertutskick är av',
        'butiksorder',
        'malmö/skåne',
        'help scout',
        'importerat',
        'ingen automatik',
        'omklassning',
        'saknar mejl',
        'kundadress',
        'no-reply',
        'formulär',
        'redan mailad',
        'product_mismatch',
        'product_unclassified',
    ];
    foreach ($final as $needle) {
        if ($why !== '' && str_contains($why, $needle)) {
            return 'skip';
        }
    }
    if ($attempts + 1 >= 8) {
        return 'skip';
    }

    return 'retry';
}

function jeab_quote_outbox_after_send(string $tid, string $product, string $idem, array $res, bool $failed, string $class = ''): void
{
    $attempts = jeab_quote_outbox_attempts($idem);
    $why = $failed ? 'throw' : trim((string) ($res['why'] ?? $res['error'] ?? $res['action'] ?? ''));
    // Claim har redan räknat det här försöket. Lägg inte på ett till.
    // Ett manuellt håll är inte ett förbrukat försök och får inte bli ett slutligt hopp.
    $manualHold = !$failed
        && (string) ($res['action'] ?? '') === 'hold'
        && jeab_quote_outbox_manual_now($idem) === 1;
    if ($failed) {
        $class = $attempts >= 8 ? 'skip' : 'retry';
    } elseif ($manualHold) {
        $class = 'retry';
        if ($attempts > 0) {
            $attempts--;
        }
    } elseif ($class === '') {
        $class = jeab_quote_drain_class($res, $attempts > 0 ? $attempts - 1 : 0);
    }
    if ($class === 'waiting') {
        $jobId = (int) ($res['send_queue_id'] ?? $res['id'] ?? 0);
        jeab_quote_outbox_mark_guard_waiting($tid, $product, $idem, $jobId);

        return;
    }
    if ($class === 'sent') {
        jeab_quote_outbox_finalize($tid, $product, $idem, 'sent', 'sent');
        if ($product !== '' && function_exists('jeab_inkorg_relabel_offer_idem_remember')) {
            jeab_inkorg_relabel_offer_idem_remember($tid, $product, true);
        }

        return;
    }
    if ($product !== '' && function_exists('jeab_inkorg_relabel_offer_idem_clear')) {
        jeab_inkorg_relabel_offer_idem_clear($tid, $product);
    }
    if ($class === 'skip') {
        jeab_quote_outbox_finalize($tid, $product, $idem, 'skipped', $why !== '' ? $why : 'hoppad');

        return;
    }
    $backoff = time() + min(900, 30 * max(1, $attempts));
    jeab_quote_outbox_mark($idem, 'retry', $attempts, $backoff, $why !== '' ? $why : 'retry');
    jeab_quote_outbox_log($tid, $product, 'retry', $why !== '' ? $why : 'retry', $attempts);
}

/**
 * Räknar rader som ligger kvar i den gamla cutover-dokumentet och i quote-queue.json.
 * Bara antal, totalt och per bolag. Ingen kundtext och ingen skrivning.
 *
 * @return array{ok:bool,read_only:bool,sent:int,would_send:bool,total:int,companies:array{jarfalla:int,malmo:int,other:int},json:array{total:int,companies:array{jarfalla:int,malmo:int,other:int}},cutover:array{total:int,companies:array{jarfalla:int,malmo:int,other:int}}}
 */
function jeab_inbox_quote_queue_legacy_count(): array
{
    $json = jeab_quote_queue_legacy_counts(jeab_quote_queue_legacy_json_map());
    $cutover = jeab_quote_queue_legacy_counts(jeab_quote_queue_legacy_cutover_map());
    $companies = ['jarfalla' => 0, 'malmo' => 0, 'other' => 0];
    foreach (array_keys($companies) as $key) {
        $companies[$key] = $json['companies'][$key] + $cutover['companies'][$key];
    }

    return [
        'ok' => true,
        'read_only' => true,
        'sent' => 0,
        'would_send' => false,
        'total' => $json['total'] + $cutover['total'],
        'companies' => $companies,
        'json' => $json,
        'cutover' => $cutover,
    ];
}

/** @return array<string, mixed> */
function jeab_quote_queue_legacy_json_map(): array
{
    $dirs = [jeab_data_dir()];
    if (function_exists('jeab_public_data_dir')) {
        $public = jeab_public_data_dir();
        if ($public !== '' && $public !== jeab_data_dir()) {
            $dirs[] = $public;
        }
    }
    $merged = [];
    foreach ($dirs as $dir) {
        $path = $dir . '/quote-queue.json';
        if (!is_file($path)) {
            continue;
        }
        $raw = file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            continue;
        }
        foreach ($decoded as $key => $row) {
            if (!array_key_exists($key, $merged)) {
                $merged[$key] = $row;
            }
        }
    }

    return $merged;
}

/** @return array<string, mixed> */
function jeab_quote_queue_legacy_cutover_map(): array
{
    if (!function_exists('jeab_data_pdo')) {
        return [];
    }
    $pdo = jeab_data_pdo();
    if (!$pdo instanceof PDO) {
        return [];
    }
    try {
        $stmt = $pdo->query("SELECT payload FROM quote_queue WHERE doc_key = 'quote_queue' LIMIT 1");
        $payload = $stmt ? $stmt->fetchColumn() : false;
    } catch (Throwable) {
        return [];
    }
    if (!is_string($payload) || $payload === '') {
        return [];
    }
    $decoded = json_decode($payload, true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<string, mixed> $map
 * @return array{total:int,companies:array{jarfalla:int,malmo:int,other:int}}
 */
function jeab_quote_queue_legacy_counts(array $map): array
{
    $companies = ['jarfalla' => 0, 'malmo' => 0, 'other' => 0];
    $total = 0;
    foreach ($map as $tid => $row) {
        if (!is_string($tid) && !is_int($tid)) {
            continue;
        }
        $thread = trim((string) $tid);
        if ($thread === '' || str_contains($thread, '@')) {
            continue;
        }
        if (!is_array($row)) {
            continue;
        }
        $total++;
        $companies[jeab_quote_queue_legacy_company($thread, $row)]++;
    }

    return ['total' => $total, 'companies' => $companies];
}

/** @param array<string, mixed> $row */
function jeab_quote_queue_legacy_company(string $tid, array $row): string
{
    $company = jeab_quote_queue_legacy_company_name($row['company'] ?? null);
    if ($company !== 'other') {
        return $company;
    }
    if ($tid === '' || !function_exists('jeab_data_pdo')) {
        return 'other';
    }
    $pdo = jeab_data_pdo();
    if (!$pdo instanceof PDO) {
        return 'other';
    }
    try {
        $stmt = $pdo->prepare('SELECT row_json FROM inkorg_threads WHERE id = ?');
        $stmt->execute([$tid]);
        $raw = $stmt->fetchColumn();
    } catch (Throwable) {
        return 'other';
    }
    if (!is_string($raw) || $raw === '') {
        return 'other';
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return 'other';
    }

    return jeab_quote_queue_legacy_company_name($decoded['company'] ?? null);
}

function jeab_quote_queue_legacy_company_name(mixed $raw): string
{
    if (function_exists('jeab_normalize_company')) {
        $name = jeab_normalize_company($raw);

        return $name === 'jarfalla' || $name === 'malmo' ? $name : 'other';
    }
    $name = strtolower(trim((string) $raw));

    return $name === 'jarfalla' || $name === 'malmo' ? $name : 'other';
}

/** Känd produkt. Tom offert och okänd produkt ger ingen brevlåda. */
function jeab_quote_product_sender_email(string $offer, string $company): string
{
    if ($offer === 'sol+batteri' || (function_exists('jeab_auto_offer_canonical') && jeab_auto_offer_canonical($offer) === 'sol+batteri')) {
        $offer = 'sol';
    }
    if (!in_array($offer, ['batteri', 'laddbox', 'sol'], true)) {
        return '';
    }
    if ($company !== 'jarfalla' && $company !== 'malmo') {
        return '';
    }
    if (!function_exists('jeab_no_email_owner') || !function_exists('jeab_gp_person_email')) {
        return '';
    }
    $who = jeab_no_email_owner($offer, $company);

    return $who === '' ? '' : jeab_gp_person_email($who, $company);
}

function jeab_quote_sender_email(string $offer, string $company, string $ownerEmail): string
{
    $ownerEmail = trim($ownerEmail);
    if ($ownerEmail !== '') {
        return $ownerEmail;
    }

    return jeab_quote_product_sender_email($offer, $company);
}

/** @return array{pending:int,retry:int,sending:int,waiting:int,sent:int,skipped:int} */
function jeab_quote_outbox_counts(): array
{
    $counts = ['pending' => 0, 'retry' => 0, 'sending' => 0, 'waiting' => 0, 'sent' => 0, 'skipped' => 0];
    if (jeab_quote_queue_backend() === 'sql') {
        $pdo = jeab_quote_outbox_pdo();
        if (!$pdo instanceof PDO) {
            return $counts;
        }
        try {
            $stmt = $pdo->query('SELECT status, COUNT(*) AS n FROM quote_outbox GROUP BY status');
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable) {
            return $counts;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int) ($row['n'] ?? 0);
            }
        }

        return $counts;
    }
    $map = function_exists('jeab_inbox_quote_queue_load') ? jeab_inbox_quote_queue_load() : [];
    foreach ($map as $row) {
        if (!is_array($row)) {
            continue;
        }
        $status = (string) ($row['status'] ?? 'pending');
        if ($status === '') {
            $status = 'pending';
        }
        if (array_key_exists($status, $counts)) {
            $counts[$status]++;
        }
    }

    return $counts;
}

/**
 * @param array<string, mixed> $row
 * @return array{status:string,attempts:int,next_attempt_at:int,reason:string,product:string,guard_job:int}
 */
function jeab_quote_outbox_row_from(array $row): array
{
    $status = (string) ($row['status'] ?? '');

    return [
        'status' => $status !== '' ? $status : 'absent',
        'attempts' => (int) ($row['attempts'] ?? 0),
        'next_attempt_at' => (int) ($row['next_attempt_at'] ?? 0),
        'reason' => (string) ($row['reason'] ?? ''),
        'product' => (string) ($row['product'] ?? ''),
        'guard_job' => (int) ($row['guard_job'] ?? 0),
    ];
}

/** @return array{status:string,attempts:int,next_attempt_at:int,reason:string,product:string} */
function jeab_quote_outbox_blank_row(): array
{
    return jeab_quote_outbox_row_from(['status' => 'absent']);
}

function jeab_quote_outbox_manual_column_missing(Throwable $e): bool
{
    $msg = $e->getMessage();

    return str_contains($msg, 'manual_send')
        || str_contains($msg, 'no such column')
        || str_contains($msg, 'Unknown column');
}

function jeab_quote_outbox_manual_fetch(PDO $pdo, string $idem): int
{
    $stmt = $pdo->prepare('SELECT manual_send FROM quote_outbox WHERE idem_key = ?');
    $stmt->execute([$idem]);
    $val = $stmt->fetchColumn();

    return (int) $val === 1 ? 1 : 0;
}

/** manual_send som raden har nu. 1 eller 0. Saknas kolumnen blir det inte en tyst nolla. */
function jeab_quote_outbox_manual_now(string $idem): int
{
    $idem = trim($idem);
    if ($idem === '') {
        return 0;
    }
    if (jeab_quote_queue_backend() !== 'sql') {
        unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
        $map = function_exists('jeab_inbox_quote_queue_load') ? jeab_inbox_quote_queue_load() : [];
        unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
        foreach ($map as $tid => $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowIdem = jeab_quote_outbox_idem((string) $tid, (string) ($row['product'] ?? ''), (string) ($row['idem'] ?? ''));
            if ($rowIdem === $idem) {
                return (int) ($row['manual_send'] ?? 0) === 1 ? 1 : 0;
            }
        }

        return 0;
    }
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    if (!$pdo instanceof PDO) {
        return 0;
    }
    try {
        return jeab_quote_outbox_manual_fetch($pdo, $idem);
    } catch (Throwable $e) {
        if (!jeab_quote_outbox_manual_column_missing($e)) {
            return 0;
        }
        // Saknas kolumnen ska det inte bli en tyst nolla. Lägg till den och läs igen.
        if (function_exists('jeab_ensure_quote_outbox')) {
            jeab_ensure_quote_outbox($pdo, jeab_quote_outbox_driver($pdo));
        }

        return jeab_quote_outbox_manual_fetch($pdo, $idem);
    }
}

/** En rad för idempotensnyckeln. Ingen annan produkt i samma tråd. */
function jeab_quote_outbox_idem_row(string $idem): ?array
{
    $idem = trim($idem);
    if ($idem === '') {
        return null;
    }
    if (jeab_quote_queue_backend() !== 'sql') {
        $map = function_exists('jeab_inbox_quote_queue_load') ? jeab_inbox_quote_queue_load() : [];
        foreach ($map as $tid => $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowIdem = jeab_quote_outbox_idem((string) $tid, (string) ($row['product'] ?? ''), (string) ($row['idem'] ?? ''));
            if ($rowIdem === $idem) {
                return jeab_quote_outbox_row_from($row);
            }
        }

        return null;
    }
    $pdo = jeab_quote_outbox_pdo();
    if (!$pdo instanceof PDO) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT status, attempts, next_attempt_at, reason, product, guard_job FROM quote_outbox WHERE idem_key = ?');
        $stmt->execute([$idem]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return null;
    }

    return is_array($row) ? jeab_quote_outbox_row_from($row) : null;
}

/**
 * Samma rad som jeab_quote_outbox_state. Produktens nyckel först, annars trådens rad.
 *
 * @return array{status:string,attempts:int,next_attempt_at:int,reason:string,product:string}
 */
function jeab_quote_outbox_lookup(string $tid, string $product = ''): array
{
    $tid = trim($tid);
    $product = trim($product);
    if ($tid === '') {
        return jeab_quote_outbox_blank_row();
    }
    if (jeab_quote_queue_backend() === 'sql') {
        $pdo = jeab_quote_outbox_pdo();
        if (!$pdo instanceof PDO) {
            return jeab_quote_outbox_blank_row();
        }
        try {
            if ($product !== '') {
                $exact = jeab_quote_outbox_idem_row(jeab_quote_outbox_idem($tid, $product));
                if (is_array($exact)) {
                    return $exact;
                }
            }
            $stmt = $pdo->prepare(
                "SELECT status, attempts, next_attempt_at, reason, product, guard_job FROM quote_outbox WHERE thread_id = ? ORDER BY CASE status
                    WHEN 'waiting' THEN 0 WHEN 'sending' THEN 0 WHEN 'pending' THEN 1 WHEN 'retry' THEN 2
                    WHEN 'sent' THEN 3 WHEN 'skipped' THEN 4 ELSE 5 END, queued_at DESC LIMIT 1"
            );
            $stmt->execute([$tid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return jeab_quote_outbox_blank_row();
        }

        return is_array($row) ? jeab_quote_outbox_row_from($row) : jeab_quote_outbox_blank_row();
    }
    $map = function_exists('jeab_inbox_quote_queue_load') ? jeab_inbox_quote_queue_load() : [];
    $row = $map[$tid] ?? null;
    if (!is_array($row)) {
        return jeab_quote_outbox_blank_row();
    }
    if ($product !== '' && (string) ($row['product'] ?? '') !== '' && (string) ($row['product'] ?? '') !== $product) {
        return jeab_quote_outbox_blank_row();
    }

    return jeab_quote_outbox_row_from($row);
}

/**
 * Lägger en väntande rad för tråd och produkt. Andra väntande rader rörs inte.
 * replace_pending får inte användas: kartan har en rad per tråd och skulle ta bort en annan produkt.
 * $manualSend sätts bara av offer_send_one, aldrig från hookens indata. En vanlig köning nollar inte en etta.
 */
function jeab_quote_outbox_enqueue_one(string $tid, string $product, bool $manualSend = false): string
{
    $tid = trim($tid);
    $product = trim($product);
    if (function_exists('mb_substr')) {
        $tid = mb_substr($tid, 0, 191);
        $product = mb_substr($product, 0, 32);
    } else {
        $tid = substr($tid, 0, 191);
        $product = substr($product, 0, 32);
    }
    if ($tid === '' || $product === '' || str_contains($tid, '@')) {
        return '';
    }
    $idem = jeab_quote_outbox_idem($tid, $product);
    $manual = $manualSend ? 1 : 0;
    if (jeab_quote_queue_backend() !== 'sql') {
        if (!function_exists('jeab_inbox_quote_queue_update')) {
            return '';
        }
        $kept = '';
        $wrote = false;
        jeab_inbox_quote_queue_update(static function (array $all) use ($tid, $product, $idem, $manual, &$kept, &$wrote): ?array {
            $row = $all[$tid] ?? null;
            $prev = 0;
            if (is_array($row)) {
                $status = (string) ($row['status'] ?? '');
                if ($status === '') {
                    $status = 'pending';
                }
                $nextAt = (int) ($row['next_attempt_at'] ?? 0);
                // Bara manuell kö tar ett utgånget sending. Färskt lease och sent står kvar.
                $staleSend = $status === 'sending' && $manual === 1 && $nextAt <= time();
                if ($status === 'sent' || $status === 'waiting' || ($status === 'sending' && !$staleSend)) {
                    $kept = jeab_quote_outbox_idem($tid, (string) ($row['product'] ?? ''), (string) ($row['idem'] ?? ''));

                    return null;
                }
                if (!$staleSend) {
                    $prev = (int) ($row['manual_send'] ?? 0) === 1 ? 1 : 0;
                }
            }
            $all[$tid] = [
                'at' => time(),
                'product' => $product,
                'idem' => $idem,
                'status' => 'pending',
                'attempts' => 0,
                'next_attempt_at' => 0,
                'manual_send' => $manual === 1 ? 1 : $prev,
            ];
            $wrote = true;

            return $all;
        });
        if ($wrote && $manual === 1) {
            jeab_quote_outbox_log($tid, $product, 'pending', 'offer_send_one', 0);
        }

        return $kept !== '' ? $kept : $idem;
    }
    $pdo = jeab_quote_outbox_pdo();
    if (!$pdo instanceof PDO) {
        return '';
    }
    $driver = jeab_quote_outbox_driver($pdo);
    $now = jeab_quote_outbox_now();
    $at = time();
    $sql = $driver === 'mysql'
        ? 'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at, manual_send)
           VALUES (?, ?, ?, \'pending\', 0, 0, \'\', ?, ?, ?)
           ON DUPLICATE KEY UPDATE
             attempts = IF(status = \'skipped\', 0, attempts),
             next_attempt_at = IF(status = \'skipped\', 0, next_attempt_at),
             reason = IF(status = \'skipped\', \'\', reason),
             queued_at = IF(status = \'skipped\', VALUES(queued_at), queued_at),
             updated_at = IF(status = \'skipped\', VALUES(updated_at), updated_at),
             thread_id = IF(status = \'skipped\', VALUES(thread_id), thread_id),
             product = IF(status = \'skipped\', VALUES(product), product),
             status = IF(status = \'skipped\', \'pending\', status),
             manual_send = IF(status IN (\'sent\', \'sending\'), manual_send, GREATEST(manual_send, VALUES(manual_send)))'
        : 'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at, manual_send)
           VALUES (?, ?, ?, \'pending\', 0, 0, \'\', ?, ?, ?)
           ON CONFLICT(idem_key) DO UPDATE SET status = \'pending\', attempts = 0, next_attempt_at = 0, reason = \'\',
             queued_at = excluded.queued_at, updated_at = excluded.updated_at, thread_id = excluded.thread_id, product = excluded.product,
             manual_send = MAX(quote_outbox.manual_send, excluded.manual_send)
           WHERE quote_outbox.status = \'skipped\'';
    try {
        jeab_quote_outbox_locked($pdo, static function (PDO $pdo) use ($sql, $idem, $tid, $product, $at, $now, $manual): void {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$idem, $tid, $product, $at, $now, $manual]);
            if ($manual !== 1) {
                return;
            }
            // Väntande, hoppad och utgånget sending nollar försök. Färskt lease och sent står kvar.
            $up = $pdo->prepare(
                "UPDATE quote_outbox
                 SET status = 'pending', attempts = 0, next_attempt_at = 0, manual_send = 1, reason = '', updated_at = ?
                 WHERE idem_key = ?
                   AND (
                     status IN ('pending', 'retry', 'skipped')
                     OR (status = 'sending' AND next_attempt_at <= ?)
                   )"
            );
            $up->execute([$now, $idem, time()]);
            $chk = $pdo->prepare('SELECT status, manual_send FROM quote_outbox WHERE idem_key = ?');
            $chk->execute([$idem]);
            $got = $chk->fetch(PDO::FETCH_ASSOC);
            if (!is_array($got)) {
                return;
            }
            $status = (string) ($got['status'] ?? '');
            if ((int) ($got['manual_send'] ?? 0) === 1 && ($status === 'pending' || $status === 'retry')) {
                jeab_quote_outbox_log($tid, $product, $status, 'offer_send_one', 0);
            }
        });
    } catch (Throwable) {
        return '';
    }
    unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);

    return $idem;
}

/** Klassad produkt på tråden. Tom när tråden saknas eller inte är klassad. */
function jeab_quote_outbox_classified_offer(string $tid): string
{
    if ($tid === '' || !function_exists('jeab_auto_thread_by_id') || !function_exists('jeab_auto_thread_classified_offer')) {
        return '';
    }
    $thread = jeab_auto_thread_by_id($tid);
    if (!is_array($thread) || !empty($thread['deleted'])) {
        return '';
    }

    return jeab_auto_thread_classified_offer($thread);
}

/**
 * Produktfamilj stoppar inte utskicket. Tom sträng släpper alltid igenom.
 */
function jeab_quote_outbox_product_blocked(string $tid, string $product): string
{
    unset($tid, $product);

    return '';
}

/**
 * Bara tråd och dry. Produkten är redan samma som klassningen och skickas inte med.
 *
 * @return array{thread_id:string,dry:string}
 */
function jeab_quote_outbox_send_input(string $tid, string $product = ''): array
{
    $out = ['thread_id' => $tid, 'dry' => '0'];
    $product = trim($product);
    if ($product !== '') {
        $out['product'] = $product;
        $out['force_product'] = 1;
    }

    return $out;
}

/** Intern flagga. Sätts bara runt utboxens utskick, aldrig från hookens indata. */
function jeab_quote_outbox_ctx_begin(): void
{
    $depth = (int) ($GLOBALS['_JEAB_QUOTE_OUTBOX_CTX_DEPTH'] ?? 0);
    if ($depth === 0) {
        $GLOBALS['_JEAB_QUOTE_OUTBOX_CTX_HAD'] = array_key_exists('_JEAB_QUOTE_OUTBOX_CTX', $GLOBALS);
        if (!empty($GLOBALS['_JEAB_QUOTE_OUTBOX_CTX_HAD'])) {
            $GLOBALS['_JEAB_QUOTE_OUTBOX_CTX_PREV'] = $GLOBALS['_JEAB_QUOTE_OUTBOX_CTX'];
        }
    }
    $GLOBALS['_JEAB_QUOTE_OUTBOX_CTX_DEPTH'] = $depth + 1;
    $GLOBALS['_JEAB_QUOTE_OUTBOX_CTX'] = true;
}

function jeab_quote_outbox_ctx_end(): void
{
    $depth = (int) ($GLOBALS['_JEAB_QUOTE_OUTBOX_CTX_DEPTH'] ?? 0);
    if ($depth <= 0) {
        return;
    }
    if ($depth > 1) {
        $GLOBALS['_JEAB_QUOTE_OUTBOX_CTX_DEPTH'] = $depth - 1;

        return;
    }
    unset($GLOBALS['_JEAB_QUOTE_OUTBOX_CTX_DEPTH']);
    if (!empty($GLOBALS['_JEAB_QUOTE_OUTBOX_CTX_HAD'])) {
        $GLOBALS['_JEAB_QUOTE_OUTBOX_CTX'] = $GLOBALS['_JEAB_QUOTE_OUTBOX_CTX_PREV'] ?? true;
    } else {
        unset($GLOBALS['_JEAB_QUOTE_OUTBOX_CTX']);
    }
    unset($GLOBALS['_JEAB_QUOTE_OUTBOX_CTX_HAD'], $GLOBALS['_JEAB_QUOTE_OUTBOX_CTX_PREV']);
}

/**
 * Kvarvarande case-håll från sparade etiketter. Inget nät.
 * Anropas bara från offer_send_one. Det är ett manuellt utskick, så Grok-osäkerhet släpps
 * redan i förhandsvisningen. Övriga håll står kvar. Tömningen släpper samma håll bara när manual_send är 1.
 */
function jeab_quote_outbox_case_hold(string $tid): string
{
    $tid = trim($tid);
    if ($tid === '' || str_contains($tid, '@')) {
        return '';
    }
    if (!function_exists('jeab_auto_thread_by_id') || !function_exists('jeab_inkorg_case_hold_why') || !function_exists('jeab_inkorg_case_of')) {
        return '';
    }
    $thread = jeab_auto_thread_by_id($tid);
    if (!is_array($thread) || !empty($thread['deleted'])) {
        return '';
    }
    $msg = function_exists('jeab_quote_chain_thread_message') ? jeab_quote_chain_thread_message($thread) : [];
    $msg = is_array($msg) ? $msg : [];
    $why = jeab_inkorg_case_hold_why(jeab_inkorg_case_of($msg, $thread));
    if ($why === '') {
        return '';
    }
    if (str_starts_with($why, 'Grok är osäker')) {
        return '';
    }

    return $why;
}

/** OFFER_SEND_ONE_ENABLED. Standard av. Samma källor som övriga flaggor. */
function jeab_offer_send_one_enabled(): bool
{
    if (!function_exists('jeab_flag_effective')) {
        return false;
    }
    $flag = jeab_flag_effective('OFFER_SEND_ONE_ENABLED');

    return (int) ($flag['value'] ?? 0) === 1;
}

/** Synkront utskick bara när test eller CLI sätter flaggan. Annars köas raden. */
function jeab_offer_send_one_sync(): bool
{
    return !empty($GLOBALS['_JEAB_OFFER_SEND_ONE_SYNC']);
}

/** Efter svaret, om php-fpm kan släppa klienten. Raden ligger kvar tills den är skickad. */
function jeab_offer_send_one_finish_later(): void
{
    if (!empty($GLOBALS['_JEAB_OFFER_SEND_ONE_LATER'])) {
        return;
    }
    $sapi = (string) ($GLOBALS['_JEAB_SAPI'] ?? PHP_SAPI);
    if ($sapi === 'cli' || $sapi === 'phpdbg' || !function_exists('fastcgi_finish_request')) {
        return;
    }
    $GLOBALS['_JEAB_OFFER_SEND_ONE_LATER'] = true;
    register_shutdown_function(static function (): void {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        if (!function_exists('jeab_inbox_queue_quote_drain')) {
            return;
        }
        $hadTick = array_key_exists('_JEAB_TICK', $GLOBALS);
        $prevTick = $GLOBALS['_JEAB_TICK'] ?? null;
        $GLOBALS['_JEAB_TICK'] = true;
        jeab_quote_outbox_inflight_arm();
        try {
            jeab_inbox_queue_quote_drain(1);
        } catch (Throwable) {
        } finally {
            jeab_quote_outbox_inflight_abort();
            if ($hadTick) {
                $GLOBALS['_JEAB_TICK'] = $prevTick;
            } else {
                unset($GLOBALS['_JEAB_TICK']);
            }
        }
    });
}

/**
 * Omläsning efter köning. queued kräver manual_send=1 och pending eller retry.
 *
 * @return array{status:string,manual_send:int,ready:bool,attempts:int,next_attempt_at:int,reason:string}
 */
function jeab_quote_outbox_manual_ready(string $idem): array
{
    $exact = jeab_quote_outbox_idem_row($idem);
    $state = is_array($exact) ? (string) ($exact['status'] ?? 'absent') : 'absent';
    $manual = jeab_quote_outbox_manual_now($idem);

    return [
        'status' => $state,
        'manual_send' => $manual,
        'ready' => $manual === 1 && ($state === 'pending' || $state === 'retry'),
        'attempts' => (int) ($exact['attempts'] ?? 0),
        'next_attempt_at' => (int) ($exact['next_attempt_at'] ?? 0),
        'reason' => (string) ($exact['reason'] ?? ''),
    ];
}

/**
 * Claim hoppas. Raden är pending och får tömmas nu.
 * Svarar queued bara när omläsningen visar manual_send=1 och pending eller retry.
 *
 * @param array<string, mixed> $view
 * @return array<string, mixed>
 */
function jeab_offer_send_one_queued(array $view, string $idem): array
{
    $ready = jeab_quote_outbox_manual_ready($idem);
    $state = $ready['status'];
    if ($state === 'sent') {
        $view['ok'] = false;
        $view['error'] = 'already_out';
        $view['sent'] = 0;
        $view['would_send'] = false;
        $view['outbox'] = 'sent';
        $view['already_out'] = true;
        $view['queued'] = 0;

        return $view;
    }
    if (!$ready['ready']) {
        $view['ok'] = false;
        $view['error'] = in_array($state, ['sending', 'skipped'], true) ? $state : 'not_queued';
        $view['sent'] = 0;
        $view['queued'] = 0;
        $view['would_send'] = false;
        $view['outbox'] = $state;
        $view['already_out'] = false;
        $view['read_only'] = true;

        return $view;
    }
    if ($state !== 'pending' || $ready['next_attempt_at'] > time()) {
        jeab_quote_outbox_mark($idem, 'pending', $ready['attempts'], 0, $ready['reason']);
        $state = 'pending';
    }
    jeab_offer_send_one_finish_later();
    $view['ok'] = true;
    $view['read_only'] = false;
    $view['sent'] = 0;
    $view['queued'] = 1;
    $view['would_send'] = false;
    $view['outbox'] = $state;
    $view['already_out'] = false;
    $view['reason'] = jeab_quote_outbox_reason('queued');

    return $view;
}

/** Tom mall, eller en mall utan produkt, stoppar. En namngiven annan produkt gör det inte. */
function jeab_quote_guard_names_product(string $template, string $product): bool
{
    $template = function_exists('mb_strtolower') ? mb_strtolower(trim($template)) : strtolower(trim($template));
    $family = function_exists('jeab_offer_product_family') ? jeab_offer_product_family($product) : '';
    $product = $family !== '' ? $family : (function_exists('jeab_auto_quote_offer_key') ? jeab_auto_quote_offer_key($product) : trim($product));
    if ($product === '' || $template === '') {
        return true;
    }
    $needles = match ($product) {
        'laddbox' => ['laddbox', 'elbil'],
        'batteri' => ['batteri'],
        'sol' => ['sol'],
        'sol+batteri' => ['sol', 'batteri'],
        default => [$product],
    };
    foreach ($needles as $needle) {
        if ($needle !== '' && str_contains($template, $needle)) {
            return true;
        }
    }

    return false;
}

function jeab_quote_outbox_guard_job(string $idem): int
{
    $row = jeab_quote_outbox_idem_row($idem);

    return is_array($row) ? (int) ($row['guard_job'] ?? 0) : 0;
}

/**
 * Lägger raden i väntan tills send_guard faktiskt skickat jobbet.
 * En rad som redan är sent eller skipped rörs inte. Saknas raden skapas den.
 */
function jeab_quote_outbox_mark_guard_waiting(string $tid, string $product, string $idem, int $jobId): void
{
    $tid = trim($tid);
    $product = trim($product);
    $idem = trim($idem);
    if (function_exists('mb_substr')) {
        $tid = mb_substr($tid, 0, 191);
        $product = mb_substr($product, 0, 32);
    }
    if ($idem === '' && $tid !== '' && $product !== '') {
        $idem = jeab_quote_outbox_idem($tid, $product);
    }
    if ($tid === '' || $idem === '' || str_contains($tid, '@')) {
        return;
    }
    $reason = 'send_guard_queued';
    if (jeab_quote_queue_backend() !== 'sql') {
        if (!function_exists('jeab_inbox_quote_queue_load') || !function_exists('jeab_inbox_quote_queue_save')) {
            return;
        }
        unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
        $all = jeab_inbox_quote_queue_load();
        $key = $tid;
        if (!isset($all[$key]) || !is_array($all[$key])) {
            $key = '';
            foreach ($all as $candidate => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rowIdem = jeab_quote_outbox_idem((string) $candidate, (string) ($row['product'] ?? ''), (string) ($row['idem'] ?? ''));
                if ($rowIdem === $idem) {
                    $key = (string) $candidate;
                    break;
                }
            }
        }
        if ($key !== '' && is_array($all[$key] ?? null)) {
            $row = $all[$key];
            $status = (string) ($row['status'] ?? 'pending');
            if ($status === 'sent' || $status === 'skipped') {
                return;
            }
            $keptJob = (int) ($row['guard_job'] ?? 0);
            $row['status'] = 'waiting';
            $row['reason'] = $reason;
            $row['next_attempt_at'] = 0;
            $row['guard_job'] = $jobId > 0 ? $jobId : $keptJob;
            $row['idem'] = $idem;
            if ($product !== '' && trim((string) ($row['product'] ?? '')) === '') {
                $row['product'] = $product;
            }
            $all[$key] = $row;
            jeab_inbox_quote_queue_save($all);
            unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
            if ($status !== 'waiting') {
                jeab_quote_outbox_log($tid, (string) ($row['product'] ?? $product), 'waiting', $reason, (int) ($row['attempts'] ?? 0));
            }

            return;
        }
        $fresh = [
            'at' => time(),
            'product' => $product,
            'idem' => $idem,
            'status' => 'waiting',
            'attempts' => 0,
            'next_attempt_at' => 0,
            'reason' => $reason,
            'guard_job' => max(0, $jobId),
            'manual_send' => 0,
        ];
        $all[$tid] = $fresh;
        jeab_inbox_quote_queue_save($all);
        unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
        jeab_quote_outbox_log($tid, $product, 'waiting', $reason, 0);

        return;
    }
    $pdo = jeab_quote_outbox_pdo();
    if (!$pdo instanceof PDO) {
        return;
    }
    try {
        jeab_quote_outbox_locked($pdo, static function (PDO $pdo) use ($tid, $product, $idem, $jobId, $reason): void {
            $look = $pdo->prepare('SELECT status, attempts, product, guard_job FROM quote_outbox WHERE idem_key = ?');
            $look->execute([$idem]);
            $got = $look->fetch(PDO::FETCH_ASSOC);
            if (is_array($got)) {
                $status = (string) ($got['status'] ?? '');
                if ($status === 'sent' || $status === 'skipped') {
                    return;
                }
                $upd = $pdo->prepare(
                    "UPDATE quote_outbox
                     SET status = 'waiting', reason = ?, next_attempt_at = 0,
                         guard_job = CASE WHEN ? > 0 THEN ? ELSE guard_job END,
                         product = CASE WHEN product = '' AND ? <> '' THEN ? ELSE product END,
                         updated_at = ?
                     WHERE idem_key = ? AND status IN ('pending', 'retry', 'sending', 'waiting')"
                );
                $upd->execute([$reason, $jobId, $jobId, $product, $product, jeab_quote_outbox_now(), $idem]);
                if ($status !== 'waiting') {
                    $loggedProduct = trim((string) ($got['product'] ?? ''));
                    if ($loggedProduct === '') {
                        $loggedProduct = $product;
                    }
                    jeab_quote_outbox_log($tid, $loggedProduct, 'waiting', $reason, (int) ($got['attempts'] ?? 0));
                }

                return;
            }
            $ins = $pdo->prepare(
                'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at, manual_send, guard_job)
                 VALUES (?, ?, ?, \'waiting\', 0, 0, ?, ?, ?, 0, ?)'
            );
            $now = time();
            $ins->execute([$idem, $tid, $product, $reason, $now, jeab_quote_outbox_now(), max(0, $jobId)]);
            jeab_quote_outbox_log($tid, $product, 'waiting', $reason, 0);
        });
    } catch (Throwable) {
    }
    unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
}

/** Bara en väntande rad släpps till retry. Sent och pågående försök lämnas. */
function jeab_quote_outbox_guard_drop_row(string $tid, string $product, string $idem): void
{
    $idem = trim($idem);
    if ($idem === '') {
        return;
    }
    $row = jeab_quote_outbox_idem_row($idem);
    if (!is_array($row) || (string) ($row['status'] ?? '') !== 'waiting') {
        return;
    }
    $attempts = (int) ($row['attempts'] ?? 0);
    $loggedProduct = trim($product) !== '' ? trim($product) : (string) ($row['product'] ?? '');
    $loggedThread = trim($tid);
    jeab_quote_outbox_mark($idem, 'retry', $attempts, time(), 'send_guard_dropped');
    jeab_quote_outbox_log($loggedThread, $loggedProduct, 'retry', 'send_guard_dropped', $attempts);
}

function jeab_quote_outbox_guard_drop_job(int $jobId): void
{
    if ($jobId < 1) {
        return;
    }
    if (jeab_quote_queue_backend() !== 'sql') {
        $map = function_exists('jeab_inbox_quote_queue_load') ? jeab_inbox_quote_queue_load() : [];
        foreach ($map as $key => $row) {
            if (!is_array($row) || (int) ($row['guard_job'] ?? 0) !== $jobId) {
                continue;
            }
            $idem = jeab_quote_outbox_idem((string) $key, (string) ($row['product'] ?? ''), (string) ($row['idem'] ?? ''));
            jeab_quote_outbox_guard_drop_row((string) $key, (string) ($row['product'] ?? ''), $idem);
        }

        return;
    }
    $pdo = jeab_quote_outbox_pdo();
    if (!$pdo instanceof PDO) {
        return;
    }
    try {
        $stmt = $pdo->prepare("SELECT idem_key, thread_id, product FROM quote_outbox WHERE guard_job = ? AND status = 'waiting'");
        $stmt->execute([$jobId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return;
    }
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        jeab_quote_outbox_guard_drop_row(
            (string) ($row['thread_id'] ?? ''),
            (string) ($row['product'] ?? ''),
            (string) ($row['idem_key'] ?? '')
        );
    }
}

/**
 * Metadata som lades på send_queue-jobbet när offerten köades.
 *
 * @param array<string, mixed> $job
 * @return array{thread_id:string,product:string,quote_idem:string,offer_idem:string,from:string,via:string,actor:string,kind:string,offer:string,idem_keys:list<string>,party:array<string,mixed>}|null
 */
function jeab_quote_guard_meta_from_job(array $job): ?array
{
    $args = is_array($job['args'] ?? null) ? $job['args'] : [];
    $opts = is_array($args['opts'] ?? null) ? $args['opts'] : [];
    $guard = $opts['quote_guard'] ?? ($args['quote_guard'] ?? null);
    if (!is_array($guard)) {
        return null;
    }
    $tid = trim((string) ($guard['thread_id'] ?? $guard['thread'] ?? ''));
    $product = trim((string) ($guard['product'] ?? ''));
    if ($tid === '' || $product === '' || str_contains($tid, '@')) {
        return null;
    }
    $quoteIdem = trim((string) ($guard['quote_idem'] ?? ''));
    if ($quoteIdem === '') {
        $quoteIdem = jeab_quote_outbox_idem($tid, $product);
    }
    $offerIdem = trim((string) ($guard['offer_idem'] ?? ''));
    if ($offerIdem === '') {
        $offerIdem = $quoteIdem;
    }
    $keys = [];
    foreach ((array) ($guard['idem_keys'] ?? []) as $key) {
        if (is_string($key) && $key !== '') {
            $keys[] = $key;
        }
    }
    $party = is_array($guard['party'] ?? null) ? $guard['party'] : [];

    return [
        'thread_id' => $tid,
        'product' => $product,
        'quote_idem' => $quoteIdem,
        'offer_idem' => $offerIdem,
        'from' => trim((string) ($guard['from'] ?? '')),
        'via' => trim((string) ($guard['via'] ?? 'manual')) !== '' ? trim((string) ($guard['via'] ?? 'manual')) : 'manual',
        'actor' => trim((string) ($guard['actor'] ?? 'hook')) !== '' ? trim((string) ($guard['actor'] ?? 'hook')) : 'hook',
        'kind' => trim((string) ($guard['kind'] ?? 'offert')) !== '' ? trim((string) ($guard['kind'] ?? 'offert')) : 'offert',
        'offer' => trim((string) ($guard['offer'] ?? $product)),
        'idem_keys' => $keys,
        'party' => $party,
    ];
}

/**
 * @param array{thread_id:string,product:string,quote_idem:string,offer_idem:string,from:string,via:string,actor:string,kind:string,offer:string,idem_keys:list<string>,party:array<string,mixed>} $meta
 * @param array<string, mixed> $result
 */
function jeab_quote_outbox_guard_commit(array $meta, array $result): void
{
    $tid = $meta['thread_id'];
    $product = $meta['product'];
    $idem = $meta['quote_idem'];
    $from = trim((string) ($result['from'] ?? ''));
    if ($from === '') {
        $from = $meta['from'];
    }
    if (function_exists('jeab_quote_send_commit')) {
        jeab_quote_send_commit($tid, $from, $meta['via'], $meta['actor'], $meta['offer'], $meta['offer_idem'], $meta['kind'], $meta['party']);
    }
    if ($meta['idem_keys'] !== [] && function_exists('jeab_send_once_remember')) {
        jeab_send_once_remember('offert', $meta['idem_keys'], 600, [
            'ok' => true,
            'already_sent' => true,
            'from' => $from,
        ]);
    }
    if ($product !== '' && function_exists('jeab_inkorg_relabel_offer_idem_remember')) {
        jeab_inkorg_relabel_offer_idem_remember($tid, $product, true);
    }
    if (jeab_quote_queue_backend() === 'sql') {
        jeab_quote_outbox_finalize($tid, $product, $idem, 'sent', 'sent');

        return;
    }
    $attempts = jeab_quote_outbox_attempts($idem);
    jeab_quote_outbox_mark($idem, 'sent', $attempts, 0, 'sent');
    jeab_quote_outbox_log($tid, $product, 'sent', 'sent', $attempts);
}

/**
 * @param array<string, mixed> $job
 * @param array<string, mixed> $result
 */
function jeab_quote_outbox_guard_replay(array $job, array $result): void
{
    $meta = jeab_quote_guard_meta_from_job($job);
    if ($meta === null) {
        return;
    }
    $real = !empty($result['ok']) && empty($result['skipped']) && empty($result['queued']);
    if ($real) {
        jeab_quote_outbox_guard_commit($meta, $result);

        return;
    }
    jeab_quote_outbox_guard_drop_row($meta['thread_id'], $meta['product'], $meta['quote_idem']);
}

function jeab_quote_send_guard_blocks(string $tid, string $product): bool
{
    $tid = trim($tid);
    if ($tid === '') {
        return false;
    }
    $product = trim($product);
    if ($product !== '' && function_exists('jeab_auto_quote_offer_key')) {
        $key = jeab_auto_quote_offer_key($product);
        if ($key !== '') {
            $product = $key;
        }
    }
    if (!function_exists('jeab_inkorg_thread_send_log_guard')) {
        return false;
    }
    foreach (jeab_inkorg_thread_send_log_guard($tid) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $status = (string) ($row['status'] ?? '');
        if (!in_array($status, ['sending', 'sent'], true)) {
            continue;
        }
        if (jeab_quote_guard_names_product((string) ($row['template'] ?? ''), $product)) {
            return true;
        }
    }

    return false;
}

/** Riktigt utskick av samma produkt. En gammal idempotensrad utan sent räknas inte. */
function jeab_quote_offer_already_out(string $tid, string $product): bool
{
    $tid = trim($tid);
    if ($tid === '' || str_contains($tid, '@')) {
        return false;
    }
    $product = function_exists('jeab_auto_quote_offer_key') ? jeab_auto_quote_offer_key($product) : trim($product);
    if ($product !== '') {
        $exact = jeab_quote_outbox_idem_row(jeab_quote_outbox_idem($tid, $product));
        if (is_array($exact) && (string) ($exact['status'] ?? '') === 'sent') {
            return true;
        }
        if (function_exists('jeab_inkorg_relabel_offer_idem_after_send') && jeab_inkorg_relabel_offer_idem_after_send($tid, $product)) {
            return true;
        }
    } else {
        $any = jeab_quote_outbox_lookup($tid, '');
        if ((string) ($any['status'] ?? '') === 'sent') {
            return true;
        }
        if (function_exists('jeab_inkorg_relabel_offer_idem_map')) {
            foreach (jeab_inkorg_relabel_offer_idem_map($tid) as $flag) {
                if (is_array($flag) && !empty($flag['sent'])) {
                    return true;
                }
            }
        }
    }
    $thread = function_exists('jeab_auto_thread_by_id') ? jeab_auto_thread_by_id($tid) : null;
    if (is_array($thread)) {
        if (function_exists('jeab_quote_chain_thread_quoted') && jeab_quote_chain_thread_quoted($tid, $product)) {
            return true;
        }
        if (function_exists('jeab_inkorg_quote_sent_in_thread') && jeab_inkorg_quote_sent_in_thread($thread, $product)) {
            return true;
        }
        if (function_exists('jeab_offer_idem_keys') && function_exists('jeab_send_once_peek') && function_exists('jeab_quote_chain_thread_message')) {
            $msg = jeab_quote_chain_thread_message($thread);
            $rcpt = '';
            if (function_exists('jeab_lead_customer')) {
                $cust = jeab_lead_customer(is_array($msg) ? $msg : []);
                $rcpt = mb_strtolower(trim((string) ($cust['email'] ?? '')));
            }
            $remId = 0;
            if (function_exists('jeab_lead_find_reminder')) {
                $rem = jeab_lead_find_reminder($tid, $rcpt);
                $remId = is_array($rem) ? (int) ($rem['id'] ?? 0) : 0;
            }
            $sets = [jeab_offer_idem_keys($tid, $rcpt, $product, $remId)];
            if ($remId !== 0) {
                $sets[] = jeab_offer_idem_keys($tid, $rcpt, $product, 0);
            }
            foreach ($sets as $keys) {
                if ($keys !== [] && jeab_send_once_peek('offert', $keys, 600) !== null) {
                    return true;
                }
            }
        }
    }

    return jeab_quote_send_guard_blocks($tid, $product);
}

/** Utgånget sending som redan har gått ut. Avslutas utan nytt mejl. */
function jeab_quote_outbox_stale_close(string $tid, string $product): bool
{
    $idem = jeab_quote_outbox_idem($tid, $product);
    $row = jeab_quote_outbox_idem_row($idem);
    if (!is_array($row) || (string) ($row['status'] ?? '') !== 'sending') {
        return false;
    }
    if ((int) ($row['next_attempt_at'] ?? 0) > time()) {
        return false;
    }
    if (!jeab_quote_offer_already_out($tid, $product)) {
        return false;
    }
    jeab_quote_outbox_finalize($tid, $product, $idem, 'sent', 'already_out');
    if ($product !== '' && function_exists('jeab_inkorg_relabel_offer_idem_remember')) {
        jeab_inkorg_relabel_offer_idem_remember($tid, $product, true);
    }

    return true;
}

/**
 * Claim av ett utgånget sending. Redan ute avslutas, annars får anropet skicka.
 */
function jeab_quote_outbox_reclaim_done(string $tid, string $product, string $idem, string $prev): bool
{
    if ($prev !== 'sending' || $tid === '' || $idem === '') {
        return false;
    }
    if (!jeab_quote_offer_already_out($tid, $product)) {
        return false;
    }
    jeab_quote_outbox_finalize($tid, $product, $idem, 'sent', 'already_out');
    if ($product !== '' && function_exists('jeab_inkorg_relabel_offer_idem_remember')) {
        jeab_inkorg_relabel_offer_idem_remember($tid, $product, true);
    }

    return true;
}

/**
 * inbox.outbox_status. Bara läsning. Antal per status, och en tråds rad när thread_id finns.
 * Ingen kundtext.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_inbox_outbox_status(array $in): array
{
    $tid = trim((string) ($in['thread_id'] ?? $in['thread'] ?? ''));
    $asked = trim((string) ($in['product'] ?? ''));
    if ($asked !== '' && function_exists('jeab_inkorg_force_offer')) {
        $forced = jeab_inkorg_force_offer($asked);
        if ($forced !== '') {
            $asked = $forced;
        }
    }
    $out = [
        'ok' => true,
        'read_only' => true,
        'dry' => 1,
        'sent' => 0,
        'would_send' => false,
        'counts' => jeab_quote_outbox_counts(),
    ];
    if ($tid === '') {
        return $out;
    }
    if (str_contains($tid, '@')) {
        $out['ok'] = false;
        $out['error'] = 'thread_id saknas';

        return $out;
    }
    $row = jeab_quote_outbox_lookup($tid, $asked);
    $out['thread_id'] = $tid;
    $out['product'] = $asked !== '' ? $asked : (string) ($row['product'] ?? '');
    $out['state'] = jeab_quote_outbox_state($tid, $asked);
    $out['attempts'] = (int) ($row['attempts'] ?? 0);
    $out['next_attempt_at'] = (int) ($row['next_attempt_at'] ?? 0);
    $out['reason'] = jeab_quote_outbox_reason((string) ($row['reason'] ?? ''));

    return $out;
}

/**
 * inbox.offer_send_one. dry är standard. Skriver bara med dry=0 och confirm=send.
 * Vägrar ett riktigt utskick i samma ärende. Samma e-post och produktfamilj på 30 dagar stoppar.
 * Live köar raden. Utskicket gör cron, utom när _JEAB_OFFER_SEND_ONE_SYNC är satt.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_inbox_offer_send_one(array $in): array
{
    $dry = true;
    if (array_key_exists('dry', $in) && ($in['dry'] === 0 || $in['dry'] === '0' || $in['dry'] === false)) {
        $dry = false;
    }
    $tid = trim((string) ($in['thread_id'] ?? $in['thread'] ?? ''));
    $asked = trim((string) ($in['product'] ?? ''));
    if ($asked !== '' && function_exists('jeab_inkorg_force_offer')) {
        $forced = jeab_inkorg_force_offer($asked);
        if ($forced !== '') {
            $asked = $forced;
        }
    }
    $base = [
        'ok' => false,
        'dry' => $dry ? 1 : 0,
        'sent' => 0,
        'would_send' => false,
        'read_only' => true,
        'thread_id' => '',
        'product' => '',
        'template' => '',
        'seller' => '',
        'sender' => '',
        'outbox' => 'absent',
        'already_out' => false,
        'blockers' => [],
        'reason' => '',
        'locked' => 0,
    ];
    if ($tid === '' || str_contains($tid, '@')) {
        $base['reason'] = 'thread_id saknas';
        $base['blockers'] = ['thread_id'];

        return $base;
    }
    $base['thread_id'] = $tid;
    $thread = function_exists('jeab_auto_thread_by_id') ? jeab_auto_thread_by_id($tid) : null;
    if (!is_array($thread) || !empty($thread['deleted'])) {
        $base['reason'] = 'not_found';
        $base['blockers'] = ['not_found'];

        return $base;
    }
    $msg = function_exists('jeab_quote_chain_thread_message') ? jeab_quote_chain_thread_message($thread) : [];
    $msg = is_array($msg) ? $msg : [];
    $identity = function_exists('jeab_quote_chain_send_identity')
        ? jeab_quote_chain_send_identity($thread, $msg)
        : ['offer' => '', 'seller' => '', 'template' => ''];
    $identityOffer = (string) ($identity['offer'] ?? '');
    $product = $asked !== '' ? $asked : $identityOffer;
    $askedFamily = function_exists('jeab_offer_product_family') ? jeab_offer_product_family($asked) : '';
    $identityFamily = function_exists('jeab_offer_product_family') ? jeab_offer_product_family($identityOffer) : '';
    $sameProduct = $asked === '' || $asked === $identityOffer || ($askedFamily !== '' && $askedFamily === $identityFamily);
    $company = function_exists('jeab_inkorg_thread_company') ? (string) jeab_inkorg_thread_company($thread) : 'jarfalla';
    $sender = (string) ($identity['seller'] ?? '');
    if ($asked !== '' && !$sameProduct) {
        $sender = '';
    }
    if ($sender === '' || !str_contains($sender, '@')) {
        $mailOffer = function_exists('jeab_auto_offer_mail') ? jeab_auto_offer_mail($product) : $product;
        $sender = jeab_quote_sender_email($mailOffer, $company, str_contains($sender, '@') ? $sender : '');
    }
    $seller = '';
    if (function_exists('jeab_inbox_queue_seller_name')) {
        $seller = jeab_inbox_queue_seller_name($sender);
    }
    if ($seller === '' && function_exists('jeab_lead_quote_owner_name')) {
        $seller = jeab_lead_quote_owner_name($product, $company);
    }
    if (str_contains($seller, '@')) {
        $seller = '';
    }
    $template = $sameProduct ? (string) ($identity['template'] ?? '') : '';
    if ($template === '' && $product !== '' && function_exists('jeab_notiser_quote_notice_id')) {
        $mailOffer = function_exists('jeab_auto_offer_mail') ? jeab_auto_offer_mail($product) : $product;
        $template = jeab_notiser_quote_notice_id($mailOffer, $company, '', false);
    }
    if ($template !== '' && preg_match('/^offert-[a-z0-9+]+-(?:jarfalla|malmo)(?:-[a-z]+)?$/', $template) !== 1) {
        $template = '';
    }
    if ($product !== '') {
        $exact = jeab_quote_outbox_idem_row(jeab_quote_outbox_idem($tid, $product));
        $outbox = is_array($exact) ? (string) ($exact['status'] ?? 'absent') : 'absent';
    } else {
        $outbox = jeab_quote_outbox_state($tid, '');
    }
    $already = jeab_quote_offer_already_out($tid, $product);
    $blockers = [];
    $window = ['action' => 'send', 'note' => '', 'date' => ''];
    if ($product !== '' && function_exists('jeab_auto_offer_window_for') && function_exists('jeab_auto_settings')) {
        $window = jeab_auto_offer_window_for($thread, $msg, jeab_auto_settings(), $product);
        if (($window['action'] ?? '') === 'blocked') {
            $blockers[] = 'same_family';
        }
    }
    if ($product === '' || (function_exists('jeab_auto_offer_is') && !jeab_auto_offer_is($product))) {
        $blockers[] = 'product';
    }
    if ($sender === '') {
        $blockers[] = 'sender';
    }
    if ($product !== '' && function_exists('jeab_auto_quote_region_block') && function_exists('jeab_lead_customer')) {
        $region = jeab_auto_quote_region_block($thread, jeab_lead_customer($msg), $msg, $product);
        if ($region !== '') {
            $blockers[] = $region;
        }
    }
    if ($already) {
        $blockers[] = 'already_out';
    }
    $blockers = array_values(array_unique($blockers));
    $known = $product !== '' && (!function_exists('jeab_auto_offer_is') || jeab_auto_offer_is($product));
    $would = $blockers === [] && !$already && $sender !== '' && $known;
    $view = $base;
    $view['ok'] = true;
    $view['would_send'] = $would;
    $view['product'] = $product;
    $view['template'] = $template;
    $view['seller'] = $seller;
    $view['sender'] = $sender;
    $view['outbox'] = $outbox;
    $view['already_out'] = $already;
    $view['blockers'] = $blockers;
    $view['reason'] = (string) ($window['note'] ?? '') !== '' && in_array('same_family', $blockers, true)
        ? (string) $window['note']
        : jeab_quote_outbox_reason($would ? 'första kontakt' : (string) ($blockers[0] ?? ''));
    if ($dry || (string) ($in['confirm'] ?? '') !== 'send') {
        return $view;
    }
    if (in_array('same_family', $blockers, true) && !$already && function_exists('jeab_auto_offer_window_stop')) {
        jeab_auto_offer_window_stop($thread, $msg, [], $window);
        $view['ok'] = false;
        $view['error'] = 'same_family';
        $view['reason'] = (string) ($window['note'] ?? '');
        $view['sent'] = 0;
        $view['would_send'] = false;

        return $view;
    }
    if ($already || $blockers !== []) {
        // Utgånget sending som redan gått ut avslutas. Färskt lease och sent rörs inte.
        if ($already && jeab_quote_outbox_stale_close($tid, $product)) {
            $view['outbox'] = 'sent';
            $view['read_only'] = false;
            $view['already_out'] = true;
        }
        $view['ok'] = false;
        $view['error'] = $already ? 'already_out' : (string) ($blockers[0] ?? 'blocked');
        $view['sent'] = 0;
        $view['would_send'] = false;

        return $view;
    }
    $idem = jeab_quote_outbox_enqueue_one($tid, $product, true);
    if ($idem === '') {
        $view['ok'] = false;
        $view['error'] = 'claim';
        $view['sent'] = 0;
        $view['would_send'] = false;
        $view['outbox'] = 'absent';
        $view['already_out'] = jeab_quote_offer_already_out($tid, $product);

        return $view;
    }
    $ready = jeab_quote_outbox_manual_ready($idem);
    if (!$ready['ready']) {
        $view['ok'] = false;
        $view['error'] = in_array($ready['status'], ['sending', 'skipped', 'sent'], true) ? $ready['status'] : 'not_queued';
        $view['sent'] = 0;
        $view['queued'] = 0;
        $view['would_send'] = false;
        $view['outbox'] = $ready['status'];
        $view['already_out'] = $ready['status'] === 'sent';

        return $view;
    }
    if (!jeab_offer_send_one_sync()) {
        return jeab_offer_send_one_queued($view, $idem);
    }
    if (!jeab_quote_outbox_claim($idem)) {
        $exact = jeab_quote_outbox_idem_row($idem);
        $state = is_array($exact) ? (string) ($exact['status'] ?? 'absent') : 'absent';
        $view['ok'] = false;
        $view['error'] = $state === 'sent' ? 'already_out' : 'claim';
        $view['sent'] = 0;
        $view['would_send'] = false;
        $view['outbox'] = $state;
        $view['already_out'] = $state === 'sent' || jeab_quote_offer_already_out($tid, $product);

        return $view;
    }
    jeab_quote_outbox_inflight_add($idem, $tid, $product);
    $view['read_only'] = false;
    $hadTick = array_key_exists('_JEAB_TICK', $GLOBALS);
    $prevTick = $GLOBALS['_JEAB_TICK'] ?? null;
    $res = [];
    $failed = false;
    $closed = false;
    try {
        try {
            $GLOBALS['_JEAB_TICK'] = true;
            jeab_quote_outbox_ctx_begin();
            if (jeab_quote_outbox_reclaim_done($tid, $product, $idem, jeab_quote_outbox_claim_prev())) {
                $closed = true;
            } else {
                $blocked = jeab_quote_outbox_product_blocked($tid, $product);
                if ($blocked !== '') {
                    $res = ['ok' => false, 'sent' => 0, 'action' => 'skip', 'why' => $blocked];
                } elseif (isset($GLOBALS['_JEAB_QUOTE_DRAIN_SEND']) && is_callable($GLOBALS['_JEAB_QUOTE_DRAIN_SEND'])) {
                    $res = ($GLOBALS['_JEAB_QUOTE_DRAIN_SEND'])($tid, $product);
                } elseif (function_exists('jeab_auto_send_quote')) {
                    $res = jeab_auto_send_quote(jeab_quote_outbox_send_input($tid, $product));
                } else {
                    $res = ['ok' => false, 'error' => 'not_found'];
                }
            }
        } catch (Throwable) {
            $failed = true;
            $res = ['action' => 'retry', 'why' => 'throw'];
        } finally {
            jeab_quote_outbox_ctx_end();
            if ($hadTick) {
                $GLOBALS['_JEAB_TICK'] = $prevTick;
            } else {
                unset($GLOBALS['_JEAB_TICK']);
            }
        }
        if ($closed) {
            $view['ok'] = false;
            $view['error'] = 'already_out';
            $view['sent'] = 0;
            $view['would_send'] = false;
            $view['outbox'] = 'sent';
            $view['already_out'] = true;
            $view['reason'] = jeab_quote_outbox_reason('already_out');

            return $view;
        }
        if (!is_array($res)) {
            $res = ['action' => 'retry', 'why' => 'empty'];
        }
        if ($failed) {
            jeab_quote_outbox_after_send($tid, $product, $idem, $res, true);
        } else {
            $attempts = jeab_quote_outbox_attempts($idem);
            $class = jeab_quote_drain_class($res, $attempts > 0 ? $attempts - 1 : 0);
            jeab_quote_outbox_after_send($tid, $product, $idem, $res, false, $class);
        }
        $sent = !$failed && (!empty($res['sent']) || (($res['action'] ?? '') === 'quoted'));
        $why = $sent ? 'sent' : (string) ($res['why'] ?? $res['error'] ?? '');
        $view['ok'] = $sent;
        $view['sent'] = $sent ? 1 : 0;
        $view['would_send'] = false;
        $view['outbox'] = jeab_quote_outbox_state($tid, $product);
        $view['already_out'] = jeab_quote_offer_already_out($tid, $product);
        $view['reason'] = jeab_quote_outbox_reason($why);
        if (!$sent) {
            $view['error'] = $view['reason'] !== '' ? $view['reason'] : 'not_sent';
        }

        return $view;
    } finally {
        jeab_quote_outbox_inflight_abort();
    }
}

/** Unix-tid från heltal, siffra eller datum. Tomt och ogiltigt blir null. */
function jeab_quote_outbox_window_bound(mixed $raw): ?int
{
    if (is_int($raw)) {
        return $raw;
    }
    if (is_float($raw)) {
        return (int) $raw;
    }
    if (!is_string($raw)) {
        return null;
    }
    $raw = trim($raw);
    if ($raw === '' || str_contains($raw, '@')) {
        return null;
    }
    if (preg_match('/^\d+$/', $raw) === 1) {
        return (int) $raw;
    }
    $parsed = strtotime($raw);

    return $parsed === false ? null : $parsed;
}

/**
 * Sant bara när quote_outbox_event har orsaken offer_send_one i fönstret.
 * Send-logg, send_queue och andra orsaker visar utskicket, inte vem som köade.
 */
function jeab_quote_outbox_manual_evidence(string $tid, string $product, int $since, int $until): bool
{
    if ($tid === '' || str_contains($tid, '@')) {
        return false;
    }
    $pdo = jeab_quote_outbox_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM quote_outbox_event
             WHERE thread_id = ? AND product = ? AND reason = 'offer_send_one'
               AND created_at >= ? AND created_at <= ?
             LIMIT 1"
        );
        $stmt->execute([
            $tid,
            $product,
            date('Y-m-d H:i:s', $since),
            date('Y-m-d H:i:s', $until),
        ]);

        return (bool) $stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

/**
 * Väntande rader vars queued_at ligger i fönstret.
 *
 * @return list<array{thread_id:string,idem:string,product:string,manual_send:int,status:string}>
 */
function jeab_quote_outbox_manual_candidates(int $since, int $until): array
{
    if (jeab_quote_queue_backend() !== 'sql') {
        $all = function_exists('jeab_inbox_quote_queue_load') ? jeab_inbox_quote_queue_load() : [];
        $out = [];
        foreach ($all as $tid => $row) {
            if (!is_string($tid) && !is_int($tid)) {
                continue;
            }
            $item = is_array($row) ? $row : [];
            $status = (string) ($item['status'] ?? 'pending');
            if ($status === '') {
                $status = 'pending';
            }
            if (!in_array($status, ['pending', 'retry'], true)) {
                continue;
            }
            $at = (int) ($item['at'] ?? 0);
            if ($at < $since || $at > $until) {
                continue;
            }
            $thread = (string) $tid;
            $product = (string) ($item['product'] ?? '');
            $out[] = [
                'thread_id' => $thread,
                'idem' => jeab_quote_outbox_idem($thread, $product, (string) ($item['idem'] ?? '')),
                'product' => $product,
                'manual_send' => (int) ($item['manual_send'] ?? 0) === 1 ? 1 : 0,
                'status' => $status,
            ];
        }

        return $out;
    }
    $pdo = jeab_quote_outbox_pdo();
    if (!$pdo instanceof PDO) {
        return [];
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT idem_key, thread_id, product, manual_send, status
             FROM quote_outbox
             WHERE status IN ('pending', 'retry') AND queued_at >= ? AND queued_at <= ?"
        );
        $stmt->execute([$since, $until]);
        $fetched = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [];
    }
    $out = [];
    foreach ($fetched as $row) {
        if (!is_array($row)) {
            continue;
        }
        $out[] = [
            'thread_id' => (string) ($row['thread_id'] ?? ''),
            'idem' => (string) ($row['idem_key'] ?? ''),
            'product' => (string) ($row['product'] ?? ''),
            'manual_send' => (int) ($row['manual_send'] ?? 0) === 1 ? 1 : 0,
            'status' => (string) ($row['status'] ?? ''),
        ];
    }

    return $out;
}

/**
 * @return array<string, mixed>
 */
function jeab_quote_outbox_manual_blank(bool $dry): array
{
    return [
        'ok' => false,
        'dry' => $dry ? 1 : 0,
        'read_only' => true,
        'sent' => 0,
        'would_send' => false,
        'error' => '',
        'marked' => 0,
        'would_mark' => 0,
        'no_evidence' => 0,
        'already' => 0,
        'rows' => [],
        'undo_id' => '',
    ];
}

/**
 * @param list<array{thread_id:string,idem:string,result:string}> $rows
 * @return list<array{thread_id:string,idem:string,result:string}>
 */
function jeab_quote_outbox_manual_public(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $tid = (string) ($row['thread_id'] ?? '');
        $idem = (string) ($row['idem'] ?? '');
        if ($tid === '' || $idem === '' || str_contains($tid, '@') || str_contains($idem, '@')) {
            continue;
        }
        $out[] = [
            'thread_id' => $tid,
            'idem' => $idem,
            'result' => (string) ($row['result'] ?? ''),
        ];
    }
    usort($out, static function (array $a, array $b): int {
        return [$a['thread_id'], $a['idem']] <=> [$b['thread_id'], $b['idem']];
    });

    return $out;
}

/**
 * inbox.outbox_manual_mark. dry är standard. dry=0 kräver confirm=mark.
 * manual_send i indata läses inte. Bara pending/retry med eventet offer_send_one markeras.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_inbox_outbox_manual_mark(array $in): array
{
    $dry = true;
    if (array_key_exists('dry', $in) && ($in['dry'] === 0 || $in['dry'] === '0' || $in['dry'] === false)) {
        $dry = false;
    }
    $out = jeab_quote_outbox_manual_blank($dry);
    $since = jeab_quote_outbox_window_bound($in['since'] ?? null);
    $until = jeab_quote_outbox_window_bound($in['until'] ?? null);
    if ($since === null || $until === null || $until < $since) {
        $out['error'] = 'window';

        return $out;
    }
    if (!$dry && (string) ($in['confirm'] ?? '') !== 'mark') {
        $out['error'] = 'confirm';

        return $out;
    }
    $plan = [];
    foreach (jeab_quote_outbox_manual_candidates($since, $until) as $row) {
        $tid = (string) ($row['thread_id'] ?? '');
        $idem = (string) ($row['idem'] ?? '');
        if ($tid === '' || $idem === '' || str_contains($tid, '@') || str_contains($idem, '@')) {
            continue;
        }
        if ((int) ($row['manual_send'] ?? 0) === 1) {
            $plan[] = ['thread_id' => $tid, 'idem' => $idem, 'result' => 'already', 'prev' => 1, 'product' => ''];
            $out['already']++;
            continue;
        }
        if (!jeab_quote_outbox_manual_evidence($tid, (string) ($row['product'] ?? ''), $since, $until)) {
            $plan[] = ['thread_id' => $tid, 'idem' => $idem, 'result' => 'no_evidence', 'prev' => 0, 'product' => ''];
            $out['no_evidence']++;
            continue;
        }
        $plan[] = [
            'thread_id' => $tid,
            'idem' => $idem,
            'result' => $dry ? 'would_mark' : 'marked',
            'prev' => (int) ($row['manual_send'] ?? 0) === 1 ? 1 : 0,
            'product' => (string) ($row['product'] ?? ''),
        ];
        if ($dry) {
            $out['would_mark']++;
        } else {
            $out['marked']++;
        }
    }
    $public = [];
    foreach ($plan as $row) {
        $public[] = [
            'thread_id' => $row['thread_id'],
            'idem' => $row['idem'],
            'result' => $row['result'],
        ];
    }
    $out['rows'] = jeab_quote_outbox_manual_public($public);
    if ($dry) {
        $out['ok'] = true;

        return $out;
    }
    $todo = [];
    foreach ($plan as $row) {
        if ($row['result'] === 'marked') {
            $todo[] = $row;
        }
    }
    if ($todo === []) {
        $out['ok'] = true;

        return $out;
    }
    $pdo = jeab_quote_outbox_pdo();
    if (!$pdo instanceof PDO) {
        $out['ok'] = false;
        $out['error'] = 'sql';
        $out['marked'] = 0;

        return $out;
    }
    $undoId = bin2hex(random_bytes(8));
    try {
        jeab_quote_outbox_locked($pdo, static function (PDO $pdo) use ($todo, $undoId): void {
            $ins = $pdo->prepare(
                'INSERT INTO quote_outbox_manual_undo (undo_id, created_at, idem_key, thread_id, manual_send) VALUES (?, ?, ?, ?, ?)'
            );
            $up = $pdo->prepare(
                "UPDATE quote_outbox SET manual_send = 1, updated_at = ? WHERE idem_key = ? AND status IN ('pending', 'retry')"
            );
            $now = jeab_quote_outbox_now();
            foreach ($todo as $row) {
                $ins->execute([$undoId, $now, $row['idem'], $row['thread_id'], (int) $row['prev']]);
                $up->execute([$now, $row['idem']]);
            }
        });
    } catch (Throwable) {
        $out['ok'] = false;
        $out['error'] = 'sql';
        $out['marked'] = 0;
        $out['undo_id'] = '';

        return $out;
    }
    if (jeab_quote_queue_backend() !== 'sql' && function_exists('jeab_inbox_quote_queue_update')) {
        $flags = [];
        foreach ($todo as $row) {
            $flags[$row['thread_id']] = 1;
        }
        jeab_inbox_quote_queue_update(static function (array $all) use ($flags): ?array {
            $changed = false;
            foreach ($flags as $tid => $_flag) {
                if (!isset($all[$tid]) || !is_array($all[$tid])) {
                    continue;
                }
                $status = (string) ($all[$tid]['status'] ?? 'pending');
                if ($status !== '' && !in_array($status, ['pending', 'retry'], true)) {
                    continue;
                }
                $all[$tid]['manual_send'] = 1;
                $changed = true;
            }

            return $changed ? $all : null;
        });
    }
    unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
    $out['ok'] = true;
    $out['read_only'] = false;
    $out['undo_id'] = $undoId;

    return $out;
}

/**
 * JSON-ånger. Sending och sent rörs inte. Bara ett värde som faktiskt byts räknas.
 *
 * @param list<array{id:int,thread_id:string,idem:string,manual_send:int}> $rows
 * @return array<int, array{id:int,thread_id:string,idem:string,manual_send:int}>
 */
function jeab_quote_outbox_undo_json(array $rows): array
{
    if (jeab_quote_queue_backend() === 'sql' || !function_exists('jeab_inbox_quote_queue_update')) {
        return [];
    }
    $hit = [];
    jeab_inbox_quote_queue_update(static function (array $all) use ($rows, &$hit): ?array {
        $changed = false;
        foreach ($rows as $row) {
            $tid = (string) ($row['thread_id'] ?? '');
            if ($tid === '' || !isset($all[$tid]) || !is_array($all[$tid])) {
                continue;
            }
            $status = (string) ($all[$tid]['status'] ?? 'pending');
            if ($status === '') {
                $status = 'pending';
            }
            if (!in_array($status, ['pending', 'retry'], true)) {
                continue;
            }
            $next = (int) ($row['manual_send'] ?? 0) === 1 ? 1 : 0;
            $cur = (int) ($all[$tid]['manual_send'] ?? 0) === 1 ? 1 : 0;
            if ($cur === $next) {
                continue;
            }
            $all[$tid]['manual_send'] = $next;
            $hit[(int) ($row['id'] ?? 0)] = $row;
            $changed = true;
        }

        return $changed ? $all : null;
    });

    return $hit;
}

/**
 * inbox.outbox_manual_mark_undo. dry är standard. dry=0 kräver confirm=undo.
 * Återställer manual_send från raden som skrevs före ändringen.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_inbox_outbox_manual_mark_undo(array $in): array
{
    $dry = true;
    if (array_key_exists('dry', $in) && ($in['dry'] === 0 || $in['dry'] === '0' || $in['dry'] === false)) {
        $dry = false;
    }
    $out = [
        'ok' => false,
        'dry' => $dry ? 1 : 0,
        'read_only' => true,
        'sent' => 0,
        'would_send' => false,
        'error' => '',
        'restored' => 0,
        'would_restore' => 0,
        'rows' => [],
        'undo_id' => '',
    ];
    $undoId = trim((string) ($in['undo_id'] ?? $in['batch'] ?? ''));
    if ($undoId === '' || str_contains($undoId, '@') || strlen($undoId) > 32) {
        $out['error'] = 'undo_id';

        return $out;
    }
    $out['undo_id'] = $undoId;
    if (!$dry && (string) ($in['confirm'] ?? '') !== 'undo') {
        $out['error'] = 'confirm';

        return $out;
    }
    $pdo = jeab_quote_outbox_pdo();
    if (!$pdo instanceof PDO) {
        $out['error'] = 'sql';

        return $out;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT id, idem_key, thread_id, manual_send FROM quote_outbox_manual_undo WHERE undo_id = ? AND undone_at IS NULL ORDER BY id ASC'
        );
        $stmt->execute([$undoId]);
        $fetched = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $out['error'] = 'sql';

        return $out;
    }
    $rows = [];
    foreach ($fetched as $row) {
        if (!is_array($row)) {
            continue;
        }
        $tid = (string) ($row['thread_id'] ?? '');
        $idem = (string) ($row['idem_key'] ?? '');
        if ($tid === '' || $idem === '' || str_contains($tid, '@') || str_contains($idem, '@')) {
            continue;
        }
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'thread_id' => $tid,
            'idem' => $idem,
            'manual_send' => (int) ($row['manual_send'] ?? 0) === 1 ? 1 : 0,
        ];
    }
    $public = [];
    foreach ($rows as $row) {
        $public[] = [
            'thread_id' => $row['thread_id'],
            'idem' => $row['idem'],
            'result' => $dry ? 'would_restore' : 'restored',
        ];
    }
    $out['rows'] = jeab_quote_outbox_manual_public($public);
    if ($dry) {
        $out['ok'] = true;
        $out['would_restore'] = count($out['rows']);

        return $out;
    }
    if ($rows === []) {
        $out['ok'] = true;

        return $out;
    }
    $changed = [];
    try {
        jeab_quote_outbox_locked($pdo, static function (PDO $pdo) use ($rows, &$changed): void {
            $up = $pdo->prepare("UPDATE quote_outbox SET manual_send = ?, updated_at = ? WHERE idem_key = ? AND status IN ('pending', 'retry')");
            $done = $pdo->prepare('UPDATE quote_outbox_manual_undo SET undone_at = ? WHERE id = ? AND undone_at IS NULL');
            $now = jeab_quote_outbox_now();
            foreach ($rows as $row) {
                $up->execute([(int) $row['manual_send'], $now, $row['idem']]);
                if ($up->rowCount() < 1) {
                    continue;
                }
                $done->execute([$now, (int) $row['id']]);
                $changed[(int) $row['id']] = $row;
            }
        });
    } catch (Throwable) {
        $out['error'] = 'sql';
        $out['restored'] = 0;

        return $out;
    }
    $jsonOnly = [];
    foreach (jeab_quote_outbox_undo_json($rows) as $id => $row) {
        if (isset($changed[$id])) {
            continue;
        }
        $changed[$id] = $row;
        $jsonOnly[] = (int) $id;
    }
    if ($jsonOnly !== []) {
        try {
            jeab_quote_outbox_locked($pdo, static function (PDO $pdo) use ($jsonOnly): void {
                $done = $pdo->prepare('UPDATE quote_outbox_manual_undo SET undone_at = ? WHERE id = ? AND undone_at IS NULL');
                $now = jeab_quote_outbox_now();
                foreach ($jsonOnly as $id) {
                    $done->execute([$now, $id]);
                }
            });
        } catch (Throwable) {
            $out['error'] = 'sql';
            $out['restored'] = 0;

            return $out;
        }
    }
    unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
    $public = [];
    foreach ($rows as $row) {
        if (!isset($changed[(int) $row['id']])) {
            continue;
        }
        $public[] = [
            'thread_id' => $row['thread_id'],
            'idem' => $row['idem'],
            'result' => 'restored',
        ];
    }
    $out['rows'] = jeab_quote_outbox_manual_public($public);
    $out['ok'] = true;
    $out['read_only'] = false;
    $out['restored'] = count($changed);

    return $out;
}
