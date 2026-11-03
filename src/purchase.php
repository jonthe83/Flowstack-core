<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/idnr.php';
require_once __DIR__ . '/purchase_store.php';
function jeab_purchase_path(): string
{
    return jeab_data_file('purchases' . '.json');
}

function jeab_purchase_kinds(): array
{
    return [
        'laddbox' => 'Köpt laddbox',
        'batteri' => 'Köpt batteri',
        'sol' => 'Köpt sol',
    ];
}

function jeab_purchase_kind_label(string $kind): string
{
    return jeab_purchase_kinds()[$kind] ?? '';
}

function jeab_purchase_normalize_kind(mixed $raw): ?string
{
    $kind = trim((string) $raw);
    return array_key_exists($kind, jeab_purchase_kinds()) ? $kind : null;
}

function jeab_purchase_sql_pdo(): ?PDO
{
    return jeab_purchases_sql_pdo();
}

function jeab_purchase_cache_all(): ?array
{
    if (!array_key_exists('_JEAB_PURCHASE_LIST', $GLOBALS) || !is_array($GLOBALS['_JEAB_PURCHASE_LIST'])) {
        return null;
    }
    return $GLOBALS['_JEAB_PURCHASE_LIST'];
}

/** @param array<string, mixed> $all */
function jeab_purchase_cache_replace(array $all): void
{
    $GLOBALS['_JEAB_PURCHASE_LIST'] = $all;
    $GLOBALS['_JEAB_PURCHASE_ROWS'] = [];
    $GLOBALS['_JEAB_ORDRAR_VISIBLE'] = [];
    $GLOBALS['_JEAB_LISTA_CELLS'] = [];
    unset($GLOBALS['_JEAB_PURCHASE_KIND_MAP']);
}

function jeab_purchase_cache_forget(): void
{
    unset($GLOBALS['_JEAB_PURCHASE_LIST'], $GLOBALS['_JEAB_PURCHASE_ROWS'], $GLOBALS['_JEAB_ORDRAR_VISIBLE'], $GLOBALS['_JEAB_LISTA_CELLS'], $GLOBALS['_JEAB_PURCHASE_KIND_MAP'], $GLOBALS['_JEAB_PURCHASE_JSON'], $GLOBALS['_JEAB_PURCHASE_JSON_REQ']);
}

/** @param array<string, mixed>|null $row */
/**
 * Order utan SQL-läge, i den här processen. Live med läge sql kastar fortfarande.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_purchase_memory_write(array $row): array
{
    $id = (int) ($row['reminder_id'] ?? 0);
    $row['reminder_id'] = $id;
    $stored = jeab_purchase_with_lock(static function () use ($id, $row): array {
        $all = jeab_purchase_json_read();
        $current = $all[(string) $id] ?? null;
        if (is_array($current)) {
            $incomingSnaps = array_key_exists('snapshots', $row) && is_array($row['snapshots']) ? $row['snapshots'] : null;
            $row = array_replace($current, $row);
            if ($incomingSnaps !== null) {
                $prior = is_array($current['snapshots'] ?? null) ? $current['snapshots'] : [];
                $bag = function_exists('jeab_snapshots_overlay_types')
                    ? jeab_snapshots_overlay_types($prior, $incomingSnaps)
                    : $incomingSnaps;
                if ($bag === []) {
                    unset($row['snapshots']);
                } else {
                    $row['snapshots'] = $bag;
                }
            }
        }
        $row['reminder_id'] = $id;
        $all[(string) $id] = $row;
        jeab_purchase_json_write_file($all);

        return $row;
    });
    if (!is_array($stored)) {
        $stored = $row;
    }
    if (!isset($GLOBALS['_JEAB_PURCHASE_LIST']) || !is_array($GLOBALS['_JEAB_PURCHASE_LIST'])) {
        $GLOBALS['_JEAB_PURCHASE_LIST'] = jeab_purchase_json_read();
    }
    jeab_purchase_cache_note($id, $stored);

    return $stored;
}

/**
 * Orderns personnummer ska behålla bindestrecket.
 * Flaggan för 12 siffror gäller kontaktlagret och pausas under orderskrivningen.
 *
 * @template T
 * @param callable(): T $fn
 * @return T
 */
function jeab_purchase_without_contact_pnr_digits(callable $fn): mixed
{
    $paused = 0;
    while (!empty($GLOBALS['_JEAB_CONTACTS_SOLE_PNR_DIGITS']) && function_exists('jeab_contacts_sole_pnr_digits_pop') && $paused < 8) {
        jeab_contacts_sole_pnr_digits_pop();
        $paused++;
    }
    try {
        return $fn();
    } finally {
        if (function_exists('jeab_contacts_sole_pnr_digits_push')) {
            for ($i = 0; $i < $paused; $i++) {
                jeab_contacts_sole_pnr_digits_push();
            }
        }
    }
}

function jeab_purchase_sql_required(): bool
{
    return function_exists('jeab_purchases_storage_mode') && jeab_purchases_storage_mode() === 'sql';
}

function jeab_purchase_cache_note(int $id, ?array $row): void
{
    $key = (string) $id;
    if (!isset($GLOBALS['_JEAB_PURCHASE_ROWS']) || !is_array($GLOBALS['_JEAB_PURCHASE_ROWS'])) {
        $GLOBALS['_JEAB_PURCHASE_ROWS'] = [];
    }
    if ($row === null) {
        unset($GLOBALS['_JEAB_PURCHASE_ROWS'][$key]);
    } else {
        $GLOBALS['_JEAB_PURCHASE_ROWS'][$key] = $row;
    }
    $GLOBALS['_JEAB_ORDRAR_VISIBLE'] = [];
    $GLOBALS['_JEAB_LISTA_CELLS'] = [];
    unset($GLOBALS['_JEAB_PURCHASE_KIND_MAP']);
    if (array_key_exists('_JEAB_PURCHASE_LIST', $GLOBALS) && is_array($GLOBALS['_JEAB_PURCHASE_LIST'])) {
        if ($row === null) {
            unset($GLOBALS['_JEAB_PURCHASE_LIST'][$key]);
        } else {
            $GLOBALS['_JEAB_PURCHASE_LIST'][$key] = $row;
        }
    }
}

