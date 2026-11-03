<?php

declare(strict_types=1);

/**
 * Slutfas: väntan på nätägaren när alla räknade steg är klara, eller bara fakturering kvar.
 * Mina på Ordrar träffar sparad personal, inte produktens crew.
 * Statuschippen räknas före uppdelningen, så «Alla» är huvudlistan plus Slutfas.
 */

$tmp = sys_get_temp_dir() . '/jeab-mina-slutfas-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';
jeab_ordrar_external_live(false);
date_default_timezone_set('Europe/Stockholm');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function ms_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function ms_row(array $extra = []): array
{
    return array_replace_recursive([
        'reminder_id' => 900,
        'company' => 'jarfalla',
        'kind' => 'batteri',
        'customer' => 'Lista Testsson',
        'address' => 'Testgatan 3, 175 00 Järfälla',
        'huvudsokande' => 'Lista Testsson',
        'personnummer' => '19800101-0000',
        'fastighetsbeteckning' => 'Testet 3:3',
        'pris' => 79000,
        'natbolag' => 'E.ON',
        'anlaggnings_id' => '735999123456789012',
        'kwh' => '10',
        'created_at' => '2026-09-01 10:00:00',
        'steps' => [],
    ], $extra);
}

$future = (new DateTimeImmutable('+20 days'))->format('Y-m-d');
$store = jeab_ordrar_resolve_store(null);
$bk = jeab_create_booking($store, 'Lista Testsson', ['Roberto'], $future . ' 08:00:00', $future . ' 16:00:00', 'batteri', ['company' => 'jarfalla'], false);
$bookingId = (int) ($bk['id'] ?? 0);
ms_assert($bookingId > 0, 'testbokning skapad');

$net = ms_row([
    'reminder_id' => 1,
    'address' => 'Slutfasgatan 1, 175 00 Järfälla',
    'fortnox_order' => '5006',
    'booking_id' => $bookingId,
    'foranmalan_status' => 'A',
    'foranmalan_case_status' => 'godkand',
    'fortnox_invoice' => '13999',
    'steps' => ['foranmald' => '2026-09-23', 'bestalld' => '2026-10-01', 'faktura_skapad' => '2026-10-05'],
]);
$netNext = jeab_ordrar_v3_next_action($net, $store);
$netProg = jeab_ordrar_v3_progress($net, $store);
ms_assert($netNext['text'] === 'Väntar på nätägaren', 'fakturerad, ej färdiganmäld: Väntar på nätägaren (fick ' . $netNext['text'] . ')');
ms_assert($netProg['total'] > 0 && $netProg['done'] === $netProg['total'], 'alla räknade steg klara (' . $netProg['done'] . '/' . $netProg['total'] . ')');
ms_assert(jeab_ordrar_is_slutfas($net, $store), 'väntan på nätägaren med klara steg är slutfas');

$invoice = ms_row([
    'reminder_id' => 2,
    'address' => 'Fakturagatan 2, 175 00 Järfälla',
    'fortnox_order' => '5001',
    'booking_id' => $bookingId,
    'foranmalan_status' => 'A',
    'foranmalan_case_status' => 'skickad',
    'steps' => ['bokad_tid' => $future . ' 08:00', 'foranmald' => '2026-09-23', 'bestalld' => '2026-10-07'],
    'aprilice_order' => '2026104801',
]);
$invNext = jeab_ordrar_v3_next_action($invoice, $store);
ms_assert($invNext['text'] === 'Skapa faktura' && $invNext['key'] === 'faktura', 'bokad och beställd: Skapa faktura');
ms_assert(jeab_ordrar_is_slutfas($invoice, $store), 'bara fakturering kvar är slutfas');

$laddInv = ms_row(['reminder_id' => 3, 'kind' => 'laddbox', 'fortnox_order' => '5007', 'address' => 'Laddgatan 3']);
$laddNext = jeab_ordrar_v3_next_action($laddInv);
ms_assert($laddNext['text'] === 'Skapa faktura', 'laddbox bekräftad: Skapa faktura');
ms_assert(jeab_ordrar_is_slutfas($laddInv), 'laddbox med bara faktura kvar är slutfas');

