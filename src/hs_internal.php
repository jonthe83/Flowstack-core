<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * hs.create_internal. Skapar ett Help Scout-ärende med imported=true och en
 * intern anteckning. Help Scout skickar då inget till kunden.
 * dry=1 är standard. Skrivningen går genom jeab_malmo_hs_http_write, som
 * nekar anropet om imported saknas. Ingen sändfunktion.
 * dry=0 kräver idem. Nyckeln och ärende-id lagras i MySQL (kolumner, inte JSON).
 * Samma nyckel igen lämnar tillbaka det sparade id:t och skapar inget nytt.
 *
 * hs.search läser Help Scout först (bara GET): mailbox, query, alla statusar
 * inklusive spam. Svaret har id, subject, status, customer_email, createdAt.
 * Anropet släpper inte databasen. Inkorgen dog när poolen stängdes mitt i
 * ett anrop som fortfarande behövde den.
 */

function jeab_hs_internal_wants_dry(array $input): bool
{
    if (function_exists('jeab_reminder_admin_wants_dry')) {
        return jeab_reminder_admin_wants_dry($input);
    }
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $dry = $input['dry'];

    return !($dry === false || $dry === 0 || $dry === '0' || $dry === 0.0);
}

function jeab_hs_internal_scrub(string $text): string
{
    if (function_exists('jeab_reminder_manual_scrub')) {
        return jeab_reminder_manual_scrub($text);
    }

    return $text;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_hs_internal_customer(array $input): array
{
    $raw = $input['customer'] ?? null;
    $email = '';
    $name = '';
    if (is_string($raw)) {
        $email = trim($raw);
    } elseif (is_array($raw)) {
        $email = trim((string) ($raw['email'] ?? ''));
        $name = trim((string) ($raw['name'] ?? $raw['firstName'] ?? ''));
        if ($name === '') {
            $name = trim((string) (($raw['first'] ?? '') . ' ' . ($raw['last'] ?? '')));
        }
    }
    $email = function_exists('jeab_kontakt_email') ? jeab_kontakt_email($email) : strtolower($email);
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return ['ok' => false, 'error' => 'ogiltig e-post'];
    }
    $first = $name;
    $last = '';
    if (str_contains($name, ' ')) {
        $bits = preg_split('/\s+/u', $name, 2) ?: [];
        $first = trim((string) ($bits[0] ?? ''));
        $last = trim((string) ($bits[1] ?? ''));
    }

    return ['ok' => true, 'email' => $email, 'first' => $first, 'last' => $last];
}

/**
 * @param array<string, mixed> $user
 */
function jeab_hs_internal_user_matches(array $user, string $given): bool
{
    $given = trim($given);
    if ($given === '') {
        return false;
    }
    $id = trim((string) ($user['id'] ?? ''));
    if ($id !== '' && $id === $given) {
        return true;
    }
    $email = function_exists('mb_strtolower')
        ? mb_strtolower(trim((string) ($user['email'] ?? '')), 'UTF-8')
        : strtolower(trim((string) ($user['email'] ?? '')));
    $givenMail = function_exists('mb_strtolower') ? mb_strtolower($given, 'UTF-8') : strtolower($given);
    if ($email !== '' && $email === $givenMail) {
        return true;
    }
    $first = trim((string) ($user['firstName'] ?? $user['first'] ?? ''));
    $last = trim((string) ($user['lastName'] ?? $user['last'] ?? ''));
    $full = trim($first . ' ' . $last);
    if ($full !== '' && strcasecmp($full, $given) === 0) {
        return true;
    }
    $parts = preg_split('/\s+/u', $given) ?: [];
    if (count($parts) === 2 && $first !== '' && strcasecmp((string) $parts[0], $first) === 0) {
        $want = (string) $parts[1];
        if ($last !== '' && strcasecmp($want, $last) === 0) {
            return true;
        }
        $wantLen = function_exists('mb_strlen') ? mb_strlen($want, 'UTF-8') : strlen($want);
        if ($wantLen === 1 && $last !== '') {
            $initial = function_exists('mb_substr') ? mb_substr($last, 0, 1, 'UTF-8') : substr($last, 0, 1);

            return strcasecmp($initial, $want) === 0;
        }
    }

    return false;
}

