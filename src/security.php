<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/einstein_webhook.php';

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

function jeab_csrf_session_token(): string
{
    if (!empty($_SESSION['csrf']) && is_string($_SESSION['csrf'])) {
        return (string) $_SESSION['csrf'];
    }
    if (!empty($GLOBALS['_JEAB_SESSION_READONLY']) && function_exists('jeab_session_write_once')) {
        jeab_session_write_once(static function (): void {
            if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
            }
        });
        if (!empty($_SESSION['csrf']) && is_string($_SESSION['csrf'])) {
            return (string) $_SESSION['csrf'];
        }
    }
    if (session_status() !== PHP_SESSION_ACTIVE && empty($GLOBALS['_JEAB_SESSION_RELEASED']) && function_exists('jeab_session_resume')) {
        jeab_session_resume();
    }
    if (!empty($_SESSION['csrf']) && is_string($_SESSION['csrf'])) {
        return (string) $_SESSION['csrf'];
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (!isset($GLOBALS['_JEAB_CSRF'])) {
            $GLOBALS['_JEAB_CSRF'] = bin2hex(random_bytes(32));
        }
        return $GLOBALS['_JEAB_CSRF'];
    }
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return (string) $_SESSION['csrf'];
}

function jeab_csrf_auth_nonce(): string
{
    if (!empty($_SESSION['jeab_csrf_auth_nonce']) && is_string($_SESSION['jeab_csrf_auth_nonce'])) {
        return (string) $_SESSION['jeab_csrf_auth_nonce'];
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['jeab_csrf_auth_nonce'] = bin2hex(random_bytes(16));
        return (string) $_SESSION['jeab_csrf_auth_nonce'];
    }
    if (!empty($GLOBALS['_JEAB_SESSION_READONLY']) && function_exists('jeab_session_write_once')) {
        jeab_session_write_once(static function (): void {
            if (empty($_SESSION['jeab_csrf_auth_nonce']) || !is_string($_SESSION['jeab_csrf_auth_nonce'])) {
                $_SESSION['jeab_csrf_auth_nonce'] = bin2hex(random_bytes(16));
            }
        });
        if (!empty($_SESSION['jeab_csrf_auth_nonce']) && is_string($_SESSION['jeab_csrf_auth_nonce'])) {
            return (string) $_SESSION['jeab_csrf_auth_nonce'];
        }
    }
    if (empty($GLOBALS['_JEAB_CSRF_AUTH_NONCE']) || !is_string($GLOBALS['_JEAB_CSRF_AUTH_NONCE'])) {
        $GLOBALS['_JEAB_CSRF_AUTH_NONCE'] = bin2hex(random_bytes(16));
    }
    return (string) $GLOBALS['_JEAB_CSRF_AUTH_NONCE'];
}

function jeab_csrf_identity(): ?string
{
    if (function_exists('jeab_auth_user_email')) {
        $email = jeab_auth_user_email();
        if ($email) {
            return 'email:' . $email;
        }
    }
    if (function_exists('jeab_auth_ok') && jeab_auth_ok()) {
        return 'auth:' . jeab_csrf_auth_nonce();
    }
    return null;
}

function jeab_csrf_signed_token(?string $identity = null): ?string
{
    $identity = $identity ?? jeab_csrf_identity();
    if ($identity === null || !function_exists('jeab_auth_secret')) {
        return null;
    }
    $memo = $GLOBALS['_JEAB_CSRF_SIGNED'] ?? null;
    if (is_array($memo) && array_key_exists($identity, $memo)) {
        $cached = $memo[$identity];

        return is_string($cached) ? $cached : null;
    }
    $secret = jeab_auth_secret();
    if ($secret === '') {
        return null;
    }
    $token = hash_hmac('sha256', 'jeab-csrf-v1|' . $identity, $secret);
    if (!isset($GLOBALS['_JEAB_CSRF_SIGNED']) || !is_array($GLOBALS['_JEAB_CSRF_SIGNED'])) {
        $GLOBALS['_JEAB_CSRF_SIGNED'] = [];
    }
    $GLOBALS['_JEAB_CSRF_SIGNED'][$identity] = $token;

    return $token;
}

function jeab_csrf_token(): string
{
    return jeab_csrf_signed_token() ?? jeab_csrf_session_token();
}

/** Tyst förnyelse. Bara GET ?p=csrf. Ingen databas. */
function jeab_csrf_refresh_request(): bool
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'HEAD') {
        return false;
    }

    return (string) ($_GET['p'] ?? '') === 'csrf';
}

function jeab_csrf_refresh_respond(): never
{
    if (function_exists('jeab_boot_session')) {
        jeab_boot_session();
    }
    if (!function_exists('jeab_auth_ok') || !jeab_auth_ok()) {
        if (function_exists('jeab_json')) {
            jeab_json(['ok' => false, 'error' => 'Logga in först.'], 401);
        }
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"ok":false,"error":"Logga in först."}';
        exit;
    }
    if (function_exists('jeab_auth_bind_user')) {
        jeab_auth_bind_user(function_exists('jeab_auth_user_email') ? jeab_auth_user_email() : null);
    }
    if (function_exists('jeab_auth_renew_cookie')) {
        jeab_auth_renew_cookie();
    }
    if (function_exists('jeab_pdo_live_count') && jeab_pdo_live_count() > 0 && function_exists('jeab_pdo_release')) {
        jeab_pdo_release();
    }
    header('Cache-Control: no-store');
    $payload = ['ok' => true, 'csrf' => jeab_csrf_token()];
    if (function_exists('jeab_json')) {
        jeab_json($payload);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jeab_csrf_field(): string
{
    $cached = $GLOBALS['_JEAB_CSRF_FIELD'] ?? null;
    if (is_string($cached)) {
        return $cached;
    }
    $html = '<input type="hidden" name="_csrf" value="' . e(jeab_csrf_token()) . '">';
    $GLOBALS['_JEAB_CSRF_FIELD'] = $html;

    return $html;
}

function jeab_request_csrf(): string
{
    $header = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($header !== '') {
        return $header;
    }
    return (string) ($_POST['_csrf'] ?? '');
}

function jeab_csrf_ok(?string $token = null): bool
{
    $given = $token ?? jeab_request_csrf();
    if ($given === '') {
        return false;
    }
    foreach ([jeab_csrf_signed_token(), jeab_csrf_session_token()] as $expected) {
        if (is_string($expected) && $expected !== '' && hash_equals($expected, $given)) {
            return true;
        }
    }
    return false;
}

function jeab_app_errors_path(): string
{
    return jeab_fellogg_data_dir() . '/app-errors.jsonl';
}

function jeab_log_mask_text(string $text): string
{
    $text = preg_replace('/(?<!\d)(?:\d{6}|\d{8})[-+]?\d{4}(?!\d)/', '[pnr]', $text) ?? $text;
    $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[mejl]', $text) ?? $text;
    $text = preg_replace('/(?<!\d)(?:\+46\s*|0)7(?:[\s\-]?\d){7,}/', '[tel]', $text) ?? $text;
    $text = preg_replace('/\b(?:19|20)\d{6}[-+]?\d{4}\b/', '[pnr]', $text) ?? $text;
    $text = preg_replace('/\b\d{6}[-+]\d{4}\b/', '[pnr]', $text) ?? $text;
    $text = preg_replace_callback(
        '/\b(\d{4,5})[-\s](\d{7,12})\b/',
        static function (array $match): string {
            return '****' . substr($match[2], -4);
        },
        $text
    ) ?? $text;
    return $text;
}

function jeab_log_safe_path(string $uri): string
{
    $parts = parse_url($uri);
    $path = (string) ($parts['path'] ?? '');
    $query = (string) ($parts['query'] ?? '');
    if ($query === '') {
        return $path !== '' ? $path : $uri;
    }
    parse_str($query, $q);
    if (!is_array($q)) {
        return $path;
    }
    foreach (['token', 'password', 'code', 'secret', 'key', 'campaign', '_csrf', 'csrf'] as $drop) {
        unset($q[$drop]);
    }
    $qs = http_build_query($q);
    return $qs === '' ? $path : $path . '?' . $qs;
}

function jeab_log_surface_from_action(string $action): string
{
    if (str_starts_with($action, 'reminder.')) {
        return 'reminders';
    }
    if (str_starts_with($action, 'booking.') || str_starts_with($action, 'outlook')) {
        return 'calendar';
    }
    if (str_starts_with($action, 'purchase.') || str_starts_with($action, 'ordrar') || str_starts_with($action, 'inkop')) {
        return 'ordrar';
    }
    if (str_starts_with($action, 'fortnox.')) {
        return 'fortnox';
    }
    if (str_starts_with($action, 'rot')) {
        return 'rot';
    }
    if (str_starts_with($action, 'bugg')) {
        return 'bugg';
    }
    return $action !== '' ? $action : 'app';
}

function jeab_log_trim_errors(string $path, int $room = 0, int $max = 10485760): void
{
    try {
        $max = $max > 0 ? $max : 10 * 1024 * 1024;
        if (!is_file($path)) {
            return;
        }
        $size = @filesize($path);
        if (!is_int($size)) {
            return;
        }
        $room = max(0, $room);
        $budget = $max - $room;
        if ($budget < 0) {
            $budget = 0;
        }
        if ($size <= $budget) {
            return;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            @file_put_contents($path, '', LOCK_EX);
            return;
        }
        $lines = preg_split("/\n/", $raw, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($lines) || $lines === []) {
            @file_put_contents($path, '', LOCK_EX);
            return;
        }
        $kept = [];
        $used = 0;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $chunk = $lines[$i] . "\n";
            $len = strlen($chunk);
            if ($kept !== [] && $used + $len > $budget) {
                break;
            }
            if ($kept === [] && $len > $budget) {
                break;
            }
            $kept[] = $lines[$i];
            $used += $len;
        }
        $kept = array_reverse($kept);
        @file_put_contents($path, $kept === [] ? '' : implode("\n", $kept) . "\n", LOCK_EX);
    } catch (Throwable) {
    }
}

