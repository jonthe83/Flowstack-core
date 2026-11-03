<?php

declare(strict_types=1);

/**
 * Auto-Allt klart kräver riktig faktura, färdiganmälan (utom Manuell/ingen)
 * och för solceller Slutförd eller 4/4. Synkad utan faktura stannar aktiv.
 * Manuell stängning (ordrar.stang / closed_at) gäller utan övriga krav.
 * Ordrar som bara låg i Allt klart på den gamla synk-regeln flyttas tillbaka.
 */

$tmp = sys_get_temp_dir() . '/jeab-allt-klart-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;

require dirname(__DIR__) . '/src/bootstrap.php';

function fm_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function fm_ids(array $rows): array
{
    $ids = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $ids[] = (int) ($row['reminder_id'] ?? 0);
        }
    }
    sort($ids);
    return $ids;
}

jeab_ordrar_external_live(false);

$synced = [
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Daryoush Mansouri',
    'huvudsokande' => 'Daryoush Mansouri',
    'address' => 'Råbäckskroken 27, Upplands Väsby',
    'stop3_saved' => true,
    'fortnox_order' => 'FX-6',
    'steps' => [
        'bekraftad' => '2026-09-01 10:00:00',
        'order_skriven' => '2026-09-20 12:00:00',
    ],
    'reminder_created_at' => '2026-08-01 09:00:00',
];
$view = jeab_ordrar_process_view($synced);
fm_assert(
    (int) ($view['idx'] ?? 0) === 2
        && (int) ($view['total'] ?? 0) === 2
        && ($view['label'] ?? '') === 'Fakturering'
        && ($view['hint'] ?? '') === 'synkad',
    'synkad elbilsladdare visas som Fakturering 2 av 2 synkad, fick '
        . (int) ($view['idx'] ?? 0) . '/' . (int) ($view['total'] ?? 0)
        . ' ' . (string) ($view['label'] ?? '') . ' ' . (string) ($view['hint'] ?? '')
);
fm_assert(jeab_ordrar_has_invoice($synced) === false, 'synkad order har ingen faktura');
fm_assert(jeab_ordrar_auto_allt_klart($synced) === false, 'synkad utan faktura är inte auto-Allt klart');
fm_assert(jeab_ordrar_allt_klart($synced) === false, 'Fortnox synkad utan faktura är inte Allt klart');

$open = [
    'kind' => 'laddbox',
    'stop3_saved' => true,
    'fortnox_order' => '',
    'steps' => [],
];
$openView = jeab_ordrar_process_view($open);
fm_assert(($openView['hint'] ?? '') !== 'synkad', 'utan Fortnox-order är den inte synkad');
fm_assert(jeab_ordrar_allt_klart($open) === false, 'elbilsladdare utan Fortnox-order stannar aktiv');

$half = [
    'kind' => 'laddbox',
    'stop3_saved' => false,
    'fortnox_order' => 'FX-HALF',
    'steps' => [],
];
fm_assert(jeab_ordrar_allt_klart($half) === false, 'osparad laddbox med ordernummer är inte klar');
fm_assert(jeab_ordrar_needs_reply($half) === true, 'osparad laddbox behöver fortfarande svar');

$invoiced = [
    'kind' => 'laddbox',
    'stop3_saved' => false,
    'fortnox_invoice' => 'F-1',
];
fm_assert(jeab_ordrar_has_invoice($invoiced) === true, 'fakturanummer räknas som riktig faktura');
fm_assert(jeab_ordrar_auto_allt_klart($invoiced) === false, 'faktura utan färdiganmälan är inte auto-Allt klart');
fm_assert(jeab_ordrar_allt_klart($invoiced) === false, 'faktura ensam räcker inte som Allt klart');

$invoicedDone = $invoiced;
$invoicedDone['foranmalan_case_status'] = 'fardiganmald';
fm_assert(jeab_ordrar_fardiganmalan_klar($invoicedDone) === true, 'Färdiganmäld föranmälan är klar');
fm_assert(jeab_ordrar_auto_allt_klart($invoicedDone) === true, 'faktura och färdiganmälan är auto-Allt klart');
fm_assert(jeab_ordrar_allt_klart($invoicedDone) === true, 'faktura och färdiganmälan ligger i Allt klart');
fm_assert(jeab_ordrar_needs_reply($invoicedDone) === false, 'auto-Allt klart lämnar Behöver svar');

$noInvoice = $invoicedDone;
unset($noInvoice['fortnox_invoice']);
fm_assert(jeab_ordrar_auto_allt_klart($noInvoice) === false, 'auto-Allt klart kräver faktura');
fm_assert(jeab_ordrar_allt_klart($noInvoice) === false, 'färdiganmälan utan faktura stannar aktiv');

