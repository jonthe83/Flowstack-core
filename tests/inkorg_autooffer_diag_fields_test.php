<?php

declare(strict_types=1);

/** Diagnosens säljare och mall, ett offertmål per påminnelse, och påminnelsens produkt. */

$tmp = sys_get_temp_dir() . '/jeab-diag-fields-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function df(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_SENT'][] = $a;
    $person = trim((string) ($a['opts']['person_email'] ?? ''));
    $from = $person !== '' ? $person : 'kontakt@jarfalla.example.test';

    return ['ok' => true, 'from' => $from, 'graph_id' => 'g-df', 'conversation_id' => 'CV', 'message_id' => 'sent-df-' . count($GLOBALS['_SENT']) . '@x'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static fn (): array => ['code' => 200, 'body' => ['value' => []]];
@mkdir(jeab_data_dir() . '/secrets', 0775, true);
file_put_contents(jeab_data_dir() . '/secrets/inkorg-llm.json', json_encode(['key' => 'test']));
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'ink_diag_fields']));

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'follow_receipt_enabled' => 0,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode('%PDF-1.4 batteri')]);
jeab_auto_pdf_save(['key' => 'laddbox_jarfalla', 'data' => base64_encode('%PDF-1.4 laddbox')]);
jeab_auto_pdf_save(['key' => 'sol_jarfalla', 'data' => base64_encode('%PDF-1.4 sol')]);
require __DIR__ . '/quote_notiser_seed.php';
jeab_test_seed_quote_notiser();
$jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
jeab_auto_pdf_save(['key' => 'team_jarfalla', 'data' => base64_encode(is_string($jpeg) ? $jpeg : '')]);

$box = jeab_graph_mailboxes()['jarfalla'];
$leadBody = static function (string $name, string $email, string $phone, string $need): string {
    return "Namn: {$name}\nE-post: {$email}\nTelefon: {$phone}\nAdress: Batterivägen 1\nPostnummer: 177 31\nOrt: Järfälla\nBehov: {$need}\n";
};
$ingest = static function (string $id, string $body, array $labels, string $from, string $subject) use ($box): array {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@diag-fields.test',
        'from' => $from,
        'from_name' => 'Lead',
        'to' => $box,
        'subject' => $subject,
        'date' => date('c'),
        'received_at' => date('c'),
        'body_text' => $body,
        'source' => 'graph-jarfalla',
        'graph_mailbox' => $box,
        'thread_key' => 'df-' . $id,
        'headers' => ['Authentication-Results' => 'mx.example.test; dmarc=pass'],
        'preset_labels' => $labels,
    ]);
};
$labels = static function (string $type, string $offer, string $product, string $intent = 'kop'): array {
    return [
        'type' => $type, 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
        'source' => 'llm', 'intent' => $intent, 'lead' => '', 'needs_review' => false,
        'offer' => $offer, 'product' => $product, 'question' => '',
    ];
};
$rowFor = static function (array $diag, string $tid): array {
    foreach ((array) ($diag['threads'] ?? []) as $row) {
        if (is_array($row) && (string) ($row['id'] ?? '') === $tid) {
            return $row;
        }
    }

    return [];
};

df(jeab_inkorg_reminder_offer(['title' => 'Ada – Sol+batteri']) === 'sol+batteri', 'Sol+batteri är sol+batteri');
df(jeab_inkorg_reminder_offer(['title' => 'Ada – Solceller med batteri']) === 'sol', 'Solceller med batteri är sol');
df(jeab_inkorg_reminder_offer(['title' => 'Ada – Batteri']) === 'batteri', 'Batteri är batteri');
df(jeab_inkorg_reminder_offer(['product' => 'sol+batteri', 'title' => 'Ada – Solceller']) === 'sol+batteri', 'påminnelsens produktfält vinner');

