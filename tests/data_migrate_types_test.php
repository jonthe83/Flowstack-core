<?php

declare(strict_types=1);

/**
 * data.migrate_types. Bara listade typer, torr som standard.
 * Ingen underhållsflagga, inga kontakter, inga olistade typer.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-migrate-types-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND');
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
$mall = ['ink_1' => ['id' => 'ink_1', 'name' => 'Testmall', 'kind' => 'batteri', 'lines' => []]];
$mallPath = $tmp . '/inkop-mallar.json';
file_put_contents($mallPath, json_encode($mall, JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/solkoll.json', json_encode(['api_url' => 'https://solkoll.example.test'], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/inkorg/sent-new.json', json_encode(['sent' => [[
    'at' => '2026-05-01T12:00:00+02:00',
    'by' => 'Test',
    'company' => 'jarfalla',
    'from' => 'kontakt@example.test',
    'to' => 'kund@example.test',
    'subject' => 'Torr',
    'send_key' => 'aabbccdd',
]]], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/inkorg/rules.json', json_encode(['views' => [[
    'id' => 'v-test',
    'name' => 'Testvy',
]]], JSON_UNESCAPED_UNICODE));
$contacts = ['contacts' => ['kt_keep' => ['id' => 'kt_keep', 'name' => 'Ada Adress', 'email' => 'ada.adress@example.test']]];
$contactsPath = $tmp . '/kontakter.json';
file_put_contents($contactsPath, json_encode($contacts, JSON_UNESCAPED_UNICODE));
require dirname(__DIR__) . '/src/bootstrap.php';

function mt(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function mt_table(PDO $pdo, string $table): bool
{
    $stmt = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($table));

    return $stmt instanceof PDOStatement && (bool) $stmt->fetchColumn();
}

$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
mt(str_contains($hookSrc, "'data.migrate_types'"), 'hook tar data.migrate_types');
$fnSrc = (string) file_get_contents(dirname(__DIR__) . '/src/datastore.php');
$fnAt = strpos($fnSrc, 'function jeab_data_migrate_types(');
$fnEnd = strpos($fnSrc, 'function jeab_data_migrate_types_dispatch(');
mt($fnAt !== false && $fnEnd !== false, 'funktionen finns');
$fnBody = substr($fnSrc, (int) $fnAt, (int) $fnEnd - (int) $fnAt);
mt(!str_contains($fnBody, 'jeab_kontakter_conflicts') && !str_contains($fnBody, 'jeab_data_maintenance_set') && !str_contains($fnBody, 'jeab_data_backend_set'), 'svepet rör inte konflikter, underhåll eller flagga');
$pdo = jeab_data_pdo();
mt($pdo instanceof PDO, 'sqlite är öppen');
if (!$pdo instanceof PDO) {
    exit(1);
}

$maintenance = jeab_data_maintenance();
$backend = jeab_data_backend_explicit() ? jeab_data_backend() : '';
$dry = jeab_data_migrate_types(['types' => ['inkop_mallar', 'solkoll']]);
mt(!empty($dry['ok']) && !empty($dry['dry']) && empty($dry['written']) && empty($dry['ready']), 'torrkörning skriver inte och räknas inte som klar');
mt(jeab_data_maintenance() === $maintenance && (jeab_data_backend_explicit() ? jeab_data_backend() : '') === $backend, 'torrkörning rör inte flagga eller underhåll');
mt(!mt_table($pdo, 'inkop_mallar') && !mt_table($pdo, 'solkoll_docs'), 'torrkörning skapar ingen tabell');
mt((string) file_get_contents($mallPath) === json_encode($mall, JSON_UNESCAPED_UNICODE), 'json-filen är orörd');

$missing = jeab_data_migrate_types([]);
mt(empty($missing['ok']) && in_array('types saknas.', $missing['errors'] ?? [], true), 'tom typ-lista gör inget');

$customersBefore = mt_table($pdo, 'customers')
    ? (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn()
    : 0;
$contactsBefore = mt_table($pdo, 'contacts')
    ? (int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn()
    : 0;
$blocked = jeab_data_migrate_types(['types' => ['contacts', 'customers', 'inkop_mallar'], 'dry' => 0]);
mt(empty($blocked['ok']), 'otillåtna typer stoppar svaret');
$blockedText = implode("\n", $blocked['errors'] ?? []);
mt(str_contains($blockedText, 'contacts') && str_contains($blockedText, 'Kontakter rörs inte.'), 'kontakter rörs inte');
mt(str_contains($blockedText, 'customers') && str_contains($blockedText, 'inte tillåten'), 'olistad typ kopieras inte');
$customersAfter = mt_table($pdo, 'customers')
    ? (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn()
    : 0;
$contactsAfter = mt_table($pdo, 'contacts')
    ? (int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn()
    : 0;
mt($contactsAfter === $contactsBefore && $customersAfter === $customersBefore, 'kontakter och kunder fick inga rader');
mt(mt_table($pdo, 'inkop_mallar'), 'den tillåtna typen skrevs ändå');
mt((string) file_get_contents($contactsPath) === json_encode($contacts, JSON_UNESCAPED_UNICODE), 'kontaktfilen är orörd');

$inkopCount = (int) $pdo->query('SELECT COUNT(*) FROM inkop_mallar')->fetchColumn();
mt($inkopCount === 1, 'tom tabell får raderna');
$verify = null;
foreach ($blocked['verify'] ?? [] as $row) {
    if (is_array($row) && ($row['type'] ?? '') === 'inkop_mallar') {
        $verify = $row;
    }
}
mt(is_array($verify) && !empty($verify['match']) && (int) $verify['json_count'] === (int) $verify['sql_count'] && (int) $verify['sql_count'] === 1, 'antalen stämmer');

$pdo->prepare('UPDATE inkop_mallar SET payload = ? WHERE mall_id = ?')->execute(['{"id":"ink_1","name":"SQL-facit"}', 'ink_1']);
$again = jeab_data_migrate_types(['types' => ['inkop_mallar'], 'dry' => 0]);
mt(!empty($again['ok']) && !empty($again['ready']), 'andra körningen är grön');
mt((int) $pdo->query('SELECT COUNT(*) FROM inkop_mallar')->fetchColumn() === 1, 'insert-only lägger inte dubbletter');
mt((string) $pdo->query("SELECT payload FROM inkop_mallar WHERE mall_id = 'ink_1'")->fetchColumn() === '{"id":"ink_1","name":"SQL-facit"}', 'befintlig rad skrivs inte över');
mt(jeab_data_maintenance() === $maintenance, 'underhåll är orört efter skrivning');

mt(jeab_data_maintenance_set(true) && jeab_data_maintenance(), 'underhåll slogs på av testet');
$held = jeab_data_migrate_types(['types' => ['inkorg_views'], 'dry' => 0]);
mt(jeab_data_maintenance() && !empty($held['ok']), 'svepet lämnar underhåll på');
mt(jeab_data_maintenance_set(false) && !jeab_data_maintenance(), 'testet slår av underhåll igen');

file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-migrate-types']));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = jeab_data_migrate_types_dispatch(['types' => ['solkoll'], 'dry' => 0]);
mt(empty($denied['ok']) && (int) ($denied['status'] ?? 0) === 401, 'hook utan token skriver inte');
mt(!mt_table($pdo, 'solkoll_docs'), 'nekad hook skapade inte solkoll');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-migrate-types';
$allowed = jeab_data_migrate_types_dispatch(['types' => ['inbox_sent_new', 'solkoll']]);
mt(!empty($allowed['ok']) && !empty($allowed['dry']) && (int) ($allowed['status'] ?? 0) === 200, 'hook är torr när dry saknas');
mt(!mt_table($pdo, 'solkoll_docs') && !mt_table($pdo, 'inbox_sent_new'), 'torr hook skapar ingen tabell');

echo "data_migrate_types_test ok\n";
