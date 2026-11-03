<?php

declare(strict_types=1);

/**
 * ordrar.restore_state_from_snapshot.
 * Bygget från #1091 tappar steg, bokning, faktura, pris och co.
 * Kroken fyller läget från nyaste källan, bara på återställda ordrar.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-ord-state-' . bin2hex(random_bytes(4));
mkdir($tmp . '/.private/backups', 0775, true);
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
file_put_contents($tmp . '/kontakter.json', json_encode([
    'contacts' => [
        'kt_127' => [
            'id' => 'kt_127',
            'gata' => 'Testgatan 12',
            'city' => 'Malmo',
        ],
    ],
], JSON_UNESCAPED_UNICODE));

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/ordrar_restore_state.php';
// Kontakter läses bara från SQL, så testkontakten läggs där.
require_once dirname(__DIR__) . '/src/kontakter_sql_migrate.php';
$ctStore = jeab_kontakter_sql_store();
if ($ctStore instanceof JeabPdoContactStore) {
    $ctStore->upsert('kt_127', ['id' => 'kt_127', 'gata' => 'Testgatan 12', 'city' => 'Malmo']);
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['jeab_email'] = 'demo@example.test';
$_COOKIE['jeab_auth'] = '';

function ors_state(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
ors_state(str_contains($hook, "'ordrar.restore_state_from_snapshot'"), 'hook tar restore_state_from_snapshot');
ors_state(str_contains($hook, "'ordrar.restore_state_undo'"), 'hook tar restore_state_undo');
$src = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar_restore_state.php');
ors_state(preg_match('/jeab_(send_|sms_|fortnox|graph_|mail_)/', $src) !== 1, 'läget anropar inget utskick');

$full = [
    'reminder_id' => 147,
    'co' => 'malmo',
    'product' => 'laddbox',
    'step' => 4,
    'status' => 'open',
    'booking_id' => 9,
    'install_date' => '2026-11-02',
    'fortnox_order' => '550147',
    'pris' => 14900,
    'steps' => ['bokad_tid' => '2026-11-02 08:00:00'],
    'inkop_lines' => [['article' => 'BOX', 'qty' => '1', 'price' => '14900']],
    'name' => 'Hemlig Kund',
    'email' => 'secret-state@example.test',
];
$dropped = jeab_ordrar_restore_state_dropped($full);
foreach (['company', 'step', 'booking_id', 'install_date', 'fortnox_order', 'pris', 'steps', 'inkop_lines'] as $key) {
    ors_state(in_array($key, $dropped, true), 'build släpper ' . $key . ', fick ' . json_encode($dropped));
}
$built = jeab_ordrar_restore_build(147, ['orders_rows' => ['147' => $full]], null);
ors_state((string) ($built['company'] ?? '') === '' && !isset($built['fortnox_order']) && !isset($built['steps']), 'build behåller inte co, faktura eller steg');

$store = jeab_store();
ors_state($store instanceof JeabPdoStore, 'lagret är öppet');
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
$book127 = $book('kt_127');
$book147 = $book('kt_147');
$book172 = $book('kt_172');
ors_state($book127 > 0 && $book147 > 0 && $book172 > 0, 'bokningarna finns');

$thin = static function (int $id, string $contact) use ($pdo): void {
    jeab_sql_purchases_put($pdo, [
        'reminder_id' => $id,
        'contact_id' => $contact,
        'step' => 1,
        'status' => 'open',
        'created_at' => '2026-10-01 08:00:00',
        'updated_at' => '2026-10-01 08:00:00',
    ]);
};
$thin(127, 'kt_127');
$thin(147, 'kt_147');
$thin(172, 'kt_172');
$thin(200, 'kt_200');
jeab_ordrar_restore_ensure_log($pdo);
$log = $pdo->prepare('INSERT INTO order_restore_log (undo_id, reminder_id, contact_id, kind, order_created_at, step, status, booking_id, undone, entity, entity_key) VALUES (?, ?, ?, ?, ?, 1, ?, 0, 0, ?, ?)');
foreach ([127 => 'kt_127', 147 => 'kt_147', 172 => 'kt_172'] as $id => $contact) {
    $log->execute(['log-state', $id, $contact, '', '2026-10-01 08:00:00', 'open', 'order', '']);
}

$undoPdo = jeab_contacts_sole_undo_pdo($store);
ors_state($undoPdo instanceof PDO, 'ångerloggen går att öppna');
$snap = [
    'ids' => ['orders' => [127, 147, 172, 200]],
    'links' => ['orders' => ['127' => 'kt_127', '147' => 'kt_147', '172' => 'kt_172']],
    'orders_rows' => [
        '127' => ['contact_id' => 'kt_127', 'step' => 1, 'status' => 'open', 'updated_at' => '2026-10-01 08:00:00', 'name' => 'Hemlig Kund'],
        '147' => ['contact_id' => 'kt_147', 'step' => 1, 'status' => 'open', 'updated_at' => '2026-10-01 08:00:00'],
        '172' => ['contact_id' => 'kt_172', 'step' => 1, 'status' => 'open', 'updated_at' => '2026-10-01 08:00:00'],
    ],
];
$undoPdo->prepare('INSERT INTO contacts_sole_undo (batch_id, seq, kind, entity, entity_id, before_json, after_json, created_at, undone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)')
    ->execute(['batchstate', 1, 'snapshot', 'census', 'batchstate', json_encode($snap, JSON_UNESCAPED_UNICODE), '{}', '2026-10-01 09:00:00']);
$seal = static fn (): array => $undoPdo->query("SELECT id, before_json, undone FROM contacts_sole_undo WHERE kind = 'snapshot' ORDER BY id")->fetchAll();
$sealBefore = $seal();

$rich = static function (int $id, string $contact, int $booking, string $install, string $fortnox) : array {
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
        'pris' => 14900,
        'steps' => ['bokad_tid' => $install . ' 08:00:00'],
        'inkop_lines' => [['article' => 'BOX', 'qty' => '1', 'price' => '14900']],
        'kalkyl' => [['article' => 'BOX', 'price' => '14900']],
        'name' => 'Hemlig Kund',
        'email' => 'secret-state@example.test',
        'updated_at' => '2026-11-01 08:00:00',
    ];
};
file_put_contents($tmp . '/.private/backups/purchases-malmo.json', json_encode([
    'purchases' => [
        '127' => $rich(127, 'kt_127', $book127, '2026-11-02', '550127'),
        '147' => $rich(147, 'kt_147', $book147, '2026-11-02', '550147'),
        '172' => $rich(172, 'kt_172', $book172, '2026-10-01', '550000'),
        '200' => $rich(200, 'kt_200', 1, '2026-11-02', '550200'),
    ],
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/.private/backups/order-audit.json', json_encode([
    '172' => [
        'reminder_id' => 172,
        'co' => 'jarfalla',
        'product' => 'laddbox',
        'fortnox_order' => '111172',
        'updated_at' => '2026-10-20 08:00:00',
    ],
], JSON_UNESCAPED_UNICODE));
$pdo->exec('CREATE TABLE purchases_history (id INTEGER PRIMARY KEY AUTOINCREMENT, reminder_id INTEGER NOT NULL, payload TEXT NOT NULL, updated_at TEXT NOT NULL)');
$pdo->exec('CREATE TABLE order_audit (id INTEGER PRIMARY KEY AUTOINCREMENT, reminder_id INTEGER NOT NULL, payload TEXT NOT NULL, created_at TEXT NOT NULL)');
$hist = $pdo->prepare('INSERT INTO purchases_history (reminder_id, payload, updated_at) VALUES (?, ?, ?)');
$hist->execute([147, json_encode(['reminder_id' => 147, 'step' => 2, 'updated_at' => '2026-10-02 08:00:00'], JSON_UNESCAPED_UNICODE), '2026-10-02 08:00:00']);
$hist->execute([172, json_encode($rich(172, 'kt_172', $book172, '2026-11-02', '550172'), JSON_UNESCAPED_UNICODE), '2026-11-01 12:00:00']);
$aud = $pdo->prepare('INSERT INTO order_audit (reminder_id, payload, created_at) VALUES (?, ?, ?)');
$aud->execute([172, json_encode(['reminder_id' => 172, 'co' => 'malmo', 'product' => 'laddbox', 'fortnox_order' => '222172', 'updated_at' => '2026-10-25 08:00:00'], JSON_UNESCAPED_UNICODE), '2026-10-25 08:00:00']);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = '';
$denied = jeab_ordrar_restore_state_dispatch($store, ['ids' => [127], 'dry' => 0, 'confirm' => 'restore']);
ors_state(empty($denied['ok']) && (int) ($denied['status'] ?? 0) === 401, 'utan token skrivs inget');
ors_state((string) (jeab_purchase_get(127)['fortnox_order'] ?? '') === '', 'nekad körning lämnar ordern');

$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-ordrar-state']));
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = 'tok-ordrar-state';

$ids = [127, 147, 172, 200];
$dry = jeab_ordrar_restore_state_dispatch($store, ['action' => 'ordrar.restore_state_from_snapshot', 'ids' => $ids]);
ors_state((int) ($dry['dry'] ?? 0) === 1 && !empty($dry['read_only']) && (int) ($dry['sent'] ?? 1) === 0, 'dry skickar inget');
ors_state((int) ($dry['mail'] ?? 1) === 0 && (int) ($dry['sms'] ?? 1) === 0 && (int) ($dry['fortnox'] ?? 1) === 0 && (int) ($dry['calendar'] ?? 1) === 0, 'dry rör inte mail sms fortnox kalender');
ors_state((string) (jeab_purchase_get(147)['fortnox_order'] ?? '') === '', 'dry skriver inte');
$byId = [];
foreach (is_array($dry['orders'] ?? null) ? $dry['orders'] : [] as $row) {
    if (is_array($row)) {
        $byId[(int) ($row['id'] ?? 0)] = $row;
    }
}
ors_state(($byId[147]['source'] ?? '') === 'backup' && ($byId[147]['source_file'] ?? '') === 'purchases-malmo.json', '147 tar backupen, fick ' . json_encode($byId[147]['source'] ?? null));
ors_state(($byId[172]['source'] ?? '') === 'purchases_history' && ($byId[172]['source_file'] ?? '') === '', '172 tar purchases_history');
ors_state(($byId[127]['diff']['company']['to'] ?? '') === 'malmo' && ($byId[127]['diff']['install_date']['to'] ?? '') === '2026-11-02', 'dry visar bolag och installation');
ors_state(($byId[172]['diff']['fortnox_order']['to'] ?? '') === '550172', '172 tar fakturan från historiken, inte den äldre auditen');
ors_state(($byId[200]['reason'] ?? '') === 'not_restored', 'en order som inte återställts rörs inte');
$dryJson = json_encode($dry, JSON_UNESCAPED_UNICODE);
ors_state(is_string($dryJson) && !str_contains($dryJson, 'Hemlig') && !str_contains($dryJson, 'secret-state'), 'dry saknar personuppgifter');

$need = jeab_ordrar_restore_state_dispatch($store, ['ids' => $ids, 'dry' => 0]);
ors_state(empty($need['ok']) && (string) (jeab_purchase_get(127)['company'] ?? '') === '', 'utan confirm skrivs inget');

$wet = jeab_ordrar_restore_state_dispatch($store, ['ids' => $ids, 'dry' => 0, 'confirm' => 'restore']);
ors_state(!empty($wet['ok']) && (int) ($wet['sent'] ?? 1) === 0 && (int) ($wet['fortnox'] ?? 1) === 0 && (int) ($wet['calendar'] ?? 1) === 0, 'våt skickar inget');
$undoId = (string) ($wet['undo_id'] ?? '');
ors_state(preg_match('/\A[a-f0-9]{32}\z/', $undoId) === 1, 'våt ger undo_id');
if (function_exists('jeab_purchase_cache_forget')) {
    jeab_purchase_cache_forget();
}
$order127 = jeab_purchase_get(127);
ors_state(is_array($order127) && (string) ($order127['company'] ?? '') === 'malmo' && (string) ($order127['kind'] ?? '') === 'laddbox', '127 är Malmö och laddbox');
ors_state((string) ($order127['fortnox_order'] ?? '') === '550127' && (int) ($order127['booking_id'] ?? 0) === $book127, '127 fick faktura och bokning');
ors_state((string) ($order127['address'] ?? '') === '' && (string) ($order127['gata'] ?? '') === '', '127 har ingen egen adress');
ors_state((string) $pdo->query('SELECT company FROM purchases WHERE reminder_id = 127')->fetchColumn() === 'malmo', 'company-kolumnen är malmo');
$progress = jeab_ordrar_v3_progress($order127, $store);
ors_state((string) ($progress['label'] ?? '') === 'Fakturering' && (int) ($progress['done'] ?? 0) === 1 && (int) ($progress['total'] ?? 0) === 2, '127 står på Fakturering 1 av 2, fick ' . json_encode([$progress['label'] ?? '', $progress['done'] ?? 0, $progress['total'] ?? 0]));
ors_state(jeab_ordrar_needs_reply($order127) === false, '127 väntar inte på följdfrågor');
$cells = jeab_ordrar_lista_cells($order127, $store);
ors_state((string) ($cells['install_raw'] ?? '') === '2026-11-02', 'installationen är 2 nov, fick ' . json_encode($cells['install_raw'] ?? ''));
$order172 = jeab_purchase_get(172);
ors_state((string) ($order172['fortnox_order'] ?? '') === '550172' && (string) ($order172['company'] ?? '') === 'malmo', '172 tog den nyaste historiken');
ors_state((string) (jeab_purchase_get(200)['fortnox_order'] ?? '') === '', '200 skrevs inte');
$visible = array_map(static fn (array $row): int => (int) ($row['reminder_id'] ?? 0), jeab_ordrar_visible('malmo'));
ors_state(in_array(127, $visible, true), '127 syns i Malmös lista, fick ' . json_encode($visible));
$jarfalla = array_map(static fn (array $row): int => (int) ($row['reminder_id'] ?? 0), jeab_ordrar_visible('jarfalla'));
ors_state(!in_array(127, $jarfalla, true), '127 syns inte i Järfälla');
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE'], $GLOBALS['_JEAB_KONTAKTER_MEM'], $GLOBALS['_JEAB_ORDRAR_ROW_HTML']);
$rowKey = jeab_ordrar_row_cache_key($order127, 'malmo', null);
@unlink(jeab_ordrar_row_cache_file($rowKey));
$html = jeab_ordrar_board_row_html($order127, 'malmo', $store);
ors_state(str_contains($html, 'Testgatan 12'), 'listan visar kontakten gatans adress');
ors_state(str_contains($html, 'Fakturering') && str_contains($html, '1 av 2') && !str_contains($html, 'väntar på följdfrågor'), 'listraden visar Fakturering 1 av 2');
ors_state($seal() == $sealBefore, 'frysta ögonblicksbilder är orörda');

$undoDry = jeab_ordrar_restore_state_dispatch($store, ['action' => 'ordrar.restore_state_undo', 'undo_id' => $undoId]);
ors_state((int) ($undoDry['dry'] ?? 0) === 1 && (string) (jeab_purchase_get(127)['fortnox_order'] ?? '') === '550127', 'ånger dry lämnar läget');
$undoNeed = jeab_ordrar_restore_state_dispatch($store, ['action' => 'ordrar.restore_state_undo', 'undo_id' => $undoId, 'dry' => 0]);
ors_state(empty($undoNeed['ok']) && (string) (jeab_purchase_get(127)['fortnox_order'] ?? '') === '550127', 'ånger utan confirm lämnar läget');
$undone = jeab_ordrar_restore_state_dispatch($store, ['action' => 'ordrar.restore_state_undo', 'undo_id' => $undoId, 'dry' => 0, 'confirm' => 'undo']);
ors_state(!empty($undone['ok']) && (int) ($undone['sent'] ?? 1) === 0, 'ångern skickar inget');
if (function_exists('jeab_purchase_cache_forget')) {
    jeab_purchase_cache_forget();
}
$back = jeab_purchase_get(127);
ors_state(is_array($back) && (string) ($back['fortnox_order'] ?? '') === '' && (string) ($back['company'] ?? '') === '' && (int) ($back['booking_id'] ?? 0) === 0, 'ångern tar tillbaka läget');
$again = jeab_ordrar_v3_progress($back, $store);
ors_state((string) ($again['label'] ?? '') === 'Bekräfta' && (int) ($again['done'] ?? -1) === 0, 'ångern är tillbaka på Bekräfta 0');
ors_state($seal() == $sealBefore, 'ångern rör inte frysta ögonblicksbilder');

echo "ordrar_restore_state_test ok\n";
