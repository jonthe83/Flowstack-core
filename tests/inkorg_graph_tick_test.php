<?php

declare(strict_types=1);

/** Hook inbox.graph_catchup, listlucka efter tom delta, tickordning och poll som syns i status. */

$tmp = sys_get_temp_dir() . '/jeab-graph-tick-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export(['timezone' => 'Europe/Stockholm', 'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json']], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/tick.php';

function gt(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function gt_msg(string $id, string $received, string $subject = 'Hej'): array
{
    return [
        'id' => $id,
        'internetMessageId' => '<' . $id . '@kund>',
        'conversationId' => 'C-' . $id,
        'from' => ['emailAddress' => ['address' => strtolower($id) . '@ex.se', 'name' => 'Kund']],
        'toRecipients' => [['emailAddress' => ['address' => 'kontakt@jarfalla.example.test']]],
        'subject' => $subject,
        'receivedDateTime' => $received,
        'body' => ['content' => 'Hej'],
        'isDraft' => false,
    ];
}

jeab_graph_secret_save(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's']);

$hookUrls = [];
$sent = 0;
$GLOBALS['_JEAB_GRAPH_HTTP'] = function (string $method, string $url, ?array $body = null, array $headers = []) use (&$hookUrls, &$sent): array {
    unset($body, $headers);
    $hookUrls[] = [$method, $url];
    if ($method === 'POST' && (str_contains($url, '/sendMail') || str_contains($url, '/reply'))) {
        $sent++;

        return ['code' => 202, 'body' => []];
    }
    if (str_contains($url, '/messages?')) {
        return ['code' => 200, 'body' => ['value' => [[
            'id' => 'MISS1',
            'receivedDateTime' => '2026-10-21T18:40:00Z',
            'subject' => 'Solceller 19800101-1234 tack',
        ]]]];
    }
    if (str_contains($url, '/messages/MISS1')) {
        return ['code' => 200, 'body' => gt_msg('MISS1', '2026-10-21T18:40:00Z', 'Solceller 19800101-1234 tack')];
    }

    return ['code' => 500, 'body' => ['error' => ['message' => 'oväntat']]];
};

$dryHook = jeab_inkorg_hook(['action' => 'inbox.graph_catchup', 'company' => 'jarfalla', 'since' => '2026-10-21T18:00:00Z']);
$dryJson = (string) json_encode($dryHook);
gt(($dryHook['ok'] ?? false) === true && ($dryHook['dry'] ?? false) === true, 'hook utan dry är torrkörning');
gt(($dryHook['error'] ?? '') !== 'not_found', 'inbox.graph_catchup finns i kroken');
gt(($dryHook['missing'][0]['subject'] ?? '') === 'Solceller [pnr] tack', 'hooken maskar personnummer');
gt(!str_contains($dryJson, '19800101') && !str_contains($dryJson, '@'), 'hooksvaret har varken personnummer eller mejladress');
$fetched = false;
foreach ($hookUrls as $call) {
    if (str_contains($call[1], '/messages/MISS1')) {
        $fetched = true;
    }
}
gt(!$fetched, 'torr hook hämtar inte mejlet');
$before = count(jeab_inkorg_messages());
$wetHook = jeab_inkorg_hook(['action' => 'inbox.graph_catchup', 'company' => 'jarfalla', 'since' => '2026-10-21T18:00:00Z', 'dry' => '0']);
gt(($wetHook['dry'] ?? true) === false && ($wetHook['ingested'][0]['auto'] ?? true) === false, 'dry=0 tar in med auto av');
gt(count(jeab_inkorg_messages()) === $before + 1 && $sent === 0, 'dry=0 skickar inget och lägger en rad');
$wetZero = jeab_inkorg_hook(['action' => 'inbox.graph_catchup', 'company' => 'jarfalla', 'since' => '2026-10-21T18:00:00Z', 'dry' => 0]);
gt(($wetZero['dry'] ?? true) === false, 'dry=0 som tal är våt');