$stepOnly = [
    'kind' => 'batteri',
    'fortnox_order' => 'FX-STEP',
    'steps' => ['faktura_skapad' => '2026-09-20'],
    'foranmalan_case_status' => 'fardiganmald',
];
fm_assert(jeab_ordrar_has_invoice($stepOnly) === true, 'stämplad faktura räknas som faktura');
fm_assert(jeab_ordrar_auto_allt_klart($stepOnly) === true, 'faktura-stämpel plus färdiganmälan är auto-Allt klart');

$manual = [
    'kind' => 'batteri',
    'fortnox_invoice' => 'F-MAN',
    'foranmalan_status' => 'C',
];
fm_assert(jeab_ordrar_foranmalan_manuell($manual) === true, 'föranmälan C är Manuell/ingen');
fm_assert(jeab_ordrar_fardiganmalan_klar($manual) === true, 'Manuell föranmälan hoppar över färdiganmälan');
fm_assert(jeab_ordrar_foranmalan_case_status($manual) === '', 'Manuell föranmälan har ingen färdiganmäld status');
fm_assert(jeab_ordrar_auto_allt_klart($manual) === true, 'Manuell föranmälan med faktura är auto-Allt klart');

$bestallManual = [
    'kind' => 'batteri',
    'fortnox_invoice' => 'F-BEST',
    'bestallning_status' => 'C',
];
fm_assert(jeab_ordrar_foranmalan_manuell($bestallManual) === true, 'beställning C är Manuell/ingen');
fm_assert(jeab_ordrar_auto_allt_klart($bestallManual) === true, 'Manuell beställning hoppar över färdiganmälan');

$manualOpen = $manual;
unset($manualOpen['fortnox_invoice']);
$manualOpen['fortnox_order'] = 'FX-MAN';
fm_assert(jeab_ordrar_auto_allt_klart($manualOpen) === false, 'Manuell föranmälan utan faktura är inte klar');

$solBase = [
    'kind' => 'sol',
    'name' => 'Ofärdig sol',
    'fortnox_invoice' => 'F-SOL',
    'foranmalan_case_status' => 'fardiganmald',
    'solkoll_status' => 'paborjad',
    'solkoll_todos_done' => 2,
    'solkoll_todos_total' => 4,
];
fm_assert(jeab_ordrar_solkoll_dokumentation_klar($solBase) === false, 'Påbörjad 2/4 är inte Slutförd eller 4/4');
fm_assert(jeab_ordrar_auto_allt_klart($solBase) === false, 'sol med ofärdig Solkoll stannar aktiv');
fm_assert(jeab_ordrar_allt_klart($solBase) === false, 'ofärdig sol är inte Allt klart trots faktura');

$solSlut = $solBase;
$solSlut['solkoll_status'] = 'slutford';
$solSlut['solkoll_todos_done'] = 0;
$solSlut['solkoll_todos_total'] = 0;
$solDoc = jeab_solkoll_status_for_row($solSlut);
fm_assert(($solDoc['key'] ?? '') === 'slutford' && ($solDoc['label'] ?? '') === 'Slutförd', 'Status-kortet visar Slutförd');
fm_assert(jeab_ordrar_solkoll_dokumentation_klar($solSlut) === true, 'Slutförd räcker för sol');
fm_assert(jeab_ordrar_auto_allt_klart($solSlut) === true, 'sol med faktura, färdiganmälan och Slutförd är auto-Allt klart');

$solFour = $solBase;
$solFour['solkoll_todos_done'] = 4;
$solFour['solkoll_todos_total'] = 4;
$fourDoc = jeab_solkoll_status_for_row($solFour);
fm_assert(($fourDoc['label'] ?? '') === 'Påbörjad 4/4', 'Status-kortet visar steget 4/4, fick: ' . (string) ($fourDoc['label'] ?? ''));
fm_assert(jeab_ordrar_solkoll_dokumentation_klar($solFour) === true, '4/4 räcker för sol');
fm_assert(jeab_ordrar_auto_allt_klart($solFour) === true, 'sol med 4/4 är auto-Allt klart');

$solManual = [
    'kind' => 'sol',
    'fortnox_invoice' => 'F-SOL-M',
    'foranmalan_status' => 'C',
    'solkoll_status' => 'slutford',
];
fm_assert(jeab_ordrar_foranmalan_case_status($solManual) === '', 'manuell sol har ingen färdiganmälan');
fm_assert(jeab_ordrar_auto_allt_klart($solManual) === true, 'manuell sol med faktura och Slutförd hoppar över färdiganmälan');
$solManual['solkoll_status'] = 'paborjad';
$solManual['solkoll_todos_done'] = 3;
$solManual['solkoll_todos_total'] = 4;
fm_assert(jeab_ordrar_auto_allt_klart($solManual) === false, 'manuell sol utan Slutförd/4/4 stannar aktiv');

