<?php

declare(strict_types=1);

/**
 * Kort namn på ordern och fullt namn på kontakten.
 * Giltigt personnummer och samma för- och efternamn är inte tvetydigt.
 * En manuell sparning i Kontakter efter ordern är inte tvetydig.
 * link_report visar kontakten en gång, med fastighet, och behåller länkarna.
 * Inget personnummer i svar eller logg.
 */

$tmp = sys_get_temp_dir() . '/jeab-pnr-middle-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
$GLOBALS['_JEAB_KONTAKT_POSTORT_LOOKUP'] = static function (): array {
    return [];
};
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
putenv('CONTACTS_SOLE=1');
putenv('JEAB_PNR_HMAC_KEY=pnr-middle-name-test');
ini_set('log_errors', '1');
ini_set('error_log', $tmp . '/php-error.log');
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
$_SESSION['user'] = 'test@example.com';
$_GET['co'] = 'jarfalla';
if (function_exists('jeab_auth_ok') && !jeab_auth_ok()) {
    $_SESSION['auth'] = true;
}

function mid(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function mid_pnr(string $nine): string
{
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $n = (int) $nine[$i];
        if ($i % 2 === 0) {
            $n *= 2;
            if ($n > 9) {
                $n -= 9;
            }
        }
        $sum += $n;
    }
    $check = (10 - ($sum % 10)) % 10;

    return '19' . $nine . $check;
}

function mid_digits(string $raw): string
{
    return preg_replace('/\D+/', '', $raw) ?? '';
}

function mid_pattern(string $text): bool
{
    // kt_-id är hex. Tolv siffror i det värdet är inte ett personnummer.
    return preg_match('/(?<![0-9A-Fa-f])\d{12}(?![0-9A-Fa-f])/', $text) === 1
        || preg_match('/(?<![0-9A-Fa-f])\d{10}(?![0-9A-Fa-f])/', $text) === 1
        || preg_match('/\d{8}\s*[-+]\s*\d{4}/', $text) === 1
        || preg_match('/\d{6}\s*[-+]\s*\d{4}/', $text) === 1;
}

function mid_hides(string $blob, string $digits, string $label): void
{
    $dashed = strlen($digits) === 12 ? substr($digits, 0, 8) . '-' . substr($digits, 8) : $digits;
    mid(!str_contains($blob, $digits) && !str_contains($blob, $dashed) && !mid_pattern($blob), $label);
}

function mid_same_pnr(string $stored, string $digits, string $label): void
{
    $left = jeab_norm_pnr($stored);
    $right = jeab_norm_pnr($digits);
    mid($left !== '' && $right !== '' && hash_equals($left, $right), $label);
}

$store = jeab_store();
mid($store instanceof JeabPdoStore, 'sqlite-lagret finns');
jeab_ensure_contacts_tables($store->pdo(), 'sqlite');
mid(jeab_kontakter_sql_is_sole_source(), 'kontakter läses från SQL');

$pnrA = mid_pnr('800101123');
$pnrB = mid_pnr('850505123');
$digitsA = mid_digits($pnrA);
$digitsB = mid_digits($pnrB);
mid(strlen($digitsA) === 12 && strlen($digitsB) === 12 && $digitsA !== $digitsB, 'två giltiga nummer');
mid(jeab_kontakt_pnr_valid($pnrA) && jeab_kontakt_pnr_valid($pnrB), 'numren går att validera');
mid(jeab_contacts_sole_name_first_last_same('Anders Karlsson', 'Anders Tore Karlsson'), 'för- och efternamn stämmer');
mid(!jeab_contacts_sole_name_first_last_same('Anders Karlsson', 'Bo Karlsson'), 'annat förnamn stämmer inte');
mid(!jeab_contacts_sole_name_first_last_same('Anders Karlsson', 'Anders Svensson'), 'annat efternamn stämmer inte');

function mid_contact(string $name, string $pnr, string $street, string $email): string
{
    $row = jeab_kontakt_save_input([
        'street' => $street,
        'postal_code' => '17730',
        'city' => 'Jarfalla',
        'owner_name_1' => $name,
        'owner_pnr_1' => $pnr,
        'contact_person' => '1',
        'email' => $email,
        'company' => 'jarfalla',
    ]);

    return (string) ($row['id'] ?? '');
}

