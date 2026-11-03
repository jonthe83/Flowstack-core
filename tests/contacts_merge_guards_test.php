<?php

declare(strict_types=1);

/**
 * dry=0 utan SQL-lager, återanslutning som tappar låset, och flags.set för
 * CONTACTS_AUTO_MERGE. Påhittade kontakter. Inget personnummer.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$home = sys_get_temp_dir() . '/jeab-cmg-' . bin2hex(random_bytes(4));
$root = $home . '/public_html';
$data = $root . '/data';
$privat = $home . '/privat';
mkdir($data . '/secrets', 0700, true);
mkdir($privat, 0700, true);
$GLOBALS['_JEAB_APP_ROOT'] = $root;
$GLOBALS['_JEAB_DATA_DIR'] = $data;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $data;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $data;
putenv('JEAB_APP_ROOT=' . $root);
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND');
putenv('CONTACTS_AUTO_MERGE');
putenv('CONTACTS_STRICT');
putenv('CONTACTS_SOLE');
unset(
    $_ENV['DATA_BACKEND'],
    $_ENV['CONTACTS_AUTO_MERGE'],
    $_ENV['CONTACTS_STRICT'],
    $_ENV['CONTACTS_SOLE'],
    $GLOBALS['_JEAB_CONTACTS_AUTO_MERGE'],
    $GLOBALS['_JEAB_CONTACTS_STRICT'],
    $GLOBALS['_JEAB_CONTACTS_SOLE'],
    $GLOBALS['_JEAB_DATA_BACKEND']
);
file_put_contents($data . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $data . '/app.sqlite',
        'json_path' => $data . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
    'incoming_webhook_token' => 'not-the-inbox-token',
], true) . ";\n");
file_put_contents($data . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-cmg']));

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/contacts_merge_duplicates.php';

function cmg(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/** @param array<string, mixed> $row */
function cmg_put(string $id, array $row): void
{
    $row['id'] = $id;
    jeab_kontakter_mutate(static function (array &$contacts) use ($id, $row): void {
        $contacts[$id] = $row;
    });
    jeab_contacts_merge_forget($id);
}

$sentinel = $data . '/kontakter.json';
$sentinelBody = "{\"contacts\":{\"keep\":{\"id\":\"keep\",\"street\":\"Orord gata\"}}}\n";
file_put_contents($sentinel, $sentinelBody);

cmg(jeab_kontakter_use_sql() === false, 'kontakterna ligger i JSON');
cmg(jeab_flag('CONTACTS_AUTO_MERGE') === false, 'CONTACTS_AUTO_MERGE är av');

foreach ([
    ['action' => 'contacts.merge_duplicates', 'dry' => 0, 'confirm' => 'merge'],
    ['action' => 'contacts.merge_duplicates_undo', 'dry' => 0, 'confirm' => 'undo', 'undo_id' => '0123456789abcdef'],
    ['action' => 'contacts.normalize_addresses', 'dry' => 0, 'confirm' => 'normalize'],
    ['action' => 'contacts.normalize_addresses_undo', 'dry' => 0, 'confirm' => 'undo', 'undo_id' => '0123456789abcdef'],
] as $input) {
    $refused = jeab_contacts_merge_dispatch($input);
    $action = (string) $input['action'];
    cmg(
        ($refused['ok'] ?? true) === false
        && ($refused['error'] ?? '') === 'sql_store_required'
        && ($refused['status'] ?? 0) === 409
        && ($refused['written'] ?? true) === false,
        $action . ' med dry=0 kräver SQL-lager'
    );
}
cmg((string) file_get_contents($sentinel) === $sentinelBody, 'kontakter.json skrevs inte');

$dry = jeab_contacts_merge_dispatch(['action' => 'contacts.merge_duplicates']);
cmg(($dry['ok'] ?? false) === true && ($dry['written'] ?? true) === false, 'torrkörning skriver inte JSON');
cmg((string) file_get_contents($sentinel) === $sentinelBody, 'torrkörning lämnar kontakter.json');

file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-cmg']));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-cmg';

$unknown = jeab_flags_set_dispatch(['name' => 'CONTACTS_AUTO_MERGE_NOPE', 'value' => 1, 'dry' => 0]);
cmg(($unknown['error'] ?? '') === 'Okänd flagga.' && ($unknown['written'] ?? true) === false, 'okänt namn nekas');

$preview = jeab_flags_set_dispatch(['name' => 'CONTACTS_AUTO_MERGE', 'value' => 1]);
cmg(
    ($preview['ok'] ?? false) === true
    && ($preview['dry'] ?? false) === true
    && ($preview['written'] ?? true) === false
    && ($preview['new'] ?? 0) === 1
    && !is_file($privat . '/flags.json'),
    'utelämnad dry visar CONTACTS_AUTO_MERGE utan att skriva'
);

$turnedOn = jeab_flags_set_dispatch(['name' => 'CONTACTS_AUTO_MERGE', 'value' => 1, 'dry' => 0]);
cmg(
    ($turnedOn['ok'] ?? false) === true
    && ($turnedOn['written'] ?? false) === true
    && ($turnedOn['old'] ?? 1) === 0
    && ($turnedOn['new'] ?? 0) === 1
    && jeab_flag('CONTACTS_AUTO_MERGE') === true
    && jeab_flag_resolve('CONTACTS_AUTO_MERGE')['source'] === 'file',
    'flags.set slår på CONTACTS_AUTO_MERGE utan SAM_YES'
);
$needsYes = (string) ($turnedOn['error'] ?? '');
cmg(!str_contains($needsYes, 'SAM_YES'), 'påslaget kräver inte SAM_YES');

