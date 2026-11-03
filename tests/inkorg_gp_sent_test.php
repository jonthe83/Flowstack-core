<?php

declare(strict_types=1);

/** Automatik-texten följer jeab_gp_owner_email. inbox.gp_sent läser Skickat, aldrig brödtext. */

$tmp = sys_get_temp_dir() . '/jeab-gp-sent-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function gs(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function gs_fn_src(string $fn): string
{
    $rf = new ReflectionFunction($fn);
    $lines = file($rf->getFileName());
    return implode('', array_slice($lines, $rf->getStartLine() - 1, $rf->getEndLine() - $rf->getStartLine() + 1));
}

$phrase = jeab_gp_auto_sender_phrase();
$batteri = jeab_gp_owner_email('batteri', 'jarfalla');
$laddbox = jeab_gp_owner_email('laddbox', 'jarfalla');
$sol = jeab_gp_owner_email('sol', 'jarfalla');
$pause = ' (pausad tills solmallen är ifylld)';
$expect = 'offert från personens egen brevlåda (Graph) — Batteri från ' . $batteri
    . ', Elbilsladdare från ' . $laddbox
    . ' och Solceller med batteri från ' . $sol . ' —';
$paused = 'offert från personens egen brevlåda (Graph) — Batteri från ' . $batteri
    . ', Elbilsladdare från ' . $laddbox
    . ' och Solceller med batteri från ' . $sol . $pause . ' —';
gs($phrase === $expect, 'frasen byggs av jeab_gp_owner_email och den riktiga solmallen pausar inte');
gs($batteri === 'saljare3@jarfalla.example.test' && $laddbox === 'saljare4@jarfalla.example.test', 'Batteri är saljare3@, Elbilsladdare är elin@');
gs(!str_contains($phrase, 'kontakt@'), 'frasen nämner inte kontakt@');
$phraseSrc = gs_fn_src('jeab_gp_auto_sender_phrase');
gs(str_contains($phraseSrc, 'jeab_gp_owner_email'), 'frasen anropar jeab_gp_owner_email');
gs(!str_contains($phraseSrc, '@'), 'frasen hårdkodar ingen adress');
$pageSrc = (string) file_get_contents(dirname(__DIR__) . '/src/pages/inkorg_regler.php');
gs(str_contains($pageSrc, 'jeab_gp_auto_sender_phrase()'), 'reglersidan använder frasen');
gs(!str_contains($pageSrc, 'offert från bolagets kontakt@'), 'reglersidan säger inte längre kontakt@ för automatik');

$_SERVER['SCRIPT_NAME'] = '/index.php';
require_once dirname(__DIR__) . '/src/layout.php';
$html = require dirname(__DIR__) . '/src/pages/inkorg_regler.php';
gs(is_string($html) && preg_match('/<h2[^>]*>Automatik efter klassning<\/h2>\s*<p[^>]*>(.*?)<\/p>/s', $html, $para) === 1, 'automatikstycket renderas');
$auto = html_entity_decode(strip_tags($para[1]), ENT_QUOTES, 'UTF-8');
gs(str_contains($auto, $phrase), 'renderad text är samma fras');
gs(str_contains($auto, 'saljare3@jarfalla.example.test') && str_contains($auto, 'saljare4@jarfalla.example.test'), 'sidan visar saljare3@ och elin@');
gs(str_contains($auto, 'personens egen brevlåda (Graph)'), 'sidan säger personens egen brevlåda (Graph)');
gs(str_contains($auto, 'Solceller med batteri från ' . $sol) && !str_contains($auto, 'pausad'), 'sidan pausar inte Solceller när mallen är de skickade mejlen');
gs(!str_contains($auto, 'kontakt@'), 'automatikstycket nämner inte kontakt@');

