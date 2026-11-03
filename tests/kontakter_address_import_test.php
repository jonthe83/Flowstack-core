<?php

declare(strict_types=1);

/**
 * kontakter.address_import. Bara påhittade kontakter och adresser.
 * dry=1 skriver inget. dry=0 tar kopia och fyller bara tomma fält.
 */

$tmp = sys_get_temp_dir() . '/jeab-address-import-' . bin2hex(random_bytes(4));
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
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/kontakter_address_import.php';

function ai_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function ai(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    ai_fail($message);
}

function ai_pnr(string $nine): string
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
    return $nine . (string) ((10 - ($sum % 10)) % 10);
}

/** @param array<string, mixed> $hint */
function ai_contact(string $id, array $hint): array
{
    $row = jeab_kontakt_blank($hint);
    $row['id'] = $id;
    return $row;
}

/** @param list<array<string, mixed>> $rows */
function ai_save(array $rows): void
{
    jeab_kontakter_mutate(static function (array &$contacts) use ($rows): void {
        $contacts = [];
        foreach ($rows as $row) {
            $contacts[(string) $row['id']] = $row;
        }
    }, true);
}

/** Registret ligger i SQL. kontakter.json är inte längre lagringen. */
function ai_registry_raw(): string
{
    $json = json_encode(jeab_kontakter_load(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($json) ? $json : '';
}

/** @param array<string, mixed> $result @return list<string> */
function ai_ids(array $result, string $bucket): array
{
    $ids = [];
    foreach ((array) ($result[$bucket] ?? []) as $item) {
        if (is_array($item)) {
            $ids[] = (string) ($item['contact_id'] ?? '');
        }
    }
    return $ids;
}

/** @param array<string, mixed> $result @return array<string, mixed>|null */
function ai_item(array $result, string $bucket, string $id): ?array
{
    foreach ((array) ($result[$bucket] ?? []) as $item) {
        if (is_array($item) && (string) ($item['contact_id'] ?? '') === $id) {
            return $item;
        }
    }
    return null;
}

/** @return list<string> */
function ai_baks(string $dir): array
{
    $found = glob($dir . '/kontakter.bak-*.json');
    return is_array($found) ? $found : [];
}

$pnrA = ai_pnr('800101123');
$pnrB = ai_pnr('900101123');
$pnrA12 = jeab_norm_pnr($pnrA);
$pnrB12 = jeab_norm_pnr($pnrB);
$pnrAStored = jeab_kontakt_pnr_store($pnrA);
ai($pnrA12 !== '' && $pnrB12 !== '' && $pnrAStored !== '', 'testdata har syntetiska personnummer');

$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-address-import']));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = jeab_kontakter_address_import_dispatch(['action' => 'kontakter.address_import']);
ai(($denied['status'] ?? 0) === 401 && ($denied['ok'] ?? true) === false, 'utan token ger 401');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-address-import';

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
ai(str_contains($hook, "'kontakter.address_import'") && str_contains($hook, 'jeab_kontakter_address_import_dispatch'), 'hook.php registrerar kontakter.address_import');
$src = (string) file_get_contents(dirname(__DIR__) . '/src/kontakter_address_import.php');
ai(str_contains($src, 'jeab_kontakt_quiet_call') && str_contains($src, 'jeab_kontakter_backup_snapshot'), 'körningen är tyst och återanvänder säkerhetskopian');
ai(!str_contains($src, 'error_log') && !str_contains($src, 'file_put_contents'), 'importen loggar inte radinnehåll');

$evidence = 'HEMLIGBEVIS ' . $pnrB12;
$empty = ai_contact('kt_empty', [
    'name' => 'Test Person',
    'email' => 'empty@example.test',
    'personnummer' => $pnrA,
]);
$phone = ai_contact('kt_phone', [
    'name' => 'Test Telefon',
    'email' => 'phone@example.test',
    'phone' => '0701111111',
]);
$full = ai_contact('kt_full', [
    'name' => 'Test Full',
    'email' => 'full@example.test',
    'street' => 'Fiktivvägen 12',
    'street_key' => jeab_kontakt_street_key('Fiktivvägen 12'),
    'postal_code' => '99991',
    'city' => 'Nordstad',
]);
$taken = ai_contact('kt_taken', [
    'name' => 'Test Ledig',
    'email' => 'taken@example.test',
]);
$batchA = ai_contact('kt_batch_a', [
    'name' => 'Test Batch A',
    'email' => 'batch.a@example.test',
]);
$batchB = ai_contact('kt_batch_b', [
    'name' => 'Test Batch B',
    'email' => 'batch.b@example.test',
]);
$country = ai_contact('kt_country', [
    'name' => 'Test Land',
    'email' => 'country@example.test',
]);
$sverige = ai_contact('kt_sverige', [
    'name' => 'Test Landtom',
    'email' => 'landtom@example.test',
]);
$low = ai_contact('kt_low', [
    'name' => 'Test Lag',
    'email' => 'low@example.test',
]);
$under = ai_contact('kt_under', [
    'name' => 'Test Under',
    'email' => 'under@example.test',
]);
$badPostal = ai_contact('kt_bad_postal', [
    'name' => 'Test Post',
    'email' => 'postal@example.test',
]);
$note = ai_contact('kt_note', [
    'name' => 'Test Not',
    'email' => 'note@example.test',
]);
$note['ovrigt'] = 'Fanns redan';
$partial = ai_contact('kt_partial', [
    'name' => 'Test Del',
    'email' => 'partial@example.test',
    'street' => 'Delstigen 6',
    'street_key' => jeab_kontakt_street_key('Delstigen 6'),
]);
$postalBlock = ai_contact('kt_postal_block', [
    'name' => 'Test Postblock',
    'email' => 'postblock@example.test',
    'postal_code' => '99998',
]);
$postalOk = ai_contact('kt_postal_ok', [
    'name' => 'Test Postok',
    'email' => 'postok@example.test',
    'postal_code' => '99996',
]);
$edge = ai_contact('kt_edge', [
    'name' => 'Test Kant',
    'email' => 'edge@example.test',
]);
ai_save([$empty, $phone, $full, $taken, $batchA, $batchB, $country, $sverige, $low, $under, $badPostal, $note, $partial, $postalBlock, $postalOk, $edge]);

$row = static function (string $id, string $street, string $number, string $postal, string $city, float $confidence, ?string $phone = null, string $source = 'register'): array {
    global $evidence;
    $item = [
        'contact_id' => $id,
        'street' => $street,
        'number' => $number,
        'postal' => $postal,
        'city' => $city,
        'source' => $source,
        'evidence' => $evidence,
        'confidence' => $confidence,
    ];
    if ($phone !== null) {
        $item['phone'] = $phone;
    }
    return $item;
};

$rows = [
    $row('kt_empty', 'Dalstigen', '4', '99991', 'Nordstad', 0.95, '0701234567'),
    $row('kt_phone', 'Provstigen', '3', '99992', 'Sydby', 0.91, '0709999999'),
    $row('kt_full', 'Korsvagen', '1', '99993', 'Sydby', 0.99),
    $row('kt_taken', 'Fiktivväg', '12', '99991', 'nordstad', 0.9),
    $row('kt_batch_a', 'Kvarnvägen', '9', '99994', 'Ostby', 0.88),
    $row('kt_batch_b', 'Kvarnväg', '9', '99994', 'Ostby', 0.87),
    $row('kt_sverige', 'Landstigen', '1', '99995', 'Sverige', 0.9),
    $row('kt_country', 'Haststigen', '5', '99995', 'Vastby, Sverige', 0.93),
    $row('kt_low', 'Lagvagen', '1', '99995', 'Lagby', 0.5),
    $row('kt_under', 'Undervagen', '2', '99995', 'Lagby', 0.79),
    $row('kt_missing', 'Saknasvagen', '3', '99995', 'Lagby', 0.9),
    $row($pnrB, 'Saknasvagen', '4', '99995', 'Lagby', 0.9),
    $row('kt_bad_postal', 'Felstigen', '1', '1234', 'Felby', 0.9),
    $row('kt_note', 'Backstigen', '7', '99997', 'Notby', 0.92, null, 'register ' . $pnrB),
    $row('kt_partial', 'Delstigen', '6', '99992', 'Delby', 0.9),
    $row('kt_postal_block', 'Blockstigen', '8', '99997', 'Blockby', 0.9),
    $row('kt_postal_ok', 'Bruksvagen', '2', '99996', 'Brukby', 0.9),
    $row('kt_edge', 'Kantvagen', '1', '99990', 'Kantby', 0.8),
    $row('kt_empty', 'Dalstigen', '4', '99991', 'Nordstad', 0.95, '0701234567'),
];

$before = (string) ai_registry_raw();
$dry = jeab_kontakter_address_import_dispatch([
    'action' => 'kontakter.address_import',
    'rows' => json_encode($rows, JSON_UNESCAPED_UNICODE),
]);
ai(($dry['status'] ?? 0) === 200 && ($dry['ok'] ?? false) === true && ($dry['dry'] ?? false) === true, 'dry saknas betyder dry=1');
ai(($dry['min_confidence'] ?? 0) === 0.8, 'min_confidence är 0.8 som standard');
ai(($dry['applied'] ?? 1) === 0 && array_key_exists('backup_file', $dry) && $dry['backup_file'] === null, 'torrkörning rapporterar inga skrivningar');
ai(ai_registry_raw() === $before, 'torrkörning lämnar kontaktfilen');
ai(ai_baks($private) === [] && ai_baks($public) === [], 'torrkörning skriver ingen kontakter.bak');
ai(!jeab_kontakt_quiet(), 'tystläget släpps efter torrkörningen');

$counts = (array) ($dry['counts'] ?? []);
ai($counts === [
    'apply' => 7,
    'low_confidence' => 2,
    'duplicate_address' => 3,
    'already_has_address' => 3,
    'contact_missing' => 2,
    'invalid' => 2,
], 'antal per hink');
ai(ai_ids($dry, 'apply') === ['kt_empty', 'kt_phone', 'kt_country', 'kt_note', 'kt_partial', 'kt_postal_ok', 'kt_edge'], 'apply är raderna som får skrivas');
ai(ai_ids($dry, 'low_confidence') === ['kt_low', 'kt_under'], 'låg confidence får en egen hink');
ai(ai_ids($dry, 'duplicate_address') === ['kt_taken', 'kt_batch_a', 'kt_batch_b'], 'dubbletter i registret och i batchen');
ai(ai_ids($dry, 'already_has_address') === ['kt_full', 'kt_postal_block', 'kt_empty'], 'redan adress, ifyllt postnummer eller tidigare rad');
ai(ai_ids($dry, 'invalid') === ['kt_sverige', 'kt_bad_postal'], 'ogiltig ort och postnummer');
$takenItem = ai_item($dry, 'duplicate_address', 'kt_taken');
$batchAItem = ai_item($dry, 'duplicate_address', 'kt_batch_a');
$batchBItem = ai_item($dry, 'duplicate_address', 'kt_batch_b');
ai(is_array($takenItem) && ($takenItem['other_contact_ids'] ?? []) === ['kt_full'], 'befintlig adress listar den andra kontakten');
ai(is_array($batchAItem) && ($batchAItem['other_contact_ids'] ?? []) === ['kt_batch_b'], 'batchrad A listar B');
ai(is_array($batchBItem) && ($batchBItem['other_contact_ids'] ?? []) === ['kt_batch_a'], 'batchrad B listar A');
ai((string) (ai_item($dry, 'invalid', 'kt_sverige')['reason'] ?? '') === 'orten saknas', 'landet blir ingen ort');
ai((string) (ai_item($dry, 'already_has_address', 'kt_full')['reason'] ?? '') === 'kontakten har redan adress', 'full adress skrivs inte över');
$maskedB = jeab_kontakt_pnr_mask($pnrB12);
ai(in_array($maskedB, ai_ids($dry, 'contact_missing'), true), 'personnummer i id maskeras');
$dryJson = json_encode($dry, JSON_UNESCAPED_UNICODE);
ai(is_string($dryJson), 'svaret går att koda');
$leaked = false;
foreach (['Dalstigen', 'Fiktiv', 'Kvarn', 'Haststigen', 'HEMLIGBEVIS', '99991', $pnrA, $pnrA12, $pnrAStored, $pnrB, $pnrB12, 'evidence'] as $secret) {
    if ($secret !== '' && str_contains((string) $dryJson, $secret)) {
        $leaked = true;
    }
}
ai(!$leaked, 'svaret saknar adress, evidence och personnummer');
$shapeOk = true;
foreach (['apply', 'low_confidence', 'already_has_address', 'contact_missing', 'invalid'] as $bucket) {
    foreach ((array) ($dry[$bucket] ?? []) as $item) {
        if (!is_array($item) || array_keys($item) !== ['contact_id', 'reason', 'confidence']) {
            $shapeOk = false;
        }
    }
}
foreach ((array) ($dry['duplicate_address'] ?? []) as $item) {
    if (!is_array($item) || array_keys($item) !== ['contact_id', 'reason', 'confidence', 'other_contact_ids']) {
        $shapeOk = false;
    }
}
ai($shapeOk, 'hinkarna har bara id, orsak, confidence och vid dubblett other_contact_ids');

$onlyDup = jeab_kontakter_address_import_dispatch([
    'action' => 'kontakter.address_import',
    'dry' => 0,
    'rows' => [
        $row('kt_batch_a', 'Kvarnvägen', '9', '99994', 'Ostby', 0.88),
        $row('kt_batch_b', 'Kvarnväg', '9', '99994', 'Ostby', 0.87),
    ],
]);
ai(($onlyDup['ok'] ?? false) === true && ($onlyDup['applied'] ?? 1) === 0 && array_key_exists('backup_file', $onlyDup) && $onlyDup['backup_file'] === null, 'dry=0 utan apply skriver inget');
ai(ai_registry_raw() === $before && ai_baks($private) === [], 'avvisad batch lämnar filen och tar ingen kopia');

$apply = jeab_kontakter_address_import_dispatch([
    'action' => 'kontakter.address_import',
    'dry' => 0,
    'rows' => $rows,
]);
ai(($apply['status'] ?? 0) === 200 && ($apply['dry'] ?? true) === false && ($apply['applied'] ?? 0) === 7, 'dry=0 skriver apply-raderna');
$backupName = (string) ($apply['backup_file'] ?? '');
ai(jeab_kontakt_backup_filename($backupName), 'backup_file är ett kontakter.bak-namn');
$backupPath = $private . '/' . $backupName;
ai(is_file($backupPath) && ai_baks($public) === [], 'kopian ligger i datakatalogen');
$backupRaw = (string) file_get_contents($backupPath);
ai(!str_contains($backupRaw, $pnrA) && !str_contains($backupRaw, $pnrA12) && !str_contains($backupRaw, $pnrAStored), 'kopian maskerar personnummer');
ai(!str_contains($backupRaw, 'Dalstigen'), 'kopian tas före skrivningen');
ai(!jeab_kontakt_quiet(), 'tystläget släpps efter skrivningen');

$live = jeab_kontakter_load();
$liveRaw = (string) ai_registry_raw();
$emptyAfter = $live['kt_empty'];
ai((string) ($emptyAfter['street'] ?? '') === 'Dalstigen 4', 'gatan sparas med nummer');
ai((string) ($emptyAfter['postal_code'] ?? '') === '99991' && (string) ($emptyAfter['city'] ?? '') === 'Nordstad', 'postnummer och ort fylls');
ai((string) ($emptyAfter['street_key'] ?? '') === jeab_kontakt_street_key('Dalstigen 4'), 'gatunyckeln följer registret');
ai(jeab_kontakt_row_phone($emptyAfter) === jeab_kontakt_phone('0701234567'), 'telefon sätts när den saknas');
ai((string) ($emptyAfter['ovrigt'] ?? '') === 'Adress från register (' . substr(jeab_now(), 0, 10) . ')', 'Övrigt får en adressrad');
ai((string) ($emptyAfter['owners'][0]['name'] ?? '') === 'Test Person', 'namnet rörs inte');
ai((string) ($emptyAfter['owners'][0]['personnummer'] ?? '') === $pnrAStored, 'personnumret rörs inte');
ai(!array_key_exists('country', $emptyAfter) && !array_key_exists('land', $emptyAfter), 'landet blir inget fält');

ai(jeab_kontakt_row_phone($live['kt_phone']) === jeab_kontakt_phone('0701111111'), 'befintlig telefon skrivs inte över');
ai((string) ($live['kt_phone']['street'] ?? '') === 'Provstigen 3', 'kontakten med telefon får ändå adress');
ai((string) ($live['kt_full']['street'] ?? '') === 'Fiktivvägen 12' && (string) ($live['kt_full']['city'] ?? '') === 'Nordstad', 'full adress lämnas orörd');
ai(trim((string) ($live['kt_taken']['street'] ?? '')) === '' && trim((string) ($live['kt_batch_a']['street'] ?? '')) === '' && trim((string) ($live['kt_batch_b']['street'] ?? '')) === '', 'dubbletter skrivs inte');
ai(trim((string) ($live['kt_sverige']['city'] ?? '')) === '' && trim((string) ($live['kt_sverige']['street'] ?? '')) === '', 'Sverige som ort skrivs inte');
ai((string) ($live['kt_country']['city'] ?? '') === 'Vastby' && (string) ($live['kt_country']['street'] ?? '') === 'Haststigen 5', 'landssvansen tas bort från orten');
ai(!str_contains($liveRaw, 'Sverige') && !str_contains($liveRaw, 'Sweden'), 'filen innehåller inte landet');
ai(!str_contains($liveRaw, 'HEMLIGBEVIS') && !str_contains($liveRaw, $pnrB) && !str_contains($liveRaw, $pnrB12), 'evidence och importens personnummer sparas inte');
ai((string) ($live['kt_note']['ovrigt'] ?? '') === 'Fanns redan' . "\n" . 'Adress från register (' . substr(jeab_now(), 0, 10) . ')', 'befintligt Övrigt behålls och källan tappar personnumret');
ai((string) ($live['kt_partial']['street'] ?? '') === 'Delstigen 6' && (string) ($live['kt_partial']['city'] ?? '') === 'Delby', 'ifylld gata skrivs inte över');
ai(jeab_kontakt_row_phone($live['kt_partial']) === '', 'utelämnad telefon lämnar tom telefon tom');
ai(trim((string) ($live['kt_postal_block']['street'] ?? '')) === '' && (string) ($live['kt_postal_block']['postal_code'] ?? '') === '99998', 'avvikande postnummer skrivs inte över');
ai((string) ($live['kt_postal_ok']['street'] ?? '') === 'Bruksvagen 2' && (string) ($live['kt_postal_ok']['postal_code'] ?? '') === '99996', 'samma postnummer lämnas och gatan fylls');
ai(trim((string) ($live['kt_low']['street'] ?? '')) === '' && trim((string) ($live['kt_under']['street'] ?? '')) === '', 'låg confidence skriver inget');

$afterWrite = (string) ai_registry_raw();
$again = jeab_kontakter_address_import_dispatch([
    'action' => 'kontakter.address_import',
    'dry' => '0',
    'rows' => $rows,
]);
ai(($again['ok'] ?? false) === true && ($again['counts']['apply'] ?? 1) === 0 && ($again['applied'] ?? 1) === 0, 'omkörning har noll apply');
ai(in_array('kt_empty', ai_ids($again, 'already_has_address'), true) && in_array('kt_country', ai_ids($again, 'already_has_address'), true), 'skrivna rader blir already_has_address');
ai(array_key_exists('backup_file', $again) && $again['backup_file'] === null && count(ai_baks($private)) === 1, 'omkörning tar ingen ny kopia');
ai(ai_registry_raw() === $afterWrite, 'omkörning ändrar inte filen');

$tooMany = [];
for ($i = 0; $i < 501; $i++) {
    $tooMany[] = $row('kt_empty', 'Dalstigen', '4', '99991', 'Nordstad', 0.95);
}
$overflow = jeab_kontakter_address_import_dispatch([
    'action' => 'kontakter.address_import',
    'dry' => 0,
    'rows' => $tooMany,
]);
ai(($overflow['status'] ?? 0) === 400 && ($overflow['ok'] ?? true) === false, 'mer än 500 rader avvisas');
ai(ai_registry_raw() === $afterWrite && count(ai_baks($private)) === 1, 'för stor batch skriver inget');

$badMin = jeab_kontakter_address_import_dispatch([
    'action' => 'kontakter.address_import',
    'dry' => 0,
    'min_confidence' => 1.5,
    'rows' => [$row('kt_low', 'Lagvagen', '1', '99995', 'Lagby', 0.5)],
]);
ai(($badMin['ok'] ?? true) === false && ai_registry_raw() === $afterWrite, 'ogiltig min_confidence skriver inget');

$strict = jeab_kontakter_address_import_dispatch([
    'action' => 'kontakter.address_import',
    'dry' => 1,
    'min_confidence' => '0,95',
    'rows' => [$row('kt_edge', 'Kantvagen', '1', '99990', 'Kantby', 0.9)],
]);
ai(($strict['ok'] ?? false) === true && ai_ids($strict, 'low_confidence') === ['kt_edge'], 'min_confidence kan höjas');

echo "address import ok\n";
