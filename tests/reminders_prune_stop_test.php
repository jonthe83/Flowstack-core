<?php

declare(strict_types=1);

/**
 * GET på Påminnelser får inte radera klara rader, oavsett antal, lista och bolag.
 * reminders.deleted_audit är bara läsning: id och antal, ingen kundtext.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-prune-stop-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$hash = password_hash('kod', PASSWORD_DEFAULT);
$email = 'ada@example.com';
$passwords = $tmp . '/passwords.json';
file_put_contents($passwords, json_encode([
    'secret' => 'prune-stop-secret',
    'hashes' => [
        ['hash' => $hash, 'email' => $email],
    ],
], JSON_UNESCAPED_UNICODE));
putenv('JEAB_PASSWORDS_FILE=' . $passwords);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require $root . '/src/bootstrap.php';

$fail = 0;
function stop_ok(bool $ok, string $msg): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $fail++;
    }
}

function stop_ids(JeabStore $store): array
{
    $ids = [];
    foreach ($store->pdo()->query('SELECT id FROM reminders ORDER BY id') as $row) {
        $ids[] = (int) ($row['id'] ?? 0);
    }

    return $ids;
}

function stop_files(string $dir): array
{
    $out = [];
    $stack = [$dir];
    while ($stack !== []) {
        $current = array_pop($stack);
        foreach (scandir($current) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $current . '/' . $name;
            if (is_dir($path)) {
                $stack[] = $path;
                continue;
            }
            $out[$path] = (string) filemtime($path) . ':' . (string) filesize($path);
        }
    }
    ksort($out);

    return $out;
}

$src = (string) file_get_contents($root . '/src/reminders.php');
$index = (string) file_get_contents($root . '/index.php');
$hook = (string) file_get_contents($root . '/hook.php');
$auto = (string) file_get_contents($root . '/src/inkorg_auto.php');
$pruneStart = strpos($src, 'function jeab_prune_completed_reminders(');
$pruneEnd = strpos($src, 'function jeab_reminders_backup_dirs(');
$pruneBody = $pruneStart !== false && $pruneEnd !== false ? substr($src, $pruneStart, $pruneEnd - $pruneStart) : '';
stop_ok($pruneBody !== '' && !str_contains($pruneBody, 'deleteReminders'), 'prune-funktionerna anropar inte deleteReminders');
stop_ok(!str_contains($index, 'jeab_prune_completed_reminders'), 'Påminnelser-sidan anropar inte prune');
stop_ok(str_contains($hook, "'reminders.deleted_audit'"), 'hook.php tar emot reminders.deleted_audit');
stop_ok(substr_count($auto, 'deleteReminders') === 1, 'enda kvarvarande anropet är självtestets uttryckliga städning');

$store = jeab_store();
stop_ok($store instanceof JeabPdoStore, 'sqlite-lager');
$now = jeab_now();
$completed = [];
for ($i = 0; $i < 150; $i++) {
    $row = $store->insertReminder([
        'title' => 'Klar ' . $i,
        'customer_name' => 'Test ' . $i,
        'company' => $i < 80 ? 'jarfalla' : 'malmo',
        'due_at' => '2020-01-01 09:00:00',
        'completed_at' => sprintf('2020-01-%02d 09:00:00', ($i % 27) + 1),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $completed[] = (int) ($row['id'] ?? 0);
}
$store->insertReminder([
    'title' => 'Öppen Järfälla',
    'customer_name' => 'Öppen',
    'company' => 'jarfalla',
    'due_at' => '2026-11-01 09:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$store->insertReminder([
    'title' => 'Öppen Malmö',
    'customer_name' => 'Öppen',
    'company' => 'malmo',
    'due_at' => '2026-11-02 09:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$pdo = $store->pdo();
$pdo->prepare('INSERT INTO reminder_lists (name, created_at) VALUES (?, ?)')->execute(['Andra listan', $now]);
$list2 = (int) $pdo->lastInsertId();
$malmoIds = array_slice($completed, 80);
$marks = implode(',', array_fill(0, count($malmoIds), '?'));
$pdo->prepare('UPDATE reminders SET list_id = ? WHERE id IN (' . $marks . ')')->execute(array_merge([$list2], $malmoIds));
$beforeIds = stop_ids($store);
$doneBefore = (int) $pdo->query('SELECT COUNT(*) FROM reminders WHERE completed_at IS NOT NULL')->fetchColumn();
$malmoBefore = (int) $pdo->query("SELECT COUNT(*) FROM reminders WHERE company = 'malmo' AND completed_at IS NOT NULL")->fetchColumn();
$listBefore = (int) $pdo->query('SELECT COUNT(*) FROM reminders WHERE list_id = ' . $list2)->fetchColumn();
stop_ok($doneBefore === 150 && $malmoBefore === 70 && $listBefore === 70 && count($beforeIds) === 152, '150 klara fördelade på två bolag och två listor');

@unlink(jeab_prune_stamp_path());
stop_ok(jeab_prune_completed_reminders($store, false) === 0, 'sidans prune raderar inget');
stop_ok(jeab_prune_completed_reminders($store, true) === 0, 'tvingad prune raderar inget');
stop_ok(jeab_prune_completed_reminders_now($store) === 0, 'prune-nu raderar inget');
stop_ok(!is_file(jeab_prune_stamp_path()) && !is_file(jeab_prune_stamp_path() . '.lock'), 'prune skriver ingen stämpel');
stop_ok(stop_ids($store) === $beforeIds, 'id-listan är oförändrad efter prune');

$value = jeab_auth_issue_value($email, $hash);
stop_ok(is_string($value) && $value !== '', 'testkakan kan utfärdas');
jeab_pdo_release();
$router = $tmp . '/router.php';
file_put_contents($router, "<?php\n\$GLOBALS['_JEAB_DATA_DIR'] = getenv('JEAB_TEST_DATA');\n\$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = getenv('JEAB_TEST_DATA');\nrequire getenv('JEAB_ROOT') . '/index.php';\n");
$port = 21000 + (getmypid() % 20000);
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $srvPipes,
    $root,
    [
        'JEAB_TEST_DATA' => $tmp,
        'JEAB_ROOT' => $root,
        'JEAB_PASSWORDS_FILE' => $passwords,
    ]
);
stop_ok(is_resource($server), 'php-servern startade');
$curl = static function (string $url, string $cookie) use ($port): array {
    unset($port);
    $proc = proc_open(
        ['curl', '-sS', '-D', '-', '--max-time', '30', '-b', 'jeab_auth=' . $cookie, $url],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($proc)) {
        return ['status' => 0, 'body' => ''];
    }
    $raw = (string) stream_get_contents($pipes[1]);
    proc_close($proc);
    $status = 0;
    if (preg_match('/^HTTP\/\S+\s+(\d+)/m', $raw, $m) === 1) {
        $status = (int) $m[1];
    }
    $split = strpos($raw, "\r\n\r\n");

    return ['status' => $status, 'body' => $split === false ? $raw : substr($raw, $split + 4)];
};
$page = ['status' => 0, 'body' => ''];
if (is_string($value)) {
    for ($try = 0; $try < 40; $try++) {
        $page = $curl('http://127.0.0.1:' . $port . '/index.php?p=reminders', $value);
        if ($page['status'] > 0) {
            break;
        }
        usleep(100000);
    }
}
stop_ok($page['status'] === 200 && str_contains($page['body'], 'Påminnelser') && !str_contains($page['body'], 'kampanjkod'), 'GET Påminnelser svarar inloggad, status ' . $page['status']);
if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}

$store = jeab_store(true);
$afterIds = stop_ids($store);
$doneAfter = (int) $store->pdo()->query('SELECT COUNT(*) FROM reminders WHERE completed_at IS NOT NULL')->fetchColumn();
$malmoAfter = (int) $store->pdo()->query("SELECT COUNT(*) FROM reminders WHERE company = 'malmo' AND completed_at IS NOT NULL")->fetchColumn();
$listAfter = (int) $store->pdo()->query('SELECT COUNT(*) FROM reminders WHERE list_id = ' . $list2)->fetchColumn();
stop_ok($afterIds === $beforeIds, 'GET lämnar samma påminnelse-id');
stop_ok($doneAfter === 150 && $malmoAfter === 70 && $listAfter === 70, 'GET raderar inga klara, varken Malmö eller den andra listan');
stop_ok(!is_file(jeab_prune_stamp_path()), 'GET skriver ingen prune-stämpel');

$liveId = $completed[0];
$older = $tmp . '/kontakter.bak-20200101-000000.json';
file_put_contents($older, json_encode([
    'reminders' => [['id' => 900777, 'contact_id' => 'c-gammal']],
    'contacts' => ['c-gammal' => ['name' => 'Gammal Hemlig']],
], JSON_UNESCAPED_UNICODE));
touch($older, time() - 7200);
$storePath = jeab_json_path();
file_put_contents($storePath, json_encode([
    'reminders' => [
        [
            'id' => $liveId,
            'title' => 'Hemlig Kundsson',
            'email' => 'hemlig.kund@example.test',
            'phone' => '0700000001',
            'note' => 'anteckning hemlig',
        ],
        [
            'id' => 900001,
            'title' => 'Hemlig Kundsson',
            'customer_name' => 'Hemlig Kundsson',
        ],
        [
            'id' => 900002,
            'title' => 'Bokningshemlig',
            'booking_id' => 7,
            'note' => 'anteckning hemlig',
        ],
    ],
    'bookings' => [
        [
            'id' => 7,
            'reminder_id' => 900004,
            'customer_name' => 'Kalenderhemlig',
            'notes' => 'notering hemlig',
        ],
    ],
], JSON_UNESCAPED_UNICODE));
touch($storePath, time());
$store->pdo()->prepare('INSERT INTO purchases (reminder_id, payload, updated_at) VALUES (?, ?, ?)')->execute([
    900002,
    json_encode(['reminder_id' => 900002, 'booking_id' => 7, 'customer_name' => 'Bokningshemlig'], JSON_UNESCAPED_UNICODE),
    $now,
]);
$store->pdo()->prepare('INSERT INTO purchases (reminder_id, payload, updated_at) VALUES (?, ?, ?)')->execute([
    900003,
    json_encode(['reminder_id' => 900003, 'customer_name' => 'Orderhemlig', 'email' => 'order.hemlig@example.test'], JSON_UNESCAPED_UNICODE),
    $now,
]);
$purchaseCount = (int) $store->pdo()->query('SELECT COUNT(*) FROM purchases')->fetchColumn();
$denied = jeab_reminders_deleted_audit_dispatch($store, []);
stop_ok(($denied['ok'] ?? true) === false && (int) ($denied['status'] ?? 0) === 401, 'hook utan token läser inte ut id');
stop_ok(!isset($denied['missing']), 'nekad hook har ingen id-lista');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = 'tok-deleted-audit';
$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-deleted-audit']));
$filesBefore = stop_files($tmp);
$audit = jeab_reminders_deleted_audit_dispatch($store, ['action' => 'reminders.deleted_audit', 'dry' => 0]);
$encoded = json_encode($audit, JSON_UNESCAPED_UNICODE);
stop_ok(($audit['ok'] ?? false) === true && ($audit['read_only'] ?? false) === true && (int) ($audit['dry'] ?? 0) === 1, 'auditen är bara läsning');
stop_ok(($audit['driver'] ?? '') === 'sqlite' && (int) ($audit['live_count'] ?? 0) === 152, 'auditen räknar den levande tabellen');
stop_ok(($audit['backup_file'] ?? '') === 'store.json', 'nyaste kopian är store.json, inte den äldre kontakter.bak');
stop_ok(($audit['missing_backup'] ?? []) === [900001, 900002], 'kopian saknar två id i tabellen, fick ' . json_encode($audit['missing_backup'] ?? null));
stop_ok(($audit['missing_orders'] ?? []) === [900002, 900003], 'ordrar pekar på två saknade id, fick ' . json_encode($audit['missing_orders'] ?? null));
stop_ok(($audit['missing_bookings'] ?? []) === [900002, 900004], 'bokningar pekar på två saknade id, fick ' . json_encode($audit['missing_bookings'] ?? null));
$missing = $audit['missing'] ?? [];
stop_ok($missing === [900001, 900002, 900003, 900004], 'samlad lista är bara id, fick ' . json_encode($missing));
stop_ok(
    (int) ($audit['missing_count'] ?? -1) === 4
    && (int) ($audit['missing_backup_count'] ?? -1) === 2
    && (int) ($audit['missing_orders_count'] ?? -1) === 2
    && (int) ($audit['missing_bookings_count'] ?? -1) === 2,
    'antalen följer listorna'
);
stop_ok(!in_array(900777, $missing, true), 'äldre kopians id tas inte med');
stop_ok(
    is_string($encoded)
    && !str_contains($encoded, 'Hemlig')
    && !str_contains($encoded, 'hemlig')
    && !str_contains($encoded, '0700000001')
    && !str_contains($encoded, 'anteckning')
    && !str_contains($encoded, 'Kalender')
    && !str_contains($encoded, '@'),
    'svaret har ingen kundtext'
);
stop_ok(stop_ids($store) === $beforeIds, 'auditen raderar inga påminnelser');
stop_ok((int) $store->pdo()->query('SELECT COUNT(*) FROM purchases')->fetchColumn() === $purchaseCount, 'auditen skriver inga ordrar');
stop_ok(stop_files($tmp) === $filesBefore, 'auditen skriver ingen fil');
unset($_SERVER['HTTP_X_WEBHOOK_TOKEN']);

echo $fail === 0 ? "OK  reminders prune stop\n" : "\n{$fail} failed\n";
exit($fail === 0 ? 0 : 1);
