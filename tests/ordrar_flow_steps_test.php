<?php

declare(strict_types=1);

/**
 * Sidosteg per produkt, borttagna steg, legacy-mappning och Fakturering-rader.
 */

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox.php';

function fl_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function fl_keys(array $row): array
{
    $keys = [];
    foreach (jeab_ordrar_flow($row) as $step) {
        $keys[] = (string) ($step['key'] ?? '');
    }
    return $keys;
}

$removed = ['paminelse', 'kop', 'boka_elin', 'eon', 'fortnox', 'fortnox_l', 'fakturera', 'fardiganmal', 'boka_bestall'];

$empty = ['kind' => '', 'booking_id' => 0, 'steps' => []];
fl_assert(fl_keys($empty) === ['bekrafta'], 'före bekräfta syns bara Bekräfta, fick: ' . implode(',', fl_keys($empty)));

$solar = [
    'kind' => 'sol',
    'booking_id' => 0,
    'steps' => ['bekraftad' => '2026-09-01'],
];
fl_assert(
    fl_keys($solar) === ['bekrafta', 'spec', 'orderrader', 'boka', 'bestall', 'fakturering'],
    'sol efter bekräfta, fick: ' . implode(',', fl_keys($solar))
);
fl_assert(!in_array('vad', fl_keys($solar), true), 'vad/pris är inte ett sidosteg');
$confirmPage = (string) file_get_contents(dirname(__DIR__) . '/src/pages/ordrar.php');
fl_assert(str_contains($confirmPage, 'class="ordrar-confirm-vad"'), 'vad/pris ligger kvar under bekräfta');
fl_assert(str_contains($confirmPage, 'Går vidare till föranmälan.'), 'nästa från bekräfta går till föranmälan');
fl_assert(!str_contains($confirmPage, 'ordrar-confirm-vad" hidden') && !str_contains($confirmPage, "vad.hidden"), 'fälten döljs inte för sol och batteri');
fl_assert(jeab_ordrar_legacy_stop($solar, 'vad') === 'bekrafta', 'gammalt vad-steg öppnas på bekräfta');
$battery = $solar;
$battery['kind'] = 'batteri';
fl_assert(fl_keys($battery) === fl_keys($solar), 'batteri har samma steg som sol, fick: ' . implode(',', fl_keys($battery)));
fl_assert(!in_array('solkoll', fl_keys($battery), true) && !in_array('dokumentation', fl_keys($battery), true), 'batteri har inget Solkoll-steg');

$laddbox = ['kind' => 'laddbox', 'booking_id' => 0, 'steps' => []];
fl_assert(fl_keys($laddbox) === ['bekrafta', 'fakturering'], 'laddbox, fick: ' . implode(',', fl_keys($laddbox)));

foreach ([$empty, $solar, $laddbox] as $row) {
    foreach ($removed as $key) {
        fl_assert(!in_array($key, fl_keys($row), true), $key . ' är inte ett numrerat steg');
    }
}

