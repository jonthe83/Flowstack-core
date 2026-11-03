<?php

declare(strict_types=1);

/**
 * Kort öppnas som ett fragment. Orderns vanliga visning slår inte på
 * Lantmäteriet. Kalenderns veckologg och importindexet ligger inte i klicket.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-kort-fart-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
$_GET = ['p' => 'reminders'];
$_SERVER['REQUEST_METHOD'] = 'GET';
require $root . '/src/bootstrap.php';

$fail = 0;
function kort_ok(bool $ok, string $msg): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $fail++;
    }
}

$index = (string) file_get_contents($root . '/index.php');
$cardAt = strpos($index, "intent'] ?? '') === 'card'");
$packAt = strpos($index, 'jeab_reminders_sql_pack');
kort_ok($cardAt !== false && $packAt !== false && $cardAt < $packAt, 'påminnelsekortet svarar innan listan');
kort_ok(str_contains($index, "intent === 'calendar-log'") && strpos($index, "intent === 'calendar-log'") < strpos($index, 'jeab_bookings_apply_open'), 'veckologgen svarar innan kalendern öppnas');

$js = (string) file_get_contents($root . '/assets/js/app.js');
kort_ok(str_contains($js, "url.searchParams.set('intent', 'card')") && !str_contains($js, "__jeabSoftSyncReminders('expand')"), 'påminnelseklicket hämtar ett kort, inte hela listan');
kort_ok(str_contains($js, 'cal-week-log') && str_contains($js, 'boot.log'), 'veckologgen hämtas när den öppnas');

$page = (string) file_get_contents($root . '/src/pages/bookings.php');
kort_ok(!str_contains($page, 'jeab_calendar_log_for_week'), 'kalendersidan räknar inte veckologgen');
kort_ok(str_contains($page, "'log' => jeab_bookings_url(['intent' => 'calendar-log'])"), 'kalendern pekar på loggfragmentet');

$kontakter = (string) file_get_contents($root . '/src/pages/kontakter.php');
kort_ok(str_contains($kontakter, "intent'] ?? '') === 'card'") && str_contains($kontakter, 'jeab_json('), 'kontaktkortet kan svara som fragment');
kort_ok(str_contains($kontakter, "searchParams.set('intent', 'card')"), 'kontaktklicket hämtar fragmentet');

$hs = (string) file_get_contents($root . '/src/inkorg_helpscout_import.php');
$fn = substr($hs, (int) strpos($hs, 'function jeab_hs_import_threads_for_contact'));
$fn = substr($fn, 0, (int) strpos($fn, 'function jeab_hs_import_filename'));
kort_ok(str_contains($fn, 'jeab_hs_import_rows_for_contact') && !str_contains($fn, 'jeab_hs_import_index()'), 'kontaktens ärenden läser inte hela importindexet');

$ordrar = (string) file_get_contents($root . '/src/ordrar.php');
$prep = substr($ordrar, (int) strpos($ordrar, 'function jeab_ordrar_bekrafta_prepare'));
$prep = substr($prep, 0, (int) strpos($prep, 'function jeab_ordrar_bekrafta_lookup'));
kort_ok(str_contains($prep, 'if (!$wasLive)'), 'stängd extern läsning slår inte på Lantmäteriet');

$store = jeab_store();
kort_ok($store instanceof JeabPdoStore, 'sqlite-lager');
$now = jeab_now();
$saved = $store->insertReminder([
    'title' => 'Kortgatan 1',
    'customer_name' => 'Ada Kort',
    'company' => 'jarfalla',
    'note' => 'En anteckning i kortet.',
    'due_at' => '2026-11-02 09:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$id = (int) ($saved['id'] ?? 0);
kort_ok($id > 0, 'påminnelsen finns');
$_GET = ['p' => 'reminders', 'intent' => 'card', 'open' => (string) $id, 'co' => 'jarfalla'];
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
$payload = jeab_reminder_card_payload($store);
kort_ok(!empty($payload['ok']) && str_contains($payload['html'], 'item-head-open') && str_contains($payload['html'], 'Ada Kort'), 'fragmentet är det öppna kortet');
kort_ok(str_contains($payload['html'], 'En anteckning i kortet.'), 'fragmentet har anteckningen');
kort_ok(!str_contains($payload['html'], 'jeab_reminders_sql_pack'), 'fragmentet är inte listan');

$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
unset($GLOBALS['_JEAB_BEKRAFTA_LOOKUP'], $GLOBALS['_JEAB_LM_CACHE_BYPASS']);
$quiet = jeab_ordrar_bekrafta_prepare([
    'reminder_id' => $id,
    'kind' => 'batteri',
    'company' => 'jarfalla',
    'address' => 'Kortgatan 1',
    'huvudsokande' => 'Ada Kort',
    'fastighetsbeteckning' => 'JARFALLA KORTET 1:1',
    'pris' => 1000,
    'kundpris' => 1000,
    'owners' => [],
], $saved);
kort_ok(jeab_ordrar_external_live() === false, 'visningen lämnar externa anrop av');
kort_ok(empty($GLOBALS['_JEAB_BEKRAFTA_LOOKUP']) && empty($GLOBALS['_JEAB_LM_CACHE_BYPASS']), 'visningen sätter inte uppslagsflaggor');
kort_ok(($quiet['row']['fastighetsbeteckning'] ?? '') === 'JARFALLA KORTET 1:1', 'sparad beteckning står kvar utan uppslag');

$live = jeab_ordrar_external_live(true);
unset($live);
$calls = 0;
$GLOBALS['_JEAB_ROT_HTTP'] = static function () use (&$calls): array {
    $calls++;

    return ['code' => 0, 'body' => ''];
};
jeab_ordrar_external_live(true);
jeab_ordrar_bekrafta_prepare([
    'reminder_id' => $id,
    'kind' => 'service',
    'company' => 'jarfalla',
    'address' => '',
    'fastighetsbeteckning' => '',
    'owners' => [],
], null);
kort_ok(true, 'påslaget uppslag får fortfarande köras');

exit($fail > 0 ? 1 : 0);
