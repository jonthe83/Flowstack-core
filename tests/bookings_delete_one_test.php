<?php

declare(strict_types=1);

/**
 * bookings.delete_one och bookings.delete_one_undo.
 * Torr körning, vägran vid order/påminnelse/faktura/outlook, radering plus ångra,
 * kalenderlogg, överlapp och confirm. Bara påhittade uppgifter. Svaren får inte bära dem.
 * SQLite körs alltid. MariaDB körs om mariadb-install-db och mariadbd finns, annars SKIP.
 */

function bdo_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/** @param list<string> $forbidden */
function bdo_no_pii(array $result, array $forbidden): void
{
    $json = json_encode($result, JSON_UNESCAPED_UNICODE);
    bdo_assert(is_string($json), 'svaret kan kodas');
    foreach ($forbidden as $needle) {
        if ($needle !== '' && str_contains((string) $json, $needle)) {
            fwrite(STDERR, "FAIL personuppgift i svaret\n");
            exit(1);
        }
    }
    bdo_assert(!str_contains((string) $json, '@'), 'svaret har ingen e-post');
    foreach (['customer_name', 'email', 'phone', 'address', 'notes', 'assignee', 'assignees', 'booking', 'backup', 'name'] as $key) {
        bdo_assert(!array_key_exists($key, $result), 'svaret saknar ' . $key);
    }
}

/** @param list<string> $forbidden */
function bdo_call(JeabStore $store, array $input, array $forbidden): array
{
    $result = jeab_bookings_delete_one_dispatch($store, $input);
    bdo_no_pii($result, $forbidden);

    return $result;
}

function bdo_row(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function bdo_undo_count(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM booking_delete_undo')->fetchColumn();
}

function bdo_rm_tree(string $dir): void
{
    if ($dir === '' || $dir === '/' || !is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            bdo_rm_tree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function bdo_boot(string $backend, int $port): string
{
    $tmp = sys_get_temp_dir() . '/jeab-bdel-' . $backend . '-' . bin2hex(random_bytes(4));
    if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
        fwrite(STDERR, "FAIL temp\n");
        exit(1);
    }
    mkdir($tmp . '/secrets', 0775, true);
    mkdir($tmp . '/backups', 0775, true);
    register_shutdown_function(static function () use ($tmp): void {
        bdo_rm_tree($tmp);
    });
    $GLOBALS['_JEAB_DATA_DIR'] = $tmp;
    $GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
    $GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
    $GLOBALS['_JEAB_CALENDAR_LOG_BACKUP_DIR'] = $tmp . '/backups';
    putenv('CONTACTS_SOLE');
    putenv('CONTACTS_STRICT');
    putenv('JEAB_DISABLE_SQLITE=0');
    unset($_ENV['CONTACTS_SOLE'], $_ENV['CONTACTS_STRICT']);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $token = 'tok-bookings-delete-one';
    file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => $token]));
    $db = [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ];
    if ($backend === 'mysql') {
        if ($port < 1) {
            bdo_assert(false, 'MySQL saknar port');
        }
        $db = [
            'driver' => 'mysql',
            'sqlite_path' => $tmp . '/app.sqlite',
            'json_path' => $tmp . '/store.json',
            'mysql' => [
                'host' => '127.0.0.1;port=' . $port,
                'name' => 'jeab',
                'user' => 'jeab',
                'pass' => 'jeabpass',
                'charset' => 'utf8mb4',
            ],
        ];
    }
    file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
        'timezone' => 'Europe/Stockholm',
        'incoming_webhook_token' => 'not-the-inbox-token',
        'db' => $db,
    ], true) . ";\n");
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    require dirname(__DIR__) . '/src/bootstrap.php';
    require_once dirname(__DIR__) . '/src/bookings_delete_one.php';

    return $token;
}

/** @return array<int, array<string, mixed>> */
function bdo_log_for(int $id): array
{
    $data = jeab_calendar_log_read();
    $out = [];
    foreach (is_array($data['entries'] ?? null) ? $data['entries'] : [] as $entry) {
        if (is_array($entry) && (int) ($entry['booking_id'] ?? 0) === $id) {
            $out[] = $entry;
        }
    }

    return $out;
}

/**
 * @param array<string, mixed> $base
 * @return array<string, mixed>
 */
function bdo_book(JeabStore $store, array $base, string $starts, string $ends, array $extra = []): array
{
    return $store->insertBooking(array_replace($base, $extra, ['starts_at' => $starts, 'ends_at' => $ends]));
}

function bdo_hide_table(PDO $pdo, string $table): void
{
    $hidden = $table . '_bdo_hidden';
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $pdo->exec('DROP TABLE IF EXISTS `' . $hidden . '`');
        $pdo->exec('RENAME TABLE `' . $table . '` TO `' . $hidden . '`');

        return;
    }
    $pdo->exec('DROP TABLE IF EXISTS ' . $hidden);
    $pdo->exec('ALTER TABLE ' . $table . ' RENAME TO ' . $hidden);
}

function bdo_show_table(PDO $pdo, string $table): void
{
    $hidden = $table . '_bdo_hidden';
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $pdo->exec('RENAME TABLE `' . $hidden . '` TO `' . $table . '`');

        return;
    }
    $pdo->exec('ALTER TABLE ' . $hidden . ' RENAME TO ' . $table);
}

