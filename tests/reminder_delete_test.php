<?php

declare(strict_types=1);

/**
 * reminder.delete_test tar bara bort en påminnelse vars rubrik börjar med TEST.
 * dry=1 är standard. dry=0 kopierar raden till data/.private/backups och tar
 * bort kontakten bara när ingen annan påminnelse, order, bokning eller
 * inkorgsärende pekar på den. Hooken kräver samma Bearer som reminders.get
 * och faller inte vidare till inkommande bokningstoken.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-rem-del-test-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$root = dirname(__DIR__);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp . '/public';
mkdir($tmp . '/public', 0775, true);
putenv('JEAB_DISABLE_SQLITE');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");

require $root . '/src/bootstrap.php';
require_once $root . '/src/reminder_delete_test.php';

function rdt(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function rdt_insert(PDO $pdo, string $title, string $contactId, string $note = ''): int
{
    $now = '2026-10-30 12:00:00';
    $pdo->prepare(
        'INSERT INTO reminders (
            list_id, title, customer_name, email, phone, address, ort, postal, assignee, company,
            note, due_at, completed_at, booking_id, contact_id, thread_id, created_at, updated_at,
            ringt, pratat, mailat, smsat
        ) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0)'
    )->execute([
        $title,
        'Test Kund',
        'hemlig@example.test',
        '0700000000',
        'TESTGATAN-RDT-9',
        'Märsta',
        '19592',
        'Elin',
        'jarfalla',
        $note,
        '2026-11-01 00:00:00',
        null,
        null,
        $contactId !== '' ? $contactId : null,
        null,
        $now,
        $now,
    ]);

    return (int) $pdo->lastInsertId();
}

/** @return array<string, mixed> */
function rdt_contact(string $name, string $marker): array
{
    $row = jeab_kontakt_blank([
        'name' => $name,
        'email' => $marker . '@example.test',
        'street' => $marker,
        'personnummer' => '199001011239',
    ]);
    jeab_kontakter_mutate(static function (array &$contacts) use ($row): void {
        $contacts[(string) $row['id']] = $row;
    });

    return $row;
}

