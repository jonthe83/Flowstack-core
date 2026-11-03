<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Övriga inkorgsfiler i SQL. Tabellerna skapas här, inte i src/db.php.
 * Gäller bara när inkorg_storage är sql. JSON läses en gång, vid första
 * läsningen, och skrivs inte mer. auto-send-cap och malmo-offer-sends
 * importeras innan svaret, så ett tomt SQL-läge inte nollställer taket.
 */

function jeab_inkorg_side_sql(): bool
{
    if (array_key_exists('_JEAB_INKORG_SIDE_SQL', $GLOBALS)) {
        return (bool) $GLOBALS['_JEAB_INKORG_SIDE_SQL'];
    }
    $on = function_exists('jeab_inkorg_use_sql') && jeab_inkorg_use_sql();
    $GLOBALS['_JEAB_INKORG_SIDE_SQL'] = $on;

    return $on;
}

function jeab_inkorg_side_pdo(): ?PDO
{
    if (!function_exists('jeab_inkorg_store_pdo')) {
        return null;
    }
    $pdo = jeab_inkorg_store_pdo();
    return $pdo instanceof PDO ? $pdo : null;
}

function jeab_inkorg_side_driver(PDO $pdo): string
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
}

/** @return list<string> */
function jeab_inkorg_side_names(): array
{
    return ['auto-send-cap', 'malmo-offer-sends', 'quote-preview', 'hs-uncertain-open', 'rebook-log', 'settings.json', 'rules.json'];
}

function jeab_inkorg_side_path(string $name): string
{
    $file = match ($name) {
        'auto-send-cap' => 'auto-send-cap.json',
        'malmo-offer-sends' => 'malmo-offer-sends.json',
        'quote-preview' => 'quote-preview.json',
        'hs-uncertain-open' => 'hs-uncertain-open.json',
        'rebook-log' => 'rebook-log.json',
        'settings.json' => 'settings.json',
        'rules.json' => 'rules.json',
        'corrections' => 'corrections.jsonl',
        default => '',
    };
    if ($file === '' || !function_exists('jeab_inkorg_dir')) {
        throw new InvalidArgumentException('Okänd sidolagring.');
    }
    return jeab_inkorg_dir() . '/' . $file;
}

/** @return array<string, mixed> */
function jeab_inkorg_side_empty(string $name): array
{
    return match ($name) {
        'auto-send-cap', 'malmo-offer-sends' => ['sends' => []],
        'quote-preview' => ['thread' => '', 'text' => '', 'at' => '', 'by_thread' => []],
        'hs-uncertain-open' => ['facts' => []],
        'rebook-log' => ['done' => []],
        'settings.json', 'rules.json' => [],
        default => throw new InvalidArgumentException('Okänd sidolagring.'),
    };
}

