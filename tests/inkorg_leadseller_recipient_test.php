<?php

declare(strict_types=1);

/** Köpt lead ska till kunden i texten, aldrig till säljaren. Påhittade domäner. */

$tmp = sys_get_temp_dir() . '/jeab-leadseller-' . bin2hex(random_bytes(4));
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

function ls_ok(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = function (array $a) {
    $GLOBALS['_SENT'][] = $a;
    $person = trim((string) ($a['opts']['person_email'] ?? ''));
    $from = $person !== '' ? $person : jeab_graph_mailboxes()[$a['company']];
    return ['ok' => true, 'from' => $from, 'graph_id' => 'g-ls', 'message_id' => 'sent-ls@local'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = function (string $method, string $url) {
    if (str_contains($url, 'mailFolders/sentitems?')) {
        return ['code' => 200, 'body' => ['id' => 'sent-folder']];
    }
    return ['code' => 200, 'body' => ['value' => []]];
};
$GLOBALS['_JEAB_REBOOK_LLM'] = fn() => json_encode(['answer' => '']);

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode('%PDF-1.4 batteri')]);
jeab_auto_pdf_save(['key' => 'sol_jarfalla', 'data' => base64_encode('%PDF-1.4 sol')]);
jeab_auto_pdf_save(['key' => 'laddbox_jarfalla', 'data' => base64_encode('%PDF-1.4 ladd')]);

$seed = jeab_lead_seller_domains();
ls_ok(in_array('nordsolen.se', $seed, true) && in_array('weply.se', $seed, true) && in_array('brabyggfirmor.se', $seed, true) && in_array('leadkit.com', $seed, true) && in_array('hitta-solceller.se', $seed, true), 'kända leadsäljare är förifyllda');
$regler = (string) file_get_contents(dirname(__DIR__) . '/src/pages/inkorg_regler.php');
ls_ok(substr_count($regler, 'name="lead_sellers"') === 1, 'en lista för leadsäljare');

$box = jeab_graph_mailboxes()['jarfalla'];
$customer = 'a.buyer@post.example';
$sellerPerson = 'person@seller.example';
$sellerGeneral = 'info@seller.example';

function ls_in(string $id, string $from, string $body, array $labels, string $thread, array $headers = []): array
{
    global $box;
    return jeab_inkorg_ingest([
        'message_id' => $id . '@lead.test',
        'from' => $from,
        'from_name' => 'Lead',
        'to' => $box,
        'subject' => 'Ny lead',
        'body_text' => $body,
        'source' => 'graph-jarfalla',
        'thread_key' => $thread,
        'graph_mailbox' => $box,
        'company' => 'jarfalla',
        'headers' => $headers,
        'preset_labels' => $labels,
    ]);
}

$leadBody = "Kontaktperson: Säljare\nE-post: {$sellerPerson}\nNamn: Köpare Exempel\nE-post: {$customer}\nAdress: Exempelvägen 1, 177 80 Järfälla\n";
$headers = ['Reply-To' => $sellerPerson, 'Cc' => $sellerGeneral];
$labels = ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'sol', 'lead' => 'kopt', 'product' => 'solceller', 'confidence' => 100, 'question' => ''];
$in = ls_in('buy', $sellerPerson, $leadBody, $labels, 'lead-buy', $headers);
$before = count($GLOBALS['_SENT']);
$auto = jeab_auto_on_message((string) $in['id']);
$sent = $GLOBALS['_SENT'][$before] ?? [];
ls_ok(($auto['action'] ?? '') === 'quoted' && ($auto['mall'] ?? '') === 'A' && ($sent['to'] ?? '') === $customer && ($sent['cc'] ?? []) === [], 'köpt lead med kundadress går till kunden utan kopia');
ls_ok(!str_contains((string) ($sent['to'] ?? ''), 'seller.example') && ($auto['to'] ?? '') === $customer, 'säljarens adress är inte mottagare');

$before = count($GLOBALS['_SENT']);
$none = ls_in('none', $sellerPerson, "Kontaktperson: Säljare\nVi har en ny förfrågan utan adress.\n", $labels, 'lead-none', $headers);
$noneRes = jeab_auto_on_message((string) $none['id']);
ls_ok(count($GLOBALS['_SENT']) === $before && ($noneRes['action'] ?? '') === 'hold' && str_contains((string) ($noneRes['why'] ?? ''), 'hittar ingen kundadress'), 'utan kundadress hålls ärendet');

