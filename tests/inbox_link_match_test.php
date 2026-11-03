<?php

declare(strict_types=1);

/**
 * inbox.link_contacts match=phone_address. E.164, telefon, adress, inte bara
 * namn, flera är tvetydigt, annat bolag räknas inte. confirm och undo.
 * Påhittade namn och example.test. Inga värden i svaret.
 */

function ilm(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function ilm_rm_tree(string $dir): void
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
            ilm_rm_tree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function ilm_cols(PDO $pdo, string $table): array
{
    $have = [];
    try {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $stmt = $pdo->query('SHOW COLUMNS FROM `' . $table . '`');
            $nameKey = 'Field';
        } else {
            $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
            $nameKey = 'name';
        }
    } catch (Throwable) {
        return [];
    }
    foreach ($stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
        if (is_array($row) && isset($row[$nameKey])) {
            $have[(string) $row[$nameKey]] = true;
        }
    }

    return $have;
}

function ilm_add(PDO $pdo, string $table, string $column): void
{
    $cols = ilm_cols($pdo, $table);
    if ($cols === [] || isset($cols[$column])) {
        return;
    }
    $mysql = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $type = $mysql ? "VARCHAR(191) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
    if ($column === 'row_json') {
        $type = $mysql ? 'LONGTEXT NOT NULL' : 'TEXT NOT NULL';
    }
    $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $type);
}

function ilm_cid(PDO $pdo, string $table, string $id): string
{
    $stmt = $pdo->prepare('SELECT contact_id FROM ' . $table . ' WHERE id = ?');
    $stmt->execute([$id]);

    return trim((string) $stmt->fetchColumn());
}

/** @param list<array<string, mixed>> $rows */
function ilm_has(array $rows, string $id): bool
{
    foreach ($rows as $row) {
        if (is_array($row) && (string) ($row['thread_id'] ?? '') === $id) {
            return true;
        }
    }

    return false;
}

/** @param array<string, mixed> $report */
function ilm_clean(array $report): void
{
    $json = (string) json_encode($report, JSON_UNESCAPED_UNICODE);
    ilm(!str_contains($json, '@'), 'svaret har ingen e-post');
    foreach (['Phonesson', 'Gatesson', 'Matchgatan', 'Namnsson', 'example.test', '0701234567', '070-123'] as $secret) {
        ilm(!str_contains($json, $secret), 'svaret har inget namn eller adressfragment');
    }
}

