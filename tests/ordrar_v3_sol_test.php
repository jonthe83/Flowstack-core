<?php

declare(strict_types=1);

/**
 * Ordrar v3 för sol: samma steg som batteri, plus Kalkyl i Bekräfta (jeab_offert_*) och Solkoll
 * i Beställ (bara sol, bara via knappen). Inget skickas på riktigt: webhooks fångas, Solkoll anropas inte.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-ordrar-v3-sol-' . bin2hex(random_bytes(4));
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
$secretJson = json_encode(['url' => 'https://bokmalen.example.invalid/hooks/jeab-ordrar', 'key' => 'v3-sol-key'], JSON_UNESCAPED_SLASHES);
file_put_contents($secretPath, $secretJson);

$store = new JeabJsonStore($tmp . '/store.json');

function vb_order(JeabStore $store, array $extra = []): array
{
    $reminder = jeab_create_reminder($store, 'Sol Testsson', null, 'Order', null, [
        'customer' => 'Sol Testsson',
        'company' => 'jarfalla',
        'assignee' => 'Roberto',
        'address' => 'Testgatan 2, 175 00 Järfälla',
        'notes' => 'Order',
    ]);
    return jeab_purchase_put(array_merge([
        'reminder_id' => (int) $reminder['id'],
        'company' => 'jarfalla',
        'kind' => 'sol',
        'customer' => 'Sol Testsson',
        'address' => 'Testgatan 2, 175 00 Järfälla',
        'huvudsokande' => 'Sol Testsson',
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
vb_assert(jeab_ordrar_v3_supports(['kind' => 'sol']), 'sol har ny vy');
$new = vb_order($store);
vb_assert(vb_states($new, $store) === [
    'bekrafta' => 'todo', 'foranmalan' => 'locked', 'bestall' => 'locked', 'faktura' => 'locked', 'stang' => 'locked',
], 'ny sol: samma steg som batteri');
$html = jeab_ordrar_v3_html($new, 'jarfalla', (int) $new['reminder_id'], $store);
vb_assert(substr_count($html, 'data-v3-primary=') === 1 && str_contains($html, '>Skapa order<'), 'en grön knapp: Skapa order');
vb_assert(!str_contains($html, '>Skapa order och boka<'), 'sol bokar inte i Bekräfta');

// —— Kalkyl i Bekräfta ——
vb_assert(str_contains($html, 'data-sub="kalkyl"') && str_contains($html, 'data-js="offert"') && str_contains($html, 'name="kalkyl_present" value="1"'), 'Bekräfta har Kalkyl med samma räknare som Kalkyl-sidan');
foreach (['b14', 'b3', 'sokande', 'b15', 'laddare'] as $key) {
    vb_assert(str_contains($html, 'name="' . $key . '"'), "kalkylfältet {$key} postas med Bekräfta");
}
$calc = jeab_offert_calc(jeab_offert_kalkyl_input($new, 'sol'));
vb_assert(str_contains($html, 'paneler · ') && str_contains($html, 'Att betala <span data-out="i14">' . e(jeab_offert_money((float) $calc['i14'])) . '</span>'), 'sammanfattningen visar Att betala från jeab_offert_calc');
vb_assert(preg_match('#<form id="v3-f-bekrafta".*data-js="offert".*name="pris".*</form>#s', $html) === 1, 'kalkylen ligger i samma formulär som Pris (Pris följer Att betala)');
vb_assert(!str_contains($html, 'data-sub="solkoll"'), 'Solkoll syns inte förrän Beställ är öppet');
$saved = jeab_ordrar_save_questions(['id' => (int) $new['reminder_id'], 'kind' => 'sol', 'pris' => '189 000', 'kalkyl_present' => '1', 'b14' => '16', 'b3' => '2', 'sokande' => '2', 'b15' => '1']);
vb_assert(($saved['kalkyl'] ?? null) === ['b14' => 16, 'b3' => 2, 'sokande' => 2, 'b15' => 1, 'laddare' => 0], 'Spara i Bekräfta sparar kalkylen på ordern');
$html = jeab_ordrar_v3_html($saved, 'jarfalla', (int) $saved['reminder_id'], $store);
vb_assert(str_contains($html, '16 paneler · 2 batterimoduler · 2 sökande · 12 kW TP2'), 'sammanfattningen följer den sparade kalkylen');
foreach (['batteri', 'laddbox'] as $other) {
    $o = $new;
    $o['kind'] = $other;
    $oh = jeab_ordrar_v3_html($o, 'jarfalla', (int) $o['reminder_id'], $store);
    vb_assert(!str_contains($oh, 'data-sub="kalkyl"') && !str_contains($oh, 'data-js="offert"') && !str_contains($oh, 'data-sub="solkoll"'), "{$other}: ingen Kalkyl och ingen Solkoll");
}

// —— Solkoll i Beställ (bara sol) ——
$booked = jeab_ordrar_create_booking($store, ['id' => (int) $saved['reminder_id']]);
$sent = $booked;
$sent['foranmalan_status'] = 'A';
$sent['steps']['foranmald'] = '2026-10-05';
$sent = jeab_purchase_put($sent);
vb_assert(vb_states($sent, $store)['bestall'] === 'todo', 'föranmäld sol: Beställ att göra');
$html = jeab_ordrar_v3_html($sent, 'jarfalla', (int) $sent['reminder_id'], $store);
vb_assert(str_contains($html, 'data-sub="solkoll"') && str_contains($html, 'id="v3-f-solkoll"') && str_contains($html, 'name="action" value="ordrar.solkoll"') && str_contains($html, '>Skapa i Solkoll<'), 'Solkoll: «Skapa i Solkoll» postar ordrar.solkoll');
vb_assert(str_contains($html, '>Inte skapad<'), 'Solkoll-sammanfattning: Inte skapad');
vb_assert(preg_match('#<form id="v3-f-solkoll".*?</form>#s', $html, $solForm) === 1 && !str_contains($solForm[0], 'data-v3-primary'), 'Solkoll-knappen är inte grön');
vb_assert(substr_count($html, 'data-v3-primary=') === 2 && str_contains($html, 'value="ordrar.fortnox_utan_bokning"'), 'Beställ är grön och saknad Fortnox-order har en knapp');
vb_assert(!preg_match('#<form id="v3-f-solkoll"[^>]*data-v3-autosave#', $html), 'Solkoll körs aldrig av autosparet');
$linked = $sent;
$linked['solkoll_facility_id'] = '3f2a9c1e-7b4d-4e0a-9c2b-1a2b3c4d5e6f';
$linked['solkoll_status'] = 'IN_PROGRESS';
$linked['solkoll_todos_done'] = 1;
$linked['solkoll_todos_total'] = 4;
$linked['solkoll_added'] = ['16 × Paneler 475 W (stående)', 'Växelriktare Sigenergy 12 kW TP2'];
$linked['solkoll_unmatched'] = [['what' => 'Batteri', 'title' => 'SigenStor BAT 10.0', 'reason' => 'Ingen matchande favorit i Solkoll.']];
$linked['solkoll_differs'] = ['Takvinkel: Solkoll 27°, ordern 30°.'];
$linked['solkoll_duplicates'] = 1;
$html = jeab_ordrar_v3_html($linked, 'jarfalla', (int) $linked['reminder_id'], $store);
vb_assert(str_contains($html, '>Uppdatera Solkoll<') && str_contains($html, 'Öppna i Solkoll') && str_contains($html, 'Påbörjad 1/4'), 'kopplad: status x/4, Öppna i Solkoll och Uppdatera Solkoll');
vb_assert(str_contains($html, 'Tillagt:') && str_contains($html, 'Lades inte till i Solkoll:') && str_contains($html, 'SigenStor BAT 10.0') && str_contains($html, 'Skiljer sig') && str_contains($html, '2 anläggningar på adressen'), 'resultatet visar Tillagt, Lades inte till, Skiljer sig och dubbletter');
vb_assert(str_contains($html, '1 lades inte till'), 'sammanfattningen varnar för rader som inte lades till');
$errRow = $sent;
$errRow['solkoll_error'] = 'Solkoll arbetar redan med ordern. Vänta en stund och försök igen.';
$html = jeab_ordrar_v3_html($errRow, 'jarfalla', (int) $errRow['reminder_id'], $store);
vb_assert(preg_match('#data-v3-err="solkoll" role="alert">Kunde inte spara\. Solkoll arbetar redan#u', $html) === 1, 'Solkoll-fel visas på plats som «Kunde inte spara» med orsak');

// Overview och Stäng
$html = jeab_ordrar_v3_html($linked, 'jarfalla', (int) $linked['reminder_id'], $store);
vb_assert(str_contains($html, 'Dokumentation (Solkoll)'), 'läget visar Dokumentation (Solkoll)');

// Beställd sol: sammanfattningen + Solkoll finns kvar
$done = $linked;
$done['steps']['bestalld'] = '2026-10-09';
$done['bestalld_at'] = '2026-10-09 10:15:00';
$done['aprilice_order'] = '2026104078';
$done['bestall_kostnad'] = 54321;
$done = jeab_purchase_put($done);
vb_assert(vb_states($done, $store)['bestall'] === 'klar', 'lagd beställning: Beställ klar');
$html = jeab_ordrar_v3_html($done, 'jarfalla', (int) $done['reminder_id'], $store);
$start = strpos($html, 'id="steg-bestall"');
$card = substr($html, (int) $start, (int) strpos($html, '</section>', (int) $start) - (int) $start);
vb_assert(substr_count($card, 'class="v3-drow"') === 4 && str_contains($card, 'Aprilice 2026104078') && str_contains($card, jeab_ordrar_v3_kr(54321)), 'Beställt: fyra rader (datum, leverans, nummer, kostnad)');
vb_assert(str_contains($card, 'data-sub="solkoll"') && str_contains($card, '>Uppdatera Solkoll<'), 'Solkoll går att köra igen efter beställning');

// Stäng-checklistan för sol
$inv = $done;
$inv['fortnox_invoice'] = '5001';
$inv['invoice_number'] = '5001';
$html = jeab_ordrar_v3_html($inv, 'jarfalla', (int) $inv['reminder_id'], $store);
vb_assert(str_contains($html, 'Solkoll Slutförd eller 4/4'), 'Stäng kräver Solkoll Slutförd eller 4/4 för sol');

// Skicka igen fungerar för sol (fångad)
$GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
$again = jeab_ordrar_foranmalan_resend(['id' => (int) $sent['reminder_id']]);
$hooks = vb_hooks('ordrar.foranmalan');
vb_assert(count($hooks) === 1 && ($hooks[0]['resend'] ?? null) === true && ($hooks[0]['kind'] ?? '') === 'sol', 'sol: Skicka igen postar en föranmälan (kind sol, resend true)');

echo "ALL OK\n";