$tplSave = static function (string $solTpl): void {
    $tpl = jeab_auto_quote_seed();
    $tpl['sol_jarfalla'] = $solTpl;
    jeab_auto_settings_save(['auto_quote_enabled' => 0, 'auto_trash_spam' => 1, 'auto_quote_only_from' => '', 'tpl' => $tpl]);
};
$tplSave("Hej,\n\nHär är solofferten.");
$openPhrase = jeab_gp_auto_sender_phrase();
gs(str_contains($openPhrase, 'Solceller med batteri från ' . $sol) && !str_contains($openPhrase, 'pausad'), 'ifylld solmall pausar inte Solceller');
$tplSave('');
gs(jeab_gp_auto_sender_phrase() === $paused, 'tom solmall pausar Solceller');
$tplSave('(solmallen från offertflödet används)');
gs(!str_contains(jeab_gp_auto_sender_phrase(), 'pausad'), 'offertflödets solmall är inte platshållaren jeab_auto_quote håller');
$tplSave('(solmallen är inte ifylld: solofferter hålls tills texten skrivits här)');
gs(jeab_gp_auto_sender_phrase() === $expect, 'den gamla platshållaren är ersatt och pausar inte');

$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg.php');
gs(str_contains($hookSrc, "case 'inbox.gp_sent':"), 'åtgärden finns bredvid gp_probe');
$sentSrc = gs_fn_src('jeab_gp_sent') . gs_fn_src('jeab_gp_sent_mailbox') . gs_fn_src('jeab_gp_sent_limit');
gs(!str_contains($sentSrc, 'error_log') && !str_contains($sentSrc, 'client_secret') && !str_contains($sentSrc, 'contentBytes'), 'hooken loggar inget och ber inte om bilageinnehåll');
gs(!str_contains($sentSrc, 'bodyPreview') && !preg_match('/\$select=[^\'\"\n]*body/', $sentSrc), 'urvalet innehåller inte brödtext');

$calls = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = function (string $method, string $url, ?array $body) use (&$calls) {
    $calls[] = [$method, $url, $body];
    if (str_contains($url, '/attachments?')) {
        return ['code' => 200, 'body' => ['value' => [
            ['name' => 'Offert.pdf', 'contentBytes' => 'PDFBYTES-SECRET', 'contentType' => 'application/pdf', '@odata.type' => '#microsoft.graph.fileAttachment'],
            ['name' => '  ', 'contentBytes' => 'EMPTYNAME'],
            ['name' => 'team.jpg'],
            'skip-me',
        ]]];
    }
    return ['code' => 200, 'body' => ['value' => [
        [
            'id' => 'MID-DO-NOT-LEAK',
            'subject' => 'Offert batteri',
            'body' => ['content' => 'HEMLIG-BRODTEXT'],
            'bodyPreview' => 'HEMLIG-PREVIEW',
            'toRecipients' => [
                ['emailAddress' => ['address' => 'Kund@Ex.se', 'name' => 'Kund Namn']],
                ['emailAddress' => ['address' => 'annan@ex.se']],
            ],
            'sentDateTime' => '2026-10-16T08:00:00Z',
            'hasAttachments' => true,
            'attachments' => [['name' => 'ignored-from-list.pdf', 'contentBytes' => 'LISTBYTES']],
        ],
        [
            'id' => 'MID2',
            'subject' => 'Utan bilaga',
            'sentDateTime' => '2026-10-15T08:00:00Z',
            'hasAttachments' => false,
            'toRecipients' => [],
            'body' => ['content' => 'OCKSA-HEMLIG'],
        ],
        [
            'id' => 'MID-EXTRA',
            'subject' => 'Ska kapas',
            'sentDateTime' => '2026-10-14T08:00:00Z',
            'hasAttachments' => false,
            'body' => ['content' => 'TREDJE-HEMLIG'],
        ],
    ]]];
};

