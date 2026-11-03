<?php

declare(strict_types=1);

ob_start();

/**
 * Samtalsstämpeln skriver bara räknaren.
 * Den väntar inte på kontaktlistan, webhooken eller ett sessionslås som en tung synk håller.
 */

$mode = $argv[1] ?? '';
$tmp = getenv('JEAB_TAP_TMP');
if (!is_string($tmp) || $tmp === '') {
    $tmp = sys_get_temp_dir() . '/jeab-tap-fast-' . bin2hex(random_bytes(4));
    putenv('JEAB_TAP_TMP=' . $tmp);
}
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
if (!is_dir($tmp . '/sess') && !mkdir($tmp . '/sess', 0775, true) && !is_dir($tmp . '/sess')) {
    fwrite(STDERR, "FAIL kunde inte skapa sessionkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('JEAB_WEBHOOK_CAPTURE=1');
if (!is_file($tmp . '/config.php')) {
    file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
        'timezone' => 'Europe/Stockholm',
        'outgoing_webhook_url' => 'https://example.test/hook',
        'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    ], true) . ";\n");
}
file_put_contents($tmp . '/kontakter.json', json_encode([
    'contacts' => [
        'c-slow' => ['id' => 'c-slow', 'name' => 'Långsam Kontakt', 'street' => 'Testgatan 1', 'city' => 'Solna'],
    ],
], JSON_UNESCAPED_UNICODE));

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/reminders.php';

function tf(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

if ($mode === 'sync-hold') {
    session_save_path($tmp . '/sess');
    session_id((string) ($argv[2] ?? ''));
    session_start();
    $_SESSION['jeab_company'] = 'jarfalla';
    jeab_release_session();
    file_put_contents($tmp . '/ready', '1');
    $GLOBALS['_JEAB_KONTAKTER_LOAD_HOOK'] = static function (): void {
        sleep(2);
    };
    jeab_kontakter_load();
    file_put_contents($tmp . '/done', '1');
    exit(0);
}

$src = (string) file_get_contents(dirname(__DIR__) . '/src/reminders.php');
$fnAt = strpos($src, 'function jeab_tap_reminder(');
$fnEnd = strpos($src, "\nfunction ", $fnAt === false ? 0 : $fnAt + 10);
$fn = ($fnAt === false || $fnEnd === false) ? '' : substr($src, $fnAt, $fnEnd - $fnAt);
tf($fn !== '' && !str_contains($fn, 'jeab_update_reminder') && !str_contains($fn, 'jeab_fire_webhook') && !str_contains($fn, 'jeab_reminder_linked_contact') && !str_contains($fn, 'saveReminder'), 'stämpeln hoppar över helrad, webhook och kontaktifyllnad');
tf(str_contains($fn, 'incrementReminderTap'), 'stämpeln ökar en kolumn');
$storeSrc = (string) file_get_contents(dirname(__DIR__) . '/src/store.php');
$incAt = strpos($storeSrc, 'SET ringt = ringt + 1');
$inc = $incAt === false ? '' : substr($storeSrc, max(0, $incAt - 500), 1400);
tf(str_contains($inc, 'SET ringt = ringt + 1') && str_contains($inc, 'SET pratat = pratat + 1') && str_contains($inc, 'SET mailat = mailat + 1') && str_contains($inc, 'SET smsat = smsat + 1'), 'MySQL skriver bara räknaren och updated_at');
tf(!str_contains($inc, 'customer_name') && !str_contains($inc, 'jeab_fire_webhook'), 'enkelraden rör inte övriga kolumner');
$js = (string) file_get_contents(dirname(__DIR__) . '/assets/js/app.js');
$bindAt = strpos($js, 'function bindInPlace(');
$bindEnd = strpos($js, "\n  function ", $bindAt === false ? 0 : $bindAt + 10);
$bind = ($bindAt === false || $bindEnd === false) ? '' : substr($js, $bindAt, $bindEnd - $bindAt);
tf(str_contains($bind, "showReminderBar('Kunde inte spara.'") && str_contains($bind, 'rollback'), 'misslyckad stämpel visar toast och rullar tillbaka');
tf(str_contains($js, 'bindInPlace(form') && str_contains($js, 'skipFlush: true'), 'stämpeln går via bindInPlace utan att vänta på anteckningen');

$index = (string) file_get_contents(dirname(__DIR__) . '/index.php');
$postAt = strpos($index, "if (\$_SERVER['REQUEST_METHOD'] === 'POST')");
$post = $postAt === false ? '' : substr($index, $postAt, 900);
$releaseAt = strpos($post, "if (\$action === 'reminder.tap')");
$handleAt = strpos($post, 'jeab_handle_action(');
tf($releaseAt !== false && $handleAt !== false && $releaseAt < $handleAt && str_contains(substr($post, $releaseAt, $handleAt - $releaseAt), 'jeab_release_session()'), 'stämpelns request släpper sessionen innan den sparar');

$remAt = strpos($index, '$jeabRemStarted');
$rem = $remAt === false ? '' : substr($index, $remAt, 6000);
$listAt = strpos($rem, 'jeab_reminders_sql_pack(');
$loadAt = strpos($rem, 'jeab_reminders_load_company(');
$syncRelease = strpos($rem, 'jeab_release_session()');
tf($syncRelease !== false && $listAt !== false && $loadAt !== false && $syncRelease < $listAt && $syncRelease < $loadAt, 'synken släpper sessionen innan listan byggs');

$store = jeab_store();
$now = jeab_now();
$created = $store->insertReminder([
    'title' => 'Norrskensvägen 12',
    'customer_name' => 'Test Tap',
    'company' => 'jarfalla',
    'phone' => '0701112233',
    'due_at' => '2026-10-22 09:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$id = (int) $created['id'];
$store->saveReminder($id, ['contact_id' => 'c-slow']);
tf((string) ($store->getReminder($id)['contact_id'] ?? '') === 'c-slow', 'påminnelsen pekar på en kontakt');

$GLOBALS['_JEAB_WEBHOOKS'] = [];
$GLOBALS['_JEAB_KONTAKTER_LOADS'] = 0;
$GLOBALS['_JEAB_KONTAKTER_LOAD_HOOK'] = static function (): void {
    usleep(1500000);
};

$sessionId = bin2hex(random_bytes(8));
@unlink($tmp . '/ready');
@unlink($tmp . '/done');
$proc = proc_open(
    [PHP_BINARY, __FILE__, 'sync-hold', $sessionId],
    [1 => ['file', $tmp . '/sync-out', 'w'], 2 => ['file', $tmp . '/sync-err', 'w']],
    $pipes
);
tf(is_resource($proc), 'synkprocessen startade');
$wait = microtime(true);
while (!is_file($tmp . '/ready') && microtime(true) - $wait < 5) {
    usleep(20000);
}
tf(is_file($tmp . '/ready'), 'synken har släppt sessionen ' . (is_file($tmp . '/sync-err') ? trim((string) file_get_contents($tmp . '/sync-err')) : ''));

session_save_path($tmp . '/sess');
session_id($sessionId);
$lockStarted = microtime(true);
$started = session_start();
$lockDt = microtime(true) - $lockStarted;
$active = session_status() === PHP_SESSION_ACTIVE;
jeab_release_session();
tf($started && $active, 'sessionen gick att öppna medan synken fortfarande arbetar');
tf($lockDt < 0.4, 'stämpeln väntar inte på sessionslåset (' . round($lockDt, 3) . ' s)');

$tapStarted = microtime(true);
$tapped = jeab_tap_reminder($store, $id, 'pratat');
$tapDt = microtime(true) - $tapStarted;
tf($tapDt < 0.4, 'stämpeln är klar på en gång (' . round($tapDt, 3) . ' s)');
tf((int) ($tapped['pratat'] ?? 0) === 1 && (int) ($tapped['ringt'] ?? 0) === 0, 'pratat ökade med ett');
tf((int) ($GLOBALS['_JEAB_KONTAKTER_LOADS'] ?? 0) === 0, 'kontaktlistan lästes inte');
tf(($GLOBALS['_JEAB_WEBHOOKS'] ?? []) === [], 'ingen webhook');
tf(!is_file($tmp . '/done'), 'synkens tunga arbete pågår fortfarande');

$again = jeab_tap_reminder($store, $id, 'pratat');
tf((int) ($again['pratat'] ?? 0) === 2, 'andra stämpeln räknar vidare');

proc_terminate($proc);
proc_close($proc);
echo "ALLA OK\n";
ob_end_flush();
