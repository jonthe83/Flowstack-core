<?php

declare(strict_types=1);

/** H-INK-1..6 och H-INK-10: kopia, timeout, godkänn, papperskorg, conversationId, kvittens, bilaga. */

$tmp = sys_get_temp_dir() . '/jeab-ink-bugs-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ib(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$GLOBALS['_JEAB_INKORG_LLM'] = static fn(): string => '{"type":"Information","action":"arkivera","priority":4,"confidence":99}';
jeab_graph_secret_save(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's']);
jeab_auto_settings_save([
    'auto_quote_enabled' => 0,
    'follow_receipt_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);

$labels = ['type' => 'Fråga från befintlig kund', 'action' => 'arkivera', 'priority' => 3, 'confidence' => 90, 'intent' => 'fraga'];

function ib_mail(string $id, string $from, string $subject, string $body, string $thread = ''): array
{
    global $labels;
    $row = [
        'message_id' => $id . '@ink-bugs.test',
        'from' => $from,
        'from_name' => 'Kund',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => $subject,
        'body_text' => $body,
        'received_at' => '2026-10-20T10:00:00+02:00',
        'source' => 'graph-jarfalla',
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
        'graph_id' => 'graph-' . $id,
        'preset_labels' => $labels,
    ];
    if ($thread !== '') {
        $row['thread_key'] = $thread;
    }
    return jeab_inkorg_ingest($row);
}

// H-INK-3. Godkänn markerar utkastet. Status väntar tills mejlet går.
$draftCase = ib_mail('approve', 'approve.kund@gmail.com', 'Fråga om tid', 'När kan ni komma?');
$draftTid = (string) $draftCase['thread_id'];
ib(jeab_inkorg_thread_meta($draftTid)['status'] === 'open', 'ärendet är öppet före godkännande');
$draft = jeab_inkorg_add_draft($draftTid, [
    'kind' => 'reply',
    'body' => 'Vi hörs i morgon.',
    'status_after' => 'waiting_customer',
], 'Test');
$approved = jeab_inkorg_approve($draftTid, (string) $draft['id'], 'Test');
ib(($approved['state'] ?? '') === 'approved' && ($approved['sent'] ?? true) === false, 'utkastet är godkänt och inte skickat');
ib(jeab_inkorg_thread_meta($draftTid)['status'] === 'open', 'H-INK-3 godkänn ändrar inte status');

// H-INK-4. Kundsvar i papperskorgen kommer tillbaka. Import lämnar den.
$trashCase = ib_mail('trash', 'trash.kund@gmail.com', 'Slängd tråd', 'Första mejlet.');
$trashTid = (string) $trashCase['thread_id'];
jeab_inkorg_thread_set($trashTid, 'closed', null, false);
jeab_inkorg_trash($trashTid, true, 'Test');
ib(trim((string) (jeab_inkorg_thread_meta($trashTid)['deleted_at'] ?? '')) !== '', 'ärendet ligger i papperskorgen');
$reply = ib_mail('trash-reply', 'trash.kund@gmail.com', 'Re: Slängd tråd', 'Jag svarar igen.', $trashTid);
ib((string) ($reply['thread_id'] ?? '') === $trashTid, 'svaret stannar i samma ärende');
$back = jeab_inkorg_thread_meta($trashTid);
ib(trim((string) ($back['deleted_at'] ?? '')) === '' && trim((string) ($back['deleted_by'] ?? '')) === '' && ($back['status'] ?? '') === 'open', 'H-INK-4 kundsvar lämnar papperskorgen och öppnar');
$importCase = ib_mail('import-trash', 'import.kund@gmail.com', 'Import', 'Importens mejl.');
$importTid = (string) $importCase['thread_id'];
jeab_inkorg_trash($importTid, true, 'Test');
jeab_inkorg_thread_on_message([
    'id' => 'import-msg',
    'thread_id' => $importTid,
    'source' => 'helpscout_import',
    'from' => 'import.kund@gmail.com',
    'labels' => [],
]);
ib(trim((string) (jeab_inkorg_thread_meta($importTid)['deleted_at'] ?? '')) !== '', 'Help Scout-import lämnar papperskorgen');

// H-INK-5. Dubblett binder conversationId. Nytt mejl följer den, inte ett nytt g-ärende.
$dup = ib_mail('dup-bind', 'dup.bind@gmail.com', 'Unik rubrik dup', 'Samma brödtext för dubblett.');
$dupTid = (string) $dup['thread_id'];
jeab_graph_state_update(static function (array &$d): bool {
    $d['conv'] = [];
    return true;
});
$dupRes = jeab_graph_ingest_message('jarfalla', [
    'id' => 'DUP1',
    'internetMessageId' => '<dup-bind@ink-bugs.test>',
    'conversationId' => 'C-DUP',
    'from' => ['emailAddress' => ['address' => 'dup.bind@gmail.com', 'name' => 'Kund']],
    'toRecipients' => [['emailAddress' => ['address' => 'kontakt@jarfalla.example.test']]],
    'subject' => 'Unik rubrik dup',
    'receivedDateTime' => '2026-10-20T10:00:00Z',
    'body' => ['content' => 'Samma brödtext för dubblett.'],
], false);
ib(!empty($dupRes['duplicate']) && (string) ($dupRes['thread_id'] ?? '') === $dupTid, 'dubbletten pekar på det sparade ärendet');
ib((jeab_graph_state()['conv']['C-DUP'] ?? '') === $dupTid, 'H-INK-5 dubblett binder conversationId');
$later = jeab_graph_ingest_message('jarfalla', [
    'id' => 'DUP2',
    'internetMessageId' => '<dup-later@ink-bugs.test>',
    'conversationId' => 'C-DUP',
    'from' => ['emailAddress' => ['address' => 'dup.bind@gmail.com', 'name' => 'Kund']],
    'toRecipients' => [['emailAddress' => ['address' => 'kontakt@jarfalla.example.test']]],
    'subject' => 'Unik rubrik dup',
    'receivedDateTime' => '2026-10-21T10:00:00Z',
    'body' => ['content' => 'Ett senare svar i samma konversation.'],
], false);
ib((string) ($later['thread_id'] ?? '') === $dupTid && empty($later['duplicate']), 'senare mejl hamnar i det bundna ärendet');

$formFirst = ib_mail('form-open', 'ada.formbug@gmail.com', 'Fråga om laddbox', 'Hej, jag heter Ada.');
$formTid = (string) $formFirst['thread_id'];
$formBody = "A site visitor just submitted your form: JEAB Kontakt Formulär\n\nNamn:\n\nAda Form\n\nE-post:\n\nada.formbug@gmail.com\n\nTelefon:\n\n070-100 00 10\n\nMeddelande:\n\nVi vill ha offert på batteri.\n";
$formIn = jeab_graph_ingest_message('jarfalla', [
    'id' => 'FORM1',
    'internetMessageId' => '<form1-ink-bugs@wix>',
    'conversationId' => 'C-FORM',
    'from' => ['emailAddress' => ['address' => 'no-reply@crm.wix.com', 'name' => 'Formulär JEAB']],
    'toRecipients' => [['emailAddress' => ['address' => 'kontakt@jarfalla.example.test']]],
    'subject' => 'JEAB Kontakt Formulär ink-bugs',
    'receivedDateTime' => '2026-10-22T10:00:00Z',
    'body' => ['content' => $formBody],
], false);
ib((string) ($formIn['thread_id'] ?? '') === $formTid, 'formulärkuvertet läggs på kundens öppna ärende');
ib((jeab_graph_state()['conv']['C-FORM'] ?? '') === $formTid, 'H-INK-5 kartan följer ärendet formuläret flyttades till');
jeab_graph_state_update(static function (array &$d): bool {
    $d['conv']['C-KEEP'] = 'redan-satt';
    return true;
});
jeab_graph_ingest_message('jarfalla', [
    'id' => 'DUP1',
    'internetMessageId' => '<dup-bind@ink-bugs.test>',
    'conversationId' => 'C-KEEP',
    'from' => ['emailAddress' => ['address' => 'dup.bind@gmail.com']],
    'subject' => 'Unik rubrik dup',
    'receivedDateTime' => '2026-10-20T10:00:00Z',
    'body' => ['content' => 'Samma brödtext för dubblett.'],
], false);
ib((jeab_graph_state()['conv']['C-KEEP'] ?? '') === 'redan-satt', 'en satt conversationId skrivs inte över av dubbletten');

// H-INK-1. Manuellt svar skickar kompositörens kopia, även när den är tom.
$ccCalls = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url, ?array $body = null) use (&$ccCalls): array {
    if ($method === 'POST' && str_contains($url, '/reply')) {
        $ccCalls[] = $body;
        return ['code' => 202, 'body' => []];
    }
    return ['code' => 200, 'body' => ['value' => []]];
};
$ccCase = ib_mail('cc', 'cc.kund@gmail.com', 'Kopia', 'Mejl som kom med kopia.');
$ccTid = (string) $ccCase['thread_id'];
jeab_inkorg_send_in_thread($ccTid, ['body' => 'Bara till dig.', 'cc' => '', 'status_after' => 'open'], 'Test');
$emptyCc = $ccCalls[count($ccCalls) - 1]['message'] ?? [];
ib(array_key_exists('ccRecipients', $emptyCc) && ($emptyCc['ccRecipients'] ?? null) === [], 'H-INK-1 tom kopia skickas som tom lista');
jeab_inkorg_send_in_thread($ccTid, ['body' => 'Med en kopia.', 'cc' => 'kopia.kund@gmail.com', 'status_after' => 'open'], 'Test');
$withCc = $ccCalls[count($ccCalls) - 1]['message']['ccRecipients'] ?? null;
ib(is_array($withCc) && ($withCc[0]['emailAddress']['address'] ?? '') === 'kopia.kund@gmail.com', 'angiven kopia följer med');

// H-INK-2. Timeout på reply blir inte ett andra sendMail. 4xx får falla tillbaka.
$urls = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url, ?array $body = null) use (&$urls): array {
    unset($body);
    if ($method === 'POST') {
        $urls[] = $url;
    }
    if (str_contains($url, '/reply')) {
        return ['code' => 0, 'body' => [], 'transport' => true, 'curl_errno' => 28];
    }
    if (str_contains($url, '/sendMail')) {
        return ['code' => 202, 'body' => []];
    }
    return ['code' => 200, 'body' => ['value' => []]];
};
$lost = jeab_outbound_graph_send('jarfalla', 'timeout.kund@gmail.com', 'Hej', 'Text timeout', false, [], [], [
    'reply_to_graph_id' => 'G-LOST',
    'guard_mode' => 'manual',
    'guard_template' => 'svar-timeout',
]);
ib(empty($lost['ok']) && !empty($lost['transport']) && count($urls) === 1 && str_contains($urls[0], '/reply'), 'H-INK-2 timeout skickar inte en andra kopia');
$urls = [];
$sentRef = '';
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url, ?array $body = null) use (&$urls, &$sentRef): array {
    if ($method === 'POST') {
        $urls[] = $url;
    }
    if (str_contains($url, '/reply')) {
        return ['code' => 400, 'body' => []];
    }
    if (str_contains($url, '/sendMail')) {
        foreach ((array) ($body['message']['internetMessageHeaders'] ?? []) as $h) {
            if (is_array($h) && strcasecmp((string) ($h['name'] ?? ''), 'X-JEAB-Ref') === 0) {
                $sentRef = (string) ($h['value'] ?? '');
            }
        }
        return ['code' => 202, 'body' => []];
    }
    if (str_contains($url, 'sentitems') && $sentRef !== '') {
        return ['code' => 200, 'body' => ['value' => [[
            'id' => 'sent-400',
            'internetMessageHeaders' => [['name' => 'X-JEAB-Ref', 'value' => $sentRef]],
        ]]]];
    }
    return ['code' => 200, 'body' => []];
};
$fallback = jeab_outbound_graph_send('jarfalla', 'fallback.kund@gmail.com', 'Hej', 'Text fyra hundra', false, [], [], [
    'reply_to_graph_id' => 'G-400',
    'guard_mode' => 'manual',
    'guard_template' => 'svar-400',
]);
ib(!empty($fallback['ok']) && count($urls) === 2 && str_contains($urls[0], '/reply') && str_contains($urls[1], '/sendMail'), 'tydligt 4xx får skicka som nytt mejl');
$urls = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url, ?array $body = null) use (&$urls): array {
    unset($body);
    if ($method === 'POST') {
        $urls[] = $url;
    }
    if (str_contains($url, '/reply')) {
        return ['code' => 0, 'body' => [], 'transport' => true, 'curl_errno' => 28];
    }
    return ['code' => 200, 'body' => ['value' => []]];
};
$GLOBALS['_JEAB_GP_FLAGS'] = ['graph_person_mail' => true];
$gpLost = jeab_gp_send('saljare4@jarfalla.example.test', 'timeout.kund@gmail.com', 'Hej', 'Text person', [], [], [
    'reply_to_graph_id' => 'G-PERSON',
    'guard_mode' => 'manual',
    'guard_template' => 'svar-person',
]);
ib(empty($gpLost['ok']) && !empty($gpLost['transport']) && count($urls) === 1 && str_contains($urls[0], '/reply'), 'personbrevlådans timeout skickar inte en andra kopia');
unset($GLOBALS['_JEAB_GP_FLAGS']);

