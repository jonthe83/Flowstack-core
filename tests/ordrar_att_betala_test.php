<?php

declare(strict_types=1);

/**
 * Att betala i Bekräfta och Fakturera är samma lagrade kundpris.
 * En ändring räknar om alla rader i den befintliga kalkylen (även schablon
 * och grön teknik med taket 50 000 kr per sökande). Utan ändring är
 * Fortnox-payloaden densamma som kopOrderRows skriver idag.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-att-betala-' . bin2hex(random_bytes(4));
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
            'solar' => '30020001',
            'charging' => '30030001',
            'material' => '9001',
        ],
    ],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox.php';
$purchasePdo = new PDO('sqlite:' . $tmp . '/purchases.sqlite');
$purchasePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_PURCHASES_PDO'] = $purchasePdo;
jeab_purchases_store_ensure($purchasePdo);
jeab_ordrar_external_live(false);

function ab_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/** @param list<array<string,mixed>> $rows */
function ab_sig(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $hw = (string) ($row['HouseWorkType'] ?? '');
        if ($hw === 'EMPTYHOUSEWORK') {
            $hw = '';
        }
        $out[] = [
            (string) ($row['Description'] ?? ''),
            (int) ($row['Price'] ?? 0),
            (string) ($row['ArticleNumber'] ?? ''),
            $hw,
        ];
    }
    return $out;
}

/** @param list<array<string,mixed>> $rows */
function ab_nonneg(array $rows): bool
{
    foreach ($rows as $row) {
        if (is_array($row) && (int) ($row['price'] ?? 0) < 0) {
            return false;
        }
    }
    return $rows !== [];
}

/** @param list<array<string,mixed>> $logical */
function ab_named(array $logical, string $desc): ?int
{
    foreach ($logical as $row) {
        if (is_array($row) && (string) ($row['desc'] ?? '') === $desc) {
            return (int) ($row['price'] ?? 0);
        }
    }
    return null;
}

/** @param list<array<string,mixed>> $logical */
function ab_plain_sum(array $logical): int
{
    $sum = 0;
    foreach ($logical as $row) {
        if (!is_array($row)) {
            continue;
        }
        $hw = $row['hw'] ?? null;
        if (!is_string($hw) || $hw === '') {
            $sum += (int) ($row['price'] ?? 0);
        }
    }
    return $sum;
}

/**
 * @param list<array<string,mixed>> $fortnox
 */
function ab_shown_matches(array $fortnox): bool
{
    $shown = jeab_ordrar_fortnox_editor_lines($fortnox);
    foreach ($fortnox as $i => $row) {
        if (!is_array($row)) {
            return false;
        }
        $line = $shown[$i] ?? null;
        if (!is_array($line)) {
            return false;
        }
        $price = $row['Price'] ?? null;
        if (!is_numeric($price) || (int) $line['price'] !== (int) round((float) $price)) {
            return false;
        }
        if ((string) $line['description'] !== (string) ($row['Description'] ?? '')) {
            return false;
        }
        $article = trim((string) ($row['ArticleNumber'] ?? ''));
        if ($article !== '' && (string) $line['article'] !== $article) {
            return false;
        }
    }
    return true;
}

/** @param list<array<string,mixed>> $logical */
function ab_bases(array $logical): array
{
    $bases = [];
    foreach ($logical as $row) {
        if (!is_array($row)) {
            continue;
        }
        $hw = $row['hw'] ?? null;
        if (!is_string($hw) || $hw === '' || !isset(FortnoxAPI::greenTechRates()[$hw])) {
            continue;
        }
        $bases[$hw] = ($bases[$hw] ?? 0.0) + (float) ($row['price'] ?? 0);
    }
    return $bases;
}

