<?php

declare(strict_types=1);

/**
 * Webshopindex och inkorgskontaktens lås. SQL är källan.
 * JSON skrivs bara när rollback-flaggan tvingar filen. Påhittade rader.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-webshop-sole-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND');
unset($GLOBALS['_JEAB_DATA_BACKEND']);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
$errLog = $tmp . '/php-error.log';
ini_set('error_log', $errLog);
require $root . '/src/bootstrap.php';

function sole_ws(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function sole_ws_hash(string $path): string
{
    return is_file($path) ? hash('sha256', (string) file_get_contents($path)) : '';
}

$shopPath = jeab_webshop_index_path();
$decoy = ['jarfalla:900' => ['reminder_id' => 900, 'name' => 'Bara fil']];
file_put_contents($shopPath, json_encode($decoy, JSON_UNESCAPED_UNICODE));
$before = sole_ws_hash($shopPath);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_LOG_PDO'] = $pdo;
jeab_log_pdo();

$threw = '';
try {
    jeab_webshop_index();
} catch (RuntimeException $e) {
    $threw = $e->getMessage();
}
sole_ws($threw === 'SQL saknas', 'tom SQL utan flagga läser inte JSON');
sole_ws(sole_ws_hash($shopPath) === $before, 'misslyckad läsning lämnar webshop-orders.json');
$log = (string) @file_get_contents($tmp . '/app-errors.jsonl');
sole_ws(str_contains($log, 'SQL saknas') && str_contains($log, 'webshop'), 'fellogg får SQL saknas');

jeab_log_flag_set($pdo, 'webshop_orders');
$empty = jeab_webshop_index();
sole_ws($empty === [], 'flaggad tom SQL är tom');
sole_ws(sole_ws_hash($shopPath) === $before, 'tom SQL lämnar filen');

jeab_webshop_index_save(['jarfalla:1' => ['reminder_id' => 7]]);
$saved = jeab_webshop_index();
sole_ws((int) ($saved['jarfalla:1']['reminder_id'] ?? 0) === 7 && !isset($saved['jarfalla:900']), 'indexet läses från SQL');
sole_ws(sole_ws_hash($shopPath) === $before, 'SQL-skrivning rör inte filen');

$pdo->exec('DROP TABLE log_webshop_orders');
$threw = '';
try {
    jeab_webshop_index();
} catch (RuntimeException $e) {
    $threw = $e->getMessage();
}
sole_ws($threw === 'SQL saknas', 'trasig SQL-läsning är ett hårt fel');
$threw = '';
try {
    jeab_webshop_index_save(['jarfalla:2' => ['reminder_id' => 8]]);
} catch (RuntimeException $e) {
    $threw = $e->getMessage();
}
sole_ws($threw === 'SQL saknas', 'trasig SQL-skrivning är ett hårt fel');
sole_ws(sole_ws_hash($shopPath) === $before, 'misslyckad skrivning skapar inte JSON');
@unlink($shopPath);
$threw = '';
try {
    jeab_webshop_index_save(['jarfalla:3' => ['reminder_id' => 3]]);
} catch (RuntimeException $e) {
    $threw = $e->getMessage();
}
sole_ws($threw === 'SQL saknas' && !is_file($shopPath), 'saknad fil skapas inte när SQL fallerar');

$GLOBALS['_JEAB_DATA_BACKEND'] = 'json';
sole_ws(jeab_webshop_json_only(), 'json-backend är rollback');
jeab_webshop_index_save(['jarfalla:9' => ['reminder_id' => 9]]);
$rolled = jeab_webshop_index();
sole_ws((int) ($rolled['jarfalla:9']['reminder_id'] ?? 0) === 9 && is_file($shopPath), 'rollback skriver och läser JSON');
unset($GLOBALS['_JEAB_DATA_BACKEND']);

$storeSrc = (string) file_get_contents($root . '/src/kontakt_store.php');
$inboxSrc = substr($storeSrc, (int) strpos($storeSrc, 'class JeabPdoInboxContactStore'));
$lockedAt = strpos($inboxSrc, 'public function locked');
$replaceAt = strpos($inboxSrc, 'public function replaceAll');
$lockedSrc = $lockedAt !== false && $replaceAt !== false ? substr($inboxSrc, $lockedAt, $replaceAt - $lockedAt) : '';
sole_ws($lockedSrc !== '' && !str_contains($lockedSrc, 'jeab_inkorg_contacts_json_map'), 'locked läser inte JSON-kartan');

$inboxPath = jeab_inkorg_dir() . '/contacts.json';
file_put_contents($inboxPath, json_encode(['contacts' => [
    'c-keep-file' => ['id' => 'c-keep-file', 'name' => 'Bara fil', 'emails' => ['fil@example.test']],
]], JSON_UNESCAPED_UNICODE));
sole_ws(jeab_kontakter_storage_mode() === 'json', 'kontakter börjar i JSON-läge');
jeab_kontakt_inbox_locked(static function (?array $d): array {
    $d = is_array($d) ? $d : ['contacts' => []];
    $d['contacts']['c-rollback'] = ['id' => 'c-rollback', 'name' => 'Rollback', 'emails' => ['rollback@example.test']];

    return ['c-rollback', $d];
});
$rolledContacts = json_decode((string) file_get_contents($inboxPath), true);
sole_ws(is_array($rolledContacts) && isset($rolledContacts['contacts']['c-rollback']), 'JSON-läge skriver contacts.json');

$app = jeab_contact_store_pdo();
sole_ws($app instanceof PDO, 'appens sqlite är öppen');
sole_ws(jeab_settings_put($app, 'sqlite', 'kontakter_storage', 'sql'), 'sql-flaggan går att sätta');
sole_ws(jeab_kontakter_storage_mode() === 'sql', 'kontakter ligger i SQL');
file_put_contents($inboxPath, json_encode(['contacts' => [
    'c-json-only' => ['id' => 'c-json-only', 'name' => 'Bara JSON', 'emails' => ['json@example.test']],
    'c-rollback' => ['id' => 'c-rollback', 'name' => 'Rollback', 'emails' => ['rollback@example.test']],
]], JSON_UNESCAPED_UNICODE));
$inboxBefore = sole_ws_hash($inboxPath);
$made = jeab_kontakt_inbox_locked(static function (?array $d): array {
    $d = is_array($d) ? $d : ['contacts' => []];
    $contacts = is_array($d['contacts'] ?? null) ? $d['contacts'] : [];
    $contacts['c-sql-new'] = ['id' => 'c-sql-new', 'name' => 'Ny SQL', 'emails' => ['ny@example.test']];
    $d['contacts'] = $contacts;

    return ['c-sql-new', $d];
});
$inboxStore = new JeabPdoInboxContactStore($app);
$ids = array_keys($inboxStore->sqlAll());
sort($ids);
sole_ws($made === 'c-sql-new' && $ids === ['c-sql-new'], 'tom SQL förblir utan JSON-raderna');
sole_ws(sole_ws_hash($inboxPath) === $inboxBefore, 'SQL-låset skriver inte contacts.json');
sole_ws(str_contains((string) @file_get_contents($errLog), 'SQL är tom, JSON läses inte.'), 'tom SQL loggas');

$inboxStore->upsert('c-keep', ['id' => 'c-keep', 'name' => 'Kvar', 'emails' => ['kvar@example.test']]);
file_put_contents($inboxPath, json_encode(['contacts' => [
    'c-json-only' => ['id' => 'c-json-only', 'name' => 'Bara JSON', 'emails' => ['json@example.test']],
    'c-extra' => ['id' => 'c-extra', 'name' => 'Extra', 'emails' => ['extra@example.test']],
]], JSON_UNESCAPED_UNICODE));
$inboxBefore = sole_ws_hash($inboxPath);
jeab_kontakt_inbox_locked(static function (?array $d): array {
    $d = is_array($d) ? $d : ['contacts' => []];
    $contacts = is_array($d['contacts'] ?? null) ? $d['contacts'] : [];
    if (isset($contacts['c-keep']) && is_array($contacts['c-keep'])) {
        $contacts['c-keep']['name'] = 'Ändrad';
    }
    $d['contacts'] = $contacts;

    return [null, $d];
});
$after = $inboxStore->sqlAll();
sole_ws((string) ($after['c-keep']['name'] ?? '') === 'Ändrad' && !isset($after['c-extra'], $after['c-json-only']), 'ifyllt SQL tar inte in JSON');
sole_ws(sole_ws_hash($inboxPath) === $inboxBefore, 'ändringen lämnar JSON-filen');

echo "OK  sql är enda källan för webshop och inkorgslåset\n";
