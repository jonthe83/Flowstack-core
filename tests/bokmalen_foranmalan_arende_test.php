<?php

declare(strict_types=1);

/**
 * Async ordrar.bestall_result från Bokmalen stämplar Aprilice/leverans/tid.
 */

$tmp = sys_get_temp_dir() . '/jeab-bokmalen-inbound-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempdir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');

require dirname(__DIR__) . '/src/bootstrap.php';

function bi_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

jeab_ordrar_external_live(false);

$secretPath = jeab_bokmalen_ordrar_webhook_secret_path();
if (!is_dir(dirname($secretPath))) {
    mkdir(dirname($secretPath), 0775, true);
}
file_put_contents($secretPath, json_encode([
    'url' => 'https://bokmalen.example.invalid/hooks/jeab-ordrar',
    'key' => 'inbound-test-key',
], JSON_UNESCAPED_SLASHES));

$row = [
    'reminder_id' => 9201,
    'kind' => 'sol',
    'company' => 'jarfalla',
    'name' => 'Ellevio Kund',
    'natbolag' => 'ellevio',
    'steps' => [],
];
jeab_purchase_put($row);
$_SERVER['REQUEST_METHOD'] = 'POST';
$res = jeab_bokmalen_ordrar_inbound([
    'type' => 'ordrar.foranmalan_status',
    'order_id' => 9201,
    'status' => 'skickad',
    'arende' => '52 376 205',
    'at' => '2026-10-06T12:00:00+02:00',
]);
bi_assert(($res['ok'] ?? false) === true && ($res['unchanged'] ?? true) === false, 'skickad med ärende sparas');
$got = jeab_purchase_get(9201);
bi_assert(($got['foranmalan_case_status'] ?? '') === 'skickad', 'läget Skickad');
bi_assert(($got['foranmalan_arende'] ?? '') === '52376205', 'ärendenummer sparas');
bi_assert(($got['steps']['foranmald'] ?? '') === '2026-10-06', 'steget foranmald stämplas med ärendets datum');
$again = jeab_bokmalen_ordrar_inbound(['type' => 'ordrar.foranmalan_status', 'order_id' => 9201, 'status' => 'skickad', 'arende' => '52376205']);
bi_assert(($again['unchanged'] ?? false) === true, 'samma läge och ärende igen ändrar inget');
$n = jeab_bokmalen_ordrar_inbound(['type' => 'ordrar.foranmalan_status', 'order_id' => 9201, 'status' => 'godkand']);
bi_assert((jeab_purchase_get(9201)['steps']['foranmald'] ?? '') === '2026-10-06', 'senare läge skriver inte över datumet');
echo "bokmalen foranmalan arende: ok\n";
