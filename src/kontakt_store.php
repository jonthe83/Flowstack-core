<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/contact_store.php';

/**
 * Kontaktregister och inkorgens contacts.json.
 * Registret är bara SQL. Saknas databasen loggas det och anropet kastar.
 * Flaggan kontakter_storage väljer fortfarande om inkorgens contacts.json
 * skrivs, tills den flaggan är sql. Sidan läser bara flaggan contacts_list_cols_v1.
 */

const JEAB_KONTAKTER_STORAGE_KEY = 'kontakter_storage';

function jeab_contact_store_pdo(): ?PDO
{
    if (!function_exists('jeab_store') || !empty($GLOBALS['_JEAB_STORE_OPENING'])) {
        return null;
    }
    try {
        $store = jeab_store();
        return $store instanceof JeabPdoStore ? $store->pdo() : null;
    } catch (Throwable) {
        return null;
    }
}

/** Inkorgens contacts.json. Registret använder inte det här läget. */
function jeab_kontakter_storage_mode(): string
{
    $legacy = 'json';
    $pdo = jeab_contact_store_pdo();
    if ($pdo instanceof PDO && function_exists('jeab_settings_value')) {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        $legacy = jeab_settings_value($pdo, $driver, JEAB_KONTAKTER_STORAGE_KEY) === 'sql' ? 'sql' : 'json';
    }
    if (function_exists('jeab_cutover_storage_file')) {
        $file = jeab_cutover_storage_file(JEAB_KONTAKTER_STORAGE_KEY);
        if ($file === 'sql' || $file === 'json') {
            $legacy = $file;
        }
    }
    return function_exists('jeab_data_storage') ? jeab_data_storage('contacts', $legacy) : $legacy;
}

/** GET på kontakter ska bara läsa. Schema och påfyllnad hör hemma i tick. */
function jeab_kontakter_page_read(): bool
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'GET') {
        return false;
    }
    $page = strtolower(trim((string) ($_GET['p'] ?? '')));

    return $page === 'kontakter';
}

function jeab_kontakter_use_sql(): bool
{
    if (jeab_kontakter_storage_mode() !== 'sql') {
        return false;
    }
    $pdo = jeab_contact_store_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    if (!jeab_kontakter_page_read() && function_exists('jeab_ensure_contacts_tables')) {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        jeab_ensure_contacts_tables($pdo, $driver);
    }
    return true;
}

function jeab_kontakter_writes_sql_only(): bool
{
    if (!empty($GLOBALS['_JEAB_DATA_MIGRATE']) && jeab_contact_store_pdo() instanceof PDO) {
        return true;
    }
    return jeab_kontakter_use_sql();
}

function jeab_kontakter_sql_fail(): never
{
    static $logged = false;
    if (!$logged && function_exists('jeab_log_app_error')) {
        $logged = true;
        jeab_log_app_error('contacts', 'SQL saknas');
    }
    throw new RuntimeException('Kontaktregistret kan inte sparas.');
}

function jeab_kontakter_pdo_store(): ?JeabPdoContactStore
{
    $pdo = jeab_contact_store_pdo();
    if (!$pdo instanceof PDO) {
        return null;
    }
    if (!jeab_kontakter_page_read() && function_exists('jeab_ensure_contacts_tables')) {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        jeab_ensure_contacts_tables($pdo, $driver);
    }
    return new JeabPdoContactStore($pdo);
}

/**
 * @param callable(array<string, array<string, mixed>>): mixed $fn
 */
function jeab_kontakter_json_mutate(callable $fn): mixed
{
    unset($fn);
    jeab_kontakter_sql_fail();
}

/**
 * Alla adresser på en kontakt. Indexeras, så listan inte läser hela registret.
 *
 * @param array<string, mixed> $row
 * @return list<string>
 */
function jeab_contact_email_list(array $row): array
{
    $set = [];
    $add = static function (string $email) use (&$set): void {
        $email = function_exists('jeab_kontakt_email') ? jeab_kontakt_email($email) : mb_strtolower(trim($email));
        if ($email !== '' && str_contains($email, '@')) {
            $set[$email] = true;
        }
    };
    if (function_exists('jeab_kontakt_row_emails')) {
        foreach (jeab_kontakt_row_emails($row) as $email) {
            $add((string) $email);
        }
    }
    foreach (['email', 'customer_email'] as $key) {
        $add((string) ($row[$key] ?? ''));
    }
    foreach (['owners', 'extra_owners'] as $list) {
        foreach ((array) ($row[$list] ?? []) as $owner) {
            if (is_array($owner)) {
                $add((string) ($owner['email'] ?? ''));
            }
        }
    }
    foreach (['email2', 'email3'] as $key) {
        $add((string) ($row[$key] ?? ''));
    }

    return array_keys($set);
}

function jeab_contact_emails_missing(Throwable $e): bool
{
    $message = $e->getMessage();

    return str_contains($message, 'contact_emails')
        && (str_contains($message, 'no such table')
            || str_contains($message, 'Base table or view not found')
            || str_contains($message, '42S02')
            || str_contains($message, '1146'));
}

function jeab_contact_emails_sync(PDO $pdo, string $id, array $row): void
{
    $id = trim($id);
    if ($id === '') {
        return;
    }
    if (function_exists('jeab_contact_emails_table_exists') && !jeab_contact_emails_table_exists($pdo)) {
        return;
    }
    try {
        $pdo->prepare('DELETE FROM contact_emails WHERE contact_id = ?')->execute([$id]);
        $ins = $pdo->prepare('INSERT INTO contact_emails (email, contact_id) VALUES (?, ?)');
        foreach (jeab_contact_email_list($row) as $email) {
            $ins->execute([mb_substr($email, 0, 191), $id]);
        }
    } catch (Throwable $e) {
        if (jeab_contact_emails_missing($e)) {
            if (function_exists('jeab_contact_emails_table_exists')) {
                jeab_contact_emails_table_exists($pdo, false);
            }

            return;
        }
        throw $e;
    }
}

/**
 * @param list<string> $emails
 * @return array<string, true>
 */
function jeab_contact_emails_find(array $emails): array
{
    $want = [];
    foreach ($emails as $email) {
        $email = function_exists('jeab_kontakt_email') ? jeab_kontakt_email((string) $email) : mb_strtolower(trim((string) $email));
        if ($email !== '') {
            $want[$email] = true;
        }
    }
    if ($want === []) {
        return [];
    }
    $GLOBALS['_JEAB_CONTACT_EMAIL_FINDS'] = (int) ($GLOBALS['_JEAB_CONTACT_EMAIL_FINDS'] ?? 0) + 1;
    $pdo = jeab_contact_store_pdo();
    if (!$pdo instanceof PDO) {
        return [];
    }
    $found = [];
    foreach (array_chunk(array_keys($want), 200) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $queries = ['SELECT owner_email AS email FROM contacts WHERE owner_email IN (' . $ph . ')'];
        if (function_exists('jeab_contact_secondary_columns_ready') && jeab_contact_secondary_columns_ready($pdo)) {
            $queries[] = 'SELECT email2 AS email FROM contacts WHERE email2 IN (' . $ph . ')';
            $queries[] = 'SELECT email3 AS email FROM contacts WHERE email3 IN (' . $ph . ')';
        }
        if (!function_exists('jeab_contact_emails_table_exists') || jeab_contact_emails_table_exists($pdo)) {
            array_unshift($queries, 'SELECT email FROM contact_emails WHERE email IN (' . $ph . ')');
        }
        foreach ($queries as $sql) {
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($chunk);
            } catch (Throwable $e) {
                if (jeab_contact_emails_missing($e)) {
                    if (function_exists('jeab_contact_emails_table_exists')) {
                        jeab_contact_emails_table_exists($pdo, false);
                    }
                    continue;
                }
                throw $e;
            }
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $email) {
                $email = mb_strtolower(trim((string) $email));
                if ($email !== '') {
                    $found[$email] = true;
                }
            }
        }
    }

    return $found;
}

