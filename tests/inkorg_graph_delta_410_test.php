<?php

declare(strict_types=1);

/** Graph-delta: utgången nextLink (410) får inte börja om från samma delta_ok_at, och en tick ska nå deltaLink. */

$tmp = sys_get_temp_dir() . '/jeab-graph-410-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export(['timezone' => 'Europe/Stockholm', 'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json']], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function g410(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function g410_msg(string $id, string $received, string $subject = 'Hej'): array
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

$urls = [];
$pages = [
    ['id' => 'M1', 'at' => '2026-10-23T16:20:00Z', 'next' => 'https://graph.microsoft.com/v1.0/delta?$skiptoken=p2'],
    ['id' => 'M2', 'at' => '2026-10-23T16:40:00Z', 'next' => 'https://graph.microsoft.com/v1.0/delta?$skiptoken=p3'],
    ['id' => 'M3', 'at' => '2026-10-23T17:00:00Z', 'next' => 'https://graph.microsoft.com/v1.0/delta?$skiptoken=p4'],
    ['id' => 'M4', 'at' => '2026-10-23T17:20:00Z', 'delta' => 'https://graph.microsoft.com/v1.0/delta?$deltatoken=fresh'],
];
$page = 0;
$msgs = [];
foreach ($pages as $p) {
    $msgs[$p['id']] = g410_msg($p['id'], $p['at']);
}
$GLOBALS['_JEAB_GRAPH_HTTP'] = function (string $method, string $url, ?array $body = null, array $headers = []) use (&$urls, &$page, &$pages, &$msgs) {
    unset($body, $headers);
    $urls[] = $url;
    if (preg_match('#/messages/([A-Za-z0-9]+)\?#', $url, $m) && !str_contains($url, '/messages/delta')) {
        return isset($msgs[$m[1]]) ? ['code' => 200, 'body' => $msgs[$m[1]]] : ['code' => 404, 'body' => []];
    }
    if (str_contains($url, 'deltatoken=stale') || str_contains($url, 'skiptoken=old')) {
        return ['code' => 410, 'body' => ['error' => ['message' => 'The sync state generation is not found.']]];
    }
    if (str_contains($url, 'deltatoken=fresh')) {
        return ['code' => 200, 'body' => [
            'value' => [$msgs['M1']],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/delta?$deltatoken=fresher',
        ]];
    }
    if (str_contains($url, '/messages/delta') || str_contains($url, 'skiptoken=p')) {
        $row = $pages[$page] ?? null;
        $page++;
        if ($row === null) {
            return ['code' => 200, 'body' => ['value' => [], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/delta?$deltatoken=fresh']];
        }
        $body = ['value' => [g410_msg($row['id'], $row['at'])]];
        if (isset($row['next'])) {
            $body['@odata.nextLink'] = $row['next'];
        }
        if (isset($row['delta'])) {
            $body['@odata.deltaLink'] = $row['delta'];
        }

        return ['code' => 200, 'body' => $body];
    }
    if (preg_match('#/messages/([A-Za-z0-9]+)\?#', $url, $m)) {
        return isset($msgs[$m[1]]) ? ['code' => 200, 'body' => $msgs[$m[1]]] : ['code' => 404, 'body' => []];
    }

    return ['code' => 500, 'body' => ['error' => ['message' => 'oväntat ' . $url]]];
};

jeab_graph_secret_save(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's']);
$oldOk = strtotime('2026-10-21T16:15:00Z');
g410($oldOk !== false, 'referenstid går att tolka');
jeab_graph_state_update(static function (array &$d) use ($oldOk): bool {
    $d['delta']['jarfalla'] = 'https://graph.microsoft.com/v1.0/users/kontakt/messages/delta?$deltatoken=stale';
    $d['delta_ok_at']['jarfalla'] = $oldOk;

    return true;
});

$delta = jeab_graph_delta(0, 3);
$box = (array) ($delta['boxes']['jarfalla'] ?? []);
$restart = '';
foreach ($urls as $url) {
    if (str_contains($url, 'receivedDateTime')) {
        $restart = $url;
        break;
    }
}
g410(str_contains($restart, '2026-10-21T16%3A05%3A00Z') || str_contains($restart, '2026-10-21T16:05:00Z'), 'första 410 utan markör går från delta_ok_at-600, fick ' . $restart);
g410((int) ($box['seen'] ?? 0) === 4, 'en tick läser alla fyra sidorna efter 410, såg ' . (int) ($box['seen'] ?? 0) . ' limited=' . json_encode($box['limited'] ?? null) . ' reset=' . json_encode($box['reset'] ?? null) . ' urls=' . count($urls) . ' err=' . ($box['error'] ?? ''));
g410((int) ($box['new'] ?? 0) === 4 && (int) ($box['errors'] ?? 0) === 0, 'de fyra mejlen tas in');
$saved = (string) (jeab_graph_state()['delta']['jarfalla'] ?? '');
g410(str_contains($saved, 'deltatoken=fresh'), 'samma körning sparar deltaLink, fick ' . $saved);
g410(!str_contains($saved, 'skiptoken'), 'nextLink lämnas inte kvar');
g410((int) (jeab_graph_state()['delta_ok_at']['jarfalla'] ?? 0) > $oldOk, 'delta_ok_at flyttas fram när deltaLink kommer');

$before = count(jeab_inkorg_messages());
$urls = [];
$again = jeab_graph_delta(0, 3);
$againBox = (array) ($again['boxes']['jarfalla'] ?? []);
g410((int) ($againBox['duplicates'] ?? 0) === 1 && (int) ($againBox['new'] ?? 0) === 0, 'samma Graph-id blir dubblett, ingen ny offertväg');
g410(count(jeab_inkorg_messages()) === $before, 'dubbletten skapar ingen ny rad');

// Markören ska vinna över det gamla delta_ok_at.
$urls = [];
$page = 0;
$pages = [
    ['id' => 'M9', 'at' => '2026-10-23T18:00:00Z', 'delta' => 'https://graph.microsoft.com/v1.0/delta?$deltatoken=after-progress'],
];
$msgs['M9'] = g410_msg('M9', '2026-10-23T18:00:00Z');
jeab_graph_state_update(static function (array &$d) use ($oldOk): bool {
    $d['delta']['jarfalla'] = 'https://graph.microsoft.com/v1.0/delta?$skiptoken=old';
    $d['delta_ok_at']['jarfalla'] = $oldOk;
    $d['delta_progress']['jarfalla'] = '2026-10-23T17:00:00Z';
    unset($d['delta_since']['jarfalla']);

    return true;
});
$marked = jeab_graph_delta(0, 3);
$markedUrl = '';
foreach ($urls as $url) {
    if (str_contains($url, 'receivedDateTime')) {
        $markedUrl = $url;
        break;
    }
}
g410(
    str_contains($markedUrl, '2026-10-23T16%3A50%3A00Z') || str_contains($markedUrl, '2026-10-23T16:50:00Z'),
    '410 med markör börjar om 10 min före markören, fick ' . $markedUrl
);
g410(!str_contains($markedUrl, '2026-10-21T16'), '410 använder inte det gamla delta_ok_at, fick ' . $markedUrl);
g410(str_contains((string) (jeab_graph_state()['delta']['jarfalla'] ?? ''), 'deltatoken=after-progress'), 'markör-körningen når deltaLink');
g410((int) (($marked['boxes']['jarfalla']['seen'] ?? 0)) === 1, 'markör-körningen läser det nya mejlet');

// Tidsgränsen sparar nextLink tillsammans med nyaste receivedDateTime.
$urls = [];
$page = 0;
$pages = [
    ['id' => 'M8', 'at' => '2026-10-23T18:30:00Z', 'next' => 'https://graph.microsoft.com/v1.0/delta?$skiptoken=later'],
    ['id' => 'M7', 'at' => '2026-10-23T19:00:00Z', 'delta' => 'https://graph.microsoft.com/v1.0/delta?$deltatoken=should-not'],
];
$msgs['M8'] = g410_msg('M8', '2026-10-23T18:30:00Z');
$msgs['M7'] = g410_msg('M7', '2026-10-23T19:00:00Z');
jeab_graph_state_update(static function (array &$d) use ($oldOk): bool {
    unset($d['delta']['jarfalla'], $d['delta_progress']['jarfalla'], $d['delta_since']['jarfalla']);
    $d['delta_ok_at']['jarfalla'] = $oldOk;

    return true;
});
$GLOBALS['_JEAB_GRAPH_DELTA_RECOVERY_SECONDS'] = 0;
$limited = jeab_graph_delta(0, 3);
unset($GLOBALS['_JEAB_GRAPH_DELTA_RECOVERY_SECONDS']);
g410(!empty($limited['boxes']['jarfalla']['limited']), 'tidsgränsen avbryter återhämtningen');
g410((string) (jeab_graph_state()['delta']['jarfalla'] ?? '') === 'https://graph.microsoft.com/v1.0/delta?$skiptoken=later', 'nextLink sparas när sidor återstår');
g410((string) (jeab_graph_state()['delta_progress']['jarfalla'] ?? '') === '2026-10-23T18:30:00Z', 'nyaste receivedDateTime sparas på nextLink-vägen');
g410((int) (jeab_graph_state()['delta_ok_at']['jarfalla'] ?? 0) === $oldOk, 'delta_ok_at står kvar tills deltaLink kommer');
g410((int) ($limited['boxes']['jarfalla']['seen'] ?? 0) === 1, 'tidsgränsen hinner en sida');

$urls = [];
$page = 0;
$pages = [
    ['id' => 'M6', 'at' => '2026-10-23T19:10:00Z', 'delta' => 'https://graph.microsoft.com/v1.0/delta?$deltatoken=done'],
];
$msgs['M6'] = g410_msg('M6', '2026-10-23T19:10:00Z');
jeab_graph_state_update(static function (array &$d): bool {
    $d['delta']['jarfalla'] = 'https://graph.microsoft.com/v1.0/delta?$skiptoken=old';

    return true;
});
jeab_graph_delta(0, 3);
$afterLimit = '';
foreach ($urls as $url) {
    if (str_contains($url, 'receivedDateTime')) {
        $afterLimit = $url;
        break;
    }
}
g410(
    str_contains($afterLimit, '2026-10-23T18%3A20%3A00Z') || str_contains($afterLimit, '2026-10-23T18:20:00Z'),
    'nästa 410 fortsätter från markören, fick ' . $afterLimit
);
g410(!str_contains($afterLimit, '2026-10-21T16'), 'nästa 410 går inte tillbaka till 21/10');

// Torrkörning och intag utan auto.
$urls = [];
$catchMsgs = [
    'A1' => g410_msg('A1', '2026-10-23T18:00:00Z', 'Redan inne'),
    'B2' => g410_msg('B2', '2026-10-23T18:40:00Z', 'Solceller 19800101-1234 tack'),
];
jeab_graph_ingest_message('jarfalla', $catchMsgs['A1']);
$sent = 0;
$GLOBALS['_JEAB_GRAPH_HTTP'] = function (string $method, string $url, ?array $body = null, array $headers = []) use (&$urls, &$sent, $catchMsgs) {
    unset($headers);
    $urls[] = [$method, $url];
    if ($method === 'POST' && (str_contains($url, '/sendMail') || str_contains($url, '/reply'))) {
        $sent++;

        return ['code' => 202, 'body' => []];
    }
    if (str_contains($url, "/messages?") || str_contains($url, '/messages?')) {
        return ['code' => 200, 'body' => ['value' => [
            ['id' => 'A1', 'receivedDateTime' => '2026-10-23T18:00:00Z', 'subject' => 'Redan inne'],
            ['id' => 'B2', 'receivedDateTime' => '2026-10-23T18:40:00Z', 'subject' => 'Solceller 19800101-1234 tack'],
            ['id' => 'C3', 'receivedDateTime' => '2026-10-23T18:50:00Z', 'subject' => 'Pnr 198001011234'],
        ]]];
    }
    if (preg_match('#/messages/([A-Za-z0-9]+)\?#', $url, $m)) {
        return isset($catchMsgs[$m[1]]) ? ['code' => 200, 'body' => $catchMsgs[$m[1]]] : ['code' => 404, 'body' => []];
    }

    return ['code' => 500, 'body' => ['error' => ['message' => 'oväntat']]];
};
$dry = jeab_graph_catchup('jarfalla', '2026-10-23T18:00:00Z', true);
$dryJson = (string) json_encode($dry);
g410(!empty($dry['dry']) && ($dry['missing'][0]['id'] ?? '') === 'B2', 'torrkörning listar saknade id');
g410(($dry['missing'][0]['subject'] ?? '') === 'Solceller [pnr] tack', 'torrkörning maskar personnummer i rubriken');
g410(($dry['missing'][1]['subject'] ?? '') === 'Pnr [pnr]', 'tolvsiffrigt personnummer maskas');
g410(!str_contains($dryJson, '19800101'), 'svaret innehåller inget personnummer');
g410(($dry['ingested'] ?? []) === [], 'torrkörning tar inte in');
$fetched = false;
foreach ($urls as $call) {
    if (str_contains($call[1], '/messages/B2')) {
        $fetched = true;
    }
}
g410(!$fetched, 'torrkörning hämtar inte hela mejlet');
$countBefore = count(jeab_inkorg_messages());
$wet = jeab_graph_catchup('jarfalla', '2026-10-23T18:00:00Z', false);
g410(empty($wet['dry']) && ($wet['ingested'][0]['ok'] ?? false) === true && ($wet['ingested'][0]['auto'] ?? true) === false, 'dry=0 tar in utan auto');
g410(count(jeab_inkorg_messages()) === $countBefore + 1, 'bara det saknade mejlet läggs till');
g410($sent === 0, 'catchup skickar inget');
$wetAgain = jeab_graph_catchup('jarfalla', '2026-10-23T18:00:00Z', false);
$idsAfter = array_column((array) ($wetAgain['missing'] ?? []), 'id');
g410(!in_array('B2', $idsAfter, true), 'intaget id är inte längre saknat');
try {
    jeab_graph_catchup('okand', '2026-10-23T18:00:00Z', true);
    g410(false, 'okänt bolag ska nekas');
} catch (InvalidArgumentException) {
    g410(true, 'okänt bolag nekas');
}

// Notiser: last_hook skrivs direkt, även vid fel clientState. missed nollställer delta från markören.
$cs = (string) jeab_graph_config()['client_state'];
jeab_graph_state_update(static function (array &$d): bool {
    $d['subs']['jarfalla'] = [
        'id' => 'sub-j',
        'expires' => gmdate('Y-m-d\TH:i:s\Z', time() + 20 * 86400),
        'notificationUrl' => JEAB_GRAPH_HOOK_URL,
        'lifecycleNotificationUrl' => JEAB_GRAPH_HOOK_URL,
        'checked_at' => time(),
    ];
    $d['delta']['jarfalla'] = 'https://graph.microsoft.com/v1.0/delta?$deltatoken=live';
    $d['delta_progress']['jarfalla'] = '2026-10-23T17:00:00Z';
    unset($d['last_hook']);

    return true;
});
$bad = jeab_graph_notifications(['value' => [[
    'clientState' => 'fel',
    'subscriptionId' => 'sub-j',
    'resourceData' => ['id' => 'M1'],
]]]);
jeab_graph_hook_remember($bad);
g410((int) ($bad['rejected'] ?? 0) === 1 && (jeab_graph_state()['last_hook']['rejected'] ?? 0) === 1, 'fel clientState stämplar last_hook');
g410((string) (jeab_graph_state()['last_hook']['at'] ?? '') !== '', 'last_hook.at sätts utan att mejlet hämtas');
$fromResource = jeab_graph_notifications(['value' => [[
    'clientState' => $cs,
    'subscriptionId' => 'sub-j',
    'resource' => "users/kontakt@jarfalla.example.test/mailFolders('inbox')/messages/QQ1",
]]]);
g410(($fromResource['jobs'][0] ?? null) === ['jarfalla', 'QQ1'], 'id läses ur resource när resourceData.id saknas');
$missed = jeab_graph_notifications(['value' => [[
    'clientState' => $cs,
    'subscriptionId' => 'sub-j',
    'lifecycleEvent' => 'missed',
]]]);
jeab_graph_hook_remember($missed);
g410(!isset(jeab_graph_state()['delta']['jarfalla']), 'missed tar bort delta-länken');
$sinceMissed = (int) (jeab_graph_state()['delta_since']['jarfalla'] ?? 0);
g410($sinceMissed === strtotime('2026-10-23T17:00:00Z') - 600, 'missed fortsätter från markören minus 10 min');
$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/graph-hook.php');
$rememberAt = strpos($hookSrc, 'jeab_graph_hook_remember');
$enqueueAt = strpos($hookSrc, 'jeab_graph_hook_enqueue');
$finishAt = strpos($hookSrc, 'fastcgi_finish_request');
g410($rememberAt !== false && $enqueueAt !== false && $rememberAt < $enqueueAt, 'kroken skriver last_hook före kön');
g410($finishAt !== false && $enqueueAt < $finishAt, 'kön skrivs före 202');
$failAt = strpos($hookSrc, 'http_response_code(503)');
g410($failAt !== false && $enqueueAt < $failAt && $failAt < $finishAt, 'misslyckad kö är 503 och avslutar före kvittensen');

$cased = jeab_graph_notifications(['value' => [[
    'clientState' => '  ' . $cs . '  ',
    'subscriptionId' => 'SUB-J',
    'resourceData' => ['id' => 'M2'],
]]]);
g410(($cased['jobs'][0] ?? null) === ['jarfalla', 'M2'] && (int) ($cased['auth_failed'] ?? 1) === 0 && jeab_graph_hook_http_status($cased) === 202, 'prenumerations-id matchas utan hänsyn till skiftläge');
g410((int) ($bad['auth_failed'] ?? 0) > 0 && jeab_graph_hook_http_status($bad) === 401, 'fel clientState är 401');
$soft = jeab_graph_notifications(['value' => [[
    'clientState' => $cs,
    'subscriptionId' => 'okand-prenumeration',
    'resourceData' => ['id' => 'M3'],
]]]);
g410((int) ($soft['rejected'] ?? 0) === 1 && (int) ($soft['auth_failed'] ?? 1) === 0 && (int) ($soft['accepted'] ?? 1) === 0 && jeab_graph_hook_http_status($soft) === 202, 'okänt prenumerations-id avvisas utan att Graph tystas');
$byBox = jeab_graph_notifications(['value' => [[
    'clientState' => $cs,
    'subscriptionId' => 'annat-id',
    'resource' => "users/kontakt@jarfalla.example.test/mailFolders('inbox')/messages/QQ2",
]]]);
g410(($byBox['jobs'][0] ?? null) === ['jarfalla', 'QQ2'] && (int) ($byBox['auth_failed'] ?? 1) === 0, 'mejlboxen i resource räcker när id:t inte matchar');

// Tillståndet säger rätt URL, Graph har kvar graph-hook.php. Då skapas prenumerationen om.
$calls = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url, ?array $body = null, array $headers = []) use (&$calls): array {
    unset($headers);
    $calls[] = [$method, $url, $body];
    if ($method === 'GET' && str_contains($url, '/subscriptions/sub-old')) {
        return ['code' => 200, 'body' => [
            'id' => 'sub-old',
            'notificationUrl' => 'https://flowstack.example.test/graph-hook.php',
            'expirationDateTime' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400),
        ]];
    }
    if ($method === 'DELETE' && str_contains($url, '/subscriptions/')) {
        return ['code' => 204, 'body' => []];
    }
    if ($method === 'POST' && str_contains($url, '/subscriptions')) {
        return ['code' => 201, 'body' => ['id' => 'sub-new', 'expirationDateTime' => (string) ($body['expirationDateTime'] ?? '')]];
    }

    return ['code' => 500, 'body' => ['error' => ['message' => 'oväntat ' . $method]]];
};
jeab_graph_state_update(static function (array &$d): bool {
    $d['subs']['jarfalla'] = [
        'id' => 'sub-old',
        'expires' => gmdate('Y-m-d\TH:i:s\Z', time() + 20 * 86400),
        'notificationUrl' => JEAB_GRAPH_HOOK_URL,
        'checked_at' => time() - 7200,
    ];

    return true;
});
g410(jeab_graph_subs_need_renew() === true, 'saknad lifecycle eller gammal koll förnyas');
$rebuilt = jeab_graph_subscriptions_ensure();
$methods = array_map(static fn (array $call): string => $call[0], $calls);
$posted = null;
foreach ($calls as $call) {
    if ($call[0] === 'POST') {
        $posted = $call[2];
    }
}
g410(in_array('GET', $methods, true) && in_array('DELETE', $methods, true) && in_array('POST', $methods, true) && !in_array('PATCH', $methods, true), 'Graph-URL som inte stämmer skapas om, fick ' . implode(',', $methods));
g410(is_array($posted) && ($posted['notificationUrl'] ?? '') === JEAB_GRAPH_HOOK_URL && ($posted['lifecycleNotificationUrl'] ?? '') === JEAB_GRAPH_HOOK_URL, 'ny prenumeration pekar på index.php och lifecycle');
g410(!empty($rebuilt['ok']) && (jeab_graph_state()['subs']['jarfalla']['id'] ?? '') === 'sub-new', 'nya id:t sparas');
g410((jeab_graph_state()['subs']['jarfalla']['notificationUrl'] ?? '') === JEAB_GRAPH_HOOK_URL, 'tillståndet får den nya URL:en');

