<?php

declare(strict_types=1);

/**
 * Steg 3: inflöden skriver kunddata till kontakten när contacts_sole är på.
 * Flaggan av skapar och ändrar ingen kontakt. Påhittade rader. Inga personnummer.
 */

$tmp = sys_get_temp_dir() . '/jeab-sole-inflow-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
$GLOBALS['_JEAB_ADDRESS_EXTRACT_NETWORK'] = false;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
putenv('CONTACTS_SOLE=0');
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
require_once dirname(__DIR__) . '/src/address_lookup.php';
require_once dirname(__DIR__) . '/src/manuell_order.php';

function inf(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function inf_count(): int
{
    $rows = function_exists('jeab_kontakter_load') ? jeab_kontakter_load() : [];

    return is_array($rows) ? count($rows) : 0;
}

function inf_property_rows(PDO $pdo): int
{
    $name = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'contacts_sole_undo'")->fetchColumn();
    if ($name !== 'contacts_sole_undo') {
        return 0;
    }

    return (int) $pdo->query("SELECT COUNT(*) FROM contacts_sole_undo WHERE kind = 'property'")->fetchColumn();
}

function inf_owner_pnr(?array $row): string
{
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $pnr = (string) ($owner['personnummer'] ?? '');
        if (trim($pnr) !== '') {
            return $pnr;
        }
    }

    return '';
}

function inf_pnr_leak(string $json, string $plain): bool
{
    if ($plain !== '' && str_contains($json, $plain)) {
        return true;
    }

    return str_contains($json, 'personnummer') || str_contains($json, 'SocialSecurityNumber');
}

function inf_error_blob(PDO $pdo): string
{
    $blob = '';
    $path = function_exists('jeab_app_errors_path') ? jeab_app_errors_path() : '';
    if ($path !== '' && is_file($path)) {
        $blob .= (string) file_get_contents($path);
    }
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        if (!is_string($table) || preg_match('/\A[A-Za-z0-9_]+\z/', $table) !== 1) {
            continue;
        }
        $cols = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($cols)) {
            continue;
        }
        foreach ($cols as $col) {
            if (!is_array($col)) {
                continue;
            }
            $column = (string) ($col['name'] ?? '');
            $type = strtolower((string) ($col['type'] ?? ''));
            if ($column === '' || preg_match('/\A[A-Za-z0-9_]+\z/', $column) !== 1) {
                continue;
            }
            if ($type !== '' && !str_contains($type, 'char') && !str_contains($type, 'text') && !str_contains($type, 'clob')) {
                continue;
            }
            try {
                $values = $pdo->query('SELECT ' . $column . ' FROM ' . $table)->fetchAll(PDO::FETCH_COLUMN);
            } catch (Throwable) {
                continue;
            }
            foreach ($values as $value) {
                if (is_string($value) && str_contains($value, 'contacts_sole.')) {
                    $blob .= "\n" . $value;
                }
            }
        }
    }

    return $blob;
}

/** @return list<array<string, mixed>> */
function inf_sole_logs(PDO $pdo): array
{
    $rows = [];
    foreach (preg_split("/\n/", inf_error_blob($pdo)) ?: [] as $line) {
        $line = trim((string) $line);
        if ($line === '' || !str_contains($line, 'contacts_sole.')) {
            continue;
        }
        $decoded = json_decode($line, true);
        if (!is_array($decoded) && str_starts_with($line, '{')) {
            continue;
        }
        if (is_array($decoded) && is_string($decoded['payload'] ?? null) && !isset($decoded['surface'])) {
            $inner = json_decode($decoded['payload'], true);
            $decoded = is_array($inner) ? $inner : $decoded;
        }
        if (!is_array($decoded)) {
            $start = strpos($line, '{');
            $decoded = $start === false ? null : json_decode(substr($line, $start), true);
        }
        if (is_array($decoded) && str_starts_with((string) ($decoded['surface'] ?? ''), 'contacts_sole.')) {
            $rows[] = $decoded;
        }
    }

    return $rows;
}

final class InfThrowStore implements JeabStore
{
    public function listReminders(bool $includeCompleted = true): array
    {
        return [];
    }

    public function getReminder(int $id): ?array
    {
        throw new RuntimeException('LEAKMARKER-LINK');
    }

    public function insertReminder(array $data): array
    {
        return [];
    }

    public function saveReminder(int $id, array $fields): array
    {
        return [];
    }

    public function incrementReminderTap(int $id, string $field): array
    {
        return [];
    }

    public function deleteReminders(array $ids): int
    {
        return 0;
    }

    public function findOpenReminderByBooking(int $bookingId): ?array
    {
        return null;
    }

    public function findCustomerByName(string $name): ?array
    {
        return null;
    }

    public function insertCustomer(string $name, string $createdAt): array
    {
        return [];
    }

    public function listCustomers(): array
    {
        return [];
    }

    public function recentAssignees(int $limit): array
    {
        return [];
    }

    public function getBooking(int $id): ?array
    {
        return null;
    }

    public function bookingsBetween(string $from, string $to): array
    {
        return [];
    }

    public function bookingsCreatedBetween(string $from, string $to): array
    {
        return [];
    }

    public function bookingsUpdatedBetween(string $from, string $to): array
    {
        return [];
    }

    public function listAllBookings(): array
    {
        return [];
    }

    public function insertBooking(array $data): array
    {
        return [];
    }

    public function saveBooking(int $id, array $fields): array
    {
        return [];
    }

    public function setting(string $key, ?string $default = null): ?string
    {
        return $default;
    }

    public function setSetting(string $key, string $value): void
    {
    }
}

$store = jeab_store();
inf($store instanceof JeabPdoStore, 'sqlite-lagret finns');
$pdo = $store->pdo();
jeab_ensure_contacts_tables($pdo, 'sqlite');
jeab_ensure_contact_id_columns($pdo, 'sqlite');
jeab_purchases_store_ensure($pdo);

$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
$now = jeab_now();

