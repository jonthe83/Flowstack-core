<?php

declare(strict_types=1);

/**
 * Tom produkt slås ihop på contact_id och assignee när raderna är skapade
 * inom 24 timmar, eller när anteckningen är densamma. En rad med produkt
 * ingår inte. Samma behåll, flytta, stäng och ångra som övriga dubbletter.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-rem-empty-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ep(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function ep_insert(PDO $pdo, array $row): int
{
    $pdo->prepare(
        'INSERT INTO reminders (
            list_id, title, assignee, company, note, due_at, completed_at, booking_id,
            contact_id, created_at, updated_at, ringt, pratat, mailat, smsat
        ) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0)'
    )->execute([
        $row['title'],
        $row['assignee'] ?? null,
        'jarfalla',
        $row['note'] ?? null,
        '2026-10-24 00:00:00',
        $row['completed_at'] ?? null,
        $row['booking_id'] ?? null,
        $row['contact_id'],
        $row['created_at'],
        $row['created_at'],
        (int) ($row['ringt'] ?? 0),
    ]);

    return (int) $pdo->lastInsertId();
}

function ep_state(PDO $pdo): array
{
    $rows = [];
    foreach ($pdo->query('SELECT id, note, completed_at, booking_id, ringt, contact_id FROM reminders ORDER BY id') as $row) {
        $rows[(int) $row['id']] = [
            'note' => (string) ($row['note'] ?? ''),
            'completed_at' => (string) ($row['completed_at'] ?? ''),
            'booking_id' => (int) ($row['booking_id'] ?? 0),
            'ringt' => (int) ($row['ringt'] ?? 0),
            'contact_id' => (string) ($row['contact_id'] ?? ''),
        ];
    }

    return $rows;
}

/**
 * @param list<array<string, mixed>> $groups
 * @return array<int, array<string, mixed>>
 */
function ep_by_kept(array $groups): array
{
    $out = [];
    foreach ($groups as $group) {
        if (is_array($group)) {
            $out[(int) ($group['kept'] ?? 0)] = $group;
        }
    }

    return $out;
}

$store = jeab_store();
ep($store instanceof JeabPdoStore, 'sqlite-lagret är öppet');
$pdo = $store->pdo();
$pdo->prepare('INSERT INTO customers (name, created_at) VALUES (?, ?)')->execute(['Kund Test', '2026-01-01 00:00:00']);
$customerId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO bookings (customer_id, customer_name, assignee, starts_at, ends_at, status, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    $customerId,
    'Bok Test',
    'Elin',
    '2026-11-02 09:00:00',
    '2026-11-02 10:00:00',
    'booked',
    '2026-10-24 08:00:00',
    '2026-10-24 08:00:00',
]);
$bookingId = (int) $pdo->lastInsertId();
ep($bookingId > 0, 'bokningen finns');

