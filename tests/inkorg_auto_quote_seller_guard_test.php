<?php

declare(strict_types=1);

/** Domänspärren för köpta leads. Omtago med kundens egen privatpost är undantaget. Inget skickas. */

$tmp = sys_get_temp_dir() . '/jeab-seller-guard-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function sg(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$bought = [
    'from' => 'kopt.kund@gmail.com',
    'source' => 'graph-jarfalla',
    'labels' => ['lead' => 'kopt'],
    'headers' => ['From' => 'kopt.kund@gmail.com'],
];
$hold = jeab_auto_quote_seller_guard($bought, 'kopt.kund@gmail.com');
sg($hold === 'mottagaren har samma domän som leadsäljaren', 'köpt gmail-lead hålls, fick ' . $hold);

$omtago = [
    'from' => 'nora.kund@gmail.com',
    'source' => 'omtago',
    'labels' => ['source' => 'omtago', 'lead' => 'kopt'],
    'headers' => ['From' => 'nora.kund@gmail.com'],
];
sg(jeab_auto_quote_seller_guard($omtago, 'nora.kund@gmail.com') === '', 'Omtago med kundens gmail släpps');

$labelOnly = $omtago;
$labelOnly['source'] = 'graph-jarfalla';
sg(jeab_auto_quote_seller_guard($labelOnly, 'nora.kund@gmail.com') === '', 'etiketten source=omtago räcker');

$company = $omtago;
$company['from'] = 'kund@firma.example';
$company['headers'] = ['From' => 'kund@firma.example'];
$companyHold = jeab_auto_quote_seller_guard($company, 'kund@firma.example');
sg($companyHold === 'mottagaren har samma domän som leadsäljaren', 'Omtago på företagsdomän hålls, fick ' . $companyHold);

$plain = [
    'from' => 'eget.kund@gmail.com',
    'source' => 'graph-jarfalla',
    'labels' => ['lead' => 'eget'],
    'headers' => ['From' => 'eget.kund@gmail.com'],
];
sg(jeab_auto_quote_seller_guard($plain, 'eget.kund@gmail.com') === '', 'eget lead utan köpt-flagga stoppas inte');

echo "seller guard: ok\n";
