<?php

declare(strict_types=1);

/**
 * PR 18: Inkorg-uppföljning. Påhittade adresser. Graph anropas bara via testkrok.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-ix-follow-' . bin2hex(random_bytes(4));
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
    'inkorg' => ['storage' => 'sql'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ix18(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/src/pages/inkorg.php');
$logic = (string) file_get_contents($root . '/src/inkorg.php');
$css = (string) file_get_contents($root . '/assets/css/inkorg.css');
$index = (string) file_get_contents($root . '/index.php');

ix18(!str_contains($page, 'jeab_inkorg_mark_read('), 'sidan markerar inte läst vid GET');
ix18(str_contains($page, 'jeab_request_is_prefetch'), 'förhämtning får inte tända läst');
ix18(str_contains($page, 'jeabInkorgCachePrefetch') && str_contains($page, 'jeabInkorgOpenReadPlan'), 'cachad förhämtning postar läst vid riktig öppning');
$previewAt = strpos($page, 'data-panel-preview="1"');
$previewEnd = $previewAt === false ? false : strpos($page, '</form>', $previewAt);
$previewSlice = ($previewAt === false || $previewEnd === false) ? '' : substr($page, $previewAt, $previewEnd - $previewAt);
ix18(
    str_contains($previewSlice, 'name="product"')
    && str_contains($previewSlice, 'name="force_product" value="1"')
    && str_contains($previewSlice, 'name="template_only" value="1"')
    && str_contains($previewSlice, 'name="dry" value="1"')
    && str_contains($previewSlice, '[data-case-product]')
    && str_contains($previewSlice, '>Förhandsgranska</button>'),
    'panelens förhandsgranskning skickar vald produkt'
);
ix18(str_contains($page, 'inputmode="email"'), 'CC är en lista, inte ett ensamt mejlfält');
ix18(str_contains($page, 'name="force_product" value="1"') && str_contains($page, 'data-send-quote="1"'), 'menyns offert tar med produkt');
ix18(str_contains($page, '>Förhandsgranska</button>') && str_contains($page, 'Förhandsgranska offert'), 'förhandsgranskning syns och menyraden finns kvar');
ix18(str_contains($page, 'data-trash="1"') && str_contains($page, "data-busy')==='1'"), 'papperskorgen låser dubbelklick');
ix18(str_contains($page, 'enctype="multipart/form-data"') && str_contains($page, 'name="files[]"'), 'svar kan ha bilaga');
ix18(str_contains($page, 'inkorg.draft_undo'), 'borttaget utkast kan ångras');
ix18(str_contains($page, 'jeab_inkorg_thread_message_cap'), 'tråden visar fler än 40 mejl');
ix18(str_contains($index, "'inkorg.draft_undo'"), 'ångra-utkast är en riktig åtgärd');
ix18(jeab_inkorg_thread_message_cap() === 200, 'taket är 200 mejl');

$nodeFile = $tmp . '/prefetch-read.js';
file_put_contents($nodeFile, <<<'JS'
const api = require(process.argv[2]);
function ok(cond, message) {
  if (!cond) {
    console.error('FAIL ' + message);
    process.exit(1);
  }
  console.log('OK  ' + message);
}
const cached = api.jeabInkorgCachePrefetch({
  ok: true,
  html: '<section id="ix-conv">Hej</section>',
  read_on_open: false,
  mark_read: true,
  id: 't1'
});
ok(!Object.prototype.hasOwnProperty.call(cached, 'read_on_open') && cached.prefetched === true && cached.mark_read === true, 'förhämtningens cache saknar read_on_open');
const opened = api.jeabInkorgOpenReadPlan(cached, '1');
ok(opened.send === true && opened.refetch === false, 'prefetch does not mark read, real open of prefetched case does');
ok(api.jeabInkorgOpenReadPlan(cached, false).send === false, 'läst rad postar inte igen');
const frozen = api.jeabInkorgCachePrefetch({ html: '<p>import</p>', read_on_open: false, mark_read: false });
ok(api.jeabInkorgOpenReadPlan(frozen, true).send === false && api.jeabInkorgOpenReadPlan(frozen, true).refetch === false, 'importerat ärende förblir oläst');
ok(api.jeabInkorgOpenReadPlan({ read_on_open: true }, true).refetch === false && api.jeabInkorgOpenReadPlan({ read_on_open: true }, true).send === true, 'vanligt svar markerar läst utan ny hämtning');
ok(api.jeabInkorgOpenReadPlan({ read_on_open: false }, true).send === false, 'ett vanligt nej lämnas ifred');
JS);
$nodeProc = proc_open(
    ['node', $nodeFile, $root . '/assets/js/inkorg-nav.js'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $nodePipes
);
$nodeOut = stream_get_contents($nodePipes[1]);
$nodeErr = stream_get_contents($nodePipes[2]);
fclose($nodePipes[1]);
fclose($nodePipes[2]);
echo $nodeOut;
ix18(proc_close($nodeProc) === 0, 'nav-planen för förhämtad läsning ' . trim((string) $nodeErr));

$name = "Ada\u{00F7}";
ix18(jeab_inkorg_trim_sep($name) === $name, 'kundraden behåller sista byten');
ix18(trim($name, ' ·') !== $name, 'byte-trim skulle ha ätit tecknet');
ix18(jeab_inkorg_trim_sep(' · ' . $name . ' · ') === $name, 'avskiljare runt namnet tas bort');

$pdo = jeab_pdo_connect_sqlite();
ix18(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan går att sätta');
$store = jeab_inkorg_pdo_store();
ix18($store instanceof JeabPdoInkorgStore, 'SQL-lagret är öppet');
$store->upsertThread('t-undo', [
    'id' => 't-undo',
    'status' => 'open',
    'tags' => [],
    'company' => 'jarfalla',
    'drafts' => [[
        'id' => 'd-undo',
        'kind' => 'reply',
        'state' => 'pending',
        'body' => 'Hej från test',
        'author' => 'Test',
        'cc' => [],
        'status_after' => 'open',
        'created_at' => '2026-10-01T10:00:00+00:00',
    ]],
    'updated_at' => '2026-10-01T10:00:00+00:00',
]);
$removed = jeab_inkorg_draft_delete('t-undo', 'd-undo');
ix18(is_array($removed) && ($removed['body'] ?? '') === 'Hej från test', 'borttaget utkast kommer tillbaka som data');
$token = jeab_inkorg_draft_undo_remember('t-undo', $removed);
$shown = jeab_inkorg_draft_undo_for('t-undo');
ix18(is_array($shown) && ($shown['token'] ?? '') === $token, 'ångra syns på ärendet');
$_POST = ['token' => $token, 'thread' => 't-undo', 'view' => 'v-inkorg'];
ix18(jeab_inkorg_action('inkorg.draft_undo') === 'Utkastet är tillbaka.', 'ångra lägger tillbaka utkastet');
$stored = $store->threadsByIds(['t-undo']);
$back = null;
foreach ((array) ($stored['t-undo']['drafts'] ?? []) as $draft) {
    if (is_array($draft) && ($draft['id'] ?? '') === 'd-undo') {
        $back = $draft;
    }
}
ix18(is_array($back) && ($back['body'] ?? '') === 'Hej från test', 'utkastet ligger i ärendet igen');
ix18(jeab_inkorg_draft_undo_pull($token) === null, 'ångra kan bara användas en gång');

$_SERVER['HTTP_PURPOSE'] = 'prefetch';
$_POST = ['thread' => 't-undo', 'read' => '1'];
ix18(jeab_inkorg_action('inkorg.read') === 'Oläst.', 'förhämtning markerar inte läst');
ix18((string) (($GLOBALS['_JEAB_LAST_ROW']['skipped'] ?? '')) === 'prefetch', 'förhämtningen säger ifrån');
unset($_SERVER['HTTP_PURPOSE']);

function ix18_child(string $root, string $tmp, string $code): array
{
    $runner = $tmp . '/child.php';
    file_put_contents($runner, "<?php\n" . $code);
    $proc = proc_open(
        [PHP_BINARY, $runner],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        ['JEAB_FOLLOW_TMP' => $tmp, 'JEAB_ROOT' => $root, 'JEAB_DISABLE_SQLITE' => '0']
    );
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($proc), 'out' => (string) $out, 'err' => (string) $err];
}

$boot = <<<'PHP'
$tmp = getenv('JEAB_FOLLOW_TMP');
$root = getenv('JEAB_ROOT');
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
require $root . '/src/bootstrap.php';
require $root . '/src/layout.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['HTTP_PURPOSE'] = 'prefetch';
PHP;
$prefetched = ix18_child($root, $tmp, $boot . <<<'PHP'
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'id' => 't-undo', 'ix_part' => 'thread'];
require getenv('JEAB_ROOT') . '/src/pages/inkorg.php';
PHP);
$pj = json_decode($prefetched['out'], true);
ix18($prefetched['code'] === 0 && is_array($pj) && ($pj['ok'] ?? false) === true, 'förhämtad tråd-JSON svarar ' . trim($prefetched['err']));
ix18(($pj['read_on_open'] ?? true) === false && ($pj['mark_read'] ?? false) === true, 'förhämtningen ber inte klienten markera läst');
$afterPrefetch = $store->threadsByIds(['t-undo']);
ix18((array) ($afterPrefetch['t-undo']['read'] ?? []) === [], 'förhämtad öppning skriver inte läst');

$passwords = $tmp . '/passwords.json';
file_put_contents($passwords, json_encode([
    'secret' => 'follow-test-secret',
    'hashes' => [
        ['hash' => 'sam-hash-fixture', 'email' => 'demo@example.test'],
    ],
], JSON_UNESCAPED_UNICODE));
putenv('JEAB_PASSWORDS_FILE=' . $passwords);
require_once $root . '/tests/sam_l_login.php';
jeab_test_login_sam_l();
ix18(jeab_auth_ok() && jeab_inkorg_me_id() === 'SamL', 'Sam L är inloggad för läst-markering');
$_POST = ['thread' => 't-undo', 'read' => '1'];
ix18(jeab_inkorg_action('inkorg.read') === 'Markerat som läst.', 'riktig öppning av förhämtat ärende markerar läst');
$marked = $store->threadsByIds(['t-undo']);
ix18(isset($marked['t-undo']['read']['SamL']) && (string) $marked['t-undo']['read']['SamL'] !== '', 'läst ligger på personen');

$GLOBALS['JEAB_INKORG_FILE_TEST'] = [
    ['filename' => 'a.txt', 'content' => 'hej', 'content_type' => 'text/plain'],
    ['filename' => '../b.txt', 'content' => 'x', 'content_type' => 'text/plain'],
];
$files = jeab_inkorg_posted_files();
ix18(count($files) === 2 && ($files[1]['filename'] ?? '') === 'b.txt', 'bilagenamn tappar sökväg');
$GLOBALS['JEAB_INKORG_FILE_TEST'] = [
    ['filename' => 'stor.bin', 'content' => str_repeat('a', 8 * 1024 * 1024 + 1), 'content_type' => 'application/octet-stream'],
];
$tooBig = false;
try {
    jeab_inkorg_posted_files();
} catch (InvalidArgumentException) {
    $tooBig = true;
}
ix18($tooBig, 'en bilaga över 8 MB stoppas');
unset($GLOBALS['JEAB_INKORG_FILE_TEST']);

$seen = null;
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url, ?array $body) use (&$seen): array {
    $seen = $body;
    return ['code' => 202, 'body' => []];
};
$GLOBALS['_JEAB_GRAPH_ATTACHMENTS'] = [[
    'filename' => 'offert.txt',
    'content' => 'pdf-bytes',
    'content_type' => 'text/plain',
]];
$sent = jeab_graph_send('jarfalla', 'kund.follow@gmail.com', 'Hej', 'Text');
ix18(!empty($sent['ok']), 'testkroken skickar, fick ' . json_encode($sent));
$attached = (string) ($seen['message']['attachments'][0]['name'] ?? '');
ix18($attached === 'offert.txt', 'Graph-mejlet har bilagan');
ix18(($seen['message']['attachments'][0]['contentBytes'] ?? '') === base64_encode('pdf-bytes'), 'bilagan är base64');
unset($GLOBALS['_JEAB_GRAPH_ATTACHMENTS'], $GLOBALS['_JEAB_GRAPH_HTTP']);

$quote = strstr($logic, "case 'inkorg.send_quote':");
$quote = is_string($quote) ? substr($quote, 0, (int) strpos($quote, "case 'inkorg.rule_test':")) : '';
ix18(str_contains($quote, 'if (!$dry)'), 'verklig offert lämnar det stängda ärendet');
ix18(str_contains($logic, "jeab_outbound_record(\$company"), 'nytt mejl sparas som ärende');

ix18(str_contains($css, 'body.page-inkorg .ix-newmail input') && str_contains($css, 'font-size: 16px'), 'nytt mejl zoomar inte på mobil');
ix18(str_contains($css, 'min-height: 44px'), 'träffytor är minst 44 px');
ix18(str_contains($css, 'padding-bottom: calc(12px + 30vh)'), 'sista mejlet kan rullas ovanför svarsrutan');
ix18(str_contains($css, 'position: sticky'), 'svarsrutan sitter kvar');

echo "OK\n";
