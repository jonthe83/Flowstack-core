<?php

declare(strict_types=1);

/** Malmö-Weply till Kim, bakgrundsomsändning och inbox.set_assignee. Skickar inget utanför mocken. */

$tmp = sys_get_temp_dir() . '/jeab-resend-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
file_put_contents($tmp . '/secrets/m365.json', json_encode([
    'tenant_id' => 'tenant',
    'client_id' => 'client',
    'client_secret' => 'secret',
]));
require dirname(__DIR__) . '/src/bootstrap.php';

function rk(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$GLOBALS['_SENT'] = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url): array {
    $GLOBALS['_N'] = (int) ($GLOBALS['_N'] ?? 0) + 1;
    if ($method === 'POST') {
        $GLOBALS['_SENT'][] = ['method' => $method, 'url' => $url];

        return ['code' => 202, 'body' => []];
    }

    return ['code' => 200, 'body' => ['value' => []]];
};
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_N'] = (int) ($GLOBALS['_N'] ?? 0) + 1;
    $GLOBALS['_SENT'][] = $a;
    $n = count($GLOBALS['_SENT']);

    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g-' . $n, 'message_id' => 'sent-' . $n . '@local'];
};

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_inkorg_locked('settings.json', static function (?array $d): array {
    $d = $d ?? [];
    $d['graph_person_mail'] = true;

    return [$d, $d];
});
jeab_auto_pdf_save(['key' => 'laddbox_malmo', 'data' => base64_encode('%PDF-1.4 laddbox-malmo')]);
jeab_auto_pdf_save(['key' => 'laddbox_jarfalla', 'data' => base64_encode('%PDF-1.4 laddbox')]);

$box = jeab_graph_mailboxes()['jarfalla'];
$auth = ['Authentication-Results' => 'abc.protection.outlook.com; dmarc=pass header.from=weply.se'];
$ingest = static function (string $id, string $body) use ($box, $auth): array {
    $when = gmdate('c');

    return jeab_inkorg_ingest([
        'message_id' => $id . '@resend.test',
        'from' => 'support@weply.se',
        'from_name' => 'Weply',
        'to' => $box,
        'subject' => 'chatt',
        'date' => $when,
        'received_at' => $when,
        'body_text' => $body,
        'source' => 'graph-jarfalla',
        'thread_key' => 'rk-' . $id,
        'graph_mailbox' => $box,
        'company' => 'jarfalla',
        'headers' => $auth,
        'preset_labels' => ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => '', 'lead' => 'eget', 'confidence' => 90, 'source' => 'llm', 'needs_review' => false, 'question' => ''],
    ]);
};

$chain = jeab_inkorg_resend_chain();
rk((int) ($chain['grok']['budget_ms'] ?? 0) === 20000 && (int) ($chain['graph']['budget_ms'] ?? 0) === 25000 && (int) ($chain['graph']['polls'] ?? 0) === 4 && (int) ($chain['helpscout']['budget_ms'] ?? 0) === 60000 && (int) ($chain['nginx_ms'] ?? 0) === 60000, 'kedjan förklarar 60-sekundersgränsen');

$before = count($GLOBALS['_SENT']);
$battery = $ingest('batt', "Namn: Kim Kund\nE-post: kim.kund@exempel.se\nTelefon: 070-111 22 33\nAdress: Storgatan 1, 211 20 Malmö\nVill ha batteri.\n");
rk(!empty($battery['ok']), 'Malmö-batteri intaget');
jeab_auto_on_message((string) ($battery['id'] ?? ''));
$battThread = jeab_auto_thread_by_id((string) ($battery['thread_id'] ?? ''));
$battRem = jeab_lead_find_reminder((string) ($battThread['id'] ?? ''), '');
$sentKim = false;
foreach (array_slice($GLOBALS['_SENT'], $before) as $call) {
    if (!is_array($call)) {
        continue;
    }
    $opts = (array) ($call['opts'] ?? []);
    $person = strtolower((string) ($opts['person_email'] ?? ''));
    $url = (string) ($call['url'] ?? '');
    if ((!empty($opts['malmo_hs_kim']) && $person === 'saljare.syd@malmo.example.test') || (str_contains($url, 'saljare.syd@malmo.example.test') && str_contains($url, 'sendMail'))) {
        $sentKim = true;
    }
}
rk(is_array($battThread) && (string) ($battThread['assignee'] ?? '') === 'Kim' && !empty($battThread['malmo_hs_kim']), 'tråden är Kims');
rk(is_array($battRem) && (int) ($battRem['id'] ?? 0) > 0 && ((string) ($battRem['assignee'] ?? '') === 'Kim' || (string) ($battRem['who'] ?? '') === 'Kim'), 'påminnelsen är Kims');
rk($sentKim, 'offerten gick från Kims brevlåda');