$quietState = jeab_graph_state();
unset($quietState['last_hook'], $quietState['subs']['jarfalla']['hook_rearmed_at']);
g410(jeab_graph_hook_is_behind($quietState, 'jarfalla') === false, 'saknad last_hook och omväpning skapar inte om');
$quietState['last_hook'] = ['at' => '2026-10-21T18:24:00+02:00', 'jobs' => 1, 'accepted' => 1, 'rejected' => 0];
$quietState['subs']['jarfalla']['created'] = '2026-10-01T08:00:00+02:00';
$quietState['subs']['jarfalla']['renewed'] = date('c');
g410(jeab_graph_hook_is_behind($quietState, 'jarfalla') === true, 'renewed håller inte kroken färsk');

// Mejl har kommit efter last_hook, URL:en ser rätt ut. Prenumerationen skapas om en gång.
$armCalls = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url, ?array $body = null, array $headers = []) use (&$armCalls): array {
    unset($headers);
    $armCalls[] = [$method, $url, $body];
    if ($method === 'GET') {
        return ['code' => 200, 'body' => [
            'id' => 'sub-quiet',
            'notificationUrl' => JEAB_GRAPH_HOOK_URL,
            'lifecycleNotificationUrl' => JEAB_GRAPH_HOOK_URL,
        ]];
    }
    if ($method === 'DELETE') {
        return ['code' => 204, 'body' => []];
    }
    if ($method === 'POST' && str_contains($url, '/subscriptions')) {
        return ['code' => 201, 'body' => ['id' => 'sub-rearm', 'expirationDateTime' => (string) ($body['expirationDateTime'] ?? '')]];
    }
    if ($method === 'PATCH') {
        return ['code' => 200, 'body' => ['expirationDateTime' => (string) ($body['expirationDateTime'] ?? '')]];
    }

    return ['code' => 500, 'body' => ['error' => ['message' => 'oväntat ' . $method]]];
};
jeab_graph_state_update(static function (array &$d): bool {
    $d['last_hook'] = ['at' => '2026-10-21T18:24:00+02:00', 'jobs' => 1, 'accepted' => 1, 'rejected' => 0];
    $d['subs']['jarfalla'] = [
        'id' => 'sub-quiet',
        'expires' => gmdate('Y-m-d\TH:i:s\Z', time() + 20 * 86400),
        'created' => '2026-10-01T08:00:00+02:00',
        'notificationUrl' => JEAB_GRAPH_HOOK_URL,
        'lifecycleNotificationUrl' => JEAB_GRAPH_HOOK_URL,
        'checked_at' => time(),
    ];

    return true;
});
g410(jeab_graph_hook_is_behind(jeab_graph_state(), 'jarfalla') === true, 'krok från 21/10 är efter mejlet 23/10');
g410(jeab_graph_subs_need_renew() === true, 'tyst krok gör att tick förnyar');
$armed = jeab_graph_subscriptions_ensure();
$armMethods = array_map(static fn (array $call): string => $call[0], $armCalls);
g410(in_array('DELETE', $armMethods, true) && in_array('POST', $armMethods, true) && !in_array('PATCH', $armMethods, true), 'tyst krok skapas om, fick ' . implode(',', $armMethods));
g410(!empty($armed['ok']) && (jeab_graph_state()['subs']['jarfalla']['id'] ?? '') === 'sub-rearm' && (int) (jeab_graph_state()['subs']['jarfalla']['hook_rearmed_at'] ?? 0) > 0, 'omväpningen sparas');
g410(jeab_graph_hook_is_behind(jeab_graph_state(), 'jarfalla') === false && jeab_graph_subs_need_renew() === false, 'samma mejl väpnar inte om igen');
$keptCreated = (string) (jeab_graph_state()['subs']['jarfalla']['created'] ?? '');
$armCalls = [];
jeab_graph_subscriptions_ensure();
$again = array_map(static fn (array $call): string => $call[0], $armCalls);
g410($again === ['PATCH'], 'andra rundan förnyar bara, fick ' . implode(',', $again));
g410((int) (jeab_graph_state()['subs']['jarfalla']['hook_rearmed_at'] ?? 0) > 0 && (string) (jeab_graph_state()['subs']['jarfalla']['created'] ?? '') === $keptCreated, 'PATCH behåller omväpningen och created');