fl_assert(in_array('eon', jeab_ordrar_form_stops($solar), true), 'föranmälan går att öppna utan stegnummer');
fl_assert(!in_array('fakturering', jeab_ordrar_form_stops($laddbox), true), 'laddbox fakturering väntar på bokning');
fl_assert(!in_array('fakturering', jeab_ordrar_form_stops($battery), true), 'batteri fakturering väntar på föranmälan');
fl_assert(jeab_ordrar_default_stop($battery) === 'spec', 'ny batteri öppnas på föranmälan');
fl_assert(jeab_ordrar_default_stop($battery) === jeab_ordrar_default_stop($solar), 'batteri och sol öppnas på samma steg');
$batteryFortnox = $battery;
$batteryFortnox['fortnox_order'] = '1877';
$batteryFortnox['steps']['order_skriven'] = '2026-09-20';
fl_assert(($batteryFortnox['fortnox_order'] ?? '') === '1877', 'Fortnox-numret ligger kvar');
fl_assert(fl_keys($batteryFortnox) === fl_keys($solar), 'batteri med Fortnox behåller solstegen');
fl_assert(jeab_ordrar_default_stop($batteryFortnox) === 'spec', 'batteri som bara hunnit till Fakturering öppnas på föranmälan');
fl_assert(in_array('fakturering', jeab_ordrar_form_stops($batteryFortnox), true), 'Fortnox-steget går fortfarande att öppna');
fl_assert(jeab_ordrar_fakturering_done($batteryFortnox) === false, 'Fortnox-order räknas inte som klar fakturering på batteri');
fl_assert(jeab_ordrar_solkoll_dokumentation_klar($batteryFortnox + ['solkoll_status' => 'paborjad', 'solkoll_todos_done' => 1, 'solkoll_todos_total' => 4]) === true, 'Solkoll krävs inte på batteri');
$bookedBox = $laddbox;
$bookedBox['fortnox_order'] = '2489173';
$bookedBox['steps']['order_skriven'] = '2026-09-20';
fl_assert(jeab_ordrar_legacy_stop($bookedBox, 'boka_elin') === 'fakturering', 'laddbox med skriven order från boka elin landar på fakturering');
fl_assert(jeab_ordrar_legacy_stop($laddbox, 'boka_elin') === 'bekrafta', 'obokad laddbox från boka elin landar på bekräfta');
fl_assert(jeab_ordrar_legacy_stop($solar, 'paminelse') === 'bekrafta', 'påminnelse mappas till bekräfta');
fl_assert(jeab_ordrar_legacy_stop($solar, 'kop') === 'bekrafta', 'köp mappas till bekräfta');
fl_assert(jeab_ordrar_legacy_stop($solar, 'bestallning') === 'orderrader', 'beställning mappas till orderrader');
fl_assert(jeab_ordrar_legacy_stop($solar, 'fakturera') === 'fakturering', 'fakturera mappas till fakturering');
fl_assert(jeab_ordrar_legacy_stop($solar, 'fortnox') === 'fakturering', 'fortnox-order mappas till fakturering');
fl_assert(jeab_ordrar_legacy_stop($solar, 'boka_bestall') === 'boka', 'obokad boka/beställ öppnas på boka');

$solarDone = $solar;
$solarDone['orderrader_saved'] = true;
$solarDone['steps']['vad'] = '2026-09-02';
$solarDone['steps']['spec'] = '2026-09-03';
$solarDone['steps']['orderrader'] = '2026-09-04';
$solarDone['steps']['bestalld'] = '2026-09-05';
fl_assert(jeab_ordrar_legacy_stop($solarDone, 'boka_bestall') === 'boka', 'utan kalenderbokning öppnas boka/beställ på boka');
fl_assert(jeab_ordrar_default_stop($solar) === 'spec', 'ny sol öppnas på följdfrågor');
fl_assert(jeab_ordrar_default_stop($solarDone) === 'boka', 'beställd men obokad sol öppnas på boka');
$linesSaved = $solar;
$linesSaved['orderrader_saved'] = true;
$linesSaved['steps']['spec'] = '2026-09-03';
$linesSaved['steps']['orderrader'] = '2026-09-04';
fl_assert(jeab_ordrar_default_stop($linesSaved) === 'bestall', 'sparade orderrader utan beställning öppnas på beställ');
fl_assert(jeab_ordrar_solar_next_stop($linesSaved, 'orderrader') === 'bestall', 'nästa efter orderrader är beställ, inte boka');
$ordrarSrc = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar.php');
$slutforStart = strpos($ordrarSrc, 'function jeab_ordrar_slutfor');
$slutforEnd = strpos($ordrarSrc, 'function jeab_ordrar_resolve_store');
$slutforBody = $slutforStart !== false && $slutforEnd !== false ? substr($ordrarSrc, $slutforStart, $slutforEnd - $slutforStart) : '';
fl_assert(str_contains($slutforBody, 'jeab_ordrar_create_booking') && str_contains($slutforBody, 'jeab_ordrar_fortnox_on_next'), 'slutför bokar och skriver Fortnox med befintliga funktioner');
$bookPos = strpos($slutforBody, 'jeab_ordrar_create_booking');
$fxPos = strpos($slutforBody, 'jeab_ordrar_fortnox_on_next');
fl_assert($bookPos !== false && $fxPos !== false && $bookPos < $fxPos, 'slutför bokar innan Fortnox-skrivning');
$bestallStart = strpos($ordrarSrc, 'function jeab_ordrar_save_bestall(');
$bestallEnd = strpos($ordrarSrc, 'function jeab_ordrar_save_boka_bestall');
$bestallBody = $bestallStart !== false && $bestallEnd !== false ? substr($ordrarSrc, $bestallStart, $bestallEnd - $bestallStart) : '';
fl_assert(str_contains($bestallBody, 'jeab_ordrar_create_booking'), 'lagd order anropar befintlig bokning');

