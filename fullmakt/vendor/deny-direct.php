<?php
declare(strict_types=1);

/**
 * Körs före vendor-PHP via auto_prepend i samma katalog som det anropade skriptet.
 * require från fullmakt/lib/pdf.php träffar inte den här filen som huvudskript.
 */
function fullmakt_vendor_request_is_direct(): bool
{
    if (PHP_SAPI === 'cli' && getenv('FULLMAKT_VENDOR_DENY_TEST') !== '1') {
        return false;
    }
    $script = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $vendor = realpath(__DIR__);
    if ($script === false || $vendor === false) {
        return false;
    }
    return $script === $vendor || str_starts_with($script, $vendor . DIRECTORY_SEPARATOR);
}

if (fullmakt_vendor_request_is_direct()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo "Forbidden\n";
    exit;
}
