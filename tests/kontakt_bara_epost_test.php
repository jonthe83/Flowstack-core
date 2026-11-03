<?php

declare(strict_types=1);

/**
 * Kontakt med bara e-post (Bernard-fallet, syntetiska data).
 * Påhittade rader. Inget utskick. Inget personnummer i svaret.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-bara-epost-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
unset($GLOBALS['_JEAB_CONTACTS_STRICT'], $GLOBALS['_JEAB_CONTACTS_SOLE']);
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
putenv('CONTACTS_SOLE');
putenv('CONTACTS_STRICT');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'tok-bara-epost',
    'outgoing_webhook_url' => '',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
jeab_ordrar_external_live(false);

function be(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
$GLOBALS['_JEAB_CONTACTS_STRICT'] = true;
be(jeab_contacts_strict_active(), 'CONTACTS_STRICT och CONTACTS_SOLE är på');

// Platshållare är tom adress.
foreach (['Ingen adress', 'ingen adress.', 'Saknar adress', 'Adress saknas', '-', ''] as $ph) {
    be(jeab_kontakt_address_placeholder($ph), 'platshållare: «' . $ph . '»');
    $p = jeab_kontakt_parse_address($ph);
    be($p['street'] === '' && $p['street_key'] === '', 'tom gata för «' . $ph . '»');
}
be(!jeab_kontakt_address_placeholder('Storgatan 1'), 'riktig gata är ingen platshållare');

// Namn + e-post, adress «Ingen adress»: kontakt skapas.
$b = jeab_kontakt_find_or_create([
    'name' => 'Bertil Testsson',
    'email' => 'Bertil.Testsson@Example.test',
    'address' => 'Ingen adress',
    'street' => 'Ingen adress',
    'thread_id' => 'g-bernard-syn',
    'email_link' => true,
], false, false, true);
be(is_array($b) && (string) ($b['id'] ?? '') !== '', 'kontakt skapas med bara namn och e-post');
$bid = (string) $b['id'];
be(trim((string) ($b['street'] ?? '')) === '' && trim((string) ($b['street_key'] ?? '')) === '', 'gatan sparas tom, inte «Ingen adress»');

// Dubblett: samma e-post (annan skiftlägesform) ger samma kontakt, även utan namn.
$again = jeab_kontakt_find_or_create(['name' => '', 'email' => 'bertil.testsson@example.test', 'address' => 'Ingen adress'], false, false, true);
be(is_array($again) && (string) $again['id'] === $bid, 'samma e-post återanvänder kontakten (jeab_kontakt_email)');
$again2 = jeab_kontakt_find_or_create(['name' => 'Bertil Testsson', 'email' => 'bertil.testsson@example.test', 'address' => 'Testvägen 3, 111 22 Teststad'], false, false, true);
be(is_array($again2) && (string) $again2['id'] === $bid, 'ny adress senare ger ingen dubblett');

// Bara e-post, inget namn: kontakt skapas.
$solo = jeab_kontakt_find_or_create(['name' => '', 'email' => 'bara.epost@example.test'], false, false, true);
be(is_array($solo) && (string) ($solo['id'] ?? '') !== '' && (string) $solo['id'] !== $bid, 'bara e-post räcker för ny kontakt');
// Utan e-post och utan namn: ingen kontakt.
be(jeab_kontakt_find_or_create(['name' => '', 'email' => '', 'phone' => '', 'address' => 'Ingen adress'], false, false, true) === null, 'helt tom hint skapar ingen kontakt');

// Kontaktformuläret: e-post utan gata sparas.
$saved = jeab_kontakt_save_input(['email' => 'form.epost@example.test', 'street' => 'Ingen adress', 'owner_name_1' => 'Form Testsson', 'company' => 'jarfalla']);
be((string) ($saved['id'] ?? '') !== '' && trim((string) ($saved['street'] ?? '')) === '', 'kontakter.save utan gata med e-post');
$saved2 = jeab_kontakt_save_input(['email' => 'utan.namn@example.test', 'company' => 'jarfalla']);
be((string) ($saved2['id'] ?? '') !== '', 'kontakter.save med bara e-post');
$err = '';
try {
    jeab_kontakt_save_input(['owner_name_1' => 'Ingen Epost', 'company' => 'jarfalla']);
} catch (InvalidArgumentException $e) {
    $err = $e->getMessage();
}
be($err === 'Skriv e-post eller gata och nummer.', 'utan e-post och gata stoppas');

// Inkorg: ärende med påminnelse men utan contact_id. Påminnelsen har kontakten.
$rem = $store->insertReminder([
    'title' => 'Bertil Testsson – Solceller med batteri',
    'company' => 'jarfalla',
    'thread_id' => 'g-bernard-syn',
    'due_at' => '2026-11-04 09:00:00',
    'created_at' => jeab_now(),
    'updated_at' => jeab_now(),
]);
be(jeab_kontakt_stamp_reminder($store, (int) ($rem['id'] ?? 0), $bid), 'påminnelsen pekar på kontakten');
$msg = null;
try {
    $t = jeab_inkorg_thread_write('g-bernard-syn', static function (array $row): array {
        $row['status'] = 'open';
        $row['company'] = 'jarfalla';

        return $row;
    });
} catch (InvalidArgumentException $e) {
    $msg = $e->getMessage();
}
be($msg === null, 'ingen «saknar kontakt» när påminnelsen har kontakt');
be((string) (jeab_inkorg_thread_meta('g-bernard-syn')['contact_id'] ?? '') === $bid, 'ärendet kopplas till kontakten');

// Inkorg: påminnelse utan kontakt, ärendet har e-post som finns i Kontakter.
$store->insertReminder([
    'title' => 'Bertil igen',
    'company' => 'jarfalla',
    'thread_id' => 'g-bernard-syn2',
    'due_at' => '2026-11-04 09:00:00',
    'created_at' => jeab_now(),
    'updated_at' => jeab_now(),
]);
$msg2 = null;
try {
    jeab_inkorg_thread_write('g-bernard-syn2', static function (array $row): array {
        $row['status'] = 'open';
        $row['company'] = 'jarfalla';
        $row['email'] = 'BERTIL.testsson@example.test';

        return $row;
    });
} catch (InvalidArgumentException $e) {
    $msg2 = $e->getMessage();
}
be($msg2 === null && (string) (jeab_inkorg_thread_meta('g-bernard-syn2')['contact_id'] ?? '') === $bid, 'e-post räcker för att koppla ärendet');

// Webhook kräver inte telefon eller adress.
be(jeab_contacts_strict_required('webhook') === ['email'], 'webhook kräver bara e-post');
be(jeab_contacts_strict_required('offert') === ['email'], 'offert kräver bara e-post');

echo "klart\n";
