<?php

declare(strict_types=1);

/**
 * Lagd beställning ska visa Aprilice-nummer, leveransdatum och när den gjordes.
 * Numret sparas från Bokmalens svar. Order #23 Adolfsson och #71 Krokusvägen fylls om fältet är tomt.
 * Lightleap och manuell C följer samma läge som tidigare.
 */

$tmp = sys_get_temp_dir() . '/jeab-bestallning-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('JEAB_BUG_WEBHOOK_CAPTURE=1');
$_ENV['JEAB_BUG_WEBHOOK_CAPTURE'] = '1';
$GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];

require dirname(__DIR__) . '/src/bootstrap.php';

function bl_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

jeab_ordrar_external_live(false);

$nested = jeab_bokmalen_ordrar_shop_facts([
    'data' => [
        'orders' => [
            'aprilice' => '2026104078',
            'lightleap' => ['order_no' => 'KO9'],
        ],
        'leverans' => '2026-10-06',
        'ordered_at' => '2026-09-29 13:00:00',
    ],
]);
bl_assert($nested['aprilice'] === '2026104078', 'svar med orders.aprilice ger numret');
bl_assert($nested['lightleap'] === 'KO9', 'svar med lightleap-objekt ger numret');
bl_assert($nested['leverans'] === '2026-10-06', 'svar ger leveransdatum');
bl_assert($nested['bestalld_at'] === '2026-09-29 13:00:00', 'svar ger beställningstid');

$junk = jeab_bokmalen_ordrar_shop_facts(['aprilice_order' => 'ok', 'lightleap' => 'true']);
bl_assert($junk['aprilice'] === '' && $junk['lightleap'] === '', 'ok/true är inte ordernummer');

$fromActivity = [
    'kind' => 'sol',
    'activity' => [['text' => 'Aprilice 2026104078', 'at' => '2026-09-29 13:00:00']],
    'inkop_lines' => [['sku' => 'P1', 'title' => 'Panel', 'qty' => 1, 'shop' => 'aprilice']],
    'orderrader_saved' => true,
    'leverans' => '2026-10-06',
    'bestalld_at' => '2026-09-29 13:00:00',
    'steps' => ['bestalld' => '2026-09-29'],
];
bl_assert(jeab_ordrar_shop_order_no($fromActivity, [], 'aprilice') === '2026104078', 'aktivitetstexten Aprilice 2026104078 läses');
$activityHtml = jeab_ordrar_status_html($fromActivity, 'jarfalla', 8, null);
bl_assert(str_contains($activityHtml, 'Beställning klar'), 'aktivitetsnummer gör beställningen klar');
bl_assert(str_contains($activityHtml, '2026104078'), 'kortet visar Aprilice-numret från aktiviteten');
bl_assert(str_contains($activityHtml, '6 okt 2026'), 'kortet visar leveransdatum');
bl_assert(str_contains($activityHtml, '29 sep 2026 13:00'), 'kortet visar när beställningen gjordes');
bl_assert(!str_contains($activityHtml, 'Väntar på Bokmalen'), 'lagd order väntar inte');

$waiting = [
    'kind' => 'sol',
    'orderrader_saved' => true,
    'inkop_lines' => [['sku' => 'P1', 'title' => 'Panel', 'qty' => 1]],
];
$waitingHtml = jeab_ordrar_status_html($waiting, 'jarfalla', 9, null);
bl_assert(str_contains($waitingHtml, 'Väntar på Bokmalen'), 'rader utan nummer väntar på Bokmalen');

$light = $waiting;
$light['shop'] = 'lightleap';
$light['steps'] = ['bestalld' => '2026-09-01'];
$light['bestalld_at'] = '2026-09-01 15:10:00';
$lightHtml = jeab_ordrar_status_html($light, 'jarfalla', 9, null);
bl_assert(str_contains($lightHtml, 'Beställning klar'), 'Lightleap efter beställning är klar');
bl_assert(str_contains($lightHtml, 'Något är beställt från Lightleap'), 'Lightleap utan nummer behåller sin text');
bl_assert(str_contains($lightHtml, '1 sep 2026 15:10'), 'Lightleap visar när beställningen gjordes');

$manual = [
    'kind' => 'sol',
    'bestallning_status' => 'C',
    'steps' => ['bestalld' => '2026-09-02'],
    'leverans' => '2026-09-20',
];
$manualHtml = jeab_ordrar_status_html($manual, 'jarfalla', 9, null);
bl_assert(str_contains($manualHtml, 'Beställning klar'), 'manuell C är klar');
bl_assert(str_contains($manualHtml, '20 sep 2026'), 'manuell C visar leveransdatum');
bl_assert(str_contains($manualHtml, '2 sep 2026'), 'manuell C visar beställningsdagen');
bl_assert(!str_contains($manualHtml, 'Lightleap'), 'manuell C utan Lightleap nämner inte distributören');

