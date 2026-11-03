<?php
declare(strict_types=1);
require_once __DIR__ . '/src/demo.php';
jeab_demo_forbid_cron();

$GLOBALS['JEAB_NO_SESSION'] = true;
$GLOBALS['_JEAB_EXTERNAL_ALLOW'] = true;
require __DIR__ . '/src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
jeab_reject_get_webhook_token();
try {
    $store = jeab_store();
    if (!jeab_sms_tick_token_ok()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.']);
        exit;
    }
    echo json_encode(jeab_sms_tick($store), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Något gick fel.'], JSON_INVALID_UTF8_SUBSTITUTE);
}
