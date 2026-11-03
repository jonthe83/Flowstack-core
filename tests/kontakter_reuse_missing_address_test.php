<?php

declare(strict_types=1);

/**
 * Saknad adress är ingen konflikt: samma e-post eller telefon återanvänds.
 * Torr körning skriver ingen hoppad hopslagning.
 * kontakter.prune_orphan tar bara bort kontakter utan länk och skriver en ångerbatch.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-reuse-missing-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
mkdir($tmp . '/inkorg', 0775, true);
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

function ru(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function ru_log(): string
{
    $path = jeab_app_errors_path();

    return is_file($path) ? (string) file_get_contents($path) : '';
}

$secretPnr = '199001011234';
$store = jeab_store();
ru($store instanceof JeabPdoStore, 'sqlite-lagret finns');
jeab_ensure_contacts_tables($store->pdo(), 'sqlite');
ru(function_exists('jeab_purchases_sql_set_storage') && jeab_purchases_sql_set_storage('sql'), 'ordrar ligger i sql');

$withAddress = jeab_kontakt_find_or_create([
    'name' => 'Ada Finns',
    'email' => 'ada.finns@example.com',
    'phone' => '0701000501',
    'address' => 'Finnesgatan 1',
    'postal_code' => '11122',
    'city' => 'Teststad',
], false, true);
$again = jeab_kontakt_find_or_create([
    'name' => 'Ada Finns',
    'email' => 'ada.finns@example.com',
    'phone' => '0701000501',
], false, true);
ru(is_array($withAddress) && is_array($again) && (string) $withAddress['id'] === (string) $again['id'], 'saknad adress på den nya raden återanvänder kontakten');
ru((string) (jeab_kontakt_get((string) $withAddress['id'])['street'] ?? '') === 'Finnesgatan 1', 'den sparade gatan ligger kvar');

$bare = jeab_kontakt_find_or_create([
    'name' => 'Bo Bar',
    'email' => 'bo.bar@example.com',
    'phone' => '0701000502',
], false, true);
$filled = jeab_kontakt_find_or_create([
    'name' => 'Bo',
    'email' => 'bo.annan@example.com',
    'phone' => '0701000502',
    'address' => 'Barvagen 4',
    'postal_code' => '33344',
    'city' => 'Teststad',
], false, true);
ru(is_array($bare) && is_array($filled) && (string) $bare['id'] === (string) $filled['id'], 'saknad adress på den gamla raden återanvänder telefonen');

$left = jeab_kontakt_find_or_create([
    'name' => 'Cia Olika',
    'email' => 'cia.olika@example.com',
    'phone' => '0701000503',
    'address' => 'Vanstergatan 2',
    'postal_code' => '11122',
    'city' => 'Teststad',
], false, true);
$right = jeab_kontakt_find_or_create([
    'name' => 'Cia Olika',
    'email' => 'cia.olika@example.com',
    'phone' => '0701000503',
    'address' => 'Hogergranden 9',
    'postal_code' => '22233',
    'city' => 'Annanort',
], false, true);
ru(is_array($left) && is_array($right) && (string) $left['id'] !== (string) $right['id'], 'båda adresserna satta och olika blir två kontakter');

$plan = jeab_kontakt_dedupe_plan(jeab_kontakter_load(), $store);
$bad = false;
foreach ($plan['merges'] as $merge) {
    $pair = [(string) ($merge['from'] ?? ''), (string) ($merge['to'] ?? '')];
    sort($pair);
    $blocked = [(string) $left['id'], (string) $right['id']];
    sort($blocked);
    if ($pair === $blocked) {
        $bad = true;
    }
}
ru($bad === false, 'dedupe-planen tar inte med två satta adresser som skiljer sig');
$finished = jeab_kontakt_dedupe_finish([
    ['from' => (string) $right['id'], 'to' => (string) $left['id'], 'rule' => 'email', 'reason' => 'samma e-post'],
], $store);
ru($finished === 0 && isset(jeab_kontakter_load()[(string) $left['id']], jeab_kontakter_load()[(string) $right['id']]), 'dedupe_finish hoppar över två satta adresser som skiljer sig');

$beforeDry = ru_log();
$dryDedupe = jeab_kontakter_dedupe(['dry' => 1], $store);
ru(!empty($dryDedupe['ok']) && !empty($dryDedupe['dry']) && ru_log() === $beforeDry, 'torr dedupe skriver ingen hoppad hopslagning');
$seen = jeab_kontakter_load();
$outcome = jeab_contacts_sole_property_outcome([
    'name' => 'Cia Olika',
    'email' => 'cia.olika@example.com',
    'phone' => '0701000503',
    'street' => 'Tredjegranden 8',
    'postal_code' => '99988',
    'city' => 'Tredjeort',
], $seen);
ru($outcome !== '' && ru_log() === $beforeDry, 'förhandsbeskedet skriver ingen hoppad hopslagning');
$guard = jeab_kontakt_merge_skip_guard(true);
jeab_kontakt_find(
    jeab_kontakter_load(),
    jeab_kontakt_street_key('Hogergranden 9'),
    '22233',
    '',
    '0701000503',
    false,
    'Cia Olika',
    'Annanort'
);
jeab_kontakt_merge_skip_log('email', (string) $left['id'], (string) $right['id'], 'olika adress');
unset($guard);
ru(ru_log() === $beforeDry, 'find i torr väg skriver ingenting');
jeab_kontakt_merge_skip_log('email', (string) $left['id'], (string) $right['id'], 'olika adress');
$wetLog = ru_log();
ru(str_contains($wetLog, '"level":"info"') && str_contains($wetLog, 'olika adress') && !str_contains($wetLog, $secretPnr) && !str_contains(strtolower($wetLog), 'personnummer'), 'en riktig konflikt skrivs som info utan personnummer');

$orphan = jeab_kontakt_find_or_create([
    'name' => 'Orphan Ren',
    'email' => 'orphan.ren@example.com',
    'phone' => '0701000504',
    'address' => 'Ensamvagen 1',
    'postal_code' => '44455',
    'city' => 'Teststad',
    'personnummer' => $secretPnr,
], false, true);
$reminded = jeab_kontakt_find_or_create([
    'name' => 'Pam Pamin',
    'email' => 'pam.pamin@example.com',
    'phone' => '0701000505',
    'address' => 'Pamgatan 2',
    'postal_code' => '55566',
    'city' => 'Teststad',
], false, true);
$ordered = jeab_kontakt_find_or_create([
    'name' => 'Order Orvar',
    'email' => 'order.orvar@example.com',
    'phone' => '0701000506',
    'address' => 'Ordergatan 3',
    'postal_code' => '55566',
    'city' => 'Teststad',
], false, true);
$booked = jeab_kontakt_find_or_create([
    'name' => 'Bok Beata',
    'email' => 'bok.beata@example.com',
    'phone' => '0701000507',
    'address' => 'Bokgatan 4',
    'postal_code' => '55566',
    'city' => 'Teststad',
], false, true);
$inboxed = jeab_kontakt_find_or_create([
    'name' => 'Ink Inga',
    'email' => 'ink.inga@example.com',
    'phone' => '0701000508',
    'address' => 'Inkorgsgatan 5',
    'postal_code' => '55566',
    'city' => 'Teststad',
], false, true);
ru(is_array($orphan) && is_array($reminded) && is_array($ordered) && is_array($booked) && is_array($inboxed), 'prune-kontakterna skapas');
$now = jeab_now();
$reminder = $store->insertReminder([
    'title' => 'Pam',
    'customer_name' => 'Pam Pamin',
    'email' => 'pam.pamin@example.com',
    'phone' => '0701000505',
    'address' => 'Pamgatan 2',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 09:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$store->pdo()->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute([(string) $reminded['id'], (int) $reminder['id']]);
$orderReminder = $store->insertReminder([
    'title' => 'Orvar',
    'customer_name' => 'Order Orvar',
    'email' => 'order.orvar@example.com',
    'phone' => '0701000506',
    'address' => 'Ordergatan 3',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 10:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
jeab_purchase_put([
    'reminder_id' => (int) $orderReminder['id'],
    'kind' => 'laddbox',
    'customer_name' => 'Order Orvar',
    'contact_id' => (string) $ordered['id'],
    'address' => 'Ordergatan 3',
    'postnummer' => '55566',
    'ort' => 'Teststad',
    'created_at' => $now,
    'updated_at' => $now,
]);
$customer = jeab_find_or_create_customer($store, 'Bok Beata');
$booking = $store->insertBooking([
    'customer_id' => $customer['id'],
    'customer_name' => 'Bok Beata',
    'email' => 'bok.beata@example.com',
    'address' => 'Bokgatan 4',
    'notes' => 'fiktiv',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => '2026-10-24 11:00:00',
    'ends_at' => '2026-10-24 12:00:00',
    'status' => 'booked',
    'created_at' => $now,
    'updated_at' => $now,
]);
$store->pdo()->prepare('UPDATE bookings SET contact_id = ? WHERE id = ?')->execute([(string) $booked['id'], (int) $booking['id']]);
$inboxRow = json_encode([
    'id' => 'in-prune',
    'name' => 'Ink Inga',
    'emails' => ['ink.inga@example.com'],
    'mobile' => '0701000508',
    'address' => 'Inkorgsgatan 5',
    'town' => 'Teststad',
    'contact_id' => (string) $inboxed['id'],
], JSON_UNESCAPED_UNICODE);
$store->pdo()->prepare('INSERT INTO inkorg_contacts (id, row_json, email, mobile, updated_at) VALUES (?, ?, ?, ?, ?)')->execute([
    'in-prune',
    $inboxRow,
    'ink.inga@example.com',
    '0701000508',
    $now,
]);

$ids = [
    (string) $orphan['id'],
    (string) $reminded['id'],
    (string) $ordered['id'],
    (string) $booked['id'],
    (string) $inboxed['id'],
    'kt_saknas_prune',
];
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-reuse-missing']));
$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = jeab_contacts_sole_dispatch(['action' => 'kontakter.prune_orphan', 'contact_ids' => $ids]);
ru(($denied['ok'] ?? true) === false && (int) ($denied['status'] ?? 0) === 401, 'prune utan token nekas');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-reuse-missing';
ru(str_contains((string) file_get_contents(dirname(__DIR__) . '/hook.php'), 'kontakter.prune_orphan'), 'hook tar kontakter.prune_orphan');

$dryDefault = jeab_contacts_sole_dispatch(['action' => 'kontakter.prune_orphan', 'contact_ids' => $ids]);
$dryJson = json_encode($dryDefault, JSON_UNESCAPED_UNICODE) ?: '';
ru(!empty($dryDefault['ok']) && !empty($dryDefault['dry']) && empty($dryDefault['written']), 'prune utan dry är torr');
ru(($dryDefault['delete'] ?? []) === [(string) $orphan['id']], 'torrkörningen pekar ut den olänkade');
$skipReasons = [];
foreach (is_array($dryDefault['skipped'] ?? null) ? $dryDefault['skipped'] : [] as $row) {
    if (is_array($row)) {
        $skipReasons[(string) ($row['id'] ?? '')] = (string) ($row['reason'] ?? '');
    }
}
ru(
    ($skipReasons[(string) $reminded['id']] ?? '') === 'har länk'
    && ($skipReasons[(string) $ordered['id']] ?? '') === 'har länk'
    && ($skipReasons[(string) $booked['id']] ?? '') === 'har länk'
    && ($skipReasons[(string) $inboxed['id']] ?? '') === 'har länk'
    && ($skipReasons['kt_saknas_prune'] ?? '') === 'saknas',
    'länkade och saknade hoppas över'
);
ru(isset(jeab_kontakter_load()[(string) $orphan['id']]) && !str_contains($dryJson, $secretPnr) && !str_contains(strtolower($dryJson), 'personnummer'), 'torrkörningen tar inte bort och visar inget personnummer');

$refused = jeab_contacts_sole_dispatch([
    'action' => 'kontakter.prune_orphan',
    'dry' => 0,
    'contact_ids' => [(string) $orphan['id']],
]);
ru(($refused['ok'] ?? true) === false && isset(jeab_kontakter_load()[(string) $orphan['id']]), 'dry=0 utan confirm skriver inget');

$wet = jeab_contacts_sole_dispatch([
    'action' => 'kontakter.prune_orphan',
    'dry' => 0,
    'confirm' => 'SAM_YES',
    'contact_ids' => implode(',', $ids),
]);
$wetJson = json_encode($wet, JSON_UNESCAPED_UNICODE) ?: '';
ru(!empty($wet['ok']) && empty($wet['dry']) && !empty($wet['written']) && ($wet['delete'] ?? []) === [(string) $orphan['id']], 'confirm tar bara bort den olänkade');
ru(!isset(jeab_kontakter_load()[(string) $orphan['id']]), 'den olänkade är borta');
ru(
    isset(jeab_kontakter_load()[(string) $reminded['id']], jeab_kontakter_load()[(string) $ordered['id']], jeab_kontakter_load()[(string) $booked['id']], jeab_kontakter_load()[(string) $inboxed['id']]),
    'påminnelse, order, bokning och inkorg lämnas kvar'
);
ru(!str_contains($wetJson, $secretPnr) && !str_contains(strtolower($wetJson), 'personnummer'), 'prune-svaret har inget personnummer');
$batch = (string) ($wet['batch_id'] ?? '');
$undoHit = false;
foreach (jeab_contacts_sole_undo_all() as $row) {
    if ((string) ($row['batch_id'] ?? '') !== $batch || (string) ($row['kind'] ?? '') !== 'prune_orphan') {
        continue;
    }
    $undoHit = true;
    $blob = (string) ($row['before_json'] ?? '') . (string) ($row['after_json'] ?? '');
    ru((string) ($row['entity_id'] ?? '') === (string) $orphan['id'] && (string) ($row['after_json'] ?? '') === 'null', 'ångerbatchen har före-bilden och tom efter-bild');
    ru(!str_contains($blob, $secretPnr) && !str_contains(strtolower($blob), 'personnummer'), 'ångerbatchen har inget personnummer');
}
ru($undoHit && $batch !== '', 'ångerbatchen finns');

echo "OK  klart\n";
