<?php

declare(strict_types=1);

/**
 * contacts.merge_duplicates och contacts.normalize_addresses.
 * Torrkörning, confirm, flagga, länkar, bolag, ånger före skrivning.
 * Svaret har antal och id, inga värden. Påhittade namn. Inget personnummer.
 */

function cmd(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function cmd_rm_tree(string $dir): void
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
            cmd_rm_tree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/** @param array<string, mixed> $hook */
function cmd_quiet(array $hook, string $label): void
{
    $json = (string) json_encode($hook, JSON_UNESCAPED_UNICODE);
    cmd(!str_contains($json, '@'), $label . ' har inget mejl');
    foreach ([
        'Testgatan', 'Teststad', 'Auditgatan', 'Auditstad', 'Namngatan', 'Namnstad',
        'Postgatan', 'Poststad', 'Brovagen', 'Brostad', 'Normgatan', 'Kopiagatan',
        'Ada Full', 'Namn Ett', 'Namn Tva', 'Post Prov', 'Audit Levande', 'Annat Audit',
        'Trad Namn', 'Maja Prov', 'Kampanj', 'Fryst Kopia', 'Anteckning Prov',
        '+46701111111', '+46702222222', '+46708888888',
        '0701111111', '0702222222', '0708888888', '0705555555', '0701000800',
    ] as $secret) {
        cmd(!str_contains($json, $secret), $label . ' har inget värde');
    }
}

/** @return array<string, mixed> */
function cmd_hook(array $input): array
{
    $result = jeab_contacts_sole_dispatch($input);

    return is_array($result) ? $result : [];
}

function cmd_cell(PDO $pdo, string $sql, array $params = []): string
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $value = $stmt->fetchColumn();

    return $value === false || $value === null ? '' : (string) $value;
}

/** @return array<string, mixed> */
function cmd_json(PDO $pdo, string $sql, array $params = []): array
{
    $decoded = json_decode(cmd_cell($pdo, $sql, $params), true);

    return is_array($decoded) ? $decoded : [];
}

/** @param array<string, mixed> $row */
function cmd_put(string $id, array $row): void
{
    $row['id'] = $id;
    jeab_kontakter_mutate(static function (array &$contacts) use ($id, $row): void {
        $contacts[$id] = $row;
    });
    jeab_contacts_merge_forget($id);
}

/** @param array<string, mixed> $row */
function cmd_set_json(PDO $pdo, string $id, array $row): void
{
    $row['id'] = $id;
    $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare('UPDATE contacts SET row_json = ? WHERE id = ?')->execute([$json === false ? '{}' : $json, $id]);
    jeab_contacts_merge_forget($id);
}

/** @return list<string> */
function cmd_email_ids(PDO $pdo, string $email): array
{
    $stmt = $pdo->prepare('SELECT contact_id FROM contact_emails WHERE email = ? ORDER BY contact_id');
    $stmt->execute([$email]);
    $ids = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $ids[] = (string) $id;
    }

    return $ids;
}

function cmd_has_col(PDO $pdo, string $table, string $column): bool
{
    try {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $rows = $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
            $key = 'Field';
        } else {
            $rows = $pdo->query('PRAGMA table_info("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC);
            $key = 'name';
        }
    } catch (Throwable) {
        return false;
    }
    foreach ($rows as $row) {
        if (is_array($row) && (string) ($row[$key] ?? '') === $column) {
            return true;
        }
    }

    return false;
}

