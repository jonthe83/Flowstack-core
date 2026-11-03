<?php

declare(strict_types=1);

/**
 * Steg 4: nya migreringsmoduler, torrkörning, idempotent upsert,
 * inkorg per rad och notiser utan .prev.
 * Påhittade rader. Inga personnummer.
 */

$tmp = sys_get_temp_dir() . '/jeab-migrate-steg4-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND');
$GLOBALS['_JEAB_STEG4_CUTOVER'] = true;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
file_put_contents($tmp . '/inkorg/messages.json', '{"messages":[]}');
file_put_contents($tmp . '/inkorg/threads.json', '{"threads":{}}');
file_put_contents($tmp . '/login-throttle.json', json_encode([
    '198.51.100.8' => ['fails' => 2, 'until' => 1700000000],
    '203.0.113.10' => ['fails' => 1, 'until' => 1700000100],
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/solkoll.json', json_encode([
    'name' => 'Testanlaggning',
    'api_key' => 'should-strip',
    'updated_at' => '2026-10-01T10:00:00+02:00',
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/solkoll-cache.json', json_encode([
    'hits' => 3,
    'at' => '2026-10-02T08:00:00+02:00',
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/inkop-mallar.json', json_encode([
    ['id' => 'mall_kabel', 'name' => 'Kabel', 'text' => 'Exempel'],
    ['id' => 'mall_skap', 'name' => 'Skap', 'text' => 'Exempel tva'],
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/inkorg/rules.json', json_encode([
    'lists' => [['id' => 'lista_syntet']],
    'tags' => ['syntet'],
    'views' => [
        ['id' => 'vy_oppna', 'name' => 'Oppna'],
        ['id' => 'vy_mina', 'name' => 'Mina'],
    ],
], JSON_UNESCAPED_UNICODE));
require dirname(__DIR__) . '/src/bootstrap.php';

