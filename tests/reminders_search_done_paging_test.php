<?php

declare(strict_types=1);

/**
 * Påminnelser: sök träffar klarmarkerade, Klara visas sidvis («Visa fler»)
 * och orten slås inte upp mot Lantmäteriet på varje sidladdning.
 */

$tmp = sys_get_temp_dir() . '/jeab-rem-paging-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'db' => [
        'driver' => 'json',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';

date_default_timezone_set('Europe/Stockholm');

function rp_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function rp_ids(array $rows): array
{
    return array_map(static fn(array $r): int => (int) $r['id'], array_values($rows));
}

function rp_row(int $id, string $address, string $name, ?string $completedAt = null, array $tags = []): array
{
    return [
        'id' => $id,
        'title' => $address,
        'address' => $address,
        'customer_name' => $name,
        'email' => null,
        'phone' => null,
        'note' => null,
        'tags' => $tags,
        'due_at' => '2026-10-01 09:00:00',
        'completed_at' => $completedAt,
        'created_at' => '2026-09-01 09:00:00',
        'updated_at' => '2026-09-01 09:00:00',
        'company' => 'jarfalla',
    ];
}

// --- Sök träffar även klarmarkerade ---
$rows = [
    rp_row(1, 'Björkrisvägen 14, Bromma', 'Rolf Edman', '2026-10-06 10:00:00'),
    rp_row(2, 'Marsvägen 37, Järfälla', 'Nils Axling'),
    rp_row(3, 'Edmansgatan 1, Täby', 'Öppen Kund'),
    rp_row(4, 'Gnejsvägen 15A, Täby', 'Johan Edin', '2026-10-05 10:00:00', ['vunnen']),
    rp_row(5, 'Edmanstorp 3, Bro', 'Gammal Klar', '2026-09-01 10:00:00'),
];
$all = ['mode' => 'all', 'tag' => null];
$scope = jeab_reminder_list_scope('today', ['mode' => 'none', 'tag' => null], 'edman');
rp_assert($scope['view'] === 'all', 'sök körs i vyn Alla');
$hits = jeab_filter_reminders($rows, $scope['view'], $scope['tag'], 'edman');
rp_assert(rp_ids($hits) === [3, 1, 5], 'sök träffar öppna först, sedan klara nyast först');
rp_assert(rp_ids(jeab_filter_reminders($rows, 'all', $all, 'Rolf')) === [1], 'sök på namn träffar en klarmarkerad');
rp_assert(rp_ids(jeab_filter_reminders($rows, 'all', $all, 'vunnen')) === [4], 'sök på tagg träffar en klarmarkerad');
rp_assert(rp_ids(jeab_filter_reminders($rows, 'all', $all, '')) === [2, 3, 1, 4, 5], 'Alla utan sök visar öppna först, sedan klara');
rp_assert(rp_ids(jeab_filter_reminders($rows, 'done', $all, '')) === [1, 4, 5], 'Klara utan sök oförändrad');
rp_assert(rp_ids(jeab_filter_reminders($rows, 'today', $all, 'edman')) === [3], 'Idag med sök visar inga klara');

// --- Klara sidvis ---
$done = [];
for ($i = 1; $i <= 60; $i++) {
    $done[] = rp_row(100 + $i, 'Gata ' . $i . ', Bro', 'Kund ' . $i, sprintf('2026-10-%02d 10:00:00', 1 + intdiv(60 - $i, 3)));
}
$sorted = jeab_filter_reminders($done, 'done', $all, '');
rp_assert(count($sorted) === 60, '60 klara');
$p1 = jeab_reminder_page($sorted, 'done', '', 0, 0);
rp_assert(count($p1['rows']) === JEAB_REMINDER_DONE_PAGE && $p1['total'] === 60 && $p1['next'] === 50, 'första sidan 50 av 60, nästa 50');
rp_assert(rp_ids($p1['rows']) === array_slice(rp_ids($sorted), 0, 50), 'första sidan är de 50 senaste');
$p2 = jeab_reminder_page($sorted, 'done', '', 50, JEAB_REMINDER_DONE_PAGE);
rp_assert(rp_ids($p2['rows']) === array_slice(rp_ids($sorted), 50) && $p2['next'] === null, 'sista sidan har 10 rader och ingen nästa');
$merged = array_merge(rp_ids($p1['rows']), rp_ids($p2['rows']));
rp_assert($merged === rp_ids($sorted), 'sidorna ihop = hela listan, inga dubbletter');
$n50 = jeab_reminder_page($sorted, 'done', '', 0, 50);
rp_assert(count($n50['rows']) === 50 && $n50['next'] === 50, 'n=50 (sync efter Visa fler) ger 50 rader');
$openId = (int) $sorted[55]['id'];
$withOpen = jeab_reminder_page($sorted, 'done', '', 0, 0, $openId);
rp_assert(count($withOpen['rows']) === 51 && (int) end($withOpen['rows'])['id'] === $openId && !empty(end($withOpen['rows'])['_jeab_open_extra']), 'öppen rad längre ner läggs sist');
rp_assert($withOpen['next'] === 50, 'Visa fler fortsätter vid offset 50');
$wide = jeab_reminder_page($sorted, 'done', '', 0, 148);
rp_assert(count($wide['rows']) === 50 && $wide['next'] === 50, 'n=148 ritar högst 50');
$searchPage = jeab_reminder_page($sorted, 'done', 'Kund', 0, 0);
rp_assert(count($searchPage['rows']) === 60 && $searchPage['next'] === null, 'sök sidas inte');
$allPage = jeab_reminder_page(jeab_filter_reminders($rows, 'all', $all, ''), 'all', '', 0, 0);
rp_assert(rp_ids($allPage['rows']) === [2, 3, 1, 4, 5] && $allPage['next'] === null, 'Alla sidas inte');
$small = jeab_reminder_page(array_slice($sorted, 0, 10), 'done', '', 0, 0);
rp_assert(count($small['rows']) === 10 && $small['next'] === null, 'färre än en sida: ingen Visa fler');
$beyond = jeab_reminder_page($sorted, 'done', '', 999, 25);
rp_assert($beyond['rows'] === [] && $beyond['next'] === null, 'offset förbi slutet ger tom sida');