$bat = $ingest(
    'bat',
    $leadBody('Bo Batteri', 'bo.batteri.df@gmail.com', '070-801 00 01', 'Endast batteri'),
    $labels('Ny lead', 'sol', 'batteri'),
    'leads@hemsol.se',
    'Nytt lead (HemSol) solceller'
);
$batTid = (string) $bat['thread_id'];
$batRem = jeab_create_reminder(jeab_store(), 'Bo Batteri – Solceller', jeab_today_start(), null, null, [
    'customer' => 'Bo Batteri',
    'email' => 'bo.batteri.df@gmail.com',
    'phone' => '0708010001',
    'company' => 'jarfalla',
    'who' => 'Sam L',
    'thread_id' => $batTid,
]);
$expectSeller = jeab_gp_owner_email('batteri', 'jarfalla');
$expectTpl = jeab_notiser_quote_notice_id('batteri', 'jarfalla', '', false);
$diagBat = jeab_inkorg_hook(['action' => 'inbox.autooffer_diag', 'threads' => [$batTid, 'df-missing']]);
$batRow = $rowFor($diagBat, $batTid);
df((string) ($batRow['reason'] ?? '') !== 'offer_mismatch', 'påminnelse med product_offer batteri är inte offer_mismatch, fick ' . (string) ($batRow['reason'] ?? ''));
df((string) ($batRow['offer'] ?? '') === 'batteri' && (string) ($batRow['product_offer'] ?? '') === 'batteri', 'product_offer vinner över offer sol, fick ' . (string) ($batRow['offer'] ?? ''));
df((string) ($batRow['seller'] ?? '') === $expectSeller && $expectSeller === 'saljare3@jarfalla.example.test', 'säljaren är Robertos brevlåda, fick ' . (string) ($batRow['seller'] ?? ''));
df((string) ($batRow['template'] ?? '') === $expectTpl && $expectTpl === 'offert-batteri-jarfalla', 'mallen är offert-batteri-jarfalla, fick ' . (string) ($batRow['template'] ?? ''));
df((string) ($rowFor($diagBat, 'df-missing')['seller'] ?? 'x') === '' && (string) ($rowFor($diagBat, 'df-missing')['template'] ?? 'x') === '', 'saknad tråd har tom säljare och mall');
$beforeBat = count($GLOBALS['_SENT']);
$batAuto = jeab_auto_on_message((string) $bat['id']);
$batSent = (array) ($GLOBALS['_SENT'][$beforeBat] ?? []);
df(($batAuto['action'] ?? '') === 'quoted' && (string) ($batSent['opts']['person_email'] ?? '') === 'saljare3@jarfalla.example.test', 'utskicket går från Roberto, fick ' . (string) ($batAuto['action'] ?? '') . ' ' . (string) ($batAuto['why'] ?? ''));
df(str_contains((string) ($batSent['subject'] ?? ''), 'Offert batteri'), 'ämnet är batterioffert');

$combo = $ingest(
    'combo',
    $leadBody('Cora Combo', 'cora.combo.df@gmail.com', '070-801 00 02', 'Sol och batteri'),
    $labels('Ny lead', 'sol', ''),
    'leads@hemsol.se',
    'Nytt lead (HemSol) solceller'
);
$comboTid = (string) $combo['thread_id'];
jeab_create_reminder(jeab_store(), 'Cora Combo – Sol+batteri', jeab_today_start(), null, null, [
    'customer' => 'Cora Combo',
    'email' => 'cora.combo.df@gmail.com',
    'phone' => '0708010002',
    'company' => 'jarfalla',
    'who' => 'Sam L',
    'thread_id' => $comboTid,
]);
$comboSeller = jeab_gp_owner_email('sol', 'jarfalla');
$diagCombo = jeab_inkorg_hook(['action' => 'inbox.autooffer_diag', 'thread' => $comboTid]);
$comboRow = (array) (($diagCombo['threads'][0] ?? []));
df((string) ($comboRow['offer'] ?? '') === 'sol+batteri', 'påminnelsens Sol+batteri vinner över offer sol, fick ' . (string) ($comboRow['offer'] ?? ''));
df((string) ($comboRow['seller'] ?? '') === $comboSeller && $comboSeller === 'demo@example.test', 'sol+batteri går från Sam L, fick ' . (string) ($comboRow['seller'] ?? ''));
df(str_starts_with((string) ($comboRow['template'] ?? ''), 'offert-sol-jarfalla'), 'mallen är solmallen, fick ' . (string) ($comboRow['template'] ?? ''));
$beforeCombo = count($GLOBALS['_SENT']);
$comboAuto = jeab_auto_on_message((string) $combo['id']);
$comboSent = (array) ($GLOBALS['_SENT'][$beforeCombo] ?? []);
df(($comboAuto['action'] ?? '') === 'quoted' && (string) ($comboSent['opts']['person_email'] ?? '') === $comboSeller, 'utskicket av sol+batteri använder samma brevlåda, fick ' . (string) ($comboAuto['action'] ?? '') . ' ' . (string) ($comboAuto['why'] ?? ''));

