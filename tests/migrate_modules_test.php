<?php

declare(strict_types=1);

/**
 * Migreringsmoduler: tabell, mappning och checksumma.
 * Skriver ingen JSON och rör ingen flagga.
 */

function mig_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function mig_ok(string $message): void
{
    echo "OK  {$message}\n";
}

$dir = dirname(__DIR__) . '/src/migrate';
$files = glob($dir . '/*.php') ?: [];
sort($files);
$helpers = ['backup.php', 'canon.php', 'log_lines.php', 'registry.php'];
$loaded = 0;
foreach ($files as $file) {
    $base = basename($file);
    require_once $file;
    if (in_array($base, $helpers, true)) {
        continue;
    }
    $id = substr($base, 0, -4);
    $module = 'jeab_migrate_' . $id . '_module';
    $rowsFn = 'jeab_migrate_' . $id . '_rows';
    $sumFn = 'jeab_migrate_' . $id . '_checksum';
    if (!function_exists($rowsFn)) {
        if (!function_exists($module)) {
            mig_fail("{$base} saknar modul");
        }
        continue;
    }
    foreach ([$module, $rowsFn, $sumFn] as $fn) {
        if (!function_exists($fn)) {
            mig_fail("{$base} saknar {$fn}");
        }
    }
    $spec = $module();
    foreach (['id', 'table', 'json', 'primary_key', 'columns', 'schema'] as $key) {
        if (!array_key_exists($key, $spec)) {
            mig_fail("{$id} saknar {$key}");
        }
    }
    if ($spec['id'] !== $id || $spec['table'] === '' || !is_string($spec['json']) || $spec['json'] === '') {
        mig_fail("{$id} har fel id eller tom tabell");
    }
    if (isset($spec['migrate']) || isset($spec['verify'])) {
        mig_fail("{$id} har egen migrate eller verify");
    }
    if (!in_array($spec['primary_key'], $spec['columns'], true)) {
        mig_fail("{$id} primary_key saknas bland kolumner");
    }
    if (!isset($spec['schema']['mysql'], $spec['schema']['sqlite'])) {
        mig_fail("{$id} saknar schema");
    }
    if (!str_contains($spec['schema']['mysql'], $spec['table'])) {
        mig_fail("{$id} schema nämner inte tabellen");
    }
    $loaded++;
}
if ($loaded < 20) {
    mig_fail('för få moduler: ' . $loaded);
}
mig_ok("{$loaded} moduler har tabell, kolumner och schema");

foreach (['reminders', 'customers', 'reminder_lists', 'purchases', 'webshop_orders', 'calendar_log', 'bookings'] as $owned) {
    if (!is_file($dir . '/' . $owned . '.php') || !function_exists('jeab_migrate_' . $owned . '_rows')) {
        mig_fail($owned . ' saknas i registret');
    }
}
mig_ok('ordrar, bokningar och påminnelser finns med');

$notes = ['next_id' => 2, 'notes' => [['id' => 4, 'text' => 'hej', 'personnummer' => '199001011234']]];
$noteRows = jeab_migrate_notes_rows($notes);
$state = jeab_migrate_notes_state_rows($notes);
if (count($noteRows) !== 1 || $noteRows[0]['id'] !== 4 || $state[0]['next_id'] !== 5) {
    mig_fail('anteckningar eller next_id mappas fel');
}
if (str_contains($noteRows[0]['payload'], '199001011234')) {
    mig_fail('personnummer följde med i anteckningen');
}
$noteSum = jeab_migrate_notes_checksum($notes);
if ($noteSum === '' || $noteSum !== jeab_migrate_notes_checksum($notes)) {
    mig_fail('anteckningschecksumma är inte stabil');
}
mig_ok('anteckningar och notes_state');

$sms = ['9' => ['at' => '2026-01-01', 'ok' => true]];
$smsRows = jeab_migrate_sms_sent_rows($sms);
if (count($smsRows) !== 1 || $smsRows[0]['booking_id'] !== 9 || !str_contains($smsRows[0]['payload'], 'ok')) {
    mig_fail('sms mappas fel');
}
mig_ok('sms-sent');

$lines = ['lines' => [['at' => '2026-10-01T10:00:00Z', 'msg' => 'timeout', 'pnr' => '19800101-1234']]];
$logRows = jeab_migrate_app_errors_rows($lines);
if (count($logRows) !== 1 || $logRows[0]['kind'] !== 'app_error' || str_contains($logRows[0]['payload'], '19800101-1234')) {
    mig_fail('loggrad mappas fel');
}
if (jeab_migrate_app_errors_checksum($lines) !== jeab_migrate_app_errors_checksum($lines)) {
    mig_fail('loggchecksumma');
}
mig_ok('jsonl-logg');

$picks = ['kal' => ['person' => 'elin', 'at' => 10]];
if (jeab_migrate_who_picks_rows($picks)[0]['person'] !== 'elin') {
    mig_fail('who-picks');
}
mig_ok('who-picks');

