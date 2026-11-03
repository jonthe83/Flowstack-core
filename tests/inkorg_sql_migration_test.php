<?php

declare(strict_types=1);

/**
 * Inkorg JSON → SQL. Flaggan är av tills cutover. Syntetiska rader, ingen kunddata.
 */

$tmp = sys_get_temp_dir() . '/jeab-inkorg-sql-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'inkorg' => ['storage' => 'sql'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function isql(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$migrate = (string) file_get_contents(dirname(__DIR__) . '/scripts/migrate-inkorg-sql.php');
$ht = (string) file_get_contents(dirname(__DIR__) . '/scripts/.htaccess');
isql(str_contains($migrate, "PHP_SAPI !== 'cli'") && str_contains($migrate, "http_response_code(403)") && str_contains($migrate, "echo 'Forbidden'") && !str_contains($migrate, 'exit(403)'), 'migreringen är bara CLI och svarar 403 Forbidden');
isql(str_contains($ht, 'Require all denied'), 'scripts/.htaccess nekar HTTP');

$messages = [];
$threads = [];
for ($i = 0; $i < 1205; $i++) {
    $tid = 'th_' . (int) ($i / 5);
    $note = $i % 10 === 0;
    $messages[] = [
        'id' => 'msg_' . $i,
        'thread_id' => $tid,
        'from' => 'user' . $i . '@example.test',
        'subject' => 'Test ' . $i,
        'received_at' => '2026-01-01 12:' . str_pad((string) ($i % 60), 2, '0', STR_PAD_LEFT) . ':00',
        'body' => 'syntetisk-text',
        'source' => 'test-unit',
        'test' => true,
        'internal_note' => $note,
        'hs_thread_type' => $note ? 'note' : 'customer',
    ];
    if (!isset($threads[$tid])) {
        $threads[$tid] = [
            'status' => 'open',
            'tags' => [],
            'created_at' => '2026-01-01T12:00:00+01:00',
            'updated_at' => '2026-01-01T12:00:00+01:00',
        ];
    }
}
file_put_contents($tmp . '/inkorg/messages.json', json_encode(['messages' => $messages], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/inkorg/threads.json', json_encode(['threads' => $threads], JSON_UNESCAPED_UNICODE));

$noteCount = 0;
foreach ($messages as $row) {
    if (!empty($row['internal_note'])) {
        $noteCount++;
    }
}
$messageCount = count($messages) - $noteCount;

isql(jeab_inkorg_storage_mode() === 'json', 'config storage=sql lämnar flaggan av');

$implicit = jeab_inkorg_sql_migrate_batch(['entity' => 'messages', 'offset' => 0, 'batch_size' => 500]);
isql(($implicit['dry'] ?? false) === true && ($implicit['written'] ?? 1) === 0, 'migrate utan dry skriver inte');

$dryAll = jeab_inkorg_sql_migrate_all(['entity' => 'all', 'batch_size' => 500]);
isql(($dryAll['dry'] ?? false) === true && ($dryAll['written'] ?? 1) === 0, 'all utan dry skriver inte');

$write = jeab_inkorg_sql_migrate_all(['entity' => 'all', 'batch_size' => 500, 'dry' => 0]);
isql(!empty($write['ok']) && !empty($write['done']), 'migrate all dry=0');

$store = new JeabPdoInkorgStore(jeab_inkorg_store_pdo());
isql($store->countMessages(false) === $messageCount, 'meddelanden i SQL');
isql($store->countMessages(true) === $noteCount, 'anteckningar i SQL');
isql($store->countThreads() === count($threads), 'trådar i SQL');

$again = jeab_inkorg_sql_migrate_all(['entity' => 'all', 'batch_size' => 500, 'dry' => 0]);
isql(!empty($again['ok']) && $store->countMessages(false) === $messageCount && $store->countThreads() === count($threads), 'migrate är idempotent');

$verify = jeab_inkorg_sql_verify(['sample' => 5]);
$encoded = json_encode($verify, JSON_UNESCAPED_UNICODE);
isql(!empty($verify['messages_count_match']) && !empty($verify['notes_count_match']) && !empty($verify['threads_count_match']), 'verify räknar tre lager');
isql(!empty($verify['samples_match']) && !empty($verify['match']), 'verify stickprov matchar');
isql(is_string($encoded) && !str_contains($encoded, 'syntetisk-text') && !str_contains($encoded, '@example.test'), 'verify ekar inte radinnehåll');
isql(($verify['storage'] ?? '') === 'json', 'verify ser flaggan av');

isql(count(jeab_inkorg_messages()) === 1205, 'live läser fortfarande JSON');

$preview = jeab_inkorg_sql_cutover(['enable' => 1]);
isql(($preview['dry'] ?? false) === true && jeab_inkorg_storage_mode() === 'json', 'cutover utan dry slår inte på');

$beforePurge = $store->countMessages(false);
isql($store->purgeMissing('inkorg_messages', []) === 0 && $store->countMessages(false) === $beforePurge, 'purge raderar inga mejl');
$sourceNow = jeab_inkorg_sql_source(false);
$firstId = (string) array_key_first((array) ($sourceNow['messages'] ?? []));
$one = $firstId !== '' ? ($store->messagesByIds([$firstId])[$firstId] ?? null) : null;
isql(is_array($one), 'ett mejl finns att avvika');
$one['body'] = 'avviker-fran-json';
$store->upsertMessage($firstId, $one);
$blocked = jeab_inkorg_sql_cutover(['enable' => 1, 'dry' => 0]);
isql(empty($blocked['ok']) && jeab_inkorg_storage_mode() === 'json', 'cutover vägrar när verify inte matchar');

jeab_inkorg_sql_migrate_all(['entity' => 'all', 'batch_size' => 500, 'dry' => 0]);
$on = jeab_inkorg_sql_cutover(['enable' => 1, 'dry' => 0]);
isql(!empty($on['ok']) && jeab_inkorg_storage_mode() === 'sql', 'cutover dry=0 enable=1');

$jsonMtime = filemtime($tmp . '/inkorg/messages.json');
$r = jeab_inkorg_ingest([
    'message_id' => '<batch-sql-test@example.test>',
    'from' => 'batch@example.test',
    'to' => 'inkorg+inkorg@example.test',
    'subject' => 'Ny',
    'body_text' => 'Hej',
    'source' => 'test-unit',
    'test' => true,
]);
isql(!empty($r['ok']), 'ingest via SQL');
isql(filemtime($tmp . '/inkorg/messages.json') === $jsonMtime, 'ingest skriver inte messages.json');
isql($store->countMessages(false) === $messageCount + 1, 'ingest ökar SQL');

$byThread = $store->findMessagesByThread((string) ($r['thread_id'] ?? ''));
isql(count($byThread) >= 1, 'index thread_id');
$byFrom = $store->findMessagesByFrom('batch@example.test');
isql(count($byFrom) >= 1, 'index from_addr');

$back = jeab_inkorg_sql_cutover(['enable' => 0, 'dry' => 0]);
isql(!empty($back['ok']) && jeab_inkorg_storage_mode() === 'json', 'rollback till JSON');
$after = json_decode((string) file_get_contents($tmp . '/inkorg/messages.json'), true);
$ids = [];
foreach (is_array($after) ? (array) ($after['messages'] ?? []) : [] as $row) {
    if (is_array($row)) {
        $ids[] = (string) ($row['id'] ?? '');
    }
}
isql(in_array((string) ($r['id'] ?? ''), $ids, true), 'rollback skrev SQL-raden till JSON');
isql(jeab_inkorg_pdo_store() === null, 'live läser JSON efter rollback');

file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-inkorg-sql']));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-inkorg-sql';
$hook = jeab_inkorg_hook(['action' => 'inbox.sql_migrate', 'entity' => 'messages', 'offset' => 0, 'batch_size' => 100]);
isql(!empty($hook['ok']) && ($hook['dry'] ?? false) === true && ($hook['written'] ?? 1) === 0, 'hook inbox.sql_migrate dry');
$seen = jeab_inkorg_hook(['action' => 'inbox.sql_verify', 'sample' => 3]);
isql(!empty($seen['ok']) && isset($seen['json_notes'], $seen['json_messages'], $seen['json_threads']), 'hook inbox.sql_verify');
$flag = jeab_inkorg_hook(['action' => 'inbox.sql_cutover']);
isql(!empty($flag['ok']) && ($flag['storage'] ?? '') === 'json' && ($flag['changed'] ?? true) === false, 'hook inbox.sql_cutover utan enable');

echo "ok\n";
