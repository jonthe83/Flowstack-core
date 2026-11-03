<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/inkorg_trace.php';

/**
 * Inkorg meddelanden, anteckningar och trådar i SQL.
 * Flaggan inkorg_storage är av (json) tills inbox.sql_cutover.
 * Anteckningar är rader med internal_note eller hs_thread_type=note.
 */

const JEAB_INKORG_STORAGE_KEY = 'inkorg_storage';
const JEAB_INKORG_MIGRATE_DEFAULT_BATCH = 500;

function jeab_inkorg_storage_mode(): string
{
    $legacy = 'json';
    $pdo = jeab_inkorg_store_pdo();
    $settings = '';
    $fileFlag = '';
    if ($pdo instanceof PDO && function_exists('jeab_settings_value')) {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        $settings = (string) jeab_settings_value($pdo, $driver, JEAB_INKORG_STORAGE_KEY);
        $legacy = $settings === 'sql' ? 'sql' : 'json';
    }
    if (function_exists('jeab_cutover_storage_file')) {
        $file = jeab_cutover_storage_file(JEAB_INKORG_STORAGE_KEY);
        if ($file === 'sql' || $file === 'json') {
            $legacy = $file;
            $fileFlag = $file;
        }
    }
    $mode = function_exists('jeab_data_storage') ? jeab_data_storage('inbox', $legacy) : $legacy;
    // inkorg_storage=sql är inkorgens eget SQL-läge. En json-typlista får inte dölja den.
    // DATA_BACKEND=json är fortfarande återställningen till JSON.
    if ($legacy === 'sql' && $mode !== 'sql') {
        $rollback = function_exists('jeab_data_backend_explicit')
            && jeab_data_backend_explicit()
            && function_exists('jeab_data_backend')
            && jeab_data_backend() === 'json';
        if (!$rollback) {
            $mode = 'sql';
        }
    }
    if (!empty($GLOBALS['_JEAB_INKORG_TRACE']) || jeab_inkorg_trace_candidate()) {
        $pdoState = $pdo instanceof PDO ? 'ok' : (string) ($GLOBALS['_JEAB_INKORG_TRACE_PDO_STATE'] ?? 'null');
        jeab_inkorg_trace_mode($pdoState, $settings, $fileFlag, (string) $mode);
    }

    return $mode;
}

function jeab_inkorg_store_pdo(): ?PDO
{
    if (!function_exists('jeab_store')) {
        return null;
    }
    try {
        $store = jeab_store();
        if (!$store instanceof JeabPdoStore) {
            $GLOBALS['_JEAB_INKORG_TRACE_PDO_STATE'] = 'not_pdo';
            if (jeab_inkorg_trace_candidate()) {
                jeab_inkorg_trace_event('store_pdo.not_pdo', null, ['store' => is_object($store) ? get_class($store) : gettype($store)]);
            }

            return null;
        }
        unset($GLOBALS['_JEAB_INKORG_TRACE_PDO_STATE']);

        return $store->pdo();
    } catch (Throwable $e) {
        $GLOBALS['_JEAB_INKORG_TRACE_PDO_STATE'] = 'throw';
        jeab_inkorg_trace_event('store_pdo.catch', $e);

        return null;
    }
}

function jeab_inkorg_use_sql(): bool
{
    return jeab_inkorg_storage_mode() === 'sql';
}

function jeab_inkorg_writes_sql_only(): bool
{
    return jeab_inkorg_use_sql();
}

/** Kolumnen list_status: raderad, annars lagrad status. Tom status är öppen. */
function jeab_inkorg_list_row_status(array $row): string
{
    if (!empty($row['deleted'])) {
        return 'deleted';
    }
    $status = trim((string) ($row['status'] ?? ''));

    return mb_substr($status === '' ? 'open' : $status, 0, 32);
}

function jeab_inkorg_row_is_note(array $row): bool
{
    if (!empty($row['internal_note'])) {
        return true;
    }
    return (string) ($row['hs_thread_type'] ?? '') === 'note';
}

function jeab_inkorg_sql_datetime(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d H:i:s', $ts);
}

/** @param array<string, mixed> $row */
function jeab_inkorg_message_index_fields(array $row): array
{
    $from = mb_strtolower(trim((string) ($row['from'] ?? '')), 'UTF-8');
    return [
        'thread_id' => mb_substr(trim((string) ($row['thread_id'] ?? ($row['id'] ?? ''))), 0, 191),
        'received_at' => jeab_inkorg_sql_datetime((string) ($row['received_at'] ?? '')),
        'from_addr' => mb_substr($from, 0, 191),
        'is_note' => jeab_inkorg_row_is_note($row) ? 1 : 0,
    ];
}

/** @param array<string, mixed> $row */
function jeab_inkorg_thread_index_fields(array $row): array
{
    $status = trim((string) ($row['status'] ?? 'open'));
    if ($status === '') {
        $status = 'open';
    }
    return [
        'status' => mb_substr($status, 0, 32),
        'updated_at' => jeab_inkorg_sql_datetime((string) ($row['updated_at'] ?? ($row['created_at'] ?? ''))),
    ];
}

/**
 * @return array{ok:bool,error?:string,messages:list<array<string,mixed>>,threads:array<string,array<string,mixed>>}
 */
function jeab_inkorg_json_snapshot(): array
{
    return [
        'ok' => false,
        'error' => 'retired: SQL is sole source',
        'messages' => [],
        'threads' => [],
    ];
}

/**
 * @return array{rows:list<array<string,mixed>>,map:array<string,array<string,mixed>>,error?:string}
 */
function jeab_inkorg_json_decode_file(string $path, string $key): array
{
    if (!is_file($path)) {
        return ['rows' => [], 'map' => []];
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return ['rows' => [], 'map' => [], 'error' => basename($path) . ' går inte att läsa.'];
    }
    if (trim($raw) === '') {
        return ['rows' => [], 'map' => []];
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !array_key_exists($key, $data) || !is_array($data[$key])) {
        return ['rows' => [], 'map' => [], 'error' => basename($path) . ' går inte att läsa.'];
    }
    if ($key === 'threads') {
        $map = [];
        foreach ($data[$key] as $id => $row) {
            if (is_array($row)) {
                $map[(string) $id] = $row;
            }
        }
        return ['rows' => [], 'map' => $map];
    }
    $rows = [];
    foreach ($data[$key] as $row) {
        if (is_array($row)) {
            $rows[] = $row;
        }
    }
    return ['rows' => $rows, 'map' => []];
}

/** @return list<array<string,mixed>> */
function jeab_inkorg_json_file_messages(): array
{
    $snap = jeab_inkorg_json_snapshot();
    return $snap['ok'] ? $snap['messages'] : [];
}

/** @return array<string,array<string,mixed>> */
function jeab_inkorg_json_file_threads(): array
{
    $snap = jeab_inkorg_json_snapshot();
    return $snap['ok'] ? $snap['threads'] : [];
}

final class JeabPdoInkorgStore
{
    private bool $reopen = false;

    /** Ett omförsök pågår. Nästa sats får inte öppna en tredje anslutning. */
    private int $goneRetry = 0;

    /** Kolumnen contact_id finns och får användas. Sidan skapar den inte. */
    private bool $listContactReady = false;

    public function __construct(private ?PDO $pdo)
    {
        if (!$this->pdo instanceof PDO) {
            return;
        }
        $driver = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        jeab_ensure_inkorg_tables($this->pdo(), $driver);
    }

    public function disconnect(): void
    {
        $this->pdo = null;
    }

    public function hasPdo(): bool
    {
        return $this->pdo instanceof PDO;
    }

    public function holdsPdo(PDO $pdo): bool
    {
        return $this->pdo === $pdo;
    }

    public function adoptPdo(PDO $pdo): void
    {
        $this->pdo = $pdo;
        $this->reopen = false;
        $this->ensureUtf8mb4($pdo);
    }

