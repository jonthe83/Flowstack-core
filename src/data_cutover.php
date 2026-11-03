<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Skrivvägar när data_backend är satt.
 * Utan explicit flagga är båda falska och dagens kod körs.
 * mysql skriver SQL. json tvingar filen, även där live-MySQL redan skriver SQL.
 */

/**
 * Egen spärr för steg 4: på som standard, även när data_backend inte är satt.
 * Av med $GLOBALS['_JEAB_STEG4_CUTOVER'] = false, env STEG4_CUTOVER=0, eller 0 i privat/flags.json.
 * Läser ingen databas. Rollback: sätt 0 i filen, så körs de tidigare skrivvägarna.
 */
function jeab_cutover_enabled(): bool
{
    return jeab_flag('STEG4_CUTOVER');
}

function jeab_cutover_explicit(): bool
{
    return jeab_cutover_enabled() && function_exists('jeab_data_backend_explicit') && jeab_data_backend_explicit();
}

function jeab_cutover_sql(string $type): bool
{
    if (!jeab_cutover_enabled() || !function_exists('jeab_data_storage')) {
        return false;
    }
    if (function_exists('jeab_data_type_forced') && jeab_data_type_forced('sql', $type)) {
        return jeab_data_storage($type, 'json') === 'sql';
    }
    if (!jeab_cutover_explicit()) {
        return false;
    }

    return jeab_data_storage($type, 'json') === 'sql';
}

function jeab_cutover_forces_json(string $type): bool
{
    if (!jeab_cutover_explicit() || !function_exists('jeab_data_storage')) {
        return false;
    }

    return jeab_data_storage($type, 'json') !== 'sql';
}

function jeab_cutover_log_pdo_missing(): bool
{
    return !function_exists('jeab_log_pdo') || !jeab_log_pdo() instanceof PDO;
}

/** @return array<string, array<string, mixed>>|null */
function jeab_cutover_read_rows(string $type): ?array
{
    if (!jeab_cutover_sql($type)) {
        return null;
    }
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    $module = function_exists('jeab_migrate_find') ? jeab_migrate_find($type) : null;
    if (!$pdo instanceof PDO || !is_array($module) || !empty($module['missing'])) {
        return null;
    }
    try {
        $selected = jeab_migrate_select_owned($pdo, $module, []);
    } catch (Throwable) {
        return null;
    }

    return jeab_migrate_key_rows($selected, (string) ($module['primary_key'] ?? ''));
}

/** @param list<string> $keys */
function jeab_cutover_delete_keys(PDO $pdo, array $module, array $keys): void
{
    $keys = array_values(array_filter(array_map('strval', $keys), static fn(string $key): bool => $key !== ''));
    if ($keys === []) {
        return;
    }
    $rawTable = (string) ($module['table'] ?? '');
    if ($rawTable === 'purchases' || $rawTable === 'orders') {
        if (!function_exists('jeab_purchase_delete_guard')) {
            throw new RuntimeException('Ordern kan bara raderas via Radera.');
        }
        jeab_purchase_delete_guard();
    }
    if ($rawTable === 'reminders') {
        if (!function_exists('jeab_reminder_delete_guard')) {
            throw new RuntimeException('Påminnelsen kan bara raderas via Radera.');
        }
        jeab_reminder_delete_guard();
    }
    if ($rawTable === 'inkorg_threads' || $rawTable === 'inbox_threads') {
        if (!function_exists('jeab_thread_delete_guard')) {
            throw new RuntimeException('Ärendet kan bara raderas via Radera.');
        }
        jeab_thread_delete_guard();
    }
    $table = jeab_migrate_ident($pdo, (string) ($module['table'] ?? ''));
    $pk = jeab_migrate_ident($pdo, (string) ($module['primary_key'] ?? ''));
    foreach (array_chunk($keys, 200) as $chunk) {
        $marks = implode(', ', array_fill(0, count($chunk), '?'));
        $pdo->prepare('DELETE FROM ' . $table . ' WHERE ' . $pk . ' IN (' . $marks . ')')->execute($chunk);
    }
}

/** @param array<string, mixed> $source */
function jeab_data_put_source(string $type, array $source, bool $dropMissing = false): void
{
    $module = jeab_migrate_find($type);
    if (!is_array($module) || !empty($module['missing'])) {
        throw new RuntimeException('Typen kan inte skrivas.');
    }
    $rowsFn = (string) ($module['rows'] ?? '');
    if (!function_exists($rowsFn)) {
        throw new RuntimeException('Typen kan inte skrivas.');
    }
    $rows = $rowsFn($source);
    if (!is_array($rows)) {
        throw new RuntimeException('Typen kan inte skrivas.');
    }
    $sql = function_exists('jeab_data_storage') && jeab_data_storage($type, 'json') === 'sql';
    if (!$dropMissing || !$sql || !function_exists('jeab_pdo_transaction')) {
        jeab_data_write($type, $rows);
        if (!$dropMissing) {
            return;
        }
        $pdo = jeab_data_pdo();
        if (!$pdo instanceof PDO) {
            return;
        }
        jeab_data_put_drop_absent($pdo, $module, $rows);

        return;
    }
    $pdo = jeab_data_pdo();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Ingen SQL-databas.');
    }
    jeab_data_put_source_locked($pdo, $type, $module, $rows);
}

/**
 * Före-bilden från den pågående jeab_cutover_locked-cykeln.
 * null betyder att anropet inte är återinträde och ska ta en egen bild.
 *
 * @return array<string, true>|null
 */
function jeab_data_put_reentry_before(string $type): ?array
{
    if ((int) ($GLOBALS['_JEAB_PUT_LOCK_DEPTH'][$type] ?? 0) < 1) {
        return null;
    }
    $stack = $GLOBALS['_JEAB_PUT_BEFORE'][$type] ?? null;
    if (!is_array($stack) || $stack === []) {
        return [];
    }
    $top = $stack[count($stack) - 1];

    return is_array($top) ? $top : [];
}

/**
 * Låst radvis upsert. Raderar bara nycklar som fanns i före-bilden och som
 * anroparen inte har kvar. En rad som kommer till efter före-bilden får stå.
 * MySQL tar GET_LOCK('jeab_put:'.typ, 5) och släpper i finally.
 * Inifrån jeab_cutover_locked tas låset inte igen. Före-bilden är den som
 * lästes när cykeln började, inte en ny bild vid själva skrivningen.
 *
 * @param array<string, mixed> $module
 * @param list<array<string, mixed>> $rows
 */
function jeab_data_put_source_locked(PDO $pdo, string $type, array $module, array $rows): void
{
    if (function_exists('jeab_data_write_guard')) {
        jeab_data_write_guard();
    }
    $keep = jeab_data_put_keep_keys($module, $rows);
    $ensured = !function_exists('jeab_pdo_tx_busy') || !jeab_pdo_tx_busy($pdo);
    if ($ensured) {
        jeab_migrate_ensure($pdo, $module);
    }
    $mysql = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $reentry = (int) ($GLOBALS['_JEAB_PUT_LOCK_DEPTH'][$type] ?? 0) > 0;
    $lockName = jeab_data_put_lock_name($type);
    $locked = false;
    try {
        if ($mysql && !$reentry) {
            $locked = jeab_data_put_lock($pdo, $lockName);
            if (!$locked) {
                throw new RuntimeException('Skrivningen kunde inte låsas.');
            }
        }
        jeab_pdo_transaction($pdo, static function (PDO $pdo) use ($type, $module, $rows, $keep, $ensured, $reentry): void {
            if (!$ensured) {
                jeab_migrate_ensure($pdo, $module);
            }
            $saved = $reentry ? jeab_data_put_reentry_before($type) : null;
            $before = is_array($saved) ? $saved : jeab_migrate_owned_keys($pdo, $module);
            $writeFn = (string) ($module['write'] ?? '');
            if ($writeFn !== '' && function_exists($writeFn)) {
                jeab_data_write($type, $rows);
            } else {
                $list = array_is_list($rows) ? $rows : array_values($rows);
                jeab_migrate_upsert($pdo, $module, $list);
            }
            // Bara tester. Körs efter före-bilden, så en rad som läggs till här inte raderas.
            $mid = $GLOBALS['_JEAB_PUT_SOURCE_AFTER_BEFORE'] ?? null;
            if (is_callable($mid)) {
                $mid($pdo);
            }
            $drop = [];
            foreach ($before as $key => $_own) {
                $key = (string) $key;
                if ($key !== '' && !isset($keep[$key])) {
                    $drop[] = $key;
                }
            }
            jeab_cutover_delete_keys($pdo, $module, $drop);
        });
    } finally {
        if ($locked) {
            jeab_data_put_unlock($pdo, $lockName);
        }
    }
}

