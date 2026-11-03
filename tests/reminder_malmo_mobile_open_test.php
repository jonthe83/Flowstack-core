<?php

declare(strict_types=1);

require_once __DIR__ . '/jeab_test_proc_env.php';

/**
 * Kim (Malmö, inte admin) ska kunna öppna ett påminnelsekort på mobil.
 * Stängt kort är a.item-main. Öppningen sker på pointerup, och klicket efteråt
 * landar på adressfältet i .item-head-open. Det klicket får inte stänga kortet.
 * Telefon, e-post och anteckning finns när kortet är öppet med co=malmo.
 * Påhittad data. Inget utskick.
 */

$tmp = sys_get_temp_dir() . '/jeab-rem-kim-mobile-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('JEAB_QA_DATA=' . $tmp);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function km(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function km_card(string $html, int $id): string
{
    $pos = strpos($html, 'id="r-' . $id . '"');
    if ($pos === false) {
        return '';
    }
    $start = strrpos(substr($html, 0, $pos), '<li ');
    if ($start === false) {
        return '';
    }
    $end = strpos($html, '</li>', $pos);
    if ($end === false) {
        return '';
    }

    return substr($html, $start, $end - $start);
}

$root = dirname(__DIR__);
$opt = (string) file_get_contents($root . '/assets/js/optimistic.js');
$js = (string) file_get_contents($root . '/assets/js/app.js');
$css = (string) file_get_contents($root . '/assets/css/paminnelser.css');
km(str_contains($opt, 'function jeabReminderHeadClick('), 'öppningsklicket har en egen regel');
km(str_contains($js, 'reminderOpenedAt = Date.now()'), 'öppningen stämplas när kortet blir öppet');
km(str_contains($js, 'jeabReminderHeadClick(reminderOpenedAt'), 'huvudklicket frågar regeln');
km(str_contains($js, '.item.is-open .item-head-open'), 'ett senare tryck på huvudet kan fortfarande stänga');
km(
    preg_match('/@media \(max-width: 639px\) \{.*?a\.item-main::after \{[^}]*position:\s*absolute;[^}]*inset:\s*0;/s', $css) === 1,
    'mobil: hela det stängda kortet är träffyta'
);
km(
    preg_match('/@media \(max-width: 639px\) \{.*?\.item:not\(\.is-open\) \.contact-line/s', $css) !== 1
        && str_contains($css, '.item:not(.is-open) .contact-line'),
    'telefon och e-post är dolda tills kortet är öppet'
);

$node = trim((string) shell_exec('command -v node'));
km($node !== '', 'node finns');
$nodeFile = $tmp . '/head-click.js';
file_put_contents($nodeFile, <<<'JS'
const m = require(process.argv[2]);
const hit = m.jeabReminderHeadClick;
const cases = [
  [1000, 1030, 'input', 'keep'],
  [1000, 1030, 'head', 'keep'],
  [1000, 1399, 'input', 'keep'],
  [1000, 1400, 'input', 'close'],
  [1000, 1800, 'head', 'close'],
  [1000, 1800, 'input', 'close'],
  [1000, 1030, 'textarea', 'keep'],
  [1000, 1800, 'textarea', 'keep'],
  [1000, 1800, 'link', 'keep'],
  [1000, 1800, 'button', 'keep'],
  [1000, 1800, 'input-focused', 'keep'],
  [1000, 1800, 'select', 'keep'],
  [0, 5000, 'input', 'close']
];
for (const row of cases) {
  const got = hit(row[0], row[1], row[2]);
  if (got !== row[3]) {
    console.error(JSON.stringify(row) + ' -> ' + got);
    process.exit(1);
  }
}
console.log('head click ok');
JS);
$ran = shell_exec($node . ' ' . escapeshellarg($nodeFile) . ' ' . escapeshellarg($root . '/assets/js/optimistic.js') . ' 2>&1');
km(is_string($ran) && str_contains($ran, 'head click ok'), 'öppningsklicket stänger inte, ett senare tryck gör det, fick ' . trim((string) $ran));

$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm'));
$stamp = $now->format('Y-m-d H:i:s');
$store = jeab_store();
$row = $store->insertReminder([
    'title' => 'KimMobilOpengatan 4',
    'customer_name' => 'Kim Mobilkund',
    'address' => 'KimMobilOpengatan 4',
    'ort' => 'Malmö',
    'email' => 'kim-mobil-open@example.test',
    'phone' => '0701112233',
    'note' => 'KimMobilOpen anteckning',
    'company' => 'malmo',
    'person' => 'Kim',
    'assignees' => ['Kim'],
    'due_at' => $stamp,
    'created_at' => $stamp,
    'updated_at' => $stamp,
]);
$id = (int) ($row['id'] ?? 0);
km($id > 0, 'Malmö-kortet finns');

