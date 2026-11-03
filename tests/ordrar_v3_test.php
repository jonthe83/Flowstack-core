<?php

declare(strict_types=1);

/**
 * Ordrar v3 (ny vy, ?v=3): vyval, stegens läge (Låst / Att göra / Klar) ur ordern
 * och att varje läge ritar rätt knapp – en grön knapp per steg, ingen knapp när steget är klart.
 */

$tmp = sys_get_temp_dir() . '/jeab-ordrar-v3-' . getmypid();
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

function v3_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function v3_states(array $row): array
{
    $out = [];
    foreach (jeab_ordrar_v3_steps($row) as $step) {
        $out[$step['id']] = $step['state'];
    }
    return $out;
}

// —— vyval ——
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_save_path($tmp);
    @session_start();
}
unset($_SESSION[JEAB_ORDRAR_V3_SESSION]);
v3_assert(jeab_ordrar_v3_mode([]) === true, 'nya vyn är standard utan v=');
$_SESSION[JEAB_ORDRAR_V3_SESSION] = 3;
v3_assert(jeab_ordrar_v3_mode([]) === true && !isset($_SESSION[JEAB_ORDRAR_V3_SESSION]), 'gammalt sessionsvärde 3 tolkas som v3 och städas');
$t0 = 1_800_000_000;
v3_assert(jeab_ordrar_v3_mode(['v' => '2'], $t0) === false, '?v=2 visar gamla vyn');
v3_assert(jeab_ordrar_v3_mode([], $t0 + 600) === false, 'gamla vyn håller vid POST/byte av steg inom 30 min');
v3_assert(jeab_ordrar_v3_mode([], $t0 + 600 + 1700) === false, 'glidande: varje anrop förlänger');
v3_assert(jeab_ordrar_v3_mode([], $t0 + 600 + 1700 + 1801) === true && !isset($_SESSION[JEAB_ORDRAR_V3_SESSION]), 'efter 30 min utan anrop: tillbaka till v3 (fastnar inte)');
jeab_ordrar_v3_mode(['v' => 'old'], $t0);
v3_assert(jeab_ordrar_v3_mode(['v' => '3'], $t0 + 5) === true && jeab_ordrar_v3_mode([], $t0 + 10) === true, '?v=3 går direkt tillbaka');
$_SESSION[JEAB_ORDRAR_V3_SESSION] = 2;
v3_assert(jeab_ordrar_v3_mode([]) === true, 'gammalt sessionsvärde 2 utan utgång fastnar inte');
v3_assert(jeab_ordrar_v3_supports(['kind' => 'laddbox']) && jeab_ordrar_v3_supports(['kind' => '']), 'laddbox och ej vald produkt ritas i v3');
v3_assert(jeab_ordrar_v3_supports(['kind' => 'batteri']), 'batteri ritas i v3');
v3_assert(jeab_ordrar_v3_supports(['kind' => 'sol']), 'sol har ny vy');

// —— läge ur ordern ——
$base = [
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
];
v3_assert(v3_states($base) === ['bekrafta' => 'todo', 'faktura' => 'locked', 'stang' => 'locked'], 'ny laddbox: Bekräfta att göra, resten låst');

$saved = $base;
$saved['bekraftad_saved'] = true;
$saved['steps']['fatt_alla_uppgifter'] = '2026-10-01 10:00:00';
v3_assert(v3_states($saved)['bekrafta'] === 'todo', 'Spara/autospar gör inte Bekräfta klar');

$order = $base;
$order['fortnox_order'] = '1874';
$order['steps']['order_skriven'] = '2026-10-02 09:00:00';
v3_assert(jeab_ordrar_fakturering_done($order) === true, 'gamla regeln räknar laddbox-Fakturering som klar med bara Fortnox-order (buggen)');
v3_assert(v3_states($order) === ['bekrafta' => 'klar', 'faktura' => 'todo', 'stang' => 'locked'], 'v3: Fortnox-order ger Bekräfta klar men Fakturering att göra');

$invoiced = $order;
$invoiced['fortnox_invoice'] = '13463';
$invoiced['steps']['faktura_skapad'] = '2026-10-08 14:00:00';
v3_assert(v3_states($invoiced) === ['bekrafta' => 'klar', 'faktura' => 'klar', 'stang' => 'todo'], 'faktura finns: Stäng att göra');

