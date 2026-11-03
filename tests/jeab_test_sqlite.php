<?php

declare(strict_types=1);

/**
 * Tillfällig sqlite när den vanliga drivrutinen inte öppnar en databas.
 * Definierar jeab_store_pdo_override(), injektionspunkten i src/store.php.
 * Src laddar inte den här filen. Testköraren gör det via auto_prepend.
 * Aktiveras bara när båda gäller: JEAB_TEST_TEMP_SQLITE=1, eller ett
 * CLI-testskript, och sqlite-sökvägen ligger under systemets temp.
 * En markörfil räcker inte. Arbetet sker först när injektionen anropas.
 * Dubbel inladdning är ofarlig.
 */

if (!function_exists('jeab_store_pdo_override')) {

$GLOBALS['_JEAB_TEST_SQLITE_BOOT_DISABLE'] = (string) getenv('JEAB_DISABLE_SQLITE');

function jeab_test_sqlite_script(): string
{
    $script = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
    if ($script === '' && isset($_SERVER['argv'][0])) {
        $script = (string) $_SERVER['argv'][0];
    }
    $script = str_replace('\\', '/', $script);
    // `php tests/foo_test.php` lämnar SCRIPT_FILENAME relativ, utan inledande /.
    if ($script !== '' && !str_starts_with($script, '/')) {
        $cwd = str_replace('\\', '/', (string) getcwd());
        if ($cwd !== '') {
            $script = rtrim($cwd, '/') . '/' . $script;
        }
    }
    $real = $script !== '' ? realpath($script) : false;
    if (is_string($real) && $real !== '') {
        $script = str_replace('\\', '/', $real);
    }

    return $script;
}

function jeab_test_sqlite_is_test_script(): bool
{
    return preg_match('#(?:^|/)tests/[^/]+_test\.php$#', jeab_test_sqlite_script()) === 1;
}

function jeab_test_sqlite_opt_out_script(): bool
{
    return in_array(basename(jeab_test_sqlite_script()), [
        'store_contacts_sql_only_test.php',
        'store_open_once_test.php',
    ], true);
}

function jeab_path_under_temp(string $path): bool
{
    $path = str_replace('\\', '/', $path);
    if ($path === '') {
        return false;
    }
    $roots = [rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/')];
    $real = realpath(sys_get_temp_dir());
    if (is_string($real) && $real !== '') {
        $roots[] = rtrim(str_replace('\\', '/', $real), '/');
    }
    foreach (array_unique($roots) as $temp) {
        if ($temp !== '' && ($path === $temp || str_starts_with($path, $temp . '/'))) {
            return true;
        }
    }

    return false;
}

function jeab_test_sqlite_target_path(): string
{
    if (!function_exists('jeab_config')) {
        return '';
    }
    $path = (string) (jeab_config()['db']['sqlite_path'] ?? '');
    if ($path === '') {
        $path = function_exists('jeab_data_dir') ? jeab_data_dir() . '/app.sqlite' : '';
    }

    return $path;
}

/** Tom sträng när markören inte ligger under temp. Den får då inte skrivas. */
function jeab_test_sqlite_marker_file(): string
{
    $dir = function_exists('jeab_data_dir') ? jeab_data_dir() : '';
    $key = hash('sha256', str_replace('\\', '/', $dir));
    $marker = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/jeab-test-sqlite-markers/' . $key;
    if (!jeab_path_under_temp($marker)) {
        return '';
    }

    return $marker;
}

function jeab_test_sqlite_arm(): void
{
    static $done = [];
    if (jeab_test_sqlite_opt_out_script()) {
        putenv('JEAB_ASSERT_SQL_DOWN=1');
        $_ENV['JEAB_ASSERT_SQL_DOWN'] = '1';

        return;
    }
    if (getenv('JEAB_ASSERT_SQL_DOWN') === '1' || !jeab_test_sqlite_is_test_script() || PHP_SAPI !== 'cli') {
        return;
    }
    $path = jeab_test_sqlite_target_path();
    if (!jeab_path_under_temp($path)) {
        return;
    }
    $dir = function_exists('jeab_data_dir') ? jeab_data_dir() : '';
    if (isset($done[$dir])) {
        return;
    }
    $done[$dir] = true;
    putenv('JEAB_TEST_TEMP_SQLITE=1');
    $_ENV['JEAB_TEST_TEMP_SQLITE'] = '1';
    $marker = jeab_test_sqlite_marker_file();
    if ($marker === '' || !jeab_path_under_temp($marker)) {
        return;
    }
    $parent = dirname($marker);
    if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
        return;
    }
    if (!is_file($marker)) {
        @file_put_contents($marker, $dir);
    }
    register_shutdown_function(static function () use ($marker): void {
        @unlink($marker);
    });
}

/** Miljövariabeln, eller ett CLI-test. php -S och fpm behöver variabeln. */
function jeab_test_sqlite_credential(): bool
{
    if (getenv('JEAB_TEST_TEMP_SQLITE') === '1') {
        return true;
    }

    return PHP_SAPI === 'cli' && jeab_test_sqlite_is_test_script();
}

function jeab_test_sqlite_wanted(): bool
{
    if (getenv('JEAB_ASSERT_SQL_DOWN') === '1' || jeab_test_sqlite_opt_out_script()) {
        return false;
    }
    // Ett CLI-barn (inte ett testskript) som själv stänger av sqlite efter start vill ha SQL nere.
    // Ärvd JEAB_DISABLE_SQLITE från testet (t.ex. hook.php-barn) får fortfarande temp-sqlite.
    // Skriptnamnet läses från argv, eftersom ett barn kan skriva om SCRIPT_FILENAME.
    if (PHP_SAPI === 'cli' && getenv('JEAB_DISABLE_SQLITE') === '1'
        && preg_match('#(?:^|/)tests/[^/]+_test\\.php$#', str_replace('\\', '/', (string) ($_SERVER['argv'][0] ?? ''))) !== 1
        && ($GLOBALS['_JEAB_TEST_SQLITE_BOOT_DISABLE'] ?? '') !== '1') {
        return false;
    }
    if (!jeab_test_sqlite_credential()) {
        return false;
    }
    if (!jeab_path_under_temp(jeab_test_sqlite_target_path())) {
        return false;
    }
    $marker = jeab_test_sqlite_marker_file();
    if ($marker !== '' && !jeab_path_under_temp($marker)) {
        return false;
    }

    return true;
}

/** Öppnar den tillfälliga sqlite-filen även när testet har stängt av sqlite. */
function jeab_test_sqlite_connect(): PDO
{
    $prev = getenv('JEAB_DISABLE_SQLITE');
    putenv('JEAB_DISABLE_SQLITE');
    unset($_ENV['JEAB_DISABLE_SQLITE']);
    // Testservern (php -S) migrerar inte schemat per request; den tillfälliga filen ska ha tabellerna.
    $prevDdl = $GLOBALS['_JEAB_SCHEMA_DDL'] ?? null;
    $GLOBALS['_JEAB_SCHEMA_DDL'] = 1;
    try {
        return jeab_pdo_connect_sqlite();
    } finally {
        $GLOBALS['_JEAB_SCHEMA_DDL'] = $prevDdl;
        if ($prev === false) {
            putenv('JEAB_DISABLE_SQLITE');
            unset($_ENV['JEAB_DISABLE_SQLITE']);
        } else {
            putenv('JEAB_DISABLE_SQLITE=' . $prev);
            $_ENV['JEAB_DISABLE_SQLITE'] = $prev;
        }
    }
}

/** Armar först, och lämnar en PDO bara när aktiveringsvillkoren håller. */
function jeab_store_pdo_override(): ?PDO
{
    jeab_test_sqlite_arm();
    if (!jeab_test_sqlite_wanted()) {
        return null;
    }

    return jeab_test_sqlite_connect();
}

}