$calls = [];
$r = jeab_gp_sent(['mailbox' => 'Roberto@jarfalla-demo.se', 'n' => 2, 'body' => 'HEMLIG-INPUT', 'client_secret' => 'sekret-xyz']);
$want = [
    'ok' => true,
    'mailbox' => 'saljare3@jarfalla.example.test',
    'n' => 2,
    'messages' => [
        [
            'subject' => 'Offert batteri',
            'to' => ['Kund@Ex.se', 'annan@ex.se'],
            'sentDateTime' => '2026-10-16T08:00:00Z',
            'attachments' => ['Offert.pdf', 'team.jpg'],
        ],
        [
            'subject' => 'Utan bilaga',
            'to' => [],
            'sentDateTime' => '2026-10-15T08:00:00Z',
            'attachments' => [],
        ],
    ],
];
gs($r === $want, 'svaret är ämne, till, tid och bilagenamn');
gs(count($calls) === 2 && $calls[0][0] === 'GET' && $calls[1][0] === 'GET', 'bara GET');
gs(str_contains($calls[0][1], '/users/roberto%40jarfalla.example.test/mailFolders/sentitems/messages'), 'Skickat i den vitlistade brevlådan');
gs(str_contains($calls[0][1], '$top=2') && str_contains($calls[0][1], '$select=id,subject,toRecipients,sentDateTime,hasAttachments'), 'top och urval utan brödtext');
gs(!str_contains($calls[0][1], 'body') && !str_contains($calls[0][1], 'contentBytes'), 'listan ber inte om brödtext');
gs(str_contains($calls[1][1], '/messages/' . rawurlencode('MID-DO-NOT-LEAK') . '/attachments?$select=name'), 'bilagor hämtas med bara namn');
gs($calls[0][2] === null && $calls[1][2] === null, 'GET har ingen body');
$encoded = json_encode($r, JSON_UNESCAPED_UNICODE);
gs(is_string($encoded)
    && !str_contains($encoded, 'HEMLIG')
    && !str_contains($encoded, 'PDFBYTES')
    && !str_contains($encoded, 'LISTBYTES')
    && !str_contains($encoded, 'EMPTYNAME')
    && !str_contains($encoded, 'MID-DO-NOT-LEAK')
    && !str_contains($encoded, 'sekret-xyz')
    && !str_contains($encoded, 'contentBytes')
    && !str_contains($encoded, 'Kund Namn'), 'svaret läcker varken brödtext, bilaga eller id');

$calls = [];
$none = jeab_gp_sent(['mailbox' => 'kontakt@jarfalla.example.test', 'n' => 3]);
gs($none === ['ok' => false, 'error' => 'Okänd personbrevlåda.'] && $calls === [], 'kontakt@ är inte en personbrevlåda och Graph anropas inte');
$calls = [];
$evil = jeab_gp_sent(['mailbox' => 'evil@ex.se']);
gs($evil['ok'] === false && $calls === [], 'okänd adress nekas utan Graph-anrop');
$calls = [];
gs(jeab_gp_sent(['mailbox' => ['saljare3@jarfalla.example.test']])['ok'] === false && $calls === [], 'mailbox måste vara en sträng');

$calls = [];
$def = jeab_gp_sent(['mailbox' => 'saljare4@jarfalla.example.test']);
gs($def['ok'] === true && $def['n'] === 10 && str_contains($calls[0][1], 'elin%40jarfalla.example.test') && str_contains($calls[0][1], '$top=10'), 'utelämnat n är 10');

foreach ([0, 11, -1, '50', '1.5', 10.0, true] as $badN) {
    try {
        jeab_gp_sent(['mailbox' => 'saljare3@jarfalla.example.test', 'n' => $badN]);
        gs(false, 'ogiltigt n ska avvisas: ' . var_export($badN, true));
    } catch (InvalidArgumentException $e) {
        gs($e->getMessage() === 'n ska vara ett heltal mellan 1 och 10.', 'ogiltigt n avvisas (' . var_export($badN, true) . ')');
    }
}
gs(jeab_gp_sent(['mailbox' => 'saljare.syd@malmo.example.test', 'n' => '1'])['n'] === 1, 'n som siffra-sträng fungerar, Malmö-personal är vitlistad');

$GLOBALS['_JEAB_GRAPH_HTTP'] = function () {
    return ['code' => 403, 'body' => ['error' => ['message' => 'Access denied SECRET-BODY', 'code' => 'ErrorAccessDenied']]];
};
$denied = jeab_gp_sent(['mailbox' => 'saljare3@jarfalla.example.test', 'n' => 1]);
gs($denied === ['ok' => false, 'error' => 'graph 403', 'mailbox' => 'saljare3@jarfalla.example.test'], 'Graph-fel ekar bara statuskod');
gs(!str_contains(json_encode($denied), 'SECRET-BODY'), 'Graph-felkroppen kommer inte med');

