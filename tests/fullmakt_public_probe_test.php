<?php

declare(strict_types=1);

/**
 * fullmakt.public_probe hämtar publika kundlänken utan kakor.
 * Svaret har hash-id, namn, order, källa, statuskedja och om sidan ritar.
 * Token, personnummer och URL lämnar inte svaret. Inget skrivs.
 */

$tmp = sys_get_temp_dir() . '/jeab-fullmakt-public-probe-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
mkdir($tmp . '/fullmakt', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_STEG4_CUTOVER'] = true;
$GLOBALS['_JEAB_DATA_BACKEND'] = 'mysql';
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
putenv('FULLMAKT_DATA=' . $tmp . '/fullmakt');
$_ENV['DATA_BACKEND'] = 'mysql';
$_ENV['FULLMAKT_DATA'] = $tmp . '/fullmakt';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
file_put_contents($tmp . '/inkorg/messages.json', '{"messages":[]}');
file_put_contents($tmp . '/inkorg/threads.json', '{"threads":{}}');

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/fullmakt/lib/bootstrap.php';
require dirname(__DIR__) . '/fullmakt/lib/store.php';
require dirname(__DIR__) . '/src/fullmakt_public_probe.php';
fullmakt_boot();

function fpp(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function fpp_rows(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT token, status, created_at, payload FROM fullmakt_docs ORDER BY token');
    $rows = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    return is_array($rows) ? $rows : [];
}

/** @param array<string, mixed> $body */
function fpp_by_id(array $body, string $id): array
{
    foreach ((array) ($body['docs'] ?? []) as $row) {
        if (is_array($row) && (string) ($row['id'] ?? '') === $id) {
            return $row;
        }
    }

    return [];
}

$src = (string) file_get_contents(dirname(__DIR__) . '/src/fullmakt_public_probe.php');
$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
$parties = (string) file_get_contents(dirname(__DIR__) . '/fullmakt/lib/parties.php');
fpp(str_contains($parties, "dirname(\$boot) . '/datastore.php'"), 'fullmakt_load_jeab laddar datastore.php när cutover saknas');
fpp(!str_contains((string) file_get_contents(dirname(__DIR__) . '/src/shield.php'), 'fullmakt_public_probe'), 'shield.php är orörd');
fpp(str_contains($hook, "'fullmakt.public_probe'") && str_contains($hook, 'jeab_fullmakt_public_probe_dispatch'), 'hook.php tar fullmakt.public_probe');
$hookStart = strpos($hook, "=== 'fullmakt.public_probe'");
$hookEnd = strpos($hook, "=== 'ordrar.backfill'");
$hookBlock = $hookStart !== false && $hookEnd !== false && $hookEnd > $hookStart
    ? substr($hook, $hookStart, $hookEnd - $hookStart)
    : '';
fpp(str_contains($hookBlock, 'jeab_inkorg_hook_authorized') && str_contains($hookBlock, 'catch (Throwable $e)') && str_contains($hookBlock, "'read_only' => true"), 'hooken kräver token, fångar fel och är read-only');
fpp(preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE)\b/', $src) !== 1, 'källan har ingen skriv-SQL');
fpp(!str_contains($src, 'file_put_contents') && !str_contains($src, '$_COOKIE') && !str_contains($src, 'CURLOPT_COOKIE'), 'källan skriver inte och skickar ingen kaka');
fpp(str_contains($src, 'CURLOPT_FOLLOWLOCATION => true'), 'hämtningen följer redirect');

$opts = jeab_fullmakt_public_probe_curl_options();
fpp(($opts[CURLOPT_FOLLOWLOCATION] ?? false) === true, 'curl följer redirect');
fpp(!array_key_exists(CURLOPT_COOKIE, $opts) && !array_key_exists(CURLOPT_COOKIEFILE, $opts) && !array_key_exists(CURLOPT_COOKIEJAR, $opts), 'curl-valen har ingen kaka');
fpp((int) ($opts[CURLOPT_PROTOCOLS] ?? 0) === (int) CURLPROTO_HTTPS, 'bara https');

$pdo = jeab_data_pdo();
fpp($pdo instanceof PDO && fullmakt_sql_active(), 'SQL-vägen är aktiv');

$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-fullmakt-probe']));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = '';
$_COOKIE['jeab_auth'] = 'ska-inte-vidare';
$denied = jeab_fullmakt_public_probe_dispatch(['action' => 'fullmakt.public_probe']);
fpp(empty($denied['ok']) && (int) ($denied['status'] ?? 0) === 401 && ($denied['read_only'] ?? false) === true && !array_key_exists('docs', $denied), 'utan token blir det 401 och ingen lista');
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = 'tok-fullmakt-probe';

$pnr = '19700101-0101';
$open = fullmakt_create_doc([
    'type' => 'ellevio',
    'namn' => 'Ada ' . $pnr,
    'personnummer' => $pnr,
    'epost' => 'ada.probe@example.test',
    'order_id' => '69',
    'sign_url' => 'https://flowstack.example.test/fullmakt/s/inte-en-riktig-token',
]);
$openToken = (string) ($open['token'] ?? '');
fpp($openToken !== '' && !is_file(fullmakt_pending_path($openToken)), 'öppen rad ligger bara i SQL');
fpp(jeab_fullmakt_public_probe_url($openToken) === 'https://flowstack.example.test/fullmakt/s/' . $openToken, 'internt byggs publika https-adressen');

$jsonOnly = bin2hex(random_bytes(16));
$jsonPath = fullmakt_pending_path($jsonOnly);
file_put_contents($jsonPath, json_encode([
    'token' => $jsonOnly,
    'namn' => 'Bara Fil',
    'personnummer' => $pnr,
    'status' => 'pending',
    'order_id' => '82',
], JSON_UNESCAPED_UNICODE));
$jsonBefore = (string) file_get_contents($jsonPath);

$asked = [];
$GLOBALS['_JEAB_FULLMAKT_PUBLIC_PROBE_FETCH'] = static function (string $token) use (&$asked, $openToken, $jsonOnly): array {
    $asked[] = $token;
    if ($token === $openToken) {
        return [
            'statuses' => [301, 200],
            'final_status' => 200,
            'body' => '<!DOCTYPE html><html><h1>Ada</h1><form id="sign-form"><button type="submit" id="skicka">Signera</button></form><a href="/fullmakt/s/' . $token . '">x</a></html>',
        ];
    }
    if ($token === $jsonOnly) {
        return [
            'statuses' => [200],
            'body' => '<html><p>Bara Fil</p><form id="sign-form"><button id="skicka" type="submit">Signera</button></form></html>',
        ];
    }

    return ['statuses' => [404], 'final_status' => 404, 'body' => '<html><h1>Länken gäller inte</h1></html>'];
};

$before = fpp_rows($pdo);
$listed = jeab_fullmakt_public_probe_dispatch(['action' => 'fullmakt.public_probe']);
$after = fpp_rows($pdo);
fpp($before === $after, 'anropet skriver inte i SQL');
fpp((string) file_get_contents($jsonPath) === $jsonBefore, 'anropet skriver inte JSON');
fpp($asked === [$openToken, $jsonOnly], 'både SQL-raden och JSON-dokumentet hämtas, SQL först');
fpp(!empty($listed['ok']) && ($listed['read_only'] ?? false) === true && (int) ($listed['sent'] ?? 1) === 0 && ($listed['would_send'] ?? true) === false, 'svaret är read-only');
fpp((int) ($listed['count'] ?? 0) === 2 && count((array) ($listed['docs'] ?? [])) === 2, 'två dokument');

$openId = substr(hash('sha256', $openToken), 0, 16);
$openRow = fpp_by_id($listed, $openId);
fpp($openRow !== [] && ($openRow['namn'] ?? '') === 'Ada' && ($openRow['order_id'] ?? '') === '69' && ($openRow['source'] ?? '') === 'sql', 'SQL-raden har namn, order och källa');
fpp(($openRow['statuskedja'] ?? null) === [301, 200] && (int) ($openRow['final_status'] ?? 0) === 200, 'SQL-raden har statuskedja och final status');
fpp(($openRow['renders'] ?? false) === true && ($openRow['sign_form'] ?? false) === true, 'SQL-raden ritar namn och formulär');

$jsonRow = fpp_by_id($listed, substr(hash('sha256', $jsonOnly), 0, 16));
fpp(($jsonRow['namn'] ?? '') === 'Bara Fil' && ($jsonRow['order_id'] ?? '') === '82' && ($jsonRow['source'] ?? '') === 'json', 'JSON-dokumentet har namn, order och källa');
fpp(($jsonRow['statuskedja'] ?? null) === [200] && (int) ($jsonRow['final_status'] ?? 0) === 200 && ($jsonRow['renders'] ?? false) === true && ($jsonRow['sign_form'] ?? false) === true, 'JSON-dokumentet följer samma fält');

$public = $listed;
unset($public['status']);
$blob = (string) json_encode($public, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
fpp(!str_contains($blob, $openToken) && !str_contains($blob, $jsonOnly), 'svaret har ingen token');
fpp(!str_contains($blob, $pnr) && !str_contains($blob, '197001011111') && !str_contains($blob, '/fullmakt/s/') && !str_contains($blob, 'https://') && !str_contains($blob, 'sign_url'), 'svaret har inget personnummer och ingen URL');

echo "fullmakt_public_probe_test: ok\n";
