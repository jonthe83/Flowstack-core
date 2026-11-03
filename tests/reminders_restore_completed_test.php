<?php

declare(strict_types=1);

/**
 * completed=1 på reminders.restore_from_backup klarmarkerar varje återställd rad
 * med samma fält som en vanlig klarmarkering. Datumet är kopians datum, eller nu
 * när kopian saknar datum. dry visar completed per id. restore_undo tar bort
 * oförändrade rader från loggen.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-restore-completed-' . getmypid();
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
function completed_ok(bool $ok, string $msg): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $fail++;
    }
}

function completed_json_files(string $dir): array
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

function completed_item(array $result, int $id): ?array
{
    foreach ($result['items'] ?? [] as $item) {
        if (is_array($item) && (int) ($item['id'] ?? 0) === $id) {
            return $item;
        }
    }

    return null;
}

function completed_file_date(string $path): string
{
    return (new DateTimeImmutable('@' . (int) filemtime($path)))
        ->setTimezone(new DateTimeZone(date_default_timezone_get()))
        ->format('Y-m-d H:i:s');
}

$store = jeab_store();
completed_ok($store instanceof JeabPdoStore, 'sqlite-lager');
$pdo = $store->pdo();
$now = jeab_now();
$pdo->prepare(
    'INSERT INTO reminders (id, list_id, title, company, note, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
)->execute([424242, 1, 'Stay', 'jarfalla', 'keep-note', $now, $now]);
$pdo->prepare('INSERT INTO customers (name, created_at) VALUES (?, ?)')->execute(['Kund', $now]);
$customerId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO bookings (customer_id, customer_name, assignee, starts_at, ends_at, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([$customerId, 'Kund', 'Kim', '2024-06-01 10:00:00', '2024-06-01 11:00:00', 'booked', $now, $now]);

$orderDate = '2024-05-06 07:08:09';
$pdo->prepare('INSERT INTO purchases (reminder_id, payload, updated_at) VALUES (?, ?, ?)')->execute([
    616161,
    json_encode([
        'reminder_id' => 616161,
        'company' => 'jarfalla',
        'seller' => 'Elin',
        'list_id' => 1,
        'created_at' => $orderDate,
        'name' => 'Secret Person',
        'email' => 'secret-completed@example.test',
    ], JSON_UNESCAPED_UNICODE),
    $now,
]);

$backupStamp = strtotime('2024-11-02 15:16:17');
$backup = $tmp . '/reminders.bak-20241102.json';
file_put_contents($backup, json_encode([
    'reminders' => [
        [
            'id' => 515151,
            'list_id' => 1,
            'title' => 'Hemlig titel',
            'company' => 'jarfalla',
            'note' => 'open-note',
            'email' => 'secret-open@example.test',
            'created_at' => '2018-01-01 00:00:00',
            'updated_at' => '2018-01-01 00:00:00',
        ],
        [
            'id' => 515152,
            'list_id' => 1,
            'title' => 'Redan klar',
            'company' => 'jarfalla',
            'note' => 'old-done',
            'completed_at' => '2019-03-04 05:06:07',
            'created_at' => '2019-03-01 01:02:03',
            'updated_at' => '2019-03-04 05:06:07',
        ],
        [
            'id' => 616162,
            'list_id' => 1,
            'title' => 'Stannar öppen',
            'company' => 'jarfalla',
            'note' => 'leave-open',
            'created_at' => '2018-02-02 00:00:00',
            'updated_at' => '2018-02-02 00:00:00',
        ],
        [
            'id' => 424242,
            'list_id' => 1,
            'title' => 'Hemlig live',
            'company' => 'malmo',
            'note' => 'overwrite-me',
            'created_at' => '2019-01-01 00:00:00',
            'updated_at' => '2019-01-01 00:00:00',
        ],
    ],
], JSON_UNESCAPED_UNICODE));
touch($backup, $backupStamp);
$expectDate = completed_file_date($backup);

$zero = $tmp . '/reminders.bak-19700101.json';
file_put_contents($zero, json_encode([
    'reminders' => [[
        'id' => 818181,
        'list_id' => 1,
        'title' => 'Utan datum',
        'company' => 'jarfalla',
        'note' => 'no-date',
        'created_at' => '2017-01-01 00:00:00',
        'updated_at' => '2017-01-01 00:00:00',
    ]],
], JSON_UNESCAPED_UNICODE));
touch($zero, 0);
completed_ok((int) filemtime($zero) === 0, 'kopian utan datum har mtime 0');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = 'tok-restore-completed';
$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-restore-completed']));

$purchaseHash = md5((string) $pdo->query("SELECT group_concat(reminder_id || ':' || payload, '|') FROM purchases")->fetchColumn());
$bookingHash = md5((string) $pdo->query("SELECT group_concat(id || ':' || customer_name || ':' || updated_at, '|') FROM bookings")->fetchColumn());
$jsonBefore = completed_json_files($tmp);
$reminderCount = (int) $pdo->query('SELECT COUNT(*) FROM reminders')->fetchColumn();

$plain = jeab_reminders_restore_from_backup_dispatch($store, ['ids' => [515151]]);
$plainItem = completed_item($plain, 515151);
completed_ok(
    (int) ($plain['dry'] ?? 0) === 1
    && (int) ($plain['sent'] ?? 1) === 0
    && is_array($plainItem)
    && (int) ($plainItem['completed'] ?? 1) === 0
    && ($plainItem['state'] ?? '') === 'open',
    'utan completed är dry klar-flaggan 0 och raden öppen'
);

$dry = jeab_reminders_restore_from_backup_dispatch($store, [
    'ids' => [515151, 515152, 424242, 616161, 717171],
    'completed' => 1,
    'dry' => 1,
]);
$dryJson = json_encode($dry, JSON_UNESCAPED_UNICODE);
$openItem = completed_item($dry, 515151);
$oldItem = completed_item($dry, 515152);
$liveItem = completed_item($dry, 424242);
$refItem = completed_item($dry, 616161);
$missingItem = completed_item($dry, 717171);
completed_ok(
    (int) ($dry['dry'] ?? 0) === 1 && ($dry['read_only'] ?? false) === true && (int) ($dry['sent'] ?? 1) === 0,
    'completed=1 dry skickar inget'
);
completed_ok(
    is_array($openItem)
    && (int) ($openItem['completed'] ?? 0) === 1
    && ($openItem['state'] ?? '') === 'completed'
    && ($openItem['date'] ?? '') === $expectDate
    && ($openItem['via'] ?? '') === 'backup'
    && (int) ($openItem['live'] ?? 1) === 0,
    'dry visar completed=1 för öppen kopia'
);
completed_ok(
    is_array($oldItem) && (int) ($oldItem['completed'] ?? 0) === 1 && ($oldItem['state'] ?? '') === 'completed',
    'dry visar completed=1 även när kopian redan var klar'
);
completed_ok(
    is_array($liveItem) && (int) ($liveItem['completed'] ?? 0) === 1 && (int) ($liveItem['live'] ?? 0) === 1,
    'dry visar completed per id även för en rad som finns'
);
completed_ok(
    is_array($refItem)
    && (int) ($refItem['completed'] ?? 0) === 1
    && ($refItem['state'] ?? '') === 'completed'
    && ($refItem['date'] ?? '') === $orderDate
    && ($refItem['via'] ?? '') === 'ref',
    'dry visar completed=1 för ordern'
);
completed_ok(
    is_array($missingItem) && (int) ($missingItem['completed'] ?? 1) === 0 && ($missingItem['via'] ?? '') === 'missing',
    'ett id utan kopia får completed 0'
);
completed_ok(
    is_string($dryJson)
    && !str_contains($dryJson, 'Hemlig')
    && !str_contains($dryJson, 'secret-')
    && !str_contains($dryJson, 'Secret')
    && !str_contains($dryJson, '@'),
    'dry-svaret har ingen kundtext'
);
completed_ok((int) $pdo->query('SELECT COUNT(*) FROM reminders')->fetchColumn() === $reminderCount, 'dry skriver ingen rad');
completed_ok(completed_json_files($tmp) === $jsonBefore, 'dry skriver ingen json');
completed_ok(md5((string) $pdo->query("SELECT group_concat(reminder_id || ':' || payload, '|') FROM purchases")->fetchColumn()) === $purchaseHash, 'dry läser bara ordrar');
completed_ok(md5((string) $pdo->query("SELECT group_concat(id || ':' || customer_name || ':' || updated_at, '|') FROM bookings")->fetchColumn()) === $bookingHash, 'dry läser bara bokningar');

$off = jeab_reminders_restore_from_backup_dispatch($store, [
    'ids' => [515151],
    'completed' => 0,
    'dry' => 1,
]);
$offItem = completed_item($off, 515151);
completed_ok(
    is_array($offItem) && (int) ($offItem['completed'] ?? 1) === 0 && ($offItem['state'] ?? '') === 'open',
    'completed=0 lämnar raden öppen i dry'
);

$beforeWrite = jeab_now();
$written = jeab_reminders_restore_from_backup_dispatch($store, [
    'ids' => [515151, 515152, 424242, 616161],
    'completed' => 1,
    'dry' => 0,
]);
$afterWrite = jeab_now();
$batch = (string) ($written['batch'] ?? '');
completed_ok(
    ($written['ok'] ?? false) === true
    && (int) ($written['sent'] ?? 1) === 0
    && ($written['inserted'] ?? []) === [515151, 515152, 616161]
    && ($written['skipped'] ?? []) === [424242],
    'completed=1 infogar saknade id, fick ' . json_encode($written['inserted'] ?? null)
);
$open = $pdo->query('SELECT * FROM reminders WHERE id = 515151')->fetch();
completed_ok(
    is_array($open)
    && (string) $open['completed_at'] === $expectDate
    && (string) $open['updated_at'] === $expectDate
    && (string) $open['created_at'] === '2018-01-01 00:00:00'
    && (string) $open['title'] === 'Hemlig titel'
    && (string) $open['note'] === 'open-note'
    && (string) $open['company'] === 'jarfalla'
    && (int) $open['restored'] === 0,
    'öppen kopia sparas klar på kopians datum'
);
$old = $pdo->query('SELECT completed_at, updated_at, created_at, note FROM reminders WHERE id = 515152')->fetch();
completed_ok(
    is_array($old)
    && (string) $old['completed_at'] === $expectDate
    && (string) $old['updated_at'] === $expectDate
    && (string) $old['created_at'] === '2019-03-01 01:02:03'
    && (string) $old['note'] === 'old-done',
    'tidigare klar-tid byts till kopians datum'
);
$kept = $pdo->query('SELECT title, note, completed_at FROM reminders WHERE id = 424242')->fetch();
completed_ok(
    is_array($kept)
    && (string) $kept['title'] === 'Stay'
    && (string) $kept['note'] === 'keep-note'
    && ($kept['completed_at'] === null || $kept['completed_at'] === ''),
    'en rad som finns klarmarkeras inte'
);
$ref = $pdo->query('SELECT completed_at, updated_at, note, restored, email FROM reminders WHERE id = 616161')->fetch();
completed_ok(
    is_array($ref)
    && (string) $ref['completed_at'] === (string) $ref['updated_at']
    && (string) $ref['completed_at'] >= $beforeWrite
    && (string) $ref['completed_at'] <= $afterWrite
    && (string) $ref['note'] === 'Restored from order #616161'
    && (int) $ref['restored'] === 1
    && ($ref['email'] === null || $ref['email'] === ''),
    'en order utan kopia sparas klar med tiden nu, fick ' . json_encode($ref)
);
$log = $pdo->query('SELECT id, completed_at, source_file FROM reminder_restore_log ORDER BY id ASC')->fetchAll();
$logById = [];
foreach ($log as $row) {
    if (is_array($row)) {
        $logById[(int) $row['id']] = $row;
    }
}
completed_ok(
    (string) ($logById[515151]['completed_at'] ?? '') === $expectDate
    && (string) ($logById[515152]['completed_at'] ?? '') === $expectDate
    && (string) ($logById[616161]['completed_at'] ?? '') === (string) ($ref['completed_at'] ?? '')
    && (string) ($logById[515151]['source_file'] ?? '') === 'reminders.bak-20241102.json'
    && (string) ($logById[616161]['source_file'] ?? '') === 'ref',
    'klar-tiden ligger i reminder_restore_log, fick ' . json_encode($logById)
);
completed_ok(md5((string) $pdo->query("SELECT group_concat(reminder_id || ':' || payload, '|') FROM purchases")->fetchColumn()) === $purchaseHash, 'skrivningen rör inte ordrar');
completed_ok(md5((string) $pdo->query("SELECT group_concat(id || ':' || customer_name || ':' || updated_at, '|') FROM bookings")->fetchColumn()) === $bookingHash, 'skrivningen rör inte bokningar');
completed_ok(completed_json_files($tmp) === $jsonBefore, 'skrivningen rör ingen json');

$undoDry = jeab_reminders_restore_undo_dispatch($store, ['batch' => $batch]);
completed_ok(
    (int) ($undoDry['dry'] ?? 0) === 1
    && ($undoDry['deleted'] ?? [1]) === []
    && (int) ($undoDry['sent'] ?? 1) === 0
    && (int) $pdo->query('SELECT COUNT(*) FROM reminders WHERE id = 515151')->fetchColumn() === 1,
    'undo dry raderar inget'
);
$pdo->prepare('UPDATE reminders SET note = ? WHERE id = ?')->execute(['ändrad', 515152]);
$undo = jeab_reminders_restore_undo_dispatch($store, ['batch' => $batch, 'dry' => 0]);
completed_ok(
    ($undo['deleted'] ?? []) === [515151, 616161] && ($undo['kept'] ?? []) === [515152],
    'undo tar bort oförändrade klara rader och lämnar den ändrade, fick ' . json_encode($undo)
);
completed_ok((int) $pdo->query('SELECT COUNT(*) FROM reminders WHERE id = 515151')->fetchColumn() === 0, 'oförändrad klar kopia är borta');
completed_ok((string) $pdo->query('SELECT note FROM reminders WHERE id = 515152')->fetchColumn() === 'ändrad', 'ändrad klar rad finns kvar');
completed_ok((string) $pdo->query('SELECT note FROM reminders WHERE id = 424242')->fetchColumn() === 'keep-note', 'undo rör inte andra id');
completed_ok(md5((string) $pdo->query("SELECT group_concat(reminder_id || ':' || payload, '|') FROM purchases")->fetchColumn()) === $purchaseHash, 'undo rör inte ordrar');

$openWrite = jeab_reminders_restore_from_backup_dispatch($store, [
    'ids' => [616162],
    'completed' => 0,
    'dry' => 0,
]);
$leftOpen = $pdo->query('SELECT completed_at, updated_at FROM reminders WHERE id = 616162')->fetch();
$openLog = $pdo->query('SELECT completed_at FROM reminder_restore_log WHERE id = 616162')->fetch();
completed_ok(
    is_array($leftOpen)
    && ($leftOpen['completed_at'] === null || $leftOpen['completed_at'] === '')
    && (string) $leftOpen['updated_at'] === '2018-02-02 00:00:00'
    && is_array($openLog)
    && ($openLog['completed_at'] === null || $openLog['completed_at'] === ''),
    'completed=0 sparar raden öppen och loggar ingen klar-tid'
);
$openUndo = jeab_reminders_restore_undo_dispatch($store, ['batch' => (string) ($openWrite['batch'] ?? ''), 'dry' => 0]);
completed_ok(($openUndo['deleted'] ?? []) === [616162], 'undo tar bort den öppna raden');

$beforeNow = jeab_now();
$nowWrite = jeab_reminders_restore_from_backup_dispatch($store, [
    'ids' => [818181],
    'completed' => 1,
    'dry' => 0,
]);
$afterNow = jeab_now();
$noDate = $pdo->query('SELECT completed_at, updated_at, created_at FROM reminders WHERE id = 818181')->fetch();
$noDateLog = $pdo->query('SELECT completed_at FROM reminder_restore_log WHERE id = 818181')->fetch();
$noDateItem = completed_item($nowWrite, 818181);
completed_ok(
    is_array($noDate)
    && is_array($noDateLog)
    && (string) $noDate['completed_at'] === (string) $noDate['updated_at']
    && (string) $noDate['completed_at'] === (string) ($noDateLog['completed_at'] ?? '')
    && (string) $noDate['completed_at'] >= $beforeNow
    && (string) $noDate['completed_at'] <= $afterNow
    && (string) $noDate['created_at'] === '2017-01-01 00:00:00'
    && is_array($noDateItem)
    && (int) ($noDateItem['completed'] ?? 0) === 1
    && array_key_exists('date', $noDateItem)
    && $noDateItem['date'] === null,
    'utan kopiedatum blir klar-tiden nu, fick ' . json_encode($noDate)
);
$nowUndo = jeab_reminders_restore_undo_dispatch($store, ['batch' => (string) ($nowWrite['batch'] ?? ''), 'dry' => 0]);
completed_ok(($nowUndo['deleted'] ?? []) === [818181], 'undo tar bort raden som klarmarkerades med nu');
completed_ok((int) $pdo->query('SELECT COUNT(*) FROM reminders WHERE id = 818181')->fetchColumn() === 0, 'raden utan datum är borta');
completed_ok(completed_json_files($tmp) === $jsonBefore, 'ingen json skrevs');
completed_ok(md5((string) $pdo->query("SELECT group_concat(reminder_id || ':' || payload, '|') FROM purchases")->fetchColumn()) === $purchaseHash, 'ordrarna är orörda till slut');
completed_ok(md5((string) $pdo->query("SELECT group_concat(id || ':' || customer_name || ':' || updated_at, '|') FROM bookings")->fetchColumn()) === $bookingHash, 'bokningarna är orörda till slut');

echo $fail === 0 ? "OK  reminders restore completed\n" : "\n{$fail} failed\n";
exit($fail === 0 ? 0 : 1);
