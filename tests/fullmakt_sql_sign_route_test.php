<?php

declare(strict_types=1);

/**
 * Token som bara finns i SQL ska öppnas via /fullmakt/s/{token}
 * innan JEAB är laddad. Samma väg för pdf och tack.
 * Påhittade rader. Inget personnummer. Ingen JSON-fil.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-fullmakt-sql-route-' . bin2hex(random_bytes(4));
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

function fsr(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$pdo = jeab_data_pdo();
fsr($pdo instanceof PDO && fullmakt_sql_active(), 'SQL-vägen är aktiv');

$created = fullmakt_create_doc([
    'type' => 'ellevio',
    'typ' => 'privatperson',
    'namn' => 'Sql Route',
    'epost' => 'sql.route@example.test',
    'telefon' => '0701000099',
    'adress' => 'Testvägen 1',
    'postnummer' => '176 74',
    'ort' => 'Järfälla',
    'anlaggningsadress' => 'Testvägen 1',
    'anlaggnings_id' => '',
]);
$token = (string) ($created['token'] ?? '');
fsr($token !== '' && !is_file(fullmakt_pending_path($token)), 'skapad rad ligger bara i SQL');
fsr((fullmakt_sql_get($token)['namn'] ?? '') === 'Sql Route', 'SQL-raden har namnet');

$signed = fullmakt_create_doc([
    'type' => 'ellevio',
    'namn' => 'Sql Pdf',
    'epost' => 'sql.pdf@example.test',
    'telefon' => '0701000098',
]);
$signedToken = (string) ($signed['token'] ?? '');
$signed['status'] = 'signed';
$signed['token'] = $signedToken;
fullmakt_sql_put($signed);
$marker = "%PDF-1.4\n%sql-route-marker\n";
file_put_contents(fullmakt_signed_pdf_path($signedToken), $marker);
fsr(
    $signedToken !== ''
        && !is_file(fullmakt_pending_path($signedToken))
        && !is_file(fullmakt_signed_json_path($signedToken)),
    'signerad rad ligger bara i SQL'
);

/**
 * @return array{code:int,out:string,err:string}
 */
function fsr_hit(string $root, string $tmp, string $uri, array $get = [], string $method = 'GET', string $script = ''): array
{
    $statusFile = $tmp . '/status-' . bin2hex(random_bytes(3)) . '.txt';
    $runner = $tmp . '/hit-' . bin2hex(random_bytes(3)) . '.php';
    $target = $script !== '' ? $script : $root . '/index.php';
    $php = '<?php
declare(strict_types=1);
$GLOBALS["_JEAB_DATA_DIR"] = ' . var_export($tmp, true) . ';
$GLOBALS["_JEAB_PUBLIC_DATA_DIR"] = ' . var_export($tmp, true) . ';
$GLOBALS["_JEAB_STEG4_CUTOVER"] = true;
$GLOBALS["_JEAB_DATA_BACKEND"] = "mysql";
putenv("JEAB_DISABLE_SQLITE=0");
putenv("DATA_BACKEND=mysql");
putenv("FULLMAKT_DATA=" . ' . var_export($tmp . '/fullmakt', true) . ');
putenv("FULLMAKT_PUBLIC_URL=https://flowstack.example.test/fullmakt");
$_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . ';
$_SERVER["HTTP_HOST"] = "flowstack.example.test";
$_SERVER["HTTPS"] = "on";
$_SERVER["REQUEST_URI"] = ' . var_export($uri, true) . ';
$_SERVER["SCRIPT_NAME"] = "/index.php";
$_GET = ' . var_export($get, true) . ';
if (' . var_export($method, true) . ' === "POST") {
    $_POST = ' . var_export($get, true) . ';
}
register_shutdown_function(static function (): void {
    $code = http_response_code();
    file_put_contents(' . var_export($statusFile, true) . ', (string) ($code === false ? 200 : $code));
});
require ' . var_export($target, true) . ';
';
    file_put_contents($runner, $php);
    $des = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open(
        [PHP_BINARY, '-d', 'display_errors=1', $runner],
        $des,
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
        fsr(false, 'kunde inte starta ' . $uri);
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

$sign = fsr_hit($root, $tmp, '/fullmakt/s/' . $token);
if ($sign['err'] !== '' && (str_contains($sign['err'], 'Fatal') || str_contains($sign['err'], 'Uncaught'))) {
    fsr(false, 'sign-route kraschade: ' . $sign['err']);
}
fsr($sign['code'] === 200, 'SQL-token via /fullmakt/s/ ger 200, fick ' . $sign['code'] . ' ' . substr($sign['err'], 0, 300));
fsr(str_contains($sign['out'], 'id="sign-form"'), 'sign-route visar signeringsformuläret');
fsr(str_contains($sign['out'], 'Sql Route'), 'sign-route visar namnet från SQL');
fsr(!str_contains($sign['out'], 'Länken gäller inte'), 'sign-route är inte 404-sidan');
fsr(!is_file(fullmakt_pending_path($token)), 'sign-route skrev ingen JSON');

$pdf = fsr_hit($root, $tmp, '/fullmakt/pdf.php?token=' . rawurlencode($signedToken), ['token' => $signedToken]);
if ($pdf['err'] !== '' && (str_contains($pdf['err'], 'Fatal') || str_contains($pdf['err'], 'Uncaught'))) {
    fsr(false, 'pdf-route kraschade: ' . $pdf['err']);
}
fsr($pdf['code'] === 200 && str_starts_with($pdf['out'], '%PDF-1.4') && str_contains($pdf['out'], 'sql-route-marker'), 'SQL-token via /fullmakt/pdf.php ger PDF');
fsr(!is_file(fullmakt_signed_json_path($signedToken)), 'pdf-route skrev ingen JSON');

$tack = fsr_hit($root, $tmp, '/fullmakt/tack');
fsr($tack['code'] === 200 && str_contains($tack['out'], 'Tack') && str_contains($tack['out'], 'Dokumentet är signerat'), 'tack-route svarar 200');

$viewed = fsr_hit($root, $tmp, '/fullmakt/viewed.php', ['token' => $token], 'POST', $root . '/fullmakt/viewed.php');
fsr($viewed['code'] === 200 && str_contains($viewed['out'], '{"ok":true}'), 'viewed mot SQL-token ger ok ' . substr($viewed['out'] . $viewed['err'], 0, 240));
$stmt = $pdo->prepare('SELECT payload FROM fullmakt_docs WHERE token = ?');
$stmt->execute([$token]);
$raw = $stmt->fetchColumn();
$payload = is_string($raw) ? json_decode($raw, true) : null;
fsr(is_array($payload) && trim((string) ($payload['viewed_at'] ?? '')) !== '', 'viewed_at skrevs i SQL');
fsr(!is_file(fullmakt_pending_path($token)), 'viewed skrev ingen JSON');

$missing = fsr_hit($root, $tmp, '/fullmakt/s/saknas' . bin2hex(random_bytes(3)));
fsr($missing['code'] === 404 && str_contains($missing['out'], 'Länken gäller inte'), 'okänd token är fortfarande 404');

echo "fullmakt_sql_sign_route_test: ok\n";
