<?php

declare(strict_types=1);

/**
 * Ett automatiskt intag återanvänder bara den kontakt som skapas eller kopplas.
 * Ett orelaterat dubblettpar i registret ligger kvar, och påminnelserna pekar inte om.
 * kontakter.dedupe_log läser de senaste raderna utan personnummer.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-auto-reuse-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
session_save_path($tmp);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
putenv('CONTACTS_SOLE=0');
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
jeab_ordrar_external_live(false);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = [];

function ar(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$secretPnr = (static function (): string {
    $nine = '900101123';
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $n = (int) $nine[$i];
        if ($i % 2 === 0) {
            $n *= 2;
            if ($n > 9) {
                $n -= 9;
            }
        }
        $sum += $n;
    }
    $check = (10 - ($sum % 10)) % 10;

    return '19900101-123' . $check;
})();
$store = jeab_store();
ar($store instanceof JeabPdoStore, 'sqlite-lagret finns');
jeab_ensure_contacts_tables($store->pdo(), 'sqlite');

$pair = jeab_kontakter_mutate(static function (array &$contacts) use ($secretPnr): array {
    $left = jeab_kontakt_blank([
        'name' => 'Uno Dublett',
        'email' => 'uno.dublett@example.test',
        'phone' => '0703001001',
        'street' => 'Dubbelgatan 1',
        'street_key' => jeab_kontakt_street_key('Dubbelgatan 1'),
        'postal_code' => '11122',
        'city' => 'Teststad',
        'personnummer' => $secretPnr,
    ]);
    $left['ovrigt'] = 'par-vanster';
    $right = jeab_kontakt_blank([
        'name' => 'Uno Dublett',
        'email' => 'uno.dublett@example.test',
        'phone' => '0703001002',
        'personnummer' => $secretPnr,
    ]);
    $right['ovrigt'] = 'par-hoger';
    $contacts[(string) $left['id']] = $left;
    $contacts[(string) $right['id']] = $right;

    return ['left' => (string) $left['id'], 'right' => (string) $right['id']];
});
ar(is_array($pair) && $pair['left'] !== '' && $pair['right'] !== '' && $pair['left'] !== $pair['right'], 'dubblettparet finns som två kontakter');
$leftId = (string) $pair['left'];
$rightId = (string) $pair['right'];

$now = jeab_now();
$leftReminder = $store->insertReminder([
    'title' => 'Vänster dublett',
    'customer_name' => 'Uno Dublett',
    'email' => 'uno.dublett@example.test',
    'phone' => '0703001001',
    'address' => 'Dubbelgatan 1',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 09:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$rightReminder = $store->insertReminder([
    'title' => 'Höger dublett',
    'customer_name' => 'Uno Dublett',
    'email' => 'uno.dublett@example.test',
    'phone' => '0703001002',
    'address' => '',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 09:30:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$store->pdo()->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute([$leftId, (int) $leftReminder['id']]);
$store->pdo()->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute([$rightId, (int) $rightReminder['id']]);

$planBefore = jeab_kontakt_dedupe_plan(jeab_kontakter_load(), $store);
$pairWouldMerge = false;
foreach ($planBefore['merges'] as $merge) {
    $ids = [(string) ($merge['from'] ?? ''), (string) ($merge['to'] ?? '')];
    sort($ids);
    $want = [$leftId, $rightId];
    sort($want);
    if ($ids === $want && (string) ($merge['rule'] ?? '') === 'email') {
        $pairWouldMerge = true;
    }
}
ar($pairWouldMerge, 'paret är en e-postdubblett som en global körning skulle slå ihop');

$created = jeab_kontakt_find_or_create([
    'name' => 'Ny Kund',
    'email' => 'ny.kund@example.test',
    'phone' => '0703001009',
    'address' => 'Nyagatan 8',
    'postal_code' => '33344',
    'city' => 'Teststad',
    'personnummer' => $secretPnr,
], false, true);
ar(is_array($created) && (string) ($created['id'] ?? '') !== '', 'intaget skapar den nya kontakten');
$createdId = (string) $created['id'];
ar($createdId !== $leftId && $createdId !== $rightId, 'den nya kontakten är inte en av dubbletterna');
ar(!is_file($tmp . '/kontakter-dedupe.log'), 'en ny kontakt skriver ingen återanvändningsrad');

$fresh = $store->insertReminder([
    'title' => 'Ny kund igen',
    'customer_name' => 'Ny Kund',
    'email' => 'ny.kund@example.test',
    'phone' => '0703001009',
    'address' => '',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 11:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$attached = jeab_kontakt_attach_reminder($store, $fresh, [
    'name' => 'Ny Kund',
    'email' => 'ny.kund@example.test',
    'phone' => '0703001009',
    'address' => '',
]);
ar(is_array($attached) && (string) ($attached['id'] ?? '') === $createdId, 'samma e-post återanvänder den nya kontakten');
ar(count(jeab_kontakter_load()) === 3, 'återanvändningen skapar ingen extra kontakt');

$phoneMade = jeab_kontakt_find_or_create([
    'name' => 'Pia Telefon',
    'phone' => '0703001010',
    'address' => 'Telefongatan 4',
    'postal_code' => '44455',
    'city' => 'Teststad',
    'personnummer' => $secretPnr,
], false, true);
ar(is_array($phoneMade) && (string) ($phoneMade['id'] ?? '') !== '', 'telefonkontakten skapas');
$phoneId = (string) $phoneMade['id'];
$phoneReminder = $store->insertReminder([
    'title' => 'Pia igen',
    'customer_name' => 'Pia Telefon',
    'email' => '',
    'phone' => '0703001010',
    'address' => '',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 12:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$phoneAttached = jeab_kontakt_attach_reminder($store, $phoneReminder, [
    'name' => 'Pia Telefon',
    'email' => '',
    'phone' => '0703001010',
    'address' => '',
]);
ar(is_array($phoneAttached) && (string) ($phoneAttached['id'] ?? '') === $phoneId, 'samma telefon återanvänder kontakten');

$loaded = jeab_kontakter_load();
ar(isset($loaded[$leftId], $loaded[$rightId]), 'dubblettparet ligger kvar efter intaget');
ar((string) ($loaded[$leftId]['ovrigt'] ?? '') === 'par-vanster' && (string) ($loaded[$rightId]['ovrigt'] ?? '') === 'par-hoger', 'parets övrigt är orört');
ar((string) ($store->getReminder((int) $leftReminder['id'])['contact_id'] ?? '') === $leftId, 'vänster påminnelse pekar kvar');
ar((string) ($store->getReminder((int) $rightReminder['id'])['contact_id'] ?? '') === $rightId, 'höger påminnelse pekar kvar');
ar((string) ($store->getReminder((int) $fresh['id'])['contact_id'] ?? '') === $createdId, 'den nya påminnelsen kopplas till den återanvända kontakten');
ar(count($loaded) === 4, 'intaget slår inte ihop andra kontakter');

$storedPnr = '';
foreach ((array) ($loaded[$leftId]['owners'] ?? []) as $owner) {
    if (is_array($owner) && trim((string) ($owner['personnummer'] ?? '')) !== '') {
        $storedPnr = (string) $owner['personnummer'];
    }
}
ar($storedPnr !== '', 'personnumret finns på kontakten');

$logPath = $tmp . '/kontakter-dedupe.log';
$log = is_file($logPath) ? (string) file_get_contents($logPath) : '';
ar($log !== '' && !str_contains($log, $secretPnr) && !str_contains($log, $storedPnr), 'loggen innehåller inte personnummer');
$lines = array_values(array_filter(explode("\n", $log), static fn (string $line): bool => trim($line) !== ''));
ar(count($lines) === 2, 'bara de två återanvändningarna loggas');
$decoded = [];
foreach ($lines as $line) {
    $row = json_decode($line, true);
    ar(is_array($row), 'loggraden är json');
    $decoded[] = $row;
}
$byTo = [];
foreach ($decoded as $row) {
    $byTo[(string) ($row['to'] ?? '')] = $row;
}
$emailRow = $byTo[$createdId] ?? null;
$phoneRow = $byTo[$phoneId] ?? null;
ar(
    is_array($emailRow)
    && ($emailRow['from'] ?? '') === 'ny'
    && ($emailRow['to'] ?? '') === $createdId
    && ($emailRow['rule'] ?? '') === 'email'
    && ($emailRow['caller'] ?? '') === 'jeab_kontakt_attach_reminder'
    && isset($emailRow['time']),
    'e-poståteranvändningen loggar from, to, regel och anropare'
);
ar(
    is_array($phoneRow)
    && ($phoneRow['from'] ?? '') === 'ny'
    && ($phoneRow['rule'] ?? '') === 'phone'
    && ($phoneRow['caller'] ?? '') === 'jeab_kontakt_attach_reminder',
    'telefonåteranvändningen loggar regeln phone'
);
ar(!isset($byTo[$leftId]) && !isset($byTo[$rightId]), 'paret skrivs inte som en hopslagning');

$logBeforeHook = $log;
$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = jeab_kontakter_dedupe_log_dispatch(['action' => 'kontakter.dedupe_log', 'limit' => 1]);
ar(($denied['status'] ?? 0) === 401 && ($denied['ok'] ?? true) === false, 'kontakter.dedupe_log utan token ger 401');
ar((string) file_get_contents($logPath) === $logBeforeHook, 'nekad läsning skriver inget');

$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-auto-reuse']));
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-auto-reuse';
$one = jeab_kontakter_dedupe_log_dispatch(['action' => 'kontakter.dedupe_log', 'limit' => 1]);
ar(($one['ok'] ?? false) === true && ($one['limit'] ?? 0) === 1 && count((array) ($one['lines'] ?? [])) === 1, 'limit 1 ger den senaste raden');
$latest = (array) ($one['lines'][0] ?? []);
ar(($latest['to'] ?? '') === $phoneId && ($latest['rule'] ?? '') === 'phone' && ($latest['caller'] ?? '') === 'jeab_kontakt_attach_reminder', 'senaste raden är telefonåteranvändningen');
ar(!array_key_exists('personnummer', $latest) && !array_key_exists('pnr', $latest), 'hooksvaret har inget personnummerfält');
$encoded = json_encode($one, JSON_UNESCAPED_UNICODE);
ar(is_string($encoded) && !str_contains($encoded, $secretPnr) && !str_contains($encoded, $storedPnr), 'hooksvaret innehåller inte personnummer');

$dry = jeab_kontakter_dedupe(['dry' => 1], $store);
$still = false;
foreach ((array) ($dry['merges'] ?? []) as $merge) {
    $ids = [(string) ($merge['from'] ?? ''), (string) ($merge['to'] ?? '')];
    sort($ids);
    $want = [$leftId, $rightId];
    sort($want);
    if ($ids === $want) {
        $still = true;
    }
}
ar(!empty($dry['dry']) && $still, 'kontakter.dedupe i torr körning ser fortfarande paret');
ar(isset(jeab_kontakter_load()[$leftId], jeab_kontakter_load()[$rightId]), 'den torra körningen lämnar paret');
ar(substr_count((string) file_get_contents($logPath), "\n") === substr_count($logBeforeHook, "\n"), 'den torra körningen skriver ingen loggrad');

$runner = $tmp . '/hook-runner.php';
file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$GLOBALS['_JEAB_DATA_DIR'] = $argv[1];
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $argv[1];
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $argv[2];
$GLOBALS['_JEAB_RAW_BODY'] = $argv[3];
require $argv[4];
PHP);
$hookOut = [];
$hookCode = 0;
exec(
    'php ' . escapeshellarg($runner) . ' '
    . escapeshellarg($tmp) . ' '
    . escapeshellarg('tok-auto-reuse') . ' '
    . escapeshellarg('{"action":"kontakter.dedupe_log","limit":1}') . ' '
    . escapeshellarg(dirname(__DIR__) . '/hook.php'),
    $hookOut,
    $hookCode
);
$hook = json_decode(implode("\n", $hookOut), true);
ar($hookCode === 0 && is_array($hook) && count((array) ($hook['lines'] ?? [])) === 1, 'hook.php svarar på kontakter.dedupe_log');
$hookLine = (array) ($hook['lines'][0] ?? []);
ar(($hookLine['rule'] ?? '') === 'phone' && ($hookLine['caller'] ?? '') === 'jeab_kontakt_attach_reminder' && !isset($hookLine['personnummer']), 'hook.php lämnar senaste raden utan personnummer');
$hookJson = implode("\n", $hookOut);
ar(!str_contains($hookJson, $secretPnr) && !str_contains($hookJson, $storedPnr), 'hook.php-svaret innehåller inte personnummer');

echo "kontakter_auto_reuse_test klar\n";