function jeab_purchase_json_read(): array
{
    $path = jeab_purchase_path();
    clearstatcache(true, $path);
    $req = (string) ($_SERVER['REQUEST_TIME_FLOAT'] ?? '0');
    $size = is_file($path) ? (int) filesize($path) : 0;
    $mtime = is_file($path) ? (int) filemtime($path) : 0;
    $key = $req . '|' . $mtime . '|' . $size;
    if (
        isset($GLOBALS['_JEAB_PURCHASE_JSON'], $GLOBALS['_JEAB_PURCHASE_JSON_REQ'])
        && is_array($GLOBALS['_JEAB_PURCHASE_JSON'])
        && $GLOBALS['_JEAB_PURCHASE_JSON_REQ'] === $key
    ) {
        return $GLOBALS['_JEAB_PURCHASE_JSON'];
    }
    if (!is_file($path)) {
        $GLOBALS['_JEAB_PURCHASE_JSON'] = [];
        $GLOBALS['_JEAB_PURCHASE_JSON_REQ'] = $key;

        return [];
    }
    $raw = json_decode((string) file_get_contents($path), true);
    $rows = is_array($raw) ? $raw : [];
    $GLOBALS['_JEAB_PURCHASE_JSON'] = $rows;
    $GLOBALS['_JEAB_PURCHASE_JSON_REQ'] = $key;

    return $rows;
}

/** Skriver orderfilen när SQL saknas, så nästa process ser samma rader. */
function jeab_purchase_json_write_file(array $all): bool
{
    $path = jeab_purchase_path();
    $dir = dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) {
        return false;
    }
    if ((int) ($GLOBALS['_JEAB_ORDRAR_DELETE_LOCK'] ?? 0) !== 1) {
        foreach (jeab_purchase_json_read() as $key => $row) {
            $id = (int) (is_array($row) ? ($row['reminder_id'] ?? $key) : $key);
            if ($id <= 0) {
                continue;
            }
            if (!isset($all[(string) $id]) && !isset($all[$id])) {
                throw new RuntimeException('Ordern kan bara raderas via Radera.');
            }
        }
    }
    $json = json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);

        return false;
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);

        return false;
    }
    unset($GLOBALS['_JEAB_PURCHASE_JSON'], $GLOBALS['_JEAB_PURCHASE_JSON_REQ']);

    return true;
}

function jeab_purchase_sql_live(): ?PDO
{
    $pdo = jeab_purchase_sql_pdo();
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $pdo = function_exists('jeab_purchases_store_pdo') ? jeab_purchases_store_pdo() : null;
    if (!$pdo instanceof PDO) {
        return null;
    }
    if (function_exists('jeab_purchases_store_ensure')) {
        jeab_purchases_store_ensure($pdo);
    }

    return $pdo;
}

function jeab_purchase_sql_fail(): never
{
    static $logged = false;
    if (!$logged && function_exists('jeab_log_app_error')) {
        $logged = true;
        jeab_log_app_error('purchase', 'SQL saknas');
    }
    throw new RuntimeException('SQL saknas');
}

/**
 * JSON-filen skrivs när flaggan är på, eller när köpen inte är i SQL-läge.
 * När flaggan är av och köpen är i SQL-läge skrivs ingen fil.
 * true bara om $sqlWritten och varje reminder_id redan finns i SQL,
 * eller om raderna skrivs här. Annars false.
 * put, patch, delete och save_all anropar det här bara när SQL-PDO saknas.
 * De skriver SQL själva och returnerar innan dess.
 *
 * @param array<string, mixed> $all
 */
function jeab_purchase_json_write(array $all, bool $sqlWritten = false): bool
{
    if (jeab_purchase_sql_live() === null) {
        jeab_purchase_sql_fail();
    }
    if ($all === []) {
        return false;
    }

    return jeab_purchase_json_write_sql($all, $sqlWritten);
}

/**
 * Ingen JSON-fil. Skriver raderna, eller bekräftar att de redan finns.
 *
 * @param array<string, mixed> $all
 */
function jeab_purchase_json_write_sql(array $all, bool $sqlWritten): bool
{
    $pdo = jeab_purchase_sql_live();
    if (!$pdo instanceof PDO || $all === []) {
        return false;
    }
    if ($sqlWritten && jeab_purchase_sql_ids_present($pdo, $all)) {
        return true;
    }
    try {
        foreach ($all as $row) {
            if (!is_array($row)) {
                return false;
            }
            $id = (int) ($row['reminder_id'] ?? 0);
            if ($id <= 0) {
                return false;
            }
            $saved = jeab_sql_purchases_put($pdo, $row);
            if ((int) ($saved['reminder_id'] ?? 0) !== $id) {
                return false;
            }
        }
    } catch (Throwable) {
        return false;
    }

    return true;
}

/** @param array<string, mixed> $all */
function jeab_purchase_sql_ids_present(PDO $pdo, array $all): bool
{
    if ($all === []) {
        return false;
    }
    foreach ($all as $row) {
        if (!is_array($row)) {
            return false;
        }
        $id = (int) ($row['reminder_id'] ?? 0);
        if ($id <= 0 || !is_array(jeab_sql_purchases_get($pdo, $id))) {
            return false;
        }
    }

    return true;
}