function jeab_fellogg_now(): int
{
    if (isset($GLOBALS['_JEAB_NOW']) && is_int($GLOBALS['_JEAB_NOW'])) {
        return $GLOBALS['_JEAB_NOW'];
    }
    return time();
}

function jeab_fellogg_data_dir(): string
{
    if (!empty($GLOBALS['_JEAB_FELLOGG_DIR']) && is_string($GLOBALS['_JEAB_FELLOGG_DIR'])) {
        return rtrim($GLOBALS['_JEAB_FELLOGG_DIR'], '/');
    }
    $dir = jeab_data_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function jeab_fellogg_json_read(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }
    $decoded = json_decode((string) @file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function jeab_fellogg_json_write(string $path, array $data): bool
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        return false;
    }
    $dir = dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) {
        return false;
    }
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function jeab_log_app_error_stack_first_line(string $stack): int
{
    if (preg_match('/#\d+\s+\S+?\((\d+)\)/', $stack, $m) === 1) {
        return (int) $m[1];
    }

    return 0;
}

function jeab_log_app_error_stack_first_file(string $stack): string
{
    $stack = trim($stack);
    if ($stack === '') {
        return '';
    }
    if (preg_match('/#\d+\s+(\S+?)(?:\(|:|\s|$)/', $stack, $m)) {
        return trim((string) $m[1], " \t\"'");
    }
    $first = trim((string) (strtok($stack, "\n") ?: ''));
    if ($first !== '' && preg_match('/(\S+\.php)/', $first, $m)) {
        return (string) $m[1];
    }
    return '';
}

function jeab_log_app_error_capture_stack(int $limit = 8): string
{
    $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit + 8);
    $lines = [];
    $n = 0;
    foreach ($frames as $frame) {
        $file = (string) ($frame['file'] ?? '');
        if ($file === '') {
            continue;
        }
        $norm = str_replace('\\', '/', $file);
        if (str_ends_with($norm, '/src/security.php')) {
            continue;
        }
        $line = (int) ($frame['line'] ?? 0);
        $fn = (string) ($frame['function'] ?? '');
        $lines[] = '#' . $n . ' ' . $file . '(' . $line . '): ' . $fn;
        $n++;
        if ($n >= $limit) {
            break;
        }
    }
    return implode("\n", $lines);
}

/**
 * Varför en CSRF-kontroll föll. Ändrar inte om begäran accepteras.
 * expired  — token finns, men inloggning saknas, session-cookie saknas, eller sessionen var tom (gammal flik)
 * mismatch — token finns, inloggning och session är aktuella, token är inte den vi väntar oss
 * other    — token saknas eller har inte formen vi ger ut
 */
function jeab_csrf_reject_reason(?string $token = null): string
{
    $given = trim($token ?? jeab_request_csrf());
    if ($given === '' || preg_match('/\A[0-9a-f]{64}\z/i', $given) !== 1) {
        return 'other';
    }
    $sessionName = session_name();
    if ($sessionName === '') {
        $sessionName = 'jeab';
    }
    $sentSession = isset($_COOKIE[$sessionName]) && trim((string) $_COOKIE[$sessionName]) !== '';
    $active = session_status() === PHP_SESSION_ACTIVE;
    $authOk = function_exists('jeab_auth_ok') && jeab_auth_ok();
    $fresh = !empty($GLOBALS['_JEAB_SESSION_FRESH']);
    if (!$authOk || !$sentSession || !$active || $fresh) {
        return 'expired';
    }
    return 'mismatch';
}

function jeab_log_id_hash(string $kind, string $value): string
{
    $value = trim($value);
    if (($kind !== 'user' && $kind !== 'session') || $value === '') {
        return '';
    }
    $key = function_exists('jeab_auth_secret') ? jeab_auth_secret() : '';
    if ($key === '') {
        $key = 'jeab-fellogg-actor';
    }
    return $kind . ':' . substr(hash_hmac('sha256', $kind . '|' . $value, $key), 0, 16);
}

function jeab_log_actor_hash(): string
{
    if (function_exists('jeab_auth_user_email')) {
        $email = jeab_auth_user_email();
        if (is_string($email) && $email !== '') {
            return jeab_log_id_hash('user', $email);
        }
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $sid = trim((string) session_id());
        if ($sid !== '') {
            return jeab_log_id_hash('session', $sid);
        }
    }
    return '';
}

function jeab_csrf_request_method(): string
{
    $method = strtoupper(trim((string) ($_SERVER['REQUEST_METHOD'] ?? '')));
    if (preg_match('/\A[A-Z]{1,16}\z/', $method) !== 1) {
        return '';
    }
    return $method;
}

