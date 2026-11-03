<?php

declare(strict_types=1);

/**
 * Adressnyckel är gata + ort. En bokstav efter husnumret skrivs ihop.
 * Tre mejl och två mobilfält sparas och följer med i exporten.
 * Påhittade namn. Inget personnummer.
 */

function car(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function car_rm_tree(string $dir): void
{
    if ($dir === '' || $dir === '/' || !is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            car_rm_tree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function car_boot(string $backend, int $port): void
{
    $tmp = sys_get_temp_dir() . '/jeab-car-' . $backend . '-' . bin2hex(random_bytes(4));
    mkdir($tmp . '/secrets', 0775, true);
    register_shutdown_function(static function () use ($tmp): void {
        car_rm_tree($tmp);
    });
    $GLOBALS['_JEAB_DATA_DIR'] = $tmp;
    $GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
    $GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
    unset(
        $GLOBALS['_JEAB_CONTACTS_STRICT'],
        $GLOBALS['_JEAB_CONTACTS_SOLE'],
        $GLOBALS['_JEAB_CONTACTS_AUTO_MERGE']
    );
    putenv('JEAB_DISABLE_SQLITE=0');
    putenv('DATA_BACKEND=mysql');
    $_ENV['DATA_BACKEND'] = 'mysql';
    putenv('CONTACTS_STRICT');
    putenv('CONTACTS_SOLE');
    putenv('CONTACTS_AUTO_MERGE');
    unset($_ENV['CONTACTS_STRICT'], $_ENV['CONTACTS_SOLE'], $_ENV['CONTACTS_AUTO_MERGE']);
    $db = [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ];
    if ($backend === 'mysql') {
        if ($port < 1) {
            car(false, 'MySQL saknar port');
        }
        $db = [
            'driver' => 'mysql',
            'sqlite_path' => $tmp . '/app.sqlite',
            'json_path' => $tmp . '/store.json',
            'mysql' => [
                'host' => '127.0.0.1;port=' . $port,
                'name' => 'jeab',
                'user' => 'jeab',
                'pass' => 'jeabpass',
                'charset' => 'utf8mb4',
            ],
        ];
    }
    file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
        'timezone' => 'Europe/Stockholm',
        'db' => $db,
        'kontakter' => ['lookup_provider' => 'off'],
        'incoming_webhook_token' => 'not-the-inbox-token',
    ], true) . ";\n");
    file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-car']));
    require dirname(__DIR__) . '/src/bootstrap.php';
}

