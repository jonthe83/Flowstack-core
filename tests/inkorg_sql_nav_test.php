<?php

declare(strict_types=1);

/**
 * SQL-inkorg: frågetak per vy, ingen dubbel rendering, Server-Timing.
 * Syntetiska rader. Ingen kunddata.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-ixnav-' . bin2hex(random_bytes(4));
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
], true) . ";\n");
require $root . '/src/bootstrap.php';

function navok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$pdo = jeab_pdo_connect_sqlite();
navok(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan går att sätta');
navok(jeab_inkorg_storage_mode() === 'sql', 'inkorgen kör storage sql');

$store = jeab_inkorg_pdo_store();
navok($store instanceof JeabPdoInkorgStore, 'SQL-lagret är öppet');

$fat = str_repeat('Hej kunden. ', 400) . 'UNIK-TRADKROPP-77';
$nThreads = 180;
for ($i = 1; $i <= $nThreads; $i++) {
    $id = sprintf('t%03d', $i);
    $company = $i % 5 === 0 ? 'malmo' : 'jarfalla';
    $body = $i === 42 ? $fat : str_repeat('Kort rad ' . $i . '. ', 80);
    $store->upsertMessage($id . 'm', [
        'id' => $id . 'm',
        'thread_id' => $id,
        'from' => 'kund' . $i . '@exempel.test',
        'from_name' => 'Kund ' . $i,
        'to' => $company === 'malmo' ? 'kontakt@malmo.example.test' : 'kontakt@jarfalla.example.test',
        'subject' => 'Ärende ' . $id,
        'body' => $body,
        'received_at' => sprintf('2024-06-%02dT10:%02d:00+00:00', ($i % 27) + 1, $i % 60),
        'source' => $company === 'malmo' ? 'graph-malmo' : 'graph-jarfalla',
        'labels' => ['type' => $i % 2 === 0 ? 'Support' : 'Ny lead'],
    ]);
    $store->upsertThread($id, [
        'status' => $i > 150 ? 'closed' : 'open',
        'tags' => [],
        'assignee' => '',
        'read' => [],
        'company' => $company,
        'updated_at' => sprintf('2024-06-%02dT10:%02d:00+00:00', ($i % 27) + 1, $i % 60),
    ]);
}
navok(true, 'laddade ' . $nThreads . ' ärenden med tjocka brev');

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$cold0 = microtime(true);
$built = jeab_inkorg_fast_prepare(false, 'list');
$coldBuild = microtime(true) - $cold0;
navok(str_contains((string) $built['html'], 'ix-it'), 'första listan byggs, ' . round($coldBuild * 1000) . ' ms');
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'q' => 'Ärende t011'];
$found = jeab_inkorg_fast_prepare(false, 'list');
navok(str_contains((string) $found['html'], 'Ärende t011') && !str_contains((string) $found['html'], 'Ärende t012'), 'SQL-sök hittar ärendet utan att läsa alla brev');
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla'];
navok(!str_contains((string) $built['html'], 'UNIK-TRADKROPP-77'), 'listan tar inte med hela brevet');

$indexes = jeab_existing_index_names($pdo, 'inkorg_messages', 'sqlite');
navok(in_array('inkorg_messages_thread_received', $indexes, true), 'index thread_id, received_at finns');
$listIdx = jeab_existing_index_names($pdo, 'inkorg_list', 'sqlite');
navok(in_array('inkorg_list_company_at', $listIdx, true), 'index company, list_at finns');

function nav_child(string $root, string $tmp, string $code): array
{
    $runner = $tmp . '/child.php';
    file_put_contents($runner, "<?php\n" . $code);
    $proc = proc_open(
        [PHP_BINARY, $runner],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        ['JEAB_NAV_TMP' => $tmp, 'JEAB_ROOT' => $root]
    );
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $codeExit = proc_close($proc);

    return ['code' => $codeExit, 'out' => (string) $out, 'err' => (string) $err];
}

$boot = <<<'PHP'
$tmp = getenv('JEAB_NAV_TMP');
$root = getenv('JEAB_ROOT');
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
require $root . '/src/bootstrap.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
jeab_pdo_connect_sqlite();
if (function_exists('jeab_inkorg_pdo_store')) {
    jeab_inkorg_pdo_store();
}
jeab_sql_stats_reset();
PHP;

$list = nav_child($root, $tmp, $boot . <<<'PHP'
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'ix_part' => 'list'];
$t = microtime(true);
$fast = jeab_inkorg_fast_prepare(false, 'list');
$dt = microtime(true) - $t;
$st = jeab_sql_stats();
$full = false;
foreach ($st['log'] as $sql) {
    if (preg_match('/select\s+row_json\s+from\s+inkorg_messages\s*$/i', trim($sql)) === 1) {
        $full = true;
    }
}
echo json_encode([
    'ms' => round($dt * 1000, 1),
    'n' => $st['n'],
    'full' => $full,
    'html' => str_contains((string) $fast['html'], 'ix-it'),
    'body' => str_contains((string) $fast['html'], 'UNIK-TRADKROPP-77'),
    'db' => round($st['seconds'] * 1000, 1),
], JSON_UNESCAPED_UNICODE);
PHP);
$lj = json_decode($list['out'], true);
navok($list['code'] === 0 && is_array($lj), 'list-barn svarar ' . trim($list['err']));
navok(($lj['n'] ?? 99) <= 40, 'lista: ' . ($lj['n'] ?? '?') . ' SQL-frågor (tak 40)');
navok(($lj['ms'] ?? 9999) < 1000, 'lista kall: ' . ($lj['ms'] ?? '?') . ' ms');
navok(empty($lj['full']), 'lista läser inte alla brev');
navok(!empty($lj['html']) && empty($lj['body']), 'lista är rader, inte trådkroppen');
echo '    lista ' . $lj['n'] . ' frågor, ' . $lj['ms'] . " ms, db " . $lj['db'] . " ms\n";

$thread = nav_child($root, $tmp, $boot . <<<'PHP'
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'id' => 't042', 'ix_part' => 'thread'];
$t = microtime(true);
$fast = jeab_inkorg_fast_prepare(false, 'thread');
$dt = microtime(true) - $t;
$st = jeab_sql_stats();
$full = false;
foreach ($st['log'] as $sql) {
    if (preg_match('/select\s+row_json\s+from\s+inkorg_messages\s*$/i', trim($sql)) === 1) {
        $full = true;
    }
}
$open = is_array($fast['open']) ? $fast['open'] : [];
echo json_encode([
    'ms' => round($dt * 1000, 1),
    'n' => $st['n'],
    'full' => $full,
    'id' => (string) ($open['id'] ?? ''),
    'body' => str_contains((string) ($open['messages'][0]['body'] ?? ''), 'UNIK-TRADKROPP-77'),
    'panel' => count((array) ($fast['panel'] ?? [])),
], JSON_UNESCAPED_UNICODE);
PHP);
$tj = json_decode($thread['out'], true);
navok($thread['code'] === 0 && is_array($tj), 'tråd-barn svarar ' . trim($thread['err']));
navok(($tj['n'] ?? 99) <= 40, 'tråd: ' . ($tj['n'] ?? '?') . ' SQL-frågor (tak 40)');
navok(($tj['ms'] ?? 9999) < 1000, 'tråd kall: ' . ($tj['ms'] ?? '?') . ' ms');
navok(empty($tj['full']) && ($tj['id'] ?? '') === 't042' && !empty($tj['body']), 'tråden hämtas med WHERE, kroppen finns');
navok(($tj['panel'] ?? 999) < 30, 'kundpanelen är inte hela inkorgen (' . ($tj['panel'] ?? '?') . ')');
echo '    tråd ' . $tj['n'] . ' frågor, ' . $tj['ms'] . " ms\n";

$page = nav_child($root, $tmp, $boot . <<<'PHP'
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'id' => 't042'];
require getenv('JEAB_ROOT') . '/src/layout.php';
ob_start();
$html = (string) require getenv('JEAB_ROOT') . '/src/pages/inkorg.php';
$rest = ob_get_clean();
$html .= $rest;
$n = preg_match_all('/\n<section class="ix-conv"/', $html);
echo json_encode([
    'n' => $n,
    'body' => str_contains($html, 'UNIK-TRADKROPP-77'),
    'push' => str_contains($html, 'history.pushState'),
    'stale' => str_contains($html, 'data-ix-stale') || str_contains($html, 'data-ix-refresh'),
    'part' => str_contains($html, 'ix_part'),
    'h1' => str_contains($html, '<h1'),
], JSON_UNESCAPED_UNICODE);
PHP);
$pj = json_decode($page['out'], true);
navok($page['code'] === 0 && is_array($pj), 'sidan svarar ' . trim($page['err']) . ' ' . substr($page['out'], 0, 200));
navok(($pj['n'] ?? 0) === 1 && !empty($pj['body']), 'dokumentet ritar tråden en gång (' . ($pj['n'] ?? '?') . ')');
navok(!empty($pj['push']) && empty($pj['stale']), 'pushState finns och sidan hämtar inte om listan själv');

$part = nav_child($root, $tmp, $boot . <<<'PHP'
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'id' => 't042', 'ix_open' => 't042', 'ix_part' => 'list'];
require getenv('JEAB_ROOT') . '/src/layout.php';
require getenv('JEAB_ROOT') . '/src/pages/inkorg.php';
PHP);
$partJson = json_decode($part['out'], true);
navok($part['code'] === 0 && is_array($partJson) && ($partJson['ok'] ?? false) === true, 'ix_part=list är JSON ' . trim($part['err']));
navok(!str_contains((string) ($partJson['html'] ?? ''), 'UNIK-TRADKROPP-77') && !str_contains($part['out'], '<h1'), 'list-delen ritar inte tråden');
navok(trim($part['err']) === '', 'list-del tyst');

$threadPart = nav_child($root, $tmp, $boot . <<<'PHP'
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'id' => 't042', 'ix_part' => 'thread'];
require getenv('JEAB_ROOT') . '/src/layout.php';
require getenv('JEAB_ROOT') . '/src/pages/inkorg.php';
PHP);
$threadHtml = (string) ($threadPart['out'] ?? '');
$decodedThread = json_decode($threadHtml, true);
navok($threadPart['code'] === 0 && is_array($decodedThread), 'ix_part=thread är JSON ' . trim($threadPart['err']));
$only = (string) ($decodedThread['html'] ?? '');
navok(substr_count($only, 'id="ix-conv"') === 1 && str_contains($only, 'UNIK-TRADKROPP-77') && !str_contains($only, 'ix-items') && !str_contains($only, 'crm-title') && substr_count($only, '<h1') === 1 && str_contains($only, '<h1 class="ix-h1">'), 'tråd-delen är bara tråden');

$malmo = nav_child($root, $tmp, $boot . <<<'PHP'
$_GET = ['p' => 'inkorg', 'box' => 'malmo', 'ix_part' => 'app'];
require getenv('JEAB_ROOT') . '/src/layout.php';
$t = microtime(true);
register_shutdown_function(static function () use ($t): void {
    fwrite(STDERR, 'MS:' . round((microtime(true) - $t) * 1000, 1));
});
require getenv('JEAB_ROOT') . '/src/pages/inkorg.php';
PHP);
$mj = json_decode($malmo['out'], true);
navok($malmo['code'] === 0 && is_array($mj) && ($mj['box'] ?? '') === 'malmo', 'Malmö-skalet är JSON');
preg_match('/MS:([0-9.]+)/', $malmo['err'], $mm);
$malmoMs = isset($mm[1]) ? (float) $mm[1] : 9999.0;
navok($malmoMs < 1000, 'Malmö-skal ' . $malmoMs . ' ms');
navok(substr_count((string) ($mj['html'] ?? ''), 'UNIK-TRADKROPP-77') === 0, 'Malmö-skalet ritar ingen öppen tråd');

$srv = nav_child($root, $tmp, $boot . <<<'PHP'
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'ix_part' => 'list'];
require getenv('JEAB_ROOT') . '/src/layout.php';
require getenv('JEAB_ROOT') . '/src/pages/inkorg.php';
PHP);
navok($srv['code'] === 0 && str_contains($srv['err'], 'Server-Timing') === false, 'list-JSON utan stderr');

file_put_contents($tmp . '/time.php', <<<'PHP'
<?php
$tmp = getenv('JEAB_NAV_TMP') ?: dirname(__FILE__);
$root = getenv('JEAB_ROOT');
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
require $root . '/src/bootstrap.php';
require $root . '/src/layout.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/time.php';
$_GET = ['p' => 'inkorg', 'box' => (string) ($_GET['box'] ?? 'jarfalla'), 'ix_part' => (string) ($_GET['ix_part'] ?? 'list')];
if (($_GET['ix_part'] ?? '') === 'thread') {
    $_GET['id'] = 't042';
}
require $root . '/src/pages/inkorg.php';
PHP);

$port = 18921;
$proc = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, $tmp . '/time.php'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $tmp,
    ['JEAB_NAV_TMP' => $tmp, 'JEAB_ROOT' => $root]
);
navok(is_resource($proc), 'lokal server startar');
usleep(200000);
$timing = [];
foreach (['list' => 'ix_part=list&box=jarfalla', 'thread' => 'ix_part=thread&box=jarfalla&id=t042', 'malmo' => 'ix_part=app&box=malmo'] as $name => $q) {
    $ch = curl_init('http://127.0.0.1:' . $port . '/time.php?' . $q);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $total = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    curl_close($ch);
    $header = strstr($raw, "\r\n\r\n", true);
    if (!is_string($header)) {
        $header = '';
    }
    preg_match('/Server-Timing:\s*(.+)/i', $header, $m);
    $timing[$name] = ['code' => $code, 'ms' => round($total * 1000, 1), 'timing' => trim((string) ($m[1] ?? ''))];
    navok($code === 200 && $timing[$name]['timing'] !== '' && str_contains($timing[$name]['timing'], 'db;dur=') && str_contains($timing[$name]['timing'], 'render;dur='), $name . ' Server-Timing ' . $timing[$name]['timing'] . ' http ' . $code);
    navok($timing[$name]['ms'] < 1000, $name . ' curl ' . $timing[$name]['ms'] . ' ms');
}
proc_terminate($proc);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);

echo 'kall lista ' . $lj['ms'] . " ms, tråd " . $tj['ms'] . " ms, Malmö " . $malmoMs . " ms\n";
echo 'curl lista ' . $timing['list']['ms'] . ' ms (' . $timing['list']['timing'] . ")\n";
echo 'curl tråd ' . $timing['thread']['ms'] . ' ms (' . $timing['thread']['timing'] . ")\n";
echo 'curl Malmö ' . $timing['malmo']['ms'] . ' ms (' . $timing['malmo']['timing'] . ")\n";
echo "inkorg sql nav: ok\n";