$anders = mid_contact('Anders Tore Karlsson', $pnrA, 'Andersgatan 1', 'anders@example.test');
$twin = mid_contact('Anders Tore Karlsson', $pnrB, 'Twingatan 1', 'twin@example.test');
$svensson = mid_contact('Anders Tore Svensson', $pnrA, 'Svenssongatan 1', 'svensson@example.test');
$siv = mid_contact('Siv Holmberg', $pnrB, 'Sivgatan 1', 'siv@example.test');
$dubbel = jeab_kontakt_save_input([
    'street' => 'Dubbelgatan 1',
    'postal_code' => '17730',
    'city' => 'Jarfalla',
    'owner_name_1' => 'Anders Tore Karlsson',
    'owner_pnr_1' => $pnrA,
    'owner_name_2' => 'Karin Ek',
    'contact_person' => '1',
    'email' => 'dubbel@example.test',
    'company' => 'jarfalla',
]);
$dubbelId = (string) ($dubbel['id'] ?? '');
$lm = mid_contact('Eva LM', $pnrA, 'Lmgatan 1', 'lm@example.test');
$same = mid_contact('Anders Tore Karlsson', $pnrA, 'Sammagatan 1', 'same@example.test');
$pair = mid_contact('Anders Tore Karlsson', $pnrB, 'Pargatan 1', 'pair@example.test');
mid($anders !== '' && $twin !== '' && $siv !== '' && $dubbelId !== '' && $lm !== '' && $same !== '' && $pair !== '', 'kontakterna finns');

jeab_kontakter_mutate(static function (array &$contacts) use ($lm): void {
    if (!isset($contacts[$lm]) || !is_array($contacts[$lm])) {
        return;
    }
    $contacts[$lm]['owner_stamp'] = 'lantmateriet';
    $contacts[$lm]['updated_at'] = '2024-06-01 12:00:00';
    if (isset($contacts[$lm]['owners'][0]) && is_array($contacts[$lm]['owners'][0])) {
        unset($contacts[$lm]['owners'][0]['saved_at']);
    }
});
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);

$owner = static function (string $id): array {
    $row = jeab_kontakt_get($id);
    $owners = is_array($row['owners'] ?? null) ? $row['owners'] : [];

    return is_array($owners[0] ?? null) ? $owners[0] : [];
};
mid(trim((string) ($owner($anders)['address'] ?? '')) === '', 'ägarens adress är tom');
mid_same_pnr((string) ($owner($anders)['personnummer'] ?? ''), $digitsA, 'kontakten har ett giltigt nummer');

