<?php

declare(strict_types=1);

/** Malmö Help Scout → Inkorg: klassning, sammanslagning, historik, lås, torrkörning, stängning. */

$tmp = sys_get_temp_dir() . '/jeab-malmo-hs-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function mhs(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function mhs_thread(array $conversation, array $threads): array
{
    $conversation['_threads'] = $threads;
    return $conversation;
}

function mhs_customer(string $id, string $email, string $first, string $last, string $body, string $at, array $attachments = []): array
{
    return [
        'id' => $id,
        'type' => 'customer',
        'createdAt' => $at,
        'body' => $body,
        'createdBy' => ['first' => $first, 'last' => $last, 'email' => $email],
        'customer' => ['id' => '9' . $id, 'email' => $email, 'first' => $first, 'last' => $last],
        'attachments' => $attachments,
    ];
}

$gets = 0;
$writes = [];
$GLOBALS['_JEAB_HS_IMPORT_HTTP'] = static function () use (&$gets): array {
    $gets++;
    return ['code' => 200, 'body' => '{"_embedded":{"conversations":[],"threads":[]},"page":{"totalPages":1}}'];
};
$GLOBALS['_JEAB_MALMO_HS_HTTP'] = static function (string $method, string $path, array $body) use (&$writes): array {
    $writes[] = [$method, $path, $body];
    return ['code' => 201];
};
file_put_contents(jeab_helpscout_path(), json_encode(['mailbox_id_malmo' => 4242]));

$maja = 'maja.exempel@brevlada.se';
$nora = 'nora.exempel@brevlada.se';
$sven = 'sven.sol@brevlada.se';
$otto = 'otto.ovrigt@brevlada.se';
$link = 'Se https://secure.helpscout.net/conversation/101/1';

$fixtures = [
    mhs_thread([
        'id' => 101,
        'subject' => 'Batteri till huset',
        'modifiedAt' => '2026-10-01T08:00:00Z',
        'tags' => [['tag' => 'batteri']],
        'primaryCustomer' => ['id' => '9101', 'email' => $maja, 'first' => 'Maja', 'last' => 'Exempel'],
    ], [
        mhs_customer('1', $maja, 'Maja', 'Exempel', 'Hej, jag vill ha batteri. ' . $link, '2026-10-01T08:00:00Z', [['id' => '55', 'filename' => 'bild-maja.jpg']]),
        ['id' => '2', 'type' => 'message', 'createdAt' => '2026-10-01T09:00:00Z', 'body' => 'Vi tittar på det. ' . $link, 'createdBy' => ['first' => 'Kim', 'email' => 'saljare.syd@malmo.example.test']],
        ['id' => '3', 'type' => 'note', 'createdAt' => '2026-10-01T09:05:00Z', 'body' => 'Intern anteckning som inte ska in.', 'createdBy' => ['email' => 'saljare.syd@malmo.example.test']],
    ]),
    mhs_thread([
        'id' => 102,
        'subject' => 'Laddare vid uppfarten',
        'modifiedAt' => '2026-10-02T08:00:00Z',
        'tags' => [['tag' => 'elbilsladdare']],
        'primaryCustomer' => ['id' => '9102', 'email' => $nora, 'first' => 'Nora', 'last' => 'Exempel'],
    ], [
        mhs_customer('4', $nora, 'Nora', 'Exempel', 'Vi behöver en laddare.', '2026-10-02T08:00:00Z'),
    ]),
    mhs_thread([
        'id' => 103,
        'subject' => 'Sol på taket',
        'modifiedAt' => '2026-10-03T08:00:00Z',
        'tags' => [['tag' => 'sol']],
        'primaryCustomer' => ['id' => '9103', 'email' => $sven, 'first' => 'Sven', 'last' => 'Sol'],
    ], [
        mhs_customer('5', $sven, 'Sven', 'Sol', 'Offert på sol tack.', '2026-10-03T08:00:00Z', [['id' => '56', 'filename' => 'tak-sven.pdf']]),
    ]),
    mhs_thread([
        'id' => 104,
        'subject' => 'Allmän fråga',
        'modifiedAt' => '2026-10-16T08:00:00Z',
        'tags' => [],
        'primaryCustomer' => ['id' => '9104', 'email' => $otto, 'first' => 'Otto', 'last' => 'Ovrigt'],
    ], [
        mhs_customer('6', $otto, 'Otto', 'Ovrigt', 'Bara en fråga.', '2026-10-16T08:00:00Z'),
    ]),
];
$GLOBALS['_JEAB_MALMO_HS_FIXTURES'] = $fixtures;

$GLOBALS['_JEAB_INKORG_LLM'] = static function (array $messages): string {
    $user = (string) ($messages[1]['content'] ?? '');
    $conf = str_contains($user, '[lag]') ? 40 : 96;
    $row = [
        'type' => 'Ny lead',
        'confidence' => $conf,
        'tags' => [],
        'question' => '',
        'reason' => 'klass',
        'is_lead_seller' => false,
        'seller_name' => '',
        'customer_email' => '',
        'customer_name' => '',
        'kind' => 'atgard',
        'label' => '',
        'field_confidence' => ['intent' => $conf / 100, 'primary_product' => $conf / 100, 'kind' => 0.95, 'type' => 0.95],
        'secondary_products' => [],
    ];
    $emit = static function (array $extra) use ($row): string {
        return json_encode(array_replace($row, $extra), JSON_UNESCAPED_UNICODE);
    };
    if (str_contains($user, '[lag]')) {
        return $emit(['intent' => 'kop', 'offer' => 'batteri', 'product' => 'batteri', 'case_intent' => 'kopintresse', 'primary_product' => 'Batteri', 'confidence' => 40]);
    }
    if (str_contains($user, '[support]') || str_contains($user, 'Bara en fråga')) {
        return $emit(['type' => 'Support', 'intent' => 'support', 'offer' => '', 'product' => '', 'case_intent' => 'support', 'primary_product' => '']);
    }
    if (str_contains($user, '[solbatteri]') || str_contains($user, 'solceller och batteri')) {
        return $emit(['intent' => 'kop', 'offer' => 'sol', 'product' => 'solceller, batteri', 'case_intent' => 'kopintresse', 'primary_product' => 'Sol', 'secondary_products' => ['Batteri']]);
    }
    if (str_contains($user, '[sol]') || str_contains($user, 'Offert på sol') || str_contains($user, 'Sol på taket') || str_contains($user, 'Sol i Skåne') || str_contains($user, 'Solceller i Lund')) {
        return $emit(['intent' => 'kop', 'offer' => 'sol', 'product' => 'solceller', 'case_intent' => 'kopintresse', 'primary_product' => 'Sol']);
    }
    if (str_contains($user, '[laddare]') || str_contains($user, 'laddare') || str_contains($user, 'Laddare')) {
        return $emit(['intent' => 'kop', 'offer' => 'laddbox', 'product' => 'elbilsladdare', 'case_intent' => 'kopintresse', 'primary_product' => 'Elbilsladdare']);
    }
    if (str_contains($user, '[batteri]') || str_contains($user, 'batteri') || str_contains($user, 'Batteri')) {
        return $emit(['intent' => 'kop', 'offer' => 'batteri', 'product' => 'batteri', 'case_intent' => 'kopintresse', 'primary_product' => 'Batteri']);
    }
    return $emit(['type' => 'Information', 'intent' => 'information', 'offer' => '', 'product' => '', 'case_intent' => '', 'primary_product' => '', 'kind' => 'information']);
};

