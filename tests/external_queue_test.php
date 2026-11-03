<?php

declare(strict_types=1);

/**
 * Extern kö: transaktionell SQL-tick, attempts, idempotent import, JSON-reserv.
 * Påhittade jobb. Inga personnummer.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-external-queue-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_STEG4_CUTOVER'] = true;
$GLOBALS['_JEAB_DATA_BACKEND'] = 'mysql';
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
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
require dirname(__DIR__) . '/src/bootstrap.php';

function xq(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/** @return list<int> */
function xq_ids(): array
{
    $ids = [];
    foreach (jeab_cutover_external_jobs() ?? [] as $job) {
        if (is_array($job)) {
            $ids[] = (int) ($job['payload']['id'] ?? 0);
        }
    }

    return $ids;
}

$pdo = jeab_data_pdo();
xq($pdo instanceof PDO, 'sqlite är öppen');
xq(jeab_cutover_sql('external_queue'), 'sql-läge för kön');

$pdo->exec('DROP TABLE IF EXISTS external_queue_jobs');
$pdo->exec(
    'CREATE TABLE external_queue_jobs (
        job_key TEXT PRIMARY KEY,
        kind TEXT NOT NULL,
        queued_at TEXT NOT NULL,
        seq INTEGER NOT NULL DEFAULT 0,
        payload TEXT NOT NULL
    )'
);
$module = jeab_migrate_find('external_queue');
xq(is_array($module), 'modulen finns');
jeab_migrate_ensure($pdo, $module);
$info = $pdo->query('PRAGMA table_info(external_queue_jobs)');
$cols = [];
if ($info instanceof PDOStatement) {
    foreach ($info->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cols[] = (string) ($row['name'] ?? '');
    }
}
xq(in_array('attempts', $cols, true), 'attempts läggs till på befintlig tabell');
xq(in_array('claimed_until', $cols, true), 'claimed_until läggs till på befintlig tabell');
xq(in_array('claim_token', $cols, true), 'claim_token läggs till på befintlig tabell');
jeab_migrate_ensure($pdo, $module);
xq(in_array('attempts', $cols, true), 'andra ensure är idempotent');
xq(in_array('claim_token', $cols, true), 'andra ensure lämnar claim_token');

$ran = [];
$GLOBALS['_JEAB_EXTERNAL_RUN_JOB'] = static function (array $job) use (&$ran): void {
    $ran[] = (int) ($job['payload']['id'] ?? 0);
};

for ($i = 1; $i <= 5; $i++) {
    jeab_external_enqueue('ping', ['id' => $i, 'email' => 'test@example.com']);
}
xq(!is_file(jeab_external_queue_path()), 'sql-läge skriver ingen json-fil vid enqueue');
xq(xq_ids() === [1, 2, 3, 4, 5], 'fem jobb i seq-ordning');

jeab_external_tick(jeab_store());
xq($ran === [1, 2, 3], 'tick tar de tre äldsta');
xq(xq_ids() === [4, 5], 'två jobb lämnas i rätt ordning');
xq(!is_file(jeab_external_queue_path()), 'sql-tick skriver ingen json-fil');

jeab_external_tick(jeab_store());
xq($ran === [1, 2, 3, 4, 5], 'andra tick dubbelkör inget jobb');
xq(xq_ids() === [], 'kön är tom efter två tick');

$boom = 0;
$GLOBALS['_JEAB_EXTERNAL_RUN_JOB'] = static function (array $job) use (&$boom): void {
    if ((int) ($job['payload']['id'] ?? 0) === 7) {
        $boom++;
        throw new RuntimeException('syntetiskt fel');
    }
};
jeab_external_enqueue('ping', ['id' => 7, 'email' => 'test@example.com']);
jeab_external_tick(jeab_store());
xq($boom === 1, 'felande jobb kördes en gång');
$left = jeab_cutover_external_jobs() ?? [];
xq(count($left) === 1 && (int) ($left[0]['attempts'] ?? 0) === 1, 'felande jobb läggs tillbaka med attempts=1');
jeab_external_tick(jeab_store());
xq($boom === 2, 'andra försöket');
jeab_external_tick(jeab_store());
xq($boom === 3, 'tredje försöket');
xq(xq_ids() === [], 'jobbet försvinner efter max tre försök');
jeab_external_tick(jeab_store());
xq($boom === 3, 'inga fler körningar efter max försök');

