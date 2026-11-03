<?php

declare(strict_types=1);

/**
 * Fakturering: Spara stannar på steget och visar raden.
 * Grön teknik följer möjlig skattereduktion när ansökan inte är begränsad.
 */

$tmp = sys_get_temp_dir() . '/jeab-fakturering-gt-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox.php';

function gt_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$beforeRows = [[
    'RowId' => 1,
    'ArticleNumber' => 'BAT',
    'Description' => 'Batteri',
    'Price' => 179910,
    'Total' => 179910,
    'HouseWork' => true,
    'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY',
    'DeliveredQuantity' => '1',
]];
$afterRows = [
    $beforeRows[0],
    [
        'RowId' => 2,
        'ArticleNumber' => 'KAP',
        'Description' => 'Kapsling',
        'Price' => 2000,
        'Total' => 2000,
        'HouseWork' => true,
        'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY',
        'DeliveredQuantity' => '1',
    ],
];

gt_assert(FortnoxAPI::greenTechPossible(FortnoxAPI::houseworkBases($beforeRows)) === 50000, 'en person: underlag över taket klampas till 50 000');
gt_assert(FortnoxAPI::greenTechPossible(FortnoxAPI::houseworkBases($beforeRows), 2) === 89955, 'två personer: underlag 179910 ger möjlig 89955');
gt_assert(FortnoxAPI::greenTechPossible(FortnoxAPI::houseworkBases($afterRows), 2) === 90955, 'två personer: underlag 181910 ger möjlig 90955');

$planOne = jeab_ordrar_gt_sync_plan($beforeRows, $afterRows, 50000, 1, true, false);
gt_assert($planOne['mojlig'] === 50000 && $planOne['asked'] === 50000, 'en person: ansökan stannar på 50 000, fick ' . $planOne['asked']);

$plan = jeab_ordrar_gt_sync_plan($beforeRows, $afterRows, 89952, 2, true, false);
gt_assert($plan['capped'] === false, '89952 mot 89955 är inte en manuell begränsning');
gt_assert($plan['update'] === true, 'ny GT-rad uppdaterar ansökan');
gt_assert($plan['mojlig'] === 90955 && $plan['asked'] === 90955, 'ansökt följer möjlig 90955, fick ' . $plan['asked']);
gt_assert((int) array_sum(array_map(static fn ($p) => (int) (($p[0]['AskedAmount'] ?? 0)), $plan['splits'])) === 90955, 'hela beloppet läggs på ansökan');
gt_assert(($plan['splits'][0][0]['WorkType'] ?? '') === 'STORAGESELFPRODUCEDELECTRICITY', 'arbetstypen följer raden');

$capped = jeab_ordrar_gt_sync_plan($beforeRows, $afterRows, 80000, 2, true, false);
gt_assert($capped['capped'] === true && $capped['update'] === false && $capped['asked'] === 80000, 'manuellt tak 80000 höjs inte');

$lowered = jeab_ordrar_gt_sync_plan($afterRows, $beforeRows, 90955, 2, true, true);
gt_assert($lowered['update'] === true && $lowered['asked'] === 89955, 'tak som överstiger nytt underlag sänks till möjlig');

$unchanged = jeab_ordrar_gt_sync_plan($afterRows, $afterRows, 90955, 2, true, false);
gt_assert($unchanged['update'] === false && $unchanged['asked'] === 90955, 'oförändrat underlag skriver inte om ansökan');

$splits = FortnoxAPI::greenTechSplits(FortnoxAPI::houseworkBases($afterRows), 2, 90955);
$splitSum = (int) ($splits[0][0]['AskedAmount'] ?? 0) + (int) ($splits[1][0]['AskedAmount'] ?? 0);
gt_assert($splitSum === 90955, 'två personer delar möjlig skattereduktion, fick ' . $splitSum);

$pay = jeab_ordrar_fortnox_with_asked([
    'Order' => [
        'TotalToPay' => 92003,
        'TaxReduction' => 89952,
        'OrderRows' => $afterRows,
    ],
], 89952, 90955);
gt_assert((float) ($pay['Order']['TotalToPay'] ?? 0) === 91000.0, 'att betala blir 91000 när ansökan höjs till 90955');
gt_assert(jeab_ordrar_fortnox_att_betala($pay) === jeab_ordrar_fortnox_money(91000), 'visad att betala är 91 000 kr');

$beforeOrder = [
    'Order' => [
        'DocumentNumber' => '9001',
        'TotalToPay' => 90003,
        'TaxReduction' => 89952,
        'OrderRows' => $beforeRows,
    ],
];
$afterOrder = [
    'Order' => [
        'DocumentNumber' => '9001',
        'TotalToPay' => 92003,
        'TaxReduction' => 89952,
        'OrderRows' => $afterRows,
    ],
];
$GLOBALS['_JEAB_FORTNOX_ORDER_GET'] = static function () use ($beforeOrder): array {
    return $beforeOrder;
};
$putRows = null;
$GLOBALS['_JEAB_FORTNOX_ORDER_PUT'] = static function (string $no, array $rows) use (&$putRows, $afterOrder): array {
    $putRows = $rows;
    return $afterOrder;
};
$GLOBALS['_JEAB_FORTNOX_GT_SYNC'] = static function (array $row, array $before, array $after, array $order): array {
    $plan = jeab_ordrar_gt_sync_plan(
        $before,
        $after,
        89952,
        2,
        true,
        !empty($row['fortnox_gt_capped']),
        (int) ($row['fortnox_gt_asked'] ?? 0)
    );
    return [
        'order' => jeab_ordrar_fortnox_with_asked($order, 89952, (int) $plan['asked']),
        'capped' => $plan['capped'],
        'asked' => $plan['asked'],
        'mojlig' => $plan['mojlig'],
        'update' => $plan['update'],
    ];
};

jeab_purchase_put([
    'reminder_id' => 77,
    'company' => 'jarfalla',
    'kind' => 'batteri',
    'fortnox_order' => '9001',
    'huvudsokande' => 'Test Testsson',
    'personnummer' => '198001011234',
    'fastighetsbeteckning' => 'TEST 1:1',
    'fortnox_order_rows' => $beforeRows,
    'fortnox_att_betala' => jeab_ordrar_fortnox_money(90003),
]);

$saved = jeab_ordrar_save_fakturering([
    'id' => 77,
    'intent' => 'save',
    'fx_rowid' => ['1', '', '', ''],
    'fx_article' => ['BAT', 'KAP', '', ''],
    'fx_desc' => ['Batteri', 'Kapsling', '', ''],
    'fx_hw' => ['STORAGESELFPRODUCEDELECTRICITY', 'STORAGESELFPRODUCEDELECTRICITY', '', ''],
    'fx_qty' => ['1', '1', '', ''],
    'fx_price' => ['179910', '2000', '', ''],
]);

gt_assert(is_array($putRows), 'Spara skriver raderna till Fortnox');
$putDesc = array_map(static fn (array $row): string => (string) ($row['Description'] ?? ''), $putRows ?? []);
gt_assert(in_array('Kapsling', $putDesc, true), 'den nya raden skickas med i skrivningen');
gt_assert((int) ($saved['fortnox_gt_asked'] ?? 0) === 90955, 'sparad ansökan är 90955');
gt_assert(empty($saved['fortnox_gt_capped']), 'obegränsad ansökan flaggas inte som tak');
gt_assert(($saved['fortnox_att_betala'] ?? '') === jeab_ordrar_fortnox_money(91000), 'att betala sparas som 91 000 kr, fick ' . (string) ($saved['fortnox_att_betala'] ?? ''));
$savedDesc = array_map(static fn (array $row): string => (string) ($row['Description'] ?? ''), $saved['fortnox_order_rows'] ?? []);
gt_assert(in_array('Kapsling', $savedDesc, true) && in_array('Batteri', $savedDesc, true), 'både gammal och ny rad ligger kvar på ordern');

jeab_ordrar_external_live(false);
$fetches = 0;
$GLOBALS['_JEAB_FORTNOX_ORDER_GET'] = static function () use (&$fetches, $afterOrder): array {
    $fetches++;
    return $afterOrder;
};
$html = jeab_ordrar_fakturering_html($saved, 'jarfalla', 77, null);
gt_assert($fetches === 0, 'svaret efter Spara läser inte om Fortnox');
gt_assert(str_contains($html, 'data-fx-source="snapshot"'), 'raderna kommer från det som just sparades');
gt_assert(str_contains($html, 'Kapsling') && str_contains($html, 'Batteri'), 'nya och gamla rader syns direkt');
gt_assert(str_contains($html, jeab_ordrar_fortnox_money(91000)), 'att betala i vyn är det nya beloppet');
gt_assert(str_contains($html, 'name="action" value="ordrar.fakturering"'), 'formuläret postar fortfarande fakturering');

$payload = jeab_ordrar_ajax_stop_payload($saved, 'fakturering', 'Sparad.');
gt_assert(($payload['stop'] ?? '') === 'fakturering', 'JSON-svaret stannar på fakturering');
gt_assert(str_contains((string) ($payload['stop_url'] ?? ''), 'stop=fakturering'), 'stop-url pekar på fakturering');
gt_assert(!str_contains((string) ($payload['stop_url'] ?? ''), 'stop=status'), 'stop-url går inte till status');
gt_assert(str_contains((string) ($payload['view_html'] ?? ''), 'Kapsling'), 'vy-html innehåller den sparade raden');

$page = (string) file_get_contents(dirname(__DIR__) . '/src/pages/ordrar.php');
gt_assert(str_contains($page, 'function syncRefresh(stop)'), 'Uppdatera följer aktuellt steg');
gt_assert(str_contains($page, "url.searchParams.set('stop', stop)"), 'Uppdatera-länken byter stop');
$showStart = strpos($page, 'function showStop');
$showEnd = strpos($page, 'function syncRefresh');
$showBody = $showStart !== false && $showEnd !== false ? substr($page, $showStart, $showEnd - $showStart) : '';
gt_assert(str_contains($showBody, 'syncRefresh(stop)'), 'stegbyte uppdaterar Uppdatera-länken');

$index = (string) file_get_contents(dirname(__DIR__) . '/index.php');
gt_assert(substr_count($index, "\$action === 'ordrar.fakturering'") >= 2, 'både JSON och redirect känner igen fakturering');
gt_assert(preg_match("/ordrar\\.fakturering'\\)\\s*\\{\\s*\\\$stop = 'fakturering';/", $index) === 1, 'JSON-svaret sätter stop fakturering');

$battery = [
    'RowId' => 1,
    'ArticleNumber' => 'BAT',
    'Description' => 'Batteri',
    'Price' => 113010,
    'Total' => 113010,
    'HouseWork' => true,
    'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY',
    'DeliveredQuantity' => '1',
];
$schablon = [
    'RowId' => 2,
    'ArticleNumber' => 'SCH',
    'Description' => 'Schablon',
    'Price' => 3495,
    'Total' => 3495,
    'HouseWork' => false,
    'HouseWorkType' => 'OTHERCOSTS',
    'DeliveredQuantity' => '1',
];
$hejGt = [
    'RowId' => 3,
    'Description' => 'hej',
    'Price' => 2000,
    'Total' => 2000,
    'HouseWork' => true,
    'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY',
    'DeliveredQuantity' => '1',
];
$hejOvrig = [
    'RowId' => 3,
    'Description' => 'hej',
    'Price' => 2000,
    'Total' => 2000,
    'HouseWork' => false,
    'HouseWorkType' => 'OTHERCOSTS',
    'DeliveredQuantity' => '1',
];
$gtRows = [$battery, $schablon, $hejGt];
$ovrigaRows = [$battery, $schablon, $hejOvrig];
gt_assert(FortnoxAPI::greenTechPossible(FortnoxAPI::houseworkBases($gtRows), 2) === 57505, 'två personer: batteri 113010 + hej 2000 ger möjlig 57505');
gt_assert(FortnoxAPI::greenTechPossible(FortnoxAPI::houseworkBases($ovrigaRows), 2) === 56505, 'två personer: hej som övrig kostnad lämnar möjlig 56505');
gt_assert(FortnoxAPI::greenTechPossible(FortnoxAPI::houseworkBases($gtRows)) === 50000, 'en person: 57505 klampas till 50 000');

