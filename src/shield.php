<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo "Forbidden\n";
    exit;
}

/**
 * filer.php dör om src/ordrar.php saknas, så TinyFM kan inte döpa tillbaka
 * filen själv. Nästa request lägger tillbaka en deploy-tmp eller .bak.
 */
function jeab_restore_boot_php(string $path): void
{
    if (is_file($path)) {
        return;
    }
    $dir = dirname($path);
    $base = basename($path);
    $candidates = glob($dir . '/' . $base . '.deploy-*.tmp') ?: [];
    usort(
        $candidates,
        static function (string $a, string $b): int {
            return filemtime($b) <=> filemtime($a);
        }
    );
    $candidates[] = $path . '.bak';
    foreach ($candidates as $candidate) {
        if (!is_file($candidate) || filesize($candidate) < 20) {
            continue;
        }
        if (@rename($candidate, $path)) {
            return;
        }
    }
}

jeab_restore_boot_php(__DIR__ . '/ordrar.php');

/**
 * Loopia nginx ignorerar .htaccess. Saknade filer faller igenom till
 * index.php (kampanjkod-HTML). Känsliga adresser svarar 403 där.
 * Adresser med "/." är redan 403 hos nginx. Hemligheter läses från
 * dirname(JEAB_ROOT)/privat/ (prickade namn) och sedan data/.private/.
 * JSON som appen skriver själv landar i data/.private/. Vid boot flyttas
 * kända läckor dit (throttle, fellogg, secrets/*.json, notes.json,
 * notes-photos/). store/purchases flyttas när filen öppnas, inte i
 * hela boot-svepet. Nya anteckningar skapas inte under publika data/.
 */

function jeab_deny_if_direct(string $file): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    $script = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $self = realpath($file);
    if ($script === false || $self === false || $script !== $self) {
        return;
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo "Forbidden\n";
    exit;
}

function jeab_data_dir(): string
{
    if (!empty($GLOBALS['_JEAB_DATA_DIR']) && is_string($GLOBALS['_JEAB_DATA_DIR'])) {
        return rtrim($GLOBALS['_JEAB_DATA_DIR'], '/');
    }
    $env = getenv('JEAB_DATA_DIR');
    if (is_string($env) && trim($env) !== '') {
        return rtrim(trim($env), '/');
    }

    return JEAB_ROOT . '/data/.private';
}

function jeab_public_data_dir(): string
{
    if (!empty($GLOBALS['_JEAB_PUBLIC_DATA_DIR']) && is_string($GLOBALS['_JEAB_PUBLIC_DATA_DIR'])) {
        return rtrim($GLOBALS['_JEAB_PUBLIC_DATA_DIR'], '/');
    }
    return JEAB_ROOT . '/data';
}

function jeab_store_path_is_public_data(string $path): bool
{
    $norm = rtrim(str_replace('\\', '/', $path), '/');
    $public = rtrim(str_replace('\\', '/', jeab_public_data_dir()), '/');
    $private = rtrim(str_replace('\\', '/', jeab_data_dir()), '/');
    if ($norm === $private || str_starts_with($norm, $private . '/')) {
        return false;
    }
    return $norm === $public || str_starts_with($norm, $public . '/');
}

function jeab_private_data_writable(): bool
{
    $dir = jeab_data_dir();
    if (is_dir($dir) && is_writable($dir)) {
        return true;
    }
    if (is_file($dir)) {
        return false;
    }
    if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false;
    }
    return is_dir($dir) && is_writable($dir);
}

function jeab_map_public_data_path(string $path): string
{
    $norm = str_replace('\\', '/', $path);
    $public = str_replace('\\', '/', jeab_public_data_dir());
    $private = str_replace('\\', '/', jeab_data_dir());
    if ($norm === $private || str_starts_with($norm, $private . '/')) {
        return $path;
    }
    if ($norm !== $public && !str_starts_with($norm, $public . '/')) {
        return $path;
    }
    $rel = ltrim(substr($norm, strlen($public)), '/');
    if ($rel === '' || $rel === 'config.php' || $rel === '.htaccess' || $rel === '.private' || str_starts_with($rel, '.private/')) {
        return $path;
    }
    return $private . '/' . $rel;
}

function jeab_resolve_data_path(string $path): string
{
    $mapped = jeab_map_public_data_path($path);
    if ($mapped === $path) {
        return $path;
    }
    return jeab_data_land_private($mapped, $path, false);
}

function jeab_resolve_sqlite_path(string $path): string
{
    $mapped = jeab_map_public_data_path($path);
    if ($mapped === $path) {
        return $path;
    }
    $landed = jeab_data_land_private($mapped, $path, false);
    if (jeab_store_path_is_public_data($landed)) {
        return $mapped;
    }
    return $landed;
}

function jeab_data_copy_over(string $from, string $to): bool
{
    $dir = dirname($to);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $tmp = $dir . '/.jeab-adopt-' . bin2hex(random_bytes(4));
    if (!@copy($from, $tmp)) {
        @unlink($tmp);
        return false;
    }
    $fromSize = @filesize($from);
    $tmpSize = @filesize($tmp);
    if ($fromSize === false || $tmpSize === false || $fromSize !== $tmpSize) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $to)) {
        @unlink($tmp);
        return false;
    }
    return is_file($to);
}

/**
 * Skrivmål under data/.private. En befintlig publik fil flyttas dit.
 * Publika data/ används bara om flytten misslyckas och filen redan finns.
 */
