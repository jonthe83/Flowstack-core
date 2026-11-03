<?php

declare(strict_types=1);

/**
 * SQL är facit för kontakter och inkorgskontakter när tabellen redan har rader.
 * Äldre JSON får inte skriva över dem. Syntetiska rader.
 */

$tmp = sys_get_temp_dir() . '/jeab-sql-facit-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'kontakter' => ['storage' => 'sql', 'sql_writes' => true, 'lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function facit(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$contactModule = jeab_migrate_find('contacts');
$inboxModule = jeab_migrate_find('inkorg_contacts');
facit(is_array($contactModule) && is_array($inboxModule), 'modulerna finns');
facit(jeab_migrate_contacts_preserve_sql() && jeab_migrate_inkorg_contacts_preserve_sql(), 'kontakter och inkorgen bevarar SQL');
facit(jeab_migrate_contacts_authoritative_sql() === false, 'tom SQL kan fortfarande fyllas');

$pdo = jeab_data_pdo();
facit($pdo instanceof PDO, 'sqlite finns');
$contacts = new JeabPdoContactStore($pdo);
$inbox = new JeabPdoInboxContactStore($pdo);

$facitRow = jeab_kontakt_blank([
    'name' => 'Sql Facit',
    'email' => 'facit@example.test',
    'street' => 'Facitgatan 1',
    'postal_code' => '17740',
    'city' => 'Sqlstad',
]);
$facitRow['id'] = 'kt_facit000000000001';
$only = jeab_kontakt_blank([
    'name' => 'Bara Sql',
    'email' => 'bara.sql@example.test',
    'street' => 'Enbartgatan 2',
    'postal_code' => '17741',
    'city' => 'Enbart',
]);
$only['id'] = 'kt_facit000000000002';
$contacts->upsert($facitRow['id'], $facitRow);
$contacts->upsert($only['id'], $only);
$jsonFacit = $facitRow;
$jsonFacit['city'] = 'Gammelstad';
$jsonOnly = jeab_kontakt_blank([
    'name' => 'Bara Json',
    'email' => 'bara.json@example.test',
    'street' => 'Jsongatan 3',
    'postal_code' => '17742',
    'city' => 'Jsonstad',
]);
$jsonOnly['id'] = 'kt_facit000000000003';
file_put_contents($tmp . '/kontakter.json', json_encode(['contacts' => [
    $jsonFacit['id'] => $jsonFacit,
    $jsonOnly['id'] => $jsonOnly,
]], JSON_UNESCAPED_UNICODE));

$before = $contacts->sqlAll();
$module = $contactModule;
$dry = jeab_migrate_copy($module, true);
facit(!empty($dry['preserved']) && empty($dry['match']) && empty($dry['written']), 'torrkörningen skriver inte och saknat id är inte match');
$afterDry = $contacts->sqlAll();
facit(($afterDry[$facitRow['id']]['city'] ?? '') === 'Sqlstad' && isset($afterDry[$only['id']]) && !isset($afterDry[$jsonOnly['id']]), 'torrkörningen lämnar SQL och hoppar över JSON-only');
facit($afterDry === $before, 'torrkörningen rör inte raderna');

$wet = jeab_migrate_copy($module, false);
$after = $contacts->sqlAll();
facit(!empty($wet['ok']) && !empty($wet['preserved']) && !empty($wet['written']), 'våtkörningen lägger bara till saknade id');
facit(($after[$facitRow['id']]['city'] ?? '') === 'Sqlstad', 'äldre JSON skriver inte över SQL-staden');
facit(($after[$only['id']]['city'] ?? '') === 'Enbart', 'SQL-raden utan JSON-kopia finns kvar');
facit(($after[$jsonOnly['id']]['city'] ?? '') === 'Jsonstad', 'JSON-id som saknas i SQL läggs till');
$raw = (string) $pdo->query('SELECT row_json FROM contacts WHERE id = ' . $pdo->quote($facitRow['id']))->fetchColumn();
facit(!str_contains($raw, 'Gammelstad'), 'row_json behåller SQL-versionen');
$again = jeab_migrate_copy($module, false);
$afterAgain = $contacts->sqlAll();
facit(!empty($again['preserved']) && empty($again['written']) && count($afterAgain) === count($after) && ($afterAgain[$facitRow['id']]['city'] ?? '') === 'Sqlstad', 'andra kopian skriver inte om');

$inboxSql = [
    'id' => 'c-facit-sql',
    'name' => 'Sql Inkorg',
    'emails' => ['inkorg.facit@example.test'],
    'mobile' => '0701000001',
    'town' => 'Sqlstad',
];
$inboxOnly = [
    'id' => 'c-facit-only',
    'name' => 'Bara Inkorg',
    'emails' => ['inkorg.only@example.test'],
    'mobile' => '0701000002',
    'town' => 'Enbart',
];
$inbox->upsert($inboxSql['id'], $inboxSql);
$inbox->upsert($inboxOnly['id'], $inboxOnly);
$inboxJson = $inboxSql;
$inboxJson['name'] = 'Gammal Inkorg';
$inboxNew = [
    'id' => 'c-facit-json',
    'name' => 'Ny Inkorg',
    'emails' => ['inkorg.json@example.test'],
    'mobile' => '0701000003',
    'town' => 'Jsonstad',
];
file_put_contents($tmp . '/inkorg/contacts.json', json_encode(['contacts' => [
    $inboxJson['id'] => $inboxJson,
    $inboxNew['id'] => $inboxNew,
]], JSON_UNESCAPED_UNICODE));
$inboxWet = jeab_migrate_copy($inboxModule, false);
$inboxAfter = $inbox->sqlAll();
facit(!empty($inboxWet['preserved']) && ($inboxAfter[$inboxSql['id']]['name'] ?? '') === 'Sql Inkorg', 'äldre JSON skriver inte över inkorgskontakten');
facit(isset($inboxAfter[$inboxOnly['id']]) && ($inboxAfter[$inboxNew['id']]['name'] ?? '') === 'Ny Inkorg', 'SQL-inkorgen finns kvar och saknad JSON läggs till');

echo "contacts_sql_facit_test ok\n";
