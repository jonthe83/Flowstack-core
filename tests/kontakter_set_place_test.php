<?php

declare(strict_types=1);

/**
 * kontakter.set_place fyller tom gata, postnummer och ort via samma SQL-väg
 * som Kontakter-formuläret. Torrkörning är standard. dry=0 kräver confirm=SAM.
 * Ett ifyllt fält rörs bara med force=1. Ångring tar tillbaka batchen.
 * Inget mejl, inget SMS och ingen hopslagning.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-set-place-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
$_ENV['DATA_BACKEND'] = 'mysql';
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

function sp(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function sp_log(): string
{
    $path = jeab_app_errors_path();

    return is_file($path) ? (string) file_get_contents($path) : '';
}

function sp_place(string $id): array
{
    $row = jeab_kontakt_fetch($id);
    if (!is_array($row)) {
        return ['gata' => '', 'postnummer' => '', 'ort' => '', 'street_key' => '', 'email' => ''];
    }

    return [
        'gata' => trim((string) ($row['street'] ?? '')),
        'postnummer' => trim((string) ($row['postal_code'] ?? '')),
        'ort' => trim((string) ($row['city'] ?? '')),
        'street_key' => trim((string) ($row['street_key'] ?? '')),
        'email' => function_exists('jeab_kontakt_row_email') ? jeab_kontakt_row_email($row) : '',
    ];
}

$store = jeab_store();
sp($store instanceof JeabPdoStore, 'sqlite-lagret finns');
jeab_ensure_contacts_tables($store->pdo(), 'sqlite');
sp(jeab_kontakter_use_sql(), 'kontakterna ligger i SQL');

$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-set-place']));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = jeab_kontakter_set_place_dispatch([
    'action' => 'kontakter.set_place',
    'id' => 'saknas',
    'postnummer' => '11122',
    'ort' => 'Solna',
]);
sp(($denied['status'] ?? 0) === 401 && ($denied['ok'] ?? true) === false && ($denied['sent'] ?? 1) === 0, 'utan token ger 401');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-set-place';

$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
$src = (string) file_get_contents(dirname(__DIR__) . '/src/kontakter_set_place.php');
sp(str_contains($hookSrc, "'kontakter.set_place'") && str_contains($hookSrc, 'jeab_kontakter_set_place_dispatch'), 'hook.php registrerar kontakter.set_place');
sp(!preg_match('/jeab_[a-z_]*(mail|sms|merge)/i', $src), 'kroken anropar varken mejl, SMS eller hopslagning');
sp(!str_contains($src, 'jeab_kontakt_save_input'), 'kroken går inte via formulärets hopslagning');

$now = jeab_now();
jeab_kontakter_mutate(static function (array &$contacts) use ($now): void {
    $contacts['c-empty'] = [
        'id' => 'c-empty',
        'street' => '',
        'street_key' => '',
        'postal_code' => '',
        'city' => '',
        'owners' => [[
            'name' => 'Ada Tom',
            'contact' => true,
            'email' => 'ada.setplace@example.com',
            'phone' => '0701000701',
        ]],
        'created_at' => $now,
        'updated_at' => $now,
    ];
    $contacts['c-full'] = [
        'id' => 'c-full',
        'street' => 'Kvarngatan 2',
        'street_key' => jeab_kontakt_street_key('Kvarngatan 2'),
        'postal_code' => '16970',
        'city' => 'Solna',
        'owners' => [[
            'name' => 'Bo Full',
            'contact' => true,
            'email' => 'ada.setplace@example.com',
            'phone' => '0701000702',
        ]],
        'created_at' => $now,
        'updated_at' => $now,
    ];
});
$idsBefore = array_keys(jeab_kontakter_load());
sort($idsBefore);

$missing = jeab_kontakter_set_place_dispatch([
    'action' => 'kontakter.set_place',
    'id' => 'ingen',
    'postnummer' => '11122',
    'ort' => 'Solna',
]);
sp(($missing['ok'] ?? true) === false && ($missing['status'] ?? 0) === 404, 'saknad kontakt ger 404');

$badPostal = jeab_kontakter_set_place_dispatch([
    'action' => 'kontakter.set_place',
    'id' => 'c-empty',
    'postnummer' => '123456',
    'ort' => 'Solna',
]);
sp(($badPostal['ok'] ?? true) === false && ($badPostal['error'] ?? '') === 'Postnumret ska vara fem siffror.', 'trasigt postnummer avvisas som i formuläret');

$logBefore = sp_log();
$dry = jeab_kontakter_set_place_dispatch([
    'action' => 'kontakter.set_place',
    'id' => 'c-empty',
    'postnummer' => '111 22',
    'ort' => 'solna, Sverige',
    'gata' => 'Storgatan 1',
]);
sp(($dry['ok'] ?? false) === true && (int) ($dry['dry'] ?? 0) === 1 && (int) ($dry['written'] ?? 1) === 0, 'utan dry är körningen torr');
sp(($dry['before'] ?? null) === ['gata' => '', 'postnummer' => '', 'ort' => ''], 'torrkörningen visar värdena före');
sp(($dry['after'] ?? null) === ['gata' => 'Storgatan 1', 'postnummer' => '11122', 'ort' => 'Solna'], 'torrkörningen visar normaliserade värden efter');
sp((int) ($dry['mail'] ?? 1) === 0 && (int) ($dry['sms'] ?? 1) === 0 && (int) ($dry['sent'] ?? 1) === 0 && (int) ($dry['merged'] ?? 1) === 0, 'torrkörningen skickar inget och slår inte ihop');
sp(sp_place('c-empty') === ['gata' => '', 'postnummer' => '', 'ort' => '', 'street_key' => '', 'email' => 'ada.setplace@example.com'], 'torrkörningen skriver inte');
sp(sp_log() === $logBefore, 'torrkörningen loggar ingen ändring');

$unconfirmed = jeab_kontakter_set_place_dispatch([
    'action' => 'kontakter.set_place',
    'id' => 'c-empty',
    'postnummer' => '111 22',
    'ort' => 'Solna',
    'gata' => 'Storgatan 1',
    'dry' => 0,
]);
sp(($unconfirmed['ok'] ?? true) === false && ($unconfirmed['error'] ?? '') === 'dry=0 kräver confirm=SAM', 'dry=0 utan confirm stoppas');
sp(($unconfirmed['before']['postnummer'] ?? '') === '' && ($unconfirmed['after']['postnummer'] ?? '') === '11122', 'nekad skrivning visar före och efter');
sp(sp_place('c-empty')['postnummer'] === '', 'nekad skrivning lämnar kontakten');

$wrote = jeab_kontakter_set_place_dispatch([
    'action' => 'kontakter.set_place',
    'id' => 'c-empty',
    'postnummer' => '111 22',
    'ort' => 'solna, Sverige',
    'gata' => 'Storgatan 1',
    'dry' => '0',
    'confirm' => 'SAM',
]);
sp(($wrote['ok'] ?? false) === true && (int) ($wrote['written'] ?? 0) === 1 && (int) ($wrote['dry'] ?? 1) === 0, 'dry=0 med confirm skriver');
sp(($wrote['before'] ?? null) === ['gata' => '', 'postnummer' => '', 'ort' => ''], 'svaret visar värdena före');
sp(($wrote['after'] ?? null) === ['gata' => 'Storgatan 1', 'postnummer' => '11122', 'ort' => 'Solna'], 'svaret visar värdena efter');
$batch = trim((string) ($wrote['batch'] ?? ''));
sp($batch !== '', 'skrivningen sparar en ångerbatch');
sp((int) ($wrote['mail'] ?? 1) === 0 && (int) ($wrote['sms'] ?? 1) === 0 && (int) ($wrote['sent'] ?? 1) === 0 && (int) ($wrote['merged'] ?? 1) === 0, 'skrivningen skickar inget och slår inte ihop');
$stored = sp_place('c-empty');
sp($stored['gata'] === 'Storgatan 1' && $stored['postnummer'] === '11122' && $stored['ort'] === 'Solna', 'SQL-raden har formulärets normalisering');
sp($stored['street_key'] === jeab_kontakt_street_key('Storgatan 1'), 'gatanyckeln följer formuläret');
$sql = $store->pdo()->prepare('SELECT postal_code, street_key FROM contacts WHERE id = ?');
$sql->execute(['c-empty']);
$sqlRow = $sql->fetch(PDO::FETCH_ASSOC);
sp(is_array($sqlRow) && (string) $sqlRow['postal_code'] === '11122' && (string) $sqlRow['street_key'] === jeab_kontakt_street_key('Storgatan 1'), 'postnummer och gatanyckel ligger i SQL-kolumnerna');
sp(str_contains(sp_log(), 'set_place c-empty batch ' . $batch . ' skrev postnummer,ort,gata'), 'ändringen loggas');
sp(!is_file($tmp . '/kontakter.json'), 'JSON-filen för kontakter skapas inte');

$idsAfter = array_keys(jeab_kontakter_load());
sort($idsAfter);
sp($idsAfter === $idsBefore && sp_place('c-full') === [
    'gata' => 'Kvarngatan 2',
    'postnummer' => '16970',
    'ort' => 'Solna',
    'street_key' => jeab_kontakt_street_key('Kvarngatan 2'),
    'email' => 'ada.setplace@example.com',
], 'samma e-post slås inte ihop och den andra kontakten är orörd');

$keep = jeab_kontakter_set_place_dispatch([
    'action' => 'kontakter.set_place',
    'id' => 'c-empty',
    'postnummer' => '16970',
    'ort' => 'Stockholm',
    'gata' => 'Annangatan 9',
    'dry' => 0,
    'confirm' => 'SAM',
]);
sp(($keep['ok'] ?? false) === true && (int) ($keep['written'] ?? 1) === 0 && ($keep['skipped'] ?? []) === ['postnummer', 'ort', 'gata'], 'ifyllda fält hoppas över');
sp(sp_place('c-empty')['gata'] === 'Storgatan 1' && sp_place('c-empty')['postnummer'] === '11122', 'hoppad skrivning lämnar fälten');

$forced = jeab_kontakter_set_place_dispatch([
    'action' => 'kontakter.set_place',
    'id' => 'c-full',
    'postnummer' => '112 18',
    'ort' => 'sthlm',
    'force' => 1,
    'dry' => 0,
    'confirm' => 'SAM',
]);
sp(($forced['ok'] ?? false) === true && (int) ($forced['written'] ?? 0) === 1, 'force=1 skriver över ifyllda fält');
sp(($forced['before'] ?? null) === ['gata' => 'Kvarngatan 2', 'postnummer' => '16970', 'ort' => 'Solna'], 'force visar värdena före');
sp(($forced['after'] ?? null) === ['gata' => 'Kvarngatan 2', 'postnummer' => '11218', 'ort' => 'Stockholm'], 'force visar värdena efter och lämnar gatan när den inte skickas');
sp(sp_place('c-full')['gata'] === 'Kvarngatan 2' && sp_place('c-full')['street_key'] === jeab_kontakt_street_key('Kvarngatan 2'), 'utelämnad gata lämnar gatanyckeln');
$forceBatch = trim((string) ($forced['batch'] ?? ''));

$undoDry = jeab_kontakter_set_place_dispatch([
    'action' => 'kontakter.set_place',
    'undo' => 1,
    'batch' => $batch,
    'id' => 'c-empty',
]);
sp(($undoDry['ok'] ?? false) === true && (int) ($undoDry['dry'] ?? 0) === 1 && (int) ($undoDry['written'] ?? 1) === 0, 'undo är torrt som standard');
sp(($undoDry['before'] ?? null) === ['gata' => 'Storgatan 1', 'postnummer' => '11122', 'ort' => 'Solna'], 'torr ångring visar värdena före');
sp(($undoDry['after'] ?? null) === ['gata' => '', 'postnummer' => '', 'ort' => ''], 'torr ångring visar värdena efter');
sp(sp_place('c-empty')['postnummer'] === '11122', 'torr ångring skriver inte');

$undoDenied = jeab_kontakter_set_place_dispatch([
    'action' => 'kontakter.set_place',
    'undo' => 1,
    'batch' => $batch,
    'dry' => 0,
]);
sp(($undoDenied['ok'] ?? true) === false && ($undoDenied['error'] ?? '') === 'dry=0 kräver confirm=SAM', 'ångring med dry=0 kräver confirm');
sp(sp_place('c-empty')['gata'] === 'Storgatan 1', 'nekad ångring lämnar kontakten');

$undo = jeab_kontakter_set_place_dispatch([
    'action' => 'kontakter.set_place',
    'undo' => '1',
    'batch' => $batch,
    'id' => 'c-empty',
    'dry' => 0,
    'confirm' => 'SAM',
]);
sp(($undo['ok'] ?? false) === true && (int) ($undo['written'] ?? 0) === 1 && (int) ($undo['undo'] ?? 0) === 1, 'undo=1 batch tar tillbaka skrivningen');
sp(($undo['before'] ?? null) === ['gata' => 'Storgatan 1', 'postnummer' => '11122', 'ort' => 'Solna'], 'ångringen visar värdena före');
sp(($undo['after'] ?? null) === ['gata' => '', 'postnummer' => '', 'ort' => ''], 'ångringen visar värdena efter');
sp(sp_place('c-empty') === ['gata' => '', 'postnummer' => '', 'ort' => '', 'street_key' => '', 'email' => 'ada.setplace@example.com'], 'SQL-raden är återställd');
sp(str_contains(sp_log(), 'set_place c-empty batch ' . $batch . ' ångrade'), 'ångringen loggas');
sp(sp_place('c-full')['postnummer'] === '11218' && sp_place('c-full')['ort'] === 'Stockholm', 'ångring av en batch lämnar den andra kontakten');

$again = jeab_kontakter_set_place_dispatch([
    'action' => 'kontakter.set_place',
    'undo' => 1,
    'batch' => $batch,
    'dry' => 0,
    'confirm' => 'SAM',
]);
sp(($again['ok'] ?? true) === false && ($again['error'] ?? '') === 'Ingen körning att ångra.', 'samma batch ångras bara en gång');

$undoForce = jeab_kontakter_set_place_dispatch([
    'action' => 'kontakter.set_place',
    'undo' => 1,
    'batch' => $forceBatch,
    'dry' => 0,
    'confirm' => 'SAM',
]);
sp(($undoForce['ok'] ?? false) === true && ($undoForce['after'] ?? null) === ['gata' => 'Kvarngatan 2', 'postnummer' => '16970', 'ort' => 'Solna'], 'force-batchen återställer de tidigare fälten');
sp(sp_place('c-full')['postnummer'] === '16970' && sp_place('c-full')['ort'] === 'Solna', 'force-ångringen ligger i SQL');

$idsEnd = array_keys(jeab_kontakter_load());
sort($idsEnd);
sp($idsEnd === $idsBefore, 'antalet kontakter är oförändrat');

echo "kontakter.set_place: ok\n";