function ilm_boot(string $backend, int $port): void
{
    $tmp = sys_get_temp_dir() . '/jeab-inbox-match-' . $backend . '-' . bin2hex(random_bytes(4));
    mkdir($tmp . '/secrets', 0775, true);
    mkdir($tmp . '/inkorg', 0775, true);
    $public = $tmp . '/public-data';
    mkdir($public, 0775, true);
    register_shutdown_function(static function () use ($tmp): void {
        ilm_rm_tree($tmp);
    });
    $GLOBALS['_JEAB_DATA_DIR'] = $tmp;
    $GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $public;
    putenv('JEAB_DISABLE_SQLITE=0');
    putenv('CONTACTS_SOLE');
    putenv('CONTACTS_STRICT');
    unset(
        $_ENV['CONTACTS_SOLE'],
        $_ENV['CONTACTS_STRICT'],
        $GLOBALS['_JEAB_CONTACTS_SOLE'],
        $GLOBALS['_JEAB_CONTACTS_STRICT'],
        $GLOBALS['_JEAB_CONTACTS_SOLE_STORED']
    );
    $db = [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ];
    if ($backend === 'mysql') {
        if ($port < 1) {
            ilm(false, 'MySQL saknar port');
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
    file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-inbox-match']));
    require dirname(__DIR__) . '/src/bootstrap.php';
}

function ilm_scenarios(string $backend, int $port): void
{
    ilm_boot($backend, $port);
    $root = dirname(__DIR__);
    $src = (string) file_get_contents($root . '/src/normalize.php');
    ilm(str_contains($src, 'function jeab_norm_phone_e164'), 'E.164-hjälparen finns');
    ilm(jeab_norm_phone_e164('0701234567') === '+46701234567', '0701234567 blir E.164');
    ilm(jeab_norm_phone_e164('070-123 45 67') === '+46701234567', '070-123 45 67 blir E.164');
    ilm(jeab_norm_phone_e164('+46701234567') === '+46701234567', '+46 redan E.164');
    ilm(jeab_norm_phone_e164('0046701234567') === '+46701234567', '0046 blir E.164');
    ilm(jeab_norm_phone_e164('') === '', 'tomt nummer är tomt');

    $store = jeab_store();
    ilm($store instanceof JeabPdoStore, $backend . '-lagret är öppet');
    $pdo = $store->pdo();
    ilm((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === $backend, 'lagret är ' . $backend);
    $mysql = $backend === 'mysql';
    if (ilm_cols($pdo, 'contacts') === []) {
        if ($mysql) {
            $pdo->exec("CREATE TABLE contacts (
                id VARCHAR(32) NOT NULL,
                row_json LONGTEXT NOT NULL,
                company VARCHAR(32) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            $pdo->exec("CREATE TABLE contacts (
                id TEXT PRIMARY KEY,
                row_json TEXT NOT NULL DEFAULT '{}',
                company TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL DEFAULT '',
                updated_at TEXT NOT NULL DEFAULT ''
            )");
        }
    }
    ilm_add($pdo, 'contacts', 'row_json');
    ilm_add($pdo, 'contacts', 'company');
    if (ilm_cols($pdo, 'inkorg_threads') === []) {
        if ($mysql) {
            $pdo->exec("CREATE TABLE inkorg_threads (
                id VARCHAR(191) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'open',
                updated_at DATETIME NULL,
                row_json LONGTEXT NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            $pdo->exec("CREATE TABLE inkorg_threads (
                id TEXT PRIMARY KEY,
                status TEXT NOT NULL DEFAULT 'open',
                updated_at TEXT,
                row_json TEXT NOT NULL
            )");
        }
    }
    ilm_add($pdo, 'inkorg_threads', 'contact_id');
    ilm_add($pdo, 'inkorg_threads', 'row_json');
    if (ilm_cols($pdo, 'inkorg_list') === []) {
        if ($mysql) {
            $pdo->exec("CREATE TABLE inkorg_list (
                id VARCHAR(191) NOT NULL,
                company VARCHAR(32) NOT NULL DEFAULT '',
                customer_email VARCHAR(191) NOT NULL DEFAULT '',
                contact_id VARCHAR(191) NOT NULL DEFAULT '',
                row_json LONGTEXT NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            $pdo->exec("CREATE TABLE inkorg_list (
                id TEXT PRIMARY KEY,
                company TEXT NOT NULL DEFAULT '',
                customer_email TEXT NOT NULL DEFAULT '',
                contact_id TEXT NOT NULL DEFAULT '',
                row_json TEXT NOT NULL
            )");
        }
    }
    ilm_add($pdo, 'inkorg_list', 'company');
    ilm_add($pdo, 'inkorg_list', 'customer_email');
    ilm_add($pdo, 'inkorg_list', 'contact_id');
    ilm_add($pdo, 'inkorg_list', 'row_json');

    $stamp = '2026-01-01 00:00:00';
    $contactSql = 'INSERT INTO contacts (id, row_json, company) VALUES (?, ?, ?)';
    if (isset(ilm_cols($pdo, 'contacts')['created_at'])) {
        $contactSql = 'INSERT INTO contacts (id, row_json, company, created_at, updated_at) VALUES (?, ?, ?, ?, ?)';
    }
    $insertContact = $pdo->prepare($contactSql);
    $addContact = static function (string $id, string $company, array $extra) use ($insertContact, $stamp, $pdo): void {
        $name = (string) ($extra['name'] ?? 'Namnsson');
        $body = json_encode(array_merge([
            'id' => $id,
            'name' => $name,
            'company' => $company,
            'owners' => [[
                'name' => $name,
                'email' => (string) ($extra['email'] ?? ''),
                'phone' => (string) ($extra['phone'] ?? ''),
                'contact' => true,
            ]],
        ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        ilm(is_string($body), 'kontakten kan kodas');
        if (isset(ilm_cols($pdo, 'contacts')['created_at'])) {
            $insertContact->execute([$id, $body, $company, $stamp, $stamp]);
        } else {
            $insertContact->execute([$id, $body, $company]);
        }
    };
    $addContact('kt_phone', 'jarfalla', [
        'name' => 'Phonesson En',
        'email' => 'phone.en@example.test',
        'phone' => '0701234567',
    ]);
    $addContact('kt_twin_a', 'jarfalla', [
        'name' => 'Phonesson Tva',
        'email' => 'phone.tva@example.test',
        'phone' => '0707654321',
    ]);
    $addContact('kt_twin_b', 'jarfalla', [
        'name' => 'Phonesson Tre',
        'email' => 'phone.tre@example.test',
        'phone' => '0707654321',
    ]);
    $addContact('kt_addr', 'jarfalla', [
        'name' => 'Gatesson En',
        'email' => 'gata.en@example.test',
        'phone' => '0701112233',
        'street' => 'Matchgatan 1',
        'postal_code' => '12345',
    ]);
    $addContact('kt_name', 'jarfalla', [
        'name' => 'Namnsson Samma',
        'email' => 'namn.samma@example.test',
        'phone' => '0709998877',
        'street' => 'Annan gata 9',
        'postal_code' => '11111',
    ]);
    $addContact('kt_malmo_phone', 'malmo', [
        'name' => 'Phonesson Malmo',
        'email' => 'phone.malmo@example.test',
        'phone' => '0701234567',
    ]);

    $list = $pdo->prepare('INSERT INTO inkorg_list (id, company, customer_email, contact_id, row_json) VALUES (?, ?, ?, ?, ?)');
    $rowJson = static function (array $extra): string {
        $json = json_encode(array_merge(['note' => 'trad-behall'], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '{}';
    };
    $list->execute(['th-phone', 'jarfalla', 'saknas.phone@example.test', '', $rowJson([
        'company' => 'jarfalla',
        'lead_customer' => ['name' => 'Phonesson En', 'phone' => '070-123 45 67'],
    ])]);
    $list->execute(['th-addr', 'jarfalla', 'saknas.gata@example.test', '', $rowJson([
        'company' => 'jarfalla',
        'lead_customer' => ['name' => 'Gatesson En', 'address' => 'Matchgatan 1, 123 45 Teststad'],
    ])]);
    $list->execute(['th-name', 'jarfalla', 'saknas.namn@example.test', '', $rowJson([
        'company' => 'jarfalla',
        'lead_customer' => ['name' => 'Namnsson Samma'],
        'customer_name' => 'Namnsson Samma',
    ])]);
    $list->execute(['th-amb-phone', 'jarfalla', 'saknas.twin@example.test', '', $rowJson([
        'company' => 'jarfalla',
        'lead_customer' => ['phone' => '0046707654321'],
    ])]);
    $list->execute(['th-other-phone', 'jarfalla', 'saknas.annan@example.test', '', $rowJson([
        'company' => 'jarfalla',
        'lead_customer' => ['phone' => '+46701234567'],
    ])]);
    $list->execute(['th-email', 'jarfalla', 'phone.en@example.test', '', $rowJson(['company' => 'jarfalla'])]);

    $thread = $pdo->prepare('INSERT INTO inkorg_threads (id, status, updated_at, row_json, contact_id) VALUES (?, ?, ?, ?, ?)');
    $thread->execute(['th-phone', 'open', $stamp, $rowJson(['company' => 'jarfalla']), '']);
    $thread->execute(['th-addr', 'open', $stamp, $rowJson(['company' => 'jarfalla']), '']);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-inbox-match';

    $badMatch = jeab_contacts_sole_dispatch(['action' => 'inbox.link_contacts', 'match' => 'namn']);
    ilm(($badMatch['ok'] ?? true) === false && ($badMatch['status'] ?? 0) === 400, 'okänt match är 400');
    ilm_clean($badMatch);

    $emailMode = jeab_contacts_sole_dispatch(['action' => 'inbox.link_contacts']);
    ilm(($emailMode['ok'] ?? false) === true, 'match=email är standard');
    ilm(ilm_has($emailMode['would_link'] ?? [], 'th-email'), 'e-postträff länkas i email-läge');
    ilm(!ilm_has($emailMode['would_link'] ?? [], 'th-phone'), 'telefonträff länkas inte i email-läge');
    ilm_clean($emailMode);

    $dry = jeab_contacts_sole_dispatch(['action' => 'inbox.link_contacts', 'match' => 'phone_address']);
    ilm(($dry['ok'] ?? false) === true && ($dry['dry'] ?? false) === true && ($dry['written'] ?? true) === false, 'phone_address dry skriver inte');
    $phoneRow = null;
    foreach ($dry['would_link'] ?? [] as $row) {
        if (is_array($row) && ($row['thread_id'] ?? '') === 'th-phone') {
            $phoneRow = $row;
        }
    }
    ilm(is_array($phoneRow) && ($phoneRow['contact_id'] ?? '') === 'kt_phone' && ($phoneRow['match_by'] ?? '') === 'phone', 'telefonträff länkas med match_by phone');
    $addrRow = null;
    foreach ($dry['would_link'] ?? [] as $row) {
        if (is_array($row) && ($row['thread_id'] ?? '') === 'th-addr') {
            $addrRow = $row;
        }
    }
    ilm(is_array($addrRow) && ($addrRow['contact_id'] ?? '') === 'kt_addr' && ($addrRow['match_by'] ?? '') === 'address', 'adressträff länkas med match_by address');
    $emailRow = null;
    foreach ($dry['would_link'] ?? [] as $row) {
        if (is_array($row) && ($row['thread_id'] ?? '') === 'th-email') {
            $emailRow = $row;
        }
    }
    ilm(is_array($emailRow) && ($emailRow['match_by'] ?? '') === 'email', 'exakt e-postträff vinner i phone_address');
    ilm(ilm_has($dry['no_match'] ?? [], 'th-name'), 'bara namn matchar inte');
    $amb = null;
    foreach ($dry['ambiguous'] ?? [] as $row) {
        if (is_array($row) && ($row['thread_id'] ?? '') === 'th-amb-phone') {
            $amb = $row;
        }
    }
    ilm(is_array($amb) && ($amb['match_by'] ?? '') === 'phone' && ($amb['matches'] ?? 0) === 2, 'flera telefonträffar är ambiguous');
    $ambIds = is_array($amb['contact_ids'] ?? null) ? $amb['contact_ids'] : [];
    sort($ambIds);
    ilm($ambIds === ['kt_twin_a', 'kt_twin_b'], 'tvetydiga telefonkontakter är de två i samma bolag');
    $otherLink = null;
    foreach ($dry['would_link'] ?? [] as $row) {
        if (is_array($row) && ($row['thread_id'] ?? '') === 'th-other-phone') {
            $otherLink = $row;
        }
    }
    ilm(is_array($otherLink) && ($otherLink['contact_id'] ?? '') === 'kt_phone', 'jarfälla-kontakten vinner över malmö');
    ilm_clean($dry);
    ilm(ilm_cid($pdo, 'inkorg_list', 'th-phone') === '', 'torr körning skriver inget');

    $noConfirm = jeab_contacts_sole_dispatch(['action' => 'inbox.link_contacts', 'match' => 'phone_address', 'dry' => 0]);
    ilm(($noConfirm['ok'] ?? true) === false && ($noConfirm['status'] ?? 0) === 400, 'dry=0 utan confirm är 400');
    ilm(ilm_cid($pdo, 'inkorg_list', 'th-phone') === '', 'utan confirm skrivs inget');

    $wet = jeab_contacts_sole_dispatch(['action' => 'inbox.link_contacts', 'match' => 'phone_address', 'dry' => 0, 'confirm' => 'link']);
    ilm(($wet['ok'] ?? false) === true && ($wet['written'] ?? false) === true, 'dry=0 med confirm skriver');
    ilm(preg_match('/^[a-f0-9]{16}$/', (string) ($wet['undo_id'] ?? '')) === 1, 'undo_id följer med');
    ilm_clean($wet);
    ilm(ilm_cid($pdo, 'inkorg_list', 'th-phone') === 'kt_phone', 'telefonlänken skrevs');
    ilm(ilm_cid($pdo, 'inkorg_list', 'th-addr') === 'kt_addr', 'adresslänken skrevs');
    ilm(ilm_cid($pdo, 'inkorg_list', 'th-name') === '', 'namn-tråden länkas inte');
    ilm(ilm_cid($pdo, 'inkorg_list', 'th-amb-phone') === '', 'tvetydig telefon länkas inte');

    $undo = jeab_contacts_sole_dispatch([
        'action' => 'inbox.link_contacts_undo',
        'undo_id' => $wet['undo_id'],
        'dry' => 0,
    ]);
    ilm(($undo['ok'] ?? false) === true && ilm_has($undo['restored'] ?? [], 'th-phone'), 'undo återställer telefonlänken');
    ilm_clean($undo);
    ilm(ilm_cid($pdo, 'inkorg_list', 'th-phone') === '' && ilm_cid($pdo, 'inkorg_list', 'th-addr') === '', 'undo tömde länkarna');
}

function ilm_mariadb(): ?array
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
    $tmp = sys_get_temp_dir() . '/jeab-ilm-mysql-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0775, true);
    $port = 35100 + random_int(0, 1500);
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
            ilm_rm_tree($holder->tmp);
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

function ilm_child(string $backend, int $port): void
{
    putenv('JEAB_ILM_CHILD=' . $backend);
    putenv('JEAB_ILM_PORT=' . (string) $port);
    $outFile = tempnam(sys_get_temp_dir(), 'ilm-out-');
    $errFile = tempnam(sys_get_temp_dir(), 'ilm-err-');
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
    putenv('JEAB_ILM_CHILD');
    putenv('JEAB_ILM_PORT');
    echo $out;
    if ($err !== '') {
        fwrite(STDERR, $err);
    }
    if ($code !== 0) {
        exit($code > 0 ? $code : 1);
    }
}

$ilmChild = getenv('JEAB_ILM_CHILD');
$ilmChild = is_string($ilmChild) ? $ilmChild : '';
if ($ilmChild === 'sqlite' || $ilmChild === 'mysql') {
    $ilmPort = (int) getenv('JEAB_ILM_PORT');
    ilm_scenarios($ilmChild, $ilmPort);
    exit(0);
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

ilm_child('sqlite', 0);
$ilmDb = ilm_mariadb();
if ($ilmDb !== null) {
    ilm_child('mysql', $ilmDb['port']);
}

echo "ALL OK\n";
