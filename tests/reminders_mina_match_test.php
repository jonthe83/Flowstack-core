<?php

declare(strict_types=1);

/**
 * Mina ska träffa id, namn och alias även när personen hör till det andra kontoret.
 * diag.mina visar högst nio tecken av kampanjkoden.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-mina-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$hash = 'boten-hash-fixture';
file_put_contents($tmp . '/passwords.json', json_encode([
    'secret' => 'test-secret-value',
    'hashes' => [['hash' => $hash, 'email' => 'boten@malmo.example.test']],
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'passwords_file' => $tmp . '/passwords.json',
    'people' => [
        ['id' => 'SamL', 'email' => 'demo@example.test', 'name' => 'Sam L', 'company' => 'jarfalla'],
        ['id' => 'BotenFlowy', 'email' => 'boten@malmo.example.test', 'name' => 'Boten Flowy', 'company' => 'malmo', 'alias' => 'boten', 'aliases' => ['Flowy']],
    ],
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('JEAB_PASSWORDS_FILE=' . $tmp . '/passwords.json');
putenv('XAI_API_KEY');
require $root . '/src/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_GET = ['p' => 'reminders', 'co' => 'jarfalla'];

function mina(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$boten = null;
foreach (jeab_people() as $row) {
    if ((string) ($row['id'] ?? '') === 'BotenFlowy') {
        $boten = $row;
    }
}
mina(is_array($boten), 'Boten Flowy finns');
$aliases = jeab_person_aliases(is_array($boten) ? $boten : []);
mina(in_array('Flowy', $aliases, true) && in_array('boten', $aliases, true), 'alias och aliases följer med');

jeab_auth_data(true);
jeab_code_people_save(jeab_auth_hash_fingerprint($hash), 'BotenFlowy', 'prefix', 'BotenFlow');
$_COOKIE['jeab_auth'] = (string) jeab_auth_issue_value('boten@malmo.example.test', $hash);
$_SESSION['jeab_email'] = 'boten@malmo.example.test';
unset($GLOBALS['_JEAB_LOGIN_IDENTITY']);

$opts = jeab_reminder_who_options('jarfalla', []);
mina(!in_array('Boten Flowy', $opts, true), 'Järfällas personlista har inte boten');
mina(jeab_who_me_name() === 'Boten Flowy', 'inloggningen är Boten Flowy, fick ' . jeab_who_me_name());
mina(jeab_reminders_sql_mina_name('jarfalla') === 'Boten Flowy', 'Mina-namnet gäller även i det andra bolaget');

$store = jeab_store();
mina($store instanceof JeabPdoStore, 'SQL-butik');
$now = jeab_now();
$store->insertReminder([
    'title' => 'id-träff',
    'company' => 'jarfalla',
    'person' => 'BotenFlowy',
    'created_at' => $now,
    'updated_at' => $now,
]);
$pdo = $store->pdo();
$list = (int) $pdo->query('SELECT id FROM reminder_lists ORDER BY id ASC LIMIT 1')->fetchColumn();
$pdo->prepare(
    'INSERT INTO reminders (list_id, title, assignee, assignees, company, person, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([$list, 'alias-träff', 'Flowy', '["Flowy"]', 'jarfalla', '', $now, $now]);

[$where, $args] = jeab_reminder_assignee_sql($pdo, 'Boten Flowy', 'jarfalla');
mina($where !== '0', 'okänd i Järfälla är inte nollträff');
$stmt = $pdo->prepare('SELECT COUNT(*) FROM reminders WHERE company = ? AND ' . $where);
$stmt->execute(array_merge(['jarfalla'], $args));
mina((int) $stmt->fetchColumn() >= 2, 'id och alias räknas i Järfälla');

$byAlias = jeab_reminder_matches_who([
    'company' => 'jarfalla',
    'person' => '',
    'assignee' => 'Flowy',
    'assignees' => ['Flowy'],
], 'Boten Flowy');
mina($byAlias, 'PHP-filtret träffar aliaset');

$path = jeab_code_people_path();
$saved = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
if (!is_array($saved)) {
    $saved = [];
}
$saved['fp:secretkampanjkod1234567890'] = [
    'person' => 'BotenFlowy',
    'via' => 'prefix',
    'at' => '2026-10-29 12:00:00',
    'head' => 'KampanjKodHemligRest',
];
file_put_contents($path, json_encode($saved, JSON_UNESCAPED_UNICODE));
$head = jeab_diag_mina_head(['head' => 'KampanjKodHemligRest'], 'fp:secretkampanjkod1234567890');
mina(strlen($head) === 9 && $head === 'KampanjKo', 'huvudet är de första 9 tecknen');
$diag = jeab_diag_mina();
$encoded = json_encode($diag, JSON_UNESCAPED_UNICODE);
mina(is_string($encoded), 'diag går att läsa');
mina(!str_contains((string) $encoded, 'HemligRest') && !str_contains((string) $encoded, 'secretkampanjkod'), 'hela koden och nyckeln läcker inte');
mina((string) ($diag['mina_name']['jarfalla'] ?? '') === 'Boten Flowy', 'diag visar Mina-namnet');
$flowy = $diag['hits']['jarfalla']['Flowy'] ?? null;
mina(is_array($flowy) && (int) ($flowy['assignees'] ?? 0) >= 1 && (string) ($flowy['error'] ?? '') === '', 'diag räknar aliaset i assignees');
$idHit = $diag['hits']['jarfalla']['BotenFlowy'] ?? null;
mina(is_array($idHit) && (int) ($idHit['person'] ?? 0) >= 1, 'diag räknar id i person');

$pdo->prepare(
    'INSERT INTO reminders (list_id, title, assignee, assignees, company, person, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([$list, 'mail-träff', '', '[]', 'jarfalla', 'boten@malmo.example.test', $now, $now]);
[$where, $args] = jeab_reminder_assignee_sql($pdo, 'Boten Flowy', 'jarfalla');
$stmt = $pdo->prepare('SELECT COUNT(*) FROM reminders WHERE company = ? AND ' . $where);
$stmt->execute(array_merge(['jarfalla'], $args));
mina((int) $stmt->fetchColumn() >= 3, 'e-posten i person räknas som Mina');
$byMail = jeab_reminder_matches_who([
    'company' => 'jarfalla',
    'person' => 'boten@malmo.example.test',
    'assignee' => '',
    'assignees' => [],
], 'Boten Flowy');
mina($byMail, 'PHP-filtret träffar e-posten');

unset($_COOKIE['jeab_auth'], $_SESSION['jeab_email'], $GLOBALS['_JEAB_LOGIN_IDENTITY'], $GLOBALS['_JEAB_LOGIN_IDENTITY_FORCE']);
$sim = jeab_diag_mina(['person' => 'BotenFlowy', 'email' => 'boten@malmo.example.test']);
$simJson = json_encode($sim, JSON_UNESCAPED_UNICODE);
mina(is_string($simJson) && !str_contains($simJson, 'boten@') && !str_contains($simJson, 'malmo.example.test'), 'simulerad diag lämnar inte ut e-post');
mina(!empty($sim['identity']['certain']) && (string) ($sim['identity']['reason'] ?? '') === 'sim-person', 'simulerad identitet är Boten, fick ' . (string) ($sim['identity']['reason'] ?? ''));
mina((string) ($sim['identity']['person_id'] ?? '') === 'BotenFlowy' && (string) ($sim['identity']['name'] ?? '') === 'Boten Flowy', 'jeab_login_identity ger Boten Flowy');
mina((int) ($sim['page_mina']['jarfalla'] ?? 0) >= 3, 'sidans Mina-villkor räknar träffarna, fick ' . (int) ($sim['page_mina']['jarfalla'] ?? 0));
$sawEmail = false;
foreach ((array) ($sim['stored']['jarfalla'] ?? []) as $row) {
    if (is_array($row) && (string) ($row['field'] ?? '') === 'person' && (string) ($row['value'] ?? '') === 'email' && (int) ($row['n'] ?? 0) >= 1) {
        $sawEmail = true;
    }
}
mina($sawEmail, 'lagrad e-post i person syns som email');

echo "OK  mina-träff\n";
