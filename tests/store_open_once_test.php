<?php

declare(strict_types=1);

/**
 * JSON-läget får inte öppna jeab_store() i en loop.
 * Ett återinträdande anrop medan butiken öppnas får en otillgänglig butik,
 * och _JEAB_STORE_OPENING nollställs när öppningen är klar.
 */

ini_set('memory_limit', '64M');

$tmp = sys_get_temp_dir() . '/jeab-store-open-' . bin2hex(random_bytes(4));
mkdir($tmp . '/private', 0775, true);
mkdir($tmp . '/public', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp . '/private';
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp . '/public';
putenv('JEAB_DISABLE_SQLITE=1');

require dirname(__DIR__) . '/src/bootstrap.php';

function so(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

so(jeab_driver() === 'json', 'sqlite av ger json-läge');

$GLOBALS['_JEAB_STORE_OPENING'] = true;
$reentered = jeab_store();
so($reentered instanceof JeabUnavailableStore, 'återinträde medan butiken öppnas ger en otillgänglig butik');
so($GLOBALS['_JEAB_STORE_OPENING'] === true, 'återinträdet startar inte en ny öppning');
$GLOBALS['_JEAB_STORE_OPENING'] = false;

$thrown = '';
try {
    jeab_store();
} catch (RuntimeException $e) {
    $thrown = $e->getMessage();
}
so($thrown === 'Databasen är inte öppen.', 'json-läget kastar i stället för store.json');
so(!is_file($tmp . '/private/store.json'), 'store.json skapas inte');
so($GLOBALS['_JEAB_STORE_OPENING'] === false, '_JEAB_STORE_OPENING nollställs i finally');
so(jeab_driver() === 'json', 'drivern stannar i json-läge');

echo "ok\n";
