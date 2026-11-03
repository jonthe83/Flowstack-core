<?php

declare(strict_types=1);

/**
 * Kontakter JSON → SQL. Lagret är av tills cutover. Syntetiska rader, ingen kunddata.
 * Rollback i det här testet skriver JSON, så LEGACY_JSON_WRITES är på.
 */

$tmp = sys_get_temp_dir() . '/jeab-kontakter-sql-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('LEGACY_JSON_WRITES=1');
$_ENV['LEGACY_JSON_WRITES'] = '1';
$GLOBALS['_JEAB_LEGACY_JSON_WRITES'] = true;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'kontakter' => ['storage' => 'sql', 'lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ks(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$migrate = (string) file_get_contents(dirname(__DIR__) . '/scripts/migrate-kontakter-sql.php');
$ht = (string) file_get_contents(dirname(__DIR__) . '/scripts/.htaccess');
ks(str_contains($migrate, "PHP_SAPI !== 'cli'") && str_contains($migrate, "http_response_code(403)") && str_contains($migrate, "echo 'Forbidden'"), 'migreringen är bara CLI och svarar 403 Forbidden');
ks(str_contains($ht, 'Require all denied'), 'scripts/.htaccess nekar HTTP');
ks(str_contains((string) file_get_contents(dirname(__DIR__) . '/hook.php'), 'kontakter.sql_migrate'), 'hook.php tar kontakter.sql_migrate');
ks(str_contains((string) file_get_contents(dirname(__DIR__) . '/src/db.php'), 'function jeab_ensure_contacts_tables'), 'tabellerna skapas i db.php');

$one = jeab_kontakt_blank([
    'name' => 'Sql Test',
    'email' => 'sql.test@example.test',
    'phone' => '0701112233',
    'street' => 'Sqlgatan 5',
    'street_key' => 'sqlgatan 5',
    'postal_code' => '12345',
    'city' => 'Teststad',
]);
$one['id'] = 'kt_sql00000000000001';
$two = jeab_kontakt_blank([
    'name' => 'Annan Sql',
    'email' => 'annan.sql@example.test',
    'phone' => '0709998877',
    'street' => 'Annanvagen 2',
    'street_key' => 'annanvagen 2',
    'postal_code' => '54321',
    'city' => 'Annaby',
]);
$two['id'] = 'kt_sql00000000000002';
file_put_contents($tmp . '/kontakter.json', json_encode(['contacts' => [
    $one['id'] => $one,
    $two['id'] => $two,
]], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
file_put_contents($tmp . '/inkorg/contacts.json', json_encode(['contacts' => [
    'c-0595fd66c5' => [
        'name' => 'Nyckel Test',
        'emails' => ['nyckel.sql@example.test'],
        'mobile' => '+46702223344',
        'address' => 'Nyckelvagen 4',
        'town' => 'Teststad',
        'notes' => '',
        'created_at' => '2026-01-02T12:00:00+01:00',
    ],
    'c-sql000001' => [
        'id' => 'c-sql000001',
        'name' => 'Inkorg Test',
        'emails' => ['inkorg.sql@example.test'],
        'mobile' => '+46701112233',
        'address' => 'Inkorgsgatan 1',
        'town' => 'Teststad',
        'notes' => '',
        'contact_id' => 'kt_sql00000000000001',
        'created_at' => '2026-01-01T12:00:00+01:00',
    ],
]], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

ks(jeab_kontakter_storage_mode() === 'json', 'config storage=sql lämnar flaggan av');
ks(jeab_contact_store() instanceof JeabPdoContactStore, 'registret är SQL innan flaggan');
ks(count(jeab_kontakter_load()) === 0, 'live läser SQL och inte JSON-filen');

$dry = jeab_kontakter_sql_migrate([]);
ks(($dry['dry'] ?? false) === true && ($dry['written'] ?? true) === false, 'migrate utan dry skriver inte');
ks(($dry['registry_sql'] ?? -1) === 0 && ($dry['inbox_sql'] ?? -1) === 0, 'dry lämnar SQL tom');

$write = jeab_kontakter_sql_migrate(['dry' => 0]);
ks(!empty($write['written']) && !empty($write['count_match']) && !empty($write['checksum_match']), 'migrate write matchar antal och checksum');

$again = jeab_kontakter_sql_migrate(['dry' => 0]);
ks(!empty($again['idempotent']) && ($again['registry_before'] ?? -1) === 2 && ($again['inbox_before'] ?? -1) === 2, 'andra migrate är idempotent');

$jsonOnly = jeab_inkorg_contacts_json_map()['c-0595fd66c5'] ?? [];
$sqlOnly = (new JeabPdoInboxContactStore(jeab_contact_store_pdo()))->sqlAll()['c-0595fd66c5'] ?? [];
ks(is_array($jsonOnly) && !array_key_exists('id', $jsonOnly) && (string) ($sqlOnly['id'] ?? '') === 'c-0595fd66c5', 'c-id ligger som nyckel i JSON och som fält i SQL');

$verify = jeab_kontakter_sql_verify(['sample' => 5]);
$encoded = json_encode($verify, JSON_UNESCAPED_UNICODE);
$inboxHit = false;
foreach ((array) ($verify['samples']['inbox'] ?? []) as $sample) {
    if (is_array($sample) && (string) ($sample['id'] ?? '') === 'c-0595fd66c5' && ($sample['match'] ?? false) === true) {
        $inboxHit = true;
    }
}
ks($inboxHit && !empty($verify['inbox_checksum_match']) && !empty($verify['samples_match']), 'inbox-stickprov med c-id matchar när checksumman gör det');
$changed = jeab_inkorg_contacts_json_map();
$changed['c-0595fd66c5']['town'] = 'Annanort';
$bad = jeab_kontakter_sql_sample($changed, (new JeabPdoInboxContactStore(jeab_contact_store_pdo()))->sqlAll(), 5);
$badHit = false;
foreach ($bad['rows'] as $sample) {
    if ((string) ($sample['id'] ?? '') === 'c-0595fd66c5') {
        $badHit = ($sample['match'] ?? true) === false;
    }
}
ks($badHit && ($bad['match'] ?? true) === false, 'ett ändrat fält gör att inbox-stickprovet faller');
ks(!empty($verify['match']) && !empty($verify['registry_count_match']) && !empty($verify['inbox_count_match']), 'verify matchar');
ks(is_string($encoded) && !str_contains($encoded, 'example.test') && !str_contains($encoded, 'Sqlgatan') && !str_contains($encoded, 'Inkorg Test'), 'verify ekar inte radinnehåll');
ks(($verify['storage'] ?? '') === 'json' && ($verify['sql_writes'] ?? true) === false, 'verify ser flaggan av');
ks(count(jeab_kontakter_load()) === 2 && jeab_contact_store() instanceof JeabPdoContactStore, 'live läser SQL före cutover');

$preview = jeab_kontakter_sql_cutover(['enable' => 1]);
ks(($preview['dry'] ?? false) === true && jeab_kontakter_storage_mode() === 'json', 'cutover utan dry slår inte på');

$registry = new JeabPdoContactStore(jeab_contact_store_pdo());
$registry->replaceAll([]);
$contactPdo = jeab_contact_store_pdo();
$gone = (string) $contactPdo->query('SELECT id FROM contacts LIMIT 1')->fetchColumn();
$contactPdo->prepare('DELETE FROM contacts WHERE id = ?')->execute([$gone]);
$blocked = jeab_kontakter_sql_cutover(['enable' => 1, 'dry' => 0]);
ks(empty($blocked['ok']) && jeab_kontakter_storage_mode() === 'json', 'cutover vägrar när verify inte matchar');
jeab_kontakter_sql_migrate(['dry' => 0]);

$on = jeab_kontakter_sql_cutover(['enable' => 1, 'dry' => 0]);
ks(!empty($on['ok']) && jeab_kontakter_storage_mode() === 'sql', 'cutover dry=0 enable=1');
ks(jeab_contact_store() instanceof JeabPdoContactStore, 'aktiv store är PDO');

$jsonMtime = filemtime($tmp . '/kontakter.json');
$inboxMtime = filemtime($tmp . '/inkorg/contacts.json');
jeab_kontakter_mutate(static function (array &$contacts): void {
    $contacts['kt_sql00000000000001']['city'] = 'Nyort';
});
ks(filemtime($tmp . '/kontakter.json') === $jsonMtime, 'mutate skriver inte kontakter.json');
$updated = jeab_kontakt_get('kt_sql00000000000001');
ks(is_array($updated) && (string) ($updated['city'] ?? '') === 'Nyort', 'mutate skriver SQL');

$store = jeab_contact_store();
$byEmail = $store->findByEmail('sql.test@example.test');
ks(count($byEmail) === 1 && (string) ($byEmail[0]['id'] ?? '') === 'kt_sql00000000000001', 'index e-post');
ks(count($store->findByPhone('0701112233')) === 1, 'index telefon');
ks(count($store->findByStreetKey('sqlgatan 5')) === 1, 'index street_key');
ks(count($store->findByPostalCode('54321')) === 1, 'index postnummer');
ks(count($store->findByOwnerNameFold(jeab_kontakt_fold_text('Sql Test'))) === 1, 'index namn');

$reserve = jeab_kontakt_blank([
    'name' => 'Bara Json',
    'email' => 'bara.json@example.test',
    'street' => 'Reservgatan 3',
    'street_key' => 'reservgatan 3',
    'postal_code' => '11122',
    'city' => 'Reservstad',
]);
$reserve['id'] = 'kt_sql00000000000009';
$raw = json_decode((string) file_get_contents($tmp . '/kontakter.json'), true);
$raw['contacts'][$reserve['id']] = $reserve;
file_put_contents($tmp . '/kontakter.json', json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$fallback = jeab_kontakt_get('kt_sql00000000000009');
ks($fallback === null, 'JSON-rad som saknas i SQL läses inte');
ks(count(jeab_contact_store()->all()) === 2, 'all() tar inte med JSON-rader');
ks((new JeabPdoContactStore(jeab_contact_store_pdo()))->count() === 2, 'backup-raden skrivs inte in i SQL');
$GLOBALS['_JEAB_DATA_BACKEND'] = 'mysql';
ks(jeab_kontakt_get('kt_sql00000000000009') === null, 'mysql-backend läser inte JSON-reserven');
ks(count(jeab_contact_store()->all()) === 2, 'all() är bara SQL');
unset($GLOBALS['_JEAB_DATA_BACKEND']);
ks(jeab_kontakt_get('kt_sql00000000000009') === null, 'utan mysql-backend läses JSON-reserven inte heller');

$saved = jeab_inkorg_contact_save([
    'name' => 'Ny Inkorg',
    'emails' => 'ny.inkorg@example.test',
    'mobile' => '0701234567',
]);
ks(is_array($saved) && str_starts_with((string) ($saved['id'] ?? ''), 'c-'), 'inkorg_contacts skriver via lagret');
ks(filemtime($tmp . '/inkorg/contacts.json') === $inboxMtime, 'sparningen skriver inte contacts.json');
$inboxStore = new JeabPdoInboxContactStore(jeab_contact_store_pdo());
ks(count($inboxStore->findByEmail('ny.inkorg@example.test')) === 1, 'index inkorg e-post');
$inboxName = (string) (jeab_inkorg_contacts()[(string) $saved['id']]['name'] ?? '');
ks($inboxName === 'Ny Inkorg', 'inkorg_contacts läser SQL');

$repoint = jeab_kontakt_repoint_inbox_contacts('kt_sql00000000000001', 'kt_sql00000000000002');
ks($repoint === 1, 'dedupe pekar om inkorgskontakten i SQL');
$moved = jeab_kontakt_inbox_read()['c-sql000001'] ?? [];
ks((string) ($moved['contact_id'] ?? '') === 'kt_sql00000000000002', 'contact_id ligger i SQL');
ks(filemtime($tmp . '/inkorg/contacts.json') === $inboxMtime, 'dedupe skriver inte contacts.json');

$old = jeab_kontakt_migrate_read_old();
ks(isset($old['c-sql000001']) && (string) ($old[(string) $saved['id']]['name'] ?? '') === 'Ny Inkorg', 'migrate läser SQL');
$beforeRegistry = count(jeab_kontakter_load());
$migrated = jeab_kontakter_migrate_inbox(['dry' => 0]);
ks(!empty($migrated['ok']) && ($migrated['counts']['moved'] ?? 0) + ($migrated['counts']['merged'] ?? 0) >= 1, 'migrate_inbox skriver registret via SQL');
ks(count(jeab_kontakter_load()) >= $beforeRegistry, 'registret finns kvar i SQL');

$back = jeab_kontakter_sql_cutover(['enable' => 0, 'dry' => 0]);
ks(!empty($back['ok']) && jeab_kontakter_storage_mode() === 'json', 'rollback till JSON');
ks(jeab_contact_store() instanceof JeabPdoContactStore, 'live läser SQL efter rollback');
$after = json_decode((string) file_get_contents($tmp . '/kontakter.json'), true);
ks(is_array($after) && (($after['contacts']['kt_sql00000000000001']['city'] ?? '') === 'Nyort'), 'rollback skrev SQL-raden till JSON');

file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-kontakter-sql']));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-kontakter-sql';
$hook = jeab_kontakter_sql_dispatch(['action' => 'kontakter.sql_migrate']);
ks(!empty($hook['ok']) && ($hook['dry'] ?? false) === true && ($hook['written'] ?? true) === false && ($hook['status'] ?? 0) === 200, 'hook kontakter.sql_migrate dry');
$seen = jeab_kontakter_sql_dispatch(['action' => 'kontakter.sql_verify', 'sample' => 3]);
ks(!empty($seen['ok']) && isset($seen['registry_json'], $seen['inbox_json']), 'hook kontakter.sql_verify');
$flag = jeab_kontakter_sql_dispatch(['action' => 'kontakter.sql_cutover']);
ks(!empty($flag['ok']) && ($flag['storage'] ?? '') === 'json' && ($flag['changed'] ?? true) === false, 'hook kontakter.sql_cutover utan enable');
$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = jeab_kontakter_sql_dispatch(['action' => 'kontakter.sql_migrate', 'dry' => 0]);
ks(empty($denied['ok']) && ($denied['status'] ?? 0) === 401, 'hook utan token skriver inte');

echo "ok\n";
