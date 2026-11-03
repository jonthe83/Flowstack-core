<?php

declare(strict_types=1);

/** Inkorgsgranskning 6–11. Påhittade kunder. */

$tmp = sys_get_temp_dir() . '/jeab-audit-followup-' . bin2hex(random_bytes(4));
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
$GLOBALS['_JEAB_LOG_PDO'] = new PDO('sqlite::memory:');
$GLOBALS['_JEAB_LOG_PDO']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
jeab_log_pdo();
jeab_log_flag_set($GLOBALS['_JEAB_LOG_PDO'], 'webshop_orders');

function af(bool $ok, string $m): void
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
    return ['ok' => true, 'from' => $from, 'graph_id' => 'g-af', 'conversation_id' => 'CV', 'message_id' => 'sent-af@x'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = function (string $method, string $url) {
    if (str_contains($url, 'mailFolders/sentitems?')) {
        return ['code' => 200, 'body' => ['id' => 'sent-folder']];
    }
    return ['code' => 200, 'body' => ['value' => []]];
};
$GLOBALS['_JEAB_REBOOK_LLM'] = fn() => json_encode(['answer' => 'Easee Charge Up kostar 8 490 kr efter grön teknik. Hör gärna av dig.']);
@mkdir(jeab_data_dir() . '/secrets', 0775, true);
file_put_contents(jeab_data_dir() . '/secrets/inkorg-llm.json', json_encode(['key' => 'test']));
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode('%PDF-1.4 batteri')]);
jeab_auto_pdf_save(['key' => 'laddbox_jarfalla', 'data' => base64_encode('%PDF-1.4 ladd')]);
jeab_auto_pdf_save(['key' => 'sol_jarfalla', 'data' => base64_encode('%PDF-1.4 sol')]);

function af_status(string $tid): string
{
    return (string) (jeab_inkorg_thread_meta($tid)['status'] ?? '');
}

function af_notes(string $tid): string
{
    $bits = [];
    foreach ((array) (jeab_auto_thread_by_id($tid)['drafts'] ?? []) as $d) {
        if (is_array($d) && ($d['kind'] ?? '') === 'note') {
            $bits[] = (string) ($d['body'] ?? '');
        }
    }
    return implode("\n", $bits);
}

function af_in(string $id, string $from, string $name, string $body, array $labels, string $thread = '', string $subject = ''): array
{
    $box = jeab_graph_mailboxes()['jarfalla'];
    $row = [
        'message_id' => $id . '@audit.test',
        'from' => $from,
        'from_name' => $name,
        'to' => $box,
        'subject' => $subject !== '' ? $subject : 'Meddelande från ' . $name,
        'body_text' => $body,
        'source' => 'graph-jarfalla',
        'graph_mailbox' => $box,
        'preset_labels' => $labels,
    ];
    if ($thread !== '') {
        $row['thread_key'] = $thread;
    }
    return jeab_inkorg_ingest($row);
}

function af_quote(string $tid): void
{
    jeab_inkorg_thread_write($tid, static function (array $t): array {
        $t['drafts'] = [[
            'id' => 'd-q',
            'kind' => 'note',
            'body' => 'Offert (Batteri) skickad till kunden.',
            'state' => 'note',
            'created_at' => date('c'),
        ]];
        return $t;
    });
}

$boxBody = static function (string $name, string $email, string $extra = ''): string {
    return "Namn: {$name}\nE-post: {$email}\nTelefon: 070-555 90 10\nAdress: Auditvägen 4, 177 40 Järfälla\n{$extra}";
};