$inboxToken = 'ink_' . bin2hex(random_bytes(8));
$httpData = sys_get_temp_dir() . '/jeab-graph-tick-hookdata-' . bin2hex(random_bytes(4));
mkdir($httpData . '/secrets', 0775, true);
file_put_contents($httpData . '/config.php', "<?php\nreturn " . var_export(['timezone' => 'Europe/Stockholm', 'db' => ['driver' => 'json', 'sqlite_path' => $httpData . '/app.sqlite', 'json_path' => $httpData . '/store.json']], true) . ";\n");
file_put_contents($httpData . '/secrets/inkorg-ingest.json', json_encode(['key' => $inboxToken]));
$httpRoot = sys_get_temp_dir() . '/jeab-graph-tick-http-' . bin2hex(random_bytes(4));
mkdir($httpRoot . '/empty', 0775, true);
file_put_contents($httpRoot . '/router.php', <<<'PHP'
<?php
$data = getenv('JEAB_GT_DATA');
$root = getenv('JEAB_GT_ROOT');
$GLOBALS['_JEAB_DATA_DIR'] = $data;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $data . '/public';
if (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) === '/hook.php') {
    require $root . '/hook.php';
    return true;
}
http_response_code(404);
echo 'no';
return true;
PHP);
require_once __DIR__ . '/jeab_test_proc_env.php';
jeab_test_router_boot($httpRoot . '/router.php');
$probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
gt($probe !== false, 'kunde inte välja port');
$addr = stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr((string) strrchr((string) $addr, ':'), 1);
$log = $httpRoot . '/php.log';
$cmd = [
    PHP_BINARY,
    '-d', 'display_errors=0',
    '-d', 'log_errors=1',
    '-d', 'error_log=' . $log,
    '-S', '127.0.0.1:' . $port,
    '-t', $httpRoot . '/empty',
    $httpRoot . '/router.php',
];
$env = getenv();
if (!is_array($env)) {
    $env = [];
}
$env['JEAB_DISABLE_SQLITE'] = '1';
$env['JEAB_GT_DATA'] = $httpData;
$env['JEAB_GT_ROOT'] = dirname(__DIR__);
$proc = proc_open(
    $cmd,
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
    $pipes,
    null,
    $env
);
gt(is_resource($proc), 'lokal PHP-server startade');
register_shutdown_function(static function () use ($proc): void {
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
});
$up = false;
for ($i = 0; $i < 50; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $eNo, $eStr, 0.2);
    if (is_resource($fp)) {
        fclose($fp);
        $up = true;
        break;
    }
    usleep(100000);
}
gt($up, 'testservern lyssnar');
$ch = curl_init('http://127.0.0.1:' . $port . '/hook.php');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['action' => 'inbox.graph_catchup', 'company' => 'jarfalla', 'since' => '2026-10-21T18:00:00Z']),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $inboxToken],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 8,
]);
$raw = curl_exec($ch);
$httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$httpJson = json_decode(is_string($raw) ? $raw : '', true);
gt($httpStatus !== 404 && is_array($httpJson) && ($httpJson['error'] ?? '') !== 'not_found', 'hook.php svarar inte not_found för inbox.graph_catchup, fick ' . $httpStatus);
gt(($httpJson['dry'] ?? false) === true && !str_contains((string) $raw, $inboxToken) && !str_contains((string) $raw, '@'), 'hook.php är torr som standard och läcker inget');