function jeab_data_put_lock_name(string $type): string
{
    $type = strtolower($type);
    if (preg_match('/\A[a-z0-9_]{1,40}\z/', $type) !== 1) {
        $type = 'type';
    }

    return 'jeab_put:' . $type;
}

function jeab_data_put_lock(PDO $pdo, string $name): bool
{
    try {
        $stmt = $pdo->query('SELECT GET_LOCK(' . $pdo->quote($name) . ', 5)');
        $got = $stmt instanceof PDOStatement ? (int) $stmt->fetchColumn() : 0;
    } catch (Throwable) {
        return false;
    }

    return $got === 1;
}

function jeab_data_put_unlock(PDO $pdo, string $name): void
{
    try {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($name) . ')');
    } catch (Throwable) {
    }
}

/**
 * Källans dokumentform, samma som put_source tar emot. null = läsningen föll.
 *
 * @return array<string, mixed>|null
 */
function jeab_cutover_read_source(string $type): ?array
{
    if ($type === 'external_queue') {
        $jobs = jeab_cutover_external_jobs();

        return is_array($jobs) ? ['jobs' => $jobs] : null;
    }
    if ($type === 'notiser') {
        $store = jeab_cutover_notiser_store();

        return is_array($store) ? $store : null;
    }
    if ($type === 'inkop_mallar') {
        $rows = jeab_cutover_read_rows('inkop_mallar');
        if (!is_array($rows) || !function_exists('jeab_inkop_load')) {
            return null;
        }

        return jeab_inkop_load();
    }
    if ($type === 'webshop_orders') {
        $index = function_exists('jeab_cutover_webshop_index') ? jeab_cutover_webshop_index() : null;

        return is_array($index) ? $index : null;
    }
    if ($type === 'inkorg_views') {
        $views = jeab_cutover_views_list();

        return is_array($views) ? ['views' => $views] : null;
    }
    if ($type === 'app_errors_webhook') {
        $maps = jeab_cutover_webhook_maps();

        return is_array($maps) ? ['seen' => (array) ($maps['seen'] ?? []), 'resolved' => (array) ($maps['resolved'] ?? [])] : null;
    }
    if ($type === 'login_throttle') {
        if (jeab_cutover_read_rows('login_throttle') === null) {
            return null;
        }

        return jeab_cutover_throttle_load();
    }
    if ($type === 'inbox_sent_new') {
        $rows = jeab_cutover_read_rows('inbox_sent_new');
        if ($rows === null) {
            return null;
        }
        $sent = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $sent[] = jeab_inbox_sent_new_log($row);
            }
        }

        return ['sent' => array_values($sent)];
    }

    return null;
}

/**
 * Läs, ändra och skriv under samma GET_LOCK och transaktion som put_source.
 * $fn får källan som den ser ut inne i låset och returnerar den nya källan.
 * null betyder att $fn redan skrivit själv, så put_source inte ersätter allt.
 * Återinträde tar inte låset en gång till.
 *
 * @param callable(array<string, mixed>): mixed $fn
 */
function jeab_cutover_locked(string $type, callable $fn): mixed
{
    if (!function_exists('jeab_migrate_find') || !function_exists('jeab_pdo_transaction')) {
        throw new RuntimeException('Typen kan inte skrivas.');
    }
    $module = jeab_migrate_find($type);
    if (!is_array($module) || !empty($module['missing'])) {
        throw new RuntimeException('Typen kan inte skrivas.');
    }
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Ingen SQL-databas.');
    }
    if (function_exists('jeab_data_write_guard')) {
        jeab_data_write_guard();
    }
    $depth = (int) ($GLOBALS['_JEAB_PUT_LOCK_DEPTH'][$type] ?? 0);
    $ensured = $depth === 0 && (!function_exists('jeab_pdo_tx_busy') || !jeab_pdo_tx_busy($pdo));
    if ($ensured) {
        jeab_migrate_ensure($pdo, $module);
    }
    $mysql = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $lockName = jeab_data_put_lock_name($type);
    $locked = false;
    try {
        if ($mysql && $depth === 0) {
            $locked = jeab_data_put_lock($pdo, $lockName);
            if (!$locked) {
                throw new RuntimeException('Skrivningen kunde inte låsas.');
            }
        }

        return jeab_pdo_transaction($pdo, static function (PDO $pdo) use ($type, $module, $fn, $ensured, $depth): mixed {
            if (!$ensured && $depth === 0) {
                jeab_migrate_ensure($pdo, $module);
            }
            $before = jeab_migrate_owned_keys($pdo, $module);
            $stack = $GLOBALS['_JEAB_PUT_BEFORE'][$type] ?? [];
            if (!is_array($stack)) {
                $stack = [];
            }
            $stack[] = $before;
            $GLOBALS['_JEAB_PUT_BEFORE'][$type] = $stack;
            $GLOBALS['_JEAB_PUT_LOCK_DEPTH'][$type] = $depth + 1;
            try {
                $source = jeab_cutover_read_source($type);
                if (!is_array($source)) {
                    throw new RuntimeException('Källan kan inte läsas.');
                }
                $next = $fn($source);
                if ($type === 'login_throttle' && is_array($next) && $next === [] && $before !== []) {
                    $live = false;
                    $now = time();
                    foreach ($source as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $until = (int) ($row['until'] ?? 0);
                        if ((int) ($row['fails'] ?? 0) > 0 && ($until === 0 || $until >= $now - 86400)) {
                            $live = true;
                            break;
                        }
                    }
                    if ($live) {
                        throw new RuntimeException('Räknarna lämnas.');
                    }
                }
                if (is_array($next)) {
                    jeab_data_put_source($type, $next, true);
                }

                return $next;
            } finally {
                $stack = $GLOBALS['_JEAB_PUT_BEFORE'][$type] ?? [];
                if (is_array($stack) && $stack !== []) {
                    array_pop($stack);
                }
                if (!is_array($stack) || $stack === []) {
                    unset($GLOBALS['_JEAB_PUT_BEFORE'][$type]);
                } else {
                    $GLOBALS['_JEAB_PUT_BEFORE'][$type] = $stack;
                }
                if ($depth === 0) {
                    unset($GLOBALS['_JEAB_PUT_LOCK_DEPTH'][$type]);
                } else {
                    $GLOBALS['_JEAB_PUT_LOCK_DEPTH'][$type] = $depth;
                }
            }
        });
    } finally {
        if ($locked) {
            jeab_data_put_unlock($pdo, $lockName);
        }
    }
}

/**
 * @param array<string, mixed> $module
 * @param list<array<string, mixed>> $rows
 * @return array<string, true>
 */