$beforePhone = '+46701000301';
$afterPhone = '+46701000302';
$made = jeab_contacts_sole_inflow_upsert([
    'name' => 'Dina Divert',
    'email' => 'dina.divert@example.test',
    'phone' => $beforePhone,
    'address' => 'Gamlagatan 1',
    'city' => 'Teststad',
    'postal_code' => '11122',
    'company' => 'jarfalla',
]);
inf(is_array($made) && trim((string) ($made['id'] ?? '')) !== '', 'divert-kontakten skapas');
$contactId = (string) $made['id'];
$reminder = $store->insertReminder([
    'title' => 'Divert-test',
    'customer_name' => 'Dina Divert',
    'email' => 'dina.divert@example.test',
    'phone' => $beforePhone,
    'address' => 'Gamlagatan 1',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 09:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$reminderId = (int) $reminder['id'];
jeab_contacts_sole_inflow_link($store, $reminderId, $contactId, false);
jeab_purchase_put([
    'reminder_id' => $reminderId,
    'kind' => 'laddbox',
    'name' => 'Dina Divert',
    'huvudsokande' => 'Dina Divert',
    'email' => 'dina.divert@example.test',
    'phone' => $beforePhone,
    'address' => 'Gamlagatan 1',
    'gata' => 'Gamlagatan 1',
    'ort' => 'Teststad',
    'postnummer' => '11122',
    'contact_id' => $contactId,
    'company' => 'jarfalla',
    'created_at' => $now,
    'updated_at' => $now,
]);
$before = jeab_purchase_get($reminderId);
inf(is_array($before), 'ordern finns');
$after = $before;
$after['phone'] = $afterPhone;
$after['email'] = 'dina.efter@example.test';
$after['address'] = 'Nygatan 9, 177 60 Testort';
$after['gata'] = 'Nygatan 9';
$after['ort'] = 'Testort';
$after['postnummer'] = '17760';
$returned = jeab_contacts_sole_divert_order($before, $after);
$disk = jeab_purchase_get($reminderId);
$contact = jeab_kontakt_get($contactId);
inf(is_array($disk) && trim((string) ($disk['phone'] ?? '')) === '' && trim((string) ($disk['email'] ?? '')) === '', 'divert skriver inte telefon eller e-post på ordern');
inf((string) ($disk['contact_id'] ?? '') === $contactId, 'divert lämnar contact_id');
inf(trim((string) ($returned['phone'] ?? '')) === '' && trim((string) ($returned['email'] ?? '')) === '', 'divert lämnar inte kvar orderns kopia');
inf(is_array($contact) && jeab_kontakt_row_phone($contact) === $afterPhone, 'divert skriver telefonen på kontakten');
inf(jeab_kontakt_row_email($contact) === 'dina.efter@example.test', 'divert skriver e-posten på kontakten');

$lead = jeab_lead_sole_upsert([
    'name' => 'Lea Lead',
    'email' => 'lea.lead@example.test',
    'phone' => '0701000101',
    'address' => 'Leadgatan 12, 177 60 Järfälla',
    'company' => 'jarfalla',
]);
inf(is_array($lead), 'lead skapar kontakt');
$leadRow = jeab_kontakt_get((string) $lead['id']);
inf(is_array($leadRow), 'lead-kontakten går att läsa');
inf(trim((string) ($leadRow['street'] ?? '')) === 'Leadgatan 12', 'lead-adressen delas i gata');
$leadPostal = function_exists('jeab_kontakt_postal') ? jeab_kontakt_postal('177 60') : '17760';
inf((string) ($leadRow['postal_code'] ?? '') === $leadPostal, 'lead-adressen delas i postnr');
inf(mb_strtolower((string) ($leadRow['city'] ?? '')) === mb_strtolower('Järfälla'), 'lead-adressen delas i ort');
inf(jeab_kontakt_row_phone($leadRow) === jeab_kontakt_phone('0701000101'), 'lead behåller telefonen');

$form = jeab_form_sender_apply([
    'from' => 'info@wix-forms.com',
    'from_name' => 'Wix',
    'body' => "Namn: Lea Form\nE-post: lea.form@example.test\nTelefon: 0701000106\nAdress: Formgatan 6, 177 60 Järfälla\n",
    'company' => 'jarfalla',
]);
inf((string) ($form['from'] ?? '') === 'lea.form@example.test', 'formuläret byter avsändare till kunden');
inf((string) ($form['phone'] ?? '') === '0701000106', 'formuläret behåller telefonen');
$formContact = jeab_contacts_sole_party_id('lea.form@example.test', '0701000106');
$formRow = $formContact !== '' ? jeab_kontakt_get($formContact) : null;
inf(is_array($formRow) && trim((string) ($formRow['street'] ?? '')) === 'Formgatan 6', 'formulärets adress delas i gata');
inf(is_array($formRow) && jeab_kontakt_row_phone($formRow) === jeab_kontakt_phone('0701000106'), 'formulärets telefon ligger på kontakten');

$shop = [
    'number' => '91001',
    'id' => 'inflow-shop',
    'createdDate' => '2026-10-14T11:04:35.419Z',
    'status' => 'APPROVED',
    'paymentStatus' => 'PAID',
    'priceSummary' => ['total' => ['amount' => '100.00']],
    'buyerInfo' => ['email' => 'webb.kund@example.test'],
    'billingInfo' => [
        'address' => ['addressLine' => 'Webbgatan 4', 'postalCode' => '177 60', 'city' => 'Järfälla'],
        'contactDetails' => ['firstName' => 'Webb', 'lastName' => 'Kund', 'phone' => '0701000104'],
    ],
    'lineItems' => [[
        'productName' => ['original' => 'Easee laddbox'],
        'quantity' => 1,
        'price' => ['amount' => '100.00'],
        'physicalProperties' => ['sku' => ''],
    ]],
];
$GLOBALS['_JEAB_LOG_PDO'] = new PDO('sqlite::memory:');
$GLOBALS['_JEAB_LOG_PDO']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
jeab_log_pdo();
jeab_log_flag_set($GLOBALS['_JEAB_LOG_PDO'], 'webshop_orders');
$ing = jeab_webshop_ingest($store, ['order' => $shop, 'company' => 'jarfalla']);
unset($GLOBALS['_JEAB_LOG_PDO']);
inf(!empty($ing['ok']) && (int) ($ing['reminder_id'] ?? 0) > 0, 'webshop skapar order');
$shopId = (int) $ing['reminder_id'];
$shopOrder = jeab_purchase_get($shopId);
inf(is_array($shopOrder) && trim((string) ($shopOrder['contact_id'] ?? '')) !== '', 'webshop-ordern får contact_id');
$shopContactId = is_array($shopOrder) ? trim((string) ($shopOrder['contact_id'] ?? '')) : '';
$shopContact = $shopContactId !== '' ? jeab_kontakt_get($shopContactId) : null;
inf(is_array($shopOrder) && trim((string) ($shopOrder['email'] ?? '')) === '', 'webshop lagrar inte e-post på ordern');
inf(is_array($shopContact) && jeab_kontakt_row_email($shopContact) === 'webb.kund@example.test', 'webshop lägger e-posten på kontakten');

$manualReminder = $store->insertReminder([
    'title' => 'Manuell',
    'customer_name' => 'Maja Manuell',
    'email' => 'maja.manuell@example.test',
    'phone' => '0701000105',
    'address' => 'Manuellgatan 5',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 10:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$manualId = (int) $manualReminder['id'];
jeab_purchase_put([
    'reminder_id' => $manualId,
    'kind' => 'laddbox',
    'name' => 'Maja Manuell',
    'email' => 'maja.manuell@example.test',
    'phone' => '0701000105',
    'address' => 'Manuellgatan 5, 177 60 Järfälla',
    'company' => 'jarfalla',
    'created_at' => $now,
    'updated_at' => $now,
]);
$manual = jeab_manuell_order_sole_contact([
    'owner_name' => ['Maja Manuell', 'Mio Manuell'],
    'owner_pnr' => ['', ''],
    'owner_address' => ['Manuellgatan 5', 'Manuellgatan 5'],
    'owner_role' => ['huvudsokande', 'medsokande'],
    'email' => 'maja.manuell@example.test',
    'phone' => '0701000105',
    'address' => 'Manuellgatan 5, 177 60 Järfälla',
    'fastighetsbeteckning' => 'TEST 1:5',
    'co' => 'jarfalla',
], $store, $manualId);
inf(is_array($manual) && trim((string) ($manual['id'] ?? '')) !== '', 'manuell order skapar kontakt');
$manualOrder = jeab_purchase_get($manualId);
inf(is_array($manualOrder) && (string) ($manualOrder['contact_id'] ?? '') === (string) $manual['id'], 'manuell order får contact_id');
$manualRow = jeab_kontakt_get((string) $manual['id']);
$ownerNames = [];
foreach (is_array($manualRow['owners'] ?? null) ? $manualRow['owners'] : [] as $owner) {
    if (is_array($owner)) {
        $ownerNames[] = (string) ($owner['name'] ?? '');
    }
}
inf(in_array('Maja Manuell', $ownerNames, true) && in_array('Mio Manuell', $ownerNames, true), 'manuell order sparar alla ägare på kontakten');
inf((string) ($manualRow['fastighetsbeteckning'] ?? '') === 'TEST 1:5', 'manuell order sparar fastighetsbeteckning på kontakten');

$undoMade = jeab_contacts_sole_inflow_upsert([
    'name' => 'Olga Undo',
    'email' => 'olga.undo@example.test',
    'phone' => '0701000201',
    'address' => 'Undogatan 1, 177 60 Järfälla',
    'company' => 'jarfalla',
]);
inf(is_array($undoMade) && trim((string) ($undoMade['id'] ?? '')) !== '', 'undo-kontakten skapas');
$undoId = (string) $undoMade['id'];
inf(jeab_fastighet_write_contact($undoId, [
    'id' => $undoId,
    'fastighetsbeteckning' => 'GAMLA 1:1',
    'owners' => [[
        'name' => 'Olga Undo',
        'contact' => true,
        'email' => 'olga.undo@example.test',
        'phone' => jeab_kontakt_phone('0701000201'),
    ]],
]), 'före-bilden går att sätta');
$undoReminder = $store->insertReminder([
    'title' => 'Undo',
    'customer_name' => 'Olga Undo',
    'email' => 'olga.undo@example.test',
    'phone' => '0701000201',
    'address' => 'Hemliggatan 4, 177 60 Järfälla',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 11:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$undoReminderId = (int) $undoReminder['id'];
jeab_purchase_put([
    'reminder_id' => $undoReminderId,
    'kind' => 'laddbox',
    'name' => 'Olga Undo',
    'email' => 'olga.undo@example.test',
    'phone' => '0701000201',
    'address' => 'Hemliggatan 4, 177 60 Järfälla',
    'contact_id' => $undoId,
    'company' => 'jarfalla',
    'fastighetsbeteckning' => 'NYA 9:9',
    'owners' => [['name' => 'Nils Ny', 'personnummer' => '']],
    'created_at' => $now,
    'updated_at' => $now,
]);
jeab_contacts_sole_divert_property([
    'contact_id' => $undoId,
    'reminder_id' => $undoReminderId,
    'name' => 'Olga Undo',
    'email' => 'olga.undo@example.test',
    'phone' => '0701000201',
    'address' => 'Hemliggatan 4, 177 60 Järfälla',
    'fastighetsbeteckning' => 'NYA 9:9',
    'owners' => [['name' => 'Nils Ny', 'personnummer' => '']],
    'company' => 'jarfalla',
]);
$changed = jeab_kontakt_get($undoId);
$changedNames = [];
foreach (is_array($changed['owners'] ?? null) ? $changed['owners'] : [] as $owner) {
    if (is_array($owner)) {
        $changedNames[] = (string) ($owner['name'] ?? '');
    }
}
inf(is_array($changed) && (string) ($changed['fastighetsbeteckning'] ?? '') === 'NYA 9:9', 'divert skriver ny fastighetsbeteckning');
inf(in_array('Nils Ny', $changedNames, true) && !in_array('Olga Undo', $changedNames, true), 'divert skriver nya ägare');
$undoLog = $pdo->prepare('SELECT batch_id, before_json, after_json FROM contacts_sole_undo WHERE kind = ? AND entity_id = ? ORDER BY id DESC LIMIT 1');
$undoLog->execute(['property', $undoId]);
$undoRow = $undoLog->fetch(PDO::FETCH_ASSOC);
inf(is_array($undoRow), 'undo-raden ligger i contacts_sole_undo');
$beforeJson = (string) ($undoRow['before_json'] ?? '');
$afterJson = (string) ($undoRow['after_json'] ?? '');
inf(str_contains($beforeJson, 'Olga Undo') && str_contains($beforeJson, 'GAMLA 1:1'), 'undo-raden har gamla ägare och fastighetsbeteckning');
inf(!str_contains($beforeJson . $afterJson, 'personnummer'), 'undo-raden innehåller inte personnummer');
$undoBatch = (string) ($undoRow['batch_id'] ?? '');
$orderBeforeUndo = jeab_purchase_get($undoReminderId);
$undoDry = jeab_contacts_sole_undo(['batch_id' => $undoBatch], $store);
inf(!empty($undoDry['ok']) && !empty($undoDry['dry']) && empty($undoDry['written']) && (int) ($undoDry['properties'] ?? 0) === 1, 'ånger av fastighet är torr som standard');
$stillNew = jeab_kontakt_get($undoId);
inf(is_array($stillNew) && (string) ($stillNew['fastighetsbeteckning'] ?? '') === 'NYA 9:9', 'torr ånger skriver inte');
$undoDone = jeab_contacts_sole_undo(['dry' => 0, 'batch_id' => $undoBatch], $store);
inf(!empty($undoDone['ok']) && !empty($undoDone['written']) && (int) ($undoDone['properties'] ?? 0) === 1, 'ångern återställer fastigheten');
$restored = jeab_kontakt_get($undoId);
$restoredNames = [];
foreach (is_array($restored['owners'] ?? null) ? $restored['owners'] : [] as $owner) {
    if (is_array($owner)) {
        $restoredNames[] = (string) ($owner['name'] ?? '');
    }
}
inf(is_array($restored) && (string) ($restored['fastighetsbeteckning'] ?? '') === 'GAMLA 1:1', 'ångern lägger tillbaka fastighetsbeteckningen');
inf(in_array('Olga Undo', $restoredNames, true) && !in_array('Nils Ny', $restoredNames, true), 'ångern lägger tillbaka ägarna');
$orderStill = jeab_purchase_get($undoReminderId);
inf(is_array($orderStill) && $orderStill == $orderBeforeUndo, 'ångern rör inte ordern');
inf((string) ($orderStill['contact_id'] ?? '') === $undoId && trim((string) ($orderStill['address'] ?? '')) === '', 'ordern pekar på kontakten och lagrar inte adressen');
inf((string) ($orderStill['fastighetsbeteckning'] ?? '') === 'NYA 9:9', 'orderns fastighetsbeteckning består');

$wouldBase = jeab_contacts_sole_property_would_create($store);
$wouldContacts = inf_count();
$nora = [
    'name' => 'Nora Ny',
    'email' => 'nora.ny@example.test',
    'phone' => '0701000203',
    'address' => 'Noragatan 3, 177 60 Järfälla',
];
foreach (['Nora ett', 'Nora tva'] as $title) {
    $noraReminder = $store->insertReminder([
        'title' => $title,
        'customer_name' => 'Nora Ny',
        'email' => 'nora.ny@example.test',
        'phone' => '0701000203',
        'address' => 'Noragatan 3, 177 60 Järfälla',
        'company' => 'jarfalla',
        'due_at' => '2026-10-24 12:00:00',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    jeab_purchase_put([
        'reminder_id' => (int) $noraReminder['id'],
        'kind' => 'laddbox',
        'name' => 'Nora Ny',
        'huvudsokande' => 'Nora Ny',
        'email' => 'nora.ny@example.test',
        'phone' => '0701000203',
        'address' => 'Noragatan 3, 177 60 Järfälla',
        'company' => 'jarfalla',
        'created_at' => $now,
        'updated_at' => $now,
    ] + $nora);
}
$olgaOpen = $store->insertReminder([
    'title' => 'Olga utan länk',
    'customer_name' => 'Olga Undo',
    'email' => 'olga.undo@example.test',
    'phone' => '0701000201',
    'address' => 'Undogatan 1, 177 60 Järfälla',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 13:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
jeab_purchase_put([
    'reminder_id' => (int) $olgaOpen['id'],
    'kind' => 'laddbox',
    'name' => 'Olga Undo',
    'huvudsokande' => 'Olga Undo',
    'email' => 'olga.undo@example.test',
    'phone' => '0701000201',
    'address' => 'Undogatan 1, 177 60 Järfälla',
    'company' => 'jarfalla',
    'created_at' => $now,
    'updated_at' => $now,
]);
$boCustomer = jeab_find_or_create_customer($store, 'Bo Bokning');
$store->insertBooking([
    'customer_id' => $boCustomer['id'],
    'customer_name' => 'Bo Bokning',
    'email' => 'bo.bokning@example.test',
    'phone' => '0701000202',
    'address' => 'Bokgatan 2, 177 60 Järfälla',
    'notes' => 'ny bokning',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => '2026-11-03 09:00:00',
    'ends_at' => '2026-11-03 10:00:00',
    'status' => 'booked',
    'created_at' => $now,
    'updated_at' => $now,
]);
$linkedCustomer = jeab_find_or_create_customer($store, 'Redan Lankad');
$linkedBooking = $store->insertBooking([
    'customer_id' => $linkedCustomer['id'],
    'customer_name' => 'Redan Lankad',
    'email' => 'redan.lankad@example.test',
    'phone' => '0701000204',
    'address' => 'Lankgatan 8, 177 60 Järfälla',
    'notes' => 'har kontakt',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => '2026-11-03 11:00:00',
    'ends_at' => '2026-11-03 12:00:00',
    'status' => 'booked',
    'created_at' => $now,
    'updated_at' => $now,
]);
$pdo->prepare('UPDATE bookings SET contact_id = ? WHERE id = ?')->execute([$undoId, (int) ($linkedBooking['id'] ?? 0)]);
$would = jeab_contacts_sole_property_would_create($store);
inf(inf_count() === $wouldContacts, 'dry-räkningen skapar ingen kontakt');
inf((int) $would['orders'] === (int) $wouldBase['orders'] + 1, 'två ordrar med samma person räknas som en ny kontakt');
inf((int) $would['bookings'] === (int) $wouldBase['bookings'] + 1, 'bokning utan contact_id räknas, länkad bokning gör det inte');
inf((int) $would['contacts'] === (int) $wouldBase['contacts'] + 2, 'befintlig träff skapar ingen kontakt');
$wouldJson = json_encode($would);
inf(is_string($wouldJson) && !str_contains($wouldJson, '@') && !str_contains($wouldJson, 'Nora') && !str_contains($wouldJson, '070'), 'dry-räkningen är bara räknare');

$_POST['action'] = 'ordrar.save';
$_POST['id'] = (string) $undoReminderId;
$_GET['p'] = 'ordrar';
$errorsBefore = jeab_contacts_sole_inflow_error_count();
$pdo->exec("CREATE TRIGGER sole_undo_fail BEFORE INSERT ON contacts_sole_undo BEGIN SELECT RAISE(ABORT, 'LEAKMARKER-SOLE'); END");
jeab_contacts_sole_divert_property([
    'contact_id' => $undoId,
    'reminder_id' => $undoReminderId,
    'fastighetsbeteckning' => 'FEL 4:4',
    'owners' => [['name' => 'Nils Ny', 'personnummer' => '']],
]);
$afterFail = jeab_kontakt_get($undoId);
inf(is_array($afterFail) && (string) ($afterFail['fastighetsbeteckning'] ?? '') === 'GAMLA 1:1', 'misslyckad undo skriver inte över kontakten');
$pdo->exec('DROP TRIGGER IF EXISTS sole_undo_fail');
jeab_contacts_sole_inflow_link(new InfThrowStore(), 424242, $undoId, true);
unset($_POST['action'], $_POST['id'], $_GET['p']);
$logs = inf_sole_logs($pdo);
$sawProperty = false;
$sawLink = false;
foreach ($logs as $log) {
    $message = (string) ($log['message'] ?? '');
    $surface = (string) ($log['surface'] ?? '');
    $ref = (string) ($log['ref'] ?? '');
    if ($surface === 'contacts_sole.divert_property' && $ref === (string) $undoReminderId && $message === 'PDOException') {
        $sawProperty = true;
    }
    if ($surface === 'contacts_sole.inflow_link' && $ref === '424242' && $message === 'RuntimeException') {
        $sawLink = true;
    }
    inf(!str_contains($message, 'LEAKMARKER') && !str_contains(json_encode($log), 'LEAKMARKER'), 'felloggen har ingen feltext');
    inf(!str_contains(json_encode($log), 'Hemliggatan') && !str_contains(json_encode($log), 'olga.undo'), 'felloggen har ingen kunddata');
}
inf($sawProperty, 'divert_property loggar id och felklass');
inf($sawLink, 'inflow_link loggar id och felklass');
inf(jeab_contacts_sole_inflow_error_count() === $errorsBefore + 2, 'felen räknas utan att inflödet kraschar');
$verify = jeab_contacts_sole_verify($store);
inf((int) ($verify['inflow_errors'] ?? -1) === $errorsBefore + 2, 'sole_verify räknar inflödesfelen');
inf(($verify['property_would_create'] ?? null) === $would, 'sole_verify har samma torräkning');
$verifyWould = json_encode($verify['property_would_create'] ?? null);
inf(is_string($verifyWould) && !str_contains($verifyWould, '@') && !str_contains((string) json_encode($verify['inflow_errors']), 'LEAKMARKER'), 'sole_verify lämnar bara räknare');

$dryBefore = inf_count();
$dry = jeab_contacts_sole_mirror_inbox('c-inflow', [
    'id' => 'c-inflow',
    'name' => 'Kim Inkorg',
    'emails' => ['kim.inkorg@example.test'],
    'mobile' => '0701000107',
    'address' => 'Inkorgsgatan 7',
    'town' => 'Järfälla',
], true);
inf((int) ($dry['would'] ?? 0) === 1 && (int) ($dry['written'] ?? 0) === 0 && inf_count() === $dryBefore, 'inkorgs-spegling dry skriver inget');

$veraMade = jeab_contacts_sole_inflow_upsert([
    'name' => 'Vera Vill',
    'email' => 'vera.vill@example.test',
    'phone' => '0701000208',
    'address' => 'Veragatan 8, 177 60 Järfälla',
    'company' => 'jarfalla',
]);
inf(is_array($veraMade) && trim((string) ($veraMade['id'] ?? '')) !== '', 'vera-kontakten skapas');
$veraId = (string) $veraMade['id'];
inf(jeab_fastighet_write_contact($veraId, [
    'id' => $veraId,
    'fastighetsbeteckning' => 'VERA 1:1',
    'owners' => [[
        'name' => 'Vera Vill',
        'personnummer' => '901340-0001',
        'contact' => true,
        'email' => 'vera.vill@example.test',
        'phone' => jeab_kontakt_phone('0701000208'),
    ]],
]), 'vera får ägare före ändringen');
jeab_contacts_sole_divert_property([
    'contact_id' => $veraId,
    'name' => 'Vera Vill',
    'email' => 'vera.vill@example.test',
    'phone' => '0701000208',
    'fastighetsbeteckning' => 'VERA 9:9',
    'owners' => [['name' => 'vera  vill', 'personnummer' => '9013400001']],
    'company' => 'jarfalla',
]);
$veraMid = jeab_kontakt_get($veraId);
$veraMidPnr = '';
foreach (is_array($veraMid['owners'] ?? null) ? $veraMid['owners'] : [] as $owner) {
    if (!is_array($owner)) {
        continue;
    }
    $veraMidPnr = (string) ($owner['personnummer'] ?? '');
}
inf(is_array($veraMid) && (string) ($veraMid['fastighetsbeteckning'] ?? '') === 'VERA 9:9', 'divert byter veras fastighetsbeteckning');
inf(jeab_contacts_sole_pnr_key($veraMidPnr) === jeab_contacts_sole_pnr_key('901340-0001') && jeab_contacts_sole_pnr_key($veraMidPnr) === jeab_contacts_sole_pnr_key('199013400001'), 'divert skriver numret normaliserat');
$veraLog = $pdo->prepare('SELECT batch_id, before_json, after_json FROM contacts_sole_undo WHERE kind = ? AND entity_id = ? ORDER BY id DESC LIMIT 1');
$veraLog->execute(['property', $veraId]);
$veraUndo = $veraLog->fetch(PDO::FETCH_ASSOC);
inf(is_array($veraUndo), 'veras undo-rad finns');
$veraJson = (string) ($veraUndo['before_json'] ?? '') . (string) ($veraUndo['after_json'] ?? '');
inf(!str_contains($veraJson, 'personnummer') && !str_contains($veraJson, '901340') && !str_contains($veraJson, '19901340'), 'veras undo-rad saknar personnummer');
$veraDone = jeab_contacts_sole_undo(['dry' => 0, 'batch_id' => (string) ($veraUndo['batch_id'] ?? '')], $store);
inf(!empty($veraDone['ok']) && !empty($veraDone['written']) && (int) ($veraDone['properties'] ?? 0) === 1, 'ångern av vera skriver');
$veraBack = jeab_kontakt_get($veraId);
$veraBackPnr = '';
$veraBackName = '';
foreach (is_array($veraBack['owners'] ?? null) ? $veraBack['owners'] : [] as $owner) {
    if (!is_array($owner)) {
        continue;
    }
    $veraBackName = (string) ($owner['name'] ?? '');
    $veraBackPnr = (string) ($owner['personnummer'] ?? '');
}
inf(is_array($veraBack) && (string) ($veraBack['fastighetsbeteckning'] ?? '') === 'VERA 1:1', 'ångern lägger tillbaka veras fastighetsbeteckning');
inf($veraBackName === 'Vera Vill', 'ångern lägger tillbaka veras namn');
inf($veraBackPnr !== '' && jeab_contacts_sole_pnr_key($veraBackPnr) === jeab_contacts_sole_pnr_key('9013400001'), 'ångern behåller personnumret från nuvarande ägare');
$veraTwo = jeab_contacts_sole_undo_owners_keep_pnr(
    [['name' => 'Vera Vill']],
    [
        ['name' => 'Vera Vill', 'personnummer' => '901340-0001'],
        ['name' => 'vera  vill', 'personnummer' => '199013410002'],
    ]
);
inf(($veraTwo[0]['personnummer'] ?? '') === '', 'två lika namn kopierar inte personnummer vid ånger');
$veraKeep = jeab_contacts_sole_undo_owners_keep_pnr(
    [['name' => 'Vera Vill', 'personnummer' => '901340-0001']],
    [['name' => 'Vera Vill', 'personnummer' => '']]
);
inf(jeab_contacts_sole_pnr_key((string) ($veraKeep[0]['personnummer'] ?? '')) === jeab_contacts_sole_pnr_key('9013400001'), 'tomt skriver inte över befintligt personnummer');
inf((string) ($veraKeep[0]['personnummer'] ?? '') === '199013400001', 'befintligt nummer vid ånger lagras som tolv siffror');

$pnrKey = 'sole-pnr-hmac-test-key';
putenv('JEAB_PNR_HMAC_KEY=' . $pnrKey);
$piaTwelve = '199013400002';
$piaHmac = hash_hmac('sha256', $piaTwelve, $pnrKey);
$piaMade = jeab_contacts_sole_inflow_upsert([
    'name' => 'Pia Nummer',
    'email' => 'pia.nummer@example.test',
    'phone' => '0701000211',
    'address' => 'Nummersgatan 2, 177 60 Järfälla',
    'company' => 'jarfalla',
    'personnummer' => '901340-0002',
]);
inf(is_array($piaMade) && trim((string) ($piaMade['id'] ?? '')) !== '', 'pia-kontakten skapas');
$piaId = (string) $piaMade['id'];
inf(inf_owner_pnr(jeab_kontakt_get($piaId)) === $piaTwelve, 'inflöde lagrar tolv siffror utan bindestreck');
$piaAgain = jeab_contacts_sole_inflow_upsert([
    'name' => 'Pia Nummer',
    'email' => 'pia.nummer@example.test',
    'phone' => '0701000211',
    'address' => 'Nummersgatan 2, 177 60 Järfälla',
    'company' => 'jarfalla',
]);
inf(is_array($piaAgain) && inf_owner_pnr($piaAgain) === $piaTwelve, 'inflöde utan nummer lämnar det sparade');
$piaText = jeab_contacts_sole_inflow_upsert([
    'name' => 'Pia Text',
    'email' => 'pia.text@example.test',
    'phone' => '0701000212',
    'address' => 'Textgatan 3, 177 60 Järfälla',
    'company' => 'jarfalla',
    'personnummer' => '  inte-ett-nummer  ',
]);
inf(is_array($piaText) && inf_owner_pnr($piaText) === 'inte-ett-nummer', 'ogiltigt nummer lagras trimmad');
$textId = (string) $piaText['id'];
jeab_kontakter_mutate(static function (array &$contacts) use ($piaId, $piaTwelve): void {
    if (isset($contacts[$piaId]) && is_array($contacts[$piaId])) {
        $contacts[$piaId]['personnummer'] = $piaTwelve;
    }
});
$piaBeforeFast = (string) (jeab_kontakt_get($piaId)['fastighetsbeteckning'] ?? '');
jeab_contacts_sole_divert_property([
    'contact_id' => $piaId,
    'name' => 'Pia Nummer',
    'email' => 'pia.nummer@example.test',
    'phone' => '0701000211',
    'fastighetsbeteckning' => 'PIA 2:2',
    'owners' => [['name' => 'Pia Nummer', 'personnummer' => '9013400002']],
    'company' => 'jarfalla',
]);
$piaMid = jeab_kontakt_get($piaId);
inf(inf_owner_pnr(is_array($piaMid) ? $piaMid : null) === $piaTwelve, 'divert lagrar tolv siffror');
inf(is_array($piaMid) && (string) ($piaMid['personnummer'] ?? '') === $piaTwelve, 'divert normaliserar kontaktens personnummerfält');
$piaLog = $pdo->prepare('SELECT batch_id, before_json, after_json FROM contacts_sole_undo WHERE kind = ? AND entity_id = ? ORDER BY id DESC LIMIT 1');
$piaLog->execute(['property', $piaId]);
$piaUndo = $piaLog->fetch(PDO::FETCH_ASSOC);
inf(is_array($piaUndo), 'pias undo-rad finns');
$piaJson = (string) ($piaUndo['before_json'] ?? '') . (string) ($piaUndo['after_json'] ?? '');
inf(!inf_pnr_leak($piaJson, $piaTwelve) && !inf_pnr_leak($piaJson, '9013400002') && !inf_pnr_leak($piaJson, '901340-0002'), 'pias undo-rad saknar klartext');
$piaBefore = json_decode((string) ($piaUndo['before_json'] ?? ''), true);
$piaAfter = json_decode((string) ($piaUndo['after_json'] ?? ''), true);
$piaOwnerHmac = '';
if (is_array($piaBefore)) {
    foreach (is_array($piaBefore['owners'] ?? null) ? $piaBefore['owners'] : [] as $owner) {
        if (is_array($owner)) {
            $piaOwnerHmac = (string) ($owner['pnr_hmac'] ?? '');
        }
    }
}
inf($piaOwnerHmac === $piaHmac && is_array($piaBefore) && (string) ($piaBefore['pnr_hmac'] ?? '') === $piaHmac, 'före-bilden har HMAC för ägare och fält');
$piaAfterHmac = '';
if (is_array($piaAfter)) {
    foreach (is_array($piaAfter['owners'] ?? null) ? $piaAfter['owners'] : [] as $owner) {
        if (is_array($owner)) {
            $piaAfterHmac = (string) ($owner['pnr_hmac'] ?? '');
        }
    }
}
inf($piaAfterHmac === $piaHmac, 'efter-bilden har samma HMAC');
jeab_contacts_sole_divert_property([
    'contact_id' => $textId,
    'name' => 'Pia Text',
    'email' => 'pia.text@example.test',
    'phone' => '0701000212',
    'fastighetsbeteckning' => 'TEXT 1:1',
    'owners' => [['name' => 'Pia Text', 'personnummer' => 'inte-ett-nummer']],
    'company' => 'jarfalla',
]);
$textLog = $pdo->prepare('SELECT before_json, after_json FROM contacts_sole_undo WHERE kind = ? AND entity_id = ? ORDER BY id DESC LIMIT 1');
$textLog->execute(['property', $textId]);
$textUndo = $textLog->fetch(PDO::FETCH_ASSOC);
$textJson = is_array($textUndo) ? (string) ($textUndo['before_json'] ?? '') . (string) ($textUndo['after_json'] ?? '') : '';
inf($textJson !== '' && !inf_pnr_leak($textJson, 'inte-ett-nummer') && !str_contains($textJson, 'pnr_hmac'), 'ogiltigt nummer hamnar inte i undo');
jeab_kontakter_mutate(static function (array &$contacts) use ($piaId): void {
    if (!isset($contacts[$piaId]) || !is_array($contacts[$piaId])) {
        return;
    }
    $contacts[$piaId]['personnummer'] = '';
    if (isset($contacts[$piaId]['owners'][0]) && is_array($contacts[$piaId]['owners'][0])) {
        $contacts[$piaId]['owners'][0]['name'] = 'Pia Annan';
    }
});
$piaDone = jeab_contacts_sole_undo(['dry' => 0, 'batch_id' => (string) ($piaUndo['batch_id'] ?? '')], $store);
inf(!empty($piaDone['ok']) && !empty($piaDone['written']), 'ånger av pia skriver');
$piaBack = jeab_kontakt_get($piaId);
$piaBackName = '';
foreach (is_array($piaBack['owners'] ?? null) ? $piaBack['owners'] : [] as $owner) {
    if (is_array($owner)) {
        $piaBackName = (string) ($owner['name'] ?? '');
    }
}
inf($piaBackName === 'Pia Nummer' && inf_owner_pnr(is_array($piaBack) ? $piaBack : null) === $piaTwelve, 'HMAC återställer numret trots bytt namn');
inf(is_array($piaBack) && (string) ($piaBack['personnummer'] ?? '') === $piaTwelve, 'HMAC återställer kontaktens personnummerfält');
inf(is_array($piaBack) && (string) ($piaBack['fastighetsbeteckning'] ?? '') === $piaBeforeFast, 'ånger lägger tillbaka pias fastighetsbeteckning');
$twinA = '199013400003';
$twinB = '199013400004';
$picked = jeab_contacts_sole_undo_owners_keep_pnr(
    [['name' => 'Pia Tvilling', 'pnr_hmac' => hash_hmac('sha256', $twinA, $pnrKey)]],
    [
        ['name' => 'Pia Tvilling', 'personnummer' => $twinB],
        ['name' => 'pia  tvilling', 'personnummer' => $twinA],
    ]
);
inf((string) ($picked[0]['personnummer'] ?? '') === $twinA && !array_key_exists('pnr_hmac', $picked[0]), 'hmac väljer rätt nummer när namnet är tvetydigt');
$emptyLive = jeab_contacts_sole_undo_owners_keep_pnr(
    [['name' => 'Pia Nummer', 'pnr_hmac' => $piaHmac]],
    [['name' => 'Pia Nummer', 'personnummer' => '']]
);
inf(!array_key_exists('personnummer', $emptyLive[0]) && !array_key_exists('pnr_hmac', $emptyLive[0]), 'hmac utan nuvarande nummer skriver inte tomt');
$scrubBatch = jeab_contacts_sole_batch();
$scrubPlain = '199013400003';
inf(jeab_contacts_sole_undo_add($scrubBatch, 1, 'contact', 'contact', 'kt_scrub_test', [
    'name' => 'Scrub Test',
    'personnummer' => $scrubPlain,
    'pnr' => $scrubPlain,
    'SocialSecurityNumber' => $scrubPlain,
    'owners' => [['name' => 'Scrub Test', 'personnummer' => $scrubPlain]],
    'owner_pnr' => [$scrubPlain],
], [
    'personnummer' => $scrubPlain,
]), 'contact-ånger kan skrivas');
inf(jeab_contacts_sole_undo_add($scrubBatch, 2, 'link', 'order', '92077', [
    'contact_id' => 'kt_scrub_test',
    'huvudsokande_personnummer' => $scrubPlain,
], [
    'contact_id' => 'kt_scrub_test',
    'owner_pnr' => [$scrubPlain],
    'objekt_personnummer' => $scrubPlain,
]), 'link-ånger kan skrivas');
$scrubLog = $pdo->prepare('SELECT kind, before_json, after_json FROM contacts_sole_undo WHERE batch_id = ? ORDER BY seq ASC');
$scrubLog->execute([$scrubBatch]);
$scrubRows = $scrubLog->fetchAll(PDO::FETCH_ASSOC);
inf(count($scrubRows) === 2, 'contact och link ligger i undo');
$scrubOk = count($scrubRows) === 2;
foreach ($scrubRows as $scrubRow) {
    if (!is_array($scrubRow)) {
        $scrubOk = false;
        continue;
    }
    $blob = (string) ($scrubRow['before_json'] ?? '') . (string) ($scrubRow['after_json'] ?? '');
    if (inf_pnr_leak($blob, $scrubPlain) || !str_contains($blob, hash_hmac('sha256', $scrubPlain, $pnrKey))) {
        $scrubOk = false;
    }
}
inf($scrubOk, 'contact och link har HMAC och ingen klartext');
putenv('JEAB_PNR_HMAC_KEY');
inf(jeab_contacts_sole_pnr_hmac_key() === '', 'testet har ingen HMAC-nyckel kvar');
jeab_contacts_sole_divert_property([
    'contact_id' => $piaId,
    'name' => 'Pia Nummer',
    'email' => 'pia.nummer@example.test',
    'phone' => '0701000211',
    'fastighetsbeteckning' => 'PIA 4:4',
    'owners' => [['name' => 'Pia Nummer', 'personnummer' => '901340-0002']],
    'company' => 'jarfalla',
]);
$piaLog->execute(['property', $piaId]);
$piaOpen = $piaLog->fetch(PDO::FETCH_ASSOC);
$piaOpenJson = is_array($piaOpen) ? (string) ($piaOpen['before_json'] ?? '') . (string) ($piaOpen['after_json'] ?? '') : '';
inf($piaOpenJson !== '' && !inf_pnr_leak($piaOpenJson, $piaTwelve) && !str_contains($piaOpenJson, 'pnr_hmac'), 'utan nyckel utelämnas fältet');
inf(inf_owner_pnr(jeab_kontakt_get($piaId)) === $piaTwelve, 'utan nyckel lagras numret ändå');

$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
$offKeep = jeab_contacts_sole_undo_owners_keep_pnr(
    [['name' => 'Vera Vill', 'personnummer' => '901340-0001']],
    [['name' => 'Vera Vill', 'personnummer' => '']]
);
inf((string) ($offKeep[0]['personnummer'] ?? '') === '19901340-0001', 'flaggan av behåller visningsformen vid ånger');
$offHmac = jeab_contacts_sole_undo_owners_keep_pnr(
    [['name' => 'Annat Namn', 'pnr_hmac' => $piaHmac]],
    [['name' => 'Pia Kvar', 'personnummer' => $piaTwelve]]
);
inf(!array_key_exists('personnummer', $offHmac[0]), 'flaggan av matchar inte hmac');
$offBefore = inf_count();
$offLead = jeab_contacts_sole_inflow_upsert([
    'name' => 'Av Flagga',
    'email' => 'av.flagga@example.test',
    'phone' => '0701000108',
    'address' => 'Avstangd 8, 177 60 Järfälla',
    'company' => 'jarfalla',
]);
inf($offLead === null && inf_count() === $offBefore, 'flaggan av skapar ingen kontakt');
$third = $after;
$third['phone'] = '+46701000309';
$third['email'] = 'dina.tredje@example.test';
$offReturned = jeab_contacts_sole_divert_order($before, $third);
$untouched = jeab_kontakt_get($contactId);
inf(is_array($untouched) && jeab_kontakt_row_phone($untouched) === $afterPhone, 'flaggan av ändrar inte kontaktens telefon');
inf(is_array($untouched) && jeab_kontakt_row_email($untouched) === 'dina.efter@example.test', 'flaggan av ändrar inte kontaktens e-post');
inf((string) ($offReturned['phone'] ?? '') === '+46701000309', 'flaggan av lämnar divert-resultatet som idag');
inf(jeab_webshop_sole_bind($store, $shopId, ['name' => 'Ny', 'email' => 'ny@example.test', 'phone' => '0701000109']) === '', 'flaggan av binder ingen webshopkontakt');
inf(jeab_manuell_order_sole_contact([
    'owner_name' => ['Av Flagga'],
    'owner_pnr' => [''],
    'email' => 'av.flagga@example.test',
    'phone' => '0701000108',
    'address' => 'Avstangd 8',
    'co' => 'jarfalla',
]) === null, 'flaggan av skapar ingen manuell kontakt');
$offProperty = inf_property_rows($pdo);
jeab_contacts_sole_divert_property([
    'contact_id' => $undoId,
    'reminder_id' => $undoReminderId,
    'fastighetsbeteckning' => 'AV 3:3',
    'owners' => [['name' => 'Av Flagga', 'personnummer' => '']],
]);
$offContact = jeab_kontakt_get($undoId);
inf(inf_property_rows($pdo) === $offProperty, 'flaggan av skriver ingen undo-rad');
inf(is_array($offContact) && (string) ($offContact['fastighetsbeteckning'] ?? '') === 'GAMLA 1:1', 'flaggan av ändrar inte fastighetsbeteckning');
inf(inf_count() === $offBefore, 'flaggan av lämnar kontaktantalet');

echo "contacts sole inflow: ok\n";