function jeab_data_land_private(string $private, string $legacy, bool $force): string
{
    if ($private === $legacy) {
        return $private;
    }
    $dir = dirname($private);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $privateReady = is_dir($dir) && is_writable($dir);

    if (is_file($legacy) && is_file($private)) {
        $privateSize = (int) (@filesize($private) ?: 0);
        $legacySize = (int) (@filesize($legacy) ?: 0);
        if ($privateSize === 0 && $legacySize > 0) {
            @unlink($private);
            if (jeab_data_try_move($legacy, $private)) {
                return $private;
            }
            if (!$force && !$privateReady) {
                return $legacy;
            }
            return $private;
        }
        if (($privateSize >= $legacySize && $privateSize > 0) || ($force && $privateSize > 0)) {
            @unlink($legacy);
            return $private;
        }
        if ($privateReady && jeab_data_copy_over($legacy, $private)) {
            @unlink($legacy);
            return $private;
        }
        if (!$force) {
            return $legacy;
        }
        return $private;
    }

    if (is_file($private)) {
        return $private;
    }
    if (is_file($legacy)) {
        if (jeab_data_try_move($legacy, $private)) {
            return $private;
        }
        if (!$force) {
            return $legacy;
        }
        return $private;
    }
    return $private;
}

function jeab_data_normalize_relative(string $relative): string
{
    $relative = ltrim(str_replace('\\', '/', $relative), '/');
    if ($relative === '' || str_contains($relative, '..') || str_contains($relative, "\0")) {
        throw new InvalidArgumentException('Ogiltig data-sökväg.');
    }
    if ($relative === '.private' || str_starts_with($relative, '.private/')) {
        throw new InvalidArgumentException('Ogiltig data-sökväg.');
    }
    return $relative;
}

function jeab_data_must_be_private(string $relative): bool
{
    $relative = jeab_data_normalize_relative($relative);
    if (str_starts_with($relative, 'secrets/')) {
        return true;
    }
    return in_array(basename($relative), [
        'login-throttle.json',
        'app-errors-webhook-resolved.json',
        'app-errors-webhook-seen.json',
        'fellogg-open-asks.json',
        'app-errors.jsonl',
        'incoming-token.txt',
    ], true);
}

function jeab_data_try_move(string $from, string $to): bool
{
    if (!is_file($from) || $from === $to) {
        return false;
    }
    $dir = dirname($to);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    if (@rename($from, $to)) {
        return is_file($to);
    }
    if (!@copy($from, $to)) {
        return false;
    }
    $fromSize = @filesize($from);
    $toSize = @filesize($to);
    if ($fromSize === false || $toSize === false || $fromSize !== $toSize) {
        @unlink($to);
        return false;
    }
    @unlink($from);
    return is_file($to);
}

/**
 * Kopiera ett träd till en privat katalog och ta bort källan först när
 * varje fil finns kvar i målet med samma storlek. En misslyckad kopia
 * lämnar originalet orört.
 */
function jeab_adopt_private_tree(string $from, string $to): bool
{
    $from = rtrim(str_replace('\\', '/', $from), '/');
    $to = rtrim(str_replace('\\', '/', $to), '/');
    if ($from === '' || $to === '' || $from === $to) {
        return true;
    }
    if (str_starts_with($to . '/', $from . '/')) {
        return false;
    }
    if (!is_dir($from)) {
        return true;
    }
    if (!is_dir($to) && !@mkdir($to, 0700, true) && !is_dir($to)) {
        return false;
    }
    $complete = true;
    foreach (scandir($from) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $src = $from . '/' . $name;
        $dst = $to . '/' . $name;
        if (is_link($src)) {
            $complete = false;
            continue;
        }
        if (is_dir($src)) {
            if (!jeab_adopt_private_tree($src, $dst)) {
                $complete = false;
            }
            continue;
        }
        if (!is_file($src)) {
            continue;
        }
        if (is_file($dst)) {
            $srcSize = @filesize($src);
            $dstSize = @filesize($dst);
            if ($srcSize !== false && $srcSize === $dstSize) {
                @unlink($src);
                continue;
            }
            if ($srcSize !== false && $srcSize > 0 && ($dstSize === false || (int) $dstSize === 0)) {
                if (jeab_data_copy_over($src, $dst)) {
                    clearstatcache(true, $dst);
                    if (@filesize($dst) === $srcSize) {
                        @unlink($src);
                        continue;
                    }
                }
            }
            $complete = false;
            continue;
        }
        if (!jeab_data_try_move($src, $dst)) {
            $complete = false;
        }
    }
    if ($complete) {
        @rmdir($from);
    }
    return $complete;
}

function jeab_data_file(string $relative): string
{
    $relative = jeab_data_normalize_relative($relative);
    $private = jeab_data_dir() . '/' . $relative;
    $legacy = jeab_public_data_dir() . '/' . $relative;
    return jeab_data_land_private($private, $legacy, jeab_data_must_be_private($relative));
}

function jeab_hidden_file(string $privateRelative, string $legacyAbsolute): string
{
    unset($legacyAbsolute);
    return jeab_data_file($privateRelative);
}

function jeab_private_configured_file(string $path): string
{
    $norm = str_replace('\\', '/', $path);
    $public = str_replace('\\', '/', jeab_public_data_dir());
    $private = str_replace('\\', '/', jeab_data_dir());
    if ($norm === $private || str_starts_with($norm, $private . '/')) {
        $rel = ltrim(substr($norm, strlen($private)), '/');
        if ($rel !== '' && preg_match('/\.(?:json|jsonl|txt)$/i', $rel) === 1) {
            return jeab_data_file($rel);
        }
        return $path;
    }
    if ($norm === $public || !str_starts_with($norm, $public . '/')) {
        return $path;
    }
    $rel = ltrim(substr($norm, strlen($public)), '/');
    if ($rel === '' || $rel === 'config.php' || $rel === '.htaccess' || $rel === '.private' || str_starts_with($rel, '.private/')) {
        return $path;
    }
    if (preg_match('/\.(?:json|jsonl|txt)$/i', $rel) !== 1) {
        return $path;
    }
    return jeab_data_file($rel);
}

