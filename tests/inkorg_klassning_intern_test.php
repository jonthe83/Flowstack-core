<?php

declare(strict_types=1);

/** Egen post till Historik. Tyst leverantör till Information. Aldrig kund i Inkorgen. */

$tmp = sys_get_temp_dir() . '/jeab-klass-intern-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ki(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$plain = static function (string $type = 'Oklassad', string $intent = '', int $conf = 0): array {
    return ['type' => $type, 'intent' => $intent, 'confidence' => $conf, 'action' => 'svara', 'priority' => 3, 'needs_review' => $conf < 80];
};

$jarfalla = ['from' => 'info@jarfalla.example.test', 'from_name' => 'Demo Energi Järfälla', 'subject' => 'Internt', 'body' => 'Hej från oss.', 'labels' => $plain()];
$sam = ['from' => 'demo.person@example.invalid', 'from_name' => 'Sam Exempel', 'subject' => 'Test från mig', 'body' => 'Hej.', 'labels' => $plain()];
$export = ['from' => 'export.sales@vendor-supply.se', 'from_name' => 'Export Sales', 'subject' => 'Prislista', 'body' => 'Bifogad prislista. Ingen åtgärd behövs.', 'labels' => $plain()];
$testsson = ['from' => 'testsson@gmail.com', 'from_name' => 'Test Testsson', 'subject' => 'Hej', 'body' => 'Ett test.', 'labels' => $plain()];
$kalkyl = ['from' => 'form@kalkyl.jarfalla.example.test', 'from_name' => 'Kalkyl', 'subject' => 'Ny förfrågan', 'body' => 'Hej', 'labels' => $plain()];

ki(jeab_inkorg_klassning_route($jarfalla) === 'egen' && !jeab_inkorg_klassning_customer_mail($jarfalla), 'Demo Energi Järfälla är egen post');
ki(jeab_inkorg_klassning_route($sam) === 'egen' && !jeab_inkorg_klassning_customer_mail($sam), 'Sam Exempel är egen post');
ki(jeab_inkorg_klassning_route($export) === 'information' && !jeab_inkorg_klassning_customer_mail($export), 'Export Sales är information');
ki(jeab_inkorg_klassning_route($testsson) === 'egen' && !jeab_inkorg_klassning_customer_mail($testsson), 'Test Testsson är egen post');
ki(jeab_inkorg_klassning_route($kalkyl) === 'kop' && jeab_inkorg_klassning_lead_sender($kalkyl), 'kalkyl förblir köpintention');

$open = ['id' => 't', 'status' => 'open', 'labels' => $plain()];
$decJarfalla = jeab_inkorg_klassning_decide($open, $jarfalla);
$decSam = jeab_inkorg_klassning_decide($open, $sam);
$decTest = jeab_inkorg_klassning_decide($open, $testsson);
$decExport = jeab_inkorg_klassning_decide($open, $export);
ki(is_array($decJarfalla) && $decJarfalla['status'] === 'closed' && $decJarfalla['type'] !== 'Information' && $decJarfalla['via'] === 'egen', 'Demo Energi Järfälla stängs till Historik');
ki(is_array($decSam) && $decSam['status'] === 'closed' && $decSam['via'] === 'egen', 'Sam Exempel stängs till Historik');
ki(is_array($decTest) && $decTest['status'] === 'closed' && $decTest['via'] === 'egen', 'Test Testsson stängs till Historik');
ki(is_array($decExport) && $decExport['status'] === 'open' && $decExport['type'] === 'Information', 'Export Sales blir Information');

$busy = $export;
$busy['subject'] = 'Defekt vara';
$busy['body'] = 'Enheten är defekt.';
ki(jeab_inkorg_klassning_route($busy) === 'inkorg' && jeab_inkorg_klassning_customer_mail($busy), 'leverantör med kundfråga stannar i Inkorgen');

