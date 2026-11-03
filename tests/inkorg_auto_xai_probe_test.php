<?php

declare(strict_types=1);

/**
 * Sidskyddet släpper api.x.ai och stoppar andra värdar med blocked=1 ms=0.
 * HTTP 0 loggar curl-errno. inbox.xai_probe skickar inget.
 * Ett AI-fel visar en tydlig text och offerten använder mallen utan det korta svaret.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-xai-probe-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp . '/public';
mkdir($GLOBALS['_JEAB_PUBLIC_DATA_DIR'], 0775, true);
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
$key = 'xai-probe-key-' . bin2hex(random_bytes(4));
$llmPath = $tmp . '/secrets/inkorg-llm.json';
$writeLlm = static function (string $url) use ($llmPath, $key): void {
    file_put_contents($llmPath, json_encode([
        'key' => $key,
        'url' => $url,
        'model' => 'grok-test',
    ]));
};
$writeLlm('http://127.0.0.1:9/v1/chat/completions');
require dirname(__DIR__) . '/src/bootstrap.php';
jeab_store();
$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';

function xp(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$calls = 0;
$seenBody = '';
$GLOBALS['_JEAB_AUTO_LLM_HTTP'] = static function (string $url, string $body) use (&$calls, &$seenBody): array {
    $calls++;
    $seenBody = $body;
    xp(!str_contains($body, 'xai-probe-key') && !str_contains($url, 'xai-probe-key'), 'testkroken får inte nyckeln');

    return ['status' => 0, 'errno' => 28, 'error' => 'Operation timed out after 45001 milliseconds', 'body' => false];
};

$bad = "Hej\n" . str_repeat('A', 90) . "\xFF\n%PDF-1.4 binary\n" . str_repeat('abcdEFG123+/=', 8) . "\n> gammal offert\nDen 1 maj skrev Ada:\nBehov: batteri hemma\nHur många kWh batteri behöver villan?\n";
$stripped = jeab_auto_llm_strip_noise($bad);
xp(!str_contains($stripped, '%PDF-') && !str_contains($stripped, 'abcdEFG123'), 'PDF och base64 tas bort');
xp(!str_contains($stripped, 'gammal offert') && str_contains($stripped, 'Behov: batteri hemma'), 'citerad historik tas bort, behovet är kvar');
xp(preg_match('//u', $stripped) === 1, 'ogiltig UTF-8 skrubbas');
$keptRules = jeab_auto_llm_trim_messages([['role' => 'system', 'content' => str_repeat('R', 9500) . "\n\nMall:\n" . str_repeat('M', 4000)]]);
$keptSystem = (string) ($keptRules[0]['content'] ?? '');
xp(str_contains($keptSystem, str_repeat('R', 9500)) && !str_contains($keptSystem, 'MMM'), 'svarsreglerna lämnas och mallen klipps');

$hugeSystem = str_repeat('regel ', 2000);
jeab_auto_llm_exchange([
    ['role' => 'system', 'content' => $hugeSystem . "\xFF"],
    ['role' => 'user', 'content' => $bad],
]);
xp($calls === 1, 'anropet gick till kroken, fick ' . $calls);
$sent = json_decode($seenBody, true);
xp(is_array($sent), 'kroppen är JSON, inte tom');
$sys = (string) ($sent['messages'][0]['content'] ?? '');
$user = (string) ($sent['messages'][1]['content'] ?? '');
xp(mb_strlen($sys) <= 9000 && str_contains($sys, 'regel'), 'systemtexten kapas vid 9000, fick ' . (string) mb_strlen($sys));
xp(str_contains($user, 'Behov: batteri hemma') && !str_contains($user, '%PDF-'), 'användartexten behåller behovet');
xp((string) ($sent['model'] ?? '') === 'grok-test' || (string) ($GLOBALS['_JEAB_AUTO_LLM_LAST']['model'] ?? '') !== '', 'modellen finns i anropet');
$transport = is_array($GLOBALS['_JEAB_AUTO_LLM_TRANSPORT'] ?? null) ? $GLOBALS['_JEAB_AUTO_LLM_TRANSPORT'] : [];
xp((int) ($transport['curl_errno'] ?? 0) === 28 && (int) ($transport['http'] ?? -1) === 0, 'transporten har errno 28');
$log = (string) @file_get_contents($tmp . '/app-errors.jsonl');
$row = json_decode(trim(strtok($log, "\n") ?: ''), true);
xp(is_array($row) && (int) ($row['curl_errno'] ?? 0) === 28, 'felloggen sparar curl errno');
$fp = (string) ($row['fingerprint'] ?? '');
$gotFp = jeab_fellogg_get(['fingerprint' => $fp]);
xp((int) ($gotFp['curl_errno'] ?? 0) === 28 && str_contains((string) ($gotFp['curl_error'] ?? ''), 'timed out'), 'fellogg.get visar curl errno och feltext');
xp(!str_contains($log, $key), 'felloggen har inte nyckeln');

$calls = 0;
$_SERVER['REQUEST_METHOD'] = 'GET';
$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
unset($GLOBALS['_JEAB_EXTERNAL_ALLOW'], $GLOBALS['_JEAB_TICK']);
xp(jeab_page_blocks_external(), 'GET-sidvisning blockerar externa anrop');
xp(jeab_auto_llm_page_blocked('http://127.0.0.1:9/v1/chat/completions'), 'en annan värd stoppas');
xp(!jeab_auto_llm_page_blocked('https://api.x.ai/v1/chat/completions'), 'api.x.ai är tillåten');
@unlink($tmp . '/app-errors.jsonl');
$blocked = jeab_auto_llm_exchange([['role' => 'user', 'content' => 'hej']]);
xp($calls === 0 && ($blocked['error'] ?? '') === 'AI-fel: HTTP 0', 'sidskyddet anropar inte curl');
$blockedLog = (string) @file_get_contents($tmp . '/app-errors.jsonl');
xp(str_contains($blockedLog, 'blocked=1') && str_contains($blockedLog, 'ms=0') && str_contains($blockedLog, 'curl_errno=0'), 'spärren loggas som blocked=1 ms=0');
$blockedTransport = is_array($GLOBALS['_JEAB_AUTO_LLM_TRANSPORT'] ?? null) ? $GLOBALS['_JEAB_AUTO_LLM_TRANSPORT'] : [];
xp((int) ($blockedTransport['blocked'] ?? 0) === 1 && (int) ($blockedTransport['elapsed_ms'] ?? -1) === 0 && (int) ($blockedTransport['curl_errno'] ?? -1) === 0, 'transporten säger blocked=1 och ms=0');

$writeLlm('https://api.x.ai/v1/chat/completions');
$calls = 0;
$allowed = jeab_auto_llm_exchange([['role' => 'user', 'content' => 'tillåten']]);
xp($calls === 1 && ($allowed['error'] ?? '') === 'AI-fel: HTTP 0', 'api.x.ai går igenom sidskyddet');
xp((int) (($GLOBALS['_JEAB_AUTO_LLM_TRANSPORT']['blocked'] ?? 1)) === 0, 'tillåten värd är inte blocked');

$_SERVER['REQUEST_METHOD'] = 'POST';
xp(!jeab_page_blocks_external(), 'POST släpper Graph och xAI');
$writeLlm('http://127.0.0.1:9/v1/chat/completions');
$calls = 0;
jeab_auto_llm_exchange([['role' => 'user', 'content' => 'post']]);
xp($calls === 1, 'POST anropar även en annan värd');
unset($GLOBALS['_JEAB_PAGE_REQUEST']);
$_SERVER['REQUEST_METHOD'] = 'POST';

$missing = jeab_inbox_xai_probe(['thread' => 'saknas-helt']);
xp(($missing['ok'] ?? true) === false && (int) ($missing['sent'] ?? 1) === 0 && ($missing['read_only'] ?? false) === true, 'okänd tråd skickar inget');

$srcAuto = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg_auto.php');
$srcHook = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg.php');
xp(str_contains($srcHook, "'inbox.xai_probe'") && str_contains($srcAuto, 'function jeab_inbox_xai_probe'), 'hooken inbox.xai_probe finns');
xp(str_contains($srcAuto, "=== 'svarsregler saknas'") && str_contains($srcAuto, 'return $hold($err)'), 'saknade svarsregler håller fortfarande');

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_SENT'][] = $a;

    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'c', 'message_id' => 'm@x'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static fn (): array => ['code' => 200, 'body' => ['value' => []]];
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode("%PDF-1.4 probe\n")]);
require __DIR__ . '/quote_notiser_seed.php';
jeab_test_seed_quote_notiser();
if (function_exists('jeab_lead_sql')) {
    jeab_lead_sql();
}

$in = jeab_inkorg_ingest([
    'message_id' => 'probe-ai@probe.test',
    'from' => 'info@wix-forms.com',
    'from_name' => 'Formulär',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Offert',
    'body_text' => "Namn: Fejk Probe\nE-post: fejk.probe@gmail.com\nTelefon: 070-000 00 24\nAdress: Fejkgatan 5, 176 70 Järfälla\nProdukt: Batteri\nHur många kWh batteri behöver villan?\n",
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'thread_key' => 'probe-ai',
    'preset_labels' => [
        'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
        'intent' => 'kop', 'offer' => 'batteri', 'product' => 'batteri', 'question' => '',
    ],
]);
$tid = (string) ($in['thread_id'] ?? '');
xp($tid !== '', 'tråden finns');
$sentBefore = count($GLOBALS['_SENT']);
$calls = 0;
$probe = jeab_inbox_xai_probe(['thread' => $tid]);
xp((int) ($probe['sent'] ?? 1) === 0 && ($probe['read_only'] ?? false) === true && ($probe['ok'] ?? false) === true, 'proben skickar inget');
xp(count($GLOBALS['_SENT']) === $sentBefore, 'proben rör inte Graph');
xp((int) ($probe['chars'] ?? 0) > 0 && (string) ($probe['model'] ?? '') === 'grok-4.7', 'proben visar storlek och modell, fick ' . (string) ($probe['model'] ?? '') . ' chars ' . (string) ($probe['chars'] ?? 0));
xp((int) ($probe['http'] ?? -1) === 0 && (int) ($probe['curl_errno'] ?? -1) === 28, 'proben visar HTTP 0 och errno');
xp(str_contains((string) ($probe['ai_notice'] ?? ''), 'Mallen används utan det korta svaret'), 'proben säger att mallen används');
xp(!str_contains(json_encode($probe, JSON_UNESCAPED_UNICODE) ?: '', '@'), 'proben har ingen adress');

$dry = jeab_auto_send_quote(['thread_id' => $tid, 'dry' => '1']);
xp(($dry['ok'] ?? false) === true && ($dry['action'] ?? '') !== 'hold', 'torr offert håller inte på AI-fel, fick ' . (string) ($dry['action'] ?? '') . ' ' . (string) ($dry['why'] ?? ''));
xp(str_contains((string) ($dry['ai_notice'] ?? ''), 'AI-anropet misslyckades') && str_contains((string) ($dry['ai_notice'] ?? ''), 'curl errno 28'), 'den torra offerten har en tydlig text');
xp(!str_contains((string) ($dry['body'] ?? ''), 'Storlek och utformning anpassar vi'), 'mallen har inte den säkra AI-raden');
xp(count($GLOBALS['_SENT']) === $sentBefore, 'torr offert skickar inget');

$_POST = ['action' => 'inkorg.send_quote', 'thread' => $tid, 'dry' => '1'];
$page = jeab_inkorg_action('inkorg.send_quote');
xp(str_starts_with($page, 'Förhandsvisning klar. Inget skickat.') && str_contains($page, 'Mallen används utan det korta svaret'), 'sidan visar förhandsvisningen och AI-texten, fick ' . $page);
require_once dirname(__DIR__) . '/src/layout.php';
xp(jeab_flash_text($page) === $page, 'flashen släpper igenom meddelandet');

$GLOBALS['_JEAB_SAPI'] = 'cli';
unset($GLOBALS['_JEAB_AUTO_QUOTE_BUDGET_START']);
$_POST = ['action' => 'inkorg.send_quote', 'thread' => $tid, 'dry' => '0'];
$livePage = jeab_inkorg_action('inkorg.send_quote');
xp(str_starts_with($livePage, 'Offerten skickad.') && str_contains($livePage, 'curl errno 28'), 'sidan säger att offerten gick och varför AI uteblev, fick ' . $livePage);
xp(count($GLOBALS['_SENT']) === $sentBefore + 1, 'ett utskick gjordes');
$notes = '';
foreach (jeab_inkorg_threads() as $t) {
    if ((string) ($t['id'] ?? '') !== $tid) {
        continue;
    }
    foreach ((array) ($t['drafts'] ?? []) as $d) {
        if (($d['kind'] ?? '') === 'note') {
            $notes .= (string) ($d['body'] ?? '') . "\n";
        }
    }
}
xp(str_contains($notes, 'Mallen används utan det korta svaret') && !str_contains($notes, 'Med kort svar på frågan'), 'anteckningen säger att mallen gick utan AI-text');
xp(str_starts_with(jeab_flash_text($livePage) ?? '', 'Offerten skickad.'), 'flashen behåller Offerten skickad');

echo "inkorg xai probe: ok\n";