$dropped = jeab_ordrar_gt_sync_plan($gtRows, $ovrigaRows, 57505, 2, true, false);
gt_assert($dropped['update'] === true && $dropped['capped'] === false && $dropped['asked'] === 56505, 'rad från lagring till övrig sänker ansökan till ny möjlig');

$keptCap = jeab_ordrar_gt_sync_plan($gtRows, $ovrigaRows, 50000, 2, true, true);
gt_assert($keptCap['update'] === false && $keptCap['capped'] === true && $keptCap['asked'] === 50000, 'manuellt tak under ny möjlig behålls');

$clippedCap = jeab_ordrar_gt_sync_plan($gtRows, $ovrigaRows, 57000, 2, true, true);
gt_assert($clippedCap['update'] === true && $clippedCap['asked'] === 56505, 'manuellt tak över ny möjlig klipps till möjlig');

$live = [
    'asked' => 57505,
    'pay' => 61000.0,
    'tax' => 57505,
    'rows' => $gtRows,
];
$calls = [];
$rejectMessage = 'Summan av raderna överskrider möjlig skattereduktion på fakturan.';
$orderPayload = static function () use (&$live): array {
    return [
        'Order' => [
            'DocumentNumber' => '1870',
            'TotalToPay' => $live['pay'],
            'TaxReduction' => $live['tax'],
            'OrderRows' => $live['rows'],
        ],
    ];
};
$GLOBALS['_JEAB_FORTNOX_ORDER_GET'] = static function () use ($orderPayload): array {
    return $orderPayload();
};
$GLOBALS['_JEAB_FORTNOX_GT_LOWER'] = static function (array $row, array $before, array $after, array $order) use (&$live, &$calls): array {
    $calls[] = 'lower';
    $plan = jeab_ordrar_gt_sync_plan(
        $before,
        $after,
        (int) $live['asked'],
        2,
        true,
        !empty($row['fortnox_gt_capped']),
        (int) ($row['fortnox_gt_asked'] ?? 0)
    );
    $target = (int) $plan['asked'];
    if (empty($plan['update']) || $target >= (int) $live['asked']) {
        return [
            'order' => $order,
            'update' => false,
            'capped' => $plan['capped'],
            'asked' => $target,
            'mojlig' => $plan['mojlig'],
        ];
    }
    $order = jeab_ordrar_fortnox_with_asked($order, (int) $live['asked'], $target);
    $body = jeab_ordrar_fortnox_order_body($order);
    $live['asked'] = $target;
    $live['tax'] = $target;
    $live['pay'] = (float) ($body['TotalToPay'] ?? $live['pay']);
    return [
        'order' => $order,
        'update' => true,
        'capped' => $plan['capped'],
        'asked' => $target,
        'mojlig' => $plan['mojlig'],
    ];
};
$GLOBALS['_JEAB_FORTNOX_ORDER_PUT'] = static function (string $no, array $rows) use (&$live, &$calls, $rejectMessage, $orderPayload): array {
    $calls[] = 'put';
    $possible = FortnoxAPI::greenTechPossible(FortnoxAPI::houseworkBases($rows), 2);
    if ((int) $live['asked'] > $possible) {
        throw new InvalidArgumentException($rejectMessage);
    }
    $live['rows'] = $rows;
    $saved = $orderPayload();
    $saved['Order']['DocumentNumber'] = $no;
    return $saved;
};
$GLOBALS['_JEAB_FORTNOX_GT_SYNC'] = static function (array $row, array $before, array $after, array $order) use (&$live, &$calls): array {
    $calls[] = 'sync';
    $plan = jeab_ordrar_gt_sync_plan(
        $before,
        $after,
        (int) $live['asked'],
        2,
        true,
        !empty($row['fortnox_gt_capped']),
        (int) ($row['fortnox_gt_asked'] ?? 0)
    );
    $target = (int) $plan['asked'];
    $order = jeab_ordrar_fortnox_with_asked($order, (int) $live['asked'], $target);
    if (!empty($plan['update'])) {
        $body = jeab_ordrar_fortnox_order_body($order);
        $live['asked'] = $target;
        $live['tax'] = $target;
        $live['pay'] = (float) ($body['TotalToPay'] ?? $live['pay']);
    }
    return [
        'order' => $order,
        'capped' => $plan['capped'],
        'asked' => $target,
        'mojlig' => $plan['mojlig'],
        'update' => $plan['update'],
    ];
};

jeab_purchase_put([
    'reminder_id' => 1870,
    'company' => 'jarfalla',
    'kind' => 'batteri',
    'fortnox_order' => '1870',
    'huvudsokande' => 'Sam',
    'personnummer' => '198001011234',
    'fastighetsbeteckning' => 'TEST 1:1',
    'fortnox_order_rows' => $gtRows,
    'fortnox_att_betala' => jeab_ordrar_fortnox_money(61000),
    'fortnox_gt_asked' => 57505,
]);

$loweredSave = jeab_ordrar_save_fakturering([
    'id' => 1870,
    'intent' => 'save',
    'fx_rowid' => ['1', '2', '3', ''],
    'fx_article' => ['BAT', 'SCH', '', ''],
    'fx_desc' => ['Batteri', 'Schablon', 'hej', ''],
    'fx_hw' => ['STORAGESELFPRODUCEDELECTRICITY', 'OTHERCOSTS', 'OTHERCOSTS', ''],
    'fx_qty' => ['1', '1', '1', ''],
    'fx_price' => ['113010', '3495', '2000', ''],
]);

$lowerAt = array_search('lower', $calls, true);
$putAt = array_search('put', $calls, true);
gt_assert($lowerAt !== false && $putAt !== false && $lowerAt < $putAt, 'ansökan sänks innan Fortnox får de nya raderna');
gt_assert(!str_contains(json_encode($loweredSave, JSON_UNESCAPED_UNICODE) ?: '', $rejectMessage), 'Spara faller inte på kvarstående hög ansökan');
gt_assert((int) ($loweredSave['fortnox_gt_asked'] ?? 0) === 56505, 'sparad ansökan är nya möjlig 56505');
gt_assert(empty($loweredSave['fortnox_gt_capped']), 'sänkt full ansökan är inget manuellt tak');
gt_assert(($loweredSave['fortnox_att_betala'] ?? '') === jeab_ordrar_fortnox_money(62000), 'att betala stiger till 62 000 kr när hej tappar grön teknik, fick ' . (string) ($loweredSave['fortnox_att_betala'] ?? ''));
$hejSaved = null;
foreach ($loweredSave['fortnox_order_rows'] ?? [] as $savedRow) {
    if (is_array($savedRow) && ($savedRow['Description'] ?? '') === 'hej') {
        $hejSaved = $savedRow;
    }
}
gt_assert(is_array($hejSaved) && ($hejSaved['HouseWorkType'] ?? '') === 'OTHERCOSTS', 'hej sparas som övrig kostnad');

jeab_ordrar_external_live(false);
$lowerHtml = jeab_ordrar_fakturering_html($loweredSave, 'jarfalla', 1870, null);
gt_assert(str_contains($lowerHtml, 'hej') && str_contains($lowerHtml, 'Övrig kostnad'), 'fakturering visar hej som övrig kostnad');
gt_assert(str_contains($lowerHtml, jeab_ordrar_fortnox_money(62000)), 'att betala i vyn är 62 000 kr');
gt_assert(!str_contains($lowerHtml, $rejectMessage), 'vyn visar inte överskrider-felet');
$lowerPayload = jeab_ordrar_ajax_stop_payload($loweredSave, 'fakturering', 'Sparad.');
gt_assert(($lowerPayload['stop'] ?? '') === 'fakturering', 'sänkt grön teknik stannar på fakturering');

$live = [
    'asked' => 50000,
    'pay' => 68505.0,
    'tax' => 50000,
    'rows' => $gtRows,
];
$calls = [];
jeab_purchase_put([
    'reminder_id' => 1871,
    'company' => 'jarfalla',
    'kind' => 'batteri',
    'fortnox_order' => '1871',
    'huvudsokande' => 'Sam',
    'personnummer' => '198001011234',
    'fastighetsbeteckning' => 'TEST 1:1',
    'fortnox_order_rows' => $gtRows,
    'fortnox_att_betala' => jeab_ordrar_fortnox_money(68505),
    'fortnox_gt_asked' => 50000,
    'fortnox_gt_capped' => true,
]);
$keptSave = jeab_ordrar_save_fakturering([
    'id' => 1871,
    'intent' => 'save',
    'fx_rowid' => ['1', '2', '3', ''],
    'fx_article' => ['BAT', 'SCH', '', ''],
    'fx_desc' => ['Batteri', 'Schablon', 'hej', ''],
    'fx_hw' => ['STORAGESELFPRODUCEDELECTRICITY', 'OTHERCOSTS', 'OTHERCOSTS', ''],
    'fx_qty' => ['1', '1', '1', ''],
    'fx_price' => ['113010', '3495', '2000', ''],
]);
gt_assert((int) ($keptSave['fortnox_gt_asked'] ?? 0) === 50000, 'tak 50000 ligger kvar när möjlig sjunker till 56505');
gt_assert(!empty($keptSave['fortnox_gt_capped']), 'manuellt tak förblir markerat');
gt_assert(($keptSave['fortnox_att_betala'] ?? '') === jeab_ordrar_fortnox_money(68505), 'att betala ändras inte när taket fortfarande ryms');
gt_assert(!str_contains(json_encode($keptSave, JSON_UNESCAPED_UNICODE) ?: '', $rejectMessage), 'Spara med giltigt tak avvisas inte');

$zeroTypeMessage = 'Den totala ansökta summan för en typ får inte vara 0 (Laddningspunkt elfordon)';
$chargingExisting = [[
    'Id' => 45,
    'CustomerName' => 'Sam',
    'SocialSecurityNumber' => '198001011234',
    'PropertyDesignation' => 'TEST 1:1',
    'TaxReductionAmounts' => [
        ['WorkType' => 'CHARGINGSTATIONELECTRICVEHICLE', 'AskedAmount' => 2000],
        ['WorkType' => 'STORAGESELFPRODUCEDELECTRICITY', 'AskedAmount' => 56505],
    ],
]];
$storageOnly = [[
    ['WorkType' => 'STORAGESELFPRODUCEDELECTRICITY', 'AskedAmount' => 56505],
]];
$chargedWrite = FortnoxAPI::greenTechAmountWrite($chargingExisting, $storageOnly);
gt_assert($chargedWrite['replace'] === true, 'borttagen laddningspunkt skrivs om i stället för att nollas');
$chargedJson = json_encode($chargedWrite['amounts'], JSON_UNESCAPED_UNICODE) ?: '';
gt_assert(!str_contains($chargedJson, 'CHARGINGSTATIONELECTRICVEHICLE'), 'laddningspunkt skickas inte med när typen försvunnit');
gt_assert(!str_contains($chargedJson, '"AskedAmount":0'), 'borttagen typ skickas inte som AskedAmount 0');
gt_assert(($chargedWrite['amounts'][0][0]['WorkType'] ?? '') === 'STORAGESELFPRODUCEDELECTRICITY', 'kvarvarande lagring ligger kvar');
gt_assert(($chargedWrite['amounts'][0][0]['AskedAmount'] ?? 0) === 56505, 'lagring behåller sitt belopp');

