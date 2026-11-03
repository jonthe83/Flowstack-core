<?php

declare(strict_types=1);

/**
 * SQL-läget ska visa arbetsrader även när row_json saknas eller säger stängd,
 * och Historik ska räkna inkorg_list.company plus arkivet. Ingen list_company på listan.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-ixsql-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
require $root . '/src/bootstrap.php';
require $root . '/src/layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla'];

function ixr(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$pdo = jeab_pdo_connect_sqlite();
ixr(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan');
$store = jeab_inkorg_pdo_store();
ixr($store instanceof JeabPdoInkorgStore, 'SQL-lager');

$open = [
    'id' => 'dbl',
    'company' => 'jarfalla',
    'status' => 'open',
    'deleted' => false,
    'assignee' => '',
    'updated_at' => '2024-06-01T10:00:00+00:00',
    'customer_email' => 'dbl@exempel.test',
    'customer_name' => 'Dubbel',
    'labels' => ['type' => 'Support'],
    'tags' => ['sol'],
];
$store->saveListRow($open);
$encoded = json_encode($open, JSON_UNESCAPED_UNICODE);
$pdo->prepare('UPDATE inkorg_list SET row_json = ? WHERE id = ?')->execute([json_encode($encoded), 'dbl']);

$store->saveListRow([
    'id' => 'col-open',
    'company' => 'jarfalla',
    'status' => 'closed',
    'deleted' => false,
    'assignee' => '',
    'updated_at' => '2024-06-02T10:00:00+00:00',
    'customer_email' => 'col@exempel.test',
    'customer_name' => 'Kolumn',
    'labels' => ['type' => 'Support'],
    'tags' => [],
]);
$pdo->prepare('UPDATE inkorg_list SET list_status = ? WHERE id = ?')->execute(['open', 'col-open']);

$store->saveListRow([
    'id' => 'gone',
    'company' => 'jarfalla',
    'status' => 'open',
    'deleted' => false,
    'assignee' => '',
    'updated_at' => '2024-06-03T10:00:00+00:00',
    'customer_email' => 'gone@exempel.test',
    'customer_name' => 'Trasig',
    'labels' => ['type' => 'Support'],
    'tags' => ['sol'],
]);
$pdo->prepare('UPDATE inkorg_list SET row_json = ? WHERE id = ?')->execute(['inte-json', 'gone']);

$store->saveListRow([
    'id' => 'shut',
    'company' => 'jarfalla',
    'status' => 'closed',
    'deleted' => false,
    'assignee' => '',
    'updated_at' => '2024-05-01T10:00:00+00:00',
    'customer_email' => 'shut@exempel.test',
    'customer_name' => 'Stängd',
    'labels' => ['type' => 'Support'],
    'tags' => [],
]);

$ids = [];
foreach ($store->listWorking('jarfalla') as $row) {
    if (is_array($row)) {
        $ids[(string) ($row['id'] ?? '')] = (string) ($row['status'] ?? '');
    }
}
ixr(isset($ids['dbl']) && $ids['dbl'] === 'open', 'dubbelkodad rad syns som öppen');
ixr(isset($ids['col-open']) && $ids['col-open'] === 'open', 'list_status open vinner över JSON stängd');
ixr(isset($ids['gone']) && $ids['gone'] === 'open', 'trasig JSON byggs från kolumnen');
ixr(!isset($ids['shut']), 'stängd rad är inte en arbetsrad');

$queues = $store->queueCounts('jarfalla', '');
ixr((int) ($queues['oppna'] ?? 0) >= 3, 'Alla öppna räknar kolumnen, fick ' . json_encode($queues));

$fn = new ReflectionFunction('jeab_inkorg_historik_total_noddl');
$lines = file($fn->getFileName());
$body = implode('', array_slice($lines, $fn->getStartLine() - 1, $fn->getEndLine() - $fn->getStartLine() + 1));
ixr(!str_contains($body, 'list_company'), 'Historik-COUNT använder inte list_company');

jeab_hs_import_store_ensure($pdo);
jeab_hs_import_ensure_list_page($pdo);
$pdo->prepare(
    'INSERT INTO jeab_hs_import_index (id, payload, list_company, list_status, list_at) VALUES (?, ?, ?, ?, ?)'
)->execute(['hsi-arkiv', '{"id":"hsi-arkiv","company":"jarfalla","status":"closed"}', 'jarfalla', 'closed', '2024-01-01T00:00:00+00:00']);
$hist = jeab_inkorg_historik_total_noddl('jarfalla');
ixr($hist >= 2, 'Historik tar listan och arkivet, fick ' . $hist);

$hash = 'okand-hash-fixture';
file_put_contents($tmp . '/passwords.json', json_encode([
    'secret' => 'test-secret-value',
    'hashes' => [['hash' => $hash, 'email' => 'okand@flowstack.example.test']],
], JSON_UNESCAPED_UNICODE));
putenv('JEAB_PASSWORDS_FILE=' . $tmp . '/passwords.json');
jeab_auth_data(true);
$_COOKIE['jeab_auth'] = (string) jeab_auth_issue_value('okand@flowstack.example.test', $hash);
$_SESSION['jeab_email'] = 'okand@flowstack.example.test';
unset($GLOBALS['_JEAB_LOGIN_IDENTITY']);
$actor = jeab_actor_companies();
ixr($actor === [], 'okänd domän har inga bolag');
$light = jeab_inkorg_fast_working_light('jarfalla');
$lightIds = [];
foreach ($light as $row) {
    if (is_array($row)) {
        $lightIds[] = (string) ($row['id'] ?? '');
    }
}
ixr(in_array('dbl', $lightIds, true), 'okänd domän ser ändå Järfällas öppna rader');

jeab_inkorg_views();
$chips = jeab_inkorg_menu_counts_merge([], $light, 'jarfalla');
ixr(isset($chips['v-solceller']) && is_int($chips['v-solceller']) && $chips['v-solceller'] >= 1, 'personchippet räknas, fick ' . json_encode($chips['v-solceller'] ?? null));

$probe = jeab_inkorg_probe();
ixr(!empty($probe['ok']) && is_array($probe['queries'] ?? null) && $probe['queries'] !== [], 'proben svarar');
foreach ($probe['queries'] as $query) {
    ixr(is_array($query) && isset($query['name'], $query['ms'], $query['ok'], $query['error']), 'frågan har tid och fel');
    ixr(is_int($query['ms']) && $query['ms'] >= 0, 'tiden är millisekunder');
}
$byName = [];
foreach ($probe['queries'] as $query) {
    $byName[(string) $query['name']] = $query;
}
ixr(!empty($byName['historik.jarfalla']['ok']) && (int) ($byName['historik.jarfalla']['value'] ?? 0) >= 2, 'proben räknar samma Historik');
ixr(!empty($byName['open.jarfalla']['ok']) && (int) ($byName['open.jarfalla']['value'] ?? 0) >= 3, 'proben räknar öppna kolumner');
ixr(!empty($byName['chips.jarfalla']['ok']) && (int) (($byName['chips.jarfalla']['value']['menu']['v-solceller'] ?? 0)) >= 1, 'proben räknar chippet');

echo "OK  sql-läsning\n";
