<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Anteckningar i SQL. Tabellerna skapas här, inte i src/db.php.
 * PDO kommer från jeab_store(), som öppnar via jeab_pdo_connect_sqlite/mysql.
 * notes.json läses som reserv tills importen är gjord och skrivs inte längre.
 */

function jeab_notes_store_ready(): bool
{
    return jeab_notes_store_pdo() instanceof PDO;
}

function jeab_notes_store_pdo(): ?PDO
{
    if (function_exists('jeab_store')) {
        try {
            $store = jeab_store();
            if ($store instanceof JeabPdoStore) {
                return $store->pdo();
            }
        } catch (Throwable) {
        }
    }
    return jeab_notes_store_sqlite_fallback();
}

/** När hela appen ligger på JSON öppnas samma sqlite-fil som PDO-hjälparen skulle använt. */
function jeab_notes_store_sqlite_fallback(): ?PDO
{
    $cached = $GLOBALS['_JEAB_SIDE_SQLITE'] ?? null;
    if ($cached instanceof PDO) {
        return $cached;
    }
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return null;
    }
    $path = (string) (function_exists('jeab_config') ? (jeab_config()['db']['sqlite_path'] ?? '') : '');
    if ($path === '' || (function_exists('jeab_store_path_is_public_data') && jeab_store_path_is_public_data($path))) {
        return null;
    }
    try {
        if (function_exists('jeab_data_dir_ready')) {
            jeab_data_dir_ready($path);
        }
        $pdo = function_exists('jeab_pdo_connect_sqlite') ? jeab_pdo_connect_sqlite() : null;
        if (!$pdo instanceof PDO) {
            return null;
        }
        $GLOBALS['_JEAB_SIDE_SQLITE'] = $pdo;
        return $pdo;
    } catch (Throwable) {
        return null;
    }
}

function jeab_notes_store_driver(PDO $pdo): string
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
}

