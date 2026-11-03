<?php

declare(strict_types=1);

/**
 * Sigen-orderrader använder alltid bolagets batteriartikel.
 * Aldrig material, aldrig sku och aldrig data/sigen-artiklar.json.
 */

$tmp = sys_get_temp_dir() . '/jeab-sigen-rows-' . bin2hex(random_bytes(4));
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
        'Jarfalla' => [
            'battery' => '30010001',
            'material' => '9001',
            'labor' => '9002',
        ],
        'Malmo' => [
            'battery' => '40010001',
            'material' => '9101',
            'labor' => '9102',
        ],
    ],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/fortnox.php';
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (): array {
    throw new Exception('Orderraderna ska inte anropa Fortnox');
};

function sr_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function sr_purchase(string $company): array
{
    return [
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
    ];
}

/** @return list<array<string,mixed>> */
function sr_rows(string $company): array
{
    $account = jeab_ordrar_fortnox_account($company);
    $api = new FortnoxAPI($account);
    $out = [];
    foreach ($api->kopOrderRows('batteri', 70000, '', sr_purchase($company)) as $row) {
        $out[] = $row;
    }
    return $out;
}

/**
 * Udda radbelopp delas i två Fortnox-rader med samma beskrivning.
 * Antal och belopp räknas ihop.
 *
 * @param list<array<string,mixed>> $rows
 * @return array{qty:int,amount:int,articles:list<string>}
 */
function sr_sum_desc(array $rows, string $desc): array
{
    $qty = 0;
    $amount = 0;
    $articles = [];
    foreach ($rows as $row) {
        if ((string) ($row['Description'] ?? '') !== $desc) {
            continue;
        }
        $n = (int) ($row['DeliveredQuantity'] ?? 0);
        $qty += $n;
        $amount += (int) ($row['Price'] ?? 0) * $n;
        $articles[] = (string) ($row['ArticleNumber'] ?? '');
    }
    return ['qty' => $qty, 'amount' => $amount, 'articles' => $articles];
}

function sr_never_supplier(array $rows): bool
{
    $banned = ['11010186', '11130012', '11170033', '11010017'];
    foreach ($rows as $row) {
        if (in_array((string) ($row['ArticleNumber'] ?? ''), $banned, true)) {
            return false;
        }
    }
    return true;
}

$jarfalla = sr_rows('jarfalla');
$malmo = sr_rows('Malmo');
sr_assert(sr_never_supplier($jarfalla) && sr_never_supplier($malmo), 'ett numeriskt leverantörsnummer skickas aldrig');
foreach ([
    'Sigen Hybrid Inverter 12.0 kW TP2',
    'SigenStor BAT 10.0 (9 kWh)',
    'Schablon',
] as $desc) {
    $jarfallaSum = sr_sum_desc($jarfalla, $desc);
    $malmoSum = sr_sum_desc($malmo, $desc);
    sr_assert($jarfallaSum['articles'] !== [] && $jarfallaSum['articles'] === array_fill(0, count($jarfallaSum['articles']), '30010001'), 'Järfälla ' . $desc . ' är batteriartikeln');
    sr_assert($malmoSum['articles'] !== [] && $malmoSum['articles'] === array_fill(0, count($malmoSum['articles']), '40010001'), 'Malmö ' . $desc . ' är Malmös batteriartikel');
}
$bat = 'SigenStor BAT 10.0 (9 kWh)';
$jarfallaBat = sr_sum_desc($jarfalla, $bat);
$malmoBat = sr_sum_desc($malmo, $bat);
sr_assert($jarfallaBat['qty'] === 2 && $malmoBat['qty'] === 2, 'batteriraden behåller antalet');
sr_assert($jarfallaBat['amount'] === 107455 && $malmoBat['amount'] === 107455, 'batteriradens belopp är hela raden 107 455 kr');
$named = false;
foreach ($jarfalla as $row) {
    if ((string) ($row['Description'] ?? '') === 'Sigen Hybrid Inverter 12.0 kW TP2') {
        $named = true;
    }
}
sr_assert($named, 'beskrivningen är Sigen-namnet');

$jsonPath = jeab_data_file('sigen-artiklar.json');
jeab_data_dir_ready($jsonPath);
file_put_contents($jsonPath, json_encode([
    'articles' => [
        'Jarfalla' => ['tp2.12.0' => '30010007', 'tp2.plate' => '50002'],
        'Malmo' => ['tp2.12.0' => '40010007', 'tp2.plate' => '91017777'],
    ],
], JSON_UNESCAPED_UNICODE));
$jarfalla = sr_rows('Jarfalla');
$malmo = sr_rows('meab');
sr_assert(sr_never_supplier($jarfalla) && sr_never_supplier($malmo), 'en json-fil med nummer skickas inte');
foreach ($jarfalla as $row) {
    sr_assert((string) ($row['ArticleNumber'] ?? '') === '30010001', 'Järfälla ignorerar json på ' . (string) ($row['Description'] ?? ''));
}
foreach ($malmo as $row) {
    sr_assert((string) ($row['ArticleNumber'] ?? '') === '40010001', 'Malmö ignorerar json på ' . (string) ($row['Description'] ?? ''));
}

echo "sigen fortnox rows: ok\n";
