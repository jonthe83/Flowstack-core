<?php

declare(strict_types=1);

/**
 * contacts.name_source. Kontaktens riktiga namn vinner över länkad order
 * och påminnelse. Platshållare faller tillbaka till order eller påminnelse.
 * Påhittade namn och example.test. Inget personnummer.
 *
 * Samma scenarier körs mot sqlite och, om MariaDB finns, mot MySQL.
 * MySQL-delen hoppas över när mariadb-install-db eller mariadbd saknas.
 */

function ns(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function ns_rm_tree(string $dir): void
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
            ns_rm_tree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function ns_contact(string $name, string $email, string $phone): string
{
    $row = jeab_kontakt_find_or_create([
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'address' => 'Namngatan 1, 111 22 Teststad',
        'city' => 'Teststad',
        'postal_code' => '11122',
        'company' => 'jarfalla',
    ], false, false);
    ns(is_array($row) && trim((string) ($row['id'] ?? '')) !== '', 'kontakt ' . $email);

    return (string) $row['id'];
}

function ns_set_name(string $id, string $name): void
{
    jeab_kontakter_mutate(static function (array &$contacts) use ($id, $name): void {
        if (!isset($contacts[$id]) || !is_array($contacts[$id])) {
            return;
        }
        $contacts[$id]['name'] = $name;
        $owners = is_array($contacts[$id]['owners'] ?? null) ? $contacts[$id]['owners'] : [];
        if ($owners === [] || !is_array($owners[0] ?? null)) {
            $owners[0] = [
                'contact' => true,
                'email' => (string) ($contacts[$id]['email'] ?? ''),
                'phone' => (string) ($contacts[$id]['phone'] ?? ''),
            ];
        }
        $owners[0]['name'] = $name;
        $owners[0]['contact'] = true;
        $contacts[$id]['owners'] = $owners;
    });
    jeab_contacts_sole_cache_forget($id);
}

function ns_book(JeabStore $store, int $customerId, string $contactId, string $copy, string $now): int
{
    $row = $store->insertBooking([
        'customer_id' => $customerId,
        'customer_name' => $copy,
        'email' => 'kopia.' . $contactId . '@example.test',
        'phone' => '0701000700',
        'address' => 'Kopiagatan 1',
        'assignees' => ['Elin'],
        'company' => 'jarfalla',
        'job_type' => 'laddbox',
        'starts_at' => '2026-11-04 09:00:00',
        'ends_at' => '2026-11-04 10:00:00',
        'status' => 'booked',
        'contact_id' => $contactId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $id = (int) ($row['id'] ?? 0);
    ns($id > 0, 'bokning ' . $copy);

    return $id;
}

function ns_reminder(JeabStore $store, int $bookingId, string $name, string $now): int
{
    $row = $store->insertReminder([
        'title' => 'Reserv',
        'customer_name' => $name,
        'email' => 'paminne.reserv@example.test',
        'phone' => '0701000701',
        'address' => 'Paminnelsegatan 2',
        'company' => 'jarfalla',
        'booking_id' => $bookingId > 0 ? $bookingId : null,
        'due_at' => '2026-11-05 09:00:00',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $id = (int) ($row['id'] ?? 0);
    ns($id > 0, 'påminnelse ' . $name);

    return $id;
}

function ns_order(PDO $pdo, int $reminderId, int $bookingId, string $contactId, string $name, string $now): void
{
    $payload = [
        'reminder_id' => $reminderId,
        'booking_id' => $bookingId,
        'contact_id' => $contactId,
        'kind' => 'laddbox',
        'huvudsokande' => $name,
        'customer_name' => $name,
        'name' => $name,
        'applicant' => ['name' => $name],
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    ns(is_string($json), 'orderpayload kan kodas');
    $pdo->prepare('INSERT INTO purchases (reminder_id, payload, updated_at, contact_id, contact_indexed) VALUES (?, ?, ?, ?, 1)')->execute([
        $reminderId,
        $json,
        $now,
        $contactId,
    ]);
}

/**
 * @param array<string, mixed> $hook
 * @param list<string> $hidden
 */
function ns_private(array $hook, array $hidden, string $label): void
{
    $json = (string) json_encode($hook, JSON_UNESCAPED_UNICODE);
    $allowed = ['ok', 'status', 'booking_id', 'contact_id', 'path', 'source_id', 'contact_name_real'];
    if (($hook['ok'] ?? false) !== true) {
        $allowed[] = 'error';
    }
    foreach (array_keys($hook) as $key) {
        ns(in_array((string) $key, $allowed, true), $label . ' har bara källfält');
    }
    ns(!str_contains($json, '@') && !str_contains($json, 'example.test'), $label . ' har ingen e-post');
    foreach ($hidden as $secret) {
        ns($secret === '' || !str_contains($json, $secret), $label . ' har inget namn');
    }
}

/** @param array<string, mixed> $input */
function ns_hook(JeabStore $store, array $input): array
{
    $hook = jeab_contacts_name_source($input, $store);
    $via = jeab_contacts_sole_dispatch(array_merge(['action' => 'contacts.name_source'], $input));
    ns(($hook['ok'] ?? false) === true && ($via['ok'] ?? false) === true, 'båda vägarna svarar');
    ns(($via['status'] ?? 0) === 200, 'dispatch ger 200');
    ns(($hook['path'] ?? '') === ($via['path'] ?? '') && ($hook['source_id'] ?? null) === ($via['source_id'] ?? null), 'dispatch följer funktionen');
    ns(($hook['booking_id'] ?? 0) === ($via['booking_id'] ?? 0) && ($hook['contact_id'] ?? '') === ($via['contact_id'] ?? ''), 'dispatch har samma id');

    return $hook;
}

function ns_boot(string $backend, int $port): void
{
    $tmp = sys_get_temp_dir() . '/jeab-name-source-' . $backend . '-' . bin2hex(random_bytes(4));
    mkdir($tmp . '/secrets', 0775, true);
    register_shutdown_function(static function () use ($tmp): void {
        ns_rm_tree($tmp);
    });
    $GLOBALS['_JEAB_DATA_DIR'] = $tmp;
    $GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
    $GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
    $GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
    $GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
    unset($GLOBALS['_JEAB_CONTACTS_STRICT'], $GLOBALS['_JEAB_CONTACTS_NAME_CANDIDATE_DISPLAY']);
    putenv('JEAB_DISABLE_SQLITE=0');
    putenv('DATA_BACKEND=mysql');
    putenv('CONTACTS_SOLE=1');
    putenv('CONTACTS_STRICT');
    putenv('CONTACTS_NAME_CANDIDATE_DISPLAY');
    unset($_ENV['CONTACTS_STRICT'], $_ENV['CONTACTS_NAME_CANDIDATE_DISPLAY']);
    $db = [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ];
    if ($backend === 'mysql') {
        if ($port < 1) {
            ns(false, 'MySQL saknar port');
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
    ], true) . ";\n");
    require dirname(__DIR__) . '/src/bootstrap.php';
}

/** Bootstrap körde jeab_migrate_mysql mot den tomma databasen. Andra anropet ska vara ofarligt. */
function ns_migrated_empty(PDO $pdo): void
{
    $names = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $names = array_map('strval', is_array($names) ? $names : []);
    ns(in_array('reminder_lists', $names, true), 'jeab_migrate_mysql på tom databas skapar reminder_lists');
    ns(in_array('inbox_link_contacts_undo', $names, true), 'jeab_migrate_mysql på tom databas skapar inbox_link_contacts_undo');
    jeab_migrate_mysql($pdo);
    $again = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $again = array_map('strval', is_array($again) ? $again : []);
    ns(in_array('reminder_lists', $again, true) && in_array('inbox_link_contacts_undo', $again, true), 'andra jeab_migrate_mysql lämnar tabellerna');
}

/** Samma namnscenarier mot sqlite eller mysql. */
function ns_scenarios(string $backend, int $port): void
{
    if ($backend === 'mysql') {
        $probe = new PDO(
            'mysql:host=127.0.0.1;port=' . $port . ';dbname=jeab;charset=utf8mb4',
            'jeab',
            'jeabpass',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        ns($probe->query('SHOW TABLES')->fetchAll() === [], 'databasen är tom före jeab_migrate_mysql');
        $probe = null;
    }
    ns_boot($backend, $port);

    $root = dirname(__DIR__);
    $hookSrc = (string) file_get_contents($root . '/hook.php');
    $dispatchSrc = (string) file_get_contents($root . '/src/contacts_sole.php');
    ns(str_contains($hookSrc, "'contacts.name_source'"), 'hook.php tar contacts.name_source');
    ns(str_contains($dispatchSrc, "=== 'contacts.name_source'") && str_contains($dispatchSrc, 'jeab_contacts_name_source('), 'dispatch skickar namnkällan');
    require_once $root . '/src/contacts_name_source.php';

    $store = jeab_store();
    ns($store instanceof JeabPdoStore, $backend . '-lagret finns');
    $pdo = $store->pdo();
    ns((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === $backend, 'lagret är ' . $backend);
    if ($backend === 'mysql') {
        ns_migrated_empty($pdo);
    }
    jeab_ensure_contacts_tables($pdo, $backend);
    jeab_ensure_contact_id_columns($pdo, $backend);
    jeab_purchases_store_ensure($pdo);
    ns(jeab_contacts_sole(), 'kontakten är källan i visningen');
    ns(jeab_contacts_strict() === false, 'strict är av');

    $tokenDir = dirname(jeab_inkorg_token_path());
    if (!is_dir($tokenDir)) {
        mkdir($tokenDir, 0775, true);
    }
    file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-name-source']));
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-name-source';

    $now = jeab_now();
    $customer = $store->insertCustomer('Kopia Los', $now);

    $noraId = ns_contact('Nora Riktig', 'nora.riktig@example.test', '0701000711');
    ns(preg_match('/^kt_[A-Za-z0-9]+$/', $noraId) === 1, 'kontakten har en kt_-nyckel');
    $noraBook = ns_book($store, (int) $customer['id'], $noraId, 'Kopia Nora', $now);
    $noraHolder = ns_reminder($store, 0, 'Kund', $now);
    ns_order($pdo, $noraHolder, $noraBook, $noraId, 'Order Annat', $now);
    $noraReminder = ns_reminder($store, $noraBook, 'Paminne Annat', $now);

    $kundId = ns_contact('Tillfallig Kund', 'tillfallig.kund@example.test', '0701000712');
    ns_set_name($kundId, 'Kund');
    $kundFresh = jeab_kontakt_get($kundId);
    ns(is_array($kundFresh), 'platshållaren kan läsas');
    $kundEmails = jeab_contacts_sole_contact_emails($kundFresh);
    $kundBest = jeab_contacts_sole_best_owner_name($kundFresh, $kundEmails);
    ns($kundBest === 'Kund' && !jeab_contacts_sole_name_is_real($kundBest, $kundEmails), 'Kund är en platshållare');
    $kundBook = ns_book($store, (int) $customer['id'], $kundId, 'Kopia Reserv', $now);
    $kundHolder = ns_reminder($store, 0, 'Kund', $now);
    ns_order($pdo, $kundHolder, $kundBook, $kundId, 'Order Reserv', $now);
    $kundSide = ns_reminder($store, $kundBook, 'Paminne Sida', $now);

    $mailId = ns_contact('Tillfallig Post', 'tillfallig.post@example.test', '0701000713');
    ns_set_name($mailId, 'epost.namn@example.test');
    $mailBook = ns_book($store, (int) $customer['id'], $mailId, 'Kopia Post', $now);
    $mailReminder = ns_reminder($store, $mailBook, 'Paminne Reserv', $now);

    $shortId = ns_contact('Tillfallig Kort', 'tillfallig.kort@example.test', '0701000714');
    ns_set_name($shortId, 'Kund');
    $shortBook = ns_book($store, (int) $customer['id'], $shortId, 'Kopia Kort', $now);
    $shortHolder = ns_reminder($store, 0, 'Kund', $now);
    ns_order($pdo, $shortHolder, $shortBook, $shortId, 'Kort', $now);
    $shortReminder = ns_reminder($store, $shortBook, 'Alfa Reserv', $now);

    $emptyId = ns_contact('Tillfallig Tom', 'tillfallig.tom@example.test', '0701000715');
    ns_set_name($emptyId, '');
    $emptyBook = ns_book($store, (int) $customer['id'], $emptyId, 'Kopia Tom', $now);

    $loose = ns_book($store, (int) $customer['id'], '', 'Kopia Los', $now);

    $twinId = ns_contact('Tvilling Namn', 'tvilling.namn@example.test', '0701000716');
    $twinA = ns_book($store, (int) $customer['id'], $twinId, 'Kopia Ett', $now);
    $twinB = ns_book($store, (int) $customer['id'], $twinId, 'Kopia Tva', $now);
    ns($twinA !== $twinB, 'tvillingarna är två bokningar');

    jeab_contacts_sole_booking_order_cache_forget();
    unset(
        $GLOBALS['_JEAB_BOOKING_SIBLING_MAP'],
        $GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES'],
        $GLOBALS['_JEAB_CONTACTS_SOLE_CACHE'],
        $GLOBALS['_JEAB_CONTACTS_SOLE_BOOKING_ORDER']
    );

    $hidden = [
        'Nora Riktig', 'Order Annat', 'Paminne Annat', 'Order Reserv', 'Paminne Sida',
        'Paminne Reserv', 'Alfa Reserv', 'Kopia Los', 'Kopia Tom', 'Kopia Nora',
        'Kopia Reserv', 'Kopia Post', 'Kopia Kort', 'epost.namn', 'Tvilling Namn', 'Kund',
    ];

    $GLOBALS['_JEAB_CONTACTS_SOLE_BOOKING_ORDER_CALLS'] = 0;
    ns(jeab_flag('CONTACTS_NAME_CANDIDATE_DISPLAY') === false, 'kandidatvisning är av så vägen inte blir candidate');
    $noraRow = $store->getBooking($noraBook);
    $noraPaint = jeab_contacts_sole_paint_booking($noraRow);
    $noraChoice = jeab_contacts_sole_booking_name_choice($noraRow);
    $noraHook = ns_hook($store, ['booking_id' => $noraBook]);
    ns($noraChoice['path'] === 'contact' && $noraHook['path'] === 'contact', 'riktigt namn har vägen contact');
    ns($noraHook['contact_name_real'] === true && $noraHook['source_id'] === null, 'kontakten är en riktig källa');
    ns($noraHook['contact_id'] === $noraId && $noraHook['booking_id'] === $noraBook, 'svaret pekar på bokningen och kontakten');
    ns($noraPaint['customer_name'] === $noraChoice['name'] && $noraChoice['name'] === 'Nora Riktig', 'visat namn är kontaktens');
    ns($noraPaint['customer_name'] !== 'Order Annat' && $noraPaint['customer_name'] !== 'Paminne Annat', 'order och påminnelse vinner inte');
    ns_private($noraHook, $hidden, 'kontaktens svar');

    $keyHook = ns_hook($store, ['key' => $noraId]);
    ns($keyHook['booking_id'] === $noraBook && $keyHook['path'] === 'contact' && $keyHook['contact_id'] === $noraId, 'key hittar bokningen');
    ns_private($keyHook, $hidden, 'key-svaret');
    $both = ns_hook($store, ['booking_id' => (string) $noraBook, 'key' => $noraId]);
    ns($both['booking_id'] === $noraBook && $both['path'] === 'contact', 'booking_id och key som hör ihop godkänns');
    ns((int) $GLOBALS['_JEAB_CONTACTS_SOLE_BOOKING_ORDER_CALLS'] === 0, 'riktigt namn slår inte upp ordern');

    $kundRow = $store->getBooking($kundBook);
    $kundPaint = jeab_contacts_sole_paint_booking($kundRow);
    $kundChoice = jeab_contacts_sole_booking_name_choice($kundRow);
    $kundHook = ns_hook($store, ['booking_id' => $kundBook]);
    ns($kundHook['path'] === 'linked_order' && $kundChoice['path'] === 'linked_order', 'platshållare faller till ordern');
    ns($kundHook['source_id'] === $kundHolder && $kundHook['source_id'] !== $kundSide, 'source_id är ordern');
    ns($kundHook['contact_name_real'] === false && $kundHook['contact_id'] === $kundId, 'kontaktnamnet är inte riktigt');
    ns($kundPaint['customer_name'] === 'Order Reserv' && $kundPaint['customer_name'] === $kundChoice['name'], 'visat namn är orderns');
    ns($kundPaint['customer_name'] !== 'Paminne Sida' && $kundPaint['customer_name'] !== 'Kopia Reserv', 'påminnelsen och kopian vinner inte över ordern');
    ns_private($kundHook, $hidden, 'ordersvaret');
    ns((int) $GLOBALS['_JEAB_CONTACTS_SOLE_BOOKING_ORDER_CALLS'] === 1, 'platshållare slår upp ordern en gång');
    $kundCached = jeab_contacts_sole_booking_name_choice($kundRow);
    ns($kundCached['path'] === 'linked_order' && $kundCached['name'] === 'Order Reserv', 'cachen behåller orderns namn');
    ns((int) $GLOBALS['_JEAB_CONTACTS_SOLE_BOOKING_ORDER_CALLS'] === 1, 'samma kontakt slår inte upp ordern igen');
    jeab_contacts_sole_booking_order_cache_forget($kundId);
    $kundFreshChoice = jeab_contacts_sole_booking_name_choice($kundRow);
    ns($kundFreshChoice['path'] === 'linked_order' && $kundFreshChoice['name'] === 'Order Reserv', 'forget ger samma ordernamn');
    ns((int) $GLOBALS['_JEAB_CONTACTS_SOLE_BOOKING_ORDER_CALLS'] === 2, 'forget läser ordern på nytt');

    $mailRow = $store->getBooking($mailBook);
    $mailPaint = jeab_contacts_sole_paint_booking($mailRow);
    $mailHook = ns_hook($store, ['booking_id' => $mailBook]);
    ns($mailHook['path'] === 'linked_reminder' && $mailHook['source_id'] === $mailReminder, 'e-postnamn faller till påminnelsen');
    ns($mailHook['contact_name_real'] === false && $mailPaint['customer_name'] === 'Paminne Reserv', 'visat namn är påminnelsens');
    ns_private($mailHook, $hidden, 'påminnelsesvaret');

    $shortRow = $store->getBooking($shortBook);
    $shortPaint = jeab_contacts_sole_paint_booking($shortRow);
    $shortHook = ns_hook($store, ['booking_id' => $shortBook]);
    ns($shortHook['path'] === 'linked_reminder' && $shortHook['source_id'] === $shortReminder, 'fullständigt påminnelsenamn vinner över ett ensamt ord på ordern');
    ns($shortPaint['customer_name'] === 'Alfa Reserv', 'visat namn är det fullständiga');
    ns_private($shortHook, $hidden, 'kortsvar');

    $looseRow = $store->getBooking($loose);
    $loosePaint = jeab_contacts_sole_paint_booking($looseRow);
    $looseHook = ns_hook($store, ['booking_id' => $loose]);
    ns($looseHook['path'] === 'none' && $looseHook['contact_id'] === '' && $looseHook['source_id'] === null && $looseHook['contact_name_real'] === false, 'bokning utan kontakt är none');
    ns(!empty($loosePaint['contact_link_missing']) && $loosePaint['customer_name'] === 'Kopia Los', 'olänkad kopia står kvar');
    ns_private($looseHook, $hidden, 'none-svaret');

    $emptyRow = $store->getBooking($emptyBook);
    $emptyPaint = jeab_contacts_sole_paint_booking($emptyRow);
    $emptyHook = ns_hook($store, ['booking_id' => $emptyBook]);
    ns($emptyHook['path'] === 'none' && $emptyHook['contact_id'] === $emptyId && $emptyHook['contact_name_real'] === false && $emptyHook['source_id'] === null, 'tomt kontaktnamn utan reserv är none');
    ns($emptyPaint['customer_name'] === 'Kopia Tom', 'tom kontakt lämnar kopian');
    ns_private($emptyHook, $hidden, 'tomma svaret');

    $missing = jeab_contacts_sole_dispatch(['action' => 'contacts.name_source', 'booking_id' => 987654321]);
    ns(($missing['ok'] ?? true) === false && ($missing['status'] ?? 0) === 404, 'okänd bokning är 404');
    $missingKey = jeab_contacts_sole_dispatch(['action' => 'contacts.name_source', 'key' => 'kt_finnsinte1']);
    ns(($missingKey['ok'] ?? true) === false && ($missingKey['status'] ?? 0) === 404, 'okänd key är 404');
    foreach ([
        [],
        ['booking_id' => 0],
        ['booking_id' => 'abc'],
        ['booking_id' => ''],
        ['key' => 'nope'],
        ['key' => 'kt_'],
        ['booking_id' => $noraBook, 'key' => $kundId],
        ['key' => $twinId],
    ] as $bad) {
        $fail = jeab_contacts_sole_dispatch(array_merge(['action' => 'contacts.name_source'], $bad));
        ns(($fail['ok'] ?? true) === false && ($fail['status'] ?? 0) === 400, 'ogiltig input är 400');
        ns_private($fail, $hidden, 'felsvar');
    }

    $savedAuth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $denied = jeab_contacts_sole_dispatch(['action' => 'contacts.name_source', 'booking_id' => $noraBook]);
    ns(($denied['ok'] ?? true) === false && ($denied['status'] ?? 0) === 401, 'utan token blir det 401');
    $_SERVER['HTTP_AUTHORIZATION'] = $savedAuth;

    ns($noraReminder > 0 && $mailReminder > 0, 'påminnelserna finns');
}

/**
 * Egen mariadbd, samma mönster som contacts_sole_mysql_test.php.
 * @return array{port:int}|null
 */
function ns_mariadb(): ?array
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
    $tmp = sys_get_temp_dir() . '/jeab-ns-mysql-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0775, true);
    $port = 37200 + random_int(0, 1500);
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
            ns_rm_tree($holder->tmp);
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

function ns_child(string $backend, int $port): void
{
    putenv('JEAB_NS_CHILD=' . $backend);
    putenv('JEAB_NS_PORT=' . (string) $port);
    $outFile = tempnam(sys_get_temp_dir(), 'ns-out-');
    $errFile = tempnam(sys_get_temp_dir(), 'ns-err-');
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
    putenv('JEAB_NS_CHILD');
    putenv('JEAB_NS_PORT');
    echo $out;
    if ($err !== '') {
        fwrite(STDERR, $err);
    }
    if ($code !== 0) {
        exit($code > 0 ? $code : 1);
    }
}

$nsChild = getenv('JEAB_NS_CHILD');
$nsChild = is_string($nsChild) ? $nsChild : '';
if ($nsChild === 'sqlite' || $nsChild === 'mysql') {
    ns_scenarios($nsChild, (int) getenv('JEAB_NS_PORT'));
    exit(0);
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

ns_child('sqlite', 0);
$nsDb = ns_mariadb();
if ($nsDb !== null) {
    ns_child('mysql', $nsDb['port']);
}

echo "ALL OK\n";
