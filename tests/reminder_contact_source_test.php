<?php

declare(strict_types=1);

/**
 * Påminnelser läser och skriver kunduppgifter via kontakten.
 * Påhittade namn och adresser. Ingen riktig kund.
 */

$tmp = sys_get_temp_dir() . '/jeab-rem-contact-' . bin2hex(random_bytes(4));
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

function rc(bool $ok, string $message): void
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
    'name' => 'Ada Exempel',
    'email' => 'ada.exempel@example.test',
    'phone' => '0701111111',
    'address' => 'Exempelgatan 12, 123 45 Teststad, Sverige',
    'city' => 'Teststad, Sverige',
    'postal_code' => '12345',
], false);
rc(is_array($contact) && (string) ($contact['id'] ?? '') !== '', 'kontakten skapas');
$contactId = (string) $contact['id'];
rc((string) ($contact['city'] ?? '') === 'Teststad' && (string) ($contact['postal_code'] ?? '') === '12345', 'kontakten tappar Sverige och behåller postnummer');

$linked = $store->insertReminder([
    'title' => 'Batteri',
    'customer_name' => 'Kopia Kund',
    'email' => 'kopia@example.test',
    'phone' => '+46702222222',
    'address' => 'Kopiagatan 1, Sverige',
    'ort' => 'Sverige',
    'postal' => '99999',
    'company' => 'jarfalla',
    'note' => 'Anteckning på raden',
    'due_at' => '2026-10-20 09:00:00',
    'created_at' => jeab_now(),
    'updated_at' => jeab_now(),
]);
$store->saveReminder((int) $linked['id'], ['contact_id' => $contactId, 'thread_id' => 'arende-exempel']);
$linked = $store->getReminder((int) $linked['id']);
rc((string) ($linked['contact_id'] ?? '') === $contactId, 'påminnelsen pekar på kontakten');
rc((string) ($linked['customer_name'] ?? '') === 'Kopia Kund', 'kopian ligger kvar på påminnelsen');

$open = jeab_reminder_row($linked, true);
$shut = jeab_reminder_row($linked, false);
foreach (['Ada Exempel', 'Exempelgatan 12', 'Teststad', 'ada.exempel@example.test', 'mailto:ada.exempel@example.test'] as $needle) {
    rc(str_contains($open, $needle), 'öppet kort visar ' . $needle);
    rc(str_contains($shut, $needle) || $needle === 'mailto:ada.exempel@example.test', 'stängt kort visar kundens adress och namn');
}
rc(str_contains($shut, 'Ada Exempel') && str_contains($shut, 'Exempelgatan 12') && str_contains($shut, 'Teststad'), 'listan visar kontakten');
rc(!str_contains($open, 'Kopia Kund') && !str_contains($shut, 'Kopia Kund'), 'kopians namn visas inte');
rc(!str_contains($open, 'Kopiagatan') && !str_contains($shut, 'Kopiagatan'), 'kopians gata visas inte');
rc(!str_contains($open, 'Sverige') && !str_contains($shut, 'Sverige'), 'Sverige visas inte');
rc(substr_count($open, 'Öppna ärendet') === 1 && substr_count($shut, 'Öppna ärendet') === 1, 'inkorgslänken visas en gång');
rc(substr_count($open, '>E-post<') === 1 || substr_count($open, 'E-post') >= 1, 'e-post har en etikett');
$heading = jeab_reminder_heading($linked);
rc(str_contains($heading, 'Ada Exempel') && str_contains($heading, 'Exempelgatan 12') && str_contains($heading, 'Teststad') && !str_contains($heading, 'Sverige'), 'rubriken kommer från kontakten');

$hits = jeab_filter_reminders([$linked], 'all', ['mode' => 'all', 'tag' => null], 'ada exempel');
rc(count($hits) === 1, 'sök träffar kontaktens namn');