function jeab_hs_internal_user_label(array $user): string
{
    $first = trim((string) ($user['firstName'] ?? $user['first'] ?? ''));
    $last = trim((string) ($user['lastName'] ?? $user['last'] ?? ''));
    $full = trim($first . ' ' . $last);

    return $full !== '' ? $full : trim((string) ($user['email'] ?? ''));
}

/**
 * @return array{ok:bool, error?:string, id?:int, label?:string}
 */
function jeab_hs_internal_resolve_user(string $given): array
{
    $given = trim($given);
    if ($given === '') {
        return ['ok' => false, 'error' => 'ange användare'];
    }
    if (!function_exists('jeab_hs_import_get_json')) {
        return ['ok' => false, 'error' => 'hs_svar'];
    }
    try {
        $json = jeab_hs_import_get_json('users', []);
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'hs_svar'];
    }
    $rows = $json['_embedded']['users'] ?? [];
    if (!is_array($rows)) {
        return ['ok' => false, 'error' => 'hs_svar'];
    }
    $hits = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !jeab_hs_internal_user_matches($row, $given)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            $hits[$id] = $row;
        }
    }
    if ($hits === []) {
        return ['ok' => false, 'error' => 'användaren finns inte i Help Scout'];
    }
    if (count($hits) !== 1) {
        return ['ok' => false, 'error' => 'användaren är inte entydig i Help Scout'];
    }
    $user = array_values($hits)[0];

    return ['ok' => true, 'id' => (int) ($user['id'] ?? 0), 'label' => jeab_hs_internal_user_label($user)];
}

/**
 * @param array<string, mixed> $customer
 * @return array<string, mixed>
 */
function jeab_hs_internal_payload(int $mailboxId, int $userId, array $customer, string $body, string $subject): array
{
    $who = ['email' => (string) $customer['email']];
    if ((string) ($customer['first'] ?? '') !== '') {
        $who['firstName'] = (string) $customer['first'];
    }
    if ((string) ($customer['last'] ?? '') !== '') {
        $who['lastName'] = (string) $customer['last'];
    }

    return [
        'subject' => $subject,
        'type' => 'email',
        'mailboxId' => $mailboxId,
        'status' => 'active',
        'imported' => true,
        'customer' => $who,
        'assignTo' => $userId,
        'threads' => [[
            'type' => 'note',
            'text' => $body,
            'user' => $userId,
        ]],
    ];
}

/**
 * Bara via Malmö-klienten, och bara när imported är true och tråden är en anteckning.
 *
 * @param array<string, mixed> $payload
 * @return array{code:int, error:string, headers:list<string>, body:string, id:int}
 */
function jeab_hs_internal_post(array $payload): array
{
    $empty = ['code' => 0, 'error' => 'imported_saknas', 'headers' => [], 'body' => '', 'id' => 0];
    if (($payload['imported'] ?? null) !== true || ($payload['status'] ?? '') !== 'active') {
        return $empty;
    }
    $threads = $payload['threads'] ?? null;
    $thread = is_array($threads) ? ($threads[0] ?? null) : null;
    if (!is_array($thread) || ($thread['type'] ?? '') !== 'note' || isset($payload['reply'])) {
        return ['code' => 0, 'error' => 'inte_anteckning', 'headers' => [], 'body' => '', 'id' => 0];
    }
    if (!function_exists('jeab_malmo_hs_http_write')) {
        return ['code' => 0, 'error' => 'hs_svar', 'headers' => [], 'body' => '', 'id' => 0];
    }
    $res = jeab_malmo_hs_http_write('POST', 'conversations', $payload);
    $code = (int) ($res['code'] ?? 0);
    $error = (string) ($res['error'] ?? '');
    if ($error === '' && ($code < 200 || $code >= 300)) {
        $error = 'hs_svar';
    }

    return [
        'code' => $code,
        'error' => $error,
        'headers' => array_values(array_map('strval', (array) ($res['headers'] ?? []))),
        'body' => (string) ($res['body'] ?? ''),
        'id' => (int) ($res['id'] ?? 0),
    ];
}