/** @param array<string, mixed> $row */
function jeab_contact_store_index_fields(array $row): array
{
    return [
        'street_key' => trim((string) ($row['street_key'] ?? '')),
        'postal_code' => function_exists('jeab_kontakt_postal') ? jeab_kontakt_postal((string) ($row['postal_code'] ?? '')) : trim((string) ($row['postal_code'] ?? '')),
        'owner_email' => function_exists('jeab_kontakt_row_email') ? jeab_kontakt_row_email($row) : '',
        'owner_phone' => function_exists('jeab_kontakt_row_phone') ? jeab_kontakt_row_phone($row) : '',
        'owner_name_fold' => function_exists('jeab_kontakt_fold_text') && function_exists('jeab_kontakt_row_name')
            ? jeab_kontakt_fold_text(jeab_kontakt_row_name($row))
            : '',
    ];
}

/**
 * Kolumner för listan: bolag, sökbar ort och samma sortering som PHP-listan.
 *
 * @param array<string, mixed> $row
 * @return array{company:string,city_fold:string,sort_key:string,sort_city:string}
 */
function jeab_contact_store_list_fields(array $row): array
{
    $company = function_exists('jeab_normalize_company')
        ? (jeab_normalize_company($row['company'] ?? null) ?? '')
        : trim((string) ($row['company'] ?? ''));
    $city = (string) ($row['city'] ?? '');
    $cityFold = function_exists('jeab_kontakt_fold_text') ? jeab_kontakt_fold_text($city) : trim($city);
    $missing = function_exists('jeab_kontakt_has_address')
        ? !jeab_kontakt_has_address($row)
        : trim((string) ($row['street'] ?? '')) === '';
    $sortText = $missing
        ? (function_exists('jeab_kontakt_list_name') ? jeab_kontakt_list_name($row) : '')
        : (string) ($row['street'] ?? '');
    $sortKey = function_exists('jeab_kontakt_sort_key') ? jeab_kontakt_sort_key($sortText) : $sortText;
    $sortCity = function_exists('jeab_kontakt_sort_key') ? jeab_kontakt_sort_key($city) : $city;

    return [
        'company' => mb_substr($company, 0, 32),
        'city_fold' => mb_substr($cityFold, 0, 191),
        'sort_key' => mb_substr($sortKey, 0, 191),
        'sort_city' => mb_substr($sortCity, 0, 191),
    ];
}

/**
 * Fyller listkolumner i bitar. En settings-flagga hoppar över jobbet när det är klart,
 * så en sidvisning inte läser hela registret.
 */
function jeab_contacts_list_backfill(PDO $pdo): void
{
    static $seen = [];
    $oid = spl_object_id($pdo);
    $stamp = (string) ($_SERVER['REQUEST_TIME_FLOAT'] ?? '1');
    if (($seen[$oid] ?? '') === $stamp) {
        return;
    }
    $seen[$oid] = $stamp;
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    if (function_exists('jeab_ensure_contacts_tables')) {
        jeab_ensure_contacts_tables($pdo, $driver);
    }
    if (function_exists('jeab_settings_value') && jeab_settings_value($pdo, $driver, 'contacts_list_cols_v1') === 'ok') {
        return;
    }
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    if (!function_exists('jeab_db_get_lock') || !jeab_db_get_lock($pdo, 'jeab_contacts_list_backfill')) {
        return;
    }
    try {
        jeab_pdo_transaction($pdo, static function () use ($pdo, $driver): void {
            $stmt = $pdo->query('SELECT id, row_json FROM contacts WHERE list_ready = 0 LIMIT 400');
            $rows = $stmt ? $stmt->fetchAll() : [];
            if ($rows === []) {
                if (function_exists('jeab_settings_put')) {
                    jeab_settings_put($pdo, $driver, 'contacts_list_cols_v1', 'ok');
                }

                return;
            }
            $upd = $pdo->prepare('UPDATE contacts SET company = ?, city_fold = ?, sort_key = ?, sort_city = ?, list_ready = 1 WHERE id = ?');
            foreach ($rows as $dbRow) {
                if (!is_array($dbRow)) {
                    continue;
                }
                $data = json_decode((string) ($dbRow['row_json'] ?? ''), true);
                $list = is_array($data) ? jeab_contact_store_list_fields($data) : [
                    'company' => '',
                    'city_fold' => '',
                    'sort_key' => '',
                    'sort_city' => '',
                ];
                $upd->execute([
                    $list['company'],
                    $list['city_fold'],
                    $list['sort_key'],
                    $list['sort_city'],
                    (string) ($dbRow['id'] ?? ''),
                ]);
            }
        });
    } catch (Throwable) {
    } finally {
        if (function_exists('jeab_db_release_lock')) {
            jeab_db_release_lock($pdo, 'jeab_contacts_list_backfill');
        }
    }
}

/** @return array<string, array<string, mixed>> */
function jeab_kontakter_json_file_load(): array
{
    $path = function_exists('jeab_kontakter_path') ? jeab_kontakter_path() : '';
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    $rows = is_array($data) ? ($data['contacts'] ?? null) : null;
    if (!is_array($rows)) {
        return [];
    }
    $out = [];
    foreach ($rows as $id => $row) {
        if (is_array($row)) {
            $out[(string) $id] = $row;
        }
    }
    return $out;
}

/** @return array<string, array<string, mixed>> */
function jeab_inkorg_contacts_json_map(): array
{
    if (!function_exists('jeab_inkorg_dir')) {
        return [];
    }
    $path = jeab_inkorg_dir() . '/contacts.json';
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    $rows = is_array($data) ? ($data['contacts'] ?? null) : null;
    if (!is_array($rows)) {
        return [];
    }
    $out = [];
    foreach ($rows as $id => $row) {
        if (is_array($row)) {
            $out[(string) $id] = $row;
        }
    }
    return $out;
}

function jeab_inkorg_contacts_sql_empty_log(): void
{
    static $logged = false;
    if ($logged) {
        return;
    }
    $logged = true;
    error_log('Inkorgskontakter: SQL är tom, JSON läses inte.');
}

/**
 * SQL är enda läskällan först när DATA_BACKEND är mysql efter en verifierad flytt.
 * kontakter_storage=sql räcker inte: en deploy ska läsa likadant som innan flytten.
 */
function jeab_kontakter_sql_is_sole_source(): bool
{
    if (function_exists('jeab_data_type_forced')
        && jeab_data_type_forced('sql', 'contacts')
        && jeab_data_type_forced('sql', 'inkorg_contacts')) {
        return true;
    }
    return function_exists('jeab_data_backend_explicit')
        && jeab_data_backend_explicit()
        && function_exists('jeab_data_backend')
        && jeab_data_backend() === 'mysql';
}

/**
 * Kontaktspärren på (STRICT/SOLE): inkorg_contacts är en dubblett av contacts och skrivs inte.
 * Gäller locked(). upsert() och replaceAll() används av migrering och undo (kontakter_sql_migrate.php) och lämnas.
 * Saknas jeab_contacts_inbox_hold (PR 1 ej deployad) används jeab_contacts_strict_active.
 * Saknas båda räknas tabellen som fryst (stängt) och det loggas.
 */
