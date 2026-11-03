<?php

declare(strict_types=1);

/**
 * Solkampanj är en egen lista utanför kontakter. Drop utan tables lyckas fast
 * solkampanj har en olänkad rad med kopior. Kolumner och värden rörs inte,
 * markören saknar tabellen, tables=solkampanj_recipients ger 400, och
 * sole_audit räknar den inte. En gammal backup återställer tabellen.
 * Påhittade namn och adresser, inget personnummer.
 */
if (!in_array('sqlite', PDO::getAvailableDrivers(), true) && !getenv('JEAB_TEST_MYSQL_PORT')) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-drop-solk-out-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
$public = $tmp . '/public-data';
mkdir($public, 0775, true);
$errLog = $tmp . '/php-error.log';
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $public;
$GLOBALS['_JEAB_SOLKAMPANJ_DIR'] = $tmp . '/privat/solkampanj';
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
$GLOBALS['_JEAB_CONTACTS_STRICT'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('CONTACTS_SOLE=1');
putenv('CONTACTS_STRICT');
unset($_ENV['CONTACTS_STRICT']);
putenv('JEAB_WEBHOOK_CAPTURE=1');
$GLOBALS['_JEAB_WEBHOOKS'] = [];
ini_set('log_errors', '1');
ini_set('error_log', $errLog);
$mysqlPort = (int) (getenv('JEAB_TEST_MYSQL_PORT') ?: 0);
$dbConfig = [
    'driver' => 'sqlite',
    'sqlite_path' => $tmp . '/app.sqlite',
    'json_path' => $tmp . '/store.json',
];
if ($mysqlPort > 0) {
    // Egen lokal MariaDB (fejkdata): en ny databas per körning, tas bort vid slut.
    $dbName = 'jeabt_' . bin2hex(random_bytes(4));
    (new PDO('mysql:host=127.0.0.1;port=' . $mysqlPort, 't', 't'))->exec('CREATE DATABASE ' . $dbName . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    // Grundschemat läggs in här: jeab_migrate_mysql hoppar över första satsen (den börjar med en kommentar).
    $boot = new PDO('mysql:host=127.0.0.1;port=' . $mysqlPort . ';dbname=' . $dbName . ';charset=utf8mb4', 't', 't');
    preg_match("/<<<'SQL'\\n(.*)\\nSQL;/s", (string) file_get_contents(dirname(__DIR__) . '/schema/mysql.php'), $m);
    $schema = (string) preg_replace('/^--.*$/m', '', $m[1] ?? '');
    foreach (array_filter(array_map('trim', explode(';', $schema))) as $stmt) {
        $boot->exec($stmt);
    }
    $boot = null;
    register_shutdown_function(static function () use ($mysqlPort, $dbName): void {
        (new PDO('mysql:host=127.0.0.1;port=' . $mysqlPort, 't', 't'))->exec('DROP DATABASE IF EXISTS ' . $dbName);
    });
    $dbConfig = [
        'driver' => 'mysql',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
        'mysql' => ['host' => '127.0.0.1;port=' . $mysqlPort, 'name' => $dbName, 'user' => 't', 'pass' => 't', 'charset' => 'utf8mb4'],
    ];
}
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => $dbConfig,
    'kontakter' => ['lookup_provider' => 'off'],
    'incoming_webhook_token' => 'not-the-drop-token',
    'outgoing_webhook_url' => '',
], true) . ";\n");
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-drop-fields']));
require dirname(__DIR__) . '/src/bootstrap.php';
putenv('JEAB_PNR_HMAC_KEY=drop-fields-test-key');

