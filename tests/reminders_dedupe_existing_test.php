<?php

declare(strict_types=1);

/**
 * reminders.dedupe_existing stänger öppna dubbletter på contact_id och produkt.
 * Äldsta raden, eller den med order eller bokning, behålls. Anteckning och länk flyttas dit.
 * En rad med en annan order eller bokning lämnas orörd. dry=1 skriver inget.
 * reminders.dedupe_undo återställer. reminders.get lämnar inget personnummer.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-rem-dedupe-' . bin2hex(random_bytes(4));
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

function rd(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function rd_insert(PDO $pdo, array $row): int
{
    $pdo->prepare(
        'INSERT INTO reminders (
            list_id, title, customer_name, email, phone, address, ort, postal, assignee, company,
            note, due_at, completed_at, booking_id, contact_id, thread_id, created_at, updated_at,
            ringt, pratat, mailat, smsat
        ) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0)'
    )->execute([
        $row['title'],
        $row['customer_name'] ?? null,
        $row['email'] ?? null,
        $row['phone'] ?? null,
        $row['address'] ?? null,
        $row['ort'] ?? null,
        $row['postal'] ?? null,
        $row['assignee'] ?? null,
        $row['company'] ?? 'jarfalla',
        $row['note'] ?? null,
        $row['due_at'] ?? '2026-10-01 00:00:00',
        $row['completed_at'] ?? null,
        $row['booking_id'] ?? null,
        $row['contact_id'],
        $row['thread_id'] ?? null,
        $row['created_at'],
        $row['created_at'],
        (int) ($row['ringt'] ?? 0),
    ]);

    return (int) $pdo->lastInsertId();
}

function rd_state(PDO $pdo): array
{
    $rows = [];
    foreach ($pdo->query('SELECT id, note, completed_at, booking_id, thread_id, ringt, contact_id, deleted_at FROM reminders ORDER BY id') as $row) {
        $rows[(int) $row['id']] = [
            'note' => (string) ($row['note'] ?? ''),
            'completed_at' => (string) ($row['completed_at'] ?? ''),
            'booking_id' => (int) ($row['booking_id'] ?? 0),
            'thread_id' => (string) ($row['thread_id'] ?? ''),
            'ringt' => (int) ($row['ringt'] ?? 0),
            'contact_id' => (string) ($row['contact_id'] ?? ''),
            'deleted_at' => (string) ($row['deleted_at'] ?? ''),
        ];
    }

    return $rows;
}

function rd_orders(PDO $pdo): array
{
    $out = [];
    $stmt = $pdo->query('SELECT reminder_id, payload FROM purchases ORDER BY reminder_id');
    foreach ($stmt ? $stmt->fetchAll() : [] as $row) {
        $out[(int) $row['reminder_id']] = (string) $row['payload'];
    }

    return $out;
}

function rd_booking_links(PDO $pdo): array
{
    $out = [];
    $stmt = $pdo->query('SELECT id, reminder_id FROM bookings ORDER BY id');
    foreach ($stmt ? $stmt->fetchAll() : [] as $row) {
        $out[(int) $row['id']] = (int) ($row['reminder_id'] ?? 0);
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $groups
 * @return array<string, array<string, mixed>>
 */
function rd_by_contact(array $groups): array
{
    $out = [];
    foreach ($groups as $group) {
        if (is_array($group)) {
            $out[(string) ($group['contact'] ?? '')] = $group;
        }
    }

    return $out;
}