$zeroSplit = [[
    ['WorkType' => 'CHARGINGSTATIONELECTRICVEHICLE', 'AskedAmount' => 0],
    ['WorkType' => 'STORAGESELFPRODUCEDELECTRICITY', 'AskedAmount' => 56505],
]];
$zeroWrite = FortnoxAPI::greenTechAmountWrite($chargingExisting, $zeroSplit);
$zeroJson = json_encode($zeroWrite['amounts'], JSON_UNESCAPED_UNICODE) ?: '';
gt_assert($zeroWrite['replace'] === true && !str_contains($zeroJson, '"AskedAmount":0'), 'en nollrad för laddningspunkt filtreras bort');

$sameType = FortnoxAPI::greenTechAmountWrite(
    [[
        'TaxReductionAmounts' => [
            ['WorkType' => 'STORAGESELFPRODUCEDELECTRICITY', 'AskedAmount' => 89955],
        ],
    ]],
    [[['WorkType' => 'STORAGESELFPRODUCEDELECTRICITY', 'AskedAmount' => 90955]]]
);
gt_assert($sameType['replace'] === false && ($sameType['amounts'][0][0]['AskedAmount'] ?? 0) === 90955, 'samma arbetstyp uppdateras utan att skrivas om');

$chargeRow = [[
    'RowId' => 1,
    'ArticleNumber' => 'LB',
    'Description' => 'Laddbox',
    'Price' => 20000,
    'Total' => 20000,
    'HouseWork' => true,
    'HouseWorkType' => 'CHARGINGSTATIONELECTRICVEHICLE',
    'DeliveredQuantity' => '1',
]];
$chargeOvrig = [[
    'RowId' => 1,
    'ArticleNumber' => 'LB',
    'Description' => 'Laddbox',
    'Price' => 20000,
    'Total' => 20000,
    'HouseWork' => false,
    'HouseWorkType' => 'OTHERCOSTS',
    'DeliveredQuantity' => '1',
]];
gt_assert(FortnoxAPI::greenTechPossible(FortnoxAPI::houseworkBases($chargeRow)) === 10000, 'laddbox 20000 ger möjlig 10000');
gt_assert(FortnoxAPI::greenTechPossible(FortnoxAPI::houseworkBases($chargeOvrig)) === 0, 'laddbox som övrig lämnar möjlig 0');
$chargePlan = jeab_ordrar_gt_sync_plan($chargeRow, $chargeOvrig, 10000, 1, true, false);
gt_assert($chargePlan['update'] === true && $chargePlan['asked'] === 0, 'ensam laddningspunkt som blir övrig sänker ansökan till 0');
$chargeExisting = [[
    'Id' => 45,
    'CustomerName' => 'Sam',
    'SocialSecurityNumber' => '198001011234',
    'PropertyDesignation' => 'TEST 1:1',
    'TaxReductionAmounts' => [
        ['WorkType' => 'CHARGINGSTATIONELECTRICVEHICLE', 'AskedAmount' => 10000],
    ],
]];
$chargeGone = FortnoxAPI::greenTechAmountWrite($chargeExisting, $chargePlan['splits']);
$chargeGoneJson = json_encode($chargeGone['amounts'], JSON_UNESCAPED_UNICODE) ?: '';
gt_assert($chargeGone['replace'] === true, 'ensam laddningspunkt tas bort från ansökan');
gt_assert(!str_contains($chargeGoneJson, 'CHARGINGSTATIONELECTRICVEHICLE') && !str_contains($chargeGoneJson, '"AskedAmount":0'), 'tom ansökan innehåller ingen nollrad');

$live = [
    'asked' => 10000,
    'pay' => 10000.0,
    'tax' => 10000,
    'rows' => $chargeRow,
];
$calls = [];
$taxApps = $chargeExisting;
$GLOBALS['_JEAB_FORTNOX_GT_LOWER'] = static function (array $row, array $before, array $after, array $order) use (&$live, &$calls, &$taxApps, $zeroTypeMessage): array {
    $calls[] = 'lower';
    $plan = jeab_ordrar_gt_sync_plan(
        $before,
        $after,
        (int) $live['asked'],
        2,
        true,
        !empty($row['fortnox_gt_capped']),
        (int) ($row['fortnox_gt_asked'] ?? 0)
    );
    $write = FortnoxAPI::greenTechAmountWrite($taxApps, is_array($plan['splits'] ?? null) ? $plan['splits'] : []);
    $payload = json_encode($write['amounts'], JSON_UNESCAPED_UNICODE) ?: '';
    if (str_contains($payload, '"AskedAmount":0')) {
        throw new InvalidArgumentException($zeroTypeMessage);
    }
    $dropped = FortnoxAPI::greenTechDroppedTypes($taxApps, is_array($plan['splits'] ?? null) ? $plan['splits'] : []);
    if ($dropped !== [] && empty($write['replace'])) {
        throw new InvalidArgumentException($zeroTypeMessage);
    }
    if ($dropped !== []) {
        $taxApps = [];
        foreach ($write['amounts'] as $i => $amounts) {
            if ($amounts === []) {
                continue;
            }
            $taxApps[] = [
                'Id' => 45,
                'CustomerName' => 'Sam',
                'SocialSecurityNumber' => '198001011234',
                'PropertyDesignation' => 'TEST 1:1',
                'TaxReductionAmounts' => $amounts,
            ];
        }
    }
    $target = (int) $plan['asked'];
    if (empty($plan['update']) || $target >= (int) $live['asked']) {
        return [
            'order' => $order,
            'update' => false,
            'capped' => $plan['capped'],
            'asked' => $target,
            'mojlig' => $plan['mojlig'],
        ];
    }
    $order = jeab_ordrar_fortnox_with_asked($order, (int) $live['asked'], $target);
    $body = jeab_ordrar_fortnox_order_body($order);
    $live['asked'] = $target;
    $live['tax'] = $target;
    $live['pay'] = (float) ($body['TotalToPay'] ?? $live['pay']);
    return [
        'order' => $order,
        'update' => true,
        'capped' => $plan['capped'],
        'asked' => $target,
        'mojlig' => $plan['mojlig'],
    ];
};
$GLOBALS['_JEAB_FORTNOX_GT_SYNC'] = static function (array $row, array $before, array $after, array $order) use (&$live, &$calls, &$taxApps, $zeroTypeMessage): array {
    $calls[] = 'sync';
    $plan = jeab_ordrar_gt_sync_plan(
        $before,
        $after,
        (int) $live['asked'],
        1,
        (int) $live['asked'] > 0 || $taxApps !== [],
        !empty($row['fortnox_gt_capped']),
        (int) ($row['fortnox_gt_asked'] ?? 0)
    );
    $write = FortnoxAPI::greenTechAmountWrite($taxApps, is_array($plan['splits'] ?? null) ? $plan['splits'] : []);
    $payload = json_encode($write['amounts'], JSON_UNESCAPED_UNICODE) ?: '';
    if (str_contains($payload, '"AskedAmount":0')) {
        throw new InvalidArgumentException($zeroTypeMessage);
    }
    $target = (int) $plan['asked'];
    $order = jeab_ordrar_fortnox_with_asked($order, (int) $live['asked'], $target);
    if (!empty($plan['update'])) {
        $body = jeab_ordrar_fortnox_order_body($order);
        $live['asked'] = $target;
        $live['tax'] = $target;
        $live['pay'] = (float) ($body['TotalToPay'] ?? $live['pay']);
    }
    return [
        'order' => $order,
        'capped' => $plan['capped'],
        'asked' => $target,
        'mojlig' => $plan['mojlig'],
        'update' => $plan['update'],
    ];
};

jeab_purchase_put([
    'reminder_id' => 45,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'fortnox_order' => '1870',
    'huvudsokande' => 'Sam',
    'personnummer' => '198001011234',
    'fastighetsbeteckning' => 'TEST 1:1',
    'fortnox_order_rows' => $chargeRow,
    'fortnox_att_betala' => jeab_ordrar_fortnox_money(10000),
    'fortnox_gt_asked' => 10000,
]);
$chargeSave = jeab_ordrar_save_fakturering([
    'id' => 45,
    'intent' => 'save',
    'fx_rowid' => ['1', '', '', ''],
    'fx_article' => ['LB', '', '', ''],
    'fx_desc' => ['Laddbox', '', '', ''],
    'fx_hw' => ['OTHERCOSTS', '', '', ''],
    'fx_qty' => ['1', '', '', ''],
    'fx_price' => ['20000', '', '', ''],
]);
gt_assert((int) ($chargeSave['fortnox_gt_asked'] ?? -1) === 0, 'ansökan är 0 när laddningspunkten försvunnit');
gt_assert(($chargeSave['fortnox_att_betala'] ?? '') === jeab_ordrar_fortnox_money(20000), 'att betala blir 20 000 kr utan grön teknik, fick ' . (string) ($chargeSave['fortnox_att_betala'] ?? ''));
gt_assert(!str_contains(json_encode($chargeSave, JSON_UNESCAPED_UNICODE) ?: '', $zeroTypeMessage), 'Spara faller inte på nollad laddningspunkt');
gt_assert($taxApps === [], 'ansökan skrivs om utan laddningspunkt och utan nollrad');
$chargeLowerAt = array_search('lower', $calls, true);
$chargePutAt = array_search('put', $calls, true);
gt_assert($chargeLowerAt !== false && $chargePutAt !== false && $chargeLowerAt < $chargePutAt, 'laddningspunkten tas bort från ansökan innan raden skrivs');
jeab_ordrar_external_live(false);
$chargeHtml = jeab_ordrar_fakturering_html($chargeSave, 'jarfalla', 45, null);
gt_assert(str_contains($chargeHtml, 'Laddbox') && str_contains($chargeHtml, 'Övrig kostnad'), 'fakturering visar laddbox som övrig kostnad');
gt_assert(str_contains($chargeHtml, jeab_ordrar_fortnox_money(20000)), 'att betala i vyn är 20 000 kr');
gt_assert(!str_contains($chargeHtml, $zeroTypeMessage), 'vyn visar inte nollfel för laddningspunkt');
$chargePayload = jeab_ordrar_ajax_stop_payload($chargeSave, 'fakturering', 'Sparad.');
gt_assert(($chargePayload['stop'] ?? '') === 'fakturering', 'borttagen laddningspunkt stannar på fakturering');

$exactTooHigh = 'Summan av raderna överskrider möjlig skattereduktion på fakturan.';
gt_assert(FortnoxAPI::isTaxAmountTooHigh($exactTooHigh) === true, 'överskrider är för högt ansökt belopp');
gt_assert(FortnoxAPI::isTaxAmountTooHigh('Summan av raderna överstiger möjlig skattereduktion på fakturan.') === true, 'överstiger är fortfarande för högt belopp');
gt_assert(FortnoxAPI::isTaxAmountTooHigh('Den totala ansökta summan för en typ får inte vara 0 (Laddningspunkt elfordon)') === false, 'nollad arbetstyp försöker inte igen som för högt belopp');
gt_assert(FortnoxAPI::isTaxAmountTooHigh('Kunde inte spara belopp. Max rader.') === false, 'slumpmässiga belopp/max är inte för högt ansökt belopp');
gt_assert(FortnoxAPI::isTaxAmountTooHigh('amount field is required') === false, 'amount utan skattereduktion sänker inte ansökan');
gt_assert(FortnoxAPI::greenTechPossible(FortnoxAPI::houseworkBases([$schablon])) === 0, 'schablon övrig kostnad räknas inte in i grön teknik-underlaget');

