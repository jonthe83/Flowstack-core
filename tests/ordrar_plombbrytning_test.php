<?php

declare(strict_types=1);

/**
 * Plombbrytning i Ordrar v3: en knapp i föranmälan för sol, batteri, laddbox och servicejobb.
 * Bekräftelse i v3-modalen krävs innan ordrar.plombbrytning postas en gång.
 * Inget skickas på riktigt: webhooks fångas.
 */

$tmp = sys_get_temp_dir() . '/jeab-plomb-' . bin2hex(random_bytes(4));
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
$GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
jeab_ordrar_external_live(false);
date_default_timezone_set('Europe/Stockholm');

function pb_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function pb_hooks(): array
{
    $out = [];
    foreach ($GLOBALS['_JEAB_BUG_WEBHOOKS'] ?? [] as $row) {
        if (is_array($row) && ($row['type'] ?? '') === 'ordrar.plombbrytning') {
            $out[] = $row;
        }
    }
    return $out;
}

$secretPath = jeab_bokmalen_ordrar_webhook_secret_path();
if (!is_dir(dirname($secretPath))) {
    mkdir(dirname($secretPath), 0775, true);
}
file_put_contents($secretPath, json_encode([
    'url' => 'https://bokmalen.example.invalid/hooks/jeab-ordrar',
    'key' => 'plomb-test-key',
], JSON_UNESCAPED_SLASHES));

$store = new JeabJsonStore($tmp . '/store.json');
$css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/ordrar-v3.css');
$js = (string) file_get_contents(dirname(__DIR__) . '/assets/js/ordrar-v3.js');
pb_assert(str_contains($css, '.ov3 .v3-btn.v3-plomb-btn') && str_contains($css, 'min-height: 44px') && str_contains($css, 'flex-wrap: wrap') && str_contains($css, 'width: 100%'), 'knappen är stor nog att trycka på, fyller bredden och radbryts');
pb_assert(!str_contains($js, 'Vill du föranmäla plombbrytning på mätartavlan hos nätägaren?') && str_contains($js, 'confirmBox('), 'plombbrytning har ingen extra dialog');
pb_assert(!str_contains($js, "data-v3-plomb-ok"), 'formuläret postar vid klick och väntar inte på en dialog');
pb_assert(!str_contains($js, "hasAttribute('data-v3-plomb')") && !str_contains($js, 'window.confirm') && !preg_match('/(?<!Box)\bconfirm\s*\(/', $js), 'ingen kryssruta och ingen webbläsardialog');

$labels = [
    'sol' => 'Sol',
    'batteri' => 'Batteri',
    'laddbox' => 'Elbilsladdare',
    'servicejobb' => 'Servicejobb',
];
foreach ($labels as $kind => $label) {
    $row = [
        'reminder_id' => 8800,
        'company' => 'jarfalla',
        'kind' => $kind,
        'customer' => 'Exempel Kund',
        'address' => 'Exempelgatan 4, 177 00 Järfälla',
        'huvudsokande' => 'Exempel Kund',
        'huvudsokande_address' => 'Exempelgatan 4, 177 00 Järfälla',
        'fastighetsbeteckning' => 'Exemplet 1:1',
        'pris' => 1000,
        'natbolag' => 'E.ON',
        'steps' => [],
    ];
    $html = jeab_ordrar_v3_html($row, 'jarfalla', 8800, $store);
    $card = '';
    if (preg_match('/id="steg-foranmalan".*$/s', $html, $m) === 1) {
        $card = $m[0];
        $next = strpos($card, 'id="steg-', 10);
        if ($next !== false) {
            $card = substr($card, 0, $next);
        }
    }
    pb_assert($card !== '' && str_contains($card, '>Plombbrytning</button>'), $label . ' visar knappen i föranmälan');
    pb_assert(str_contains($card, 'type="submit"') && str_contains($card, 'data-working="Skickar…"') && !str_contains($card, 'data-v3="plomb-ask"'), $label . ' postar direkt med upptagen-knapp');
    pb_assert(!str_contains($card, 'type="checkbox"'), $label . ' har ingen kryssruta');
    pb_assert(substr_count($html, 'name="plombbrytning"') === 1, $label . ' har fältet på ett ställe');
    pb_assert(str_contains($card, 'value="ordrar.plombbrytning"'), $label . ' postar på plomb-åtgärden');
    pb_assert(str_contains($html, 'data-v3="modal-cancel">Avbryt'), $label . ' använder mallens dialog med Avbryt');
    pb_assert(substr_count($html, 'name="kind"') === 3, $label . ' ändrar inte produktvalen i Bekräfta');
}