unset($GLOBALS['_JEAB_GRAPH_HTTP']);
$plain = jeab_gp_sent(['mailbox' => 'saljare3@jarfalla.example.test', 'n' => 1]);
gs($plain === ['ok' => false, 'error' => 'graph 0', 'mailbox' => 'saljare3@jarfalla.example.test'], 'okonfigurerad Graph blir graph 0');

$via = jeab_inkorg_hook(['action' => 'inbox.gp_sent', 'mailbox' => 'saljare4@jarfalla.example.test', 'n' => 1, 'token' => 'should-not-echo']);
gs(($via['ok'] ?? null) === false && ($via['error'] ?? '') === 'graph 0' && !array_key_exists('token', $via), 'hooken når åtgärden och ekar inte token');

// HTTP: samma tokenkanaler som inbox.gp_probe.
$httpRoot = sys_get_temp_dir() . '/jeab-gp-sent-http-' . bin2hex(random_bytes(4));
$httpData = $httpRoot . '/data';
$httpEmpty = $httpRoot . '/empty';
mkdir($httpData . '/inkorg', 0775, true);
mkdir($httpData . '/secrets', 0775, true);
mkdir($httpData . '/public', 0775, true);
mkdir($httpEmpty, 0775, true);
$inboxToken = 'ink_' . bin2hex(random_bytes(8));
file_put_contents($httpData . '/secrets/inkorg-ingest.json', json_encode(['key' => $inboxToken]));
file_put_contents($httpData . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'json', 'sqlite_path' => $httpData . '/app.sqlite', 'json_path' => $httpData . '/store.json'],
], true) . ";\n");
$router = $httpRoot . '/router.php';
$log = $httpRoot . '/php.log';
file_put_contents($router, <<<'PHP'
<?php
$data = getenv('JEAB_GP_SENT_DATA');
$root = getenv('JEAB_GP_SENT_ROOT');
if (!is_string($data) || $data === '' || !is_string($root) || $root === '') {
    http_response_code(500);
    echo 'missing';
    return true;
}
$GLOBALS['_JEAB_DATA_DIR'] = $data;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $data . '/public';
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url, ?array $body): array {
    if ($method !== 'GET' || !str_contains($url, '/mailFolders/sentitems/messages')) {
        return ['code' => 404, 'body' => ['error' => ['message' => 'unexpected']]];
    }
    return ['code' => 200, 'body' => ['value' => [[
        'id' => 'H1',
        'subject' => 'Offert',
        'body' => ['content' => 'HTTP-HEMLIG-BRODTEXT'],
        'bodyPreview' => 'HTTP-HEMLIG-PREVIEW',
        'toRecipients' => [['emailAddress' => ['address' => 'kund@ex.se']]],
        'sentDateTime' => '2026-10-16T09:00:00Z',
        'hasAttachments' => false,
    ]]]];
};
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($path === '/hook.php') {
    require $root . '/hook.php';
    return true;
}
http_response_code(404);
echo 'no';
return true;
PHP);
$probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($probe === false) {
    gs(false, 'kunde inte välja port');
}
$addr = stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr((string) strrchr((string) $addr, ':'), 1);
gs($port > 0, 'port vald');
$php = PHP_BINARY;
$cmd = 'JEAB_DISABLE_SQLITE=1 JEAB_GP_SENT_DATA=' . escapeshellarg($httpData)
    . ' JEAB_GP_SENT_ROOT=' . escapeshellarg(dirname(__DIR__))
    . ' ' . escapeshellarg($php)
    . ' -d display_errors=0 -d log_errors=1 -d error_log=' . escapeshellarg($log)
    . ' -S 127.0.0.1:' . $port
    . ' -t ' . escapeshellarg($httpEmpty)
    . ' ' . escapeshellarg($router);
