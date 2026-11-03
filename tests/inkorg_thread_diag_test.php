<?php

declare(strict_types=1);

/**
 * Test Support4 (is_test, Support, ingen kontakt) ska ritas.
 * Ett fel i trådrutan loggas i felloggen och lämnar en kod, aldrig kundtext.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-ixdiag-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/fellogg', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp . '/fellogg';
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

function diag_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$nav = (string) file_get_contents($root . '/assets/js/inkorg-nav.js');
$page = (string) file_get_contents($root . '/src/pages/inkorg.php');
diag_ok(str_contains($nav, 'Ärendet kunde inte öppnas.') && str_contains($nav, 'ix-diag') && str_contains($nav, 'jeabInkorgThreadFailCode'), 'felkoden ritas från inkorg-nav.js');
diag_ok(str_contains($page, 'jeabInkorgThreadFailHtml(code)') && str_contains($page, 'jeab_inkorg_thread_part_fail'), 'sidan fångar tråden och visar koden');

$node = $tmp . '/diag-plan.js';
file_put_contents($node, <<<'JS'
const api = require(process.argv[2]);
function ok(cond, message) {
  if (!cond) {
    console.error('FAIL ' + message);
    process.exit(1);
  }
  console.log('OK  ' + message);
}
const html = api.jeabInkorgThreadFailHtml('ix_render');
ok(html.indexOf('Ärendet kunde inte öppnas.') !== -1 && html.indexOf('ix_render') !== -1 && html.indexOf('data-ix-retry') !== -1, 'felrutan har kod och Försök igen');
ok(api.jeabInkorgThreadFailHtml('Ada@example.com').indexOf('example') === -1, 'kundtext blir inte en kod');
ok(api.jeabInkorgThreadFailCode({ timeout: true }) === 'timeout', 'tiden syns som timeout');
ok(api.jeabInkorgThreadFailCode({ busy: true }) === 'busy', 'upptaget syns som busy');
const rev = api.jeabInkorgThreadRevDecision('old', { ok: false, diag: 'ix_render', html: '' });
ok(rev.paint === false && rev.diag === 'ix_render', 'tomt svar bär serverns kod');
JS);
$nodeProc = proc_open(['node', $node, $root . '/assets/js/inkorg-nav.js'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $nodePipes);
echo stream_get_contents($nodePipes[1]);
$nodeErr = stream_get_contents($nodePipes[2]);
fclose($nodePipes[1]);
fclose($nodePipes[2]);
diag_ok(proc_close($nodeProc) === 0, 'nav-koden ' . trim((string) $nodeErr));

$marked = [
    'id' => 'ffca2ddc06852c04',
    'labels' => ['type' => 'Support', 'is_test' => true],
    'test_lead' => true,
    'first' => ['from' => 'real.kund@example.com', 'from_name' => 'Ada Kund', 'body' => str_repeat('inte ett test. ', 100)],
    'messages' => [],
];
$_GET = ['p' => 'inkorg', 'ix_part' => 'thread'];
diag_ok(jeab_inkorg_thread_is_test($marked) === true, 'is_test räcker utan att läsa brödtexten');

file_put_contents(jeab_inkorg_dir() . '/contacts.json', json_encode([
    'contacts' => [
        'c-test' => ['name' => 'Test Support4', 'emails' => ['test.support4@example.test'], 'mobile' => '', 'address' => '', 'town' => ''],
    ],
], JSON_UNESCAPED_UNICODE));
$thread = [
    'id' => 'ffca2ddc06852c04',
    'labels' => ['type' => 'Support', 'is_test' => true],
    'test_lead' => true,
    'contact_id' => '',
    'company' => 'jarfalla',
    'first' => ['id' => 'ffca2ddc06852c04', 'from' => 'test.support4@example.test', 'from_name' => 'Test Support4', 'body' => 'Hej', 'labels' => ['type' => 'Support', 'is_test' => true]],
    'messages' => [['id' => 'ffca2ddc06852c04', 'from' => 'test.support4@example.test', 'from_name' => 'Test Support4', 'body' => 'Hej', 'labels' => ['type' => 'Support', 'is_test' => true]]],
];
diag_ok(jeab_inkorg_contact_for_thread($thread) === null, 'testärende i trådrutan läser inte hela kontaktboken');
$_GET['ix_part'] = '';
diag_ok(is_array(jeab_inkorg_contact_for_thread($thread)), 'hela sidan kan fortfarande hitta kontakten');

$pdo = jeab_pdo_connect_sqlite();
diag_ok(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan går att sätta');
$store = jeab_inkorg_pdo_store();
$store->upsertMessage('ffca2ddc06852c04', [
    'id' => 'ffca2ddc06852c04',
    'thread_id' => 'ffca2ddc06852c04',
    'from' => 'test.support4@example.test',
    'from_name' => 'Test Support4',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Test Support4',
    'body' => 'Oklassat testärende utan kontakt.',
    'received_at' => '2026-10-22T13:04:12+00:00',
    'source' => 'graph-jarfalla',
    'labels' => ['type' => 'Support', 'is_test' => true],
    'is_test' => true,
]);
$store->upsertThread('ffca2ddc06852c04', [
    'status' => 'open',
    'tags' => [],
    'assignee' => '',
    'read' => [],
    'company' => 'jarfalla',
    'contact_id' => '',
    'test_lead' => true,
    'drafts' => [[
        'id' => 'd1',
        'kind' => 'reply',
        'state' => 'pending',
        'author' => 'Test',
        'created_at' => '2026-10-22T13:05:00+00:00',
        'body' => 'Utkast',
        'cc' => 'inte-en-lista',
        'status_after' => '',
    ]],
    'updated_at' => '2026-10-22T15:04:12+02:00',
]);
$app = jeab_store();
$linked = jeab_create_reminder($app, 'Notlänk', '2026-10-22 09:00:00', 'Öppna id=ffca2ddc06852c04 i inkorgen', null, [
    'company' => 'jarfalla',
    'who' => 'Roberto',
]);
$app->saveReminder((int) $linked['id'], ['thread_id' => '']);
unset($GLOBALS['_JEAB_INKORG_THREAD_REM']);
$_GET = ['p' => 'inkorg', 'ix_part' => 'thread'];
jeab_sql_stats_reset();
$skipped = jeab_inkorg_thread_reminder($thread);
$skipLog = implode("\n", jeab_sql_stats()['log']);
diag_ok($skipped === null && !str_contains($skipLog, 'note LIKE'), 'testärende i trådrutan hoppar över anteckningssökningen');

$runner = $tmp . '/child.php';
file_put_contents($runner, <<<'PHP'
<?php
$tmp = getenv('JEAB_DIAG_TMP');
$root = getenv('JEAB_ROOT');
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp . '/fellogg';
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
require $root . '/src/bootstrap.php';
require $root . '/src/layout.php';
register_shutdown_function(static function (): void {
    $log = function_exists('jeab_sql_stats') ? implode("\n", jeab_sql_stats()['log']) : '';
    file_put_contents((string) getenv('JEAB_SQL_LOG'), $log);
});
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_GET = [
    'p' => 'inkorg',
    'box' => 'jarfalla',
    'scope' => 'allas',
    'id' => 'ffca2ddc06852c04',
    'ix_open' => 'ffca2ddc06852c04',
    'ix_part' => 'thread',
    'ix_rev' => '2026-10-22T15:04:12+02:00|ffca2ddc06852c04',
];
require $root . '/src/pages/inkorg.php';
PHP);
$sqlLog = $tmp . '/sql.log';
$proc = proc_open(
    [PHP_BINARY, $runner],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    null,
    ['JEAB_DIAG_TMP' => $tmp, 'JEAB_ROOT' => $root, 'JEAB_DISABLE_SQLITE' => '0', 'JEAB_SQL_LOG' => $sqlLog]
);
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($proc);
$json = json_decode((string) $out, true);
diag_ok($code === 0 && trim((string) $err) === '', 'tråden skriver inga varningar ' . trim((string) $err));
diag_ok(is_array($json) && ($json['ok'] ?? false) === true && empty($json['diag']) && empty($json['busy']), 'svaret är tråden, inte en felkod');
$html = (string) ($json['html'] ?? '');
diag_ok(str_contains($html, 'Test Support4') && str_contains($html, 'Support') && str_contains($html, 'id="ix-conv"'), 'Test Support4 ritas');
$renderLog = is_file($sqlLog) ? (string) file_get_contents($sqlLog) : '';
diag_ok(!str_contains($renderLog, 'note LIKE'), 'ritningen söker inte igenom anteckningar');

$fail = $tmp . '/fail.php';
file_put_contents($fail, <<<'PHP'
<?php
$tmp = getenv('JEAB_DIAG_TMP');
$root = getenv('JEAB_ROOT');
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp . '/fellogg';
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
require $root . '/src/bootstrap.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['p' => 'inkorg', 'id' => 'support4', 'ix_part' => 'thread'];
jeab_inkorg_thread_part_fail(new RuntimeException('ada@example.com kundtext'), 'ix_render');
PHP);
$failProc = proc_open(
    [PHP_BINARY, $fail],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $failPipes,
    null,
    ['JEAB_DIAG_TMP' => $tmp, 'JEAB_ROOT' => $root, 'JEAB_DISABLE_SQLITE' => '0']
);
$failOut = stream_get_contents($failPipes[1]);
$failErr = stream_get_contents($failPipes[2]);
fclose($failPipes[1]);
fclose($failPipes[2]);
$failCode = proc_close($failProc);
$failJson = json_decode((string) $failOut, true);
$log = (string) @file_get_contents($tmp . '/fellogg/app-errors.jsonl');
diag_ok($failCode === 0 && trim((string) $failErr) === '' && is_array($failJson) && ($failJson['diag'] ?? '') === 'ix_render' && ($failJson['html'] ?? null) === '', 'undantag blir en kod ' . trim((string) $failErr));
file_put_contents($fail, <<<'PHP'
<?php
$tmp = getenv('JEAB_DIAG_TMP');
$root = getenv('JEAB_ROOT');
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp . '/fellogg';
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
require $root . '/src/bootstrap.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_ACCEPT'] = 'text/html';
$_GET = ['p' => 'inkorg', 'id' => 'support4'];
jeab_inkorg_thread_part_fail(new RuntimeException('ada@example.com kundtext'), 'ix_render');
PHP);
$failProc = proc_open(
    [PHP_BINARY, $fail],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $failPipes,
    null,
    ['JEAB_DIAG_TMP' => $tmp, 'JEAB_ROOT' => $root, 'JEAB_DISABLE_SQLITE' => '0']
);
$pageOut = stream_get_contents($failPipes[1]);
$pageErr = stream_get_contents($failPipes[2]);
fclose($failPipes[1]);
fclose($failPipes[2]);
$pageCode = proc_close($failProc);
$pageTrim = trim((string) $pageOut);
diag_ok($pageCode === 0 && trim((string) $pageErr) === '' && json_decode($pageTrim) === null && str_contains($pageTrim, '<!doctype html') && !str_contains($pageTrim, 'ada@example.com'), 'direktadressens fel är HTML');
diag_ok(str_contains($log, 'ix_render') && str_contains($log, 'RuntimeException') && !str_contains($log, 'ada@example.com'), 'felloggen har koden och ingen kundtext');
echo "inkorg thread diag: ok\n";