function jeab_purchase_with_lock(callable $fn): mixed
{
    $path = jeab_purchase_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $lockPath = $path . '.lock';
    $fh = @fopen($lockPath, 'c');
    if ($fh === false) {
        throw new RuntimeException('Kunde inte låsa orderlagret.');
    }
    @chmod($lockPath, 0600);
    try {
        if (!flock($fh, LOCK_EX)) {
            throw new RuntimeException('Kunde inte låsa orderlagret.');
        }
        return $fn();
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function jeab_purchase_all(): array
{
    $cached = jeab_purchase_cache_all();
    if (is_array($cached)) {
        return $cached;
    }
    $pdo = jeab_purchase_sql_live();
    if (!$pdo instanceof PDO) {
        if (!(function_exists('jeab_sql_disabled_json') && jeab_sql_disabled_json())
            && (!function_exists('jeab_purchases_storage_mode') || jeab_purchases_storage_mode() !== 'sql')) {
            $all = jeab_purchase_json_read();
            jeab_purchase_cache_replace($all);

            return $all;
        }
        jeab_purchase_sql_fail();
    }
    $all = jeab_sql_purchases_load($pdo);
    jeab_purchase_cache_replace($all);
    return $all;
}

/**
 * Läser ordrar för en kontakt via contact_id-kolumnen.
 * Påfyllnaden från payload körs i tick, inte i den här läsningen.
 *
 * @return list<array<string, mixed>>
 */
function jeab_purchase_for_contact(string $contactId, int $limit = 0): array
{
    $GLOBALS['_JEAB_PURCHASE_FOR_CONTACT_CALLS'] = (int) ($GLOBALS['_JEAB_PURCHASE_FOR_CONTACT_CALLS'] ?? 0) + 1;
    $contactId = trim($contactId);
    if ($contactId === '') {
        return [];
    }
    $pdo = jeab_purchase_sql_live();
    if (!$pdo instanceof PDO) {
        jeab_purchase_sql_fail();
    }
    $sql = 'SELECT reminder_id, payload FROM purchases WHERE contact_id = ?';
    if ($limit > 0) {
        $sql .= ' ORDER BY updated_at DESC LIMIT ' . max(1, $limit);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([mb_substr($contactId, 0, 64)]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $data = jeab_sql_purchases_decode($row['reminder_id'] ?? 0, $row['payload'] ?? '');
        if (is_array($data)) {
            $out[] = $data;
        }
    }
    return $out;
}

/**
 * Hela lagret byts inte. Ordrar skrivs rad för rad.
 *
 * @param array<string, mixed> $all
 */
/** Id som finns i orderlagret just nu. En bulkskrivning får inte tappa något av dem. */
function jeab_purchase_live_ids(): array
{
    $out = [];
    $note = static function (int $id) use (&$out): void {
        if ($id > 0) {
            $out[$id] = true;
        }
    };
    $pdo = jeab_purchase_sql_live();
    if ($pdo instanceof PDO) {
        try {
            foreach ($pdo->query('SELECT reminder_id FROM purchases') ?: [] as $row) {
                $note((int) (is_array($row) ? ($row['reminder_id'] ?? 0) : 0));
            }
        } catch (Throwable) {
        }

        return $out;
    }
    if (!jeab_purchase_sql_required()) {
        foreach (jeab_purchase_json_read() as $key => $row) {
            $note((int) (is_array($row) ? ($row['reminder_id'] ?? $key) : $key));
        }
    }

    return $out;
}

function jeab_purchase_delete_guard(): void
{
    if ((int) ($GLOBALS['_JEAB_ORDRAR_DELETE_LOCK'] ?? 0) !== 1) {
        throw new RuntimeException('Ordern kan bara raderas via Radera.');
    }
}

function jeab_purchase_save_all(array $all): void
{
    if (function_exists('jeab_data_write_guard')) {
        jeab_data_write_guard();
    }
    $before = jeab_purchase_live_ids();
    foreach ($all as $row) {
        if (!is_array($row)) {
            continue;
        }
        jeab_purchase_put($row);
    }
    $after = jeab_purchase_live_ids();
    foreach ($before as $id => $_own) {
        if (!isset($after[$id])) {
            throw new RuntimeException('Ordern kan bara raderas via Radera.');
        }
    }
}

function jeab_purchase_get(int $reminderId): ?array
{
    if ($reminderId <= 0) {
        return null;
    }
    $key = (string) $reminderId;
    $list = jeab_purchase_cache_all();
    if (is_array($list) && isset($list[$key]) && is_array($list[$key])) {
        return $list[$key];
    }
    $rows = $GLOBALS['_JEAB_PURCHASE_ROWS'] ?? null;
    if (is_array($rows) && isset($rows[$key]) && is_array($rows[$key])) {
        return $rows[$key];
    }
    $pdo = jeab_purchase_sql_live();
    if (!$pdo instanceof PDO) {
        if (!function_exists('jeab_purchases_storage_mode') || jeab_purchases_storage_mode() !== 'sql') {
            $all = jeab_purchase_json_read();
            if (isset($all[$key]) && is_array($all[$key])) {
                jeab_purchase_cache_note($reminderId, $all[$key]);

                return $all[$key];
            }

            return null;
        }
        jeab_purchase_sql_fail();
    }
    $row = jeab_sql_purchases_get($pdo, $reminderId);
    if (is_array($row)) {
        jeab_purchase_cache_note($reminderId, $row);
    }

    return $row;
}

/**
 * Visning av köpet. Flaggan av lämnar raden. Flaggan på läser kundfält via paint_order.
 * Lagret (get/put) rörs inte.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_purchase_shown(array $row): array
{
    if (function_exists('jeab_contacts_sole') && jeab_contacts_sole() && function_exists('jeab_contacts_sole_paint_order')) {
        return jeab_contacts_sole_paint_order($row);
    }

    return $row;
}

function jeab_purchase_by_booking(int $bookingId): ?array
{
    if ($bookingId <= 0) {
        return null;
    }
    foreach (jeab_purchase_all() as $row) {
        if (is_array($row) && (int) ($row['booking_id'] ?? 0) === $bookingId) {
            return $row;
        }
    }
    return null;
}

/**
 * booking_id → reminder_id utan att avkoda varje order.
 * Saknas SQL blir listan tom. Kalendern ska ändå rita bokningen.
 *
 * @return array<int, int>
 */
function jeab_purchase_booking_ids_cache_path(): string
{
    return rtrim(sys_get_temp_dir(), '/') . '/jeab-purchase-booking-ids.json';
}

/** @return array<int, int>|null */
function jeab_purchase_booking_ids_cache_read(): ?array
{
    $raw = @file_get_contents(jeab_purchase_booking_ids_cache_path());
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data) || (time() - (int) ($data['t'] ?? 0)) > 120 || !is_array($data['map'] ?? null)) {
        return null;
    }
    $out = [];
    foreach ($data['map'] as $bookingId => $reminderId) {
        $bookingId = (int) $bookingId;
        $reminderId = (int) $reminderId;
        if ($bookingId > 0 && $reminderId > 0) {
            $out[$bookingId] = $reminderId;
        }
    }

    return $out;
}

/** @param array<int, int> $map */
function jeab_purchase_booking_ids_cache_write(array $map): void
{
    $payload = ['t' => time(), 'map' => $map];
    @file_put_contents(jeab_purchase_booking_ids_cache_path(), json_encode($payload), LOCK_EX);
}

function jeab_purchase_booking_ids(): array
{
    $cached = jeab_purchase_booking_ids_cache_read();
    if (is_array($cached)) {
        return $cached;
    }
    $pdo = jeab_purchase_sql_live();
    if (!$pdo instanceof PDO) {
        return [];
    }
    $column = 'payload';
    $driver = jeab_sql_purchases_is_mysql($pdo) ? 'mysql' : 'sqlite';
    if (function_exists('jeab_settings_value') && jeab_settings_value($pdo, $driver, 'purchases_list_json_v1') === 'ok') {
        $column = 'list_json';
    }
    $expr = jeab_sql_purchases_is_mysql($pdo)
        ? "CAST(JSON_UNQUOTE(JSON_EXTRACT($column, '$.booking_id')) AS UNSIGNED)"
        : "CAST(json_extract($column, '$.booking_id') AS INTEGER)";
    $out = [];
    try {
        $stmt = $pdo->query('SELECT reminder_id, ' . $expr . ' AS booking_id FROM purchases WHERE ' . $expr . ' > 0');
    } catch (Throwable) {
        return [];
    }
    if ($stmt === false) {
        return [];
    }
    foreach ($stmt as $row) {
        if (!is_array($row)) {
            continue;
        }
        $bookingId = (int) ($row['booking_id'] ?? 0);
        $reminderId = (int) ($row['reminder_id'] ?? 0);
        if ($bookingId > 0 && $reminderId > 0) {
            $out[$bookingId] = $reminderId;
        }
    }
    jeab_purchase_booking_ids_cache_write($out);

    return $out;
}

function jeab_purchase_put(array $row, bool $allowDeletedCreate = false): array
{
    if (function_exists('jeab_data_write_guard')) {
        jeab_data_write_guard();
    }
    $bookingCache = jeab_purchase_booking_ids_cache_path();
    if (is_file($bookingCache)) {
        @unlink($bookingCache);
    }
    if (function_exists('jeab_ordrar_v3_steps_forget')) {
        jeab_ordrar_v3_steps_forget();
    }
    $id = (int) ($row['reminder_id'] ?? 0);
    if ($id <= 0) {
        throw new InvalidArgumentException('Påminnelsen finns inte.');
    }
    if (
        function_exists('jeab_contacts_strict_assert_link')
        && jeab_contacts_strict()
        && function_exists('jeab_contacts_sole')
        && jeab_contacts_sole()
        && !is_array(jeab_purchase_get($id))
    ) {
        jeab_contacts_strict_assert_link('order', (string) ($row['contact_id'] ?? ''));
    }
    return jeab_purchase_without_contact_pnr_digits(static function () use ($row, $id, $allowDeletedCreate): array {
        $row = jeab_idnr_normalize_row($row);
        if (
            !$allowDeletedCreate
            && function_exists('jeab_ordrar_deleted_blocks')
            && jeab_ordrar_deleted_blocks($id)
            && !is_array(jeab_purchase_get($id))
        ) {
            throw new InvalidArgumentException('Ärendet är raderat.');
        }
        $active = $GLOBALS['_JEAB_ACTIVE_STORE'] ?? null;
        if ($active instanceof JeabStore && function_exists('jeab_ordrar_fill_missing_names')) {
            $row = jeab_ordrar_fill_missing_names($row, $active);
        }
        $row['reminder_id'] = $id;
        $pdo = jeab_purchase_sql_live();
        if (!$pdo instanceof PDO) {
            if (!jeab_purchase_sql_required()) {
                return jeab_purchase_memory_write($row);
            }
            jeab_purchase_sql_fail();
        }
        $saved = jeab_sql_purchases_put($pdo, $row);
        jeab_purchase_cache_note($id, $saved);

        return $saved;
    });
}

/**
 * Läser om en order, ändrar bara det mutatorn returnerar och skriver den raden.
 * JSON: hela läs-ändra-skriv under flock. MySQL: transaktion och SELECT … FOR UPDATE.
 * Mutator som returnerar null skriver inget. Saknad rad ger null.
 *
 * @param callable(array<string, mixed>): ?array $mutator
 * @return array<string, mixed>|null
 */
function jeab_purchase_patch(int $reminderId, callable $mutator): ?array
{
    if ($reminderId <= 0) {
        return null;
    }

    return jeab_purchase_without_contact_pnr_digits(static function () use ($reminderId, $mutator): ?array {
        $pdo = jeab_purchase_sql_live();
        if (!$pdo instanceof PDO) {
            if (!jeab_purchase_sql_required()) {
                $current = jeab_purchase_get($reminderId);
                if (!is_array($current)) {
                    return null;
                }
                $next = $mutator($current);
                if (!is_array($next)) {
                    return null;
                }
                $next['reminder_id'] = $reminderId;

                return jeab_purchase_memory_write($next);
            }
            jeab_purchase_sql_fail();
        }
        $next = jeab_sql_purchases_patch($pdo, $reminderId, $mutator);
        if (is_array($next)) {
            jeab_purchase_cache_note($reminderId, $next);
        }

        return $next;
    });
}

/** Ordrar Radera (ordrar.delete → jeab_ordrar_delete) depends on this. */
function jeab_purchase_delete(int $reminderId): array
{
    jeab_purchase_delete_guard();
    if ($reminderId <= 0) {
        throw new InvalidArgumentException('Ordern finns inte.');
    }
    $pdo = jeab_purchase_sql_live();
    if (!$pdo instanceof PDO) {
        if (!jeab_purchase_sql_required()) {
            $row = jeab_purchase_get($reminderId);
            if (!is_array($row)) {
                return [];
            }
            jeab_purchase_with_lock(static function () use ($reminderId): void {
                $all = jeab_purchase_json_read();
                unset($all[(string) $reminderId]);
                jeab_purchase_json_write_file($all);
            });
            jeab_purchase_cache_note($reminderId, null);

            return $row;
        }
        jeab_purchase_sql_fail();
    }
    $row = jeab_purchase_get($reminderId);
    if (!is_array($row)) {
        return [];
    }
    jeab_sql_purchases_delete($pdo, $reminderId);
    jeab_purchase_cache_note($reminderId, null);

    return $row;
}

function jeab_purchase_url(array $extra = []): string
{
    $q = ['p' => 'purchase'];
    $id = array_key_exists('id', $extra) ? (int) $extra['id'] : (int) ($_GET['id'] ?? 0);
    $step = array_key_exists('step', $extra) ? (int) $extra['step'] : (int) ($_GET['step'] ?? 1);
    $company = array_key_exists('co', $extra) ? $extra['co'] : ($_GET['co'] ?? jeab_selected_company());
    unset($extra['id'], $extra['step'], $extra['co']);
    if ($id > 0) {
        $q['id'] = $id;
    }
    $q['step'] = $step >= 2 ? 2 : 1;
    $q['co'] = jeab_normalize_company($company) ?? jeab_selected_company();
    $who = array_key_exists('who', $extra) ? $extra['who'] : (function_exists('jeab_reminder_who_filter') ? jeab_reminder_who_filter() : null);
    unset($extra['who']);
    if (is_string($who) && $who !== '') {
        $q['who'] = $who;
    }
    foreach ($extra as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $q[$key] = $value;
    }
    return jeab_url('index.php' . chr(63) . http_build_query($q));
}

function jeab_purchase_amount(mixed $value): float
{
    if (is_int($value) || is_float($value)) {
        return (float) $value;
    }
    $text = trim((string) $value);
    $text = preg_replace('/\s*(?:kr|kronor|sek)\.?\s*$/iu', '', $text) ?? $text;
    $text = preg_replace('/\s*:-\s*$/u', '', $text) ?? $text;
    $text = trim($text);
    // number_format i jeab_ordrar_kr använder smalt hårt mellanslag (U+202F).
    $text = preg_replace('/\p{Z}+/u', '', $text) ?? $text;
    if (str_contains($text, ',') && str_contains($text, '.')) {
        $text = str_replace('.', '', $text);
        $text = str_replace(',', '.', $text);
    } elseif (substr_count($text, '.') > 1) {
        $text = str_replace('.', '', $text);
    } elseif (preg_match('/^\d+\.\d{3}$/', $text) === 1) {
        $text = str_replace('.', '', $text);
    } else {
        $text = str_replace(',', '.', $text);
    }
    return is_numeric($text) ? (float) $text : 0.0;
}

function jeab_purchase_templates(string $kind, ?JeabStore $store = null): array
{
    $loaded = jeab_offert_load_standard($store);
    $offert = $loaded['offert'];
    $ev = jeab_offert_ev_calc($loaded['ev']);
    $calc = jeab_offert_calc($offert);
    $lines = [];
    if ($kind === 'laddbox') {
        $lines = [
            ['title' => '5G6', 'amount' => $ev['c4'] * $ev['d4']],
            ['title' => '5G16', 'amount' => $ev['c5'] * $ev['d5']],
            ['title' => 'Dosa', 'amount' => $ev['c6'] * $ev['d6']],
            ['title' => 'Laddare', 'amount' => $ev['c7'] * $ev['d7']],
            ['title' => 'Säkring', 'amount' => $ev['c8'] * $ev['d8']],
            ['title' => 'Lastbalans', 'amount' => $ev['c9'] * $ev['d9']],
            ['title' => 'Extra material', 'amount' => $ev['c10']],
            ['title' => 'Extra arbetstid', 'amount' => $ev['c11'] * $ev['hourly_extra']],
            ['title' => 'Extra externa', 'amount' => $ev['c12']],
            ['title' => 'Elcentral', 'amount' => $ev['c21'] * $ev['hour_low']],
        ];
    } elseif ($kind === 'batteri') {
        $lines = [
            ['title' => 'Batterimoduler', 'amount' => $calc['b3'] * $calc['c27']],
            ['title' => 'Övrigt batteri', 'amount' => $calc['c4']],
            ['title' => 'Batteriarbete', 'amount' => $calc['c5']],
            ['title' => 'Montage', 'amount' => 1543.0 + 1179.0],
        ];
        if (!empty($calc['laddare'])) {
            $hourly = (float) ($offert['hourly'] ?? 750);
            $lines[] = ['title' => 'Elbilsladdare', 'amount' => (float) ($calc['laddare_unit'] ?? 0)];
            $lines[] = ['title' => 'Laddarearbete', 'amount' => (float) ($calc['laddare_hours'] ?? 0) * $hourly];
        }
    } elseif ($kind === 'sol') {
        $inverter = !empty($calc['b15']) ? $calc['c15'] : $calc['c16'];
        $inverterTitle = !empty($calc['b15']) ? '12 kW TP2' : '12 kW ej TP2';
        $lines = [
            ['title' => 'Paneler', 'amount' => $calc['b14'] * $calc['c28']],
            ['title' => 'Övrigt sol', 'amount' => $calc['c13']],
            ['title' => $inverterTitle, 'amount' => $inverter],
            ['title' => 'Solarbete', 'amount' => $calc['c17']],
        ];
    }
    $out = [];
    foreach (array_slice($lines, 0, 10) as $line) {
        $out[] = [
            'title' => (string) ($line['title'] ?? ''),
            'amount' => round((float) ($line['amount'] ?? 0), 2),
        ];
    }
    return $out;
}

function jeab_purchase_pad_lines(array $lines): array
{
    $out = [];
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $out[] = [
            'title' => trim((string) ($line['title'] ?? '')),
            'amount' => jeab_purchase_amount($line['amount'] ?? 0),
        ];
        if (count($out) >= 20) {
            break;
        }
    }
    while (count($out) < 20) {
        $out[] = ['title' => '', 'amount' => 0.0];
    }
    return $out;
}

