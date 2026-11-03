<?php

declare(strict_types=1);

/**
 * fortnox.sigen_articles finns inte. Inga Sigen-artiklar skapas.
 * Varje Sigen-rad använder bolagets batteriartikel.
 */

$tmp = sys_get_temp_dir() . '/jeab-sigen-artiklar-' . bin2hex(random_bytes(4));
if (!mkdir($tmp . '/rot', 0775, true) && !is_dir($tmp . '/rot')) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'json',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
file_put_contents($tmp . '/rot/fortnox_config.php', "<?php\nreturn " . var_export([
    'clientId' => 'test-client',
    'clientSecret' => 'test-secret',
    'articles' => [
        'Jarfalla' => ['battery' => '30010001', 'material' => '9001'],
        'Malmo' => ['battery' => '40010001', 'material' => '9101'],
    ],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/fortnox.php';
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (): array {
    throw new Exception('Testet ska inte anropa Fortnox');
};

function sa_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$root = dirname(__DIR__);
$hook = (string) file_get_contents($root . '/hook.php');
$sigen = (string) file_get_contents($root . '/src/sigen.php');
$fortnox = (string) file_get_contents($root . '/src/fortnox.php');
sa_assert(!is_file($root . '/src/sigen_artiklar.php'), 'src/sigen_artiklar.php är borta');
sa_assert(!str_contains($hook, 'fortnox.sigen_articles') && !str_contains($hook, 'sigen_artiklar.php'), 'hook.php tar inte emot fortnox.sigen_articles');
sa_assert(!function_exists('jeab_sigen_articles_hook') && !function_exists('jeab_sigen_articles_sync'), 'skapafunktionerna finns inte');
sa_assert(!str_contains($sigen, 'sigen-artiklar.json') && !str_contains($sigen, 'createArticle') && !str_contains($sigen, 'fortnox_article'), 'sigen.php läser ingen nummerfil och skapar inga artiklar');
sa_assert(!str_contains($fortnox, 'function createArticle'), 'FortnoxAPI skapar inga artiklar');
sa_assert(!method_exists(FortnoxAPI::class, 'createArticle'), 'createArticle finns inte på FortnoxAPI');

$choice = jeab_sigen_resolve('tp2', '12', 2);
sa_assert(is_array($choice), 'TP2 går att slå upp');
if (!is_array($choice)) {
    exit(1);
}
$base = [
    ['price' => 10000, 'hw' => 'STORAGESELFPRODUCEDELECTRICITY'],
    ['price' => 4000],
];
$names = [
    'Sigen Hybrid Inverter 12.0 kW TP2',
    'SigenStor BAT 10.0 (9 kWh)',
    'Schablon',
];
foreach (['jarfalla' => '30010001', 'Malmo' => '40010001'] as $company => $number) {
    $api = new FortnoxAPI(jeab_ordrar_fortnox_account($company));
    $byDesc = [];
    foreach ($api->kopOrderRows('batteri', 70000, '', [
        'kind' => 'batteri',
        'company' => $company,
        'kundpris' => 70000,
        'pris' => 70000,
        'kalkyl' => [
            'sokande' => 2,
            'b14' => 0,
            'b3' => 2,
            'b15' => 1,
            'laddare' => 0,
            'sigen_typ' => 'tp2',
            'sigen_kw' => '12.0',
        ],
    ]) as $row) {
        $byDesc[(string) ($row['Description'] ?? '')] = (string) ($row['ArticleNumber'] ?? '');
    }
    foreach ($names as $name) {
        sa_assert(($byDesc[$name] ?? '') === $number, $company . ' ' . $name . ' är batteriartikeln ' . $number);
    }
    $logical = jeab_sigen_fortnox_rows($base, $choice, $company);
    foreach ($logical as $row) {
        sa_assert(($row['article'] ?? '') === 'battery', $company . ' logisk rad ' . ($row['desc'] ?? '') . ' är battery');
        sa_assert(!in_array((string) ($row['article'] ?? ''), ['11010186', '11130012', '9001', '9101'], true), 'varken sku eller material');
    }
}

$neo = jeab_sigen_resolve('neo', '8', 1);
sa_assert(is_array($neo) && $neo['inverter_sku'] === '' && $neo['battery_sku'] === '', 'NEO-sku är tom Aprilice-kod');
sa_assert(is_array($choice) && $choice['inverter_sku'] === '11010186' && $choice['plate_sku'] === '', 'TP2-sku är leverantörskoden, plattan har ingen');

echo "sigen artiklar: ok\n";
