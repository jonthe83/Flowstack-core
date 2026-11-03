<?php

declare(strict_types=1);

require_once __DIR__ . '/src/deploy_guard.php';
jeab_deploy_guard();
require_once __DIR__ . '/src/demo.php';
jeab_demo_forbid_cron();

/**
 * Microsoft Graph → Inkorg. Validering: ?validationToken= ekas som text/plain.
 * Notiser: clientState måste stämma; meddelandet hämtas från Graph på servern.
 */

if (isset($_GET['validationToken'])) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(200);
    echo substr((string) $_GET['validationToken'], 0, 2048);
    exit;
}

$GLOBALS['JEAB_NO_SESSION'] = true;
$GLOBALS['_JEAB_EXTERNAL_ALLOW'] = true;
require __DIR__ . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

$raw = file_get_contents('php://input');
$unreadable = !is_string($raw) || trim($raw) === '';
$payload = null;
if (!$unreadable) {
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        $unreadable = true;
    }
}
if ($unreadable) {
    $res = [
        'ok' => false,
        'unreadable' => true,
        'accepted' => 0,
        'rejected' => 0,
        'auth_failed' => 0,
        'jobs' => [],
        'reset' => [],
        'renew' => [],
        'removed' => [],
    ];
} else {
    $res = jeab_graph_notifications($payload);
}
try {
    jeab_graph_hook_remember($res);
} catch (Throwable) {
}
// Kön före 202. Graph får 503 och försöker igen om raden inte kan skrivas.
// Oläsbar kropp är 400, och den grenen ligger före 401 i jeab_graph_hook_http_status.
if (!empty($res['jobs'])) {
    $stored = false;
    try {
        $stored = jeab_graph_hook_enqueue($res['jobs']);
    } catch (Throwable) {
        $stored = false;
    }
    if ($stored !== true) {
        if (function_exists('jeab_log_app_error')) {
            jeab_log_app_error('inkorg', 'Graph-hook kö: notisen kunde inte köas');
        }
        http_response_code(503);
        echo json_encode(['ok' => false]);
        exit;
    }
}
$status = jeab_graph_hook_http_status($res);
http_response_code($status);
echo json_encode(['ok' => $status === 202]);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
