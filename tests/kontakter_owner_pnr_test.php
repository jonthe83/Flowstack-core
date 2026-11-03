<?php

declare(strict_types=1);

/**
 * Personnummer på ägare och fastighetsbeteckning på adressen.
 * Numret lagras som 12 siffror, syns inte i hook-svar eller fellogg,
 * autosparas inte, och ångras tillbaka till tomt.
 * Påhittade nummer.
 */

$tmp = sys_get_temp_dir() . '/jeab-owner-pnr-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
putenv('CONTACTS_SOLE=1');
putenv('JEAB_PNR_HMAC_KEY=owner-pnr-fill-test');
putenv('FULLMAKT_DATA=' . $tmp . '/fullmakt');
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
require_once dirname(__DIR__) . '/fullmakt/lib/parties.php';
jeab_ordrar_external_live(false);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['user'] = 'test@example.com';
if (function_exists('jeab_auth_ok') && !jeab_auth_ok()) {
    $_SESSION['auth'] = true;
}

function op(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function op_pnr(string $nine): string
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

function op_digits(string $raw): string
{
    return preg_replace('/\D+/', '', $raw) ?? '';
}

function op_blob_hides(string $blob, string $digits, string $label): void
{
    $dashed = strlen($digits) === 12 ? substr($digits, 0, 8) . '-' . substr($digits, 8) : $digits;
    op(!str_contains($blob, $digits) && !str_contains($blob, $dashed), $label);
}

$store = jeab_store();
op($store instanceof JeabPdoStore, 'sqlite-lagret finns');
jeab_ensure_contacts_tables($store->pdo(), 'sqlite');
op(jeab_kontakter_sql_is_sole_source(), 'kontakter läses från SQL');

$pnrA = op_pnr('800101123');
$pnrB = op_pnr('850505123');
$pnrC = op_pnr('900101123');
$digitsA = op_digits($pnrA);
$digitsB = op_digits($pnrB);
$digitsC = op_digits($pnrC);
op(strlen($digitsA) === 12 && strlen($digitsB) === 12 && $digitsA !== $digitsB, 'två giltiga nummer');

$saved = jeab_kontakt_save_input([
    'street' => 'Spargatan 1',
    'postal_code' => '11122',
    'city' => 'Teststad',
    'owner_name_1' => 'Spar Test',
    'owner_pnr_1' => substr($digitsA, 0, 8) . '-' . substr($digitsA, 8),
    'contact_person' => '1',
    'email' => 'spar@example.test',
    'phone' => '0701000101',
    'company' => 'jarfalla',
    'fastighetsbeteckning' => 'SPAR 1:1',
]);
$storedPnr = (string) ($saved['owners'][0]['personnummer'] ?? '');
op($storedPnr === $digitsA, 'Spara lagrar 12 siffror utan bindestreck');
op((string) ($saved['fastighetsbeteckning'] ?? '') === 'SPAR 1:1', 'fastighetsbeteckning sparas på adressen');

$autosaved = jeab_kontakt_save_input([
    'id' => (string) $saved['id'],
    'street' => 'Spargatan 2',
    'postal_code' => '11122',
    'city' => 'Teststad',
    'owner_name_1' => 'Spar Test',
    'owner_pnr_1' => $digitsB,
    'contact_person' => '1',
    'kt_autosave' => '1',
    'fastighetsbeteckning' => 'SPAR 2:2',
]);
op((string) ($autosaved['owners'][0]['personnummer'] ?? '') === $digitsA, 'autospar skriver inte personnummer');
op((string) ($autosaved['street'] ?? '') === 'Spargatan 2', 'autospar skriver gatan');
op((string) ($autosaved['fastighetsbeteckning'] ?? '') === 'SPAR 2:2', 'autospar skriver fastighetsbeteckning');

$explicit = jeab_kontakt_save_input([
    'id' => (string) $saved['id'],
    'street' => 'Spargatan 2',
    'postal_code' => '11122',
    'city' => 'Teststad',
    'owner_name_1' => 'Spar Test',
    'owner_pnr_1' => $digitsB,
    'owner_name_2' => 'Spar Två',
    'owner_pnr_2' => $digitsC,
    'contact_person' => '1',
    'fastighetsbeteckning' => 'SPAR 2:2',
]);
op((string) ($explicit['owners'][0]['personnummer'] ?? '') === $digitsB, 'Spara skriver ägare 1');
op((string) ($explicit['owners'][1]['personnummer'] ?? '') === $digitsC, 'Spara skriver ägare 2');
try {
    jeab_kontakt_save_input([
        'id' => (string) $saved['id'],
        'street' => 'Spargatan 2',
        'city' => 'Teststad',
        'owner_name_1' => 'Spar Test',
        'owner_pnr_1' => '19800101-0000',
        'contact_person' => '1',
    ]);
    op(false, 'ogiltigt personnummer ska stoppas');
} catch (InvalidArgumentException $e) {
    op($e->getMessage() === 'Personnumret är ogiltigt.', 'feltexten säger att personnumret är ogiltigt');
    op(!str_contains($e->getMessage(), '19800101') && !str_contains($e->getMessage(), '0000'), 'feltexten innehåller inte numret');
}

$page = (string) file_get_contents(dirname(__DIR__) . '/src/pages/kontakter.php');
op(str_contains($page, 'name="owner_pnr_1"') && str_contains($page, 'name="owner_pnr_2"') && str_contains($page, 'name="fastighetsbeteckning"'), 'kontakter har fält för personnummer och fastighetsbeteckning');
op(str_contains($page, "body.delete('owner_pnr_1')") && str_contains($page, "body.append('kt_autosave', '1')"), 'autospar tar bort personnummerfältet');
op(str_contains($page, '>Spara<'), 'Spara-knappen finns');
$_GET = ['p' => 'kontakter', 'id' => (string) $saved['id']];
$error = null;
$flash = null;
$html = require dirname(__DIR__) . '/src/pages/kontakter.php';
op(is_string($html) && !str_contains($html, $digitsB) && !str_contains($html, $digitsC), 'sidan visar inte hela personnumret');

function op_contact(string $name, string $pnr = '', string $fast = ''): string
{
    $row = jeab_kontakt_save_input([
        'street' => $name . 'gatan 1',
        'postal_code' => '11122',
        'city' => 'Teststad',
        'owner_name_1' => $name,
        'owner_pnr_1' => $pnr,
        'contact_person' => '1',
        'fastighetsbeteckning' => $fast,
        'company' => 'jarfalla',
    ]);

    return (string) $row['id'];
}

$klar = op_contact('Klar Test');
$kvar = op_contact('Kvar Test', $digitsA, 'KVAR 1:1');
$tveksam = op_contact('Tveksam Test');
$tveksam2 = op_contact('Tveksam Två');
$full = op_contact('Full Test');
op($klar !== '' && $kvar !== '' && $full !== '', 'kontakterna finns');

$doc = null;
if (!function_exists('fullmakt_create_doc')) {
    require_once dirname(__DIR__) . '/fullmakt/lib/bootstrap.php';
    require_once dirname(__DIR__) . '/fullmakt/lib/store.php';
}
if (function_exists('fullmakt_create_doc')) {
    $doc = fullmakt_create_doc([
        'namn' => 'Full Test',
        'personnummer' => $digitsC,
        'telefon' => '0701000199',
        'epost' => 'full@example.test',
        'adress' => 'Fullgatan 1',
        'postnummer' => '111 22',
        'ort' => 'Teststad',
        'typ' => 'privatperson',
    ]);
}
op(is_array($doc) && trim((string) ($doc['token'] ?? '')) !== '', 'fullmakten kan läsas som källa');
$token = (string) $doc['token'];

$GLOBALS['_JEAB_PURCHASE_LIST'] = [
    '7101' => [
        'reminder_id' => 7101,
        'contact_id' => $klar,
        'name' => 'Klar Test',
        'huvudsokande' => 'Klar Test',
        'personnummer' => $digitsB,
        'fastighetsbeteckning' => 'ORDER 9:9',
        'owners' => [
            ['name' => 'Klar Test', 'personnummer' => $digitsB, 'address' => 'Klargatan 1'],
        ],
        'kind' => 'batteri',
        'company' => 'jarfalla',
    ],
    '7105' => [
        'reminder_id' => 7105,
        'contact_id' => $kvar,
        'name' => 'Kvar Test',
        'huvudsokande' => 'Kvar Test',
        'personnummer' => $digitsB,
        'fastighetsbeteckning' => 'ORDER 8:8',
        'owners' => [
            ['name' => 'Kvar Test', 'personnummer' => $digitsB, 'address' => 'Kvarngatan 1'],
        ],
        'kind' => 'batteri',
        'company' => 'jarfalla',
    ],
    '7103' => [
        'reminder_id' => 7103,
        'contact_id' => $tveksam,
        'name' => 'Tveksam Test',
        'huvudsokande' => 'Tveksam Test',
        'personnummer' => $digitsB,
        'fastighetsbeteckning' => 'TVEK 1:1',
        'owners' => [
            ['name' => 'Någon Annan', 'personnummer' => $digitsB, 'address' => 'Annangatan 1'],
        ],
        'kind' => 'batteri',
        'company' => 'jarfalla',
    ],
    '7104' => [
        'reminder_id' => 7104,
        'contact_id' => $tveksam2,
        'name' => 'Tveksam Två',
        'huvudsokande' => 'Tveksam Två',
        'personnummer' => $digitsC,
        'fastighetsbeteckning' => 'TVEK 2:2',
        'owners' => [
            ['name' => 'Också Annan', 'personnummer' => $digitsC, 'address' => 'Annangatan 2'],
        ],
        'kind' => 'batteri',
        'company' => 'jarfalla',
    ],
    '7102' => [
        'reminder_id' => 7102,
        'contact_id' => $full,
        'name' => 'Full Test',
        'huvudsokande' => 'Full Test',
        'personnummer' => '',
        'fastighetsbeteckning' => 'FULL 2:2',
        'fullmakt_token' => $token,
        'owners' => [
            ['name' => 'Full Test', 'personnummer' => '', 'address' => 'Fullgatan 1'],
        ],
        'kind' => 'batteri',
        'company' => 'jarfalla',
    ],
];

$klarOrder = $GLOBALS['_JEAB_PURCHASE_LIST']['7101'];
$beforeFill = jeab_bokmalen_ordrar_webhook_payload('ordrar.foranmalan', $klarOrder, false);
op(is_array($beforeFill) && ($beforeFill['personnummer'] ?? '') === jeab_contacts_sole_pnr_text($digitsB), 'föranmälan faller tillbaka på orderns personnummer');
op(($beforeFill['fastighetsbeteckning'] ?? '') === 'ORDER 9:9', 'föranmälan faller tillbaka på orderns fastighet');
$fieldsBefore = jeab_ellevio_fullmakt_fields($klarOrder);
op(($fieldsBefore['personnummer'] ?? '') === jeab_contacts_sole_pnr_text($digitsB), 'fullmaktsfälten faller tillbaka på ordern');
op(($fieldsBefore['fastighetsbeteckning'] ?? '') === 'ORDER 9:9', 'fullmaktsfälten faller tillbaka på orderns fastighet');

$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$offPayload = jeab_bokmalen_ordrar_webhook_payload('ordrar.foranmalan', $klarOrder, false);
op(($offPayload['personnummer'] ?? '') === jeab_contacts_sole_pnr_text($digitsB), 'flaggan av läser orderns personnummer');
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);