$contact = 'kt_58139320978c9fd5';
$burstA = ep_insert($pdo, [
    'title' => 'Ring kunden',
    'contact_id' => $contact,
    'assignee' => 'Elin',
    'note' => 'ett',
    'created_at' => '2026-10-24 08:00:00',
    'ringt' => 1,
]);
$burstB = ep_insert($pdo, [
    'title' => 'Ring igen',
    'contact_id' => $contact,
    'assignee' => 'Elin',
    'note' => 'två',
    'created_at' => '2026-10-24 08:00:10',
]);
$burstC = ep_insert($pdo, [
    'title' => 'Ring en tredje gång',
    'contact_id' => $contact,
    'assignee' => 'Elin',
    'note' => 'tre',
    'created_at' => '2026-10-24 08:00:20',
    'ringt' => 2,
]);
$product = ep_insert($pdo, [
    'title' => 'Batteri uppföljning',
    'contact_id' => $contact,
    'assignee' => 'Elin',
    'note' => 'ett',
    'created_at' => '2026-10-24 08:00:05',
]);
$otherContact = ep_insert($pdo, [
    'title' => 'Ring kunden',
    'contact_id' => 'kt_annan',
    'assignee' => 'Elin',
    'note' => 'ett',
    'created_at' => '2026-10-24 08:00:10',
]);
$robA = ep_insert($pdo, [
    'title' => 'Ring kunden',
    'contact_id' => $contact,
    'assignee' => 'Roberto',
    'note' => 'alfa',
    'created_at' => '2026-10-24 08:00:00',
]);
$robB = ep_insert($pdo, [
    'title' => 'Ring kunden',
    'contact_id' => $contact,
    'assignee' => 'Roberto',
    'note' => 'beta',
    'created_at' => '2026-10-24 08:00:10',
]);
$winA = ep_insert($pdo, [
    'title' => 'Ring kunden',
    'contact_id' => 'kt_window',
    'assignee' => 'Elin',
    'note' => 'första',
    'created_at' => '2026-01-01 09:00:00',
]);
$winB = ep_insert($pdo, [
    'title' => 'Ring kunden',
    'contact_id' => 'kt_window',
    'assignee' => 'Elin',
    'note' => 'andra',
    'created_at' => '2026-01-02 09:00:00',
]);
$winC = ep_insert($pdo, [
    'title' => 'Ring kunden',
    'contact_id' => 'kt_window',
    'assignee' => 'Elin',
    'note' => 'tredje',
    'created_at' => '2026-01-02 09:00:01',
]);
$noteA = ep_insert($pdo, [
    'title' => 'Ring kunden',
    'contact_id' => 'kt_note',
    'assignee' => 'Elin',
    'note' => "exakt samma\nrad",
    'created_at' => '2026-03-01 09:00:00',
]);
$noteB = ep_insert($pdo, [
    'title' => 'Ring kunden',
    'contact_id' => 'kt_note',
    'assignee' => 'Elin',
    'note' => "exakt samma\r\nrad",
    'created_at' => '2026-03-15 09:00:00',
]);
$gapA = ep_insert($pdo, [
    'title' => 'Ring kunden',
    'contact_id' => 'kt_gap',
    'assignee' => 'Elin',
    'note' => 'olika ett',
    'created_at' => '2026-03-01 09:00:00',
]);
$gapB = ep_insert($pdo, [
    'title' => 'Ring kunden',
    'contact_id' => 'kt_gap',
    'assignee' => 'Elin',
    'note' => 'olika två',
    'created_at' => '2026-03-15 09:00:00',
]);
$caseA = ep_insert($pdo, [
    'title' => 'Ring kunden',
    'contact_id' => 'kt_case',
    'assignee' => 'Elin',
    'note' => 'Hej',
    'created_at' => '2026-04-01 09:00:00',
]);
$caseB = ep_insert($pdo, [
    'title' => 'Ring kunden',
    'contact_id' => 'kt_case',
    'assignee' => 'Elin',
    'note' => 'hej',
    'created_at' => '2026-04-11 09:00:00',
]);
$blankA = ep_insert($pdo, [
    'title' => 'Ring kunden',
    'contact_id' => 'kt_blank',
    'assignee' => 'Elin',
    'note' => '',
    'created_at' => '2026-05-01 09:00:00',
]);
$blankB = ep_insert($pdo, [
    'title' => 'Ring kunden',
    'contact_id' => 'kt_blank',
    'assignee' => 'Elin',
    'note' => '',
    'created_at' => '2026-05-10 09:00:00',
]);
$linkOld = ep_insert($pdo, [
    'title' => 'Ring kunden',
    'contact_id' => 'kt_link',
    'assignee' => 'Elin',
    'note' => 'utan bokning',
    'created_at' => '2026-06-01 09:00:00',
]);
$linkNew = ep_insert($pdo, [
    'title' => 'Ring kunden',
    'contact_id' => 'kt_link',
    'assignee' => 'Elin',
    'note' => 'med bokning',
    'booking_id' => $bookingId,
    'created_at' => '2026-06-01 09:00:10',
]);
$doneOpen = ep_insert($pdo, [
    'title' => 'Ring kunden',
    'contact_id' => 'kt_done_empty',
    'assignee' => 'Elin',
    'note' => 'öppen',
    'created_at' => '2026-07-01 09:00:00',
]);
$doneClosed = ep_insert($pdo, [
    'title' => 'Ring kunden',
    'contact_id' => 'kt_done_empty',
    'assignee' => 'Elin',
    'note' => 'klar',
    'completed_at' => '2026-07-01 10:00:00',
    'created_at' => '2026-07-01 09:00:05',
]);
$bridgeA = ep_insert($pdo, [
    'title' => 'Ring kunden',
    'contact_id' => 'kt_bridge',
    'assignee' => 'Elin',
    'note' => 'alfa',
    'created_at' => '2026-08-01 09:00:00',
]);
$bridgeB = ep_insert($pdo, [
    'title' => 'Ring kunden',
    'contact_id' => 'kt_bridge',
    'assignee' => 'Elin',
    'note' => 'beta',
    'created_at' => '2026-08-01 10:00:00',
]);
$bridgeC = ep_insert($pdo, [
    'title' => 'Ring kunden',
    'contact_id' => 'kt_bridge',
    'assignee' => 'Elin',
    'note' => 'alfa',
    'created_at' => '2026-08-20 09:00:00',
]);