function jeab_privatize_config(array $config): array
{
    if (isset($config['db']) && is_array($config['db'])) {
        if (isset($config['db']['json_path']) && is_string($config['db']['json_path']) && $config['db']['json_path'] !== '') {
            $config['db']['json_path'] = jeab_private_configured_file($config['db']['json_path']);
        }
        if (isset($config['db']['sqlite_path']) && is_string($config['db']['sqlite_path']) && $config['db']['sqlite_path'] !== '') {
            $config['db']['sqlite_path'] = jeab_resolve_sqlite_path($config['db']['sqlite_path']);
        }
    }
    if (isset($config['passwords_file']) && is_string($config['passwords_file']) && $config['passwords_file'] !== '') {
        $config['passwords_file'] = jeab_resolve_data_path($config['passwords_file']);
    }
    return $config;
}

function jeab_fortnox_file(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $primary = jeab_data_dir() . '/rot/' . $name;
    $fallback = JEAB_ROOT . '/rot/' . $name;
    if (is_file($primary)) {
        return $primary;
    }
    if (is_file($fallback)) {
        return $fallback;
    }
    return $primary;
}

function jeab_fortnox_config_path(): string
{
    return jeab_fortnox_file('fortnox_config.php');
}

function jeab_fortnox_app_root(): string
{
    if (!empty($GLOBALS['_JEAB_FORTNOX_APP_ROOT']) && is_string($GLOBALS['_JEAB_FORTNOX_APP_ROOT'])) {
        return rtrim($GLOBALS['_JEAB_FORTNOX_APP_ROOT'], '/');
    }
    if (!defined('JEAB_ROOT')) {
        throw new RuntimeException('JEAB_ROOT saknas');
    }
    return JEAB_ROOT;
}

/**
 * Refresh- och access-token för båda bolagen.
 * Filen ligger under data/.private/ och är spärrad i deploy.
 * rot/fortnox_tokens.json deployas tillsammans med PHP och får inte vara källan.
 */
function jeab_fortnox_tokens_canonical_path(): string
{
    return jeab_data_dir() . '/fortnox/tokens.json';
}

function jeab_fortnox_tokens_rotated_path(): string
{
    return jeab_data_dir() . '/fortnox/tokens.rotated.json';
}

/** @return list<string> */
function jeab_fortnox_tokens_legacy_paths(): array
{
    $root = jeab_fortnox_app_root();
    $private = jeab_data_dir();
    $canonical = jeab_fortnox_tokens_canonical_path();
    $paths = [
        $private . '/rot/fortnox_tokens.json',
        $root . '/rot/fortnox_tokens.json',
        $private . '/rot/tokens.json',
        $root . '/rot/tokens.json',
        $root . '/data/rot/fortnox_tokens.json',
        $root . '/data/fortnox_tokens.json',
    ];
    $out = [];
    foreach ($paths as $path) {
        if ($path === $canonical || in_array($path, $out, true)) {
            continue;
        }
        $out[] = $path;
    }
    return $out;
}

function jeab_fortnox_token_account_key(string $account): string
{
    $fold = function_exists('mb_strtolower') ? mb_strtolower(trim($account), 'UTF-8') : strtolower(trim($account));
    $ascii = strtr($fold, ['ä' => 'a', 'ö' => 'o', 'å' => 'a', 'é' => 'e']);
    if (in_array($ascii, ['malmo', 'meab'], true)) {
        return 'Malmo';
    }
    if (in_array($ascii, ['jarfalla', 'jeab'], true)) {
        return 'Jarfalla';
    }
    return trim($account);
}

function jeab_fortnox_token_refresh_value(mixed $row): string
{
    if (is_string($row)) {
        return trim($row);
    }
    if (!is_array($row)) {
        return '';
    }
    return trim((string) ($row['refresh_token'] ?? $row['refreshToken'] ?? ''));
}

function jeab_fortnox_is_token_filename(string $path): bool
{
    $name = strtolower(basename(str_replace('\\', '/', $path)));
    return $name === 'fortnox_tokens.json' || $name === 'tokens.json';
}

/** @return array<string,mixed> */
function jeab_fortnox_tokens_read_file(string $path): array
{
    $decoded = [];
    if (is_file($path)) {
        $raw = json_decode((string) file_get_contents($path), true);
        $decoded = is_array($raw) ? $raw : [];
    }
    if ($path !== jeab_fortnox_tokens_canonical_path()) {
        return $decoded;
    }
    $store = jeab_fortnox_tokens_normalize_store($decoded);
    $rotatedPath = jeab_fortnox_tokens_rotated_path();
    if (is_file($rotatedPath)) {
        $rotatedRaw = json_decode((string) file_get_contents($rotatedPath), true);
        $rotated = is_array($rotatedRaw) ? jeab_fortnox_tokens_normalize_store($rotatedRaw) : [];
        $store = jeab_fortnox_tokens_prefer_newer($rotated, $store);
    }
    if (isset($GLOBALS['_JEAB_FORTNOX_TOKENS_MEMORY']) && is_array($GLOBALS['_JEAB_FORTNOX_TOKENS_MEMORY'])) {
        $memory = jeab_fortnox_tokens_normalize_store($GLOBALS['_JEAB_FORTNOX_TOKENS_MEMORY']);
        $store = jeab_fortnox_tokens_prefer_newer($memory, $store);
    }
    return $store;
}

/**
 * @param array<string,mixed> $primary
 * @param array<string,mixed> $fallback
 * @return array<string,mixed>
 */
function jeab_fortnox_tokens_prefer_newer(array $primary, array $fallback): array
{
    $out = $fallback;
    foreach ($primary as $account => $row) {
        if (!is_array($row) || jeab_fortnox_token_refresh_value($row) === '') {
            continue;
        }
        $have = $out[$account] ?? null;
        $haveExp = is_array($have) ? (int) ($have['expires_at'] ?? 0) : 0;
        $rowExp = (int) ($row['expires_at'] ?? 0);
        if (!is_array($have) || jeab_fortnox_token_refresh_value($have) === '' || $rowExp >= $haveExp) {
            $out[$account] = $row;
        }
    }
    return $out;
}

function jeab_fortnox_token_file_has_refresh(string $path): bool
{
    foreach (jeab_fortnox_tokens_normalize_store(jeab_fortnox_tokens_read_file($path)) as $row) {
        if (jeab_fortnox_token_refresh_value($row) !== '') {
            return true;
        }
    }
    return false;
}