function jeab_purchase_lines_from_input(array $input): array
{
    $titles = $input['line_title'] ?? [];
    $amounts = $input['line_amount'] ?? [];
    if (!is_array($titles)) {
        $titles = [];
    }
    if (!is_array($amounts)) {
        $amounts = [];
    }
    $lines = [];
    $max = max(count($titles), count($amounts), 20);
    for ($i = 0; $i < min(20, $max); $i++) {
        $lines[] = [
            'title' => trim((string) ($titles[$i] ?? '')),
            'amount' => jeab_purchase_amount($amounts[$i] ?? 0),
        ];
    }
    return jeab_purchase_pad_lines($lines);
}

function jeab_purchase_reminder_name(array $reminder): string
{
    // Kopian kan vara borttagen: namnet kommer då från kontakten.
    if (trim((string) ($reminder['contact_id'] ?? '')) !== '' && function_exists('jeab_contacts_sole_paint_reminder')) {
        $reminder = jeab_contacts_sole_paint_reminder($reminder);
    }
    $name = trim((string) ($reminder['customer_name'] ?? ''));
    if ($name !== '' && $name !== '·') {
        return $name;
    }
    return trim((string) ($reminder['title'] ?? ''));
}

function jeab_purchase_blank(int $reminderId, string $kind, array $reminder, ?JeabStore $store = null): array
{
    $company = jeab_reminder_company($reminder);
    $name = jeab_purchase_reminder_name($reminder);
    $kindNorm = jeab_purchase_normalize_kind($kind) ?? '';
    $lines = $kindNorm !== ''
        ? jeab_purchase_pad_lines(jeab_purchase_templates($kindNorm, $store))
        : jeab_purchase_pad_lines([]);
    return [
        'reminder_id' => $reminderId,
        'kind' => $kindNorm,
        'name' => $name,
        'company' => $company,
        'email' => trim((string) ($reminder['email'] ?? '')),
        'phone' => jeab_normalize_booking_phone($reminder['phone'] ?? '') ?? '',
        'address' => trim((string) ($reminder['address'] ?? '')),
        'reminder_created_at' => trim((string) ($reminder['created_at'] ?? $reminder['created'] ?? $reminder['createdAt'] ?? '')),
        'lines' => $lines,
        'applicant' => [
            'name' => $name,
            'email' => '',
            'phone' => '',
        ],
        'coapplicant' => [
            'name' => '',
            'email' => '',
            'phone' => '',
        ],
        'step' => 1,
        'done' => false,
        'activity' => [],
        'log' => [],
        'created_at' => jeab_now(),
        'steps' => [
            'kund_bestallt' => '',
            'fatt_alla_uppgifter' => '',
            'foranmald' => '',
            'bokad_tid' => '',
            'bestalld' => '',
            'order_skriven' => '',
            'faktura_skapad' => '',
        ],
        'updated_at' => jeab_now(),
    ];
}

