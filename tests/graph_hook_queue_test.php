<?php

declare(strict_types=1);

/** Graph-hookens kö ligger i SQL. Samma bolag och meddelande-id blir en rad. Ingen JSON-fil. */

$tmp = sys_get_temp_dir() . '/jeab-ghq-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
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
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ghq(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$long = str_repeat('A', 400);
jeab_graph_hook_enqueue([
    ['jarfalla', 'A1'],
    ['jarfalla', 'A1'],
    [' malmo ', 'A1'],
    ['', 'A1'],
    ['jarfalla', ''],
    ['jarfalla', $long],
]);
jeab_graph_hook_enqueue([['jarfalla', 'A1'], ['jarfalla', $long]]);

$pdo = jeab_store()->pdo();
ghq($pdo instanceof PDO, 'sqlite är öppen');
$count = (int) $pdo->query('SELECT COUNT(*) FROM graph_hook_queue')->fetchColumn();
ghq($count === 3, 'samma bolag och id skrivs en gång, fick ' . $count);
$jarfalla = (string) $pdo->query("SELECT message_id FROM graph_hook_queue WHERE company = 'jarfalla' AND length(message_id) > 100")->fetchColumn();
ghq($jarfalla === $long, 'hela Graph-id:t sparas');
ghq(!is_file($tmp . '/inkorg/graph-hook-queue.json'), 'kön skriver ingen json-fil');

$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url, ?array $body = null, array $headers = []): array {
    unset($method, $url, $body, $headers);

    return ['code' => 404, 'body' => []];
};
$done = jeab_graph_hook_drain();
$pdo = jeab_store()->pdo();
$left = (int) $pdo->query('SELECT COUNT(*) FROM graph_hook_queue')->fetchColumn();
ghq($left === 0, 'drain tömmer kön');
ghq($done === 3, 'de tre raderna lämnar kön, fick ' . $done);
ghq(!is_file($tmp . '/inkorg/graph-hook-queue.json'), 'drain skriver ingen json-fil');

echo "graph hook queue: ok\n";