// H-INK-10. Graph-id vinner. Två filer med samma namn hämtas inte.
$got = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url) use (&$got): array {
    $got[] = $url;
    if (str_contains($url, '/attachments?')) {
        return ['code' => 200, 'body' => ['value' => [
            ['id' => 'att-a', 'name' => 'Offert.PDF', 'size' => 4, 'contentType' => 'application/pdf'],
            ['id' => 'att-b', 'name' => 'offert.pdf', 'size' => 4, 'contentType' => 'application/pdf'],
        ]]];
    }
    if (str_contains($url, '/attachments/att-b')) {
        return ['code' => 200, 'body' => ['contentBytes' => base64_encode('BYTE-B')]];
    }
    if (str_contains($url, '/attachments/att-a')) {
        return ['code' => 200, 'body' => ['contentBytes' => base64_encode('BYTE-A')]];
    }
    return ['code' => 404, 'body' => []];
};
$msg = ['company' => 'jarfalla', 'graph_id' => 'GM-ATT'];
$ambiguous = jeab_hs_import_graph_bytes($msg, ['name' => 'offert.pdf', 'company' => 'jarfalla']);
ib($ambiguous === null && !array_filter($got, static fn(string $u): bool => str_contains($u, '/attachments/att-')), 'H-INK-10 samma filnamn två gånger hämtas inte');
$byId = jeab_hs_import_graph_bytes($msg, [
    'name' => 'annat.pdf',
    'company' => 'jarfalla',
    'graph_attachment_id' => 'att-b',
]);
ib($byId === 'BYTE-B', 'sparat Graph-id väljer rätt bilaga');
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url): array {
    unset($method);
    if (str_contains($url, '/attachments?')) {
        return ['code' => 200, 'body' => ['value' => [
            ['id' => 'att-one', 'name' => 'tak.jpg', 'size' => 3, 'contentType' => 'image/jpeg'],
        ]]];
    }
    if (str_contains($url, '/attachments/att-one')) {
        return ['code' => 200, 'body' => ['contentBytes' => base64_encode('TAK')]];
    }
    return ['code' => 404, 'body' => []];
};
ib(jeab_hs_import_graph_bytes($msg, ['name' => 'TAK.JPG', 'company' => 'jarfalla']) === 'TAK', 'ett unikt namn hämtas som förut');