$statusRow = [
    'reminder_id' => 8801,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => 'Exempel Kund',
    'address' => 'Exempelgatan 4, 177 00 Järfälla',
    'huvudsokande' => 'Exempel Kund',
    'natbolag' => 'Vattenfall',
    'plombbrytning_sent' => true,
    'plombbrytning_sent_at' => '2026-10-19 11:40:08',
    'steps' => [],
];
$statusHtml = jeab_ordrar_v3_html($statusRow, 'jarfalla', 8801, $store);
pb_assert(str_contains($statusHtml, 'Plombbrytning begärd 19/10'), 'status visar Plombbrytning begärd DD/MM');
pb_assert(!str_contains($statusHtml, 'data-v3="plomb-ask"') && !str_contains($statusHtml, 'type="checkbox"') && !str_contains($statusHtml, 'name="plombbrytning"'), 'efter begäran finns ingen knapp som kan skicka igen');
pb_assert(array_keys(jeab_ordrar_v3_products()) === ['laddbox', 'batteri', 'sol'], 'Bekräfta har kvar tre valbara produkter');
foreach (['sol' => 'Solpaneler', 'batteri' => 'Batteri', 'laddbox' => 'Elbilsladdare', 'servicejobb' => 'Servicejobb'] as $kind => $board) {
    pb_assert(jeab_ordrar_board_product(['kind' => $kind]) === $board, 'listan visar ' . $board);
}
$svcSteps = array_map(static fn(array $s): string => $s['id'], jeab_ordrar_v3_steps(['kind' => 'servicejobb', 'steps' => []]));
$laddSteps = array_map(static fn(array $s): string => $s['id'], jeab_ordrar_v3_steps(['kind' => 'laddbox', 'steps' => []]));
$solSteps = array_map(static fn(array $s): string => $s['id'], jeab_ordrar_v3_steps(['kind' => 'sol', 'steps' => []]));
$batSteps = array_map(static fn(array $s): string => $s['id'], jeab_ordrar_v3_steps(['kind' => 'batteri', 'steps' => []]));
pb_assert($svcSteps === $laddSteps, 'servicejobb har samma steg som elbilsladdare');
pb_assert($solSteps === $batSteps && in_array('foranmalan', $solSteps, true) && in_array('bestall', $solSteps, true), 'sol och batteri behåller föranmälan och beställ');
$svcList = jeab_ordrar_lista_cells(['kind' => 'servicejobb', 'company' => 'jarfalla', 'steps' => []]);
$solList = jeab_ordrar_lista_cells(['kind' => 'sol', 'company' => 'jarfalla', 'steps' => []]);
pb_assert(($svcList['foran']['text'] ?? 'x') === '' && ($solList['progress']['total'] ?? 0) === 4 && ($svcList['progress']['total'] ?? 0) === 2, 'listan räknar servicejobb som kort flöde och sol som fyra steg');

function pb_order(JeabStore $store, string $kind, array $extra = []): array
{
    $reminder = jeab_create_reminder($store, 'Exempel Kund', null, 'Order', null, [
        'customer' => 'Exempel Kund',
        'company' => 'jarfalla',
        'assignee' => 'Roberto',
        'address' => 'Exempelgatan 4, 177 00 Järfälla',
        'notes' => 'Order',
    ]);
    return jeab_purchase_put(array_merge([
        'reminder_id' => (int) $reminder['id'],
        'company' => 'jarfalla',
        'kind' => $kind,
        'customer' => 'Exempel Kund',
        'address' => 'Exempelgatan 4, 177 00 Järfälla',
        'huvudsokande' => 'Exempel Kund',
        'huvudsokande_address' => 'Exempelgatan 4, 177 00 Järfälla',
        'fastighetsbeteckning' => 'Exemplet 1:1',
        'pris' => 1000,
        'natbolag' => 'E.ON',
        'anlaggnings_id' => '735999100000000018',
        'fortnox_order' => '45021',
        'install_date' => '2026-11-02',
        'booking_id' => 4242,
        'extra_konto' => '000111222',
        'steps' => [],
    ], $extra));
}

$allowed = ['address', 'anlaggnings_id', 'kind', 'natagare', 'natbolag', 'order_id', 'order_number', 'product', 'source', 'type'];
$forbidden = ['person' . 'nummer', 'pnr', 'kontonummer', 'account', 'bankgiro', 'clearing', '000111222'];

