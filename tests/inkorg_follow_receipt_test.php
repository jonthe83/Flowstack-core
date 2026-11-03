<?php

declare(strict_types=1);

/** Första kontakten får offert. Senare svar med stängd påminnelse får kvittens, inte en ny offert. */

$tmp = sys_get_temp_dir() . '/jeab-follow-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export(['timezone' => 'Europe/Stockholm', 'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json']], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function fr(bool $ok, string $m): void
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
    return ['ok' => true, 'from' => $from, 'graph_id' => 'g-follow', 'conversation_id' => 'CVF', 'message_id' => 'sent-follow-' . count($GLOBALS['_SENT']) . '@x'];
};

jeab_auto_pdf_save(['key' => 'laddbox_jarfalla', 'data' => base64_encode("%PDF-1.4 follow")]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode("%PDF-1.4 follow-b")]);
jeab_auto_pdf_save(['key' => 'laddbox_malmo', 'data' => base64_encode("%PDF-1.4 follow-ml")]);
jeab_notiser_save_body('offert-batteri-jarfalla', "Hej\n\nOffert batteri.", 'test');
jeab_notiser_save_body('offert-laddbox-jarfalla', "Hej\n\nOffert laddbox.", 'test');
jeab_notiser_save_body('offert-laddbox-malmo', "Hej\n\nOffert laddbox Malmö.", 'test');
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'follow_receipt_enabled' => 1,
    'auto_trash_spam' => 1,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
fr(jeab_follow_receipt_text() === jeab_follow_receipt_default(), 'kvittensen har en standardtext');
fr(str_contains((string) file_get_contents(dirname(__DIR__) . '/src/pages/inkorg_regler.php'), 'name="follow_receipt"'), 'kvittensen redigeras på ett ställe på Regler');

$in = function (string $id, string $from, string $name, array $labels, string $body, string $thread = '', string $source = 'graph-jarfalla') {
    $row = [
        'message_id' => $id . '@follow.test',
        'from' => $from,
        'from_name' => $name,
        'to' => str_contains($source, 'malmo') ? 'kontakt@malmo.example.test' : 'kontakt@jarfalla.example.test',
        'subject' => 'Laddbox hemma',
        'body_text' => $body,
        'received_at' => '2026-10-19T08:15:00+02:00',
        'source' => $source,
        'graph_mailbox' => str_contains($source, 'malmo') ? 'kontakt@malmo.example.test' : 'kontakt@jarfalla.example.test',
        'graph_id' => 'graph-' . $id,
        'preset_labels' => $labels,
    ];
    if ($thread !== '') {
        $row['thread_key'] = $thread;
    }
    return jeab_inkorg_ingest($row);
};

