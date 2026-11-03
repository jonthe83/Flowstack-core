<?php

declare(strict_types=1);

/** GI-lån i SQL (json_docs). Fingeravtryck, ingen kunddata. */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-gi-loans-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
mkdir($tmp . '/gi', 0700, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/gi_loans_sql_migrate.php';

function gl(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

gl(str_contains((string) file_get_contents(dirname(__DIR__) . '/hook.php'), 'gi_loans.sql_migrate'), 'hook tar gi_loans.sql_migrate');
gl(jeab_gi_loans_storage_mode() === 'json', 'flagga av');
gl(str_contains((string) file_get_contents(dirname(__DIR__) . '/src/gi_loans_store.php'), 'jeab_sql_store_pdo'), 'live mysql-väg finns');

$livePath = jeab_gi_loans_json_path();
file_put_contents($livePath, json_encode([
    'loans' => [[
        'id' => 'loan-live-1',
        'item' => 'Oförändrad json',
        'status' => 'active',
        'borrower' => 'Test',
        'out_at' => '2026-01-01',
    ]],
], JSON_PRETTY_PRINT) . "\n");
$liveBefore = (string) file_get_contents($livePath);
$GLOBALS['_JEAB_GI_LOANS_LEGACY_SQL'] = true;
gl(jeab_gi_loans_storage_mode() === 'sql', 'mysql live är sql utan flagga');
gl((jeab_gi_loans_load()['loans'][0]['item'] ?? '') === 'Oförändrad json', 'läser json tills sql har data');
jeab_gi_loans_mutate(static function (array &$data): null {
    $data['loans'][0]['item'] = 'Bara SQL';
    return null;
});
gl((jeab_gi_loans_sql_read()['loans'][0]['item'] ?? '') === 'Bara SQL', 'skriver sql');
gl((string) file_get_contents($livePath) === $liveBefore, 'json bara läsning');
$noop = jeab_gi_loans_sql_cutover(['enable' => 1, 'dry' => 0]);
gl(($noop['noop'] ?? '') === 'mysql_already_sql', 'cutover no-op på live');
unset($GLOBALS['_JEAB_GI_LOANS_LEGACY_SQL']);
gl(jeab_gi_loans_storage_mode() === 'json', 'sqlite utan flagga är json');

$fixture = [
    'loans' => [
        [
            'id' => 'loan-demo-1',
            'item' => 'Demo-enhet',
            'status' => 'active',
            'borrower' => 'Test',
            'out_at' => '2026-01-01',
        ],
    ],
];
$path = jeab_gi_loans_json_path();
file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT) . "\n");
$before = (string) file_get_contents($path);

$migrate = jeab_gi_loans_sql_migrate(['dry' => 0]);
gl(!empty($migrate['ok']) && ($migrate['checksum_match'] ?? false) === true, 'migrate checksum');

$verify = jeab_gi_loans_sql_verify([]);
gl(!empty($verify['ok']) && ($verify['match'] ?? false) === true, 'verify match före cutover');

jeab_gi_loans_sql_cutover(['enable' => 1, 'dry' => 0]);
jeab_gi_loans_mutate(static function (array &$data): null {
    $data['loans'][0]['item'] = 'Uppdaterad demo';
    return null;
});
$loaded = jeab_gi_loans_load();
gl(($loaded['loans'][0]['item'] ?? '') === 'Uppdaterad demo', 'sql-skrivning');
gl(!str_contains((string) file_get_contents($path), 'Uppdaterad demo'), 'json oförändrad efter sql');
gl((string) file_get_contents($path) === $before, 'ursprunglig json kvar');

echo "gi_loans_store_test ok\n";