$boka = ms_row([
    'reminder_id' => 4,
    'address' => 'Atgardsgatan 4, 175 00 Järfälla',
    'fortnox_order' => '5001',
    'foranmalan_status' => 'A',
    'foranmalan_case_status' => 'skickad',
    'steps' => ['foranmald' => '2026-09-23', 'bestalld' => '2026-10-07'],
]);
ms_assert(jeab_ordrar_v3_next_action($boka)['text'] === 'Boka', 'nästa Boka');
ms_assert(!jeab_ordrar_is_slutfas($boka), 'Boka stannar i huvudlistan');

$foran = ms_row(['reminder_id' => 5, 'fortnox_order' => '5003', 'foranmalan_status' => 'A', 'steps' => ['bokad_tid' => $future]]);
ms_assert(jeab_ordrar_v3_next_action($foran)['text'] === 'Skicka föranmälan', 'nästa Skicka föranmälan');
ms_assert(!jeab_ordrar_is_slutfas($foran), 'föranmälan stannar i huvudlistan');

$close = ms_row([
    'reminder_id' => 6,
    'kind' => 'laddbox',
    'fortnox_order' => '5008',
    'fortnox_invoice' => '14000',
    'address' => 'Stanggatan 6',
]);
$closeNext = jeab_ordrar_v3_next_action($close);
ms_assert($closeNext['text'] === 'Stäng ärendet', 'laddbox fakturerad: Stäng ärendet (fick ' . $closeNext['text'] . ')');
ms_assert(!jeab_ordrar_is_slutfas($close), 'Stäng ärendet är inte slutfas');

$solkoll = ms_row([
    'reminder_id' => 7,
    'kind' => 'sol',
    'address' => 'Solgatan 7',
    'fortnox_order' => '5009',
    'booking_id' => $bookingId,
    'foranmalan_status' => 'A',
    'foranmalan_case_status' => 'fardiganmald',
    'fortnox_invoice' => '14001',
    'steps' => ['foranmald' => '2026-09-23', 'bestalld' => '2026-10-01', 'faktura_skapad' => '2026-10-05'],
]);
$solNext = jeab_ordrar_v3_next_action($solkoll, $store);
ms_assert($solNext['text'] === 'Väntar på Solkoll', 'sol utan dokumentation: Väntar på Solkoll (fick ' . $solNext['text'] . ')');
ms_assert(!jeab_ordrar_is_slutfas($solkoll, $store), 'Väntar på Solkoll stannar i huvudlistan');

$closed = ms_row([
    'reminder_id' => 8,
    'kind' => 'sol',
    'allt_klart' => true,
    'closed_at' => '2026-10-02 10:00:00',
    'fortnox_invoice' => '13462',
    'fortnox_order' => '4001',
    'foranmalan_status' => 'C',
    'foranmalan_case_status' => 'fardiganmald',
    'steps' => ['spec' => '2026-09-14', 'faktura_skapad' => '2026-09-28', 'bestalld' => '2026-09-14'],
]);
ms_assert(jeab_ordrar_allt_klart($closed) && !jeab_ordrar_is_slutfas($closed), 'stängt ärende är inte slutfas');

$loose = ms_row(['reminder_id' => 11, 'kind' => 'batteri', 'address' => 'Losgatan 11', 'assignee' => '', 'assignees' => [], 'person' => '']);
$owned = ms_row(['reminder_id' => 12, 'kind' => 'batteri', 'address' => 'Robgatan 12', 'assignee' => 'Roberto', 'assignees' => ['Roberto'], 'person' => 'Roberto']);
$ladd = ms_row(['reminder_id' => 13, 'kind' => 'laddbox', 'address' => 'Elingatan 13', 'assignee' => 'Elin', 'assignees' => ['Elin'], 'person' => 'Elin']);
ms_assert(count(jeab_ordrar_filter_list([$loose, $owned], null, null)) === 2, 'Alla visar även order utan ägare');
$mina = jeab_ordrar_filter_list([$loose, $owned, $ladd], 'Roberto', null);
ms_assert(count($mina) === 1 && (int) $mina[0]['reminder_id'] === 12, 'Mina träffar bara sparad personal');
ms_assert(jeab_order_matches_filter($loose, 'Roberto', null) && !jeab_ordrar_filter_list([$loose], 'Roberto', null), 'crew-filtret är oförändrat och används inte av Ordrar-listan');
$jobs = jeab_ordrar_filter_list([$owned, $ladd], null, 'laddbox');
ms_assert(count($jobs) === 1 && (int) $jobs[0]['reminder_id'] === 13, 'produktpiller gäller tillsammans med personal');

