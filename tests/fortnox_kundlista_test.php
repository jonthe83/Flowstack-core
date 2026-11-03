<?php

declare(strict_types=1);

/** Kundlista: pagering, kredit dras av, makulerade och utanför perioden räknas inte. */

$tmp = sys_get_temp_dir() . '/jeab-kl-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export(['timezone' => 'Europe/Stockholm', 'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json']], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox.php';

function kl(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$calls = [];
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = function (string $method, string $path) use (&$calls): array {
    $calls[] = $method . ' ' . $path;
    if ($method !== 'GET') {
        throw new Exception('write');
    }
    if (str_starts_with($path, '/customers')) {
        return str_contains($path, 'page=1')
            ? ['MetaInformation' => ['@TotalPages' => 2], 'Customers' => [['CustomerNumber' => '1', 'Name' => 'A', 'City' => 'Järfälla', 'Email' => 'a@x.se']]]
            : ['MetaInformation' => ['@TotalPages' => 2], 'Customers' => [['CustomerNumber' => '2', 'Name' => 'B', 'City' => 'Malmö', 'Email' => 'b@x.se']]];
    }
    return ['MetaInformation' => ['@TotalPages' => 1], 'Invoices' => [
        ['CustomerNumber' => '1', 'InvoiceDate' => '2024-01-01', 'Total' => 1000, 'Cancelled' => false, 'Credit' => false],
        ['CustomerNumber' => '1', 'InvoiceDate' => '2024-02-01', 'Total' => -200, 'Cancelled' => false, 'Credit' => true],
        ['CustomerNumber' => '1', 'InvoiceDate' => '2024-03-01', 'Total' => 5000, 'Cancelled' => true, 'Credit' => false],
        ['CustomerNumber' => '2', 'InvoiceDate' => '2024-01-01', 'Total' => 3000, 'Cancelled' => false, 'Credit' => false],
        ['CustomerNumber' => '2', 'InvoiceDate' => '2018-01-01', 'Total' => 9999, 'Cancelled' => false, 'Credit' => false],
    ]];
};
$r = jeab_fortnox_customer_totals('Jarfalla', '2019-10-15', '2026-10-15', 0);
kl(count($r['rows']) === 2, 'båda kunder över två sidor');
kl($r['rows'][0]['kundnr'] === '2' && $r['rows'][0]['total'] === 3000.0, 'sorterad fallande, utanför perioden ignoreras');
kl($r['rows'][1]['total'] === 800.0, 'kredit dras av, makulerad räknas inte');
kl($r['makulerade'] === 1, 'makulerade räknas');
kl(count(array_filter($calls, fn ($c) => !str_starts_with($c, 'GET'))) === 0, 'bara GET');
kl((bool) array_filter($calls, fn ($c) => str_contains($c, 'fromdate=2019-10-15')), 'fromdate skickas');
echo "Alla OK\n";
