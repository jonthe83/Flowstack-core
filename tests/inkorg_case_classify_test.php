<?php

declare(strict_types=1);

/** Klassning i ett Grok-anrop. Påhittade adresser. Inget utskick. */

$tmp = sys_get_temp_dir() . '/jeab-case-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function cc(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function cc_decides(bool $on): void
{
    $path = jeab_inkorg_dir() . '/settings.json';
    $d = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
    if (!is_array($d)) {
        $d = [];
    }
    $d['grok_decides'] = $on;
    file_put_contents($path, json_encode($d, JSON_UNESCAPED_UNICODE));
}

function cc_conf(array $over = []): array
{
    $base = [
        'is_lead_seller' => 0.95,
        'seller_name' => 0.95,
        'customer_email' => 0.95,
        'customer_name' => 0.95,
        'intent' => 0.95,
        'primary_product' => 0.95,
        'kind' => 0.95,
        'type' => 0.95,
        'label' => 0.95,
    ];
    return array_merge($base, $over);
}

function cc_json(array $over = []): string
{
    $base = [
        'type' => 'Ny lead',
        'confidence' => 95,
        'reason' => 'kort',
        'is_lead_seller' => false,
        'seller_name' => 'Nordsolen',
        'customer_email' => 'kund.exempel@example.com',
        'customer_name' => 'Test Person',
        'case_intent' => 'kopintresse',
        'primary_product' => 'Batteri',
        'secondary_products' => [],
        'kind' => 'atgard',
        'label' => '',
        'field_confidence' => cc_conf(),
    ];
    $merged = array_replace($base, $over);
    if (isset($over['field_confidence']) && is_array($over['field_confidence'])) {
        $merged['field_confidence'] = cc_conf($over['field_confidence']);
    }
    return json_encode($merged, JSON_UNESCAPED_UNICODE);
}

$calls = 0;
$GLOBALS['_JEAB_INKORG_LLM'] = function (array $messages) use (&$calls): string {
    $calls++;
    $user = (string) ($messages[1]['content'] ?? '');
    if (str_contains($user, 'SUPPORT-KRYSS')) {
        return cc_json(['case_intent' => 'kopintresse', 'primary_product' => 'Sol', 'is_lead_seller' => true]);
    }
    if (str_contains($user, 'MIX-SOL-BATT')) {
        return cc_json(['primary_product' => 'Batteri', 'secondary_products' => ['Sol']]);
    }
    if (str_contains($user, 'OFF-SAKNAS')) {
        return cc_json(['is_lead_seller' => true, 'seller_name' => 'Offs', 'customer_email' => '', 'primary_product' => 'Batteri', 'type' => 'Ny lead']);
    }
    if (str_contains($user, 'OFF-REST')) {
        return cc_json(['type' => 'Information', 'kind' => 'information', 'label' => '', 'case_intent' => '', 'primary_product' => '', 'is_lead_seller' => false]);
    }
    if (str_contains($user, 'SAKNAS-MEJL')) {
        return cc_json(['is_lead_seller' => true, 'seller_name' => 'Weply', 'customer_email' => '', 'field_confidence' => ['customer_email' => 0.99]]);
    }
    if (str_contains($user, 'LAG-SAKERHET')) {
        return cc_json(['is_lead_seller' => true, 'seller_name' => 'Lag', 'field_confidence' => ['primary_product' => 0.4, 'is_lead_seller' => 0.4]]);
    }
    if (str_contains($user, 'GMAIL-PERSON')) {
        return cc_json(['is_lead_seller' => true, 'seller_name' => 'Person']);
    }
    if (str_contains($user, 'PNR-NAMN')) {
        return cc_json(['seller_name' => 'Test 19900101-1234', 'customer_name' => 'Konto 12345678901', 'reason' => 'mejl kund.exempel@example.com']);
    }
    if (str_contains($user, 'APRIL-LAGER') || str_contains($user, 'LIGHT-LAGER')) {
        return cc_json([
            'type' => 'Ny lead',
            'priority' => 1,
            'case_intent' => 'kopintresse',
            'primary_product' => '',
            'kind' => 'information',
            'label' => '',
            'is_lead_seller' => false,
        ]);
    }
    if (str_contains($user, 'APRIL-REST') || str_contains($user, 'LIGHT-REST')) {
        return cc_json([
            'type' => 'Information',
            'kind' => 'information',
            'label' => '',
            'case_intent' => 'kopintresse',
            'primary_product' => '',
            'is_lead_seller' => false,
        ]);
    }
    if (str_contains($user, 'QUOTE-BATT')) {
        return cc_json(['customer_email' => 'kund.batt@example.com', 'primary_product' => 'Batteri']);
    }
    if (str_contains($user, 'EVAL-MIX')) {
        return cc_json(['primary_product' => 'Batteri', 'secondary_products' => ['Sol']]);
    }
    if (str_contains($user, 'EVAL-APR-OK') || str_contains($user, 'EVAL-LL-OK')) {
        return cc_json(['type' => 'Information', 'kind' => 'information', 'case_intent' => '', 'primary_product' => '', 'label' => '', 'is_lead_seller' => false]);
    }
    if (str_contains($user, 'EVAL-APR-REST') || str_contains($user, 'EVAL-LL-REST')) {
        return cc_json(['type' => 'Information', 'kind' => 'information', 'label' => '', 'case_intent' => '', 'primary_product' => '', 'is_lead_seller' => false]);
    }
    if (str_contains($user, 'SERVICE-JOBB')) {
        return cc_json(['primary_product' => 'Servicejobb', 'case_intent' => 'kopintresse']);
    }
    return cc_json();
};