$before = count($GLOBALS['_SENT']);
$boxLead = $ingest('box', "Namn: Lina Ladd\nE-post: lina.ladd@exempel.se\nTelefon: 070-222 33 44\nAdress: 212 22 Malmö\nVill ha laddbox.\n");
$boxAuto = jeab_auto_on_message((string) ($boxLead['id'] ?? ''));
$boxThread = jeab_auto_thread_by_id((string) ($boxLead['thread_id'] ?? ''));
rk((string) ($boxAuto['action'] ?? '') === 'quoted' && (string) ($boxAuto['key'] ?? '') === 'laddbox_malmo' && (string) ($boxThread['assignee'] ?? '') === 'Kim', 'Malmö-laddbox använder Kims mall');

$before = count($GLOBALS['_SENT']);
$solar = $ingest('sol', "Namn: Sol Skane\nE-post: sol.skane@exempel.se\nTelefon: 070-333 44 55\nAdress: 211 20 Malmö\nVill ha solceller.\n");
$solAuto = jeab_auto_on_message((string) ($solar['id'] ?? ''));
$solThread = jeab_auto_thread_by_id((string) ($solar['thread_id'] ?? ''));
$solRem = jeab_lead_find_reminder((string) ($solThread['id'] ?? ''), '');
rk(count($GLOBALS['_SENT']) === $before && ($solAuto['action'] ?? '') === 'skip' && (string) ($solThread['assignee'] ?? '') === 'SamW' && !is_array($solRem), 'Malmö-sol skickas inte och lämnas till Sam W');

$jarfalla = $ingest('jf', "Namn: Nora Norr\nE-post: nora.norr@exempel.se\nTelefon: 070-444 55 66\nAdress: Solvägen 1, 177 31 Järfälla\nVill ha batteri.\n");
jeab_auto_on_message((string) ($jarfalla['id'] ?? ''));
$jfThread = jeab_auto_thread_by_id((string) ($jarfalla['thread_id'] ?? ''));
rk((string) ($jfThread['assignee'] ?? '') !== 'Kim', 'Järfälla-Weply blir inte Kims');

