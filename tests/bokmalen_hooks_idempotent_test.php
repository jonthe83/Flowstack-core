<?php

declare(strict_types=1);

/**
 * Bokmalen → JEAB: ordrar.foranmalan_status och ordrar.shop_facts är idempotenta och säkra.
 * Gamla vyns «Skicka igen» går via ordrar.foranmalan_resend (samma som v3, med spärr).
 * Listans Process-kolumn visar Klart för stängda ärenden (samma källa som v3 Stäng).
 * Fakturalänken pekar på Fortnox kundfakturamodul (kf/invoice).
 * Inget skickas på riktigt: webhooks fångas.
 */

$tmp = sys_get_temp_dir() . '/jeab-bokmalen-idem-' . bin2hex(random_bytes(4));
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

function bh_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function bh_acts(array $row, string $needle): int
{
    $n = 0;
    foreach ((array) ($row['activity'] ?? []) as $a) {
        if (is_array($a) && str_contains((string) ($a['text'] ?? ''), $needle)) {
            $n++;
        }
    }
    return $n;
}

$secretPath = jeab_bokmalen_ordrar_webhook_secret_path();
if (!is_dir(dirname($secretPath))) {
    mkdir(dirname($secretPath), 0775, true);
}
file_put_contents($secretPath, json_encode(['url' => 'https://bokmalen.example.invalid/hooks/jeab-ordrar', 'key' => 'idem-key'], JSON_UNESCAPED_SLASHES));
$store = new JeabJsonStore($tmp . '/store.json');

function bh_order(JeabStore $store, array $extra = []): array
{
    $reminder = jeab_create_reminder($store, 'Idem Testsson', null, 'Order', null, [
        'customer' => 'Idem Testsson', 'company' => 'jarfalla', 'assignee' => 'Roberto',
        'address' => 'Testgatan 3, 175 00 Järfälla', 'notes' => 'Order',
    ]);
    return jeab_purchase_put(array_merge([
        'reminder_id' => (int) $reminder['id'], 'company' => 'jarfalla', 'kind' => 'batteri',
        'customer' => 'Idem Testsson', 'address' => 'Testgatan 3, 175 00 Järfälla', 'huvudsokande' => 'Idem Testsson',
        'personnummer' => '19800101-0000', 'fastighetsbeteckning' => 'Testet 3:3', 'pris' => 79000,
        'natbolag' => 'E.ON', 'anlaggnings_id' => '735999123456789012', 'kwh' => '10',
        'foranmalan_status' => 'A', 'steps' => ['foranmald' => '2026-10-01'],
    ], $extra));
}

$_SERVER['REQUEST_METHOD'] = 'POST';

// —— ordrar.foranmalan_status ——
$o = bh_order($store);
$id = (int) $o['reminder_id'];
$r1 = jeab_bokmalen_ordrar_inbound(['type' => 'ordrar.foranmalan_status', 'order_id' => $id, 'status' => 'Skickad', 'kommentar' => "test av\nstatuskoppling", 'event_id' => 'evt-1', 'at' => '2026-10-09T08:00:00+02:00']);
bh_assert(($r1['ok'] ?? false) && ($r1['status'] ?? '') === 'skickad' && ($r1['unchanged'] ?? true) === false, 'första status sparas (skickad)');
$s1 = jeab_purchase_get($id);
bh_assert(($s1['foranmalan_kommentar'] ?? '') === 'test av statuskoppling', 'kommentaren rensas från radbrytningar');
bh_assert(bh_acts($s1, 'Föranmälan: Skickad (Bokmalen)') === 1, 'en aktivitet loggas');
$stamp = (string) ($s1['foranmalan_status_at'] ?? '');
$upd = (string) ($s1['updated_at'] ?? '');

$r2 = jeab_bokmalen_ordrar_inbound(['type' => 'ordrar.foranmalan_status', 'order_id' => $id, 'status' => 'sent', 'kommentar' => 'test av statuskoppling']);
$s2 = jeab_purchase_get($id);
bh_assert(($r2['unchanged'] ?? false) === true && bh_acts($s2, 'Föranmälan: Skickad') === 1 && ($s2['foranmalan_status_at'] ?? '') === $stamp && ($s2['updated_at'] ?? '') === $upd, 'samma status igen: ingen ändring, ingen ny aktivitet');