$GLOBALS['_SENT'] = [];
$GLOBALS['_FOLLOW_N'] = 0;
$GLOBALS['_CLOSE_N'] = 0;
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_SENT'][] = $a;

    return ['ok' => true];
};
$GLOBALS['JEAB_FOLLOW_TRY_WATCH'] = static function (): void {
    $GLOBALS['_FOLLOW_N'] = (int) ($GLOBALS['_FOLLOW_N'] ?? 0) + 1;
};
$GLOBALS['JEAB_CLOSE_QUOTED_WATCH'] = static function (): void {
    $GLOBALS['_CLOSE_N'] = (int) ($GLOBALS['_CLOSE_N'] ?? 0) + 1;
};
jeab_follow_try(['id' => 'watch', 'deleted' => true, 'messages' => []], ['from' => 'a@b.se']);
jeab_inkorg_close_quoted_try('');
ki((int) $GLOBALS['_FOLLOW_N'] === 1 && (int) $GLOBALS['_CLOSE_N'] === 1, 'bevakningen ser uppföljning och offertstängning');
$GLOBALS['_FOLLOW_N'] = 0;
$GLOBALS['_CLOSE_N'] = 0;

$in = static function (string $id, array $msg): array {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@klass-intern.test',
        'from' => (string) $msg['from'],
        'from_name' => (string) ($msg['from_name'] ?? ''),
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => (string) $msg['subject'],
        'body_text' => (string) ($msg['body'] ?? ''),
        'received_at' => date('c'),
        'source' => 'graph-jarfalla',
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
        'preset_labels' => (array) $msg['labels'],
    ]);
};
$thread = static function (string $tid): array {
    foreach (jeab_inkorg_threads() as $t) {
        if ((string) ($t['id'] ?? '') === $tid) {
            return $t;
        }
    }

    return [];
};

foreach ([
    'jarfalla' => $jarfalla,
    'sam' => $sam,
    'testsson' => $testsson,
] as $id => $msg) {
    $saved = $in($id, $msg);
    $auto = jeab_auto_on_message((string) $saved['id']);
    $row = $thread((string) $saved['thread_id']);
    ki(($auto['action'] ?? '') === 'historik' && ($auto['sent'] ?? 1) === 0 && ($row['status'] ?? '') === 'closed' && jeab_inkorg_folder_thread($row, 'historik') && !jeab_inkorg_folder_thread($row, 'inkorg'), $msg['from_name'] . ' hamnar i Historik');
}

$exportSaved = $in('export', $export);
$exportAuto = jeab_auto_on_message((string) $exportSaved['id']);
$exportRow = $thread((string) $exportSaved['thread_id']);
ki(($exportAuto['action'] ?? '') === 'information' && ($exportAuto['sent'] ?? 1) === 0 && ($exportRow['status'] ?? '') === 'open' && jeab_inkorg_folder_thread($exportRow, 'information') && !jeab_inkorg_folder_thread($exportRow, 'inkorg'), 'Export Sales hamnar i Information');
ki((int) $GLOBALS['_FOLLOW_N'] === 0 && (int) $GLOBALS['_CLOSE_N'] === 0 && $GLOBALS['_SENT'] === [], 'ett ensamt internt mejl anropar varken uppföljning eller offertstängning');