function ab_input_value(string $html, string $id): ?string
{
    $pattern = '/id="' . preg_quote($id, '/') . '"[^>]*value="([^"]*)"/';
    if (preg_match($pattern, $html, $match) !== 1) {
        return null;
    }
    return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** @param array<string,mixed> $spec */
function ab_order(int $id, array $spec): array
{
    $spec['reminder_id'] = $id;
    $spec['company'] = 'jarfalla';
    $spec['fortnox_order'] = (string) $id;
    $spec['huvudsokande'] = 'Test Testsson';
    $spec['personnummer'] = '198001011234';
    $spec['fastighetsbeteckning'] = 'TEST 1:1';
    $spec['address'] = 'Testgatan 1, 177 00 Järfälla';
    $spec['steps'] = [];
    return $spec;
}

/** @param array<string,mixed> $row */
function ab_logical(array $row): array
{
    $kind = (string) (jeab_purchase_normalize_kind($row['kind'] ?? '') ?? '');
    $price = jeab_ordrar_fortnox_price($row);
    return jeab_offert_fortnox_kop_rows($kind, $price, (string) ($row['install_what'] ?? ''), $row);
}

$engine = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar_fortnox.php');
ab_assert(!str_contains($engine, 'function jeab_ordrar_att_betala_scale'), 'ingen separat fördelning i Fakturera');
ab_assert(!str_contains($engine, 'fakturering_att_betala'), 'inget eget faktureringsbelopp');

$cases = [
    'sol' => [
        'kind' => 'sol',
        'kundpris' => 80000,
        'pris' => 80000,
        'kalkyl' => ['sokande' => 2, 'b14' => 20, 'b3' => 4, 'b15' => 1, 'laddare' => 0],
        'next' => 120000,
        'cap_price' => 250000,
        'schablon' => 'Schablon',
    ],
    'batteri' => [
        'kind' => 'batteri',
        'kundpris' => 70000,
        'pris' => 70000,
        'kalkyl' => ['sokande' => 2, 'b14' => 0, 'b3' => 2, 'b15' => 1, 'laddare' => 0],
        'next' => 120000,
        'cap_price' => 250000,
        'schablon' => 'Schablon',
    ],
    'laddbox' => [
        'kind' => 'laddbox',
        'kundpris' => 12500,
        'pris' => 12500,
        'install_what' => 'Elbilsladdare',
        'kalkyl' => ['sokande' => 1],
        'next' => 20000,
        'cap_price' => 120000,
        'schablon' => 'Framkörning',
    ],
];

$api = new FortnoxAPI('Jarfalla');
$id = 44000;
foreach ($cases as $label => $spec) {
    $peopleList = [1, 2];
    foreach ($peopleList as $people) {
        $purchase = $spec;
        unset($purchase['next'], $purchase['cap_price'], $purchase['schablon']);
        $purchase['company'] = 'jarfalla';
        $purchase['kalkyl']['sokande'] = $people;
        $purchase['huvudsokande'] = 'Test Testsson';
        $purchase['personnummer'] = '198001011234';
        $purchase['fastighetsbeteckning'] = 'TEST 1:1';
        $kind = (string) $spec['kind'];
        $price = (int) $spec['pris'];
        $what = (string) ($spec['install_what'] ?? '');
        $today = $api->kopOrderRows($kind, $price, $what, $purchase);
        $again = $api->kopOrderRows($kind, $price, $what, $purchase);
        ab_assert($today === $again, $label . ' utan ändring är dagens Fortnox-rader, ' . $people . ' sökande');

        $oldLogical = jeab_offert_rows_for_payable($kind, $price, $what, $purchase);
        $next = (int) $spec['next'];
        $nextRow = $purchase;
        $nextRow['pris'] = $next;
        $nextRow['kundpris'] = $next;
        $newLogical = jeab_offert_rows_for_payable($kind, $next, $what, $nextRow);
        ab_assert(ab_nonneg($oldLogical) && ab_nonneg($newLogical), $label . ' har inga negativa rader, ' . $people . ' sökande');
        ab_assert(jeab_offert_rows_att_betala($oldLogical, $people) === $price, $label . ' utan nytt pris betalar kunden ' . $price);
        ab_assert(jeab_offert_rows_att_betala($newLogical, $people) === $next, $label . ' summan är exakt ' . $next . ', fick ' . jeab_offert_rows_att_betala($newLogical, $people));
        $engineNow = jeab_offert_fortnox_kop_rows($kind, $price, $what, $purchase);
        if (jeab_offert_rows_match_payable($engineNow, $price, $people)) {
            ab_assert($oldLogical === $engineNow, $label . ' utan takändring är identisk med dagens rader, ' . $people . ' sökande');
        }
        $oldFixed = ab_named($oldLogical, (string) $spec['schablon']);
        $newFixed = ab_named($newLogical, (string) $spec['schablon']);
        ab_assert($oldFixed !== null && $newFixed !== null && $oldFixed >= 0 && $newFixed >= 0, $label . ' schablon är minst 0');
        if ($label === 'laddbox') {
            ab_assert($oldFixed === 45 && $newFixed === 45, 'Elbilsladdarens framkörning följer kalkylen (45 kr)');
            ab_assert((int) $newLogical[0]['price'] !== (int) $oldLogical[0]['price'], 'Elbilsladdarens produktrad följer det nya priset');
        } else {
            ab_assert($newFixed !== $oldFixed, $label . ' schablon ändras med kalkylen, ' . $oldFixed . ' → ' . $newFixed);
        }

        $id++;
        $bekrafta = ab_order($id, $purchase);
        jeab_purchase_put($bekrafta);
        $savedBekrafta = jeab_ordrar_save_questions([
            'id' => $id,
            'kind' => $kind,
            'pris' => jeab_ordrar_kr($next),
        ]);
        $fromBekrafta = (new FortnoxAPI('Jarfalla'))->kopRowsFromLogical(jeab_offert_rows_for_payable($kind, $next, $what, $savedBekrafta));

        $id++;
        $fakturera = ab_order($id, $purchase);
        jeab_purchase_put($fakturera);
        $put = null;
        $GLOBALS['_JEAB_FORTNOX_ORDER_GET'] = static function () use ($id): array {
            return ['Order' => ['DocumentNumber' => (string) $id, 'OrderRows' => []]];
        };
        $GLOBALS['_JEAB_FORTNOX_ORDER_PUT'] = static function (string $no, array $rows) use (&$put): array {
            $put = $rows;
            return ['Order' => ['DocumentNumber' => $no, 'OrderRows' => $rows]];
        };
        $GLOBALS['_JEAB_FORTNOX_GT_SYNC'] = static function (array $row, array $before, array $after, array $order): array {
            unset($row, $before, $after);
            return ['order' => $order, 'update' => false];
        };
        $savedFaktura = jeab_ordrar_save_fakturering([
            'id' => $id,
            'intent' => 'save',
            'pris' => jeab_ordrar_kr($next),
            'fx_rowid' => ['', '', '', ''],
            'fx_article' => ['', '', '', ''],
            'fx_desc' => ['Annan', '', '', ''],
            'fx_hw' => ['', '', '', ''],
            'fx_qty' => ['1', '', '', ''],
            'fx_price' => ['1', '', '', ''],
        ]);
        $fromFaktura = (new FortnoxAPI('Jarfalla'))->kopRowsFromLogical(jeab_offert_rows_for_payable($kind, $next, $what, $savedFaktura));
        ab_assert(ab_sig($fromBekrafta) === ab_sig($fromFaktura), $label . ' Bekräfta och Fakturera ger identiska rader, ' . $people . ' sökande');
        ab_assert(jeab_ordrar_att_betala_amount($savedBekrafta) === $next, $label . ' Bekräfta lagrar ' . $next);
        ab_assert(jeab_ordrar_att_betala_amount($savedFaktura) === $next, $label . ' Fakturera lagrar samma ' . $next);
        ab_assert((int) ($savedFaktura['pris'] ?? 0) === $next && (int) ($savedFaktura['kundpris'] ?? 0) === $next, $label . ' pris och kundpris är samma värde');
        ab_assert(is_array($put) && ab_shown_matches($put), $label . ' Fortnox-payloaden är de visade raderna');
        ab_assert(ab_sig($put ?? []) === ab_sig($fromFaktura), $label . ' skickade rader är kalkylens rader');
        ab_assert(count($fromFaktura) <= 4, $label . ' alla rader ryms i faktureringen');

        $capPrice = (int) $spec['cap_price'];
        $capRow = $nextRow;
        $capRow['pris'] = $capPrice;
        $capRow['kundpris'] = $capPrice;
        $capRow['kalkyl']['sokande'] = $people;
        $capLogical = jeab_offert_rows_for_payable($kind, $capPrice, $what, $capRow);
        $green = jeab_offert_logical_gt_deduction($capLogical, $people);
        $cap = 50000 * $people;
        ab_assert(ab_nonneg($capLogical), $label . ' med tak har inga negativa rader');
        ab_assert(jeab_offert_rows_att_betala($capLogical, $people) === $capPrice, $label . ' med tak betalar kunden exakt ' . $capPrice . ', fick ' . jeab_offert_rows_att_betala($capLogical, $people));
        ab_assert($green === $cap, $label . ' taket ' . $cap . ' kr tillämpas, fick ' . $green);
    }
}

$sigenBase = [
    'kind' => 'batteri',
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
$sigenOld = jeab_offert_rows_for_payable('batteri', 70000, '', $sigenBase + ['company' => 'jarfalla']);
$sigenNext = $sigenBase;
$sigenNext['pris'] = 120000;
$sigenNext['kundpris'] = 120000;
$sigenNext['company'] = 'jarfalla';
$sigenNew = jeab_offert_rows_for_payable('batteri', 120000, '', $sigenNext);
ab_assert(ab_nonneg($sigenNew) && jeab_offert_rows_att_betala($sigenNew, 2) === 120000, 'Sigen Att betala är 120 000');
ab_assert(ab_plain_sum($sigenNew) !== ab_plain_sum($sigenOld) && ab_plain_sum($sigenNew) >= 0, 'Sigen-schablonen ändras och är minst 0');
$plain = $sigenNext;
unset($plain['kalkyl']['sigen_typ'], $plain['kalkyl']['sigen_kw']);
$plainLogical = jeab_offert_rows_for_payable('batteri', 120000, '', $plain);
ab_assert(ab_plain_sum($sigenNew) === ab_named($plainLogical, 'Schablon'), 'Sigen fördelar kalkylens schablon');
$id++;
jeab_purchase_put(ab_order($id, $sigenBase));
$sigenSaved = jeab_ordrar_save_questions(['id' => $id, 'kind' => 'batteri', 'pris' => '120 000']);
$sigenRows = (new FortnoxAPI('Jarfalla'))->kopRowsFromLogical(jeab_offert_rows_for_payable('batteri', 120000, '', $sigenSaved));
ab_assert($sigenRows !== [], 'Sigen får kalkylens rader');
foreach ($sigenRows as $row) {
    ab_assert((string) ($row['ArticleNumber'] ?? '') === '30010001', 'Sigen stannar på batteriartikeln');
}
ab_assert(jeab_offert_rows_for_payable('batteri', 70000, '', $sigenBase + ['company' => 'jarfalla']) === jeab_offert_fortnox_kop_rows('batteri', 70000, '', $sigenBase + ['company' => 'jarfalla']), 'Sigen utan takändring är dagens rader');

$id++;
$quiet = ab_order($id, $cases['batteri']);
unset($quiet['next'], $quiet['cap_price'], $quiet['schablon']);
jeab_purchase_put($quiet);
$beforeQuiet = $api->kopOrderRows('batteri', 70000, '', $quiet);
$afterQuiet = $api->kopOrderRows('batteri', 70000, '', $quiet);
ab_assert($beforeQuiet === $afterQuiet, 'oförändrat batteri är identiskt med dagens payload');
$quietPut = null;
$GLOBALS['_JEAB_FORTNOX_ORDER_GET'] = static function () use ($id): array {
    return ['Order' => ['DocumentNumber' => (string) $id, 'OrderRows' => []]];
};
$GLOBALS['_JEAB_FORTNOX_ORDER_PUT'] = static function (string $no, array $rows) use (&$quietPut): array {
    $quietPut = $rows;
    return ['Order' => ['DocumentNumber' => $no, 'OrderRows' => $rows]];
};
jeab_ordrar_save_fakturering([
    'id' => $id,
    'intent' => 'save',
    'fx_rowid' => ['9', '', '', ''],
    'fx_article' => ['30010001', '', '', ''],
    'fx_desc' => ['Oförändrad', '', '', ''],
    'fx_hw' => ['STORAGESELFPRODUCEDELECTRICITY', '', '', ''],
    'fx_qty' => ['1', '', '', ''],
    'fx_price' => ['111', '', '', ''],
]);
ab_assert(is_array($quietPut) && (int) ($quietPut[0]['Price'] ?? 0) === 111 && (string) ($quietPut[0]['Description'] ?? '') === 'Oförändrad', 'utan Att betala-ändring skrivs de postade raderna');

$id++;
$samePrice = ab_order($id, $cases['batteri']);
unset($samePrice['next'], $samePrice['cap_price'], $samePrice['schablon']);
jeab_purchase_put($samePrice);
$samePut = null;
$GLOBALS['_JEAB_FORTNOX_ORDER_PUT'] = static function (string $no, array $rows) use (&$samePut): array {
    $samePut = $rows;
    return ['Order' => ['DocumentNumber' => $no, 'OrderRows' => $rows]];
};
$GLOBALS['_JEAB_FORTNOX_ORDER_GET'] = static function () use ($id): array {
    return ['Order' => ['DocumentNumber' => (string) $id, 'OrderRows' => []]];
};
jeab_ordrar_save_fakturering([
    'id' => $id,
    'intent' => 'save',
    'pris' => jeab_ordrar_kr(70000),
    'fx_rowid' => ['9', '', '', ''],
    'fx_article' => ['30010001', '', '', ''],
    'fx_desc' => ['Oförändrad', '', '', ''],
    'fx_hw' => ['STORAGESELFPRODUCEDELECTRICITY', '', '', ''],
    'fx_qty' => ['1', '', '', ''],
    'fx_price' => ['111', '', '', ''],
]);
ab_assert(is_array($samePut) && (int) ($samePut[0]['Price'] ?? 0) === 111, 'samma Att betala räknar inte om raderna');

$sample = ab_order(44001, $cases['batteri']);
unset($sample['next'], $sample['cap_price'], $sample['schablon']);
foreach (['sol', 'batteri', 'laddbox', 'servicejobb'] as $kind) {
    $open = $sample;
    $open['kind'] = $kind;
    if ($kind === 'laddbox' || $kind === 'servicejobb') {
        $open['pris'] = $kind === 'laddbox' ? 12500 : 6400;
        $open['kundpris'] = $open['pris'];
    }
    if ($kind === 'sol') {
        $open['kalkyl'] = ['sokande' => 2, 'b14' => 12, 'b3' => 2, 'b15' => 1, 'laddare' => 1];
        $open['pris'] = 80000;
        $open['kundpris'] = 80000;
    }
    if ($kind === 'sol' || $kind === 'batteri') {
        $open['foranmalan_status'] = 'A';
        $open['foranmalan_case_status'] = 'skickad';
        $open['steps'] = [
            'foranmald' => '2026-10-05',
            'spec' => '2026-10-05',
            'bestalld' => '2026-10-09',
        ];
    }
    $html = jeab_ordrar_v3_html($open, 'jarfalla', 44001);
    $amount = jeab_ordrar_kr(jeab_ordrar_att_betala_amount($open));
    $bekrafta = ab_input_value($html, 'v3-pris');
    $faktura = ab_input_value($html, 'v3-faktura-att-betala');
    ab_assert($bekrafta === $amount, $kind . ' Bekräfta visar Att betala ' . $amount);
    ab_assert($faktura === $amount, $kind . ' Fakturera visar samma Att betala ' . $amount);
    ab_assert(substr_count($html, 'id="v3-faktura-att-betala"') === 1, $kind . ' har ett fält i Fakturera');
    ab_assert(!str_contains($html, 'name="att_betala"'), $kind . ' har inget extra beloppsfält');
}
$invoiced = $sample;
$invoiced['fortnox_invoice'] = '13500';
$invoiced['steps']['faktura_skapad'] = '2026-10-08 14:00:00';
$invoicedHtml = jeab_ordrar_v3_html($invoiced, 'jarfalla', 44001);
ab_assert(substr_count($invoicedHtml, 'id="v3-faktura-att-betala"') === 0, 'fakturerad order har inget redigerbart Att betala i Fakturera');
$closed = $invoiced;
$closed['allt_klart'] = true;
$closedHtml = jeab_ordrar_v3_html($closed, 'jarfalla', 44001);
ab_assert(substr_count($closedHtml, 'id="v3-faktura-att-betala"') === 0, 'stängd order har inget redigerbart Att betala i Fakturera');

$id++;
$impossible = ab_order($id, [
    'kind' => 'laddbox',
    'pris' => 40,
    'kundpris' => 40,
    'install_what' => 'Elbilsladdare',
    'kalkyl' => ['sokande' => 1],
]);
jeab_purchase_put($impossible);
$impossibleHtml = jeab_ordrar_v3_html($impossible, 'jarfalla', $id);
ab_assert(str_contains($impossibleHtml, 'data-v3-pay-error'), 'omöjligt pris visar felet i Fakturera');
ab_assert(str_contains($impossibleHtml, 'Att betala 40 kr går inte att fakturera. En rad skulle bli negativ.'), 'felet säger att en rad skulle bli negativ');
ab_assert(preg_match('/data-v3-primary="faktura"[^>]*disabled/', $impossibleHtml) === 1, 'Skapa faktura är spärrad');
ab_assert(preg_match('/data-v3-sec="faktura"[^>]*disabled/', $impossibleHtml) === 1, 'Spara är spärrad');
$threw = false;
try {
    jeab_ordrar_save_fakturering([
        'id' => $id,
        'intent' => 'save',
        'pris' => '40',
        'fx_rowid' => ['', '', '', ''],
        'fx_article' => ['', '', '', ''],
        'fx_desc' => ['Elbilsladdare', '', '', ''],
        'fx_hw' => ['CHARGINGSTATIONELECTRICVEHICLE', '', '', ''],
        'fx_qty' => ['1', '', '', ''],
        'fx_price' => ['1', '', '', ''],
    ]);
} catch (InvalidArgumentException $e) {
    $threw = str_contains($e->getMessage(), 'skulle bli negativ');
}
ab_assert($threw, 'Fakturera sparar inte ett pris som kräver en negativ rad');
foreach ([1, 2] as $people) {
    $low = $impossible;
    $low['kalkyl'] = ['sokande' => $people];
    ab_assert(jeab_ordrar_att_betala_error($low) !== '', 'Elbilsladdare 40 kr blockeras för ' . $people . ' sökande');
}

echo "ordrar att betala: ok\n";
