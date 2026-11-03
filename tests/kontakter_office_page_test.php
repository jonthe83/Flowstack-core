<?php

declare(strict_types=1);

/**
 * Kontorets lista är en SQL-sida. Samma id som kontorsregeln.
 * Öppnad kontakt läser inkorg_list, inte alla mejl.
 * view_for på påminnelse och order behåller kontaktens namn.
 */

$tmp = sys_get_temp_dir() . '/jeab-kt-office-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
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

function kof(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/** @param array<string, mixed> $view @return list<string> */
function kof_ids(array $view): array
{
    $ids = array_map('strval', array_keys($view['rows']));
    sort($ids);

    return $ids;
}

/** @param list<string> $ids @return list<string> */
function kof_want(array $ids): array
{
    sort($ids);

    return $ids;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

kof(jeab_kontakter_sql_is_sole_source(), 'SQL är enda läskällan');
$store = jeab_store();
kof($store instanceof JeabPdoStore, 'sqlite-lagret finns');
$pdo = $store->pdo();
jeab_ensure_contacts_tables($pdo, 'sqlite');
jeab_purchases_store_ensure($pdo);
$now = jeab_now();

$ada = 'kt_jf_addr';
$rows = [
    $ada => ['id' => $ada, 'street' => 'Listgatan 1', 'city' => 'Järfälla', 'postal_code' => '17730', 'company' => 'jarfalla', 'owners' => [['name' => 'Ada Lista', 'contact' => true, 'email' => 'ada.lista@example.test', 'phone' => '0701000001']]],
    'kt_ma_addr' => ['id' => 'kt_ma_addr', 'street' => 'Malmogatan 2', 'city' => 'Malmö', 'postal_code' => '21120', 'company' => 'malmo', 'owners' => [['name' => 'Malmö Ada', 'contact' => true, 'email' => '', 'phone' => '']]],
    'kt_free' => ['id' => 'kt_free', 'street' => 'Frigatan 3', 'city' => 'Fristad', 'company' => '', 'owners' => [['name' => 'Fri Person', 'contact' => true, 'email' => '', 'phone' => '']]],
    'kt_link' => ['id' => 'kt_link', 'street' => 'Linkgatan 4', 'city' => 'Malmö', 'company' => '', 'owners' => [['name' => 'Link Person', 'contact' => true, 'email' => '', 'phone' => '']]],
    'kt_both' => ['id' => 'kt_both', 'street' => 'Bothgatan 5', 'city' => 'Järfälla', 'company' => 'jarfalla', 'owners' => [['name' => 'Both Person', 'contact' => true, 'email' => '', 'phone' => '']]],
    'kt_buy' => ['id' => 'kt_buy', 'street' => 'Buygatan 6', 'city' => 'Järfälla', 'company' => '', 'owners' => [['name' => 'Buy Person', 'contact' => true, 'email' => '', 'phone' => '']]],
    'kt_jf_miss' => ['id' => 'kt_jf_miss', 'street' => '', 'city' => '', 'company' => 'jarfalla', 'owners' => [['name' => 'Miss Person', 'contact' => true, 'email' => '', 'phone' => '']]],
    'kt_cross' => ['id' => 'kt_cross', 'street' => '', 'city' => '', 'company' => 'malmo', 'owners' => [['name' => 'Cross Person', 'contact' => true, 'email' => '', 'phone' => '']]],
];
foreach ($rows as $id => $row) {
    $rows[$id]['street_key'] = jeab_kontakt_street_key((string) ($row['street'] ?? ''));
}
jeab_kontakter_mutate(function (array &$contacts) use ($rows): void {
    foreach ($rows as $id => $row) {
        $contacts[$id] = $row;
    }
});

$link = $store->insertReminder([
    'title' => 'Bara malmö',
    'customer_name' => 'Fel Namn',
    'address' => 'Annanvägen 9',
    'company' => 'malmo',
    'due_at' => '2026-10-22 09:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$pdo->prepare('UPDATE reminders SET contact_id = ?, company = ? WHERE id = ?')->execute(['kt_link', 'malmo', (int) $link['id']]);
$cross = $store->insertReminder([
    'title' => 'Kors',
    'customer_name' => 'Fel Namn',
    'company' => 'jarfalla',
    'due_at' => '2026-10-22 09:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$pdo->prepare('UPDATE reminders SET contact_id = ?, company = ? WHERE id = ?')->execute(['kt_cross', 'jarfalla', (int) $cross['id']]);
$adaRem = $store->insertReminder([
    'title' => 'Adas påminnelse',
    'customer_name' => 'Fel Namn',
    'address' => 'Annanvägen 9, Järfälla',
    'company' => 'jarfalla',
    'email' => 'fel@example.test',
    'phone' => '0701999888',
    'due_at' => '2026-10-22 09:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$pdo->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute([$ada, (int) $adaRem['id']]);
$customer = jeab_find_or_create_customer($store, 'Both Person');
$booking = $store->insertBooking([
    'customer_id' => $customer['id'],
    'customer_name' => 'Both Person',
    'address' => 'Bothgatan 5',
    'phone' => '0701888777',
    'email' => 'both@example.test',
    'company' => 'malmo',
    'assignees' => ['Roberto'],
    'job_type' => 'solceller',
    'starts_at' => '2026-11-02 09:00:00',
    'ends_at' => '2026-11-02 10:00:00',
    'status' => 'booked',
    'created_at' => $now,
    'updated_at' => $now,
]);
$pdo->prepare('UPDATE bookings SET contact_id = ?, company = ? WHERE id = ?')->execute(['kt_both', 'malmo', (int) $booking['id']]);
$buyRem = $store->insertReminder([
    'title' => 'Köp',
    'customer_name' => 'Fel Order',
    'company' => 'jarfalla',
    'due_at' => '2026-10-22 09:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
jeab_purchase_put([
    'reminder_id' => (int) $buyRem['id'],
    'contact_id' => 'kt_buy',
    'company' => 'jarfalla',
    'kind' => 'batteri',
    'customer_name' => 'Fel Order',
    'address' => 'Annanvägen 9',
    'phone' => '0701777666',
    'email' => 'order@example.test',
    'updated_at' => $now,
]);
$pdo->prepare('UPDATE purchases SET contact_id = ?, company = ? WHERE reminder_id = ?')->execute(['kt_buy', 'jarfalla', (int) $buyRem['id']]);

$jarfalla = ['kt_jf_addr', 'kt_free', 'kt_both', 'kt_buy', 'kt_jf_miss', 'kt_cross'];
$malmo = ['kt_ma_addr', 'kt_free', 'kt_link', 'kt_both', 'kt_cross'];
$all = array_keys($rows);

$GLOBALS['_JEAB_KONTAKT_OFFICE_FILL'] = 0;
$GLOBALS['_JEAB_CONTACTS_SQL_ALL'] = 0;
$jar = jeab_kontakter_view($store, '', 'alla', 'jarfalla', 50);
kof((int) $GLOBALS['_JEAB_KONTAKT_OFFICE_FILL'] === 0, 'kontorslistan materialiserar inte id-mängden');
kof((int) $GLOBALS['_JEAB_CONTACTS_SQL_ALL'] === 0, 'listan läser inte hela registret');
kof(kof_ids($jar) === kof_want($jarfalla), 'Järfälla har samma id, fick ' . implode(',', kof_ids($jar)));
kof($jar['counts']['alla'] === 6 && $jar['counts']['adress'] === 4 && $jar['counts']['saknas'] === 2, 'Järfällas chips räknar adress och saknas');
kof(!empty($jar['paged']) && $jar['total'] === 6 && count($jar['rows']) === 6, 'Järfälla är en sida');

$mal = jeab_kontakter_view($store, '', 'alla', 'malmo', 50);
kof(kof_ids($mal) === kof_want($malmo), 'Malmö har samma id, fick ' . implode(',', kof_ids($mal)));

$GLOBALS['_JEAB_KONTAKT_OFFICE_FILL'] = 0;
$alla = jeab_kontakter_view($store, '', 'alla', null, 50);
kof((int) $GLOBALS['_JEAB_KONTAKT_OFFICE_FILL'] === 0, 'alla kontor bygger ingen kontorsmängd');
kof(kof_ids($alla) === kof_want($all), 'alla kontor visar varje id');

$adress = jeab_kontakter_view($store, '', 'adress', 'jarfalla', 50);
kof(kof_ids($adress) === kof_want(['kt_jf_addr', 'kt_free', 'kt_both', 'kt_buy']), 'med adress är bara rader med gata');
kof($adress['counts']['alla'] === 6 && $adress['counts']['adress'] === 4, 'chipet med adress räknar hela kontoret');
$saknas = jeab_kontakter_view($store, '', 'saknas', 'jarfalla', 50);
kof(kof_ids($saknas) === kof_want(['kt_jf_miss', 'kt_cross']), 'saknar adress är bara rader utan gata');
$saknasAlla = jeab_kontakter_view($store, '', 'saknas', null, 50);
kof(kof_ids($saknasAlla) === kof_want(['kt_jf_miss', 'kt_cross']), 'alla kontor och saknar adress har samma id');

$hit = jeab_kontakter_view($store, 'listgatan', 'alla', 'jarfalla', 50);
kof(kof_ids($hit) === ['kt_jf_addr'] && $hit['total'] === 1, 'sökningen träffar en rad');
kof($hit['counts']['alla'] === 6, 'sökningen ändrar inte chipen');
$missOffice = jeab_kontakter_view($store, 'linkgatan', 'alla', 'jarfalla', 50);
kof($missOffice['rows'] === [] && $missOffice['total'] === 0, 'Malmö-kopplingen syns inte i Järfälla-sökningen');

$GLOBALS['_JEAB_CONTACTS_SQL_ALL'] = 0;
$huge = jeab_kontakter_view($store, '', 'alla', null, PHP_INT_MAX);
kof((int) $GLOBALS['_JEAB_CONTACTS_SQL_ALL'] === 0 && !empty($huge['paged']), 'PHP_INT_MAX tar inte hela registret');
kof(count(jeab_kontakter_sorted('ada.lista@example.test')) === 1, 'sökningen i sole-läge är en sida och träffar e-posten');

$adaRow = $store->getReminder((int) $adaRem['id']);
kof(is_array($adaRow), 'påminnelsen finns');
$adaView = jeab_contacts_sole_view_for($adaRow);
kof(is_array($adaView) && (string) ($adaView['name'] ?? '') === 'Ada Lista', 'view_for på påminnelsen är kontaktens namn');
kof((string) ($adaView['street'] ?? '') === 'Listgatan 1', 'view_for på påminnelsen är kontaktens gata');
$shown = jeab_reminder_shown_customer($adaRow);
kof((string) ($shown['name'] ?? '') === 'Ada Lista' && str_contains((string) ($shown['display'] ?? ''), 'Listgatan 1'), 'påminnelsekortet visar kontakten');
$order = jeab_purchase_get((int) $buyRem['id']);
kof(is_array($order), 'ordern finns');
$painted = jeab_ordrar_display_row($order);
kof((string) ($painted['customer_name'] ?? '') === 'Buy Person', 'orderraden visar kontaktens namn');
kof(str_contains((string) ($painted['address'] ?? $painted['gata'] ?? ''), 'Buygatan 6'), 'orderraden visar kontaktens gata');

$extra = [];
for ($i = 1; $i <= 55; $i++) {
    $id = sprintf('kt_page_%02d', $i);
    $extra[$id] = [
        'id' => $id,
        'street' => sprintf('Sidgatan %d', $i),
        'city' => 'Järfälla',
        'company' => 'jarfalla',
        'owners' => [['name' => 'Sida ' . $i, 'contact' => true, 'email' => '', 'phone' => '']],
    ];
}
jeab_kontakter_mutate(function (array &$contacts) use ($extra): void {
    foreach ($extra as $id => $row) {
        $contacts[$id] = $row;
    }
});
$page = jeab_kontakter_view($store, '', 'alla', 'jarfalla', JEAB_KONTAKT_PAGE);
kof(count($page['rows']) === JEAB_KONTAKT_PAGE && $page['total'] === 61 && $page['next'] === JEAB_KONTAKT_PAGE, 'sidan är 50 av 61');
kof(count(jeab_kontakter_sorted('')) === JEAB_KONTAKT_PAGE, 'sorteringen i sole-läge är en sida');

$live = jeab_inkorg_sql_store_live();
kof($live instanceof JeabPdoInkorgStore, 'inkorgens SQL-lager finns');
for ($i = 0; $i < 40; $i++) {
    $mid = 'm-noise-' . $i;
    $payload = json_encode([
        'id' => $mid,
        'thread_id' => 't-noise',
        'subject' => 'Brus ' . $i,
        'company' => 'jarfalla',
        'received_at' => '2026-01-01 10:00:00',
    ], JSON_UNESCAPED_UNICODE);
    $pdo->prepare('INSERT INTO inkorg_messages (id, thread_id, received_at, from_addr, is_note, row_json) VALUES (?, ?, ?, ?, 0, ?)')->execute([
        $mid,
        't-noise',
        '2026-01-01 10:00:00',
        'brus@example.test',
        $payload,
    ]);
}
$pdo->prepare('INSERT INTO inkorg_threads (id, status, row_json) VALUES (?, ?, ?)')->execute([
    't-only-thread',
    'open',
    json_encode(['status' => 'open', 'contact_id' => $ada], JSON_UNESCAPED_UNICODE),
]);
$live->saveListRow([
    'id' => 't-ada',
    'company' => 'jarfalla',
    'contact_id' => $ada,
    'status' => 'open',
    'updated_at' => '2026-10-01 10:00:00',
    'customer_email' => 'ada.lista@example.test',
    'first' => ['subject' => 'Tak till Ada'],
    'subject' => 'Tak till Ada',
]);
$live->saveListRow([
    'id' => 't-borta',
    'company' => 'jarfalla',
    'contact_id' => 'kt_annan',
    'status' => 'open',
    'updated_at' => '2026-10-02 10:00:00',
    'first' => ['subject' => 'Annan kund'],
]);
$GLOBALS['_JEAB_INKORG_MESSAGES_ALL'] = 0;
$GLOBALS['_JEAB_INKORG_THREADS_ALL'] = 0;
$cases = jeab_hs_import_threads_for_contact($ada);
$subjects = [];
$caseIds = [];
foreach ($cases as $case) {
    $subjects[] = (string) ($case['subject'] ?? '');
    $caseIds[] = (string) ($case['id'] ?? '');
}
kof(in_array('Tak till Ada', $subjects, true), 'kontakten får rubriken från listan');
kof(!in_array('t-only-thread', $caseIds, true) && !in_array('t-borta', $caseIds, true), 'tråd utan list-rad och annan kontakt läses inte');
kof((int) $GLOBALS['_JEAB_INKORG_MESSAGES_ALL'] === 0, 'öppningen läser inte alla mejl');
kof((int) $GLOBALS['_JEAB_INKORG_THREADS_ALL'] === 0, 'öppningen läser inte alla trådar');

$error = null;
$flash = null;
$_GET = ['p' => 'kontakter', 'id' => $ada, 'co' => 'jarfalla'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$GLOBALS['_JEAB_INKORG_MESSAGES_ALL'] = 0;
$html = require dirname(__DIR__) . '/src/pages/kontakter.php';
kof(is_string($html) && str_contains($html, 'Tak till Ada') && str_contains($html, 'Listgatan 1'), 'detaljen visar gata och ärende');
kof((int) $GLOBALS['_JEAB_INKORG_MESSAGES_ALL'] === 0, 'detaljsidan läser inte alla mejl');
kof(str_contains($html, 'Ada Lista'), 'detaljen visar kontaktens namn');

$adaStored = $store->getReminder((int) $adaRem['id']);
kof(is_array($adaStored)
    && (string) ($adaStored['customer_name'] ?? '') === 'Fel Namn'
    && (string) ($adaStored['email'] ?? '') === 'fel@example.test'
    && (string) ($adaStored['phone'] ?? '') === '0701999888'
    && str_contains((string) ($adaStored['address'] ?? ''), 'Annanvägen 9'), 'påminnelsens namn, e-post, telefon och adress är oförändrade');
$bookStored = $store->getBooking((int) $booking['id']);
kof(is_array($bookStored)
    && (string) ($bookStored['customer_name'] ?? '') === 'Both Person'
    && (string) ($bookStored['email'] ?? '') === 'both@example.test'
    && (string) ($bookStored['phone'] ?? '') === '0701888777'
    && (string) ($bookStored['address'] ?? '') === 'Bothgatan 5', 'bokningens namn, e-post, telefon och adress är oförändrade');
$orderStored = jeab_purchase_get((int) $buyRem['id']);
kof(is_array($orderStored)
    && (string) ($orderStored['contact_id'] ?? '') === 'kt_buy'
    && trim((string) ($orderStored['customer_name'] ?? '')) === ''
    && trim((string) ($orderStored['name'] ?? '')) === ''
    && trim((string) ($orderStored['email'] ?? '')) === ''
    && trim((string) ($orderStored['phone'] ?? '')) === ''
    && trim((string) ($orderStored['address'] ?? '')) === '', 'ordern behåller bara contact_id, inte namn, e-post, telefon eller adress');

$chunk = [];
for ($i = 1; $i <= 850; $i++) {
    $id = sprintf('kt_chunk_%04d', $i);
    $chunk[$id] = [
        'id' => $id,
        'street' => sprintf('Chunkgatan %d', $i),
        'city' => 'Järfälla',
        'company' => 'jarfalla',
        'owners' => [['name' => 'Chunk ' . $i, 'contact' => true, 'email' => '', 'phone' => '']],
    ];
}
$chunk['kt_chunk_hit'] = [
    'id' => 'kt_chunk_hit',
    'street' => 'Träffgatan 1',
    'city' => 'Järfälla',
    'company' => 'jarfalla',
    'owners' => [['name' => 'Chunkträff', 'contact' => true, 'email' => 'traff@example.test', 'phone' => '0701555444']],
];
jeab_kontakter_mutate(function (array &$contacts) use ($chunk): void {
    foreach ($chunk as $id => $row) {
        $contacts[$id] = $row;
    }
});
$GLOBALS['_JEAB_KONTAKT_OFFICE_IN'] = 0;
$wide = jeab_kontakter_view($store, '', 'alla', 'jarfalla', 50);
kof((int) $GLOBALS['_JEAB_KONTAKT_OFFICE_IN'] === 0, 'stor kontorslista använder union i SQL, inte IN-bitar, fick ' . (int) $GLOBALS['_JEAB_KONTAKT_OFFICE_IN']);
kof(count($wide['rows']) === 50 && (int) $wide['total'] === 912 && (int) $wide['counts']['alla'] === 912, 'den stora kontorssidan är 50 av 912');
$found = jeab_kontakter_view($store, 'traff@example.test', 'alla', 'jarfalla', 50);
kof(kof_ids($found) === ['kt_chunk_hit'] && (int) $found['total'] === 1 && (int) $found['counts']['alla'] === 912, 'sökningen träffar i den andra id-biten');
$phoneHit = jeab_kontakter_view($store, '0701555', 'alla', 'jarfalla', 50);
kof(kof_ids($phoneHit) === ['kt_chunk_hit'] && (int) $phoneHit['total'] === 1, 'telefonprefix träffar i id-bitarna');
$phoneTail = jeab_kontakter_view($store, '555444', 'alla', 'jarfalla', 50);
kof(kof_ids($phoneTail) === [], 'slutet av telefonnumret träffar inte i id-bitarna');

echo "kontakter office page ok\n";
