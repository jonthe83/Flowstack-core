<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/tinyfm_allow.php';
const JEAB_AUTH_COOKIE = 'jeab_auth';
// 90 dagar. Chrome kan kapa visningen till ~400 dagar.
const JEAB_AUTH_TTL = 90 * 24 * 60 * 60;

function jeab_public_script(): bool
{
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    // hook.php keeps its own token. TinyFM (filer.php) goes through jeab_gate().
    return $script === 'hook.php' || $script === 'webshop-hook.php' || $script === 'graph-hook.php' || $script === 'omtago-lead.php';
}

/**
 * Collapse "." and ".." without asking the filesystem.
 * realpath() on shared hosts often fails for a readable privat/ file when the
 * path steps through public_html/.. while open_basedir is set.
 */
function jeab_auth_normalize_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $absolute = str_starts_with($path, '/');
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            if ($parts !== [] && end($parts) !== '..') {
                array_pop($parts);
            } elseif (!$absolute) {
                $parts[] = '..';
            }
            continue;
        }
        $parts[] = $part;
    }
    $out = implode('/', $parts);

    return $absolute ? '/' . $out : $out;
}

function jeab_auth_path_inside(string $file, string $dir): bool
{
    $file = rtrim(jeab_auth_normalize_path($file), '/');
    $dir = rtrim(jeab_auth_normalize_path($dir), '/');
    if ($file === '' || $dir === '' || $dir === '/') {
        return false;
    }

    return $file === $dir || str_starts_with($file, $dir . '/');
}

function jeab_auth_web_root_canonical(): string
{
    $web = function_exists('jeab_app_web_root') ? jeab_app_web_root() : '';
    if ($web === '' && defined('JEAB_ROOT')) {
        $web = JEAB_ROOT;
    }
    $web = str_replace('\\', '/', $web);
    $real = $web !== '' ? realpath($web) : false;
    $use = is_string($real) && $real !== '' ? $real : $web;

    return rtrim(jeab_auth_normalize_path($use), '/');
}

/** @return list<string> */
function jeab_auth_search_roots(): array
{
    $roots = [];
    $web = jeab_auth_web_root_canonical();
    if ($web !== '') {
        $roots[] = $web;
    }
    if (defined('JEAB_ROOT')) {
        $root = str_replace('\\', '/', JEAB_ROOT);
        $real = realpath(JEAB_ROOT);
        $root = is_string($real) && $real !== '' ? $real : $root;
        $root = rtrim(jeab_auth_normalize_path($root), '/');
        if ($root !== '') {
            $roots[] = $root;
        }
    }

    return array_values(array_unique($roots));
}

/**
 * privat/ next to public_html. JEAB_ROOT is "src/..", so dirname(JEAB_ROOT)
 * stays inside src and never sees the sibling folder login is supposed to use.
 *
 * @return list<string>
 */
function jeab_auth_privat_dirs(): array
{
    if (function_exists('jeab_demo_mode') && jeab_demo_mode()) {
        return [];
    }
    $dirs = [];
    foreach (jeab_auth_search_roots() as $root) {
        if (is_dir($root . '/public_html')) {
            $dirs[] = $root . '/privat';
        }
        $parent = dirname($root);
        if ($parent !== '' && $parent !== '.' && $parent !== '/' && $parent !== $root) {
            $dirs[] = $parent . '/privat';
        }
    }

    return array_values(array_unique($dirs));
}

/** @return list<string> */
function jeab_auth_candidate_paths(): array
{
    $paths = [];
    foreach (jeab_auth_search_roots() as $root) {
        $paths[] = $root . '/data/.private/passwords.json';
    }
    foreach (jeab_auth_privat_dirs() as $dir) {
        $paths[] = $dir . '/passwords.json';
        $paths[] = $dir . '/.passwords.json';
    }

    return array_values(array_unique($paths));
}

function jeab_auth_canonical_file(string $path): string
{
    $path = str_replace('\\', '/', $path);
    if ($path === '' || str_contains($path, "\0")) {
        return '';
    }
    if (is_link($path)) {
        $real = realpath($path);
        if (!is_string($real) || $real === '') {
            return '';
        }

        return jeab_auth_normalize_path($real);
    }
    $real = realpath($path);
    if (is_string($real) && $real !== '') {
        return jeab_auth_normalize_path($real);
    }

    return jeab_auth_normalize_path($path);
}

/** True when this file is the store login will actually accept. */
function jeab_auth_file_is_login_store(string $path): bool
{
    if ($path === '' || !is_file($path)) {
        return false;
    }
    $data = jeab_auth_read_file($path);

    return $data['secret'] !== '' && $data['hashes'] !== [];
}

function jeab_auth_follow_private(string $path): string
{
    if ($path === '' || !is_link($path)) {
        return $path;
    }
    $real = realpath($path);
    if (!is_string($real) || $real === '') {
        return $path;
    }
    $real = str_replace('\\', '/', $real);

    return jeab_auth_path_is_private($real) ? $real : $path;
}

function jeab_auth_path_kind(string $path): string
{
    if ($path === '' || !is_file($path)) {
        return 'missing';
    }
    $check = jeab_auth_canonical_file($path);
    if ($check === '') {
        return 'missing';
    }
    foreach (jeab_auth_privat_dirs() as $dir) {
        if (jeab_auth_path_inside($check, $dir)) {
            return 'privat';
        }
    }
    if (preg_match('#/data/\\.private(?:/|$)#', $check) === 1) {
        return 'data_private';
    }

    return 'other';
}

/**
 * The password store login reads. File only: there is no SQL user table.
 * Env and config win, same as before. Among discovered files, a private
 * store that login can verify wins over a copy inside the web root.
 *
 * @return array{path:string,via:string,source:string,path_kind:string,private:int,exists:int,ready:int}
 */
function jeab_auth_place(): array
{
    $env = getenv('JEAB_PASSWORDS_FILE');
    if ($env !== false && $env !== '') {
        return jeab_auth_describe($env, 'env');
    }
    $fromConfig = trim((string) (jeab_config()['passwords_file'] ?? ''));
    if ($fromConfig !== '') {
        return jeab_auth_describe($fromConfig, 'config');
    }
    $existing = [];
    foreach (jeab_auth_candidate_paths() as $path) {
        if (is_file($path)) {
            $existing[] = $path;
        }
    }
    $pick = null;
    foreach ($existing as $path) {
        $followed = jeab_auth_follow_private($path);
        if (jeab_auth_path_is_private($followed) && jeab_auth_file_is_login_store($followed)) {
            $pick = $followed;
            break;
        }
    }
    if ($pick === null) {
        foreach ($existing as $path) {
            if (jeab_auth_file_is_login_store($path)) {
                $pick = $path;
                break;
            }
        }
    }
    if ($pick === null) {
        foreach ($existing as $path) {
            $followed = jeab_auth_follow_private($path);
            if (jeab_auth_path_is_private($followed)) {
                $pick = $followed;
                break;
            }
        }
    }
    if ($pick === null && $existing !== []) {
        $pick = $existing[0];
    }
    if ($pick === null) {
        $pick = '';
        foreach (jeab_auth_privat_dirs() as $dir) {
            $pick = $dir . '/passwords.json';
            break;
        }
        if ($pick === '') {
            $candidates = jeab_auth_candidate_paths();
            $pick = $candidates[0] ?? '';
        }
    }

    return jeab_auth_describe($pick, 'candidate');
}

/**
 * @return array{path:string,via:string,source:string,path_kind:string,private:int,exists:int,ready:int}
 */
function jeab_auth_describe(string $path, string $via): array
{
    $followed = jeab_auth_follow_private($path);
    $use = ($followed !== $path && jeab_auth_path_is_private($followed)) ? $followed : $path;

    return [
        'path' => $use,
        'via' => $via,
        'source' => 'file',
        'path_kind' => jeab_auth_path_kind($use),
        'private' => jeab_auth_path_is_private($use) ? 1 : 0,
        'exists' => is_file($use) ? 1 : 0,
        'ready' => jeab_auth_file_is_login_store($use) ? 1 : 0,
    ];
}

function jeab_auth_path(): string
{
    return jeab_auth_place()['path'];
}

/**
 * Read-only description of the store login uses.
 * No path, no hashes, no secret, no code.
 *
 * @return array{ok:bool,source:string,path_kind:string,via:string,private:int,exists:int,ready:int,status:int}
 */
function jeab_auth_diag(): array
{
    $place = jeab_auth_place();

    return [
        'ok' => true,
        'source' => (string) ($place['source'] ?? 'file'),
        'path_kind' => (string) ($place['path_kind'] ?? 'missing'),
        'via' => (string) ($place['via'] ?? ''),
        'private' => !empty($place['private']) ? 1 : 0,
        'exists' => !empty($place['exists']) ? 1 : 0,
        'ready' => jeab_auth_ready() ? 1 : 0,
        'status' => 200,
    ];
}

function jeab_auth_apcu_key(string $path): string
{
    return 'jeab_auth1_' . hash('sha256', $path);
}

function jeab_auth_data(bool $reload = false): array
{
    static $data;
    static $loadedPath;
    $path = jeab_auth_path();
    $key = $path !== '' ? jeab_auth_apcu_key($path) : '';
    if ($reload && $key !== '' && function_exists('apcu_delete')) {
        apcu_delete($key);
    }
    if ($reload) {
        jeab_auth_memo_forget();
    }
    if (!$reload && $data !== null && $loadedPath === $path) {
        return function_exists('jeab_demo_auth_overlay') ? jeab_demo_auth_overlay($data) : $data;
    }
    if ($loadedPath !== null && $loadedPath !== $path) {
        jeab_auth_memo_forget();
    }
    if (!$reload && $key !== '' && function_exists('apcu_fetch')) {
        $ok = false;
        $hit = apcu_fetch($key, $ok);
        if ($ok && is_array($hit) && isset($hit['hashes']) && is_array($hit['hashes'])) {
            $loadedPath = $path;
            $data = $hit;

            return function_exists('jeab_demo_auth_overlay') ? jeab_demo_auth_overlay($data) : $data;
        }
    }
    $loadedPath = $path;
    $data = jeab_auth_read_file($path);
    if ($key !== '' && function_exists('apcu_store')) {
        apcu_store($key, $data, 60);
    }

    return function_exists('jeab_demo_auth_overlay') ? jeab_demo_auth_overlay($data) : $data;
}

function jeab_auth_read_file(string $path): array
{
    $empty = ['secret' => '', 'hashes' => []];
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return $empty;
    }
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $empty;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $empty;
    }
    if (!isset($decoded['hashes']) && !isset($decoded['secret'])) {
        return [
            'secret' => '',
            'hashes' => jeab_auth_normalize_hashes($decoded),
            'entries' => jeab_auth_normalize_entries($decoded),
        ];
    }
    $hashes = $decoded['hashes'] ?? [];
    $raw = is_array($hashes) ? $hashes : [];
    return [
        'secret' => trim((string) ($decoded['secret'] ?? '')),
        'hashes' => jeab_auth_normalize_hashes($raw),
        'entries' => jeab_auth_normalize_entries($raw),
    ];
}

function jeab_auth_normalize_hashes(array $raw): array
{
    $out = [];
    foreach ($raw as $item) {
        if (is_array($item)) {
            $hash = trim((string) ($item['hash'] ?? ''));
        } else {
            $hash = trim((string) $item);
        }
        if ($hash !== '') {
            $out[] = $hash;
        }
    }
    return $out;
}