function jeab_csrf_request_url(): string
{
    $uri = trim((string) ($_SERVER['REQUEST_URI'] ?? ''));
    if ($uri === '') {
        $uri = trim((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    }
    $safe = jeab_log_mask_text(jeab_log_safe_path($uri));
    if (strlen($safe) > 300) {
        $safe = substr($safe, 0, 300);
    }
    return $safe;
}

function jeab_csrf_log_context(?string $token = null): array
{
    $context = [
        'url' => jeab_csrf_request_url(),
        'method' => jeab_csrf_request_method(),
        'reason' => jeab_csrf_reject_reason($token),
    ];
    $actor = jeab_log_actor_hash();
    if ($actor !== '') {
        $context['actor'] = $actor;
    }
    return $context;
}

function jeab_log_row_diag(array $row): array
{
    $out = [];
    $url = trim((string) ($row['url'] ?? ''));
    if ($url !== '') {
        $url = jeab_log_mask_text(jeab_log_safe_path($url));
        if (strlen($url) > 300) {
            $url = substr($url, 0, 300);
        }
        if ($url !== '') {
            $out['url'] = $url;
        }
    }
    $method = strtoupper(trim((string) ($row['method'] ?? '')));
    if (preg_match('/\A[A-Z]{1,16}\z/', $method) === 1) {
        $out['method'] = $method;
    }
    $actor = strtolower(trim((string) ($row['actor'] ?? '')));
    if (preg_match('/\A(?:user|session):[0-9a-f]{16}\z/', $actor) === 1) {
        $out['actor'] = $actor;
    }
    $reason = strtolower(trim((string) ($row['reason'] ?? '')));
    if (in_array($reason, ['expired', 'mismatch', 'other'], true)) {
        $out['reason'] = $reason;
    }
    $ref = trim((string) ($row['ref'] ?? ''));
    if (preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $ref) === 1) {
        $out['ref'] = $ref;
    }
    if (isset($row['curl_errno']) && is_numeric($row['curl_errno'])) {
        $errno = (int) $row['curl_errno'];
        if ($errno >= 0 && $errno <= 999) {
            $out['curl_errno'] = $errno;
        }
    }
    $curlError = trim((string) ($row['curl_error'] ?? ''));
    if ($curlError !== '') {
        $curlError = jeab_log_mask_text(trim(preg_replace('/\s+/', ' ', $curlError) ?? ''));
        if (strlen($curlError) > 180) {
            $curlError = substr($curlError, 0, 180);
        }
        if ($curlError !== '') {
            $out['curl_error'] = $curlError;
        }
    }
    return $out;
}

function jeab_csrf_log_redact(string $line): string
{
    $needles = [];
    $token = trim(jeab_request_csrf());
    if (strlen($token) >= 8) {
        $needles[] = $token;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $sid = trim((string) session_id());
        if (strlen($sid) >= 8) {
            $needles[] = $sid;
        }
    }
    if (function_exists('jeab_auth_user_email')) {
        $email = jeab_auth_user_email();
        if (is_string($email) && $email !== '') {
            $needles[] = $email;
        }
    }
    foreach ($needles as $needle) {
        $line = str_replace($needle, '[redacted]', $line);
    }
    return $line;
}

/**
 * Vilken order och vem: adress (aldrig ordernummer i text) och personens namn.
 * Inga personnummer, koder, tokens eller mejladresser.
 */
function jeab_log_request_context(): array
{
    $out = [];
    try {
        $action = (string) ($_POST['action'] ?? '');
        $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
        $page = (string) ($_GET['p'] ?? '');
        if ($id > 0 && (str_starts_with($action, 'ordrar.') || $page === 'ordrar') && function_exists('jeab_purchase_get')) {
            $order = jeab_purchase_get($id);
            if (is_array($order)) {
                $addr = trim((string) ($order['address'] ?? $order['huvudsokande_address'] ?? ''));
                if ($addr !== '') {
                    $addr = jeab_log_mask_text($addr);
                    $out['order'] = function_exists('mb_substr') ? mb_substr($addr, 0, 160, 'UTF-8') : substr($addr, 0, 160);
                }
            }
        }
        if (function_exists('jeab_who_me_person')) {
            $person = jeab_who_me_person();
            $name = is_array($person) ? trim((string) ($person['name'] ?? '')) : '';
            if ($name !== '') {
                $out['user_name'] = function_exists('mb_substr') ? mb_substr($name, 0, 60, 'UTF-8') : substr($name, 0, 60);
            }
        }
        if (!isset($out['actor']) && function_exists('jeab_log_actor_hash')) {
            $actor = jeab_log_actor_hash();
            if ($actor !== '') {
                $out['actor'] = $actor;
            }
        }
    } catch (Throwable) {
    }
    return $out;
}

function jeab_throwable_log_message(Throwable $e): string
{
    $message = trim($e->getMessage());
    $text = $e::class . ($message !== '' ? ': ' . $message : '');
    $file = $e->getFile();
    $base = defined('JEAB_ROOT') ? JEAB_ROOT : dirname(__DIR__);
    if ($file !== '' && str_starts_with($file, $base)) {
        $file = ltrim(substr($file, strlen($base)), '/');
    }
    if ($file !== '') {
        $text .= ' @ ' . $file . ':' . $e->getLine();
    }
    $previous = $e->getPrevious();
    if ($previous instanceof Throwable && $previous !== $e) {
        $prev = trim($previous->getMessage());
        $text .= ' | ' . $previous::class . ($prev !== '' ? ': ' . $prev : '');
    }

    return $text;
}

/**
 * En avsedd svensk mening får visas. SQL, sökvägar, stack och hemligheter får inte det.
 * Det som stoppas loggas med jeab_throwable_log_message via jeab_log_uncaught.
 */
function jeab_public_error_sentence(string $message): bool
{
    $message = trim($message);
    if ($message === '' || strlen($message) > 400) {
        return false;
    }
    if (preg_match('/[\r\n]/', $message) === 1) {
        return false;
    }
    if (preg_match('/\b(select|insert|delete|drop|alter|union|sqlstate|pdo|stack trace)\b/i', $message) === 1) {
        return false;
    }
    if (preg_match('/\bupdate\s+\S+\s+set\b/i', $message) === 1) {
        return false;
    }
    if (preg_match('/\.php\b|\\\\|\/(?:var|home|workspace|tmp)\b|password|secret|api[_-]?key|bearer\s+\S|token\s*=/i', $message) === 1) {
        return false;
    }

    return true;
}

/**
 * Förväntad vägran när #vunnen saknar adress eller kontakt.
 * Säljaren ska se meningen. Den är inget fel i felloggen.
 */
function jeab_log_skip_expected_refusal(Throwable $e): bool
{
    if (!$e instanceof InvalidArgumentException) {
        return false;
    }

    return str_starts_with(trim($e->getMessage()), 'Ordern skapades inte');
}

/** Texten användaren får se. Allt annat loggas med jeab_throwable_log_message. */
function jeab_public_error_text(Throwable $e): string
{
    if ($e instanceof JeabDbBusyException || (function_exists('jeab_pdo_is_max_user_connections') && jeab_pdo_is_max_user_connections($e))) {
        $busy = $e instanceof JeabDbBusyException ? trim($e->getMessage()) : '';
        if ($busy === '' && function_exists('jeab_pdo_busy_message')) {
            $busy = jeab_pdo_busy_message();
        }

        return $busy !== '' ? $busy : 'Databasen är upptagen. Försök igen om en stund.';
    }
    if (class_exists(JeabAlreadySentException::class, false) && $e instanceof JeabAlreadySentException) {
        return 'Offerten är redan skickad';
    }
    if ($e instanceof InvalidArgumentException) {
        $message = trim($e->getMessage());
        if ($message !== '' && jeab_public_error_sentence($message)) {
            return $message;
        }
        if (function_exists('jeab_log_uncaught')) {
            jeab_log_uncaught('public-error', $e);
        }

        return 'Något gick fel.';
    }

    return 'Något gick fel.';
}

function jeab_log_uncaught(string $surface, Throwable $e): void
{
    jeab_log_app_error($surface, jeab_throwable_log_message($e));
}

/**
 * Långsam request, fråga eller klick-till-färdig. Samma dedupe som övriga app-fel.
 * Nivån info aviserar inte webhooken. Frågan loggas som verb och tabell.
 * En request över tröskeln får dessutom spår och normaliserad SQL, aldrig söktext.
 */
/** Spår och normaliserad SQL. Mejl, citat, länkar och taggar tas bort. */
function jeab_log_slow_note_safe(string $note): string
{
    $note = preg_replace('/\s+/', ' ', trim($note)) ?? '';
    $note = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '?', $note) ?? $note;
    $note = preg_replace("/'[^']*'/", '?', $note) ?? $note;
    $note = preg_replace('/"[^"]*"/', '?', $note) ?? $note;
    if ($note === '' || preg_match('/[<>]|https?:|token=|@/i', $note) === 1) {
        return '';
    }
    if (strlen($note) > 420) {
        $note = substr($note, 0, 420);
    }

    return $note;
}

function jeab_log_slow_request(string $kind, string $page, int $ms, string $detail = ''): void
{
    $kind = strtolower(preg_replace('/[^a-z]/', '', $kind) ?? '');
    if (!in_array($kind, ['total', 'query', 'click'], true)) {
        return;
    }
    $page = function_exists('jeab_timing_page_slug') ? jeab_timing_page_slug($page) : 'okand';
    $note = $kind === 'total' ? jeab_log_slow_note_safe($detail) : '';
    $shape = '';
    if ($kind === 'query' && preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b(?:\s+([A-Za-z_][A-Za-z0-9_]{0,31}))?/i', $detail, $shapeMatch) === 1) {
        $shape = strtoupper($shapeMatch[1]);
        $table = strtolower((string) ($shapeMatch[2] ?? ''));
        if ($table !== '') {
            $shape .= ' ' . $table;
        }
    }
    $detail = $shape;
    $ms = max(0, $ms);
    $label = match ($kind) {
        'query' => 'långsam fråga',
        'click' => 'långsamt klick',
        default => 'långsam request',
    };
    $message = $label . ' ' . $ms . ' ms p=' . $page;
    if ($kind === 'query' && $detail !== '') {
        $message .= ' ' . $detail;
    }
    if ($note !== '') {
        $message .= ' ' . $note;
    }
    $window = function_exists('jeab_timing_slow_window_seconds') ? jeab_timing_slow_window_seconds() : 1800;
    jeab_log_app_error('fart', $message, [
        'level' => 'info',
        'fingerprint' => hash('sha256', 'slow|' . $kind . '|' . $page . '|' . $detail),
        'dedupe_seconds' => $window,
        'skip_request' => true,
        'path' => '/index.php?p=' . $page,
    ]);
}

function jeab_log_app_error(string $surface, ?string $message = null, array $context = []): void
{
    try {
        $level = strtolower(trim((string) ($context['level'] ?? '')));
        if ($level === 'info') {
            $context['no_webhook'] = true;
        }
        $dir = jeab_fellogg_data_dir();
        if (!is_dir($dir) || !is_writable($dir)) {
            return;
        }
        $stack = jeab_log_app_error_capture_stack();
        $file = jeab_log_app_error_stack_first_file($stack);
        $stackLine = jeab_log_app_error_stack_first_line($stack);
        $messageText = trim((string) ($message ?? ''));
        $diag = jeab_log_row_diag($context);
        $fpMessage = $messageText;
        if (isset($diag['reason'])) {
            $fpMessage .= '|' . $diag['reason'];
        }
        $fingerprint = jeab_log_app_error_fingerprint($surface, $fpMessage, $stack);
        $givenFp = strtolower(trim((string) ($context['fingerprint'] ?? '')));
        if (preg_match('/\A[0-9a-f]{64}\z/', $givenFp) === 1) {
            $fingerprint = $givenFp;
        }
        $dedupeWindow = max(0, (int) ($context['dedupe_seconds'] ?? 0));
        $pathSource = array_key_exists('path', $context)
            ? (string) $context['path']
            : (string) ($_SERVER['REQUEST_URI'] ?? '');
        $row = [
            'time' => function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s'),
            'surface' => jeab_log_mask_text($surface),
            'message' => jeab_log_mask_text($messageText),
            'action' => jeab_log_mask_text((string) ($_POST['action'] ?? '')),
            'path' => jeab_log_safe_path($pathSource),
            'fingerprint' => $fingerprint,
            'count' => 1,
        ];
        if ($dedupeWindow > 0) {
            $seenAt = jeab_fellogg_now();
            $row['last_seen'] = $seenAt;
            $row['opened_at'] = $seenAt;
        }
        if ($file !== '') {
            $row['file'] = $file;
        }
        if ($stackLine > 0) {
            $row['line'] = $stackLine;
        }
        if ($level === 'info') {
            $row['level'] = 'info';
        }
        foreach ($diag as $key => $value) {
            $row[$key] = $value;
        }
        if (empty($context['skip_request'])) {
            foreach (jeab_log_request_context() as $key => $value) {
                if (!isset($row[$key])) {
                    $row[$key] = $value;
                }
            }
        }
        $logFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $logFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $line = json_encode($row, $logFlags);
        if (!is_string($line) || $line === '') {
            $row['message'] = 'xAI HTTP 0 errno ' . (int) ($row['curl_errno'] ?? 0);
            $row['curl_error'] = 'log encode failed';
            $line = json_encode($row, $logFlags);
        }
        if (!is_string($line) || $line === '') {
            return;
        }
        if (strlen($line) > 4000) {
            $row['message'] = function_exists('mb_substr')
                ? mb_substr((string) $row['message'], 0, 500, 'UTF-8')
                : substr((string) $row['message'], 0, 500);
            $line = json_encode($row, $logFlags);
            if (!is_string($line) || $line === '') {
                return;
            }
        }
        if (jeab_log_app_error_norm($surface) === 'csrf') {
            $line = jeab_csrf_log_redact($line);
        }
        $payload = $line . "\n";
        if (!function_exists('jeab_remain_sql_mode')) {
            require_once __DIR__ . '/remain_sql.php';
        }
        $logPdo = function_exists('jeab_log_pdo_raw') ? jeab_log_pdo_raw() : null;
        $logBusy = $logPdo instanceof PDO && function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($logPdo);
        $jsonOnly = (function_exists('jeab_cutover_forces_json') && jeab_cutover_forces_json('app_errors'))
            || (function_exists('jeab_remain_sql_mode') && jeab_remain_sql_mode('app_errors') === 'json');
        $trySql = !$jsonOnly && !$logBusy && empty($GLOBALS['_JEAB_FELLOGG_DIR']) && $logPdo instanceof PDO && function_exists('jeab_log_app_error_append');
        if ($dedupeWindow > 0) {
            if (jeab_log_app_error_bump($fingerprint, $dedupeWindow, $row, $trySql)) {
                return;
            }
            // Nytt tillfälle efter fönstret ska aviseras även om felloggens timspärr ligger kvar.
            jeab_log_app_error_webhook_release($fingerprint);
        }
        if ($trySql && jeab_log_app_error_append($line)) {
            if (empty($context['no_webhook'])) {
                jeab_log_app_error_webhook($row);
            }
            return;
        }
        if (!$jsonOnly && empty($GLOBALS['_JEAB_FELLOGG_DIR']) && function_exists('jeab_cutover_log_append') && jeab_cutover_log_append('app_errors', $line)) {
            if (empty($context['no_webhook'])) {
                jeab_log_app_error_webhook($row);
            }
            return;
        }
        $path = jeab_app_errors_path();
        jeab_log_trim_errors($path, strlen($payload));
        $written = @file_put_contents($path, $payload, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            jeab_log_trim_errors($path, strlen($payload) + 1024);
            $written = @file_put_contents($path, $payload, FILE_APPEND | LOCK_EX);
        }
        if ($written !== false && empty($context['no_webhook'])) {
            jeab_log_app_error_webhook($row);
        }
    } catch (Throwable) {
    }
}

