<?php

declare(strict_types=1);

/**
 * Bokning med droppade customer_name/email/phone/address. Kopian är tom i
 * jeab_hydrate_booking. Visat namn på en länkad bokning kommer från
 * jeab_contacts_sole_paint_booking. Tom kopia som inte är kontakt eller
 * snapshot är other, aldrig copy. Påhittade namn, inget personnummer.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-field-booking-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
$_ENV['DATA_BACKEND'] = 'mysql';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function fb(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$empty = jeab_contacts_field_source_classify('', '', '', '', false, 'name');
fb($empty['source'] === 'other', 'tom kopia blir other');
$painted = jeab_contacts_field_source_classify('Malat Namn', 'Bea Bok', '', '', false, 'name');
fb($painted['source'] === 'other', 'tom kopia som inte matchar kontakten blir other');
$fromContact = jeab_contacts_field_source_classify('Bea Bok', 'Bea Bok', '', '', false, 'name');
fb($fromContact['source'] === 'contact' && $fromContact['match'] === 1, 'tom kopia som matchar kontakten blir contact');
$fromCopy = jeab_contacts_field_source_classify('Kopia Ada', 'Ada Kontakt', 'Kopia Ada', '', false, 'name');
fb($fromCopy['source'] === 'copy', 'ifylld avvikande kopia blir copy');
$fromSnap = jeab_contacts_field_source_classify('Fryst Ada', '', 'Fryst Ada', 'Fryst Ada', true, 'name');
fb($fromSnap['source'] === 'snapshot', 'fryst kopia utan kontakt blir snapshot');

require_once dirname(__DIR__) . '/src/contacts_drop_customer_fields.php';
$store = jeab_store();
fb($store instanceof JeabPdoStore, 'sqlite-lagret finns');
$pdo = $store->pdo();
fb(jeab_contacts_sole(), 'kontakten är källan i visningen');

$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-field-booking']));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-field-booking';

$now = jeab_now();
$contact = jeab_kontakt_find_or_create([
    'name' => 'Bea Bok',
    'email' => 'bea.bok@example.test',
    'phone' => '0701000911',
    'address' => 'Bokgatan 4, 177 31 Teststad',
    'city' => 'Teststad',
    'postal_code' => '17731',
    'company' => 'jarfalla',
], false, false);
fb(is_array($contact) && (string) $contact['id'] !== '', 'kontakten finns');
$contactId = (string) $contact['id'];
$customer = $store->insertCustomer('Kopia Los', $now);
$linked = $store->insertBooking([
    'customer_id' => $customer['id'],
    'customer_name' => 'Kopia Bea',
    'email' => 'kopia.bea@example.test',
    'phone' => '0701000912',
    'address' => 'Kopiagatan 9',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => '2026-11-02 09:00:00',
    'ends_at' => '2026-11-02 10:00:00',
    'status' => 'booked',
    'contact_id' => $contactId,
    'created_at' => $now,
    'updated_at' => $now,
]);
$loose = $store->insertBooking([
    'customer_id' => $customer['id'],
    'customer_name' => 'Kopia Los',
    'email' => 'kopia.los@example.test',
    'phone' => '0701000913',
    'address' => 'Losgatan 2',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => '2026-11-03 09:00:00',
    'ends_at' => '2026-11-03 10:00:00',
    'status' => 'booked',
    'contact_id' => '',
    'created_at' => $now,
    'updated_at' => $now,
]);
$linkedId = (int) ($linked['id'] ?? 0);
$looseId = (int) ($loose['id'] ?? 0);
fb($linkedId > 0 && $looseId > 0, 'bokningarna finns');

foreach (['customer_name', 'email', 'phone', 'address'] as $col) {
    jeab_contacts_drop_column($pdo, 'bookings', $col);
}
jeab_contacts_drop_marker_save($pdo, ['bookings' => '2026-11-01T00:00:00+00:00']);
jeab_copy_columns_forget();
jeab_booking_cache_forget();
fb(!jeab_copy_column_present($pdo, 'bookings', 'customer_name') && !jeab_copy_column_present($pdo, 'bookings', 'email') && !jeab_copy_column_present($pdo, 'bookings', 'phone') && !jeab_copy_column_present($pdo, 'bookings', 'address'), 'bokningens kopiekolumner är borta');
$raw = $store->getBooking($linkedId);
fb(is_array($raw) && ($raw['customer_name'] ?? 'x') === '' && array_key_exists('email', $raw) && $raw['email'] === null, 'hydrate ger tom kopia när kolumnerna saknas');

$report = jeab_contacts_field_source_dispatch(['kind' => 'booking', 'id' => (string) $linkedId]);
$json = (string) json_encode($report);
fb(($report['ok'] ?? false) === true && ($report['contact_id'] ?? '') === $contactId, 'länkad bokning pekar ut kontakten');
fb(($report['fields']['name']['source'] ?? '') === 'contact' && ($report['fields']['email']['source'] ?? '') === 'contact' && ($report['fields']['phone']['source'] ?? '') === 'contact' && ($report['fields']['address']['source'] ?? '') === 'contact', 'länkad bokning har källan contact');
fb(!str_contains($json, 'copy'), 'länkad bokning säger inte copy');
fb(!str_contains($json, 'Bea Bok') && !str_contains($json, 'bea.bok') && !str_contains($json, 'Kopia Bea') && !str_contains($json, '0701000911'), 'svaret har ingen klartext');

jeab_booking_cache_forget($looseId);
$looseReport = jeab_contacts_field_source_dispatch(['kind' => 'booking', 'id' => (string) $looseId]);
$looseJson = (string) json_encode($looseReport);
fb(($looseReport['ok'] ?? false) === true && ($looseReport['fields']['name']['source'] ?? '') === 'other' && ($looseReport['fields']['email']['source'] ?? '') === 'other' && ($looseReport['fields']['phone']['source'] ?? '') === 'other' && ($looseReport['fields']['address']['source'] ?? '') === 'other', 'olänkad bokning med tom kopia blir other');
fb(!str_contains($looseJson, 'copy') && !str_contains($looseJson, 'Kopia Los') && !str_contains($looseJson, 'kopia.los'), 'olänkad bokning säger inte copy och har ingen klartext');
fb(!jeab_copy_column_present($pdo, 'bookings', 'customer_name'), 'läsningen lägger inte tillbaka kolumnen');

echo "ALL OK\n";