function bdo_scenarios(string $backend, int $port): void
{
    $token = bdo_boot($backend, $port);
$root = dirname(__DIR__);
$hook = (string) file_get_contents($root . '/hook.php');
$src = (string) file_get_contents($root . '/src/bookings_delete_one.php');
bdo_assert(str_contains($hook, "'bookings.delete_one'") && str_contains($hook, "'bookings.delete_one_undo'"), 'hook.php tar båda åtgärderna');
bdo_assert(str_contains($hook, 'bookings_delete_one.php'), 'hook.php laddar borttagningen');
bdo_assert(str_contains($src, 'jeab_booking_delete_silent('), 'raderar via jeab_booking_delete_silent');
bdo_assert(str_contains($src, 'jeab_pdo_transaction('), 'koll och radering ligger i en transaktion');
bdo_assert(str_contains($src, 'jeab_booking_delete_outlook_block('), 'outlook använder samma block');
bdo_assert(str_contains($src, 'jeab_booking_assignee_overlap('), 'återställning använder befintlig överlapp');
bdo_assert(str_contains($src, 'jeab_calendar_log_read(') && str_contains($src, 'jeab_calendar_log_write(') && str_contains($src, 'jeab_calendar_log_store_row('), 'kalenderloggen går via befintlig läs- och skrivväg');
bdo_assert(str_contains($src, "return ['unknown']"), 'databasfel blir unknown');
bdo_assert(jeab_driver() === $backend, 'testet använder ' . $backend);

$name = 'Fakebok Kundsson';
$email = 'fakebok@example.test';
$phone = '0701234999';
$address = 'Fakebokgatan 1';
$notes = 'Fakebok notering';
$otherName = 'Annan Boksson';
$otherEmail = 'annan@example.test';
$otherPhone = '0701234888';
$otherAddress = 'Annangatan 2';
$forbidden = [$name, 'Fakebok', 'fakebok', $email, $phone, $address, $notes, $otherName, 'Annan', $otherEmail, $otherPhone, $otherAddress, 'Annangatan'];

$store = jeab_store(true);
bdo_assert($store instanceof JeabPdoStore, 'pdo-lager');
$pdo = $store->pdo();
$customer = $store->insertCustomer($name, '2026-10-01 10:00:00');
$otherCustomer = $store->insertCustomer($otherName, '2026-10-01 10:00:00');
$base = [
    'customer_id' => (int) $customer['id'],
    'customer_name' => $name,
    'email' => $email,
    'phone' => $phone,
    'address' => $address,
    'notes' => $notes,
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'created_at' => '2026-10-01 10:00:00',
    'updated_at' => '2026-10-01 10:00:00',
];
$clean = $store->insertBooking($base + ['starts_at' => '2026-10-20 09:00:00', 'ends_at' => '2026-10-20 10:00:00']);
$sibling = $store->insertBooking([
    'customer_id' => (int) $otherCustomer['id'],
    'customer_name' => $otherName,
    'email' => $otherEmail,
    'phone' => $otherPhone,
    'address' => $otherAddress,
    'notes' => null,
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => '2026-11-02 07:00:00',
    'ends_at' => '2026-11-02 11:00:00',
    'created_at' => '2026-10-01 10:00:00',
    'updated_at' => '2026-10-01 10:00:00',
]);
$withReminder = $store->insertBooking($base + ['starts_at' => '2026-10-21 09:00:00', 'ends_at' => '2026-10-21 10:00:00']);
$withOrder = $store->insertBooking($base + ['starts_at' => '2026-10-22 09:00:00', 'ends_at' => '2026-10-22 10:00:00']);
$withInvoice = $store->insertBooking($base + ['starts_at' => '2026-10-23 09:00:00', 'ends_at' => '2026-10-23 10:00:00']);
$withAll = $store->insertBooking($base + ['starts_at' => '2026-10-24 09:00:00', 'ends_at' => '2026-10-24 10:00:00']);
$viaReminder = $store->insertBooking($base + ['starts_at' => '2026-10-25 09:00:00', 'ends_at' => '2026-10-25 10:00:00']);
$cleanId = (int) $clean['id'];
$siblingId = (int) $sibling['id'];

$store->insertReminder([
    'title' => 'Folj upp',
    'booking_id' => (int) $withReminder['id'],
    'completed_at' => '2026-10-21 12:00:00',
    'due_at' => '2026-10-22 09:00:00',
    'created_at' => '2026-10-21 08:00:00',
    'updated_at' => '2026-10-21 08:00:00',
]);
$allReminder = $store->insertReminder([
    'title' => 'Folj upp',
    'booking_id' => (int) $withAll['id'],
    'completed_at' => '2026-10-24 12:00:00',
    'due_at' => '2026-10-25 09:00:00',
    'created_at' => '2026-10-24 08:00:00',
    'updated_at' => '2026-10-24 08:00:00',
]);
$viaRow = $store->insertReminder([
    'title' => 'Folj upp',
    'booking_id' => (int) $viaReminder['id'],
    'completed_at' => '2026-10-25 12:00:00',
    'due_at' => '2026-10-26 09:00:00',
    'created_at' => '2026-10-25 08:00:00',
    'updated_at' => '2026-10-25 08:00:00',
]);

$ins = $pdo->prepare('INSERT INTO purchases (reminder_id, payload, updated_at) VALUES (?, ?, ?)');
$ins->execute([
    910001,
    json_encode(['booking_id' => (int) $withOrder['id'], 'fortnox_order' => '', 'fortnox_invoice' => ''], JSON_UNESCAPED_UNICODE),
    '2026-10-22 08:00:00',
]);
$ins->execute([
    910002,
    json_encode(['booking_id' => (int) $withInvoice['id'], 'fortnox_invoice' => '1001'], JSON_UNESCAPED_UNICODE),
    '2026-10-23 08:00:00',
]);
$ins->execute([
    910003,
    json_encode([
        'booking_id' => (int) $withAll['id'],
        'fortnox_invoice' => '1001',
        'steps' => ['faktura_skapad' => '2026-10-24 12:00:00'],
    ], JSON_UNESCAPED_UNICODE),
    '2026-10-24 08:00:00',
]);
$ins->execute([
    (int) $viaRow['id'],
    json_encode(['fortnox_order' => '1'], JSON_UNESCAPED_UNICODE),
    '2026-10-25 08:00:00',
]);

$beforeClean = bdo_row($pdo, $cleanId);
bdo_assert(is_array($beforeClean), 'bokningen finns före körning');

$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = bdo_call($store, ['action' => 'bookings.delete_one', 'id' => $cleanId], $forbidden);
bdo_assert(($denied['ok'] ?? true) === false && ($denied['status'] ?? 0) === 401, 'fel token');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
bdo_assert(bdo_row($pdo, $cleanId) !== null, 'fel token tar inte bort');

$junk = bdo_call($store, ['action' => 'bookings.delete_one', 'id' => $email, 'dry' => 0, 'confirm' => 'delete'], $forbidden);
bdo_assert(($junk['ok'] ?? true) === false && ($junk['status'] ?? 0) === 400 && !isset($junk['id']), 'ogiltigt id skrivs inte ut');

$dry = bdo_call($store, ['action' => 'bookings.delete_one', 'id' => $cleanId], $forbidden);
bdo_assert(
    ($dry['ok'] ?? false) === true && ($dry['dry'] ?? false) === true && ($dry['deleted'] ?? true) === false && ($dry['written'] ?? true) === false && (int) ($dry['id'] ?? 0) === $cleanId && ($dry['status'] ?? 0) === 200,
    'dry är standard och skriver inte'
);
bdo_assert(bdo_row($pdo, $cleanId) !== null && bdo_undo_count($pdo) === 0, 'dry lämnar bokning och ångerrad');

$noConfirm = bdo_call($store, ['action' => 'bookings.delete_one', 'id' => $cleanId, 'dry' => 0], $forbidden);
bdo_assert(($noConfirm['ok'] ?? true) === false && ($noConfirm['status'] ?? 0) === 400 && ($noConfirm['error'] ?? '') === 'Kräver confirm:delete.', 'confirm krävs');
$badConfirm = bdo_call($store, ['action' => 'bookings.delete_one', 'id' => $cleanId, 'dry' => 0, 'confirm' => 'Delete'], $forbidden);
bdo_assert(($badConfirm['ok'] ?? true) === false && ($badConfirm['status'] ?? 0) === 400, 'confirm måste vara exakt delete');
bdo_assert(bdo_row($pdo, $cleanId) !== null && bdo_undo_count($pdo) === 0, 'utan confirm skrivs inget');

bdo_assert($store->findOpenReminderByBooking((int) $withReminder['id']) === null, 'avslutad påminnelse är inte öppen');
$cases = [
    [(int) $withReminder['id'], ['reminder']],
    [(int) $withOrder['id'], ['order']],
    [(int) $withInvoice['id'], ['order', 'invoice']],
    [(int) $withAll['id'], ['reminder', 'order', 'invoice']],
    [(int) $viaReminder['id'], ['reminder', 'order']],
];
foreach ($cases as [$linkedId, $expect]) {
    $linkedDry = bdo_call($store, ['action' => 'bookings.delete_one', 'id' => $linkedId], $forbidden);
    bdo_assert(($linkedDry['ok'] ?? true) === false && ($linkedDry['status'] ?? 0) === 409 && ($linkedDry['links'] ?? null) === $expect && ($linkedDry['written'] ?? true) === false, 'torr vägran ' . implode('+', $expect));
    $linkedWet = bdo_call($store, ['action' => 'bookings.delete_one', 'id' => $linkedId, 'dry' => 0, 'confirm' => 'delete'], $forbidden);
    bdo_assert(($linkedWet['ok'] ?? true) === false && ($linkedWet['status'] ?? 0) === 409 && ($linkedWet['links'] ?? null) === $expect, 'skarpt vägras ' . implode('+', $expect));
    bdo_assert(bdo_row($pdo, $linkedId) !== null, 'länkad bokning ligger kvar');
}
bdo_assert(bdo_undo_count($pdo) === 0, 'vägran skriver ingen ångerrad');
bdo_assert((int) $allReminder['id'] > 0, 'påminnelsen för alla tre länkar finns');

$gone = bdo_call($store, ['action' => 'bookings.delete_one', 'id' => (string) $cleanId, 'dry' => '0', 'confirm' => 'delete'], $forbidden);
bdo_assert(
    ($gone['ok'] ?? false) === true && ($gone['dry'] ?? true) === false && ($gone['deleted'] ?? false) === true && ($gone['written'] ?? false) === true && (int) ($gone['id'] ?? 0) === $cleanId,
    'dry=0 och confirm delete tar bort'
);
$undoId = (string) ($gone['undo_id'] ?? '');
bdo_assert(preg_match('/^[a-f0-9]{16}$/', $undoId) === 1, 'undo_id är 16 hex');
bdo_assert(bdo_row($pdo, $cleanId) === null, 'raden är borta');
$undoStmt = $pdo->prepare('SELECT booking_json, undone_at FROM booking_delete_undo WHERE undo_id = ?');
$undoStmt->execute([$undoId]);
$undoRow = $undoStmt->fetch(PDO::FETCH_ASSOC);
bdo_assert(is_array($undoRow) && ($undoRow['undone_at'] ?? null) === null, 'ångerraden är inte markerad');
bdo_assert(is_string($undoRow['booking_json'] ?? null) && str_contains($undoRow['booking_json'], $name) && str_contains($undoRow['booking_json'], '2026-10-20 09:00:00'), 'ångerraden har hela bokningen');
$siblingNow = bdo_row($pdo, $siblingId);
bdo_assert(is_array($siblingNow) && (string) ($siblingNow['starts_at'] ?? '') === '2026-11-02 07:00:00' && (string) ($siblingNow['customer_name'] ?? '') === $otherName, 'andra bokningen orörd');

$preview = bdo_call($store, ['action' => 'bookings.delete_one_undo', 'undo_id' => $undoId], $forbidden);
bdo_assert(
    ($preview['ok'] ?? false) === true && ($preview['dry'] ?? false) === true && ($preview['restored'] ?? true) === false && ($preview['written'] ?? true) === false && ($preview['starts_at'] ?? '') === '2026-10-20 09:00:00' && ($preview['ends_at'] ?? '') === '2026-10-20 10:00:00',
    'undo är torr som standard'
);
bdo_assert(bdo_row($pdo, $cleanId) === null, 'torr undo lägger inte tillbaka');
$undoStmt->execute([$undoId]);
$still = $undoStmt->fetch(PDO::FETCH_ASSOC);
bdo_assert(is_array($still) && ($still['undone_at'] ?? null) === null, 'torr undo sätter inte undone_at');

$undoConfirm = bdo_call($store, ['action' => 'bookings.delete_one_undo', 'undo_id' => $undoId, 'dry' => 0], $forbidden);
bdo_assert(($undoConfirm['ok'] ?? true) === false && ($undoConfirm['error'] ?? '') === 'Kräver confirm:restore.', 'undo kräver confirm:restore');
$undoBad = bdo_call($store, ['action' => 'bookings.delete_one_undo', 'undo_id' => $undoId, 'dry' => 0, 'confirm' => 'Restore'], $forbidden);
bdo_assert(($undoBad['ok'] ?? true) === false && ($undoBad['status'] ?? 0) === 400, 'confirm måste vara exakt restore');
bdo_assert(bdo_row($pdo, $cleanId) === null, 'utan restore ligger bokningen borta');

$back = bdo_call($store, ['action' => 'bookings.delete_one_undo', 'undo_id' => $undoId, 'dry' => 0, 'confirm' => 'restore'], $forbidden);
bdo_assert(
    ($back['ok'] ?? false) === true && ($back['restored'] ?? false) === true && ($back['written'] ?? false) === true && (int) ($back['booking_id'] ?? 0) === $cleanId && ($back['starts_at'] ?? '') === '2026-10-20 09:00:00' && ($back['ends_at'] ?? '') === '2026-10-20 10:00:00',
    'undo lägger tillbaka samma id och tider'
);
$after = bdo_row($pdo, $cleanId);
bdo_assert(is_array($after) && is_array($beforeClean), 'raden är tillbaka');
foreach ($beforeClean as $column => $value) {
    $left = $after[$column] ?? null;
    $same = ($value === null || $left === null) ? $value === $left : (string) $left === (string) $value;
    bdo_assert($same, 'kolumn ' . $column . ' återställd');
}
$hydrated = $store->getBooking($cleanId);
bdo_assert(
    is_array($hydrated) && (int) $hydrated['id'] === $cleanId && $hydrated['starts_at'] === '2026-10-20 09:00:00' && $hydrated['ends_at'] === '2026-10-20 10:00:00' && $hydrated['customer_name'] === $name,
    'lagret läser samma bokning'
);
$undoStmt->execute([$undoId]);
$marked = $undoStmt->fetch(PDO::FETCH_ASSOC);
bdo_assert(is_array($marked) && trim((string) ($marked['undone_at'] ?? '')) !== '', 'undone_at är satt');

$againDry = bdo_call($store, ['action' => 'bookings.delete_one_undo', 'undo_id' => $undoId], $forbidden);
bdo_assert(($againDry['ok'] ?? true) === false && ($againDry['status'] ?? 0) === 409 && ($againDry['error'] ?? '') === 'Redan återställd.', 'andra undo är 409 även torr');
$againWet = bdo_call($store, ['action' => 'bookings.delete_one_undo', 'undo_id' => $undoId, 'dry' => 0, 'confirm' => 'restore'], $forbidden);
bdo_assert(($againWet['status'] ?? 0) === 409, 'andra skarpa undo är 409');
bdo_assert((string) (bdo_row($pdo, $cleanId)['starts_at'] ?? '') === '2026-10-20 09:00:00', 'andra undo rör inte tiderna');

$missing = bdo_call($store, ['action' => 'bookings.delete_one_undo', 'undo_id' => '0123456789abcdef'], $forbidden);
bdo_assert(($missing['ok'] ?? true) === false && ($missing['status'] ?? 0) === 404 && ($missing['error'] ?? '') === 'Okänt undo_id.', 'okänt undo_id');
$badUndo = bdo_call($store, ['action' => 'bookings.delete_one_undo', 'undo_id' => $email, 'dry' => 0, 'confirm' => 'restore'], $forbidden);
bdo_assert(($badUndo['ok'] ?? true) === false && ($badUndo['status'] ?? 0) === 400 && !isset($badUndo['undo_id']), 'ogiltigt undo_id skrivs inte ut');
$absent = bdo_call($store, ['action' => 'bookings.delete_one', 'id' => 999999, 'dry' => 0, 'confirm' => 'delete'], $forbidden);
bdo_assert(($absent['status'] ?? 0) === 404 && ($absent['error'] ?? '') === 'Bokningen finns inte.', 'saknad bokning');

$contactName = 'Mira Kontaktson';
$contactEmail = 'mira.kontaktson@example.test';
$blankName = 'Noll Blanksson';
$blankEmail = 'noll.blanksson@example.test';
$contactForbidden = array_merge($forbidden, [$contactName, 'Mira', 'Kontaktson', $contactEmail, $blankName, 'Noll', 'Blanksson', $blankEmail, 'Miragatan', 'Nollgatan']);
$made = jeab_kontakt_find_or_create([
    'name' => $contactName,
    'email' => $contactEmail,
    'street' => 'Miragatan 4',
    'city' => 'Bollnas',
], false, false);
bdo_assert(is_array($made) && trim((string) ($made['id'] ?? '')) !== '', 'kontakt med namn');
$contactId = (string) $made['id'];
$read = jeab_kontakt_get($contactId);
bdo_assert(is_array($read) && jeab_kontakt_row_name($read) === $contactName, 'kontaktnamnet läses via jeab_kontakt_get');
$namedEmpty = $store->insertBooking([
    'customer_id' => (int) $customer['id'],
    'customer_name' => '',
    'email' => null,
    'phone' => null,
    'address' => null,
    'notes' => null,
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'contact_id' => $contactId,
    'starts_at' => '2027-01-11 09:00:00',
    'ends_at' => '2027-01-11 10:00:00',
    'created_at' => '2026-10-01 10:00:00',
    'updated_at' => '2026-10-01 10:00:00',
]);
$namedEmptyId = (int) $namedEmpty['id'];
$namedRow = bdo_row($pdo, $namedEmptyId);
bdo_assert(
    is_array($namedRow) && trim((string) ($namedRow['customer_name'] ?? '')) === '' && trim((string) ($namedRow['contact_id'] ?? '')) === $contactId,
    'bokningen har tomt namn och länkad kontakt'
);
$blank = jeab_kontakt_find_or_create([
    'name' => $blankName,
    'email' => $blankEmail,
    'street' => 'Nollgatan 2',
    'city' => 'Bollnas',
], false, false);
bdo_assert(is_array($blank) && trim((string) ($blank['id'] ?? '')) !== '', 'kontakt som ska sakna namn');
$blankId = (string) $blank['id'];
jeab_kontakter_mutate(static function (array &$contacts) use ($blankId): void {
    if (!isset($contacts[$blankId]) || !is_array($contacts[$blankId])) {
        return;
    }
    $owners = is_array($contacts[$blankId]['owners'] ?? null) ? $contacts[$blankId]['owners'] : [];
    foreach ($owners as $i => $owner) {
        if (is_array($owner)) {
            $owners[$i]['name'] = '';
        }
    }
    $contacts[$blankId]['owners'] = $owners;
});
$blankRead = jeab_kontakt_get($blankId);
bdo_assert(is_array($blankRead) && jeab_kontakt_row_name($blankRead) === '', 'länkad kontakt utan namn');
$blankBooking = $store->insertBooking([
    'customer_id' => (int) $customer['id'],
    'customer_name' => '',
    'email' => null,
    'phone' => null,
    'address' => null,
    'notes' => null,
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'contact_id' => $blankId,
    'starts_at' => '2027-01-12 09:00:00',
    'ends_at' => '2027-01-12 10:00:00',
    'created_at' => '2026-10-01 10:00:00',
    'updated_at' => '2026-10-01 10:00:00',
]);
$blankBookingId = (int) $blankBooking['id'];
$undoBefore = bdo_undo_count($pdo);
$refused = bdo_call($store, ['action' => 'bookings.delete_one', 'id' => $blankBookingId, 'dry' => 0, 'confirm' => 'delete'], $contactForbidden);
bdo_assert(
    ($refused['ok'] ?? true) === false && ($refused['status'] ?? 0) === 400 && ($refused['error'] ?? '') === 'Namnet stämmer inte. Inget ändrat.',
    'tomt namn på bokning och kontakt vägras'
);
bdo_assert(bdo_row($pdo, $blankBookingId) !== null && bdo_undo_count($pdo) === $undoBefore, 'vägran utan namn lämnar bokningen');
$byContact = bdo_call($store, ['action' => 'bookings.delete_one', 'id' => $namedEmptyId, 'dry' => 0, 'confirm' => 'delete'], $contactForbidden);
bdo_assert(
    ($byContact['ok'] ?? false) === true && ($byContact['deleted'] ?? false) === true && (int) ($byContact['id'] ?? 0) === $namedEmptyId && bdo_row($pdo, $namedEmptyId) === null,
    'tomt bokningsnamn tas bort med kontaktens namn'
);
bdo_assert(bdo_row($pdo, $blankBookingId) !== null, 'namnlös bokning ligger kvar');

bdo_more($store, $pdo, $base, $forbidden, $siblingId);
}