$dry = jeab_inkorg_hook(['action' => 'inbox.malmo_hs_sync', 'limit' => 25]);
mhs(!empty($dry['ok']) && !empty($dry['dry']) && $gets === 0 && $writes === [], 'torrkörning gör inga Help Scout-anrop');
$byId = [];
foreach ((array) ($dry['conversations'] ?? []) as $row) {
    $byId[(string) ($row['id'] ?? '')] = $row;
}
mhs(($byId['101']['classification'] ?? '') === 'Batteri' && ($byId['101']['target'] ?? '') === 'Kim aktiv' && empty($byId['101']['merge']) && empty($byId['101']['close']), 'batteri till Kim, ingen stängning');
mhs(($byId['102']['classification'] ?? '') === 'Elbilsladdare' && ($byId['102']['target'] ?? '') === 'Kim aktiv' && !str_contains((string) ($byId['102']['classification'] ?? ''), 'laddbox'), 'elbilsladdare stavas Elbilsladdare');
mhs(($byId['103']['classification'] ?? '') === 'Sol' && ($byId['103']['target'] ?? '') === 'Historik' && empty($byId['103']['close']), 'sol till Historik');
mhs(($byId['104']['classification'] ?? '') === 'Övrigt' && ($byId['104']['target'] ?? '') === 'Historik', 'utan etikett till Historik');
mhs(($byId['101']['decision'] ?? '') === 'kim' && ($byId['101']['would_send_offer'] ?? null) === true && ($byId['101']['template'] ?? '') === 'batteri_malmo' && ($byId['101']['sender'] ?? '') === 'saljare.syd@malmo.example.test', 'torrkörning visar batteri-offert från Kim');
mhs(($byId['103']['decision'] ?? '') === 'historik' && empty($byId['103']['would_close']) && empty($byId['103']['would_send_offer']), 'sol ska inte stängas eller få offert');
mhs(!str_contains(json_encode($dry['conversations'], JSON_UNESCAPED_UNICODE), $maja), 'torrkörningen tar inte med kundens adress');
mhs(empty($dry['sync']) && empty($dry['close_enabled']), 'båda brytarna är av');

$filtered = jeab_inkorg_hook(['action' => 'inbox.malmo_hs_sync', 'since' => '2026-10-15', 'limit' => 1]);
$filtIds = array_map(static fn($r) => (string) ($r['id'] ?? ''), (array) ($filtered['conversations'] ?? []));
mhs($filtIds === ['104'] && $gets === 0, 'since och limit filtrerar torrlistan');

jeab_malmo_hs_settings_save(['malmo_hs_sync' => '1', 'malmo_hs_close' => '0']);
$live = jeab_malmo_hs_sync(['dry' => 0, 'backfill' => 1, 'limit' => 25]);
mhs(!empty($live['ok']) && empty($live['dry']) && $gets === 0 && $writes === [], 'riktig synk utan stängning skriver inte till Help Scout');

function mhs_tid(string $cid): string
{
    foreach (jeab_inkorg_threads_meta() as $tid => $t) {
        if (!is_array($t)) {
            continue;
        }
        $ids = array_map('strval', (array) ($t['hs_conversation_ids'] ?? []));
        if ((string) ($t['hs_conversation_id'] ?? '') === $cid || in_array($cid, $ids, true)) {
            return (string) $tid;
        }
    }
    return '';
}

function mhs_msgs(string $tid): array
{
    $out = [];
    foreach (jeab_inkorg_messages() as $m) {
        if (is_array($m) && (string) ($m['thread_id'] ?? '') === $tid) {
            $out[] = $m;
        }
    }
    return $out;
}

$kim = mhs_tid('101');
$kimMeta = jeab_inkorg_thread_meta($kim);
$kimMsgs = mhs_msgs($kim);
mhs($kim !== '' && !empty($kimMeta['malmo_hs_kim']) && ($kimMeta['status'] ?? '') === 'open' && ($kimMeta['assignee'] ?? '') === 'Kim' && in_array('batteri', (array) ($kimMeta['tags'] ?? []), true), 'Kim-tråd öppen och tilldelad Kim');
mhs(count($kimMsgs) === 2, 'text in och ut sparas, anteckning hoppas över');
$blob = json_encode($kimMsgs, JSON_UNESCAPED_UNICODE);
mhs(is_string($blob) && !str_contains($blob, 'helpscout.net') && !str_contains($blob, 'bild-maja.jpg') && !str_contains($blob, 'attachments'), 'inga bilagor och inga Help Scout-länkar');
foreach ($kimMsgs as $m) {
    mhs(($m['source'] ?? '') === 'malmo_hs', 'Kim-källa är malmo_hs');
}

$sol = mhs_tid('103');
$solMeta = jeab_inkorg_thread_meta($sol);
mhs($sol !== '' && $sol !== $kim && ($solMeta['status'] ?? '') === 'closed' && ($solMeta['import_source'] ?? '') === 'helpscout_import' && empty($solMeta['malmo_hs_kim']) && (string) ($solMeta['contact_id'] ?? '') !== '', 'sol stängs i Historik och kopplas till Kontakter');
$solBlob = json_encode(mhs_msgs($sol), JSON_UNESCAPED_UNICODE);
mhs(is_string($solBlob) && !str_contains($solBlob, 'tak-sven.pdf'), 'sol sparar inte bilagan');

$fixtures[0]['_threads'][] = mhs_customer('7', $maja, 'Maja', 'Exempel', 'En uppföljning.', '2026-10-04T08:00:00Z');
$GLOBALS['_JEAB_MALMO_HS_FIXTURES'] = $fixtures;
$again = jeab_malmo_hs_sync(['dry' => 0, 'backfill' => 1, 'limit' => 25]);
$againBy = [];
foreach ((array) ($again['conversations'] ?? []) as $row) {
    $againBy[(string) ($row['id'] ?? '')] = $row;
}
mhs(!empty($againBy['101']['merge']) && mhs_tid('101') === $kim && count(mhs_msgs($kim)) === 3, 'samma konversation slås ihop i Kims tråd');

$fixtures[] = mhs_thread([
    'id' => 202,
    'subject' => 'Batteri igen',
    'modifiedAt' => '2026-10-05T08:00:00Z',
    'tags' => [['tag' => 'batteri']],
    'primaryCustomer' => ['id' => '9202', 'email' => $maja, 'first' => 'Maja', 'last' => 'Exempel'],
], [
    mhs_customer('8', $maja, 'Maja', 'Exempel', 'Ny tråd, samma kund.', '2026-10-05T08:00:00Z'),
]);
$GLOBALS['_JEAB_MALMO_HS_FIXTURES'] = $fixtures;
$merged = jeab_malmo_hs_sync(['dry' => 0, 'backfill' => 1, 'limit' => 25]);
$mergedBy = [];
foreach ((array) ($merged['conversations'] ?? []) as $row) {
    $mergedBy[(string) ($row['id'] ?? '')] = $row;
}
$ids = array_map('strval', (array) (jeab_inkorg_thread_meta($kim)['hs_conversation_ids'] ?? []));
$kimThreads = 0;
foreach (jeab_inkorg_threads_meta() as $t) {
    if (is_array($t) && !empty($t['malmo_hs_kim'])) {
        $kimThreads++;
    }
}
mhs(!empty($mergedBy['202']['merge']) && mhs_tid('202') === $kim && in_array('202', $ids, true) && in_array('101', $ids, true) && $kimThreads === 2, 'samma e-post slås ihop, ingen extra Kim-tråd');

