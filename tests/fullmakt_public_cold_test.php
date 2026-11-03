<?php

declare(strict_types=1);

/**
 * Kundlänk utan jeab_auth. Webb-SAPI, tom kaka, lazy boot.
 * SQL-rad utan JSON-fil ska ge signeringsformuläret, inte 404.
 * Påhittade uppgifter. Inget personnummer.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-fullmakt-public-cold-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
mkdir($tmp . '/fullmakt', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_STEG4_CUTOVER'] = true;
$GLOBALS['_JEAB_DATA_BACKEND'] = 'mysql';
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
putenv('FULLMAKT_DATA=' . $tmp . '/fullmakt');
putenv('FULLMAKT_PUBLIC_URL=https://flowstack.example.test/fullmakt');
$_ENV['DATA_BACKEND'] = 'mysql';
$_ENV['FULLMAKT_DATA'] = $tmp . '/fullmakt';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
file_put_contents($tmp . '/inkorg/messages.json', '{"messages":[]}');
file_put_contents($tmp . '/inkorg/threads.json', '{"threads":{}}');

require $root . '/src/bootstrap.php';
require $root . '/fullmakt/lib/bootstrap.php';
require $root . '/fullmakt/lib/store.php';
fullmakt_boot();

function fpc(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$pdo = jeab_data_pdo();
fpc($pdo instanceof PDO && fullmakt_sql_active(), 'SQL-vägen är aktiv i testprocessen');

$created = fullmakt_create_doc([
    'type' => 'ellevio',
    'typ' => 'privatperson',
    'namn' => 'Kall Kund',
    'epost' => 'kall.kund@example.test',
    'telefon' => '0701000077',
    'adress' => 'Testvägen 2',
    'postnummer' => '176 74',
    'ort' => 'Järfälla',
    'anlaggningsadress' => 'Testvägen 2',
    'anlaggnings_id' => '',
    'order_id' => '81',
]);
$token = (string) ($created['token'] ?? '');
fpc($token !== '' && !is_file(fullmakt_pending_path($token)), 'dokumentet ligger bara i SQL');

/**
 * @return array{code:int,out:string,err:string}
 */
function fpc_hit(string $root, string $tmp, string $token): array
{
    $statusFile = $tmp . '/status.txt';
    $runner = $tmp . '/hit.php';
    $php = '<?php
declare(strict_types=1);
$GLOBALS["_JEAB_DATA_DIR"] = ' . var_export($tmp, true) . ';
$GLOBALS["_JEAB_PUBLIC_DATA_DIR"] = ' . var_export($tmp, true) . ';
$GLOBALS["_JEAB_STEG4_CUTOVER"] = true;
$GLOBALS["_JEAB_DATA_BACKEND"] = "mysql";
$GLOBALS["_JEAB_SAPI"] = "fpm-fcgi";
$GLOBALS["_JEAB_BOOT_LAZY"] = true;
unset($GLOBALS["_JEAB_BOOT_ALL"]);
$_COOKIE = [];
putenv("JEAB_DISABLE_SQLITE=0");
putenv("DATA_BACKEND=mysql");
putenv("FULLMAKT_DATA=" . ' . var_export($tmp . '/fullmakt', true) . ');
putenv("FULLMAKT_PUBLIC_URL=https://flowstack.example.test/fullmakt");
$_ENV["DATA_BACKEND"] = "mysql";
$_ENV["FULLMAKT_DATA"] = ' . var_export($tmp . '/fullmakt', true) . ';
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["HTTP_HOST"] = "flowstack.example.test";
$_SERVER["HTTPS"] = "on";
$_SERVER["SERVER_PORT"] = "443";
$_SERVER["REQUEST_URI"] = ' . var_export('/fullmakt/s/' . $token, true) . ';
$_SERVER["SCRIPT_NAME"] = "/index.php";
$_SERVER["SCRIPT_FILENAME"] = ' . var_export($root . '/index.php', true) . ';
$_GET = [];
$_POST = [];
register_shutdown_function(static function (): void {
    $code = http_response_code();
    file_put_contents(' . var_export($statusFile, true) . ', (string) ($code === false ? 200 : $code));
});
require ' . var_export($root . '/index.php', true) . ';
';
    file_put_contents($runner, $php);
    $proc = proc_open(
        [PHP_BINARY, '-d', 'display_errors=1', $runner],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        [
            'PATH' => (string) getenv('PATH'),
            'JEAB_DISABLE_SQLITE' => '0',
            'DATA_BACKEND' => 'mysql',
            'FULLMAKT_DATA' => $tmp . '/fullmakt',
            'FULLMAKT_PUBLIC_URL' => 'https://flowstack.example.test/fullmakt',
        ]
    );
    if (!is_resource($proc)) {
        fpc(false, 'kunde inte starta webbanropet');
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    @unlink($runner);
    $code = is_file($statusFile) ? (int) file_get_contents($statusFile) : 0;
    @unlink($statusFile);

    return ['code' => $code, 'out' => $stdout, 'err' => $stderr];
}

$sign = fpc_hit($root, $tmp, $token);
if ($sign['err'] !== '' && (str_contains($sign['err'], 'Fatal') || str_contains($sign['err'], 'Uncaught'))) {
    fpc(false, 'kall kundlänk kraschade: ' . substr($sign['err'], 0, 400));
}
fpc($sign['code'] === 200, 'utan kaka ger SQL-token 200, fick ' . $sign['code'] . ' ' . substr($sign['out'] . $sign['err'], 0, 240));
fpc(str_contains($sign['out'], 'Kall Kund'), 'sidan visar kundnamnet');
fpc(str_contains($sign['out'], 'id="sign-form"'), 'sidan visar signeringsformuläret');
fpc(str_contains($sign['out'], 'id="skicka"'), 'sidan visar signeringsknappen');
fpc(!str_contains($sign['out'], 'Länken gäller inte'), 'sidan är inte 404');
fpc(!is_file(fullmakt_pending_path($token)), 'anropet skrev ingen JSON');

echo "fullmakt_public_cold_test: ok\n";
