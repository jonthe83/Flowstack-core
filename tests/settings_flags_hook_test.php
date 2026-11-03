<?php

declare(strict_types=1);

/**
 * settings.contacts_sole och data.backend_set.
 * Påhittad data. Inga hemligheter.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-flags-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('CONTACTS_SOLE');
putenv('DATA_BACKEND');
unset($_ENV['CONTACTS_SOLE'], $_ENV['DATA_BACKEND'], $GLOBALS['_JEAB_CONTACTS_SOLE'], $GLOBALS['_JEAB_DATA_BACKEND'], $GLOBALS['_JEAB_CONTACTS_SOLE_STORED'], $GLOBALS['_JEAB_DATA_BACKEND_STORED']);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function flag_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
flag_ok(str_contains($hookSrc, "=== 'settings.contacts_sole'") && str_contains($hookSrc, 'jeab_contacts_sole_setting_dispatch'), 'hook tar settings.contacts_sole');
flag_ok(str_contains($hookSrc, "=== 'data.backend_set'") && str_contains($hookSrc, 'jeab_data_backend_set_dispatch'), 'hook tar data.backend_set');

file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-flags-test']));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = '';

$store = jeab_store();
flag_ok($store instanceof JeabPdoStore, 'sqlite-lagret är öppet');
$pdo = $store->pdo();
$before = jeab_settings_value($pdo, 'sqlite', JEAB_CONTACTS_SOLE_KEY);
flag_ok($before === null && jeab_contacts_sole() === true, 'contacts_sole är på utan env och utan rad');

$denied = jeab_contacts_sole_setting_dispatch(['action' => 'settings.contacts_sole', 'on' => true, 'dry' => 0]);
flag_ok(($denied['status'] ?? 0) === 401 && empty($denied['ok']) && jeab_settings_value($pdo, 'sqlite', JEAB_CONTACTS_SOLE_KEY) === null, 'settings.contacts_sole utan token skriver inte');
$deniedBackend = jeab_data_backend_set_dispatch(['action' => 'data.backend_set', 'backend' => 'mysql', 'dry' => 0]);
flag_ok(($deniedBackend['status'] ?? 0) === 401 && jeab_data_backend_stored() === '', 'data.backend_set utan token skriver inte');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-flags-test';

$missing = jeab_contacts_sole_setting_dispatch(['action' => 'settings.contacts_sole', 'dry' => 1]);
flag_ok(empty($missing['ok']) && ($missing['status'] ?? 0) === 400 && ($missing['current'] ?? '') === '0' && jeab_settings_value($pdo, 'sqlite', JEAB_CONTACTS_SOLE_KEY) === null, 'on saknas och inget skrivs');

$dryOn = jeab_contacts_sole_setting_dispatch(['action' => 'settings.contacts_sole', 'on' => true, 'dry' => 1]);
flag_ok(!empty($dryOn['ok']) && ($dryOn['dry'] ?? false) === true && ($dryOn['written'] ?? true) === false && ($dryOn['current'] ?? '') === '0' && ($dryOn['would'] ?? '') === '1', 'dry visar av och att det skulle bli på');
flag_ok(jeab_contacts_sole() === true && jeab_settings_value($pdo, 'sqlite', JEAB_CONTACTS_SOLE_KEY) === null, 'dry lämnar kodstandarden på');

$defaultDry = jeab_contacts_sole_setting_dispatch(['action' => 'settings.contacts_sole', 'on' => 'true']);
flag_ok(!empty($defaultDry['ok']) && ($defaultDry['dry'] ?? false) === true && ($defaultDry['would'] ?? '') === '1' && jeab_settings_value($pdo, 'sqlite', JEAB_CONTACTS_SOLE_KEY) === null, 'utelämnad dry skriver inte');

$wetOn = jeab_contacts_sole_setting_dispatch(['action' => 'settings.contacts_sole', 'on' => true, 'dry' => 0]);
flag_ok(!empty($wetOn['ok']) && ($wetOn['written'] ?? false) === true && ($wetOn['current'] ?? '') === '0' && ($wetOn['would'] ?? '') === '1' && ($wetOn['value'] ?? '') === '1', 'på skriver contacts_sole=1');
flag_ok(jeab_contacts_sole() === true && jeab_settings_value($pdo, 'sqlite', JEAB_CONTACTS_SOLE_KEY) === '1', 'settings-raden slår på läsningen utan env');

$dryOff = jeab_contacts_sole_setting_dispatch(['action' => 'settings.contacts_sole', 'on' => false, 'dry' => '1']);
flag_ok(!empty($dryOff['ok']) && ($dryOff['current'] ?? '') === '1' && ($dryOff['would'] ?? '') === '0' && jeab_contacts_sole() === true, 'dry visar på och att det skulle bli av');

$wetOff = jeab_contacts_sole_setting_dispatch(['action' => 'settings.contacts_sole', 'on' => 'false', 'dry' => '0']);
flag_ok(!empty($wetOff['ok']) && ($wetOff['current'] ?? '') === '1' && ($wetOff['would'] ?? '') === '0' && ($wetOff['value'] ?? '') === '0', 'false skriver tillbaka 0');
flag_ok(jeab_contacts_sole() === false && jeab_settings_value($pdo, 'sqlite', JEAB_CONTACTS_SOLE_KEY) === '0', 'contacts_sole=0 slår av igen');

unset($GLOBALS['_JEAB_CONTACTS_SOLE_STORED']);
flag_ok(jeab_settings_put($pdo, 'sqlite', JEAB_CONTACTS_SOLE_KEY, '1'), 'raden kan sättas direkt');
unset($GLOBALS['_JEAB_CONTACTS_SOLE_STORED']);
flag_ok(jeab_contacts_sole() === true, 'direkt contacts_sole=1 läses som på');

putenv('CONTACTS_SOLE=0');
$_ENV['CONTACTS_SOLE'] = '0';
flag_ok(jeab_contacts_sole() === false, 'env 0 vinner över raden');
putenv('CONTACTS_SOLE=1');
$_ENV['CONTACTS_SOLE'] = '1';
jeab_contacts_sole_setting_put(false);
flag_ok(jeab_contacts_sole() === true && jeab_contacts_sole_stored() === '0', 'env 1 vinner över contacts_sole=0');
putenv('CONTACTS_SOLE');
unset($_ENV['CONTACTS_SOLE']);
flag_ok(jeab_contacts_sole() === false, 'utan env gäller raden 0');

$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
jeab_contacts_sole_setting_put(true);
flag_ok(jeab_contacts_sole() === false, 'testspärren vinner över raden');
unset($GLOBALS['_JEAB_CONTACTS_SOLE']);
flag_ok(jeab_contacts_sole() === true, 'utan spärren läses raden igen');
jeab_contacts_sole_setting_put(false);

$badBackend = jeab_data_backend_set_dispatch(['action' => 'data.backend_set', 'backend' => 'postgres', 'dry' => 0]);
flag_ok(empty($badBackend['ok']) && ($badBackend['status'] ?? 0) === 400 && ($badBackend['written'] ?? true) === false && jeab_data_backend_stored() === '', 'ogiltig backend skriver inte');

$dryMysql = jeab_data_backend_set_dispatch(['action' => 'data.backend_set', 'backend' => 'mysql', 'dry' => 1]);
flag_ok(!empty($dryMysql['ok']) && ($dryMysql['current'] ?? 'x') === '' && ($dryMysql['would'] ?? '') === 'mysql' && ($dryMysql['written'] ?? true) === false && jeab_data_backend_stored() === '' && !jeab_data_backend_explicit(), 'dry visar tom rad och mysql utan att skriva');

$wetMysql = jeab_data_backend_set_dispatch(['action' => 'data.backend_set', 'backend' => 'mysql', 'dry' => 0]);
flag_ok(!empty($wetMysql['ok']) && ($wetMysql['current'] ?? 'x') === '' && ($wetMysql['would'] ?? '') === 'mysql' && ($wetMysql['value'] ?? '') === 'mysql' && jeab_data_backend() === 'mysql', 'mysql skrivs i settings');

$dryJson = jeab_data_backend_set_dispatch(['action' => 'data.backend_set', 'backend' => 'json']);
flag_ok(!empty($dryJson['ok']) && ($dryJson['dry'] ?? false) === true && ($dryJson['current'] ?? '') === 'mysql' && ($dryJson['would'] ?? '') === 'json' && jeab_data_backend() === 'mysql', 'utelämnad dry lämnar mysql');

$wetJson = jeab_data_backend_set_dispatch(['action' => 'data.backend_set', 'backend' => 'JSON', 'dry' => false]);
flag_ok(!empty($wetJson['ok']) && ($wetJson['current'] ?? '') === 'mysql' && ($wetJson['would'] ?? '') === 'json' && ($wetJson['value'] ?? '') === 'json' && jeab_data_backend() === 'json' && jeab_data_backend_explicit(), 'json är vägen tillbaka');

echo "OK\n";