require_once __DIR__ . '/jeab_test_proc_env.php';
jeab_test_router_boot($router);
$proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
gs(is_resource($proc), 'lokal PHP-server startade');
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
gs($up, 'testservern lyssnar');

$base = 'http://127.0.0.1:' . $port . '/hook.php';
$http = static function (string $url, string $method, ?string $body, array $headers) use ($inboxToken): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 5,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $text = is_string($raw) ? $raw : '';
    if ($inboxToken !== '' && str_contains($text, $inboxToken)) {
        fwrite(STDERR, "FAIL svaret innehåller token\n");
        exit(1);
    }
    return ['status' => $status, 'body' => $text, 'json' => json_decode($text, true)];
};

$deny = $http($base, 'POST', json_encode(['action' => 'inbox.gp_sent', 'mailbox' => 'saljare3@jarfalla.example.test']), ['Content-Type: application/json']);
gs($deny['status'] === 401 && ($deny['json']['error'] ?? '') === 'Fel token.', 'utan token blir det 401');

$payload = json_encode([
    'action' => 'inbox.gp_sent',
    'mailbox' => 'saljare3@jarfalla.example.test',
    'n' => 1,
    'client_secret' => 'post-secret',
    'body' => 'HTTP-HEMLIG-BRODTEXT-INPUT',
]);
$on = $http($base, 'POST', $payload, ['Content-Type: application/json', 'Authorization: Bearer ' . $inboxToken]);
gs($on['status'] === 200 && $on['json'] === [
    'ok' => true,
    'mailbox' => 'saljare3@jarfalla.example.test',
    'n' => 1,
    'messages' => [[
        'subject' => 'Offert',
        'to' => ['kund@ex.se'],
        'sentDateTime' => '2026-10-16T09:00:00Z',
        'attachments' => [],
    ]],
], 'Bearer läser Skickat');
gs(!str_contains($on['body'], 'HTTP-HEMLIG') && !str_contains($on['body'], 'post-secret'), 'HTTP-svaret har ingen brödtext');

$head = $http($base, 'POST', json_encode(['action' => 'inbox.gp_sent', 'mailbox' => 'saljare4@jarfalla.example.test', 'n' => 1]), ['Content-Type: application/json', 'X-Webhook-Token: ' . $inboxToken]);
gs($head['status'] === 200 && ($head['json']['mailbox'] ?? '') === 'saljare4@jarfalla.example.test', 'X-Webhook-Token fungerar som för gp_probe');

$bad = $http($base, 'POST', json_encode(['action' => 'inbox.gp_sent', 'mailbox' => 'saljare3@jarfalla.example.test', 'n' => 99]), ['Content-Type: application/json', 'Authorization: Bearer ' . $inboxToken]);
gs($bad['status'] === 422 && ($bad['json']['error'] ?? '') === 'n ska vara ett heltal mellan 1 och 10.', 'för stort n är 422');

$unknown = $http($base, 'POST', json_encode(['action' => 'inbox.gp_sent', 'mailbox' => 'kontakt@jarfalla.example.test']), ['Content-Type: application/json', 'Authorization: Bearer ' . $inboxToken]);
gs($unknown['status'] === 400 && ($unknown['json']['error'] ?? '') === 'Okänd personbrevlåda.', 'kontakt@ nekas över HTTP');

$getTok = $http($base . '?token=probe', 'GET', null, []);
gs($getTok['status'] === 405, 'query-token på GET är 405');
$getPlain = $http($base, 'GET', null, []);
gs($getPlain['status'] === 200 && $getPlain['json'] === ['ok' => true], 'GET utan token avslöjar inte Skickat');

$logText = is_file($log) ? (string) file_get_contents($log) : '';
gs(!str_contains($logText, $inboxToken) && !str_contains($logText, 'HTTP-HEMLIG') && !str_contains($logText, 'post-secret'), 'PHP-loggen innehåller varken token eller brödtext');

if (is_resource($proc)) {
    proc_terminate($proc);
    proc_close($proc);
}
echo "inkorg gp_sent: ok\n";