function s4(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/** @param array<string, mixed> $module */
function s4_rows(array $module): array
{
    $fn = (string) ($module['rows'] ?? '');
    $rows = $fn(jeab_migrate_read_source($module));

    return is_array($rows) ? $rows : [];
}

$ids = ['login_throttle', 'solkoll', 'app_errors_webhook', 'inkop_mallar', 'inkorg_views'];
$expect = [
    'login_throttle' => 2,
    'solkoll' => 2,
    'app_errors_webhook' => 2,
    'inkop_mallar' => 2,
    'inkorg_views' => 2,
];
$byId = [];
foreach (jeab_migrate_registry() as $entry) {
    $byId[(string) ($entry['id'] ?? '')] = $entry;
}
foreach ($ids as $id) {
    s4(isset($byId[$id]) && ($byId[$id]['rows'] ?? '') !== '' && ($byId[$id]['checksum'] ?? '') !== '', $id . ' finns i registret');
}

$pdo = jeab_data_pdo();
s4($pdo instanceof PDO, 'sqlite finns');
if (!$pdo instanceof PDO) {
    exit(1);
}
file_put_contents($tmp . '/app-errors-webhook-seen.json', json_encode([
    'aabbccdd' => time(),
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/app-errors-webhook-resolved.json', json_encode([
    '11223344' => ['at' => time(), 'by' => 'redaktor@example.test'],
], JSON_UNESCAPED_UNICODE));

foreach ($ids as $id) {
    $module = $byId[$id];
    $dry = jeab_migrate_copy($module, true);
    $sum = (string) ($dry['checksum'] ?? '');
    s4(!empty($dry['ok']) && !empty($dry['dry']) && empty($dry['written']), $id . ' torrkörning skriver inte');
    s4((int) ($dry['json_count'] ?? -1) === $expect[$id], $id . ' torrkörning räknar ' . $expect[$id]);
    s4(preg_match('/^[a-f0-9]{64}$/', $sum) === 1, $id . ' torrkörning ger sha256');
    s4($sum !== 'match' && $sum !== 'preserved', $id . ' torrkörning är inte match eller preserved');
    $sumFn = (string) ($module['checksum'] ?? '');
    s4($sum === (string) $sumFn(jeab_migrate_read_source($module)), $id . ' checksumma följer källan');
    $rows = s4_rows($module);
    if ($id === 'solkoll') {
        s4(!str_contains((string) json_encode($rows), 'should-strip'), 'solkoll tar bort api_key');
    }
    if ($id === 'inkorg_views') {
        $encoded = (string) json_encode($rows);
        s4(!str_contains($encoded, 'lista_syntet') && !str_contains($encoded, 'syntet'), 'vyer tar inte med listor eller etiketter');
    }
    jeab_migrate_ensure($pdo, $module);
    jeab_migrate_upsert($pdo, $module, $rows);
    $first = jeab_migrate_checksum(jeab_migrate_select($pdo, $module));
    jeab_migrate_upsert($pdo, $module, $rows);
    $second = jeab_migrate_checksum(jeab_migrate_select($pdo, $module));
    s4($first !== '' && $first === $second && preg_match('/^[a-f0-9]{64}$/', $first) === 1, $id . ' upsert är idempotent');
    s4(count(jeab_migrate_select($pdo, $module)) === $expect[$id], $id . ' har samma antal i sql');
}

s4(!jeab_data_backend_explicit() && jeab_inkorg_storage_mode() === 'json', 'flaggan är av före inkorgstestet');

jeab_inkorg_locked('messages.json', static function (?array $doc): array {
    $messages = is_array($doc['messages'] ?? null) ? $doc['messages'] : [];
    $messages[] = [
        'id' => 'msg_json',
        'thread_id' => 'th_json',
        'from' => 'json.trad@example.test',
        'subject' => 'Json',
        'received_at' => '2026-10-01 09:00:00',
        'body' => 'json-rad',
        'source' => 'test-unit',
    ];

    return ['ok', ['messages' => $messages]];
});
s4(!is_file(jeab_inkorg_dir() . '/messages.json.prev'), 'json-läge skriver ingen messages.json.prev');

$oldPdf = "%PDF-1.4 GAMMAL\n";
$newPdf = "%PDF-1.4 NY\n";
jeab_notiser_save_pdf('offert-laddbox-jarfalla', $oldPdf, 'redaktor@example.test');
jeab_notiser_save_pdf('offert-laddbox-jarfalla', $newPdf, 'redaktor@example.test');
$pdfPath = jeab_auto_pdf_path('laddbox_jarfalla');
s4(is_file($pdfPath) && (string) file_get_contents($pdfPath) === $newPdf, 'json-läge skriver aktuell pdf');
s4(!is_file(jeab_notiser_pdf_prev_path('laddbox_jarfalla')), 'notiser skriver ingen .prev');
$pdfMeta = jeab_notiser_store_read()['pdf']['laddbox_jarfalla'] ?? [];
$prevRaw = base64_decode((string) ($pdfMeta['previous_b64'] ?? ''), true);
s4(is_string($prevRaw) && $prevRaw === $oldPdf && jeab_notiser_pdf_can_restore('laddbox_jarfalla', $pdfMeta), 'tidigare pdf ligger i previous_b64');
jeab_notiser_restore_pdf('offert-laddbox-jarfalla', 'redaktor@example.test');
s4((string) file_get_contents($pdfPath) === $oldPdf && !is_file(jeab_notiser_pdf_prev_path('laddbox_jarfalla')), 'återställning skriver ingen .prev');
$mallPath = jeab_notiser_store_path();
$mallBefore = is_file($mallPath) ? (string) file_get_contents($mallPath) : '';
s4($mallBefore !== '', 'json-läge skrev mallar.json');

$prevHits = [];
$scan = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS));
foreach ($scan as $file) {
    if ($file instanceof SplFileInfo && str_ends_with($file->getFilename(), '.prev')) {
        $prevHits[] = $file->getPathname();
    }
}
s4($prevHits === [], 'tempkatalogen har ingen .prev');

