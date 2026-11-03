<?php

declare(strict_types=1);

/**
 * Already-signed invite link (/s/{token}) must show the signed PDF,
 * without echoing token, filesystem path, or a signing URL in the body.
 * Unsigned documents stay on the signing form. No directory listing.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-fm-signed-view-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0700, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}

$cleanup = static function () use ($tmp): void {
    if (!is_dir($tmp)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($tmp);
};
register_shutdown_function($cleanup);

function sv_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function sv_ok(string $message): void
{
    echo "OK  {$message}\n";
}

function sv_run_sign(string $root, string $dataDir, string $token, string $method = 'GET'): array
{
    $script = $tmpScript = $dataDir . '/run-sign.php';
    $php = '<?php
declare(strict_types=1);
$_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . ';
$_SERVER["HTTP_HOST"] = "flowstack.example.test";
$_SERVER["HTTPS"] = "on";
$_SERVER["REQUEST_URI"] = "/fullmakt/s/" . ' . var_export($token, true) . ';
$_GET["token"] = ' . var_export($token, true) . ';
putenv("FULLMAKT_DATA=" . ' . var_export($dataDir . '/store', true) . ');
putenv("FULLMAKT_PUBLIC_URL=https://flowstack.example.test/fullmakt");
require ' . var_export($root . '/fullmakt/sign.php', true) . ';
';
    file_put_contents($script, $php);
    $des = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open(
        [PHP_BINARY, '-d', 'display_errors=1', $script],
        $des,
        $pipes,
        $root,
        [
            'PATH' => (string) getenv('PATH'),
            'FULLMAKT_DATA' => $dataDir . '/store',
            'FULLMAKT_PUBLIC_URL' => 'https://flowstack.example.test/fullmakt',
        ]
    );
    if (!is_resource($proc)) {
        sv_fail('kunde inte starta sign.php');
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    @unlink($script);
    return ['out' => $stdout, 'err' => $stderr, 'code' => $code];
}

$dataDir = $tmp;
$store = $dataDir . '/store';
foreach (['pending', 'signed', 'tmp', 'originals'] as $dir) {
    if (!mkdir($store . '/' . $dir, 0700, true) && !is_dir($store . '/' . $dir)) {
        sv_fail('kunde inte skapa store/' . $dir);
    }
}
file_put_contents($store . '/secret.php', "<?php\n\$FULLMAKT_KEY = 'test-key-not-live';\n");

$signedToken = 'sv' . bin2hex(random_bytes(8));
$pendingToken = 'pv' . bin2hex(random_bytes(8));
$marker = '%PDF-1.4\n%signed-view-marker-' . bin2hex(random_bytes(6)) . "\n";

$signedDoc = [
    'token' => $signedToken,
    'status' => 'signed',
    'namn' => 'Saga Signerad',
    'signed_at' => '2026-10-04T12:00:00+02:00',
    'pdf' => $signedToken . '.pdf',
];
file_put_contents(
    $store . '/signed/' . $signedToken . '.json',
    json_encode($signedDoc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);
file_put_contents($store . '/signed/' . $signedToken . '.pdf', $marker);

$pendingDoc = [
    'token' => $pendingToken,
    'status' => 'pending',
    'namn' => 'Per Pending',
    'doc_type' => 'ellevio',
    'typ' => 'privatperson',
    'personnummer' => '19800101-1234',
    'telefon' => '0701234567',
    'epost' => 'per@example.com',
    'adress' => 'Testvägen 1',
    'postnummer' => '176 74',
    'ort' => 'Järfälla',
    'anlaggningsadress' => 'Testvägen 1',
];
file_put_contents(
    $store . '/pending/' . $pendingToken . '.json',
    json_encode($pendingDoc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);

$signedHit = sv_run_sign($root, $dataDir, $signedToken);
if ($signedHit['code'] !== 0) {
    sv_fail('signerad länk kraschade: ' . $signedHit['err'] . $signedHit['out']);
}
if (!str_starts_with($signedHit['out'], '%PDF-1.4')) {
    sv_fail('signerad länk gav inte PDF, fick: ' . substr($signedHit['out'], 0, 200));
}
if (!str_contains($signedHit['out'], 'signed-view-marker-')) {
    sv_fail('PDF-markören saknas i svaret');
}
sv_ok('signerad länk visar den signerade PDF:en');

foreach ([$signedToken, '/s/' . $signedToken, 'sign.php', $store . '/signed/', 'privat/fullmakt', 'pdf.php?token='] as $leak) {
    if (str_contains($signedHit['out'], $leak)) {
        sv_fail('PDF-svaret innehåller läcka: ' . $leak);
    }
}
sv_ok('PDF-svaret ekar inte token, sökväg eller signeringslänk');

if (str_contains($signedHit['out'], 'Redan signerad') || str_contains($signedHit['out'], 'Länken kan inte användas igen')) {
    sv_fail('signerad länk visar fortfarande stängd/tom sida');
}
sv_ok('ingen stängd "Redan signerad"-sida för signerad länk');

$pendingHit = sv_run_sign($root, $dataDir, $pendingToken);
if ($pendingHit['code'] !== 0) {
    sv_fail('osignerad länk kraschade: ' . $pendingHit['err']);
}
if (str_starts_with($pendingHit['out'], '%PDF')) {
    sv_fail('osignerad länk ska inte servera PDF');
}
if (!str_contains($pendingHit['out'], 'Signera') && !str_contains($pendingHit['out'], 'sign-form')) {
    sv_fail('osignerad länk ska visa signeringsformulär');
}
if (str_contains($pendingHit['out'], $store) || str_contains($pendingHit['out'], 'privat/fullmakt/signed')) {
    sv_fail('osignerad HTML läcker sökväg');
}
sv_ok('osignerad länk förblir privat signeringsvy');

$missingPdfToken = 'mp' . bin2hex(random_bytes(8));
file_put_contents(
    $store . '/signed/' . $missingPdfToken . '.json',
    json_encode([
        'token' => $missingPdfToken,
        'status' => 'signed',
        'namn' => 'Utan Pdf',
    ], JSON_UNESCAPED_UNICODE)
);
$missingHit = sv_run_sign($root, $dataDir, $missingPdfToken);
if (str_starts_with($missingHit['out'], '%PDF')) {
    sv_fail('saknad PDF ska inte hitta på en fil');
}
if (str_contains($missingHit['out'], $missingPdfToken) || str_contains($missingHit['out'], '/s/')) {
    sv_fail('felsida ekar token eller signeringslänk');
}
if (!str_contains($missingHit['out'], 'signerat')) {
    sv_fail('felsida nämner att dokumentet är signerat');
}
sv_ok('signerad utan PDF ger felsida utan token/sökväg');

$unknown = sv_run_sign($root, $dataDir, 'unknown' . bin2hex(random_bytes(4)));
if (str_starts_with($unknown['out'], '%PDF') || !str_contains($unknown['out'], 'Länken gäller inte')) {
    sv_fail('okänd token ska ge 404-sida, inte PDF');
}
sv_ok('okänd token ger inte dokument');

$signSrc = (string) file_get_contents($root . '/fullmakt/sign.php');
$pdfSrc = (string) file_get_contents($root . '/fullmakt/pdf.php');
$storeSrc = (string) file_get_contents($root . '/fullmakt/lib/store.php');
if (!str_contains($signSrc, 'fullmakt_serve_signed_pdf($token)')) {
    sv_fail('sign.php anropar inte fullmakt_serve_signed_pdf');
}
if (!str_contains($pdfSrc, 'fullmakt_serve_signed_pdf($token)')) {
    sv_fail('pdf.php anropar inte fullmakt_serve_signed_pdf');
}
if (!str_contains($storeSrc, 'filename="fullmakt.pdf"')) {
    sv_fail('servehjälparen ska använda generiskt filnamn');
}
if (str_contains($signSrc, 'Länken kan inte användas igen')) {
    sv_fail('stängd-texten ska vara borta från sign.php');
}
if (str_contains($signSrc, 'token=$token') || str_contains($pdfSrc, 'token=$token')) {
    sv_fail('källan får inte ha token=$token-mönster');
}
sv_ok('källan serverar PDF via gemensam hjälpare utan stängd sida');

echo "fullmakt_signed_view_test: ok\n";
