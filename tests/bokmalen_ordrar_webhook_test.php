<?php

declare(strict_types=1);

/**
 * Beställ-webhook ska gå för sol/batteri även utan kWh, nät och anläggnings-id.
 * Laddbox postas inte. Utkast bär utkast:true så Bokmalen inte kör live-shop.
 * Saknad secret och misslyckad POST syns i felloggen utan url eller nyckel.
 * Leveransadress, postnummer, ort, telefon och leveransdatum fylls från order,
 * sedan kontakt/påminnelse, sedan Fortnox-kundens leveransadress. Inget personnummer.
 */

$tmp = sys_get_temp_dir() . '/jeab-bokmalen-ordrar-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempdir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_BUG_WEBHOOK_CAPTURE=1');
$_ENV['JEAB_BUG_WEBHOOK_CAPTURE'] = '1';
$GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];

require dirname(__DIR__) . '/src/bootstrap.php';

function bm_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function bm_secret(string $url, string $key): void
{
    $path = jeab_bokmalen_ordrar_webhook_secret_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    file_put_contents($path, json_encode(['url' => $url, 'key' => $key], JSON_UNESCAPED_SLASHES));
}

function bm_clear_secret(): void
{
    $path = jeab_bokmalen_ordrar_webhook_secret_path();
    if (is_file($path)) {
        unlink($path);
    }
}

function bm_log(): string
{
    $path = jeab_app_errors_path();
    return is_file($path) ? (string) file_get_contents($path) : '';
}

function bm_reset_capture(): void
{
    $GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
}