function jeab_inkorg_side_ensure(PDO $pdo): void
{
    static $done = [];
    $key = spl_object_id($pdo);
    if (isset($done[$key])) {
        return;
    }
    if (jeab_pdo_tx_busy($pdo)) {
        return;
    }
    if (jeab_inkorg_side_driver($pdo) === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inkorg_side_doc (
                name VARCHAR(64) NOT NULL,
                payload LONGTEXT NOT NULL,
                imported TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inkorg_side_correction (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                line LONGTEXT NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inkorg_side_flag (
                name VARCHAR(64) NOT NULL,
                imported TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $done[$key] = true;
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inkorg_side_doc (
            name TEXT PRIMARY KEY,
            payload TEXT NOT NULL,
            imported INTEGER NOT NULL DEFAULT 0
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inkorg_side_correction (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            line TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inkorg_side_flag (
            name TEXT PRIMARY KEY,
            imported INTEGER NOT NULL DEFAULT 0
        )'
    );
    $done[$key] = true;
}

/**
 * @template T
 * @param callable(PDO): T $fn
 * @return T
 */
function jeab_inkorg_side_transaction(PDO $pdo, callable $fn): mixed
{
    return jeab_pdo_transaction($pdo, $fn);
}

/**
 * JSON som ännu inte importerats. null = filen finns men går inte att läsa.
 *
 * @return array<string, mixed>|null
 */
function jeab_inkorg_side_json_doc(string $name): ?array
{
    $path = jeab_inkorg_side_path($name);
    if (!is_file($path)) {
        return jeab_inkorg_side_empty($name);
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    if (trim($raw) === '') {
        return jeab_inkorg_side_empty($name);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    return $data;
}

/**
 * @param array<string, mixed> $base
 * @param array<string, mixed> $over
 * @return array<string, mixed>
 */
function jeab_inkorg_side_merge(string $name, array $base, array $over): array
{
    if ($name === 'auto-send-cap' || $name === 'malmo-offer-sends') {
        $seen = [];
        $sends = [];
        foreach (array_merge((array) ($base['sends'] ?? []), (array) ($over['sends'] ?? [])) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = json_encode($row);
            if (!is_string($key) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $sends[] = $row;
        }
        return ['sends' => $sends];
    }
    if ($name === 'hs-uncertain-open') {
        $facts = is_array($base['facts'] ?? null) ? $base['facts'] : [];
        foreach ((array) ($over['facts'] ?? []) as $id => $row) {
            if (is_array($row)) {
                $facts[(string) $id] = $row;
            }
        }
        return ['facts' => $facts];
    }
    if ($name === 'rebook-log') {
        $done = is_array($base['done'] ?? null) ? $base['done'] : [];
        foreach ((array) ($over['done'] ?? []) as $id => $row) {
            if (is_array($row)) {
                $done[(string) $id] = $row;
            }
        }
        return ['done' => $done];
    }
    if ($name === 'quote-preview') {
        $slots = [];
        $lastTid = '';
        $lastText = '';
        $lastAt = '';
        foreach ([$base, $over] as $doc) {
            foreach ((array) ($doc['by_thread'] ?? []) as $id => $slot) {
                if (!is_array($slot)) {
                    continue;
                }
                $id = (string) $id;
                if ($id === '') {
                    continue;
                }
                $slots[$id] = [
                    'text' => (string) ($slot['text'] ?? ''),
                    'at' => (string) ($slot['at'] ?? ''),
                ];
            }
            $tid = trim((string) ($doc['thread'] ?? ''));
            if ($tid === '') {
                continue;
            }
            $lastTid = $tid;
            $lastText = (string) ($doc['text'] ?? '');
            $lastAt = (string) ($doc['at'] ?? '');
            if (!isset($slots[$tid])) {
                $slots[$tid] = ['text' => $lastText, 'at' => $lastAt];
            }
        }
        if ($lastTid === '' && $slots !== []) {
            $lastTid = (string) array_key_last($slots);
            $lastText = (string) ($slots[$lastTid]['text'] ?? '');
            $lastAt = (string) ($slots[$lastTid]['at'] ?? '');
        }

        return [
            'by_thread' => $slots,
            'thread' => $lastTid,
            'text' => $lastText,
            'at' => $lastAt,
        ];
    }
    if ($name === 'settings.json' || $name === 'rules.json') {
        return $over !== [] ? $over : $base;
    }
    throw new InvalidArgumentException('Okänd sidolagring.');
}

/** @return array{doc: array<string, mixed>, imported: bool} */
function jeab_inkorg_side_lock(PDO $pdo, string $name): array
{
    if (!in_array($name, jeab_inkorg_side_names(), true)) {
        throw new InvalidArgumentException('Okänd sidolagring.');
    }
    $empty = jeab_inkorg_side_empty($name);
    $encoded = json_encode($empty);
    if (!is_string($encoded)) {
        throw new RuntimeException($name . ' kunde inte sparas.');
    }
    if (jeab_inkorg_side_driver($pdo) === 'mysql') {
        $pdo->prepare('INSERT IGNORE INTO inkorg_side_doc (name, payload, imported) VALUES (?, ?, 0)')->execute([$name, $encoded]);
        $stmt = $pdo->prepare('SELECT payload, imported FROM inkorg_side_doc WHERE name = ? FOR UPDATE');
    } else {
        $stmt = $pdo->prepare('SELECT payload, imported FROM inkorg_side_doc WHERE name = ?');
    }
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        $pdo->prepare('INSERT INTO inkorg_side_doc (name, payload, imported) VALUES (?, ?, 0)')->execute([$name, $encoded]);
        return ['doc' => $empty, 'imported' => false];
    }
    $doc = json_decode((string) ($row['payload'] ?? ''), true);
    return [
        'doc' => is_array($doc) ? $doc : $empty,
        'imported' => (int) ($row['imported'] ?? 0) === 1,
    ];
}

/** @param array<string, mixed> $doc */
function jeab_inkorg_side_save(PDO $pdo, string $name, array $doc, int $imported): void
{
    $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException($name . ' kunde inte sparas.');
    }
    jeab_inkorg_side_forget($name);
    $imported = $imported === 1 ? 1 : 0;
    $GLOBALS['_JEAB_INKORG_SIDE_IMPORTED'][$name] = $imported === 1;
    if (jeab_inkorg_side_driver($pdo) === 'mysql') {
        $pdo->prepare(
            'INSERT INTO inkorg_side_doc (name, payload, imported) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), imported = VALUES(imported)'
        )->execute([$name, $json, $imported]);
        return;
    }
    $pdo->prepare(
        'INSERT INTO inkorg_side_doc (name, payload, imported) VALUES (?, ?, ?)
         ON CONFLICT(name) DO UPDATE SET payload = excluded.payload, imported = excluded.imported'
    )->execute([$name, $json, $imported]);
}

function jeab_inkorg_side_forget(string $name): void
{
    if (isset($GLOBALS['_JEAB_INKORG_SIDE_PEEK']) && is_array($GLOBALS['_JEAB_INKORG_SIDE_PEEK'])) {
        unset($GLOBALS['_JEAB_INKORG_SIDE_PEEK'][$name . '|0'], $GLOBALS['_JEAB_INKORG_SIDE_PEEK'][$name . '|1']);
    }
    if (isset($GLOBALS['_JEAB_INKORG_JSON_MEM']) && is_array($GLOBALS['_JEAB_INKORG_JSON_MEM'])) {
        unset($GLOBALS['_JEAB_INKORG_JSON_MEM'][$name]);
    }
}

function jeab_inkorg_side_pdo_ready(bool $allowWithoutStorage = false): PDO
{
    if (!$allowWithoutStorage && !jeab_inkorg_side_sql()) {
        throw new RuntimeException('SQL saknas.');
    }
    $pdo = jeab_inkorg_side_pdo();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('SQL saknas.');
    }
    jeab_inkorg_side_ensure($pdo);
    return $pdo;
}

/**
 * Första anropet kopierar JSON in i SQL innan dokumentet lämnas tillbaka.
 *
 * @return array<string, mixed>
 */
function jeab_inkorg_side_doc(string $name, bool $allowWithoutStorage = false): array
{
    return jeab_inkorg_side_update($name, static function (array $doc): array {
        return [$doc, $doc];
    }, $allowWithoutStorage);
}

/**
 * Läser ett dokument utan att skriva om en redan importerad rad.
 * Första gången, när imported inte är 1, kopieras JSON in som side_doc.
 *
 * @return array<string, mixed>
 */
function jeab_inkorg_side_peek(string $name, bool $allowWithoutStorage = false): array
{
    $key = $name . '|' . ($allowWithoutStorage ? '1' : '0');
    if (isset($GLOBALS['_JEAB_INKORG_SIDE_PEEK']) && is_array($GLOBALS['_JEAB_INKORG_SIDE_PEEK']) && array_key_exists($key, $GLOBALS['_JEAB_INKORG_SIDE_PEEK'])) {
        $cached = $GLOBALS['_JEAB_INKORG_SIDE_PEEK'][$key];

        return is_array($cached) ? $cached : jeab_inkorg_side_empty($name);
    }
    $imported = null;
    if (isset($GLOBALS['_JEAB_INKORG_SIDE_IMPORTED']) && is_array($GLOBALS['_JEAB_INKORG_SIDE_IMPORTED']) && array_key_exists($name, $GLOBALS['_JEAB_INKORG_SIDE_IMPORTED'])) {
        $imported = (bool) $GLOBALS['_JEAB_INKORG_SIDE_IMPORTED'][$name];
    }
    $pdo = jeab_inkorg_side_pdo_ready($allowWithoutStorage);
    if ($imported === true) {
        $stmt = $pdo->prepare('SELECT payload FROM inkorg_side_doc WHERE name = ?');
        $stmt->execute([$name]);
        $payload = $stmt->fetchColumn();
        if ($payload !== false) {
            $doc = json_decode((string) $payload, true);
            $doc = is_array($doc) ? $doc : jeab_inkorg_side_empty($name);
            if (!isset($GLOBALS['_JEAB_INKORG_SIDE_PEEK']) || !is_array($GLOBALS['_JEAB_INKORG_SIDE_PEEK'])) {
                $GLOBALS['_JEAB_INKORG_SIDE_PEEK'] = [];
            }
            $GLOBALS['_JEAB_INKORG_SIDE_PEEK'][$key] = $doc;

            return $doc;
        }
        $imported = false;
        $GLOBALS['_JEAB_INKORG_SIDE_IMPORTED'][$name] = false;
    }
    if ($imported !== false) {
        $stmt = $pdo->prepare('SELECT payload, imported FROM inkorg_side_doc WHERE name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        if (is_array($row) && (int) ($row['imported'] ?? 0) === 1) {
            $doc = json_decode((string) ($row['payload'] ?? ''), true);
            $doc = is_array($doc) ? $doc : jeab_inkorg_side_empty($name);
            $GLOBALS['_JEAB_INKORG_SIDE_IMPORTED'][$name] = true;
            if (!isset($GLOBALS['_JEAB_INKORG_SIDE_PEEK']) || !is_array($GLOBALS['_JEAB_INKORG_SIDE_PEEK'])) {
                $GLOBALS['_JEAB_INKORG_SIDE_PEEK'] = [];
            }
            $GLOBALS['_JEAB_INKORG_SIDE_PEEK'][$key] = $doc;

            return $doc;
        }
    }
    $doc = jeab_inkorg_side_doc($name, $allowWithoutStorage);
    $GLOBALS['_JEAB_INKORG_SIDE_IMPORTED'][$name] = true;
    if (!isset($GLOBALS['_JEAB_INKORG_SIDE_PEEK']) || !is_array($GLOBALS['_JEAB_INKORG_SIDE_PEEK'])) {
        $GLOBALS['_JEAB_INKORG_SIDE_PEEK'] = [];
    }
    $GLOBALS['_JEAB_INKORG_SIDE_PEEK'][$key] = $doc;

    return $doc;
}

/**
 * @template T
 * @param callable(array<string, mixed>): array{0:T,1:array<string, mixed>} $fn
 * @return T
 */
function jeab_inkorg_side_update(string $name, callable $fn, bool $allowWithoutStorage = false): mixed
{
    $pdo = jeab_inkorg_side_pdo_ready($allowWithoutStorage);
    return jeab_inkorg_side_transaction($pdo, static function (PDO $pdo) use ($name, $fn): mixed {
        $state = jeab_inkorg_side_lock($pdo, $name);
        $doc = $state['doc'];
        if (!$state['imported']) {
            $json = jeab_inkorg_side_json_doc($name);
            if ($json === null) {
                throw new RuntimeException($name . ' går inte att läsa.');
            }
            $doc = jeab_inkorg_side_merge($name, $doc, $json);
        }
        [$out, $new] = $fn($doc);
        if (!is_array($new)) {
            throw new RuntimeException($name . ' kunde inte sparas.');
        }
        jeab_inkorg_side_save($pdo, $name, $new, 1);
        return $out;
    });
}

/**
 * Samma yta som jeab_inkorg_locked för settings.json och rules.json.
 * $fn får dokumentet (tom array om det saknas) och returnerar [ut, nytt].
 * null som nytt betyder att mutationen inte sparas. Första importen från
 * JSON-filen skrivs ändå, så imported blir 1.
 *
 * @template T
 * @param callable(?array<string, mixed>): array{0:T,1:?array<string, mixed>} $fn
 * @return T
 */
function jeab_inkorg_side_locked(string $name, callable $fn): mixed
{
    $pdo = jeab_inkorg_side_pdo_ready();
    return jeab_inkorg_side_transaction($pdo, static function (PDO $pdo) use ($name, $fn): mixed {
        $state = jeab_inkorg_side_lock($pdo, $name);
        $doc = $state['doc'];
        $imported = $state['imported'];
        if ($name === 'settings.json' && $imported && function_exists('jeab_inkorg_settings_doc')) {
            if (function_exists('jeab_inkorg_side_forget')) {
                jeab_inkorg_side_forget($name);
            }
            $shared = jeab_inkorg_settings_doc();
            if (is_array($shared)) {
                $doc = $shared;
            }
        }
        if (!$imported) {
            $json = jeab_inkorg_side_json_doc($name);
            if ($json === null) {
                throw new RuntimeException($name . ' går inte att läsa.');
            }
            $doc = jeab_inkorg_side_merge($name, $doc, $json);
        }
        [$out, $new] = $fn($doc);
        if ($new === null) {
            if (!$imported) {
                jeab_inkorg_side_save($pdo, $name, $doc, 1);
            }
            return $out;
        }
        if (!is_array($new)) {
            throw new RuntimeException($name . ' kunde inte sparas.');
        }
        jeab_inkorg_side_save($pdo, $name, $new, 1);
        return $out;
    });
}

/**
 * Skriver ett importerat sidodokument tillbaka till JSON-filen.
 * Används vid rollback (data_backend=json / inkorg_storage=json) så att
 * filen får SQL-lägets innehåll innan JSON-vägen tar över.
 */
function jeab_inkorg_side_export(string $name): bool
{
    $pdo = jeab_inkorg_side_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    jeab_inkorg_side_ensure($pdo);
    $stmt = $pdo->prepare('SELECT payload, imported FROM inkorg_side_doc WHERE name = ?');
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    if (!is_array($row) || (int) ($row['imported'] ?? 0) !== 1) {
        return false;
    }
    $doc = json_decode((string) ($row['payload'] ?? ''), true);
    if (!is_array($doc)) {
        throw new RuntimeException($name . ' går inte att exportera.');
    }
    $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json) || $json === '') {
        throw new RuntimeException($name . ' går inte att exportera.');
    }
    $path = jeab_inkorg_side_path($name);
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException($name . ' går inte att exportera.');
    }
    if (file_put_contents($path, $json, LOCK_EX) === false) {
        throw new RuntimeException($name . ' går inte att exportera.');
    }

    return true;
}

function jeab_inkorg_side_cap_why(string $rcpt): string
{
    $doc = jeab_inkorg_side_doc('auto-send-cap');
    return jeab_auto_send_cap_why_in((array) ($doc['sends'] ?? []), $rcpt, time());
}

function jeab_inkorg_side_cap_record(string $key, int $now): void
{
    jeab_inkorg_side_update('auto-send-cap', static function (array $doc) use ($key, $now): array {
        $cut = $now - 30 * 86400;
        $sends = array_values(array_filter((array) ($doc['sends'] ?? []), static fn($row): bool => is_array($row) && (int) ($row['at'] ?? 0) >= $cut));
        $sends[] = ['at' => $now, 'rcpt' => $key];
        $doc['sends'] = $sends;
        return [true, $doc];
    });
}

/** @return list<array<string, mixed>> */
function jeab_inkorg_side_malmo_rows(): array
{
    $doc = jeab_inkorg_side_doc('malmo-offer-sends');
    $out = [];
    foreach ((array) ($doc['sends'] ?? []) as $row) {
        if (is_array($row)) {
            $out[] = $row;
        }
    }
    return $out;
}

/** @param array<string, mixed> $row */
function jeab_inkorg_side_malmo_record(array $row, int $now): void
{
    jeab_inkorg_side_update('malmo-offer-sends', static function (array $doc) use ($row, $now): array {
        $cut = $now - 30 * 86400;
        $sends = [];
        foreach ((array) ($doc['sends'] ?? []) as $old) {
            if (!is_array($old)) {
                continue;
            }
            $at = strtotime((string) ($old['at'] ?? ''));
            if ($at !== false && $at >= $cut) {
                unset($old['email'], $old['to'], $old['customer_name'], $old['address']);
                $sends[] = $old;
            }
        }
        $sends[] = $row;
        $doc['sends'] = $sends;
        return [true, $doc];
    });
}

function jeab_inkorg_side_quote_save(string $tid, string $text): void
{
    jeab_inkorg_side_update('quote-preview', static function (array $doc) use ($tid, $text): array {
        $doc = jeab_inkorg_quote_preview_doc_put($doc, $tid, $text);
        return [true, $doc];
    });
}

/** @param array<string, mixed> $facts */
function jeab_inkorg_side_hs_save(array $facts, bool $allowWithoutStorage = false): void
{
    jeab_inkorg_side_update('hs-uncertain-open', static function (array $doc) use ($facts): array {
        $base = is_array($doc['facts'] ?? null) ? $doc['facts'] : [];
        foreach ($facts as $id => $row) {
            if (is_array($row)) {
                $base[(string) $id] = $row;
            }
        }
        return [true, ['facts' => $base]];
    }, $allowWithoutStorage);
}

/**
 * Läser hs-uncertain-open från SQL. Första gången kopieras JSON in.
 * En redan importerad rad läses utan att skrivas om.
 *
 * @return array<string, mixed>
 */
function jeab_inkorg_side_hs_read(bool $allowWithoutStorage = false): array
{
    $pdo = jeab_inkorg_side_pdo_ready($allowWithoutStorage);
    $stmt = $pdo->prepare('SELECT payload, imported FROM inkorg_side_doc WHERE name = ?');
    $stmt->execute(['hs-uncertain-open']);
    $row = $stmt->fetch();
    if (is_array($row) && (int) ($row['imported'] ?? 0) === 1) {
        $doc = json_decode((string) ($row['payload'] ?? ''), true);

        return is_array($doc) ? $doc : jeab_inkorg_side_empty('hs-uncertain-open');
    }

    return jeab_inkorg_side_doc('hs-uncertain-open', $allowWithoutStorage);
}

/** @param array<string, mixed> $row */
function jeab_inkorg_side_rebook_log(string $msgId, array $row): void
{
    jeab_inkorg_side_update('rebook-log', static function (array $doc) use ($msgId, $row): array {
        $done = is_array($doc['done'] ?? null) ? $doc['done'] : [];
        $done[$msgId] = $row + ['at' => date('c')];
        if (count($done) > 2000) {
            $done = array_slice($done, -2000, null, true);
        }
        $doc['done'] = $done;
        return [true, $doc];
    });
}

function jeab_inkorg_side_rebook_forget(string $msgId): void
{
    if ($msgId === '') {
        return;
    }
    jeab_inkorg_side_update('rebook-log', static function (array $doc) use ($msgId): array {
        $done = is_array($doc['done'] ?? null) ? $doc['done'] : [];
        unset($done[$msgId]);
        $doc['done'] = $done;
        return [true, $doc];
    });
}

function jeab_inkorg_side_flag_imported(PDO $pdo, string $name): bool
{
    if (jeab_inkorg_side_driver($pdo) === 'mysql') {
        $pdo->prepare('INSERT IGNORE INTO inkorg_side_flag (name, imported) VALUES (?, 0)')->execute([$name]);
        $stmt = $pdo->prepare('SELECT imported FROM inkorg_side_flag WHERE name = ? FOR UPDATE');
    } else {
        $stmt = $pdo->prepare('SELECT imported FROM inkorg_side_flag WHERE name = ?');
    }
    $stmt->execute([$name]);
    $value = $stmt->fetchColumn();
    if ($value === false) {
        $pdo->prepare('INSERT INTO inkorg_side_flag (name, imported) VALUES (?, 0)')->execute([$name]);
        return false;
    }
    return (int) $value === 1;
}

function jeab_inkorg_side_flag_set(PDO $pdo, string $name): void
{
    if (jeab_inkorg_side_driver($pdo) === 'mysql') {
        $pdo->prepare(
            'INSERT INTO inkorg_side_flag (name, imported) VALUES (?, 1)
             ON DUPLICATE KEY UPDATE imported = VALUES(imported)'
        )->execute([$name]);
        return;
    }
    $pdo->prepare(
        'INSERT INTO inkorg_side_flag (name, imported) VALUES (?, 1)
         ON CONFLICT(name) DO UPDATE SET imported = excluded.imported'
    )->execute([$name]);
}

function jeab_inkorg_side_correction_add(string $line): void
{
    $line = rtrim($line, "\r\n");
    if ($line === '') {
        return;
    }
    $pdo = jeab_inkorg_side_pdo_ready();
    jeab_inkorg_side_transaction($pdo, static function (PDO $pdo) use ($line): void {
        if (!jeab_inkorg_side_flag_imported($pdo, 'corrections')) {
            $path = jeab_inkorg_side_path('corrections');
            $have = [];
            foreach ($pdo->query('SELECT line FROM inkorg_side_correction') ?: [] as $dbRow) {
                if (is_array($dbRow)) {
                    $have[(string) ($dbRow['line'] ?? '')] = true;
                }
            }
            if (is_file($path)) {
                $raw = file_get_contents($path);
                if ($raw === false) {
                    throw new RuntimeException('corrections går inte att läsa.');
                }
                foreach (preg_split("/\r?\n/", $raw) ?: [] as $old) {
                    $old = rtrim((string) $old, "\r\n");
                    if ($old === '' || isset($have[$old])) {
                        continue;
                    }
                    $pdo->prepare('INSERT INTO inkorg_side_correction (line) VALUES (?)')->execute([$old]);
                    $have[$old] = true;
                }
            }
            jeab_inkorg_side_flag_set($pdo, 'corrections');
        }
        $pdo->prepare('INSERT INTO inkorg_side_correction (line) VALUES (?)')->execute([$line]);
    });
}