$parkTid = (string) af_in('park-quote', 'leia.park@gmail.com', 'Leia Park', $boxBody('Leia Park', 'leia.park@gmail.com', "Hej, jag vill ha batteri.\n"), [
    'type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'batteri', 'product' => 'Batteri', 'confidence' => 95, 'question' => '',
], 'audit-park')['thread_id'];
af_quote($parkTid);
jeab_create_reminder(jeab_store(), 'Leia Park – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Leia Park', 'email' => 'leia.park@gmail.com', 'company' => 'jarfalla', 'who' => 'Roberto',
    'note' => 'mail: ' . jeab_lead_mail_url($parkTid),
]);
jeab_inkorg_thread_write($parkTid, static function (array $t): array {
    $t['status'] = 'waiting_customer';
    return $t;
});
$dryPark = jeab_inkorg_hook(['action' => 'inbox.close', 'thread_id' => $parkTid, 'dry' => 1]);
af(af_status($parkTid) === 'waiting_customer' && ($dryPark['reason'] ?? '') === 'offert och påminnelse', 'väntar på kund med offert och påminnelse stängs inte när dry=1');
$livePark = jeab_inkorg_hook(['action' => 'inbox.close', 'thread_id' => $parkTid, 'dry' => 0]);
af(af_status($parkTid) === 'closed' && ($livePark['reason'] ?? '') === 'offert och påminnelse', 'väntar på kund med offert och påminnelse stängs');

$supTid = (string) af_in('park-sup', 'noel.sup@gmail.com', 'Noel Sup', $boxBody('Noel Sup', 'noel.sup@gmail.com', "Hej, jag vill ha batteri.\n"), [
    'type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'batteri', 'product' => 'Batteri', 'confidence' => 95, 'question' => '',
], 'audit-sup')['thread_id'];
af_quote($supTid);
jeab_create_reminder(jeab_store(), 'Noel Sup – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Noel Sup', 'email' => 'noel.sup@gmail.com', 'company' => 'jarfalla', 'who' => 'Roberto',
    'note' => 'mail: ' . jeab_lead_mail_url($supTid),
]);
jeab_inkorg_thread_write($supTid, static function (array $t): array {
    $t['status'] = 'waiting_supplier';
    return $t;
});
$autoSup = jeab_inkorg_maybe_close_quoted($supTid);
af(af_status($supTid) === 'closed' && ($autoSup['reason'] ?? '') === 'offert och påminnelse', 'Parkerat väntar på leverantör stängs av samma regel');

$openLead = af_in('await-open', 'mira.batt@gmail.com', 'Mira Batt', $boxBody('Mira Batt', 'mira.batt@gmail.com', "Hej, jag vill ha batteri.\n"), [
    'type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'batteri', 'product' => 'Batteri', 'confidence' => 95, 'question' => '',
], 'audit-await');
$openThread = jeab_auto_thread_by_id((string) $openLead['thread_id']);
af(is_array($openThread) && jeab_inkorg_awaiting_quote($openThread), 'vanligt batterilead väntar på offert');

$support = jeab_auto_thread_by_id((string) af_in('await-sup', 'support.batt@gmail.com', 'Sune Batt', $boxBody('Sune Batt', 'support.batt@gmail.com', "Batteriet krånglar.\n"), [
    'type' => 'Support', 'intent' => 'support', 'offer' => 'batteri', 'product' => 'Batteri', 'confidence' => 95, 'question' => '',
], 'audit-type-support')['thread_id']);
$info = jeab_auto_thread_by_id((string) af_in('await-info', 'info.batt@gmail.com', 'Ida Batt', $boxBody('Ida Batt', 'info.batt@gmail.com', "Info om batteri.\n"), [
    'type' => 'Information', 'intent' => 'information', 'offer' => 'batteri', 'product' => 'Batteri', 'confidence' => 95, 'question' => '',
], 'audit-type-info')['thread_id']);
$junk = jeab_auto_thread_by_id((string) af_in('await-junk', 'junk.batt@gmail.com', 'Jens Batt', $boxBody('Jens Batt', 'junk.batt@gmail.com', "Reklam om batteri.\n"), [
    'type' => 'Skräp', 'intent' => 'skrap', 'offer' => 'batteri', 'product' => 'Batteri', 'confidence' => 95, 'question' => '',
], 'audit-type-junk')['thread_id']);
$exist = jeab_auto_thread_by_id((string) af_in('await-exist', 'exist.batt@gmail.com', 'Eva Batt', "Vad gäller det: Support till befintlig kund\nProdukt: batteri\nNamn: Eva Batt\nE-post: exist.batt@gmail.com\n", [
    'type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'batteri', 'product' => 'Batteri', 'confidence' => 95, 'question' => '',
], 'audit-exist')['thread_id']);
af(is_array($support) && !jeab_inkorg_awaiting_quote($support), 'Support ligger inte i Väntar på offert');
af(is_array($info) && !jeab_inkorg_awaiting_quote($info), 'Information ligger inte i Väntar på offert');
af(is_array($junk) && !jeab_inkorg_awaiting_quote($junk), 'Skräp ligger inte i Väntar på offert');
af(is_array($exist) && jeab_inkorg_form_is_existing_support((array) $exist['first']) && !jeab_inkorg_awaiting_quote($exist), 'Support till befintlig kund väntar inte på offert trots batteri');

