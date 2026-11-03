<?php

declare(strict_types=1);

/**
 * ordrar.restore_from_snapshot, missing_report, delete_audit.
 * Torr och våt återställning, ånger, ingen överskrivning, en bokning,
 * bulk-sparning och sammanslagning tar inte bort ordrar, inget skickas.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-ord-restore-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND');
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
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['jeab_email'] = 'demo@example.test';
$_COOKIE['jeab_auth'] = '';

function ors(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
foreach (['ordrar.restore_from_snapshot', 'ordrar.restore_undo', 'ordrar.missing_report', 'ordrar.delete_audit'] as $action) {
    ors(str_contains($hook, "'" . $action . "'"), 'hook tar ' . $action);
}
$restoreSrc = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar_restore.php');
ors(preg_match('/jeab_(send_|sms_|fortnox|graph_|mail_)/', $restoreSrc) !== 1, 'återställningen anropar inget utskick');

$store = jeab_store();
ors($store instanceof JeabPdoStore, 'lagret är öppet');
$GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
$pdo = $store->pdo();
jeab_purchases_store_ensure($pdo);
$pdo->exec('ALTER TABLE bookings ADD COLUMN order_id INTEGER');

$customer = $store->insertCustomer('Ada Borta', '2026-10-01 08:00:00');
$book = static function (string $contact, string $start) use ($store, $customer): int {
    $row = $store->insertBooking([
        'customer_id' => $customer['id'],
        'customer_name' => 'Ada Borta',
        'email' => 'ada.borta@example.test',
        'phone' => '0700000000',
        'assignees' => ['Elin'],
        'company' => 'jarfalla',
        'job_type' => 'batteri',
        'starts_at' => $start,
        'ends_at' => '2026-10-01 10:00:00',
        'status' => 'booked',
        'contact_id' => $contact,
        'created_at' => '2026-10-01 08:00:00',
        'updated_at' => '2026-10-01 08:00:00',
    ]);

    return (int) ($row['id'] ?? 0);
};

$make = static function (string $title, string $contact, string $company, string $created) use ($store): int {
    $row = jeab_create_reminder($store, $title, '2026-10-01 00:00:00', 'notering', null, [
        'customer' => $title,
        'company' => $company,
        'tags' => ['vunnen'],
        'address' => 'Testgatan 1',
        'contact_id' => $contact,
        'kind' => 'batteri',
    ]);
    $id = (int) ($row['id'] ?? 0);
    jeab_update_reminder($store, $id, [
        'completed_at' => '2026-10-01 15:00:00',
        'contact_id' => $contact,
        'company' => $company,
        'address' => 'Testgatan 1',
    ]);

    return $id;
};

$back = $make('Tillbaka', 'kt_back', 'jarfalla', '2026-10-01 08:00:00');
$amb = $make('Tveksam', 'kt_amb', 'jarfalla', '2026-10-03 08:00:00');
$keep = $make('Kvar', 'kt_keep', 'jarfalla', '2026-10-01 08:00:00');
$gone = $make('Borta', 'kt_gone', 'jarfalla', '2026-10-01 08:00:00');
$other = $make('Malmö', 'kt_malmo', 'malmo', '2026-10-04 08:00:00');
ors($back > 0 && $amb > 0 && $keep > 0 && $gone > 0 && $other > 0, 'påminnelserna finns');

$one = $book('kt_back', '2026-10-01 08:00:00');
$twoA = $book('kt_amb', '2026-10-03 08:00:00');
$twoB = $book('kt_amb', '2026-10-03 11:00:00');
ors($one > 0 && $twoA > 0 && $twoB > 0 && $twoA !== $twoB, 'bokningarna finns');

$put = static function (int $id, string $contact) use ($store): void {
    jeab_purchase_put([
        'reminder_id' => $id,
        'contact_id' => $contact,
        'kind' => 'batteri',
        'company' => 'jarfalla',
        'address' => 'Testgatan 1',
        'step' => 1,
        'status' => 'done',
        'created_at' => '2026-10-01 08:00:00',
        'updated_at' => '2026-10-01 08:00:00',
    ]);
};
$put($keep, 'kt_keep');
$put($gone, 'kt_gone');
jeab_ordrar_delete(['id' => $gone], $store);
ors(!is_array(jeab_purchase_get($gone)), 'raderad order är borta');
ors(jeab_ordrar_deleted_blocks($gone), 'raderingen har spärr');

$snap = [
    'counts' => ['orders' => 5],
    'ids' => ['orders' => [$back, $amb, $keep, $gone, $other], 'reminders' => [], 'bookings' => []],
    'links' => [
        'orders' => [
            (string) $back => 'kt_back',
            (string) $amb => 'kt_amb',
            (string) $keep => 'kt_keep',
            (string) $gone => 'kt_gone',
            (string) $other => 'kt_malmo',
        ],
    ],
    'orders_rows' => [
        (string) $back => [
            'contact_id' => 'kt_back',
            'product' => 'batteri',
            'step' => 2,
            'status' => 'done',
            'created_at' => '2026-10-01 08:00:00',
            'updated_at' => '2026-10-02 09:00:00',
            'company' => 'jarfalla',
            'personnummer' => '19900101-1234',
            'name' => 'Hemligt Namn',
            'email' => 'secret@example.test',
        ],
        (string) $amb => [
            'contact_id' => 'kt_amb',
            'product' => 'sol',
            'step' => 1,
            'status' => 'done',
            'created_at' => '2026-10-03 08:00:00',
            'updated_at' => '2026-10-03 08:00:00',
            'company' => 'jarfalla',
        ],
        (string) $other => [
            'contact_id' => 'kt_malmo',
            'product' => 'laddbox',
            'step' => 1,
            'status' => 'open',
            'created_at' => '2026-10-04 08:00:00',
            'updated_at' => '2026-10-04 08:00:00',
            'company' => 'malmo',
        ],
    ],
    'personnummer' => '19900101-1234',
];
$undoPdo = jeab_contacts_sole_undo_pdo($store);
ors($undoPdo instanceof PDO, 'ångerloggen går att öppna');
$undoPdo->prepare('INSERT INTO contacts_sole_undo (batch_id, seq, kind, entity, entity_id, before_json, after_json, created_at, undone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)')
    ->execute(['batchsnap', 1, 'snapshot', 'census', 'batchsnap', json_encode($snap, JSON_UNESCAPED_UNICODE), '{}', '2026-10-05 00:00:00']);

file_put_contents($tmp . '/purchases.bak-old.json', json_encode([
    '900001' => ['reminder_id' => 900001, 'name' => 'Hemlig Backup', 'email' => 'backup@example.test'],
], JSON_UNESCAPED_UNICODE));
touch($tmp . '/purchases.bak-old.json', strtotime('2020-01-01 00:00:00'));
file_put_contents($tmp . '/purchases.json', json_encode([
    '900002' => ['reminder_id' => 900002, 'phone' => '0700111222'],
], JSON_UNESCAPED_UNICODE));

$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = '';
$denied = jeab_ordrar_restore_from_snapshot_dispatch($store, ['ids' => [$back], 'dry' => 0, 'confirm' => 'restore']);
ors(empty($denied['ok']) && (int) ($denied['status'] ?? 0) === 401, 'utan token skrivs inget');
ors(!is_array(jeab_purchase_get($back)), 'nekad återställning skapar ingen rad');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = 'tok-ordrar-restore';
$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-ordrar-restore']));

$ids = [$back, $amb, $keep, $gone, $other, 999999];
$dry = jeab_ordrar_restore_from_snapshot_dispatch($store, ['action' => 'ordrar.restore_from_snapshot', 'ids' => $ids, 'co' => 'jarfalla']);
ors((int) ($dry['dry'] ?? 0) === 1 && !empty($dry['read_only']) && (int) ($dry['sent'] ?? 1) === 0, 'dry skickar inget');
ors((int) ($dry['mail'] ?? 1) === 0 && (int) ($dry['sms'] ?? 1) === 0 && (int) ($dry['fortnox'] ?? 1) === 0 && (int) ($dry['calendar'] ?? 1) === 0, 'dry rör inte mail sms fortnox kalender');
$wouldIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), is_array($dry['would_restore'] ?? null) ? $dry['would_restore'] : []);
sort($wouldIds);
ors($wouldIds === [$back, $amb], 'dry visar vilka som skulle återställas, fick ' . json_encode($wouldIds));
$reasons = [];
foreach (is_array($dry['skipped'] ?? null) ? $dry['skipped'] : [] as $row) {
    if (is_array($row)) {
        $reasons[(int) ($row['id'] ?? 0)] = (string) ($row['reason'] ?? '');
    }
}
ors(($reasons[$keep] ?? '') === 'exists', 'finns redan hoppas över');
ors(($reasons[$gone] ?? '') === 'deleted', 'spärrad id hoppas över');
ors(($reasons[$other] ?? '') === 'company', 'annat bolag hoppas över');
ors(($reasons[999999] ?? '') === 'not_in_snapshot', 'id utan bild hoppas över');
ors(!is_array(jeab_purchase_get($back)), 'dry skriver ingen rad');
$dryJson = json_encode($dry, JSON_UNESCAPED_UNICODE);
ors(is_string($dryJson) && !str_contains($dryJson, '19900101') && !str_contains($dryJson, 'secret@') && !str_contains($dryJson, 'Hemligt') && !str_contains($dryJson, '0700'), 'dry saknar personuppgifter');

$needConfirm = jeab_ordrar_restore_from_snapshot_dispatch($store, ['ids' => [$back], 'dry' => 0, 'co' => 'jarfalla']);
ors(empty($needConfirm['ok']) && !is_array(jeab_purchase_get($back)), 'utan confirm skrivs inget');

$wet = jeab_ordrar_restore_from_snapshot_dispatch($store, [
    'ids' => $ids,
    'co' => 'jarfalla',
    'dry' => 0,
    'confirm' => 'restore',
]);
ors(!empty($wet['ok']) && (int) ($wet['sent'] ?? 1) === 0 && (int) ($wet['mail'] ?? 1) === 0, 'våt skickar inget');
$undoId = (string) ($wet['undo_id'] ?? '');
ors(preg_match('/\A[a-f0-9]{32}\z/', $undoId) === 1, 'våt ger undo_id');
$restored = jeab_purchase_get($back);
ors(is_array($restored), 'raden skapades');
ors((int) ($restored['reminder_id'] ?? 0) === $back, 'samma id');
ors((string) ($restored['contact_id'] ?? '') === 'kt_back', 'samma kontakt');
ors((string) ($restored['kind'] ?? '') === 'batteri', 'samma produkt');
ors((int) ($restored['step'] ?? 0) === 2 && (string) ($restored['status'] ?? '') === 'done', 'samma steg och status');
ors((string) ($restored['created_at'] ?? '') === '2026-10-01 08:00:00' && (string) ($restored['updated_at'] ?? '') === '2026-10-02 09:00:00', 'samma datum');
ors((int) ($restored['booking_id'] ?? 0) === $one, 'en bokning kopplades');
ors((int) $pdo->query('SELECT order_id FROM bookings WHERE id = ' . $one)->fetchColumn() === $back, 'bokningens order_id sattes');
$ambiguous = jeab_purchase_get($amb);
ors(is_array($ambiguous) && (int) ($ambiguous['booking_id'] ?? 0) === 0, 'två bokningar kopplas inte');
ors((int) $pdo->query('SELECT COUNT(*) FROM purchases')->fetchColumn() === 3, 'keep ligger kvar och två rader lades till');
ors(is_array(jeab_purchase_get($keep)), 'befintlig order skrevs inte över');
ors(!is_array(jeab_purchase_get($gone)), 'spärrad order återskapades inte');

$again = jeab_ordrar_restore_from_snapshot_dispatch($store, [
    'ids' => [$back],
    'dry' => 0,
    'confirm' => 'restore',
    'co' => 'jarfalla',
]);
ors(!empty($again['ok']) && ($again['would_restore'] ?? null) === [] && ((int) ($again['skipped'][0]['id'] ?? 0)) === $back, 'andra körningen skriver inte över');
ors((int) $pdo->query('SELECT order_id FROM bookings WHERE id = ' . $one)->fetchColumn() === $back, 'bokningen kopplades bara en gång');

$report = jeab_ordrar_missing_report_dispatch($store, ['since' => '2026-01-01']);
ors(!empty($report['ok']) && !empty($report['read_only']) && (int) ($report['sent'] ?? 1) === 0, 'missing_report är läsning');
$missingIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), is_array($report['missing'] ?? null) ? $report['missing'] : []);
ors(in_array($gone, $missingIds, true) && in_array(900002, $missingIds, true), 'rapporten tar bild och backup, fick ' . json_encode($missingIds));
ors(!in_array(900001, $missingIds, true), 'since hoppar över gammal backup');
ors(!in_array($back, $missingIds, true) && !in_array($keep, $missingIds, true), 'levande id är inte saknade');
$goneRow = null;
foreach (is_array($report['missing'] ?? null) ? $report['missing'] : [] as $row) {
    if (is_array($row) && (int) ($row['id'] ?? 0) === $gone) {
        $goneRow = $row;
    }
}
ors(is_array($goneRow) && is_array($goneRow['ordrar_deleted'] ?? null) && ($goneRow['ordrar_deleted']['at'] ?? '') !== '', 'spärren syns');
$openRow = null;
foreach (is_array($report['missing'] ?? null) ? $report['missing'] : [] as $row) {
    if (is_array($row) && (int) ($row['id'] ?? 0) === 900002) {
        $openRow = $row;
    }
}
ors(is_array($openRow) && array_key_exists('ordrar_deleted', $openRow) && $openRow['ordrar_deleted'] === null, 'backup utan spärr är null');
$reportJson = json_encode($report, JSON_UNESCAPED_UNICODE);
ors(is_string($reportJson) && !str_contains($reportJson, 'Hemlig') && !str_contains($reportJson, '0700') && !str_contains($reportJson, '@'), 'rapporten saknar personuppgifter');

$audit = jeab_ordrar_delete_audit_dispatch($store, ['since' => '2020-01-01']);
ors(!empty($audit['read_only']) && (int) ($audit['sent'] ?? 1) === 0, 'delete_audit är läsning');
$auditIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), is_array($audit['rows'] ?? null) ? $audit['rows'] : []);
ors(in_array($gone, $auditIds, true), 'raderingen finns i auditen');
$auditJson = json_encode($audit, JSON_UNESCAPED_UNICODE);
ors(is_string($auditJson) && !str_contains($auditJson, '@'), 'auditsvaret tar inte med mejl');

$undoDry = jeab_ordrar_restore_undo_dispatch($store, ['undo_id' => $undoId]);
ors(!empty($undoDry['ok']) && (int) ($undoDry['dry'] ?? 0) === 1 && is_array(jeab_purchase_get($back)), 'ånger dry lämnar raden');
$undo = jeab_ordrar_restore_undo_dispatch($store, ['undo_id' => $undoId, 'dry' => 0]);
ors(!empty($undo['ok']) && (int) ($undo['sent'] ?? 1) === 0 && !is_array(jeab_purchase_get($back)) && !is_array(jeab_purchase_get($amb)), 'ångern tar bort bara de återställda');
ors(is_array(jeab_purchase_get($keep)), 'ångern lämnar den gamla ordern');
ors($pdo->query('SELECT order_id FROM bookings WHERE id = ' . $one)->fetchColumn() === null, 'bokningens order_id släpptes');

$beforeBulk = (int) $pdo->query('SELECT COUNT(*) FROM purchases')->fetchColumn();
jeab_purchase_save_all([
    [
        'reminder_id' => $keep,
        'contact_id' => 'kt_keep',
        'kind' => 'batteri',
        'company' => 'jarfalla',
        'step' => 1,
        'status' => 'done',
        'created_at' => '2026-10-01 08:00:00',
        'updated_at' => '2026-10-01 08:00:00',
    ],
]);
ors((int) $pdo->query('SELECT COUNT(*) FROM purchases')->fetchColumn() === $beforeBulk && is_array(jeab_purchase_get($keep)), 'bulk-sparning tar inte bort ordrar');

$put($other, 'kt_from');
$otherRow = jeab_purchase_get($other);
ors(is_array($otherRow), 'ordern som ska flyttas finns');
$moved = jeab_purchase_all();
$changed = false;
foreach ($moved as $key => $row) {
    if (!is_array($row) || (string) ($row['contact_id'] ?? '') !== 'kt_from') {
        continue;
    }
    $row['contact_id'] = 'kt_to';
    $moved[$key] = $row;
    $changed = true;
}
ors($changed, 'sammanslagningen hittar kontaktfältet');
jeab_purchase_save_all($moved);
ors(is_array(jeab_purchase_get($other)) && (string) (jeab_purchase_get($other)['contact_id'] ?? '') === 'kt_to', 'sammanslagningen flyttar bara contact_id');
ors(is_array(jeab_purchase_get($keep)), 'sammanslagningen lämnar den andra ordern');

$threw = false;
try {
    jeab_purchase_delete($keep);
} catch (RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'Radera');
}
ors($threw && is_array(jeab_purchase_get($keep)), 'radering utanför Radera nekas');

$threw = false;
try {
    jeab_cutover_restore_dump('purchases', ['dir' => $tmp]);
} catch (RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'Radera');
}
ors($threw && is_array(jeab_purchase_get($keep)), 'JSON-återläsning får inte radera ordrar');

$threw = false;
try {
    jeab_cutover_delete_keys($pdo, ['table' => 'purchases', 'primary_key' => 'reminder_id'], [(string) $keep]);
} catch (RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'Radera');
}
ors($threw && is_array(jeab_purchase_get($keep)), 'cutover får inte radera ordrar');

$liveBefore = (int) $pdo->query('SELECT COUNT(*) FROM purchases')->fetchColumn();
$reminder = jeab_get_reminder($store, $keep);
ors(is_array($reminder), 'påminnelsen finns');
jeab_ordrar_vunnen_purchase($store, $keep, $reminder);
ors((int) $pdo->query('SELECT COUNT(*) FROM purchases')->fetchColumn() === $liveBefore && is_array(jeab_purchase_get($keep)), '#vunnen på nytt tar inte bort ordern');

$sealBefore = $undoPdo->query("SELECT id, before_json, undone FROM contacts_sole_undo WHERE kind = 'snapshot' ORDER BY id")->fetchAll();
$missReminder = 440001;
$felloggReminder = 440002;
$oldReminder = 440003;
$backupReminder = 440004;
$threadId = 'th-miss';
$extra = [
    'counts' => ['reminders' => 1, 'orders' => 0, 'bookings' => 0],
    'ids' => ['reminders' => [$missReminder], 'orders' => [], 'bookings' => [], 'threads' => [$threadId]],
    'links' => ['reminders' => [(string) $missReminder => 'kt_miss'], 'orders' => [], 'bookings' => []],
    'reminders_rows' => [
        (string) $missReminder => [
            'id' => $missReminder,
            'contact_id' => 'kt_miss',
            'company' => 'jarfalla',
            'address' => 'Saknadgatan 2',
            'title' => 'Saknadgatan 2',
            'status' => 'done',
            'created_at' => '2026-10-06 08:00:00',
            'updated_at' => '2026-10-06 09:00:00',
            'completed_at' => '2026-10-06 09:00:00',
            'name' => 'Hemligt Namn',
            'email' => 'secret@example.test',
            'phone' => '0700111222',
        ],
    ],
    'threads_rows' => [
        $threadId => [
            'id' => $threadId,
            'contact_id' => 'kt_thread',
            'company' => 'malmo',
            'status' => 'open',
            'created_at' => '2026-10-07 08:00:00',
            'updated_at' => '2026-10-07 08:00:00',
            'email' => 'secret@example.test',
        ],
    ],
];
$undoPdo->prepare('INSERT INTO contacts_sole_undo (batch_id, seq, kind, entity, entity_id, before_json, after_json, created_at, undone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)')
    ->execute(['batchmore', 1, 'snapshot', 'census', 'batchmore', json_encode($extra, JSON_UNESCAPED_UNICODE), '{}', '2026-10-08 00:00:00']);
$private = $tmp . '/.private/backups';
mkdir($private, 0775, true);
file_put_contents($private . '/reminder-bort.json', json_encode([
    'reminder' => [
        'id' => $backupReminder,
        'company' => 'jarfalla',
        'address' => 'Backupgatan 3',
        'status' => 'open',
        'contact_id' => 'kt_backup',
        'order_id' => 440004,
        'name' => 'Hemligt Namn',
        'email' => 'secret@example.test',
    ],
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/app-errors.jsonl', json_encode([
    'time' => '2026-10-30 12:00:00',
    'action' => 'reminder.tag',
    'entity' => 'reminder',
    'id' => $felloggReminder,
    'company' => 'jarfalla',
    'message' => 'Hemligt Namn secret@example.test',
]) . "\n" . json_encode([
    'time' => '2020-01-01 00:00:00',
    'entity' => 'reminder',
    'id' => $oldReminder,
    'company' => 'malmo',
]) . "\n");

$more = jeab_ordrar_missing_report_dispatch($store, ['since' => '2026-01-01']);
$jarfallaReminders = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $more['companies']['jarfalla']['reminders'] ?? []);
$malmoThreads = array_map(static fn (array $row): string => (string) ($row['id'] ?? ''), $more['companies']['malmo']['threads'] ?? []);
ors(in_array($missReminder, $jarfallaReminders, true) && in_array($backupReminder, $jarfallaReminders, true) && in_array($felloggReminder, $jarfallaReminders, true), 'rapporten tar påminnelser per bolag, fick ' . json_encode($jarfallaReminders));
ors(!in_array($oldReminder, $jarfallaReminders, true) && !in_array($oldReminder, array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $more['companies']['malmo']['reminders'] ?? []), true), 'fellogg äldre än sju dagar hoppas över');
ors($malmoThreads === [$threadId], 'rapporten tar ärenden per bolag, fick ' . json_encode($malmoThreads));
$reasons = [];
foreach (array_merge($more['companies']['jarfalla']['reminders'] ?? [], $more['companies']['malmo']['threads'] ?? []) as $row) {
    if (is_array($row)) {
        $reasons[(string) ($row['id'] ?? '')] = (string) ($row['reason'] ?? '');
    }
}
ors(($reasons[(string) $missReminder] ?? '') === 'snapshot' && str_contains($reasons[(string) $backupReminder] ?? '', 'backup') && str_contains($reasons[(string) $felloggReminder] ?? '', 'fellogg') && ($reasons[$threadId] ?? '') === 'snapshot', 'orsaken är snapshot, backup eller fellogg');
$backupRow = null;
foreach ($more['companies']['jarfalla']['reminders'] ?? [] as $row) {
    if (is_array($row) && (int) ($row['id'] ?? 0) === $backupReminder) {
        $backupRow = $row;
    }
}
ors(
    is_array($backupRow)
    && ($backupRow['file'] ?? '') === 'reminder-bort.json'
    && ($backupRow['status'] ?? '') === 'open'
    && ($backupRow['contact_id'] ?? '') === 'kt_backup'
    && ($backupRow['order_id'] ?? 0) === 440004
    && !array_key_exists('file', $more['companies']['jarfalla']['reminders'][0] ?? []),
    'backup-påminnelsen visar fil, status, kontakt och order, fick ' . json_encode($backupRow)
);
$moreJson = json_encode($more, JSON_UNESCAPED_UNICODE);
ors(is_string($moreJson) && !str_contains($moreJson, 'Hemlig') && !str_contains($moreJson, 'secret@') && !str_contains($moreJson, '0700'), 'utökad rapport saknar personuppgifter');

$srcMore = (string) file_get_contents(dirname(__DIR__) . '/src/restore_entities.php');
ors(!str_contains($srcMore, 'jeab_send_') && !str_contains($srcMore, 'jeab_sms_') && !str_contains($srcMore, 'jeab_fortnox') && !str_contains($srcMore, 'jeab_graph_'), 'påminnelse och ärende skickar inget');

$dryReminder = jeab_ordrar_restore_from_snapshot_dispatch($store, ['type' => 'reminder', 'ids' => [$missReminder], 'co' => 'jarfalla']);
ors((int) ($dryReminder['dry'] ?? 0) === 1 && (int) ($dryReminder['sent'] ?? 1) === 0 && ((int) ($dryReminder['would_restore'][0]['id'] ?? 0)) === $missReminder, 'torr påminnelse visas');
ors(!is_array(jeab_get_reminder($store, $missReminder)), 'torr påminnelse skriver inte');
$wetReminder = jeab_ordrar_restore_from_snapshot_dispatch($store, ['type' => 'reminder', 'ids' => [$missReminder], 'dry' => 0, 'confirm' => 'restore', 'co' => 'jarfalla']);
$restoredReminder = jeab_get_reminder($store, $missReminder);
ors(!empty($wetReminder['ok']) && is_string($wetReminder['undo_id'] ?? null) && is_array($restoredReminder) && (string) ($restoredReminder['contact_id'] ?? '') === 'kt_miss', 'påminnelsen återställs med samma id och kontakt');
ors((string) ($restoredReminder['address'] ?? '') === 'Saknadgatan 2' && !str_contains(json_encode($wetReminder), 'Hemlig'), 'påminnelsesvaret har adressen och inget namn');
$againReminder = jeab_ordrar_restore_from_snapshot_dispatch($store, ['type' => 'reminder', 'ids' => [$missReminder], 'dry' => 0, 'confirm' => 'restore']);
ors(($againReminder['would_restore'] ?? null) === [] && ((string) ($againReminder['skipped'][0]['reason'] ?? '')) === 'exists', 'påminnelsen skrivs inte över');

$dryThread = jeab_ordrar_restore_from_snapshot_dispatch($store, ['type' => 'thread', 'ids' => [$threadId], 'co' => 'malmo']);
ors((int) ($dryThread['dry'] ?? 0) === 1 && (int) ($dryThread['sent'] ?? 1) === 0 && (int) ($dryThread['calendar'] ?? 1) === 0, 'torrt ärende skickar inget');
$wetThread = jeab_ordrar_restore_from_snapshot_dispatch($store, ['type' => 'thread', 'ids' => [$threadId], 'dry' => 0, 'confirm' => 'restore', 'co' => 'malmo']);
$threadRow = $pdo->query("SELECT status, row_json FROM inkorg_threads WHERE id = " . $pdo->quote($threadId))->fetch();
ors(!empty($wetThread['ok']) && is_array($threadRow) && (string) ($threadRow['status'] ?? '') === 'open', 'ärendet återställs');
ors(!str_contains((string) ($threadRow['row_json'] ?? ''), 'secret@'), 'ärendets sparade rad tar inte med mejlet');
$againThread = jeab_ordrar_restore_from_snapshot_dispatch($store, ['type' => 'thread', 'ids' => [$threadId], 'dry' => 0, 'confirm' => 'restore']);
ors(($againThread['skipped'][0]['reason'] ?? '') === 'exists', 'ärendet skrivs inte över');
$sealAfter = $undoPdo->query("SELECT id, before_json, undone FROM contacts_sole_undo WHERE kind = 'snapshot' ORDER BY id")->fetchAll();
ors($sealAfter === $sealBefore || count($sealAfter) === count($sealBefore) + 1, 'återställningen lägger inte till en ögonblicksbild');
ors($sealBefore === array_slice($sealAfter, 0, count($sealBefore)), 'frysta ögonblicksbilder är orörda');

$undoReminder = jeab_ordrar_restore_undo_dispatch($store, ['undo_id' => (string) $wetReminder['undo_id'], 'dry' => 0]);
$undoThread = jeab_ordrar_restore_undo_dispatch($store, ['undo_id' => (string) $wetThread['undo_id'], 'dry' => 0]);
ors(!empty($undoReminder['ok']) && !is_array(jeab_get_reminder($store, $missReminder)), 'ångern tar bort den återställda påminnelsen');
ors(!empty($undoThread['ok']) && $pdo->query('SELECT id FROM inkorg_threads WHERE id = ' . $pdo->quote($threadId))->fetchColumn() === false, 'ångern tar bort det återställda ärendet');
ors(is_array(jeab_purchase_get($keep)), 'ångern lämnar ordrarna');

$threw = false;
try {
    jeab_reminders_sql_delete($pdo, [$keep]);
} catch (RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'Radera');
}
ors($threw && is_array(jeab_get_reminder($store, $keep)), 'påminnelse raderas inte utanför Radera');
$threw = false;
try {
    jeab_cutover_restore_dump('reminders', ['dir' => $tmp]);
} catch (RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'Radera');
}
ors($threw, 'cutover får inte radera påminnelser');
$threw = false;
try {
    jeab_cutover_delete_keys($pdo, ['table' => 'inkorg_threads', 'primary_key' => 'id'], [$threadId]);
} catch (RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'Radera');
}
ors($threw, 'cutover får inte radera ärenden');
jeab_restore_threads_table($pdo);
$pdo->prepare('INSERT INTO inkorg_threads (id, status, updated_at, row_json) VALUES (?, ?, ?, ?)')->execute(['th-keep', 'open', '2026-10-01 08:00:00', '{"status":"open"}']);
$pdo->prepare('INSERT INTO inkorg_threads (id, status, updated_at, row_json) VALUES (?, ?, ?, ?)')->execute(['th-drop', 'open', '2026-10-01 08:00:00', '{"status":"open"}']);
$inkorg = new JeabPdoInkorgStore($pdo);
$threw = false;
try {
    $inkorg->lockedThreads(static function (?array $doc): array {
        $threads = is_array($doc['threads'] ?? null) ? $doc['threads'] : [];
        unset($threads['th-drop']);

        return ['ok', ['threads' => $threads]];
    });
} catch (RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'Radera');
}
ors($threw && (string) $pdo->query("SELECT id FROM inkorg_threads WHERE id = 'th-drop'")->fetchColumn() === 'th-drop', 'bulk-sparning tar inte bort ärenden');

echo "ordrar_restore_snapshot_test ok\n";