function jeab_data_put_keep_keys(array $module, array $rows): array
{
    $pk = (string) ($module['primary_key'] ?? '');
    $keep = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = trim((string) ($row[$pk] ?? ''));
        if ($key !== '') {
            $keep[$key] = true;
        }
    }

    return $keep;
}

/**
 * @param array<string, mixed> $module
 * @param list<array<string, mixed>> $rows
 */
function jeab_data_put_drop_absent(PDO $pdo, array $module, array $rows): void
{
    $keep = jeab_data_put_keep_keys($module, $rows);
    $drop = [];
    foreach (jeab_migrate_owned_keys($pdo, $module) as $key => $_own) {
        if (!isset($keep[(string) $key])) {
            $drop[] = (string) $key;
        }
    }
    jeab_cutover_delete_keys($pdo, $module, $drop);
}

function jeab_cutover_doc_fetch_key(string $type, string $key): ?array
{
    if (!jeab_cutover_sql($type) || $key === '') {
        return null;
    }
    $pdo = jeab_data_pdo();
    $module = jeab_migrate_find($type);
    if (!$pdo instanceof PDO || !is_array($module)) {
        return null;
    }
    $table = (string) ($module['table'] ?? '');
    $pk = (string) ($module['primary_key'] ?? '');
    if ($table === '' || $pk === '') {
        return null;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT payload FROM ' . jeab_migrate_ident($pdo, $table)
            . ' WHERE ' . jeab_migrate_ident($pdo, $pk) . ' = ?'
        );
        $stmt->execute([$key]);
        $raw = $stmt->fetchColumn();
    } catch (Throwable) {
        return null;
    }
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

function jeab_cutover_doc_fetch(string $type): ?array
{
    return jeab_cutover_doc_fetch_key($type, $type);
}

/** @param array<string, mixed> $doc */
function jeab_cutover_doc_put(string $type, array $doc): void
{
    if (!jeab_cutover_sql($type)) {
        return;
    }
    $module = jeab_migrate_find($type);
    $rowsFn = is_array($module) ? (string) ($module['rows'] ?? '') : '';
    if (!function_exists($rowsFn)) {
        throw new RuntimeException('Typen kan inte skrivas.');
    }
    $rows = $rowsFn($doc);
    jeab_data_write($type, is_array($rows) ? $rows : []);
}

function jeab_cutover_graph_id(string $path): string
{
    return match (basename($path)) {
        'graph-state.json' => 'graph_state',
        'graph-daily.json' => 'graph_daily',
        'graph-poll.json' => 'graph_poll',
        default => '',
    };
}

function jeab_cutover_graph_read(string $path): ?array
{
    $id = jeab_cutover_graph_id($path);
    if ($id === '') {
        return null;
    }

    return jeab_cutover_doc_fetch($id);
}

/** @param array<string, mixed> $doc */
function jeab_cutover_graph_write(string $path, array $doc): bool
{
    $id = jeab_cutover_graph_id($path);
    if ($id === '' || !jeab_cutover_sql($id)) {
        return false;
    }
    try {
        jeab_cutover_doc_put($id, $doc);
    } catch (Throwable) {
        return false;
    }

    return true;
}

function jeab_cutover_log_append(string $type, string $line): bool
{
    // Avsiktligt. jeab_log_pdo() (MySQL log_store) är den primära SQL-vägen,
    // och anroparen har redan försökt den. Här skrivs raden bara när log-PDO
    // saknas (jeab_cutover_log_pdo_missing() är sant), så samma rad inte
    // dubbleras och migrate-schemat för log_lines inte krockar med log_store.
    // Villkoret är alltså inte inverterat: !jeab_cutover_log_pdo_missing()
    // betyder att log-PDO finns, och då ska den här vägen inte skriva.
    if (!jeab_cutover_sql($type) || !jeab_cutover_log_pdo_missing()) {
        return false;
    }
    $module = jeab_migrate_find($type);
    $rowsFn = is_array($module) ? (string) ($module['rows'] ?? '') : '';
    if (!function_exists($rowsFn)) {
        return false;
    }
    $mapped = $rowsFn([$line]);
    if (!is_array($mapped) || $mapped === []) {
        return false;
    }
    try {
        jeab_data_write($type, $mapped);
    } catch (Throwable) {
        return false;
    }

    return true;
}

/** @param array<string, mixed> $row */
function jeab_cutover_who_diag_sql(array $row): bool
{
    if (jeab_cutover_forces_json('who_diag') || !jeab_cutover_log_pdo_missing()) {
        return false;
    }
    $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($line) || !jeab_cutover_log_append('who_diag', $line)) {
        return false;
    }
    jeab_cutover_who_diag_prune();

    return true;
}

function jeab_cutover_who_diag_prune(): void
{
    $pdo = jeab_data_pdo();
    $module = jeab_migrate_find('who_diag');
    if (!$pdo instanceof PDO || !is_array($module)) {
        return;
    }
    try {
        $rows = jeab_migrate_select_owned($pdo, $module, []);
    } catch (Throwable) {
        return;
    }
    $keepSeconds = defined('JEAB_WHO_DIAG_KEEP') ? (int) JEAB_WHO_DIAG_KEEP : 3 * 24 * 60 * 60;
    $cut = date('Y-m-d H:i:s', time() - $keepSeconds);
    $alive = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $at = (string) ($row['at'] ?? '');
        if ($at !== '' && $at < $cut) {
            continue;
        }
        $alive[] = $row;
    }
    if (count($alive) > 3000) {
        usort($alive, static fn(array $a, array $b): int => strcmp((string) ($a['at'] ?? ''), (string) ($b['at'] ?? '')));
        $alive = array_slice($alive, -3000);
    }
    $keep = [];
    foreach ($alive as $row) {
        $key = (string) ($row['line_key'] ?? '');
        if ($key !== '') {
            $keep[$key] = true;
        }
    }
    $drop = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = (string) ($row['line_key'] ?? '');
        if ($key !== '' && !isset($keep[$key])) {
            $drop[] = $key;
        }
    }
    jeab_cutover_delete_keys($pdo, $module, $drop);
}

function jeab_cutover_who_pick_put(string $key, string $personId): bool
{
    if ($key === '' || $personId === '' || jeab_cutover_forces_json('who_picks') || !jeab_cutover_log_pdo_missing()) {
        return false;
    }
    if (!jeab_cutover_sql('who_picks') || !function_exists('jeab_migrate_who_picks_rows')) {
        return false;
    }
    $rows = jeab_migrate_who_picks_rows([$key => ['person' => $personId, 'at' => time()]]);
    try {
        jeab_data_write('who_picks', $rows);
    } catch (Throwable) {
        return false;
    }

    return true;
}

/** @return array<string, array{person:string,at:int}>|null */
function jeab_cutover_who_picks_map(): ?array
{
    if (jeab_cutover_forces_json('who_picks') || !jeab_cutover_log_pdo_missing()) {
        return null;
    }
    $rows = jeab_cutover_read_rows('who_picks');
    if ($rows === null) {
        return null;
    }
    $out = [];
    foreach ($rows as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        $out[(string) $key] = [
            'person' => (string) ($row['person'] ?? ''),
            'at' => (int) ($row['picked_at'] ?? 0),
        ];
    }

    return $out;
}

/** @return array<string, array<string, mixed>>|null */
function jeab_cutover_webshop_index(): ?array
{
    if (jeab_cutover_forces_json('webshop_orders') || !jeab_cutover_log_pdo_missing()) {
        return null;
    }
    $rows = jeab_cutover_read_rows('webshop_orders');
    if ($rows === null) {
        return null;
    }
    $out = [];
    foreach ($rows as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        $data = json_decode((string) ($row['payload'] ?? ''), true);
        if (is_array($data)) {
            $out[(string) $key] = $data;
        }
    }

    return $out;
}