/**
 * Samma fingeravtryck inom fönstret: höj count och last_seen på raden som finns.
 * Ingen ny rad och inget webhook-anrop.
 */
function jeab_log_app_error_bump(string $fingerprint, int $window, array $patch, bool $trySql): bool
{
    if ($fingerprint === '' || $window <= 0) {
        return false;
    }
    try {
        if ($trySql) {
            return jeab_log_app_error_bump_sql($fingerprint, $window, $patch);
        }
        return jeab_log_app_error_bump_file($fingerprint, $window, $patch);
    } catch (Throwable) {
        return false;
    }
}

function jeab_log_app_error_bump_apply(array $row, int $window, array $patch): ?array
{
    $now = (int) ($patch['last_seen'] ?? jeab_fellogg_now());
    $opened = (int) ($row['opened_at'] ?? 0);
    if ($opened <= 0) {
        $opened = (int) ($row['last_seen'] ?? 0);
    }
    if ($opened <= 0) {
        $parsed = strtotime((string) ($row['time'] ?? ''));
        $opened = $parsed === false ? 0 : $parsed;
    }
    if ($opened <= 0 || ($now - $opened) > $window) {
        return null;
    }
    $row['count'] = max(1, (int) ($row['count'] ?? 1)) + 1;
    $row['opened_at'] = $opened;
    $row['last_seen'] = $now;
    if (isset($patch['time'])) {
        $row['time'] = (string) $patch['time'];
    }
    if (isset($patch['message'])) {
        $row['message'] = (string) $patch['message'];
    }
    return $row;
}

function jeab_log_app_error_bump_sql(string $fingerprint, int $window, array $patch): bool
{
    if (!function_exists('jeab_log_pdo') || !function_exists('jeab_log_line_hash')) {
        return false;
    }
    $pdo = jeab_log_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    // Senaste app-felen via (kind, id), inte LIKE över hela payload.
    $stmt = $pdo->prepare('SELECT id, payload FROM log_lines WHERE kind = ? ORDER BY id DESC LIMIT 200');
    $stmt->execute(['app_error']);
    $hit = null;
    $row = null;
    while ($cand = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($cand)) {
            continue;
        }
        $decoded = json_decode((string) ($cand['payload'] ?? ''), true);
        if (!is_array($decoded) || (string) ($decoded['fingerprint'] ?? '') !== $fingerprint) {
            continue;
        }
        $hit = $cand;
        $row = $decoded;
        break;
    }
    if (!is_array($hit) || !is_array($row)) {
        return false;
    }
    $next = jeab_log_app_error_bump_apply($row, $window, $patch);
    if ($next === null) {
        return false;
    }
    $line = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($line) || $line === '') {
        return false;
    }
    $upd = $pdo->prepare('UPDATE log_lines SET line_hash = ?, at = ?, payload = ? WHERE id = ? AND kind = ?');
    $upd->execute([
        jeab_log_line_hash($line),
        (string) ($next['time'] ?? ''),
        $line,
        (int) $hit['id'],
        'app_error',
    ]);
    return true;
}

/**
 * Byter ut den senaste raden med samma fingeravtryck.
 * null = ingen träff. false = träff utanför fönstret.
 *
 * @return array{0:string,1:int}|false|null
 */
function jeab_log_app_error_bump_replace(string $body, string $fingerprint, int $window, array $patch): array|false|null
{
    $lines = explode("\n", $body);
    $found = -1;
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $trim = trim((string) $lines[$i]);
        if ($trim === '') {
            continue;
        }
        $row = json_decode($trim, true);
        if (!is_array($row) || (string) ($row['fingerprint'] ?? '') !== $fingerprint) {
            continue;
        }
        $next = jeab_log_app_error_bump_apply($row, $window, $patch);
        if ($next === null) {
            return false;
        }
        $encoded = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || $encoded === '') {
            return false;
        }
        $lines[$i] = $encoded;
        $found = $i;
        break;
    }
    if ($found < 0) {
        return null;
    }
    $offset = 0;
    for ($i = 0; $i < $found; $i++) {
        $offset += strlen($lines[$i]) + 1;
    }

    return [implode("\n", $lines), $offset];
}