$closed = $invoiced;
$closed['allt_klart'] = true;
$closed['closed_at'] = '2026-10-09 08:00:00';
v3_assert(v3_states($closed)['stang'] === 'klar', 'stängt ärende: Stäng klar');

$noKind = $base;
$noKind['kind'] = '';
$noKind['fortnox_order'] = '';
v3_assert(v3_states($noKind)['bekrafta'] === 'todo', 'utan produkt är Bekräfta aldrig klar');

// —— ritning ——
$html = jeab_ordrar_v3_html($base, 'jarfalla', 9101);
v3_assert(substr_count($html, 'data-v3-primary=') === 1, 'en grön knapp när bara Bekräfta är att göra');
v3_assert(str_contains($html, '>Skapa order och boka<'), 'knappen heter «Skapa order och boka»');
v3_assert(str_contains($html, 'name="action" value="ordrar.slutfor"'), 'Skapa order och boka postar ordrar.slutfor');
$utanAt = strpos($html, 'class="v3-textlink"');
$bokaAt = strpos($html, '>Skapa order och boka<');
v3_assert($utanAt !== false && $bokaAt !== false && $bokaAt < $utanAt, '«Skapa Fortnox-order (utan bokning)» är en textlänk under «Skapa order och boka»');
v3_assert(!str_contains($html, 'v3-btn is-sec" name="action" value="ordrar.fortnox_utan_bokning"'), 'utan bokning är inte en andra knapp');
v3_assert(str_contains($html, 'name="action" value="ordrar.fortnox_utan_bokning"') && substr_count($html, 'data-v3-utan-bokning=') === 1, 'den postar ordrar.fortnox_utan_bokning');
$cssSrc = (string) file_get_contents(dirname(__DIR__) . '/assets/css/ordrar-v3.css');
$hideAct = strpos($cssSrc, '.ov3 .v3-card .v3-actrow { display: none; }');
$showAct = strpos($cssSrc, '.ov3 #steg-bekrafta .v3-actrow {');
v3_assert($hideAct !== false && $showAct !== false && $showAct > $hideAct, 'smal skärm visar Bekräfta-knappen i kortet');
v3_assert(str_contains($cssSrc, '#steg-bekrafta .v3-actrow .v3-under'), 'textlänken ligger under den stora knappen på smal skärm');
v3_assert(str_contains($cssSrc, '.ov3 .v3-calbtn { width: 44px; height: 44px;'), 'kalenderknappen på Objekt är 44 px på mobil');
v3_assert(str_contains($cssSrc, '@media (max-width: 390px)') && str_contains($cssSrc, 'scroll-padding-top: 168px'), 'på 390 px lämnar sticky huvud rum för fält');
v3_assert(substr_count($html, 'data-v3-primary=') === 1, 'den extra knappen är inte en andra grön knapp');
v3_assert(str_contains($html, 'name="action" value="ordrar.spara"'), 'Spara postar ordrar.spara');
v3_assert(str_contains($html, 'name="huvudsokande"') && str_contains($html, 'name="huvudsokande_personnummer"') && str_contains($html, 'name="owner_name[]"') && str_contains($html, 'name="fastighetsbeteckning"'), 'Bekräfta har samma dolda fält som gamla vyn');
$v3php = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar_v3.php');
v3_assert(str_contains($v3php, 'data-field="name"') && str_contains($v3php, '" autocomplete="name">'), 'ägarnamn har autocomplete name');
v3_assert(!str_contains($html, 'Skapa faktura<'), 'Skapa faktura syns inte innan Bekräfta är klar');
v3_assert(!str_contains($html, 'v3-f-stang'), 'Stäng-formuläret finns inte när steget är låst');
v3_assert(str_contains($html, 'Kräver att Bekräfta är klar') && str_contains($html, 'Kräver att fakturan är skapad'), 'låsta steg säger vad som krävs');
v3_assert(str_contains($html, 'name="action" value="ordrar.objekt"'), 'Objekt sparar via ordrar.objekt');
v3_assert(str_contains($html, 'Radera ärendet…') && substr_count($html, 'ordrar.delete') === 1, 'Radera finns bara i «…»-menyn');
v3_assert(str_contains($html, 'data-booking-id="0"') && !str_contains($html, 'id="v3-f-avboka"'), 'obokad order har inget avboka-formulär');
$jsV3 = (string) file_get_contents(dirname(__DIR__) . '/assets/js/ordrar-v3.js');
v3_assert(str_contains($jsV3, 'tiden bokad') && str_contains($jsV3, "btn.textContent = 'Ångra'") && str_contains($jsV3, 'booking.cancel'), 'toast tiden bokad har Ångra');
v3_assert(str_contains($html, 'href="#steg-bekrafta"') && str_contains($html, 'href="#steg-faktura"'), 'menyn hoppar inom sidan');
v3_assert(str_contains($html, '19800101-••••'), 'personnummer visas maskat tills man trycker Visa');
v3_assert(!str_contains($html, 'name="objekt_personnummer"'), 'Objekt har inget extra Personnr-fält');
v3_assert(substr_count($html, 'Personnummer:') === 1, 'personnummer står bara i ägarkortet');

