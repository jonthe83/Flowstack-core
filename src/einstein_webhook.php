<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
function jeab_einstein_webhook_secret_path(): string
{
    return jeab_data_file('secrets/einstein-bug-webhook.json');
}

function jeab_einstein_webhook_host_blocked(string $url): bool
{
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
    if ($host === '') {
        return true;
    }
    $blocked = [
        'pastebin.com',
        'pastebin.pl',
        'hastebin.com',
        'dpaste.com',
        'dpaste.org',
        '0x0.st',
        'anonfiles.com',
        'file.io',
        'transfer.sh',
        'gist.github.com',
        'paste.ee',
        'paste.mozilla.org',
        'justpaste.it',
        'rentry.co',
        'rentry.org',
        'controlc.com',
        'webhook.site',
        'requestbin.com',
        'requestbin.net',
        'pipedream.net',
    ];
    foreach ($blocked as $bad) {
        if ($host === $bad || str_ends_with($host, '.' . $bad)) {
            return true;
        }
    }
    return false;
}

function jeab_webhook_secret_from_path(string $path): ?array
{
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }
    $url = trim((string) ($decoded['url'] ?? ''));
    $key = trim((string) ($decoded['key'] ?? ''));
    if ($url === '' || $key === '') {
        return null;
    }
    if (!preg_match('#^https://#i', $url)) {
        return null;
    }
    if (jeab_einstein_webhook_host_blocked($url)) {
        return null;
    }
    return ['url' => $url, 'key' => $key];
}

function jeab_einstein_webhook_secret(): ?array
{
    if (isset($GLOBALS['_JEAB_EINSTEIN_SECRET']) && is_array($GLOBALS['_JEAB_EINSTEIN_SECRET'])) {
        $url = trim((string) ($GLOBALS['_JEAB_EINSTEIN_SECRET']['url'] ?? ''));
        $key = trim((string) ($GLOBALS['_JEAB_EINSTEIN_SECRET']['key'] ?? ''));
        if ($url !== '' && $key !== '') {
            return ['url' => $url, 'key' => $key];
        }
    }
    return jeab_webhook_secret_from_path(jeab_einstein_webhook_secret_path());
}

function jeab_einstein_inbound_authorized(): bool
{
    $secret = jeab_einstein_webhook_secret();
    if ($secret === null) {
        return false;
    }
    $key = trim((string) ($secret['key'] ?? ''));
    return $key !== '' && function_exists('jeab_incoming_token_ok') && jeab_incoming_token_ok($key);
}

function jeab_einstein_inbound(array $input): array
{
    if (function_exists('jeab_fellogg_open_asks_persist_sweep')) {
        jeab_fellogg_open_asks_persist_sweep();
    }
    $type = (string) ($input['type'] ?? '');
    if ($type === 'fellogg_resolve') {
        $fp = trim((string) ($input['fingerprint'] ?? ''));
        if ($fp === '' || !function_exists('jeab_log_app_error_resolved_put')) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $row = jeab_log_app_error_resolved_put($fp, 'einstein', (string) ($input['note'] ?? ''));
        $at = is_array($row) ? (int) ($row['at'] ?? 0) : 0;
        if ($at <= 0 && function_exists('jeab_fellogg_now')) {
            $at = jeab_fellogg_now();
        }
        return ['ok' => true, 'fingerprint' => $fp, 'resolved_at' => $at];
    }
    if ($type === 'fellogg_ask_ack') {
        $id = trim((string) ($input['id'] ?? ''));
        $ask = ($id !== '' && function_exists('jeab_fellogg_open_ask_ack')) ? jeab_fellogg_open_ask_ack($id) : null;
        if (!is_array($ask)) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        return ['ok' => true, 'id' => $id, 'status' => 'delivered'];
    }
    return ['ok' => false, 'error' => 'not_found'];
}

/**
 * @return array{ok:bool,json:?array,http:int}
 */
function jeab_einstein_webhook_exchange(array $secret, array $payload, int $timeoutSeconds = 5): array
{
    $url = (string) ($secret['url'] ?? '');
    $key = (string) ($secret['key'] ?? '');
    $empty = ['ok' => false, 'json' => null, 'http' => 0];
    if ($url === '' || $key === '' || jeab_einstein_webhook_host_blocked($url) || !preg_match('#^https://#i', $url)) {
        return $empty;
    }
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        return $empty;
    }
    if (getenv('JEAB_BUG_WEBHOOK_CAPTURE')) {
        $GLOBALS['_JEAB_BUG_WEBHOOKS'][] = $payload;
        $scripted = $GLOBALS['_JEAB_BUG_WEBHOOK_RESPONSE'] ?? null;
        return ['ok' => true, 'json' => is_array($scripted) ? $scripted : null, 'http' => 200];
    }
    if (function_exists('jeab_url_ssrf_safe') && !jeab_url_ssrf_safe($url)) {
        return $empty;
    }
    $timeoutSeconds = max(1, min(60, $timeoutSeconds));
    $headers = [
        'Content-Type: application/json; charset=utf-8',
        'Accept: application/json',
        'Authorization: Bearer ' . $key,
    ];
    $http = [
        'method' => 'POST',
        'header' => implode("\r\n", $headers) . "\r\n",
        'content' => $body,
        'timeout' => $timeoutSeconds,
        'ignore_errors' => true,
    ];
    if (function_exists('jeab_http_no_follow')) {
        $http = jeab_http_no_follow($http);
    } else {
        $http['follow_location'] = 0;
        $http['max_redirects'] = 0;
    }
    $context = stream_context_create(['http' => $http]);
    if (function_exists('jeab_demo_outbound') && jeab_demo_outbound('einstein', $url)) {
        return ['ok' => true, 'json' => ['demo' => true], 'http' => 200, 'demo' => true];
    }
    try {
        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            return $empty;
        }
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\b#', (string) $http_response_header[0], $m) === 1) {
            $status = (int) $m[1];
        }
        $decoded = is_string($result) ? json_decode($result, true) : null;
        return [
            'ok' => $status >= 200 && $status < 300,
            'json' => is_array($decoded) ? $decoded : null,
            'http' => $status,
        ];
    } catch (Throwable) {
        return $empty;
    }
}

function jeab_einstein_webhook_post(array $secret, array $payload): bool
{
    return jeab_einstein_webhook_exchange($secret, $payload)['ok'] === true;
}