/** @return list<array<string, mixed>>|null */
function jeab_cutover_external_jobs(): ?array
{
    if (!jeab_cutover_sql('external_queue')) {
        return null;
    }
    $rows = jeab_cutover_read_rows('external_queue');
    if ($rows === null) {
        return null;
    }
    $jobs = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        if (!is_array($payload)) {
            continue;
        }
        $payload['_seq'] = (int) ($row['seq'] ?? 0);
        $payload['attempts'] = (int) ($row['attempts'] ?? 0);
        $jobs[] = $payload;
    }
    usort($jobs, static fn(array $a, array $b): int => ((int) ($a['_seq'] ?? 0)) <=> ((int) ($b['_seq'] ?? 0)));
    foreach ($jobs as $i => $job) {
        unset($jobs[$i]['_seq']);
    }

    return $jobs;
}

function jeab_cutover_external_skip_locked(?bool $set = null): bool
{
    static $ok = true;
    if ($set !== null) {
        $ok = $set;
    }

    return $ok;
}

/**
 * sqlite: BEGIN IMMEDIATE. MySQL: jeab_pdo_transaction. Redan öppen transaktion återanvänds.
 *
 * @template T
 * @param callable(PDO): T $fn
 * @return T
 */
function jeab_cutover_external_tx(PDO $pdo, callable $fn): mixed
{
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return $fn($pdo);
    }
    if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' && function_exists('jeab_pdo_begin_immediate')) {
        jeab_pdo_begin_immediate($pdo);
        try {
            $result = $fn($pdo);
            jeab_pdo_tx_finish($pdo, true);

            return $result;
        } catch (Throwable $e) {
            try {
                jeab_pdo_tx_finish($pdo, false);
            } catch (Throwable) {
            }
            throw $e;
        }
    }
    if (function_exists('jeab_pdo_transaction')) {
        return jeab_pdo_transaction($pdo, $fn);
    }
    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();

        return $result;
    } catch (Throwable $e) {
        try {
            $pdo->rollBack();
        } catch (Throwable) {
        }
        throw $e;
    }
}

function jeab_cutover_external_ensure(PDO $pdo, array $module): void
{
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    if (function_exists('jeab_migrate_ensure')) {
        jeab_migrate_ensure($pdo, $module);
    }
}

function jeab_cutover_external_now(): string
{
    return function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
}

function jeab_cutover_external_lease_until(string $now): string
{
    $ts = strtotime($now);

    return date('Y-m-d H:i:s', ($ts !== false ? $ts : time()) + 300);
}

/**
 * @param array<string, mixed> $row
 */
function jeab_cutover_external_drop_log(array $row): void
{
    $kind = trim((string) ($row['kind'] ?? ''));
    $message = 'extern kö: släppt efter 3 försök';
    if ($kind !== '') {
        $message .= ' (' . $kind . ')';
    }
    if (function_exists('jeab_log_app_error')) {
        jeab_log_app_error('external_queue', $message, ['no_webhook' => true]);

        return;
    }
    error_log($message);
}

/**
 * Rader med attempts >= 3 och utgången/ingen lease tas bort.
 *
 * @param array<string, mixed> $module
 * @return list<array<string, mixed>>
 */
function jeab_cutover_external_drop_exhausted(PDO $pdo, array $module, string $now): array
{
    $table = jeab_migrate_ident($pdo, (string) ($module['table'] ?? 'external_queue_jobs'));
    $claimed = jeab_migrate_ident($pdo, 'claimed_until');
    $attempts = jeab_migrate_ident($pdo, 'attempts');
    $stmt = $pdo->prepare(
        'SELECT job_key, kind, attempts FROM ' . $table
        . ' WHERE ' . $attempts . ' >= 3 AND (' . $claimed . ' IS NULL OR ' . $claimed . ' <= ?)'
    );
    $stmt->execute([$now]);
    $gone = [];
    $drop = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = trim((string) ($row['job_key'] ?? ''));
        if ($key === '') {
            continue;
        }
        $gone[] = $row;
        $drop[] = $key;
    }
    jeab_cutover_delete_keys($pdo, $module, $drop);

    return $gone;
}