function jeab_auth_normalize_entries(array $raw): array
{
    $out = [];
    foreach ($raw as $key => $item) {
        $email = '';
        $hash = '';
        if (is_array($item)) {
            $hash = trim((string) ($item['hash'] ?? ''));
            $email = trim((string) ($item['email'] ?? ''));
        } else {
            $hash = trim((string) $item);
            if (is_string($key) && !is_numeric($key) && str_contains($key, '@')) {
                $email = trim($key);
            }
        }
        if ($hash === '') {
            continue;
        }
        $out[] = [
            'email' => jeab_normalize_person_email($email) ?? '',
            'hash' => $hash,
        ];
    }
    return $out;
}

function jeab_auth_entries(bool $reload = false): array
{
    return jeab_auth_data($reload)['entries'] ?? [];
}

function jeab_auth_verify_count(): int
{
    return (int) ($GLOBALS['_JEAB_AUTH_VERIFY_COUNT'] ?? 0);
}

function jeab_auth_password_verify(string $password, string $hash): bool
{
    if ($hash === '') {
        return false;
    }
    $GLOBALS['_JEAB_AUTH_VERIFY_COUNT'] = jeab_auth_verify_count() + 1;

    return password_verify($password, $hash);
}

/** HMAC av lösenordets sha256. Själva koden sparas inte. */
function jeab_auth_match_mac(string $password): string
{
    $secret = jeab_auth_secret();
    if ($secret === '' || $password === '') {
        return '';
    }

    return hash_hmac('sha256', hash('sha256', $password), $secret);
}

/**
 * Redan godkänd kod i den här requesten eller sessionen. bcrypt körs inte igen.
 */
function jeab_auth_match_fast(string $password): ?array
{
    $mac = jeab_auth_match_mac($password);
    if ($mac === '') {
        return null;
    }
    $saved = $GLOBALS['_JEAB_AUTH_MATCH_FAST'] ?? null;
    if (!is_array($saved) && (session_status() === PHP_SESSION_ACTIVE || !empty($GLOBALS['_JEAB_SESSION_RELEASED']))) {
        $saved = $_SESSION['jeab_auth_fast'] ?? null;
    }
    if (!is_array($saved)) {
        return null;
    }
    $known = (string) ($saved['mac'] ?? '');
    if ($known === '' || strlen($known) !== strlen($mac) || !hash_equals($known, $mac)) {
        return null;
    }
    $entry = jeab_auth_entry_by_fingerprint((string) ($saved['fp'] ?? ''));
    if (!is_array($entry)) {
        return null;
    }
    $GLOBALS['_JEAB_AUTH_MATCH_FAST'] = ['fp' => (string) ($saved['fp'] ?? ''), 'mac' => $mac];

    return $entry;
}

function jeab_auth_match_remember(string $password, array $entry): void
{
    $hash = (string) ($entry['hash'] ?? '');
    $fp = jeab_auth_hash_fingerprint($hash);
    if ($fp === '') {
        return;
    }
    $mac = jeab_auth_match_mac($password);
    if ($mac === '') {
        return;
    }
    $fast = ['fp' => $fp, 'mac' => $mac];
    $GLOBALS['_JEAB_AUTH_MATCH_FAST'] = $fast;
    $head = function_exists('jeab_code_head') ? jeab_code_head($password) : substr(trim($password), 0, 9);
    if ($head !== '') {
        if (!isset($GLOBALS['_JEAB_AUTH_HEAD_FP']) || !is_array($GLOBALS['_JEAB_AUTH_HEAD_FP'])) {
            $GLOBALS['_JEAB_AUTH_HEAD_FP'] = [];
        }
        if (!isset($GLOBALS['_JEAB_AUTH_HEAD_FP'][$head]) || !is_array($GLOBALS['_JEAB_AUTH_HEAD_FP'][$head])) {
            $GLOBALS['_JEAB_AUTH_HEAD_FP'][$head] = [];
        }
        $GLOBALS['_JEAB_AUTH_HEAD_FP'][$head][$fp] = true;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['jeab_auth_fast'] = $fast;
    }
}

/**
 * Kandidater vars sparade huvud (9 tecken, inte hemligt) är exakt det här.
 * Ett ensamt Sam… är inte SamL eller SamW.
 *
 * @return list<array{email?:string,hash?:string}>
 */
function jeab_auth_entries_for_head(string $head): array
{
    $head = function_exists('jeab_code_head') ? jeab_code_head($head) : substr(trim($head), 0, 9);
    if ($head === '') {
        return [];
    }
    $fps = [];
    $mem = $GLOBALS['_JEAB_AUTH_HEAD_FP'][$head] ?? null;
    if (is_array($mem)) {
        foreach ($mem as $fp => $on) {
            if ($on && is_string($fp) && preg_match('/\A[0-9a-f]{16}\z/', $fp) === 1) {
                $fps[$fp] = true;
            }
        }
    }
    if (function_exists('jeab_code_people_read')) {
        $all = jeab_code_people_read(false);
        if (is_array($all)) {
            foreach ($all as $key => $row) {
                if (!is_string($key) || !str_starts_with($key, 'fp:') || !is_array($row)) {
                    continue;
                }
                $stored = function_exists('jeab_code_head') ? jeab_code_head((string) ($row['head'] ?? '')) : '';
                $storedKey = function_exists('jeab_person_code_probe') ? jeab_person_code_probe($stored) : $stored;
                $wantKey = function_exists('jeab_person_code_probe') ? jeab_person_code_probe($head) : $head;
                if ($storedKey === '' || $wantKey === '' || $storedKey !== $wantKey) {
                    continue;
                }
                $fp = strtolower(substr($key, 3));
                if (preg_match('/\A[0-9a-f]{16}\z/', $fp) === 1) {
                    $fps[$fp] = true;
                }
            }
        }
    }
    $out = [];
    foreach (array_keys($fps) as $fp) {
        $entry = jeab_auth_entry_by_fingerprint($fp);
        if (is_array($entry)) {
            $out[] = $entry;
        }
    }

    return $out;
}

function jeab_auth_match_scan(string $password): ?array
{
    $head = function_exists('jeab_code_head') ? jeab_code_head($password) : substr(trim($password), 0, 9);
    $seen = [];
    foreach (jeab_auth_entries_for_head($head) as $entry) {
        $hash = (string) ($entry['hash'] ?? '');
        $fp = jeab_auth_hash_fingerprint($hash);
        if ($fp !== '') {
            $seen[$fp] = true;
        }
        if (jeab_auth_password_verify($password, $hash)) {
            return $entry;
        }
    }
    foreach (jeab_auth_entries() as $entry) {
        $hash = (string) ($entry['hash'] ?? '');
        $fp = jeab_auth_hash_fingerprint($hash);
        if ($fp !== '' && isset($seen[$fp])) {
            continue;
        }
        if (jeab_auth_password_verify($password, $hash)) {
            return $entry;
        }
    }

    return null;
}

function jeab_auth_match(string $password): ?array
{
    if ($password === '' || !jeab_auth_ready()) {
        return null;
    }
    $fast = jeab_auth_match_fast($password);
    if (is_array($fast)) {
        return $fast;
    }
    $matched = jeab_auth_match_scan($password);
    if (is_array($matched)) {
        jeab_auth_match_remember($password, $matched);
    }

    return $matched;
}

function jeab_auth_hashes(bool $reload = false): array
{
    return jeab_auth_data($reload)['hashes'];
}

function jeab_auth_secret(bool $reload = false): string
{
    return jeab_auth_data($reload)['secret'];
}

function jeab_auth_ready(bool $reload = false): bool
{
    $data = jeab_auth_data($reload);
    return $data['secret'] !== '' && $data['hashes'] !== [];
}

function jeab_auth_password_ok(string $password): bool
{
    return jeab_auth_match($password) !== null;
}

function jeab_auth_hash_fingerprint(string $hash): string
{
    $hash = trim($hash);
    if ($hash === '') {
        return '';
    }
    return substr(hash('sha256', $hash), 0, 16);
}

/**
 * True when the file is outside the web root. data/.private is not outside.
 * A symlink counts only as its target.
 */
function jeab_auth_path_is_outside_web(string $path): bool
{
    $path = str_replace('\\', '/', $path);
    if ($path === '' || str_contains($path, "\0") || !is_file($path)) {
        return false;
    }
    $check = jeab_auth_canonical_file($path);
    if ($check === '') {
        return false;
    }
    $web = jeab_auth_web_root_canonical();
    if ($web !== '' && is_dir($web . '/public_html')) {
        if (jeab_auth_path_inside($check, $web . '/public_html')) {
            return false;
        }
        if (jeab_auth_path_inside($check, $web . '/privat')) {
            return true;
        }
    }
    if ($web !== '' && jeab_auth_path_inside($check, $web)) {
        return false;
    }

    return true;
}

function jeab_auth_is_login_data_private_file(string $check): bool
{
    return preg_match('#/data/\\.private/(?:\\.)?passwords\\.json$#', $check) === 1;
}

function jeab_auth_forced_passwords_path(): string
{
    $env = getenv('JEAB_PASSWORDS_FILE');
    if ($env !== false && $env !== '') {
        return $env;
    }

    return trim((string) (jeab_config()['passwords_file'] ?? ''));
}

function jeab_auth_same_file(string $a, string $b): bool
{
    if ($a === '' || $b === '') {
        return false;
    }
    $left = jeab_auth_canonical_file($a);
    $right = jeab_auth_canonical_file($b);

    return $left !== '' && $left === $right;
}

/** Another candidate outside the web root is the store login would pick first. */
function jeab_auth_outside_login_store_exists(string $exceptCheck): bool
{
    foreach (jeab_auth_candidate_paths() as $candidate) {
        if (!is_file($candidate)) {
            continue;
        }
        $check = jeab_auth_canonical_file($candidate);
        if ($check === '' || $check === $exceptCheck) {
            continue;
        }
        if (jeab_auth_path_is_outside_web($candidate) && jeab_auth_file_is_login_store($candidate)) {
            return true;
        }
    }

    return false;
}

function jeab_auth_htaccess_denies(string $file): bool
{
    if ($file === '' || !is_file($file) || !is_readable($file)) {
        return false;
    }
    $text = (string) file_get_contents($file);

    return preg_match('/Require\s+all\s+denied/i', $text) === 1
        || preg_match('/Deny\s+from\s+all/i', $text) === 1;
}

function jeab_auth_deny_htaccess_block(): string
{
    return "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
}

/**
 * data/.private/.htaccess. Create it when missing. An existing file is left
 * as-is when it already denies, otherwise the deny block is appended.
 * Nothing already in the file is removed.
 */
function jeab_auth_ensure_data_private_htaccess(string $dir): bool
{
    $dir = rtrim(str_replace('\\', '/', $dir), '/');
    if ($dir === '' || !preg_match('#/data/\\.private$#', $dir) || !is_dir($dir)) {
        return false;
    }
    $path = $dir . '/.htaccess';
    $parent = dirname($dir) . '/.htaccess';
    if (is_link($path)) {
        return jeab_auth_htaccess_denies($path) || jeab_auth_htaccess_denies($parent);
    }
    if (!is_file($path)) {
        if (file_put_contents($path, jeab_auth_deny_htaccess_block(), LOCK_EX) !== false) {
            @chmod($path, 0644);
        }
    } elseif (!jeab_auth_htaccess_denies($path)) {
        $existing = (string) file_get_contents($path);
        $sep = ($existing !== '' && !str_ends_with($existing, "\n")) ? "\n" : '';
        file_put_contents($path, $existing . $sep . jeab_auth_deny_htaccess_block(), LOCK_EX);
    }

    return jeab_auth_htaccess_denies($path) || jeab_auth_htaccess_denies($parent);
}