function jeab_log_app_error_bump_file(string $fingerprint, int $window, array $patch): bool
{
    $path = jeab_app_errors_path();
    if (!is_file($path)) {
        return false;
    }
    $fh = @fopen($path, 'c+');
    if ($fh === false) {
        return false;
    }
    try {
        if (!flock($fh, LOCK_EX)) {
            return false;
        }
        $stat = fstat($fh);
        $size = is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;
        if ($size <= 0) {
            return false;
        }
        $tailBytes = 262144;
        $start = $size > $tailBytes ? $size - $tailBytes : 0;
        if (fseek($fh, $start) !== 0) {
            return false;
        }
        $raw = stream_get_contents($fh);
        if (!is_string($raw) || $raw === '') {
            return false;
        }
        $sliceFrom = 0;
        if ($start > 0) {
            $nl = strpos($raw, "\n");
            if ($nl === false) {
                $start = 0;
                if (fseek($fh, 0) !== 0) {
                    return false;
                }
                $raw = stream_get_contents($fh);
                if (!is_string($raw) || $raw === '') {
                    return false;
                }
            } else {
                $sliceFrom = $nl + 1;
            }
        }
        $body = substr($raw, $sliceFrom);
        $absBase = $start + $sliceFrom;
        $replaced = jeab_log_app_error_bump_replace($body, $fingerprint, $window, $patch);
        if ($replaced === false) {
            return false;
        }
        if ($replaced === null && $start > 0) {
            if (fseek($fh, 0) !== 0) {
                return false;
            }
            $raw = stream_get_contents($fh);
            if (!is_string($raw) || $raw === '') {
                return false;
            }
            $replaced = jeab_log_app_error_bump_replace($raw, $fingerprint, $window, $patch);
            $absBase = 0;
        }
        if (!is_array($replaced)) {
            return false;
        }
        [$newBody, $editAt] = $replaced;
        if (fseek($fh, $absBase + $editAt) !== 0) {
            return false;
        }
        $suffix = substr($newBody, $editAt);
        if (fwrite($fh, $suffix) === false) {
            return false;
        }
        $end = $absBase + $editAt + strlen($suffix);
        if ($end < $size) {
            ftruncate($fh, $end);
        }

        return true;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function jeab_log_app_error_norm(string $text): string
{
    $text = trim($text);
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

function jeab_log_app_error_fingerprint(string $surface, string $message, string $stack = ''): string
{
    $file = jeab_log_app_error_stack_first_file($stack);
    return hash('sha256', jeab_log_app_error_norm($surface) . '|' . jeab_log_app_error_norm($message) . '|' . jeab_log_app_error_norm($file));
}

function jeab_log_app_error_session_noise(string $message): bool
{
    $norm = rtrim(jeab_log_app_error_norm($message), ".!");
    $norm = trim($norm);
    $noise = [
        'ogiltig session',
        'ogiltig session ladda om sidan',
        'invalid session',
        'session expired',
        'expired session',
        'sessionen har gått ut',
    ];
    return in_array($norm, $noise, true);
}

function jeab_log_app_error_skip_webhook(string $surface, string $message): bool
{
    if (jeab_log_app_error_norm($surface) === 'csrf') {
        return true;
    }
    return jeab_log_app_error_session_noise($message);
}

function jeab_log_app_error_webhook_seen_path(): string
{
    return jeab_fellogg_data_dir() . '/app-errors-webhook-seen.json';
}

function jeab_log_app_error_webhook_resolved_path(): string
{
    return jeab_fellogg_data_dir() . '/app-errors-webhook-resolved.json';
}

function jeab_fellogg_open_asks_path(): string
{
    return jeab_fellogg_data_dir() . '/fellogg-open-asks.json';
}

function jeab_log_app_error_resolved_ttl(): int
{
    return 90 * 24 * 60 * 60;
}

function jeab_log_app_error_resolved_prune(array $map, ?int $now = null): array
{
    $now = $now ?? jeab_fellogg_now();
    $ttl = jeab_log_app_error_resolved_ttl();
    $fresh = [];
    foreach ($map as $fp => $row) {
        if (!is_string($fp) || $fp === '') {
            continue;
        }
        $at = 0;
        $by = 'einstein';
        $note = '';
        if (is_array($row)) {
            $at = (int) ($row['at'] ?? 0);
            $by = (string) ($row['by'] ?? 'einstein');
            $note = (string) ($row['note'] ?? '');
        } elseif (is_int($row) || is_numeric($row)) {
            $at = (int) $row;
        }
        if ($at <= 0 || ($now - $at) >= $ttl) {
            continue;
        }
        if ($by !== 'manual') {
            $by = 'einstein';
        }
        $fresh[$fp] = ['at' => $at, 'by' => $by, 'note' => $note];
    }
    return $fresh;
}

function jeab_log_app_error_resolved_read(?int $now = null): array
{
    if (function_exists('jeab_cutover_webhook_maps')) {
        $maps = jeab_cutover_webhook_maps();
        if (is_array($maps)) {
            return jeab_log_app_error_resolved_prune(is_array($maps['resolved'] ?? null) ? $maps['resolved'] : [], $now);
        }
    }

    return jeab_log_app_error_resolved_prune(jeab_fellogg_json_read(jeab_log_app_error_webhook_resolved_path()), $now);
}

function jeab_log_app_error_resolved_write(array $map, ?int $now = null): bool
{
    $pruned = jeab_log_app_error_resolved_prune($map, $now);
    if (function_exists('jeab_cutover_webhook_write') && function_exists('jeab_cutover_webhook_maps')) {
        $maps = jeab_cutover_webhook_maps();
        if (is_array($maps) || (function_exists('jeab_cutover_sql') && jeab_cutover_sql('app_errors_webhook'))) {
            $seen = is_array($maps) ? null : jeab_fellogg_json_read(jeab_log_app_error_webhook_seen_path());
            if (jeab_cutover_webhook_write($seen, $pruned)) {
                return true;
            }
        }
    }

    return jeab_fellogg_json_write(jeab_log_app_error_webhook_resolved_path(), $pruned);
}

function jeab_log_app_error_resolved_has(string $fingerprint, ?int $now = null): bool
{
    $fingerprint = strtolower(trim($fingerprint));
    if ($fingerprint === '') {
        return false;
    }
    $map = jeab_log_app_error_resolved_read($now);
    if (isset($map[$fingerprint])) {
        return true;
    }
    if (preg_match('/\A[0-9a-f]{8,64}\z/', $fingerprint) !== 1) {
        return false;
    }
    foreach ($map as $key => $_row) {
        $key = strtolower(trim((string) $key));
        if (preg_match('/\A[0-9a-f]{8,64}\z/', $key) !== 1) {
            continue;
        }
        if (str_starts_with($fingerprint, $key) || str_starts_with($key, $fingerprint)) {
            return true;
        }
    }

    return false;
}

function jeab_log_app_error_resolved_put(string $fingerprint, string $by = 'einstein', string $note = '', ?int $now = null): ?array
{
    if ($fingerprint === '') {
        return null;
    }
    $now = $now ?? jeab_fellogg_now();
    $by = $by === 'manual' ? 'manual' : 'einstein';
    $map = jeab_log_app_error_resolved_read($now);
    $row = ['at' => $now, 'by' => $by, 'note' => $note];
    $map[$fingerprint] = $row;
    jeab_log_app_error_resolved_write($map, $now);
    return $row;
}

function jeab_log_app_error_webhook_claim(string $fingerprint): bool
{
    if ($fingerprint === '') {
        return false;
    }
    $path = jeab_log_app_error_webhook_seen_path();
    $now = jeab_fellogg_now();
    $window = jeab_log_app_error_webhook_window();
    $maps = function_exists('jeab_cutover_webhook_maps') ? jeab_cutover_webhook_maps() : null;
    if (is_array($maps) && function_exists('jeab_cutover_webhook_mutate')) {
        // S4-4: avgör och skriv claim inne i samma lås.
        $claimed = false;
        $wrote = jeab_cutover_webhook_mutate(static function (array $seen, array $resolved) use ($fingerprint, $now, $window, &$claimed): ?array {
            $fresh = [];
            foreach ($seen as $fp => $ts) {
                $at = is_int($ts) ? $ts : (int) $ts;
                if (is_string($fp) && $fp !== '' && $at > 0 && ($now - $at) < $window) {
                    $fresh[$fp] = $at;
                }
            }
            if (isset($fresh[$fingerprint])) {
                return null;
            }
            $fresh[$fingerprint] = $now;
            $claimed = true;

            return ['seen' => $fresh, 'resolved' => $resolved];
        });
        if ($wrote) {
            return $claimed;
        }
    }
    $seen = is_array($maps) ? (array) ($maps['seen'] ?? []) : jeab_fellogg_json_read($path);
    $fresh = [];
    foreach ($seen as $fp => $ts) {
        if (!is_string($fp) || $fp === '') {
            continue;
        }
        $at = is_int($ts) ? $ts : (int) $ts;
        if ($at > 0 && ($now - $at) < $window) {
            $fresh[$fp] = $at;
        }
    }
    if (isset($fresh[$fingerprint])) {
        return false;
    }
    $fresh[$fingerprint] = $now;
    if (is_array($maps) || (function_exists('jeab_cutover_sql') && jeab_cutover_sql('app_errors_webhook') && function_exists('jeab_cutover_webhook_write'))) {
        $resolved = is_array($maps) ? (array) ($maps['resolved'] ?? []) : [];
        if (!is_array($maps) && function_exists('jeab_log_app_error_resolved_read')) {
            $resolved = jeab_log_app_error_resolved_read($now);
        }
        if (function_exists('jeab_cutover_webhook_write') && jeab_cutover_webhook_write($fresh, $resolved)) {
            return true;
        }
    }
    if (!jeab_fellogg_json_write($path, $fresh)) {
        return true;
    }
    return true;
}

function jeab_fellogg_open_asks_read(): array
{
    $decoded = null;
    if (empty($GLOBALS['_JEAB_FELLOGG_DIR']) && function_exists('jeab_log_open_asks_read')) {
        $decoded = jeab_log_open_asks_read();
    }
    if (!is_array($decoded)) {
        $decoded = jeab_fellogg_json_read(jeab_fellogg_open_asks_path());
    }
    if (isset($decoded['asks']) && is_array($decoded['asks'])) {
        $decoded = $decoded['asks'];
    }
    $asks = [];
    foreach ($decoded as $row) {
        if (is_array($row) && trim((string) ($row['id'] ?? '')) !== '') {
            $asks[] = $row;
        }
    }
    return $asks;
}

function jeab_fellogg_open_asks_write(array $asks): bool
{
    $asks = array_values($asks);
    if (empty($GLOBALS['_JEAB_FELLOGG_DIR']) && function_exists('jeab_log_open_asks_write') && jeab_log_open_asks_write($asks)) {
        return true;
    }
    return jeab_fellogg_json_write(jeab_fellogg_open_asks_path(), $asks);
}

function jeab_fellogg_ask_miss_post(array $ask): bool
{
    $secret = jeab_einstein_webhook_secret();
    if ($secret === null) {
        return false;
    }
    $fingerprint = trim((string) ($ask['fingerprint'] ?? ''));
    if ($fingerprint === '') {
        $fingerprint = trim((string) ($ask['bug_id'] ?? ''));
    }
    return jeab_einstein_webhook_post($secret, [
        'source' => 'jeab',
        'type' => 'fellogg_ask_miss',
        'id' => (string) ($ask['id'] ?? ''),
        'fingerprint' => $fingerprint,
        'asked_at' => (int) ($ask['asked_at'] ?? 0),
    ]);
}

function jeab_fellogg_open_asks_sweep(array $asks, ?int $now = null): array
{
    $now = $now ?? jeab_fellogg_now();
    $missAfter = 2 * 60 * 60;
    $missDedup = 24 * 60 * 60;
    foreach ($asks as $i => $ask) {
        if (!is_array($ask)) {
            continue;
        }
        $status = (string) ($ask['status'] ?? 'open');
        $askedAt = (int) ($ask['asked_at'] ?? 0);
        $posted = (int) ($ask['miss_posted_at'] ?? 0);
        $staleOpen = $status === 'open' && $askedAt > 0 && ($now - $askedAt) >= $missAfter;
        if (!$staleOpen) {
            continue;
        }
        $asks[$i]['status'] = 'miss';
        if ($posted > 0 && ($now - $posted) < $missDedup) {
            continue;
        }
        jeab_fellogg_ask_miss_post($asks[$i]);
        $asks[$i]['miss_posted_at'] = $now;
    }
    return $asks;
}

function jeab_fellogg_open_asks_persist_sweep(?int $now = null): array
{
    $asks = jeab_fellogg_open_asks_sweep(jeab_fellogg_open_asks_read(), $now);
    jeab_fellogg_open_asks_write($asks);
    return $asks;
}

function jeab_fellogg_open_ask_append(array $ask): ?array
{
    $now = jeab_fellogg_now();
    $id = trim((string) ($ask['id'] ?? ''));
    if ($id === '') {
        $id = bin2hex(random_bytes(8));
    }
    $kind = (string) ($ask['kind'] ?? 'visa_mig');
    if ($kind !== 'fix') {
        $kind = 'visa_mig';
    }
    $row = [
        'id' => $id,
        'asked_at' => (int) ($ask['asked_at'] ?? $now),
        'kind' => $kind,
        'status' => 'open',
    ];
    $fingerprint = trim((string) ($ask['fingerprint'] ?? ''));
    if ($fingerprint !== '') {
        $row['fingerprint'] = $fingerprint;
    }
    $bugId = trim((string) ($ask['bug_id'] ?? ''));
    if ($bugId !== '') {
        $row['bug_id'] = $bugId;
    }
    $asks = jeab_fellogg_open_asks_read();
    $asks[] = $row;
    $asks = jeab_fellogg_open_asks_sweep($asks, $now);
    jeab_fellogg_open_asks_write($asks);
    return $row;
}

function jeab_fellogg_open_ask_ack(string $id): ?array
{
    $id = trim($id);
    if ($id === '') {
        return null;
    }
    $asks = jeab_fellogg_open_asks_sweep(jeab_fellogg_open_asks_read());
    $found = null;
    foreach ($asks as $i => $ask) {
        if ((string) ($ask['id'] ?? '') === $id) {
            $asks[$i]['status'] = 'delivered';
            $found = $asks[$i];
            break;
        }
    }
    jeab_fellogg_open_asks_write($asks);
    return $found;
}

function jeab_fellogg_latest_visa_mig(): ?array
{
    $asks = jeab_fellogg_open_asks_persist_sweep();
    $best = null;
    $bestAt = -1;
    foreach ($asks as $ask) {
        if (!is_array($ask) || (string) ($ask['kind'] ?? '') !== 'visa_mig') {
            continue;
        }
        $at = (int) ($ask['asked_at'] ?? 0);
        if ($at >= $bestAt) {
            $bestAt = $at;
            $best = $ask;
        }
    }
    return $best;
}

function jeab_app_errors_stats(array $entries): array
{
    $count = 0;
    $first = '';
    $last = '';
    foreach ($entries as $row) {
        $count += max(1, (int) ($row['count'] ?? 1));
        foreach (['first', 'time'] as $key) {
            $t = trim((string) ($row[$key] ?? ''));
            if ($t !== '' && ($first === '' || strcmp($t, $first) < 0)) {
                $first = $t;
            }
        }
        foreach (['last', 'time'] as $key) {
            $t = trim((string) ($row[$key] ?? ''));
            if ($t !== '' && ($last === '' || strcmp($t, $last) > 0)) {
                $last = $t;
            }
        }
    }
    return ['count' => $count, 'first' => $first, 'last' => $last];
}

function jeab_log_app_error_webhook_window(): int
{
    return 60 * 60;
}

/** Antal rader i felloggen med samma fingeravtryck (läser bara loggfilen). */
function jeab_log_app_error_fp_count(string $fingerprint): int
{
    if ($fingerprint === '') {
        return 1;
    }
    $raw = null;
    if (empty($GLOBALS['_JEAB_FELLOGG_DIR']) && function_exists('jeab_log_kind_raw')) {
        $raw = jeab_log_kind_raw('app_error');
    }
    if (!is_string($raw)) {
        $raw = @file_get_contents(jeab_app_errors_path());
    }
    if (!is_string($raw) || $raw === '') {
        return 1;
    }
    $n = substr_count($raw, '"fingerprint":"' . $fingerprint . '"');
    return max(1, $n);
}

/**
 * Varje ny felgrupp (fingeravtryck) postas till Einsteins webhook «Bugg från jeab»,
 * högst en gång per grupp och timme. Skickas efter att svaret gått till webbläsaren.
 * Ingen hemlighet, token, kod eller cookie följer med: bara maskad text från loggraden.
 */
function jeab_log_app_error_webhook(array $row): void
{
    try {
        $surface = (string) ($row['surface'] ?? '');
        $message = (string) ($row['message'] ?? '');
        $fingerprint = (string) ($row['fingerprint'] ?? '');
        if ($fingerprint === '') {
            $stack = (string) ($row['stack'] ?? $row['file'] ?? '');
            $fingerprint = jeab_log_app_error_fingerprint($surface, $message, $stack);
        }
        if (jeab_log_app_error_norm($surface) === 'db-conn') {
            return;
        }
        if (jeab_log_app_error_resolved_has($fingerprint)) {
            return;
        }
        // Utgången session (gammal flik, utloggad) är brus. Saknad/fel token (other/mismatch) är buggar och postas.
        if (jeab_log_app_error_norm($surface) === 'csrf' && (string) ($row['reason'] ?? '') === 'expired') {
            return;
        }
        if (!jeab_log_app_error_webhook_claim($fingerprint)) {
            return;
        }
        $payload = [
            'source' => 'jeab',
            'type' => 'fellogg_error',
            'time' => (string) ($row['time'] ?? ''),
            'category' => $surface,
            'surface' => $surface,
            'message' => $message,
            'action' => (string) ($row['action'] ?? ''),
            'path' => (string) ($row['url'] ?? $row['path'] ?? ''),
            'url' => 'https://flowstack.example.test' . (string) ($row['url'] ?? $row['path'] ?? ''),
            'method' => (string) ($row['method'] ?? ''),
            'user' => (string) ($row['actor'] ?? ''),
            'user_name' => (string) ($row['user_name'] ?? ''),
            'order' => (string) ($row['order'] ?? ''),
            'reason' => (string) ($row['reason'] ?? ''),
            'fingerprint' => $fingerprint,
        ];
        $send = static function () use ($payload, $fingerprint): void {
            try {
                $secret = jeab_einstein_webhook_secret();
                if ($secret === null) {
                    jeab_fellogg_secret_missing();
                    jeab_log_app_error_webhook_release($fingerprint);
                    return;
                }
                $payload['count'] = jeab_log_app_error_fp_count($fingerprint);
                $posted = jeab_einstein_webhook_post($secret, $payload);
                if ($posted) {
                    jeab_fellogg_open_ask_append([
                        'fingerprint' => $fingerprint,
                        'kind' => 'visa_mig',
                    ]);
                } else {
                    // Misslyckad post: släpp spärren så nästa förekomst försöker igen.
                    jeab_log_app_error_webhook_release($fingerprint);
                }
                jeab_fellogg_open_asks_persist_sweep();
            } catch (Throwable) {
            }
        };
        if (PHP_SAPI === 'cli' || !empty($GLOBALS['_JEAB_WEBHOOK_SYNC'])) {
            $send();
            return;
        }
        if (empty($GLOBALS['_JEAB_FELLOGG_DEFERRED'])) {
            $GLOBALS['_JEAB_FELLOGG_DEFERRED'] = [];
            register_shutdown_function(static function (): void {
                $jobs = $GLOBALS['_JEAB_FELLOGG_DEFERRED'] ?? [];
                $GLOBALS['_JEAB_FELLOGG_DEFERRED'] = [];
                if ($jobs === []) {
                    return;
                }
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                }
                @ignore_user_abort(true);
                foreach ($jobs as $job) {
                    $job();
                }
            });
        }
        $GLOBALS['_JEAB_FELLOGG_DEFERRED'][] = $send;
    } catch (Throwable) {
    }
}

/** Saknad webhook-hemlighet syns i felloggen högst en gång i timmen. Felets egen spärr släpps ändå. */
function jeab_fellogg_secret_missing(): void
{
    if (!jeab_log_app_error_webhook_claim('einstein-webhook-secret-saknas')) {
        return;
    }
    error_log('fellogg: einstein-webhook-secret saknas');
    if (!empty($GLOBALS['_JEAB_FELLOGG_SECRET_MISSING'])) {
        return;
    }
    $GLOBALS['_JEAB_FELLOGG_SECRET_MISSING'] = true;
    try {
        jeab_log_app_error('fellogg', 'einstein-webhook-secret saknas', ['no_webhook' => true]);
    } finally {
        unset($GLOBALS['_JEAB_FELLOGG_SECRET_MISSING']);
    }
}

function jeab_fellogg_fp_hex(string $fp): bool
{
    return preg_match('/\A[0-9a-f]{8,64}\z/', strtolower($fp)) === 1;
}

/** Exakt träff, eller prefix när båda är minst 8 hextecken. */
function jeab_fellogg_fp_matches(string $filter, string $fp): bool
{
    $filter = strtolower(trim($filter));
    $fp = strtolower(trim($fp));
    if ($filter === '' || $fp === '') {
        return false;
    }
    if ($filter === $fp) {
        return true;
    }
    if (!jeab_fellogg_fp_hex($filter) || !jeab_fellogg_fp_hex($fp)) {
        return false;
    }

    return str_starts_with($fp, $filter) || str_starts_with($filter, $fp);
}

/**
 * @return array{covered_by_resolved:bool,resolved_prefix:string}
 */
function jeab_fellogg_resolved_cover(string $fingerprint): array
{
    $fingerprint = strtolower(trim($fingerprint));
    $hit = '';
    if ($fingerprint === '' || !function_exists('jeab_log_app_error_resolved_read')) {
        return ['covered_by_resolved' => false, 'resolved_prefix' => ''];
    }
    foreach (jeab_log_app_error_resolved_read() as $key => $_row) {
        $keyNorm = strtolower(trim((string) $key));
        if ($keyNorm === '' || !jeab_fellogg_fp_matches($fingerprint, $keyNorm)) {
            continue;
        }
        if ($hit === '' || strlen((string) $key) > strlen($hit)) {
            $hit = (string) $key;
        }
    }

    return ['covered_by_resolved' => $hit !== '', 'resolved_prefix' => $hit];
}

/**
 * Läser felloggen. Hemligheten och lösta anteckningar ingår aldrig.
 *
 * @param array<string, mixed> $in
 * @return array{ok:bool,fingerprints:list<array{fingerprint:string,count:int,latest:string}>,secret_readable:bool,resolved:int,covered_by_resolved:bool,resolved_prefix:string}
 */
function jeab_fellogg_stats(array $in): array
{
    $filter = trim((string) ($in['fingerprint'] ?? ''));
    $by = [];
    $raw = null;
    if (empty($GLOBALS['_JEAB_FELLOGG_DIR']) && function_exists('jeab_log_kind_raw')) {
        $raw = jeab_log_kind_raw('app_error');
    }
    if (!is_string($raw) || trim($raw) === '') {
        $raw = @file_get_contents(jeab_app_errors_path());
    }
    if (is_string($raw) && trim($raw) !== '') {
        $lines = preg_split("/\n/", $raw, -1, PREG_SPLIT_NO_EMPTY);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $row = json_decode((string) $line, true);
                if (!is_array($row)) {
                    continue;
                }
                $fp = trim((string) ($row['fingerprint'] ?? ''));
                if ($fp === '') {
                    $fp = jeab_log_app_error_fingerprint(
                        (string) ($row['surface'] ?? ''),
                        (string) ($row['message'] ?? ''),
                        (string) ($row['file'] ?? '')
                    );
                }
                if ($fp === '') {
                    continue;
                }
                $time = trim((string) ($row['time'] ?? ''));
                if (!isset($by[$fp])) {
                    $by[$fp] = ['fingerprint' => $fp, 'count' => 0, 'latest' => ''];
                }
                $by[$fp]['count']++;
                if ($time !== '' && ($by[$fp]['latest'] === '' || strcmp($time, $by[$fp]['latest']) > 0)) {
                    $by[$fp]['latest'] = $time;
                }
            }
        }
    }
    if ($filter !== '') {
        $rows = [];
        foreach ($by as $fp => $row) {
            if (jeab_fellogg_fp_matches($filter, (string) $fp)) {
                $rows[] = $row;
            }
        }
        if ($rows === []) {
            $rows = [['fingerprint' => $filter, 'count' => 0, 'latest' => '']];
        }
    } else {
        $rows = array_values($by);
    }
    $path = function_exists('jeab_einstein_webhook_secret_path') ? jeab_einstein_webhook_secret_path() : '';
    $readable = $path !== '' && is_file($path) && is_readable($path);
    $resolved = function_exists('jeab_log_app_error_resolved_read') ? count(jeab_log_app_error_resolved_read()) : 0;
    $cover = jeab_fellogg_resolved_cover($filter);

    return [
        'ok' => true,
        'fingerprints' => $rows,
        'secret_readable' => $readable,
        'resolved' => $resolved,
        'covered_by_resolved' => $cover['covered_by_resolved'],
        'resolved_prefix' => $cover['resolved_prefix'],
    ];
}