$hold = $tmp . '/hold.php';
file_put_contents($hold, "<?php\n\$f = fopen(\$argv[1], 'c');\nif (!\$f || !flock(\$f, LOCK_EX)) { fwrite(STDERR, \"nolock\\n\"); exit(1); }\necho \"held\\n\";\nfflush(STDOUT);\nsleep(30);\nflock(\$f, LOCK_UN);\n");
$proc = proc_open([PHP_BINARY, $hold, jeab_hs_import_lock_path()], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
mhs(is_resource($proc), 'låstest startar');
$held = is_resource($proc) ? (string) fgets($pipes[1]) : '';
mhs($held === "held\n", 'barnprocessen håller låset');
$busyHook = jeab_inkorg_hook(['action' => 'inbox.malmo_hs_sync', 'dry' => 1]);
$busyImport = jeab_hs_import_run(['dry' => 1]);
mhs(($busyHook['error'] ?? '') === 'busy' && ($busyImport['error'] ?? '') === 'busy', 'samma lås stoppar parallell synk och import');
if (is_resource($proc)) {
    proc_terminate($proc);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
}

jeab_malmo_hs_settings_save(['malmo_hs_sync' => '0', 'malmo_hs_close' => '0']);
$before = $gets;
$off = jeab_malmo_hs_sync(['dry' => 0, 'limit' => 5]);
mhs(!empty($off['ok']) && ($off['skipped'] ?? '') === 'av' && $gets === $before && $writes === [], 'avstängd synk gör inga anrop');

jeab_malmo_hs_settings_save(['malmo_hs_sync' => '1', 'malmo_hs_close' => '1']);
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts): array {
    unset($opts);

    return ['ok' => true, 'path' => 'inkorg', 'id' => 'stub-close', 'from' => 'saljare.syd@malmo.example.test'];
};
$GLOBALS['_JEAB_MALMO_HS_FIXTURES'] = [
    mhs_thread([
        'id' => 301,
        'subject' => 'Stäng batteriet',
        'modifiedAt' => '2026-10-06T08:00:00Z',
        'tags' => [['tag' => 'batteri']],
        'primaryCustomer' => ['id' => '9301', 'email' => 'mira.exempel@brevlada.se', 'first' => 'Mira', 'last' => 'Exempel'],
    ], [
        mhs_customer('9', 'mira.exempel@brevlada.se', 'Mira', 'Exempel', 'Nytt batteriärende.', '2026-10-06T08:00:00Z'),
    ]),
];
$closed = jeab_malmo_hs_sync(['dry' => 0, 'backfill' => 1, 'limit' => 5]);
mhs(!empty($closed['ok']) && $gets === $before, 'stängning läser inte Help Scout');
mhs($writes === [
    ['POST', 'conversations/301/notes', ['text' => 'Hanteras av Kim i flowstack.example.test']],
    ['PATCH', 'conversations/301', ['op' => 'replace', 'path' => '/status', 'value' => 'closed']],
], 'enda skrivningen är anteckning och stängning');
$denied = jeab_malmo_hs_http_write('POST', 'conversations/1/reply', ['text' => 'Hej']);
mhs(($denied['error'] ?? '') === 'hs_skriv_nekad' && count($writes) === 2, 'kundsvar i Help Scout nekas');

$writesBeforeSol = $writes;
$GLOBALS['_JEAB_MALMO_HS_FIXTURES'] = [
    mhs_thread([
        'id' => 303,
        'subject' => 'Sol i Skåne',
        'modifiedAt' => '2026-10-07T08:00:00Z',
        'tags' => [['tag' => 'sol']],
        'primaryCustomer' => ['id' => '9303', 'email' => 'svea.exempel@brevlada.se', 'first' => 'Svea', 'last' => 'Exempel'],
    ], [
        mhs_customer('10', 'svea.exempel@brevlada.se', 'Svea', 'Exempel', 'Solceller i Lund.', '2026-10-07T08:00:00Z'),
    ]),
];
$solClose = jeab_malmo_hs_sync(['dry' => 0, 'backfill' => 1, 'limit' => 5]);
$solPlan = null;
foreach ((array) ($solClose['conversations'] ?? []) as $row) {
    if ((string) ($row['id'] ?? '') === '303') {
        $solPlan = $row;
    }
}
mhs(!empty($solClose['ok']) && $writes === $writesBeforeSol, 'sol stängs inte i Help Scout och får inget svar');
mhs(is_array($solPlan) && ($solPlan['classification'] ?? '') === 'Sol' && ($solPlan['target'] ?? '') === 'Historik' && empty($solPlan['close']), 'sol med stängning på blir inte Kim-stängning');

unset($GLOBALS['_JEAB_MALMO_HS_FIXTURES']);
@unlink(jeab_malmo_hs_state_path());
$mark = jeab_malmo_hs_sync(['dry' => 0, 'since' => 1, 'limit' => 5]);
mhs(!empty($mark['ok']) && ($mark['conversations'] ?? null) === [] && $gets === $before + 1 && (string) (jeab_malmo_hs_state()['watermark'] ?? '') !== '', 'första since=1 hämtar öppna ärenden och sätter vattenstämpel');
$before = $gets;

jeab_malmo_hs_settings_save(['malmo_hs_sync' => '0', 'malmo_hs_close' => '0']);
if (!function_exists('jeab_flash_html')) {
    function jeab_flash_html(?string $flash): string
    {
        return '';
    }
}
if (!function_exists('jeab_error_html')) {
    function jeab_error_html(?string $error): string
    {
        return '';
    }
}
$html = (string) require dirname(__DIR__) . '/src/pages/inkorg_regler.php';
mhs(str_contains($html, 'Elbilsladdare') && str_contains($html, 'Kim') && str_contains($html, 'Historik') && str_contains($html, 'Hanteras av Kim i flowstack.example.test'), 'Regler visar Kim och Historik');
mhs(preg_match('/name="malmo_hs_sync"(?![^>]*checked)/', $html) === 1 && preg_match('/name="malmo_hs_close"(?![^>]*checked)/', $html) === 1, 'brytarna är av som standard i sidan');
mhs(str_contains($html, 'name="helpscout_companies[]"'), 'mejlboxarna ligger kvar');

$catalog = [];
foreach (jeab_notiser_catalog() as $row) {
    if (is_array($row)) {
        $catalog[(string) ($row['id'] ?? '')] = $row;
    }
}
foreach (['offert-batteri-malmo', 'offert-laddbox-malmo', 'offert-sol-malmo'] as $nid) {
    $row = $catalog[$nid] ?? null;
    $sender = (string) (is_array($row) ? ($row['sender'] ?? '') : '');
    $problem = (string) (is_array($row) ? ($row['problem'] ?? '') : '');
    mhs(is_array($row) && str_contains($sender, 'saljare.syd@malmo.example.test'), $nid . ' har avsändare saljare.syd@malmo.example.test, fick ' . $sender);
    mhs(!str_contains($problem, 'avsändare saknas') && !str_contains($problem, 'flödet skickar inte'), $nid . ' blockeras inte, fick ' . $problem);
}
mhs(str_contains((string) (($catalog['bekraft-laddbox-malmo']['sender'] ?? '')), 'kontakt@malmo.example.test'), 'bokningsbekräftelse behåller kontakt@');

