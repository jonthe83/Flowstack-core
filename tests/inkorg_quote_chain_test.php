<?php

declare(strict_types=1);

/** Första offerten när påminnelsen saknar sent_log. Uppföljning bara efter offert. En påminnelse, en offert. */

$tmp = sys_get_temp_dir() . '/jeab-quote-chain-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function qc(bool $ok, string $m): void
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

    return ['ok' => true, 'from' => $from, 'graph_id' => 'g-qc', 'conversation_id' => 'CV', 'message_id' => 'sent-qc-' . count($GLOBALS['_SENT']) . '@x'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static fn (): array => ['code' => 200, 'body' => ['value' => []]];
@mkdir(jeab_data_dir() . '/secrets', 0775, true);
file_put_contents(jeab_data_dir() . '/secrets/inkorg-llm.json', json_encode(['key' => 'test']));
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'ink_quote_chain']));

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
jeab_notiser_save_body('offert-batteri-jarfalla', "Hej\n\n" . jeab_offer_batteri_neo_price_paragraph(), 'test');
jeab_notiser_save_body('offert-sol-jarfalla-eget', "Hej\n\nSol eget.", 'test');
jeab_notiser_save_body('offert-sol-jarfalla-kopt', "Hej\n\nSol köpt.", 'test');
jeab_notiser_save_body('offert-batteri-malmo', "Hej\n\nMalmö batteri.", 'test');
jeab_auto_pdf_save(['key' => 'sol_jarfalla', 'data' => base64_encode('%PDF-1.4 sol')]);
$jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
jeab_auto_pdf_save(['key' => 'team_jarfalla', 'data' => base64_encode(is_string($jpeg) ? $jpeg : '')]);

$box = jeab_graph_mailboxes()['jarfalla'];
$leadBody = static function (string $name, string $email, string $phone, string $need): string {
    return "Namn: {$name}\nE-post: {$email}\nTelefon: {$phone}\nAdress: Batterivägen 1\nPostnummer: 177 31\nOrt: Järfälla\nBehov: {$need}\n";
};
$ingest = static function (string $id, string $body, array $labels, string $from, string $subject) use ($box): array {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@quote-chain.test',
        'from' => $from,
        'from_name' => 'Lead',
        'to' => $box,
        'subject' => $subject,
        'date' => date('c'),
        'received_at' => date('c'),
        'body_text' => $body,
        'source' => 'graph-jarfalla',
        'graph_mailbox' => $box,
        'thread_key' => 'qc-' . $id,
        'headers' => ['Authentication-Results' => 'mx.example.test; dmarc=pass'],
        'preset_labels' => $labels,
    ]);
};
$bought = static function (string $offer, string $product, string $lead): array {
    return [
        'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
        'source' => 'llm', 'intent' => 'kop', 'lead' => $lead, 'needs_review' => false,
        'offer' => $offer, 'product' => $product, 'question' => '',
    ];
};

$open = $ingest(
    'mira',
    $leadBody('Mira Batteri', 'mira.batteri.qc@gmail.com', '070-701 00 01', 'Endast batteri'),
    $bought('batteri', 'batteri', 'kopt'),
    'leads@hemsol.se',
    'Nytt lead (HemSol) batteri'
);
$tid = (string) $open['thread_id'];
jeab_inkorg_thread_write($tid, static function (array $t): array {
    unset($t['reminder_id']);

    return $t;
});
$rem = jeab_create_reminder(jeab_store(), 'Mira Batteri – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Mira Batteri',
    'email' => 'mira.batteri.qc@gmail.com',
    'phone' => '0707010001',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'thread_id' => $tid,
    'note' => '',
]);
$rid = (int) ($rem['id'] ?? 0);
qc($rid > 0, 'påminnelse skapad');
$meta = jeab_inkorg_thread_meta($tid);
qc((int) ($meta['reminder_id'] ?? 0) === 0, 'ärendets reminder_id är tomt innan diagnosen');