jeab_kontakter_mutate(static function (array &$contacts) use ($klar, $digitsA): void {
    if (isset($contacts[$klar]) && is_array($contacts[$klar])) {
        $contacts[$klar]['owners'][0]['personnummer'] = $digitsA;
        $contacts[$klar]['fastighetsbeteckning'] = 'KONTAKT 3:3';
    }
});
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$prefer = jeab_bokmalen_ordrar_webhook_payload('ordrar.foranmalan', $klarOrder, false);
op(($prefer['personnummer'] ?? '') === jeab_contacts_sole_pnr_text($digitsA), 'föranmälan läser kontaktens personnummer');
op(($prefer['fastighetsbeteckning'] ?? '') === 'KONTAKT 3:3', 'föranmälan läser kontaktens fastighet');
op(!str_contains((string) ($prefer['personnummer'] ?? ''), $digitsB), 'orderns nummer används inte när kontakten har ett');
$signed = jeab_bokmalen_ellevio_fullmakt_pnr(['personnummer' => $digitsB, 'token' => 'x'], $klarOrder);
op($signed === jeab_contacts_sole_pnr_text($digitsA), 'Ellevio-webhooken läser kontaktens personnummer');
$signedFast = jeab_bokmalen_ellevio_fullmakt_fastighet(['fastighetsbeteckning' => 'DOK 1:1'], $klarOrder);
op($signedFast === 'KONTAKT 3:3', 'Ellevio-webhooken läser kontaktens fastighet');
jeab_kontakter_mutate(static function (array &$contacts) use ($klar): void {
    if (isset($contacts[$klar]) && is_array($contacts[$klar])) {
        $contacts[$klar]['owners'][0]['personnummer'] = '';
        $contacts[$klar]['fastighetsbeteckning'] = '';
    }
});
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);