function cc(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/** Kolumndefinitioner som en gammal backup skulle ha sparat. @return array<string, array<string, mixed>> */
function t_solk_defs(PDO $pdo): array
{
    $want = array_flip(jeab_contacts_drop_legacy_solkampanj_columns());
    $mysql = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $rows = $mysql
        ? $pdo->query('SHOW FULL COLUMNS FROM solkampanj_recipients')->fetchAll(PDO::FETCH_ASSOC)
        : $pdo->query('PRAGMA table_info(solkampanj_recipients)')->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach (is_array($rows) ? $rows : [] as $col) {
        if (!is_array($col)) {
            continue;
        }
        $name = (string) ($col['Field'] ?? $col['name'] ?? '');
        if (!isset($want[$name])) {
            continue;
        }
        if ($mysql) {
            $default = $col['Default'] ?? null;
            $out[$name] = [
                'type' => strtolower((string) ($col['Type'] ?? '')),
                'null' => strtoupper((string) ($col['Null'] ?? 'YES')) === 'YES',
                'default' => $default === null ? null : (string) $default,
                'collation' => (string) ($col['Collation'] ?? ''),
            ];
        } else {
            $default = $col['dflt_value'] ?? null;
            $out[$name] = [
                'type' => strtoupper((string) ($col['type'] ?? '')),
                'null' => (int) ($col['notnull'] ?? 0) === 0,
                'default' => $default === null ? null : (string) $default,
            ];
        }
    }

    return $out;
}

/** @return array<string, bool> */
function t_solk_indexes(PDO $pdo): array
{
    $out = [];
    if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        foreach ($pdo->query('SHOW INDEX FROM solkampanj_recipients')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['Key_name']] = (int) $r['Non_unique'] === 0;
        }

        return $out;
    }
    foreach ($pdo->query('PRAGMA index_list(solkampanj_recipients)')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(string) $r['name']] = (int) $r['unique'] === 1;
    }

    return $out;
}

$root = dirname(__DIR__);
require_once $root . '/src/contacts_drop_customer_fields.php';
require_once $root . '/tests/fixtures/drop_keypair.php';
$dropPair = t_drop_keypair($tmp . '/keys');
t_drop_use_pub($dropPair);
require_once $root . '/src/solkampanj.php';
require_once $root . '/src/inkorg_store.php';
$store = jeab_store();
$GLOBALS['_JEAB_CONTACTS_STRICT'] = true;
putenv('CONTACTS_STRICT=1');
$pdo = $store->pdo();
$isMysql = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
jeab_solkampanj_ensure($pdo);
jeab_solkampanj_ensure_contact($pdo);
(new JeabPdoInkorgStore($pdo))->listForCompany('jarfalla');

cc(jeab_contacts_drop_tables() === ['reminders', 'bookings', 'inkorg_list'], 'drop-listan är de tre tabellerna');
cc(jeab_copy_columns('solkampanj_recipients') === [], 'solkampanj har inga kopiekolumner');

$pdo->exec("INSERT INTO solkampanj_recipients (customer_number, namn, first_name, mejl, gata, postnr, ort, contact_id) VALUES ('901', 'Ove Okand', 'Ove', 'ove.okand@example.test', 'Mangatan 2', '177 32', 'Jarfalla', '')");
$solkId = (int) $pdo->lastInsertId();
cc($solkId > 0, 'olänkad solkampanj-rad med kopior');
$before = $pdo->query('SELECT namn, first_name, mejl, gata, postnr, ort FROM solkampanj_recipients WHERE id = ' . $solkId)->fetch(PDO::FETCH_ASSOC);

$legacyAt = '2020-01-02T03:04:05+00:00';
$keyCol = $isMysql ? '`key`' : 'key';
$pdo->prepare('INSERT INTO settings (' . $keyCol . ', value) VALUES (?, ?)')->execute([
    jeab_copy_columns_marker_key(),
    json_encode(['solkampanj_recipients' => $legacyAt], JSON_UNESCAPED_SLASHES),
]);
jeab_copy_columns_forget();
cc(!isset(jeab_copy_columns_marker($pdo)['solkampanj_recipients']), 'publika markören ignorerar den gamla posten');

$audit = jeab_contacts_sole_audit($store);
$auditJson = (string) json_encode($audit);
$seen = [];
$empty = 0;
$differName = 0;
foreach (['jarfalla', 'malmo'] as $company) {
    foreach ((array) ($audit['co'][$company] ?? []) as $table => $counts) {
        $seen[] = (string) $table;
        $empty += (int) (is_array($counts) ? ($counts['empty_contact_id'] ?? 0) : 0);
        $differName += (int) (is_array($counts) ? ($counts['differ']['name'] ?? 0) : 0);
    }
}
cc(($audit['ok'] ?? false) === true && ($audit['note'] ?? '') === 'Solkampanj är en egen lista utanför kontakter.', 'sole_audit säger att solkampanj ligger utanför kontakter');
cc(!in_array('solkampanj_recipients', $seen, true) && !str_contains($auditJson, 'solkampanj_recipients'), 'sole_audit räknar inte solkampanj');
cc($empty === 0 && $differName === 0, 'den olänkade solkampanj-raden ingår inte i täckningen');
cc(!str_contains($auditJson, 'Ove') && !str_contains($auditJson, 'ove.okand') && !str_contains($auditJson, 'Mangatan'), 'sole_audit listar inga värden');