/** @param array<string, mixed> $module */
function jeab_cutover_external_trim(PDO $pdo, array $module, int $limit = 40): void
{
    $table = jeab_migrate_ident($pdo, (string) ($module['table'] ?? 'external_queue_jobs'));
    $count = (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    if ($count <= $limit) {
        return;
    }
    $extra = $count - $limit;
    $claimed = jeab_migrate_ident($pdo, 'claimed_until');
    $stmt = $pdo->prepare(
        'SELECT ' . jeab_migrate_ident($pdo, 'job_key') . ' FROM ' . $table
        . ' WHERE ' . $claimed . ' IS NULL OR ' . $claimed . ' <= ?'
        . ' ORDER BY seq ASC, job_key ASC LIMIT ' . $extra
    );
    $stmt->execute([jeab_cutover_external_now()]);
    $drop = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
        $key = trim((string) $key);
        if ($key !== '') {
            $drop[] = $key;
        }
    }
    jeab_cutover_delete_keys($pdo, $module, $drop);
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_cutover_external_row_job(array $row): array
{
    $decoded = json_decode((string) ($row['payload'] ?? ''), true);
    if (!is_array($decoded)) {
        return [
            'kind' => (string) ($row['kind'] ?? ''),
            'payload' => [],
            'at' => 0,
        ];
    }
    unset($decoded['seq'], $decoded['_seq'], $decoded['attempts']);

    return $decoded;
}

/**
 * @param array<string, mixed> $row
 */
function jeab_cutover_external_claim_token(array $row): string
{
    return trim((string) ($row['claim_token'] ?? ''));
}

function jeab_cutover_external_token_miss(string $op): void
{
    error_log('extern kö: ' . $op . ' utan matchande claim_token');
}

/**
 * Tar högst $limit äldsta lediga jobb (ORDER BY seq) med lease. Raden raderas
 * inte: claimed_until = nu + 300 s, slumpad claim_token och attempts+1.
 * MySQL: SELECT … FOR UPDATE [SKIP LOCKED]. sqlite: BEGIN IMMEDIATE.
 *
 * @return list<array<string, mixed>>
 */
function jeab_cutover_external_claim(int $limit = 3): array
{
    if (!jeab_cutover_sql('external_queue')) {
        return [];
    }
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    $module = function_exists('jeab_migrate_find') ? jeab_migrate_find('external_queue') : null;
    if (!$pdo instanceof PDO || !is_array($module) || !empty($module['missing'])) {
        return [];
    }
    if (function_exists('jeab_data_write_guard')) {
        jeab_data_write_guard();
    }
    jeab_cutover_external_ensure($pdo, $module);
    $limit = max(1, min(40, $limit));
    $now = jeab_cutover_external_now();
    $until = jeab_cutover_external_lease_until($now);
    $dropped = [];
    $claimed = jeab_cutover_external_tx($pdo, static function (PDO $pdo) use ($module, $limit, $now, $until, &$dropped): array {
        $dropped = jeab_cutover_external_drop_exhausted($pdo, $module, $now);
        $table = jeab_migrate_ident($pdo, (string) ($module['table'] ?? 'external_queue_jobs'));
        $claimedCol = jeab_migrate_ident($pdo, 'claimed_until');
        $attemptsCol = jeab_migrate_ident($pdo, 'attempts');
        $tokenCol = jeab_migrate_ident($pdo, 'claim_token');
        $where = ' WHERE (' . $claimedCol . ' IS NULL OR ' . $claimedCol . ' <= ?) AND ' . $attemptsCol . ' < 3';
        $base = 'SELECT job_key, kind, queued_at, seq, payload, attempts, claimed_until, claim_token FROM ' . $table
            . $where
            . ' ORDER BY seq ASC, job_key ASC LIMIT ' . $limit;
        $mysql = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $skipLocked = jeab_cutover_external_skip_locked();
        $lock = $mysql ? ($skipLocked ? ' FOR UPDATE SKIP LOCKED' : ' FOR UPDATE') : '';
        $stmt = null;
        try {
            $stmt = $pdo->prepare($base . $lock);
            $stmt->execute([$now]);
        } catch (Throwable $e) {
            if ($mysql && $skipLocked) {
                jeab_cutover_external_skip_locked(false);
                $stmt = $pdo->prepare($base . ' FOR UPDATE');
                $stmt->execute([$now]);
            } else {
                throw $e;
            }
        }
        if (!$stmt instanceof PDOStatement) {
            return [];
        }
        $claimed = [];
        $upd = $pdo->prepare(
            'UPDATE ' . $table . ' SET ' . $claimedCol . ' = ?, ' . $attemptsCol . ' = ' . $attemptsCol . ' + 1, '
            . $tokenCol . ' = ? WHERE job_key = ?'
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = trim((string) ($row['job_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $token = bin2hex(random_bytes(16));
            $upd->execute([$until, $token, $key]);
            $row['attempts'] = (int) ($row['attempts'] ?? 0) + 1;
            $row['seq'] = (int) ($row['seq'] ?? 0);
            $row['claimed_until'] = $until;
            $row['claim_token'] = $token;
            $row['job'] = jeab_cutover_external_row_job($row);
            $claimed[] = $row;
        }

        return $claimed;
    });
    foreach ($dropped as $row) {
        if (is_array($row)) {
            jeab_cutover_external_drop_log($row);
        }
    }

    return $claimed;
}

/**
 * Lyckad körning: raden tas bort. Rör inte en rad vars claim_token inte matchar.
 *
 * @param array<string, mixed> $row
 */
function jeab_cutover_external_complete(array $row): void
{
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    $module = function_exists('jeab_migrate_find') ? jeab_migrate_find('external_queue') : null;
    $key = trim((string) ($row['job_key'] ?? ''));
    $token = jeab_cutover_external_claim_token($row);
    if (!$pdo instanceof PDO || !is_array($module) || $key === '') {
        return;
    }
    if ($token === '') {
        jeab_cutover_external_token_miss('complete');

        return;
    }
    jeab_cutover_external_tx($pdo, static function (PDO $pdo) use ($module, $key, $token): void {
        $table = jeab_migrate_ident($pdo, (string) ($module['table'] ?? 'external_queue_jobs'));
        $pk = jeab_migrate_ident($pdo, (string) ($module['primary_key'] ?? 'job_key'));
        $sql = 'DELETE FROM ' . $table . ' WHERE ' . $pk . ' = ? AND '
            . jeab_migrate_ident($pdo, 'claim_token') . ' = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$key, $token]);
        if ($stmt->rowCount() === 0) {
            jeab_cutover_external_token_miss('complete');
        }
    });
}

/**
 * Misslyckat jobb: claimed_until och claim_token nollställs (attempts redan ökad vid claim).
 * Efter 3 försök släpps raden. Rör inte en rad vars claim_token inte matchar.
 *
 * @param array<string, mixed> $row
 */
function jeab_cutover_external_retry(array $row): void
{
    $attempts = (int) ($row['attempts'] ?? 0);
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    $module = function_exists('jeab_migrate_find') ? jeab_migrate_find('external_queue') : null;
    $key = trim((string) ($row['job_key'] ?? ''));
    $token = jeab_cutover_external_claim_token($row);
    if (!$pdo instanceof PDO || !is_array($module) || $key === '') {
        return;
    }
    if ($token === '') {
        jeab_cutover_external_token_miss('retry');

        return;
    }
    if ($attempts >= 3) {
        $gone = false;
        jeab_cutover_external_tx($pdo, static function (PDO $pdo) use ($module, $key, $token, &$gone): void {
            $table = jeab_migrate_ident($pdo, (string) ($module['table'] ?? 'external_queue_jobs'));
            $pk = jeab_migrate_ident($pdo, (string) ($module['primary_key'] ?? 'job_key'));
            $sql = 'DELETE FROM ' . $table . ' WHERE ' . $pk . ' = ? AND '
                . jeab_migrate_ident($pdo, 'claim_token') . ' = ?';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$key, $token]);
            $gone = $stmt->rowCount() > 0;
        });
        if ($gone) {
            jeab_cutover_external_drop_log($row);
        } else {
            jeab_cutover_external_token_miss('retry');
        }

        return;
    }
    jeab_cutover_external_tx($pdo, static function (PDO $pdo) use ($module, $key, $token): void {
        $table = jeab_migrate_ident($pdo, (string) ($module['table'] ?? 'external_queue_jobs'));
        $pk = jeab_migrate_ident($pdo, (string) ($module['primary_key'] ?? 'job_key'));
        $claimed = jeab_migrate_ident($pdo, 'claimed_until');
        $tokenCol = jeab_migrate_ident($pdo, 'claim_token');
        $sql = 'UPDATE ' . $table . ' SET ' . $claimed . ' = NULL, ' . $tokenCol . ' = NULL WHERE ' . $pk
            . ' = ? AND ' . $tokenCol . ' = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$key, $token]);
        if ($stmt->rowCount() === 0) {
            jeab_cutover_external_token_miss('retry');
        }
    });
}

/**
 * Förlänger leasen 300 s. Villkorat på claim_token. Gör inget vid mismatch.
 *
 * @param array<string, mixed> $row
 */
function jeab_cutover_external_renew(array $row): ?string
{
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    $module = function_exists('jeab_migrate_find') ? jeab_migrate_find('external_queue') : null;
    $key = trim((string) ($row['job_key'] ?? ''));
    $token = jeab_cutover_external_claim_token($row);
    if (!$pdo instanceof PDO || !is_array($module) || $key === '') {
        return null;
    }
    if ($token === '') {
        jeab_cutover_external_token_miss('renew');

        return null;
    }
    $until = trim((string) ($row['claimed_until'] ?? ''));
    $next = jeab_cutover_external_lease_until($until !== '' ? $until : jeab_cutover_external_now());

    return jeab_cutover_external_tx($pdo, static function (PDO $pdo) use ($module, $key, $token, $next): ?string {
        $table = jeab_migrate_ident($pdo, (string) ($module['table'] ?? 'external_queue_jobs'));
        $pk = jeab_migrate_ident($pdo, (string) ($module['primary_key'] ?? 'job_key'));
        $claimed = jeab_migrate_ident($pdo, 'claimed_until');
        $tokenCol = jeab_migrate_ident($pdo, 'claim_token');
        $sql = 'UPDATE ' . $table . ' SET ' . $claimed . ' = ? WHERE ' . $pk . ' = ? AND ' . $tokenCol . ' = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$next, $key, $token]);
        if ($stmt->rowCount() === 0) {
            jeab_cutover_external_token_miss('renew');

            return null;
        }

        return $next;
    });
}

/**
 * En rad i external_queue. Ersätter inte övriga jobb.
 * Fler än 40: de äldsta seq tas bort, räknat inne i samma transaktion.
 * seq tilldelas under GET_LOCK (MySQL) eller BEGIN IMMEDIATE (sqlite).
 *
 * @param array<string, mixed> $job
 */
function jeab_cutover_external_insert(array $job): void
{
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    $module = function_exists('jeab_migrate_find') ? jeab_migrate_find('external_queue') : null;
    if (!$pdo instanceof PDO || !is_array($module) || !function_exists('jeab_migrate_external_queue_rows')) {
        throw new RuntimeException('Kön kan inte skrivas.');
    }
    $built = jeab_migrate_external_queue_rows(['jobs' => [$job]]);
    if ($built === [] || !is_array($built[0] ?? null)) {
        return;
    }
    $row = $built[0];
    if (function_exists('jeab_data_write_guard')) {
        jeab_data_write_guard();
    }
    jeab_cutover_external_ensure($pdo, $module);
    $mysql = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $reentry = (int) ($GLOBALS['_JEAB_PUT_LOCK_DEPTH']['external_queue'] ?? 0) > 0;
    $lockName = function_exists('jeab_data_put_lock_name') ? jeab_data_put_lock_name('external_queue') : '';
    $locked = false;
    try {
        if ($mysql && !$reentry && $lockName !== '' && function_exists('jeab_data_put_lock')) {
            $locked = jeab_data_put_lock($pdo, $lockName);
            if (!$locked) {
                throw new RuntimeException('Skrivningen kunde inte låsas.');
            }
        }
        jeab_cutover_external_tx($pdo, static function (PDO $pdo) use ($module, $row, $mysql): void {
            $table = jeab_migrate_ident($pdo, (string) ($module['table'] ?? 'external_queue_jobs'));
            $pk = jeab_migrate_ident($pdo, (string) ($module['primary_key'] ?? 'job_key'));
            $key = (string) ($row['job_key'] ?? '');
            $existing = null;
            if ($key !== '') {
                $sql = 'SELECT seq, attempts FROM ' . $table . ' WHERE ' . $pk . ' = ?';
                if ($mysql) {
                    $sql .= ' FOR UPDATE';
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$key]);
                $got = $stmt->fetch(PDO::FETCH_ASSOC);
                $existing = is_array($got) ? $got : null;
            }
            if (is_array($existing)) {
                $row['seq'] = (int) ($existing['seq'] ?? 0);
                $row['attempts'] = (int) ($existing['attempts'] ?? 0);
            } else {
                $max = (int) $pdo->query('SELECT COALESCE(MAX(seq), -1) FROM ' . $table)->fetchColumn();
                $row['seq'] = $max + 1;
                $row['attempts'] = (int) ($row['attempts'] ?? 0);
            }
            jeab_migrate_upsert($pdo, $module, [$row]);
            jeab_cutover_external_trim($pdo, $module, 40);
        });
    } finally {
        if ($locked && function_exists('jeab_data_put_unlock')) {
            jeab_data_put_unlock($pdo, $lockName);
        }
    }
}

/** @return array{items: array<string, array<string, mixed>>, pdf: array<string, array<string, mixed>>}|null */
function jeab_cutover_notiser_store(): ?array
{
    if (!jeab_cutover_sql('notiser')) {
        return null;
    }
    $rows = jeab_cutover_read_rows('notiser');
    if ($rows === null) {
        return null;
    }
    $store = ['items' => [], 'pdf' => []];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string) ($row['item_id'] ?? '');
        if ($id === '') {
            continue;
        }
        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        if ((string) ($row['slot'] ?? '') === 'pdf') {
            $store['pdf'][$id] = $payload;
            continue;
        }
        if (!array_key_exists('body', $payload) && isset($row['body'])) {
            $payload['body'] = $row['body'];
        }
        $store['items'][$id] = $payload;
    }

    return $store;
}