$plain = jeab_inkorg_ingest([
    'message_id' => 'plain@resend.test',
    'from' => 'anna.assignee@exempel.se',
    'from_name' => 'Anna Assignee',
    'to' => $box,
    'subject' => 'Batteri',
    'date' => gmdate('c'),
    'received_at' => gmdate('c'),
    'body_text' => "Namn: Anna Assignee\nE-post: anna.assignee@exempel.se\nTelefon: 070-555 66 77\nAdress: Parkvägen 2, 177 31 Järfälla\nVill ha batteri.\n",
    'source' => 'graph-jarfalla',
    'thread_key' => 'rk-plain',
    'graph_mailbox' => $box,
    'company' => 'jarfalla',
    'headers' => ['Authentication-Results' => 'abc.protection.outlook.com; dmarc=pass header.from=exempel.se'],
    'preset_labels' => ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'batteri', 'lead' => 'eget', 'confidence' => 90, 'source' => 'llm', 'needs_review' => false, 'question' => ''],
]);
jeab_auto_on_message((string) ($plain['id'] ?? ''));
$plainId = (string) ($plain['thread_id'] ?? '');
$plainRem = jeab_lead_find_reminder($plainId, '');
$dryAssign = jeab_inkorg_hook(['action' => 'inbox.set_assignee', 'thread_id' => $plainId, 'assignee' => 'Elin']);
rk(($dryAssign['dry'] ?? 0) === 1 && (int) ($dryAssign['sent'] ?? 1) === 0 && (string) ($dryAssign['assignee'] ?? '') === 'Elin' && (string) (jeab_auto_thread_by_id($plainId)['assignee'] ?? '') !== 'Elin', 'tilldelning är torr som standard');
$deniedAssign = jeab_inkorg_hook(['action' => 'inbox.set_assignee', 'thread_id' => $plainId, 'assignee' => 'Elin', 'dry' => 0]);
rk(($deniedAssign['ok'] ?? true) === false && ($deniedAssign['error'] ?? '') === 'confirm', 'tilldelning kräver confirm');
$wrote = jeab_inkorg_hook(['action' => 'inbox.set_assignee', 'thread_id' => $plainId, 'assignee' => 'Elin', 'dry' => 0, 'confirm' => 'SAM']);
$afterRem = jeab_lead_find_reminder($plainId, '');
rk(($wrote['ok'] ?? false) === true && (string) (jeab_auto_thread_by_id($plainId)['assignee'] ?? '') === 'Elin' && is_array($afterRem) && (string) ($afterRem['assignee'] ?? '') === 'Elin', 'tråd och påminnelse får samma person');
$undo = jeab_inkorg_hook(['action' => 'inbox.set_assignee', 'undo' => 1, 'batch' => (string) ($wrote['undo'] ?? ''), 'dry' => 0, 'confirm' => 'SAM']);
$undone = jeab_lead_find_reminder($plainId, '');
rk(($undo['ok'] ?? false) === true && (string) (jeab_auto_thread_by_id($plainId)['assignee'] ?? '') === (string) ($jfThread['assignee'] ?? 'Roberto') && is_array($undone) && (string) ($undone['person'] ?? '') === (string) ($plainRem['person'] ?? ''), 'ångring återställer tråd och påminnelse');

$charge = jeab_inkorg_ingest([
    'message_id' => 'charge-kim@resend.test',
    'from' => 'support@weply.se',
    'from_name' => 'Weply',
    'to' => $box,
    'subject' => 'chatt laddbox',
    'date' => gmdate('c', time() - 3600),
    'received_at' => gmdate('c', time() - 3600),
    'body_text' => "Namn: Kim Ladd\nE-post: kim.ladd@exempel.se\nTelefon: 070-888 99 00\nAdress: Solvägen 9, 177 31 Järfälla\nVill ha laddbox.\n",
    'source' => 'graph-jarfalla',
    'thread_key' => 'rk-charge',
    'graph_mailbox' => $box,
    'company' => 'jarfalla',
    'headers' => $auth,
    'preset_labels' => ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'laddbox', 'lead' => 'eget', 'confidence' => 90, 'source' => 'llm', 'needs_review' => false, 'question' => ''],
    'backfilled' => 1,
]);
$chargeId = (string) ($charge['thread_id'] ?? '');
$chargeThread = jeab_auto_thread_by_id($chargeId);
rk(is_array($chargeThread) && jeab_inkorg_thread_company($chargeThread) === 'jarfalla', 'laddboxtråden ligger i Järfällas mejlbox');