$run = jeab_contacts_drop_customer_fields(['dry' => 0], $store);
jeab_copy_columns_forget();
cc(($run['ok'] ?? false) === true && ($run['written'] ?? false) === true && ($run['blocked'] ?? null) === [], 'drop utan tables lyckas trots olänkad solkampanj');
cc(($run['selected'] ?? null) === ['reminders', 'bookings', 'inkorg_list'], 'valda tabeller är de tre');
cc(jeab_solkampanj_has_column($pdo, 'namn') && jeab_solkampanj_has_column($pdo, 'first_name') && jeab_solkampanj_has_column($pdo, 'mejl') && jeab_solkampanj_has_column($pdo, 'gata'), 'solkampanj-kolumnerna är orörda');
$after = $pdo->query('SELECT namn, first_name, mejl, gata, postnr, ort FROM solkampanj_recipients WHERE id = ' . $solkId)->fetch(PDO::FETCH_ASSOC);
cc($after === $before, 'solkampanj-värdena är orörda');
$mark = jeab_copy_columns_marker($pdo);
cc(isset($mark['reminders'], $mark['bookings'], $mark['inkorg_list']) && !isset($mark['solkampanj_recipients']), 'markören saknar solkampanj');
$stored = json_decode((string) $pdo->query('SELECT value FROM settings WHERE ' . $keyCol . ' = ' . $pdo->quote(jeab_copy_columns_marker_key()))->fetchColumn(), true);
cc(is_array($stored) && ($stored['solkampanj_recipients'] ?? '') === $legacyAt, 'den gamla markörposten ligger kvar');
$backup = json_decode((string) file_get_contents(jeab_contacts_drop_dir() . '/' . $run['batch_id'] . '.json'), true);
cc(is_array($backup) && !isset($backup['tables']['solkampanj_recipients']), 'backupen har inte solkampanj');

$badDry = jeab_contacts_drop_customer_fields(['dry' => 1, 'tables' => 'solkampanj_recipients'], $store);
cc(($badDry['ok'] ?? true) === false && ($badDry['status'] ?? 0) === 400 && ($badDry['error'] ?? '') === 'Okänd tabell.' && ($badDry['unknown'] ?? null) === ['solkampanj_recipients'] && ($badDry['written'] ?? true) === false, 'tables=solkampanj_recipients ger 400 vid dry');
$badLive = jeab_contacts_drop_customer_fields(['dry' => 0, 'tables' => 'bookings,solkampanj_recipients'], $store);
cc(($badLive['status'] ?? 0) === 400 && ($badLive['error'] ?? '') === 'Okänd tabell.' && ($badLive['unknown'] ?? null) === ['solkampanj_recipients'] && ($badLive['written'] ?? true) === false, 'tables=solkampanj_recipients ger 400 och skriver inget');
cc($pdo->query('SELECT namn, first_name, mejl, gata, postnr, ort FROM solkampanj_recipients WHERE id = ' . $solkId)->fetch(PDO::FETCH_ASSOC) === $before, '400 lämnar värdena');
$stored = json_decode((string) $pdo->query('SELECT value FROM settings WHERE ' . $keyCol . ' = ' . $pdo->quote(jeab_copy_columns_marker_key()))->fetchColumn(), true);
cc(is_array($stored) && ($stored['solkampanj_recipients'] ?? '') === $legacyAt && !isset(jeab_copy_columns_marker($pdo)['solkampanj_recipients']), '400 rör inte markören');

