<?php

declare(strict_types=1);

/**
 * Status-steget visar objekt, datum/order och läge från befintliga fält,
 * och listan öppnas på status när inget stop är valt.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

jeab_ordrar_external_live(false);

function st_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$row = [
    'kind' => 'sol',
    'company' => 'jarfalla',
    'name' => 'Guy Nilsson',
    'huvudsokande' => 'Guy Nilsson',
    'personnummer' => '198001011234',
    'fastighetsbeteckning' => 'GRÖDINGE 1:1',
    'address' => 'Sibblevägen 8, 147 92 Grödinge',
    'natbolag' => 'Ellevio',
    'phone' => '+46701234567',
    'email' => 'guy@example.com',
    'booking_id' => 0,
    'steps' => ['bokad_tid' => '2026-09-14'],
    'aprilice_order' => '2026102314',
    'lightleap_order' => 'KO322852',
    'fortnox_order' => '2489173',
    'solkoll_status' => 'slutford',
    'fullmakt_token' => 'tok-status',
    'fullmakt_mailed' => true,
    'foranmalan_case_status' => 'godkand',
];

$html = jeab_ordrar_status_html($row, 'jarfalla', 42, null);
foreach ([
    'Objekt',
    'Sibblevägen 8',
    'value="147 92"',
    'value="Grödinge"',
    'name="objekt_postnummer"',
    'Ellevio',
    'Mobil',
    'E-post',
    'Nätägare',
    '+46701234567',
    'guy@example.com',
    'name="objekt_adress"',
    'name="objekt_postort"',
    'name="phone"',
    'name="email"',
    'name="natbolag"',
    'ordrar.objekt',
    'Spara',
    'Datum &amp; order',
    'Bokat installationsdatum',
    '14 sep 2026',
    'Önskat leveransdatum',
    '8 sep 2026',
    'Aprilice',
    '2026102314',
    'Lightleap',
    'KO322852',
    'Fortnox',
    '2489173',
    'DOKUMENTATION (Solkoll)',
    'Slutförd',
    'Fullmakt',
    'Skickad',
    'Föranmälan',
    'Godkänd',
    'Ingen kommentar',
    'Fakturering',
    'Order skriven',
    'Solkoll',
    'is-ok',
    'is-brand',
    'is-warn',
    'Beställning',
    'Beställning klar',
    'data-bestallning="klar"',
    'Leveransdatum',
    '8 sep 2026',
] as $needle) {
    st_assert(str_contains($html, $needle), 'statuskort innehåller ' . $needle);
}
st_assert(!str_contains($html, 'Radera ärendet'), 'radera sitter inte i statuskortet');
st_assert(!str_contains($html, 'ordrar-status-solkoll') && !str_contains($html, 'ordrar-solkoll'), 'fristående Solkoll-rad är borta');
st_assert(str_contains($html, 'is-as-written'), 'dokumentationsrubriken behåller Solkoll-versaler');
st_assert(str_contains($html, 'Allt är fakturerat och klart, stäng ärendet'), 'status har knappen som stänger ärendet');
st_assert(str_contains($html, 'value="ordrar.stang"'), 'stäng-knappen postar ordrar.stang');

$bare = [
    'kind' => 'sol',
    'address' => 'Storgatan 1',
    'natbolag' => 'E.ON',
    'fortnox_order' => '',
];
$bareHtml = jeab_ordrar_status_html($bare, 'jarfalla', 7, null);
st_assert(str_contains($bareHtml, 'name="phone"'), 'tomt statuskort har mobilfält');
st_assert(str_contains($bareHtml, 'name="email"'), 'tomt statuskort har e-postfält');
st_assert(str_contains($bareHtml, 'name="natbolag"'), 'nätägare som finns går att ändra');
st_assert(!str_contains($bareHtml, 'Aprilice'), 'saknat Aprilice-id visas inte');
st_assert(!str_contains($bareHtml, 'Lightleap'), 'saknat Lightleap-id visas inte');
st_assert(!str_contains($bareHtml, '>Fortnox<'), 'saknat Fortnox-id visas inte');
st_assert(str_contains($bareHtml, 'Ingen kommentar'), 'föranmälan utan kommentar säger Ingen kommentar');
st_assert(!str_contains($bareHtml, 'Radera ärendet'), 'tomt statuskort har ingen radera');
st_assert(str_contains($bareHtml, 'Allt är fakturerat och klart, stäng ärendet'), 'tomt statuskort har också stäng-knappen');
st_assert(str_contains($bareHtml, 'Väntar på orderrader'), 'sol utan rader väntar på orderrader');
st_assert(str_contains($bareHtml, 'data-bestallning="rader"'), 'beställningsläget är rader');
st_assert(!str_contains($bareHtml, 'Beställning klar'), 'tom solorder är inte beställning klar');

$waiting = $bare;
$waiting['orderrader_saved'] = true;
$waiting['inkop_lines'] = [['sku' => 'P1', 'title' => 'Panel', 'qty' => 1]];
$waitingHtml = jeab_ordrar_status_html($waiting, 'jarfalla', 7, null);
st_assert(str_contains($waitingHtml, 'Väntar på Bokmalen'), 'sparade orderrader väntar på Bokmalen');
st_assert(str_contains($waitingHtml, 'data-bestallning="bokmalen"'), 'beställningsläget är bokmalen');
st_assert(!str_contains($waitingHtml, 'Leveransdatum'), 'väntan visar inte leveransdatum');

$sent = $waiting;
$sent['steps'] = ['bestalld' => '2026-09-01'];
$sentHtml = jeab_ordrar_status_html($sent, 'jarfalla', 7, null);
st_assert(str_contains($sentHtml, 'Väntar på Bokmalen'), 'skickad beställning utan nummer väntar på Bokmalen');

$other = $sent;
$other['shop'] = 'lightleap';
$otherHtml = jeab_ordrar_status_html($other, 'jarfalla', 7, null);
st_assert(str_contains($otherHtml, 'Beställning klar'), 'Lightleap-rad efter beställning är klar');
st_assert(str_contains($otherHtml, 'Något är beställt från Lightleap'), 'Lightleap utan nummer nämns');
st_assert(str_contains($otherHtml, '>Aprilice</span>'), 'klar utan Aprilice-nummer visar fältet');

$manual = $bare;
$manual['bestallning_status'] = 'C';
$manual['steps'] = ['bestalld' => '2026-09-02'];
$manual['leverans'] = '2026-09-20';
$manualHtml = jeab_ordrar_status_html($manual, 'jarfalla', 7, null);
st_assert(str_contains($manualHtml, 'Beställning klar'), 'manuell beställning är klar');
st_assert(str_contains($manualHtml, '20 sep 2026'), 'manuell beställning visar leveransdatum');
st_assert(str_contains($manualHtml, '>Beställd</span>'), 'manuell beställning visar när den gjordes');
st_assert(str_contains($manualHtml, '2 sep 2026'), 'manuell beställning visar beställningsdagen');
st_assert(!str_contains($manualHtml, 'Lightleap'), 'manuell beställning utan Lightleap nämner inte distributören');

$placed = $waiting;
$placed['aprilice_order'] = '2026104078';
$placed['leverans'] = '2026-10-06';
$placed['bestalld_at'] = '2026-09-29 13:00:00';
$placed['steps'] = ['bestalld' => '2026-09-29'];
$placedHtml = jeab_ordrar_status_html($placed, 'jarfalla', 7, null);
st_assert(str_contains($placedHtml, 'Beställning klar'), 'lagd Aprilice-order är klar');
st_assert(str_contains($placedHtml, '2026104078'), 'lagd Aprilice-order visar ordernumret');
st_assert(str_contains($placedHtml, '6 okt 2026'), 'lagd Aprilice-order visar leveransdatum');
st_assert(str_contains($placedHtml, '29 sep 2026 13:00'), 'lagd Aprilice-order visar när beställningen gjordes');
st_assert(!str_contains($placedHtml, 'Väntar på Bokmalen'), 'lagd Aprilice-order väntar inte på Bokmalen');

$box = ['kind' => 'laddbox', 'address' => 'Storgatan 1', 'natbolag' => 'E.ON'];
$boxHtml = jeab_ordrar_status_html($box, 'jarfalla', 8, null);
st_assert(!str_contains($boxHtml, 'data-bestallning'), 'laddbox har ingen beställningssektion');
st_assert(!str_contains($boxHtml, 'Väntar på orderrader'), 'laddbox väntar inte på orderrader');
$page = (string) file_get_contents(dirname(__DIR__) . '/src/pages/ordrar.php');
$activityAt = strpos($page, 'jeab_ordrar_activity_html');
$deleteAt = strpos($page, 'ordrar-after-log');
st_assert($activityAt !== false && $deleteAt !== false && $activityAt < $deleteAt, 'radera ligger under aktivitet');

$_GET = [];
st_assert(
    jeab_ordrar_view_stop($row, null) === 'status',
    'order utan stop öppnas på status'
);
$_GET = ['stop' => 'kop'];
st_assert(jeab_ordrar_view_stop($row, null) === 'bekrafta', 'borttaget steg kop öppnas på bekräfta');
$_GET = ['stop' => 'status'];
st_assert(jeab_ordrar_view_stop($row, null) === 'status', 'stop=status öppnar status');
$_GET = [];
st_assert(
    jeab_ordrar_view_stop(['kind' => 'sol', 'fortnox_order' => ''], null) === 'bekrafta',
    'saknad identitet utan Fortnox-order går fortfarande till bekräfta'
);
$_GET = ['stop' => 'eon'];
st_assert(
    jeab_ordrar_view_stop(['kind' => 'sol', 'fortnox_order' => '1', 'steps' => []], null) === 'eon',
    'föranmälan går att öppna utan att vara ett numrerat steg'
);
$_GET = [];

$flow = jeab_ordrar_flow(['kind' => 'laddbox', 'stop3_saved' => true, 'fortnox_order' => 'FX']);
$flowKeys = array_map(static fn (array $step): string => (string) ($step['key'] ?? ''), $flow);
st_assert($flowKeys === ['bekrafta', 'fakturering'], 'laddbox visar Bekräfta och Fakturering, fick: ' . implode(',', $flowKeys));
st_assert(!in_array('paminelse', $flowKeys, true) && !in_array('boka_elin', $flowKeys, true), 'påminnelse och boka elin är borta');

echo "ordrar status layout: ok\n";