/**
 * @param array<string,mixed> $store
 * @return array<string,mixed>
 */
function jeab_fortnox_tokens_normalize_store(array $store): array
{
    $out = [];
    foreach ($store as $key => $value) {
        if (!is_string($key) || $key === '' || str_starts_with($key, '_')) {
            continue;
        }
        $canon = jeab_fortnox_token_account_key($key);
        $known = $canon === 'Jarfalla' || $canon === 'Malmo';
        $row = null;
        if (is_array($value)) {
            $row = $value;
        } elseif (is_string($value) && $known) {
            $row = ['refresh_token' => trim($value)];
        }
        if ($row === null) {
            continue;
        }
        if (!$known) {
            if (jeab_fortnox_token_refresh_value($row) === '') {
                continue;
            }
            $canon = $key;
        }
        if (jeab_fortnox_token_refresh_value($row) === '') {
            if (!isset($out[$canon])) {
                $out[$canon] = $row;
            }
            continue;
        }
        if (isset($out[$canon]) && jeab_fortnox_token_refresh_value($out[$canon]) !== '' && $key !== $canon) {
            continue;
        }
        $out[$canon] = $row;
    }
    return $out;
}

/**
 * @param array<string,mixed> $store
 * @return array<string,bool>
 */
function jeab_fortnox_tokens_present(array $store): array
{
    $have = [];
    foreach (jeab_fortnox_tokens_normalize_store($store) as $account => $row) {
        if (jeab_fortnox_token_refresh_value($row) !== '') {
            $have[$account] = true;
        }
    }
    return $have;
}

function jeab_fortnox_tokens_needs_legacy_scan(array $store): bool
{
    $have = jeab_fortnox_tokens_present($store);
    return empty($have['Jarfalla']) || empty($have['Malmo']);
}

/** @param array<string,mixed> $store */
function jeab_fortnox_tokens_write_file(string $path, array $store): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return false;
    }
    $ok = jeab_shield_write_file($path, $json . "\n");
    if ($ok && ($path === jeab_fortnox_tokens_canonical_path() || $path === jeab_fortnox_tokens_rotated_path())) {
        $GLOBALS['_JEAB_FORTNOX_TOKENS_MEMORY'] = $store;
    }
    return $ok;
}

/** @param array<string,mixed> $store */
function jeab_fortnox_tokens_write_durable(string $path, array $store): bool
{
    $GLOBALS['_JEAB_FORTNOX_TOKENS_MEMORY'] = $store;
    for ($try = 0; $try < 3; $try++) {
        if (jeab_fortnox_tokens_write_file($path, $store)) {
            $rotated = jeab_fortnox_tokens_rotated_path();
            if ($rotated !== $path && is_file($rotated)) {
                @unlink($rotated);
            }
            return true;
        }
    }
    $rotated = jeab_fortnox_tokens_rotated_path();
    if ($rotated !== $path) {
        for ($try = 0; $try < 3; $try++) {
            if (jeab_fortnox_tokens_write_file($rotated, $store)) {
                return true;
            }
        }
    }
    return false;
}

function jeab_fortnox_tokens_with_lock(callable $fn): mixed
{
    $dir = dirname(jeab_fortnox_tokens_canonical_path());
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Fortnox-tokenmappen kunde inte skapas');
    }
    $fh = @fopen($dir . '/tokens.lock', 'c');
    if ($fh === false) {
        throw new RuntimeException('Fortnox-tokenlåset kunde inte öppnas');
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        throw new RuntimeException('Fortnox-tokenlåset kunde inte tas');
    }
    try {
        return $fn();
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function jeab_fortnox_tokens_migrate_unlocked(): void
{
    $canonical = jeab_fortnox_tokens_canonical_path();
    $store = jeab_fortnox_tokens_normalize_store(jeab_fortnox_tokens_read_file($canonical));
    foreach (jeab_fortnox_tokens_legacy_paths() as $legacy) {
        if (!is_file($legacy)) {
            continue;
        }
        $from = jeab_fortnox_tokens_normalize_store(jeab_fortnox_tokens_read_file($legacy));
        foreach ($from as $account => $row) {
            if (jeab_fortnox_token_refresh_value($store[$account] ?? null) !== '') {
                continue;
            }
            if (jeab_fortnox_token_refresh_value($row) === '') {
                continue;
            }
            $store[$account] = $row;
        }
    }
    $before = jeab_fortnox_tokens_read_file($canonical);
    if ($store === [] || jeab_fortnox_tokens_normalize_store($before) == $store) {
        return;
    }
    jeab_fortnox_tokens_write_file($canonical, $store);
}

function jeab_fortnox_tokens_migrate(): void
{
    jeab_fortnox_tokens_with_lock(static function (): void {
        jeab_fortnox_tokens_migrate_unlocked();
    });
}

function jeab_fortnox_tokens_path(): string
{
    $canonical = jeab_fortnox_tokens_canonical_path();
    $store = jeab_fortnox_tokens_read_file($canonical);
    if (!jeab_fortnox_tokens_needs_legacy_scan($store)) {
        return $canonical;
    }
    foreach (jeab_fortnox_tokens_legacy_paths() as $legacy) {
        if (is_file($legacy)) {
            jeab_fortnox_tokens_migrate();
            break;
        }
    }
    return $canonical;
}

/** @return array{prefixes: list<string>, exact: list<string>} */
function jeab_web_deny_rules(): array
{
    static $rules;
    if ($rules !== null) {
        return $rules;
    }
    $fallback = [
        'prefixes' => ['data', 'schema', 'src', 'tests', 'fullmakt/data', 'fullmakt/lib', 'fullmakt/vendor', 'gi/data', 'privat/fullmakt/signed', 'data/.private/fullmakt/signed'],
        'exact' => ['README.md', 'AGENTS.md'],
    ];
    $file = (defined('JEAB_ROOT') ? JEAB_ROOT : dirname(__DIR__)) . '/deploy/sensitive-paths.txt';
    if (!is_file($file) || !is_readable($file)) {
        $rules = $fallback;
        return $rules;
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        $rules = $fallback;
        return $rules;
    }
    $prefixes = [];
    $exact = [];
    foreach ($lines as $line) {
        $line = preg_replace('/#.*$/', '', $line) ?? '';
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^prefix\s+(\S+)$/', $line, $match) === 1) {
            $prefixes[] = trim($match[1], '/');
            continue;
        }
        if (preg_match('/^exact\s+(\S+)$/', $line, $match) === 1) {
            $exact[] = trim($match[1], '/');
        }
    }
    if ($prefixes === [] && $exact === []) {
        $rules = $fallback;
        return $rules;
    }
    $rules = ['prefixes' => $prefixes, 'exact' => $exact];
    return $rules;
}