$orders = [
    '5801' => [
        'reminder_id' => 5801,
        'contact_id' => $anders,
        'name' => 'Anders Karlsson',
        'huvudsokande' => 'Anders Karlsson',
        'fastighetsbeteckning' => 'KARLEN 1:1',
        'address' => 'Nya vägen 8, 17730 Jarfalla',
        'owners' => [
            ['name' => 'Anders Karlsson', 'personnummer' => '', 'address' => 'Nya vägen 8'],
        ],
        'kind' => 'batteri',
        'company' => 'jarfalla',
    ],
    '5802' => [
        'reminder_id' => 5802,
        'contact_id' => $twin,
        'name' => 'Anders Karlsson',
        'fastighetsbeteckning' => 'TWIN 1:1',
        'address' => 'Twingatan 1, 17730 Jarfalla',
        'owners' => [
            ['name' => 'Anders Karlsson', 'personnummer' => '', 'address' => 'Twingatan 1'],
            ['name' => 'Anders Nils Karlsson', 'personnummer' => '', 'address' => 'Twingatan 1'],
        ],
        'kind' => 'batteri',
        'company' => 'jarfalla',
    ],
    '5803' => [
        'reminder_id' => 5803,
        'contact_id' => $svensson,
        'updated_at' => '2099-01-01 00:00:00',
        'name' => 'Anders Karlsson',
        'fastighetsbeteckning' => 'SVENS 1:1',
        'address' => 'Svenssongatan 1, 17730 Jarfalla',
        'owners' => [
            ['name' => 'Anders Karlsson', 'personnummer' => '', 'address' => ''],
        ],
        'kind' => 'batteri',
        'company' => 'jarfalla',
    ],
    '5804' => [
        'reminder_id' => 5804,
        'contact_id' => $siv,
        'updated_at' => '2020-01-01 00:00:00',
        'name' => 'Bo Ek',
        'fastighetsbeteckning' => 'SIV 1:1',
        'address' => 'Sivgatan 1, 17730 Jarfalla',
        'owners' => [
            ['name' => 'Bo Ek', 'personnummer' => '', 'address' => ''],
        ],
        'kind' => 'batteri',
        'company' => 'jarfalla',
    ],
    '5805' => [
        'reminder_id' => 5805,
        'contact_id' => $lm,
        'updated_at' => '2020-01-01 00:00:00',
        'name' => 'Bo Ek',
        'fastighetsbeteckning' => 'LM 1:1',
        'address' => 'Lmgatan 1, 17730 Jarfalla',
        'owners' => [
            ['name' => 'Bo Ek', 'personnummer' => '', 'address' => ''],
        ],
        'kind' => 'batteri',
        'company' => 'jarfalla',
    ],
    '5806' => [
        'reminder_id' => 5806,
        'contact_id' => $dubbelId,
        'name' => 'Anders Karlsson',
        'fastighetsbeteckning' => 'DUBBEL 1:1',
        'address' => 'Dubbelgatan 1, 17730 Jarfalla',
        'owners' => [
            ['name' => 'Anders Karlsson', 'personnummer' => '', 'address' => 'Dubbelgatan 1'],
        ],
        'kind' => 'batteri',
        'company' => 'jarfalla',
    ],
    '5807' => [
        'reminder_id' => 5807,
        'contact_id' => $same,
        'name' => 'Anders Karlsson',
        'fastighetsbeteckning' => 'SAMMA 1:1',
        'address' => 'Sammagatan 1, 17730 Jarfalla',
        'owners' => [
            ['name' => 'Anders Karlsson', 'personnummer' => '', 'address' => 'Sammagatan 1'],
            ['name' => 'Anders Tore Karlsson', 'personnummer' => '', 'address' => 'Sammagatan 1'],
        ],
        'kind' => 'batteri',
        'company' => 'jarfalla',
    ],
    '5808' => [
        'reminder_id' => 5808,
        'contact_id' => $pair,
        'name' => 'Anders Karlsson',
        'fastighetsbeteckning' => 'PAR 1:1',
        'address' => 'Pargatan 1, 17730 Jarfalla',
        'owners' => [
            ['name' => 'Anders Karlsson', 'personnummer' => '', 'address' => 'Pargatan 1'],
            ['name' => 'Anders Karlsson', 'personnummer' => '', 'address' => 'Pargatan 1'],
        ],
        'kind' => 'batteri',
        'company' => 'jarfalla',
    ],
];
$GLOBALS['_JEAB_PURCHASE_LIST'] = $orders;
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);

mid(jeab_contacts_sole_row_pnr_name_ambiguous($orders['5801']) === false, 'mellannamn är löst');
mid(jeab_contacts_sole_row_pnr_name_ambiguous($orders['5802']) === true, 'två för- och efternamn är tvetydigt');
mid(jeab_contacts_sole_row_pnr_name_ambiguous($orders['5803']) === true, 'annat efternamn före sparningen är tvetydigt');
mid(jeab_contacts_sole_row_pnr_name_ambiguous($orders['5804']) === false, 'sparning efter ordern är löst');
mid(jeab_contacts_sole_row_pnr_name_ambiguous($orders['5805']) === true, 'Lantmäteriets skrivning löser inte annat namn');
mid(jeab_contacts_sole_row_pnr_name_ambiguous($orders['5806']) === true, 'en olöst ägare håller ordern tvetydig');
mid(jeab_contacts_sole_row_pnr_name_ambiguous($orders['5807']) === false, 'exakt fullt namn löser ordern även när det korta namnet också finns');
mid(jeab_contacts_sole_row_pnr_name_ambiguous($orders['5808']) === true, 'två likadana korta namn är tvetydigt');

