<?php

declare(strict_types=1);

/**
 * Långsamma påminnelseanrop: tröskel 2000 ms, och samma rutt plus sökning
 * höjer räknaren i 30 minuter i stället för att skapa nya larm.
 * Påhittad data. Inget kundutskick.
 */

$tmp = sys_get_temp_dir() . '/jeab-slow-alarm-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_WEBHOOK_SYNC'] = true;
$GLOBALS['_JEAB_NOW'] = 1_700_000_000;
$GLOBALS['_JEAB_EINSTEIN_SECRET'] = ['url' => 'https://hooks.flowstack.example.test/bug', 'key' => 'slow-alarm-test'];
$GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
unset($GLOBALS['_JEAB_REMINDER_SLOW_MS'], $GLOBALS['_JEAB_REMINDER_SLOW_WINDOW']);
putenv('JEAB_DISABLE_SQLITE=1');
putenv('JEAB_BUG_WEBHOOK_CAPTURE=1');
putenv('JEAB_REMINDER_SLOW_MS');
putenv('JEAB_REMINDER_SLOW_WINDOW');

require dirname(__DIR__) . '/src/bootstrap.php';

function slow_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/** @return list<array<string, mixed>> */
function slow_alarm_rows(): array
{
    $raw = is_file(jeab_app_errors_path()) ? (string) file_get_contents(jeab_app_errors_path()) : '';
    $out = [];
    foreach (preg_split("/\n/", $raw) ?: [] as $line) {
        $row = json_decode(trim((string) $line), true);
        if (!is_array($row)) {
            continue;
        }
        if (!str_starts_with((string) ($row['message'] ?? ''), 'långsamt anrop')) {
            continue;
        }
        $out[] = $row;
    }
    return $out;
}

function slow_notes(): int
{
    $n = 0;
    foreach ($GLOBALS['_JEAB_BUG_WEBHOOKS'] ?? [] as $payload) {
        if (is_array($payload) && str_starts_with((string) ($payload['message'] ?? ''), 'långsamt anrop')) {
            $n++;
        }
    }
    return $n;
}

slow_ok(jeab_reminder_slow_threshold_ms() === 2000, 'standardtröskeln är 2000 ms');
slow_ok(jeab_reminder_slow_window_seconds() === 1800, 'standardfönstret är 30 minuter');
putenv('JEAB_REMINDER_SLOW_MS=1500');
slow_ok(jeab_reminder_slow_threshold_ms() === 1500, 'JEAB_REMINDER_SLOW_MS styr tröskeln');
putenv('JEAB_REMINDER_SLOW_MS');
$GLOBALS['_JEAB_REMINDER_SLOW_MS'] = 900;
slow_ok(jeab_reminder_slow_threshold_ms() === 900, 'global tröskel vinner');
unset($GLOBALS['_JEAB_REMINDER_SLOW_MS']);

$_GET = ['p' => 'reminders', 'intent' => 'page', 'q' => '  Anna   Berg '];
$_POST = [];
$same = jeab_reminder_slow_fingerprint('reminders');
$_GET['q'] = 'anna berg';
slow_ok(jeab_reminder_slow_fingerprint('reminders') === $same, 'samma sökning efter skiftläge och mellanslag');
$_GET['q'] = 'Anna';
slow_ok(jeab_reminder_slow_fingerprint('reminders') !== $same, 'en annan sökning är ett annat fingeravtryck');
$_GET['q'] = 'anna berg';
$_GET['intent'] = 'sync';
slow_ok(jeab_reminder_slow_fingerprint('reminders') !== $same, 'en annan avsikt är en annan rutt');
$_GET['intent'] = 'page';
slow_ok(jeab_reminder_slow_fingerprint('ort') !== $same, 'ett annat anrop är ett annat fingeravtryck');

$_GET = ['p' => 'reminders', 'intent' => 'page', 'q' => 'Testgatan 12'];
$_SERVER['REQUEST_URI'] = '/index.php?p=reminders&intent=page';
jeab_reminder_log_slow('reminders', 1.0);
slow_ok(slow_alarm_rows() === [], '1 sekund under 2000 ms skriver inget larm');

jeab_reminder_log_slow('reminders', 2.5);
$first = slow_alarm_rows();
slow_ok(count($first) === 1, '2,5 sekunder skrivs en gång');
slow_ok((int) ($first[0]['count'] ?? 0) === 1, 'första träffen har räknare 1');
slow_ok((int) ($first[0]['opened_at'] ?? 0) === 1_700_000_000, 'fönstret räknas från första träffen');
slow_ok(slow_notes() === 1, 'första larmet aviseras');
$fp = (string) ($first[0]['fingerprint'] ?? '');
slow_ok($fp === jeab_reminder_slow_fingerprint('reminders'), 'fingeravtrycket är rutt, anrop och sökning');

$GLOBALS['_JEAB_NOW'] = 1_700_000_000 + 120;
jeab_reminder_log_slow('reminders', 3.1);
$again = slow_alarm_rows();
slow_ok(count($again) === 1, 'samma sökning inom 30 minuter skapar ingen ny rad');
slow_ok((int) ($again[0]['count'] ?? 0) === 2, 'räknaren höjs på raden som finns');
slow_ok((int) ($again[0]['last_seen'] ?? 0) === 1_700_000_120, 'last_seen flyttas');
slow_ok((int) ($again[0]['opened_at'] ?? 0) === 1_700_000_000, 'opened_at står kvar');
slow_ok(str_contains((string) ($again[0]['message'] ?? ''), '3100 ms'), 'meddelandet visar senaste tiden');
slow_ok((string) ($again[0]['fingerprint'] ?? '') === $fp, 'fingeravtrycket är oförändrat');
slow_ok(slow_notes() === 1, 'upprepningen aviseras inte igen');
$raw = (string) file_get_contents(jeab_app_errors_path());
slow_ok(!str_contains($raw, 'Testgatan'), 'söktexten ligger inte i felloggen');

$shown = jeab_app_errors_read(20);
$shownCount = 0;
foreach ($shown as $row) {
    if ((string) ($row['fingerprint'] ?? '') === $fp) {
        $shownCount = (int) ($row['count'] ?? 0);
    }
}
slow_ok($shownCount === 2, 'felloggen visar den höjda räknaren');

$_GET['q'] = 'Annan väg 2';
$GLOBALS['_JEAB_NOW'] = 1_700_000_200;
jeab_reminder_log_slow('reminders', 2.2);
$other = slow_alarm_rows();
slow_ok(count($other) === 2, 'en annan sökning är ett eget larm');
slow_ok(slow_notes() === 2, 'det andra larmet aviseras');

$_GET['q'] = 'Testgatan 12';
$GLOBALS['_JEAB_NOW'] = 1_700_000_000 + 1801;
jeab_reminder_log_slow('reminders', 2.4);
$later = array_values(array_filter(
    slow_alarm_rows(),
    static fn(array $row): bool => (string) ($row['fingerprint'] ?? '') === $fp
));
slow_ok(count($later) === 2, 'efter 30 minuter skrivs ett nytt larm');
slow_ok((int) ($later[0]['count'] ?? 0) === 2 && (int) ($later[1]['count'] ?? 0) === 1, 'det gamla larmet behåller sin räknare');
slow_ok(slow_notes() === 3, 'det nya larmet aviseras');

echo "OK  långsamma påminnelser\n";
