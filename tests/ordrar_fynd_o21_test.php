<?php

declare(strict_types=1);

/**
 * Ordrar-fynd som går att stänga utan ny Fortnox-skrivning och utan kundutskick.
 * Fortnox anropas inte. Testkund finns bara som namn på ordern.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-ordrar-fynd-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
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
$purchasePdo = new PDO('sqlite:' . $tmp . '/purchases.sqlite');
$purchasePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_PURCHASES_PDO'] = $purchasePdo;
jeab_purchases_store_ensure($purchasePdo);
jeab_ordrar_external_live(false);
date_default_timezone_set('Europe/Stockholm');

function fy_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
$beforeBooks = count($store->listAllBookings());

$jar = $store->insertReminder([
    'title' => 'Laddbox',
    'company' => 'jarfalla',
    'assignee' => 'Elin',
    'customer_name' => 'Testkund Fynd',
    'email' => 'fynd@example.test',
    'phone' => '0701000093',
    'address' => 'Testgatan 1',
    'notes' => 'laddbox',
    'tags' => ['vunnen'],
]);
$purchase = jeab_ordrar_vunnen_purchase($store, (int) $jar['id'], $jar);
fy_ok((int) ($purchase['booking_id'] ?? 0) === 0, 'öppning skapar ingen bokning');
fy_ok(count($store->listAllBookings()) === $beforeBooks, 'föreslagen tid skriver ingen bokning');
$slot = jeab_ordrar_proposed_slot($store, $purchase);
fy_ok(is_array($slot) && ($slot['who'][0] ?? '') === 'Elin', 'Järfälla visar Elins första lediga tid');
fy_ok(is_array($slot) && preg_match('/\d{2}:\d{2}/', (string) $slot['starts_at']) === 1, 'sloten har klockslag');
$rid = (int) $purchase['reminder_id'];
$step = ['id' => 'bekrafta', 'state' => 'todo', 'n' => 1, 'title' => 'Bekräfta', 'short' => 'Bekräfta', 'reason' => ''];
$bekrafta = jeab_ordrar_v3_bekrafta_html($purchase, 'jarfalla', $rid, $step, $store, $jar);
fy_ok(str_contains($bekrafta, 'Föreslagen tid') && str_contains($bekrafta, 'data-proposed-slot='), 'Bekräfta visar tiden före bokning');
fy_ok(str_contains($bekrafta, 'name="install_date"') && str_contains($bekrafta, 'form="v3-f-bekrafta"') && str_contains($bekrafta, 'id="v3-bekrafta-install"'), 'Bekräfta kan byta lucka med samma datumväljare som Boka');
fy_ok(count($store->listAllBookings()) === $beforeBooks, 'Bekräfta-sidan bokar inte');

$sol = jeab_purchase_put([
    'reminder_id' => $rid,
    'company' => 'jarfalla',
    'kind' => 'sol',
    'customer' => 'Testkund Fynd',
    'fortnox_order' => '9001',
    'natbolag' => 'E.ON',
    'anlaggnings_id' => '735999123456789012',
    'kwh' => '10',
    'vaxelriktare' => 'Sigenergy 12 kW TP2',
    'booking_id' => 0,
    'steps' => [],
]);
fy_ok(jeab_ordrar_v3_next_action($sol, $store)['key'] === 'foranmalan', 'nästa steg är föranmälan');
$list = jeab_ordrar_board_row_html($sol, 'jarfalla', $store);
fy_ok(str_contains($list, '#steg-foranmalan'), 'listan öppnar Föranmälan');

$ladd = $sol;
$ladd['kind'] = 'laddbox';
$ladd['fortnox_order'] = '9002';
fy_ok(jeab_ordrar_v3_next_action($ladd, $store)['key'] === 'faktura', 'elbilsladdare med order har fakturering näst');
fy_ok(str_contains(jeab_ordrar_board_row_html($ladd, 'jarfalla', $store), '#steg-faktura'), 'listan öppnar Fakturering');

$draft = jeab_ordrar_save_foranmalan_status(['id' => $rid, 'foranmalan_status' => 'B']);
$draftSteps = is_array($draft['steps'] ?? null) ? $draft['steps'] : [];
fy_ok((string) ($draft['foranmalan_status'] ?? '') === 'B' && trim((string) ($draftSteps['spec'] ?? '')) !== '' && trim((string) ($draftSteps['foranmald'] ?? '')) === '', 'utkast gör steget klart utan att skicka');
fy_ok(jeab_ordrar_foranmalan_wizard_done($draft), 'utkast räknas som klar föranmälan');
$manual = jeab_ordrar_save_foranmalan_status(['id' => $rid, 'foranmalan_status' => 'C']);
$manualSteps = is_array($manual['steps'] ?? null) ? $manual['steps'] : [];
fy_ok(trim((string) ($manualSteps['foranmald'] ?? '')) === '' && trim((string) ($manualSteps['bestalld'] ?? '')) === '', 'manuellt stämplar varken föranmäld eller beställd');

$sol['foranmalan_status'] = 'A';
$sol['anlaggnings_id'] = '735999';
$sol['steps'] = [];
jeab_purchase_put($sol);
$short = '';
try {
    jeab_ordrar_save_foranmalan([
        'id' => $rid,
        'intent' => 'send',
        'natbolag' => 'E.ON',
        'anlaggnings_id' => '735999',
        'kwh' => '10',
    ]);
} catch (InvalidArgumentException $e) {
    $short = $e->getMessage();
}
fy_ok(str_contains($short, '18 siffror'), 'för kort anläggnings-id stoppar skicket, fick ' . $short);
$kept = jeab_purchase_get($rid);
fy_ok(is_array($kept) && (string) ($kept['fortnox_order'] ?? '') === '9001', 'nekad föranmälan lämnar ordern');

$withBooking = $kept;
$withBooking['booking_id'] = 4242;
$remembered = jeab_ordrar_remember_leveransdatum($withBooking, [
    'ok' => false,
    'status' => 'fel',
    'error' => 'DeliveryDate nekades för 19800101-1234',
]);
fy_ok((int) ($remembered['booking_id'] ?? 0) === 4242, 'leveransfel behåller booking_id');
fy_ok(str_contains((string) ($remembered['fortnox_leverans_error'] ?? ''), 'DeliveryDate') && !str_contains((string) ($remembered['fortnox_leverans_error'] ?? ''), '19800101'), 'leveransfelet visas utan personnummer');
$cleared = jeab_ordrar_remember_leveransdatum($remembered, ['status' => 'satt']);
fy_ok(trim((string) ($cleared['fortnox_leverans_error'] ?? '')) === '' && (int) ($cleared['booking_id'] ?? 0) === 4242, 'lyckat leveransdatum tar bort felet och behåller bokningen');

$open = $cleared;
$open['kind'] = 'sol';
$open['fortnox_order'] = '9001';
$open['booking_id'] = 0;
$open['foranmalan_status'] = 'A';
$open['steps'] = [];
$openHtml = jeab_ordrar_v3_html($open, 'jarfalla', $rid, $store);
fy_ok(str_contains($openHtml, 'inputmode="decimal"') && str_contains($openHtml, 'name="kwh"') && !preg_match('/name="kwh"[^>]*type="number"/', $openHtml), 'kWh är fritext med decimal-tangentbord');
fy_ok(str_contains($openHtml, 'Inget skickas till nätbolaget förrän du trycker på knappen'), 'föranmälan startar inte av sig själv');
fy_ok(str_contains($openHtml, 'aria-disabled="true"'), 'låsta steg går inte att öppna som vanliga länkar');
$errRow = $open;
$errRow['kind'] = 'laddbox';
$errRow['fortnox_order'] = '';
$errRow['booking_id'] = 0;
$errRow['fortnox_error'] = 'Fortnox avvisade ordern';
$fxHtml = jeab_ordrar_v3_html($errRow, 'jarfalla', $rid, $store);
fy_ok(str_contains($fxHtml, 'Fortnox-fel') && str_contains($fxHtml, 'is-danger'), 'Fortnox-fel ger röd faktureringsstatus');
fy_ok(str_contains($fxHtml, 'data-v3-utan-bokning=') && substr_count($fxHtml, 'id="v3-mbar-utan"') === 1, 'utan bokning finns kvar, bottenfältet speglar den inte');

$confirm = jeab_ordrar_v3_faktura_html($cleared, 'jarfalla', $rid, [
    'id' => 'faktura',
    'state' => 'todo',
    'n' => 4,
    'title' => 'Fakturering',
    'short' => 'Faktura',
    'reason' => '',
]);
fy_ok(str_contains($confirm, 'faktura_bekrafta') && str_contains($confirm, 'data-v3-hold="1"') && str_contains($confirm, 'Raderna nedan är de som skrivs till Fortnox'), 'Skapa faktura sparar Fortnox-raderna efter Ångra-fönstret');
fy_ok(str_contains($confirm, 'inputmode="numeric"'), 'artikelnr har siffer-tangentbord');

$v3 = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar_v3.php');
fy_ok(str_contains($v3, 'function jeab_ordrar_v3_kundbekraftelse_status') && !str_contains($v3, 'jeab_booking_notify_customer'), 'kundbekräftelsen visas utan att skicka');
$js = (string) file_get_contents(dirname(__DIR__) . '/assets/js/ordrar-v3.js');
fy_ok(str_contains($js, 'DOMParser') && str_contains($js, 'is-locked') && !str_contains($js, 'data-v3-plomb-ok'), 'sparning byter vy utan hel omladdning och låsta steg toastas');
fy_ok(str_contains($js, 'tiden bokad') && str_contains($js, "btn.textContent = 'Ångra'") && str_contains($js, 'booking.cancel'), 'toast tiden bokad har Ångra');
fy_ok(str_contains($js, 'aidDigits.length !== 18'), 'klienten kräver 18 siffror');
$css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/ordrar-v3.css');
fy_ok(str_contains($css, 'min-height: 44px') && str_contains($css, 'max-width: 390px') && str_contains($css, 'max-width: 900px'), 'träffytor och smala bredder finns i css');

echo "ALL OK\n";