$week = ['w1' => ['at' => 3, 'events' => [['id' => 'e']]]];
if ((int) jeab_migrate_graph_week_rows($week)[0]['cached_at'] !== 3) {
    mig_fail('graph-week');
}
$events = ['a@example.com' => ['4' => 'evt-1']];
$eventRows = jeab_migrate_graph_events_rows($events);
if ($eventRows[0]['booking_id'] !== 4 || $eventRows[0]['event_id'] !== 'evt-1') {
    mig_fail('graph-events');
}
mig_ok('graph-cache');

$queue = ['jobs' => [['kind' => 'order', 'at' => 1700000000, 'payload' => ['id' => 1]]]];
$jobs = jeab_migrate_external_queue_rows($queue);
if (count($jobs) !== 1 || $jobs[0]['kind'] !== 'order' || strlen($jobs[0]['job_key']) !== 64) {
    mig_fail('kö');
}
mig_ok('external-queue');

$mallar = ['items' => ['sol' => ['body' => 'Hej', 'updated_by' => 'elin']], 'pdf' => []];
$mallRows = jeab_migrate_notiser_rows($mallar);
if (count($mallRows) !== 1 || $mallRows[0]['item_key'] !== 'item:sol' || $mallRows[0]['body'] !== 'Hej') {
    mig_fail('notiser');
}
mig_ok('notiser');

$side = jeab_migrate_quote_preview_rows(['thread' => 't1', 'text' => 'offert', 'at' => 'nu']);
if ($side[0]['name'] !== 'quote-preview' || !str_contains($side[0]['payload'], 'offert')) {
    mig_fail('sidodokument');
}
$corr = jeab_migrate_corrections_rows(['lines' => ['{"id":"c1"}', '{"id":"c1"}']]);
if (count($corr) !== 1) {
    mig_fail('korrigeringar ska vara idempotenta i mappningen');
}
mig_ok('inkorgssida');

$docs = ['docs' => [['token' => 'abc', 'status' => 'pending', 'created_at' => '2026-10-01T12:00:00+00:00', 'personnummer' => '200001011234']]];
$full = jeab_migrate_fullmakt_rows($docs);
if ($full[0]['token'] !== 'abc' || str_contains($full[0]['payload'], '200001011234')) {
    mig_fail('fullmakt');
}
mig_ok('fullmakt');

$lead = jeab_migrate_omtago_leads_rows(['leads' => [['lead_id' => 'o1', 'thread_id' => 'th', 'received_at' => '2026-05-01 12:00:00', 'test' => 1]]]);
if ($lead[0]['test'] !== 1 || $lead[0]['thread_id'] !== 'th') {
    mig_fail('omtago');
}
$sent = jeab_migrate_inbox_sent_rows(['rows' => [[
    'created_at' => '2026-05-01 12:00:00',
    'thread_id' => 'th',
    'via' => 'auto',
    'actor' => 'elin',
    'kind' => 'quote',
    'to' => 'kund@example.com',
    'body' => 'hej',
]]]);
if ($sent[0]['rcpt_hash'] !== '' || str_contains(json_encode($sent), 'kund@example.com') || $sent[0]['body_hash'] === '') {
    mig_fail('utskickslogg');
}
$sentNew = jeab_migrate_inbox_sent_new_rows(['sent' => [
    ['at' => '2026-05-01T12:00:00+02:00', 'by' => 'Test', 'company' => 'jarfalla', 'from' => 'kontakt@jarfalla.example.test', 'to' => 'test@example.com', 'subject' => 'Hej', 'send_key' => 'aabbccdd'],
    ['at' => '2026-05-01T13:00:00+02:00', 'by' => 'Test', 'company' => 'jarfalla', 'from' => 'kontakt@jarfalla.example.test', 'to' => 'other@example.com', 'subject' => 'Två', 'send_key' => ''],
]]);
if (count($sentNew) !== 2 || $sentNew[0]['send_key'] !== 'aabbccdd' || !str_starts_with($sentNew[1]['send_key'], 'nk:')) {
    mig_fail('sent-new mappas fel');
}
if (jeab_migrate_inbox_sent_new_checksum(['sent' => [['send_key' => 'aabbccdd', 'to' => 'test@example.com']]])
    !== jeab_migrate_inbox_sent_new_checksum(['sent' => [['send_key' => 'aabbccdd', 'to' => 'test@example.com']]])) {
    mig_fail('sent-new checksumma');
}
mig_ok('leads och utskickslogg');

$state = jeab_migrate_graph_state_rows(['subs' => ['jeab' => ['id' => 's']]]);
if ($state[0]['doc_key'] !== 'graph_state' || !str_contains($state[0]['payload'], 'jeab')) {
    mig_fail('graph-state');
}
if (jeab_migrate_malmo_hs_cursor_rows(['watermark' => '2026-01-01', 'page' => 2, 'offset' => 5])[0]['cursor_offset'] !== 5) {
    mig_fail('malmö-cursor');
}
mig_ok('graph-state och hs-cursor');

echo "ALL OK\n";
