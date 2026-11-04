<?php

declare(strict_types=1);

/**
 * Hosting cron entrypoint for calendar SMS + email.
 * Cron kan ofta bara GET, så ?token= fungerar. POST (Authorization,
 * X-Webhook-Token eller body) fungerar också.
 * CLI behöver ingen token. Token är suresms tick_token, inte tick.php:s
 * fil och inte webbshopens nyckel. Värdet loggas inte och ekas inte.
 */

require_once __DIR__ . '/src/demo.php';
jeab_demo_forbid_cron();

$GLOBALS['JEAB_NO_SESSION'] = true;
$GLOBALS['_JEAB_EXTERNAL_ALLOW'] = true;
$jeabCronSelf = realpath(__FILE__);
$jeabCronScript = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
if ($jeabCronSelf !== false && $jeabCronSelf === $jeabCronScript) {
    $GLOBALS['_JEAB_PDO_BACKGROUND'] = true;
}
require __DIR__ . '/src/bootstrap.php';

$isCli = (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg');

if (!$isCli && !jeab_cron_http_gate(static function (): bool {
    return jeab_sms_cron_token_ok();
})) {
    exit;
}

if (function_exists('jeab_job_overlap_acquire') && !jeab_job_overlap_acquire(2000)) {
    $busy = json_encode(['ok' => true, 'busy' => 1, 'sent' => 0, 'sent_email' => 0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($isCli) {
        fwrite(STDOUT, $busy . PHP_EOL);
        exit(0);
    }
    echo $busy;
    exit;
}

try {
    $store = jeab_store();
    $result = jeab_sms_cron_tick($store);
    $out = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($isCli) {
        fwrite(STDOUT, $out . PHP_EOL);
        exit(!empty($result['ok']) ? 0 : 1);
    }
    echo $out;
} catch (Throwable $e) {
    if ($isCli) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
    $busy = $e instanceof JeabDbBusyException;
    http_response_code($busy ? 503 : 500);
    if ($busy && !headers_sent()) {
        header('Retry-After: 3');
    }
    echo json_encode([
        'ok' => false,
        'error' => $busy ? 'Databasen är upptagen. Försök igen om en stund.' : 'Något gick fel.',
        'busy' => $busy ? 1 : 0,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} finally {
    if (function_exists('jeab_job_overlap_release')) {
        jeab_job_overlap_release();
    }
}

/** Egen cron-token (suresms tick_token). GET ?token= och POST. Inte tick.php:s fil. */
function jeab_sms_cron_token_ok(): bool
{
    $expected = trim((string) (jeab_suresms_config()['tick_token'] ?? ''));

    return jeab_cron_token_matches($expected);
}

function jeab_sms_cron_tick(JeabStore $store): array
{
    $stub = $GLOBALS['_JEAB_SMS_CRON_TICK'] ?? null;
    if ($stub instanceof Closure) {
        $result = $stub($store);
        if (!is_array($result)) {
            $result = ['ok' => false];
        }
        if (function_exists('send_guard_drain')) {
            try {
                $drain = send_guard_drain(['exclude_booking' => false]);
                $result['send_guard'] = [
                    'ran' => !empty($drain['ran']),
                    'sent' => (int) ($drain['sent'] ?? 0),
                ];
            } catch (Throwable) {
            }
        }
        return $result;
    }
    return jeab_sms_tick($store);
}