$page = (string) file_get_contents(dirname(__DIR__) . '/src/pages/ordrar.php');
fl_assert(!str_contains($page, 'Nästa steg är Boka'), 'orderrader pekar inte på Boka-steget');
fl_assert(str_contains($page, 'Första lediga tiden bokas när ordern läggs.'), 'orderrader säger att tiden bokas när ordern läggs');
fl_assert(str_contains($page, "\$kind === 'laddbox' || \$kind === 'batteri' || \$kind === 'sol'"), 'sol slutför tillsammans med laddbox och batteri');
fl_assert(str_contains($page, "kindNow === 'laddbox' || kindNow === 'batteri' || kindNow === 'sol'"), 'klienten slutför sol');
$solWithOrder = $solar;
$solWithOrder['fortnox_order'] = '9100';
fl_assert(in_array('fakturering', jeab_ordrar_form_stops($solWithOrder), true), 'sol med Fortnox-order kan öppna fakturering före föranmälan');
fl_assert(in_array('spec', jeab_ordrar_form_stops($solWithOrder), true), 'sol med Fortnox-order behåller föranmälan');
fl_assert(str_contains($page, "\$confirmSlutfor ? 'ordrar.slutfor' : 'ordrar.nasta'"), 'bekräfta-knappen bokar laddbox och batteri');
fl_assert(!str_contains($page, "\$confirmSlutfor ? 'Slutför' : 'Nästa'"), 'Slutför är omdöpt till Nästa');
fl_assert(str_contains($page, 'data-js="ordrar-nasta">Nästa</button>'), 'bekräfta-knappen heter Nästa');
fl_assert(str_contains($page, "var shortFlow = kind === 'laddbox';"), 'klienten kortar bara laddbox');
fl_assert(str_contains($page, "kind === 'laddbox' || kind === 'batteri' || kind === 'sol'"), 'klienten slutför batteri tillsammans med sol');
fl_assert(str_contains($page, 'När ärendet slutförs skrivs ordern i Fortnox'), 'slutför-texten nämner Fortnox och kalender');
fl_assert(str_contains($page, 'is-status'), 'status är en onumrerad rubrik');
fl_assert(str_contains($ordrarSrc, "in_array(\$stepKey, ['boka', 'bestall'], true)"), 'boka och gamla beställ visas inte i menyn');
$linesAt = strpos($page, 'name="line_sku[]"');
$bestallAt = strpos($page, 'jeab_ordrar_bestall_step_html');
fl_assert($linesAt !== false && $bestallAt !== false && $linesAt < $bestallAt, 'beställ-innehållet ligger under orderraderna');
$linesChunk = $linesAt !== false && $bestallAt !== false ? substr($page, $linesAt, $bestallAt - $linesAt) : '';
fl_assert(str_contains($page, 'data-js="ordrar-lines-autosave"'), 'orderrader sparas automatiskt');
fl_assert(str_contains($linesChunk, 'Spara som mall'), 'mallsparning ligger kvar vid raderna');
fl_assert(!str_contains($linesChunk, 'value="next"'), 'Nästa vid raderna är borta');
fl_assert(!preg_match('/<button[^>]*>\s*Spara\s*<\/button>/', $linesChunk), 'Spara-knappen vid raderna är borta');
fl_assert(!str_contains($page, 'Fixat manuellt') && !str_contains($ordrarSrc, 'Fixat manuellt') && !str_contains((string) file_get_contents(dirname(__DIR__) . '/index.php'), 'ordrar.fixat'), 'Fixat manuellt är borta');
fl_assert(!str_contains($page, "elseif (\$view === 'bestall')"), 'beställ är inte ett eget panelsteg');
fl_assert(str_contains($page, "\$paneBody === 'bestall'"), 'beställ-panelen skapas inte igen');
fl_assert(str_contains($page, 'function paintNavForKind'), 'produktbyte ritar om menyn');
fl_assert(str_contains($page, 'function persistKind'), 'produktbyte sparar valet');
$persistStart = strpos($page, 'function persistKind');
$persistEnd = strpos($page, 'function postSlutfor');
$persistBody = $persistStart !== false && $persistEnd !== false ? substr($page, $persistStart, $persistEnd - $persistStart) : '';
fl_assert($persistBody !== '' && str_contains($persistBody, "body.set('action', 'ordrar.spara')"), 'produktbyte sparar utan att boka');
fl_assert($persistBody !== '' && !str_contains($persistBody, 'showSpin'), 'produktbyte lämnar inte Sparar på kalendertiden');
fl_assert(str_contains($page, "body.set('action', 'ordrar.slutfor')"), 'laddbox slutför går egen väg');
$laddNav = jeab_ordrar_stepper_html(['kind' => 'laddbox', 'steps' => ['bekraftad' => '2026-09-01']], 'jarfalla', 7, 'bekrafta');
fl_assert(str_contains($laddNav, 'Status') && str_contains($laddNav, 'Bekräfta') && str_contains($laddNav, 'Fakturering'), 'laddbox-menyn är Status, Bekräfta, Fakturering');
fl_assert(!str_contains($laddNav, 'Orderrader') && !str_contains($laddNav, '>Boka<'), 'laddbox-menyn har inte solsteg');
fl_assert(substr_count($laddNav, 'ordrar-step-lbl') === 3, 'laddbox har tre menyrader');
$batNav = jeab_ordrar_stepper_html(['kind' => 'batteri', 'steps' => ['bekraftad' => '2026-09-01']], 'jarfalla', 7, 'bekrafta');
$solNavEarly = jeab_ordrar_stepper_html($solar, 'jarfalla', 7, 'bekrafta');
fl_assert(str_contains($batNav, 'Status') && str_contains($batNav, 'Bekräfta') && str_contains($batNav, '>Föranmälan<') && str_contains($batNav, '>Beställ<') && str_contains($batNav, 'Fakturering'), 'batteri-menyn följer solens steg');
fl_assert(substr_count($batNav, 'ordrar-step-lbl') === substr_count($solNavEarly, 'ordrar-step-lbl'), 'batteri har samma antal menyrader som sol, fick ' . substr_count($batNav, 'ordrar-step-lbl'));
fl_assert(!str_contains($batNav, 'Solkoll') && !str_contains($batNav, 'Dokumentation'), 'batteri-menyn har inget Solkoll-steg');
fl_assert(!str_contains($batNav, '>Boka<'), 'boka är dolt i menyn även för batteri');
$solNav = jeab_ordrar_stepper_html($solar, 'jarfalla', 7, 'orderrader');
fl_assert(substr_count($solNav, '>Beställ<') === 1, 'sol har ett Beställ-steg i menyn');
fl_assert(!str_contains($solNav, '>Boka<'), 'boka är inte tillbaka i menyn');
$solarLabels = [];
foreach (jeab_ordrar_flow($solar) as $step) {
    $solarLabels[(string) ($step['key'] ?? '')] = (string) ($step['label'] ?? '');
}
fl_assert(($solarLabels['spec'] ?? '') === 'Föranmälan', 'steg 3 heter Föranmälan');
fl_assert(($solarLabels['orderrader'] ?? '') === 'Beställ', 'steg 4 heter Beställ');
fl_assert(jeab_ordrar_booking_when_label([
    'booking_id' => 0,
    'steps' => ['bokad_tid' => '2026-10-07 07:00:00'],
]) === 'onsdag 7 oktober klockan 7 till 11', 'bokad tid skrivs ut i full svenska');
$whenSol = jeab_ordrar_result_body([
    'kind' => 'sol',
    'booking_id' => 0,
    'steps' => ['bokad_tid' => '2026-10-07 07:00:00'],
    'solkoll_status' => 'paborjad',
]);
$whenBox = jeab_ordrar_result_body([
    'kind' => 'laddbox',
    'booking_id' => 0,
    'steps' => ['bokad_tid' => '2026-10-07 07:00:00'],
    'solkoll_status' => 'paborjad',
]);
fl_assert(str_contains($whenSol, 'Tiden kan flyttas senare'), 'sol visar meningen om att flytta tiden');
fl_assert(!str_contains($whenBox, 'Tiden kan flyttas senare'), 'laddbox döljer meningen om att flytta tiden');
$whenBat = jeab_ordrar_result_body([
    'kind' => 'batteri',
    'booking_id' => 0,
    'steps' => ['bokad_tid' => '2026-10-07 07:00:00'],
]);
fl_assert(!str_contains($whenBat, 'Tiden kan flyttas senare'), 'batteri döljer meningen om att flytta tiden');
$savedOnly = jeab_ordrar_result_body(['kind' => 'laddbox', 'booking_id' => 0], 'Sparad.');
fl_assert($savedOnly === '', 'autospar utan order eller tid lämnar inte Sparad i resultatytan');
fl_assert(str_contains($page, 'data-js="ordrar-save-status"'), 'Sparad ligger vid sparaknappen');
fl_assert(str_contains($page, 'ordrar-lines-tools') && str_contains($page, 'data-js="ordrar-lines-status"'), 'orderradernas Sparad ligger vid knappraden');
$v3 = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar_v3.php');
fl_assert(str_contains($v3, 'v3-rowbtns') && preg_match('/v3-rowbtns[\s\S]{0,900}data-v3-autosave-status/', $v3) === 1, 'v3-autospar står i knappraden');
fl_assert(!str_contains($whenSol, 'ordrar-solkoll') && !str_contains($whenBox, 'ordrar-solkoll'), 'solkoll-raden ligger inte på andra steg');