/**
 * @param array{code:int, headers:list<string>, body:string, id:int} $res
 */
function jeab_hs_internal_conversation_id(array $res): int
{
    if ((int) ($res['id'] ?? 0) > 0) {
        return (int) $res['id'];
    }
    $body = json_decode((string) ($res['body'] ?? ''), true);
    if (is_array($body) && (int) ($body['id'] ?? 0) > 0) {
        return (int) $body['id'];
    }
    foreach ((array) ($res['headers'] ?? []) as $line) {
        if (preg_match('#/conversations/(\d+)#', (string) $line, $m) === 1) {
            return (int) $m[1];
        }
    }

    return 0;
}

function jeab_hs_internal_idem_driver(PDO $pdo): string
{
    $name = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    return $name === 'mysql' ? 'mysql' : 'sqlite';
}

function jeab_hs_internal_idem_pdo(): ?PDO
{
    $injected = $GLOBALS['_JEAB_HS_IDEM_PDO'] ?? null;
    if ($injected instanceof PDO) {
        return $injected;
    }
    if (!function_exists('jeab_store')) {
        return null;
    }
    try {
        $store = jeab_store();
    } catch (Throwable) {
        return null;
    }
    if (!is_object($store) || !method_exists($store, 'pdo')) {
        return null;
    }
    try {
        $pdo = $store->pdo();
    } catch (Throwable) {
        return null;
    }

    return $pdo instanceof PDO ? $pdo : null;
}

function jeab_hs_internal_idem_install(PDO $pdo): void
{
    static $done = [];
    $id = spl_object_id($pdo);
    if (isset($done[$id])) {
        return;
    }
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        throw new RuntimeException('databas');
    }
    if (jeab_hs_internal_idem_driver($pdo) === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS hs_internal_idem (
                idem_key VARCHAR(191) NOT NULL,
                conversation_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                claim CHAR(16) NOT NULL,
                PRIMARY KEY (idem_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } else {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS hs_internal_idem (
                idem_key TEXT PRIMARY KEY,
                conversation_id INTEGER NOT NULL,
                created_at TEXT NOT NULL,
                claim TEXT NOT NULL
            )'
        );
    }
    $done[$id] = true;
}

function jeab_hs_internal_idem_key(mixed $raw): string
{
    if (is_array($raw) || is_object($raw) || $raw === null) {
        return '';
    }
    $key = trim((string) $raw);
    $key = preg_replace('/[\x00-\x1F\x7F]/', '', $key) ?? '';
    $key = trim($key);
    if ($key === '') {
        return '';
    }
    $len = function_exists('mb_strlen') ? mb_strlen($key, 'UTF-8') : strlen($key);

    return $len > 128 ? '' : $key;
}

/**
 * @return array<string, mixed>|null
 */