$pdfDir = jeab_inkorg_dir() . '/offert';
if (!is_dir($pdfDir)) {
    mkdir($pdfDir, 0775, true);
}
file_put_contents(jeab_auto_pdf_path('batteri_malmo'), "%PDF-1.1\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
$quoteThread = null;
foreach (jeab_inkorg_threads() as $t) {
    if (is_array($t) && (string) ($t['id'] ?? '') === $kim) {
        $quoteThread = $t;
        break;
    }
}
$quoteMsg = null;
foreach (mhs_msgs($kim) as $m) {
    if (($m['labels']['offer'] ?? '') === 'batteri' && strcasecmp((string) ($m['from'] ?? ''), $maja) === 0) {
        $quoteMsg = $m;
        break;
    }
}
$mailCalls = [];
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use (&$mailCalls): array {
    $mailCalls[] = $opts;
    return ['ok' => true, 'path' => 'inkorg', 'id' => 'stub-kim'];
};
$writesBefore = count($writes);
$GLOBALS['_JEAB_AUTO_SELFTEST'] = true;
$quoted = is_array($quoteThread) && is_array($quoteMsg)
    ? jeab_auto_quote($quoteThread, $quoteMsg, jeab_auto_settings(), [], ['manual' => true])
    : ['action' => 'missing'];
unset($GLOBALS['_JEAB_AUTO_SELFTEST'], $GLOBALS['JEAB_SYSTEM_MAIL']);
$mailOpts = $mailCalls[0] ?? [];
mhs(($quoted['action'] ?? '') === 'quoted' && count($mailCalls) === 1, 'Kims offert går via systemmejlet, fick ' . (string) ($quoted['action'] ?? '') . ' ' . (string) ($quoted['why'] ?? ''));
mhs(
    strcasecmp((string) ($mailOpts['from_email'] ?? ''), 'saljare.syd@malmo.example.test') === 0
    && ($mailOpts['company'] ?? '') === 'malmo'
    && !empty($mailOpts['malmo_hs_kim'])
    && strcasecmp((string) ($mailOpts['to'] ?? ''), $maja) === 0,
    'stubben fick saljare.syd@malmo.example.test och ingen riktig kundsändning'
);
mhs(count($writes) === $writesBefore && $gets === $before, 'offertstestet skickar inget till Help Scout');

$graphCalls = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $args) use (&$graphCalls): array {
    $graphCalls[] = $args;
    return ['ok' => true, 'message_id' => 'graph-stub', 'from' => 'saljare.syd@malmo.example.test'];
};
$helper = jeab_system_mail_send([
    'to' => $maja,
    'subject' => 'Offert batteri',
    'text' => 'Hej Maja, det här är en stubb.',
    'company' => 'malmo',
    'from_email' => 'saljare.syd@malmo.example.test',
    'from_name' => 'Kim, Demo Energi Malmö',
    'malmo_hs_kim' => true,
]);
unset($GLOBALS['JEAB_GRAPH_SEND']);
$graphOpts = (array) (($graphCalls[0]['opts'] ?? []));
mhs(
    ($helper['path'] ?? '') === 'inkorg'
    && count($graphCalls) === 1
    && strcasecmp((string) ($graphOpts['person_email'] ?? ''), 'saljare.syd@malmo.example.test') === 0
    && !empty($graphOpts['malmo_hs_kim'])
    && count($writes) === $writesBefore,
    'systemmejlet tar Graph när Help Scout inte kan skicka som Kim'
);

jeab_malmo_hs_settings_save(['malmo_hs_sync' => '1', 'malmo_hs_close' => '1']);
$rules = [
    mhs_thread([
        'id' => 910,
        'subject' => '[solbatteri] Nya solceller och batteri',
        'status' => 'active',
        'modifiedAt' => '2026-10-18T08:00:00Z',
        'tags' => [['tag' => 'sol'], ['tag' => 'batteri']],
        'primaryCustomer' => ['id' => '9910', 'email' => 'sol.kund@brevlada.se', 'first' => 'Sol', 'last' => 'Kund'],
    ], [
        mhs_customer('910', 'sol.kund@brevlada.se', 'Sol', 'Kund', 'Vi vill ha solceller och batteri.', '2026-10-18T08:00:00Z'),
    ]),
    mhs_thread([
        'id' => 911,
        'subject' => '[batteri] Batteri till huset',
        'status' => 'active',
        'modifiedAt' => '2026-10-18T08:00:00Z',
        'tags' => [['tag' => 'batteri']],
        'primaryCustomer' => ['id' => '9911', 'email' => 'batt.kund@brevlada.se', 'first' => 'Batt', 'last' => 'Kund'],
    ], [
        mhs_customer('911', 'batt.kund@brevlada.se', 'Batt', 'Kund', 'Har redan solceller? Nej. [batteri] Jag vill köpa ett batteri.', '2026-10-18T08:00:00Z'),
    ]),
    mhs_thread([
        'id' => 912,
        'subject' => '[support] Hjälp med appen',
        'status' => 'active',
        'modifiedAt' => '2026-10-18T08:00:00Z',
        'tags' => [['tag' => 'batteri']],
        'primaryCustomer' => ['id' => '9912', 'email' => 'supp.kund@brevlada.se', 'first' => 'Supp', 'last' => 'Kund'],
    ], [
        mhs_customer('912', 'supp.kund@brevlada.se', 'Supp', 'Kund', '[support] Appen fungerar inte.', '2026-10-18T08:00:00Z'),
    ]),
    mhs_thread([
        'id' => 913,
        'subject' => '[lag] Osäkert batteri',
        'status' => 'active',
        'modifiedAt' => '2026-10-18T08:00:00Z',
        'tags' => [['tag' => 'batteri']],
        'primaryCustomer' => ['id' => '9913', 'email' => 'lag.kund@brevlada.se', 'first' => 'Lag', 'last' => 'Kund'],
    ], [
        mhs_customer('913', 'lag.kund@brevlada.se', 'Lag', 'Kund', '[lag] Kanske ett batteri.', '2026-10-18T08:00:00Z'),
    ]),
    mhs_thread([
        'id' => 914,
        'subject' => '[laddare] Laddare hemma',
        'status' => 'active',
        'modifiedAt' => '2026-10-18T08:00:00Z',
        'tags' => [['tag' => 'elbilsladdare']],
        'primaryCustomer' => ['id' => '9914', 'email' => 'ladd.kund@brevlada.se', 'first' => 'Ladd', 'last' => 'Kund'],
    ], [
        mhs_customer('914', 'ladd.kund@brevlada.se', 'Ladd', 'Kund', '[laddare] Vi vill köpa en laddare.', '2026-10-18T08:00:00Z'),
    ]),
];
$GLOBALS['_JEAB_MALMO_HS_FIXTURES'] = $rules;
$ruleDry = jeab_malmo_hs_sync(['dry' => 1, 'limit' => 10]);
$ruleBy = [];
foreach ((array) ($ruleDry['conversations'] ?? []) as $row) {
    $ruleBy[(string) ($row['id'] ?? '')] = $row;
}
mhs(($ruleBy['910']['decision'] ?? '') === 'historik' && empty($ruleBy['910']['would_close']) && empty($ruleBy['910']['would_send_offer']), 'sol och batteri blir historik och stängs inte');
mhs(($ruleBy['911']['decision'] ?? '') === 'kim' && ($ruleBy['911']['reason'] ?? '') === 'köpintresse batteri' && !empty($ruleBy['911']['would_close']) && ($ruleBy['911']['template'] ?? '') === 'batteri_malmo', 'batteri utan befintlig sol blir Kim');
mhs(($ruleBy['912']['decision'] ?? '') === 'historik' && ($ruleBy['912']['reason'] ?? '') === 'support' && empty($ruleBy['912']['would_close']), 'support blir historik');
mhs(($ruleBy['913']['decision'] ?? '') === 'historik' && ($ruleBy['913']['reason'] ?? '') === 'osäker klassning' && empty($ruleBy['913']['would_close']), 'låg säkerhet blir historik');
mhs(($ruleBy['914']['decision'] ?? '') === 'kim' && ($ruleBy['914']['template'] ?? '') === 'laddbox_malmo' && ($ruleBy['914']['sender'] ?? '') === 'saljare.syd@malmo.example.test', 'elbilsladdare med köpintresse blir Kim');
mhs(count($writes) === $writesBefore && $gets === $before, 'klassningens torrkörning skriver inte till Help Scout');

$refused = jeab_malmo_hs_sync(['dry' => 0, 'limit' => 5]);
mhs(empty($refused['ok']) && ($refused['error'] ?? '') === 'since_saknas' && ($refused['conversations'] ?? null) === [], 'dry=0 utan since nekas');
$tooOld = jeab_malmo_hs_sync(['dry' => 0, 'since' => '2020-01-01', 'limit' => 5]);
mhs(empty($tooOld['ok']) && ($tooOld['error'] ?? '') === 'since_for_gammal', 'since äldre än 14 dagar nekas');

