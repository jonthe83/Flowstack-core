<?php

declare(strict_types=1);

/**
 * Riktad Help Scout-import via inbox.import_helpscout ids och inbox.find_by_hs.
 * Fejkad HTTP och fejkad modell. Ingen riktig nyckel och inget utskick.
 */

$tmp = sys_get_temp_dir() . '/jeab-hsi-ids-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
file_put_contents($tmp . '/helpscout.json', json_encode([
    'client_id' => 'hs-test-client',
    'client_secret' => 'hs-test-secret',
    'mailbox_id' => 111,
    'mailbox_id_malmo' => 222,
    'import_per_minute' => 200,
], JSON_UNESCAPED_UNICODE));
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/layout.php';
jeab_ordrar_external_live(false);

function ids_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function ids_json(int $code, array $body): array
{
    return ['code' => $code, 'headers' => [], 'body' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
}

$conv = static function (int $id, int $number, string $subject): array {
    return [
        'id' => $id,
        'number' => $number,
        'mailboxId' => 111,
        'status' => 'active',
        'subject' => $subject,
        'primaryCustomer' => ['id' => 50, 'email' => 'kund@example.com', 'first' => 'Kim', 'last' => 'Alm'],
    ];
};
$conversations = [
    1001 => $conv(1001, 5010, 'Batterifråga'),
    3003 => $conv(3003, 88001, 'Batterifråga till'),
];
$threadRow = static function (int $convId, int $threadId): array {
    return [
        'id' => $threadId,
        'type' => 'customer',
        'createdAt' => '2024-05-01T10:00:00Z',
        'body' => '<p>Vi vill ha batteri.</p>',
        'createdBy' => ['first' => 'Kim', 'last' => 'Alm', 'email' => 'kund@example.com'],
        'customer' => ['id' => 50, 'email' => 'kund@example.com', 'first' => 'Kim', 'last' => 'Alm'],
    ];
};
$threads = [
    1001 => [$threadRow(1001, 501)],
    3003 => [$threadRow(3003, 801)],
];
$customer = [
    'id' => 50,
    'firstName' => 'Kim',
    'lastName' => 'Alm',
    'email' => 'kund@example.com',
    '_embedded' => ['emails' => [['value' => 'kund@example.com']]],
];
$http = 0;
$GLOBALS['_JEAB_HS_IMPORT_SLEEP'] = static function (float $seconds): void {
    unset($seconds);
};
$GLOBALS['_JEAB_HS_IMPORT_HTTP'] = static function (string $method, string $url) use (&$http, $conversations, $threads, $customer): array {
    if ($method !== 'GET') {
        return ids_json(405, ['error' => 'write']);
    }
    $http++;
    $parts = parse_url($url);
    $path = (string) ($parts['path'] ?? '');
    $q = [];
    parse_str((string) ($parts['query'] ?? ''), $q);
    if (preg_match('#/v2/conversations/(\d+)/threads$#', $path, $m) === 1) {
        $id = (int) $m[1];
        return ids_json(200, ['_embedded' => ['threads' => $threads[$id] ?? []], 'page' => ['totalPages' => 1]]);
    }
    if (preg_match('#/v2/customers/(\d+)$#', $path, $m) === 1) {
        return (int) $m[1] === 50 ? ids_json(200, $customer) : ids_json(404, []);
    }
    if (preg_match('#/v2/conversations/(\d+)$#', $path, $m) === 1) {
        $id = (int) $m[1];
        return isset($conversations[$id]) ? ids_json(200, $conversations[$id]) : ids_json(404, []);
    }
    if ($path === '/v2/conversations') {
        $query = (string) ($q['query'] ?? '');
        $rows = [];
        if (preg_match('/number:(\d+)/', $query, $m) === 1) {
            foreach ($conversations as $row) {
                if ((string) ($row['number'] ?? '') === $m[1]) {
                    $rows[] = $row;
                }
            }
        }
        return ids_json(200, ['_embedded' => ['conversations' => $rows], 'page' => ['totalPages' => 1]]);
    }
    return ids_json(404, []);
};

$llm = 0;
$GLOBALS['_JEAB_INKORG_LLM'] = static function (array $messages) use (&$llm): string {
    unset($messages);
    $llm++;
    return json_encode([
        'type' => 'Ny lead',
        'confidence' => 96,
        'product' => 'batteri',
        'tags' => [],
        'intent' => 'kop',
        'offer' => 'batteri',
        'lead' => 'kopt',
        'question' => '',
        'reason' => 'batteri',
        'is_lead_seller' => true,
        'seller_name' => 'Exempel Salj',
        'customer_email' => 'kund@example.com',
        'customer_name' => 'Kund Exempel',
        'case_intent' => 'kopintresse',
        'primary_product' => 'Batteri',
        'secondary_products' => [],
        'kind' => 'atgard',
        'label' => '',
        'field_confidence' => [
            'is_lead_seller' => 0.95,
            'seller_name' => 0.95,
            'customer_email' => 0.95,
            'customer_name' => 0.95,
            'intent' => 0.95,
            'primary_product' => 0.95,
            'kind' => 0.95,
            'type' => 0.95,
            'label' => 0.95,
        ],
    ], JSON_UNESCAPED_UNICODE);
};
$mail = 0;
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use (&$mail): array {
    unset($opts);
    $mail++;
    return ['ok' => true, 'path' => 'test', 'id' => 'mail-1'];
};

file_put_contents(jeab_inkorg_dir() . '/settings.json', json_encode([
    'grok_decides' => 1,
    'auto_quote_enabled' => true,
], JSON_UNESCAPED_UNICODE));
file_put_contents(jeab_inkorg_dir() . '/threads.json', json_encode([
    'threads' => [
        'hs-2002' => [
            'status' => 'open',
            'created_at' => '2024-01-01T00:00:00+00:00',
            'hs' => ['id' => '2002', 'number' => 77, 'status' => 'active'],
        ],
    ],
], JSON_UNESCAPED_UNICODE));
$cpPath = jeab_inkorg_dir() . '/helpscout-import.json';
$cpBody = '{"mailboxes":{"jarfalla":{"watermark":"2020-01-01T00:00:00Z","page":3}}}';
file_put_contents($cpPath, $cpBody);
$cpBefore = (string) file_get_contents($cpPath);

$dry = jeab_inkorg_hook([
    'action' => 'inbox.import_helpscout',
    'ids' => [1001, '9999', 'hs-2002'],
    'disk_min_bytes' => 0,
    'disk_min_ratio' => 0,
]);
$tid1001 = jeab_hs_import_thread_key('1001');
ids_ok(!empty($dry['ok']) && !empty($dry['dry']), 'torrkörning är standard');
ids_ok(($dry['items']['1001']['status'] ?? '') === 'would_import' && ($dry['items']['1001']['thread_id'] ?? '') === 'hs-1001' && ($dry['items']['1001']['hsi_id'] ?? '') === $tid1001, 'torrkörning pekar ut den levande tråden utan att skriva');
ids_ok(($dry['items']['9999']['status'] ?? '') === 'not_found' && ($dry['items']['9999']['thread_id'] ?? '') === '', 'okänt id blir not_found');
ids_ok(($dry['items']['hs-2002']['status'] ?? '') === 'already_present' && ($dry['items']['hs-2002']['thread_id'] ?? '') === 'hs-2002', 'hs-id som redan finns hoppas över');
ids_ok($llm === 1, 'klassning körs i torrkörning');
$dryPath = jeab_hs_import_conv_path($tid1001);
ids_ok($dryPath === null || !is_file($dryPath), 'torrkörning skapar ingen trådfil');
ids_ok((string) file_get_contents($cpPath) === $cpBefore, 'torrkörning lämnar checkpointen');
ids_ok($mail === 0, 'torrkörning skickar inget mejl');
ids_ok(($dry['items']['1001']['would_send'] ?? null) === false && ($dry['items']['9999']['would_send'] ?? null) === false && ($dry['items']['hs-2002']['would_send'] ?? null) === false, 'torrkörning säger would_send false på varje id');
ids_ok(($dry['limit'] ?? 0) === 5 && ($dry['pending'] ?? null) === [], 'standardgränsen är 5 och inget blir kvar');

$write = jeab_inkorg_hook([
    'action' => 'inbox.import_helpscout',
    'ids' => '1001,88001',
    'dry' => 0,
    'disk_min_bytes' => 0,
    'disk_min_ratio' => 0,
]);
$tid3003 = jeab_hs_import_thread_key('3003');
ids_ok(!empty($write['ok']) && empty($write['dry']), 'dry=0 skriver');
ids_ok(($write['items']['1001']['status'] ?? '') === 'imported' && ($write['items']['1001']['thread_id'] ?? '') === 'hs-1001' && ($write['items']['1001']['hsi_id'] ?? '') === $tid1001, 'id importeras som levande hs-tråd');
ids_ok(($write['items']['88001']['status'] ?? '') === 'imported' && ($write['items']['88001']['thread_id'] ?? '') === 'hs-3003' && ($write['items']['88001']['hs_id'] ?? '') === '3003', 'ärendenummer importeras till den levande tråden');
$listed = jeab_inkorg_hook(['action' => 'inbox.threads', 'status' => 'all', 'limit' => 50]);
$listedIds = [];
foreach ((array) ($listed['threads'] ?? []) as $row) {
    if (is_array($row)) {
        $listedIds[] = (string) ($row['thread_id'] ?? '');
    }
}
ids_ok(in_array('hs-1001', $listedIds, true) && in_array('hs-3003', $listedIds, true), 'inbox.threads ser de levande trådarna');
$closed = jeab_inkorg_hook(['action' => 'inbox.close', 'thread_id' => 'hs-1001', 'dry' => 1]);
ids_ok(($closed['error'] ?? '') !== 'not_found' && ($closed['thread_id'] ?? '') === 'hs-1001', 'inbox.close hittar den levande tråden');
ids_ok($llm === 3, 'klassning körs vid skrivning');
ids_ok((string) file_get_contents($cpPath) === $cpBefore, 'skrivning lämnar checkpoint och watermark');
ids_ok($mail === 0, 'skrivning skickar inget mejl');

$stored = jeab_hs_import_read_conv($tid1001);
$labels = [];
$source = '';
$noAuto = false;
if (is_array($stored)) {
    foreach ((array) ($stored['messages'] ?? []) as $message) {
        if (!is_array($message)) {
            continue;
        }
        $source = (string) ($message['source'] ?? '');
        $noAuto = !empty($message['no_auto']);
        $labels = (array) ($message['labels'] ?? []);
        break;
    }
}
$case = (array) ($labels['case_class'] ?? []);
ids_ok($source === 'helpscout_import' && $noAuto && !empty($stored['meta']['no_auto']), 'importmärkning och no_auto ligger kvar');
ids_ok((string) ($labels['source'] ?? '') === 'llm' && (string) ($case['primary_product'] ?? '') === 'Batteri' && !empty($case['is_lead_seller']) && (string) ($labels['type'] ?? '') === 'Ny lead', 'Grok sätter produkt, säljare och kategori');

$again = jeab_inkorg_hook(['action' => 'inbox.import_helpscout', 'ids' => [1001, 88001]]);
ids_ok(($again['items']['1001']['status'] ?? '') === 'already_present' && ($again['items']['1001']['thread_id'] ?? '') === 'hs-1001', 'indexet hoppas över');
ids_ok(($again['items']['88001']['status'] ?? '') === 'already_present' && ($again['items']['88001']['thread_id'] ?? '') === 'hs-3003', 'samma ärendenummer hoppas över');
ids_ok($llm === 3, 'en träff som redan finns klassas inte om');
ids_ok((string) file_get_contents($cpPath) === $cpBefore, 'checkpointen är orörd efter omtag');

$manualTid = jeab_hs_import_thread_key('4004');
$manual = [
    'id' => $manualTid,
    'company' => 'jarfalla',
    'status' => 'closed',
    'import_source' => 'helpscout_import',
    'no_auto' => true,
    'hs_ids' => ['conversation' => '4004', 'number' => '1212', 'mailbox' => '111', 'customer' => ''],
    'messages' => [[
        'id' => 'aabbccddeeff0011',
        'message_id' => 'hs-4004-1@helpscout.net',
        'thread_id' => $manualTid,
        'from' => 'kund@example.com',
        'subject' => 'Sparad',
        'body' => 'Hej',
        'source' => 'helpscout_import',
        'no_auto' => true,
        'received_at' => '2024-01-01T00:00:00+00:00',
        'labels' => ['type' => 'Information', 'source' => 'import'],
        'hs_thread_type' => 'customer',
    ]],
    'meta' => [
        'status' => 'closed',
        'import_source' => 'helpscout_import',
        'no_auto' => true,
        'hs_ids' => ['conversation' => '4004', 'number' => '1212'],
    ],
];
ids_ok(jeab_hs_import_save_conv($manual), 'äldre ärende kan sparas');
ids_ok(jeab_hs_import_index_append([
    'id' => $manualTid,
    'company' => 'jarfalla',
    'status' => 'closed',
    'subject' => 'Sparad',
    'import_source' => 'helpscout_import',
    'message_ids' => ['aabbccddeeff0011'],
    'message_count' => 1,
]), 'indexrad utan hs-fält räknas ändå');

$found = jeab_inkorg_hook([
    'action' => 'inbox.find_by_hs',
    'ids' => [1001, '88001', 77, '2002', '1212', '4004', '4040'],
]);
$threads = (array) ($found['threads'] ?? []);
ids_ok(!empty($found['ok']) && ($threads['1001']['thread_id'] ?? '') === 'hs-1001' && ($threads['1001']['kind'] ?? '') === 'hs' && in_array($tid1001, (array) ($threads['1001']['thread_ids'] ?? []), true), 'find_by_hs hittar den levande tråden och arkivkopian');
ids_ok(($threads['88001']['thread_id'] ?? '') === 'hs-3003' && ($threads['88001']['kind'] ?? '') === 'hs', 'find_by_hs hittar ärendenummer via den levande tråden');
ids_ok(($threads['77']['thread_id'] ?? '') === 'hs-2002' && ($threads['2002']['thread_id'] ?? '') === 'hs-2002', 'find_by_hs hittar hs-id och sparad metadata');
ids_ok(($threads['1212']['thread_id'] ?? '') === $manualTid && ($threads['4004']['thread_id'] ?? '') === $manualTid, 'find_by_hs läser sparad hs-metadata');
ids_ok(($threads['4040']['thread_id'] ?? '') === '' && ($threads['4040']['thread_ids'] ?? null) === [], 'find_by_hs lämnar en miss tom');
ids_ok((string) file_get_contents($cpPath) === $cpBefore, 'uppslagning skriver inte checkpointen');

$msgId = '';
if (is_array($stored)) {
    $msgId = (string) (($stored['messages'][0]['id'] ?? ''));
}
$auto = $msgId !== '' ? jeab_auto_on_message($msgId) : ['action' => 'ran'];
$plain = [
    'id' => 'local-1',
    'from' => 'kund@example.com',
    'subject' => 'Batteri',
    'body' => 'Vi vill ha batteri.',
    'source' => 'graph-jarfalla',
    'no_auto' => true,
    'labels' => ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'batteri', 'confidence' => 99, 'source' => 'llm'],
];
$quote = jeab_auto_quote([
    'id' => 'local-1',
    'status' => 'open',
    'no_auto' => true,
    'company' => 'jarfalla',
    'messages' => [$plain],
    'first' => $plain,
], $plain, ['quote_enabled' => true, 'min_confidence' => 0, 'only_from' => [], 'trash_spam' => false], [], ['automatic' => true]);
$follow = jeab_follow_try(['id' => 'local-1', 'no_auto' => true, 'status' => 'open', 'messages' => [$plain]], $plain);
$outbound = 0;
foreach (jeab_inkorg_messages() as $message) {
    if (str_starts_with((string) ($message['source'] ?? ''), 'outbound-')) {
        $outbound++;
    }
}
ids_ok(($auto['action'] ?? '') === 'skip' && ($quote['action'] ?? '') === 'skip' && $follow === null, 'automatik, offert och kvittens är av');
ids_ok($mail === 0 && $outbound === 0, 'inget utgående mejl skickas');

