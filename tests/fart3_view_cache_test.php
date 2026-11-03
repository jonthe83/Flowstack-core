<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$_GET = ['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'today', 'ws' => '111'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
$GLOBALS['_JEAB_BOOT_LAZY'] = true;
require_once $root . '/src/bootstrap.php';

function f3_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$a = jeab_view_cache_query();
$_GET['ws'] = '222';
$b = jeab_view_cache_query();
$_GET['open'] = '5';
$c = jeab_view_cache_query();
if ($a === '' || $a !== $b) {
    f3_fail('ws ska inte ingå i cache-nyckeln');
}
if ($c === $a) {
    f3_fail('open ska ingå i cache-nyckeln');
}
if (jeab_view_cache_defer_modules()) {
    f3_fail('cli ska inte hoppa över modulerna');
}
echo "OK  cache-nyckel ignorerar ws\n";

jeab_bootstrap_load_page_modules();
$plain = jeab_reminder_note_html("Rad ett\nRad två");
$expect = htmlspecialchars("Rad ett\nRad två", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
if ($plain !== $expect) {
    f3_fail('anteckning utan url ändrades');
}
$linked = jeab_reminder_note_html('Se https://example.com/a.');
if (!str_contains($linked, 'href="https://example.com/a"') || !str_contains($linked, '>https://example.com/a</a>')) {
    f3_fail('länk i anteckning gick sönder');
}
echo "OK  anteckning utan url slipper regex\n";

$row = [
    'id' => 9,
    'title' => 'Testgatan 1',
    'customer_name' => 'Ada',
    'address' => 'Testgatan 1',
    'ort' => 'Järfälla',
    'company' => 'jarfalla',
    'due_at' => '2026-10-29 09:00:00',
    'note' => 'Kort text',
    'phone' => '0700000001',
    'created_at' => '2026-10-01 08:00:00',
    'updated_at' => '2026-10-01 08:00:00',
    'completed_at' => null,
];
$open = jeab_reminder_row($row, true);
if (!str_contains($open, 'item-head-open') || str_contains($open, 'pam-desk-pin') || str_contains($open, 'pam-side')) {
    f3_fail('öppet kort ska vara oförändrat');
}
echo "OK  öppet kort har kvar sin HTML\n";

$page = (string) file_get_contents($root . '/src/pages/reminders.php');
if (!str_contains($page, 'pam-board') || !str_contains($page, 'jeab_pam_desk_pin') || !str_contains($page, 'data-js="pam-side"')) {
    f3_fail('listan saknar skrivbordets rutor');
}
$css = (string) file_get_contents($root . '/assets/css/paminnelser.css');
if (!str_contains($css, 'min-width: 900px') || !str_contains($css, 'min-width: 1280px')) {
    f3_fail('css saknar brytpunkterna');
}
if (!preg_match('/\.pam-desk-pin,\s*html body\.page-reminders \.pam-mid-empty,\s*html body\.page-reminders \.pam-side \{\s*display:\s*none;/', $css)) {
    f3_fail('mobil döljer inte de nya rutorna');
}
$index = (string) file_get_contents($root . '/index.php');
$sideAt = strpos($index, "=== 'side'");
$listAt = strpos($index, '/src/pages/reminders.php');
if ($sideAt === false || $listAt === false || $sideAt > $listAt) {
    f3_fail('sidopanelen ska läsas före listans HTML');
}
if (!str_contains($index, 'jeab_view_cache_serve')) {
    f3_fail('index servar inte vy-cachen');
}
$app = (string) file_get_contents($root . '/assets/js/app.js');
if (!str_contains($app, "searchParams.set('intent', 'side')") || !str_contains($app, '1280px')) {
    f3_fail('klienten hämtar inte panelen');
}
if (!str_contains($app, 'function pamDeskEnsureTop') || !str_contains($app, 'pamDeskTopItem')) {
    f3_fail('skrivbordet väljer inte översta påminnelsen');
}
if (!str_contains($css, '.apple-main > .empty')) {
    f3_fail('tom lista ska ligga i de tre rutorna');
}
echo "OK  skrivbordet är extra HTML och hämtas vid intent=side\n";

$etag = jeab_partial_etag('{"ok":true}');
if ($etag === '' || !str_starts_with($etag, '"j')) {
    f3_fail('etag saknas');
}
$_SERVER['HTTP_IF_NONE_MATCH'] = $etag;
if (!jeab_partial_not_modified($etag)) {
    f3_fail('samma etag ska ge 304');
}
$_SERVER['HTTP_IF_NONE_MATCH'] = '"annan"';
if (jeab_partial_not_modified($etag)) {
    f3_fail('annan etag ska inte ge 304');
}
unset($_SERVER['HTTP_IF_NONE_MATCH']);
echo "OK  oförändrad partial kan svara 304\n";