foreach ($labels as $kind => $label) {
    $GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
    $order = pb_order($store, $kind);
    $id = (int) $order['reminder_id'];
    $idle = jeab_ordrar_plombbrytning_save(['id' => $id]);
    pb_assert(pb_hooks() === [] && ($idle['_plomb_deliver'] ?? '') === 'idle', $label . ' utan bekräftelse ger ingen webhook');
    $first = jeab_ordrar_plombbrytning_save(['id' => $id, 'plombbrytning' => '1']);
    pb_assert(($first['_plomb_deliver'] ?? '') === 'sent', $label . ' köar en gång');
    $sentAt = (string) ($first['plombbrytning_sent_at'] ?? '');
    pb_assert($sentAt !== '' && ($first['plombbrytning_sent'] ?? false) === true, $label . ' sparar skickad-flagga och tid');
    $hooks = pb_hooks();
    pb_assert(count($hooks) === 1, $label . ' ger ett webhook-anrop');
    $payload = $hooks[0];
    $keys = array_keys($payload);
    sort($keys);
    pb_assert($keys === $allowed, $label . ' payload har bara adress, nätägare, anläggning, produkt och ordernummer');
    $blob = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
    $clean = true;
    foreach ($forbidden as $word) {
        if (str_contains(strtolower($blob), strtolower($word))) {
            $clean = false;
        }
    }
    pb_assert($clean, $label . ' payload saknar personligt id och kontonummer');
    pb_assert(($payload['product'] ?? '') === $label, $label . ' står som produkt');
    pb_assert(($payload['address'] ?? '') === 'Exempelgatan 4, 177 00 Järfälla', $label . ' har orderadressen');
    pb_assert(($payload['natbolag'] ?? '') === 'E.ON' && ($payload['natagare'] ?? '') === 'E.ON', $label . ' har nätägare');
    pb_assert(($payload['anlaggnings_id'] ?? '') === '735999100000000018', $label . ' har anläggnings-id när det finns');
    pb_assert(($payload['order_number'] ?? '') === '45021' && ($payload['type'] ?? '') === 'ordrar.plombbrytning', $label . ' har ordernummer och typen Bokmalen läser');
    pb_assert((string) ($first['install_date'] ?? '') === '2026-11-02' && (int) ($first['booking_id'] ?? 0) === 4242, $label . ' lämnar bokad tid orörd');
    $stamp = new DateTimeImmutable($sentAt, new DateTimeZone('Europe/Stockholm'));
    $after = jeab_ordrar_v3_html($first, 'jarfalla', $id, $store);
    pb_assert(str_contains($after, 'Plombbrytning begärd ' . $stamp->format('d/m')), $label . ' visar status efter bekräftelse');
    pb_assert(!str_contains($after, 'data-v3="plomb-ask"'), $label . ' byter ut knappen mot status');

    $again = jeab_ordrar_plombbrytning_save(['id' => $id, 'plombbrytning' => '1']);
    $off = jeab_ordrar_plombbrytning_save(['id' => $id]);
    $re = jeab_ordrar_plombbrytning_save(['id' => $id, 'plombbrytning' => '1']);
    pb_assert(count(pb_hooks()) === 1, $label . ' andra bekräftelsen ger inget nytt webhook-anrop');
    pb_assert(($again['_plomb_deliver'] ?? '') === 'kept' && ($re['_plomb_deliver'] ?? '') === 'kept' && ($off['_plomb_deliver'] ?? '') === 'kept', $label . ' andra anropet lämnar tillbaka sparad status');
    pb_assert(($again['plombbrytning_sent_at'] ?? '') === $sentAt && ($re['plombbrytning_sent_at'] ?? '') === $sentAt, $label . ' behåller samma tidsstämpel');
}

$GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
$bare = pb_order($store, 'sol', ['anlaggnings_id' => '', 'fortnox_order' => '']);
$bareSaved = jeab_ordrar_plombbrytning_save(['id' => (int) $bare['reminder_id'], 'plombbrytning' => '1']);
$bareHook = pb_hooks()[0] ?? [];
pb_assert(($bareHook['anlaggnings_id'] ?? 'saknas') === '', 'saknat anläggnings-id skickas tomt');
pb_assert(($bareHook['order_number'] ?? '') === (string) ($bare['reminder_id'] ?? ''), 'utan Fortnox-nummer används orderns id');
pb_assert(($bareSaved['_plomb_deliver'] ?? '') === 'sent', 'tomt anläggnings-id stoppar inte meddelandet');

$closed = pb_order($store, 'batteri', [
    'allt_klart' => true,
    'closed_at' => '2026-10-01 08:00:00',
    'install_date' => '2026-12-01',
    'booking_id' => 77,
]);
$closedBefore = jeab_purchase_get((int) $closed['reminder_id']);
$GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
$threw = false;
try {
    jeab_ordrar_plombbrytning_save(['id' => (int) $closed['reminder_id'], 'plombbrytning' => '1']);
} catch (InvalidArgumentException) {
    $threw = true;
}
$closedAfter = jeab_purchase_get((int) $closed['reminder_id']);
pb_assert($threw && pb_hooks() === [], 'stängt ärende köar inget');
pb_assert($closedAfter === $closedBefore, 'stängt ärende skrivs inte om');
pb_assert((string) ($closedAfter['install_date'] ?? '') === '2026-12-01' && (int) ($closedAfter['booking_id'] ?? 0) === 77, 'bokad tid på stängt ärende ligger kvar');

echo "OK  plombbrytning\n";
