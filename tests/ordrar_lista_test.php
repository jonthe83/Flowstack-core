<?php

declare(strict_types=1);

/**
 * Ordrar-listan följer v3: «x av N» från jeab_ordrar_v3_progress (4 för sol/batteri,
 * 2 för laddbox), samma ord, kolumnerna Installation/Beställd/Leverans med «4 nov»,
 * Nästa som verb (grått när någon annan väntas), brådskande sortering och
 * försenat datum utan märken.
 */

$tmp = sys_get_temp_dir() . '/jeab-ordrar-lista-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
putenv('JEAB_DISABLE_SQLITE=1');
putenv('JEAB_BUG_WEBHOOK_CAPTURE=1');
$_ENV['JEAB_BUG_WEBHOOK_CAPTURE'] = '1';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
jeab_ordrar_external_live(false);
date_default_timezone_set('Europe/Stockholm');

function ol_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function ol_row(array $extra = []): array
{
    return array_replace_recursive([
        'reminder_id' => 900, 'company' => 'jarfalla', 'kind' => 'batteri',
        'customer' => 'Lista Testsson', 'address' => 'Testgatan 3, 175 00 Järfälla', 'huvudsokande' => 'Lista Testsson',
        'personnummer' => '19800101-0000', 'fastighetsbeteckning' => 'Testet 3:3', 'pris' => 79000,
        'natbolag' => 'E.ON', 'anlaggnings_id' => '735999123456789012', 'kwh' => '10',
        'created_at' => '2026-09-01 10:00:00', 'steps' => [],
    ], $extra);
}

$future = (new DateTimeImmutable('+20 days'))->format('Y-m-d');
$past = (new DateTimeImmutable('-5 days'))->format('Y-m-d');

// 1. Räknaren: 4 för batteri/sol, 2 för laddbox, samma funktion som v3-huvudet.
$bat = ol_row([
    'fortnox_order' => '5001', 'foranmalan_status' => 'A', 'foranmalan_case_status' => 'skickad',
    'steps' => ['bokad_tid' => $future . ' 08:00', 'foranmald' => '2026-09-23', 'bestalld' => '2026-10-07'],
    'aprilice_order' => '2026104801', 'aprilic_leverans' => '2026-10-12',
]);
$p = jeab_ordrar_v3_progress($bat);
ol_assert($p['total'] === 4 && $p['done'] === 3, 'batteri: 3 av 4 (Bekräfta, Föranmälan, Beställ klara)');
$sol = ol_row(['kind' => 'sol']);
ol_assert(jeab_ordrar_v3_progress($sol)['total'] === 4, 'sol: 4 steg');
$ladd = ol_row(['kind' => 'laddbox']);
$pl = jeab_ordrar_v3_progress($ladd);
ol_assert($pl['total'] === 2 && $pl['done'] === 0, 'laddbox: 0 av 2');
$html = jeab_ordrar_board_row_html($bat, 'jarfalla');
ol_assert(str_contains($html, '3 av 4') && !str_contains($html, 'av 6'), 'listraden visar 3 av 4');
ol_assert(substr_count($html, '<i class="is-d"></i>') === 3 && str_contains($html, 'ordrar-bar'), 'tunn stegbar med 3 klara segment');

// 2. Kolumner: Föranmälan med datum, Beställd = orderdatum, Leverans = leveransdatum, «4 nov».
$cells = jeab_ordrar_lista_cells($bat);
ol_assert($cells['foran']['text'] === '23 sep' && $cells['foran']['sub'] === 'Skickad', 'föranmälan: 23 sep · Skickad (inte —)');
ol_assert($cells['bestalld']['text'] === '7 okt' && str_contains($cells['bestalld']['sub'], 'Aprilice 2026104801'), 'Beställd visar orderdatum 7 okt + Aprilice-nummer');
ol_assert($cells['leverans']['text'] === '12 okt', 'Leverans visar 12 okt');
ol_assert(preg_match('/^\d{1,2} (jan|feb|mar|apr|maj|jun|jul|aug|sep|okt|nov|dec)$/', $cells['install']) === 1, 'installation som «4 nov»');
ol_assert(preg_match('/>\d{4}</', $html) !== 1, 'inga MMDD-datum i raden');