$leads = jeab_inkorg_hook(['action' => 'inbox.leads', 'thread' => $tid]);
$leadRid = (int) (($leads['leads'][0]['reminder'] ?? 0));
$sentBeforeDiag = count($GLOBALS['_SENT']);
$diag = jeab_inkorg_hook(['action' => 'inbox.autooffer_diag', 'threads' => [$tid, 'qc-missing']]);
qc(($diag['sent'] ?? 1) === 0 && ($diag['would_send'] ?? true) === false && count($GLOBALS['_SENT']) === $sentBeforeDiag, 'diagnosen skickar inget');
qc(count((array) ($diag['threads'] ?? [])) === 2, 'thread-listan kommer med båda');
$diagRow = (array) (($diag['threads'][0] ?? []));
qc((int) ($diagRow['reminder_id'] ?? 0) === $rid && $leadRid === $rid, 'diagnosens reminder_id är samma som inbox.leads, fick ' . (int) ($diagRow['reminder_id'] ?? 0));
qc((string) (($diag['threads'][1]['reason'] ?? '')) === 'missing', 'okänd tråd i listan syns');

$before = count($GLOBALS['_SENT']);
$auto = jeab_auto_on_message((string) $open['id']);
qc(($auto['action'] ?? '') === 'quoted' && count($GLOBALS['_SENT']) === $before + 1, 'påminnelse utan offert ger en offert, fick ' . (string) ($auto['action'] ?? '') . ' ' . (string) ($auto['why'] ?? ''));
$sent = (array) ($GLOBALS['_SENT'][$before] ?? []);
qc((string) ($sent['opts']['person_email'] ?? '') === 'saljare3@jarfalla.example.test', 'köpt batteri går från Roberto');
qc(str_contains((string) ($sent['subject'] ?? ''), 'Offert batteri'), 'mallen är batterioffert');
$logged = false;
foreach (jeab_inbox_sent_load() as $row) {
    if ((string) ($row['thread_id'] ?? '') === $tid && (string) ($row['kind'] ?? '') === 'offert') {
        $logged = true;
    }
}
qc($logged, 'sent_log har en offert-rad');

$sentLead = $ingest(
    'sent-already',
    $leadBody('Sent Redan', 'sent.redan.qc@gmail.com', '070-701 00 08', 'Endast batteri'),
    $bought('batteri', 'batteri', 'kopt'),
    'leads@hemsol.se',
    'Nytt lead (HemSol) batteri'
);
$sentTid = (string) $sentLead['thread_id'];
jeab_create_reminder(jeab_store(), 'Sent Redan – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Sent Redan',
    'email' => 'sent.redan.qc@gmail.com',
    'phone' => '0707010008',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'thread_id' => $sentTid,
]);
jeab_inbox_sent_record($sentTid, 'auto', 'system', 'offert', [
    'outcome' => 'sent',
    'channel' => 'email',
    'idem' => hash('sha256', 'qc-sent-' . $sentTid),
]);
$beforeFollow = count($GLOBALS['_SENT']);
$follow = jeab_auto_on_message((string) $sentLead['id']);
qc(($follow['action'] ?? '') === 'follow' && count($GLOBALS['_SENT']) === $beforeFollow, 'påminnelse med offert i sent_log tar uppföljning, fick ' . (string) ($follow['action'] ?? '') . ' ' . (string) ($follow['why'] ?? ''));

$own = $ingest(
    'nora',
    $leadBody('Nora Sol', 'nora.sol.qc@gmail.com', '070-701 00 02', 'Solceller med batteri'),
    $bought('sol', 'solceller', 'eget'),
    'info@wix-forms.com',
    'Nytt formulär sol'
);
$ownTid = (string) $own['thread_id'];
jeab_create_reminder(jeab_store(), 'Nora Sol – Solceller med batteri', jeab_today_start(), null, null, [
    'customer' => 'Nora Sol',
    'email' => 'nora.sol.qc@gmail.com',
    'phone' => '0707010002',
    'company' => 'jarfalla',
    'who' => 'Sam L',
    'thread_id' => $ownTid,
]);
$beforeOwn = count($GLOBALS['_SENT']);
$ownAuto = jeab_auto_on_message((string) $own['id']);
$ownSent = (array) ($GLOBALS['_SENT'][$beforeOwn] ?? []);
qc(($ownAuto['action'] ?? '') === 'quoted' && count($GLOBALS['_SENT']) === $beforeOwn + 1, 'eget sol-lead med påminnelse får offert, fick ' . (string) ($ownAuto['action'] ?? '') . ' ' . (string) ($ownAuto['why'] ?? ''));
qc((string) ($ownSent['opts']['person_email'] ?? '') === 'demo@example.test', 'eget sol går från Sam L');
qc(str_contains(mb_strtolower((string) ($ownSent['subject'] ?? '')), 'solceller'), 'eget sol använder solmallen');

