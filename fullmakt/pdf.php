<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/parties.php';

fullmakt_boot();
fullmakt_load_jeab();

$token = fullmakt_request_token();
$doc = $token !== '' ? fullmakt_load_doc($token) : null;
// filename="fullmakt.pdf" sätts i fullmakt_serve_signed_pdf (generiskt, utan token).
if (!is_array($doc) || ($doc['status'] ?? '') !== 'signed' || !fullmakt_serve_signed_pdf($token)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Dokumentet finns inte';
    exit;
}
exit;
