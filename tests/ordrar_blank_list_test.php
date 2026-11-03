<?php

declare(strict_types=1);

/**
 * Saknad kontakt lämnar orderns adress. Länkad kontakt utan gata visas tom. Listan visar orderns egen gata
 * och tar med öppna ordrar förbi de första 50. Leveransdatum skriver bara felet.
 * ordrar.blank_scan läser, skriver inte.
 */

$tmp = sys_get_temp_dir() . '/jeab-ordrar-blank-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'json',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/ordrar_v3.php';
jeab_ordrar_external_live(false);
date_default_timezone_set('Europe/Stockholm');

function bl_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
$lead = $store->insertReminder([
    'title' => 'Batteri',
    'company' => 'jarfalla',
    'assignee' => 'Sam L',
    'customer_name' => 'Testkund Blank',
    'email' => 'blank@example.test',
    'phone' => '0700000099',
    'address' => 'Dimvägen 8',
    'step' => 'vunnen',
    'payload' => ['job' => 'batteri'],
]);
$rid = (int) $lead['id'];
$stored = [
    'reminder_id' => $rid,
    'company' => 'jarfalla',
    'kind' => 'batteri',
    'natbolag' => 'Vattenfall',
    'contact_id' => '',
    'huvudsokande' => 'Testkund Blank',
    'name' => 'Testkund Blank',
    'address' => 'Dimvägen 8',
    'huvudsokande_address' => 'Dimvägen 8',
    'gata' => 'Dimvägen 8',
    'ort' => 'Järfälla',
    'booking_id' => 0,
    'steps' => [],
];
jeab_purchase_put($stored);

$painted = jeab_contacts_sole_paint_order($stored);
bl_ok(($painted['address'] ?? '') === 'Dimvägen 8' && ($painted['gata'] ?? '') === 'Dimvägen 8', 'saknad kontakt målar inte tom adress över ordern');
bl_ok(($painted['kind'] ?? '') === 'batteri' && ($painted['huvudsokande'] ?? '') === 'Testkund Blank', 'saknad kontakt lämnar produkt och namn');

$orphan = $stored;
$orphan['contact_id'] = 'saknas-kontakt';
$paintedOrphan = jeab_contacts_sole_paint_order($orphan);
bl_ok(($paintedOrphan['address'] ?? '') === 'Dimvägen 8', 'föräldralös kontakt målar inte tom adress');

$contact = jeab_kontakt_find_or_create([
    'name' => 'Testkund Kontakt',
    'email' => 'kontakt@example.test',
    'phone' => '0700000088',
    'address' => '',
]);
$linked = $stored;
$linked['contact_id'] = (string) ($contact['id'] ?? '');
$paintedLinked = jeab_contacts_sole_paint_order($linked);
bl_ok(($paintedLinked['address'] ?? '') === '', 'kontakt utan gata visas tom');
bl_ok(str_contains((string) ($paintedLinked['huvudsokande'] ?? ''), 'Testkund Kontakt'), 'kontaktens namn används när det finns');

$html = jeab_ordrar_v3_html($painted, 'jarfalla', $rid, $store);
bl_ok(str_contains($html, 'Dimvägen 8'), 'vyn visar orderns adress');
bl_ok(str_contains($html, 'Batteri'), 'vyn visar produkten');
bl_ok(str_contains($html, 'Vattenfall'), 'vyn visar nätägare');
bl_ok(str_contains($html, 'class="v3-textlink"') && str_contains($html, 'ordrar.fortnox_utan_bokning'), 'U15: utan bokning är en textlänk med samma åtgärd');
$primary = strpos($html, '>Skapa order<');
$link = strpos($html, 'class="v3-textlink"');
bl_ok($primary !== false && $link !== false && $primary < $link && substr_count($html, 'data-v3-primary="bekrafta"') === 1, 'U15: Bekräfta har en primär knapp och länken under');

$rowHtml = jeab_ordrar_board_row_html($stored, 'jarfalla', $store, ['explicit' => true, 'who' => '', 'job' => '']);
bl_ok(str_contains($rowHtml, 'Dimvägen 8') && str_contains($rowHtml, '#steg-bekrafta'), 'listan visar adressen och länkar till Bekräfta');
bl_ok(str_contains($rowHtml, 'data-name="') && str_contains($rowHtml, 'Dimvägen 8'), 'söktexten innehåller orderns egen adress');

jeab_purchase_put(['reminder_id' => $rid, 'booking_id' => 4242]);
$stored['booking_id'] = 4242;
$blanked = $stored;
$blanked['address'] = '';
$blanked['huvudsokande_address'] = '';
$blanked['gata'] = '';
$blanked['kind'] = '';
$blanked['natbolag'] = '';
$blanked['huvudsokande'] = '';
$remembered = jeab_ordrar_remember_leveransdatum($blanked, ['ok' => false, 'status' => 'fel', 'error' => 'DeliveryDate nekades']);
$again = jeab_purchase_get($rid);
bl_ok(is_array($again) && ($again['address'] ?? '') === 'Dimvägen 8' && ($again['kind'] ?? '') === 'batteri', 'leveransdatum skriver inte tomma fält över ordern');
bl_ok(($again['natbolag'] ?? '') === 'Vattenfall' && (int) ($again['booking_id'] ?? 0) === 4242, 'nätägare och booking_id står kvar');
bl_ok(str_contains((string) ($remembered['fortnox_leverans_error'] ?? ''), 'DeliveryDate'), 'leveransfelet sparas');