$msg = static function (string $from, string $subject, string $body): array {
    return ['from' => $from, 'from_name' => 'Desk', 'subject' => $subject, 'body' => $body, 'source' => 'test-unit', 'test' => true];
};

cc(jeab_inkorg_case_decides() === false && empty(jeab_auto_settings()['grok_decides']), 'brytaren är av som standard');
cc_decides(true);
cc(jeab_inkorg_case_decides() === true, 'brytaren kan slås på');

$seller = jeab_inkorg_classify($msg('desk@nordsolen.example', 'Nytt lead NORD', "Namn: Test Person\nE-post: kund.exempel@example.com\nVill ha batteri till huset."));
cc(($seller['case_class']['is_lead_seller'] ?? false) === false, 'vanlig mock utan säljarflagga');
$seller = jeab_inkorg_classify($msg('desk@nordsolen.example', 'Nytt lead NORD SAKNAS-MEJL', "Namn: Test Person\nVill ha batteri.\nSAKNAS-MEJL"));
cc(!empty($seller['case_class']['is_lead_seller']), 'Nordsolen-stil är leadsäljare');
cc(in_array('nordsolen.example', jeab_lead_seller_domains(), true), 'domänen läggs i stödlistan');
cc(jeab_inkorg_case_hold_why($seller['case_class']) === 'kundens e-post saknas i leaden', 'saknad kundadress håller');
cc(jeab_lead_purchased(['from' => 'desk@nordsolen.example', 'labels' => $seller]), 'köpt lead enligt Grok');
cc(jeab_auto_quote_seller_guard(['from' => 'desk@nordsolen.example', 'labels' => $seller, 'headers' => ['From' => 'desk@nordsolen.example']], 'desk@nordsolen.example') !== '', 'säljardomän är aldrig mottagare');
cc(jeab_lead_purchased_customer_email(['from' => 'desk@nordsolen.example', 'labels' => $seller])['email'] === '', 'ingen kundadress att skicka till');
$dry = jeab_auto_quote(
    ['id' => 't-saknas', 'messages' => [['from' => 'desk@nordsolen.example', 'labels' => $seller, 'body' => 'SAKNAS-MEJL', 'source' => 'test-unit']], 'tags' => [], 'drafts' => [], 'status' => 'open'],
    ['from' => 'desk@nordsolen.example', 'labels' => $seller, 'body' => 'SAKNAS-MEJL', 'subject' => 'SAKNAS-MEJL', 'source' => 'test-unit'],
    jeab_auto_settings(),
    [],
    ['dry' => true]
);
cc(($dry['action'] ?? '') === 'hold' && ($dry['why'] ?? '') === 'kundens e-post saknas i leaden', 'offerten hålls utan kundadress');