/**
 * True when login may write this passwords file.
 * privat/ beside public_html is private. So is data/.private/passwords.json
 * when that file is the store login uses and data/.private has a deny
 * .htaccess (Require all denied or Deny from all). Other files inside
 * public_html stay blocked. A symlink counts only as its target.
 */
function jeab_auth_path_is_private(string $path): bool
{
    if (jeab_auth_path_is_outside_web($path)) {
        return true;
    }
    $path = str_replace('\\', '/', $path);
    if ($path === '' || str_contains($path, "\0") || !is_file($path)) {
        return false;
    }
    $check = jeab_auth_canonical_file($path);
    if ($check === '' || !jeab_auth_is_login_data_private_file($check)) {
        return false;
    }
    $forced = jeab_auth_forced_passwords_path();
    if ($forced !== '') {
        if (!jeab_auth_same_file($forced, $path) || !jeab_auth_file_is_login_store($path)) {
            return false;
        }
    } elseif (jeab_auth_outside_login_store_exists($check) || !jeab_auth_file_is_login_store($path)) {
        return false;
    }

    return jeab_auth_ensure_data_private_htaccess(dirname($path));
}

/**
 * Append one password hash to the existing privat passwords file.
 * The plaintext is never passed here and is never written.
 */
function jeab_auth_append_hash(string $hash): bool
{
    $hash = trim($hash);
    if ($hash === '' || !str_starts_with($hash, '$') || strlen($hash) < 20) {
        return false;
    }
    $path = jeab_auth_path();
    if (!jeab_auth_path_is_private($path)) {
        return false;
    }
    $raw = json_decode((string) file_get_contents($path), true);
    if (!is_array($raw) || !isset($raw['hashes']) || !is_array($raw['hashes'])) {
        return false;
    }
    foreach ($raw['hashes'] as $item) {
        $existing = is_array($item) ? trim((string) ($item['hash'] ?? '')) : trim((string) $item);
        if ($existing !== '' && strlen($existing) === strlen($hash) && hash_equals($existing, $hash)) {
            jeab_auth_data(true);

            return true;
        }
    }
    $raw['hashes'][] = ['hash' => $hash];
    $json = json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $tmp = dirname($path) . '/.pw-' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0600);
    if (!rename($tmp, $path)) {
        @unlink($tmp);

        return false;
    }
    @chmod($path, 0600);
    jeab_auth_data(true);

    return true;
}

/** Drop one unlabeled hash. Used only to roll back a restricted user that did not finish saving. */
function jeab_auth_remove_hash(string $hash): bool
{
    $hash = trim($hash);
    if ($hash === '' || !str_starts_with($hash, '$')) {
        return false;
    }
    $path = jeab_auth_path();
    if (!jeab_auth_path_is_private($path)) {
        return false;
    }
    $raw = json_decode((string) file_get_contents($path), true);
    if (!is_array($raw) || !isset($raw['hashes']) || !is_array($raw['hashes'])) {
        return false;
    }
    $next = [];
    $removed = false;
    foreach ($raw['hashes'] as $item) {
        $existing = is_array($item) ? trim((string) ($item['hash'] ?? '')) : trim((string) $item);
        $email = is_array($item) ? trim((string) ($item['email'] ?? '')) : '';
        if (!$removed && $email === '' && $existing !== '' && strlen($existing) === strlen($hash) && hash_equals($existing, $hash)) {
            $removed = true;
            continue;
        }
        $next[] = $item;
    }
    if (!$removed) {
        return false;
    }
    $raw['hashes'] = $next;
    $json = json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $tmp = dirname($path) . '/.pw-' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0600);
    if (!rename($tmp, $path)) {
        @unlink($tmp);

        return false;
    }
    @chmod($path, 0600);
    jeab_auth_data(true);

    return true;
}

/** Glömmer kak-svar för den här förfrågan. Anropas när lösenordsfilen eller epoch läses om. */
function jeab_auth_memo_forget(): void
{
    unset(
        $GLOBALS['_JEAB_AUTH_PAYLOAD'],
        $GLOBALS['_JEAB_AUTH_FP_ENTRY'],
        $GLOBALS['_JEAB_AUTH_EMAIL'],
        $GLOBALS['_JEAB_AUTH_OK'],
        $GLOBALS['_JEAB_CSRF_SIGNED'],
        $GLOBALS['_JEAB_CSRF_FIELD']
    );
}

function jeab_auth_entry_by_fingerprint(string $fp): ?array
{
    $fp = strtolower(trim($fp));
    if ($fp === '' || !ctype_xdigit($fp) || strlen($fp) !== 16) {
        return null;
    }
    $memo = $GLOBALS['_JEAB_AUTH_FP_ENTRY'] ?? null;
    if (is_array($memo) && array_key_exists($fp, $memo)) {
        $hit = $memo[$fp];

        return is_array($hit) ? $hit : null;
    }
    $found = null;
    foreach (jeab_auth_entries() as $entry) {
        $hash = (string) ($entry['hash'] ?? '');
        if ($hash !== '' && hash_equals(jeab_auth_hash_fingerprint($hash), $fp)) {
            $found = $entry;
            break;
        }
    }
    if (!isset($GLOBALS['_JEAB_AUTH_FP_ENTRY']) || !is_array($GLOBALS['_JEAB_AUTH_FP_ENTRY'])) {
        $GLOBALS['_JEAB_AUTH_FP_ENTRY'] = [];
    }
    $GLOBALS['_JEAB_AUTH_FP_ENTRY'][$fp] = $found ?? false;

    return $found;
}

function jeab_auth_entry_by_email(?string $email): ?array
{
    $email = jeab_normalize_person_email($email);
    if ($email === null) {
        return null;
    }
    $found = null;
    foreach (jeab_auth_entries() as $entry) {
        $entryEmail = jeab_normalize_person_email((string) ($entry['email'] ?? ''));
        if ($entryEmail === null || $entryEmail !== $email) {
            continue;
        }
        if ($found !== null) {
            return null;
        }
        $found = $entry;
    }
    return $found;
}

function jeab_auth_email_listed(?string $email): bool
{
    $email = jeab_normalize_person_email($email);
    if ($email === null) {
        return false;
    }
    foreach (jeab_auth_entries() as $entry) {
        $entryEmail = jeab_normalize_person_email((string) ($entry['email'] ?? ''));
        if ($entryEmail !== null && $entryEmail === $email) {
            return true;
        }
    }
    return false;
}

function jeab_auth_issue_value(?string $email = null, ?string $hash = null): ?string
{
    $secret = jeab_auth_secret();
    if ($secret === '') {
        return null;
    }
    $hash = trim((string) $hash);
    $fp = $hash !== '' ? jeab_auth_hash_fingerprint($hash) : '';
    if ($fp === '') {
        return null;
    }
    $email = jeab_normalize_person_email($email);
    $enc = $email !== null
        ? rtrim(strtr(base64_encode($email), '+/', '-_'), '=')
        : '-';
    // Utfärdad tid får inte ligga före utloggningen. auth_epoch gäller alla
    // koder (logout_all). Kodens egen gräns gäller en enskild utloggning.
    $now = jeab_auth_now();
    $floor = max(jeab_auth_generation_for($fp), jeab_auth_epoch());
    $issued = max($now, $floor);
    $payload = '3.' . $fp . '.' . $enc . '.' . (string) $issued . '.' . bin2hex(random_bytes(8));
    return $payload . '.' . hash_hmac('sha256', $payload, $secret);
}

function jeab_auth_payload(string $value): ?string
{
    if ($value === '') {
        return null;
    }
    $memo = $GLOBALS['_JEAB_AUTH_PAYLOAD'] ?? null;
    if (is_array($memo) && array_key_exists($value, $memo)) {
        $cached = $memo[$value];

        return is_string($cached) ? $cached : null;
    }
    $secret = jeab_auth_secret();
    $payload = null;
    if ($secret !== '') {
        $pos = strrpos($value, '.');
        if ($pos !== false) {
            $body = substr($value, 0, $pos);
            $sig = substr($value, $pos + 1);
            if ($body !== '' && $sig !== '' && hash_equals(hash_hmac('sha256', $body, $secret), $sig)) {
                $payload = $body;
            }
        }
    }
    if (!isset($GLOBALS['_JEAB_AUTH_PAYLOAD']) || !is_array($GLOBALS['_JEAB_AUTH_PAYLOAD'])) {
        $GLOBALS['_JEAB_AUTH_PAYLOAD'] = [];
    }
    $GLOBALS['_JEAB_AUTH_PAYLOAD'][$value] = $payload ?? false;

    return $payload;
}

function jeab_auth_decode_email_part(string $part): ?string
{
    $part = trim($part);
    if ($part === '' || $part === '-') {
        return null;
    }
    $b64 = strtr($part, '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad !== 0) {
        $b64 .= str_repeat('=', 4 - $pad);
    }
    $email = base64_decode($b64, true);
    return is_string($email) ? jeab_normalize_person_email($email) : null;
}

function jeab_auth_issued_at(string $payload): ?int
{
    $parts = explode('.', $payload);
    $ver = $parts[0] ?? '';
    if ($ver === '1' && isset($parts[1]) && ctype_digit($parts[1])) {
        return (int) $parts[1];
    }
    if ($ver === '2' && isset($parts[2]) && ctype_digit($parts[2])) {
        return (int) $parts[2];
    }
    if ($ver === '3' && isset($parts[3]) && ctype_digit($parts[3])) {
        return (int) $parts[3];
    }
    return null;
}

/** Glömmer vem sessionen tror är inloggad. Kakan avgör efter en utloggning. */
function jeab_auth_forget_session_email(): void
{
    if (isset($_SESSION) && is_array($_SESSION) && array_key_exists('jeab_email', $_SESSION)) {
        unset($_SESSION['jeab_email']);
    }
}

function jeab_auth_epoch_pdo(): ?PDO
{
    if (!function_exists('jeab_driver')) {
        return null;
    }
    $driver = jeab_driver();
    if ($driver !== 'sqlite' && $driver !== 'mysql') {
        return null;
    }
    if (!empty($GLOBALS['_JEAB_STORE_OPENING']) || !empty($GLOBALS['_JEAB_SCHEMA_MIGRATING'])) {
        return null;
    }
    try {
        $active = $GLOBALS['_JEAB_ACTIVE_STORE'] ?? null;
        if ($active instanceof JeabPdoStore) {
            return $active->pdo();
        }
        // Första träffen efter session_start ska inte öppna MySQL medan låset sitter.
        if (!empty($GLOBALS['_JEAB_AUTH_EPOCH_DEFER'])) {
            return null;
        }
        if (!function_exists('jeab_store')) {
            return null;
        }
        $store = jeab_store();
        return $store instanceof JeabPdoStore ? $store->pdo() : null;
    } catch (Throwable) {
        return null;
    }
}

