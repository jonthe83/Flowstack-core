<?php

declare(strict_types=1);

/**
 * Påminnelser målas från kontakten när CONTACTS_SOLE är på.
 * Flaggan av lämnar raden. En sida hämtar kontakterna i en fråga.
 * Påhittade rader. Inga personnummer.
 */

$tmp = sys_get_temp_dir() . '/jeab-rem-sole-paint-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
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
require_once dirname(__DIR__) . '/src/layout.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_GET = ['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'all'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SESSION['jeab_email'] = 'agenter@flowstack.example.test';

function rp_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
rp_ok($store instanceof JeabPdoStore, 'sqlite-lagret finns');
$pdo = $store->pdo();
jeab_ensure_contacts_tables($pdo, 'sqlite');
jeab_ensure_contact_id_columns($pdo, 'sqlite');
rp_ok(jeab_kontakter_sql_is_sole_source(), 'kontakter läses med get mot SQL');

$now = jeab_now();
$ada = jeab_kontakt_find_or_create([
    'name' => 'Ada Målad',
    'email' => 'ada.malad@example.test',
    'phone' => '0701000181',
    'address' => 'Malargatan 12, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
rp_ok(is_array($ada) && trim((string) ($ada['id'] ?? '')) !== '', 'kontakt finns');
$adaId = (string) $ada['id'];

$blank = jeab_kontakt_find_or_create([
    'name' => 'Blank Målad',
    'email' => 'blank.malad@example.test',
    'phone' => '0701000182',
    'address' => 'Blankgatan 1, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
rp_ok(is_array($blank), 'blank kontakt finns');
$blankId = (string) $blank['id'];
jeab_kontakter_mutate(static function (array &$contacts) use ($blankId): void {
    if (!isset($contacts[$blankId]) || !is_array($contacts[$blankId])) {
        return;
    }
    $owners = is_array($contacts[$blankId]['owners'] ?? null) ? $contacts[$blankId]['owners'] : [];
    if (isset($owners[0]) && is_array($owners[0])) {
        $owners[0]['email'] = '';
        $contacts[$blankId]['owners'] = $owners;
    }
    $contacts[$blankId]['email'] = '';
});
if (function_exists('jeab_reminder_contacts_bump')) {
    jeab_reminder_contacts_bump();
}
if (function_exists('jeab_contacts_sole_cache_forget')) {
    jeab_contacts_sole_cache_forget();
}

$copy = [
    'title' => 'Fel titel',
    'customer_name' => 'Kopia Påminnelse',
    'email' => 'kopia.paminnelse@example.test',
    'phone' => '0701000183',
    'address' => 'Kopiagatan 4, Felort',
    'ort' => 'Felort',
    'postal' => '99999',
    'note' => 'Behåll anteckningen',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 09:00:00',
    'created_at' => $now,
    'updated_at' => $now,
];
$linked = $store->insertReminder($copy);
$loose = $store->insertReminder($copy);
$dangling = $store->insertReminder($copy + ['contact_id' => 'kt_borta']);
$partial = $store->insertReminder($copy);
rp_ok(jeab_kontakt_stamp_reminder($store, (int) $linked['id'], $adaId), 'påminnelsen får contact_id');
rp_ok(jeab_kontakt_stamp_reminder($store, (int) $partial['id'], $blankId), 'delvis påminnelse får contact_id');
$adaFresh = jeab_kontakt_get($adaId);
$adaEmail = is_array($adaFresh) ? jeab_kontakt_row_email($adaFresh) : '';
$adaPhone = is_array($adaFresh) ? jeab_kontakt_row_phone($adaFresh) : '';
rp_ok($adaEmail === 'ada.malad@example.test' && $adaPhone !== '' && $adaPhone !== '0701000183', 'kontakten har egen e-post och telefon');
$stored = $store->getReminder((int) $linked['id']);
$stamp = (string) ($stored['updated_at'] ?? '');
rp_ok(is_array($stored) && (string) ($stored['contact_id'] ?? '') === $adaId, 'raden är länkad');

$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
$off = jeab_contacts_sole_paint_reminder($stored);
rp_ok($off == $stored, 'flaggan av lämnar påminnelsens kopia');
rp_ok(!array_key_exists('contact_link_missing', $off), 'flaggan av sätter ingen saknad-flagga');
$offShown = jeab_reminder_shown_customer($stored);
rp_ok($offShown['name'] === 'Ada Målad' && $offShown['street'] === 'Malargatan 12', 'flaggan av använder redan länkad kontakt som förut');
$offLoose = jeab_reminder_shown_customer($store->getReminder((int) $loose['id']));
rp_ok($offLoose['name'] === 'Kopia Påminnelse' && str_contains($offLoose['display'], 'Kopiagatan 4'), 'flaggan av visar kopian utan länk');
$offCard = jeab_reminder_row($stored, false);
rp_ok(str_contains($offCard, 'Ada Målad') && str_contains($offCard, 'Malargatan 12'), 'stängt kort med flaggan av är oförändrat');

$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$painted = jeab_contacts_sole_paint_reminder($stored);
rp_ok($painted['customer_name'] === 'Ada Målad', 'flaggan på målar namnet');
rp_ok($painted['email'] === $adaEmail && $painted['phone'] === $adaPhone, 'flaggan på målar e-post och telefon');
rp_ok(str_contains((string) $painted['address'], 'Malargatan 12') && !str_contains((string) $painted['address'], 'Kopiagatan'), 'flaggan på målar adressen');
rp_ok((string) ($painted['ort'] ?? '') === 'Teststad', 'flaggan på målar orten');
rp_ok((string) $painted['note'] === 'Behåll anteckningen' && (string) $painted['title'] === 'Fel titel' && (string) $painted['due_at'] === '2026-10-24 09:00:00', 'anteckning, titel och datum målas inte');
rp_ok((string) $painted['contact_id'] === $adaId, 'paint skriver inte om contact_id');
$again = $store->getReminder((int) $linked['id']);
rp_ok(is_array($again) && (string) $again['customer_name'] === 'Kopia Påminnelse' && (string) $again['email'] === 'kopia.paminnelse@example.test', 'paint skriver inte kopian');
rp_ok((string) ($again['updated_at'] ?? '') === $stamp, 'paint rör inte updated_at');

$bare = jeab_contacts_sole_paint_reminder($store->getReminder((int) $loose['id']));
rp_ok(($bare['contact_link_missing'] ?? false) === true && $bare['customer_name'] === 'Kopia Påminnelse' && $bare['email'] === 'kopia.paminnelse@example.test', 'utan länk behålls kopian');
$gone = jeab_contacts_sole_paint_reminder($store->getReminder((int) $dangling['id']));
rp_ok(($gone['contact_link_missing'] ?? false) === true && $gone['phone'] === '0701000183' && str_contains((string) $gone['address'], 'Kopiagatan'), 'trasig länk lämnar kopian');
$partPaint = jeab_contacts_sole_paint_reminder($store->getReminder((int) $partial['id']));
rp_ok($partPaint['customer_name'] === 'Blank Målad' && $partPaint['email'] === 'kopia.paminnelse@example.test', 'tom e-post på kontakten skriver inte över kopian');

$shown = jeab_reminder_shown_customer($stored);
rp_ok($shown['linked'] === true && $shown['name'] === 'Ada Målad' && $shown['email'] === $adaEmail && $shown['phone'] === $adaPhone, 'visningen läser kontakten');
rp_ok($shown['street'] === 'Malargatan 12' && $shown['city'] === 'Teststad' && !str_contains($shown['display'], 'Kopiagatan'), 'visningen läser gata och ort');
$shut = jeab_reminder_row($stored, false);
$open = jeab_reminder_row($stored, true);
rp_ok(str_contains($shut, 'Ada Målad') && str_contains($shut, 'Malargatan 12') && !str_contains($shut, 'Kopia Påminnelse') && !str_contains($shut, 'Kopiagatan'), 'stängt kort visar kontakten');
rp_ok(str_contains($open, 'value="Ada Målad"') && str_contains($open, $adaEmail) && str_contains($open, $adaPhone) && str_contains($open, 'Malargatan 12'), 'öppet kort visar kontakten');
rp_ok(!str_contains($open, 'Kopia Påminnelse') && !str_contains($open, 'kopia.paminnelse@example.test') && str_contains($open, 'Behåll anteckningen'), 'öppet kort lämnar kopians namn och behåller anteckningen');
$afterHtml = $store->getReminder((int) $linked['id']);
rp_ok(is_array($afterHtml) && (string) $afterHtml['customer_name'] === 'Kopia Påminnelse' && (string) ($afterHtml['updated_at'] ?? '') === $stamp, 'kortet skriver inte raden');

$page = [];
for ($i = 0; $i < 16; $i++) {
    $n = 200 + $i;
    $contact = jeab_kontakt_find_or_create([
        'name' => 'Sida ' . $n,
        'email' => 'sida' . $n . '@example.test',
        'phone' => '0701000' . str_pad((string) $n, 3, '0', STR_PAD_LEFT),
        'address' => 'Sidgatan ' . $n . ', 111 22 Teststad',
        'city' => 'Teststad',
        'postal_code' => '11122',
    ], false);
    rp_ok(is_array($contact), 'sidkontakt ' . $n);
    $row = $store->insertReminder([
        'title' => 'Sida ' . $n,
        'customer_name' => 'Kopia ' . $n,
        'email' => 'kopia' . $n . '@example.test',
        'phone' => '0701999' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
        'address' => 'Kopiasida ' . $n,
        'company' => 'jarfalla',
        'due_at' => '2026-10-24 10:00:00',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    rp_ok(jeab_kontakt_stamp_reminder($store, (int) $row['id'], (string) $contact['id']), 'sidlänk ' . $n);
    $fresh = $store->getReminder((int) $row['id']);
    rp_ok(is_array($fresh), 'sidrad ' . $n);
    $page[] = $fresh;
}

unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$GLOBALS['_JEAB_CONTACTS_SOLE_GETS'] = 0;
$GLOBALS['_JEAB_CONTACTS_SOLE_ID_QUERIES'] = 0;
$coldStarted = microtime(true);
foreach ($page as $row) {
    $one = jeab_reminder_shown_customer($row);
    rp_ok(str_starts_with($one['name'], 'Sida '), 'kall visning läser kontakten');
}
$cold = microtime(true) - $coldStarted;
$coldGets = (int) $GLOBALS['_JEAB_CONTACTS_SOLE_GETS'];
$coldQueries = (int) $GLOBALS['_JEAB_CONTACTS_SOLE_ID_QUERIES'];
rp_ok($coldGets === 16 && $coldQueries === 0, 'utan förhandshämtning blir det ett get per kort, fick ' . $coldGets . ' get och ' . $coldQueries . ' frågor');

unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE'], $GLOBALS['_JEAB_REMINDER_CONTACT_BY_ID']);
$GLOBALS['_JEAB_CONTACTS_SOLE_GETS'] = 0;
$GLOBALS['_JEAB_CONTACTS_SOLE_ID_QUERIES'] = 0;
$warmStarted = microtime(true);
jeab_reminder_preload_contacts($page);
foreach ($page as $row) {
    $one = jeab_reminder_shown_customer($row);
    rp_ok(str_starts_with($one['name'], 'Sida '), 'varm visning läser kontakten');
}
$warm = microtime(true) - $warmStarted;
$warmGets = (int) $GLOBALS['_JEAB_CONTACTS_SOLE_GETS'];
$warmQueries = (int) $GLOBALS['_JEAB_CONTACTS_SOLE_ID_QUERIES'];
rp_ok($warmQueries === 1 && $warmGets === 0, 'en sida är en fråga och inga get, fick ' . $warmQueries . ' frågor och ' . $warmGets . ' get');
rp_ok($warm <= $cold + 0.05, 'förhandshämtningen är inte långsammare (' . number_format($warm, 4) . ' s mot ' . number_format($cold, 4) . ' s)');
echo 'TIME cold ' . number_format($cold, 4) . 's warm ' . number_format($warm, 4) . "s\n";
foreach ($page as $row) {
    $shut = jeab_reminder_row($row, false);
    $open = jeab_reminder_row($row, true);
    rp_ok(str_contains($shut, 'Sida ') && str_contains($open, '@example.test') && !str_contains($shut, 'Kopia '), 'sidans kort visar kontakten');
}
rp_ok((int) $GLOBALS['_JEAB_CONTACTS_SOLE_ID_QUERIES'] === 1 && (int) $GLOBALS['_JEAB_CONTACTS_SOLE_GETS'] === 0, 'korten läser den redan hämtade kontakten');

$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
$offAgain = jeab_contacts_sole_paint_reminder($page[0]);
rp_ok($offAgain == $page[0], 'flaggan av igen lämnar sidraden');
rp_ok(jeab_reminder_shown_customer($page[0])['name'] === 'Sida 200', 'flaggan av igen visar den länkade kontakten som förut');

echo "reminders sole paint ok\n";