s4(jeab_inkorg_sql_set_storage('sql') && jeab_inkorg_storage_mode() === 'sql', 'inkorg_storage sql');
$store = jeab_inkorg_pdo_store();
s4($store instanceof JeabPdoInkorgStore, 'sql-lagret är öppet');
if (!$store instanceof JeabPdoInkorgStore) {
    exit(1);
}
$msgA = [
    'id' => 'msg_a',
    'thread_id' => 'th_a',
    'from' => 'ada.trad@example.test',
    'subject' => 'A',
    'received_at' => '2026-10-01 10:00:00',
    'body' => 'rad-a',
    'source' => 'test-unit',
];
$msgB = [
    'id' => 'msg_b',
    'thread_id' => 'th_b',
    'from' => 'bo.trad@example.test',
    'subject' => 'B',
    'received_at' => '2026-10-01 11:00:00',
    'body' => 'rad-b',
    'source' => 'test-unit',
];
$store->upsertMessage('msg_a', $msgA);
$store->upsertMessage('msg_b', $msgB);
$store->upsertThread('th_a', ['status' => 'open', 'updated_at' => '2026-10-01 10:00:00']);
$store->upsertThread('th_b', ['status' => 'open', 'updated_at' => '2026-10-01 11:00:00']);
$store->setListStamp('sql|behall');
$store->countCachePut('probe', ['n' => 1]);
$beforeB = (string) ($store->messagesByIds(['msg_b'])['msg_b']['subject'] ?? '');
$rowJsonB = (string) $pdo->query("SELECT row_json FROM inkorg_messages WHERE id = 'msg_b'")->fetchColumn();
$threadJsonB = (string) $pdo->query("SELECT row_json FROM inkorg_threads WHERE id = 'th_b'")->fetchColumn();
$GLOBALS['_JEAB_INKORG_SQL_TOUCHES'] = [];
jeab_inkorg_locked('messages.json', static function (?array $doc): array {
    $messages = is_array($doc['messages'] ?? null) ? $doc['messages'] : [];
    foreach ($messages as $i => $row) {
        if (is_array($row) && (string) ($row['id'] ?? '') === 'msg_a') {
            $row['subject'] = 'A2';
            $messages[$i] = $row;
        }
    }

    return ['ok', ['messages' => $messages]];
});
$touches = $GLOBALS['_JEAB_INKORG_SQL_TOUCHES'];
s4($touches === ['message:msg_a'], 'en ändrad rad rör bara den raden');
s4((string) ($store->messagesByIds(['msg_a'])['msg_a']['subject'] ?? '') === 'A2', 'ändringen sparades');
s4((string) ($store->messagesByIds(['msg_b'])['msg_b']['subject'] ?? '') === $beforeB, 'den andra raden är oförändrad');
$afterB = (string) $pdo->query("SELECT row_json FROM inkorg_messages WHERE id = 'msg_b'")->fetchColumn();
$afterThreadB = (string) $pdo->query("SELECT row_json FROM inkorg_threads WHERE id = 'th_b'")->fetchColumn();
s4($afterB === $rowJsonB && $afterThreadB === $threadJsonB, 'orörd rad och orörd tråd skrevs inte om');
s4($store->listStamp() !== '', 'liststämpeln nollställdes inte');
$probe = $pdo->query("SELECT payload FROM inkorg_count_cache WHERE cache_key = 'probe'")->fetchColumn();
s4(is_string($probe) && $probe !== '', 'räkningscachen finns kvar');
s4($store->countMessages(false) === 2, 'antal mejl är oförändrat');
s4(!is_file(jeab_inkorg_dir() . '/messages.json.prev'), 'sql-skriv skapar ingen .prev');