/** @return array{seen: array<string, int>, resolved: array<string, mixed>}|null */
function jeab_cutover_webhook_maps(): ?array
{
    if (!jeab_cutover_sql('app_errors_webhook')) {
        return null;
    }
    $rows = jeab_cutover_read_rows('app_errors_webhook');
    if ($rows === null) {
        return null;
    }
    $seen = [];
    $resolved = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $fp = (string) ($row['fp'] ?? '');
        if ($fp === '') {
            continue;
        }
        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        if ((string) ($row['slot'] ?? '') === 'resolved') {
            $resolved[$fp] = is_array($payload) ? $payload : ['at' => (int) ($row['at'] ?? 0), 'by' => 'einstein', 'note' => ''];
            continue;
        }
        $seen[$fp] = is_array($payload) ? (int) ($payload['at'] ?? 0) : (int) ($row['at'] ?? 0);
    }

    return ['seen' => $seen, 'resolved' => $resolved];
}

/**
 * Skriver hela webhook-kartan under jeab_cutover_locked. $seen null behåller
 * det seen som ligger i SQL inne i låset.
 *
 * @param array<string, int>|null $seen @param array<string, mixed> $resolved
 */
function jeab_cutover_webhook_write(?array $seen, array $resolved): bool
{
    return jeab_cutover_webhook_mutate(static function (array $curSeen, array $curResolved) use ($seen, $resolved): array {
        unset($curResolved);

        return ['seen' => $seen ?? $curSeen, 'resolved' => $resolved];
    });
}

/**
 * Läs-ändra-skriv av webhook-kartan under GET_LOCK och transaktion (S4-4).
 * $fn får seen och resolved från SQL inne i låset och returnerar
 * ['seen'=>..,'resolved'=>..] eller null för ingen skrivning.
 *
 * @param callable(array<string, int>, array<string, mixed>): ?array $fn
 */
function jeab_cutover_webhook_mutate(callable $fn): bool
{
    if (!jeab_cutover_sql('app_errors_webhook') || !function_exists('jeab_cutover_locked')) {
        return false;
    }
    try {
        jeab_cutover_locked('app_errors_webhook', static function (array $source) use ($fn): ?array {
            $next = $fn((array) ($source['seen'] ?? []), (array) ($source['resolved'] ?? []));
            if (!is_array($next)) {
                return null;
            }

            return ['seen' => (array) ($next['seen'] ?? []), 'resolved' => (array) ($next['resolved'] ?? [])];
        });
    } catch (Throwable $e) {
        if (function_exists('jeab_log_append_failed')) {
            jeab_log_append_failed('app_errors_webhook', 'mutate', $e);
        }
        return false;
    }

    return true;
}

function jeab_cutover_solkoll_read(string $path): ?array
{
    if (!function_exists('jeab_solkoll_config_path') || !jeab_cutover_sql('solkoll')) {
        return null;
    }
    $slot = '';
    if ($path === jeab_solkoll_config_path()) {
        $slot = 'config';
    } elseif (function_exists('jeab_solkoll_cache_path') && $path === jeab_solkoll_cache_path()) {
        $slot = 'cache';
    }
    if ($slot === '') {
        return null;
    }
    if (!jeab_cutover_type_boot('solkoll')) {
        return null;
    }

    return jeab_cutover_doc_fetch_key('solkoll', $slot);
}

/** @return list<array<string, mixed>>|null */
function jeab_cutover_views_list(): ?array
{
    if (!jeab_cutover_sql('inkorg_views')) {
        return null;
    }
    if (!jeab_cutover_type_boot('inkorg_views')) {
        return null;
    }
    $rows = jeab_cutover_read_rows('inkorg_views');
    if ($rows === null) {
        return null;
    }
    $list = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        if (!is_array($payload)) {
            continue;
        }
        $payload['_position'] = (int) ($row['position'] ?? 0);
        $list[] = $payload;
    }
    usort($list, static function (array $a, array $b): int {
        $pos = ((int) ($a['_position'] ?? 0)) <=> ((int) ($b['_position'] ?? 0));
        if ($pos !== 0) {
            return $pos;
        }

        return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
    });
    foreach ($list as $i => $view) {
        unset($list[$i]['_position']);
    }

    return $list;
}

/**
 * Inkorgsvyer: läs-ändra-skriv under jeab_cutover_locked (S4-4).
 *
 * @param array<string, mixed> $view
 */