$dry = jeab_contacts_owner_pnr_fill([], $store);
$dryJson = json_encode($dry);
op(is_string($dryJson), 'torrkörningen kan kodas');
op(!empty($dry['ok']) && ($dry['dry'] ?? false) === true && ($dry['written'] ?? true) === false, 'hooken är torr som standard');
op(($dry['pnr_name_ambiguous'] ?? null) === [7103, 7104], 'tvetydiga ordrar listas med id, fick ' . json_encode($dry['pnr_name_ambiguous'] ?? null));
$dryIds = is_array($dry['ids'] ?? null) ? $dry['ids'] : [];
sort($dryIds);
$expect = [$full, $klar];
sort($expect);
op($dryIds === $expect, 'bara tomma kontakter skulle fyllas, fick ' . implode(',', $dryIds));
op((int) ($dry['counts']['owners'] ?? 0) === 2 && (int) ($dry['counts']['fastighet'] ?? 0) === 2, 'torrkörningen räknar ägare och fastighet');
op(!array_key_exists('pnr', is_array($dry['counts'] ?? null) ? $dry['counts'] : []), 'svaret har ingen pnr-nyckel');
op_blob_hides($dryJson, $digitsA, 'torrkörningen innehåller inte nummer A');
op_blob_hides($dryJson, $digitsB, 'torrkörningen innehåller inte nummer B');
op_blob_hides($dryJson, $digitsC, 'torrkörningen innehåller inte nummer C');
$klarNow = jeab_kontakt_get($klar);
op(trim((string) ($klarNow['owners'][0]['personnummer'] ?? '')) === '', 'torrkörningen skriver inte');