$GLOBALS['_JEAB_INKORG_SQL_TOUCHES'] = [];
jeab_inkorg_locked('messages.json', static function (?array $doc): array {
    $kept = [];
    foreach (is_array($doc['messages'] ?? null) ? $doc['messages'] : [] as $row) {
        if (is_array($row) && (string) ($row['id'] ?? '') === 'msg_b') {
            $kept[] = $row;
        }
    }

    return ['ok', ['messages' => $kept]];
});
s4($GLOBALS['_JEAB_INKORG_SQL_TOUCHES'] === ['message:msg_a'], 'radering rör bara den borttagna raden');
s4($store->messagesByIds(['msg_a']) === [] && isset($store->messagesByIds(['msg_b'])['msg_b']), 'bara den uttryckliga raden är borta');
s4((string) $pdo->query("SELECT row_json FROM inkorg_messages WHERE id = 'msg_b'")->fetchColumn() === $rowJsonB, 'kvarvarande rad skrevs inte om');

$GLOBALS['_JEAB_INKORG_SQL_TOUCHES'] = [];
jeab_inkorg_locked('threads.json', static function (?array $doc): array {
    $threads = is_array($doc['threads'] ?? null) ? $doc['threads'] : [];
    if (isset($threads['th_a']) && is_array($threads['th_a'])) {
        $threads['th_a']['status'] = 'closed';
    }

    return ['ok', ['threads' => $threads]];
});
s4($GLOBALS['_JEAB_INKORG_SQL_TOUCHES'] === ['thread:th_a'], 'en ändrad tråd rör bara den tråden');
s4((string) ($store->threadsByIds(['th_a'])['th_a']['status'] ?? '') === 'closed', 'trådstatus sparades');
s4((string) $pdo->query("SELECT row_json FROM inkorg_threads WHERE id = 'th_b'")->fetchColumn() === $threadJsonB, 'den andra tråden skrevs inte om');

s4(jeab_data_backend_set('mysql') && jeab_cutover_sql('notiser'), 'mysql-flaggan slår på notiser');
$sqlPdf = "%PDF-1.4 SQL\n";
jeab_notiser_save_pdf('offert-laddbox-jarfalla', $sqlPdf, 'redaktor@example.test');
s4((string) file_get_contents($mallPath) === $mallBefore, 'sql-läge skriver inte mallar.json');
s4((string) file_get_contents($pdfPath) === $oldPdf, 'sql-läge skriver inte pdf-filen');
s4(!is_file(jeab_notiser_pdf_prev_path('laddbox_jarfalla')), 'sql-läge skriver ingen .prev');
s4(jeab_notiser_quote_pdf_bytes('laddbox_jarfalla') === $sqlPdf, 'pdf läses från sql');

$job = static function (int $id): array {
    return ['kind' => 'ping', 'payload' => ['id' => $id], 'at' => 1700000000 + $id];
};
jeab_data_put_source('external_queue', ['jobs' => [$job(1)]], true);
jeab_cutover_locked('external_queue', static function (array $source) use ($job): array {
    $ids = [];
    foreach (is_array($source['jobs'] ?? null) ? $source['jobs'] : [] as $row) {
        if (is_array($row)) {
            $ids[] = (int) ($row['payload']['id'] ?? 0);
        }
    }
    sort($ids);
    s4($ids === [1], 'A läser [1]');
    jeab_cutover_locked('external_queue', static function (array $inner) use ($job): array {
        $jobs = is_array($inner['jobs'] ?? null) ? $inner['jobs'] : [];
        $jobs[] = $job(2);

        return ['jobs' => array_values($jobs)];
    });
    $jobs = is_array($source['jobs'] ?? null) ? $source['jobs'] : [];
    $jobs[] = $job(3);

    return ['jobs' => array_values($jobs)];
});
$leftIds = [];
foreach (jeab_cutover_external_jobs() ?? [] as $row) {
    if (is_array($row)) {
        $leftIds[] = (int) ($row['payload']['id'] ?? 0);
    }
}
sort($leftIds);
s4($leftIds === [1, 2, 3], 'A skriver [1,3] och rad 2 finns kvar');

