<?php

declare(strict_types=1);

/**
 * Optimistisk UI: påminnelser (stäng, parkera, tilldela, tagga), orderstatus
 * och små kontaktfält. Utskick och Fortnox väntar. Påhittade rader.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-optimistic-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
$passwords = $tmp . '/passwords.json';
file_put_contents($passwords, json_encode([
    'secret' => 'optimistic-test-secret',
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

function ou(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$jsOpt = (string) file_get_contents($root . '/assets/js/optimistic.js');
$jsApp = (string) file_get_contents($root . '/assets/js/app.js');
$jsV3 = (string) file_get_contents($root . '/assets/js/ordrar-v3.js');
$cssV3 = (string) file_get_contents($root . '/assets/css/ordrar-v3.css');
$kt = (string) file_get_contents($root . '/src/pages/kontakter.php');
$layout = (string) file_get_contents($root . '/src/layout.php');
ou(str_contains($layout, 'assets/js/optimistic.js'), 'layout laddar optimistic.js');
ou(str_contains($jsApp, 'markReminderDone') && str_contains($jsApp, 'Ångra'), 'stäng behåller Klar och Ångra');
ou(str_contains($jsApp, 'commitReminderDone(pending);') && str_contains($jsApp, "data.set('done', '0')"), 'stäng skickas direkt och ångra vänder med done=0');
ou(str_contains($jsApp, 'paintWho(row, btn)') && str_contains($jsApp, 'restorePark(row, snap)'), 'tilldela och parkera ritas före svaret');
ou(str_contains($jsApp, "btn.classList.toggle('is-on');") && str_contains($jsApp, "showReminderBar('Kunde inte spara.', false, 2800)"), 'tagg och feltoast återställer');
ou(str_contains($jsV3, 'ordrar.foranmalan-status') && str_contains($jsV3, 'ordrar.bestall_steg') && str_contains($jsV3, "toast('Kunde inte spara.')"), 'orderstatus sparas i bakgrunden');
ou(str_contains($jsV3, "toast('Kunde inte kopiera')") && str_contains($jsV3, '.then(copied, failed)'), 'Kopiera toastar Kopierat bara när clipboard lyckas');
ou(str_contains($jsV3, 'tiden bokad') && str_contains($jsV3, 'data-v3-undo') && str_contains($jsV3, "btn.textContent = 'Ångra'") && str_contains($jsV3, 'booking.cancel'), 'toast tiden bokad har Ångra som avbokar');
ou(str_contains($cssV3, '.v3-toast.has-undo') && str_contains($cssV3, '.v3-toast-undo') && str_contains($cssV3, 'pointer-events: auto'), 'Ångra i toasten går att trycka');
ou(str_contains($jsV3, "if (mode === 'B' || mode === 'C')") && !str_contains($jsV3, "reloadTo('foranmalan', value === 'B'"), 'B/C stänger inte steget utan knapptryck');
ou(str_contains($jsV3, 'function persistNat') && str_contains($jsV3, "t.name === 'natbolag'") && str_contains($jsV3, "action: 'ordrar.objekt'"), 'nätbolagsval sparas vid val utan att skicka föranmälan');
ou(str_contains($jsV3, "section.classList.add('is-done')") && str_contains($jsV3, 'SUCCESS.stang'), 'stäng ärendet ritas innan svaret');
ou(str_contains($jsV3, 'setWorking(btn, true)'), 'utskick och Fortnox väntar fortfarande på knappen');
ou(!str_contains($jsV3, "persistStatus(form, 'fortnox'") && !preg_match("/persistStatus\\([^\\n]*ordrar\\.(fortnox|slutfor|foranmalan'|boka')/", $jsV3), 'persistStatus tar inte utskick eller Fortnox');
ou(str_contains($kt, 'data-kt-optimistic') && str_contains($kt, 'Kunde inte spara.') && str_contains($kt, 'owner_pnr_1'), 'kontaktfält sparas i bakgrunden och personnummer är tungt');
ou(!str_contains($kt, 'ordrar.fortnox') && !str_contains($jsApp, 'inkorg.thread'), 'påminnelsekoden rör inte inkorg eller Fortnox');

$node = proc_open(
    ['node', '-e', <<<'JS'
const { jeabParkDueLabel: due, jeabClickWaits: waits } = require(process.env.JEAB_OPT);
const now = new Date(2026, 9, 20, 15, 0, 0);
function eq(a, b, msg) {
  if (a !== b) {
    console.error(msg + ' fick ' + JSON.stringify(a));
    process.exit(1);
  }
}
eq(due('2h', now, ''), 'idag', '2h samma dag');
eq(due('2d', now, '2026-10-20'), 'tor 22 okt', '2d');
eq(due('1w', now, '2026-10-20'), 'tis 27 okt', '1v');
eq(due('2d', now, '2026-10-25'), 'tis 27 okt', '2d från senare datum');
eq(waits('ordrar.fortnox', {}), true, 'fortnox väntar');
eq(waits('ordrar.slutfor', {}), true, 'slutfor väntar');
eq(waits('ordrar.foranmalan', {}), true, 'föranmälan-utskick väntar');
eq(waits('ordrar.foranmalan_resend', {}), true, 'skicka igen väntar');
eq(waits('ordrar.fullmakt_resend', {}), true, 'fullmakt väntar');
eq(waits('ordrar.boka', {}), true, 'bokning väntar');
eq(waits('ordrar.bestall', {}), true, 'beställ väntar');
eq(waits('ordrar.bestall_steg', { intent: 'next' }), true, 'nästa på beställ väntar');
eq(waits('ordrar.foranmalan-status', {}), false, 'föranmälan-status är direkt');
eq(waits('ordrar.bestall_steg', {}), false, 'status utan nästa är direkt');
eq(waits('ordrar.stang', {}), false, 'stäng ärende är direkt');
eq(waits('reminder.tag', { tag: 'admin' }), false, 'tagg är direkt');
eq(waits('reminder.tag', { tag: 'vunnen' }), true, 'vunnen väntar');
eq(waits('reminder.who', {}), false, 'tilldela är direkt');
eq(waits('reminder.snooze', {}), false, 'parkera är direkt');
eq(waits('reminder.toggle', {}), false, 'stäng påminnelse är direkt');
eq(waits('kontakter.save', {}), false, 'kontaktfält är direkt');
JS],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $nodePipes,
    null,
    ['JEAB_OPT' => $root . '/assets/js/optimistic.js']
);
$nodeOut = stream_get_contents($nodePipes[1]);
$nodeErr = stream_get_contents($nodePipes[2]);
fclose($nodePipes[1]);
fclose($nodePipes[2]);
ou(proc_close($node) === 0, 'datum och väntelista' . ($nodeErr !== '' ? ' ' . trim($nodeErr) : '') . ($nodeOut !== '' ? ' ' . trim($nodeOut) : ''));

jeab_ordrar_external_live(false);
$_COOKIE['jeab_auth'] = (string) jeab_auth_issue_value('demo@example.test', 'sam-hash-fixture');
ou(jeab_auth_ok(), 'testanvändaren är inloggad');

$store = jeab_store();
$created = jeab_create_reminder($store, 'Exempelgatan 4');
$rid = (int) ($created['id'] ?? 0);
ou($rid > 0, 'påminnelse skapad');
jeab_toggle_reminder_tag($store, $rid, 'vunnen');
$purchase = jeab_purchase_start($store, $rid, 'batteri');
ou((int) ($purchase['reminder_id'] ?? 0) === $rid, 'order skapad');
$contact = jeab_kontakt_save_input([
    'street' => 'Exempelgatan 4',
    'postal_code' => '177 40',
    'city' => 'Jarfalla',
    'owner_name_1' => 'Test Person',
    'email' => 'test.person@example.com',
    'phone' => '',
    'company' => 'jarfalla',
    'contact_person' => '1',
]);
$cid = (string) ($contact['id'] ?? '');
ou($cid !== '' && ($contact['email'] ?? '') === '', 'kontakt skapad utan e-post i svaret eller med id');
$public = jeab_kontakt_get($cid);
ou(is_array($public) && (string) ($public['street'] ?? '') === 'Exempelgatan 4', 'kontakten ligger kvar');

$router = $tmp . '/router.php';
file_put_contents($router, <<<'PHP'
<?php
$GLOBALS['_JEAB_DATA_DIR'] = getenv('JEAB_OPT_DATA');
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = getenv('JEAB_OPT_DATA');
require getenv('JEAB_ROOT') . '/index.php';
PHP);
$port = 0;
for ($try = 8791; $try < 8810; $try++) {
    $socket = @stream_socket_server('tcp://127.0.0.1:' . $try, $errno, $errstr);
    if ($socket === false) {
        continue;
    }
    fclose($socket);
    $port = $try;
    break;
}
ou($port > 0, 'en lokal port är ledig');
$log = $tmp . '/server.log';
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, $router],
    [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
    $serverPipes,
    null,
    [
        'JEAB_OPT_DATA' => $tmp,
        'JEAB_ROOT' => $root,
        'JEAB_PASSWORDS_FILE' => $passwords,
        'JEAB_DISABLE_SQLITE' => '0',
        'XAI_API_KEY' => '',
    ]
);
ou(is_resource($server), 'php-servern startade');
register_shutdown_function(static function () use ($server): void {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
});
$ready = false;
for ($i = 0; $i < 40; $i++) {
    $probe = @file_get_contents('http://127.0.0.1:' . $port . '/index.php?p=csrf');
    if (is_string($probe) && $probe !== '') {
        $ready = true;
        break;
    }
    usleep(100000);
}
ou($ready, 'servern svarar');

$cookie = (string) $_COOKIE['jeab_auth'];
$csrf = (string) jeab_csrf_token();
$base = 'http://127.0.0.1:' . $port . '/index.php';
$curlJson = function (array $post) use ($base, $cookie, $csrf): array {
    $post['_csrf'] = $post['_csrf'] ?? $csrf;
    $ch = curl_init($base);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post),
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
    return ['code' => $code, 'body' => $body, 'json' => is_array($json) ? $json : null];
};

$freshReminder = static function () use ($rid): array {
    $row = jeab_get_reminder(jeab_store(), $rid);
    return is_array($row) ? $row : [];
};

$tag = $curlJson(['action' => 'reminder.tag', 'id' => $rid, 'tag' => 'admin']);
$afterTag = $freshReminder();
ou($tag['code'] === 200 && ($tag['json']['ok'] ?? false) === true && in_array('admin', $afterTag['tags'] ?? [], true), 'curl taggar, fick ' . $tag['code'] . ' ' . substr($tag['body'], 0, 160));

$bad = $curlJson(['action' => 'reminder.tag', 'id' => $rid, 'tag' => 'finns-inte']);
$afterBad = $freshReminder();
ou($bad['code'] >= 400 && ($bad['json']['ok'] ?? true) === false && in_array('admin', $afterBad['tags'] ?? [], true) && !in_array('finns-inte', $afterBad['tags'] ?? [], true), 'ogiltig tagg ändrar inget, fick ' . $bad['code']);

$who = $curlJson(['action' => 'reminder.who', 'id' => $rid, 'who' => 'Elin', 'assignee' => 'Elin', 'company' => 'jarfalla']);
$afterWho = $freshReminder();
ou($who['code'] === 200 && in_array('Elin', jeab_assignees_from_row($afterWho), true), 'curl tilldelar Elin, fick ' . $who['code'] . ' ' . substr($who['body'], 0, 160));

$beforeDue = (string) ($afterWho['due_at'] ?? '');
$park = $curlJson(['action' => 'reminder.snooze', 'id' => $rid, 'by' => '2d']);
$afterPark = $freshReminder();
ou($park['code'] === 200 && ($park['json']['due'] ?? '') !== '' && (string) ($afterPark['due_at'] ?? '') !== '' && (string) $afterPark['due_at'] !== $beforeDue, 'curl parkerar 2d, fick ' . $park['code'] . ' ' . substr($park['body'], 0, 180));

$close = $curlJson(['action' => 'reminder.toggle', 'id' => $rid, 'done' => '1']);
ou($close['code'] === 200 && !empty($freshReminder()['completed_at']), 'curl stänger påminnelsen');
$reopen = $curlJson(['action' => 'reminder.toggle', 'id' => $rid, 'done' => '0']);
ou($reopen['code'] === 200 && empty($freshReminder()['completed_at']), 'curl ångra öppnar påminnelsen');

$status = $curlJson(['action' => 'ordrar.foranmalan-status', 'id' => $rid, 'foranmalan_status' => 'B', 'v3' => '1']);
$order = is_array($status['json']['row'] ?? null) ? $status['json']['row'] : [];
$foranStatus = (string) ($order['foranmalan_status'] ?? '');
$foranStep = trim((string) ((is_array($order['steps'] ?? null) ? $order['steps'] : [])['foranmald'] ?? ''));
ou($status['code'] === 200 && $foranStatus === 'B' && $foranStep === '', 'curl byter föranmälan-status utan att skicka, fick ' . $status['code'] . ' status=' . $foranStatus . ' foranmald=' . $foranStep . ' ' . substr($status['body'], 0, 240));

$bestall = $curlJson(['action' => 'ordrar.bestall_steg', 'id' => $rid, 'bestallning_status' => 'C', 'v3' => '1']);
$order = is_array($bestall['json']['row'] ?? null) ? $bestall['json']['row'] : [];
ou($bestall['code'] === 200 && (string) ($order['bestallning_status'] ?? '') === 'C' && trim((string) ((is_array($order['steps'] ?? null) ? $order['steps'] : [])['bestalld'] ?? '')) === '', 'curl byter beställningsstatus utan Bokmalen, fick ' . $bestall['code'] . ' status=' . (string) ($order['bestallning_status'] ?? '') . ' ' . substr($bestall['body'], 0, 180));

$blocked = $curlJson(['action' => 'ordrar.stang', 'id' => $rid, 'v3' => '1']);
ou($blocked['code'] === 422 && str_contains((string) ($blocked['json']['error'] ?? ''), 'Fortnox'), 'stäng utan faktura svarar fel så gränssnittet kan ångra, fick ' . $blocked['code']);
$patched = jeab_purchase_patch($rid, static function (array $row): array {
    $steps = is_array($row['steps'] ?? null) ? $row['steps'] : [];
    $steps['faktura_skapad'] = '2026-10-20 12:00:00';
    $row['steps'] = $steps;
    return $row;
});
ou(is_array($patched) && jeab_ordrar_has_invoice($patched), 'testordern har en lokal fakturamarkering');
$stang = $curlJson(['action' => 'ordrar.stang', 'id' => $rid, 'v3' => '1']);
$order = is_array($stang['json']['row'] ?? null) ? $stang['json']['row'] : [];
ou($stang['code'] === 200 && trim((string) ($order['closed_at'] ?? '')) !== '', 'curl stänger ärendet, fick ' . $stang['code'] . ' ' . substr($stang['body'], 0, 160));

$owners = is_array($public['owners'] ?? null) ? $public['owners'] : [];
$mail = $curlJson([
    'action' => 'kontakter.save',
    'id' => $cid,
    'company' => 'jarfalla',
    'street' => 'Exempelgatan 4',
    'postal_code' => '177 40',
    'city' => 'Jarfalla',
    'owner_name_1' => 'Test Person',
    'email' => 'test.person@example.com',
    'phone' => '0701234567',
    'contact_person' => '1',
]);
$savedRow = is_array($mail['json']['row'] ?? null) ? $mail['json']['row'] : [];
$savedOwners = is_array($savedRow['owners'] ?? null) ? $savedRow['owners'] : [];
$savedPhone = (string) ($savedOwners[0]['phone'] ?? '');
ou($mail['code'] === 200 && ($mail['json']['ok'] ?? false) === true && str_contains($savedPhone, '701234567'), 'curl sparar telefon på kontakten, fick ' . $mail['code'] . ' ' . $savedPhone);

$failStreet = $curlJson([
    'action' => 'kontakter.save',
    'id' => $cid,
    'company' => 'jarfalla',
    'street' => '',
    'postal_code' => '177 40',
    'city' => 'Jarfalla',
    'owner_name_1' => 'Test Person',
    'phone' => '0701234567',
    'contact_person' => '1',
]);
unset($GLOBALS['_JEAB_KONTAKTER_MEM'], $GLOBALS['_JEAB_KONTAKTER_MEM_KEY']);
$keptRow = jeab_kontakt_get($cid);
$keptRow = is_array($keptRow) ? $keptRow : [];
$keptStreet = (string) ($keptRow['street'] ?? '');
ou($failStreet['code'] >= 400 && ($failStreet['json']['ok'] ?? true) === false && $keptStreet === 'Exempelgatan 4', 'tom gata lämnar gatan orörd, fick ' . $failStreet['code'] . ' street=' . $keptStreet);
ou(!is_file($tmp . '/kontakter.json'), 'sparningen skriver inte kontakter.json');

if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}

echo "OK  optimistisk ui\n";