$low = jeab_inkorg_classify($msg('desk@lag.example', 'LAG-SAKERHET', 'Kunden vill ha batteri. LAG-SAKERHET'));
cc(str_starts_with((string) jeab_inkorg_case_hold_why($low['case_class']), 'Grok är osäker'), 'låg säkerhet håller');
cc(!in_array('lag.example', jeab_lead_seller_domains(), true), 'låg säkerhet fyller inte listan');
$dryLow = jeab_auto_quote(
    ['id' => 't-lag', 'messages' => [['from' => 'desk@lag.example', 'labels' => $low, 'body' => 'LAG-SAKERHET', 'source' => 'test-unit']], 'tags' => [], 'drafts' => [], 'status' => 'open'],
    ['from' => 'desk@lag.example', 'labels' => $low, 'body' => 'LAG-SAKERHET', 'subject' => 'LAG-SAKERHET', 'source' => 'test-unit'],
    jeab_auto_settings(),
    [],
    ['dry' => true]
);
cc(($dryLow['action'] ?? '') === 'hold', 'offerten hålls vid låg säkerhet');

$gmail = jeab_inkorg_classify($msg('person@gmail.com', 'GMAIL-PERSON', 'E-post: annan@example.com GMAIL-PERSON'));
cc(!in_array('gmail.com', jeab_lead_seller_domains(), true), 'gmail läggs inte i listan');

$GLOBALS['_JEAB_INKORG_REPROCESS'] = true;
$before = jeab_lead_seller_domains();
jeab_inkorg_classify($msg('desk@reprocess.example', 'SAKNAS-MEJL', 'SAKNAS-MEJL batteri'));
cc(jeab_lead_seller_domains() === $before, 'omklassning fyller inte listan');
unset($GLOBALS['_JEAB_INKORG_REPROCESS']);
$GLOBALS['_JEAB_HS_IMPORTING'] = true;
jeab_inkorg_classify($msg('desk@import.example', 'SAKNAS-MEJL', 'SAKNAS-MEJL batteri'));
cc(!in_array('import.example', jeab_lead_seller_domains(), true), 'Help Scout-import fyller inte listan');
unset($GLOBALS['_JEAB_HS_IMPORTING']);

$support = jeab_inkorg_classify($msg('form@example.com', 'SUPPORT-KRYSS', "Vad gäller det: Support till befintlig kund\nProdukt: solceller\nSUPPORT-KRYSS"));
cc(($support['case_class']['intent'] ?? '') === 'support' && ($support['type'] ?? '') === 'Support', 'support-kryss är alltid support');
cc(empty($support['case_class']['is_lead_seller']), 'support-kryss är inte leadsäljare');
cc(!jeab_lead_is_lead(['id' => 's', 'tags' => []], ['from' => 'form@example.com', 'labels' => $support, 'body' => $support ? "Support till befintlig kund\nSUPPORT-KRYSS" : '']), 'support är inte lead');

$mix = jeab_inkorg_classify($msg('kund.mix@brevlada.se', 'MIX-SOL-BATT', 'Vi vill ha solceller och ett batteri. MIX-SOL-BATT'));
cc(($mix['case_class']['primary_product'] ?? '') === 'Batteri' && ($mix['case_class']['secondary_products'] ?? []) === ['Sol'], 'Grok sätter produkten även när texten nämner både sol och batteri');
cc(jeab_lead_infer_offer(['from' => 'kund.mix@brevlada.se', 'labels' => $mix, 'subject' => 'solceller och batteri', 'body' => 'solceller och batteri']) === 'batteri', 'offerten följer Groks produkt');
cc(jeab_inkorg_case_product_line($mix['case_class']) === 'Produkt: Batteri (även Sol)', 'en produktrad');
cc((int) ($mix['priority'] ?? 0) === jeab_inkorg_default_priority('Ny lead'), 'prioritet ur specen');

