<?php

declare(strict_types=1);

/**
 * Webbens offert: AI högst 15 s, anslutning 5 s, prompt högst 4000 tecken,
 * reasoning_effort low. Timeout använder mallen. Dubblettspärren är orörd.
 * Inget skickas.
 */

$tmp = sys_get_temp_dir() . '/jeab-quote-web-budget-' . bin2hex(random_bytes(4));
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
file_put_contents($tmp . '/secrets/inkorg-llm.json', json_encode([
    'key' => 'xai-web-budget-key',
    'url' => 'http://127.0.0.1:9/v1/chat/completions',
    'model' => 'grok-test',
]));
require dirname(__DIR__) . '/src/bootstrap.php';

function wb(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$src = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg_auto.php');
wb(substr_count($src, "return ['action' => 'blocked_duplicate', 'why' => 'blocked_duplicate', 'sent' => 0];") >= 2, 'dubblettspärren är kvar');
wb(str_contains($src, "str_starts_with(\$err, 'AI-fel')"), 'AI-fel lämnar det korta svaret tomt');
$graphSrc = (string) file_get_contents(dirname(__DIR__) . '/src/graph_person.php');
wb(str_contains($graphSrc, 'jeab_auto_quote_web_remaining_seconds() < 2'), 'Skickat-uppslaget stannar när offertbudgeten är slut');

$GLOBALS['_JEAB_SAPI'] = 'cli';
unset($GLOBALS['_JEAB_AUTO_QUOTE_BUDGET_START']);
wb(jeab_auto_llm_curl_timeout() === 24 && jeab_auto_llm_curl_connect_timeout() === 10, 'cron väntar 24 s och ansluter på 10 s');
wb((jeab_auto_answer_llm_opts()['reasoning_effort'] ?? '') === 'medium', 'cron använder reasoning_effort medium');

$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
unset($GLOBALS['_JEAB_AUTO_QUOTE_BUDGET_START']);
wb(jeab_auto_llm_is_web_request(), 'php-fpm är ett webbanrop');
wb(jeab_auto_llm_curl_timeout() === 15, 'webben väntar 15 s');
wb(jeab_auto_llm_curl_connect_timeout() === 5, 'webben ansluter på 5 s');
wb(jeab_auto_llm_max_attempts() === 1, 'webben försöker en gång');
wb(jeab_auto_quote_web_budget_seconds() === 30, 'offertbudgeten är 30 s');
wb(jeab_auto_llm_curl_timeout() * jeab_auto_llm_max_attempts() < jeab_auto_quote_web_budget_seconds(), 'AI-anropet ryms under 30 s');
wb((jeab_auto_answer_llm_opts()['reasoning_effort'] ?? '') === 'low', 'webben använder reasoning_effort low');
wb((jeab_auto_answer_llm_opts()['model'] ?? '') === 'grok-4.7', 'modellen är grok-4.7');

jeab_auto_quote_begin_web_budget();
wb(jeab_auto_quote_web_budget_active() && jeab_auto_llm_curl_timeout() === 15, 'en ny offert har 15 s kvar åt AI');
$GLOBALS['_JEAB_AUTO_QUOTE_BUDGET_START'] = hrtime(true) - 20_000_000_000;
$left = jeab_auto_quote_web_remaining_seconds();
wb($left >= 9 && $left <= 10 && jeab_auto_llm_curl_timeout() === $left, 'AI kortas till det som återstår av 30 s, fick ' . $left);
$GLOBALS['_JEAB_AUTO_QUOTE_BUDGET_START'] = hrtime(true) - 40_000_000_000;
wb(jeab_auto_quote_web_remaining_seconds() === 0 && jeab_auto_llm_curl_timeout() === 1, 'en slut budget ger inte curl 0, fick ' . (string) jeab_auto_quote_web_remaining_seconds());

$calls = 0;
$seen = '';
$GLOBALS['_JEAB_AUTO_LLM_HTTP'] = static function (string $url, string $body) use (&$calls, &$seen): array {
    $calls++;
    $seen = $body;
    wb(!str_contains($body, 'xai-web-budget-key') && !str_contains($url, 'xai-web-budget-key'), 'nyckeln följer inte med');

    return ['status' => 0, 'errno' => 28, 'error' => 'Operation timed out after 15001 milliseconds', 'body' => false];
};

unset($GLOBALS['_JEAB_AUTO_QUOTE_BUDGET_START']);
$question = 'Hur många kWh batteri behöver villan?';
$facts = str_repeat("Mallrad. Pris 94 000 kr efter grön teknik.\n", 500);
$body = str_repeat("Behov: batteri till villan.\n", 400);
$got = jeab_auto_answer($question, $facts, $body, 'jarfalla', 'batteri');
wb($calls === 1, 'webben anropar en gång, fick ' . $calls);
wb(mb_strlen($seen) <= 4000, 'prompten är högst 4000 tecken, fick ' . (string) mb_strlen($seen));
wb(mb_strlen($seen) > 400, 'prompten är inte tom, fick ' . (string) mb_strlen($seen));
$sent = json_decode($seen, true);
wb(is_array($sent), 'prompten är JSON');
wb((string) ($sent['model'] ?? '') === 'grok-4.7', 'anropet ber om grok-4.7');
wb((string) ($sent['reasoning_effort'] ?? '') === 'low', 'anropet ber om reasoning_effort low');
wb(!isset($sent['fit_prompt']), 'fit_prompt skickas inte till xAI');
$user = (string) ($sent['messages'][1]['content'] ?? '');
$system = (string) ($sent['messages'][0]['content'] ?? '');
wb(str_contains($user, 'Hur många kWh batteri'), 'frågan är kvar i prompten');
wb(str_contains($system, 'Svarsregler'), 'svarsreglerna börjar prompten');
wb(($got['answer'] ?? 'x') === '' && str_starts_with((string) ($got['error'] ?? ''), 'AI-fel'), 'timeout ger inget kort svar, fick ' . (string) ($got['error'] ?? ''));
$notice = jeab_auto_llm_failure_notice((string) $got['error']);
wb(str_contains($notice, 'Mallen används utan det korta svaret') && str_contains($notice, 'curl errno 28'), 'texten säger att mallen används, fick ' . $notice);
wb((int) (($GLOBALS['_JEAB_AUTO_LLM_TRANSPORT']['chars'] ?? 0)) === mb_strlen($seen) && (int) (($GLOBALS['_JEAB_AUTO_LLM_TRANSPORT']['chars'] ?? 0)) <= 4000, 'transportens tecken är den korta prompten');

$GLOBALS['_JEAB_SAPI'] = 'cli';
unset($GLOBALS['_JEAB_AUTO_QUOTE_BUDGET_START']);
$calls = 0;
$cliSeen = '';
$GLOBALS['_JEAB_AUTO_LLM_HTTP'] = static function (string $url, string $body) use (&$calls, &$cliSeen): array {
    $calls++;
    $cliSeen = $body;

    return ['status' => 200, 'errno' => 0, 'error' => '', 'body' => json_encode([
        'choices' => [['message' => ['content' => '{"answer":""}']]],
    ], JSON_UNESCAPED_UNICODE)];
};
jeab_auto_answer($question, $facts, $body, 'jarfalla', 'batteri');
$cli = json_decode($cliSeen, true);
wb(is_array($cli) && mb_strlen($cliSeen) > 4000, 'cron klipper inte prompten till 4000, fick ' . (string) mb_strlen($cliSeen));
wb((string) ($cli['reasoning_effort'] ?? '') === 'medium', 'cron skickar reasoning_effort medium');

$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
$GLOBALS['_JEAB_AUTO_QUOTE_BUDGET_START'] = hrtime(true) - 40_000_000_000;
$graphCalls = 0;
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function () use (&$graphCalls): array {
    $graphCalls++;

    return ['code' => 200, 'body' => ['value' => []]];
};
$blocked = jeab_graph_http('GET', 'https://graph.microsoft.com/v1.0/me/messages');
wb($graphCalls === 0 && (int) ($blocked['curl_errno'] ?? 0) === 28 && (string) ($blocked['curl_error'] ?? '') === 'quote budget', 'slut offertbudget anropar inte Graph');
$aiCalls = 0;
$GLOBALS['_JEAB_AUTO_LLM_HTTP'] = static function () use (&$aiCalls): array {
    $aiCalls++;

    return ['status' => 200, 'errno' => 0, 'error' => '', 'body' => ''];
};
$spent = jeab_auto_llm_exchange([['role' => 'user', 'content' => 'hej']], ['model' => 'grok-4.7', 'reasoning_effort' => 'low']);
wb($aiCalls === 0 && str_starts_with((string) ($spent['error'] ?? ''), 'AI-fel'), 'slut offertbudget anropar inte xAI');

echo "inkorg quote web budget: ok\n";