$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
op(str_contains($hookSrc, "'kontakter.owner_pnr_fill'"), 'hook.php tar kontakter.owner_pnr_fill');

$wet = jeab_contacts_owner_pnr_fill(['dry' => 0], $store);
$wetJson = json_encode($wet);
op(is_string($wetJson) && !empty($wet['ok']) && ($wet['dry'] ?? true) === false && !empty($wet['written']), 'dry=0 skriver');
op(is_array($wet['ids'] ?? null) && in_array($klar, $wet['ids'], true) && in_array($full, $wet['ids'], true) && !in_array($kvar, $wet['ids'], true) && !in_array($tveksam, $wet['ids'], true), 'skrivna id är de tomma');
op_blob_hides($wetJson, $digitsA, 'svaret innehåller inte nummer A');
op_blob_hides($wetJson, $digitsB, 'svaret innehåller inte nummer B');
op_blob_hides($wetJson, $digitsC, 'svaret innehåller inte nummer C');
$klarFilled = jeab_kontakt_get($klar);
$fullFilled = jeab_kontakt_get($full);
$kvarFilled = jeab_kontakt_get($kvar);
$tveksamFilled = jeab_kontakt_get($tveksam);
op((string) ($klarFilled['owners'][0]['personnummer'] ?? '') === $digitsB, 'tomt personnummer fylls från ordern');
op((string) ($klarFilled['fastighetsbeteckning'] ?? '') === 'ORDER 9:9', 'tom fastighet fylls från ordern');
op((string) ($fullFilled['owners'][0]['personnummer'] ?? '') === $digitsC, 'tomt personnummer fylls från fullmakten');
op((string) ($fullFilled['fastighetsbeteckning'] ?? '') === 'FULL 2:2', 'tom fastighet fylls även när numret kommer från fullmakten');
op((string) ($kvarFilled['owners'][0]['personnummer'] ?? '') === $digitsA && (string) ($kvarFilled['fastighetsbeteckning'] ?? '') === 'KVAR 1:1', 'ifyllt värde skrivs inte över');
op(trim((string) ($tveksamFilled['owners'][0]['personnummer'] ?? '')) === '' && trim((string) ($tveksamFilled['fastighetsbeteckning'] ?? '')) === '', 'tvetydig order fyller inte');

