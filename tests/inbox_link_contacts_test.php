<?php

declare(strict_types=1);

/**
 * inbox.link_contacts. En träff länkas, två är tvetydigt, noll och annat
 * bolag är no_match. Redan länkad rad rörs inte. Påhittade adresser på
 * example.test. Inget personnummer och inga adresser i svaret.
 *
 * Samma scenarier körs mot sqlite och, om MariaDB finns, mot MySQL.
 * Ångerloggen ligger i inbox_link_contacts_undo. Undo utan dry=0 visar
 * planen och skriver inget. dry=0 återställer. Andra undo mot samma
 * undo_id ger 409 och skriver inget. Raderna ligger kvar med undone_at.
 * dry=0 utan confirm:link skriver varken tråd eller ångerrad.
 */

function ilc(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function ilc_rm_tree(string $dir): void
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
            ilc_rm_tree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function ilc_cols(PDO $pdo, string $table): array
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

function ilc_add(PDO $pdo, string $table, string $column): void
{
    $cols = ilc_cols($pdo, $table);
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

function ilc_cid(PDO $pdo, string $table, string $id): string
{
    $stmt = $pdo->prepare('SELECT contact_id FROM ' . $table . ' WHERE id = ?');
    $stmt->execute([$id]);

    return trim((string) $stmt->fetchColumn());
}

function ilc_json_cid(PDO $pdo, string $table, string $id): string
{
    $stmt = $pdo->prepare('SELECT row_json FROM ' . $table . ' WHERE id = ?');
    $stmt->execute([$id]);
    $decoded = json_decode((string) $stmt->fetchColumn(), true);

    return is_array($decoded) ? trim((string) ($decoded['contact_id'] ?? '')) : '';
}

/** @return list<array<string, mixed>> */
function ilc_contacts(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, company, row_json FROM contacts ORDER BY id');
    $rows = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    return is_array($rows) ? $rows : [];
}

/** @param list<array<string, mixed>> $rows */
function ilc_has(array $rows, string $id): bool
{
    foreach ($rows as $row) {
        if (is_array($row) && (string) ($row['thread_id'] ?? '') === $id) {
            return true;
        }
    }

    return false;
}

/** @param array<string, mixed> $report */
function ilc_clean(array $report): void
{
    $json = (string) json_encode($report, JSON_UNESCAPED_UNICODE);
    ilc(!str_contains($json, '@'), 'svaret har ingen e-post');
    foreach (['Uno', 'Linksson', 'Twin', 'Malmo', 'Keep', 'example.test', 'no-reply'] as $secret) {
        ilc(!str_contains($json, $secret), 'svaret har inget namn eller adressfragment');
    }
}

function ilc_no_undo_dir(): void
{
    $dir = rtrim((string) jeab_data_dir(), '/') . '/inbox-link-contacts';
    ilc(!is_dir($dir) && !is_file($dir), 'ingen katalog för ångerloggen');
}

/** @return list<array<string, mixed>> */
function ilc_undo_rows(PDO $pdo, string $undoId): array
{
    $stmt = $pdo->prepare(
        'SELECT thread_id, tbl, old_contact_id, new_contact_id, undone_at'
        . ' FROM inbox_link_contacts_undo WHERE undo_id = ? ORDER BY tbl'
    );
    $stmt->execute([$undoId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return is_array($rows) ? $rows : [];
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<string>
 */
function ilc_undo_tables(array $rows): array
{
    $tables = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $tables[] = (string) ($row['tbl'] ?? '');
        }
    }
    sort($tables);

    return $tables;
}

function ilc_boot(string $backend, int $port): string
{
    $tmp = sys_get_temp_dir() . '/jeab-inbox-link-' . $backend . '-' . bin2hex(random_bytes(4));
    mkdir($tmp . '/secrets', 0775, true);
    mkdir($tmp . '/inkorg', 0775, true);
    $public = $tmp . '/public-data';
    mkdir($public, 0775, true);
    register_shutdown_function(static function () use ($tmp): void {
        ilc_rm_tree($tmp);
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
            ilc(false, 'MySQL saknar port');
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
    file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-inbox-link']));
    require dirname(__DIR__) . '/src/bootstrap.php';

    return $tmp;
}

/** Bootstrap körde jeab_migrate_mysql mot den tomma databasen. Andra anropet ska vara ofarligt. */
function ilc_migrated_empty(PDO $pdo): void
{
    $names = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $names = array_map('strval', is_array($names) ? $names : []);
    ilc(in_array('reminder_lists', $names, true), 'jeab_migrate_mysql på tom databas skapar reminder_lists');
    ilc(in_array('inbox_link_contacts_undo', $names, true), 'jeab_migrate_mysql på tom databas skapar inbox_link_contacts_undo');
    jeab_migrate_mysql($pdo);
    $again = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $again = array_map('strval', is_array($again) ? $again : []);
    ilc(in_array('reminder_lists', $again, true) && in_array('inbox_link_contacts_undo', $again, true), 'andra jeab_migrate_mysql lämnar tabellerna');
}

/** Samma scenarier mot sqlite eller mysql. */
function ilc_scenarios(string $backend, int $port): void
{
    if ($backend === 'mysql') {
        $probe = new PDO(
            'mysql:host=127.0.0.1;port=' . $port . ';dbname=jeab;charset=utf8mb4',
            'jeab',
            'jeabpass',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        ilc($probe->query('SHOW TABLES')->fetchAll() === [], 'databasen är tom före jeab_migrate_mysql');
        $probe = null;
    }
    ilc_boot($backend, $port);
    $root = dirname(__DIR__);
    $src = (string) file_get_contents($root . '/src/inbox_link_contacts.php');
    $hookSrc = (string) file_get_contents($root . '/hook.php');
    $dispatchSrc = (string) file_get_contents($root . '/src/contacts_sole.php');
    ilc(str_contains($hookSrc, "'inbox.link_contacts'") && str_contains($hookSrc, "'inbox.link_contacts_undo'"), 'hook.php tar båda åtgärderna');
    ilc(str_contains($dispatchSrc, "=== 'inbox.link_contacts'") && str_contains($dispatchSrc, 'jeab_inbox_link_contacts('), 'dispatch skickar länk');
    ilc(str_contains($dispatchSrc, 'jeab_inbox_link_contacts_undo('), 'dispatch skickar undo');
    foreach (['INSERT INTO contacts', 'UPDATE contacts', 'DELETE FROM contacts', 'jeab_contacts_sole_audit(', 'jeab_kontakt_find_or_create', 'jeab_kontakt_save'] as $banned) {
        ilc(!str_contains($src, $banned), 'källan innehåller inte ' . $banned);
    }
    ilc(!str_contains($src, 'inbox-link-contacts'), 'källan skriver ingen ångerkatalog');
    ilc(!str_contains($src, 'file_put_contents'), 'källan skriver ingen ångerfil');
    ilc(str_contains($src, 'inbox_link_contacts_undo') && str_contains($src, 'undone_at'), 'ångerloggen är tabellen och andra undo ser undone_at');

    $store = jeab_store();
    ilc($store instanceof JeabPdoStore, $backend . '-lagret är öppet');
    $pdo = $store->pdo();
    ilc((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === $backend, 'lagret är ' . $backend);
    if ($backend === 'mysql') {
        ilc_migrated_empty($pdo);
    }
    $mysql = $backend === 'mysql';

    if (ilc_cols($pdo, 'contacts') === []) {
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
    ilc_add($pdo, 'contacts', 'row_json');
    ilc_add($pdo, 'contacts', 'company');
    if (ilc_cols($pdo, 'inkorg_threads') === []) {
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
    ilc_add($pdo, 'inkorg_threads', 'contact_id');
    ilc_add($pdo, 'inkorg_threads', 'row_json');
    if (ilc_cols($pdo, 'inkorg_list') === []) {
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
    ilc_add($pdo, 'inkorg_list', 'company');
    ilc_add($pdo, 'inkorg_list', 'customer_email');
    ilc_add($pdo, 'inkorg_list', 'contact_id');
    ilc_add($pdo, 'inkorg_list', 'row_json');
    ilc(ilc_cols($pdo, 'inbox_link_contacts_undo') !== [], 'inbox_link_contacts_undo finns efter migrate');

    $stamp = '2026-01-01 00:00:00';
    $contactSql = 'INSERT INTO contacts (id, row_json, company) VALUES (?, ?, ?)';
    if (isset(ilc_cols($pdo, 'contacts')['created_at'])) {
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
        ilc(is_string($body), 'kontakten kan kodas');
        if (isset(ilc_cols($pdo, 'contacts')['created_at'])) {
            $insertContact->execute([$id, $body, $company, $stamp, $stamp]);
        } else {
            $insertContact->execute([$id, $body, $company]);
        }
    };
    $addContact('kt_one', 'jarfalla', 'uno.link@example.test', 'Uno Linksson');
    $addContact('kt_amba', 'jarfalla', 'twin.link@example.test', 'Twin Ett');
    $addContact('kt_ambb', 'jarfalla', 'twin.link@example.test', 'Twin Tva');
    $addContact('kt_malmo', 'malmo', 'bara.malmo@example.test', 'Malmo Bara');
    $addContact('kt_keep', 'jarfalla', 'keep.link@example.test', 'Keep Namn');
    $addContact('kt_noreply', 'jarfalla', 'no-reply@example.test', 'Skick Ej');

    $list = $pdo->prepare('INSERT INTO inkorg_list (id, company, customer_email, contact_id, row_json) VALUES (?, ?, ?, ?, ?)');
    $rowJson = static function (array $extra): string {
        $json = json_encode(array_merge(['note' => 'trad-behall'], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '{}';
    };
    $list->execute(['th-one', 'jarfalla', 'Uno.Link@example.test', '', $rowJson(['company' => 'jarfalla'])]);
    $list->execute(['th-amb', 'jarfalla', '', '', $rowJson(['company' => 'jarfalla', 'customer_email' => 'twin.link@example.test'])]);
    $list->execute(['th-none', 'jarfalla', 'saknas.link@example.test', '', $rowJson(['company' => 'jarfalla'])]);
    $list->execute(['th-other', 'jarfalla', 'bara.malmo@example.test', '', $rowJson(['company' => 'jarfalla'])]);
    $list->execute(['th-keep', 'jarfalla', 'keep.link@example.test', 'kt_keep', $rowJson(['company' => 'jarfalla', 'contact_id' => 'kt_keep'])]);
    $list->execute(['th-noreply', 'jarfalla', 'no-reply@example.test', '', $rowJson(['company' => 'jarfalla'])]);

    $thread = $pdo->prepare('INSERT INTO inkorg_threads (id, status, updated_at, row_json, contact_id) VALUES (?, ?, ?, ?, ?)');
    $thread->execute(['th-one', 'open', $stamp, $rowJson(['company' => 'jarfalla', 'note' => 'trad-one-behall']), '']);

    $quoteOk = false;
    try {
        if (ilc_cols($pdo, 'quotes') === []) {
            if ($mysql) {
                $pdo->exec('CREATE TABLE quotes (id VARCHAR(64) NOT NULL, body TEXT, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            } else {
                $pdo->exec('CREATE TABLE quotes (id TEXT PRIMARY KEY, body TEXT)');
            }
        }
        $pdo->exec("INSERT INTO quotes (id, body) VALUES ('q-ilc', 'offert-behall')");
        $quoteOk = true;
    } catch (Throwable) {
        $quoteOk = false;
    }

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-inbox-link';

    $beforeContacts = ilc_contacts($pdo);
    ilc(count($beforeContacts) === 6, 'sex kontakter finns');

    $dry = jeab_contacts_sole_dispatch(['action' => 'inbox.link_contacts']);
    ilc(($dry['ok'] ?? false) === true && ($dry['dry'] ?? false) === true && ($dry['written'] ?? true) === false && ($dry['undo_id'] ?? 'x') === '', 'dry=1 är standard och skriver inte');
    ilc(ilc_has($dry['would_link'] ?? [], 'th-one') && count($dry['would_link'] ?? []) === 1 && ($dry['would_link'][0]['contact_id'] ?? '') === 'kt_one', 'en träff skulle länkas');
    $amb = $dry['ambiguous'][0] ?? [];
    ilc(count($dry['ambiguous'] ?? []) === 1 && ($amb['thread_id'] ?? '') === 'th-amb' && ($amb['matches'] ?? 0) === 2, 'två träffar är tvetydigt');
    $ambIds = is_array($amb['contact_ids'] ?? null) ? $amb['contact_ids'] : [];
    sort($ambIds);
    ilc($ambIds === ['kt_amba', 'kt_ambb'], 'tvetydiga kontakter är de två i samma bolag');
    $none = [];
    foreach ($dry['no_match'] ?? [] as $row) {
        if (is_array($row)) {
            $none[] = (string) ($row['thread_id'] ?? '');
        }
    }
    sort($none);
    ilc($none === ['th-none', 'th-noreply', 'th-other'], 'ingen träff, blockerad adress och annat bolag är no_match');
    ilc(!ilc_has($dry['would_link'] ?? [], 'th-keep') && !ilc_has($dry['ambiguous'] ?? [], 'th-keep') && !ilc_has($dry['no_match'] ?? [], 'th-keep'), 'redan länkad tråd rörs inte');
    ilc(($dry['companies']['jarfalla']['would_link'] ?? -1) === 1 && ($dry['companies']['jarfalla']['ambiguous'] ?? -1) === 1 && ($dry['companies']['jarfalla']['no_match'] ?? -1) === 3, 'Järfälla räknas');
    ilc(($dry['companies']['malmo']['would_link'] ?? -1) === 0 && ($dry['companies']['malmo']['no_match'] ?? -1) === 0, 'Malmö räknas för sig');
    ilc(($dry['total']['would_link'] ?? -1) === 1 && ($dry['total']['ambiguous'] ?? -1) === 1 && ($dry['total']['no_match'] ?? -1) === 3, 'totalen stämmer');
    ilc_clean($dry);
    ilc(ilc_cid($pdo, 'inkorg_list', 'th-one') === '' && ilc_json_cid($pdo, 'inkorg_threads', 'th-one') === '', 'torr körning skriver inget');
    ilc((int) $pdo->query('SELECT COUNT(*) FROM inbox_link_contacts_undo')->fetchColumn() === 0, 'torr körning skriver inga undo-rader');
    ilc_no_undo_dir();

    $badCompany = jeab_contacts_sole_dispatch(['action' => 'inbox.link_contacts', 'company' => 'JEAB']);
    ilc(($badCompany['ok'] ?? true) === false && ($badCompany['status'] ?? 0) === 400, 'okänt bolag är 400');
    $badLimit = jeab_contacts_sole_dispatch(['action' => 'inbox.link_contacts', 'limit' => -1]);
    ilc(($badLimit['ok'] ?? true) === false && ($badLimit['status'] ?? 0) === 400, 'ogiltig limit är 400');
    ilc_clean($badCompany);
    ilc_clean($badLimit);

    $undoBefore = (int) $pdo->query('SELECT COUNT(*) FROM inbox_link_contacts_undo')->fetchColumn();
    $noConfirm = jeab_contacts_sole_dispatch(['action' => 'inbox.link_contacts', 'dry' => 0]);
    ilc(($noConfirm['ok'] ?? true) === false && ($noConfirm['status'] ?? 0) === 400 && ($noConfirm['error'] ?? '') === 'Kräver confirm:link.', 'dry=0 utan confirm är 400');
    ilc(($noConfirm['written'] ?? true) === false, 'dry=0 utan confirm skriver inte');
    ilc(ilc_cid($pdo, 'inkorg_list', 'th-one') === '' && ilc_cid($pdo, 'inkorg_threads', 'th-one') === '', 'dry=0 utan confirm lämnar trådarna');
    ilc(ilc_json_cid($pdo, 'inkorg_list', 'th-one') === '' && ilc_json_cid($pdo, 'inkorg_threads', 'th-one') === '', 'dry=0 utan confirm lämnar json');
    ilc((int) $pdo->query('SELECT COUNT(*) FROM inbox_link_contacts_undo')->fetchColumn() === $undoBefore, 'dry=0 utan confirm skriver ingen undo-rad');
    $badConfirm = jeab_contacts_sole_dispatch(['action' => 'inbox.link_contacts', 'dry' => 0, 'confirm' => 'fel']);
    ilc(($badConfirm['ok'] ?? true) === false && ($badConfirm['status'] ?? 0) === 400 && ($badConfirm['error'] ?? '') === 'Kräver confirm:link.', 'confirm:fel är 400');
    ilc(ilc_cid($pdo, 'inkorg_list', 'th-one') === '' && ilc_cid($pdo, 'inkorg_threads', 'th-one') === '', 'fel confirm lämnar trådarna');
    ilc((int) $pdo->query('SELECT COUNT(*) FROM inbox_link_contacts_undo')->fetchColumn() === $undoBefore, 'fel confirm skriver ingen undo-rad');

    $limited = jeab_contacts_sole_dispatch(['action' => 'inbox.link_contacts', 'dry' => 0, 'limit' => 0, 'confirm' => 'link']);
    ilc(($limited['ok'] ?? false) === true && ($limited['would_link'] ?? null) === [] && ($limited['written'] ?? true) === false, 'limit 0 länkar inget');
    ilc(ilc_cid($pdo, 'inkorg_list', 'th-one') === '', 'limit 0 skriver inte');

    $wet = jeab_contacts_sole_dispatch(['action' => 'inbox.link_contacts', 'dry' => 0, 'confirm' => 'link']);
    ilc(($wet['ok'] ?? false) === true && ($wet['dry'] ?? true) === false && ($wet['written'] ?? false) === true, 'dry=0 skriver');
    ilc(preg_match('/^[a-f0-9]{16}$/', (string) ($wet['undo_id'] ?? '')) === 1, 'undo_id följer med');
    ilc_clean($wet);
    ilc(ilc_cid($pdo, 'inkorg_list', 'th-one') === 'kt_one' && ilc_json_cid($pdo, 'inkorg_list', 'th-one') === 'kt_one', 'listan fick contact_id');
    ilc(ilc_cid($pdo, 'inkorg_threads', 'th-one') === 'kt_one' && ilc_json_cid($pdo, 'inkorg_threads', 'th-one') === 'kt_one', 'tråden fick contact_id');
    foreach (['th-amb', 'th-none', 'th-other', 'th-noreply'] as $id) {
        ilc(ilc_cid($pdo, 'inkorg_list', $id) === '' && ilc_json_cid($pdo, 'inkorg_list', $id) === '', $id . ' länkas inte');
    }
    ilc(ilc_cid($pdo, 'inkorg_list', 'th-keep') === 'kt_keep', 'redan länkad rad står kvar');
    ilc(ilc_contacts($pdo) === $beforeContacts, 'inga kontakter skapas eller ändras');
    ilc_no_undo_dir();
    $undoRows = ilc_undo_rows($pdo, (string) $wet['undo_id']);
    ilc(count($undoRows) === 2 && ilc_undo_tables($undoRows) === ['inkorg_list', 'inkorg_threads'], 'två undo-rader, en per tabell');
    $undoText = (string) json_encode($undoRows, JSON_UNESCAPED_UNICODE);
    ilc(!str_contains($undoText, '@') && !str_contains($undoText, 'Linksson'), 'undo-raderna har ingen e-post');
    foreach ($undoRows as $undoRow) {
        ilc((string) ($undoRow['thread_id'] ?? '') === 'th-one', 'undo-raden pekar på tråden');
        ilc((string) ($undoRow['old_contact_id'] ?? 'x') === '' && (string) ($undoRow['new_contact_id'] ?? '') === 'kt_one', 'undo-raden har gammalt och nytt id');
        ilc(($undoRow['undone_at'] ?? 'satt') === null || (string) ($undoRow['undone_at'] ?? '') === '', 'undone_at är tom före undo');
    }
    if ($quoteOk) {
        ilc((string) $pdo->query("SELECT body FROM quotes WHERE id = 'q-ilc'")->fetchColumn() === 'offert-behall', 'offerten rörs inte');
    }

    $preview = jeab_contacts_sole_dispatch([
        'action' => 'inbox.link_contacts_undo',
        'undo_id' => $wet['undo_id'],
        'dry' => 1,
    ]);
    ilc(($preview['ok'] ?? false) === true && ($preview['dry'] ?? false) === true && ($preview['written'] ?? true) === false, 'undo med dry=1 skriver inte');
    ilc(ilc_has($preview['restored'] ?? [], 'th-one'), 'torr undo visar återställningen');
    ilc_clean($preview);
    ilc(ilc_cid($pdo, 'inkorg_list', 'th-one') === 'kt_one', 'torr undo lämnar listan');
    foreach (ilc_undo_rows($pdo, (string) $wet['undo_id']) as $undoRow) {
        ilc(($undoRow['undone_at'] ?? 'satt') === null || (string) ($undoRow['undone_at'] ?? '') === '', 'torr undo sätter inte undone_at');
    }

    $bare = jeab_contacts_sole_dispatch([
        'action' => 'inbox.link_contacts_undo',
        'undo_id' => $wet['undo_id'],
    ]);
    ilc(($bare['ok'] ?? false) === true && ($bare['dry'] ?? false) === true && ($bare['written'] ?? true) === false, 'undo utan dry=0 skriver inte');
    ilc(ilc_has($bare['restored'] ?? [], 'th-one') && ($bare['skipped'] ?? null) === [], 'undo utan dry visar planen');
    ilc_clean($bare);
    ilc(ilc_cid($pdo, 'inkorg_list', 'th-one') === 'kt_one' && ilc_cid($pdo, 'inkorg_threads', 'th-one') === 'kt_one', 'undo utan dry lämnar contact_id');
    ilc(ilc_json_cid($pdo, 'inkorg_list', 'th-one') === 'kt_one' && ilc_json_cid($pdo, 'inkorg_threads', 'th-one') === 'kt_one', 'undo utan dry lämnar json');
    foreach (ilc_undo_rows($pdo, (string) $wet['undo_id']) as $undoRow) {
        ilc(($undoRow['undone_at'] ?? 'satt') === null || (string) ($undoRow['undone_at'] ?? '') === '', 'undo utan dry sätter inte undone_at');
    }
    $bareViaLink = jeab_contacts_sole_dispatch([
        'action' => 'inbox.link_contacts',
        'undo' => 1,
        'undo_id' => $wet['undo_id'],
    ]);
    ilc(($bareViaLink['ok'] ?? false) === true && ($bareViaLink['dry'] ?? false) === true && ($bareViaLink['written'] ?? true) === false, 'undo=1 utan dry=0 skriver inte');
    ilc(ilc_has($bareViaLink['restored'] ?? [], 'th-one'), 'undo=1 utan dry visar planen');
    ilc(ilc_cid($pdo, 'inkorg_list', 'th-one') === 'kt_one' && ilc_cid($pdo, 'inkorg_threads', 'th-one') === 'kt_one', 'undo=1 utan dry lämnar contact_id');

    $undo = jeab_contacts_sole_dispatch([
        'action' => 'inbox.link_contacts',
        'undo' => 1,
        'undo_id' => $wet['undo_id'],
        'dry' => 0,
    ]);
    ilc(($undo['ok'] ?? false) === true && ($undo['written'] ?? false) === true && ($undo['dry'] ?? true) === false, 'undo=1 med dry=0 återställer');
    ilc(ilc_has($undo['restored'] ?? [], 'th-one') && ($undo['skipped'] ?? null) === [], 'hela tråden återställs');
    ilc_clean($undo);
    ilc(ilc_cid($pdo, 'inkorg_list', 'th-one') === '' && ilc_json_cid($pdo, 'inkorg_list', 'th-one') === '', 'listans contact_id är tom igen');
    ilc(ilc_cid($pdo, 'inkorg_threads', 'th-one') === '' && ilc_json_cid($pdo, 'inkorg_threads', 'th-one') === '', 'trådens contact_id är tom igen');
    ilc(ilc_cid($pdo, 'inkorg_list', 'th-keep') === 'kt_keep', 'undo rör inte den redan länkade');
    ilc(ilc_contacts($pdo) === $beforeContacts, 'undo ändrar inga kontakter');
    $marked = ilc_undo_rows($pdo, (string) $wet['undo_id']);
    ilc(count($marked) === 2, 'undo raderar inte raderna');
    foreach ($marked as $undoRow) {
        ilc(trim((string) ($undoRow['undone_at'] ?? '')) !== '', 'undone_at sätts vid undo');
    }
    ilc_no_undo_dir();

    $second = jeab_contacts_sole_dispatch([
        'action' => 'inbox.link_contacts_undo',
        'undo_id' => $wet['undo_id'],
    ]);
    ilc(($second['ok'] ?? true) === false && ($second['status'] ?? 0) === 409 && ($second['written'] ?? true) === false, 'andra undo är 409 och skriver inget');
    ilc(($second['restored'] ?? null) === [] && ($second['skipped'] ?? null) === [], 'andra undo är en noop');
    ilc_clean($second);
    ilc(ilc_cid($pdo, 'inkorg_list', 'th-one') === '' && ilc_cid($pdo, 'inkorg_threads', 'th-one') === '', 'andra undo lämnar tråden återställd');
    ilc(count(ilc_undo_rows($pdo, (string) $wet['undo_id'])) === 2, 'andra undo lägger inte till rader');

    $missingUndo = jeab_contacts_sole_dispatch([
        'action' => 'inbox.link_contacts_undo',
        'undo_id' => '0123456789abcdef',
    ]);
    ilc(($missingUndo['ok'] ?? true) === false && ($missingUndo['status'] ?? 0) === 404, 'okänt undo_id är 404');

    $again = jeab_contacts_sole_dispatch(['action' => 'inbox.link_contacts', 'dry' => '0', 'confirm' => 'link']);
    ilc(($again['ok'] ?? false) === true && ilc_cid($pdo, 'inkorg_list', 'th-one') === 'kt_one' && ilc_cid($pdo, 'inkorg_threads', 'th-one') === 'kt_one', 'andra körningen länkar igen');
    $changed = json_decode((string) $pdo->query("SELECT row_json FROM inkorg_list WHERE id = 'th-one'")->fetchColumn(), true);
    ilc(is_array($changed), 'listans json kan läsas');
    $changed['contact_id'] = 'kt_keep';
    $pdo->prepare('UPDATE inkorg_list SET contact_id = ?, row_json = ? WHERE id = ?')->execute([
        'kt_keep',
        json_encode($changed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'th-one',
    ]);
    $partial = jeab_contacts_sole_dispatch([
        'action' => 'inbox.link_contacts_undo',
        'undo_id' => $again['undo_id'],
        'dry' => 0,
    ]);
    ilc(($partial['ok'] ?? false) === true && ilc_has($partial['skipped'] ?? [], 'th-one'), 'ändrad rad hoppas över');
    ilc_clean($partial);
    ilc(ilc_cid($pdo, 'inkorg_list', 'th-one') === 'kt_keep' && ilc_json_cid($pdo, 'inkorg_list', 'th-one') === 'kt_keep', 'ändrad lista står kvar');
    ilc(ilc_cid($pdo, 'inkorg_threads', 'th-one') === '' && ilc_json_cid($pdo, 'inkorg_threads', 'th-one') === '', 'oförändrad tråd återställs');
    ilc(ilc_contacts($pdo) === $beforeContacts, 'kontakterna är oförändrade till slut');
    $partialAgain = jeab_contacts_sole_dispatch([
        'action' => 'inbox.link_contacts_undo',
        'undo_id' => $again['undo_id'],
    ]);
    ilc(($partialAgain['ok'] ?? true) === false && ($partialAgain['status'] ?? 0) === 409, 'andra undo av den delvisa körningen är 409');
    ilc(ilc_cid($pdo, 'inkorg_list', 'th-one') === 'kt_keep' && ilc_cid($pdo, 'inkorg_threads', 'th-one') === '', 'andra undo lämnar delresultatet');
    ilc_no_undo_dir();

    $savedAuth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $denied = jeab_contacts_sole_dispatch(['action' => 'inbox.link_contacts']);
    ilc(($denied['ok'] ?? true) === false && ($denied['status'] ?? 0) === 401, 'utan token blir det 401');
    $_SERVER['HTTP_AUTHORIZATION'] = $savedAuth;
}

/**
 * Egen mariadbd, samma mönster som contacts_sole_mysql_test.php.
 * @return array{port:int}|null
 */
function ilc_mariadb(): ?array
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
    $tmp = sys_get_temp_dir() . '/jeab-ilc-mysql-' . bin2hex(random_bytes(4));
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
            ilc_rm_tree($holder->tmp);
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

function ilc_child(string $backend, int $port): void
{
    putenv('JEAB_ILC_CHILD=' . $backend);
    putenv('JEAB_ILC_PORT=' . (string) $port);
    $outFile = tempnam(sys_get_temp_dir(), 'ilc-out-');
    $errFile = tempnam(sys_get_temp_dir(), 'ilc-err-');
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
    putenv('JEAB_ILC_CHILD');
    putenv('JEAB_ILC_PORT');
    echo $out;
    if ($err !== '') {
        fwrite(STDERR, $err);
    }
    if ($code !== 0) {
        exit($code > 0 ? $code : 1);
    }
}

$ilcChild = getenv('JEAB_ILC_CHILD');
$ilcChild = is_string($ilcChild) ? $ilcChild : '';
if ($ilcChild === 'sqlite' || $ilcChild === 'mysql') {
    $ilcPort = (int) getenv('JEAB_ILC_PORT');
    ilc_scenarios($ilcChild, $ilcPort);
    exit(0);
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

ilc_child('sqlite', 0);
$ilcDb = ilc_mariadb();
if ($ilcDb !== null) {
    ilc_child('mysql', $ilcDb['port']);
}

echo "ALL OK\n";
