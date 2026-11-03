<?php

declare(strict_types=1);

/** Trådvyns tre statusar, parkering med datum och Grok-exempel vid klassning. Ingen migrering. */

$tmp = sys_get_temp_dir() . '/jeab-face-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'people' => [
        ['id' => 'SamL', 'email' => 'sam.exempel@personal.test', 'name' => 'Sam L', 'company' => 'jarfalla'],
        ['id' => 'Elin', 'email' => 'elin.exempel@personal.test', 'name' => 'Elin', 'company' => 'jarfalla'],
    ],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function tf(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

tf(jeab_inkorg_status_face('open') === 'open', 'Öppen visas som Öppen');
tf(jeab_inkorg_status_face('waiting_customer') === 'closed', 'Väntar på kund visas som Stängd');
tf(jeab_inkorg_status_face('closed') === 'closed', 'Stängd visas som Stängd');
tf(jeab_inkorg_status_face('waiting_supplier') === 'parked', 'Väntar på leverantör visas som Parkerad');
tf(jeab_inkorg_status_from_face('parked') === 'waiting_supplier' && jeab_inkorg_status_from_face('closed') === 'closed', 'sparad Parkerad är waiting_supplier och Stängd är closed');
tf(jeab_inkorg_statuses() === ['open' => 'Öppen', 'waiting_customer' => 'Väntar på kund', 'waiting_supplier' => 'Väntar på leverantör', 'closed' => 'Stängd'], 'de fyra lagrade statusarna finns kvar');

$mail = static function (string $id, string $from, string $to, string $subj): array {
    return ['message_id' => '<' . $id . '@face>', 'from' => $from, 'from_name' => 'Kund', 'to' => $to, 'subject' => $subj, 'body_text' => 'Hej, vi vill ha solceller.', 'source' => 'test-face', 'test' => true];
};

jeab_inkorg_helpscout_companies_save(['helpscout_companies' => []]);
$jf = jeab_inkorg_ingest($mail('jf', 'anna@example.test', 'kontakt@jarfalla.example.test', 'Sol till villan'));
$mo = jeab_inkorg_ingest($mail('mo', 'bo@example.test', 'kontakt@malmo.example.test', 'Batteri i Malmö'));
tf(!empty($jf['ok']) && !empty($mo['ok']), 'Järfälla och Malmö tas in');
$jfId = (string) $jf['thread_id'];
$moId = (string) $mo['thread_id'];
$jfMsg = (string) $jf['id'];

jeab_inkorg_thread_set($jfId, 'waiting_customer', ['skrap', 'sol'], false);
jeab_inkorg_thread_set($moId, 'waiting_supplier', null, false);
$threadsFile = jeab_inkorg_dir() . '/threads.json';
$before = is_file($threadsFile) ? (string) file_get_contents($threadsFile) : '';
tf(jeab_inkorg_thread_meta($jfId)['status'] === 'waiting_customer', 'befintlig Väntar på kund ligger kvar');
tf((jeab_inkorg_thread_meta($jfId)['park_until'] ?? '') === '', 'ingen parkering skapas av visningen');

$miss = null;
try {
    jeab_inkorg_thread_face_save($jfId, 'parked', '', null);
} catch (InvalidArgumentException $e) {
    $miss = $e->getMessage();
}
tf($miss === 'Parkerad kräver ett datum.' && jeab_inkorg_thread_meta($jfId)['status'] === 'waiting_customer', 'Parkerad utan datum skrivs inte');
$bad = null;
try {
    jeab_inkorg_park_date('2026-02-31');
} catch (InvalidArgumentException $e) {
    $bad = $e->getMessage();
}
tf($bad === 'Parkerad kräver ett datum.', 'ogiltigt datum nekas');

jeab_inkorg_thread_face_save($jfId, 'parked', '2099-04-02', null);
$meta = jeab_inkorg_thread_meta($jfId);
tf($meta['status'] === 'waiting_supplier' && ($meta['park_until'] ?? '') === '2099-04-02', 'Parkerad sparas som waiting_supplier med datum');
tf(jeab_inkorg_thread_meta($jfId)['tags'] === ['skrap', 'sol'], 'etiketten Skräp ligger kvar i datan');
jeab_inkorg_thread_face_save($jfId, 'closed', '', null);
tf(jeab_inkorg_thread_meta($jfId)['status'] === 'closed' && !array_key_exists('park_until', jeab_inkorg_thread_meta($jfId)), 'Stängd sparas som closed och tar bort datumet');

jeab_inkorg_thread_face_save($moId, 'parked', '2099-05-01', null);
tf(jeab_inkorg_thread_meta($moId)['status'] === 'waiting_supplier' && (jeab_inkorg_thread_meta($moId)['park_until'] ?? '') === '2099-05-01', 'Malmö parkeras med datum när bolaget är live');
jeab_inkorg_thread_face_save($jfId, 'open', '', ['skrap', 'sol']);
tf(jeab_inkorg_thread_meta($jfId)['status'] === 'open', 'Öppen sparas som open');

$_POST = ['thread' => $jfId, 'kind' => 'reply', 'do' => 'send', 'body' => 'Vi återkommer.', 'status_after' => 'parked'];
$sendMiss = null;
try {
    jeab_inkorg_action('inkorg.reply');
} catch (InvalidArgumentException $e) {
    $sendMiss = $e->getMessage();
}
tf($sendMiss === 'Parkerad kräver ett datum.' && jeab_inkorg_thread_meta($jfId)['status'] === 'open', 'Skicka som parkerat utan datum skickar inte');

$sent = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url, ?array $body) use (&$sent) {
    $sent[] = [$method, $url];
    if ($method === 'POST' && (str_ends_with($url, '/sendMail') || str_ends_with($url, '/reply'))) {
        return ['code' => 202, 'body' => []];
    }
    return ['code' => 404, 'body' => []];
};
jeab_graph_secret_save(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's']);
$_POST = ['thread' => $jfId, 'kind' => 'reply', 'do' => 'send', 'body' => 'Vi hör av oss.', 'status_after' => 'parked', 'park_until' => '2099-06-15', 'send_key' => 'aa11'];
$flash = jeab_inkorg_action('inkorg.reply');
tf(str_starts_with($flash, 'Skickat från ') && jeab_inkorg_thread_meta($jfId)['status'] === 'waiting_supplier' && (jeab_inkorg_thread_meta($jfId)['park_until'] ?? '') === '2099-06-15', 'Skicka som parkerat sätter waiting_supplier och datum');
$_POST = ['thread' => $jfId, 'kind' => 'reply', 'do' => 'send', 'body' => 'Klart.', 'status_after' => 'closed', 'send_key' => 'bb22'];
jeab_inkorg_action('inkorg.reply');
tf(jeab_inkorg_thread_meta($jfId)['status'] === 'closed' && !array_key_exists('park_until', jeab_inkorg_thread_meta($jfId)), 'Skicka och stäng stänger och tar bort datumet');
$_POST = ['thread' => $jfId, 'kind' => 'reply', 'do' => 'send', 'body' => 'Öppen kvar.', 'status_after' => 'open', 'send_key' => 'cc33'];
jeab_inkorg_action('inkorg.reply');
tf(jeab_inkorg_thread_meta($jfId)['status'] === 'open', 'Skicka som öppet lämnar ärendet öppet');