$ada = [
    'reminder_id' => 23,
    'kind' => 'sol',
    'company' => 'jarfalla',
    'name' => 'Adolfsson',
    'huvudsokande' => 'Adolfsson',
    'address' => 'Hässelby Strandväg 47',
    'orderrader_saved' => true,
    'inkop_lines' => [['sku' => 'P1', 'title' => 'Panel', 'qty' => 2, 'shop' => 'aprilice']],
    'leverans' => '2026-10-06',
    'steps' => [],
];
jeab_purchase_put($ada);
$filled = jeab_ordrar_persist_known_shop_order(jeab_purchase_get(23) ?: $ada);
bl_assert(($filled['aprilice_order'] ?? '') === '2026104078', 'tomt Aprilice-nummer på #23 blir 2026104078');
bl_assert(($filled['bestalld_at'] ?? '') === '2026-09-29 13:00:00', '#23 får beställningstiden 13:00');
bl_assert(($filled['steps']['bestalld'] ?? '') === '2026-09-29', '#23 stämplas som beställd');
$adaHtml = jeab_ordrar_status_html($filled, 'jarfalla', 23, null);
bl_assert(str_contains($adaHtml, 'Beställning klar'), '#23 visar beställning klar');
bl_assert(str_contains($adaHtml, '2026104078'), '#23 visar Aprilice-numret');
bl_assert(str_contains($adaHtml, '6 okt 2026'), '#23 visar leveransdatum');
bl_assert(str_contains($adaHtml, '29 sep 2026 13:00'), '#23 visar när beställningen gjordes');
bl_assert(!str_contains($adaHtml, 'Väntar på Bokmalen'), '#23 väntar inte på Bokmalen');
$kept = $filled;
$kept['aprilice_order'] = '1111111111';
jeab_purchase_put($kept);
$notOverwritten = jeab_ordrar_persist_known_shop_order(jeab_purchase_get(23) ?: $kept);
bl_assert(($notOverwritten['aprilice_order'] ?? '') === '1111111111', 'sparat Aprilice-nummer skrivs inte över');

$otherName = [
    'reminder_id' => 23,
    'kind' => 'sol',
    'name' => 'Någon annan',
    'orderrader_saved' => true,
    'inkop_lines' => [['title' => 'Panel', 'qty' => 1]],
];
bl_assert(jeab_ordrar_shop_order_no($otherName, [], 'aprilice') === '', 'order 23 med annat namn får inget känt nummer');

$secretPath = jeab_bokmalen_ordrar_webhook_secret_path();
if (!is_dir(dirname($secretPath))) {
    mkdir(dirname($secretPath), 0775, true);
}
file_put_contents($secretPath, json_encode([
    'url' => 'https://bokmalen.example.invalid/hooks/jeab-ordrar',
    'key' => 'test-key',
], JSON_UNESCAPED_SLASHES));
$GLOBALS['_JEAB_BUG_WEBHOOK_RESPONSE'] = [
    'result' => [
        'inkop_lines' => [
            ['shop' => 'aprilice', 'order_no' => '2026104078'],
        ],
        'leveransdatum' => '2026-10-06',
        'bestalld_at' => '2026-09-29T13:00:00',
    ],
];
$place = [
    'reminder_id' => 8802,
    'kind' => 'sol',
    'company' => 'jarfalla',
    'name' => 'Test Beställ',
    'huvudsokande' => 'Test Beställ',
    'personnummer' => '19800101-1234',
    'fastighetsbeteckning' => 'TEST 1:1',
    'inkop_lines' => [['sku' => 'P1', 'title' => 'Panel', 'qty' => 1, 'shop' => 'aprilice']],
    'orderrader_saved' => true,
    'steps' => [],
];
jeab_purchase_put($place);
$placed = jeab_ordrar_save_bestall([
    'id' => 8802,
    'intent' => 'bestall',
]);
bl_assert(($placed['aprilice_order'] ?? '') === '2026104078', 'beställning sparar Aprilice-numret från svaret');
bl_assert(($placed['leverans'] ?? '') === '2026-10-06', 'beställning sparar leveransdatum från svaret');
bl_assert(($placed['bestalld_at'] ?? '') === '2026-09-29 13:00:00', 'beställning sparar tiden från svaret');
bl_assert((string) ($placed['inkop_lines'][0]['order_no'] ?? '') === '2026104078', 'raden får samma ordernummer');
$placeHtml = jeab_ordrar_status_html($placed, 'jarfalla', 8802, null);
bl_assert(str_contains($placeHtml, '2026104078') && str_contains($placeHtml, '6 okt 2026') && str_contains($placeHtml, '29 sep 2026 13:00'), 'sparad beställning syns på statuskortet');