$pdo->exec('DELETE FROM external_queue_jobs');
$ran = [];
$GLOBALS['_JEAB_EXTERNAL_RUN_JOB'] = static function (array $job) use (&$ran): void {
    $ran[] = (int) ($job['payload']['id'] ?? 0);
};
jeab_external_enqueue('ping', ['id' => 21, 'email' => 'test@example.com']);
jeab_external_enqueue('ping', ['id' => 22, 'email' => 'test@example.com']);
$held = jeab_cutover_external_claim(1);
xq(count($held) === 1 && (int) ($held[0]['job']['payload']['id'] ?? 0) === 21, 'claim tar det äldsta jobbet');
$heldKey = (string) ($held[0]['job_key'] ?? '');
$heldUntil = (string) ($held[0]['claimed_until'] ?? '');
$heldToken = trim((string) ($held[0]['claim_token'] ?? ''));
xq($heldKey !== '' && $heldUntil !== '' && $heldUntil > jeab_now(), 'lease sätts 300 s framåt');
xq($heldToken !== '', 'claim sätter token');
$ran = [];
jeab_external_tick(jeab_store());
xq($ran === [22], 'pågående claim plockas inte av annat tick');
xq(xq_ids() === [21], 'claimat jobb ligger kvar under lease');

$expire = $pdo->prepare('UPDATE external_queue_jobs SET claimed_until = ? WHERE job_key = ?');
$expire->execute(['2000-01-01 00:00:00', $heldKey]);
$ran = [];
jeab_external_tick(jeab_store());
xq($ran === [21], 'claim utan avslut körs igen när lease passerat');
xq(xq_ids() === [], 'lyckad körning tar bort raden');

$pdo->exec('DELETE FROM external_queue_jobs');
jeab_external_enqueue('ping', ['id' => 41, 'email' => 'test@example.com']);
$stale = jeab_cutover_external_claim(1);
xq(count($stale) === 1, 'första claim före övertag');
$staleKey = (string) ($stale[0]['job_key'] ?? '');
$staleToken = trim((string) ($stale[0]['claim_token'] ?? ''));
xq($staleKey !== '' && $staleToken !== '', 'gammal körning har token');
$expire->execute(['2000-01-01 00:00:00', $staleKey]);
$takeover = jeab_cutover_external_claim(1);
$newToken = trim((string) ($takeover[0]['claim_token'] ?? ''));
xq(count($takeover) === 1 && $newToken !== '' && $newToken !== $staleToken, 'nytt tick tar över med ny token');
jeab_cutover_external_complete($stale[0]);
$afterComplete = $pdo->prepare('SELECT claim_token, claimed_until FROM external_queue_jobs WHERE job_key = ?');
$afterComplete->execute([$staleKey]);
$leftComplete = $afterComplete->fetch(PDO::FETCH_ASSOC);
xq(is_array($leftComplete) && (string) ($leftComplete['claim_token'] ?? '') === $newToken, 'gammal körning med utgången lease kan inte radera/släppa jobbet efter att ett nytt tick tagit över (token-mismatch)');
jeab_cutover_external_retry($stale[0]);
$afterRetry = $pdo->prepare('SELECT claim_token, claimed_until FROM external_queue_jobs WHERE job_key = ?');
$afterRetry->execute([$staleKey]);
$leftRetry = $afterRetry->fetch(PDO::FETCH_ASSOC);
xq(is_array($leftRetry) && (string) ($leftRetry['claim_token'] ?? '') === $newToken && trim((string) ($leftRetry['claimed_until'] ?? '')) !== '', 'gammal retry släpper inte övertaget jobb');

$pdo->exec('DELETE FROM external_queue_jobs');
jeab_external_enqueue('ping', ['id' => 51, 'email' => 'test@example.com']);
$renewRow = jeab_cutover_external_claim(1);
xq(count($renewRow) === 1, 'claim före förnyelse');
$renewKey = (string) ($renewRow[0]['job_key'] ?? '');
$renewToken = trim((string) ($renewRow[0]['claim_token'] ?? ''));
$renewUntil = (string) ($renewRow[0]['claimed_until'] ?? '');
$wrong = $renewRow[0];
$wrong['claim_token'] = 'deadbeefdeadbeefdeadbeefdeadbeef';
xq(jeab_cutover_external_renew($wrong) === null, 'fel token förnyar inte');
$still = $pdo->prepare('SELECT claimed_until FROM external_queue_jobs WHERE job_key = ?');
$still->execute([$renewKey]);
xq((string) $still->fetchColumn() === $renewUntil, 'förnyelse villkorad på token');
$extended = jeab_cutover_external_renew($renewRow[0]);
$afterRenew = $pdo->prepare('SELECT claimed_until FROM external_queue_jobs WHERE job_key = ?');
$afterRenew->execute([$renewKey]);
$gotUntil = (string) $afterRenew->fetchColumn();
$expectUntil = date('Y-m-d H:i:s', (int) strtotime($renewUntil) + 300);
xq(is_string($extended) && $extended === $gotUntil && $gotUntil === $expectUntil, 'rätt token förlänger leasen 300 s');
xq($renewToken !== '', 'förnyelse behåller token');

