<?php

declare(strict_types=1);

/**
 * bookings.unlinked_for_contacts, bookings.link_order och återställning
 * av en påminnelse som bara finns i data/.private/backups.
 * Inget utskick. Frysta ögonblicksbilder lämnas orörda.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-book-link-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/.private/backups', 0775, true);
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
require_once dirname(__DIR__) . '/src/bookings_link_order.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['jeab_email'] = 'demo@example.test';

function blk(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$root = dirname(__DIR__);
$hook = (string) file_get_contents($root . '/hook.php');
foreach (['bookings.unlinked_for_contacts', 'bookings.link_order', 'bookings.link_order_undo'] as $action) {
    blk(str_contains($hook, "'" . $action . "'"), 'hook tar ' . $action);
}
$linkSrc = (string) file_get_contents($root . '/src/bookings_link_order.php');
$restoreSrc = (string) file_get_contents($root . '/src/restore_entities.php');
blk(preg_match('/jeab_(send_|sms_|fortnox|graph_|mail_)/', $linkSrc) !== 1, 'länken anropar inget utskick');
blk(preg_match('/jeab_(send_|sms_|fortnox|graph_|mail_)/', $restoreSrc) !== 1, 'återställningen anropar inget utskick');

$store = jeab_store();
blk($store instanceof JeabPdoStore, 'lagret är öppet');
$GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
$pdo = $store->pdo();
jeab_purchases_store_ensure($pdo);
$pdo->exec('ALTER TABLE bookings ADD COLUMN order_id INTEGER');
$pdo->exec('ALTER TABLE bookings ADD COLUMN reminder_id INTEGER');

$undoPdo = jeab_contacts_sole_undo_pdo($store);
blk($undoPdo instanceof PDO, 'ångerloggen går att öppna');
$undoPdo->prepare('INSERT INTO contacts_sole_undo (batch_id, seq, kind, entity, entity_id, before_json, after_json, created_at, undone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)')
    ->execute(['batchlink', 1, 'snapshot', 'census', 'batchlink', '{"ids":{"orders":[1]}}', '{}', '2026-10-01 00:00:00']);
$seal = static fn (): array => $undoPdo->query("SELECT id, before_json, undone FROM contacts_sole_undo WHERE kind = 'snapshot' ORDER BY id")->fetchAll();
$sealBefore = $seal();

$customer = $store->insertCustomer('Ada Hemlig', '2026-10-01 08:00:00');
$book = static function (string $contact, string $start) use ($store, $customer): int {
    $row = $store->insertBooking([
        'customer_id' => $customer['id'],
        'customer_name' => 'Ada Hemlig',
        'email' => 'secret-book@example.test',
        'phone' => '0700111222',
        'address' => 'Hemliggatan 1',
        'assignees' => ['Elin'],
        'company' => 'jarfalla',
        'job_type' => 'batteri',
        'starts_at' => $start,
        'ends_at' => '2026-10-02 10:00:00',
        'status' => 'booked',
        'contact_id' => $contact,
        'created_at' => '2026-10-01 08:00:00',
        'updated_at' => '2026-10-01 08:00:00',
    ]);

    return (int) ($row['id'] ?? 0);
};

$open = $book('kt_a', '2026-10-02 09:00:00');
$early = $book('kt_a', '2026-10-01 08:00:00');
$taken = $book('kt_a', '2026-10-03 08:00:00');
$other = $book('kt_b', '2026-10-04 08:00:00');
$blank = $book('', '2026-10-05 08:00:00');
blk($open > 0 && $early > 0 && $taken > 0 && $other > 0 && $blank > 0, 'bokningarna finns');
$pdo->prepare('UPDATE bookings SET reminder_id = 7701 WHERE id = ?')->execute([$early]);
$pdo->prepare('UPDATE bookings SET company = ? WHERE id = ?')->execute(['malmo', $other]);

$put = static function (int $id, string $contact) : void {
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
        'name' => 'Ada Hemlig',
        'email' => 'secret-book@example.test',
    ]);
};
$put(8801, 'kt_a');
$put(8802, 'kt_other');
$put(8803, 'kt_a');
$pdo->prepare('UPDATE bookings SET order_id = 8803 WHERE id = ?')->execute([$taken]);
$purchaseHash = md5((string) $pdo->query("SELECT group_concat(reminder_id || ':' || payload, '|') FROM purchases")->fetchColumn());

$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = '';
$denied = jeab_bookings_link_dispatch($store, ['action' => 'bookings.unlinked_for_contacts', 'contact_ids' => ['kt_a']]);
blk(empty($denied['ok']) && (int) ($denied['status'] ?? 0) === 401, 'utan token läses inget');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = 'tok-bookings-link';
$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-bookings-link']));

$bad = jeab_bookings_link_dispatch($store, ['action' => 'bookings.unlinked_for_contacts', 'contact_ids' => 'kt_a']);
blk(empty($bad['ok']) && (int) ($bad['status'] ?? 0) === 422, 'contact_ids ska vara en lista');
$emailId = jeab_bookings_link_dispatch($store, ['action' => 'bookings.unlinked_for_contacts', 'contact_ids' => ['secret-book@example.test']]);
blk(empty($emailId['ok']), 'e-post tas inte emot som kontakt-id');

$list = jeab_bookings_link_dispatch($store, [
    'action' => 'bookings.unlinked_for_contacts',
    'contact_ids' => ['kt_b', 'kt_a', 'kt_none'],
]);
blk(!empty($list['ok']) && !empty($list['read_only']) && (int) ($list['sent'] ?? 1) === 0 && (int) ($list['calendar'] ?? 1) === 0 && (int) ($list['mail'] ?? 1) === 0 && (int) ($list['sms'] ?? 1) === 0, 'listan är läsning och skickar inget');
$byContact = [];
foreach (is_array($list['contacts'] ?? null) ? $list['contacts'] : [] as $group) {
    if (is_array($group)) {
        $byContact[(string) ($group['contact_id'] ?? '')] = $group['bookings'] ?? [];
    }
}
$idsOf = static function (array $rows): array {
    $ids = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $ids[] = (int) ($row['id'] ?? 0);
        }
    }

    return $ids;
};
blk($idsOf($byContact['kt_a'] ?? []) === [$early, $open], 'kt_a får bokningar utan order, sorterade på datum, fick ' . json_encode($idsOf($byContact['kt_a'] ?? [])));
blk((int) (($byContact['kt_a'][0]['reminder_id'] ?? 0)) === 7701 && ($byContact['kt_a'][0]['date'] ?? '') === '2026-10-01' && ($byContact['kt_a'][0]['company'] ?? '') === 'jarfalla', 'raden har id, datum, bolag och reminder_id');
blk($idsOf($byContact['kt_b'] ?? []) === [$other] && ($byContact['kt_b'][0]['company'] ?? '') === 'malmo', 'kt_b har sin bokning');
blk(($byContact['kt_none'] ?? null) === [], 'kontakt utan bokning är en tom lista');
blk(!in_array($taken, $idsOf($byContact['kt_a'] ?? []), true), 'bokning med order_id hoppas över');
$listJson = json_encode($list, JSON_UNESCAPED_UNICODE);
blk(is_string($listJson) && !str_contains($listJson, 'Hemlig') && !str_contains($listJson, 'secret-book') && !str_contains($listJson, '0700') && !str_contains($listJson, '@'), 'listan saknar personuppgifter');

$dryLink = jeab_bookings_link_dispatch($store, [
    'action' => 'bookings.link_order',
    'booking_id' => $open,
    'order_id' => 8801,
]);
blk((int) ($dryLink['dry'] ?? 0) === 1 && (int) ($dryLink['would_link'] ?? 0) === 1 && (int) ($dryLink['sent'] ?? 1) === 0 && (int) ($dryLink['calendar'] ?? 1) === 0, 'torr länk skickar inget');
blk($pdo->query('SELECT order_id FROM bookings WHERE id = ' . $open)->fetchColumn() === null, 'torr länk skriver inte');
$noConfirm = jeab_bookings_link_dispatch($store, [
    'action' => 'bookings.link_order',
    'booking_id' => $open,
    'order_id' => 8801,
    'dry' => 0,
]);
blk(empty($noConfirm['ok']) && $pdo->query('SELECT order_id FROM bookings WHERE id = ' . $open)->fetchColumn() === null, 'utan confirm skrivs inget');
$wrong = jeab_bookings_link_dispatch($store, [
    'action' => 'bookings.link_order',
    'booking_id' => $open,
    'order_id' => 8802,
    'dry' => 0,
    'confirm' => 'link',
]);
blk(empty($wrong['ok']) && (int) ($wrong['status'] ?? 0) === 409, 'olika kontakt kopplas inte');
$already = jeab_bookings_link_dispatch($store, [
    'action' => 'bookings.link_order',
    'booking_id' => $taken,
    'order_id' => 8801,
    'dry' => 0,
    'confirm' => 'link',
]);
blk(empty($already['ok']) && (int) ($already['status'] ?? 0) === 409, 'bokning som redan har order kopplas inte');
$emptyContact = jeab_bookings_link_dispatch($store, [
    'action' => 'bookings.link_order',
    'booking_id' => $blank,
    'order_id' => 8801,
    'dry' => 0,
    'confirm' => 'link',
]);
blk(empty($emptyContact['ok']), 'tom kontakt kopplas inte');

$linked = jeab_bookings_link_dispatch($store, [
    'action' => 'bookings.link_order',
    'booking_id' => $open,
    'order_id' => 8801,
    'dry' => 0,
    'confirm' => 'link',
]);
$undoId = (string) ($linked['undo_id'] ?? '');
blk(!empty($linked['ok']) && (int) ($linked['sent'] ?? 1) === 0 && (int) ($linked['mail'] ?? 1) === 0 && (int) ($linked['sms'] ?? 1) === 0 && (int) ($linked['calendar'] ?? 1) === 0, 'våt länk skickar inget');
blk(preg_match('/\A[a-f0-9]{32}\z/', $undoId) === 1, 'våt länk ger undo_id');
blk((int) $pdo->query('SELECT order_id FROM bookings WHERE id = ' . $open)->fetchColumn() === 8801, 'order_id sattes');
blk(md5((string) $pdo->query("SELECT group_concat(reminder_id || ':' || payload, '|') FROM purchases")->fetchColumn()) === $purchaseHash, 'ordern är orörd');
$linkedJson = json_encode($linked, JSON_UNESCAPED_UNICODE);
blk(is_string($linkedJson) && !str_contains($linkedJson, 'Hemlig') && !str_contains($linkedJson, 'secret-book') && !str_contains($linkedJson, '@'), 'länksvaret saknar personuppgifter');
$again = jeab_bookings_link_dispatch($store, [
    'action' => 'bookings.link_order',
    'booking_id' => $open,
    'order_id' => 8801,
    'dry' => 0,
    'confirm' => 'link',
]);
blk(empty($again['ok']), 'samma bokning kopplas inte en gång till');

$undoDry = jeab_bookings_link_dispatch($store, ['action' => 'bookings.link_order_undo', 'undo_id' => $undoId]);
blk(!empty($undoDry['ok']) && (int) ($undoDry['dry'] ?? 0) === 1 && (int) $pdo->query('SELECT order_id FROM bookings WHERE id = ' . $open)->fetchColumn() === 8801, 'ånger dry lämnar länken');
$undoNo = jeab_bookings_link_dispatch($store, ['action' => 'bookings.link_order_undo', 'undo_id' => $undoId, 'dry' => 0]);
blk(empty($undoNo['ok']) && (int) $pdo->query('SELECT order_id FROM bookings WHERE id = ' . $open)->fetchColumn() === 8801, 'ånger utan confirm lämnar länken');
$undo = jeab_bookings_link_dispatch($store, ['action' => 'bookings.link_order_undo', 'undo_id' => $undoId, 'dry' => 0, 'confirm' => 'undo']);
blk(!empty($undo['ok']) && (int) ($undo['sent'] ?? 1) === 0 && (int) ($undo['calendar'] ?? 1) === 0 && $pdo->query('SELECT order_id FROM bookings WHERE id = ' . $open)->fetchColumn() === null, 'ångern släpper order_id');
blk($seal() === $sealBefore, 'länken rör inte frysta ögonblicksbilder');

$relink = jeab_bookings_link_dispatch($store, [
    'action' => 'bookings.link_order',
    'booking_id' => $open,
    'order_id' => 8801,
    'dry' => 0,
    'confirm' => 'link',
]);
$pdo->prepare('UPDATE bookings SET order_id = 8803 WHERE id = ?')->execute([$open]);
$keptUndo = jeab_bookings_link_dispatch($store, [
    'action' => 'bookings.link_order_undo',
    'undo_id' => (string) ($relink['undo_id'] ?? ''),
    'dry' => 0,
    'confirm' => 'undo',
]);
blk(!empty($keptUndo['ok']) && (int) $pdo->query('SELECT order_id FROM bookings WHERE id = ' . $open)->fetchColumn() === 8803, 'ångern skriver inte över en senare order');

$privateA = 550001;
$privateB = 550002;
$privateLive = 550003;
file_put_contents($tmp . '/.private/backups/paminnelse-a.json', json_encode([
    'reminder' => [
        'id' => $privateA,
        'company' => 'jarfalla',
        'status' => 'done',
        'contact_id' => 'kt_priv',
        'order_id' => 6601,
        'address' => 'Backupgatan 9',
        'title' => 'Backupgatan 9',
        'completed_at' => '2026-10-08 09:00:00',
        'created_at' => '2026-10-08 08:00:00',
        'updated_at' => '2026-10-08 09:00:00',
        'name' => 'Ada Hemlig',
        'email' => 'secret-book@example.test',
        'phone' => '0700111222',
    ],
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/.private/backups/paminnelse-b.json', json_encode([
    'reminders' => [[
        'id' => $privateB,
        'company' => 'malmo',
        'status' => 'open',
        'contact_id' => 'kt_b',
        'order_id' => 6602,
        'title' => 'Andra gatan 2',
        'created_at' => '2026-10-09 08:00:00',
        'updated_at' => '2026-10-09 08:00:00',
        'name' => 'Ada Hemlig',
        'email' => 'secret-book@example.test',
    ], [
        'id' => $privateLive,
        'company' => 'jarfalla',
        'status' => 'open',
        'contact_id' => 'kt_live',
        'order_id' => 6603,
        'title' => 'Kvar gatan 3',
        'created_at' => '2026-10-09 08:00:00',
        'updated_at' => '2026-10-09 08:00:00',
        'email' => 'secret-book@example.test',
    ]],
], JSON_UNESCAPED_UNICODE));

$report = jeab_ordrar_missing_report_dispatch($store, ['since' => '2026-01-01']);
$found = [];
foreach (['jarfalla', 'malmo'] as $company) {
    foreach ($report['companies'][$company]['reminders'] ?? [] as $row) {
        if (is_array($row)) {
            $found[(int) ($row['id'] ?? 0)] = $row;
        }
    }
}
blk(
    ($found[$privateA]['file'] ?? '') === 'paminnelse-a.json'
    && ($found[$privateA]['status'] ?? '') === 'done'
    && ($found[$privateA]['contact_id'] ?? '') === 'kt_priv'
    && ($found[$privateA]['order_id'] ?? 0) === 6601
    && ($found[$privateA]['reason'] ?? '') === 'backup',
    'rapporten visar filen för påminnelsen som bara finns i backup, fick ' . json_encode($found[$privateA] ?? null)
);
blk(
    ($found[$privateB]['file'] ?? '') === 'paminnelse-b.json'
    && ($found[$privateB]['status'] ?? '') === 'open'
    && ($found[$privateB]['contact_id'] ?? '') === 'kt_b'
    && ($found[$privateB]['order_id'] ?? 0) === 6602,
    'malmö-raden har samma fält'
);
$reportJson = json_encode($report, JSON_UNESCAPED_UNICODE);
blk(is_string($reportJson) && !str_contains($reportJson, 'Hemlig') && !str_contains($reportJson, 'secret-book') && !str_contains($reportJson, '0700') && !str_contains($reportJson, '@'), 'rapporten saknar personuppgifter');

$dryReminder = jeab_ordrar_restore_from_snapshot_dispatch($store, ['type' => 'reminder', 'ids' => [$privateA]]);
blk((int) ($dryReminder['dry'] ?? 0) === 1 && (int) ($dryReminder['sent'] ?? 1) === 0 && (int) ($dryReminder['calendar'] ?? 1) === 0 && ((int) ($dryReminder['would_restore'][0]['id'] ?? 0)) === $privateA, 'torr återställning från backup visas');
blk(!is_array(jeab_get_reminder($store, $privateA)), 'torr återställning skriver inte');
$need = jeab_ordrar_restore_from_snapshot_dispatch($store, ['type' => 'reminder', 'ids' => [$privateA], 'dry' => 0]);
blk(empty($need['ok']) && !is_array(jeab_get_reminder($store, $privateA)), 'backup-påminnelse kräver confirm');
$wetReminder = jeab_ordrar_restore_from_snapshot_dispatch($store, [
    'type' => 'reminder',
    'ids' => [$privateA],
    'dry' => 0,
    'confirm' => 'restore',
]);
$restored = jeab_get_reminder($store, $privateA);
blk(!empty($wetReminder['ok']) && is_string($wetReminder['undo_id'] ?? null) && is_array($restored) && (string) ($restored['contact_id'] ?? '') === 'kt_priv', 'påminnelsen tas från backupfilen');
blk((string) ($restored['email'] ?? '') === '' && !str_contains((string) json_encode($wetReminder), 'secret-book') && !str_contains((string) json_encode($wetReminder), 'Hemlig'), 'återställningen sparar inte namn eller mejl i svaret');
$skipLive = jeab_ordrar_restore_from_snapshot_dispatch($store, ['type' => 'reminder', 'ids' => [$privateA], 'dry' => 0, 'confirm' => 'restore']);
blk(($skipLive['would_restore'] ?? null) === [] && (($skipLive['skipped'][0]['reason'] ?? '') === 'exists'), 'befintlig påminnelse skrivs inte över');
$undoReminder = jeab_ordrar_restore_undo_dispatch($store, ['undo_id' => (string) $wetReminder['undo_id'], 'dry' => 0]);
blk(!empty($undoReminder['ok']) && (int) ($undoReminder['sent'] ?? 1) === 0 && !is_array(jeab_get_reminder($store, $privateA)), 'ångern tar bort bara den återställda påminnelsen');

$now = jeab_now();
$pdo->prepare('INSERT INTO reminders (id, list_id, title, company, contact_id, note, created_at, updated_at) VALUES (?, 1, ?, ?, ?, ?, ?, ?)')
    ->execute([$privateLive, 'Kvar', 'jarfalla', 'kt_live', 'keep-note', $now, $now]);
$backupDry = jeab_reminders_restore_from_backup_dispatch($store, [
    'ids' => [$privateB, $privateLive],
    'source' => 'backup',
]);
$itemB = null;
foreach (is_array($backupDry['items'] ?? null) ? $backupDry['items'] : [] as $item) {
    if (is_array($item) && (int) ($item['id'] ?? 0) === $privateB) {
        $itemB = $item;
    }
}
blk((int) ($backupDry['dry'] ?? 0) === 1 && is_array($itemB) && ($itemB['file'] ?? '') === 'paminnelse-b.json' && ($itemB['via'] ?? '') === 'backup', 'restore_from_backup läser privat backup');
blk(!is_array(jeab_get_reminder($store, $privateB)), 'torr backup-restore skriver inte');
$backupNo = jeab_reminders_restore_from_backup_dispatch($store, [
    'ids' => [$privateB],
    'source' => 'backup',
    'dry' => 0,
]);
blk(empty($backupNo['ok']) && !is_array(jeab_get_reminder($store, $privateB)), 'privat backup kräver confirm');
$backupWet = jeab_reminders_restore_from_backup_dispatch($store, [
    'ids' => [$privateB, $privateLive],
    'source' => 'backup',
    'dry' => 0,
    'confirm' => 'restore',
]);
blk(
    !empty($backupWet['ok'])
    && (int) ($backupWet['sent'] ?? 1) === 0
    && ($backupWet['inserted'] ?? []) === [$privateB]
    && ($backupWet['skipped'] ?? []) === [$privateLive],
    'bara den saknade raden infogas, fick ' . json_encode($backupWet['inserted'] ?? null)
);
$keptNote = (string) $pdo->query('SELECT note FROM reminders WHERE id = ' . $privateLive)->fetchColumn();
blk($keptNote === 'keep-note', 'levande rad skrivs inte över');
$backupJson = json_encode($backupWet, JSON_UNESCAPED_UNICODE);
blk(is_string($backupJson) && !str_contains($backupJson, 'secret-book') && !str_contains($backupJson, 'Hemlig') && !str_contains($backupJson, '@'), 'backup-svaret saknar personuppgifter');
$batch = (string) ($backupWet['batch'] ?? '');
$backupUndo = jeab_reminders_restore_undo_dispatch($store, ['batch' => $batch, 'dry' => 0]);
blk(!empty($backupUndo['ok']) && (int) ($backupUndo['sent'] ?? 1) === 0 && !is_array(jeab_get_reminder($store, $privateB)) && $keptNote === (string) $pdo->query('SELECT note FROM reminders WHERE id = ' . $privateLive)->fetchColumn(), 'ångern tar bort backup-raden och lämnar den levande');
blk($seal() === $sealBefore, 'återställningen rör inte frysta ögonblicksbilder');

echo "bookings_link_order_test ok\n";