/**
 * En felloggrad. Hemlighet, stack och personnummer ingår inte.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_fellogg_get(array $in): array
{
    $filter = strtolower(trim((string) ($in['fingerprint'] ?? '')));
    if (!jeab_fellogg_fp_hex($filter)) {
        return ['ok' => false, 'error' => 'fingerprint saknas.', 'status' => 400];
    }
    $raw = null;
    if (empty($GLOBALS['_JEAB_FELLOGG_DIR']) && function_exists('jeab_log_kind_raw')) {
        $raw = jeab_log_kind_raw('app_error');
    }
    if (!is_string($raw) || trim($raw) === '') {
        $raw = @file_get_contents(jeab_app_errors_path());
    }
    $hit = null;
    $count = 0;
    if (is_string($raw) && trim($raw) !== '') {
        $lines = preg_split("/\n/", $raw, -1, PREG_SPLIT_NO_EMPTY);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $row = json_decode((string) $line, true);
                if (!is_array($row)) {
                    continue;
                }
                $fp = strtolower(trim((string) ($row['fingerprint'] ?? '')));
                if ($fp === '' || !jeab_fellogg_fp_matches($filter, $fp)) {
                    continue;
                }
                $count++;
                $time = trim((string) ($row['time'] ?? ''));
                $prev = is_array($hit) ? trim((string) ($hit['time'] ?? '')) : '';
                if (!is_array($hit) || ($time !== '' && ($prev === '' || strcmp($time, $prev) >= 0))) {
                    $hit = $row;
                }
            }
        }
    }
    if (!is_array($hit)) {
        return [
            'ok' => true,
            'fingerprint' => $filter,
            'message' => '',
            'file' => '',
            'line' => 0,
            'time' => '',
            'surface' => '',
            'count' => 0,
        ];
    }
    $message = jeab_log_mask_text((string) ($hit['message'] ?? ''));
    $file = jeab_log_mask_text((string) ($hit['file'] ?? ''));
    $lineNo = (int) ($hit['line'] ?? 0);
    if ($lineNo <= 0 && preg_match('/\((\d+)\)/', $file, $m) === 1) {
        $lineNo = (int) $m[1];
    }
    $secretPath = function_exists('jeab_einstein_webhook_secret_path') ? jeab_einstein_webhook_secret_path() : '';
    if ($secretPath !== '' && is_file($secretPath)) {
        $secret = json_decode((string) @file_get_contents($secretPath), true);
        if (is_array($secret)) {
            foreach (['key', 'url'] as $key) {
                $value = trim((string) ($secret[$key] ?? ''));
                if ($value !== '' && strlen($value) >= 6) {
                    $message = str_replace($value, '[hemlighet]', $message);
                    $file = str_replace($value, '[hemlighet]', $file);
                }
            }
        }
    }

    $diag = function_exists('jeab_log_row_diag') ? jeab_log_row_diag($hit) : [];

    return [
        'ok' => true,
        'fingerprint' => strtolower(trim((string) ($hit['fingerprint'] ?? $filter))),
        'message' => $message,
        'file' => $file,
        'line' => $lineNo,
        'time' => jeab_log_mask_text((string) ($hit['time'] ?? '')),
        'surface' => jeab_log_mask_text((string) ($hit['surface'] ?? '')),
        'count' => $count,
        'curl_errno' => (int) ($diag['curl_errno'] ?? 0),
        'curl_error' => (string) ($diag['curl_error'] ?? ''),
    ];
}

function jeab_log_app_error_webhook_release(string $fingerprint): void
{
    $path = jeab_log_app_error_webhook_seen_path();
    $seen = jeab_fellogg_json_read($path);
    if (isset($seen[$fingerprint])) {
        unset($seen[$fingerprint]);
        jeab_fellogg_json_write($path, $seen);
    }
}

function jeab_app_errors_read(int $limit = 200): array
{
    try {
        $raw = null;
        if (empty($GLOBALS['_JEAB_FELLOGG_DIR']) && function_exists('jeab_log_kind_raw')) {
            $raw = jeab_log_kind_raw('app_error');
        }
        if (!is_string($raw) || trim($raw) === '') {
            $path = jeab_app_errors_path();
            if (!is_file($path)) {
                return [];
            }
            $raw = @file_get_contents($path);
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $lines = preg_split("/\n/", $raw, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($lines)) {
            return [];
        }
        // Aggregera samma fingerprint → en rad (count / first / last). Senaste först.
        $byFp = [];
        $order = [];
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $row = json_decode((string) $lines[$i], true);
            if (!is_array($row)) {
                continue;
            }
            $surface = jeab_log_mask_text((string) ($row['surface'] ?? $row['kind'] ?? ''));
            $message = jeab_log_mask_text((string) ($row['message'] ?? ''));
            $file = jeab_log_mask_text((string) ($row['file'] ?? ''));
            $time = jeab_log_mask_text((string) ($row['time'] ?? ''));
            $fp = trim((string) ($row['fingerprint'] ?? ''));
            if ($fp === '') {
                $fp = jeab_log_app_error_fingerprint($surface, $message, $file);
            }
            $stored = max(1, (int) ($row['count'] ?? 1));
            if (!isset($byFp[$fp])) {
                $byFp[$fp] = [
                    'time' => $time,
                    'first' => $time,
                    'last' => $time,
                    'surface' => $surface,
                    'message' => $message,
                    'file' => $file,
                    'fingerprint' => $fp,
                    'count' => $stored,
                ];
                if (isset($row['last_seen'])) {
                    $byFp[$fp]['last_seen'] = (int) $row['last_seen'];
                }
                foreach (jeab_log_row_diag($row) as $key => $value) {
                    $byFp[$fp][$key] = $value;
                }
                $order[] = $fp;
            } else {
                $byFp[$fp]['count'] += $stored;
                if ($time !== '' && ($byFp[$fp]['first'] === '' || strcmp($time, $byFp[$fp]['first']) < 0)) {
                    $byFp[$fp]['first'] = $time;
                }
                if ($time !== '' && ($byFp[$fp]['last'] === '' || strcmp($time, $byFp[$fp]['last']) > 0)) {
                    $byFp[$fp]['last'] = $time;
                    $byFp[$fp]['time'] = $time;
                    $byFp[$fp]['message'] = $message;
                    $byFp[$fp]['surface'] = $surface;
                    if ($file !== '') {
                        $byFp[$fp]['file'] = $file;
                    }
                }
            }
        }
        $out = [];
        foreach ($order as $fp) {
            if (count($out) >= $limit) {
                break;
            }
            $out[] = $byFp[$fp];
        }
        return $out;
    } catch (Throwable) {
        return [];
    }
}

function jeab_require_csrf(): void
{
    if (jeab_csrf_ok()) {
        return;
    }
    // Bara fellogg. Avvisningen är kvar; ingen utloggning och ingen annan åtgärd.
    jeab_log_app_error('csrf', 'Ogiltig session.', jeab_csrf_log_context());
    if (jeab_wants_json()) {
        jeab_json(['ok' => false, 'error' => 'Ogiltig session. Ladda om sidan.'], 419);
    }
    http_response_code(419);
    header('Content-Type: text/html; charset=utf-8');
    $href = function_exists('jeab_url') ? jeab_url('index.php?p=reminders') : 'index.php?p=reminders';
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>JEAB</title><p>Ogiltig session.</p><p><a href="' . e($href) . '">Ladda om sidan</a></p>';
    exit;
}

function jeab_reject_get_webhook_token(): void
{
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
        return;
    }
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'GET') {
        return;
    }
    if (trim((string) ($_GET['token'] ?? '')) === '') {
        return;
    }
    http_response_code(405);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'error' => 'Token tas inte emot via GET.']);
    exit;
}

function jeab_incoming_body_token(): string
{
    $posted = trim((string) ($_POST['token'] ?? ''));
    if ($posted !== '') {
        return $posted;
    }
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        return '';
    }
    $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    if (!str_contains($type, 'json')) {
        return '';
    }
    if (!isset($GLOBALS['_JEAB_RAW_BODY']) || !is_string($GLOBALS['_JEAB_RAW_BODY'])) {
        $raw = file_get_contents('php://input');
        $GLOBALS['_JEAB_RAW_BODY'] = is_string($raw) ? $raw : '';
    }
    $decoded = json_decode($GLOBALS['_JEAB_RAW_BODY'], true);
    if (!is_array($decoded)) {
        return '';
    }
    return trim((string) ($decoded['token'] ?? ''));
}

function jeab_incoming_token_ok(string $expected): bool
{
    if ($expected === '') {
        return false;
    }
    // Övriga ingångar: bara POST (Authorization, X-Webhook-Token eller body).
    // Query-token hör bara till tick.php och sms-cron.php, via jeab_cron_token_matches.
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        return false;
    }
    $candidates = [];
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
        $candidates[] = trim($m[1]);
    }
    $candidates[] = trim((string) ($_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? ''));
    $candidates[] = jeab_incoming_body_token();

    foreach ($candidates as $given) {
        if ($given !== '' && hash_equals($expected, $given)) {
            return true;
        }
    }
    return false;
}

/**
 * Tar bort cron-token från query, REQUEST_URI och QUERY_STRING innan något loggas.
 * Värdet sparas bara i minnet tills jämförelsen är klar.
 */
