<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * IP-lås för TinyFM. Den skarpa listan är data/.private/tinyfm_allowlist.txt
 * på servern (inte i git, deploy rör den inte).
 * och läses vid varje anrop, så en rad på servern räcker — ingen koddeploy.
 * Saknas filen, eller går den inte att läsa, blir svaret 403.
 *
 * Klient-IP: REMOTE_ADDR när det är en publik adress. X-Forwarded-For
 * ignoreras då, eftersom klienten kan sätta den själv. Bara när
 * REMOTE_ADDR är privat eller reserverad (webbhotellets proxy på samma nät)
 * används högra publika hoppet i X-Forwarded-For — det hoppet som
 * närmaste proxy lagt till. Saknas en publik klientadress blir det 403.
 */

const JEAB_TINYFM_ALLOWLIST_MAX_BYTES = 262144;

function jeab_tinyfm_allowlist_path(): string
{
    if (!empty($GLOBALS['_JEAB_TINYFM_ALLOWLIST']) && is_string($GLOBALS['_JEAB_TINYFM_ALLOWLIST'])) {
        return $GLOBALS['_JEAB_TINYFM_ALLOWLIST'];
    }
    if (!empty($GLOBALS['_JEAB_DATA_DIR']) && is_string($GLOBALS['_JEAB_DATA_DIR'])) {
        return rtrim($GLOBALS['_JEAB_DATA_DIR'], '/') . '/tinyfm_allowlist.txt';
    }
    $root = defined('JEAB_ROOT') ? JEAB_ROOT : dirname(__DIR__);
    return $root . '/data/.private/tinyfm_allowlist.txt';
}

function jeab_tinyfm_normalize_ip(string $ip): string
{
    $ip = trim($ip);
    if ($ip === '') {
        return '';
    }
    if ($ip[0] === '[' && str_contains($ip, ']')) {
        $end = strpos($ip, ']');
        $ip = $end === false ? $ip : substr($ip, 1, $end - 1);
    } elseif (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $ip, $match) === 1) {
        $ip = $match[1];
    }
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return '';
    }
    if (strlen($bin) === 16 && substr($bin, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
        $mapped = inet_ntop(substr($bin, 12));
        return $mapped === false ? '' : $mapped;
    }
    $text = inet_ntop($bin);
    return $text === false ? '' : $text;
}

function jeab_tinyfm_is_peer_proxy(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) === false;
}

function jeab_tinyfm_forwarded_client(string $header): string
{
    $parts = preg_split('/\s*,\s*/', $header) ?: [];
    for ($i = count($parts) - 1; $i >= 0; $i--) {
        $hop = jeab_tinyfm_normalize_ip($parts[$i]);
        if ($hop === '' || jeab_tinyfm_is_peer_proxy($hop)) {
            continue;
        }
        return $hop;
    }
    return '';
}

function jeab_tinyfm_client_ip(?array $server = null): string
{
    $server ??= $_SERVER;
    $remote = jeab_tinyfm_normalize_ip((string) ($server['REMOTE_ADDR'] ?? ''));
    if ($remote === '') {
        return '';
    }
    if (!jeab_tinyfm_is_peer_proxy($remote)) {
        return $remote;
    }
    return jeab_tinyfm_forwarded_client((string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''));
}

function jeab_tinyfm_parse_cidr(string $token): ?array
{
    $token = trim($token);
    if ($token === '' || preg_match('/\s/', $token) === 1) {
        return null;
    }
    $bitsRaw = null;
    if (str_contains($token, '/')) {
        [$addr, $bitsRaw] = explode('/', $token, 2);
        if (preg_match('/^\d{1,3}$/', $bitsRaw) !== 1) {
            return null;
        }
    } else {
        $addr = $token;
    }
    $bin = jeab_tinyfm_ip_bytes($addr);
    if ($bin === null) {
        return null;
    }
    $max = strlen($bin) * 8;
    $bits = $bitsRaw === null ? $max : (int) $bitsRaw;
    if ($bits < 0 || $bits > $max) {
        return null;
    }
    return [$bin, $bits];
}

function jeab_tinyfm_ip_bytes(string $ip): ?string
{
    $norm = jeab_tinyfm_normalize_ip($ip);
    if ($norm === '') {
        return null;
    }
    $bin = @inet_pton($norm);
    return $bin === false ? null : $bin;
}

function jeab_tinyfm_bytes_in_cidr(string $ipBin, string $netBin, int $bits): bool
{
    if (strlen($ipBin) !== strlen($netBin)) {
        return false;
    }
    $full = intdiv($bits, 8);
    if ($full > 0 && substr($ipBin, 0, $full) !== substr($netBin, 0, $full)) {
        return false;
    }
    $rem = $bits % 8;
    if ($rem === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $rem)) & 0xFF;
    return (ord($ipBin[$full]) & $mask) === (ord($netBin[$full]) & $mask);
}

function jeab_tinyfm_ip_in_cidr(string $ip, string $cidr): bool
{
    $ipBin = jeab_tinyfm_ip_bytes($ip);
    $parsed = jeab_tinyfm_parse_cidr($cidr);
    if ($ipBin === null || $parsed === null) {
        return false;
    }
    return jeab_tinyfm_bytes_in_cidr($ipBin, $parsed[0], $parsed[1]);
}

function jeab_tinyfm_cidr_from_line(string $line): ?string
{
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        return null;
    }
    $token = trim(explode('#', $line, 2)[0]);
    return $token === '' ? null : $token;
}

function jeab_tinyfm_ip_allowed(string $ip, ?string $path = null): bool
{
    if (jeab_tinyfm_ip_bytes($ip) === null) {
        return false;
    }
    $path ??= jeab_tinyfm_allowlist_path();
    clearstatcache(true, $path);
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return false;
    }
    $size = filesize($path);
    if ($size === false || $size > JEAB_TINYFM_ALLOWLIST_MAX_BYTES) {
        return false;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw)) {
        return false;
    }
    foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
        $token = jeab_tinyfm_cidr_from_line($line);
        if ($token === null) {
            continue;
        }
        if (jeab_tinyfm_ip_in_cidr($ip, $token)) {
            return true;
        }
    }
    return false;
}

function jeab_tinyfm_deny(): void
{
    http_response_code(403);
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
    echo "Forbidden\n";
    exit;
}

function jeab_tinyfm_guard(): void
{
    if (!jeab_tinyfm_ip_allowed(jeab_tinyfm_client_ip())) {
        jeab_tinyfm_deny();
    }
}
