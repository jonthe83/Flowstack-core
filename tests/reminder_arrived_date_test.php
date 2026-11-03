<?php

declare(strict_types=1);

/**
 * Inkomstdatum nere till höger på påminnelsekortet, d/m utan nollor.
 * Kopplat ärende: första mejlets received_at, inte en anteckning.
 * Saknas ärendet används created_at.
 * Mobil: adress- och namnraden fäller ihop öppet kort. Knapp och länk gör det inte.
 * Påhittad data. Inget utskick.
 */

$tmp = sys_get_temp_dir() . '/jeab-rem-arrived-' . bin2hex(random_bytes(4));
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_GET = ['p' => 'reminders', 'co' => 'jarfalla'];
$_SERVER['REQUEST_METHOD'] = 'GET';

function ad_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
ad_assert($store instanceof JeabPdoStore, 'sqlite-lagret finns');
$pdo = $store->pdo();
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS inkorg_messages (
        id TEXT PRIMARY KEY,
        thread_id TEXT NOT NULL DEFAULT \'\',
        received_at TEXT,
        from_addr TEXT NOT NULL DEFAULT \'\',
        is_note INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT,
        row_json TEXT NOT NULL
    )'
);
$insert = $pdo->prepare('INSERT INTO inkorg_messages (id, thread_id, received_at, from_addr, is_note, row_json) VALUES (?, ?, ?, ?, ?, ?)');
$insert->execute(['m-note', 'th-ada', '2026-09-01 08:00:00', 'anteckning@example.test', 1, '{}']);
$insert->execute(['m-late', 'th-ada', '2026-10-09 11:00:00', 'kund@example.test', 0, '{}']);
$insert->execute(['m-first', 'th-ada', '2026-10-03 15:04:00', 'kund@example.test', 0, '{}']);
$insert->execute(['m-link', 'th-note', '2026-04-05 09:00:00', 'lank@example.test', 0, '{}']);

$withCase = $store->insertReminder([
    'title' => 'Inkomvägen 3',
    'customer_name' => 'Ada Inkom',
    'company' => 'jarfalla',
    'due_at' => '2026-10-20 09:00:00',
    'thread_id' => 'th-ada',
    'created_at' => '2026-11-02 10:00:00',
    'updated_at' => '2026-11-02 10:00:00',
    'note' => 'Hej',
]);
$plain = $store->insertReminder([
    'title' => 'Utanärende 9',
    'customer_name' => 'Bo Utan',
    'company' => 'jarfalla',
    'due_at' => '2026-02-01 09:00:00',
    'created_at' => '2026-01-09 08:00:00',
    'updated_at' => '2026-01-09 08:00:00',
]);
$linked = $store->insertReminder([
    'title' => 'Länkgatan 5',
    'customer_name' => 'Caj Länk',
    'company' => 'jarfalla',
    'due_at' => '2026-04-20 09:00:00',
    'note' => "mail: https://flowstack.example.test/index.php?p=inkorg&id=th-note\nHej",
    'created_at' => '2026-12-01 10:00:00',
    'updated_at' => '2026-12-01 10:00:00',
]);

ad_assert(jeab_reminder_arrived_day($withCase) === '3/10', 'ärendets första mejl är 3/10, inte anteckningen och inte created_at');
ad_assert(jeab_reminder_arrived_day($plain) === '9/1', 'utan ärende används created_at, utan inledande nollor');
ad_assert(jeab_reminder_arrived_day($linked) === '5/4', 'tråden i anteckningens länk ger mejlets datum');
ad_assert(!str_contains(jeab_reminder_arrived_html($plain), '09/01') && !str_contains(jeab_reminder_arrived_html($withCase), '03/10'), 'formatet har inga inledande nollor');

$shut = jeab_reminder_row($withCase, false);
$open = jeab_reminder_row($withCase, true);
ad_assert(preg_match('/<p class="item-arrived"><span class="item-arrived-label" aria-label="Inkom 3\/10">3\/10<\/span><\/p>/', $shut) === 1, 'stängt kort har 3/10');
ad_assert(preg_match('/<p class="item-arrived"><span class="item-arrived-label" aria-label="Inkom 3\/10">3\/10<\/span><\/p>/', $open) === 1, 'öppet kort har 3/10');
ad_assert(substr_count($shut, 'class="item-arrived"') === 1, 'stängt kort har ett .item-arrived');
ad_assert(substr_count($open, 'class="item-arrived"') === 1, 'öppet kort har ett .item-arrived');
ad_assert(substr_count($open, 'item-created-label') === 0, 'öppet kort skriver inte samma dag en gång till vid Visa mer');
$shutNote = strpos($shut, 'class="item-note"');
$shutDate = strpos($shut, 'class="item-arrived"');
$shutEnd = strrpos($shut, '</div>');
ad_assert($shutDate !== false && $shutEnd !== false && $shutDate < $shutEnd && ($shutNote === false || $shutNote < $shutDate), 'datumet ligger sist i kortet, under anteckningen');
$openExtra = strpos($open, 'class="item-extra"');
$openDate = strpos($open, 'class="item-arrived"');
ad_assert($openExtra !== false && $openDate !== false && $openExtra < $openDate, 'öppet kort har datumet under knapparna');

