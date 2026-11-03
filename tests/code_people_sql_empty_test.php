<?php

declare(strict_types=1);

/**
 * Tom SQL-kopia av code_people ska läsas från JSON (ingen JSON-skrivning),
 * admin ska få båda kontoren, och cutover ska vägra när JSON har nycklar SQL saknar.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-code-people-sql-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');

$hashCode = password_hash('saml-personlig-kod', PASSWORD_DEFAULT);
$hashAgents = password_hash('kod-agenter', PASSWORD_DEFAULT);
file_put_contents($tmp . '/passwords.json', json_encode([
    'secret' => bin2hex(random_bytes(16)),
    'hashes' => [
        ['hash' => $hashCode],
        ['email' => 'agenter@flowstack.example.test', 'hash' => $hashAgents],
    ],
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'passwords_file' => $tmp . '/passwords.json',
    'login_people' => [
        'agenter@flowstack.example.test' => 'SamL',
        'vikarie@example.test' => 'Elin',
    ],
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/biz_doc_store.php';
require dirname(__DIR__) . '/src/biz_doc_sql_migrate.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function cp(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function cp_login(?string $email, ?string $hash): void
{
    $_GET = [];
    $_POST = [];
    $_SESSION = [];
    if ($hash === null) {
        unset($_COOKIE[JEAB_AUTH_COOKIE]);
        if ($email !== null && $email !== '') {
            $_SESSION['jeab_email'] = $email;
        }
        return;
    }
    $_COOKIE[JEAB_AUTH_COOKIE] = (string) jeab_auth_issue_value($email, $hash);
}

function cp_fellogg_hits(): int
{
    $needle = 'code_people: SQL är tom, läser JSON';
    $hits = 0;
    $rows = function_exists('jeab_log_lines_decoded') ? jeab_log_lines_decoded('app_error') : null;
    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (is_array($row) && str_contains((string) ($row['message'] ?? ''), $needle)) {
                $hits++;
            }
        }
    }
    $path = jeab_app_errors_path();
    if (is_file($path)) {
        $hits += substr_count((string) file_get_contents($path), $needle);
    }
    return $hits;
}

cp_login(null, null);
$store = jeab_store();
$stamp = jeab_now();
$reminder = $store->insertReminder([
    'title' => 'Tom SQL-kopia',
    'customer_name' => 'Ada',
    'company' => 'jarfalla',
    'person' => 'Sam L',
    'due_at' => '2026-11-02 08:00:00',
    'created_at' => $stamp,
    'updated_at' => $stamp,
]);
$reminderId = (int) ($reminder['id'] ?? 0);
cp($reminderId > 0, 'påminnelse skapad');

$fp = jeab_auth_hash_fingerprint($hashCode);
$json = [
    'fp:' . $fp => ['person' => 'SamL', 'via' => 'code', 'at' => '2026-10-01 00:00:00'],
];
$jsonPath = jeab_code_people_path();
$jsonText = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
file_put_contents($jsonPath, $jsonText);

cp(jeab_biz_doc_set_storage('code_people', 'sql') && jeab_biz_doc_storage_mode('code_people') === 'sql', 'code_people är i SQL-läge');
$pdo = jeab_biz_doc_store_pdo();
cp($pdo instanceof PDO, 'SQL-pdo finns');
jeab_biz_doc_store_ensure($pdo);
$pdo->prepare('DELETE FROM json_docs WHERE doc_key = ?')->execute([jeab_biz_doc_sql_key('code_people')]);
cp(jeab_biz_doc_sql_read('code_people') === [], 'SQL-kopian är tom');

cp_login(null, $hashCode);
$read = jeab_code_people_read();
jeab_code_people_read();
cp(($read['fp:' . $fp]['person'] ?? '') === 'SamL' && ($read['fp:' . $fp]['via'] ?? '') === 'code', 'läser SamL via=code från JSON');
cp(jeab_is_admin(), 'Sam L via kod är admin');
cp(jeab_actor_companies() === ['jarfalla', 'malmo'], 'admin får båda kontoren');
cp(cp_fellogg_hits() === 1, 'fellogg skriver en rad, fick ' . cp_fellogg_hits());
cp((string) file_get_contents($jsonPath) === $jsonText, 'JSON-filen skrivs inte');
cp(jeab_biz_doc_sql_read('code_people') === [], 'läsningen skriver inte SQL');

$dueExisting = jeab_get_reminder($store, $reminderId);
$duePosted = jeab_reminder_due_posted('2026-11-02 14:15:00');
$duePosted = jeab_reminder_due_keep_clock(
    $duePosted,
    is_array($dueExisting) ? (string) ($dueExisting['due_at'] ?? '') : null
);
$saved = jeab_update_reminder($store, $reminderId, ['due_at' => $duePosted]);
cp((string) ($saved['due_at'] ?? '') === '2026-11-02 14:15:00', 'reminder.due sparas');
cp((string) file_get_contents($jsonPath) === $jsonText && jeab_biz_doc_sql_read('code_people') === [], 'sparningen rör inte code_people');

cp_login('okand@flowstack.example.test', null);
cp(!jeab_is_admin() && jeab_actor_companies() === [], 'okopplad icke-admin får tom lista');

cp_login('agenter@flowstack.example.test', null);
cp(jeab_is_admin() && jeab_actor_companies() === ['jarfalla', 'malmo'], 'agenter@ har båda kontoren');

cp_login('saljare4@jarfalla.example.test', null);
cp(!jeab_is_admin() && jeab_actor_companies() === ['jarfalla', 'malmo'], 'Elin har Järfälla först och båda kontoren');

cp_login('vikarie@example.test', null);
cp(!jeab_is_admin() && jeab_actor_companies() === ['jarfalla', 'malmo'], 'login_people ger Elins hemma-kontor först');

cp(jeab_biz_doc_set_storage('code_people', 'json'), 'flaggan tillbaka till json');
$refused = jeab_biz_doc_sql_cutover(['entity' => 'code_people', 'enable' => 1, 'dry' => 0, 'force' => 1]);
cp(empty($refused['ok']), 'cutover vägrar, fick ' . json_encode($refused, JSON_UNESCAPED_UNICODE));
$only = (int) ($refused['verify']['docs']['code_people']['json_only'] ?? 0);
$sqlKeys = (int) ($refused['verify']['docs']['code_people']['sql_keys'] ?? -1);
$jsonKeys = (int) ($refused['verify']['docs']['code_people']['json_keys'] ?? 0);
cp($only > 0 && $sqlKeys < $jsonKeys, 'json_only=' . $only . ' sql_keys=' . $sqlKeys . ' json_keys=' . $jsonKeys);
cp(jeab_biz_doc_storage_mode('code_people') === 'json', 'flaggan stannar på json');
cp((string) file_get_contents($jsonPath) === $jsonText, 'vägrad cutover skriver inte JSON');

echo "code_people sql empty: ok\n";