$before = count($GLOBALS['_SENT']);
$same = ls_in('same', $sellerPerson, "Namn: Köpare Exempel\nE-post: buyer@seller.example\n", $labels, 'lead-same', $headers);
$sameRes = jeab_auto_on_message((string) $same['id']);
ls_ok(count($GLOBALS['_SENT']) === $before && ($sameRes['action'] ?? '') === 'hold' && str_contains((string) ($sameRes['why'] ?? ''), 'samma domän'), 'kundadress på säljarens domän hålls');

$guard = jeab_auto_quote_seller_guard(['from' => $sellerPerson, 'labels' => ['lead' => 'kopt'], 'headers' => $headers], $sellerPerson);
ls_ok($guard === 'mottagaren har samma domän som leadsäljaren', 'spärren nekar säljarens domän');
$listed = jeab_auto_quote_seller_guard(['from' => $customer, 'labels' => ['lead' => 'eget']], 'desk@weply.se');
ls_ok($listed === 'mottagaren är en leadsäljare', 'spärren nekar en domän på leadsäljarlistan');

$before = count($GLOBALS['_SENT']);
$formCustomer = 'b.buyer@post.example';
$form = ls_in('form', $formCustomer, "Hej, jag vill ha ett batteri.\n", [
    'type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'batteri', 'lead' => 'eget', 'product' => 'batteri', 'confidence' => 100, 'question' => '',
], 'lead-form');
$formRes = jeab_auto_on_message((string) $form['id']);
$formSent = $GLOBALS['_SENT'][$before] ?? [];
ls_ok(($formRes['action'] ?? '') === 'quoted' && ($formSent['to'] ?? '') === $formCustomer, 'vanligt kundmejl går fortfarande till avsändaren');

$laterCustomer = 'd.buyer@post.example';
$laterBody = "Namn: Köpare Exempel\nE-post: {$laterCustomer}\nTelefon: 070-700 00 04\nAdress: Senarevägen 4, 177 80 Järfälla\n";
$leadIn = jeab_inkorg_ingest([
    'message_id' => 'later-lead@lead.test',
    'from' => 'desk@seller.example',
    'from_name' => 'Lead',
    'to' => $box,
    'subject' => 'Ny lead',
    'body_text' => $laterBody,
    'source' => 'graph-jarfalla',
    'thread_key' => 'lead-later',
    'graph_mailbox' => $box,
    'graph_id' => 'g-lead',
    'company' => 'jarfalla',
    'received_at' => '2026-10-18T08:00:00+02:00',
    'headers' => ['Cc' => $sellerGeneral],
    'preset_labels' => $labels,
]);
$follow = jeab_inkorg_ingest([
    'message_id' => 'later-follow@lead.test',
    'from' => $sellerPerson,
    'from_name' => 'Kontakt',
    'to' => $box,
    'subject' => 'Ny lead',
    'body_text' => "Hej, har ni sett förfrågan?\n",
    'source' => 'graph-jarfalla',
    'thread_key' => 'lead-later',
    'graph_mailbox' => $box,
    'graph_id' => 'g-follow',
    'company' => 'jarfalla',
    'received_at' => '2026-10-18T09:00:00+02:00',
    'headers' => ['Cc' => $sellerGeneral, 'Reply-To' => $sellerPerson],
    'preset_labels' => ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'laddbox', 'lead' => 'eget', 'product' => 'elbilsladdare', 'confidence' => 100, 'question' => ''],
]);
$picked = jeab_auto_quote_lead_message(jeab_auto_thread_by_id((string) $leadIn['thread_id']) ?? []);
ls_ok((string) ($picked['graph_id'] ?? '') === 'g-lead', 'leadtexten väljs före säljarens senare mejl');
$before = count($GLOBALS['_SENT']);
$laterRes = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $follow['thread_id'], 'dry' => 0]);
$laterSent = $GLOBALS['_SENT'][$before] ?? [];
ls_ok(($laterRes['action'] ?? '') === 'quoted' && ($laterRes['mall'] ?? '') === 'A' && ($laterSent['to'] ?? '') === $laterCustomer && ($laterSent['cc'] ?? []) === [] && (string) ($laterSent['opts']['reply_to_graph_id'] ?? 'x') === '' && !empty($laterSent['opts']['strip_cc']), 'senare mejl från säljaren kopierar inte mottagare eller kopia');