/**
 * @param array<string, mixed> $base
 * @param list<string> $forbidden
 */
function bdo_more(JeabStore $store, PDO $pdo, array $base, array $forbidden, int $siblingId): void
{
    $jsonBook = bdo_book($store, $base, '2026-12-11 09:00:00', '2026-12-11 10:00:00');
    $jsonId = (int) $jsonBook['id'];
    jeab_calendar_log_append('booked', $store->getBooking($jsonId), '2026-12-11 08:00:00', 'web');
    jeab_calendar_log_append('booked', $store->getBooking($siblingId), '2026-11-02 08:00:00', 'web');
    $jsonBefore = bdo_log_for($jsonId);
    $siblingBefore = bdo_log_for($siblingId);
    bdo_assert(count($jsonBefore) === 1 && count($siblingBefore) === 1, 'json-loggen har båda raderna');
    $jsonEntryId = (int) ($jsonBefore[0]['id'] ?? 0);
    bdo_assert($jsonEntryId > 0, 'json-raden har id');
    $jsonGone = bdo_call($store, ['action' => 'bookings.delete_one', 'id' => $jsonId, 'dry' => 0, 'confirm' => 'delete'], $forbidden);
    bdo_assert(($jsonGone['deleted'] ?? false) === true, 'json-bokningen tas bort');
    bdo_assert(bdo_log_for($jsonId) === [], 'json-loggen för bokningen är borta');
    bdo_assert(count(bdo_log_for($siblingId)) === 1, 'annan json-rad ligger kvar');
    $jsonUndo = (string) ($jsonGone['undo_id'] ?? '');
    $logCol = $pdo->prepare('SELECT calendar_log_json FROM booking_delete_undo WHERE undo_id = ?');
    $logCol->execute([$jsonUndo]);
    $savedLog = json_decode((string) $logCol->fetchColumn(), true);
    bdo_assert(is_array($savedLog) && count($savedLog) === 1 && (int) ($savedLog[0]['id'] ?? 0) === $jsonEntryId && (int) ($savedLog[0]['booking_id'] ?? 0) === $jsonId, 'ångerraden har kalenderloggen');
    $jsonBack = bdo_call($store, ['action' => 'bookings.delete_one_undo', 'undo_id' => $jsonUndo, 'dry' => 0, 'confirm' => 'restore'], $forbidden);
    bdo_assert(($jsonBack['restored'] ?? false) === true && (int) ($jsonBack['booking_id'] ?? 0) === $jsonId, 'json-bokningen är tillbaka');
    $jsonAfter = bdo_log_for($jsonId);
    bdo_assert(count($jsonAfter) === 1 && (int) ($jsonAfter[0]['id'] ?? 0) === $jsonEntryId && (string) ($jsonAfter[0]['type'] ?? '') === 'booked' && (string) ($jsonAfter[0]['starts_at'] ?? '') === '2026-12-11 09:00:00', 'json-loggen är återställd');
    $siblingAfter = bdo_log_for($siblingId);
    bdo_assert(count($siblingAfter) === 1 && (int) ($siblingAfter[0]['id'] ?? 0) === (int) ($siblingBefore[0]['id'] ?? 0), 'annan json-rad är orörd');
    $logPath = (string) ($GLOBALS['_JEAB_CALENDAR_LOG_PATH'] ?? '');
    $fileRaw = $logPath !== '' && is_file($logPath) ? (string) file_get_contents($logPath) : '';
    $file = json_decode($fileRaw, true);
    $fileHit = false;
    foreach (is_array($file['entries'] ?? null) ? $file['entries'] : [] as $entry) {
        if (is_array($entry) && (int) ($entry['id'] ?? 0) === $jsonEntryId && (int) ($entry['booking_id'] ?? 0) === $jsonId) {
            $fileHit = true;
        }
    }
    bdo_assert($fileHit, 'json-filen har raden igen');

    $outlookBook = bdo_book($store, $base, '2026-12-12 09:00:00', '2026-12-12 10:00:00');
    $outlookId = (int) $outlookBook['id'];
    $pdo->prepare('INSERT INTO booking_outlook_events (booking_id, resource, email, graph_event_id, show_as, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$outlookId, 'Elin', 'outlook.fake@example.test', 'evt-fake-bdo-1', 'busy', '2026-10-01 10:00:00']);
    $undoBefore = bdo_undo_count($pdo);
    $outlookDry = bdo_call($store, ['action' => 'bookings.delete_one', 'id' => $outlookId], $forbidden);
    bdo_assert(($outlookDry['status'] ?? 0) === 409 && ($outlookDry['links'] ?? null) === ['outlook'] && ($outlookDry['written'] ?? true) === false, 'torr outlook vägras');
    $outlookWet = bdo_call($store, ['action' => 'bookings.delete_one', 'id' => $outlookId, 'dry' => 0, 'confirm' => 'delete'], $forbidden);
    bdo_assert(($outlookWet['status'] ?? 0) === 409 && ($outlookWet['links'] ?? null) === ['outlook'] && ($outlookWet['deleted'] ?? true) === false, 'skarp outlook vägras');
    bdo_assert(bdo_row($pdo, $outlookId) !== null && bdo_undo_count($pdo) === $undoBefore, 'outlook-bokningen ligger kvar');

    $remindMiss = bdo_book($store, $base, '2026-12-13 09:00:00', '2026-12-13 10:00:00');
    $remindMissId = (int) $remindMiss['id'];
    $undoBefore = bdo_undo_count($pdo);
    bdo_hide_table($pdo, 'reminders');
    try {
        $remindDry = bdo_call($store, ['action' => 'bookings.delete_one', 'id' => $remindMissId], $forbidden);
        bdo_assert(($remindDry['status'] ?? 0) === 409 && ($remindDry['links'] ?? null) === ['unknown'] && ($remindDry['written'] ?? true) === false, 'torr påminnelsefråga som kastar');
        $remindWet = bdo_call($store, ['action' => 'bookings.delete_one', 'id' => $remindMissId, 'dry' => 0, 'confirm' => 'delete'], $forbidden);
        bdo_assert(($remindWet['status'] ?? 0) === 409 && ($remindWet['links'] ?? null) === ['unknown'], 'skarp påminnelsefråga som kastar');
    } finally {
        bdo_show_table($pdo, 'reminders');
    }
    bdo_assert(bdo_row($pdo, $remindMissId) !== null && bdo_undo_count($pdo) === $undoBefore, 'bokningen ligger kvar när påminnelser inte kan läsas');

    $orderMiss = bdo_book($store, $base, '2026-12-14 09:00:00', '2026-12-14 10:00:00');
    $orderMissId = (int) $orderMiss['id'];
    $undoBefore = bdo_undo_count($pdo);
    bdo_hide_table($pdo, 'purchases');
    try {
        $orderWet = bdo_call($store, ['action' => 'bookings.delete_one', 'id' => $orderMissId, 'dry' => 0, 'confirm' => 'delete'], $forbidden);
        bdo_assert(($orderWet['status'] ?? 0) === 409 && ($orderWet['links'] ?? null) === ['unknown'] && ($orderWet['deleted'] ?? true) === false, 'order- och fakturafrågan som kastar');
    } finally {
        bdo_show_table($pdo, 'purchases');
    }
    bdo_assert(bdo_row($pdo, $orderMissId) !== null && bdo_undo_count($pdo) === $undoBefore, 'bokningen ligger kvar när köp inte kan läsas');

    $invoiceBook = bdo_book($store, $base, '2026-12-15 09:00:00', '2026-12-15 10:00:00');
    $invoiceId = (int) $invoiceBook['id'];
    $pdo->prepare('INSERT INTO purchases (reminder_id, payload, updated_at) VALUES (?, ?, ?)')->execute([
        910110,
        json_encode(['booking_id' => $invoiceId, 'fortnox_order' => '', 'fortnox_invoice' => ''], JSON_UNESCAPED_UNICODE),
        '2026-12-15 08:00:00',
    ]);
    $GLOBALS['_JEAB_BDO_INVOICE_THROW'] = true;
    try {
        $invoiceWet = bdo_call($store, ['action' => 'bookings.delete_one', 'id' => $invoiceId, 'dry' => 0, 'confirm' => 'delete'], $forbidden);
        bdo_assert(($invoiceWet['status'] ?? 0) === 409 && ($invoiceWet['links'] ?? null) === ['unknown'], 'fakturafrågan som kastar');
    } finally {
        unset($GLOBALS['_JEAB_BDO_INVOICE_THROW']);
    }
    bdo_assert(bdo_row($pdo, $invoiceId) !== null, 'bokningen ligger kvar när fakturan inte kan läsas');

    $blocked = bdo_book($store, $base, '2026-12-16 09:00:00', '2026-12-16 10:00:00');
    $blockedId = (int) $blocked['id'];
    jeab_calendar_log_append('cancelled', $store->getBooking($blockedId), '2026-12-16 08:00:00', 'web');
    bdo_assert(count(bdo_log_for($blockedId)) === 1, 'spärrad bokning har en loggrad');
    $undoBefore = bdo_undo_count($pdo);
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $pdo->exec('DROP TRIGGER IF EXISTS bdo_block_delete');
        $pdo->exec('CREATE TRIGGER bdo_block_delete BEFORE DELETE ON bookings FOR EACH ROW BEGIN IF OLD.id = ' . $blockedId . ' THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'bdo-block\'; END IF; END');
    } else {
        $pdo->exec('DROP TRIGGER IF EXISTS bdo_block_delete');
        $pdo->exec('CREATE TRIGGER bdo_block_delete BEFORE DELETE ON bookings FOR EACH ROW WHEN OLD.id = ' . $blockedId . ' BEGIN SELECT RAISE(ABORT, \'bdo-block\'); END');
    }
    try {
        $blockedRes = bdo_call($store, ['action' => 'bookings.delete_one', 'id' => $blockedId, 'dry' => 0, 'confirm' => 'delete'], $forbidden);
        bdo_assert(($blockedRes['ok'] ?? true) === false && ($blockedRes['deleted'] ?? true) === false && ($blockedRes['written'] ?? true) === false, 'avbruten radering skriver inte');
    } finally {
        $pdo->exec('DROP TRIGGER IF EXISTS bdo_block_delete');
    }
    bdo_assert(bdo_row($pdo, $blockedId) !== null && bdo_undo_count($pdo) === $undoBefore, 'ångerraden rullas tillbaka med raderingen');
    bdo_assert(count(bdo_log_for($blockedId)) === 1, 'loggen ligger kvar när raderingen rullas tillbaka');

    bdo_overlap($store, $pdo, $base, $forbidden);
    bdo_sql_log($store, $pdo, $base, $forbidden);
}