$list = jeab_contacts_sole_pnr_ambiguous_list([], $store);
$listJson = json_encode($list);
mid(is_string($listJson), 'listan kan kodas');
mid_hides((string) $listJson, $digitsA, 'listan innehåller inte nummer A');
mid_hides((string) $listJson, $digitsB, 'listan innehåller inte nummer B');
$listRows = [];
foreach ((array) ($list['rows'] ?? []) as $listRow) {
    if (is_array($listRow)) {
        $listRows[(int) ($listRow['reminder_id'] ?? 0)] = $listRow;
    }
}
mid(!isset($listRows[5801]) && !isset($listRows[5804]) && !isset($listRows[5807]), 'lösta ordrar lämnar listan');
$reasonAt = static function (array $rows, int $id): array {
    $hit = $rows[$id] ?? [];

    return is_array($hit['reason'] ?? null) ? $hit['reason'] : [];
};
mid($reasonAt($listRows, 5802) === [['index' => 0, 'reason' => 'Flera personer har samma för- och efternamn.']], '5802 är flera personer med samma för- och efternamn');
mid($reasonAt($listRows, 5803) === [['index' => 0, 'reason' => 'Namnet skiljer sig mer än mellannamn.']], '5803 skiljer sig mer än mellannamn');
mid($reasonAt($listRows, 5805) === [['index' => 0, 'reason' => 'Namnet skiljer sig mer än mellannamn.']], '5805 skiljer sig mer än mellannamn');
mid($reasonAt($listRows, 5806) === [['index' => 1, 'reason' => 'Personnumret saknas.']], '5806 saknar nummer på andra platsen');
$owners5806 = is_array(($listRows[5806]['owners'] ?? null)) ? $listRows[5806]['owners'] : [];
mid((string) ($owners5806[0]['reason'] ?? 'x') === '' && (string) ($owners5806[1]['reason'] ?? '') === 'Personnumret saknas.', 'löst plats har tom orsak');
mid($reasonAt($listRows, 5808) === [['index' => 0, 'reason' => 'Flera personer har samma för- och efternamn.']], '5808 är två likadana namn');

$dry = jeab_contacts_owner_pnr_fill([], $store);
$dryJson = json_encode($dry);
mid(is_string($dryJson) && !empty($dry['ok']) && ($dry['dry'] ?? false) === true && ($dry['written'] ?? true) === false, 'fyllningen är torr');
$ambiguous = is_array($dry['pnr_name_ambiguous'] ?? null) ? $dry['pnr_name_ambiguous'] : [];
sort($ambiguous);
mid($ambiguous === [5802, 5803, 5805, 5806, 5808], 'tvetydiga id är de olösta, fick ' . implode(',', array_map('strval', $ambiguous)));
$dryIds = is_array($dry['ids'] ?? null) ? $dry['ids'] : [];
sort($dryIds);
$expectIds = [$anders, $siv, $same];
sort($expectIds);
mid($dryIds === $expectIds, 'bara lösta kontakter med tom fastighet skulle fyllas');
mid((int) ($dry['counts']['fastighet'] ?? 0) === 3, 'torrkörningen räknar tre fastigheter');
mid((int) ($dry['counts']['owners'] ?? 0) === 0, 'ifyllt nummer skrivs inte om');
mid_hides($dryJson, $digitsA, 'torrkörningen innehåller inte nummer A');
mid_hides($dryJson, $digitsB, 'torrkörningen innehåller inte nummer B');
mid(trim((string) (jeab_kontakt_get($anders)['fastighetsbeteckning'] ?? '')) === '', 'torrkörningen skriver inte');

