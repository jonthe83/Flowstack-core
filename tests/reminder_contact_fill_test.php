<?php

declare(strict_types=1);

/**
 * Automatisk uppdatering får inte skriva över en kopplad kontakt.
 * Påhittade namn och adresser.
 */

$tmp = sys_get_temp_dir() . '/jeab-rem-fill-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function rf(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
$contact = jeab_kontakt_find_or_create([
    'name' => 'Ada Prov',
    'email' => 'ada@example.test',
    'address' => 'Provgatan 12',
    'postal_code' => '12345',
    'city' => 'Provstad',
], false);
rf(is_array($contact) && (string) ($contact['id'] ?? '') !== '', 'kontakten skapas');
$contactId = (string) $contact['id'];

$row = $store->insertReminder([
    'title' => 'Uppföljning',
    'customer_name' => 'Ada Prov',
    'email' => '',
    'phone' => '',
    'address' => 'Provgatan 12, Sverige',
    'ort' => '',
    'postal' => '',
    'company' => 'jarfalla',
    'note' => "Adress: Felgatan 3, 543 21 Felstad, Sverige\nE-post: annan@example.test\n",
    'due_at' => '2026-10-20 09:00:00',
    'created_at' => jeab_now(),
    'updated_at' => jeab_now(),
]);
$id = (int) $row['id'];
$store->saveReminder($id, ['contact_id' => $contactId]);
$row = $store->getReminder($id);
rf((string) ($row['contact_id'] ?? '') === $contactId, 'påminnelsen är kopplad');

$hookStyle = jeab_update_reminder($store, $id, [
    'email' => 'annan@example.test',
    'address' => 'Felgatan 3, Felstad',
    'ort' => 'Felstad',
    'postal' => '54321',
]);
$kept = jeab_kontakt_get($contactId);
rf((string) ($kept['street'] ?? '') === 'Provgatan 12', 'krok-stil lämnar gatan');
rf((string) ($kept['postal_code'] ?? '') === '12345', 'krok-stil lämnar postnumret');
rf((string) ($kept['city'] ?? '') === 'Provstad', 'krok-stil lämnar orten');
rf(jeab_kontakt_row_email($kept) === 'ada@example.test', 'krok-stil lämnar e-posten');
rf((string) ($hookStyle['address'] ?? '') === 'Provgatan 12, Sverige', 'kopian med Sverige ligger kvar');
rf(trim((string) ($hookStyle['email'] ?? '')) === '', 'kopians tomma e-post ligger kvar');

$again = jeab_update_reminder($store, $id, [
    'email' => 'annan@example.test',
    'address' => 'Felgatan 3, Felstad',
    'ort' => 'Felstad',
    'postal' => '54321',
]);
$keptAgain = jeab_kontakt_get($contactId);
rf((int) ($again['id'] ?? 0) === $id && (string) ($keptAgain['street'] ?? '') === 'Provgatan 12' && jeab_kontakt_row_email($keptAgain) === 'ada@example.test', 'samma krok skriver inte över en gång till');

$hook = jeab_inkorg_reminder_epost_hook(['dry' => 0, 'id' => $id]);
$afterHook = jeab_kontakt_get($contactId);
rf(in_array($id, $hook['ids'] ?? [], true), 'kroken ser anteckningen');
rf((string) ($afterHook['street'] ?? '') === 'Provgatan 12', 'kroken lämnar Provgatan 12');
rf((string) ($afterHook['postal_code'] ?? '') === '12345' && (string) ($afterHook['city'] ?? '') === 'Provstad', 'kroken lämnar 12345 Provstad');
rf(jeab_kontakt_row_email($afterHook) === 'ada@example.test', 'kroken lämnar ada@example.test');
rf(!str_contains((string) ($afterHook['street'] ?? '') . (string) ($afterHook['city'] ?? '') . jeab_kontakt_row_email($afterHook), 'Felgatan') && jeab_kontakt_row_email($afterHook) !== 'annan@example.test', 'kontakten blir inte Felgatan');

$second = jeab_inkorg_reminder_epost_hook(['dry' => 0, 'id' => $id]);
$afterSecond = jeab_kontakt_get($contactId);
rf(($second['ids'] ?? []) === [] || (string) ($afterSecond['street'] ?? '') === 'Provgatan 12', 'andra körningen lämnar kontakten');
rf((string) ($afterSecond['postal_code'] ?? '') === '12345' && (string) ($afterSecond['city'] ?? '') === 'Provstad' && jeab_kontakt_row_email($afterSecond) === 'ada@example.test', 'andra körningen rör inte e-post eller adress');

echo "reminder contact fill ok\n";
