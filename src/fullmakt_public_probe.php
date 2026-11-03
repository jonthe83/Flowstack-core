<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * fullmakt.public_probe hämtar varje fullmakt som om kunden öppnade länken.
 * GET mot https://flowstack.example.test/fullmakt/s/{token}, utan kakor, följer redirect.
 * Svaret har id (sha256, 16 hex), namn, order_id, source sql/json,
 * statuskedja, final_status, renders och sign_form.
 * Token, personnummer och URL lämnar aldrig svaret. Inget skrivs.
 */

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_fullmakt_public_probe_dispatch(array $input): array
{
    unset($input);
    $fail = static function (string $error, int $status): array {
        return [
            'ok' => false,
            'error' => $error,
            'action' => 'fullmakt.public_probe',
            'read_only' => true,
            'sent' => 0,
            'would_send' => false,
            'status' => $status,
        ];
    };
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return $fail('Fel token.', 401);
    }
    jeab_fullmakt_public_probe_load();
    $secrets = [];
    $docs = [];
    $seen = [];
    foreach (jeab_fullmakt_public_probe_sql_rows() as $row) {
        $token = $row['token'];
        $seen[$token] = true;
        foreach (jeab_fullmakt_public_probe_secrets($row['doc'], $token) as $secret) {
            $secrets[] = $secret;
        }
        $docs[] = jeab_fullmakt_public_probe_one($token, $row['doc'], 'sql');
    }
    foreach (jeab_fullmakt_public_probe_json_docs() as $token => $doc) {
        if (isset($seen[$token])) {
            continue;
        }
        foreach (jeab_fullmakt_public_probe_secrets($doc, $token) as $secret) {
            $secrets[] = $secret;
        }
        $docs[] = jeab_fullmakt_public_probe_one($token, $doc, 'json');
    }

    return jeab_fullmakt_public_probe_seal([
        'ok' => true,
        'action' => 'fullmakt.public_probe',
        'read_only' => true,
        'sent' => 0,
        'would_send' => false,
        'count' => count($docs),
        'docs' => $docs,
        'status' => 200,
    ], $secrets);
}

function jeab_fullmakt_public_probe_load(): void
{
    $root = dirname(__DIR__);
    $boot = $root . '/fullmakt/lib/bootstrap.php';
    if (!defined('FULLMAKT_DATA') && is_file($boot)) {
        require_once $boot;
    }
    $status = __DIR__ . '/fullmakt_docs_status.php';
    if (is_file($status)) {
        require_once $status;
    }
}

/**
 * @return list<array{token:string,doc:array<string,mixed>}>
 */
function jeab_fullmakt_public_probe_sql_rows(): array
{
    if (!function_exists('jeab_data_pdo') || !function_exists('jeab_migrate_ident')) {
        return [];
    }
    $pdo = jeab_data_pdo();
    if (!$pdo instanceof PDO) {
        return [];
    }
    try {
        $stmt = $pdo->query(
            'SELECT '
            . jeab_migrate_ident($pdo, 'token') . ', '
            . jeab_migrate_ident($pdo, 'payload')
            . ' FROM ' . jeab_migrate_ident($pdo, 'fullmakt_docs')
            . ' ORDER BY ' . jeab_migrate_ident($pdo, 'created_at')
        );
    } catch (Throwable) {
        return [];
    }
    if (!$stmt instanceof PDOStatement) {
        return [];
    }
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sqlRow) {
        if (!is_array($sqlRow)) {
            continue;
        }
        $token = jeab_fullmakt_public_probe_token((string) ($sqlRow['token'] ?? ''));
        if ($token === '') {
            continue;
        }
        $payload = json_decode((string) ($sqlRow['payload'] ?? ''), true);
        $out[] = [
            'token' => $token,
            'doc' => is_array($payload) ? $payload : [],
        ];
    }

    return $out;
}

/**
 * @return array<string, array<string, mixed>>
 */
function jeab_fullmakt_public_probe_json_docs(): array
{
    $dirs = [];
    if (defined('FULLMAKT_PENDING')) {
        $dirs[] = FULLMAKT_PENDING;
    }
    if (defined('FULLMAKT_SIGNED')) {
        $dirs[] = FULLMAKT_SIGNED;
    }
    if (function_exists('fullmakt_legacy_dirs')) {
        foreach (fullmakt_legacy_dirs() as $root) {
            $dirs[] = $root . '/pending';
            $dirs[] = $root . '/signed';
        }
    }
    $docs = [];
    foreach ($dirs as $dir) {
        if (!is_string($dir) || !is_dir($dir)) {
            continue;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if (!is_string($name) || preg_match('/^([A-Za-z0-9_-]+)\.json$/', $name, $match) !== 1) {
                continue;
            }
            $token = jeab_fullmakt_public_probe_token($match[1]);
            if ($token === '' || isset($docs[$token])) {
                continue;
            }
            $raw = @file_get_contents($dir . '/' . $name);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            $docs[$token] = is_array($data) ? $data : [];
        }
    }

    return $docs;
}

function jeab_fullmakt_public_probe_token(string $token): string
{
    return preg_replace('/[^A-Za-z0-9_-]/', '', trim($token)) ?? '';
}

function jeab_fullmakt_public_probe_url(string $token): string
{
    return 'https://flowstack.example.test/fullmakt/s/' . rawurlencode(jeab_fullmakt_public_probe_token($token));
}