$gmailLearned = jeab_inkorg_finish_labels(['lead' => 'kopt', 'type' => 'Ny lead', 'confidence' => 90, 'source' => 'llm'], [
    'from' => 'a.buyer@gmail.com',
    'body' => "Namn: Köpare Exempel\nE-post: a.buyer@post.example\n",
]);
ls_ok(($gmailLearned['lead'] ?? '') === 'kopt' && jeab_lead_seller_match('a.buyer@gmail.com') === '' && jeab_lead_personal_domain('hotmail.se') && jeab_lead_personal_domain('yahoo.co.uk') && jeab_lead_personal_domain('outlook.com'), 'gmail som köpt lead läggs inte i listan');
$before = count($GLOBALS['_SENT']);
$wixGmail = 'h.buyer@gmail.com';
$wixIn = ls_in('wix-gmail', 'info@wix-forms.com', "Namn: Köpare Exempel\nE-post: {$wixGmail}\nTelefon: 070-700 00 08\nAdress: Wixvägen 2, 177 80 Järfälla\n", [
    'type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'batteri', 'lead' => 'eget', 'product' => 'batteri', 'confidence' => 100, 'question' => '',
], 'lead-wix-gmail', ['Authentication-Results' => 'abc.protection.outlook.com; dmarc=pass']);
$wixRes = jeab_auto_on_message((string) $wixIn['id']);
$wixSent = $GLOBALS['_SENT'][$before] ?? [];
ls_ok(($wixRes['action'] ?? '') === 'quoted' && ($wixSent['to'] ?? '') === $wixGmail, 'Wix-formulär med gmail får fortfarande offert');
$noField = jeab_inkorg_finish_labels(['lead' => 'kopt', 'type' => 'Ny lead', 'confidence' => 90, 'source' => 'llm'], ['from' => 'desk@plain.seller.example', 'body' => "Hej, en lead utan kundfält.\n"]);
ls_ok(jeab_lead_seller_match('desk@plain.seller.example') === '', 'utan kundfält på annan domän lärs inget');
$GLOBALS['_JEAB_HS_IMPORTING'] = true;
jeab_inkorg_finish_labels(['lead' => 'kopt', 'type' => 'Ny lead', 'confidence' => 90, 'source' => 'llm'], [
    'from' => 'desk@import.seller.example',
    'source' => 'graph-jarfalla',
    'body' => "E-post: a.buyer@post.example\n",
]);
unset($GLOBALS['_JEAB_HS_IMPORTING']);
jeab_inkorg_finish_labels(['lead' => 'kopt', 'type' => 'Ny lead', 'confidence' => 90, 'source' => 'import'], [
    'from' => 'desk@hs.seller.example',
    'source' => 'helpscout_import',
    'body' => "E-post: a.buyer@post.example\n",
]);
ls_ok(jeab_lead_seller_match('desk@import.seller.example') === '' && jeab_lead_seller_match('desk@hs.seller.example') === '', 'import och omkörning lär inte in säljare');
$learned = jeab_inkorg_finish_labels(['lead' => 'kopt', 'type' => 'Ny lead', 'confidence' => 90, 'source' => 'llm'], [
    'from' => 'desk@new.seller.example',
    'body' => "Namn: Köpare Exempel\nE-post: f.buyer@post.example\n",
]);
ls_ok(($learned['lead'] ?? '') === 'kopt', 'klassningen behåller köpt lead');
$added = '';
foreach (jeab_lead_seller_rows() as $row) {
    if ($row['domain'] === 'new.seller.example') {
        $added = $row['added'];
    }
}
ls_ok($added === date('Y-m-d'), 'ny leadsäljare läggs till med datum');
$settingsPath = jeab_inkorg_dir() . '/settings.json';
$snap = (string) file_get_contents($settingsPath);
jeab_auto_settings_save(['auto_quote_enabled' => 1, 'auto_trash_spam' => 0, 'tpl' => jeab_auto_quote_seed()]);
ls_ok((string) file_get_contents($settingsPath) !== '' && jeab_lead_seller_match('desk@new.seller.example') === 'new.seller.example', 'sparad automatik tar inte bort listan');
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'unknown_product_ask_enabled' => 1,
    'auto_trash_spam' => 0,
    'tpl' => jeab_auto_quote_seed(),
]);
$before = count($GLOBALS['_SENT']);
$askIn = ls_in('ask-seller', $sellerPerson, "Hej, jag vill ha hjälp.\n", [
    'type' => 'Ny lead', 'intent' => '', 'offer' => '', 'lead' => 'kopt', 'product' => '', 'question' => '', 'confidence' => 100,
], 'lead-ask', $headers);
$askRes = jeab_auto_on_message((string) $askIn['id']);
ls_ok(count($GLOBALS['_SENT']) === $before && ($askRes['action'] ?? '') === 'hold' && str_contains((string) ($askRes['why'] ?? ''), 'kundadress') && ($askRes['to'] ?? '') !== $sellerPerson, 'köpt lead utan produkt skickar inte frågan till säljaren');
$before = count($GLOBALS['_SENT']);
$layer = jeab_outbound_graph_send('jarfalla', 'desk@weply.se', 'Fråga', 'Hej', false, [], ['info@weply.se'], [
    'guard_seller' => true,
    'seller_msg' => ['from' => 'a.buyer@post.example', 'labels' => ['lead' => 'eget']],
]);
ls_ok(empty($layer['ok']) && str_contains((string) ($layer['error'] ?? ''), 'leadsäljare') && count($GLOBALS['_SENT']) === $before, 'utskicksspärren nekar säljdomän innan Graph');

