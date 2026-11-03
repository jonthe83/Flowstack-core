<?php

declare(strict_types=1);

/** Nytt Järfälla-lead får offert och påminnelse åt båda hållen. Diagnosen skickar inget. */

$tmp = sys_get_temp_dir() . '/jeab-autooffer-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ao(bool $ok, string $m): void
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

    return ['ok' => true, 'from' => $from, 'graph_id' => 'g-ao', 'conversation_id' => 'CV', 'message_id' => 'sent-ao@x'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static fn (): array => ['code' => 200, 'body' => ['value' => []]];
$GLOBALS['_JEAB_REBOOK_LLM'] = static fn (): string => json_encode(['answer' => 'Ja.']);
@mkdir(jeab_data_dir() . '/secrets', 0775, true);
file_put_contents(jeab_data_dir() . '/secrets/inkorg-llm.json', json_encode(['key' => 'test']));
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'ink_autooffer']));

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode('%PDF-1.4 batteri')]);
require __DIR__ . '/quote_notiser_seed.php';
jeab_test_seed_quote_notiser();
$jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
jeab_auto_pdf_save(['key' => 'team_jarfalla', 'data' => base64_encode(is_string($jpeg) ? $jpeg : '')]);

$box = jeab_graph_mailboxes()['jarfalla'];
$leadBody = static function (string $name, string $email, string $phone, string $need): string {
    return "Namn: {$name}\nE-post: {$email}\nTelefon: {$phone}\nAdress: Batterivägen 1\nPostnummer: 177 31\nOrt: Järfälla\nBehov: {$need}\n";
};
$ingest = static function (string $id, string $body, array $labels = [], string $subject = 'Nytt lead (HemSol) solceller') use ($box): array {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@hemsol.test',
        'from' => 'leads@hemsol.se',
        'from_name' => 'HemSol',
        'to' => $box,
        'subject' => $subject,
        'date' => date('c'),
        'received_at' => date('c'),
        'body_text' => $body,
        'source' => 'graph-jarfalla',
        'graph_mailbox' => $box,
        'thread_key' => 'ao-' . $id,
        'headers' => ['Authentication-Results' => 'mx.example.test; dmarc=pass header.from=hemsol.se'],
        'preset_labels' => array_merge([
            'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
            'source' => 'llm', 'intent' => 'kop', 'lead' => 'kopt', 'needs_review' => false,
            'offer' => 'sol', 'product' => 'batteri', 'question' => '',
        ], $labels),
    ]);
};

ao(jeab_lead_grok_product_exclusive(['product' => 'batteri', 'offer' => 'sol']) === 'batteri', 'bara batteri är batteri');
ao(jeab_lead_offer(['product' => 'batteri', 'offer' => 'sol']) === 'batteri', 'offer följer produktfältet');
ao(jeab_lead_offer(['product' => 'solceller, batteri', 'offer' => 'sol']) === 'sol', 'sol och batteri lämnar offer sol');

$open = $ingest('open-wait', $leadBody('Öppen Väntar', 'oppen.vantar.auto@gmail.com', '070-100 00 01', 'Endast batteri'));
$sentBefore = count($GLOBALS['_SENT']);
$remBefore = count(jeab_list_reminders(jeab_store(), true));
$diagOpen = jeab_inkorg_hook(['action' => 'inbox.autooffer_diag', 'thread' => (string) $open['thread_id']]);
jeab_inkorg_learn_drain(5);
ao(($diagOpen['ok'] ?? false) === true && (int) ($diagOpen['sent'] ?? 1) === 0 && ($diagOpen['would_send'] ?? true) === false, 'diagnosen är bara läsning');
ao(count($GLOBALS['_SENT']) === $sentBefore && count(jeab_list_reminders(jeab_store(), true)) === $remBefore, 'öppet lead får ingen offert och ingen påminnelse av diagnosen');
$openRow = (array) (($diagOpen['threads'] ?? [])[0] ?? []);
ao((string) ($openRow['reason'] ?? '') === 'offer_mismatch' && (string) ($openRow['product_offer'] ?? '') === 'batteri' && (string) ($openRow['offer'] ?? '') === 'sol', 'oskickat HemSol-batteri visar offer_mismatch, fick ' . (string) ($openRow['reason'] ?? ''));
ao(!isset($openRow['email']) && !isset($openRow['phone']), 'diagnosen har ingen adress');

$review = $ingest('review', $leadBody('Granskning Kund', 'granskning.auto@gmail.com', '070-100 00 02', 'Endast batteri'), ['needs_review' => true, 'confidence' => 40, 'offer' => '', 'product' => '']);
$diagReview = jeab_inkorg_hook(['action' => 'inbox.autooffer_diag', 'thread' => (string) $review['thread_id']]);
ao((string) (($diagReview['threads'][0]['reason'] ?? '')) === 'needs_review', 'granskning syns som needs_review');

