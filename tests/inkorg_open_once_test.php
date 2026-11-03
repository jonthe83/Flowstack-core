<?php

declare(strict_types=1);

/**
 * Öppna ärende: ett klick, ingen sparning, kort tid även med stor tråd.
 * Syntetiska rader. Ingen kunddata.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-ixopen-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
$passwords = $tmp . '/passwords.json';
file_put_contents($passwords, json_encode([
    'secret' => 'open-test-secret',
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
file_put_contents($tmp . '/inkorg/settings.json', json_encode([
    'helpscout_companies' => [],
    'send_enabled' => false,
    'examples' => [],
], JSON_UNESCAPED_UNICODE));
require $root . '/src/bootstrap.php';
require $root . '/src/layout.php';

function op(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$page = (string) file_get_contents($root . '/src/pages/inkorg.php');
$js = (string) file_get_contents($root . '/assets/js/inkorg-swipe.js');
$app = (string) file_get_contents($root . '/assets/js/app.js');
$nav = (string) file_get_contents($root . '/assets/js/inkorg-nav.js');
$css = (string) file_get_contents($root . '/assets/css/inkorg.css');
$fast = (string) file_get_contents($root . '/src/inkorg_fast.php');
op(str_contains($page, "if (kind === 'thread')") && str_contains($page, 'ixShowPending(nextId)'), 'klicket anropar ixShowPending');
$cardAt = strpos($page, 'var cardHit = ev.target');
$preventAt = strpos($page, 'if (ev.defaultPrevented && !cardHit) { return; }');
op($cardAt !== false && $preventAt !== false && $cardAt < $preventAt, 'ett redan avbrutet klick på kortet öppnar ändå tråden');
op(str_contains($app, 'function isInkorgRow') && str_contains($app, "classList.contains('page-inkorg')"), 'påminnelseklicket binder inte inkorgskort');
op(str_contains($nav, 'function jeabInkorgThreadRequest') && str_contains($page, 'jeabInkorgThreadRequest(location.href)'), 'adressen styr vilken tråd som hämtas');
op(str_contains($page, 'function ixEnsureAddressThread') && str_contains($page, 'data-ix-open'), 'annan tråd i panelen byts mot id i adressen');
op(str_contains($js, 'function jeabInkorgSwipeRelease') && str_contains($js, 'suppressClick'), 'ett tryck utan svep öppnar länken när klicket annars uteblir');
op(str_contains($page, 'Öppnar ärendet…') && str_contains($page, "box.classList.add('is-open')"), 'första klicket öppnar panelen direkt');
op(str_contains($page, 'cardHit') && str_contains($page, "querySelector('a.item-main')"), 'klick på kortet använder hela raden');
op(str_contains($page, 'pendingSame') && str_contains($page, 'if (!pendingSame)'), 'pågående förhämtning avbryts inte');
op(str_contains($page, 'ev.isTrusted') && str_contains($page, "dataset.ixUser === '1'"), 'autosparning kräver ett klick eller parkeringsknappen');
op(str_contains($page, 'ixOptimisticRead') && str_contains($page, 'X-Jeab-Interactive'), 'läst markeras tyst efter öppning');
op(str_contains($page, "\$part !== 'thread'") && str_contains($page, 'jeab_hs_import_related_threads'), 'trådöppning hoppar över Help Scout-indexet');
op(str_contains($js, "decision.commit === 'close' || decision.commit === 'read'"), 'svep blockerar klick bara när det faktiskt stänger eller läser');
op(str_contains($css, 'touch-action: pan-y') && str_contains($css, '.ix-toast[hidden]') && str_contains($css, 'a.ix-face.item-main'), 'toasten är dold och kortet är en länk');
op(str_contains($fast, 'class="item-main ix-face') && str_contains($fast, 'data-ix-act="read"'), 'listkortet har länk och läsknapp');

$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['p' => 'inkorg', 'ix_part' => 'thread'];
op(jeab_inkorg_external_timeout(25) === 4 && jeab_inkorg_external_timeout(40) === 4, 'extern timeout är 4 s när tråden öppnas');
$_GET = ['p' => 'inkorg'];
op(jeab_inkorg_external_timeout(25) === 25, 'vanlig sidvisning behåller timeouten');
$_SERVER['REQUEST_METHOD'] = 'POST';
op(jeab_inkorg_external_timeout(12) === 12, 'sparning behåller timeouten');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [];

$huge = '<p>' . str_repeat('äldre citat ', 4000) . '</p>';
$fresh = ['id' => 'm1', 'from' => 'kund@example.test', 'body' => "Nytt svar om taket.\n\n" . $huge];
$t0 = microtime(true);
$shown = jeab_inkorg_open_message_text($fresh, true, [$huge, $huge, $huge, $huge, $huge, $huge, $huge, $huge]);
$old = jeab_inkorg_open_message_text($fresh, false, [$huge]);
op(microtime(true) - $t0 < 1.5, 'rensning av stor text är under 1,5 s');
op(str_contains($shown['clean'], 'Nytt svar om taket'), 'senaste mejlet behåller det nya svaret');
op(mb_strlen($old['clean']) <= 281 && mb_strlen($old['raw']) <= 281, 'äldre mejl klipps');

require_once $root . '/tests/sam_l_login.php';
jeab_test_login_sam_l();
op(jeab_auth_ok() && jeab_inkorg_me_id() === 'SamL', 'Sam L är inloggad');

$ing = jeab_inkorg_ingest([
    'message_id' => '<open-1@example.test>',
    'from' => 'kund@example.test',
    'from_name' => 'Kund Exempel',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Sol på taket',
    'body_text' => 'Hej, vi vill ha solceller.',
    'source' => 'graph-jarfalla',
    'test' => true,
]);
op(!empty($ing['ok']), 'ärendet tas in');
$tid = (string) $ing['thread_id'];
jeab_inkorg_thread_set($tid, 'waiting_customer', ['skrap', 'sol'], false);
$beforeAt = (string) (jeab_inkorg_thread_meta($tid)['updated_at'] ?? '');
$beforeEx = jeab_inkorg_settings()['examples'] ?? [];
sleep(1);
$same = jeab_inkorg_thread_face_save($tid, 'closed', '', ['sol', 'skrap']);
op((string) ($same['status'] ?? '') === 'waiting_customer', 'samma visning behåller Väntar på kund');
op((string) (jeab_inkorg_thread_meta($tid)['updated_at'] ?? '') === $beforeAt, 'samma visning skriver inte updated_at');
op(!isset($GLOBALS['_JEAB_INKORG_LEARN']), 'samma visning lär inte Grok');
op((jeab_inkorg_settings()['examples'] ?? []) === $beforeEx, 'samma visning lägger inget exempel');

$list = jeab_inkorg_fast_list_html([jeab_inkorg_thread_meta($tid) + [
    'id' => $tid,
    'first' => ['subject' => 'Sol på taket', 'from' => 'kund@example.test', 'from_name' => 'Kund Exempel'],
    'last' => ['from' => 'kund@example.test'],
    'updated_at' => $beforeAt,
    'message_count' => 1,
]], '', 'SamL', ['p' => 'inkorg'], [], 1, 1);
op(str_contains($list, 'class="item-main ix-face') && str_contains($list, 'class="ix-dot"'), 'kortets länk täcker prick och text');
$linkAt = strpos($list, '<a class="item-main ix-face');
$actAt = strpos($list, 'data-ix-act="read"');
$linkEnd = strpos($list, '</a>');
op($linkAt !== false && $actAt !== false && $linkEnd !== false && $actAt > $linkEnd, 'läs- och stängknappar ligger utanför länken');

function op_child(string $root, string $tmp, string $code): array
{
    $runner = $tmp . '/child.php';
    file_put_contents($runner, "<?php\n" . $code);
    $proc = proc_open(
        [PHP_BINARY, $runner],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        ['JEAB_OPEN_TMP' => $tmp, 'JEAB_ROOT' => $root, 'JEAB_DISABLE_SQLITE' => '0', 'JEAB_PASSWORDS_FILE' => $tmp . '/passwords.json', 'XAI_API_KEY' => '']
    );
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($proc), 'out' => (string) $out, 'err' => (string) $err];
}

$boot = <<<'PHP'
$tmp = getenv('JEAB_OPEN_TMP');
$root = getenv('JEAB_ROOT');
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
putenv('JEAB_PASSWORDS_FILE=' . $tmp . '/passwords.json');
require $root . '/src/bootstrap.php';
require $root . '/src/layout.php';
$_COOKIE['jeab_auth'] = (string) jeab_auth_issue_value('demo@example.test', 'sam-hash-fixture');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
PHP;

$thread = op_child($root, $tmp, $boot . <<<PHP
\$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'id' => '{$tid}', 'ix_part' => 'thread'];
require getenv('JEAB_ROOT') . '/src/pages/inkorg.php';
PHP);
$tj = json_decode($thread['out'], true);
op($thread['code'] === 0 && is_array($tj) && ($tj['ok'] ?? false) === true, 'tråd-JSON svarar ' . trim($thread['err']));
$html = (string) ($tj['html'] ?? '');
op(str_contains($html, 'id="ix-toast" hidden') && !str_contains($html, 'Sparat – Grok lär sig av detta'), 'öppnad tråd visar inte lär-toasten');
op(($tj['read_on_open'] ?? false) === true, 'tråd-JSON ber klienten markera läst');
op(!isset($tj['learn']) && !isset($tj['examples']), 'tråd-JSON har inga nya affärsfält');
$meta = jeab_inkorg_thread_meta($tid);
op((string) ($meta['updated_at'] ?? '') === $beforeAt && (array) ($meta['read'] ?? []) === [], 'öppningen skrev varken klocka eller läst');
op((jeab_inkorg_settings()['examples'] ?? []) === $beforeEx, 'öppningen la inget Grok-exempel');

$GLOBALS['_JEAB_INKORG_LEARN'] = ['kind' => 'tags', 'thread' => $tid];
$_POST = ['thread' => $tid, 'read' => '1'];
$readFlash = jeab_inkorg_action('inkorg.read');
op($readFlash === 'Markerat som läst.' && !isset($GLOBALS['_JEAB_INKORG_LEARN']), 'läst-markering tar inte med learn');
op(!isset($GLOBALS['_JEAB_LAST_ROW']['learn']) && !isset($GLOBALS['_JEAB_LAST_ROW']['undo']), 'läst-svaret har ingen toast');
op((jeab_inkorg_settings()['examples'] ?? []) === $beforeEx, 'läst-markering lär inte Grok');

$_POST = ['thread' => $tid, 'status_ui' => '1', 'status' => 'closed', 'tags_present' => '1', 'tags' => ['batteri', 'skrap']];
$tagFlash = jeab_inkorg_action('inkorg.thread');
op($tagFlash === 'Sparat – Grok lär sig av detta' && !empty($GLOBALS['_JEAB_LAST_ROW']['undo']), 'uttrycklig etikettändring sparar och lär');

$fatId = 'fat1';
op(jeab_inkorg_sql_set_storage('sql') && jeab_inkorg_use_sql(), 'SQL-lagret är öppet');
$store = jeab_inkorg_pdo_store();
op($store instanceof JeabPdoInkorgStore, 'SQL-handtaget finns');
$blob = '<div>' . str_repeat('<p>Hej detta är ett långt Help Scout-mejl med citat och tabell.</p>', 400) . '</div>';
for ($i = 1; $i <= 30; $i++) {
    $store->upsertMessage($fatId . 'm' . $i, [
        'id' => $fatId . 'm' . $i,
        'thread_id' => $fatId,
        'from' => $i === 30 ? 'vi@jarfalla.example.test' : 'kund@example.test',
        'from_name' => 'Kund Exempel',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Stor tråd',
        'body' => $i === 30 ? 'Senaste korta svaret.' : $blob,
        'received_at' => sprintf('2024-01-%02dT10:00:00+00:00', min($i, 28)),
        'source' => $i === 30 ? 'outbound-jarfalla' : 'graph-jarfalla',
        'labels' => ['type' => 'Support'],
    ]);
}
$store->upsertThread($fatId, [
    'status' => 'open',
    'tags' => [],
    'assignee' => '',
    'read' => [],
    'company' => 'jarfalla',
    'updated_at' => '2024-02-01T10:00:00+00:00',
]);
$fatStarted = microtime(true);
$fat = op_child($root, $tmp, $boot . <<<'PHP'
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'id' => 'fat1', 'ix_part' => 'thread'];
$t = microtime(true);
require getenv('JEAB_ROOT') . '/src/pages/inkorg.php';
PHP);
$fatWall = microtime(true) - $fatStarted;
$fj = json_decode($fat['out'], true);
op($fat['code'] === 0 && is_array($fj) && ($fj['ok'] ?? false) === true, 'stor tråd svarar ' . trim($fat['err']));
op($fatWall < 3, 'stor tråd öppnas på ' . round($fatWall, 2) . ' s');
op(str_contains((string) ($fj['html'] ?? ''), 'Senaste korta svaret') && str_contains((string) ($fj['html'] ?? ''), 'id="ix-toast" hidden'), 'stor tråd visar senaste texten utan toast');

file_put_contents($tmp . '/time.php', <<<'PHP'
<?php
$tmp = getenv('JEAB_OPEN_TMP') ?: dirname(__FILE__);
$root = getenv('JEAB_ROOT');
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
putenv('JEAB_PASSWORDS_FILE=' . $tmp . '/passwords.json');
require $root . '/src/bootstrap.php';
require $root . '/src/layout.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/time.php';
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'id' => 'fat1', 'ix_part' => 'thread'];
require $root . '/src/pages/inkorg.php';
PHP);
$port = 18947;
$proc = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, $tmp . '/time.php'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $tmp,
    ['JEAB_OPEN_TMP' => $tmp, 'JEAB_ROOT' => $root, 'JEAB_DISABLE_SQLITE' => '0', 'JEAB_PASSWORDS_FILE' => $tmp . '/passwords.json']
);
op(is_resource($proc), 'lokal server startar');
usleep(250000);
$ch = curl_init('http://127.0.0.1:' . $port . '/time.php?p=inkorg&box=jarfalla&id=fat1&ix_part=thread');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_HTTPHEADER => ['X-Jeab-Interactive: 1', 'X-Requested-With: XMLHttpRequest', 'Accept: application/json'],
]);
$raw = (string) curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ms = round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000, 1);
curl_close($ch);
proc_terminate($proc);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);
$header = strstr($raw, "\r\n\r\n", true);
$body = (string) substr($raw, strlen((string) $header) + 4);
$payload = json_decode($body, true);
op($code === 200 && is_array($payload) && ($payload['ok'] ?? false) === true, 'curl tråd HTTP ' . $code);
op($ms < 3000, 'curl stor tråd ' . $ms . ' ms');
op(!str_contains($body, 'Sparat – Grok lär sig av detta'), 'curl-svaret saknar lär-toasten');
echo 'curl tråd ' . $ms . " ms\n";
echo "inkorg open once: ok\n";