$kop = ['type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95, 'product' => 'elbilsladdare', 'intent' => 'kop', 'offer' => 'laddbox', 'question' => '', 'reason' => 'vill köpa'];
$first = $in('mira', 'mira.eklund@gmail.com', 'Mira Eklund', $kop, 'Hej, jag vill ha en laddbox.');
$quoted = jeab_auto_on_message((string) $first['id']);
fr(($quoted['action'] ?? '') === 'quoted', 'första kontakten får offert');
fr(count($GLOBALS['_SENT']) === 1 && ($GLOBALS['_SENT'][0]['opts']['person_email'] ?? '') === 'saljare4@jarfalla.example.test', 'första offerten går från Elin');
$remId = (int) ($quoted['reminder'] ?? 0);
$rem = jeab_get_reminder(jeab_store(), $remId);
fr(is_array($rem) && (string) ($rem['person'] ?? '') === 'Elin' && empty($rem['completed_at']), 'påminnelsen ligger på Elin');
jeab_toggle_reminder(jeab_store(), $remId, true);
fr(!empty(jeab_get_reminder(jeab_store(), $remId)['completed_at']), 'påminnelsen är stängd');

$againLabels = $kop;
$againLabels['question'] = 'Kan ni ringa ikväll?';
$again = $in('mira-2', 'mira.eklund@gmail.com', 'Mira Eklund', $againLabels, 'Kan ni ringa ikväll?');
$followed = jeab_auto_on_message((string) $again['id']);
fr(($followed['action'] ?? '') === 'follow' && ($followed['quoted'] ?? true) === false && ($followed['receipt'] ?? 0) === 1, 'andra kontakten får kvittens och ingen offert');
fr((int) ($followed['reminder'] ?? 0) === $remId && ($followed['owner'] ?? '') === 'Elin' && ($followed['from'] ?? '') === 'saljare4@jarfalla.example.test', 'kvittensen går från påminnelsens ägare');
fr(count($GLOBALS['_SENT']) === 2, 'bara en kvittens skickades');
$receipt = $GLOBALS['_SENT'][1];
fr(($receipt['opts']['person_email'] ?? '') === 'saljare4@jarfalla.example.test' && ($receipt['attachments'] ?? null) === [] && str_contains((string) $receipt['body'], jeab_follow_receipt_default()) && str_contains((string) $receipt['body'], 'Elin') && str_contains((string) $receipt['body'], '070-000 00 05'), 'kvittensen har standardtext, namn och mobil');
$openRem = jeab_get_reminder(jeab_store(), $remId);
fr(is_array($openRem) && empty($openRem['completed_at']) && (string) ($openRem['person'] ?? '') === 'Elin' && str_contains((string) $openRem['note'], 'Kunden svarade 19/10: Kan ni ringa ikväll?, ring.'), 'stängd påminnelse öppnas hos Elin med raden');
$againMsg = jeab_inkorg_get((string) $again['id']);
$againThread = jeab_auto_thread_by_id((string) $again['thread_id']);
fr(is_array($againMsg) && ($againMsg['labels']['type'] ?? '') === 'Kräver åtgärd från oss' && is_array($againThread) && ($againThread['status'] ?? '') === 'open', 'ärendet är öppet och kräver åtgärd');

$againSame = jeab_auto_on_message((string) $again['id']);
fr(($againSame['receipt'] ?? 1) === 0 && count($GLOBALS['_SENT']) === 2, 'samma svar skickar inte en andra kvittens');

$reply = $in('mira-3', 'mira.eklund@gmail.com', 'Mira Eklund', $againLabels, 'En sak till, kan ni ringa imorgon?', (string) $again['thread_id']);
$replyRes = jeab_auto_on_message((string) $reply['id']);
fr(($replyRes['action'] ?? '') === 'follow' && ($replyRes['receipt'] ?? 1) === 0 && count($GLOBALS['_SENT']) === 2 && empty(jeab_get_reminder(jeab_store(), $remId)['completed_at']), 'nytt svar inom ett dygn öppnar påminnelsen men skickar ingen ny kvittens');

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'follow_receipt_enabled' => 1,
    'auto_trash_spam' => 1,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
    'follow_receipt' => 'Vi ringer dig snart, testkvittens.',
]);
fr(jeab_follow_receipt_text() === 'Vi ringer dig snart, testkvittens.', 'kvittensen läses från inställningarna');
$nilsRem = jeab_create_reminder(jeab_store(), 'Nils Berg – Elbilsladdare', jeab_today_start(), null, null, [
    'customer' => 'Nils Berg',
    'email' => 'nils.berg@gmail.com',
    'company' => 'jarfalla',
    'who' => 'Elin',
    'note' => 'tidigare',
]);
jeab_toggle_reminder(jeab_store(), (int) $nilsRem['id'], true);
// «tidigare» är inte en skickad offert. Första mejlet får offerten.
// Texten från Regler provas på svaret efter den offerten.
$nilsFirst = $in('nils', 'nils.berg@gmail.com', 'Nils Berg', $againLabels, 'Jag vill köpa och bli uppringd.');
$nilsQuoted = jeab_auto_on_message((string) $nilsFirst['id']);
fr(($nilsQuoted['action'] ?? '') === 'quoted', 'påminnelse utan offert får första offerten');
$nilsQuoteId = (int) ($nilsQuoted['reminder'] ?? $nilsRem['id']);
$nilsQuotedRow = jeab_get_reminder(jeab_store(), $nilsQuoteId);
if (is_array($nilsQuotedRow) && empty($nilsQuotedRow['completed_at'])) {
    jeab_toggle_reminder(jeab_store(), $nilsQuoteId, true);
}
$before = count($GLOBALS['_SENT']);
$nils = $in('nils-2', 'nils.berg@gmail.com', 'Nils Berg', $againLabels, 'Jag vill köpa och bli uppringd.', (string) $nilsFirst['thread_id']);
$nilsRes = jeab_auto_on_message((string) $nils['id']);
$nilsSend = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1] ?? [];
fr(($nilsRes['action'] ?? '') === 'follow' && ($nilsRes['receipt'] ?? 0) === 1 && count($GLOBALS['_SENT']) === $before + 1 && str_contains((string) ($nilsSend['body'] ?? ''), 'Vi ringer dig snart, testkvittens.') && !str_contains((string) ($nilsSend['body'] ?? ''), jeab_follow_receipt_default()), 'ny kvittens använder texten från Regler');

