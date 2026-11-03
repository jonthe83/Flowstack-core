<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

const JEAB_RESTRICTED_ROLE = 'solkampanj';

/**
 * Restricted logins live beside the password hashes, outside the web root.
 * The file stores name, role and username under a hash fingerprint. Never the code.
 */
function jeab_restricted_users_path(): string
{
    $forced = $GLOBALS['_JEAB_RESTRICTED_USERS_FILE'] ?? '';
    if (is_string($forced) && $forced !== '') {
        return $forced;
    }

    return jeab_outside_privat_dir() . '/restricted-users.json';
}

function jeab_restricted_path_is_private(string $path): bool
{
    $path = str_replace('\\', '/', $path);
    if ($path === '' || str_contains($path, "\0")) {
        return false;
    }
    $dir = is_dir($path) ? $path : dirname($path);
    $realDir = realpath($dir);
    $check = str_replace('\\', '/', $realDir !== false ? $realDir : $dir);
    $web = function_exists('jeab_app_web_root') ? rtrim(str_replace('\\', '/', jeab_app_web_root()), '/') : '';
    if ($web !== '' && ($check === $web || str_starts_with($check, $web . '/'))) {
        return false;
    }
    if ($web !== '' && $realDir === false) {
        $parent = dirname($check);
        $realParent = realpath($parent);
        $parentCheck = str_replace('\\', '/', $realParent !== false ? $realParent : $parent);
        if ($parentCheck === $web || str_starts_with($parentCheck, $web . '/')) {
            return false;
        }
    }

    return true;
}

/** @return array<string, array{name:string, role:string, username:string}> */
function jeab_restricted_read(bool $reload = false): array
{
    static $data;
    static $loaded;
    $path = jeab_restricted_users_path();
    if ($reload || $data === null || $loaded !== $path) {
        $loaded = $path;
        $data = [];
        if (is_file($path) && is_readable($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                foreach ($decoded as $key => $row) {
                    if (!is_string($key) || !is_array($row) || !preg_match('/^fp:[0-9a-f]{16}$/', $key)) {
                        continue;
                    }
                    $data[$key] = [
                        'name' => trim((string) ($row['name'] ?? '')),
                        'role' => trim((string) ($row['role'] ?? '')),
                        'username' => strtolower(trim((string) ($row['username'] ?? ''))),
                    ];
                }
            }
        }
    }

    return $data;
}

/** @param array<string, array{name:string, role:string, username:string}> $rows */
function jeab_restricted_write(array $rows): bool
{
    $path = jeab_restricted_users_path();
    if (!jeab_restricted_path_is_private($path)) {
        return false;
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false;
    }
    if (!jeab_restricted_path_is_private($path)) {
        return false;
    }
    $clean = [];
    foreach ($rows as $key => $row) {
        if (!is_string($key) || !preg_match('/^fp:[0-9a-f]{16}$/', $key) || !is_array($row)) {
            continue;
        }
        $clean[$key] = [
            'name' => trim((string) ($row['name'] ?? '')),
            'role' => trim((string) ($row['role'] ?? '')),
            'username' => strtolower(trim((string) ($row['username'] ?? ''))),
        ];
    }
    $json = json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $tmp = $dir . '/.restricted-' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0600);
    if (!rename($tmp, $path)) {
        @unlink($tmp);

        return false;
    }
    @chmod($path, 0600);
    jeab_restricted_read(true);

    return true;
}

/** @return array{name:string, role:string, username:string, fp:string}|null */
function jeab_restricted_by_fp(?string $fp): ?array
{
    $fp = strtolower(trim((string) $fp));
    if ($fp === '' || !ctype_xdigit($fp) || strlen($fp) !== 16) {
        return null;
    }
    $row = jeab_restricted_read()['fp:' . $fp] ?? null;
    if (!is_array($row) || ($row['role'] ?? '') === '' || ($row['name'] ?? '') === '') {
        return null;
    }
    $row['fp'] = $fp;

    return $row;
}

/** @return array{name:string, role:string, username:string, fp:string}|null */
function jeab_restricted_by_hash(string $hash): ?array
{
    if ($hash === '' || !function_exists('jeab_auth_hash_fingerprint')) {
        return null;
    }

    return jeab_restricted_by_fp(jeab_auth_hash_fingerprint($hash));
}