$before = ep_state($pdo);
$dry = jeab_reminders_dedupe_existing($store, []);
ep(($dry['ok'] ?? false) === true && (int) ($dry['dry'] ?? 0) === 1 && $dry['batch'] === null && $dry['closed'] === [], 'dry=1 skriver inget');
ep(ep_state($pdo) === $before, 'dry=1 lämnar raderna');
$planned = ep_by_kept($dry['groups'] ?? []);
$burst = $planned[$burstA] ?? [];
ep(($burst['ids'] ?? []) === [$burstA, $burstB, $burstC] && ($burst['product'] ?? 'x') === '' && ($burst['contact'] ?? '') === $contact, 'tre tomma rader inom 20 sekunder blir en grupp och den äldsta behålls');
ep(!in_array($product, $burst['ids'] ?? [], true) && !isset($planned[$product]), 'en batterirad i samma sekund ingår inte');
ep(!in_array($otherContact, $burst['ids'] ?? [], true), 'en annan kontakt ingår inte');
$rob = $planned[$robA] ?? [];
ep(($rob['ids'] ?? []) === [$robA, $robB] && ($rob['product'] ?? 'x') === '', 'samma tid med annan assignee blir en egen grupp');
$window = $planned[$winA] ?? [];
ep(($window['ids'] ?? []) === [$winA, $winB] && !in_array($winC, $window['ids'] ?? [], true) && !isset($planned[$winC]), 'exakt 24 timmar ingår och sekunden efter lämnas');
$sameNote = $planned[$noteA] ?? [];
ep(($sameNote['ids'] ?? []) === [$noteA, $noteB] && ($sameNote['product'] ?? 'x') === '', 'samma anteckning slås ihop efter mer än 24 timmar');
ep(!isset($planned[$gapA], $planned[$gapB], $planned[$caseA], $planned[$caseB], $planned[$doneOpen], $planned[$doneClosed]), 'olika text, annan versal och en klar rad blir ingen grupp');
$blank = $planned[$blankA] ?? [];
ep(($blank['ids'] ?? []) === [$blankA, $blankB], 'två tomma anteckningar är samma text');
$linked = $planned[$linkNew] ?? [];
ep(($linked['ids'] ?? []) === [$linkOld, $linkNew] && (int) ($linked['kept'] ?? 0) === $linkNew, 'raden med bokning behålls framför den äldre');
$bridge = $planned[$bridgeA] ?? [];
ep(($bridge['ids'] ?? []) === [$bridgeA, $bridgeB] && !in_array($bridgeC, $bridge['ids'] ?? [], true) && !isset($planned[$bridgeC]), 'en tidsklunga tar inte med samma text långt senare');