    /** Ny anslutning ska läsa och skriva utf8mb4. Sqlite hoppas över. */
    private function ensureUtf8mb4(PDO $pdo): void
    {
        try {
            if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                return;
            }
        } catch (Throwable) {
            return;
        }
        $sql = function_exists('jeab_pdo_mysql_names_sql')
            ? jeab_pdo_mysql_names_sql()
            : 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci';
        try {
            $pdo->exec($sql);
        } catch (Throwable) {
        }
    }

    /**
     * 2006/2013: byt anslutning, sätt utf8mb4, kör samma sats en gång till.
     * Öppen transaktion lämnas åt jeab_pdo_transaction. Har hjälparen redan
     * bytt anslutning kastas felet vidare så satsen inte körs en tredje gång.
     * Skrivning är idempotent på id (message-id-hash eller tråd-id).
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function trySql(callable $fn): mixed
    {
        if ($this->goneRetry > 0) {
            return $fn();
        }
        $before = $this->pdo;
        try {
            return $fn();
        } catch (PDOException $e) {
            if (!function_exists('jeab_pdo_mysql_gone') || !jeab_pdo_mysql_gone($e)) {
                throw $e;
            }
            $dead = $before instanceof PDO ? $before : $this->pdo;
            if ($this->pdo !== $before) {
                throw $e;
            }
            if ($dead instanceof PDO && function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($dead)) {
                throw $e;
            }
            $known = $dead instanceof PDO && function_exists('jeab_pdo_replaced') ? jeab_pdo_replaced($dead) : null;
            if ($known instanceof PDO) {
                $this->adoptPdo($known);
                throw $e;
            }
            $this->goneRetry++;
            jeab_inkorg_trace_event('store.sql_gone_retry', $e);
            try {
                try {
                    $this->reconnectAfterGone();
                } catch (Throwable $re) {
                    jeab_inkorg_trace_event('store.reconnect_catch', $re);
                }
                return $fn();
            } catch (PDOException $again) {
                if (function_exists('jeab_pdo_mysql_gone') && jeab_pdo_mysql_gone($again) && function_exists('jeab_pdo_log_gone')) {
                    jeab_pdo_log_gone($again, 'inkorg');
                }
                throw $again;
            } finally {
                $this->goneRetry--;
            }
        }
    }

    private function reconnectAfterGone(): void
    {
        $dead = $this->pdo;
        $fresh = null;
        if ($dead instanceof PDO && function_exists('jeab_pdo_replace')) {
            try {
                $fresh = jeab_pdo_replace($dead);
            } catch (Throwable) {
                $fresh = null;
            }
        }
        if (!$fresh instanceof PDO) {
            $this->disconnect();
            $fresh = $this->pdo();
        }
        $this->adoptPdo($fresh);
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }
        if ($this->reopen) {
            throw new RuntimeException('Databasen är inte öppen.');
        }
        $this->reopen = true;
        try {
            $this->pdo = jeab_pdo_store_connect();
        } finally {
            $this->reopen = false;
        }

        return $this->pdo;
    }

    /** Finns inkorg_list.customer_email kvar? contacts.drop_customer_fields kan ta bort den. */
    private function listEmailColumn(): bool
    {
        return !function_exists('jeab_copy_column_present') || jeab_copy_column_present($this->pdo(), 'inkorg_list', 'customer_email');
    }

    /** Uttryck för trådens e-post: kolumnen, eller row_json.customer_email när kolumnen är borta. */
    private function listEmailExpr(string $alias = ''): string
    {
        $p = $alias === '' ? '' : $alias . '.';
        if ($this->listEmailColumn()) {
            return $p . 'customer_email';
        }

        return $this->isMysql()
            ? "LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(" . $p . "row_json, '$.customer_email')), ''))"
            : "lower(COALESCE(json_extract(" . $p . "row_json, '$.customer_email'), ''))";
    }

    /** SELECT-del: kolumnen, eller uttrycket AS customer_email. */
    private function listEmailSelect(): string
    {
        return $this->listEmailColumn() ? 'customer_email' : $this->listEmailExpr() . ' AS customer_email';
    }

    private function isMysql(): bool
    {
        return $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    /** @param array<string,mixed> $dbRow */
    private function decodeRow(array $dbRow): ?array
    {
        $raw = $dbRow['row_json'] ?? '';
        $data = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (is_string($data)) {
            $again = json_decode($data, true);
            if (is_array($again)) {
                $data = $again;
            }
        }
        if (!is_array($data)) {
            $data = $this->listStub($dbRow);
        }
        if (!is_array($data)) {
            return null;
        }

        return $this->applyListColumns($data, $dbRow);
    }

    /**
     * Kolumnerna är det sidan räknar när row_json saknas eller är dubbelkodad.
     *
     * @param array<string,mixed> $dbRow
     * @return array<string,mixed>|null
     */
    private function listStub(array $dbRow): ?array
    {
        $id = trim((string) ($dbRow['id'] ?? ''));
        $status = trim((string) ($dbRow['list_status'] ?? ''));
        $company = trim((string) ($dbRow['company'] ?? ''));
        if ($id === '' && $status === '' && $company === '') {
            return null;
        }

        return [
            'id' => $id,
            'company' => $company,
            'status' => $status === '' ? 'open' : $status,
            'deleted' => $status === 'deleted',
            'updated_at' => (string) ($dbRow['list_at'] ?? ''),
            'customer_email' => (string) ($dbRow['customer_email'] ?? ''),
            'tags' => [],
            'labels' => [],
        ];
    }

    /**
     * list_status open är Öppen även när JSON säger stängd, raderad eller saknar status.
     * JSON-status open lämnas, även när kolumnen säger stängd.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $dbRow
     * @return array<string,mixed>
     */
    private function applyListColumns(array $data, array $dbRow): array
    {
        $id = trim((string) ($dbRow['id'] ?? ''));
        if ($id !== '' && trim((string) ($data['id'] ?? '')) === '') {
            $data['id'] = $id;
        }
        $company = trim((string) ($dbRow['company'] ?? ''));
        if ($company !== '' && trim((string) ($data['company'] ?? '')) === '') {
            $data['company'] = $company;
        }
        $at = trim((string) ($dbRow['list_at'] ?? ''));
        if ($at !== '' && trim((string) ($data['updated_at'] ?? '')) === '') {
            $data['updated_at'] = $at;
        }
        $email = trim((string) ($dbRow['customer_email'] ?? ''));
        $contactId = trim((string) ($data['contact_id'] ?? $dbRow['contact_id'] ?? ''));
        // Länkad tråd: inget kontaktmejl läses in här. customer_email lämnas som tråden har den
        // (jeab_lead_send_recipient läser den). Skärmen visar kontakten via contact_id
        // (inkorg_fast.php jeab_inkorg_fast_list_row tar bort kundfälten på länkade trådar).
        $linkedRow = array_key_exists('customer_email', $dbRow) && $contactId !== '';
        if (!$linkedRow && $email !== '' && trim((string) ($data['customer_email'] ?? '')) === '') {
            $data['customer_email'] = $email;
        }
        if (!array_key_exists('list_status', $dbRow)) {
            return $data;
        }
        $status = trim((string) $dbRow['list_status']);
        $json = trim((string) ($data['status'] ?? ''));
        if ($status === 'deleted') {
            $data['deleted'] = true;
            if ($json === '') {
                $data['status'] = 'closed';
            }

            return $data;
        }
        // Kolumnen är inte raderad. list_status open är Öppen även när JSON säger stängd eller raderad.
        $data['deleted'] = false;
        if ($json === 'open' || $status === 'open') {
            $data['status'] = 'open';

            return $data;
        }
        if (in_array($status, ['waiting_customer', 'waiting_supplier'], true) && ($json === '' || $json === 'closed' || $json === 'deleted')) {
            $data['status'] = $status;
        } elseif ($status === 'closed' && $json === '') {
            $data['status'] = 'closed';
        }

        return $data;
    }

    /** @return list<array<string,mixed>> */
    public function listMessages(): array
    {
        $GLOBALS['_JEAB_INKORG_MESSAGES_ALL'] = (int) ($GLOBALS['_JEAB_INKORG_MESSAGES_ALL'] ?? 0) + 1;
        $out = [];
        foreach ($this->pdo()->query('SELECT row_json FROM inkorg_messages')->fetchAll() as $dbRow) {
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

    public function messagesRevision(): string
    {
        $row = $this->pdo()->query('SELECT COUNT(*), COALESCE(MAX(received_at), ""), COALESCE(MAX(updated_at), "") FROM inkorg_messages')->fetch(PDO::FETCH_NUM);
        $base = is_array($row) ? implode(':', $row) : '0';

        return $base . ':r' . $this->writeRev();
    }

    public function threadsRevision(): string
    {
        $row = $this->pdo()->query('SELECT COUNT(*), COALESCE(MAX(updated_at), "") FROM inkorg_threads')->fetch(PDO::FETCH_NUM);
        $base = is_array($row) ? implode(':', $row) : '0';

        return $base . ':r' . $this->writeRev();
    }

    /** Monoton räknare. MAX(updated_at) saknar bråkdelar, så två skrivningar samma sekund annars ger samma fingerprint. */
    private function writeRev(): string
    {
        try {
            $pdo = $this->pdo();
            $driver = $this->isMysql() ? 'mysql' : 'sqlite';
            $raw = function_exists('jeab_settings_value') ? (string) (jeab_settings_value($pdo, $driver, 'inkorg_write_rev') ?? '0') : '0';

            return (string) (int) $raw;
        } catch (Throwable) {
            return '0';
        }
    }

    private function bumpWriteRev(): void
    {
        try {
            $pdo = $this->pdo();
            $driver = $this->isMysql() ? 'mysql' : 'sqlite';
            if ($driver === 'mysql') {
                $sql = 'INSERT INTO settings (`key`, value) VALUES (?, \'1\') ON DUPLICATE KEY UPDATE value = CAST(value AS UNSIGNED) + 1';
            } else {
                $sql = 'INSERT INTO settings ("key", value) VALUES (?, \'1\') ON CONFLICT("key") DO UPDATE SET value = CAST(value AS INTEGER) + 1';
            }
            $pdo->prepare($sql)->execute(['inkorg_write_rev']);
            if (function_exists('jeab_settings_cache_forget')) {
                jeab_settings_cache_forget($pdo, $driver, 'inkorg_write_rev');
            }
        } catch (Throwable) {
        }
    }

    /** Listans fingerprint: COUNT, MAX(received_at) och MAX(updated_at) på mejl, COUNT och MAX(updated_at) på trådar. Inte filens mtime. */
    public function listFingerprint(): string
    {
        return 'sql|' . $this->messagesRevision() . '|sql|' . $this->threadsRevision();
    }

    public function noteListRebuild(): void
    {
        try {
            $driver = $this->isMysql() ? 'mysql' : 'sqlite';
            $key = 'inkorg_list_rebuilds';
            $now = time();
            $raw = function_exists('jeab_settings_value') ? (string) (jeab_settings_value($this->pdo(), $driver, $key) ?? '') : '';
            $cut = $now - 3600;
            $times = [];
            foreach (explode(',', $raw) as $part) {
                $t = (int) $part;
                if ($part !== '' && $t >= $cut) {
                    $times[] = (string) $t;
                }
            }
            $times[] = (string) $now;
            if (count($times) > 300) {
                $times = array_slice($times, -300);
            }
            if (function_exists('jeab_settings_put')) {
                jeab_settings_put($this->pdo(), $driver, $key, implode(',', $times));
            }
        } catch (Throwable) {
        }
    }

    /** @return array<string,array<string,mixed>> */
    public function threadsMap(): array
    {
        $GLOBALS['_JEAB_INKORG_THREADS_ALL'] = (int) ($GLOBALS['_JEAB_INKORG_THREADS_ALL'] ?? 0) + 1;
        $out = [];
        foreach ($this->pdo()->query('SELECT id, row_json FROM inkorg_threads')->fetchAll() as $dbRow) {
            if (!is_array($dbRow)) {
                continue;
            }
            $id = (string) ($dbRow['id'] ?? '');
            $decoded = $this->decodeRow($dbRow);
            if ($id !== '' && is_array($decoded)) {
                $out[$id] = $decoded;
            }
        }
        return $out;
    }

    /** @param list<string> $ids @return array<string,array<string,mixed>> */
    public function messagesByIds(array $ids): array
    {
        return $this->rowsByIds('inkorg_messages', $ids);
    }

    /** @param list<string> $ids @return array<string,array<string,mixed>> */
    public function threadsByIds(array $ids): array
    {
        return $this->rowsByIds('inkorg_threads', $ids);
    }

    /** @param list<string> $ids @return array<string,array<string,mixed>> */
    private function rowsByIds(string $table, array $ids): array
    {
        if ($table !== 'inkorg_messages' && $table !== 'inkorg_threads') {
            return [];
        }
        $want = [];
        foreach ($ids as $id) {
            $id = mb_substr(trim((string) $id), 0, 191);
            if ($id !== '') {
                $want[$id] = true;
            }
        }
        $out = [];
        foreach (array_chunk(array_keys($want), 400) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $sql = 'SELECT id, row_json FROM ' . $table . ' WHERE id IN (' . $placeholders . ')';
            $fetched = $this->trySql(function () use ($sql, $chunk): array {
                $stmt = $this->pdo()->prepare($sql);
                $stmt->execute($chunk);
                $rows = $stmt->fetchAll();

                return is_array($rows) ? $rows : [];
            });
            foreach ($fetched as $dbRow) {
                if (!is_array($dbRow)) {
                    continue;
                }
                $id = (string) ($dbRow['id'] ?? '');
                $decoded = $this->decodeRow($dbRow);
                if ($id !== '' && is_array($decoded)) {
                    $out[$id] = $decoded;
                }
            }
        }

        return $out;
    }

    public function countMessages(bool $notes): int
    {
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM inkorg_messages WHERE is_note = ?');
        $stmt->execute([$notes ? 1 : 0]);
        return (int) $stmt->fetchColumn();
    }

    public function countThreads(): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM inkorg_threads')->fetchColumn();
    }

    /** @param callable(): mixed $fn */
    private function transaction(callable $fn): mixed
    {
        if (!jeab_pdo_tx_busy($this->pdo()) && function_exists('jeab_inkorg_side_ensure')) {
            jeab_inkorg_side_ensure($this->pdo());
        }

        return jeab_pdo_transaction($this->pdo(), static function () use ($fn): mixed {
            return $fn();
        });
    }

    /** @param callable(array{messages:list<array<string,mixed>>}|null): array{0:mixed,1:?array{messages:list<array<string,mixed>>}} $fn */
    public function lockedMessages(callable $fn): mixed
    {
        $threads = [];
        $out = $this->transaction(function () use ($fn, &$threads): mixed {
            $loaded = $this->listMessages();
            $before = [];
            foreach ($loaded as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = mb_substr(trim((string) ($row['id'] ?? '')), 0, 191);
                if ($id === '') {
                    continue;
                }
                $before[$id] = [
                    'canon' => $this->rowCanon($row),
                    'thread_id' => (string) (jeab_inkorg_message_index_fields($row)['thread_id'] ?? ''),
                ];
            }
            $doc = ['messages' => $loaded];
            [$result, $new] = $fn($doc);
            if ($new !== null && is_array($new)) {
                $messages = is_array($new['messages'] ?? null) ? $new['messages'] : [];
                $threads = $this->applyMessageDiff($before, $messages);
            }

            return $result;
        });
        $this->refreshTouchedThreads($threads);

        return $out;
    }

    /** @param callable(array{threads:array<string,array<string,mixed>>}|null): array{0:mixed,1:?array{threads:array<string,array<string,mixed>>}} $fn */
    public function lockedThreads(callable $fn): mixed
    {
        $touched = [];
        $out = $this->transaction(function () use ($fn, &$touched): mixed {
            $loaded = $this->threadsMap();
            $before = [];
            foreach ($loaded as $id => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $tid = mb_substr(trim((string) $id), 0, 191);
                if ($tid === '') {
                    continue;
                }
                $before[$tid] = ['canon' => $this->rowCanon($row)];
            }
            $doc = ['threads' => $loaded];
            [$result, $new] = $fn($doc);
            if ($new !== null && is_array($new)) {
                $threads = is_array($new['threads'] ?? null) ? $new['threads'] : [];
                $touched = $this->applyThreadDiff($before, $threads);
            }

            return $result;
        });
        $this->refreshTouchedThreads($touched);

        return $out;
    }

    /** @param array<string, mixed> $row */
    private function rowCanon(array $row): string
    {
        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '';
    }

    private function noteInkorgTouch(string $kind, string $id): void
    {
        if (!isset($GLOBALS['_JEAB_INKORG_SQL_TOUCHES']) || !is_array($GLOBALS['_JEAB_INKORG_SQL_TOUCHES'])) {
            return;
        }
        $GLOBALS['_JEAB_INKORG_SQL_TOUCHES'][] = $kind . ':' . $id;
    }

    /** @param list<string> $ids */
    private function refreshTouchedThreads(array $ids): void
    {
        if ($ids === [] || !function_exists('jeab_inkorg_sql_refresh_thread')) {
            return;
        }
        foreach (array_values(array_unique($ids)) as $tid) {
            $tid = trim((string) $tid);
            if ($tid === '') {
                continue;
            }
            try {
                $this->touchListThread($tid);
            } catch (Throwable) {
            }
        }
    }

    /**
     * UPSERT bara rader vars kanon ändrats. DELETE bara id som försvann.
     * Liststämpeln lämnas. Berörda trådar fräschas efter commit.
     *
     * @param array<string, array{canon:string, thread_id:string}> $before
     * @param list<array<string, mixed>> $messages
     * @return list<string>
     */
    private function applyMessageDiff(array $before, array $messages): array
    {
        $after = [];
        foreach ($messages as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = mb_substr(trim((string) ($row['id'] ?? '')), 0, 191);
            if ($id === '') {
                continue;
            }
            $after[$id] = $row;
        }
        $threads = [];
        foreach ($after as $id => $row) {
            $canon = $this->rowCanon($row);
            $tid = (string) (jeab_inkorg_message_index_fields($row)['thread_id'] ?? '');
            $prev = $before[$id] ?? null;
            if (!is_array($prev) || (string) ($prev['canon'] ?? '') !== $canon) {
                $this->upsertMessage($id, $row);
                $this->noteInkorgTouch('message', $id);
                if ($tid !== '') {
                    $threads[] = $tid;
                }
                $oldTid = is_array($prev) ? (string) ($prev['thread_id'] ?? '') : '';
                if ($oldTid !== '' && $oldTid !== $tid) {
                    $threads[] = $oldTid;
                }
            }
        }
        foreach ($before as $id => $prev) {
            if (isset($after[$id])) {
                continue;
            }
            $this->deleteMessage((string) $id);
            $this->noteInkorgTouch('message', (string) $id);
            $oldTid = (string) ($prev['thread_id'] ?? '');
            if ($oldTid !== '') {
                $threads[] = $oldTid;
            }
        }

        return $threads;
    }

    /**
     * @param array<string, array{canon:string}> $before
     * @param array<string, array<string, mixed>> $threads
     * @return list<string>
     */
    private function applyThreadDiff(array $before, array $threads): array
    {
        $after = [];
        foreach ($threads as $id => $row) {
            if (!is_array($row)) {
                continue;
            }
            $tid = mb_substr(trim((string) $id), 0, 191);
            if ($tid === '') {
                continue;
            }
            $after[$tid] = $row;
        }
        $touched = [];
        foreach ($after as $tid => $row) {
            $prev = $before[$tid]['canon'] ?? null;
            if ($prev !== $this->rowCanon($row)) {
                $this->upsertThread((string) $tid, $row);
                $this->noteInkorgTouch('thread', (string) $tid);
                $touched[] = (string) $tid;
            }
        }
        foreach ($before as $tid => $_prev) {
            if (isset($after[$tid])) {
                continue;
            }
            if ((int) ($GLOBALS['_JEAB_THREAD_DELETE_LOCK'] ?? 0) !== 1) {
                throw new RuntimeException('Ärendet kan bara raderas via Radera.');
            }
            $this->deleteThread((string) $tid);
            $this->noteInkorgTouch('thread', (string) $tid);
            $touched[] = (string) $tid;
        }

        return $touched;
    }

    public function deleteMessage(string $id): void
    {
        $id = mb_substr(trim($id), 0, 191);
        if ($id === '') {
            return;
        }
        $this->trySql(function () use ($id): void {
            $this->pdo()->prepare('DELETE FROM inkorg_messages WHERE id = ?')->execute([$id]);
            $this->bumpWriteRev();
        });
    }

    public function deleteThread(string $id): void
    {
        $id = mb_substr(trim($id), 0, 191);
        if ($id === '') {
            return;
        }
        $this->trySql(function () use ($id): void {
            if (!function_exists('jeab_thread_sql_delete')) {
                require_once __DIR__ . '/restore_entities.php';
            }
            jeab_thread_sql_delete($this->pdo(), $id);
            $this->bumpWriteRev();
        });
    }

    /** @param list<array<string,mixed>> $messages */
    public function replaceMessages(array $messages): void
    {
        $this->clearListStamp();
        foreach ($messages as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $this->upsertMessage($id, $row);
        }
    }

    /** @param array<string,array<string,mixed>> $threads */
    public function replaceThreads(array $threads): void
    {
        $this->clearListStamp();
        foreach ($threads as $id => $row) {
            if (!is_array($row)) {
                continue;
            }
            $tid = trim((string) $id);
            if ($tid === '') {
                continue;
            }
            $this->upsertThread($tid, $row);
        }
    }

    /** @param list<array<string,mixed>> $messages */
    public function upsertMessageBatch(array $messages): int
    {
        $n = 0;
        foreach ($messages as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $this->upsertMessage($id, $row);
            $n++;
        }
        return $n;
    }

    /** @param array<string,array<string,mixed>> $threads */
    public function upsertThreadBatch(array $threads): int
    {
        $n = 0;
        foreach ($threads as $id => $row) {
            if (!is_array($row)) {
                continue;
            }
            $tid = trim((string) $id);
            if ($tid === '') {
                continue;
            }
            $this->upsertThread($tid, $row);
            $n++;
        }
        return $n;
    }

    /** @param array<string,mixed> $row */
    public function upsertMessage(string $id, array $row): void
    {
        $id = mb_substr(trim($id), 0, 191);
        $idx = jeab_inkorg_message_index_fields($row);
        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || $id === '') {
            throw new RuntimeException('Inkorgen kan inte sparas.');
        }
        $updatedAt = date('Y-m-d H:i:s');
        $this->trySql(function () use ($id, $idx, $json, $updatedAt): void {
            if ($this->isMysql()) {
                $sql = 'INSERT INTO inkorg_messages (id, thread_id, received_at, from_addr, is_note, updated_at, row_json)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE thread_id = VALUES(thread_id), received_at = VALUES(received_at),
                    from_addr = VALUES(from_addr), is_note = VALUES(is_note), updated_at = VALUES(updated_at), row_json = VALUES(row_json)';
            } else {
                $sql = 'INSERT INTO inkorg_messages (id, thread_id, received_at, from_addr, is_note, updated_at, row_json)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON CONFLICT(id) DO UPDATE SET thread_id = excluded.thread_id, received_at = excluded.received_at,
                    from_addr = excluded.from_addr, is_note = excluded.is_note, updated_at = excluded.updated_at, row_json = excluded.row_json';
            }
            $this->pdo()->prepare($sql)->execute([$id, $idx['thread_id'], $idx['received_at'], $idx['from_addr'], $idx['is_note'], $updatedAt, $json]);
            $this->bumpWriteRev();
            $this->touchListThread((string) $idx['thread_id']);
        });
    }

    /** @param array<string,mixed> $row */
    public function upsertThread(string $id, array $row): void
    {
        $id = mb_substr(trim($id), 0, 191);
        $idx = jeab_inkorg_thread_index_fields($row);
        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || $id === '') {
            throw new RuntimeException('Inkorgen kan inte sparas.');
        }
        $this->trySql(function () use ($id, $idx, $json): void {
            if ($this->isMysql()) {
                $sql = 'INSERT INTO inkorg_threads (id, status, updated_at, row_json)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = VALUES(updated_at), row_json = VALUES(row_json)';
            } else {
                $sql = 'INSERT INTO inkorg_threads (id, status, updated_at, row_json)
                    VALUES (?, ?, ?, ?)
                    ON CONFLICT(id) DO UPDATE SET status = excluded.status, updated_at = excluded.updated_at, row_json = excluded.row_json';
            }
            $this->pdo()->prepare($sql)->execute([$id, $idx['status'], $idx['updated_at'], $json]);
            $this->bumpWriteRev();
            $this->touchListThread($id);
        });
    }

    /** Bara klockkolumnen, så listcachen ser läst/oläst. row_json.updated_at och status lämnas. */
    public function touchThreadClock(string $id): void
    {
        $id = mb_substr(trim($id), 0, 191);
        if ($id === '') {
            return;
        }
        $stamp = date('Y-m-d H:i:s');
        $cur = $this->pdo()->prepare('SELECT updated_at FROM inkorg_threads WHERE id = ?');
        $cur->execute([$id]);
        $prev = (string) $cur->fetchColumn();
        if ($prev === $stamp) {
            $stamp = date('Y-m-d H:i:s', time() + 1);
        }
        $this->pdo()->prepare('UPDATE inkorg_threads SET updated_at = ? WHERE id = ?')->execute([$stamp, $id]);
        $this->bumpWriteRev();
        if ($this->listStamp() !== '') {
            $this->setListStamp($this->listFingerprint());
        }
    }

    /**
     * Purge får inte radera trådar, mejl eller kontakter.
     * Anropet finns kvar så migreringen kan fråga, och svaret är alltid 0.
     *
     * @param list<string> $keep
     */
    public function purgeMissing(string $table, array $keep): int
    {
        unset($table, $keep);

        return 0;
    }

    /** @return list<array<string,mixed>> */
    public function findMessagesByThread(string $threadId): array
    {
        $threadId = trim($threadId);
        if ($threadId === '') {
            return [];
        }
        $stmt = $this->pdo()->prepare('SELECT row_json FROM inkorg_messages WHERE thread_id = ? ORDER BY received_at ASC');
        $stmt->execute([$threadId]);
        $out = [];
        foreach ($stmt->fetchAll() as $dbRow) {
            if (is_array($dbRow)) {
                $decoded = $this->decodeRow($dbRow);
                if (is_array($decoded)) {
                    $out[] = $decoded;
                }
            }
        }
        return $out;
    }

    /**
     * Utgående mejl i kundens egna ärenden. Inte hela meddelandetabellen.
     *
     * @return list<array<string,mixed>>
     */
    public function findQuoteCandidates(string $email, string $since): array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@')) {
            return [];
        }
        $since = trim($since);
        $cap = mb_substr($email, 0, 191);
        $ids = $this->ensureListContact() ? $this->contactIdsForEmail($cap) : [];
        $args = [$cap];
        $where = $this->listEmailExpr('l') . ' = ?';
        if ($ids !== []) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $where = '(' . $where . ' OR l.contact_id IN (' . $marks . '))';
            $args = array_merge($args, $ids);
        }
        $args[] = $since;
        $stmt = $this->pdo()->prepare(
            'SELECT m.row_json FROM inkorg_messages m
             INNER JOIN inkorg_list l ON l.id = m.thread_id
             WHERE ' . $where . ' AND m.is_note = 0
               AND (m.received_at IS NULL OR m.received_at = \'\' OR m.received_at >= ?)'
        );
        $stmt->execute($args);
        $out = [];
        foreach ($stmt->fetchAll() as $dbRow) {
            if (is_array($dbRow)) {
                $decoded = $this->decodeRow($dbRow);
                if (is_array($decoded)) {
                    $out[] = $decoded;
                }
            }
        }

        return $out;
    }

    /**
     * Utgående träffar för flera kunder i en fråga. Bara to, source och from, inte hela brevet.
     *
     * @param list<string> $emails
     * @return list<array{email:string,received_at:string,from_addr:string,msg_to:string,msg_source:string}>
     */
    public function quoteSentTouches(array $emails, string $since): array
    {
        $clean = [];
        foreach ($emails as $email) {
            $email = mb_substr(mb_strtolower(trim((string) $email)), 0, 191);
            if ($email !== '' && str_contains($email, '@')) {
                $clean[$email] = $email;
            }
        }
        $clean = array_values($clean);
        if ($clean === []) {
            return [];
        }
        $GLOBALS['_JEAB_QUOTE_INBOX_SQL'] = (int) ($GLOBALS['_JEAB_QUOTE_INBOX_SQL'] ?? 0) + 1;
        $marks = implode(',', array_fill(0, count($clean), '?'));
        $ids = [];
        $idEmail = [];
        $haveContact = $this->ensureListContact();
        if ($haveContact) {
            foreach ($clean as $email) {
                foreach ($this->contactIdsForEmail($email) as $id) {
                    $ids[$id] = $id;
                    $idEmail[$id] = $email;
                }
            }
        }
        $idMarks = $ids === [] ? '' : implode(',', array_fill(0, count($ids), '?'));
        if ($this->isMysql()) {
            $to = "JSON_UNQUOTE(JSON_EXTRACT(m.row_json, '$.to'))";
            $src = "JSON_UNQUOTE(JSON_EXTRACT(m.row_json, '$.source'))";
        } else {
            $to = "json_extract(m.row_json, '$.to')";
            $src = "json_extract(m.row_json, '$.source')";
        }
        $sql = 'SELECT ' . $this->listEmailExpr('l') . ' AS email, ' . ($haveContact ? 'l.contact_id AS contact_id, ' : '') . 'm.received_at AS received_at, m.from_addr AS from_addr, '
            . $to . ' AS msg_to, ' . $src . ' AS msg_source
            FROM inkorg_messages m
            INNER JOIN inkorg_list l ON l.id = m.thread_id
            WHERE (' . $this->listEmailExpr('l') . ' IN (' . $marks . ')';
        if ($idMarks !== '') {
            $sql .= ' OR l.contact_id IN (' . $idMarks . ')';
        }
        $sql .= ') AND m.is_note = 0
              AND (m.received_at IS NULL OR m.received_at = \'\' OR m.received_at >= ?)';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(array_merge($clean, array_values($ids), [trim($since)]));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $email = mb_strtolower(trim((string) ($row['email'] ?? '')));
            if ($email === '') {
                $email = (string) ($idEmail[trim((string) ($row['contact_id'] ?? ''))] ?? '');
            }
            $out[] = [
                'email' => $email,
                'received_at' => (string) ($row['received_at'] ?? ''),
                'from_addr' => mb_strtolower(trim((string) ($row['from_addr'] ?? ''))),
                'msg_to' => (string) ($row['msg_to'] ?? ''),
                'msg_source' => (string) ($row['msg_source'] ?? ''),
            ];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function findMessagesByFrom(string $from): array
    {
        $from = mb_strtolower(trim($from), 'UTF-8');
        if ($from === '') {
            return [];
        }
        $stmt = $this->pdo()->prepare('SELECT row_json FROM inkorg_messages WHERE from_addr = ? LIMIT 50');
        $stmt->execute([mb_substr($from, 0, 191)]);
        $out = [];
        foreach ($stmt->fetchAll() as $dbRow) {
            if (is_array($dbRow)) {
                $decoded = $this->decodeRow($dbRow);
                if (is_array($decoded)) {
                    $out[] = $decoded;
                }
            }
        }
        return $out;
    }

    /** @param callable(array<string,mixed>): array<string,mixed> $fn */
    public function updateThread(string $id, callable $fn): array
    {
        $id = mb_substr(trim($id), 0, 191);
        $next = $this->transaction(function () use ($id, $fn): array {
            $cur = $this->threadsByIds([$id])[$id] ?? ['status' => 'open', 'tags' => []];
            if (!is_array($cur)) {
                $cur = ['status' => 'open', 'tags' => []];
            }
            $next = $fn($cur);
            if (!is_array($next)) {
                $next = $cur;
            }
            if (function_exists('jeab_contacts_strict_inbox_adopt')) {
                $next = jeab_contacts_strict_inbox_adopt($id, $next);
            }
            if (function_exists('jeab_contacts_strict_assert_inbox')) {
                $check = $next;
                $check['id'] = $id;
                jeab_contacts_strict_assert_inbox($check);
            }
            $this->upsertThread($id, $next);
            unset($GLOBALS['_JEAB_INKORG_META'], $GLOBALS['_JEAB_INKORG_META_FP'], $GLOBALS['_JEAB_INKORG_THREADS'], $GLOBALS['_JEAB_INKORG_THREADS_FP'], $GLOBALS['_JEAB_INKORG_LIGHT']);

            return $next;
        });

        if (!is_array($next)) {
            throw new RuntimeException('Inkorgen kan inte sparas.');
        }
        try {
            $this->touchListThread($id);
        } catch (Throwable) {
        }

        return $next;
    }

    public function listStamp(): string
    {
        if ($this->listBuilt === false) {
            return '';
        }
        try {
            $stmt = $this->pdo()->prepare('SELECT payload FROM inkorg_count_cache WHERE cache_key = ?');
            $stmt->execute(['__stamp']);
            $raw = $stmt->fetchColumn();
        } catch (Throwable $e) {
            jeab_inkorg_trace_event('store.list_stamp_catch', $e);
            $this->listBuilt = false;
            return '';
        }
        $stamp = is_string($raw) ? $raw : '';
        $this->listBuilt = $stamp !== '';

        return $stamp;
    }

    public function setListStamp(string $stamp): void
    {
        $this->cacheUpsert('__stamp', $stamp);
        $this->listBuilt = $stamp !== '';
    }

    public function clearListStamp(): void
    {
        try {
            $this->pdo()->prepare('DELETE FROM inkorg_count_cache WHERE cache_key = ?')->execute(['__stamp']);
            $this->pdo()->exec('DELETE FROM inkorg_count_cache');
        } catch (Throwable) {
        }
        $this->listBuilt = false;
    }

    /** @param list<array<string,mixed>> $rows */
    public function replaceListRows(array $rows): void
    {
        $keep = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = mb_substr(trim((string) ($row['id'] ?? '')), 0, 191);
            if ($id === '') {
                continue;
            }
            $this->saveListRow($row);
            $keep[] = $id;
        }
        $this->deleteListRowsExcept($keep);
    }

    /** @param list<string> $keep */
    private function deleteListRowsExcept(array $keep): void
    {
        $have = array_fill_keys($keep, true);
        $drop = [];
        $stmt = $this->pdo()->query('SELECT id FROM inkorg_list');
        foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [] as $id) {
            $id = (string) $id;
            if ($id !== '' && !isset($have[$id])) {
                $drop[] = $id;
            }
        }
        foreach (array_chunk($drop, 400) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $this->pdo()->prepare('DELETE FROM inkorg_list WHERE id IN (' . $placeholders . ')')->execute($chunk);
        }
    }

    /** @return list<array<string,mixed>> */
    public function listForCompany(string $company): array
    {
        $GLOBALS['_JEAB_LIST_COMPANY_CALLS'] = (int) ($GLOBALS['_JEAB_LIST_COMPANY_CALLS'] ?? 0) + 1;
        $stmt = $this->pdo()->prepare('SELECT id, company, list_status, list_at, ' . $this->listEmailSelect() . ', row_json FROM inkorg_list WHERE company = ? ORDER BY list_at DESC');
        $stmt->execute([mb_substr($company, 0, 32)]);

        return $this->decodeList($stmt);
    }

    /** Stängda och raderade rader. Öppna ärenden läses inte. Sidan använder historikKeys, inte den här. */
    public function listHistorik(string $company): array
    {
        $GLOBALS['_JEAB_LIST_HISTORIK_FULL'] = (int) ($GLOBALS['_JEAB_LIST_HISTORIK_FULL'] ?? 0) + 1;
        $this->backfillListStatus();
        [$companySql, $args] = $this->companySql($company);
        $stmt = $this->pdo()->prepare(
            'SELECT row_json FROM inkorg_list WHERE ' . $companySql . ' AND ' . $this->listStatusSql(true) . ' ORDER BY list_at DESC, id DESC'
        );
        $stmt->execute($args);
        $rows = $this->decodeList($stmt);
        $GLOBALS['_JEAB_LIST_HISTORIK_ROWS'] = count($rows);

        return $rows;
    }

    /**
     * Öppna arbetsrader, nyast först. $limit 0 läser hela träffmängden.
     * Annars en sida: rader strikt äldre än ($beforeAt, $beforeId), samma ordning som hela listan.
     *
     * @return list<array<string,mixed>>
     */
    public function listWorking(string $company, int $limit = 0, string $beforeAt = '', string $beforeId = ''): array
    {
        $this->backfillListStatus();
        $GLOBALS['_JEAB_LIST_WORKING_CALLS'] = (int) ($GLOBALS['_JEAB_LIST_WORKING_CALLS'] ?? 0) + 1;
        [$companySql, $args] = $this->companySql($company);
        $limit = max(0, $limit);
        $sql = 'SELECT id, company, list_status, list_at, ' . $this->listEmailSelect() . ', row_json FROM inkorg_list WHERE ' . $companySql . ' AND ' . $this->listStatusSql(false);
        $beforeAt = mb_substr($beforeAt, 0, 40);
        $beforeId = mb_substr($beforeId, 0, 191);
        if ($beforeAt !== '') {
            $sql .= ' AND (list_at < ? OR (list_at = ? AND id < ?))';
            $args[] = $beforeAt;
            $args[] = $beforeAt;
            $args[] = $beforeId;
        }
        $sql .= ' ORDER BY list_at DESC, id DESC';
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
            $GLOBALS['_JEAB_LIST_WORKING_LIMIT'] = $limit;
        }
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($args);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $dbRow) {
            if (!is_array($dbRow)) {
                continue;
            }
            $decoded = $this->decodeRow($dbRow);
            if (!is_array($decoded)) {
                continue;
            }
            if ($limit > 0) {
                $decoded['_list_at'] = (string) ($dbRow['list_at'] ?? '');
                $decoded['_list_id'] = (string) ($dbRow['id'] ?? '');
            }
            $rows[] = $decoded;
        }
        $GLOBALS['_JEAB_LIST_WORKING_FETCHED'] = (int) ($GLOBALS['_JEAB_LIST_WORKING_FETCHED'] ?? 0) + count($rows);

        return $rows;
    }

    /** @param list<string> $ids @return list<string> */
    public function existingIds(array $ids): array
    {
        $want = [];
        foreach ($ids as $id) {
            $id = mb_substr(trim((string) $id), 0, 191);
            if ($id !== '') {
                $want[$id] = true;
            }
        }
        $have = [];
        foreach (array_chunk(array_keys($want), 400) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->pdo()->prepare('SELECT id FROM inkorg_list WHERE id IN (' . $marks . ')');
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $id = (string) $id;
                if ($id !== '') {
                    $have[] = $id;
                }
            }
        }

        return $have;
    }

    /**
     * Kötal utan row_json. Samma gräns som jeab_inkorg_queue_ok:
     * öppen är inte väntar-kund, väntar-leverantör eller stängd, och inte raderad.
     * Mina jämför personen efter TRIM och utan mellanslag, så Sam L och Sam W hålls isär.
     * Otilldelade är tom efter TRIM. MySQL gör JSON null till strängen "null".
     *
     * @return array{mina:int, otilldelade:int, oppna:int, parkerade:int, stangda:int}
     */
    public function queueCounts(string $company, string $me): array
    {
        $this->backfillListStatus();
        [$companySql, $args] = $this->companySql($company);
        $status = $this->jsonScalar('$.status');
        $assignee = $this->jsonScalar('$.assignee');
        $columnOpen = "list_status = 'open'";
        // Öppen kolumn är en arbetsrad även när JSON säger raderad.
        $alive = '(' . $this->notDeletedSql() . ' OR ' . $columnOpen . ')';
        $open = $alive . ' AND (' . $status . " NOT IN ('waiting_supplier', 'waiting_customer', 'closed') OR " . $columnOpen . ')';
        $parked = $alive . ' AND ' . $status . " = 'waiting_supplier' AND list_status <> 'open'";
        $shut = '(NOT (' . $alive . ') OR ' . $status . " IN ('waiting_customer', 'closed')) AND list_status <> 'open'";
        $me = mb_substr(trim($me), 0, 191);
        $minaSql = '0';
        $minaArgs = [];
        if ($me !== '') {
            $flat = "LOWER(REPLACE(TRIM(%s), ' ', ''))";
            $minaSql = 'SUM(CASE WHEN ' . $open . ' AND ' . sprintf($flat, $assignee) . ' = ' . sprintf($flat, '?') . ' THEN 1 ELSE 0 END)';
            $minaArgs[] = $me;
        }
        $sql = 'SELECT
            ' . $minaSql . ' AS mina,
            SUM(CASE WHEN ' . $open . ' AND TRIM(' . $assignee . ") = '' THEN 1 ELSE 0 END) AS otilldelade,
            SUM(CASE WHEN " . $open . ' THEN 1 ELSE 0 END) AS oppna,
            SUM(CASE WHEN ' . $parked . ' THEN 1 ELSE 0 END) AS parkerade,
            SUM(CASE WHEN ' . $shut . ' THEN 1 ELSE 0 END) AS stangda
            FROM inkorg_list WHERE ' . $companySql;
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(array_merge($minaArgs, $args));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $row = is_array($row) ? $row : [];
        $out = [];
        foreach (['mina', 'otilldelade', 'oppna', 'parkerade', 'stangda'] as $key) {
            $out[$key] = (int) ($row[$key] ?? 0);
        }

        return $out;
    }

    /**
     * Antal rader i inkorg_list för en vy. -1 när filtret kräver att trådar byggs.
     * row_json lämnar inte databasen, och importindexet läses inte.
     *
     * @param array<string, mixed> $filter
     */
    public function countViewFilter(array $filter): int
    {
        $built = $this->viewFilterWhere($filter);
        if ($built === null) {
            return -1;
        }
        [$where, $args] = $built;
        $sql = 'SELECT COUNT(*) FROM inkorg_list';
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        $GLOBALS['_JEAB_VIEW_FILTER_SQL'] = (int) ($GLOBALS['_JEAB_VIEW_FILTER_SQL'] ?? 0) + 1;
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($args);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Alla räknebara vyer i ett svep. Filter som kräver trådar blir -1.
     *
     * @param list<array{0:string,1:array<string,mixed>}> $pending
     * @return array<string, int>
     */
    public function countViewFilters(array $pending): array
    {
        $out = [];
        $select = [];
        $args = [];
        $ids = [];
        foreach ($pending as $pair) {
            if (!is_array($pair) || count($pair) < 2) {
                continue;
            }
            $id = (string) $pair[0];
            $filter = is_array($pair[1]) ? $pair[1] : [];
            if ($id === '') {
                continue;
            }
            $built = $this->viewFilterWhere($filter);
            if ($built === null) {
                $out[$id] = -1;
                continue;
            }
            [$where, $oneArgs] = $built;
            $cond = $where === '' ? '1' : $where;
            $select[] = 'SUM(CASE WHEN ' . $cond . ' THEN 1 ELSE 0 END) AS c' . count($ids);
            array_push($args, ...$oneArgs);
            $ids[] = $id;
        }
        if ($select === []) {
            return $out;
        }
        $GLOBALS['_JEAB_VIEW_FILTER_SQL'] = (int) ($GLOBALS['_JEAB_VIEW_FILTER_SQL'] ?? 0) + 1;
        $stmt = $this->pdo()->prepare('SELECT ' . implode(', ', $select) . ' FROM inkorg_list');
        $stmt->execute($args);
        $row = $stmt->fetch(PDO::FETCH_NUM);
        if (!is_array($row)) {
            $row = [];
        }
        foreach ($ids as $i => $id) {
            $out[$id] = (int) ($row[$i] ?? 0);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $filter
     * @return array{0:string,1:list<mixed>}|null
     */
    private function viewFilterWhere(array $filter): ?array
    {
        if (!empty($filter['review']) || !empty($filter['awaiting_quote']) || array_key_exists('show', $filter)) {
            return null;
        }
        if (trim((string) ($filter['q'] ?? '')) !== '') {
            return null;
        }
        $assignee = (string) ($filter['assignee'] ?? '');
        if ($assignee !== '') {
            return null;
        }
        $tagsAny = array_values(array_map('strval', (array) ($filter['tags_any'] ?? [])));
        if (in_array('ingen', $tagsAny, true)) {
            return null;
        }
        $where = [];
        $args = [];
        $company = (string) ($filter['company'] ?? '');
        if ($company === 'meab') {
            $company = 'malmo';
        }
        if ($company !== '') {
            [$companySql, $companyArgs] = $this->companySql($company);
            $where[] = $companySql;
            array_push($args, ...$companyArgs);
        }
        if (!empty($filter['deleted'])) {
            $where[] = "list_status = 'deleted'";
        } else {
            $status = array_values(array_filter(
                array_map('strval', (array) ($filter['status'] ?? [])),
                static fn (string $one): bool => $one !== ''
            ));
            if ($status === []) {
                $where[] = $this->listStatusSql(false);
            } else {
                $marks = implode(',', array_fill(0, count($status), '?'));
                $in = 'list_status IN (' . $marks . ')';
                if (in_array('open', $status, true)) {
                    $in = '(' . $in . " OR list_status IS NULL OR list_status = '')";
                }
                $where[] = $in;
                array_push($args, ...$status);
            }
        }
        $tag = trim((string) ($filter['tag'] ?? ''));
        if ($tag !== '') {
            $where[] = $this->jsonTagHasSql();
            $args[] = $tag;
        }
        $tagOr = [];
        foreach ($tagsAny as $one) {
            if ($one === '' || $one === 'ingen') {
                continue;
            }
            $tagOr[] = $this->jsonTagHasSql();
            $args[] = $one;
        }
        if ($tagOr !== []) {
            $where[] = '(' . implode(' OR ', $tagOr) . ')';
        }
        $types = array_values(array_filter(array_map('strval', (array) ($filter['types'] ?? [])), static fn (string $one): bool => $one !== ''));
        $oneType = trim((string) ($filter['type'] ?? ''));
        if ($oneType !== '') {
            $types[] = $oneType;
        }
        $types = array_values(array_unique($types));
        if ($types !== []) {
            $marks = implode(',', array_fill(0, count($types), '?'));
            $where[] = $this->jsonScalar('$.labels.type') . ' IN (' . $marks . ')';
            array_push($args, ...$types);
        }
        $skip = array_values(array_filter(array_map('strval', (array) ($filter['types_not'] ?? [])), static fn (string $one): bool => $one !== ''));
        if ($skip !== []) {
            $marks = implode(',', array_fill(0, count($skip), '?'));
            $where[] = $this->jsonScalar('$.labels.type') . ' NOT IN (' . $marks . ')';
            array_push($args, ...$skip);
        }

        return [implode(' AND ', $where), $args];
    }

    /** Etikett i $.tags. En platshållare. */
    private function jsonTagHasSql(): string
    {
        $doc = $this->jsonDoc();
        if ($this->isMysql()) {
            return "JSON_CONTAINS(COALESCE(JSON_EXTRACT(" . $doc . ", '$.tags'), JSON_ARRAY()), JSON_QUOTE(?))";
        }

        return "EXISTS (SELECT 1 FROM json_each(COALESCE(json_extract(" . $doc . ", '$.tags'), '[]')) AS je WHERE je.value = ?)";
    }

    /** Arbetsrader för bolaget. Samma predikat som listWorking, utan row_json. */
    public function countWorking(string $company): int
    {
        $this->backfillListStatus();
        [$companySql, $args] = $this->companySql($company);
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM inkorg_list WHERE ' . $companySql . ' AND ' . $this->listStatusSql(false));
        $stmt->execute($args);

        return (int) $stmt->fetchColumn();
    }

    public function countHistorik(string $company, string $assignee = '', string $me = '', string $q = ''): int
    {
        [$where, $args] = $this->historikPageSql($company, $assignee, $me, $q);
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM inkorg_list WHERE ' . $where);
        $stmt->execute($args);
        $n = (int) $stmt->fetchColumn();
        $GLOBALS['_JEAB_LIST_HISTORIK_ROWS'] = $n;

        return $n;
    }

    /** @return list<array<string,mixed>> */
    public function listHistorikPage(string $company, int $limit, int $offset, string $assignee = '', string $me = '', string $q = ''): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        [$where, $args] = $this->historikPageSql($company, $assignee, $me, $q);
        $stmt = $this->pdo()->prepare(
            'SELECT row_json FROM inkorg_list WHERE ' . $where . ' ORDER BY list_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $stmt->execute($args);

        return $this->decodeList($stmt);
    }

    /**
     * Bara id och datum, så en sida kan väljas utan att läsa row_json.
     *
     * @return list<array{id:string, at:string}>
     */
    public function historikKeys(string $company, int $limit, int $offset, string $assignee = '', string $me = '', string $q = ''): array
    {
        $limit = max(0, $limit);
        $offset = max(0, $offset);
        if ($limit === 0) {
            return [];
        }
        $GLOBALS['_JEAB_HISTORIK_KEY_LIMIT'] = $limit;
        [$where, $args] = $this->historikPageSql($company, $assignee, $me, $q);
        $stmt = $this->pdo()->prepare(
            'SELECT id, list_at FROM inkorg_list WHERE ' . $where . ' ORDER BY list_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $stmt->execute($args);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[] = ['id' => $id, 'at' => (string) ($row['list_at'] ?? '')];
        }

        return $out;
    }

    /**
     * Träffar i Historik för en sökning. Öppna rader och övriga stängda rader läses inte.
     *
     * @return list<array<string,mixed>>
     */
    public function listHistorikMatching(string $company, string $q, int $limit = 0): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        [$where, $args] = $this->historikPageSql($company, '', '', $q);
        $sql = 'SELECT row_json FROM inkorg_list WHERE ' . $where . ' ORDER BY list_at DESC, id DESC';
        $limit = max(0, $limit);
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
            $GLOBALS['_JEAB_LIST_HISTORIK_MATCH_LIMIT'] = $limit;
        }
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($args);

        return $this->decodeList($stmt);
    }

    /** @param list<string> $ids @return list<array<string,mixed>> */
    public function listRowsByIds(array $ids): array
    {
        $want = [];
        foreach ($ids as $id) {
            $id = mb_substr(trim((string) $id), 0, 191);
            if ($id !== '') {
                $want[] = $id;
            }
        }
        if ($want === []) {
            return [];
        }
        $byId = [];
        foreach (array_chunk($want, 400) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->pdo()->prepare('SELECT id, row_json FROM inkorg_list WHERE id IN (' . $marks . ')');
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $decoded = $this->decodeRow($row);
                $id = (string) ($row['id'] ?? '');
                if ($id !== '' && is_array($decoded)) {
                    $byId[$id] = $decoded;
                }
            }
        }
        $out = [];
        foreach ($want as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }

        return $out;
    }

    /**
     * En sida tråd-id för inkorg.threads. Räknar och begränsar i SQL.
     *
     * @param list<string> $ownerIds
     * @param list<string> $ownerOffers
     * @param list<string> $serviceTypes
     * @param list<string> $serviceStatuses
     * @return array{total:int, ids:list<string>}
     */
    public function pageInboxThreads(
        string $status,
        string $company,
        array $ownerIds,
        string $ownerName,
        array $ownerOffers,
        array $serviceTypes,
        array $serviceStatuses,
        int $limit,
        int $offset
    ): array {
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $GLOBALS['_JEAB_INKORG_THREAD_PAGE'] = (int) ($GLOBALS['_JEAB_INKORG_THREAD_PAGE'] ?? 0) + 1;
        [$where, $args] = $this->inboxThreadSql($status, $company, $ownerIds, $ownerName, $ownerOffers, $serviceTypes, $serviceStatuses);
        $count = $this->pdo()->prepare('SELECT COUNT(*) FROM inkorg_threads t LEFT JOIN inkorg_list l ON l.id = t.id WHERE ' . $where);
        $count->execute($args);
        $total = (int) $count->fetchColumn();
        $stmt = $this->pdo()->prepare(
            'SELECT t.id FROM inkorg_threads t LEFT JOIN inkorg_list l ON l.id = t.id WHERE ' . $where
            . ' ORDER BY t.updated_at DESC, t.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $stmt->execute($args);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $id = (string) $id;
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return ['total' => $total, 'ids' => $ids];
    }

    /**
     * Mejl för de trådar sidan visar. Inte hela meddelandetabellen.
     *
     * @param list<string> $threadIds
     * @return array<string, list<array<string,mixed>>>
     */
    public function messagesForThreads(array $threadIds): array
    {
        $want = [];
        foreach ($threadIds as $id) {
            $id = mb_substr(trim((string) $id), 0, 191);
            if ($id !== '') {
                $want[$id] = true;
            }
        }
        $out = [];
        foreach (array_chunk(array_keys($want), 200) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->pdo()->prepare(
                'SELECT thread_id, row_json FROM inkorg_messages WHERE thread_id IN (' . $marks . ') ORDER BY received_at ASC'
            );
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $decoded = $this->decodeRow($row);
                $tid = (string) ($row['thread_id'] ?? '');
                if ($tid === '' || !is_array($decoded)) {
                    continue;
                }
                $out[$tid][] = $decoded;
            }
        }

        return $out;
    }

    /**
     * Samma statusgräns som mappfiltret. list_status closed med JSON-status open är en arbetsrad.
     * NULL räknas som öppen: NOT IN utesluter NULL i SQL. Flaggan ändrar inte predikatet.
     */
    private function listStatusSql(bool $historik): string
    {
        $doc = $this->jsonDoc();
        $openJson = $this->isMysql()
            ? "JSON_UNQUOTE(JSON_EXTRACT(" . $doc . ", '$.status')) = 'open'"
            : "json_extract(" . $doc . ", '$.status') = 'open'";
        $jsonOpen = '(' . $openJson . ' AND ' . $this->notDeletedSql() . ')';
        if ($historik) {
            return "list_status IN ('closed', 'deleted') AND NOT " . $jsonOpen;
        }

        return "(list_status IS NULL OR list_status = '' OR list_status NOT IN ('closed', 'deleted') OR " . $jsonOpen . ")";
    }

    /** JSON-text. MySQL gör JSON null till strängen "null", som räknas som tom. */
    /** Ogiltig JSON räknas som tomt dokument så en trasig rad inte fäller hela frågan. */
    private function jsonDoc(): string
    {
        if ($this->isMysql()) {
            return "IF(JSON_VALID(row_json), row_json, '{}')";
        }

        return "CASE WHEN json_valid(row_json) THEN row_json ELSE '{}' END";
    }

    private function jsonScalar(string $path): string
    {
        $doc = $this->jsonDoc();
        if ($this->isMysql()) {
            $raw = "JSON_UNQUOTE(JSON_EXTRACT(" . $doc . ", '" . $path . "'))";
        } else {
            $raw = "json_extract(" . $doc . ", '" . $path . "')";
        }

        return "COALESCE(NULLIF($raw, 'null'), '')";
    }

    private function notDeletedSql(): string
    {
        $doc = $this->jsonDoc();
        if ($this->isMysql()) {
            return "(JSON_EXTRACT(" . $doc . ", '$.deleted') IS NULL OR JSON_EXTRACT(" . $doc . ", '$.deleted') = false OR JSON_EXTRACT(" . $doc . ", '$.deleted') = 0)";
        }

        return "(json_extract(" . $doc . ", '$.deleted') IS NULL OR json_extract(" . $doc . ", '$.deleted') IN (0, 'false', '0'))";
    }

    public function listStatusBackfillDone(): bool
    {
        if ($this->listStatusFlag === true) {
            return true;
        }
        if (!function_exists('jeab_settings_value')) {
            return false;
        }
        $driver = $this->isMysql() ? 'mysql' : 'sqlite';
        $done = jeab_settings_value($this->pdo(), $driver, 'inkorg_list_status_backfill') === 'ok';
        if ($done) {
            $this->listStatusFlag = true;
        }

        return $done;
    }

    /** @return array{0: string, 1: list<string>} */
    private function companySql(string $company): array
    {
        $company = mb_substr($company, 0, 32);
        if ($company === 'malmo') {
            return ['company IN (?, ?)', ['malmo', 'meab']];
        }

        return ['company = ?', [$company]];
    }

    /**
     * Sidan filtrerar på list_status, som är indexerad. JSON-status läses inte för varje rad.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function historikPageSql(string $company, string $assignee, string $me, string $q): array
    {
        $this->ensureListStatus();
        [$companySql, $args] = $this->companySql($company);
        [$assigneeSql, $assigneeArgs] = $this->historikAssigneeSql($assignee, $me);
        [$searchSql, $searchArgs] = $this->historikSearchSql($q);

        return [
            $companySql . ' AND list_status IN (\'closed\', \'deleted\')' . $assigneeSql . $searchSql,
            array_merge($args, $assigneeArgs, $searchArgs),
        ];
    }

    /** @return array{0: string, 1: list<string>} */
    private function historikSearchSql(string $q): array
    {
        $q = mb_strtolower(trim($q));
        if ($q === '') {
            return ['', []];
        }
        $mail = $this->listEmailExpr();
        $hay = $this->isMysql()
            ? "LOWER(CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_json, '$.search_hay')), ''), ' ', " . $mail . ", ' ', COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_json, '$.customer_name')), ''), ' ', COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_json, '$.list_snippet')), '')))"
            : "lower(COALESCE(json_extract(row_json, '$.search_hay'), '') || ' ' || " . $mail . " || ' ' || COALESCE(json_extract(row_json, '$.customer_name'), '') || ' ' || COALESCE(json_extract(row_json, '$.list_snippet'), ''))";

        return [' AND instr(' . $hay . ', ?) > 0', [$q]];
    }

    /**
     * @param list<string> $ownerIds
     * @param list<string> $ownerOffers
     * @param list<string> $serviceTypes
     * @param list<string> $serviceStatuses
     * @return array{0: string, 1: list<string>}
     */
    private function inboxThreadSql(
        string $status,
        string $company,
        array $ownerIds,
        string $ownerName,
        array $ownerOffers,
        array $serviceTypes,
        array $serviceStatuses
    ): array {
        $deleted = $this->jsonPath('t.row_json', '$.deleted_at');
        $where = ["(COALESCE($deleted, '') = '')"];
        $args = [];
        if ($status === 'open' || $status === 'closed') {
            $where[] = 't.status = ?';
            $args[] = $status;
        } else {
            $where[] = "t.status <> 'deleted'";
        }
        $company = mb_substr(trim($company), 0, 32);
        if ($company !== '') {
            $companies = $company === 'malmo' ? ['malmo', 'meab'] : [$company];
            $marks = implode(',', array_fill(0, count($companies), '?'));
            $threadCompany = $this->jsonPath('t.row_json', '$.company');
            $where[] = "($threadCompany IN ($marks) OR l.company IN ($marks))";
            $args = array_merge($args, $companies, $companies);
        }
        $ownerName = trim($ownerName);
        $ownerIds = array_values(array_filter(array_map(static fn($id): string => mb_substr(trim((string) $id), 0, 64), $ownerIds)));
        $ownerOffers = array_values(array_unique(array_filter(array_map(static fn($offer): string => mb_strtolower(trim((string) $offer)), $ownerOffers))));
        if ($ownerName !== '' || $ownerIds !== []) {
            $assignee = "LOWER(COALESCE(" . $this->jsonPath('t.row_json', '$.assignee') . ", ''))";
            $parts = [];
            if ($ownerName !== '') {
                $parts[] = $assignee . ' = LOWER(?)';
                $args[] = $ownerName;
            }
            if ($ownerIds !== []) {
                $marks = implode(',', array_fill(0, count($ownerIds), '?'));
                $parts[] = $assignee . ' IN (' . $marks . ')';
                foreach ($ownerIds as $id) {
                    $args[] = mb_strtolower($id);
                }
            }
            if ($ownerOffers !== []) {
                $marks = implode(',', array_fill(0, count($ownerOffers), '?'));
                $offerParts = [];
                foreach (['t.row_json', 'l.row_json'] as $column) {
                    $offerParts[] = 'LOWER(COALESCE(' . $this->jsonPath($column, '$.offer') . ", '')) IN ($marks)";
                    $offerParts[] = 'LOWER(COALESCE(' . $this->jsonPath($column, '$.labels.offer') . ", '')) IN ($marks)";
                }
                $parts[] = "($assignee = '' AND (" . implode(' OR ', $offerParts) . '))';
                for ($i = 0; $i < 4; $i++) {
                    foreach ($ownerOffers as $offer) {
                        $args[] = $offer;
                    }
                }
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
        $serviceStatuses = array_values(array_filter(array_map('strval', $serviceStatuses), static fn(string $s): bool => $s === 'open' || $s === 'closed'));
        if ($serviceStatuses !== []) {
            $marks = implode(',', array_fill(0, count($serviceStatuses), '?'));
            $where[] = 't.status IN (' . $marks . ')';
            $args = array_merge($args, $serviceStatuses);
        }
        $serviceTypes = array_values(array_filter(array_map(static fn($type): string => mb_substr(trim((string) $type), 0, 80), $serviceTypes)));
        if ($serviceTypes !== []) {
            $marks = implode(',', array_fill(0, count($serviceTypes), '?'));
            $typeSql = [];
            foreach ([
                ['l.row_json', '$.list_type'],
                ['l.row_json', '$.labels.type'],
                ['t.row_json', '$.labels.type'],
            ] as [$column, $path]) {
                $typeSql[] = $this->jsonPath($column, $path) . " IN ($marks)";
            }
            $typeSql[] = 'EXISTS (SELECT 1 FROM inkorg_messages m WHERE m.thread_id = t.id AND ' . $this->jsonPath('m.row_json', '$.labels.type') . " IN ($marks))";
            $where[] = '(' . implode(' OR ', $typeSql) . ')';
            for ($i = 0; $i < 4; $i++) {
                $args = array_merge($args, $serviceTypes);
            }
        }
        $where[] = 'EXISTS (SELECT 1 FROM inkorg_messages m WHERE m.thread_id = t.id)';

        return [implode(' AND ', $where), $args];
    }

    private function jsonPath(string $column, string $path): string
    {
        if ($this->isMysql()) {
            return "JSON_UNQUOTE(JSON_EXTRACT($column, '$path'))";
        }

        return "json_extract($column, '$path')";
    }

    /** @return array{0: string, 1: list<string>} */
    private function historikAssigneeSql(string $assignee, string $me): array
    {
        if ($assignee !== 'me' && $assignee !== 'others') {
            return ['', []];
        }
        $expr = $this->isMysql()
            ? "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_json, '$.assignee')), '')"
            : "COALESCE(json_extract(row_json, '$.assignee'), '')";
        if ($assignee === 'me') {
            if ($me === '') {
                return [' AND 1 = 0', []];
            }

            // Egna Historik: bara personens egna, inte hela otilldelade arkivet.
            return [' AND ' . $expr . " <> '' AND LOWER(" . $expr . ') = LOWER(?)', [$me]];
        }

        return [' AND ' . $expr . " <> '' AND LOWER(" . $expr . ') <> LOWER(?)', [$me]];
    }

    public function backfillListStatus(): int
    {
        $this->ensureListStatus();
        if ($this->listStatusBackfillDone()) {
            return 0;
        }
        $check = $this->pdo()->query('SELECT 1 FROM inkorg_list WHERE list_status IS NULL OR list_status = \'\' LIMIT 1');
        if (!$check || $check->fetchColumn() === false) {
            return 0;
        }
        if ($this->isMysql()) {
            $sql = 'UPDATE inkorg_list SET list_status = CASE
                WHEN JSON_EXTRACT(row_json, \'$.deleted\') = true THEN \'deleted\'
                WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_json, \'$.status\')), \'\') = \'\' THEN \'open\'
                ELSE JSON_UNQUOTE(JSON_EXTRACT(row_json, \'$.status\'))
            END WHERE list_status IS NULL OR list_status = \'\'';
        } else {
            $sql = 'UPDATE inkorg_list SET list_status = CASE
                WHEN json_extract(row_json, \'$.deleted\') IN (1, \'true\', \'1\') THEN \'deleted\'
                WHEN json_extract(row_json, \'$.status\') IS NULL OR json_extract(row_json, \'$.status\') = \'\' THEN \'open\'
                ELSE json_extract(row_json, \'$.status\')
            END WHERE list_status IS NULL OR list_status = \'\'';
        }
        $ran = $this->pdo()->exec($sql);
        $n = is_int($ran) ? $ran : 0;
        if ($n > 0 && function_exists('jeab_settings_put')) {
            $driver = $this->isMysql() ? 'mysql' : 'sqlite';
            if (jeab_settings_put($this->pdo(), $driver, 'inkorg_list_status_backfill', 'ok')) {
                $this->listStatusFlag = true;
            }
        }

        return $n;
    }

    private bool $listStatusReady = false;

    private ?bool $listStatusFlag = null;

    private function ensureListStatus(): void
    {
        if ($this->listStatusReady) {
            return;
        }
        $driver = $this->isMysql() ? 'mysql' : 'sqlite';
        if (function_exists('jeab_inkorg_ensure_list_status')) {
            jeab_inkorg_ensure_list_status($this->pdo(), $driver);
        }
        $this->listStatusReady = true;
    }

    private function ensureListContact(): bool
    {
        if ($this->listContactReady) {
            return true;
        }
        if (!function_exists('jeab_inkorg_ensure_list_contact')) {
            return false;
        }
        $driver = $this->isMysql() ? 'mysql' : 'sqlite';
        if (!jeab_inkorg_ensure_list_contact($this->pdo(), $driver)) {
            return false;
        }
        $this->listContactReady = true;

        return true;
    }

    /** @return list<string> */
    private function contactIdsForEmail(string $email): array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return [];
        }
        $ids = [];
        try {
            $stmt = $this->pdo()->prepare('SELECT id FROM contacts WHERE owner_email = ?');
            $stmt->execute([$email]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
                $id = trim((string) $id);
                if ($id !== '') {
                    $ids[$id] = $id;
                }
            }
        } catch (Throwable) {
            return [];
        }
        try {
            $stmt = $this->pdo()->prepare('SELECT contact_id FROM contact_emails WHERE email = ?');
            $stmt->execute([$email]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
                $id = trim((string) $id);
                if ($id !== '') {
                    $ids[$id] = $id;
                }
            }
        } catch (Throwable) {
        }

        return array_values($ids);
    }

    /** @return list<array<string,mixed>> */
    public function listForCustomer(string $email, int $limit = 0): array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return [];
        }
        $cap = mb_substr($email, 0, 191);
        $ids = $this->contactIdsForEmail($cap);
        $args = [$cap];
        $sql = 'SELECT row_json FROM inkorg_list WHERE ' . $this->listEmailExpr() . ' = ?';
        if ($ids !== [] && $this->ensureListContact()) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $sql .= ' OR contact_id IN (' . $marks . ')';
            $args = array_merge($args, $ids);
        }
        $sql .= ' ORDER BY list_at DESC';
        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($args);

        return $this->decodeList($stmt);
    }

    public function countCacheGet(string $key, int $ttl): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT stored_at, payload FROM inkorg_count_cache WHERE cache_key = ?');
        $stmt->execute([mb_substr($key, 0, 191)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        if (time() - (int) ($row['stored_at'] ?? 0) > $ttl) {
            return null;
        }
        $data = json_decode((string) ($row['payload'] ?? ''), true);

        return is_array($data) ? $data : null;
    }

    /** @param array<string,mixed> $payload */
    public function countCachePut(string $key, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return;
        }
        $this->cacheUpsert(mb_substr($key, 0, 191), $json);
    }

    /** @param array<string,mixed> $row */
    public function saveListRow(array $row): void
    {
        $id = mb_substr(trim((string) ($row['id'] ?? '')), 0, 191);
        if ($id === '') {
            return;
        }
        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return;
        }
        $company = mb_substr((string) ($row['company'] ?? ''), 0, 32);
        $email = mb_substr(mb_strtolower((string) ($row['customer_email'] ?? '')), 0, 191);
        $at = mb_substr((string) ($row['updated_at'] ?? ''), 0, 40);
        $status = jeab_inkorg_list_row_status($row);
        $contactId = mb_substr(trim((string) ($row['contact_id'] ?? '')), 0, 64);
        $this->ensureListStatus();
        // Kolumnlistan byggs efter vad som finns. Utan customer_email ligger e-posten kvar i row_json.
        $cols = ['id' => $id, 'company' => $company];
        if ($this->listEmailColumn()) {
            $cols['customer_email'] = $email;
        }
        $cols += ['list_at' => $at, 'list_status' => $status];
        if ($this->ensureListContact()) {
            $cols['contact_id'] = $contactId;
        }
        $cols['row_json'] = $json;
        $names = array_keys($cols);
        $update = array_values(array_filter($names, static fn (string $c): bool => $c !== 'id'));
        $marks = implode(', ', array_fill(0, count($names), '?'));
        if ($this->isMysql()) {
            $sql = 'INSERT INTO inkorg_list (' . implode(', ', $names) . ') VALUES (' . $marks . ')
                ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn (string $c): string => $c . ' = VALUES(' . $c . ')', $update));
        } else {
            $sql = 'INSERT INTO inkorg_list (' . implode(', ', $names) . ') VALUES (' . $marks . ')
                ON CONFLICT(id) DO UPDATE SET ' . implode(', ', array_map(static fn (string $c): string => $c . ' = excluded.' . $c, $update));
        }
        $this->pdo()->prepare($sql)->execute(array_values($cols));
    }

    /**
     * Ny eller ändrad länk. Skriver bara contact_id, både i kolumnen och i raden.
     * Namn, e-post, telefon och adress lämnas. Saknas kolumnen uppdateras raden
     * ändå, så tick kan fylla kolumnen sedan.
     */
    public function assignListContact(string $id, string $contactId): bool
    {
        $id = mb_substr(trim($id), 0, 191);
        if ($id === '') {
            return false;
        }
        $contactId = mb_substr(trim($contactId), 0, 64);
        $column = $this->ensureListContact();
        try {
            $stmt = $this->pdo()->prepare('SELECT row_json FROM inkorg_list WHERE id = ?');
            $stmt->execute([$id]);
            $raw = $stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
        if (!is_string($raw)) {
            return false;
        }
        $data = json_decode($raw, true);
        $json = null;
        if (is_array($data) && (string) ($data['contact_id'] ?? '') !== $contactId) {
            $data['contact_id'] = $contactId;
            $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $json = is_string($encoded) ? $encoded : null;
        }
        if ($column) {
            if (is_string($json)) {
                $this->pdo()->prepare('UPDATE inkorg_list SET contact_id = ?, row_json = ? WHERE id = ?')->execute([$contactId, $json, $id]);
            } else {
                $this->pdo()->prepare('UPDATE inkorg_list SET contact_id = ? WHERE id = ?')->execute([$contactId, $id]);
            }

            return true;
        }
        if (is_string($json)) {
            $this->pdo()->prepare('UPDATE inkorg_list SET row_json = ? WHERE id = ?')->execute([$json, $id]);
        }

        return true;
    }

    /**
     * Senaste ärendena för en kontakt. Index på contact_id.
     * Sidan skannar inte row_json när kolumnen saknas.
     */
    public function listForContact(string $contactId, int $limit = 40): array
    {
        $contactId = mb_substr(trim($contactId), 0, 64);
        if ($contactId === '') {
            return [];
        }
        $limit = max(1, min(80, $limit));
        $tail = ' AND list_status <> \'deleted\' ORDER BY list_at DESC, id DESC LIMIT ' . $limit;
        if ($this->ensureListContact()) {
            $stmt = $this->pdo()->prepare('SELECT row_json FROM inkorg_list WHERE contact_id = ?' . $tail);
            $stmt->execute([$contactId]);

            return $this->decodeList($stmt);
        }
        $page = function_exists('jeab_schema_request_skips_ddl') && jeab_schema_request_skips_ddl();
        try {
            $stmt = $this->pdo()->prepare('SELECT row_json FROM inkorg_list WHERE contact_id = ?' . $tail);
            $stmt->execute([$contactId]);

            return $this->decodeList($stmt);
        } catch (Throwable) {
            if ($page) {
                return [];
            }
        }
        $expr = $this->isMysql()
            ? 'JSON_UNQUOTE(JSON_EXTRACT(row_json, \'$.contact_id\')) = ?'
            : 'json_extract(row_json, \'$.contact_id\') = ?';
        $stmt = $this->pdo()->prepare(
            'SELECT row_json FROM inkorg_list WHERE ' . $expr . ' ORDER BY list_at DESC, id DESC LIMIT ' . $limit
        );
        $stmt->execute([$contactId]);

        return $this->decodeList($stmt);
    }

    public function deleteListRow(string $id): void
    {
        $id = mb_substr(trim($id), 0, 191);
        if ($id === '') {
            return;
        }
        $this->pdo()->prepare('DELETE FROM inkorg_list WHERE id = ?')->execute([$id]);
    }

    public function importCacheGet(string $key): ?array
    {
        try {
            $stmt = $this->pdo()->prepare('SELECT payload FROM inkorg_import_cache WHERE cache_key = ?');
            $stmt->execute([mb_substr($key, 0, 64)]);
            $raw = $stmt->fetchColumn();
        } catch (Throwable $e) {
            jeab_inkorg_trace_event('store.import_cache_catch', $e);

            return null;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $payload */
    public function importCachePut(string $key, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return;
        }
        try {
            $key = mb_substr($key, 0, 64);
            if ($this->isMysql()) {
                $sql = 'INSERT INTO inkorg_import_cache (cache_key, payload) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE payload = VALUES(payload)';
            } else {
                $sql = 'INSERT INTO inkorg_import_cache (cache_key, payload) VALUES (?, ?)
                    ON CONFLICT(cache_key) DO UPDATE SET payload = excluded.payload';
            }
            $this->pdo()->prepare($sql)->execute([$key, $json]);
        } catch (Throwable) {
        }
    }

    private function cacheUpsert(string $key, string $payload): void
    {
        if ($this->isMysql()) {
            $sql = 'INSERT INTO inkorg_count_cache (cache_key, stored_at, payload) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE stored_at = VALUES(stored_at), payload = VALUES(payload)';
        } else {
            $sql = 'INSERT INTO inkorg_count_cache (cache_key, stored_at, payload) VALUES (?, ?, ?)
                ON CONFLICT(cache_key) DO UPDATE SET stored_at = excluded.stored_at, payload = excluded.payload';
        }
        $this->pdo()->prepare($sql)->execute([mb_substr($key, 0, 191), time(), $payload]);
    }

    private ?bool $listBuilt = null;

    private function touchListThread(string $tid): void
    {
        if ($this->skipListTouch || $tid === '') {
            return;
        }
        if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($this->pdo())) {
            return;
        }
        if (!function_exists('jeab_inkorg_sql_refresh_thread')) {
            $this->clearListStamp();
            return;
        }
        jeab_inkorg_sql_refresh_thread($this, $tid);
    }

    private bool $skipListTouch = false;

    /** @return list<array<string,mixed>> */
    private function decodeList(PDOStatement $stmt): array
    {
        $dbRows = [];
        foreach ($stmt->fetchAll() as $dbRow) {
            if (!is_array($dbRow)) {
                continue;
            }
            $dbRows[] = $dbRow;
        }
        $out = [];
        foreach ($dbRows as $dbRow) {
            $decoded = $this->decodeRow($dbRow);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }
}

function jeab_inkorg_pdo_store(): ?JeabPdoInkorgStore
{
    static $store = null;
    if (!empty($GLOBALS['_JEAB_INKORG_PDO_DROP'])) {
        if ($store instanceof JeabPdoInkorgStore) {
            $store->disconnect();
        }
        $live = $GLOBALS['_JEAB_INKORG_SQL_LIVE'] ?? null;
        if ($live instanceof JeabPdoInkorgStore && $live !== $store) {
            $live->disconnect();
        }
        $store = null;
        unset($GLOBALS['_JEAB_INKORG_STORE'], $GLOBALS['_JEAB_INKORG_SIDE_WARM'], $GLOBALS['_JEAB_INKORG_SQL_LIVE']);
        $GLOBALS['_JEAB_INKORG_PDO_DROP'] = false;
        jeab_inkorg_trace_event('pdo_store.drop');

        return null;
    }
    if (!jeab_inkorg_use_sql()) {
        $store = null;
        return null;
    }
    if ($store instanceof JeabPdoInkorgStore) {
        $GLOBALS['_JEAB_INKORG_STORE'] = $store;
        if (function_exists('jeab_inkorg_json_side_warm')) {
            jeab_inkorg_json_side_warm();
        }

        return $store;
    }
    $pdo = jeab_inkorg_store_pdo();
    if (!$pdo instanceof PDO) {
        jeab_inkorg_trace_event('pdo_store.null_pdo', null, ['pdo' => (string) ($GLOBALS['_JEAB_INKORG_TRACE_PDO_STATE'] ?? 'null')]);

        return null;
    }
    $store = new JeabPdoInkorgStore($pdo);
    $GLOBALS['_JEAB_INKORG_STORE'] = $store;
    if (function_exists('jeab_inkorg_json_side_warm')) {
        jeab_inkorg_json_side_warm();
    }

    return $store;
}
