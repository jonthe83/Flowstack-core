<?php

declare(strict_types=1);

/**
 * Stängt kort ritar inte redigeraren. Sync hoppar över kort med samma stämpel.
 * n= ritar högst en sida. Who och CSRF memoseras.
 */

$tmp = sys_get_temp_dir() . '/jeab-closed-draw-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
file_put_contents($tmp . '/passwords.json', json_encode([
    'secret' => bin2hex(random_bytes(16)),
    'hashes' => [['email' => 'demo@example.test', 'hash' => password_hash('SamLdraw', PASSWORD_DEFAULT)]],
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'passwords_file' => $tmp . '/passwords.json',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
$_GET = ['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'today', 'who' => 'all', 'ws' => (string) time()];
$_SERVER['REQUEST_METHOD'] = 'GET';
require dirname(__DIR__) . '/src/bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$fail = 0;
function cd(bool $ok, string $msg): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $fail++;
    }
}

$row = [
    'id' => 7,
    'title' => 'Testgatan 1',
    'address' => 'Testgatan 1',
    'customer_name' => 'Ada',
    'phone' => '0701112233',
    'email' => 'ada@example.test',
    'due_at' => '2026-10-22 09:00:00',
    'company' => 'jarfalla',
    'assignees' => ['Elin'],
    'tags' => ['admin'],
    'note' => 'En rad',
    'created_at' => '2026-10-01 08:00:00',
    'updated_at' => '2026-10-02 08:00:00',
    'thread_id' => 'thr-1',
    'contact_id' => 'c-1',
];
$shut = jeab_reminder_row($row, false);
$open = jeab_reminder_row($row, true);
cd(str_contains($shut, 'Testgatan 1') && str_contains($shut, 'Ada') && str_contains($shut, 'class="check-form"'), 'stängt kort har adress, namn och bock');
cd(str_contains($shut, 'class="item-due-chip"'), 'stängt kort har förfallo');
cd(!str_contains($shut, 'item-head-open') && !str_contains($shut, 'data-js="snooze"') && !str_contains($shut, 'data-js="tap"') && !str_contains($shut, 'item-who-form') && !str_contains($shut, 'name="address"'), 'stängt kort ritar inte redigeraren');
cd(str_contains($open, 'item-head-open') && str_contains($open, 'data-js="snooze"') && str_contains($open, 'data-js="inline"') && str_contains($open, 'contact-edit-form'), 'öppet kort har redigerare, knuff och kontakt');
cd(substr_count($shut, 'name="_csrf"') === 1, 'stängt kort har ett formulär');
cd(substr_count($open, 'name="_csrf"') > 3, 'öppet kort har formulären');

$stamp = jeab_reminder_row_stamp($row);
cd(preg_match('/\A[0-9a-f]{16}\z/', $stamp) === 1 && str_contains($shut, 'data-sync-stamp="' . $stamp . '"'), 'stämpeln sitter på raden');
$_GET['rst'] = '7.' . $stamp;
$same = jeab_reminder_sync_payload([$row], ['today' => 1], 1, null);
cd(!isset($same['items'][0]['html']) && ($same['items'][0]['stamp'] ?? '') === $stamp, 'sync ritar inte om ett kort med samma stämpel');
$_GET['rst'] = '7.' . str_repeat('a', 16);
$changed = jeab_reminder_sync_payload([$row], ['today' => 1], 1, null);
cd(isset($changed['items'][0]['html']) && str_contains((string) $changed['items'][0]['html'], 'Ada') && !str_contains((string) $changed['items'][0]['html'], 'data-js="snooze"'), 'ändrad stämpel ritar den stängda raden');
unset($_GET['rst']);

$many = [];
for ($i = 1; $i <= 60; $i++) {
    $many[] = $row;
    $many[$i - 1]['id'] = $i;
}
$payload = jeab_reminder_sync_payload($many, ['today' => 60], 60, 50);
$htmlN = 0;
foreach ($payload['items'] as $item) {
    if (isset($item['html'])) {
        $htmlN++;
    }
}
cd(count($payload['items']) === 60 && $htmlN === 50, 'sync utan kända stämplar ritar högst 50, fick ' . $htmlN);
cd(jeab_reminder_draw_limit(148, '') === 50 && jeab_reminder_draw_limit(148, 'sync') === 148 && jeab_reminder_draw_limit(400, 'sync') === 200, 'n= klipps, sync-fönstret är större men begränsat');

$t0 = hrtime(true);
for ($i = 0; $i < 34; $i++) {
    jeab_reminder_row($row, false);
}
$ms = (hrtime(true) - $t0) / 1e6;
cd($ms < 1000, '34 stängda kort under 1 s, fick ' . round($ms, 1) . ' ms');

$who1 = jeab_reminder_who_filter();
$tWho = hrtime(true);
for ($i = 0; $i < 40; $i++) {
    jeab_reminders_url(['open' => $i + 1]);
}
$whoMs = (hrtime(true) - $tWho) / 1e6;
cd(jeab_reminder_who_filter() === $who1 && $whoMs < 1000, '40 länkar återanvänder who, fick ' . round($whoMs, 1) . ' ms');
$csrfA = jeab_csrf_token();
$csrfB = jeab_csrf_token();
cd($csrfA !== '' && $csrfA === $csrfB, 'CSRF-token memoseras');

$store = jeab_store();
cd($store instanceof JeabPdoStore, 'sqlite');
if ($store instanceof JeabPdoStore) {
    $now = jeab_now();
    for ($i = 0; $i < 30; $i++) {
        $store->insertReminder([
            'title' => 'Gata ' . $i,
            'customer_name' => 'Kund ' . $i,
            'company' => 'jarfalla',
            'assignees' => [$i % 2 === 0 ? 'Elin' : 'Roberto'],
            'due_at' => '2026-10-01 09:00:00',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
    jeab_reminders_count_cache_forget($store->pdo());
    $GLOBALS['_JEAB_REMINDER_TALLY_SQL'] = 0;
    $tag = ['mode' => 'all', 'tag' => null];
    $a = jeab_reminders_sql_tally($store, 'jarfalla', '', 'today', $tag, '2026-10-29', '2026-10-29 12:00:00', true);
    $b = jeab_reminders_sql_tally($store, 'jarfalla', '', 'waiting', $tag, '2026-10-29', '2026-10-29 12:00:00', true);
    cd((int) ($GLOBALS['_JEAB_REMINDER_TALLY_SQL'] ?? 0) === 1, 'filterbyte läser inte om tally');
    cd(($a['counts']['today'] ?? 0) === ($b['counts']['today'] ?? 0) && ($a['counts']['today'] ?? 0) > 0, 'samma räkning i båda vyerna');
    cd(isset($a['who']['all'], $b['who']['all']), 'who-räkningen finns');
}

echo $fail > 0 ? "FAIL {$fail}\n" : "ALLA OK\n";
exit($fail > 0 ? 1 : 0);