/**
 * settings.auth_epoch i SQL. 0 (saknas eller otydligt) ändrar inga kakor.
 */
function jeab_auth_epoch(): int
{
    if (isset($GLOBALS['_JEAB_AUTH_EPOCH_CACHE']) && is_int($GLOBALS['_JEAB_AUTH_EPOCH_CACHE'])) {
        return $GLOBALS['_JEAB_AUTH_EPOCH_CACHE'];
    }
    if (!empty($GLOBALS['_JEAB_STORE_OPENING'])) {
        return 0;
    }
    // Cacha inte. Nästa läsning efter att jeab_data_pdo skapat filen ska se epoch.
    if (function_exists('jeab_sqlite_file_missing') && jeab_sqlite_file_missing()) {
        return 0;
    }
    $epoch = 0;
    $pdo = jeab_auth_epoch_pdo();
    if (!$pdo instanceof PDO && !empty($GLOBALS['_JEAB_AUTH_EPOCH_DEFER'])) {
        return 0;
    }
    if ($pdo instanceof PDO && function_exists('jeab_settings_value')) {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        $raw = jeab_settings_value($pdo, $driver, 'auth_epoch');
        if (is_string($raw) && preg_match('/\A[0-9]{1,12}\z/', $raw) === 1) {
            $epoch = (int) $raw;
        }
    }
    $GLOBALS['_JEAB_AUTH_EPOCH_CACHE'] = $epoch;

    return $epoch;
}

function jeab_auth_epoch_put(int $epoch): bool
{
    if ($epoch < 1) {
        return false;
    }
    $pdo = jeab_auth_epoch_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_settings_put')) {
        return false;
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    if (!jeab_settings_put($pdo, $driver, 'auth_epoch', (string) $epoch)) {
        return false;
    }
    $GLOBALS['_JEAB_AUTH_EPOCH_CACHE'] = $epoch;

    return true;
}

function jeab_auth_logout_all_wants_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $value = $input['dry'];

    return !($value === false || $value === 0 || $value === '0' || $value === 'false');
}

/**
 * auth.logout_all. Utan dry, eller dry=1: rapporterar epoch. dry=0 och
 * confirm=SAM_YES sätter settings.auth_epoch till time().
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_auth_logout_all_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $epoch = jeab_auth_epoch();
    $dry = jeab_auth_logout_all_wants_dry($input);
    if ($dry) {
        return [
            'ok' => true,
            'dry' => true,
            'auth_epoch' => $epoch,
            'status' => 200,
        ];
    }
    if ((string) ($input['confirm'] ?? '') !== 'SAM_YES') {
        return [
            'ok' => false,
            'error' => 'Kräver confirm=SAM_YES.',
            'dry' => false,
            'auth_epoch' => $epoch,
            'status' => 400,
        ];
    }
    $next = time();
    if (!jeab_auth_epoch_put($next)) {
        return [
            'ok' => false,
            'error' => 'auth_epoch kunde inte sparas.',
            'dry' => false,
            'auth_epoch' => $epoch,
            'status' => 500,
        ];
    }
    if (function_exists('jeab_log_app_error')) {
        jeab_log_app_error('auth', 'logout_all ' . date('Y-m-d H:i:s', $next), [
            'level' => 'info',
            'no_webhook' => true,
        ]);
    }

    return [
        'ok' => true,
        'dry' => false,
        'auth_epoch' => $next,
        'previous' => $epoch,
        'status' => 200,
    ];
}

function jeab_auth_bound_entry(?string $value): ?array
{
    if ($value === null || $value === '') {
        return null;
    }
    $payload = jeab_auth_payload($value);
    if ($payload === null) {
        return null;
    }
    $parts = explode('.', $payload);
    $ver = $parts[0] ?? '';
    if ($ver === '3') {
        return jeab_auth_entry_by_fingerprint((string) ($parts[1] ?? ''));
    }
    if ($ver === '2') {
        $email = jeab_auth_decode_email_part((string) ($parts[1] ?? ''));
        return jeab_auth_entry_by_email($email);
    }
    return null;
}

function jeab_auth_value_ok(?string $value): bool
{
    if ($value === null || $value === '') {
        return false;
    }
    $payload = jeab_auth_payload($value);
    if ($payload === null) {
        return false;
    }
    $issued = jeab_auth_issued_at($payload);
    if ($issued === null) {
        return false;
    }
    $age = jeab_auth_now() - $issued;
    if ($age < -60 || $age > JEAB_AUTH_TTL) {
        return false;
    }
    if ($issued < jeab_auth_epoch() || jeab_auth_generation_blocks($payload)) {
        $current = isset($_COOKIE[JEAB_AUTH_COOKIE]) ? (string) $_COOKIE[JEAB_AUTH_COOKIE] : '';
        if ($current !== '' && strlen($current) === strlen($value) && hash_equals($current, $value)) {
            jeab_auth_forget_session_email();
        }
        return false;
    }
    $parts = explode('.', $payload);
    $ver = $parts[0] ?? '';
    if ($ver === '3') {
        return jeab_auth_entry_by_fingerprint((string) ($parts[1] ?? '')) !== null;
    }
    if ($ver === '2') {
        return jeab_auth_email_listed(jeab_auth_decode_email_part((string) ($parts[1] ?? '')));
    }
    // v1 has no email or hash. Accept while anyone is still listed.
    if ($ver === '1') {
        return jeab_auth_hashes() !== [];
    }
    return false;
}

function jeab_auth_email_from_value(?string $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    $payload = jeab_auth_payload($value);
    if ($payload === null) {
        return null;
    }
    $parts = explode('.', $payload);
    $ver = $parts[0] ?? '';
    if ($ver === '3') {
        // Koden (fingeravtrycket) avgör vem det är. En kaka utfärdad innan koden fick
        // e-post i passwords.json bär «-»; då gäller kodens nuvarande e-post.
        $entry = jeab_auth_entry_by_fingerprint((string) ($parts[1] ?? ''));
        $entryEmail = is_array($entry) ? jeab_normalize_person_email((string) ($entry['email'] ?? '')) : null;
        return $entryEmail ?? jeab_auth_decode_email_part((string) ($parts[2] ?? ''));
    }
    if ($ver === '2') {
        return jeab_auth_decode_email_part((string) ($parts[1] ?? ''));
    }
    return null;
}

/** Kodens fingeravtryck (16 hex, inte hemligt) i inloggningskakan, eller null. */
function jeab_auth_fingerprint_from_value(?string $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    $payload = jeab_auth_payload($value);
    if ($payload === null) {
        return null;
    }
    $parts = explode('.', $payload);
    if (($parts[0] ?? '') !== '3') {
        return null;
    }
    $fp = strtolower(trim((string) ($parts[1] ?? '')));
    return ($fp !== '' && ctype_xdigit($fp) && strlen($fp) === 16) ? $fp : null;
}

function jeab_auth_user_fingerprint(): ?string
{
    return jeab_auth_fingerprint_from_value(isset($_COOKIE[JEAB_AUTH_COOKIE]) ? (string) $_COOKIE[JEAB_AUTH_COOKIE] : null);
}

function jeab_auth_user_email(): ?string
{
    $cookieRaw = isset($_COOKIE[JEAB_AUTH_COOKIE]) ? (string) $_COOKIE[JEAB_AUTH_COOKIE] : '';
    $memoKey = $cookieRaw !== ''
        ? 'c:' . $cookieRaw
        : 's:' . (isset($_SESSION['jeab_email']) ? (string) $_SESSION['jeab_email'] : '');
    $emailMemo = $GLOBALS['_JEAB_AUTH_EMAIL'] ?? null;
    if (is_array($emailMemo) && array_key_exists($memoKey, $emailMemo)) {
        $cached = $emailMemo[$memoKey];

        return is_string($cached) ? $cached : null;
    }
    $email = jeab_auth_user_email_lookup($cookieRaw);
    if (!isset($GLOBALS['_JEAB_AUTH_EMAIL']) || !is_array($GLOBALS['_JEAB_AUTH_EMAIL'])) {
        $GLOBALS['_JEAB_AUTH_EMAIL'] = [];
    }
    $GLOBALS['_JEAB_AUTH_EMAIL'][$memoKey] = $email ?? false;

    return $email;
}

function jeab_auth_user_email_lookup(string $cookieRaw): ?string
{
    if ($cookieRaw !== '') {
        $payload = jeab_auth_payload($cookieRaw);
        $issued = $payload !== null ? jeab_auth_issued_at($payload) : null;
        if ($issued !== null && ($issued < jeab_auth_epoch() || jeab_auth_generation_blocks($payload))) {
            jeab_auth_forget_session_email();
            return null;
        }
    }
    // session_write_close() lämnar värdet kvar men session_status är inte ACTIVE.
    // ix_part=counts släpper låset innan räknaren läser vem som är inloggad.
    if ($cookieRaw !== '') {
        $fp = jeab_auth_fingerprint_from_value($cookieRaw);
        if (is_string($fp) && $fp !== '') {
            $entry = jeab_auth_entry_by_fingerprint($fp);
            if (is_array($entry)) {
                // Radens e-post gäller. En gammal session (annan Sam, annan ort)
                // får inte ärvas, och en rad utan e-post är ingen person.
                $rowEmail = jeab_normalize_person_email((string) ($entry['email'] ?? ''));
                if ($rowEmail) {
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        $_SESSION['jeab_email'] = $rowEmail;
                    }
                    return $rowEmail;
                }
                if (session_status() === PHP_SESSION_ACTIVE) {
                    unset($_SESSION['jeab_email']);
                }
                return null;
            }
        }
    }
    if (!empty($_SESSION['jeab_email'])) {
        $fromSession = jeab_normalize_person_email((string) $_SESSION['jeab_email']);
        if ($fromSession) {
            return $fromSession;
        }
    }
    $fromCookie = jeab_auth_email_from_value(isset($_COOKIE[JEAB_AUTH_COOKIE]) ? (string) $_COOKIE[JEAB_AUTH_COOKIE] : null);
    if ($fromCookie && session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['jeab_email'] = $fromCookie;
    }
    return $fromCookie;
}

function jeab_auth_bind_user(?string $email): void
{
    $email = jeab_normalize_person_email($email);
    if (session_status() === PHP_SESSION_ACTIVE) {
        if ($email) {
            $_SESSION['jeab_email'] = $email;
        } else {
            unset($_SESSION['jeab_email']);
        }
    }
    $company = jeab_company_from_email($email);
    if ($company === null) {
        return;
    }
    if (function_exists('jeab_company_allowed') && !jeab_company_allowed($company)) {
        return;
    }
    // Ett valt bolag i sessionen ska överleva nästa sidträff. Grinden anropar
    // den här funktionen varje gång, och e-postens bolag är bara förvalet.
    if (session_status() === PHP_SESSION_ACTIVE && function_exists('jeab_normalize_company')) {
        $current = jeab_normalize_company($_SESSION['jeab_company'] ?? null);
        if ($current !== null && (!function_exists('jeab_company_allowed') || jeab_company_allowed($current))) {
            return;
        }
    }
    jeab_remember_company($company);
}