$already = $placed;
$already['aprilice_order'] = '999';
unset($already['bestalld_at']);
jeab_purchase_put($already);
$GLOBALS['_JEAB_BUG_WEBHOOK_RESPONSE'] = ['aprilice_order' => '2026104078', 'bestalld_at' => '2026-09-29 13:00:00'];
$second = jeab_ordrar_save_bestall(['id' => 8802, 'intent' => 'bestall']);
bl_assert(($second['aprilice_order'] ?? '') === '999', 'nytt svar skriver inte över ett sparat nummer');

$path = $tmp . '/store.json';
$store = new JeabJsonStore($path);
$lead = jeab_create_reminder($store, 'Lisa Lead', '2026-09-29 00:00:00', 'bestall-lead', null, [
    'customer' => 'Lisa Lead',
    'company' => 'jarfalla',
    'tags' => ['vunnen'],
    'address' => 'Storgatan 1, 176 74 Järfälla',
]);
$leadId = (int) ($lead['id'] ?? 0);
bl_assert($leadId > 0, 'leaden skapades');
$started = jeab_purchase_start($store, $leadId, 'sol');
$started['aprilice_order'] = '2026104078';
$started['lightleap_order'] = 'KO9';
$started['bestalld_at'] = '2026-09-29 13:00:00';
$started['bestallning_status'] = 'C';
jeab_purchase_put($started);
$restarted = jeab_purchase_start($store, $leadId, 'sol');
bl_assert(($restarted['aprilice_order'] ?? '') === '2026104078', 'omstart behåller Aprilice-numret');
bl_assert(($restarted['lightleap_order'] ?? '') === 'KO9', 'omstart behåller Lightleap-numret');
bl_assert(($restarted['bestalld_at'] ?? '') === '2026-09-29 13:00:00', 'omstart behåller beställningstiden');
bl_assert(($restarted['bestallning_status'] ?? '') === 'C', 'omstart behåller manuell status');


$krokus = [
    'reminder_id' => 71,
    'kind' => 'batteri',
    'company' => 'jarfalla',
    'name' => 'Leif Ove Görgen Karlsson',
    'huvudsokande' => 'Leif Ove Görgen Karlsson',
    'address' => 'Krokusvägen 10, Järfälla',
    'orderrader_saved' => true,
    'inkop_lines' => [['sku' => 'B1', 'title' => 'Batteri', 'qty' => 1, 'shop' => 'aprilice']],
    'steps' => [],
];
jeab_purchase_put($krokus);
$kFilled = jeab_ordrar_persist_known_shop_order(jeab_purchase_get(71) ?: $krokus);
bl_assert(($kFilled['aprilice_order'] ?? '') === '2026104339', 'tomt Aprilice-nummer på #71 blir 2026104339');
bl_assert(($kFilled['bestalld_at'] ?? '') === '2026-10-01 10:01:00', '#71 får beställningstiden 10:01');
bl_assert(($kFilled['leverans'] ?? '') === '2026-10-07', '#71 får leverans 2026-10-07');
bl_assert(($kFilled['steps']['bestalld'] ?? '') === '2026-10-01', '#71 stämplas som beställd');
$kHtml = jeab_ordrar_status_html($kFilled, 'jarfalla', 71, null);
bl_assert(str_contains($kHtml, 'Beställning klar'), '#71 visar beställning klar');
bl_assert(str_contains($kHtml, '2026104339'), '#71 visar Aprilice-numret');
bl_assert(!str_contains($kHtml, 'Väntar på Bokmalen'), '#71 väntar inte på Bokmalen');
$wrongKarlsson = [
    'reminder_id' => 71,
    'kind' => 'batteri',
    'name' => 'Anders Karlsson',
    'address' => 'Spettvägen 6',
    'orderrader_saved' => true,
    'inkop_lines' => [['title' => 'Batteri', 'qty' => 1]],
];
bl_assert(jeab_ordrar_shop_order_no($wrongKarlsson, [], 'aprilice') === '', 'order 71 utan Krokus får inget känt nummer');

echo "ordrar beställning läge: ok\n";