function jeab_http_path_matches_web_deny(string $path): bool
{
    $trimmed = trim($path, '/');
    $rules = jeab_web_deny_rules();
    $base = $trimmed;
    $slash = strrpos($trimmed, '/');
    if ($slash !== false) {
        $base = substr($trimmed, $slash + 1);
    }
    foreach ($rules['exact'] as $name) {
        if (strcasecmp($trimmed, $name) === 0) {
            return true;
        }
        // A slash-free name is the filename, not only the web root.
        // exact README.md therefore also matches fullmakt/README.md.
        if ($name !== '' && !str_contains($name, '/') && strcasecmp($base, $name) === 0) {
            return true;
        }
    }
    foreach ($rules['prefixes'] as $prefix) {
        if ($prefix === '') {
            continue;
        }
        if ($trimmed === $prefix || str_starts_with($trimmed, $prefix . '/')) {
            return true;
        }
    }
    return false;
}

function jeab_http_path_is_sensitive(string $path): bool
{
    $query = strpos($path, '?');
    if ($query !== false) {
        $path = substr($path, 0, $query);
    }
    $path = rawurldecode($path);
    if ($path === '') {
        return false;
    }
    $path = '/' . trim($path, '/');
    if (jeab_http_path_matches_web_deny($path)) {
        return true;
    }
    $blocked = [
        '/config.php',
        '/passwords.json',
        '/README.md',
        '/AGENTS.md',
        '/rot/fortnox_config.php',
        '/rot/fortnox_tokens.json',
        '/rot/tokens.json',
    ];
    if (in_array($path, $blocked, true)) {
        return true;
    }
    if ($path === '/rot/logs' || str_starts_with($path, '/rot/logs/')) {
        return true;
    }
    if (preg_match('#(?:^|/)\.env(?:\.|$)#', $path) === 1) {
        return true;
    }
    return preg_match('#^/rot/.+\.(?:json|jsonl|txt|sqlite|log)$#i', $path) === 1;
}

function jeab_shield_deny_http(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if ($uri === '' || !jeab_http_path_is_sensitive($uri)) {
        return;
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo "Forbidden\n";
    exit;
}

function jeab_shield_write_file(string $file, string $contents): bool
{
    $dir = dirname($file);
    if (!is_dir($dir) || !is_writable($dir)) {
        return false;
    }
    $tmp = $dir . '/.jeab-write-' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function jeab_shield_arm_php(string $file): bool
{
    if (!is_file($file) || !is_readable($file) || !is_writable($file)) {
        return false;
    }
    $raw = file_get_contents($file);
    if (!is_string($raw)) {
        return false;
    }
    if (str_contains($raw, 'jeab-deny-direct')) {
        return true;
    }
    $snippet = <<<'PHP'

if (PHP_SAPI !== 'cli') { /* jeab-deny-direct */
    $jeabScript = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $jeabSelf = realpath(__FILE__);
    if ($jeabScript !== false && $jeabSelf !== false && $jeabScript === $jeabSelf) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        exit;
    }
}

PHP;
    if (preg_match('/^<\?php\b/', $raw) === 1) {
        $updated = preg_replace('/^<\?php\b/', '<?php' . $snippet, $raw, 1);
    } else {
        $updated = "<?php" . $snippet . "?>\n" . $raw;
    }
    if (!is_string($updated) || !str_contains($updated, 'jeab-deny-direct')) {
        return false;
    }
    return jeab_shield_write_file($file, $updated);
}

function jeab_shield_retarget_oauth(string $file): bool
{
    if (!is_file($file) || !is_readable($file) || !is_writable($file)) {
        return false;
    }
    $raw = file_get_contents($file);
    if (!is_string($raw)) {
        return false;
    }
    if (str_contains($raw, 'jeab-fortnox-private')) {
        return true;
    }
    $config = preg_replace(
        '#\$config\s*=\s*require\s+__DIR__\s*\.\s*[\'"]/fortnox_config\.php[\'"]\s*;#',
        "\$config = require (function_exists('jeab_fortnox_config_path') ? jeab_fortnox_config_path() : __DIR__ . '/fortnox_config.php'); /* jeab-fortnox-private */",
        $raw,
        1,
        $configCount
    );
    if (!is_string($config) || $configCount !== 1) {
        return false;
    }
    $updated = preg_replace(
        '#\$tokensFile\s*=\s*__DIR__\s*\.\s*[\'"]/fortnox_tokens\.json[\'"]\s*;#',
        "\$tokensFile = function_exists('jeab_fortnox_tokens_path') ? jeab_fortnox_tokens_path() : __DIR__ . '/fortnox_tokens.json';",
        $config,
        1,
        $tokenCount
    );
    if (!is_string($updated) || $tokenCount !== 1) {
        return false;
    }
    return jeab_shield_write_file($file, $updated);
}

function jeab_shield_retarget_logger(string $file): bool
{
    if (!is_file($file) || !is_readable($file) || !is_writable($file)) {
        return false;
    }
    $raw = file_get_contents($file);
    if (!is_string($raw)) {
        return false;
    }
    if (str_contains($raw, 'jeab-log-private')) {
        return true;
    }
    $updated = preg_replace(
        '#__DIR__\s*\.\s*[\'"]/logs[\'"]#',
        "(function_exists('jeab_data_dir') ? jeab_data_dir() . '/rot/logs' : __DIR__ . '/logs') /* jeab-log-private */",
        $raw,
        1,
        $count
    );
    if (!is_string($updated) || $count !== 1) {
        return false;
    }
    return jeab_shield_write_file($file, $updated);
}

function jeab_shield_skip_name(string $name): bool
{
    if ($name === '.' || $name === '..' || $name === '.private' || $name === '.htaccess' || $name === '.gitkeep' || $name === 'config.php') {
        return true;
    }
    if (str_starts_with($name, '.')) {
        return true;
    }
    return str_ends_with($name, '.example');
}

function jeab_shield_move(string $from, string $to): void
{
    if (!file_exists($from) || jeab_shield_skip_name(basename($from))) {
        return;
    }
    $parent = dirname($to);
    if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
        return;
    }
    if (is_dir($from)) {
        if (!is_dir($to) && !@mkdir($to, 0775, true) && !is_dir($to)) {
            return;
        }
        foreach (scandir($from) ?: [] as $child) {
            if (jeab_shield_skip_name($child)) {
                continue;
            }
            jeab_shield_move($from . '/' . $child, $to . '/' . $child);
        }
        return;
    }
    if (jeab_fortnox_is_token_filename($from) || jeab_fortnox_is_token_filename($to)) {
        if (is_file($to) && jeab_fortnox_token_file_has_refresh($to)) {
            return;
        }
        if (jeab_fortnox_token_file_has_refresh($from)) {
            jeab_data_copy_over($from, $to);
        }
        return;
    }
    if (is_file($to)) {
        $fromMtime = (int) (@filemtime($from) ?: 0);
        $toMtime = (int) (@filemtime($to) ?: 0);
        if ($fromMtime > $toMtime) {
            @rename($from, $to);
        } else {
            @unlink($from);
        }
        return;
    }
    @rename($from, $to);
}

