<?php

declare(strict_types=1);

/**
 * sent-new.json → inbox_sent_new. Deduplicering av nytt mejl per send_key.
 * Påhittade adresser. Graph anropas aldrig på riktigt.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-sent-new-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND');
putenv('XAI_API_KEY');
$passwords = $tmp . '/passwords-admin.json';
file_put_contents($passwords, json_encode([
    'secret' => 'sent-new-secret',
    'hashes' => [['hash' => 'sam-hash-fixture', 'email' => 'demo@example.test']],
], JSON_UNESCAPED_UNICODE));
putenv('JEAB_PASSWORDS_FILE=' . $passwords);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
file_put_contents($tmp . '/inkorg/messages.json', '{"messages":[]}');
file_put_contents($tmp . '/inkorg/threads.json', '{"threads":[]}');
require dirname(__DIR__) . '/src/bootstrap.php';

function sn(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$module = jeab_migrate_find('inbox_sent_new');
sn(is_array($module) && ($module['id'] ?? '') === 'inbox_sent_new' && ($module['table'] ?? '') === 'inbox_sent_new', 'modulen är registrerad i grupp C');
sn(is_array($module) && str_contains((string) ($module['schema']['sqlite'] ?? ''), 'PRIMARY KEY') && str_contains((string) ($module['schema']['mysql'] ?? ''), 'PRIMARY KEY (send_key)'), 'send_key är unik i schemat');

$pdo = jeab_data_pdo();
sn($pdo instanceof PDO, 'sqlite är öppen');
if (!$pdo instanceof PDO || !is_array($module)) {
    exit(1);
}

$source = [
    'sent' => [
        [
            'at' => '2026-05-01T12:00:00+02:00',
            'by' => 'Test',
            'company' => 'jarfalla',
            'from' => 'kontakt@jarfalla.example.test',
            'to' => 'test@example.com',
            'subject' => 'Första',
            'send_key' => 'aabbccdd',
        ],
        [
            'at' => '2026-05-01T13:00:00+02:00',
            'by' => 'Test',
            'company' => 'jarfalla',
            'from' => 'kontakt@jarfalla.example.test',
            'to' => 'other@example.com',
            'subject' => 'Andra utan nyckel',
            'send_key' => '',
        ],
    ],
];
$path = jeab_inkorg_dir() . '/sent-new.json';
file_put_contents($path, json_encode($source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$first = jeab_migrate_copy($module, false);
$c1 = (int) $pdo->query('SELECT COUNT(*) FROM inbox_sent_new')->fetchColumn();
$second = jeab_migrate_copy($module, false);
$c2 = (int) $pdo->query('SELECT COUNT(*) FROM inbox_sent_new')->fetchColumn();
sn(!empty($first['ok']) && $c1 === 2, 'importen tar in två rader');
sn(!empty($second['ok']) && $c2 === $c1, 'andra importen ger samma antal rader');
sn((int) $pdo->query("SELECT COUNT(*) FROM inbox_sent_new WHERE send_key = 'aabbccdd'")->fetchColumn() === 1, 'send_key är unik efter import');

jeab_graph_secret_save(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's']);
$sent = [];
$failSend = false;
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url) use (&$sent, &$failSend): array {
    if ($method === 'POST' && str_contains($url, 'sendMail')) {
        if ($failSend) {
            return ['code' => 500, 'body' => ['error' => ['message' => 'graph fail']]];
        }
        $sent[] = $url;

        return ['code' => 202, 'body' => []];
    }

    return ['code' => 200, 'body' => []];
};
jeab_auth_data(true);
$_COOKIE['jeab_auth'] = (string) jeab_auth_issue_value('demo@example.test', 'sam-hash-fixture');

$payload = [
    'company' => 'jarfalla',
    'to' => 'test@example.com',
    'subject' => 'Nytt mejl',
    'body' => 'Påhittad text.',
    'send_key' => 'aa11aa11',
];
$jsonBefore = (string) file_get_contents($path);
$a = jeab_inkorg_send_new($payload, 'Test');
$jsonAfter = (string) file_get_contents($path);
$n1 = count($sent);
$b = jeab_inkorg_send_new($payload, 'Test');
sn($n1 === 1 && count($sent) === 1, 'json-läge: samma send_key ger ett utskick');
sn(($a['send_key'] ?? '') === 'aa11aa11' && ($b['send_key'] ?? '') === 'aa11aa11', 'json-läge returnerar samma nyckel');
sn($jsonAfter !== $jsonBefore && str_contains($jsonAfter, 'aa11aa11'), 'json-läge skriver sent-new.json');

$GLOBALS['_JEAB_DATA_BACKEND'] = 'mysql';
putenv('DATA_BACKEND=mysql');
sn(jeab_cutover_sql('inbox_sent_new'), 'mysql-flaggan slår på SQL för sent-new');
$fileBeforeSql = (string) file_get_contents($path);
$sqlPayload = [
    'company' => 'jarfalla',
    'to' => 'test@example.com',
    'subject' => 'SQL mejl',
    'body' => 'Påhittad SQL-text.',
    'send_key' => 'bb22bb22',
];
$nBefore = count($sent);
$c = jeab_inkorg_send_new($sqlPayload, 'Test');
$d = jeab_inkorg_send_new($sqlPayload, 'Test');
sn(count($sent) === $nBefore + 1, 'sql-läge: samma send_key ger ett utskick');
sn(($c['send_key'] ?? '') === 'bb22bb22' && ($d['send_key'] ?? '') === 'bb22bb22', 'sql-läge returnerar befintlig rad vid krock');
sn((string) file_get_contents($path) === $fileBeforeSql, 'sql-läge skriver inte JSON-filen');
sn((int) $pdo->query("SELECT COUNT(*) FROM inbox_sent_new WHERE send_key = 'bb22bb22'")->fetchColumn() === 1, 'sql-läget har en rad för nyckeln');
$from = (string) $pdo->query("SELECT \"from\" FROM inbox_sent_new WHERE send_key = 'bb22bb22'")->fetchColumn();
sn($from === 'kontakt@jarfalla.example.test', 'lyckat utskick fyller i from');

$failSend = true;
$threw = false;
try {
    jeab_inkorg_send_new([
        'company' => 'jarfalla',
        'to' => 'test@example.com',
        'subject' => 'Misslyckas',
        'body' => 'Ska inte gå.',
        'send_key' => 'cc33cc33',
    ], 'Test');
} catch (Throwable) {
    $threw = true;
}
sn($threw, 'misslyckat utskick kastar');
sn((int) $pdo->query("SELECT COUNT(*) FROM inbox_sent_new WHERE send_key = 'cc33cc33'")->fetchColumn() === 0, 'misslyckat utskick frigör nyckeln');
$failSend = false;
$nFail = count($sent);
$okRetry = jeab_inkorg_send_new([
    'company' => 'jarfalla',
    'to' => 'test@example.com',
    'subject' => 'Misslyckas',
    'body' => 'Ska inte gå.',
    'send_key' => 'cc33cc33',
], 'Test');
sn(count($sent) === $nFail + 1 && ($okRetry['send_key'] ?? '') === 'cc33cc33', 'frigjord nyckel kan skickas om');
sn((string) file_get_contents($path) === $fileBeforeSql, 'sql-läge lämnar JSON-filen efter omförsök');

$GLOBALS['_JEAB_DATA_BACKEND'] = 'json';
putenv('DATA_BACKEND=json');
sn(!jeab_cutover_sql('inbox_sent_new'), 'json-flaggan går tillbaka till filen');
$fileBeforeJson = (string) file_get_contents($path);
$back = jeab_inkorg_send_new([
    'company' => 'jarfalla',
    'to' => 'test@example.com',
    'subject' => 'Tillbaka',
    'body' => 'JSON igen.',
    'send_key' => 'dd44dd44',
], 'Test');
sn(($back['send_key'] ?? '') === 'dd44dd44' && str_contains((string) file_get_contents($path), 'dd44dd44'), 'json-läge efter rollback skriver filen igen');
sn((string) file_get_contents($path) !== $fileBeforeJson, 'rollback-skrivningen ändrar JSON');

echo "klart\n";