$pnr = jeab_inkorg_classify($msg('desk@nordsolen.example', 'PNR-NAMN', 'PNR-NAMN batteri'));
cc(!str_contains((string) ($pnr['case_class']['seller_name'] ?? ''), '19900101') && str_contains((string) ($pnr['case_class']['seller_name'] ?? ''), '[pnr]'), 'personnummer maskeras');
cc(!str_contains((string) ($pnr['case_class']['customer_name'] ?? ''), '12345678901'), 'kontonummer maskeras');
cc(jeab_inkorg_case_mask_text('hs-3480042636') === 'hs-3480042636', 'hs-tråd maskeras inte');
cc(jeab_inkorg_case_mask_text('g-3480042636') === 'g-3480042636', 'g-tråd maskeras inte');
cc(jeab_inkorg_case_mask_text('hs-3480042636 19900101-1234') === 'hs-3480042636 [pnr]', 'personnummer maskeras bredvid hs-id');
cc(jeab_inkorg_case_mask_text('199001011234') === '[pnr]', 'personnummer utan bindestreck maskeras');
cc(!str_contains((string) ($pnr['reason'] ?? ''), 'kund.exempel@example.com'), 'orsaken har inte hela e-postadressen');

jeab_purchase_put([
    'reminder_id' => 44021,
    'address' => 'Exempelgatan 12',
    'aprilice_order' => '44021',
    'kind' => 'sol',
]);
jeab_purchase_put([
    'reminder_id' => 7782,
    'address' => 'Exempelstigen 4',
    'lightleap_order' => 'LL-7782',
    'kind' => 'batteri',
]);

$aprilOk = jeab_inkorg_classify($msg('order@aprilice.example', 'Orderbekräftelse APRIL-LAGER', "Ordernummer: 44020\nArtikel: Panel 410W\nStatus: i lager\nAPRIL-LAGER"));
cc(($aprilOk['case_class']['kind'] ?? '') === 'information' && ($aprilOk['type'] ?? '') === 'Information' && ($aprilOk['case_class']['label'] ?? '') === '', 'Aprilice utan avvikelse är information');
cc((int) ($aprilOk['priority'] ?? 0) === 4, 'information har prioritet 4');

$aprilRest = jeab_inkorg_classify($msg('order@aprilice.example', 'Orderbekräftelse APRIL-REST', "Ordernummer: 44021\nArtikel: Växelriktare\nRestnoterad, ej i lager\nAPRIL-REST"));
cc(($aprilRest['case_class']['kind'] ?? '') === 'atgard' && ($aprilRest['case_class']['label'] ?? '') === 'Restnoterad/ej i lager', 'Aprilice-rest är åtgärd');
cc(($aprilRest['type'] ?? '') === 'Kräver åtgärd från oss' && (int) ($aprilRest['priority'] ?? 0) === 2, 'restnotering använder befintlig typ och prioritet');
cc(in_array('restnoterad', (array) ($aprilRest['add_tags'] ?? []), true), 'etiketten Restnoterad följer med');
cc((int) ($aprilRest['case_class']['order_link']['reminder_id'] ?? 0) === 44021 && ($aprilRest['case_class']['order_link']['via'] ?? '') === 'order', 'restnotering länkas till ordern');

$lightOk = jeab_inkorg_classify($msg('notify@lightleap.example', 'Order confirmation LIGHT-LAGER', "Order LL-7781\nAll lines in stock.\nLIGHT-LAGER"));
cc(($lightOk['case_class']['kind'] ?? '') === 'information' && ($lightOk['case_class']['label'] ?? '') === '', 'Lightleap utan avvikelse är information');
$lightRest = jeab_inkorg_classify($msg('notify@lightleap.example', 'Order confirmation LIGHT-REST', "Order LL-7782\nItem: cable\nBackorder, out of stock\nLIGHT-REST"));
cc(($lightRest['case_class']['kind'] ?? '') === 'atgard' && ($lightRest['case_class']['label'] ?? '') === 'Restnoterad/ej i lager', 'Lightleap-rest är åtgärd');
cc((int) ($lightRest['case_class']['order_link']['reminder_id'] ?? 0) === 7782, 'Lightleap länkas på ordernummer');

