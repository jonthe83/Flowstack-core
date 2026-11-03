<?php

declare(strict_types=1);

/**
 * Järfällas inkorg (?p=inkorg) med ungefär 1000 trådar.
 * Sidan ska ritas på under 3 s och utan en SQL-fråga per rad:
 * inga hela orderpayloads, ingen SELECT * på påminnelser, inga kontakt-get per id.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-ixtime-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('CONTACTS_SOLE=1');
putenv('LEGACY_JSON_WRITES=0');
putenv('DATA_BACKEND=mysql');
putenv('XAI_API_KEY');
$_ENV['CONTACTS_SOLE'] = '1';
$_ENV['LEGACY_JSON_WRITES'] = '0';
$_ENV['DATA_BACKEND'] = 'mysql';
require $root . '/src/bootstrap.php';
require $root . '/src/layout.php';
require_once $root . '/src/kontakt_store.php';

function ix(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['jeab_email'] = 'saljare3@jarfalla.example.test';
$_SESSION['jeab_inkorg_box'] = 'jarfalla';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'co' => 'jarfalla', 'view' => 'v-inkorg'];

$pdo = jeab_pdo_connect_sqlite();
ix($pdo instanceof PDO, 'sqlite är öppen');
ix(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'inkorg_storage=sql');
ix(jeab_settings_put($pdo, 'sqlite', 'kontakter_storage', 'sql'), 'kontakter_storage=sql');
ix(jeab_contacts_sole(), 'CONTACTS_SOLE är på');
ix(jeab_inkorg_use_sql(), 'inkorgen läser SQL');
ix(jeab_purchase_sql_pdo() instanceof PDO, 'ordrar läser SQL');

jeab_purchase_cache_replace([
    '1' => ['reminder_id' => 1, 'email' => 'cache-install@orderkund.se', 'install_date' => '2024-05-01'],
    '2' => ['reminder_id' => 2, 'email' => 'cache-order@orderkund.se', 'install_date' => ''],
]);
unset($GLOBALS['_JEAB_PURCHASE_KIND_MAP']);
$GLOBALS['_JEAB_PURCHASE_LOAD_ALL'] = 0;
$GLOBALS['_JEAB_PURCHASE_KIND_BUILDS'] = 0;
ix(jeab_inkorg_purchase_customer_kind('cache-install@orderkund.se') === 'support_installation', 'cachad installation slår order');
ix(jeab_inkorg_purchase_customer_kind('cache-order@orderkund.se') === 'support_order', 'cachad order utan datum är order');
ix((int) $GLOBALS['_JEAB_PURCHASE_LOAD_ALL'] === 0, 'cachevägen läser inte payload');
jeab_purchase_cache_forget();

$blob = str_repeat('y', 8000);
$ins = $pdo->prepare('INSERT INTO purchases (reminder_id, payload, updated_at) VALUES (?, ?, ?)');
$samples = [
    ['email' => 'install@orderkund.se', 'install_date' => '2024-05-01', 'blob' => $blob],
    ['email' => 'plain@orderkund.se', 'install_date' => '', 'blob' => $blob],
    ['email' => '', 'applicant' => ['email' => 'via-applicant@orderkund.se'], 'install_ymd' => '2024-06-02', 'blob' => $blob],
    ['email' => 'null-date@orderkund.se', 'install_date' => null, 'install_ymd' => '2024-07-03', 'blob' => $blob],
];
foreach ($samples as $i => $row) {
    $ins->execute([$i + 1, json_encode($row, JSON_UNESCAPED_UNICODE), '2026-06-01 10:00:00']);
}
$pdo->prepare('INSERT INTO reminders (list_id, title, email, note, thread_id, flagged, created_at, updated_at) VALUES (1, ?, ?, ?, ?, 0, ?, ?)')
    ->execute(['Kind', 'rem-only@orderkund.se', 'kort', '', '2026-06-01 10:00:00', '2026-06-01 10:00:00']);
unset($GLOBALS['_JEAB_PURCHASE_KIND_MAP'], $GLOBALS['_JEAB_PURCHASE_LIST']);
$GLOBALS['_JEAB_PURCHASE_LOAD_ALL'] = 0;
$GLOBALS['_JEAB_PURCHASE_KIND_BUILDS'] = 0;
ix(jeab_inkorg_purchase_customer_kind('install@orderkund.se') === 'support_installation', 'SQL-utdrag: installation');
ix(jeab_inkorg_purchase_customer_kind('plain@orderkund.se') === 'support_order', 'SQL-utdrag: order');
ix(jeab_inkorg_purchase_customer_kind('via-applicant@orderkund.se') === 'support_installation', 'SQL-utdrag: applicant och install_ymd');
ix(jeab_inkorg_purchase_customer_kind('null-date@orderkund.se') === 'support_installation', 'JSON null i install_date faller till install_ymd');
ix(jeab_inkorg_purchase_customer_kind('rem-only@orderkund.se') === 'support_reminder', 'SQL-utdrag: påminnelse utan order');
ix((int) $GLOBALS['_JEAB_PURCHASE_KIND_BUILDS'] === 1, 'utdraget byggs en gång');
ix((int) $GLOBALS['_JEAB_PURCHASE_LOAD_ALL'] === 0, 'utdraget läser inte hela payloaden, fick ' . (int) $GLOBALS['_JEAB_PURCHASE_LOAD_ALL']);

$contacts = new JeabPdoContactStore($pdo);
for ($i = 0; $i < 40; $i++) {
    $email = 'kund' . $i . '@kundbrev.se';
    $contacts->upsert('c' . $i, [
        'id' => 'c' . $i,
        'name' => 'Eva Tid ' . $i,
        'email' => $email,
        'street' => 'Gatan ' . $i,
        'owners' => [[
            'name' => 'Eva Tid ' . $i,
            'email' => $email,
            'phone' => '0701000' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            'contact' => true,
        ]],
    ]);
}

$types = ['Information', 'Information', 'Ny lead', 'Support', 'Skräp'];
$rows = [];
for ($i = 0; $i < 1000; $i++) {
    $type = $types[$i % 5];
    if ($type === 'Information' && $i % 2 === 0) {
        $email = 'kund' . ($i % 40) . '@kundbrev.se';
    } elseif ($type === 'Information') {
        $email = 'news' . $i . '@nyhetsbrev.se';
    } else {
        $email = 'lead' . $i . '@leadbrev.se';
    }
    $rows[] = [
        'id' => 't' . $i,
        'status' => 'open',
        'company' => 'jarfalla',
        'tags' => [],
        'manual_tags' => [],
        'assignee' => '',
        'read' => [],
        'updated_at' => '2026-06-28T10:00:00+00:00',
        'customer_email' => $email,
        'labels' => ['type' => $type, 'intent' => $type === 'Information' ? 'information' : 'support', 'confidence' => 90],
        'list_snippet' => 'Hej',
        'message_count' => 1,
        'last' => [
            'id' => 'm' . $i,
            'from' => $email,
            'from_name' => 'Avsändare ' . $i,
            'subject' => 'Ärende ' . $i,
            'received_at' => '2026-06-28T10:00:00+00:00',
            'labels' => ['type' => $type, 'intent' => 'information', 'confidence' => 90],
        ],
    ];
}
$store = jeab_inkorg_pdo_store();
ix($store instanceof JeabPdoInkorgStore, 'inkorgslagret är öppet');
$store->replaceListRows($rows);
$store->setListStamp($store->listFingerprint());
ix($store->listStamp() === $store->listFingerprint(), 'stämpeln matchar fingerprint så sidan inte bygger om');

$note = str_repeat('Anteckning utan träff. ', 800);
$rem = $pdo->prepare('INSERT INTO reminders (list_id, title, email, note, thread_id, flagged, created_at, updated_at) VALUES (1, ?, ?, ?, ?, 0, ?, ?)');
$pdo->beginTransaction();
for ($i = 0; $i < 500; $i++) {
    $thread = 't' . $i;
    $body = $note;
    if ($i === 0) {
        $thread = 't-col-1';
        $body .= ' id=not-this';
    } elseif ($i === 1) {
        $thread = '';
        $body .= ' ?id=from-note-only';
    } elseif ($i === 2) {
        $thread = 't-col-2';
    }
    $rem->execute(['R' . $i, 'bulk' . $i . '@paminn.se', $body, $thread, '2026-06-01 10:00:00', '2026-06-01 10:00:00']);
}
$pdo->commit();
$bulk = $pdo->prepare('INSERT INTO purchases (reminder_id, payload, updated_at) VALUES (?, ?, ?)');
$pdo->beginTransaction();
for ($i = 10; $i < 1000; $i++) {
    $bulk->execute([$i, json_encode([
        'email' => 'order' . $i . '@orderkund.se',
        'install_date' => '',
        'blob' => $blob,
    ], JSON_UNESCAPED_UNICODE), '2026-06-01 10:00:00']);
}
$pdo->commit();

unset(
    $GLOBALS['_JEAB_PURCHASE_KIND_MAP'],
    $GLOBALS['_JEAB_PURCHASE_LIST'],
    $GLOBALS['_JEAB_KLASSNING_MEMO'],
    $GLOBALS['_JEAB_KLASSNING_CONTACT_SQL'],
    $GLOBALS['_JEAB_KLASSNING_KNOWN'],
    $GLOBALS['_JEAB_INKORG_SETTINGS_MEM'],
    $GLOBALS['_JEAB_INKORG_SETTINGS_STAMP'],
    $GLOBALS['_JEAB_KONTAKTER_MEM'],
    $GLOBALS['_JEAB_CONTACTS_SOLE_CACHE'],
    $GLOBALS['_JEAB_CONTACTS_SOLE_PARTY'],
    $GLOBALS['_JEAB_REMINDER_THREAD_NARROW']
);
$GLOBALS['_JEAB_PURCHASE_LOAD_ALL'] = 0;
$GLOBALS['_JEAB_PURCHASE_KIND_BUILDS'] = 0;
$GLOBALS['_JEAB_CONTACT_EMAIL_FINDS'] = 0;
$GLOBALS['_JEAB_CONTACTS_SOLE_GETS'] = 0;
$GLOBALS['_JEAB_CONTACTS_SOLE_ID_QUERIES'] = 0;
$GLOBALS['_JEAB_KONTAKTER_LOADS'] = 0;
$GLOBALS['_JEAB_CONTACTS_SQL_ALL'] = 0;
$GLOBALS['_JEAB_INKORG_LIST_REBUILD'] = 0;
jeab_sql_stats_reset();

$t0 = microtime(true);
$rendered = include $root . '/src/pages/inkorg.php';
$html = is_string($rendered) ? $rendered : '';
$elapsed = microtime(true) - $t0;
$sqlN = (int) jeab_sql_stats()['n'];
ix($elapsed < 3, 'sidan ritas på ' . round($elapsed, 3) . ' s');
ix($sqlN < 80, 'sidan gör ' . $sqlN . ' SQL-frågor');
ix(strlen($html) > 1000 && str_contains($html, 'ix-it'), 'listan ritas, ' . strlen($html) . ' tecken');
ix((int) ($GLOBALS['_JEAB_INKORG_LIST_REBUILD'] ?? 0) === 0, 'sidträffen bygger inte om listan');
ix((int) $GLOBALS['_JEAB_CONTACTS_SOLE_GETS'] === 0, 'sidan gör inget get per kontakt');
ix((int) $GLOBALS['_JEAB_KONTAKTER_LOADS'] === 0, 'sidan läser inte hela kontaktregistret');
ix((int) $GLOBALS['_JEAB_PURCHASE_LOAD_ALL'] === 0, 'sidan läser inte hela orderpayloaden');
ix((int) $GLOBALS['_JEAB_PURCHASE_KIND_BUILDS'] === 1, 'orderkartan byggs en gång under sidan, fick ' . (int) $GLOBALS['_JEAB_PURCHASE_KIND_BUILDS']);
ix((int) ($GLOBALS['_JEAB_REMINDER_THREAD_NARROW'] ?? 0) === 0, 'listan läser inte påminnelseraderna');
ix((int) $GLOBALS['_JEAB_CONTACT_EMAIL_FINDS'] >= 1 && (int) $GLOBALS['_JEAB_CONTACT_EMAIL_FINDS'] <= 4, 'kontaktmejlen slås upp ' . (int) $GLOBALS['_JEAB_CONTACT_EMAIL_FINDS'] . ' gånger');
$threads = jeab_inkorg_reminder_thread_set();
ix(isset($threads['t-col-1'], $threads['t-col-2'], $threads['from-note-only']), 'tråd-id och ?id= i anteckningen följer med');
ix(!isset($threads['not-this']), 'id= mitt i en mening blir inte en tråd');

jeab_sql_stats_reset();
$c0 = microtime(true);
$bag = jeab_inkorg_fast_counts_refresh();
$cElapsed = microtime(true) - $c0;
$cSql = (int) jeab_sql_stats()['n'];
ix($cElapsed < 3, 'räknarna fylls på ' . round($cElapsed, 3) . ' s');
ix($cSql < 80, 'räknarna gör ' . $cSql . ' SQL-frågor');
ix((int) ($bag['fresh'] ?? 0) === 1, 'räknarna är färska');
ix((int) $GLOBALS['_JEAB_PURCHASE_LOAD_ALL'] === 0, 'räknarna läser inte hela orderpayloaden');
ix((int) $GLOBALS['_JEAB_KONTAKTER_LOADS'] === 0, 'räknarna läser inte hela kontaktregistret');

$ids = [];
for ($i = 0; $i < 40; $i++) {
    $ids[] = 'c' . $i;
}
$gets = (int) $GLOBALS['_JEAB_CONTACTS_SOLE_GETS'];
$loads = (int) $GLOBALS['_JEAB_KONTAKTER_LOADS'];
$idQueries = (int) $GLOBALS['_JEAB_CONTACTS_SOLE_ID_QUERIES'];
$loaded = jeab_contacts_sole_load_ids($ids);
ix(count($loaded) === 40, '40 kontakter hämtas i en bunt');
ix((int) $GLOBALS['_JEAB_CONTACTS_SOLE_ID_QUERIES'] - $idQueries <= 1, 'bunten är en fråga, fick ' . ((int) $GLOBALS['_JEAB_CONTACTS_SOLE_ID_QUERIES'] - $idQueries));
$paintMiss = '';
foreach ($ids as $id) {
    $view = jeab_contacts_sole_paint_order([
        'reminder_id' => 1,
        'contact_id' => $id,
        'email' => 'stripped@orderkund.se',
        'name' => 'Stripped',
    ]);
    if (($view['name'] ?? '') !== 'Eva Tid ' . substr($id, 1)) {
        $paintMiss = $id . '=' . (string) ($view['name'] ?? '');
        break;
    }
}
ix($paintMiss === '', 'paint läser de 40 kontakterna' . ($paintMiss === '' ? '' : ' (' . $paintMiss . ')'));
ix((int) $GLOBALS['_JEAB_CONTACTS_SOLE_GETS'] === $gets, 'paint efter bunten gör inget get');
ix((int) $GLOBALS['_JEAB_KONTAKTER_LOADS'] === $loads, 'paint efter bunten läser inte hela registret');

$partyLoads = (int) $GLOBALS['_JEAB_KONTAKTER_LOADS'];
$first = jeab_contacts_sole_party_id('kund0@kundbrev.se');
$second = jeab_contacts_sole_party_id('kund1@kundbrev.se');
$again = jeab_contacts_sole_party_id('kund0@kundbrev.se');
ix($first === 'c0' && $second === 'c1' && $again === 'c0', 'party_id träffar samma kontakt');
ix((int) $GLOBALS['_JEAB_KONTAKTER_LOADS'] - $partyLoads === 0, 'party_id slår upp mejlet i indexet, fick ' . ((int) $GLOBALS['_JEAB_KONTAKTER_LOADS'] - $partyLoads) . ' hela läsningar');

jeab_inkorg_quote_preview_save('th-time', '<p>Första</p>');
ix(jeab_inkorg_quote_preview_text('th-time') === 'Första', 'förhandsvisningen läses');
jeab_sql_stats_reset();
ix(jeab_inkorg_quote_preview_text('th-time') === 'Första', 'samma förhandsvisning läses igen');
$peekLog = implode("\n", jeab_sql_stats()['log']);
ix(!str_contains($peekLog, 'inkorg_side_doc'), 'andra läsningen hämtar inte dokumentet igen');
jeab_inkorg_quote_preview_save('th-time', '<p>Andra</p>');
ix(jeab_inkorg_quote_preview_text('th-time') === 'Andra', 'sparning tömmer cachen');

echo "OK  timing page {$elapsed}s sql {$sqlN}; counts {$cElapsed}s sql {$cSql}\n";