$graph = 0;
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $opts) use (&$graph): array {
    unset($opts);
    $graph++;
    return ['ok' => true, 'id' => 'graph-1'];
};

$hsiTid = jeab_hs_import_thread_key('5555');
$archive = [
    'id' => $hsiTid,
    'company' => 'jarfalla',
    'status' => 'closed',
    'import_source' => 'helpscout_import',
    'no_auto' => true,
    'hs_ids' => ['conversation' => '5555', 'number' => '9090', 'mailbox' => '111', 'customer' => ''],
    'messages' => [[
        'id' => '1122334455667788',
        'message_id' => 'hs-5555-1@helpscout.net',
        'thread_id' => $hsiTid,
        'from' => 'kund@example.com',
        'from_name' => 'Kim Alm',
        'subject' => 'Arkiv batteri',
        'body' => 'Vi vill ha batteri till villan.',
        'source' => 'helpscout_import',
        'no_auto' => true,
        'received_at' => '2024-06-01T00:00:00+00:00',
        'labels' => ['type' => 'Ny lead', 'source' => 'import'],
        'hs_thread_type' => 'customer',
        'hs_conversation_id' => '5555',
    ]],
    'meta' => [
        'status' => 'closed',
        'import_source' => 'helpscout_import',
        'no_auto' => true,
        'hs_ids' => ['conversation' => '5555', 'number' => '9090'],
    ],
];
ids_ok(jeab_hs_import_save_conv($archive), 'arkivkopia kan sparas');
ids_ok(jeab_hs_import_index_append([
    'id' => $hsiTid,
    'company' => 'jarfalla',
    'status' => 'closed',
    'subject' => 'Arkiv batteri',
    'import_source' => 'helpscout_import',
    'message_ids' => ['1122334455667788'],
    'message_count' => 1,
    'hs_conversation_id' => '',
    'hs_number' => '',
]), 'indexrad med tomma hs-fält');

