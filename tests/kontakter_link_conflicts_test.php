<?php

declare(strict_types=1);

/**
 * Konfliktregler för kontakter.link. Bara syntetiska poster.
 * dry=1 skriver inget. dry=0 tar en kopia före första skrivningen.
 */

$tmp = sys_get_temp_dir() . '/jeab-link-conflicts-' . bin2hex(random_bytes(4));
$private = $tmp . '/private';
$public = $tmp . '/public';
mkdir($private, 0775, true);
mkdir($public, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $private;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $public;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($private . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $private . '/app.sqlite', 'json_path' => $private . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function lc_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function lc(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    lc_fail($message);
}

function lc_pnr(string $nine): string
{
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $n = (int) $nine[$i];
        if ($i % 2 === 0) {
            $n *= 2;
            if ($n > 9) {
                $n -= 9;
            }
        }
        $sum += $n;
    }
    $check = (10 - ($sum % 10)) % 10;
    return '19' . substr($nine, 0, 6) . '-' . substr($nine, 6) . $check;
}

function lc_reminder(JeabStore $store, string $name, string $email, string $phone, string $address, string $at): array
{
    $row = jeab_create_reminder($store, $name, $at, null, null, [
        'customer' => $name,
        'email' => $email,
        'phone' => $phone,
        'address' => $address,
        'company' => 'jarfalla',
        'who' => 'Elin',
    ]);
    return $store->saveReminder((int) $row['id'], [
        'created_at' => $at,
        'updated_at' => $at,
        'due_at' => $at,
    ]);
}

function lc_contact_named(string $name): ?array
{
    foreach (jeab_kontakter_load() as $row) {
        if (!is_array($row)) {
            continue;
        }
        foreach ((array) ($row['owners'] ?? []) as $owner) {
            if (is_array($owner) && (string) ($owner['name'] ?? '') === $name) {
                return $row;
            }
        }
    }
    return null;
}

function lc_contacts_file(): string
{
    $path = jeab_kontakter_path();
    if (!is_file($path)) {
        return '';
    }
    $raw = file_get_contents($path);

    return is_string($raw) ? $raw : '';
}

/**
 * Olika e-post på samma person blir två kontakter redan vid skapandet.
 * Kopplingen ska då själv lägga den extra adressen i Övrigt, så de länkarna tas bort först.
 *
 * @param list<int> $reminderIds
 */
function lc_unlink_reminders(JeabStore $store, array $reminderIds): void
{
    $dropContacts = [];
    foreach ($reminderIds as $id) {
        $row = $store->getReminder((int) $id);
        if (!is_array($row)) {
            continue;
        }
        $cid = trim((string) ($row['contact_id'] ?? ''));
        if ($cid === '') {
            continue;
        }
        $dropContacts[$cid] = true;
        $store->saveReminder((int) $id, ['contact_id' => '']);
    }
    if ($dropContacts === []) {
        return;
    }
    jeab_kontakter_mutate(static function (array &$contacts) use ($dropContacts): void {
        foreach (array_keys($dropContacts) as $id) {
            unset($contacts[$id]);
        }
    }, true);
}

function lc_contacts_named(string $name): array
{
    $hits = [];
    foreach (jeab_kontakter_load() as $row) {
        if (!is_array($row)) {
            continue;
        }
        foreach ((array) ($row['owners'] ?? []) as $owner) {
            if (is_array($owner) && (string) ($owner['name'] ?? '') === $name) {
                $hits[] = $row;
                break;
            }
        }
    }
    return $hits;
}

$GLOBALS['_JEAB_LINK_QUIET'] = false;
$GLOBALS['_JEAB_KONTAKT_POSTORT_LOOKUP'] = static function (string $street, string $postal): array {
    unset($street, $postal);
    $GLOBALS['_JEAB_LINK_QUIET'] = jeab_kontakt_quiet();
    return [];
};

$seedPnr = lc_pnr('800101123');
$orderPnr = lc_pnr('900101123');
$seed = jeab_kontakt_blank([
    'name' => 'Seed Person',
    'email' => 'seed@post.test',
    'phone' => '0701000000',
    'personnummer' => $seedPnr,
    'street' => 'Seedgatan 1',
    'street_key' => jeab_kontakt_street_key('Seedgatan 1'),
    'postal_code' => '11122',
    'city' => 'Solna',
]);
jeab_kontakter_mutate(static function (array &$contacts) use ($seed): void {
    $contacts[(string) $seed['id']] = $seed;
});
$store = jeab_store();
$seedReminder = lc_reminder($store, 'Seed Person', 'seed@post.test', '0701000000', 'Seedgatan 1, 111 22 Solna', '2022-01-01 09:00:00');
$store->saveReminder((int) $seedReminder['id'], ['contact_id' => (string) $seed['id']]);

$majaFirst = lc_reminder($store, 'Maja Lind', 'maja@post.test', '0701111111', 'Lindvägen 10, 111 22 Solna', '2024-01-01 09:00:00');
$majaSecond = lc_reminder($store, 'Maja Lind', 'maja.andra@post.test', '0702222222', 'Lindvägen 10, 111 22 Solna', '2024-06-01 09:00:00');
$erik = lc_reminder($store, 'Erik Holm', 'erik@post.test', '0703333333', 'Holmgatan 2, 111 22 Solna', '2024-02-01 09:00:00');
$sara = lc_reminder($store, 'Sara Holm', 'sara@post.test', '0704444444', 'Holmgatan 2, 111 22 Solna', '2024-03-01 09:00:00');
$noraOld = lc_reminder($store, 'Nora Ek', 'nora@post.test', '0705555555', 'Gamla vägen 1, 111 22 Solna', '2023-01-01 09:00:00');
$noraNew = lc_reminder($store, 'Nora Ek', 'nora@post.test', '0705555555', 'Nya vägen 4, 111 22 Solna', '2025-05-01 09:00:00');
$piaA = lc_reminder($store, 'Pia Berg', 'pia@post.test', '0706666666', 'Berggatan 5, 111 22 Solna', '2024-04-01 09:00:00');
$piaB = lc_reminder($store, 'Pia Berg', 'pia@post.test', '0706666666', 'Berggatan 5, 222 33 Solna', '2024-05-01 09:00:00');
$tyst = lc_reminder($store, 'Tyst Kund', 'tyst@post.test', '0707777777', 'Tystgatan 8, Solna', '2024-07-01 09:00:00');

$noraCustomer = jeab_find_or_create_customer($store, 'Nora Ek');
$noraBooking = $store->insertBooking([
    'customer_id' => $noraCustomer['id'],
    'customer_name' => 'Nora Ek',
    'email' => 'nora@post.test',
    'phone' => '0705555555',
    'address' => '',
    'notes' => 'lös kanal',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => '2025-06-01 09:00:00',
    'ends_at' => '2025-06-01 10:00:00',
    'status' => 'booked',
    'created_at' => '2025-06-01 09:00:00',
    'updated_at' => '2025-06-01 09:00:00',
]);
$orderReminder = lc_reminder($store, 'Order Person', 'order@post.test', '0708888888', 'Ordergatan 3, 111 22 Solna', '2024-08-01 09:00:00');
jeab_purchase_put([
    'reminder_id' => (int) $orderReminder['id'],
    'kind' => 'laddbox',
    'name' => 'Order Person',
    'email' => 'order-hemmelig@post.test',
    'phone' => '0708888888',
    'address' => 'Ordergatan 3, 111 22 Solna',
    'personnummer' => $orderPnr,
    'closed_at' => '2024-08-02 09:00:00',
    'allt_klart' => true,
    'company' => 'jarfalla',
    'contact_id' => (string) $seed['id'],
    'updated_at' => '2024-08-02 09:00:00',
]);
$inboxEmail = 'inbox-hemmelig@post.test';
file_put_contents(jeab_inkorg_dir() . '/contacts.json', json_encode([
    'contacts' => [
        'in-seed' => [
            'name' => 'Seed Person',
            'emails' => [$inboxEmail],
            'mobile' => '',
            'address' => 'Seedgatan 1, 111 22 Solna',
            'town' => 'Solna',
            'notes' => '',
            'contact_id' => (string) $seed['id'],
        ],
    ],
], JSON_UNESCAPED_UNICODE));
$inboxBefore = (string) file_get_contents(jeab_inkorg_dir() . '/contacts.json');
lc_unlink_reminders($store, [(int) $majaFirst['id'], (int) $majaSecond['id']]);

$contactsBefore = lc_contacts_file();
$contactsStateBefore = jeab_kontakter_load();
$idsBefore = array_keys($contactsStateBefore);
$reminderBefore = [];
foreach ($store->listReminders(true) as $row) {
    $reminderBefore[(string) $row['id']] = (string) ($row['contact_id'] ?? '');
}

$dry = jeab_kontakter_link(['dry' => 1], $store);
lc(!empty($dry['ok']) && ($dry['dry'] ?? false) === true, 'torrkörning svarar ok');
lc(($dry['created'] ?? 1) === 0 && array_key_exists('backup_file', $dry) && $dry['backup_file'] === null, 'torrkörning skapar ingen kopia');
lc(lc_contacts_file() === $contactsBefore && jeab_kontakter_load() === $contactsStateBefore, 'torrkörning lämnar kontaktfilen');
$bakAfterDry = glob($private . '/kontakter.bak-*.json') ?: [];
$bakPublic = glob($public . '/kontakter.bak-*.json') ?: [];
lc($bakAfterDry === [] && $bakPublic === [], 'torrkörning skriver ingen kontakter.bak');
$stampedDuringDry = false;
foreach ($store->listReminders(true) as $row) {
    if ((string) ($row['contact_id'] ?? '') !== ($reminderBefore[(string) $row['id']] ?? '')) {
        $stampedDuringDry = true;
    }
}
lc(!$stampedDuringDry, 'torrkörning sätter inget contact_id');
lc(!empty($GLOBALS['_JEAB_LINK_QUIET']), 'kopplingen är tyst mot uppslag');
lc(!jeab_kontakt_quiet(), 'tystläget släpps efter körningen');

$idsAtSnapshot = array_keys(jeab_kontakter_load());
$apply = jeab_kontakter_link(['dry' => 0], $store);
lc(!empty($apply['ok']) && ($apply['dry'] ?? true) === false, 'verklig körning svarar ok');
$backupName = (string) ($apply['backup_file'] ?? '');
lc($backupName !== '' && preg_match('/^kontakter\.bak-\d{8}-\d{6}(?:-\d+)?\.json$/', $backupName) === 1, 'svaret har bara filnamnet, fick ' . $backupName);
lc(!str_contains($backupName, '/') && !str_contains($backupName, '\\') && !str_contains($backupName, 'privat'), 'filnamnet är ingen sökväg');
$backupPath = jeab_data_dir() . '/' . $backupName;
lc(is_file($backupPath), 'kopian ligger i datakatalogen');
lc(realpath(dirname($backupPath)) === realpath(jeab_data_dir()), 'kopian ligger i samma katalog som kontaktregistret');
lc(realpath(dirname($backupPath)) === realpath(dirname(jeab_kontakter_path())), 'katalogen är registrets katalog');
lc(jeab_store_path_is_public_data($backupPath) === false, 'kopian räknas inte som publik data');
lc(!is_file($public . '/' . $backupName), 'kopian ligger inte i den publika katalogen');
$rootHt = (string) file_get_contents(dirname(__DIR__) . '/.htaccess');
$dataHt = (string) file_get_contents(dirname(__DIR__) . '/data/.htaccess');
lc(str_contains($rootHt, 'RewriteRule ^data/ - [F,L]'), 'rotens .htaccess spärrar data/');
lc(str_contains($dataHt, 'Require all denied') && str_contains($dataHt, 'Deny from all'), 'data/.htaccess nekar HTTP');
$backupRaw = (string) file_get_contents($backupPath);
$backup = json_decode($backupRaw, true);
lc(is_array($backup), 'kopian är JSON');
$backupIds = array_keys(is_array($backup['contacts'] ?? null) ? $backup['contacts'] : []);
sort($backupIds);
$expectedIds = $idsAtSnapshot;
sort($expectedIds);
lc($backupIds === $expectedIds, 'kopian togs före nya kontakter');
$seedDigits = preg_replace('/\D+/', '', $seedPnr) ?? '';
$orderDigits = preg_replace('/\D+/', '', $orderPnr) ?? '';
lc(!str_contains($backupRaw, $seedDigits) && !str_contains($backupRaw, $seedPnr), 'kopian har inget rått personnummer');
lc(!str_contains($backupRaw, $orderDigits) && !str_contains($backupRaw, $orderPnr), 'orderns personnummer ligger inte i kopian');
lc(!str_contains($backupRaw, $inboxEmail) && !str_contains($backupRaw, 'order-hemmelig@post.test'), 'kopian tar inte med e-post från order eller inkorg');
$seedMasked = jeab_kontakt_pnr_mask((string) ($seed['owners'][0]['personnummer'] ?? ''));
lc($seedMasked !== '' && str_contains($backupRaw, $seedMasked), 'personnummer i kopian är maskerat');
$backupReminders = [];
foreach ((array) ($backup['reminders'] ?? []) as $row) {
    if (is_array($row)) {
        $backupReminders[(string) ($row['id'] ?? '')] = (string) ($row['contact_id'] ?? '');
    }
}
lc(($backupReminders[(string) $seedReminder['id']] ?? '') === (string) $seed['id'], 'kopian har påminnelsens contact_id');
lc(array_key_exists('orders', $backup) && array_key_exists('bookings', $backup) && array_key_exists('inbox_contacts', $backup), 'kopian har ordrar, bokningar och inkorg');
$inboxPair = null;
foreach ((array) ($backup['inbox_contacts'] ?? []) as $row) {
    if (is_array($row) && (string) ($row['id'] ?? '') === 'in-seed') {
        $inboxPair = $row;
    }
}
lc(is_array($inboxPair) && ($inboxPair['contact_id'] ?? '') === (string) $seed['id'] && count($inboxPair) === 2, 'inkorgens rad i kopian är bara id och contact_id');

$kinds = [];
foreach ((array) ($apply['conflicts'] ?? []) as $conflict) {
    if (is_array($conflict)) {
        $kinds[(string) ($conflict['kind'] ?? '')] = true;
    }
}
lc(isset($kinds['extra_email'], $kinds['extra_phone'], $kinds['owner_channel'], $kinds['ambiguous_channel']), 'konflikterna extra_email, extra_phone, owner_channel och ambiguous_channel finns, fick ' . implode(',', array_keys($kinds)));

$maja = lc_contact_named('Maja Lind');
lc(is_array($maja), 'Maja får en kontakt');
$majaNotes = jeab_kontakt_ovrigt(is_array($maja) ? $maja : []);
lc(substr_count($majaNotes, 'E-post: maja.andra@post.test') === 1, 'extra e-post läggs en gång i Övrigt, fick ' . $majaNotes);
lc(substr_count($majaNotes, 'Tel: ' . jeab_kontakt_phone('0702222222')) === 1, 'extra telefon läggs en gång i Övrigt, fick ' . $majaNotes);
lc((string) ($store->getReminder((int) $majaFirst['id'])['contact_id'] ?? '') !== ''
    && (string) ($store->getReminder((int) $majaFirst['id'])['contact_id'] ?? '') === (string) ($store->getReminder((int) $majaSecond['id'])['contact_id'] ?? ''), 'båda Maja-posterna pekar på samma kontakt');

$holm = lc_contact_named('Erik Holm');
lc(is_array($holm), 'paret får en kontakt');
$saraOwner = null;
$erikOwner = null;
foreach ((array) ($holm['owners'] ?? []) as $owner) {
    if (!is_array($owner)) {
        continue;
    }
    if ((string) ($owner['name'] ?? '') === 'Sara Holm') {
        $saraOwner = $owner;
    }
    if ((string) ($owner['name'] ?? '') === 'Erik Holm') {
        $erikOwner = $owner;
    }
}
lc(is_array($saraOwner) && (string) ($saraOwner['email'] ?? '') === 'sara@post.test' && (string) ($saraOwner['phone'] ?? '') === jeab_kontakt_phone('0704444444'), 'ägare 2 behåller e-post och telefon');
lc(is_array($erikOwner) && (string) ($store->getReminder((int) $erik['id'])['contact_id'] ?? '') === (string) ($holm['id'] ?? '')
    && (string) ($store->getReminder((int) $sara['id'])['contact_id'] ?? '') === (string) ($holm['id'] ?? ''), 'båda ägarna kopplas till samma kontakt');

$noraHits = lc_contacts_named('Nora Ek');
lc(count($noraHits) === 1, 'samma e-post blir en kontakt efter dedupe, fick ' . count($noraHits));
$bookingAfter = $store->getBooking((int) $noraBooking['id']);
$bookingContact = (string) ($bookingAfter['contact_id'] ?? '');
$noraContact = $noraHits[0] ?? null;
lc(is_array($noraContact) && $bookingContact === (string) ($noraContact['id'] ?? ''), 'den lösa kanalen pekar på Noras kontakt');
lc(is_array($noraContact) && (string) ($noraContact['street_key'] ?? '') === jeab_kontakt_street_key('Gamla vägen 1'), 'e-postträff lämnar den första gatan, fick ' . (string) ($noraContact['street'] ?? ''));
$noraNotes = jeab_kontakt_ovrigt(is_array($noraContact) ? $noraContact : []);
lc(str_contains($noraNotes, '2023-01-01') && str_contains($noraNotes, 'Gamla vägen 1'), 'den andra adressen läggs som daterad rad, fick ' . $noraNotes);
lc(substr_count($noraNotes, '2023-01-01 Gamla vägen 1') === 1, 'den daterade raden dupliceras inte, fick ' . $noraNotes);

$piaHits = lc_contacts_named('Pia Berg');
lc(count($piaHits) === 1, 'samma gata, nummer och ort blir inte en andra kontakt, fick ' . count($piaHits));
lc((string) ($store->getReminder((int) $piaA['id'])['contact_id'] ?? '') !== ''
    && (string) ($store->getReminder((int) $piaA['id'])['contact_id'] ?? '') === (string) ($store->getReminder((int) $piaB['id'])['contact_id'] ?? ''), 'båda Pia-posterna pekar på samma kontakt');

$notesAfter = $majaNotes;
$again = jeab_kontakter_link(['dry' => 0], $store);
lc(!empty($again['ok']) && (int) ($again['created'] ?? 1) === 0, 'andra körningen skapar ingen ny kontakt');
$majaAgain = lc_contact_named('Maja Lind');
lc(jeab_kontakt_ovrigt(is_array($majaAgain) ? $majaAgain : []) === $notesAfter, 'andra körningen lägger inte samma rad i Övrigt igen');
$noraAgain = lc_contact_named('Nora Ek');
lc(substr_count(jeab_kontakt_ovrigt(is_array($noraAgain) ? $noraAgain : []), '2023-01-01 Gamla vägen 1') === 1, 'daterad adressrad finns kvar en gång');
lc(file_get_contents(jeab_inkorg_dir() . '/contacts.json') === $inboxBefore, 'kopplingen skriver inte om inkorgens fil');

$unplaced = lc_reminder($store, 'Hemlig Unplaced', 'hemlig@post.test', '0709999999', '', '2024-09-01 09:00:00');
$ostad = lc_reminder($store, 'Ostad Kanal', 'ostad@post.test', '0701212121', '', '2024-09-02 09:00:00');
$unplacedBefore = (string) ($store->getReminder((int) $unplaced['id'])['contact_id'] ?? '');
$ostadBefore = (string) ($store->getReminder((int) $ostad['id'])['contact_id'] ?? '');
$heldContacts = count(jeab_kontakter_load());
$member = static function (array $row, string $name, string $email, string $phone): array {
    return [
        'kind' => 'reminder',
        'id' => (string) $row['id'],
        'closed' => false,
        'contact_id' => '',
        'street' => '',
        'street_key' => '',
        'postal' => '',
        'postal_invalid' => false,
        'city' => '',
        'fastighet' => '',
        'name' => $name,
        'title' => '',
        'job_type' => '',
        'email' => $email,
        'phone' => jeab_kontakt_phone($phone),
        'pnr' => '',
        'at' => '2024-09-01 09:00:00',
    ];
};
$held = jeab_kontakter_link(['dry' => 0, 'skip_conflicts' => 1], $store, [
    [
        'key' => 'loose:reminder:' . $unplaced['id'],
        'members' => [$member($unplaced, 'Hemlig Unplaced', 'hemlig@post.test', '0709999999')],
        'conflicts' => [[
            'address_key' => 'loose:reminder:' . $unplaced['id'],
            'kind' => 'unplaced',
            'name' => 'Hemlig Unplaced',
            'email' => 'hemlig@post.test',
            'source' => 'reminder:' . $unplaced['id'],
        ]],
        'needs_postnr' => false,
    ],
    [
        'key' => 'loose:reminder:' . $ostad['id'],
        'members' => [$member($ostad, 'Ostad Kanal', 'ostad@post.test', '0701212121')],
        'conflicts' => [[
            'address_key' => 'loose:reminder:' . $ostad['id'],
            'kind' => 'ambiguous_channel',
            'name' => 'Ostad Kanal',
            'email' => 'ostad@post.test',
            'source' => 'reminder:' . $ostad['id'],
            'matches' => ['saknas-helt'],
        ]],
        'needs_postnr' => false,
    ],
]);
lc(!empty($held['ok']), 'hållen körning svarar ok');
$heldActions = [];
foreach ((array) ($held['groups'] ?? []) as $group) {
    if (is_array($group)) {
        $heldActions[(string) ($group['address_key'] ?? '')] = (string) ($group['action'] ?? '');
    }
}
lc(($heldActions['loose:reminder:' . $unplaced['id']] ?? '') === 'held', 'unplaced rapporteras som held');
lc(($heldActions['loose:reminder:' . $ostad['id']] ?? '') === 'held', 'olöst ambiguous_channel rapporteras som held');
lc((string) ($store->getReminder((int) $unplaced['id'])['contact_id'] ?? '') === $unplacedBefore, 'hållen unplaced skriver inte om contact_id');
lc((string) ($store->getReminder((int) $ostad['id'])['contact_id'] ?? '') === $ostadBefore, 'hållen ambiguous_channel skriver inte om contact_id');
lc(count(jeab_kontakter_load()) === $heldContacts, 'hållen körning skapar ingen ny kontakt');
$unplacedJson = json_encode($held['unplaced'] ?? null, JSON_UNESCAPED_UNICODE);
lc(is_string($unplacedJson) && !str_contains($unplacedJson, 'Hemlig') && !str_contains($unplacedJson, 'hemlig@') && !str_contains($unplacedJson, '0709999999'), 'unplaced har inga namn eller e-post');
$unplacedRow = null;
foreach ((array) ($held['unplaced'] ?? []) as $row) {
    if (is_array($row) && (string) ($row['id'] ?? '') === (string) $unplaced['id']) {
        $unplacedRow = $row;
    }
}
lc(is_array($unplacedRow) && array_keys($unplacedRow) === ['kind', 'id', 'reason']
    && $unplacedRow['kind'] === 'reminder' && $unplacedRow['reason'] === 'unplaced', 'unplaced är kind, id och reason');

$onlyA = lc_reminder($store, 'Filter Lind', 'filter@post.test', '0703030303', 'Filtergatan 6, 111 22 Solna', '2024-01-15 09:00:00');
$onlyB = lc_reminder($store, 'Filter Lind', 'filter.andra@post.test', '0704040404', 'Filtergatan 6, 111 22 Solna', '2024-10-01 09:00:00');
lc_unlink_reminders($store, [(int) $onlyA['id'], (int) $onlyB['id']]);
$filtered = jeab_kontakter_link(['dry' => 0, 'only_kinds' => ['extra_email']], $store);
lc(!empty($filtered['ok']), 'only_kinds svarar ok');
$filterContact = lc_contact_named('Filter Lind');
$filterNotes = jeab_kontakt_ovrigt(is_array($filterContact) ? $filterContact : []);
lc(is_array($filterContact) && str_contains($filterNotes, 'E-post: filter.andra@post.test'), 'only_kinds tar med extra e-post');
lc(!str_contains($filterNotes, 'Tel:'), 'only_kinds lämnar extra telefon, fick ' . $filterNotes);
lc((string) ($store->getReminder((int) $onlyA['id'])['contact_id'] ?? '') !== ''
    && (string) ($store->getReminder((int) $onlyA['id'])['contact_id'] ?? '') === (string) ($store->getReminder((int) $onlyB['id'])['contact_id'] ?? ''), 'filtret kopplar ändå gruppen');

echo "ok\n";
