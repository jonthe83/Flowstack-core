<?php

declare(strict_types=1);

/**
 * Sigen-orderrader får bolagets batteriartikel på varje väg.
 * En json-fil med andra nummer ändrar inte raderna. Utan bolag är nyckeln battery.
 */

$tmp = sys_get_temp_dir() . '/jeab-sigen-company-' . bin2hex(random_bytes(4));
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
jeab_ordrar_external_live(false);
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (): array {
    throw new Exception('Testet ska inte skapa ordrar i Fortnox');
};

function sc_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function sc_order(?string $company): array
{
    $row = [
        'kind' => 'batteri',
        'pris' => 70000,
        'kundpris' => 70000,
        'huvudsokande' => 'Test Testsson',
        'personnummer' => '19780305-0000',
        'fastighetsbeteckning' => 'TEST 1:1',
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
    if ($company !== null) {
        $row['company'] = $company;
    }
    return $row;
}

function sc_by_desc(array $rows, string $keyDesc, string $keyArticle): array
{
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $out[(string) ($row[$keyDesc] ?? '')] = (string) ($row[$keyArticle] ?? '');
    }
    return $out;
}

$jsonPath = jeab_data_file('sigen-artiklar.json');
jeab_data_dir_ready($jsonPath);
file_put_contents($jsonPath, json_encode([
    'articles' => [
        'Jarfalla' => ['tp2.12.0' => '30010007', 'tp2.plate' => '50002', 'tp2.surcharge' => '50003'],
        'Malmo' => ['tp2.12.0' => '40010007', 'tp2.plate' => '91017777'],
    ],
], JSON_UNESCAPED_UNICODE));

$malmoOrder = sc_order('malmo');
$v3 = jeab_ordrar_v3_fakturering_from_kalkyl($malmoOrder, [
    'lines' => [],
    'pay' => '',
    'error' => '',
    'source' => '',
    'invoice' => '',
]);
$v3Articles = sc_by_desc($v3['lines'], 'description', 'article');
sc_assert(($v3['source'] ?? '') === 'kalkyl', 'v3 förifyller från kalkylen');
foreach ([
    'Sigen Hybrid Inverter 12.0 kW TP2',
    'SigenStor BAT 10.0 (9 kWh)',
    'Schablon',
] as $desc) {
    sc_assert(($v3Articles[$desc] ?? '') === '40010001', 'v3 Malmö ' . $desc . ' är Malmös batteriartikel');
}

$formula = sc_by_desc(jeab_ordrar_fortnox_formula_order_rows($malmoOrder), 'Description', 'ArticleNumber');
sc_assert(($formula['Sigen Hybrid Inverter 12.0 kW TP2'] ?? '') === '40010001', 'äldre ordervägen Malmö tar Malmös batteriartikel');
sc_assert(($formula['Schablon'] ?? '') === '40010001', 'äldre ordervägen Malmö tar batteriartikeln för schablonen');

$logical = jeab_ordrar_kalkyl_logical($malmoOrder);
$logicalArticles = sc_by_desc($logical['rows'] ?? [], 'desc', 'article');
foreach ($logicalArticles as $desc => $article) {
    sc_assert($article === 'battery', 'Kalkylens logiska rad ' . $desc . ' är batterinyckeln');
}

$jarfalla = sc_by_desc(jeab_ordrar_fortnox_formula_order_rows(sc_order('jarfalla')), 'Description', 'ArticleNumber');
foreach ($jarfalla as $desc => $number) {
    sc_assert($number === '30010001', 'Järfälla ' . $desc . ' är Järfällas batteriartikel');
    sc_assert($number !== '40010001' && $number !== '30010007', 'Järfälla tar inte Malmös eller json-nummer på ' . $desc);
}

$none = sc_order(null);
$v3none = jeab_ordrar_v3_fakturering_from_kalkyl($none, [
    'lines' => [], 'pay' => '', 'error' => '', 'source' => '', 'invoice' => '',
]);
$noneArticles = sc_by_desc($v3none['lines'], 'description', 'article');
foreach ($noneArticles as $desc => $number) {
    if ($desc === '') {
        continue;
    }
    sc_assert($number === '30010001', 'utan bolag tar v3 Järfällas batteriartikel på ' . $desc);
    sc_assert($number !== '30010007' && $number !== '91017777', 'utan bolag läser v3 inte json på ' . $desc);
}

$logicalNone = jeab_ordrar_kalkyl_logical($none);
$logicalNoneArticles = sc_by_desc($logicalNone['rows'] ?? [], 'desc', 'article');
foreach ($logicalNoneArticles as $desc => $article) {
    sc_assert($article === 'battery', 'utan bolag är ' . $desc . ' bara batterinyckeln');
}

$formulaNone = sc_by_desc(jeab_ordrar_fortnox_formula_order_rows($none), 'Description', 'ArticleNumber');
foreach ($formulaNone as $desc => $number) {
    sc_assert($number === '30010001', 'utan bolag materialiserar den äldre vägen batteriartikeln på ' . $desc);
}
sc_assert(!in_array('30010007', $formulaNone, true) && !in_array('40010007', $formulaNone, true) && !in_array('9101', $formulaNone, true), 'utan bolag finns inget json-nummer eller materialnummer');

echo "sigen row company: ok\n";