$html = jeab_ordrar_v3_html($order + ['fortnox_order_rows' => [[
    'RowId' => '1', 'ArticleNumber' => '100', 'Description' => 'Laddbox', 'HouseWorkType' => 'CHARGINGSTATIONELECTRICVEHICLE', 'DeliveredQuantity' => '1', 'Price' => '9990',
]], 'fortnox_att_betala' => '7 968 kr'], 'jarfalla', 9101);
v3_assert(substr_count($html, 'data-v3-primary=') === 1, 'en grön knapp när Fakturering är att göra');
v3_assert(str_contains($html, '>Skapa faktura<') && str_contains($html, 'name="intent" value="faktura"'), 'Skapa faktura postar intent=faktura');
v3_assert(str_contains($html, 'name="intent" value="save"'), 'Spara skriver raderna utan faktura');
v3_assert(str_contains($html, 'name="fx_desc[]"') && str_contains($html, 'name="fx_rowid[]"'), 'fakturaraderna har samma fältnamn som gamla vyn');
v3_assert(!str_contains($html, '>Skapa order och boka<'), 'Bekräfta klar: ingen knapp, bara sammanfattning');
v3_assert(!str_contains($html, 'ordrar.fortnox_utan_bokning'), 'Bekräfta klar: ingen knapp för Fortnox utan bokning');
v3_assert(str_contains($html, 'Fortnox-order skapad'), 'Bekräfta-sammanfattningen visar Fortnox-ordern');
v3_assert(str_contains($html, 'apps5.fortnox.se/app/') && str_contains($html, '/order/order/1874'), 'länk till Fortnox-ordern');
v3_assert(!str_contains($html, 'v3-f-stang'), 'Stäng ärendet syns inte förrän fakturan finns');

$bookedHtml = jeab_ordrar_v3_html($order + ['booking_id' => 4242], 'jarfalla', 9101);
v3_assert(str_contains($bookedHtml, 'data-booking-id="4242"'), 'bokad order har booking-id för Ångra');
v3_assert(str_contains($bookedHtml, 'id="v3-f-avboka"') && str_contains($bookedHtml, 'name="action" value="booking.cancel"'), 'Ångra postar booking.cancel');

$html = jeab_ordrar_v3_html($invoiced + ['fortnox_att_betala' => '7 968 kr'], 'jarfalla', 9101);
v3_assert(str_contains($html, 'Faktura 13463 skapad'), 'Fakturering klar visar fakturanumret');
v3_assert(str_contains($html, '/kf/invoice/13463'), 'länk till fakturan');
v3_assert(!str_contains($html, 'name="intent"'), 'Fakturering klar har ingen Spara/Skapa');
v3_assert(str_contains($html, 'v3-f-stang') && str_contains($html, 'name="v3" value="1"'), 'Stäng ärendet finns när fakturan finns');
v3_assert(substr_count($html, 'data-v3-primary=') === 1, 'en grön knapp: Stäng ärendet');

$html = jeab_ordrar_v3_html($closed, 'jarfalla', 9101);
v3_assert(substr_count($html, 'data-v3-primary=') === 0, 'stängt ärende: inga gröna knappar');
v3_assert(str_contains($html, 'Ärendet stängt'), 'stängt ärende sammanfattas');

$html = jeab_ordrar_v3_html($base, 'jarfalla', 9101, null, 'Fortnox svarade inte');
v3_assert(str_contains($html, 'Kunde inte spara. Fortnox svarade inte'), 'fel efter vanlig POST visas med «Kunde inte spara» och orsaken');