function jeab_auth_ok(): bool
{
    $value = isset($_COOKIE[JEAB_AUTH_COOKIE]) ? (string) $_COOKIE[JEAB_AUTH_COOKIE] : '';
    $okMemo = $GLOBALS['_JEAB_AUTH_OK'] ?? null;
    if (is_array($okMemo) && array_key_exists($value, $okMemo)) {
        return (bool) $okMemo[$value];
    }
    $ok = jeab_auth_value_ok($value === '' ? null : $value);
    if (!isset($GLOBALS['_JEAB_AUTH_OK']) || !is_array($GLOBALS['_JEAB_AUTH_OK'])) {
        $GLOBALS['_JEAB_AUTH_OK'] = [];
    }
    $GLOBALS['_JEAB_AUTH_OK'][$value] = $ok;

    return $ok;
}

function jeab_auth_cookie_params(?bool $secure = null): array
{
    return [
        'name' => JEAB_AUTH_COOKIE,
        'path' => '/',
        'httponly' => true,
        'secure' => $secure ?? jeab_is_https(),
        'samesite' => 'Lax',
        'max_age' => JEAB_AUTH_TTL,
    ];
}

function jeab_auth_cookie_header(string $value, ?bool $secure = null): string
{
    $params = jeab_auth_cookie_params($secure);
    $parts = [
        $params['name'] . '=' . rawurlencode($value),
        'Max-Age=' . (int) $params['max_age'],
        'Expires=' . gmdate('D, d M Y H:i:s', time() + (int) $params['max_age']) . ' GMT',
        'Path=' . $params['path'],
        'SameSite=' . $params['samesite'],
        'HttpOnly',
    ];
    if (!empty($params['secure'])) {
        $parts[] = 'Secure';
    }
    return implode('; ', $parts);
}

function jeab_auth_send_cookie(string $value): void
{
    $_COOKIE[JEAB_AUTH_COOKIE] = $value;
    unset($GLOBALS['_JEAB_AUTH_EMAIL'], $GLOBALS['_JEAB_AUTH_OK'], $GLOBALS['_JEAB_CSRF_FIELD'], $GLOBALS['_JEAB_CSRF_SIGNED']);
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    header('Set-Cookie: ' . jeab_auth_cookie_header($value), false);
}

function jeab_auth_set_cookie(?string $email = null, ?string $hash = null): bool
{
    $email = $email ?? jeab_auth_user_email();
    $hash = trim((string) $hash);
    if ($hash === '') {
        $current = isset($_COOKIE[JEAB_AUTH_COOKIE]) ? (string) $_COOKIE[JEAB_AUTH_COOKIE] : null;
        $bound = jeab_auth_bound_entry($current);
        $hash = is_array($bound) ? (string) ($bound['hash'] ?? '') : '';
    }
    if ($hash === '') {
        $byEmail = jeab_auth_entry_by_email($email);
        $hash = is_array($byEmail) ? (string) ($byEmail['hash'] ?? '') : '';
    }
    $value = jeab_auth_issue_value($email, $hash !== '' ? $hash : null);
    if ($value === null) {
        return false;
    }
    jeab_auth_send_cookie($value);
    return true;
}

/** Efter butiken: epoch gäller. false betyder att inloggningen ska ritas om. */
function jeab_auth_recheck_or_login(): bool
{
    if (empty($GLOBALS['_JEAB_AUTH_RENEW_AFTER_STORE'])) {
        return true;
    }
    if (jeab_auth_recheck_after_store()) {
        return true;
    }
    jeab_auth_forget_session_email();

    return false;
}

function jeab_auth_recheck_after_store(): bool
{
    unset($GLOBALS['_JEAB_AUTH_EPOCH_CACHE'], $GLOBALS['_JEAB_AUTH_OK'], $GLOBALS['_JEAB_AUTH_EMAIL']);
    $ok = jeab_auth_ok();
    if ($ok && !empty($GLOBALS['_JEAB_AUTH_RENEW_AFTER_STORE'])) {
        unset($GLOBALS['_JEAB_AUTH_RENEW_AFTER_STORE']);
        jeab_auth_renew_cookie();
    }
    if (!$ok) {
        unset($GLOBALS['_JEAB_AUTH_RENEW_AFTER_STORE']);
    }

    return $ok;
}

function jeab_auth_renew_cookie(): void
{
    if (!empty($GLOBALS['_JEAB_AUTH_EPOCH_DEFER'])) {
        $GLOBALS['_JEAB_AUTH_RENEW_AFTER_STORE'] = true;

        return;
    }
    if (!jeab_auth_ok()) {
        return;
    }
    $current = isset($_COOKIE[JEAB_AUTH_COOKIE]) ? (string) $_COOKIE[JEAB_AUTH_COOKIE] : null;
    $bound = jeab_auth_bound_entry($current);
    $hash = is_array($bound) ? (string) ($bound['hash'] ?? '') : '';
    // v1 (and ambiguous v2) cannot name a single hash. Leave the accepted cookie.
    if ($hash === '') {
        return;
    }
    jeab_auth_set_cookie(jeab_auth_user_email(), $hash);
}

function jeab_auth_clear_cookie(): void
{
    unset($_COOKIE[JEAB_AUTH_COOKIE], $GLOBALS['_JEAB_AUTH_EMAIL'], $GLOBALS['_JEAB_AUTH_OK'], $GLOBALS['_JEAB_CSRF_FIELD'], $GLOBALS['_JEAB_CSRF_SIGNED']);
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    $params = jeab_auth_cookie_params();
    $parts = [
        $params['name'] . '=',
        'Max-Age=0',
        'Expires=' . gmdate('D, d M Y H:i:s', time() - 3600) . ' GMT',
        'Path=' . $params['path'],
        'SameSite=' . $params['samesite'],
        'HttpOnly',
    ];
    if (!empty($params['secure'])) {
        $parts[] = 'Secure';
    }
    header('Set-Cookie: ' . implode('; ', $parts), false);
}

function jeab_auth_logout(): void
{
    $current = isset($_COOKIE[JEAB_AUTH_COOKIE]) ? (string) $_COOKIE[JEAB_AUTH_COOKIE] : '';
    if ($current !== '') {
        // H-AUTH-1: höj kodens gräns så en kopierad kaka slutar gälla.
        // Andra webbläsare med samma kod loggas ut. En annan kod rörs inte.
        jeab_auth_generation_bump_for_value($current);
    }
    if (isset($_SESSION) && is_array($_SESSION)) {
        foreach (array_keys($_SESSION) as $key) {
            if (is_string($key) && str_starts_with($key, 'jeab_')) {
                unset($_SESSION[$key]);
            }
        }
    }
    jeab_auth_clear_cookie();
}

/**
 * Inställningsnyckel per kod. v3 bär fingeravtrycket. v2 slås upp via e-post.
 * v1 har ingen kod; en utloggning ogiltigförklarar äldre v1-kakor.
 */
function jeab_auth_generation_id(string $payload): string
{
    $parts = explode('.', $payload);
    $ver = $parts[0] ?? '';
    if ($ver === '3') {
        $fp = strtolower(trim((string) ($parts[1] ?? '')));
        if (strlen($fp) === 16 && ctype_xdigit($fp)) {
            return $fp;
        }

        return '';
    }
    if ($ver === '2') {
        $email = jeab_auth_decode_email_part((string) ($parts[1] ?? ''));
        $entry = jeab_auth_entry_by_email($email);
        $hash = is_array($entry) ? (string) ($entry['hash'] ?? '') : '';

        return $hash !== '' ? jeab_auth_hash_fingerprint($hash) : '';
    }
    if ($ver === '1') {
        return 'v1';
    }

    return '';
}

function jeab_auth_generation_key(string $id): string
{
    return 'auth_gen_' . $id;
}

function jeab_auth_generation_id_ok(string $id): bool
{
    return preg_match('/\A(?:v1|[0-9a-f]{16})\z/', $id) === 1;
}

/** Minsta issued-tid som fortfarande gäller för koden. 0 betyder ingen utloggning. */
function jeab_auth_generation_for(string $id): int
{
    if (!jeab_auth_generation_id_ok($id)) {
        return 0;
    }
    $cacheKey = '_JEAB_AUTH_GEN_' . $id;
    if (isset($GLOBALS[$cacheKey]) && is_int($GLOBALS[$cacheKey])) {
        return $GLOBALS[$cacheKey];
    }
    if (!empty($GLOBALS['_JEAB_STORE_OPENING'])) {
        return 0;
    }
    if (function_exists('jeab_sqlite_file_missing') && jeab_sqlite_file_missing()) {
        return 0;
    }
    $pdo = jeab_auth_epoch_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_settings_value')) {
        return 0;
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    $raw = jeab_settings_value($pdo, $driver, jeab_auth_generation_key($id));
    $gen = 0;
    if (is_string($raw) && preg_match('/\A[0-9]{1,12}\z/', $raw) === 1) {
        $gen = (int) $raw;
    }
    $GLOBALS[$cacheKey] = $gen;

    return $gen;
}

function jeab_auth_generation_put(string $id, int $generation): bool
{
    if ($generation < 1 || $generation > 999999999999 || !jeab_auth_generation_id_ok($id)) {
        return false;
    }
    $pdo = jeab_auth_epoch_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_settings_put')) {
        return false;
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    if (!jeab_settings_put($pdo, $driver, jeab_auth_generation_key($id), (string) $generation)) {
        return false;
    }
    $GLOBALS['_JEAB_AUTH_GEN_' . $id] = $generation;

    return true;
}

function jeab_auth_generation_blocks(string $payload): bool
{
    $issued = jeab_auth_issued_at($payload);
    if ($issued === null) {
        return false;
    }
    $id = jeab_auth_generation_id($payload);
    if ($id === '') {
        return false;
    }

    return $issued < jeab_auth_generation_for($id);
}

/** Höj kodens gräns till strax efter den här kakan. false om gränsen inte kunde sparas. */
function jeab_auth_generation_bump_for_value(string $value): bool
{
    $payload = jeab_auth_payload($value);
    if ($payload === null) {
        return false;
    }
    $id = jeab_auth_generation_id($payload);
    if ($id === '') {
        return false;
    }
    $issued = jeab_auth_issued_at($payload);
    $now = jeab_auth_now();
    $floor = max($now, $issued ?? $now) + 1;
    $prev = jeab_auth_generation_for($id);
    if ($floor <= $prev) {
        $floor = $prev + 1;
    }
    if ($floor > 999999999999) {
        return false;
    }
    if (jeab_auth_generation_put($id, $floor)) {
        return true;
    }
    if (function_exists('jeab_log_app_error')) {
        jeab_log_app_error('auth', 'logout generation kunde inte sparas', [
            'level' => 'error',
            'no_webhook' => true,
        ]);
    }

    return false;
}

function jeab_auth_safe_next(string $given): ?string
{
    $given = trim($given);
    if ($given === '' || strlen($given) > 2048) {
        return null;
    }
    if (str_contains($given, '\\') || str_contains($given, "\0")) {
        return null;
    }
    if (preg_match('/[\x00-\x1f\x7f]/', $given) === 1) {
        return null;
    }
    if (!str_starts_with($given, '/')) {
        return null;
    }
    if (str_starts_with($given, '//') || str_contains($given, '//')) {
        return null;
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $given) === 1) {
        return null;
    }
    return $given;
}