$wet = jeab_contacts_owner_pnr_fill(['dry' => 0], $store);
$wetJson = json_encode($wet);
mid(is_string($wetJson) && !empty($wet['ok']) && ($wet['written'] ?? false) === true, 'dry=0 skriver fastighet');
mid_hides($wetJson, $digitsA, 'svaret innehåller inte nummer A');
mid_hides($wetJson, $digitsB, 'svaret innehåller inte nummer B');
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$andersFilled = jeab_kontakt_get($anders);
$sivFilled = jeab_kontakt_get($siv);
mid((string) ($andersFilled['fastighetsbeteckning'] ?? '') === 'KARLEN 1:1', 'fastighet fylls från ordern');
mid((string) ($sivFilled['fastighetsbeteckning'] ?? '') === 'SIV 1:1', 'fastighet fylls efter manuell sparning');
mid((string) (jeab_kontakt_get($same)['fastighetsbeteckning'] ?? '') === 'SAMMA 1:1', 'fastighet fylls när det fulla namnet finns exakt en gång');
mid(trim((string) (jeab_kontakt_get($twin)['fastighetsbeteckning'] ?? '')) === '', 'tvetydig order fyller inte');
mid(trim((string) (jeab_kontakt_get($pair)['fastighetsbeteckning'] ?? '')) === '', 'två likadana namn fyller inte');
mid(trim((string) (jeab_kontakt_get($lm)['fastighetsbeteckning'] ?? '')) === '', 'Lantmäteriet-stämpeln fyller inte');
mid_same_pnr((string) ($andersFilled['owners'][0]['personnummer'] ?? ''), $digitsA, 'numret på kontakten är oförändrat');
mid_same_pnr((string) (jeab_kontakt_get($same)['owners'][0]['personnummer'] ?? ''), $digitsA, 'samma persons nummer är oförändrat');

$batch = (string) ($wet['batch_id'] ?? '');
$undoBlob = '';
if ($batch !== '') {
    $undoRows = $store->pdo()->prepare('SELECT before_json, after_json FROM contacts_sole_undo WHERE batch_id = ?');
    $undoRows->execute([$batch]);
    foreach ($undoRows->fetchAll() as $undoRow) {
        if (is_array($undoRow)) {
            $undoBlob .= (string) ($undoRow['before_json'] ?? '') . (string) ($undoRow['after_json'] ?? '');
        }
    }
}
mid($undoBlob !== '', 'ångerloggen har rader');
mid_hides($undoBlob, $digitsA, 'ångerloggen innehåller inte nummer A');
mid_hides($undoBlob, $digitsB, 'ångerloggen innehåller inte nummer B');