function jeab_cron_capture_query_token(): void
{
    if (!empty($GLOBALS['_JEAB_CRON_QUERY_TOKEN_READY'])) {
        return;
    }
    $GLOBALS['_JEAB_CRON_QUERY_TOKEN'] = trim((string) ($_GET['token'] ?? ''));
    $GLOBALS['_JEAB_CRON_QUERY_TOKEN_READY'] = true;
    unset($_GET['token']);
    if (isset($_REQUEST['token'])) {
        unset($_REQUEST['token']);
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if ($uri !== '') {
        $_SERVER['REQUEST_URI'] = jeab_log_safe_path($uri);
    }
    $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
    if ($qs !== '') {
        parse_str($qs, $q);
        if (is_array($q)) {
            unset($q['token']);
            $_SERVER['QUERY_STRING'] = http_build_query($q);
        }
    }
}

/**
 * Egen cron-token för tick.php eller sms-cron.php. Inte webbshopens nyckel
 * och inte hookens token: anroparen skickar den hemlighet just den ingången äger.
 * GET ?token= (Loopia kan bara sätta en URL) och POST (Authorization,
 * X-Webhook-Token eller body). Jämförelsen är hash_equals. Värdet loggas inte
 * och skrivs inte tillbaka i svaret.
 */
function jeab_cron_token_matches(string $expected): bool
{
    jeab_cron_capture_query_token();
    if ($expected === '') {
        unset($GLOBALS['_JEAB_CRON_QUERY_TOKEN']);

        return false;
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
    if ($method === 'GET') {
        $given = (string) ($GLOBALS['_JEAB_CRON_QUERY_TOKEN'] ?? '');
        unset($GLOBALS['_JEAB_CRON_QUERY_TOKEN']);

        return $given !== '' && hash_equals($expected, $given);
    }
    unset($GLOBALS['_JEAB_CRON_QUERY_TOKEN']);
    if ($method !== 'POST') {
        return false;
    }

    return jeab_incoming_token_ok($expected);
}

function jeab_cron_send_headers(): void
{
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg' || headers_sent()) {
        return;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
}

/** @return list<string> */
function jeab_cron_allow_ips(): array
{
    if (array_key_exists('_JEAB_CRON_ALLOW_IPS', $GLOBALS)) {
        $raw = $GLOBALS['_JEAB_CRON_ALLOW_IPS'];
    } else {
        $env = getenv('JEAB_CRON_ALLOW_IPS');
        if (is_string($env) && trim($env) !== '') {
            $raw = explode(',', $env);
        } else {
            $cfg = function_exists('jeab_config') ? (jeab_config()['cron_allow_ips'] ?? []) : [];
            $raw = is_array($cfg) ? $cfg : [];
        }
    }
    if (is_string($raw)) {
        $raw = trim($raw) === '' ? [] : explode(',', $raw);
    }
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $ip) {
        $ip = trim((string) $ip);
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            $out[] = $ip;
        }
    }

    return $out;
}