$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir) && !mkdir($tokenDir, 0775, true) && !is_dir($tokenDir)) {
    fwrite(STDERR, "FAIL token dir\n");
    exit(1);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-reminder-delete-test']));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';

$store = jeab_store();
rdt($store instanceof JeabPdoStore, 'sqlite-lagret finns');
$pdo = $store->pdo();

$hook = (string) file_get_contents($root . '/hook.php');
$actionAt = strpos($hook, "=== 'reminder.delete_test'");
$incomingAt = strpos($hook, '$token = jeab_ensure_incoming_token($store)');
rdt($actionAt !== false && $incomingAt !== false && $actionAt < $incomingAt, 'hooken ligger före inkommande bokningstoken');
$slice = substr($hook, (int) $actionAt, 700);
rdt(str_contains($slice, 'jeab_reminder_delete_test_dispatch') && str_contains($slice, 'exit;'), 'hooken anropar dispatch och avslutar');

$denied = jeab_reminder_delete_test_dispatch($store, ['id' => 1, 'dry' => 0]);
rdt(($denied['ok'] ?? true) === false && ($denied['status'] ?? 0) === 401, 'utan token ger 401');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-reminder-delete-test';

$noId = jeab_reminder_delete_test_dispatch($store, []);
rdt(($noId['ok'] ?? true) === false && ($noId['status'] ?? 0) === 422, 'id krävs');

$missing = jeab_reminder_delete_test_dispatch($store, ['id' => 999999, 'dry' => 0]);
rdt(($missing['ok'] ?? true) === false && ($missing['status'] ?? 0) === 404, 'okänt id ger 404');

$real = rdt_contact('Riktig Kund', 'REAL-RDT');
$realId = rdt_insert($pdo, 'Solceller hemma', (string) $real['id'], 'HEMLIG-ANTECKNING-REAL');
$refuse = jeab_reminder_delete_test_dispatch($store, ['id' => $realId, 'contact_id' => $real['id'], 'dry' => 0]);
rdt(($refuse['ok'] ?? true) === false && ($refuse['status'] ?? 0) === 409 && str_contains((string) ($refuse['error'] ?? ''), 'TEST'), 'vanlig rubrik nekas');
rdt($store->getReminder($realId) !== null && jeab_kontakt_get((string) $real['id']) !== null, 'nekad körning raderar inget');

$lower = rdt_insert($pdo, 'Test små bokstäver', '');
$lowerRes = jeab_reminder_delete_test_dispatch($store, ['id' => $lower, 'dry' => 0]);
rdt(($lowerRes['ok'] ?? true) === false && $store->getReminder($lower) !== null, 'test med litet t nekas');

$sole = rdt_contact('Ensam Test', 'SOLE-RDT');
$soleRem = rdt_insert($pdo, 'TEST ensam', (string) $sole['id'], 'HEMLIG-ANTECKNING-SOLE');
$other = rdt_contact('Delad Test', 'OTHER-RDT');
$otherRem = rdt_insert($pdo, 'TEST delad', (string) $other['id'], 'HEMLIG-ANTECKNING-OTHER');
$otherKeep = rdt_insert($pdo, 'Vanlig kvar', (string) $other['id'], 'HEMLIG-ANTECKNING-KEEP');
$orderContact = rdt_contact('Order Test', 'ORDER-RDT');
$orderRem = rdt_insert($pdo, 'TEST order', (string) $orderContact['id'], 'HEMLIG-ANTECKNING-ORDER');
$bookContact = rdt_contact('Bokning Test', 'BOOK-RDT');
$bookRem = rdt_insert($pdo, 'TEST bokning', (string) $bookContact['id'], 'HEMLIG-ANTECKNING-BOOK');
$inboxContact = rdt_contact('Inkorg Test', 'INBOX-RDT');
$inboxRem = rdt_insert($pdo, 'TEST inkorg', (string) $inboxContact['id'], 'HEMLIG-ANTECKNING-INBOX');

$pdo->prepare('INSERT INTO customers (name, created_at) VALUES (?, ?)')->execute(['Bokning Test', '2026-10-30 12:00:00']);
$customerId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO bookings (customer_id, customer_name, assignee, starts_at, ends_at, status, contact_id, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    $customerId,
    'Bokning Test',
    'Elin',
    '2026-11-02 09:00:00',
    '2026-11-02 10:00:00',
    'booked',
    $bookContact['id'],
    '2026-10-30 12:00:00',
    '2026-10-30 12:00:00',
]);
$bookingId = (int) $pdo->lastInsertId();
rdt($bookingId > 0, 'bokningen finns');

$order = jeab_purchase_put([
    'reminder_id' => $orderRem,
    'contact_id' => (string) $orderContact['id'],
    'kind' => 'sol',
    'customer_name' => 'Order Test',
]);
rdt((int) ($order['reminder_id'] ?? 0) === $orderRem, 'ordern finns');

$threadId = 'rdt-thread-' . bin2hex(random_bytes(3));
jeab_inkorg_thread_write($threadId, static function (array $t) use ($inboxContact): array {
    $t['status'] = 'open';
    $t['contact_id'] = (string) $inboxContact['id'];
    $t['customer_name'] = 'Inkorg Test';

    return $t;
});
rdt((string) (jeab_inkorg_thread_meta($threadId)['contact_id'] ?? '') === (string) $inboxContact['id'], 'ärendet pekar på kontakten');