function jeab_auth_next(): string
{
    $given = (string) ($_POST['next'] ?? $_GET['next'] ?? '');
    $safe = jeab_auth_safe_next($given);
    if ($safe !== null) {
        return $safe;
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $safeUri = jeab_auth_safe_next($uri);
    if ($safeUri !== null) {
        return $safeUri;
    }
    return jeab_url('');
}

function jeab_auth_client_ip(): string
{
    $fromTiny = function_exists('jeab_tinyfm_client_ip') ? jeab_tinyfm_client_ip() : '';
    if ($fromTiny !== '') {
        return $fromTiny;
    }
    $raw = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($raw !== '' && filter_var($raw, FILTER_VALIDATE_IP)) {
        return $raw;
    }
    return '0.0.0.0';
}

function jeab_auth_now(): int
{
    if (isset($GLOBALS['_JEAB_AUTH_NOW']) && is_int($GLOBALS['_JEAB_AUTH_NOW'])) {
        return $GLOBALS['_JEAB_AUTH_NOW'];
    }
    return time();
}

function jeab_auth_throttle_path(): string
{
    $env = getenv('JEAB_LOGIN_THROTTLE_FILE');
    if ($env !== false && $env !== '') {
        return $env;
    }
    return jeab_data_file('login-throttle.json');
}

function jeab_auth_throttle_wait_seconds(int $fails): int
{
    if ($fails < 1) {
        return 0;
    }
    $exp = min($fails - 1, 12);
    return 1 << $exp;
}

function jeab_auth_wait_message(int $seconds): string
{
    $seconds = max(1, $seconds);
    if ($seconds === 1) {
        return 'Fel kod. Vänta 1 sekund.';
    }
    return 'Fel kod. Vänta ' . $seconds . ' sekunder.';
}

function jeab_auth_throttle_normalize(mixed $raw): array
{
    $out = [];
    if (!is_array($raw)) {
        return $out;
    }
    foreach ($raw as $ip => $row) {
        $key = trim((string) $ip);
        if ($key === '' || !is_array($row)) {
            continue;
        }
        $out[$key] = [
            'fails' => max(0, (int) ($row['fails'] ?? 0)),
            'until' => max(0, (int) ($row['until'] ?? 0)),
        ];
    }
    return $out;
}

function jeab_auth_throttle_prune(array $store, int $now): array
{
    $keep = [];
    foreach ($store as $ip => $row) {
        $until = (int) ($row['until'] ?? 0);
        $fails = (int) ($row['fails'] ?? 0);
        if ($fails < 1) {
            continue;
        }
        if ($until > 0 && $until < $now - 86400) {
            continue;
        }
        $keep[$ip] = [
            'fails' => $fails,
            'until' => $until,
        ];
    }
    return $keep;
}

function jeab_auth_throttle_unavailable(): bool
{
    return !empty($GLOBALS['_JEAB_THROTTLE_UNAVAILABLE']);
}

function jeab_auth_throttle_mutate(callable $fn): mixed
{
    if (function_exists('jeab_cutover_sql') && jeab_cutover_sql('login_throttle') && function_exists('jeab_cutover_throttle_mutate')) {
        return jeab_cutover_throttle_mutate($fn);
    }
    $GLOBALS['_JEAB_THROTTLE_UNAVAILABLE'] = false;
    $path = jeab_auth_throttle_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        $GLOBALS['_JEAB_THROTTLE_UNAVAILABLE'] = true;
        $empty = [];
        return $fn($empty);
    }
    $fp = @fopen($path, 'c+');
    if ($fp === false) {
        $GLOBALS['_JEAB_THROTTLE_UNAVAILABLE'] = true;
        $empty = [];
        return $fn($empty);
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        $GLOBALS['_JEAB_THROTTLE_UNAVAILABLE'] = true;
        $empty = [];
        return $fn($empty);
    }
    try {
        $raw = stream_get_contents($fp);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $store = jeab_auth_throttle_normalize($decoded);
        $result = $fn($store);
        $json = json_encode($store, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            $GLOBALS['_JEAB_THROTTLE_UNAVAILABLE'] = true;
            return $result;
        }
        rewind($fp);
        if (ftruncate($fp, 0) === false || fwrite($fp, $json) === false) {
            $GLOBALS['_JEAB_THROTTLE_UNAVAILABLE'] = true;
            return $result;
        }
        fflush($fp);
        return $result;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function jeab_auth_throttle_state(?string $ip = null, ?int $now = null): array
{
    $ip = $ip ?? jeab_auth_client_ip();
    $now = $now ?? jeab_auth_now();
    $row = jeab_auth_throttle_mutate(function (array &$store) use ($ip, $now): array {
        $store = jeab_auth_throttle_prune($store, $now);
        $found = $store[$ip] ?? ['fails' => 0, 'until' => 0];
        return [
            'fails' => (int) ($found['fails'] ?? 0),
            'until' => (int) ($found['until'] ?? 0),
        ];
    });
    if (!is_array($row)) {
        $row = ['fails' => 0, 'until' => 0];
    }
    $until = (int) ($row['until'] ?? 0);
    $left = max(0, $until - $now);
    return [
        'fails' => (int) ($row['fails'] ?? 0),
        'until' => $until,
        'wait' => $left,
    ];
}

function jeab_auth_throttle_fail(?string $ip = null, ?int $now = null): array
{
    $ip = $ip ?? jeab_auth_client_ip();
    $now = $now ?? jeab_auth_now();
    $row = jeab_auth_throttle_mutate(function (array &$store) use ($ip, $now): array {
        $store = jeab_auth_throttle_prune($store, $now);
        $prev = $store[$ip] ?? ['fails' => 0, 'until' => 0];
        $fails = (int) ($prev['fails'] ?? 0) + 1;
        $wait = jeab_auth_throttle_wait_seconds($fails);
        $until = $now + $wait;
        $store[$ip] = ['fails' => $fails, 'until' => $until];
        return ['fails' => $fails, 'until' => $until, 'wait' => $wait];
    });
    if (!is_array($row)) {
        $fails = 1;
        $wait = jeab_auth_throttle_wait_seconds($fails);
        return ['fails' => $fails, 'until' => $now + $wait, 'wait' => $wait];
    }
    return $row;
}

function jeab_auth_throttle_reset(?string $ip = null, ?int $now = null): void
{
    $ip = $ip ?? jeab_auth_client_ip();
    $now = $now ?? jeab_auth_now();
    jeab_auth_throttle_mutate(function (array &$store) use ($ip, $now): bool {
        $store = jeab_auth_throttle_prune($store, $now);
        unset($store[$ip]);
        return true;
    });
}

function jeab_auth_attempt(string $code, ?string $ip = null, ?int $now = null): array
{
    if (function_exists('jeab_demo_mode') && jeab_demo_mode()) {
        $now = $now ?? jeab_auth_now();
        if (!jeab_demo_campaign_code_ok($code)) {
            return [
                'ok' => false,
                'error' => 'Fel kod.',
                'wait' => 0,
                'until' => 0,
                'fails' => 0,
            ];
        }
        $person = function_exists('jeab_demo_person') ? jeab_demo_person() : null;
        return [
            'ok' => true,
            'email' => is_array($person) ? (string) ($person['email'] ?? '') : '',
            'hash' => function_exists('jeab_demo_auth_hash') ? jeab_demo_auth_hash() : '',
            'error' => null,
            'wait' => 0,
            'until' => 0,
            'fails' => 0,
        ];
    }
    $ip = $ip ?? jeab_auth_client_ip();
    $now = $now ?? jeab_auth_now();
    $state = jeab_auth_throttle_state($ip, $now);
    if (jeab_auth_throttle_unavailable()) {
        return [
            'ok' => false,
            'error' => 'Inloggning går inte just nu. Försök igen strax.',
            'wait' => 1,
            'until' => $now + 1,
            'fails' => (int) ($state['fails'] ?? 0),
        ];
    }
    if ($state['wait'] > 0) {
        return [
            'ok' => false,
            'error' => jeab_auth_wait_message($state['wait']),
            'wait' => $state['wait'],
            'until' => $state['until'],
            'fails' => $state['fails'],
        ];
    }
    $match = jeab_auth_match($code);
    if ($match !== null) {
        jeab_auth_throttle_reset($ip, $now);
        if (jeab_auth_throttle_unavailable()) {
            return [
                'ok' => false,
                'error' => 'Inloggning går inte just nu. Försök igen strax.',
                'wait' => 1,
                'until' => $now + 1,
                'fails' => 0,
            ];
        }
        return [
            'ok' => true,
            'email' => (string) ($match['email'] ?? ''),
            'hash' => (string) ($match['hash'] ?? ''),
            'error' => null,
            'wait' => 0,
            'until' => 0,
            'fails' => 0,
        ];
    }
    $fail = jeab_auth_throttle_fail($ip, $now);
    if (jeab_auth_throttle_unavailable()) {
        return [
            'ok' => false,
            'error' => 'Inloggning går inte just nu. Försök igen strax.',
            'wait' => 1,
            'until' => $now + 1,
            'fails' => (int) ($fail['fails'] ?? 0),
        ];
    }
    return [
        'ok' => false,
        'error' => jeab_auth_wait_message((int) $fail['wait']),
        'wait' => (int) $fail['wait'],
        'until' => (int) $fail['until'],
        'fails' => (int) $fail['fails'],
    ];
}

function jeab_login_html(?string $error = null, int $waitUntil = 0): string
{
    $waitUntil = max(0, $waitUntil);
    ob_start();
    ?>
    <div class="login-box">
        <div class="card-text">
            <h1><?= function_exists('jeab_demo_mode') && jeab_demo_mode() && function_exists('jeab_demo_public_name') ? e(jeab_demo_public_name()) : 'JEAB solrättigheter' ?></h1>
            <?= jeab_error_html($error) ?>
            <form method="post" action="" autocomplete="off" data-js="login" data-wait-until="<?= (int) $waitUntil ?>">
                <?= jeab_csrf_field() ?>
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="next" value="<?= e(jeab_auth_next()) ?>">
                <label class="login-label" for="password">Ange kampanjkod</label>
                <input id="password" type="password" name="password" placeholder="Skriv kampanjkoden" autocomplete="off" required>
                <button type="submit">Redeem</button>
            </form>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

function jeab_render_login(?string $error = null, int $waitUntil = 0): void
{
    if (!function_exists('jeab_error_html') || !function_exists('jeab_font_link_tags') || !function_exists('jeab_critical_css')) {
        require_once __DIR__ . '/layout.php';
    }
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    header('Referrer-Policy: same-origin');
    ?>
<!doctype html>
<html lang="sv" class="<?= e(function_exists('jeab_theme_class') ? jeab_theme_class() : 'theme-dark') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <link rel="icon" href="/favicon.ico?v=20261104" sizes="48x48">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <meta name="theme-color" content="#0A1630">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="<?= function_exists('jeab_demo_mode') && jeab_demo_mode() ? 'Flowstack' : 'JEAB' ?>">
    <meta name="application-name" content="<?= function_exists('jeab_demo_mode') && jeab_demo_mode() ? 'Flowstack' : 'JEAB' ?>">
    <?php if (function_exists('jeab_demo_mode') && jeab_demo_mode()): ?>
    <meta name="robots" content="noindex, nofollow">
    <?php endif; ?>
    <title><?= function_exists('jeab_demo_title') ? e(jeab_demo_title('JEAB')) : 'JEAB' ?></title>
    <style><?= jeab_critical_css() ?></style>
    <link rel="stylesheet" href="<?= e(jeab_asset_url('assets/css/tokens-apple-dark.css')) ?>">
    <link rel="stylesheet" href="<?= e(jeab_asset_url('assets/css/app.css')) ?>">
    <?= jeab_font_link_tags() ?>
</head>
<body class="page-login">
    <?= function_exists('jeab_demo_banner_html') ? jeab_demo_banner_html() : '' ?>
    <main class="login-main">
        <?= jeab_login_html($error, $waitUntil) ?>
    </main>
    <script>
    (function () {
        var form = document.querySelector('[data-js="login"]');
        if (!form) return;
        var btn = form.querySelector('button[type="submit"]');
        var until = parseInt(form.getAttribute('data-wait-until') || '0', 10) || 0;
        function left() {
            return until - Math.floor(Date.now() / 1000);
        }
        function tick() {
            var remain = left();
            if (!btn) return remain;
            btn.disabled = remain > 0;
            return remain;
        }
        if (tick() <= 0) return;
        var id = setInterval(function () {
            if (tick() <= 0) clearInterval(id);
        }, 200);
    })();
    </script>
</body>
</html>
    <?php
}

function jeab_auth_session_bag_on(): bool
{
    return session_status() === PHP_SESSION_ACTIVE || !empty($GLOBALS['_JEAB_SESSION_RELEASED']);
}

/** Person-id i sessionen, om det fortfarande finns i registret. */
function jeab_auth_session_stored_person(): ?array
{
    if (!jeab_auth_session_bag_on() || !function_exists('jeab_people')) {
        return null;
    }
    $id = trim((string) ($_SESSION['jeab_user'] ?? ''));
    if ($id === '') {
        return null;
    }
    foreach (jeab_people() as $row) {
        if (is_array($row) && strcasecmp((string) ($row['id'] ?? ''), $id) === 0) {
            return $row;
        }
    }

    return null;
}

function jeab_auth_session_has_user(): bool
{
    return is_array(jeab_auth_session_stored_person());
}

/**
 * @param 'prefix'|'choice'|'identity' $via
 */
function jeab_auth_session_user_store(string $personId, string $via, ?string $head = null): void
{
    $personId = trim($personId);
    if ($personId === '' || !empty($GLOBALS['JEAB_NO_SESSION'])) {
        return;
    }
    if (!in_array($via, ['prefix', 'choice', 'identity'], true)) {
        $via = 'identity';
    }
    $write = static function () use ($personId, $via, $head): void {
        $_SESSION['jeab_user'] = $personId;
        $_SESSION['jeab_user_via'] = $via;
        $_SESSION['jeab_code_person'] = $personId;
        if ($head !== null && $head !== '' && function_exists('jeab_code_head')) {
            $kept = jeab_code_head($head);
            if ($kept !== '') {
                $_SESSION['jeab_code_head'] = $kept;
            }
        }
    };
    unset($GLOBALS['_JEAB_LOGIN_IDENTITY']);
    if (session_status() === PHP_SESSION_ACTIVE) {
        $write();

        return;
    }
    if (function_exists('jeab_session_write_once')) {
        jeab_session_write_once($write);
    }
}

/**
 * Huvuden (högst 9 tecken) som den här begäran får använda.
 * Sessionen först, sedan det som sparats mot kakans fingeravtryck.
 *
 * @return list<string>
 */
function jeab_auth_request_code_heads(): array
{
    $raw = [];
    if (jeab_auth_session_bag_on()) {
        $raw[] = (string) ($_SESSION['jeab_code_head'] ?? '');
    }
    $fp = function_exists('jeab_auth_user_fingerprint') ? strtolower(trim((string) jeab_auth_user_fingerprint())) : '';
    if ($fp !== '' && function_exists('jeab_code_people_read')) {
        $row = jeab_code_people_read(false)['fp:' . $fp] ?? null;
        if (is_array($row)) {
            $via = (string) ($row['via'] ?? '');
            $saved = trim((string) ($row['person'] ?? ''));
            // Ett prefixhuvud som nu pekar på en annan person eller flera används inte.
            $moved = in_array($via, ['prefix', 'code'], true) && $saved !== ''
                && function_exists('jeab_code_people_row_person') && jeab_code_people_row_person($row) === null;
            if ($via !== 'conflict' && !$moved) {
                $raw[] = (string) ($row['head'] ?? '');
            }
        }
    }
    $out = [];
    foreach ($raw as $item) {
        $head = function_exists('jeab_code_head') ? jeab_code_head($item) : '';
        if ($head !== '' && !in_array($head, $out, true)) {
            $out[] = $head;
        }
    }

    return $out;
}

/**
 * @return array{certain:bool,person:?array,reason:string}|null
 */
function jeab_auth_session_user_pack(): ?array
{
    if (!empty($GLOBALS['_JEAB_SESSION_USER_PACK'])) {
        return null;
    }
    $GLOBALS['_JEAB_SESSION_USER_PACK'] = true;
    try {
        $stored = jeab_auth_session_stored_person();
        if (is_array($stored)) {
            $via = jeab_auth_session_bag_on() ? (string) ($_SESSION['jeab_user_via'] ?? '') : '';
            $reason = $via === 'choice' ? 'choice' : ($via === 'prefix' ? 'code' : 'email');

            return ['certain' => true, 'person' => $stored, 'reason' => $reason];
        }
        if (!function_exists('jeab_person_by_code_prefix')) {
            return null;
        }
        foreach (jeab_auth_request_code_heads() as $head) {
            $person = jeab_person_by_code_prefix($head);
            if (!is_array($person) || trim((string) ($person['id'] ?? '')) === '') {
                continue;
            }
            jeab_auth_session_user_store((string) $person['id'], 'prefix', $head);
            $fp = function_exists('jeab_auth_user_fingerprint') ? strtolower(trim((string) jeab_auth_user_fingerprint())) : '';
            if ($fp !== '' && function_exists('jeab_code_people_save')) {
                jeab_code_people_save($fp, (string) $person['id'], 'prefix', $head);
            }

            return ['certain' => true, 'person' => $person, 'reason' => 'code'];
        }

        return null;
    } finally {
        $GLOBALS['_JEAB_SESSION_USER_PACK'] = false;
    }
}

/** @param array{certain?:bool,person?:mixed,reason?:string} $result */
function jeab_auth_session_remember_resolved(array $result): void
{
    if (jeab_auth_session_has_user() || empty($result['certain']) || !is_array($result['person'] ?? null)) {
        return;
    }
    $id = trim((string) ($result['person']['id'] ?? ''));
    if ($id === '') {
        return;
    }
    $reason = (string) ($result['reason'] ?? '');
    if ($reason === 'choice') {
        return;
    }
    $via = $reason === 'code' ? 'prefix' : 'identity';
    jeab_auth_session_user_store($id, $via, null);
}

function jeab_auth_person_by_id(string $id): ?array
{
    $id = trim($id);
    if ($id === '' || !function_exists('jeab_people')) {
        return null;
    }
    foreach (jeab_people() as $row) {
        if (is_array($row) && strcasecmp((string) ($row['id'] ?? ''), $id) === 0) {
            return $row;
        }
    }

    return null;
}

function jeab_auth_user_picker_html(?string $error = null): string
{
    if (!function_exists('jeab_error_html') || !function_exists('e')) {
        require_once __DIR__ . '/layout.php';
    }
    $buttons = '';
    foreach (jeab_people() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));
        if ($id === '' || $name === '') {
            continue;
        }
        $buttons .= '<li><button type="submit" name="person" value="' . e($id) . '">' . e($name) . '</button></li>';
    }
    $errorHtml = $error !== null && $error !== '' && function_exists('jeab_error_html') ? jeab_error_html($error) : '';

    return '<div class="login-box" data-user-picker="1">'
        . '<div class="card-text">'
        . '<h1>Välj användare</h1>'
        . $errorHtml
        . '<form method="post" action="">'
        . (function_exists('jeab_csrf_field') ? jeab_csrf_field() : '')
        . '<input type="hidden" name="action" value="session.user">'
        . '<ul class="user-picker-list">' . $buttons . '</ul>'
        . '</form></div></div>';
}

function jeab_auth_render_user_picker(?string $error = null): void
{
    if (!function_exists('jeab_critical_css') || !function_exists('jeab_font_link_tags')) {
        require_once __DIR__ . '/layout.php';
    }
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    header('Referrer-Policy: same-origin');
    echo '<!doctype html><html lang="sv" class="' . e(function_exists('jeab_theme_class') ? jeab_theme_class() : 'theme-dark') . '"><head>'
        . '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Välj användare</title><style>' . jeab_critical_css() . '</style>'
        . '<link rel="stylesheet" href="' . e(jeab_asset_url('assets/css/tokens-apple-dark.css')) . '">'
        . '<link rel="stylesheet" href="' . e(jeab_asset_url('assets/css/app.css')) . '">'
        . jeab_font_link_tags()
        . '</head><body class="page-login" data-user-picker="1"><main class="login-main">'
        . jeab_auth_user_picker_html($error)
        . '</main></body></html>';
}

function jeab_auth_session_user_choose(): void
{
    if (!function_exists('jeab_csrf_ok') || !jeab_csrf_ok()) {
        if (function_exists('jeab_wants_json') && jeab_wants_json() && function_exists('jeab_json')) {
            jeab_json(['ok' => false, 'error' => 'Ogiltig session. Ladda om sidan.'], 419);
        }
        http_response_code(419);
        jeab_auth_render_user_picker('Ogiltig session. Ladda om sidan.');
        exit;
    }
    $picked = jeab_auth_person_by_id((string) ($_POST['person'] ?? ''));
    if (!is_array($picked)) {
        http_response_code(422);
        jeab_auth_render_user_picker('Välj en användare i listan.');
        exit;
    }
    jeab_auth_session_user_store((string) $picked['id'], 'choice', null);
    if (function_exists('jeab_wants_json') && jeab_wants_json() && function_exists('jeab_json')) {
        jeab_json(['ok' => true]);
    }
    if (function_exists('jeab_redirect') && function_exists('jeab_auth_next')) {
        jeab_redirect(jeab_auth_next(), 303);
    }
    header('Location: ' . (function_exists('jeab_auth_next') ? jeab_auth_next() : '/'), true, 303);
    exit;
}

/** Stannar sidan tills sessionen har en användare. Prefixet försöks först. */
function jeab_auth_session_user_barrier(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string) ($_POST['action'] ?? '') === 'session.user') {
        jeab_auth_session_user_choose();
    }
    $id = function_exists('jeab_login_identity') ? jeab_login_identity() : ['certain' => false];
    if (!empty($id['certain']) || jeab_auth_session_has_user()) {
        return;
    }
    $reason = (string) ($id['reason'] ?? '');
    // Delad kod är admin och ingen person. En inloggning som inte träffar
    // någon i registret har inget val. Osäker personal får väljaren.
    if ($reason === 'shared' || $reason === 'none') {
        return;
    }
    if (function_exists('jeab_wants_json') && jeab_wants_json() && function_exists('jeab_json')) {
        jeab_json(['ok' => false, 'error' => 'Välj användare'], 409);
    }
    http_response_code(200);
    jeab_auth_render_user_picker(null);
    exit;
}

