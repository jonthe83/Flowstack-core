<?php

declare(strict_types=1);

/**
 * contacts.merge_runs, ordrar.get fields:1 och rows.keys är skrivskyddade.
 * Svaret har id, nyckelnamn och tider. Inga värden och inga personuppgifter.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-readonly-keys-' . bin2hex(random_bytes(4));
mkdir($tmp . '/.private/backups', 0775, true);
mkdir($tmp . '/.private/trash/2026-11-01', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('CONTACTS_SOLE=1');
putenv('DATA_BACKEND');
$_ENV['CONTACTS_SOLE'] = '1';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/ordrar_restore_state.php';
require_once dirname(__DIR__) . '/src/hook_readonly_keys.php';

function rk(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
$src = (string) file_get_contents(dirname(__DIR__) . '/src/hook_readonly_keys.php');
rk(str_contains($hook, "'contacts.merge_runs'"), 'hook tar contacts.merge_runs');
rk(str_contains($hook, "'rows.keys'"), 'hook tar rows.keys');
rk(str_contains($hook, "'ordrar.get'"), 'hook tar ordrar.get');
rk(preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE)\b/', $src) !== 1, 'kroken har inga skriv-SQL');
rk(!str_contains($src, 'file_put_contents') && !str_contains($src, 'unlink('), 'kroken skriver inga filer');

$store = jeab_store();
rk($store instanceof JeabPdoStore, 'lagret är öppet');
$pdo = $store->pdo();
jeab_purchases_store_ensure($pdo);
jeab_ensure_contacts_tables($pdo, 'sqlite');
jeab_ensure_contacts_merge_undo_table($pdo, 'sqlite');
jeab_ensure_inkorg_tables($pdo, 'sqlite');

$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-readonly-keys']));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = '';
$denied = jeab_hook_readonly_dispatch(['action' => 'contacts.merge_runs']);
rk(empty($denied['ok']) && (int) ($denied['status'] ?? 0) === 401 && (int) ($denied['read_only'] ?? 0) === 1, 'utan token ger 401');
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = 'tok-readonly-keys';

$secret = 'Ada Hemlig secret-merge@example.test 0700111222';
$payloadOld = json_encode([
    'contact_id' => 'kt_dup',
    'step' => 4,
    'rows' => [['article' => 'BOX']],
    'install_date' => '2026-11-02',
    'kalkyl' => ['sum' => 10],
    'name' => 'Ada Hemlig',
    'email' => 'secret-merge@example.test',
], JSON_UNESCAPED_UNICODE);
$payloadNew = json_encode([
    'contact_id' => 'kt_master',
    'step' => 4,
    'rows' => [['article' => 'BOX']],
    'install_date' => '2026-11-02',
    'kalkyl' => ['sum' => 10],
    'name' => 'Ada Hemlig',
    'email' => 'secret-merge@example.test',
], JSON_UNESCAPED_UNICODE);
$now = '2026-11-01 12:00:00';
$pdo->prepare('INSERT INTO contacts (id, row_json, company, created_at, updated_at) VALUES (?, ?, ?, ?, ?)')
    ->execute(['kt_master', '{"name":"Ada Hemlig"}', 'malmo', $now, $now]);
$pdo->prepare('INSERT INTO contacts (id, row_json, company, created_at, updated_at) VALUES (?, ?, ?, ?, ?)')
    ->execute(['kt_jar', '{"name":"Bo Hemlig"}', 'jarfalla', $now, $now]);
$log = $pdo->prepare('INSERT INTO contacts_merge_undo (undo_id, created_at, kind, tbl, row_key, col, old_value, new_value, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
$log->execute(['aaaaaaaaaaaaaaaa', '2026-11-01 15:51:00', 'link', 'purchases', '319', 'payload', $payloadOld, $payloadNew, 'done']);
$log->execute(['aaaaaaaaaaaaaaaa', '2026-11-01 15:51:01', 'link', 'contact_emails', 'ada.hemlig@example.test', 'contact_id', 'kt_dup', 'kt_master', 'done']);
$log->execute(['aaaaaaaaaaaaaaaa', '2026-11-01 15:51:02', 'contact_delete', 'contacts', 'kt_dup', 'row_json', $secret, '', 'done']);
$log->execute(['bbbbbbbbbbbbbbbb', '2026-11-01 16:33:00', 'contact_update', 'contacts', 'kt_jar', 'street', 'Gamla gatan 1', 'Nya gatan 1', 'done']);

$runs = jeab_hook_readonly_dispatch(['action' => 'contacts.merge_runs']);
rk(!empty($runs['ok']) && count($runs['runs'] ?? []) === 2, 'båda körningarna listas');
$encoded = json_encode($runs, JSON_UNESCAPED_UNICODE);
rk(is_string($encoded) && !str_contains($encoded, 'Ada') && !str_contains($encoded, 'secret-merge') && !str_contains($encoded, '0700') && !str_contains($encoded, 'Gamla') && !str_contains($encoded, 'ada.hemlig'), 'merge_runs läcker inga värden');
$first = $runs['runs'][0];
rk(($first['undo_id'] ?? '') === 'aaaaaaaaaaaaaaaa' && str_starts_with((string) ($first['at'] ?? ''), '2026-11-01 15:51'), 'första körningen har tid');
$byKind = [];
foreach ($first['rows'] as $row) {
    $byKind[$row['kind'] . ':' . $row['tbl']] = $row;
}
$link = $byKind['link:purchases'] ?? [];
rk(($link['col'] ?? '') === 'payload' && ($link['row_key'] ?? '') === '319' && ($link['master_id'] ?? '') === 'kt_master' && ($link['dup_id'] ?? '') === 'kt_dup', 'payload-raden har master och dup utan värden');
$emailRow = $byKind['link:contact_emails'] ?? [];
rk(($emailRow['row_key'] ?? 'x') === '' && ($emailRow['master_id'] ?? '') === 'kt_master' && ($emailRow['dup_id'] ?? '') === 'kt_dup', 'mejl som radnyckel lämnas ute');
$deleted = $byKind['contact_delete:contacts'] ?? [];
rk(($deleted['dup_id'] ?? '') === 'kt_dup' && ($deleted['master_id'] ?? '') === 'kt_master' && ($deleted['col'] ?? '') === 'row_json', 'raderingen får master från länk-raden');

$malmo = jeab_hook_readonly_dispatch(['action' => 'contacts.merge_runs', 'co' => 'meab']);
rk(!empty($malmo['ok']) && ($malmo['co'] ?? '') === 'malmo' && count($malmo['runs'] ?? []) === 1 && ($malmo['runs'][0]['undo_id'] ?? '') === 'aaaaaaaaaaaaaaaa', 'co meab håller Malmö-körningen');
$badCo = jeab_hook_readonly_dispatch(['action' => 'contacts.merge_runs', 'co' => 'zz']);
rk(empty($badCo['ok']) && (int) ($badCo['status'] ?? 0) === 422, 'ogiltigt bolag är 422');

$undoBefore = (int) $pdo->query('SELECT COUNT(*) FROM contacts_merge_undo')->fetchColumn();
jeab_hook_readonly_dispatch(['action' => 'contacts.merge_runs']);
rk((int) $pdo->query('SELECT COUNT(*) FROM contacts_merge_undo')->fetchColumn() === $undoBefore, 'merge_runs skriver inte i loggen');

jeab_sql_purchases_put($pdo, [
    'reminder_id' => 319,
    'contact_id' => 'kt_319',
    'company' => 'malmo',
    'step' => 1,
    'status' => 'open',
    'rows' => [],
    'install_date' => '',
    'kalkyl' => [],
    'email' => 'secret-live@example.test',
    'note' => 'PAYLOADSECRET',
    'created_at' => '2026-11-01 12:00:00',
    'updated_at' => '2026-11-01 18:00:00',
]);
$plain = jeab_ordrar_state_sources_dispatch($store, ['action' => 'ordrar.get', 'id' => 319]);
rk(!empty($plain['ok']) && !array_key_exists('keys', $plain) && !array_key_exists('contact_id', $plain), 'ordrar.get utan fields lämnar nyckellistan');
$fields = jeab_ordrar_state_sources_dispatch($store, ['action' => 'ordrar.get', 'id' => 319, 'fields' => 1]);
$fieldJson = json_encode($fields, JSON_UNESCAPED_UNICODE);
$fieldKeys = is_array($fields['keys'] ?? null) ? $fields['keys'] : [];
rk(!empty($fields['ok']) && ($fields['contact_id'] ?? '') === 'kt_319', 'fields:1 ger contact_id');
foreach (['step', 'rows', 'install_date', 'kalkyl'] as $key) {
    rk(in_array($key, $fieldKeys, true), 'fields:1 har nyckeln ' . $key);
}
rk(is_string($fieldJson) && !str_contains($fieldJson, 'PAYLOADSECRET') && !str_contains($fieldJson, 'secret-live'), 'fields:1 läcker inte payload-värden');

$tz = new DateTimeZone('Europe/Stockholm');
$touch = static function (string $path, string $local) use ($tz): void {
    $ts = (new DateTimeImmutable($local, $tz))->getTimestamp();
    touch($path, $ts);
};
$priorDoc = static function (int $id, array $extra): string {
    return json_encode([
        'purchases' => [
            (string) $id => array_merge([
                'reminder_id' => $id,
                'contact_id' => 'kt_319',
                'company' => 'malmo',
                'step' => 4,
                'rows' => [['article' => 'BOX']],
                'install_date' => '2026-11-02',
                'kalkyl' => ['sum' => 99],
                'name' => 'Hemlig Kalla',
                'email' => 'secret-prior@example.test',
            ], $extra),
        ],
    ], JSON_UNESCAPED_UNICODE);
};
$backup = $tmp . '/.private/backups/order-319.json';
$trash = $tmp . '/.private/trash/2026-11-01/order-319.json';
$later = $tmp . '/.private/trash/2026-11-01/later-319.json';
file_put_contents($backup, $priorDoc(319, ['backup_key' => 1]));
file_put_contents($trash, $priorDoc(319, ['trash_key' => 1]));
file_put_contents($later, $priorDoc(319, ['future_key' => 1]));
$touch($backup, '2026-11-01 15:00:00');
$touch($trash, '2026-11-01 16:00:00');
$touch($later, '2026-11-01 17:00:00');
$undoPdo = jeab_contacts_sole_undo_pdo($store);
rk($undoPdo instanceof PDO, 'ångerloggen går att öppna');
$snap = [
    'orders_rows' => [
        '319' => [
            'reminder_id' => 319,
            'step' => 4,
            'rows' => [],
            'install_date' => '2026-11-02',
            'kalkyl' => ['sum' => 1],
            'snapshot_key' => 1,
            'email' => 'secret-prior@example.test',
        ],
    ],
    'reminders' => [[
        'id' => 7,
        'company' => 'malmo',
        'title' => 'Hemlig titel',
        'extra_old' => 1,
        'email' => 'secret-rem@example.test',
    ]],
    'bookings' => [[
        'id' => 3,
        'company' => 'malmo',
        'note_old' => 'secret-book@example.test',
    ]],
    'threads' => [[
        'id' => 'th_keys',
        'company' => 'malmo',
        'subject' => 'Hemlig trad',
        'kalkyl' => 1,
    ]],
];
$undoPdo->prepare('INSERT INTO contacts_sole_undo (batch_id, seq, kind, entity, entity_id, before_json, after_json, created_at, undone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)')
    ->execute(['batchkeys', 1, 'snapshot', 'census', 'batchkeys', json_encode($snap, JSON_UNESCAPED_UNICODE), '{}', '2026-11-01 16:20:00']);

$mid = jeab_hook_readonly_dispatch([
    'action' => 'rows.keys',
    'tbl' => 'purchases',
    'id' => 319,
    'co' => 'malmo',
    'before' => '2026-11-01T16:10:00+01:00',
]);
$midJson = json_encode($mid, JSON_UNESCAPED_UNICODE);
rk(!empty($mid['ok']) && ($mid['prior']['source'] ?? '') === 'trash' && ($mid['prior']['at'] ?? '') === '2026-11-01 16:00:00', 'senaste före 16:10 är trash 16:00');
$midKeys = is_array($mid['prior']['keys'] ?? null) ? $mid['prior']['keys'] : [];
rk(in_array('trash_key', $midKeys, true) && in_array('kalkyl', $midKeys, true) && in_array('rows', $midKeys, true) && !in_array('future_key', $midKeys, true) && !in_array('snapshot_key', $midKeys, true), 'prior-nycklar är trash-versionen');
$nowKeys = is_array($mid['keys'] ?? null) ? $mid['keys'] : [];
rk(in_array('step', $nowKeys, true) && !in_array('trash_key', $nowKeys, true), 'nuvarande nycklar kommer från raden');
rk(is_string($midJson) && !str_contains($midJson, 'Hemlig') && !str_contains($midJson, 'secret-prior') && !str_contains($midJson, 'BOX'), 'rows.keys läcker inga värden');

$late = jeab_hook_readonly_dispatch([
    'action' => 'rows.keys',
    'tbl' => 'purchases',
    'id' => '319',
    'before' => '2026-11-01T16:30:00+01:00',
]);
rk(($late['prior']['source'] ?? '') === 'snapshot' && in_array('snapshot_key', $late['prior']['keys'] ?? [], true) && !in_array('future_key', $late['prior']['keys'] ?? [], true), '16:30 tar ögonblicksbilden 16:20');
$none = jeab_hook_readonly_dispatch([
    'action' => 'rows.keys',
    'tbl' => 'purchases',
    'id' => 319,
    'before' => '2026-11-01T14:00:00+01:00',
]);
rk(!empty($none['ok']) && $none['prior'] === null, 'ingen version före 14:00');
$other = jeab_hook_readonly_dispatch(['action' => 'rows.keys', 'tbl' => 'purchases', 'id' => 319, 'co' => 'jarfalla']);
rk(empty($other['ok']) && (int) ($other['status'] ?? 0) === 404, 'fel bolag är 404');
$badBefore = jeab_hook_readonly_dispatch(['action' => 'rows.keys', 'tbl' => 'purchases', 'id' => 319, 'before' => 'igår']);
rk(empty($badBefore['ok']) && (int) ($badBefore['status'] ?? 0) === 422, 'trasig before är 422');

$pdo->prepare('INSERT INTO reminders (id, list_id, title, company, contact_id, note, created_at, updated_at) VALUES (?, 1, ?, ?, ?, ?, ?, ?)')
    ->execute([7, 'Hemlig titel', 'malmo', 'kt_319', 'secret-rem@example.test', '2026-11-01 12:00:00', '2026-11-01 12:00:00']);
$reminder = jeab_hook_readonly_dispatch([
    'action' => 'rows.keys',
    'tbl' => 'reminders',
    'id' => 7,
    'before' => '2026-11-01T16:30:00+01:00',
]);
$reminderJson = json_encode($reminder, JSON_UNESCAPED_UNICODE);
rk(!empty($reminder['ok']) && in_array('title', $reminder['keys'] ?? [], true) && in_array('extra_old', $reminder['prior']['keys'] ?? [], true), 'påminnelsen har nuvarande och tidigare nycklar');
rk(is_string($reminderJson) && !str_contains($reminderJson, 'Hemlig') && !str_contains($reminderJson, 'secret-rem'), 'påminnelsen läcker inte värden');

$customer = $store->insertCustomer('Bok Hemlig', '2026-11-01 12:00:00');
$booking = $store->insertBooking([
    'customer_id' => $customer['id'],
    'customer_name' => 'Bok Hemlig',
    'email' => 'secret-book@example.test',
    'phone' => '0700999888',
    'assignees' => ['Kim'],
    'company' => 'malmo',
    'job_type' => 'laddbox',
    'starts_at' => '2026-11-02 08:00:00',
    'ends_at' => '2026-11-02 12:00:00',
    'status' => 'booked',
    'contact_id' => 'kt_319',
    'created_at' => '2026-11-01 12:00:00',
    'updated_at' => '2026-11-01 12:00:00',
]);
$bookingId = (int) ($booking['id'] ?? 0);
rk($bookingId > 0, 'bokningen finns');
$bookFile = $tmp . '/.private/backups/bookings.json';
file_put_contents($bookFile, json_encode([
    'bookings' => [[
        'id' => $bookingId,
        'company' => 'malmo',
        'customer_name' => 'Bok Hemlig',
        'note_old' => 'secret-book@example.test',
    ]],
], JSON_UNESCAPED_UNICODE));
$touch($bookFile, '2026-11-01 15:40:00');
$bookingKeys = jeab_hook_readonly_dispatch([
    'action' => 'rows.keys',
    'tbl' => 'bookings',
    'id' => $bookingId,
    'before' => '2026-11-01T16:00:00+01:00',
]);
$bookingJson = json_encode($bookingKeys, JSON_UNESCAPED_UNICODE);
rk(!empty($bookingKeys['ok']) && ($bookingKeys['prior']['source'] ?? '') === 'backup' && in_array('note_old', $bookingKeys['prior']['keys'] ?? [], true), 'bokningen hittar backup-nycklar');
rk(is_string($bookingJson) && !str_contains($bookingJson, 'Hemlig') && !str_contains($bookingJson, 'secret-book') && !str_contains($bookingJson, '0700'), 'bokningen läcker inte värden');

$pdo->prepare('INSERT INTO inkorg_threads (id, status, updated_at, row_json) VALUES (?, ?, ?, ?)')
    ->execute(['th_keys', 'open', '2026-11-01 12:00:00', json_encode([
        'status' => 'open',
        'company' => 'malmo',
        'subject' => 'Hemlig trad',
    ], JSON_UNESCAPED_UNICODE)]);
$thread = jeab_hook_readonly_dispatch([
    'action' => 'rows.keys',
    'tbl' => 'inbox_threads',
    'id' => 'th_keys',
    'before' => '2026-11-01T16:30:00+01:00',
]);
$threadJson = json_encode($thread, JSON_UNESCAPED_UNICODE);
rk(!empty($thread['ok']) && in_array('status', $thread['keys'] ?? [], true) && in_array('kalkyl', $thread['prior']['keys'] ?? [], true) && ($thread['prior']['source'] ?? '') === 'snapshot', 'tråden har nuvarande och snapshot-nycklar');
rk(is_string($threadJson) && !str_contains($threadJson, 'Hemlig'), 'tråden läcker inte ämnet');

$purchasesBefore = (int) $pdo->query('SELECT COUNT(*) FROM purchases')->fetchColumn();
jeab_hook_readonly_dispatch(['action' => 'rows.keys', 'tbl' => 'purchases', 'id' => 319, 'before' => '2026-11-01T16:30:00+01:00']);
jeab_ordrar_state_sources_dispatch($store, ['action' => 'ordrar.get', 'id' => 319, 'fields' => 1]);
rk((int) $pdo->query('SELECT COUNT(*) FROM purchases')->fetchColumn() === $purchasesBefore, 'nyckelkrokarna skriver inte ordrar');

echo "OK  klart\n";
