<?php

declare(strict_types=1);

/**
 * Ångra efter knuff ska kunna sätta tillbaka exakt tidigare due_at och
 * completed_at, även när datumet ligger före idag. Ett gammalt datum
 * kläms inte till dagens datum.
 */

$tmp = sys_get_temp_dir() . '/jeab-due-undo-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';

function du_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$tz = new DateTimeZone(date_default_timezone_get());
$today = new DateTimeImmutable('today', $tz);
$pastDay = $today->modify('-40 days')->format('Y-m-d');
$past = $pastDay . ' 14:30:00';
$doneAt = $today->modify('-12 days')->setTime(9, 15, 0)->format('Y-m-d H:i:s');
$todayStart = jeab_today_start();

du_assert(jeab_reminder_due_posted($pastDay) === $pastDay . ' 00:00:00', 'datum utan klockslag behåller dagen');
du_assert(jeab_reminder_due_posted($past) === $past, 'klockslag i det förflutna behålls');
du_assert(jeab_reminder_due_posted($past) !== $todayStart, 'gammalt datum blir inte idag');
du_assert(jeab_reminder_due_posted('') === null, 'tom tid rensas och blir inte idag');
du_assert(jeab_reminder_completed_posted($doneAt) === $doneAt, 'completed_at behålls exakt');
du_assert(jeab_reminder_completed_posted('') === null, 'tom status betyder öppen');

$store = jeab_store();
$waitingBefore = 0;
for ($i = 1; $i <= 5; $i++) {
    $when = $today->modify('+' . $i . ' days')->setTime(8, 0, 0)->format('Y-m-d H:i:s');
    $row = jeab_create_reminder($store, 'Väntar ' . $i . ' ' . bin2hex(random_bytes(3)), $when, null, null, []);
    if (jeab_reminder_is_waiting($row)) {
        $waitingBefore++;
    }
}
du_assert($waitingBefore === 5, 'fem kommande räknas som väntar');

$open = jeab_create_reminder($store, 'Försenad ' . bin2hex(random_bytes(3)), $past, null, null, []);
$id = (int) $open['id'];
du_assert((string) $open['due_at'] === $past, 'skapad med det gamla klockslaget');
du_assert(!jeab_reminder_is_waiting($open), 'passerat datum ligger inte i Väntar');
du_assert(empty($open['completed_at']), 'raden är öppen');

$snoozed = jeab_snooze_reminder($store, $id, '2h');
du_assert((string) $snoozed['due_at'] !== $past, 'knuff flyttar tiden');
du_assert((string) $snoozed['due_at'] > jeab_now(), 'knuff från passerat datum hamnar i framtiden');
du_assert(jeab_reminder_is_waiting($snoozed), 'knuffad rad hamnar i Väntar');

$restored = jeab_update_reminder($store, $id, [
    'due_at' => jeab_reminder_due_posted($past),
    'completed_at' => jeab_reminder_completed_posted(''),
]);
du_assert((string) $restored['due_at'] === $past, 'Ångra sätter tillbaka exakt tidigare tid');
du_assert($restored['completed_at'] === null || $restored['completed_at'] === '', 'Ångra lämnar raden öppen');
du_assert(!jeab_reminder_is_waiting($restored), 'Väntar tappar den återställda raden');

$waiting = 0;
foreach ($store->listReminders() as $row) {
    if (jeab_reminder_is_waiting($row)) {
        $waiting++;
    }
}
du_assert($waiting === 5, 'Väntar är tillbaka på 5');

$closed = jeab_create_reminder($store, 'Klar ' . bin2hex(random_bytes(3)), $past, null, null, []);
$closedId = (int) $closed['id'];
$closed = jeab_update_reminder($store, $closedId, [
    'due_at' => $past,
    'completed_at' => $doneAt,
]);
du_assert((string) $closed['completed_at'] === $doneAt, 'klar-status sparas med sitt klockslag');
$closedSnooze = jeab_snooze_reminder($store, $closedId, '2h');
du_assert((string) $closedSnooze['completed_at'] === $doneAt, 'knuff rör inte completed_at');
$closedBack = jeab_update_reminder($store, $closedId, [
    'due_at' => jeab_reminder_due_posted($past),
    'completed_at' => jeab_reminder_completed_posted($doneAt),
]);
du_assert((string) $closedBack['due_at'] === $past, 'klar rad får tillbaka samma datum');
du_assert((string) $closedBack['completed_at'] === $doneAt, 'klar rad får tillbaka samma status');

$index = (string) file_get_contents(dirname(__DIR__) . '/index.php');
$remindersSrc = (string) file_get_contents(dirname(__DIR__) . '/src/reminders.php');
du_assert(str_contains($index, 'jeab_reminder_due_fields($_POST'), 'due-formuläret går via fälten som inte klämmer till idag');
du_assert(str_contains($remindersSrc, "jeab_reminder_due_posted((string) (\$post['due_at'] ?? ''))"), 'due-tolken behåller det skickade datumet');

date_default_timezone_set('Europe/Stockholm');
$original = '2026-10-23 12:54:00';
$shifted = '2026-10-23 18:54:00';
du_assert(jeab_reminder_due_posted('2026-10-23T12:54:00.000Z') === $original, 'ISO-Z behåller 12:54 och blir inte 18:54');
du_assert(jeab_reminder_due_posted('2026-10-23T12:54:00+00:00') === $original, 'offset behåller väggklockan 12:54');
du_assert(jeab_reminder_due_posted($original) === $original, 'serverformatet 12:54 går igenom oförändrat');
$keptClock = jeab_reminder_due_keep_clock(jeab_reminder_due_posted($original), $shifted);
du_assert($keptClock === $original, 'ett helt klockslag skriver över 18:54');
$dateOnly = jeab_reminder_due_keep_clock(jeab_reminder_due_posted('2026-10-23'), $shifted);
du_assert($dateOnly === $shifted, 'bara datum behåller det knuffade klockslaget');
$wall = jeab_create_reminder($store, 'Ångra tz ' . bin2hex(random_bytes(3)), $original, null, null, []);
$wallId = (int) $wall['id'];
du_assert((string) $wall['due_at'] === $original, 'raden skapas med 12:54');
$moved = jeab_update_reminder($store, $wallId, ['due_at' => $shifted]);
du_assert((string) $moved['due_at'] === $shifted, 'knuffad tid är 18:54');
$restoredDue = jeab_reminder_due_keep_clock(
    jeab_reminder_due_posted($original),
    (string) $moved['due_at']
);
$wallBack = jeab_update_reminder($store, $wallId, [
    'due_at' => $restoredDue,
    'completed_at' => jeab_reminder_completed_posted(''),
]);
du_assert((string) $wallBack['due_at'] === $original, 'Ångra-POST återställer exakt 2026-10-23 12:54:00, fick ' . (string) $wallBack['due_at']);
$wallAgain = jeab_get_reminder($store, $wallId);
du_assert(is_array($wallAgain) && (string) ($wallAgain['due_at'] ?? '') === $original, 'lagrad tid efter Ångra är 12:54');

echo "ALLA OK\n";
