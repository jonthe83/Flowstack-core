<?php

declare(strict_types=1);

/**
 * kontakter.import_new. Bara påhittade kontakter.
 * dry=1 skriver inget. dry=0 tar kopia och skapar nya rader. Andra körningen skapar noll.
 */

$tmp = sys_get_temp_dir() . '/jeab-import-new-' . bin2hex(random_bytes(4));
$private = $tmp . '/private';
$public = $tmp . '/public';
mkdir($private, 0775, true);
mkdir($public, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $private;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $public;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
file_put_contents($private . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $private . '/app.sqlite', 'json_path' => $private . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/kontakter_import_new.php';

function cin_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function cin(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    cin_fail($message);
}

/** @param array<string, mixed> $hint */
function cin_contact(string $id, array $hint): array
{
    $row = jeab_kontakt_blank($hint);
    $row['id'] = $id;
    $row['typ'] = 'Privatperson';
    return $row;
}

/** @param list<array<string, mixed>> $rows */
function cin_save(array $rows): void
{
    jeab_kontakter_mutate(static function (array &$contacts) use ($rows): void {
        $contacts = [];
        foreach ($rows as $row) {
            $contacts[(string) $row['id']] = $row;
        }
    });
}

/** @param array<string, mixed> $result @return list<array<string, mixed>> */
function cin_samples(array $result, string $bucket): array
{
    $items = $result['samples'][$bucket] ?? [];
    return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
}

/** @param list<array<string, mixed>> $items */
function cin_dup(array $items, string $key): ?array
{
    foreach ($items as $item) {
        if ((string) ($item['matched_key'] ?? '') === $key) {
            return $item;
        }
    }
    return null;
}

/** @return list<string> */
function cin_baks(string $dir): array
{
    $found = glob($dir . '/kontakter.bak-*.json');
    return is_array($found) ? $found : [];
}

$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-import-new']));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = jeab_kontakter_import_new_dispatch(['action' => 'kontakter.import_new', 'rows' => []]);
cin(($denied['status'] ?? 0) === 401 && ($denied['ok'] ?? true) === false, 'utan token ger 401');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-import-new';

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
cin(str_contains($hook, "'kontakter.import_new'") && str_contains($hook, 'jeab_kontakter_import_new_dispatch'), 'hook.php registrerar kontakter.import_new');
$src = (string) file_get_contents(dirname(__DIR__) . '/src/kontakter_import_new.php');
$storeSrc = (string) file_get_contents(dirname(__DIR__) . '/src/contact_store.php');
cin(str_contains($src, 'JeabContactStore') && str_contains($storeSrc, 'class JeabJsonContactStore'), 'hooken skriver via kontaktlagret');
cin(str_contains($src, 'jeab_norm_street_key'), 'adressnyckeln är gata och postnummer');
cin(!str_contains($src, 'error_log') && !str_contains($storeSrc, 'error_log'), 'importen loggar inte radinnehåll');
cin(jeab_contact_store() instanceof JeabPdoContactStore, 'registret är SQL');

cin_save([
    cin_contact('kt_mail', [
        'name' => 'Mail Test',
        'email' => 'import.mail@example.test',
        'phone' => '0701110001',
        'street' => 'Annanvägen 1',
        'street_key' => jeab_kontakt_street_key('Annanvägen 1'),
        'postal_code' => '11111',
        'city' => 'Alfa',
    ]),
    cin_contact('kt_phone', [
        'name' => 'Telefon Test',
        'email' => 'import.phone@example.test',
        'phone' => '0702220002',
        'street' => 'Annanvägen 2',
        'street_key' => jeab_kontakt_street_key('Annanvägen 2'),
        'postal_code' => '22222',
        'city' => 'Beta',
    ]),
    cin_contact('kt_addr', [
        'name' => 'Adress Test',
        'email' => 'import.addr@example.test',
        'phone' => '0703330003',
        'street' => 'Nyckelvägen 9',
        'street_key' => jeab_kontakt_street_key('Nyckelvägen 9'),
        'postal_code' => '33333',
        'city' => 'Gamma',
    ]),
]);

$rows = [
    ['namn' => 'Nya Test', 'mejl' => 'import.new@example.test', 'telefon' => '0704440004', 'gata' => 'Solstigen 3', 'postnr' => '44444', 'ort' => 'Delta'],
    ['namn' => 'Mail Dup', 'mejl' => 'Import.Mail@Example.TEST', 'telefon' => '0708880008', 'gata' => 'Solstigen 8', 'postnr' => '88888', 'ort' => 'Delta'],
    ['namn' => 'Telefon Dup', 'mejl' => 'import.phonedup@example.test', 'telefon' => '070-222 00 02', 'gata' => 'Solstigen 7', 'postnr' => '77777', 'ort' => 'Delta'],
    ['namn' => 'Adress Dup', 'mejl' => 'import.addrdup@example.test', 'telefon' => '0707770007', 'gata' => 'Nyckel-vägen 9', 'postnr' => '333 33', 'ort' => 'Annanort'],
    ['namn' => 'Batch Dup', 'mejl' => 'import.new@example.test', 'telefon' => '0709990009', 'gata' => 'Solstigen 5', 'postnr' => '12121', 'ort' => 'Delta'],
    ['namn' => 'Kim Exempel', 'mejl' => 'kim.import@example.test', 'telefon' => '0705550005', 'gata' => 'Sverige Provgatan 4', 'postnr' => '99991', 'ort' => 'Testort, Sverige'],
    ['namn' => 'Sverige', 'mejl' => 'tom.namn@example.test', 'telefon' => '', 'gata' => '', 'postnr' => '', 'ort' => ''],
    ['namn' => 'Annat Postnr', 'mejl' => 'import.otherpostal@example.test', 'telefon' => '0706660006', 'gata' => 'Nyckelvägen 9', 'postnr' => '55555', 'ort' => 'Gamma'],
];

$contactFile = jeab_kontakter_path();
$beforeCount = count(jeab_kontakter_load());
cin(!is_file($contactFile), 'kontakter.json finns inte');
$missingOffice = jeab_kontakter_import_new_dispatch(['action' => 'kontakter.import_new', 'rows' => $rows, 'dry' => 0]);
cin(($missingOffice['ok'] ?? true) === false && ($missingOffice['status'] ?? 0) === 400 && ($missingOffice['error'] ?? '') === 'Bolag saknas.', 'import utan bolag nekas');
$badOffice = jeab_kontakter_import_new_dispatch(['action' => 'kontakter.import_new', 'rows' => $rows, 'dry' => 0, 'company' => 'Stockholm']);
cin(($badOffice['ok'] ?? true) === false && ($badOffice['error'] ?? '') === 'Bolaget är ogiltigt.' && !str_contains((string) ($badOffice['error'] ?? ''), 'Stockholm'), 'ogiltigt bolag nekas utan att ekas');
cin(!is_file($contactFile) && cin_baks($private) === [], 'nekad import skriver ingen json och ingen kopia');
$dry = jeab_kontakter_import_new_dispatch(['action' => 'kontakter.import_new', 'rows' => $rows, 'bolag' => 'jarfalla']);
cin(($dry['ok'] ?? false) === true && ($dry['dry'] ?? false) === true, 'utan dry är körningen torr');
cin(($dry['counts']['would_create'] ?? -1) === 3, 'torrkörning skulle skapa tre');
cin(($dry['counts']['duplicates'] ?? -1) === 4, 'torrkörning har fyra dubbletter');
cin(($dry['counts']['invalid'] ?? -1) === 1, 'torrkörning har en ogiltig rad');
cin(!is_file($contactFile) && cin_baks($private) === [], 'torrkörning skriver ingen json och ingen kopia');

$dups = cin_samples($dry, 'duplicates');
$byMail = cin_dup($dups, 'mejl');
$byPhone = cin_dup($dups, 'telefon');
$byAddress = cin_dup($dups, 'adress');
$batch = null;
foreach ($dups as $item) {
    if ((string) ($item['matched_id'] ?? '') === 'batch:0') {
        $batch = $item;
    }
}
cin(is_array($byMail) && ($byMail['matched_id'] ?? '') === 'kt_mail', 'mejl träffar kontakten');
cin(is_array($byPhone) && ($byPhone['matched_id'] ?? '') === 'kt_phone', 'telefon träffar kontakten');
cin(is_array($byAddress) && ($byAddress['matched_id'] ?? '') === 'kt_addr', 'adressnyckeln träffar kontakten');
cin(is_array($batch) && ($batch['matched_key'] ?? '') === 'mejl', 'dubblett i samma batch hoppas över');
$invalid = cin_samples($dry, 'invalid');
cin(count($invalid) === 1 && ($invalid[0]['reason'] ?? '') === 'namn saknas' && ($invalid[0]['index'] ?? -1) === 6, 'Sverige som namn är ogiltigt');
$encoded = json_encode($dry, JSON_UNESCAPED_UNICODE);
cin(is_string($encoded) && !str_contains($encoded, '@') && stripos($encoded, 'sverige') === false && !str_contains($encoded, 'Provgatan') && !str_contains($encoded, 'Nyckel'), 'svaret har inga kundfält');

$wet = jeab_kontakter_import_new_dispatch(['action' => 'kontakter.import_new', 'rows' => $rows, 'dry' => 0, 'company' => 'JEAB']);
cin(($wet['ok'] ?? false) === true && ($wet['dry'] ?? true) === false, 'dry=0 skriver');
cin(count((array) ($wet['created_ids'] ?? [])) === 3 && ($wet['counts']['created'] ?? -1) === 3, 'tre kontakter skapas');
$baks = cin_baks($private);
cin(count($baks) === 1 && ($wet['backup_file'] ?? '') === basename($baks[0]) && is_file($baks[0]), 'säkerhetskopian skapas före skrivning');
cin(count(jeab_kontakter_load()) === $beforeCount + 3, 'lagret har de tre nya raderna');

$live = json_encode(jeab_kontakter_load());
cin(is_string($live) && stripos($live, 'sverige') === false && !is_file($contactFile), 'Sverige lagras inte och kontakter.json skrivs inte');
$kim = null;
$otherPostal = 0;
foreach (jeab_kontakter_load() as $row) {
    if (!is_array($row)) {
        continue;
    }
    if (jeab_kontakt_row_email($row) === 'kim.import@example.test') {
        $kim = $row;
    }
    if ((string) ($row['street'] ?? '') === 'Nyckelvägen 9' && (string) ($row['postal_code'] ?? '') === '55555') {
        $otherPostal++;
    }
}
cin(is_array($kim) && ($kim['typ'] ?? '') === 'Privatperson' && ($kim['kind'] ?? '') === 'person', 'typen är Privatperson');
cin(is_array($kim) && ($kim['company'] ?? '') === 'jarfalla', 'nya raden får Järfälla');
$importStore = jeab_store();
$kimId = is_array($kim) ? (string) ($kim['id'] ?? '') : '';
$inJarfalla = jeab_kontakter_view($importStore, '', 'alla', 'jarfalla', 50);
$inMalmo = jeab_kontakter_view($importStore, '', 'alla', 'malmo', 50);
cin($kimId !== '' && isset($inJarfalla['rows'][$kimId]) && !isset($inMalmo['rows'][$kimId]), 'kontakten syns i Järfälla och inte i Malmö');
cin(!jeab_kontakt_matches_office($kim, 'malmo', []) && jeab_kontakt_matches_office($kim, 'jarfalla', []), 'sparat bolag styr vilket kontor som ser raden');
cin(is_array($kim) && ($kim['street'] ?? '') === 'Provgatan 4' && ($kim['city'] ?? '') === 'Testort', 'Sverige tas bort från gata och ort');
cin(is_array($kim) && jeab_kontakt_row_phone($kim) === '+46705550005', 'telefonen normaliseras');
cin($otherPostal === 1, 'samma gata med annat postnummer är en ny kontakt');
$createdIds = array_fill_keys((array) ($wet['created_ids'] ?? []), true);
cin(is_array($kim) && isset($createdIds[(string) ($kim['id'] ?? '')]), 'svaret innehåller de skapade id:na');
$wetBatch = null;
foreach (cin_samples($wet, 'duplicates') as $item) {
    if ((int) ($item['index'] ?? -1) === 4) {
        $wetBatch = $item;
    }
}
cin(is_array($wetBatch) && ($wetBatch['matched_id'] ?? '') === (string) (($wet['created_ids'][0] ?? '')), 'batchdubblett pekar på det skapade id:t');

$again = jeab_kontakter_import_new_dispatch(['action' => 'kontakter.import_new', 'rows' => $rows, 'dry' => 0, 'co' => 'Järfälla']);
cin(($again['ok'] ?? false) === true && ($again['created_ids'] ?? null) === [] && ($again['counts']['would_create'] ?? -1) === 0 && ($again['counts']['created'] ?? -1) === 0, 'andra körningen skapar noll');
cin(count(jeab_kontakter_load()) === $beforeCount + 3 && count(cin_baks($private)) === 1, 'andra körningen rör inte lagret');

$malmoRows = [
    ['namn' => 'Malmo Import', 'mejl' => 'import.malmo@example.test', 'telefon' => '0701210001', 'gata' => 'Limhamnsgatan 2', 'postnr' => '21642', 'ort' => 'Limhamn'],
];
$malmoWet = jeab_kontakter_import_new_dispatch(['action' => 'kontakter.import_new', 'rows' => $malmoRows, 'dry' => 0, 'co' => 'MEAB']);
cin(($malmoWet['ok'] ?? false) === true && count((array) ($malmoWet['created_ids'] ?? [])) === 1, 'co=MEAB skapar en Malmö-rad');
$malmoRow = null;
foreach (jeab_kontakter_load() as $row) {
    if (is_array($row) && jeab_kontakt_row_email($row) === 'import.malmo@example.test') {
        $malmoRow = $row;
    }
}
$malmoId = is_array($malmoRow) ? (string) ($malmoRow['id'] ?? '') : '';
cin(is_array($malmoRow) && ($malmoRow['company'] ?? '') === 'malmo', 'MEAB lagras som malmo');
$malmoList = jeab_kontakter_view($importStore, '', 'alla', 'malmo', 50);
$jarfallaList = jeab_kontakter_view($importStore, '', 'alla', 'jarfalla', 50);
cin($malmoId !== '' && isset($malmoList['rows'][$malmoId]), 'Malmö-raden syns i Malmö');
cin($malmoId !== '' && !isset($jarfallaList['rows'][$malmoId]), 'Malmö-raden syns inte i Järfälla');

echo "Alla kontakter.import_new-tester OK\n";
