<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Sparad partial-JSON för tunga GET-sidor. Nyckeln är data-stämpel plus
 * adress (utan ws, som byts varje sekund). Träff hoppar över sidmodulerna.
 * CLI och tester är av. Filen ligger i temp med 0600.
 */

function jeab_view_cache_pages(): array
{
    return ['reminders', 'bookings', 'ordrar'];
}

function jeab_view_cache_defer_modules(): bool
{
    if (in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
        return false;
    }
    if (!empty($GLOBALS['_JEAB_VIEW_CACHE_OFF']) || getenv('JEAB_VIEW_CACHE') === '0') {
        return false;
    }
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        return false;
    }
    if ((string) ($_SERVER['HTTP_X_JEAB_PARTIAL'] ?? '') !== '1') {
        return false;
    }
    if (trim((string) ($_GET['intent'] ?? '')) !== '' || trim((string) ($_GET['flash'] ?? '')) !== '') {
        return false;
    }
    if ((string) ($_GET['_t'] ?? '') !== '' || (string) ($_GET['ny'] ?? '') !== '') {
        return false;
    }
    $raw = (string) ($_GET['p'] ?? 'reminders');
    $page = function_exists('jeab_page_slug') ? jeab_page_slug($raw) : $raw;

    return in_array($page, jeab_view_cache_pages(), true);
}

function jeab_view_cache_code(): string
{
    $root = dirname(__DIR__);
    $files = [
        __FILE__,
        __DIR__ . '/reminders.php',
        __DIR__ . '/pages/reminders.php',
        __DIR__ . '/ordrar.php',
        __DIR__ . '/bookings.php',
        __DIR__ . '/pam_desk.php',
        $root . '/assets/css/paminnelser.css',
        $root . '/assets/js/app.js',
        $root . '/index.php',
    ];
    $parts = [];
    foreach ($files as $file) {
        $parts[] = is_file($file) ? (string) filemtime($file) : '0';
    }

    return implode('.', $parts);
}

/** Adress utan ws. ws är time() och gör annars varje svar unikt. */
function jeab_view_cache_query(): string
{
    $q = $_GET;
    unset($q['ws'], $q['_t']);
    ksort($q);
    $encoded = json_encode($q, JSON_UNESCAPED_UNICODE);

    return is_string($encoded) ? $encoded : '';
}

function jeab_view_cache_who_blob(): string
{
    $saved = $_SESSION['jeab_reminder_who'] ?? null;
    if (!is_array($saved)) {
        return '';
    }
    $stable = [];
    foreach ($saved as $company => $row) {
        if (!is_string($company) || !is_array($row)) {
            continue;
        }
        $who = trim((string) ($row['who'] ?? ''));
        if ($who !== '') {
            $stable[$company] = $who;
        }
    }
    ksort($stable);
    $encoded = json_encode($stable, JSON_UNESCAPED_UNICODE);

    return is_string($encoded) ? $encoded : '';
}

function jeab_view_cache_key(string $stamp): string
{
    $page = function_exists('jeab_page_slug')
        ? jeab_page_slug((string) ($_GET['p'] ?? 'reminders'))
        : (string) ($_GET['p'] ?? 'reminders');
    $company = function_exists('jeab_selected_company') ? jeab_selected_company() : '';
    $fp = function_exists('jeab_auth_user_fingerprint') ? (string) jeab_auth_user_fingerprint() : '';
    $day = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
    $raw = implode("\n", [
        $page,
        $company,
        $fp,
        $day,
        jeab_view_cache_code(),
        jeab_view_cache_query(),
        jeab_view_cache_who_blob(),
        $stamp,
    ]);

    return hash('sha256', $raw);
}

function jeab_view_cache_path(string $key): string
{
    return rtrim(sys_get_temp_dir(), '/') . '/jeab-view-' . substr($key, 0, 40);
}

function jeab_view_cache_stamp_sql(PDO $pdo, string $sql, array $args): string
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $row = $stmt->fetch(PDO::FETCH_NUM);
    if (!is_array($row)) {
        return '0';
    }

    return implode('|', array_map(static fn ($v): string => (string) $v, $row));
}

function jeab_view_cache_stamp(object $store, string $page): string
{
    if (!method_exists($store, 'pdo')) {
        return '';
    }
    try {
        $pdo = $store->pdo();
    } catch (Throwable) {
        return '';
    }
    if (!$pdo instanceof PDO) {
        return '';
    }
    $company = function_exists('jeab_selected_company') ? jeab_selected_company() : 'jarfalla';
    try {
        if ($page === 'reminders') {
            return 'r:' . jeab_view_cache_stamp_sql(
                $pdo,
                'SELECT COUNT(*), IFNULL(MAX(updated_at), \'\'), IFNULL(MAX(id), 0) FROM reminders WHERE company = ?',
                [$company]
            );
        }
        if ($page === 'bookings') {
            return 'b:' . jeab_view_cache_stamp_sql(
                $pdo,
                'SELECT COUNT(*), IFNULL(MAX(updated_at), \'\'), IFNULL(MAX(id), 0) FROM bookings',
                []
            );
        }
        if ($page === 'ordrar') {
            return 'o:' . jeab_view_cache_stamp_sql(
                $pdo,
                'SELECT COUNT(*), IFNULL(MAX(updated_at), \'\') FROM purchases',
                []
            );
        }
    } catch (Throwable) {
        return '';
    }

    return '';
}