$counts = static function () use ($pdo, $threadId): array {
    return [
        'reminders' => (int) $pdo->query('SELECT COUNT(*) FROM reminders')->fetchColumn(),
        'bookings' => (int) $pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn(),
        'customers' => (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn(),
        'thread' => (string) (jeab_inkorg_thread_meta($threadId)['contact_id'] ?? ''),
        'order' => is_array(jeab_purchase_get($GLOBALS['rdt_order_rem'] ?? 0)),
    ];
};
$GLOBALS['rdt_order_rem'] = $orderRem;
$before = $counts();

$dryDefault = jeab_reminder_delete_test_dispatch($store, ['id' => $soleRem, 'contact_id' => $sole['id']]);
rdt(($dryDefault['ok'] ?? false) === true && (int) ($dryDefault['dry'] ?? 0) === 1 && ($dryDefault['deleted'] ?? true) === false, 'dry är 1 som standard');
rdt(($dryDefault['would']['reminder'] ?? 0) === $soleRem && ($dryDefault['would']['contact'] ?? '') === (string) $sole['id'], 'dry visar påminnelse och kontakt');
rdt(($dryDefault['contact']['reason'] ?? '') === 'would_delete' && ($dryDefault['contact']['references']['reminders'] ?? [1]) === [], 'ensam kontakt skulle tas bort');
rdt($store->getReminder($soleRem) !== null && jeab_kontakt_get((string) $sole['id']) !== null && $dryDefault['backup'] === null, 'dry skriver ingen kopia och raderar inget');
$dryJson = (string) json_encode($dryDefault, JSON_UNESCAPED_UNICODE);
rdt(!str_contains($dryJson, 'HEMLIG-ANTECKNING-SOLE') && !str_contains($dryJson, '199001011239') && !str_contains($dryJson, 'SOLE-RDT'), 'dry-svaret lämnar inte anteckning, personnummer eller adress');

$sharedDry = jeab_reminder_delete_test_dispatch($store, ['id' => $otherRem, 'contact_id' => $other['id']]);
rdt(($sharedDry['contact']['kept'] ?? false) === true && ($sharedDry['contact']['references']['reminders'] ?? []) === [$otherKeep], 'annan påminnelse rapporteras');
rdt(array_key_exists('contact', $sharedDry['would']) && $sharedDry['would']['contact'] === null, 'delad kontakt skulle inte tas bort');

$orderDry = jeab_reminder_delete_test_dispatch($store, ['id' => $orderRem, 'contact_id' => $orderContact['id']]);
rdt(($orderDry['contact']['kept'] ?? false) === true && ($orderDry['contact']['references']['orders'] ?? []) === [$orderRem], 'ordern rapporteras');

$bookDry = jeab_reminder_delete_test_dispatch($store, ['id' => $bookRem, 'contact_id' => $bookContact['id']]);
rdt(($bookDry['contact']['kept'] ?? false) === true && ($bookDry['contact']['references']['bookings'] ?? []) === [$bookingId], 'bokningen rapporteras');

$inboxDry = jeab_reminder_delete_test_dispatch($store, ['id' => $inboxRem, 'contact_id' => $inboxContact['id']]);
rdt(($inboxDry['contact']['kept'] ?? false) === true && ($inboxDry['contact']['references']['inbox'] ?? []) === [$threadId], 'ärendet rapporteras');

$gone = jeab_reminder_delete_test_dispatch($store, ['id' => $soleRem, 'contact_id' => $sole['id'], 'dry' => 0]);
rdt(($gone['ok'] ?? false) === true && (int) ($gone['dry'] ?? 1) === 0, 'dry=0 tar bort: ' . json_encode($gone['error'] ?? ''));
rdt(is_array($gone['deleted'] ?? null) && ($gone['deleted']['reminder'] ?? 0) === $soleRem && ($gone['deleted']['contact'] ?? '') === (string) $sole['id'], 'både påminnelse och kontakt är borta');
rdt($store->getReminder($soleRem) === null && jeab_kontakt_get((string) $sole['id']) === null, 'raderna är borta i lagret');
$mismatchContact = rdt_contact('Fel Test', 'MIS-RDT');
$mismatchRem = rdt_insert($pdo, 'TEST fel kontakt', (string) $mismatchContact['id'], 'HEMLIG-ANTECKNING-MISMATCH');
$mismatch = jeab_reminder_delete_test_dispatch($store, ['id' => $mismatchRem, 'contact_id' => $other['id'], 'dry' => 0]);
rdt(($mismatch['ok'] ?? true) === false && ($mismatch['status'] ?? 0) === 409 && $store->getReminder($mismatchRem) !== null && jeab_kontakt_get((string) $mismatchContact['id']) !== null, 'fel contact_id raderar inget');
$backupRel = (string) ($gone['backup'] ?? '');
$backupPath = jeab_data_dir() . '/' . $backupRel;
rdt($backupRel !== '' && is_file($backupPath) && str_starts_with($backupRel, 'backups/reminder-delete-test-'), 'kopian ligger i backups');
$backup = json_decode((string) file_get_contents($backupPath), true);
rdt(is_array($backup) && (int) ($backup['reminder']['id'] ?? 0) === $soleRem && ($backup['reminder']['note'] ?? '') === 'HEMLIG-ANTECKNING-SOLE', 'kopian har påminnelseraden');
rdt(is_array($backup['contact'] ?? null) && ($backup['contact']['id'] ?? '') === (string) $sole['id'] && ($backup['contact']['street'] ?? '') === 'SOLE-RDT', 'kopian har kontakten');
$goneJson = (string) json_encode($gone, JSON_UNESCAPED_UNICODE);
rdt(!str_contains($goneJson, 'HEMLIG-ANTECKNING-SOLE') && !str_contains($goneJson, '199001011239'), 'svaret lämnar inte anteckning eller personnummer');

$sharedGone = jeab_reminder_delete_test_dispatch($store, ['id' => $otherRem, 'contact_id' => $other['id'], 'dry' => '0']);
rdt(($sharedGone['ok'] ?? false) === true && $store->getReminder($otherRem) === null && jeab_kontakt_get((string) $other['id']) !== null, 'delad kontakt ligger kvar');
rdt(is_array($sharedGone['deleted'] ?? null) && $sharedGone['deleted']['contact'] === null && ($sharedGone['contact']['reason'] ?? '') === 'references', 'svaret säger att kontakten behålls');
rdt($store->getReminder($otherKeep) !== null, 'den andra påminnelsen ligger kvar');
$sharedBackup = json_decode((string) file_get_contents(jeab_data_dir() . '/' . (string) $sharedGone['backup']), true);
rdt(is_array($sharedBackup) && !isset($sharedBackup['contact']) && (int) ($sharedBackup['reminder']['id'] ?? 0) === $otherRem, 'kopian har bara påminnelsen när kontakten behålls');

$orderGone = jeab_reminder_delete_test_dispatch($store, ['id' => $orderRem, 'dry' => 0]);
rdt(($orderGone['ok'] ?? false) === true && $store->getReminder($orderRem) === null && jeab_kontakt_get((string) $orderContact['id']) !== null, 'utan contact_id rörs inte kontakten');
rdt(($orderGone['contact']['reason'] ?? '') === 'not_requested' && is_array(jeab_purchase_get($orderRem)), 'ordern ligger kvar');

$bookGone = jeab_reminder_delete_test_dispatch($store, ['id' => $bookRem, 'contact_id' => $bookContact['id'], 'dry' => false]);
rdt(($bookGone['contact']['kept'] ?? false) === true && $store->getBooking($bookingId) !== null && jeab_kontakt_get((string) $bookContact['id']) !== null, 'bokningen och kontakten ligger kvar');

$inboxGone = jeab_reminder_delete_test_dispatch($store, ['id' => $inboxRem, 'contact_id' => $inboxContact['id'], 'dry' => 0]);
rdt(($inboxGone['contact']['kept'] ?? false) === true && (string) (jeab_inkorg_thread_meta($threadId)['contact_id'] ?? '') === (string) $inboxContact['id'], 'ärendet ligger kvar');
rdt(jeab_kontakt_get((string) $inboxContact['id']) !== null && $store->getReminder($realId) !== null && $store->getReminder($lower) !== null, 'övriga rader ligger kvar');

$after = $counts();
rdt($after['bookings'] === $before['bookings'] && $after['customers'] === $before['customers'] && $after['thread'] === $before['thread'] && $after['order'] === true, 'bokning, kund, ärende och order är orörda');
rdt($after['reminders'] === $before['reminders'] - 4, 'de fem TEST-påminnelserna är borta och fel-kontakt-raden ligger kvar');

$curlContact = rdt_contact('Curl Test', 'CURL-RDT');
$curlRem = rdt_insert($pdo, 'TEST curl', (string) $curlContact['id'], 'HEMLIG-ANTECKNING-CURL');
$curlKeep = rdt_insert($pdo, 'Vanlig curl', '');
if ($store instanceof JeabPdoStore) {
    $store->disconnect();
}
if (function_exists('jeab_pdo_release')) {
    jeab_pdo_release();
}

$router = $tmp . '/router.php';
file_put_contents($router, <<<'PHP'
<?php
$GLOBALS['_JEAB_DATA_DIR'] = getenv('JEAB_TEST_DATA');
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = getenv('JEAB_TEST_DATA') . '/public';
if (trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '')) === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        if (strcasecmp((string) $name, 'Authorization') === 0) {
            $_SERVER['HTTP_AUTHORIZATION'] = (string) $value;
        }
    }
}
require getenv('JEAB_ROOT') . '/hook.php';
PHP);
$port = 23000 + (getmypid() % 10000);
$serverLog = $tmp . '/server.log';
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
    [1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']],
    $srvPipes,
    $root,
    ['JEAB_TEST_DATA' => $tmp, 'JEAB_ROOT' => $root]
);
rdt(is_resource($server), 'php -S startade');