$retryBefore = [[
    'Description' => 'Laddbox',
    'Price' => 18000,
    'Total' => 18000,
    'HouseWork' => true,
    'HouseWorkType' => 'CHARGINGSTATIONELECTRICVEHICLE',
    'DeliveredQuantity' => '1',
]];
$retryAfter = [
    [
        'Description' => 'Laddbox',
        'Price' => 20000,
        'Total' => 20000,
        'HouseWork' => true,
        'HouseWorkType' => 'CHARGINGSTATIONELECTRICVEHICLE',
        'DeliveredQuantity' => '1',
    ],
    $schablon,
];
gt_assert(FortnoxAPI::greenTechPossible(FortnoxAPI::houseworkBases($retryAfter)) === 10000, 'schablon höjer inte möjlig skattereduktion');
$taxPuts = [];
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, ?array $body) use (&$taxPuts, $retryAfter, $exactTooHigh): array {
    if ($method === 'GET' && str_contains($path, '/orders/')) {
        return ['Order' => [
            'DocumentNumber' => '1870',
            'TotalToPay' => 10000,
            'TaxReduction' => 9000,
            'OrderRows' => $retryAfter,
            '@urlTaxReductionList' => 'https://api.fortnox.se/3/taxreductions?filter=orders',
        ]];
    }
    if ($method === 'GET' && str_contains($path, '/taxreductions?')) {
        return ['TaxReductions' => [[
            'Id' => 45,
            'ReferenceNumber' => '1870',
            'CustomerName' => 'Sam',
        ]]];
    }
    if ($method === 'GET' && str_contains($path, '/taxreductions/')) {
        return ['TaxReduction' => [
            'Id' => 45,
            'CustomerName' => 'Sam',
            'SocialSecurityNumber' => '198001011234',
            'PropertyDesignation' => 'TEST 1:1',
            'TaxReductionAmounts' => [
                ['WorkType' => 'CHARGINGSTATIONELECTRICVEHICLE', 'AskedAmount' => 9000],
            ],
        ]];
    }
    if ($method === 'PUT' && str_contains($path, '/taxreductions/')) {
        $taxPuts[] = $body;
        if (count($taxPuts) === 1) {
            throw new Exception($exactTooHigh);
        }
        return ['TaxReduction' => ['Id' => 45]];
    }
    return [];
};
$retryApi = new FortnoxAPI('Jarfalla');
$retrySynced = $retryApi->syncKopTaxReduction(
    ['fortnox_order' => '1870', 'fastighetsbeteckning' => 'TEST 1:1'],
    $retryBefore,
    $retryAfter,
    ['Order' => ['DocumentNumber' => '1870', 'TotalToPay' => 10000, 'TaxReduction' => 9000, 'OrderRows' => $retryBefore]]
);
unset($GLOBALS['_JEAB_FORTNOX_REQUEST']);
gt_assert(count($taxPuts) === 2, 'överskrider gör ett nytt försök med lägre belopp, fick ' . count($taxPuts) . ' skrivningar');
$firstAsked = (int) ($taxPuts[0]['TaxReduction']['TaxReductionAmounts'][0]['AskedAmount'] ?? 0);
$secondAsked = (int) ($taxPuts[1]['TaxReduction']['TaxReductionAmounts'][0]['AskedAmount'] ?? 0);
gt_assert($firstAsked === 10000, 'första försöket är hela möjlig 10000, fick ' . $firstAsked);
gt_assert($secondAsked === 9999, 'nytt försök är en krona lägre per person, fick ' . $secondAsked);
gt_assert(empty($retrySynced['error']), 'Spara-synken lyckas efter överskrider, fel: ' . (string) ($retrySynced['error'] ?? ''));
gt_assert((int) ($retrySynced['asked'] ?? 0) === 9999, 'ansökan efter retry är 9999');
gt_assert((float) ($retrySynced['order']['Order']['TotalToPay'] ?? 0) === 9001.0, 'att betala följer det sänkta försöket');

$solarTypeMessage = 'Den ansökta summan för en typ får inte överskrida möjligt belopp för den typen (Solceller)';
gt_assert(FortnoxAPI::isTaxAmountTooHigh($solarTypeMessage) === true, 'Solceller-överskrider är för högt belopp');
gt_assert(FortnoxAPI::workTypeFromTooHighMessage($solarTypeMessage) === 'SOLARCELLS', 'feltexten pekar ut solceller');
gt_assert((int) floor(64041 * 0.15) === 9606 && (int) floor(100926 * 0.50) === 50463, 'gammalt sol- och batteriunderlag');
gt_assert(9606 + 50463 === 60069, 'gammal ansökan är hela möjlig 60069');
gt_assert((int) floor(76004 * 0.15) === 11400 && (int) floor(81077 * 0.50) === 40538, 'nytt sol- och batteriunderlag');
gt_assert(11400 + 40538 === 51938, 'ny möjlig är 51938');

$priceBefore = [
    [
        'Description' => 'Solpaneler',
        'Price' => 64041,
        'Total' => 64041,
        'HouseWork' => true,
        'HouseWorkType' => 'SOLARCELLS',
        'DeliveredQuantity' => '1',
    ],
    [
        'Description' => 'Batteri',
        'Price' => 100926,
        'Total' => 100926,
        'HouseWork' => true,
        'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY',
        'DeliveredQuantity' => '1',
    ],
];
$priceAfter = [
    [
        'Description' => 'Solpaneler',
        'Price' => 76004,
        'Total' => 76004,
        'HouseWork' => true,
        'HouseWorkType' => 'SOLARCELLS',
        'DeliveredQuantity' => '1',
    ],
    [
        'Description' => 'Batteri',
        'Price' => 81077,
        'Total' => 81077,
        'HouseWork' => true,
        'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY',
        'DeliveredQuantity' => '1',
    ],
];
$held = FortnoxAPI::greenTechHoldBeforeRows(
    FortnoxAPI::houseworkBases($priceBefore),
    FortnoxAPI::houseworkBases($priceAfter),
    ['SOLARCELLS' => 9606, 'STORAGESELFPRODUCEDELECTRICITY' => 50463],
    2
);
gt_assert(!empty($held['update']), 'prisförskjutning måste skriva om ansökan innan raderna');
gt_assert((int) ($held['per_type']['SOLARCELLS'] ?? 0) === 9606, 'solceller höjs inte innan den nya raden finns, fick ' . (int) ($held['per_type']['SOLARCELLS'] ?? 0));
gt_assert((int) ($held['per_type']['STORAGESELFPRODUCEDELECTRICITY'] ?? 0) === 40538, 'lagring sänks till nytt möjligt innan raden skrivs');
gt_assert((int) ($held['asked'] ?? 0) === 50144, 'ansökan före radskrivning är 50144, fick ' . (int) ($held['asked'] ?? 0));
$heldSplits = FortnoxAPI::greenTechSplitsFromTypes($held['per_type'], 2);
$heldSolar = (int) ($heldSplits[0][0]['AskedAmount'] ?? 0) + (int) ($heldSplits[1][0]['AskedAmount'] ?? 0);
$heldStorage = (int) ($heldSplits[0][1]['AskedAmount'] ?? 0) + (int) ($heldSplits[1][1]['AskedAmount'] ?? 0);
gt_assert($heldSolar === 9606 && $heldStorage === 40538, 'två personer delar de sänkta beloppen per typ');
$unmarkedStorage = $priceBefore;
$unmarkedStorage[1]['HouseWork'] = false;
$keptHold = FortnoxAPI::greenTechHoldBeforeRows(
    FortnoxAPI::houseworkBases($unmarkedStorage),
    FortnoxAPI::houseworkBases($priceBefore),
    ['SOLARCELLS' => 9606, 'STORAGESELFPRODUCEDELECTRICITY' => 40000]
);
gt_assert(empty($keptHold['update']), 'husarbete som slås på nollställer inte befintlig lagring');
gt_assert((int) ($keptHold['per_type']['STORAGESELFPRODUCEDELECTRICITY'] ?? 0) === 40000, 'lagring ligger kvar när den nya raden rymmer den');

$shiftAsked = [
    'SOLARCELLS' => 9606,
    'STORAGESELFPRODUCEDELECTRICITY' => 50463,
];
$shiftPuts = [];
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, ?array $body) use (&$shiftAsked, &$shiftPuts, $priceAfter, $solarTypeMessage): array {
    if ($method === 'GET' && str_contains($path, '/orders/')) {
        return ['Order' => [
            'DocumentNumber' => '1871',
            'TotalToPay' => 110000,
            'TaxReduction' => array_sum($shiftAsked),
            'OrderRows' => $priceAfter,
            '@urlTaxReductionList' => 'https://api.fortnox.se/3/taxreductions?filter=orders',
        ]];
    }
    if ($method === 'GET' && str_contains($path, '/taxreductions?')) {
        return ['TaxReductions' => [
            ['Id' => 1871, 'ReferenceNumber' => '1871', 'CustomerName' => 'Test Testsson'],
            ['Id' => 1872, 'ReferenceNumber' => '1871', 'CustomerName' => 'Med Sokande'],
        ]];
    }
    if ($method === 'GET' && str_contains($path, '/taxreductions/')) {
        $id = basename($path);
        $share = [];
        foreach ($shiftAsked as $type => $amount) {
            $half = intdiv((int) $amount, 2);
            $rem = (int) $amount - $half;
            $share[$type] = $id === '1872' ? $rem : $half;
        }
        $amounts = [];
        foreach ($share as $type => $amount) {
            if ($amount > 0) {
                $amounts[] = ['WorkType' => $type, 'AskedAmount' => $amount];
            }
        }
        return ['TaxReduction' => [
            'Id' => (int) $id,
            'CustomerName' => $id === '1872' ? 'Med Sokande' : 'Test Testsson',
            'SocialSecurityNumber' => $id === '1872' ? '198202022345' : '198001011234',
            'PropertyDesignation' => 'TEST 1:1',
            'TaxReductionAmounts' => $amounts,
        ]];
    }
    if ($method === 'PUT' && str_contains($path, '/taxreductions/')) {
        $shiftPuts[] = $body;
        $id = basename($path);
        static $pending = [];
        $pending[$id] = [];
        foreach ($body['TaxReduction']['TaxReductionAmounts'] ?? [] as $amount) {
            if (!is_array($amount)) {
                continue;
            }
            $pending[$id][(string) ($amount['WorkType'] ?? '')] = (int) ($amount['AskedAmount'] ?? 0);
        }
        if (count($pending) >= 2) {
            $next = [];
            foreach ($pending as $part) {
                foreach ($part as $type => $amount) {
                    $next[$type] = ($next[$type] ?? 0) + $amount;
                }
            }
            if (($next['SOLARCELLS'] ?? 0) > 9606 && count($shiftPuts) <= 2) {
                $pending = [];
                throw new Exception($solarTypeMessage);
            }
            $shiftAsked = $next;
            $pending = [];
        }
        return ['TaxReduction' => ['Id' => (int) $id]];
    }
    return [];
};
$shiftApi = new FortnoxAPI('Jarfalla');
$shiftOrder = ['Order' => [
    'DocumentNumber' => '1871',
    'TotalToPay' => 110000,
    'TaxReduction' => 60069,
    'OrderRows' => $priceBefore,
    '@urlTaxReductionList' => 'https://api.fortnox.se/3/taxreductions?filter=orders',
]];
$shiftLowered = $shiftApi->lowerKopTaxReduction(
    ['fortnox_order' => '1871', 'fortnox_gt_asked' => 60069],
    $priceBefore,
    $priceAfter,
    $shiftOrder
);
gt_assert(empty($shiftLowered['error']), 'sänkningen före raderna faller inte på Solceller, fel: ' . (string) ($shiftLowered['error'] ?? ''));
gt_assert(!empty($shiftLowered['update']), 'prisförskjutning skriver ansökan innan raderna');
gt_assert(count($shiftPuts) === 2, 'solceller höjs inte i ett avvisat förstaförsök, fick ' . count($shiftPuts) . ' skrivningar');
$shiftFirst = [];
foreach (array_slice($shiftPuts, 0, 2) as $put) {
    foreach ($put['TaxReduction']['TaxReductionAmounts'] ?? [] as $amount) {
        if (is_array($amount)) {
            $type = (string) ($amount['WorkType'] ?? '');
            $shiftFirst[$type] = ($shiftFirst[$type] ?? 0) + (int) ($amount['AskedAmount'] ?? 0);
        }
    }
}
gt_assert(($shiftFirst['SOLARCELLS'] ?? 0) === 9606, 'första skrivningen lämnar solceller på 9606, fick ' . (int) ($shiftFirst['SOLARCELLS'] ?? 0));
gt_assert(($shiftFirst['STORAGESELFPRODUCEDELECTRICITY'] ?? 0) === 40538, 'första skrivningen sänker lagring till 40538');
gt_assert((int) ($shiftLowered['asked'] ?? 0) === 50144, 'sparad ansökan före raderna är 50144');