$passwords = $tmp . '/passwords.json';
file_put_contents($passwords, json_encode([
    'secret' => 'kim-mobile-secret',
    'hashes' => [
        ['hash' => 'kim-mobile-hash', 'email' => 'saljare.syd@malmo.example.test'],
    ],
], JSON_UNESCAPED_UNICODE));
putenv('JEAB_PASSWORDS_FILE=' . $passwords);
$_COOKIE['jeab_auth'] = (string) jeab_auth_issue_value('saljare.syd@malmo.example.test', 'kim-mobile-hash');
$_SESSION['jeab_email'] = 'saljare.syd@malmo.example.test';
unset($_SESSION['jeab_company']);
km(jeab_auth_ok(), 'Kim är inloggad');
km(!jeab_is_admin(), 'Kim är inte admin');
km(jeab_selected_company() === 'malmo', 'Kims förvalda kontor är Malmö');
km(in_array('malmo', jeab_actor_companies() ?? [], true), 'Kim får läsa Malmö');

$workspace = dirname(__DIR__);
$router = $tmp . '/router.php';
file_put_contents($router, "<?php\n" . jeab_test_sqlite_boot() . <<<PHP
\$tmp = getenv('JEAB_QA_DATA');
\$GLOBALS['_JEAB_DATA_DIR'] = \$tmp;
putenv('JEAB_PASSWORDS_FILE=' . \$tmp . '/passwords.json');
putenv('JEAB_DISABLE_SQLITE=1');
\$uri = (string) (parse_url((string) (\$_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
if (\$uri === '/index.php' || \$uri === '/') {
    require '{$workspace}/index.php';
    return true;
}
return false;
PHP);
$port = 0;
for ($try = 8971; $try < 8990; $try++) {
    $socket = @stream_socket_server('tcp://127.0.0.1:' . $try, $errno, $errstr);
    if ($socket === false) {
        continue;
    }
    fclose($socket);
    $port = $try;
    break;
}
km($port > 0, 'en lokal port är ledig');
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $workspace, $router],
    [1 => ['file', $tmp . '/server.out', 'w'], 2 => ['file', $tmp . '/server.err', 'w']],
    $pipes,
    $workspace,
    jeab_test_proc_env([
        'JEAB_TEST_TEMP_SQLITE' => '1',
        'JEAB_QA_DATA' => $tmp,
        'JEAB_PASSWORDS_FILE' => $passwords,
        'JEAB_DISABLE_SQLITE' => '1',
    ])
);
km(is_resource($server), 'php-servern startade');
register_shutdown_function(static function () use ($server): void {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
});
$deadline = microtime(true) + 5;
$ready = false;
do {
    $probe = @file_get_contents('http://127.0.0.1:' . $port . '/index.php?p=csrf');
    if (is_string($probe) && $probe !== '') {
        $ready = true;
        break;
    }
    usleep(80000);
} while (microtime(true) < $deadline);
km($ready, 'servern svarar');

$cookie = 'jeab_auth=' . (string) $_COOKIE['jeab_auth'];
$fetch = static function (string $qs) use ($port, $cookie): array {
    $ch = curl_init('http://127.0.0.1:' . $port . '/index.php?' . $qs);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Accept: text/html',
            'Cookie: ' . $cookie,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $html = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$code, $html];
};

[$code, $closed] = $fetch('p=reminders&co=malmo&v=all&who=Kim&ws=1');
km($code === 200, 'stängd lista svarar, fick ' . $code);
$card = km_card($closed, $id);
km($card !== '', 'Kims Malmö-kort finns i listan');
km(!str_contains(substr($card, 0, strpos($card, '>') ?: 0), 'is-open'), 'kortet är stängt');
km(preg_match('/<a class="item-main" href="([^"]+)"/', $card, $href) === 1, 'mobilträffytan är kortets länk');
$link = html_entity_decode($href[1]);
km(str_contains($link, 'co=malmo') && str_contains($link, 'open=' . $id), 'länken öppnar Malmö-kortet, fick ' . $link);
km(!str_contains($card, 'name="address"') && !str_contains($card, 'item-head-open'), 'stängt kort ritar inte adressfältet');
km(str_contains($card, 'class="item-shut-addr"'), 'stängt kort visar adressen');
km(str_contains($closed, 'chrome-company'), 'kontorsväljaren finns');
km(substr_count($closed, 'class="chrome-company') === 1, 'en kontorsväljare, ingen extra yta');

[$code, $open] = $fetch('p=reminders&co=malmo&v=all&who=Kim&ws=1&open=' . $id);
km($code === 200, 'öppnad Malmö-adress svarar, fick ' . $code);
$openCard = km_card($open, $id);
km($openCard !== '' && str_contains(substr($openCard, 0, 160), 'is-open'), 'co=malmo&open visar kortet öppet');
km(str_contains($openCard, '070 111 22 33'), 'telefonen syns i det öppna kortet');
km(str_contains($openCard, 'mailto:kim-mobil-open@example.test'), 'e-posten syns i det öppna kortet');
km(str_contains($openCard, 'KimMobilOpen anteckning'), 'anteckningen går att läsa');
km(str_contains($openCard, 'name="notes"'), 'anteckningsfältet finns');

[$code, $other] = $fetch('p=reminders&co=jarfalla&v=all&who=all&ws=1&open=' . $id);
km($code === 200, 'fel kontor svarar, fick ' . $code);
km(!str_contains($other, 'KimMobilOpengatan'), 'Malmö-kortet öppnas inte när adressen säger Järfälla');
km(!str_contains($other, 'is-open'), 'inget kort blir öppet på fel kontor');

echo "ALLA OK\n";