$infoRem = jeab_create_reminder(jeab_store(), 'Ove Dahl – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Ove Dahl',
    'email' => 'ove.dahl@gmail.com',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => 'stängd utan köp',
]);
jeab_toggle_reminder(jeab_store(), (int) $infoRem['id'], true);
$infoLead = [
    'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
    'product' => 'batteri', 'intent' => 'kop', 'offer' => 'batteri', 'question' => '', 'reason' => 'vill köpa',
];
$infoFirst = $in('ove', 'ove.dahl@gmail.com', 'Ove Dahl', $infoLead, 'Hej, jag vill ha ett batteri.');
$infoQuoted = jeab_auto_on_message((string) $infoFirst['id']);
fr(($infoQuoted['action'] ?? '') === 'quoted', 'Oves påminnelse utan offert får första offerten');
$infoQuoteId = (int) ($infoQuoted['reminder'] ?? $infoRem['id']);
$infoQuotedRow = jeab_get_reminder(jeab_store(), $infoQuoteId);
if (is_array($infoQuotedRow) && empty($infoQuotedRow['completed_at'])) {
    jeab_toggle_reminder(jeab_store(), $infoQuoteId, true);
}
$before = count($GLOBALS['_SENT']);
$info = $in('ove-2', 'ove.dahl@gmail.com', 'Ove Dahl', [
    'type' => 'Information', 'action' => 'arkivera', 'priority' => 4, 'confidence' => 90,
    'intent' => 'information', 'offer' => '', 'question' => '', 'reason' => 'läst',
], 'Tack, jag hör av mig senare.', (string) $infoFirst['thread_id']);
$infoRes = jeab_auto_on_message((string) $info['id']);
$infoNow = jeab_get_reminder(jeab_store(), $infoQuoteId);
fr(($infoRes['action'] ?? '') === 'follow' && ($infoRes['receipt'] ?? 1) === 0 && ($infoRes['owner'] ?? '') === 'Roberto' && count($GLOBALS['_SENT']) === $before && is_array($infoNow) && empty($infoNow['completed_at']) && !str_contains((string) $infoNow['note'], 'Kunden svarade'), 'svar utan köp, fråga eller kontakt öppnar påminnelsen utan kvittens');

$boRem = jeab_create_reminder(jeab_store(), 'Bo Ek – Elbilsladdare', jeab_today_start(), null, null, [
    'customer' => 'Bo Ek',
    'email' => '',
    'phone' => '070-111 22 33',
    'company' => 'jarfalla',
    'who' => 'Elin',
    'note' => 'telefon',
]);
jeab_toggle_reminder(jeab_store(), (int) $boRem['id'], true);
$boFirst = $in('bo', 'bo.ek@gmail.com', 'Bo Ek', $againLabels, "Namn: Bo Ek\nTelefon: 070-111 22 33\nE-post: bo.ek@gmail.com\nKan ni ringa?");
$boQuoted = jeab_auto_on_message((string) $boFirst['id']);
fr(($boQuoted['action'] ?? '') === 'quoted', 'telefonpåminnelse utan offert får första offerten');
$boQuoteId = (int) ($boQuoted['reminder'] ?? $boRem['id']);
$boQuotedRow = jeab_get_reminder(jeab_store(), $boQuoteId);
if (is_array($boQuotedRow) && empty($boQuotedRow['completed_at'])) {
    jeab_toggle_reminder(jeab_store(), $boQuoteId, true);
}
$before = count($GLOBALS['_SENT']);
$bo = $in('bo-2', 'bo.ek@gmail.com', 'Bo Ek', $againLabels, "Namn: Bo Ek\nTelefon: 070-111 22 33\nE-post: bo.ek@gmail.com\nKan ni ringa?", (string) $boFirst['thread_id']);
$boRes = jeab_auto_on_message((string) $bo['id']);
fr(($boRes['action'] ?? '') === 'follow' && (int) ($boRes['reminder'] ?? 0) === (int) $boRem['id'] && ($boRes['receipt'] ?? 0) === 1 && count($GLOBALS['_SENT']) === $before + 1 && empty(jeab_get_reminder(jeab_store(), (int) $boRem['id'])['completed_at']), 'samma telefon och namn räcker för att öppna påminnelsen');

