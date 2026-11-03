<?php

declare(strict_types=1);

/**
 * Loopia anropar med GET var 5:e minut. Formuläret kan bara sätta en URL,
 * så ?token= måste fungera. Samma anrop tar också POST (Authorization,
 * X-Webhook-Token eller body).
 *
 * Token är bara privat/jeab_tick_token (repo-secret JEAB_TICK_TOKEN).
 * Inte hookens token och inte webbshopens nyckel. sms-cron.php har sin
 * egen token och skickar fortfarande bokningsnotiser.
 *
 * Svaret är JSON: ok, ms och count per deljobb. Inga namn, adresser eller token.
 */

require_once __DIR__ . '/src/demo.php';
jeab_demo_forbid_cron();

$GLOBALS['JEAB_NO_SESSION'] = true;
$GLOBALS['_JEAB_EXTERNAL_ALLOW'] = true;
$jeabTickSelf = realpath(__FILE__);
$jeabTickScript = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
if ($jeabTickSelf !== false && $jeabTickSelf === $jeabTickScript) {
    $GLOBALS['_JEAB_PDO_BACKGROUND'] = true;
}
require __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/tick.php';

$isCli = (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg');

if (!$isCli && !jeab_cron_http_gate(static function (): bool {
    return jeab_tick_token_ok();
})) {
    exit;
}

try {
    $result = jeab_tick_run();
    $out = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($out === false) {
        $out = '{"ok":false,"error":"fel"}';
    }
    if ($isCli) {
        fwrite(STDOUT, $out . PHP_EOL);
        exit(!empty($result['ok']) ? 0 : 1);
    }
    if (!empty($result['error']) && ($result['error'] ?? '') === 'las' && empty($result['busy'])) {
        http_response_code(503);
    }
    echo $out;
} catch (Throwable $e) {
    if (function_exists('jeab_log_app_error')) {
        jeab_log_app_error('tick', $e::class);
    }
    if ($isCli) {
        fwrite(STDERR, "fel\n");
        exit(1);
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Något gick fel.'], JSON_INVALID_UTF8_SUBSTITUTE);
}
