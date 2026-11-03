<?php

declare(strict_types=1);

/**
 * Vanlig kundtråd (ingen Malmö-kopia, ingen Help Scout-import, inget testmärke).
 * Den ska ritas utan undantag. Direktadressen ger HTML, aldrig JSON.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-ixcust-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/passwords.json', json_encode([
    'secret' => 'cust-thread-secret',
    'hashes' => [
        ['hash' => 'sam-hash-fixture', 'email' => 'demo@example.test'],
    ],
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
require $root . '/src/bootstrap.php';
require $root . '/src/layout.php';

function cust(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

[$like, $arg] = jeab_inkorg_reminder_note_like('id=ashraf-1');
cust($like === "note LIKE ? ESCAPE '!'", 'note-sökningen använder utropstecken som escape');
cust(!str_contains($like, '\\'), 'note-sökningen har inget bakstreck som MySQL läser som citattecken');
cust(str_contains($arg, 'id=ashraf-1'), 'ärende-id ligger i sökvärdet');

$pdo = jeab_pdo_connect_sqlite();
cust(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan går att sätta');
$store = jeab_inkorg_pdo_store();
cust($store instanceof JeabPdoInkorgStore, 'SQL-lagret är öppet');
$store->upsertMessage('ashraf-m', [
    'id' => 'ashraf-m',
    'thread_id' => 'ashraf-1',
    'from' => 'ashraf.kund@kundbrev.se',
    'from_name' => 'Ashraf Kund',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Fråga från Ashraf',
    'body' => 'Hej, jag heter Ashraf och vill ha pris på ett batteri till huset.',
    'received_at' => '2026-10-22T08:00:00+00:00',
    'source' => 'graph-jarfalla',
    'labels' => ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'batteri'],
]);
$store->upsertThread('ashraf-1', [
    'status' => 'open',
    'tags' => ['batteri'],
    'assignee' => '',
    'read' => [],
    'company' => 'jarfalla',
    'contact_id' => '',
    'updated_at' => '2026-10-22T08:00:00+00:00',
]);
$store->upsertMessage('malmo-m', [
    'id' => 'malmo-m',
    'thread_id' => 'malmo-1',
    'from' => 'malmo.kund@kundbrev.se',
    'from_name' => 'Malmö Kund',
    'to' => 'kontakt@malmo.example.test',
    'subject' => 'Fråga från Malmö',
    'body' => 'Hej, jag bor på Storgatan 1 i Malmö och vill ha hjälp.',
    'received_at' => '2026-10-22T09:00:00+00:00',
    'source' => 'helpscout',
    'labels' => ['type' => 'Kundfråga'],
]);
$store->upsertThread('malmo-1', [
    'status' => 'open',
    'tags' => [],
    'assignee' => '',
    'read' => [],
    'company' => 'malmo',
    'brand' => 'malmo',
    'contact_id' => '',
    'updated_at' => '2026-10-22T09:00:00+00:00',
]);

$app = jeab_store();
$linked = jeab_create_reminder($app, 'Ashraf Kund', '2026-10-22 09:00:00', 'Följ upp id=ashraf-1', null, [
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'email' => 'ashraf.kund@kundbrev.se',
]);
$app->saveReminder((int) $linked['id'], ['thread_id' => '']);
unset($GLOBALS['_JEAB_INKORG_THREAD_REM']);
$thread = [
    'id' => 'ashraf-1',
    'company' => 'jarfalla',
    'contact_id' => '',
    'messages' => [[
        'from' => 'ashraf.kund@kundbrev.se',
        'from_name' => 'Ashraf Kund',
        'subject' => 'Fråga från Ashraf',
        'body' => 'Hej, jag heter Ashraf och vill ha pris på ett batteri till huset.',
    ]],
    'first' => ['from' => 'ashraf.kund@kundbrev.se', 'from_name' => 'Ashraf Kund'],
    'case_class' => null,
    'tags' => ['batteri'],
];
cust(jeab_inkorg_thread_test_mark($thread) === false, 'vanlig kund är inget testmärke');
$found = jeab_inkorg_thread_reminder($thread);
cust(is_array($found) && (int) ($found['id'] ?? 0) === (int) $linked['id'], 'påminnelsen hittas utan PDOException');

putenv('JEAB_PASSWORDS_FILE=' . $tmp . '/passwords.json');
jeab_auth_data(true);
require_once $root . '/tests/sam_l_login.php';
jeab_test_login_sam_l();
$authCookie = (string) $_COOKIE['jeab_auth'];
cust($authCookie !== '', 'testkakan kan utfärdas');

$render = static function (array $get) use ($tmp, $root, $authCookie): array {
    $runner = $tmp . '/child-' . bin2hex(random_bytes(3)) . '.php';
    $export = var_export($get, true);
    $qs = http_build_query($get);
    $cookieExport = var_export($authCookie, true);
    file_put_contents($runner, <<<PHP
<?php
\$tmp = getenv('JEAB_CUST_TMP');
\$root = getenv('JEAB_ROOT');
\$GLOBALS['_JEAB_DATA_DIR'] = \$tmp;
\$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = \$tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
putenv('JEAB_PASSWORDS_FILE=' . \$tmp . '/passwords.json');
\$_COOKIE['jeab_auth'] = {$cookieExport};
\$_SERVER['REQUEST_METHOD'] = 'GET';
\$_SERVER['SCRIPT_NAME'] = '/index.php';
\$_SERVER['SCRIPT_FILENAME'] = \$root . '/index.php';
\$_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml';
\$_SERVER['REQUEST_URI'] = '/index.php?{$qs}';
\$_GET = {$export};
require \$root . '/index.php';
PHP);
    $proc = proc_open(
        [PHP_BINARY, $runner],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        ['JEAB_CUST_TMP' => $tmp, 'JEAB_ROOT' => $root, 'JEAB_DISABLE_SQLITE' => '0', 'JEAB_PASSWORDS_FILE' => $tmp . '/passwords.json']
    );
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    return ['code' => $code, 'out' => (string) $out, 'err' => (string) $err];
};

$page = $render(['p' => 'inkorg', 'box' => 'jarfalla', 'id' => 'ashraf-1']);
cust($page['code'] === 0 && trim($page['err']) === '', 'direktadress skriver inga varningar ' . trim($page['err']));
$trimmed = trim($page['out']);
cust($trimmed !== '' && json_decode($trimmed) === null && !str_starts_with($trimmed, '{'), 'direktadress är inte ett JSON-dokument');
cust(str_contains($trimmed, '<') && str_contains($trimmed, 'Fråga från Ashraf') && str_contains($trimmed, 'id="ix-conv"'), 'direktadress ritar kundtråden som HTML');
cust(!str_contains($trimmed, 'PDOException') && !str_contains($page['err'], 'PDOException'), 'direktadress kastar inte PDOException');

$part = $render(['p' => 'inkorg', 'box' => 'jarfalla', 'id' => 'ashraf-1', 'ix_part' => 'thread']);
cust($part['code'] === 0 && trim($part['err']) === '', 'ix_part=thread skriver inga varningar ' . trim($part['err']));
$json = json_decode($part['out'], true);
cust(is_array($json) && ($json['ok'] ?? false) === true && ($json['diag'] ?? '') === '', 'delanropet svarar ok utan diag');
cust(str_contains((string) ($json['html'] ?? ''), 'Fråga från Ashraf') && str_contains((string) ($json['html'] ?? ''), 'Ashraf Kund'), 'delanropet ritar samma kundtråd');

$malmo = $render(['p' => 'inkorg', 'box' => 'malmo', 'id' => 'malmo-1']);
cust($malmo['code'] === 0 && trim($malmo['err']) === '', 'Malmö-tråden skriver inga varningar ' . trim($malmo['err']));
$malmoHtml = trim($malmo['out']);
cust($malmoHtml !== '' && json_decode($malmoHtml) === null && str_contains($malmoHtml, 'Fråga från Malmö') && str_contains($malmoHtml, 'id="ix-conv"'), 'Malmö-tråden ritas som HTML');
cust(!str_contains($malmoHtml, 'PDOException') && !str_contains($malmo['err'], 'PDOException'), 'Malmö-tråden kastar inte PDOException');

echo "inkorg customer thread: ok\n";
