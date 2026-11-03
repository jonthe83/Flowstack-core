<?php

declare(strict_types=1);

/**
 * Försenad påminnelse med udda klockslag. 2h är nu+2h i Stockholm, 2d är
 * idag+2 vid midnatt. keep_clock=0 får inte lägga tillbaka 02:00.
 * Hookarna läser bara och skickar inget.
 */

function ov(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$root = dirname(__DIR__);
$acc = sys_get_temp_dir() . '/jeab-snooze-overdue-' . bin2hex(random_bytes(3));
if (!is_dir($acc) && !mkdir($acc, 0775, true) && !is_dir($acc)) {
    fwrite(STDERR, "FAIL tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $acc;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('JEAB_WEBHOOK_CAPTURE=1');
$GLOBALS['_JEAB_WEBHOOKS'] = [];
file_put_contents($acc . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $acc . '/app.sqlite', 'json_path' => $acc . '/store.json'],
], true) . ";\n");
require_once $root . '/src/bootstrap.php';

$tz = new DateTimeZone('Europe/Stockholm');
$utc = new DateTimeZone('UTC');
$now = new DateTimeImmutable('now', $tz);
$past = $now->modify('-2 days')->setTime(2, 0, 0);
$store = jeab_store();
$secretName = 'Snooze Hemlig ' . bin2hex(random_bytes(3));

$make = static function (string $title) use ($store, $past, $secretName): int {
    $created = $store->insertReminder([
        'title' => $title,
        'customer_name' => $secretName,
        'email' => 'snooze.hemlig@example.test',
        'company' => 'jarfalla',
        'due_at' => $past->format('Y-m-d H:i:s'),
        'created_at' => jeab_now(),
        'updated_at' => jeab_now(),
    ]);

    return (int) ($created['id'] ?? 0);
};

$hourId = $make('Knuff 2h');
$dayId = $make('Knuff 2d');
ov($hourId > 0 && $dayId > 0, 'två försenade påminnelser');
$open = jeab_get_reminder($store, $hourId);
ov(is_array($open) && (string) $open['due_at'] === $past->format('Y-m-d H:i:s'), 'förfaller för två dagar sedan 02:00');
ov(!jeab_reminder_is_waiting($open), 'den försenade ligger inte i Väntar');