function car_scenarios(string $backend, int $port): void
{
    car_boot($backend, $port);
    $store = jeab_store();
    car($store instanceof JeabPdoStore, $backend . '-lagret finns');
    car((string) $store->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === $backend, 'lagret är ' . $backend);
    car(jeab_flag('CONTACTS_AUTO_MERGE') === false, 'CONTACTS_AUTO_MERGE är av');
    car(jeab_flag('CONTACTS_STRICT') === false, 'CONTACTS_STRICT är av');
    car(jeab_flag('CONTACTS_SOLE') === true, 'CONTACTS_SOLE är på');

    $same = jeab_contact_address_key('Testgatan 38 A', 'Teststad');
    car($same !== '' && $same === jeab_contact_address_key('Testgatan 38A', 'Teststad'), '38 A och 38A är samma nyckel');
    car($same === jeab_contact_address_key('testgatan 38 a', 'teststad'), 'nyckeln viker skiftläge och mellanslag');
    car(jeab_contact_address_key('Testgatan 38A', 'Annanort') !== $same, 'annan ort är en annan nyckel');
    car(jeab_contact_address_key('Testgatan 38A', '') === '', 'tom ort ger tom nyckel');
    car(jeab_contact_address_key('', 'Teststad') === '', 'tom gata ger tom nyckel');
    car(jeab_contact_address_key('Testgatan 38A', 'Teststad kommun') !== $same, 'kommun tas inte bort ur orten');
    car(jeab_contact_address_key('Gatan 1', 'Malmö') !== jeab_contact_address_key('Gatan 1', 'Malmo'), 'åäö viks inte');
    car(jeab_norm_street_letter('Testgatan 38 A') === 'Testgatan 38A', '38 A blir 38A');
    car(jeab_norm_street_letter('Testgatan 38 a') === 'Testgatan 38A', '38 a blir 38A');
    car(jeab_norm_street_letter('Testgatan 38a') === 'Testgatan 38A', '38a blir 38A');
    car(jeab_norm_street_letter('Testgatan 12 B lgh 1101') === 'Testgatan 12B lgh 1101', 'lägenhet lämnas efter bokstaven');
    car(jeab_norm_street_letter('Brovagen 38 BRO') === 'Brovagen 38 BRO', '38 BRO är oförändrat');

    $saved = jeab_kontakt_save_input([
        'owner_name_1' => 'Ada Full',
        'contact_person' => '1',
        'street' => 'Testgatan 38 A',
        'postal_code' => '11122',
        'city' => 'Teststad',
        'company' => 'jarfalla',
        'email' => 'ada.full@example.test',
        'email2' => 'ada.tva@example.test',
        'email3' => 'ada.tre@example.test',
        'phone' => '0701111111',
        'phone2' => '0702222222',
    ]);
    $id = (string) ($saved['id'] ?? '');
    car($id !== '', 'kontakten sparades');
    $row = jeab_kontakt_get($id);
    car(is_array($row), 'kontakten kan läsas');
    car((string) ($row['street'] ?? '') === 'Testgatan 38A', 'sparad gata är Testgatan 38A');
    car(jeab_kontakt_row_email($row) === 'ada.full@example.test', 'första mejlet ligger kvar');
    car(jeab_kontakt_email((string) ($row['email2'] ?? '')) === 'ada.tva@example.test', 'andra mejlet ligger kvar');
    car(jeab_kontakt_email((string) ($row['email3'] ?? '')) === 'ada.tre@example.test', 'tredje mejlet ligger kvar');
    car(jeab_kontakt_row_phone($row) !== '' && jeab_kontakt_phone((string) ($row['phone2'] ?? '')) !== '', 'båda mobilfälten är ifyllda');
    car(jeab_kontakt_phone((string) ($row['phone2'] ?? '')) !== jeab_kontakt_row_phone($row), 'mobilerna är olika');

    $bro = jeab_kontakt_save_input([
        'owner_name_1' => 'Bro Bro',
        'contact_person' => '1',
        'street' => 'Brovagen 38 BRO',
        'postal_code' => '33344',
        'city' => 'Brostad',
        'company' => 'jarfalla',
        'email' => 'bro.bro@example.test',
        'phone' => '0703333333',
    ]);
    $broId = (string) ($bro['id'] ?? '');
    $broRow = jeab_kontakt_get($broId);
    car(is_array($broRow) && (string) ($broRow['street'] ?? '') === 'Brovagen 38 BRO', '38 BRO sparas oförändrat');

    $export = jeab_kontakter_text_export(['limit' => 50]);
    $hit = null;
    $broHit = null;
    foreach (is_array($export['contacts'] ?? null) ? $export['contacts'] : [] as $item) {
        if (!is_array($item)) {
            continue;
        }
        if ((string) ($item['contact_id'] ?? '') === $id) {
            $hit = $item;
        }
        if ((string) ($item['contact_id'] ?? '') === $broId) {
            $broHit = $item;
        }
    }
    car(is_array($hit) && is_array($broHit), 'exporten har båda kontakterna');
    foreach (['email', 'email2', 'email3', 'phone', 'phone2'] as $key) {
        car(array_key_exists($key, $hit) && array_key_exists($key, $broHit), 'exporten har ' . $key);
    }
    car($hit['email'] === jeab_kontakt_row_email($row), 'exportens mejl är det sparade');
    car($hit['email2'] === jeab_kontakt_email((string) ($row['email2'] ?? '')), 'exportens mejl 2 är det sparade');
    car($hit['email3'] === jeab_kontakt_email((string) ($row['email3'] ?? '')), 'exportens mejl 3 är det sparade');
    car($hit['phone'] === jeab_kontakter_export_phone($row), 'exportens mobil är den sparade');
    car($hit['phone2'] === jeab_kontakt_phone((string) ($row['phone2'] ?? '')), 'exportens mobil 2 är den sparade');
    car($hit['email2'] !== '' && $hit['email3'] !== '' && $hit['phone2'] !== '', 'tre mejl och två mobiler exporteras');
    car($hit['street'] === (string) ($row['street'] ?? ''), 'exportens gata är den sparade');
}