$kund = ['from' => 'ulf.hedenstrom@hedenstrom.example', 'from_name' => 'Ulf Hedenström', 'subject' => 'Installation av laddbox', 'body' => 'Hej, jag hörde av mig om laddboxen.', 'labels' => $plain()];
$kundSaved = $in('kund', $kund);
$kundAgain = jeab_inkorg_ingest([
    'message_id' => 'kund-igen@klass-intern.test',
    'from' => (string) $kund['from'],
    'from_name' => (string) $kund['from_name'],
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Re: Installation av laddbox',
    'body_text' => 'Någon som kan återkomma?',
    'received_at' => date('c'),
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'preset_labels' => $plain(),
    'in_reply_to' => 'kund@klass-intern.test',
]);
ki((string) ($kundAgain['thread_id'] ?? '') === (string) ($kundSaved['thread_id'] ?? ''), 'kundens andra mejl ligger i samma tråd');
$openBefore = $thread((string) $kundSaved['thread_id']);
ki(($openBefore['status'] ?? '') === 'open' && count((array) ($openBefore['messages'] ?? [])) >= 2, 'kundtråden är öppen och har flera mejl');
$reply = $sam;
$reply['subject'] = 'Re: Installation av laddbox';
$replyIn = jeab_inkorg_ingest([
    'message_id' => 'sam-svar@klass-intern.test',
    'from' => (string) $reply['from'],
    'from_name' => (string) $reply['from_name'],
    'to' => 'ulf.hedenstrom@hedenstrom.example, kontakt@jarfalla.example.test',
    'cc' => 'saljare4@jarfalla.example.test',
    'subject' => (string) $reply['subject'],
    'body_text' => 'Vi tar det internt.',
    'received_at' => date('c'),
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'preset_labels' => (array) $reply['labels'],
    'in_reply_to' => 'kund-igen@klass-intern.test',
]);
ki((string) ($replyIn['thread_id'] ?? '') === (string) ($kundSaved['thread_id'] ?? '') && count((array) ($thread((string) $kundSaved['thread_id'])['messages'] ?? [])) >= 3, 'svar-till-alla från personal ligger i kundtråden');
$followBefore = (int) $GLOBALS['_FOLLOW_N'];
$sentBefore = $GLOBALS['_SENT'];
$replyAuto = jeab_auto_on_message((string) $replyIn['id']);
$replyRow = $thread((string) $kundSaved['thread_id']);
ki(($replyRow['status'] ?? '') === 'open' && ($replyAuto['action'] ?? '') !== 'historik' && $GLOBALS['_SENT'] === $sentBefore, 'svar-till-alla i öppen kundtråd lämnar status och skickar inget');
ki((int) $GLOBALS['_FOLLOW_N'] === $followBefore, 'personalens svar i en längre tråd får inget kvitto');
$boxIn = jeab_inkorg_ingest([
    'message_id' => 'kontakt-kopia@klass-intern.test',
    'from' => 'kontakt@jarfalla.example.test',
    'from_name' => 'Demo Energi Järfälla',
    'to' => 'ulf.hedenstrom@hedenstrom.example',
    'subject' => 'Re: Installation av laddbox',
    'body_text' => 'Kopia från brevlådan.',
    'received_at' => date('c'),
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'preset_labels' => $plain('Oklassad', '', 0),
    'in_reply_to' => 'sam-svar@klass-intern.test',
]);
ki((string) ($boxIn['thread_id'] ?? '') === (string) ($kundSaved['thread_id'] ?? ''), 'kontakt@ ligger i samma kundtråd');
$statusBeforeBox = (string) ($thread((string) $kundSaved['thread_id'])['status'] ?? '');
$boxAuto = jeab_auto_on_message((string) $boxIn['id']);
$boxRow = $thread((string) $kundSaved['thread_id']);
ki(($boxAuto['action'] ?? '') === 'skip' && (string) ($boxRow['status'] ?? '') === $statusBeforeBox && $GLOBALS['_SENT'] === $sentBefore, 'kontakt@ i en längre tråd hoppas över utan ändring');

