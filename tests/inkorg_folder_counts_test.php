<?php

declare(strict_types=1);

/** Öppna ärenden utan klass räknas i Inkorg. Sök filtrerar vyn. Inget utskick. */

$tmp = sys_get_temp_dir() . '/jeab-fold-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['jeab_email'] = 'saljare3@jarfalla.example.test';

function fc(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$in = static function (string $id, string $from, string $subject, string $text, array $labels): array {
    $row = jeab_inkorg_ingest([
        'message_id' => $id . '@fold.test',
        'from' => $from,
        'from_name' => 'Kund ' . $id,
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => $subject,
        'body_text' => $text,
        'source' => 'graph-jarfalla',
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
        'preset_labels' => $labels,
    ]);
    fc(!empty($row['ok']) && !empty($row['thread_id']), $id . ' tas in');

    return $row;
};
$blank = ['type' => '', 'intent' => '', 'confidence' => 0, 'action' => 'svara', 'priority' => 3];
$info = ['type' => 'Information', 'intent' => 'information', 'confidence' => 90, 'action' => 'arkivera', 'priority' => 4];
$support = ['type' => 'Support', 'intent' => 'support', 'confidence' => 90, 'action' => 'svara', 'priority' => 2];

$old = $in('old', 'gammal@kund.example', 'Gammal pump', 'Detta mejl har ingen klass.', $blank);
jeab_inkorg_thread_write((string) $old['thread_id'], static function (array $t): array {
    $t['status'] = 'open';
    $t['assignee'] = '';
    $t['import_source'] = 'helpscout_import';
    $t['labels'] = ['type' => ''];

    return $t;
});
$elinOpen = $in('elin-open', 'ann@kund.example', 'Elin supportfraga', 'En öppen fråga hos Elin.', $support);
jeab_inkorg_assign((string) $elinOpen['thread_id'], 'Elin');
$batt = $in('batt', 'vera@kund.example', 'Batteribyte', 'Vi behöver ett nytt batteri.', $info);
jeab_inkorg_assign((string) $batt['thread_id'], 'Roberto');
$faktura = $in('faktura', 'vera@kund.example', 'Leveransbesked', 'Paketet är på väg.', $info);
jeab_inkorg_assign((string) $faktura['thread_id'], 'Roberto');
$elinBatt = $in('elin-batt', 'elsa@kund.example', 'Batteri hos Elin', 'Elin har ett batteriärende.', $info);
jeab_inkorg_assign((string) $elinBatt['thread_id'], 'Elin');
$service = $in('svc', 'bosse@kund.example', 'Servicebesok', 'Dags att titta på anläggningen.', $support);
jeab_inkorg_thread_set((string) $service['thread_id'], 'open', ['service'], false);
jeab_inkorg_assign((string) $service['thread_id'], 'Roberto');
$closed = $in('closed', 'arkiv@kund.example', 'Arkiverat arende', 'Detta är stängt.', $support);
jeab_inkorg_thread_write((string) $closed['thread_id'], static function (array $t): array {
    $t['status'] = 'closed';

    return $t;
});

$thread = static function (string $tid): array {
    foreach (jeab_inkorg_threads() as $t) {
        if ((string) ($t['id'] ?? '') === $tid) {
            return $t;
        }
    }

    return [];
};
$oldThread = $thread((string) $old['thread_id']);
fc((string) ($oldThread['labels']['type'] ?? '') === '' && (string) ($oldThread['status'] ?? '') === 'open', 'gammalt öppet ärende saknar klass');
fc(jeab_inkorg_folder_thread($oldThread, 'inkorg') && jeab_inkorg_folder_thread($oldThread, 'alla') && !jeab_inkorg_folder_thread($oldThread, 'historik'), 'öppen import utan klass ligger i Inkorg och Alla');
fc(!jeab_inkorg_folder_thread($thread((string) $closed['thread_id']), 'alla') && jeab_inkorg_folder_thread($thread((string) $closed['thread_id']), 'historik'), 'stängt ärende ligger bara i Historik');
$svcThread = $thread((string) $service['thread_id']);
fc(jeab_inkorg_folder_thread($svcThread, 'service') && !jeab_inkorg_folder_thread($svcThread, 'inkorg') && jeab_inkorg_folder_thread($svcThread, 'alla'), 'Service räknas i Service och Alla, inte i Inkorg');

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-inkorg', 'scope' => 'egna'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
unset($GLOBALS['_JEAB_INKORG_UNREAD'], $GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_THREADS']);
$GLOBALS['_JEAB_INKORG_COUNT_COMPUTE'] = 0;
$own = jeab_inkorg_fast_prepare(true);
fc((array) ($own['counts'] ?? []) === [] || (int) ($own['counts_fresh'] ?? 0) === 0, 'första sidträffen räknar inte om vykorten');
fc(!str_contains((string) ($own['html'] ?? ''), 'Gammal pump'), 'Egna döljer otilldelat utan produkt');
fc(!str_contains((string) ($own['html'] ?? ''), 'Elin supportfraga'), 'Egna döljer Elins ärende');
$ownBag = jeab_inkorg_fast_counts_refresh();
$ownCounts = (array) ($ownBag['counts'] ?? []);
fc(!str_contains((string) ($own['html'] ?? ''), 'Batteribyte'), 'Egna Inkorg visar inte typen Information');
fc((int) ($ownCounts['v-inkorg'] ?? -1) === 0 && (int) ($ownCounts['v-inkorg'] ?? -1) === (int) ($own['total'] ?? -2), 'Egna Inkorg är tom när det tilldelade är Information');
fc((int) ($ownCounts['v-alla'] ?? 0) >= (int) ($ownCounts['v-information'] ?? 0) + (int) ($ownCounts['v-inkorg'] ?? 0) + (int) ($ownCounts['v-service'] ?? 0), 'Egna Alla är minst Information + Inkorg + Service');
unset($GLOBALS['_JEAB_INKORG_UNREAD'], $GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_THREADS']);
$GLOBALS['_JEAB_INKORG_COUNT_COMPUTE'] = 0;
$painted = jeab_inkorg_fast_prepare(true);
fc((int) $GLOBALS['_JEAB_INKORG_COUNT_COMPUTE'] === 0, 'ritningen efter räkningen läser cachen');
fc((int) jeab_inkorg_unread_count() === (int) ($ownCounts['v-inkorg'] ?? -1), 'flikens märke använder samma Inkorg-urval som räknaren');
fc((int) (($painted['counts'] ?? [])['v-inkorg'] ?? -1) === (int) ($ownCounts['v-inkorg'] ?? -2), 'ritningen visar de cachade Egna-talen');

$_GET['scope'] = 'allas';
unset($GLOBALS['_JEAB_INKORG_UNREAD'], $GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_THREADS']);
$GLOBALS['_JEAB_INKORG_COUNT_COMPUTE'] = 0;
$all = jeab_inkorg_fast_prepare(true);
fc((array) ($all['counts'] ?? []) === [] || (int) ($all['counts_fresh'] ?? 0) === 0, 'Allas-sidträffen räknar inte om vykorten');
fc(str_contains((string) ($all['html'] ?? ''), 'Elin supportfraga') && str_contains((string) ($all['html'] ?? ''), 'Gammal pump'), 'Allas Inkorg visar egna och andras öppna ärenden');
$allCounts = (array) (jeab_inkorg_fast_counts_refresh()['counts'] ?? []);
fc((int) ($allCounts['v-inkorg'] ?? 0) >= 2 && (int) ($allCounts['v-inkorg'] ?? 0) === (int) ($all['total'] ?? -2), 'Allas Inkorg räknar egna och andras öppna ärenden');
fc((int) ($allCounts['v-alla'] ?? 0) >= (int) ($allCounts['v-information'] ?? 0) + (int) ($allCounts['v-inkorg'] ?? 0) + (int) ($allCounts['v-service'] ?? 0), 'Allas Alla är minst Information + Inkorg + Service');
fc((int) ($allCounts['v-inkorg'] ?? 0) > (int) ($ownCounts['v-inkorg'] ?? 0), 'Allas Inkorg är fler än Egna');

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-information', 'scope' => 'allas', 'q' => 'batteri'];
unset($GLOBALS['_JEAB_INKORG_UNREAD'], $GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_THREADS']);
$GLOBALS['_JEAB_INKORG_COUNT_COMPUTE'] = 0;
$kept = jeab_inkorg_fast_prepare(true);
fc((int) $GLOBALS['_JEAB_INKORG_COUNT_COMPUTE'] === 0, 'sök-klicket räknar inte om vykorten');
fc((string) ($kept['vyId'] ?? '') === 'v-information' && (string) ($kept['scope'] ?? '') === 'allas' && (string) ($kept['q'] ?? '') === 'batteri', 'Allas behåller vyn och sökordet');

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-inkorg', 'scope' => 'egna'];
unset($GLOBALS['_JEAB_INKORG_UNREAD'], $GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_THREADS']);
$GLOBALS['_JEAB_INKORG_COUNT_COMPUTE'] = 0;
$backOwn = jeab_inkorg_fast_prepare(true);
fc((int) $GLOBALS['_JEAB_INKORG_COUNT_COMPUTE'] === 0, 'tillbaka till Egna räknar inte om i klicket');
fc((int) (($backOwn['counts'] ?? [])['v-inkorg'] ?? -1) === (int) ($ownCounts['v-inkorg'] ?? -2), 'tillbaka till Egna visar Egnas tal, inte Allas');

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-information', 'scope' => 'egna', 'q' => 'batteri'];
unset($GLOBALS['_JEAB_INKORG_UNREAD'], $GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_THREADS']);
$seek = jeab_inkorg_fast_prepare(true);
$html = (string) ($seek['html'] ?? '');
$ownAt = strpos($html, 'Batteribyte');
$headAt = strpos($html, 'Hos andra medarbetare');
$otherAt = strpos($html, 'Batteri hos Elin');
fc($ownAt !== false && $headAt !== false && $otherAt !== false && $ownAt < $headAt && $headAt < $otherAt, 'sök i Information visar egna träffar före andras');
fc(!str_contains($html, 'Leveransbesked') && !str_contains($html, 'Gammal pump') && !str_contains($html, 'Servicebesok'), 'sökordet filtrerar bort övriga ärenden i vyn');

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-inkorg', 'scope' => 'allas'];
unset($GLOBALS['_JEAB_INKORG_UNREAD'], $GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_THREADS']);
$page = (string) include dirname(__DIR__) . '/src/pages/inkorg.php';
fc(str_contains($page, 'data-ix-scope="egna"') && str_contains($page, 'data-ix-scope="allas"'), 'Egna och Allas har scope i länken');
fc(str_contains($page, 'class="chip is-on" data-ix-scope="allas"') && !str_contains($page, 'class="chip is-on" data-ix-scope="egna"'), 'Allas är markerad när scope är allas');
fc(str_contains($page, 'scope=allas') && str_contains($page, 'scope=egna'), 'båda lägena finns i adresserna');
fc(str_contains($page, 'name="q"') && str_contains($page, 'jeabInkorgListQuery'), 'sökfältet skickar q via listfrågan');

$node = getenv('NODE') ?: 'node';
$script = <<<'JS'
const assert = require('assert');
const nav = require(process.argv[2]);
const qs = nav.jeabInkorgListQuery({ box: 'jarfalla', view: 'v-information', scope: 'egna', q: 'batteri' });
assert.ok(qs.indexOf('q=batteri') !== -1, qs);
assert.ok(qs.indexOf('view=v-information') !== -1, qs);
assert.ok(qs.indexOf('scope=egna') !== -1, qs);
assert.strictEqual(nav.jeabInkorgListKey({ box: 'jarfalla', view: 'v-inkorg', scope: '' }), nav.jeabInkorgListKey({ box: 'jarfalla', view: 'v-inkorg', scope: 'allas' }));
assert.notStrictEqual(nav.jeabInkorgListKey({ box: 'jarfalla', view: 'v-inkorg', scope: 'egna' }), nav.jeabInkorgListKey({ box: 'jarfalla', view: 'v-inkorg', scope: 'allas' }));
JS;
$nodeFile = $tmp . '/nav-check.js';
file_put_contents($nodeFile, $script);
exec(escapeshellarg($node) . ' ' . escapeshellarg($nodeFile) . ' ' . escapeshellarg(dirname(__DIR__) . '/assets/js/inkorg-nav.js') . ' 2>&1', $nodeOut, $nodeCode);
fc($nodeCode === 0, 'nav-hjälpen skickar q och skiljer Egna från Allas' . ($nodeCode === 0 ? '' : ': ' . implode(' ', $nodeOut)));

$router = $tmp . '/router.php';
file_put_contents($router, "<?php\n\$GLOBALS['_JEAB_DATA_DIR'] = getenv('JEAB_DATA');\nputenv('JEAB_DISABLE_SQLITE=1');\nputenv('XAI_API_KEY');\nrequire getenv('JEAB_ROOT') . '/src/bootstrap.php';\nrequire getenv('JEAB_ROOT') . '/src/layout.php';\nif (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }\n\$_SESSION['jeab_email'] = 'saljare3@jarfalla.example.test';\n\$_GET = ['p' => 'inkorg', 'box' => 'jarfalla'];\n\$qs = [];\nparse_str((string) parse_url((string) (\$_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY), \$qs);\nif (is_array(\$qs)) { \$_GET = array_merge(\$_GET, \$qs); }\n\$_SERVER['REQUEST_METHOD'] = 'GET';\n\$_SERVER['SCRIPT_NAME'] = '/index.php';\necho include getenv('JEAB_ROOT') . '/src/pages/inkorg.php';\n");
$port = 0;
for ($try = 8191; $try < 8210; $try++) {
    $socket = @stream_socket_server('tcp://127.0.0.1:' . $try, $errno, $errstr);
    if ($socket === false) {
        continue;
    }
    fclose($socket);
    $port = $try;
    break;
}
fc($port > 0, 'en lokal port är ledig');
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, $router],
    [1 => ['file', $tmp . '/server.log', 'a'], 2 => ['file', $tmp . '/server.log', 'a']],
    $pipes,
    $tmp,
    ['JEAB_DATA' => $tmp, 'JEAB_ROOT' => dirname(__DIR__), 'JEAB_DISABLE_SQLITE' => '1']
);
fc(is_resource($server), 'php-servern startade');
$pull = static function (string $path) use ($port): array {
    $code = 0;
    $body = '';
    for ($i = 0; $i < 20; $i++) {
        $curl = curl_init('http://127.0.0.1:' . $port . $path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
        $body = (string) curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($code === 200 && str_contains($body, 'data-ix-scope')) {
            break;
        }
        usleep(150000);
    }

    return [$code, $body];
};
[$code, $body] = $pull('/?view=v-information&scope=egna&q=batteri');
fc($code === 200 && str_contains($body, 'Batteribyte') && str_contains($body, 'Batteri hos Elin') && !str_contains($body, 'Leveransbesked'), 'curl filtrerar Information på q=batteri, fick ' . $code);
[$code, $body] = $pull('/?view=v-inkorg&scope=allas');
fc($code === 200 && str_contains($body, 'class="chip is-on" data-ix-scope="allas"') && str_contains($body, 'Gammal pump') && str_contains($body, 'Elin supportfraga'), 'curl byter till Allas och visar öppna ärenden, fick ' . $code);
fc($code === 200 && preg_match('#assets/js/inkorg-nav\.js\?v=\d+#', $body) === 1 && str_contains($body, 'jeabInkorgBindScopeClicks'), 'ritad sida laddar scope-klicket från inkorg-nav.js?v=');
if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}

echo "folder counts ok\n";
