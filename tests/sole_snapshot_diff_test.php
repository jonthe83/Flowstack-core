<?php

declare(strict_types=1);

/**
 * kontakter.sole_snapshot_diff och ordrar.delete.
 * Påhittade namn. Personnumret får inte synas i svaret.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-snap-diff-' . bin2hex(random_bytes(4));
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

function sd(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
sd(str_contains($hookSrc, "'kontakter.sole_snapshot_diff'"), 'hook tar kontakter.sole_snapshot_diff');

$store = jeab_store();
sd($store instanceof JeabStore, 'lagret är öppet');
$pnr = '19900101-1234';
$customer = $store->insertCustomer('Ada Borta', '2026-10-01 08:00:00');
$booking = $store->insertBooking([
    'customer_id' => $customer['id'],
    'customer_name' => 'Ada Borta',
    'email' => 'ada.borta@example.test',
    'phone' => '0700000000',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => '2026-10-01 08:00:00',
    'ends_at' => '2026-10-01 10:00:00',
    'status' => 'booked',
    'created_at' => '2026-10-01 08:00:00',
    'updated_at' => '2026-10-01 08:00:00',
]);
$bookingId = (int) ($booking['id'] ?? 0);
sd($bookingId > 0, 'bokningen finns');
$gone = jeab_create_reminder($store, 'Ada Borta', '2026-10-01 00:00:00', 'HS#424242 ' . $pnr, $bookingId, [
    'customer' => 'Ada Borta',
    'company' => 'jarfalla',
    'tags' => ['vunnen'],
    'address' => 'Testgatan 1',
]);
$keep = jeab_create_reminder($store, 'Bea Kvar', '2026-10-02 00:00:00', 'kvar', null, [
    'customer' => 'Bea Kvar',
    'company' => 'jarfalla',
    'tags' => ['vunnen'],
    'address' => 'Annan gata 2',
]);
$goneId = (int) ($gone['id'] ?? 0);
$keepId = (int) ($keep['id'] ?? 0);
sd($goneId > 0 && $keepId > 0 && $goneId !== $keepId, 'två påminnelser finns');
jeab_purchase_start($store, $goneId, 'batteri');
jeab_purchase_start($store, $keepId, 'batteri');

$pdo = jeab_contacts_sole_undo_pdo($store);
sd($pdo instanceof PDO, 'ångerloggen går att öppna');
if (!$pdo instanceof PDO) {
    exit(1);
}
$snap = [
    'counts' => ['orders' => 2, 'reminders' => 2, 'bookings' => 0],
    'ids' => ['orders' => [$goneId, $keepId], 'reminders' => [], 'bookings' => []],
    'links' => ['orders' => [(string) $goneId => 'kt_pnr', (string) $keepId => ''], 'reminders' => [], 'bookings' => []],
    'personnummer' => $pnr,
];
$pdo->prepare('INSERT INTO contacts_sole_undo (batch_id, seq, kind, entity, entity_id, before_json, after_json, created_at, undone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)')
    ->execute(['batchsnap', 1, 'snapshot', 'census', 'batchsnap', json_encode($snap, JSON_UNESCAPED_UNICODE), '{}', '2026-10-01 00:00:00']);

$empty = jeab_contacts_sole_snapshot_diff($store);
sd(!empty($empty['ok']) && !empty($empty['read_only']) && empty($empty['written']) && ($empty['count'] ?? -1) === 0, 'båda ordrarna finns kvar');

jeab_ordrar_delete(['id' => $goneId], $store);
$doc = jeab_ordrar_deleted_doc();
sd(($doc['ids'][(string) $goneId]['by'] ?? '') === 'demo@example.test', 'raderingen sparar vem som var inloggad');
sd((int) ($doc['ids'][(string) $goneId]['booking_id'] ?? 0) === $bookingId, 'raderingen sparar bokningen');
$audit = jeab_ordrar_delete_audit_list();
sd(count($audit) === 1 && (int) ($audit[0]['reminder_id'] ?? 0) === $goneId && (string) ($audit[0]['actor'] ?? '') === 'demo@example.test', 'ordrar.delete skriver en audit-rad');

$diff = jeab_contacts_sole_snapshot_diff($store);
sd(!empty($diff['ok']) && !empty($diff['dry']) && empty($diff['written']) && ($diff['count'] ?? 0) === 1, 'en order saknas mot ögonblicksbilden');
$row = $diff['orders'][0] ?? null;
sd(is_array($row) && (int) ($row['id'] ?? 0) === $goneId, 'det är den raderade ordern');
sd(($row['address'] ?? '') === 'Testgatan 1', 'adressen följer med');
$tomb = is_array($row) ? ($row['ordrar_deleted'] ?? null) : null;
sd(is_array($tomb) && ($tomb['at'] ?? '') !== '' && ($tomb['hs'] ?? []) === ['424242'] && (int) ($tomb['booking_id'] ?? 0) === $bookingId, 'ordrar_deleted har at, hs och booking_id');
sd(is_array($tomb) && !array_key_exists('by', $tomb), 'svaret tar inte med by');
$encoded = json_encode($diff, JSON_UNESCAPED_UNICODE);
sd(is_string($encoded) && !str_contains($encoded, '19900101') && !str_contains($encoded, 'personnummer') && !str_contains($encoded, 'pnr'), 'svaret har inget personnummer');

$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = jeab_contacts_sole_dispatch(['action' => 'kontakter.sole_snapshot_diff']);
sd(empty($denied['ok']) && (int) ($denied['status'] ?? 0) === 401, 'hook utan token skriver inte');

echo "sole_snapshot_diff_test ok\n";
