<?php

declare(strict_types=1);

/**
 * Einstein QA: bolagsbyte, Historik-klick, Information mot etikett, utf8mb4 och statusknapp.
 */

$root = dirname(__DIR__);

function qa(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$page = (string) file_get_contents($root . '/src/pages/inkorg.php');
$db = (string) file_get_contents($root . '/src/db.php');
qa(str_contains($db, 'SET NAMES utf8mb4 COLLATE utf8mb4_swedish_ci'), 'PDO sätter utf8mb4');
qa(str_contains($page, "preg_replace('/\\s*▾$/u', '', \$pillText)"), 'skrivskyddad status tar bort pilen med preg_replace');
qa(!str_contains($page, "trim(\$pillText, ' ▾')"), 'statusknappen använder inte trim-bytemasken');
$pill = "● Öppen ▾";
$broken = trim($pill, " ▾");
$fixed = (string) preg_replace('/\s*▾$/u', '', $pill);
qa($broken !== '● Öppen' && !mb_check_encoding($broken, 'UTF-8'), 'trim med ▾ klipper ●');
qa($fixed === '● Öppen' && mb_check_encoding($fixed, 'UTF-8'), 'preg_replace lämnar ● Öppen');
qa(str_contains($page, 'jeabInkorgClickPlan') && str_contains($page, 'jeabInkorgCompanyHref'), 'klicket använder nav-planen');
qa(str_contains($page, 'jeabInkorgResponseLive'), 'ett sent svar från fel bolag slängs');
qa(str_contains($page, 'history.pushState({ ix: kind }'), 'bolagsbyte och Historik skriver URL:en före hämtningen');

$nav = $root . '/assets/js/inkorg-nav.js';
$node = <<<'JS'
const nav = require(process.argv[1]);
const assert = require('assert');
const company = nav.jeabInkorgCompanyHref('http://local/index.php?p=inkorg&box=malmo&co=malmo&view=v-historik&id=abc&q=Anna');
const url = new URL(company, 'http://local/');
assert.strictEqual(url.searchParams.get('box'), 'malmo');
assert.strictEqual(url.searchParams.get('view'), 'v-inkorg');
assert.strictEqual(url.searchParams.get('id'), null);
assert.strictEqual(url.searchParams.get('q'), null);

const ignored = nav.jeabInkorgClickPlan({
  sameUrl: true,
  shownView: 'v-historik',
  nextView: 'v-historik',
  boxChanged: false,
  company: false,
  nextId: '',
  curId: ''
});
assert.strictEqual(ignored.ignore, true);

const first = nav.jeabInkorgClickPlan({
  sameUrl: true,
  shownView: 'v-inkorg',
  nextView: 'v-historik',
  boxChanged: false,
  company: false,
  nextId: '',
  curId: ''
});
assert.strictEqual(first.ignore, false);
assert.strictEqual(first.kind, 'list');
assert.strictEqual(first.reveal, false);
assert.strictEqual(first.pushFirst, true);
assert.strictEqual(first.fetch, true);

const back = nav.jeabInkorgClickPlan({
  sameUrl: false,
  shownView: 'v-inkorg',
  nextView: 'v-inkorg',
  boxChanged: false,
  company: false,
  nextId: '',
  curId: 'case-1'
});
assert.strictEqual(back.reveal, true);
assert.strictEqual(back.pushFirst, false);

const app = nav.jeabInkorgClickPlan({
  sameUrl: false,
  shownView: 'v-historik',
  nextView: 'v-inkorg',
  boxChanged: true,
  company: true,
  nextId: '',
  curId: ''
});
assert.strictEqual(app.kind, 'page');
assert.strictEqual(app.navigate, true);
assert.strictEqual(app.fetch, false);
assert.strictEqual(app.pushFirst, false);
assert.strictEqual(app.reveal, false);
assert.strictEqual(nav.jeabInkorgResponseLive('malmo', 'jarfalla', 'malmo'), false);
assert.strictEqual(nav.jeabInkorgResponseLive('malmo', 'malmo', 'malmo'), true);
assert.strictEqual(nav.jeabInkorgResponseLive('jarfalla', 'jarfalla', 'malmo'), false);
JS;
$proc = proc_open(
    [PHP_OS_FAMILY === 'Windows' ? 'node.exe' : 'node', '-e', $node, $nav],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);
qa(is_resource($proc), 'node startar');
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
qa(proc_close($proc) === 0, 'nav-planen för bolag och Historik: ' . trim($out . $err));

$tmp = sys_get_temp_dir() . '/jeab-qa-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require $root . '/src/bootstrap.php';

$supplier = [
    'id' => 'info-1',
    'status' => 'open',
    'company' => 'malmo',
    'labels' => ['type' => 'Information', 'intent' => 'information', 'confidence' => 10],
    'tags' => [],
    'last' => [
        'from' => 'info@leverantor.se',
        'from_name' => 'Leverantor',
        'subject' => 'Information om leverans',
        'labels' => ['type' => 'Information', 'intent' => 'information', 'confidence' => 10],
    ],
];
qa(jeab_inkorg_folder_thread($supplier, 'information'), 'Informationsetikett utan kund hamnar i Information');
qa(!jeab_inkorg_folder_thread($supplier, 'inkorg'), 'Informationsetikett ligger inte kvar i Inkorg');
qa(jeab_inkorg_list_category_mark($supplier) === 'Information', 'chippen säger Information när mappen gör det');

$customer = [
    'id' => 'kund-1',
    'status' => 'open',
    'company' => 'malmo',
    'labels' => ['type' => 'Information', 'intent' => 'information', 'confidence' => 90],
    'tags' => [],
    'last' => [
        'from' => 'kund@brevlada.se',
        'from_name' => 'Kund Ett',
        'subject' => 'Hej',
        'labels' => ['type' => 'Information', 'intent' => 'information', 'confidence' => 90],
    ],
];
qa(jeab_inkorg_folder_thread($customer, 'information') && !jeab_inkorg_folder_thread($customer, 'inkorg'), 'känd kund med typen Information ligger i Information');
qa(jeab_inkorg_list_category_mark($customer) === 'Information', 'Information-raden får chippen Information');

$label = 'Öppen';
qa(mb_check_encoding($label, 'UTF-8') && !str_contains($label, "\u{FFFD}"), 'statusetiketten är giltig UTF-8');

echo "inkorg qa followup: ok\n";