function jeab_shield_relocate_data_tree(string $publicDir, string $privateDir): void
{
    if (!is_dir($publicDir)) {
        return;
    }
    if (!is_dir($privateDir) && !@mkdir($privateDir, 0775, true) && !is_dir($privateDir)) {
        return;
    }
    foreach (scandir($publicDir) ?: [] as $name) {
        if (jeab_shield_skip_name($name)) {
            continue;
        }
        jeab_shield_move($publicDir . '/' . $name, $privateDir . '/' . $name);
    }
}

function jeab_shield_relocate(): void
{
    if (!defined('JEAB_ROOT')) {
        return;
    }
    $private = jeab_data_dir();
    if (!is_dir($private) && !@mkdir($private, 0775, true) && !is_dir($private)) {
        return;
    }
    $lockPath = $private . '/.lock';
    $lock = @fopen($lockPath, 'c');
    if ($lock !== false) {
        @flock($lock, LOCK_EX);
    }
    try {
        jeab_shield_arm_php(JEAB_ROOT . '/data/config.php');
        jeab_shield_arm_php(JEAB_ROOT . '/config.php');
        jeab_shield_relocate_data_tree(jeab_public_data_dir(), $private);
        if (is_file(JEAB_ROOT . '/passwords.json')) {
            jeab_shield_move(JEAB_ROOT . '/passwords.json', $private . '/passwords.json');
        }
        $oauth = JEAB_ROOT . '/rot/oauth_setup.php';
        $logger = JEAB_ROOT . '/rot/logger.php';
        $oauthReady = !is_file($oauth) || jeab_shield_retarget_oauth($oauth);
        $logReady = !is_file($logger) || jeab_shield_retarget_logger($logger);
        if ($oauthReady) {
            $config = JEAB_ROOT . '/rot/fortnox_config.php';
            if (is_file($config)) {
                jeab_shield_arm_php($config);
            }
            jeab_shield_move($config, $private . '/rot/fortnox_config.php');
            jeab_shield_move(JEAB_ROOT . '/rot/fortnox_tokens.json', $private . '/rot/fortnox_tokens.json');
            jeab_shield_move(JEAB_ROOT . '/rot/tokens.json', $private . '/rot/tokens.json');
        }
        if ($logReady && is_dir(JEAB_ROOT . '/rot/logs')) {
            jeab_shield_move(JEAB_ROOT . '/rot/logs', $private . '/rot/logs');
        }
        if (is_file(JEAB_ROOT . '/rot/.env')) {
            jeab_shield_move(JEAB_ROOT . '/rot/.env', $private . '/rot/.env');
        }
    } finally {
        if ($lock !== false) {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }
}

function jeab_shield_move_leak(string $from, string $to): void
{
    if (!is_file($from)) {
        return;
    }
    $dir = dirname($to);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }
    if (!is_file($to)) {
        jeab_data_try_move($from, $to);
        return;
    }
    $toSize = (int) (@filesize($to) ?: 0);
    $fromSize = (int) (@filesize($from) ?: 0);
    if ($toSize === 0 && $fromSize > 0) {
        @unlink($to);
        jeab_data_try_move($from, $to);
        return;
    }
    if ($toSize > 0) {
        @unlink($from);
    }
}