$addrOnly = jeab_inkorg_case_link_order(['subject' => 'Restnoterad', 'body' => "Ingen orderträff ZZ-1\nExempelstigen 4\n"]);
cc((int) ($addrOnly['reminder_id'] ?? 0) === 7782 && ($addrOnly['via'] ?? '') === 'address', 'restnotering kan länkas på adress');

$service = jeab_inkorg_classify($msg('kund.svc@example.com', 'SERVICE-JOBB', 'Behöver service på anläggningen. SERVICE-JOBB'));
cc(($service['case_class']['primary_product'] ?? '') === 'Servicejobb' && in_array('servicejobb', (array) ($service['add_tags'] ?? []), true), 'servicejobb sätter etiketten');
cc(jeab_lead_infer_offer(['labels' => $service, 'body' => 'batteri']) === '', 'servicejobb väljer ingen offertmall');

$beforeIngest = $calls;
$saved = jeab_inkorg_ingest([
    'message_id' => 'store-1@case.test',
    'from' => 'desk@nordsolen.example',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Förfrågan batteri STORE',
    'body_text' => "E-post: kund.exempel@example.com\nVill ha batteri.",
    'source' => 'test-unit',
    'test' => true,
]);
cc($saved['ok'] && $calls === $beforeIngest + 1, 'ett anrop per nytt mejl');
$thread = null;
foreach (jeab_inkorg_threads() as $t) {
    if ($t['id'] === $saved['thread_id']) {
        $thread = $t;
    }
}
cc(is_array($thread) && ($thread['case_class']['primary_product'] ?? '') === 'Batteri', 'JSON sparas på ärendet');
$frozen = $calls;
jeab_inkorg_threads();
cc($calls === $frozen, 'sidvisning räknar inte om');
cc(jeab_inkorg_case_product_line_for($thread, (array) $thread['labels']) === 'Produkt: Batteri', 'ärendet visar produktraden');

$preset = static function (string $id, string $from, string $subject, string $body, array $labels): string {
    $res = jeab_inkorg_ingest([
        'message_id' => $id . '@case.test',
        'from' => $from,
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => $subject,
        'body_text' => $body,
        'source' => 'test-unit',
        'test' => true,
        'preset_labels' => $labels + ['action' => 'svara', 'confidence' => 90, 'source' => 'manual'],
    ]);
    cc(!empty($res['ok']), 'eval-ärende ' . $id);
    return (string) $res['thread_id'];
};
$leadLabels = ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'batteri', 'product' => 'batteri', 'priority' => 1];
$quoteTid = $preset('quote-batt', 'kund.batt@example.com', 'QUOTE-BATT', 'Vill ha batteri. QUOTE-BATT', $leadLabels);
jeab_inkorg_thread_write($quoteTid, static function (array $t): array {
    $t['quote_sent'] = ['source' => 'note', 'date' => '2026-10-01', 'product' => 'Batteri'];
    $t['lead_customer'] = ['email' => 'kund.batt@example.com', 'name' => 'Test Person'];
    return $t;
});
$preset('eval-mix', 'kund.mix2@example.com', 'EVAL-MIX', "Vi nämner solceller. Vi nämner batteri.\nEVAL-MIX", ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'sol', 'product' => 'solceller', 'priority' => 1]);
$info = ['type' => 'Information', 'intent' => 'information', 'offer' => '', 'product' => '', 'priority' => 4];
$preset('eval-apr-ok', 'order@aprilice.example', 'Orderbekräftelse EVAL-APR-OK', "Ordernummer: 100\nI lager\nEVAL-APR-OK", $info);
$preset('eval-apr-rest', 'order@aprilice.example', 'Orderbekräftelse EVAL-APR-REST', "Ordernummer: 101\nRestnoterad\nEVAL-APR-REST", $info);
$preset('eval-ll-ok', 'notify@lightleap.example', 'Order confirmation EVAL-LL-OK', "Order LL-1\nIn stock\nEVAL-LL-OK", $info);
$preset('eval-ll-rest', 'notify@lightleap.example', 'Order confirmation EVAL-LL-REST', "Order LL-2\nBackorder\nEVAL-LL-REST", $info);