function jeab_purchase_start(JeabStore $store, int $reminderId, string $kind = '', bool $allowDeletedCreate = false): array
{
    $wanted = jeab_purchase_normalize_kind($kind);
    $reminder = jeab_get_reminder($store, $reminderId);
    if (!$reminder) {
        throw new InvalidArgumentException('Påminnelsen finns inte.');
    }
    if (!$allowDeletedCreate && function_exists('jeab_ordrar_deleted_blocks') && jeab_ordrar_deleted_blocks($reminderId, $reminder)) {
        throw new InvalidArgumentException('Ärendet är raderat.');
    }
    $tags = jeab_parse_tags($reminder['tags'] ?? []);
    if (!in_array('vunnen', $tags, true)) {
        throw new InvalidArgumentException('Tagga vunnen först.');
    }
    $prev = jeab_purchase_get($reminderId);
    $keptAddress = is_array($prev) && trim((string) ($prev['address'] ?? '')) !== '';
    $keepKind = $wanted ?? (is_array($prev) ? (jeab_purchase_normalize_kind($prev['kind'] ?? '') ?? '') : '');
    $row = jeab_purchase_blank($reminderId, is_string($keepKind) ? $keepKind : '', $reminder, $store);
    if (is_array($prev)) {
        $prevKind = jeab_purchase_normalize_kind($prev['kind'] ?? '') ?? '';
        $same = $wanted === null || $prevKind === '' || $prevKind === (string) $keepKind;
        if ($same) {
            foreach ([
                'kind', 'name', 'email', 'phone', 'address', 'personnummer',
                'fastighetsbeteckning', 'huvudsokande', 'huvudsokande_address',
                'huvudsokande_andel', 'huvudsokande_lagfart', 'medsokande', 'owners', 'install_what', 'pris',
                'install_note', 'vaxelriktare', 'kwh', 'kundpris', 'anlaggnings_id',
                'inkop_lines', 'stop3_saved', 'fortnox_order', 'fortnox_customer',
                'fortnox_order_rows', 'fortnox_att_betala', 'fortnox_error', 'fortnox_manual_rows',
                'reminder_created_at', 'booking_id', 'huvudsokande_manual', 'identity_filled_at',
                'activity', 'log', 'natbolag', 'foranmalan_saved', 'orderrader_saved',
                'postnr', 'postnummer', 'ort', 'gata', 'objekt_saved_at', 'identity_source', 'address_edited_at', 'address_previous',
                'fullmakt_token', 'fullmakt_url', 'fullmakt_mailed', 'fullmakt_sent_at',
                'fullmakt_error', 'fullmakt_signed', 'fullmakt_mail_path',
                'fullmakt_start_at', 'fullmakt_channel', 'fullmakt_history', 'fullmakt_new_at',
                'seom_fullmakt_status', 'seom_sam_sent_at', 'seom_sam_resent_at',
                'seom_customer_sent_at', 'seom_customer_signed_at', 'seom_fullmakt_error',
                'seom_mail_path', 'seom_hooks', 'seom_last_hook',
                'bestall_draft', 'shop', 'onskad_leverans', 'önskad_leverans',
                'leverans', 'faktisk_leverans', 'bestalld_at', 'bestallning_status',
                'aprilice_order', 'aprilice_ordernr', 'aprilice_order_no', 'aprilice_shop_order',
                'lightleap_order', 'lightleap_ordernr', 'lightleap_order_no', 'lightleap_shop_order',
                'foranmalan_status',
                'foranmalan_draft', 'foranmalan_draft_at',
                'solkoll_facility_id', 'solkoll_status', 'solkoll_products_added', 'solkoll_note',
                'solkoll_added', 'solkoll_unmatched', 'solkoll_differs', 'solkoll_fixed', 'solkoll_duplicates', 'solkoll_synced_at',
                'solkoll_error', 'solkoll_todos_done', 'solkoll_todos_total',
                'kalkyl', 'closed_at', 'allt_klart', 'source', 'webshop', 'webshop_link',
                'assignees', 'assignee',
            ] as $key) {
                if (!array_key_exists($key, $prev)) {
                    continue;
                }
                $row[$key] = $prev[$key];
            }
            $row['lines'] = jeab_purchase_pad_lines($prev['lines'] ?? $row['lines']);
            $row['applicant'] = is_array($prev['applicant'] ?? null) ? $prev['applicant'] : $row['applicant'];
            $row['coapplicant'] = is_array($prev['coapplicant'] ?? null) ? $prev['coapplicant'] : $row['coapplicant'];
            $row['done'] = !empty($prev['done']);
            if (trim((string) ($prev['created_at'] ?? '')) !== '') {
                $row['created_at'] = (string) $prev['created_at'];
            }
            if (is_array($prev['steps'] ?? null)) {
                foreach ($prev['steps'] as $key => $value) {
                    $row['steps'][(string) $key] = is_string($value) || is_numeric($value) ? trim((string) $value) : '';
                }
            }
        }
    }
    if (trim((string) ($row['name'] ?? '')) === '') {
        $row['name'] = jeab_purchase_reminder_name($reminder);
    }
    if (!is_array($row['applicant'] ?? null)) {
        $row['applicant'] = ['name' => '', 'email' => '', 'phone' => ''];
    }
    if (trim((string) ($row['applicant']['name'] ?? '')) === '') {
        $row['applicant']['name'] = (string) $row['name'];
    }
    if (trim((string) ($row['address'] ?? '')) === '') {
        $row['address'] = trim((string) ($reminder['address'] ?? ''));
    }
    $savedZip = function_exists('jeab_ordrar_postnummer_digits')
        ? jeab_ordrar_postnummer_digits((string) ($row['postnummer'] ?? $row['postnr'] ?? ''))
        : '';
    if (!$keptAddress && $savedZip === '' && function_exists('jeab_reminder_strip_postnummer')) {
        $stripped = jeab_reminder_strip_postnummer((string) ($row['address'] ?? ''));
        if ($stripped !== '') {
            $row['address'] = $stripped;
        }
    }
    if (trim((string) ($row['steps']['kund_bestallt'] ?? '')) === '') {
        $row['steps']['kund_bestallt'] = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
    }
    if (trim((string) ($row['ort'] ?? '')) === '') {
        $city = trim((string) ($reminder['ort'] ?? ''));
        if ($city === '' && function_exists('jeab_reminder_parse_city')) {
            $city = jeab_reminder_parse_city(trim((string) ($reminder['address'] ?? '')));
            if ($city === '') {
                $city = jeab_reminder_parse_city(trim((string) ($reminder['title'] ?? '')));
            }
        }
        if ($city !== '') {
            $row['ort'] = $city;
        }
    }
    if (function_exists('jeab_ordrar_apply_reminder_identity')) {
        $row = jeab_ordrar_apply_reminder_identity($row, $reminder);
    }
    if (function_exists('jeab_ordrar_apply_reminder_owner')) {
        $row = jeab_ordrar_apply_reminder_owner($row, $reminder);
    }
    if (function_exists('jeab_ordrar_vunnen_source') && function_exists('jeab_ordrar_apply_vunnen_source')) {
        $row = jeab_ordrar_apply_vunnen_source($row, jeab_ordrar_vunnen_source($reminder), $reminder);
    }
    if (function_exists('jeab_ordrar_apply_carried_price')) {
        $row = jeab_ordrar_apply_carried_price($row, $reminder);
    }
    $row['step'] = 1;
    $saved = jeab_purchase_put($row, $allowDeletedCreate);
    if (!is_array($prev) && function_exists('jeab_ordrar_lm_lookup_on_create')) {
        $saved = jeab_ordrar_lm_lookup_on_create($store, $saved);
    }
    return $saved;
}

