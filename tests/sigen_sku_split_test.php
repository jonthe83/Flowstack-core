<?php

declare(strict_types=1);

/**
 * sku är bara Aprilice. En json med Fortnox-nummer per bolag ändrar varken
 * katalogen eller orderraden. Varje rad är batteriartikeln.
 */

$tmp = sys_get_temp_dir() . '/jeab-sigen-sku-' . bin2hex(random_bytes(4));
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

function ss_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$numbers = ['J-NEO-8', 'J-NEO-BAT', 'J-TP2-PLATE', 'J-TP2-SUR', 'M-NEO-8', 'M-NEO-BAT', 'M-TP2-PLATE', 'M-TP2-SUR'];
$path = jeab_data_file('sigen-artiklar.json');
jeab_data_dir_ready($path);
file_put_contents($path, json_encode(['articles' => [
    'Jarfalla' => [
        'neo.8.0' => 'J-NEO-8',
        'neo.battery' => 'J-NEO-BAT',
        'tp2.plate' => 'J-TP2-PLATE',
        'tp2.surcharge' => 'J-TP2-SUR',
    ],
    'Malmo' => [
        'neo.8.0' => 'M-NEO-8',
        'neo.battery' => 'M-NEO-BAT',
        'tp2.plate' => 'M-TP2-PLATE',
        'tp2.surcharge' => 'M-TP2-SUR',
    ],
]], JSON_UNESCAPED_UNICODE));

$skus = [];
foreach (jeab_sigen_catalog() as $row) {
    foreach (is_array($row['sizes'] ?? null) ? $row['sizes'] : [] as $size) {
        if (!is_array($size)) {
            continue;
        }
        $skus[] = trim((string) ($size['sku'] ?? ''));
        ss_assert(!isset($size['fortnox_article']), 'katalogen har inget fortnox_article');
    }
    foreach (['battery', 'plate'] as $part) {
        $item = is_array($row[$part] ?? null) ? $row[$part] : [];
        $skus[] = trim((string) ($item['sku'] ?? ''));
    }
    $skus[] = trim((string) ($row['surcharge_sku'] ?? ''));
}
foreach ($skus as $sku) {
    ss_assert(!in_array($sku, $numbers, true), 'katalogens sku är inte ett Fortnox-nummer');
}
ss_assert(($skus[0] ?? null) === '', 'NEO 8.0 sku är tom');

$base = [
    ['price' => 10000, 'hw' => 'STORAGESELFPRODUCEDELECTRICITY'],
    ['price' => 4000],
];
$expect = [
    'neo' => ['8.0', [
        'SigenStor Neo EC 8.0 TP',
        'SigenStor Neo BAT 8.0',
        'Schablon',
    ]],
    'tp2' => ['12', [
        'Sigen Hybrid Inverter 12.0 kW TP2',
        'SigenStor BAT 10.0 (9 kWh)',
        'Schablon',
    ]],
];
foreach ($expect as $type => [$kw, $names]) {
    $choice = jeab_sigen_resolve($type, $kw, 1);
    ss_assert(is_array($choice), $type . ' går att slå upp');
    if (!is_array($choice)) {
        continue;
    }
    foreach ([null, '', 'jarfalla', 'Malmo', 'meab'] as $company) {
        $rows = jeab_sigen_fortnox_rows($base, $choice, $company);
        $byDesc = [];
        foreach ($rows as $row) {
            $byDesc[(string) $row['desc']] = (string) $row['article'];
        }
        foreach ($names as $name) {
            ss_assert(($byDesc[$name] ?? '') === 'battery', ($company === null || $company === '' ? 'utan bolag' : $company) . ' ' . $name . ' är battery');
            ss_assert(!in_array($byDesc[$name] ?? '', $numbers, true), $name . ' tar inte json-nummer');
        }
    }
}

$filled = jeab_sigen_apply_inkop([
    'kalkyl' => ['sigen_typ' => 'neo', 'sigen_kw' => '8.0', 'b3' => 1],
]);
$inkop = [];
foreach (is_array($filled['inkop_lines'] ?? null) ? $filled['inkop_lines'] : [] as $line) {
    $inkop[] = trim((string) ($line['sku'] ?? ''));
}
ss_assert($inkop === ['', '', '11170033'], 'inköpslistan behåller Aprilice-koderna, fick ' . implode(',', $inkop));

echo "sigen sku split: ok\n";
