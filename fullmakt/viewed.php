<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/viewed.php';
require_once __DIR__ . '/lib/parties.php';

fullmakt_boot();
fullmakt_load_jeab();
fullmakt_security_headers();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json; charset=utf-8');
    echo '{"ok":false}';
    exit;
}

$token = (string) ($_POST['token'] ?? '');
$ok = fullmakt_mark_viewed($token);
header('Content-Type: application/json; charset=utf-8');
echo $ok ? '{"ok":true}' : '{"ok":false}';