function jeab_purchase_save(JeabStore $store, array $input): array
{
    $reminderId = (int) ($input['id'] ?? 0);
    $reminder = jeab_get_reminder($store, $reminderId);
    if (!$reminder) {
        throw new InvalidArgumentException('Påminnelsen finns inte.');
    }
    $prev = jeab_purchase_get($reminderId);
    if (!is_array($prev) && function_exists('jeab_ordrar_deleted_blocks') && jeab_ordrar_deleted_blocks($reminderId, $reminder)) {
        throw new InvalidArgumentException('Ärendet är raderat.');
    }
    $kind = jeab_purchase_normalize_kind($input['kind'] ?? ($prev['kind'] ?? ''));
    if ($kind === null) {
        throw new InvalidArgumentException('Välj Köpt laddbox, Köpt batteri eller Köpt sol.');
    }
    $row = is_array($prev) ? $prev : jeab_purchase_blank($reminderId, $kind, $reminder, $store);
    $row['kind'] = $kind;
    $row['company'] = jeab_normalize_company($input['company'] ?? $row['company'] ?? null) ?? jeab_reminder_company($reminder);
    $step = (int) ($input['step'] ?? 1);
    if ($step <= 1) {
        $row['email'] = trim((string) ($input['email'] ?? $row['email'] ?? ''));
        $row['phone'] = jeab_normalize_booking_phone($input['phone'] ?? $row['phone'] ?? '') ?? '';
        $row['lines'] = jeab_purchase_lines_from_input($input);
        $row['step'] = 2;
        $row['done'] = false;
    } else {
        $applicant = is_array($row['applicant'] ?? null) ? $row['applicant'] : [];
        $co = is_array($row['coapplicant'] ?? null) ? $row['coapplicant'] : [];
        $applicant['name'] = trim((string) ($input['applicant_name'] ?? $applicant['name'] ?? ''));
        $co['name'] = trim((string) ($input['coapplicant_name'] ?? $co['name'] ?? ''));
        $applicant['email'] = (string) ($row['email'] ?? '');
        $applicant['phone'] = (string) ($row['phone'] ?? '');
        $co['email'] = (string) ($row['email'] ?? '');
        $co['phone'] = (string) ($row['phone'] ?? '');
        $row['applicant'] = $applicant;
        $row['coapplicant'] = $co;
        $row['step'] = 2;
        $row['done'] = true;
    }
    $row['updated_at'] = jeab_now();
    if (is_array($prev) && function_exists('jeab_contacts_sole_divert_order')) {
        $row = jeab_contacts_sole_divert_order($prev, $row);
    }
    return jeab_purchase_put($row);
}

function jeab_purchase_status_html(array $reminder): string
{
    unset($reminder);
    return '';
}

function jeab_purchase_pick_html(array $reminder): string
{
    return '';
}

function jeab_purchase_existing_or_start_vunnen(JeabStore $store, int $reminderId): ?array
{
    if ($reminderId <= 0) {
        return null;
    }
    $existing = jeab_purchase_get($reminderId);
    if ($existing) {
        return $existing;
    }
    $reminder = jeab_get_reminder($store, $reminderId);
    if (!$reminder) {
        return null;
    }
    $tags = jeab_parse_tags($reminder['tags'] ?? []);
    if (!in_array('vunnen', $tags, true)) {
        return null;
    }
    return jeab_purchase_start($store, $reminderId, '');
}

function jeab_purchase_ordrar_url(array $purchase, ?array $reminder = null): string
{
    $id = (int) ($purchase['reminder_id'] ?? 0);
    $company = $purchase['company'] ?? ($reminder ? jeab_reminder_company($reminder) : jeab_selected_company());
    return jeab_ordrar_url([
        'id' => $id,
        'co' => $company,
    ]);
}