function jeab_hs_internal_idem_read(PDO $pdo, string $key): ?array
{
    $stmt = $pdo->prepare('SELECT conversation_id, created_at, claim FROM hs_internal_idem WHERE idem_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * @return array{state:string, id:int}
 */
function jeab_hs_internal_idem_reserve(PDO $pdo, string $key): array
{
    jeab_hs_internal_idem_install($pdo);
    $run = static function (PDO $pdo) use ($key): array {
        $now = gmdate('Y-m-d H:i:s');
        $stale = gmdate('Y-m-d H:i:s', time() - 120);
        $claim = bin2hex(random_bytes(8));
        $insert = jeab_hs_internal_idem_driver($pdo) === 'mysql'
            ? 'INSERT IGNORE INTO hs_internal_idem (idem_key, conversation_id, created_at, claim) VALUES (?, 0, ?, ?)'
            : 'INSERT OR IGNORE INTO hs_internal_idem (idem_key, conversation_id, created_at, claim) VALUES (?, 0, ?, ?)';
        $pdo->prepare($insert)->execute([$key, $now, $claim]);
        $row = jeab_hs_internal_idem_read($pdo, $key);
        if ($row === null) {
            return ['state' => 'busy', 'id' => 0];
        }
        $id = (int) ($row['conversation_id'] ?? 0);
        if ($id > 0) {
            return ['state' => 'replay', 'id' => $id];
        }
        if ((string) ($row['claim'] ?? '') === $claim) {
            return ['state' => 'go', 'id' => 0];
        }
        $created = (string) ($row['created_at'] ?? '');
        if ($created !== '' && strcmp($created, $stale) >= 0) {
            return ['state' => 'busy', 'id' => 0];
        }
        $upd = $pdo->prepare(
            'UPDATE hs_internal_idem SET created_at = ?, claim = ? WHERE idem_key = ? AND conversation_id = 0 AND claim = ?'
        );
        $upd->execute([$now, $claim, $key, (string) ($row['claim'] ?? '')]);
        $again = jeab_hs_internal_idem_read($pdo, $key);
        $againId = (int) ($again['conversation_id'] ?? 0);
        if ($againId > 0) {
            return ['state' => 'replay', 'id' => $againId];
        }

        return (string) ($again['claim'] ?? '') === $claim
            ? ['state' => 'go', 'id' => 0]
            : ['state' => 'busy', 'id' => 0];
    };
    if (function_exists('jeab_pdo_transaction')) {
        $out = jeab_pdo_transaction($pdo, $run);

        return is_array($out) ? $out : ['state' => 'busy', 'id' => 0];
    }

    return $run($pdo);
}

function jeab_hs_internal_idem_finish(PDO $pdo, string $key, int $id): void
{
    if ($id < 1) {
        return;
    }
    $stmt = $pdo->prepare('UPDATE hs_internal_idem SET conversation_id = ? WHERE idem_key = ? AND conversation_id = 0');
    $stmt->execute([$id, $key]);
}

function jeab_hs_internal_idem_release(PDO $pdo, string $key): void
{
    $stmt = $pdo->prepare('DELETE FROM hs_internal_idem WHERE idem_key = ? AND conversation_id = 0');
    $stmt->execute([$key]);
}

/**
 * @return array<string, mixed>
 */
function jeab_hs_internal_replay(int $id): array
{
    return [
        'ok' => true,
        'action' => 'hs.create_internal',
        'dry' => 0,
        'sent' => 0,
        'would_send' => false,
        'imported' => true,
        'conversation_status' => 'active',
        'thread' => 'note',
        'written' => 0,
        'replayed' => 1,
        'id' => $id,
        'status' => 200,
    ];
}

/**
 * Bara GET mot ärendelistan. Ingen kropp, ingen sändväg, och poolen släpps inte.
 *
 * @param array<string, scalar|null> $query
 * @return array{code:int, error:string, headers:list<string>, body:string, id:int}
 */
function jeab_malmo_hs_http_get(string $path, array $query = []): array
{
    $empty = ['code' => 0, 'error' => 'hs_las_nekad', 'headers' => [], 'body' => '', 'id' => 0];
    $path = ltrim($path, '/');
    if ($path !== 'conversations') {
        return $empty;
    }
    if (isset($GLOBALS['_JEAB_MALMO_HS_HTTP_GET']) && is_callable($GLOBALS['_JEAB_MALMO_HS_HTTP_GET'])) {
        $res = ($GLOBALS['_JEAB_MALMO_HS_HTTP_GET'])($path, $query);
        $res = is_array($res) ? $res : [];

        return [
            'code' => (int) ($res['code'] ?? 0),
            'error' => (string) ($res['error'] ?? ''),
            'headers' => array_values(array_map('strval', (array) ($res['headers'] ?? []))),
            'body' => (string) ($res['body'] ?? ''),
            'id' => 0,
        ];
    }
    $token = function_exists('jeab_hs_import_token') ? jeab_hs_import_token() : null;
    if (!is_string($token) || $token === '') {
        return ['code' => 0, 'error' => 'hs_nyckel', 'headers' => [], 'body' => '', 'id' => 0];
    }
    $url = 'https://api.helpscout.net/v2/' . $path;
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }
    if ((string) parse_url($url, PHP_URL_HOST) !== 'api.helpscout.net') {
        return ['code' => 0, 'error' => 'hs_vard', 'headers' => [], 'body' => '', 'id' => 0];
    }
    if (function_exists('jeab_demo_outbound') && jeab_demo_outbound('helpscout', $url)) {
        return ['code' => 200, 'error' => '', 'headers' => [], 'body' => '{}', 'id' => 0, 'demo' => true];
    }
    $headers = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPGET => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
            unset($ch);
            $headers[] = $line;

            return strlen($line);
        },
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ],
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $body = is_string($raw) ? $raw : '';
    if (defined('JEAB_HS_IMPORT_MAX_BYTES') && strlen($body) > JEAB_HS_IMPORT_MAX_BYTES) {
        return ['code' => 413, 'error' => 'hs_svar', 'headers' => $headers, 'body' => '', 'id' => 0];
    }

    return [
        'code' => $code,
        'error' => ($code >= 200 && $code < 300) ? '' : 'hs_svar',
        'headers' => $headers,
        'body' => $body,
        'id' => 0,
    ];
}