function jeab_inkorg_contacts_write_frozen(): bool
{
    if (!function_exists('jeab_contacts_inbox_hold') && is_file(__DIR__ . '/contacts_strict.php')) {
        require_once __DIR__ . '/contacts_strict.php';
    }
    if (function_exists('jeab_contacts_inbox_hold')) {
        return jeab_contacts_inbox_hold();
    }
    if (function_exists('jeab_contacts_strict_active')) {
        return jeab_contacts_strict_active();
    }
    static $logged = false;
    if (!$logged) {
        $logged = true;
        error_log('inkorg_contacts: kontaktspärren går inte att läsa, skrivning stoppad (fryst)');
    }

    return true;
}

/**
 * Svar från locked() när skrivningen stoppades. Array får frozen=true,
 * en räkning blir 0 och ett nytt id som aldrig sparades blir null.
 *
 * @param array<string, mixed> $contacts
 */
function jeab_inkorg_contacts_frozen_result(mixed $out, array $contacts): mixed
{
    $GLOBALS['_JEAB_INKORG_CONTACTS_FROZEN'] = (int) ($GLOBALS['_JEAB_INKORG_CONTACTS_FROZEN'] ?? 0) + 1;
    error_log('inkorg_contacts: skrivning hoppad över, kontaktspärren är på');
    if (is_array($out)) {
        $out['frozen'] = true;

        return $out;
    }
    if (is_int($out)) {
        return 0;
    }
    if (is_string($out) && $out !== '' && !isset($contacts[$out])) {
        return null;
    }

    return $out;
}

/**
 * Inkorgens contacts.json, eller SQL när flaggan är på.
 * Rader som bara finns i JSON följer med som läsreserv tills DATA_BACKEND är mysql.
 * Då lämnas en tom SQL-tabell tom. Import sker bara i ett uttryckligt migrate.
 *
 * @return array<string, array<string, mixed>>
 */
function jeab_inkorg_contacts_marker_present(): bool
{
    if (!function_exists('jeab_data_dir')) {
        return false;
    }
    $dir = rtrim(jeab_data_dir(), '/');

    return $dir !== '' && is_file($dir . '/inkorg/contacts.json.migrated');
}

function jeab_kontakt_inbox_read(): array
{
    if (jeab_inkorg_contacts_marker_present()) {
        return [];
    }
    if (!jeab_kontakter_use_sql()) {
        return jeab_inkorg_contacts_json_map();
    }
    $pdo = jeab_contact_store_pdo();
    if (!$pdo instanceof PDO) {
        return jeab_inkorg_contacts_json_map();
    }
    $sql = (new JeabPdoInboxContactStore($pdo))->sqlAll();
    if (jeab_kontakter_sql_is_sole_source()) {
        return $sql;
    }
    if ($sql === []) {
        return jeab_inkorg_contacts_json_map();
    }
    foreach (jeab_inkorg_contacts_json_map() as $id => $row) {
        if (!isset($sql[$id])) {
            $sql[$id] = $row;
        }
    }
    return $sql;
}

/** @param callable(?array): array{0:mixed,1:mixed} $fn */
function jeab_kontakt_inbox_locked(callable $fn): mixed
{
    if (jeab_kontakter_writes_sql_only()) {
        $pdo = jeab_contact_store_pdo();
        if ($pdo instanceof PDO) {
            return (new JeabPdoInboxContactStore($pdo))->locked($fn);
        }
    }
    if (!function_exists('jeab_inkorg_locked')) {
        throw new RuntimeException('Inkorgen kan inte sparas.');
    }
    return jeab_inkorg_locked('contacts.json', $fn);
}

/** @param array<string, mixed> $row */
function jeab_kontakt_inbox_index_fields(array $row): array
{
    $email = '';
    foreach ((array) ($row['emails'] ?? []) as $one) {
        $norm = function_exists('jeab_norm_email') ? jeab_norm_email((string) $one) : strtolower(trim((string) $one));
        if ($norm !== '') {
            $email = $norm;
            break;
        }
    }
    $mobile = trim((string) ($row['mobile'] ?? ''));
    return [
        'email' => mb_substr($email, 0, 191),
        'mobile' => mb_substr($mobile, 0, 32),
    ];
}

/** LIKE-escape är ! så MariaDB inte läser backslash som strängescape. */
function jeab_kontakt_like_bang(string $value): string
{
    return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
}

/**
 * Prefix utan jokertecken hoppar över ESCAPE, så SQLite kan använda indexet.
 *
 * @return array{pattern:string,expr:string}
 */
function jeab_kontakt_like_prefix(string $value): array
{
    if (!str_contains($value, '!') && !str_contains($value, '%') && !str_contains($value, '_')) {
        return ['pattern' => $value . '%', 'expr' => 'LIKE ?'];
    }

    return ['pattern' => jeab_kontakt_like_bang($value) . '%', 'expr' => "LIKE ? ESCAPE '!'"];
}

/**
 * Intervall som betyder «börjar med». SQLite använder inte index för LIKE
 * när LIKE är skiftlägesokänsligt. Telefon och e-post är redan vikta.
 * Övre gränsen måste ligga efter prefixet i både binär ordning och
 * utf8mb4_unicode_ci, så 9 går till a och z eller skiljetecken faller tillbaka till LIKE.
 *
 * @return array{lower:string,upper:string}|null
 */
function jeab_kontakt_prefix_span(string $value): ?array
{
    if ($value === '' || preg_match('/\A[\x21-\x7e]+\z/', $value) !== 1) {
        return null;
    }
    $last = $value[strlen($value) - 1];
    $ord = ord($last);
    if ($last === '9') {
        $upper = 'a';
    } elseif (($ord >= 0x30 && $ord <= 0x38) || ($ord >= 0x61 && $ord <= 0x79) || ($ord >= 0x41 && $ord <= 0x59)) {
        $upper = chr($ord + 1);
    } else {
        return null;
    }

    return [
        'lower' => $value,
        'upper' => substr($value, 0, -1) . $upper,
    ];
}

/**
 * Tom, e-post, telefon eller fri text.
 * Fem siffror utan landskod eller inledande 0 är postnummer och stannar som text.
 */
function jeab_kontakt_search_kind(string $query): string
{
    $query = trim($query);
    if ($query === '') {
        return 'empty';
    }
    if (str_contains($query, '@') && preg_match('/\s/u', $query) !== 1 && !str_starts_with($query, '@')) {
        return 'email';
    }
    $compact = preg_replace('/[\s().\-]/u', '', $query) ?? $query;
    if (preg_match('/^\+?\d+$/', $compact) !== 1) {
        return 'text';
    }
    $digits = ltrim($compact, '+');
    if ($digits === '') {
        return 'text';
    }
    $mobile = str_starts_with($compact, '+')
        || str_starts_with($digits, '0')
        || (str_starts_with($digits, '46') && strlen($digits) >= 9);
    if (strlen($digits) >= 6 || ($mobile && strlen($digits) >= 4)) {
        return 'phone';
    }

    return 'text';
}

/**
 * Prefix mot lagrad +46-form. En eller två kandidater, så indexet kan användas.
 *
 * @return list<string>
 */
