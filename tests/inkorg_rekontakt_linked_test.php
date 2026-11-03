<?php

declare(strict_types=1);

/**
 * inbox.rekontakt skriver inte customer_email eller customer_name på en tråd
 * som redan har contact_id. Olänkad tråd skrivs som förut. Inget skickas.
 * Påhittade namn och example.test-adresser.
 */

$tmp = sys_get_temp_dir() . '/jeab-rekontakt-linked-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function rk(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$ingest = static function (string $id, string $email, string $name): string {
    $r = jeab_inkorg_ingest([
        'message_id' => $id . '@example.test',
        'from' => $name . ' <' . $email . '>',
        'from_name' => $name,
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Offert',
        'body_text' => "Hej, vi vill ha en offert på laddbox.\n",
        'source' => 'graph-jarfalla',
        'thread_key' => 'rek-' . $id,
        'test' => true,
    ]);
    rk(!empty($r['ok']) && (string) ($r['thread_id'] ?? '') !== '', $id . ' intagen');

    return (string) $r['thread_id'];
};

$linked = $ingest('lina', 'lina.lank@example.test', 'Lina Lank');
$free = $ingest('olle', 'olle.fri@example.test', 'Olle Fri');

jeab_inkorg_thread_write($linked, static function (array $t): array {
    $t['contact_id'] = 'c-lina-1';
    $t['customer_email'] = 'gammal.lina@example.test';
    $t['customer_name'] = 'Gammal Lina';
    $t['customer_source'] = 'behall';

    return $t;
});
jeab_inkorg_thread_write($free, static function (array $t): array {
    $t['customer_email'] = 'fore.olle@example.test';
    $t['customer_name'] = 'Fore Olle';
    $t['customer_source'] = 'fore';
    unset($t['contact_id']);

    return $t;
});
rk(trim((string) (jeab_inkorg_thread_meta($linked)['contact_id'] ?? '')) === 'c-lina-1', 'contact_id ligger på trådens metadata');
rk(trim((string) (jeab_inkorg_thread_meta($free)['contact_id'] ?? '')) === '', 'den andra tråden är olänkad');

$dry = jeab_inkorg_hook(['action' => 'inkorg.rekontakt']);
$dryJson = (string) json_encode($dry);
rk(($dry['ok'] ?? false) === true && ($dry['dry'] ?? false) === true && ($dry['skipped_linked'] ?? 0) === 1, 'torrkörning räknar den länkade tråden');
rk(!str_contains($dryJson, 'lina.lank') && !str_contains($dryJson, 'Gammal Lina') && !str_contains($dryJson, 'gammal.lina'), 'torrkörning listar inte den länkade');
$linkedMeta = jeab_inkorg_thread_meta($linked);
rk(($linkedMeta['customer_email'] ?? '') === 'gammal.lina@example.test' && ($linkedMeta['customer_name'] ?? '') === 'Gammal Lina' && ($linkedMeta['customer_source'] ?? '') === 'behall', 'torrkörning skriver inte den länkade');
rk((jeab_inkorg_thread_meta($free)['customer_email'] ?? '') === 'fore.olle@example.test', 'torrkörning skriver inte den olänkade');

$apply = jeab_inkorg_hook(['action' => 'inbox.rekontakt', 'dry' => 0]);
$applyJson = (string) json_encode($apply);
rk(($apply['ok'] ?? false) === true && ($apply['dry'] ?? true) === false && ($apply['skipped_linked'] ?? 0) === 1, 'dry=0 hoppar över den länkade');
$hit = false;
$linkedListed = false;
foreach ((array) ($apply['changes'] ?? []) as $ch) {
    if (!is_array($ch)) {
        continue;
    }
    if (($ch['thread'] ?? '') === $linked) {
        $linkedListed = true;
    }
    if (($ch['thread'] ?? '') === $free) {
        $hit = ($ch['old'] ?? '') === 'fore.olle@example.test' && ($ch['new'] ?? '') === 'olle.fri@example.test';
    }
}
rk($hit && !$linkedListed, 'ändringarna har den olänkade tråden och inte den länkade');
rk(!str_contains($applyJson, 'lina.lank') && !str_contains($applyJson, 'Gammal Lina') && !str_contains($applyJson, 'gammal.lina'), 'svaret listar inte den länkade trådens värden');
$linkedMeta = jeab_inkorg_thread_meta($linked);
rk(($linkedMeta['customer_email'] ?? '') === 'gammal.lina@example.test' && ($linkedMeta['customer_name'] ?? '') === 'Gammal Lina' && ($linkedMeta['customer_source'] ?? '') === 'behall' && ($linkedMeta['contact_id'] ?? '') === 'c-lina-1', 'länkad tråd skrivs inte');
$freeMeta = jeab_inkorg_thread_meta($free);
rk(($freeMeta['customer_email'] ?? '') === 'olle.fri@example.test' && ($freeMeta['customer_name'] ?? '') === 'Olle Fri' && trim((string) ($freeMeta['contact_id'] ?? '')) === '', 'olänkad tråd skrivs som förut');

echo "ALL OK\n";