$updated = jeab_update_reminder($store, (int) $linked['id'], [
    'customer' => 'Bo Exempel',
    'email' => 'bo.exempel@example.test',
    'phone' => '0703333333',
    'address' => 'Exempelgatan 99, 234 56 Testby',
    'notes' => 'Kvar på påminnelsen',
    'due_at' => '2026-11-02 00:00:00',
], true);
rc((string) ($updated['customer_name'] ?? '') === 'Kopia Kund', 'redigering skriver inte namnet på kopian');
rc((string) ($updated['email'] ?? '') === 'kopia@example.test', 'redigering skriver inte e-post på kopian');
rc((string) ($updated['phone'] ?? '') === '+46702222222', 'redigering skriver inte telefon på kopian');
rc((string) ($updated['address'] ?? '') === 'Kopiagatan 1, Sverige', 'redigering skriver inte adressen på kopian');
rc((string) ($updated['postal'] ?? '') === '99999' && (string) ($updated['ort'] ?? '') === 'Sverige', 'kopians postnummer och ort lämnas');
rc((string) ($updated['note'] ?? '') === 'Kvar på påminnelsen', 'anteckningen ligger kvar på påminnelsen');
rc((string) ($updated['title'] ?? '') === 'Batteri' && (string) ($updated['due_at'] ?? '') === '2026-11-02 00:00:00', 'produkt och datum ligger kvar på påminnelsen');
$after = jeab_kontakt_get($contactId);
rc(is_array($after) && jeab_kontakt_row_name($after) === 'Bo Exempel', 'namnet skrivs på kontakten');
rc(jeab_kontakt_row_email($after) === 'bo.exempel@example.test', 'e-post skrivs på kontakten');
rc(jeab_kontakt_row_phone($after) === jeab_kontakt_phone('0703333333'), 'telefon skrivs på kontakten');
rc((string) ($after['street'] ?? '') === 'Exempelgatan 99', 'gatan skrivs på kontakten');
rc((string) ($after['postal_code'] ?? '') === '23456' && (string) ($after['city'] ?? '') === 'Testby', 'postnummer och ort skrivs på kontakten');
$again = jeab_reminder_row($store->getReminder((int) $linked['id']), true);
rc(str_contains($again, 'Bo Exempel') && str_contains($again, 'Exempelgatan 99') && str_contains($again, 'Testby') && !str_contains($again, 'Sverige'), 'kortet läser den uppdaterade kontakten');

$made = jeab_create_reminder($store, 'Solceller', '2026-10-21 00:00:00', 'ny rad', null, [
    'customer' => 'Bo Exempel',
    'email' => 'bo.exempel@example.test',
    'phone' => '0703333333',
    'address' => 'Exempelgatan 99, 234 56 Testby',
    'company' => 'jarfalla',
]);
rc((string) ($made['contact_id'] ?? '') === $contactId, 'skapande återanvänder kontakten på samma gata och ort');
rc((string) ($made['ort'] ?? '') === 'Testby' && (string) ($made['postal'] ?? '') === '23456', 'ort och postnummer fylls vid skapande');
rc(count(jeab_kontakter_load()) === 1, 'samma gata och ort skapar ingen andra kontakt');

$other = jeab_create_reminder($store, 'Laddbox', '2026-10-22 00:00:00', null, null, [
    'customer' => 'Caj Exempel',
    'email' => 'caj.exempel@example.test',
    'phone' => '0704444444',
    'address' => 'Annangatan 4, 111 22 Teststad, Sverige',
    'company' => 'jarfalla',
]);
rc((string) ($other['contact_id'] ?? '') !== '' && (string) ($other['contact_id'] ?? '') !== $contactId, 'ny gata får en egen kontakt');
rc((string) ($other['ort'] ?? '') === 'Teststad' && (string) ($other['postal'] ?? '') === '11122', 'ny kontakt får ort och postnummer');
rc(!str_contains((string) ($other['ort'] ?? ''), 'Sverige'), 'skapad ort innehåller inte Sverige');
$otherContact = jeab_kontakt_get((string) $other['contact_id']);
rc(is_array($otherContact) && (string) ($otherContact['city'] ?? '') === 'Teststad' && (string) ($otherContact['postal_code'] ?? '') === '11122', 'kontakten får ort och postnummer');
rc(count(jeab_kontakter_load()) === 2, 'två olika gator är två kontakter');