$sentAtDry = $GLOBALS['_SENT'];
$customerThreads = [];
foreach ([
    'hans' => ['hans.steijen@gmail.com', 'Hans Steijen'],
    'abbas' => ['abbas.lavehi@gmail.com', 'Abbas Lavehi'],
] as $id => [$email, $name]) {
    $saved = jeab_inkorg_ingest([
        'message_id' => $id . '@klass-intern.test',
        'from' => $email,
        'from_name' => $name,
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Fråga om installation',
        'body_text' => 'Hej, jag vill höra mer.',
        'received_at' => '2026-10-20T09:00:00+02:00',
        'source' => 'graph-jarfalla',
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
        'preset_labels' => $plain(),
    ]);
    $reply = jeab_inkorg_ingest([
        'message_id' => $id . '-svar@klass-intern.test',
        'from' => 'saljare4@jarfalla.example.test',
        'from_name' => 'Elin, Demo Energi Järfälla',
        'to' => $email,
        'subject' => 'Re: Fråga om installation',
        'body_text' => 'Hej, vi återkommer.',
        'received_at' => '2026-10-21T09:00:00+02:00',
        'source' => 'graph-jarfalla',
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
        'preset_labels' => $plain(),
        'in_reply_to' => $id . '@klass-intern.test',
    ]);
    ki((string) ($reply['thread_id'] ?? '') === (string) ($saved['thread_id'] ?? '') && (string) ($saved['thread_id'] ?? '') !== '', $name . ' och vårt svar ligger i samma tråd');
    $row = $thread((string) $saved['thread_id']);
    $face = jeab_inkorg_klassning_face($row);
    ki((string) ($face['from'] ?? '') === $email && jeab_inkorg_klassning_route($face) !== 'egen', $name . ' klassas på kundens mejl, inte vårt svar');
    $customerThreads[$id] = (string) $saved['thread_id'];
}
$dryCustomers = jeab_inkorg_klassning_reclassify(['dry' => 1, 'days' => 30]);
ki(($dryCustomers['ok'] ?? false) === true && ($dryCustomers['dry'] ?? 0) === 1 && $GLOBALS['_SENT'] === $sentAtDry, 'dry=1 skickar inget');
foreach ($customerThreads as $id => $tid) {
    $hit = false;
    foreach ((array) ($dryCustomers['rows'] ?? []) as $row) {
        if ((string) ($row['thread_id'] ?? '') === $tid && (string) ($row['route'] ?? '') === 'egen') {
            $hit = true;
        }
    }
    $still = $thread($tid);
    ki(!$hit && ($still['status'] ?? '') === 'open', $id . ' blir inte egen/Oklassad i dry=1');
}

ki(jeab_inkorg_klassning_staff_address('saljare.syd@malmo.example.test') && jeab_inkorg_klassning_staff_address('contact@malmo.example.test') && jeab_inkorg_klassning_staff_address('demo@malmo.example.test'), 'Malmös domän och adresser är personal');
ki(jeab_inkorg_klassning_staff_name('Demo Energi Malmö') && jeab_inkorg_klassning_staff_name('Demo Energi Malmö') && jeab_inkorg_klassning_staff_name('Demo Energi Järfälla') && jeab_inkorg_klassning_staff_name('Demo Energi Järfälla'), 'bolagsnamn och AB-varianten matchar exakt');
ki(!jeab_inkorg_klassning_staff_name('Kim, Demo Energi Malmö') && !jeab_inkorg_klassning_staff_name('Elin, Demo Energi Järfälla'), 'namn som bara innehåller bolagsnamnet är inte personal');

$offer = jeab_inkorg_ingest([
    'message_id' => 'offert@klass-intern.test',
    'from' => 'saljare4@jarfalla.example.test',
    'from_name' => 'Elin',
    'to' => 'kund.offert@gmail.com',
    'subject' => 'Offert Elbilsladdare',
    'body_text' => 'Här är offerten.',
    'received_at' => '2026-10-18T09:00:00+02:00',
    'source' => 'outbound-graph',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'preset_labels' => $plain(),
]);
$offerReply = jeab_inkorg_ingest([
    'message_id' => 'offert-svar@klass-intern.test',
    'from' => 'kund.offert@gmail.com',
    'from_name' => 'Kund Offert',
    'to' => 'saljare4@jarfalla.example.test',
    'subject' => 'Re: Offert Elbilsladdare',
    'body_text' => 'Tack, jag tar den.',
    'received_at' => '2026-10-19T09:00:00+02:00',
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'preset_labels' => $plain(),
    'in_reply_to' => 'offert@klass-intern.test',
]);
ki((string) ($offerReply['thread_id'] ?? '') === (string) ($offer['thread_id'] ?? '') && (string) ($offer['thread_id'] ?? '') !== '', 'kundsvaret ligger i offerttråden');
$offerRow = $thread((string) $offer['thread_id']);
$offerStatus = (string) ($offerRow['status'] ?? '');
$offerFace = jeab_inkorg_klassning_face($offerRow);
ki((string) ($offerFace['from'] ?? '') === 'kund.offert@gmail.com' && jeab_inkorg_klassning_route($offerFace) !== 'egen', 'offert plus kundsvar är inte egen');
$dryOffer = jeab_inkorg_klassning_reclassify(['dry' => 1, 'days' => 30]);
$offerEgen = false;
foreach ((array) ($dryOffer['rows'] ?? []) as $row) {
    if ((string) ($row['thread_id'] ?? '') === (string) $offer['thread_id'] && (string) ($row['route'] ?? '') === 'egen') {
        $offerEgen = true;
    }
}
ki(!$offerEgen && (string) ($thread((string) $offer['thread_id'])['status'] ?? '') === $offerStatus && $GLOBALS['_SENT'] === $sentAtDry, 'offert plus kundsvar lämnar status oförändrad');