$other = $ingest(
    'ef',
    $leadBody('Eva Annan', 'eva.annan.df@gmail.com', '070-801 00 03', 'Hej'),
    $labels('Information', '', '', 'info'),
    'eva.annan.df@gmail.com',
    'Fråga'
);
$lead = $ingest(
    'g',
    $leadBody('Greta Lead', 'greta.lead.df@gmail.com', '070-801 00 04', 'Solceller') . "\nKälla: Solceller.nu\n",
    $labels('Information', 'sol', 'sol', 'info'),
    'greta.lead.df@gmail.com',
    'Förfrågan'
);
$otherTid = (string) $other['thread_id'];
$leadTid = (string) $lead['thread_id'];
df(jeab_lead_is_lead(jeab_auto_thread_by_id($leadTid) ?? [], (array) (jeab_inkorg_get((string) $lead['id']) ?? [])) === true, 'Solceller.nu med offer sol är ett lead');
df((string) ((jeab_inkorg_get((string) $lead['id'])['labels']['type'] ?? '')) !== 'Ny lead', 'typen är inte Ny lead');
$pair = jeab_create_reminder(jeab_store(), 'Eva Annan – Solceller', jeab_today_start(), null, null, [
    'customer' => 'Eva Annan',
    'email' => 'eva.annan.df@gmail.com',
    'phone' => '0708010003',
    'company' => 'jarfalla',
    'who' => 'Sam L',
    'thread_id' => $otherTid,
]);
jeab_lead_reminder_append($pair, $leadTid, '');
$diagPair = jeab_inkorg_hook(['action' => 'inbox.autooffer_diag', 'threads' => [$otherTid, $leadTid]]);
$otherRow = $rowFor($diagPair, $otherTid);
$leadRow = $rowFor($diagPair, $leadTid);
df((string) ($leadRow['reason'] ?? '') !== 'not_lead' && (string) ($leadRow['reason'] ?? '') !== 'duplicate', 'leadtråden är offertmålet, fick ' . (string) ($leadRow['reason'] ?? ''));
df((string) ($otherRow['reason'] ?? '') === 'duplicate', 'den andra tråden på samma påminnelse är duplicate, fick ' . (string) ($otherRow['reason'] ?? ''));
df((string) ($otherRow['duplicate_thread'] ?? '') === $leadTid && str_contains((string) ($otherRow['duplicate_why'] ?? ''), $leadTid), 'dubbletten pekar på leadtråden, fick ' . (string) ($otherRow['duplicate_why'] ?? ''));
$beforePair = count($GLOBALS['_SENT']);
$otherAuto = jeab_auto_on_message((string) $other['id']);
$leadAuto = jeab_auto_on_message((string) $lead['id']);
df(($otherAuto['action'] ?? '') !== 'quoted' && ($leadAuto['action'] ?? '') === 'quoted' && count($GLOBALS['_SENT']) === $beforePair + 1, 'bara leadtråden får offerten, fick ' . (string) ($otherAuto['action'] ?? '') . '/' . (string) ($leadAuto['action'] ?? '') . ' ' . (string) ($leadAuto['why'] ?? ''));

$boxA = $ingest(
    'box-a',
    $leadBody('Lina Ladd', 'lina.ladd.df@gmail.com', '070-801 00 05', 'Laddbox'),
    $labels('Ny lead', 'laddbox', 'laddbox'),
    'leads@hemsol.se',
    'Nytt lead laddbox'
);
$boxB = $ingest(
    'box-b',
    $leadBody('Lina Ladd', 'lina.ladd.df@gmail.com', '070-801 00 05', 'Laddbox igen'),
    $labels('Ny lead', 'laddbox', 'laddbox'),
    'leads@hemsol.se',
    'Nytt lead laddbox'
);
$boxATid = (string) $boxA['thread_id'];
$boxBTid = (string) $boxB['thread_id'];
$boxRem = jeab_create_reminder(jeab_store(), 'Lina Ladd – Elbilsladdare', jeab_today_start(), null, null, [
    'customer' => 'Lina Ladd',
    'email' => 'lina.ladd.df@gmail.com',
    'phone' => '0708010005',
    'company' => 'jarfalla',
    'who' => 'Elin',
    'thread_id' => $boxATid,
]);
jeab_lead_reminder_append($boxRem, $boxBTid, '');
jeab_inbox_sent_record($boxATid, 'auto', 'system', 'offert', [
    'outcome' => 'sent',
    'channel' => 'email',
    'idem' => hash('sha256', 'df-laddbox-' . $boxATid),
]);
$sentLogId = 0;
foreach (jeab_inbox_sent_load() as $sentRow) {
    if ((string) ($sentRow['thread_id'] ?? '') === $boxATid && (string) ($sentRow['kind'] ?? '') === 'offert') {
        $sentLogId = (int) ($sentRow['id'] ?? 0);
    }
}
df($sentLogId > 0, 'sent_log har en laddboxrad');
$diagBox = jeab_inkorg_hook(['action' => 'inbox.autooffer_diag', 'threads' => [$boxATid, $boxBTid]]);
$boxBRow = $rowFor($diagBox, $boxBTid);
df((string) ($boxBRow['reason'] ?? '') === 'duplicate', 'laddbox-dubbletten syns, fick ' . (string) ($boxBRow['reason'] ?? ''));
df((int) ($boxBRow['sent_log_id'] ?? 0) === $sentLogId && (string) ($boxBRow['duplicate_thread'] ?? '') === $boxATid, 'orsaken är sent_log ' . $sentLogId . ' och tråden, fick ' . (string) ($boxBRow['duplicate_why'] ?? ''));
df(str_contains((string) ($boxBRow['duplicate_why'] ?? ''), 'sent_log ' . $sentLogId) && str_contains((string) ($boxBRow['duplicate_why'] ?? ''), $boxATid), 'duplicate_why nämner rad och tråd');
$beforeBox = count($GLOBALS['_SENT']);
$boxAuto = jeab_auto_on_message((string) $boxB['id']);
df(($boxAuto['action'] ?? '') === 'hold' && str_contains((string) ($boxAuto['why'] ?? ''), 'skickande av offert har stoppats pga. att offert på samma produkt skickats ut datum') && count($GLOBALS['_SENT']) === $beforeBox, 'laddbox-dubbletten skickar inget, fick ' . (string) ($boxAuto['action'] ?? '') . ' ' . (string) ($boxAuto['why'] ?? ''));

echo "inkorg autooffer diag fields: ok\n";