$offerMail = [];
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use (&$offerMail): array {
    $offerMail[] = $opts;
    return ['ok' => true, 'path' => 'inkorg', 'id' => 'stub-offer', 'from' => 'saljare.syd@malmo.example.test'];
};
jeab_offer_malmo_sent_record('finns.kund@brevlada.se', 'batteri', '920');
$remBefore = count(jeab_reminders_every(jeab_store()));
jeab_create_reminder(jeab_store(), 'Finns Kund – Batteri', jeab_today_start(), 'hs_conversation_id: 921', null, [
    'customer' => 'Finns Kund',
    'email' => 'finns.kund@brevlada.se',
    'company' => 'malmo',
    'who' => 'Kim',
    'note' => 'hs_conversation_id: 921',
]);
$GLOBALS['_JEAB_MALMO_HS_FIXTURES'] = [
    mhs_thread([
        'id' => 920,
        'subject' => '[batteri] Redan offert',
        'status' => 'active',
        'modifiedAt' => '2026-10-18T09:00:00Z',
        'tags' => [['tag' => 'batteri']],
        'primaryCustomer' => ['id' => '9920', 'email' => 'finns.kund@brevlada.se', 'first' => 'Finns', 'last' => 'Kund'],
    ], [
        mhs_customer('920', 'finns.kund@brevlada.se', 'Finns', 'Kund', '[batteri] Batteri igen.', '2026-10-18T09:00:00Z'),
    ]),
];
$hadOffer = jeab_malmo_hs_sync(['dry' => 0, 'backfill' => 1, 'limit' => 5]);
$hadOfferRow = (array) (($hadOffer['conversations'][0] ?? []));
mhs(!empty($hadOffer['ok']) && !empty($hadOfferRow['has_offer']) && empty($hadOfferRow['would_send_offer']) && $offerMail === [], 'befintlig offert skickas inte igen');
mhs(!function_exists('jeab_malmo_hs_auto_quote_wanted'), 'backfill har ingen avstängd auto_quote-brytare');

$offerMail = [];
$GLOBALS['_JEAB_MALMO_HS_FIXTURES'] = [
    mhs_thread([
        'id' => 923,
        'subject' => '[batteri] Saknar offert',
        'status' => 'active',
        'modifiedAt' => '2026-10-18T09:05:00Z',
        'tags' => [['tag' => 'batteri']],
        'primaryCustomer' => ['id' => '9923', 'email' => 'saknas.kund@brevlada.se', 'first' => 'Saknas', 'last' => 'Kund'],
    ], [
        mhs_customer('923', 'saknas.kund@brevlada.se', 'Saknas', 'Kund', '[batteri] Jag vill köpa ett batteri.', '2026-10-18T09:05:00Z'),
    ]),
];
$missDry = jeab_malmo_hs_sync(['dry' => 1, 'backfill' => 1, 'limit' => 5]);
$missDryRow = (array) (($missDry['conversations'][0] ?? []));
$missLive = jeab_malmo_hs_sync(['dry' => 0, 'backfill' => 1, 'limit' => 5]);
$sentOffers = count($offerMail);
$missAgain = jeab_malmo_hs_sync(['dry' => 1, 'backfill' => 1, 'limit' => 5]);
$missAgainRow = (array) (($missAgain['conversations'][0] ?? []));
mhs(
    !empty($missDry['ok'])
    && empty($missDryRow['has_offer'])
    && !empty($missDryRow['would_send_offer'])
    && !empty($missLive['ok'])
    && $sentOffers === 1
    && !empty($missAgainRow['has_offer'])
    && empty($missAgainRow['would_send_offer'])
    && count($offerMail) === 1,
    'backfill skickar saknad offert och skickar den inte igen'
);

$onlyRem = count(jeab_reminders_every(jeab_store()));
$GLOBALS['_JEAB_MALMO_HS_FIXTURES'] = [
    mhs_thread([
        'id' => 921,
        'subject' => '[batteri] Redan påminnelse',
        'status' => 'active',
        'modifiedAt' => '2026-10-18T09:10:00Z',
        'tags' => [['tag' => 'batteri']],
        'primaryCustomer' => ['id' => '9921', 'email' => 'finns.kund@brevlada.se', 'first' => 'Finns', 'last' => 'Kund'],
    ], [
        mhs_customer('921', 'finns.kund@brevlada.se', 'Finns', 'Kund', '[batteri] Samma batteri.', '2026-10-18T09:10:00Z'),
    ]),
];
$offerMail = [];
$hadRem = jeab_malmo_hs_sync(['dry' => 0, 'backfill' => 1, 'limit' => 5]);
$hadRemRow = (array) (($hadRem['conversations'][0] ?? []));
mhs(
    !empty($hadRem['ok'])
    && !empty($hadRemRow['has_reminder'])
    && empty($hadRemRow['would_create_reminder'])
    && count(jeab_reminders_every(jeab_store())) === $onlyRem,
    'befintlig påminnelse skapas inte igen'
);
unset($remBefore);

$writesClosed = $writes;
$offerMail = [];
$GLOBALS['_JEAB_MALMO_HS_FIXTURES'] = [
    mhs_thread([
        'id' => 922,
        'subject' => '[batteri] Redan stängt',
        'status' => 'closed',
        'modifiedAt' => '2026-10-18T09:20:00Z',
        'tags' => [['tag' => 'batteri']],
        'primaryCustomer' => ['id' => '9922', 'email' => 'stangd.kund@brevlada.se', 'first' => 'Stangd', 'last' => 'Kund'],
    ], [
        mhs_customer('922', 'stangd.kund@brevlada.se', 'Stangd', 'Kund', '[batteri] Gammalt batteri.', '2026-10-18T09:20:00Z'),
    ]),
];
$already = jeab_malmo_hs_sync(['dry' => 0, 'backfill' => 1, 'limit' => 5]);
$alreadyRow = (array) (($already['conversations'][0] ?? []));
mhs(
    !empty($already['ok'])
    && ($alreadyRow['skipped'] ?? '') === 'hs_closed'
    && empty($alreadyRow['would_send_offer'])
    && empty($alreadyRow['would_create_reminder'])
    && empty($alreadyRow['would_close'])
    && $offerMail === []
    && $writes === $writesClosed,
    'redan stängt Help Scout-ärende hoppas över'
);

$dryGets = [];
$stateBefore = jeab_malmo_hs_state();
unset($GLOBALS['_JEAB_MALMO_HS_FIXTURES']);
$GLOBALS['_JEAB_HS_IMPORT_SLEEP'] = static function (): void {};
$GLOBALS['_JEAB_HS_IMPORT_HTTP'] = static function (string $method, string $url) use (&$dryGets): array {
    $dryGets[] = $method . ' ' . $url;
    $rows = [];
    for ($i = 1; $i <= 25; $i++) {
        $rows[] = [
            'id' => 8000 + $i,
            'subject' => 'Sida ' . $i,
            'status' => 'active',
            'modifiedAt' => '2026-10-18T08:00:00Z',
            'tags' => [],
            'primaryCustomer' => ['id' => (string) $i, 'email' => 'sida@brevlada.se', 'first' => 'Sida', 'last' => 'Kund'],
            '_threads' => [],
        ];
    }
    return ['code' => 200, 'body' => json_encode(['_embedded' => ['conversations' => $rows, 'threads' => []], 'page' => ['totalPages' => 1]], JSON_UNESCAPED_UNICODE)];
};
$dryLive = jeab_malmo_hs_sync(['dry' => 1, 'limit' => 10]);
$dryIds = array_map(static fn($r) => (string) ($r['id'] ?? ''), (array) ($dryLive['conversations'] ?? []));
mhs(!empty($dryLive['dry']) && $dryGets !== [] && $offerMail === [] && $writes === $writesClosed && jeab_malmo_hs_state() == $stateBefore, 'dry=1 läser Help Scout och skriver inget');
mhs($dryIds === ['8001', '8002', '8003', '8004', '8005', '8006', '8007', '8008', '8009', '8010'], 'dry=1 tar första delen av sidan');
mhs(isset($dryLive['conversations'][0]['has_reminder']) && array_key_exists('would_send_offer', (array) $dryLive['conversations'][0]), 'dry=1 visar påminnelse och offert per ärende');