// —— Fortnox-order utan bokning ——
if (!is_dir($tmp . '/rot') && !mkdir($tmp . '/rot', 0775, true) && !is_dir($tmp . '/rot')) {
    fwrite(STDERR, "FAIL fortnox config dir\n");
    exit(1);
}
if (!is_dir($tmp . '/fortnox') && !mkdir($tmp . '/fortnox', 0775, true) && !is_dir($tmp . '/fortnox')) {
    fwrite(STDERR, "FAIL fortnox token dir\n");
    exit(1);
}
file_put_contents($tmp . '/rot/fortnox_config.php', "<?php\nreturn ['clientId' => 'test', 'clientSecret' => 'test', 'articles' => []];\n");
file_put_contents($tmp . '/fortnox/tokens.json', json_encode([
    'Jarfalla' => ['refresh_token' => 'test-refresh-jarfalla'],
    'Malmo' => ['refresh_token' => 'test-refresh-malmo'],
], JSON_UNESCAPED_UNICODE));
$orderPosts = 0;
$GLOBALS['_JEAB_WEBSHOP_FORTNOX_FIND'] = static fn (array $probe): array => [];
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, mixed $body) use (&$orderPosts): array {
    unset($body);
    $route = strtok($path, '?');
    $route = is_string($route) && $route !== '' ? $route : $path;
    if ($method === 'POST' && $route === '/orders') {
        $orderPosts++;
        return ['Order' => [
            'DocumentNumber' => '4242',
            'CustomerNumber' => '42',
            'TotalToPay' => 9990,
            'OrderRows' => [[
                'Description' => 'Laddbox',
                'Price' => 19890,
                'DeliveredQuantity' => 1,
                'HouseWork' => true,
                'HouseWorkType' => 'CHARGINGSTATIONELECTRICVEHICLE',
            ]],
        ]];
    }
    if ($method === 'GET' && str_starts_with($route, '/customers')) {
        return ['Customers' => [['CustomerNumber' => '42', 'Active' => true]]];
    }
    if ($method === 'GET' && $route === '/orders/4242') {
        return ['Order' => [
            'DocumentNumber' => '4242',
            'CustomerNumber' => '42',
            'OrderRows' => [[
                'Description' => 'Laddbox',
                'Price' => 19890,
                'DeliveredQuantity' => 1,
                'HouseWork' => true,
                'HouseWorkType' => 'CHARGINGSTATIONELECTRICVEHICLE',
            ]],
        ]];
    }
    if ($method === 'GET' && str_starts_with($route, '/taxreductions')) {
        return [
            'TaxReductions' => [[
                'Id' => '1',
                'ReferenceNumber' => '4242',
                'PropertyDesignation' => 'Testet 1:1',
                'CustomerName' => 'Nils Nilsson',
                'SocialSecurityNumber' => '19800101-0000',
            ]],
            'MetaInformation' => ['@TotalPages' => 1],
        ];
    }
    return [];
};
$post = [
    'id' => 9201,
    'kind' => 'laddbox',
    'pris' => '9 990',
    'huvudsokande' => 'Nils Nilsson',
    'huvudsokande_personnummer' => '19800101-0000',
    'fastighetsbeteckning' => 'Testet 1:1',
    'install_what' => 'Laddbox',
];
jeab_purchase_put([
    'reminder_id' => 9201,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => 'Nils Nilsson',
    'address' => 'Testgatan 1, 175 00 Järfälla',
    'huvudsokande' => 'Nils Nilsson',
    'personnummer' => '19800101-0000',
    'fastighetsbeteckning' => 'Testet 1:1',
    'pris' => 9990,
    'steps' => [],
]);
$created = jeab_ordrar_fortnox_utan_bokning($post);
v3_assert(trim((string) ($created['fortnox_order'] ?? '')) === '4242', 'knappen skapar Fortnox-order 4242');
v3_assert($orderPosts === 1, 'Fortnox anropas med en order, fick ' . $orderPosts);
v3_assert((int) ($created['booking_id'] ?? 0) === 0, 'ingen kalenderbokning');
v3_assert(trim((string) ((is_array($created['steps'] ?? null) ? $created['steps'] : [])['bokad_tid'] ?? '')) === '', 'ingen bokad tid stämplas');
v3_assert(!is_file($GLOBALS['_JEAB_CALENDAR_LOG_PATH']), 'kalenderloggen skrivs inte');
v3_assert(v3_states($created) === ['bekrafta' => 'klar', 'faktura' => 'todo', 'stang' => 'locked'], 'Fortnox-order utan tid låser upp Fakturering');
$doneHtml = jeab_ordrar_v3_html($created, 'jarfalla', 9201);
v3_assert(str_contains($doneHtml, 'Ingen tid bokad') && str_contains($doneHtml, 'Fortnox-order 4242'), 'sammanfattningen visar ordern och att ingen tid bokats');
v3_assert(str_contains($doneHtml, 'data-step="faktura" data-state="todo"'), 'Fakturering är att göra');
$again = jeab_ordrar_fortnox_utan_bokning($post);
v3_assert(trim((string) ($again['fortnox_order'] ?? '')) === '4242' && $orderPosts === 1, 'andra klicket skapar ingen ny Fortnox-order');
jeab_purchase_put([
    'reminder_id' => 9202,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => 'Nils Nilsson',
    'address' => 'Testgatan 1, 175 00 Järfälla',
    'pris' => 9990,
    'steps' => [],
]);
$refused = '';
try {
    jeab_ordrar_fortnox_utan_bokning([
        'id' => 9202,
        'kind' => 'laddbox',
        'pris' => '9990',
        'huvudsokande' => '',
        'huvudsokande_personnummer' => '',
        'fastighetsbeteckning' => '',
    ]);
} catch (InvalidArgumentException $e) {
    $refused = $e->getMessage();
}
v3_assert(str_contains($refused, 'personnummer') && str_contains($refused, 'fastighetsbeteckning'), 'saknat personnummer och fastighetsbeteckning nekas, fick ' . $refused);
$refusedRow = jeab_purchase_get(9202);
v3_assert(is_array($refusedRow) && trim((string) ($refusedRow['fortnox_order'] ?? '')) === '' && (int) ($refusedRow['booking_id'] ?? 0) === 0, 'nekad skapning skriver ingen order och bokar inte');
v3_assert(v3_states($refusedRow)['faktura'] === 'locked', 'Fakturering förblir låst när ordern nekas');
v3_assert($orderPosts === 1, 'nekad skapning anropar inte Fortnox-order');
$ordrarSrc = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar.php');
$utanStart = strpos($ordrarSrc, 'function jeab_ordrar_fortnox_utan_bokning');
$utanEnd = strpos($ordrarSrc, 'function jeab_ordrar_resolve_store');
$utanBody = $utanStart !== false && $utanEnd !== false ? substr($ordrarSrc, $utanStart, $utanEnd - $utanStart) : '';
v3_assert(str_contains($utanBody, 'jeab_ordrar_create_fortnox') && !str_contains($utanBody, 'jeab_ordrar_create_booking') && !str_contains($utanBody, 'mail') && !str_contains($utanBody, 'sms'), 'åtgärden anropar bara befintlig Fortnox-skapning');
$indexSrc = (string) file_get_contents(dirname(__DIR__) . '/index.php');
v3_assert(str_contains($indexSrc, "case 'ordrar.fortnox_utan_bokning':") && str_contains($indexSrc, 'finns redan.'), 'POST-åtgärden visar befintligt ordernummer');
$jsSrc = (string) file_get_contents(dirname(__DIR__) . '/assets/js/ordrar-v3.js');
v3_assert(str_contains($jsSrc, 'utanBokning') && str_contains($jsSrc, 'isPrimary || utanBokning'), 'klienten validerar knappen och bokar inte om den');
v3_assert(str_contains($jsSrc, 'Bekräfta-knappen sitter i kortet'), 'bottenfältet visar inte en andra Bekräfta-knapp');

// —— Stäng kräver faktura i v3 ——
jeab_purchase_put($order);
$thrown = '';
try {
    jeab_ordrar_stang(['id' => 9101, 'v3' => '1']);
} catch (InvalidArgumentException $e) {
    $thrown = $e->getMessage();
}
v3_assert(str_contains($thrown, 'Fakturan finns inte'), 'v3 kan inte stänga utan faktura');
$row = jeab_purchase_get(9101);
v3_assert(is_array($row) && empty($row['allt_klart']), 'ordern är inte stängd efter nekad Stäng');
$old = jeab_ordrar_stang(['id' => 9101]);
v3_assert(!empty($old['allt_klart']), 'gamla vyns Stäng beter sig som förut');

echo "ALL OK\n";