$before = count($GLOBALS['_SENT']);
$first = $ingest('vera', $leadBody('Vera Batteri', 'vera.batteri.auto@gmail.com', '070-123 45 67', 'Endast batteri'));
$auto = jeab_auto_on_message((string) $first['id']);
ao(($auto['action'] ?? '') === 'quoted' && count($GLOBALS['_SENT']) === $before + 1, 'nytt batteri-lead skickar offert, fick ' . (string) ($auto['action'] ?? '') . ' ' . (string) ($auto['why'] ?? ''));
$sent = (array) ($GLOBALS['_SENT'][$before] ?? []);
ao((string) ($sent['opts']['person_email'] ?? '') === 'saljare3@jarfalla.example.test', 'avsändaren är Roberto');
ao(str_contains((string) ($sent['subject'] ?? ''), 'Offert batteri'), 'ämnet är batterioffert');
ao(!str_contains(mb_strtolower((string) ($sent['subject'] ?? '')), 'solcell'), 'ämnet är inte sol');

$tid = (string) $first['thread_id'];
$meta = jeab_inkorg_thread_meta($tid);
$rid = (int) ($meta['reminder_id'] ?? 0);
ao($rid > 0, 'ärendet har reminder_id');
$rem = jeab_get_reminder(jeab_store(), $rid);
ao(is_array($rem) && trim((string) ($rem['thread_id'] ?? '')) === $tid, 'påminnelsen har samma thread_id');
$storedMsg = jeab_inkorg_get((string) $first['id']);
ao((string) (($storedMsg['labels']['offer'] ?? '')) === 'batteri', 'sparad offer är batteri');
$diagSent = jeab_inkorg_hook(['action' => 'inbox.autooffer_diag', 'thread' => $tid]);
ao((string) (($diagSent['threads'][0]['reason'] ?? '')) === 'quoted' && count($GLOBALS['_SENT']) === $before + 1, 'skickad offert syns som quoted och diagnosen skickar inte igen');

$beforeDup = count($GLOBALS['_SENT']);
$second = $ingest('vera-dup', $leadBody('Vera Batteri', 'vera.batteri.auto@gmail.com', '070-123 45 67', 'Endast batteri igen'));
$dupAuto = jeab_auto_on_message((string) $second['id']);
ao(($dupAuto['action'] ?? '') === 'hold' && str_contains((string) ($dupAuto['why'] ?? ''), 'skickande av offert har stoppats pga. att offert på samma produkt skickats ut datum') && count($GLOBALS['_SENT']) === $beforeDup, 'dubblett skickar ingen offert, fick ' . (string) ($dupAuto['action'] ?? '') . ' ' . (string) ($dupAuto['why'] ?? ''));
$sameEmail = [];
foreach (jeab_list_reminders(jeab_store(), true) as $row) {
    if (($row['email'] ?? '') === 'vera.batteri.auto@gmail.com') {
        $sameEmail[] = $row;
    }
}
ao(count($sameEmail) === 1, 'samma e-post inom 30 dagar blir en påminnelse');
$dupMeta = jeab_inkorg_thread_meta((string) $second['thread_id']);
ao((int) ($dupMeta['reminder_id'] ?? 0) === $rid, 'dubbletten länkas till den befintliga påminnelsen');
ao(trim((string) ($sameEmail[0]['thread_id'] ?? '')) === $tid, 'den första påminnelsens tråd skrivs inte över');
$diagDup = jeab_inkorg_hook(['action' => 'inbox.autooffer_diag', 'thread' => (string) $second['thread_id']]);
ao((string) (($diagDup['threads'][0]['reason'] ?? '')) === 'duplicate', 'diagnosen säger duplicate');

$phoneBody = "Namn: Pia Telefon\nTelefon: 070-555 66 77\nAdress: Telefonvägen 2\nPostnummer: 177 31\nOrt: Järfälla\nBehov: Endast batteri\n";
$beforeSms = count($GLOBALS['_SENT']);
$smsLead = $ingest('pia', $phoneBody, []);
$smsAuto = jeab_auto_on_message((string) $smsLead['id']);
ao(count($GLOBALS['_SENT']) === $beforeSms, 'utan e-post skickas ingen offert, fick ' . (string) ($smsAuto['action'] ?? '') . ' ' . (string) ($smsAuto['why'] ?? ''));
$smsMeta = jeab_inkorg_thread_meta((string) $smsLead['thread_id']);
$smsRid = (int) ($smsMeta['reminder_id'] ?? 0);
$smsRem = $smsRid > 0 ? jeab_get_reminder(jeab_store(), $smsRid) : null;
ao(is_array($smsRem) && trim((string) ($smsRem['thread_id'] ?? '')) === (string) $smsLead['thread_id'], 'utan e-post får påminnelsen thread_id och ärendet reminder_id');
$diagSms = jeab_inkorg_hook(['action' => 'inbox.autooffer_diag', 'thread' => (string) $smsLead['thread_id']]);
ao((string) (($diagSms['threads'][0]['reason'] ?? '')) === 'no_email', 'diagnosen säger no_email, fick ' . (string) (($diagSms['threads'][0]['reason'] ?? '')));

echo "inkorg autooffer diag: ok\n";