/** @return array{name:string, role:string, username:string, fp:string}|null */
function jeab_restricted_current(): ?array
{
    if (!function_exists('jeab_auth_ok') || !jeab_auth_ok() || !function_exists('jeab_auth_user_fingerprint')) {
        return null;
    }

    return jeab_restricted_by_fp(jeab_auth_user_fingerprint());
}

function jeab_restricted_is_solkampanj(): bool
{
    $row = jeab_restricted_current();

    return is_array($row) && ($row['role'] ?? '') === JEAB_RESTRICTED_ROLE;
}

function jeab_solkampanj_may_use(): bool
{
    if (function_exists('jeab_is_admin') && jeab_is_admin()) {
        return true;
    }

    return jeab_restricted_is_solkampanj();
}

function jeab_restricted_username(string $name): string
{
    $name = trim($name);
    if (function_exists('mb_strtolower')) {
        $name = mb_strtolower($name, 'UTF-8');
    } else {
        $name = strtolower($name);
    }
    $name = strtr($name, ['ä' => 'a', 'å' => 'a', 'ö' => 'o', 'é' => 'e', 'ü' => 'u', 'á' => 'a']);
    $name = preg_replace('/[^a-z0-9]/', '', $name) ?? '';

    return $name;
}

function jeab_restricted_prefix_clash(string $a, string $b): bool
{
    if ($a === '' || $b === '') {
        return false;
    }

    return $a === $b || str_starts_with($a, $b) || str_starts_with($b, $a);
}

function jeab_restricted_username_taken(string $username, ?string $exceptFp = null): bool
{
    if (function_exists('jeab_people') && function_exists('jeab_person_username')) {
        foreach (jeab_people() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $taken = jeab_person_username($row);
            if (jeab_restricted_prefix_clash($username, $taken)) {
                return true;
            }
        }
    }
    foreach (jeab_restricted_read() as $key => $row) {
        $fp = substr($key, 3);
        if ($exceptFp !== null && $fp !== '' && strlen($fp) === strlen($exceptFp) && hash_equals($fp, $exceptFp)) {
            continue;
        }
        if (jeab_restricted_prefix_clash($username, (string) ($row['username'] ?? ''))) {
            return true;
        }
    }

    return false;
}

/** Sidorollen får öppna: solkampanj och kalkylatorn (kalkyl.php, p=offert, även p=kalkyl). */
function jeab_restricted_request_page(): string
{
    $page = strtolower(trim((string) ($_GET['p'] ?? '')));
    $page = preg_replace('/[^a-z-]/', '', $page) ?? '';
    $page = trim($page, '-');
    if ($page === 'kalkyl') {
        return 'offert';
    }

    return $page;
}

function jeab_restricted_request_script(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

    return strtolower(basename($script));
}

function jeab_restricted_request_basename(): string
{
    $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
    $path = rawurldecode(str_replace('\\', '/', $path));
    $base = strtolower(basename($path));

    return $base === '.' ? '' : $base;
}

/** /kalkyl.php, även när nginx lämnar anropet på index.php utan p. */
function jeab_restricted_kalkyl_entry(): bool
{
    if ((string) ($_GET['graph_hook'] ?? '') === '1' || array_key_exists('validationToken', $_GET)) {
        return false;
    }
    $page = jeab_restricted_request_page();
    if ($page !== '' && $page !== 'offert') {
        return false;
    }
    $script = jeab_restricted_request_script();
    if ($script === 'kalkyl.php') {
        return true;
    }
    if ($script !== 'index.php' && $script !== '') {
        return false;
    }

    return jeab_restricted_request_basename() === 'kalkyl.php';
}

/** Tom /kalkyl.php är kalkylatorn, samma sida som p=offert. */
function jeab_promote_kalkyl_request(): void
{
    if (!jeab_restricted_kalkyl_entry() || jeab_restricted_request_page() !== '') {
        return;
    }
    $_GET['p'] = 'offert';
}

/**
 * allow, redirect (other HTML pages) or deny (hooks and other APIs).
 * Kalkylatorn får sin sida, CSRF-förnyelsen och sparningen av sina egna standardvärden.
 */
