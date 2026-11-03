<?php

declare(strict_types=1);

/**
 * Ordrar-tavlan läser mindre och visar samma rader.
 * Påhittade rader. Inga personnummer.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-ordrar-reads-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';

function olr(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$root = dirname(__DIR__);
$index = (string) file_get_contents($root . '/index.php');
$ordrarAt = strpos($index, "if (\$page === 'ordrar')");
olr($ordrarAt !== false, 'ordrar-grenen finns');
$pageRequire = strpos($index, "require __DIR__ . '/src/pages/ordrar.php'", $ordrarAt);
olr($pageRequire !== false, 'listan ritas av ordrar-sidan');
$branch = substr($index, $ordrarAt, $pageRequire - $ordrarAt);
$csrfAt = strpos($branch, 'jeab_csrf_token()');
$releaseAt = $csrfAt === false ? false : strpos($branch, 'jeab_release_session()', $csrfAt);
olr($csrfAt !== false && $releaseAt !== false && $releaseAt > $csrfAt, 'CSRF mintas och sessionen släpps innan listan');
olr(str_contains(substr($branch, 0, (int) $releaseAt), 'jeab_filter_from_request'), 'who/job skrivs innan sessionen släpps');
olr(!str_contains($branch, 'jeab_ordrar_visible('), 'index läser inte båda listorna innan sidan');

$collectSrc = (string) file_get_contents($root . '/src/ordrar.php');
$collectAt = strpos($collectSrc, 'function jeab_ordrar_board_collect(');
olr($collectAt !== false, 'tavlan samlas i jeab_ordrar_board_collect');
$collect = substr($collectSrc, $collectAt, 5000);
$warmAt = strpos($collect, 'jeab_bookings_warm');
$sortAt = strpos($collect, 'jeab_ordrar_lista_sort');
$boardAt = strpos($collect, 'jeab_ordrar_board_filters');
olr($warmAt !== false && $sortAt !== false && $warmAt < $sortAt && $warmAt < $boardAt, 'bokningar värms innan sortering och filter');
olr(str_contains($collect, 'jeab_ordrar_visible($company, false)'), 'öppna ordrar läses fortfarande som en lista');

$cellsAt = strpos((string) file_get_contents($root . '/src/ordrar_v3.php'), 'function jeab_ordrar_lista_cells(');
olr($cellsAt !== false, 'lista_cells finns');
$cells = substr((string) file_get_contents($root . '/src/ordrar_v3.php'), $cellsAt, 500);
olr(str_contains($cells, "updated_at"), 'cellcachen nycklas på updated_at');
olr(!str_contains($cells, 'json_encode($row)'), 'cellcachen kodar inte hela ordern');
$sameId = [
    'reminder_id' => 900,
    'company' => 'jarfalla',
    'kind' => 'batteri',
    'customer' => 'Lista Testsson',
    'address' => 'Testgatan 3',
    'huvudsokande' => 'Lista Testsson',
    'created_at' => '2026-09-01 10:00:00',
    'steps' => [],
];
$withShop = $sameId;
$withShop['fortnox_order'] = '5001';
$withShop['foranmalan_status'] = 'A';
$withShop['foranmalan_case_status'] = 'skickad';
$withShop['steps'] = ['bokad_tid' => '2026-11-20 08:00', 'foranmald' => '2026-09-23', 'bestalld' => '2026-10-07'];
$withShop['aprilice_order'] = '2026104801';
$waiting = $sameId;
$waiting['fortnox_order'] = '5002';
$waiting['foranmalan_status'] = 'A';
$waiting['foranmalan_case_status'] = 'skickad';
$waiting['steps'] = ['foranmald' => '2026-09-23', 'bestalld' => '2026-10-07', 'orderrader' => '2026-10-06'];
$waiting['orderrader_saved'] = true;
$shopCells = jeab_ordrar_lista_cells($withShop);
$waitCells = jeab_ordrar_lista_cells($waiting);
olr(($shopCells['bestalld']['sub'] ?? '') !== ($waitCells['bestalld']['sub'] ?? ''), 'samma id utan updated_at delar inte celler');
olr(($waitCells['bestalld']['sub'] ?? '') === 'Väntar på Bokmalen', 'väntande beställning visar Bokmalen');

$fat = [
    'reminder_id' => 880,
    'company' => 'jarfalla',
    'kind' => 'batteri',
    'name' => 'Lista Test',
    'huvudsokande' => 'Lista Test',
    'customer_name' => 'Lista Test',
    'address' => 'Testgatan 4',
    'huvudsokande_address' => 'Testgatan 4',
    'ort' => 'Järfälla',
    'postnummer' => '17741',
    'phone' => '0700000000',
    'email' => 'lista@example.test',
    'created_at' => '2026-09-01 10:00:00',
    'updated_at' => '2026-09-02 11:00:00',
    'fortnox_order' => 'F-100',
    'steps' => ['foranmald' => '2026-09-03', 'bestalld' => '2026-09-04'],
    'foranmalan_case_status' => 'skickad',
    'booking_id' => 0,
    'person' => 'Elin',
    'install_note' => str_repeat('Anteckning som inte ska till tavlan. ', 40),
    'lookup_raw' => str_repeat('rått ', 400),
    'keep_me' => 'kvar',
];
$record = jeab_ordrar_board_record($fat);
olr(!isset($record['install_note']) && !isset($record['lookup_raw']), 'tavlan släpper anteckning och råtext');
olr(($record['keep_me'] ?? '') === 'kvar' && ($record['fortnox_order'] ?? '') === 'F-100', 'tavlan behåller fält den ritar');
$htmlFull = jeab_ordrar_board_row_html($fat, 'jarfalla', null, null);
$htmlSlim = jeab_ordrar_board_row_html($record, 'jarfalla', null, null);
olr($htmlFull === $htmlSlim, 'samma HTML för samma tavelfält');
olr(jeab_ordrar_is_slutfas($fat, null) === jeab_ordrar_is_slutfas($record, null), 'slutfas räknas likadant');
$filtersFull = jeab_ordrar_board_filters([$fat], null);
$filtersSlim = jeab_ordrar_board_filters([$record], null);
olr($filtersFull === $filtersSlim, 'samma chip-räknare');

$GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
$pdo = jeab_purchase_sql_pdo();
olr($pdo instanceof PDO, 'SQL är öppet');

jeab_purchase_put([
    'reminder_id' => 23,
    'kind' => 'sol',
    'company' => 'jarfalla',
    'name' => 'Adolfsson',
    'huvudsokande' => 'Adolfsson',
    'address' => 'Hässelby Strandväg 47',
    'orderrader_saved' => true,
    'inkop_lines' => [['sku' => 'P1', 'title' => 'Panel', 'qty' => 2, 'shop' => 'aprilice']],
    'steps' => [],
    'keep_me' => 'fore',
    'created_at' => '2026-09-01 10:00:00',
    'updated_at' => '2026-09-01 10:00:00',
]);
$stale = jeab_purchase_get(23);
olr(is_array($stale), 'order 23 finns');
jeab_purchase_patch(23, static function (array $row): array {
    $row['keep_me'] = 'efter';
    $row['fortnox_order'] = 'F-77';

    return $row;
});
$shown = jeab_ordrar_persist_known_shop_order(is_array($stale) ? $stale : []);
$stored = jeab_purchase_get(23);
olr(is_array($stored) && ($stored['keep_me'] ?? '') === 'efter', 'en senare skrivning av keep_me består');
olr(is_array($stored) && ($stored['fortnox_order'] ?? '') === 'F-77', 'Fortnox-ordern från den andra skrivningen består');
olr(is_array($stored) && ($stored['aprilice_order'] ?? '') === '2026104078', 'känt Aprilice-nummer fylls på den låsta raden');
olr(is_array($shown) && ($shown['aprilice_order'] ?? '') === '2026104078', 'öppningen visar Aprilice-numret direkt');

jeab_purchase_put([
    'reminder_id' => 24,
    'kind' => 'batteri',
    'company' => 'jarfalla',
    'name' => 'Aktiv',
    'steps' => ['foranmald' => '2026-09-05'],
    'keep_me' => 'fore',
    'created_at' => '2026-09-05 10:00:00',
    'updated_at' => '2026-09-05 10:00:00',
]);
$old = jeab_purchase_get(24);
jeab_purchase_patch(24, static function (array $row): array {
    $row['keep_me'] = 'efter';

    return $row;
});
$open = jeab_ordrar_activity_backfill(is_array($old) ? $old : []);
$after = jeab_purchase_get(24);
olr(is_array($after) && ($after['keep_me'] ?? '') === 'efter', 'aktivitetsbackfill skriver inte över ett nyare fält');
$texts = [];
foreach (jeab_ordrar_activity_list(is_array($after) ? $after : []) as $item) {
    $texts[] = (string) ($item['text'] ?? '');
}
olr(in_array('Föranmälan skickad', $texts, true), 'föranmälan ligger på den låsta raden');
olr(is_array($open) && ($open['keep_me'] ?? '') === 'fore', 'visningen behåller kopians övriga fält');

echo "ordrar list reads: ok\n";
