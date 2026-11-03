<?php

declare(strict_types=1);

/**
 * Underhållsflagga och fatal-logg. Ingen hemlighet, ingen shield.
 *
 * Deploy skriver private/deploying.flag (unix-tid). Tom fil = av.
 * Yngre än 3 minuter = 503. Äldre än så ignoreras, så en avbruten
 * deploy låser inte sajten. Flaggan raderas aldrig här.
 */

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    $jeabDeployGuardScript = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $jeabDeployGuardSelf = realpath(__FILE__);
    if ($jeabDeployGuardScript !== false && $jeabDeployGuardSelf !== false && $jeabDeployGuardScript === $jeabDeployGuardSelf) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo "Forbidden\n";
        exit;
    }
}

function jeab_deploy_flag_ttl(): int
{
    return 180;
}

/** @return list<string> */
function jeab_deploy_flag_paths(): array
{
    $root = defined('JEAB_ROOT') ? JEAB_ROOT : dirname(__DIR__);
    $paths = [$root . '/private/deploying.flag'];
    $sibling = dirname($root) . '/private/deploying.flag';
    if ($sibling !== $paths[0]) {
        $paths[] = $sibling;
    }

    return $paths;
}

function jeab_deploy_flag_is_fresh(string $path, int $now): bool
{
    clearstatcache(true, $path);
    if (!is_file($path)) {
        return false;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw)) {
        return false;
    }
    $text = trim($raw);
    if ($text === '' || preg_match('/^\d{1,12}$/', $text) !== 1) {
        return false;
    }
    $ts = (int) $text;
    if ($ts < 1000000000) {
        return false;
    }
    $age = $now - $ts;

    return $age >= -30 && $age < jeab_deploy_flag_ttl();
}

function jeab_deploy_request_is_filer(): bool
{
    if (!empty($GLOBALS['_JEAB_FILER'])) {
        return true;
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['SCRIPT_NAME'] ?? ''));

    return strtolower(basename($script)) === 'filer.php';
}

function jeab_deploy_guard_handles_request(): bool
{
    if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
        return true;
    }

    return isset($_SERVER['REQUEST_METHOD']) && trim((string) $_SERVER['REQUEST_METHOD']) !== '';
}

function jeab_deploy_maintenance_active(?int $now = null): bool
{
    if (!jeab_deploy_guard_handles_request() || jeab_deploy_request_is_filer()) {
        return false;
    }
    $now = $now ?? time();
    foreach (jeab_deploy_flag_paths() as $path) {
        if (jeab_deploy_flag_is_fresh($path, $now)) {
            return true;
        }
    }

    return false;
}

function jeab_deploy_maintenance_wants_json(): bool
{
    $script = strtolower(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['SCRIPT_FILENAME'] ?? '')));
    $uri = strtolower((string) ($_SERVER['REQUEST_URI'] ?? ''));
    foreach (['hook', 'omtago', 'kalkyl', '/api/'] as $needle) {
        if (str_contains($script, $needle) || str_contains($uri, $needle)) {
            return true;
        }
    }

    return false;
}

function jeab_deploy_maintenance_respond(): void
{
    if (!jeab_deploy_maintenance_active()) {
        return;
    }
    if (!headers_sent()) {
        http_response_code(503);
        header('Retry-After: 30');
        header('Cache-Control: no-store');
        if (jeab_deploy_maintenance_wants_json()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Underhåll. Försök igen.'], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo "Underhåll. Försök igen.\n";
        }
    }
    exit;
}

function jeab_fatal_redact(string $text): string
{
    $text = preg_replace('/(?<!\d)(?:\d{6}|\d{8})[-+]?\d{4}(?!\d)/', '[pnr]', $text) ?? $text;
    $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[mejl]', $text) ?? $text;
    $text = preg_replace('/(Bearer\s+)\S+/i', '$1[secret]', $text) ?? $text;
    $text = preg_replace('/((?:password|passwd|secret|token|api[_-]?key|authorization|campaign)\s*[=:]\s*)\S+/i', '$1[secret]', $text) ?? $text;
    $text = preg_replace('/\b[A-Za-z0-9+\/=_-]{24,}\b/', '[secret]', $text) ?? $text;
    if (strlen($text) > 500) {
        $text = substr($text, 0, 500);
    }

    return $text;
}

function jeab_php_log_errors_on(): bool
{
    $value = strtolower(trim((string) ini_get('log_errors')));

    return $value !== '' && $value !== '0' && $value !== 'off' && $value !== 'false' && $value !== 'no';
}

function jeab_log_php_fatal(): void
{
    $err = error_get_last();
    if (!is_array($err)) {
        return;
    }
    $type = (int) ($err['type'] ?? 0);
    if (!in_array($type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }
    // PHP har redan skrivit fatalen när log_errors är på. Varningar och
    // notices ska fortsätta dit. En andra rad här blir en dubblett.
    if (jeab_php_log_errors_on()) {
        return;
    }
    $message = jeab_fatal_redact((string) ($err['message'] ?? ''));
    $file = (string) ($err['file'] ?? '');
    $root = defined('JEAB_ROOT') ? JEAB_ROOT : dirname(__DIR__);
    if ($file !== '' && str_starts_with($file, $root)) {
        $file = ltrim(substr($file, strlen($root)), '/');
    } else {
        $file = basename(str_replace('\\', '/', $file));
    }
    $line = (int) ($err['line'] ?? 0);
    $text = 'PHP fatal: ' . $message;
    if ($file !== '') {
        $text .= ' @ ' . $file . ':' . $line;
    }
    @error_log($text);
}

function jeab_deploy_guard(): void
{
    static $armed = false;
    if ($armed) {
        return;
    }
    $armed = true;
    register_shutdown_function('jeab_log_php_fatal');
    jeab_deploy_maintenance_respond();
}

/**
 * deploy.flag_status: bara om root/private/deploying.flag finns och dess mtime.
 * Filens innehåll läses inte och lämnar aldrig svaret.
 *
 * @return array{ok:bool,exists:bool,mtime:?int,mtime_iso:?string}
 */
function jeab_deploy_flag_status(?string $path = null): array
{
    $path ??= jeab_deploy_flag_paths()[0];
    clearstatcache(true, $path);
    $exists = is_file($path);
    $mtime = $exists ? @filemtime($path) : false;
    $mtime = is_int($mtime) ? $mtime : null;

    return [
        'ok' => true,
        'exists' => $exists,
        'mtime' => $mtime,
        'mtime_iso' => $mtime !== null ? gmdate('c', $mtime) : null,
    ];
}

/**
 * Hook-ingång. Samma tokenkontroll som övriga hooks (jeab_inkorg_hook_authorized).
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_deploy_flag_status_dispatch(array $input = []): array
{
    unset($input);
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }

    return jeab_deploy_flag_status() + ['status' => 200];
}