$before = count($GLOBALS['_SENT']);
$fortnox = af_in('fortnox', 'noreply@mail.fortnox.se', 'Fortnox', "Autogiro\nBetalning mottagen\nKvitto\n", [
    'type' => 'Ny lead', 'intent' => 'kop', 'offer' => '', 'product' => '', 'confidence' => 90, 'question' => '',
], 'audit-fortnox', 'Kvitto');
$fortnoxRes = jeab_auto_on_message((string) $fortnox['id']);
$fortnoxMsg = jeab_inkorg_get((string) $fortnox['id']);
af(($fortnoxRes['action'] ?? '') === 'closed' && ($fortnoxRes['sent'] ?? 1) === 0 && ($fortnoxRes['why'] ?? '') === 'fortnox', 'Fortnox-kvitto stängs utan utskick');
af(af_status((string) $fortnox['thread_id']) === 'closed' && str_contains(af_notes((string) $fortnox['thread_id']), 'Fortnox-kvitto arkiverat.'), 'Fortnox-kvittot är arkiverat');
af(is_array($fortnoxMsg) && (($fortnoxMsg['labels']['type'] ?? '') === 'Information') && count($GLOBALS['_SENT']) === $before, 'Fortnox får typ Information och inget mejl går ut');

$mention = af_in('fortnox-kund', 'stina.fortnox@gmail.com', 'Stina Fortnox', "Hej, jag undrar om fortnox autogiro.\nNamn: Stina Fortnox\nE-post: stina.fortnox@gmail.com\n", [
    'type' => 'Information', 'intent' => 'information', 'offer' => '', 'product' => '', 'confidence' => 80, 'question' => '',
], 'audit-fortnox-kund');
$mentionRes = jeab_auto_on_message((string) $mention['id']);
af(($mentionRes['action'] ?? '') !== 'closed' && af_status((string) $mention['thread_id']) === 'open' && !str_contains(af_notes((string) $mention['thread_id']), 'Fortnox-kvitto'), 'en kund som nämner Fortnox arkiveras inte');