$batch = (string) ($wet['batch_id'] ?? '');
op($batch !== '', 'körningen har en egen ångerbatch');
$undoRows = $store->pdo()->prepare('SELECT before_json, after_json FROM contacts_sole_undo WHERE batch_id = ?');
$undoRows->execute([$batch]);
$undoBlob = '';
foreach ($undoRows->fetchAll() as $undoRow) {
    if (is_array($undoRow)) {
        $undoBlob .= (string) ($undoRow['before_json'] ?? '') . (string) ($undoRow['after_json'] ?? '');
    }
}
op($undoBlob !== '', 'ångerloggen har rader');
op_blob_hides($undoBlob, $digitsA, 'ångerloggen innehåller inte nummer A');
op_blob_hides($undoBlob, $digitsB, 'ångerloggen innehåller inte nummer B');
op_blob_hides($undoBlob, $digitsC, 'ångerloggen innehåller inte nummer C');
op(str_contains($undoBlob, 'pnr_hmac') || !str_contains($undoBlob, 'personnummer'), 'ångerloggen har HMAC i stället för numret');

$fromContact = jeab_bokmalen_ordrar_webhook_payload('ordrar.foranmalan', $klarOrder, false);
op(($fromContact['personnummer'] ?? '') === jeab_contacts_sole_pnr_text($digitsB) && ($fromContact['fastighetsbeteckning'] ?? '') === 'ORDER 9:9', 'föranmälan läser det som ligger på kontakten efter fyllning');
$elf = jeab_ellevio_fullmakt_fields($klarOrder);
op(($elf['personnummer'] ?? '') === jeab_contacts_sole_pnr_text($digitsB) && ($elf['fastighetsbeteckning'] ?? '') === 'ORDER 9:9', 'Ellevio-fälten läser kontakten');

$undone = jeab_contacts_sole_undo(['dry' => 0, 'batch_id' => $batch], $store);
$undoJson = json_encode($undone);
op(is_string($undoJson) && !empty($undone['ok']) && ($undone['written'] ?? false) === true, 'ångern skriver tillbaka');
op_blob_hides($undoJson, $digitsA, 'ångersvaret innehåller inte nummer A');
op_blob_hides($undoJson, $digitsB, 'ångersvaret innehåller inte nummer B');
op_blob_hides($undoJson, $digitsC, 'ångersvaret innehåller inte nummer C');
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$klarRestored = jeab_kontakt_get($klar);
$fullRestored = jeab_kontakt_get($full);
op(trim((string) ($klarRestored['owners'][0]['personnummer'] ?? '')) === '', 'ångern tar bort det fyllda personnumret');
op(trim((string) ($klarRestored['fastighetsbeteckning'] ?? '')) === '', 'ångern tar bort den fyllda fastigheten');
op(trim((string) ($fullRestored['owners'][0]['personnummer'] ?? '')) === '', 'ångern tar bort fullmaktens personnummer');
op((string) ($kvarFilled['owners'][0]['personnummer'] ?? '') === $digitsA, 'kvar-kontakten rörs inte av ångern');
$kvarAfter = jeab_kontakt_get($kvar);
op((string) ($kvarAfter['owners'][0]['personnummer'] ?? '') === $digitsA && (string) ($kvarAfter['fastighetsbeteckning'] ?? '') === 'KVAR 1:1', 'ifyllt värde ligger kvar efter ångern');