$eval = jeab_inkorg_hook(['action' => 'inbox.classify_eval', 'dry' => 1]);
cc(!empty($eval['ok']) && (int) ($eval['dry'] ?? 0) === 1, 'eval-hooken är läsning');
cc((int) ($eval['quoted'] ?? 0) === 1 && (int) ($eval['mixed'] ?? 0) === 1 && (int) ($eval['supplier'] ?? 0) === 4, 'eval tar offert, blandade ord och leverantörsbekräftelser');
cc((int) ($eval['total'] ?? 0) === 6 && array_key_exists('next_offset', $eval) && $eval['next_offset'] === null && count((array) ($eval['rows'] ?? [])) === 6, 'standardsidan är 8 och det lilla urvalet ryms');
$beforePage = $calls;
$pageA = jeab_inkorg_hook(['action' => 'inbox.classify_eval', 'dry' => 1, 'limit' => 2, 'offset' => 0]);
$pageB = jeab_inkorg_hook(['action' => 'inbox.classify_eval', 'dry' => 1, 'limit' => 2, 'offset' => 2]);
$pageC = jeab_inkorg_hook(['action' => 'inbox.classify_eval', 'dry' => 1, 'limit' => 2, 'offset' => 4]);
$joined = array_merge(array_column((array) ($pageA['rows'] ?? []), 'thread'), array_column((array) ($pageB['rows'] ?? []), 'thread'), array_column((array) ($pageC['rows'] ?? []), 'thread'));
cc($calls === $beforePage + 6 && count((array) ($pageA['rows'] ?? [])) === 2 && $pageA['next_offset'] === 2 && $pageB['next_offset'] === 4 && $pageC['next_offset'] === null && $joined === array_column((array) $eval['rows'], 'thread'), 'sidor med limit och offset går att lägga ihop');
cc((int) ($pageA['quoted'] ?? 0) === 1 && isset($pageA['accuracy']['kind']['n'], $pageA['mismatches']), 'sidan behåller träff och urvalsantal');
$acc = (array) ($eval['accuracy'] ?? []);
cc((int) ($acc['product']['hit'] ?? 0) === 1 && (int) ($acc['product']['n'] ?? 0) === 2, 'produktträff mot sparad produkt');
cc((int) ($acc['intent']['hit'] ?? 0) === 2 && (int) ($acc['intent']['n'] ?? 0) === 2, 'avsiktsträff');
cc((int) ($acc['recipient']['hit'] ?? 0) === 1 && (int) ($acc['recipient']['n'] ?? 0) === 1, 'mottagarträff');
cc((int) ($acc['kind']['hit'] ?? 0) === 4 && (int) ($acc['kind']['n'] ?? 0) === 6, 'slag mot sparad klassning');
cc((int) ($acc['type']['hit'] ?? 0) === 4 && (int) ($acc['type']['n'] ?? 0) === 6, 'typ mot sparad klassning');
cc((int) ($acc['label']['hit'] ?? 0) === 4 && (int) ($acc['label']['n'] ?? 0) === 6, 'etikett mot sparad klassning');
$keys = array_keys($eval);
cc(array_search('accuracy', $keys, true) < array_search('rows', $keys, true) && array_search('mismatches', $keys, true) < array_search('rows', $keys, true), 'träff och avvikelser ligger före raderna');
$miss = (array) ($eval['mismatches'] ?? []);
cc(count($miss) === 7, 'avvikelserna är de fält som inte träffar');
$missOk = $miss !== [];
$sawProduct = false;
foreach ($miss as $one) {
    if (!is_array($one) || !isset($one['thread'], $one['field'], $one['old'], $one['new'], $one['confidence'])) {
        $missOk = false;
    }
    if (($one['field'] ?? '') === 'product' && ($one['old'] ?? '') === 'Sol' && ($one['new'] ?? '') === 'Batteri') {
        $sawProduct = true;
    }
}
cc($missOk && $sawProduct, 'en avvikelse är tråd, fält, gammalt, nytt och säkerhet');
$blob = json_encode($eval, JSON_UNESCAPED_UNICODE);
cc(is_string($blob) && !str_contains($blob, 'kund.batt@example.com') && !str_contains($blob, 'kund.exempel@example.com'), 'eval skriver inte hela e-postadresser');
cc(is_string($blob) && str_contains($blob, 'k***@example.com'), 'eval maskerar mottagaren');
$refused = jeab_inkorg_hook(['action' => 'inbox.classify_eval', 'dry' => '0']);
cc(empty($refused['ok']) && ($refused['error'] ?? '') === 'classify_eval är bara läsning', 'eval utan dry skriver inte');