$r3 = jeab_bokmalen_ordrar_inbound(['type' => 'ordrar.foranmalan_status', 'order_id' => $id, 'status' => 'godkand', 'event_id' => 'evt-1']);
bh_assert(($r3['duplicate'] ?? false) === true && jeab_ordrar_foranmalan_case_status(jeab_purchase_get($id)) === 'skickad', 'samma event_id igen: no-op');

$r4 = jeab_bokmalen_ordrar_inbound(['type' => 'ordrar.foranmalan_status', 'order_id' => $id, 'status' => 'nekad', 'at' => '2026-10-08T12:00:00+02:00']);
bh_assert(($r4['stale'] ?? false) === true && jeab_ordrar_foranmalan_case_status(jeab_purchase_get($id)) === 'skickad', 'äldre at: ignoreras (backar inte läget)');

$r5 = jeab_bokmalen_ordrar_inbound(['type' => 'ordrar.foranmalan_status', 'order_id' => $id, 'status' => 'approved', 'event_id' => 'evt-2', 'at' => '2026-10-10T09:00:00Z']);
bh_assert(($r5['status_now'] ?? '') === 'godkand' && jeab_ordrar_foranmalan_case_status(jeab_purchase_get($id)) === 'godkand', 'ny status med senare at sparas');

$bad = jeab_bokmalen_ordrar_inbound(['type' => 'ordrar.foranmalan_status', 'order_id' => $id, 'status' => 'kanske']);
bh_assert(($bad['error'] ?? '') === 'status', 'okänd status: error status');
$miss = jeab_bokmalen_ordrar_inbound(['type' => 'ordrar.foranmalan_status', 'order_id' => 999999, 'status' => 'skickad']);
bh_assert(($miss['error'] ?? '') === 'missing', 'okänd order: error missing');
$lb = bh_order($store, ['kind' => 'laddbox']);
$kind = jeab_bokmalen_ordrar_inbound(['type' => 'ordrar.foranmalan_status', 'order_id' => (int) $lb['reminder_id'], 'status' => 'skickad']);
bh_assert(($kind['error'] ?? '') === 'kind' && jeab_ordrar_foranmalan_case_status(jeab_purchase_get((int) $lb['reminder_id'])) === '', 'laddbox: error kind, inget sparas');
$nested = jeab_bokmalen_ordrar_inbound(['type' => 'ordrar.foranmalan_status', 'order_id' => $id, 'data' => ['status' => 'fardiganmald']]);
bh_assert(($nested['status_now'] ?? '') === 'fardiganmald', 'status i data{} fungerar');

// —— ordrar.shop_facts ——
$b = bh_order($store);
$bid = (int) $b['reminder_id'];
$c1 = jeab_bokmalen_ordrar_inbound(['type' => 'ordrar.shop_facts', 'order_id' => $bid, 'kostnad' => '45 120 kr']);
$cb = jeab_purchase_get($bid);
bh_assert(($c1['ok'] ?? false) && (int) ($cb['bestall_kostnad'] ?? 0) === 45120 && trim((string) ($cb['steps']['bestalld'] ?? '')) === '', 'bara kostnad: sparas men markerar inte beställd');

$f1 = jeab_bokmalen_ordrar_inbound(['type' => 'ordrar.shop_facts', 'order_id' => $bid, 'event_id' => 'shop-1', 'aprilice' => '2026104339', 'bestalld_at' => '2026-10-02T11:22:00+02:00', 'leverans' => '2026-10-09', 'kostnad' => 45120]);
$fb = jeab_purchase_get($bid);
bh_assert(($f1['unchanged'] ?? true) === false && ($fb['aprilice_order'] ?? '') === '2026104339' && ($fb['steps']['bestalld'] ?? '') === '2026-10-02' && ($f1['kostnad'] ?? '') === '45120', 'ordernummer + tid: sparas, beställd stämplas med beställningsdagen');
$acts = bh_acts($fb, 'Aprilice 2026104339');
$updB = (string) ($fb['updated_at'] ?? '');