$paidEmail = 'vera.holm@gmail.com';
$paidLead = af_in('paid-lead', $paidEmail, 'Vera Holm', $boxBody('Vera Holm', $paidEmail, "Hej, jag vill ha batteri.\n"), [
    'type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'batteri', 'product' => 'Batteri', 'confidence' => 95, 'question' => '',
], 'audit-paid-lead');
$paidLeadTid = (string) $paidLead['thread_id'];
$paidSol = af_in('paid-sol', $paidEmail, 'Vera Holm', $boxBody('Vera Holm', $paidEmail, "Hej, jag vill ha solceller.\n"), [
    'type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'sol', 'product' => 'Solceller', 'confidence' => 95, 'question' => '',
], 'audit-paid-sol');
$paidRem = jeab_create_reminder(jeab_store(), 'Vera Holm – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Vera Holm', 'email' => $paidEmail, 'company' => 'jarfalla', 'who' => 'Roberto', 'note' => 'öppen lead',
]);
$solRem = jeab_create_reminder(jeab_store(), 'Vera Holm – Solceller', jeab_today_start(), null, null, [
    'customer' => 'Vera Holm', 'email' => $paidEmail, 'company' => 'jarfalla', 'who' => 'Sam L', 'note' => 'öppen sol',
]);
$orderRem = jeab_create_reminder(jeab_store(), 'Vera Holm – webshop', jeab_today_start(), null, null, [
    'customer' => 'Vera Holm', 'email' => $paidEmail, 'company' => 'jarfalla', 'who' => 'Roberto', 'note' => 'webshop',
]);
$orderId = (int) ($orderRem['id'] ?? 0);
jeab_purchase_put([
    'reminder_id' => $orderId,
    'kind' => 'batteri',
    'source' => 'webshop',
    'webshop' => ['number' => '10482', 'payment' => 'PAID', 'email' => $paidEmail, 'platform' => 'wix'],
]);
jeab_webshop_index_save(['jarfalla:10482' => ['reminder_id' => $orderId]]);
$before = count($GLOBALS['_SENT']);
$confirm = af_in('wix-paid', 'orders@wix.com', 'Wix', "Orderbekräftelse\nOrdernummer: 10482\nEasee\nNamn: Vera Holm\nE-post: {$paidEmail}\n", [
    'type' => 'Information', 'intent' => 'information', 'offer' => '', 'product' => '', 'confidence' => 90, 'question' => '',
], 'audit-wix-paid', 'Orderbekräftelse');
$confirmRes = jeab_auto_on_message((string) $confirm['id']);
$confirmMeta = jeab_inkorg_thread_meta((string) $confirm['thread_id']);
af(($confirmRes['sent'] ?? 1) === 0 && ($confirmRes['action'] ?? '') === 'closed' && af_status((string) $confirm['thread_id']) === 'closed', 'orderbekräftelse stängs utan utskick');
af((string) ($confirmMeta['order_number'] ?? '') === '10482' && (int) ($confirmMeta['order_reminder_id'] ?? 0) === $orderId && str_contains(af_notes((string) $confirm['thread_id']), 'Kopplad till order 10482 i Ordrar.'), 'bekräftelsen länkas till ordern i Ordrar');
af(count($GLOBALS['_SENT']) === $before, 'ingen order skickar mejl');
af(af_status($paidLeadTid) === 'closed' && str_contains(af_notes($paidLeadTid), 'Kunden har betalat order 10482.'), 'betald order stänger batterileadet och antecknar');
af(af_status((string) $paidSol['thread_id']) === 'closed', 'solpåminnelsen stänger även solleadet');
$paidRow = jeab_get_reminder(jeab_store(), (int) ($paidRem['id'] ?? 0));
$solRow = jeab_get_reminder(jeab_store(), (int) ($solRem['id'] ?? 0));
af(is_array($paidRow) && !empty($paidRow['completed_at']) && str_contains((string) ($paidRow['note'] ?? ''), 'Kunden har betalat order 10482.'), 'påminnelsen för batterileadet stängs med samma notering');
af(is_array($solRow) && empty($solRow['completed_at']), 'solpåminnelsen lämnas öppen');

$unpaidEmail = 'stina.order@gmail.com';
$unpaidLead = af_in('unpaid-lead', $unpaidEmail, 'Stina Order', $boxBody('Stina Order', $unpaidEmail, "Hej, jag vill ha batteri.\n"), [
    'type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'batteri', 'product' => 'Batteri', 'confidence' => 95, 'question' => '',
], 'audit-unpaid-lead');
$unpaidRem = jeab_create_reminder(jeab_store(), 'Stina Order – webshop', jeab_today_start(), null, null, [
    'customer' => 'Stina Order', 'email' => $unpaidEmail, 'company' => 'jarfalla', 'note' => 'obetald',
]);
jeab_purchase_put([
    'reminder_id' => (int) ($unpaidRem['id'] ?? 0),
    'kind' => 'batteri',
    'source' => 'webshop',
    'webshop' => ['number' => '10483', 'payment' => 'NOT_PAID', 'email' => $unpaidEmail],
]);
jeab_webshop_index_save(jeab_webshop_index() + ['jarfalla:10483' => ['reminder_id' => (int) ($unpaidRem['id'] ?? 0)]]);
$unpaid = af_in('wix-unpaid', 'orders@wix.com', 'Wix', "Orderbekräftelse\nOrdernummer: 10483\nNamn: Stina Order\nE-post: {$unpaidEmail}\n", [
    'type' => 'Information', 'intent' => 'information', 'offer' => '', 'confidence' => 90, 'question' => '',
], 'audit-wix-unpaid', 'Orderbekräftelse');
$unpaidRes = jeab_auto_on_message((string) $unpaid['id']);
af(($unpaidRes['action'] ?? '') === 'closed' && af_status((string) $unpaidLead['thread_id']) === 'closed' && !str_contains(af_notes((string) $unpaidLead['thread_id']), 'betalat'), 'obetald order antecknar inte betalning; orderpåminnelsen har redan stängt leadet');