$weFirst = jeab_inkorg_ingest([
    'message_id' => 'vi-forst@klass-intern.test',
    'from' => 'kontakt@jarfalla.example.test',
    'from_name' => 'Demo Energi Järfälla',
    'to' => 'fragare@gmail.com',
    'subject' => 'Hej',
    'body_text' => 'Vi hörde av oss.',
    'received_at' => '2026-10-18T10:00:00+02:00',
    'source' => 'outbound-graph',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'preset_labels' => $plain(),
]);
$question = jeab_inkorg_ingest([
    'message_id' => 'fraga@klass-intern.test',
    'from' => 'fragare@gmail.com',
    'from_name' => 'Eva Frågare',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Re: Hej',
    'body_text' => 'Hur fungerar laddboxen?',
    'received_at' => '2026-10-19T10:00:00+02:00',
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'preset_labels' => $plain(),
    'in_reply_to' => 'vi-forst@klass-intern.test',
]);
ki((string) ($question['thread_id'] ?? '') === (string) ($weFirst['thread_id'] ?? ''), 'kundens fråga ligger i tråden vi började');
$questionRow = $thread((string) $weFirst['thread_id']);
$questionFace = jeab_inkorg_klassning_face($questionRow);
ki((string) ($questionFace['from'] ?? '') === 'fragare@gmail.com' && jeab_inkorg_klassning_route($questionFace) === 'inkorg' && jeab_inkorg_folder_thread($questionRow, 'inkorg') && !jeab_inkorg_folder_thread($questionRow, 'historik'), 'vi skriver först och kunden frågar: Inkorg');

$onlyA = jeab_inkorg_ingest([
    'message_id' => 'bara-intern-a@klass-intern.test',
    'from' => 'info@jarfalla.example.test',
    'from_name' => 'Demo Energi Järfälla',
    'to' => 'saljare4@jarfalla.example.test',
    'subject' => 'Internt',
    'body_text' => 'Bara oss.',
    'received_at' => '2026-10-18T11:00:00+02:00',
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'preset_labels' => $plain(),
]);
$onlyB = jeab_inkorg_ingest([
    'message_id' => 'bara-intern-b@klass-intern.test',
    'from' => 'saljare4@jarfalla.example.test',
    'from_name' => 'Elin',
    'to' => 'info@jarfalla.example.test',
    'subject' => 'Re: Internt',
    'body_text' => 'Uppfattat.',
    'received_at' => '2026-10-18T12:00:00+02:00',
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'preset_labels' => $plain(),
    'in_reply_to' => 'bara-intern-a@klass-intern.test',
]);
ki((string) ($onlyB['thread_id'] ?? '') === (string) ($onlyA['thread_id'] ?? ''), 'intern post ligger i samma tråd');
$onlyRow = $thread((string) $onlyA['thread_id']);
$onlyFace = jeab_inkorg_klassning_face($onlyRow);
$onlyDecision = jeab_inkorg_klassning_decide($onlyRow, $onlyFace);
ki(jeab_inkorg_klassning_route($onlyFace) === 'egen' && is_array($onlyDecision) && ($onlyDecision['via'] ?? '') === 'egen', 'bara interna mejl är egen');