$log = '';
foreach ([$tmp . '/app-errors.jsonl', $tmp . '/php-error.log'] as $path) {
    if (is_file($path)) {
        $log .= (string) file_get_contents($path);
    }
}
op_blob_hides($log, $digitsA, 'felloggen innehåller inte nummer A');
op_blob_hides($log, $digitsB, 'felloggen innehåller inte nummer B');
op_blob_hides($log, $digitsC, 'felloggen innehåller inte nummer C');

op(jeab_contacts_sole_name_given_last_same('Olof Bladh', 'Jan Olof Martin Bladh'), 'Olof är tilltalsnamn');
op(!jeab_contacts_sole_name_given_last_same('Bo Bladh', 'Jan Olof Martin Bladh'), 'Bo är inte tilltalsnamn');
op(!jeab_contacts_sole_name_first_last_same('Olof Bladh', 'Jan Olof Martin Bladh'), 'första token skiljer sig');
$pnrD = op_pnr('750303123');
$pnrE = op_pnr('920202123');
$pnrF = op_pnr('880808123');
$digitsD = op_digits($pnrD);
$digitsE = op_digits($pnrE);
$digitsF = op_digits($pnrF);
op(jeab_kontakt_pnr_valid($pnrD) && jeab_kontakt_pnr_valid($pnrE) && jeab_kontakt_pnr_valid($pnrF), 'tre nya nummer är giltiga');
$samePerson = jeab_contacts_sole_order_person_for_owner([
    'owners' => [['name' => 'Jan Olof Martin Bladh', 'personnummer' => $digitsD]],
], 'Olof Bladh', $digitsD);
op(is_array($samePerson), 'samma nummer ger träff på tilltalsnamnet');
$otherPerson = jeab_contacts_sole_order_person_for_owner([
    'owners' => [['name' => 'Jan Olof Martin Bladh', 'personnummer' => $digitsD]],
], 'Olof Bladh', $digitsA);
op($otherPerson === null, 'annat nummer ger ingen träff');
$olofMake = static function (string $email, string $street): string {
    $row = jeab_kontakt_save_input([
        'street' => $street,
        'postal_code' => '11122',
        'city' => 'Teststad',
        'owner_name_1' => 'Olof Bladh',
        'contact_person' => '1',
        'email' => $email,
        'company' => 'jarfalla',
    ]);

    return (string) ($row['id'] ?? '');
};
$olof = $olofMake('olof.empty@example.test', 'Olofgatan 1');
$olofHeld = $olofMake('olof.held@example.test', 'Olofgatan 2');
$olofFull = $olofMake('olof.full@example.test', 'Olofgatan 3');
$olofCrowd = $olofMake('olof.crowd@example.test', 'Olofgatan 4');
op(count(array_unique([$olof, $olofHeld, $olofFull, $olofCrowd])) === 4, 'fyra Olof-kontakter');
jeab_kontakter_mutate(static function (array &$contacts) use ($olofHeld, $olofFull, $digitsA, $digitsD): void {
    $contacts[$olofHeld]['owners'][0]['personnummer'] = $digitsA;
    $contacts[$olofFull]['owners'][0]['personnummer'] = $digitsD;
});
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$GLOBALS['_JEAB_PURCHASE_LIST']['74'] = [
    'reminder_id' => 74,
    'contact_id' => $olof,
    'name' => 'Jan Olof Martin Bladh',
    'customer_name' => 'Jan Olof Martin Bladh',
    'owners' => [
        ['name' => 'Jan Olof Martin Bladh', 'personnummer' => $digitsD, 'address' => 'Bladhgatan 1'],
        ['name' => 'Emma Sofie Bladh', 'personnummer' => $digitsE, 'address' => 'Bladhgatan 1'],
    ],
    'kind' => 'batteri',
    'company' => 'jarfalla',
];
$GLOBALS['_JEAB_PURCHASE_LIST']['75'] = [
    'reminder_id' => 75,
    'contact_id' => $olofHeld,
    'owners' => [
        ['name' => 'Jan Olof Martin Bladh', 'personnummer' => $digitsD, 'address' => 'Bladhgatan 1'],
        ['name' => 'Emma Sofie Bladh', 'personnummer' => $digitsE, 'address' => 'Bladhgatan 1'],
    ],
    'kind' => 'batteri',
    'company' => 'jarfalla',
];
$GLOBALS['_JEAB_PURCHASE_LIST']['76'] = [
    'reminder_id' => 76,
    'contact_id' => $olofFull,
    'owners' => [
        ['name' => 'Jan Olof Martin Bladh', 'personnummer' => $digitsD, 'address' => 'Bladhgatan 1'],
        ['name' => 'Emma Sofie Bladh', 'personnummer' => $digitsE, 'address' => 'Bladhgatan 1'],
    ],
    'kind' => 'batteri',
    'company' => 'jarfalla',
];
$GLOBALS['_JEAB_PURCHASE_LIST']['77'] = [
    'reminder_id' => 77,
    'contact_id' => $olofCrowd,
    'owners' => [
        ['name' => 'Jan Olof Martin Bladh', 'personnummer' => $digitsD, 'address' => 'Bladhgatan 1'],
        ['name' => 'Emma Sofie Bladh', 'personnummer' => $digitsE, 'address' => 'Bladhgatan 1'],
        ['name' => 'Karin Bladh', 'personnummer' => $digitsF, 'address' => 'Bladhgatan 1'],
    ],
    'kind' => 'batteri',
    'company' => 'jarfalla',
];
$dryGiven = jeab_contacts_owner_pnr_fill([], $store);
$dryGivenJson = json_encode($dryGiven);
op(is_string($dryGivenJson) && !empty($dryGiven['ok']) && ($dryGiven['dry'] ?? false) === true, 'tilltalsnamnet är torrt');
$ambiguousGiven = is_array($dryGiven['pnr_name_ambiguous'] ?? null) ? $dryGiven['pnr_name_ambiguous'] : [];
sort($ambiguousGiven);
op($ambiguousGiven === [75], 'annat nummer är tvetydigt, fick ' . json_encode($ambiguousGiven));
op(in_array($olof, (array) ($dryGiven['ids'] ?? []), true) && in_array($olofFull, (array) ($dryGiven['ids'] ?? []), true) && in_array($olofCrowd, (array) ($dryGiven['ids'] ?? []), true), 'tom, fylld och trång plats skulle skrivas');
op(!in_array($olofHeld, (array) ($dryGiven['ids'] ?? []), true), 'annat nummer hoppas över');
op(count((array) (jeab_kontakt_get($olof)['owners'] ?? [])) === 1, 'torrkörningen lägger inte till ägare');
op_blob_hides($dryGivenJson, $digitsD, 'torrkörningen innehåller inte nummer D');
op_blob_hides($dryGivenJson, $digitsE, 'torrkörningen innehåller inte nummer E');
op_blob_hides($dryGivenJson, $digitsF, 'torrkörningen innehåller inte nummer F');
$wetGiven = jeab_contacts_owner_pnr_fill(['dry' => 0], $store);
$wetGivenJson = json_encode($wetGiven);
op(is_string($wetGivenJson) && !empty($wetGiven['written']), 'tilltalsnamnet skrivs');
op_blob_hides($wetGivenJson, $digitsD, 'svaret innehåller inte nummer D');
op_blob_hides($wetGivenJson, $digitsE, 'svaret innehåller inte nummer E');
op_blob_hides($wetGivenJson, $digitsF, 'svaret innehåller inte nummer F');
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$olofNow = jeab_kontakt_get($olof);
$olofOwners = is_array($olofNow['owners'] ?? null) ? $olofNow['owners'] : [];
op(count($olofOwners) === 2, 'andra ägaren läggs till');
op((string) ($olofOwners[0]['name'] ?? '') === 'Olof Bladh' && (string) ($olofOwners[0]['personnummer'] ?? '') === $digitsD, 'Olof får orderpersonens nummer');
op((string) ($olofOwners[1]['name'] ?? '') === 'Emma Sofie Bladh' && (string) ($olofOwners[1]['personnummer'] ?? '') === $digitsE, 'Emma läggs till med sitt nummer');
$heldNow = jeab_kontakt_get($olofHeld);
op(count((array) ($heldNow['owners'] ?? [])) === 1 && (string) ($heldNow['owners'][0]['personnummer'] ?? '') === $digitsA, 'annat nummer skrivs inte över');
$fullNow = jeab_kontakt_get($olofFull);
$fullOwners = is_array($fullNow['owners'] ?? null) ? $fullNow['owners'] : [];
op(count($fullOwners) === 2 && (string) ($fullOwners[0]['personnummer'] ?? '') === $digitsD && (string) ($fullOwners[1]['name'] ?? '') === 'Emma Sofie Bladh', 'fylld plats får bara den saknade ägaren');
$crowdNow = jeab_kontakt_get($olofCrowd);
op(count((array) ($crowdNow['owners'] ?? [])) === 1 && (string) ($crowdNow['owners'][0]['personnummer'] ?? '') === $digitsD, 'två saknade ägare fyller numret och lämnar platsen');
$givenBatch = (string) ($wetGiven['batch_id'] ?? '');
$givenUndo = $store->pdo()->prepare('SELECT before_json, after_json FROM contacts_sole_undo WHERE batch_id = ?');
$givenUndo->execute([$givenBatch]);
$givenBlob = '';
foreach ($givenUndo->fetchAll() as $givenRow) {
    if (is_array($givenRow)) {
        $givenBlob .= (string) ($givenRow['before_json'] ?? '') . (string) ($givenRow['after_json'] ?? '');
    }
}
op_blob_hides($givenBlob, $digitsD, 'ångerloggen innehåller inte nummer D');
op_blob_hides($givenBlob, $digitsE, 'ångerloggen innehåller inte nummer E');
op_blob_hides($givenBlob, $digitsF, 'ångerloggen innehåller inte nummer F');
$givenUndone = jeab_contacts_sole_undo(['dry' => 0, 'batch_id' => $givenBatch], $store);
$givenUndoJson = json_encode($givenUndone);
op(is_string($givenUndoJson) && !empty($givenUndone['ok']), 'tilltalsnamnet ångras');
op_blob_hides($givenUndoJson, $digitsD, 'ångersvaret innehåller inte nummer D');
op_blob_hides($givenUndoJson, $digitsE, 'ångersvaret innehåller inte nummer E');
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$olofBack = jeab_kontakt_get($olof);
$olofBackOwners = is_array($olofBack['owners'] ?? null) ? $olofBack['owners'] : [];
op(count($olofBackOwners) === 1 && trim((string) ($olofBackOwners[0]['personnummer'] ?? '')) === '', 'ångern tar bort Emma och Olofs nummer');
$fullBack = jeab_kontakt_get($olofFull);
op(count((array) ($fullBack['owners'] ?? [])) === 1 && (string) ($fullBack['owners'][0]['personnummer'] ?? '') === $digitsD, 'ångern lämnar det tidigare numret');
$logGiven = '';
foreach ([$tmp . '/app-errors.jsonl', $tmp . '/php-error.log'] as $path) {
    if (is_file($path)) {
        $logGiven .= (string) file_get_contents($path);
    }
}
op_blob_hides($logGiven, $digitsD, 'felloggen innehåller inte nummer D');
op_blob_hides($logGiven, $digitsE, 'felloggen innehåller inte nummer E');
op_blob_hides($logGiven, $digitsF, 'felloggen innehåller inte nummer F');

echo "kontakter owner pnr: ok\n";
