<?php

declare(strict_types=1);

/**
 * kontakter.restore_contact_from_backup läser privat/backups/YYYYMMDD-HHMMSS
 * som migrate.backup skriver, och den äldre kontakter.bak-filen.
 * Inget personnummer i svaret.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$home = sys_get_temp_dir() . '/jeab-restore-stamp-' . bin2hex(random_bytes(4));
$root = $home . '/public_html';
$data = $root . '/data';
mkdir($data . '/secrets', 0775, true);
mkdir($home . '/privat/backups', 0700, true);
session_save_path($home);
$GLOBALS['_JEAB_APP_ROOT'] = $root;
$GLOBALS['_JEAB_DATA_DIR'] = $data;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $data;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $data;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
putenv('JEAB_APP_ROOT=' . $root);
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
putenv('CONTACTS_SOLE=0');
file_put_contents($data . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $data . '/app.sqlite',
        'json_path' => $data . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
    'incoming_webhook_token' => 'tok-restore-stamp',
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
jeab_ordrar_external_live(false);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = [];

function rs(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$secretPnr = '199001011234';
$store = jeab_store();
rs($store instanceof JeabPdoStore, 'sqlite-lagret finns');
jeab_ensure_contacts_tables($store->pdo(), 'sqlite');
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-restore-stamp']));
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-restore-stamp';

$stamp = '20261024-120000';
$stampId = 'kt_stamp_adress';
$stampContact = [
    'id' => $stampId,
    'street' => 'Stampgatan 1',
    'street_key' => jeab_kontakt_street_key('Stampgatan 1'),
    'postal_code' => '11122',
    'city' => 'Teststad',
    'owners' => [[
        'name' => 'Stina Stamp',
        'personnummer' => $secretPnr,
        'contact' => true,
        'email' => 'stina.stamp@example.test',
        'phone' => '0701000501',
    ]],
];
$stampDir = $home . '/privat/backups/' . $stamp;
mkdir($stampDir . '/tables', 0700, true);
mkdir($stampDir . '/files/data', 0700, true);
$line = json_encode([
    'id' => $stampId,
    'row_json' => json_encode($stampContact, JSON_UNESCAPED_UNICODE),
    'street_key' => 'felgata 9',
    'postal_code' => '99999',
    'owner_email' => 'annan@example.test',
    'owner_phone' => '',
    'owner_name_fold' => 'fel',
    'created_at' => '2026-10-24 12:00:00',
    'updated_at' => '2026-10-24 12:00:00',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($stampDir . '/tables/contacts.jsonl', $line . "\n");
file_put_contents($stampDir . '/files/data/kontakter.json', json_encode([
    'contacts' => [
        $stampId => [
            'id' => $stampId,
            'street' => 'Filgatan 2',
            'city' => 'Filstad',
            'owners' => [[
                'name' => 'Fil Filsson',
                'contact' => true,
                'email' => 'fil@example.test',
                'phone' => '',
            ]],
        ],
    ],
], JSON_UNESCAPED_UNICODE));
file_put_contents($stampDir . '/manifest.json', json_encode([
    'version' => 1,
    'stamp' => $stamp,
    'tables' => [[
        'table' => 'contacts',
        'exists' => true,
        'stored' => 'tables/contacts.jsonl',
        'columns' => ['id', 'row_json', 'street_key', 'postal_code', 'owner_email', 'owner_phone', 'owner_name_fold', 'created_at', 'updated_at'],
    ]],
    'files' => [[
        'path' => 'data/kontakter.json',
        'exists' => true,
        'stored' => 'files/data/kontakter.json',
    ]],
], JSON_UNESCAPED_UNICODE));

$brokenStamp = '20261024-120003';
$brokenDir = $home . '/privat/backups/' . $brokenStamp;
mkdir($brokenDir . '/tables', 0700, true);
mkdir($brokenDir . '/files/data', 0700, true);
file_put_contents($brokenDir . '/tables/contacts.jsonl', "inte-json\n");
file_put_contents($brokenDir . '/files/data/kontakter.json', json_encode([
    'contacts' => [
        $stampId => [
            'id' => $stampId,
            'street' => 'Trasigvagen 4',
            'city' => 'Teststad',
            'owners' => [[
                'name' => 'Trasig Rad',
                'contact' => true,
                'email' => 'trasig@example.test',
                'phone' => '',
            ]],
        ],
    ],
], JSON_UNESCAPED_UNICODE));

$fileStamp = '20261024-120001';
$fileId = 'kt_fil_adress';
$fileDir = $home . '/privat/backups/' . $fileStamp . '/files/data';
mkdir($fileDir, 0700, true);
file_put_contents($fileDir . '/kontakter.json', json_encode([
    'contacts' => [
        $fileId => [
            'id' => $fileId,
            'street' => 'Filgatan 8',
            'postal_code' => '22233',
            'city' => 'Teststad',
            'owners' => [[
                'name' => 'Folke Fil',
                'personnummer' => $secretPnr,
                'contact' => true,
                'email' => 'folke.fil@example.test',
                'phone' => '0701000502',
            ]],
        ],
    ],
], JSON_UNESCAPED_UNICODE));

$oldName = 'kontakter.bak-20261024-120002.json';
$oldId = 'kt_json_adress';
file_put_contents($data . '/' . $oldName, json_encode([
    'contacts' => [
        $oldId => [
            'id' => $oldId,
            'street' => 'Jsonvagen 3',
            'postal_code' => '33344',
            'city' => 'Teststad',
            'owners' => [[
                'name' => 'Julia Json',
                'personnummer' => $secretPnr,
                'contact' => true,
                'email' => 'julia.json@example.test',
                'phone' => '0701000503',
            ]],
        ],
    ],
], JSON_UNESCAPED_UNICODE));

$now = jeab_now();
$reminder = $store->insertReminder([
    'title' => 'Stamp',
    'customer_name' => 'Stina Stamp',
    'email' => 'stina.stamp@example.test',
    'phone' => '0701000501',
    'address' => 'Stampgatan 1',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 12:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$store->pdo()->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute(['kt_annan', (int) $reminder['id']]);

$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = jeab_contacts_sole_dispatch([
    'action' => 'kontakter.restore_contact_from_backup',
    'contact_id' => $stampId,
    'backup' => $stamp,
]);
rs(($denied['status'] ?? 0) === 401 && empty($denied['ok']) && jeab_kontakt_get($stampId) === null, 'stamp utan token nekas');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-restore-stamp';

foreach ([
    '../' . $stamp,
    $stamp . '/../../etc/passwd',
    'privat/backups/' . $stamp,
    '..',
    '20261024120000',
    '2026-1024-120000',
    $stamp . '.json',
    "{$stamp}\n",
] as $bad) {
    $rejected = jeab_contacts_sole_dispatch([
        'action' => 'kontakter.restore_contact_from_backup',
        'contact_id' => $stampId,
        'backup' => $bad,
        'dry' => 0,
        'confirm' => 'SAM_YES',
    ]);
    $err = (string) ($rejected['error'] ?? '');
    rs(empty($rejected['ok']) && empty($rejected['written']) && $err === 'Ogiltigt filnamn.' && jeab_kontakt_get($stampId) === null, 'ogiltigt namn skrivs inte: ' . json_encode($bad));
}

$outside = $home . '/utanfor';
mkdir($outside . '/tables', 0700, true);
file_put_contents($outside . '/tables/contacts.jsonl', $line . "\n");
symlink($outside, $home . '/privat/backups/20261024-130000');
$linked = jeab_contacts_sole_dispatch([
    'action' => 'kontakter.restore_contact_from_backup',
    'contact_id' => $stampId,
    'backup' => '20261024-130000',
    'dry' => 0,
    'confirm' => 'SAM_YES',
]);
rs(empty($linked['ok']) && empty($linked['written']) && (string) ($linked['error'] ?? '') === 'Kopian saknas.' && jeab_kontakt_get($stampId) === null, 'symbolisk länk läses inte');

$missing = jeab_contacts_sole_dispatch([
    'action' => 'kontakter.restore_contact_from_backup',
    'contact_id' => 'kt_finns_inte',
    'backup' => $stamp,
]);
rs(empty($missing['ok']) && (string) ($missing['error'] ?? '') === 'Kontakten saknas i kopian.' && empty($missing['written']), 'saknad rad i stampen skrivs inte');

$broken = jeab_contacts_sole_dispatch([
    'action' => 'kontakter.restore_contact_from_backup',
    'contact_id' => $stampId,
    'backup' => $brokenStamp,
    'dry' => 0,
    'confirm' => 'SAM_YES',
]);
rs(empty($broken['ok']) && empty($broken['written']) && (string) ($broken['error'] ?? '') === 'Kopian kunde inte läsas.' && jeab_kontakt_get($stampId) === null, 'trasig tabellfil används inte som filkopia');

$dry = jeab_contacts_sole_dispatch([
    'action' => 'kontakter.restore_contact_from_backup',
    'contact_id' => $stampId,
    'backup' => $stamp,
    'relink' => [['table' => 'reminders', 'id' => (int) $reminder['id']]],
    'clear_fields' => ['email'],
]);
$dryJson = json_encode($dry, JSON_UNESCAPED_UNICODE) ?: '';
rs(!empty($dry['ok']) && !empty($dry['dry']) && empty($dry['written']) && (string) ($dry['backup'] ?? '') === $stamp, 'stamp utan dry är torrt');
rs(jeab_kontakt_get($stampId) === null, 'torrkörningen skapar ingen kontakt');
rs(!str_contains($dryJson, $secretPnr) && !str_contains(strtolower($dryJson), 'personnummer'), 'torrkörningen visar inget personnummer');

$noConfirm = jeab_contacts_sole_dispatch([
    'action' => 'kontakter.restore_contact_from_backup',
    'contact_id' => $stampId,
    'backup' => $stamp,
    'dry' => 0,
]);
rs(empty($noConfirm['ok']) && str_contains((string) ($noConfirm['error'] ?? ''), 'SAM_YES') && jeab_kontakt_get($stampId) === null, 'stamp dry=0 utan confirm skriver inget');

$wet = jeab_contacts_sole_dispatch([
    'action' => 'kontakter.restore_contact_from_backup',
    'contact_id' => $stampId,
    'backup' => $stamp,
    'dry' => 0,
    'confirm' => 'SAM_YES',
    'relink' => [['table' => 'reminders', 'id' => (int) $reminder['id']]],
    'clear_fields' => ['email'],
]);
$wetJson = json_encode($wet, JSON_UNESCAPED_UNICODE) ?: '';
rs(!empty($wet['ok']) && !empty($wet['written']) && trim((string) ($wet['batch_id'] ?? '')) !== '', 'stamp skriver och ger batch_id');
$restored = jeab_kontakt_get($stampId);
rs(is_array($restored) && (string) ($restored['street'] ?? '') === 'Stampgatan 1' && (string) ($restored['city'] ?? '') === 'Teststad', 'kontakten läses från tabellens row_json');
rs(jeab_kontakt_row_email($restored ?: []) === '' && jeab_kontakt_row_name($restored ?: []) === 'Stina Stamp', 'mejlet töms och namnet blir kvar');
rs((string) ($store->getReminder((int) $reminder['id'])['contact_id'] ?? '') === $stampId, 'påminnelsen pekar på stampkontakten');
rs(!str_contains($wetJson, $secretPnr) && !str_contains(strtolower($wetJson), 'personnummer'), 'stamp-svaret har inget personnummer');
$undoJson = json_encode(jeab_contacts_sole_undo_all(), JSON_UNESCAPED_UNICODE) ?: '';
rs(!str_contains($undoJson, $secretPnr) && !str_contains(strtolower($undoJson), 'personnummer'), 'ångerbatchen har inget personnummer');

$fileDry = jeab_contacts_sole_dispatch([
    'action' => 'kontakter.restore_contact_from_backup',
    'contact_id' => $fileId,
    'backup' => $fileStamp,
]);
rs(!empty($fileDry['ok']) && !empty($fileDry['dry']) && jeab_kontakt_get($fileId) === null, 'filkopian utan tabell är torr');
$fileWet = jeab_contacts_sole_dispatch([
    'action' => 'kontakter.restore_contact_from_backup',
    'contact_id' => $fileId,
    'backup' => $fileStamp,
    'dry' => 0,
    'confirm' => 'SAM_YES',
]);
$fileRow = jeab_kontakt_get($fileId);
$fileJson = json_encode($fileWet, JSON_UNESCAPED_UNICODE) ?: '';
rs(!empty($fileWet['written']) && is_array($fileRow) && (string) ($fileRow['street'] ?? '') === 'Filgatan 8', 'filen data/kontakter.json återskapas');
rs(!str_contains($fileJson, $secretPnr) && !str_contains(strtolower($fileJson), 'personnummer'), 'filsvaret har inget personnummer');

$oldDry = jeab_contacts_sole_dispatch([
    'action' => 'kontakter.restore_contact_from_backup',
    'contact_id' => $oldId,
    'backup' => $oldName,
]);
rs(!empty($oldDry['ok']) && !empty($oldDry['dry']) && (string) ($oldDry['backup'] ?? '') === $oldName && jeab_kontakt_get($oldId) === null, 'kontakter.bak-filen är fortfarande torr');
$oldWet = jeab_contacts_sole_dispatch([
    'action' => 'kontakter.restore_contact_from_backup',
    'contact_id' => $oldId,
    'backup' => $oldName,
    'dry' => 0,
    'confirm' => 'SAM_YES',
    'clear_fields' => ['email'],
]);
$oldRow = jeab_kontakt_get($oldId);
$oldJson = json_encode($oldWet, JSON_UNESCAPED_UNICODE) ?: '';
rs(!empty($oldWet['written']) && is_array($oldRow) && (string) ($oldRow['street'] ?? '') === 'Jsonvagen 3' && jeab_kontakt_row_email($oldRow) === '', 'kontakter.bak-filen skrivs');
rs(!str_contains($oldJson, $secretPnr) && !str_contains(strtolower($oldJson), 'personnummer'), 'json-svaret har inget personnummer');

echo "OK\n";