$pair = [
    ['RowId' => 11, 'ArticleNumber' => '1001', 'Description' => 'Solpaneler', 'Price' => 45000, 'HouseWork' => true, 'HouseWorkType' => 'SOLARCELLS', 'DeliveredQuantity' => '1'],
    ['RowId' => 12, 'ArticleNumber' => '1002', 'Description' => 'Montage', 'Price' => 12000, 'HouseWork' => true, 'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY', 'DeliveredQuantity' => '1'],
];
$lines = jeab_ordrar_fortnox_editor_lines($pair);
fl_assert(count($lines) === 4, 'editorn har fyra rader');
fl_assert($lines[0]['article'] === '1001' && $lines[0]['housework'] === 'SOLARCELLS', 'första raden hämtas');
fl_assert($lines[2]['article'] === '' && $lines[3]['article'] === '', 'två tomma rader fyller på');
fl_assert($lines[1]['housework'] === 'STORAGESELFPRODUCEDELECTRICITY', 'husarbete-typ följer med');
fl_assert($lines[0]['qty'] === '1' && $lines[2]['qty'] === '', 'antal hämtas och tomma rader är tomma');
fl_assert(jeab_ordrar_fortnox_line_sum_text($lines[0]) === jeab_ordrar_fortnox_money(45000), 'summa är antal gånger à-pris');