$foundHsi = jeab_inkorg_hook(['action' => 'inbox.find_by_hs', 'ids' => ['5555', '9090']]);
$hsiThreads = (array) ($foundHsi['threads'] ?? []);
ids_ok(($hsiThreads['5555']['thread_id'] ?? '') === $hsiTid && ($hsiThreads['5555']['kind'] ?? '') === 'hsi', 'find_by_hs hittar hsi-kopia på id');
ids_ok(($hsiThreads['9090']['thread_id'] ?? '') === $hsiTid && ($hsiThreads['9090']['kind'] ?? '') === 'hsi', 'find_by_hs hittar hsi-kopia på ärendenummer');

$llmBefore = $llm;
$mailBefore = $mail;
$dryPromote = jeab_inkorg_hook([
    'action' => 'inbox.import_helpscout',
    'ids' => ['9090'],
    'promote' => 1,
    'dry' => 1,
    'disk_min_bytes' => 0,
    'disk_min_ratio' => 0,
]);
ids_ok(($dryPromote['items']['9090']['status'] ?? '') === 'would_promote' && ($dryPromote['items']['9090']['thread_id'] ?? '') === 'hs-5555' && ($dryPromote['items']['9090']['would_send'] ?? null) === false, 'torrkörning av promote skriver would_send false');
ids_ok(!isset(jeab_inkorg_threads_meta()['hs-5555']), 'torrkörning skapar ingen levande tråd');
ids_ok($llm === $llmBefore && $mail === $mailBefore && $graph === 0, 'torrkörning av promote klassar och skickar inget');