$malmoA = jeab_inkorg_ingest([
    'message_id' => 'sam-malmo@klass-intern.test',
    'from' => 'demo@malmo.example.test',
    'from_name' => 'Sam Exempel',
    'to' => 'kontakt@malmo.example.test',
    'subject' => 'Internt Malmö',
    'body_text' => 'Bara vi.',
    'received_at' => '2026-10-18T08:00:00+02:00',
    'source' => 'graph-malmo',
    'graph_mailbox' => 'kontakt@malmo.example.test',
    'preset_labels' => $plain(),
]);
$malmoB = jeab_inkorg_ingest([
    'message_id' => 'malmo-energi@klass-intern.test',
    'from' => 'contact@malmo.example.test',
    'from_name' => 'Demo Energi Malmö',
    'to' => 'demo@malmo.example.test',
    'subject' => 'Re: Internt Malmö',
    'body_text' => 'Noterat.',
    'received_at' => '2026-10-18T08:30:00+02:00',
    'source' => 'graph-malmo',
    'graph_mailbox' => 'kontakt@malmo.example.test',
    'preset_labels' => $plain(),
    'in_reply_to' => 'sam-malmo@klass-intern.test',
]);
ki((string) ($malmoB['thread_id'] ?? '') === (string) ($malmoA['thread_id'] ?? '') && (string) ($malmoA['thread_id'] ?? '') !== '', 'Malmötråden har bara intern post');
$malmoRow = $thread((string) $malmoA['thread_id']);
$malmoFace = jeab_inkorg_klassning_face($malmoRow);
$malmoDecision = jeab_inkorg_klassning_decide($malmoRow, $malmoFace);
ki(jeab_inkorg_klassning_staff_address((string) ($malmoFace['from'] ?? '')) && jeab_inkorg_klassning_route($malmoFace) === 'egen' && is_array($malmoDecision) && ($malmoDecision['via'] ?? '') === 'egen', 'Sam Exempel i Malmö är egen via Malmölistan');
$dryMalmo = jeab_inkorg_klassning_reclassify(['dry' => 1, 'days' => 30, 'company' => 'malmo']);
$malmoListed = false;
foreach ((array) ($dryMalmo['rows'] ?? []) as $row) {
    if ((string) ($row['thread_id'] ?? '') === (string) $malmoA['thread_id'] && (string) ($row['route'] ?? '') === 'egen' && (string) ($row['new_type'] ?? '') === 'Oklassad' && empty($row['would_send'])) {
        $malmoListed = true;
    }
}
ki(($dryMalmo['dry'] ?? 0) === 1 && !$malmoListed && ($thread((string) $malmoA['thread_id'])['status'] ?? '') === 'open' && $GLOBALS['_SENT'] === $sentAtDry, 'dry=1 stänger inte den öppna interna Malmötråden');

$kontakt = $jarfalla;
$kontakt['from'] = 'kontakt@jarfalla.example.test';
$kontaktSaved = $in('kontakt', $kontakt);
$before = $thread((string) $kontaktSaved['thread_id']);
ki(($before['status'] ?? '') === 'open', 'kontakt@ ligger öppen före omklassning');
$wet = jeab_inkorg_klassning_reclassify(['dry' => 0, 'days' => 30]);
$after = $thread((string) $kontaktSaved['thread_id']);
ki(($wet['ok'] ?? false) === true && ($wet['sent'] ?? 1) === 0 && ($after['status'] ?? '') === 'open' && !jeab_inkorg_folder_thread($after, 'historik'), 'omklassning lämnar Demo Energi Järfälla öppen');
$moved = false;
foreach ((array) ($wet['rows'] ?? []) as $row) {
    if ((string) ($row['thread_id'] ?? '') === (string) $kontaktSaved['thread_id'] && (string) ($row['new_status'] ?? '') === 'closed') {
        $moved = true;
    }
}
ki(!$moved, 'omklassningen stänger inte kontakt@');

$exportAfter = $thread((string) $exportSaved['thread_id']);
ki(($exportAfter['status'] ?? '') === 'open' && jeab_inkorg_folder_thread($exportAfter, 'information') && !jeab_inkorg_folder_thread($exportAfter, 'inkorg'), 'omklassning lämnar Export Sales i Information');
foreach ($customerThreads as $id => $tid) {
    $still = $thread($tid);
    ki(($still['status'] ?? '') === 'open' && !jeab_inkorg_folder_thread($still, 'historik'), $id . ' är kvar öppen efter omklassning');
}