$missing = af_in('wix-miss', 'orders@wix.com', 'Wix', "Orderbekräftelse\nOrdernummer: 88001\nNamn: Noa Miss\nE-post: noa.miss@gmail.com\n", [
    'type' => 'Information', 'intent' => 'information', 'offer' => '', 'confidence' => 90, 'question' => '',
], 'audit-wix-miss', 'Orderbekräftelse');
$before = count($GLOBALS['_SENT']);
$missRes = jeab_auto_on_message((string) $missing['id']);
af(($missRes['action'] ?? '') === 'hold' && af_status((string) $missing['thread_id']) === 'open' && str_contains(af_notes((string) $missing['thread_id']), 'saknar matchande order') && (jeab_inkorg_thread_meta((string) $missing['thread_id'])['order_number'] ?? '') === '' && count($GLOBALS['_SENT']) === $before, 'order utan träff hålls och länkas inte');

$store = af_in('store-plain', 'orders@stores-emails.com', 'Butik', "Ny beställning mottagen\nNamn: Kim Butik\nE-post: kim.butik@gmail.com\n", [
    'type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'laddbox', 'product' => 'Elbilsladdare', 'confidence' => 95, 'question' => '',
], 'audit-store', 'Ny beställning');
$before = count($GLOBALS['_SENT']);
$storeRes = jeab_auto_on_message((string) $store['id']);
af(($storeRes['action'] ?? '') === 'skip' && str_contains((string) ($storeRes['why'] ?? ''), 'butiksorder') && af_status((string) $store['thread_id']) !== 'closed' && count($GLOBALS['_SENT']) === $before, 'butiksorder utan ordernummer stängs inte');

$askSettings = jeab_inkorg_dir() . '/settings.json';
$askRaw = json_decode((string) file_get_contents($askSettings), true);
unset($askRaw['unknown_product_ask_enabled']);
file_put_contents($askSettings, json_encode($askRaw));
af(jeab_auto_settings()['unknown_product_ask'] === false, 'saknad unknown_product_ask_enabled är av');
af(substr_count((string) file_get_contents(dirname(__DIR__) . '/src/pages/inkorg_regler.php'), 'name="unknown_product_ask_enabled"') === 1, 'en kryssruta för produktfrågan');
$held = af_in('unknown-held', 'held.okand@gmail.com', 'Held Okänd', "Namn: Held Okänd\nE-post: held.okand@gmail.com\n", [
    'type' => 'Ny lead', 'intent' => '', 'offer' => '', 'product' => '', 'confidence' => 40, 'question' => '',
], 'audit-unknown-held');
$before = count($GLOBALS['_SENT']);
$heldRes = jeab_auto_on_message((string) $held['id']);
$heldRem = null;
foreach (jeab_reminders_every(jeab_store()) as $rem) {
    if (is_array($rem) && ($rem['email'] ?? '') === 'held.okand@gmail.com' && str_contains((string) ($rem['title'] ?? ''), 'okänd produkt')) {
        $heldRem = $rem;
    }
}
af(($heldRes['sent'] ?? 1) === 0 && ($heldRes['action'] ?? '') !== 'asked' && count($GLOBALS['_SENT']) === $before && af_status((string) $held['thread_id']) === 'waiting_customer' && str_contains(af_notes((string) $held['thread_id']), 'utskicket är av') && is_array($heldRem), 'avstängd produktfråga skickar inget men lämnar påminnelse och anteckning');
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'unknown_product_ask_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'tpl' => jeab_auto_quote_seed(),
]);
$expect = 'Hej Vera! Tack för att du hörde av dig. Gäller det solceller, batteri eller Elbilsladdare?';
$unknown = af_in('unknown', 'vera.okand@gmail.com', 'Vera Okänd', "Namn: Vera Okänd\nE-post: vera.okand@gmail.com\nTelefon: 070-555 80 01\nAdress: Okändvägen 1, 177 80 Järfälla\n", [
    'type' => 'Ny lead', 'intent' => '', 'offer' => '', 'product' => 'okänd produkt', 'confidence' => 40, 'question' => '',
], 'audit-unknown');
$before = count($GLOBALS['_SENT']);
$ask = jeab_auto_on_message((string) $unknown['id']);
$sent = $GLOBALS['_SENT'][$before] ?? [];
af(($ask['action'] ?? '') === 'asked' && ($ask['sent'] ?? 0) === 1 && ($sent['body'] ?? '') === $expect && ($sent['html'] ?? true) === false, 'okänd produkt får exakt texten i svarsreglerna');
af(($sent['opts']['person_email'] ?? '') === 'demo@example.test' && !str_contains((string) ($sent['body'] ?? ''), 'Ansvarig') && !str_contains((string) ($sent['body'] ?? ''), 'Texten nedan'), 'frågan går från ansvarig och utan dold text');
af(af_status((string) $unknown['thread_id']) === 'waiting_customer' && str_contains(af_notes((string) $unknown['thread_id']), 'Fråga om produkt skickad från demo@example.test'), 'ärendet parkeras och frågan antecknas');
$unknownRem = null;
foreach (jeab_reminders_every(jeab_store()) as $rem) {
    if (is_array($rem) && ($rem['email'] ?? '') === 'vera.okand@gmail.com' && str_contains((string) ($rem['title'] ?? ''), 'okänd produkt')) {
        $unknownRem = $rem;
    }
}
af(is_array($unknownRem) && (string) ($unknownRem['person'] ?? '') === 'SamL' && str_contains((string) ($unknownRem['note'] ?? ''), 'mail:'), 'påminnelse till ansvarig för okänd produkt');
$again = jeab_auto_on_message((string) $unknown['id']);
af(($again['action'] ?? '') === 'hold' && ($again['why'] ?? '') === 'frågan är redan skickad' && count($GLOBALS['_SENT']) === $before + 1, 'samma fråga skickas inte en gång till');