$f2 = jeab_bokmalen_ordrar_inbound(['type' => 'ordrar.shop_facts', 'order_id' => $bid, 'aprilice' => '2026104339', 'bestalld_at' => '2026-10-02T11:22:00+02:00', 'leverans' => '2026-10-09', 'kostnad' => 45120]);
$fb2 = jeab_purchase_get($bid);
bh_assert(($f2['unchanged'] ?? false) === true && bh_acts($fb2, 'Aprilice 2026104339') === $acts && ($fb2['updated_at'] ?? '') === $updB, 'samma fakta igen: unchanged, ingen ny aktivitet');

$f3 = jeab_bokmalen_ordrar_inbound(['type' => 'ordrar.shop_facts', 'order_id' => $bid, 'aprilice' => '2026109999', 'kostnad' => 1]);
$fb3 = jeab_purchase_get($bid);
bh_assert(($fb3['aprilice_order'] ?? '') === '2026104339' && (int) $fb3['bestall_kostnad'] === 45120 && isset($f3['conflicts']['aprilice'], $f3['conflicts']['kostnad']) && bh_acts($fb3, '2026109999') === 0, 'annat värde: skrivs inte över, rapporteras i conflicts');

$f4 = jeab_bokmalen_ordrar_inbound(['type' => 'ordrar.shop_facts', 'order_id' => $bid, 'event_id' => 'shop-1', 'aprilice' => 'X1']);
bh_assert(($f4['duplicate'] ?? false) === true, 'samma event_id: duplicate');
$e = jeab_bokmalen_ordrar_inbound(['type' => 'ordrar.shop_facts', 'order_id' => $bid]);
bh_assert(($e['error'] ?? '') === 'empty', 'inga fakta: error empty');
$lk = jeab_bokmalen_ordrar_inbound(['type' => 'ordrar.shop_facts', 'order_id' => (int) $lb['reminder_id'], 'aprilice' => '1234']);
bh_assert(($lk['error'] ?? '') === 'kind', 'shop_facts på laddbox: error kind');

// —— Gamla vyns «Skicka igen» ——
$html = jeab_ordrar_eon_html($o, 'jarfalla', $id);
bh_assert(preg_match('#name="action" value="ordrar\.foranmalan_resend"[^<]*<input type="hidden" name="from" value="eon">\s*<input type="hidden" name="id" value="' . $id . '">\s*<button[^>]*>Skicka igen</button>#', $html) === 1, 'gamla vyn: Skicka igen postar ordrar.foranmalan_resend');
bh_assert(!preg_match('#value="ordrar\.advance">\s*<input type="hidden" name="from" value="eon">[^B]*Skicka igen#', $html), 'gamla vyn: Skicka igen är inte längre ordrar.advance');
$ell = $o;
$ell['natbolag'] = 'Ellevio';
bh_assert(!jeab_ordrar_foranmalan_can_resend($ell) || !str_contains(jeab_ordrar_eon_html($ell, 'jarfalla', $id), 'ordrar.foranmalan_resend'), 'Ellevio: ingen Skicka igen för föranmälan');
$man = $o;
$man['foranmalan_status'] = 'C';
bh_assert(!jeab_ordrar_foranmalan_can_resend($man), 'Manuell: ingen Skicka igen');

$GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
$_POST = ['action' => 'ordrar.foranmalan_resend', 'from' => 'eon', 'id' => (string) $id];
// index.php case ordrar.foranmalan_resend anropar exakt denna funktion med \$_POST.
jeab_ordrar_foranmalan_resend($_POST);
$flash = 'Föranmälan skickad igen.';
$hooks = array_values(array_filter($GLOBALS['_JEAB_BUG_WEBHOOKS'], static fn ($p) => is_array($p) && ($p['type'] ?? '') === 'ordrar.foranmalan'));
bh_assert(str_contains((string) $flash, 'skickad igen') && count($hooks) === 1 && ($hooks[0]['resend'] ?? null) === true, 'gamla vyns action: en fångad föranmälan, resend true');
$err = '';
try {
    jeab_ordrar_foranmalan_resend($_POST);
} catch (Throwable $ex) {
    $err = $ex->getMessage();
}
$hooks = array_values(array_filter($GLOBALS['_JEAB_BUG_WEBHOOKS'], static fn ($p) => is_array($p) && ($p['type'] ?? '') === 'ordrar.foranmalan'));
bh_assert(str_contains($err, 'Vänta en minut') && count($hooks) === 1, 'andra klicket inom en minut: spärr, inget nytt skickas');
$html = jeab_ordrar_eon_html(jeab_purchase_get($id), 'jarfalla', $id);
bh_assert(str_contains($html, 'Skickad igen'), 'gamla vyn visar Skickad igen');
$_POST = [];
$idx = (string) file_get_contents(dirname(__DIR__) . '/index.php');
bh_assert(preg_match("#'ordrar\\.fullmakt_resend', 'ordrar\\.foranmalan_resend'#", $idx) === 1 && str_contains($idx, "\$action === 'ordrar.foranmalan_resend') {\n                    \$stop = 'eon';"), 'gamla vyns XHR: svaret ritar om föranmälan-rutan (stop eon)');

// —— Listan: stängt ärende ——
$closed = bh_order($store, ['kind' => 'sol', 'fortnox_invoice' => '13462', 'invoice_number' => '13462', 'allt_klart' => true, 'closed_at' => '2026-09-28 10:00:00']);
$pv = jeab_ordrar_process_view($closed);
bh_assert(($pv['label'] ?? '') === 'Klart' && $pv['idx'] === $pv['total'] && ($pv['hint'] ?? '') === 'faktura 13462', 'stängd order: listan visar Klart n av n, faktura 13462');
bh_assert(jeab_ordrar_v3_stang_klar($closed) === (($pv['closed'] ?? false) === true), 'listan och v3 Stäng: samma källa');
$rowHtml = jeab_ordrar_board_row_html($closed, 'jarfalla', $store);
bh_assert(!str_contains($rowHtml, 'is-now') && str_contains($rowHtml, '>Klart<'), 'stängd order: ingen nu-prick i listan');
$open = bh_order($store, ['kind' => 'sol']);
bh_assert((jeab_ordrar_process_view($open)['key'] ?? '') !== 'klart', 'öppen order: oförändrat läge');

// Listan använder samma steg som ärendet (steps.spec m.fl. tas inte bort).
$specRow = bh_order($store, ['kind' => 'sol', 'foranmalan_status' => 'C', 'steps' => ['spec' => '2026-09-14', 'orderrader' => '2026-09-14', 'bestalld' => '2026-09-14']]);
$listed = null;
foreach (jeab_ordrar_visible('jarfalla', false) as $lr) {
    if ((int) ($lr['reminder_id'] ?? 0) === (int) $specRow['reminder_id']) {
        $listed = $lr;
    }
}
bh_assert(is_array($listed) && ($listed['steps']['spec'] ?? '') === '2026-09-14', 'listans rad behåller steps.spec');
bh_assert((jeab_ordrar_process_view($listed)['key'] ?? '') === (jeab_ordrar_process_view($specRow)['key'] ?? 'x') && (jeab_ordrar_process_view($listed)['key'] ?? '') !== 'spec', 'listan och ärendet: samma läge (inte Föranmälan när spec är gjord)');

// —— Fakturalänk ——
$url = jeab_ordrar_fortnox_invoice_url(['company' => 'jarfalla', 'fortnox_invoice' => '13462', 'invoice_number' => '13462']);
bh_assert(preg_match('#^https://apps5\.fortnox\.se/app/[0-9a-f]{32}/kf/invoice/13462$#', $url) === 1, 'fakturalänk: kf/invoice/{nr}');

echo "ALL OK\n";
