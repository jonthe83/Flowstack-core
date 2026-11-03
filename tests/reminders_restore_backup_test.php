<?php

declare(strict_types=1);

/**
 * reminders.restore_from_backup behåller id, skriver inte över en rad som finns,
 * bygger en klar rad från ordern när kopian saknas, och restore_undo tar bara
 * bort oförändrade rader från samma batch.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-restore-backup-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require $root . '/src/bootstrap.php';

$fail = 0;
function restore_ok(bool $ok, string $msg): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $fail++;
    }
}

function restore_json_files(string $dir): array
{
    $out = [];
    foreach (scandir($dir) ?: [] as $name) {
        if (!str_ends_with($name, '.json')) {
            continue;
        }
        $path = $dir . '/' . $name;
        if (is_file($path)) {
            $out[$name] = md5((string) file_get_contents($path));
        }
    }
    ksort($out);

    return $out;
}

function restore_item(array $result, int $id): ?array
{
    foreach ($result['items'] ?? [] as $item) {
        if (is_array($item) && (int) ($item['id'] ?? 0) === $id) {
            return $item;
        }
    }

    return null;
}

$store = jeab_store();
restore_ok($store instanceof JeabPdoStore, 'sqlite-lager');
$pdo = $store->pdo();
$now = jeab_now();
$pdo->prepare('INSERT INTO reminder_lists (name, created_at) VALUES (?, ?)')->execute(['Uniklistnamn', $now]);
$listId = (int) $pdo->lastInsertId();
restore_ok($listId > 1, 'egen lista fick id ' . $listId);
$pdo->prepare(
    'INSERT INTO reminders (id, list_id, title, company, person, note, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([424242, 1, 'Stay', 'jarfalla', 'Ada', 'keep-note', $now, $now]);
$pdo->prepare('INSERT INTO customers (name, created_at) VALUES (?, ?)')->execute(['Kund', $now]);
$customerId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO bookings (customer_id, customer_name, assignee, starts_at, ends_at, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([$customerId, 'Kund', 'Kim', '2024-06-01 10:00:00', '2024-06-01 11:00:00', 'booked', $now, $now]);
$bookingId = (int) $pdo->lastInsertId();
restore_ok($bookingId > 0, 'bokning ' . $bookingId);

$old = $tmp . '/kontakter.bak-20200101-000000.json';
file_put_contents($old, json_encode([
    'reminders' => [
        ['id' => 717171, 'customer_name' => 'Hemlig Gammal', 'email' => 'secret-old@example.test', 'company' => 'jarfalla'],
        ['id' => 515151, 'company' => 'jarfalla', 'person' => 'Ada', 'note' => 'for-gammal'],
    ],
], JSON_UNESCAPED_UNICODE));
touch($old, strtotime('2020-01-01 00:00:00'));

$middleStamp = strtotime('2024-11-02 15:16:17');
$middle = $tmp . '/reminders.bak-20241102.json';
file_put_contents($middle, json_encode([
    'reminders' => [
        [
            'id' => 515151,
            'list_id' => $listId,
            'title' => 'Hemlig titel',
            'customer_name' => 'Secret Backup',
            'email' => 'secret-backup@example.test',
            'company' => 'malmo',
            'person' => 'Kim',
            'note' => 'backup-note',
            'completed_at' => '2019-03-04 05:06:07',
            'created_at' => '2019-03-01 01:02:03',
            'updated_at' => '2019-03-04 05:06:07',
            'contact_id' => 'c-backup',
            'address' => 'Backupgatan 2',
            'ort' => 'Malmö',
        ],
        [
            'id' => 424242,
            'list_id' => $listId,
            'title' => 'Hemlig live',
            'email' => 'secret-live@example.test',
            'company' => 'malmo',
            'person' => 'Kim',
            'note' => 'overwrite-me',
            'completed_at' => '2019-01-01 00:00:00',
            'created_at' => '2019-01-01 00:00:00',
            'updated_at' => '2019-01-01 00:00:00',
        ],
    ],
], JSON_UNESCAPED_UNICODE));
touch($middle, $middleStamp);

file_put_contents(jeab_json_path(), json_encode([
    'reminders' => [[
        'id' => 818181,
        'company' => 'jarfalla',
        'email' => 'secret-store@example.test',
        'customer_name' => 'Secret Store',
        'note' => 'store-note',
        'created_at' => '2021-01-01 00:00:00',
        'updated_at' => '2021-01-01 00:00:00',
    ]],
], JSON_UNESCAPED_UNICODE));
touch(jeab_json_path(), time());

$orderDate = '2024-05-06 07:08:09';
$purchase = static function (int $id, array $extra) use ($pdo, $now): void {
    $pdo->prepare('INSERT INTO purchases (reminder_id, payload, updated_at) VALUES (?, ?, ?)')->execute([
        $id,
        json_encode($extra, JSON_UNESCAPED_UNICODE),
        $now,
    ]);
};
$purchase(616161, [
    'reminder_id' => 616161,
    'company' => 'malmo',
    'seller' => 'Elin',
    'list_id' => $listId,
    'contact_id' => 'c616',
    'address' => 'Storgatan 4',
    'ort' => 'Lund',
    'created_at' => $orderDate,
    'booking_id' => $bookingId,
    'name' => 'Secret Person',
    'email' => 'secret-ref@example.test',
]);
$purchase(616162, [
    'reminder_id' => 616162,
    'company' => 'jarfalla',
    'seller' => 'Roberto',
    'created_at' => $orderDate,
    'name' => 'Secret Other',
    'email' => 'secret-other@example.test',
]);
$purchase(818181, [
    'reminder_id' => 818181,
    'company' => 'malmo',
    'seller' => 'Elin',
    'list_id' => $listId,
    'contact_id' => 'c818',
    'address' => 'Refgatan 8',
    'ort' => 'Lund',
    'created_at' => '2023-08-09 10:11:12',
    'name' => 'Secret Store',
    'email' => 'secret-store@example.test',
]);

$purchaseHash = md5((string) $pdo->query("SELECT group_concat(reminder_id || ':' || payload, '|') FROM purchases")->fetchColumn());
$bookingHash = md5((string) $pdo->query("SELECT group_concat(id || ':' || customer_name || ':' || updated_at, '|') FROM bookings")->fetchColumn());
$liveBefore = (int) $pdo->query('SELECT COUNT(*) FROM reminders')->fetchColumn();

$denied = jeab_reminders_restore_from_backup_dispatch($store, ['ids' => [515151], 'dry' => 0]);
restore_ok(($denied['ok'] ?? true) === false && (int) ($denied['status'] ?? 0) === 401, 'restore utan token skrivs inte');
restore_ok(!isset($denied['items']), 'nekad restore har ingen rad');
restore_ok((int) $pdo->query('SELECT COUNT(*) FROM reminders')->fetchColumn() === $liveBefore, 'nekad restore skapar ingen rad');
$undoDenied = jeab_reminders_restore_undo_dispatch($store, ['batch' => str_repeat('a', 32), 'dry' => 0]);
restore_ok(($undoDenied['ok'] ?? true) === false && (int) ($undoDenied['status'] ?? 0) === 401, 'undo utan token skrivs inte');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = 'tok-restore-backup';
$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-restore-backup']));

$audit = jeab_reminders_deleted_audit_dispatch($store, ['action' => 'reminders.deleted_audit']);
$auditAll = jeab_reminders_deleted_audit_dispatch($store, ['all_backups' => 1]);
restore_ok((int) ($audit['all_backups'] ?? 1) === 0, 'audit utan flagga läser en kopia');
restore_ok(($audit['missing_backup'] ?? []) === [818181], 'nyaste filen är bara store.json, fick ' . json_encode($audit['missing_backup'] ?? null));
restore_ok((int) ($auditAll['all_backups'] ?? 0) === 1, 'all_backups är på');
restore_ok(
    ($auditAll['missing_backup'] ?? []) === [515151, 717171, 818181],
    'alla kopior ger nyaste raden per id, fick ' . json_encode($auditAll['missing_backup'] ?? null)
);
$auditJson = json_encode($auditAll, JSON_UNESCAPED_UNICODE);
restore_ok(
    is_string($auditJson)
    && !str_contains($auditJson, 'Hemlig')
    && !str_contains($auditJson, 'secret-')
    && !str_contains($auditJson, 'Uniklistnamn')
    && !str_contains($auditJson, '@'),
    'all_backups har ingen kundtext'
);
restore_ok((int) $pdo->query('SELECT COUNT(*) FROM reminders')->fetchColumn() === $liveBefore, 'auditen skriver inga påminnelser');

$jsonBefore = restore_json_files($tmp);
$dry = jeab_reminders_restore_from_backup_dispatch($store, [
    'ids' => [515151, 424242, 616161],
]);
$dryJson = json_encode($dry, JSON_UNESCAPED_UNICODE);
$copy = restore_item($dry, 515151);
$liveItem = restore_item($dry, 424242);
$refItem = restore_item($dry, 616161);
$expectDate = (new DateTimeImmutable('@' . (int) filemtime($middle)))
    ->setTimezone(new DateTimeZone(date_default_timezone_get()))
    ->format('Y-m-d H:i:s');
restore_ok((int) ($dry['dry'] ?? 0) === 1 && ($dry['read_only'] ?? false) === true && (int) ($dry['sent'] ?? 1) === 0, 'dry är standard och skickar inget');
restore_ok(
    is_array($copy)
    && ($copy['file'] ?? '') === 'reminders.bak-20241102.json'
    && ($copy['date'] ?? '') === $expectDate
    && ($copy['state'] ?? '') === 'completed'
    && ($copy['company'] ?? '') === 'malmo'
    && (int) ($copy['list'] ?? 0) === $listId
    && $copy['order'] === null
    && $copy['booking'] === null
    && ($copy['via'] ?? '') === 'backup'
    && (int) ($copy['live'] ?? 1) === 0,
    'dry visar fil, datum, klar, bolag och lista för kopian'
);
restore_ok(
    is_array($liveItem) && (int) ($liveItem['live'] ?? 0) === 1 && ($liveItem['via'] ?? '') === 'backup',
    'en rad som finns markeras live'
);
restore_ok(
    is_array($refItem)
    && $refItem['file'] === null
    && ($refItem['date'] ?? '') === $orderDate
    && ($refItem['state'] ?? '') === 'completed'
    && ($refItem['company'] ?? '') === 'malmo'
    && (int) ($refItem['list'] ?? 0) === $listId
    && (int) ($refItem['order'] ?? 0) === 616161
    && (int) ($refItem['booking'] ?? 0) === $bookingId
    && ($refItem['via'] ?? '') === 'ref',
    'dry faller tillbaka på ordern när kopian saknas'
);
restore_ok(
    is_string($dryJson)
    && !str_contains($dryJson, 'Hemlig')
    && !str_contains($dryJson, 'secret-')
    && !str_contains($dryJson, 'Secret')
    && !str_contains($dryJson, 'Storgatan')
    && !str_contains($dryJson, 'Uniklistnamn')
    && !str_contains($dryJson, '@'),
    'dry-svaret har ingen kundtext'
);
restore_ok((int) $pdo->query('SELECT COUNT(*) FROM reminders WHERE id = 515151')->fetchColumn() === 0, 'dry skriver ingen rad');
restore_ok(restore_json_files($tmp) === $jsonBefore, 'dry skriver ingen json');

$onlyRef = jeab_reminders_restore_from_backup_dispatch($store, [
    'ids' => [616162],
    'source' => 'backup',
    'dry' => 0,
]);
restore_ok(
    ($onlyRef['missing'] ?? []) === [616162] && (int) $pdo->query('SELECT COUNT(*) FROM reminders WHERE id = 616162')->fetchColumn() === 0,
    'source=backup skapar inget från ordern'
);

$written = jeab_reminders_restore_from_backup_dispatch($store, [
    'ids' => [515151, 424242, 616161],
    'source' => 'auto',
    'dry' => 0,
]);
$batch = (string) ($written['batch'] ?? '');
restore_ok(($written['ok'] ?? false) === true && (int) ($written['sent'] ?? 1) === 0, 'skrivningen skickar inget');
restore_ok(($written['inserted'] ?? []) === [515151, 616161] && ($written['skipped'] ?? []) === [424242], 'infogar saknade id och hoppar över det som finns, fick ' . json_encode($written['inserted'] ?? null));
restore_ok(preg_match('/\A[a-f0-9]{32}\z/', $batch) === 1, 'batch-id följer med');
$row = $pdo->query('SELECT * FROM reminders WHERE id = 515151')->fetch();
restore_ok(
    is_array($row)
    && (int) $row['id'] === 515151
    && (string) $row['completed_at'] === '2019-03-04 05:06:07'
    && (string) $row['company'] === 'malmo'
    && (int) $row['list_id'] === $listId
    && (string) $row['person'] === 'Kim'
    && (string) $row['note'] === 'backup-note'
    && (string) $row['title'] === 'Hemlig titel'
    && (string) $row['email'] === 'secret-backup@example.test'
    && (int) $row['restored'] === 0,
    'kopian behåller ursprungligt id och fälten'
);
$kept = $pdo->query('SELECT title, company, person, note, list_id, completed_at FROM reminders WHERE id = 424242')->fetch();
restore_ok(
    is_array($kept)
    && (string) $kept['title'] === 'Stay'
    && (string) $kept['company'] === 'jarfalla'
    && (string) $kept['person'] === 'Ada'
    && (string) $kept['note'] === 'keep-note'
    && (int) $kept['list_id'] === 1
    && ($kept['completed_at'] === null || $kept['completed_at'] === ''),
    'befintlig rad skrivs inte över'
);
restore_ok((int) $pdo->query('SELECT COUNT(*) FROM reminders WHERE id = 424242')->fetchColumn() === 1, 'befintligt id finns kvar en gång');
$ref = $pdo->query('SELECT * FROM reminders WHERE id = 616161')->fetch();
restore_ok(
    is_array($ref)
    && (int) $ref['id'] === 616161
    && (string) $ref['completed_at'] === $orderDate
    && (string) $ref['company'] === 'malmo'
    && (int) $ref['list_id'] === $listId
    && (string) $ref['person'] === 'Elin'
    && (string) $ref['contact_id'] === 'c616'
    && (string) $ref['address'] === 'Storgatan 4'
    && (string) $ref['ort'] === 'Lund'
    && (string) $ref['note'] === 'Restored from order #616161'
    && (int) $ref['restored'] === 1
    && (int) $ref['booking_id'] === $bookingId
    && ($ref['email'] === null || $ref['email'] === '')
    && ($ref['customer_name'] === null || $ref['customer_name'] === ''),
    'ordern blir en klar påminnelse med ursprungligt id'
);
$again = jeab_reminders_restore_from_backup_dispatch($store, [
    'ids' => [515151],
    'dry' => 0,
]);
restore_ok(
    ($again['inserted'] ?? [1]) === [] && ($again['skipped'] ?? []) === [515151] && (int) $pdo->query('SELECT COUNT(*) FROM reminders WHERE id = 515151')->fetchColumn() === 1,
    'samma id en gång till skrivs inte över'
);

$fromRef = jeab_reminders_restore_from_backup_dispatch($store, [
    'ids' => [818181],
    'source' => 'ref',
    'dry' => 0,
]);
$refBatch = (string) ($fromRef['batch'] ?? '');
$stored = $pdo->query('SELECT email, note, company, restored FROM reminders WHERE id = 818181')->fetch();
restore_ok(
    is_array($stored)
    && (string) $stored['note'] === 'Restored from order #818181'
    && (string) $stored['company'] === 'malmo'
    && (int) $stored['restored'] === 1
    && ($stored['email'] === null || $stored['email'] === ''),
    'source=ref bygger från ordern även när en kopia finns'
);

restore_ok(md5((string) $pdo->query("SELECT group_concat(reminder_id || ':' || payload, '|') FROM purchases")->fetchColumn()) === $purchaseHash, 'ordrarna är orörda');
restore_ok(md5((string) $pdo->query("SELECT group_concat(id || ':' || customer_name || ':' || updated_at, '|') FROM bookings")->fetchColumn()) === $bookingHash, 'bokningarna är orörda');
restore_ok(restore_json_files($tmp) === $jsonBefore, 'skrivningen rör ingen json');

$pdo->exec('ALTER TABLE bookings ADD COLUMN reminder_id INTEGER');
$pdo->prepare(
    'INSERT INTO bookings (customer_id, customer_name, assignee, address, company, starts_at, ends_at, status, created_at, updated_at, reminder_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([$customerId, 'Annan', 'Roberto', 'Bokgatan 3', 'malmo', '2022-02-02 02:02:02', '2022-02-02 03:02:02', 'booked', $now, $now, 919191]);
$bookingOnly = (int) $pdo->lastInsertId();
$bookingBefore = $pdo->query('SELECT reminder_id, address, assignee, company, updated_at FROM bookings WHERE id = ' . $bookingOnly)->fetch();
$fromBooking = jeab_reminders_restore_from_backup_dispatch($store, [
    'ids' => [919191],
    'source' => 'auto',
    'dry' => 0,
]);
$bookedRow = $pdo->query('SELECT * FROM reminders WHERE id = 919191')->fetch();
$bookedItem = restore_item($fromBooking, 919191);
restore_ok(
    is_array($bookedRow)
    && (string) $bookedRow['completed_at'] === '2022-02-02 02:02:02'
    && (string) $bookedRow['person'] === 'Roberto'
    && (string) $bookedRow['company'] === 'malmo'
    && (string) $bookedRow['address'] === 'Bokgatan 3'
    && (string) $bookedRow['note'] === 'Restored from order #919191'
    && (int) $bookedRow['restored'] === 1
    && (int) $bookedRow['booking_id'] === $bookingOnly
    && is_array($bookedItem)
    && $bookedItem['order'] === null
    && (int) ($bookedItem['booking'] ?? 0) === $bookingOnly
    && ($bookedItem['via'] ?? '') === 'ref',
    'en bokning utan order blir en klar påminnelse'
);
$bookingAfter = $pdo->query('SELECT reminder_id, address, assignee, company, updated_at FROM bookings WHERE id = ' . $bookingOnly)->fetch();
$bookingJson = json_encode($bookedItem, JSON_UNESCAPED_UNICODE);
restore_ok($bookingBefore == $bookingAfter, 'bokningsraden skrivs inte om');
restore_ok(
    is_string($bookingJson) && !str_contains($bookingJson, 'Bokgatan') && !str_contains($bookingJson, 'Roberto') && !str_contains($bookingJson, 'Annan'),
    'boknings-svaret har ingen kundtext'
);
$bookingUndo = jeab_reminders_restore_undo_dispatch($store, ['batch' => (string) ($fromBooking['batch'] ?? ''), 'dry' => 0]);
restore_ok(($bookingUndo['deleted'] ?? []) === [919191], 'undo tar bort bokningsraden');

$pdo->prepare('UPDATE reminders SET note = ? WHERE id = ?')->execute(['ändrad', 616161]);
$undoDry = jeab_reminders_restore_undo_dispatch($store, ['batch' => $batch]);
restore_ok(
    (int) ($undoDry['dry'] ?? 0) === 1
    && ($undoDry['deleted'] ?? [1]) === []
    && (int) $pdo->query('SELECT COUNT(*) FROM reminders WHERE id = 515151')->fetchColumn() === 1,
    'undo dry raderar inget'
);
$undo = jeab_reminders_restore_undo_dispatch($store, ['batch' => $batch, 'dry' => 0]);
restore_ok(
    ($undo['deleted'] ?? []) === [515151] && ($undo['kept'] ?? []) === [616161],
    'undo tar bort oförändrad kopia och lämnar ändrad order-rad, fick ' . json_encode($undo)
);
restore_ok((int) $pdo->query('SELECT COUNT(*) FROM reminders WHERE id = 515151')->fetchColumn() === 0, 'oförändrat id är borta');
restore_ok((string) $pdo->query('SELECT note FROM reminders WHERE id = 616161')->fetchColumn() === 'ändrad', 'ändrad rad finns kvar');
restore_ok((string) $pdo->query('SELECT note FROM reminders WHERE id = 424242')->fetchColumn() === 'keep-note', 'undo rör inte andra id');
$undoRef = jeab_reminders_restore_undo_dispatch($store, ['batch' => $refBatch, 'dry' => 0]);
restore_ok(($undoRef['deleted'] ?? []) === [818181], 'undo tar bort oförändrad ref-rad, fick ' . json_encode($undoRef['deleted'] ?? null));
restore_ok((int) $pdo->query('SELECT COUNT(*) FROM reminders WHERE id = 818181')->fetchColumn() === 0, 'ref-raden är borta');
restore_ok(md5((string) $pdo->query("SELECT group_concat(reminder_id || ':' || payload, '|') FROM purchases")->fetchColumn()) === $purchaseHash, 'undo rör inte ordrar');

$tooMany = jeab_reminders_restore_from_backup_dispatch($store, ['ids' => range(1, 51), 'dry' => 0]);
restore_ok(($tooMany['ok'] ?? true) === false && (int) ($tooMany['status'] ?? 0) === 422, 'mer än 50 id nekas');
$badSource = jeab_reminders_restore_from_backup_dispatch($store, ['ids' => [1], 'source' => 'fil']);
restore_ok(($badSource['ok'] ?? true) === false, 'okänd källa nekas');

$hook = (string) file_get_contents($root . '/hook.php');
restore_ok(str_contains($hook, "'reminders.restore_from_backup'"), 'hook.php tar emot restore');
restore_ok(str_contains($hook, "'reminders.restore_undo'"), 'hook.php tar emot undo');
$schema = (string) file_get_contents($root . '/schema/sqlite.php') . (string) file_get_contents($root . '/schema/mysql.php');
restore_ok(!str_contains($schema, 'reminder_restore_log'), 'loggen skapas inte i schema/');
$logCols = [];
foreach ($pdo->query('PRAGMA table_info(reminder_restore_log)') as $col) {
    $logCols[] = (string) ($col['name'] ?? '');
}
restore_ok($logCols === ['batch_id', 'id', 'source_file', 'inserted_at', 'completed_at'], 'loggen har batch, id, fil, tid och klar-tid, fick ' . json_encode($logCols));

echo $fail === 0 ? "OK  reminders restore backup\n" : "\n{$fail} failed\n";
exit($fail === 0 ? 0 : 1);