$auditIn = ls_in('audit-in', $sellerPerson, $leadBody, $labels, 'lead-audit', $headers);
jeab_inkorg_ingest([
    'message_id' => 'audit-out@lead.test',
    'from' => 'demo@example.test',
    'from_name' => 'Sam L',
    'to' => $sellerPerson,
    'cc' => $sellerGeneral,
    'subject' => 'Solceller med batteri',
    'body_text' => 'Offert',
    'source' => 'outbound-jarfalla',
    'thread_key' => 'lead-audit',
    'company' => 'jarfalla',
    'headers' => ['Cc' => $sellerGeneral],
    'preset_labels' => ['type' => 'Information', 'action' => 'arkivera', 'priority' => 4, 'confidence' => 100, 'source' => 'system', 'reason' => 'Skickat av flowstack.example.test (offert)'],
]);
$beforeAudit = (string) file_get_contents($settingsPath);
$audit = jeab_inkorg_hook(['action' => 'inbox.leadseller_sends_audit']);
ls_ok((string) file_get_contents($settingsPath) === $beforeAudit, 'granskningen skriver inte settings.json');
$hit = null;
foreach ((array) ($audit['rows'] ?? []) as $row) {
    if (($row['thread_id'] ?? '') === 'lead-audit') {
        $hit = $row;
    }
}
$encoded = json_encode($audit, JSON_UNESCAPED_UNICODE);
ls_ok(is_array($hit) && ($hit['recipient_domain'] ?? '') === 'seller.example' && ($hit['seller'] ?? '') === 'seller.example' && ($hit['to'] ?? []) === ['p***@seller.example'] && ($hit['cc'] ?? []) === ['i***@seller.example'] && ($hit['customer_email'] ?? '') === 'a***@post.example' && ($hit['customer_present'] ?? false) === true && ($hit['template'] ?? '') === 'Solceller med batteri', 'granskningen listar utskick till säljaren utan hela adressen');
ls_ok(!str_contains((string) $encoded, $customer) && !str_contains((string) $encoded, $sellerPerson) && !str_contains((string) $encoded, 'Köpare'), 'granskningen har varken hel adress eller namn');
$goodListed = false;
foreach ((array) ($audit['rows'] ?? []) as $row) {
    if (($row['thread_id'] ?? '') === 'lead-buy') {
        $goodListed = true;
    }
}
ls_ok($goodListed === false, 'utskick till kunden listas inte');

echo "OK  leadseller recipient\n";
