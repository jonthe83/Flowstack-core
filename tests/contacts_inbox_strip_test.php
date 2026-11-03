<?php

declare(strict_types=1);

/**
 * contacts.inbox_strip. Påhittade id. Svaret har id och antal.
 * Bara inkorgsrader med contact_id töms. Spam utan kontakt behåller sina fält.
 * Ångra tar bara oförändrade rader. Inget skickas och inga kontakter skapas.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-inbox-strip-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
$public = $tmp . '/public-data';
mkdir($public, 0775, true);
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
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
    'incoming_webhook_token' => 'not-the-inbox-token',
], true) . ";\n");
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-inbox-strip']));
require dirname(__DIR__) . '/src/bootstrap.php';

function cis(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$root = dirname(__DIR__);
$src = (string) file_get_contents($root . '/src/contacts_inbox_strip.php');
$hookSrc = (string) file_get_contents($root . '/hook.php');
$dispatchSrc = (string) file_get_contents($root . '/src/contacts_sole.php');
cis(str_contains($hookSrc, "'contacts.inbox_strip'") && str_contains($hookSrc, "'contacts.inbox_strip_undo'"), 'hook.php tar båda åtgärderna');
cis(str_contains($dispatchSrc, "=== 'contacts.inbox_strip'") && str_contains($dispatchSrc, 'jeab_contacts_inbox_strip('), 'dispatch skickar tömning');
cis(str_contains($dispatchSrc, 'jeab_contacts_inbox_strip_undo('), 'dispatch skickar undo');
cis(str_contains($src, 'contacts-inbox-strip') && str_contains($src, '.private') && str_contains($src, '0600'), 'ångerfilen ligger under .private och är 0600');
cis(str_contains($src, "return true;") && str_contains($src, "array_key_exists('dry'"), 'dry saknas är torr körning');
require_once $root . '/src/contacts_inbox_strip.php';
cis(jeab_contacts_inbox_strip_dry(['dry' => '0x']) === true, '0x är torr');
cis(jeab_contacts_inbox_strip_dry(['dry' => '[]']) === true, '[] är torr');
cis(jeab_contacts_inbox_strip_dry(['dry' => '']) === true, 'tom dry är torr');

$store = jeab_store();
cis($store instanceof JeabPdoStore, 'sqlite-lagret är öppet');
$pdo = $store->pdo();
$pnr = '199001011239';
$stamp = '2026-01-01 00:00:00';

function cis_cols(PDO $pdo, string $table): array
{
    $have = [];
    try {
        $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
    } catch (Throwable) {
        return [];
    }
    foreach ($stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
        if (is_array($row) && isset($row['name'])) {
            $have[(string) $row['name']] = true;
        }
    }

    return $have;
}

function cis_add(PDO $pdo, string $table, string $column): void
{
    if (cis_cols($pdo, $table) !== [] && !isset(cis_cols($pdo, $table)[$column])) {
        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' TEXT NOT NULL DEFAULT \'\'');
    }
}

if (cis_cols($pdo, 'inkorg_threads') === []) {
    $pdo->exec("CREATE TABLE inkorg_threads (
        id TEXT PRIMARY KEY,
        status TEXT NOT NULL DEFAULT 'open',
        updated_at TEXT,
        row_json TEXT NOT NULL
    )");
}
cis_add($pdo, 'inkorg_threads', 'contact_id');
cis_add($pdo, 'inkorg_threads', 'row_json');
if (cis_cols($pdo, 'inkorg_list') === []) {
    $pdo->exec("CREATE TABLE inkorg_list (
        id TEXT PRIMARY KEY,
        company TEXT NOT NULL DEFAULT '',
        customer_email TEXT NOT NULL DEFAULT '',
        contact_id TEXT NOT NULL DEFAULT '',
        row_json TEXT NOT NULL
    )");
}
cis_add($pdo, 'inkorg_list', 'company');
cis_add($pdo, 'inkorg_list', 'customer_email');
cis_add($pdo, 'inkorg_list', 'contact_id');
cis_add($pdo, 'inkorg_list', 'row_json');

$encode = static function (array $extra): string {
    $json = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($json) ? $json : '{}';
};
$linked = [
    'contact_id' => 'kt_ada',
    'customer_name' => 'Ada Strip',
    'customer_email' => 'ada.strip@example.test',
    'customer_address' => 'Storgatan 1',
    'customer_phone' => $pnr,
    'phone' => '0700001111',
    'email' => 'top.ada@example.test',
    'name' => 'Top Ada',
    'address' => '9001011239',
    'customer_city' => 'Jarfalla',
    'ort' => 'Jarfalla',
    'postal' => '17731',
    'postnr' => '177 31',
    'lead_customer' => [
        'email' => 'lead.ada@example.test',
        'name' => 'Ada Lead',
        'phone' => '0700002222',
        'address' => 'Leadgatan 2',
        'note' => 'formular-behall',
    ],
    'note' => 'trad-behall',
    'messages' => [['body' => 'meddelande-behall']],
];
$spam = [
    'contact_id' => '',
    'customer_name' => 'Spam Person',
    'customer_email' => 'spam@example.test',
    'customer_address' => 'Spamgatan 9',
    'phone' => '0709999999',
    'ort' => 'Spamort',
    'postnr' => '99999',
    'note' => 'spam-behall',
];
$blank = [
    'contact_id' => 'kt_ada',
    'customer_name' => '',
    'customer_email' => '',
    'note' => 'redan-tom',
];
$columnOnly = [
    'customer_name' => 'Kolumn Person',
    'customer_email' => 'kolumn@example.test',
    'note' => 'kolumn-behall',
];
$thread = $pdo->prepare('INSERT INTO inkorg_threads (id, status, updated_at, contact_id, row_json) VALUES (?, ?, ?, ?, ?)');
$thread->execute(['th-linked', 'open', $stamp, 'kt_ada', $encode($linked)]);
$thread->execute(['th-spam', 'open', $stamp, '', $encode($spam)]);
$thread->execute(['th-blank', 'open', $stamp, 'kt_ada', $encode($blank)]);
$list = $pdo->prepare('INSERT INTO inkorg_list (id, company, customer_email, contact_id, row_json) VALUES (?, ?, ?, ?, ?)');
$list->execute(['ls-linked', 'jarfalla', 'ada.strip@example.test', 'kt_ada', $encode($linked)]);
$list->execute(['ls-spam', 'jarfalla', 'spam@example.test', '', $encode($spam)]);
$list->execute(['ls-column', 'jarfalla', 'kolumn@example.test', 'kt_ada', $encode($columnOnly)]);

function cis_json(PDO $pdo, string $table, string $id): array
{
    $stmt = $pdo->prepare('SELECT row_json FROM ' . $table . ' WHERE id = ?');
    $stmt->execute([$id]);
    $decoded = json_decode((string) $stmt->fetchColumn(), true);

    return is_array($decoded) ? $decoded : [];
}

function cis_email(PDO $pdo, string $id): string
{
    $stmt = $pdo->prepare('SELECT customer_email FROM inkorg_list WHERE id = ?');
    $stmt->execute([$id]);

    return (string) $stmt->fetchColumn();
}

function cis_clean(string $json, string $pnr): bool
{
    return !str_contains($json, $pnr)
        && !str_contains(strtolower($json), 'personnummer')
        && !str_contains($json, 'ada.strip@example.test')
        && !str_contains($json, 'Ada Strip')
        && !str_contains($json, 'Spam Person')
        && !str_contains($json, '9001011239')
        && !str_contains($json, 'Storgatan')
        && !str_contains($json, '0700001111');
}

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = '';
$denied = jeab_contacts_sole_dispatch(['action' => 'contacts.inbox_strip', 'dry' => 0]);
cis(($denied['status'] ?? 0) === 401 && empty($denied['ok']), 'utan token blir det 401');
cis((cis_json($pdo, 'inkorg_threads', 'th-linked')['customer_name'] ?? '') === 'Ada Strip', 'nekad körning skriver inget');
cis(!is_dir($tmp . '/contacts-inbox-strip'), 'nekad körning skriver ingen ångerfil');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-inbox-strip';
$refused = jeab_contacts_sole_dispatch(['action' => 'contacts.inbox_strip', 'dry' => 0]);
cis(empty($refused['ok']) && (int) ($refused['status'] ?? 0) === 400 && ($refused['written'] ?? true) === false, 'strip vägrar när STRICT är av');
$refusedUndo = jeab_contacts_sole_dispatch(['action' => 'contacts.inbox_strip_undo', 'undo_id' => '20260101010101-abcdef', 'dry' => 0]);
cis(empty($refusedUndo['ok']) && (int) ($refusedUndo['status'] ?? 0) === 400 && ($refusedUndo['written'] ?? true) === false, 'undo vägrar när STRICT är av');
cis((cis_json($pdo, 'inkorg_threads', 'th-linked')['customer_name'] ?? '') === 'Ada Strip', 'vägrad körning skriver inget');
cis(!is_dir($tmp . '/.private/contacts-inbox-strip'), 'vägrad körning skriver ingen ångerfil');
putenv('CONTACTS_SOLE=1');
putenv('CONTACTS_STRICT=1');
$_ENV['CONTACTS_SOLE'] = '1';
$_ENV['CONTACTS_STRICT'] = '1';
$_SERVER['CONTACTS_SOLE'] = '1';
$_SERVER['CONTACTS_STRICT'] = '1';
unset($GLOBALS['_JEAB_CONTACTS_SOLE'], $GLOBALS['_JEAB_CONTACTS_STRICT'], $GLOBALS['_JEAB_CONTACTS_SOLE_STORED']);
cis(jeab_contacts_sole() && jeab_contacts_strict(), 'CONTACTS_SOLE och CONTACTS_STRICT är på');
$errLog = $tmp . '/strip-error.log';
ini_set('error_log', $errLog);

$undoDir = $tmp . '/.private/contacts-inbox-strip';
mkdir($undoDir, 0700, true);
$oldUndo = $undoDir . '/20200101010101-abcdef.json';
file_put_contents($oldUndo, '{"rows":[]}');
touch($oldUndo, time() - 15 * 86400);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-inbox-strip';
$dry = jeab_contacts_sole_dispatch(['action' => 'contacts.inbox_strip']);
cis(!is_file($oldUndo), 'ångerfiler äldre än 14 dagar tas bort');
cis(!empty($dry['ok']) && ($dry['dry'] ?? false) === true && ($dry['written'] ?? true) === false && ($dry['undo_id'] ?? 'x') === '', 'utelämnad dry skriver inte');
cis(($dry['threads'] ?? -1) === 1 && ($dry['list'] ?? -1) === 2, 'torrkörningen räknar länkade rader med kundfält');
$dryIds = is_array($dry['ids'] ?? null) ? $dry['ids'] : [];
sort($dryIds);
cis($dryIds === ['ls-column', 'ls-linked', 'th-linked'], 'spam och redan tom rad utelämnas');
cis(glob($undoDir . '/*.json') === [], 'torrkörningen skriver ingen ångerfil');
cis((cis_json($pdo, 'inkorg_threads', 'th-linked')['customer_email'] ?? '') === 'ada.strip@example.test', 'torrkörningen lämnar trådens fält');
cis(cis_email($pdo, 'ls-linked') === 'ada.strip@example.test', 'torrkörningen lämnar listans e-postkolumn');
cis((cis_json($pdo, 'inkorg_threads', 'th-spam')['customer_name'] ?? '') === 'Spam Person', 'spam behåller sina kundfält');
$dryJson = json_encode($dry, JSON_UNESCAPED_UNICODE);
cis(is_string($dryJson) && cis_clean($dryJson, $pnr), 'dry-svaret saknar kundfält och personnummer');

$port = 18180 + random_int(0, 800);
$router = $tmp . '/router.php';
file_put_contents($router, "<?php\n\$GLOBALS['_JEAB_DATA_DIR'] = " . var_export($tmp, true) . ";\n\$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = " . var_export($public, true) . ";\nrequire " . var_export($root . '/hook.php', true) . ";\n");
$log = $tmp . '/php-server.log';
$cmd = sprintf('exec php -S 127.0.0.1:%d -t %s %s', $port, escapeshellarg($root), escapeshellarg($router));
$proc = proc_open($cmd, [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root);
cis(is_resource($proc), 'php -S startar');
register_shutdown_function(static function () use ($proc): void {
    if (is_resource($proc)) {
        proc_terminate($proc);
    }
});
$up = false;
for ($i = 0; $i < 50; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $errno, $err, 0.2);
    if (is_resource($fp)) {
        fclose($fp);
        $up = true;
        break;
    }
    usleep(100000);
}
cis($up, 'php -S lyssnar');
$curl = static function (string $body, string $token) use ($port): array {
    $ch = curl_init('http://127.0.0.1:' . $port . '/hook.php');
    $headers = ['Content-Type: application/json'];
    if ($token !== '') {
        $headers[] = 'X-Webhook-Token: ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['status' => $status, 'raw' => is_string($raw) ? $raw : '', 'json' => json_decode(is_string($raw) ? $raw : '', true)];
};
$curlDenied = $curl('{"action":"contacts.inbox_strip","dry":0}', '');
cis($curlDenied['status'] === 401 && is_array($curlDenied['json']) && empty($curlDenied['json']['ok']), 'curl utan token blir 401');
$curlDry = $curl('{"action":"contacts.inbox_strip"}', 'tok-inbox-strip');
cis($curlDry['status'] === 200 && is_array($curlDry['json']) && ($curlDry['json']['action'] ?? '') === 'contacts.inbox_strip' && ($curlDry['json']['dry'] ?? false) === true && ($curlDry['json']['written'] ?? true) === false, 'curl med token får torr körning');
cis(cis_clean($curlDry['raw'], $pnr) && ($curlDry['json']['threads'] ?? -1) === 1 && ($curlDry['json']['list'] ?? -1) === 2, 'curl-svaret har antal och saknar kundfält');
cis((cis_json($pdo, 'inkorg_list', 'ls-linked')['customer_name'] ?? '') === 'Ada Strip', 'curl skriver inga fält');
if (is_resource($proc)) {
    proc_terminate($proc);
    proc_close($proc);
}

$wet = jeab_contacts_sole_dispatch(['action' => 'contacts.inbox_strip', 'dry' => 0]);
cis(!empty($wet['ok']) && ($wet['dry'] ?? true) === false && !empty($wet['written']), 'dry=0 skriver');
$undoId = (string) ($wet['undo_id'] ?? '');
cis(preg_match('/^[0-9]{14}-[a-f0-9]{6}$/', $undoId) === 1, 'undo-id är satt');
$undoPath = $undoDir . '/' . $undoId . '.json';
cis(is_file($undoPath), 'ångerfilen ligger under .private/contacts-inbox-strip');
cis((fileperms($undoPath) & 0777) === 0600, 'ångerfilen är 0600');
$undoRaw = (string) file_get_contents($undoPath);
cis(!str_contains($undoRaw, $pnr) && !str_contains($undoRaw, '9001011239') && !str_contains(strtolower($undoRaw), 'personnummer'), 'ångerfilen har inget personnummer');
cis(str_contains($undoRaw, 'th-linked') && str_contains($undoRaw, 'Ada Strip') && str_contains($undoRaw, 'lead.ada@example.test') && str_contains($undoRaw, 'lead_customer.email') && !str_contains($undoRaw, 'meddelande-behall'), 'ångerfilen har bara de tömda kundfälten');
$wetJson = json_encode($wet, JSON_UNESCAPED_UNICODE);
cis(is_string($wetJson) && cis_clean($wetJson, $pnr), 'svaret saknar kundfält och personnummer');
$cleared = cis_json($pdo, 'inkorg_threads', 'th-linked');
cis(($cleared['customer_name'] ?? 'x') === '' && ($cleared['customer_email'] ?? 'x') === '' && ($cleared['phone'] ?? 'x') === '' && ($cleared['email'] ?? 'x') === '' && ($cleared['name'] ?? 'x') === '' && ($cleared['ort'] ?? 'x') === '' && ($cleared['postnr'] ?? 'x') === '', 'länkad tråd töms på kundfält');
cis(($cleared['customer_phone'] ?? '') === $pnr && ($cleared['address'] ?? '') === '9001011239', 'personnummerliknande värden lämnas kvar');
cis(($cleared['lead_customer']['email'] ?? 'x') === '' && ($cleared['lead_customer']['name'] ?? 'x') === '' && ($cleared['lead_customer']['phone'] ?? 'x') === '' && ($cleared['lead_customer']['address'] ?? 'x') === '', 'lead_customer töms på namn, mejl, telefon och adress');
cis(($cleared['contact_id'] ?? '') === 'kt_ada' && ($cleared['note'] ?? '') === 'trad-behall', 'kontaktlänk och övriga fält är kvar');
cis(($cleared['lead_customer']['note'] ?? '') === 'formular-behall' && ($cleared['messages'][0]['body'] ?? '') === 'meddelande-behall', 'formulär och meddelande rörs inte');
$logged = is_file($errLog) ? (string) file_get_contents($errLog) : '';
cis(str_contains($logged, 'table=inkorg_threads id=th-linked field=customer_phone') && str_contains($logged, 'table=inkorg_threads id=th-linked field=address'), 'pnr-fält loggas med tabell, id och fält');
cis(!str_contains($logged, $pnr) && !str_contains($logged, '9001011239'), 'loggen har inget personnummer');
$spamNow = cis_json($pdo, 'inkorg_threads', 'th-spam');
cis(($spamNow['customer_name'] ?? '') === 'Spam Person' && ($spamNow['customer_email'] ?? '') === 'spam@example.test' && ($spamNow['ort'] ?? '') === 'Spamort', 'spamtråden behåller sina kundfält');
cis(cis_email($pdo, 'ls-spam') === 'spam@example.test', 'spamlistan behåller e-postkolumnen');
cis(cis_email($pdo, 'ls-linked') === '' && (cis_json($pdo, 'inkorg_list', 'ls-linked')['customer_email'] ?? 'x') === '', 'länkad listas e-postkolumn och json töms');
cis((cis_json($pdo, 'inkorg_list', 'ls-column')['customer_name'] ?? 'x') === '' && (cis_json($pdo, 'inkorg_list', 'ls-column')['note'] ?? '') === 'kolumn-behall', 'contact_id i kolumnen räcker för tömning');
cis((cis_json($pdo, 'inkorg_threads', 'th-blank')['note'] ?? '') === 'redan-tom', 'redan tom länkad rad rörs inte');

$undoDry = jeab_contacts_sole_dispatch(['action' => 'contacts.inbox_strip_undo', 'undo_id' => $undoId]);
cis(!empty($undoDry['ok']) && ($undoDry['dry'] ?? false) === true && empty($undoDry['written']), 'undo utan dry skriver inte');
cis((cis_json($pdo, 'inkorg_threads', 'th-linked')['customer_name'] ?? 'x') === '', 'torr undo lämnar den tömda raden');
cis(cis_clean((string) json_encode($undoDry, JSON_UNESCAPED_UNICODE), $pnr), 'undo-svaret saknar kundfält och personnummer');

$pdo->prepare('UPDATE inkorg_list SET customer_email = ? WHERE id = ?')->execute(['annan@example.test', 'ls-linked']);
$undoWet = jeab_contacts_sole_dispatch(['action' => 'contacts.inbox_strip_undo', 'undo_id' => $undoId, 'dry' => 0]);
cis(!empty($undoWet['ok']) && !empty($undoWet['written']) && ($undoWet['skipped'] ?? 0) === 1, 'undo dry=0 återställer oförändrade rader och hoppar över en ändrad');
$restoredThread = cis_json($pdo, 'inkorg_threads', 'th-linked');
cis(($restoredThread['customer_name'] ?? '') === 'Ada Strip' && ($restoredThread['customer_phone'] ?? '') === $pnr && ($restoredThread['address'] ?? '') === '9001011239' && ($restoredThread['lead_customer']['email'] ?? '') === 'lead.ada@example.test' && ($restoredThread['email'] ?? '') === 'top.ada@example.test' && ($restoredThread['messages'][0]['body'] ?? '') === 'meddelande-behall', 'tråden fick tillbaka de tömda fälten och personnumret låg kvar');
cis(cis_email($pdo, 'ls-linked') === 'annan@example.test' && (cis_json($pdo, 'inkorg_list', 'ls-linked')['customer_name'] ?? 'x') === '', 'ändrad e-postkolumn hoppas över');
cis(cis_email($pdo, 'ls-column') === 'kolumn@example.test' && (cis_json($pdo, 'inkorg_list', 'ls-column')['customer_name'] ?? '') === 'Kolumn Person', 'oändrad listad fick tillbaka e-postkolumnen');
cis((cis_json($pdo, 'inkorg_threads', 'th-spam')['customer_name'] ?? '') === 'Spam Person', 'undo rör inte spam');
cis(cis_clean((string) json_encode($undoWet, JSON_UNESCAPED_UNICODE), $pnr), 'våt undo-svar saknar kundfält och personnummer');
putenv('CONTACTS_SOLE=0');
$_ENV['CONTACTS_SOLE'] = '0';
$_SERVER['CONTACTS_SOLE'] = '0';
unset($GLOBALS['_JEAB_CONTACTS_SOLE'], $GLOBALS['_JEAB_CONTACTS_SOLE_STORED']);
$soleOff = jeab_contacts_sole_dispatch(['action' => 'contacts.inbox_strip', 'dry' => 0]);
$soleOffUndo = jeab_contacts_sole_dispatch(['action' => 'contacts.inbox_strip_undo', 'undo_id' => $undoId, 'dry' => 0]);
cis(empty($soleOff['ok']) && empty($soleOffUndo['ok']) && ($soleOff['written'] ?? true) === false && ($soleOffUndo['written'] ?? true) === false, 'strip och undo vägrar när SOLE är av');

echo "contacts_inbox_strip_test klar\n";
