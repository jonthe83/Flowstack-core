<?php

declare(strict_types=1);

/**
 * Klick ska inte bygga om hela mejlboxen, räkna vyer var för sig,
 * eller köra CREATE TABLE på en sidvisning.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-fart-klick-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_GET = ['p' => 'inkorg-vyer'];
require $root . '/src/bootstrap.php';
require $root . '/src/layout.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$fail = 0;
function fk(bool $ok, string $msg): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $fail++;
    }
}

$shell = (string) file_get_contents($root . '/assets/js/shell.js');
fk(str_contains($shell, "p === 'inkorg'") && !str_contains($shell, 'if (isInbox(p) || p === \'csrf\''), 'Vyer och Regler är inte undantagna från skalet');
fk(str_contains($shell, "document.getElementById('ix')"), 'skalet lämnar bara Inkorgens egen lista');
fk(str_contains($shell, 'page-inkorg'), 'inkorgen har kvar sin egen navigering');

$boot = (string) file_get_contents($root . '/src/bootstrap.php');
fk(str_contains($boot, "'inkorg-vyer'") && str_contains($boot, "'inloggdiag'"), 'Vyer och Inloggningar är lätta sidor');

$notes = (string) file_get_contents($root . '/src/notes_store.php');
$ensureAt = strpos($notes, 'function jeab_notes_store_ensure');
$ensure = $ensureAt === false ? '' : substr($notes, $ensureAt, 700);
fk(str_contains($ensure, 'jeab_schema_request_skips_ddl'), 'anteckningar skapar inte tabell på en sidvisning');

$cal = (string) file_get_contents($root . '/src/calendar_outlook.php');
$calAt = strpos($cal, 'function jeab_outlook_ensure');
$calBody = $calAt === false ? '' : substr($cal, $calAt, 900);
fk(str_contains($calBody, "REQUEST_METHOD") && str_contains($calBody, 'jeab_schema_request_skips_ddl'), 'kalenderns GET skapar inte Outlook-tabeller');

$pdf = (string) file_get_contents($root . '/src/fullmakt_signed.php');
fk(str_contains($pdf, 'function jeab_fullmakt_signed_find_tokens') && str_contains($pdf, 'fullmakt_sql_tokens_by_status'), 'PDF-öppning läser token, inte hela payloaden');

$notiser = (string) file_get_contents($root . '/src/pages/notiser.php');
fk(str_contains($notiser, 'data-set="flik"') && str_contains($notiser, 'stopImmediatePropagation'), 'utskickets filter och vy byts i sidan');

$storeSrc = (string) file_get_contents($root . '/src/inkorg_store.php');
fk(str_contains($storeSrc, 'function countViewFilters'), 'vyerna räknas i en fråga');

$pdo = jeab_pdo_connect_sqlite();
fk(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan går att sätta');
$store = jeab_inkorg_pdo_store();
fk($store instanceof JeabPdoInkorgStore, 'SQL-lagret är öppet');
foreach ([
    ['closed-1', 'closed', [], 'Information'],
    ['open-sol', 'open', ['sol'], 'Ny lead'],
    ['open-info', 'open', [], 'Information'],
    ['open-bat', 'open', ['batteri'], 'Ny lead'],
] as [$id, $status, $tags, $type]) {
    $store->saveListRow([
        'id' => $id,
        'company' => 'jarfalla',
        'status' => $status,
        'deleted' => false,
        'assignee' => '',
        'updated_at' => '2024-06-01T10:00:00+00:00',
        'customer_email' => $id . '@exempel.test',
        'customer_name' => $id,
        'labels' => ['type' => $type],
        'tags' => $tags,
        'search_hay' => $id,
        'list_snippet' => $id,
    ]);
}
$GLOBALS['_JEAB_VIEW_FILTER_SQL'] = 0;
$batch = $store->countViewFilters([
    ['a', ['type' => 'Information', 'company' => 'jarfalla']],
    ['b', ['tag' => 'sol', 'company' => 'jarfalla']],
    ['c', ['show' => ['open']]],
]);
fk((int) ($batch['a'] ?? -1) === 1, 'informationsvyn räknas, fick ' . (int) ($batch['a'] ?? -2));
fk((int) ($batch['b'] ?? -1) === 1, 'sol-etiketten räknas, fick ' . (int) ($batch['b'] ?? -2));
fk((int) ($batch['c'] ?? 0) === -1, 'show-filter räknas inte i SQL');
fk((int) $GLOBALS['_JEAB_VIEW_FILTER_SQL'] === 1, 'två vyer är en fråga, fick ' . (int) $GLOBALS['_JEAB_VIEW_FILTER_SQL']);

$_GET = ['p' => 'inkorg-vyer', 'box' => 'jarfalla'];
$_SESSION['jeab_inkorg_box'] = 'jarfalla';
$GLOBALS['_JEAB_PAGE_REQUEST'] = 1;
$GLOBALS['_JEAB_SAPI'] = 'apache2handler';
$GLOBALS['_JEAB_INKORG_THREADS_CALLS'] = 0;
unset($GLOBALS['_JEAB_INKORG_UNREAD']);
$badge = jeab_inkorg_unread_count();
fk($badge === 0, 'Vyer utan räknarcache bygger inte olästa, fick ' . $badge);
fk((int) $GLOBALS['_JEAB_INKORG_THREADS_CALLS'] === 0, 'Vyer anropar inte jeab_inkorg_threads för märket');

$notePdo = new PDO('sqlite:' . $tmp . '/notes.sqlite');
jeab_notes_store_ensure($notePdo);
$hasNotes = $notePdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='notes'")->fetchColumn();
fk($hasNotes === false, 'sidvisning skapar inte notes');

unset($GLOBALS['_JEAB_PAGE_REQUEST']);
$GLOBALS['_JEAB_SAPI'] = 'cli';
$notePdo2 = new PDO('sqlite:' . $tmp . '/notes-cli.sqlite');
jeab_notes_store_ensure($notePdo2);
$hasCli = $notePdo2->query("SELECT name FROM sqlite_master WHERE type='table' AND name='notes'")->fetchColumn();
fk($hasCli === 'notes', 'CLI skapar notes');

$ordrar = (string) file_get_contents($root . '/src/ordrar.php');
fk(str_contains($ordrar, 'function jeab_ordrar_visible_stamp') && str_contains($ordrar, 'jeab_ordrar_visible_cache_read') && str_contains($ordrar, 'jeab_ordrar_list_cache_forget'), 'ordrar återanvänder listan tills en order skrivs');

exit($fail > 0 ? 1 : 0);
