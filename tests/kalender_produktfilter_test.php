<?php

declare(strict_types=1);

/**
 * N-69: produktchipsen i kalendern syns utan Filter-klick, 44 px tapyta.
 */

$tmp = sys_get_temp_dir() . '/jeab-kalender-produktfilter-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
putenv('JEAB_DISABLE_SQLITE=1');
session_save_path($tmp);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';
date_default_timezone_set('Europe/Stockholm');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function n69(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$_SESSION['jeab_email'] = 'agenter@flowstack.example.test';
$_GET = ['p' => 'bookings', 'co' => 'jarfalla'];
$win = jeab_calendar_visible_window();
$monday = $win['monday'];
$weekOffset = (int) $win['weekOffset'];
$bookings = [];
$selectedInstallers = [];
$flash = null;
$error = null;
$fromReminder = null;
$html = require dirname(__DIR__) . '/src/pages/bookings.php';
$css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/app.css');

n69(is_string($html), 'kalendersidan ritas');
n69(!str_contains($html, '>Filter</summary>') && !str_contains($html, '<details class="cal-filter-products">'), 'ingen extra Filter-ruta');
n69(str_contains($html, 'class="cal-filter-products"'), 'produktchipsen har egen rad');
n69(preg_match('/<div class="cal-filter-products">\s*<a class="chip chip-job/', $html) === 1, 'produktchipsen ligger synliga i rutan');
n69(str_contains($html, '>Batteri</a>') && str_contains($html, '>Sol</a>') && str_contains($html, '>Elbilsladdare</a>') && str_contains($html, '>Service</a>'), 'alla produkter syns');

$at = strpos($css, 'body.page-bookings .cal-filter-products .chip');
n69($at !== false, 'produktchips har egen css');
$open = strpos($css, '{', $at === false ? 0 : $at);
$close = strpos($css, '}', $open === false ? 0 : $open);
$rule = ($open === false || $close === false) ? '' : substr($css, $at, $close - $at + 1);
n69(str_contains($rule, 'min-height: 44px'), 'produktchipsen är minst 44 px');

echo "ALLA OK\n";