$a = $ingest(
    'duo-a',
    $leadBody('Duo Kund', 'duo.kund.qc@gmail.com', '070-701 00 03', 'Endast batteri'),
    $bought('batteri', 'batteri', 'kopt'),
    'leads@hemsol.se',
    'Nytt lead (HemSol) batteri'
);
$b = $ingest(
    'duo-b',
    $leadBody('Duo Kund', 'duo.kund.qc@gmail.com', '070-701 00 03', 'Endast batteri'),
    $bought('batteri', 'batteri', 'kopt'),
    'leads@hemsol.se',
    'Nytt lead (HemSol) batteri'
);
$aTid = (string) $a['thread_id'];
$bTid = (string) $b['thread_id'];
$duo = jeab_create_reminder(jeab_store(), 'Duo Kund – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Duo Kund',
    'email' => 'duo.kund.qc@gmail.com',
    'phone' => '0707010003',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'thread_id' => $aTid,
]);
$duoId = (int) ($duo['id'] ?? 0);
jeab_lead_reminder_append($duo, $bTid, '');
$dry = jeab_inkorg_hook(['action' => 'inbox.resend_missed', 'ids' => [$aTid, $bTid]]);
$dryActions = array_map(static fn ($row): string => (string) (is_array($row) ? ($row['action'] ?? '') : ''), (array) ($dry['leads'] ?? []));
qc(in_array('would', $dryActions, true) && in_array('blocked_duplicate', $dryActions, true) && count($GLOBALS['_SENT']) === $beforeOwn + 1, 'torrkörning: en skulle skickas och den andra är blocked_duplicate, fick ' . implode(',', $dryActions));
qc((int) ($dry['sent'] ?? 1) === 0, 'torrkörning skickar inget');

$beforeDuo = count($GLOBALS['_SENT']);
$aAuto = jeab_auto_on_message((string) $a['id']);
$bAuto = jeab_auto_on_message((string) $b['id']);
qc(($aAuto['action'] ?? '') === 'quoted' && ($bAuto['action'] ?? '') === 'hold' && str_contains((string) ($bAuto['why'] ?? ''), 'skickande av offert har stoppats pga. att offert på samma produkt skickats ut datum'), 'två trådar: första offerten och samma produkt inom 30 dagar, fick ' . (string) ($aAuto['action'] ?? '') . '/' . (string) ($bAuto['action'] ?? '') . ' ' . (string) ($bAuto['why'] ?? ''));
qc(count($GLOBALS['_SENT']) === $beforeDuo + 1, 'två trådar för en påminnelse ger exakt en offert');
$dryAfter = jeab_inkorg_hook(['action' => 'inbox.resend_missed', 'ids' => [$bTid]]);
qc((string) (($dryAfter['leads'][0]['action'] ?? '')) === 'blocked_duplicate', 'torrkörning efter offert är blocked_duplicate');
$liveBlocked = jeab_inkorg_hook(['action' => 'inbox.resend_missed', 'dry' => 0, 'confirm' => 'SAM', 'ids' => [$bTid]]);
qc((string) (($liveBlocked['leads'][0]['action'] ?? '')) === 'blocked_duplicate' && count($GLOBALS['_SENT']) === $beforeDuo + 1, 'verklig körning skickar inget för dubbletten');
qc($duoId > 0, 'påminnelsen finns kvar');

$phone = $ingest(
    'pia',
    "Namn: Pia Telefon\nTelefon: 070-701 00 09\nAdress: Telefonvägen 2\nPostnummer: 177 31\nOrt: Järfälla\nBehov: Endast batteri\n",
    $bought('batteri', 'batteri', 'kopt'),
    'leads@hemsol.se',
    'Nytt lead (HemSol) batteri'
);
$beforePhone = count($GLOBALS['_SENT']);
$phoneAuto = jeab_auto_on_message((string) $phone['id']);
qc(count($GLOBALS['_SENT']) === $beforePhone, 'utan e-post skickas ingen offert, fick ' . (string) ($phoneAuto['action'] ?? ''));

echo "inkorg quote chain: ok\n";
