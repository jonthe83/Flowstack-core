<?php

declare(strict_types=1);

/**
 * inbox.contacts_from_threads. Plattform filtreras, flaggan av är 403,
 * confirm krävs, skapande, befintlig e-post länkas utan sammanslagning,
 * undo raderar oanvänd kontakt, påminnelselänk är in_use, andra undo 409.
 * Misslyckad ångerlogg skapar inget. Fel på andra create lämnar den första,
 * och undo tar bort den. Pending-raden hoppas över.
 * autotest- hoppas över som test. Pending-träff bara inom tio sekunder efter raden.
 * Påhittade namn och example.test. Inga värden i något svar.
 */

function icft(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function icft_rm_tree(string $dir): void
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
            icft_rm_tree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function icft_cols(PDO $pdo, string $table): array
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

function icft_add(PDO $pdo, string $table, string $column): void
{
    $cols = icft_cols($pdo, $table);
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

function icft_cid(PDO $pdo, string $table, string $id): string
{
    $stmt = $pdo->prepare('SELECT contact_id FROM ' . $table . ' WHERE id = ?');
    $stmt->execute([$id]);

    return trim((string) $stmt->fetchColumn());
}

/** @param list<array<string, mixed>> $rows */
function icft_has(array $rows, string $id, string $key = 'thread_id'): bool
{
    foreach ($rows as $row) {
        if (is_array($row) && (string) ($row[$key] ?? '') === $id) {
            return true;
        }
    }

    return false;
}

/** @param list<array<string, mixed>> $rows */
function icft_reason(array $rows, string $id): string
{
    foreach ($rows as $row) {
        if (is_array($row) && (string) ($row['thread_id'] ?? '') === $id) {
            return (string) ($row['reason'] ?? '');
        }
    }

    return '';
}

/** @return list<string> */
function icft_secrets(): array
{
    return [
        'Skapesson', 'Redansson', 'Noreply', 'Leadkit', 'example.test',
        '0701234567', 'no-reply', 'jarfalla-demo', 'leadkit.com', 'skapad.from',
        'redan.from', 'egen.from', 'skrap.from',
    ];
}

/** @param array<string, mixed> $report */
function icft_clean(array $report): void
{
    $json = (string) json_encode($report, JSON_UNESCAPED_UNICODE);
    foreach (icft_secrets() as $secret) {
        icft(!str_contains($json, $secret), 'svaret har inget värde ' . $secret);
    }
}

function icft_boot(string $backend, int $port): void
{
    $tmp = sys_get_temp_dir() . '/jeab-from-threads-' . $backend . '-' . bin2hex(random_bytes(4));
    mkdir($tmp . '/secrets', 0775, true);
    mkdir($tmp . '/inkorg', 0775, true);
    $public = $tmp . '/public-data';
    mkdir($public, 0775, true);
    register_shutdown_function(static function () use ($tmp): void {
        icft_rm_tree($tmp);
    });
    $GLOBALS['_JEAB_DATA_DIR'] = $tmp;
    $GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $public;
    putenv('JEAB_DISABLE_SQLITE=0');
    putenv('CONTACTS_SOLE');
    putenv('CONTACTS_STRICT');
    putenv('INBOX_CONTACTS_FROM_THREADS');
    unset(
        $_ENV['CONTACTS_SOLE'],
        $_ENV['CONTACTS_STRICT'],
        $_ENV['INBOX_CONTACTS_FROM_THREADS'],
        $GLOBALS['_JEAB_CONTACTS_SOLE'],
        $GLOBALS['_JEAB_CONTACTS_STRICT'],
        $GLOBALS['_JEAB_INBOX_CONTACTS_FROM_THREADS'],
        $GLOBALS['_JEAB_CONTACTS_SOLE_STORED']
    );
    $db = [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ];
    if ($backend === 'mysql') {
        if ($port < 1) {
            icft(false, 'MySQL saknar port');
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
    file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-from-threads']));
    require dirname(__DIR__) . '/src/bootstrap.php';
}

function icft_scenarios(string $backend, int $port): void
{
    icft_boot($backend, $port);
    $root = dirname(__DIR__);
    $hookSrc = (string) file_get_contents($root . '/hook.php');
    $dispatchSrc = (string) file_get_contents($root . '/src/contacts_sole.php');
    icft(str_contains($hookSrc, "'inbox.contacts_from_threads'") && str_contains($hookSrc, "'inbox.contacts_from_threads_undo'"), 'hook.php tar båda åtgärderna');
    icft(str_contains($dispatchSrc, "=== 'inbox.contacts_from_threads'") && str_contains($dispatchSrc, 'jeab_inbox_contacts_from_threads('), 'dispatch skickar create');
    icft(str_contains($dispatchSrc, 'jeab_inbox_contacts_from_threads_undo('), 'dispatch skickar undo');

    $store = jeab_store();
    icft($store instanceof JeabPdoStore, $backend . '-lagret är öppet');
    $pdo = $store->pdo();
    icft((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === $backend, 'lagret är ' . $backend);
    $mysql = $backend === 'mysql';
    jeab_settings_put($pdo, $backend, JEAB_KONTAKTER_STORAGE_KEY, 'sql');
    if (icft_cols($pdo, 'contacts') === []) {
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
    icft_add($pdo, 'contacts', 'row_json');
    icft_add($pdo, 'contacts', 'company');
    if (icft_cols($pdo, 'inkorg_threads') === []) {
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
    icft_add($pdo, 'inkorg_threads', 'contact_id');
    icft_add($pdo, 'inkorg_threads', 'row_json');
    if (icft_cols($pdo, 'inkorg_list') === []) {
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
    icft_add($pdo, 'inkorg_list', 'company');
    icft_add($pdo, 'inkorg_list', 'customer_email');
    icft_add($pdo, 'inkorg_list', 'contact_id');
    icft_add($pdo, 'inkorg_list', 'row_json');
    if (function_exists('jeab_ensure_lead_mail_tables')) {
        jeab_ensure_lead_mail_tables($pdo, $mysql ? 'mysql' : 'sqlite');
    }
    if (function_exists('jeab_ensure_inbox_link_undo_table')) {
        jeab_ensure_inbox_link_undo_table($pdo, $mysql ? 'mysql' : 'sqlite');
    }

    $stamp = '2026-01-01 00:00:00';
    $contactSql = 'INSERT INTO contacts (id, row_json, company) VALUES (?, ?, ?)';
    if (isset(icft_cols($pdo, 'contacts')['created_at'])) {
        $contactSql = 'INSERT INTO contacts (id, row_json, company, created_at, updated_at) VALUES (?, ?, ?, ?, ?)';
    }
    $insertContact = $pdo->prepare($contactSql);
    $addContact = static function (string $id, string $company, string $email, string $name) use ($insertContact, $stamp, $pdo): void {
        $body = json_encode([
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'company' => $company,
            'owners' => [['name' => $name, 'email' => $email, 'contact' => true]],
            'note' => 'kontakt-behall',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        icft(is_string($body), 'kontakten kan kodas');
        if (isset(icft_cols($pdo, 'contacts')['created_at'])) {
            $insertContact->execute([$id, $body, $company, $stamp, $stamp]);
        } else {
            $insertContact->execute([$id, $body, $company]);
        }
    };
    $addContact('kt_redan', 'jarfalla', 'redan.from@example.test', 'Redansson Behall');
    $addContact('kt_malmo', 'malmo', 'annan.bolag@example.test', 'Malmo Bara');
    // Fyllnadskontakter så att skyddet mot massradering (>5 %) inte slår till vid undo.
    require_once dirname(__DIR__) . '/src/inkorg_contact_create.php';
    for ($f = 0; $f < 30; $f++) {
        jeab_kontakt_create_unmerged(['name' => 'Fyll ' . $f, 'email' => 'fyll' . $f . '@example.test', 'company' => 'jarfalla']);
    }
    $beforeContacts = (int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn();
    $beforeRedan = (string) $pdo->query("SELECT row_json FROM contacts WHERE id = 'kt_redan'")->fetchColumn();

    $list = $pdo->prepare('INSERT INTO inkorg_list (id, company, customer_email, contact_id, row_json) VALUES (?, ?, ?, ?, ?)');
    $rowJson = static function (array $extra): string {
        $json = json_encode(array_merge(['note' => 'trad-behall'], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '{}';
    };
    $typeLead = ['list_type' => 'Ny lead', 'labels' => ['type' => 'Ny lead']];
    $list->execute(['th-create', 'jarfalla', 'skapad.from@example.test', '', $rowJson($typeLead + [
        'company' => 'jarfalla',
        'lead_customer' => ['name' => 'Skapesson Ny', 'email' => 'skapad.from@example.test', 'phone' => '0701234567'],
    ])]);
    $list->execute(['th-exist', 'jarfalla', '', '', $rowJson($typeLead + ['company' => 'jarfalla'])]);
    $list->execute(['th-platform', 'jarfalla', 'no-reply@example.test', '', $rowJson($typeLead + ['company' => 'jarfalla'])]);
    $list->execute(['th-own', 'jarfalla', 'info@jarfalla.example.test', '', $rowJson($typeLead + ['company' => 'jarfalla'])]);
    $list->execute(['th-leadkit', 'jarfalla', 'leads@leadkit.com', '', $rowJson($typeLead + ['company' => 'jarfalla'])]);
    $list->execute(['th-nocompany', '', 'noll.bolag@example.test', '', $rowJson($typeLead + [
        'lead_customer' => ['email' => 'noll.bolag@example.test'],
    ])]);
    $list->execute(['th-noemail', 'jarfalla', '', '', $rowJson($typeLead + ['company' => 'jarfalla', 'lead_customer' => ['name' => 'Utan Post']])]);
    $list->execute(['th-type', 'jarfalla', 'skrap.from@example.test', '', $rowJson([
        'company' => 'jarfalla',
        'list_type' => 'Skräp',
        'labels' => ['type' => 'Skräp'],
        'lead_customer' => ['email' => 'skrap.from@example.test'],
    ])]);
    $list->execute(['th-other', 'jarfalla', 'annan.bolag@example.test', '', $rowJson($typeLead + [
        'company' => 'jarfalla',
        'lead_customer' => ['email' => 'annan.bolag@example.test'],
    ])]);
    $list->execute(['th-linked', 'jarfalla', 'redan.from@example.test', 'kt_redan', $rowJson($typeLead + [
        'company' => 'jarfalla',
        'contact_id' => 'kt_redan',
    ])]);
    $list->execute(['th-inuse', 'jarfalla', 'inuse.from@example.test', '', $rowJson($typeLead + [
        'company' => 'jarfalla',
        'lead_customer' => ['name' => 'Inusesson', 'email' => 'inuse.from@example.test'],
    ])]);
    $list->execute(['autotest-lead', 'jarfalla', 'autotest.trad@example.test', '', $rowJson($typeLead + [
        'company' => 'jarfalla',
        'lead_customer' => ['name' => 'Autotest Trad', 'email' => 'autotest.trad@example.test'],
    ])]);

    $thread = $pdo->prepare('INSERT INTO inkorg_threads (id, status, updated_at, row_json, contact_id) VALUES (?, ?, ?, ?, ?)');
    foreach (['th-create', 'th-exist', 'th-inuse'] as $tid) {
        $thread->execute([$tid, 'open', $stamp, $rowJson(['company' => 'jarfalla'] + $typeLead), '']);
    }

    if (function_exists('jeab_ensure_lead_mail_tables')) {
        $party = $pdo->prepare('INSERT INTO inbox_lead_party (thread_id, email, name, phone, source_id, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
        $party->execute(['th-exist', 'redan.from@example.test', 'Redansson Behall', '0701234567', 'test', $stamp]);
    }

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-from-threads';

    $dry = jeab_contacts_sole_dispatch(['action' => 'inbox.contacts_from_threads']);
    icft(($dry['ok'] ?? false) === true && ($dry['dry'] ?? false) === true && ($dry['written'] ?? true) === false, 'dry=1 är standard');
    icft(icft_has($dry['would_create'] ?? [], 'th-create') && icft_has($dry['would_create'] ?? [], 'th-inuse'), 'nya e-poster skulle skapas');
    icft(icft_has($dry['would_link_existing'] ?? [], 'th-exist'), 'befintlig e-post från lead_party skulle länkas');
    icft(icft_reason($dry['skipped'] ?? [], 'th-platform') === 'platform', 'no-reply är platform');
    icft(icft_reason($dry['skipped'] ?? [], 'th-own') === 'platform', 'egen domän är platform');
    icft(icft_reason($dry['skipped'] ?? [], 'th-leadkit') === 'platform', 'leadplattform är platform');
    icft(icft_reason($dry['skipped'] ?? [], 'th-nocompany') === 'no_company', 'utan bolag är no_company');
    icft(icft_reason($dry['skipped'] ?? [], 'th-noemail') === 'no_email', 'utan e-post är no_email');
    icft(icft_reason($dry['skipped'] ?? [], 'th-type') === 'type', 'fel typ är type');
    icft(icft_reason($dry['skipped'] ?? [], 'th-other') === 'other_company', 'e-post i annat bolag är other_company');
    icft(icft_reason($dry['skipped'] ?? [], 'th-linked') === 'linked', 'redan länkad är linked');
    icft(icft_reason($dry['skipped'] ?? [], 'autotest-lead') === 'test', 'autotest-tråd är test');
    icft(!icft_has($dry['would_create'] ?? [], 'autotest-lead') && !icft_has($dry['would_link_existing'] ?? [], 'autotest-lead'), 'autotest-tråd skapas inte');
    $skipListed = 0;
    foreach ($dry['skipped'] ?? [] as $row) {
        if (is_array($row)) {
            $skipListed++;
        }
    }
    icft((int) ($dry['total']['skipped'] ?? -1) === $skipListed, 'test räknas som övriga hopp');
    icft_clean($dry);
    icft((int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn() === $beforeContacts, 'torr körning skapar inga kontakter');
    icft(icft_cid($pdo, 'inkorg_list', 'th-create') === '', 'torr körning länkar inte');

    $off = jeab_contacts_sole_dispatch(['action' => 'inbox.contacts_from_threads', 'dry' => 0, 'confirm' => 'create']);
    icft(($off['ok'] ?? true) === false && ($off['status'] ?? 0) === 403, 'flaggan av är 403');
    icft(($off['error'] ?? '') === 'INBOX_CONTACTS_FROM_THREADS är av.', 'feltexten nämner flaggan');
    icft((int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn() === $beforeContacts, 'flaggan av skriver inga kontakter');
    icft(icft_cid($pdo, 'inkorg_list', 'th-create') === '', 'flaggan av länkar inte');
    icft_clean($off);

    $GLOBALS['_JEAB_INBOX_CONTACTS_FROM_THREADS'] = true;
    $noConfirm = jeab_contacts_sole_dispatch(['action' => 'inbox.contacts_from_threads', 'dry' => 0]);
    icft(($noConfirm['ok'] ?? true) === false && ($noConfirm['status'] ?? 0) === 400 && ($noConfirm['error'] ?? '') === 'Kräver confirm:create.', 'confirm saknas är 400');
    icft((int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn() === $beforeContacts, 'utan confirm skapas inget');
    icft_clean($noConfirm);

    $undoBefore = (int) $pdo->query('SELECT COUNT(*) FROM inbox_link_contacts_undo')->fetchColumn();
    $GLOBALS['_JEAB_ICFT_FAIL_LOG'] = true;
    $logFail = jeab_contacts_sole_dispatch(['action' => 'inbox.contacts_from_threads', 'dry' => 0, 'confirm' => 'create']);
    unset($GLOBALS['_JEAB_ICFT_FAIL_LOG']);
    icft(($logFail['ok'] ?? true) === false && ($logFail['status'] ?? 0) === 500, 'loggfel är 500');
    icft(($logFail['error'] ?? '') === 'Kunde inte skriva ångerloggen.', 'loggfelet har rätt text');
    icft(($logFail['written'] ?? true) === false && ($logFail['undo_id'] ?? 'x') === '', 'loggfel utan skapad rad har inget undo_id');
    icft_clean($logFail);
    icft((int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn() === $beforeContacts, 'loggfel skapar inga kontakter');
    icft(icft_cid($pdo, 'inkorg_list', 'th-create') === '' && icft_cid($pdo, 'inkorg_threads', 'th-create') === '', 'loggfel länkar inte listan');
    icft(icft_cid($pdo, 'inkorg_list', 'th-exist') === '' && icft_cid($pdo, 'inkorg_list', 'th-inuse') === '', 'loggfel länkar inga andra trådar');
    icft((int) $pdo->query('SELECT COUNT(*) FROM inbox_link_contacts_undo')->fetchColumn() === $undoBefore, 'loggfel lämnar ingen ångerrad');

    $GLOBALS['_JEAB_ICFT_FAIL_CREATE_AT'] = 2;
    $mid = jeab_contacts_sole_dispatch(['action' => 'inbox.contacts_from_threads', 'dry' => 0, 'confirm' => 'create']);
    unset($GLOBALS['_JEAB_ICFT_FAIL_CREATE_AT']);
    icft(($mid['ok'] ?? true) === false && ($mid['status'] ?? 0) === 500, 'fel på andra create är 500');
    icft(($mid['written'] ?? false) === true && preg_match('/^[a-f0-9]{16}$/', (string) ($mid['undo_id'] ?? '')) === 1, 'avbruten körning lämnar undo_id');
    icft_clean($mid);
    $firstId = icft_cid($pdo, 'inkorg_list', 'th-create');
    icft($firstId !== '' && $firstId !== 'kt_redan', 'första kontakten finns och är länkad');
    icft(icft_cid($pdo, 'inkorg_threads', 'th-create') === $firstId, 'första tråden är länkad i båda tabellerna');
    icft(icft_cid($pdo, 'inkorg_list', 'th-inuse') === '' && icft_cid($pdo, 'inkorg_threads', 'th-inuse') === '', 'andra create skapade ingen länk');
    icft((int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn() === $beforeContacts + 1, 'bara den första kontakten skapades');
    $pendingOpen = $pdo->prepare("SELECT COUNT(*) FROM inbox_link_contacts_undo WHERE undo_id = ? AND action = 'create' AND new_contact_id = '' AND undone_at IS NULL");
    $pendingOpen->execute([(string) $mid['undo_id']]);
    icft((int) $pendingOpen->fetchColumn() === 1, 'pending-raden ligger kvar tills undo');

    $midUndo = jeab_contacts_sole_dispatch([
        'action' => 'inbox.contacts_from_threads_undo',
        'undo_id' => $mid['undo_id'],
        'dry' => 0,
    ]);
    icft(($midUndo['ok'] ?? false) === true && ($midUndo['written'] ?? false) === true, 'undo efter avbrott skriver');
    icft(icft_has($midUndo['deleted'] ?? [], $firstId, 'contact_id'), 'undo raderar den första kontakten');
    icft(icft_cid($pdo, 'inkorg_list', 'th-create') === '' && icft_cid($pdo, 'inkorg_threads', 'th-create') === '', 'undo tar bort den första länken');
    icft(icft_cid($pdo, 'inkorg_list', 'th-exist') === '', 'undo återställer länken till befintlig kontakt');
    $pendingSkip = false;
    foreach ($midUndo['skipped'] ?? [] as $row) {
        if (is_array($row) && (string) ($row['reason'] ?? '') === 'pending') {
            $pendingSkip = true;
            icft((string) ($row['contact_id'] ?? 'x') === '', 'pending har inget kontakt-id');
        }
    }
    icft($pendingSkip, 'pending-raden är skipped pending');
    icft_clean($midUndo);
    icft((int) $pdo->query('SELECT COUNT(*) FROM contacts WHERE id = ' . $pdo->quote($firstId))->fetchColumn() === 0, 'första kontakten är borta');
    icft((int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn() === $beforeContacts, 'fyllnadskontakterna ligger kvar');
    icft((string) $pdo->query("SELECT row_json FROM contacts WHERE id = 'kt_redan'")->fetchColumn() === $beforeRedan, 'befintlig kontakt orörd efter avbruten körning');
    $pendingDone = $pdo->prepare("SELECT COUNT(*) FROM inbox_link_contacts_undo WHERE undo_id = ? AND action = 'create' AND new_contact_id = '' AND undone_at IS NOT NULL");
    $pendingDone->execute([(string) $mid['undo_id']]);
    icft((int) $pendingDone->fetchColumn() === 1, 'pending-raden är markerad undone');
    $midAgain = jeab_contacts_sole_dispatch([
        'action' => 'inbox.contacts_from_threads_undo',
        'undo_id' => $mid['undo_id'],
    ]);
    icft(($midAgain['ok'] ?? true) === false && ($midAgain['status'] ?? 0) === 409, 'undo av pending är redan gjord');
    icft_clean($midAgain);

    $wet = jeab_contacts_sole_dispatch(['action' => 'inbox.contacts_from_threads', 'dry' => 0, 'confirm' => 'create']);
    icft(($wet['ok'] ?? false) === true && ($wet['written'] ?? false) === true, 'dry=0 med flagga och confirm skriver');
    icft(preg_match('/^[a-f0-9]{16}$/', (string) ($wet['undo_id'] ?? '')) === 1, 'undo_id följer med');
    icft_clean($wet);
    $afterContacts = (int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn();
    icft($afterContacts === $beforeContacts + 2, 'två nya kontakter skapades');
    icft((string) $pdo->query("SELECT row_json FROM contacts WHERE id = 'kt_redan'")->fetchColumn() === $beforeRedan, 'befintlig kontakt är oförändrad');
    icft(icft_cid($pdo, 'inkorg_list', 'th-exist') === 'kt_redan', 'befintlig e-post länkas utan ny kontakt');
    $createdId = icft_cid($pdo, 'inkorg_list', 'th-create');
    icft($createdId !== '' && $createdId !== 'kt_redan', 'skapad tråd fick ny contact_id');
    $oldStmt = $pdo->prepare("SELECT old_value FROM inbox_link_contacts_undo WHERE undo_id = ? AND action = 'create' AND new_contact_id = ?");
    $oldStmt->execute([(string) $wet['undo_id'], $createdId]);
    $oldParsed = json_decode((string) $oldStmt->fetchColumn(), true);
    icft(is_array($oldParsed) && array_key_exists('email', $oldParsed) && array_key_exists('company', $oldParsed), 'create-raden sparar e-post och bolag');
    icft((string) ($oldParsed['company'] ?? '') === 'jarfalla', 'bolaget i old_value är rätt kontor');
    icft(is_string($oldParsed['email'] ?? null) && str_contains((string) $oldParsed['email'], '@'), 'old_value har en e-post');
    $inuseId = icft_cid($pdo, 'inkorg_list', 'th-inuse');
    icft($inuseId !== '' && $inuseId !== $createdId, 'in_use-tråden fick egen kontakt');

    $now = function_exists('jeab_now') ? jeab_now() : $stamp;
    $rem = $store->insertReminder([
        'title' => 'Lankad',
        'customer_name' => 'Inusesson',
        'email' => 'inuse.from@example.test',
        'company' => 'jarfalla',
        'contact_id' => $inuseId,
        'due_at' => '2026-11-05 09:00:00',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    icft((int) ($rem['id'] ?? 0) > 0, 'påminnelse pekar på skapad kontakt');

    $preview = jeab_contacts_sole_dispatch([
        'action' => 'inbox.contacts_from_threads_undo',
        'undo_id' => $wet['undo_id'],
    ]);
    icft(($preview['ok'] ?? false) === true && ($preview['dry'] ?? false) === true && ($preview['written'] ?? true) === false, 'undo utan dry=0 skriver inte');
    icft_clean($preview);
    icft(icft_cid($pdo, 'inkorg_list', 'th-create') === $createdId, 'torr undo lämnar länken');

    $undo = jeab_contacts_sole_dispatch([
        'action' => 'inbox.contacts_from_threads',
        'undo' => 1,
        'undo_id' => $wet['undo_id'],
        'dry' => 0,
    ]);
    icft(($undo['ok'] ?? false) === true && ($undo['written'] ?? false) === true, 'undo med dry=0 skriver');
    icft_clean($undo);
    icft(icft_has($undo['deleted'] ?? [], $createdId, 'contact_id'), 'oanvänd skapad kontakt raderas');
    $inuseSkip = false;
    foreach ($undo['skipped'] ?? [] as $row) {
        if (is_array($row) && (string) ($row['contact_id'] ?? '') === $inuseId && (string) ($row['reason'] ?? '') === 'in_use') {
            $inuseSkip = true;
        }
    }
    icft($inuseSkip, 'kontakt länkad från påminnelse är in_use');
    icft(icft_cid($pdo, 'inkorg_list', 'th-create') === '', 'skapad trådlänk togs bort');
    icft((int) $pdo->query('SELECT COUNT(*) FROM contacts WHERE id = ' . $pdo->quote($createdId))->fetchColumn() === 0, 'skapad kontakt är borta');
    icft((int) $pdo->query('SELECT COUNT(*) FROM contacts WHERE id = ' . $pdo->quote($inuseId))->fetchColumn() === 1, 'använd kontakt ligger kvar');
    icft((string) $pdo->query("SELECT row_json FROM contacts WHERE id = 'kt_redan'")->fetchColumn() === $beforeRedan, 'befintlig kontakt orörd efter undo');

    $second = jeab_contacts_sole_dispatch([
        'action' => 'inbox.contacts_from_threads_undo',
        'undo_id' => $wet['undo_id'],
    ]);
    icft(($second['ok'] ?? true) === false && ($second['status'] ?? 0) === 409, 'andra undo är 409');
    icft_clean($second);

    $earlyEmail = 'pending.tidig@example.test';
    $earlyJson = json_encode(['email' => $earlyEmail, 'company' => 'jarfalla'], JSON_UNESCAPED_UNICODE);
    icft(is_string($earlyJson), 'pending-hint kan kodas');
    $early = jeab_kontakt_create_unmerged([
        'name' => 'Tidig Pending',
        'email' => $earlyEmail,
        'company' => 'jarfalla',
    ]);
    $earlyId = is_array($early) ? trim((string) ($early['id'] ?? '')) : '';
    icft($earlyId !== '', 'tidig kontakt skapades');
    $futureUndo = bin2hex(random_bytes(8));
    $pdo->prepare('INSERT INTO inbox_link_contacts_undo (undo_id, created_at, thread_id, tbl, old_contact_id, new_contact_id, action, old_value) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$futureUndo, '2099-01-01 00:00:00', 'th-pending-tidig', 'contacts', '', '', 'create', $earlyJson]);
    $futureUndoRes = jeab_contacts_sole_dispatch([
        'action' => 'inbox.contacts_from_threads_undo',
        'undo_id' => $futureUndo,
        'dry' => 0,
    ]);
    icft(($futureUndoRes['ok'] ?? false) === true && ($futureUndoRes['deleted'] ?? []) === [], 'kontakt skapad före raden raderas inte');
    $futurePending = false;
    foreach ($futureUndoRes['skipped'] ?? [] as $row) {
        if (is_array($row) && (string) ($row['reason'] ?? '') === 'pending' && (string) ($row['contact_id'] ?? 'x') === '') {
            $futurePending = true;
        }
    }
    icft($futurePending, 'för tidig kontakt är skipped pending');
    icft_clean($futureUndoRes);
    icft((int) $pdo->query('SELECT COUNT(*) FROM contacts WHERE id = ' . $pdo->quote($earlyId))->fetchColumn() === 1, 'tidig kontakt ligger kvar');

    $hitEmail = 'pending.sen@example.test';
    $hitJson = json_encode(['email' => $hitEmail, 'company' => 'jarfalla'], JSON_UNESCAPED_UNICODE);
    $hitUndo = bin2hex(random_bytes(8));
    $hitAt = function_exists('jeab_now') ? jeab_now() : $stamp;
    $pdo->prepare('INSERT INTO inbox_link_contacts_undo (undo_id, created_at, thread_id, tbl, old_contact_id, new_contact_id, action, old_value) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$hitUndo, $hitAt, 'th-pending-hit', 'contacts', '', '', 'create', $hitJson]);
    $hit = jeab_kontakt_create_unmerged([
        'name' => 'Sen Pending',
        'email' => $hitEmail,
        'company' => 'jarfalla',
    ]);
    $hitId = is_array($hit) ? trim((string) ($hit['id'] ?? '')) : '';
    icft($hitId !== '' && $hitId !== $earlyId, 'sen kontakt skapades');
    $hitRes = jeab_contacts_sole_dispatch([
        'action' => 'inbox.contacts_from_threads_undo',
        'undo_id' => $hitUndo,
        'dry' => 0,
    ]);
    icft(($hitRes['ok'] ?? false) === true && ($hitRes['written'] ?? false) === true, 'entydig pending-kontakt raderas');
    icft(icft_has($hitRes['deleted'] ?? [], $hitId, 'contact_id'), 'raderat id är den sena kontakten');
    icft_clean($hitRes);
    icft((int) $pdo->query('SELECT COUNT(*) FROM contacts WHERE id = ' . $pdo->quote($hitId))->fetchColumn() === 0, 'sen kontakt är borta');
    icft((int) $pdo->query('SELECT COUNT(*) FROM contacts WHERE id = ' . $pdo->quote($earlyId))->fetchColumn() === 1, 'tidig kontakt raderas inte av den senare raden');

    $twoEmail = 'pending.tva@example.test';
    $twoJson = json_encode(['email' => $twoEmail, 'company' => 'jarfalla'], JSON_UNESCAPED_UNICODE);
    $twoUndo = bin2hex(random_bytes(8));
    $twoAt = function_exists('jeab_now') ? jeab_now() : $stamp;
    $pdo->prepare('INSERT INTO inbox_link_contacts_undo (undo_id, created_at, thread_id, tbl, old_contact_id, new_contact_id, action, old_value) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$twoUndo, $twoAt, 'th-pending-tva', 'contacts', '', '', 'create', $twoJson]);
    $twoA = jeab_kontakt_create_unmerged(['name' => 'Tva Ett', 'email' => $twoEmail, 'company' => 'jarfalla']);
    $twoB = jeab_kontakt_create_unmerged(['name' => 'Tva Tva', 'email' => $twoEmail, 'company' => 'jarfalla']);
    $twoAId = is_array($twoA) ? trim((string) ($twoA['id'] ?? '')) : '';
    $twoBId = is_array($twoB) ? trim((string) ($twoB['id'] ?? '')) : '';
    icft($twoAId !== '' && $twoBId !== '' && $twoAId !== $twoBId, 'två kontakter med samma e-post finns');
    $twoRes = jeab_contacts_sole_dispatch([
        'action' => 'inbox.contacts_from_threads_undo',
        'undo_id' => $twoUndo,
        'dry' => 0,
    ]);
    $twoPending = false;
    foreach ($twoRes['skipped'] ?? [] as $row) {
        if (is_array($row) && (string) ($row['reason'] ?? '') === 'pending') {
            $twoPending = true;
        }
    }
    icft(($twoRes['ok'] ?? false) === true && ($twoRes['deleted'] ?? []) === [] && $twoPending, 'två träffar är skipped pending');
    icft_clean($twoRes);
    icft((int) $pdo->query('SELECT COUNT(*) FROM contacts WHERE id = ' . $pdo->quote($twoAId))->fetchColumn() === 1, 'första tvetydiga ligger kvar');
    icft((int) $pdo->query('SELECT COUNT(*) FROM contacts WHERE id = ' . $pdo->quote($twoBId))->fetchColumn() === 1, 'andra tvetydiga ligger kvar');

    $edgeBase = '2024-03-01 12:00:00';
    $edgeTen = '2024-03-01 12:00:10';
    $edgeEleven = '2024-03-01 12:00:11';
    $edgePut = static function (string $id, string $email, string $at) use ($pdo): void {
        $body = json_encode([
            'id' => $id,
            'name' => 'Grans Pending',
            'email' => $email,
            'company' => 'jarfalla',
            'owners' => [['name' => 'Grans Pending', 'email' => $email, 'contact' => true]],
            'created_at' => $at,
            'updated_at' => $at,
            'note' => 'kontakt-behall',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        icft(is_string($body), 'tidsatt kontakt kan kodas');
        if (isset(icft_cols($pdo, 'contacts')['created_at'])) {
            $pdo->prepare('INSERT INTO contacts (id, row_json, company, created_at, updated_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$id, $body, 'jarfalla', $at, $at]);
        } else {
            $pdo->prepare('INSERT INTO contacts (id, row_json, company) VALUES (?, ?, ?)')
                ->execute([$id, $body, 'jarfalla']);
        }
    };
    $tenEmail = 'pending.tio@example.test';
    $tenJson = json_encode(['email' => $tenEmail, 'company' => 'jarfalla'], JSON_UNESCAPED_UNICODE);
    icft(is_string($tenJson), 'tio-sekunders-hint kan kodas');
    $tenUndo = bin2hex(random_bytes(8));
    $pdo->prepare('INSERT INTO inbox_link_contacts_undo (undo_id, created_at, thread_id, tbl, old_contact_id, new_contact_id, action, old_value) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$tenUndo, $edgeBase, 'th-pending-tio', 'contacts', '', '', 'create', $tenJson]);
    $edgePut('kt_pending_tio', $tenEmail, $edgeTen);
    $tenRes = jeab_contacts_sole_dispatch([
        'action' => 'inbox.contacts_from_threads_undo',
        'undo_id' => $tenUndo,
        'dry' => 0,
    ]);
    icft(($tenRes['ok'] ?? false) === true && icft_has($tenRes['deleted'] ?? [], 'kt_pending_tio', 'contact_id'), 'kontakt tio sekunder efter raden raderas');
    icft_clean($tenRes);
    icft((int) $pdo->query('SELECT COUNT(*) FROM contacts WHERE id = ' . $pdo->quote('kt_pending_tio'))->fetchColumn() === 0, 'tio-sekunders-kontakten är borta');

    $elevenEmail = 'pending.elva@example.test';
    $elevenJson = json_encode(['email' => $elevenEmail, 'company' => 'jarfalla'], JSON_UNESCAPED_UNICODE);
    icft(is_string($elevenJson), 'elva-sekunders-hint kan kodas');
    $elevenUndo = bin2hex(random_bytes(8));
    $pdo->prepare('INSERT INTO inbox_link_contacts_undo (undo_id, created_at, thread_id, tbl, old_contact_id, new_contact_id, action, old_value) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$elevenUndo, $edgeBase, 'th-pending-elva', 'contacts', '', '', 'create', $elevenJson]);
    $edgePut('kt_pending_elva', $elevenEmail, $edgeEleven);
    $elevenRes = jeab_contacts_sole_dispatch([
        'action' => 'inbox.contacts_from_threads_undo',
        'undo_id' => $elevenUndo,
        'dry' => 0,
    ]);
    $elevenPending = false;
    foreach ($elevenRes['skipped'] ?? [] as $row) {
        if (is_array($row) && (string) ($row['reason'] ?? '') === 'pending' && (string) ($row['contact_id'] ?? 'x') === '') {
            $elevenPending = true;
        }
    }
    icft(($elevenRes['ok'] ?? false) === true && ($elevenRes['deleted'] ?? []) === [] && $elevenPending, 'kontakt senare än tio sekunder är skipped pending');
    icft_clean($elevenRes);
    icft((int) $pdo->query('SELECT COUNT(*) FROM contacts WHERE id = ' . $pdo->quote('kt_pending_elva'))->fetchColumn() === 1, 'elva-sekunders-kontakten ligger kvar');

    unset($GLOBALS['_JEAB_INBOX_CONTACTS_FROM_THREADS']);
    $savedAuth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $denied = jeab_contacts_sole_dispatch(['action' => 'inbox.contacts_from_threads']);
    icft(($denied['ok'] ?? true) === false && ($denied['status'] ?? 0) === 401, 'utan token blir det 401');
    $_SERVER['HTTP_AUTHORIZATION'] = $savedAuth;
}

function icft_mariadb(): ?array
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
    $tmp = sys_get_temp_dir() . '/jeab-icft-mysql-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0775, true);
    $port = 35200 + random_int(0, 1500);
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
            icft_rm_tree($holder->tmp);
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

function icft_child(string $backend, int $port): void
{
    putenv('JEAB_ICFT_CHILD=' . $backend);
    putenv('JEAB_ICFT_PORT=' . (string) $port);
    $outFile = tempnam(sys_get_temp_dir(), 'icft-out-');
    $errFile = tempnam(sys_get_temp_dir(), 'icft-err-');
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
    putenv('JEAB_ICFT_CHILD');
    putenv('JEAB_ICFT_PORT');
    echo $out;
    if ($err !== '') {
        fwrite(STDERR, $err);
    }
    if ($code !== 0) {
        exit($code > 0 ? $code : 1);
    }
}

$icftChild = getenv('JEAB_ICFT_CHILD');
$icftChild = is_string($icftChild) ? $icftChild : '';
if ($icftChild === 'sqlite' || $icftChild === 'mysql') {
    $icftPort = (int) getenv('JEAB_ICFT_PORT');
    icft_scenarios($icftChild, $icftPort);
    exit(0);
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

icft_child('sqlite', 0);
$icftDb = icft_mariadb();
if ($icftDb !== null) {
    icft_child('mysql', $icftDb['port']);
}

echo "ALL OK\n";