function jeab_shield_relocate_leaks(): void
{
    if (!defined('JEAB_ROOT')) {
        return;
    }
    $private = jeab_data_dir();
    if (!is_dir($private) && !@mkdir($private, 0775, true) && !is_dir($private)) {
        return;
    }
    $lockPath = $private . '/.lock';
    $lock = @fopen($lockPath, 'c');
    if ($lock !== false) {
        @flock($lock, LOCK_EX);
    }
    try {
        $public = jeab_public_data_dir();
        foreach ([
            'login-throttle.json',
            'app-errors-webhook-resolved.json',
            'fellogg-open-asks.json',
            'app-errors-webhook-seen.json',
            'app-errors.jsonl',
        ] as $name) {
            jeab_shield_move_leak($public . '/' . $name, $private . '/' . $name);
        }
        $secretDir = $public . '/secrets';
        if (is_dir($secretDir)) {
            foreach (scandir($secretDir) ?: [] as $name) {
                if ($name === '.' || $name === '..' || jeab_shield_skip_name($name)) {
                    continue;
                }
                if (!str_ends_with($name, '.json')) {
                    continue;
                }
                jeab_shield_move_leak($secretDir . '/' . $name, $private . '/secrets/' . $name);
            }
        }
        if (function_exists('jeab_notes_path')) {
            jeab_notes_path();
            jeab_notes_photos_dir();
        }
    } finally {
        if ($lock !== false) {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }
}

function jeab_hsts_value(): ?string
{
    // Egen HTTPS-koll. jeab_shield_web() körs innan bootstrap hinner
    // definiera jeab_is_https(), så den funktionen får inte krävas här.
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    if (!$https) {
        $https = isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443';
    }
    if (!$https) {
        return null;
    }
    return 'max-age=31536000';
}

function jeab_send_hsts(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    $value = jeab_hsts_value();
    if ($value === null) {
        return;
    }
    header('Strict-Transport-Security: ' . $value);
}

/** @return list<string> */
function jeab_self_hosted_font_rels(): array
{
    return [
        'assets/fonts/inter-latin-400.woff2',
        'assets/fonts/inter-latin-500.woff2',
        'assets/fonts/inter-latin-600.woff2',
        'assets/fonts/inter-latin-700.woff2',
        'assets/fonts/ibm-plex-mono-latin-400.woff2',
        'assets/fonts/ibm-plex-mono-latin-500.woff2',
    ];
}

/** True when every weight the tokens name is a file on this host. */
function jeab_fonts_hosted_locally(): bool
{
    $root = defined('JEAB_ROOT') ? JEAB_ROOT : dirname(__DIR__);
    foreach (jeab_self_hosted_font_rels() as $rel) {
        if (!is_file($root . '/' . $rel)) {
            return false;
        }
    }

    return true;
}

function jeab_csp_value(string $frameAncestors = 'self', ?bool $localFonts = null): string
{
    $frame = $frameAncestors === 'none' ? "'none'" : "'self'";
    unset($localFonts);
    $style = "style-src 'self' 'unsafe-inline'";
    $font = "font-src 'self' data:";
    $connect = "connect-src 'self'";

    return implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline'",
        $style,
        $font,
        "img-src 'self' data: blob:",
        $connect,
        'frame-ancestors ' . $frame,
        "base-uri 'self'",
        "form-action 'self' https://login.microsoftonline.com",
        "object-src 'none'",
    ]);
}

function jeab_frame_options_value(): string
{
    return 'SAMEORIGIN';
}

function jeab_send_app_security_headers(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: ' . jeab_frame_options_value());
    header('Content-Security-Policy: ' . jeab_csp_value());
    jeab_send_hsts();
}

function jeab_bokning_apex_target(): string
{
    $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
    return '/index.php' . ($query !== '' ? '?' . $query : '');
}

function jeab_request_is_bokning_index(?string $path = null): bool
{
    if ($path === null) {
        $path = rawurldecode((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/'));
    }
    $path = '/' . trim($path, '/');
    return $path === '/bokning' || $path === '/bokning/index.php';
}

function jeab_shield_relocate_sqlite(): void
{
    if (!function_exists('jeab_public_data_dir')) {
        return;
    }
    foreach (['app.sqlite', 'app.sqlite-wal', 'app.sqlite-shm'] as $name) {
        $public = jeab_public_data_dir() . '/' . $name;
        if (!is_file($public)) {
            continue;
        }
        jeab_resolve_sqlite_path($public);
    }
}

function jeab_resolved_web_root(): string
{
    if (defined('JEAB_ROOT')) {
        $real = realpath(JEAB_ROOT);
        if (is_string($real) && $real !== '') {
            return $real;
        }
    }
    $fallback = dirname(__DIR__);
    $real = realpath($fallback);
    return is_string($real) && $real !== '' ? $real : $fallback;
}

/** Webbrot: testets _JEAB_APP_ROOT / JEAB_APP_ROOT, annars jeab_resolved_web_root(). */
function jeab_app_web_root(): string
{
    $forced = $GLOBALS['_JEAB_APP_ROOT'] ?? null;
    if (!is_string($forced) || $forced === '') {
        $env = getenv('JEAB_APP_ROOT');
        $forced = is_string($env) ? $env : '';
    }
    if ($forced !== '') {
        $real = realpath($forced);
        $path = is_string($real) && $real !== '' ? $real : $forced;

        return rtrim(str_replace('\\', '/', $path), '/');
    }

    return rtrim(str_replace('\\', '/', jeab_resolved_web_root()), '/');
}

/**
 * Syskon till public_html: dirname(webbrot)/privat, alltså /flowstack.example.test/privat.
 * Samma plats som fullmakt och gi redan använder. Ingen fallback in i webbroten.
 */
function jeab_outside_privat_dir(): string
{
    if (function_exists('jeab_demo_mode') && jeab_demo_mode()) {
        return function_exists('jeab_data_dir') ? jeab_data_dir() : (defined('JEAB_ROOT') ? JEAB_ROOT . '/data/.private' : jeab_app_web_root() . '/data/.private');
    }

    return dirname(jeab_app_web_root()) . '/privat';
}

/**
 * Lånearkivet ska inte ligga som statisk fil under gi/data/.
 * ../privat/gi när den katalogen finns och går att skriva, annars
 * data/.private/gi. null betyder att den enda kopian ska ligga kvar.
 */
function jeab_gi_loans_private_file(): ?string
{
    $privat = null;
    if (!empty($GLOBALS['_JEAB_PRIVAT_DIR']) && is_string($GLOBALS['_JEAB_PRIVAT_DIR'])) {
        $candidate = rtrim(str_replace('\\', '/', $GLOBALS['_JEAB_PRIVAT_DIR']), '/');
        if (is_dir($candidate) && is_writable($candidate)) {
            $privat = $candidate;
        }
    } elseif (!(function_exists('jeab_demo_mode') && jeab_demo_mode())) {
        $candidate = dirname(jeab_resolved_web_root()) . '/privat';
        if (is_dir($candidate) && is_writable($candidate)) {
            $privat = $candidate;
        }
    }
    if ($privat !== null) {
        return $privat . '/gi/loans.json';
    }
    if (!empty($GLOBALS['_JEAB_DATA_DIR']) && is_string($GLOBALS['_JEAB_DATA_DIR'])) {
        $dir = rtrim(str_replace('\\', '/', $GLOBALS['_JEAB_DATA_DIR']), '/');
    } elseif (defined('JEAB_ROOT')) {
        $dir = jeab_data_dir();
    } else {
        $dir = jeab_resolved_web_root() . '/data/.private';
    }
    if (is_file($dir)) {
        return null;
    }
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return null;
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return null;
    }
    return $dir . '/gi/loans.json';
}

function jeab_gi_adopt_loans_file(string $publicFile, string $privateFile): bool
{
    if ($publicFile === $privateFile) {
        return true;
    }
    if (!is_file($publicFile) || is_link($publicFile)) {
        return !is_link($publicFile);
    }
    $dir = dirname($privateFile);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false;
    }
    $in = @fopen($publicFile, 'rb');
    if ($in === false) {
        return false;
    }
    if (!@flock($in, LOCK_EX)) {
        fclose($in);
        return false;
    }
    try {
        clearstatcache(true, $publicFile);
        clearstatcache(true, $privateFile);
        $pubSize = @filesize($publicFile);
        if ($pubSize === false) {
            return false;
        }
        $privExists = is_file($privateFile);
        $privSize = $privExists ? @filesize($privateFile) : false;
        if ($privExists && $privSize === $pubSize) {
            @chmod($privateFile, 0600);
            @chmod($dir, 0700);
            @unlink($publicFile);
            return !is_file($publicFile);
        }
        $source = $publicFile;
        if ($privExists && $privSize !== false && (int) $privSize > 0 && (int) $pubSize > 0) {
            $pubMtime = (int) (@filemtime($publicFile) ?: 0);
            $privMtime = (int) (@filemtime($privateFile) ?: 0);
            if ($privMtime >= $pubMtime) {
                @unlink($publicFile);
                @chmod($privateFile, 0600);
                return !is_file($publicFile);
            }
        }
        if (!jeab_data_copy_over($source, $privateFile)) {
            return false;
        }
        clearstatcache(true, $privateFile);
        if (@filesize($privateFile) !== $pubSize) {
            return false;
        }
        @chmod($privateFile, 0600);
        @chmod($dir, 0700);
        @unlink($publicFile);
        return !is_file($publicFile);
    } finally {
        @flock($in, LOCK_UN);
        @fclose($in);
    }
}

