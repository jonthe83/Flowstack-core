<?php

declare(strict_types=1);

/**
 * Inkorgens klickväg: prefetch av tråd-JSON, vykort i bakgrunden, ingen Graph på klick.
 * Syntetiska rader. Ingen kunddata.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-ixfart-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
require $root . '/src/bootstrap.php';

function fart(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$pdo = jeab_pdo_connect_sqlite();
fart(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan går att sätta');
$store = jeab_inkorg_pdo_store();
fart($store instanceof JeabPdoInkorgStore, 'SQL-lagret är öppet');

for ($i = 1; $i <= 12; $i++) {
    $id = sprintf('t%02d', $i);
    $store->upsertMessage($id . 'm', [
        'id' => $id . 'm',
        'thread_id' => $id,
        'from' => 'kund' . $i . '@exempel.test',
        'from_name' => 'Kund ' . $i,
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Ärende ' . $id,
        'body' => 'Kort text ' . $i,
        'received_at' => sprintf('2024-06-%02dT10:00:00+00:00', $i),
        'source' => 'graph-jarfalla',
        'labels' => ['type' => 'Support'],
    ]);
    $store->upsertThread($id, [
        'status' => 'open',
        'tags' => [],
        'assignee' => '',
        'read' => [],
        'company' => 'jarfalla',
        'updated_at' => sprintf('2024-06-%02dT10:00:00+00:00', $i),
    ]);
}

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$GLOBALS['_JEAB_INKORG_COUNT_COMPUTE'] = 0;
$before = jeab_inkorg_fast_prepare(false, 'list');
fart((int) $GLOBALS['_JEAB_INKORG_COUNT_COMPUTE'] === 0, 'listklick räknar inte vykorten');
fart(substr_count((string) $before['html'], 'data-ix-id="') >= 10, 'listan har minst tio ärenden att förhämta');
fart((int) ($before['counts_fresh'] ?? 0) === 0, 'första klicket väntar inte på en färsk räkning');

$bag = jeab_inkorg_fast_counts_refresh();
fart((int) $GLOBALS['_JEAB_INKORG_COUNT_COMPUTE'] === 1, 'bakgrundsräkningen körs en gång');
$sum = 0;
foreach ((array) ($bag['counts'] ?? []) as $n) {
    $sum += (int) $n;
}
fart($sum > 0, 'bakgrundsräkningen fyller vykorten');
$again = jeab_inkorg_fast_counts_refresh();
fart((int) $GLOBALS['_JEAB_INKORG_COUNT_COMPUTE'] === 1, 'färsk cache räknas inte om');
fart((int) ($again['fresh'] ?? 0) === 1, 'cachen är färsk');

$GLOBALS['_JEAB_INKORG_COUNT_COMPUTE'] = 0;
$hit = jeab_inkorg_fast_prepare(true, 'list');
fart((int) $GLOBALS['_JEAB_INKORG_COUNT_COMPUTE'] === 0, 'nästa listklick läser cachen');
fart((array) ($hit['counts'] ?? []) !== [] && (int) ($hit['counts_fresh'] ?? 0) === 1, 'listklicket får talen från cachen');

$page = (string) file_get_contents($root . '/src/pages/inkorg.php');
$js = (string) file_get_contents($root . '/assets/js/inkorg-swipe.js');
$index = (string) file_get_contents($root . '/index.php');
$sms = (string) file_get_contents($root . '/src/sms.php');
$graph = (string) file_get_contents($root . '/src/inkorg_graph.php');
fart(str_contains($page, 'ixPrefetchTop') && str_contains($page, 'n < 1') && str_contains($page, 'ixMem'), 'nästa tråd förhämtas, inte en kö av tio');
fart(str_contains($page, 'ixShowPending') && str_contains($page, 'is-loading') && str_contains($page, 'X-Jeab-Interactive'), 'klicket tänder raden direkt och skickar interaktiv header');
fart(str_contains($page, 'ixLoadThread(url, true)') && str_contains($page, "headers.Purpose = 'prefetch'"), 'bara förhämtning sätter Purpose prefetch');
$shellJs = (string) file_get_contents($root . '/assets/js/shell.js');
$appJs = (string) file_get_contents($root . '/assets/js/app.js');
fart(str_contains($shellJs, 'jeabBackground') && str_contains($shellJs, 'window.jeabBackground.busy()'), 'skalets förhämtning väntar om en redan pågår');
fart(str_contains($appJs, 'calNavAbort') && str_contains($appJs, 'bg.busy()'), 'veckobyte och påminnelsepoll kör högst en bakgrundshämtning');
fart(str_contains($page, 'pointerover') && str_contains($page, 'touchstart'), 'hover och touchstart förhämtar tråden');
fart(str_contains($page, 'ixPaintThread') && str_contains($page, 'history.pushState'), 'öppnad tråd byter panel utan helsida');
fart(str_contains($page, 'ix_part=counts') || str_contains($page, "'counts'"), 'vykort har en egen bakgrundsdel');
fart(str_contains($page, 'jeab_timing_span') && str_contains($page, "'thread'"), 'tråd-delen sätter tid');
fart(!str_contains($index, 'jeab_graph_daily_schedule'), 'index anropar inte Graph vid sidklick');
fart(str_contains($sms, 'jeab_inkorg_mail_poll') && str_contains($graph, 'function jeab_inkorg_mail_poll'), 'nya mejl hämtas från tick');
fart(str_contains($graph, 'JEAB_MAIL_POLL'), 'Graph-schemat på sidvisning är av');
$closeAt = strpos($js, 'function closeCard');
$closeSlice = $closeAt === false ? '' : substr($js, $closeAt, 1400);
$optAt = strpos($closeSlice, 'ixOptimisticRow');
$postAt = strpos($closeSlice, "status: 'closed'");
fart($optAt !== false && $postAt !== false && $optAt < $postAt, 'stäng ritar om raden innan post');
fart(str_contains($js, 'function toggleRead') && strpos($js, 'setUnread(card, !wasUnread)') < strpos($js, "action: 'inkorg.read'"), 'läst ritar om raden innan post');
fart(str_contains($page, 'ixOptimisticRead'), 'öppnad tråd markerar läst på raden');

function fart_child(string $root, string $tmp, string $code): array
{
    $runner = $tmp . '/child.php';
    file_put_contents($runner, "<?php\n" . $code);
    $proc = proc_open(
        [PHP_BINARY, $runner],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        ['JEAB_FART_TMP' => $tmp, 'JEAB_ROOT' => $root]
    );
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($proc), 'out' => (string) $out, 'err' => (string) $err];
}

$boot = <<<'PHP'
$tmp = getenv('JEAB_FART_TMP');
$root = getenv('JEAB_ROOT');
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
require $root . '/src/bootstrap.php';
require $root . '/src/layout.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
jeab_pdo_connect_sqlite();
PHP;

$thread = fart_child($root, $tmp, $boot . <<<'PHP'
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'id' => 't01', 'ix_part' => 'thread'];
require getenv('JEAB_ROOT') . '/src/pages/inkorg.php';
PHP);
$tj = json_decode($thread['out'], true);
fart($thread['code'] === 0 && is_array($tj) && ($tj['ok'] ?? false) === true, 'tråd-JSON svarar ' . trim($thread['err']));
fart(str_contains((string) ($tj['html'] ?? ''), 'id="ix-conv"') && str_contains((string) ($tj['html'] ?? ''), 'ix-h1') && !str_contains((string) ($tj['html'] ?? ''), 'crm-title'), 'tråd-JSON är bara tråden');
fart(($tj['read_on_open'] ?? false) === true, 'tråd-JSON säger att raden ska bli läst, utan att skriva');
$meta = $store->threadsByIds(['t01']);
fart((array) ($meta['t01']['read'] ?? []) === [], 'förhämtningen markerar inte läst');

$counts = fart_child($root, $tmp, $boot . <<<'PHP'
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'ix_part' => 'counts'];
$t = microtime(true);
require getenv('JEAB_ROOT') . '/src/pages/inkorg.php';
PHP);
$cj = json_decode($counts['out'], true);
fart($counts['code'] === 0 && is_array($cj) && ($cj['fresh'] ?? 0) === 1 && is_array($cj['counts'] ?? null), 'counts-delen är JSON');

file_put_contents($tmp . '/time.php', <<<'PHP'
<?php
$tmp = getenv('JEAB_FART_TMP') ?: dirname(__FILE__);
$root = getenv('JEAB_ROOT');
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
require $root . '/src/bootstrap.php';
require $root . '/src/layout.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/time.php';
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'id' => 't01', 'ix_part' => 'thread'];
require $root . '/src/pages/inkorg.php';
PHP);
$port = 18931;
$proc = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, $tmp . '/time.php'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $tmp,
    ['JEAB_FART_TMP' => $tmp, 'JEAB_ROOT' => $root, 'JEAB_DISABLE_SQLITE' => '0']
);
fart(is_resource($proc), 'lokal server startar');
usleep(200000);
$last = ['code' => 0, 'ms' => 0.0, 'timing' => ''];
for ($n = 0; $n < 2; $n++) {
    $ch = curl_init('http://127.0.0.1:' . $port . '/time.php?p=inkorg&box=jarfalla&id=t01&ix_part=thread');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['X-Jeab-Interactive: 1', 'X-Requested-With: XMLHttpRequest', 'Accept: application/json'],
    ]);
    $raw = (string) curl_exec($ch);
    $last['code'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $last['ms'] = round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000, 1);
    curl_close($ch);
    $header = strstr($raw, "\r\n\r\n", true);
    preg_match('/Server-Timing:\s*(.+)/i', is_string($header) ? $header : '', $m);
    $last['timing'] = trim((string) ($m[1] ?? ''));
}
proc_terminate($proc);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);
fart($last['code'] === 200 && str_contains($last['timing'], 'db;dur=') && str_contains($last['timing'], 'total;dur='), 'tråd Server-Timing ' . $last['timing']);
fart($last['ms'] < 300, 'tråd curl ' . $last['ms'] . ' ms');
echo 'curl tråd ' . $last['ms'] . ' ms (' . $last['timing'] . ")\n";
echo "inkorg fart: ok\n";