$kept = jeab_ordrar_keep_stored_fields(
    ['kind' => 'batteri', 'natbolag' => 'Vattenfall', 'address' => 'Dimvägen 29'],
    ['kind' => '', 'natbolag' => '', 'address' => '', 'postnummer' => '177 55']
);
bl_ok($kept['kind'] === 'batteri' && $kept['natbolag'] === 'Vattenfall' && $kept['address'] === 'Dimvägen 29' && $kept['postnummer'] === '177 55', 'prepare-sparning tömmer inte produkt, nätägare eller adress');

$beforeScan = jeab_purchase_get($rid);
$scan = jeab_ordrar_blank_scan(['dry' => 0]);
$afterScan = jeab_purchase_get($rid);
bl_ok(($scan['dry'] ?? 0) === 1 && ($scan['read_only'] ?? 0) === 1 && ($scan['ignored_dry'] ?? 0) === 1, 'blank_scan är torr även när dry=0');
bl_ok($beforeScan === $afterScan, 'blank_scan skriver inte ordern');
bl_ok(in_array($rid, $scan['ids'] ?? [], true), 'order utan contact_id listas');
$hit = null;
foreach ($scan['rows'] ?? [] as $item) {
    if ((int) ($item['id'] ?? 0) === $rid) {
        $hit = $item;
    }
}
bl_ok(is_array($hit) && ($hit['contact'] ?? '') === 'missing' && ($hit['empty'] ?? []) === [], 'komplett order med saknad kontakt flaggas bara som missing');

$emptyRow = $stored;
$emptyRow['reminder_id'] = $rid + 50;
$emptyRow['kind'] = '';
$emptyRow['natbolag'] = '';
$emptyRow['address'] = '';
$emptyRow['huvudsokande_address'] = '';
$emptyRow['gata'] = '';
$emptyRow['ort'] = '';
$emptyRow['contact_id'] = 'borta-id';
jeab_purchase_put($emptyRow);
$scan2 = jeab_ordrar_blank_scan([]);
$hit2 = null;
foreach ($scan2['rows'] ?? [] as $item) {
    if ((int) ($item['id'] ?? 0) === $rid + 50) {
        $hit2 = $item;
    }
}
bl_ok(is_array($hit2) && in_array('kind', $hit2['empty'], true) && in_array('address', $hit2['empty'], true) && in_array('natagare', $hit2['empty'], true) && ($hit2['contact'] ?? '') === 'orphan', 'tom produkt, adress och nätägare plus föräldralös kontakt listas');

$denied = jeab_ordrar_blank_scan_dispatch([]);
bl_ok(($denied['status'] ?? 0) === 401 && empty($denied['ok']), 'blank_scan utan token skriver inte');

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
bl_ok(str_contains($hook, "'ordrar.blank_scan'") && str_contains($hook, 'jeab_ordrar_blank_scan_dispatch'), 'hook.php tar emot ordrar.blank_scan');

$page = (string) file_get_contents(dirname(__DIR__) . '/src/pages/ordrar.php');
bl_ok(str_contains($page, 'jeab_ordrar_page_size()') && str_contains($page, 'Visa fler'), 'listan visar en sida och Visa fler i stället för att rita 500');

$icon = jeab_ordrar_v3_icon('refresh');
bl_ok(str_contains($icon, 'width="15"') && str_contains($icon, 'height="15"'), 'uppdateringsikonen har egen storlek');

$js = (string) file_get_contents(dirname(__DIR__) . '/assets/js/ordrar-v3.js');
bl_ok(str_contains($js, 'function openHashStep') && str_contains($js, "getElementById(hash.slice(1))") && str_contains($js, 'window.scrollTo'), 'hash #steg- scrollar till steget');

$shell = (string) file_get_contents(dirname(__DIR__) . '/assets/js/shell.js');
bl_ok(str_contains($shell, 'function shellUrl') && str_contains($shell, '#steg-[a-z]+') && str_contains($shell, 'shellUrl(url)'), 'skalet behåller #steg- i adressen');
bl_ok(str_contains($shell, 'jeabOrdrarV3Mount()'), 'redan laddad v3-skript monteras om så hash kan scrolla');

$css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/ordrar-v3.css');
bl_ok(str_contains($css, '.ov3 .v3-textlink') && str_contains($css, 'max-width: 15px'), 'textlänk och ikonstorlek finns i css');

echo "OK\n";
