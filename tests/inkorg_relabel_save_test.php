<?php

declare(strict_types=1);

/**
 * Typbyte sparas i SQL utan att vänta på modellen.
 * Inlärningen ligger i kön tills tick kör den.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-relabel-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
file_put_contents($tmp . '/inkorg/messages.json', json_encode(['messages' => []], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/inkorg/threads.json', json_encode(['threads' => new stdClass()], JSON_UNESCAPED_UNICODE));
require dirname(__DIR__) . '/src/bootstrap.php';

$fail = 0;
function rl(bool $ok, string $message): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $message . "\n";
    if (!$ok) {
        $fail++;
    }
}

rl(jeab_inkorg_sql_set_storage('sql') && jeab_inkorg_storage_mode() === 'sql', 'sql-läge');

$calls = 0;
$GLOBALS['_JEAB_INKORG_LLM'] = static function () use (&$calls): string {
    $calls++;
    throw new RuntimeException('Modellen svarade inte.');
};

$ing = jeab_inkorg_ingest([
    'message_id' => '<relabel-oklassad@example.test>',
    'from' => 'kund.relabel@example.test',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Hej',
    'body_text' => 'Ett kort hej utan produkt.',
    'source' => 'graph-jarfalla',
    'received_at' => '2020-01-02T10:00:00+01:00',
    'test' => true,
]);
rl(!empty($ing['ok']), 'mejlet finns');
$id = (string) ($ing['id'] ?? '');
$tid = (string) ($ing['thread_id'] ?? '');
$before = jeab_inkorg_get($id);
rl(is_array($before) && ($before['labels']['type'] ?? '') === 'Oklassad' && ($before['labels']['reason'] ?? '') === 'Modellen svarade inte.', 'oklassad när modellen kastar');
$calls = 0;

$row = jeab_inkorg_relabel($id, ['type' => 'Skräp'], 'Test');
rl(($row['labels']['type'] ?? '') === 'Skräp' && str_starts_with((string) ($row['labels']['reason'] ?? ''), 'Ändrad av '), 'typen sparas utan modellsvaret');
rl($calls === 0, 'relabel anropar inte modellen');
$stored = jeab_inkorg_get($id);
rl(is_array($stored) && ($stored['labels']['type'] ?? '') === 'Skräp' && ($stored['labels']['reason'] ?? '') !== 'Modellen svarade inte.', 'MySQL-raden har Skräp');

$pdo = jeab_inkorg_store_pdo();
rl($pdo instanceof PDO, 'PDO är öppen');
$list = null;
if ($pdo instanceof PDO) {
    $stmt = $pdo->prepare('SELECT row_json FROM inkorg_list WHERE id = ?');
    $stmt->execute([$tid]);
    $list = json_decode((string) $stmt->fetchColumn(), true);
}
rl(is_array($list) && (($list['list_type'] ?? '') === 'Skräp' || ($list['labels']['type'] ?? '') === 'Skräp'), 'listan har Skräp utan omladdning av sidan');

$queued = 0;
if ($pdo instanceof PDO) {
    $queued = (int) $pdo->query('SELECT COUNT(*) FROM inkorg_learn_queue')->fetchColumn();
}
rl($queued === 1, 'inlärningen ligger i kön');
rl(jeab_inkorg_settings()['examples'] === [], 'exemplet skrivs inte under sparandet');

$drained = jeab_inkorg_learn_drain();
rl($drained === 1 && $calls === 0, 'tick-kön skriver exemplet även när modellen kastar');
$after = jeab_inkorg_get($id);
rl(is_array($after) && ($after['labels']['type'] ?? '') === 'Skräp', 'modellfel i kön skriver inte tillbaka Oklassad');
$examples = jeab_inkorg_settings()['examples'];
rl(count($examples) === 1 && ($examples[0]['label'] ?? '') === 'typ:Skräp', 'exemplet blir typ:Skräp');
if ($pdo instanceof PDO) {
    $left = (int) $pdo->query('SELECT COUNT(*) FROM inkorg_learn_queue')->fetchColumn();
    rl($left === 0, 'kön är tom efter tick');
}

$page = (string) file_get_contents(dirname(__DIR__) . '/src/pages/inkorg.php');
$listHtml = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg_fast.php');
$tick = (string) file_get_contents(dirname(__DIR__) . '/src/tick.php');
rl(str_contains($page, 'function ixPaintListType') && str_contains($page, 'function ixClsRestore') && str_contains($page, 'ixClearReason'), 'listan och orsaken ritas om direkt och rullas tillbaka vid sparfel');
rl(str_contains($listHtml, 'data-ix-type'), 'listraden har ett fält för typen');
rl(str_contains($tick, 'jeab_inkorg_learn_drain'), 'tick kör inlärningskön');

exit($fail > 0 ? 1 : 0);