$denied = jeab_reminders_dedupe_dispatch($store, ['action' => 'reminders.dedupe_existing', 'dry' => 0]);
ep(($denied['ok'] ?? true) === false && (int) ($denied['status'] ?? 0) === 401, 'hook utan token skriver inte');
ep(ep_state($pdo) === $before, 'nekad hook lämnar raderna');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = 'tok-rem-empty';
$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-rem-empty']));

$wet = jeab_reminders_dedupe_dispatch($store, ['action' => 'reminders.dedupe_existing', 'dry' => 0]);
$closed = [$burstB, $burstC, $robB, $winB, $noteB, $blankB, $linkOld, $bridgeB];
sort($closed);
ep(($wet['ok'] ?? false) === true && (int) ($wet['dry'] ?? 1) === 0 && ($wet['closed'] ?? []) === $closed, 'dry=0 stänger dubbletterna');
ep(count(ep_state($pdo)) === count($before), 'ingen rad raderas');
$after = ep_state($pdo);
ep($after[$burstA]['completed_at'] === '' && $after[$burstA]['ringt'] === 3 && str_contains($after[$burstA]['note'], 'ett') && str_contains($after[$burstA]['note'], 'två') && str_contains($after[$burstA]['note'], 'tre'), 'behållen rad får anteckningarna och trycken');
ep($after[$burstB]['completed_at'] !== '' && $after[$burstB]['note'] === "två\nsammanslagen med #" . $burstA, 'den andra raden stängs med sammanslagen-raden');
ep($after[$burstC]['completed_at'] !== '' && str_contains($after[$burstC]['note'], 'sammanslagen med #' . $burstA) && $after[$burstC]['ringt'] === 2, 'den tredje raden stängs och behåller sina tryck');
ep($after[$product] === $before[$product] && $after[$otherContact] === $before[$otherContact], 'produkt-raden och den andra kontakten rörs inte');
ep($after[$winC] === $before[$winC] && $after[$gapA] === $before[$gapA] && $after[$gapB] === $before[$gapB], 'raden utanför 24 timmar och paret med olika text rörs inte');
ep($after[$caseA] === $before[$caseA] && $after[$caseB] === $before[$caseB], 'Hej och hej rörs inte');
ep($after[$linkNew]['completed_at'] === '' && $after[$linkNew]['booking_id'] === $bookingId && str_contains($after[$linkNew]['note'], 'utan bokning'), 'bokningen stannar på den behållna raden och anteckningen flyttas dit');
ep($after[$linkOld]['completed_at'] !== '' && $after[$linkOld]['booking_id'] === 0 && str_contains($after[$linkOld]['note'], 'sammanslagen med #' . $linkNew), 'den äldre raden utan bokning stängs');
ep($after[$doneOpen] === $before[$doneOpen] && $after[$doneClosed] === $before[$doneClosed], 'en klar rad rörs inte');
ep($after[$bridgeC] === $before[$bridgeC] && $after[$bridgeA]['completed_at'] === '' && $after[$bridgeB]['completed_at'] !== '', 'samma text utanför tidsklungan rörs inte');

$batch = (string) ($wet['batch'] ?? '');
$undoDry = jeab_reminders_dedupe_dispatch($store, ['action' => 'reminders.dedupe_undo', 'batch' => $batch]);
ep(($undoDry['ok'] ?? false) === true && (int) ($undoDry['dry'] ?? 0) === 1 && ep_state($pdo) === $after, 'ångringens dry skriver inte');
$undo = jeab_reminders_dedupe_dispatch($store, ['action' => 'reminders.dedupe_undo', 'batch' => $batch, 'dry' => 0]);
ep(($undo['ok'] ?? false) === true && ep_state($pdo) === $before, 'ångringen ställer tillbaka raderna');
$undoAgain = jeab_reminders_dedupe_undo($store, ['batch' => $batch, 'dry' => 0]);
ep(($undoAgain['restored'] ?? [1]) === [] && ep_state($pdo) === $before, 'en andra ångring gör inget');

echo "reminders dedupe empty product: all passed\n";
