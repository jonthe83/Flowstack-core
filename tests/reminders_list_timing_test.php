<?php

declare(strict_types=1);

/**
 * Påminnelselistan utan sök läser inte note för hela bolaget.
 * Anteckningen hämtas för sidan som ritas. Räknarna och ordningen är desamma.
 * Diag-kroken ligger i minnet. Ingen fil, inget utskick.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-rem-list-ms-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['p' => 'reminders'];
require $root . '/src/bootstrap.php';

$fail = 0;
function rem_ms(bool $ok, string $msg): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $fail++;
    }
}

$index = (string) file_get_contents($root . '/index.php');
rem_ms(str_contains($index, 'jeab_reminders_load_company'), 'sidan laddar bolagets lista via load_company');
rem_ms(str_contains($index, 'jeab_reminders_fill_bodies'), 'sidan fyller anteckning efter sidbrytningen');
rem_ms(str_contains($index, "jeab_reminders_ensure_ort(\$store, jeab_reminders_fill_bodies(\$store, \$page['rows']), false)"), 'ort slås inte upp på sidvisningen');
rem_ms(!str_contains($index, 'mail('), 'sidan skickar inte mail');

$store = jeab_store();
rem_ms($store instanceof JeabPdoStore, 'sqlite-lager');
$pdo = $store->pdo();
$listId = (int) $pdo->query('SELECT id FROM reminder_lists ORDER BY id ASC LIMIT 1')->fetchColumn();
rem_ms($listId > 0, 'standardlistan finns');

$people = ['Roberto', 'Elin', 'Sam L', 'Niman'];
$note = str_repeat('Anteckning om taket. ', 280);
$now = jeab_now();
$today = (new DateTimeImmutable('now'))->format('Y-m-d');
$insert = $pdo->prepare(
    'INSERT INTO reminders (list_id, title, customer_name, email, phone, address, ort, assignee, assignees, company, person, flagged, tags, note, due_at, completed_at, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)'
);
$pdo->beginTransaction();
$total = 3200;
for ($i = 1; $i <= $total; $i++) {
    $person = $people[$i % 4];
    $company = $i % 17 === 0 ? 'malmo' : 'jarfalla';
    $bucket = $i % 5;
    if ($bucket === 0) {
        $due = '2026-10-01 09:00:00';
        $done = null;
    } elseif ($bucket === 1) {
        $due = $today . ' 08:00:00';
        $done = null;
    } elseif ($bucket === 2) {
        $due = '2026-12-01 09:00:00';
        $done = null;
    } else {
        $due = '2026-09-01 09:00:00';
        $done = $now;
    }
    $tags = $i % 3 === 0 ? '["sol"]' : null;
    $insert->execute([
        $listId,
        'Kundgatan ' . $i,
        'Kund ' . $i,
        'kund' . $i . '@example.test',
        '070000' . str_pad((string) ($i % 10000), 4, '0', STR_PAD_LEFT),
        'Kundgatan ' . $i . ', Järfälla',
        'Järfälla',
        $person,
        json_encode([$person], JSON_UNESCAPED_UNICODE),
        $company,
        $person,
        $tags,
        $note . ' NOTE-' . $i,
        $due,
        $done,
        $now,
        $now,
    ]);
}
$pdo->commit();

$idsOf = static function (array $rows): array {
    $ids = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $ids[] = (int) ($row['id'] ?? 0);
        }
    }

    return $ids;
};

$GLOBALS['_JEAB_REMINDER_LIST_DIAG'] = true;
$GLOBALS['_JEAB_REMINDER_LIST_DIAG_ROWS'] = [];
jeab_sql_stats_reset();
$coldT = hrtime(true);
$slim = jeab_reminders_load_company($store, 'jarfalla', '');
$coldList = (hrtime(true) - $coldT) / 1e6;
$log = jeab_sql_stats()['log'];
$listSql = '';
foreach ($log as $sql) {
    if (str_starts_with($sql, 'SELECT ') && str_contains($sql, 'FROM reminders') && !str_contains($sql, ' id IN (')) {
        $listSql = $sql;
    }
}
rem_ms($listSql !== '' && !str_contains($listSql, 'note'), 'listfrågan utan sök tar inte med note');
rem_ms(count($slim) > 1000, 'jarfälla har en stor lista (' . count($slim) . ')');
$slimMarked = 0;
foreach ($slim as $row) {
    if (is_array($row) && !empty($row['_jeab_list_slim']) && ($row['note'] ?? null) === null) {
        $slimMarked++;
    }
}
rem_ms($slimMarked === count($slim), 'alla olästa rader är markerade och saknar note');

$GLOBALS['_JEAB_REMINDER_LIST_DIAG_ROWS'] = [];
jeab_sql_stats_reset();
$fullT = hrtime(true);
$full = jeab_reminders_load_company($store, 'jarfalla', 'x');
$fullMs = (hrtime(true) - $fullT) / 1e6;
$fullSql = '';
foreach (jeab_sql_stats()['log'] as $sql) {
    if (str_starts_with($sql, 'SELECT ') && str_contains($sql, 'FROM reminders') && str_contains($sql, 'note')) {
        $fullSql = $sql;
        break;
    }
}
rem_ms($fullSql !== '' && str_contains($fullSql, 'note'), 'sök läser note');
rem_ms($idsOf($slim) === $idsOf($full), 'samma id och ordning med och utan note');

$tagNone = ['mode' => 'none', 'tag' => null];
$view = 'today';
$scopeFull = jeab_filter_reminders_scope($full, 'jarfalla', null);
$scopeSlim = jeab_filter_reminders_scope($slim, 'jarfalla', null);
$visibleFull = jeab_filter_reminders($scopeFull, $view, $tagNone, '');
$visibleSlim = jeab_filter_reminders($scopeSlim, $view, $tagNone, '');
rem_ms($idsOf($visibleFull) === $idsOf($visibleSlim), 'idag-vyn har samma rader');
$countsFull = jeab_reminder_counts($scopeFull, $tagNone, '');
$countsSlim = jeab_reminder_counts($scopeSlim, $tagNone, '');
rem_ms($countsFull === $countsSlim, 'flikarnas siffror är lika');
$whoFull = jeab_reminder_who_counts($full, 'jarfalla', $view, $tagNone, '');
$whoSlim = jeab_reminder_who_counts($slim, 'jarfalla', $view, $tagNone, '');
rem_ms($whoFull === $whoSlim, 'personräknarna är lika');
rem_ms(($whoFull['all'] ?? 0) === count($visibleFull), 'alla är samma antal som listan');
foreach (['Roberto', 'Elin', 'Sam L', 'Niman'] as $name) {
    $manual = 0;
    foreach ($visibleFull as $row) {
        if (is_array($row) && jeab_reminder_matches_who($row, $name)) {
            $manual++;
        }
    }
    rem_ms(($whoFull[$name] ?? -1) === $manual, 'räknare ' . $name . ' = ' . $manual);
}

$robertoFull = jeab_filter_reminders(jeab_filter_reminders_scope($full, 'jarfalla', 'Roberto'), $view, $tagNone, '');
$robertoSlim = jeab_filter_reminders(jeab_filter_reminders_scope($slim, 'jarfalla', 'Roberto'), $view, $tagNone, '');
rem_ms($idsOf($robertoFull) === $idsOf($robertoSlim), 'Roberto har samma idag-rader');

$tagAll = ['mode' => 'all', 'tag' => null];
$companyFull = array_values(array_filter(
    $full,
    static fn ($row): bool => is_array($row) && jeab_reminder_company($row) === 'jarfalla'
));
$expectWho = ['all' => count(jeab_filter_reminders($companyFull, 'all', $tagAll, 'kund 12'))];
foreach (['Roberto', 'Elin', 'Sam L', 'Niman'] as $name) {
    $scoped = [];
    foreach ($companyFull as $row) {
        if (jeab_reminder_matches_who($row, $name)) {
            $scoped[] = $row;
        }
    }
    $expectWho[$name] = count(jeab_filter_reminders($scoped, 'all', $tagAll, 'kund 12'));
}
$gotWho = jeab_reminder_who_counts($full, 'jarfalla', 'all', $tagAll, 'kund 12');
rem_ms($gotWho == $expectWho, 'sökräknare per person matchar ett svep per person');
rem_ms(array_keys($gotWho) === array_merge(['all'], jeab_company_names('jarfalla')), 'personordningen följer bolagets personal');

$searchRows = jeab_filter_reminders($full, 'all', ['mode' => 'all', 'tag' => null], 'note-17');
rem_ms($searchRows !== [], 'sök i anteckning träffar');
$searchSlim = jeab_filter_reminders($slim, 'all', ['mode' => 'all', 'tag' => null], 'note-17');
rem_ms($searchSlim === [], 'slim-rader utan note träffar inte anteckningssök');

$page = jeab_reminder_page($visibleSlim, $view, '', 0, 0);
rem_ms(count($page['rows']) <= 50 && count($page['rows']) > 0, 'sidan är högst 50 rader');
$GLOBALS['_JEAB_REMINDER_LIST_DIAG_ROWS'] = [];
jeab_sql_stats_reset();
$fillT = hrtime(true);
$filled = jeab_reminders_fill_bodies($store, $page['rows']);
$fillMs = (hrtime(true) - $fillT) / 1e6;
$fillSql = '';
foreach (jeab_sql_stats()['log'] as $sql) {
    if (str_contains($sql, ' id IN (')) {
        $fillSql = $sql;
    }
}
rem_ms(str_contains($fillSql, 'note') && str_contains($fillSql, ' id IN ('), 'fyllningen läser note för sidans id');
rem_ms(substr_count($fillSql, '?') === count($page['rows']), 'fyllningen frågar bara sidans id (' . count($page['rows']) . ')');
$byFull = [];
foreach ($visibleFull as $row) {
    if (is_array($row)) {
        $byFull[(int) $row['id']] = $row;
    }
}
$notesOk = true;
$flagGone = true;
foreach ($filled as $row) {
    if (!is_array($row) || isset($row['_jeab_list_slim'])) {
        $flagGone = false;
        break;
    }
    $id = (int) ($row['id'] ?? 0);
    $want = (string) ($byFull[$id]['note'] ?? '');
    if ($want === '' || (string) ($row['note'] ?? '') !== $want) {
        $notesOk = false;
        break;
    }
}
rem_ms($notesOk, 'sidans anteckningar matchar den fulla listan');
rem_ms($flagGone, 'slim-flaggan följer inte med till kortet');
$html = jeab_reminder_row($filled[0]);
$firstId = (int) ($filled[0]['id'] ?? 0);
rem_ms(str_contains($html, 'NOTE-' . $firstId), 'kortet visar anteckningen');

$GLOBALS['_JEAB_REMINDER_LIST_DIAG_ROWS'] = [];
$warmT = hrtime(true);
$again = jeab_reminders_load_company($store, 'jarfalla', '');
$scoped = jeab_filter_reminders(jeab_filter_reminders_scope($again, 'jarfalla', 'Roberto'), $view, $tagNone, '');
$page2 = jeab_reminder_page($scoped, $view, '', 0, 0);
$filled2 = jeab_reminders_fill_bodies($store, $page2['rows']);
jeab_reminder_counts($scoped, $tagNone, '');
jeab_reminder_who_counts($again, 'jarfalla', $view, $tagNone, '');
$warmMs = (hrtime(true) - $warmT) / 1e6;
rem_ms($idsOf($filled2) === $idsOf(array_slice($robertoSlim, 0, count($filled2))), 'varm lista har samma första sida');

$diag = jeab_reminder_list_diag();
rem_ms(isset($diag['list']['ms'], $diag['fill']['rows'], $diag['who']['rows']), 'diag har list, fill och who');
rem_ms(($diag['fill']['rows'] ?? 0) === count($page2['rows']), 'diag fill räknar sidans rader');
rem_ms(!is_file($tmp . '/reminders-wrote.json'), 'diag skrev ingen fil');

$coldPath = $coldList + $fillMs;
echo 'list kall ' . number_format($coldList, 1, '.', '') . " ms, fyll " . number_format($fillMs, 1, '.', '') . " ms, sökväg " . number_format($fullMs, 1, '.', '') . " ms, varm sida " . number_format($warmMs, 1, '.', '') . " ms\n";
rem_ms($coldPath < 2000, 'kall lista under 2000 ms (' . number_format($coldPath, 1, '.', '') . ')');
rem_ms($warmMs < 1000, 'varm sida under 1000 ms (' . number_format($warmMs, 1, '.', '') . ')');

echo $fail === 0 ? "\nall passed\n" : "\n{$fail} failed\n";
exit($fail === 0 ? 0 : 1);
