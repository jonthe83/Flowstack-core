<?php

declare(strict_types=1);

/**
 * Påminnelser Mina använder samma person som Egna i Inkorg (jeab_egna_person):
 * säker inloggning, annars sessionens kodperson. Lata bootstrapen för
 * reminders laddar inkorg_klassning.php. Okänd person: listan visar Alla med
 * raden "Ingen person är kopplad till din inloggning, visar alla", Mina-chippet visar 0.
 * Ingen skrivning i data/ utanför tmp, inget utskick.
 */

$root = dirname(__DIR__);
$php = getenv('PHP_BINARY') ?: PHP_BINARY;
$tmp = sys_get_temp_dir() . '/jeab-pammina-' . bin2hex(random_bytes(4));
mkdir($tmp . '/privat', 0775, true);

function pm(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

// 1) Lat bootstrap (HTTP) för reminders/reminder har Egna-personen.
foreach (['reminders', 'reminder'] as $page) {
    $code = '$GLOBALS["_JEAB_BOOT_LAZY"]=true;'
        . '$GLOBALS["_JEAB_DATA_DIR"]=' . var_export($tmp, true) . ';'
        . '$GLOBALS["_JEAB_PUBLIC_DATA_DIR"]=' . var_export($tmp, true) . ';'
        . '$GLOBALS["_JEAB_PRIVAT_DIR"]=' . var_export($tmp . '/privat', true) . ';'
        . '$_GET=["p"=>' . var_export($page, true) . '];'
        . '$_SERVER["SCRIPT_FILENAME"]=' . var_export($root . '/index.php', true) . ';'
        . 'require ' . var_export($root . '/src/bootstrap.php', true) . ';'
        . 'jeab_bootstrap_load_page_modules();'
        . 'foreach (["jeab_egna_person","jeab_reminder_mina_person","jeab_reminders_sql_mina_name"] as $f) { if (!function_exists($f)) { echo "MISSING ", $f, "\n"; } }'
        . 'echo "DONE\n";';
    $proc = proc_open([$php, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    pm(str_contains($out, 'DONE') && !str_contains($out, 'MISSING'), "p={$page} laddar jeab_egna_person i lat bootstrap " . trim($out));
}

// 2) Kodperson i sessionen ger Mina-namn och träffar person/assignee/assignees/alias.
file_put_contents($tmp . '/passwords.json', json_encode([
    'secret' => 'test-secret-value',
    'hashes' => [['hash' => 'delad-hash', 'email' => 'kontor@jarfalla.example.test']],
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'passwords_file' => $tmp . '/passwords.json',
    'people' => [
        ['id' => 'BotenFlowy', 'email' => 'boten@jarfalla.example.test', 'name' => 'Boten Flowy', 'company' => 'jarfalla', 'alias' => 'boten', 'aliases' => ['Flowy']],
        ['id' => 'AnnaB', 'email' => 'anna@jarfalla.example.test', 'name' => 'Anna B', 'company' => 'jarfalla'],
    ],
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PRIVAT_DIR'] = $tmp . '/privat';
putenv('JEAB_DISABLE_SQLITE=0');
putenv('JEAB_PASSWORDS_FILE=' . $tmp . '/passwords.json');
putenv('XAI_API_KEY');
require $root . '/src/bootstrap.php';
require_once $root . '/src/inkorg_klassning.php';
require_once $root . '/src/reminders.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_GET = ['p' => 'reminders', 'co' => 'jarfalla'];

function pm_reset(): void
{
    unset(
        $GLOBALS['_JEAB_LOGIN_IDENTITY'],
        $GLOBALS['_JEAB_LOGIN_IDENTITY_FORCE'],
        $GLOBALS['_JEAB_REMINDER_WHO_FILTER'],
        $GLOBALS['_JEAB_REMINDER_WHO_MEMO']
    );
}

unset($_COOKIE['jeab_auth'], $_SESSION['jeab_email']);
$_SESSION['jeab_code_person'] = 'BotenFlowy';
pm_reset();
pm(jeab_who_me_name() === '', 'inloggningen själv saknar person (det gamla felet gav Mina 0)');
$egna = jeab_egna_person();
pm(is_array($egna) && (string) ($egna['id'] ?? '') === 'BotenFlowy', 'Egna i Inkorg ser Boten via kodpersonen');
pm((string) (jeab_reminder_mina_person()['id'] ?? '') === 'BotenFlowy', 'Påminnelsers Mina-person = Egna-personen');
pm(jeab_reminders_sql_mina_name('jarfalla') === 'Boten Flowy', 'Mina-namnet (räknare/chip) är Boten Flowy, fick ' . jeab_reminders_sql_mina_name('jarfalla'));
pm(jeab_reminder_mina_me_name('jarfalla') === 'Boten Flowy', 'Mina-filtrets förval är Boten Flowy');

$store = jeab_store();
pm($store instanceof JeabPdoStore, 'SQL-butik');
$now = jeab_now();
$store->insertReminder(['title' => 'id', 'company' => 'jarfalla', 'person' => 'BotenFlowy', 'created_at' => $now, 'updated_at' => $now]);
$pdo = $store->pdo();
$list = (int) $pdo->query('SELECT id FROM reminder_lists ORDER BY id ASC LIMIT 1')->fetchColumn();
$ins = $pdo->prepare('INSERT INTO reminders (list_id, title, assignee, assignees, company, person, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$ins->execute([$list, 'assignee-alias', 'boten', '[]', 'jarfalla', '', $now, $now]);
$ins->execute([$list, 'assignees-alias', '', '["Anna B","Flowy"]', 'jarfalla', '', $now, $now]);
$ins->execute([$list, 'annans', 'Anna B', '["Anna B"]', 'jarfalla', 'AnnaB', $now, $now]);
[$where, $args] = jeab_reminder_assignee_sql($pdo, jeab_reminders_sql_mina_name('jarfalla'), 'jarfalla');
$stmt = $pdo->prepare('SELECT COUNT(*) FROM reminders WHERE company = ? AND ' . $where);
$stmt->execute(array_merge(['jarfalla'], $args));
pm((int) $stmt->fetchColumn() === 3, 'SQL-Mina räknar person, assignee-alias och assignees-alias men inte Annas');

$rows = [
    ['company' => 'jarfalla', 'person' => 'BotenFlowy', 'assignee' => '', 'assignees' => []],
    ['company' => 'jarfalla', 'person' => '', 'assignee' => 'boten', 'assignees' => []],
    ['company' => 'jarfalla', 'person' => '', 'assignee' => '', 'assignees' => ['Anna B', 'Flowy']],
    ['company' => 'jarfalla', 'person' => 'AnnaB', 'assignee' => 'Anna B', 'assignees' => ['Anna B']],
];
$hits = 0;
foreach ($rows as $row) {
    $hits += jeab_reminder_matches_who($row, 'Boten Flowy') ? 1 : 0;
}
pm($hits === 3, 'PHP-filtret (json-läget) träffar samma tre');

// 3) Verkligt okänd person: Mina-namnet är tomt, sidan visar Alla med raden.
unset($_SESSION['jeab_code_person']);
pm_reset();
pm(jeab_reminder_mina_person() === null && jeab_reminders_sql_mina_name('jarfalla') === '', 'okänd person ger inget Mina-namn');
pm(jeab_reminder_mina_me_name('jarfalla') === null, 'okänd person: Mina-filtret faller på Alla');

$page = (string) file_get_contents($root . '/src/pages/reminders.php');
pm(str_contains($page, 'Ingen person är kopplad till din inloggning, visar alla'), 'sidan har raden för okänd person');
pm(str_contains($page, "(\$meName !== null ? (\$whoCounts[\$meName] ?? 0) : 0)"), 'Mina-chippet visar 0 för okänd person, inte Allas tal');
pm(str_contains($page, 'class="who-scope-note" role="status" data-mina-fallback="1"'), 'raden återanvänder who-scope-note');

$boot = (string) file_get_contents($root . '/src/bootstrap.php');
pm(preg_match("/'pam_desk\\.php',\\s*(?:\\/\\/[^\\n]*\\n\\s*)?'inkorg_klassning\\.php',/", $boot) === 1, 'reminders-gruppen i bootstrap listar inkorg_klassning.php');

echo "OK  påminnelser-mina-identitet\n";
