<?php

declare(strict_types=1);

/** Sök: ingen träff bland de egna -> träffar bland alla i bolaget, med raden om det. */

$tmp = sys_get_temp_dir() . '/jeab-search-fb-' . getmypid();
@mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export(['db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json']], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
date_default_timezone_set('Europe/Stockholm');

function sf_assert(bool $ok, string $m): void
{
    if (!$ok) { fwrite(STDERR, "FAIL {$m}\n"); exit(1); }
    echo "OK  {$m}\n";
}

$rows = [
    ['id' => 1, 'title' => 'Storgatan 1', 'customer_name' => 'Anna Andersson', 'company' => 'jarfalla', 'due_at' => null],
    ['id' => 2, 'title' => 'Lillgatan 2', 'customer_name' => 'Bertil Berg', 'company' => 'jarfalla', 'due_at' => null],
    ['id' => 3, 'title' => 'Malmövägen 3', 'customer_name' => 'Bertil Malm', 'company' => 'malmo', 'due_at' => null],
];
$hits = jeab_reminder_search_fallback($rows, 'all', 'all', 'bertil', 'jarfalla');
sf_assert(array_column($hits, 'id') === [2], 'fallback hittar bara i valt bolag');
sf_assert(jeab_reminder_search_fallback($rows, 'all', 'all', '', 'jarfalla') === [], 'tom sök ger ingen fallback');

$src = (string) file_get_contents(dirname(__DIR__) . '/src/pages/reminders.php');
sf_assert(str_contains($src, 'Ingen träff på dina egna. Vi hittade däremot följande:'), 'Påminnelser har fallback-raden');
$src = (string) file_get_contents(dirname(__DIR__) . '/src/pages/ordrar.php');
sf_assert(str_contains($src, 'Ingen träff på dina egna. Vi hittade däremot följande:'), 'Ordrar har fallback-raden');
sf_assert(str_contains($src, "searchParams.set('q', q)"), 'Ordrar: personbyte tar med söktexten');
echo "Alla tester gick igenom\n";
