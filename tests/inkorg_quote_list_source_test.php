<?php

declare(strict_types=1);

/**
 * inbox.threads quote_sent efter att diagnosens markör är borta.
 * Påhittade adresser på exempel.test. Inget skickas och inget live skrivs.
 */

$tmp = sys_get_temp_dir() . '/jeab-quote-list-' . bin2hex(random_bytes(4));
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

function ql(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$box = jeab_graph_mailboxes()['jarfalla'];
$battery = mb_strtolower((string) jeab_gp_owner_email('batteri', 'jarfalla'));
ql($battery !== '' && !str_contains($battery, 'exempel.test'), 'säljaren är inte testdata');

$ingest = static function (string $id, string $email, string $body, string $type = 'Support') use ($box): array {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@exempel.test',
        'from' => $email,
        'from_name' => 'Kund',
        'to' => $box,
        'subject' => 'Fråga',
        'body_text' => $body,
        'source' => 'graph-jarfalla',
        'graph_mailbox' => $box,
        'thread_key' => 'ql-' . $id,
        'preset_labels' => [
            'type' => $type, 'action' => 'svara', 'priority' => 3, 'confidence' => 90,
            'intent' => '', 'offer' => '', 'product' => '', 'question' => '',
        ],
    ]);
};
$rowOf = static function (array $res, string $tid): array {
    foreach ((array) ($res['threads'] ?? []) as $row) {
        if (is_array($row) && (string) ($row['thread_id'] ?? '') === $tid) {
            return $row;
        }
    }
    return [];
};
$listQuote = static function (string $tid) use ($rowOf): string {
    $list = jeab_inkorg_hook(['action' => 'inbox.threads', 'mailbox' => 'jarfalla', 'status' => 'all', 'limit' => 50]);
    $json = (string) json_encode($list, JSON_UNESCAPED_UNICODE);
    if (str_contains($json, '@') || str_contains($json, 'exempel.test')) {
        fwrite(STDERR, "FAIL listan läcker adress\n");
        exit(1);
    }
    return (string) ($rowOf($list, $tid)['quote_sent'] ?? '');
};

$word = $ingest('ord', 'kund.ord@exempel.test', "Hej.\nVi vill ha en offert på batteri, är den skickad?\n");
$wordTid = (string) $word['thread_id'];
jeab_inkorg_ingest([
    'message_id' => 'ord-svar@exempel.test',
    'from' => $box,
    'from_name' => 'JEAB',
    'to' => 'kund.ord@exempel.test',
    'subject' => 'Sv: Fråga',
    'body_text' => 'Tack, vi återkommer.',
    'source' => 'outbound-graph',
    'thread_key' => 'ql-ord',
    'preset_labels' => ['type' => 'Support', 'action' => 'arkivera', 'priority' => 4, 'confidence' => 80, 'intent' => '', 'offer' => '', 'product' => '', 'question' => ''],
]);
$wordThread = jeab_auto_thread_by_id($wordTid);
ql(is_array($wordThread) && jeab_inkorg_quote_was_sent($wordThread) && !jeab_inkorg_quote_sent_in_thread($wordThread), 'vanligt svar håller fortfarande utskicksspärren');
ql($listQuote($wordTid) === 'no', 'inkommande offertord plus vanligt svar är inte quote_sent i listan');

$subject = $ingest('amne', 'kund.amne@exempel.test', "Hej.\nJag vill ha hjälp.\n");
$subjectTid = (string) $subject['thread_id'];
jeab_inkorg_ingest([
    'message_id' => 'amne-svar@exempel.test',
    'from' => $battery,
    'from_name' => 'Säljare',
    'to' => 'kund.amne@exempel.test',
    'subject' => 'Angående batteriet',
    'body_text' => 'Vi tittar på det.',
    'source' => 'outbound-graph',
    'thread_key' => 'ql-amne',
    'preset_labels' => ['type' => 'Information', 'action' => 'arkivera', 'priority' => 4, 'confidence' => 80, 'intent' => '', 'offer' => '', 'product' => '', 'question' => ''],
]);
$subjectThread = jeab_auto_thread_by_id($subjectTid);
ql(is_array($subjectThread) && (jeab_inkorg_resolve_unknown_offer($subjectThread, 'kund.amne@exempel.test', 'jarfalla', false, false)['state'] ?? '') === 'known', 'produktord i utgående ämne är den gamla gissningen');
ql($listQuote($subjectTid) === 'no', 'produktord i ett utgående svar är inte en skickad offert');

