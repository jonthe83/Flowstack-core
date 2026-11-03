<?php

declare(strict_types=1);

/**
 * create/invite får inte skriva signerings-token eller mottagaradress i loggen.
 * Kundmejlet behåller signerings-URL. Direktträff mot lib och vendor nekas.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-fm-api-log-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0700, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}

$realArchive = $root . '/data/.private/fullmakt';
$asideArchive = $root . '/data/.private/fullmakt.api-log-aside';
$movedArchive = false;
if (is_dir($realArchive) && !is_dir($asideArchive)) {
    $movedArchive = rename($realArchive, $asideArchive);
    if (!$movedArchive) {
        fwrite(STDERR, "FAIL kunde inte flytta undan privat fullmakt-arkiv\n");
        exit(1);
    }
}

$cleanup = static function () use ($tmp, $realArchive, $asideArchive, $movedArchive): void {
    if ($movedArchive && is_dir($asideArchive) && !is_dir($realArchive)) {
        rename($asideArchive, $realArchive);
    }
    if (is_dir($tmp)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($tmp);
    }
};
register_shutdown_function($cleanup);

function api_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function api_ok(string $message): void
{
    echo "OK  {$message}\n";
}

$createSrc = (string) file_get_contents($root . '/fullmakt/api/create.php');
$inviteSrc = (string) file_get_contents($root . '/fullmakt/api/invite.php');
if (str_contains($createSrc, 'token=') || str_contains($createSrc, "to='")) {
    api_fail('create.php loggar fortfarande token eller mottagare');
}
if (!str_contains($inviteSrc, "fullmakt_log('mail.log', 'invite-resend mailed=") || str_contains($inviteSrc, 'token=')) {
    api_fail('invite.php loggar fortfarande token');
}
api_ok('källan loggar inte token eller mottagare');

putenv('FULLMAKT_DATA=' . $tmp . '/store');
putenv('FULLMAKT_KEY=test-key-not-live');
putenv('FULLMAKT_PUBLIC_URL=https://flowstack.example.test/fullmakt');
$_SERVER['HTTP_HOST'] = 'flowstack.example.test';
require $root . '/fullmakt/lib/bootstrap.php';
require $root . '/fullmakt/lib/mailer.php';
require $root . '/fullmakt/lib/pdf.php';

$secretToken = 'aabbccddeeff00112233445566778899';
$secretMail = 'signer@example.com';
$overlay = fullmakt_redact_log_line(
    'fpdi Unable to parse /privat/fullmakt/originals/' . $secretToken . '.pdf for ' . $secretMail,
    '',
    '/privat/fullmakt/originals/' . $secretToken . '.pdf'
);
if (str_contains($overlay, $secretToken) || str_contains($overlay, $secretMail) || !str_contains($overlay, '[mejl]')) {
    api_fail('overlay-raden döljer inte token och adress: ' . $overlay);
}
api_ok('overlay-loggen döljer token och adress');

[$subject, $body] = fullmakt_invite_parts([
    'type' => 'ellevio',
    'namn' => 'Anna Andersson',
    'epost' => $secretMail,
], 'https://flowstack.example.test/fullmakt/s/' . $secretToken);
if (!str_contains($body, 'https://flowstack.example.test/fullmakt/s/' . $secretToken)) {
    api_fail('kundmejlet saknar signerings-URL');
}
api_ok('kundmejlet behåller signerings-URL');

$mailerSrc = (string) file_get_contents($root . '/fullmakt/lib/mailer.php');
if (!str_contains($mailerSrc, "'signerat.pdf'") || preg_match('/Token:\s*\{/', $mailerSrc) === 1) {
    api_fail('personalmejlet eller PDF-filnamnet har fått tillbaka token');
}
api_ok('personalmejl och signerat.pdf är oförändrade');

$cgi = trim((string) shell_exec('command -v php-cgi'));
if ($cgi === '') {
    api_fail('php-cgi saknas');
}
$prepend = $tmp . '/prepend.php';
file_put_contents($prepend, <<<'PHP'
<?php
$capture = (string) getenv('FULLMAKT_MAIL_CAPTURE');
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use ($capture): array {
    file_put_contents($capture, json_encode($opts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return ['ok' => true, 'path' => 'helpscout', 'id' => 'fixture'];
};
PHP
);

$runApi = static function (string $script, string $uri, string $json) use ($cgi, $prepend, $tmp, $root): array {
    $capture = $tmp . '/mail-capture.json';
    @unlink($capture);
    $des = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $env = [
        'PATH' => (string) getenv('PATH'),
        'FULLMAKT_DATA' => $tmp . '/store',
        'FULLMAKT_KEY' => 'test-key-not-live',
        'FULLMAKT_PUBLIC_URL' => 'https://flowstack.example.test/fullmakt',
        'FULLMAKT_MAIL_CAPTURE' => $capture,
        'REQUEST_METHOD' => 'POST',
        'REDIRECT_STATUS' => '200',
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'HTTP_HOST' => 'flowstack.example.test',
        'HTTP_X_FULLMAKT_KEY' => 'test-key-not-live',
        'CONTENT_TYPE' => 'application/json',
        'CONTENT_LENGTH' => (string) strlen($json),
        'SCRIPT_FILENAME' => $script,
        'REQUEST_URI' => $uri,
        'DOCUMENT_ROOT' => $root,
    ];
    $proc = proc_open(
        [$cgi, '-d', 'auto_prepend_file=' . $prepend, '-d', 'display_errors=1', '-d', 'user_ini.cache_ttl=0', $script],
        $des,
        $pipes,
        $root,
        $env
    );
    if (!is_resource($proc)) {
        api_fail('kunde inte starta ' . $script);
    }
    fwrite($pipes[0], $json);
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $body = preg_split("/\r?\n\r?\n/", $stdout, 2)[1] ?? $stdout;
    $decoded = json_decode($body, true);
    if ($code !== 0 || !is_array($decoded)) {
        api_fail($script . " svarade inte JSON ({$code}) {$stdout} {$stderr}");
    }
    $captured = is_file($capture) ? json_decode((string) file_get_contents($capture), true) : null;
    return ['json' => $decoded, 'mail' => is_array($captured) ? $captured : null];
};

$created = $runApi($root . '/fullmakt/api/create.php', '/fullmakt/api/create.php', json_encode([
    'namn' => 'Anna Andersson',
    'personnummer' => '19800101-1234',
    'telefon' => '070-123 45 67',
    'epost' => 'signer@example.com',
    'adress' => 'Testvägen 1',
    'postnummer' => '176 76',
    'ort' => 'Järfälla',
    'typ' => 'privatperson',
], JSON_UNESCAPED_UNICODE));
$token = (string) ($created['json']['token'] ?? '');
$url = (string) ($created['json']['url'] ?? '');
if (($created['json']['ok'] ?? false) !== true || $token === '' || !str_contains($url, $token)) {
    api_fail('create-svaret saknar token eller url');
}
$mailBody = (string) ($created['mail']['text'] ?? '');
if (!str_contains($mailBody, $url)) {
    api_fail('det skickade kundmejlet saknar signerings-URL');
}
api_ok('create-svar och kundmejl bär signeringslänken');

$resent = $runApi($root . '/fullmakt/api/invite.php', '/fullmakt/api/invite.php', json_encode(['token' => $token], JSON_UNESCAPED_UNICODE));
if (($resent['json']['ok'] ?? false) !== true || ($resent['json']['token'] ?? '') !== $token) {
    api_fail('resend-svaret saknar token');
}
if (!str_contains((string) ($resent['mail']['text'] ?? ''), $url)) {
    api_fail('omskickat kundmejl saknar signerings-URL');
}
api_ok('resend-svar och kundmejl bär signeringslänken');

$logs = '';
foreach (glob($tmp . '/store/tmp/*') ?: [] as $logFile) {
    if (is_file($logFile)) {
        $logs .= (string) file_get_contents($logFile);
    }
}
if ($logs === '' || !str_contains($logs, 'invite mailed=1') || !str_contains($logs, 'invite-resend mailed=1')) {
    api_fail('mail.log saknar invite-raderna: ' . $logs);
}
if (str_contains($logs, $token) || str_contains($logs, 'signer@example.com') || str_contains($logs, 'token=')) {
    api_fail('loggen innehåller token eller mottagaradress: ' . $logs);
}
api_ok('mail.log har varken token eller mottagaradress');

$hit = static function (string $script, ?string $docroot) use ($cgi): string {
    $des = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $env = [
        'PATH' => (string) getenv('PATH'),
        'REDIRECT_STATUS' => '200',
        'REQUEST_METHOD' => 'GET',
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'SCRIPT_FILENAME' => $script,
        'REQUEST_URI' => '/probe',
    ];
    if ($docroot !== null) {
        $env['DOCUMENT_ROOT'] = $docroot;
    }
    $proc = proc_open([$cgi, '-d', 'user_ini.cache_ttl=0', '-d', 'display_errors=1', $script], $des, $pipes, null, $env);
    if (!is_resource($proc)) {
        api_fail('kunde inte starta php-cgi');
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return $stdout . $stderr;
};

$libHit = $hit($root . '/fullmakt/lib/pdf.php', $root);
if (!str_contains($libHit, '403') || !str_contains($libHit, 'Forbidden') || str_contains($libHit, 'class FPDF') || str_contains($libHit, 'function fullmakt_build_pdf')) {
    api_fail('direktträff mot fullmakt/lib/pdf.php nekades inte: ' . $libHit);
}
api_ok('direktträff mot fullmakt/lib är 403');

$vendorHit = $hit($root . '/fullmakt/vendor/fpdf/fpdf.php', $root);
if (!str_contains($vendorHit, '403') || !str_contains($vendorHit, 'Forbidden') || str_contains($vendorHit, 'class FPDF')) {
    api_fail('direktträff mot vendor-PHP nekades inte: ' . $vendorHit);
}
api_ok('direktträff mot fullmakt/vendor är 403');

$includeScript = $tmp . '/include-fpdf.php';
file_put_contents($includeScript, "<?php\nrequire " . var_export($root . '/fullmakt/vendor/fpdf/fpdf.php', true) . ";\necho class_exists('FPDF') ? \"FPDF-OK\\n\" : \"FPDF-MISSING\\n\";\n");
$includeHit = $hit($includeScript, $tmp);
if (!str_contains($includeHit, 'FPDF-OK') || str_contains($includeHit, 'Forbidden')) {
    api_fail('include av fpdf från lib-sidan bröts: ' . $includeHit);
}
api_ok('include av vendor-PHP från ett annat skript fungerar');

$routerHit = static function (string $uri) use ($root): string {
    $des = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $code = '$_SERVER["REQUEST_URI"]=' . var_export($uri, true) . '; require ' . var_export($root . '/fullmakt/router.php', true) . ';';
    $proc = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-r', $code], $des, $pipes, $root);
    if (!is_resource($proc)) {
        api_fail('kunde inte starta router');
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return $stdout . $stderr;
};
foreach (['/fullmakt/vendor/fpdf/fpdf.php', '/fullmakt/lib/pdf.php', '/fullmakt/data/secret.php'] as $uri) {
    $out = $routerHit($uri);
    if (!str_contains($out, 'Forbidden') || str_contains($out, 'class FPDF')) {
        api_fail("router släppte {$uri}: {$out}");
    }
}
api_ok('router nekar lib, data och vendor');

echo "fullmakt api log: ok\n";