// Beställd utan Aprilice-nummer: väntar på Bokmalen (grått).
$noNo = ol_row([
    'fortnox_order' => '5002', 'foranmalan_status' => 'A', 'foranmalan_case_status' => 'skickad',
    'steps' => ['foranmald' => '2026-09-23', 'bestalld' => '2026-10-07', 'orderrader' => '2026-10-06'],
    'orderrader_saved' => true,
]);
$c2 = jeab_ordrar_lista_cells($noNo);
ol_assert(jeab_ordrar_bestallning_lage($noNo)['key'] === 'bokmalen', 'fixture: läget väntar på Bokmalen');
{
    ol_assert($c2['bestalld']['sub'] === 'Väntar på Bokmalen' && $c2['bestalld']['wait'], 'Beställd utan ordernummer: Väntar på Bokmalen');
}

// 4. Nästa som verb.
$store = jeab_ordrar_resolve_store(null);
$bk = jeab_create_booking($store, 'Lista Testsson', ['Roberto'], $future . ' 08:00:00', $future . ' 16:00:00', 'batteri', ['company' => 'jarfalla'], false);
$bookingId = (int) ($bk['id'] ?? 0);
ol_assert($bookingId > 0, 'testbokning skapad');
$booked = $bat;
$booked['booking_id'] = $bookingId;
ol_assert(jeab_ordrar_v3_next_action($booked, $store)['text'] === 'Skapa faktura', 'bokad + beställd: nästa Skapa faktura');
ol_assert(jeab_ordrar_v3_next_action($bat)['text'] === 'Boka', 'ingen kalenderbokning: nästa Boka (som v3)');
$foranTodo = ol_row(['fortnox_order' => '5003', 'foranmalan_status' => 'A', 'steps' => ['bokad_tid' => $future]]);
ol_assert(jeab_ordrar_v3_next_action($foranTodo)['text'] === 'Skicka föranmälan', 'nästa: Skicka föranmälan');
$bestTodo = ol_row(['fortnox_order' => '5004', 'booking_id' => $bookingId, 'foranmalan_status' => 'C', 'steps' => ['spec' => '2026-09-14']]);
ol_assert(jeab_ordrar_v3_next_action($bestTodo, $store)['text'] === 'Beställ', 'nästa: Beställ');
$nekad = ol_row(['fortnox_order' => '5005', 'foranmalan_status' => 'A', 'foranmalan_case_status' => 'nekad', 'steps' => ['foranmald' => '2026-09-23']]);
$nekad['booking_id'] = $bookingId;
ol_assert(jeab_ordrar_v3_next_action($nekad, $store)['text'] === 'Skicka föranmälan igen', 'nekad: Skicka föranmälan igen');
$net = ol_row([
    'fortnox_order' => '5006', 'booking_id' => $bookingId, 'foranmalan_status' => 'A', 'foranmalan_case_status' => 'godkand', 'fortnox_invoice' => '13999',
    'steps' => ['foranmald' => '2026-09-23', 'bestalld' => '2026-10-01', 'faktura_skapad' => '2026-10-05'],
]);
$nn = jeab_ordrar_v3_next_action($net, $store);
if (!jeab_ordrar_allt_klart($net)) {
    ol_assert($nn['text'] === 'Väntar på nätägaren' && $nn['wait'] === true, 'fakturerad, ej färdiganmäld: Väntar på nätägaren (grått)');
    ol_assert(str_contains(jeab_ordrar_board_row_html($net, 'jarfalla', $store), 'ordrar-next is-wait">Väntar på nätägaren'), 'väntetext renderas grå');
}
ol_assert(jeab_ordrar_v3_next_action($ladd)['text'] === 'Skapa order och boka', 'laddbox: Skapa order och boka');
$laddInv = ol_row(['kind' => 'laddbox', 'fortnox_order' => '5007']);
ol_assert(jeab_ordrar_v3_next_action($laddInv)['text'] === 'Skapa faktura', 'laddbox bekräftad: Skapa faktura');