$shiftRaised = $shiftApi->syncKopTaxReduction(
    ['fortnox_order' => '1871', 'fortnox_gt_asked' => 50144, 'fastighetsbeteckning' => 'TEST 1:1'],
    $priceBefore,
    $priceAfter,
    $shiftOrder
);
unset($GLOBALS['_JEAB_FORTNOX_REQUEST']);
gt_assert(empty($shiftRaised['error']), 'höjningen efter raderna faller inte, fel: ' . (string) ($shiftRaised['error'] ?? ''));
gt_assert((int) ($shiftAsked['SOLARCELLS'] ?? 0) === 11400, 'solceller höjs till 11400 när raden är skriven, fick ' . (int) ($shiftAsked['SOLARCELLS'] ?? 0));
gt_assert((int) ($shiftAsked['STORAGESELFPRODUCEDELECTRICITY'] ?? 0) === 40538, 'lagring ligger kvar på 40538');
gt_assert((int) ($shiftRaised['asked'] ?? 0) === 51938, 'ansökan efter raderna är nya möjlig 51938, fick ' . (int) ($shiftRaised['asked'] ?? 0));

function gt_split_totals(array $splits): array
{
    $out = [];
    foreach ($splits as $person) {
        if (!is_array($person)) {
            continue;
        }
        foreach ($person as $amount) {
            if (!is_array($amount)) {
                continue;
            }
            $type = (string) ($amount['WorkType'] ?? '');
            $out[$type] = ($out[$type] ?? 0) + (int) ($amount['AskedAmount'] ?? 0);
        }
    }
    return $out;
}

$solCap = (int) floor(100000 * 0.15);
$batCap = (int) floor(80000 * 0.50);
$mixedBases = [
    'SOLARCELLS' => 100000.0,
    'STORAGESELFPRODUCEDELECTRICITY' => 80000.0,
];
gt_assert(FortnoxAPI::greenTechPossible($mixedBases, 2) === $solCap + $batCap, 'två personer: sol+batteri möjlig är summan av taken');
gt_assert(FortnoxAPI::greenTechPossible($mixedBases) === 50000, 'en person: sol+batteri klampas till 50 000');
$inflated = FortnoxAPI::greenTechSplits($mixedBases, 2, 70000);
$inflatedTotals = gt_split_totals($inflated);
gt_assert(($inflatedTotals['SOLARCELLS'] ?? 0) === $solCap, 'mål över summan höjer inte Solceller, fick ' . ($inflatedTotals['SOLARCELLS'] ?? 0));
gt_assert(($inflatedTotals['STORAGESELFPRODUCEDELECTRICITY'] ?? 0) === $batCap, 'batteri stannar på sitt tak, fick ' . ($inflatedTotals['STORAGESELFPRODUCEDELECTRICITY'] ?? 0));
gt_assert(array_sum($inflatedTotals) === $solCap + $batCap, 'fördelningen klampas till summan av taken');
$onePerson = gt_split_totals(FortnoxAPI::greenTechSplits($mixedBases, 1, 70000));
gt_assert(array_sum($onePerson) === 50000, 'en person: fördelningen klampas till 50 000, fick ' . array_sum($onePerson));
$twoPeople = gt_split_totals(FortnoxAPI::greenTechSplits($mixedBases, 2, 70000));
gt_assert(($twoPeople['SOLARCELLS'] ?? 0) === $solCap, 'två personer lägger inte överskottet på Solceller');
gt_assert(($twoPeople['STORAGESELFPRODUCEDELECTRICITY'] ?? 0) === $batCap, 'två personer håller batteri på taket');

$solOnlyCap = (int) floor(200000 * 0.15);
$solOnly = FortnoxAPI::greenTechSplits(['SOLARCELLS' => 200000.0], 1, 50000);
gt_assert(count($solOnly[0] ?? []) === 1, 'bara solceller får en rad');
gt_assert(($solOnly[0][0]['WorkType'] ?? '') === 'SOLARCELLS', 'sol-only är Solceller');
gt_assert((int) ($solOnly[0][0]['AskedAmount'] ?? 0) === $solOnlyCap, 'sol-only klampas till floor(underlag×0,15), fick ' . (int) ($solOnly[0][0]['AskedAmount'] ?? 0));

$vatBases = FortnoxAPI::houseworkBases([[
    'Description' => 'Solpaneler',
    'Price' => 100000,
    'Total' => 125000,
    'HouseWork' => true,
    'HouseWorkType' => 'SOLARCELLS',
    'DeliveredQuantity' => '1',
]]);
gt_assert((float) ($vatBases['SOLARCELLS'] ?? 0) === 100000.0, 'Total med moms höjer inte solcellsunderlaget, fick ' . (float) ($vatBases['SOLARCELLS'] ?? 0));
gt_assert(FortnoxAPI::greenTechPossible($vatBases) === $solCap, 'möjlig Solceller följer à-pris, inte Total×1,25');
$discounted = FortnoxAPI::houseworkBases([[
    'Price' => 100000,
    'Total' => 90000,
    'HouseWork' => true,
    'HouseWorkType' => 'SOLARCELLS',
    'DeliveredQuantity' => '1',
]]);
gt_assert((float) ($discounted['SOLARCELLS'] ?? 0) === 90000.0, 'lägre Total är rabatt och ligger kvar som underlag');
$leftoverType = FortnoxAPI::houseworkBases([[
    'Description' => 'Schablon',
    'Price' => 40000,
    'Total' => 50000,
    'HouseWork' => false,
    'HouseWorkType' => 'SOLARCELLS',
    'DeliveredQuantity' => '1',
]]);
gt_assert(FortnoxAPI::greenTechPossible($leftoverType) === 0, 'avstängt husarbete med kvarvarande Solceller-typ räknas inte');

gt_assert(FortnoxAPI::isPerTypeTaxAmountTooHigh($solarTypeMessage) === true, 'överskrida + typ/Solceller/belopp är per-typ-tak');
gt_assert(FortnoxAPI::isPerTypeTaxAmountTooHigh($exactTooHigh) === false, 'fakturaradens överskrider är inte per typ');
gt_assert(FortnoxAPI::isPerTypeTaxAmountTooHigh('Den totala ansökta summan för en typ får inte vara 0 (Solceller)') === false, 'nollad typ är inte per-typ-tak');
$personCapMessage = 'Angivet belopp gör att totalen överskrider 50000 kr.';
gt_assert(FortnoxAPI::isPersonCapTaxAmountTooHigh($personCapMessage) === true, 'totalen överskrider 50000 är person-tak');
gt_assert(FortnoxAPI::isPerTypeTaxAmountTooHigh($personCapMessage) === false, 'person-taket är inte per-typ-tak');
gt_assert(FortnoxAPI::isTaxAmountTooHigh($personCapMessage) === true, 'person-taket räknas som för högt belopp');

$perBefore = [[
    'Description' => 'Batteri',
    'Price' => 80000,
    'Total' => 80000,
    'HouseWork' => true,
    'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY',
    'DeliveredQuantity' => '1',
]];
$perAfter = [
    [
        'Description' => 'Solpaneler',
        'Price' => 100000,
        'Total' => 125000,
        'HouseWork' => true,
        'HouseWorkType' => 'SOLARCELLS',
        'DeliveredQuantity' => '1',
    ],
    $perBefore[0],
];
$perPuts = [];
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, ?array $body) use (&$perPuts, $perAfter, $solarTypeMessage, $solCap): array {
    if ($method === 'GET' && str_contains($path, '/orders/')) {
        return ['Order' => [
            'DocumentNumber' => '14',
            'TotalToPay' => 140000,
            'TaxReduction' => 40000,
            'OrderRows' => $perAfter,
            '@urlTaxReductionList' => 'https://api.fortnox.se/3/taxreductions?filter=orders',
        ]];
    }
    if ($method === 'GET' && str_contains($path, '/taxreductions?')) {
        return ['TaxReductions' => [[
            'Id' => 14,
            'ReferenceNumber' => '14',
            'CustomerName' => 'Sam',
        ]]];
    }
    if ($method === 'GET' && str_contains($path, '/taxreductions/')) {
        return ['TaxReduction' => [
            'Id' => 14,
            'CustomerName' => 'Sam',
            'SocialSecurityNumber' => '198001011234',
            'PropertyDesignation' => 'TEST 1:1',
            'TaxReductionAmounts' => [
                ['WorkType' => 'STORAGESELFPRODUCEDELECTRICITY', 'AskedAmount' => 40000],
            ],
        ]];
    }
    if ($method === 'PUT' && str_contains($path, '/taxreductions/')) {
        $perPuts[] = $body;
        $amounts = is_array($body['TaxReduction']['TaxReductionAmounts'] ?? null) ? $body['TaxReduction']['TaxReductionAmounts'] : [];
        foreach ($amounts as $amount) {
            if (!is_array($amount)) {
                continue;
            }
            if (($amount['WorkType'] ?? '') === 'SOLARCELLS' && (int) ($amount['AskedAmount'] ?? 0) > $solCap) {
                throw new Exception($solarTypeMessage);
            }
        }
        if (count($perPuts) === 1) {
            throw new Exception($solarTypeMessage);
        }
        return ['TaxReduction' => ['Id' => 14]];
    }
    return [];
};
$perApi = new FortnoxAPI('Jarfalla');
$perSynced = $perApi->syncKopTaxReduction(
    ['fortnox_order' => '14', 'fastighetsbeteckning' => 'TEST 1:1'],
    $perBefore,
    $perAfter,
    ['Order' => ['DocumentNumber' => '14', 'TotalToPay' => 40000, 'TaxReduction' => 40000, 'OrderRows' => $perBefore]]
);
unset($GLOBALS['_JEAB_FORTNOX_REQUEST']);
gt_assert(count($perPuts) === 2, 'per-typ-fel gör ett nytt försök på taken, fick ' . count($perPuts) . ' skrivningar');
$perFirst = gt_split_totals([$perPuts[0]['TaxReduction']['TaxReductionAmounts'] ?? []]);
$perSecond = gt_split_totals([$perPuts[1]['TaxReduction']['TaxReductionAmounts'] ?? []]);
$personCapped = min($solCap + $batCap, FortnoxAPI::greenTechPersonCap());
gt_assert(($perFirst['SOLARCELLS'] ?? 0) === $solCap, 'första försöket ligger redan på solcellstaket, fick ' . ($perFirst['SOLARCELLS'] ?? 0));
gt_assert(($perFirst['STORAGESELFPRODUCEDELECTRICITY'] ?? 0) === $personCapped - $solCap, 'första försöket respekterar person-taket på batteri, fick ' . ($perFirst['STORAGESELFPRODUCEDELECTRICITY'] ?? 0));
gt_assert(array_sum($perFirst) === $personCapped, 'första försöket summerar till person-taket');
gt_assert(($perSecond['SOLARCELLS'] ?? 0) === $solCap, 'nytt försök sänker inte Solceller med en krona, fick ' . ($perSecond['SOLARCELLS'] ?? 0));
gt_assert(($perSecond['STORAGESELFPRODUCEDELECTRICITY'] ?? 0) === $personCapped - $solCap, 'nytt försök behåller person-taket på batteri, fick ' . ($perSecond['STORAGESELFPRODUCEDELECTRICITY'] ?? 0));
gt_assert(array_sum($perSecond) === $personCapped, 'nytt försök respekterar person-taket, fick ' . array_sum($perSecond));
gt_assert(empty($perSynced['error']), 'synken lyckas efter per-typ-tak, fel: ' . (string) ($perSynced['error'] ?? ''));
gt_assert((int) ($perSynced['asked'] ?? 0) === $personCapped, 'ansökan efter per-typ-retry är person-taket, fick ' . (int) ($perSynced['asked'] ?? 0));