jeab_purchase_put([
    'reminder_id' => 42,
    'company' => 'jarfalla',
    'kind' => 'batteri',
    'address' => 'Agarvagen 42',
    'personnummer' => '19800101-0000',
    'huvudsokande' => 'Kund Kundsson',
    'owners' => [['name' => 'Kund Kundsson']],
]);
$saved = jeab_ordrar_set_staff_owner(42, 'Elin');
ms_assert(($saved['assignee'] ?? '') === 'Elin' && ($saved['person'] ?? '') === 'Elin' && ($saved['assignees'] ?? []) === ['Elin'], 'Tillhör sparar Elin');
ms_assert(($saved['huvudsokande'] ?? '') === 'Kund Kundsson' && ($saved['owners'][0]['name'] ?? '') === 'Kund Kundsson' && ($saved['personnummer'] ?? '') === '19800101-0000', 'fastighetsägare och personnummer rörs inte');
$threw = false;
try {
    jeab_ordrar_set_staff_owner(42, 'Okänd');
} catch (InvalidArgumentException) {
    $threw = true;
}
$multi = false;
try {
    jeab_ordrar_set_staff_owner(42, '__multi__');
} catch (InvalidArgumentException) {
    $multi = true;
}
ms_assert($multi, 'Flera går inte att spara');
ms_assert($threw && (jeab_purchase_get(42)['assignee'] ?? '') === 'Elin', 'okänd person sparas inte');
$cleared = jeab_ordrar_set_staff_owner(42, '');
ms_assert(($cleared['assignee'] ?? 'x') === '' && ($cleared['assignees'] ?? ['x']) === [] && ($cleared['person'] ?? 'x') === '', 'Ingen tar bort tillhör');
ms_assert(jeab_ordrar_filter_list([$cleared], 'Elin', null) === [], 'utan ägare syns ordern inte under Mina');

$cleared['allt_klart'] = true;
$cleared['closed_at'] = '2026-10-02 10:00:00';
jeab_purchase_put($cleared);
jeab_purchase_put($net);
jeab_purchase_put($boka);
$_GET = ['p' => 'ordrar', 'co' => 'jarfalla', 'who' => 'all', 'job' => 'all', 'ws' => (string) time()];
$_SERVER['REQUEST_METHOD'] = 'GET';
$open = null;
$company = 'jarfalla';
$flash = null;
$error = null;
$rows = [];
$html = require dirname(__DIR__) . '/src/pages/ordrar.php';
ms_assert(is_string($html), 'ordersidan renderas');
$cut = strpos($html, 'data-js="ordrar-slutfas"');
ms_assert($cut !== false, 'sidan har sektionen Slutfas');
$main = substr($html, 0, (int) $cut);
$tail = substr($html, (int) $cut);
ms_assert(str_contains($main, 'Atgardsgatan 4') && !str_contains($main, 'Slutfasgatan 1'), 'huvudlistan har bara det som behöver åtgärd');
ms_assert(str_contains($tail, 'Slutfas') && str_contains($tail, 'Slutfasgatan 1') && str_contains($tail, 'data-js="ordrar-slutfas-count">1'), 'Slutfas visar väntan och antal');
ms_assert(str_contains($html, 'data-scope="mina"') && str_contains($html, 'data-scope="alla"'), 'Mina och Alla finns på sidan');
ms_assert(preg_match('#data-filter=""[^>]*>Alla <em>2</em>#', $html) === 1, 'statuschippet Alla räknar huvudlistan och Slutfas');

echo "ordrar_mina_slutfas_test: OK\n";
