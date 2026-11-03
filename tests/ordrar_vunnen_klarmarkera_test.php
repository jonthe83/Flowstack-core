<?php

declare(strict_types=1);

/**
 * Vunnen: ordern skapas först, sedan klarmarkeras påminnelsen.
 * Misslyckad order lämnar påminnelsen öppen. Ny vunnen ger ingen dubbelorder.
 * Tmp-data, inget utskick.
 */

$tmp = sys_get_temp_dir() . '/jeab-vunnen-klar-' . bin2hex(random_bytes(4));
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
$GLOBALS['JEAB_FORTNOX_WRITES'] = 0;
$GLOBALS['_JEAB_FORTNOX_CREATE'] = static function (array $row): array {
    $GLOBALS['JEAB_FORTNOX_WRITES'] = (int) $GLOBALS['JEAB_FORTNOX_WRITES'] + 1;

    return $row;
};
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND');
$GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
jeab_ordrar_external_live(false);

function vup(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
$contact = jeab_kontakt_find_or_create([
    'name' => 'Kim Utanprodukt',
    'email' => 'kim.utanprodukt@example.test',
    'phone' => '0701000193',
    'address' => 'Tomproduktgatan 4, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
vup(is_array($contact) && trim((string) ($contact['id'] ?? '')) !== '', 'testkontakten finns');
$contactId = (string) $contact['id'];


$now = '2026-11-01 12:00:00';
$reminder = $store->insertReminder([
    'title' => 'Uppföljning', 'company' => 'jarfalla', 'customer_name' => 'Kim Utanprodukt',
    'email' => 'kim.utanprodukt@example.test', 'phone' => '0701000193', 'tags' => [],
    'created_at' => $now, 'updated_at' => $now,
]);
$rid = (int) $reminder['id'];
$store->saveReminder($rid, ['contact_id' => $contactId]);
$reminder = $store->saveReminder($rid, ['tags' => ['vunnen']]);
vup(empty($reminder['completed_at']), 'påminnelsen är öppen före vunnen');

// Ordningen: påminnelsen får inte vara klar när ordern skapas.
$GLOBALS['VK_SEEN_OPEN'] = null;
$order = jeab_ordrar_vunnen_from_tag($store, $reminder);
vup((int) ($order['reminder_id'] ?? 0) === $rid, 'ordern skapas');
$live = jeab_get_reminder($store, $rid);
vup(is_array($live) && !empty($live['completed_at']), 'påminnelsen klarmarkeras efter ordern');
$src = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar.php');
$fn = substr($src, strpos($src, 'function jeab_ordrar_vunnen_from_tag'), 900);
vup(strpos($fn, 'jeab_ordrar_vunnen_purchase(') < strpos($fn, 'jeab_ordrar_vunnen_close_reminder('), 'ordern skapas före klarmarkeringen');

// Idempotent: vunnen igen ger samma order, ingen dubbel, klar_at orört.
$doneAt = (string) $live['completed_at'];
$again = jeab_ordrar_vunnen_from_tag($store, $live);
vup((int) ($again['reminder_id'] ?? -1) === $rid, 'samma order vid ny vunnen');
$count = 0;
foreach ([false, true] as $klart) {
    foreach (jeab_purchase_list('jarfalla', $klart) as $p) {
        if ((int) ($p['reminder_id'] ?? 0) === $rid) {
            $count++;
        }
    }
}
vup($count === 1, 'ingen dubbelorder');
vup((string) (jeab_get_reminder($store, $rid)['completed_at'] ?? '') === $doneAt, 'klarmarkeringen är orörd');

// Misslyckad order: utan kontakt/adress kastar ordern, påminnelsen förblir öppen.
$bad = $store->insertReminder([
    'title' => 'Saknar allt', 'company' => 'jarfalla', 'customer_name' => '', 'tags' => ['vunnen'],
    'created_at' => $now, 'updated_at' => $now,
]);
$bid = (int) $bad['id'];
$err = '';
try {
    jeab_ordrar_vunnen_from_tag($store, $bad);
} catch (Throwable $e) {
    $err = $e->getMessage();
}
vup($err !== '', 'felet syns: ' . $err);
vup(!is_array(jeab_purchase_get($bid)), 'ingen order skapades');
$b = jeab_get_reminder($store, $bid);
vup(is_array($b) && empty($b['completed_at']), 'påminnelsen förblir öppen');
vup(jeab_ordrar_vunnen_close_reminder($store, $bid) === null && empty(jeab_get_reminder($store, $bid)['completed_at']), 'klarmarkering utan order gör inget');

echo "OK  vunnen klarmarkerar\n";
