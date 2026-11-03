<?php

declare(strict_types=1);

/**
 * H-CAL-3: saveBooking skriver bara fälten anropet skickade, i en transaktion,
 * och jämför updated_at. En krock läser om raden och försöker samma fält igen.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-booking-save-' . bin2hex(random_bytes(4));
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
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
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
date_default_timezone_set('Europe/Stockholm');

function sbs_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store(true);
sbs_ok($store instanceof JeabPdoStore, 'sqlite-butik');
$customer = $store->insertCustomer('Kalender Lås', '2026-11-01 08:00:00');
$row = $store->insertBooking([
    'customer_id' => $customer['id'],
    'customer_name' => 'Kalender Lås',
    'email' => 'las@example.test',
    'phone' => '0701000001',
    'address' => 'Låsgatan 1',
    'notes' => 'behåll mig',
    'assignees' => ['Sam L'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => '2026-11-02 09:00:00',
    'ends_at' => '2026-11-02 10:00:00',
    'status' => 'booked',
    'created_at' => '2026-11-01 08:00:00',
    'updated_at' => '2026-11-01 08:00:00',
]);
$id = (int) $row['id'];
sbs_ok($id > 0, 'bokning skapad');

$saved = $store->saveBooking($id, [
    'starts_at' => '2026-11-02 13:00:00',
    'ends_at' => '2026-11-02 14:00:00',
    'updated_at' => '2026-11-01 08:00:00',
]);
sbs_ok((string) ($saved['starts_at'] ?? '') === '2026-11-02 13:00:00', 'tiden sparas');
sbs_ok((string) ($saved['ends_at'] ?? '') === '2026-11-02 14:00:00', 'sluttiden sparas');
sbs_ok((string) ($saved['notes'] ?? '') === 'behåll mig', 'anteckningen rörs inte av en tidsskrivning');
sbs_ok((string) ($saved['phone'] ?? '') === '0701000001', 'telefonen rörs inte av en tidsskrivning');
sbs_ok((string) ($saved['address'] ?? '') === 'Låsgatan 1', 'adressen rörs inte av en tidsskrivning');
sbs_ok((string) ($saved['email'] ?? '') === 'las@example.test', 'mejlet rörs inte av en tidsskrivning');
sbs_ok((string) ($saved['customer_name'] ?? '') === 'Kalender Lås', 'namnet rörs inte av en tidsskrivning');
sbs_ok((string) ($saved['job_type'] ?? '') === 'laddbox', 'jobbet rörs inte av en tidsskrivning');
sbs_ok((string) ($saved['status'] ?? '') === 'booked', 'status rörs inte av en tidsskrivning');
sbs_ok(($saved['assignees'] ?? null) === ['Sam L'], 'montörerna rörs inte av en tidsskrivning');
sbs_ok((string) ($saved['updated_at'] ?? '') === '2026-11-01 08:00:00', 'updated_at blir det anropet skickade');

$pdo = $store->pdo();
$endsSort = $pdo->query('SELECT ends_sort FROM bookings WHERE id = ' . $id)->fetchColumn();
sbs_ok((string) $endsSort === '2026-11-02 14:00:00', 'ends_sort följer den nya sluttiden');

$phoned = $store->saveBooking($id, ['phone' => '0701999888']);
sbs_ok((string) ($phoned['phone'] ?? '') === '0701999888', 'telefonen sparas ensam');
sbs_ok((string) ($phoned['notes'] ?? '') === 'behåll mig', 'anteckningen består när telefonen sparas');
sbs_ok((string) ($phoned['starts_at'] ?? '') === '2026-11-02 13:00:00', 'tiden består när telefonen sparas');
sbs_ok((string) ($phoned['updated_at'] ?? '') === '2026-11-01 08:00:00', 'utelämnad updated_at lämnas kvar');

$races = 0;
$GLOBALS['_JEAB_BOOKING_SAVE_RACE'] = static function (PDO $pdo, int $bookingId) use (&$races): void {
    $races++;
    $pdo->prepare('UPDATE bookings SET notes = ?, updated_at = ? WHERE id = ?')
        ->execute(['från andra', '2026-11-01 09:30:00', $bookingId]);
};
try {
    $raced = $store->saveBooking($id, [
        'starts_at' => '2026-11-03 09:00:00',
        'ends_at' => '2026-11-03 10:00:00',
        'updated_at' => '2026-11-01 08:00:00',
    ]);
} finally {
    unset($GLOBALS['_JEAB_BOOKING_SAVE_RACE']);
}
sbs_ok($races === 1, 'krocken körs en gång');
sbs_ok((string) ($raced['notes'] ?? '') === 'från andra', 'den andra skrivningens anteckning består');
sbs_ok((string) ($raced['phone'] ?? '') === '0701999888', 'telefonen består efter omläsning');
sbs_ok((string) ($raced['starts_at'] ?? '') === '2026-11-03 09:00:00', 'tiden skrivs om efter omläsning');
sbs_ok((string) ($raced['ends_at'] ?? '') === '2026-11-03 10:00:00', 'sluttiden skrivs om efter omläsning');
sbs_ok((string) ($raced['updated_at'] ?? '') === '2026-11-01 08:00:00', 'samma updated_at skrivs efter omläsning');
sbs_ok(($raced['assignees'] ?? null) === ['Sam L'], 'montörerna består efter omläsning');

$missing = false;
try {
    $store->saveBooking($id + 99, ['notes' => 'saknas']);
} catch (InvalidArgumentException $e) {
    $missing = str_contains($e->getMessage(), 'Bokningen finns inte');
}
sbs_ok($missing, 'saknad bokning ger samma fel som förut');

$src = (string) file_get_contents(dirname(__DIR__) . '/src/store.php');
sbs_ok(str_contains($src, 'WHERE id = ? AND updated_at = ?'), 'skrivningen jämför updated_at');
sbs_ok(str_contains($src, 'FOR UPDATE'), 'mysql låser raden');
sbs_ok(!str_contains($src, 'UPDATE bookings SET customer_id = ?, customer_name = ?, email = ?, phone = ?, address = ?, notes = ?'), 'hela raden skrivs inte längre');

echo "ALL OK\n";
