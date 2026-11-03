<?php

declare(strict_types=1);

/**
 * #vunnen utan kontakt ska gå att klicka och säga till.
 * En taggknapp får inte markera påminnelsen klar.
 * Köpsflödet (jeab_ordrar_vunnen_purchase) rörs inte. Inget utskick.
 */

$tmp = sys_get_temp_dir() . '/jeab-vunnen-click-' . bin2hex(random_bytes(4));
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function vclick(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function vclick_form(string $html): string
{
    $start = strpos($html, 'data-js="vunnen"');
    vclick($start !== false, 'Vunnen-formuläret finns');
    $formStart = strrpos(substr($html, 0, $start), '<form');
    vclick($formStart !== false, 'Vunnen-formuläret börjar');
    $end = strpos($html, '</form>', $start);
    vclick($end !== false, 'Vunnen-formuläret slutar');

    return substr($html, $formStart, $end + 7 - $formStart);
}

$bare = [
    'id' => 77,
    'title' => 'Test',
    'company' => 'jarfalla',
    'tags' => [],
];
vclick(jeab_ordrar_vunnen_need_label($bare) === 'Saknar adress och kontakt.', 'utan kontakt saknas adress och kontakt');
$form = vclick_form(jeab_reminder_row($bare, true));
vclick(str_contains($form, 'name="action" value="reminder.tag"') && str_contains($form, 'name="tag" value="vunnen"'), 'knappen postar reminder.tag');
vclick(str_contains($form, 'type="submit"') && !str_contains($form, 'disabled') && !str_contains($form, 'aria-disabled'), 'saknad data stänger inte av knappen');
vclick(str_contains($form, 'data-vunnen-need="Ordern skapades inte. Saknar adress och kontakt."'), 'vägran följer med knappen');
vclick(str_contains($form, 'role="alert"') && str_contains($form, '>Ordern skapades inte. Saknar adress och kontakt.</p>'), 'vägran syns på kortet');

$ready = [
    'id' => 78,
    'title' => 'Batteri',
    'company' => 'jarfalla',
    'customer_name' => 'Kim Redo',
    'email' => 'kim.redo.click@example.test',
    'address' => 'Redogatan 1',
    'note' => 'batteri',
    'tags' => [],
];
vclick(jeab_ordrar_vunnen_need_label($ready) === '', 'komplett påminnelse har ingen lucka');
$readyForm = vclick_form(jeab_reminder_row($ready, true));
vclick(!str_contains($readyForm, 'data-vunnen-need') && !str_contains($readyForm, 'disabled'), 'komplett #vunnen går till köpet');

$root = dirname(__DIR__);
$js = (string) file_get_contents($root . '/assets/js/app.js');
$opt = (string) file_get_contents($root . '/assets/js/optimistic.js');
$ordrar = (string) file_get_contents($root . '/src/ordrar.php');
vclick(str_contains($ordrar, 'function jeab_ordrar_vunnen_purchase(') && str_contains($ordrar, 'function jeab_ordrar_vunnen_close_reminder('), 'köpet klarmarkerar påminnelsen efter ordern');

$vunnenAt = strpos($js, 'function bindVunnenForm');
$vunnenEnd = $vunnenAt === false ? false : strpos($js, 'function tapTotalLabel', $vunnenAt);
$vunnen = ($vunnenAt === false || $vunnenEnd === false) ? '' : substr($js, $vunnenAt, $vunnenEnd - $vunnenAt);
$planAt = strpos($vunnen, 'jeabVunnenClickPlan');
$msgAt = strpos($vunnen, "vunnenPlan.action === 'message'");
$sendAt = strpos($vunnen, 'const startSend');
vclick($planAt !== false && $msgAt !== false && $sendAt !== false && $planAt < $msgAt && $msgAt < $sendAt, 'saknad data visas innan köpet startar');
vclick(str_contains($vunnen, 'paintVunnenRefusal(form, refusal)') && substr_count($vunnen, 'paintVunnenRefusal(form, refusal)') >= 2, 'vägran skrivs på kortet både direkt och från servern');
vclick(str_contains($vunnen, 'showReminderBar'), 'meddelandet syns i listen');
vclick(str_contains($vunnen, 'location.href = String(data.next)'), 'komplett klick öppnar fortfarande nästa steg');

$tagAt = strpos($js, 'function bindTagForm');
$tag = $tagAt === false ? '' : substr($js, $tagAt, 700);
$mouseAt = strpos($tag, "addEventListener('mousedown'");
$mouseEnd = $mouseAt === false ? false : strpos($tag, '});', $mouseAt);
$mouse = ($mouseAt !== false && $mouseEnd !== false) ? substr($tag, $mouseAt, $mouseEnd - $mouseAt) : '';
vclick($mouse !== '' && !str_contains($mouse, 'preventDefault'), 'taggknappens mousedown flyttar inte klicket');

$doneAt = strpos($js, 'jeabReminderDoneSubmit');
$markAt = strpos($js, 'markReminderDone(form);');
vclick($doneAt !== false && $markAt !== false && $doneAt < $markAt, 'klar-bocken frågar innan den markerar');
vclick(str_contains($opt, 'function jeabVunnenClickPlan') && str_contains($opt, 'function jeabReminderDoneSubmit'), 'reglerna ligger i optimistic.js');

$node = trim((string) shell_exec('command -v node'));
vclick($node !== '', 'node finns');
$nodeFile = $tmp . '/vunnen-click.js';
file_put_contents($nodeFile, <<<'JS'
const m = require(process.argv[2]);
const plan = m.jeabVunnenClickPlan;
const done = m.jeabReminderDoneSubmit;
const cases = [
  ['Saknar kontakt.', false, 'message', 'Ordern skapades inte. Saknar kontakt.'],
  ['Saknar adress, produkt och kontakt.', false, 'message', 'Ordern skapades inte. Saknar adress, produkt och kontakt.'],
  ['Ordern skapades inte. Saknar adress.', false, 'message', 'Ordern skapades inte. Saknar adress.'],
  ['', false, 'purchase', ''],
  ['Saknar kontakt.', true, 'ask', '']
];
for (const row of cases) {
  const got = plan(row[0], row[1]);
  if (!got || got.action !== row[2] || (row[3] !== '' && got.message !== row[3])) {
    console.error('plan ' + JSON.stringify(row) + ' -> ' + JSON.stringify(got));
    process.exit(1);
  }
}
const dones = [
  [true, true, true, 'ignore'],
  [true, true, false, 'done'],
  [true, false, false, 'ignore'],
  [false, false, false, 'done'],
  [false, false, true, 'ignore']
];
for (const row of dones) {
  const got = done(row[0], row[1], row[2]);
  if (got !== row[3]) {
    console.error('done ' + JSON.stringify(row) + ' -> ' + got);
    process.exit(1);
  }
}
console.log('vunnen click ok');
JS);
$ran = shell_exec($node . ' ' . escapeshellarg($nodeFile) . ' ' . escapeshellarg($root . '/assets/js/optimistic.js') . ' 2>&1');
vclick(is_string($ran) && str_contains($ran, 'vunnen click ok'), 'regeln skiljer meddelande, köp och bock, fick ' . trim((string) $ran));

$http = sys_get_temp_dir() . '/jeab-vunnen-click-http-' . bin2hex(random_bytes(3));
mkdir($http, 0700, true);
file_put_contents($http . '/kort.html', $form);
file_put_contents($http . '/app.js', $js);
$port = 0;
for ($try = 8361; $try < 8380; $try++) {
    $socket = @stream_socket_server('tcp://127.0.0.1:' . $try, $errno, $errstr);
    if ($socket === false) {
        continue;
    }
    fclose($socket);
    $port = $try;
    break;
}
vclick($port > 0, 'en lokal port är ledig');
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $http],
    [1 => ['file', $http . '/server.log', 'a'], 2 => ['file', $http . '/server.log', 'a']],
    $pipes
);
vclick(is_resource($server), 'php-servern startade');
$body = '';
$code = 0;
for ($i = 0; $i < 20; $i++) {
    $ch = curl_init('http://127.0.0.1:' . $port . '/kort.html');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
    $probe = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (is_string($probe) && str_contains($probe, 'data-vunnen-need')) {
        $body = $probe;
        break;
    }
    usleep(100000);
}
vclick($code === 200 && str_contains($body, 'type="submit"') && str_contains($body, 'value="vunnen"') && !str_contains($body, 'disabled'), 'curl ser en klickbar #vunnen, fick ' . $code);
$ch = curl_init('http://127.0.0.1:' . $port . '/app.js');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
$jsBody = (string) curl_exec($ch);
$jsCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
proc_terminate($server);
proc_close($server);
vclick($jsCode === 200 && str_contains($jsBody, 'jeabVunnenClickPlan') && str_contains($jsBody, 'jeabReminderDoneSubmit'), 'curl ser meddelandet och klar-spärren, fick ' . $jsCode);

echo "ALLA OK\n";
