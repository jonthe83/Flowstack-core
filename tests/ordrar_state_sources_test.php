<?php

declare(strict_types=1);

/**
 * ordrar.state_sources, ordrar.get och ordrar.rebuild_state.
 * Census-ögonblicksbilden saknar läge. Kroken ska hitta läget i filer
 * (även trash och filer över 2 MB), SQL, inkorg, påminnelse, kalender
 * och Fortnox-mappning, utan personuppgifter.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-ord-sources-' . bin2hex(random_bytes(4));
mkdir($tmp . '/.private/backups', 0775, true);
mkdir($tmp . '/.private/trash/2026-11-01', 0775, true);
mkdir($tmp . '/.private/trash/2026-10-15', 0775, true);
mkdir($tmp . '/data_cutover', 0775, true);
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('CONTACTS_SOLE=1');
putenv('DATA_BACKEND');
$_ENV['CONTACTS_SOLE'] = '1';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
file_put_contents($tmp . '/inkorg/messages.json', '{"messages":[]}');
file_put_contents($tmp . '/inkorg/threads.json', '{"threads":[]}');

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/ordrar_restore_state.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['jeab_email'] = 'demo@example.test';
$_COOKIE['jeab_auth'] = '';

function oss_state(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
foreach (['ordrar.state_sources', 'ordrar.get', 'ordrar.rebuild_state'] as $action) {
    oss_state(str_contains($hook, "'" . $action . "'"), 'hook tar ' . $action);
}
$src = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar_state_sources.php')
    . (string) file_get_contents(dirname(__DIR__) . '/src/ordrar_restore_state.php');
oss_state(preg_match('/jeab_(send_|sms_|fortnox|graph_|mail_)/', $src) !== 1, 'källsökningen anropar inget utskick');
oss_state(!str_contains($src, '2000000') && !str_contains($src, '2_000_000'), '2 MB-taket är borta');

$store = jeab_store();
oss_state($store instanceof JeabPdoStore, 'lagret är öppet');
$GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
$pdo = $store->pdo();
jeab_purchases_store_ensure($pdo);
$customer = $store->insertCustomer('Ada Lista', '2026-10-01 08:00:00');
$book = static function (string $contact) use ($store, $customer): int {
    $row = $store->insertBooking([
        'customer_id' => $customer['id'],
        'customer_name' => 'Ada Lista',
        'email' => 'ada.lista@example.test',
        'phone' => '0700000000',
        'assignees' => ['Kim'],
        'company' => 'malmo',
        'job_type' => 'laddbox',
        'starts_at' => '2026-11-02 08:00:00',
        'ends_at' => '2026-11-02 12:00:00',
        'status' => 'booked',
        'contact_id' => $contact,
        'created_at' => '2026-10-01 08:00:00',
        'updated_at' => '2026-10-01 08:00:00',
    ]);

    return (int) ($row['id'] ?? 0);
};
$book319 = $book('kt_319');
$book320 = $book('kt_320');
oss_state($book319 > 0 && $book320 > 0, 'bokningarna finns');
$bookingCols = jeab_ordrar_restore_state_columns($pdo, 'bookings');
if (!isset($bookingCols['order_id'])) {
    $pdo->exec('ALTER TABLE bookings ADD COLUMN order_id INTEGER');
}
$pdo->prepare('UPDATE bookings SET order_id = ? WHERE id = ?')->execute([320, $book320]);

$thin = static function (int $id, string $contact) use ($pdo): void {
    jeab_sql_purchases_put($pdo, [
        'reminder_id' => $id,
        'contact_id' => $contact,
        'company' => 'malmo',
        'kind' => 'laddbox',
        'step' => 1,
        'status' => 'open',
        'created_at' => '2026-10-01 08:00:00',
        'updated_at' => '2026-10-01 08:00:00',
    ]);
};
$thin(319, 'kt_319');
$thin(320, 'kt_320');
jeab_ordrar_restore_ensure_log($pdo);
$log = $pdo->prepare('INSERT INTO order_restore_log (undo_id, reminder_id, contact_id, kind, order_created_at, step, status, booking_id, undone, entity, entity_key) VALUES (?, ?, ?, ?, ?, 1, ?, 0, 0, ?, ?)');
$log->execute(['log-sources', 319, 'kt_319', 'laddbox', '2026-10-01 08:00:00', 'open', 'order', '']);
$log->execute(['log-sources', 320, 'kt_320', 'laddbox', '2026-10-01 08:00:00', 'open', 'order', '']);

$undoPdo = jeab_contacts_sole_undo_pdo($store);
oss_state($undoPdo instanceof PDO, 'ångerloggen går att öppna');
$census = [
    'ids' => ['orders' => [319, 320]],
    'links' => ['orders' => ['319' => 'kt_319', '320' => 'kt_320']],
];
$undoPdo->prepare('INSERT INTO contacts_sole_undo (batch_id, seq, kind, entity, entity_id, before_json, after_json, created_at, undone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)')
    ->execute(['batchsources', 1, 'snapshot', 'census', 'batchsources', json_encode($census, JSON_UNESCAPED_UNICODE), '{}', '2026-10-01 09:00:00']);
$seal = static fn (): array => $undoPdo->query("SELECT id, before_json, undone FROM contacts_sole_undo WHERE kind = 'snapshot' ORDER BY id")->fetchAll();
$sealBefore = $seal();

$rich = static function (int $id, string $contact, int $booking, string $install, string $fortnox, string $at): array {
    return [
        'reminder_id' => $id,
        'contact_id' => $contact,
        'co' => 'malmo',
        'product' => 'laddbox',
        'step' => 4,
        'status' => 'open',
        'booking_id' => $booking,
        'install_date' => $install,
        'fortnox_order' => $fortnox,
        'pris' => 18900,
        'kundpris' => 19900,
        'steps' => ['bokad_tid' => $install . ' 08:00:00'],
        'rows' => [['article' => 'BOX', 'qty' => '1', 'price' => '18900']],
        'kalkyl' => [['article' => 'BOX', 'price' => '18900']],
        'name' => 'Hemlig Kalla',
        'email' => 'secret-source@example.test',
        'updated_at' => $at,
    ];
};
file_put_contents($tmp . '/order-319-public.json', json_encode([
    'purchases' => ['319' => $rich(319, 'kt_319', $book319, '2026-10-05', '100319', '2026-10-05 08:00:00')],
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/.private/trash/2026-10-15/order-319.json', json_encode([
    'purchases' => ['319' => $rich(319, 'kt_319', $book319, '2026-10-15', '200319', '2026-10-15 08:00:00')],
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/.private/trash/2026-11-01/order-319.json', json_encode([
    'purchases' => ['319' => $rich(319, 'kt_319', $book319, '2026-11-02', '770319', '2026-11-01 18:00:00')],
], JSON_UNESCAPED_UNICODE));
$older = $rich(319, 'kt_319', $book319, '2026-09-01', '900319', '2026-09-01 08:00:00');
$wide = '{"pad":"' . str_repeat('x', 2100000) . '","purchases":{"319":' . json_encode($older, JSON_UNESCAPED_UNICODE) . '}}';
file_put_contents($tmp . '/.private/backups/wide-319.json', $wide);
oss_state(filesize($tmp . '/.private/backups/wide-319.json') > 2000000, 'testfilen är större än 2 MB');
file_put_contents($tmp . '/data_cutover/purchases.json', json_encode([
    'table' => 'purchases',
    'rows' => [[
        'reminder_id' => 319,
        'updated_at' => '2026-10-20 08:00:00',
        'payload' => json_encode($rich(319, 'kt_319', $book319, '2026-10-20', '300319', '2026-10-20 08:00:00'), JSON_UNESCAPED_UNICODE),
    ]],
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/inkorg/order-319.json', json_encode([
    'messages' => [[
        'reminder_id' => 319,
        'step' => 2,
        'updated_at' => '2026-10-03 08:00:00',
        'email' => 'secret-source@example.test',
    ]],
], JSON_UNESCAPED_UNICODE));

if (!jeab_ordrar_restore_state_table($pdo, 'purchases_history')) {
    $pdo->exec('CREATE TABLE purchases_history (id INTEGER PRIMARY KEY AUTOINCREMENT, reminder_id INTEGER NOT NULL, payload TEXT NOT NULL, updated_at TEXT NOT NULL)');
}
if (!jeab_ordrar_restore_state_table($pdo, 'order_audit')) {
    $pdo->exec('CREATE TABLE order_audit (id INTEGER PRIMARY KEY AUTOINCREMENT, reminder_id INTEGER NOT NULL, payload TEXT NOT NULL, created_at TEXT NOT NULL)');
}
if (!jeab_ordrar_restore_state_table($pdo, 'purchase_store')) {
    $pdo->exec('CREATE TABLE purchase_store (id INTEGER PRIMARY KEY AUTOINCREMENT, reminder_id INTEGER NOT NULL, payload TEXT NOT NULL, updated_at TEXT NOT NULL)');
}
if (!jeab_ordrar_restore_state_table($pdo, 'fortnox_order_audit') && function_exists('jeab_ensure_fortnox_order_audit')) {
    jeab_ensure_fortnox_order_audit($pdo, 'sqlite');
}
if (!jeab_ordrar_restore_state_table($pdo, 'inkorg_messages')) {
    $pdo->exec('CREATE TABLE inkorg_messages (
        id TEXT PRIMARY KEY,
        thread_id TEXT NOT NULL DEFAULT \'\',
        received_at TEXT,
        from_addr TEXT NOT NULL DEFAULT \'\',
        is_note INTEGER NOT NULL DEFAULT 0,
        row_json TEXT NOT NULL
    )');
}
$pdo->prepare('INSERT INTO purchases_history (reminder_id, payload, updated_at) VALUES (?, ?, ?)')
    ->execute([319, json_encode($rich(319, 'kt_319', $book319, '2026-10-01', '400319', '2026-10-01 12:00:00'), JSON_UNESCAPED_UNICODE), '2026-10-01 12:00:00']);
$pdo->prepare('INSERT INTO order_audit (reminder_id, payload, created_at) VALUES (?, ?, ?)')
    ->execute([319, json_encode(['reminder_id' => 319, 'co' => 'malmo', 'fortnox_order' => '500319', 'updated_at' => '2026-10-02 08:00:00'], JSON_UNESCAPED_UNICODE), '2026-10-02 08:00:00']);
$pdo->prepare('INSERT INTO purchase_store (reminder_id, payload, updated_at) VALUES (?, ?, ?)')
    ->execute([319, json_encode($rich(319, 'kt_319', $book319, '2026-10-28', '600319', '2026-10-28 08:00:00'), JSON_UNESCAPED_UNICODE), '2026-10-28 08:00:00']);
$pdo->prepare('INSERT INTO fortnox_order_audit (created_at, reminder_id, fortnox_order, reason, message) VALUES (?, ?, ?, ?, ?)')
    ->execute(['2026-11-01 09:00:00', 320, '880320', 'map', 'secret-map@example.test']);
$pdo->prepare('INSERT INTO inkorg_messages (id, thread_id, received_at, from_addr, is_note, row_json) VALUES (?, ?, ?, ?, 0, ?)')
    ->execute(['m319', 't319', '2026-10-03 07:00:00', 'secret-source@example.test', json_encode([
        'reminder_id' => 319,
        'step' => 2,
        'updated_at' => '2026-10-03 07:00:00',
        'email' => 'secret-source@example.test',
    ], JSON_UNESCAPED_UNICODE)]);
$pdo->prepare('INSERT INTO reminders (id, list_id, title, company, contact_id, note, created_at, updated_at) VALUES (?, 1, ?, ?, ?, ?, ?, ?)')
    ->execute([319, 'Hemlig Kalla', 'malmo', 'kt_319', json_encode([
        'fortnox_order' => '700319',
        'step' => 3,
        'email' => 'secret-source@example.test',
    ], JSON_UNESCAPED_UNICODE), '2026-10-04 08:00:00', '2026-10-04 08:00:00']);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = '';
$denied = jeab_ordrar_state_sources_dispatch($store, ['action' => 'ordrar.get', 'id' => 319]);
oss_state(empty($denied['ok']) && (int) ($denied['status'] ?? 0) === 401, 'ordrar.get utan token är 401');
$deniedSources = jeab_ordrar_state_sources_dispatch($store, ['action' => 'ordrar.state_sources', 'ids' => [319]]);
oss_state(empty($deniedSources['ok']) && (int) ($deniedSources['status'] ?? 0) === 401, 'state_sources utan token är 401');

$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-state-sources']));
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = 'tok-state-sources';

$missing = jeab_ordrar_state_sources_dispatch($store, ['action' => 'ordrar.get', 'id' => 999]);
oss_state(empty($missing['ok']) && (int) ($missing['status'] ?? 0) === 404, 'ordrar.get saknad order är 404');

$report = jeab_ordrar_state_sources_dispatch($store, ['action' => 'ordrar.state_sources', 'ids' => [319, 320]]);
oss_state(!empty($report['ok']) && (int) ($report['sent'] ?? 1) === 0 && (int) ($report['fortnox'] ?? 1) === 0, 'state_sources skickar inget');
$reportJson = json_encode($report, JSON_UNESCAPED_UNICODE);
oss_state(is_string($reportJson) && !str_contains($reportJson, 'Hemlig') && !str_contains($reportJson, 'secret-source') && !str_contains($reportJson, 'secret-map') && !str_contains($reportJson, 'ada.lista'), 'state_sources saknar personuppgifter');
$byId = [];
foreach (is_array($report['orders'] ?? null) ? $report['orders'] : [] as $row) {
    if (is_array($row)) {
        $byId[(int) ($row['id'] ?? 0)] = $row;
    }
}
$names = static function (array $order): array {
    $out = [];
    foreach (is_array($order['sources'] ?? null) ? $order['sources'] : [] as $source) {
        if (is_array($source)) {
            $out[(string) ($source['name'] ?? '')][] = $source;
        }
    }

    return $out;
};
$src319 = $names($byId[319] ?? []);
foreach (['file', 'trash', 'backup', 'cutover', 'purchases_history', 'audit', 'purchase_store', 'inkorg', 'reminder', 'snapshot'] as $name) {
    oss_state(isset($src319[$name]), '319 har källan ' . $name . ', fick ' . json_encode(array_keys($src319)));
}
$trashPaths = array_map(static fn (array $row): string => (string) ($row['path'] ?? ''), $src319['trash'] ?? []);
oss_state(in_array('.private/trash/2026-11-01/order-319.json', $trashPaths, true), 'trash 2026-11-01 finns, fick ' . json_encode($trashPaths));
oss_state(in_array('.private/trash/2026-10-15/order-319.json', $trashPaths, true), 'äldre trash finns');
$wideHit = null;
foreach ($src319['backup'] ?? [] as $source) {
    if (str_contains((string) ($source['path'] ?? ''), 'wide-319.json')) {
        $wideHit = $source;
    }
}
oss_state(is_array($wideHit) && in_array('step', $wideHit['keys'] ?? [], true) && in_array('fortnox_order', $wideHit['keys'] ?? [], true), 'fil över 2 MB läses i bitar');
$best319 = is_array($byId[319]['best'] ?? null) ? $byId[319]['best'] : [];
oss_state((string) ($best319['name'] ?? '') === 'trash' && (string) ($best319['at'] ?? '') === '2026-11-01 18:00:00', 'bästa källan är nyaste trash, fick ' . json_encode($best319));
foreach (['step', 'steps', 'bokad_tid', 'install_date', 'fortnox_order', 'pris', 'kundpris', 'rows', 'kalkyl', 'co'] as $key) {
    oss_state(in_array($key, $best319['keys'] ?? [], true), 'bästa källan har ' . $key);
}
$src320 = $names($byId[320] ?? []);
$snap320 = $src320['snapshot'][0] ?? [];
oss_state(($snap320['keys'] ?? null) === [] && (string) ($snap320['path'] ?? '') === 'contacts_sole_undo', 'census har inga lägesnycklar');
oss_state(isset($src320['calendar']) && in_array('bokad_tid', $src320['calendar'][0]['keys'] ?? [], true) && in_array('install_date', $src320['calendar'][0]['keys'] ?? [], true), 'kalendern rapporterar bokad tid');
oss_state(isset($src320['fortnox']) && ($src320['fortnox'][0]['path'] ?? '') === 'fortnox_order_audit' && ($src320['fortnox'][0]['keys'] ?? []) === ['fortnox_order'], 'Fortnox-mappningen rapporterar bara nyckeln');
oss_state(($byId[320]['best'] ?? null) === null, '320 har ingen lagrad källa med läge');

$dryRestore = jeab_ordrar_restore_state_dispatch($store, ['action' => 'ordrar.restore_state_from_snapshot', 'ids' => [319, 320]]);
$restored = [];
foreach (is_array($dryRestore['orders'] ?? null) ? $dryRestore['orders'] : [] as $row) {
    if (is_array($row)) {
        $restored[(int) ($row['id'] ?? 0)] = $row;
    }
}
oss_state((string) ($restored[319]['source'] ?? '') === 'trash' && (string) ($restored[319]['source_file'] ?? '') === 'order-319.json', 'återställning tar trash, fick ' . json_encode($restored[319]['source'] ?? null));
oss_state((string) ($restored[319]['diff']['fortnox_order']['to'] ?? '') === '770319', 'återställning tar fakturan från nyaste trash');
oss_state((string) ($restored[320]['reason'] ?? '') === 'no_state', '320 saknar lagrat läge');
oss_state((string) (jeab_purchase_get(319)['fortnox_order'] ?? '') === '', 'dry återställning skriver inte');

$blocked = jeab_ordrar_state_sources_dispatch($store, ['action' => 'ordrar.rebuild_state', 'id' => 319, 'dry' => 0]);
oss_state((string) ($blocked['reason'] ?? '') === 'has_state' && (string) (jeab_purchase_get(319)['fortnox_order'] ?? '') === '', 'rebuild rör inte en order som har en källa');

$rebuildDry = jeab_ordrar_state_sources_dispatch($store, ['action' => 'ordrar.rebuild_state', 'id' => 320]);
oss_state((int) ($rebuildDry['dry'] ?? 0) === 1 && (int) ($rebuildDry['sent'] ?? 1) === 0 && (int) ($rebuildDry['mail'] ?? 1) === 0 && (int) ($rebuildDry['sms'] ?? 1) === 0 && (int) ($rebuildDry['fortnox'] ?? 1) === 0 && (int) ($rebuildDry['calendar'] ?? 1) === 0, 'rebuild dry skickar inget');
oss_state((string) ($rebuildDry['diff']['fortnox_order']['to'] ?? '') === '880320', 'rebuild dry visar Fortnox-numret');
oss_state((string) ($rebuildDry['diff']['install_date']['to'] ?? '') === '2026-11-02', 'rebuild dry visar installationen');
oss_state((string) ($rebuildDry['diff']['booking_id']['to'] ?? '') === (string) $book320, 'rebuild dry visar bokningen');
oss_state((string) ($rebuildDry['diff']['bokad_tid']['to'] ?? '') === '2026-11-02', 'rebuild dry visar bokad tid');
oss_state((string) (jeab_purchase_get(320)['fortnox_order'] ?? '') === '', 'rebuild dry skriver inte');
$rebuildJson = json_encode($rebuildDry, JSON_UNESCAPED_UNICODE);
oss_state(is_string($rebuildJson) && !str_contains($rebuildJson, 'secret-map') && !str_contains($rebuildJson, 'Ada'), 'rebuild dry saknar personuppgifter');

$rebuilt = jeab_ordrar_state_sources_dispatch($store, ['action' => 'ordrar.rebuild_state', 'id' => 320, 'dry' => 0]);
oss_state(!empty($rebuilt['ok']) && (int) ($rebuilt['sent'] ?? 1) === 0 && (int) ($rebuilt['fortnox'] ?? 1) === 0 && (int) ($rebuilt['calendar'] ?? 1) === 0, 'rebuild våt skickar inget');
$undoId = (string) ($rebuilt['undo_id'] ?? '');
oss_state(preg_match('/\A[a-f0-9]{32}\z/', $undoId) === 1, 'rebuild ger undo_id');
if (function_exists('jeab_purchase_cache_forget')) {
    jeab_purchase_cache_forget();
}
$order320 = jeab_purchase_get(320);
oss_state(is_array($order320) && (string) ($order320['fortnox_order'] ?? '') === '880320' && (int) ($order320['booking_id'] ?? 0) === $book320, '320 fick faktura och bokning');
oss_state((string) ($order320['install_date'] ?? '') === '2026-11-02', '320 fick installationsdatum');
$progress = jeab_ordrar_v3_progress($order320, $store);
oss_state((string) ($progress['label'] ?? '') === 'Fakturering' && (int) ($progress['done'] ?? 0) === 1 && (int) ($progress['total'] ?? 0) === 2, '320 står på Fakturering 1 av 2, fick ' . json_encode([$progress['label'] ?? '', $progress['done'] ?? 0, $progress['total'] ?? 0]));
$got = jeab_ordrar_state_sources_dispatch($store, ['action' => 'ordrar.get', 'id' => 320]);
oss_state(!empty($got['ok']) && (string) ($got['fortnox_order'] ?? '') === '880320' && (int) ($got['price'] ?? -1) === 0 && (string) ($got['company'] ?? '') === 'malmo' && (int) ($got['booking_id'] ?? 0) === $book320 && (string) ($got['install_date'] ?? '') === '2026-11-02', 'ordrar.get visar bara läget, fick ' . json_encode($got));
$gotJson = json_encode($got, JSON_UNESCAPED_UNICODE);
oss_state(is_string($gotJson) && !str_contains($gotJson, 'secret') && !str_contains($gotJson, 'Ada') && !str_contains($gotJson, '0700'), 'ordrar.get saknar personuppgifter');
oss_state($seal() == $sealBefore, 'frysta ögonblicksbilder är orörda');

$undone = jeab_ordrar_restore_state_dispatch($store, ['action' => 'ordrar.restore_state_undo', 'undo_id' => $undoId, 'dry' => 0, 'confirm' => 'undo']);
oss_state(!empty($undone['ok']) && (int) ($undone['sent'] ?? 1) === 0, 'ångern skickar inget');
if (function_exists('jeab_purchase_cache_forget')) {
    jeab_purchase_cache_forget();
}
$back = jeab_purchase_get(320);
oss_state(is_array($back) && (string) ($back['fortnox_order'] ?? '') === '' && (int) ($back['booking_id'] ?? 0) === 0, 'ångern tar tillbaka det härledda läget');
oss_state($seal() == $sealBefore, 'ångern rör inte frysta ögonblicksbilder');

mkdir($tmp . '/.private/trash/2026-10-31', 0775, true);
$thin(321, 'kt_321');
$log->execute(['log-sources', 321, 'kt_321', 'laddbox', '2026-10-01 08:00:00', 'open', 'order', '']);
file_put_contents($tmp . '/.private/trash/2026-10-31/purchases.json', json_encode([
    'purchases' => ['321' => $rich(321, 'kt_321', $book319, '2026-11-02', '777321', '2026-10-31 22:10:00')],
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/purchases.json.bak', json_encode([
    'purchases' => ['321' => $rich(321, 'kt_321', $book319, '2026-11-02', '666321', '2026-10-31 21:40:00')],
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/order-321-later.json', json_encode([
    'purchases' => ['321' => $rich(321, 'kt_321', $book319, '2026-11-03', '111321', '2026-11-01 20:00:00')],
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/data_cutover/purchases-dump.sql', "INSERT INTO purchases VALUES (321, '{\"reminder_id\":321,\"co\":\"malmo\",\"step\":4,\"fortnox_order\":\"555321\",\"updated_at\":\"2026-10-31 21:10:00\"}');\n");
$win = jeab_ordrar_state_sources_dispatch($store, ['action' => 'ordrar.state_sources', 'ids' => [321]]);
$winOrder = [];
foreach (is_array($win['orders'] ?? null) ? $win['orders'] : [] as $row) {
    if (is_array($row) && (int) ($row['id'] ?? 0) === 321) {
        $winOrder = $row;
    }
}
$winBest = is_array($winOrder['best'] ?? null) ? $winOrder['best'] : [];
oss_state((string) ($winBest['name'] ?? '') === 'trash' && str_contains((string) ($winBest['path'] ?? ''), 'trash/2026-10-31') && (int) ($winBest['window'] ?? 0) === 1, 'fönstret vinner över en nyare rad, fick ' . json_encode($winBest));
$winNames = $names($winOrder);
oss_state(isset($winNames['bak']) && (int) ($winNames['bak'][0]['window'] ?? 0) === 1, 'purchases.json.bak i fönstret räknas');
oss_state(isset($winNames['cutover']) || isset($winNames['sql']), 'SQL-dumpen i data_cutover hittas, fick ' . json_encode(array_keys($winNames)));
$later = null;
foreach ($winNames['file'] ?? [] as $source) {
    if (str_contains((string) ($source['path'] ?? ''), 'order-321-later.json')) {
        $later = $source;
    }
}
oss_state(is_array($later) && (int) ($later['window'] ?? 1) === 0, 'en rad efter 1 nov 17:34 är inte fönstret');
$winRestore = jeab_ordrar_restore_state_dispatch($store, ['ids' => [321]]);
$winRow = [];
foreach (is_array($winRestore['orders'] ?? null) ? $winRestore['orders'] : [] as $row) {
    if (is_array($row) && (int) ($row['id'] ?? 0) === 321) {
        $winRow = $row;
    }
}
oss_state((string) ($winRow['source'] ?? '') === 'trash' && (string) ($winRow['diff']['fortnox_order']['to'] ?? '') === '777321', 'återställning tar trash från 31 okt, inte den nyare raden, fick ' . json_encode($winRow['diff']['fortnox_order'] ?? null));

$book322 = $book('kt_322');
$pdo->prepare('UPDATE bookings SET order_id = ?, starts_at = ?, ends_at = ? WHERE id = ?')->execute([322, '2026-11-06 09:30:00', '2026-11-06 12:00:00', $book322]);
$thin(322, 'kt_322');
$log->execute(['log-sources', 322, 'kt_322', 'laddbox', '2026-10-01 08:00:00', 'open', 'order', '']);
file_put_contents($tmp . '/order-322-co.json', json_encode([
    'purchases' => ['322' => [
        'reminder_id' => 322,
        'co' => 'jarfalla',
        'updated_at' => '2026-11-01 16:00:00',
    ]],
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/order-322-step.json', json_encode([
    'purchases' => ['322' => [
        'reminder_id' => 322,
        'co' => 'jarfalla',
        'step' => 2,
        'updated_at' => '2026-11-01 18:30:00',
    ]],
], JSON_UNESCAPED_UNICODE));
$weakReport = jeab_ordrar_state_sources_dispatch($store, ['action' => 'ordrar.state_sources', 'ids' => [322]]);
$weakOrder = [];
foreach (is_array($weakReport['orders'] ?? null) ? $weakReport['orders'] : [] as $row) {
    if (is_array($row) && (int) ($row['id'] ?? 0) === 322) {
        $weakOrder = $row;
    }
}
$weakNames = $names($weakOrder);
oss_state(isset($weakNames['file']), '322 har en filkälla, fick ' . json_encode(array_keys($weakNames)));
oss_state(($weakOrder['best'] ?? null) === null, 'bara co eller step räknas inte som läge, fick ' . json_encode($weakOrder['best'] ?? null));
$weakGet = jeab_ordrar_state_sources_dispatch($store, ['action' => 'ordrar.get', 'id' => 322]);
oss_state(!empty($weakGet['ok']) && (int) ($weakGet['booking_id'] ?? 0) === $book322 && (int) (jeab_purchase_get(322)['booking_id'] ?? 0) === 0, 'ordrar.get tar booking_id från bookings.order_id, fick ' . json_encode($weakGet['booking_id'] ?? null));
$weakRestore = jeab_ordrar_restore_state_dispatch($store, ['action' => 'ordrar.restore_state_from_snapshot', 'ids' => [322]]);
$weakRestored = [];
foreach (is_array($weakRestore['orders'] ?? null) ? $weakRestore['orders'] : [] as $row) {
    if (is_array($row) && (int) ($row['id'] ?? 0) === 322) {
        $weakRestored = $row;
    }
}
oss_state((string) ($weakRestored['reason'] ?? '') === 'no_state', 'svag källa faller igenom i återställning, fick ' . json_encode($weakRestored));
$weakDry = jeab_ordrar_state_sources_dispatch($store, ['action' => 'ordrar.rebuild_state', 'id' => 322]);
oss_state((int) ($weakDry['dry'] ?? 0) === 1 && (int) ($weakDry['sent'] ?? 1) === 0 && (int) ($weakDry['fortnox'] ?? 1) === 0 && (string) ($weakDry['reason'] ?? '') !== 'has_state', 'rebuild dry härleder och skickar inget');
oss_state((string) ($weakDry['diff']['install_date']['to'] ?? '') === '2026-11-06', 'härlett installationsdatum kommer från bokningen, fick ' . json_encode($weakDry['diff']['install_date'] ?? null));
oss_state((int) ($weakDry['diff']['step']['to'] ?? 0) >= 4, 'härlett steg är minst installation bokad, fick ' . json_encode($weakDry['diff']['step'] ?? null));
oss_state((string) ($weakDry['diff']['booking_id']['to'] ?? '') === (string) $book322, 'härledd bokning är den kopplade');
oss_state((string) (jeab_purchase_get(322)['company'] ?? '') === 'malmo' && (int) (jeab_purchase_get(322)['step'] ?? 0) === 1, 'rebuild dry skriver inte den svaga källan');
$weakWet = jeab_ordrar_state_sources_dispatch($store, ['action' => 'ordrar.rebuild_state', 'id' => 322, 'dry' => 0, 'confirm' => 'restore']);
oss_state(!empty($weakWet['ok']) && (int) ($weakWet['sent'] ?? 1) === 0 && (int) ($weakWet['fortnox'] ?? 1) === 0 && (int) ($weakWet['calendar'] ?? 1) === 0, 'härledd våt körning skickar inget och skriver inte till Fortnox');
if (function_exists('jeab_purchase_cache_forget')) {
    jeab_purchase_cache_forget();
}
$order322 = jeab_purchase_get(322);
oss_state(is_array($order322) && (string) ($order322['install_date'] ?? '') === '2026-11-06' && (int) ($order322['step'] ?? 0) >= 4 && (int) ($order322['booking_id'] ?? 0) === $book322, '322 fick installation och steg från bokningen, fick ' . json_encode([
    $order322['install_date'] ?? '',
    $order322['step'] ?? 0,
    $order322['booking_id'] ?? 0,
]));
oss_state((string) ($order322['company'] ?? '') === 'malmo', 'den svaga co-källan skrev inte över bolaget');
$weakUndo = (string) ($weakWet['undo_id'] ?? '');
$weakUndone = jeab_ordrar_restore_state_dispatch($store, ['action' => 'ordrar.restore_state_undo', 'undo_id' => $weakUndo, 'dry' => 0, 'confirm' => 'undo']);
oss_state(!empty($weakUndone['ok']) && (int) ($weakUndone['sent'] ?? 1) === 0, 'ångern av härledningen skickar inget');
if (function_exists('jeab_purchase_cache_forget')) {
    jeab_purchase_cache_forget();
}
$back322 = jeab_purchase_get(322);
oss_state(is_array($back322) && (string) ($back322['install_date'] ?? '') === '' && (int) ($back322['booking_id'] ?? 0) === 0 && (int) ($back322['step'] ?? 0) === 1, 'ångern tar tillbaka det härledda läget');

echo "ordrar_state_sources_test ok\n";