$solOrder = ['kind' => 'sol', 'name' => 'Ofärdig sol', 'fortnox_order' => 'FX-SOL'];
fm_assert(jeab_ordrar_allt_klart($solOrder) === false, 'ofärdig sol stannar aktiv även med Fortnox-order');
fm_assert(jeab_ordrar_needs_reply($solOrder) === true, 'sol utan följdfrågor behöver fortfarande svar');

$stangd = $solOrder;
$stangd['closed_at'] = '2026-09-27 12:00:00';
fm_assert(jeab_ordrar_auto_allt_klart($stangd) === false, 'manuell stängning uppfyller inte auto-reglerna');
fm_assert(jeab_ordrar_manuellt_stangt($stangd) === true, 'closed_at från stäng räknas som manuellt stängd');
fm_assert(jeab_ordrar_allt_klart($stangd) === true, 'manuellt stängd ofärdig sol är Allt klart');
fm_assert(jeab_ordrar_needs_reply($stangd) === false, 'manuellt stängd sol lämnar Behöver svar');
fm_assert(jeab_ordrar_manuellt_stangt(['closed_at' => '2026-09-27 12:00:00']) === true, 'closed_at räcker som manuellt stängd');
fm_assert(jeab_ordrar_manuellt_stangt(['allt_klart' => true]) === true, 'allt_klart-flaggan från knappen räcker');
fm_assert(jeab_ordrar_allt_klart(['kind' => 'batteri', 'fortnox_order' => 'FX', 'allt_klart' => true]) === true, 'manuell stängning utan faktura är Allt klart');
fm_assert(jeab_ordrar_manuellt_stangt($solOrder) === false, 'ofärdig sol utan flagga är inte manuellt stängd');

$helin = [
    'reminder_id' => 15,
    'kind' => 'batteri',
    'company' => 'jarfalla',
    'name' => 'Helin',
    'huvudsokande' => 'Helin',
    'address' => 'Lillängsgatan 15',
    'huvudsokande_address' => 'Lillängsgatan 15',
    'stop3_saved' => true,
    'fortnox_order' => 'FX-HELIN',
    'steps' => [
        'bekraftad' => '2026-09-01 10:00:00',
        'order_skriven' => '2026-09-20 12:00:00',
    ],
    'created_at' => '2026-09-01 09:00:00',
];
$helinView = jeab_ordrar_process_view($helin);
fm_assert(
    ($helinView['key'] ?? '') === 'spec'
        && ($helinView['label'] ?? '') === 'Föranmälan'
        && (int) ($helinView['total'] ?? 0) === 6,
    'Helin med bara Fortnox ligger på föranmälan i solflödet, fick ' . (string) ($helinView['key'] ?? '') . ' ' . (int) ($helinView['idx'] ?? 0) . '/' . (int) ($helinView['total'] ?? 0)
);
fm_assert(($helin['fortnox_order'] ?? '') === 'FX-HELIN', 'Helins Fortnox-order ligger kvar');
fm_assert(jeab_ordrar_has_invoice($helin) === false, 'Helin har ingen riktig faktura');
fm_assert(jeab_ordrar_allt_klart($helin) === false, 'Helin får inte ligga i Allt klart');
fm_assert(jeab_ordrar_needs_reply($helin) === true, 'Helin behöver fortfarande föranmälan');

$klarAuto = [
    'reminder_id' => 16,
    'kind' => 'batteri',
    'company' => 'jarfalla',
    'name' => 'Klar Kund',
    'huvudsokande' => 'Klar Kund',
    'address' => 'Klara gatan 1',
    'fortnox_invoice' => 'F-KLAR',
    'foranmalan_case_status' => 'fardiganmald',
    'created_at' => '2026-09-02 09:00:00',
];
$stangdLista = [
    'reminder_id' => 17,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Stängd Kund',
    'huvudsokande' => 'Stängd Kund',
    'address' => 'Stängda vägen 2',
    'fortnox_order' => 'FX-STANG',
    'closed_at' => '2026-09-27 12:00:00',
    'allt_klart' => true,
    'created_at' => '2026-09-03 09:00:00',
];
fm_assert(jeab_ordrar_auto_allt_klart($stangdLista) === false, 'manuellt stängd listaorder uppfyller inte auto-reglerna');
fm_assert(jeab_ordrar_allt_klart($stangdLista) === true, 'manuellt stängd listaorder stannar i Allt klart');

jeab_purchase_save_all([
    '15' => $helin,
    '16' => $klarAuto,
    '17' => $stangdLista,
]);
$active = fm_ids(jeab_ordrar_visible('jarfalla', false));
$klart = fm_ids(jeab_ordrar_visible('jarfalla', true));
fm_assert($active === [15], 'migration flyttar synkad utan faktura till aktiv lista, fick ' . implode(',', $active));
fm_assert($klart === [16, 17], 'Allt klart behåller auto-klar och manuellt stängd, fick ' . implode(',', $klart));

echo "ordrar allt klart: ok\n";
