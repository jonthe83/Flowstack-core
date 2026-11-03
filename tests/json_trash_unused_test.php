<?php

declare(strict_types=1);

require_once __DIR__ . '/jeab_test_proc_env.php';

/**
 * json.inventory listar varje .json och klassar den. json.trash_unused
 * flyttar bara inactive. dry saknas betyder dry=1. Okända filer flyttas
 * inte. Svaret innehåller aldrig filinnehåll.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-json-trash-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$root = dirname(__DIR__);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp . '/public';
mkdir($tmp . '/public', 0775, true);
putenv('JEAB_DISABLE_SQLITE');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");

require $root . '/src/bootstrap.php';
require_once $root . '/src/json_trash.php';

function jtt(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$src = (string) file_get_contents($root . '/src/json_trash.php');
jtt(preg_match('/\bunlink\s*\(/', $src) !== 1, 'json_trash.php anropar inte unlink');
jtt(!str_contains($src, 'jeab_data_file('), 'json_trash.php anropar inte jeab_data_file');
jtt(!str_contains($src, 'file_get_contents'), 'json_trash.php läser inte filinnehåll');
jtt(!str_contains($src, 'jeab_json_trash_audit'), 'den gamla auditen är borta');

foreach (jeab_json_registry() as $row) {
    $words = preg_split('/\s+/', trim((string) $row['reason'])) ?: [];
    jtt(count($words) >= 1 && count($words) <= 4, 'motivering max 4 ord: ' . $row['rel']);
    jtt(in_array($row['class'], ['active', 'inactive'], true), 'registret har klass för ' . $row['rel']);
}
jtt((jeab_json_classify('passwords.json')['class'] ?? '') === 'active', 'lösenord är aktiva');
jtt((jeab_json_classify('store.json')['class'] ?? '') === 'active', 'store.json är aktiv');
jtt((jeab_json_classify('inkorg/graph-daily.json')['class'] ?? '') === 'active', 'graph-daily är aktiv');
jtt((jeab_json_classify('ordrar-deleted.json')['class'] ?? '') === 'inactive', 'ordrar-deleted är inactive');
jtt((jeab_json_classify('inkorg/messages.json')['class'] ?? '') === 'inactive', 'messages är inactive');
jtt((jeab_json_classify('inkorg/hs-import/c/a.json')['class'] ?? '') === 'inactive', 'hs-import-prefix är inactive');
jtt((jeab_json_classify('notes/orphan.json')['class'] ?? '') === 'unknown', 'okänd fil är unknown');
jtt((jeab_json_classify('secrets/solkoll.json')['class'] ?? '') === 'active', 'secrets-prefix är aktiv');
jtt((jeab_json_classify('fortnox/tokens.json')['reason'] ?? '') === 'token vid Fortnox', 'Fortnox-token har kort motivering');

$hook = (string) file_get_contents($root . '/hook.php');
$inventoryAt = strpos($hook, "=== 'json.inventory'");
$trashAt = strpos($hook, "=== 'json.trash_unused'");
$incomingAt = strpos($hook, '$token = jeab_ensure_incoming_token($store)');
jtt($inventoryAt !== false && $trashAt !== false && $incomingAt !== false && $inventoryAt < $incomingAt && $trashAt < $incomingAt, 'båda krokarna ligger före inkommande bokningstoken');

$secret = 'HEMLIGT-INNEHALL-XYZ';
file_put_contents($tmp . '/passwords.json', '{"password":"' . $secret . "\"}\n");
file_put_contents($tmp . '/ordrar-deleted.json', "{\"ids\":{}}\n");
file_put_contents($tmp . '/public/ordrar-deleted.json', "{\"public\":true}\n");
file_put_contents($tmp . '/public/store.json', "{\"bookings\":[]}\n");
file_put_contents($tmp . '/decoy.json', "{\"no\":1}\n");
mkdir($tmp . '/inkorg', 0775, true);
file_put_contents($tmp . '/inkorg/graph-daily.json', "{\"date\":\"\"}\n");
file_put_contents($tmp . '/inkorg/messages.json', "{\"items\":[]}\n");
mkdir($tmp . '/fortnox', 0775, true);
file_put_contents($tmp . '/fortnox/tokens.json', '{"access":"' . $secret . "\"}\n");
file_put_contents($tmp . '/shield.php', "<?php\n");
mkdir($tmp . '/rot', 0775, true);
file_put_contents($tmp . '/rot/x.json', "{}\n");
file_put_contents($tmp . '/bokningstider.json', "{\"slots\":[]}\n");
file_put_contents($tmp . '/quote-queue.json', '{"pad":"' . str_repeat('Q', 110000) . "\"}\n");
mkdir($tmp . '/trash/2026-10-30', 0775, true);
file_put_contents($tmp . '/trash/2026-10-30/hidden.json', "{}\n");
mkdir($tmp . '/backups', 0775, true);
file_put_contents($tmp . '/backups/old.json', "{}\n");
mkdir($tmp . '/notes', 0775, true);
file_put_contents($tmp . '/notes/orphan.json', "{\"kept\":true}\n");

$rels = jeab_json_trash_unused_rels();
jtt(in_array('ordrar-deleted.json', $rels, true), 'listan innehåller ordrar-deleted');
jtt(in_array('inkorg/messages.json', $rels, true), 'listan innehåller messages');
jtt(!in_array('decoy.json', $rels, true), 'okänd decoy är inte med');
jtt(!in_array('passwords.json', $rels, true), 'lösenord är inte med');
jtt(!in_array('inkorg/graph-daily.json', $rels, true), 'graph-daily är inte med');
jtt(!in_array('quote-queue.json', $rels, true), 'stor aktiv kö är inte med');
jtt(!in_array('notes/orphan.json', $rels, true), 'okänd orphan är inte med');

$dry = jeab_json_trash_apply(['notes/orphan.json', 'shield.php', 'rot/x.json', 'store.json', 'passwords.json', 'fortnox/tokens.json'], true, '2026-10-30');
jtt(($dry['dry'] ?? 0) === 1 && ($dry['moved'] ?? null) === [], 'dry flyttar inget');
$refusedWhy = [];
foreach ($dry['refused'] ?? [] as $row) {
    $refusedWhy[(string) ($row['rel'] ?? '')] = (string) ($row['why'] ?? '');
}
jtt(($refusedWhy['notes/orphan.json'] ?? '') === 'inte inactive', 'okänd fil nekas');
jtt(($refusedWhy['shield.php'] ?? '') === 'shield.php', 'shield.php nekas');
jtt(($refusedWhy['rot/x.json'] ?? '') === 'rot', 'rot nekas');
jtt(($refusedWhy['store.json'] ?? '') === 'bokningar', 'store.json nekas');
jtt(($refusedWhy['passwords.json'] ?? '') === 'lösenord', 'lösenord nekas');
jtt(($refusedWhy['fortnox/tokens.json'] ?? '') === 'token', 'token-fil nekas');

$wet = jeab_json_trash_apply(['ordrar-deleted.json'], false, '2026-10-30');
$dest = $tmp . '/trash/2026-10-30/ordrar-deleted.json';
$publicDest = $tmp . '/trash/2026-10-30/public/ordrar-deleted.json';
jtt(($wet['ok'] ?? false) === true && is_file($dest) && !is_file($tmp . '/ordrar-deleted.json'), 'wet döper om den inaktiva filen');
jtt(is_file($publicDest) && !is_file($tmp . '/public/ordrar-deleted.json'), 'publik kopia följer med under public/');
jtt((string) file_get_contents($dest) === "{\"ids\":{}}\n", 'bytets byte är desamma');
$manifest = (string) ($wet['manifest'] ?? '');
$doc = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;
jtt(is_array($doc) && ($doc['keep_until'] ?? '') === '2026-11-13' && ($doc['dry'] ?? 1) === 0 && ($doc['action'] ?? '') === 'json.trash_unused', 'manifestet har kvar-till 13 nov');
jtt(!str_contains((string) file_get_contents($manifest), $secret), 'manifestet innehåller inte filinnehåll');
jtt(is_file($tmp . '/notes/orphan.json') && is_file($tmp . '/passwords.json') && is_file($tmp . '/fortnox/tokens.json'), 'okända, lösenord och tokens ligger kvar');

file_put_contents($tmp . '/ordrar-deleted.json', "{\"ids\":{}}\n");
file_put_contents($tmp . '/public/ordrar-deleted.json', "{\"public\":true}\n");

$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir) && !mkdir($tokenDir, 0775, true) && !is_dir($tokenDir)) {
    fwrite(STDERR, "FAIL token dir\n");
    exit(1);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-json-trash']));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = jeab_json_trash_unused_dispatch(['dry' => 0, 'rels' => ['ordrar-deleted.json']]);
jtt(($denied['ok'] ?? true) === false && ($denied['status'] ?? 0) === 401 && ($denied['error'] ?? '') === 'Fel token.', 'utan token ger 401');
$deniedInv = jeab_json_inventory_dispatch([]);
jtt(($deniedInv['status'] ?? 0) === 401 && ($deniedInv['error'] ?? '') === 'Fel token.', 'inventering utan token ger 401');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-json-trash';
$listed = jeab_json_inventory_dispatch(['rels' => ['ordrar-deleted.json']]);
$encoded = json_encode($listed, JSON_UNESCAPED_UNICODE);
jtt(($listed['read_only'] ?? 0) === 1 && is_array($listed['files'] ?? null), 'inventeringen är skrivskyddad');
jtt(is_string($encoded) && !str_contains($encoded, $secret), 'inventeringen läcker inte filinnehåll');
$byRel = [];
foreach ($listed['files'] as $row) {
    jtt(!array_key_exists('content', $row) && !array_key_exists('body', $row), 'raden saknar innehållsfält');
    $byRel[(string) $row['root'] . ':' . (string) $row['rel']] = $row;
}
jtt(($byRel['private:ordrar-deleted.json']['class'] ?? '') === 'inactive', 'inventeringen klassar ordrar-deleted');
jtt(($byRel['private:decoy.json']['class'] ?? '') === 'unknown', 'inventeringen klassar decoy som okänd');
jtt(($byRel['private:passwords.json']['class'] ?? '') === 'active', 'inventeringen behåller lösenord');
jtt(($byRel['private:quote-queue.json']['large'] ?? false) === true && ($byRel['private:quote-queue.json']['bytes'] ?? 0) > 102400, 'stor aktiv fil flaggas');
jtt(!isset($byRel['private:trash/2026-10-30/hidden.json']) && !isset($byRel['private:backups/old.json']), 'papperskorg och backups hoppas över');
jtt(is_file($tmp . '/ordrar-deleted.json') && is_file($tmp . '/decoy.json'), 'inventeringen flyttar inget');

$hookDry = jeab_json_trash_unused_dispatch(['rels' => ['decoy.json', 'ordrar-deleted.json', 'passwords.json']]);
jtt(($hookDry['dry'] ?? 0) === 1 && is_file($tmp . '/decoy.json') && is_file($tmp . '/ordrar-deleted.json') && is_file($tmp . '/passwords.json'), 'utelämnad dry flyttar inte och ignorerar klientens sökvägar');

$hookWet = jeab_json_trash_unused_dispatch(['dry' => 0, 'paths' => ['decoy.json', 'passwords.json', 'inkorg/graph-daily.json']]);
$movedRels = array_map(static fn (array $row): string => (string) ($row['rel'] ?? ''), $hookWet['moved'] ?? []);
jtt(($hookWet['dry'] ?? 1) === 0 && in_array('ordrar-deleted.json', $movedRels, true), 'dry=0 flyttar inactive');
jtt(!is_file($tmp . '/ordrar-deleted.json') && is_file($tmp . '/decoy.json'), 'okänd decoy ligger kvar');
jtt(is_file($tmp . '/passwords.json') && is_file($tmp . '/fortnox/tokens.json') && is_file($tmp . '/inkorg/graph-daily.json'), 'lösenord, tokens och graph-daily ligger kvar');
jtt(is_file($tmp . '/public/store.json') && is_file($tmp . '/bokningstider.json') && is_file($tmp . '/shield.php') && is_file($tmp . '/rot/x.json'), 'bokningar, tider, shield och rot ligger kvar');
jtt(is_file($tmp . '/quote-queue.json'), 'stor aktiv kö ligger kvar');

try {
    $booted = jeab_store();
    jtt($booted instanceof JeabStore, 'sqlite-lagret går att öppna');
    if ($booted instanceof JeabPdoStore) {
        $booted->disconnect();
    }
} catch (Throwable $e) {
    jtt(false, 'sqlite-lagret: ' . $e->getMessage());
}
if (function_exists('jeab_pdo_release')) {
    jeab_pdo_release();
}

file_put_contents($tmp . '/purchases.json', "{\"rows\":[]}\n");
file_put_contents($tmp . '/bokningstider.json', "{\"slots\":[]}\n");
if (!is_file($tmp . '/passwords.json')) {
    file_put_contents($tmp . '/passwords.json', '{"password":"' . $secret . "\"}\n");
}

$router = $tmp . '/router.php';
file_put_contents($router, <<<'PHP'
<?php
$GLOBALS['_JEAB_DATA_DIR'] = getenv('JEAB_TEST_DATA');
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = getenv('JEAB_TEST_DATA') . '/public';
if (trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '')) === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        if (strcasecmp((string) $name, 'Authorization') === 0) {
            $_SERVER['HTTP_AUTHORIZATION'] = (string) $value;
        }
    }
}
require getenv('JEAB_ROOT') . '/hook.php';
PHP);
$port = 24000 + (getmypid() % 10000);
$serverLog = $tmp . '/server.log';
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
    [1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']],
    $srvPipes,
    $root,
    jeab_test_proc_env(['JEAB_TEST_TEMP_SQLITE' => '1', 'JEAB_TEST_DATA' => $tmp, 'JEAB_ROOT' => $root])
);
jtt(is_resource($server), 'php -S startade');

$curl = static function (array $payload, string $bearer) use ($port): array {
    $cmd = [
        'curl', '-sS', '-D', '-', '--max-time', '60',
        '-X', 'POST',
        '-H', 'Content-Type: application/json',
        '-H', 'Authorization: Bearer ' . $bearer,
        '--data-binary', json_encode($payload, JSON_UNESCAPED_UNICODE),
        'http://127.0.0.1:' . $port . '/hook.php',
    ];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return ['status' => 0, 'json' => []];
    }
    $raw = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $status = 0;
    if (preg_match('/^HTTP\/\S+\s+(\d+)/m', $raw, $m) === 1) {
        $status = (int) $m[1];
    }
    $split = strpos($raw, "\r\n\r\n");
    $body = $split === false ? $raw : substr($raw, $split + 4);
    $json = json_decode($body, true);

    return ['status' => $status, 'json' => is_array($json) ? $json : [], 'body' => $body];
};

$ready = false;
for ($i = 0; $i < 30; $i++) {
    $probe = $curl(['action' => 'json.inventory'], 'fel-token');
    if ($probe['status'] !== 0) {
        $ready = true;
        break;
    }
    usleep(150000);
}
jtt($ready, 'php -S svarar');

$unauth = $curl(['action' => 'json.trash_unused', 'dry' => 0], 'fel-token');
jtt($unauth['status'] === 401 && ($unauth['json']['error'] ?? '') === 'Fel token.', 'curl med fel token ger 401 på flytten');
$unauthInv = $curl(['action' => 'json.inventory'], 'fel-token');
jtt($unauthInv['status'] === 401 && ($unauthInv['json']['error'] ?? '') === 'Fel token.', 'curl med fel token ger 401 på inventeringen');

$beforePurchases = (string) file_get_contents($tmp . '/purchases.json');
$beforeTimes = (string) file_get_contents($tmp . '/bokningstider.json');
$inv = $curl(['action' => 'json.inventory', 'rels' => ['purchases.json']], 'tok-json-trash');
jtt($inv['status'] === 200 && ($inv['json']['read_only'] ?? 0) === 1, 'curl-inventering svarar 200');
jtt(!str_contains((string) ($inv['body'] ?? ''), $secret), 'curl-inventering läcker inte filinnehåll');
$sawPurchase = false;
$sawLarge = false;
foreach ($inv['json']['files'] ?? [] as $row) {
    if (($row['rel'] ?? '') === 'purchases.json' && ($row['class'] ?? '') === 'inactive') {
        $sawPurchase = true;
    }
    if (($row['rel'] ?? '') === 'quote-queue.json' && !empty($row['large'])) {
        $sawLarge = true;
    }
}
jtt($sawPurchase && $sawLarge, 'curl-inventering visar inactive och stor aktiv fil');
jtt((string) file_get_contents($tmp . '/purchases.json') === $beforePurchases, 'curl-inventering lämnar purchases');

$omitted = $curl(['action' => 'json.trash_unused', 'rels' => ['purchases.json', 'decoy.json', 'passwords.json']], 'tok-json-trash');
jtt($omitted['status'] === 200 && ($omitted['json']['dry'] ?? 0) === 1 && ($omitted['json']['moved'] ?? null) === [], 'curl utan dry är dry=1');
jtt((string) file_get_contents($tmp . '/purchases.json') === $beforePurchases, 'curl dry lämnar purchases');
jtt((string) file_get_contents($tmp . '/bokningstider.json') === $beforeTimes, 'curl dry lämnar bokningstider');
jtt(is_file($tmp . '/decoy.json') && is_file($tmp . '/passwords.json') && is_file($tmp . '/shield.php') && is_file($tmp . '/rot/x.json'), 'curl dry lämnar decoy, lösenord, shield och rot');

if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}

$alias = sys_get_temp_dir() . '/jeab-json-alias-' . bin2hex(random_bytes(3));
mkdir($alias . '/real', 0775, true);
file_put_contents($alias . '/real/ordrar-deleted.json', "{\"ids\":{}}\n");
file_put_contents($alias . '/real/decoy.json', "{}\n");
symlink($alias . '/real', $alias . '/via-priv');
symlink($alias . '/real', $alias . '/via-pub');
mkdir($alias . '/nest/.private', 0775, true);
file_put_contents($alias . '/nest/.private/purchases.json', "{\"rows\":[]}\n");
file_put_contents($alias . '/nest/only-public.json', "{\"pub\":1}\n");
symlink($alias . '/nest/.private/purchases.json', $alias . '/nest/link-purchases.json');
$prevPriv = $GLOBALS['_JEAB_DATA_DIR'];
$prevPub = $GLOBALS['_JEAB_PUBLIC_DATA_DIR'];
$GLOBALS['_JEAB_DATA_DIR'] = $alias . '/nest/.private';
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $alias . '/nest';
$nestedRels = [];
foreach (jeab_json_inventory_rows() as $row) {
    $nestedRels[] = (string) $row['root'] . ':' . (string) $row['rel'];
}
jtt(in_array('private:purchases.json', $nestedRels, true), 'nästlad privat fil räknas en gång');
jtt(!in_array('public:.private/purchases.json', $nestedRels, true) && !in_array('public:link-purchases.json', $nestedRels, true), 'samma inode via .private och symlink räknas inte igen');
jtt(in_array('public:only-public.json', $nestedRels, true), 'fil som bara ligger publikt finns kvar');
$GLOBALS['_JEAB_DATA_DIR'] = $alias . '/via-priv';
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $alias . '/via-pub';
$aliasRows = jeab_json_inventory_rows();
$deletedListed = 0;
foreach ($aliasRows as $row) {
    if ((string) ($row['rel'] ?? '') === 'ordrar-deleted.json') {
        $deletedListed++;
    }
}
jtt($deletedListed === 1 && count($aliasRows) === 2, 'två symlänkade rötter ger varje fil en gång');
$symlinkWet = jeab_json_trash_apply(['ordrar-deleted.json'], false, '2026-10-30');
$symlinkMoved = 0;
foreach ($symlinkWet['moved'] ?? [] as $row) {
    if (str_ends_with((string) ($row['rel'] ?? ''), 'ordrar-deleted.json')) {
        $symlinkMoved++;
    }
}
jtt($symlinkMoved === 1 && !is_file($alias . '/real/ordrar-deleted.json') && is_file($alias . '/real/decoy.json'), 'symlink-rötter flyttar filen en gång');
jtt(count(glob($alias . '/real/trash/*/*ordrar-deleted.json') ?: []) === 1, 'symlink-flytten lämnar en kopia');
mkdir($alias . '/nest/.private/secrets', 0775, true);
file_put_contents($alias . '/nest/.private/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-alias']));
file_put_contents($alias . '/nest/.private/ordrar-deleted.json', "{\"ids\":{}}\n");
$schemaBoot = $alias . '/schema-boot.php';
file_put_contents($schemaBoot, <<<'PHP'
<?php
$GLOBALS['_JEAB_DATA_DIR'] = $argv[1];
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $argv[2];
require $argv[3] . '/src/bootstrap.php';
jeab_boot();
PHP);
$schemaProc = proc_open(
    [PHP_BINARY, $schemaBoot, $alias . '/nest/.private', $alias . '/nest', $root],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $schemaPipes
);
jtt(is_resource($schemaProc), 'schema-boot startade');
$schemaOut = stream_get_contents($schemaPipes[1]) . stream_get_contents($schemaPipes[2]);
fclose($schemaPipes[1]);
fclose($schemaPipes[2]);
$schemaCode = proc_close($schemaProc);
jtt($schemaCode === 0 && is_file($alias . '/nest/.private/app.sqlite'), 'färsk sqlite har settings innan php -S: ' . trim($schemaOut));
$aliasRouter = $alias . '/router.php';
file_put_contents($aliasRouter, <<<'PHP'
<?php
$GLOBALS['_JEAB_DATA_DIR'] = getenv('JEAB_ALIAS_PRIVATE');
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = getenv('JEAB_ALIAS_PUBLIC');
if (trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '')) === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        if (strcasecmp((string) $name, 'Authorization') === 0) {
            $_SERVER['HTTP_AUTHORIZATION'] = (string) $value;
        }
    }
}
require getenv('JEAB_ROOT') . '/hook.php';
PHP);
$aliasPort = 27000 + (getmypid() % 10000);
$aliasLog = $alias . '/server.log';
$aliasServer = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $aliasPort, $aliasRouter],
    [1 => ['file', $aliasLog, 'a'], 2 => ['file', $aliasLog, 'a']],
    $aliasPipes,
    $root,
    jeab_test_proc_env([
        'JEAB_TEST_TEMP_SQLITE' => '1',
        'JEAB_ALIAS_PRIVATE' => $alias . '/nest/.private',
        'JEAB_ALIAS_PUBLIC' => $alias . '/nest',
        'JEAB_ROOT' => $root,
    ])
);
jtt(is_resource($aliasServer), 'php -S för alias startade');
$aliasCurl = static function (array $payload) use ($aliasPort): array {
    $cmd = [
        'curl', '-sS', '-D', '-', '--max-time', '60',
        '-X', 'POST',
        '-H', 'Content-Type: application/json',
        '-H', 'Authorization: Bearer tok-alias',
        '--data-binary', json_encode($payload, JSON_UNESCAPED_UNICODE),
        'http://127.0.0.1:' . $aliasPort . '/hook.php',
    ];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return ['status' => 0, 'json' => []];
    }
    $raw = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $status = 0;
    if (preg_match('/^HTTP\/\S+\s+(\d+)/m', $raw, $m) === 1) {
        $status = (int) $m[1];
    }
    $split = strpos($raw, "\r\n\r\n");
    $body = $split === false ? $raw : substr($raw, $split + 4);
    $json = json_decode($body, true);

    return ['status' => $status, 'json' => is_array($json) ? $json : [], 'body' => $body];
};
$aliasInv = ['status' => 0, 'json' => []];
for ($i = 0; $i < 30; $i++) {
    $aliasInv = $aliasCurl(['action' => 'json.inventory']);
    if ($aliasInv['status'] === 200 && is_array($aliasInv['json']['files'] ?? null)) {
        break;
    }
    usleep(150000);
}
$aliasDeleted = 0;
$aliasPrivateDup = 0;
foreach ($aliasInv['json']['files'] ?? [] as $row) {
    $rel = (string) ($row['rel'] ?? '');
    if ($rel === 'ordrar-deleted.json' || $rel === 'purchases.json') {
        $aliasDeleted++;
    }
    if (str_starts_with($rel, '.private/')) {
        $aliasPrivateDup++;
    }
}
jtt($aliasInv['status'] === 200 && $aliasDeleted === 2 && $aliasPrivateDup === 0, 'curl-inventering räknar inte .private en gång till');
$aliasTrash = $aliasCurl(['action' => 'json.trash_unused', 'dry' => 0]);
$aliasMoved = 0;
foreach ($aliasTrash['json']['moved'] ?? [] as $row) {
    if (str_ends_with((string) ($row['rel'] ?? ''), 'ordrar-deleted.json') || str_ends_with((string) ($row['rel'] ?? ''), 'purchases.json')) {
        $aliasMoved++;
    }
}
jtt($aliasTrash['status'] === 200 && ($aliasTrash['json']['dry'] ?? 1) === 0 && $aliasMoved === 2, 'curl-flytt tar varje delad fil en gång');
jtt(!is_file($alias . '/nest/.private/ordrar-deleted.json') && !is_file($alias . '/nest/.private/purchases.json') && is_file($alias . '/nest/only-public.json'), 'källorna är borta och den publika filen ligger kvar');
$trashed = array_values(array_filter(
    glob($alias . '/nest/.private/trash/*/*.json') ?: [],
    static fn (string $path): bool => !str_ends_with($path, 'manifest.json')
));
$trashNames = array_map('basename', $trashed);
$trashCount = static function (string $name) use ($trashNames): int {
    return count(array_filter($trashNames, static fn (string $one): bool => $one === $name));
};
jtt($trashCount('ordrar-deleted.json') === 1 && $trashCount('purchases.json') === 1, 'papperskorgen har en kopia per fil');
if (is_resource($aliasServer)) {
    proc_terminate($aliasServer);
    proc_close($aliasServer);
}
$GLOBALS['_JEAB_DATA_DIR'] = $prevPriv;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $prevPub;

echo "PASS json.inventory json.trash_unused\n";