jeab_auto_settings_save([
    'auto_quote_enabled' => 0,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'tpl' => jeab_auto_quote_seed(),
]);
$openA = jeab_inkorg_ingest([
    'message_id' => 'open-a@resend.test',
    'from' => 'support@weply.se',
    'from_name' => 'Weply',
    'to' => $box,
    'subject' => 'chatt a',
    'date' => gmdate('c', time() - 86400),
    'received_at' => gmdate('c', time() - 86400),
    'body_text' => "Namn: A A\nE-post: a.a@exempel.se\nTelefon: 070-666 77 88\nAdress: Solvägen 4, 177 31 Järfälla\nVill ha batteri.\n",
    'source' => 'graph-jarfalla',
    'thread_key' => 'rk-a',
    'graph_mailbox' => $box,
    'company' => 'jarfalla',
    'headers' => $auth,
    'preset_labels' => ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'batteri', 'lead' => 'eget', 'confidence' => 90, 'source' => 'llm', 'needs_review' => false, 'question' => ''],
    'backfilled' => 1,
]);
$openB = jeab_inkorg_ingest([
    'message_id' => 'open-b@resend.test',
    'from' => 'support@weply.se',
    'from_name' => 'Weply',
    'to' => $box,
    'subject' => 'chatt b',
    'date' => gmdate('c', time() - 86400),
    'received_at' => gmdate('c', time() - 86400),
    'body_text' => "Namn: B B\nE-post: b.b@exempel.se\nTelefon: 070-777 88 99\nAdress: Solvägen 5, 177 31 Järfälla\nVill ha batteri.\n",
    'source' => 'graph-jarfalla',
    'thread_key' => 'rk-b',
    'graph_mailbox' => $box,
    'company' => 'jarfalla',
    'headers' => $auth,
    'preset_labels' => ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'batteri', 'lead' => 'eget', 'confidence' => 90, 'source' => 'llm', 'needs_review' => false, 'question' => ''],
    'backfilled' => 1,
]);
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'tpl' => jeab_auto_quote_seed(),
]);
$aId = (string) ($openA['thread_id'] ?? '');
$bId = (string) ($openB['thread_id'] ?? '');
$before = count($GLOBALS['_SENT']);
$queued = jeab_inkorg_hook(['action' => 'inbox.resend_missed', 'dry' => 0, 'confirm' => 'SAM', 'ids' => [$aId, $bId]]);
$aMarked = jeab_auto_thread_by_id($aId);
$bMarked = jeab_auto_thread_by_id($bId);
$dryOne = jeab_inkorg_hook(['action' => 'inbox.resend_missed', 'ids' => [$aId]]);
$dryJson = (string) json_encode($dryOne);
rk((int) ($queued['sent'] ?? 1) === 0 && ($queued['status'] ?? '') === 'queued' && count($GLOBALS['_SENT']) === $before && trim((string) ($aMarked['missed_resend_at'] ?? '')) !== '' && trim((string) ($bMarked['missed_resend_at'] ?? '')) === '' && in_array($bId, (array) ($queued['left'] ?? []), true), 'en tråd köas och markeras, den andra väntar');
rk(($dryOne['leads'][0]['sender'] ?? '') !== '' && ($dryOne['leads'][0]['template'] ?? '') !== '' && ($dryOne['leads'][0]['recipient'] ?? '') === 'yes' && !str_contains($dryJson, '@'), 'torr status har namn, mall och mottagare utan adress');
$GLOBALS['_JEAB_RESEND_PROFILE_INJECT'] = ['pdf' => 12, 'grok' => 20000, 'graph' => 25000, 'helpscout' => 60000];
$done = jeab_inkorg_resend_work((string) ($queued['job'] ?? ''));
unset($GLOBALS['_JEAB_RESEND_PROFILE_INJECT']);
$status = jeab_inkorg_hook(['action' => 'inbox.resend_status', 'job' => (string) ($queued['job'] ?? '')]);
$statusJson = (string) json_encode($status);
rk((string) ($done['status'] ?? '') === 'sent' && (int) ($status['profile']['helpscout'] ?? 0) === 60000 && (string) ($status['profile']['slow'] ?? '') === 'helpscout' && (int) ($status['chain']['nginx_ms'] ?? 0) === 60000 && !str_contains($statusJson, '@'), 'status visar att Help Scout är den långsamma delen');
$again = jeab_inkorg_resend_work((string) ($queued['job'] ?? ''));
rk((int) ($again['sent'] ?? 0) === 1 && (string) ($again['status'] ?? '') === 'sent', 'samma jobb skickar inte en andra gång');