$root = dirname(__DIR__);
$css = (string) file_get_contents($root . '/assets/css/paminnelser.css');
$js = (string) file_get_contents($root . '/assets/js/app.js');
$opt = (string) file_get_contents($root . '/assets/js/optimistic.js');
ad_assert(str_contains($css, 'html body.page-reminders:not(.page-kontakter) .item-arrived-label'), 'datumet har en egen stil');
$labelAt = strpos($css, '.item-arrived-label {');
$labelCss = $labelAt === false ? '' : substr($css, $labelAt, 280);
ad_assert(str_contains($labelCss, 'font-size: 12px;') && str_contains($labelCss, 'color: var(--orchid-subtle);'), 'datumet är litet och dämpat');
ad_assert(str_contains($css, 'align-self: flex-end;') && str_contains($css, 'margin: 4px 0 0 auto;'), 'datumet ligger till höger');
$hideAt = strrpos($css, '@media (min-width: 900px)');
$hide = $hideAt === false ? '' : substr($css, $hideAt);
ad_assert(str_contains($hide, '.pam-board-list .item-arrived') && str_contains($hide, 'display: none !important;'), 'skrivbordets listrad döljer datumet så rutnätet består');
ad_assert(!str_contains(substr($css, 0, (int) strpos($css, '@media (min-width: 900px)')), '.pam-board-list .item-arrived'), 'döljningen ligger inte i mobilens regler');

$targetAt = strpos($js, 'function reminderHeadTarget');
$target = $targetAt === false ? '' : substr($js, $targetAt, 900);
$buttonAt = strpos($target, "return 'button'");
$headerAt = strpos($target, "return 'header-row'");
ad_assert($buttonAt !== false && $headerAt !== false && $buttonAt < $headerAt, 'knapp lämnar huvudet före ihopfällningen');
ad_assert(strpos($target, "return 'link'") !== false && strpos($target, "return 'link'") < $headerAt, 'länk lämnar huvudet före ihopfällningen');
ad_assert(str_contains($target, 'reminderMobileList()') && str_contains($target, '.item-addr-row, .item-name-row'), 'ihopfällningen gäller adress- och namnraden på mobil');
ad_assert(str_contains($opt, "kind === 'button'") && str_contains($opt, 'header-row'), 'regeln känner header-row och lämnar knappen');

$node = trim((string) shell_exec('command -v node'));
ad_assert($node !== '', 'node finns');
$nodeFile = $tmp . '/head.js';
file_put_contents($nodeFile, <<<'JS'
const m = require(process.argv[2]);
const hit = m.jeabReminderHeadClick;
const cases = [
  [1000, 1030, 'header-row', 'keep'],
  [1000, 1399, 'header-row', 'keep'],
  [1000, 1400, 'header-row', 'close'],
  [1000, 1800, 'header-row', 'close'],
  [1000, 1800, 'button', 'keep'],
  [1000, 1800, 'link', 'keep'],
  [1000, 1800, 'textarea', 'keep'],
  [1000, 1800, 'input-focused', 'keep'],
  [1000, 1800, 'head', 'close']
];
for (const row of cases) {
  const got = hit(row[0], row[1], row[2]);
  if (got !== row[3]) {
    console.error(JSON.stringify(row) + ' -> ' + got);
    process.exit(1);
  }
}
console.log('head ok');
JS);
$ran = shell_exec($node . ' ' . escapeshellarg($nodeFile) . ' ' . escapeshellarg($root . '/assets/js/optimistic.js') . ' 2>&1');
ad_assert(is_string($ran) && str_contains($ran, 'head ok'), 'header-row stänger efter öppningen, knapp och länk stänger inte, fick ' . trim((string) $ran));

$http = sys_get_temp_dir() . '/jeab-rem-arrived-http-' . bin2hex(random_bytes(3));
mkdir($http, 0700, true);
file_put_contents($http . '/kort.html', $shut);
file_put_contents($http . '/paminnelser.css', $css);
$port = 0;
for ($try = 8291; $try < 8310; $try++) {
    $socket = @stream_socket_server('tcp://127.0.0.1:' . $try, $errno, $errstr);
    if ($socket === false) {
        continue;
    }
    fclose($socket);
    $port = $try;
    break;
}
ad_assert($port > 0, 'en lokal port är ledig');
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $http],
    [1 => ['file', $http . '/server.log', 'a'], 2 => ['file', $http . '/server.log', 'a']],
    $pipes
);
ad_assert(is_resource($server), 'php-servern startade');
$body = '';
for ($i = 0; $i < 20; $i++) {
    $probe = @file_get_contents('http://127.0.0.1:' . $port . '/kort.html');
    if (is_string($probe) && str_contains($probe, 'item-arrived')) {
        $body = $probe;
        break;
    }
    usleep(100000);
}
ad_assert(str_contains($body, 'Inkom 3/10') && str_contains($body, '>3/10<'), 'curl ser 3/10 på kortet');
$cssBody = (string) @file_get_contents('http://127.0.0.1:' . $port . '/paminnelser.css');
ad_assert(str_contains($cssBody, '.item-arrived-label') && str_contains($cssBody, 'font-size: 12px;'), 'curl ser den lilla stilen');
proc_terminate($server);
proc_close($server);

echo "ALLA OK\n";