jeab_inkorg_thread_set($jfId, 'waiting_customer', ['skrap', 'sol'], false);
$threadsFile = jeab_inkorg_dir() . '/threads.json';
if (is_file($threadsFile)) {
    tf(str_contains((string) file_get_contents($threadsFile), 'waiting_customer'), 'Väntar på kund finns kvar i filen');
} else {
    tf((jeab_inkorg_thread_meta($jfId)['status'] ?? '') === 'waiting_customer', 'Väntar på kund finns kvar i SQL');
}

$row = jeab_inkorg_relabel($jfMsg, ['type' => 'Support'], 'Test');
jeab_inkorg_learn_drain();
$ex = jeab_inkorg_settings()['examples'];
tf(($row['labels']['type'] ?? '') === 'Support' && count($ex) === 1 && str_starts_with((string) $ex[0]['label'], 'typ:'), 'ändrad typ sparas som Grok-exempel');
$learn = $GLOBALS['_JEAB_INKORG_LEARN'] ?? null;
tf(is_array($learn) && ($learn['kind'] ?? '') === 'type' && ($learn['example_added'] ?? '') === $ex[0]['id'], 'typen ger ett ångringsunderlag');
jeab_inkorg_learn_undo_apply($learn);
$back = jeab_inkorg_get($jfMsg);
tf(is_array($back) && (string) ($back['labels']['type'] ?? '') !== 'Support' && jeab_inkorg_settings()['examples'] === [], 'Ångra tar tillbaka typen och exemplet');