function jeab_kontakt_phone_prefixes(string $query): array
{
    $compact = preg_replace('/[^\d+]/', '', $query) ?? '';
    $digits = preg_replace('/\D/', '', $compact) ?? '';
    if ($digits === '') {
        return [];
    }
    $out = [];
    $add = static function (string $prefix) use (&$out): void {
        $prefix = trim($prefix);
        if ($prefix === '' || strlen(ltrim($prefix, '+')) < 3) {
            return;
        }
        $out[$prefix] = $prefix;
    };
    if (str_starts_with($compact, '+') || str_starts_with($digits, '00')) {
        $national = str_starts_with($digits, '00') ? substr($digits, 2) : $digits;
        $add('+' . ltrim($national, '0'));
        if (str_starts_with($national, '0')) {
            $add('+46' . substr($national, 1));
        }
    } elseif (str_starts_with($digits, '0')) {
        $add('+46' . substr($digits, 1));
    } elseif (str_starts_with($digits, '46') && strlen($digits) >= 9) {
        $add('+' . $digits);
    } else {
        $add('+' . $digits);
        $add('+46' . $digits);
    }

    return array_values($out);
}

/**
 * E-post och telefon är prefix. Namn, gata, ort och postnummer är oförändrat innehållssök.
 *
 * @return array{sql:string,args:list<string>,kind:string}
 */
function jeab_kontakt_search_clause(string $query, bool $useEmailTable = true): array
{
    $query = trim($query);
    if ($query === '') {
        return ['sql' => '', 'args' => [], 'kind' => 'empty'];
    }
    $kind = jeab_kontakt_search_kind($query);
    if ($kind === 'email') {
        $span = jeab_kontakt_prefix_span($query);
        if ($span !== null) {
            return [
                'sql' => $useEmailTable
                    ? ' AND ((owner_email >= ? AND owner_email < ?) OR id IN (SELECT contact_id FROM contact_emails WHERE email >= ? AND email < ?))'
                    : ' AND (owner_email >= ? AND owner_email < ?)',
                'args' => $useEmailTable
                    ? [$span['lower'], $span['upper'], $span['lower'], $span['upper']]
                    : [$span['lower'], $span['upper']],
                'kind' => 'email',
            ];
        }
        $like = jeab_kontakt_like_prefix($query);

        return [
            'sql' => $useEmailTable
                ? ' AND (owner_email ' . $like['expr'] . ' OR id IN (SELECT contact_id FROM contact_emails WHERE email ' . $like['expr'] . '))'
                : ' AND owner_email ' . $like['expr'],
            'args' => $useEmailTable ? [$like['pattern'], $like['pattern']] : [$like['pattern']],
            'kind' => 'email',
        ];
    }
    if ($kind === 'phone') {
        $prefixes = jeab_kontakt_phone_prefixes($query);
        if ($prefixes !== []) {
            $parts = [];
            $args = [];
            foreach ($prefixes as $prefix) {
                $span = jeab_kontakt_prefix_span($prefix);
                if ($span !== null) {
                    $parts[] = '(owner_phone >= ? AND owner_phone < ?)';
                    $args[] = $span['lower'];
                    $args[] = $span['upper'];
                    continue;
                }
                $like = jeab_kontakt_like_prefix($prefix);
                $parts[] = 'owner_phone ' . $like['expr'];
                $args[] = $like['pattern'];
            }

            return [
                'sql' => ' AND (' . implode(' OR ', $parts) . ')',
                'args' => $args,
                'kind' => 'phone',
            ];
        }
    }
    $like = '%' . jeab_kontakt_like_bang($query) . '%';

    return [
        'sql' => ' AND (owner_name_fold LIKE ? ESCAPE \'!\' OR street_key LIKE ? ESCAPE \'!\' OR city_fold LIKE ? ESCAPE \'!\' OR postal_code LIKE ? ESCAPE \'!\' OR owner_email LIKE ? ESCAPE \'!\' OR owner_phone LIKE ? ESCAPE \'!\')',
        'args' => array_fill(0, 6, $like),
        'kind' => 'text',
    ];
}

final class JeabPdoContactStore implements JeabContactStore
{
    public function __construct(private PDO $pdo)
    {
        if (jeab_kontakter_page_read()) {
            return;
        }
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        jeab_ensure_contacts_tables($this->pdo, $driver);
    }

    private function isMysql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    /** Läser bara. Saknade orderkolumner får inte skapa DDL på sidvisningen. */
    private function purchasesOfficeReady(): bool
    {
        $key = spl_object_id($this->pdo);
        static $cache = [];
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $need = ['contact_id' => false, 'company' => false];
        if (function_exists('jeab_schema_probe_allowed') && !jeab_schema_probe_allowed()) {
            $cache[$key] = true;

            return true;
        }
        try {
            if ($this->isMysql()) {
                $stmt = $this->pdo->query('SHOW COLUMNS FROM purchases');
                $cols = $stmt ? $stmt->fetchAll() : [];
                foreach ($cols as $col) {
                    $name = (string) ($col['Field'] ?? '');
                    if (array_key_exists($name, $need)) {
                        $need[$name] = true;
                    }
                }
            } else {
                $stmt = $this->pdo->query('PRAGMA table_info(purchases)');
                foreach ($stmt ? $stmt->fetchAll() : [] as $col) {
                    $name = (string) ($col['name'] ?? '');
                    if (array_key_exists($name, $need)) {
                        $need[$name] = true;
                    }
                }
            }
        } catch (Throwable) {
            $cache[$key] = false;

            return false;
        }
        $cache[$key] = $need['contact_id'] && $need['company'];

        return $cache[$key];
    }