function jeab_hs_search_mailbox(mixed $raw): ?int
{
    if (is_int($raw) || (is_string($raw) && preg_match('/^\d+$/', trim($raw)) === 1)) {
        $id = (int) $raw;

        return $id > 0 ? $id : null;
    }
    if (!is_string($raw)) {
        return null;
    }
    $office = function_exists('jeab_normalize_company') ? jeab_normalize_company($raw) : null;
    if ($office !== 'jarfalla' && $office !== 'malmo') {
        return null;
    }
    if (!function_exists('jeab_helpscout_mailbox_id')) {
        return null;
    }
    $id = jeab_helpscout_mailbox_id($office);

    return $id !== null && $id > 0 ? $id : null;
}

function jeab_hs_search_text(mixed $raw): string
{
    if (is_array($raw) || is_object($raw) || $raw === null) {
        return '';
    }
    $text = trim((string) $raw);
    $text = preg_replace('/[\x00-\x1F\x7F]/', '', $text) ?? '';

    return trim($text);
}

/**
 * @return list<array{id:int, subject:string, status:string, customer_email:string, createdAt:string}>
 */
function jeab_hs_search_rows(string $body): array
{
    $json = json_decode($body, true);
    if (!is_array($json)) {
        return [];
    }
    $rows = $json['_embedded']['conversations'] ?? null;
    if (!is_array($rows)) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $customer = $row['primaryCustomer'] ?? $row['customer'] ?? [];
        $email = is_array($customer) ? trim((string) ($customer['email'] ?? '')) : '';
        $out[] = [
            'id' => $id,
            'subject' => (string) ($row['subject'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'customer_email' => $email,
            'createdAt' => (string) ($row['createdAt'] ?? ''),
        ];
    }

    return $out;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_hs_search_dispatch(array $input): array
{
    $fail = static function (string $error, int $status): array {
        return [
            'ok' => false,
            'error' => $error,
            'action' => 'hs.search',
            'read_only' => true,
            'sent' => 0,
            'would_send' => false,
            'status' => $status,
        ];
    };
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return $fail('Fel token.', 401);
    }
    $mailbox = jeab_hs_search_mailbox($input['mailbox'] ?? null);
    if ($mailbox === null) {
        return $fail('ange mailbox', 422);
    }
    $query = jeab_hs_search_text($input['query'] ?? null);
    if ($query === '') {
        return $fail('ange sökning', 422);
    }
    $queryLen = function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') : strlen($query);
    if ($queryLen > 200) {
        return $fail('sökningen är för lång', 422);
    }
    if (!function_exists('jeab_malmo_hs_http_get')) {
        return $fail('hs_svar', 502);
    }
    $seen = [];
    $items = [];
    foreach (['all', 'spam'] as $hsStatus) {
        $res = jeab_malmo_hs_http_get('conversations', [
            'mailbox' => $mailbox,
            'status' => $hsStatus,
            'query' => $query,
            'sortField' => 'createdAt',
            'sortOrder' => 'desc',
        ]);
        $code = (int) ($res['code'] ?? 0);
        if ($code < 200 || $code >= 300) {
            $error = (string) ($res['error'] ?? '');

            return $fail($error !== '' ? $error : 'hs_svar', 502);
        }
        foreach (jeab_hs_search_rows((string) ($res['body'] ?? '')) as $row) {
            if (isset($seen[$row['id']])) {
                continue;
            }
            $seen[$row['id']] = true;
            $items[] = $row;
            if (count($items) >= 50) {
                break 2;
            }
        }
    }

    return [
        'ok' => true,
        'action' => 'hs.search',
        'read_only' => true,
        'sent' => 0,
        'would_send' => false,
        'mailbox_id' => $mailbox,
        'count' => count($items),
        'conversations' => $items,
        'status' => 200,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_hs_create_internal_dispatch(array $input): array
{
    $dry = jeab_hs_internal_wants_dry($input);
    $fail = static function (string $error, int $status) use ($dry): array {
        return [
            'ok' => false,
            'error' => $error,
            'action' => 'hs.create_internal',
            'dry' => $dry ? 1 : 0,
            'sent' => 0,
            'would_send' => false,
            'imported' => true,
            'status' => $status,
        ];
    };
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return $fail('Fel token.', 401);
    }
    $idem = '';
    $idemPdo = null;
    if (!$dry) {
        $rawIdem = $input['idem'] ?? null;
        if (is_string($rawIdem)) {
            $trimmedIdem = trim($rawIdem);
            $idemLen = function_exists('mb_strlen') ? mb_strlen($trimmedIdem, 'UTF-8') : strlen($trimmedIdem);
            if ($idemLen > 128) {
                return $fail('idem är för lång', 422);
            }
        }
        $idem = jeab_hs_internal_idem_key($rawIdem);
        if ($idem === '') {
            return $fail('ange idem', 422);
        }
        $idemPdo = jeab_hs_internal_idem_pdo();
        if (!$idemPdo instanceof PDO) {
            return $fail('databas', 503);
        }
        jeab_hs_internal_idem_install($idemPdo);
        $have = jeab_hs_internal_idem_read($idemPdo, $idem);
        if (is_array($have) && (int) ($have['conversation_id'] ?? 0) > 0) {
            return jeab_hs_internal_replay((int) $have['conversation_id']);
        }
    }
    $userGiven = $input['user'] ?? $input['assignee'] ?? null;
    if (is_array($userGiven)) {
        return $fail('ange användare', 422);
    }
    $bodyRaw = $input['body'] ?? $input['note'] ?? $input['text'] ?? null;
    if (is_array($bodyRaw)) {
        return $fail('ange text', 422);
    }
    $body = trim((string) $bodyRaw);
    if ($body === '') {
        return $fail('ange text', 422);
    }
    if (function_exists('mb_strlen') && mb_strlen($body, 'UTF-8') > 8000) {
        return $fail('texten är för lång', 422);
    }
    $customer = jeab_hs_internal_customer($input);
    if (empty($customer['ok'])) {
        return $fail((string) ($customer['error'] ?? 'ogiltig e-post'), 422);
    }
    $officeRaw = $input['office'] ?? 'jarfalla';
    if (is_array($officeRaw)) {
        return $fail('kontoret ska vara jarfalla eller malmo', 422);
    }
    $office = function_exists('jeab_normalize_company') ? jeab_normalize_company($officeRaw) : null;
    if ($office !== 'jarfalla' && $office !== 'malmo') {
        return $fail('kontoret ska vara jarfalla eller malmo', 422);
    }
    $mailbox = function_exists('jeab_helpscout_mailbox_id') ? jeab_helpscout_mailbox_id($office) : null;
    if ($mailbox === null || $mailbox < 1) {
        return $fail('Help Scout har ingen brevlåda för kontoret', 422);
    }
    $subjectRaw = $input['subject'] ?? 'Intern anteckning';
    if (is_array($subjectRaw)) {
        return $fail('ämnet ska vara text', 422);
    }
    $subject = trim((string) $subjectRaw);
    if ($subject === '') {
        $subject = 'Intern anteckning';
    }
    $user = jeab_hs_internal_resolve_user((string) $userGiven);
    if (empty($user['ok'])) {
        $error = (string) ($user['error'] ?? 'hs_svar');
        $status = $error === 'hs_svar' ? 502 : 422;

        return $fail($error, $status);
    }
    $payload = jeab_hs_internal_payload($mailbox, (int) $user['id'], $customer, $body, $subject);
    $masked = function_exists('jeab_lead_mask_email') ? jeab_lead_mask_email((string) $customer['email']) : '';
    $out = [
        'ok' => true,
        'action' => 'hs.create_internal',
        'dry' => $dry ? 1 : 0,
        'sent' => 0,
        'would_send' => false,
        'imported' => true,
        'conversation_status' => 'active',
        'thread' => 'note',
        'written' => 0,
        'id' => 0,
        'assignee' => (string) ($user['label'] ?? ''),
        'assignee_id' => (int) $user['id'],
        'customer_email' => $masked,
        'mailbox_id' => $mailbox,
        'office' => $office,
        'subject' => jeab_hs_internal_scrub($subject),
        'body' => jeab_hs_internal_scrub($body),
        'status' => 200,
    ];
    if ($dry) {
        return $out;
    }
    if ($idem === '' || !$idemPdo instanceof PDO) {
        return $fail('ange idem', 422);
    }
    $reserved = jeab_hs_internal_idem_reserve($idemPdo, $idem);
    if (($reserved['state'] ?? '') === 'replay') {
        return jeab_hs_internal_replay((int) ($reserved['id'] ?? 0));
    }
    if (($reserved['state'] ?? '') !== 'go') {
        return $fail('idem pågår', 409);
    }
    try {
        $posted = jeab_hs_internal_post($payload);
    } catch (Throwable $e) {
        jeab_hs_internal_idem_release($idemPdo, $idem);
        throw $e;
    }
    if ((int) ($posted['code'] ?? 0) < 200 || (int) ($posted['code'] ?? 0) >= 300) {
        jeab_hs_internal_idem_release($idemPdo, $idem);

        return $fail((string) ($posted['error'] ?? 'hs_svar') !== '' ? (string) $posted['error'] : 'hs_svar', 502);
    }
    $out['written'] = 1;
    $out['id'] = jeab_hs_internal_conversation_id($posted);
    if ((int) $out['id'] > 0) {
        jeab_hs_internal_idem_finish($idemPdo, $idem, (int) $out['id']);
    } else {
        jeab_hs_internal_idem_release($idemPdo, $idem);
    }

    return $out;
}
