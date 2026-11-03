<?php

declare(strict_types=1);

/**
 * contacts.drop_customer_fields med publik nyckel: saknad publik nyckel ger 409, backupen går
 * inte att läsa utan privat nyckel, undo med rätt och fel nyckel, den tillfälliga nyckelfilen
 * raderas, och varken svar eller logg har hemligheter. Genererade testpar i tmp.
 */
if (!in_array('sqlite', PDO::getAvailableDrivers(), true) && !getenv('JEAB_TEST_MYSQL_PORT')) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-drop-pub-' . bin2hex(random_bytes(4));
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
$pdo->prepare('INSERT INTO inkorg_list (id, company, customer_email, list_at, list_status, contact_id, row_json) VALUES (?, ?, ?, ?, ?, ?, ?)')
    ->execute(['t-p1', 'jarfalla', 'ann.solberg@example.test', '2026-01-03', 'closed', $annId, json_encode(['id' => 't-p1', 'subject' => 'Länkad'])]);
$other = t_drop_keypair($tmp . '/keys', 'b');
$secrets = static function (array $pair): array {
    $lines = array_values(array_filter(explode("\n", $pair['pem']), static fn (string $l): bool => $l !== '' && !str_starts_with($l, '-----')));

    return [$lines[0], $lines[intdiv(count($lines), 2)], $lines[count($lines) - 1], 'PRIVATE KEY'];
};
$hasSecret = static function (string $text) use ($secrets, $dropPair, $other): bool {
    foreach (array_merge($secrets($dropPair), $secrets($other)) as $needle) {
        if (str_contains($text, $needle)) {
            return true;
        }
    }

    return false;
};
$pubSha = static fn (array $pair): string => hash('sha256', (string) base64_decode((string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pair['pub_pem'])));

// Saknad publik nyckel: 409 vid dry=0, dry går, inget tas bort och ingen nyckel skapas.
$GLOBALS['_JEAB_DROP_PUBKEY_PATH'] = $tmp . '/keys/finns-inte.pub';
$dry = jeab_contacts_drop_customer_fields([], $store);
$missing = jeab_contacts_drop_customer_fields(['dry' => 0], $store);
jeab_copy_columns_forget();
cc(($dry['ok'] ?? false) === true && ($missing['status'] ?? 0) === 409 && ($missing['error'] ?? '') === 'backup_key_missing', 'saknad publik nyckel ger 409 backup_key_missing');
cc(jeab_copy_column_present($pdo, 'inkorg_list', 'customer_email') && glob(jeab_contacts_drop_dir() . '/*.json') === [] && !is_file(jeab_contacts_drop_key_path()), '409 tar inte bort något och skapar ingen nyckel');
// En "publik" fil som är en privat nyckel godtas inte.
$GLOBALS['_JEAB_DROP_PUBKEY_PATH'] = $dropPair['priv'];
cc((jeab_contacts_drop_customer_fields(['dry' => 0], $store)['error'] ?? '') === 'backup_key_missing', 'en privat nyckel som publik fil godtas inte');
t_drop_use_pub($dropPair);

$run = jeab_contacts_drop_customer_fields(['dry' => 0], $store);
jeab_copy_columns_forget();
$batch = (string) ($run['batch_id'] ?? '');
cc(($run['ok'] ?? false) === true && !jeab_copy_column_present($pdo, 'inkorg_list', 'customer_email'), 'DROP med publik nyckel går igenom');
cc(($run['pub_sha256'] ?? '') === $pubSha($dropPair) && !is_file(jeab_contacts_drop_key_path()) && glob(dirname(jeab_contacts_drop_dir()) . '/*.key') === [], 'svaret har publika nyckelns sha256 och ingen nyckel finns på servern');
$data = jeab_contacts_drop_read_backup($batch);
cc(($data['seal'] ?? '') === 'v3' && ($data['pub_sha256'] ?? '') === $pubSha($dropPair) && strlen((string) base64_decode((string) ($data['wrapped_key'] ?? ''))) === 256, 'backupen har fingeravtryck och inslagen nyckel');
cc(jeab_contacts_drop_batch_key((array) $data, null) === '', 'backupen går inte att dekryptera utan privat nyckel');
$token = (string) ($data['tables']['inkorg_list']['rows'][0]['fields']['customer_email']['sealed'] ?? '');
cc($token !== '' && jeab_contacts_drop_open($token, random_bytes(32)) === null, 'värdet går inte att öppna med en annan nyckel');

