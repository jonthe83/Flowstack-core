<?php

declare(strict_types=1);

/**
 * Kort, kontaktlista och orderfilter ska inte läsa hela lagret.
 * Påhittad data. Skickar inget.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-sidfart-' . bin2hex(random_bytes(4));
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}
putenv('JEAB_DISABLE_SQLITE=0');
putenv('CONTACTS_SOLE=1');
putenv('DATA_BACKEND=mysql');
$_ENV['CONTACTS_SOLE'] = '1';
$_ENV['DATA_BACKEND'] = 'mysql';
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
$_GET = ['p' => 'kontakter'];
$_SERVER['REQUEST_METHOD'] = 'GET';
require $root . '/src/bootstrap.php';

$fail = 0;
function sf(bool $ok, string $msg): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $fail++;
    }
}

$store = jeab_store();
sf($store instanceof JeabPdoStore, 'sqlite-lager');
if (!$store instanceof JeabPdoStore) {
    exit(1);
}
$pdo = $store->pdo();
$now = jeab_now();
$live = jeab_inkorg_sql_store_live();
sf($live instanceof JeabPdoInkorgStore, 'inkorgslager');
if (!$live instanceof JeabPdoInkorgStore) {
    exit(1);
}

$fat = str_repeat('x', 8000);
for ($i = 0; $i < 200; $i++) {
    $at = date('Y-m-d H:i:s', strtotime('2026-01-01 00:00:00') + ($i * 60));
    $live->saveListRow([
        'id' => 't-fat-' . $i,
        'company' => 'jarfalla',
        'contact_id' => 'kt_fat',
        'status' => 'open',
        'updated_at' => $at,
        'customer_email' => 'fat@example.test',
        'subject' => $i === 199 ? 'Nyast arende' : ('Gammalt ' . $i),
        'body' => $fat,
        'first' => ['subject' => $i === 199 ? 'Nyast arende' : ('Gammalt ' . $i)],
    ]);
}
$live->saveListRow([
    'id' => 't-slim',
    'company' => 'jarfalla',
    'contact_id' => 'kt_slim',
    'status' => 'open',
    'updated_at' => '2026-06-01 10:00:00',
    'subject' => 'En rad',
    'first' => ['subject' => 'En rad'],
]);

$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
$GLOBALS['_JEAB_HS_INDEX_FULL'] = 0;
$started = microtime(true);
$cases = jeab_hs_import_threads_for_contact('kt_fat');
$elapsed = microtime(true) - $started;
$subjects = [];
foreach ($cases as $case) {
    $subjects[] = (string) ($case['subject'] ?? '');
}
sf(count($cases) <= 40 && count($cases) > 0, 'fett kort läser högst 40 ärenden, fick ' . count($cases));
sf(in_array('Nyast arende', $subjects, true), 'de senaste ärendena följer med');
sf(!in_array('Gammalt 0', $subjects, true), 'äldsta ärendet läses inte');
sf($elapsed < 1.0, 'fett kort öppnas under 1 s, tog ' . round($elapsed, 3) . ' s');
sf((int) $GLOBALS['_JEAB_HS_INDEX_FULL'] === 0, 'kortet läser inte hela importindexet');
$slim = jeab_hs_import_threads_for_contact('kt_slim');
sf(count($slim) === 1 && (string) ($slim[0]['subject'] ?? '') === 'En rad', 'litet kort läser sin rad');

$GLOBALS['_JEAB_KONTAKT_CARD_DEADLINE'] = microtime(true) - 1;
sf(jeab_hs_import_threads_for_contact('kt_fat') === [], 'tidsbudgeten stoppar ärendena');
unset($GLOBALS['_JEAB_KONTAKT_CARD_DEADLINE']);

for ($i = 0; $i < 60; $i++) {
    $row = $store->insertReminder([
        'title' => 'Paminnelse ' . $i,
        'customer_name' => 'Fett Kort',
        'company' => 'jarfalla',
        'note' => $fat,
        'due_at' => '2026-11-02 09:00:00',
        'created_at' => $now,
        'updated_at' => date('Y-m-d H:i:s', strtotime('2026-11-01') + $i),
    ]);
    $pdo->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute(['kt_fat', (int) ($row['id'] ?? 0)]);
}
$linkStarted = microtime(true);
$links = jeab_kontakt_links($store, 'kt_fat');
$linkElapsed = microtime(true) - $linkStarted;
sf(count($links['reminders']) <= JEAB_KONTAKT_LINK_CAP, 'kortet hämtar högst en sida påminnelser, fick ' . count($links['reminders']));
sf((int) ($links['counts']['reminders'] ?? 0) === 60, 'räknaren är hela träffen, fick ' . (int) ($links['counts']['reminders'] ?? 0));
sf($linkElapsed < 1.0, 'länkarna tar under 1 s, tog ' . round($linkElapsed, 3) . ' s');

$contacts = jeab_kontakter_pdo_store();
sf($contacts instanceof JeabPdoContactStore, 'kontakt-SQL');
if ($contacts instanceof JeabPdoContactStore) {
    $contacts->upsert('kt_post', [
        'id' => 'kt_post',
        'street' => 'Postgatan 1',
        'city' => 'Järfälla',
        'company' => 'jarfalla',
        'owners' => [[
            'name' => 'Post Person',
            'email' => 'post.person@example.test',
            'phone' => '0701000099',
            'contact' => true,
        ]],
    ]);
}
$loads = (int) ($GLOBALS['_JEAB_KONTAKTER_LOADS'] ?? 0);
$party = jeab_contacts_sole_party_id('post.person@example.test');
$partyAgain = jeab_contacts_sole_party_id('post.person@example.test');
sf($party === 'kt_post' && $partyAgain === 'kt_post', 'party_id träffar mejlindexet, fick ' . $party);
sf((int) ($GLOBALS['_JEAB_KONTAKTER_LOADS'] ?? 0) === $loads, 'party_id läser inte hela registret');

$gapKey = 'order|sidfart-' . getmypid() . '|address';
sf(jeab_contacts_sole_gap_log_allow($gapKey) === true, 'luckan loggas första gången');
sf(jeab_contacts_sole_gap_log_allow($gapKey) === false, 'samma lucka loggas inte igen samma dygn');

unset($GLOBALS['_JEAB_PAGE_REQUEST'], $GLOBALS['_JEAB_SAPI']);
$_GET = ['p' => 'ordrar', 'co' => 'jarfalla', 'job' => 'sol'];
unset($_GET['who'], $_GET['ws']);
$_SESSION['jeab_ordrar_filter'] = [];
$solState = jeab_filter_from_request('jarfalla', true);
sf(($solState['job'] ?? null) === 'sol', 'job=sol utan ws filtrerar, fick ' . json_encode($solState));
$_GET['job'] = 'batteri';
$batState = jeab_filter_from_request('jarfalla', true);
sf(($batState['job'] ?? null) === 'batteri', 'job=batteri filtrerar');
$_GET['job'] = 'laddbox';
$ladState = jeab_filter_from_request('jarfalla', true);
sf(($ladState['job'] ?? null) === 'laddbox', 'job=laddbox filtrerar');
sf($solState['job'] !== $batState['job'] && $batState['job'] !== $ladState['job'], 'produkterna är inte samma filter');

foreach ([
    9101 => 'sol',
    9102 => 'batteri',
    9103 => 'laddbox',
] as $id => $kind) {
    jeab_sql_purchases_put($pdo, [
        'reminder_id' => $id,
        'company' => 'jarfalla',
        'kind' => $kind,
        'customer_name' => $kind . ' kund',
        'name' => $kind . ' kund',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}
jeab_settings_put($pdo, 'sqlite', 'purchases_storage', 'sql');
jeab_settings_put($pdo, 'sqlite', 'purchases_list_index_v1', 'ok');
jeab_settings_put($pdo, 'sqlite', 'purchases_list_json_v1', 'ok');
jeab_ordrar_list_cache_forget();
$names = static function (string $job): array {
    $out = [];
    foreach (jeab_ordrar_visible('jarfalla', false, $job) as $row) {
        if (is_array($row)) {
            $out[] = (string) ($row['customer_name'] ?? $row['name'] ?? '');
        }
    }
    sort($out);

    return $out;
};
$solNames = $names('sol');
$batNames = $names('batteri');
$ladNames = $names('laddbox');
sf($solNames === ['sol kund'], 'sol-listan är bara sol, fick ' . implode(',', $solNames));
sf($batNames === ['batteri kund'], 'batteri-listan är bara batteri, fick ' . implode(',', $batNames));
sf($ladNames === ['laddbox kund'], 'laddbox-listan är bara laddbox, fick ' . implode(',', $ladNames));
sf($solNames !== $batNames && $batNames !== $ladNames, 'listorna har inte samma text');

$_GET = ['p' => 'ordrar', 'co' => 'jarfalla', 'who' => 'all', 'job' => 'all'];
unset($_GET['ws'], $_SESSION['jeab_ordrar_filter']);
$stale = jeab_filter_from_request('jarfalla', true);
sf(($GLOBALS['_JEAB_WHO_SRC'] ?? '') === 'url-stale-ignored', 'who=all&job=all utan ws är fortfarande en gammal adress');
sf(!isset($stale['job']) || $stale['job'] === null, 'job=all återgår till förvalet, fick ' . json_encode($stale));

exit($fail > 0 ? 1 : 0);
