<?php

declare(strict_types=1);

/**
 * Lokal yta för webbläsartesterna. Ingen hemlighet. flowstack.example.test anropas inte.
 */

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) ? $path : '/';

if ($path === '/health') {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ok';
    return true;
}

if ($path === '/save') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"ok":false}';
        return true;
    }
    $countFile = getenv('JEAB_E2E_SAVES');
    if (!is_string($countFile) || $countFile === '') {
        $countFile = sys_get_temp_dir() . '/jeab-e2e-saves';
    }
    file_put_contents($countFile, "1\n", FILE_APPEND | LOCK_EX);
    usleep(200000);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"ok":true}';
    return true;
}

$flows = [
    '/flow/1' => 'flow1-paminnelser.html',
    '/flow/2' => 'flow2-inkorg.html',
    '/flow/3' => 'flow3-ordrar.html',
    '/flow/double' => 'flow-double.html',
    '/flow/shot' => 'flow-shot.html',
    '/flow/owner' => 'flow-owner.html',
];
if (isset($flows[$path])) {
    $file = __DIR__ . '/fixtures/' . $flows[$path];
    if (!is_file($file)) {
        http_response_code(404);
        echo 'saknas';
        return true;
    }
    header('Content-Type: text/html; charset=utf-8');
    readfile($file);
    return true;
}

return false;
