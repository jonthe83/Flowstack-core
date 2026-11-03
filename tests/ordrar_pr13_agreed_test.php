<?php

declare(strict_types=1);

/**
 * PR 13 (P-7, P-8, P-11): Ordrar-listan är en sida, öppning skriver inte,
 * och de godkända knapp-/fakturaändringarna i nya vyn.
 */

$tmp = sys_get_temp_dir() . '/jeab-ordrar-pr13-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}
$purchasePdo = new PDO('sqlite:' . $tmp . '/purchases.sqlite');
$purchasePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_PURCHASES_PDO'] = $purchasePdo;
jeab_purchases_store_ensure($purchasePdo);
jeab_ordrar_external_live(false);
date_default_timezone_set('Europe/Stockholm');

function p13(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function p13_read(string $rel): string
{
    $text = file_get_contents(dirname(__DIR__) . '/' . $rel);
    p13(is_string($text) && $text !== '', $rel . ' går att läsa');

    return (string) $text;
}

function p13_row(array $extra = []): array
{
    return array_replace([
        'reminder_id' => 9101,
        'company' => 'jarfalla',
        'kind' => 'laddbox',
        'customer' => 'Nils Nilsson',
        'address' => 'Testgatan 1, 175 00 Järfälla',
        'huvudsokande' => 'Nils Nilsson',
        'personnummer' => '19800101-0000',
        'fastighetsbeteckning' => 'Testet 1:1',
        'pris' => 9990,
        'steps' => [],
    ], $extra);
}

function p13_reason(array $row, string $id, ?JeabStore $store = null): string
{
    foreach (jeab_ordrar_v3_steps($row, $store) as $step) {
        if (($step['id'] ?? '') === $id) {
            return (string) ($step['reason'] ?? '');
        }
    }

    return '';
}

function p13_state(array $row, string $id, ?JeabStore $store = null): string
{
    foreach (jeab_ordrar_v3_steps($row, $store) as $step) {
        if (($step['id'] ?? '') === $id) {
            return (string) ($step['state'] ?? '');
        }
    }

    return '';
}

// —— P-7: sida om 40, offset, sök, chip ——
p13(jeab_ordrar_page_size() === 25, 'sidstorlek är 25');
$rows = [];
for ($i = 1; $i <= 45; $i++) {
    $rows[] = p13_row([
        'reminder_id' => 8000 + $i,
        'customer' => $i === 41 ? 'Unik Zetaq' : 'Kund ' . $i,
        'huvudsokande' => $i === 41 ? 'Unik Zetaq' : 'Kund ' . $i,
    ]);
}
$first = jeab_ordrar_board_page($rows, '', '', 0);
p13(count($first['rows']) === 25 && $first['total'] === 45 && $first['next'] === 25, 'första sidan är 25 och nästa offset är 25');
$second = jeab_ordrar_board_page($rows, '', '', 25);
p13(count($second['rows']) === 20 && $second['next'] === null, 'andra sidan är resten');
$secondIds = array_map(static fn (array $row): int => (int) ($row['reminder_id'] ?? 0), $second['rows']);
p13(in_array(8041, $secondIds, true), 'rad 41 ligger på sida två');
$found = jeab_ordrar_board_page($rows, '', 'Zetaq', 0);
p13(count($found['rows']) === 1 && (string) ($found['rows'][0]['customer'] ?? '') === 'Unik Zetaq', 'sök träffar ett unikt namn');
$tags = jeab_ordrar_board_tags($rows[0]);
$htmlRow = jeab_ordrar_board_row_html($rows[0], 'jarfalla');
p13(str_contains($htmlRow, 'data-tags="' . implode(' ', $tags) . '"'), 'radens data-tags är samma som chiptaggarna');
if ($tags !== []) {
    $chipped = jeab_ordrar_board_page($rows, $tags[0], '', 0);
    p13($chipped['total'] >= 1 && jeab_ordrar_board_matches_chip($chipped['rows'][0], $tags[0]), 'chippet tar bara rader med den taggen');
}
$none = jeab_ordrar_board_page($rows, 'besikt', '', 0);
p13($none['total'] === 0, 'ett chip utan träff ger en tom sida');

$page = p13_read('src/pages/ordrar.php');
p13(str_contains($page, 'data-server-search="1"') && str_contains($page, "echo '<a class=\"' . e(\$cls)"), 'chips är länkar och sök går till servern');
p13(str_contains($page, "Visa fler"), 'Visa fler finns kvar');
$shell = p13_read('assets/js/shell.js');
p13(str_contains($shell, "data-server-search') !== '1'") && str_contains($shell, 'ordrarSearchTimer'), 'skalsök väntar och körs bara med server-sök');

// —— P-8: öppning skriver inte; tick och sparande POST reparerar ——
$index = p13_read('index.php');
$openPage = p13_read('src/pages/ordrar_v3.php');
p13(!str_contains($index, 'jeab_ordrar_persist_known_shop_order'), 'GET i index skriver inte shopnummer');
p13(!str_contains($page, 'jeab_ordrar_activity_backfill') && !str_contains($page, 'jeab_ellevio_fullmakt_pull_signed'), 'listvyn skriver inte aktivitet eller Ellevio-id');
p13(!str_contains($openPage, 'jeab_ordrar_activity_backfill') && !str_contains($openPage, 'jeab_ellevio_fullmakt_pull_signed'), 'ordervyn skriver inte aktivitet eller Ellevio-id');
p13(str_contains($index, 'jeab_ordrar_repair_saved'), 'sparande POST reparerar ordern');
$tick = p13_read('src/tick.php');
p13(str_contains($tick, 'jeab_ordrar_repair_tick(25)') && str_contains($tick, 'inte ett fjärde deljobb'), 'tick reparerar några ordrar utan ett fjärde deljobb');
p13(jeab_ordrar_repair_tick(25) === 0, 'tom ordertabell: reparationen gör inget');

function p13_fn(string $src, string $name): string
{
    $start = strpos($src, 'function ' . $name . '(');
    p13($start !== false, $name . ' finns');
    $next = strpos($src, "\nfunction ", $start + 10);

    return $next === false ? substr($src, $start) : substr($src, $start, $next - $start);
}

function p13_has_key(array $row, string $key): bool
{
    foreach (jeab_ordrar_activity_list($row) as $item) {
        if (($item['key'] ?? '') === $key) {
            return true;
        }
    }

    return false;
}

$ordrarSrc = p13_read('src/ordrar.php');
$maybeFn = p13_fn($ordrarSrc, 'jeab_ordrar_repair_maybe');
$oneFn = p13_fn($ordrarSrc, 'jeab_ordrar_repair_one');
$tickFn = p13_fn($ordrarSrc, 'jeab_ordrar_repair_tick');
$fetchFn = p13_fn($ordrarSrc, 'jeab_ordrar_repair_fetch');
p13(!str_contains($maybeFn, 'jeab_ellevio_fullmakt_applies') && !str_contains($maybeFn, 'jeab_ordrar_natbolag'), 'reparationen slår inte upp nätbolag');
p13(str_contains($maybeFn, "=== 'Ellevio'"), 'reparationen tittar på sparat nätbolag');
p13(str_contains($oneFn, "=== 'Ellevio'") && str_contains($oneFn, 'jeab_ellevio_fullmakt_pull_signed'), 'Ellevio-id hämtas bara när nätbolaget redan är sparat');
p13(!str_contains($tickFn, 'jeab_purchase_list') && str_contains($fetchFn, 'LIMIT ') && str_contains($fetchFn, 'klart = 0'), 'tick går högst en sida öppna ordrar');

$bare = p13_row([
    'reminder_id' => 1001,
    'natbolag' => '',
    'fullmakt_token' => 'tok-tomt',
    'address' => 'Testgatan 1, 175 00 Järfälla',
]);
p13(jeab_ordrar_repair_maybe($bare) === false, 'tomt nätbolag är ingen kandidat');
jeab_purchase_put($bare);
$doneRow = p13_row([
    'reminder_id' => 1000,
    'natbolag' => 'Ellevio',
    'fullmakt_token' => 'tok-klar',
    'manual_done' => ['by' => 'Test', 'at' => '2026-10-01 10:00:00'],
    'steps' => ['foranmald' => '2026-09-01'],
]);
jeab_purchase_put($doneRow);
p13(jeab_ordrar_repair_maybe(jeab_purchase_get(1000)) === false, 'Allt klart repareras inte');
for ($n = 0; $n < 30; $n++) {
    $id = 1100 + $n;
    $stuck = p13_row([
        'reminder_id' => $id,
        'natbolag' => 'Ellevio',
        'fullmakt_token' => 'tok-' . $id,
        'fullmakt_signed' => $n === 28,
        'anlaggnings_id' => '',
    ]);
    jeab_purchase_put($stuck);
}
p13(jeab_ordrar_repair_maybe(jeab_purchase_get(1100)) === true, 'osignerad fullmakt med sparat Ellevio är kandidat en gång');
p13(jeab_ordrar_repair_maybe(jeab_purchase_get(1128)) === true, 'signerad fullmakt utan anläggnings-id är kandidat en gång');
$later = p13_row([
    'reminder_id' => 2000,
    'steps' => ['foranmald' => '2026-09-01'],
    'activity' => [],
]);
jeab_purchase_put($later);
p13(jeab_ordrar_repair_maybe(jeab_purchase_get(2000)) === true, 'saknad aktivitet ligger längre fram i kön');
$loads = (int) ($GLOBALS['_JEAB_PURCHASE_LOAD_ALL'] ?? 0);
$firstPass = jeab_ordrar_repair_tick(25);
p13($firstPass === 24, 'första tick tar 24 lagningar och inte hela kön');
p13(!p13_has_key((array) jeab_purchase_get(2000), 'foranmald'), 'ordern längst fram i kön väntar tills de första är märkta');
p13(jeab_ordrar_repair_maybe((array) jeab_purchase_get(1100)) === false, 'försökt osignerad fullmakt tar inte nästa plats');
p13(trim((string) (jeab_purchase_get(1124)['ordrar_repair_hold'] ?? '')) === '', 'en rad utanför sidan är inte märkt än');
$seen = (array) jeab_purchase_get(1100);
$seen['fullmakt_signed'] = true;
p13(jeab_ordrar_repair_maybe($seen) === true, 'ett ändrat fullmaktsläge blir kandidat igen');
$secondPass = jeab_ordrar_repair_tick(25);
p13($secondPass === 7, 'nästa tick tar resten, inklusive aktiviteten');
p13(p13_has_key((array) jeab_purchase_get(2000), 'foranmald'), 'aktiviteten fylls i när tick når raden');
p13(jeab_ordrar_repair_maybe((array) jeab_purchase_get(1128)) === false, 'signerad rad utan anläggnings-id märks som försökt');
p13(trim((string) (jeab_purchase_get(1128)['anlaggnings_id'] ?? '')) === '', 'anläggnings-id skrivs inte när det inte finns');
p13(!p13_has_key((array) jeab_purchase_get(1000), 'foranmald'), 'Allt klart får ingen aktivitet av tick');
p13(jeab_ordrar_repair_tick(25) === 0, 'märkta rader tar inga platser nästa varv');
p13((int) ($GLOBALS['_JEAB_PURCHASE_LOAD_ALL'] ?? 0) === $loads, 'tick läser inte hela ordertabellen');
$board = jeab_ordrar_board_row_html((array) jeab_purchase_get(1100), 'jarfalla');
p13(!str_contains($board, 'ordrar_repair_hold'), 'tavlan visar inte tick-markören');
p13(!str_contains(jeab_sql_purchases_list_json((array) jeab_purchase_get(1100)), 'ordrar_repair_hold'), 'list_json tar inte med tick-markören');

// —— P-11: klockslag, fakturalås, knappar, Ångra ——
$morning = jeab_ordrar_windows_for_clock(jeab_ordrar_day_windows('laddbox'), '09:30');
$afternoon = jeab_ordrar_windows_for_clock(jeab_ordrar_day_windows('laddbox'), '12:00');
$emptyClock = jeab_ordrar_windows_for_clock(jeab_ordrar_day_windows('laddbox'), '');
$batteri = jeab_ordrar_windows_for_clock(jeab_ordrar_day_windows('batteri'), '15:00');
p13($morning[0][0] === '07:00:00' && $afternoon[0][0] === '12:00:00', 'klockslag från 12 väljer eftermiddag');
p13($emptyClock[0][0] === '07:00:00', 'tomt klockslag tar förmiddagen först');
p13($batteri === [['07:00:00', '16:00:00']], 'batteri förblir heldag');
p13(jeab_ordrar_faktura_void_plan(['Cancelled' => true], 'makulera') === 'already', 'makulerad faktura är redan klar');
p13(jeab_ordrar_faktura_void_plan(['Booked' => true, 'Cancelled' => false], 'makulera') === 'booked', 'bokförd faktura ska krediteras');
p13(jeab_ordrar_faktura_void_plan(['Booked' => true], 'kreditera') === 'kreditera', 'kreditering går vidare även om fakturan är bokförd');

$plain = p13_row();
p13(p13_reason($plain, 'faktura') === 'Kräver att Bekräfta är klar', 'obokad laddbox låser fakturan på Bekräfta');
$withOrder = p13_row(['fortnox_order' => '1874']);
p13(p13_state($withOrder, 'faktura') === 'todo' && p13_reason($withOrder, 'faktura') === '', 'Fortnox-order låser upp Skapa faktura');

$store = jeab_store();
$booking = jeab_create_booking($store, 'Nils Nilsson', ['Elin'], '2026-11-16 09:00:00', '2026-11-16 10:00:00', 'laddbox', [
    'company' => 'jarfalla',
]);
$booked = p13_row(['booking_id' => (int) $booking['id'], 'steps' => ['bokad_tid' => '2026-11-16 09:00:00']]);
p13(jeab_ordrar_is_booked($booked, $store), 'testbokningen räknas som bokad');
p13(p13_reason($booked, 'faktura', $store) === 'Kräver en Fortnox-order', 'kort flöde kräver Fortnox-order innan faktura');
p13(p13_state($booked, 'bekrafta', $store) === 'klar' || p13_state($booked, 'bekrafta', $store) === 'todo', 'Bekräfta-läget följer fortfarande bokningen');
$bookedHtml = jeab_ordrar_v3_html($booked, 'jarfalla', 9101, $store);
p13(str_contains($bookedHtml, 'value="ordrar.fortnox_utan_bokning"') && !str_contains($bookedHtml, 'value="ordrar.slutfor"'), 'bokad order utan Fortnox-order postar fortnox_utan_bokning');
p13(!str_contains($bookedHtml, '>Skapa order och boka<') && !str_contains($bookedHtml, '>Skapa faktura<'), 'fakturan och skapa-och-boka syns inte före Fortnox-ordern');

$openHtml = jeab_ordrar_v3_html($plain, 'jarfalla', 9101);
p13(str_contains($openHtml, 'value="ordrar.slutfor"') && str_contains($openHtml, '>Skapa order och boka<'), 'obokad order postar fortfarande ordrar.slutfor');

$clockHtml = jeab_ordrar_v3_install_date_html(p13_row(['install_time' => '13:15', 'kind' => 'laddbox']), null, 'v3-install');
p13(str_contains($clockHtml, 'type="time" name="install_time"') && str_contains($clockHtml, 'value="13:15"'), 'installationsklockan finns och fylls i');
p13(str_contains($clockHtml, 'Klockslaget väljer förmiddag eller eftermiddag'), 'hjälpen säger hur klockslaget används');

$invoice = p13_row([
    'fortnox_order' => '1874',
    'fortnox_invoice' => '13463',
    'fortnox_order_rows' => [[
        'RowId' => '1', 'ArticleNumber' => '100', 'Description' => 'Laddbox test', 'HouseWorkType' => 'CHARGINGSTATIONELECTRICVEHICLE',
        'DeliveredQuantity' => '1', 'Price' => '9990',
    ]],
    'steps' => ['faktura_skapad' => '2026-10-08 14:00:00'],
]);
$invoiceHtml = jeab_ordrar_v3_html($invoice, 'jarfalla', 9101);
p13(str_contains($invoiceHtml, 'ordrar.faktura_makulera') && str_contains($invoiceHtml, 'ordrar.faktura_kreditera'), 'skapad faktura har Makulera och Kreditera');
p13(str_contains($invoiceHtml, 'data-v3-hold="1"') && !str_contains($invoiceHtml, 'data-v3-primary="faktura-void"'), 'makulera och kreditera väntar på Ångra och är inte primärknappar');
p13(substr_count($invoiceHtml, 'data-v3-primary=') === 1, 'en grön knapp när fakturan finns');

$todoInvoice = p13_row([
    'fortnox_order' => '1874',
    'fortnox_order_rows' => [[
        'RowId' => '1', 'ArticleNumber' => '100', 'Description' => 'Laddbox test', 'HouseWorkType' => '',
        'DeliveredQuantity' => '1', 'Price' => '9990',
    ]],
]);
$todoHtml = jeab_ordrar_v3_html($todoInvoice, 'jarfalla', 9101);
p13(str_contains($todoHtml, 'name="fx_article[]"') && str_contains($todoHtml, 'inputmode="text"'), 'artikelnummer är text');
p13(str_contains($todoHtml, 'name="fx_hw[0]"') && !str_contains($todoHtml, 'name="fx_hw[]"'), 'husarbete är en rad per index');
p13(str_contains($todoHtml, 'name="pris"') && str_contains($todoHtml, 'inputmode="numeric"'), 'Att betala är fortfarande numeriskt');
p13(str_contains($todoHtml, 'data-v3-hold="1"') && !str_contains($todoHtml, 'Ångra stoppar det'), 'Skapa faktura skickas direkt, utan ångrafönster');
p13(!str_contains($todoHtml, 'data-v3-faktura-confirm'), 'fakturafrågan är inte en dialog');

$foran = p13_row([
    'kind' => 'batteri',
    'kwh' => '10',
    'natbolag' => 'E.ON',
    'anlaggnings_id' => '735999123456789012',
    'fortnox_order' => '5001',
    'foranmalan_status' => 'A',
    'steps' => ['foranmald' => '2026-09-23', 'bokad_tid' => '2026-11-16 09:00:00'],
]);
$foranHtml = jeab_ordrar_v3_foranmalan_html($foran, 'jarfalla', 9101, ['id' => 'foranmalan', 'state' => 'klar', 'reason' => '', 'n' => 2, 'title' => 'Föranmälan', 'short' => 'Föranmälan']);
p13(str_contains($foranHtml, 'ordrar.foranmalan_status_live') && str_contains($foranHtml, 'Hämtar läget från Bokmalen.'), 'Uppdatera status läser Bokmalen');
p13(str_contains($foranHtml, 'data-v3-resend="1"') && str_contains($foranHtml, '>Skicka igen<'), 'Skicka igen syns på steget');
$foranTodo = jeab_ordrar_v3_foranmalan_html($foran, 'jarfalla', 9101, ['id' => 'foranmalan', 'state' => 'todo', 'reason' => '', 'n' => 2, 'title' => 'Föranmälan', 'short' => 'Föranmälan']);
p13(str_contains($foranTodo, 'Inget skickas till nätbolaget förrän du trycker på knappen') && str_contains($foranTodo, 'Spara utan att skicka'), 'föranmälan säger att inget skickas och Spara är en länk');
p13(str_contains($foranTodo, 'data-v3-hold="1"'), 'Skicka föranmälan har ångrafönster');

$spec = jeab_ordrar_spec_html(p13_row(['kind' => 'batteri', 'vaxelriktare' => '']), 'jarfalla', 9101);
p13(!str_contains($spec, 'Sigenergy') && str_contains($spec, 'placeholder="Välj växelriktare"'), 'gamla vyn har ingen förvald växelriktare');
p13(str_contains($spec, 'Nästa med Automatiskt skickar föranmälan till Bokmalen.') && str_contains($spec, 'data-js="ordrar-nasta">Nästa'), 'gamla vyn behåller Nästa och hjälptexten');

$js = p13_read('assets/js/ordrar-v3.js');
p13(!str_contains($js, 'location.reload') && str_contains($js, 'Steget kunde inte uppdateras. Du är kvar på sidan.'), 'ett misslyckat stegbyte laddar inte om sidan');
p13(str_contains($js, 'submitHeldStep(form, submitter)') && str_contains($js, "step === 'bekrafta'") && str_contains($js, 'data-v3-hold'), 'Skickas direkt och Bekräfta stannar kvar');
p13(!str_contains($js, 'data-v3-pay-saved'), 'Att betala sparas inte av sig själv');
p13(str_contains($js, "submitter.value : 'ordrar.slutfor'"), 'primärknappen postar sitt eget action-värde');

$css = p13_read('assets/css/ordrar-v3.css');
p13(str_contains($css, '.ov3 .v3-calbtn { display: inline-grid; place-items: center; width: 44px; height: 44px;'), 'kalenderknappen är 44 px i basen');
p13(str_contains($css, '.ov3 .v3-calbtn { width: 44px; height: 44px;'), 'mobilregeln för kalenderknappen är kvar');
p13(str_contains($css, '.ov3 .v3-pillsw button { min-height: 44px;') && str_contains($css, '.ov3 .v3-lnk { min-height: 44px;'), 'piller och länkar är 44 px på mobil');
p13(str_contains($css, '.ov3 .v3-fxrow .v3-mn { flex: 1; min-width: 0; white-space: normal; overflow: visible; text-overflow: unset;'), 'fakturarader radbryts');

echo "OK  pr13 agreed\n";