function cmd_boot(string $backend, int $port): void
{
    $tmp = sys_get_temp_dir() . '/jeab-cmd-' . $backend . '-' . bin2hex(random_bytes(4));
    mkdir($tmp . '/secrets', 0775, true);
    register_shutdown_function(static function () use ($tmp): void {
        cmd_rm_tree($tmp);
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
            cmd(false, 'MySQL saknar port');
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
    file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-cmd']));
    require dirname(__DIR__) . '/src/bootstrap.php';
}

function cmd_scenarios(string $backend, int $port): void
{
    cmd_boot($backend, $port);
    $root = dirname(__DIR__);
    $hookSrc = (string) file_get_contents($root . '/hook.php');
    $dispatchSrc = (string) file_get_contents($root . '/src/contacts_sole.php');
    foreach ([
        'contacts.merge_duplicates',
        'contacts.merge_duplicates_undo',
        'contacts.normalize_addresses',
        'contacts.normalize_addresses_undo',
        'contacts.sole_audit',
    ] as $action) {
        cmd(str_contains($hookSrc, "'" . $action . "'"), 'hook.php tar ' . $action);
    }
    cmd(str_contains($dispatchSrc, 'jeab_contacts_merge_dispatch('), 'dispatch skickar sammanslagning');
    cmd(str_contains($dispatchSrc, 'jeab_contacts_sole_audit('), 'dispatch skickar auditen');

    $store = jeab_store();
    cmd($store instanceof JeabPdoStore, $backend . '-lagret finns');
    $pdo = $store->pdo();
    $mysql = $backend === 'mysql';
    cmd((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === $backend, 'lagret är ' . $backend);
    jeab_ensure_contacts_tables($pdo, $backend);
    jeab_ensure_contact_id_columns($pdo, $backend);
    jeab_purchases_store_ensure($pdo);
    jeab_contact_emails_create_table($pdo, $mysql ? 'mysql' : 'sqlite');
    jeab_contact_emails_table_exists($pdo, true);
    require_once dirname(__DIR__) . '/src/contacts_merge_duplicates.php';
    cmd(jeab_flag('CONTACTS_AUTO_MERGE') === false, 'CONTACTS_AUTO_MERGE är av');
    cmd(jeab_flag('CONTACTS_STRICT') === false, 'CONTACTS_STRICT är av');
    cmd(jeab_flag('CONTACTS_SOLE') === true, 'CONTACTS_SOLE är på');
    $caseKey = jeab_contacts_merge_address_key('Casegatan 38 A', 'Casestad');
    cmd($caseKey !== '' && $caseKey === jeab_contacts_merge_address_key('casegatan  38a', 'CASESTAD'), 'nyckeln viker skiftläge och mellanslag');
    cmd($caseKey === jeab_contacts_merge_address_key('Casegatan 38A.', 'Casestad'), 'nyckeln tar bort avslutande punkt');
    cmd(jeab_contacts_merge_address_key('Storgatan. 1', 'Ort') === jeab_contacts_merge_address_key('Storgatan 1', 'Ort'), 'punkt i gatunamnet är mellanslag');
    cmd(jeab_contacts_merge_address_key('A.gatan 1', 'Ort') === jeab_contacts_merge_address_key('A gatan 1', 'Ort'), 'förkortningspunkt är mellanslag');
    cmd(jeab_contacts_merge_address_key('Norrskensv. 12', 'Ort') === jeab_contacts_merge_address_key('Norrskensv 12', 'Ort'), 'förkortningspunkt före nummer är mellanslag');
    cmd(jeab_contacts_merge_address_key('Brovagen 38 BRO', 'Brostad') !== jeab_contacts_merge_address_key('Brovagen 38B', 'Brostad'), '38 BRO är inte 38B');
    cmd(jeab_contacts_merge_address_key('Norra Storgatan 1', 'Ort') !== jeab_contacts_merge_address_key('Norrastorgatan 1', 'Ort'), 'mellanslag i gatunamnet tas inte bort');
    cmd(jeab_contacts_merge_address_key('Gatan 1', 'Malmö') !== jeab_contacts_merge_address_key('Gatan 1', 'Malmo'), 'orten viker inte åäö');
    cmd(jeab_contacts_merge_address_key('Gatan 1', 'Teststad kommun') !== jeab_contacts_merge_address_key('Gatan 1', 'Teststad'), 'kommun tas inte bort ur orten');
    cmd(jeab_contacts_merge_address_key('Gatan 1.2', 'Ort') !== jeab_contacts_merge_address_key('Gatan 12', 'Ort'), 'decimalpunkt blir inte ett nummer');
    cmd(jeab_contacts_merge_address_key('Gatan 1', '') === '' && jeab_contacts_merge_address_key('', 'Ort') === '', 'tom gata eller tom ort ger ingen nyckel');
    cmd(jeab_contacts_merge_address_key('+46701111111', 'Ort') === '' && jeab_contacts_merge_address_key('070-111 11 11', 'Ort') === '', 'telefon i gatufältet är ingen nyckel');
    cmd(jeab_contacts_merge_address_key('ada@example.test', 'Ort') === '', 'mejl i gatufältet är ingen nyckel');
    $postalSplit = jeab_contacts_merge_postal_clusters([
        ['id' => 'a', 'postal_code' => '11111'],
        ['id' => 'b', 'postal_code' => '22222'],
        ['id' => 'c', 'postal_code' => ''],
    ]);
    $postalJoined = false;
    foreach ($postalSplit as $cluster) {
        $ids = [];
        foreach ($cluster as $row) {
            $ids[] = (string) ($row['id'] ?? '');
        }
        if (in_array('a', $ids, true) && in_array('b', $ids, true)) {
            $postalJoined = true;
        }
        cmd(!in_array('c', $ids, true) || count($ids) === 1, 'tomt postnummer binder inte ihop två postnummer');
    }
    cmd($postalJoined === false, 'olika postnummer delar inte kluster');
    $postalSame = jeab_contacts_merge_postal_clusters([
        ['id' => 'a', 'postal_code' => '111 11'],
        ['id' => 'b', 'postal_code' => '11111'],
        ['id' => 'c', 'postal_code' => ''],
    ]);
    $sameIds = [];
    foreach ($postalSame[0] ?? [] as $row) {
        $sameIds[] = (string) ($row['id'] ?? '');
    }
    sort($sameIds);
    cmd($sameIds === ['a', 'b', 'c'], 'samma postnummer och ett tomt följer med');

    $tokenDir = dirname(jeab_inkorg_token_path());
    if (!is_dir($tokenDir)) {
        mkdir($tokenDir, 0775, true);
    }
    file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-cmd']));
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-cmd';

    $now = jeab_now();
    $text = $mysql ? 'LONGTEXT' : 'TEXT';
    $idType = $mysql ? 'VARCHAR(64) NOT NULL' : 'TEXT NOT NULL';
    if (!cmd_has_col($pdo, 'inkorg_threads', 'id')) {
        $pdo->exec('CREATE TABLE inkorg_threads (id ' . ($mysql ? 'VARCHAR(191) NOT NULL' : 'TEXT NOT NULL') . ', status ' . ($mysql ? 'VARCHAR(32)' : 'TEXT') . " NOT NULL DEFAULT 'open', updated_at " . ($mysql ? 'DATETIME' : 'TEXT') . ', row_json ' . $text . ' NOT NULL, PRIMARY KEY (id))');
    }
    if (!cmd_has_col($pdo, 'inkorg_list', 'id')) {
        $pdo->exec('CREATE TABLE inkorg_list (id ' . ($mysql ? 'VARCHAR(191) NOT NULL' : 'TEXT NOT NULL') . ', company ' . ($mysql ? 'VARCHAR(32)' : 'TEXT') . " NOT NULL DEFAULT '', customer_email " . ($mysql ? 'VARCHAR(191)' : 'TEXT') . " NOT NULL DEFAULT '', list_at " . ($mysql ? 'VARCHAR(40)' : 'TEXT') . " NOT NULL DEFAULT '', list_status " . ($mysql ? 'VARCHAR(32)' : 'TEXT') . " NOT NULL DEFAULT '', contact_id " . ($mysql ? 'VARCHAR(64)' : 'TEXT') . " NOT NULL DEFAULT '', row_json " . $text . ' NOT NULL, PRIMARY KEY (id))');
    }
    if (!cmd_has_col($pdo, 'customers', 'contact_id')) {
        $pdo->exec('ALTER TABLE customers ADD COLUMN contact_id ' . ($mysql ? 'VARCHAR(64) NOT NULL DEFAULT \'\'' : 'TEXT NOT NULL DEFAULT \'\''));
    }
    if (!cmd_has_col($pdo, 'notes', 'payload')) {
        $pdo->exec('CREATE TABLE IF NOT EXISTS notes (id ' . ($mysql ? 'INT NOT NULL' : 'INTEGER') . ' PRIMARY KEY, payload ' . $text . ' NOT NULL)');
    }
    try {
        $pdo->query('SELECT 1 FROM solkampanj_recipients LIMIT 0');
    } catch (Throwable) {
        if ($mysql) {
            $pdo->exec('CREATE TABLE solkampanj_recipients (id INT UNSIGNED NOT NULL AUTO_INCREMENT, contact_id VARCHAR(64) NOT NULL DEFAULT \'\', `values` LONGTEXT NULL, PRIMARY KEY (id))');
        } else {
            $pdo->exec('CREATE TABLE solkampanj_recipients (id INTEGER PRIMARY KEY AUTOINCREMENT, contact_id TEXT NOT NULL DEFAULT \'\', "values" TEXT)');
        }
    }
    if (!cmd_has_col($pdo, 'solkampanj_recipients', 'contact_id')) {
        $pdo->exec('ALTER TABLE solkampanj_recipients ADD COLUMN contact_id ' . ($mysql ? "VARCHAR(64) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''"));
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS sample_hold (id ' . $idType . ', contact_id ' . ($mysql ? 'VARCHAR(64)' : 'TEXT') . ', snapshot_json ' . $text . ', PRIMARY KEY (id))');
    $pdo->exec('CREATE TABLE IF NOT EXISTS sample_audit (id ' . $idType . ', contact_id ' . ($mysql ? 'VARCHAR(64)' : 'TEXT') . ', PRIMARY KEY (id))');

    $base = [
        'street' => 'Testgatan 38A',
        'postal_code' => '11122',
        'city' => 'Teststad',
        'company' => 'jarfalla',
        'created_at' => '2020-01-01 00:00:00',
        'updated_at' => '2020-01-01 00:00:00',
    ];
    cmd_put('kt_full', array_merge($base, [
        'owners' => [[
            'name' => 'Ada Full',
            'email' => 'ada.full@example.test',
            'phone' => '+46701111111',
            'contact' => true,
        ]],
        'email2' => 'delad.fyll@example.test',
    ]));
    cmd_put('kt_thin', array_merge($base, [
        'street' => 'Testgatan 38 A',
        'created_at' => '2024-06-01 00:00:00',
        'owners' => [[
            'name' => '',
            'email' => 'delad.fyll@example.test',
            'phone' => '',
            'contact' => true,
        ]],
        'email2' => 'b.extra@example.test',
        'phone2' => '+46702222222',
    ]));
    cmd_put('kt_malmo', array_merge($base, [
        'company' => 'malmo',
        'created_at' => '2021-01-01 00:00:00',
        'owners' => [[
            'name' => 'Malmo Enda',
            'email' => 'malmo.enda@example.test',
            'phone' => '+46703333333',
            'contact' => true,
        ]],
    ]));
    cmd_put('kt_empty', array_merge($base, [
        'company' => '',
        'created_at' => '2021-02-01 00:00:00',
        'owners' => [[
            'name' => 'Tom Bolag',
            'email' => 'tom.enda@example.test',
            'phone' => '+46704444444',
            'contact' => true,
        ]],
    ]));
    cmd_put('kt_namn_a', [
        'street' => 'Namngatan 5',
        'postal_code' => '55555',
        'city' => 'Namnstad',
        'company' => 'jarfalla',
        'created_at' => '2020-02-01 00:00:00',
        'updated_at' => '2020-02-01 00:00:00',
        'owners' => [[
            'name' => 'Namn Ett',
            'email' => 'namn.a@example.test',
            'phone' => '+46705555551',
            'contact' => true,
        ]],
    ]);
    cmd_put('kt_namn_b', [
        'street' => 'Namngatan 5',
        'postal_code' => '55555',
        'city' => 'Namnstad',
        'company' => 'jarfalla',
        'created_at' => '2024-02-01 00:00:00',
        'updated_at' => '2024-02-01 00:00:00',
        'owners' => [[
            'name' => 'Namn Tva',
            'email' => 'namn.b@example.test',
            'phone' => '+46705555552',
            'contact' => true,
        ]],
    ]);
    cmd_put('kt_post_a', [
        'street' => 'Postgatan 6',
        'postal_code' => '22233',
        'city' => 'Poststad',
        'company' => 'jarfalla',
        'created_at' => '2020-03-01 00:00:00',
        'updated_at' => '2020-03-01 00:00:00',
        'owners' => [[
            'name' => 'Post Prov',
            'email' => 'post.a@example.test',
            'phone' => '+46706666661',
            'contact' => true,
        ]],
    ]);
    cmd_put('kt_post_b', [
        'street' => 'Postgatan 6',
        'postal_code' => '33344',
        'city' => 'Poststad',
        'company' => 'jarfalla',
        'created_at' => '2024-03-01 00:00:00',
        'updated_at' => '2024-03-01 00:00:00',
        'owners' => [[
            'name' => 'Post Prov',
            'email' => 'post.b@example.test',
            'phone' => '+46706666662',
            'contact' => true,
        ]],
    ]);
    cmd_put('kt_bro', [
        'street' => 'Brovagen 38 BRO',
        'postal_code' => '44400',
        'city' => 'Brostad',
        'company' => 'jarfalla',
        'created_at' => '2022-01-01 00:00:00',
        'updated_at' => '2022-01-01 00:00:00',
        'owners' => [[
            'name' => 'Bro Bro',
            'email' => 'bro.bro@example.test',
            'phone' => '+46707777777',
            'contact' => true,
        ]],
    ]);
    cmd_put('kt_norm', [
        'street' => 'Normgatan 12 A',
        'postal_code' => '12121',
        'city' => 'Normstad',
        'company' => 'jarfalla',
        'created_at' => '2022-04-01 00:00:00',
        'updated_at' => '2022-04-01 00:00:00',
        'owners' => [[
            'name' => 'Norm Norm',
            'email' => 'norm.norm@example.test',
            'phone' => '+46701212121',
            'contact' => true,
        ]],
    ]);
    cmd_put('kt_audit', [
        'street' => 'Auditgatan 9',
        'postal_code' => '44455',
        'city' => 'Auditstad',
        'company' => 'jarfalla',
        'created_at' => '2022-05-01 00:00:00',
        'updated_at' => '2022-05-01 00:00:00',
        'owners' => [[
            'name' => 'Audit Levande',
            'email' => 'audit.live@example.test',
            'phone' => '+46708888888',
            'contact' => true,
        ]],
    ]);
    $caseOwner = [[
        'name' => 'Case Prov',
        'email' => '',
        'phone' => '',
        'contact' => true,
    ]];
    $caseRows = [
        'kt_case_a' => [
            'street' => 'Casegatan 38 A',
            'postal_code' => '12121',
            'city' => 'Casestad',
            'company' => 'jarfalla',
            'created_at' => '2020-08-01 00:00:00',
            'updated_at' => '2020-08-01 00:00:00',
            'owners' => $caseOwner,
        ],
        'kt_case_b' => [
            'street' => 'casegatan  38a',
            'postal_code' => '121 21',
            'city' => 'CASESTAD',
            'company' => 'jarfalla',
            'created_at' => '2024-08-01 00:00:00',
            'updated_at' => '2024-08-01 00:00:00',
            'owners' => $caseOwner,
        ],
        'kt_case_c' => [
            'street' => 'Casegatan 38A.',
            'postal_code' => '12121',
            'city' => 'Casestad',
            'company' => 'jarfalla',
            'created_at' => '2024-08-02 00:00:00',
            'updated_at' => '2024-08-02 00:00:00',
            'owners' => $caseOwner,
        ],
    ];
    foreach ($caseRows as $caseId => $caseRow) {
        cmd_put($caseId, $caseRow);
        cmd_set_json($pdo, $caseId, $caseRow);
    }
    cmd_put('kt_annan', [
        'street' => 'Annangatan 4',
        'postal_code' => '11122',
        'city' => 'Teststad',
        'company' => 'jarfalla',
        'created_at' => '2021-08-01 00:00:00',
        'updated_at' => '2021-08-01 00:00:00',
        'owners' => [[
            'name' => 'Annan Gata',
            'email' => 'annan.gata@example.test',
            'phone' => '+46701111111',
            'contact' => true,
        ]],
    ]);
    for ($tel = 1; $tel <= 5; $tel++) {
        cmd_put('kt_tel_' . $tel, [
            'street' => 'Telgatan ' . $tel,
            'postal_code' => '13131',
            'city' => 'Telstad',
            'company' => 'jarfalla',
            'created_at' => '2021-09-0' . $tel . ' 00:00:00',
            'updated_at' => '2021-09-0' . $tel . ' 00:00:00',
            'owners' => [[
                'name' => 'Tel Prov',
                'email' => '',
                'phone' => '+46701111111',
                'contact' => true,
            ]],
        ]);
    }
    foreach (['kt_mail_a' => 'Mailgatan 1', 'kt_mail_b' => 'Mailgatan 2'] as $mailId => $mailStreet) {
        cmd_put($mailId, [
            'street' => $mailStreet,
            'postal_code' => '14141',
            'city' => 'Mailstad',
            'company' => 'jarfalla',
            'created_at' => '2021-10-01 00:00:00',
            'updated_at' => '2021-10-01 00:00:00',
            'owners' => [[
                'name' => 'Mail Prov',
                'email' => 'delad.post@example.test',
                'phone' => '',
                'contact' => true,
            ]],
        ]);
    }
    foreach (['kt_town_a', 'kt_town_b'] as $townId) {
        cmd_put($townId, [
            'street' => 'Tomgatan 3',
            'postal_code' => '15151',
            'city' => '',
            'company' => 'jarfalla',
            'created_at' => '2021-11-01 00:00:00',
            'updated_at' => '2021-11-01 00:00:00',
            'owners' => [[
                'name' => 'Tom Ort',
                'email' => '',
                'phone' => '',
                'contact' => true,
            ]],
        ]);
    }
    cmd_put('kt_bro_b', [
        'street' => 'Brovagen 38B',
        'postal_code' => '44400',
        'city' => 'Brostad',
        'company' => 'jarfalla',
        'created_at' => '2022-02-01 00:00:00',
        'updated_at' => '2022-02-01 00:00:00',
        'owners' => [[
            'name' => 'Bro Bokstav',
            'email' => 'bro.bokstav@example.test',
            'phone' => '+46707777778',
            'contact' => true,
        ]],
    ]);
    cmd(cmd_email_ids($pdo, 'delad.fyll@example.test') === ['kt_full', 'kt_thin'], 'delad adress finns på båda');
    cmd(cmd_email_ids($pdo, 'b.extra@example.test') === ['kt_thin'], 'extra mejl finns på dubbletten');
    cmd((string) (jeab_contacts_merge_fetch($pdo, 'kt_thin')['street'] ?? '') === 'Testgatan 38A', 'dubblettens gata är redan hopskriven');

    $customer = $store->insertCustomer('Kopia Kund', $now);
    $customerId = (int) ($customer['id'] ?? 0);
    cmd($customerId > 0, 'kundraden finns');
    $book = $store->insertBooking([
        'customer_id' => $customerId,
        'customer_name' => 'Kopia Thin',
        'email' => 'kopia.thin@example.test',
        'phone' => '0701000800',
        'address' => 'Kopiagatan 1',
        'assignees' => ['Elin'],
        'company' => 'jarfalla',
        'job_type' => 'laddbox',
        'starts_at' => '2026-11-04 09:00:00',
        'ends_at' => '2026-11-04 10:00:00',
        'status' => 'booked',
        'contact_id' => 'kt_thin',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $bookId = (int) ($book['id'] ?? 0);
    cmd($bookId > 0 && (string) ($book['contact_id'] ?? '') === 'kt_thin', 'bokningen pekar på dubbletten');
    $malmoBook = $store->insertBooking([
        'customer_id' => $customerId,
        'customer_name' => 'Kopia Malmo',
        'email' => 'kopia.malmo@example.test',
        'phone' => '0701000801',
        'address' => 'Kopiagatan 2',
        'assignees' => ['Elin'],
        'company' => 'malmo',
        'job_type' => 'laddbox',
        'starts_at' => '2026-11-04 11:00:00',
        'ends_at' => '2026-11-04 12:00:00',
        'status' => 'booked',
        'contact_id' => 'kt_malmo',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $malmoBookId = (int) ($malmoBook['id'] ?? 0);
    cmd($malmoBookId > 0 && (string) ($malmoBook['contact_id'] ?? '') === 'kt_malmo', 'malmo-bokningen pekar på sitt bolag');
    $reminder = $store->insertReminder([
        'title' => 'Trad',
        'customer_name' => 'Kopia Thin',
        'company' => 'jarfalla',
        'contact_id' => 'kt_thin',
        'due_at' => '2026-11-05 09:00:00',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $reminderId = (int) ($reminder['id'] ?? 0);
    cmd($reminderId > 0 && (string) ($reminder['contact_id'] ?? '') === 'kt_thin', 'påminnelsen pekar på dubbletten');
    $holder = $store->insertReminder([
        'title' => 'Orderhallare',
        'customer_name' => 'Kopia Thin',
        'company' => 'jarfalla',
        'due_at' => '2026-11-05 10:00:00',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $holderId = (int) ($holder['id'] ?? 0);
    $payload = [
        'frozen' => true,
        'contact_id' => 'kt_thin',
        'namn' => 'Maja Prov',
        'z_keep' => 'kvar',
        'pris' => 14900,
        'rows' => [['article' => 'KEEP', 'qty' => '1']],
        'kalkyl' => [['article' => 'BOX']],
        'snapshot' => [
            'frozen' => true,
            'contact_id' => 'kt_thin',
            'label' => 'Fryst Kopia',
        ],
        'open_copy' => [
            'snapshot' => [
                'contact_id' => 'kt_thin',
                'label' => 'Ofryst Kopia',
            ],
        ],
    ];
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    cmd(is_string($payloadJson), 'orderpayload kan kodas');
    $pdo->prepare('INSERT INTO purchases (reminder_id, payload, updated_at, contact_id) VALUES (?, ?, ?, ?)')->execute([
        $holderId,
        $payloadJson,
        $now,
        'kt_thin',
    ]);
    if (cmd_has_col($pdo, 'purchases', 'list_json')) {
        $pdo->prepare('UPDATE purchases SET list_json = ? WHERE reminder_id = ?')->execute([
            json_encode(['contact_id' => 'kt_thin'], JSON_UNESCAPED_UNICODE),
            $holderId,
        ]);
    }
    $threadJson = json_encode([
        'company' => 'jarfalla',
        'contact_id' => 'kt_thin',
        'customer_name' => 'Trad Namn',
        'meta' => ['contact_id' => 'kt_thin'],
        'snapshot' => ['frozen' => true, 'contact_id' => 'kt_thin', 'label' => 'Fryst Kopia'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare('INSERT INTO inkorg_threads (id, status, updated_at, row_json) VALUES (?, ?, ?, ?)')->execute(['th-thin', 'open', $now, $threadJson]);
    $listJson = json_encode([
        'company' => 'jarfalla',
        'contact_id' => 'kt_thin',
        'meta' => ['contact_id' => 'kt_thin'],
        'customer_email' => 'lista.thin@example.test',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare('INSERT INTO inkorg_list (id, company, customer_email, list_at, list_status, contact_id, row_json) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([
        'ls-thin', 'jarfalla', 'lista.thin@example.test', $now, 'open', 'kt_thin', $listJson,
    ]);
    $pdo->prepare('UPDATE customers SET contact_id = ? WHERE id = ?')->execute(['kt_thin', $customerId]);
    $valuesCol = $mysql ? '`values`' : '"values"';
    $valuesJson = json_encode(['contact_id' => 'kt_thin', 'namn' => 'Kampanj'], JSON_UNESCAPED_UNICODE);
    $pdo->prepare('INSERT INTO solkampanj_recipients (contact_id, ' . $valuesCol . ') VALUES (?, ?)')->execute(['kt_thin', $valuesJson]);
    $solId = (string) $pdo->lastInsertId();
    $noteJson = json_encode(['contact_id' => 'kt_thin', 'text' => 'Anteckning Prov'], JSON_UNESCAPED_UNICODE);
    $pdo->prepare('INSERT INTO notes (id, payload) VALUES (?, ?)')->execute([9001, $noteJson]);
    $holdJson = json_encode(['frozen' => true, 'contact_id' => 'kt_thin', 'label' => 'Fryst Kopia'], JSON_UNESCAPED_UNICODE);
    $pdo->prepare('INSERT INTO sample_hold (id, contact_id, snapshot_json) VALUES (?, ?, ?)')->execute(['hold1', 'kt_thin', $holdJson]);
    $openHoldJson = json_encode(['contact_id' => 'kt_thin', 'label' => 'Ofryst Kopia'], JSON_UNESCAPED_UNICODE);
    $pdo->prepare('INSERT INTO sample_hold (id, contact_id, snapshot_json) VALUES (?, ?, ?)')->execute(['hold2', 'kt_thin', $openHoldJson]);
    $pdo->prepare('INSERT INTO sample_audit (id, contact_id) VALUES (?, ?)')->execute(['aud1', 'kt_thin']);

    $auditAddress = 'Auditgatan 9, 44455 Auditstad';
    $pdo->prepare('INSERT INTO inkorg_threads (id, status, updated_at, row_json) VALUES (?, ?, ?, ?)')->execute(['th-namn', 'open', $now, json_encode([
        'company' => 'jarfalla',
        'contact_id' => 'kt_audit',
        'customer_name' => 'Annat Audit',
        'customer_email' => 'annan.audit@example.test',
        'phone' => '0708888888',
        'address' => $auditAddress,
    ], JSON_UNESCAPED_UNICODE)]);
    $pdo->prepare('INSERT INTO inkorg_threads (id, status, updated_at, row_json) VALUES (?, ?, ?, ?)')->execute(['th-mobil', 'open', $now, json_encode([
        'company' => 'jarfalla',
        'contact_id' => 'kt_audit',
        'customer_name' => 'Audit Levande',
        'customer_email' => 'audit.live@example.test',
        'phone' => '0705555555',
        'address' => $auditAddress,
    ], JSON_UNESCAPED_UNICODE)]);
    $pdo->prepare('INSERT INTO inkorg_list (id, company, customer_email, list_at, list_status, contact_id, row_json) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([
        'ls-namn', 'jarfalla', 'annan.audit@example.test', $now, 'open', 'kt_audit', json_encode([
            'company' => 'jarfalla',
            'contact_id' => 'kt_audit',
            'customer_name' => 'Annat Audit',
            'phone' => '0708888888',
            'address' => $auditAddress,
        ], JSON_UNESCAPED_UNICODE),
    ]);
    $pdo->prepare('INSERT INTO inkorg_list (id, company, customer_email, list_at, list_status, contact_id, row_json) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([
        'ls-mobil', 'jarfalla', 'audit.live@example.test', $now, 'open', 'kt_audit', json_encode([
            'company' => 'jarfalla',
            'contact_id' => 'kt_audit',
            'customer_name' => 'Audit Levande',
            'phone' => '0705555555',
            'address' => $auditAddress,
        ], JSON_UNESCAPED_UNICODE),
    ]);

    $savedAuth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $denied = cmd_hook(['action' => 'contacts.merge_duplicates']);
    cmd(($denied['ok'] ?? true) === false && ($denied['status'] ?? 0) === 401, 'utan token blir det 401');
    $_SERVER['HTTP_AUTHORIZATION'] = $savedAuth;

    $contactsBefore = (int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn();
    $threadBefore = cmd_cell($pdo, 'SELECT row_json FROM inkorg_threads WHERE id = ?', ['th-namn']);
    $audit = cmd_hook(['action' => 'contacts.sole_audit', 'dry' => 0]);
    cmd(($audit['ok'] ?? false) === true && ($audit['written'] ?? true) === false && ($audit['read_only'] ?? false) === true, 'auditen skriver inget');
    cmd((int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn() === $contactsBefore, 'auditen raderar ingen kontakt');
    cmd(cmd_cell($pdo, 'SELECT row_json FROM inkorg_threads WHERE id = ?', ['th-namn']) === $threadBefore, 'auditen lämnar tråden');
    cmd_quiet($audit, 'audit');
    $co = is_array($audit['co'] ?? null) ? $audit['co'] : [];
    $jar = is_array($co['jarfalla'] ?? null) ? $co['jarfalla'] : [];
    $th = is_array($jar['inkorg_threads']['differ'] ?? null) ? $jar['inkorg_threads']['differ'] : [];
    $ls = is_array($jar['inkorg_list']['differ'] ?? null) ? $jar['inkorg_list']['differ'] : [];
    cmd(($th['email'] ?? -1) === 0 && ($th['name'] ?? -1) === 0 && ($th['phone'] ?? -1) === 1, 'kopplad tråd ignorerar namn och mejl');
    cmd(($th['thread_ids'] ?? null) === ['th-mobil'], 'tråd-id för avvikande mobil');
    cmd(($ls['email'] ?? -1) === 0 && ($ls['name'] ?? -1) === 0 && ($ls['phone'] ?? -1) === 1, 'listan ignorerar namn och mejl');
    cmd(($ls['thread_ids'] ?? null) === ['ls-mobil'], 'listradens id följer med');

    $dry = cmd_hook(['action' => 'contacts.merge_duplicates']);
    cmd(($dry['ok'] ?? false) === true && ($dry['dry'] ?? false) === true && ($dry['written'] ?? true) === false, 'utelämnad dry är torr');
    cmd_quiet($dry, 'torr sammanslagning');
    $blank = cmd_hook(['action' => 'contacts.merge_duplicates', 'dry' => '']);
    $nullDry = cmd_hook(['action' => 'contacts.merge_duplicates', 'dry' => null]);
    cmd(($blank['written'] ?? true) === false && ($nullDry['written'] ?? true) === false, 'tom dry skriver inte');
    $mentioned = [];
    foreach (is_array($dry['groups'] ?? null) ? $dry['groups'] : [] as $group) {
        if (!is_array($group)) {
            continue;
        }
        $mentioned[] = (string) ($group['master_id'] ?? '');
        foreach (['duplicate_ids', 'overflow_ids'] as $key) {
            foreach (is_array($group[$key] ?? null) ? $group[$key] : [] as $id) {
                $mentioned[] = (string) $id;
            }
        }
        cmd(!array_key_exists('changes', $group), 'gruppen har inga värden');
    }
    cmd(!in_array('kt_malmo', $mentioned, true) && !in_array('kt_empty', $mentioned, true), 'olika bolag slås inte ihop');
    $main = null;
    $nameGroup = null;
    $caseGroup = null;
    foreach ($dry['groups'] as $group) {
        if (!is_array($group)) {
            continue;
        }
        if ((string) ($group['master_id'] ?? '') === 'kt_full') {
            $main = $group;
        }
        if ((string) ($group['master_id'] ?? '') === 'kt_namn_a') {
            $nameGroup = $group;
        }
        if ((string) ($group['master_id'] ?? '') === 'kt_case_a') {
            $caseGroup = $group;
        }
    }
    cmd(is_array($main) && ($main['duplicate_ids'] ?? null) === ['kt_thin'] && ($main['overflow_ids'] ?? null) === [], 'full kontakt är master');
    cmd(is_array($nameGroup) && ($nameGroup['duplicate_ids'] ?? null) === [] && ($nameGroup['overflow_ids'] ?? null) === ['kt_namn_b'], 'olika namn blir overflow');
    cmd(is_array($caseGroup) && ($caseGroup['duplicate_ids'] ?? null) === ['kt_case_b', 'kt_case_c'] && ($caseGroup['overflow_ids'] ?? null) === [], 'samma adress och ort grupperas');
    foreach ([
        'kt_post_a', 'kt_post_b', 'kt_annan', 'kt_mail_a', 'kt_mail_b', 'kt_town_a', 'kt_town_b',
        'kt_bro', 'kt_bro_b', 'kt_tel_1', 'kt_tel_2', 'kt_tel_3', 'kt_tel_4', 'kt_tel_5',
    ] as $absent) {
        cmd(!in_array($absent, $mentioned, true), $absent . ' är inte i en grupp');
    }
    $counts = is_array($dry['counts'] ?? null) ? $dry['counts'] : [];
    cmd(($counts['duplicates'] ?? -1) === 3 && ($counts['overflow'] ?? -1) === 1 && ($counts['fields'] ?? -1) === 2, 'torr räkning');
    cmd(($counts['links'] ?? 0) > 0 && ($counts['merged'] ?? 1) === 0, 'torr körning flyttar inget');
    foreach (['kt_case_a', 'kt_case_b', 'kt_case_c'] as $gone) {
        $pdo->prepare('DELETE FROM contact_emails WHERE contact_id = ?')->execute([$gone]);
        $pdo->prepare('DELETE FROM contacts WHERE id = ?')->execute([$gone]);
        jeab_contacts_merge_forget($gone);
    }

    $blocked = cmd_hook(['action' => 'contacts.merge_duplicates', 'dry' => 0, 'confirm' => 'merge']);
    cmd(($blocked['status'] ?? 0) === 403 && ($blocked['written'] ?? true) === false, 'flaggan av ger 403');
    cmd(jeab_contacts_merge_fetch($pdo, 'kt_thin') !== null, '403 lämnar dubbletten');
    $GLOBALS['_JEAB_CONTACTS_AUTO_MERGE'] = true;
    $needConfirm = cmd_hook(['action' => 'contacts.merge_duplicates', 'dry' => 0]);
    $badConfirm = cmd_hook(['action' => 'contacts.merge_duplicates', 'dry' => 0, 'confirm' => 'MERGE']);
    cmd(($needConfirm['status'] ?? 0) === 400 && ($badConfirm['status'] ?? 0) === 400, 'confirm:merge krävs');
    cmd(jeab_contacts_merge_fetch($pdo, 'kt_thin') !== null && cmd_cell($pdo, 'SELECT contact_id FROM bookings WHERE id = ?', [$bookId]) === 'kt_thin', 'nekad confirm skriver inte');
    $explicitDry = cmd_hook(['action' => 'contacts.merge_duplicates', 'dry' => 1, 'confirm' => 'merge']);
    $impliedDry = cmd_hook(['action' => 'contacts.merge_duplicates', 'confirm' => 'merge']);
    cmd(($explicitDry['written'] ?? true) === false && ($impliedDry['written'] ?? true) === false, 'confirm utan dry=0 skriver inte');
    jeab_contacts_merge_ensure($pdo);
    cmd((int) $pdo->query('SELECT COUNT(*) FROM contacts_merge_undo')->fetchColumn() === 0, 'torr körning loggar inget');
    if ($mysql) {
        $pdo->exec('ALTER TABLE contacts_merge_undo MODIFY new_value TEXT NULL');
        jeab_ensure_contacts_merge_undo_table($pdo, 'mysql');
        $newValue = $pdo->query("SHOW COLUMNS FROM contacts_merge_undo LIKE 'new_value'")->fetch(PDO::FETCH_ASSOC);
        $newType = is_array($newValue) ? strtolower((string) ($newValue['Type'] ?? '')) : '';
        cmd(str_contains($newType, 'longtext'), 'new_value är longtext');
    }

    $openDoc = [
        'contact_id' => 'kt_thin',
        'snapshot' => ['contact_id' => 'kt_thin', 'label' => 'Ofryst Kopia'],
        'snapshots' => [['contact_id' => 'kt_thin']],
    ];
    $openNext = jeab_contacts_merge_rewrite_doc($openDoc, 'kt_thin', 'kt_full');
    cmd((string) ($openNext['contact_id'] ?? '') === 'kt_full', 'toppens contact_id flyttas');
    cmd((string) ($openNext['snapshot']['contact_id'] ?? '') === 'kt_thin', 'snapshot utan frysflagga skrivs inte om');
    cmd((string) ($openNext['snapshots'][0]['contact_id'] ?? '') === 'kt_thin', 'snapshot-lista utan frysflagga skrivs inte om');

    $staleJson = json_encode(['contact_id' => 'kt_thin', 'text' => 'Anteckning Prov'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $keptJson = json_encode(['contact_id' => 'kt_other', 'text' => 'Oförändrad'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $nextJson = json_encode(['contact_id' => 'kt_full', 'text' => 'Anteckning Prov'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare('INSERT INTO notes (id, payload) VALUES (?, ?)')->execute([9002, $keptJson]);
    $pdo->beginTransaction();
    $jsonMoved = jeab_contacts_merge_run_link($pdo, 'abcdef0123456789', [
        'tbl' => 'notes',
        'row_key' => '9002',
        'col' => 'payload',
        'old' => (string) $staleJson,
        'new' => (string) $nextJson,
        'pk' => 'id',
        'mode' => 'json',
    ]);
    cmd($jsonMoved === false, 'ändrat json flyttas inte');
    cmd(cmd_cell($pdo, 'SELECT payload FROM notes WHERE id = ?', [9002]) === $keptJson, 'ändrat json skrivs inte över');
    $pdo->rollBack();
    cmd(cmd_cell($pdo, 'SELECT payload FROM notes WHERE id = ?', [9002]) === $keptJson, 'json-provet lämnar raden');
    cmd((int) $pdo->query('SELECT COUNT(*) FROM contacts_merge_undo')->fetchColumn() === 0, 'json-provet loggar inget');

    cmd(jeab_contacts_merge_purchase_payloads_harm(
        ['9' => ['len' => 40, 'keys' => ['contact_id', 'namn', 'rows', 'rows.0.article']]],
        ['9' => ['len' => 39, 'keys' => ['contact_id', 'namn', 'rows', 'rows.0.article']]]
    ), 'kortare orderpayload stoppar');
    cmd(jeab_contacts_merge_purchase_payloads_harm(
        ['9' => ['len' => 40, 'keys' => ['contact_id', 'namn', 'z_keep']]],
        ['9' => ['len' => 40, 'keys' => ['contact_id', 'namn']]]
    ), 'tappad payload-nyckel stoppar');
    cmd(!jeab_contacts_merge_purchase_payloads_harm(
        ['9' => ['len' => 40, 'keys' => ['contact_id', 'namn']]],
        ['9' => ['len' => 40, 'keys' => ['contact_id', 'namn', 'extra']]]
    ), 'samma längd och kvarvarande nycklar går igenom');

    cmd(jeab_db_get_lock($pdo, 'jeab_contacts_merge') === true, 'låset går att ta');
    $busy = cmd_hook(['action' => 'contacts.merge_duplicates', 'dry' => 0, 'confirm' => 'merge']);
    cmd(($busy['ok'] ?? true) === false && ($busy['error'] ?? '') === 'busy' && ($busy['written'] ?? true) === false, 'andra körningen är busy');
    cmd(jeab_contacts_merge_fetch($pdo, 'kt_thin') !== null && cmd_cell($pdo, 'SELECT contact_id FROM bookings WHERE id = ?', [$bookId]) === 'kt_thin', 'busy skriver inte');
    jeab_db_release_lock($pdo, 'jeab_contacts_merge');

    if ($mysql) {
        $pdo->exec('CREATE TABLE jeab_cmd_undo_seen (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(64), tbl VARCHAR(64), ok INT)');
        $pdo->exec(
            'CREATE TRIGGER jeab_cmd_undo_probe AFTER INSERT ON contacts_merge_undo FOR EACH ROW '
            . 'INSERT INTO jeab_cmd_undo_seen (kind, tbl, ok) SELECT NEW.kind, NEW.tbl, CASE '
            . "WHEN NEW.kind = 'link' AND NEW.tbl = 'bookings' AND NEW.col = 'contact_id' THEN "
            . "IFNULL((SELECT CASE WHEN contact_id COLLATE utf8mb4_unicode_ci = NEW.old_value COLLATE utf8mb4_unicode_ci THEN 1 ELSE 0 END FROM bookings WHERE CAST(id AS CHAR(32)) COLLATE utf8mb4_unicode_ci = NEW.row_key COLLATE utf8mb4_unicode_ci), 0) "
            . "WHEN NEW.kind = 'contact_delete' THEN CASE WHEN (SELECT COUNT(*) FROM contacts WHERE id = NEW.row_key) = 1 THEN 1 ELSE 0 END "
            . "WHEN NEW.kind = 'contact_update' THEN CASE WHEN INSTR(COALESCE((SELECT row_json FROM contacts WHERE id = NEW.row_key), ''), NEW.new_value) = 0 THEN 1 ELSE 0 END "
            . 'ELSE 1 END'
        );
    } else {
        $pdo->exec('CREATE TABLE jeab_cmd_undo_seen (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT, tbl TEXT, ok INTEGER)');
        $pdo->exec(
            'CREATE TRIGGER jeab_cmd_undo_probe AFTER INSERT ON contacts_merge_undo BEGIN '
            . 'INSERT INTO jeab_cmd_undo_seen (kind, tbl, ok) VALUES (NEW.kind, NEW.tbl, CASE '
            . "WHEN NEW.kind = 'link' AND NEW.tbl = 'bookings' AND NEW.col = 'contact_id' THEN "
            . "IFNULL((SELECT CASE WHEN contact_id = NEW.old_value THEN 1 ELSE 0 END FROM bookings WHERE CAST(id AS TEXT) = NEW.row_key), 0) "
            . "WHEN NEW.kind = 'contact_delete' THEN CASE WHEN (SELECT COUNT(*) FROM contacts WHERE id = NEW.row_key) = 1 THEN 1 ELSE 0 END "
            . "WHEN NEW.kind = 'contact_update' THEN CASE WHEN INSTR(COALESCE((SELECT row_json FROM contacts WHERE id = NEW.row_key), ''), NEW.new_value) = 0 THEN 1 ELSE 0 END "
            . 'ELSE 1 END); END'
        );
    }

    $wet = cmd_hook(['action' => 'contacts.merge_duplicates', 'dry' => 0, 'confirm' => 'merge']);
    cmd(($wet['ok'] ?? false) === true && ($wet['written'] ?? false) === true && ($wet['dry'] ?? true) === false, 'sammanslagningen skriver');
    cmd_quiet($wet, 'våt sammanslagning');
    $undoId = (string) ($wet['undo_id'] ?? '');
    cmd(preg_match('/^[a-f0-9]{16}$/', $undoId) === 1, 'undo_id är 16 hex');
    $wetCounts = is_array($wet['counts'] ?? null) ? $wet['counts'] : [];
    cmd(($wetCounts['merged'] ?? 0) === 1 && ($wetCounts['refused'] ?? 1) === 0, 'en dubblett slogs ihop');
    $shapeB = ['7' => ['len' => 50, 'keys' => ['a', 'contact_id']]];
    $shapeA = ['7' => ['len' => 46, 'keys' => ['a', 'contact_id']]];
    cmd(!jeab_contacts_merge_purchase_payloads_harm($shapeB, $shapeA, ['7' => 46]), 'json_set-patch som kortar payload med känd längd stoppar inte');
    cmd(jeab_contacts_merge_purchase_payloads_harm($shapeB, $shapeA), 'okänd förkortning av payload stoppar');
    cmd(jeab_contacts_merge_purchase_payloads_harm($shapeB, ['7' => ['len' => 60, 'keys' => ['a']]], ['7' => 60]), 'tappad nyckel stoppar trots patch');
    cmd(($wetCounts['links'] ?? -1) === ($counts['links'] ?? -2), 'alla länkar flyttades');
    cmd((int) $pdo->query('SELECT MIN(ok) FROM jeab_cmd_undo_seen')->fetchColumn() === 1, 'ånger radades före ändringen');
    cmd((int) cmd_cell($pdo, "SELECT COUNT(*) FROM jeab_cmd_undo_seen WHERE kind = 'link' AND tbl = 'bookings' AND ok = 1") > 0, 'bokningens länk loggades innan den flyttades');
    cmd((int) cmd_cell($pdo, "SELECT COUNT(*) FROM jeab_cmd_undo_seen WHERE kind = 'contact_delete' AND ok = 1") > 0, 'kontakten fanns kvar när raderingen loggades');
    cmd((int) cmd_cell($pdo, "SELECT COUNT(*) FROM jeab_cmd_undo_seen WHERE kind = 'contact_update' AND ok = 1") > 0, 'fältet loggades innan det fylldes');

    cmd(jeab_contacts_merge_fetch($pdo, 'kt_thin') === null, 'dubbletten är borta');
    $full = jeab_contacts_merge_fetch($pdo, 'kt_full');
    cmd(is_array($full), 'mastern finns kvar');
    cmd(jeab_contacts_merge_read($full, 'email3') === 'b.extra@example.test', 'extra mejl fyllde tom plats');
    cmd(jeab_contacts_merge_read($full, 'phone2') === '+46702222222', 'extra mobil fyllde tom plats');
    cmd(jeab_contacts_merge_read($full, 'email') === 'ada.full@example.test', 'masterns mejl ligger kvar');
    cmd(jeab_contacts_merge_read($full, 'email2') === 'delad.fyll@example.test', 'masterns andra mejl ligger kvar');
    cmd(cmd_cell($pdo, 'SELECT contact_id FROM bookings WHERE id = ?', [$bookId]) === 'kt_full', 'bokningen pekar på mastern');
    cmd(cmd_cell($pdo, 'SELECT contact_id FROM bookings WHERE id = ?', [$malmoBookId]) === 'kt_malmo', 'malmo-bokningen ligger kvar');
    cmd(cmd_cell($pdo, 'SELECT contact_id FROM reminders WHERE id = ?', [$reminderId]) === 'kt_full', 'påminnelsen pekar på mastern');
    cmd(cmd_cell($pdo, 'SELECT contact_id FROM purchases WHERE reminder_id = ?', [$holderId]) === 'kt_full', 'orderns kolumn pekar på mastern');
    $buyRaw = cmd_cell($pdo, 'SELECT payload FROM purchases WHERE reminder_id = ?', [$holderId]);
    $buy = cmd_json($pdo, 'SELECT payload FROM purchases WHERE reminder_id = ?', [$holderId]);
    cmd((string) ($buy['contact_id'] ?? '') === 'kt_full', 'orderns contact_id flyttades');
    cmd(strlen($buyRaw) >= strlen((string) $payloadJson), 'orderpayloaden blev inte kortare');
    foreach (['namn', 'z_keep', 'pris', 'rows', 'rows.0', 'rows.0.article', 'rows.0.qty', 'kalkyl', 'kalkyl.0', 'kalkyl.0.article', 'snapshot', 'snapshot.contact_id', 'snapshot.label', 'open_copy', 'open_copy.snapshot', 'open_copy.snapshot.contact_id', 'frozen'] as $keptKey) {
        cmd(in_array($keptKey, jeab_contacts_merge_payload_keys($buy), true), 'ordern behåller nyckeln ' . $keptKey);
    }
    cmd((string) ($buy['z_keep'] ?? '') === 'kvar' && (string) ($buy['rows'][0]['article'] ?? '') === 'KEEP' && (int) ($buy['pris'] ?? 0) === 14900, 'orderns övriga fält ligger kvar');
    cmd((string) ($buy['snapshot']['contact_id'] ?? '') === 'kt_thin' && (string) ($buy['namn'] ?? '') === 'Maja Prov', 'fryst orderkopia ligger kvar');
    cmd((string) ($buy['open_copy']['snapshot']['contact_id'] ?? '') === 'kt_thin', 'ofryst snapshot-nod ligger kvar');
    if (cmd_has_col($pdo, 'purchases', 'list_json')) {
        $listBuy = cmd_json($pdo, 'SELECT list_json FROM purchases WHERE reminder_id = ?', [$holderId]);
        cmd((string) ($listBuy['contact_id'] ?? '') === 'kt_full', 'orderns list_json flyttades');
    }
    cmd(cmd_cell($pdo, 'SELECT contact_id FROM inkorg_list WHERE id = ?', ['ls-thin']) === 'kt_full', 'listans kolumn flyttades');
    $listRow = cmd_json($pdo, 'SELECT row_json FROM inkorg_list WHERE id = ?', ['ls-thin']);
    cmd((string) ($listRow['contact_id'] ?? '') === 'kt_full' && (string) ($listRow['meta']['contact_id'] ?? '') === 'kt_full', 'listans json flyttades');
    $threadRow = cmd_json($pdo, 'SELECT row_json FROM inkorg_threads WHERE id = ?', ['th-thin']);
    cmd((string) ($threadRow['contact_id'] ?? '') === 'kt_full' && (string) ($threadRow['meta']['contact_id'] ?? '') === 'kt_full', 'tråden flyttades');
    cmd((string) ($threadRow['snapshot']['contact_id'] ?? '') === 'kt_thin', 'fryst trådkopia ligger kvar');
    cmd(cmd_cell($pdo, 'SELECT contact_id FROM customers WHERE id = ?', [$customerId]) === 'kt_full', 'kunden flyttades');
    cmd(cmd_cell($pdo, 'SELECT contact_id FROM solkampanj_recipients WHERE id = ?', [$solId]) === 'kt_full', 'solkampanjen flyttades');
    $sol = cmd_json($pdo, 'SELECT ' . $valuesCol . ' FROM solkampanj_recipients WHERE id = ?', [$solId]);
    cmd((string) ($sol['contact_id'] ?? '') === 'kt_full' && (string) ($sol['namn'] ?? '') === 'Kampanj', 'solkampanjens json flyttades');
    $note = cmd_json($pdo, 'SELECT payload FROM notes WHERE id = ?', [9001]);
    cmd((string) ($note['contact_id'] ?? '') === 'kt_full' && (string) ($note['text'] ?? '') === 'Anteckning Prov', 'anteckningen flyttades');
    cmd(cmd_cell($pdo, 'SELECT contact_id FROM sample_hold WHERE id = ?', ['hold1']) === 'kt_full', 'hållarens kolumn flyttades');
    cmd(cmd_cell($pdo, 'SELECT snapshot_json FROM sample_hold WHERE id = ?', ['hold1']) === $holdJson, 'fryst snapshot-kolumn ligger kvar');
    cmd(cmd_cell($pdo, 'SELECT contact_id FROM sample_hold WHERE id = ?', ['hold2']) === 'kt_full', 'ofryst hallares kolumn flyttades');
    cmd(cmd_cell($pdo, 'SELECT snapshot_json FROM sample_hold WHERE id = ?', ['hold2']) === $openHoldJson, 'ofryst snapshot-kolumn ligger kvar');
    cmd(cmd_cell($pdo, 'SELECT contact_id FROM sample_audit WHERE id = ?', ['aud1']) === 'kt_thin', 'audit-tabellen rörs inte');
    cmd(cmd_email_ids($pdo, 'b.extra@example.test') === ['kt_full'], 'extra mejl pekar på mastern');
    cmd(cmd_email_ids($pdo, 'delad.fyll@example.test') === ['kt_full'], 'delad adress finns bara på mastern');
    cmd(cmd_email_ids($pdo, 'ada.full@example.test') === ['kt_full'], 'masterns mejl ligger kvar i indexet');
    foreach (['kt_malmo', 'kt_empty', 'kt_namn_a', 'kt_namn_b', 'kt_post_a', 'kt_post_b', 'kt_bro', 'kt_bro_b', 'kt_norm', 'kt_audit', 'kt_annan', 'kt_mail_a', 'kt_mail_b', 'kt_town_a', 'kt_town_b', 'kt_tel_1', 'kt_tel_2', 'kt_tel_3', 'kt_tel_4', 'kt_tel_5'] as $kept) {
        cmd(jeab_contacts_merge_fetch($pdo, $kept) !== null, $kept . ' finns kvar');
    }
    cmd((string) (jeab_contacts_merge_fetch($pdo, 'kt_bro')['street'] ?? '') === 'Brovagen 38 BRO', '38 BRO är oförändrat');

    $undoDry = cmd_hook(['action' => 'contacts.merge_duplicates', 'undo' => 1, 'undo_id' => $undoId]);
    cmd(($undoDry['written'] ?? true) === false && jeab_contacts_merge_fetch($pdo, 'kt_thin') === null, 'ånger utan dry=0 skriver inte');
    cmd_quiet($undoDry, 'torr ånger');
    $undoBare = cmd_hook(['action' => 'contacts.merge_duplicates_undo', 'dry' => 0, 'undo_id' => $undoId]);
    cmd(($undoBare['status'] ?? 0) === 400 && jeab_contacts_merge_fetch($pdo, 'kt_thin') === null, 'ånger kräver confirm:undo');
    cmd(trim(cmd_cell($pdo, 'SELECT undone_at FROM contacts_merge_undo WHERE undo_id = ? LIMIT 1', [$undoId])) === '', 'nekad ånger stämplas inte');
    $undoWet = cmd_hook([
        'action' => 'contacts.merge_duplicates_undo',
        'dry' => 0,
        'confirm' => 'undo',
        'undo_id' => $undoId,
    ]);
    cmd(($undoWet['ok'] ?? false) === true && ($undoWet['written'] ?? false) === true, 'ångern skriver');
    cmd_quiet($undoWet, 'våt ånger');
    cmd(in_array('kt_thin', is_array($undoWet['restored'] ?? null) ? $undoWet['restored'] : [], true), 'återställd id följer med');
    $restoredThin = jeab_contacts_merge_fetch($pdo, 'kt_thin');
    $restoredFull = jeab_contacts_merge_fetch($pdo, 'kt_full');
    cmd(is_array($restoredThin) && is_array($restoredFull), 'båda kontakterna finns efter ånger');
    cmd(jeab_contacts_merge_read($restoredFull, 'email3') === '' && jeab_contacts_merge_read($restoredFull, 'phone2') === '', 'fyllda fält tömdes');
    cmd(jeab_contacts_merge_read($restoredFull, 'email2') === 'delad.fyll@example.test', 'masterns egna mejl ligger kvar');
    cmd(cmd_cell($pdo, 'SELECT contact_id FROM bookings WHERE id = ?', [$bookId]) === 'kt_thin', 'bokningen pekar tillbaka');
    cmd(cmd_cell($pdo, 'SELECT contact_id FROM reminders WHERE id = ?', [$reminderId]) === 'kt_thin', 'påminnelsen pekar tillbaka');
    cmd(cmd_cell($pdo, 'SELECT contact_id FROM purchases WHERE reminder_id = ?', [$holderId]) === 'kt_thin', 'ordern pekar tillbaka');
    $buyBack = cmd_json($pdo, 'SELECT payload FROM purchases WHERE reminder_id = ?', [$holderId]);
    cmd((string) ($buyBack['contact_id'] ?? '') === 'kt_thin' && (string) ($buyBack['snapshot']['contact_id'] ?? '') === 'kt_thin', 'orderns json pekar tillbaka');
    cmd(cmd_cell($pdo, 'SELECT contact_id FROM inkorg_list WHERE id = ?', ['ls-thin']) === 'kt_thin', 'listan pekar tillbaka');
    cmd(cmd_cell($pdo, 'SELECT contact_id FROM customers WHERE id = ?', [$customerId]) === 'kt_thin', 'kunden pekar tillbaka');
    cmd(cmd_cell($pdo, 'SELECT contact_id FROM solkampanj_recipients WHERE id = ?', [$solId]) === 'kt_thin', 'solkampanjen pekar tillbaka');
    cmd(cmd_cell($pdo, 'SELECT contact_id FROM sample_hold WHERE id = ?', ['hold1']) === 'kt_thin', 'hållaren pekar tillbaka');
    cmd(cmd_cell($pdo, 'SELECT snapshot_json FROM sample_hold WHERE id = ?', ['hold1']) === $holdJson, 'fryst snapshot är orörd efter ånger');
    cmd(cmd_cell($pdo, 'SELECT contact_id FROM sample_audit WHERE id = ?', ['aud1']) === 'kt_thin', 'audit-tabellen är orörd efter ånger');
    cmd(cmd_email_ids($pdo, 'delad.fyll@example.test') === ['kt_full', 'kt_thin'], 'delad adress finns på båda igen');
    cmd(cmd_email_ids($pdo, 'b.extra@example.test') === ['kt_thin'], 'extra mejl pekar tillbaka');
    cmd(cmd_email_ids($pdo, 'ada.full@example.test') === ['kt_full'], 'masterns mejl stals inte');
    $again = cmd_hook([
        'action' => 'contacts.merge_duplicates_undo',
        'dry' => 0,
        'confirm' => 'undo',
        'undo_id' => $undoId,
    ]);
    cmd(($again['status'] ?? 0) === 409, 'andra ångern nekas');

    $normJson = cmd_cell($pdo, 'SELECT row_json FROM contacts WHERE id = ?', ['kt_norm']);
    cmd(str_contains($normJson, 'Normgatan 12A'), 'normgatan sparades hopskriven');
    $planted = str_replace('Normgatan 12A', 'Normgatan 12 A', $normJson);
    $pdo->prepare('UPDATE contacts SET row_json = ? WHERE id = ?')->execute([$planted, 'kt_norm']);
    $broJson = cmd_cell($pdo, 'SELECT row_json FROM contacts WHERE id = ?', ['kt_bro']);
    $broRow = json_decode($broJson, true);
    cmd(is_array($broRow) && (string) ($broRow['street'] ?? '') === 'Brovagen 38 BRO', '38 BRO ligger kvar innan nyckelprovet');
    if (is_array($broRow)) {
        $broRow['street_key'] = 'inte-samma-nyckel';
        $broEncoded = json_encode($broRow, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pdo->prepare('UPDATE contacts SET row_json = ? WHERE id = ?')->execute([$broEncoded === false ? $broJson : $broEncoded, 'kt_bro']);
        if (cmd_has_col($pdo, 'contacts', 'street_key')) {
            $pdo->prepare('UPDATE contacts SET street_key = ? WHERE id = ?')->execute(['inte-samma-nyckel', 'kt_bro']);
        }
    }
    unset($GLOBALS['_JEAB_KONTAKTER_MEM'], $GLOBALS['_JEAB_KONTAKTER_MEM_KEY']);
    cmd((string) (jeab_contacts_merge_fetch($pdo, 'kt_norm')['street'] ?? '') === 'Normgatan 12 A', 'gata med mellanslag är planterad');
    $normDry = cmd_hook(['action' => 'contacts.normalize_addresses']);
    $normDryCounts = is_array($normDry['counts'] ?? null) ? $normDry['counts'] : [];
    cmd(($normDry['written'] ?? true) === false && ($normDry['contact_ids'] ?? null) === ['kt_norm'], 'normalisering listar bara gatan med mellanslag');
    cmd(($normDryCounts['would'] ?? -1) === 1 && ($normDryCounts['changed'] ?? -1) === 0 && count(is_array($normDry['contact_ids'] ?? null) ? $normDry['contact_ids'] : []) === 1, 'torr would räknar bara gatan som skulle ändras');
    cmd(!in_array('kt_bro', is_array($normDry['contact_ids'] ?? null) ? $normDry['contact_ids'] : [], true), '38 BRO och en avvikande street_key normaliseras inte');
    cmd_quiet($normDry, 'torr normalisering');
    cmd((string) (jeab_contacts_merge_fetch($pdo, 'kt_norm')['street'] ?? '') === 'Normgatan 12 A', 'torr normalisering skriver inte');
    $normBare = cmd_hook(['action' => 'contacts.normalize_addresses', 'dry' => 0]);
    cmd(($normBare['status'] ?? 0) === 400 && (string) (jeab_contacts_merge_fetch($pdo, 'kt_norm')['street'] ?? '') === 'Normgatan 12 A', 'normalisering kräver confirm:normalize');
    $normWet = cmd_hook(['action' => 'contacts.normalize_addresses', 'dry' => 0, 'confirm' => 'normalize']);
    cmd(($normWet['ok'] ?? false) === true && ($normWet['written'] ?? false) === true, 'normaliseringen skriver');
    cmd_quiet($normWet, 'våt normalisering');
    $normUndo = (string) ($normWet['undo_id'] ?? '');
    cmd(preg_match('/^[a-f0-9]{16}$/', $normUndo) === 1, 'normaliseringens undo_id är 16 hex');
    cmd((string) (jeab_contacts_merge_fetch($pdo, 'kt_norm')['street'] ?? '') === 'Normgatan 12A', 'mellanslaget skrevs ihop');
    cmd((string) (jeab_contacts_merge_fetch($pdo, 'kt_bro')['street'] ?? '') === 'Brovagen 38 BRO', '38 BRO rördes inte');
    $normWetCounts = is_array($normWet['counts'] ?? null) ? $normWet['counts'] : [];
    cmd(($normWetCounts['changed'] ?? -1) === ($normDryCounts['would'] ?? -2) && ($normWetCounts['would'] ?? -1) === ($normDryCounts['would'] ?? -2), 'våt changed matchar torr would');
    $normSecond = cmd_hook(['action' => 'contacts.normalize_addresses']);
    $normSecondCounts = is_array($normSecond['counts'] ?? null) ? $normSecond['counts'] : [];
    cmd(($normSecond['written'] ?? true) === false && ($normSecondCounts['would'] ?? -1) === 0 && ($normSecond['contact_ids'] ?? null) === [], 'redan normaliserad gata räknas inte');
    $normUndoDry = cmd_hook(['action' => 'contacts.normalize_addresses', 'undo' => 1, 'undo_id' => $normUndo]);
    cmd(($normUndoDry['written'] ?? true) === false && (string) (jeab_contacts_merge_fetch($pdo, 'kt_norm')['street'] ?? '') === 'Normgatan 12A', 'torr normaliseringsånger skriver inte');
    $normUndoBare = cmd_hook(['action' => 'contacts.normalize_addresses_undo', 'dry' => 0, 'undo_id' => $normUndo]);
    cmd(($normUndoBare['status'] ?? 0) === 400, 'normaliseringsånger kräver confirm:undo');
    cmd(trim(cmd_cell($pdo, 'SELECT undone_at FROM contacts_merge_undo WHERE undo_id = ? LIMIT 1', [$normUndo])) === '', 'nekad normaliseringsånger stämplas inte');
    $normUndoWet = cmd_hook([
        'action' => 'contacts.normalize_addresses_undo',
        'dry' => 0,
        'confirm' => 'undo',
        'undo_id' => $normUndo,
    ]);
    cmd(($normUndoWet['ok'] ?? false) === true && ($normUndoWet['written'] ?? false) === true, 'normaliseringsångern skriver');
    cmd_quiet($normUndoWet, 'våt normaliseringsånger');
    cmd((string) (jeab_contacts_merge_fetch($pdo, 'kt_norm')['street'] ?? '') === 'Normgatan 12 A', 'mellanslaget kom tillbaka');
    $normAgain = cmd_hook([
        'action' => 'contacts.normalize_addresses_undo',
        'dry' => 0,
        'confirm' => 'undo',
        'undo_id' => $normUndo,
    ]);
    cmd(($normAgain['status'] ?? 0) === 409, 'normaliseringens andra ånger nekas');

    cmd_put('kt_fail_m', [
        'street' => 'Failgatan 2',
        'postal_code' => '90909',
        'city' => 'Failstad',
        'company' => 'jarfalla',
        'created_at' => '2020-07-01 00:00:00',
        'updated_at' => '2020-07-01 00:00:00',
        'owners' => [[
            'name' => 'Fail Master',
            'email' => 'fail.master@example.test',
            'phone' => '+46709000001',
            'contact' => true,
        ]],
    ]);
    cmd_put('kt_fail_d', [
        'street' => 'Failgatan 2',
        'postal_code' => '90909',
        'city' => 'Failstad',
        'company' => 'jarfalla',
        'created_at' => '2024-07-01 00:00:00',
        'updated_at' => '2024-07-01 00:00:00',
        'owners' => [[
            'name' => '',
            'email' => 'fail.extra@example.test',
            'phone' => '',
            'contact' => true,
        ]],
    ]);
    $failText = $mysql ? 'LONGTEXT' : 'TEXT';
    $failId = $mysql ? 'VARCHAR(64) NOT NULL' : 'TEXT NOT NULL';
    $pdo->exec('CREATE TABLE sample_fail (id ' . $failId . ', contact_id ' . ($mysql ? 'VARCHAR(64)' : 'TEXT') . ', payload ' . $failText . ', PRIMARY KEY (id))');
    $failPayload = json_encode(['contact_id' => 'kt_fail_d', 'label' => 'Fail Lank'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare('INSERT INTO sample_fail (id, contact_id, payload) VALUES (?, ?, ?)')->execute(['sf1', 'kt_fail_d', $failPayload]);
    if ($mysql) {
        $pdo->exec(
            'CREATE TRIGGER jeab_cmd_fail_link BEFORE UPDATE ON sample_fail FOR EACH ROW '
            . "BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'link fail'; END"
        );
    } else {
        $pdo->exec(
            'CREATE TRIGGER jeab_cmd_fail_link BEFORE UPDATE ON sample_fail '
            . "BEGIN SELECT RAISE(ABORT, 'link fail'); END"
        );
    }
    $failMasterBefore = cmd_cell($pdo, 'SELECT row_json FROM contacts WHERE id = ?', ['kt_fail_m']);
    $failDupBefore = cmd_cell($pdo, 'SELECT row_json FROM contacts WHERE id = ?', ['kt_fail_d']);
    $failLinkBefore = cmd_cell($pdo, 'SELECT payload FROM sample_fail WHERE id = ?', ['sf1']);
    $failRun = cmd_hook(['action' => 'contacts.merge_duplicates', 'dry' => 0, 'confirm' => 'merge']);
    $failRows = is_array($failRun['failed_ids'] ?? null) ? $failRun['failed_ids'] : [];
    $failHit = null;
    foreach ($failRows as $failRow) {
        if (is_array($failRow) && (string) ($failRow['id'] ?? '') === 'kt_fail_d') {
            $failHit = $failRow;
        }
    }
    cmd(is_array($failHit) && (string) ($failHit['reason'] ?? '') === 'link', 'misslyckad länk hamnar i failed_ids');
    cmd(jeab_contacts_merge_fetch($pdo, 'kt_fail_d') !== null, 'dubbletten med bruten länk ligger kvar');
    cmd(cmd_cell($pdo, 'SELECT row_json FROM contacts WHERE id = ?', ['kt_fail_m']) === $failMasterBefore, 'mastern ändrades inte för det paret');
    cmd(cmd_cell($pdo, 'SELECT row_json FROM contacts WHERE id = ?', ['kt_fail_d']) === $failDupBefore, 'dubblettens rad ändrades inte');
    cmd(cmd_cell($pdo, 'SELECT contact_id FROM sample_fail WHERE id = ?', ['sf1']) === 'kt_fail_d', 'bruten länk pekar kvar');
    cmd(cmd_cell($pdo, 'SELECT payload FROM sample_fail WHERE id = ?', ['sf1']) === $failLinkBefore, 'bruten json-länk skrivs inte över');
    cmd((int) cmd_cell($pdo, "SELECT COUNT(*) FROM contacts_merge_undo WHERE row_key IN ('kt_fail_d', 'sf1') OR tbl = 'sample_fail'") === 0, 'paret med bruten länk loggas inte');
    cmd(jeab_contacts_merge_read(jeab_contacts_merge_fetch($pdo, 'kt_fail_m') ?: [], 'email2') === '', 'parets fält fylldes inte');
}

function cmd_mariadb(): ?array
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
    $tmp = sys_get_temp_dir() . '/jeab-cmd-mysql-' . bin2hex(random_bytes(4));
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
            cmd_rm_tree($holder->tmp);
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

function cmd_child(string $backend, int $port): void
{
    putenv('JEAB_CMD_CHILD=' . $backend);
    putenv('JEAB_CMD_PORT=' . (string) $port);
    $outFile = tempnam(sys_get_temp_dir(), 'cmd-out-');
    $errFile = tempnam(sys_get_temp_dir(), 'cmd-err-');
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
    putenv('JEAB_CMD_CHILD');
    putenv('JEAB_CMD_PORT');
    echo $out;
    if ($err !== '') {
        fwrite(STDERR, $err);
    }
    if ($code !== 0) {
        exit($code > 0 ? $code : 1);
    }
}

$cmdChild = getenv('JEAB_CMD_CHILD');
$cmdChild = is_string($cmdChild) ? $cmdChild : '';
if ($cmdChild === 'sqlite' || $cmdChild === 'mysql') {
    cmd_scenarios($cmdChild, (int) getenv('JEAB_CMD_PORT'));
    exit(0);
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

cmd_child('sqlite', 0);
$cmdDb = cmd_mariadb();
if ($cmdDb !== null) {
    cmd_child('mysql', $cmdDb['port']);
}

echo "ALL OK\n";