$before = count($GLOBALS['_SENT']);
$junkForm = af_in('junk-form', 'reklam.okand@gmail.com', 'Reklam Okänd', "Namn: Reklam Okänd\nE-post: reklam.okand@gmail.com\n", [
    'type' => 'Skräp', 'intent' => 'skrap', 'offer' => '', 'product' => '', 'confidence' => 90, 'question' => '',
], 'audit-junk-form');
$supportForm = af_in('support-form', 'support.okand@gmail.com', 'Support Okänd', "Namn: Support Okänd\nE-post: support.okand@gmail.com\n", [
    'type' => 'Support', 'intent' => 'support', 'offer' => '', 'product' => '', 'confidence' => 90, 'question' => '',
], 'audit-support-form');
$testForm = af_in('test-form', 'testkund@example.com', 'Testkund Okänd', "Namn: Testkund Okänd\nE-post: testkund@example.com\n", [
    'type' => 'Ny lead', 'intent' => '', 'offer' => '', 'product' => '', 'confidence' => 40, 'question' => '',
], 'audit-test-form');
$existForm = af_in('exist-form', 'exist.okand@gmail.com', 'Exist Okänd', "Support till befintlig kund\nNamn: Exist Okänd\nE-post: exist.okand@gmail.com\n", [
    'type' => 'Ny lead', 'intent' => '', 'offer' => '', 'product' => '', 'confidence' => 40, 'question' => '',
], 'audit-exist-form');
jeab_auto_on_message((string) $junkForm['id']);
jeab_auto_on_message((string) $supportForm['id']);
jeab_auto_on_message((string) $testForm['id']);
jeab_auto_on_message((string) $existForm['id']);
af(count($GLOBALS['_SENT']) === $before && !str_contains(af_notes((string) $junkForm['thread_id']) . af_notes((string) $supportForm['thread_id']) . af_notes((string) $testForm['thread_id']) . af_notes((string) $existForm['thread_id']), 'Fråga om produkt skickad'), 'skräp, support, test och befintlig kund får ingen produktfråga');

