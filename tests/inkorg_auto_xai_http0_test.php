<?php

declare(strict_types=1);

/**
 * xAI-anrop som inte får något HTTP-svar loggar curl-errno och meddelande.
 * Svarets innehåll och nyckeln loggas inte. Inget skickas.
 */

$tmp = sys_get_temp_dir() . '/jeab-xai-http0-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp . '/public';
mkdir($GLOBALS['_JEAB_PUBLIC_DATA_DIR'], 0775, true);
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
$key = 'xai-test-key-' . bin2hex(random_bytes(4));
file_put_contents($tmp . '/secrets/inkorg-llm.json', json_encode([
    'key' => $key,
    'url' => 'http://127.0.0.1:9/v1/chat/completions',
    'model' => 'grok-test',
]));
require dirname(__DIR__) . '/src/bootstrap.php';

function xai0(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$src = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg_auto.php');
xai0(str_contains($src, 'curl_errno') && str_contains($src, 'curl_error'), 'anropet läser curl-fel');
xai0(str_contains($src, "'no_webhook' => true"), 'HTTP 0 skickar ingen avisering');

$got = jeab_auto_llm_exchange([['role' => 'user', 'content' => 'hej']]);
xai0(($got['json'] ?? null) === null && ($got['error'] ?? '') === 'AI-fel: HTTP 0', 'svaret är bara HTTP 0');
xai0(!str_contains((string) ($got['error'] ?? ''), $key), 'felet innehåller inte nyckeln');

$log = (string) @file_get_contents($tmp . '/app-errors.jsonl');
xai0($log !== '' && str_contains($log, 'xAI HTTP 0') && str_contains($log, 'errno '), 'felloggen har errno');
xai0(!str_contains($log, $key), 'felloggen har inte nyckeln');
xai0(!str_contains($log, 'chat/completions') || !str_contains($log, '"content"'), 'felloggen har inte svarets innehåll');
$decoded = json_decode(trim(strtok($log, "\n") ?: ''), true);
xai0(is_array($decoded) && str_contains((string) ($decoded['message'] ?? ''), 'errno '), 'meddelandet har errno');
xai0(preg_match('/errno\s+\d+/', (string) ($decoded['message'] ?? '')) === 1, 'errno är ett tal');
xai0(strlen((string) ($decoded['message'] ?? '')) > strlen('xAI HTTP 0 errno 0'), 'meddelandet har curl-text');

echo "inkorg xai http 0: ok\n";
