<?php

declare(strict_types=1);

/**
 * Direktträff mot PHP under fullmakt/vendor/ ska bli 403 innan biblioteket körs,
 * även när PHP bara läser .user.ini i skriptets egen katalog.
 * require från fullmakt/lib/pdf.php ska fortfarande kunna skapa PDF.
 */

$root = dirname(__DIR__);
$cgi = trim((string) shell_exec('command -v php-cgi'));
if ($cgi === '') {
    fwrite(STDERR, "FAIL php-cgi saknas\n");
    exit(1);
}

$tmp = sys_get_temp_dir() . '/jeab-fm-vendor-deny-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0700, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$docLink = $tmp . '/docroot';
if (!symlink($root, $docLink)) {
    fwrite(STDERR, "FAIL kunde inte skapa docroot-symlink\n");
    exit(1);
}

$cleanup = static function () use ($tmp, $docLink): void {
    if (is_link($docLink)) {
        @unlink($docLink);
    }
    if (!is_dir($tmp)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        if ($item->isLink() || $item->isFile()) {
            @unlink($item->getPathname());
        } elseif ($item->isDir()) {
            @rmdir($item->getPathname());
        }
    }
    @rmdir($tmp);
};
register_shutdown_function($cleanup);

function vendor_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function vendor_ok(string $message): void
{
    echo "OK  {$message}\n";
}

$canonical = (string) file_get_contents($root . '/fullmakt/vendor/.user.ini');
if (!str_contains($canonical, 'auto_prepend_file=${DOCUMENT_ROOT}/fullmakt/vendor/deny-direct.php')) {
    vendor_fail('vendor/.user.ini pekar inte på deny-direct.php');
}

$phpDirs = [];
$phpIt = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/fullmakt/vendor', FilesystemIterator::SKIP_DOTS)
);
foreach ($phpIt as $item) {
    if ($item->isFile() && str_ends_with($item->getFilename(), '.php')) {
        $phpDirs[$item->getPath()] = true;
    }
}
if ($phpDirs === []) {
    vendor_fail('hittade ingen PHP under fullmakt/vendor');
}
foreach (array_keys($phpDirs) as $dir) {
    $ini = $dir . '/.user.ini';
    if (!is_file($ini) || (string) file_get_contents($ini) !== $canonical) {
        vendor_fail('saknar samma .user.ini som vendor-roten: ' . $dir);
    }
}
vendor_ok('varje PHP-katalog under vendor har auto_prepend till deny-direct.php');

$fpdf = (string) file_get_contents($root . '/fullmakt/vendor/fpdf/fpdf.php');
$fpdi = (string) file_get_contents($root . '/fullmakt/vendor/fpdi/Fpdi.php');
if (str_contains($fpdf, 'fullmakt_vendor_request_is_direct') || str_contains($fpdi, 'fullmakt_vendor_request_is_direct')) {
    vendor_fail('FPDF/FPDI-källan är omskriven med deny-kontroll');
}
vendor_ok('FPDF och FPDI är orörda');

$hit = static function (string $script, string $docroot) use ($cgi, $tmp): string {
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
        'SCRIPT_NAME' => '/fullmakt/vendor/probe.php',
        'REQUEST_URI' => '/fullmakt/vendor/probe.php',
        'DOCUMENT_ROOT' => $docroot,
    ];
    $proc = proc_open(
        [$cgi, '-d', 'user_ini.cache_ttl=0', '-d', 'display_errors=1', '-d', 'display_startup_errors=1', $script],
        $des,
        $pipes,
        $tmp,
        $env
    );
    if (!is_resource($proc)) {
        vendor_fail('kunde inte starta php-cgi');
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return $stdout . $stderr;
};

$assertDenied = static function (string $label, string $output): void {
    $ranLibrary = str_contains($output, 'Fatal error')
        || str_contains($output, 'Uncaught')
        || str_contains($output, 'class FPDF')
        || str_contains($output, 'FpdfTpl')
        || str_contains($output, 'Failed opening required');
    if (!str_contains($output, 'Status: 403') || !str_contains($output, 'Forbidden') || $ranLibrary) {
        vendor_fail($label . ' kördes eller nekades inte: ' . $output);
    }
    vendor_ok($label . ' är 403 utan att biblioteket körs');
};

$direct = [
    'vendor/deny-direct.php' => $root . '/fullmakt/vendor/deny-direct.php',
    'vendor/fpdf/fpdf.php' => $root . '/fullmakt/vendor/fpdf/fpdf.php',
    'vendor/fpdi/Fpdi.php' => $root . '/fullmakt/vendor/fpdi/Fpdi.php',
    'vendor/fpdi/PdfParser/Filter/Flate.php' => $root . '/fullmakt/vendor/fpdi/PdfParser/Filter/Flate.php',
];
foreach ($direct as $label => $script) {
    if (!is_file($script)) {
        vendor_fail('saknar ' . $script);
    }
    $assertDenied($label . ' (dokumentrot via symlink)', $hit($script, $docLink));
}
$assertDenied(
    'vendor/fpdf/fpdf.php (dokumentrot = webbrot)',
    $hit($root . '/fullmakt/vendor/fpdf/fpdf.php', $root)
);

$builder = $tmp . '/build-pdf.php';
$bootstrap = var_export($root . '/fullmakt/lib/bootstrap.php', true);
$pdfLib = var_export($root . '/fullmakt/lib/pdf.php', true);
file_put_contents($builder, <<<PHP
<?php
declare(strict_types=1);
require {$bootstrap};
require {$pdfLib};
\$doc = [
    'typ' => 'privatperson',
    'namn' => 'Test Testsson',
    'personnummer' => '198001011234',
    'telefon' => '0700000000',
    'epost' => 'a@example.com',
    'adress' => 'Vägen 1',
    'postnummer' => '17676',
    'ort' => 'Järfälla',
    'anlaggningsadress' => 'Vägen 1',
    'anlaggnings_id' => '735999000000000000',
];
\$bytes = fullmakt_build_ellevio_pdf(\$doc, '');
if (!is_string(\$bytes) || strncmp(\$bytes, '%PDF', 4) !== 0) {
    fwrite(STDERR, "ellevio-pdf saknas\\n");
    exit(2);
}
require_once FULLMAKT_ROOT . '/vendor/fpdi/autoload.php';
\$fpdi = new \\setasign\\Fpdi\\Fpdi('P', 'mm', 'A4');
\$fpdi->AddPage();
\$fpdi->SetFont('Helvetica', '', 12);
\$fpdi->Cell(40, 10, 'ok');
\$imported = \$fpdi->Output('S');
if (!is_string(\$imported) || strncmp(\$imported, '%PDF', 4) !== 0) {
    fwrite(STDERR, "fpdi-pdf saknas\\n");
    exit(3);
}
echo "PDF-OK\\n";
PHP
);
$build = $hit($builder, $docLink);
if (!str_contains($build, 'PDF-OK') || str_contains($build, 'Forbidden') || str_contains($build, 'Fatal error')) {
    vendor_fail('PDF-include från lib/pdf.php bröts: ' . $build);
}
vendor_ok('lib/pdf.php skapar PDF och FPDI kan laddas');

echo "fullmakt vendor deny: ok\n";