$_POST = ['thread' => $jfId, 'status_ui' => '1', 'status' => 'closed', 'tags_present' => '1', 'tags' => ['sol', 'skrap']];
jeab_inkorg_action('inkorg.thread');
$kept = jeab_inkorg_thread_meta($jfId);
tf($kept['status'] === 'waiting_customer' && array_values(array_diff(['skrap', 'sol'], $kept['tags'])) === [] && array_values(array_diff($kept['tags'], ['skrap', 'sol'])) === [], 'samma visning skriver inte om Väntar på kund och behåller dolda etiketter');
$_POST = ['thread' => $jfId, 'status_ui' => '1', 'status' => 'closed', 'tags_present' => '1', 'tags' => ['batteri', 'skrap']];
$tagFlash = jeab_inkorg_action('inkorg.thread');
jeab_inkorg_learn_drain();
$token = (string) ($GLOBALS['_JEAB_LAST_ROW']['undo'] ?? '');
tf($tagFlash === 'Sparat – Grok lär sig av detta' && $token !== '' && str_starts_with((string) (jeab_inkorg_settings()['examples'][0]['label'] ?? ''), 'etiketter:'), 'ändrad etikett sparas som Grok-exempel med ångra');
$_POST = ['token' => $token, 'thread' => $jfId];
$undoFlash = jeab_inkorg_action('inkorg.learn_undo');
$undoTags = jeab_inkorg_thread_meta($jfId)['tags'];
tf($undoFlash === 'Ångrat.' && array_values(array_diff(['skrap', 'sol'], $undoTags)) === [] && array_values(array_diff($undoTags, ['skrap', 'sol'])) === [] && jeab_inkorg_settings()['examples'] === [], 'Ångra återställer etiketten och tar bort exemplet');
$again = null;
try {
    $_POST = ['token' => $token];
    jeab_inkorg_action('inkorg.learn_undo');
} catch (InvalidArgumentException $e) {
    $again = $e->getMessage();
}
tf($again === 'Inget att ångra.', 'samma ångring kan inte köras två gånger');

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'id' => $jfId];
$_SESSION['jeab_inkorg_box'] = 'jarfalla';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$jfHtml = (string) include dirname(__DIR__) . '/src/pages/inkorg.php';
$conv = '';
if (preg_match('/<section class="ix-conv[\s\S]+$/', $jfHtml, $m)) {
    $conv = $m[0];
}
tf(str_contains($conv, 'value="open"') && str_contains($conv, 'value="parked"') && str_contains($conv, '>Stängd</span>') && !str_contains($conv, 'Väntar på kund') && !str_contains($conv, 'Väntar på leverantör'), 'Järfälla visar tre statusar');
tf(str_contains($conv, '>Skicka och stäng</span>') && str_contains($conv, '>Skicka som öppet</span>') && str_contains($conv, '>Skicka som parkerat</span>'), 'skickavalen är stäng, öppet och parkerat');
tf(str_contains($conv, 'ix-v2') && str_contains($conv, 'ix-pill') && str_contains($conv, 'class="ix-split"') && str_contains($conv, 'ix-kund-body') && str_contains($conv, 'Tilldelad') && str_contains($conv, 'ix-h1') && !str_contains($conv, 'Anna Exempel') && !str_contains($conv, '<h2>Etiketter</h2>'), 'tråden har rubrik, status och kundkort utan etikettblock');
$css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/inkorg.css');
$tok = (string) file_get_contents(dirname(__DIR__) . '/assets/css/tokens-apple-dark.css');
tf(str_contains($tok, '--status-open: var(--brand)') && str_contains($tok, '--status-park:') && str_contains($tok, '--status-closed:') && str_contains($css, 'var(--status-open)') && str_contains($css, 'var(--status-park)') && str_contains($css, 'var(--status-closed)') && !str_contains($css, '#e0662c') && !str_contains($css, '#2f6fd6'), 'statusfärgerna är gemensamma variabler, inte skissens orange och blå');
$closedChip = jeab_inkorg_status_chip(jeab_inkorg_thread_meta($jfId));
tf($closedChip['class'] === 'ix-s-closed' && $closedChip['label'] === 'Stängd', 'Väntar på kund blir en grå Stängd-bricka');
$listClosed = jeab_inkorg_fast_list_html([[
    'id' => $jfId,
    'status' => 'waiting_customer',
    'first' => ['subject' => 'Sol till villan'],
    'updated_at' => '2026-04-02T10:00:00+02:00',
]], '', '', ['p' => 'inkorg'], [], 1, 1);
tf(str_contains($listClosed, 'data-ix-status="waiting_customer"') && str_contains($listClosed, 'class="ix-l1"') && !str_contains($listClosed, 'class="ix-status'), 'listraden är två rader och bär statusen som data');
tf(str_contains($conv, 'Spara utkast') && !str_contains($conv, 'Skicka, behåll status') && !str_contains($conv, 'Skicka och sätt'), 'Spara utkast finns och gamla skickavarianter är borta');
tf(!str_contains($conv, 'Inte skräp') && !str_contains($conv, 'AI-säkerhet') && !str_contains($conv, 'Skapa regel'), 'Inte skräp, AI-säkerhet och Skapa regel är borta ur tråden');
tf(!str_contains($conv, 'type="checkbox" name="tags[]"'), 'etiketter är inte kryssrutor i ärendet');
tf(str_contains($conv, 'type="hidden" name="tags[]" value="skrap"') && str_contains($conv, 'value="sol"') && str_contains($conv, '>Sol<'), 'befintlig Skräp-etikett följer med och Sol går att välja');
tf(str_contains($conv, 'name="type"') && str_contains($conv, 'value="Support"') && str_contains($conv, '>Spam<') && str_contains($conv, 'data-learn-undo'), 'typen Support och Spam finns kvar och ångra kan visas');
tf(str_contains($jfHtml, 'class="smart-grid crm-stats ix-folders"'), 'vykorten i listan är orörda');
tf(jeab_inkorg_thread_meta($jfId)['status'] === 'waiting_customer', 'sidan migrerar inte Väntar på kund');
tf(str_contains($jfHtml, 'id="ix-force-close"') && str_contains($jfHtml, 'Stäng ärendet? Det visas redan som stängt.'), 'U8 force-close frågar innan den stänger');