// H-INK-6. Kravraden stoppar en andra kvittens. Misslyckat utskick släpper kravet.
unset($GLOBALS['_JEAB_GRAPH_HTTP']);
$receiptCase = ib_mail('receipt', 'receipt.kund@gmail.com', 'Ring mig', 'Kan ni ringa?');
$receiptTid = (string) $receiptCase['thread_id'];
$receiptMsg = [
    'id' => (string) $receiptCase['id'],
    'from' => 'receipt.kund@gmail.com',
    'subject' => 'Ring mig',
    'graph_id' => 'graph-receipt',
];
$owner = ['name' => 'Elin', 'email' => 'saljare4@jarfalla.example.test', 'company' => 'jarfalla', 'id' => 'Elin'];
$thread = ['id' => $receiptTid];
$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_SENT'][] = $a;
    if (!empty($GLOBALS['_RECEIPT_FAIL'])) {
        return ['ok' => false, 'error' => 'graph 0', 'transport' => true];
    }
    return ['ok' => true, 'from' => 'saljare4@jarfalla.example.test', 'graph_id' => 'g-receipt'];
};
$GLOBALS['_RECEIPT_FAIL'] = true;
ib(jeab_follow_send_receipt($thread, $receiptMsg, [], $owner) === false && count($GLOBALS['_SENT']) === 1, 'misslyckad kvittens skickas inte');
ib(jeab_follow_receipt_allowed($receiptTid, (string) $receiptMsg['id']), 'misslyckad kvittens lämnar inget stämpel');
$GLOBALS['_RECEIPT_FAIL'] = false;
ib(jeab_follow_send_receipt($thread, $receiptMsg, [], $owner) === true && count($GLOBALS['_SENT']) === 2, 'ny försök efter släppt krav skickar');
jeab_inkorg_thread_write($receiptTid, static function (array $t): array {
    unset($t['follow_receipt_msgs'], $t['follow_receipt_at']);
    return $t;
});
ib(jeab_follow_receipt_allowed($receiptTid, (string) $receiptMsg['id']), 'stämpeln är borta');
ib(jeab_follow_send_receipt($thread, $receiptMsg, [], $owner) === false && count($GLOBALS['_SENT']) === 2, 'H-INK-6 kravraden stoppar en andra kvittens');

echo "done\n";
