<?php

declare(strict_types=1);

/**
 * Veckologgen v43–v47 (19 okt–16 nov 2026): bara bokningar som ligger, låg
 * eller flyttades in i/ut ur veckan, efter bokningens egna tider.
 * Rader utan tider löses via bokningen eller loggens historik, annars ingenstans.
 * Städningen av QA/test-rader sparar kopia först och rör inga riktiga rader.
 */

$tmp = sys_get_temp_dir() . '/jeab-week-log-bw-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
$GLOBALS['_JEAB_CALENDAR_LOG_BACKUP_DIR'] = $tmp . '/backups';
file_put_contents($tmp . '/config.php', <<<'PHP'
<?php
return [
    'timezone' => 'Europe/Stockholm',
    'outgoing_webhook_url' => '',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => __DIR__ . '/app.sqlite',
        'json_path' => __DIR__ . '/store.json',
    ],
];
PHP);

require dirname(__DIR__) . '/src/bootstrap.php';

function bw_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

date_default_timezone_set('Europe/Stockholm');
$tz = new DateTimeZone('Europe/Stockholm');
$mon = static fn(string $d): DateTimeImmutable => new DateTimeImmutable($d . ' 00:00:00', $tz);

$store = jeab_store(true);
$edin = jeab_create_booking($store, 'Johan Edin', ['Sam L'], '2026-11-04 00:00:00', '2026-11-11 00:00:00', 'sol', ['company' => 'jarfalla', 'assignees' => ['Sam L']]);
$sten = jeab_create_booking($store, 'Sten Håkansson', ['Roberto'], '2026-10-20 07:00:00', '2026-10-20 16:00:00', 'batteri', ['company' => 'jarfalla', 'assignees' => ['Roberto']]);
$filip = jeab_create_booking($store, 'Filip', ['Isaq'], '2026-10-28 00:00:00', '2026-10-30 00:00:00', 'sol', ['company' => 'malmo', 'assignees' => ['Isaq']]);
$edinId = (int) $edin['id'];
$stenId = (int) $sten['id'];
$filipId = (int) $filip['id'];

jeab_calendar_log_write([
    'entries' => [
        // QA Orphan: bokad/avbokad 12 sep, besöket låg 28 okt–3 nov, bokningen finns inte kvar.
        ['id' => 1, 'type' => 'booked', 'at' => '2026-09-12 09:40:00', 'booking_id' => 9001, 'starts_at' => '2026-10-28 00:00:00', 'ends_at' => '2026-11-04 00:00:00', 'title' => 'QA Orphan · Sam L', 'source' => 'web'],
        ['id' => 2, 'type' => 'cancelled', 'at' => '2026-09-12 09:43:00', 'booking_id' => 9001, 'starts_at' => '2026-10-28 00:00:00', 'ends_at' => '2026-11-04 00:00:00', 'title' => 'QA Orphan · Sam L', 'source' => 'web'],
        // Johan Edin bokad 6 okt för 4–10 nov.
        ['id' => 3, 'type' => 'booked', 'at' => '2026-10-06 12:12:00', 'booking_id' => $edinId, 'starts_at' => '2026-11-04 00:00:00', 'ends_at' => '2026-11-11 00:00:00', 'title' => 'Johan Edin · Sol · Sam L', 'source' => 'web'],
        // Sten bokad 6 okt för en dag 20 okt (förr bara i v41, veckan raden skrevs).
        ['id' => 4, 'type' => 'booked', 'at' => '2026-10-06 21:04:00', 'booking_id' => $stenId, 'starts_at' => '2026-10-20 07:00:00', 'ends_at' => '2026-10-20 16:00:00', 'title' => 'Sten Håkansson · Batteri · Roberto', 'source' => 'web'],
        // Rad utan tider, bokningen finns: läggs i bokningens vecka.
        ['id' => 5, 'type' => 'mail', 'at' => '2026-10-07 00:00:00', 'booking_id' => $stenId, 'title' => 'Sten Håkansson · Batteri · Roberto'],
        // Rad utan tider, bokningen borta men loggen vet: historisk vecka (2–6 nov).
        ['id' => 6, 'type' => 'booked', 'at' => '2026-09-01 10:00:00', 'booking_id' => 9002, 'starts_at' => '2026-11-03 07:00:00', 'ends_at' => '2026-11-03 16:00:00', 'title' => 'Gammal Kund · Batteri · Roberto'],
        ['id' => 7, 'type' => 'cancelled', 'at' => '2026-09-02 10:00:00', 'booking_id' => 9002, 'title' => 'Gammal Kund · Batteri · Roberto'],
        // Rad utan tider och utan bokning/historik: syns ingenstans.
        ['id' => 8, 'type' => 'cancelled', 'at' => '2026-10-20 10:00:00', 'booking_id' => 9003, 'title' => 'Okänd · Sol'],
        ['id' => 9, 'type' => 'booked', 'at' => '2026-10-21 10:00:00', 'booking_id' => 0, 'title' => 'Utan bokning'],
        // Flytt från 12 nov till 2 dec: syns i v46 (gamla veckan) men inte v47.
        ['id' => 10, 'type' => 'rescheduled', 'at' => '2026-10-02 10:00:00', 'booking_id' => 9004, 'starts_at' => '2026-12-02 07:00:00', 'ends_at' => '2026-12-02 16:00:00', 'prev_starts_at' => '2026-11-12 07:00:00', 'prev_ends_at' => '2026-11-12 16:00:00', 'title' => 'Flyttad Kund · Batteri · Roberto'],
        // Malmö-bokning i en Järfälla-kalender.
        ['id' => 11, 'type' => 'booked', 'at' => '2026-10-07 15:28:00', 'booking_id' => $filipId, 'starts_at' => '2026-10-28 00:00:00', 'ends_at' => '2026-10-30 00:00:00', 'title' => 'Filip · Sol · Isaq'],
        // Sams egen provbokning 4 okt för 9–13 nov.
        ['id' => 12, 'type' => 'booked', 'at' => '2026-10-04 19:28:00', 'booking_id' => 9005, 'starts_at' => '2026-11-09 00:00:00', 'ends_at' => '2026-11-14 00:00:00', 'title' => 'Sam Exempel · Sol · Sam L'],
    ],
    'backfilled' => true,
]);