$turnedOff = jeab_flags_set_dispatch(['name' => 'CONTACTS_AUTO_MERGE', 'value' => 0, 'dry' => 0]);
cmg(
    ($turnedOff['ok'] ?? false) === true
    && ($turnedOff['written'] ?? false) === true
    && jeab_flag('CONTACTS_AUTO_MERGE') === false,
    'flags.set slår av CONTACTS_AUTO_MERGE utan confirm'
);
jeab_flags_set_dispatch(['name' => 'CONTACTS_AUTO_MERGE', 'value' => 1, 'dry' => 0]);
cmg(jeab_flag('CONTACTS_AUTO_MERGE') === true, 'flaggan är på inför sammanslagningen');

$store = jeab_store();
cmg($store instanceof JeabPdoStore, 'sqlite-lagret finns');
$pdo = $store->pdo();
cmg(jeab_settings_put($pdo, 'sqlite', JEAB_KONTAKTER_STORAGE_KEY, 'sql'), 'kontakter_storage sätts till sql');
unset($GLOBALS['_JEAB_KONTAKTER_MEM'], $GLOBALS['_JEAB_KONTAKTER_MEM_KEY']);
cmg(jeab_kontakter_use_sql() === true, 'kontakterna ligger i SQL');

$pair = static function (string $street, string $city, string $masterEmail, string $dupEmail): array {
    return [
        'street' => $street,
        'postal_code' => '11122',
        'city' => $city,
        'company' => 'jarfalla',
        'created_at' => '2020-01-01 00:00:00',
        'updated_at' => '2020-01-01 00:00:00',
        'master' => [[
            'name' => 'Master Prov',
            'email' => $masterEmail,
            'phone' => '+46701000001',
            'contact' => true,
        ]],
        'dup' => [[
            'name' => '',
            'email' => $dupEmail,
            'phone' => '',
            'contact' => true,
        ]],
    ];
};
$alpha = $pair('Alfagatan 1', 'Alfastad', 'master.a@example.test', 'extra.a@example.test');
$beta = $pair('Betagatan 2', 'Betastad', 'master.b@example.test', 'extra.b@example.test');
cmg_put('kt_a', [
    'street' => $alpha['street'],
    'postal_code' => $alpha['postal_code'],
    'city' => $alpha['city'],
    'company' => 'jarfalla',
    'created_at' => '2020-01-01 00:00:00',
    'updated_at' => '2020-01-01 00:00:00',
    'owners' => $alpha['master'],
]);
cmg_put('kt_a2', [
    'street' => $alpha['street'],
    'postal_code' => $alpha['postal_code'],
    'city' => $alpha['city'],
    'company' => 'jarfalla',
    'created_at' => '2024-01-01 00:00:00',
    'updated_at' => '2024-01-01 00:00:00',
    'owners' => $alpha['dup'],
]);
cmg_put('kt_b', [
    'street' => $beta['street'],
    'postal_code' => $beta['postal_code'],
    'city' => $beta['city'],
    'company' => 'jarfalla',
    'created_at' => '2020-02-01 00:00:00',
    'updated_at' => '2020-02-01 00:00:00',
    'owners' => $beta['master'],
]);
cmg_put('kt_b2', [
    'street' => $beta['street'],
    'postal_code' => $beta['postal_code'],
    'city' => $beta['city'],
    'company' => 'jarfalla',
    'created_at' => '2024-02-01 00:00:00',
    'updated_at' => '2024-02-01 00:00:00',
    'owners' => $beta['dup'],
]);
cmg((string) file_get_contents($sentinel) === $sentinelBody, 'SQL-sparning rör inte kontakter.json');

$errLog = $data . '/php-error.log';
ini_set('error_log', $errLog);
$gone = new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away', 2006);
$gone->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];
$GLOBALS['_JEAB_CONTACTS_MERGE_GONE_ONCE'] = $gone;
$run = jeab_contacts_merge_dispatch([
    'action' => 'contacts.merge_duplicates',
    'dry' => 0,
    'confirm' => 'merge',
]);
unset($GLOBALS['_JEAB_CONTACTS_MERGE_GONE_ONCE']);
$live = jeab_store();
cmg($live instanceof JeabPdoStore, 'lagret finns efter återanslutning');
$livePdo = $live->pdo();
$failed = is_array($run['failed_ids'] ?? null) ? $run['failed_ids'] : [];
$busyHit = null;
foreach ($failed as $row) {
    if (is_array($row) && (string) ($row['id'] ?? '') === 'kt_b2') {
        $busyHit = $row;
    }
}
cmg(
    ($run['ok'] ?? true) === false
    && ($run['error'] ?? '') === 'busy'
    && ($run['busy'] ?? false) === true
    && ($run['status'] ?? 0) === 409,
    'återanslutning avbryter resten med busy'
);
cmg(is_array($busyHit) && (string) ($busyHit['reason'] ?? '') === 'busy', 'kvarvarande par får status busy');
cmg(jeab_contacts_merge_fetch($livePdo, 'kt_b2') !== null, 'kvarvarande dubblett slogs inte ihop');
cmg(jeab_contacts_merge_fetch($livePdo, 'kt_a2') === null, 'paret före återanslutningen hann slås ihop');
$logged = is_file($errLog) ? (string) file_get_contents($errLog) : '';
cmg(str_contains($logged, 'contacts.merge_duplicates återanslutning låset borta'), 'tappat lås loggas');
cmg((string) file_get_contents($sentinel) === $sentinelBody, 'återanslutningen skrev inte kontakter.json');

echo "OK\n";