// Gammal backup: kolumnerna tas bort för hand och läggs tillbaka av undo.
$pdo->exec($isMysql ? 'CREATE UNIQUE INDEX solk_gata_unique ON solkampanj_recipients (gata(100))' : 'CREATE UNIQUE INDEX solk_gata_unique ON solkampanj_recipients (gata)');
$defs = t_solk_defs($pdo);
cc(array_keys($defs) === ['namn', 'first_name', 'mejl', 'gata'] || count($defs) === 4, 'backupen kan läsa kolumntyperna');
$pub = jeab_contacts_drop_pubkey();
$key = random_bytes(32);
$wrapped = is_array($pub) ? jeab_contacts_drop_wrap($key, $pub['key']) : null;
$sealed = jeab_contacts_drop_seal_rows([[
    'id' => $solkId,
    'contact_id' => '',
    'values' => [
        'namn' => 'Ove Okand',
        'first_name' => 'Ove',
        'mejl' => 'ove.okand@example.test',
        'gata' => 'Mangatan 2',
    ],
]], $key);
$batch = bin2hex(random_bytes(8));
$indexSql = $isMysql
    ? 'CREATE UNIQUE INDEX solk_gata_unique ON solkampanj_recipients (gata(100))'
    : 'CREATE UNIQUE INDEX solk_gata_unique ON solkampanj_recipients (gata)';
$payload = [
    'batch_id' => $batch,
    'at' => '2020-01-02T03:04:05+00:00',
    'seal' => 'v3',
    'pub_sha256' => is_array($pub) ? $pub['sha256'] : '',
    'wrapped_key' => $wrapped,
    'tables' => [
        'solkampanj_recipients' => [
            'columns' => ['namn', 'first_name', 'mejl', 'gata'],
            'defs' => $defs,
            'indexes' => ['solk_gata_unique' => $indexSql],
            'rows' => $sealed,
        ],
    ],
];
cc(is_array($pub) && is_string($wrapped) && is_array($sealed) && jeab_contacts_drop_write_backup($batch, $payload) !== null, 'gammal backup skrivs');
cc(jeab_contacts_drop_mark_columns($batch, 'solkampanj_recipients', ['namn', 'first_name', 'mejl', 'gata']), 'gammal backup markerar kolumnerna');
$pdo->exec($isMysql ? 'DROP INDEX solk_gata_unique ON solkampanj_recipients' : 'DROP INDEX IF EXISTS solk_gata_unique');
foreach (['namn', 'first_name', 'mejl', 'gata'] as $col) {
    $pdo->exec('ALTER TABLE solkampanj_recipients DROP COLUMN ' . $col);
}
cc(!jeab_solkampanj_has_column($pdo, 'namn') && !jeab_solkampanj_has_column($pdo, 'mejl') && jeab_solkampanj_has_column($pdo, 'postnr'), 'kolumnerna är borta inför undo, postnr ligger kvar');
t_drop_undo_key($dropPair);
$undo = jeab_contacts_drop_customer_fields_undo(['dry' => 0, 'batch_id' => $batch], $store);
jeab_copy_columns_forget();
cc(($undo['ok'] ?? false) === true && isset($undo['tables']['solkampanj_recipients']), 'undo återställer solkampanj från den gamla backupen');
cc(jeab_solkampanj_has_column($pdo, 'namn') && jeab_solkampanj_has_column($pdo, 'first_name') && jeab_solkampanj_has_column($pdo, 'mejl') && jeab_solkampanj_has_column($pdo, 'gata'), 'undo lägger tillbaka kolumnerna');
$restored = $pdo->query('SELECT namn, first_name, mejl, gata, postnr, ort FROM solkampanj_recipients WHERE id = ' . $solkId)->fetch(PDO::FETCH_ASSOC);
cc($restored === $before, 'undo lägger tillbaka värdena');
cc((t_solk_indexes($pdo)['solk_gata_unique'] ?? false) === true, 'undo lägger tillbaka det unika indexet');
$stored = json_decode((string) $pdo->query('SELECT value FROM settings WHERE ' . $keyCol . ' = ' . $pdo->quote(jeab_copy_columns_marker_key()))->fetchColumn(), true);
cc(is_array($stored) && ($stored['solkampanj_recipients'] ?? '') === $legacyAt && !isset(jeab_copy_columns_marker($pdo)['solkampanj_recipients']), 'den gamla markörposten stör inte efter undo');

echo "ALL OK\n";