function jeab_notes_store_ensure(PDO $pdo): void
{
    static $done = [];
    $key = spl_object_id($pdo);
    if (isset($done[$key])) {
        return;
    }
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    $done[$key] = true;
    // Tabellerna skapas av tick och CLI. En sidvisning ska inte ta metadata-lås.
    if (function_exists('jeab_schema_request_skips_ddl') && jeab_schema_request_skips_ddl()) {
        return;
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $sapi = (string) ($GLOBALS['_JEAB_SAPI'] ?? PHP_SAPI);
    if ($sapi !== 'cli' && $sapi !== 'phpdbg' && ($method === 'GET' || $method === 'HEAD') && empty($GLOBALS['_JEAB_TICK']) && empty($GLOBALS['_JEAB_SCHEMA_DDL'])) {
        return;
    }
    if (jeab_notes_store_driver($pdo) === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS notes (
                id INT UNSIGNED NOT NULL,
                payload LONGTEXT NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS notes_state (
                id TINYINT UNSIGNED NOT NULL,
                next_id INT UNSIGNED NOT NULL,
                imported TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS notes (
            id INTEGER PRIMARY KEY,
            payload TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS notes_state (
            id INTEGER PRIMARY KEY,
            next_id INTEGER NOT NULL,
            imported INTEGER NOT NULL DEFAULT 0
        )'
    );
}

/** @return array{next_id:int,notes:list<array<string,mixed>>} */
function jeab_notes_store_empty(): array
{
    return ['next_id' => 1, 'notes' => []];
}

/** @return array{next_id:int,notes:list<array<string,mixed>>}|null */
function jeab_notes_json_read(): ?array
{
    $paths = [];
    if (function_exists('jeab_notes_path')) {
        $paths[] = jeab_notes_path();
    }
    if (function_exists('jeab_public_data_dir')) {
        $paths[] = jeab_public_data_dir() . '/notes.json';
    }
    foreach ($paths as $path) {
        if (!is_file($path)) {
            continue;
        }
        $raw = @file_get_contents($path);
        $data = json_decode(is_string($raw) ? $raw : '', true);
        if (!is_array($data) || !isset($data['notes']) || !is_array($data['notes'])) {
            continue;
        }
        $notes = [];
        foreach ($data['notes'] as $row) {
            if (is_array($row)) {
                $notes[] = $row;
            }
        }
        $next = (int) ($data['next_id'] ?? 1);
        return ['next_id' => $next > 0 ? $next : 1, 'notes' => $notes];
    }
    return null;
}

function jeab_notes_store_is_imported(PDO $pdo): bool
{
    $stmt = $pdo->query('SELECT imported FROM notes_state WHERE id = 1');
    $value = $stmt ? $stmt->fetchColumn() : false;
    return $value !== false && (int) $value === 1;
}

/** @return array{next_id:int,imported:int} */
function jeab_notes_store_state(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT next_id, imported FROM notes_state WHERE id = 1');
    $row = $stmt ? $stmt->fetch() : false;
    if (!is_array($row)) {
        return ['next_id' => 1, 'imported' => 0];
    }
    $next = (int) ($row['next_id'] ?? 1);
    return ['next_id' => $next > 0 ? $next : 1, 'imported' => (int) ($row['imported'] ?? 0) === 1 ? 1 : 0];
}

/** @return array{next_id:int,notes:list<array<string,mixed>>} */
function jeab_notes_store_read_sql(PDO $pdo): array
{
    $notes = [];
    $maxId = 0;
    foreach ($pdo->query('SELECT id, payload FROM notes ORDER BY id') ?: [] as $dbRow) {
        if (!is_array($dbRow)) {
            continue;
        }
        $row = json_decode((string) ($dbRow['payload'] ?? ''), true);
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($dbRow['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $row['id'] = $id;
        $notes[] = $row;
        if ($id > $maxId) {
            $maxId = $id;
        }
    }
    $state = jeab_notes_store_state($pdo);
    $next = $state['next_id'];
    if ($maxId >= $next) {
        $next = $maxId + 1;
    }
    return ['next_id' => $next, 'notes' => $notes];
}

/**
 * @param array{next_id:int,notes:list<array<string,mixed>>} $base
 * @param array{next_id:int,notes:list<array<string,mixed>>} $over
 * @return array{next_id:int,notes:list<array<string,mixed>>}
 */
function jeab_notes_store_merge(array $base, array $over): array
{
    $byId = [];
    foreach ([$base, $over] as $doc) {
        foreach ($doc['notes'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $row['id'] = $id;
            $byId[$id] = $row;
        }
    }
    $next = max((int) $base['next_id'], (int) $over['next_id'], 1);
    if ($byId !== [] && max(array_keys($byId)) >= $next) {
        $next = max(array_keys($byId)) + 1;
    }
    return ['next_id' => $next, 'notes' => array_values($byId)];
}

function jeab_notes_store_payload(array $row): ?string
{
    $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json === false ? null : $json;
}

function jeab_notes_store_insert_ignore(PDO $pdo, int $id, string $payload): void
{
    if (jeab_notes_store_driver($pdo) === 'mysql') {
        $pdo->prepare('INSERT IGNORE INTO notes (id, payload) VALUES (?, ?)')->execute([$id, $payload]);
        return;
    }
    $pdo->prepare('INSERT OR IGNORE INTO notes (id, payload) VALUES (?, ?)')->execute([$id, $payload]);
}

function jeab_notes_store_upsert(PDO $pdo, int $id, string $payload): void
{
    if (jeab_notes_store_driver($pdo) === 'mysql') {
        $pdo->prepare(
            'INSERT INTO notes (id, payload) VALUES (?, ?) ON DUPLICATE KEY UPDATE payload = VALUES(payload)'
        )->execute([$id, $payload]);
        return;
    }
    $pdo->prepare(
        'INSERT INTO notes (id, payload) VALUES (?, ?) ON CONFLICT(id) DO UPDATE SET payload = excluded.payload'
    )->execute([$id, $payload]);
}

function jeab_notes_store_put_state(PDO $pdo, int $nextId, int $imported): void
{
    if ($nextId < 1) {
        $nextId = 1;
    }
    $imported = $imported === 1 ? 1 : 0;
    if (jeab_notes_store_driver($pdo) === 'mysql') {
        $pdo->prepare(
            'INSERT INTO notes_state (id, next_id, imported) VALUES (1, ?, ?)
             ON DUPLICATE KEY UPDATE next_id = VALUES(next_id), imported = VALUES(imported)'
        )->execute([$nextId, $imported]);
        return;
    }
    $pdo->prepare(
        'INSERT INTO notes_state (id, next_id, imported) VALUES (1, ?, ?)
         ON CONFLICT(id) DO UPDATE SET next_id = excluded.next_id, imported = excluded.imported'
    )->execute([$nextId, $imported]);
}

/**
 * Kopierar JSON-rader som saknas i SQL. Befintliga id rörs inte.
 *
 * @return array{inserted:int,skipped:int,json_rows:int,next_id:int}
 */
function jeab_notes_store_copy_json(PDO $pdo): array
{
    $json = jeab_notes_json_read() ?? jeab_notes_store_empty();
    $have = [];
    foreach ($pdo->query('SELECT id FROM notes') ?: [] as $dbRow) {
        if (is_array($dbRow)) {
            $have[(int) ($dbRow['id'] ?? 0)] = true;
        }
    }
    $inserted = 0;
    $skipped = 0;
    $maxId = 0;
    foreach ($json['notes'] as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            $skipped++;
            continue;
        }
        if ($id > $maxId) {
            $maxId = $id;
        }
        if (isset($have[$id])) {
            $skipped++;
            continue;
        }
        $row['id'] = $id;
        $payload = jeab_notes_store_payload($row);
        if ($payload === null) {
            $skipped++;
            continue;
        }
        jeab_notes_store_insert_ignore($pdo, $id, $payload);
        $have[$id] = true;
        $inserted++;
    }
    $state = jeab_notes_store_state($pdo);
    $next = max($state['next_id'], (int) $json['next_id'], 1);
    if ($maxId >= $next) {
        $next = $maxId + 1;
    }
    return ['inserted' => $inserted, 'skipped' => $skipped, 'json_rows' => count($json['notes']), 'next_id' => $next];
}

/** @param array<string, mixed> $row */
function jeab_notes_store_canonical(array $row): string
{
    $sort = static function (array &$value) use (&$sort): void {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $sort($item);
            }
        }
        unset($item);
        ksort($value);
    };
    $sort($row);
    $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($json) ? $json : '';
}

function jeab_notes_store_refuse_mass_delete(int $total, int $drop): bool
{
    if ($drop <= 0) {
        return false;
    }
    $overCount = $drop > 20;
    $overShare = $total > 0 && ($drop / $total) > 0.05;
    if (!$overCount && !$overShare) {
        return false;
    }
    error_log('Anteckningar: radering vägras (' . $drop . ' av ' . $total . ').');

    return true;
}

/**
 * Skriver bara nya och ändrade rader. Raderar bara id som saknas mot
 * föregående ögonblicksbild. Tom lista raderar inget.
 *
 * @param array{next_id:int,notes:list<array<string,mixed>>} $data
 */
function jeab_notes_store_replace(PDO $pdo, array $data): void
{
    $before = [];
    foreach ($pdo->query('SELECT id, payload FROM notes') ?: [] as $dbRow) {
        if (!is_array($dbRow)) {
            continue;
        }
        $id = (int) ($dbRow['id'] ?? 0);
        $row = json_decode((string) ($dbRow['payload'] ?? ''), true);
        if ($id <= 0 || !is_array($row)) {
            continue;
        }
        $row['id'] = $id;
        $before[$id] = $row;
    }
    $after = [];
    $maxId = 0;
    foreach ($data['notes'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $row['id'] = $id;
        $after[$id] = $row;
        if ($id > $maxId) {
            $maxId = $id;
        }
    }
    foreach ($after as $id => $row) {
        if (isset($before[$id]) && jeab_notes_store_canonical($before[$id]) === jeab_notes_store_canonical($row)) {
            continue;
        }
        $payload = jeab_notes_store_payload($row);
        if ($payload === null) {
            throw new RuntimeException('Kunde inte spara anteckningen.');
        }
        jeab_notes_store_upsert($pdo, $id, $payload);
    }
    if ($after === []) {
        if ($before !== []) {
            error_log('Anteckningar: tom lista, inget raderas (' . count($before) . ' rader).');
        }
    } else {
        $drop = [];
        foreach ($before as $id => $_row) {
            if (!isset($after[$id])) {
                $drop[] = $id;
            }
        }
        if (!jeab_notes_store_refuse_mass_delete(count($before), count($drop))) {
            foreach ($drop as $id) {
                $pdo->prepare('DELETE FROM notes WHERE id = ?')->execute([$id]);
            }
        }
    }
    $next = (int) ($data['next_id'] ?? 1);
    if ($maxId >= $next) {
        $next = $maxId + 1;
    }
    jeab_notes_store_put_state($pdo, $next, 1);
}

/** @return array{next_id:int,notes:list<array<string,mixed>>} */
function jeab_notes_store_load(): array
{
    $empty = jeab_notes_store_empty();
    // Explicit sql-flagga (cutover har importerat): notes.json läses inte som reserv.
    $sqlOnly = function_exists('jeab_data_type_forced') && jeab_data_type_forced('sql', 'notes');
    $pdo = jeab_notes_store_pdo();
    if (!$pdo instanceof PDO) {
        if ($sqlOnly) {
            error_log('Anteckningar: SQL saknas, ingen JSON-reserv.');

            return $empty;
        }
        return jeab_notes_json_read() ?? $empty;
    }
    try {
        jeab_notes_store_ensure($pdo);
        $sql = jeab_notes_store_read_sql($pdo);
    } catch (Throwable) {
        error_log('Anteckningar: SQL-läsning misslyckades.');

        return $empty;
    }
    if ($sqlOnly || jeab_notes_store_is_imported($pdo) || $sql['notes'] !== []) {
        return $sql;
    }
    $json = jeab_notes_json_read();

    return $json ?? $empty;
}

/**
 * @template T
 * @param callable(array{next_id:int,notes:list<array<string,mixed>>}):T $fn
 * @return T
 */
function jeab_notes_store_mutate(callable $fn): mixed
{
    $pdo = jeab_notes_store_pdo();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Kunde inte spara anteckningen.');
    }
    if (!jeab_pdo_tx_busy($pdo)) {
        jeab_notes_store_ensure($pdo);
    }
    return jeab_pdo_transaction($pdo, static function (PDO $pdo) use ($fn): mixed {
        if (!jeab_notes_store_is_imported($pdo)) {
            $copied = jeab_notes_store_copy_json($pdo);
            $state = jeab_notes_store_state($pdo);
            $next = max($copied['next_id'], $state['next_id']);
            jeab_notes_store_put_state($pdo, $next, 0);
        }
        $data = jeab_notes_store_read_sql($pdo);
        $result = $fn($data);
        jeab_notes_store_replace($pdo, $data);

        return $result;
    });
}

function jeab_notes_import_is_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $dry = $input['dry'];
    return !($dry === 0 || $dry === '0' || $dry === false);
}

/** @param array<string, mixed> $input
 *  @return array<string, mixed>
 */
function jeab_notes_import_dispatch(array $input): array
{
    $dry = jeab_notes_import_is_dry($input);
    $pdo = jeab_notes_store_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'SQL saknas.', 'dry' => $dry ? 1 : 0, 'status' => 400];
    }
    jeab_notes_store_ensure($pdo);
    $json = jeab_notes_json_read() ?? jeab_notes_store_empty();
    if (jeab_notes_store_is_imported($pdo)) {
        return [
            'ok' => true,
            'dry' => $dry ? 1 : 0,
            'inserted' => 0,
            'skipped' => count($json['notes']),
            'json_rows' => count($json['notes']),
        ];
    }
    if ($dry) {
        $have = [];
        foreach ($pdo->query('SELECT id FROM notes') ?: [] as $dbRow) {
            if (is_array($dbRow)) {
                $have[(int) ($dbRow['id'] ?? 0)] = true;
            }
        }
        $inserted = 0;
        $skipped = 0;
        foreach ($json['notes'] as $row) {
            if (!is_array($row)) {
                $skipped++;
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || isset($have[$id])) {
                $skipped++;
                continue;
            }
            $inserted++;
        }
        return [
            'ok' => true,
            'dry' => 1,
            'inserted' => $inserted,
            'skipped' => $skipped,
            'json_rows' => count($json['notes']),
        ];
    }
    try {
        $copied = jeab_pdo_transaction($pdo, static function (PDO $pdo): array {
            $copied = jeab_notes_store_copy_json($pdo);
            jeab_notes_store_put_state($pdo, $copied['next_id'], 1);

            return $copied;
        });
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'Importen stoppades.', 'dry' => 0, 'status' => 400];
    }
    return [
        'ok' => true,
        'dry' => 0,
        'inserted' => $copied['inserted'],
        'skipped' => $copied['skipped'],
        'json_rows' => $copied['json_rows'],
    ];
}