/**
 * Inga kakor. Bara HTTPS, även efter redirect.
 *
 * @return array<int, mixed>
 */
function jeab_fullmakt_public_probe_curl_options(): array
{
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPGET => true,
        CURLOPT_USERAGENT => 'JEAB-fullmakt-probe',
    ];
    if (defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
    }

    return $options;
}

/**
 * @param array<string, mixed> $doc
 * @return array<string, mixed>
 */
function jeab_fullmakt_public_probe_one(string $token, array $doc, string $source): array
{
    $name = function_exists('jeab_fullmakt_docs_status_name')
        ? jeab_fullmakt_docs_status_name($doc)
        : trim((string) ($doc['namn'] ?? ''));
    $orderId = function_exists('jeab_fullmakt_docs_status_order_id')
        ? jeab_fullmakt_docs_status_order_id($doc)
        : '';
    $fetched = jeab_fullmakt_public_probe_fetch($token);
    $body = (string) ($fetched['body'] ?? '');
    $row = [
        'id' => substr(hash('sha256', $token), 0, 16),
        'namn' => $name,
        'source' => $source === 'json' ? 'json' : 'sql',
        'statuskedja' => array_values(array_map('intval', (array) ($fetched['statuses'] ?? []))),
        'final_status' => (int) ($fetched['final_status'] ?? 0),
        'renders' => jeab_fullmakt_public_probe_renders($body, $name),
        'sign_form' => jeab_fullmakt_public_probe_sign_form($body),
    ];
    if ($orderId !== '') {
        $row['order_id'] = $orderId;
    }

    return $row;
}

function jeab_fullmakt_public_probe_renders(string $body, string $name): bool
{
    $name = trim($name);
    if ($name === '' || !str_contains($body, '<')) {
        return false;
    }
    if (str_contains($body, $name)) {
        return true;
    }
    $escaped = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return $escaped !== $name && str_contains($body, $escaped);
}

function jeab_fullmakt_public_probe_sign_form(string $body): bool
{
    return str_contains($body, 'id="sign-form"')
        && (str_contains($body, 'id="skicka"') || str_contains($body, 'type="submit"'));
}

/**
 * @return array{statuses:list<int>,final_status:int,body:string}
 */
function jeab_fullmakt_public_probe_fetch(string $token): array
{
    $empty = ['statuses' => [], 'final_status' => 0, 'body' => ''];
    $token = jeab_fullmakt_public_probe_token($token);
    if ($token === '') {
        return $empty;
    }
    $override = $GLOBALS['_JEAB_FULLMAKT_PUBLIC_PROBE_FETCH'] ?? null;
    if (is_callable($override)) {
        $got = $override($token);
        if (!is_array($got)) {
            return $empty;
        }
        $statuses = [];
        foreach ((array) ($got['statuses'] ?? []) as $code) {
            $statuses[] = (int) $code;
        }
        $final = (int) ($got['final_status'] ?? 0);
        if ($final === 0 && $statuses !== []) {
            $final = $statuses[count($statuses) - 1];
        }

        return [
            'statuses' => $statuses,
            'final_status' => $final,
            'body' => (string) ($got['body'] ?? ''),
        ];
    }
    if (!function_exists('curl_init')) {
        return $empty;
    }
    if (function_exists('jeab_demo_outbound') && jeab_demo_outbound('fullmakt', 'probe')) {
        return $empty;
    }
    $statuses = [];
    $handle = curl_init(jeab_fullmakt_public_probe_url($token));
    if ($handle === false) {
        return $empty;
    }
    $options = jeab_fullmakt_public_probe_curl_options();
    $options[CURLOPT_HEADERFUNCTION] = static function ($ch, string $header) use (&$statuses): int {
        unset($ch);
        if (preg_match('#\AHTTP/\d+(?:\.\d+)?\s+(\d{3})\b#', $header, $match) === 1) {
            $statuses[] = (int) $match[1];
        }

        return strlen($header);
    };
    curl_setopt_array($handle, $options);
    $body = curl_exec($handle);
    $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if (!is_string($body)) {
        $body = '';
    }
    if ($statuses === [] && $code > 0) {
        $statuses[] = $code;
    }
    $final = $statuses === [] ? $code : $statuses[count($statuses) - 1];

    return ['statuses' => $statuses, 'final_status' => $final, 'body' => $body];
}

/**
 * @param array<string, mixed> $doc
 * @return list<string>
 */
function jeab_fullmakt_public_probe_secrets(array $doc, string $token): array
{
    if (function_exists('jeab_fullmakt_docs_status_secrets')) {
        $secrets = jeab_fullmakt_docs_status_secrets($doc, $token);
    } else {
        $secrets = [];
    }
    $secrets[] = $token;
    $secrets[] = 'https://flowstack.example.test/fullmakt/s/' . $token;
    $secrets[] = '/fullmakt/s/' . $token;

    return $secrets;
}

/**
 * @param array<string, mixed> $body
 * @param list<string> $secrets
 * @return array<string, mixed>
 */
function jeab_fullmakt_public_probe_seal(array $body, array $secrets): array
{
    if (function_exists('jeab_fullmakt_docs_status_seal')) {
        $sealed = jeab_fullmakt_docs_status_seal($body, $secrets);

        return is_array($sealed) ? $sealed : $body;
    }

    return $body;
}