$hsEmail = 'kund.hs@exempel.test';
$hsLive = $ingest('hs', $hsEmail, "Hej.\nJag vill ha hjälp.\n");
$hsTid = (string) $hsLive['thread_id'];
$importId = 'hsi-' . substr(hash('sha256', 'fiktiv-hs'), 0, 16);
$conv = [
    'id' => $importId,
    'messages' => [[
        'from' => $hsEmail,
        'to' => $box,
        'subject' => 'Fråga',
        'body' => 'Hej',
        'received_at' => date('c'),
        'hs_thread_type' => 'reply',
    ]],
];
ql(jeab_hs_import_save_conv($conv), 'lokal Help Scout-kopia går att spara');
unset($GLOBALS['_JEAB_HS_IMPORT_INDEX']);
ql(jeab_hs_import_index_append([
    'id' => $importId,
    'from' => $hsEmail,
    'snippet' => 'Hej',
    'subject' => 'Fråga',
    'message_ids' => [],
]), 'indexraden skrivs');
unset($GLOBALS['_JEAB_HS_IMPORT_INDEX']);
$hsThread = jeab_auto_thread_by_id($hsTid);
ql(is_array($hsThread) && jeab_inkorg_quote_sent_elsewhere($hsThread) && !jeab_inkorg_quote_sent_in_thread($hsThread), 'lokal Help Scout-reply träffar spärren, inte ärendets offert');
ql($listQuote($hsTid) === 'no', 'Help Scout-reply utan offert är inte quote_sent i listan');

$note = $ingest('anteckning', 'kund.anteckning@exempel.test', "Hej.\nJag vill ha hjälp.\n", 'Ny lead');
$noteTid = (string) $note['thread_id'];
jeab_inkorg_thread_write($noteTid, static function (array $t): array {
    $t['drafts'] = [[
        'id' => 'd-anteckning',
        'kind' => 'note',
        'body' => 'Offert (Batteri) skickad.',
        'state' => 'note',
        'created_at' => date('c'),
    ]];
    return $t;
});
ql($listQuote($noteTid) === 'yes', 'anteckningen Offert (Batteri) skickad är yes');

$mark = $ingest('markor', 'kund.markor@exempel.test', "Hej.\nJag vill ha hjälp.\n");
$markTid = (string) $mark['thread_id'];
jeab_inkorg_thread_write($markTid, static function (array $t): array {
    $t['quote_sent'] = ['source' => 'anteckning', 'date' => '2026-10-01 09:00', 'at' => '2026-10-01T09:00:00+02:00', 'product' => 'Batteri'];
    return $t;
});
ql($listQuote($markTid) === 'yes', 'sparad quote_sent utan diagnosform är yes');

$file = $ingest('bilaga', 'kund.bilaga@exempel.test', "Hej.\nJag vill ha hjälp.\n");
$fileTid = (string) $file['thread_id'];
jeab_inkorg_ingest([
    'message_id' => 'bilaga-svar@exempel.test',
    'from' => $box,
    'from_name' => 'JEAB',
    'to' => 'kund.bilaga@exempel.test',
    'subject' => 'Offert',
    'body_text' => "Hej.\n[Bilaga: Offert.pdf]\n",
    'source' => 'outbound-graph',
    'thread_key' => 'ql-bilaga',
    'preset_labels' => ['type' => 'Information', 'action' => 'arkivera', 'priority' => 4, 'confidence' => 80, 'intent' => '', 'offer' => '', 'product' => '', 'question' => ''],
]);
ql($listQuote($fileTid) === 'yes', 'utgående bilaga i samma ärende är yes');

$person = 'saljare@exempel.test';
$old = time() - 3600;
file_put_contents(jeab_data_dir() . '/graph-sent-scan.json', json_encode([
    $person => ['at' => $old, 'rows' => [[
        'to' => ['kund.cache@exempel.test'],
        'cc' => [],
        'sent' => gmdate('Y-m-d\TH:i:s\Z', time() - 86400),
        'subject' => 'Offert batteri',
        'has_attachments' => true,
    ]]],
], JSON_UNESCAPED_UNICODE));
ql(jeab_auto_graph_scan_cache_get($person) === null, 'Skickat-cachen från diagnoskörningen är äldre än tio minuter och läses inte');
file_put_contents(jeab_data_dir() . '/graph-sent-scan.json', json_encode([
    $battery => ['at' => time(), 'rows' => [[
        'to' => ['kund.cache@exempel.test'],
        'cc' => [],
        'sent' => gmdate('Y-m-d\TH:i:s\Z', time() - 86400),
        'subject' => 'Offert batteri',
        'has_attachments' => true,
    ]]],
], JSON_UNESCAPED_UNICODE));
unset($GLOBALS['_JEAB_GRAPH_SCAN_MEM']);
$cache = $ingest('cache', 'kund.cache@exempel.test', "Hej.\nJag vill ha hjälp.\n");
$cacheTid = (string) $cache['thread_id'];
ql(jeab_auto_graph_scan_cache_get($battery) !== null && $listQuote($cacheTid) === 'no', 'färsk Skickat-cache ändrar inte listan när deep är av');

echo "OK  quote-list-source\n";