function jeab_inkorg_views_sql_save(array $view): array
{
    jeab_cutover_locked('inkorg_views', static function (array $source) use ($view): array {
        $views = is_array($source['views'] ?? null) ? $source['views'] : [];
        $found = false;
        foreach ($views as $i => $row) {
            if (is_array($row) && (string) ($row['id'] ?? '') === (string) ($view['id'] ?? '')) {
                $views[$i] = $view;
                $found = true;
            }
        }
        if (!$found) {
            $views[] = $view;
        }

        return ['views' => array_values($views)];
    });

    return $view;
}

function jeab_inkorg_views_sql_delete(string $id): void
{
    jeab_cutover_locked('inkorg_views', static function (array $source) use ($id): array {
        $next = [];
        foreach (is_array($source['views'] ?? null) ? $source['views'] : [] as $row) {
            if (!is_array($row) || (string) ($row['id'] ?? '') === $id) {
                continue;
            }
            $next[] = $row;
        }

        return ['views' => $next];
    });
}

/** @param list<array<string, mixed>> $views */
function jeab_inkorg_views_sql_replace(array $views): void
{
    jeab_cutover_locked('inkorg_views', static function (array $source) use ($views): array {
        unset($source);

        return ['views' => array_values($views)];
    });
}

/** @param callable(array<string, array{fails:int,until:int}>): mixed $fn */
function jeab_cutover_throttle_mutate(callable $fn): mixed
{
    $GLOBALS['_JEAB_THROTTLE_UNAVAILABLE'] = false;
    $pdo = jeab_data_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_pdo_transaction')) {
        $GLOBALS['_JEAB_THROTTLE_UNAVAILABLE'] = true;
        $empty = [];

        return $fn($empty);
    }
    try {
        // S4-4: GET_LOCK och transaktion över hela läs-ändra-skriv-cykeln.
        unset($pdo);
        $result = null;
        jeab_cutover_locked('login_throttle', static function (array $store) use ($fn, &$result): array {
            $result = $fn($store);

            return $store;
        });

        return $result;
    } catch (Throwable) {
        $GLOBALS['_JEAB_THROTTLE_UNAVAILABLE'] = true;
        $empty = [];

        return $fn($empty);
    }
}

/** @return array<string, array{fails:int,until:int}> */
function jeab_cutover_throttle_load(): array
{
    $rows = jeab_cutover_read_rows('login_throttle');
    if ($rows === null) {
        $path = function_exists('jeab_auth_throttle_path') ? jeab_auth_throttle_path() : '';
        $raw = $path !== '' && is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return function_exists('jeab_auth_throttle_normalize') ? jeab_auth_throttle_normalize($raw) : [];
    }
    $store = [];
    foreach ($rows as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        $store[(string) $key] = [
            'fails' => (int) ($row['fails'] ?? 0),
            'until' => (int) ($row['until'] ?? 0),
        ];
    }

    return $store;
}

/** @return list<string> */
function jeab_cutover_json_fallback_types(): array
{
    return ['inbox_sent_new', 'solkoll', 'inkop_mallar', 'inkorg_views'];
}

function jeab_cutover_json_fallback_type(string $type): bool
{
    return in_array($type, jeab_cutover_json_fallback_types(), true);
}

/**
 * Skapar tabellen och fyller tomma nycklar från JSON. Insert-only.
 * false när tabellen saknas eller inte går att läsa, så läsaren tar JSON.
 */
function jeab_cutover_type_boot(string $type): bool
{
    if (isset($GLOBALS['_JEAB_CUTOVER_BOOT_DONE'][$type])) {
        return (bool) $GLOBALS['_JEAB_CUTOVER_BOOT_DONE'][$type];
    }
    $ok = false;
    try {
        if (!jeab_cutover_sql($type) || !function_exists('jeab_migrate_find') || !function_exists('jeab_migrate_ensure')) {
            $GLOBALS['_JEAB_CUTOVER_BOOT_DONE'][$type] = false;

            return false;
        }
        $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
        $module = jeab_migrate_find($type);
        if (!$pdo instanceof PDO || !is_array($module) || !empty($module['missing'])) {
            $GLOBALS['_JEAB_CUTOVER_BOOT_DONE'][$type] = false;

            return false;
        }
        if (function_exists('jeab_data_write_guard')) {
            jeab_data_write_guard();
        }
        if (!function_exists('jeab_pdo_tx_busy') || !jeab_pdo_tx_busy($pdo)) {
            jeab_migrate_ensure($pdo, $module);
        }
        $source = function_exists('jeab_migrate_read_source') ? jeab_migrate_read_source($module) : [];
        $rowsFn = (string) ($module['rows'] ?? '');
        $mapped = function_exists($rowsFn) ? $rowsFn(is_array($source) ? $source : []) : [];
        if (!is_array($mapped) || $mapped === []) {
            $ok = true;
            $GLOBALS['_JEAB_CUTOVER_BOOT_DONE'][$type] = true;

            return true;
        }
        $existing = jeab_migrate_owned_keys($pdo, $module);
        $pk = (string) ($module['primary_key'] ?? '');
        $fresh = [];
        foreach ($mapped as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = trim((string) ($row[$pk] ?? ''));
            if ($key === '' || isset($existing[$key])) {
                continue;
            }
            $fresh[] = $row;
        }
        if ($fresh !== []) {
            jeab_migrate_upsert($pdo, $module, $fresh);
        }
        $ok = true;
    } catch (Throwable) {
        $ok = false;
    }
    $GLOBALS['_JEAB_CUTOVER_BOOT_DONE'][$type] = $ok;

    return $ok;
}

function jeab_inbox_sent_new_sql(): bool
{
    return jeab_cutover_sql('inbox_sent_new');
}

/** @param array<string, mixed> $row */
function jeab_inbox_sent_new_log(array $row): array
{
    return [
        'at' => (string) ($row['at'] ?? ''),
        'by' => (string) ($row['by'] ?? ''),
        'company' => (string) ($row['company'] ?? ''),
        'from' => (string) ($row['from'] ?? ''),
        'to' => (string) ($row['to'] ?? ''),
        'subject' => (string) ($row['subject'] ?? ''),
        'send_key' => (string) ($row['send_key'] ?? ''),
    ];
}

/**
 * @return array{pdo:PDO,module:array<string, mixed>}|null
 */
function jeab_inbox_sent_new_ctx(): ?array
{
    if (!jeab_inbox_sent_new_sql()) {
        return null;
    }
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    $module = function_exists('jeab_migrate_find') ? jeab_migrate_find('inbox_sent_new') : null;
    if (!$pdo instanceof PDO || !is_array($module) || !empty($module['missing'])) {
        return null;
    }
    jeab_inbox_sent_new_boot($pdo, $module);

    return ['pdo' => $pdo, 'module' => $module];
}

/** SQL-läget är på och tabellen går att läsa. Annars ska anroparen ta JSON. */
function jeab_inbox_sent_new_usable(): bool
{
    if (!jeab_inbox_sent_new_sql()) {
        return false;
    }
    try {
        $ctx = jeab_inbox_sent_new_ctx();
        if ($ctx === null) {
            return false;
        }
        $table = jeab_migrate_ident($ctx['pdo'], (string) ($ctx['module']['table'] ?? ''));
        $probe = $ctx['pdo']->query('SELECT 1 FROM ' . $table . ' LIMIT 1');

        return $probe instanceof PDOStatement;
    } catch (Throwable) {
        return false;
    }
}

/**
 * @param array<string, mixed> $module
 */