$dryGets = [];
$p1 = jeab_malmo_hs_sync(['dry' => 0, 'since' => 1, 'limit' => 10]);
$p2 = jeab_malmo_hs_sync(['dry' => 0, 'since' => 1, 'limit' => 10]);
$ids1 = array_map(static fn($r) => (string) ($r['id'] ?? ''), (array) ($p1['conversations'] ?? []));
$ids2 = array_map(static fn($r) => (string) ($r['id'] ?? ''), (array) ($p2['conversations'] ?? []));
mhs($ids1 === $dryIds && $ids2[0] === '8011' && $ids2[9] === '8020', 'nästa since=1 tar resten av sidan');
unset($GLOBALS['_JEAB_HS_IMPORT_SLEEP']);
$GLOBALS['_JEAB_HS_IMPORT_HTTP'] = static function () use (&$gets): array {
    $gets++;
    return ['code' => 200, 'body' => '{"_embedded":{"conversations":[],"threads":[]},"page":{"totalPages":1}}'];
};

$kimMsg = mhs_msgs($kim)[0] ?? [];
$solMsg = mhs_msgs($sol)[0] ?? [];
$kimRebook = is_array($kimMsg) ? jeab_rebook_on_message((string) ($kimMsg['id'] ?? ''), true) : ['why' => 'saknas'];
$solRebook = is_array($solMsg) ? jeab_rebook_on_message((string) ($solMsg['id'] ?? ''), true) : ['why' => 'saknas'];
mhs(($kimRebook['why'] ?? '') !== 'bolaget hanteras i Help Scout', 'flaggat Kim-ärende passerar Help Scout-spärren');
mhs(in_array((string) ($solRebook['why'] ?? ''), ['bolaget hanteras i Help Scout', 'importerat ärende'], true), 'övriga Malmö-ärenden stannar i Help Scout');
mhs(in_array('malmo', jeab_inkorg_helpscout_companies(), true), 'Malmö ligger kvar bland Help Scout-bolagen');

$gateCalls = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $args) use (&$gateCalls): array {
    $gateCalls[] = $args;
    return ['ok' => true, 'via' => 'graph'];
};
$kimReply = jeab_inkorg_send_reply($kim, 'kund.svar@brevlada.se', 'Hej', 'Text', 'malmo');
$solReply = jeab_inkorg_send_reply($sol, 'kund.svar@brevlada.se', 'Hej', 'Text', 'malmo');
unset($GLOBALS['JEAB_GRAPH_SEND'], $GLOBALS['JEAB_SYSTEM_MAIL']);
mhs(!empty($kimReply['ok']) && count($gateCalls) === 1 && !empty($gateCalls[0]['opts']['malmo_hs_kim']), 'Kim-svar går från Kims brevlåda');
mhs(empty($solReply['ok']) && count($gateCalls) === 1, 'historik svarar inte via Graph');
unset($GLOBALS['JEAB_GRAPH_SEND'], $GLOBALS['JEAB_SYSTEM_MAIL']);

mhs(str_contains($html, 'name="malmo_hs_since"') && str_contains($html, '25206') && str_contains($html, '25221'), 'Regler har sedan-datum och spärrlista');

$plain = jeab_inkorg_ingest([
    'message_id' => 'plain-malmo-' . bin2hex(random_bytes(3)) . '@jeab.test',
    'from' => 'annan.kund@brevlada.se',
    'to' => 'kontakt@malmo.example.test',
    'subject' => 'Hej',
    'body_text' => 'Hej',
    'source' => 'graph-malmo',
    'thread_key' => 'plain-malmo',
    'company' => 'malmo',
]);
$plainRebook = jeab_rebook_on_message((string) ($plain['id'] ?? ''), true);
mhs(($plainRebook['why'] ?? '') === 'bolaget hanteras i Help Scout', 'Malmö utan Kim-flagga stannar i Help Scout');

$GLOBALS['_JEAB_GP_FLAGS'] = ['graph_person_mail' => false];
$kimPreview = is_array($quoteThread) && is_array($quoteMsg)
    ? jeab_auto_quote($quoteThread, $quoteMsg, jeab_auto_settings(), [], ['dry' => true])
    : ['action' => 'missing'];
unset($GLOBALS['_JEAB_GP_FLAGS']);
$kimWhy = (string) ($kimPreview['why'] ?? '');
mhs(
    ($kimPreview['action'] ?? '') === 'dry'
    && str_contains((string) ($kimPreview['sender'] ?? ''), 'saljare.syd@malmo.example.test')
    && !str_contains($kimWhy, 'personens brevlåda')
    && !str_contains($kimWhy, 'ingen personbrevlåda')
    && !str_contains($kimWhy, 'bolaget hanteras'),
    'Kim-offert hålls inte för personbrevlåda eller Help Scout, fick ' . (string) ($kimPreview['action'] ?? '') . ' ' . $kimWhy
);
$kimClose = jeab_inkorg_close_one(['thread_id' => $kim, 'dry' => 1]);
mhs((int) ($kimClose['status'] ?? 0) !== 403 && !str_contains((string) ($kimClose['reason'] ?? ''), 'skrivskyddad'), 'Kim-ärende är inte skrivskyddat i inkorgen');

