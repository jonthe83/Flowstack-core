<?php

declare(strict_types=1);

/**
 * QA efter #695: ångra på öppet kort, skapa med tagg, Klar med id,
 * Väntar = listan, klockslag, ett tryck öppnar, 2 s knuff, namn, ingen flagga,
 * och listan byts inte mot grå platshållare.
 * Påhittad data. Inget kundutskick.
 */

$tmp = sys_get_temp_dir() . '/jeab-rem-qa695-' . getmypid();
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

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function qa(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$_SERVER['HTTP_ACCEPT'] = 'application/json';
qa(jeab_reminder_bot_request(['ui' => '1', 'title' => 'A']) === false, 'ui=1 är inte en bot även när svaret är JSON');
qa(jeab_reminder_bot_request(['title' => 'A']) === true, 'JSON utan ui är en bot');
$_SERVER['HTTP_ACCEPT'] = 'text/html, application/json';

$_GET = ['p' => 'reminders', 'v' => 'today', 'tag' => 'none', 'co' => 'jarfalla', 'who' => 'all'];
$_SESSION['jeab_company'] = 'jarfalla';

$store = jeab_store();
$tagged = jeab_reminder_create_action($store, [
    'ui' => '1',
    'title' => 'Qa Vägen 8',
    'customer' => 'Qa Kund',
    'company' => 'jarfalla',
    'tags' => ['släpp'],
]);
$taggedId = (int) ($tagged['id'] ?? 0);
qa($taggedId > 0, 'skapad påminnelse har id');
qa(in_array('släpp', jeab_parse_tags($tagged['tags'] ?? []), true), 'taggen #släpp sparas');
qa(jeab_reminder_in_today($tagged), 'tomt datum i formuläret hamnar i Idag');
qa(empty($GLOBALS['_JEAB_REMINDER_SHOWN']), 'taggad rad syns inte under Utan tagg');
qa(is_array($GLOBALS['_JEAB_REMINDER_COUNTS'] ?? null), 'skapandet räknar flikarna innan filtret vidgas');

$_GET = ['p' => 'reminders', 'v' => 'today', 'tag' => 'none', 'co' => 'jarfalla', 'who' => 'all'];
unset($GLOBALS['_JEAB_REMINDER_SHOWN'], $GLOBALS['_JEAB_REMINDER_COUNTS']);
$plain = jeab_reminder_create_action($store, [
    'ui' => '1',
    'title' => 'Qa Vägen 9',
    'customer' => 'Qa Namn',
    'company' => 'jarfalla',
]);
$plainId = (int) ($plain['id'] ?? 0);
qa($plainId > 0 && !empty($GLOBALS['_JEAB_REMINDER_SHOWN']), 'rad utan tagg hör hemma i Idag / Utan tagg');
qa(jeab_reminder_in_today($plain), 'rad utan tagg är i Idag');

$html = jeab_reminder_row($plain, false);
$nameAt = strpos($html, 'class="item-shut-name"');
$addrAt = strpos($html, 'class="item-shut-addr"');
qa($nameAt !== false && $addrAt !== false && $addrAt < $nameAt, 'adressen ligger före namnet');
qa(str_contains($html, '>Qa Namn<'), 'kundnamnet syns på stängt kort');
qa(!str_contains($html, 'flag-form') && !str_contains($html, 'flag-btn'), 'flaggan finns inte på kortet');
qa(str_contains($html, 'name="id" value="' . $plainId . '"'), 'kortet har id i formuläret');

$done = jeab_toggle_reminder($store, $plainId, true);
qa(trim((string) ($done['completed_at'] ?? '')) !== '', 'Klar sätter completed_at');
$open = jeab_toggle_reminder($store, $plainId, false);
qa(trim((string) ($open['completed_at'] ?? '')) === '', 'Ångra tar bort completed_at');
$openHtml = jeab_reminder_row($open, true);
qa(str_contains($openHtml, 'data-js="snooze"') && str_contains($openHtml, 'value="2h"'), 'öppet kort efter ångra har knuffraden');
qa(str_contains($openHtml, 'name="id" value="' . $plainId . '"'), 'ångrat kort behåller id');

$snoozed = jeab_snooze_reminder($store, $plainId, '2h');
qa(!jeab_reminder_in_today($snoozed) && jeab_reminder_is_waiting($snoozed), '2h lämnar Idag och hamnar i Väntar');
qa(jeab_due_has_clock((string) ($snoozed['due_at'] ?? '')), '2h behåller klockslag');
$clock = (new DateTimeImmutable((string) $snoozed['due_at']))->format('H:i');
$snoozeHtml = jeab_reminder_row($snoozed, false);
qa(str_contains($snoozeHtml, $clock), 'klockslaget syns på kortet, fick ' . $clock);

$companyRows = jeab_list_reminders($store, true, 'jarfalla', null);
$tagNone = ['mode' => 'none', 'tag' => null];
$waitingRows = jeab_filter_reminders($companyRows, 'waiting', $tagNone, '');
$counts = jeab_reminder_counts($companyRows, $tagNone);
qa($counts['waiting'] === count($waitingRows), 'Väntar-siffran är antalet kort i Väntar');
$waitingIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $waitingRows);
qa(in_array($plainId, $waitingIds, true), 'knuffad rad finns i samma urval som siffran');