// 5. Sortering: närmaste installation först (passerade först), utan datum sist.
$a = ol_row(['reminder_id' => 1, 'steps' => ['bokad_tid' => $future]]);
$b = ol_row(['reminder_id' => 2, 'steps' => ['bokad_tid' => $past]]);
$c = ol_row(['reminder_id' => 3, 'created_at' => '2026-10-01 10:00:00']);
$d = ol_row(['reminder_id' => 4, 'created_at' => '2026-10-05 10:00:00']);
$sorted = array_map(static fn(array $r): int => (int) $r['reminder_id'], jeab_ordrar_lista_sort([$c, $a, $d, $b]));
ol_assert($sorted === [2, 1, 4, 3], 'sortering: passerad, kommande, utan datum (nyast först)');

// Försenat: bara datumet får is-late, ingen bakgrund/märke.
$late = jeab_ordrar_lista_cells($b);
ol_assert($late['late'] === true, 'passerad installation utan faktura = försenad');
$lateHtml = jeab_ordrar_board_row_html($b, 'jarfalla');
ol_assert(str_contains($lateHtml, 'ordrar-cell-val is-late') && !str_contains($lateHtml, 'ordrar-row is-late'), 'försenat markerar bara datumtexten');
ol_assert(jeab_ordrar_lista_cells($a)['late'] === false, 'kommande installation är inte försenad');

// Stängd order: Klart, alla steg, fakturanumret kvar.
$closed = ol_row([
    'kind' => 'sol', 'allt_klart' => true, 'closed_at' => '2026-10-02 10:00:00', 'fortnox_invoice' => '13462',
    'steps' => ['spec' => '2026-09-14', 'faktura_skapad' => '2026-09-28', 'bestalld' => '2026-09-14'],
    'foranmalan_status' => 'C', 'fortnox_order' => '4001',
]);
if (jeab_ordrar_allt_klart($closed)) {
    $pc = jeab_ordrar_v3_progress($closed);
    ol_assert($pc['closed'] && $pc['done'] === 4 && $pc['label'] === 'Klart', 'stängd sol: Klart 4 av 4');
    $ch = jeab_ordrar_board_row_html($closed, 'jarfalla');
    ol_assert(str_contains($ch, '>Klart<') && str_contains($ch, 'Faktura 13462'), 'stängd rad: Klart + Faktura 13462');
}

// Inga namn-/produktchips; adressen identifierar ordern, inte ordernumret.
ol_assert(str_contains($html, '<span class="ordrar-name">Testgatan 3, Järfälla</span>') && !str_contains($html, '#900'), 'adressen är rubriken, inget ordernummer');

// v3-huvudet använder samma räkning.
$v3src = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar_v3.php');
ol_assert(str_contains($v3src, '$progress = jeab_ordrar_v3_progress($open, $store, $steps);'), 'v3-huvudet räknar med jeab_ordrar_v3_progress');

// Rubrikerna i listan.
$page = (string) file_get_contents(dirname(__DIR__) . '/src/pages/ordrar.php');
foreach (['Adress', 'Steg', 'Installation', 'Beställd', 'Leverans', 'Nästa'] as $h) {
    ol_assert(str_contains($page, '<span>' . $h . '</span>'), 'kolumnrubrik ' . $h);
}
ol_assert(str_contains($page, 'jeab_ordrar_board_collect('), 'sidan samlar tavlan');
$collectSrc = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar.php');
ol_assert(str_contains($collectSrc, 'jeab_ordrar_lista_sort('), 'listan sorteras brådskande först');

echo "ordrar_lista_test: OK\n";
