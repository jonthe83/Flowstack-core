<?php

declare(strict_types=1);

/**
 * Sidfart: auth-cache, notis-katalog, opcache-rad och att tunga bilder
 * inte ligger i sidor som inte visar dem. Påhittad data. Inget utskick.
 */

$tmp = sys_get_temp_dir() . '/jeab-page-speed-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
$diskSecret = 'disk-' . bin2hex(random_bytes(8));
file_put_contents($tmp . '/passwords.json', json_encode([
    'secret' => $diskSecret,
    'hashes' => [],
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'passwords_file' => $tmp . '/passwords.json',
], true) . ";\n");

if (function_exists('apcu_store') && apcu_store('jeab_apcu_probe', '1', 30) !== true && getenv('JEAB_APCU_REEXEC') !== '1') {
    putenv('JEAB_APCU_REEXEC=1');
    passthru(escapeshellarg(PHP_BINARY) . ' -d apc.enable_cli=1 ' . escapeshellarg(__FILE__), $reexec);
    exit($reexec);
}

if (!function_exists('apcu_fetch')) {
    $GLOBALS['_JEAB_APCU_TEST'] = [];
    function apcu_fetch(string $key, mixed &$success = null): mixed
    {
        $success = array_key_exists($key, $GLOBALS['_JEAB_APCU_TEST']);

        return $success ? $GLOBALS['_JEAB_APCU_TEST'][$key] : false;
    }
    function apcu_store(string $key, mixed $value, int $ttl = 0): bool
    {
        $GLOBALS['_JEAB_APCU_TEST'][$key] = $value;

        return true;
    }
    function apcu_delete(string $key): bool
    {
        unset($GLOBALS['_JEAB_APCU_TEST'][$key]);

        return true;
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

function pls(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$path = jeab_auth_path();
pls($path !== '' && is_file($path), 'lösenordsfilen finns');
$cachedSecret = 'cached-' . bin2hex(random_bytes(4));
apcu_store(jeab_auth_apcu_key($path), [
    'secret' => $cachedSecret,
    'hashes' => [],
    'entries' => [],
]);
$hit = jeab_auth_data();
pls(($hit['secret'] ?? '') === $cachedSecret, 'auth läser APCu i 60 s i stället för disk, fick ' . (string) ($hit['secret'] ?? ''));
$reloaded = jeab_auth_data(true);
pls(($reloaded['secret'] ?? '') === $diskSecret, 'skrivning och reload läser filen igen');
$again = jeab_auth_data();
pls(($again['secret'] ?? '') === $diskSecret, 'cachen efter reload är den nya filen');

$line = jeab_opcache_ini_line();
pls(str_contains($line, 'opcache enable=') && str_contains($line, 'validate_timestamps=') && str_contains($line, 'revalidate_freq='), 'opcache-raden har de tre ini-värdena: ' . $line);
pls(!str_contains($line, '/') && !str_contains($line, '@'), 'opcache-raden har ingen sökväg');
$logg = (string) file_get_contents(dirname(__DIR__) . '/src/pages/logg.php');
pls(str_contains($logg, 'jeab_opcache_ini_line'), 'Fellogg visar opcache-raden');

// Schemat skapas här, inte mitt i sidvisningen. Migrate-flaggan får inte ligga kvar:
// en riktig GET migrerar inte, och den flaggan stänger av den lätta notisvyn.
jeab_store();
unset($GLOBALS['_JEAB_MIGRATE_RUNS']);
$GLOBALS['_JEAB_PAGE_REQUEST'] = 1;
$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
pls(jeab_notiser_view_is_light(), 'notissidan är lätt');
$GLOBALS['_JEAB_NOTIS_CATALOG_BUILDS'] = 0;
$first = jeab_notiser_catalog();
$built = (int) $GLOBALS['_JEAB_NOTIS_CATALOG_BUILDS'];
$second = jeab_notiser_catalog();
pls($built === 1 && (int) $GLOBALS['_JEAB_NOTIS_CATALOG_BUILDS'] === 1, 'katalogen byggs en gång och läses sedan från cachen, byggde ' . $GLOBALS['_JEAB_NOTIS_CATALOG_BUILDS']);
pls($first === $second && $first !== [], 'cachad katalog är samma rader');
jeab_notiser_catalog_forget();
jeab_notiser_catalog();
pls((int) $GLOBALS['_JEAB_NOTIS_CATALOG_BUILDS'] === 2, 'en glömd katalog byggs om');
$notiser = (string) file_get_contents(dirname(__DIR__) . '/src/notiser.php');
pls(!str_contains($notiser, 'jeab_inkorg_threads_meta('), 'notiser bygger inte hela inkorgen');
pls(str_contains($notiser, 'SELECT id, company FROM inkorg_list'), 'notiser räknar via inkorg_list');

require_once dirname(__DIR__) . '/src/layout.php';
pls(!jeab_page_needs_app_js('links') && !jeab_page_needs_app_js('notiser') && !jeab_page_needs_app_js('logg'), 'Länkar, Notiser och Fellogg hoppar över app.js');
foreach (['src/layout.php', 'src/pages/links.php', 'src/pages/logg.php', 'src/pages/notiser.php'] as $rel) {
    $src = (string) file_get_contents(dirname(__DIR__) . '/' . $rel);
    pls(!str_contains($src, 'zon-karta.jpg') && !str_contains($src, 'reminder-sms.png') && !str_contains($src, 'reminder-mail.png'), $rel . ' laddar inte kartan eller påminnelsebilderna');
}
$sack = (string) file_get_contents(dirname(__DIR__) . '/src/pages/sack.php');
pls(str_contains($sack, "jeab_asset_url('assets/img/zon-karta.jpg')"), 'zon-kartan är versionerad där den länkas');

$index = (string) file_get_contents(dirname(__DIR__) . '/index.php');
$bookAt = strpos($index, "if (\$page === 'bookings')");
$requireAt = $bookAt === false ? false : strpos($index, "require __DIR__ . '/src/pages/bookings.php'", $bookAt);
$branch = ($bookAt !== false && $requireAt !== false) ? substr($index, $bookAt, $requireAt - $bookAt) : '';
$releaseAt = strpos($branch, 'jeab_release_session()');
pls($releaseAt !== false && strpos($branch, 'jeab_calendar_log_for_week') > $releaseAt, 'kalenderloggen håller inte sessionslåset');

echo "page-load-speed: ok\n";
