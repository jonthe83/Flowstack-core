<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/parties.php';

fullmakt_boot();
fullmakt_load_jeab();
fullmakt_security_headers();

$token = fullmakt_request_token();
$doc = $token !== '' ? fullmakt_load_doc($token) : null;
if ($doc === null || ($doc['status'] ?? '') === 'signed') {
    http_response_code($doc === null ? 404 : 410);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Förhandsvisning saknas';
    exit;
}

$path = fullmakt_preview_png_path($token);
if (!is_readable($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Förhandsvisning saknas';
    exit;
}

header('Content-Type: image/png');
header('Cache-Control: private, max-age=120');
readfile($path);