$edited = [
    ['row_id' => '11', 'article' => '1001', 'description' => 'Solpaneler 10 kW', 'housework' => 'SOLARCELLS', 'qty' => '2', 'price' => '46000'],
    ['row_id' => '12', 'article' => '1002', 'description' => 'Montage', 'housework' => 'STORAGESELFPRODUCEDELECTRICITY', 'price' => '12000'],
    ['row_id' => '', 'article' => '', 'description' => '', 'housework' => '', 'price' => ''],
    ['row_id' => '', 'article' => '900', 'description' => 'Övrigt', 'housework' => 'OTHERCOSTS', 'price' => '300'],
];
$existing = array_merge($pair, [
    ['RowId' => 13, 'ArticleNumber' => 'OLD', 'Description' => 'Gammal', 'Price' => 1, 'DeliveredQuantity' => '1'],
    ['RowId' => 14, 'ArticleNumber' => 'OLD2', 'Description' => 'Gammal 2', 'Price' => 2, 'DeliveredQuantity' => '1'],
    ['RowId' => 19, 'ArticleNumber' => 'FRAKT', 'Description' => 'Frakt', 'Price' => 500, 'DeliveredQuantity' => '1'],
]);
$merged = jeab_ordrar_fortnox_merge_order_rows($existing, $edited);
fl_assert(count($merged) === 4, 'tom rad hoppas över och rad 5 behålls, fick ' . count($merged));
fl_assert(($merged[0]['Description'] ?? '') === 'Solpaneler 10 kW', 'benämning skrivs');
fl_assert(($merged[0]['Price'] ?? 0) === 46000, 'à-pris skrivs');
fl_assert(($merged[0]['DeliveredQuantity'] ?? '') === '2' && ($merged[0]['OrderedQuantity'] ?? '') === '2', 'antal skrivs');
fl_assert(($merged[0]['HouseWorkType'] ?? '') === 'SOLARCELLS', 'solceller');
fl_assert((int) ($merged[0]['HouseWorkHoursToReport'] ?? 0) === 16, 'solceller 16 timmar');
fl_assert((int) ($merged[1]['HouseWorkHoursToReport'] ?? 0) === 24, 'lagring 24 timmar');
fl_assert(($merged[2]['HouseWork'] ?? null) === false && ($merged[2]['HouseWorkType'] ?? '') === 'OTHERCOSTS', 'övrig kostnad');
fl_assert(!isset($merged[2]['HouseWorkHoursToReport']), 'övrig kostnad har inga timmar');
fl_assert(($merged[3]['ArticleNumber'] ?? '') === 'FRAKT', 'rader efter de fyra första ligger kvar');

