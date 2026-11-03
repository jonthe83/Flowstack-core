<?php

declare(strict_types=1);

/**
 * N-14: saknad räknarcache ritas som laddar (…), aldrig streck som ser ut som 0.
 * Påhittade id:n. Ingen kunddata.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-n14-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function n14(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

n14(JEAB_INKORG_COUNT_PENDING === '…', 'platshållaren är ellips, inte streck');
n14(jeab_inkorg_count_label([], 'v-inkorg') === '…', 'saknad nyckel är laddar');
n14(jeab_inkorg_count_label(['v-inkorg' => null], 'v-inkorg') === '…', 'null i cachen är laddar');
n14(jeab_inkorg_count_label(['v-inkorg' => ''], 'v-inkorg') === '…', 'tom sträng är laddar');
n14(jeab_inkorg_count_label(['v-inkorg' => 0], 'v-inkorg') === '0', 'en riktig nolla är 0');
n14(jeab_inkorg_count_label(['v-inkorg' => 12], 'v-inkorg') === '12', 'ett tal ritas som tal');
n14(jeab_inkorg_count_label(['v-inkorg' => 12], 'v-service') === '…', 'mapp utan cache är laddar, inte 0');
n14(jeab_inkorg_count_label(['v-inkorg' => 12], 'v-service') !== '–', 'saknad cache blir aldrig streck');
n14(jeab_inkorg_count_label([], 'v-historik') !== '-', 'saknad cache blir inte ASCII-streck');

$counts = ['v-inkorg' => 3];
$menu = [
    ['id' => 'v-inkorg', 'filter' => ['folder' => 'inkorg']],
    ['id' => 'v-service', 'filter' => ['folder' => 'service']],
];
$light = [
    [
        'id' => 't-n14',
        'company' => 'jarfalla',
        'status' => 'open',
        'folder' => 'service',
        'labels' => ['type' => 'Service'],
        'last' => ['from' => 'ada.n14@example.test', 'subject' => 'Service'],
        'first' => ['from' => 'ada.n14@example.test', 'subject' => 'Service'],
    ],
];
$filled = jeab_inkorg_counts_fill_missing($counts, $menu, 'jarfalla', 'me', $light);
n14((int) ($filled['v-inkorg'] ?? -1) === 3, 'befintlig cache räknas inte om');
n14(array_key_exists('v-service', $filled) && is_int($filled['v-service']), 'saknad mapp fylls med ett tal från raderna');
n14(!in_array('–', $filled, true) && !in_array('-', $filled, true), 'ifyllnaden skriver inte streck');

$fast = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg_fast.php');
$fn = strstr($fast, 'function jeab_inkorg_count_label');
$fn = is_string($fn) ? substr($fn, 0, (int) strpos($fn, 'function jeab_inkorg_counts_all_zero')) : '';
n14($fn !== '' && str_contains($fn, 'JEAB_INKORG_COUNT_PENDING') && !str_contains($fn, "'–'") && !str_contains($fn, '"–"'), 'count_label returnerar aldrig streck');
n14(str_contains($fast, 'jeab_inkorg_counts_fill_missing'), 'sidan fyller saknade nycklar från listan');

$page = (string) file_get_contents(dirname(__DIR__) . '/src/pages/inkorg.php');
n14(str_contains($page, "el.textContent = '…'") && str_contains($page, "setAttribute('aria-busy', 'true')"), 'JS-blankning är laddar med aria-busy');
n14(!str_contains($page, "el.textContent = '–'"), 'JS ritar inte streck i räknarna');

echo "OK\n";
