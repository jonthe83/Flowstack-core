<?php

declare(strict_types=1);

/**
 * fullmakt.docs_status läser fullmakt_docs och svarar utan token,
 * personnummer och sign-länk. resolves följer fullmakt_load_doc,
 * samma uppslag som /fullmakt/s/{token} efter #1101.
 * Påhittade rader. Inget riktigt personnummer.
 */

$tmp = sys_get_temp_dir() . '/jeab-fullmakt-docs-status-' . bin2hex(random_bytes(4));
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
putenv('JEAB_BUG_WEBHOOK_CAPTURE=1');
$_ENV['DATA_BACKEND'] = 'mysql';
$_ENV['FULLMAKT_DATA'] = $tmp . '/fullmakt';
$_ENV['JEAB_BUG_WEBHOOK_CAPTURE'] = '1';
$GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
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
require dirname(__DIR__) . '/fullmakt/lib/viewed.php';
require dirname(__DIR__) . '/src/fullmakt_docs_status.php';
fullmakt_boot();

function fds(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function fds_rows(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT token, status, created_at, payload FROM fullmakt_docs ORDER BY token');
    $rows = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    return is_array($rows) ? $rows : [];
}

/** @param array<string, mixed> $body */
function fds_by_id(array $body, string $id): array
{
    foreach ((array) ($body['docs'] ?? []) as $row) {
        if (is_array($row) && (string) ($row['id'] ?? '') === $id) {
            return $row;
        }
    }

    return [];
}

$src = (string) file_get_contents(dirname(__DIR__) . '/src/fullmakt_docs_status.php');
$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
fds(str_contains($hook, "'fullmakt.docs_status'") && str_contains($hook, 'jeab_fullmakt_docs_status_dispatch'), 'hook.php tar fullmakt.docs_status');
fds(str_contains($hook, "require_once __DIR__ . '/src/fullmakt_docs_status.php'"), 'hook.php laddar samma fil');
$hookStart = strpos($hook, "=== 'fullmakt.docs_status'");
$hookEnd = strpos($hook, "=== 'ordrar.backfill'");
$hookBlock = $hookStart !== false && $hookEnd !== false && $hookEnd > $hookStart
    ? substr($hook, $hookStart, $hookEnd - $hookStart)
    : '';
fds(str_contains($hookBlock, 'jeab_inkorg_hook_authorized') && str_contains($hookBlock, 'catch (Throwable $e)') && str_contains($hookBlock, "'read_only' => true"), 'hooken kräver token, fångar fel och är read-only');
fds(str_contains($src, 'function jeab_fullmakt_docs_status_dispatch') && str_contains($src, 'fullmakt_load_doc'), 'dispatch läser via fullmakt_load_doc');
fds(preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE)\b/', $src) !== 1, 'källan har ingen skriv-SQL');
fds(!str_contains($src, 'file_put_contents') && !str_contains($src, 'fullmakt_sql_put') && !str_contains($src, 'fullmakt_save_pending') && !str_contains($src, 'jeab_data_write'), 'källan skriver inte');

$pdo = jeab_data_pdo();
fds($pdo instanceof PDO && fullmakt_sql_active(), 'SQL-vägen är aktiv');

$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-fullmakt-docs']));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = '';
$denied = jeab_fullmakt_docs_status_dispatch(['action' => 'fullmakt.docs_status']);
fds(empty($denied['ok']) && (int) ($denied['status'] ?? 0) === 401 && ($denied['read_only'] ?? false) === true && !array_key_exists('docs', $denied), 'utan token blir det 401 och ingen lista');
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = 'tok-fullmakt-docs';

$pnr = '19700101-0101';
$open = fullmakt_create_doc([
    'type' => 'ellevio',
    'namn' => 'Ada ' . $pnr,
    'personnummer' => $pnr,
    'epost' => 'ada.fullmakt@example.test',
    'invite_epost' => 'mottagare.fullmakt@example.test',
    'order_id' => '69',
    'hs_conversation_id' => 'https://secure.helpscout.net/conversation/24563/hej',
    'thread_id' => '8801',
]);
$openToken = (string) ($open['token'] ?? '');
fds($openToken !== '' && !is_file(fullmakt_pending_path($openToken)), 'öppen rad ligger bara i SQL');
$open['sign_url'] = 'https://flowstack.example.test/fullmakt/s/' . $openToken;
$open['personnummer'] = $pnr;
fullmakt_save_pending($open);

$viewed = fullmakt_create_doc([
    'type' => 'ellevio',
    'namn' => 'Bea Visad',
    'epost' => 'bea.visad@example.test',
]);
$viewedToken = (string) ($viewed['token'] ?? '');
fds(fullmakt_mark_viewed($viewedToken), 'visad rad får viewed_at');

$signed = fullmakt_create_doc([
    'type' => 'overlay',
    'stamp_method' => 'fpdi',
    'namn' => 'Cajsa Signerad',
    'epost' => 'cajsa.signerad@example.test',
    'order_id' => '70',
]);
$signedToken = (string) ($signed['token'] ?? '');
$signedDoc = fullmakt_mark_signed($signedToken, $signed, "%PDF-1.4 docs-status\n");
fds(($signedDoc['status'] ?? '') === 'signed', 'signerad rad är signed');

$brokenToken = bin2hex(random_bytes(16));
$pdo->prepare('INSERT INTO fullmakt_docs (token, status, created_at, payload) VALUES (?, ?, ?, ?)')
    ->execute([$brokenToken, 'pending', '2020-01-01 00:00:00', '{']);
fds(fullmakt_load_doc($brokenToken) === null, 'trasig payload hittas inte av fullmakt_load_doc');

$jsonOnly = bin2hex(random_bytes(16));
file_put_contents(fullmakt_pending_path($jsonOnly), json_encode([
    'token' => $jsonOnly,
    'namn' => 'Bara Fil',
    'status' => 'pending',
], JSON_UNESCAPED_UNICODE));

$before = fds_rows($pdo);
$listed = jeab_fullmakt_docs_status_dispatch(['action' => 'fullmakt.docs_status']);
$after = fds_rows($pdo);
fds($before === $after, 'anropet skriver inte i SQL');
fds(!empty($listed['ok']) && ($listed['read_only'] ?? false) === true && (int) ($listed['sent'] ?? 1) === 0 && ($listed['would_send'] ?? true) === false, 'svaret är read-only');
fds((int) ($listed['count'] ?? 0) === 4 && count((array) ($listed['docs'] ?? [])) === 4, 'fyra SQL-rader, inte JSON-filen');

$openId = substr(hash('sha256', $openToken), 0, 16);
$openRow = fds_by_id($listed, $openId);
fds($openRow !== [] && ($openRow['status'] ?? '') === 'osignerad' && ($openRow['namn'] ?? '') === 'Ada' && ($openRow['order_id'] ?? '') === '69', 'öppen rad har status, namn och order');
fds(($openRow['email'] ?? '') === 'm***@example.test' && ($openRow['resolves'] ?? false) === true, 'mottagaren är maskad och sign-vägen hittar raden');
fds(($openRow['hs_conversation_id'] ?? '') === '24563' && ($openRow['hs_thread_id'] ?? '') === '8801', 'Help Scout-id är siffror');
fds(($openRow['created'] ?? '') !== '', 'skapad tid följer med');

$viewedRow = fds_by_id($listed, substr(hash('sha256', $viewedToken), 0, 16));
fds(($viewedRow['status'] ?? '') === 'visad' && ($viewedRow['namn'] ?? '') === 'Bea Visad' && ($viewedRow['resolves'] ?? false) === true && !array_key_exists('order_id', $viewedRow), 'visad rad saknar order_id');

$signedRow = fds_by_id($listed, substr(hash('sha256', $signedToken), 0, 16));
fds(($signedRow['status'] ?? '') === 'signerad' && ($signedRow['namn'] ?? '') === 'Cajsa Signerad' && ($signedRow['order_id'] ?? '') === '70' && ($signedRow['resolves'] ?? false) === true, 'signerad rad hittas av samma uppslag');

$brokenRow = fds_by_id($listed, substr(hash('sha256', $brokenToken), 0, 16));
fds(($brokenRow['status'] ?? '') === 'osignerad' && ($brokenRow['resolves'] ?? true) === false && ($brokenRow['namn'] ?? 'x') === '', 'trasig SQL-rad listas med resolves false');

$public = $listed;
unset($public['status']);
$blob = (string) json_encode($public, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
fds(!str_contains($blob, $openToken) && !str_contains($blob, $viewedToken) && !str_contains($blob, $signedToken) && !str_contains($blob, $brokenToken) && !str_contains($blob, $jsonOnly), 'svaret har ingen token');
fds(!str_contains($blob, $pnr) && !str_contains($blob, '197001011111') && !str_contains($blob, '/fullmakt/s/') && !str_contains(strtolower($blob), 'helpscout.net') && !str_contains($blob, 'sign_url') && !str_contains($blob, 'Bara Fil') && !str_contains($blob, 'mottagare.fullmakt'), 'svaret har inget personnummer, ingen sign-länk och ingen rå e-post');

echo "fullmakt_docs_status_test: ok\n";