$mustPutMessage = 'Minst en skattereduktion måste vara kopplat till en faktura som är märkt för husarbete, använd PUT för att istället uppdatera.';
gt_assert(FortnoxAPI::isTaxReductionMustUpdate($mustPutMessage) === true, 'Fortnox ber om PUT när ansökan inte får tas bort');
gt_assert(FortnoxAPI::isTaxAmountTooHigh($mustPutMessage) === false, 'PUT-kravet är inte ett för högt belopp');
$dropBatteryWrite = FortnoxAPI::greenTechAmountWrite(
    [[
        'TaxReductionAmounts' => [
            ['WorkType' => 'SOLARCELLS', 'AskedAmount' => 10500],
            ['WorkType' => 'STORAGESELFPRODUCEDELECTRICITY', 'AskedAmount' => 40000],
        ],
    ]],
    [[['WorkType' => 'SOLARCELLS', 'AskedAmount' => 10500]]]
);
gt_assert($dropBatteryWrite['replace'] === true, 'borttagen lagring markeras som försvunnen typ');
gt_assert(FortnoxAPI::greenTechHasPositiveAmounts($dropBatteryWrite['amounts']) === true, 'solceller finns kvar och ansökan ska uppdateras');
gt_assert(!str_contains(json_encode($dropBatteryWrite['amounts']) ?: '', 'STORAGESELFPRODUCEDELECTRICITY'), 'lagring skickas inte med');
gt_assert(!str_contains(json_encode($dropBatteryWrite['amounts']) ?: '', '"AskedAmount":0'), 'borttagen lagring skickas inte som 0');

$batteryBefore = [
    [
        'Description' => 'Solpaneler',
        'Price' => 70000,
        'Total' => 70000,
        'HouseWork' => true,
        'HouseWorkType' => 'SOLARCELLS',
        'DeliveredQuantity' => '1',
    ],
    [
        'Description' => 'Batteri',
        'Price' => 80000,
        'Total' => 80000,
        'HouseWork' => true,
        'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY',
        'DeliveredQuantity' => '1',
    ],
    [
        'Description' => 'Schablon',
        'Price' => 2100,
        'Total' => 2100,
        'HouseWork' => false,
        'HouseWorkType' => 'OTHERCOSTS',
        'DeliveredQuantity' => '1',
    ],
];
$batteryAfter = [$batteryBefore[0], $batteryBefore[2]];
$batteryPark = FortnoxAPI::greenTechHoldBeforeRows(
    FortnoxAPI::houseworkBases($batteryBefore),
    FortnoxAPI::houseworkBases($batteryAfter),
    ['SOLARCELLS' => 10500, 'STORAGESELFPRODUCEDELECTRICITY' => 40000],
    1
);
gt_assert((int) ($batteryPark['per_type']['SOLARCELLS'] ?? 0) === 10499, 'en krona tas från solceller så totalsumman ryms, fick ' . (int) ($batteryPark['per_type']['SOLARCELLS'] ?? 0));
gt_assert((int) ($batteryPark['per_type']['STORAGESELFPRODUCEDELECTRICITY'] ?? 0) === 1, 'lagring parkeras på 1 kr medan batteriraden finns kvar');
gt_assert((int) ($batteryPark['asked'] ?? 0) === 10500, 'parkerad ansökan är lika med ny möjlig 10500');
$batteryParkTwo = FortnoxAPI::greenTechHoldBeforeRows(
    FortnoxAPI::houseworkBases($batteryBefore),
    FortnoxAPI::houseworkBases($batteryAfter),
    ['SOLARCELLS' => 10500, 'STORAGESELFPRODUCEDELECTRICITY' => 40000],
    2
);
gt_assert((int) ($batteryParkTwo['per_type']['STORAGESELFPRODUCEDELECTRICITY'] ?? 0) === 2, 'två personer behåller en krona var på lagring');
gt_assert((int) ($batteryParkTwo['per_type']['SOLARCELLS'] ?? 0) === 10498, 'två kronor tas från solceller');
$zeroStorageMessage = 'Den totala ansökta summan för en typ får inte vara 0 (Lagring egenproducerad el)';
$batteryDeletes = 0;
$batteryPuts = [];
$batteryTax = [
    ['WorkType' => 'SOLARCELLS', 'AskedAmount' => 10500],
    ['WorkType' => 'STORAGESELFPRODUCEDELECTRICITY', 'AskedAmount' => 40000],
];
$storageRowLive = true;
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, ?array $body) use (&$batteryDeletes, &$batteryPuts, &$batteryTax, &$storageRowLive, $mustPutMessage, $batteryAfter, $zeroStorageMessage): array {
    if ($method === 'GET' && str_contains($path, '/orders/')) {
        return ['Order' => [
            'DocumentNumber' => '1856',
            'TotalToPay' => 72100,
            'TaxReduction' => 50500,
            'OrderRows' => $storageRowLive ? [$batteryAfter[0], [
                'Description' => 'Batteri',
                'Price' => 80000,
                'Total' => 80000,
                'HouseWork' => true,
                'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY',
                'DeliveredQuantity' => '1',
            ], $batteryAfter[1]] : $batteryAfter,
            '@urlTaxReductionList' => 'https://api.fortnox.se/3/taxreductions?filter=orders',
        ]];
    }
    if ($method === 'GET' && str_contains($path, '/taxreductions?')) {
        return ['TaxReductions' => [[
            'Id' => 1856,
            'ReferenceNumber' => '1856',
            'CustomerName' => 'Test Testsson',
        ]]];
    }
    if ($method === 'GET' && str_contains($path, '/taxreductions/')) {
        return ['TaxReduction' => [
            'Id' => 1856,
            'CustomerName' => 'Test Testsson',
            'SocialSecurityNumber' => '198001011234',
            'PropertyDesignation' => 'TEST 1:1',
            'TaxReductionAmounts' => $batteryTax,
        ]];
    }
    if ($method === 'DELETE' && str_contains($path, '/taxreductions/')) {
        $batteryDeletes++;
        throw new Exception($mustPutMessage);
    }
    if ($method === 'PUT' && str_contains($path, '/taxreductions/')) {
        $batteryPuts[] = $body;
        $encoded = json_encode($body, JSON_UNESCAPED_UNICODE) ?: '';
        $keptStorage = 0;
        foreach ($body['TaxReduction']['TaxReductionAmounts'] ?? [] as $amount) {
            if (is_array($amount) && ($amount['WorkType'] ?? '') === 'STORAGESELFPRODUCEDELECTRICITY') {
                $keptStorage = (int) ($amount['AskedAmount'] ?? 0);
            }
        }
        if ($storageRowLive && ($keptStorage <= 0 || str_contains($encoded, '"AskedAmount":0'))) {
            throw new Exception($zeroStorageMessage);
        }
        $next = [];
        foreach ($body['TaxReduction']['TaxReductionAmounts'] ?? [] as $amount) {
            if (is_array($amount)) {
                $next[] = $amount;
            }
        }
        $batteryTax = $next;
        return ['TaxReduction' => ['Id' => 1856]];
    }
    return [];
};
$batteryApi = new FortnoxAPI('Jarfalla');
$batteryLowered = $batteryApi->lowerKopTaxReduction(
    ['fortnox_order' => '1856', 'fortnox_gt_asked' => 50500],
    $batteryBefore,
    $batteryAfter,
    ['Order' => [
        'DocumentNumber' => '1856',
        'TotalToPay' => 72100,
        'TaxReduction' => 50500,
        'OrderRows' => $batteryBefore,
        '@urlTaxReductionList' => 'https://api.fortnox.se/3/taxreductions?filter=orders',
    ]]
);
gt_assert($batteryDeletes === 0, 'borttagen batterirad tar inte bort ansökan, fick ' . $batteryDeletes . ' borttagningar');
gt_assert(count($batteryPuts) === 1, 'ansökan parkeras med PUT innan raden tas bort, fick ' . count($batteryPuts) . ' skrivningar');
$batteryKept = [];
foreach ($batteryPuts[0]['TaxReduction']['TaxReductionAmounts'] ?? [] as $amount) {
    if (is_array($amount)) {
        $batteryKept[(string) ($amount['WorkType'] ?? '')] = (int) ($amount['AskedAmount'] ?? 0);
    }
}
gt_assert(($batteryKept['SOLARCELLS'] ?? 0) === 10499, 'solceller sänks med en krona före radskrivningen, fick ' . (int) ($batteryKept['SOLARCELLS'] ?? 0));
gt_assert(($batteryKept['STORAGESELFPRODUCEDELECTRICITY'] ?? 0) === 1, 'lagring skrivs som 1 kr, inte 0');
gt_assert(empty($batteryLowered['error']), 'Spara utan batterirad faller inte, fel: ' . (string) ($batteryLowered['error'] ?? ''));
gt_assert(!empty($batteryLowered['update']), 'borttagen batterirad skriver om ansökan');
gt_assert((int) ($batteryLowered['asked'] ?? 0) === 10500, 'ansökan före raderna är 10500, fick ' . (int) ($batteryLowered['asked'] ?? 0));
$storageRowLive = false;
$batterySynced = $batteryApi->syncKopTaxReduction(
    ['fortnox_order' => '1856', 'fortnox_gt_asked' => 10500, 'fastighetsbeteckning' => 'TEST 1:1'],
    $batteryBefore,
    $batteryAfter,
    ['Order' => [
        'DocumentNumber' => '1856',
        'TotalToPay' => 72100,
        'TaxReduction' => 10500,
        'OrderRows' => $batteryBefore,
        '@urlTaxReductionList' => 'https://api.fortnox.se/3/taxreductions?filter=orders',
    ]]
);
unset($GLOBALS['_JEAB_FORTNOX_REQUEST']);
gt_assert(count($batteryPuts) === 2, 'lagring tas bort först när batteriraden är borta, fick ' . count($batteryPuts) . ' skrivningar');
$batteryFinal = [];
foreach ($batteryPuts[1]['TaxReduction']['TaxReductionAmounts'] ?? [] as $amount) {
    if (is_array($amount)) {
        $batteryFinal[(string) ($amount['WorkType'] ?? '')] = (int) ($amount['AskedAmount'] ?? 0);
    }
}
gt_assert(($batteryFinal['SOLARCELLS'] ?? 0) === 10500, 'solceller återgår till 10500, fick ' . (int) ($batteryFinal['SOLARCELLS'] ?? 0));
gt_assert(!isset($batteryFinal['STORAGESELFPRODUCEDELECTRICITY']), 'lagring är borta när raden inte längre finns');
gt_assert(empty($batterySynced['error']), 'synken efter batteriraden faller inte, fel: ' . (string) ($batterySynced['error'] ?? ''));
gt_assert((int) ($batterySynced['asked'] ?? 0) === 10500, 'ansökan efter batteriraden är solcellernas 10500');

