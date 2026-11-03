<?php

declare(strict_types=1);

/** H-INK-9: samma ordernummer i två bolag länkas till trådens bolag. */

$tmp = sys_get_temp_dir() . '/jeab-case-co-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ccl(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

jeab_purchase_put([
    'reminder_id' => 51001,
    'company' => 'jarfalla',
    'address' => 'Järfällavägen 1',
    'order_number' => 'SAME-77',
    'kind' => 'batteri',
]);
jeab_purchase_put([
    'reminder_id' => 51002,
    'company' => 'malmo',
    'address' => 'Malmövägen 2',
    'order_number' => 'SAME-77',
    'kind' => 'batteri',
]);

$malmo = jeab_inkorg_case_link_order([
    'company' => 'malmo',
    'subject' => 'Restnoterad',
    'body' => "Ordernummer: SAME-77\nRestnoterad, ej i lager\n",
]);
ccl((int) ($malmo['reminder_id'] ?? 0) === 51002 && ($malmo['via'] ?? '') === 'order', 'Malmö-ärendet länkas till Malmös order, fick ' . (int) ($malmo['reminder_id'] ?? 0));

$jarfalla = jeab_inkorg_case_link_order([
    'source' => 'graph-jarfalla',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Restnoterad',
    'body' => "Ordernummer: SAME-77\nEj i lager\n",
]);
ccl((int) ($jarfalla['reminder_id'] ?? 0) === 51001 && ($jarfalla['via'] ?? '') === 'order', 'Järfälla-mejlet länkas till Järfällas order, fick ' . (int) ($jarfalla['reminder_id'] ?? 0));

$otherAddress = jeab_inkorg_case_link_order([
    'company' => 'jarfalla',
    'subject' => 'Restnoterad',
    'body' => "Ordernummer: SAKNAS-9\nMalmövägen 2\n",
]);
ccl((int) ($otherAddress['reminder_id'] ?? 0) === 0, 'adressen i det andra bolaget länkas inte');

echo "case company link: ok\n";