$store = jeab_store();
rd($store instanceof JeabPdoStore, 'sqlite-lagret är öppet');
$pdo = $store->pdo();
$names = [];
foreach ($pdo->query('PRAGMA table_info(reminders)')->fetchAll() as $col) {
    if (is_array($col)) {
        $names[] = (string) ($col['name'] ?? '');
    }
}
rd(in_array('contact_id', $names, true) && in_array('ringt', $names, true), 'påminnelsetabellen har kontakt och tryck');
if (!in_array('deleted_at', $names, true)) {
    $pdo->exec('ALTER TABLE reminders ADD COLUMN deleted_at TEXT');
}
jeab_purchases_store_ensure($pdo);
if (!jeab_reminders_sql_column($pdo, 'bookings', 'reminder_id')) {
    $pdo->exec('ALTER TABLE bookings ADD COLUMN reminder_id INTEGER');
}
$pdo->prepare('INSERT INTO customers (name, created_at) VALUES (?, ?)')->execute(['Kund Test', '2026-01-01 00:00:00']);
$customerId = (int) $pdo->lastInsertId();
$makeBooking = static function (PDO $pdo, int $customerId, ?int $reminderId = null): int {
    $pdo->prepare(
        'INSERT INTO bookings (customer_id, customer_name, assignee, starts_at, ends_at, status, reminder_id, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $customerId,
        'Bok Test',
        'Elin',
        '2026-11-02 09:00:00',
        '2026-11-02 10:00:00',
        'booked',
        $reminderId,
        '2026-03-02 09:00:00',
        '2026-03-02 09:00:00',
    ]);

    return (int) $pdo->lastInsertId();
};
$stayBooking = $makeBooking($pdo, $customerId);
$bidBooking1 = $makeBooking($pdo, $customerId);
$bidBooking2 = $makeBooking($pdo, $customerId);

$pnr = '198001011234';
$pnrShown = '19800101-1234';
$secretMail = 'hemlig.dedupe@example.test';
$secretName = 'Ada Hemlig';
$secretPhone = '07019900101';

$textOld = rd_insert($pdo, [
    'title' => 'Batteri hemma',
    'contact_id' => 'kt_text',
    'assignee' => 'Elin',
    'note' => 'samma text',
    'created_at' => '2026-01-01 09:00:00',
    'ringt' => 1,
]);
$textMid = rd_insert($pdo, [
    'title' => 'Batteri hemma',
    'contact_id' => 'kt_text',
    'assignee' => 'Elin',
    'note' => 'samma text',
    'created_at' => '2026-02-01 09:00:00',
]);
$textNew = rd_insert($pdo, [
    'title' => 'Batteri hemma',
    'contact_id' => 'kt_text',
    'assignee' => 'Roberto',
    'note' => "samma text\nextra rad",
    'thread_id' => 'thread-text-new',
    'created_at' => '2026-03-01 09:00:00',
    'ringt' => 2,
]);
$textTrash = rd_insert($pdo, [
    'title' => 'Batteri hemma',
    'contact_id' => 'kt_text',
    'note' => 'samma text i papperskorgen',
    'created_at' => '2026-01-15 09:00:00',
]);
$pdo->prepare('UPDATE reminders SET deleted_at = ? WHERE id = ?')->execute(['2026-04-01 09:00:00', $textTrash]);