function jeab_restricted_decision(): string
{
    if (!jeab_restricted_is_solkampanj()) {
        return 'allow';
    }
    $script = jeab_restricted_request_script();
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $page = jeab_restricted_request_page();
    if ($script === 'solkampanj.php') {
        return 'allow';
    }
    $app = $script === 'index.php' || $script === '' || $script === 'kalkyl.php';
    $calc = $app && ($page === 'offert' || jeab_restricted_kalkyl_entry());
    $csrf = $app && $page === 'csrf';
    if ($app && ($method === 'GET' || $method === 'HEAD')) {
        if ($calc || $csrf) {
            return 'allow';
        }
        if (function_exists('jeab_wants_json') && jeab_wants_json()) {
            return 'deny';
        }

        return 'redirect';
    }
    if ($app && $method === 'POST' && (string) ($_POST['action'] ?? '') === 'offert.defaults' && $calc) {
        return 'allow';
    }
    if ($app) {
        return 'deny';
    }
    $hooks = [
        'hook.php',
        'webshop-hook.php',
        'graph-hook.php',
        'omtago-lead.php',
        'tick.php',
        'sms-cron.php',
        'sms-tick.php',
    ];
    $json = function_exists('jeab_wants_json') && jeab_wants_json();
    if (in_array($script, $hooks, true) || $json || $method === 'POST') {
        return 'deny';
    }

    return 'redirect';
}

/** Leave logout POSTs alone when $allowLogout so index.php can clear the cookie. */
function jeab_restricted_fence(bool $allowLogout = false): void
{
    if (PHP_SAPI === 'cli' && empty($GLOBALS['_JEAB_RESTRICTED_FENCE'])) {
        return;
    }
    $decision = jeab_restricted_decision();
    if ($decision === 'allow') {
        return;
    }
    if ($allowLogout && $decision === 'deny'
        && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST'
        && (string) ($_POST['action'] ?? '') === 'logout') {
        return;
    }
    if ($decision === 'redirect' && function_exists('jeab_redirect') && function_exists('jeab_url')) {
        jeab_redirect(jeab_url('solkampanj.php'), 302);
    }
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => false, 'error' => 'Inte behörig.'], JSON_UNESCAPED_UNICODE);
    exit;
}

function jeab_restricted_clean_name(string $name): string
{
    $name = trim(strip_tags($name));
    $name = preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?? '';
    $name = preg_replace('/\s+/u', ' ', $name) ?? '';

    return trim($name);
}

/**
 * @param array<string, mixed> $result
 * @return array<string, mixed>
 */
function jeab_restricted_scrub(array $result, string $code): array
{
    if ($code === '') {
        return $result;
    }
    $walk = static function (mixed $value) use (&$walk, $code): mixed {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && str_contains($key, $code)) {
                    continue;
                }
                $out[$key] = $walk($item);
            }

            return $out;
        }
        if (is_string($value) && str_contains($value, $code)) {
            return 'borttaget';
        }

        return $value;
    };
    $scrubbed = $walk($result);

    return is_array($scrubbed) ? $scrubbed : ['ok' => false, 'error' => 'Svaret stoppades.'];
}