$pdo->exec('DELETE FROM external_queue_jobs');
for ($i = 61; $i <= 63; $i++) {
    jeab_external_enqueue('ping', ['id' => $i, 'email' => 'test@example.com']);
}
$during = [];
$GLOBALS['_JEAB_EXTERNAL_RUN_JOB'] = static function (array $job) use ($pdo, &$during): void {
    $id = (int) ($job['payload']['id'] ?? 0);
    $stmt = $pdo->query('SELECT claimed_until FROM external_queue_jobs ORDER BY seq ASC');
    $during[$id] = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
};
jeab_external_tick(jeab_store());
$beforeRest = (string) ($during[61][count($during[61]) - 1]['claimed_until'] ?? '');
$afterFirst = (string) ($during[62][count($during[62]) - 1]['claimed_until'] ?? '');
xq(
    $beforeRest !== '' && $afterFirst === date('Y-m-d H:i:s', (int) strtotime($beforeRest) + 300),
    'tick förnyar senare jobb efter varje körning'
);

$pdo->exec('DELETE FROM external_queue_jobs');
for ($i = 1; $i <= 40; $i++) {
    jeab_external_enqueue('ping', ['id' => 100 + $i, 'email' => 'test@example.com']);
}
$heldTrim = jeab_cutover_external_claim(3);
xq(count($heldTrim) === 3, 'tre jobb claimas före trim');
jeab_external_enqueue('ping', ['id' => 141, 'email' => 'test@example.com']);
$ids = xq_ids();
foreach ([101, 102, 103] as $id) {
    xq(in_array($id, $ids, true), 'claimat jobb ' . $id . ' trimmas inte');
}
xq(!in_array(104, $ids, true), 'äldsta oclaimade trimmas');
xq(in_array(141, $ids, true) && count($ids) === 40, 'kön är 40 och nya jobbet finns');

$pdo->exec('DELETE FROM external_queue_jobs');
$path = jeab_external_queue_path();
file_put_contents($path, json_encode([
    'jobs' => [
        ['kind' => 'ping', 'payload' => ['id' => 11, 'email' => 'test@example.com'], 'at' => 1700000011],
        ['kind' => 'ping', 'payload' => ['id' => 12, 'email' => 'test@example.com'], 'at' => 1700000012],
        ['kind' => 'ping', 'payload' => ['id' => 11, 'email' => 'test@example.com'], 'at' => 1700000011],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$first = jeab_migrate_copy($module, false);
$second = jeab_migrate_copy($module, false);
$count = (int) $pdo->query('SELECT COUNT(*) FROM external_queue_jobs')->fetchColumn();
xq(!empty($first['ok']) && (int) ($first['json_count'] ?? 0) === 2, 'import mappar två unika jobb');
xq(!empty($second['ok']) && $count === 2 && (int) ($second['sql_count'] ?? 0) === 2, 'import två gånger ger samma antal');

unset($GLOBALS['_JEAB_EXTERNAL_RUN_JOB']);
$GLOBALS['_JEAB_DATA_BACKEND'] = 'json';
putenv('DATA_BACKEND=json');
$jsonPath = jeab_external_queue_path();
@unlink($jsonPath);
jeab_external_enqueue('ping', ['id' => 99, 'email' => 'test@example.com']);
xq(is_file($jsonPath), 'json-läge skriver fil');
$raw = json_decode((string) file_get_contents($jsonPath), true);
xq(is_array($raw['jobs'] ?? null) && (int) ($raw['jobs'][0]['payload']['id'] ?? 0) === 99, 'json-kön innehåller jobbet');
$sqlCount = (int) $pdo->query('SELECT COUNT(*) FROM external_queue_jobs')->fetchColumn();
xq($sqlCount === 2, 'json-läge skriver inte SQL-kön');

$throws = 0;
$GLOBALS['_JEAB_EXTERNAL_RUN_JOB'] = static function (array $job) use (&$throws): void {
    unset($job);
    $throws++;
    throw new RuntimeException('json-fel');
};
jeab_external_tick(jeab_store());
jeab_external_tick(jeab_store());
$afterJson = json_decode((string) file_get_contents($jsonPath), true);
xq(is_array($afterJson['jobs'] ?? null) && count($afterJson['jobs']) === 1, 'json-läge lämnar jobbet kvar vid fel');

echo "external_queue_test ok\n";