$liveMustPut = 'Minst en skattereduktion måste vara kopplat till en faktura som är märkt för husarbete, använd PUT för att isället uppdatera.';
gt_assert(FortnoxAPI::isTaxReductionMustUpdate($liveMustPut) === true, 'live-texten med isället ber om PUT');
gt_assert(FortnoxAPI::isTaxAmountTooHigh($liveMustPut) === false, 'live-texten är inte för högt belopp');
gt_assert(FortnoxAPI::isPerTypeTaxAmountTooHigh($liveMustPut) === false, 'live-texten är inte per-typ-tak');

$twoDeletes = 0;
$twoPuts = [];
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, ?array $body) use (&$twoDeletes, &$twoPuts, $liveMustPut): array {
    if ($method === 'GET' && str_contains($path, '/taxreductions?')) {
        return ['TaxReductions' => [
            ['Id' => 18462, 'ReferenceNumber' => '1856', 'CustomerName' => 'A'],
            ['Id' => 18463, 'ReferenceNumber' => '1856', 'CustomerName' => 'B'],
        ]];
    }
    if ($method === 'GET' && str_contains($path, '/taxreductions/18462')) {
        return ['TaxReduction' => [
            'Id' => 18462,
            'CustomerName' => 'A',
            'SocialSecurityNumber' => '198001011234',
            'PropertyDesignation' => 'TEST 1:1',
            'TaxReductionAmounts' => [
                ['WorkType' => 'SOLARCELLS', 'AskedAmount' => 5250],
                ['WorkType' => 'STORAGESELFPRODUCEDELECTRICITY', 'AskedAmount' => 20000],
            ],
        ]];
    }
    if ($method === 'GET' && str_contains($path, '/taxreductions/18463')) {
        return ['TaxReduction' => [
            'Id' => 18463,
            'CustomerName' => 'B',
            'SocialSecurityNumber' => '198002021234',
            'PropertyDesignation' => 'TEST 1:1',
            'TaxReductionAmounts' => [
                ['WorkType' => 'SOLARCELLS', 'AskedAmount' => 5250],
                ['WorkType' => 'STORAGESELFPRODUCEDELECTRICITY', 'AskedAmount' => 20000],
            ],
        ]];
    }
    if ($method === 'DELETE') {
        $twoDeletes++;
        throw new Exception($liveMustPut);
    }
    if ($method === 'PUT' && str_contains($path, '/taxreductions/')) {
        $twoPuts[] = ['path' => $path, 'body' => $body];
        $encoded = json_encode($body, JSON_UNESCAPED_UNICODE) ?: '';
        $keptStorage = 0;
        foreach ($body['TaxReduction']['TaxReductionAmounts'] ?? [] as $amount) {
            if (is_array($amount) && ($amount['WorkType'] ?? '') === 'STORAGESELFPRODUCEDELECTRICITY') {
                $keptStorage = (int) ($amount['AskedAmount'] ?? 0);
            }
        }
        if ($keptStorage <= 0 || str_contains($encoded, '"AskedAmount":0')) {
            throw new Exception('Den totala ansökta summan för en typ får inte vara 0 (Lagring egenproducerad el)');
        }
        return ['TaxReduction' => ['Id' => 1]];
    }
    return [];
};
$twoApi = new FortnoxAPI('Jarfalla');
$twoLowered = $twoApi->lowerKopTaxReduction(
    ['fortnox_order' => '1856', 'fortnox_gt_asked' => 50500],
    $batteryBefore,
    $batteryAfter,
    ['Order' => [
        'DocumentNumber' => '1856',
        'TotalToPay' => 72100,
        'TaxReduction' => 50500,
        'OrderRows' => $batteryBefore,
        '@urlTaxReductionList' => 'https://api.fortnox.se/3/taxreductions?filter=orders&referencenumber=1856',
    ]]
);
unset($GLOBALS['_JEAB_FORTNOX_REQUEST']);
gt_assert($twoDeletes === 0, 'två ansökningar raderas inte när batteriet försvinner, fick ' . $twoDeletes);
gt_assert(count($twoPuts) === 2, 'båda ansökningarna uppdateras med PUT, fick ' . count($twoPuts));
gt_assert(empty($twoLowered['error']), 'två personer sparas utan rå PUT-text, fel: ' . (string) ($twoLowered['error'] ?? ''));
foreach ($twoPuts as $put) {
    $encoded = json_encode($put['body'], JSON_UNESCAPED_UNICODE) ?: '';
    $amounts = [];
    foreach ($put['body']['TaxReduction']['TaxReductionAmounts'] ?? [] as $amount) {
        if (is_array($amount)) {
            $amounts[(string) ($amount['WorkType'] ?? '')] = (int) ($amount['AskedAmount'] ?? 0);
        }
    }
    gt_assert(($amounts['STORAGESELFPRODUCEDELECTRICITY'] ?? 0) === 1, 'varje person behåller 1 kr lagring tills raden är borta');
    gt_assert(($amounts['SOLARCELLS'] ?? 0) === 5249, 'solceller delas efter att två kronor parkerats, fick ' . (int) ($amounts['SOLARCELLS'] ?? 0));
    gt_assert(!str_contains($encoded, '"AskedAmount":0'), 'ingen person nollas');
}

$missPosts = 0;
$missPuts = 0;
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, ?array $body) use (&$missPosts, &$missPuts, $liveMustPut, $batteryAfter): array {
    if ($method === 'GET' && str_contains($path, '/orders/')) {
        return ['Order' => [
            'DocumentNumber' => '1856',
            'TotalToPay' => 70000,
            'TaxReduction' => 0,
            'OrderRows' => [$batteryAfter[0]],
            '@urlTaxReductionList' => 'https://api.fortnox.se/3/taxreductions?filter=orders&referencenumber=1856',
        ]];
    }
    if ($method === 'GET' && str_contains($path, '/taxreductions?')) {
        if ($missPosts === 0) {
            return ['TaxReductions' => []];
        }
        return ['TaxReductions' => [[
            'Id' => 18463,
            'ReferenceNumber' => '1856',
            'CustomerName' => 'A',
        ]]];
    }
    if ($method === 'GET' && str_contains($path, '/taxreductions/')) {
        return ['TaxReduction' => [
            'Id' => 18463,
            'CustomerName' => 'A',
            'SocialSecurityNumber' => '198001011234',
            'PropertyDesignation' => 'TEST 1:1',
            'TaxReductionAmounts' => [
                ['WorkType' => 'SOLARCELLS', 'AskedAmount' => 1],
            ],
        ]];
    }
    if ($method === 'POST' && str_contains($path, '/taxreductions')) {
        $missPosts++;
        throw new Exception($liveMustPut);
    }
    if ($method === 'PUT' && str_contains($path, '/taxreductions/')) {
        $missPuts++;
        return ['TaxReduction' => ['Id' => 18463]];
    }
    return [];
};
$missApi = new FortnoxAPI('Jarfalla');
$missSynced = $missApi->syncKopTaxReduction(
    [
        'fortnox_order' => '1856',
        'fastighetsbeteckning' => 'TEST 1:1',
        'huvudsokande' => 'A',
        'personnummer' => '198001011234',
    ],
    [],
    [$batteryAfter[0]],
    ['Order' => ['DocumentNumber' => '1856', 'OrderRows' => [], 'TaxReduction' => 0]]
);
unset($GLOBALS['_JEAB_FORTNOX_REQUEST']);
gt_assert($missPosts === 1, 'missad lista POST:ar en gång, fick ' . $missPosts);
gt_assert($missPuts === 1, 'POST som ber om PUT läser om ansökan och uppdaterar den');
gt_assert(empty($missSynced['error']), 'synken lyckas när ansökan redan fanns, fel: ' . (string) ($missSynced['error'] ?? ''));
gt_assert(!str_contains((string) ($missSynced['error'] ?? ''), 'använd PUT'), 'rå PUT-text lämnas inte kvar');

$closedCalls = [];
$closedAfter = [[
    'HouseWork' => false,
    'HouseWorkType' => 'SOLARCELLS',
    'Price' => 70000,
    'Total' => 70000,
    'DeliveredQuantity' => '1',
]];
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, ?array $body) use (&$closedCalls, $liveMustPut): array {
    $closedCalls[] = $method;
    if ($method === 'GET' && str_contains($path, '/taxreductions?')) {
        return ['TaxReductions' => [[
            'Id' => 18463,
            'ReferenceNumber' => '1856',
            'CustomerName' => 'A',
        ]]];
    }
    if ($method === 'GET' && str_contains($path, '/taxreductions/')) {
        return ['TaxReduction' => [
            'Id' => 18463,
            'CustomerName' => 'A',
            'SocialSecurityNumber' => '198001011234',
            'PropertyDesignation' => 'TEST 1:1',
            'TaxReductionAmounts' => [
                ['WorkType' => 'SOLARCELLS', 'AskedAmount' => 10500],
            ],
        ]];
    }
    if ($method === 'DELETE' || $method === 'POST') {
        throw new Exception($liveMustPut);
    }
    return [];
};
$closedApi = new FortnoxAPI('Jarfalla');
$closedLowered = $closedApi->lowerKopTaxReduction(
    ['fortnox_order' => '1856', 'fortnox_gt_asked' => 10500],
    [$batteryBefore[0]],
    $closedAfter,
    ['Order' => [
        'DocumentNumber' => '1856',
        'TaxReduction' => 10500,
        'OrderRows' => [$batteryBefore[0]],
        '@urlTaxReductionList' => 'https://api.fortnox.se/3/taxreductions?filter=orders&referencenumber=1856',
    ]]
);
unset($GLOBALS['_JEAB_FORTNOX_REQUEST']);
gt_assert(!in_array('DELETE', $closedCalls, true) && !in_array('POST', $closedCalls, true), 'avstängt husarbete varken raderar eller skapar ansökan');
$closedError = (string) ($closedLowered['error'] ?? '');
gt_assert($closedError !== '' && !str_contains($closedError, 'använd PUT'), 'avstängt husarbete ger eget fel, fick: ' . $closedError);
gt_assert(str_contains($closedError, 'husarbete'), 'felet nämner husarbete');

