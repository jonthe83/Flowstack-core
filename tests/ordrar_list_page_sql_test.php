<?php

declare(strict_types=1);

/**
 * Ordrar-tavlan med list_json ger samma rader, ordning, filter, Slutfas
 * och räknare som när hela payload läses. Listans SELECT tar inte payload.
 * Påhittade rader. Inga personnummer.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-ordrar-list-page-' . bin2hex(random_bytes(4));
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

function olp(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function olp_ids(array $rows): array
{
    $ids = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $ids[] = (int) ($row['reminder_id'] ?? 0);
        }
    }

    return $ids;
}

function olp_pack(array $pack): array
{
    return [
        'rows' => olp_ids($pack['rows'] ?? []),
        'slutfas' => olp_ids($pack['slutfasRows'] ?? []),
        'klart' => olp_ids($pack['klartRows'] ?? []),
        'other' => olp_ids($pack['otherRows'] ?? []),
        'filters' => $pack['boardFilters'] ?? [],
        'ordrarTotal' => (int) ($pack['ordrarTotal'] ?? 0),
        'slutfasTotal' => (int) ($pack['slutfasTotal'] ?? 0),
        'klartTotal' => (int) ($pack['klartTotal'] ?? 0),
        'ordrarNext' => $pack['ordrarNext'] ?? null,
        'slutfasNext' => $pack['slutfasNext'] ?? null,
        'klartNext' => $pack['klartNext'] ?? null,
    ];
}

$GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
$pdo = jeab_purchase_sql_pdo();
olp($pdo instanceof PDO, 'SQL-läget är öppet');

$note = str_repeat('Anteckning som bara det öppna kortet ska läsa. ', 80);
$raw = str_repeat('uppslag ', 400);

jeab_purchase_put([
    'reminder_id' => 81001,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'name' => 'Sen installation',
    'huvudsokande' => 'Sen installation',
    'address' => 'Sengatan 1',
    'created_at' => '2026-01-01 10:00:00',
    'updated_at' => '2026-01-01 10:00:00',
    'steps' => ['bokad_tid' => '2026-12-20 08:00:00'],
    'install_note' => $note,
    'lookup_raw' => $raw,
]);
jeab_purchase_put([
    'reminder_id' => 81002,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'name' => 'Tidig installation',
    'huvudsokande' => 'Tidig installation',
    'address' => 'Tidiggatan 2',
    'created_at' => '2026-01-02 10:00:00',
    'updated_at' => '2026-01-02 10:00:00',
    'steps' => ['bokad_tid' => '2026-11-02 08:00:00'],
    'install_note' => $note,
    'lookup_raw' => $raw,
]);
jeab_purchase_put([
    'reminder_id' => 81003,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'name' => 'Utan datum',
    'huvudsokande' => 'Utan datum',
    'address' => 'Obokadgatan 3',
    'created_at' => '2026-08-01 10:00:00',
    'updated_at' => '2026-08-01 10:00:00',
    'steps' => [],
]);
jeab_purchase_put([
    'reminder_id' => 81004,
    'company' => 'jarfalla',
    'kind' => 'sol',
    'name' => 'Solkund',
    'huvudsokande' => 'Solkund',
    'address' => 'Solgatan 4',
    'assignee' => 'Elin',
    'assignees' => ['Elin'],
    'person' => 'Elin',
    'created_at' => '2026-05-01 10:00:00',
    'updated_at' => '2026-05-01 10:00:00',
    'steps' => ['bokad_tid' => '2026-11-01 08:00:00'],
    'install_note' => $note,
]);
jeab_purchase_put([
    'reminder_id' => 81005,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'name' => 'Slutfas',
    'huvudsokande' => 'Slutfas',
    'address' => 'Slutfasgatan 5',
    'fortnox_order' => '5007',
    'created_at' => '2026-03-01 10:00:00',
    'updated_at' => '2026-03-01 10:00:00',
    'steps' => [],
    'install_note' => $note,
]);

$base = new DateTimeImmutable('2026-02-01 00:00:00');
for ($i = 0; $i < 45; $i++) {
    $at = $base->modify('+' . $i . ' hours')->format('Y-m-d H:i:s');
    jeab_purchase_put([
        'reminder_id' => 82000 + $i,
        'company' => 'jarfalla',
        'kind' => 'laddbox',
        'name' => 'Klar ' . $i,
        'huvudsokande' => 'Klar ' . $i,
        'address' => 'Klargatan ' . $i,
        'allt_klart' => true,
        'closed_at' => $at,
        'created_at' => $at,
        'updated_at' => $at,
        'install_note' => $note,
        'lookup_raw' => $raw,
    ]);
}

jeab_settings_put($pdo, 'sqlite', 'purchases_list_index_v1', 'ok');
jeab_settings_put($pdo, 'sqlite', 'purchases_list_json_v1', 'ok');
unset($GLOBALS['_JEAB_ORDRAR_VISIBLE']);
jeab_purchase_cache_forget();
$GLOBALS['_JEAB_PURCHASE_LIST_PAYLOAD'] = 0;
$GLOBALS['_JEAB_PURCHASE_LIST_SQL_LOG'] = [];

$after = jeab_ordrar_board_collect('jarfalla', null, null, '', '', 0, 0, 0, null);
$afterIds = olp_pack($after);
olp($afterIds['rows'][0] === 81004, 'närmaste installation ligger först, fick ' . implode(',', $afterIds['rows']));
olp($afterIds['rows'][1] === 81002, 'äldre skapad order med tidigare installation går före nyare skapad');
olp(!in_array(81003, array_slice($afterIds['rows'], 0, 2), true), 'order utan datum sorteras inte före bokade');
olp(in_array(81005, $afterIds['slutfas'], true) && !in_array(81005, $afterIds['rows'], true), 'Slutfas ligger i sin sektion');
olp($afterIds['filters']['all'] === 5, 'räknaren Alla tar med Slutfas, fick ' . (int) $afterIds['filters']['all']);
olp($afterIds['klartTotal'] === 45 && count($afterIds['klart']) === 40 && $afterIds['klartNext'] === 40, 'Allt klart är en sida i SQL, fick ' . count($afterIds['klart']) . ' av ' . $afterIds['klartTotal']);
olp((int) $GLOBALS['_JEAB_PURCHASE_LIST_PAYLOAD'] === 0, 'listan läser inte payload, fick ' . (int) $GLOBALS['_JEAB_PURCHASE_LIST_PAYLOAD']);
$logged = $GLOBALS['_JEAB_PURCHASE_LIST_SQL_LOG'] ?? [];
olp(is_array($logged) && $logged !== [], 'listan körde SQL');
$sawLimit = false;
foreach ($logged as $sql) {
    olp(is_string($sql) && !str_contains($sql, 'payload'), 'list-SELECT tar inte med payload: ' . (string) $sql);
    olp(is_string($sql) && str_contains($sql, 'list_json'), 'list-SELECT läser list_json');
    if (is_string($sql) && str_contains($sql, 'LIMIT')) {
        $sawLimit = true;
    }
}
olp($sawLimit, 'Allt klart skärs med LIMIT');
$listRow = null;
foreach (array_merge($after['rows'], $after['slutfasRows']) as $row) {
    if (is_array($row) && (int) ($row['reminder_id'] ?? 0) === 81002) {
        $listRow = $row;
    }
}
olp(is_array($listRow) && !isset($listRow['install_note']) && !isset($listRow['lookup_raw']), 'tavlan har inte anteckning eller råtext');
$opened = jeab_purchase_get(81002);
olp(is_array($opened) && str_contains((string) ($opened['install_note'] ?? ''), 'Anteckning'), 'öppet kort läser hela payload');

unset($GLOBALS['_JEAB_ORDRAR_VISIBLE']);
jeab_purchase_cache_forget();
$GLOBALS['_JEAB_PURCHASE_LIST_SQL_LOG'] = [];
$GLOBALS['_JEAB_PURCHASE_LIST_PAYLOAD'] = 0;
$sol = jeab_ordrar_board_collect('jarfalla', null, 'sol', '', '', 0, 0, 0, null);
$solIds = olp_pack($sol);
olp($solIds['rows'] === [81004], 'job=sol visar bara solordern, fick ' . implode(',', $solIds['rows']));
olp($solIds['klart'] === [] && $solIds['klartTotal'] === 0, 'job=sol tar inte med klara laddboxar');
olp($solIds['filters']['all'] === 1, 'job=sol räknar en order');
$solLogged = $GLOBALS['_JEAB_PURCHASE_LIST_SQL_LOG'] ?? [];
foreach ($solLogged as $sql) {
    olp(is_string($sql) && !str_contains($sql, 'LIMIT'), 'produktfilter läser urvalet utan LIMIT, fick ' . (string) $sql);
}
olp((int) $GLOBALS['_JEAB_PURCHASE_LIST_PAYLOAD'] === 0, 'produktfiltret läser inte payload');

unset($GLOBALS['_JEAB_ORDRAR_VISIBLE']);
jeab_purchase_cache_forget();
$mine = olp_pack(jeab_ordrar_board_collect('jarfalla', 'Elin', null, '', '', 0, 0, 0, null));
olp($mine['rows'] === [81004], 'personfiltret visar Elins order, fick ' . implode(',', $mine['rows']));

unset($GLOBALS['_JEAB_ORDRAR_VISIBLE']);
jeab_purchase_cache_forget();
$page2 = olp_pack(jeab_ordrar_board_collect('jarfalla', null, null, '', '', 0, 0, 40, null));
olp(count($page2['klart']) === 5 && $page2['klartNext'] === null && $page2['klartTotal'] === 45, 'andra sidan Allt klart är resten');

$pdo->exec("UPDATE purchases SET list_json = ''");
jeab_settings_put($pdo, 'sqlite', 'purchases_list_json_v1', '');
unset($GLOBALS['_JEAB_ORDRAR_VISIBLE']);
jeab_purchase_cache_forget();
$before = olp_pack(jeab_ordrar_board_collect('jarfalla', null, null, '', '', 0, 0, 0, null));
olp($before === $afterIds, 'samma tavla när listan läser payload, fick ' . json_encode($before) . ' mot ' . json_encode($afterIds));
unset($GLOBALS['_JEAB_ORDRAR_VISIBLE']);
jeab_purchase_cache_forget();
$beforeSol = olp_pack(jeab_ordrar_board_collect('jarfalla', null, 'sol', '', '', 0, 0, 0, null));
olp($beforeSol === $solIds, 'samma job=sol när listan läser payload');
unset($GLOBALS['_JEAB_ORDRAR_VISIBLE']);
jeab_purchase_cache_forget();
$beforeMine = olp_pack(jeab_ordrar_board_collect('jarfalla', 'Elin', null, '', '', 0, 0, 0, null));
olp($beforeMine === $mine, 'samma personfilter när listan läser payload');
$fullKlart = olp_ids(jeab_ordrar_visible('jarfalla', true));
olp(array_slice($fullKlart, 0, 40) === $afterIds['klart'], 'första SQL-sidan är samma ordrar som hela payload-listan');
olp(array_slice($fullKlart, 40, 40) === $page2['klart'], 'andra SQL-sidan är resten av payload-listan');
$htmlAfter = '';
$htmlBefore = '';
foreach (jeab_ordrar_visible('jarfalla', false) as $row) {
    if (is_array($row) && (int) ($row['reminder_id'] ?? 0) === 81002) {
        $htmlBefore = jeab_ordrar_board_row_html($row, 'jarfalla', null, null);
    }
}
if (is_array($listRow)) {
    $htmlAfter = jeab_ordrar_board_row_html($listRow, 'jarfalla', null, null);
}
olp($htmlAfter !== '' && $htmlAfter === $htmlBefore, 'samma rad-HTML för list_json och payload');

echo "ordrar list page sql: ok\n";
