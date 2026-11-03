<?php

declare(strict_types=1);

/**
 * Skrivbordets högerkolumn: #vunnen, #admin och «boka tid i kalendern»
 * behåller markup och klick. Tryck där stänger inte det öppna kortet.
 * Inkomstdatumet står kvar en gång. Inget utskick.
 */

$tmp = sys_get_temp_dir() . '/jeab-pam-side-act-' . bin2hex(random_bytes(4));
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';
require dirname(__DIR__) . '/src/pam_desk.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_GET = ['p' => 'reminders', 'co' => 'jarfalla'];

function sideact(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
$row = $store->insertReminder([
    'title' => 'Batteri – Nytt tak till villan',
    'customer_name' => 'Ada Ärende',
    'email' => 'ada.pam@example.test',
    'phone' => '0701234567',
    'address' => 'Storgatan 12',
    'ort' => 'Sollentuna',
    'company' => 'jarfalla',
    'tags' => ['batteri'],
    'note' => 'Hej',
    'due_at' => '2026-10-20 09:00:00',
    'created_at' => '2026-10-03 15:04:00',
    'updated_at' => '2026-10-03 15:04:00',
]);
$id = (int) ($row['id'] ?? 0);
sideact($id > 0, 'påminnelsen skapas');
$full = $store->getReminder($id);
sideact(is_array($full), 'raden läses tillbaka');

$open = jeab_reminder_row($full, true);
sideact(substr_count($open, 'class="item-arrived"') === 1, 'öppet kort har inkomstdatumet en gång');
sideact(substr_count($open, 'item-created-label') === 0, 'öppet kort skriver inte datumet en gång till vid Visa mer');
sideact(str_contains($open, 'data-js="vunnen"') && str_contains($open, 'name="tag" value="vunnen"'), 'kortets #vunnen har kvar sin markering');
sideact(str_contains($open, 'data-js="tag"') && str_contains($open, 'name="tag" value="admin"'), 'kortets #admin har kvar sin markering');
sideact(str_contains($open, 'data-js="reminder-book"') && str_contains($open, 'boka tid i kalendern'), 'kortets kalenderlänk har kvar sin markering');

$_GET['open'] = (string) $id;
$side = jeab_pam_side_payload($store);
$sideHtml = (string) ($side['html'] ?? '');
$toolsAt = strpos($sideHtml, 'class="pam-side-tools"');
$contactAt = strpos($sideHtml, '>Kontakt<');
sideact($toolsAt !== false && $contactAt !== false && $toolsAt < $contactAt, 'verktygen ligger överst i högerkolumnen');
$tools = substr($sideHtml, $toolsAt, $contactAt - $toolsAt);
sideact(str_contains($tools, 'data-js="vunnen"') && str_contains($tools, 'name="tag" value="vunnen"') && str_contains($tools, '>#vunnen<'), 'högerkolumnens #vunnen är en taggknapp');
sideact(!preg_match('/data-js="vunnen"[^>]*>[\s\S]*<button[^>]*disabled/', $tools), 'högerkolumnens #vunnen är inte avstängd');
sideact(str_contains($tools, 'data-js="tag"') && str_contains($tools, 'name="tag" value="admin"') && str_contains($tools, '>#admin<'), 'högerkolumnens #admin är en taggknapp');
sideact(str_contains($tools, 'data-js="reminder-book"') && str_contains($tools, 'boka tid i kalendern') && str_contains($tools, 'p=bookings'), 'högerkolumnens kalenderlänk går till bokningen');
sideact(str_contains($tools, 'data-js="who"'), 'högerkolumnens ansvarig har kvar sin markering');

$root = dirname(__DIR__);
$js = (string) file_get_contents($root . '/assets/js/app.js');
$opt = (string) file_get_contents($root . '/assets/js/optimistic.js');
$bindAt = strpos($js, 'function bindReminderSubtree');
$bind = $bindAt === false ? '' : substr($js, $bindAt, 1600);
sideact(str_contains($bind, '[data-js="vunnen"]') && str_contains($bind, 'bindVunnenForm'), 'högerkolumnen binder #vunnen');
sideact(str_contains($bind, '[data-js="tag"]') && str_contains($bind, 'bindTagForm'), 'högerkolumnen binder #admin');
sideact(str_contains($bind, '[data-js="reminder-book"]') && str_contains($bind, 'bindReminderBook'), 'högerkolumnen binder kalenderlänken');
sideact(str_contains($bind, '[data-js="who"]') && str_contains($bind, 'bindWhoForm'), 'högerkolumnen binder ansvarig');

$whoAt = strpos($js, 'function bindWhoForm');
$who = $whoAt === false ? '' : substr($js, $whoAt, 1200);
sideact(str_contains($who, "form.closest('.pam-side')") && str_contains($who, 'currentOpenRow()'), 'ansvarig i högerkolumnen postas även utanför kortet');

$outAt = strpos($js, 'const onOutside');
$out = $outAt === false ? '' : substr($js, $outAt, 2800);
$sideHit = strpos($out, "classList.contains('pam-side')");
$keepAt = strpos($out, "outsidePlan === 'keep'");
$closeAt = strpos($out, 'closeOpenReminder()');
$leaveAt = strpos($out, 'leavesReminders(event)');
$snoozeAt = strpos($out, 'snoozeRowBusy(openRow)');
sideact($sideHit !== false && $keepAt !== false && $sideHit < $keepAt && $keepAt < $closeAt, 'högerkolumnen lämnas innan kortet stängs');
sideact($snoozeAt !== false && $leaveAt !== false && $closeAt !== false && $snoozeAt < $leaveAt && $leaveAt < $closeAt, 'snooze och att lämna sidan går fortfarande före stängning');
$keepSlice = substr($out, $sideHit, $keepAt - $sideHit);
sideact(!str_contains($keepSlice, 'preventDefault') && !str_contains($keepSlice, 'stopPropagation'), 'högerkolumnen sväljer inte klicket');

$targetAt = strpos($js, 'function reminderHeadTarget');
$target = $targetAt === false ? '' : substr($js, $targetAt, 900);
$buttonAt = strpos($target, "return 'button'");
$headerAt = strpos($target, "return 'header-row'");
sideact($buttonAt !== false && $headerAt !== false && $buttonAt < $headerAt, 'knapp lämnar huvudet före ihopfällningen');
sideact(str_contains($target, 'reminderMobileList()'), 'ihopfällningen av adressraden är kvar på mobil');
sideact(str_contains($opt, 'function jeabReminderOutsidePlan') && str_contains($opt, "place === 'side'"), 'regeln känner högerkolumnen');

$node = trim((string) shell_exec('command -v node'));
sideact($node !== '', 'node finns');
$nodeFile = $tmp . '/side.js';
file_put_contents($nodeFile, <<<'JS'
const m = require(process.argv[2]);
const plan = m.jeabReminderOutsidePlan;
const head = m.jeabReminderHeadClick;
const cases = [
  ['side', 'keep'],
  ['open-row', 'keep'],
  ['outside', 'close'],
  ['', 'close']
];
for (const row of cases) {
  const got = plan(row[0]);
  if (got !== row[1]) {
    console.error('plan ' + JSON.stringify(row) + ' -> ' + got);
    process.exit(1);
  }
}
const heads = [
  [1000, 1800, 'button', 'keep'],
  [1000, 1800, 'link', 'keep'],
  [1000, 1030, 'header-row', 'keep'],
  [1000, 1800, 'header-row', 'close'],
  [1000, 1800, 'head', 'close']
];
for (const row of heads) {
  const got = head(row[0], row[1], row[2]);
  if (got !== row[3]) {
    console.error('head ' + JSON.stringify(row) + ' -> ' + got);
    process.exit(1);
  }
}
console.log('side ok');
JS);
$ran = shell_exec($node . ' ' . escapeshellarg($nodeFile) . ' ' . escapeshellarg($root . '/assets/js/optimistic.js') . ' 2>&1');
sideact(is_string($ran) && str_contains($ran, 'side ok'), 'regeln behåller högerkolumnen och huvudets knapp, fick ' . trim((string) $ran));

$http = sys_get_temp_dir() . '/jeab-pam-side-act-http-' . bin2hex(random_bytes(3));
mkdir($http, 0700, true);
file_put_contents($http . '/sida.html', $tools);
file_put_contents($http . '/app.js', $js);
$port = 0;
for ($try = 8331; $try < 8350; $try++) {
    $socket = @stream_socket_server('tcp://127.0.0.1:' . $try, $errno, $errstr);
    if ($socket === false) {
        continue;
    }
    fclose($socket);
    $port = $try;
    break;
}
sideact($port > 0, 'en lokal port är ledig');
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $http],
    [1 => ['file', $http . '/server.log', 'a'], 2 => ['file', $http . '/server.log', 'a']],
    $pipes
);
sideact(is_resource($server), 'php-servern startade');
$body = '';
for ($i = 0; $i < 20; $i++) {
    $probe = @file_get_contents('http://127.0.0.1:' . $port . '/sida.html');
    if (is_string($probe) && str_contains($probe, 'data-js="vunnen"')) {
        $body = $probe;
        break;
    }
    usleep(100000);
}
sideact(str_contains($body, '>#vunnen<') && str_contains($body, '>#admin<') && str_contains($body, 'boka tid i kalendern'), 'curl ser knapparna i högerkolumnen');
$curlJs = curl_init('http://127.0.0.1:' . $port . '/app.js');
curl_setopt_array($curlJs, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
$jsBody = (string) curl_exec($curlJs);
$jsCode = (int) curl_getinfo($curlJs, CURLINFO_HTTP_CODE);
curl_close($curlJs);
proc_terminate($server);
proc_close($server);
sideact($jsCode === 200 && str_contains($jsBody, "classList.contains('pam-side')") && str_contains($jsBody, 'bindVunnenForm'), 'curl ser klickregeln och #vunnen-bindningen, fick ' . $jsCode);

echo "ALLA OK\n";
