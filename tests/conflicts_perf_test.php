<?php

declare(strict_types=1);

/**
 * kontakter.conflicts på cirka 10 000 syntetiska rader. Torrkörningen ska bli klar på under 60 s.
 */

$tmp = sys_get_temp_dir() . '/jeab-conflicts-perf-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
file_put_contents($tmp . '/inkorg/messages.json', '{"messages":[]}');
file_put_contents($tmp . '/inkorg/threads.json', '{"threads":[]}');
file_put_contents($tmp . '/inkorg/contacts.json', '{"contacts":[]}');
file_put_contents($tmp . '/kontakter.json', '{"contacts":[]}');
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/kontakter_conflicts.php';
require_once dirname(__DIR__) . '/src/kontakter_sql_migrate.php';
require_once dirname(__DIR__) . '/src/data_jobs.php';

function perf_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
$pdo = jeab_contact_store_pdo();
perf_ok($pdo instanceof PDO, 'sqlite är öppen');
$insert = $pdo->prepare(
    'INSERT INTO contacts (id, row_json, street_key, postal_code, owner_email, owner_phone, owner_name_fold, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$pdo->beginTransaction();
$stamp = '2024-01-01 00:00:00';
$n = 0;
$add = static function (string $id, string $email, string $phone, string $street, string $postal, string $city, string $updated) use ($insert, $stamp, &$n): void {
    $row = [
        'id' => $id,
        'street' => $street,
        'postal_code' => $postal,
        'city' => $city,
        'owners' => [[
            'name' => 'Perf ' . $id,
            'personnummer' => '',
            'contact' => true,
            'email' => $email,
            'phone' => $phone,
        ]],
        'created_at' => $stamp,
        'updated_at' => $updated,
    ];
    $insert->execute([$id, json_encode($row, JSON_UNESCAPED_UNICODE), '', $postal, $email, $phone, '', $stamp, $updated]);
    $n++;
};
for ($k = 0; $k < 100; $k++) {
    $street = 'Mergatan ' . ($k + 1);
    $email = 'merge' . $k . '@example.test';
    $add('pf_m' . $k . 'a', $email, '', $street, '17741', 'Teststad', '2026-08-02 00:00:00');
    $add('pf_m' . $k . 'b', $email, '', $street, '17741', 'Teststad', '2020-02-02 00:00:00');
}
for ($k = 0; $k < 100; $k++) {
    $email = 'keep' . $k . '@example.test';
    $phone = sprintf('070%07d', 1000000 + $k);
    $add('pf_k' . $k . 'a', $email, $phone, 'Hallgatan ' . ($k + 1), '11122', 'Alfa', '2024-02-01 00:00:00');
    $add('pf_k' . $k . 'b', $email, $phone, 'Annanvagen ' . ($k + 1), '22233', 'Beta', '2024-03-01 00:00:00');
}
while ($n < 10000) {
    $i = $n;
    $add('pf_u' . $i, 'unik' . $i . '@example.test', '', 'Unikgatan ' . ($i + 1), '33344', 'Teststad', $stamp);
}
$pdo->commit();
perf_ok($n === 10000, '10 000 kontakter är insatta');
perf_ok(jeab_kontakter_sql_set_storage('sql'), 'sql är facit');
perf_ok(count(jeab_kontakter_conflicts_rows()) === 10000, 'svepet ser 10 000 rader');

$started = microtime(true);
$dry = jeab_kontakter_conflicts(['dry' => 1]);
$elapsed = microtime(true) - $started;
echo 'elapsed ' . round($elapsed, 3) . " s\n";
perf_ok($elapsed < 60, 'torrkörningen blir klar på under 60 s');
perf_ok(!empty($dry['ok']) && !empty($dry['dry']) && ($dry['written'] ?? true) === false, 'svepet är torrt');
perf_ok((int) ($dry['counts']['merge'] ?? 0) >= 100, 'samma adress slås ihop');
perf_ok((int) ($dry['counts']['keep'] ?? 0) >= 100, 'olika adress behålls');
perf_ok((int) ($dry['counts']['manual'] ?? 1) === 0, 'manual är noll');

$state = jeab_kontakter_conflicts_initial(['dry' => 1, 'limit' => 250]);
$state = jeab_kontakter_conflicts_chunk($state, $store instanceof JeabStore ? $store : null);
perf_ok(($state['phase'] ?? '') === 'postal' && (int) ($state['cursor'] ?? 0) === 250 && (int) ($state['limit'] ?? 0) === 250 && (int) ($state['total'] ?? 0) === 10000, 'första biten flyttar cursorn');
jeab_kontakter_conflicts_cache_clear();

echo "conflicts_perf_test ok\n";
