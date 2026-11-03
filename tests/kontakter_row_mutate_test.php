<?php

declare(strict_types=1);

/**
 * jeab_kontakter_mutate skriver bara ändrade id.
 * Tom lista raderar inget. Radering större än 5 % eller 20 rader vägras.
 * SQL-läget skriver inte kontakter.json.
 * Påhittad data. Inget skickas.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-kontakter-row-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$log = $tmp . '/error.log';
ini_set('error_log', $log);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
$jsonPath = $tmp . '/kontakter.json';
file_put_contents($jsonPath, json_encode(['contacts' => [
    'kt_json_only' => ['id' => 'kt_json_only', 'city' => 'Jsonstad', 'name' => 'Bara Json'],
]], JSON_UNESCAPED_UNICODE));
require dirname(__DIR__) . '/src/bootstrap.php';

function kr(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function kr_log(): string
{
    $path = ini_get('error_log');
    return is_string($path) && is_file($path) ? (string) file_get_contents($path) : '';
}

$pdo = jeab_contact_store_pdo();
kr($pdo instanceof PDO, 'sqlite är öppen');
kr(jeab_settings_put($pdo, 'sqlite', 'kontakter_storage', 'sql'), 'sql-flaggan går att sätta');
kr(jeab_kontakter_storage_mode() === 'sql', 'kontakter ligger i SQL');

$jsonMtime = filemtime($jsonPath);
jeab_kontakter_mutate(static function (array &$contacts): void {
    $contacts['kt_new'] = ['id' => 'kt_new', 'city' => 'Nystad', 'name' => 'Ny'];
});
kr(filemtime($jsonPath) === $jsonMtime, 'mutate skriver inte kontakter.json');
kr(!isset((new JeabPdoContactStore($pdo))->sqlAll()['kt_json_only']), 'JSON-filen importeras inte vid skrivning');
kr(isset((new JeabPdoContactStore($pdo))->sqlAll()['kt_new']), 'den nya raden finns i SQL');

$store = new JeabPdoContactStore($pdo);
for ($i = 1; $i <= 21; $i++) {
    $id = sprintf('kt_%02d', $i);
    $store->upsert($id, ['id' => $id, 'city' => 'Stad', 'name' => 'Namn ' . $i]);
}
kr($store->count() === 22, 'tjugotvå kontakter finns');

$raw = [];
foreach ($pdo->query('SELECT id, row_json FROM contacts') as $dbRow) {
    $raw[(string) $dbRow['id']] = (string) $dbRow['row_json'];
}
jeab_kontakter_mutate(static function (array &$contacts): void {
    $contacts = [];
});
kr($store->count() === 22, 'tom lista raderar inga kontakter');
kr(str_contains(kr_log(), 'tom lista'), 'tom lista loggas');

jeab_kontakter_mutate(static function (array &$contacts): void {
    unset($contacts['kt_01'], $contacts['kt_02']);
});
kr($store->count() === 22, 'två rader är mer än 5 procent och raderas inte');
kr(str_contains(kr_log(), 'vägras'), 'den vägrade raderingen loggas');

jeab_kontakter_mutate(static function (array &$contacts): void {
    unset($contacts['kt_21']);
    $contacts['kt_01']['city'] = 'Ändrad';
});
kr($store->count() === 21 && !isset($store->sqlAll()['kt_21']), 'en rad under spärren raderas');
$after = [];
foreach ($pdo->query('SELECT id, row_json FROM contacts') as $dbRow) {
    $after[(string) $dbRow['id']] = (string) $dbRow['row_json'];
}
$same = 0;
foreach ($raw as $id => $json) {
    if ($id === 'kt_21' || $id === 'kt_01') {
        continue;
    }
    if (($after[$id] ?? '') === $json) {
        $same++;
    }
}
kr($same === 20, 'övriga kontakter skrivs inte om');
$changed = json_decode((string) ($after['kt_01'] ?? ''), true);
kr(is_array($changed) && (string) ($changed['city'] ?? '') === 'Ändrad', 'den ändrade kontakten är sparad');
$storeSrc = (string) file_get_contents(dirname(__DIR__) . '/src/kontakt_store.php');
$registryStart = (int) strpos($storeSrc, 'class JeabPdoContactStore');
$inboxStart = (int) strpos($storeSrc, 'class JeabPdoInboxContactStore');
$registrySrc = substr($storeSrc, $registryStart, $inboxStart - $registryStart);
kr($registrySrc !== '' && !str_contains($registrySrc, 'DELETE FROM contacts WHERE id NOT IN') && !str_contains($registrySrc, 'contact_id NOT IN'), 'kontaktregistret har ingen DELETE NOT IN');

echo "ok\n";