/**
 * @return array{user:string,head:string,fp:string,auth:bool}
 */
function jeab_auth_session_blob_fields(string $raw): array
{
    $out = ['user' => '', 'head' => '', 'fp' => '', 'auth' => false];
    if (preg_match('/jeab_user\|s:\d+:"([A-Za-z0-9]*)";/', $raw, $user) === 1) {
        $out['user'] = (string) $user[1];
    }
    if (preg_match('/jeab_code_head\|s:\d+:"([^"]*)";/', $raw, $head) === 1) {
        $out['head'] = (string) $head[1];
    }
    if (preg_match('/s:2:"fp";s:16:"([0-9a-fA-F]{16})"/', $raw, $fp) === 1) {
        $out['fp'] = strtolower((string) $fp[1]);
    }
    $out['auth'] = str_contains($raw, 'jeab_email|') || str_contains($raw, 'jeab_auth_fast|') || str_contains($raw, 'jeab_code_head|');

    return $out;
}

function jeab_auth_session_save_dir(): string
{
    $path = (string) session_save_path();
    if (preg_match('/\A\d+;(.+)\z/', $path, $m) === 1) {
        $path = (string) $m[1];
    }
    $path = rtrim($path, '/');
    if ($path === '' || $path === '/' || !is_dir($path)) {
        return '';
    }

    return $path;
}

