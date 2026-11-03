<?php

declare(strict_types=1);

/**
 * contacts.drop_customer_fields för inkorg_list: historiktrådar utan kontakt blockerar
 * inte och visas efter DROP. Solkampanj är en egen lista, länkas med exakt en träff
 * och lämnas orörd av DROP. Undo lägger tillbaka inkorgens kolumn, index och row_json.
 * Påhittade namn och adresser, inget personnummer.
 */
if (!in_array('sqlite', PDO::getAvailableDrivers(), true) && !getenv('JEAB_TEST_MYSQL_PORT')) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-drop-3b-' . bin2hex(random_bytes(4));
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
/** @return array<string, bool> indexnamn => unikt */
function t_indexes(PDO $pdo, string $table): array
{
    $out = [];
    if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        foreach ($pdo->query('SHOW INDEX FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['Key_name']] = (int) $r['Non_unique'] === 0;
        }

        return $out;
    }
    foreach ($pdo->query('PRAGMA index_list(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(string) $r['name']] = (int) $r['unique'] === 1;
    }

    return $out;
}
jeab_solkampanj_ensure($pdo);
jeab_solkampanj_ensure_contact($pdo);
(new JeabPdoInkorgStore($pdo))->listForCompany('jarfalla');
$pdo->exec('CREATE INDEX IF NOT EXISTS inkorg_list_customer ON inkorg_list (customer_email)');
$pdo->exec($isMysql ? 'CREATE UNIQUE INDEX solk_gata_unique ON solkampanj_recipients (gata(100))' : 'CREATE UNIQUE INDEX solk_gata_unique ON solkampanj_recipients (gata)');

$ann = jeab_kontakt_find_or_create(['name' => 'Ann Solberg', 'email' => 'ann.solberg@example.test', 'company' => 'jarfalla'], false, false);
$annId = (string) $ann['id'];
jeab_kontakt_find_or_create(['name' => 'Malin Malm', 'email' => 'malin.malm@example.test', 'company' => 'malmo'], false, false);
$realIdx = jeab_solkampanj_contact_index();
cc(jeab_solkampanj_match_contact($realIdx, '', 'malin.malm@example.test') === '' && jeab_solkampanj_match_contact($realIdx, '', 'ann.solberg@example.test') === $annId, 'Malmö-kontakt länkas inte, Järfälla-kontakt gör det');

// Exakt en träff: kundnummer och mejl som pekar på olika kontakter ger ingen länk.
$idx = ['number' => ['900' => ['k-a' => true]], 'email' => ['x@example.test' => ['k-b' => true], 'y@example.test' => ['k-a' => true]]];
cc(jeab_solkampanj_match_contact($idx, '900', 'x@example.test') === '', 'två olika träffar länkar inte');
cc(jeab_solkampanj_match_contact($idx, '900', 'y@example.test') === 'k-a', 'samma kontakt från båda hållen länkas');
cc(jeab_solkampanj_match_contact($idx, '', 'z@example.test') === '', 'ingen träff länkar inte');

$csv = "kundnummer;namn;gata;postnr;ort;mejl;saldo\n"
    . "901;Ann Solberg;Solgatan 1;177 31;Järfälla;ann.solberg@example.test;0\n"
    . "902;Ove Okand;Mångatan 2;177 32;Järfälla;ove.okand@example.test;0\n";
$imp = jeab_solkampanj_import_csv($csv);
cc(($imp['imported'] ?? 0) === 2 && ($imp['unlinked'] ?? -1) === 0, 'import före DROP sparar alla rader');
cc((int) $pdo->query("SELECT COUNT(*) FROM solkampanj_recipients WHERE contact_id <> ''")->fetchColumn() === 1, 'importen länkar Ann med exakt en träff');
$pdo->exec("UPDATE solkampanj_recipients SET contact_id = ''");
$dryLink = jeab_solkampanj_link_contacts(true, $pdo);
cc(($dryLink['written'] ?? true) === false && (int) $pdo->query("SELECT COUNT(*) FROM solkampanj_recipients WHERE contact_id <> ''")->fetchColumn() === 0, 'dry länkar inget');
$link = jeab_solkampanj_link_contacts(false, $pdo);
$annRow = (int) $pdo->query("SELECT id FROM solkampanj_recipients WHERE customer_number = '901'")->fetchColumn();
$oveRow = (int) $pdo->query("SELECT id FROM solkampanj_recipients WHERE customer_number = '902'")->fetchColumn();
cc((string) $pdo->query('SELECT contact_id FROM solkampanj_recipients WHERE id = ' . $annRow)->fetchColumn() === $annId, 'Ann länkas till sin kontakt');
cc((string) $pdo->query('SELECT contact_id FROM solkampanj_recipients WHERE id = ' . $oveRow)->fetchColumn() === '', 'Ove utan kontakt länkas inte');

// inkorg_list: L1 länkad, H1 historik utan kontakt och utan e-post i row_json, H2 med samma i row_json.
$put = $pdo->prepare('INSERT INTO inkorg_list (id, company, customer_email, list_at, list_status, contact_id, row_json) VALUES (?, ?, ?, ?, ?, ?, ?)');
$put->execute(['t-l1', 'jarfalla', 'ann.solberg@example.test', '2026-01-03', 'closed', $annId, json_encode(['id' => 't-l1', 'subject' => 'Länkad'])]);
$put->execute(['t-h1', 'jarfalla', 'gammal.kund@example.test', '2026-01-02', 'closed', '', json_encode(['id' => 't-h1', 'subject' => 'Historik'])]);
$put->execute(['t-h2', 'jarfalla', 'annan.kund@example.test', '2026-01-01', 'closed', '', json_encode(['id' => 't-h2', 'subject' => 'Historik 2', 'customer_email' => 'annan.kund@example.test'])]);
$put->execute(['t-h3', 'jarfalla', 'tom.json@example.test', '2025-12-31', 'closed', '', '']);

// Ove saknar kontakt. Solkampanj ligger utanför drop och blockerar inte.
$drySolk = jeab_contacts_drop_customer_fields(['dry' => 1], $store);
cc(($drySolk['ok'] ?? false) === true && !in_array('solkampanj_recipients', (array) ($drySolk['blocked'] ?? []), true) && !isset($drySolk['tables']['solkampanj_recipients']), 'olänkad solkampanj blockerar inte');
cc(jeab_solkampanj_has_column($pdo, 'mejl') && jeab_copy_column_present($pdo, 'inkorg_list', 'customer_email'), 'torrkörningen rör inga kolumner');

// Annan e-post i row_json än i kolumnen: konflikt.
$pdo->exec("UPDATE inkorg_list SET row_json = '" . json_encode(['id' => 't-h1', 'customer_email' => 'fel@example.test']) . "' WHERE id = 't-h1'");
$conf = jeab_contacts_drop_customer_fields(['dry' => 0], $store);
cc(($conf['status'] ?? 0) === 409 && ($conf['conflicts'][0]['id'] ?? '') === 't-h1', 'olika e-post i row_json och kolumn stoppar');
$pdo->exec("UPDATE inkorg_list SET row_json = '" . json_encode(['id' => 't-h1', 'subject' => 'Historik']) . "' WHERE id = 't-h1'");

// Länkad tråd med en adress kontakten inte har: konflikt.
$pdo->exec("UPDATE inkorg_list SET customer_email = 'inte.ann@example.test' WHERE id = 't-l1'");
$conf2 = jeab_contacts_drop_customer_fields(['dry' => 0], $store);
cc(($conf2['status'] ?? 0) === 409 && ($conf2['conflicts'][0]['fields'] ?? []) === ['email'], 'länkad tråd med främmande adress stoppar');
$pdo->exec("UPDATE inkorg_list SET customer_email = 'ann.solberg@example.test' WHERE id = 't-l1'");

$dry = jeab_contacts_drop_customer_fields([], $store);
cc(($dry['ok'] ?? false) === true && ($dry['written'] ?? true) === false && (int) ($dry['tables']['inkorg_list']['unlinked_kept'] ?? 0) === 3, 'dry visar tre historiktrådar som behålls');
$run = jeab_contacts_drop_customer_fields(['dry' => 0], $store);
jeab_copy_columns_forget();
cc(($run['ok'] ?? false) === true, 'DROP går igenom');
cc(!jeab_copy_column_present($pdo, 'inkorg_list', 'customer_email') && jeab_solkampanj_has_column($pdo, 'mejl') && jeab_solkampanj_has_column($pdo, 'namn') && jeab_solkampanj_has_column($pdo, 'gata'), 'inkorgens kopia är borta, solkampanjens kolumner står kvar');
cc(jeab_solkampanj_has_column($pdo, 'postnr') && jeab_solkampanj_has_column($pdo, 'ort'), 'postnr och ort ligger kvar');
cc((string) $pdo->query('SELECT namn FROM solkampanj_recipients WHERE id = ' . $annRow)->fetchColumn() === 'Ann Solberg'
    && (string) $pdo->query('SELECT mejl FROM solkampanj_recipients WHERE id = ' . $oveRow)->fetchColumn() === 'ove.okand@example.test'
    && (string) $pdo->query('SELECT gata FROM solkampanj_recipients WHERE id = ' . $annRow)->fetchColumn() === 'Solgatan 1', 'solkampanj-värdena är orörda');
cc(!isset(t_indexes($pdo, 'inkorg_list')['inkorg_list_customer']) && (t_indexes($pdo, 'solkampanj_recipients')['solk_gata_unique'] ?? false) === true, 'inkorgens index togs bort, solkampanjens unika index ligger kvar');
$h1 = json_decode((string) $pdo->query("SELECT row_json FROM inkorg_list WHERE id = 't-h1'")->fetchColumn(), true);
cc(($h1['customer_email'] ?? '') === 'gammal.kund@example.test', 'historiktrådens e-post ligger i row_json');
$h3 = json_decode((string) $pdo->query("SELECT row_json FROM inkorg_list WHERE id = 't-h3'")->fetchColumn(), true);
cc(($h3['customer_email'] ?? '') === 'tom.json@example.test', 'tom row_json behåller e-posten');

$list = (new JeabPdoInkorgStore($pdo))->listForCompany('jarfalla');
$byId = [];
foreach ($list as $row) {
    $byId[(string) ($row['id'] ?? '')] = $row;
}
cc(count($list) === 4 && ($byId['t-h1']['customer_email'] ?? '') === 'gammal.kund@example.test', 'inkorgen läser utan kolumnen och visar historiken');

$fetched = jeab_solkampanj_fetch($pdo, $annRow);
cc(is_array($fetched) && ($fetched['mejl'] ?? '') === 'ann.solberg@example.test' && ($fetched['namn'] ?? '') === 'Ann Solberg' && ($fetched['first_name'] ?? '') === 'Ann', 'solkampanj läser sina egna kolumner');

$csv2 = $csv . "903;Pia Ny;Takvägen 3;177 33;Järfälla;pia.ny@example.test;0\n";
$imp2 = jeab_solkampanj_import_csv($csv2);
cc(($imp2['unlinked'] ?? -1) === 0 && ($imp2['updated'] ?? 0) === 2 && ($imp2['imported'] ?? 0) === 1, 'import efter DROP sparar även olänkade rader');
cc(($imp2['unlinked_rows'] ?? null) === [], 'importen hoppar inte över några rader');
$rep = (string) json_encode($imp2);
cc(!str_contains($rep, 'ove') && !str_contains($rep, 'pia') && !str_contains($rep, 'Okand'), 'rapporten har inga kunddata');
cc((int) $pdo->query('SELECT COUNT(*) FROM solkampanj_recipients')->fetchColumn() === 3, 'olänkad rad sparas, listan ligger utanför drop');

t_drop_undo_key($dropPair);
$undo = jeab_contacts_drop_customer_fields_undo(['dry' => 0, 'batch_id' => (string) $run['batch_id']], $store);
jeab_copy_columns_forget();
cc(($undo['ok'] ?? false) === true && jeab_copy_column_present($pdo, 'inkorg_list', 'customer_email') && jeab_solkampanj_has_column($pdo, 'mejl'), 'undo lägger tillbaka inkorgens kolumn, solkampanj var kvar');
cc((string) $pdo->query("SELECT customer_email FROM inkorg_list WHERE id = 't-h1'")->fetchColumn() === 'gammal.kund@example.test'
    && (string) $pdo->query('SELECT mejl FROM solkampanj_recipients WHERE id = ' . $annRow)->fetchColumn() === 'ann.solberg@example.test', 'undo lägger tillbaka inkorgens värde, solkampanj är orörd');
$h1 = json_decode((string) $pdo->query("SELECT row_json FROM inkorg_list WHERE id = 't-h1'")->fetchColumn(), true);
$h2 = json_decode((string) $pdo->query("SELECT row_json FROM inkorg_list WHERE id = 't-h2'")->fetchColumn(), true);
cc(!array_key_exists('customer_email', (array) $h1) && ($h2['customer_email'] ?? '') === 'annan.kund@example.test', 'undo tar bara bort row_json-kopian som körningen lade dit');
cc(isset(t_indexes($pdo, 'inkorg_list')['inkorg_list_customer']) && (t_indexes($pdo, 'solkampanj_recipients')['solk_gata_unique'] ?? false) === true, 'undo lägger tillbaka inkorgens index, solkampanjens unika index var kvar');
$raw = (string) file_get_contents(jeab_contacts_drop_dir() . '/' . $run['batch_id'] . '.json');
cc(!str_contains($raw, 'gammal.kund') && !str_contains($raw, 'ann.solberg'), 'backupen har inga klartextadresser');

echo "ALL OK\n";
