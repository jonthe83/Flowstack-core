<?php

declare(strict_types=1);

/**
 * Kundpanelen läser Kontakter. En levande Help Scout-tråd utan kundfält
 * tar contact_id från arkivet för samma konversation, eller från påminnelsen
 * när tråden själv saknar contact_id. En ifylld contact_id med e-post eller
 * adress vinner. Inget skrivs, och inget personnummer lämnar panelen.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-panel-link-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
putenv('CONTACTS_SOLE=1');
putenv('XAI_API_KEY');
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
require_once dirname(__DIR__) . '/src/layout.php';
$purchasePdo = new PDO('sqlite:' . $tmp . '/purchases.sqlite');
$purchasePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_PURCHASES_PDO'] = $purchasePdo;
jeab_purchases_store_ensure($purchasePdo);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['jeab_email'] = 'agenter@flowstack.example.test';

function lp(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
lp($store instanceof JeabPdoStore, 'sqlite-lagret finns');
$pdo = $store->pdo();
jeab_ensure_contacts_tables($pdo, 'sqlite');
jeab_ensure_contact_id_columns($pdo, 'sqlite');
lp(jeab_contacts_sole(), 'CONTACTS_SOLE är på');
$now = jeab_now();

$make = static function (string $name, string $email, string $phone, string $street, string $city): array {
    $row = jeab_kontakt_find_or_create([
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'address' => $street . ', 266 32 ' . $city,
        'city' => $city,
        'postal_code' => '26632',
    ], false);
    lp(is_array($row) && trim((string) ($row['id'] ?? '')) !== '', 'kontakt ' . $name);

    return $row;
};

$rich = $make('David Kulneff', 'david.kulneff@example.test', '0707000001', 'Onsjögatan 7', 'Munka-Ljungby');
$richId = (string) $rich['id'];
$hollow = $make('David Kulneff', 'tom.kulneff@example.test', '0707000002', 'Tomgatan 1', 'Teststad');
$hollowId = (string) $hollow['id'];
$sara = $make('Sara Stabil', 'sara.stabil@example.test', '0707000003', 'Stabilgatan 2', 'Teststad');
$saraId = (string) $sara['id'];
$secret = '19010101-2391';
jeab_kontakter_mutate(static function (array &$contacts) use ($richId, $hollowId, $secret): void {
    if (isset($contacts[$hollowId]) && is_array($contacts[$hollowId])) {
        $contacts[$hollowId]['street'] = '';
        $contacts[$hollowId]['city'] = '';
        $contacts[$hollowId]['postal_code'] = '';
        $contacts[$hollowId]['owners'] = [[
            'name' => 'David Kulneff',
            'email' => '',
            'phone' => '',
            'contact' => true,
            'personnummer' => '',
        ]];
    }
    if (isset($contacts[$richId]) && is_array($contacts[$richId])) {
        $owners = is_array($contacts[$richId]['owners'] ?? null) ? $contacts[$richId]['owners'] : [];
        if (!isset($owners[0]) || !is_array($owners[0])) {
            $owners[0] = ['name' => 'David Kulneff', 'contact' => true];
        }
        $owners[0]['personnummer'] = $secret;
        $contacts[$richId]['owners'] = $owners;
    }
});
if (function_exists('jeab_contacts_sole_cache_forget')) {
    jeab_contacts_sole_cache_forget(null);
}
$hollowRow = jeab_kontakt_get($hollowId);
lp(is_array($hollowRow) && trim((string) ($hollowRow['street'] ?? '')) === '' && jeab_kontakt_row_email($hollowRow) === '', 'namnkontakten saknar adress och e-post');

$subject = 'Solceller till villan';
$saveArchive = static function (string $digits, string $number, string $contactId) use ($subject): string {
    $id = jeab_hs_import_thread_key($digits);
    $conv = [
        'id' => $id,
        'company' => 'malmo',
        'status' => 'closed',
        'contact_id' => $contactId,
        'import_source' => 'helpscout_import',
        'hs_ids' => ['conversation' => $digits, 'number' => $number],
        'messages' => [[
            'id' => substr(hash('sha256', $id . $subject), 0, 16),
            'from' => 'david.kulneff@example.test',
            'from_name' => 'David Kulneff',
            'subject' => $subject,
            'body' => 'Hej',
            'received_at' => '2026-09-01T10:00:00+02:00',
            'source' => 'helpscout_import',
        ]],
        'meta' => [
            'status' => 'closed',
            'contact_id' => $contactId,
            'import_source' => 'helpscout_import',
            'hs_ids' => ['conversation' => $digits, 'number' => $number],
        ],
    ];
    lp(jeab_hs_import_save_conv($conv) && jeab_hs_import_index_append(jeab_hs_import_index_row($conv)), 'arkiv ' . $digits . ' sparas');
    unset($GLOBALS['_JEAB_HS_IMPORT_INDEX']);

    return $id;
};

$byNumber = $saveArchive('8001', '3459867075', $richId);
$byId = $saveArchive('111', '111', $richId);
$listed = function_exists('jeab_hs_import_threads_for_contact') ? jeab_hs_import_threads_for_contact($richId) : [];
$listedSubjects = [];
foreach ($listed as $case) {
    if (is_array($case)) {
        $listedSubjects[] = (string) ($case['subject'] ?? '');
    }
}
lp(in_array($subject, $listedSubjects, true), 'kontakten listar arkivtråden med samma ämne');

$customer = jeab_find_or_create_customer($store, 'David Kulneff');
$reminder = $store->insertReminder([
    'title' => 'Kort',
    'customer_name' => 'Kopia',
    'email' => 'kopia@example.test',
    'phone' => '0707000099',
    'address' => 'Kopiagatan 9',
    'ort' => 'Felort',
    'company' => 'malmo',
    'thread_id' => 'hs-222',
    'due_at' => '2026-10-24 09:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
lp(jeab_kontakt_stamp_reminder($store, (int) $reminder['id'], $richId), 'påminnelsen länkas');
$booking = $store->insertBooking([
    'customer_id' => $customer['id'],
    'customer_name' => 'Kopia Bokning',
    'email' => 'bokning@example.test',
    'phone' => '0707000088',
    'address' => 'Bokningsgatan 3',
    'assignees' => ['Elin'],
    'company' => 'malmo',
    'job_type' => 'solceller',
    'starts_at' => '2026-11-02 09:00:00',
    'ends_at' => '2026-11-02 10:00:00',
    'status' => 'booked',
    'created_at' => $now,
    'updated_at' => $now,
]);
$pdo->prepare('UPDATE bookings SET contact_id = ? WHERE id = ?')->execute([$richId, (int) $booking['id']]);
$orderReminder = $store->insertReminder([
    'title' => 'Order',
    'customer_name' => 'Kopia Order',
    'email' => 'order@example.test',
    'company' => 'malmo',
    'due_at' => '2026-10-24 09:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
jeab_purchase_put([
    'reminder_id' => (int) $orderReminder['id'],
    'kind' => 'solceller',
    'name' => 'Kopia Order',
    'contact_id' => $richId,
    'created_at' => $now,
    'updated_at' => $now,
]);
$store->saveReminder((int) $orderReminder['id'], ['contact_id' => $richId]);
$beforeContacts = count(jeab_kontakter_load());
$beforeReminder = (string) ($store->getReminder((int) $reminder['id'])['contact_id'] ?? '');
$beforeOrder = (string) (jeab_purchase_get((int) $orderReminder['id'])['contact_id'] ?? '');
$beforeBooking = (string) ($store->getBooking((int) $booking['id'])['contact_id'] ?? '');
lp($beforeReminder === $richId && $beforeOrder === $richId && $beforeBooking === $richId, 'order, bokning och påminnelse pekar på kontakten');

$open = static function (string $id, string $contactId): array {
    return [
        'id' => $id,
        'contact_id' => $contactId,
        'company' => 'malmo',
        'brand' => 'malmo',
        'customer_name' => 'David Kulneff',
        'customer_email' => '',
        'messages' => [[
            'from' => '',
            'from_name' => 'David Kulneff',
            'subject' => 'Solceller till villan',
            'body' => 'Hej, jag vill ha solceller.',
            'received_at' => '2026-10-01T10:00:00+02:00',
            'source' => 'helpscout-live',
            'company' => 'malmo',
        ]],
    ];
};

$show = static function (array $thread) use ($secret): array {
    $panel = jeab_inkorg_panel_contact($thread);
    $place = jeab_inkorg_panel_place($thread, is_array($panel) ? $panel : null);
    $box = jeab_inkorg_contact_box($thread, is_array($panel) ? $panel : null, [
        'name' => 'David Kulneff',
        'email' => '',
        'phone' => '',
    ]);
    $blob = (string) json_encode([$panel, $place, $box], JSON_UNESCAPED_UNICODE);
    lp(!str_contains($blob, $secret) && !str_contains($blob, 'personnummer'), 'panelen innehåller inte personnummer');

    return [
        'panel' => is_array($panel) ? $panel : [],
        'place' => $place,
        'box' => $box,
    ];
};

$numberThread = $open('hs-3459867075', '');
$numberShown = $show($numberThread);
lp((string) ($numberShown['panel']['contact_id'] ?? '') === $richId, 'tråden utan contact_id tar arkivets kontakt via ärendenumret');
lp(in_array('david.kulneff@example.test', (array) ($numberShown['box']['emails'] ?? []), true), 'panelen visar kontaktens e-post');
$numberPhone = (string) ($numberShown['box']['phone'] ?? '');
lp($numberPhone !== '' && str_contains($numberPhone, '707000001'), 'panelen visar kontaktens telefon');
lp(str_contains((string) ($numberShown['place']['address'] ?? ''), 'Onsjögatan 7') && str_contains((string) ($numberShown['place']['address'] ?? ''), 'Munka-Ljungby'), 'panelen visar kontaktens adress');
lp((string) ($numberThread['contact_id'] ?? 'x') === '', 'visningen skriver inte contact_id på tråden');

$hollowThread = $open('hs-111', $hollowId);
$hollowShown = $show($hollowThread);
lp((string) ($hollowShown['panel']['contact_id'] ?? '') === $richId, 'en namnkontakt lämnar plats åt arkivets ifyllda kontakt');
lp(in_array('david.kulneff@example.test', (array) ($hollowShown['box']['emails'] ?? []), true), 'namnkontakten döljer inte e-posten');
lp(is_array(jeab_kontakt_get($hollowId)), 'namnkontakten finns kvar');

$keptThread = $open('hs-111', $saraId);
$keptShown = $show($keptThread);
lp((string) ($keptShown['panel']['contact_id'] ?? '') === $saraId && in_array('sara.stabil@example.test', (array) ($keptShown['box']['emails'] ?? []), true), 'en ifylld contact_id på tråden vinner över arkivet');
lp(str_contains((string) ($keptShown['place']['address'] ?? ''), 'Stabilgatan 2'), 'adressen kommer från trådens egen kontakt');

$reminderThread = $open('hs-222', '');
$reminderShown = $show($reminderThread);
lp((string) ($reminderShown['panel']['contact_id'] ?? '') === $richId && str_contains((string) ($reminderShown['box']['phone'] ?? ''), '707000001'), 'utan arkiv läser panelen påminnelsens kontakt');

lp(count(jeab_kontakter_load()) === $beforeContacts, 'ingen kontakt skapas eller slås ihop');
lp((string) ($store->getReminder((int) $reminder['id'])['contact_id'] ?? '') === $beforeReminder, 'påminnelsens länk består');
lp((string) (jeab_purchase_get((int) $orderReminder['id'])['contact_id'] ?? '') === $beforeOrder, 'orderns länk består');
lp((string) ($store->getBooking((int) $booking['id'])['contact_id'] ?? '') === $beforeBooking, 'bokningens länk består');
$indexRow = jeab_hs_import_index()['by_id'][$byNumber] ?? null;
lp(is_array($indexRow) && (string) ($indexRow['contact_id'] ?? '') === $richId, 'arkivets contact_id består');
unset($byId);

echo "OK\n";
