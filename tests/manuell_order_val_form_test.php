<?php

declare(strict_types=1);

/**
 * Kontorsväljaren på manuell order är method="get".
 * Webbläsaren slänger action-queryn, så p måste ligga som dolt fält.
 * Kollas med curl mot php -S. Ingen kunddata.
 */

function mov_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function mov_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    mov_fail($message);
}

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-mov-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    mov_fail('temp');
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('JEAB_SHELL_TMP=' . $tmp);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
file_put_contents($tmp . '/store.json', "{}\n");
$hash = password_hash('mov-test-kod', PASSWORD_DEFAULT);
file_put_contents($tmp . '/pw.json', json_encode([
    'secret' => 'mov-test-secret',
    'hashes' => [
        ['hash' => $hash, 'email' => 'mov@example.test'],
    ],
], JSON_UNESCAPED_UNICODE));
putenv('JEAB_PASSWORDS_FILE=' . $tmp . '/pw.json');

require $root . '/src/bootstrap.php';
require $root . '/src/layout.php';

jeab_auth_data(true);
$cookie = jeab_auth_issue_value('mov@example.test', $hash);
mov_ok(is_string($cookie) && $cookie !== '', 'testkaka utfärdad');

$router = $tmp . '/router.php';
file_put_contents($router, <<<PHP
<?php
\$tmp = getenv('JEAB_SHELL_TMP');
\$GLOBALS['_JEAB_DATA_DIR'] = \$tmp;
putenv('JEAB_PASSWORDS_FILE=' . \$tmp . '/pw.json');
putenv('JEAB_DISABLE_SQLITE=1');
\$uri = (string) (parse_url((string) (\$_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
if (\$uri === '/index.php' || \$uri === '/') {
    require '{$root}/index.php';
    return true;
}
return false;
PHP);

$port = 18000 + (getmypid() % 20000);
require_once __DIR__ . '/jeab_test_proc_env.php';
jeab_test_router_boot($router);
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $root, $router],
    [1 => ['file', $tmp . '/server.out', 'w'], 2 => ['file', $tmp . '/server.err', 'w']],
    $pipes,
    $root
);
mov_ok(is_resource($server), 'php -S startade');
register_shutdown_function(static function () use ($server): void {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
});

function mov_curl(string $url, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_error($ch);
    curl_close($ch);
    if (!is_string($raw)) {
        return [0, '', $err];
    }
    return [$status, substr($raw, 0, $headerSize), substr($raw, $headerSize)];
}

$base = 'http://127.0.0.1:' . $port;
$auth = [
    'Cookie: jeab_auth=' . rawurlencode((string) $cookie),
    'X-Jeab-Partial: 1',
    'Accept: text/html, application/json',
];
$deadline = microtime(true) + 8;
$ready = [0, '', ''];
do {
    $ready = mov_curl($base . '/index.php?p=manuell-order&co=jarfalla', $auth);
    if ($ready[0] === 200 && $ready[2] !== '') {
        break;
    }
    usleep(80000);
} while (microtime(true) < $deadline);

/**
 * @return array{0: string, 1: string, 2: list<array{tag: string, name: string, type: string, value: string}>}
 */
function mov_rot_val(string $body): array
{
    $decoded = json_decode($body, true);
    $html = is_array($decoded) ? (string) ($decoded['html'] ?? '') : '';
    if (preg_match('/<form class="rot-val" method="(get)" action="([^"]*)">(.*?)<\/form>/s', $html, $form) !== 1) {
        return ['', '', []];
    }
    $inputs = [];
    if (preg_match_all('/<([a-z0-9]+)(\s[^>]*)?>/i', $form[3], $tags, PREG_SET_ORDER)) {
        foreach ($tags as $tag) {
            $attrs = $tag[2] ?? '';
            $name = '';
            $type = '';
            $value = '';
            if (preg_match('/\bname="([^"]*)"/', $attrs, $hit) === 1) {
                $name = html_entity_decode($hit[1], ENT_QUOTES, 'UTF-8');
            }
            if (preg_match('/\btype="([^"]*)"/', $attrs, $hit) === 1) {
                $type = strtolower($hit[1]);
            }
            if (preg_match('/\bvalue="([^"]*)"/', $attrs, $hit) === 1) {
                $value = html_entity_decode($hit[1], ENT_QUOTES, 'UTF-8');
            }
            $inputs[] = [
                'tag' => strtolower($tag[1]),
                'name' => $name,
                'type' => $type,
                'value' => $value,
            ];
        }
    }
    return [$form[1], html_entity_decode($form[2], ENT_QUOTES, 'UTF-8'), $inputs];
}

mov_ok($ready[0] === 200, 'curl manuell-order svarar 200');
[$method, $action, $inputs] = mov_rot_val($ready[2]);
mov_ok($method === 'get', 'rot-val är method get');
mov_ok(str_contains($action, 'p=manuell-order'), 'action behåller p=manuell-order');
mov_ok(
    ($inputs[0]['tag'] ?? '') === 'input'
        && ($inputs[0]['type'] ?? '') === 'hidden'
        && ($inputs[0]['name'] ?? '') === 'p'
        && ($inputs[0]['value'] ?? '') === 'manuell-order',
    'första barnet är dolt p=manuell-order'
);

$withQ = mov_curl($base . '/index.php?p=manuell-order&co=jarfalla&q=' . rawurlencode('Storgatan 1'), $auth);
mov_ok($withQ[0] === 200, 'curl med q svarar 200');
[, , $qInputs] = mov_rot_val($withQ[2]);
mov_ok(
    ($qInputs[0]['name'] ?? '') === 'p'
        && ($qInputs[0]['value'] ?? '') === 'manuell-order'
        && ($qInputs[1]['name'] ?? '') === 'q'
        && ($qInputs[1]['value'] ?? '') === 'Storgatan 1',
    'p ligger före q'
);

echo "OK  manuell order val-form\n";
