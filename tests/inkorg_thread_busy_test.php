<?php

declare(strict_types=1);

/**
 * ix_part=thread ska rita ärendet även när db-platsen svarar busy/stale.
 * Klienten slutar efter två omförsök eller 10 s och feltexten ligger i inkorg-nav.js.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-ixbusy-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
$passwords = $tmp . '/passwords.json';
file_put_contents($passwords, json_encode([
    'secret' => 'busy-test-secret',
    'hashes' => [
        ['hash' => 'sam-hash-fixture', 'email' => 'demo@example.test'],
    ],
], JSON_UNESCAPED_UNICODE));
putenv('JEAB_PASSWORDS_FILE=' . $passwords);
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

function busy_ok(bool $ok, string $message): void
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
$boot = (string) file_get_contents($root . '/src/bootstrap.php');
$enterAt = strpos($boot, 'function jeab_inkorg_part_enter');
$enter = $enterAt === false ? '' : substr($boot, $enterAt, 1400);
busy_ok(str_contains($enter, "\$partNow === 'thread'") && str_contains($enter, "\$slot = 'open'"), 'tråd-delen ritar när platsen är upptagen');
busy_ok(str_contains($nav, 'Ärendet kunde inte öppnas.') && str_contains($nav, 'data-ix-retry'), 'feltexten ligger i inkorg-nav.js');
busy_ok(str_contains($page, 'jeabInkorgThreadFailHtml') && str_contains($page, 'jeabInkorgThreadBusyPlan') && str_contains($page, "jeab_asset_url('assets/js/inkorg-nav.js')"), 'sidan anropar felrutan i den laddade js-filen');
busy_ok(!str_contains($page, "String(j.rev) !== now"), 'en annan ix_rev slänger inte svaret');

$node = $tmp . '/busy-plan.js';
file_put_contents($node, <<<'JS'
const api = require(process.argv[2]);
function ok(cond, message) {
  if (!cond) {
    console.error('FAIL ' + message);
    process.exit(1);
  }
  console.log('OK  ' + message);
}
const html = api.jeabInkorgThreadFailHtml();
ok(html.indexOf('Ärendet kunde inte öppnas.') !== -1 && html.indexOf('data-ix-retry') !== -1, 'nav-filen har feltext och Försök igen');
ok(api.jeabInkorgThreadBusyPlan(1, 0).retry === true, 'första busy-svaret försöks igen');
ok(api.jeabInkorgThreadBusyPlan(2, 0).retry === true, 'andra busy-svaret är sista omförsöket');
ok(api.jeabInkorgThreadBusyPlan(3, 0).retry === false && api.jeabInkorgThreadBusyPlan(3, 0).fail === true, 'tredje busy-svaret stannar');
ok(api.jeabInkorgThreadBusyPlan(2, 10000).fail === true, '10 s stannar även när omförsök återstår');
const rev = api.jeabInkorgThreadRevDecision('2026-10-22T15:04:12+02:00|ffca2ddc06852c04', {
  ok: true,
  html: '<section id="ix-conv">Test Support4</section>',
  rev: '2099-01-01T00:00:00+02:00|annat'
});
ok(rev.paint === true && rev.reason === 'rev', 'HTML ritas trots annan revision');
const locked = api.jeabInkorgThreadRevDecision('x', { ok: true, busy: 1, retry: 1, stale: 1 });
ok(locked.paint === false && locked.busy === true, 'busy utan html är inte en tråd');
JS);
$nodeProc = proc_open(
    ['node', $node, $root . '/assets/js/inkorg-nav.js'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $nodePipes
);
$nodeOut = stream_get_contents($nodePipes[1]);
$nodeErr = stream_get_contents($nodePipes[2]);
fclose($nodePipes[1]);
fclose($nodePipes[2]);
echo $nodeOut;
busy_ok(proc_close($nodeProc) === 0, 'nav-planen ' . trim((string) $nodeErr));

$pdo = jeab_pdo_connect_sqlite();
busy_ok(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan går att sätta');
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
    'labels' => ['type' => 'Oklassad', 'is_test' => true],
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
    'updated_at' => '2026-10-22T15:04:12+02:00',
]);

$held = [];
for ($i = 0; $i < 2; $i++) {
    $slot = jeab_db_slot_acquire(2);
    busy_ok(is_resource($slot), 'testet håller plats ' . $i);
    $held[] = $slot;
}

require_once $root . '/tests/sam_l_login.php';
jeab_test_login_sam_l();
$cookie = (string) $_COOKIE['jeab_auth'];
busy_ok($cookie !== '', 'testet kan utfärda en inloggningskaka');
$runner = $tmp . '/child.php';
file_put_contents($runner, <<<'PHP'
<?php
$tmp = getenv('JEAB_BUSY_TMP');
$root = getenv('JEAB_ROOT');
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
putenv('JEAB_PASSWORDS_FILE=' . getenv('JEAB_PASSWORDS_FILE'));
require $root . '/src/bootstrap.php';
require $root . '/src/layout.php';
$_COOKIE['jeab_auth'] = (string) getenv('JEAB_BUSY_COOKIE');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
$_GET = [
    'p' => 'inkorg',
    'box' => 'jarfalla',
    'scope' => 'allas',
    'id' => 'ffca2ddc06852c04',
    'ix_open' => 'ffca2ddc06852c04',
    'ix_part' => 'thread',
    'ix_rev' => '2026-10-22T15:04:12+02:00|ffca2ddc06852c04',
];
jeab_inkorg_part_enter();
require $root . '/src/pages/inkorg.php';
PHP);
$proc = proc_open(
    [PHP_BINARY, $runner],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    null,
    [
        'JEAB_BUSY_TMP' => $tmp,
        'JEAB_ROOT' => $root,
        'JEAB_DISABLE_SQLITE' => '0',
        'JEAB_PASSWORDS_FILE' => $passwords,
        'JEAB_BUSY_COOKIE' => $cookie,
    ]
);
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($proc);
foreach ($held as $fh) {
    jeab_db_slot_release($fh);
}
$json = json_decode((string) $out, true);
busy_ok($code === 0 && trim((string) $err) === '', 'tråd under lås skriver inga varningar ' . trim((string) $err));
busy_ok(is_array($json) && ($json['ok'] ?? false) === true && empty($json['busy']), 'upptagen plats svarar inte busy/stale utan html');
$html = (string) ($json['html'] ?? '');
busy_ok(str_contains($html, 'Test Support4') && str_contains($html, 'id="ix-conv"'), 'Test Support4 ritas trots busy-lås och ix_rev');
echo "inkorg thread busy: ok\n";
