<?php

declare(strict_types=1);

/** maildiag: läser Malmö contact@-bekräftelser och deras läge. Skickar inget. */

function md_assert(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }
    echo "OK  {$message}\n";
}

$tmp = sys_get_temp_dir() . '/jeab-mail-diag-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['JEAB_NO_SESSION'] = true;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
file_put_contents($tmp . '/helpscout.json', json_encode(['mailbox_id' => 372708, 'mailbox_id_malmo' => 372709]));
require dirname(__DIR__) . '/src/bootstrap.php';

$store = jeab_store(true);
$customer = jeab_find_or_create_customer($store, 'Anna Berg');
$mk = static function (array $d) use ($store, $customer): int {
    $company = (string) ($d['company'] ?? 'malmo');
    $row = $store->insertBooking($d + [
        'title' => 'Installation',
        'status' => 'booked',
        'company' => 'malmo',
        'job_type' => 'laddbox',
        'customer_name' => 'Anna Berg',
        'email' => 'anna.berg@example.com',
        'customer_id' => $customer['id'],
        'assignees' => [$company === 'jarfalla' ? 'Elin' : 'Kim'],
        'created_at' => '2026-10-03 10:00:00',
        'updated_at' => '2026-10-03 10:00:00',
    ]);
    return (int) $row['id'];
};
$queued = $mk(['address' => 'Kögatan 1', 'starts_at' => '2026-10-14 08:00:00', 'ends_at' => '2026-10-14 12:00:00']);
$dropped = $mk(['address' => 'Tappgatan 2', 'starts_at' => '2026-10-05 08:00:00', 'ends_at' => '2026-10-05 12:00:00']);
$jeab = $mk(['address' => 'Järfällavägen 3', 'company' => 'jarfalla', 'starts_at' => '2026-10-14 08:00:00', 'ends_at' => '2026-10-14 12:00:00']);
$all = $store->listAllBookings();
md_assert(count($all) === 3, 'tre bokningar');
// Ändra stämplar så bokningarna är äldre än två timmar.
if ($store instanceof JeabPdoStore) {
    $store->pdo()->exec("UPDATE bookings SET created_at = '2026-10-03 10:00:00', updated_at = '2026-10-03 10:00:00'");
}
unset($GLOBALS['_JEAB_BOOKING_CACHE']);
$store = jeab_store(true);

$rows = jeab_system_mail_diag_rows('2026-10-10 00:10:00');
$byId = [];
foreach ($rows as $r) {
    $byId[(int) $r['id']] = $r;
}
md_assert(!isset($byId[$jeab]), 'Järfälla-bokning listas inte');
md_assert(str_starts_with($byId[$queued]['state'] ?? '', 'I KÖ'), 'framtida osänd = i kö');
md_assert(str_starts_with($byId[$dropped]['state'] ?? '', 'TAPPAD'), 'passerad osänd = tappad');
md_assert(($byId[$queued]['to'] ?? '') === 'an***@example.com', 'mottagaren maskeras');
md_assert(($byId[$queued]['route'] ?? '') === 'helpscout', 'contact@ går via Help Scout');

jeab_sms_save_sent($queued, ['email' => true, 'email_at' => '2026-10-10 02:00:01', 'starts_at' => '2026-10-14 08:00:00']);
$rows = jeab_system_mail_diag_rows('2026-10-10 02:10:00');
foreach ($rows as $r) {
    if ((int) $r['id'] === $queued) {
        md_assert(str_starts_with($r['state'], 'skickat'), 'markerad som skickad efteråt');
    }
}
md_assert(jeab_sms_email_already_sent($queued), 'tickern hoppar över den skickade');
echo "ALLA OK\n";