jeab_auto_settings_save(['auto_quote_enabled' => 0, 'auto_trash_spam' => 0, 'tpl' => jeab_auto_quote_seed()]);
$off = af_in('unknown-off', 'off.okand@gmail.com', 'Off Okänd', "Namn: Off Okänd\nE-post: off.okand@gmail.com\n", [
    'type' => 'Ny lead', 'intent' => '', 'offer' => '', 'product' => '', 'confidence' => 40, 'question' => '',
], 'audit-unknown-off');
$before = count($GLOBALS['_SENT']);
$offRes = jeab_auto_on_message((string) $off['id']);
af(($offRes['action'] ?? '') !== 'asked' && count($GLOBALS['_SENT']) === $before && af_status((string) $off['thread_id']) === 'closed', 'avstängt utskick skickar ingen produktfråga och påminnelsen stänger ärendet');
jeab_auto_settings_save(['auto_quote_enabled' => 1, 'unknown_product_ask_enabled' => 1, 'auto_trash_spam' => 0, 'tpl' => jeab_auto_quote_seed()]);

$GLOBALS['_JEAB_GRAPH_HTTP'] = fn() => ['code' => 500, 'body' => []];
$fail = af_in('unknown-fail', 'fail.okand@gmail.com', 'Fail Okänd', "Namn: Fail Okänd\nE-post: fail.okand@gmail.com\n", [
    'type' => 'Ny lead', 'intent' => '', 'offer' => '', 'product' => '', 'confidence' => 40, 'question' => '',
], 'audit-unknown-fail');
$before = count($GLOBALS['_SENT']);
$failRes = jeab_auto_on_message((string) $fail['id']);
af(($failRes['action'] ?? '') === 'hold' && ($failRes['sent'] ?? 1) === 0 && count($GLOBALS['_SENT']) === $before && af_status((string) $fail['thread_id']) === 'waiting_customer' && str_contains(af_notes((string) $fail['thread_id']), 'skickades inte'), 'oläsbar källa skickar ingen produktfråga');
$GLOBALS['_JEAB_GRAPH_HTTP'] = function (string $method, string $url) {
    if (str_contains($url, 'mailFolders/sentitems?')) {
        return ['code' => 200, 'body' => ['id' => 'sent-folder']];
    }
    return ['code' => 200, 'body' => ['value' => []]];
};

$priceLead = af_in('price', 'maja.pris@gmail.com', 'Maja Pris', "Vad kostar batteri med 38 kw?\nNamn: Maja Pris\nE-post: maja.pris@gmail.com\nTelefon: 070-303 00 03\nAdress: Prisvägen 3, 176 70 Järfälla\n", [
    'type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'batteri', 'product' => 'Batteri', 'confidence' => 95, 'question' => 'Vad kostar batteri med 38 kw?',
], 'audit-price');
$GLOBALS['_JEAB_REBOOK_LLM'] = fn() => json_encode(['answer' => '']);
$price = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $priceLead['thread_id'], 'dry' => 1]);
af(($price['action'] ?? '') === 'hold' && str_contains((string) ($price['why'] ?? ''), 'priset står inte i svarsreglerna'), 'pris som saknas i svarsreglerna hålls');

$banLead = af_in('ban', 'ban.holm@gmail.com', 'Ban Holm', "Ingår lastbalansering?\nNamn: Ban Holm\nE-post: ban.holm@gmail.com\nTelefon: 070-303 00 04\nAdress: Banvägen 4, 176 70 Järfälla\n", [
    'type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'laddbox', 'product' => 'Elbilsladdare', 'confidence' => 95, 'question' => 'Ingår lastbalansering?',
], 'audit-ban');
$GLOBALS['_JEAB_REBOOK_LLM'] = fn() => json_encode(['answer' => 'Jag återkommer med ett förslag.']);
$ban = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $banLead['thread_id'], 'dry' => 1]);
af(($ban['ok'] ?? false) === true && ($ban['action'] ?? '') !== 'hold' && str_contains((string) ($ban['body'] ?? ''), jeab_auto_answer_safe_line()) && !str_contains((string) ($ban['body'] ?? ''), 'återkommer'), 'förbjuden fras blir den säkra raden och hålls inte');

$brf = af_in('brf', 'brf.holm@gmail.com', 'Brf Holm', "Vi är en BRF och vill ha laddare.\nNamn: Brf Holm\nE-post: brf.holm@gmail.com\nTelefon: 070-303 00 05\nAdress: Brfvägen 5, 176 70 Järfälla\n", [
    'type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'laddbox', 'product' => 'Elbilsladdare', 'confidence' => 95, 'question' => '',
], 'audit-brf');
$before = count($GLOBALS['_SENT']);
$brfRes = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $brf['thread_id'], 'dry' => 1]);
af(($brfRes['action'] ?? '') === 'hold' && str_contains((string) ($brfRes['why'] ?? ''), 'ring') && count($GLOBALS['_SENT']) === $before, 'BRF hålls för att ringa');