$dryElin = jeab_inkorg_hook(['action' => 'inbox.resend_missed', 'ids' => [$chargeId]]);
$elinRow = (array) ($dryElin['leads'][0] ?? []);
rk(jeab_inkorg_thread_company(jeab_auto_thread_by_id($chargeId) ?? []) === 'jarfalla' && ($elinRow['sender'] ?? '') === 'Elin, Demo Energi Järfälla' && ($elinRow['template'] ?? '') === 'offert-laddbox-jarfalla' && ($elinRow['action'] ?? '') === 'would', 'Järfällas mejlbox ger Elin innan tilldelningen');
$assigned = jeab_inkorg_hook(['action' => 'inbox.set_assignee', 'thread_id' => $chargeId, 'assignee' => 'Kim', 'dry' => 0, 'confirm' => 'SAM']);
$assignedThread = jeab_auto_thread_by_id($chargeId);
rk(($assigned['ok'] ?? false) === true && (string) ($assignedThread['assignee'] ?? '') === 'Kim' && jeab_inkorg_thread_company($assignedThread) === 'jarfalla', 'Kim är satt och mejlboxen är kvar i Järfälla');
jeab_inkorg_thread_write($chargeId, static function (array $t): array {
    $t['missed_resend_at'] = '2026-10-26T08:00:00+00:00';

    return $t;
});
$dryMarked = jeab_inkorg_hook(['action' => 'inbox.resend_missed', 'ids' => [$chargeId]]);
$markedRow = (array) ($dryMarked['leads'][0] ?? []);
$markedJson = (string) json_encode($dryMarked);
$markedEvidence = (array) ($markedRow['evidence'] ?? []);
rk(($markedRow['sender'] ?? '') === 'Kim, Demo Energi Malmö' && ($markedRow['template'] ?? '') === 'offert-laddbox-malmo' && ($markedRow['action'] ?? '') === 'would' && ($markedEvidence['sent_log'] ?? '') === 'no' && ($markedEvidence['quote_sent_diag'] ?? '') === 'no' && ($markedEvidence['outlook_sent'] ?? '') === 'no' && ($markedEvidence['any'] ?? '') === 'no' && !str_contains($markedJson, '@'), 'torr körning: Kim och Malmös laddboxmall trots markör och utan utskick');
$before = count($GLOBALS['_SENT']);
$live = jeab_inkorg_hook(['action' => 'inbox.resend_missed', 'dry' => 0, 'confirm' => 'SAM', 'ids' => [$chargeId]]);
rk((int) ($live['sent'] ?? 1) === 0 && ($live['status'] ?? '') === 'queued' && count($GLOBALS['_SENT']) === $before, 'bekräftad omsändning köar utan att skicka');
$GLOBALS['_JEAB_SEND_GUARD_REPLAY'] = true;
$worked = jeab_inkorg_resend_work((string) ($live['job'] ?? ''));
unset($GLOBALS['_JEAB_SEND_GUARD_REPLAY']);
$sentKimOffice = false;
foreach (array_slice($GLOBALS['_SENT'], $before) as $call) {
    if (!is_array($call)) {
        continue;
    }
    $opts = (array) ($call['opts'] ?? []);
    if (!empty($opts['malmo_hs_kim']) && strtolower((string) ($opts['person_email'] ?? '')) === 'saljare.syd@malmo.example.test') {
        $sentKimOffice = true;
    }
}
rk((int) ($worked['sent'] ?? 0) === 1 && $sentKimOffice, 'jobbet skickar från Kims brevlåda');
$dryDone = jeab_inkorg_hook(['action' => 'inbox.resend_missed', 'ids' => [$chargeId]]);
$doneRow = (array) ($dryDone['leads'][0] ?? []);
$doneEvidence = (array) ($doneRow['evidence'] ?? []);
rk(($doneRow['action'] ?? '') === 'already' && (($doneEvidence['sent_log'] ?? '') === 'yes' || ($doneEvidence['quote_sent_diag'] ?? '') === 'yes' || ($doneEvidence['outlook_sent'] ?? '') === 'yes'), 'torr körning efter utskick är already på verklig träff');

echo "resend kim assignee: ok\n";