$curl = static function (array $payload, string $bearer) use ($port): array {
    $cmd = [
        'curl', '-sS', '-D', '-', '--max-time', '60',
        '-X', 'POST',
        '-H', 'Content-Type: application/json',
        '-H', 'Authorization: Bearer ' . $bearer,
        '--data-binary', json_encode($payload, JSON_UNESCAPED_UNICODE),
        'http://127.0.0.1:' . $port . '/hook.php',
    ];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return ['status' => 0, 'json' => []];
    }
    $raw = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $status = 0;
    if (preg_match('/^HTTP\/\S+\s+(\d+)/m', $raw, $m) === 1) {
        $status = (int) $m[1];
    }
    $split = strpos($raw, "\r\n\r\n");
    $body = $split === false ? $raw : substr($raw, $split + 4);
    $json = json_decode($body, true);

    return ['status' => $status, 'json' => is_array($json) ? $json : [], 'body' => $body, 'err' => $err];
};

$ready = false;
for ($i = 0; $i < 20; $i++) {
    $probe = $curl(['action' => 'reminder.delete_test'], 'fel-token');
    if ($probe['status'] !== 0) {
        $ready = true;
        break;
    }
    usleep(150000);
}
rdt($ready, 'php -S svarar');

$unauth = $curl(['action' => 'reminder.delete_test', 'id' => $curlRem, 'dry' => 0], 'fel-token');
rdt($unauth['status'] === 401 && ($unauth['json']['error'] ?? '') === 'Fel token.', 'curl utan rätt token ger 401 och skapar ingen bokning');
rdt(($unauth['json']['type'] ?? '') === '' && !isset($unauth['json']['data']), 'curl med fel token faller inte igenom till bokningsvägen');

