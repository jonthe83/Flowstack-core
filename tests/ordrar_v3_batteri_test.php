<?php

declare(strict_types=1);

/**
 * Ordrar v3 för batteri: stegen Bekräfta → Föranmälan → (Boka) → Beställ → Fakturering → Stäng,
 * att Beställ inte blir klar av autosparet, att «Skicka igen» verkligen skickar föranmälan
 * igen (samma väg som första gången, fångad – inget skickas på riktigt), att Bokmalen kan
 * skicka läget hos nätbolaget, och att Beställt-sammanfattningen bara visar datum, nummer och kostnad.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-ordrar-v3-bat-' . bin2hex(random_bytes(4));
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
    'db' => [
        'driver' => 'json',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
$purchasePdo = new PDO('sqlite:' . $tmp . '/purchases.sqlite');
$purchasePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_PURCHASES_PDO'] = $purchasePdo;
jeab_purchases_store_ensure($purchasePdo);
jeab_ordrar_external_live(false);
date_default_timezone_set('Europe/Stockholm');

function vb_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function vb_states(array $row, ?JeabStore $store = null): array
{
    $out = [];
    foreach (jeab_ordrar_v3_steps($row, $store) as $step) {
        $out[$step['id']] = $step['state'];
    }
    return $out;
}

function vb_hooks(string $type): array
{
    $out = [];
    foreach ($GLOBALS['_JEAB_BUG_WEBHOOKS'] ?? [] as $p) {
        if (is_array($p) && ($p['type'] ?? '') === $type) {
            $out[] = $p;
        }
    }
    return $out;
}

$secretPath = jeab_bokmalen_ordrar_webhook_secret_path();
if (!is_dir(dirname($secretPath))) {
    mkdir(dirname($secretPath), 0775, true);
}
$secretJson = json_encode(['url' => 'https://bokmalen.example.invalid/hooks/jeab-ordrar', 'key' => 'v3-bat-key'], JSON_UNESCAPED_SLASHES);
file_put_contents($secretPath, $secretJson);

// Bokningen läses via jeab_store(). En JSON-fil träffar inte den cachen.
$store = jeab_store();

function vb_order(JeabStore $store, array $extra = []): array
{
    $reminder = jeab_create_reminder($store, 'Batteri Testsson', null, 'Order', null, [
        'customer' => 'Batteri Testsson',
        'company' => 'jarfalla',
        'assignee' => 'Roberto',
        'address' => 'Testgatan 2, 175 00 Järfälla',
        'notes' => 'Order',
    ]);
    return jeab_purchase_put(array_merge([
        'reminder_id' => (int) $reminder['id'],
        'company' => 'jarfalla',
        'kind' => 'batteri',
        'customer' => 'Batteri Testsson',
        'address' => 'Testgatan 2, 175 00 Järfälla',
        'huvudsokande' => 'Batteri Testsson',
        'personnummer' => '19800101-0000',
        'fastighetsbeteckning' => 'Testet 2:2',
        'pris' => 89000,
        'natbolag' => 'E.ON',
        'anlaggnings_id' => '735999123456789012',
        'kwh' => '16',
        'steps' => [],
    ], $extra));
}

// —— stegen ——
$new = vb_order($store);
vb_assert(vb_states($new, $store) === [
    'bekrafta' => 'todo', 'foranmalan' => 'locked', 'bestall' => 'locked', 'faktura' => 'locked', 'stang' => 'locked',
], 'nytt batteri: Bekräfta att göra, resten låst, ingen Boka');
$html = jeab_ordrar_v3_html($new, 'jarfalla', (int) $new['reminder_id'], $store);
vb_assert(substr_count($html, 'data-v3-primary=') === 1 && str_contains($html, '>Skapa order<'), 'en grön knapp: Skapa order');
vb_assert(!str_contains($html, '>Skapa order och boka<'), 'batteri bokar inte i Bekräfta');
vb_assert(str_contains($html, 'href="#steg-foranmalan"') && str_contains($html, 'href="#steg-bestall"'), 'menyn har Föranmälan och Beställ');

$booked = jeab_ordrar_create_booking($store, ['id' => (int) $new['reminder_id']]);
vb_assert(jeab_ordrar_is_booked($booked, $store), 'testordern är bokad');
vb_assert(vb_states($booked, $store) === [
    'bekrafta' => 'klar', 'foranmalan' => 'todo', 'bestall' => 'locked', 'faktura' => 'locked', 'stang' => 'locked',
], 'bokad: Föranmälan att göra, Beställ och Fakturering väntar');
$steps = jeab_ordrar_v3_steps($booked, $store);
$bestallStep = array_values(array_filter($steps, static fn($s) => $s['id'] === 'bestall'))[0];
vb_assert($bestallStep['reason'] === 'Kräver att Föranmälan är klar', 'låst Beställ säger vad som krävs');

// Bekräfta: saknade Fortnox-uppgifter visas som Att göra utan att låsa stegen efter.
$noPnr = $booked;
$noPnr['personnummer'] = '';
$st = vb_states($noPnr, $store);
vb_assert($st['bekrafta'] === 'todo' && $st['foranmalan'] === 'todo' && $st['faktura'] === 'locked', 'saknat personnummer: Bekräfta att göra, Föranmälan öppen, Fakturering låst tills Beställ');
$html = jeab_ordrar_v3_html($noPnr, 'jarfalla', (int) $noPnr['reminder_id'], $store);
vb_assert(str_contains($html, 'Saknas för Fortnox-order: personnummer'), 'Bekräfta visar «Saknas för Fortnox-order: personnummer»');
vb_assert(str_contains($html, '>Skapa Fortnox-order<') && str_contains($html, 'Tiden är redan bokad'), 'bokad men utan order: knappen heter Skapa Fortnox-order');
$withOrder = $noPnr;
$withOrder['fortnox_order'] = '1901';
vb_assert(vb_states($withOrder, $store)['bekrafta'] === 'klar', 'med Fortnox-order är Bekräfta klar även om uppgifter saknas lokalt');

// Föranmälan-kortet (att göra)
$html = jeab_ordrar_v3_html($booked, 'jarfalla', (int) $booked['reminder_id'], $store);
vb_assert(str_contains($html, 'id="v3-f-foranmalan"') && str_contains($html, 'name="action" value="ordrar.batterisol"'), 'Föranmälan postar ordrar.batterisol som gamla vyn');
vb_assert(str_contains($html, 'data-v3-primary="foranmalan"') && str_contains($html, 'name="intent" value="next"') && str_contains($html, '>Skicka föranmälan<'), 'grön knapp «Skicka föranmälan» med intent=next');
vb_assert(str_contains($html, 'name="foranmalan_status" value="A"') && str_contains($html, 'name="natbolag" value="Vattenfall"') && str_contains($html, 'name="kwh"') && str_contains($html, 'name="anlaggnings_id"'), 'samma fält som gamla vyn');
vb_assert(substr_count($html, 'data-v3-primary=') === 2 && str_contains($html, 'value="ordrar.fortnox_utan_bokning"'), 'Föranmälan är grön och Skapa Fortnox-order syns när ordernumret saknas');
$ell = $booked;
$ell['natbolag'] = 'Ellevio';
$html = jeab_ordrar_v3_html($ell, 'jarfalla', (int) $ell['reminder_id'], $store);
vb_assert(str_contains($html, '>Skicka fullmakt<'), 'Ellevio: knappen heter «Skicka fullmakt»');

// Autospar av inköpslistan gör inte Beställ klar (bocken blev grön förut).
$sent = $booked;
$sent['foranmalan_status'] = 'A';
$sent['foranmalan_case_status'] = 'skickad';
$sent['steps']['foranmald'] = '2026-10-05';
$sent['steps']['spec'] = '2026-10-05';
$sent = jeab_purchase_put($sent);
vb_assert(vb_states($sent, $store)['foranmalan'] === 'klar' && vb_states($sent, $store)['bestall'] === 'todo', 'föranmälan skickad: Beställ att göra');
$auto = jeab_ordrar_save_orderrader(['id' => (int) $sent['reminder_id'], 'line_sku' => ['SIG-16'], 'line_title' => ['Sigenergy 16 kWh'], 'line_qty' => ['1']]);
vb_assert(!empty($auto['orderrader_saved']), 'autosparet sparade raderna');
vb_assert(vb_states($auto, $store)['bestall'] === 'todo', 'autosparad inköpslista: Beställ är fortfarande Att göra (ingen grön bock)');
$html = jeab_ordrar_v3_html($auto, 'jarfalla', (int) $auto['reminder_id'], $store);
vb_assert(str_contains($html, 'name="action" value="ordrar.orderrader"') && str_contains($html, 'data-v3-autosave') && str_contains($html, 'value="SIG-16"'), 'inköpslistan autosparas via ordrar.orderrader');
vb_assert(str_contains($html, 'data-js="ordrar-mall"') && str_contains($html, 'data-js="ordrar-mall-pick"') && str_contains($html, 'data-js="ordrar-lines-more"'), 'mallar och «Lägg till 5 rader» använder samma JS som gamla vyn');
vb_assert(str_contains($html, 'name="action" value="ordrar.bestall_steg"') && str_contains($html, 'data-v3-primary="bestall"') && str_contains($html, '>Beställ<'), 'Beställ postar ordrar.bestall_steg med intent=next');
vb_assert(str_contains($html, 'Inköpslista sparad'), 'menyn säger «Inköpslista sparad», inte Klar');

// Föranmälan klar: Skicka igen, Uppdatera status, läget.
vb_assert(str_contains($html, 'Skicka föranmälan igen…') && str_contains($html, 'value="ordrar.foranmalan_resend"'), 'klar föranmälan har «Skicka igen» med egen åtgärd');
vb_assert(str_contains($html, 'Uppdatera status') && str_contains($html, 'data-v3="status-refresh"'), '«Uppdatera status» finns');
vb_assert(str_contains($html, 'class="v3-pill is-brand is-on"') && str_contains($html, '>Skickad<'), 'läget Skickad är markerat');
vb_assert(!str_contains($html, 'value="ordrar.advance"'), 'v3 använder inte den döda advance-vägen för Skicka igen');

// —— Skicka igen: samma väg som första skickningen (fångad, inget skickas på riktigt) ——
$GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
$first = jeab_ordrar_save_foranmalan(['id' => (int) $sent['reminder_id'], 'intent' => 'send', 'natbolag' => 'E.ON']);
vb_assert(vb_hooks('ordrar.foranmalan') === [], 'gamla vägen (once) skickar inte igen när föranmälan redan är skickad – därför egen åtgärd');
$again = jeab_ordrar_foranmalan_resend(['id' => (int) $sent['reminder_id']]);
$hooks = vb_hooks('ordrar.foranmalan');
vb_assert(count($hooks) === 1, 'Skicka igen postar en föranmälan till Bokmalen');
vb_assert(($hooks[0]['utkast'] ?? null) === false && ($hooks[0]['resend'] ?? null) === true, 'utkast false och resend true');
vb_assert(($hooks[0]['order_id'] ?? 0) === (int) $sent['reminder_id'] && ($hooks[0]['anlaggnings_id'] ?? '') === '735999123456789012' && ($hooks[0]['natbolag'] ?? '') === 'E.ON' && ($hooks[0]['kwh'] ?? '') === '16', 'samma payload som första gången (order, nät, anläggnings-id, kWh)');
$firstPayload = jeab_bokmalen_ordrar_webhook_payload('ordrar.foranmalan', $again, false);
unset($hooks[0]['resend']);
vb_assert(array_keys($firstPayload) === array_keys($hooks[0]), 'samma fält som jeab_bokmalen_ordrar_webhook_payload');
vb_assert(trim((string) ($again['foranmalan_resent_at'] ?? '')) !== '' && (int) ($again['foranmalan_resent_count'] ?? 0) === 1, 'tid för omskick sparas');
vb_assert(str_contains(json_encode(jeab_ordrar_activity_list($again), JSON_UNESCAPED_UNICODE), 'Föranmälan skickad igen'), 'aktiviteten loggar «Föranmälan skickad igen»');
$thrown = '';
try {
    jeab_ordrar_foranmalan_resend(['id' => (int) $sent['reminder_id']]);
} catch (InvalidArgumentException $e) {
    $thrown = $e->getMessage();
}
vb_assert(str_contains($thrown, 'nyss') && count(vb_hooks('ordrar.foranmalan')) === 1, 'dubbelklick: andra försöket inom en minut skickar inget');

// Misslyckat skick: felet syns, inget stämplas.
$again['foranmalan_resent_at'] = '2026-01-01 08:00:00';
jeab_purchase_put($again);
unlink($secretPath);
$thrown = '';
try {
    jeab_ordrar_foranmalan_resend(['id' => (int) $sent['reminder_id']]);
} catch (InvalidArgumentException $e) {
    $thrown = $e->getMessage();
}
vb_assert(str_contains($thrown, 'kunde inte skickas till Bokmalen'), 'utan webhook: «Föranmälan kunde inte skickas till Bokmalen»');
vb_assert((string) (jeab_purchase_get((int) $sent['reminder_id'])['foranmalan_resent_at'] ?? '') === '2026-01-01 08:00:00', 'misslyckat omskick stämplar inget');
file_put_contents($secretPath, $secretJson);

$notSent = vb_order($store);
$thrown = '';
try {
    jeab_ordrar_foranmalan_resend(['id' => (int) $notSent['reminder_id']]);
} catch (InvalidArgumentException $e) {
    $thrown = $e->getMessage();
}
vb_assert(str_contains($thrown, 'inte skickad än'), 'Skicka igen kräver att föranmälan redan är skickad');
$ellSent = vb_order($store, ['natbolag' => 'Ellevio', 'steps' => ['foranmald' => '2026-10-01']]);
$thrown = '';
try {
    jeab_ordrar_foranmalan_resend(['id' => (int) $ellSent['reminder_id']]);
} catch (InvalidArgumentException $e) {
    $thrown = $e->getMessage();
}
vb_assert(str_contains($thrown, 'fullmakten'), 'Ellevio skickas inte om här (går via fullmakten)');
$closed = vb_order($store, ['steps' => ['foranmald' => '2026-10-01'], 'allt_klart' => true, 'closed_at' => '2026-10-08 10:00:00', 'manuellt_stangt' => true]);
$thrown = '';
try {
    jeab_ordrar_foranmalan_resend(['id' => (int) $closed['reminder_id']]);
} catch (InvalidArgumentException $e) {
    $thrown = $e->getMessage();
}
vb_assert($thrown !== '' && count(vb_hooks('ordrar.foranmalan')) === 1, 'stängt ärende skickas inte om');

// —— läget från Bokmalen (inbound) ——
$_SERVER['REQUEST_METHOD'] = 'POST';
$res = jeab_bokmalen_ordrar_inbound(['type' => 'ordrar.foranmalan_status', 'order_id' => (int) $sent['reminder_id'], 'status' => 'approved', 'kommentar' => 'Godkänd av E.ON, ärende 4711']);
vb_assert(!empty($res['ok']) && ($res['status'] ?? '') === 'godkand', 'Bokmalen kan skicka läget Godkänd');
$row = jeab_purchase_get((int) $sent['reminder_id']);
vb_assert(jeab_ordrar_foranmalan_case_status($row) === 'godkand' && jeab_ordrar_status_comment($row) === 'Godkänd av E.ON, ärende 4711', 'läget och kommentaren sparas på ordern');
$html = jeab_ordrar_v3_html($row, 'jarfalla', (int) $row['reminder_id'], $store);
vb_assert(str_contains($html, 'Godkänd av E.ON, ärende 4711') && str_contains($html, 'Senast från Bokmalen'), 'kortet visar Bokmalens kommentar och när läget kom');
$bad = jeab_bokmalen_ordrar_inbound(['type' => 'ordrar.foranmalan_status', 'order_id' => (int) $sent['reminder_id'], 'status' => 'kanske']);
vb_assert(empty($bad['ok']), 'okänt läge avvisas');

// —— Beställt: bara orderdatum, leveransdatum, ordernummer, kostnad ——
$facts = jeab_bokmalen_ordrar_shop_facts(['data' => ['aprilice' => '2026104078', 'leverans' => '2026-10-20', 'ordered_at' => '2026-10-09 10:15:00', 'total' => '12 345,50']]);
vb_assert($facts['kostnad'] === '12346', 'kostnaden läses ur Bokmalens svar');
$placed = jeab_ordrar_apply_placed_shop($row, $facts);
$placed = jeab_ordrar_stamp_today($placed, 'bestalld');
vb_assert((int) ($placed['bestall_kostnad'] ?? 0) === 12346, 'kostnaden sparas på ordern');
vb_assert(vb_states($placed, $store)['bestall'] === 'klar', 'lagd beställning: Beställ klar');
$html = jeab_ordrar_v3_html($placed, 'jarfalla', (int) $placed['reminder_id'], $store);
preg_match('#<section class="v3-card is-done" id="steg-bestall".*?</section>#s', $html, $m);
$card = $m[0] ?? '';
vb_assert($card !== '', 'Beställ-kortet är hopfällt som klart');
preg_match('#<div class="v3-sumgrid">(.*?)</div></div>#s', $card, $sm);
$sum = $sm[1] ?? '';
vb_assert(substr_count($sum, 'class="v3-drow"') === 4, 'sammanfattningen har exakt fyra rader');
vb_assert(str_contains($sum, 'Orderdatum') && str_contains($sum, '9 okt 2026') && str_contains($sum, 'Leveransdatum') && str_contains($sum, '20 okt 2026') && str_contains($sum, 'Aprilice 2026104078') && str_contains($sum, jeab_ordrar_v3_kr(12346)), 'orderdatum, leveransdatum, ordernummer och kostnad');
vb_assert(!str_contains($card, 'data-v3-primary'), 'klar Beställ har ingen grön knapp');
$noCost = $placed;
unset($noCost['bestall_kostnad'], $noCost['supplier_lines']);
$html = jeab_ordrar_v3_html($noCost, 'jarfalla', (int) $noCost['reminder_id'], $store);
vb_assert(preg_match('#Kostnad</span><b>–</b>#u', $html) === 1, 'okänd kostnad visas som –');

// —— Boka visas när Bekräfta är gjort men tiden saknas ——
$unbooked = vb_order($store, ['fortnox_order' => '1902']);
$st = vb_states($unbooked, $store);
vb_assert(!isset($st['boka']), 'Fortnox-order utan beställning visar inte Boka');
vb_assert(($st['foranmalan'] ?? '') === 'todo', 'Fortnox-order utan föranmälan: Föranmälan att göra');
$html = jeab_ordrar_v3_html($unbooked, 'jarfalla', (int) $unbooked['reminder_id'], $store);
vb_assert(!str_contains($html, 'value="ordrar.boka"'), 'Boka visas inte före Beställ');

// —— Stäng för batteri: faktura + färdiganmäld ——
$inv = $placed;
$inv['fortnox_order'] = '1903';
$inv['fortnox_invoice'] = '13500';
$inv['steps']['faktura_skapad'] = '2026-10-21';
$st = vb_states($inv, $store);
vb_assert($st['faktura'] === 'klar' && $st['stang'] === 'todo', 'faktura finns: Stäng att göra');
$html = jeab_ordrar_v3_html($inv, 'jarfalla', (int) $inv['reminder_id'], $store);
vb_assert(str_contains($html, 'Föranmälan Färdiganmäld'), 'Stäng-checklistan har Färdiganmäld för batteri');

// Gamla vyns steglista är orörd.
$flow = jeab_ordrar_flow($auto);
$keys = array_map(static fn($s) => $s['key'], $flow);
vb_assert($keys === ['bekrafta', 'spec', 'orderrader', 'boka', 'bestall', 'fakturering'], 'gamla vyns flöde är oförändrat');

// Mallens tomma rader (antal 0) räknas inte som inköpsrader.
vb_assert(!jeab_ordrar_v3_ink_row_has(['sku' => '', 'title' => '', 'qty' => '0']) && jeab_ordrar_v3_ink_row_has(['qty' => '2']) && jeab_ordrar_v3_ink_row_has(['sku' => '1151', 'qty' => '0']), 'tom rad med antal 0 räknas inte');
$zero = vb_order($store, ['inkop_lines' => [['sku' => 'A1', 'title' => 'Batteri', 'qty' => '1'], ['sku' => '', 'title' => '', 'qty' => '0'], ['sku' => '', 'title' => '', 'qty' => '0']]]);
$html = jeab_ordrar_v3_inkop_html($zero, 'jarfalla', (int) $zero['reminder_id'], false);
vb_assert(str_contains($html, '>1 rad · sparas automatiskt<'), 'inköpslistan räknar bara riktiga rader (1 rad)');

echo "ALL OK\n";