$root = dirname(__DIR__);
$js = (string) file_get_contents($root . '/assets/js/app.js');
$endAt = strpos($js, 'function endSwipe(');
$end = $endAt === false ? '' : substr($js, $endAt, 1400);
qa(str_contains($end, 'openReminderRow(s.row)'), 'ett tryck öppnar kortet på pointerup');
qa(str_contains($end, "gesture === 'open'"), 'bara ett tryck öppnar kortet');
qa(str_contains($end, "gesture !== 'open'") && strpos($end, '_jeabSwipeClickBlock') > strpos($end, "gesture !== 'open'"), 'svepet blockerar klicket');
qa(!preg_match('/function openReminderRow\([\s\S]{0,400}setTimeout\(/', $js), 'öppningen väntar inte på en timer');
$snoozeFnAt = strpos($js, 'function bindSnoozeForm(');
$snoozeFnEnd = strpos($js, "\n  function ", $snoozeFnAt === false ? 0 : $snoozeFnAt + 10);
$snoozeFn = ($snoozeFnAt === false || $snoozeFnEnd === false) ? '' : substr($js, $snoozeFnAt, $snoozeFnEnd - $snoozeFnAt);
qa(!str_contains($snoozeFn, 'queueSnoozeSave('), 'knuffens POST väntar med Ångra');
qa(str_contains($js, 'function reminderListMatches('), 'mjuk synk jämför listan med siffrorna');
qa(str_contains($js, 'wasOpen'), 'Klar på öppet kort minns att det var öppet');

$shell = (string) file_get_contents($root . '/assets/js/shell.js');
$paintAt = strpos($shell, 'function paintNow(');
$paint = $paintAt === false ? '' : substr($shell, $paintAt, 4000);
qa(str_contains($paint, 'isRemindersPage(url)') && str_contains($paint, 'grayList(main)'), 'Påminnelser byter bara listan mot staplar');
$remindersAt = strpos($paint, 'isRemindersPage(url)');
$grayAt = strpos($paint, 'grayList(main)');
qa($remindersAt !== false && $grayAt !== false && $remindersAt < $grayAt, 'listans staplar ligger i Påminnelser-grenen');

$css = (string) file_get_contents($root . '/assets/css/paminnelser.css');
qa(str_contains($css, 'opacity: 1'), 'filtret gör inte listan grå');
qa(str_contains($css, 'display: block'), 'namn och adress är block');
qa(str_contains($css, 'a.item-main::after') && str_contains($css, 'inset: 0') && str_contains($css, 'min-height: 44px'), 'hela kortet är träffyta, minst 44 px');
$shellSkip = strpos($shell, 'function skipLink(');
$shellSkipBody = $shellSkip === false ? '' : substr($shell, $shellSkip, 500);
qa(str_contains($shellSkipBody, 'a.item-main'), 'skalet tar inte över korttrycket');

$storeSrc = (string) file_get_contents($root . '/src/store.php');
qa(str_contains($storeSrc, 'BEGIN IMMEDIATE'), 'SQL läser om raden i en skrivlåsning');
$remSrc = (string) file_get_contents($root . '/src/reminders.php');
qa(str_contains($remSrc, 'function jeab_reminder_ort_skip_key_scan') && str_contains($remSrc, "'sync', 'more', 'page', 'pulse'"), 'sidvisning och ajax hoppar över ort-LIKE');

$passwords = $tmp . '/passwords.json';
file_put_contents($passwords, json_encode([
    'secret' => 'qa695-secret',
    'hashes' => [
        ['hash' => 'qa695-hash', 'email' => 'demo@example.test'],
    ],
], JSON_UNESCAPED_UNICODE));
putenv('JEAB_PASSWORDS_FILE=' . $passwords);
// #910: en inloggning utan person får inget bolag. Curl behöver en riktig person.
$_COOKIE['jeab_auth'] = (string) jeab_auth_issue_value('demo@example.test', 'qa695-hash');
qa(jeab_auth_ok(), 'curl-testaren är inloggad');

$router = $tmp . '/router.php';
$workspace = dirname(__DIR__);
file_put_contents($router, <<<PHP
<?php
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
for ($try = 8931; $try < 8950; $try++) {
    $socket = @stream_socket_server('tcp://127.0.0.1:' . $try, $errno, $errstr);
    if ($socket === false) {
        continue;
    }
    fclose($socket);
    $port = $try;
    break;
}
qa($port > 0, 'en lokal port är ledig');
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $workspace, $router],
    [1 => ['file', $tmp . '/server.out', 'w'], 2 => ['file', $tmp . '/server.err', 'w']],
    $pipes,
    $workspace,
    [
        'JEAB_QA_DATA' => $tmp,
        'JEAB_PASSWORDS_FILE' => $passwords,
        'JEAB_DISABLE_SQLITE' => '1',
    ]
);
qa(is_resource($server), 'php-servern startade');
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
qa($ready, 'servern svarar');

