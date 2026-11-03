<?php

declare(strict_types=1);

/**
 * Regler och Vyer ska bli klara utan jeab_inkorg_threads och jeab_inkorg_view_total.
 * Sidomenyn tar flikarnas sparade tal. Övriga vyer räknas mot inkorg_list.
 * Importarkivet läses inte. Syntetiska rader, ingen kunddata.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-ix-settings-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
require $root . '/src/bootstrap.php';
require $root . '/src/layout.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function sf(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function sf_span(string $id, string $html): string
{
    if (preg_match('/data-ix-count="' . preg_quote($id, '/') . '">([^<]*)</', $html, $m) !== 1) {
        return '';
    }

    return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function sf_vy_count(string $name, string $html): int
{
    $pattern = '/' . preg_quote($name, '/') . ' <span class="vy-count">\((\d+)\)<\/span>/u';
    if (preg_match($pattern, $html, $m) !== 1) {
        return -1;
    }

    return (int) $m[1];
}

$vyerSrc = (string) file_get_contents($root . '/src/pages/inkorg_vyer.php');
sf(!str_contains($vyerSrc, 'jeab_inkorg_threads(') && !str_contains($vyerSrc, 'jeab_inkorg_view_total('), 'vyer-sidan anropar inte threads eller view_total');

$inkorgSrc = (string) file_get_contents($root . '/src/inkorg.php');
$sideStart = strpos($inkorgSrc, 'function jeab_inkorg_sidebar_html(');
$sideEnd = strpos($inkorgSrc, 'function jeab_inkorg_staff(', $sideStart === false ? 0 : $sideStart);
sf($sideStart !== false && $sideEnd !== false, 'sidomenyn finns i inkorg.php');
$sideSrc = substr($inkorgSrc, (int) $sideStart, (int) $sideEnd - (int) $sideStart);
sf(!str_contains($sideSrc, 'jeab_inkorg_threads(') && !str_contains($sideSrc, 'jeab_inkorg_view_total('), 'sidomenyn anropar inte threads eller view_total');
sf(str_contains($sideSrc, 'jeab_inkorg_sidebar_saved_counts'), 'sidomenyn tar sparade tal när inga tal skickas in');

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_GET = ['p' => 'inkorg-regler', 'box' => 'jarfalla', 'scope' => 'allas'];
$_SESSION['jeab_inkorg_box'] = 'jarfalla';

$pdo = jeab_pdo_connect_sqlite();
sf(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan går att sätta');
$store = jeab_inkorg_pdo_store();
sf($store instanceof JeabPdoInkorgStore, 'SQL-lagret är öppet');

$row = static function (string $id, string $status, array $tags, string $type) use ($store): void {
    $store->saveListRow([
        'id' => $id,
        'company' => 'jarfalla',
        'status' => $status,
        'deleted' => false,
        'assignee' => '',
        'updated_at' => '2024-06-01T10:00:00+00:00',
        'customer_email' => $id . '@exempel.test',
        'customer_name' => $id,
        'labels' => ['type' => $type],
        'tags' => $tags,
        'search_hay' => $id,
        'list_snippet' => $id,
    ]);
};
$row('closed-1', 'closed', [], 'Information');
$row('open-sol', 'open', ['sol'], 'Ny lead');
$row('open-info', 'open', [], 'Information');
$store->setListStamp('settings-seed');
sf($store->countHistorik('jarfalla') === 1, 'en stängd rad räknas i historik');

$menu = jeab_inkorg_fast_scope_menu('allas');
$key = jeab_inkorg_fast_count_key($menu, 'jarfalla', jeab_inkorg_me_id());
jeab_inkorg_fast_count_write($key, [
    'counts' => [
        'v-inkorg' => 9,
        'v-service' => 3,
        'v-information' => 2,
        'v-alla' => 8,
        'v-historik' => 4,
    ],
    'unread' => 0,
    'sources' => [],
]);

$GLOBALS['_JEAB_INKORG_THREADS_CALLS'] = 0;
$GLOBALS['_JEAB_INKORG_VIEW_TOTAL_CALLS'] = 0;
$GLOBALS['_JEAB_HS_INDEX_FULL'] = 0;
$t0 = microtime(true);
$regler = require $root . '/src/pages/inkorg_regler.php';
$reglerMs = (microtime(true) - $t0) * 1000;
sf(is_string($regler), 'regler-sidan ritas');
sf((int) $GLOBALS['_JEAB_INKORG_THREADS_CALLS'] === 0, 'regler anropar inte jeab_inkorg_threads');
sf((int) $GLOBALS['_JEAB_INKORG_VIEW_TOTAL_CALLS'] === 0, 'regler anropar inte jeab_inkorg_view_total');
sf((int) $GLOBALS['_JEAB_HS_INDEX_FULL'] === 0, 'regler läser inte importindexet');
sf(sf_span('v-historik', $regler) === '4', 'menyns historik är det sparade talet, fick ' . sf_span('v-historik', $regler));
sf(sf_span('v-information', $regler) === '2', 'menyns information är det sparade talet, fick ' . sf_span('v-information', $regler));
sf(sf_span('v-solceller', $regler) === '1', 'solceller räknas mot listan, fick ' . sf_span('v-solceller', $regler));
sf(sf_span('v-vantar', $regler) === '0', 'parkerat utan rader är 0, fick ' . sf_span('v-vantar', $regler));
sf(str_contains($regler, 'Vyer och etiketter') && str_contains($regler, 'crm-title">Regler'), 'regler-sidan har samma meny och rubrik');
sf($reglerMs < 3000, 'regler blev klar på ' . (int) $reglerMs . ' ms');

$_GET = ['p' => 'inkorg-vyer', 'box' => 'jarfalla', 'scope' => 'allas'];
$GLOBALS['_JEAB_INKORG_THREADS_CALLS'] = 0;
$GLOBALS['_JEAB_INKORG_VIEW_TOTAL_CALLS'] = 0;
$GLOBALS['_JEAB_HS_INDEX_FULL'] = 0;
$t1 = microtime(true);
$vyer = require $root . '/src/pages/inkorg_vyer.php';
$vyerMs = (microtime(true) - $t1) * 1000;
sf(is_string($vyer), 'vyer-sidan ritas');
sf((int) $GLOBALS['_JEAB_INKORG_THREADS_CALLS'] === 0, 'vyer anropar inte jeab_inkorg_threads');
sf((int) $GLOBALS['_JEAB_INKORG_VIEW_TOTAL_CALLS'] === 0, 'vyer anropar inte jeab_inkorg_view_total');
sf((int) $GLOBALS['_JEAB_HS_INDEX_FULL'] === 0, 'vyer läser inte importindexet');
sf(sf_vy_count('Historik', $vyer) === 1, 'historik på vyer är listans stängda rader, fick ' . sf_vy_count('Historik', $vyer));
sf(sf_vy_count('Sam L', $vyer) === 1, 'solcellsvyn räknas mot listan, fick ' . sf_vy_count('Sam L', $vyer));
sf(sf_vy_count('Information', $vyer) === 1, 'öppen information räknas, stängd gör det inte, fick ' . sf_vy_count('Information', $vyer));
sf(str_contains($vyer, 'Vyer och etiketter') && str_contains($vyer, 'id="etiketter"'), 'vyer-sidan har samma rubriker');
sf($vyerMs < 3000, 'vyer blev klar på ' . (int) $vyerMs . ' ms');

echo "OK  settingsidor\n";