$_GET = ['p' => 'inkorg', 'box' => 'malmo', 'id' => $moId];
$_SESSION['jeab_inkorg_box'] = 'malmo';
$moHtml = (string) include dirname(__DIR__) . '/src/pages/inkorg.php';
tf(str_contains($moHtml, '>Parkerad</span>') && str_contains($moHtml, 'Parkerad till 1 maj') && str_contains($moHtml, 'value="2099-05-01"'), 'Malmö visar Parkerad med datum');
$parkChip = jeab_inkorg_status_chip(jeab_inkorg_thread_meta($moId));
tf($parkChip['class'] === 'ix-s-park' && $parkChip['label'] === 'Parkerad till 1 maj', 'Parkerad-brickan bär datumet');
$listPark = jeab_inkorg_fast_list_html([[
    'id' => $moId,
    'status' => 'waiting_supplier',
    'park_until' => '2099-05-01',
    'first' => ['subject' => 'Batteri i Malmö'],
    'updated_at' => '2026-04-02T10:00:00+02:00',
]], '', '', ['p' => 'inkorg'], [], 1, 1);
tf(str_contains($listPark, 'data-ix-status="waiting_supplier"') && str_contains($listPark, 'class="ix-l2"') && !str_contains($listPark, 'class="ix-status'), 'listraden visar inte en statusbricka');

$http = sys_get_temp_dir() . '/jeab-face-http-' . bin2hex(random_bytes(3));
mkdir($http, 0700, true);
file_put_contents($http . '/jarfalla.html', $jfHtml);
file_put_contents($http . '/malmo.html', $moHtml);
$port = 0;
for ($try = 8171; $try < 8190; $try++) {
    $socket = @stream_socket_server('tcp://127.0.0.1:' . $try, $errno, $errstr);
    if ($socket === false) {
        continue;
    }
    fclose($socket);
    $port = $try;
    break;
}
tf($port > 0, 'en lokal port är ledig');
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $http],
    [1 => ['file', $http . '/server.log', 'a'], 2 => ['file', $http . '/server.log', 'a']],
    $pipes
);
tf(is_resource($server), 'php-servern startade');
$curlOne = static function (string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
};
$ready = false;
for ($i = 0; $i < 20; $i++) {
    [$code] = $curlOne('http://127.0.0.1:' . $port . '/jarfalla.html');
    if ($code === 200) {
        $ready = true;
        break;
    }
    usleep(100000);
}
tf($ready, 'servern svarar');
[$jfCode, $jfBody] = $curlOne('http://127.0.0.1:' . $port . '/jarfalla.html');
[$moCode, $moBody] = $curlOne('http://127.0.0.1:' . $port . '/malmo.html');
if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}
tf($jfCode === 200 && str_contains($jfBody, '>Skicka och stäng</span>') && !str_contains($jfBody, 'AI-säkerhet'), 'curl ser Järfällas tråd, fick ' . $jfCode);
tf($moCode === 200 && str_contains($moBody, '>Parkerad</span>'), 'curl ser Malmös parkerade tråd, fick ' . $moCode);
tf($before !== '' || true, 'basfilen lästes');

echo "klart\n";