$emptyHw = jeab_ordrar_fortnox_editor_to_row([
    'article' => '1',
    'description' => 'Utan',
    'housework' => '',
    'price' => '10',
], []);
fl_assert(($emptyHw['HouseWorkType'] ?? '') === 'EMPTYHOUSEWORK' && ($emptyHw['HouseWork'] ?? true) === false, 'ej husarbete');
$charge = jeab_ordrar_fortnox_editor_to_row([
    'article' => 'LB',
    'description' => 'Laddbox',
    'housework' => 'CHARGINGSTATIONELECTRICVEHICLE',
    'price' => '8000',
], []);
fl_assert((int) ($charge['HouseWorkHoursToReport'] ?? 0) === 4, 'laddpunkt 4 timmar');

$choices = jeab_ordrar_fortnox_housework_choices();
fl_assert(array_values($choices) === [
    'Ej husarbete',
    'Solceller',
    'Lagring egenproducerad el',
    'Laddningspunkt elfordon',
    'Övrig kostnad',
], 'husarbete-typer som i Fortnox');

$payload = ['Order' => ['TotalToPay' => 18750.5, 'OrderRows' => $existing]];
fl_assert(jeab_ordrar_fortnox_att_betala($payload) === '18 750,50 kr', 'att betala från TotalToPay, fick ' . jeab_ordrar_fortnox_att_betala($payload));

