<?php

declare(strict_types=1);

/**
 * kontakter.conflicts löser allt vid dry=0. Syntetiska rader.
 * Personnummer får inte synas i hooksvaret.
 */

$tmp = sys_get_temp_dir() . '/jeab-kontakter-conflicts-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
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
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function cf(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function cf_row(string $id, string $name, string $email, string $street, string $postal, string $city, string $updated, string $phone = ''): array
{
    $row = jeab_kontakt_blank([
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'street' => $street,
        'street_key' => $street !== '' ? jeab_kontakt_street_key($street) : '',
        'postal_code' => $postal,
        'city' => $city,
    ]);
    $row['id'] = $id;
    $row['created_at'] = '2018-01-01 00:00:00';
    $row['updated_at'] = $updated;
    return $row;
}

/** @param array<string, mixed> $res */
function cf_decision(array $res, string $action, array $ids): ?array
{
    sort($ids, SORT_STRING);
    foreach ((array) ($res['decisions'] ?? []) as $item) {
        if (!is_array($item) || (string) ($item['action'] ?? '') !== $action) {
            continue;
        }
        $have = array_map('strval', (array) ($item['ids'] ?? []));
        sort($have, SORT_STRING);
        if ($have === $ids) {
            return $item;
        }
    }
    return null;
}

/** @param array<string, mixed> $res */
function cf_links_same(array $res): bool
{
    $before = is_array($res['links']['before'] ?? null) ? $res['links']['before'] : [];
    $after = is_array($res['links']['after'] ?? null) ? $res['links']['after'] : [];
    foreach (['reminders', 'bookings', 'orders', 'inbox', 'unknown'] as $key) {
        if ((int) ($before[$key] ?? -1) !== (int) ($after[$key] ?? -2)) {
            return false;
        }
    }
    return true;
}

$pnr = '198001011234';
$batchToken = jeab_kontakter_conflicts_redact_value([
    'batch' => '0123456789abcdef',
    'error' => 'träff ' . $pnr,
]);
cf(is_array($batchToken) && (string) ($batchToken['batch'] ?? '') === '0123456789abcdef', 'batch-id med tio siffror lämnas orörd');
cf(is_array($batchToken) && !str_contains((string) ($batchToken['error'] ?? ''), $pnr) && str_contains((string) ($batchToken['error'] ?? ''), '######'), 'personnummer i feltexten redigeras');
$otherPnr = '197912129999';
$GLOBALS['_JEAB_KONTAKT_POSTORT_LOOKUP'] = static function (string $street, string $postal): array {
    if (str_contains($street, 'Uppslagsvägen 7')) {
        return ['postal_code' => '17777', 'city' => 'Teststad'];
    }
    return [];
};

$address = cf_row('kt_cf_address', 'Ada Adress', 'ada.adress@example.test', 'Exempelgatan 12, 177 40 Järfälla', '', '', '2024-01-01 00:00:00');
$sibling = cf_row('kt_cf_sibling', 'Bo Syskon', 'bo.syskon@example.test', 'Syskonvägen 3', '', 'Teststad', '2020-04-02 00:00:00');
$known = cf_row('kt_cf_known', 'Bo Känd', 'bo.kand@example.test', 'Syskonvägen 3', '17731', 'Teststad', '2026-06-02 00:00:00');
$keepA = cf_row('kt_cf_keep_a', 'Cia Håll', 'cia.hall@example.test', 'Hallgatan 1', '11122', 'Alfa', '2024-02-01 00:00:00');
$keepB = cf_row('kt_cf_keep_b', 'Cia Annan', 'cia.hall@example.test', 'Annanvägen 9', '22233', 'Beta', '2024-03-01 00:00:00');
$mergeA = cf_row('kt_cf_merge_a', 'Dag Hel', 'dag.hel@example.test', 'Helgatan 4', '17741', 'Teststad', '2026-08-02 00:00:00');
$mergeB = cf_row('kt_cf_merge_b', 'Dag Hel', 'dag.hel@example.test', 'Helgatan 4', '17741', 'Teststad', '2020-02-02 00:00:00');
$splitA = cf_row('kt_cf_split_a', 'Eva Ett', 'eva.ett@example.test', 'Delgatan 1', '17740', 'Teststad', '2026-07-02 00:00:00');
$splitB = cf_row('kt_cf_split_b', 'Eva Två', 'eva.ett@example.test', 'Delgatan 1', '', 'Teststad', '2020-03-02 00:00:00');
$splitB['owners'][0]['personnummer'] = $pnr;
$splitA['owners'][0]['personnummer'] = $otherPnr;
$lonely = cf_row('kt_cf_lonely', 'Finn Ensam', 'finn.ensam@example.test', 'Ensamvägen 1', '', 'Enort', '2024-04-01 00:00:00');
$linked = cf_row('kt_cf_linked', 'Gerd Länk', 'gerd.lank@example.test', 'Källgatan 4', '', '', '2024-05-01 00:00:00');
$long = cf_row('kt_cf_long', 'Hans Lång', 'hans.lang@example.test', 'Långgatan 1 198001011234', '', '', '2024-06-01 00:00:00');
$book = cf_row('kt_cf_book', 'Ida Bok', 'ida.bok@example.test', 'Bokgatan 5', '', '', '2024-07-01 00:00:00');
$orderContact = cf_row('kt_cf_order', 'Jan Order', 'jan.order@example.test', 'Ordergatan 2', '', '', '2024-08-01 00:00:00');
$kalkyl = cf_row('kt_cf_kalkyl', 'Kim Kalkyl', 'kim.kalkyl@example.test', 'Kalkylgatan 8', '', '', '2024-09-01 00:00:00');
$lookup = cf_row('kt_cf_lookup', 'Loo Uppslag', 'loo.uppslag@example.test', 'Uppslagsvägen 7', '', 'Teststad', '2024-10-01 00:00:00');
$newest = cf_row('kt_cf_new', 'Mia Ny', 'mia.ny@example.test', 'Nyagatan 1', '', '', '2024-11-01 00:00:00');
$inboxContact = cf_row('kt_cf_inbox', 'Noa Inkorg', 'noa.inkorg@example.test', 'Inkorgsgatan 3', '', '', '2024-12-01 00:00:00');

cf(str_contains((string) file_get_contents(dirname(__DIR__) . '/hook.php'), 'kontakter.conflicts_undo'), 'hook tar kontakter.conflicts_undo');
cf(str_contains((string) file_get_contents(dirname(__DIR__) . '/src/kontakter_conflicts.php'), 'shield.php'), 'konflikter laddar skölden');

$store = jeab_store();
cf(jeab_purchases_sql_set_storage('sql') && jeab_purchases_storage_mode() === 'sql', 'ordrar ligger i sql');

$mkReminder = static function (string $title, string $due, array $details) use ($store): array {
    $row = jeab_create_reminder($store, $title, $due, null, null, $details + [
        'company' => 'jarfalla',
        'who' => 'Elin',
    ]);
    return $row;
};

$linkedReminder = $mkReminder('Gerd Länk', '2019-02-02 09:00:00', [
    'customer' => 'Gerd Länk',
    'email' => 'gerd.lank@example.test',
    'address' => 'Källgatan 4, 177 41 Järfälla',
    'contact_id' => 'kt_cf_linked',
]);
$unknownReminder = $mkReminder('Okänd koppling', '2019-03-03 09:00:00', [
    'customer' => 'Okänd Koppling',
    'email' => 'okand.koppling@example.test',
    'contact_id' => 'kt_cf_unknown',
]);
$mergeReminder = $mkReminder('Dag förlorare', '2019-04-04 09:00:00', [
    'customer' => 'Dag Hel',
    'email' => 'dag.hel@example.test',
    'contact_id' => 'kt_cf_merge_b',
]);
$orderReminder = $mkReminder('Jan Order', '2019-05-05 09:00:00', [
    'customer' => 'Jan Order',
    'email' => 'jan.order@example.test',
    'contact_id' => 'kt_cf_order',
]);
$kalkylReminder = $mkReminder('Kim Kalkyl', '2019-06-06 09:00:00', [
    'customer' => 'Kim Kalkyl',
    'email' => 'kim.kalkyl@example.test',
    'contact_id' => 'kt_cf_kalkyl',
]);
$splitReminder = $mkReminder('Eva Två order', '2019-01-02 09:00:00', [
    'customer' => 'Eva Två',
    'email' => 'eva.ett@example.test',
    'contact_id' => 'kt_cf_split_b',
]);
$newReminder = $mkReminder('Mia Ny gammal källa', '2019-07-07 09:00:00', [
    'customer' => 'Mia Ny',
    'email' => 'mia.ny@example.test',
    'address' => 'Nyagatan 1, 111 11 Gammalstad',
    'contact_id' => 'kt_cf_new',
]);

$customer = jeab_find_or_create_customer($store, 'Ida Bok');
$booking = $store->insertBooking([
    'customer_id' => $customer['id'],
    'customer_name' => 'Ida Bok',
    'email' => 'ida.bok@example.test',
    'address' => 'Bokgatan 5, 177 60 Teststad',
    'notes' => 'fiktiv',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => '2024-07-02 09:00:00',
    'ends_at' => '2024-07-02 10:00:00',
    'status' => 'booked',
    'created_at' => '2024-07-02 09:00:00',
    'updated_at' => '2024-07-02 09:00:00',
]);
$store->pdo()->prepare('UPDATE bookings SET contact_id = ? WHERE id = ?')->execute(['kt_cf_book', (int) $booking['id']]);
$mergeCustomer = jeab_find_or_create_customer($store, 'Dag Hel');
$mergeBooking = $store->insertBooking([
    'customer_id' => $mergeCustomer['id'],
    'customer_name' => 'Dag Hel',
    'email' => 'dag.hel@example.test',
    'address' => '',
    'notes' => 'fiktiv koppling',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => '2019-08-08 09:00:00',
    'ends_at' => '2019-08-08 10:00:00',
    'status' => 'booked',
    'created_at' => '2019-08-08 09:00:00',
    'updated_at' => '2019-08-08 09:00:00',
]);
$store->pdo()->prepare('UPDATE bookings SET contact_id = ? WHERE id = ?')->execute(['kt_cf_merge_b', (int) $mergeBooking['id']]);
$stampReminder = $store->pdo()->prepare('UPDATE reminders SET contact_id = ?, updated_at = ?, created_at = ?, due_at = ? WHERE id = ?');
foreach ([
    [(int) $linkedReminder['id'], 'kt_cf_linked', '2021-02-02 09:00:00'],
    [(int) $unknownReminder['id'], 'kt_cf_unknown', '2019-03-03 09:00:00'],
    [(int) $mergeReminder['id'], 'kt_cf_merge_b', '2019-04-04 09:00:00'],
    [(int) $orderReminder['id'], 'kt_cf_order', '2019-05-05 09:00:00'],
    [(int) $kalkylReminder['id'], 'kt_cf_kalkyl', '2019-06-06 09:00:00'],
    [(int) $splitReminder['id'], 'kt_cf_split_b', '2019-01-02 09:00:00'],
    [(int) $newReminder['id'], 'kt_cf_new', '2020-01-01 00:00:00'],
] as $spec) {
    $stampReminder->execute([$spec[1], $spec[2], $spec[2], $spec[2], $spec[0]]);
}

jeab_purchase_put([
    'reminder_id' => (int) $orderReminder['id'],
    'kind' => 'laddbox',
    'customer_name' => 'Jan Order',
    'contact_id' => 'kt_cf_order',
    'address' => 'Ordergatan 2',
    'postnummer' => '17755',
    'ort' => 'Teststad',
    'created_at' => '2024-08-02 00:00:00',
    'updated_at' => '2024-08-02 00:00:00',
]);
jeab_purchase_put([
    'reminder_id' => (int) $kalkylReminder['id'],
    'kind' => 'laddbox',
    'customer_name' => 'Kim Kalkyl',
    'contact_id' => 'kt_cf_kalkyl',
    'kalkyl' => [
        'address' => 'Kalkylgatan 8',
        'postnummer' => '17766',
        'ort' => 'Teststad',
    ],
    'created_at' => '2024-09-02 00:00:00',
    'updated_at' => '2024-09-02 00:00:00',
]);
jeab_purchase_put([
    'reminder_id' => (int) $splitReminder['id'],
    'kind' => 'batteri',
    'customer_name' => 'Eva Två',
    'contact_id' => 'kt_cf_split_b',
    'address' => 'Delgatan 1',
    'postnummer' => '17740',
    'ort' => 'Teststad',
    'created_at' => '2019-01-01 00:00:00',
    'updated_at' => '2019-01-01 00:00:00',
]);
jeab_purchase_put([
    'reminder_id' => (int) $newReminder['id'],
    'kind' => 'sol',
    'customer_name' => 'Mia Ny',
    'contact_id' => 'kt_cf_new',
    'address' => 'Nyagatan 1',
    'postnummer' => '22222',
    'ort' => 'Nystad',
    'created_at' => '2026-04-01 00:00:00',
    'updated_at' => '2026-04-01 00:00:00',
]);

$contacts = [];
foreach ([$address, $sibling, $known, $keepA, $keepB, $mergeA, $mergeB, $splitA, $splitB, $lonely, $linked, $long, $book, $orderContact, $kalkyl, $lookup, $newest, $inboxContact] as $row) {
    $contacts[$row['id']] = $row;
}
for ($i = 1; $i <= 45; $i++) {
    $pad = cf_row(sprintf('kt_cf_pad_%02d', $i), 'Pad ' . $i, 'pad' . $i . '@example.test', 'Padgatan ' . $i, '11111', 'Padstad', '2023-01-01 00:00:00');
    $contacts[$pad['id']] = $pad;
}
file_put_contents($tmp . '/kontakter.json', json_encode(['contacts' => $contacts], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$seed = jeab_kontakter_pdo_store();
if ($seed instanceof JeabPdoContactStore) {
    $seed->replaceAll(jeab_kontakter_json_file_load());
}
unset($GLOBALS['_JEAB_KONTAKTER_MEM'], $GLOBALS['_JEAB_KONTAKTER_MEM_KEY']);
file_put_contents($tmp . '/inkorg/contacts.json', json_encode(['contacts' => [
    'c-inbox-postal' => [
        'id' => 'c-inbox-postal',
        'name' => 'Noa Inkorg',
        'contact_id' => 'kt_cf_inbox',
        'address' => 'Inkorgsgatan 3',
        'postal_code' => '17788',
        'town' => 'Teststad',
        'updated_at' => '2026-05-01 10:00:00',
    ],
    'c-inbox-merge' => [
        'id' => 'c-inbox-merge',
        'name' => 'Dag Hel',
        'contact_id' => 'kt_cf_merge_b',
        'updated_at' => '2019-01-01 10:00:00',
    ],
]], JSON_UNESCAPED_UNICODE));

$purchasePath = jeab_purchase_path();
$purchaseBefore = is_file($purchasePath) ? (string) file_get_contents($purchasePath) : '';
$jsonMtime = filemtime($tmp . '/kontakter.json');
$dry = jeab_kontakter_conflicts([]);
$encoded = json_encode($dry, JSON_UNESCAPED_UNICODE);
cf(($dry['dry'] ?? false) === true && ($dry['written'] ?? true) === false, 'utan dry skrivs inget');
cf(is_string($encoded) && !str_contains($encoded, $pnr) && !str_contains($encoded, $otherPnr) && !str_contains($encoded, 'confidence'), 'torrkörningen saknar personnummer och manuell kö');
cf((int) ($dry['counts']['manual'] ?? 1) === 0, 'manual är noll');
cf(cf_links_same($dry) && (int) ($dry['links']['before']['unknown'] ?? 0) === 1, 'torrkörningen visar samma antal före och efter');
cf((int) ($dry['links']['before']['reminders'] ?? 0) >= 7 && (int) ($dry['links']['before']['bookings'] ?? 0) === 2 && (int) ($dry['links']['before']['orders'] ?? 0) === 4 && (int) ($dry['links']['before']['inbox'] ?? 0) === 2, 'räkningen täcker påminnelse, bokning, order och inkorg');
cf(filemtime($tmp . '/kontakter.json') === $jsonMtime, 'torrkörning rör inte kontakter.json');
cf((string) ($dry['guard'] ?? 'x') === '', 'planen håller kopplingarna');

$fillAddress = cf_decision($dry, 'fill', ['kt_cf_address']);
$fillSibling = cf_decision($dry, 'fill', ['kt_cf_sibling']);
$fillLinked = cf_decision($dry, 'fill', ['kt_cf_linked']);
$fillBook = cf_decision($dry, 'fill', ['kt_cf_book']);
$fillOrder = cf_decision($dry, 'fill', ['kt_cf_order']);
$fillKalkyl = cf_decision($dry, 'fill', ['kt_cf_kalkyl']);
$fillLookup = cf_decision($dry, 'fill', ['kt_cf_lookup']);
$fillInbox = cf_decision($dry, 'fill', ['kt_cf_inbox']);
$fillNew = cf_decision($dry, 'fill', ['kt_cf_new']);
$keepLonely = cf_decision($dry, 'keep', ['kt_cf_lonely']);
$keepLong = cf_decision($dry, 'keep', ['kt_cf_long']);
$keepPair = cf_decision($dry, 'keep', ['kt_cf_keep_a', 'kt_cf_keep_b']);
$mergeChannel = cf_decision($dry, 'merge', ['kt_cf_merge_a', 'kt_cf_merge_b']);
$mergeAddress = cf_decision($dry, 'merge', ['kt_cf_split_a', 'kt_cf_split_b']);
$mergeSibling = cf_decision($dry, 'merge', ['kt_cf_known', 'kt_cf_sibling']);
cf(is_array($fillAddress) && ($fillAddress['source'] ?? '') === 'address' && ($fillAddress['postal_code'] ?? '') === '17740' && ($fillAddress['city'] ?? '') === 'Järfälla', 'postnummer från adressen');
cf(is_array($fillSibling) && ($fillSibling['source'] ?? '') === 'ort' && ($fillSibling['postal_code'] ?? '') === '17731', 'postnummer från samma gata och ort');
cf(is_array($fillLinked) && ($fillLinked['source'] ?? '') === 'reminder' && ($fillLinked['postal_code'] ?? '') === '17741', 'postnummer från länkad påminnelse');
cf(is_array($fillBook) && ($fillBook['source'] ?? '') === 'booking' && ($fillBook['postal_code'] ?? '') === '17760', 'postnummer från bokning');
cf(is_array($fillOrder) && ($fillOrder['source'] ?? '') === 'order' && ($fillOrder['postal_code'] ?? '') === '17755', 'postnummer från order');
cf(is_array($fillKalkyl) && ($fillKalkyl['source'] ?? '') === 'kalkyl' && ($fillKalkyl['postal_code'] ?? '') === '17766', 'postnummer från kalkyl');
cf(is_array($fillLookup) && ($fillLookup['source'] ?? '') === 'lookup' && ($fillLookup['postal_code'] ?? '') === '17777', 'postnummer från uppslag');
cf(is_array($fillInbox) && ($fillInbox['source'] ?? '') === 'inbox' && ($fillInbox['postal_code'] ?? '') === '17788', 'postnummer från inkorg');
cf(is_array($fillNew) && ($fillNew['source'] ?? '') === 'order' && ($fillNew['postal_code'] ?? '') === '22222', 'nyaste postnumret vinner');
cf(is_array($keepLonely) && ($keepLonely['postal_code'] ?? 'x') === '' && ($keepLong['postal_code'] ?? 'x') === '', 'utan källa och lång sifferserie blir kvar utan postnummer');
cf(is_array($keepPair) && ($keepPair['reason'] ?? '') === 'olika adress, behåll', 'olika adress behålls');
cf(is_array($mergeChannel) && ($mergeChannel['winner'] ?? '') === 'kt_cf_merge_a', 'samma kanal, nyaste vinner');
cf(is_array($mergeAddress) && ($mergeAddress['winner'] ?? '') === 'kt_cf_split_a', 'samma adress slås ihop även med olika personnummer');
cf(is_array($mergeSibling) && ($mergeSibling['winner'] ?? '') === 'kt_cf_known', 'samma normaliserade adress slås ihop');
cf(($dry['counts']['fill'] ?? 0) >= 8 && ($dry['counts']['merge'] ?? 0) >= 3 && ($dry['counts']['keep'] ?? 0) >= 3, 'alla beslut räknas');

$blocked = jeab_kontakter_conflicts(['dry' => 0]);
cf(($blocked['written'] ?? true) === false && ($blocked['blocked'] ?? '') === 'json', 'dry=0 skriver inte medan flaggan är json');
cf((string) (jeab_kontakter_load()['kt_cf_address']['postal_code'] ?? 'x') === '', 'JSON-raden fick inget postnummer');

jeab_kontakter_sql_migrate(['dry' => 0]);
$on = jeab_kontakter_sql_cutover(['enable' => 1, 'dry' => 0]);
cf(!empty($on['ok']) && jeab_kontakter_storage_mode() === 'sql', 'cutover till sql');
clearstatcache(true, $tmp . '/kontakter.json');
$jsonMtime = filemtime($tmp . '/kontakter.json');
$pdo = jeab_contact_store_pdo();
cf($pdo instanceof PDO, 'sql-pdo finns');
$counts = static function (PDO $pdo): array {
    return [
        'reminders' => (int) $pdo->query('SELECT COUNT(*) FROM reminders')->fetchColumn(),
        'bookings' => (int) $pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn(),
        'orders' => (int) $pdo->query('SELECT COUNT(*) FROM purchases')->fetchColumn(),
    ];
};
$beforeCounts = $counts($pdo);
$reminderContact = static function (PDO $pdo, int $id): string {
    $stmt = $pdo->prepare('SELECT contact_id FROM reminders WHERE id = ?');
    $stmt->execute([$id]);
    return trim((string) $stmt->fetchColumn());
};
$bookingContact = static function (PDO $pdo, int $id): string {
    $stmt = $pdo->prepare('SELECT contact_id FROM bookings WHERE id = ?');
    $stmt->execute([$id]);
    return trim((string) $stmt->fetchColumn());
};

$GLOBALS['_JEAB_CONFLICTS_DROP_LINK'] = true;
$guarded = jeab_kontakter_conflicts(['dry' => 0]);
unset($GLOBALS['_JEAB_CONFLICTS_DROP_LINK']);
cf(($guarded['written'] ?? true) === false && ($guarded['rolled_back'] ?? false) === true, 'bruten koppling rullas tillbaka');
cf($counts($pdo) === $beforeCounts, 'antalet rader är oförändrat efter rollback');
cf((string) (jeab_kontakter_load()['kt_cf_address']['postal_code'] ?? 'x') === '', 'rollback lämnar postnumret orört');
cf(isset(jeab_kontakter_load()['kt_cf_merge_b'], jeab_kontakter_load()['kt_cf_split_b']), 'rollback lämnar dubbletterna');
cf($reminderContact($pdo, (int) $mergeReminder['id']) === 'kt_cf_merge_b', 'påminnelsens koppling består efter rollback');
cf($bookingContact($pdo, (int) $mergeBooking['id']) === 'kt_cf_merge_b', 'bokningens koppling består efter rollback');
cf((string) (jeab_purchase_get((int) $splitReminder['id'])['contact_id'] ?? '') === 'kt_cf_split_b', 'orderns koppling består efter rollback');

$wet = jeab_kontakter_conflicts(['dry' => 0]);
$wetJson = json_encode($wet, JSON_UNESCAPED_UNICODE);
cf(($wet['written'] ?? false) === true && ($wet['rolled_back'] ?? true) === false && ($wet['batch'] ?? '') !== '', 'dry=0 skriver alla beslut');
cf(is_string($wetJson) && !str_contains($wetJson, $pnr) && !str_contains($wetJson, $otherPnr), 'skrivsvaret saknar personnummer');
cf(cf_links_same($wet) && (int) ($wet['counts']['manual'] ?? 1) === 0, 'skrivningen behåller antalet kopplingar');
cf($counts($pdo) === $beforeCounts, 'antalet påminnelser, bokningar och ordrar består');
cf(filemtime($tmp . '/kontakter.json') === $jsonMtime, 'applicering skriver inte kontakter.json');
$purchaseAfter = is_file($purchasePath) ? (string) file_get_contents($purchasePath) : '';
cf($purchaseAfter === $purchaseBefore, 'applicering skriver inte purchases.json');
$after = jeab_kontakter_load();
cf((string) ($after['kt_cf_address']['postal_code'] ?? '') === '17740' && (string) ($after['kt_cf_address']['city'] ?? '') === 'Järfälla', 'adressen fick postnummer och ort');
cf((string) ($after['kt_cf_linked']['postal_code'] ?? '') === '17741', 'påminnelsen fyllde postnumret');
cf((string) ($after['kt_cf_book']['postal_code'] ?? '') === '17760', 'bokningen fyllde postnumret');
cf((string) ($after['kt_cf_order']['postal_code'] ?? '') === '17755', 'ordern fyllde postnumret');
cf((string) ($after['kt_cf_kalkyl']['postal_code'] ?? '') === '17766', 'kalkylen fyllde postnumret');
cf((string) ($after['kt_cf_lookup']['postal_code'] ?? '') === '17777', 'uppslaget fyllde postnumret');
cf((string) ($after['kt_cf_inbox']['postal_code'] ?? '') === '17788', 'inkorgen fyllde postnumret');
cf((string) ($after['kt_cf_new']['postal_code'] ?? '') === '22222', 'nyaste ordern skrev postnumret');
cf((string) ($after['kt_cf_lonely']['postal_code'] ?? 'x') === '' && (string) ($after['kt_cf_long']['postal_code'] ?? 'x') === '', 'olösta rader fick inget postnummer');
cf(isset($after['kt_cf_keep_a'], $after['kt_cf_keep_b']), 'behåll-paret finns kvar');
$sqlAfter = (new JeabPdoContactStore($pdo))->sqlAll();
cf(!isset($after['kt_cf_merge_b']) && !isset($after['kt_cf_split_b']) && !isset($after['kt_cf_sibling']), 'borttagna dubbletter läses inte tillbaka från kontakter.json');
cf(isset($sqlAfter['kt_cf_merge_a']) && !isset($sqlAfter['kt_cf_merge_b']), 'kanalsammanslagningen lämnar den nyaste');
cf((string) ($after['kt_cf_merge_a']['postal_code'] ?? '') === '17741' && (string) ($after['kt_cf_merge_a']['street'] ?? '') === 'Helgatan 4', 'kanalsammanslagningen behåller adressen');
cf(isset($sqlAfter['kt_cf_split_a']) && !isset($sqlAfter['kt_cf_split_b']) && isset($sqlAfter['kt_cf_known']) && !isset($sqlAfter['kt_cf_sibling']), 'adressdubbletter är ihopslagna');
$owners = is_array($after['kt_cf_split_a']['owners'] ?? null) ? $after['kt_cf_split_a']['owners'] : [];
$pnrs = [];
foreach ($owners as $owner) {
    if (is_array($owner)) {
        $pnrs[] = (string) ($owner['personnummer'] ?? '');
    }
}
sort($pnrs);
$expectPnrs = [$otherPnr, $pnr];
sort($expectPnrs);
cf($pnrs === $expectPnrs, 'båda personnumren finns kvar på vinnaren');
cf($reminderContact($pdo, (int) $mergeReminder['id']) === 'kt_cf_merge_a', 'påminnelsen pekar på vinnaren');
cf($bookingContact($pdo, (int) $mergeBooking['id']) === 'kt_cf_merge_a', 'bokningen pekar på vinnaren');
cf($reminderContact($pdo, (int) $unknownReminder['id']) === 'kt_cf_unknown', 'okänd koppling består');
cf((string) (jeab_purchase_get((int) $splitReminder['id'])['contact_id'] ?? '') === 'kt_cf_split_a', 'ordern pekar på vinnaren');
$inbox = jeab_kontakt_inbox_read();
cf((string) ($inbox['c-inbox-merge']['contact_id'] ?? '') === 'kt_cf_merge_a', 'inkorgen pekar på vinnaren');
$undoCount = (int) $pdo->query('SELECT COUNT(*) FROM contact_conflict_undo WHERE undone = 0')->fetchColumn();
cf($undoCount > 0, 'undo-tabellen har före- och eftervärden');
$deleted = $pdo->prepare('SELECT after_json, before_json FROM contact_conflict_undo WHERE entity = ? AND entity_id = ? AND undone = 0 ORDER BY seq DESC LIMIT 1');
$deleted->execute(['contact', 'kt_cf_split_b']);
$deletedRow = $deleted->fetch();
cf(is_array($deletedRow) && (string) ($deletedRow['after_json'] ?? '') === 'null' && str_contains((string) ($deletedRow['before_json'] ?? ''), $pnr), 'raderad kontakt kan återställas från undo');

$again = jeab_kontakter_conflicts(['dry' => 0]);
cf(($again['written'] ?? true) === false && ($again['counts']['fill'] ?? 1) === 0 && ($again['counts']['merge'] ?? 1) === 0, 'andra körningen är idempotent');

$undoDry = jeab_kontakter_conflicts_undo([]);
$undoDryJson = json_encode($undoDry, JSON_UNESCAPED_UNICODE);
cf(($undoDry['dry'] ?? false) === true && ($undoDry['written'] ?? true) === false && ($undoDry['entries'] ?? 0) > 0, 'undo är torr som standard');
cf(cf_links_same($undoDry), 'undo-torrkörningen visar samma antal före och efter');
cf(is_string($undoDryJson) && !str_contains($undoDryJson, $pnr) && !str_contains($undoDryJson, 'before_json'), 'undo-svaret saknar personnummer och råvärden');
$sqlStill = (new JeabPdoContactStore($pdo))->sqlAll();
cf(isset($sqlStill['kt_cf_merge_a']) && !isset($sqlStill['kt_cf_merge_b']), 'torr undo skriver inte');

$dbBatch = (string) $pdo->query('SELECT batch_id FROM contact_conflict_undo WHERE undone = 0 ORDER BY id DESC LIMIT 1')->fetchColumn();
cf($dbBatch !== '' && (string) ($wet['batch'] ?? '') === $dbBatch, 'batch-id i svaret matchar körningen');
$undoWet = jeab_kontakter_conflicts_undo(['dry' => 0, 'batch' => (string) $wet['batch']]);
cf(($undoWet['written'] ?? false) === true && ($undoWet['rolled_back'] ?? true) === false, 'undo återställer körningen');
cf($counts($pdo) === $beforeCounts, 'undo behåller antalet rader');
$restored = jeab_kontakter_load();
cf(isset($restored['kt_cf_merge_b'], $restored['kt_cf_split_b'], $restored['kt_cf_sibling']), 'undo lägger tillbaka kontakterna');
cf((string) ($restored['kt_cf_address']['postal_code'] ?? 'x') === '', 'undo tar bort det ifyllda postnumret');
cf((string) ($restored['kt_cf_split_b']['owners'][0]['personnummer'] ?? '') === $pnr, 'undo lägger tillbaka personnumret');
cf($reminderContact($pdo, (int) $mergeReminder['id']) === 'kt_cf_merge_b', 'undo pekar tillbaka påminnelsen');
cf($bookingContact($pdo, (int) $mergeBooking['id']) === 'kt_cf_merge_b', 'undo pekar tillbaka bokningen');
cf((string) (jeab_purchase_get((int) $splitReminder['id'])['contact_id'] ?? '') === 'kt_cf_split_b', 'undo pekar tillbaka ordern');
cf((string) (jeab_kontakt_inbox_read()['c-inbox-merge']['contact_id'] ?? '') === 'kt_cf_merge_b', 'undo pekar tillbaka inkorgen');
cf((int) $pdo->query('SELECT COUNT(*) FROM contact_conflict_undo WHERE undone = 0')->fetchColumn() === 0, 'körningen är markerad som ångrad');

file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-kontakter-conflicts']));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-kontakter-conflicts';
$hook = jeab_kontakter_conflicts_dispatch(['action' => 'kontakter.conflicts']);
cf(is_string($hook['job_id'] ?? null) && ($hook['state'] ?? '') === 'queued' && (int) ($hook['status'] ?? 0) === 200, 'hook svarar med job_id');
cf(!isset($hook['counts']), 'svaret väntar inte på konflikterna');
jeab_data_job_run((string) $hook['job_id']);
$jobDone = jeab_data_job_status_dispatch(['job_id' => (string) $hook['job_id']]);
cf(($jobDone['state'] ?? '') === 'done' && !empty($jobDone['result']['ok']) && ($jobDone['result']['dry'] ?? false) === true, 'jobbet är torrt');
$jobJson = json_encode($jobDone, JSON_UNESCAPED_UNICODE);
cf(is_string($jobJson) && !str_contains($jobJson, $pnr), 'jobbsvaret saknar personnummer');
$hookUndo = jeab_kontakter_conflicts_dispatch(['action' => 'kontakter.conflicts_undo']);
cf(empty($hookUndo['ok']) && ($hookUndo['status'] ?? 0) === 400, 'hook-undo utan kvarvarande körning skriver inte');
$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = jeab_kontakter_conflicts_dispatch(['action' => 'kontakter.conflicts', 'dry' => 0]);
cf(empty($denied['ok']) && ($denied['status'] ?? 0) === 401, 'hook utan token skriver inte');

echo "kontakter_conflicts_test ok\n";