$top = af_in('top', 'top.holm@gmail.com', 'Top Holm', "Ingår lastbalansering?\nNamn: Top Holm\nE-post: top.holm@gmail.com\nTelefon: 070-303 00 06\nAdress: Topvägen 6, 176 70 Järfälla\n", [
    'type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'laddbox', 'product' => 'Elbilsladdare', 'confidence' => 95, 'question' => 'Ingår lastbalansering?',
], 'audit-top');
$long = str_repeat('Hör gärna av dig. ', 40);
$GLOBALS['_JEAB_REBOOK_LLM'] = fn() => json_encode(['answer' => $long]);
$topRes = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $top['thread_id'], 'dry' => 0]);
$topBody = (string) (($GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1]['body'] ?? ''));
$topNotes = af_notes((string) $top['thread_id']);
$clip = rtrim(mb_substr($long, 0, 500));
$clipAt = strpos($topBody, $clip);
$tplAt = strpos($topBody, 'Easee Charge Up');
af(($topRes['action'] ?? '') === 'quoted' && mb_strlen($clip) <= 500 && $clipAt !== false && $tplAt !== false && $clipAt < $tplAt && !str_contains($topBody, $long), 'kort svar kapas vid 500 tecken och ligger överst');
af(str_contains($topNotes, 'Kort svar: ' . $clip), 'kort svar antecknas på ärendet');

$keep = af_in('keep', 'keep.holm@gmail.com', 'Keep Holm', $boxBody('Keep Holm', 'keep.holm@gmail.com', "Hej.\n"), [
    'type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'batteri', 'product' => 'Batteri', 'confidence' => 95, 'question' => '',
], 'audit-keep');
$autoA = af_in('auto-a', 'auto.a@gmail.com', 'Auto A', "Namn: Auto A\nE-post: auto.a@gmail.com\n", [
    'type' => 'Ny lead', 'intent' => '', 'offer' => '', 'product' => '', 'confidence' => 40, 'question' => '',
], 'autotest-a');
$autoB = af_in('auto-b', 'auto.b@gmail.com', 'Auto B', "Namn: Auto B\nE-post: auto.b@gmail.com\n", [
    'type' => 'Ny lead', 'intent' => '', 'offer' => '', 'product' => '', 'confidence' => 40, 'question' => '',
], 'autotest-b');
$dryDel = jeab_inkorg_hook(['action' => 'inbox.delete_test', 'dry' => 1]);
$dryIds = (array) ($dryDel['threads'] ?? []);
af(($dryDel['ok'] ?? false) === true && ($dryDel['dry'] ?? 0) === 1 && ($dryDel['deleted'] ?? 1) === 0 && in_array('autotest-a', $dryIds, true) && in_array('autotest-b', $dryIds, true) && jeab_inkorg_get((string) $autoA['id']) !== null, 'dry=1 listar autotest och raderar inget');
$refuse = jeab_inkorg_hook(['action' => 'inbox.delete_test', 'thread_id' => (string) $keep['thread_id'], 'dry' => 0]);
af(($refuse['ok'] ?? true) === false && str_contains((string) ($refuse['error'] ?? ''), 'autotest') && jeab_inkorg_get((string) $keep['id']) !== null, 'delete_test rör inte vanliga ärenden');
$liveDel = jeab_inkorg_hook(['action' => 'inbox.delete_test', 'dry' => 0]);
af(($liveDel['ok'] ?? false) === true && ($liveDel['dry'] ?? 1) === 0 && (int) ($liveDel['deleted'] ?? 0) >= 2 && jeab_inkorg_get((string) $autoA['id']) === null && jeab_inkorg_get((string) $autoB['id']) === null && jeab_auto_thread_by_id('autotest-a') === null, 'dry=0 tar bort autotest-ärenden');
af(jeab_inkorg_get((string) $keep['id']) !== null && jeab_auto_thread_by_id((string) $keep['thread_id']) !== null, 'övriga ärenden ligger kvar');

echo "OK  audit followup\n";