/**
 * Admin hook users.add_restricted. Dry unless dry=0 and confirm is exactly SAM.
 * The login code is hashed into the existing passwords file and is not returned.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_restricted_add(array $in): array
{
    $code = (string) ($in['code'] ?? '');
    $finish = static function (array $result) use ($code): array {
        unset($result['code'], $result['hash'], $result['password'], $result['secret']);

        return jeab_restricted_scrub($result, $code);
    };
    $role = trim((string) ($in['role'] ?? ''));
    if ($role !== JEAB_RESTRICTED_ROLE) {
        return $finish(['ok' => false, 'error' => 'Rollen kan bara vara solkampanj.', 'status' => 422]);
    }
    $name = jeab_restricted_clean_name((string) ($in['name'] ?? ''));
    if ($name === '' || strlen($name) > 80) {
        return $finish(['ok' => false, 'error' => 'Namnet saknas.', 'status' => 422]);
    }
    $username = jeab_restricted_username($name);
    if (strlen($username) < 3) {
        return $finish(['ok' => false, 'error' => 'Namnet ger inget användarnamn.', 'status' => 422]);
    }
    $codeTrim = trim($code);
    $codeLower = function_exists('mb_strtolower') ? mb_strtolower($codeTrim, 'UTF-8') : strtolower($codeTrim);
    if ($codeTrim === '' || strlen($codeTrim) > 200 || preg_match('/\s/u', $codeTrim) === 1 || preg_match('/[\x00-\x1f\x7f]/', $codeTrim) === 1) {
        return $finish(['ok' => false, 'error' => 'Koden är ogiltig.', 'status' => 422]);
    }
    if (!str_starts_with($codeLower, $username) || strlen($codeTrim) < strlen($username) + 8) {
        return $finish([
            'ok' => false,
            'error' => 'Koden måste börja med användarnamnet och vara längre än det.',
            'username' => $username,
            'status' => 422,
        ]);
    }
    $dryRaw = $in['dry'] ?? 1;
    $dry = !($dryRaw === 0 || $dryRaw === '0' || $dryRaw === false);
    if (!$dry && (string) ($in['confirm'] ?? '') !== 'SAM') {
        return $finish(['ok' => false, 'error' => 'dry=0 kräver confirm=SAM', 'dry' => 1, 'status' => 422]);
    }
    $authPlace = function_exists('jeab_auth_place') ? jeab_auth_place() : null;
    $authWritable = is_array($authPlace) && (
        (($authPlace['source'] ?? '') === 'sql' && !empty($authPlace['ready']))
        || (($authPlace['source'] ?? '') === 'file' && !empty($authPlace['private']))
    );
    if (!$authWritable) {
        return $finish(['ok' => false, 'error' => 'Lösenordsfilen saknas.', 'status' => 400]);
    }
    if (!jeab_restricted_path_is_private(jeab_restricted_users_path())) {
        return $finish(['ok' => false, 'error' => 'Rollen kan inte sparas.', 'status' => 400]);
    }
    $match = function_exists('jeab_auth_match') ? jeab_auth_match($codeTrim) : null;
    $existing = null;
    if (is_array($match)) {
        $existing = jeab_restricted_by_hash((string) ($match['hash'] ?? ''));
        if ($existing === null || ($existing['username'] ?? '') !== $username || ($existing['role'] ?? '') !== JEAB_RESTRICTED_ROLE) {
            return $finish(['ok' => false, 'error' => 'Koden används redan.', 'status' => 409]);
        }
    }
    if (jeab_restricted_username_taken($username, $existing['fp'] ?? null)) {
        return $finish(['ok' => false, 'error' => 'Användarnamnet krockar med en befintlig inloggning.', 'status' => 409]);
    }
    $base = [
        'ok' => true,
        'role' => JEAB_RESTRICTED_ROLE,
        'name' => $name,
        'username' => $username,
    ];
    if ($dry) {
        return $finish($base + [
            'dry' => 1,
            'would_create' => $existing === null ? 1 : 0,
            'already' => $existing === null ? 0 : 1,
            'status' => 200,
        ]);
    }
    if ($existing !== null) {
        $rows = jeab_restricted_read();
        $rows['fp:' . $existing['fp']]['name'] = $name;
        if (!jeab_restricted_write($rows)) {
            return $finish(['ok' => false, 'error' => 'Rollen kunde inte sparas.', 'status' => 500]);
        }

        return $finish($base + ['dry' => 0, 'created' => 0, 'already' => 1, 'status' => 200]);
    }
    $hash = password_hash($codeTrim, PASSWORD_DEFAULT);
    if (!is_string($hash) || $hash === '' || !function_exists('jeab_auth_append_hash') || !jeab_auth_append_hash($hash)) {
        return $finish(['ok' => false, 'error' => 'Lösenordsfilen kunde inte uppdateras.', 'status' => 500]);
    }
    $fp = jeab_auth_hash_fingerprint($hash);
    $rows = jeab_restricted_read();
    $rows['fp:' . $fp] = [
        'name' => $name,
        'role' => JEAB_RESTRICTED_ROLE,
        'username' => $username,
    ];
    if (!jeab_restricted_write($rows)) {
        if (function_exists('jeab_auth_remove_hash')) {
            jeab_auth_remove_hash($hash);
        }

        return $finish(['ok' => false, 'error' => 'Rollen kunde inte sparas.', 'status' => 500]);
    }

    return $finish($base + ['dry' => 0, 'created' => 1, 'already' => 0, 'status' => 200]);
}
