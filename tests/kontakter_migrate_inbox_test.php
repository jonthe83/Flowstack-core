<?php

declare(strict_types=1);

/**
 * Flytt av inkorgens kontakter. Bara påhittade personer.
 */

$tmp = sys_get_temp_dir() . '/jeab-kontakter-migrate-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'rot'],
    'staff' => [
        ['name' => 'Stina Exempel', 'email' => 'stina.exempel@example.test'],
    ],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function km(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function km_hash(string $path): string
{
    return is_file($path) ? hash('sha256', (string) file_get_contents($path)) : '';
}

/** @return array<string, mixed>|null */
function km_by_email(string $email): ?array
{
    foreach (jeab_kontakter_load() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $projected = jeab_inkorg_contact_project((string) ($row['id'] ?? ''), $row);
        if (in_array($email, (array) ($projected['emails'] ?? []), true)) {
            return $row;
        }
    }
    return null;
}

function km_count_email(string $email): int
{
    $n = 0;
    foreach (jeab_kontakter_load() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $projected = jeab_inkorg_contact_project((string) ($row['id'] ?? ''), $row);
        if (in_array($email, (array) ($projected['emails'] ?? []), true)) {
            $n++;
        }
    }
    return $n;
}

$parsed = jeab_kontakt_migrate_parse_address('Exempelvägen 3, 111 22', 'Exempelby');
km($parsed['street'] === 'Exempelvägen 3' && $parsed['postal'] === '11122' && $parsed['city'] === 'Exempelby', 'postnummer i fritexten tas ut före gatunyckeln');
$same = jeab_kontakt_address_identity('Exempelvägen 3', 'Exempelby');
$variant = jeab_kontakt_address_identity('exempelv. 3', 'Exempelby');
km($same['key'] !== '' && $same['key'] === $variant['key'], 'exempelv. 3 är samma adress som Exempelvägen 3');

$nils = jeab_kontakt_blank([
    'name' => 'Nils Exempel',
    'email' => 'nils@example.test',
    'street' => 'Kvarnvägen 5',
    'street_key' => jeab_kontakt_street_key('Kvarnvägen 5'),
    'postal_code' => '11122',
    'city' => 'Exempelby',
]);
$nils['updated_at'] = '2026-01-01 08:00:00';
$nils['created_at'] = '2026-01-01 08:00:00';
jeab_kontakter_mutate(static function (array &$contacts) use ($nils): array {
    $contacts[(string) $nils['id']] = $nils;
    return $nils;
});
$keep = jeab_kontakt_blank([
    'name' => 'Orörd Exempel',
    'email' => 'orord@example.test',
    'street' => 'Sidovägen 1',
    'street_key' => jeab_kontakt_street_key('Sidovägen 1'),
    'city' => 'Exempelby',
]);
jeab_kontakter_mutate(static function (array &$contacts) use ($keep): array {
    $contacts[(string) $keep['id']] = $keep;
    return $keep;
});
$store = jeab_store();
$reminder = $store->insertReminder([
    'title' => 'Fiktiv påminnelse',
    'created_at' => '2026-09-01 09:00:00',
    'updated_at' => '2026-09-01 09:00:00',
    'due_at' => '2026-09-02 09:00:00',
]);
$store->saveReminder((int) $reminder['id'], [
    'contact_id' => (string) $nils['id'],
    'updated_at' => '2026-09-01 09:00:00',
]);

$old = [
    'c-addr1' => [
        'name' => 'Kalle Exempel',
        'emails' => ['kalle.ett@example.test'],
        'address' => 'Exempelvägen 3',
        'town' => 'Exempelby',
        'mobile' => '070-0000001',
        'notes' => 'portkod 0000',
        'updated_at' => '2026-03-01T10:00:00+01:00',
        'created_at' => '2026-03-01T10:00:00+01:00',
    ],
    'c-postal' => [
        'name' => 'Kalle Exempel',
        'emails' => ['kalle.ett@example.test'],
        'address' => 'Exempelvägen 3, 111 22',
        'town' => 'Exempelby',
        'updated_at' => '2026-02-01T10:00:00+01:00',
    ],
    'c-addr2' => [
        'name' => 'Karin Exempel',
        'emails' => ['karin.exempel@example.test'],
        'address' => 'exempelv. 3',
        'town' => 'Exempelby',
        'updated_at' => '2026-04-02T10:00:00+01:00',
    ],
    'c-mailold' => [
        'name' => 'Sara Exempel',
        'emails' => ['sara@example.test'],
        'address' => 'Gamla Exempelgatan 1',
        'town' => 'Exempelby',
        'updated_at' => '2026-01-15T10:00:00+01:00',
    ],
    'c-mailnew' => [
        'name' => 'Sara Exempel',
        'emails' => ['sara@example.test'],
        'address' => 'Nya Exempelgatan 9',
        'town' => 'Exempelby',
        'updated_at' => '2026-08-01T10:00:00+01:00',
    ],
    'c-lgh1' => [
        'name' => 'Bo Exempel',
        'emails' => ['bo.ett@example.test'],
        'address' => 'Exempelvägen 4 lgh 1101',
        'town' => 'Exempelby',
        'updated_at' => '2026-05-01T10:00:00+01:00',
    ],
    'c-lgh2' => [
        'name' => 'Bo Exempel',
        'emails' => ['bo.tva@example.test'],
        'address' => 'Exempelvägen 4 lgh 1102',
        'town' => 'Exempelby',
        'updated_at' => '2026-05-02T10:00:00+01:00',
    ],
    'c-staff1' => [
        'name' => 'Info Ett',
        'emails' => ['info@example.test'],
        'address' => 'Stavgatan 1',
        'town' => 'Exempelby',
        'updated_at' => '2026-05-03T10:00:00+01:00',
    ],
    'c-staff2' => [
        'name' => 'Info Två',
        'emails' => ['info@example.test'],
        'address' => 'Stavgatan 2',
        'town' => 'Exempelby',
        'updated_at' => '2026-05-04T10:00:00+01:00',
    ],
    'c-extra' => [
        'name' => 'Extra Exempel',
        'emails' => ['extra@example.test', 'andra@example.test'],
        'address' => 'Solvägen 8',
        'town' => 'Exempelby',
        'mobile' => '070-1111111',
        'notes' => 'nyckel hos grannen',
        'kod' => 'blå',
        'fortnox_customer' => '9001',
        'personnummer' => '900101-0000',
        'updated_at' => '2026-06-01T10:00:00+01:00',
    ],
    'c-exist' => [
        'name' => 'Nils Exempel',
        'emails' => ['nils@example.test'],
        'address' => 'Bortavägen 2',
        'town' => 'Exempelby',
        'updated_at' => '2026-02-01T10:00:00+01:00',
    ],
    'c-empty' => [
        'name' => '',
        'emails' => [],
        'address' => '',
        'town' => '',
        'created_at' => '2026-01-01T00:00:00+01:00',
    ],
];
$inkorgDir = jeab_inkorg_dir();
$inboxPath = $inkorgDir . '/contacts.json';
file_put_contents($inboxPath, json_encode(['contacts' => $old], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
file_put_contents($inkorgDir . '/messages.json', json_encode(['messages' => [
    [
        'id' => 'm-sara',
        'thread_id' => 't-sara',
        'from' => 'sara@example.test',
        'from_name' => 'Sara Exempel',
        'subject' => 'Fiktiv fråga från Sara',
        'body' => 'Hej, påhittat.',
        'received_at' => '2026-08-02 10:00:00',
        'source' => 'test',
    ],
    [
        'id' => 'm-kalle',
        'thread_id' => 't-kalle',
        'from' => 'kalle.ett@example.test',
        'from_name' => 'Kalle Exempel',
        'subject' => 'Fiktiv fråga från Kalle',
        'body' => 'Hej, påhittat.',
        'received_at' => '2026-04-03 10:00:00',
        'source' => 'test',
    ],
    [
        'id' => 'm-extra',
        'thread_id' => 't-extra',
        'from' => 'extra@example.test',
        'from_name' => 'Extra Exempel',
        'subject' => 'Fiktiv extra',
        'body' => 'Hej, påhittat.',
        'received_at' => '2026-06-02 10:00:00',
        'source' => 'test',
    ],
    [
        'id' => 'm-keep',
        'thread_id' => 't-keep',
        'from' => 'orord@example.test',
        'from_name' => 'Orörd Exempel',
        'subject' => 'Fiktiv orörd',
        'body' => 'Hej, påhittat.',
        'received_at' => '2026-06-03 10:00:00',
        'source' => 'test',
    ],
]], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
file_put_contents($inkorgDir . '/threads.json', json_encode(['threads' => [
    't-sara' => ['status' => 'open', 'tags' => []],
    't-kalle' => ['status' => 'open', 'tags' => []],
    't-extra' => ['status' => 'open', 'tags' => [], 'contact_id' => 'c-extra'],
    't-keep' => ['status' => 'open', 'tags' => [], 'contact_id' => (string) $keep['id']],
]], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

$rotDir = $tmp . '/rot';
mkdir($rotDir, 0775, true);
$rotFile = $rotDir . '/ansokan-fiktiv.json';
file_put_contents($rotFile, "{\"id\":\"fiktiv\"}\n");
$rotHash = km_hash($rotFile);
$inboxHash = km_hash($inboxPath);
$registerHash = km_hash(jeab_kontakter_path());
$threadHash = km_hash($inkorgDir . '/threads.json');
km(jeab_inkorg_contacts_url() !== '' && str_contains(jeab_inkorg_contacts_url(), 'p=inkorg-kontakter'), 'före flytten pekar kontakter på inkorgens lista');
km(!jeab_inkorg_contacts_archived(), 'markören finns inte före dry=0');

$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-km-test']));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = jeab_kontakter_migrate_inbox_dispatch(['action' => 'kontakter.migrate_inbox_contacts']);
km(($denied['status'] ?? 0) === 401 && ($denied['ok'] ?? true) === false, 'kontakter.migrate_inbox_contacts utan token ger 401');
km(km_hash($inboxPath) === $inboxHash && !is_file(jeab_kontakt_migrate_marker_path()), 'nekad hook skriver inget');

$dry = jeab_kontakter_migrate_inbox([]);
km(($dry['ok'] ?? false) === true && ($dry['dry'] ?? false) === true, 'dry saknas betyder dry=1');
km(($dry['counts']['moved'] ?? 0) > 0 && ($dry['counts']['merged'] ?? 0) > 0, 'torrkörning räknar flyttade och hopslagna');
km(($dry['counts']['ovrigt'] ?? -1) === ($dry['counts']['moved'] ?? 0) + ($dry['counts']['merged'] ?? 0), 'torrkörning räknar spår i Övrigt');
km(($dry['counts']['links'] ?? 0) >= 3, 'torrkörning räknar ärenden som ska pekas om');
$dryJson = json_encode($dry, JSON_UNESCAPED_UNICODE);
km(is_string($dryJson) && !str_contains($dryJson, '900101'), 'torrkörningen innehåller inte personnummer');
km(km_hash($inboxPath) === $inboxHash && km_hash(jeab_kontakter_path()) === $registerHash && !is_file(jeab_kontakt_migrate_marker_path()), 'dry=1 skriver varken fil, register eller markör');
km(!is_file($tmp . '/kontakter-dedupe.log'), 'dry=1 skriver ingen sammanslagningslogg');

require_once dirname(__DIR__) . '/src/fortnox.php';
$sideHits = ['fortnox' => 0];
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function () use (&$sideHits): array {
    $sideHits['fortnox']++;
    return ['Customer' => ['CustomerNumber' => '9001']];
};
$GLOBALS['_JEAB_KONTAKT_SIDE_EFFECTS'] = [];
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-km-test';
$wet = jeab_kontakter_migrate_inbox_dispatch(['action' => 'kontakter.migrate_inbox_contacts', 'dry' => 0]);
km(($wet['ok'] ?? false) === true && ($wet['dry'] ?? true) === false, 'dry=0 flyttar kontakter');
km(($wet['counts']['moved'] ?? -1) === ($dry['counts']['moved'] ?? -2), 'dry=0 flyttar samma antal som torrkörningen');
km(($wet['counts']['merged'] ?? -1) === ($dry['counts']['merged'] ?? -2) && ($wet['counts']['merged'] ?? 0) > 0, 'dry=0 slår ihop samma antal');
km(($wet['counts']['ovrigt'] ?? -1) === ($wet['counts']['moved'] ?? 0) + ($wet['counts']['merged'] ?? 0), 'dry=0 lämnar ett spår i Övrigt per post');
km(($wet['counts']['links'] ?? -1) === ($dry['counts']['links'] ?? -2) && ($wet['counts']['links'] ?? 0) >= 3, 'dry=0 pekar om samma ärenden');
km($sideHits['fortnox'] === 0, 'flytten anropar inte Fortnox');
$effects = $GLOBALS['_JEAB_KONTAKT_SIDE_EFFECTS'] ?? [];
km(is_array($effects) && !in_array('fortnox', $effects, true) && !in_array('rot', $effects, true) && !in_array('mail', $effects, true) && !in_array('sms', $effects, true) && !in_array('quote', $effects, true) && !in_array('auto_quote', $effects, true), 'flytten skickar inget och rör inte ROT');
km(km_hash($rotFile) === $rotHash, 'flytten skriver inte under /rot/');
km(is_file($inboxPath) && km_hash($inboxPath) === $inboxHash, 'contacts.json ligger kvar oförändrad');
km(is_file(jeab_kontakt_migrate_marker_path()), 'markören contacts.json.migrated skrivs');
$marker = (string) file_get_contents(jeab_kontakt_migrate_marker_path());
km(!str_contains($marker, '@') && !str_contains($marker, '900101'), 'markören har varken e-post eller personnummer');
km(jeab_inkorg_contacts_archived(), 'arkivet är skrivskyddat efter dry=0');

$contacts = jeab_kontakter_load();
km(count($contacts) === 9, 'tom rad hoppas över och hopslagna blir inte egna kontakter');
$karin = km_by_email('karin.exempel@example.test');
km(is_array($karin) && str_contains((string) ($karin['ovrigt'] ?? ''), 'inkorg c-addr1') && str_contains((string) ($karin['ovrigt'] ?? ''), 'portkod 0000'), 'samma adress slås ihop och den äldre raden ligger i Övrigt');
km(is_array($karin) && in_array('kalle.ett@example.test', jeab_inkorg_contact_project((string) $karin['id'], $karin)['emails'], true), 'den hopslagna adressen behåller båda e-postadresserna');
$sara = km_by_email('sara@example.test');
km(is_array($sara) && (string) ($sara['street'] ?? '') === 'Nya Exempelgatan 9', 'samma e-post behåller den nyare adressen');
km(is_array($sara) && str_contains((string) ($sara['ovrigt'] ?? ''), 'Gamla Exempelgatan 1') && str_contains((string) ($sara['ovrigt'] ?? ''), 'inkorg c-mailold'), 'den äldre adressen finns kvar i Övrigt');
km(km_count_email('bo.ett@example.test') === 1 && km_count_email('bo.tva@example.test') === 1, 'olika lägenhetsnummer slås inte ihop');
km(km_count_email('info@example.test') === 2, 'personaladress info slås inte ihop');
$extra = km_by_email('extra@example.test');
km(is_array($extra), 'kontakten med extrafält finns i registret');
$extraText = (string) ($extra['ovrigt'] ?? '');
km(str_contains($extraText, 'anteckning: nyckel hos grannen') && str_contains($extraText, 'andra@example.test') && str_contains($extraText, 'inkorg c-extra') && str_contains($extraText, 'kod: blå') && str_contains($extraText, 'Fortnox-kund 9001') && str_contains($extraText, '070-1111111'), 'fält som inte får en egen ruta hamnar i Övrigt');
km(!str_contains($extraText, '900101') && !array_key_exists('fortnox_customer', $extra) && !array_key_exists('personnummer', $extra), 'personnummer och Fortnox-pekaren kopieras inte');
$nilsAfter = jeab_kontakt_get((string) $nils['id']);
km(is_array($nilsAfter) && (string) ($nilsAfter['street'] ?? '') === 'Kvarnvägen 5' && str_contains((string) ($nilsAfter['ovrigt'] ?? ''), 'Bortavägen 2') && str_contains((string) ($nilsAfter['ovrigt'] ?? ''), 'inkorg c-exist'), 'nyare påminnelse behåller registrets adress');
km((string) ($store->getReminder((int) $reminder['id'])['contact_id'] ?? '') === (string) $nils['id'], 'påminnelsen pekar kvar på kontakten som behålls');

$meta = jeab_inkorg_threads_meta();
km((string) ($meta['t-sara']['contact_id'] ?? '') === (string) ($sara['id'] ?? ''), 'ärende utan contact_id pekar på den nya kontakten');
km((string) ($meta['t-extra']['contact_id'] ?? '') === (string) ($extra['id'] ?? ''), 'gammalt c-id pekas om till contact_id');
km((string) ($meta['t-kalle']['contact_id'] ?? '') === (string) ($karin['id'] ?? ''), 'ärendet följer den hopslagna kontakten');
km((string) ($meta['t-keep']['contact_id'] ?? '') === (string) $keep['id'], 'ett annat contact_id skrivs inte över');
$cases = jeab_hs_import_threads_for_contact((string) ($sara['id'] ?? ''));
$subjects = array_map(static fn(array $case): string => (string) ($case['subject'] ?? ''), $cases);
km(in_array('Fiktiv fråga från Sara', $subjects, true), 'kontaktens inkorgslista visar ärendet');

$projected = jeab_inkorg_contacts();
km($projected !== [] && str_starts_with((string) array_key_first($projected), 'kt_'), 'inkorgens läsning använder registrets id');
km(isset($projected[(string) ($sara['id'] ?? '')]), 'den nya kontakten syns i inkorgens kontaktlista');
km(str_contains(jeab_inkorg_contacts_url((string) ($sara['id'] ?? '')), 'p=kontakter') && str_contains(jeab_inkorg_contacts_url((string) ($sara['id'] ?? '')), 'id='), 'efter flytten går kontaktsidan till registret');
km(str_contains(jeab_inkorg_contacts_url('c-mailnew'), 'id=' . rawurlencode((string) ($sara['id'] ?? 'saknas'))), 'gammalt c-id i länken blir den nya kontaktens id');

$saved = jeab_inkorg_contact_save([
    'id' => (string) ($extra['id'] ?? ''),
    'name' => 'Extra Exempel',
    'emails' => 'extra@example.test',
    'mobile' => '070-1111111',
    'address' => 'Solvägen 8',
    'town' => 'Exempelby',
    'notes' => $extraText,
]);
km((string) ($saved['id'] ?? '') === (string) ($extra['id'] ?? '') && km_hash($inboxPath) === $inboxHash, 'sparning skriver i registret och lämnar arkivet');
jeab_inkorg_contact_delete((string) ($extra['id'] ?? ''));
km(jeab_kontakt_get((string) ($extra['id'] ?? '')) !== null && km_hash($inboxPath) === $inboxHash, 'borttagning raderar varken register eller arkiv');
$beforeEnsure = count(jeab_kontakter_load());
$ensured = jeab_inkorg_contact_ensure([
    'email' => 'ny.fiktiv@example.test',
    'name' => 'Ny Fiktiv',
    'phone' => '',
    'address' => 'Nyvägen 4',
    'platform' => '',
    'source' => 'from',
], [
    'from' => 'ny.fiktiv@example.test',
    'from_name' => 'Ny Fiktiv',
    'body' => "Hej\nNyvägen 4\n111 22 Exempelby",
    'source' => 'test',
]);
km(is_string($ensured) && str_starts_with($ensured, 'kt_') && count(jeab_kontakter_load()) === $beforeEnsure + 1 && km_hash($inboxPath) === $inboxHash, 'nytt mejl skapar kontakt i registret');
$againEnsure = jeab_inkorg_contact_ensure([
    'email' => 'ny.fiktiv@example.test',
    'name' => 'Ny Fiktiv',
    'phone' => '',
    'address' => '',
    'platform' => '',
    'source' => 'from',
], ['from' => 'ny.fiktiv@example.test', 'from_name' => 'Ny Fiktiv', 'body' => 'Hej igen', 'source' => 'test']);
km($againEnsure === $ensured && count(jeab_kontakter_load()) === $beforeEnsure + 1, 'samma e-post skapar inte en andra kontakt');

$markerHash = km_hash(jeab_kontakt_migrate_marker_path());
$registerAfter = km_hash(jeab_kontakter_path());
$threadsAfter = km_hash($inkorgDir . '/threads.json');
$second = jeab_kontakter_migrate_inbox(['dry' => 0]);
km(($second['counts']['moved'] ?? -1) === 0 && ($second['counts']['merged'] ?? -1) === 0 && ($second['counts']['ovrigt'] ?? -1) === 0 && ($second['counts']['links'] ?? -1) === 0, 'andra dry=0 ändrar inget');
km(km_hash($inboxPath) === $inboxHash && km_hash(jeab_kontakt_migrate_marker_path()) === $markerHash && km_hash(jeab_kontakter_path()) === $registerAfter && km_hash($inkorgDir . '/threads.json') === $threadsAfter, 'andra körningen skriver inte om filerna');
$dryAgain = jeab_kontakter_migrate_inbox([]);
km(($dryAgain['counts']['moved'] ?? -1) === 0 && ($dryAgain['counts']['links'] ?? -1) === 0, 'torrkörning efter flytten är tom');

$threw = false;
try {
    jeab_inkorg_locked('contacts.json', static function (?array $data): array {
        $data = is_array($data) ? $data : ['contacts' => []];
        $data['contacts']['c-skriv'] = ['name' => 'Ska inte in'];
        return [null, $data];
    });
} catch (RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'arkiverade');
}
km($threw && km_hash($inboxPath) === $inboxHash, 'skrivning mot den gamla filen stoppas');
$log = is_file($tmp . '/kontakter-dedupe.log') ? (string) file_get_contents($tmp . '/kontakter-dedupe.log') : '';
km($log === '' || !str_contains($log, '900101'), 'sammanslagningsloggen innehåller inte personnummer');
km(!jeab_kontakt_quiet(), 'spärren släpps när flytten är klar');

echo "kontakter migrate inbox: ok\n";
