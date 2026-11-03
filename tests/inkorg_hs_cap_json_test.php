<?php

declare(strict_types=1);

/**
 * data/inkorg/hs-uncertain-open.json och corrections.jsonl när LEGACY_JSON_WRITES är av.
 * Flaggan på lämnar cachen som fil. Flaggan av skriver SQL och rör inte filen.
 * Påhittade id. Ingen kunddata.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-hs-cap-json-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE');
putenv('XAI_API_KEY');
putenv('LEGACY_JSON_WRITES');
unset($_ENV['LEGACY_JSON_WRITES'], $GLOBALS['_JEAB_LEGACY_JSON_WRITES']);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function capj(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function capj_flag(string $value): void
{
    putenv('LEGACY_JSON_WRITES=' . $value);
    $_ENV['LEGACY_JSON_WRITES'] = $value;
    unset($GLOBALS['_JEAB_LEGACY_JSON_WRITES'], $GLOBALS['_JEAB_LEGACY_JSON_VERIFY_INDEX']);
}

$path = jeab_inkorg_dir() . '/hs-uncertain-open.json';
capj(jeab_inkorg_storage_mode() === 'json', 'inkorgen är json');
capj_flag('1');
capj(jeab_legacy_json_writes() === true && jeab_inkorg_hs_cap_skip_json() === false, 'flaggan på skriver cachen');

jeab_inkorg_hs_open_cap_store($path, ['conv-1' => ['mail' => 100, 'closed' => 0, 'status' => 'open']]);
$seed = is_file($path) ? (string) file_get_contents($path) : '';
capj(str_contains($seed, 'conv-1'), 'hs-uncertain-open.json skrivs när flaggan är på');

capj_flag('0');
capj(jeab_inkorg_hs_cap_skip_json() === true, 'flaggan av hoppar över json');
jeab_inkorg_hs_open_cap_store($path, ['conv-2' => ['mail' => 200, 'closed' => 0, 'status' => 'open']]);
capj((string) file_get_contents($path) === $seed, 'flaggan av skriver inte hs-uncertain-open.json');
$loaded = jeab_inkorg_hs_cap_facts_load($path);
capj(isset($loaded['conv-1'], $loaded['conv-2']) && (int) ($loaded['conv-2']['mail'] ?? 0) === 200, 'fakta läses från SQL och tar med den gamla filen');
file_put_contents($path, '{"facts":{"conv-x":{"mail":1,"closed":0,"status":"open"}}}');
$again = jeab_inkorg_hs_cap_facts_load($path);
capj(isset($again['conv-1'], $again['conv-2']) && !isset($again['conv-x']), 'en senare läsning ignorerar den oskrivna filen');
capj((string) file_get_contents($path) === '{"facts":{"conv-x":{"mail":1,"closed":0,"status":"open"}}}', 'läsningen skriver inte tillbaka filen');

$corr = jeab_inkorg_dir() . '/corrections.jsonl';
$beforeCorr = is_file($corr) ? (string) file_get_contents($corr) : '';
jeab_inkorg_correction_write('{"id":"cap-json","how":"relabel"}');
capj((is_file($corr) ? (string) file_get_contents($corr) : '') === $beforeCorr, 'corrections.jsonl skrivs inte när flaggan är av');
$pdo = jeab_data_pdo();
capj($pdo instanceof PDO, 'SQL är öppen');
$hit = false;
if ($pdo instanceof PDO) {
    foreach ($pdo->query('SELECT line FROM inkorg_corrections') ?: [] as $row) {
        if (str_contains((string) ($row['line'] ?? ''), 'cap-json')) {
            $hit = true;
        }
    }
}
capj($hit, 'korrigeringen ligger i inkorg_corrections');

capj_flag('1');
jeab_inkorg_hs_open_cap_store($path, ['conv-3' => ['mail' => 300, 'closed' => 0, 'status' => 'open']]);
$rewritten = (string) file_get_contents($path);
capj(str_contains($rewritten, 'conv-3') && !str_contains($rewritten, 'conv-2'), 'flaggan på skriver cachen igen');

echo "ok\n";