/** Meny-GET glömmer personvalet. En träff måste göra samma sak. */
function jeab_view_cache_touch_who(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $page = function_exists('jeab_page_slug')
        ? jeab_page_slug((string) ($_GET['p'] ?? 'reminders'))
        : 'reminders';
    if ($page !== 'reminders') {
        return;
    }
    $company = function_exists('jeab_selected_company') ? jeab_selected_company() : 'jarfalla';
    $menu = true;
    foreach (['who', 'v', 'tag', 'q', 'open', 'n', 'flash', 'intent'] as $key) {
        if (array_key_exists($key, $_GET)) {
            $menu = false;
            break;
        }
    }
    if ($menu) {
        unset($_SESSION['jeab_reminder_who'][$company]);

        return;
    }
    $saved = $_SESSION['jeab_reminder_who'][$company] ?? null;
    if (!is_array($saved)) {
        return;
    }
    $who = trim((string) ($saved['who'] ?? ''));
    $at = (int) ($saved['at'] ?? 0);
    $ttl = defined('JEAB_REMINDER_WHO_TTL') ? (int) JEAB_REMINDER_WHO_TTL : 1800;
    if ($who === '' || $at <= 0 || time() - $at > $ttl) {
        unset($_SESSION['jeab_reminder_who'][$company]);

        return;
    }
    $_SESSION['jeab_reminder_who'][$company]['at'] = time();
}

function jeab_view_cache_prepare(object $store): void
{
    unset($GLOBALS['_JEAB_VIEW_CACHE_KEY'], $GLOBALS['_JEAB_VIEW_CACHE_STAMP']);
    if (!jeab_view_cache_defer_modules()) {
        return;
    }
    $page = function_exists('jeab_page_slug')
        ? jeab_page_slug((string) ($_GET['p'] ?? 'reminders'))
        : 'reminders';
    $stamp = jeab_view_cache_stamp($store, $page);
    if ($stamp === '') {
        return;
    }
    $GLOBALS['_JEAB_VIEW_CACHE_STAMP'] = $stamp;
    $GLOBALS['_JEAB_VIEW_CACHE_KEY'] = jeab_view_cache_key($stamp);
}

function jeab_view_cache_serve(object $store): bool
{
    jeab_view_cache_prepare($store);
    $stamp = $GLOBALS['_JEAB_VIEW_CACHE_STAMP'] ?? '';
    if (!is_string($stamp) || $stamp === '') {
        return false;
    }
    jeab_view_cache_touch_who();
    $key = jeab_view_cache_key($stamp);
    $GLOBALS['_JEAB_VIEW_CACHE_KEY'] = $key;
    $file = jeab_view_cache_path($key);
    if (!is_file($file) || (time() - (int) filemtime($file)) > 45) {
        return false;
    }
    $raw = file_get_contents($file);
    $prefix = $stamp . "\n";
    if (!is_string($raw) || !str_starts_with($raw, $prefix)) {
        return false;
    }
    $json = substr($raw, strlen($prefix));
    if ($json === '' || $json[0] !== '{') {
        return false;
    }
    if (function_exists('jeab_release_session')) {
        jeab_release_session();
    }
    if (function_exists('jeab_timing_span')) {
        jeab_timing_span('view_cache', 0.001);
    }
    if (function_exists('jeab_partial_json_send')) {
        jeab_partial_json_send($json, ['X-Jeab-View-Cache: hit']);

        return true;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Referrer-Policy: same-origin');
    header('X-Jeab-Partial: 1');
    header('X-Jeab-View-Cache: hit');
    echo $json;

    return true;
}

function jeab_view_cache_store(string $json): void
{
    $stamp = $GLOBALS['_JEAB_VIEW_CACHE_STAMP'] ?? '';
    if (!is_string($stamp) || $stamp === '' || $json === '' || $json[0] !== '{') {
        return;
    }
    $key = jeab_view_cache_key($stamp);
    if (strlen($json) > 1500000 || str_contains($json, 'class=\\"flash\\"') || str_contains($json, 'class="flash"')) {
        return;
    }
    $file = jeab_view_cache_path($key);
    if (file_put_contents($file, $stamp . "\n" . $json, LOCK_EX) !== false) {
        @chmod($file, 0600);
    }
}