/**
 * @param array<string, mixed> $base
 * @param list<string> $forbidden
 */
function bdo_overlap(JeabStore $store, PDO $pdo, array $base, array $forbidden): void
{
    $hit = bdo_book($store, $base, '2026-12-01 09:00:00', '2026-12-01 10:00:00');
    $hitId = (int) $hit['id'];
    $hitGone = bdo_call($store, ['action' => 'bookings.delete_one', 'id' => $hitId, 'dry' => 0, 'confirm' => 'delete'], $forbidden);
    $hitUndo = (string) ($hitGone['undo_id'] ?? '');
    bdo_assert(($hitGone['deleted'] ?? false) === true && $hitUndo !== '', 'överlappets bokning är borttagen');
    $blocker = bdo_book($store, $base, '2026-12-01 09:30:00', '2026-12-01 10:30:00');
    $blockerId = (int) $blocker['id'];
    $dryHit = bdo_call($store, ['action' => 'bookings.delete_one_undo', 'undo_id' => $hitUndo], $forbidden);
    bdo_assert(($dryHit['status'] ?? 0) === 409 && ($dryHit['error'] ?? '') === 'conflict' && ($dryHit['restored'] ?? true) === false && ($dryHit['written'] ?? true) === false, 'torr återställning ser överlappet');
    $wetHit = bdo_call($store, ['action' => 'bookings.delete_one_undo', 'undo_id' => $hitUndo, 'dry' => 0, 'confirm' => 'restore'], $forbidden);
    bdo_assert(($wetHit['status'] ?? 0) === 409 && ($wetHit['error'] ?? '') === 'conflict' && ($wetHit['restored'] ?? true) === false, 'återställning vägrar överlapp');
    bdo_assert(bdo_row($pdo, $hitId) === null, 'överlappet lägger inte tillbaka bokningen');
    $undoStmt = $pdo->prepare('SELECT undone_at FROM booking_delete_undo WHERE undo_id = ?');
    $undoStmt->execute([$hitUndo]);
    bdo_assert(($undoStmt->fetchColumn() ?: null) === null, 'överlappet sätter inte undone_at');
    bdo_assert($store->deleteBooking($blockerId) === true, 'blockeringen tas bort');
    $hitBack = bdo_call($store, ['action' => 'bookings.delete_one_undo', 'undo_id' => $hitUndo, 'dry' => 0, 'confirm' => 'restore'], $forbidden);
    bdo_assert(($hitBack['restored'] ?? false) === true && (string) (bdo_row($pdo, $hitId)['starts_at'] ?? '') === '2026-12-01 09:00:00', 'utan överlapp går återställningen');

    $cancelled = bdo_book($store, $base, '2026-12-02 09:00:00', '2026-12-02 10:00:00');
    $cancelledId = (int) $cancelled['id'];
    $cancelledGone = bdo_call($store, ['action' => 'bookings.delete_one', 'id' => $cancelledId, 'dry' => 0, 'confirm' => 'delete'], $forbidden);
    $cancelledUndo = (string) ($cancelledGone['undo_id'] ?? '');
    bdo_book($store, $base, '2026-12-02 09:30:00', '2026-12-02 10:30:00', ['status' => 'cancelled']);
    $cancelledBack = bdo_call($store, ['action' => 'bookings.delete_one_undo', 'undo_id' => $cancelledUndo, 'dry' => 0, 'confirm' => 'restore'], $forbidden);
    bdo_assert(($cancelledBack['restored'] ?? false) === true, 'avbokad tid spärrar inte');

    $other = bdo_book($store, $base, '2026-12-03 09:00:00', '2026-12-03 10:00:00');
    $otherId = (int) $other['id'];
    $otherGone = bdo_call($store, ['action' => 'bookings.delete_one', 'id' => $otherId, 'dry' => 0, 'confirm' => 'delete'], $forbidden);
    $otherUndo = (string) ($otherGone['undo_id'] ?? '');
    bdo_book($store, $base, '2026-12-03 09:30:00', '2026-12-03 10:30:00', ['assignees' => ['Roberto']]);
    $otherBack = bdo_call($store, ['action' => 'bookings.delete_one_undo', 'undo_id' => $otherUndo, 'dry' => 0, 'confirm' => 'restore'], $forbidden);
    bdo_assert(($otherBack['restored'] ?? false) === true, 'annan person spärrar inte');

    $edge = bdo_book($store, $base, '2026-12-04 09:00:00', '2026-12-04 10:00:00');
    $edgeId = (int) $edge['id'];
    $edgeGone = bdo_call($store, ['action' => 'bookings.delete_one', 'id' => $edgeId, 'dry' => 0, 'confirm' => 'delete'], $forbidden);
    $edgeUndo = (string) ($edgeGone['undo_id'] ?? '');
    bdo_book($store, $base, '2026-12-04 10:00:00', '2026-12-04 11:00:00');
    $edgeBack = bdo_call($store, ['action' => 'bookings.delete_one_undo', 'undo_id' => $edgeUndo, 'dry' => 0, 'confirm' => 'restore'], $forbidden);
    bdo_assert(($edgeBack['restored'] ?? false) === true && (string) (bdo_row($pdo, $edgeId)['starts_at'] ?? '') === '2026-12-04 09:00:00', 'tider som möts i en punkt överlappar inte');

}

