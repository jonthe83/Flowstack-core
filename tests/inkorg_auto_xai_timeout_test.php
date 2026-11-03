<?php

declare(strict_types=1);

/**
 * Webb: 15 s och anslutning 5 s, inget omförsök. Hela offerten ryms under 30 s.
 * CLI/cron: högst ett omförsök, två försök under 50 s.
 * Lång leadtext klipps. errno syns under inkorg.xai. Inget skickas.
 */

$tmp = sys_get_temp_dir() . '/jeab-xai-timeout-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp . '/public';
mkdir($GLOBALS['_JEAB_PUBLIC_DATA_DIR'], 0775, true);
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
$key = 'xai-timeout-key-' . bin2hex(random_bytes(4));
file_put_contents($tmp . '/secrets/inkorg-llm.json', json_encode([
    'key' => $key,
    'url' => 'http://127.0.0.1:9/v1/chat/completions',
    'model' => 'grok-test',
]));
require dirname(__DIR__) . '/src/bootstrap.php';

function xt(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$GLOBALS['_JEAB_SAPI'] = 'cli';
xt(jeab_auto_llm_is_web_request() === false, 'CLI är inte ett webbanrop');
xt(jeab_auto_llm_curl_timeout() === 24, 'CLI väntar 24 s per försök');
xt(jeab_auto_llm_max_attempts() === 2, 'CLI får högst ett omförsök');
xt(jeab_auto_llm_curl_timeout() * jeab_auto_llm_max_attempts() < 50, 'två CLI-försök stannar under 50 s');
xt(jeab_auto_llm_curl_connect_timeout() === 10, 'anslutningen väntar 10 s');
$src = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg_auto.php');
xt(str_contains($src, '$limit = $timeout > 0 ? $timeout : jeab_auto_llm_curl_timeout()') && str_contains($src, 'CURLOPT_TIMEOUT => $limit'), 'curl använder tidsgränsen');
xt(str_contains($src, 'CURLOPT_CONNECTTIMEOUT => jeab_auto_llm_curl_connect_timeout()'), 'curl använder anslutningens tidsgräns');
xt(jeab_auto_llm_transport_timed_out(28, '', 0) === true, 'errno 28 är en timeout');
xt(jeab_auto_llm_transport_timed_out(7, 'Connection refused', 0) === false, 'nekad anslutning försöks inte om');

$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
xt(jeab_auto_llm_is_web_request() === true, 'php-fpm är ett webbanrop');
xt(jeab_auto_llm_curl_timeout() === 15, 'webben väntar 15 s');
xt(jeab_auto_llm_max_attempts() === 1, 'webben försöker inte om');
xt(jeab_auto_llm_curl_connect_timeout() === 5, 'webben ansluter på 5 s');
xt(jeab_auto_quote_web_budget_seconds() === 30 && jeab_auto_llm_curl_timeout() < jeab_auto_quote_web_budget_seconds(), 'AI-anropet ryms i offertens 30 s');

$calls = 0;
$script = [];
$GLOBALS['_JEAB_AUTO_LLM_HTTP'] = static function () use (&$calls, &$script): array {
    $calls++;
    $step = $script[$calls - 1] ?? ['status' => 0, 'errno' => 7, 'error' => 'Connection refused', 'body' => false];

    return $step;
};
$okBody = json_encode([
    'choices' => [['message' => ['content' => '{"answer":"ja"}']]],
], JSON_UNESCAPED_UNICODE);

$curlError = 'Operation timed out after 45001 milliseconds';
$script = [
    ['status' => 0, 'errno' => 28, 'error' => $curlError, 'body' => false],
    ['status' => 200, 'errno' => 0, 'error' => '', 'body' => $okBody],
];
$calls = 0;
@unlink($tmp . '/app-errors.jsonl');
$webFail = jeab_auto_llm_exchange([['role' => 'user', 'content' => 'webb']]);
xt($calls === 1 && ($webFail['error'] ?? '') === 'AI-fel: HTTP 0', 'webbens timeout försöks inte om, fick ' . $calls);
$webLog = (string) @file_get_contents($tmp . '/app-errors.jsonl');
xt(str_contains($webLog, '"surface":"inkorg.xai"') && str_contains($webLog, 'errno 28') && str_contains($webLog, $curlError) && !str_contains($webLog, 'försök 2'), 'webben loggar errno utan omförsök');

$GLOBALS['_JEAB_SAPI'] = 'cli';
$script = [
    ['status' => 0, 'errno' => 28, 'error' => 'Operation timed out', 'body' => false],
    ['status' => 200, 'errno' => 0, 'error' => '', 'body' => $okBody],
];
$calls = 0;
@unlink($tmp . '/app-errors.jsonl');
$recovered = jeab_auto_llm_exchange([['role' => 'user', 'content' => 'långt lead']]);
xt($calls === 2 && ($recovered['error'] ?? 'x') === '' && ($recovered['json']['answer'] ?? '') === 'ja', 'CLI försöker en timeout en gång till, fick ' . $calls . ' ' . (string) ($recovered['error'] ?? ''));
xt(!is_file($tmp . '/app-errors.jsonl') || !str_contains((string) @file_get_contents($tmp . '/app-errors.jsonl'), 'inkorg.xai'), 'lyckat omförsök loggas inte som HTTP 0');

$script = [
    ['status' => 0, 'errno' => 7, 'error' => 'Connection refused', 'body' => false],
    ['status' => 200, 'errno' => 0, 'error' => '', 'body' => $okBody],
];
$calls = 0;
$refused = jeab_auto_llm_exchange([['role' => 'user', 'content' => 'kort']]);
xt($calls === 1 && ($refused['error'] ?? '') === 'AI-fel: HTTP 0', 'annan curl-felkod försöks inte om, fick ' . $calls);

$cliError = 'Operation timed out after 24002 milliseconds';
$script = [
    ['status' => 0, 'errno' => 28, 'error' => $cliError, 'body' => false],
    ['status' => 0, 'errno' => 28, 'error' => $cliError, 'body' => false],
    ['status' => 200, 'errno' => 0, 'error' => '', 'body' => $okBody],
];
$calls = 0;
@unlink($tmp . '/app-errors.jsonl');
$failed = jeab_auto_llm_exchange([['role' => 'user', 'content' => 'Markus']]);
xt($calls === 2 && ($failed['error'] ?? '') === 'AI-fel: HTTP 0', 'två CLI-timeouts ger HTTP 0');
xt(!str_contains((string) ($failed['error'] ?? ''), $key), 'svaret innehåller inte nyckeln');
$log = (string) @file_get_contents($tmp . '/app-errors.jsonl');
xt(str_contains($log, '"surface":"inkorg.xai"') && str_contains($log, 'errno 28') && str_contains($log, $cliError) && str_contains($log, 'försök 2'), 'felloggen har yta, errno och curl-text');
xt(!str_contains($log, $key), 'felloggen har inte nyckeln');
$rows = jeab_app_errors_read(20);
$hit = null;
foreach ($rows as $row) {
    if (is_array($row) && (string) ($row['surface'] ?? '') === 'inkorg.xai') {
        $hit = $row;
    }
}
xt(is_array($hit), 'felloggens statistik har inkorg.xai');
xt((int) ($hit['curl_errno'] ?? 0) === 28 && str_contains((string) ($hit['curl_error'] ?? ''), 'Operation timed out'), 'statistiken har curl errno och feltext, fick ' . (string) ($hit['curl_error'] ?? ''));
xt((int) ($hit['count'] ?? 0) >= 1 && str_contains((string) ($hit['message'] ?? ''), 'errno 28'), 'raden räknas och meddelandet har errno');
$stats = jeab_app_errors_stats($rows);
xt((int) ($stats['count'] ?? 0) >= 1, 'felloggens summering räknar raden');
$entries = $rows;
$html = include dirname(__DIR__) . '/src/pages/logg.php';
xt(is_string($html) && str_contains($html, 'inkorg.xai') && str_contains($html, 'curl 28') && str_contains($html, 'Operation timed out'), 'felloggsidan visar yta och curl-fel');
xt(!str_contains((string) $html, $key), 'sidan visar inte nyckeln');

$cap = jeab_auto_llm_body_cap();
xt($cap === 4000, 'leadtexten kapas vid 4000 tecken');
$long = "LEADSTART\n" . str_repeat("x\n", 5000) . "Behov: sol och batteri\nE-post: markus@example.com\n";
$trimmed = jeab_inkorg_llm_lead_text(['body' => $long]);
xt(mb_strlen($trimmed) <= $cap, 'den klippta texten ryms i taket, fick ' . (string) mb_strlen($trimmed));
xt(str_starts_with($trimmed, 'LEADSTART') && str_contains($trimmed, 'Behov: sol och batteri') && str_contains($trimmed, 'E-post: markus@example.com'), 'början och nyckelfälten är kvar');
$short = jeab_inkorg_llm_lead_text(['body' => "Hej\nBehov: batteri"]);
xt($short === "Hej\nBehov: batteri", 'kort text lämnas hel');

$calls = 0;
$script = [
    ['status' => 200, 'errno' => 0, 'error' => '', 'body' => $okBody],
];
$huge = "FRÅGA-START " . str_repeat('ord ', 3000) . "\nBehov: sol+batteri till villan\n";
jeab_auto_llm_exchange([['role' => 'system', 'content' => str_repeat('regel ', 900)], ['role' => 'user', 'content' => $huge]]);
$sentUser = (string) (($GLOBALS['_JEAB_AUTO_LLM_LAST']['messages'][1]['content'] ?? ''));
$sentSystem = (string) (($GLOBALS['_JEAB_AUTO_LLM_LAST']['messages'][0]['content'] ?? ''));
xt(mb_strlen($sentUser) <= $cap && str_contains($sentUser, 'FRÅGA-START') && str_contains($sentUser, 'Behov: sol+batteri till villan'), 'anropet skickar en kort användartext med behovet');
xt(str_contains($sentSystem, 'regel') && mb_strlen($sentSystem) > $cap, 'svarsreglerna klipps inte');

echo "inkorg xai timeout: ok\n";