// Undo utan nyckelfil: 409, inget skrivs.
$noKey = jeab_contacts_drop_customer_fields_undo(['dry' => 0, 'batch_id' => $batch], $store);
jeab_copy_columns_forget();
cc(($noKey['status'] ?? 0) === 409 && ($noKey['error'] ?? '') === 'undo_key_missing' && !jeab_copy_column_present($pdo, 'inkorg_list', 'customer_email'), 'undo utan privat nyckel ger 409 och skriver inget');

// Fel nyckel: 409 och filen raderas.
$path = t_drop_undo_key($other);
$wrong = jeab_contacts_drop_customer_fields_undo(['dry' => 0, 'batch_id' => $batch], $store);
jeab_copy_columns_forget();
cc(($wrong['status'] ?? 0) === 409 && ($wrong['error'] ?? '') === 'undo_key_mismatch' && !jeab_copy_column_present($pdo, 'inkorg_list', 'customer_email'), 'fel privat nyckel ger 409 och skriver inget');
cc(!is_file($path), 'nyckelfilen raderas efter fel nyckel');

// För öppen fil (0644) godtas inte och raderas.
$path = t_drop_undo_key($dropPair, 0644);
$open = jeab_contacts_drop_customer_fields_undo(['dry' => 0, 'batch_id' => $batch], $store);
cc(($open['error'] ?? '') === 'undo_key_missing' && !is_file($path), 'nyckelfil som inte är 0600 godtas inte och raderas');

// Ogiltigt batch_id: filen raderas ändå.
$path = t_drop_undo_key($dropPair);
jeab_contacts_drop_customer_fields_undo(['dry' => 0, 'batch_id' => 'nej'], $store);
cc(!is_file($path), 'nyckelfilen raderas även när undo stoppar tidigt');

// Rätt nyckel: undo går igenom och filen raderas.
$path = t_drop_undo_key($dropPair);
$undo = jeab_contacts_drop_customer_fields_undo(['dry' => 0, 'batch_id' => $batch], $store);
jeab_copy_columns_forget();
cc(($undo['ok'] ?? false) === true && jeab_copy_column_present($pdo, 'inkorg_list', 'customer_email') && (string) $pdo->query("SELECT customer_email FROM inkorg_list WHERE id = 't-p1'")->fetchColumn() === 'ann.solberg@example.test', 'undo med rätt nyckel lägger tillbaka värdet');
cc(!is_file($path) && ($undo['pub_sha256'] ?? '') === $pubSha($dropPair), 'nyckelfilen raderas och svaret har bara publika nyckelns sha256');

// Äldre symmetriska backuper (v2) läses fortfarande, men nyckeln skapas aldrig.
cc(jeab_contacts_drop_batch_key(['seal' => 'v2'], null) === '', 'utan gammal nyckelfil finns ingen v2-nyckel');
$legacyHex = bin2hex(random_bytes(32));
file_put_contents(jeab_contacts_drop_key_path(), $legacyHex);
chmod(jeab_contacts_drop_key_path(), 0600);
$legacy = jeab_contacts_drop_batch_key(['seal' => 'v2'], null);
cc(strlen($legacy) === 32 && jeab_contacts_drop_open((string) jeab_contacts_drop_seal('x@example.test', $legacy), $legacy) === 'x@example.test', 'gammal v2-nyckel läses för gamla backuper');
$run2 = jeab_contacts_drop_customer_fields(['dry' => 0], $store);
cc((jeab_contacts_drop_read_backup((string) ($run2['batch_id'] ?? ''))['seal'] ?? '') === 'v3' && (string) file_get_contents(jeab_contacts_drop_key_path()) === $legacyHex, 'nya backuper är v3 även när den gamla nyckeln finns');

// Inga hemligheter i svar eller logg.
$all = (string) json_encode([$dry, $missing, $run, $noKey, $wrong, $open, $undo, $run2]);
$log = is_file($errLog) ? (string) file_get_contents($errLog) : '';
cc(!$hasSecret($all) && !$hasSecret($log) && !str_contains($all, (string) ($data['wrapped_key'] ?? 'x')) && !str_contains($all, 'ann.solberg'), 'svar och logg har ingen privat nyckel, inslagen nyckel eller kunddata');
$raw = (string) file_get_contents(jeab_contacts_drop_dir() . '/' . $batch . '.json');
cc(!$hasSecret($raw) && !str_contains($raw, 'ann.solberg'), 'backupen har ingen privat nyckel och ingen klartext');

echo "ALL OK\n";