$spamRem = jeab_create_reminder(jeab_store(), 'Rea Kund – Elbilsladdare', jeab_today_start(), null, null, [
    'customer' => 'Rea Kund',
    'email' => 'rea.kund@gmail.com',
    'company' => 'jarfalla',
    'who' => 'Elin',
]);
jeab_toggle_reminder(jeab_store(), (int) $spamRem['id'], true);
$spam = $in('rea', 'rea.kund@gmail.com', 'Rea Kund', [
    'type' => 'Skräp', 'action' => 'radera', 'priority' => 4, 'confidence' => 95, 'intent' => 'skrap', 'question' => '', 'reason' => 'reklam',
], 'Rea på nyhetsbrev');
$spamRes = jeab_auto_on_message((string) $spam['id']);
fr(($spamRes['action'] ?? '') === 'historik' && ($spamRes['sent'] ?? 1) === 0 && !empty(jeab_get_reminder(jeab_store(), (int) $spamRem['id'])['completed_at']) && (jeab_inkorg_thread_meta((string) $spam['thread_id'])['status'] ?? '') === 'closed', 'reklam i ett nytt ärende går till Historik och öppnar inte den gamla påminnelsen');

$kimRem = jeab_create_reminder(jeab_store(), 'Kim Kund – Elbilsladdare', jeab_today_start(), null, null, [
    'customer' => 'Kim Kund',
    'email' => 'kim.kund@gmail.com',
    'company' => 'malmo',
    'who' => 'Kim',
    'note' => 'kim',
]);
jeab_toggle_reminder(jeab_store(), (int) $kimRem['id'], true);
$kimFirst = $in('kim', 'kim.kund@gmail.com', 'Kim Kund', $againLabels, 'Kan ni ringa?', '', 'graph-malmo');
jeab_inkorg_thread_write((string) $kimFirst['thread_id'], static function (array $t): array {
    $t['malmo_hs_kim'] = true;
    return $t;
});
$kimQuoted = jeab_auto_on_message((string) $kimFirst['id']);
fr(($kimQuoted['action'] ?? '') === 'quoted', 'Kims påminnelse utan offert får första offerten');
$kimQuoteId = (int) ($kimQuoted['reminder'] ?? $kimRem['id']);
$kimQuotedRow = jeab_get_reminder(jeab_store(), $kimQuoteId);
if (is_array($kimQuotedRow) && empty($kimQuotedRow['completed_at'])) {
    jeab_toggle_reminder(jeab_store(), $kimQuoteId, true);
}
$before = count($GLOBALS['_SENT']);
$kim = $in('kim-2', 'kim.kund@gmail.com', 'Kim Kund', $againLabels, 'Kan ni ringa?', (string) $kimFirst['thread_id'], 'graph-malmo');
$kimRes = jeab_auto_on_message((string) $kim['id']);
$kimSend = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1] ?? [];
fr(($kimRes['action'] ?? '') === 'follow' && ($kimRes['owner'] ?? '') === 'Kim' && ($kimRes['from'] ?? '') === 'saljare.syd@malmo.example.test' && count($GLOBALS['_SENT']) === $before + 1 && ($kimSend['opts']['person_email'] ?? '') === 'saljare.syd@malmo.example.test' && empty(jeab_get_reminder(jeab_store(), (int) $kimRem['id'])['completed_at']), 'Malmö-ärende i pipelinen kvitteras från Kim');

$check = jeab_inkorg_hook(['action' => 'inbox.signatures_check']);
$names = [];
$mobiles = [];
foreach ((array) ($check['sellers'] ?? []) as $row) {
    if (!is_array($row)) {
        continue;
    }
    $names[] = (string) ($row['name'] ?? '');
    $mobiles[] = (bool) ($row['has_mobile'] ?? false);
    fr(array_keys($row) === ['name', 'has_mobile'], 'signaturkollen har bara namn och has_mobile');
}
$encoded = json_encode($check, JSON_UNESCAPED_UNICODE);
fr(($check['ok'] ?? false) === true && $names === ['Elin', 'Roberto', 'Sam L', 'Kim'] && !in_array(false, $mobiles, true) && is_string($encoded) && !str_contains($encoded, '@') && !preg_match('/\d{6,}/', $encoded), 'signaturkollen listar säljarna och att mobil finns');

echo "done\n";
