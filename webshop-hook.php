<?php

declare(strict_types=1);

require_once __DIR__ . '/src/deploy_guard.php';
jeab_deploy_guard();
require_once __DIR__ . '/src/demo.php';
if (jeab_demo_mode()) {
    jeab_demo_forbid_hook();
}

/**
 * Wix «Order paid» → flowstack.example.test. Egen låg-privilegierad nyckel som bara kan trigga
 * hämtning av en order från Wix API. Orderinnehåll i anropet används aldrig.
 * URL: https://flowstack.example.test/webshop-hook.php?site=malmo|jarfalla
 * Nyckeln i X-Webshop-Key, Authorization: Bearer eller JSON-fältet key. Inte i query.
 */

$GLOBALS['JEAB_NO_SESSION'] = true;
$GLOBALS['_JEAB_EXTERNAL_ALLOW'] = true;
require __DIR__ . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST;
}

if (!jeab_webshop_webhook_key_ok($input)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Fel nyckel.']);
    exit;
}

$ids = jeab_webshop_event_ids($input);
if ($ids['order_id'] === '' || $ids['site_id'] === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'order_id eller sajt saknas.']);
    exit;
}

jeab_webshop_hook_accepted();
try {
    $store = jeab_boot();
    jeab_webshop_fetch_and_ingest($store, $ids['site_id'], $ids['order_id']);
} catch (Throwable) {
}