$linkKept = rd_insert($pdo, [
    'title' => 'Solceller tak',
    'contact_id' => 'kt_links',
    'assignee' => 'Elin',
    'note' => 'bokningen står kvar',
    'booking_id' => $stayBooking,
    'created_at' => '2026-01-02 09:00:00',
    'ringt' => 1,
]);
$linkOrder = rd_insert($pdo, [
    'title' => 'Solceller uppföljning',
    'contact_id' => 'kt_links',
    'customer_name' => $secretName,
    'email' => $secretMail,
    'phone' => $secretPhone,
    'note' => "orderanteckning\n" . $pnrShown,
    'thread_id' => 'thread-order',
    'created_at' => '2026-03-02 09:00:00',
    'ringt' => 3,
]);
$orderPayload = json_encode([
    'reminder_id' => $linkOrder,
    'fortnox_order' => 'F-77',
    'personnummer' => $pnr,
    'booking_id' => 0,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
rd(is_string($orderPayload), 'orderpayloaden går att skriva');
$pdo->prepare('INSERT INTO purchases (reminder_id, payload, updated_at) VALUES (?, ?, ?)')->execute([
    $linkOrder,
    $orderPayload,
    '2026-03-02 09:00:00',
]);
$bookingRow = $makeBooking($pdo, $customerId, $linkOrder);
rd($bookingRow > 0, 'bokningsraden pekar på orderpåminnelsen');

$plain = rd_insert($pdo, [
    'title' => 'Batteri lös',
    'contact_id' => 'kt_two',
    'note' => 'lös anteckning',
    'created_at' => '2026-01-03 09:00:00',
    'ringt' => 4,
]);
$orderA = rd_insert($pdo, [
    'title' => 'Batteri order ett',
    'contact_id' => 'kt_two',
    'note' => 'första ordern',
    'created_at' => '2026-02-03 09:00:00',
]);
$orderB = rd_insert($pdo, [
    'title' => 'Batteri order två',
    'contact_id' => 'kt_two',
    'note' => 'andra ordern',
    'created_at' => '2026-03-03 09:00:00',
]);
$payloadA = '{"reminder_id":' . $orderA . ',"fortnox_order":"A-1","personnummer":"' . $pnr . '"}';
$payloadB = '{"reminder_id":' . $orderB . ',"fortnox_order":"B-2","personnummer":"' . $pnr . '"}';
$pdo->prepare('INSERT INTO purchases (reminder_id, payload, updated_at) VALUES (?, ?, ?)')->execute([$orderA, $payloadA, '2026-02-03 09:00:00']);
$pdo->prepare('INSERT INTO purchases (reminder_id, payload, updated_at) VALUES (?, ?, ?)')->execute([$orderB, $payloadB, '2026-03-03 09:00:00']);

$bidA = rd_insert($pdo, [
    'title' => 'Laddbox hemma',
    'contact_id' => 'kt_bids',
    'note' => 'bokning ett',
    'booking_id' => $bidBooking1,
    'created_at' => '2026-01-04 09:00:00',
]);
$bidB = rd_insert($pdo, [
    'title' => 'Laddbox hemma',
    'contact_id' => 'kt_bids',
    'note' => 'bokning två',
    'booking_id' => $bidBooking2,
    'created_at' => '2026-02-04 09:00:00',
]);

$doneOpen = rd_insert($pdo, [
    'title' => 'Solceller ensam',
    'contact_id' => 'kt_done',
    'note' => 'den öppna',
    'created_at' => '2026-05-01 09:00:00',
]);
$doneClosed = rd_insert($pdo, [
    'title' => 'Solceller ensam',
    'contact_id' => 'kt_done',
    'note' => 'den klara',
    'completed_at' => '2026-05-02 09:00:00',
    'created_at' => '2026-04-01 09:00:00',
]);
rd_insert($pdo, [
    'title' => 'Batteri annan',
    'contact_id' => 'kt_other',
    'note' => 'ensam kontakt',
    'created_at' => '2026-01-05 09:00:00',
]);
rd_insert($pdo, [
    'title' => 'Ring kunden',
    'contact_id' => 'kt_plain',
    'note' => 'ingen produkt',
    'created_at' => '2026-01-06 09:00:00',
]);
rd_insert($pdo, [
    'title' => 'Ring kunden igen',
    'contact_id' => 'kt_plain',
    'note' => 'ingen produkt heller',
    'created_at' => '2026-02-06 09:00:00',
]);
$getOpen = rd_insert($pdo, [
    'title' => 'Batteri hemlig',
    'contact_id' => 'kt_get',
    'customer_name' => $secretName,
    'email' => $secretMail,
    'phone' => $secretPhone,
    'postal' => '19800101',
    'assignee' => 'Elin',
    'note' => 'hemlig rad ' . $pnr,
    'created_at' => '2026-05-01 08:00:00',
]);
$getDone = rd_insert($pdo, [
    'title' => 'Solceller klar',
    'contact_id' => 'kt_get',
    'assignee' => 'Roberto',
    'note' => 'klar rad ' . $pnrShown,
    'completed_at' => '2026-05-03 08:00:00',
    'created_at' => '2026-05-01 07:00:00',
]);

$beforeRows = rd_state($pdo);
$beforeOrders = rd_orders($pdo);
$beforeBookings = rd_booking_links($pdo);
$beforeCount = count($beforeRows);

$dry = jeab_reminders_dedupe_existing($store, []);
$dryJson = json_encode($dry, JSON_UNESCAPED_UNICODE);
rd(($dry['ok'] ?? false) === true && (int) ($dry['dry'] ?? 0) === 1 && ($dry['read_only'] ?? false) === true, 'dry saknas är torr');
rd((int) ($dry['sent'] ?? 1) === 0 && $dry['batch'] === null && $dry['closed'] === [], 'torrkörningen skriver ingen batch');
rd(rd_state($pdo) === $beforeRows && rd_orders($pdo) === $beforeOrders && rd_booking_links($pdo) === $beforeBookings, 'dry=1 lämnar rader, ordrar och bokningar');
rd(is_string($dryJson) && !str_contains($dryJson, $pnr) && !str_contains($dryJson, $pnrShown) && !str_contains($dryJson, $secretMail) && !str_contains($dryJson, $secretName), 'planen innehåller inget personnummer, ingen e-post och inget namn');
rd(!str_contains((string) $dryJson, 'samma text') && !str_contains((string) $dryJson, 'orderanteckning'), 'planen innehåller ingen anteckning');
$planned = rd_by_contact($dry['groups'] ?? []);
rd(isset($planned['kt_text'], $planned['kt_links'], $planned['kt_two']) && !isset($planned['kt_bids'], $planned['kt_done'], $planned['kt_other'], $planned['kt_plain'], $planned['kt_get']), 'bara grupper med en öppen dubblett på samma produkt');
$textPlan = $planned['kt_text'];
rd($textPlan['ids'] === [$textOld, $textMid, $textNew] && (int) $textPlan['kept'] === $textOld && $textPlan['product'] === 'batteri' && !isset($textPlan['held']), 'identisk text: äldsta behålls och papperskorgen är inte med');
rd(!in_array($textTrash, $textPlan['ids'], true), 'papperskorgen ingår inte i planen');
$linkPlan = $planned['kt_links'];
rd((int) $linkPlan['kept'] === $linkKept && $linkPlan['ids'] === [$linkKept, $linkOrder] && $linkPlan['product'] === 'sol', 'raden med bokning behålls framför den yngre ordern');
$twoPlan = $planned['kt_two'];
rd((int) $twoPlan['kept'] === $orderA && $twoPlan['ids'] === [$plain, $orderA] && ($twoPlan['held'] ?? []) === [$orderB] && $twoPlan['product'] === 'batteri', 'en andra order lämnas utanför sammanslagningen');

$denied = jeab_reminders_dedupe_dispatch($store, ['action' => 'reminders.dedupe_existing', 'dry' => 0]);
rd(($denied['ok'] ?? true) === false && (int) ($denied['status'] ?? 0) === 401, 'hook utan token skriver inte');
rd(rd_state($pdo) === $beforeRows, 'nekad hook lämnar raderna');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = 'tok-rem-dedupe';
$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-rem-dedupe']));

$wet = jeab_reminders_dedupe_dispatch($store, ['action' => 'reminders.dedupe_existing', 'dry' => '0']);
$wetJson = json_encode($wet, JSON_UNESCAPED_UNICODE);
rd(($wet['ok'] ?? false) === true && (int) ($wet['dry'] ?? 1) === 0 && ($wet['read_only'] ?? true) === false, 'dry=0 skriver');
rd(is_string($wet['batch'] ?? null) && preg_match('/\A[a-f0-9]{32}\z/', (string) $wet['batch']) === 1, 'skrivningen får en batch');
rd(($wet['closed'] ?? []) === [$textMid, $textNew, $linkOrder, $plain], 'fyra dubbletter stängs, fick ' . json_encode($wet['closed'] ?? null));
rd(is_string($wetJson) && !str_contains($wetJson, $pnr) && !str_contains($wetJson, $secretMail), 'skrivsvaret innehåller inget personnummer');
rd(count(rd_state($pdo)) === $beforeCount, 'ingen rad raderas');

$after = rd_state($pdo);
rd($after[$textOld]['completed_at'] === '' && $after[$textOld]['ringt'] === 3 && str_contains($after[$textOld]['note'], 'extra rad') && str_contains($after[$textOld]['note'], 'samma text') && $after[$textOld]['thread_id'] === 'thread-text-new', 'behållen rad får anteckning, tryck och tråd');
rd($after[$textOld]['contact_id'] === 'kt_text' && $after[$textOld]['booking_id'] === 0, 'behållen rad behåller kontakt och saknar bokningsfält');
rd($after[$textMid]['completed_at'] !== '' && $after[$textMid]['note'] === "samma text\nsammanslagen med #" . $textOld, 'mellanraden stängs med sammanslagen-raden');
rd($after[$textNew]['completed_at'] !== '' && str_contains($after[$textNew]['note'], 'sammanslagen med #' . $textOld) && str_contains($after[$textNew]['note'], 'extra rad') && $after[$textNew]['thread_id'] === 'thread-text-new' && $after[$textNew]['ringt'] === 2, 'den nyare raden stängs och behåller sin egen text och sina tryck');
rd($after[$textTrash]['deleted_at'] !== '' && $after[$textTrash]['completed_at'] === '' && $after[$textTrash]['note'] === 'samma text i papperskorgen', 'papperskorgen rörs inte');

rd($after[$linkKept]['completed_at'] === '' && $after[$linkKept]['booking_id'] === $stayBooking && $after[$linkKept]['ringt'] === 4 && str_contains($after[$linkKept]['note'], 'orderanteckning') && str_contains($after[$linkKept]['note'], $pnrShown) && $after[$linkKept]['thread_id'] === 'thread-order', 'bokningsraden får orderns anteckning och tråd och behåller bokningen');
rd($after[$linkOrder]['completed_at'] !== '' && str_contains($after[$linkOrder]['note'], 'sammanslagen med #' . $linkKept) && $after[$linkOrder]['booking_id'] === 0 && $after[$linkOrder]['thread_id'] === 'thread-order', 'orderdubbletten stängs');
$ordersNow = rd_orders($pdo);
$movedPayload = $ordersNow[$linkKept] ?? '';
rd(!isset($ordersNow[$linkOrder]) && $movedPayload !== '', 'ordern ligger på den behållna raden');
rd(str_contains($movedPayload, '"fortnox_order":"F-77"') && str_contains($movedPayload, '"personnummer":"' . $pnr . '"') && str_contains($movedPayload, '"reminder_id":' . $linkKept), 'ordern byter bara reminder_id');
rd(str_replace('"reminder_id":' . $linkOrder, '"reminder_id":' . $linkKept, (string) $orderPayload) === $movedPayload, 'övrig ordertext är oförändrad');
$bookingsNow = rd_booking_links($pdo);
rd(($bookingsNow[$bookingRow] ?? 0) === $linkKept && ($bookingsNow[$stayBooking] ?? 0) === 0, 'bokningsradens länk flyttas till den behållna påminnelsen');

rd($after[$orderA]['completed_at'] === '' && $after[$orderA]['note'] === "första ordern\nlös anteckning" && $after[$orderA]['ringt'] === 4, 'första ordern får den lösa anteckningen och trycken');
rd($after[$plain]['completed_at'] !== '' && str_contains($after[$plain]['note'], 'sammanslagen med #' . $orderA), 'den lösa raden stängs mot ordern');
rd($after[$orderB]['completed_at'] === '' && $after[$orderB]['note'] === 'andra ordern', 'den andra ordern är orörd');
rd(($ordersNow[$orderA] ?? '') === $payloadA && ($ordersNow[$orderB] ?? '') === $payloadB, 'två ordrar behåller sina egna reminder_id');
rd($after[$bidA]['completed_at'] === '' && $after[$bidA]['booking_id'] === $bidBooking1 && $after[$bidA]['note'] === 'bokning ett', 'första bokningen rörs inte');
rd($after[$bidB]['completed_at'] === '' && $after[$bidB]['booking_id'] === $bidBooking2 && $after[$bidB]['note'] === 'bokning två', 'andra bokningen rörs inte');
rd($after[$doneOpen]['note'] === 'den öppna' && $after[$doneClosed]['completed_at'] === '2026-05-02 09:00:00' && $after[$doneClosed]['note'] === 'den klara', 'en klar rad är ingen dubblett');

$again = jeab_reminders_dedupe_existing($store, ['dry' => 0]);
rd($again['groups'] === [] && $again['closed'] === [] && $again['batch'] === null, 'en andra körning hittar inget att slå ihop');
rd(rd_state($pdo) === $after && rd_orders($pdo) === $ordersNow, 'andra körningen skriver inget');

$batch = (string) $wet['batch'];
$undoDry = jeab_reminders_dedupe_dispatch($store, ['action' => 'reminders.dedupe_undo', 'batch' => $batch]);
rd(($undoDry['ok'] ?? false) === true && (int) ($undoDry['dry'] ?? 0) === 1 && ($undoDry['restored'] ?? []) === [$textOld, $textMid, $textNew, $linkKept, $linkOrder, $plain, $orderA], 'ångringens dry listar raderna');
rd(rd_state($pdo) === $after && rd_orders($pdo) === $ordersNow && rd_booking_links($pdo) === $bookingsNow, 'ångringens dry skriver inte');

$undo = jeab_reminders_dedupe_dispatch($store, ['action' => 'reminders.dedupe_undo', 'batch' => $batch, 'dry' => 0]);
rd(($undo['ok'] ?? false) === true && (int) ($undo['dry'] ?? 1) === 0 && ($undo['order_missing'] ?? [1]) === [], 'ångringen skriver tillbaka');
$restored = rd_state($pdo);
rd($restored[$textOld] === $beforeRows[$textOld] && $restored[$textMid] === $beforeRows[$textMid] && $restored[$textNew] === $beforeRows[$textNew], 'textgruppen är återställd');
rd($restored[$linkKept] === $beforeRows[$linkKept] && $restored[$linkOrder] === $beforeRows[$linkOrder], 'länkgruppen är återställd');
rd($restored[$plain] === $beforeRows[$plain] && $restored[$orderA] === $beforeRows[$orderA] && $restored[$orderB] === $beforeRows[$orderB], 'ordergruppen är återställd');
rd($restored[$bidA] === $beforeRows[$bidA] && $restored[$bidB] === $beforeRows[$bidB], 'bokningsparet är orört efter ångring');
rd(rd_orders($pdo) === $beforeOrders, 'ordrarna pekar tillbaka');
rd(rd_booking_links($pdo) === $beforeBookings, 'bokningslänken pekar tillbaka');
$undoAgain = jeab_reminders_dedupe_undo($store, ['batch' => $batch, 'dry' => 0]);
rd(($undoAgain['restored'] ?? [1]) === [] && rd_orders($pdo) === $beforeOrders, 'en andra ångring gör inget');

$got = jeab_reminders_dedupe_dispatch($store, ['action' => 'reminders.get', 'ids' => [$getOpen, $getDone, 999999]]);
$gotJson = json_encode($got, JSON_UNESCAPED_UNICODE);
rd(($got['ok'] ?? false) === true && ($got['read_only'] ?? false) === true && (int) ($got['dry'] ?? 0) === 1, 'reminders.get är bara läsning');
rd(($got['missing'] ?? []) === [999999], 'okänt id ligger i missing');
rd(count($got['reminders'] ?? []) === 2, 'två kända id kommer tillbaka');
$openGot = $got['reminders'][0] ?? [];
$doneGot = $got['reminders'][1] ?? [];
rd(array_keys($openGot) === ['id', 'created', 'product', 'status', 'contact_id', 'assignee'], 'svaret har bara de sex fälten');
rd((int) ($openGot['id'] ?? 0) === $getOpen && ($openGot['created'] ?? '') === '2026-05-01 08:00:00' && ($openGot['product'] ?? '') === 'batteri' && ($openGot['status'] ?? '') === 'open' && ($openGot['contact_id'] ?? '') === 'kt_get' && ($openGot['assignee'] ?? '') === 'Elin', 'öppen rad ger id, tid, produkt, status, kontakt och ägare');
rd((int) ($doneGot['id'] ?? 0) === $getDone && ($doneGot['status'] ?? '') === 'done' && ($doneGot['product'] ?? '') === 'sol' && ($doneGot['assignee'] ?? '') === 'Roberto', 'klar rad får status done');
rd(is_string($gotJson) && !str_contains($gotJson, $pnr) && !str_contains($gotJson, $pnrShown) && !str_contains($gotJson, $secretMail) && !str_contains($gotJson, $secretName) && !str_contains($gotJson, $secretPhone) && !str_contains($gotJson, 'hemlig rad'), 'get lämnar inget personnummer och ingen anteckning');
$byString = jeab_reminders_get($store, ['ids' => (string) $getOpen]);
rd(count($byString['reminders'] ?? []) === 1 && (int) ($byString['reminders'][0]['id'] ?? 0) === $getOpen, 'ett id som text fungerar');
$noId = jeab_reminders_get($store, []);
rd(($noId['ok'] ?? true) === false && (int) ($noId['status'] ?? 0) === 422, 'get utan id blir fel');

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
rd(str_contains($hook, "'reminders.dedupe_existing'") && str_contains($hook, "'reminders.dedupe_undo'") && str_contains($hook, "'reminders.get'"), 'hook.php tar emot de tre åtgärderna');

echo "reminders dedupe existing: all passed\n";
