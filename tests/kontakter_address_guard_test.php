<?php

declare(strict_types=1);

/**
 * E-post och telefon slår inte ihop kontakter när båda adresserna finns och skiljer sig.
 * Saknas adressen på ena sidan återanvänds kontakten.
 * gap_fill hoppar över ett mejl som redan finns på en annan adress.
 * kontakter.restore_contact_from_backup återskapar en rad utan att visa personnummer.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-address-guard-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
session_save_path($tmp);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
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
jeab_ordrar_external_live(false);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = [];

function ag(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function ag_log(): string
{
    $path = jeab_app_errors_path();

    return is_file($path) ? (string) file_get_contents($path) : '';
}

$secretPnr = '199001011234';
$store = jeab_store();
ag($store instanceof JeabPdoStore, 'sqlite-lagret finns');
jeab_ensure_contacts_tables($store->pdo(), 'sqlite');

$left = jeab_kontakt_find_or_create([
    'name' => 'Ada Adress',
    'email' => 'ada.adress@example.test',
    'phone' => '0701000401',
    'address' => 'Adressgatan 1',
    'postal_code' => '11122',
    'city' => 'Teststad',
], false, true);
$right = jeab_kontakt_find_or_create([
    'name' => 'Ada Adress',
    'email' => 'ada.adress@example.test',
    'phone' => '0701000401',
    'address' => 'Annanvagen 9',
    'postal_code' => '22233',
    'city' => 'Annanort',
], false, true);
ag(is_array($left) && is_array($right) && (string) $left['id'] !== (string) $right['id'], 'olika adress och samma e-post blir två kontakter');
ag(isset(jeab_kontakter_load()[(string) $left['id']], jeab_kontakter_load()[(string) $right['id']]), 'find_or_create lämnar båda kvar');

$saved = jeab_kontakt_save_input([
    'street' => 'Spargatan 4',
    'postal_code' => '33344',
    'city' => 'Teststad',
    'owner_name_1' => 'Bo Spar',
    'email' => 'ada.adress@example.test',
    'phone' => '0701000402',
    'contact_person' => '1',
]);
ag((string) ($saved['id'] ?? '') !== '' && (string) $saved['id'] !== (string) $left['id'], 'save_input skapar en egen rad när adressen skiljer');
ag(isset(jeab_kontakter_load()[(string) $left['id']], jeab_kontakter_load()[(string) $saved['id']]), 'save_input slår inte ihop olika adresser');

$same = jeab_kontakt_find_or_create([
    'name' => 'Cajsa Samma',
    'email' => 'cajsa.samma@example.test',
    'phone' => '0701000403',
    'address' => 'Sammagatan 2',
    'postal_code' => '44455',
    'city' => 'Teststad',
], false, true);
$sameAgain = jeab_kontakt_find_or_create([
    'name' => 'Cajsa Samma',
    'email' => 'cajsa.samma@example.test',
    'phone' => '0701000403',
    'address' => 'Samma gatan 2',
    'postal_code' => '44455',
    'city' => 'Annanort',
], false, true);
ag(is_array($same) && is_array($sameAgain) && (string) $same['id'] === (string) $sameAgain['id'], 'samma gata och postnummer återanvänds');

$plan = jeab_kontakt_dedupe_plan(jeab_kontakter_load(), $store);
$badMerge = false;
foreach ($plan['merges'] as $merge) {
    $pair = [(string) ($merge['from'] ?? ''), (string) ($merge['to'] ?? '')];
    sort($pair);
    $blocked = [(string) $left['id'], (string) $right['id']];
    sort($blocked);
    if ($pair === $blocked) {
        $badMerge = true;
    }
}
ag($badMerge === false, 'dedupe-planen tar inte med olika adresser');

$phoneHit = jeab_kontakt_find(
    jeab_kontakter_load(),
    jeab_kontakt_street_key('Frammandegatan 12'),
    '99988',
    '',
    '0701000401',
    false,
    'Ada Adress',
    'Teststad'
);
ag($phoneHit === null, 'samma telefon och annan adress är ingen träff');
$phoneSame = jeab_kontakt_find(
    jeab_kontakter_load(),
    jeab_kontakt_street_key('Adressgatan 1'),
    '11122',
    '',
    '0701000401',
    false,
    'Ada Adress',
    'Teststad'
);
ag($phoneSame === (string) $left['id'], 'samma telefon och samma adress träffar');

$missing = jeab_kontakt_find_or_create([
    'name' => 'Dag Utan',
    'email' => 'dag.utan@example.test',
    'phone' => '0701000404',
    'address' => 'Utangatan 3',
    'city' => 'Teststad',
], false, true);
$missingPeer = jeab_kontakt_find_or_create([
    'name' => 'Dag Utan',
    'email' => 'dag.utan@example.test',
    'phone' => '0701000404',
    'address' => 'Postgatan 8',
    'postal_code' => '55566',
    'city' => 'Teststad',
], false, true);
ag(is_array($missing) && is_array($missingPeer) && (string) $missing['id'] === (string) $missingPeer['id'], 'saknat postnummer återanvänder kontakten på e-post');

$now = jeab_now();
$reminder = $store->insertReminder([
    'title' => 'Adressvakt',
    'customer_name' => 'Ada Adress',
    'email' => 'ada.adress@example.test',
    'phone' => '0701000401',
    'address' => 'Adressgatan 1',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 09:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$store->pdo()->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute([(string) $right['id'], (int) $reminder['id']]);
$beforeLink = (string) ($store->getReminder((int) $reminder['id'])['contact_id'] ?? '');
$finished = jeab_kontakt_dedupe_finish([
    ['from' => (string) $right['id'], 'to' => (string) $left['id'], 'rule' => 'email', 'reason' => 'samma e-post'],
], $store);
ag($finished === 0, 'dedupe_finish flyttar ingen länk när adresserna skiljer');
ag((string) ($store->getReminder((int) $reminder['id'])['contact_id'] ?? '') === $beforeLink, 'påminnelsen stannar');
ag(isset(jeab_kontakter_load()[(string) $left['id']], jeab_kontakter_load()[(string) $right['id']]), 'dedupe_finish tar inte bort någon av kontakterna');

$log = ag_log();
ag(str_contains($log, '"level":"info"') && str_contains($log, 'olika adress'), 'hoppad hopslagning skrivs som info i fellogg');
ag(!str_contains($log, $secretPnr) && !str_contains(strtolower($log), 'personnummer'), 'felloggen har inget personnummer');

jeab_kontakter_mutate(static function (array &$contacts) use ($left, $secretPnr): void {
    $id = (string) $left['id'];
    if (!isset($contacts[$id]) || !is_array($contacts[$id])) {
        return;
    }
    $owners = is_array($contacts[$id]['owners'] ?? null) ? $contacts[$id]['owners'] : [];
    if (isset($owners[0]) && is_array($owners[0])) {
        $owners[0]['personnummer'] = $secretPnr;
        $owners[0]['email'] = '';
    }
    $contacts[$id]['owners'] = $owners;
});
if (function_exists('jeab_contacts_sole_cache_forget')) {
    jeab_contacts_sole_cache_forget((string) $left['id']);
}
$store->pdo()->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute([(string) $left['id'], (int) $reminder['id']]);
$dryGap = jeab_contacts_gap_fill(['dry' => 1], $store);
$dryJson = json_encode($dryGap, JSON_UNESCAPED_UNICODE) ?: '';
$skipped = false;
$stillEmail = false;
foreach (is_array($dryGap['skipped_duplicate_email'] ?? null) ? $dryGap['skipped_duplicate_email'] : [] as $row) {
    if (!is_array($row)) {
        continue;
    }
    $other = (string) ($row['other_contact_id'] ?? '');
    if ((string) ($row['table'] ?? '') === 'reminders' && (int) ($row['id'] ?? 0) === (int) $reminder['id'] && ($other === (string) $right['id'] || $other === (string) $saved['id'])) {
        $skipped = true;
    }
}
foreach (is_array($dryGap['rows'] ?? null) ? $dryGap['rows'] : [] as $row) {
    if (!is_array($row) || (string) ($row['table'] ?? '') !== 'reminders' || (int) ($row['id'] ?? 0) !== (int) $reminder['id']) {
        continue;
    }
    $fields = is_array($row['fields'] ?? null) ? $row['fields'] : [];
    $stillEmail = in_array('email', $fields, true);
}
ag(!empty($dryGap['ok']) && !empty($dryGap['dry']) && $skipped && $stillEmail === false, 'gap_fill dry rapporterar skipped_duplicate_email');
ag(!str_contains($dryJson, $secretPnr) && !str_contains(strtolower($dryJson), 'personnummer'), 'gap_fill-svaret har inget personnummer');
$applyGap = jeab_contacts_gap_fill(['dry' => 0], $store);
ag(jeab_kontakt_row_email(jeab_kontakt_get((string) $left['id']) ?: []) === '', 'gap_fill skriver inte det upptagna mejlet');
ag(!str_contains(json_encode($applyGap, JSON_UNESCAPED_UNICODE) ?: '', $secretPnr), 'gap_fill-skrivningen visar inget personnummer');

$restoreId = 'kt_restore_adress';
$backup = 'kontakter.bak-20261024-120000.json';
file_put_contents($tmp . '/' . $backup, json_encode([
    'contacts' => [
        $restoreId => [
            'id' => $restoreId,
            'street' => 'Bakgatan 6',
            'street_key' => jeab_kontakt_street_key('Bakgatan 6'),
            'postal_code' => '66677',
            'city' => 'Teststad',
            'owners' => [[
                'name' => 'Eva Bak',
                'personnummer' => $secretPnr,
                'contact' => true,
                'email' => 'eva.bak@example.test',
                'phone' => '0701000405',
            ]],
        ],
    ],
], JSON_UNESCAPED_UNICODE));
$restoreReminder = $store->insertReminder([
    'title' => 'Aterstall',
    'customer_name' => 'Eva Bak',
    'email' => 'eva.bak@example.test',
    'phone' => '0701000405',
    'address' => 'Bakgatan 6',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 11:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$store->pdo()->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute(['kt_annan', (int) $restoreReminder['id']]);
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-address-guard']));
$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = jeab_contacts_sole_dispatch([
    'action' => 'kontakter.restore_contact_from_backup',
    'contact_id' => $restoreId,
    'backup' => $backup,
]);
ag(($denied['status'] ?? 0) === 401 && empty($denied['ok']), 'restore utan token nekas');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-address-guard';
$dryRestore = jeab_contacts_sole_dispatch([
    'action' => 'kontakter.restore_contact_from_backup',
    'contact_id' => $restoreId,
    'backup' => $backup,
    'relink' => [['table' => 'reminders', 'id' => (int) $restoreReminder['id']]],
    'clear_fields' => ['email'],
]);
$dryRestoreJson = json_encode($dryRestore, JSON_UNESCAPED_UNICODE) ?: '';
ag(!empty($dryRestore['ok']) && !empty($dryRestore['dry']) && empty($dryRestore['written']), 'restore utan dry är torrt');
ag(jeab_kontakt_get($restoreId) === null, 'torrkörningen skapar ingen kontakt');
ag(!str_contains($dryRestoreJson, $secretPnr) && !str_contains(strtolower($dryRestoreJson), 'personnummer'), 'torrkörningen visar inget personnummer');
$noConfirm = jeab_contacts_sole_dispatch([
    'action' => 'kontakter.restore_contact_from_backup',
    'contact_id' => $restoreId,
    'backup' => $backup,
    'dry' => 0,
    'relink' => [['table' => 'reminders', 'id' => (int) $restoreReminder['id']]],
    'clear_fields' => ['email'],
]);
ag(empty($noConfirm['ok']) && str_contains((string) ($noConfirm['error'] ?? ''), 'SAM_YES') && jeab_kontakt_get($restoreId) === null, 'dry=0 utan confirm skriver inget');
$wetRestore = jeab_contacts_sole_dispatch([
    'action' => 'kontakter.restore_contact_from_backup',
    'contact_id' => $restoreId,
    'backup' => $backup,
    'dry' => 0,
    'confirm' => 'SAM_YES',
    'relink' => [['table' => 'reminders', 'id' => (int) $restoreReminder['id']]],
    'clear_fields' => ['email'],
]);
$wetJson = json_encode($wetRestore, JSON_UNESCAPED_UNICODE) ?: '';
ag(!empty($wetRestore['ok']) && !empty($wetRestore['written']) && trim((string) ($wetRestore['batch_id'] ?? '')) !== '', 'restore skriver och ger batch_id');
$restored = jeab_kontakt_get($restoreId);
ag(is_array($restored) && (string) ($restored['street'] ?? '') === 'Bakgatan 6', 'kontakten återskapas från kopian');
ag(jeab_kontakt_row_email($restored) === '' && jeab_kontakt_row_name($restored) === 'Eva Bak', 'angivet fält töms och namnet blir kvar');
ag((string) ($store->getReminder((int) $restoreReminder['id'])['contact_id'] ?? '') === $restoreId, 'påminnelsen pekar på den återskapade kontakten');
$undoRows = [];
foreach (jeab_contacts_sole_undo_all() as $undoRow) {
    if ((string) ($undoRow['batch_id'] ?? '') === (string) $wetRestore['batch_id']) {
        $undoRows[] = $undoRow;
    }
}
$undoJson = json_encode($undoRows, JSON_UNESCAPED_UNICODE) ?: '';
$kinds = [];
foreach ($undoRows as $undoRow) {
    $kinds[] = (string) ($undoRow['kind'] ?? '');
}
sort($kinds);
ag($kinds === ['contact', 'link'], 'ångerbatchen har kontakten och länken');
ag(!str_contains($wetJson, $secretPnr) && !str_contains(strtolower($wetJson), 'personnummer'), 'restore-svaret har inget personnummer');
ag(!str_contains($undoJson, $secretPnr) && !str_contains(strtolower($undoJson), 'personnummer'), 'ångerbatchen har inget personnummer');
ag(!str_contains(ag_log(), $secretPnr) && !str_contains(strtolower(ag_log()), 'personnummer'), 'felloggen har fortfarande inget personnummer');

echo "OK\n";