    /**
     * Samma träffar som de sju kontorsvillkoren, en gång.
     * Eget bolag, koppling i det bolaget, eller tomt bolag utan koppling till ett annat.
     */
    private function officeUnionSql(): string
    {
        return 'SELECT id FROM contacts WHERE company = ?
            UNION
            SELECT contact_id FROM reminders WHERE company = ? AND contact_id <> \'\'
            UNION
            SELECT contact_id FROM bookings WHERE company = ? AND contact_id <> \'\'
            UNION
            SELECT contact_id FROM purchases WHERE company = ? AND contact_id <> \'\'
            UNION
            SELECT id FROM contacts WHERE company = \'\' AND id NOT IN (
                SELECT contact_id FROM reminders WHERE contact_id <> \'\' AND company <> \'\' AND company <> ?
                UNION
                SELECT contact_id FROM bookings WHERE contact_id <> \'\' AND company <> \'\' AND company <> ?
                UNION
                SELECT contact_id FROM purchases WHERE contact_id <> \'\' AND company <> \'\' AND company <> ?
            )';
    }

    /**
     * Id-listan för kontoret, en SELECT. Återanvänds i chip, träffar och sida.
     * Null lämnar UNION i frågan. Över 30000 id gör samma sak.
     * Frågan som använder listan delar IN i högst 900 platshållare.
     *
     * @return list<string>|null
     */
    private function officeIdList(string $office): ?array
    {
        try {
            $stmt = $this->pdo->prepare($this->officeUnionSql());
            $stmt->execute([$office, $office, $office, $office, $office, $office, $office]);
            $ids = [];
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $id = (string) $id;
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        } catch (Throwable) {
            return null;
        }
        if (count($ids) > 30000) {
            return null;
        }
        $GLOBALS['_JEAB_KONTAKT_OFFICE_FILL'] = (int) ($GLOBALS['_JEAB_KONTAKT_OFFICE_FILL'] ?? 0) + 1;

        return $ids;
    }

    /** @param array<string, mixed> $dbRow @return array<string, mixed>|null */
    private function decodeRow(array $dbRow): ?array
    {
        $data = json_decode((string) ($dbRow['row_json'] ?? ''), true);
        if (!is_array($data)) {
            return null;
        }
        $data['id'] = (string) ($dbRow['id'] ?? ($data['id'] ?? ''));
        return $data;
    }

    /** @return array<string, array<string, mixed>> */
    public function sqlAll(): array
    {
        $GLOBALS['_JEAB_CONTACTS_SQL_ALL'] = (int) ($GLOBALS['_JEAB_CONTACTS_SQL_ALL'] ?? 0) + 1;
        $rows = $this->pdo->query('SELECT id, row_json FROM contacts ORDER BY id')->fetchAll();
        $out = [];
        foreach ($rows as $dbRow) {
            if (!is_array($dbRow)) {
                continue;
            }
            $decoded = $this->decodeRow($dbRow);
            if (is_array($decoded) && (string) $decoded['id'] !== '') {
                $out[(string) $decoded['id']] = $decoded;
            }
        }
        return $out;
    }

    public function all(): array
    {
        return $this->sqlAll();
    }

    public function get(string $id): ?array
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT id, row_json FROM contacts WHERE id = ?');
        $stmt->execute([$id]);
        $dbRow = $stmt->fetch();
        if (!is_array($dbRow)) {
            return null;
        }
        $decoded = $this->decodeRow($dbRow);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param list<string> $ids
     * @return array<string, array<string, mixed>>
     */
    public function getMany(array $ids): array
    {
        $want = [];
        foreach ($ids as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $want[$id] = $id;
            }
        }
        if ($want === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk(array_values($want), 200) as $chunk) {
            $place = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->pdo->prepare('SELECT id, row_json FROM contacts WHERE id IN (' . $place . ')');
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll() as $dbRow) {
                if (!is_array($dbRow)) {
                    continue;
                }
                $decoded = $this->decodeRow($dbRow);
                if (is_array($decoded)) {
                    $out[(string) ($decoded['id'] ?? $dbRow['id'] ?? '')] = $decoded;
                }
            }
        }
        return $out;
    }

    public function mutate(callable $fn, bool $allowMass = false): mixed
    {
        return jeab_pdo_transaction($this->pdo, function () use ($fn, $allowMass): mixed {
            $before = $this->sqlAll();
            $contacts = $before;
            $result = $fn($contacts);
            $this->applyDiff($before, $contacts, $allowMass);

            return $result;
        });
    }

    /**
     * Skriver bara nya och ändrade rader. Raderar bara id som saknas mot
     * föregående ögonblicksbild. Tom lista raderar inget. Ingen DELETE NOT IN.
     *
     * @param array<string, array<string, mixed>> $contacts
     */
    public function replaceAll(array $contacts): void
    {
        $this->applyDiff($this->sqlAll(), $contacts);
    }

    /**
     * @param array<string, array<string, mixed>> $before
     * @param array<string, array<string, mixed>> $after
     */
    private function applyDiff(array $before, array $after, bool $allowMass = false): void
    {
        if (function_exists('jeab_data_write_guard')) {
            jeab_data_write_guard();
        }
        $before = $this->indexContacts($before);
        $after = $this->indexContacts($after);
        foreach ($after as $id => $row) {
            if (isset($before[$id]) && $this->canonicalContact($before[$id]) === $this->canonicalContact($row)) {
                continue;
            }
            $this->upsert($id, $row);
        }
        $drop = [];
        foreach ($before as $id => $_row) {
            if (!isset($after[$id])) {
                $drop[] = $id;
            }
        }
        if ($drop === []) {
            return;
        }
        if ($after === [] && !$allowMass) {
            error_log('Kontakter: tom lista, inget raderas (' . count($before) . ' rader).');

            return;
        }
        if (!$allowMass && $this->refuseMassDelete(count($before), count($drop))) {
            return;
        }
        foreach ($drop as $id) {
            $this->deleteContact($id);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $contacts
     * @return array<string, array<string, mixed>>
     */
    private function indexContacts(array $contacts): array
    {
        $out = [];
        foreach ($contacts as $id => $row) {
            if (!is_array($row)) {
                continue;
            }
            $cid = trim((string) ($row['id'] ?? $id));
            if ($cid === '') {
                continue;
            }
            $row['id'] = $cid;
            $out[$cid] = $row;
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    private function canonicalContact(array $row): string
    {
        $this->sortContact($row);
        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '';
    }

    /** @param array<string, mixed> $row */
    private function sortContact(array &$row): void
    {
        foreach ($row as &$value) {
            if (is_array($value)) {
                $this->sortContact($value);
            }
        }
        unset($value);
        ksort($row);
    }

    private function refuseMassDelete(int $total, int $drop): bool
    {
        if ($drop <= 0) {
            return false;
        }
        $overCount = $drop > 20;
        $overShare = $total > 0 && ($drop / $total) > 0.05;
        if (!$overCount && !$overShare) {
            return false;
        }
        error_log('Kontakter: radering vägras (' . $drop . ' av ' . $total . ').');

        return true;
    }

    private function deleteContact(string $id): void
    {
        $id = trim($id);
        if ($id === '') {
            return;
        }
        $this->pdo->prepare('DELETE FROM contacts WHERE id = ?')->execute([$id]);
        if (!function_exists('jeab_contact_emails_table_exists') || jeab_contact_emails_table_exists($this->pdo)) {
            try {
                $this->pdo->prepare('DELETE FROM contact_emails WHERE contact_id = ?')->execute([$id]);
            } catch (Throwable $e) {
                if (!function_exists('jeab_contact_emails_missing') || !jeab_contact_emails_missing($e)) {
                    throw $e;
                }
                if (function_exists('jeab_contact_emails_table_exists')) {
                    jeab_contact_emails_table_exists($this->pdo, false);
                }
            }
        }
        if (function_exists('jeab_contacts_sole_cache_forget')) {
            jeab_contacts_sole_cache_forget($id);
        }
    }

    /** @param array<string, mixed> $row */
    public function upsert(string $id, array $row): void
    {
        if (function_exists('jeab_contact_apply_street_letter')) {
            $row = jeab_contact_apply_street_letter($row);
        }
        $idx = jeab_contact_store_index_fields($row);
        $json = json_encode($row, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Kunde inte spara kontakten.');
        }
        $now = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
        $created = trim((string) ($row['created_at'] ?? '')) ?: $now;
        $updated = trim((string) ($row['updated_at'] ?? '')) ?: $now;
        $list = jeab_contact_store_list_fields($row);
        if ($this->isMysql()) {
            $sql = 'INSERT INTO contacts (id, row_json, street_key, postal_code, owner_email, owner_phone, owner_name_fold, company, city_fold, sort_key, sort_city, list_ready, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
                ON DUPLICATE KEY UPDATE row_json = VALUES(row_json), street_key = VALUES(street_key), postal_code = VALUES(postal_code),
                owner_email = VALUES(owner_email), owner_phone = VALUES(owner_phone), owner_name_fold = VALUES(owner_name_fold),
                company = VALUES(company), city_fold = VALUES(city_fold), sort_key = VALUES(sort_key), sort_city = VALUES(sort_city),
                list_ready = 1, updated_at = VALUES(updated_at)';
        } else {
            $sql = 'INSERT INTO contacts (id, row_json, street_key, postal_code, owner_email, owner_phone, owner_name_fold, company, city_fold, sort_key, sort_city, list_ready, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
                ON CONFLICT(id) DO UPDATE SET row_json = excluded.row_json, street_key = excluded.street_key, postal_code = excluded.postal_code,
                owner_email = excluded.owner_email, owner_phone = excluded.owner_phone, owner_name_fold = excluded.owner_name_fold,
                company = excluded.company, city_fold = excluded.city_fold, sort_key = excluded.sort_key, sort_city = excluded.sort_city,
                list_ready = 1, updated_at = excluded.updated_at';
        }
        $this->pdo->prepare($sql)->execute([
            $id,
            $json,
            mb_substr($idx['street_key'], 0, 191),
            mb_substr($idx['postal_code'], 0, 16),
            mb_substr($idx['owner_email'], 0, 191),
            mb_substr($idx['owner_phone'], 0, 32),
            mb_substr($idx['owner_name_fold'], 0, 191),
            $list['company'],
            $list['city_fold'],
            $list['sort_key'],
            $list['sort_city'],
            $created,
            $updated,
        ]);
        jeab_contact_emails_sync($this->pdo, $id, $row);
        if (function_exists('jeab_contact_secondary_write_columns')) {
            jeab_contact_secondary_write_columns($this->pdo, $id, $row);
        }
        if (function_exists('jeab_contacts_sole_cache_forget')) {
            jeab_contacts_sole_cache_forget($id);
        }
    }

    /**
     * Chip-räknarna för ett kontor. Samma stämpel (antal + senaste updated_at)
     * gör att filterklicket inte räknar om unionen.
     *
     * @param list<string> $officeArgs
     * @return array{alla:int,adress:int,saknas:int}
     */
    private function officeCounts(string $cacheKey, string $officeSql, array $officeArgs): array
    {
        $stamp = $this->listStamp();
        $cached = $this->countsCacheGet($cacheKey, $stamp);
        if (is_array($cached)) {
            $GLOBALS['_JEAB_KONTAKT_COUNT_CACHE'] = (int) ($GLOBALS['_JEAB_KONTAKT_COUNT_CACHE'] ?? 0) + 1;

            return $cached;
        }
        $count = $this->pdo->prepare(
            'SELECT COUNT(*) AS alla,
                COALESCE(SUM(CASE WHEN street_key <> \'\' THEN 1 ELSE 0 END), 0) AS adress,
                COALESCE(SUM(CASE WHEN street_key = \'\' THEN 1 ELSE 0 END), 0) AS saknas
             FROM contacts WHERE ' . $officeSql
        );
        $count->execute($officeArgs);
        $countsRow = $count->fetch();
        $counts = [
            'alla' => (int) ($countsRow['alla'] ?? 0),
            'adress' => (int) ($countsRow['adress'] ?? 0),
            'saknas' => (int) ($countsRow['saknas'] ?? 0),
        ];
        $this->countsCachePut($cacheKey, $stamp, $counts);

        return $counts;
    }

    private function listStamp(): string
    {
        $parts = [];
        foreach (['contacts', 'reminders', 'bookings', 'purchases'] as $table) {
            try {
                $row = $this->pdo->query('SELECT COUNT(*) AS n, MAX(updated_at) AS m FROM ' . $table)->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable) {
                return '';
            }
            if (!is_array($row)) {
                return '';
            }
            $parts[] = $table . ':' . (int) ($row['n'] ?? 0) . ':' . (string) ($row['m'] ?? '');
        }

        return implode('|', $parts);
    }

    /** @return array{alla:int,adress:int,saknas:int}|null */
    private function countsCacheGet(string $key, string $stamp): ?array
    {
        if ($stamp === '') {
            return null;
        }
        $bag = $GLOBALS['_JEAB_KONTAKT_COUNT_BAG'] ?? null;
        if (is_array($bag) && (string) ($bag['stamp'] ?? '') === $stamp && is_array($bag['rows'][$key] ?? null)) {
            /** @var array{alla:int,adress:int,saknas:int} $hit */
            $hit = $bag['rows'][$key];

            return $hit;
        }
        $path = $this->countsCachePath();
        $raw = @file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || (string) ($data['stamp'] ?? '') !== $stamp || !is_array($data['rows'][$key] ?? null)) {
            return null;
        }
        $row = $data['rows'][$key];
        $counts = [
            'alla' => (int) ($row['alla'] ?? 0),
            'adress' => (int) ($row['adress'] ?? 0),
            'saknas' => (int) ($row['saknas'] ?? 0),
        ];
        $GLOBALS['_JEAB_KONTAKT_COUNT_BAG'] = $data;

        return $counts;
    }

    /** @param array{alla:int,adress:int,saknas:int} $counts */
    private function countsCachePut(string $key, string $stamp, array $counts): void
    {
        if ($stamp === '') {
            return;
        }
        $data = $GLOBALS['_JEAB_KONTAKT_COUNT_BAG'] ?? null;
        if (!is_array($data) || (string) ($data['stamp'] ?? '') !== $stamp) {
            $data = ['stamp' => $stamp, 'rows' => []];
        }
        $data['rows'][$key] = $counts;
        $GLOBALS['_JEAB_KONTAKT_COUNT_BAG'] = $data;
        @file_put_contents($this->countsCachePath(), json_encode($data), LOCK_EX);
    }

    private function countsCachePath(): string
    {
        $id = (string) spl_object_id($this->pdo);
        try {
            $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $row = $this->pdo->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC);
                $id = is_array($row) ? (string) ($row['file'] ?? $id) : $id;
            } else {
                $name = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
                $id = is_string($name) && $name !== '' ? $name : $id;
            }
        } catch (Throwable) {
        }

        return rtrim(sys_get_temp_dir(), '/') . '/jeab-kt-counts-' . hash('sha256', $id) . '.json';
    }

    /**
     * En sida från SQL. Hämtar inte hela registret.
     *
     * @return array{rows: array<string, array<string, mixed>>, total: int, counts: array{alla:int,adress:int,saknas:int}}
     */
    public function page(string $query, string $filter, ?string $office, int $limit): array
    {
        $driver = $this->isMysql() ? 'mysql' : 'sqlite';
        if (function_exists('jeab_settings_value')) {
            jeab_settings_value($this->pdo, $driver, 'contacts_list_cols_v1');
        }
        $limit = max(1, $limit);
        $office = function_exists('jeab_normalize_company') ? (jeab_normalize_company($office) ?? '') : trim((string) $office);
        if (!in_array($filter, ['alla', 'adress', 'saknas'], true)) {
            $filter = 'alla';
        }
        if (function_exists('jeab_kontakt_fold_text')) {
            $query = jeab_kontakt_fold_text($query);
        }
        $query = trim($query);
        $search = jeab_kontakt_search_clause($query);
        if (($search['kind'] ?? '') === 'email'
            && function_exists('jeab_contact_emails_table_exists')
            && !jeab_contact_emails_table_exists($this->pdo)) {
            $search = jeab_kontakt_search_clause($query, false);
        }
        $officeSql = '1 = 1';
        $officeArgs = [];
        if ($office !== '') {
            // Unionen stannar i SQL. Id-listan och IN-bitarna på 900 hämtade
            // hela kontoret till PHP och gjorde filtret lika dyrt som första sidan.
            if ($this->purchasesOfficeReady()) {
                $officeSql = 'id IN (' . $this->officeUnionSql() . ')';
                $officeArgs = [$office, $office, $office, $office, $office, $office, $office];
            } else {
                $officeSql = '(company = ? OR company = \'\')';
                $officeArgs = [$office];
            }
        }
        $searchSql = $search['sql'];
        $chipSql = match ($filter) {
            'adress' => ' AND street_key <> \'\'',
            'saknas' => ' AND street_key = \'\'',
            default => '',
        };
        $searchArgs = $search['args'];
        $counts = $this->officeCounts($office === '' ? '*' : $office, $officeSql, $officeArgs);
        $totalStmt = $this->pdo->prepare('SELECT COUNT(*) FROM contacts WHERE ' . $officeSql . $chipSql . $searchSql);
        $totalStmt->execute(array_merge($officeArgs, $searchArgs));
        $total = (int) $totalStmt->fetchColumn();
        // N-9: sort_key kodar å/ä/ö som { | } så de hamnar efter z. MySQL:s
        // unicode_ci/swedish_ci sorterar skiljetecken först, så jämför binärt.
        $bin = jeab_kontakt_store_sort_collate($this->pdo);
        $list = $this->pdo->prepare(
            'SELECT id, row_json FROM contacts WHERE ' . $officeSql . $chipSql . $searchSql
            . ' ORDER BY CASE WHEN street_key = \'\' THEN 1 ELSE 0 END, sort_key' . $bin . ', sort_city' . $bin . ', id LIMIT ' . (int) $limit
        );
        $list->execute(array_merge($officeArgs, $searchArgs));
        $rows = [];
        foreach ($list->fetchAll() as $dbRow) {
            if (!is_array($dbRow)) {
                continue;
            }
            $decoded = $this->decodeRow($dbRow);
            if (!is_array($decoded) || (string) ($decoded['id'] ?? '') === '') {
                continue;
            }
            $rows[(string) $decoded['id']] = $decoded;
        }

        return ['rows' => $rows, 'total' => $total, 'counts' => $counts];
    }

    /**
     * SQLite före 3.32 tar högst 999 platshållare. Varje fråga stannar på 900 id.
     *
     * @param list<string> $ids
     * @param list<string> $searchArgs
     * @return array{rows: array<string, array<string, mixed>>, total: int, counts: array{alla:int,adress:int,saknas:int}}
     */
    private function pageByIdChunks(array $ids, string $chipSql, string $searchSql, array $searchArgs, int $limit): array
    {
        $chunks = array_chunk($ids, 900);
        $max = 0;
        foreach ($chunks as $chunk) {
            $max = max($max, count($chunk));
        }
        $GLOBALS['_JEAB_KONTAKT_OFFICE_IN'] = max((int) ($GLOBALS['_JEAB_KONTAKT_OFFICE_IN'] ?? 0), $max);
        $counts = ['alla' => 0, 'adress' => 0, 'saknas' => 0];
        $total = 0;
        $picked = [];
        $bin = jeab_kontakt_store_sort_collate($this->pdo);
        foreach ($chunks as $chunk) {
            $in = 'id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')';
            $count = $this->pdo->prepare(
                'SELECT COUNT(*) AS alla,
                    COALESCE(SUM(CASE WHEN street_key <> \'\' THEN 1 ELSE 0 END), 0) AS adress,
                    COALESCE(SUM(CASE WHEN street_key = \'\' THEN 1 ELSE 0 END), 0) AS saknas
                 FROM contacts WHERE ' . $in
            );
            $count->execute($chunk);
            $countsRow = $count->fetch();
            $counts['alla'] += (int) ($countsRow['alla'] ?? 0);
            $counts['adress'] += (int) ($countsRow['adress'] ?? 0);
            $counts['saknas'] += (int) ($countsRow['saknas'] ?? 0);
            $totalStmt = $this->pdo->prepare('SELECT COUNT(*) FROM contacts WHERE ' . $in . $chipSql . $searchSql);
            $totalStmt->execute(array_merge($chunk, $searchArgs));
            $total += (int) $totalStmt->fetchColumn();
            $list = $this->pdo->prepare(
                'SELECT id, row_json, street_key, sort_key, sort_city FROM contacts WHERE ' . $in . $chipSql . $searchSql
                . ' ORDER BY CASE WHEN street_key = \'\' THEN 1 ELSE 0 END, sort_key' . $bin . ', sort_city' . $bin . ', id LIMIT ' . (int) $limit
            );
            $list->execute(array_merge($chunk, $searchArgs));
            foreach ($list->fetchAll() as $dbRow) {
                if (is_array($dbRow)) {
                    $picked[] = $dbRow;
                }
            }
        }
        usort($picked, static function (array $a, array $b): int {
            $emptyA = ((string) ($a['street_key'] ?? '')) === '' ? 1 : 0;
            $emptyB = ((string) ($b['street_key'] ?? '')) === '' ? 1 : 0;
            if ($emptyA !== $emptyB) {
                return $emptyA <=> $emptyB;
            }
            $cmp = strcmp((string) ($a['sort_key'] ?? ''), (string) ($b['sort_key'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcmp((string) ($a['sort_city'] ?? ''), (string) ($b['sort_city'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
        });
        if (count($picked) > $limit) {
            $picked = array_slice($picked, 0, $limit);
        }
        $rows = [];
        foreach ($picked as $dbRow) {
            $decoded = $this->decodeRow($dbRow);
            if (!is_array($decoded) || (string) ($decoded['id'] ?? '') === '') {
                continue;
            }
            $rows[(string) $decoded['id']] = $decoded;
        }

        return ['rows' => $rows, 'total' => $total, 'counts' => $counts];
    }

    public function findByEmail(string $email): array
    {
        $email = function_exists('jeab_kontakt_email') ? jeab_kontakt_email($email) : trim($email);
        return $this->findColumn('owner_email', $email);
    }

    public function findByPhone(string $phone): array
    {
        $phone = function_exists('jeab_kontakt_phone') ? jeab_kontakt_phone($phone) : trim($phone);
        return $this->findColumn('owner_phone', $phone);
    }

    public function findByStreetKey(string $streetKey): array
    {
        return $this->findColumn('street_key', trim($streetKey));
    }

    public function findByPostalCode(string $postal): array
    {
        $postal = function_exists('jeab_kontakt_postal') ? jeab_kontakt_postal($postal) : trim($postal);
        return $this->findColumn('postal_code', $postal);
    }

    public function findByOwnerNameFold(string $fold): array
    {
        return $this->findColumn('owner_name_fold', trim($fold));
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn();
    }

    public function backup(): string
    {
        $app = function_exists('jeab_store') ? jeab_store() : null;
        if (!$app instanceof JeabStore || !function_exists('jeab_kontakter_backup_snapshot')) {
            return '';
        }
        return jeab_kontakter_backup_snapshot($app);
    }

    /** @param list<array<string, mixed>> $rows @return list<string> */
    public function insertMany(array $rows): array
    {
        $saved = $this->mutate(static function (array &$contacts) use ($rows): array {
            $ids = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = trim((string) ($row['id'] ?? ''));
                if ($id === '' || isset($contacts[$id])) {
                    continue;
                }
                $row['id'] = $id;
                if (function_exists('jeab_idnr_normalize_row')) {
                    $row = jeab_idnr_normalize_row($row);
                }
                $contacts[$id] = $row;
                $ids[] = $id;
            }
            return $ids;
        });
        return is_array($saved) ? array_values($saved) : [];
    }

    /** @return list<array<string, mixed>> */
    private function findColumn(string $column, string $value): array
    {
        if ($value === '' || !in_array($column, ['owner_email', 'owner_phone', 'street_key', 'postal_code', 'owner_name_fold'], true)) {
            return [];
        }
        $stmt = $this->pdo->prepare('SELECT id, row_json FROM contacts WHERE ' . $column . ' = ?');
        $stmt->execute([$value]);
        return $this->fetchDecodedList($stmt);
    }

    /** @return list<array<string, mixed>> */
    private function fetchDecodedList(PDOStatement $stmt): array
    {
        $out = [];
        foreach ($stmt->fetchAll() as $dbRow) {
            if (!is_array($dbRow)) {
                continue;
            }
            $decoded = $this->decodeRow($dbRow);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }
        return $out;
    }
}

final class JeabPdoInboxContactStore
{
    public function __construct(private PDO $pdo)
    {
        if (jeab_kontakter_page_read()) {
            return;
        }
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        jeab_ensure_contacts_tables($this->pdo, $driver);
    }

    private function isMysql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    /** @param array<string, mixed> $dbRow @return array<string, mixed>|null */
    private function decodeRow(array $dbRow): ?array
    {
        $data = json_decode((string) ($dbRow['row_json'] ?? ''), true);
        if (!is_array($data)) {
            return null;
        }
        $data['id'] = (string) ($dbRow['id'] ?? ($data['id'] ?? ''));
        return $data;
    }

    /** @return array<string, array<string, mixed>> */
    public function sqlAll(): array
    {
        if (function_exists('jeab_inkorg_contacts_marker_present') && jeab_inkorg_contacts_marker_present()) {
            return [];
        }
        $rows = $this->pdo->query('SELECT id, row_json FROM inkorg_contacts ORDER BY id')->fetchAll();
        $out = [];
        foreach ($rows as $dbRow) {
            if (!is_array($dbRow)) {
                continue;
            }
            $decoded = $this->decodeRow($dbRow);
            if (is_array($decoded) && (string) $decoded['id'] !== '') {
                $out[(string) $decoded['id']] = $decoded;
            }
        }
        return $out;
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM inkorg_contacts')->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function findByEmail(string $email): array
    {
        if (function_exists('jeab_inkorg_contacts_marker_present') && jeab_inkorg_contacts_marker_present()) {
            return [];
        }
        $email = function_exists('jeab_norm_email') ? jeab_norm_email($email) : strtolower(trim($email));
        if ($email === '') {
            return [];
        }
        $stmt = $this->pdo->prepare('SELECT id, row_json FROM inkorg_contacts WHERE email = ?');
        $stmt->execute([mb_substr($email, 0, 191)]);
        return $this->fetchDecodedList($stmt);
    }

    /** @return list<array<string, mixed>> */
    public function findByMobile(string $mobile): array
    {
        if (function_exists('jeab_inkorg_contacts_marker_present') && jeab_inkorg_contacts_marker_present()) {
            return [];
        }
        $mobile = trim($mobile);
        if ($mobile === '') {
            return [];
        }
        $stmt = $this->pdo->prepare('SELECT id, row_json FROM inkorg_contacts WHERE mobile = ?');
        $stmt->execute([mb_substr($mobile, 0, 32)]);
        return $this->fetchDecodedList($stmt);
    }

    /** @param callable(?array): array{0:mixed,1:mixed} $fn */
    public function locked(callable $fn): mixed
    {
        return jeab_pdo_transaction($this->pdo, function () use ($fn): mixed {
            $contacts = $this->sqlAll();
            if ($contacts === []) {
                jeab_inkorg_contacts_sql_empty_log();
            }
            [$out, $new] = $fn(['contacts' => $contacts]);
            if ($new !== null && jeab_inkorg_contacts_write_frozen()) {
                return jeab_inkorg_contacts_frozen_result($out, $contacts);
            }
            if ($new !== null) {
                $rows = is_array($new) && is_array($new['contacts'] ?? null) ? $new['contacts'] : [];
                $this->replaceAll($rows);
            }

            return $out;
        });
    }

    /**
     * Skriver bara nya och ändrade rader. Raderar bara id som saknas mot
     * föregående ögonblicksbild. Tom lista raderar inget.
     *
     * @param array<string, mixed> $contacts
     */
    public function replaceAll(array $contacts): void
    {
        $this->applyDiff($this->sqlAll(), $contacts);
    }

    /**
     * @param array<string, array<string, mixed>> $before
     * @param array<string, mixed> $after
     */
    private function applyDiff(array $before, array $after): void
    {
        if (function_exists('jeab_data_write_guard')) {
            jeab_data_write_guard();
        }
        $before = $this->indexContacts($before);
        $next = $this->indexContacts($after);
        foreach ($next as $id => $row) {
            if (isset($before[$id]) && $this->canonicalContact($before[$id]) === $this->canonicalContact($row)) {
                continue;
            }
            $this->upsert($id, $row);
        }
        if ($next === []) {
            if ($before !== []) {
                error_log('Inkorgskontakter: tom lista, inget raderas (' . count($before) . ' rader).');
            }

            return;
        }
        $drop = [];
        foreach ($before as $id => $_row) {
            if (!isset($next[$id])) {
                $drop[] = $id;
            }
        }
        if ($this->refuseMassDelete(count($before), count($drop))) {
            return;
        }
        foreach ($drop as $id) {
            $this->pdo->prepare('DELETE FROM inkorg_contacts WHERE id = ?')->execute([$id]);
        }
    }

    /**
     * @param array<string, mixed> $contacts
     * @return array<string, array<string, mixed>>
     */
    private function indexContacts(array $contacts): array
    {
        $out = [];
        foreach ($contacts as $id => $row) {
            if (!is_array($row)) {
                continue;
            }
            $cid = trim((string) ($row['id'] ?? $id));
            if ($cid === '') {
                $cid = trim((string) $id);
            }
            if ($cid === '') {
                continue;
            }
            $row['id'] = $cid;
            $out[$cid] = $row;
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    private function canonicalContact(array $row): string
    {
        $this->sortContact($row);
        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '';
    }

    /** @param array<string, mixed> $row */
    private function sortContact(array &$row): void
    {
        foreach ($row as &$value) {
            if (is_array($value)) {
                $this->sortContact($value);
            }
        }
        unset($value);
        ksort($row);
    }

    private function refuseMassDelete(int $total, int $drop): bool
    {
        if ($drop <= 0) {
            return false;
        }
        $overCount = $drop > 20;
        $overShare = $total > 0 && ($drop / $total) > 0.05;
        if (!$overCount && !$overShare) {
            return false;
        }
        error_log('Inkorgskontakter: radering vägras (' . $drop . ' av ' . $total . ').');

        return true;
    }

    /** @param array<string, mixed> $row */
    public function upsert(string $id, array $row): void
    {
        $idx = jeab_kontakt_inbox_index_fields($row);
        $json = json_encode($row, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Kunde inte spara kontakten.');
        }
        $updated = trim((string) ($row['updated_at'] ?? ''));
        if ($updated === '') {
            $updated = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
        }
        if ($this->isMysql()) {
            $sql = 'INSERT INTO inkorg_contacts (id, row_json, email, mobile, updated_at)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE row_json = VALUES(row_json), email = VALUES(email), mobile = VALUES(mobile), updated_at = VALUES(updated_at)';
        } else {
            $sql = 'INSERT INTO inkorg_contacts (id, row_json, email, mobile, updated_at)
                VALUES (?, ?, ?, ?, ?)
                ON CONFLICT(id) DO UPDATE SET row_json = excluded.row_json, email = excluded.email, mobile = excluded.mobile, updated_at = excluded.updated_at';
        }
        $this->pdo->prepare($sql)->execute([$id, $json, $idx['email'], $idx['mobile'], $updated]);
    }

    /** @return list<array<string, mixed>> */
    private function fetchDecodedList(PDOStatement $stmt): array
    {
        $out = [];
        foreach ($stmt->fetchAll() as $dbRow) {
            if (!is_array($dbRow)) {
                continue;
            }
            $decoded = $this->decodeRow($dbRow);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }
        return $out;
    }
}

/** N-9: binär ordning på sort_key i MySQL. SQLite jämför redan binärt. */
function jeab_kontakt_store_sort_collate(PDO $pdo): string
{
    try {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' COLLATE utf8mb4_bin' : '';
    } catch (Throwable) {
        return '';
    }
}