$loose = $store->insertReminder([
    'title' => 'Batteri',
    'customer_name' => 'Disa Exempel',
    'email' => 'disa.exempel@example.test',
    'phone' => '+46705555555',
    'address' => 'Ensamgatan 7, 211 20 Teststad',
    'ort' => 'Teststad',
    'postal' => '21120',
    'company' => 'jarfalla',
    'note' => 'utan kontakt',
    'due_at' => '2026-10-23 00:00:00',
    'created_at' => jeab_now(),
    'updated_at' => jeab_now(),
]);
rc(trim((string) ($loose['contact_id'] ?? '')) === '', 'rad utan contact_id förblir utan kontakt');
$looseHtml = jeab_reminder_row($loose, true);
rc(str_contains($looseHtml, 'Disa Exempel') && str_contains($looseHtml, 'Ensamgatan 7') && str_contains($looseHtml, 'Teststad'), 'rad utan kontakt visar sina egna fält');
rc(str_contains($looseHtml, 'disa.exempel@example.test') && str_contains($looseHtml, 'mailto:disa.exempel@example.test'), 'rad utan kontakt visar sin e-post');
rc(!str_contains($looseHtml, 'Bo Exempel') && !str_contains($looseHtml, 'Sverige'), 'rad utan kontakt blandar inte in en annan kontakt');
$looseSaved = jeab_update_reminder($store, (int) $loose['id'], ['customer' => 'Disa Qvar', 'phone' => '0706666666']);
rc((string) ($looseSaved['customer_name'] ?? '') === 'Disa Qvar', 'rad utan kontakt sparar namnet på sig själv');
rc((string) ($looseSaved['phone'] ?? '') === jeab_normalize_reminder_phone('0706666666'), 'rad utan kontakt sparar telefonen på sig själv');
rc(jeab_kontakt_row_name(jeab_kontakt_get($contactId)) === 'Bo Exempel', 'en annan kontakts namn rörs inte');

$byMail = jeab_create_reminder($store, 'Uppföljning', '2026-10-24 00:00:00', null, null, [
    'customer' => 'Bo Exempel',
    'email' => 'bo.exempel@example.test',
    'company' => 'jarfalla',
]);
rc((string) ($byMail['contact_id'] ?? '') === $contactId, 'samma e-post återanvänder kontakten');
rc(count(jeab_kontakter_load()) === 2, 'e-postträff skapar ingen extra kontakt');

$otherAddress = jeab_create_reminder($store, 'Annan adress', '2026-10-25 00:00:00', null, null, [
    'customer' => 'Bo Annan',
    'email' => 'bo.exempel@example.test',
    'phone' => '0707777777',
    'address' => 'Nygatan 8, 333 33 Testort, Sverige',
    'company' => 'jarfalla',
]);
$keptAddress = jeab_kontakt_get($contactId);
rc((string) ($otherAddress['contact_id'] ?? '') === $contactId, 'annan adress på samma e-post länkar kontakten');
rc((string) ($keptAddress['street'] ?? '') === 'Exempelgatan 99', 'annan adress skriver inte över gatan');
rc((string) ($keptAddress['postal_code'] ?? '') === '23456' && (string) ($keptAddress['city'] ?? '') === 'Testby', 'annan adress skriver inte över postnummer eller ort');
rc(jeab_kontakt_row_name($keptAddress) === 'Bo Exempel', 'skapande skriver inte över namnet');
rc(jeab_kontakt_row_phone($keptAddress) === jeab_kontakt_phone('0703333333'), 'skapande skriver inte över telefonen');
rc((string) ($otherAddress['address'] ?? '') === 'Nygatan 8, 333 33 Testort, Sverige', 'den andra adressen ligger kvar på påminnelsen');
$otherLine = 'Annan adress från påminnelse ' . (int) $otherAddress['id'] . ': Nygatan 8, Testort';
rc(str_contains((string) ($keptAddress['ovrigt'] ?? ''), $otherLine), 'Övrigt får en rad om den andra adressen');
rc(!str_contains((string) ($keptAddress['ovrigt'] ?? ''), 'Sverige'), 'Övrigt tar inte med Sverige');
$repeat = jeab_create_reminder($store, 'Annan adress igen', '2026-10-26 00:00:00', null, null, [
    'customer' => 'Bo Exempel',
    'email' => 'bo.exempel@example.test',
    'address' => 'Nygatan 8, 333 33 Testort, Sverige',
    'company' => 'jarfalla',
]);
$afterRepeat = jeab_kontakt_get($contactId);
rc((string) ($repeat['contact_id'] ?? '') === $contactId && substr_count((string) ($afterRepeat['ovrigt'] ?? ''), 'Nygatan 8, Testort') === 2, 'varje påminnelse antecknas en gång');

echo "reminder contact source ok\n";