jeab_graph_ingest_message('jarfalla', gt_msg('OLD1', '2026-10-22T10:00:00Z', 'Redan inne'), false);
jeab_graph_state_update(static function (array &$d): bool {
    $d['delta']['jarfalla'] = 'https://graph.microsoft.com/v1.0/delta?$deltatoken=gap';
    $d['delta_progress']['jarfalla'] = '2099-01-01T00:00:00Z';
    $d['delta_ok_at']['jarfalla'] = strtotime('2026-10-21T16:15:00Z');
    unset($d['delta_list']['jarfalla'], $d['delta_listed_through']['jarfalla'], $d['delta_since']['jarfalla']);

    return true;
});
$order = [];
$gapSent = 0;
$GLOBALS['_JEAB_GRAPH_HTTP'] = function (string $method, string $url, ?array $body = null, array $headers = []) use (&$order, &$gapSent): array {
    unset($body, $headers);
    $order[] = $url;
    if ($method === 'POST' && (str_contains($url, '/sendMail') || str_contains($url, '/reply'))) {
        $gapSent++;

        return ['code' => 202, 'body' => []];
    }
    if (str_contains($url, 'deltatoken=gap')) {
        return ['code' => 200, 'body' => ['value' => [], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/delta?$deltatoken=gap2']];
    }
    if (str_contains($url, 'orderby')) {
        return ['code' => 400, 'body' => ['error' => ['message' => 'The restriction or sort order is too complex for this operation.']]];
    }
    if (str_contains($url, "/messages?")) {
        return ['code' => 200, 'body' => ['value' => [[
            'id' => 'GAP1',
            'receivedDateTime' => '2026-10-22T12:00:00Z',
        ]]]];
    }
    if (str_contains($url, '/messages/GAP1')) {
        return ['code' => 200, 'body' => gt_msg('GAP1', '2026-10-22T12:00:00Z', 'Nytt efter luckan')];
    }

    return ['code' => 500, 'body' => ['error' => ['message' => 'oväntat ' . $url]]];
};
$gap = jeab_graph_delta(0, 3);
$box = (array) ($gap['boxes']['jarfalla'] ?? []);
gt((int) ($box['new'] ?? 0) === 1, 'listan tar in mejlet som en tom deltaLink hoppade över, new=' . (int) ($box['new'] ?? 0));
$storedGap = false;
foreach (jeab_inkorg_messages() as $m) {
    if ((string) ($m['graph_id'] ?? '') === 'GAP1') {
        $storedGap = true;
    }
}
gt($storedGap, 'GAP1 ligger i inkorgen');
gt($gapSent === 0, 'gammal lucka skickar ingen offert');
$gapRow = null;
foreach (jeab_inkorg_messages() as $m) {
    if ((string) ($m['graph_id'] ?? '') === 'GAP1') {
        $gapRow = $m;
    }
}
gt(is_array($gapRow) && (int) ($gapRow['backfilled'] ?? 0) === 1, 'gammalt mejl märks backfilled=1');
unset($GLOBALS['_JEAB_INKORG_META'], $GLOBALS['_JEAB_INKORG_META_FP']);
gt((int) (jeab_inkorg_thread_meta((string) ($gapRow['thread_id'] ?? ''))['backfilled'] ?? 0) === 1, 'ärendet märks backfilled=1');
$listed = jeab_graph_catchup('jarfalla', '2026-10-22T11:00:00Z', true);
$listedJson = (string) json_encode($listed);
$noOfferIds = array_column((array) ($listed['no_offer'] ?? []), 'id');
gt(in_array('GAP1', $noOfferIds, true), 'dry=1 visar mejl som inte fick offert');
$gapOffer = null;
foreach ((array) ($listed['no_offer'] ?? []) as $row) {
    if ((string) ($row['id'] ?? '') === 'GAP1') {
        $gapOffer = $row;
    }
}
gt(is_array($gapOffer) && ($gapOffer['offer'] ?? true) === false && (int) ($gapOffer['backfilled'] ?? 0) === 1, 'dry=1 säger att offerten uteblev');
gt(!str_contains($listedJson, '@') && !str_contains($listedJson, '19800101'), 'dry=1 läcker varken adress eller personnummer');
gt(str_contains((string) (jeab_graph_state()['delta']['jarfalla'] ?? ''), 'deltatoken=gap2'), 'listans 400 nollställer inte deltatoken');
$listUrl = '';
$deltaAt = null;
$listAt = null;
foreach ($order as $i => $url) {
    if ($deltaAt === null && str_contains($url, 'deltatoken=gap')) {
        $deltaAt = $i;
    }
    if ($listAt === null && str_contains($url, "/messages?") && !str_contains($url, '/messages/delta')) {
        $listAt = $i;
        $listUrl = $url;
    }
}
gt($deltaAt !== null && $listAt !== null && $deltaAt < $listAt, 'deltat följs före listluckan');
gt(str_contains($listUrl, '2026-10-22T09:50:00Z') || str_contains($listUrl, '2026-10-22T09%3A50%3A00Z'), 'listan börjar vid senaste sparade mejlet minus 10 min, fick ' . $listUrl);
gt(!str_contains($listUrl, '2099'), 'förstörd delta_progress används inte som golv');
gt((int) (jeab_graph_state()['delta_listed_through']['jarfalla'] ?? 0) > 0 && !isset(jeab_graph_state()['delta_list']['jarfalla']), 'avslutad lista stämplas');
$order = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = function (string $method, string $url, ?array $body = null, array $headers = []) use (&$order): array {
    unset($method, $body, $headers);
    $order[] = $url;
    if (str_contains($url, 'deltatoken=')) {
        return ['code' => 200, 'body' => ['value' => [], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/delta?$deltatoken=gap3']];
    }

    return ['code' => 500, 'body' => ['error' => ['message' => 'oväntat']]];
};
jeab_graph_delta(0, 3);
$listedAgain = false;
foreach ($order as $url) {
    if (str_contains($url, "/messages?") && !str_contains($url, '/messages/delta')) {
        $listedAgain = true;
    }
}
gt(!$listedAgain, 'nästa tick listar inte om luckan');

$freshAt = gmdate('Y-m-d\TH:i:s\Z', time() - 300);
$oldAt = gmdate('Y-m-d\TH:i:s\Z', time() - 72 * 3600);
$ageSent = 0;
$GLOBALS['_JEAB_GRAPH_HTTP'] = function (string $method, string $url, ?array $body = null, array $headers = []) use (&$ageSent, $freshAt, $oldAt): array {
    unset($body, $headers);
    if ($method === 'POST' && (str_contains($url, '/sendMail') || str_contains($url, '/reply'))) {
        $ageSent++;

        return ['code' => 202, 'body' => []];
    }
    if (str_contains($url, '/messages/OLDX')) {
        return ['code' => 200, 'body' => gt_msg('OLDX', $oldAt, 'Gammalt')];
    }
    if (str_contains($url, '/messages/FRESH')) {
        return ['code' => 200, 'body' => gt_msg('FRESH', $freshAt, 'Nytt')];
    }

    return ['code' => 500, 'body' => ['error' => ['message' => 'oväntat']]];
};
$ageSent = 0;
jeab_graph_ingest_backfill('jarfalla', 'OLDX', $oldAt);
gt($ageSent === 0, 'backfill äldre än 48 timmar skickar inget');
$oldRow = null;
foreach (jeab_inkorg_messages() as $m) {
    if ((string) ($m['graph_id'] ?? '') === 'OLDX') {
        $oldRow = $m;
    }
}
gt(is_array($oldRow) && (int) ($oldRow['backfilled'] ?? 0) === 1, 'OLDX är backfilled');
$ageSent = 0;
$freshRes = jeab_graph_ingest_backfill('jarfalla', 'FRESH', $freshAt);
$freshRow = null;
foreach (jeab_inkorg_messages() as $m) {
    if ((string) ($m['graph_id'] ?? '') === 'FRESH') {
        $freshRow = $m;
    }
}
gt(is_array($freshRow) && (int) ($freshRow['backfilled'] ?? 0) !== 1, 'mejl från senaste halvtimmen följer vanlig auto');
gt(array_key_exists('auto', $freshRes) || array_key_exists('rebook', $freshRes), 'färskt mejl går in i auto-reglerna');

// Hook-kön och andra vägar anropar ingest_once med auto=true. Gammalt mejl ska ändå bli backfilled utan utskick.
$GLOBALS['_JEAB_GRAPH_HTTP_PREV'] = $GLOBALS['_JEAB_GRAPH_HTTP'];
$GLOBALS['_JEAB_GRAPH_HTTP'] = function (string $method, string $url, ?array $body = null, array $headers = []) use (&$ageSent, $oldAt): array {
    if (str_contains($url, '/messages/HOOKOLD')) {
        return ['code' => 200, 'body' => gt_msg('HOOKOLD', $oldAt, 'Gammal hook')];
    }

    return ($GLOBALS['_JEAB_GRAPH_HTTP_PREV'])($method, $url, $body, $headers);
};
$ageSent = 0;
$hookRes = jeab_graph_ingest_once('jarfalla', 'HOOKOLD');
gt($ageSent === 0 && !array_key_exists('auto', $hookRes), 'gammalt mejl via ingest_once(auto=true) skickar inget');
$hookRow = null;
foreach (jeab_inkorg_messages() as $m) {
    if ((string) ($m['graph_id'] ?? '') === 'HOOKOLD') {
        $hookRow = $m;
    }
}
gt(is_array($hookRow) && (int) ($hookRow['backfilled'] ?? 0) === 1, 'HOOKOLD märks backfilled');
if (function_exists('jeab_auto_on_message') && is_array($oldRow)) {
    $ageSent = 0;
    $again = jeab_auto_on_message((string) $oldRow['id']);
    gt(($again['why'] ?? '') === 'backfilled' && $ageSent === 0, 'auto_on_message hoppar över backfilled');
}
$GLOBALS['_JEAB_GRAPH_HTTP'] = $GLOBALS['_JEAB_GRAPH_HTTP_PREV'];

jeab_graph_state_update(static function (array &$d): bool {
    unset($d['delta_listed_through']['jarfalla'], $d['delta_list']['jarfalla']);
    $d['delta']['jarfalla'] = 'https://graph.microsoft.com/v1.0/delta?$deltatoken=pages';

    return true;
});
$GLOBALS['_JEAB_GRAPH_DELTA_RECOVERY_SECONDS'] = 0;
$GLOBALS['_JEAB_GRAPH_HTTP'] = function (string $method, string $url, ?array $body = null, array $headers = []): array {
    unset($method, $body, $headers);
    if (str_contains($url, 'deltatoken=pages')) {
        return ['code' => 200, 'body' => ['value' => [], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/delta?$deltatoken=pages2']];
    }
    if (str_contains($url, 'skiptoken=p2')) {
        return ['code' => 200, 'body' => ['value' => [[
            'id' => 'GAP3',
            'receivedDateTime' => '2026-10-21T19:00:00Z',
        ]]]];
    }
    if (str_contains($url, "/messages?")) {
        return ['code' => 200, 'body' => [
            'value' => [['id' => 'GAP2', 'receivedDateTime' => '2026-10-21T18:30:00Z']],
            '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/list?$skiptoken=p2',
        ]];
    }
    if (str_contains($url, '/messages/GAP2')) {
        return ['code' => 200, 'body' => gt_msg('GAP2', '2026-10-21T18:30:00Z')];
    }
    if (str_contains($url, '/messages/GAP3')) {
        return ['code' => 200, 'body' => gt_msg('GAP3', '2026-10-21T19:00:00Z')];
    }

    return ['code' => 500, 'body' => ['error' => ['message' => 'oväntat']]];
};
$partial = jeab_graph_delta(0, 3);
unset($GLOBALS['_JEAB_GRAPH_DELTA_RECOVERY_SECONDS']);
gt(!empty($partial['boxes']['jarfalla']['limited']), 'listan sparar sidan när tiden tar slut');
gt((string) (jeab_graph_state()['delta_list']['jarfalla'] ?? '') === 'https://graph.microsoft.com/v1.0/list?$skiptoken=p2', 'nästa listsida sparas');
gt(!isset(jeab_graph_state()['delta_listed_through']['jarfalla']), 'halvfärdig lista räknas inte som klar');
$haveGap2 = false;
foreach (jeab_inkorg_messages() as $m) {
    if ((string) ($m['graph_id'] ?? '') === 'GAP2') {
        $haveGap2 = true;
    }
}
gt($haveGap2, 'första listsidan tas in innan avbrottet');
jeab_graph_delta(0, 3);
$haveGap3 = false;
foreach (jeab_inkorg_messages() as $m) {
    if ((string) ($m['graph_id'] ?? '') === 'GAP3') {
        $haveGap3 = true;
    }
}
gt($haveGap3 && !isset(jeab_graph_state()['delta_list']['jarfalla']) && (int) (jeab_graph_state()['delta_listed_through']['jarfalla'] ?? 0) > 0, 'nästa tick fortsätter från sparad listsida');

$calls = [];
jeab_graph_state_update(static function (array &$d): bool {
    $d['delta']['jarfalla'] = 'https://graph.microsoft.com/v1.0/delta?$deltatoken=tick';
    $d['subs']['jarfalla'] = ['id' => '', 'expires' => '', 'error' => 'saknas'];
    unset($d['delta_list']['jarfalla']);

    return true;
});
$GLOBALS['_JEAB_GRAPH_HTTP'] = function (string $method, string $url, ?array $body = null, array $headers = []) use (&$calls): array {
    unset($body, $headers);
    $calls[] = [$method, $url];
    if (str_contains($url, 'deltatoken=')) {
        return ['code' => 200, 'body' => ['value' => [], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/delta?$deltatoken=tick2']];
    }
    if (str_contains($url, '/subscriptions')) {
        return ['code' => 201, 'body' => ['id' => 'sub-new', 'expirationDateTime' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400)]];
    }
    if (str_contains($url, "/messages?")) {
        return ['code' => 200, 'body' => ['value' => []]];
    }

    return ['code' => 500, 'body' => ['error' => ['message' => 'oväntat']]];
};
$tick = jeab_graph_mail_tick(microtime(true) + 30);
$deltaPos = null;
$subPos = null;
foreach ($calls as $i => $call) {
    if ($deltaPos === null && str_contains($call[1], 'deltatoken=')) {
        $deltaPos = $i;
    }
    if ($subPos === null && str_contains($call[1], '/subscriptions')) {
        $subPos = $i;
    }
}
gt($deltaPos !== null && $subPos !== null && $deltaPos < $subPos, 'mailticken läser delta före prenumerationen');
gt(empty($tick['subscriptions']['skipped']) || ($tick['subscriptions']['skipped'] ?? '') !== 'färsk', 'lång deadline förnyar prenumerationen');
$calls = [];
jeab_graph_state_update(static function (array &$d): bool {
    $d['subs']['jarfalla'] = ['id' => '', 'expires' => ''];

    return true;
});
jeab_tick_inkorg_live(microtime(true) + 1.2);
$subOnShort = false;
$deltaOnShort = false;
foreach ($calls as $call) {
    if (str_contains($call[1], '/subscriptions')) {
        $subOnShort = true;
    }
    if (str_contains($call[1], 'deltatoken=')) {
        $deltaOnShort = true;
    }
}
gt($deltaOnShort && !$subOnShort, 'kort tick läser delta och hoppar över förnyelsen ' . json_encode($calls));
$poll = jeab_graph_json_read(jeab_inkorg_dir() . '/graph-poll.json');
gt((int) ($poll['at'] ?? 0) > time() - 30, 'pollen skrivs även när förnyelsen hoppas över');

$secret = 'super-secret-client-state';
$okAt = strtotime('2026-10-21T16:15:00Z');
jeab_graph_state_update(static function (array &$d) use ($secret, $okAt): bool {
    $d['subs']['jarfalla'] = [
        'id' => 'sub-j',
        'expires' => '2026-11-01T00:00:00Z',
        'notificationUrl' => JEAB_GRAPH_HOOK_URL,
        'lifecycleNotificationUrl' => JEAB_GRAPH_HOOK_URL,
        'checked_at' => $okAt,
        'clientState' => $secret,
    ];
    $d['delta_progress']['jarfalla'] = '2026-10-21T18:15:00Z';
    $d['delta_ok_at']['jarfalla'] = $okAt;

    return true;
});
$st = jeab_graph_status();
$blob = (string) json_encode($st);
gt(($st['subs']['jarfalla']['notification_url'] ?? '') === JEAB_GRAPH_HOOK_URL, 'status visar notification_url');
gt(($st['subs']['jarfalla']['lifecycle_url'] ?? '') === JEAB_GRAPH_HOOK_URL, 'status visar lifecycle_url');
gt(($st['subs']['jarfalla']['checked_at'] ?? '') === date('c', $okAt), 'status visar checked_at');
gt(($st['delta_progress']['jarfalla'] ?? '') === '2026-10-21T18:15:00Z', 'status visar delta_progress');
gt(($st['delta_ok_at']['jarfalla'] ?? '') === date('c', $okAt), 'status visar delta_ok_at');
gt(!str_contains($blob, $secret), 'status visar inte clientState');

$sql = sys_get_temp_dir() . '/jeab-graph-tick-sql-' . bin2hex(random_bytes(4));
mkdir($sql . '/inkorg', 0775, true);
file_put_contents($sql . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $sql . '/app.sqlite', 'json_path' => $sql . '/store.json'],
], true) . ";\n");
file_put_contents($sql . '/child.php', <<<'PHP'
<?php
$tmp = getenv('JEAB_GT_SQL');
$root = getenv('JEAB_GT_ROOT');
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_STEG4_CUTOVER'] = true;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
putenv('XAI_API_KEY');
require $root . '/src/bootstrap.php';
$path = jeab_inkorg_dir() . '/graph-poll.json';
jeab_graph_json_write($path, ['at' => 111, 'result' => ['marker' => 'sql']]);
file_put_contents($path, json_encode(['at' => 1, 'result' => ['marker' => 'file']]));
$read = jeab_graph_json_read($path);
$ok = (int) ($read['at'] ?? 0) === 111 && (string) ($read['result']['marker'] ?? '') === 'sql';
echo $ok ? "sql-ok\n" : ('sql-fail ' . json_encode($read) . "\n");
exit($ok ? 0 : 1);
PHP);
$sqlProc = proc_open(
    [PHP_BINARY, $sql . '/child.php'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $sqlPipes,
    null,
    ['JEAB_GT_SQL' => $sql, 'JEAB_GT_ROOT' => dirname(__DIR__)]
);
$sqlOut = stream_get_contents($sqlPipes[1]);
$sqlErr = stream_get_contents($sqlPipes[2]);
fclose($sqlPipes[1]);
fclose($sqlPipes[2]);
$sqlCode = proc_close($sqlProc);
gt($sqlCode === 0 && str_contains((string) $sqlOut, 'sql-ok'), 'poll-skrivning syns i SQL-läsningen ' . trim((string) $sqlOut . ' ' . $sqlErr));

echo "klart\n";