jeab_malmo_hs_settings_save([
    'malmo_hs_sync' => '1',
    'malmo_hs_close' => '1',
    'malmo_hs_since' => '2026-06-01',
    'malmo_hs_skip' => "25206\n25221",
]);
$GLOBALS['_JEAB_MALMO_HS_FIXTURES'] = [
    mhs_thread([
        'id' => 930,
        'subject' => '[batteri] Gammalt stängt',
        'status' => 'closed',
        'createdAt' => '2019-01-01T00:00:00Z',
        'modifiedAt' => '2026-10-18T10:00:00Z',
        'tags' => [['tag' => 'batteri']],
        'primaryCustomer' => ['id' => '9930', 'email' => 'gammal.kund@brevlada.se', 'first' => 'Gammal', 'last' => 'Kund'],
    ], [
        mhs_customer('930', 'gammal.kund@brevlada.se', 'Gammal', 'Kund', '[batteri] Gammalt.', '2019-01-01T00:00:00Z'),
    ]),
    mhs_thread([
        'id' => 931,
        'subject' => '[batteri] Gammalt öppet',
        'status' => 'active',
        'createdAt' => '2019-02-01T00:00:00Z',
        'modifiedAt' => '2026-10-18T10:00:00Z',
        'tags' => [['tag' => 'batteri']],
        'primaryCustomer' => ['id' => '9931', 'email' => 'oppet.kund@brevlada.se', 'first' => 'Oppet', 'last' => 'Kund'],
    ], [
        mhs_customer('931', 'oppet.kund@brevlada.se', 'Oppet', 'Kund', '[batteri] Fortfarande öppet.', '2019-02-01T00:00:00Z'),
    ]),
    mhs_thread([
        'id' => 932,
        'subject' => '[batteri] Nytt stängt',
        'status' => 'closed',
        'createdAt' => '2026-08-01T00:00:00Z',
        'modifiedAt' => '2026-10-18T10:00:00Z',
        'tags' => [['tag' => 'batteri']],
        'primaryCustomer' => ['id' => '9932', 'email' => 'nytt.kund@brevlada.se', 'first' => 'Nytt', 'last' => 'Kund'],
    ], [
        mhs_customer('932', 'nytt.kund@brevlada.se', 'Nytt', 'Kund', '[batteri] Nytt men stängt.', '2026-08-01T00:00:00Z'),
    ]),
    mhs_thread([
        'id' => 25206,
        'subject' => '[batteri] Kundtjänst',
        'status' => 'active',
        'createdAt' => '2026-08-02T00:00:00Z',
        'modifiedAt' => '2026-10-18T10:00:00Z',
        'tags' => [['tag' => 'batteri']],
        'primaryCustomer' => ['id' => '9933', 'email' => 'tjanst.kund@brevlada.se', 'first' => 'Tjanst', 'last' => 'Kund'],
    ], [
        mhs_customer('25206', 'tjanst.kund@brevlada.se', 'Tjanst', 'Kund', '[batteri] Kundtjänst.', '2026-08-02T00:00:00Z'),
    ]),
    mhs_thread([
        'id' => 25221,
        'subject' => '[laddare] Kundtjänst laddare',
        'status' => 'active',
        'createdAt' => '2026-08-03T00:00:00Z',
        'modifiedAt' => '2026-10-18T10:00:00Z',
        'tags' => [['tag' => 'elbilsladdare']],
        'primaryCustomer' => ['id' => '9934', 'email' => 'tjanst2.kund@brevlada.se', 'first' => 'Tjanst', 'last' => 'Tva'],
    ], [
        mhs_customer('25221', 'tjanst2.kund@brevlada.se', 'Tjanst', 'Tva', '[laddare] Kundtjänst.', '2026-08-03T00:00:00Z'),
    ]),
    mhs_thread([
        'id' => 3487280105,
        'number' => 25206,
        'subject' => '[batteri] Nummer i spärrlistan',
        'status' => 'active',
        'createdAt' => '2026-08-04T00:00:00Z',
        'modifiedAt' => '2026-10-18T10:00:00Z',
        'tags' => [['tag' => 'batteri']],
        'primaryCustomer' => ['id' => '9935', 'email' => 'nummer.kund@brevlada.se', 'first' => 'Nummer', 'last' => 'Kund'],
    ], [
        mhs_customer('3487280105', 'nummer.kund@brevlada.se', 'Nummer', 'Kund', '[batteri] Kundtjänst nummer.', '2026-08-04T00:00:00Z'),
    ]),
    mhs_thread([
        'id' => 3487280999,
        'number' => 25999,
        'subject' => '[batteri] Annat nummer',
        'status' => 'active',
        'createdAt' => '2026-08-05T00:00:00Z',
        'modifiedAt' => '2026-10-18T10:00:00Z',
        'tags' => [['tag' => 'batteri']],
        'primaryCustomer' => ['id' => '9936', 'email' => 'annat.nummer@brevlada.se', 'first' => 'Annat', 'last' => 'Nummer'],
    ], [
        mhs_customer('3487280999', 'annat.nummer@brevlada.se', 'Annat', 'Nummer', '[batteri] Inte spärrat.', '2026-08-05T00:00:00Z'),
    ]),
];
$scopeMail = [];
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use (&$scopeMail): array {
    $scopeMail[] = $opts;
    return ['ok' => true, 'path' => 'inkorg', 'id' => 'stub-scope'];
};
$scope = jeab_malmo_hs_sync(['dry' => 1, 'limit' => 20]);
$scopeIds = array_map(static fn($r) => (string) ($r['id'] ?? ''), (array) ($scope['conversations'] ?? []));
$scopeBy = [];
foreach ((array) ($scope['conversations'] ?? []) as $row) {
    $scopeBy[(string) ($row['id'] ?? '')] = $row;
}
mhs(!in_array('930', $scopeIds, true) && in_array('931', $scopeIds, true), 'sedan-datumet hoppar över gamla stängda och tar öppna');
mhs(($scopeBy['932']['skipped'] ?? '') === 'hs_closed' && empty($scopeBy['932']['would_close']) && empty($scopeBy['932']['would_send_offer']), 'stängt ärende efter datumet skickas inte');
mhs(($scopeBy['25206']['skipped'] ?? '') === 'skip_list' && empty($scopeBy['25206']['would_send_offer']) && empty($scopeBy['25206']['would_close']), '25206 lämnas till kundtjänst');
mhs(($scopeBy['25221']['skipped'] ?? '') === 'skip_list' && empty($scopeBy['25221']['would_send_offer']), '25221 lämnas till kundtjänst');
mhs(($scopeBy['3487280105']['skipped'] ?? '') === 'skip_list' && empty($scopeBy['3487280105']['would_send_offer']), 'spärrlista matchar Help Scout-nummer när id är ett annat');
mhs(($scopeBy['3487280999']['skipped'] ?? '') === '' && !empty($scopeBy['3487280999']['would_send_offer']), 'annat nummer spärras inte');
$scopeLive = jeab_malmo_hs_sync(['dry' => 0, 'backfill' => 1, 'limit' => 20]);
$scopeTo = array_map(static fn(array $o): string => mb_strtolower((string) ($o['to'] ?? '')), $scopeMail);
mhs(
    !empty($scopeLive['ok'])
    && !in_array('gammal.kund@brevlada.se', $scopeTo, true)
    && !in_array('nytt.kund@brevlada.se', $scopeTo, true)
    && !in_array('tjanst.kund@brevlada.se', $scopeTo, true)
    && !in_array('tjanst2.kund@brevlada.se', $scopeTo, true)
    && !in_array('nummer.kund@brevlada.se', $scopeTo, true),
    'spärrlista och stängda ärenden skickar ingen offert'
);
unset($GLOBALS['JEAB_SYSTEM_MAIL'], $GLOBALS['_JEAB_MALMO_HS_FIXTURES']);

$hsUrls = [];
$GLOBALS['_JEAB_HS_IMPORT_HTTP'] = static function (string $method, string $url) use (&$hsUrls): array {
    $hsUrls[] = rawurldecode($url);
    return ['code' => 200, 'body' => '{"_embedded":{"conversations":[]},"page":{"totalPages":1}}'];
};
jeab_malmo_hs_sync(['dry' => 1, 'limit' => 1]);
$asked = implode("\n", $hsUrls);
$askedFlat = str_replace('+', ' ', $asked);
mhs(str_contains($askedFlat, 'status:"active"') && str_contains($askedFlat, 'createdAt:[2026-06-01T00:00:00Z TO *]') && !str_contains($askedFlat, 'status=closed'), 'Help Scout-frågan tar öppna och nya, inte den gamla stängda högen');

$graphUrls = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url, ?array $body = null, array $headers = []) use (&$graphUrls): array {
    unset($method, $body, $headers);
    $graphUrls[] = rawurldecode($url);
    return ['code' => 200, 'body' => ['value' => []]];
};
jeab_inkorg_helpscout_companies_save(['helpscout_companies' => []]);
jeab_graph_delta();
$graphAsked = implode("\n", $graphUrls);
mhs(array_keys(jeab_graph_poll_mailboxes()) === ['jarfalla'] && !str_contains($graphAsked, 'malmo-demo'), 'Graph läser inte Malmös brevlåda');
unset($GLOBALS['_JEAB_GRAPH_HTTP']);