/** @return list<string> */
function jeab_auth_session_files(): array
{
    $dir = jeab_auth_session_save_dir();
    if ($dir === '') {
        return [];
    }
    $files = glob($dir . '/sess_*') ?: [];
    $out = [];
    foreach ($files as $file) {
        if (is_string($file) && is_file($file) && !is_link($file)) {
            $out[] = $file;
        }
    }

    return $out;
}

function jeab_auth_session_php_put_string(string $raw, string $key, string $value): string
{
    if (preg_match('/\A[A-Za-z0-9_]+\z/', $key) !== 1 || preg_match('/\A[A-Za-z0-9]+\z/', $value) !== 1) {
        return $raw;
    }
    $piece = $key . '|s:' . strlen($value) . ':"' . $value . '";';
    $pattern = '/' . preg_quote($key, '/') . '\|s:\d+:"[^"]*";/';
    if (preg_match($pattern, $raw) === 1) {
        $next = preg_replace($pattern, $piece, $raw, 1);

        return is_string($next) ? $next : $raw;
    }

    return $raw . $piece;
}

/**
 * auth.code_people_shape. Bara läsning. En rad per code_people-rad med person, via,
 * head_len och has_sep (ja/nej: blanksteg, punkt, bindestreck eller understreck i huvudet).
 * Aldrig huvud, hash, fingeravtryck eller kod. Skriver inget och loggar inget.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_auth_code_people_shape_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $rows = [];
    $all = function_exists('jeab_code_people_read') ? jeab_code_people_read(false) : [];
    foreach ($all as $row) {
        if (!is_array($row)) {
            continue;
        }
        // Det sparade huvudet som det ligger, så äldre huvuden med skiljetecken syns.
        $head = trim((string) ($row['head'] ?? ''));
        $rows[] = [
            'person' => (string) ($row['person'] ?? ''),
            'via' => (string) ($row['via'] ?? ''),
            'head_len' => function_exists('mb_strlen') ? mb_strlen($head, 'UTF-8') : strlen($head),
            'has_sep' => preg_match('/[\s._\-]/u', $head) === 1 ? 'ja' : 'nej',
        ];
    }

    return ['ok' => true, 'count' => count($rows), 'rows' => $rows];
}

/**
 * auth.session_user_repair. dry saknas eller dry=1 räknar bara.
 * dry=0 skriver person-id på de sessioner vars prefix träffar en användare.
 * Inga koder i svaret.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_auth_session_user_repair_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $dry = jeab_auth_logout_all_wants_dry($input);
    $without = 0;
    $matched = 0;
    $unmatched = 0;
    $linked = 0;
    foreach (jeab_auth_session_files() as $file) {
        $raw = (string) @file_get_contents($file);
        $fields = jeab_auth_session_blob_fields($raw);
        if (!$fields['auth']) {
            continue;
        }
        if (jeab_auth_person_by_id($fields['user']) !== null) {
            continue;
        }
        $without++;
        $heads = [];
        if ($fields['head'] !== '' && function_exists('jeab_code_head')) {
            $heads[] = jeab_code_head($fields['head']);
        }
        if ($fields['fp'] !== '' && function_exists('jeab_code_people_read')) {
            $row = jeab_code_people_read(false)['fp:' . $fields['fp']] ?? null;
            // Inaktuell prefixrad (namnbyte, ny person med samma prefix) ger inget huvud.
            // En rad utan person har bara huvudet och får lösas från det.
            $staleRow = is_array($row) && in_array((string) ($row['via'] ?? ''), ['prefix', 'code'], true)
                && trim((string) ($row['person'] ?? '')) !== ''
                && function_exists('jeab_code_people_row_person') && jeab_code_people_row_person($row) === null;
            if (is_array($row) && (string) ($row['via'] ?? '') !== 'conflict' && !$staleRow) {
                $storedHead = function_exists('jeab_code_head') ? jeab_code_head((string) ($row['head'] ?? '')) : '';
                if ($storedHead !== '') {
                    $heads[] = $storedHead;
                }
            }
        }
        $person = null;
        foreach ($heads as $head) {
            if ($head === '' || !function_exists('jeab_person_by_code_prefix')) {
                continue;
            }
            $hit = jeab_person_by_code_prefix($head);
            if (is_array($hit) && trim((string) ($hit['id'] ?? '')) !== '') {
                $person = $hit;
                break;
            }
        }
        if (!is_array($person)) {
            $unmatched++;
            continue;
        }
        $matched++;
        if ($dry) {
            continue;
        }
        $id = (string) $person['id'];
        $next = jeab_auth_session_php_put_string($raw, 'jeab_user', $id);
        $next = jeab_auth_session_php_put_string($next, 'jeab_user_via', 'prefix');
        $next = jeab_auth_session_php_put_string($next, 'jeab_code_person', $id);
        if ($next !== $raw && @file_put_contents($file, $next, LOCK_EX) !== false) {
            $linked++;
        }
    }

    return [
        'ok' => true,
        'dry' => $dry,
        'sessions_without_user' => $without,
        'matched' => $matched,
        'unmatched' => $unmatched,
        'linked' => $dry ? 0 : $linked,
        'status' => 200,
    ];
}

function jeab_gate(): void
{
    if (jeab_public_script()) {
        return;
    }

    jeab_start_session();

    if (jeab_auth_ok()) {
        if (function_exists('jeab_demo_bind_session')) {
            jeab_demo_bind_session();
        }
        jeab_auth_bind_user(jeab_auth_user_email());
        jeab_auth_renew_cookie();
        if (function_exists('jeab_restricted_is_solkampanj') && jeab_restricted_is_solkampanj()) {
            if (function_exists('jeab_restricted_fence')) {
                jeab_restricted_fence(false);
            }
            return;
        }
        // Kom-ihåg-kakan band bara e-post. Prefixet (9 tecken) fyller i användaren
        // här, även för en session som skapades innan mappningen fanns.
        jeab_auth_session_user_barrier();
        if (function_exists('jeab_system_mail_diag_page')) {
            jeab_system_mail_diag_page();
        }
        if (function_exists('jeab_who_diag_record')) {
            jeab_who_migrate();
            if (function_exists('jeab_login_diag_page')) {
                jeab_login_diag_page();
            }
            if (function_exists('jeab_offert_utskick_page')) {
                jeab_offert_utskick_page();
            }
            jeab_who_diag_page();
            register_shutdown_function(static function (): void {
                if (function_exists('jeab_finish_http_response')) {
                    jeab_finish_http_response();
                }
                jeab_who_diag_record();
            });
        } elseif ((string) ($_GET['p'] ?? '') === 'whodiag') {
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
            echo 'who_identity laddades inte: ' . (string) ($GLOBALS['_JEAB_WHO_LOAD_ERR'] ?? 'okänt') . "\n";
            exit;
        }
        return;
    }

    jeab_auth_forget_session_email();

    $error = null;
    $waitUntil = (int) (jeab_auth_throttle_state()['until'] ?? 0);
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string) ($_POST['action'] ?? '') === 'login') {
        if (!jeab_csrf_ok()) {
            if (jeab_wants_json()) {
                jeab_json(['ok' => false, 'error' => 'Ogiltig session. Ladda om sidan.'], 419);
            }
            $error = 'Ogiltig session. Ladda om sidan.';
        } else {
            $attempt = jeab_auth_attempt((string) ($_POST['password'] ?? ''));
            $waitUntil = (int) ($attempt['until'] ?? 0);
            if (!empty($attempt['ok'])) {
                $email = jeab_normalize_person_email((string) ($attempt['email'] ?? '')) ?? '';
                $hash = (string) ($attempt['hash'] ?? '');
                $restrictedLogin = $hash !== '' && function_exists('jeab_restricted_by_hash') && jeab_restricted_by_hash($hash) !== null;
                if (!$restrictedLogin && function_exists('jeab_code_people_remember')) {
                    $postedCode = (string) ($_POST['password'] ?? '');
                    $codePerson = jeab_code_people_remember($postedCode, $hash, $email !== '' ? $email : null);
                    $head = function_exists('jeab_code_head') ? jeab_code_head($postedCode) : '';
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        if ($head !== '') {
                            $_SESSION['jeab_code_head'] = $head;
                        }
                        if (is_array($codePerson) && trim((string) ($codePerson['id'] ?? '')) !== '') {
                            $_SESSION['jeab_code_person'] = (string) $codePerson['id'];
                            $_SESSION['jeab_user'] = (string) $codePerson['id'];
                            $_SESSION['jeab_user_via'] = 'prefix';
                        } else {
                            unset($_SESSION['jeab_code_person'], $_SESSION['jeab_user'], $_SESSION['jeab_user_via']);
                        }
                    }
                }
                jeab_auth_bind_user($email !== '' ? $email : null);
                if (function_exists('jeab_demo_bind_session')) {
                    jeab_demo_bind_session();
                }
                if (jeab_auth_set_cookie($email !== '' ? $email : null, $hash !== '' ? $hash : null)) {
                    if (jeab_wants_json()) {
                        jeab_json(['ok' => true]);
                    }
                    if ($restrictedLogin) {
                        jeab_redirect(jeab_url('solkampanj.php'), 303);
                    }
                    jeab_redirect(jeab_auth_next(), 303);
                }
            }
            $error = (string) ($attempt['error'] ?? 'Fel kod.');
            if (jeab_wants_json()) {
                jeab_json(['ok' => false, 'error' => $error, 'wait' => (int) ($attempt['wait'] ?? 0)], 401);
            }
        }
    }

    if (jeab_wants_json()) {
        jeab_json(['ok' => false, 'error' => 'Logga in först.'], 401);
    }

    http_response_code($error ? 401 : 200);
    jeab_render_login($error, $waitUntil);
    exit;
}

// Personförvalet laddas försiktigt: fel här får aldrig stoppa inloggningen.
(static function (): void {
    $file = __DIR__ . '/who_identity.php';
    if (!is_file($file) || !is_readable($file)) {
        $GLOBALS['_JEAB_WHO_LOAD_ERR'] = 'filen saknas eller går inte att läsa (' . (is_file($file) ? substr(sprintf('%o', (int) @fileperms($file)), -4) : 'saknas') . ')';
        return;
    }
    try {
        require_once $file;
    } catch (\Throwable $e) {
        $GLOBALS['_JEAB_WHO_LOAD_ERR'] = get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine();
    }
})();