/** @return list<array<string,mixed>> */
function bm_captured(): array
{
    $rows = $GLOBALS['_JEAB_BUG_WEBHOOKS'] ?? [];
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

function bm_pnr_pattern(string $json): bool
{
    return preg_match('/(?<!\d)(?:(?:19|20)\d{6}[-+]?\d{4}|\d{6}[-+]\d{4})(?!\d)/', $json) === 1;
}

/** @param array<mixed> $payload */
function bm_pnr_keys(array $payload, string $path = ''): array
{
    $bad = [];
    foreach ($payload as $key => $value) {
        if (is_string($key) && jeab_bokmalen_ordrar_pnr_key($key)) {
            $bad[] = $path . $key;
        }
        if (is_array($value)) {
            $bad = array_merge($bad, bm_pnr_keys($value, $path . $key . '.'));
        }
    }

    return $bad;
}

$bare = [
    'reminder_id' => 42,
    'kind' => 'sol',
    'company' => 'jarfalla',
    'name' => 'Test Kund',
    'address' => 'Exempelgatan 1',
    'natbolag' => '',
    'anlaggnings_id' => '',
    'kwh' => '',
];

bm_assert(jeab_ordrar_bokmalen_missing($bare, 'ordrar.bestall') === [], 'sol beställ utan kWh/nät/anläggning saknar inget för webhook');

$batteri = $bare;
$batteri['kind'] = 'batteri';
$batteri['kwh'] = '';
bm_assert(jeab_ordrar_bokmalen_missing($batteri, 'ordrar.bestall') === [], 'batteri beställ utan kWh blockeras inte');

$ladd = $bare;
$ladd['kind'] = 'laddbox';
bm_assert(jeab_ordrar_bokmalen_missing($ladd, 'ordrar.bestall') === [], 'laddbox har ingen beställ-grind');
bm_assert(jeab_bokmalen_ordrar_webhook_payload('ordrar.bestall', $ladd, false) === null, 'laddbox ger ingen payload');

$foran = $bare;
$foran['natbolag'] = 'E.ON';
$foranMissing = jeab_ordrar_bokmalen_missing($foran, 'ordrar.foranmalan');
bm_assert(in_array('anläggnings-id', $foranMissing, true), 'föranmälan E.ON kräver anläggnings-id');
bm_assert(in_array('kWh', $foranMissing, true), 'föranmälan kräver kWh (storlek till nätportalen)');
bm_assert(!in_array('nätbolag', $foranMissing, true), 'satt E.ON räcker som nätbolag');

$ellevio = $foran;
$ellevio['natbolag'] = 'Ellevio';
$ellevioMissing = jeab_ordrar_bokmalen_missing($ellevio, 'ordrar.foranmalan');
bm_assert($ellevioMissing === ['kWh'], 'Ellevio föranmälan hoppar anläggnings-id men kräver kWh, fick: ' . implode(', ', $ellevioMissing));

$manualForan = $foran;
$manualForan['foranmalan_status'] = 'C';
$manualMissing = jeab_ordrar_bokmalen_missing($manualForan, 'ordrar.foranmalan');
bm_assert(!in_array('anläggnings-id', $manualMissing, true), 'föranmälan C (Manuell/ingen) undantar anläggnings-id, fick: ' . implode(', ', $manualMissing));
bm_assert(in_array('kWh', $manualMissing, true), 'föranmälan C kräver fortfarande kWh');
$bestallManual = $foran;
$bestallManual['foranmalan_status'] = 'A';
$bestallManual['bestallning_status'] = 'C';
bm_assert(
    in_array('anläggnings-id', jeab_ordrar_bokmalen_missing($bestallManual, 'ordrar.foranmalan'), true),
    'beställning C undantar inte anläggnings-id på föranmälan'
);
$vattenfallManual = $manualForan;
$vattenfallManual['natbolag'] = 'Vattenfall';
$vattenfallManual['kwh'] = '10';
bm_assert(jeab_ordrar_bokmalen_missing($vattenfallManual, 'ordrar.foranmalan') === [], 'Vattenfall Manuell/ingen utan anläggnings-id saknar inget');

$ready = $foran;
$ready['anlaggnings_id'] = '735999';
$ready['kwh'] = '12';
bm_assert(jeab_ordrar_bokmalen_missing($ready, 'ordrar.foranmalan') === [], 'komplett föranmälan saknar inget');

$bestallHelp = jeab_ordrar_bokmalen_help_html($bare, 'ordrar.bestall');
bm_assert($bestallHelp === '', 'beställ-rutan visar inte Saknas för Bokmalen när kWh saknas');
$foranHelp = jeab_ordrar_bokmalen_help_html($foran, 'ordrar.foranmalan');
bm_assert(str_contains($foranHelp, 'kWh') && str_contains($foranHelp, 'anläggnings-id'), 'föranmälan-hjälpen listar kWh och anläggnings-id');

$leakKey = 'do-not-leak-bokmalen-key';
bm_secret('https://bokmalen.example.invalid/hooks/jeab-ordrar', $leakKey);
bm_reset_capture();
jeab_bokmalen_ordrar_notify('ordrar.bestall', $bare, false);
$posted = bm_captured();
bm_assert(count($posted) === 1, 'sol beställ utan kWh fångar en payload, fick ' . count($posted));
bm_assert(($posted[0]['type'] ?? '') === 'ordrar.bestall', 'payload type är ordrar.bestall');
bm_assert(($posted[0]['kind'] ?? '') === 'sol', 'payload kind är sol');
bm_assert(($posted[0]['kwh'] ?? null) === '', 'payload får gå med tom kWh');
bm_assert(($posted[0]['utkast'] ?? null) === false, 'automatisk beställ har utkast false');
bm_assert(($posted[0]['order_id'] ?? 0) === 42, 'payload har order_id från reminder_id');
bm_assert(($posted[0]['inkop_lines'] ?? null) === [], 'beställ utan inköpsrader skickar tom inkop_lines');
bm_assert(($posted[0]['address'] ?? '') === 'Exempelgatan 1', 'befintligt address-fält behåller orderns adress');
bm_assert(($posted[0]['leveransadress'] ?? '') === 'Exempelgatan 1', 'leveransadress är gatan');
bm_assert(array_key_exists('postnummer', $posted[0]) && array_key_exists('ort', $posted[0]), 'postnummer och ort finns i payload');
bm_assert(array_key_exists('telefon', $posted[0]) && array_key_exists('leveransdatum', $posted[0]), 'telefon och leveransdatum finns i payload');
bm_assert(!array_key_exists('personnummer', $posted[0]), 'beställ-payload har inget personnummer-fält');
bm_assert(!str_contains(bm_log(), $leakKey), 'lyckad capture skriver inte nyckeln i felloggen');
bm_assert(!str_contains(bm_log(), 'example.invalid'), 'lyckad capture skriver inte webhook-url i felloggen');

$stored = jeab_ordrar_inkop_from_input([
    'line_sku' => ['11010017', 'BAT', 'FEET', ''],
    'line_title' => ['SigenStor EC 20.0 kW TP', 'Batteri', 'Fötter', ''],
    'line_qty' => ['0', '3', '', '1'],
]);
bm_assert($stored[0]['qty'] === 0, 'explicit 0 sparas som 0');
bm_assert($stored[1]['qty'] === 3, 'antal 3 sparas som 3');
bm_assert($stored[2]['qty'] === '', 'tomt antal förblir blankt');
$reloaded = json_decode((string) json_encode($stored), true);
bm_assert(is_array($reloaded) && $reloaded[0]['qty'] === 0, '0 överlever JSON-roundtrip');
bm_assert(is_array($reloaded) && $reloaded[1]['qty'] === 3, '3 överlever JSON-roundtrip');
bm_assert(is_array($reloaded) && $reloaded[2]['qty'] === '', 'blank överlever JSON-roundtrip');
bm_assert(jeab_ordrar_inkop_qty_input(0) === '0', 'reload visar 0');
bm_assert(jeab_ordrar_inkop_qty_input('0') === '0', 'reload visar strängen 0 som 0');
bm_assert(jeab_ordrar_inkop_qty_input('') === '', 'reload visar blankt fält tomt');
bm_assert(jeab_ordrar_inkop_qty_input(3) === '3', 'reload visar 3');
$page = (string) file_get_contents(dirname(__DIR__) . '/src/pages/ordrar.php');
bm_assert(str_contains($page, 'jeab_ordrar_inkop_qty_input'), 'orderrader-fältet använder qty-input som behåller 0');

$zeroOnly = [
    'kind' => 'sol',
    'inkop_lines' => [
        ['sku' => '11010017', 'qty' => '', 'title' => 'SigenStor EC 20.0 kW TP'],
        ['sku' => 'X', 'qty' => 0, 'title' => 'Noll'],
    ],
];
$qtyError = jeab_ordrar_bestall_qty_error($zeroOnly);
bm_assert($qtyError !== '', 'automatisk beställ utan positivt antal ger fel');
bm_assert(str_starts_with(jeab_ordrar_save_fail_text($qtyError), 'Kunde inte spara'), 'felet följer Kunde inte spara-mönstret');
bm_assert(jeab_ordrar_bestall_qty_error(['kind' => 'sol', 'inkop_lines' => [['sku' => 'BAT', 'qty' => 3]]]) === '', 'antal 3 släpper igenom beställ');
bm_assert(jeab_ordrar_bestall_qty_error(['kind' => 'laddbox', 'inkop_lines' => []]) === '', 'laddbox kräver inte inköpsantal');

$withLines = $bare;
$withLines['id'] = 999001;
$withLines['reminder_id'] = 4812;
$withLines['inkop_lines'] = [
    ['sku' => 'SIG-12', 'qty' => 1, 'title' => 'Växelriktare', 'shop' => 'Aprilice'],
    ['sku' => '', 'qty' => 2, 'title' => 'Saknar artikelnummer', 'shop' => 'lightleap'],
    ['sku' => 'BAT', 'qty' => 3, 'title' => 'Batteri', 'shop' => 'aprilice'],
    ['sku' => 'FAKE-SKU', 'qty' => '1', 'title' => 'Fejk', 'shop' => 'aprilice', 'order_no' => 'A-10'],
    ['sku' => '11010017', 'title' => 'SigenStor EC 20.0 kW TP', 'qty' => '', 'shop' => 'aprilice'],
    ['sku' => 'ZERO', 'title' => 'Nollrad', 'qty' => 0, 'shop' => 'aprilice'],
    ['sku' => '', 'title' => '', 'qty' => 0, 'shop' => ''],
    'inte-en-rad',
];
bm_reset_capture();
jeab_bokmalen_ordrar_notify('ordrar.bestall', $withLines, false);
$linesPost = bm_captured();
bm_assert(count($linesPost) === 1, 'beställ med inköpsrader fångar en payload');
$linePayload = $linesPost[0];
bm_assert(($linePayload['order_id'] ?? 0) === 4812, 'order_id är reminder_id, inte probe-id');
bm_assert(($linePayload['order_id'] ?? 0) !== 999001, 'probe-id 999001 används inte som order_id');
bm_assert(($linePayload['utkast'] ?? null) === false, 'automatisk beställ med rader har utkast false');
$sentLines = $linePayload['inkop_lines'] ?? null;
bm_assert(is_array($sentLines) && count($sentLines) === 4, 'bara antal > 0 följer med, fick ' . (is_array($sentLines) ? count($sentLines) : -1));
$sentSkus = is_array($sentLines) ? array_map(static fn (array $line): string => (string) ($line['sku'] ?? ''), $sentLines) : [];
bm_assert(!in_array('11010017', $sentSkus, true), 'blankt antal på SKU skickas inte');
bm_assert(!in_array('ZERO', $sentSkus, true), 'antal 0 skickas inte');
bm_assert(in_array('BAT', $sentSkus, true), 'antal 3 ingår');
bm_assert(
    is_array($sentLines)
        && ($sentLines[0]['sku'] ?? '') === 'SIG-12'
        && ($sentLines[0]['qty'] ?? 0) === 1
        && ($sentLines[0]['title'] ?? '') === 'Växelriktare'
        && ($sentLines[0]['shop'] ?? '') === 'aprilice',
    'första raden har sku, qty, title och shop aprilice'
);
bm_assert(
    is_array($sentLines)
        && ($sentLines[1]['sku'] ?? 'x') === ''
        && ($sentLines[1]['title'] ?? '') === 'Saknar artikelnummer'
        && ($sentLines[1]['shop'] ?? '') === 'lightleap'
        && ($sentLines[1]['qty'] ?? 0) === 2,
    'tom SKU med positivt antal filtreras inte bort'
);
$bat = null;
$fake = null;
if (is_array($sentLines)) {
    foreach ($sentLines as $line) {
        if (($line['sku'] ?? '') === 'BAT') {
            $bat = $line;
        }
        if (($line['sku'] ?? '') === 'FAKE-SKU') {
            $fake = $line;
        }
    }
}
bm_assert(is_array($bat) && ($bat['qty'] ?? 0) === 3, 'BAT-raden har qty 3');
bm_assert(is_array($fake) && ($fake['sku'] ?? '') === 'FAKE-SKU' && ($fake['order_no'] ?? '') === 'A-10', 'fejk-SKU med antal > 0 och övriga radfält följer med');
foreach (is_array($sentLines) ? $sentLines : [] as $line) {
    bm_assert(jeab_bokmalen_ordrar_line_qty_positive($line), 'payloadrad har antal > 0');
}

$fortnoxCalls = 0;
$GLOBALS['_JEAB_FORTNOX_CUSTOMER_GET'] = static function (array $row) use (&$fortnoxCalls): array {
    $fortnoxCalls++;
    bm_assert((string) ($row['fortnox_customer'] ?? '') === '1904', 'Fortnox-uppslaget använder kund 1904');

    return [
        'CustomerNumber' => '1904',
        'Name' => 'Test Kund',
        'OrganisationNumber' => '198001011234',
        'personnummer' => '19700101-0101',
        'Address1' => 'Fakturagatan 9',
        'ZipCode' => '11122',
        'City' => 'Felstad',
        'DeliveryAddress1' => 'Vilanvägen 3',
        'DeliveryZipCode' => '17670',
        'DeliveryCity' => 'Järfälla',
        'Phone1' => '08-580 000 00',
        'Phone2' => '0707654321',
        'DeliveryDate' => '2026-11-02',
    ];
};
$GLOBALS['_JEAB_CONTACTS_SOLE_CACHE'] = [
    'c-vilan' => [
        'id' => 'c-vilan',
        'street' => 'Kontaktvägen 8',
        'postal_code' => '16552',
        'city' => 'Hässelby',
        'phone' => '0703333333',
        'personnummer' => '19800101-1234',
        'owners' => [[
            'name' => 'Kontakt Test',
            'contact' => true,
            'phone' => '0703333333',
            'personnummer' => '19800101-1234',
        ]],
    ],
    'c-empty' => [
        'id' => 'c-empty',
        'street' => '',
        'postal_code' => '',
        'city' => '',
        'phone' => '',
        'personnummer' => '19900101-0101',
    ],
];

$orderWins = [
    'reminder_id' => 88011,
    'kind' => 'sol',
    'company' => 'jarfalla',
    'name' => 'Order Kund',
    'address' => 'Ordergatan 2, 111 22 Solna',
    'gata' => 'Ordergatan 2',
    'postnummer' => '11122',
    'ort' => 'Solna',
    'phone' => '08-123 00 00',
    'mobil' => '0701111111',
    'onskad_leverans' => '2026-10-20',
    'contact_id' => 'c-vilan',
    'fortnox_customer' => '1904',
    'personnummer' => '19700101-0101',
];
$fortnoxCalls = 0;
$orderPayload = jeab_bokmalen_ordrar_webhook_payload('ordrar.bestall', $orderWins, false);
bm_assert(is_array($orderPayload), 'order med egna fält ger payload');
bm_assert(($orderPayload['address'] ?? '') === 'Ordergatan 2, 111 22 Solna', 'address behåller orderns sparade rad');
bm_assert(($orderPayload['leveransadress'] ?? '') === 'Ordergatan 2', 'orderfält vinner gatan över kontakt och Fortnox');
bm_assert(($orderPayload['postnummer'] ?? '') === '111 22', 'orderfält vinner postnummer');
bm_assert(($orderPayload['ort'] ?? '') === 'Solna', 'orderfält vinner ort');
bm_assert(($orderPayload['telefon'] ?? '') === '+46701111111', 'telefon är orderns mobil');
bm_assert(($orderPayload['leveransdatum'] ?? '') === '2026-10-20', 'leveransdatum är önskat datum på ordern');
bm_assert($fortnoxCalls === 0, 'full order frågar inte Fortnox, fick ' . $fortnoxCalls);

$store = jeab_store();
$reminder = $store->insertReminder([
    'title' => 'Batteri',
    'customer_name' => 'Påminnelse Kund',
    'phone' => '0702222222',
    'address' => 'Påminnelsevägen 4, 177 00 Järfälla',
    'company' => 'jarfalla',
    'created_at' => jeab_now(),
    'updated_at' => jeab_now(),
]);
$reminderId = (int) ($reminder['id'] ?? 0);
bm_assert($reminderId > 0, 'påminnelsen skapas');
$fromReminder = [
    'reminder_id' => $reminderId,
    'kind' => 'batteri',
    'company' => 'jarfalla',
    'name' => 'Påminnelse Kund',
    'address' => '',
    'huvudsokande_address' => '',
    'contact_id' => 'c-empty',
    'fortnox_customer' => '1904',
    'personnummer' => '19700101-0101',
];
$fortnoxCalls = 0;
$reminderPayload = jeab_bokmalen_ordrar_webhook_payload('ordrar.bestall', $fromReminder, false);
bm_assert(is_array($reminderPayload), 'tom order med påminnelse ger payload');
bm_assert(($reminderPayload['leveransadress'] ?? '') === 'Påminnelsevägen 4', 'påminnelsen fyller gatan när order och kontakt saknar den');
bm_assert(($reminderPayload['postnummer'] ?? '') === '177 00', 'påminnelsen fyller postnummer');
bm_assert(($reminderPayload['ort'] ?? '') === 'Järfälla', 'påminnelsen fyller ort');
bm_assert(($reminderPayload['telefon'] ?? '') === '+46702222222', 'påminnelsen fyller mobil');
bm_assert(jeab_bokmalen_ordrar_row_leveransdatum(['onskad_leverans' => '2026-12-01']) === '2026-12-01', 'sparat datum på påminnelsen läses');
bm_assert(($reminderPayload['leveransdatum'] ?? '') === '2026-11-02', 'påminnelsen har inget datum, Fortnox fyller leveransdatum');
bm_assert(($reminderPayload['address'] ?? '') === 'Påminnelsevägen 4', 'tomt address-fält fylls med gatan');
bm_assert($fortnoxCalls === 1, 'Fortnox anropas för datumet som påminnelsen saknar, fick ' . $fortnoxCalls);

$fromContact = [
    'reminder_id' => 88033,
    'kind' => 'sol',
    'company' => 'jarfalla',
    'name' => 'Kontakt Kund',
    'address' => '',
    'contact_id' => 'c-vilan',
    'fortnox_customer' => '1904',
    'personnummer' => '19700101-0101',
    'owners' => [['name' => 'Kontakt Kund', 'personnummer' => '198001011234']],
];
$fortnoxCalls = 0;
$contactPayload = jeab_bokmalen_ordrar_webhook_payload('ordrar.bestall', $fromContact, false);
bm_assert(is_array($contactPayload), 'länkad kontakt ger payload');
bm_assert(($contactPayload['leveransadress'] ?? '') === 'Kontaktvägen 8', 'kontakten vinner över Fortnox');
bm_assert(($contactPayload['postnummer'] ?? '') === '165 52', 'kontakten fyller postnummer');
bm_assert(($contactPayload['ort'] ?? '') === 'Hässelby', 'kontakten fyller ort');
bm_assert(($contactPayload['telefon'] ?? '') === '+46703333333', 'kontakten fyller mobil');
bm_assert(($contactPayload['leveransdatum'] ?? '') === '2026-11-02', 'kontakten saknar datum så Fortnox fyller leveransdatum');
bm_assert($fortnoxCalls === 1, 'kontakten fyller adressen och Fortnox bara datumet, fick ' . $fortnoxCalls);

$fromFortnox = [
    'reminder_id' => 88044,
    'kind' => 'sol',
    'company' => 'jarfalla',
    'name' => 'Fortnox Kund',
    'address' => '',
    'huvudsokande_address' => '',
    'contact_id' => 'c-empty',
    'fortnox_customer' => '1904',
    'personnummer' => '19700101-0101',
    'owners' => [['name' => 'Fortnox Kund', 'personnummer' => '19800101-1234']],
    'inkop_lines' => [[
        'sku' => 'SIG',
        'qty' => 1,
        'title' => 'Panel 19800101-1234 198001011234',
        'shop' => 'aprilice',
        'personnummer' => '19800101-1234',
        'orgnr' => '5599999999',
    ]],
];
$fortnoxCalls = 0;
$fortnoxPayload = jeab_bokmalen_ordrar_webhook_payload('ordrar.bestall', $fromFortnox, false);
bm_assert(is_array($fortnoxPayload), 'Fortnox-kund 1904 ger payload');
bm_assert($fortnoxCalls === 1, 'tom adress hämtar Fortnox-kunden en gång, fick ' . $fortnoxCalls);
bm_assert(($fortnoxPayload['address'] ?? '') === 'Vilanvägen 3', 'tomt address-fält fylls från Fortnox leveransadress');
bm_assert(($fortnoxPayload['leveransadress'] ?? '') === 'Vilanvägen 3', 'leveransadress är Vilanvägen 3, inte fakturaadressen');
bm_assert(($fortnoxPayload['postnummer'] ?? '') === '176 70', 'postnummer kommer från DeliveryZipCode');
bm_assert(($fortnoxPayload['ort'] ?? '') === 'Järfälla', 'ort kommer från DeliveryCity');
bm_assert(($fortnoxPayload['telefon'] ?? '') === '+46707654321', 'telefon är mobilnumret, inte riktnumret');
bm_assert(($fortnoxPayload['leveransdatum'] ?? '') === '2026-11-02', 'leveransdatum kommer från Fortnox när ordern saknar datum');
bm_assert(array_key_exists('customer_name', $fortnoxPayload) && array_key_exists('fortnox_order', $fortnoxPayload), 'customer_name och fortnox_order ligger kvar');
bm_assert(($fortnoxPayload['fortnox_order'] ?? '') === '', 'fortnox_order skrivs inte över');
$fortnoxLines = $fortnoxPayload['inkop_lines'] ?? null;
bm_assert(is_array($fortnoxLines) && count($fortnoxLines) === 1, 'inköpsraden ligger kvar');
bm_assert(is_array($fortnoxLines) && ($fortnoxLines[0]['sku'] ?? '') === 'SIG' && ($fortnoxLines[0]['qty'] ?? 0) === 1, 'sku och antal ligger kvar');
bm_assert(is_array($fortnoxLines) && ($fortnoxLines[0]['title'] ?? '') === 'Panel', 'personnummer i artikeltexten tas bort, fick: ' . (is_array($fortnoxLines) ? (string) ($fortnoxLines[0]['title'] ?? '') : ''));
bm_assert(is_array($fortnoxLines) && !array_key_exists('personnummer', $fortnoxLines[0]) && !array_key_exists('orgnr', $fortnoxLines[0]), 'pnr-fält på raden följer inte med');
$fortnoxJson = (string) json_encode($fortnoxPayload, JSON_UNESCAPED_UNICODE);
bm_assert(!bm_pnr_pattern($fortnoxJson), 'payloaden innehåller inget personnummermönster');
bm_assert(bm_pnr_keys($fortnoxPayload) === [], 'payloaden har inga pnr-nycklar: ' . implode(', ', bm_pnr_keys($fortnoxPayload)));
bm_assert(!str_contains($fortnoxJson, 'OrganisationNumber') && !str_contains($fortnoxJson, 'Fakturagatan'), 'Fortnox organisationsnummer och fakturaadress följer inte med');

$foranPnr = $ready;
$foranPnr['personnummer'] = '19700101-0101';
$foranPayload = jeab_bokmalen_ordrar_webhook_payload('ordrar.foranmalan', $foranPnr, false);
bm_assert(is_array($foranPayload) && ($foranPayload['personnummer'] ?? '') === '19700101-0101', 'föranmälan skickar fortfarande personnummer');
bm_assert(!array_key_exists('leveransadress', $foranPayload ?? []), 'föranmälan får inte beställningens leveransfält');

unset($GLOBALS['_JEAB_FORTNOX_CUSTOMER_GET'], $GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);

$bestallSrc = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar.php');
$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/src/bokmalen_ordrar_webhook.php');
$bestallStart = strpos($bestallSrc, 'function jeab_ordrar_save_bestall');
$bestallEnd = strpos($bestallSrc, 'function jeab_ordrar_save_boka_bestall');
$bestallBody = $bestallStart !== false && $bestallEnd !== false ? substr($bestallSrc, $bestallStart, $bestallEnd - $bestallStart) : '';
bm_assert($bestallBody !== '' && str_contains($bestallBody, 'jeab_ordrar_bestall_qty_error'), 'automatisk beställ kontrollerar positivt antal');
bm_assert(!str_contains($bestallBody, '?? 1') && !str_contains($hookSrc, '?? 1'), 'varken beställ eller webhook defaultar antal till 1');
bm_assert(!str_contains($bestallBody, 'jeab_purchase_get') && !str_contains($bestallBody, 'jeab_inkop_list'), 'beställ läser inte en annan order eller mall');
bm_assert(!str_contains($hookSrc, 'jeab_purchase_get') && !str_contains($hookSrc, 'jeab_inkop_'), 'webhook kopierar inte antal från annan order eller mall');

bm_reset_capture();
jeab_bokmalen_ordrar_notify('ordrar.bestall', $bare, true);
$draft = bm_captured();
bm_assert(count($draft) === 1 && ($draft[0]['utkast'] ?? null) === true, 'beställ-utkast postas med utkast true');
bm_assert(($draft[0]['type'] ?? '') === 'ordrar.bestall', 'utkast behåller type ordrar.bestall');

bm_reset_capture();
jeab_bokmalen_ordrar_notify('ordrar.bestall', $ladd, false);
bm_assert(bm_captured() === [], 'laddbox ger ingen POST');

bm_reset_capture();
jeab_bokmalen_ordrar_notify('ordrar.foranmalan', $ready, false);
$foranPost = bm_captured();
bm_assert(count($foranPost) === 1 && ($foranPost[0]['type'] ?? '') === 'ordrar.foranmalan', 'komplett föranmälan postar ordrar.foranmalan');
bm_assert(($foranPost[0]['utkast'] ?? null) === false, 'skickad föranmälan har utkast false');

bm_clear_secret();
bm_reset_capture();
$before = bm_log();
jeab_bokmalen_ordrar_notify('ordrar.bestall', $bare, false);
bm_assert(bm_captured() === [], 'utan secret blir det ingen payload');
$missingLog = bm_log();
bm_assert(str_contains($missingLog, 'secret saknas'), 'saknad secret loggas i felloggen');
bm_assert(str_contains($missingLog, 'ordrar.bestall'), 'felloggen nämner typen');
bm_assert(!str_contains($missingLog, 'http://') && !str_contains($missingLog, 'https://'), 'felloggen innehåller ingen url');
bm_assert(strlen($missingLog) > strlen($before), 'saknad secret lägger en ny rad');

bm_secret('https://127.0.0.1:1/hooks/jeab-ordrar', $leakKey);
putenv('JEAB_BUG_WEBHOOK_CAPTURE');
unset($_ENV['JEAB_BUG_WEBHOOK_CAPTURE']);
bm_reset_capture();
jeab_bokmalen_ordrar_notify('ordrar.bestall', $batteri, false);
$failLog = bm_log();
bm_assert(str_contains($failLog, 'POST misslyckades (HTTP '), 'misslyckad POST loggas med HTTP-kod');
bm_assert(preg_match_all('/POST misslyckades \(HTTP \d+\)/', $failLog) === 1, 'misslyckad POST försöker en gång, ingen automatisk omsändning');
bm_assert(!str_contains($failLog, $leakKey), 'nyckeln syns inte i felloggen');
bm_assert(!str_contains($failLog, '127.0.0.1'), 'webhook-url syns inte i felloggen');
bm_assert(bm_captured() === [], 'avstängd capture sparar ingen payload vid nätverksfel');

putenv('JEAB_BUG_WEBHOOK_CAPTURE=1');
$_ENV['JEAB_BUG_WEBHOOK_CAPTURE'] = '1';
jeab_bokmalen_ordrar_notify_fail('ordrar.bestall', 'fel https://api2.cursor.sh/automations/webhook/secret Bearer ' . $leakKey);
$scrub = bm_log();
bm_assert(str_contains($scrub, '[url]'), 'url i feltext maskeras');
bm_assert(str_contains($scrub, 'Bearer [nyckel]'), 'bearer i feltext maskeras');
bm_assert(!str_contains($scrub, 'api2.cursor.sh'), 'api-host läcker inte till felloggen');
bm_assert(!str_contains($scrub, $leakKey), 'nyckel läcker inte via feltext');


echo "bokmalen ordrar webhook: ok\n";