jeab_malmo_hs_state_save(['watermark' => '2020-01-01T00:00:00Z', 'page' => 9, 'offset' => 40, 'query' => 'dirty']);
$dirtyCursor = jeab_malmo_hs_state();
$pageUrls = [];
$GLOBALS['_JEAB_HS_IMPORT_SLEEP'] = static function (): void {};
$GLOBALS['_JEAB_HS_IMPORT_HTTP'] = static function (string $method, string $url) use (&$pageUrls): array {
    unset($method);
    $pageUrls[] = str_replace('+', ' ', rawurldecode($url));
    $page = 1;
    if (preg_match('/[?&]page=(\d+)/', $url, $m) === 1) {
        $page = (int) $m[1];
    }
    $rows = [];
    for ($i = 1; $i <= 2; $i++) {
        $id = 9000 + (($page - 1) * 2) + $i;
        $rows[] = [
            'id' => $id,
            'number' => $id,
            'subject' => '[lag] Sida ' . $page,
            'status' => 'active',
            'modifiedAt' => '2026-10-18T08:00:00Z',
            'tags' => [],
            'primaryCustomer' => ['id' => (string) $id, 'email' => 'sida' . $id . '@brevlada.se', 'first' => 'Sida', 'last' => 'Kund'],
            '_threads' => [[
                'id' => (string) $id,
                'type' => 'customer',
                'createdAt' => '2026-10-18T08:00:00Z',
                'body' => '[lag] Öppen sida.',
                'createdBy' => ['first' => 'Sida', 'last' => 'Kund', 'email' => 'sida' . $id . '@brevlada.se'],
            ]],
        ];
    }
    return ['code' => 200, 'body' => json_encode(['_embedded' => ['conversations' => $rows], 'page' => ['totalPages' => 3]], JSON_UNESCAPED_UNICODE)];
};
$pageDry = jeab_malmo_hs_sync(['dry' => 1, 'backfill' => 1, 'limit' => 50]);
$pageDryIds = array_map(static fn($r) => (string) ($r['id'] ?? ''), (array) ($pageDry['conversations'] ?? []));
$pageDryFlat = implode("\n", $pageUrls);
mhs(
    $pageDryIds === ['9001', '9002', '9003', '9004', '9005', '9006']
    && str_contains($pageDryFlat, 'page=1')
    && str_contains($pageDryFlat, 'page=2')
    && str_contains($pageDryFlat, 'page=3')
    && !str_contains($pageDryFlat, 'page=9')
    && !str_contains($pageDryFlat, 'modifiedAt:[')
    && jeab_malmo_hs_state() == $dirtyCursor,
    'dry backfill går igenom tre sidor och rör inte markören'
);
$pageUrls = [];
$pageLive = jeab_malmo_hs_sync(['dry' => 0, 'backfill' => 1, 'limit' => 50]);
$pageLiveIds = array_map(static fn($r) => (string) ($r['id'] ?? ''), (array) ($pageLive['conversations'] ?? []));
$pageLiveFlat = implode("\n", $pageUrls);
$afterBackfill = jeab_malmo_hs_state();
mhs(
    !empty($pageLive['ok'])
    && $pageLiveIds === ['9001', '9002', '9003', '9004', '9005', '9006']
    && str_starts_with($pageUrls[0] ?? '', 'https://')
    && str_contains((string) ($pageUrls[0] ?? ''), 'page=1')
    && !str_contains($pageLiveFlat, 'page=9')
    && (int) ($afterBackfill['page'] ?? 0) === 1
    && (int) ($afterBackfill['offset'] ?? -1) === 0
    && (string) ($afterBackfill['watermark'] ?? '') !== '2020-01-01T00:00:00Z',
    'backfill börjar om från sida 1 och ignorerar sparad markör'
);

@unlink(jeab_malmo_hs_state_path());
$bgPdo = jeab_hs_import_store_pdo(false);
if ($bgPdo instanceof PDO) {
    $bgPdo->exec('DELETE FROM malmo_hs_cursor');
}
$bgUrls = [];
$GLOBALS['_JEAB_HS_IMPORT_HTTP'] = static function (string $method, string $url) use (&$bgUrls): array {
    unset($method);
    $bgUrls[] = str_replace('+', ' ', rawurldecode($url));
    $rows = [[
        'id' => 7001,
        'number' => 27001,
        'subject' => '[lag] Första bakgrunden',
        'status' => 'active',
        'modifiedAt' => '2026-10-18T08:00:00Z',
        'tags' => [],
        'primaryCustomer' => ['id' => '7001', 'email' => 'bakgrund.kund@brevlada.se', 'first' => 'Bakgrund', 'last' => 'Kund'],
        '_threads' => [[
            'id' => '7001',
            'type' => 'customer',
            'createdAt' => '2026-10-18T08:00:00Z',
            'body' => '[lag] Öppet ärende.',
            'createdBy' => ['first' => 'Bakgrund', 'last' => 'Kund', 'email' => 'bakgrund.kund@brevlada.se'],
        ]],
    ]];
    return ['code' => 200, 'body' => json_encode(['_embedded' => ['conversations' => $rows], 'page' => ['totalPages' => 1]], JSON_UNESCAPED_UNICODE)];
};
jeab_malmo_hs_background();
$bgFlat = implode("\n", $bgUrls);
mhs($bgUrls !== [] && !str_contains($bgFlat, 'modifiedAt:[') && (string) (jeab_malmo_hs_state()['watermark'] ?? '') !== '', 'första bakgrunden tar öppna ärenden och sätter vattenstämpel');
$bgUrls = [];
jeab_malmo_hs_background();
mhs(str_contains(implode("\n", $bgUrls), 'modifiedAt:['), 'nästa bakgrund följer vattenstämpeln');
unset($GLOBALS['_JEAB_HS_IMPORT_SLEEP']);

$token = jeab_inkorg_token_ensure();
$methodWas = $_SERVER['REQUEST_METHOD'] ?? null;
$authWas = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = jeab_inkorg_hook(['action' => 'inbox.malmo_hs_settings']);
mhs(empty($denied['ok']) && ($denied['error'] ?? '') === 'Fel token.', 'inställningshook kräver token');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
$beforeHook = jeab_malmo_hs_settings();
$readHook = jeab_inkorg_hook(['action' => 'inbox.malmo_hs_settings']);
mhs(
    !empty($readHook['ok'])
    && ($readHook['malmo_hs_since'] ?? null) === $beforeHook['since']
    && ($readHook['malmo_hs_skip'] ?? null) === $beforeHook['skip']
    && ($readHook['malmo_hs_sync'] ?? null) === $beforeHook['sync']
    && ($readHook['malmo_hs_close'] ?? null) === $beforeHook['close'],
    'läsning utan parametrar ger nuvarande värden'
);
$savedHook = jeab_inkorg_hook([
    'action' => 'inbox.malmo_hs_settings',
    'malmo_hs_since' => '2026-07-01',
    'malmo_hs_skip' => "25206\n25221\n25230",
    'malmo_hs_sync' => 'false',
    'malmo_hs_close' => '0',
    'malmo_hs_other' => 'nej',
]);
mhs(
    !empty($savedHook['ok'])
    && ($savedHook['malmo_hs_since'] ?? '') === '2026-07-01'
    && ($savedHook['malmo_hs_sync'] ?? null) === false
    && ($savedHook['malmo_hs_close'] ?? null) === false
    && in_array('25230', (array) ($savedHook['malmo_hs_skip'] ?? []), true)
    && !array_key_exists('malmo_hs_other', $savedHook),
    'hook sparar sedan, spärrlista och brytare'
);
$rawSettings = json_decode((string) file_get_contents(jeab_inkorg_dir() . '/settings.json'), true);
mhs(is_array($rawSettings) && !array_key_exists('malmo_hs_other', $rawSettings), 'hook skriver inga andra nycklar');
$badDate = jeab_inkorg_hook(['action' => 'inbox.malmo_hs_settings', 'malmo_hs_since' => '2026-13-40']);
$badBool = jeab_inkorg_hook(['action' => 'inbox.malmo_hs_settings', 'malmo_hs_sync' => 'maybe']);
$afterHook = jeab_malmo_hs_settings();
mhs(empty($badDate['ok']) && ($badDate['error'] ?? '') === 'since ska vara ett datum.' && $afterHook['since'] === '2026-07-01', 'ogiltigt datum sparas inte');
mhs(empty($badBool['ok']) && $afterHook['sync'] === false, 'ogiltig brytare sparas inte');
$_SERVER['HTTP_AUTHORIZATION'] = '';
$deniedPost = jeab_inkorg_hook(['action' => 'inbox.malmo_hs_settings']);
mhs(empty($deniedPost['ok']) && ($deniedPost['error'] ?? '') === 'Fel token.', 'hook utan bearer nekas');
if ($methodWas === null) {
    unset($_SERVER['REQUEST_METHOD']);
} else {
    $_SERVER['REQUEST_METHOD'] = $methodWas;
}
if ($authWas === null) {
    unset($_SERVER['HTTP_AUTHORIZATION']);
} else {
    $_SERVER['HTTP_AUTHORIZATION'] = $authWas;
}

echo "inkorg malmö hs sync: ok\n";