$curlDry = $curl(['action' => 'reminder.delete_test', 'id' => $curlRem, 'contact_id' => $curlContact['id']], 'tok-reminder-delete-test');
rdt($curlDry['status'] === 200 && (int) ($curlDry['json']['dry'] ?? 0) === 1 && ($curlDry['json']['would']['reminder'] ?? 0) === $curlRem && ($curlDry['json']['would']['contact'] ?? '') === (string) $curlContact['id'], 'curl dry visar vad som skulle tas bort');

$curlLive = $curl([
    'action' => 'reminder.delete_test',
    'id' => $curlRem,
    'contact_id' => $curlContact['id'],
    'dry' => 0,
], 'tok-reminder-delete-test');
rdt($curlLive['status'] === 200 && ($curlLive['json']['deleted']['reminder'] ?? 0) === $curlRem && ($curlLive['json']['deleted']['contact'] ?? '') === (string) $curlContact['id'], 'curl dry=0 tar bort TEST-raden och kontakten');

$store = jeab_store(true);
rdt($store->getReminder($curlRem) === null && jeab_kontakt_get((string) $curlContact['id']) === null, 'curl-raderna är borta');
rdt($store->getReminder($curlKeep) !== null && $store->getReminder($realId) !== null && $store->getBooking($bookingId) !== null, 'curl rör inte övriga rader');
$curlBackup = jeab_data_dir() . '/' . (string) ($curlLive['json']['backup'] ?? '');
rdt(is_file($curlBackup), 'curl skrev en kopia');

if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}

echo "reminder.delete_test: all passed\n";
