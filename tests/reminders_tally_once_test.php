<?php

declare(strict_types=1);

/**
 * Flikrutan räknar personfiltret en gång per rad, inte en gång per flik.
 * Idag/schemalagt/väntar sorterar på due_at utan CASE.
 * Namnuppslaget faller tillbaka på en fråga, inte en per kontakt.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-tally-once-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_REMINDER_COUNT_TTL'] = 0;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function once(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
once($store instanceof JeabPdoStore, 'sqlite-lagret finns');
$pdo = $store->pdo();
jeab_ensure_contact_id_columns($pdo, 'sqlite');

$todayOrder = jeab_reminder_page_order_sql('today');
$scheduledOrder = jeab_reminder_page_order_sql('scheduled');
$waitingOrder = jeab_reminder_page_order_sql('waiting');
once(!str_contains($todayOrder, 'CASE') && str_contains($todayOrder, 'due_at ASC'), 'idag sorterar på due_at');
once(!str_contains($scheduledOrder, 'CASE') && !str_contains($waitingOrder, 'CASE'), 'schemalagt och väntar sorterar utan CASE');
once(str_contains(jeab_reminder_page_order_sql('all'), 'CASE'), 'alla behåller den blandade ordningen');
once(str_contains(jeab_reminder_page_order_sql('done'), 'completed_at DESC'), 'klara sorterar på avslut');

$now = jeab_now();
$store->insertReminder([
    'title' => 'Idag',
    'customer_name' => 'Idag',
    'company' => 'jarfalla',
    'person' => 'Tally Person',
    'due_at' => '2020-01-01 08:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$store->insertReminder([
    'title' => 'Senare',
    'customer_name' => 'Senare',
    'company' => 'jarfalla',
    'person' => 'Någon Annan',
    'due_at' => '2099-01-01 08:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);

$pack = jeab_reminders_sql_tally($store, 'jarfalla', 'Tally Person', 'today', ['mode' => 'all'], '2026-10-31', '2026-10-31 12:00:00', false);
$sql = (string) ($GLOBALS['_JEAB_REMINDER_TALLY_LAST_SQL'] ?? '');
[$whoSql] = jeab_reminder_assignee_sql($pdo, 'Tally Person', 'jarfalla');
$whoHits = substr_count($whoSql, 'json_each');
once($whoHits > 0 && substr_count($sql, 'json_each') === $whoHits, 'personfiltret körs en gång, inte en gång per flik');
once(substr_count($sql, 'due_at <= ?') === 1, 'idag-predikatet finns en gång');
once((int) ($pack['counts']['today'] ?? 0) === 1, 'en förfallen rad räknas som idag');
once((int) ($pack['counts']['waiting'] ?? 0) === 0, 'den andra personen räknas inte i personens väntar');
once((int) ($pack['counts']['all'] ?? -1) === 1, 'alla i personfiltret är en rad');

$ids = ['namn-a', 'namn-b', 'namn-c'];
foreach ($ids as $i => $id) {
    $row = $store->insertReminder([
        'title' => 'Namn ' . $id,
        'customer_name' => 'Visas ' . $id,
        'email' => $id . '@example.test',
        'company' => 'jarfalla',
        'due_at' => '2026-10-31 09:00:00',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $pdo->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute([$id, (int) $row['id']]);
    if ($i === 0) {
        $extra = $store->insertReminder([
            'title' => 'Extra',
            'customer_name' => 'Extra ' . $id,
            'company' => 'jarfalla',
            'due_at' => '2026-10-31 10:00:00',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $pdo->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute([$id, (int) $extra['id']]);
    }
}
$GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES'] = [];
foreach ($ids as $id) {
    $GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES'][$id] = ['names' => [], 'emails' => []];
}
$GLOBALS['_JEAB_CONTACTS_SOLE_REMINDER_NAMES_NO_WINDOW'] = true;
$before = (int) ($GLOBALS['_JEAB_CONTACTS_SOLE_REMINDER_NAME_SQL'] ?? 0);
jeab_contacts_sole_linked_names_reminders($ids);
$delta = (int) ($GLOBALS['_JEAB_CONTACTS_SOLE_REMINDER_NAME_SQL'] ?? 0) - $before;
once($delta === 1, 'namnuppslaget är en fråga för tre kontakter, delta ' . $delta);
$packA = $GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES']['namn-a'] ?? [];
$namesA = is_array($packA['names'] ?? null) ? $packA['names'] : [];
once(in_array('Visas namn-a', $namesA, true) && in_array('Extra namn-a', $namesA, true), 'båda namnen för första kontakten följde med');

echo "OK  reminders tally\n";
