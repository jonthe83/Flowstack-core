<?php

declare(strict_types=1);

/**
 * Huvudmenyn är samma komponent på Inkorg som på övriga sidor.
 * Jämför jeab_chrome_html() (src/layout.php). Ingen kunddata, inget utskick.
 */

$tmp = sys_get_temp_dir() . '/jeab-menu-same-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
file_put_contents($tmp . '/store.json', "{}\n");

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function menu_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/**
 * Samma meny, oavsett vilken flik som är vald och vart bolagslänkarna pekar.
 * is-on, aria-current, href, title och oläst-märket är sidans tillstånd.
 */
function menu_skeleton(string $html): string
{
    $html = preg_replace('/\s+/', ' ', trim($html)) ?? '';
    $html = preg_replace('/\s(?:href|title)="[^"]*"/', '', $html) ?? '';
    $html = preg_replace('/\saria-current="[^"]*"/', '', $html) ?? '';
    $html = preg_replace_callback('/\sclass="([^"]*)"/', static function (array $m): string {
        $classes = preg_split('/\s+/', trim($m[1])) ?: [];
        $classes = array_values(array_filter(
            $classes,
            static fn (string $class): bool => $class !== '' && $class !== 'is-on'
        ));

        return $classes === [] ? '' : ' class="' . implode(' ', $classes) . '"';
    }, $html) ?? '';
    $html = preg_replace('/<span class="tab-count"[^>]*>.*?<\/span>/', '', $html) ?? '';

    return trim($html);
}

$_SESSION['jeab_company'] = 'jarfalla';
$_SESSION['jeab_inkorg_box'] = 'jarfalla';

$pages = ['inkorg', 'reminders', 'bookings', 'ordrar'];
$chrome = [];
foreach ($pages as $page) {
    $_GET = ['p' => $page, 'co' => 'jarfalla', 'box' => 'jarfalla'];
    $chrome[$page] = jeab_chrome_html($page);
    menu_ok(str_contains($chrome[$page], '<header class="chrome">'), $page . ' ritar den gemensamma menyn');
    menu_ok(str_contains($chrome[$page], 'class="chrome-company"'), $page . ' har bolagsväljaren');
    menu_ok(str_contains($chrome[$page], '>Malmö</a>') && str_contains($chrome[$page], '>Järfälla</a>'), $page . ' har Malmö och Järfälla');
    menu_ok(str_contains($chrome[$page], '<nav class="tabs" aria-label="Moduler">'), $page . ' har modulfliken');
    foreach (['Inkorg', 'Påminnelser', 'Kalender', 'Ordrar', 'Kalkyl', 'Övrigt'] as $label) {
        menu_ok(str_contains($chrome[$page], '>' . $label . '</a>') || str_contains($chrome[$page], '>' . $label . '<'), $page . ' har menypunkten ' . $label);
    }
}

$inkorgMenu = menu_skeleton($chrome['inkorg']);
foreach (['reminders', 'bookings', 'ordrar'] as $page) {
    menu_ok($inkorgMenu === menu_skeleton($chrome[$page]), 'menymarkup är samma för Inkorg och ' . $page);
}

$root = dirname(__DIR__);
$app = (string) file_get_contents($root . '/assets/css/app.css');
$inkorgCss = (string) file_get_contents($root . '/assets/css/inkorg.css');
menu_ok(preg_match('/\.chrome \{\s*position: sticky;.*?flex: 0 0 auto;/s', $app) === 1, 'den delade menyn får inte krympa');
menu_ok(!preg_match('/header\.chrome|body\.page-inkorg \.chrome\b|body\.page-inkorg \.tabs\b|body\.page-inkorg \.tab\b|body\.page-inkorg \.chrome-company/', $inkorgCss), 'inkorg.css stylar inte om huvudmenyn');

$layout = (string) file_get_contents($root . '/src/layout.php');
menu_ok(str_contains($layout, 'function jeab_chrome_html') && str_contains($layout, 'echo jeab_chrome_html($page);'), 'alla sidor, även Inkorg, skriver ut jeab_chrome_html');