/**
 * @param array<string, mixed> $base
 * @param list<string> $forbidden
 */
function bdo_sql_log(JeabStore $store, PDO $pdo, array $base, array $forbidden): void
{
    $savedPath = $GLOBALS['_JEAB_CALENDAR_LOG_PATH'] ?? null;
    unset($GLOBALS['_JEAB_CALENDAR_LOG_PATH'], $GLOBALS['_JEAB_SQL_DOC']);
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver !== 'mysql') {
        $GLOBALS['_JEAB_LOG_PDO'] = $pdo;
    }
    try {
        if ($driver === 'mysql' && function_exists('jeab_sql_doc_put')) {
            $docOnly = bdo_book($store, $base, '2026-12-20 09:00:00', '2026-12-20 10:00:00');
            $docOnlyId = (int) $docOnly['id'];
            if (function_exists('jeab_log_pdo')) {
                jeab_log_pdo();
            }
            $pdo->prepare('DELETE FROM log_docs WHERE doc_key = ?')->execute(['calendar_log']);
            unset($GLOBALS['_JEAB_SQL_DOC']);
            $docEntry = [
                'id' => 82001,
                'type' => 'mail',
                'at' => '2026-12-20 08:00:00',
                'booking_id' => $docOnlyId,
                'starts_at' => '2026-12-20 09:00:00',
                'ends_at' => '2026-12-20 10:00:00',
                'title' => 'Dok',
            ];
            jeab_sql_doc_put($pdo, 'calendar_log', ['entries' => [$docEntry], 'backfilled' => false]);
            bdo_assert(count(bdo_log_for($docOnlyId)) === 1, 'json_docs-loggen syns');
            $docGone = bdo_call($store, ['action' => 'bookings.delete_one', 'id' => $docOnlyId, 'dry' => 0, 'confirm' => 'delete'], $forbidden);
            bdo_assert(($docGone['deleted'] ?? false) === true && bdo_log_for($docOnlyId) === [], 'json_docs-raden tas bort');
            $docBack = bdo_call($store, ['action' => 'bookings.delete_one_undo', 'undo_id' => (string) ($docGone['undo_id'] ?? ''), 'dry' => 0, 'confirm' => 'restore'], $forbidden);
            $docAfter = bdo_log_for($docOnlyId);
            bdo_assert(($docBack['restored'] ?? false) === true && count($docAfter) === 1 && (int) ($docAfter[0]['id'] ?? 0) === 82001, 'json_docs-raden är tillbaka');
        }

        $sqlBook = bdo_book($store, $base, '2026-12-21 09:00:00', '2026-12-21 10:00:00');
        $sqlId = (int) $sqlBook['id'];
        $keep = bdo_book($store, $base, '2026-12-22 09:00:00', '2026-12-22 10:00:00');
        $keepId = (int) $keep['id'];
        $data = jeab_calendar_log_read();
        $data['entries'][] = [
            'id' => 81001,
            'type' => 'mail',
            'at' => '2026-12-21 08:05:00',
            'booking_id' => $sqlId,
            'starts_at' => '2026-12-21 09:00:00',
            'ends_at' => '2026-12-21 10:00:00',
            'title' => 'Dok',
        ];
        $data['entries'][] = [
            'id' => 81002,
            'type' => 'booked',
            'at' => '2026-12-22 08:05:00',
            'booking_id' => $keepId,
            'starts_at' => '2026-12-22 09:00:00',
            'ends_at' => '2026-12-22 10:00:00',
            'title' => 'Kvar',
        ];
        jeab_calendar_log_write($data);
        jeab_calendar_log_append('booked', $store->getBooking($sqlId), '2026-12-21 08:00:00', 'web');
        $lines = bdo_log_for($sqlId);
        bdo_assert(count($lines) === 2, 'sql-loggen har dokumentrad och händelserad');
        $lineIds = [];
        foreach ($lines as $entry) {
            $lineIds[] = (int) ($entry['id'] ?? 0);
        }
        sort($lineIds);
        $lineStmt = $pdo->prepare('SELECT payload FROM log_lines WHERE kind = ?');
        $lineStmt->execute(['calendar_event']);
        $lineHit = false;
        foreach ($lineStmt->fetchAll(PDO::FETCH_COLUMN) as $payload) {
            $decoded = json_decode((string) $payload, true);
            if (is_array($decoded) && (int) ($decoded['booking_id'] ?? 0) === $sqlId) {
                $lineHit = true;
            }
        }
        bdo_assert($lineHit, 'händelseraden ligger i log_lines');
        $sqlGone = bdo_call($store, ['action' => 'bookings.delete_one', 'id' => $sqlId, 'dry' => 0, 'confirm' => 'delete'], $forbidden);
        bdo_assert(($sqlGone['deleted'] ?? false) === true && bdo_log_for($sqlId) === [], 'sql-loggen för bokningen är borta');
        bdo_assert(count(bdo_log_for($keepId)) === 1 && (int) (bdo_log_for($keepId)[0]['id'] ?? 0) === 81002, 'annan sql-rad ligger kvar');
        $sqlBack = bdo_call($store, ['action' => 'bookings.delete_one_undo', 'undo_id' => (string) ($sqlGone['undo_id'] ?? ''), 'dry' => 0, 'confirm' => 'restore'], $forbidden);
        $sqlAfter = bdo_log_for($sqlId);
        $afterIds = [];
        foreach ($sqlAfter as $entry) {
            $afterIds[] = (int) ($entry['id'] ?? 0);
        }
        sort($afterIds);
        bdo_assert(($sqlBack['restored'] ?? false) === true && $afterIds === $lineIds, 'sql-loggen är återställd');
        bdo_assert(count(bdo_log_for($keepId)) === 1, 'annan sql-rad är orörd efter återställning');
        $lineStmt->execute(['calendar_event']);
        $lineBack = false;
        foreach ($lineStmt->fetchAll(PDO::FETCH_COLUMN) as $payload) {
            $decoded = json_decode((string) $payload, true);
            if (is_array($decoded) && (int) ($decoded['booking_id'] ?? 0) === $sqlId && (string) ($decoded['type'] ?? '') === 'booked') {
                $lineBack = true;
            }
        }
        bdo_assert($lineBack, 'händelseraden är tillbaka i log_lines');
    } finally {
        unset($GLOBALS['_JEAB_LOG_PDO'], $GLOBALS['_JEAB_SQL_DOC']);
        if (is_string($savedPath) && $savedPath !== '') {
            $GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $savedPath;
        }
    }
}