/** @return list<int> */
function bw_ids(DateTimeImmutable $monday, JeabStore $store, ?string $company = 'jarfalla'): array
{
    $ids = [];
    foreach (jeab_calendar_log_for_week($monday, 100, $store, $company) as $entry) {
        if ((int) ($entry['id'] ?? 0) > 0) {
            $ids[] = (int) $entry['id'];
        }
    }
    sort($ids);
    return $ids;
}

$v41 = bw_ids($mon('2026-10-05'), $store);
$v43 = bw_ids($mon('2026-10-19'), $store);
$v44 = bw_ids($mon('2026-10-26'), $store);
$v45 = bw_ids($mon('2026-11-02'), $store);
$v46 = bw_ids($mon('2026-11-09'), $store);
$v47 = bw_ids($mon('2026-11-16'), $store);

bw_assert(!in_array(3, $v41, true) && !in_array(4, $v41, true) && !in_array(12, $v41, true), 'bokad i v41 för senare besök syns inte i v41');
bw_assert(in_array(4, $v43, true) && in_array(5, $v43, true), 'Sten 20 okt syns i v43, också raden utan tider');
bw_assert(!in_array(3, $v43, true) && !in_array(3, $v44, true) && !in_array(3, $v47, true), 'Johan Edin syns inte i v43, v44 eller v47');
bw_assert(in_array(3, $v45, true) && in_array(3, $v46, true), 'Johan Edin 4–10 nov syns i v45 och v46');
bw_assert(in_array(1, $v44, true) && in_array(1, $v45, true), 'QA Orphan syns där besöket låg (städas separat)');
bw_assert(in_array(6, $v45, true) && in_array(7, $v45, true), 'avbokning utan tider får tiden från loggens historik');
bw_assert(!in_array(7, $v43, true) && !in_array(7, $v44, true), 'avbokning utan tider hamnar inte i veckan den skrevs');
foreach ([$v41, $v43, $v44, $v45, $v46, $v47] as $week) {
    bw_assert(!in_array(8, $week, true) && !in_array(9, $week, true), 'rad utan tider och utan bokning syns inte');
}
bw_assert(in_array(10, $v46, true) && !in_array(10, $v47, true) && !in_array(10, $v45, true), 'flytt ut ur v46 syns i v46, inte grannveckorna');
bw_assert(!in_array(11, $v44, true), 'Malmö-bokning syns inte i Järfällas logg');
bw_assert(in_array(11, bw_ids($mon('2026-10-26'), $store, 'malmo'), true), 'Malmö-bokning syns i Malmös logg');
bw_assert(in_array(11, bw_ids($mon('2026-10-26'), $store, null), true), 'utan företag filtreras inget');
bw_assert(bw_ids($mon('2026-11-23'), $store) === [], 'tom vecka utan bokningar har tom logg');