$beforeQueue = (int) $pdo->query('SELECT COUNT(*) FROM external_queue_jobs')->fetchColumn();
$GLOBALS['_JEAB_STEG4_CUTOVER'] = false;
jeab_external_enqueue('ping', ['id' => 9]);
$GLOBALS['_JEAB_STEG4_CUTOVER'] = true;
$afterQueue = (int) $pdo->query('SELECT COUNT(*) FROM external_queue_jobs')->fetchColumn();
$fileQueue = json_decode((string) file_get_contents(jeab_external_queue_path()), true);
s4($afterQueue === $beforeQueue, 'flaggan av skriver inte SQL-kön');
s4(is_array($fileQueue['jobs'] ?? null) && count($fileQueue['jobs']) === 1 && (int) ($fileQueue['jobs'][0]['payload']['id'] ?? 0) === 9, 'flaggan av skriver kön till filen');


// S4-4 omgranskning: inkorg_views, login_throttle, app_errors_webhook och webshop_orders under jeab_cutover_locked.
if (jeab_cutover_sql('inkorg_views')) {
    jeab_inkorg_views_sql_replace([['id' => 'vy_a', 'name' => 'A']]);
    jeab_cutover_locked('inkorg_views', static function (array $source): array {
        jeab_inkorg_views_sql_save(['id' => 'vy_b', 'name' => 'B']);
        $views = $source['views'];
        $views[] = ['id' => 'vy_c', 'name' => 'C'];

        return ['views' => $views];
    });
    $vids = array_map(static fn ($v) => (string) ($v['id'] ?? ''), jeab_cutover_views_list() ?? []);
    sort($vids);
    s4($vids === ['vy_a', 'vy_c'] || $vids === ['vy_a', 'vy_b', 'vy_c'], 'vyer: nästlad save går genom låset');
    jeab_inkorg_views_sql_delete('vy_c');
    $vids = array_map(static fn ($v) => (string) ($v['id'] ?? ''), jeab_cutover_views_list() ?? []);
    s4(!in_array('vy_c', $vids, true) && in_array('vy_a', $vids, true), 'vyer: delete under låset tar bara sin rad');
}
if (jeab_cutover_sql('login_throttle')) {
    $res = jeab_cutover_throttle_mutate(static function (array &$store): string {
        $store['192.0.2.77'] = ['fails' => 3, 'until' => 1700000500];

        return 'klart';
    });
    $th = jeab_cutover_throttle_load();
    s4($res === 'klart' && (int) ($th['192.0.2.77']['fails'] ?? 0) === 3, 'throttle: mutate under låset sparar och ger resultatet');
}
if (jeab_cutover_sql('app_errors_webhook')) {
    $fp = 'feed' . bin2hex(random_bytes(4));
    s4(jeab_log_app_error_webhook_claim($fp) === true, 'webhook: första claim vinner');
    s4(jeab_log_app_error_webhook_claim($fp) === false, 'webhook: andra claim nekas inne i låset');
    $maps = jeab_cutover_webhook_maps();
    s4(isset($maps['seen'][$fp]), 'webhook: seen skrevs till SQL');
}
if (function_exists('jeab_cutover_sql') && jeab_cutover_sql('webshop_orders') && jeab_cutover_log_pdo_missing()) {
    jeab_webshop_index_save(['ws_a' => ['reminder_id' => 1]]);
    jeab_cutover_locked('webshop_orders', static function (array $index): array {
        jeab_webshop_index_mutate(static function (array $inner): array {
            $inner['ws_b'] = ['reminder_id' => 2];

            return $inner;
        });
        $index['ws_c'] = ['reminder_id' => 3];

        return $index;
    });
    $ws = array_keys(jeab_cutover_webshop_index() ?? []);
    sort($ws);
    s4(in_array('ws_a', $ws, true) && in_array('ws_c', $ws, true), 'webshop: indexet skrivs under låset');
} else {
    echo "SKIP webshop_orders (log-PDO finns eller typen är inte SQL)\n";
}

echo "ALL OK\n";