$livePromote = jeab_inkorg_hook([
    'action' => 'inbox.promote_helpscout',
    'ids' => '5555',
    'dry' => 0,
    'disk_min_bytes' => 0,
    'disk_min_ratio' => 0,
]);
$liveMeta = jeab_inkorg_threads_meta()['hs-5555'] ?? [];
$liveMsgs = [];
foreach (jeab_inkorg_messages() as $message) {
    if ((string) ($message['thread_id'] ?? '') === 'hs-5555') {
        $liveMsgs[] = $message;
    }
}
$liveMsg = $liveMsgs[0] ?? [];
$linked = jeab_hs_import_read_conv($hsiTid);
ids_ok(!empty($livePromote['ok']) && ($livePromote['items']['5555']['status'] ?? '') === 'promoted' && ($livePromote['items']['5555']['thread_id'] ?? '') === 'hs-5555' && ($livePromote['items']['5555']['would_send'] ?? null) === false, 'promote skapar hs-tråden');
ids_ok((string) ($liveMeta['status'] ?? '') === 'open' && (string) ($liveMeta['import_source'] ?? '') === 'helpscout_import' && !empty($liveMeta['no_auto']) && !empty($liveMeta['helpscout_import']) && (string) ($liveMeta['hsi_id'] ?? '') === $hsiTid, 'tråden är öppen, märkt och länkad');
ids_ok((string) ($liveMeta['hs']['id'] ?? '') === '5555' && (string) ($liveMeta['hs']['number'] ?? '') === '9090', 'tråden bär Help Scout-id och nummer');
ids_ok(is_array($linked) && (string) ($linked['meta']['promoted_thread'] ?? '') === 'hs-5555', 'arkivkopian pekar tillbaka');
ids_ok(count($liveMsgs) === 1 && (string) ($liveMsg['source'] ?? '') === 'helpscout_import' && !empty($liveMsg['no_auto']) && (string) ($liveMsg['labels']['type'] ?? '') === 'Ny lead', 'vanlig klassning utan utskick');
$promotedAuto = jeab_auto_on_message((string) ($liveMsg['id'] ?? ''));
$promotedThread = null;
foreach (jeab_inkorg_threads() as $thread) {
    if ((string) ($thread['id'] ?? '') === 'hs-5555') {
        $promotedThread = $thread;
    }
}
$promotedFollow = is_array($promotedThread) ? jeab_follow_try($promotedThread, $liveMsg) : ['ran' => true];
$promotedQuote = is_array($promotedThread) ? jeab_auto_quote($promotedThread, $liveMsg, ['quote_enabled' => true, 'min_confidence' => 0, 'only_from' => [], 'trash_spam' => false], [], ['automatic' => true]) : ['action' => 'ran'];
$outbound = 0;
foreach (jeab_inkorg_messages() as $message) {
    if (str_starts_with((string) ($message['source'] ?? ''), 'outbound-')) {
        $outbound++;
    }
}
ids_ok(($promotedAuto['action'] ?? '') === 'skip' && ($promotedQuote['action'] ?? '') === 'skip' && $promotedFollow === null, 'promotad tråd ger ingen offert eller kvittens');
ids_ok($mail === $mailBefore && $graph === 0 && $outbound === 0, 'promote skickar inget mejl');
ids_ok((string) file_get_contents($cpPath) === $cpBefore, 'promote lämnar checkpointen');

