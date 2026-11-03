<?php

declare(strict_types=1);

/**
 * Inkorgs diagnostikring (data.inkorg_trace). En nekad SQL-anslutning i en
 * riktig sidrequest ger en reservrad i loggen. I json-läge skrivs inget.
 * Ingen sökväg, e-post eller kontonamn hamnar i filen. Ingen DDL.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-ixtrace-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
mkdir($tmp . '/privat', 0775, true);
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
$GLOBALS['_JEAB_PRIVAT_DIR'] = $tmp . '/privat';
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
require $root . '/src/bootstrap.php';
require $root . '/src/layout.php';
require_once $root . '/src/data_cutover_all.php';

function ct(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/** En sidrequest p=inkorg&ix_part=list: förbered, slutrad, skriv. */
function ct_request(): void
{
    unset($GLOBALS['_JEAB_INKORG_TRACE'], $GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP'], $GLOBALS['_JEAB_INKORG_TRACE_PDO_STATE']);
    $fast = [];
    try {
        $fast = jeab_inkorg_fast_prepare(false, 'list');
    } catch (Throwable $e) {
        jeab_inkorg_trace_event('test.prepare_throw', $e);
    }
    jeab_inkorg_trace_page('list', $fast);
    jeab_inkorg_trace_flush();
}

/** Simulerar nekad anslutning: huvudlagret tappar PDO och får inte öppna igen. */
function ct_deny(bool $deny): void
{
    $base = jeab_store();
    if (!$base instanceof JeabPdoStore) {
        return;
    }
    $ref = new ReflectionClass($base);
    $reopen = $ref->getProperty('reopen');
    $reopen->setAccessible(true);
    if ($deny) {
        $base->disconnect();
        $reopen->setValue($base, true);
    } else {
        $reopen->setValue($base, false);
    }
}

function ct_rows(): array
{
    return jeab_inkorg_trace_read();
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_GET = ['p' => 'inkorg', 'ix_part' => 'list', 'box' => 'jarfalla', 'view' => 'v-inkorg', 'scope' => 'allas'];
$GLOBALS['_JEAB_INKORG_TRACE_CLI'] = true;
$GLOBALS['_JEAB_INKORG_TRACE_NO_SHUTDOWN'] = true;
$GLOBALS['_JEAB_AUTH_OK'] = ['' => true];
$tracePath = jeab_inkorg_trace_path();

// Rensaren.
$clean = jeab_inkorg_trace_clean("SQLSTATE[HY000] [1045] Access denied for user 'jeab_app'@'10.1.2.3' (using password: YES) in /home/jeab/public_html/src/db.php kund@exempel.test host=db.example.se 46701234567");
ct(!str_contains($clean, 'jeab_app') && !str_contains($clean, '/home') && !str_contains($clean, '@exempel') && !str_contains($clean, 'db.example') && !str_contains($clean, '46701234567'), 'rensaren tar bort konto, sökväg, e-post, värd och nummer');
ct(str_contains($clean, '1045') && str_contains($clean, 'Access denied'), 'rensaren behåller felkoden');

// 1. json-läge: inget skrivs.
$pdo = jeab_pdo_connect_sqlite();
ct(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'json'), 'json-flaggan sätts');
ct(jeab_inkorg_storage_mode() === 'json', 'läget är json');
ct_request();
ct(!is_file($tracePath), 'json-läge skriver ingen logg');

// 2. sql-läge, frisk anslutning: en slutrad med källa och siffror.
ct(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan sätts');
$store = jeab_inkorg_pdo_store();
ct($store instanceof JeabPdoInkorgStore, 'SQL-lagret är öppet');
for ($i = 1; $i <= 3; $i++) {
    $id = 't' . $i;
    $store->upsertMessage($id . 'm', [
        'id' => $id . 'm', 'thread_id' => $id, 'from' => 'kund' . $i . '@exempel.test', 'from_name' => 'Kund ' . $i,
        'to' => 'kontakt@jarfalla.example.test', 'subject' => 'Rad ' . $i, 'body' => 'Kort', 'received_at' => '2024-06-0' . $i . 'T10:00:00+00:00',
        'source' => 'graph-jarfalla', 'labels' => ['type' => 'Support', 'product' => 'batteri'],
    ]);
    $store->upsertThread($id, ['status' => 'open', 'tags' => [], 'assignee' => '', 'read' => [], 'company' => 'jarfalla', 'updated_at' => '2024-06-0' . $i . 'T10:00:00+00:00']);
}
ct_request();
ct(is_file($tracePath), 'sql-läge skriver loggen');
ct((fileperms($tracePath) & 0777) === 0600, 'loggen har 0600');
$rows = ct_rows();
$end = end($rows);
ct(is_array($end) && ($end['k'] ?? '') === 'end' && ($end['mode'] ?? '') === 'sql' && ($end['part'] ?? '') === 'list', 'slutraden visar del och läge sql');
ct(is_array($end['source'] ?? null) && array_filter($end['source'], static fn ($s) => str_starts_with((string) $s, 'sql')) !== [], 'slutraden visar SQL-källa');
ct(array_key_exists('oppna', $end) && array_key_exists('identity', $end) && isset($end['ms']), 'slutraden har Öppna, identitet och ms');

// 3. Nekad anslutning mitt i requesten: reservrad.
$before = count(ct_rows());
unset($GLOBALS['_JEAB_INKORG_TRACE']);
ct(jeab_inkorg_storage_mode() === 'sql', 'läget är sql före nekningen');
ct_deny(true);
$GLOBALS['_JEAB_INKORG_PDO_DROP'] = true;
jeab_inkorg_pdo_store();
$fast = [];
unset($GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP']);
try {
    $fast = jeab_inkorg_fast_prepare(false, 'list');
} catch (Throwable $e) {
    jeab_inkorg_trace_event('test.prepare_throw', $e);
}
jeab_inkorg_trace_page('list', $fast);
jeab_inkorg_trace_flush();
ct_deny(false);
$rows = ct_rows();
ct(count($rows) > $before, 'nekad anslutning skriver rader');
$wheres = array_map(static fn ($r) => (string) ($r['where'] ?? ''), array_slice($rows, $before));
ct(in_array('store_pdo.catch', $wheres, true), 'felet i jeab_inkorg_store_pdo loggas');
ct(in_array('mode_flip.sql_to_json', $wheres, true), 'bytet sql till json loggas');
$denyEnd = end($rows);
ct(($denyEnd['mode'] ?? '') === 'json' && ($denyEnd['mode_first'] ?? '') === 'sql' && (int) ($denyEnd['flips'] ?? 0) >= 1, 'slutraden visar att läget byttes');

// 4. Filflaggan sql men PDO nekad: lagret blir null, listan tom.
file_put_contents($tmp . '/privat/inkorg_storage.txt', "sql\n");
$before = count(ct_rows());
ct_deny(true);
ct_request();
ct_deny(false);
$rows = ct_rows();
$wheres = array_map(static fn ($r) => (string) ($r['where'] ?? ''), array_slice($rows, $before));
ct(in_array('pdo_store.null_pdo', $wheres, true), 'sql-flagga utan PDO loggar att lagret blev null');
@unlink($tmp . '/privat/inkorg_storage.txt');

// 5. Ingen persondata eller sökväg i filen.
$raw = (string) file_get_contents($tracePath);
ct(!str_contains($raw, $tmp) && !str_contains($raw, '@exempel.test') && !str_contains($raw, 'Kund '), 'loggen saknar sökväg, e-post och namn');
ct(count(ct_rows()) <= JEAB_INKORG_TRACE_RING, 'ringen är högst ' . JEAB_INKORG_TRACE_RING . ' rader');

// 6. Hooken: token krävs, clear=1 tömmer.
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'testnyckel-123']));
$GLOBALS['_JEAB_CRON_QUERY_TOKEN_READY'] = true;
$GLOBALS['_JEAB_CRON_QUERY_TOKEN'] = 'fel';
$denied = jeab_inkorg_trace_dispatch([]);
ct(empty($denied['ok']) && (int) ($denied['status'] ?? 0) === 401, 'data.inkorg_trace utan rätt token ger 401');
$GLOBALS['_JEAB_CRON_QUERY_TOKEN'] = 'testnyckel-123';
$read = jeab_inkorg_trace_dispatch([]);
ct(!empty($read['ok']) && (int) $read['count'] > 0 && is_array($read['rows']), 'data.inkorg_trace läser ringen');
$GLOBALS['_JEAB_CRON_QUERY_TOKEN'] = 'testnyckel-123';
$cleared = jeab_inkorg_trace_dispatch(['clear' => '1']);
ct(!empty($cleared['cleared']) && ct_rows() === [] && is_file($tracePath), 'clear=1 tömmer ringen');

// 7. Utloggad eller hook: inget skrivs.
$GLOBALS['_JEAB_AUTH_OK'] = ['' => false];
ct_request();
ct(ct_rows() === [], 'utloggad request skriver inget');
$GLOBALS['_JEAB_AUTH_OK'] = ['' => true];
$GLOBALS['JEAB_NO_SESSION'] = true;
ct_request();
ct(ct_rows() === [], 'hook-request skriver inget');
unset($GLOBALS['JEAB_NO_SESSION']);
$_GET['p'] = 'paminnelser';
ct_request();
ct(ct_rows() === [], 'annan sida skriver inget');
$_GET['p'] = 'inkorg';

// 8. Tillbaka till json: inga nya rader.
ct(jeab_settings_put(jeab_pdo_connect_sqlite(), 'sqlite', 'inkorg_storage', 'json'), 'json-flaggan sätts igen');
ct_request();
ct(ct_rows() === [], 'json-läge skriver inga nya rader');
ct(empty($GLOBALS['_JEAB_SCHEMA_DDL']), 'loggen sätter ingen DDL');

echo "OK  inkorg-spår\n";
