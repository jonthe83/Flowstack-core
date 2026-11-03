<?php

declare(strict_types=1);

/**
 * ix_part=list och ix_part=thread räknar inte om vykort och bygger inte om listan.
 * Bara ix_part=counts får göra det. Syntetiska rader. Ingen kunddata.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-ixclick-' . bin2hex(random_bytes(4));
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
require $root . '/src/layout.php';

function cc(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['jeab_email'] = 'saljare3@jarfalla.example.test';

$pdo = jeab_pdo_connect_sqlite();
cc(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan går att sätta');
$store = jeab_inkorg_pdo_store();
cc($store instanceof JeabPdoInkorgStore, 'SQL-lagret är öppet');

for ($i = 1; $i <= 3; $i++) {
    $id = 'c' . $i;
    $store->upsertMessage($id . 'm', [
        'id' => $id . 'm',
        'thread_id' => $id,
        'from' => 'kund' . $i . '@exempel.test',
        'from_name' => 'Kund ' . $i,
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Klick ' . $id,
        'body' => 'Kort text ' . $i,
        'received_at' => sprintf('2024-07-%02dT10:00:00+00:00', $i),
        'source' => 'graph-jarfalla',
        'labels' => ['type' => 'Support', 'product' => 'batteri'],
    ]);
    $store->upsertThread($id, [
        'status' => 'open',
        'tags' => [],
        'assignee' => '',
        'read' => [],
        'company' => 'jarfalla',
        'updated_at' => sprintf('2024-07-%02dT10:00:00+00:00', $i),
    ]);
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-inkorg', 'scope' => 'egna', 'ix_part' => 'list'];
$menu = jeab_inkorg_fast_scope_menu('egna');
$keyBefore = jeab_inkorg_fast_count_key($menu, 'jarfalla', jeab_inkorg_me_id());
$GLOBALS['_JEAB_INKORG_COUNT_COMPUTE'] = 0;
$GLOBALS['_JEAB_INKORG_LIST_REBUILD'] = 0;
unset($GLOBALS['_JEAB_IX_COMPUTE'], $GLOBALS['_JEAB_TIMING'], $GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP']);
$list = jeab_inkorg_fast_prepare(true, 'list');
cc((int) $GLOBALS['_JEAB_INKORG_COUNT_COMPUTE'] === 0, 'ix_part=list kör inte fast_counts(compute)');
cc((int) ($GLOBALS['_JEAB_INKORG_LIST_REBUILD'] ?? 0) === 0, 'ix_part=list bygger inte om listan');
cc(jeab_inkorg_compute_header_value() === '0', 'ix_part=list sätter X-Ix-Compute till 0');
cc(isset($GLOBALS['_JEAB_TIMING']['sql_list_sync']), 'ix_part=list har tidsspannet sql_list_sync');
cc(isset($GLOBALS['_JEAB_TIMING']['fast_counts']), 'ix_part=list har tidsspannet fast_counts');
cc((string) ($GLOBALS['_JEAB_INKORG_LIST_SYNC'] ?? '') === 'skip', 'listan hoppar över ombyggnad när stämpeln saknas');

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'id' => 'c1', 'ix_part' => 'thread'];
unset($GLOBALS['_JEAB_IX_COMPUTE'], $GLOBALS['_JEAB_TIMING']);
$thread = jeab_inkorg_fast_prepare(true, 'thread');
cc((int) $GLOBALS['_JEAB_INKORG_COUNT_COMPUTE'] === 0, 'ix_part=thread kör inte fast_counts(compute)');
cc((int) ($GLOBALS['_JEAB_INKORG_LIST_REBUILD'] ?? 0) === 0, 'ix_part=thread bygger inte om listan');
cc(jeab_inkorg_compute_header_value() === '0', 'ix_part=thread sätter X-Ix-Compute till 0');
cc(is_array($thread['open'] ?? null) && (string) ($thread['open']['id'] ?? '') === 'c1', 'ix_part=thread öppnar ärendet');
cc(isset($GLOBALS['_JEAB_TIMING']['sql_open']), 'ix_part=thread har tidsspannet sql_open');

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-inkorg', 'scope' => 'egna', 'ix_part' => 'counts'];
unset($GLOBALS['_JEAB_IX_COMPUTE'], $GLOBALS['_JEAB_TIMING'], $GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP']);
$bag = jeab_inkorg_fast_counts_refresh();
cc((int) $GLOBALS['_JEAB_INKORG_COUNT_COMPUTE'] === 1, 'ix_part=counts räknar vykorten');
cc((int) ($GLOBALS['_JEAB_INKORG_LIST_REBUILD'] ?? 0) === 1, 'ix_part=counts får bygga om listan');
cc((int) (($bag['counts'] ?? [])['v-inkorg'] ?? 0) >= 1, 'ombyggnaden fyller Inkorg');
$header = jeab_inkorg_compute_header_value();
cc(str_contains($header, 'fast_counts') && str_contains($header, 'sql_list_sync'), 'ix_part=counts sätter X-Ix-Compute, fick ' . $header);
cc(isset($GLOBALS['_JEAB_TIMING']['fast_counts']) && isset($GLOBALS['_JEAB_TIMING']['sql_list_sync']), 'ix_part=counts har tidsspann för räkning och lista');
cc($store->listStamp() !== '', 'ombyggnaden sätter liststämpeln');

$keyAfterBuild = jeab_inkorg_fast_count_key($menu, 'jarfalla', jeab_inkorg_me_id());
cc($keyAfterBuild === $keyBefore, 'räknarnyckeln följer inte meddelandenas fingerprint');

$rebuild = (int) $GLOBALS['_JEAB_INKORG_LIST_REBUILD'];
jeab_inkorg_assign('c1', 'Elin');
jeab_inkorg_mark_read('c2');
jeab_inkorg_thread_set('c3', 'closed', null, false);
cc((int) ($GLOBALS['_JEAB_INKORG_LIST_REBUILD'] ?? 0) === $rebuild, 'läst, etikett och stäng bygger inte om listan');
$fp = jeab_inkorg_messages_fp() . '|' . jeab_inkorg_threads_fp();
cc($store->listStamp() === $fp, 'skrivningen flyttar stämpeln så nästa lista slipper byggas om');
$keyAfterWrite = jeab_inkorg_fast_count_key($menu, 'jarfalla', jeab_inkorg_me_id());
cc($keyAfterWrite === $keyBefore, 'en trådskrivning byter inte räknarnyckeln');
$listed = $store->listForCompany('jarfalla');
$byId = [];
foreach ($listed as $row) {
    if (is_array($row)) {
        $byId[(string) ($row['id'] ?? '')] = $row;
    }
}
cc((string) ($byId['c1']['assignee'] ?? '') === 'Elin', 'tilldelningen syns på den uppdaterade listraden');
cc((string) ($byId['c3']['status'] ?? '') === 'closed', 'stängningen syns på den uppdaterade listraden');

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-inkorg', 'scope' => 'egna', 'ix_part' => 'list'];
unset($GLOBALS['_JEAB_IX_COMPUTE'], $GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP'], $GLOBALS['_JEAB_INKORG_LIST_SYNC']);
$GLOBALS['_JEAB_INKORG_COUNT_COMPUTE'] = 0;
$again = jeab_inkorg_fast_prepare(true, 'list');
cc((int) $GLOBALS['_JEAB_INKORG_COUNT_COMPUTE'] === 0, 'nästa listklick räknar inte om');
cc((int) ($GLOBALS['_JEAB_INKORG_LIST_REBUILD'] ?? 0) === $rebuild, 'nästa listklick bygger inte om');
cc(jeab_inkorg_compute_header_value() === '0', 'nästa listklick har X-Ix-Compute 0');
cc(str_contains((string) ($again['html'] ?? ''), 'data-ix-id="c2"'), 'listan visar raderna utan ombyggnad');

$store->clearListStamp();
unset($GLOBALS['_JEAB_IX_COMPUTE'], $GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP'], $GLOBALS['_JEAB_INKORG_LIST_SYNC']);
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-inkorg', 'scope' => 'egna', 'ix_part' => 'list'];
jeab_inkorg_fast_prepare(true, 'list');
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'id' => 'c1', 'ix_part' => 'thread'];
jeab_inkorg_fast_prepare(true, 'thread');
cc((int) ($GLOBALS['_JEAB_INKORG_LIST_REBUILD'] ?? 0) === $rebuild, 'list och tråd bygger inte om även när stämpeln är borta');
cc(jeab_inkorg_compute_header_value() === '0', 'klicket efter tömd stämpel har X-Ix-Compute 0');

unset($GLOBALS['_JEAB_IX_COMPUTE'], $GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP'], $GLOBALS['_JEAB_INKORG_LIST_SYNC']);
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'id' => 'c1', 'view' => 'v-inkorg', 'scope' => 'egna'];
$painted = jeab_inkorg_fast_prepare(true, '');
cc((int) ($GLOBALS['_JEAB_INKORG_LIST_REBUILD'] ?? 0) === $rebuild, 'sidöppning med tom stämpel bygger inte listan');
cc((int) $GLOBALS['_JEAB_INKORG_COUNT_COMPUTE'] === 0, 'en hel sidöppning räknar inte om vykorten');
cc($store->listStamp() === '', 'sidöppningen lämnar stämpeln till tick');
cc(str_contains((string) ($painted['html'] ?? ''), 'data-ix-id="c2"'), 'sidöppningen ritar den lagrade listan');

unset($GLOBALS['_JEAB_IX_COMPUTE'], $GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP']);
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'ix_part' => 'counts'];
jeab_inkorg_fast_counts_refresh();
cc((int) ($GLOBALS['_JEAB_INKORG_LIST_REBUILD'] ?? 0) === $rebuild + 1, 'counts bygger listan när stämpeln saknas');

$store->clearListStamp();
unset($GLOBALS['_JEAB_IX_COMPUTE'], $GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP'], $GLOBALS['_JEAB_INKORG_LIST_SYNC']);
jeab_inkorg_fast_counts_refresh();
cc((int) ($GLOBALS['_JEAB_INKORG_LIST_REBUILD'] ?? 0) === $rebuild + 2, 'counts bygger om när stämpeln saknas');

file_put_contents($tmp . '/router.php', <<<'PHP'
<?php
$tmp = getenv('JEAB_CLICK_TMP');
$root = getenv('JEAB_ROOT');
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
require $root . '/src/bootstrap.php';
require $root . '/src/layout.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['jeab_email'] = 'saljare3@jarfalla.example.test';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla'];
$qs = [];
parse_str((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY), $qs);
if (is_array($qs)) {
    $_GET = array_merge($_GET, $qs);
}
require $root . '/src/pages/inkorg.php';
PHP);
$port = 0;
for ($try = 8311; $try < 8330; $try++) {
    $socket = @stream_socket_server('tcp://127.0.0.1:' . $try, $errno, $errstr);
    if ($socket === false) {
        continue;
    }
    fclose($socket);
    $port = $try;
    break;
}
cc($port > 0, 'en lokal port är ledig');
$proc = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, $tmp . '/router.php'],
    [1 => ['file', $tmp . '/server.log', 'a'], 2 => ['file', $tmp . '/server.log', 'a']],
    $pipes,
    $tmp,
    ['JEAB_CLICK_TMP' => $tmp, 'JEAB_ROOT' => $root, 'JEAB_DISABLE_SQLITE' => '0']
);
cc(is_resource($proc), 'php-servern startade');
$pull = static function (string $path) use ($port): array {
    $code = 0;
    $body = '';
    $headers = '';
    for ($i = 0; $i < 20; $i++) {
        $curl = curl_init('http://127.0.0.1:' . $port . $path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 8]);
        $raw = (string) curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $size = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        curl_close($curl);
        $headers = substr($raw, 0, $size);
        $body = substr($raw, $size);
        if ($code === 200 && $body !== '') {
            break;
        }
        usleep(150000);
    }

    return [$code, $headers, $body];
};
[$code, $headers, $body] = $pull('/?ix_part=list&view=v-inkorg&scope=egna');
cc($code === 200 && stripos($headers, 'X-Ix-Compute: 0') !== false, 'curl list har X-Ix-Compute 0, fick ' . $code . ' ' . trim($headers));
cc(stripos($headers, 'sql_list_sync;dur=') !== false, 'curl list har Server-Timing sql_list_sync');
cc(!str_contains($body, '"busy"'), 'curl list är list-JSON');
[$code, $headers, $body] = $pull('/?ix_part=thread&id=c1');
cc($code === 200 && stripos($headers, 'X-Ix-Compute: 0') !== false, 'curl thread har X-Ix-Compute 0, fick ' . $code);
cc(stripos($headers, 'sql_open;dur=') !== false, 'curl thread har Server-Timing sql_open');
$pdo->exec("DELETE FROM inkorg_count_cache WHERE cache_key NOT IN ('__stamp', '__sources')");
[$code, $headers] = $pull('/?ix_part=counts&view=v-inkorg&scope=egna');
cc($code === 200 && stripos($headers, 'X-Ix-Compute:') !== false && stripos($headers, 'fast_counts') !== false, 'curl counts har X-Ix-Compute fast_counts, fick ' . trim($headers));
cc(stripos($headers, 'fast_counts;dur=') !== false, 'curl counts har Server-Timing fast_counts');
if (is_resource($proc)) {
    proc_terminate($proc);
    proc_close($proc);
}

echo "click compute ok\n";