bw_assert(jeab_calendar_log_junk_kind('QA Orphan') === 'test', 'QA Orphan är test');
bw_assert(jeab_calendar_log_junk_kind('QA Orphan Short') === 'test', 'QA Orphan Short är test');
bw_assert(jeab_calendar_log_junk_kind('FEJK Testsson') === 'test', 'FEJK Testsson är test');
bw_assert(jeab_calendar_log_junk_kind('Einstein API-test') === 'test', 'Einstein API-test är test');
bw_assert(jeab_calendar_log_junk_kind('sdfg') === 'test', 'sdfg är test');
bw_assert(jeab_calendar_log_junk_kind('Johan Edin') === '', 'Johan Edin är riktig');
bw_assert(jeab_calendar_log_junk_kind('Sten Håkansson') === '', 'Sten Håkansson är riktig');
bw_assert(jeab_calendar_log_junk_kind('Qasim Ali') === '', 'Qa-namn utan mellanslag är riktigt');
bw_assert(jeab_calendar_log_junk_kind('Sam Exempel') === 'review', 'Sam Exempel kräver eget beslut');

$before = jeab_calendar_log_read();
$dry = jeab_calendar_log_cleanup($store, [1, 2, 3], true);
bw_assert(!empty($dry['dry']) && jeab_calendar_log_read() === $before, 'dry ändrar inget');
$candIds = array_map(static fn(array $r): int => (int) $r['id'], $dry['candidates']);
sort($candIds);
bw_assert($candIds === [1, 2, 12], 'kandidater är QA Orphan och provbokningen, inga kunder');

$res = jeab_calendar_log_cleanup($store, [1, 2, 3, 4], false);
$removedIds = array_map(static fn(array $r): int => (int) $r['id'], $res['removed']);
sort($removedIds);
bw_assert($removedIds === [1, 2], 'bara utpekade QA-rader togs bort, kunder rördes inte');
bw_assert((string) $res['backup'] !== '' && is_file((string) $res['backup']), 'kopia sparad');
$backup = json_decode((string) file_get_contents((string) $res['backup']), true);
bw_assert(is_array($backup) && count($backup['entries']) === count($before['entries']), 'kopian har alla rader');
$after = jeab_calendar_log_read();
bw_assert(count($after['entries']) === count($before['entries']) - 2 && !empty($after['backfilled']), 'loggen har två rader färre och backfilled kvar');
bw_assert(!in_array(1, bw_ids($mon('2026-10-26'), $store), true), 'QA Orphan borta ur v44');

// Aktiv bokning med samma namn skyddas även om namnet ser ut som test.
$active = jeab_create_booking($store, 'testmöte', ['Roberto'], '2026-10-22 08:00:00', '2026-10-22 09:00:00', 'service', ['company' => 'jarfalla', 'assignees' => ['Roberto']]);
$data = jeab_calendar_log_read();
$data['entries'][] = ['id' => 50, 'type' => 'booked', 'at' => '2026-10-02 09:38:00', 'booking_id' => (int) $active['id'], 'starts_at' => '2026-10-22 08:00:00', 'ends_at' => '2026-10-22 09:00:00', 'title' => 'testmöte · Service · Isaq'];
jeab_calendar_log_write($data);
$res2 = jeab_calendar_log_cleanup($store, [50], false);
bw_assert($res2['removed'] === [] && $res2['backup'] === '', 'aktiv bokning skyddas och inget skrivs');

$nothing = jeab_calendar_log_cleanup($store, [], false);
bw_assert($nothing['removed'] === [] && $nothing['backup'] === '', 'utan id ändras inget');

echo "calendar week log booking week: ok\n";
