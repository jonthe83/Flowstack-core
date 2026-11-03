<?php

declare(strict_types=1);

/**
 * contacts.name_candidates och contacts.fill_name. Källor, exakt en ger
 * path candidate när CONTACTS_NAME_CANDIDATE_DISPLAY är på. Flaggan av
 * kör inget kandidatuppslag. name_candidates och fill_name fungerar ändå.
 * fill_name skriver pending-rad före mutate och uppdaterar den efteråt.
 * Påhittade namn. Inga värden i svaret.
 */

function cnc(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function cnc_rm_tree(string $dir): void
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
            cnc_rm_tree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function cnc_contact(string $name, string $email, string $phone): string
{
    $row = jeab_kontakt_find_or_create([
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'address' => 'Kandgatan 1, 111 22 Teststad',
        'city' => 'Teststad',
        'postal_code' => '11122',
        'company' => 'jarfalla',
    ], false, false);
    cnc(is_array($row) && trim((string) ($row['id'] ?? '')) !== '', 'kontakt ' . $email);

    return (string) $row['id'];
}

/** @param list<array<string, mixed>> $owners */
function cnc_owners(string $id, array $owners, string $name, bool $missing = false): void
{
    jeab_kontakter_mutate(static function (array &$contacts) use ($id, $owners, $name, $missing): void {
        if (!isset($contacts[$id]) || !is_array($contacts[$id])) {
            return;
        }
        $contacts[$id]['owners'] = $owners;
        $contacts[$id]['name'] = $name;
        if ($missing) {
            $contacts[$id]['name_missing'] = true;
        } else {
            unset($contacts[$id]['name_missing']);
        }
    });
    jeab_contacts_sole_cache_forget($id);
}

function cnc_set_name(string $id, string $name): void
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

function cnc_book(JeabStore $store, int $customerId, string $contactId, string $copy, string $now): int
{
    $row = $store->insertBooking([
        'customer_id' => $customerId,
        'customer_name' => $copy,
        'email' => 'kopia.' . $contactId . '@example.test',
        'phone' => '0701000800',
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
    cnc($id > 0, 'bokning ' . $copy);

    return $id;
}

/** @param array<string, mixed> $hook */
function cnc_private(array $hook, array $hidden, string $label): void
{
    $json = (string) json_encode($hook, JSON_UNESCAPED_UNICODE);
    cnc(!str_contains($json, '@') && !str_contains($json, 'example.test'), $label . ' har ingen e-post');
    foreach ($hidden as $secret) {
        cnc($secret === '' || !str_contains($json, $secret), $label . ' har inget namn');
    }
}

function cnc_boot(string $backend, int $port): void
{
    $tmp = sys_get_temp_dir() . '/jeab-name-cand-' . $backend . '-' . bin2hex(random_bytes(4));
    mkdir($tmp . '/secrets', 0775, true);
    register_shutdown_function(static function () use ($tmp): void {
        cnc_rm_tree($tmp);
    });
    $GLOBALS['_JEAB_DATA_DIR'] = $tmp;
    $GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
    $GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
    $GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
    $GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
    unset($GLOBALS['_JEAB_CONTACTS_STRICT'], $GLOBALS['_JEAB_CONTACTS_NAME_CANDIDATE_DISPLAY'], $GLOBALS['_JEAB_NAME_CANDIDATE_QUERIES']);
    putenv('JEAB_DISABLE_SQLITE=0');
    putenv('CONTACTS_SOLE=1');
    putenv('CONTACTS_NAME_CANDIDATE_DISPLAY');
    $_ENV['CONTACTS_SOLE'] = '1';
    unset($_ENV['CONTACTS_NAME_CANDIDATE_DISPLAY']);
    $db = [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ];
    if ($backend === 'mysql') {
        if ($port < 1) {
            cnc(false, 'MySQL saknar port');
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
    file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-name-cand']));
    require dirname(__DIR__) . '/src/bootstrap.php';
}

function cnc_scenarios(string $backend, int $port): void
{
    cnc_boot($backend, $port);
    $root = dirname(__DIR__);
    $hookSrc = (string) file_get_contents($root . '/hook.php');
    $dispatchSrc = (string) file_get_contents($root . '/src/contacts_sole.php');
    cnc(str_contains($hookSrc, "'contacts.name_candidates'"), 'hook.php tar name_candidates');
    cnc(str_contains($hookSrc, "'contacts.fill_name'") && str_contains($hookSrc, "'contacts.fill_name_undo'"), 'hook.php tar fill_name');
    cnc(str_contains($dispatchSrc, "=== 'contacts.name_candidates'"), 'dispatch skickar kandidater');
    cnc(str_contains($dispatchSrc, 'jeab_contacts_fill_name('), 'dispatch skickar fill_name');

    $store = jeab_store();
    cnc($store instanceof JeabPdoStore, $backend . '-lagret finns');
    $pdo = $store->pdo();
    cnc((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === $backend, 'lagret är ' . $backend);
    jeab_ensure_contacts_tables($pdo, $backend);
    jeab_ensure_contact_id_columns($pdo, $backend);
    jeab_purchases_store_ensure($pdo);
    if (function_exists('jeab_ensure_inbox_link_undo_table')) {
        jeab_ensure_inbox_link_undo_table($pdo, $backend === 'mysql' ? 'mysql' : 'sqlite');
    }
    cnc(jeab_contacts_sole(), 'kontakten är källan i visningen');

    $tokenDir = dirname(jeab_inkorg_token_path());
    if (!is_dir($tokenDir)) {
        mkdir($tokenDir, 0775, true);
    }
    file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-name-cand']));
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-name-cand';

    $now = jeab_now();
    $customer = $store->insertCustomer('Kopia Kand', $now);

    $realId = cnc_contact('Nora Riktig', 'nora.kand@example.test', '0701000811');
    $realBook = cnc_book($store, (int) $customer['id'], $realId, 'Kopia Nora', $now);

    $oneId = cnc_contact('Tillfallig En', 'en.kand@example.test', '0701000812');
    cnc_set_name($oneId, 'Kund');
    $oneBook = cnc_book($store, (int) $customer['id'], $oneId, 'Kopia En', $now);

    $twoId = cnc_contact('Tillfallig Tva', 'tva.kand@example.test', '0701000813');
    cnc_set_name($twoId, '');
    $twoBook = cnc_book($store, (int) $customer['id'], $twoId, 'Kopia Tva', $now);

    $fillId = cnc_contact('Tillfallig Fyll', 'fyll.kand@example.test', '0701000814');
    cnc_set_name($fillId, '');
    $fillBook = cnc_book($store, (int) $customer['id'], $fillId, 'Kopia Fyll', $now);

    $mysql = $backend === 'mysql';
    try {
        $pdo->query('SELECT id FROM inkorg_list LIMIT 1');
    } catch (Throwable) {
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
    $list = $pdo->prepare('INSERT INTO inkorg_list (id, company, customer_email, contact_id, row_json) VALUES (?, ?, ?, ?, ?)');
    $enc = static function (array $extra): string {
        $json = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '{}';
    };
    $list->execute(['th-book-one', 'jarfalla', 'en.kand@example.test', '', $enc([
        'company' => 'jarfalla',
        'lead_customer' => ['name' => 'Kandidat Ennamn'],
    ])]);
    $list->execute(['th-contact-one', 'jarfalla', 'en.kand@example.test', $oneId, $enc([
        'company' => 'jarfalla',
        'contact_id' => $oneId,
        'lead_customer' => ['name' => 'Kandidat Ennamn'],
    ])]);
    $list->execute(['th-two-a', 'jarfalla', 'tva.kand@example.test', $twoId, $enc([
        'company' => 'jarfalla',
        'contact_id' => $twoId,
        'lead_customer' => ['name' => 'Kandidat Alfa'],
    ])]);
    $list->execute(['th-two-b', 'jarfalla', 'tva.kand@example.test', $twoId, $enc([
        'company' => 'jarfalla',
        'contact_id' => $twoId,
        'lead_customer' => ['name' => 'Kandidat Beta'],
    ])]);
    $list->execute(['th-fill', 'jarfalla', 'fyll.kand@example.test', $fillId, $enc([
        'company' => 'jarfalla',
        'contact_id' => $fillId,
        'lead_customer' => ['name' => 'Kandidat Fyllnamn'],
    ])]);
    $list->execute(['th-real', 'jarfalla', 'nora.kand@example.test', $realId, $enc([
        'company' => 'jarfalla',
        'contact_id' => $realId,
        'lead_customer' => ['name' => 'Kandidat Annat'],
    ])]);

    $store->insertReminder([
        'title' => 'Trad',
        'customer_name' => 'Kund',
        'company' => 'jarfalla',
        'booking_id' => $oneBook,
        'thread_id' => 'th-book-one',
        'contact_id' => $oneId,
        'due_at' => '2026-11-05 09:00:00',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $store->insertReminder([
        'title' => 'Fylltrad',
        'customer_name' => 'Kund',
        'company' => 'jarfalla',
        'booking_id' => $fillBook,
        'thread_id' => 'th-fill',
        'contact_id' => $fillId,
        'due_at' => '2026-11-05 09:00:00',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $holder = $store->insertReminder([
        'title' => 'Orderhallare',
        'customer_name' => 'Kund',
        'company' => 'jarfalla',
        'due_at' => '2026-11-05 09:00:00',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $holderId = (int) ($holder['id'] ?? 0);
    $payload = [
        'reminder_id' => $holderId,
        'booking_id' => $oneBook,
        'contact_id' => $oneId,
        'fortnox_customer' => '4711',
        'CustomerName' => 'Kandidat Ennamn',
        'huvudsokande' => 'Kund',
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    cnc(is_string($json), 'orderpayload kan kodas');
    $pdo->prepare('INSERT INTO purchases (reminder_id, payload, updated_at, contact_id, contact_indexed) VALUES (?, ?, ?, ?, 1)')->execute([
        $holderId,
        $json,
        $now,
        $oneId,
    ]);

    $hidden = [
        'Nora Riktig', 'Kandidat Ennamn', 'Kandidat Alfa', 'Kandidat Beta',
        'Kandidat Fyllnamn', 'Kandidat Annat', 'Kopia Nora', 'Kopia En',
        'Tillfallig En', 'Tillfallig Tva', 'Tillfallig Fyll',
    ];

    unset($GLOBALS['_JEAB_CONTACTS_NAME_CANDIDATES'], $GLOBALS['_JEAB_CONTACTS_SOLE_BOOKING_ORDER'], $GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);

    $oneHook = jeab_contacts_sole_dispatch(['action' => 'contacts.name_candidates', 'booking_id' => $oneBook]);
    cnc(($oneHook['ok'] ?? false) === true && ($oneHook['contact_id'] ?? '') === $oneId, 'kandidater för en-bokningen');
    cnc(($oneHook['contact_name_real'] ?? true) === false, 'platshållare är inte riktigt namn');
    $cands = is_array($oneHook['candidates'] ?? null) ? $oneHook['candidates'] : [];
    cnc(count($cands) === 1, 'samma namn slås ihop till en kandidat');
    $sources = is_array($cands[0]['sources'] ?? null) ? $cands[0]['sources'] : [];
    sort($sources);
    cnc($sources === ['booking_thread', 'contact_thread', 'fortnox_customer'], 'alla tre källor finns');
    $ids = is_array($cands[0]['ids'] ?? null) ? $cands[0]['ids'] : [];
    cnc(in_array('th-book-one', $ids, true) && in_array('th-contact-one', $ids, true) && in_array('4711', $ids, true), 'käll-id:n följer med');
    cnc_private($oneHook, $hidden, 'en-kandidat');

    unset($GLOBALS['_JEAB_CONTACTS_NAME_CANDIDATE_DISPLAY'], $GLOBALS['_JEAB_CONTACTS_NAME_CANDIDATES']);
    $GLOBALS['_JEAB_NAME_CANDIDATE_QUERIES'] = 0;
    cnc(jeab_flag('CONTACTS_NAME_CANDIDATE_DISPLAY') === false, 'kandidatvisning är av som standard');
    foreach ([$oneBook, $twoBook, $realBook, $fillBook] as $bid) {
        $offChoice = jeab_contacts_sole_booking_name_choice($store->getBooking($bid));
        cnc(($offChoice['path'] ?? '') !== 'candidate', 'flaggan av ger inte path candidate');
    }
    $offOne = jeab_contacts_sole_booking_name_choice($store->getBooking($oneBook));
    cnc(($offOne['path'] ?? '') === 'none' && ($offOne['name'] ?? '') !== 'Kandidat Ennamn', 'en kandidat utan flagga är none');
    $offSource = jeab_contacts_sole_dispatch(['action' => 'contacts.name_source', 'booking_id' => $oneBook]);
    cnc(($offSource['path'] ?? '') === 'none', 'name_source utan flagga är inte candidate');
    cnc_private($offSource, $hidden, 'name_source av');
    foreach ($store->listAllBookings() as $row) {
        if (!is_array($row)) {
            continue;
        }
        jeab_contacts_sole_paint_booking(jeab_hydrate_booking($row));
    }
    foreach ($store->bookingsBetween('2026-11-01 00:00:00', '2026-11-06 00:00:00') as $row) {
        if (!is_array($row)) {
            continue;
        }
        jeab_contacts_sole_paint_booking(jeab_hydrate_booking($row));
    }
    cnc((int) ($GLOBALS['_JEAB_NAME_CANDIDATE_QUERIES'] ?? 0) === 0, 'flaggan av kör ingen kandidatfråga');

    $GLOBALS['_JEAB_CONTACTS_NAME_CANDIDATE_DISPLAY'] = true;
    unset($GLOBALS['_JEAB_CONTACTS_NAME_CANDIDATES']);
    $GLOBALS['_JEAB_NAME_CANDIDATE_QUERIES'] = 0;
    $oneRow = $store->getBooking($oneBook);
    $oneChoice = jeab_contacts_sole_booking_name_choice($oneRow);
    $oneAgain = jeab_contacts_sole_booking_name_choice($oneRow);
    cnc(($oneChoice['path'] ?? '') === 'candidate' && ($oneAgain['path'] ?? '') === 'candidate', 'flaggan på ger path candidate');
    cnc(($oneChoice['name'] ?? '') === 'Kandidat Ennamn' && ($oneAgain['name'] ?? '') === 'Kandidat Ennamn', 'visningsnamnet är kandidaten');
    cnc((int) ($GLOBALS['_JEAB_NAME_CANDIDATE_QUERIES'] ?? 0) === 1, 'samma bokning slår upp kandidaten en gång');
    $oneSource = jeab_contacts_sole_dispatch(['action' => 'contacts.name_source', 'booking_id' => $oneBook]);
    cnc(($oneSource['path'] ?? '') === 'candidate', 'name_source rapporterar candidate när flaggan är på');
    cnc((int) ($GLOBALS['_JEAB_NAME_CANDIDATE_QUERIES'] ?? 0) === 1, 'name_source återanvänder kandidatcachen');
    cnc_private($oneSource, $hidden, 'candidate-källan');
    unset($GLOBALS['_JEAB_CONTACTS_NAME_CANDIDATE_DISPLAY']);
    cnc(jeab_flag('CONTACTS_NAME_CANDIDATE_DISPLAY') === false, 'flaggan är av igen');

    $twoHook = jeab_contacts_sole_dispatch(['action' => 'contacts.name_candidates', 'booking_id' => $twoBook]);
    cnc(count($twoHook['candidates'] ?? []) === 2, 'två olika namn blir två kandidater');
    cnc_private($twoHook, $hidden, 'två-kandidater');
    $twoChoice = jeab_contacts_sole_booking_name_choice($store->getBooking($twoBook));
    cnc(($twoChoice['path'] ?? '') === 'none', 'två olika ger ingen candidate-väg');

    $realHook = jeab_contacts_sole_dispatch(['action' => 'contacts.name_candidates', 'booking_id' => $realBook]);
    cnc(($realHook['contact_name_real'] ?? false) === true, 'riktigt kontaktnamn syns som bool');
    cnc_private($realHook, $hidden, 'riktigt-kandidater');
    $realChoice = jeab_contacts_sole_booking_name_choice($store->getBooking($realBook));
    $realSource = jeab_contacts_sole_dispatch(['action' => 'contacts.name_source', 'booking_id' => $realBook]);
    cnc(($realChoice['path'] ?? '') === 'contact' && ($realSource['path'] ?? '') === 'contact', 'riktigt kontaktnamn vinner');
    cnc(($realChoice['name'] ?? '') === 'Nora Riktig', 'visat namn är kontaktens');

    $missing = jeab_contacts_sole_dispatch(['action' => 'contacts.name_candidates', 'booking_id' => 987654321]);
    cnc(($missing['ok'] ?? true) === false && ($missing['status'] ?? 0) === 404, 'okänd bokning är 404');
    $bad = jeab_contacts_sole_dispatch(['action' => 'contacts.name_candidates']);
    cnc(($bad['ok'] ?? true) === false && ($bad['status'] ?? 0) === 400, 'utan booking_id är 400');
    cnc_private($missing, $hidden, 'saknad');
    cnc_private($bad, $hidden, 'ogiltig');

    cnc(jeab_flag('CONTACTS_NAME_CANDIDATE_DISPLAY') === false, 'name_candidates och fill_name körs med flaggan av');
    $fillDry = jeab_contacts_sole_dispatch(['action' => 'contacts.fill_name', 'booking_id' => $fillBook]);
    cnc(($fillDry['ok'] ?? false) === true && ($fillDry['dry'] ?? false) === true && ($fillDry['would_fill'] ?? false) === true, 'fill_name dry visar planen');
    cnc(($fillDry['written'] ?? true) === false && ($fillDry['undo_id'] ?? 'x') === '', 'fill_name dry skriver inte');
    cnc_private($fillDry, $hidden, 'fill dry');
    cnc(trim((string) (jeab_kontakt_get($fillId)['name'] ?? 'x')) === '', 'dry lämnar tomt namn');

    $noConfirm = jeab_contacts_sole_dispatch(['action' => 'contacts.fill_name', 'booking_id' => $fillBook, 'dry' => 0]);
    cnc(($noConfirm['ok'] ?? true) === false && ($noConfirm['status'] ?? 0) === 400, 'fill_name utan confirm är 400');
    cnc_private($noConfirm, $hidden, 'fill utan confirm');

    $realFill = jeab_contacts_sole_dispatch(['action' => 'contacts.fill_name', 'booking_id' => $realBook, 'dry' => 0, 'confirm' => 'name']);
    cnc(($realFill['ok'] ?? false) === true && ($realFill['would_fill'] ?? true) === false, 'riktigt namn fylls inte');
    cnc(($realFill['skipped'][0]['reason'] ?? '') === 'has_name', 'skipped has_name');
    cnc_private($realFill, $hidden, 'fill has_name');

    $twoFill = jeab_contacts_sole_dispatch(['action' => 'contacts.fill_name', 'booking_id' => $twoBook, 'dry' => 0, 'confirm' => 'name']);
    cnc(($twoFill['would_fill'] ?? true) === false && ($twoFill['skipped'][0]['reason'] ?? '') === 'no_candidate', 'två kandidater fylls inte');
    cnc_private($twoFill, $hidden, 'fill två');

    $fillWet = jeab_contacts_sole_dispatch(['action' => 'contacts.fill_name', 'booking_id' => $fillBook, 'dry' => 0, 'confirm' => 'name']);
    cnc(($fillWet['ok'] ?? false) === true && ($fillWet['written'] ?? false) === true, 'fill_name skriver');
    cnc(preg_match('/^[a-f0-9]{16}$/', (string) ($fillWet['undo_id'] ?? '')) === 1, 'fill undo_id följer med');
    cnc_private($fillWet, $hidden, 'fill wet');
    $filled = jeab_kontakt_get($fillId);
    cnc(is_array($filled) && trim((string) ($filled['name'] ?? '')) === 'Kandidat Fyllnamn', 'namnet fylldes i');
    $fillLog = $pdo->prepare("SELECT new_contact_id, old_value FROM inbox_link_contacts_undo WHERE undo_id = ? AND action = 'fill_name'");
    $fillLog->execute([(string) $fillWet['undo_id']]);
    $fillLogRow = $fillLog->fetch(PDO::FETCH_ASSOC);
    cnc(is_array($fillLogRow) && (string) ($fillLogRow['new_contact_id'] ?? '') === $fillId, 'fill-raden uppdateras med kontakt-id');
    $fillOld = json_decode((string) ($fillLogRow['old_value'] ?? ''), true);
    cnc(is_array($fillOld) && isset($fillOld['owners']) && array_key_exists('name', $fillOld), 'fill-raden uppdateras med old_value');
    $fillCount = $pdo->prepare('SELECT COUNT(*) FROM inbox_link_contacts_undo WHERE undo_id = ?');
    $fillCount->execute([(string) $fillWet['undo_id']]);
    cnc((int) $fillCount->fetchColumn() === 1, 'en fill-rad efter lyckad ändring');

    $undoDry = jeab_contacts_sole_dispatch([
        'action' => 'contacts.fill_name_undo',
        'undo_id' => $fillWet['undo_id'],
    ]);
    cnc(($undoDry['ok'] ?? false) === true && ($undoDry['dry'] ?? false) === true && ($undoDry['written'] ?? true) === false, 'fill undo dry skriver inte');
    cnc_private($undoDry, $hidden, 'fill undo dry');
    cnc(trim((string) (jeab_kontakt_get($fillId)['name'] ?? '')) === 'Kandidat Fyllnamn', 'dry undo lämnar ifyllt namn');

    $undoWet = jeab_contacts_sole_dispatch([
        'action' => 'contacts.fill_name',
        'undo' => 1,
        'undo_id' => $fillWet['undo_id'],
        'dry' => 0,
    ]);
    cnc(($undoWet['ok'] ?? false) === true && ($undoWet['written'] ?? false) === true, 'fill undo återställer');
    cnc_private($undoWet, $hidden, 'fill undo wet');
    cnc(trim((string) (jeab_kontakt_get($fillId)['name'] ?? 'x')) === '', 'namnet är tomt efter undo');

    $again = jeab_contacts_sole_dispatch(['action' => 'contacts.fill_name', 'booking_id' => $fillBook, 'dry' => 0, 'confirm' => 'name']);
    cnc(($again['written'] ?? false) === true, 'andra ifyllningen skriver');
    cnc_set_name($fillId, 'Manuellt Annat');
    jeab_contacts_name_candidates_cache_forget($fillBook);
    $skipUndo = jeab_contacts_sole_dispatch([
        'action' => 'contacts.fill_name_undo',
        'undo_id' => $again['undo_id'],
        'dry' => 0,
    ]);
    cnc(($skipUndo['ok'] ?? false) === true, 'undo mot ändrat namn svarar');
    $skipHit = false;
    foreach ($skipUndo['skipped'] ?? [] as $row) {
        if (is_array($row) && (string) ($row['reason'] ?? '') === 'changed') {
            $skipHit = true;
        }
    }
    cnc($skipHit, 'ändrat namn är skipped changed');
    cnc(trim((string) (jeab_kontakt_get($fillId)['name'] ?? '')) === 'Manuellt Annat', 'ändrat namn står kvar');
    cnc_private($skipUndo, $hidden, 'fill undo skipped');

    $delId = cnc_contact('Tillfallig Del', 'del.kand@example.test', '0701000815');
    cnc_owners($delId, [[
        'contact' => false,
        'name' => 'Delagare Riktig',
        'email' => 'del.kand@example.test',
        'phone' => '0701000815',
    ]], '', true);
    $delBook = cnc_book($store, (int) $customer['id'], $delId, 'Kopia Del', $now);
    $list->execute(['th-del', 'jarfalla', 'del.kand@example.test', $delId, $enc([
        'company' => 'jarfalla',
        'contact_id' => $delId,
        'lead_customer' => ['name' => 'Kandidat Delnamn'],
    ])]);
    $tomId = cnc_contact('Tillfallig Tom', 'tom.kand@example.test', '0701000816');
    cnc_owners($tomId, [
        ['contact' => true, 'name' => '', 'email' => 'tom.kand@example.test', 'phone' => '0701000816'],
        ['contact' => false, 'name' => 'Delagare Sitter', 'email' => '', 'phone' => ''],
    ], '');
    $tomBook = cnc_book($store, (int) $customer['id'], $tomId, 'Kopia Tom', $now);
    $list->execute(['th-tom', 'jarfalla', 'tom.kand@example.test', $tomId, $enc([
        'company' => 'jarfalla',
        'contact_id' => $tomId,
        'lead_customer' => ['name' => 'Kandidat Tomnamn'],
    ])]);
    $raceId = cnc_contact('Tillfallig Race', 'race.kand@example.test', '0701000817');
    cnc_owners($raceId, [[
        'contact' => true,
        'name' => '',
        'email' => 'race.kand@example.test',
        'phone' => '0701000817',
    ]], '');
    $raceBook = cnc_book($store, (int) $customer['id'], $raceId, 'Kopia Race', $now);
    $list->execute(['th-race', 'jarfalla', 'race.kand@example.test', $raceId, $enc([
        'company' => 'jarfalla',
        'contact_id' => $raceId,
        'lead_customer' => ['name' => 'Kandidat Racenam'],
    ])]);
    $moreHidden = [
        'Delagare Riktig', 'Delagare Sitter', 'Kandidat Delnamn', 'Kandidat Tomnamn',
        'Kandidat Racenam', 'Race Hunnit', 'Kandidat Strang', 'Tillfallig Del',
        'Tillfallig Tom', 'Tillfallig Race', 'Kopia Del', 'Kopia Tom', 'Kopia Race',
    ];

    $beforeDel = jeab_kontakt_get($delId);
    cnc(is_array($beforeDel), 'delägar-kontakten finns');
    $beforeOwners = json_encode($beforeDel['owners'] ?? null);
    $beforeName = (string) ($beforeDel['name'] ?? '');
    $delFill = jeab_contacts_sole_dispatch(['action' => 'contacts.fill_name', 'booking_id' => $delBook, 'dry' => 0, 'confirm' => 'name']);
    cnc(($delFill['ok'] ?? false) === true && ($delFill['written'] ?? false) === true, 'delägare utan contact fylls med ny ägare');
    cnc_private($delFill, $moreHidden, 'fill delägare');
    $delNow = jeab_kontakt_get($delId);
    $delOwners = is_array($delNow['owners'] ?? null) ? $delNow['owners'] : [];
    cnc(count($delOwners) === 2, 'en ny ägare lades till');
    cnc((string) ($delOwners[0]['name'] ?? '') === 'Delagare Riktig' && empty($delOwners[0]['contact']), 'delägaren lämnas orörd');
    cnc(!empty($delOwners[1]['contact']) && (string) ($delOwners[1]['name'] ?? '') === 'Kandidat Delnamn', 'nya ägaren fick namnet');
    cnc((string) ($delOwners[1]['email'] ?? 'x') === '' && (string) ($delOwners[1]['phone'] ?? 'x') === '', 'nya ägaren har tom e-post och telefon');
    cnc((string) ($delNow['name'] ?? '') === 'Kandidat Delnamn', 'toppnivå fylldes när den var tom');

    $delUndo = jeab_contacts_sole_dispatch([
        'action' => 'contacts.fill_name_undo',
        'undo_id' => $delFill['undo_id'],
        'dry' => 0,
    ]);
    cnc(($delUndo['ok'] ?? false) === true && ($delUndo['written'] ?? false) === true, 'undo av delägare skriver');
    cnc_private($delUndo, $moreHidden, 'undo delägare');
    $delBack = jeab_kontakt_get($delId);
    cnc(json_encode($delBack['owners'] ?? null) === $beforeOwners, 'undo återställer owners exakt');
    cnc((string) ($delBack['name'] ?? '') === $beforeName, 'undo återställer name');
    cnc(!empty($delBack['name_missing']), 'undo återställer name_missing');

    $tomFill = jeab_contacts_sole_dispatch(['action' => 'contacts.fill_name', 'booking_id' => $tomBook, 'dry' => 0, 'confirm' => 'name']);
    cnc(($tomFill['written'] ?? false) === true, 'tom contact:true fylls');
    cnc_private($tomFill, $moreHidden, 'fill tom contact');
    $tomNow = jeab_kontakt_get($tomId);
    $tomOwners = is_array($tomNow['owners'] ?? null) ? $tomNow['owners'] : [];
    cnc(count($tomOwners) === 2, 'ingen extra ägare när contact:true var tom');
    cnc(!empty($tomOwners[0]['contact']) && (string) ($tomOwners[0]['name'] ?? '') === 'Kandidat Tomnamn', 'contact:true fick namnet');
    cnc((string) ($tomOwners[0]['email'] ?? '') === 'tom.kand@example.test', 'contact:true behåller e-post');
    cnc((string) ($tomOwners[1]['name'] ?? '') === 'Delagare Sitter' && empty($tomOwners[1]['contact']), 'delägaren med riktigt namn rörs inte');

    $GLOBALS['_JEAB_FILL_NAME_BEFORE_LOCK'] = static function () use ($raceId): void {
        cnc_set_name($raceId, 'Race Hunnit');
    };
    $raceFill = jeab_contacts_sole_dispatch(['action' => 'contacts.fill_name', 'booking_id' => $raceBook, 'dry' => 0, 'confirm' => 'name']);
    unset($GLOBALS['_JEAB_FILL_NAME_BEFORE_LOCK']);
    cnc(($raceFill['ok'] ?? false) === true && ($raceFill['written'] ?? true) === false, 'race skriver inte');
    cnc(($raceFill['undo_id'] ?? 'x') === '' && ($raceFill['skipped'][0]['reason'] ?? '') === 'name_present', 'race är skipped name_present');
    cnc_private($raceFill, $moreHidden, 'fill race');
    $raceNow = jeab_kontakt_get($raceId);
    $raceOwners = is_array($raceNow['owners'] ?? null) ? $raceNow['owners'] : [];
    cnc((string) ($raceNow['name'] ?? '') === 'Race Hunnit' && (string) ($raceOwners[0]['name'] ?? '') === 'Race Hunnit', 'kroken hann, kandidaten skrevs inte');
    cnc(count($raceOwners) === 1, 'race lade inte till en ägare');

    $pendId = cnc_contact('Tillfallig Pend', 'pend.kand@example.test', '0701000819');
    cnc_owners($pendId, [[
        'contact' => true,
        'name' => '',
        'email' => 'pend.kand@example.test',
        'phone' => '0701000819',
    ]], '');
    $pendBook = cnc_book($store, (int) $customer['id'], $pendId, 'Kopia Pend', $now);
    $list->execute(['th-pend', 'jarfalla', 'pend.kand@example.test', $pendId, $enc([
        'company' => 'jarfalla',
        'contact_id' => $pendId,
        'lead_customer' => ['name' => 'Kandidat Pendnamn'],
    ])]);
    $sawPending = false;
    $GLOBALS['_JEAB_FILL_NAME_BEFORE_LOCK'] = static function () use ($pdo, $pendBook, $pendId, &$sawPending): void {
        $stmt = $pdo->prepare(
            "SELECT new_contact_id, old_value FROM inbox_link_contacts_undo WHERE action = 'fill_name' AND thread_id = ? AND undone_at IS NULL"
        );
        $stmt->execute([(string) $pendBook]);
        $pendingRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $sawPending = is_array($pendingRow)
            && trim((string) ($pendingRow['new_contact_id'] ?? 'x')) === ''
            && trim((string) ($pendingRow['old_value'] ?? '')) === '{"pending":true}';
        cnc_set_name($pendId, 'Pendlar Namn');
    };
    $pendFill = jeab_contacts_sole_dispatch(['action' => 'contacts.fill_name', 'booking_id' => $pendBook, 'dry' => 0, 'confirm' => 'name']);
    unset($GLOBALS['_JEAB_FILL_NAME_BEFORE_LOCK']);
    cnc($sawPending, 'pending-raden skrivs före mutate');
    cnc(($pendFill['ok'] ?? false) === true && ($pendFill['written'] ?? true) === false, 'oförändrad fill skriver inte');
    cnc(($pendFill['undo_id'] ?? 'x') === '' && ($pendFill['skipped'][0]['reason'] ?? '') === 'name_present', 'oförändrad fill har inget undo_id');
    cnc(trim((string) (jeab_kontakt_get($pendId)['name'] ?? '')) === 'Pendlar Namn', 'kroknamnet står kvar');
    $pendLeft = $pdo->prepare(
        "SELECT COUNT(*) FROM inbox_link_contacts_undo WHERE action = 'fill_name' AND thread_id = ? AND new_contact_id = '' AND undone_at IS NULL"
    );
    $pendLeft->execute([(string) $pendBook]);
    cnc((int) $pendLeft->fetchColumn() === 0, 'oförändrad fill tar bort pending-raden');
    cnc_private($pendFill, array_merge($moreHidden, ['Tillfallig Pend', 'Kandidat Pendnamn', 'Pendlar Namn', 'Kopia Pend']), 'fill pending');

    $halfId = cnc_contact('Tillfallig Halv', 'halv.kand@example.test', '0701000820');
    cnc_set_name($halfId, 'Kandidat Halvnamn');
    $halfBook = cnc_book($store, (int) $customer['id'], $halfId, 'Kopia Halv', $now);
    $list->execute(['th-halv', 'jarfalla', 'halv.kand@example.test', $halfId, $enc([
        'company' => 'jarfalla',
        'contact_id' => $halfId,
        'lead_customer' => ['name' => 'Kandidat Halvnamn'],
    ])]);
    $halfHidden = array_merge($moreHidden, [
        'Tillfallig Pend', 'Kandidat Pendnamn', 'Pendlar Namn', 'Kopia Pend',
        'Tillfallig Halv', 'Kandidat Halvnamn', 'Kopia Halv',
    ]);
    $openUndo = bin2hex(random_bytes(8));
    $pdo->prepare('INSERT INTO inbox_link_contacts_undo (undo_id, created_at, thread_id, tbl, old_contact_id, new_contact_id, action, old_value) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$openUndo, $now, (string) $halfBook, 'contacts', '', '', 'fill_name', '{"pending":true}']);
    $openRes = jeab_contacts_sole_dispatch([
        'action' => 'contacts.fill_name_undo',
        'undo_id' => $openUndo,
        'dry' => 0,
    ]);
    $openPending = false;
    foreach ($openRes['skipped'] ?? [] as $row) {
        if (is_array($row) && (string) ($row['reason'] ?? '') === 'pending' && (string) ($row['contact_id'] ?? 'x') === '') {
            $openPending = true;
        }
    }
    cnc(($openRes['ok'] ?? false) === true && ($openRes['written'] ?? true) === false && ($openRes['restored'] ?? []) === [] && $openPending, 'pending fill utan kontakt-id återställer inte');
    cnc_private($openRes, $halfHidden, 'undo pending tom');
    cnc(trim((string) (jeab_kontakt_get($halfId)['name'] ?? '')) === 'Kandidat Halvnamn', 'namnet står kvar efter pending-undo');
    $openAgain = jeab_contacts_sole_dispatch([
        'action' => 'contacts.fill_name_undo',
        'undo_id' => $openUndo,
    ]);
    cnc(($openAgain['ok'] ?? true) === false && ($openAgain['status'] ?? 0) === 409, 'undo av pending fill är redan gjord');
    cnc_private($openAgain, $halfHidden, 'undo pending igen');

    $nullUndo = bin2hex(random_bytes(8));
    $pdo->prepare('INSERT INTO inbox_link_contacts_undo (undo_id, created_at, thread_id, tbl, old_contact_id, new_contact_id, action, old_value) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$nullUndo, $now, (string) $halfBook, 'contacts', '', $halfId, 'fill_name', null]);
    $nullRes = jeab_contacts_sole_dispatch([
        'action' => 'contacts.fill_name_undo',
        'undo_id' => $nullUndo,
        'dry' => 0,
    ]);
    $nullPending = false;
    foreach ($nullRes['skipped'] ?? [] as $row) {
        if (is_array($row) && (string) ($row['reason'] ?? '') === 'pending' && (string) ($row['contact_id'] ?? '') === $halfId) {
            $nullPending = true;
        }
    }
    cnc(($nullRes['ok'] ?? false) === true && ($nullRes['written'] ?? true) === false && ($nullRes['restored'] ?? []) === [] && $nullPending, 'pending fill utan old_value återställer inte');
    cnc_private($nullRes, $halfHidden, 'undo pending null');
    cnc(trim((string) (jeab_kontakt_get($halfId)['name'] ?? '')) === 'Kandidat Halvnamn', 'namnet står kvar när old_value saknas');

    $legacyId = cnc_contact('Tillfallig Strang', 'strang.kand@example.test', '0701000818');
    cnc_set_name($legacyId, 'Kandidat Strang');
    $legacyBook = cnc_book($store, (int) $customer['id'], $legacyId, 'Kopia Strang', $now);
    $list->execute(['th-strang', 'jarfalla', 'strang.kand@example.test', $legacyId, $enc([
        'company' => 'jarfalla',
        'contact_id' => $legacyId,
        'lead_customer' => ['name' => 'Kandidat Strang'],
    ])]);
    $legacyUndo = bin2hex(random_bytes(8));
    $pdo->prepare('INSERT INTO inbox_link_contacts_undo (undo_id, created_at, thread_id, tbl, old_contact_id, new_contact_id, action, old_value) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$legacyUndo, $now, (string) $legacyBook, 'contacts', '', $legacyId, 'fill_name', '']);
    $legacyWet = jeab_contacts_sole_dispatch([
        'action' => 'contacts.fill_name_undo',
        'undo_id' => $legacyUndo,
        'dry' => 0,
    ]);
    cnc(($legacyWet['ok'] ?? false) === true && ($legacyWet['written'] ?? false) === true, 'gammal old_value-sträng återställs');
    cnc_private($legacyWet, array_merge($moreHidden, ['Tillfallig Strang', 'Kopia Strang']), 'undo strang');
    cnc(trim((string) (jeab_kontakt_get($legacyId)['name'] ?? 'x')) === '', 'sträng-undo tömmer namnet');

    $savedAuth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $denied = jeab_contacts_sole_dispatch(['action' => 'contacts.name_candidates', 'booking_id' => $oneBook]);
    cnc(($denied['ok'] ?? true) === false && ($denied['status'] ?? 0) === 401, 'utan token blir det 401');
    $_SERVER['HTTP_AUTHORIZATION'] = $savedAuth;
}

function cnc_mariadb(): ?array
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
    $tmp = sys_get_temp_dir() . '/jeab-cnc-mysql-' . bin2hex(random_bytes(4));
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
            cnc_rm_tree($holder->tmp);
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

function cnc_child(string $backend, int $port): void
{
    putenv('JEAB_CNC_CHILD=' . $backend);
    putenv('JEAB_CNC_PORT=' . (string) $port);
    $outFile = tempnam(sys_get_temp_dir(), 'cnc-out-');
    $errFile = tempnam(sys_get_temp_dir(), 'cnc-err-');
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
    putenv('JEAB_CNC_CHILD');
    putenv('JEAB_CNC_PORT');
    echo $out;
    if ($err !== '') {
        fwrite(STDERR, $err);
    }
    if ($code !== 0) {
        exit($code > 0 ? $code : 1);
    }
}

$cncChild = getenv('JEAB_CNC_CHILD');
$cncChild = is_string($cncChild) ? $cncChild : '';
if ($cncChild === 'sqlite' || $cncChild === 'mysql') {
    $cncPort = (int) getenv('JEAB_CNC_PORT');
    cnc_scenarios($cncChild, $cncPort);
    exit(0);
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

cnc_child('sqlite', 0);
$cncDb = cnc_mariadb();
if ($cncDb !== null) {
    cnc_child('mysql', $cncDb['port']);
}

echo "ALL OK\n";