$oldRem = jeab_create_reminder($store, 'Gammal adress', jeab_today_start(), null, null, [
    'customer' => 'Anders Karlsson',
    'email' => 'anders.order@example.test',
    'address' => 'Gamla vägen 4, 17730 Jarfalla',
    'company' => 'jarfalla',
    'who' => 'Elin',
    'contact_id' => $anders,
    'lookup' => false,
]);
$sameStreetOld = jeab_create_reminder($store, 'Gammal samgata', jeab_today_start(), null, null, [
    'customer' => 'Anders Karlsson',
    'email' => 'sam.old@example.test',
    'address' => 'Samgatan 3, 11122 Jarfalla',
    'company' => 'jarfalla',
    'who' => 'Elin',
    'contact_id' => $twin,
    'lookup' => false,
]);
$other = mid_contact('Bo Ek', $pnrB, 'Ekgatan 2', 'bo@example.test');
$otherRem = jeab_create_reminder($store, 'Annan person', jeab_today_start(), null, null, [
    'customer' => 'Bo Ek',
    'email' => 'bo.rem@example.test',
    'address' => 'Ekgatan 2, 17730 Jarfalla',
    'company' => 'jarfalla',
    'who' => 'Elin',
    'contact_id' => $other,
    'lookup' => false,
]);
$orders['5810'] = [
    'reminder_id' => 5810,
    'contact_id' => $anders,
    'name' => 'Anders Karlsson',
    'fastighetsbeteckning' => 'KARLEN 1:1',
    'address' => 'Nya vägen 8, 17730 Jarfalla',
    'owners' => [
        ['name' => 'Anders Karlsson', 'personnummer' => '', 'address' => 'Nya vägen 8'],
    ],
    'kind' => 'batteri',
    'company' => 'jarfalla',
];
$orders['5811'] = [
    'reminder_id' => 5811,
    'contact_id' => $siv,
    'name' => 'Anders Tore Karlsson',
    'fastighetsbeteckning' => 'SAMEN 1:1',
    'address' => 'Samgatan 3, 11133 Jarfalla',
    'owners' => [
        ['name' => 'Anders Tore Karlsson', 'personnummer' => '', 'address' => 'Samgatan 3'],
    ],
    'kind' => 'batteri',
    'company' => 'jarfalla',
];
$orders['5812'] = [
    'reminder_id' => 5812,
    'contact_id' => $other,
    'name' => 'Bo Ek',
    'fastighetsbeteckning' => 'EKEN 2:2',
    'address' => 'Annan vägen 9, 17730 Jarfalla',
    'owners' => [
        ['name' => 'Bo Ek', 'personnummer' => '', 'address' => 'Annan vägen 9'],
    ],
    'kind' => 'batteri',
    'company' => 'jarfalla',
];
$GLOBALS['_JEAB_PURCHASE_LIST'] = $orders;
$beforeContacts = count(jeab_kontakter_load());
$beforeLinks = [
    (string) ($store->getReminder((int) $oldRem['id'])['contact_id'] ?? ''),
    (string) ($store->getReminder((int) $sameStreetOld['id'])['contact_id'] ?? ''),
    (string) ($store->getReminder((int) $otherRem['id'])['contact_id'] ?? ''),
];
$report = jeab_kontakter_link(['dry' => 1], $store);
mid(!empty($report['ok']) && ($report['dry'] ?? false) === true && (int) ($report['created'] ?? 1) === 0, 'link_report skriver inget');
mid(count(jeab_kontakter_load()) === $beforeContacts, 'antalet kontakter är oförändrat');
mid($beforeLinks === [
    (string) ($store->getReminder((int) $oldRem['id'])['contact_id'] ?? ''),
    (string) ($store->getReminder((int) $sameStreetOld['id'])['contact_id'] ?? ''),
    (string) ($store->getReminder((int) $otherRem['id'])['contact_id'] ?? ''),
], 'länkarna ligger kvar');

$groupsFor = static function (array $report, string $needle) : array {
    $hit = [];
    foreach ((array) ($report['groups'] ?? []) as $group) {
        if (!is_array($group)) {
            continue;
        }
        $ids = array_merge(
            (array) ($group['reminders'] ?? []),
            (array) ($group['orders'] ?? []),
            (array) ($group['orders_skipped_closed'] ?? [])
        );
        $ids = array_map('strval', $ids);
        if (in_array($needle, $ids, true) || (string) ($group['contact_id'] ?? '') === $needle) {
            $hit[] = $group;
        }
    }

    return $hit;
};
$andersGroups = $groupsFor($report, $anders);
$andersGroups = array_values(array_filter($andersGroups, static function (array $group) use ($oldRem): bool {
    $ids = array_map('strval', array_merge((array) ($group['reminders'] ?? []), (array) ($group['orders'] ?? [])));

    return in_array((string) $oldRem['id'], $ids, true) || in_array('5810', $ids, true);
}));
mid(count($andersGroups) === 1, 'samma kontakt är en grupp, fick ' . count($andersGroups));
$andersGroup = $andersGroups[0] ?? [];
$andersIds = array_map('strval', array_merge((array) ($andersGroup['reminders'] ?? []), (array) ($andersGroup['orders'] ?? [])));
mid(in_array((string) $oldRem['id'], $andersIds, true) && in_array('5810', $andersIds, true), 'både den gamla länken och ordern finns i gruppen');
mid((string) ($andersGroup['fastighetsbeteckning'] ?? '') === 'KARLEN 1:1', 'gruppen har fastigheten');
mid((string) ($andersGroup['contact_id'] ?? '') === $anders, 'gruppen pekar på den befintliga kontakten');