function bdo_mariadb(): ?array
{
    if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
        echo "SKIP MySQL: pdo_mysql saknas\n";

        return null;
    }
    $installBin = trim((string) shell_exec('command -v mariadb-install-db 2>/dev/null'));
    $serverBin = trim((string) shell_exec('command -v mariadbd 2>/dev/null'));
    if ($installBin === '' || $serverBin === '') {
        echo "SKIP MySQL: MariaDB saknas\n";

        return null;
    }
    $tmp = sys_get_temp_dir() . '/jeab-bdo-mysql-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0775, true);
    $port = 37300 + random_int(0, 1500);
    $data = $tmp . '/db';
    $sock = $tmp . '/mysql.sock';
    $pidFile = $tmp . '/mysql.pid';
    $init = $tmp . '/init.sql';
    $log = $tmp . '/mysql.log';
    file_put_contents($init, <<<SQL
CREATE DATABASE IF NOT EXISTS jeab CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'jeab'@'127.0.0.1' IDENTIFIED BY 'jeabpass';
GRANT ALL PRIVILEGES ON jeab.* TO 'jeab'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL);
    $holder = new stdClass();
    $holder->proc = null;
    $holder->tmp = $tmp;
    register_shutdown_function(static function () use ($holder): void {
        $proc = $holder->proc;
        if (is_resource($proc)) {
            proc_terminate($proc);
            for ($i = 0; $i < 20; $i++) {
                $status = proc_get_status($proc);
                if (empty($status['running'])) {
                    break;
                }
                usleep(100000);
            }
            $status = proc_get_status($proc);
            if (!empty($status['running'])) {
                proc_terminate($proc, 9);
            }
            proc_close($proc);
            $holder->proc = null;
        }
        for ($try = 0; $try < 5 && is_dir($holder->tmp); $try++) {
            bdo_rm_tree($holder->tmp);
            if (is_dir($holder->tmp)) {
                usleep(100000);
            }
        }
    });
    $install = sprintf(
        'mariadb-install-db --datadir=%s --auth-root-authentication-method=normal --skip-test-db >%s 2>&1',
        escapeshellarg($data),
        escapeshellarg($log)
    );
    exec($install, $unused, $installCode);
    if ($installCode !== 0) {
        fwrite(STDERR, "FAIL MariaDB kunde inte initieras\n");
        exit(1);
    }
    $cmd = sprintf(
        'mariadbd --datadir=%s --bind-address=127.0.0.1 --port=%d --socket=%s --pid-file=%s --init-file=%s',
        escapeshellarg($data),
        $port,
        escapeshellarg($sock),
        escapeshellarg($pidFile),
        escapeshellarg($init)
    );
    $proc = proc_open($cmd, [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
    if (!is_resource($proc)) {
        fwrite(STDERR, "FAIL MariaDB startade inte\n");
        exit(1);
    }
    $holder->proc = $proc;
    $up = false;
    for ($i = 0; $i < 50; $i++) {
        try {
            new PDO('mysql:host=127.0.0.1;port=' . $port . ';dbname=jeab;charset=utf8mb4', 'jeab', 'jeabpass', [PDO::ATTR_TIMEOUT => 1]);
            $up = true;
            break;
        } catch (Throwable) {
            usleep(200000);
        }
    }
    if (!$up) {
        fwrite(STDERR, "FAIL MariaDB svarade inte\n" . (string) file_get_contents($log) . "\n");
        exit(1);
    }

    return ['port' => $port];
}

function bdo_child(string $backend, int $port): void
{
    putenv('JEAB_BDO_CHILD=' . $backend);
    putenv('JEAB_BDO_PORT=' . (string) $port);
    $outFile = tempnam(sys_get_temp_dir(), 'bdo-out-');
    $errFile = tempnam(sys_get_temp_dir(), 'bdo-err-');
    if (!is_string($outFile) || !is_string($errFile)) {
        fwrite(STDERR, "FAIL kunde inte skapa logg\n");
        exit(1);
    }
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__);
    $proc = proc_open($cmd, [1 => ['file', $outFile, 'w'], 2 => ['file', $errFile, 'w']], $pipes);
    if (!is_resource($proc)) {
        @unlink($outFile);
        @unlink($errFile);
        fwrite(STDERR, "FAIL kunde inte starta {$backend}\n");
        exit(1);
    }
    $code = proc_close($proc);
    $out = (string) file_get_contents($outFile);
    $err = (string) file_get_contents($errFile);
    @unlink($outFile);
    @unlink($errFile);
    putenv('JEAB_BDO_CHILD');
    putenv('JEAB_BDO_PORT');
    echo $out;
    if ($err !== '') {
        fwrite(STDERR, $err);
    }
    if ($code !== 0) {
        exit($code > 0 ? $code : 1);
    }
}

$bdoChild = getenv('JEAB_BDO_CHILD');
$bdoChild = is_string($bdoChild) ? $bdoChild : '';
if ($bdoChild === 'sqlite' || $bdoChild === 'mysql') {
    bdo_scenarios($bdoChild, (int) getenv('JEAB_BDO_PORT'));
    exit(0);
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

bdo_child('sqlite', 0);
$bdoDb = bdo_mariadb();
if ($bdoDb !== null) {
    bdo_child('mysql', $bdoDb['port']);
}

echo "ALLA OK\n";
