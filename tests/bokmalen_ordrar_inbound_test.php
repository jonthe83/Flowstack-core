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
    'reminder_id' => 9101,
    'kind' => 'batteri',
    'company' => 'jarfalla',
    'name' => 'Callback Kund',
    'orderrader_saved' => true,
    'inkop_lines' => [['sku' => 'B1', 'title' => 'Batteri', 'qty' => 1, 'shop' => 'aprilice']],
    'steps' => ['bestalld' => '2026-10-01'],
];
jeab_purchase_put($row);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer inbound-test-key';
$res = jeab_bokmalen_ordrar_inbound([
    'type' => 'ordrar.bestall_result',
    'order_id' => 9101,
    'aprilice' => '2026104999',
    'leverans' => '2026-10-09',
    'bestalld_at' => '2026-10-02 11:22:00',
]);
bi_assert(($res['ok'] ?? false) === true, 'callback ok');
$saved = jeab_purchase_get(9101);
bi_assert(($saved['aprilice_order'] ?? '') === '2026104999', 'callback sparar Aprilice');
bi_assert(($saved['leverans'] ?? '') === '2026-10-09', 'callback sparar leverans');
bi_assert(($saved['bestalld_at'] ?? '') === '2026-10-02 11:22:00', 'callback sparar tid');
$html = jeab_ordrar_status_html($saved, 'jarfalla', 9101, null);
bi_assert(str_contains($html, 'Beställning klar'), 'callback gör beställningen klar');
bi_assert(!str_contains($html, 'Väntar på Bokmalen'), 'callback väntar inte');

$bad = jeab_bokmalen_ordrar_inbound(['type' => 'ordrar.bestall_result', 'order_id' => 9101]);
bi_assert(($bad['ok'] ?? true) === false, 'tom callback avvisas');

$unknown = jeab_bokmalen_ordrar_inbound(['type' => 'something.else', 'order_id' => 9101, 'aprilice' => '1']);
bi_assert(($unknown['error'] ?? '') === 'not_found', 'fel type ger not_found');

echo "bokmalen ordrar inbound: ok\n";