function jeab_cron_client_ip(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return '';
    }

    return $ip;
}

function jeab_cron_rate_limit(): int
{
    if (array_key_exists('_JEAB_CRON_RATE_LIMIT', $GLOBALS)) {
        return max(0, (int) $GLOBALS['_JEAB_CRON_RATE_LIMIT']);
    }
    $env = getenv('JEAB_CRON_RATE_LIMIT');
    if (is_string($env) && $env !== '') {
        return max(0, (int) $env);
    }
    $cfg = function_exists('jeab_config') ? (jeab_config()['cron_rate_limit'] ?? 0) : 0;

    return max(0, (int) $cfg);
}

function jeab_cron_rate_window(): int
{
    $window = (int) ($GLOBALS['_JEAB_CRON_RATE_WINDOW'] ?? 60);

    return $window > 0 ? $window : 60;
}

function jeab_cron_rate_dir(): string
{
    $override = $GLOBALS['_JEAB_CRON_RATE_DIR'] ?? getenv('JEAB_CRON_RATE_DIR');
    if (is_string($override) && $override !== '') {
        return rtrim($override, '/');
    }

    return rtrim(sys_get_temp_dir(), '/') . '/jeab-cron-rate';
}

/**
 * Tom tillåtelista och tak 0 betyder av, så Loopia inte behöver ändras.
 * REMOTE_ADDR används, inte X-Forwarded-For. Returnerar ip, rate eller null.
 * Skriver aldrig token.
 */
function jeab_cron_access_block(): ?string
{
    $allow = jeab_cron_allow_ips();
    $ip = jeab_cron_client_ip();
    if ($allow !== []) {
        $listed = false;
        foreach ($allow as $item) {
            if (hash_equals($item, $ip)) {
                $listed = true;
                break;
            }
        }
        if (!$listed) {
            return 'ip';
        }
    }
    $limit = jeab_cron_rate_limit();
    if ($limit <= 0) {
        return null;
    }
    $dir = jeab_cron_rate_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return null;
    }
    $file = $dir . '/' . hash('sha256', $ip === '' ? 'none' : $ip);
    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        return null;
    }
    try {
        if (!flock($fh, LOCK_EX)) {
            return null;
        }
        $now = time();
        $window = jeab_cron_rate_window();
        $raw = stream_get_contents($fh);
        $times = [];
        foreach (preg_split('/\s+/', trim((string) $raw)) ?: [] as $part) {
            if ($part === '') {
                continue;
            }
            $stamp = (int) $part;
            if ($stamp > 0 && ($now - $stamp) < $window) {
                $times[] = $stamp;
            }
        }
        if (count($times) >= $limit) {
            return 'rate';
        }
        $times[] = $now;
        rewind($fh);
        ftruncate($fh, 0);
        fwrite($fh, implode(' ', $times));
        fflush($fh);
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    return null;
}

/**
 * HTTP-grind för de två cron-ingångarna. false betyder att svaret redan är skickat.
 * Feltexten är fast och innehåller inte token.
 */
function jeab_cron_http_gate(callable $tokenOk): bool
{
    jeab_cron_send_headers();
    jeab_cron_capture_query_token();
    $block = jeab_cron_access_block();
    if ($block !== null) {
        unset($GLOBALS['_JEAB_CRON_QUERY_TOKEN']);
        http_response_code($block === 'rate' ? 429 : 403);
        echo json_encode([
            'ok' => false,
            'error' => $block === 'rate' ? 'För många anrop.' : 'Otillåten adress.',
        ], JSON_INVALID_UTF8_SUBSTITUTE);

        return false;
    }
    if (!$tokenOk()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.'], JSON_INVALID_UTF8_SUBSTITUTE);

        return false;
    }

    return true;
}