$payload = jeab_reminder_sync_payload($p1['rows'], ['done' => 60], $p1['total'], $p1['next']);
rp_assert($payload['total'] === 60 && $payload['next'] === 50 && count($payload['items']) === 50, 'sync-svaret bär total och nästa');
rp_assert(str_contains($payload['items'][0]['html'], 'data-id="' . $p1['rows'][0]['id'] . '"'), 'sync-raden är rätt påminnelse');

// --- Ort: cache i stället för uppslag varje gång ---
$calls = [];
$GLOBALS['_JEAB_REMINDER_CITY_LOOKUP'] = static function (string $q) use (&$calls): string {
    $calls[] = $q;
    return str_starts_with($q, 'Rimfaxegränd') ? 'Järfälla' : '';
};
$store = jeab_store();
$noCity = rp_row(201, 'Rimfaxegränd 25', 'Kund A');
$noCity['ort'] = null;
$noCity2 = rp_row(202, 'Snapphanevägen 49', 'Kund B');
$noCity2['ort'] = null;
$withCity = rp_row(203, 'Björkrisvägen 14, Bromma', 'Kund C');

jeab_reminder_ort_lookup_budget(1);
$out = jeab_reminders_ensure_ort($store, [$withCity, $noCity, $noCity2]);
rp_assert(count($calls) === 1, 'högst ett uppslag per anrop');
rp_assert($out[0]['ort'] === 'Bromma', 'ort ur adressen kräver inget uppslag');
rp_assert($out[1]['ort'] === 'Järfälla', 'uppslagen ort visas');
rp_assert($out[2]['ort'] === '', 'över budget: ingen ort ännu, inget uppslag');

unset($GLOBALS['_JEAB_REMINDER_ORT_CACHE']);
jeab_reminder_ort_lookup_budget(1);
$out = jeab_reminders_ensure_ort($store, [$noCity, $noCity2]);
rp_assert($out[0]['ort'] === 'Järfälla', 'ort läses från sparad cache');
$newCalls = array_slice($calls, 1);
rp_assert($newCalls !== [] && array_filter($newCalls, static fn(string $q): bool => !str_contains($q, 'Snapphanevägen')) === [], 'bara den osparade adressen slås upp');
$callsAfter = count($calls);

unset($GLOBALS['_JEAB_REMINDER_ORT_CACHE']);
jeab_reminder_ort_lookup_budget(5);
$out = jeab_reminders_ensure_ort($store, [$noCity, $noCity2]);
rp_assert(count($calls) === $callsAfter, 'andra laddningen: inga uppslag alls (träff och miss sparade)');
rp_assert($out[0]['ort'] === 'Järfälla' && $out[1]['ort'] === '', 'samma ort som förut');
rp_assert(is_file($tmp . '/reminder-ort-cache.json'), 'cachen ligger i privat datakatalog');
rp_assert(!str_contains((string) file_get_contents($tmp . '/reminder-ort-cache.json'), 'Kund A'), 'cachen sparar inte namn i klartext');

$key = jeab_reminder_ort_cache_key($noCity2);
rp_assert(jeab_reminder_ort_cache_get($key, time() + JEAB_REMINDER_ORT_MISS_TTL + 1) === null, 'miss slås upp igen efter en vecka');
rp_assert(jeab_reminder_ort_cache_get(jeab_reminder_ort_cache_key($noCity), time() + 10 * JEAB_REMINDER_ORT_MISS_TTL) === 'Järfälla', 'hittad ort gäller tills vidare');

$listed = jeab_store()->listReminders(true);
rp_assert($listed === [], 'listning rör inte påminnelserna (ingen skrivning vid GET)');

array_map('unlink', glob($tmp . '/*') ?: []);
@rmdir($tmp);
echo "Alla tester gick igenom.\n";