function jeab_gi_loans_file(string $publicFile): string
{
    $private = jeab_gi_loans_private_file();
    if ($private === null || $private === $publicFile) {
        return $publicFile;
    }
    if (!jeab_gi_adopt_loans_file($publicFile, $private) && is_file($publicFile)) {
        return $publicFile;
    }
    return $private;
}

function jeab_shield_relocate_gi_loans(): void
{
    $public = jeab_resolved_web_root() . '/gi/data/loans.json';
    jeab_gi_loans_file($public);
}

function jeab_shield_relocate_fullmakt(): void
{
    if (!defined('JEAB_ROOT')) {
        return;
    }
    $legacy = JEAB_ROOT . '/fullmakt/data';
    if (!is_dir($legacy)) {
        return;
    }
    $boot = JEAB_ROOT . '/fullmakt/lib/bootstrap.php';
    if (!is_file($boot)) {
        return;
    }
    try {
        require_once $boot;
        if (function_exists('fullmakt_boot')) {
            fullmakt_boot();
        }
    } catch (Throwable $e) {
        return;
    }
}

function jeab_shield_leaks_stamp_path(): string
{
    return jeab_data_dir() . '/.shield-leaks-ok';
}

/** Sidan tar inte det exklusiva låset när stämpeln säger att trädet redan är rent. */
function jeab_shield_leaks_deferred(): bool
{
    if (!empty($GLOBALS['_JEAB_SHIELD_LEAKS_FORCE'])) {
        return false;
    }

    return is_file(jeab_shield_leaks_stamp_path());
}

function jeab_shield_leaks_mark_clean(): void
{
    $dir = jeab_data_dir();
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }
    $path = $dir . '/.shield-leaks-ok';
    if (is_file($path)) {
        return;
    }
    @file_put_contents($path, "ok\n", LOCK_EX);
}

/** Webbträff: hoppa över flocken när tick redan har städat. */
function jeab_shield_relocate_leaks_for_page(): void
{
    if (jeab_shield_leaks_deferred()) {
        return;
    }
    jeab_shield_relocate_leaks();
    jeab_shield_leaks_mark_clean();
}

/** Tick flyttar läckor även när stämpeln finns, och sätter den efteråt. */
function jeab_shield_relocate_leaks_from_tick(): void
{
    jeab_shield_relocate_leaks();
    jeab_shield_leaks_mark_clean();
}

function jeab_shield_web(): void
{
    if (PHP_SAPI === 'cli' || !empty($GLOBALS['_JEAB_SHIELD_DONE'])) {
        return;
    }
    $GLOBALS['_JEAB_SHIELD_DONE'] = true;
    jeab_send_app_security_headers();
    if (defined('JEAB_ROOT')) {
        $oauth = jeab_fortnox_app_root() . '/rot/oauth_setup.php';
        if (is_file($oauth)) {
            jeab_shield_retarget_oauth($oauth);
        }
        jeab_fortnox_tokens_path();
    }
    jeab_shield_relocate_leaks_for_page();
    jeab_shield_relocate_sqlite();
    jeab_shield_relocate_fullmakt();
    jeab_shield_relocate_gi_loans();
    jeab_shield_deny_http();
}