$restoreAsked = ['SOLARCELLS' => 10500, 'STORAGESELFPRODUCEDELECTRICITY' => 40000];
$restoreSolarOnly = ['SOLARCELLS' => 70000.0, 'STORAGESELFPRODUCEDELECTRICITY' => 0.0];
gt_assert(
    FortnoxAPI::greenTechRestoreKept('STORAGESELFPRODUCEDELECTRICITY', $restoreAsked, $restoreSolarOnly, 1) === 1,
    'utan lagringsunderlag parkeras 1 kr, inte hela tidigare beloppet'
);
gt_assert(
    FortnoxAPI::greenTechRestoreKept('STORAGESELFPRODUCEDELECTRICITY', $restoreAsked, $restoreSolarOnly, 2) === 2,
    'två personer parkerar en krona var när lagring saknar underlag'
);
gt_assert(
    FortnoxAPI::greenTechRestoreKept('STORAGESELFPRODUCEDELECTRICITY', $restoreAsked, ['SOLARCELLS' => 70000.0, 'STORAGESELFPRODUCEDELECTRICITY' => 1000.0], 1) === 500,
    'lägre lagringsunderlag takar återställningen till möjligt belopp'
);
gt_assert(
    FortnoxAPI::greenTechRestoreKept('STORAGESELFPRODUCEDELECTRICITY', ['STORAGESELFPRODUCEDELECTRICITY' => 100], ['STORAGESELFPRODUCEDELECTRICITY' => 1000.0], 1) === 100,
    'återställning höjer inte en typ över tidigare ansökan'
);
gt_assert(
    FortnoxAPI::greenTechRestoreKept('STORAGESELFPRODUCEDELECTRICITY', ['SOLARCELLS' => 10500], $restoreSolarOnly, 1) === 0,
    'typ som inte var ansökt skrivs inte tillbaka'
);

function gt_body_totals(?array $body): array
{
    $amounts = $body['TaxReduction']['TaxReductionAmounts'] ?? [];
    return gt_split_totals([is_array($amounts) ? $amounts : []]);
}

function gt_storage_of_puts(array $puts): array
{
    $max = 0;
    $sum = 0;
    foreach ($puts as $body) {
        $amount = (int) (gt_body_totals(is_array($body) ? $body : [])['STORAGESELFPRODUCEDELECTRICITY'] ?? 0);
        $max = max($max, $amount);
        $sum += $amount;
    }
    return ['max' => $max, 'sum' => $sum];
}

$overStorageMessage = 'Den ansökta summan för en typ får inte överskrida möjligt belopp för den typen (Lagring egenproducerad el)';
gt_assert(FortnoxAPI::isPerTypeTaxAmountTooHigh($overStorageMessage) === true, 'överskrida lagring är per-typ-tak');

$solarLive = [$batteryBefore[0]];
$lowerStorageLive = [
    $batteryBefore[0],
    [
        'Description' => 'Batteri',
        'Price' => 1000,
        'Total' => 1000,
        'HouseWork' => true,
        'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY',
        'DeliveredQuantity' => '1',
    ],
];
$highStorageTax = [[
    'Id' => 1856,
    'CustomerName' => 'Test Testsson',
    'SocialSecurityNumber' => '198001011234',
    'PropertyDesignation' => 'TEST 1:1',
    'TaxReductionAmounts' => [
        ['WorkType' => 'SOLARCELLS', 'AskedAmount' => 10500],
        ['WorkType' => 'STORAGESELFPRODUCEDELECTRICITY', 'AskedAmount' => 40000],
    ],
]];

/**
 * @param list<array<string,mixed>> $liveRows
 * @param list<array<string,mixed>> $taxRows
 */
$gtRestoreStub = static function (array &$puts, int $cap, bool $forceFirstZero, bool $rejectFirstPark, array $liveRows, array $taxRows) use ($zeroStorageMessage, $overStorageMessage): void {
    $zeros = 0;
    $didForce = false;
    $rejectedPark = false;
    $GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, ?array $body) use (&$puts, &$zeros, &$didForce, &$rejectedPark, $cap, $forceFirstZero, $rejectFirstPark, $liveRows, $taxRows, $zeroStorageMessage, $overStorageMessage): array {
        if ($method === 'GET' && str_contains($path, '/orders/')) {
            return ['Order' => [
                'DocumentNumber' => '1856',
                'TotalToPay' => 70000,
                'TaxReduction' => 50500,
                'OrderRows' => $liveRows,
                '@urlTaxReductionList' => 'https://api.fortnox.se/3/taxreductions?filter=orders',
            ]];
        }
        if ($method === 'GET' && str_contains($path, '/taxreductions?')) {
            $list = [];
            foreach ($taxRows as $taxRow) {
                $list[] = [
                    'Id' => $taxRow['Id'] ?? 0,
                    'ReferenceNumber' => '1856',
                    'CustomerName' => $taxRow['CustomerName'] ?? 'Test',
                ];
            }
            return ['TaxReductions' => $list];
        }
        if ($method === 'GET' && str_contains($path, '/taxreductions/')) {
            foreach ($taxRows as $taxRow) {
                $id = (string) ($taxRow['Id'] ?? '');
                if ($id !== '' && str_contains($path, '/' . $id)) {
                    return ['TaxReduction' => $taxRow];
                }
            }
            return ['TaxReduction' => $taxRows[0]];
        }
        if ($method === 'PUT' && str_contains($path, '/taxreductions/')) {
            $puts[] = $body;
            $storage = (int) (gt_body_totals($body)['STORAGESELFPRODUCEDELECTRICITY'] ?? 0);
            if ($storage > $cap) {
                throw new Exception($overStorageMessage);
            }
            if ($forceFirstZero && !$didForce) {
                $didForce = true;
                throw new Exception($zeroStorageMessage);
            }
            if ($storage <= 0 && $zeros === 0) {
                $zeros++;
                throw new Exception($zeroStorageMessage);
            }
            if ($rejectFirstPark && $storage > 0 && !$rejectedPark) {
                $rejectedPark = true;
                throw new Exception($overStorageMessage);
            }
            return ['TaxReduction' => ['Id' => 1856]];
        }
        return [];
    };
};

$restorePuts = [];
$gtRestoreStub($restorePuts, 1, false, false, $solarLive, $highStorageTax);
$restoreApi = new FortnoxAPI('Jarfalla');
$restoreSynced = $restoreApi->syncKopTaxReduction(
    ['fortnox_order' => '1856', 'fastighetsbeteckning' => 'TEST 1:1'],
    $batteryBefore,
    $solarLive,
    ['Order' => ['DocumentNumber' => '1856', 'OrderRows' => $batteryBefore, 'TaxReduction' => 50500]]
);
unset($GLOBALS['_JEAB_FORTNOX_REQUEST']);
$restoreStorage = gt_storage_of_puts($restorePuts);
gt_assert($restoreStorage['max'] <= 1, 'återställning utan underlag skickar högst 1 kr lagring, fick ' . $restoreStorage['max']);
gt_assert($restoreStorage['sum'] === 1, 'nollad lagring parkeras på 1 kr, fick summa ' . $restoreStorage['sum']);
gt_assert(empty($restoreSynced['error']), 'synken utan lagringsrad lämnar inte överskrida, fel: ' . (string) ($restoreSynced['error'] ?? ''));
gt_assert(!str_contains((string) ($restoreSynced['error'] ?? ''), 'överskrida'), 'felloggen får inte rå överskrida-text');

$twoRestoreTax = [
    [
        'Id' => 18462,
        'CustomerName' => 'A',
        'SocialSecurityNumber' => '198001011234',
        'PropertyDesignation' => 'TEST 1:1',
        'TaxReductionAmounts' => [
            ['WorkType' => 'SOLARCELLS', 'AskedAmount' => 5250],
            ['WorkType' => 'STORAGESELFPRODUCEDELECTRICITY', 'AskedAmount' => 20000],
        ],
    ],
    [
        'Id' => 18463,
        'CustomerName' => 'B',
        'SocialSecurityNumber' => '198002021234',
        'PropertyDesignation' => 'TEST 1:1',
        'TaxReductionAmounts' => [
            ['WorkType' => 'SOLARCELLS', 'AskedAmount' => 5250],
            ['WorkType' => 'STORAGESELFPRODUCEDELECTRICITY', 'AskedAmount' => 20000],
        ],
    ],
];
$twoRestorePuts = [];
$gtRestoreStub($twoRestorePuts, 1, false, false, $solarLive, $twoRestoreTax);
$twoRestoreApi = new FortnoxAPI('Jarfalla');
$twoRestoreSynced = $twoRestoreApi->syncKopTaxReduction(
    ['fortnox_order' => '1856', 'fastighetsbeteckning' => 'TEST 1:1'],
    $batteryBefore,
    $solarLive,
    ['Order' => ['DocumentNumber' => '1856', 'OrderRows' => $batteryBefore, 'TaxReduction' => 50500]]
);
unset($GLOBALS['_JEAB_FORTNOX_REQUEST']);
$twoRestoreStorage = gt_storage_of_puts($twoRestorePuts);
gt_assert($twoRestoreStorage['max'] <= 1, 'två personer får högst 1 kr lagring var, fick ' . $twoRestoreStorage['max']);
gt_assert($twoRestoreStorage['sum'] === 2, 'två personer parkerar 2 kr tillsammans, fick ' . $twoRestoreStorage['sum']);
gt_assert(empty($twoRestoreSynced['error']), 'två personer återställs inom taket, fel: ' . (string) ($twoRestoreSynced['error'] ?? ''));

$lowerRestorePuts = [];
$gtRestoreStub($lowerRestorePuts, 500, true, false, $lowerStorageLive, $highStorageTax);
$lowerRestoreApi = new FortnoxAPI('Jarfalla');
$lowerRestoreSynced = $lowerRestoreApi->syncKopTaxReduction(
    ['fortnox_order' => '1856', 'fastighetsbeteckning' => 'TEST 1:1'],
    $batteryBefore,
    $lowerStorageLive,
    ['Order' => ['DocumentNumber' => '1856', 'OrderRows' => $batteryBefore, 'TaxReduction' => 50500]]
);
unset($GLOBALS['_JEAB_FORTNOX_REQUEST']);
$lowerRestoreStorage = gt_storage_of_puts($lowerRestorePuts);
gt_assert($lowerRestoreStorage['max'] <= 500, 'lägre lagringsbas återställs högst till 500, fick ' . $lowerRestoreStorage['max']);
gt_assert($lowerRestoreStorage['max'] > 1, 'lägre underlag behåller mer än parkeringskronan, fick ' . $lowerRestoreStorage['max']);
gt_assert(empty($lowerRestoreSynced['error']), 'lägre lagring lämnar inte överskrida, fel: ' . (string) ($lowerRestoreSynced['error'] ?? ''));
gt_assert(!str_contains((string) ($lowerRestoreSynced['error'] ?? ''), 'överskrida'), 'lägre lagring loggar inte rå överskrida');

$caughtPuts = [];
$gtRestoreStub($caughtPuts, 1, false, true, $solarLive, $highStorageTax);
$caughtApi = new FortnoxAPI('Jarfalla');
$caughtSynced = $caughtApi->syncKopTaxReduction(
    ['fortnox_order' => '1856', 'fastighetsbeteckning' => 'TEST 1:1'],
    $batteryBefore,
    $solarLive,
    ['Order' => ['DocumentNumber' => '1856', 'OrderRows' => $batteryBefore, 'TaxReduction' => 50500]]
);
unset($GLOBALS['_JEAB_FORTNOX_REQUEST']);
$caughtStorage = gt_storage_of_puts($caughtPuts);
gt_assert($caughtStorage['max'] <= 1, 'överskrida efter återställning skickar inte gamla lagringen, fick ' . $caughtStorage['max']);
gt_assert(count($caughtPuts) >= 3, 'överskrida efter återställning gör ett nytt försök på taken, fick ' . count($caughtPuts) . ' skrivningar');
gt_assert(empty($caughtSynced['error']), 'överskrida efter återställning hanteras, fel: ' . (string) ($caughtSynced['error'] ?? ''));
gt_assert(!str_contains((string) ($caughtSynced['error'] ?? ''), 'överskrida'), 'hanterad överskrida blir inte fellogg');
gt_assert((int) ($caughtSynced['asked'] ?? 0) === 10500, 'nytt försök landar på solcellernas tak, fick ' . (int) ($caughtSynced['asked'] ?? 0));

echo "OK  fakturering grön teknik\n";