function jeab_inbox_sent_new_boot(PDO $pdo, array $module): void
{
    static $done = [];
    $key = spl_object_id($pdo);
    if (isset($done[$key])) {
        return;
    }
    if (function_exists('jeab_data_write_guard')) {
        jeab_data_write_guard();
    }
    if (!function_exists('jeab_pdo_tx_busy') || !jeab_pdo_tx_busy($pdo)) {
        jeab_migrate_ensure($pdo, $module);
    }
    $source = function_exists('jeab_migrate_read_source') ? jeab_migrate_read_source($module) : [];
    $mapped = function_exists('jeab_migrate_inbox_sent_new_rows')
        ? jeab_migrate_inbox_sent_new_rows(is_array($source) ? $source : [])
        : [];
    if (!is_array($mapped) || $mapped === []) {
        $done[$key] = true;

        return;
    }
    $existing = jeab_migrate_owned_keys($pdo, $module);
    $fresh = [];
    foreach ($mapped as $row) {
        if (!is_array($row)) {
            continue;
        }
        $sendKey = trim((string) ($row['send_key'] ?? ''));
        if ($sendKey === '' || isset($existing[$sendKey])) {
            continue;
        }
        $fresh[] = $row;
    }
    if ($fresh !== []) {
        jeab_migrate_upsert($pdo, $module, $fresh);
    }
    $done[$key] = true;
}

/**
 * @param array<string, mixed> $module
 * @return list<string>
 */
function jeab_inbox_sent_new_select_sql(PDO $pdo, array $module): array
{
    $quoted = [];
    foreach (is_array($module['columns'] ?? null) ? $module['columns'] : [] as $column) {
        $quoted[] = jeab_migrate_ident($pdo, (string) $column);
    }

    return $quoted;
}

/**
 * @param array<string, mixed> $module
 */
function jeab_inbox_sent_new_fetch(PDO $pdo, array $module, string $sendKey): ?array
{
    if ($sendKey === '') {
        return null;
    }
    $quoted = jeab_inbox_sent_new_select_sql($pdo, $module);
    if ($quoted === []) {
        return null;
    }
    $sql = 'SELECT ' . implode(', ', $quoted)
        . ' FROM ' . jeab_migrate_ident($pdo, (string) ($module['table'] ?? ''))
        . ' WHERE ' . jeab_migrate_ident($pdo, 'send_key') . ' = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$sendKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? jeab_inbox_sent_new_log($row) : null;
}

/**
 * Reserverar send_key med INSERT mot unik nyckel. null = reservationen togs.
 * En befintlig rad returneras vid krock.
 *
 * @param array<string, mixed> $log
 * @return array<string, mixed>|null
 */
function jeab_inbox_sent_new_reserve(array $log): ?array
{
    $ctx = jeab_inbox_sent_new_ctx();
    if ($ctx === null) {
        throw new RuntimeException('Ingen SQL-databas.');
    }
    if (function_exists('jeab_data_write_guard')) {
        jeab_data_write_guard();
    }
    $pdo = $ctx['pdo'];
    $module = $ctx['module'];
    $row = function_exists('jeab_migrate_inbox_sent_new_normalize')
        ? jeab_migrate_inbox_sent_new_normalize($log)
        : $log;
    $sendKey = trim((string) ($row['send_key'] ?? ''));
    if ($sendKey === '') {
        return null;
    }
    $quoted = [];
    $values = [];
    foreach (is_array($module['columns'] ?? null) ? $module['columns'] : [] as $column) {
        $column = (string) $column;
        $quoted[] = jeab_migrate_ident($pdo, $column);
        $values[] = $row[$column] ?? '';
    }
    $table = jeab_migrate_ident($pdo, (string) ($module['table'] ?? ''));
    $marks = implode(', ', array_fill(0, count($quoted), '?'));
    $mysql = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $sql = ($mysql ? 'INSERT IGNORE INTO ' : 'INSERT OR IGNORE INTO ')
        . $table . ' (' . implode(', ', $quoted) . ') VALUES (' . $marks . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
    $changed = $stmt->rowCount();
    if ($changed < 1 && !$mysql) {
        try {
            $changed = (int) $pdo->query('SELECT changes()')->fetchColumn();
        } catch (Throwable) {
            $changed = 0;
        }
    }
    if ($changed > 0) {
        return null;
    }
    $existing = jeab_inbox_sent_new_fetch($pdo, $module, $sendKey);
    if (is_array($existing)) {
        return $existing;
    }

    throw new RuntimeException('send_key kunde inte reserveras.');
}

function jeab_inbox_sent_new_release(string $sendKey): void
{
    $sendKey = trim($sendKey);
    if ($sendKey === '') {
        return;
    }
    $ctx = jeab_inbox_sent_new_ctx();
    if ($ctx === null) {
        return;
    }
    if (function_exists('jeab_data_write_guard')) {
        jeab_data_write_guard();
    }
    jeab_cutover_delete_keys($ctx['pdo'], $ctx['module'], [$sendKey]);
}

/**
 * @param array<string, mixed> $log
 */
function jeab_inbox_sent_new_commit(array $log): void
{
    $ctx = jeab_inbox_sent_new_ctx();
    if ($ctx === null) {
        throw new RuntimeException('Ingen SQL-databas.');
    }
    if (function_exists('jeab_data_write_guard')) {
        jeab_data_write_guard();
    }
    $pdo = $ctx['pdo'];
    $module = $ctx['module'];
    $row = function_exists('jeab_migrate_inbox_sent_new_normalize')
        ? jeab_migrate_inbox_sent_new_normalize($log)
        : $log;
    $sendKey = trim((string) ($row['send_key'] ?? ''));
    if ($sendKey === '') {
        return;
    }
    $assignments = [];
    $values = [];
    foreach (is_array($module['columns'] ?? null) ? $module['columns'] : [] as $column) {
        $column = (string) $column;
        if ($column === 'send_key') {
            continue;
        }
        $assignments[] = jeab_migrate_ident($pdo, $column) . ' = ?';
        $values[] = $row[$column] ?? '';
    }
    $values[] = $sendKey;
    $table = jeab_migrate_ident($pdo, (string) ($module['table'] ?? ''));
    $pk = jeab_migrate_ident($pdo, 'send_key');
    if ($assignments !== []) {
        $pdo->prepare(
            'UPDATE ' . $table . ' SET ' . implode(', ', $assignments) . ' WHERE ' . $pk . ' = ?'
        )->execute($values);
    }
    if (jeab_inbox_sent_new_fetch($pdo, $module, $sendKey) === null) {
        $quoted = [];
        $insert = [];
        foreach (is_array($module['columns'] ?? null) ? $module['columns'] : [] as $column) {
            $column = (string) $column;
            $quoted[] = jeab_migrate_ident($pdo, $column);
            $insert[] = $row[$column] ?? '';
        }
        $mysql = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $marks = implode(', ', array_fill(0, count($quoted), '?'));
        $sql = ($mysql ? 'INSERT IGNORE INTO ' : 'INSERT OR IGNORE INTO ')
            . $table . ' (' . implode(', ', $quoted) . ') VALUES (' . $marks . ')';
        $pdo->prepare($sql)->execute($insert);
    }
    jeab_inbox_sent_new_trim($pdo, $module);
}

/**
 * @param array<string, mixed> $module
 */
function jeab_inbox_sent_new_trim(PDO $pdo, array $module): void
{
    $table = jeab_migrate_ident($pdo, (string) ($module['table'] ?? ''));
    $pk = jeab_migrate_ident($pdo, 'send_key');
    $at = jeab_migrate_ident($pdo, 'at');
    try {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    } catch (Throwable) {
        return;
    }
    $drop = $count - 500;
    if ($drop <= 0) {
        return;
    }
    $stmt = $pdo->query('SELECT ' . $pk . ' FROM ' . $table . ' ORDER BY ' . $at . ' ASC, ' . $pk . ' ASC');
    if (!$stmt instanceof PDOStatement) {
        return;
    }
    $keys = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
        $key = trim((string) $key);
        if ($key !== '') {
            $keys[] = $key;
        }
    }
    jeab_cutover_delete_keys($pdo, $module, array_slice($keys, 0, $drop));
}
