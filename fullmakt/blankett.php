<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/pdf.php';
require_once __DIR__ . '/lib/parties.php';
fullmakt_load_jeab();

fullmakt_boot();
fullmakt_security_headers();

$token = fullmakt_request_token();
$doc = $token !== '' ? fullmakt_load_doc($token) : null;
if ($doc === null || ($doc['status'] ?? '') === 'signed') {
    http_response_code($doc === null ? 404 : 410);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Blanketten kan inte visas';
    exit;
}

$prepared = fullmakt_backfill_anlaggnings_id(fullmakt_ensure_parties($doc));
if ($prepared !== $doc) {
    try {
        fullmakt_save_pending($prepared);
    } catch (Throwable $e) {
        fullmakt_log('error.log', 'blankett ' . $e->getMessage());
    }
    $doc = $prepared;
}

try {
    [$pdf, $method] = fullmakt_build_pdf($doc, '');
} catch (Throwable $e) {
    fullmakt_log('error.log', 'blankett ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Blanketten kunde inte ritas';
    exit;
}

unset($method);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="fullmakt.pdf"');
echo $pdf;