cc(str_contains((string) file_get_contents(dirname(__DIR__) . '/src/pages/inkorg.php'), 'jeab_inkorg_case_product_line_for'), 'ärendesidan visar produktraden');
$regler = (string) file_get_contents(dirname(__DIR__) . '/src/pages/inkorg_regler.php');
cc(str_contains($regler, 'Stödlista') && str_contains($regler, 'name="grok_decides"') && str_contains($regler, 'Grok bestämmer produkt, avsikt och säljare'), 'listan på Regler är en stödlista');
cc(isset(jeab_inkorg_tags()['restnoterad']), 'etiketten Restnoterad finns');

cc_decides(false);
cc(jeab_inkorg_case_decides() === false, 'brytaren är av igen');
$offSeller = jeab_inkorg_classify($msg('desk@offseller.example', 'OFF-SAKNAS', "Vill ha batteri.\nOFF-SAKNAS"));
cc(!empty($offSeller['case_class']['is_lead_seller']) && ($offSeller['case_class']['primary_product'] ?? '') === 'Batteri', 'avstängd brytare sparar ändå Groks svar');
cc(($offSeller['type'] ?? '') === 'Ny lead' && ($offSeller['offer'] ?? '') === '' && ($offSeller['lead'] ?? '') === 'eget' && !in_array('restnoterad', (array) ($offSeller['add_tags'] ?? []), true), 'avstängd brytare ändrar inte typ, offert eller säljare');
cc(!in_array('offseller.example', jeab_lead_seller_domains(), true), 'avstängd brytare fyller inte stödlistan från is_lead_seller');
cc(jeab_lead_purchased(['from' => 'desk@offseller.example', 'labels' => $offSeller]) === false, 'avstängd brytare gör inte avsändaren till leadsäljare');
cc(jeab_lead_infer_offer(['from' => 'desk@offseller.example', 'labels' => $offSeller, 'subject' => 'solceller och batteri', 'body' => 'solceller och batteri']) === '', 'avstängd brytare låter inte primärprodukten styra offerten');
cc(jeab_inkorg_case_of(['labels' => $offSeller]) === [] && jeab_inkorg_case_product_line_for(['case_class' => $offSeller['case_class']], $offSeller) === '', 'avstängd brytare visar inte den nya produktraden');
$manual = [
    'from' => 'kund.vanlig@example.com',
    'labels' => [
        'type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'sol', 'product' => 'solceller', 'lead' => 'eget',
        'case_class' => ['primary_product' => 'Batteri', 'intent' => 'support', 'kind' => 'information', 'is_lead_seller' => true, 'confidence' => cc_conf()],
    ],
    'subject' => 'solceller',
    'body' => 'solceller',
];
cc(jeab_lead_infer_offer($manual) === 'sol' && jeab_lead_is_lead(['id' => 'm', 'tags' => []], $manual) === true && jeab_lead_purchased($manual) === false, 'avstängd brytare behåller sparad produkt, avsikt och säljare');
$offRest = jeab_inkorg_classify($msg('order@aprilice.example', 'Orderbekräftelse OFF-REST', "Ordernummer: 9\nRestnoterad\nOFF-REST"));
cc(($offRest['type'] ?? '') === 'Information' && (int) ($offRest['priority'] ?? 0) === 4 && !in_array('restnoterad', (array) ($offRest['add_tags'] ?? []), true), 'avstängd brytare låter restnotering vara Information');
cc(($offRest['case_class']['kind'] ?? '') === 'atgard' && ($offRest['case_class']['label'] ?? '') === 'Restnoterad/ej i lager', 'avstängd brytare har ändå restnotering i svaret');
jeab_inkorg_finish_labels(['lead' => 'kopt', 'type' => 'Ny lead', 'confidence' => 90, 'source' => 'llm'], [
    'from' => 'desk@offlist.example',
    'body' => "Namn: Köpare\nE-post: f.buyer@post.example\n",
]);
cc(jeab_lead_seller_match('desk@offlist.example') !== '' && jeab_auto_quote_seller_guard(['from' => 'desk@offlist.example', 'headers' => ['From' => 'desk@offlist.example']], 'desk@offlist.example') !== '', 'spärren mot säljardomän gäller när brytaren är av');
cc(jeab_auto_quote_seller_guard(['from' => 'desk@offlist.example', 'labels' => ['lead' => 'kopt'], 'headers' => ['From' => 'desk@offlist.example']], 'f.buyer@post.example') === '', 'kundadressen stoppas inte av spärren');
$beforeOff = $calls;
$evalOff = jeab_inkorg_hook(['action' => 'inbox.classify_eval', 'dry' => 1]);
cc($calls > $beforeOff && !empty($evalOff['ok']) && (int) ($evalOff['accuracy']['kind']['hit'] ?? 0) === 4 && count((array) ($evalOff['mismatches'] ?? [])) === 7, 'eval anropar Grok även när brytaren är av');
$offBlob = json_encode($evalOff['mismatches'] ?? [], JSON_UNESCAPED_UNICODE);
cc(is_string($offBlob) && !str_contains($offBlob, 'kund.batt@example.com') && !str_contains($offBlob, 'Test Person'), 'avvikelserna har varken namn eller hela e-post');
for ($n = 0; $n < 6; $n++) {
    $pageTid = $preset('page-' . $n, 'page' . $n . '.eval@example.com', 'PAGE-' . $n, 'Vill ha batteri. PAGE-' . $n, $leadLabels);
    jeab_inkorg_thread_write($pageTid, static function (array $t): array {
        $t['quote_sent'] = ['source' => 'note', 'date' => '2026-10-02', 'product' => 'Batteri'];
        return $t;
    });
}
$beforeCap = $calls;
$capped = jeab_inkorg_hook(['action' => 'inbox.classify_eval', 'dry' => 1, 'limit' => 99, 'offset' => 0]);
$tail = jeab_inkorg_hook(['action' => 'inbox.classify_eval', 'dry' => 1, 'limit' => 8, 'offset' => 10]);
$pastCalls = $calls;
$past = jeab_inkorg_hook(['action' => 'inbox.classify_eval', 'dry' => 1, 'offset' => 40]);
$refusedPage = jeab_inkorg_hook(['action' => 'inbox.classify_eval', 'dry' => '0', 'limit' => 2, 'offset' => 0]);
cc($calls === $beforeCap + 12 && $pastCalls === $calls && count((array) ($capped['rows'] ?? [])) === 10 && $capped['next_offset'] === 10 && (int) ($capped['total'] ?? 0) === 12 && (int) ($capped['quoted'] ?? 0) === 7 && count((array) ($tail['rows'] ?? [])) === 2 && $tail['next_offset'] === null && ($past['rows'] ?? null) === [] && $past['next_offset'] === null && empty($refusedPage['ok']) && ($refusedPage['error'] ?? '') === 'classify_eval är bara läsning', 'limit takas vid 10 och dry=0 skrivs inte');

echo "calls {$calls}\n";