$againPromote = jeab_inkorg_hook([
    'action' => 'inbox.import_helpscout',
    'ids' => ['9090'],
    'promote' => 'yes',
    'dry' => 0,
    'disk_min_bytes' => 0,
    'disk_min_ratio' => 0,
]);
$againMsgs = 0;
foreach (jeab_inkorg_messages() as $message) {
    if ((string) ($message['thread_id'] ?? '') === 'hs-5555') {
        $againMsgs++;
    }
}
ids_ok(($againPromote['items']['9090']['status'] ?? '') === 'already_present' && ($againPromote['items']['9090']['thread_id'] ?? '') === 'hs-5555' && $againMsgs === 1, 'samma promote igen skapar ingen kopia');
ids_ok($mail === $mailBefore && $graph === 0, 'andra körningen skickar heller inget');

$dupLive = jeab_inkorg_hook([
    'action' => 'inbox.import_helpscout',
    'ids' => ['2002'],
    'promote' => 1,
    'dry' => 0,
    'disk_min_bytes' => 0,
    'disk_min_ratio' => 0,
]);
ids_ok(($dupLive['items']['2002']['status'] ?? '') === 'already_present' && ($dupLive['items']['2002']['thread_id'] ?? '') === 'hs-2002', 'befintlig hs-tråd dupliceras inte');

$chunk = jeab_inkorg_hook([
    'action' => 'inbox.import_helpscout',
    'ids' => ['9101', '9102', '9103'],
    'dry' => 1,
    'limit' => 1,
    'disk_min_bytes' => 0,
    'disk_min_ratio' => 0,
]);
ids_ok(count((array) ($chunk['items'] ?? [])) === 1 && ($chunk['items']['9101']['would_send'] ?? null) === false && ($chunk['pending'] ?? null) === ['9102', '9103'], 'limit tar en bit och lämnar resten');

echo "OK\n";
