<?php

declare(strict_types=1);

ob_start();

/**
 * Påminnelser skriver CSRF och flash innan sessionen släpps.
 * Formulärens token gäller efteråt. En skrivning efter släppet sparas inte.
 */

$root = dirname(__DIR__);
$index = (string) file_get_contents($root . '/index.php');

function csrf_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

csrf_ok(!str_contains($index, 'jeab_prune_completed_reminders'), 'Påminnelser anropar inte prune');
$renderAt = strpos($index, 'function jeab_render_page(');
$releaseAt = $renderAt === false ? false : strrpos($index, 'jeab_release_session()');
csrf_ok($renderAt !== false && $releaseAt !== false && $releaseAt > $renderAt, 'sidan släpper sessionen');
$before = substr($index, max(0, $releaseAt - 400), 400);
csrf_ok(str_contains($before, 'jeab_flash_text(') && str_contains($before, 'jeab_csrf_session_token()') && str_contains($before, 'jeab_csrf_token()'), 'flash och CSRF skrivs innan sessionen släpps');
$after = substr($index, $releaseAt, 220);
csrf_ok(!str_contains($after, 'jeab_prune_completed_reminders'), 'släppet följs inte av prune');

$tmp = sys_get_temp_dir() . '/jeab-csrf-rel-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
if (!is_dir($tmp . '/sess') && !mkdir($tmp . '/sess', 0775, true) && !is_dir($tmp . '/sess')) {
    fwrite(STDERR, "FAIL sess\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require_once $root . '/src/bootstrap.php';
require_once $root . '/src/layout.php';
require_once $root . '/src/reminders.php';

session_save_path($tmp . '/sess');
session_id('jeabcsrfrel');
session_start();
$_SESSION['jeab_email'] = 'ada@example.com';
unset($_SESSION['csrf']);
$flash = jeab_flash_text('Sparad.');
$csrf = jeab_csrf_session_token();
$shown = jeab_csrf_token();
csrf_ok($flash === 'Sparad.', 'flash-texten är klar innan släppet');
csrf_ok(session_status() === PHP_SESSION_ACTIVE && $csrf !== '' && ($_SESSION['csrf'] ?? '') === $csrf, 'CSRF ligger i sessionen innan släppet');
csrf_ok($shown !== '' && jeab_csrf_ok($shown), 'den synliga token gäller');
jeab_release_session();
csrf_ok(session_status() !== PHP_SESSION_ACTIVE, 'sessionen är släppt');
$_SESSION['after_release'] = 'borta';
$_SESSION['jeab_flash_late'] = 'Knuffad.';

$now = jeab_now();
$html = jeab_reminder_row([
    'id' => 7,
    'title' => 'Testgatan 1',
    'address' => 'Testgatan 1',
    'customer_name' => 'Ada',
    'phone' => '0701112233',
    'due_at' => '2026-10-22 09:00:00',
    'company' => 'jarfalla',
    'created_at' => $now,
    'updated_at' => $now,
], true);
$found = [];
preg_match_all('/name="_csrf" value="([^"]+)"/', $html, $found);
$tokens = $found[1] ?? [];
csrf_ok(count($tokens) >= 3, 'knapparnas formulär har CSRF, fick ' . count($tokens));
csrf_ok(str_contains($html, 'data-js="snooze"') && str_contains($html, 'data-js="tap"'), 'knuff och stämpel finns på kortet');
foreach ($tokens as $token) {
    if (!jeab_csrf_ok($token) || ($token !== $csrf && $token !== $shown)) {
        csrf_ok(false, 'ett formulär har en token som inte sparades');
    }
}
csrf_ok(true, 'varje knapp använder den sparade token');
csrf_ok(jeab_flash_html($flash) !== '' && str_contains(jeab_flash_html($flash), 'Sparad.'), 'flash kan ritas efter släppet');

session_id('jeabcsrfrel');
$opened = session_start();
csrf_ok($opened && session_status() === PHP_SESSION_ACTIVE, 'sessionen går att öppna igen');
csrf_ok(($_SESSION['csrf'] ?? '') === $csrf, 'CSRF finns kvar på disk');
csrf_ok(!isset($_SESSION['after_release']) && !isset($_SESSION['jeab_flash_late']), 'skrivning efter släppet försvann');
foreach ($tokens as $token) {
    if (!jeab_csrf_ok($token)) {
        csrf_ok(false, 'token gäller inte i nästa request');
    }
}
csrf_ok(true, 'nästa request godkänner formulärens token');

echo "ALLA OK\n";
ob_end_flush();