$csCalls = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url, ?array $body = null, array $headers = []) use (&$csCalls): array {
    unset($headers);
    $csCalls[] = [$method, $url, $body];
    if ($method === 'GET' && str_contains($url, '/subscriptions/sub-cs')) {
        return ['code' => 200, 'body' => [
            'id' => 'sub-cs',
            'notificationUrl' => JEAB_GRAPH_HOOK_URL,
            'lifecycleNotificationUrl' => JEAB_GRAPH_HOOK_URL,
            'clientState' => 'definitely-not-the-state',
        ]];
    }
    if ($method === 'DELETE') {
        return ['code' => 204, 'body' => []];
    }
    if ($method === 'POST' && str_contains($url, '/subscriptions')) {
        return ['code' => 201, 'body' => ['id' => 'sub-cs-new', 'expirationDateTime' => (string) ($body['expirationDateTime'] ?? '')]];
    }

    return ['code' => 500, 'body' => ['error' => ['message' => 'oväntat ' . $method]]];
};
jeab_graph_state_update(static function (array &$d): bool {
    $d['last_hook'] = ['at' => date('c'), 'jobs' => 0, 'accepted' => 0, 'rejected' => 0];
    $d['subs']['jarfalla'] = [
        'id' => 'sub-cs',
        'expires' => gmdate('Y-m-d\TH:i:s\Z', time() + 20 * 86400),
        'created' => date('c'),
        'notificationUrl' => JEAB_GRAPH_HOOK_URL,
        'lifecycleNotificationUrl' => JEAB_GRAPH_HOOK_URL,
        'checked_at' => time() - 7200,
    ];

    return true;
});
$csFixed = jeab_graph_subscriptions_ensure();
$csMethods = array_map(static fn (array $call): string => $call[0], $csCalls);
$csPosted = null;
foreach ($csCalls as $call) {
    if ($call[0] === 'POST') {
        $csPosted = $call[2];
    }
}
g410(in_array('GET', $csMethods, true) && in_array('DELETE', $csMethods, true) && in_array('POST', $csMethods, true) && !in_array('PATCH', $csMethods, true), 'annat clientState skapas om, fick ' . implode(',', $csMethods));
g410(is_array($csPosted) && ($csPosted['clientState'] ?? '') === $cs && ($csPosted['clientState'] ?? '') !== 'definitely-not-the-state', 'ny prenumeration får det sparade clientState');
g410(!empty($csFixed['ok']) && (jeab_graph_state()['subs']['jarfalla']['id'] ?? '') === 'sub-cs-new', 'nytt id efter clientState');
g410(!str_contains((string) json_encode(jeab_graph_state()['subs']['jarfalla'] ?? []), 'definitely-not-the-state'), 'clientState från Graph sparas inte');

$index = (string) file_get_contents(dirname(__DIR__) . '/index.php');
$inkorg = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg.php');
g410(str_contains($index, "'inkorg.graph_catchup'") && str_contains($inkorg, "case 'inkorg.graph_catchup':"), 'hooken inkorg.graph_catchup är kopplad');

echo "klart\n";