$cookie = (string) $_COOKIE['jeab_auth'];
$csrf = (string) jeab_csrf_token();

function qa_post(int $port, string $cookie, array $fields, string $tag = 'none'): array
{
    $qs = 'p=reminders&v=today&tag=' . rawurlencode($tag) . '&co=jarfalla&who=all&ws=' . time();
    $ch = curl_init('http://127.0.0.1:' . $port . '/index.php?' . $qs);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_HTTPHEADER => [
            'X-Requested-With: XMLHttpRequest',
            'Accept: application/json',
            'Cookie: jeab_auth=' . $cookie,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($body, true);
    return [$code, is_array($json) ? $json : [], $body];
}

[$code, $json, $body] = qa_post($port, $cookie, [
    '_csrf' => $csrf,
    'action' => 'reminder.create',
    'ui' => '1',
    'title' => 'Qa Curlvägen 1',
    'customer' => 'Curl Kund',
    'company' => 'jarfalla',
    'tags' => ['släpp'],
]);
qa($code === 200 && !empty($json['ok']) && (int) ($json['id'] ?? 0) > 0, 'curl skapa svarar med id, fick ' . $code . ' ' . substr($body, 0, 180));
qa(in_array('släpp', $json['tags'] ?? [], true), 'curl sparar taggen');
qa(str_contains((string) ($json['html'] ?? ''), 'Curl Kund') && !str_contains((string) ($json['html'] ?? ''), 'flag-btn'), 'curl-kortet har namn och ingen flagga');
qa(isset($json['counts']['today'], $json['counts']['waiting']), 'curl skickar fliksiffror');
$curlId = (int) $json['id'];

[$code, $json, $body] = qa_post($port, $cookie, [
    '_csrf' => $csrf,
    'action' => 'reminder.toggle',
    'id' => $curlId,
    'done' => '1',
]);
qa($code === 200 && !empty($json['ok']), 'curl Klar, fick ' . $code . ' ' . substr($body, 0, 120));
[$code, $json, $body] = qa_post($port, $cookie, [
    '_csrf' => $csrf,
    'action' => 'reminder.toggle',
    'id' => $curlId,
    'done' => '0',
]);
qa($code === 200 && str_contains((string) ($json['html'] ?? ''), 'data-js="snooze"'), 'curl Ångra ritar knuffraden, fick ' . substr($body, 0, 120));
$after = jeab_get_reminder(jeab_store(), $curlId);
qa(is_array($after) && trim((string) ($after['completed_at'] ?? '')) === '', 'curl Ångra ligger kvar efter omläsning');

[$code, $json, $body] = qa_post($port, $cookie, [
    '_csrf' => $csrf,
    'action' => 'reminder.snooze',
    'id' => $curlId,
    'by' => '2h',
], 'all');
$dueLabel = (string) ($json['due'] ?? '');
qa($code === 200 && preg_match('/\d{2}:\d{2}/', $dueLabel) === 1, 'curl 2h visar klockslag, fick ' . $dueLabel);
qa(isset($json['counts']['waiting']) && (int) $json['counts']['waiting'] >= 1, 'curl Väntar-siffran följer med, fick ' . json_encode($json['counts'] ?? null));

$originalDue = '2026-10-23 12:54:00';
$shiftedDue = '2026-10-23 18:54:00';
[$code, $json, $body] = qa_post($port, $cookie, [
    '_csrf' => $csrf,
    'action' => 'reminder.due',
    'id' => $curlId,
    'due_at' => $originalDue,
    'completed_at' => '',
]);
qa($code === 200 && (string) ($json['row']['due_at'] ?? '') === $originalDue, 'curl sätter 12:54, fick ' . (string) ($json['row']['due_at'] ?? substr($body, 0, 120)));
[$code, $json, $body] = qa_post($port, $cookie, [
    '_csrf' => $csrf,
    'action' => 'reminder.due',
    'id' => $curlId,
    'due_at' => $shiftedDue,
    'completed_at' => '',
]);
qa($code === 200 && (string) ($json['row']['due_at'] ?? '') === $shiftedDue, 'curl knuffar till 18:54, fick ' . (string) ($json['row']['due_at'] ?? ''));
[$code, $json, $body] = qa_post($port, $cookie, [
    '_csrf' => $csrf,
    'action' => 'reminder.due',
    'id' => $curlId,
    'due_at' => '2026-10-23T12:54:00.000Z',
    'completed_at' => '',
]);
qa($code === 200 && (string) ($json['row']['due_at'] ?? '') === $originalDue, 'curl Ångra med Z återställer 12:54, fick ' . (string) ($json['row']['due_at'] ?? substr($body, 0, 160)));
qa(str_contains((string) ($json['html'] ?? ''), 'data-due-at="2026-10-23 12:54:00"'), 'curl-svaret ritar kortet med 12:54');
$storedDue = jeab_get_reminder(jeab_store(true), $curlId);
qa(is_array($storedDue) && (string) ($storedDue['due_at'] ?? '') === $originalDue, 'curl Ångra ligger kvar som 12:54 efter omläsning, fick ' . (string) ($storedDue['due_at'] ?? 'null'));

echo "ALLA OK\n";