$sameGroups = $groupsFor($report, (string) $sameStreetOld['id']);
mid(count($sameGroups) === 1, 'samma person på samma gata är en grupp');
$sameIds = array_map('strval', array_merge(
    (array) ($sameGroups[0]['reminders'] ?? []),
    (array) ($sameGroups[0]['orders'] ?? [])
));
mid(in_array((string) $sameStreetOld['id'], $sameIds, true) && in_array('5811', $sameIds, true), 'båda länkarna på samma gata finns kvar');
mid((string) ($sameGroups[0]['fastighetsbeteckning'] ?? '') === 'SAMEN 1:1', 'fastigheten följer med den sammanslagna gruppen');

$mia = mid_contact('Mia Holm', $pnrA, 'Miagatan 5', 'mia@example.test');
$miaRem = jeab_create_reminder($store, 'Mia ensam', jeab_today_start(), null, null, [
    'customer' => 'Mia Holm',
    'email' => 'mia.rem@example.test',
    'address' => 'Miagatan 5, 17730 Jarfalla',
    'company' => 'jarfalla',
    'who' => 'Elin',
    'contact_id' => $mia,
    'lookup' => false,
]);
$orders['5812'] = [
    'reminder_id' => 5812,
    'contact_id' => $other,
    'name' => 'Bo Ek',
    'fastighetsbeteckning' => 'EKEN 2:2',
    'address' => 'Annan vägen 9, 17730 Jarfalla',
    'owners' => [
        ['name' => 'Bo Ek', 'personnummer' => '', 'address' => 'Annan vägen 9'],
    ],
    'kind' => 'batteri',
    'company' => 'jarfalla',
];
$orders['5813'] = [
    'reminder_id' => 5813,
    'contact_id' => $mia,
    'name' => 'Mia Holm',
    'fastighetsbeteckning' => '',
    'address' => 'Holmgatan 4, 17731 Jarfalla',
    'owners' => [
        ['name' => 'Mia Holm', 'personnummer' => '', 'address' => 'Holmgatan 4'],
    ],
    'kind' => 'batteri',
    'company' => 'jarfalla',
];
$GLOBALS['_JEAB_PURCHASE_LIST'] = $orders;
$report = jeab_kontakter_link(['dry' => 1], $store);
$kept = [
    (string) ($store->getReminder((int) $oldRem['id'])['contact_id'] ?? ''),
    (string) ($store->getReminder((int) $sameStreetOld['id'])['contact_id'] ?? ''),
    (string) ($store->getReminder((int) $otherRem['id'])['contact_id'] ?? ''),
    (string) ($store->getReminder((int) $miaRem['id'])['contact_id'] ?? ''),
];
mid($kept === [$anders, $twin, $other, $mia], 'länkarna pekar kvar på sina kontakter');
$miaGroups = array_values(array_filter($groupsFor($report, $mia), static function (array $group) use ($miaRem): bool {
    $ids = array_map('strval', array_merge((array) ($group['reminders'] ?? []), (array) ($group['orders'] ?? [])));

    return in_array((string) $miaRem['id'], $ids, true) || in_array('5813', $ids, true);
}));
$otherOnly = array_values(array_filter($groupsFor($report, $other), static function (array $group) use ($otherRem): bool {
    $ids = array_map('strval', array_merge((array) ($group['reminders'] ?? []), (array) ($group['orders'] ?? [])));

    return in_array((string) $otherRem['id'], $ids, true) || in_array('5812', $ids, true);
}));
mid(count($otherOnly) === 1, 'samma kontakt på två gator blir en grupp när bara en har fastighet');
mid(count($miaGroups) === 2, 'samma kontakt utan fastighet på någon källa blir kvar som två grupper, fick ' . count($miaGroups));

$reportJson = json_encode($report);
mid(is_string($reportJson), 'rapporten kan kodas');
mid_hides((string) $reportJson, $digitsA, 'rapporten innehåller inte nummer A');
mid_hides((string) $reportJson, $digitsB, 'rapporten innehåller inte nummer B');

$log = '';
foreach ([$tmp . '/app-errors.jsonl', $tmp . '/php-error.log'] as $path) {
    if (is_file($path)) {
        $log .= (string) file_get_contents($path);
    }
}
mid_hides($log, $digitsA, 'felloggen innehåller inte nummer A');
mid_hides($log, $digitsB, 'felloggen innehåller inte nummer B');

echo "pnr middle name: ok\n";