date_default_timezone_set('UTC');
$utcBefore = new DateTimeImmutable('now', $utc);
$stoBefore = new DateTimeImmutable('now', $tz);
$hour = jeab_snooze_reminder($store, $hourId, '2h');
$stoAfter = new DateTimeImmutable('now', $tz);
$utcAfter = new DateTimeImmutable('now', $utc);
date_default_timezone_set('Europe/Stockholm');
$hourDue = (string) ($hour['due_at'] ?? '');
$hourMinute = substr($hourDue, 0, 16);
$stoMinutes = [
    $stoBefore->modify('+2 hours')->format('Y-m-d H:i'),
    $stoAfter->modify('+2 hours')->format('Y-m-d H:i'),
];
$utcMinutes = [
    $utcBefore->modify('+2 hours')->format('Y-m-d H:i'),
    $utcAfter->modify('+2 hours')->format('Y-m-d H:i'),
];
ov(in_array($hourMinute, $stoMinutes, true), '2h sparar Stockholm nu+2h, fick ' . $hourDue);
ov($stoMinutes !== $utcMinutes && !in_array($hourMinute, $utcMinutes, true), '2h följer inte UTC, fick ' . $hourDue);
ov($hourDue !== $past->modify('+2 hours')->format('Y-m-d H:i:s'), '2h utgår inte från 02:00');
ov($hourDue > (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s') && jeab_reminder_is_waiting($hour), '2h hamnar i Väntar');

$dayBefore = new DateTimeImmutable('now', $tz);
$day = jeab_snooze_reminder($store, $dayId, '2d');
$dayAfter = new DateTimeImmutable('now', $tz);
$dayDue = (string) ($day['due_at'] ?? '');
$dayOk = [
    $dayBefore->modify('+2 days')->setTime(0, 0, 0)->format('Y-m-d H:i:s'),
    $dayAfter->modify('+2 days')->setTime(0, 0, 0)->format('Y-m-d H:i:s'),
];
$dayBug = $past->setTime(0, 0, 0)->modify('+2 days')->format('Y-m-d H:i:s');
ov(in_array($dayDue, $dayOk, true), '2d sparar idag+2 vid 00:00, fick ' . $dayDue);
ov($dayDue !== $dayBug && !str_ends_with($dayDue, '02:00:00'), '2d behåller inte 02:00 och inte det gamla dygnet, fick ' . $dayDue);
ov(jeab_reminder_is_waiting($day), '2d hamnar i Väntar');

$postedMidnight = $dayOk[0];
$kept = jeab_reminder_due_from_post($postedMidnight, $past->format('Y-m-d H:i:s'), []);
ov($kept === substr($postedMidnight, 0, 10) . ' 02:00:00', 'utan keep_clock=0 följer 02:00 med, fick ' . (string) $kept);
$plain = jeab_reminder_due_from_post($postedMidnight, $past->format('Y-m-d H:i:s'), ['keep_clock' => '0']);
ov($plain === $postedMidnight, 'keep_clock=0 sparar midnatt, fick ' . (string) $plain);

$clockId = $make('Knuff klocka');
$skipped = jeab_update_reminder($store, $clockId, ['due_at' => $plain]);
ov((string) ($skipped['due_at'] ?? '') === $postedMidnight && jeab_reminder_is_waiting($skipped), 'sparad midnatt ligger i Väntar');

$index = (string) file_get_contents($root . '/index.php');
$reminders = (string) file_get_contents($root . '/src/reminders.php');
ov(str_contains($index, 'jeab_reminder_due_from_post') && str_contains($reminders, 'jeab_reminder_keep_clock_skipped'), 'due-vägen hoppar över klockan när keep_clock=0');
$app = (string) file_get_contents($root . '/assets/js/app.js');
$postAt = strpos($app, 'function postSnoozeTarget(');
$postEnd = strpos($app, "\n  function ", $postAt === false ? 0 : $postAt + 10);
$post = ($postAt === false || $postEnd === false) ? '' : substr($app, $postAt, $postEnd - $postAt);
ov(str_contains($post, "body.set('keep_clock', '0')") && !str_contains($post, 'savedStamp !== stamped'), 'knuffen skickar keep_clock=0 och tar serverns tid');
ov(str_contains($app, 'jeabSnoozeStack(baseDue, steps, new Date())') && str_contains($app, 'jeabSnoozeStack(baseDue, row._jeabSnoozeSteps, new Date())'), 'båda knuffvägarna räknar från nu');
$hook = (string) file_get_contents($root . '/hook.php');
ov(str_contains($hook, "'reminder.get_due'") && str_contains($hook, "'reminders.snooze_audit'"), 'hook.php tar emot de två läsningarna');

$beforeGet = (string) (jeab_get_reminder($store, $dayId)['updated_at'] ?? '');
$dueHook = jeab_reminder_get_due($store, ['id' => $dayId]);
$afterGet = (string) (jeab_get_reminder($store, $dayId)['updated_at'] ?? '');
ov(($dueHook['ok'] ?? false) === true && ($dueHook['read_only'] ?? false) === true && (int) ($dueHook['sent'] ?? 1) === 0, 'get_due är bara läsning');
ov((string) ($dueHook['due_at'] ?? '') === $dayDue && $afterGet === $beforeGet, 'get_due lämnar due_at och skriver inget');
ov(!isset($dueHook['customer_name']) && !isset($dueHook['email']) && !isset($dueHook['title']), 'get_due har ingen kundtext');

$hooksBefore = count($GLOBALS['_JEAB_WEBHOOKS'] ?? []);
$seen = jeab_reminders_snooze_audit($store, ['dry' => 0]);
ov(($seen['ok'] ?? false) === true && ($seen['read_only'] ?? false) === true && (int) ($seen['dry'] ?? 0) === 1 && (int) ($seen['sent'] ?? 1) === 0, 'auditen skriver och skickar inget');
ov(count($GLOBALS['_JEAB_WEBHOOKS'] ?? []) === $hooksBefore, 'auditen tänder ingen webhook');
$seenIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $seen['reminders'] ?? []);
ov(!in_array($hourId, $seenIds, true) && !in_array($dayId, $seenIds, true), 'en knuff som landar i Väntar listas inte');

$broken = jeab_update_reminder($store, $hourId, ['due_at' => $past->format('Y-m-d H:i:s')]);
ov((string) ($broken['due_at'] ?? '') === $past->format('Y-m-d H:i:s') && !jeab_reminder_is_waiting($broken), 'tillbakaskriven 02:00 är försenad igen');
$flagged = jeab_reminders_snooze_audit($store, []);
$flaggedIds = [];
$flaggedRow = null;
foreach ($flagged['reminders'] ?? [] as $row) {
    $flaggedIds[] = (int) ($row['id'] ?? 0);
    if ((int) ($row['id'] ?? 0) === $hourId) {
        $flaggedRow = $row;
    }
}
ov(in_array($hourId, $flaggedIds, true) && is_array($flaggedRow), 'försenad efter knuff listas');
ov(in_array('due_before_snooze', $flaggedRow['reasons'] ?? [], true) && in_array('snoozed_today_still_overdue', $flaggedRow['reasons'] ?? [], true), 'båda orsakerna följer med');
ov((string) ($flaggedRow['due_at'] ?? '') === $past->format('Y-m-d H:i:s'), 'auditen visar due_at');
$encoded = json_encode($flagged, JSON_UNESCAPED_UNICODE);
ov(is_string($encoded) && !str_contains($encoded, $secretName) && !str_contains($encoded, 'snooze.hemlig@example.test'), 'auditen har inget namn eller någon e-post');

$done = jeab_toggle_reminder($store, $hourId, true);
ov(!empty($done['completed_at']), 'raden stängs');
$afterClose = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), jeab_reminders_snooze_audit($store)['reminders'] ?? []);
ov(!in_array($hourId, $afterClose, true), 'stängd rad listas inte');

$denied = jeab_reminders_snooze_audit_dispatch($store, []);
ov(($denied['ok'] ?? true) === false && (int) ($denied['status'] ?? 0) === 401, 'audit utan token nekas');
$deniedDue = jeab_reminder_get_due_dispatch($store, ['id' => $dayId]);
ov(($deniedDue['ok'] ?? true) === false && (int) ($deniedDue['status'] ?? 0) === 401 && !isset($deniedDue['due_at']), 'get_due utan token nekas');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = 'tok-snooze-audit';
$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-snooze-audit']));
$allowed = jeab_reminder_get_due_dispatch($store, ['action' => 'reminder.get_due', 'id' => $dayId]);
ov(($allowed['ok'] ?? false) === true && (string) ($allowed['due_at'] ?? '') === $dayDue && (int) ($allowed['sent'] ?? 1) === 0, 'get_due med token läser due_at');

echo "ALLA OK\n";