function car_mariadb(): ?array
{
    if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
        echo "SKIP MySQL: pdo_mysql saknas\n";

        return null;
    }
    $installBin = trim((string) shell_exec('command -v mariadb-install-db 2>/dev/null'));
    $serverBin = trim((string) shell_exec('command -v mariadbd 2>/dev/null'));
    if ($installBin === '' || $serverBin === '') {
        echo "SKIP MySQL: MariaDB saknas\n";

        return null;
    }
    $tmp = sys_get_temp_dir() . '/jeab-car-mysql-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0775, true);
    $port = 35300 + random_int(0, 1500);
    $data = $tmp . '/db';
    $sock = $tmp . '/mysql.sock';
    $pidFile = $tmp . '/mysql.pid';
    $init = $tmp . '/init.sql';
    $log = $tmp . '/mysql.log';
    file_put_contents($init, <<<SQL
CREATE DATABASE IF NOT EXISTS jeab CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'jeab'@'127.0.0.1' IDENTIFIED BY 'jeabpass';
GRANT ALL PRIVILEGES ON jeab.* TO 'jeab'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL);
    $holder = new stdClass();
    $holder->proc = null;
    $holder->tmp = $tmp;
    register_shutdown_function(static function () use ($holder): void {
        $proc = $holder->proc;
        if (is_resource($proc)) {
            proc_terminate($proc);
            for ($i = 0; $i < 20; $i++) {
                $status = proc_get_status($proc);
                if (empty($status['running'])) {
                    break;
                }
                usleep(100000);
            }
            $status = proc_get_status($proc);
            if (!empty($status['running'])) {
                proc_terminate($proc, 9);
            }
            proc_close($proc);
            $holder->proc = null;
        }
        for ($try = 0; $try < 5 && is_dir($holder->tmp); $try++) {
            car_rm_tree($holder->tmp);
            if (is_dir($holder->tmp)) {
                usleep(100000);
            }
        }
    });
    $install = sprintf(
        'mariadb-install-db --datadir=%s --auth-root-authentication-method=normal --skip-test-db >%s 2>&1',
        escapeshellarg($data),
        escapeshellarg($log)
    );
    exec($install, $unused, $installCode);
    if ($installCode !== 0) {
        fwrite(STDERR, "FAIL MariaDB kunde inte initieras\n");
        exit(1);
    }
    $cmd = sprintf(
        'mariadbd --datadir=%s --bind-address=127.0.0.1 --port=%d --socket=%s --pid-file=%s --init-file=%s',
        escapeshellarg($data),
        $port,
        escapeshellarg($sock),
        escapeshellarg($pidFile),
        escapeshellarg($init)
    );
    $proc = proc_open($cmd, [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
    if (!is_resource($proc)) {
        fwrite(STDERR, "FAIL MariaDB startade inte\n");
        exit(1);
    }
    $holder->proc = $proc;
    $up = false;
    for ($i = 0; $i < 50; $i++) {
        try {
            new PDO('mysql:host=127.0.0.1;port=' . $port . ';dbname=jeab;charset=utf8mb4', 'jeab', 'jeabpass', [PDO::ATTR_TIMEOUT => 1]);
            $up = true;
            break;
        } catch (Throwable) {
            usleep(200000);
        }
    }
    if (!$up) {
        fwrite(STDERR, "FAIL MariaDB svarade inte\n" . (string) file_get_contents($log) . "\n");
        exit(1);
    }

    return ['port' => $port];
}

function car_child(string $backend, int $port): void
{
    putenv('JEAB_CAR_CHILD=' . $backend);
    putenv('JEAB_CAR_PORT=' . (string) $port);
    $outFile = tempnam(sys_get_temp_dir(), 'car-out-');
    $errFile = tempnam(sys_get_temp_dir(), 'car-err-');
    if (!is_string($outFile) || !is_string($errFile)) {
        fwrite(STDERR, "FAIL kunde inte skapa logg\n");
        exit(1);
    }
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__);
    $proc = proc_open($cmd, [1 => ['file', $outFile, 'w'], 2 => ['file', $errFile, 'w']], $pipes);
    if (!is_resource($proc)) {
        @unlink($outFile);
        @unlink($errFile);
        fwrite(STDERR, "FAIL kunde inte starta {$backend}\n");
        exit(1);
    }
    $code = proc_close($proc);
    $out = (string) file_get_contents($outFile);
    $err = (string) file_get_contents($errFile);
    @unlink($outFile);
    @unlink($errFile);
    putenv('JEAB_CAR_CHILD');
    putenv('JEAB_CAR_PORT');
    echo $out;
    if ($err !== '') {
        fwrite(STDERR, $err);
    }
    if ($code !== 0) {
        exit($code > 0 ? $code : 1);
    }
}

$carChild = getenv('JEAB_CAR_CHILD');
$carChild = is_string($carChild) ? $carChild : '';
if ($carChild === 'sqlite' || $carChild === 'mysql') {
    car_scenarios($carChild, (int) getenv('JEAB_CAR_PORT'));
    exit(0);
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

car_child('sqlite', 0);
$carDb = car_mariadb();
if ($carDb !== null) {
    car_child('mysql', $carDb['port']);
}

echo "ALL OK\n";