$GLOBALS['_JEAB_FORTNOX_ORDER_GET'] = static function (array $row) use ($payload): array {
    return $payload;
};
$open = [
    'kind' => 'sol',
    'company' => 'jarfalla',
    'fortnox_order' => '9001',
    'huvudsokande' => 'Guy Nilsson',
    'reminder_id' => 0,
];
$html = jeab_ordrar_fakturering_html($open, 'jarfalla', 42, null);
foreach ([
    'Fakturering',
    'Artikelnr',
    'Benämning',
    'Husarbete',
    'Antal',
    'À-pris',
    'Summa',
    'Att betala',
    '18 750,50 kr',
    '1001',
    'Solpaneler',
    'name="intent" value="save"',
    'Spara',
    'value="faktura"',
    'Skapa faktura',
    'Ej husarbete',
    'Laddningspunkt elfordon',
] as $needle) {
    fl_assert(str_contains($html, $needle), 'fakturering innehåller ' . $needle);
}
fl_assert(substr_count($html, 'name="fx_article[]"') === 4, 'fyra artikelrader');
fl_assert(substr_count($html, '<input type="text" name="fx_qty[]"') === 4, 'fyra antalfält');
fl_assert(str_contains($html, jeab_ordrar_fortnox_line_sum_text($lines[0])), 'summa visas på raden');
$fxCss = (string) file_get_contents(dirname(__DIR__) . '/assets/css/ordrar.css');
fl_assert(str_contains($fxCss, '#0A84FF'), 'att betala använder primärblått');
fl_assert(preg_match('/\.page-ordrar \.ordrar-search input \{[^}]*font-size:\s*16px/s', $fxCss) === 1, 'listsök är 16 px så iOS inte zoomar');
fl_assert(preg_match('/\.page-ordrar \.ordrar-search input \{[^}]*min-height:\s*44px/s', $fxCss) === 1, 'listsök är 44 px hög');
fl_assert(str_contains($fxCss, '--ordrar-frame-pad: var(--space-5)'), 'gemensam ram-padding från --space-5');
fl_assert(!str_contains($fxCss, 'padding: var(--space-2) 0 var(--space-4)'), 'bekräfta-ramen har sid-padding');
fl_assert(str_contains($fxCss, '.ordrar-confirm .ordrar-fastighet'), 'fastighet ingår i ram-regeln');
fl_assert(str_contains($fxCss, '.ordrar-list .ordrar-row'), 'listan använder samma ram-padding');

$GLOBALS['_JEAB_FORTNOX_ORDER_GET'] = static function (): void {
    throw new InvalidArgumentException('Ordern är låst.');
};
$errHtml = jeab_ordrar_fakturering_html($open, 'jarfalla', 42, null);
fl_assert(str_contains($errHtml, 'Kunde inte spara. Ordern är låst.'), 'hämtningsfel visas som Kunde inte spara');
fl_assert(str_contains($errHtml, 'is-alert'), 'felet är röd alert');

$put = null;
$GLOBALS['_JEAB_FORTNOX_ORDER_GET'] = static function () use ($payload): array {
    return $payload;
};
$GLOBALS['_JEAB_FORTNOX_ORDER_PUT'] = static function (string $no, array $rows) use (&$put, $payload): array {
    $put = ['no' => $no, 'rows' => $rows];
    $next = $payload;
    $next['Order']['OrderRows'] = $rows;
    return $next;
};
$written = jeab_ordrar_fortnox_write_lines($open, $edited);
fl_assert(is_array($put) && $put['no'] === '9001' && count($put['rows']) === 4, 'spara skriver de sammanslagna raderna');
fl_assert((string) ($written['Order']['OrderRows'][3]['ArticleNumber'] ?? '') === 'FRAKT', 'skrivningen behåller raden efter de fyra');

echo "ordrar flow steps: ok\n";
