<?php

declare(strict_types=1);

/**
 * Idempotens för kontakter.link när gruppen bara är en inkorgskontakt.
 * Bara syntetiska poster.
 */

$tmp = sys_get_temp_dir() . '/jeab-link-idem-' . bin2hex(random_bytes(4));
$private = $tmp . '/private';
$public = $tmp . '/public';
mkdir($private, 0775, true);
mkdir($public, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $private;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $public;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($private . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $private . '/app.sqlite', 'json_path' => $private . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
    'incoming_webhook_token' => 'tok-link-idem',
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function li_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function li(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    li_fail($message);
}

/** Registret ligger i SQL. kontakter.json är inte längre lagringen. */
function li_registry(): string
{
    $json = json_encode(jeab_kontakter_load(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($json) ? $json : '';
}

function li_save_inbox(array $contacts): void
{
    $dir = jeab_inkorg_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    file_put_contents($dir . '/contacts.json', json_encode(['contacts' => $contacts], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function li_inbox_id(string $id): string
{
    $row = jeab_inkorg_contacts()[$id] ?? null;
    return is_array($row) ? trim((string) ($row['contact_id'] ?? '')) : '';
}

function li_create_actions(array $report): int
{
    $count = 0;
    foreach ((array) ($report['groups'] ?? []) as $group) {
        if (is_array($group) && (string) ($group['action'] ?? '') === 'create') {
            $count++;
        }
    }
    return $count;
}

function li_ids_for_street(string $street): array
{
    $want = jeab_kontakt_street_key($street);
    $ids = [];
    foreach (jeab_kontakter_load() as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        $have = jeab_kontakt_effective_street_key((string) ($row['street'] ?? ''), (string) ($row['street_key'] ?? ''));
        if (jeab_kontakt_street_keys_equal($have, $want)) {
            $ids[] = (string) ($row['id'] ?? $id);
        }
    }
    sort($ids);
    return $ids;
}

/** @return array<string, mixed> */
function li_member(string $id, string $name, string $email, string $phone, string $street, string $postal, string $city): array
{
    return [
        'kind' => 'reminder',
        'id' => $id,
        'closed' => false,
        'contact_id' => '',
        'street' => $street,
        'street_key' => jeab_kontakt_street_key($street),
        'postal' => $postal,
        'postal_invalid' => false,
        'city' => $city,
        'fastighet' => '',
        'name' => $name,
        'title' => '',
        'job_type' => '',
        'email' => $email,
        'phone' => $phone === '' ? '' : jeab_kontakt_phone($phone),
        'pnr' => '',
        'at' => '2024-04-01 09:00:00',
        'extra_owners' => [],
    ];
}

$GLOBALS['_JEAB_KONTAKT_POSTORT_LOOKUP'] = static function (string $street, string $postal): array {
    unset($street, $postal);
    return [];
};

$store = jeab_store();
$inbox = [
    'in-ada' => [
        'name' => 'Ada Holm',
        'emails' => ['ada.holm@post.se'],
        'mobile' => '0701000001',
        'address' => 'Ekvägen 12',
        'town' => '',
    ],
];
li_save_inbox($inbox);

$before = count(jeab_kontakter_load());
$wet = jeab_kontakter_link(['dry' => 0, 'skip_conflicts' => 1], $store);
li(!empty($wet['ok']) && ($wet['dry'] ?? true) === false, 'första körningen skriver');
li((int) ($wet['created'] ?? 0) === 1 && (int) ($wet['created_net'] ?? 0) === 1, 'en ny rad, created ' . (int) ($wet['created'] ?? -1) . ' net ' . (int) ($wet['created_net'] ?? -1));
li(count(jeab_kontakter_load()) === $before + 1, 'totalen ökar med created_net');
$adaIds = li_ids_for_street('Ekvägen 12');
li(count($adaIds) === 1, 'inkorgen utan ort ger en kontakt');
$ada = jeab_kontakter_load()[$adaIds[0]] ?? null;
li(is_array($ada) && (string) ($ada['source'] ?? '') === 'link', 'skapad kontakt får source link');
li(li_inbox_id('in-ada') === $adaIds[0], 'inkorgskontakten pekar på den skapade kontakten');

$dry = jeab_kontakter_link(['dry' => 1, 'skip_conflicts' => 1], $store);
li(!empty($dry['ok']) && ($dry['dry'] ?? false) === true, 'direkt torrkörning svarar');
li(li_create_actions($dry) === 0 && (int) ($dry['created'] ?? 1) === 0 && (int) ($dry['created_net'] ?? 1) === 0, 'torrkörningen föreslår create 0');
$linked = is_array($dry['linked'] ?? null) ? $dry['linked'] : [];
li((int) ($linked['reminders'] ?? 1) === 0 && (int) ($linked['orders'] ?? 1) === 0 && (int) ($linked['bookings'] ?? 1) === 0, 'torrkörningen har linked 0');
li(li_inbox_id('in-ada') === $adaIds[0], 'torrkörningen skriver inte om inkorgens länk');

$inbox['in-bo'] = [
    'name' => 'Bo Ek',
    'emails' => ['bo.ek@post.se'],
    'mobile' => '0701000002',
    'address' => 'Ekvägen 12',
    'town' => '',
];
li_save_inbox($inbox);
$split = jeab_kontakter_link(['dry' => 0, 'skip_conflicts' => 1], $store);
li((int) ($split['created'] ?? 0) === 1 && (int) ($split['created_net'] ?? 0) === 1, 'annan e-post på samma gata utan ort blir en egen kontakt');
$ekIds = li_ids_for_street('Ekvägen 12');
li(count($ekIds) === 2, 'två kontakter delar gata utan ort, fick ' . count($ekIds));
li(li_inbox_id('in-bo') !== '' && li_inbox_id('in-bo') !== li_inbox_id('in-ada'), 'den andra inkorgen pekar på sin egen kontakt');

$phoneReuse = jeab_kontakter_link(['dry' => 0, 'skip_conflicts' => 1], $store, [[
    'key' => jeab_kontakt_street_key('Ekvägen 12') . '|saknar:phone',
    'members' => [li_member('77', 'Pia Holm', '', '0701000001', 'Ekvägen 12', '', '')],
    'conflicts' => [],
    'needs_postnr' => true,
]]);
li((int) ($phoneReuse['created'] ?? 0) === 1 && (int) ($phoneReuse['created_net'] ?? 0) === 1, 'samma telefon utan postnummer skapar en egen kontakt');
li(count(li_ids_for_street('Ekvägen 12')) === 3, 'telefon utan postnummer slås inte ihop');

$inbox['in-eva'] = [
    'name' => 'Eva Lind',
    'emails' => ['eva.lind@post.se'],
    'mobile' => '0701000003',
    'address' => 'Almstigen 8',
    'town' => 'Lund',
];
$inbox['in-fia'] = [
    'name' => 'Fia Lind',
    'emails' => ['fia.lind@post.se'],
    'mobile' => '0701000004',
    'address' => 'Almstigen 8, 333 44 Lund',
    'town' => 'Lund',
];
li_save_inbox($inbox);
$town = jeab_kontakter_link(['dry' => 0, 'skip_conflicts' => 1], $store);
$almIds = li_ids_for_street('Almstigen 8');
li(count($almIds) === 1, 'samma gata, nummer och ort blir inte en andra kontakt, fick ' . count($almIds));
li((int) ($town['created_net'] ?? 0) === 1, 'ortträffen ger en ny rad');
li(li_inbox_id('in-eva') === $almIds[0] && li_inbox_id('in-fia') === $almIds[0], 'båda inkorgarna med ort pekar på samma kontakt');

$again = jeab_kontakter_link(['dry' => 1, 'skip_conflicts' => 1], $store);
li(li_create_actions($again) === 0, 'ny torrkörning föreslår fortfarande create 0');
$againLinked = is_array($again['linked'] ?? null) ? $again['linked'] : [];
li((int) ($againLinked['reminders'] ?? 1) === 0 && (int) ($againLinked['orders'] ?? 1) === 0 && (int) ($againLinked['bookings'] ?? 1) === 0, 'ny torrkörning har linked 0');

$fileBefore = li_registry();
$audit = jeab_kontakter_link_audit([], $store);
li(!empty($audit['ok']), 'audit svarar ok');
$shared = array_map('strval', (array) ($audit['shared_unknown_town'] ?? []));
sort($shared);
$expectShared = li_ids_for_street('Ekvägen 12');
sort($expectShared);
li(count($expectShared) === 3 && $shared === $expectShared, 'audit listar kontakterna som delar gata utan ort');
li(!in_array($almIds[0], $shared, true), 'känd ort ligger inte i listan för okänd ort');
li(li_registry() === $fileBefore, 'audit skriver inte kontaktfilen');

$backupName = 'kontakter.bak-20200101-120000.json';
file_put_contents(
    jeab_data_dir() . '/' . $backupName,
    json_encode(['contacts' => jeab_kontakter_load()], JSON_UNESCAPED_UNICODE)
);
$beforeAudit = li_registry();
$orphan = jeab_kontakt_blank([
    'name' => 'Orphan Holm',
    'email' => 'orphan.holm@post.se',
    'phone' => '0701000099',
    'street' => '',
    'street_key' => '',
    'city' => '',
]);
jeab_kontakter_mutate(static function (array &$contacts) use ($orphan): void {
    $contacts[(string) $orphan['id']] = $orphan;
});
$beforeRead = li_registry();
$auditAfter = jeab_kontakter_link_audit(['backup' => $backupName], $store);
$orphans = array_map('strval', (array) ($auditAfter['orphans'] ?? []));
$fresh = array_map('strval', (array) ($auditAfter['new_since_backup'] ?? []));
li(in_array((string) $orphan['id'], $orphans, true), 'audit listar kontakten som ingen källa pekar på');
li(!in_array($adaIds[0], $orphans, true) && !in_array($almIds[0], $orphans, true), 'kopplade kontakter är inte föräldralösa');
li($fresh === [(string) $orphan['id']], 'backup listar bara id som tillkommit');
li(li_registry() === $beforeRead, 'audit lämnar filen');
li($beforeAudit !== $beforeRead, 'föräldralösen lades till efter kopian');

$badNames = [
    '../kontakter.bak-20200101-120000.json',
    '..\\kontakter.bak-20200101-120000.json',
    'kontakter.bak-20200101-120000.json/../../etc/passwd',
    'foo.json',
    'kontakter.bak-20200101-120000.txt',
    'kontakter.bak-evil.json',
    './kontakter.bak-20200101-120000.json',
];
foreach ($badNames as $badName) {
    $bad = jeab_kontakter_link_audit(['backup' => $badName], $store);
    li(($bad['ok'] ?? true) === false && !isset($bad['orphans']) && !isset($bad['new_since_backup']), 'ogiltigt filnamn avvisas: ' . $badName);
}
$missing = jeab_kontakter_link_audit(['backup' => 'kontakter.bak-19990101-000000.json'], $store);
li(($missing['ok'] ?? true) === false, 'filnamn som saknas avvisas');
li(!is_file(jeab_data_dir() . '/kontakter.bak-19990101-000000.json'), 'avvisningen skapar ingen fil');

$notes = jeab_kontakter_link(['dry' => 0, 'skip_conflicts' => 1], $store, [[
    'key' => jeab_kontakt_street_key('Norrstigen 3') . '|11122',
    'members' => [
        li_member('501', 'Nora Ek', 'nora.ek@post.se', '', 'Norrstigen 3', '11122', 'Lund'),
        li_member('502', 'Nora Ek', 'nora.andra@post.se', '', 'Norrstigen 3', '11122', 'Lund'),
    ],
    'conflicts' => [],
    'needs_postnr' => false,
]]);
li((int) ($notes['ovrigt_lines'] ?? 0) === 1 && (int) ($notes['ovrigt_contacts'] ?? 0) === 1, 'ovrigt räknar en rad på en kontakt, fick lines ' . (int) ($notes['ovrigt_lines'] ?? -1) . ' contacts ' . (int) ($notes['ovrigt_contacts'] ?? -1));
$nora = null;
foreach (jeab_kontakter_load() as $row) {
    if (!is_array($row)) {
        continue;
    }
    foreach ((array) ($row['owners'] ?? []) as $owner) {
        if (is_array($owner) && (string) ($owner['name'] ?? '') === 'Nora Ek') {
            $nora = $row;
        }
    }
}
li(is_array($nora) && (string) ($nora['source'] ?? '') === 'link' && str_contains(jeab_kontakt_ovrigt($nora), 'E-post: nora.andra@post.se'), 'extra e-post ligger i Övrigt och källan är link');

$merge = jeab_kontakter_link(['dry' => 0, 'skip_conflicts' => 1], $store, [
    [
        'key' => jeab_kontakt_street_key('Cedergatan 2') . '|11122',
        'members' => [li_member('601', 'Kim Alm', 'kim.alm@post.se', '0701000008', 'Cedergatan 2', '11122', 'Lund')],
        'conflicts' => [],
        'needs_postnr' => false,
    ],
    [
        'key' => jeab_kontakt_street_key('Duvstigen 9') . '|22233',
        'members' => [li_member('602', 'Kim Alm', 'kim.alm@post.se', '0701000008', 'Duvstigen 9', '22233', 'Lund')],
        'conflicts' => [],
        'needs_postnr' => false,
    ],
]);
li((int) ($merge['created'] ?? 0) === 2 && (int) ($merge['created_net'] ?? 0) === 2, 'olika gata och postnummer slås inte ihop, fick ' . (int) ($merge['created'] ?? -1) . '/' . (int) ($merge['created_net'] ?? -1));
$kim = 0;
foreach (jeab_kontakter_load() as $row) {
    if (!is_array($row)) {
        continue;
    }
    foreach ((array) ($row['owners'] ?? []) as $owner) {
        if (is_array($owner) && (string) ($owner['name'] ?? '') === 'Kim Alm') {
            $kim++;
            li((string) ($row['source'] ?? '') === 'link', 'kontakten som blev kvar har source link');
        }
    }
}
li($kim === 2, 'samma e-post på olika adresser blir två rader');

$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-link-idem']));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = jeab_kontakter_link_audit_dispatch(['action' => 'kontakter.link_audit']);
li(($denied['status'] ?? 0) === 401 && ($denied['ok'] ?? true) === false, 'kontakter.link_audit utan token ger 401');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-link-idem';
$allowed = jeab_kontakter_link_audit_dispatch(['action' => 'kontakter.link_audit', 'backup' => '../kontakter.bak-20200101-120000.json']);
li(($allowed['status'] ?? 0) === 400 && ($allowed['ok'] ?? true) === false, 'hooken avvisar sökväg i backup');
$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
li(str_contains($hook, "'kontakter.link_audit'") && str_contains($hook, 'jeab_kontakter_link_audit_dispatch'), 'hook.php registrerar kontakter.link_audit');

echo "OK  klart\n";
