<?php

declare(strict_types=1);

/** Lista: Historik visar 20, övriga vyer 50, andra laddningen läser inte mejlfilen igen. */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-inkfast-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require $root . '/src/bootstrap.php';

function fl(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$messages = [];
$threads = [];
for ($i = 1; $i <= 27; $i++) {
    $id = sprintf('t%02d', $i);
    $closed = $i <= 25;
    $secret = $i === 26 ? str_repeat('vanlig text ', 30) . 'UNIK-LISTTOKEN-99' : 'Kort rad ' . $i;
    $messages[] = [
        'id' => $id . 'm',
        'thread_id' => $id,
        'from' => 'kund' . $i . '@exempel.test',
        'from_name' => 'Kund ' . $i,
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Ärende ' . $id,
        'body' => $secret,
        'received_at' => sprintf('2024-06-%02dT10:00:00+00:00', min($i, 28)),
        'source' => 'graph-jarfalla',
        'labels' => ['type' => 'Support'],
    ];
    $threads[$id] = [
        'status' => $closed ? 'closed' : 'open',
        'tags' => [],
        'assignee' => '',
        'read' => [],
        'updated_at' => sprintf('2024-06-%02dT10:00:00+00:00', min($i, 28)),
    ];
}
$dir = jeab_inkorg_dir();
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
file_put_contents($dir . '/messages.json', json_encode(['messages' => $messages], JSON_UNESCAPED_UNICODE));
file_put_contents($dir . '/threads.json', json_encode(['threads' => $threads], JSON_UNESCAPED_UNICODE));
jeab_inkorg_rules();

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$first = jeab_inkorg_fast_prepare();
fl((string) ($first['view']['id'] ?? '') !== 'v-historik' && (string) ($first['view']['id'] ?? '') !== 'v-allt', 'standardvyn är varken Historik eller Alla');
fl(substr_count((string) $first['html'], 'class="item ix-it') <= 50, 'en vanlig vy hämtar högst 50 rader');
fl(!str_contains((string) $first['html'], 'UNIK-LISTTOKEN-99'), 'listan tar inte med texten bortom förhandsraden');

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-historik'];
$hist = jeab_inkorg_fast_prepare(true);
fl(substr_count((string) $hist['html'], 'class="item ix-it') === 20, 'Historik visar 20 rader');
fl(str_contains((string) $hist['html'], 'Visa fler') && str_contains((string) $hist['html'], 'Nästa sida'), 'resten nås med Visa fler');
fl(str_contains((string) $hist['html'], 'Ärende t25') && !str_contains((string) $hist['html'], 'Ärende t01'), 'de 20 senaste ligger först');

$GLOBALS['_JEAB_INKORG_MSG_READS'] = 0;
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-historik'];
jeab_inkorg_fast_prepare(false);
fl((int) ($GLOBALS['_JEAB_INKORG_MSG_READS'] ?? 0) === 0, 'andra laddningen läser inte messages.json');

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-historik', 'q' => 'Ärende t02'];
$found = jeab_inkorg_fast_prepare(true);
fl(str_contains((string) $found['html'], 'Ärende t02'), 'sök på serversidan hittar en äldre historikrad');

$path = $dir . '/threads.json';
jeab_inkorg_mark_read('t26', 'SamL', '2024-06-26T10:00:00+00:00');
$hash = md5((string) file_get_contents($path));
jeab_inkorg_mark_read('t26', 'SamL', '2024-06-26T10:00:00+00:00');
fl(md5((string) file_get_contents($path)) === $hash, 'redan läst ärende skrivs inte om');

$runner = $tmp . '/part.php';
file_put_contents($runner, <<<'PHP'
<?php
$GLOBALS['_JEAB_DATA_DIR'] = getenv('JEAB_FAST_DATA');
putenv('JEAB_DISABLE_SQLITE=1');
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-historik', 'ix_part' => 'list'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
require getenv('JEAB_ROOT') . '/src/bootstrap.php';
require getenv('JEAB_ROOT') . '/src/pages/inkorg.php';
PHP);
require_once __DIR__ . '/jeab_test_proc_env.php';
$proc = proc_open(
    [PHP_BINARY, $runner],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    null,
    jeab_test_proc_env(['JEAB_FAST_DATA' => $tmp, 'JEAB_ROOT' => $root, 'JEAB_DISABLE_SQLITE' => '1', 'JEAB_TEST_TEMP_SQLITE' => (string) getenv('JEAB_TEST_TEMP_SQLITE')])
);
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($proc);
$json = json_decode((string) $out, true);
fl($code === 0 && is_array($json) && ($json['ok'] ?? false) === true && str_contains((string) ($json['html'] ?? ''), 'ix-items') && !str_contains((string) $out, '<h1'), 'bakgrundsanropet är bara listans JSON' . ($err !== '' ? ' ' . trim($err) : ''));

echo "inkorg fast list: ok\n";
