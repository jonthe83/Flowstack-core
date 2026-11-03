<?php

declare(strict_types=1);

/**
 * contact_id på inkorg_list skapas i tick, inte på en sidvisning.
 * En ny länk skriver om kolumnen, så listan inte visar den gamla kontakten.
 * Påminnelse, bokning och order behåller namn, e-post, telefon och adress.
 */

$tmp = sys_get_temp_dir() . '/jeab-kt-list-link-' . bin2hex(random_bytes(4));
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

function kll(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/** @return list<string> */
function kll_case_ids(string $contactId): array
{
    $ids = [];
    foreach (jeab_hs_import_threads_for_contact($contactId) as $case) {
        $ids[] = (string) ($case['id'] ?? '');
    }
    sort($ids);

    return $ids;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$store = jeab_store();
kll($store instanceof JeabPdoStore, 'sqlite-lagret finns');
$app = $store->pdo();
jeab_ensure_inkorg_tables($app, 'sqlite');
kll(jeab_settings_value($app, 'sqlite', 'inkorg_list_contact_v2') === 'ok', 'migrate sätter v2');

$fresh = new PDO('sqlite:' . $tmp . '/ddl.sqlite', null, null, jeab_pdo_base_options());
$fresh->exec('CREATE TABLE settings ("key" TEXT PRIMARY KEY, value TEXT NOT NULL)');
$fresh->exec(
    'CREATE TABLE inkorg_list (
        id TEXT PRIMARY KEY,
        company TEXT NOT NULL DEFAULT \'\',
        customer_email TEXT NOT NULL DEFAULT \'\',
        list_at TEXT NOT NULL DEFAULT \'\',
        list_status TEXT NOT NULL DEFAULT \'\',
        row_json TEXT NOT NULL
    )'
);
$fresh->prepare(
    'INSERT INTO inkorg_list (id, company, customer_email, list_at, list_status, row_json) VALUES (?, ?, ?, ?, ?, ?)'
)->execute([
    't-fore',
    'jarfalla',
    'fore@example.test',
    '2026-10-01 10:00:00',
    'open',
    json_encode([
        'id' => 't-fore',
        'contact_id' => 'kt_rad',
        'status' => 'open',
        'subject' => 'Före tick',
        'customer_name' => 'Oförändrad',
        'customer_email' => 'fore@example.test',
        'first' => ['subject' => 'Före tick'],
    ], JSON_UNESCAPED_UNICODE),
]);

$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
unset($GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_SCHEMA_DDL'], $GLOBALS['_JEAB_MIGRATE_RUNS']);
$before = (int) ($GLOBALS['_JEAB_INKORG_LIST_CONTACT_DDL'] ?? 0);
kll(jeab_inkorg_ensure_list_contact($fresh, 'sqlite') === false, 'sidan skapar inte kolumnen');
$names = [];
foreach ($fresh->query('PRAGMA table_info(inkorg_list)')->fetchAll() as $col) {
    $names[] = (string) ($col['name'] ?? '');
}
kll(!in_array('contact_id', $names, true), 'sidan lämnar tabellen utan contact_id');
kll((int) ($GLOBALS['_JEAB_INKORG_LIST_CONTACT_DDL'] ?? 0) === $before, 'sidan kör ingen DDL');
kll(jeab_settings_value($fresh, 'sqlite', 'inkorg_list_contact_v2') !== 'ok', 'sidan sätter inte v2');

$GLOBALS['_JEAB_TICK'] = true;
kll(jeab_inkorg_ensure_list_contact($fresh, 'sqlite') === true, 'tick skapar kolumnen och fyller den');
kll((int) ($GLOBALS['_JEAB_INKORG_LIST_CONTACT_DDL'] ?? 0) === $before + 1, 'tick räknas som DDL');
kll((string) $fresh->query('SELECT contact_id FROM inkorg_list WHERE id = \'t-fore\'')->fetchColumn() === 'kt_rad', 'tick kopierar id från raden');
$kept = json_decode((string) $fresh->query('SELECT row_json FROM inkorg_list WHERE id = \'t-fore\'')->fetchColumn(), true);
kll(is_array($kept)
    && (string) ($kept['customer_name'] ?? '') === 'Oförändrad'
    && (string) ($kept['customer_email'] ?? '') === 'fore@example.test'
    && (string) ($kept['subject'] ?? '') === 'Före tick', 'påfyllnaden rör inte namn, e-post eller rubrik');

$fresh->prepare('UPDATE inkorg_list SET contact_id = ? WHERE id = ?')->execute(['kt_gammal', 't-fore']);
$fresh->prepare('DELETE FROM settings WHERE "key" = ?')->execute(['inkorg_list_contact_v2']);
$again = new PDO('sqlite:' . $tmp . '/ddl.sqlite', null, null, jeab_pdo_base_options());
kll(jeab_inkorg_ensure_list_contact($again, 'sqlite') === true, 'tick rättar en kolumn som pekar fel');
kll((string) $again->query('SELECT contact_id FROM inkorg_list WHERE id = \'t-fore\'')->fetchColumn() === 'kt_rad', 'kolumnen följer radens kontakt');

unset($GLOBALS['_JEAB_PAGE_REQUEST'], $GLOBALS['_JEAB_TICK']);
$GLOBALS['_JEAB_SAPI'] = 'cli';
$live = jeab_inkorg_sql_store_live();
kll($live instanceof JeabPdoInkorgStore, 'inkorgens lager finns');
$live->saveListRow([
    'id' => 't-lank',
    'company' => 'jarfalla',
    'contact_id' => 'kt_a',
    'status' => 'open',
    'updated_at' => '2026-10-03 10:00:00',
    'customer_email' => 'a@example.test',
    'customer_name' => 'Namn A',
    'subject' => 'Första länken',
    'first' => ['subject' => 'Första länken'],
]);
kll(kll_case_ids('kt_a') === ['t-lank'], 'första länken syns');
$live->saveListRow([
    'id' => 't-lank',
    'company' => 'jarfalla',
    'contact_id' => 'kt_b',
    'status' => 'open',
    'updated_at' => '2026-10-03 11:00:00',
    'customer_email' => 'a@example.test',
    'customer_name' => 'Namn A',
    'subject' => 'Första länken',
    'first' => ['subject' => 'Första länken'],
]);
kll(kll_case_ids('kt_a') === [] && kll_case_ids('kt_b') === ['t-lank'], 'ny listlänk tar bort den gamla kontakten');
kll($live->assignListContact('t-lank', 'kt_c') === true, 'en senare länk skrivs på kolumnen');
kll(kll_case_ids('kt_b') === [] && kll_case_ids('kt_c') === ['t-lank'], 'jeab_hs_import_threads_for_contact följer den nya länken');
$afterLink = json_decode((string) $app->query('SELECT row_json FROM inkorg_list WHERE id = \'t-lank\'')->fetchColumn(), true);
kll(is_array($afterLink)
    && (string) ($afterLink['contact_id'] ?? '') === 'kt_c'
    && (string) ($afterLink['customer_name'] ?? '') === 'Namn A'
    && (string) ($afterLink['customer_email'] ?? '') === 'a@example.test'
    && (string) ($afterLink['subject'] ?? '') === 'Första länken', 'länken ändrar inte namn, e-post eller rubrik');

$hid = 'hsi-0123456789abcdef';
$live->saveListRow([
    'id' => $hid,
    'company' => 'jarfalla',
    'contact_id' => 'kt_gammal',
    'status' => 'open',
    'updated_at' => '2026-10-04 10:00:00',
    'customer_name' => 'Help Scout',
    'customer_email' => 'hs@example.test',
    'phone' => '0701000000',
    'subject' => 'Importerat',
    'first' => ['subject' => 'Importerat'],
]);
$saved = jeab_hs_import_save_conv([
    'id' => $hid,
    'company' => 'jarfalla',
    'status' => 'open',
    'contact_id' => 'kt_ny',
    'messages' => [],
    'meta' => ['contact_id' => 'kt_ny', 'status' => 'open'],
]);
kll($saved === true, 'Help Scout-ärendet sparas');
kll(kll_case_ids('kt_gammal') === [] && kll_case_ids('kt_ny') === [$hid], 'importens nya länk uppdaterar listan');
$hsRow = json_decode((string) $app->query('SELECT row_json FROM inkorg_list WHERE id = ' . $app->quote($hid))->fetchColumn(), true);
kll(is_array($hsRow)
    && (string) ($hsRow['customer_name'] ?? '') === 'Help Scout'
    && (string) ($hsRow['customer_email'] ?? '') === 'hs@example.test'
    && (string) ($hsRow['phone'] ?? '') === '0701000000', 'importlänken lämnar namn, e-post och telefon');

$now = jeab_now();
$rem = $store->insertReminder([
    'title' => 'Kvar',
    'customer_name' => 'Kvar Namn',
    'email' => 'kvar@example.test',
    'phone' => '0701222333',
    'address' => 'Kvarvägen 2',
    'company' => 'jarfalla',
    'due_at' => '2026-10-22 09:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$remRow = $store->getReminder((int) $rem['id']);
kll(is_array($remRow)
    && (string) ($remRow['customer_name'] ?? '') === 'Kvar Namn'
    && (string) ($remRow['email'] ?? '') === 'kvar@example.test'
    && (string) ($remRow['phone'] ?? '') === '0701222333'
    && (string) ($remRow['address'] ?? '') === 'Kvarvägen 2', 'påminnelsens fält rörs inte av listlänken');

$tickSrc = (string) file_get_contents(dirname(__DIR__) . '/src/tick.php');
$contactCall = strpos($tickSrc, 'jeab_inkorg_ensure_list_contact($pdo, $driver)');
$historyCall = strpos($tickSrc, 'jeab_notiser_history_refresh()');
kll($contactCall !== false && $historyCall !== false && $contactCall < $historyCall, 'tick kör listlänken och sedan utskickshistoriken');

echo "kontakter list link ok\n";
