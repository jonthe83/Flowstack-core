<?php

declare(strict_types=1);

/**
 * Varje sparväg i Kontakter med LEGACY_JSON_WRITES 0 och 1.
 * En vanlig kundadress ska gå igenom i båda lägena.
 * Oförändrad spärrad adress och inkorg+…@example.test på is_test stoppar inte övrigt.
 * Påhittade personer. Inget skickas.
 */

$mariadb = trim((string) shell_exec('command -v mariadb-install-db 2>/dev/null'));
$mariadbd = trim((string) shell_exec('command -v mariadbd 2>/dev/null'));
if ($mariadb === '' || $mariadbd === '') {
    echo "SKIP MariaDB saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-kt-flag-save-' . bin2hex(random_bytes(3));
mkdir($tmp, 0775, true);
mkdir($tmp . '/inkorg', 0775, true);
$port = 35000 + random_int(0, 1500);
$data = $tmp . '/db';
$sock = $tmp . '/mysql.sock';
$pidFile = $tmp . '/mysql.pid';
$init = $tmp . '/init.sql';
$log = $tmp . '/mysql.log';
file_put_contents($init, <<<SQL
CREATE DATABASE IF NOT EXISTS jeab CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'jeab'@'127.0.0.1' IDENTIFIED BY 'jeabpass';
GRANT ALL PRIVILEGES ON jeab.* TO 'jeab'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL);
exec(sprintf(
    'mariadb-install-db --datadir=%s --auth-root-authentication-method=normal --skip-test-db >%s 2>&1',
    escapeshellarg($data),
    escapeshellarg($log)
), $unused, $installCode);
if ($installCode !== 0) {
    fwrite(STDERR, "FAIL MariaDB kunde inte initieras\n");
    exit(1);
}
$cmd = sprintf(
    'mariadbd --datadir=%s --bind-address=127.0.0.1 --port=%d --socket=%s --pid-file=%s --init-file=%s',
    escapeshellarg($data),
    $port,
    escapeshellarg($sock),
    escapeshellarg($pidFile),
    escapeshellarg($init)
);
$proc = proc_open($cmd, [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
if (!is_resource($proc)) {
    fwrite(STDERR, "FAIL MariaDB startade inte\n");
    exit(1);
}
register_shutdown_function(static function () use ($proc): void {
    if (is_resource($proc)) {
        proc_terminate($proc);
    }
});
$up = false;
for ($i = 0; $i < 50; $i++) {
    try {
        new PDO('mysql:host=127.0.0.1;port=' . $port . ';dbname=jeab;charset=utf8mb4', 'jeab', 'jeabpass', [PDO::ATTR_TIMEOUT => 1]);
        $up = true;
        break;
    } catch (Throwable) {
        usleep(200000);
    }
}
if (!$up) {
    fwrite(STDERR, "FAIL MariaDB svarade inte\n" . (string) file_get_contents($log) . "\n");
    exit(1);
}

$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('LEGACY_JSON_WRITES=0');
$_ENV['LEGACY_JSON_WRITES'] = '0';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'mysql',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
        'mysql' => [
            'host' => '127.0.0.1;port=' . $port,
            'name' => 'jeab',
            'user' => 'jeab',
            'pass' => 'jeabpass',
            'charset' => 'utf8mb4',
        ],
    ],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

$setup = new PDO('mysql:host=127.0.0.1;port=' . $port . ';dbname=jeab;charset=utf8mb4', 'jeab', 'jeabpass');
$setup->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$schema = require dirname(__DIR__) . '/schema/mysql.php';
$schema = preg_replace('/^\s*--.*$/m', '', is_string($schema) ? $schema : '');
foreach (array_filter(array_map('trim', explode(';', (string) $schema))) as $stmt) {
    if ($stmt !== '') {
        $setup->exec($stmt);
    }
}
unset($setup);

function ksj(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function ksj_flag(string $value): void
{
    putenv('LEGACY_JSON_WRITES=' . $value);
    $_ENV['LEGACY_JSON_WRITES'] = $value;
    unset($GLOBALS['_JEAB_LEGACY_JSON_WRITES']);
}

function ksj_pnr(): string
{
    $nine = '800101123';
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $n = (int) $nine[$i];
        if ($i % 2 === 0) {
            $n *= 2;
            if ($n > 9) {
                $n -= 9;
            }
        }
        $sum += $n;
    }
    $check = (10 - ($sum % 10)) % 10;

    return '19800101-123' . $check;
}

/** @param array<string, string> $form */
function ksj_save(array $form): array
{
    try {
        $saved = jeab_kontakt_save_input($form);
    } catch (Throwable $e) {
        ksj(false, 'sparningen kastade: ' . $e->getMessage());
    }

    return is_array($saved) ? $saved : [];
}

$pdo = jeab_contact_store_pdo();
ksj($pdo instanceof PDO && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql', 'MariaDB är kontakten');
ksj(jeab_legacy_json_writes() === false, 'flaggan är av');
ksj(jeab_kontakter_storage_mode() === 'json', 'kontakter_storage är fortfarande json');
ksj(!jeab_kontakter_use_sql(), 'flaggan av lämnar kontakter i JSON');

$contactsPath = $tmp . '/kontakter.json';
$inboxPath = $tmp . '/inkorg/contacts.json';
file_put_contents($contactsPath, '{"contacts":{}}');
file_put_contents($inboxPath, '{"contacts":{}}');
$pnr = ksj_pnr();

function ksj_expect(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        ksj($e->getMessage() === 'E-postadressen tillhör inte en kund.', $message);
        return;
    }
    ksj(false, $message);
}

/** @param array<string, mixed> $row */
function ksj_plant(array $row): void
{
    jeab_kontakter_mutate(static function (array &$contacts) use ($row): void {
        $contacts[(string) $row['id']] = $row;
    });
}

$page = (string) file_get_contents(dirname(__DIR__) . '/src/pages/kontakter.php');
ksj(!str_contains($page, 'saveForm.action'), 'fellogg-adressen läser inte form.action');
ksj(substr_count($page, "saveForm.getAttribute('action') || location.href") === 2, 'båda sparningarna använder action-attributet');
ksj(str_contains($page, 'data.error') && str_contains($page, 'ktSaveNote(err && err.message'), 'feltexten visas vid Spara');
ksj(str_contains($page, 'data-kt-save-status') && str_contains($page, 'ktSaveNote(savedText(data), false)') && str_contains($page, 'SAVE_MS = 2500') && !str_contains($page, "rememberToast('Sparad.')"), 'Sparad. sitter vid knappen i 2,5 s efter serverns ok');
$index = (string) file_get_contents(dirname(__DIR__) . '/index.php');
ksj(str_contains($index, "\$action === 'kontakter.save'") && str_contains($index, "\$payload['flash'] = \$flash"), 'svaret innehåller Sparad.');
$appJs = (string) file_get_contents(dirname(__DIR__) . '/assets/js/app.js');
ksj(str_contains($appJs, 'function formPostUrl(form)') && !str_contains($appJs, '|| form.action'), 'delad sparning använder inte form.action');

$form = [
    'id' => '',
    'street' => 'Kundgatan 4',
    'postal_code' => '177 40',
    'city' => 'Järfälla',
    'fastighetsbeteckning' => 'Järfälla 1:2',
    'owner_name_1' => 'Kim Kund',
    'owner_pnr_1' => '',
    'owner_name_2' => 'Bo Kund',
    'owner_pnr_2' => '',
    'contact_person' => '1',
    'email' => 'kim.kund@example.test',
    'phone' => '0701112233',
    'ovrigt' => 'första övrigt',
];
$saved = ksj_save($form);
$id = (string) ($saved['id'] ?? '');
ksj($id !== '' && str_starts_with($id, 'kt_'), 'ny kontakt med vanlig adress får id');
$json = (string) file_get_contents($contactsPath);
ksj(str_contains($json, 'första övrigt') && str_contains($json, 'Kim Kund') && str_contains($json, 'Kundgatan 4'), 'flagga 0 skriver den vanliga kontakten till JSON');
ksj((int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn() === 0, 'flagga 0 skriver inte SQL-registret');

$form['id'] = $id;
$form['ovrigt'] = 'autospar övrigt';
$saved = ksj_save($form);
ksj((string) ($saved['ovrigt'] ?? '') === 'autospar övrigt', 'övrigt sparas');
$form['owner_name_1'] = 'Kim Ny';
$form['owner_name_2'] = 'Bo Ny';
$saved = ksj_save($form);
$owners = is_array($saved['owners'] ?? null) ? $saved['owners'] : [];
ksj(count($owners) === 2 && (string) ($owners[0]['name'] ?? '') === 'Kim Ny' && (string) ($owners[1]['name'] ?? '') === 'Bo Ny', 'båda ägarna sparas');
$form['street'] = 'Nygatan 4';
$form['postal_code'] = '111 22';
$form['city'] = 'Stockholm';
$saved = ksj_save($form);
ksj((string) ($saved['street'] ?? '') === 'Nygatan 4' && (string) ($saved['postal_code'] ?? '') === '11122' && (string) ($saved['city'] ?? '') === 'Stockholm', 'adressen sparas');
$form['fastighetsbeteckning'] = 'Stockholm 2:3';
$saved = ksj_save($form);
ksj((string) ($saved['fastighetsbeteckning'] ?? '') === 'Stockholm 2:3', 'fastighetsbeteckning sparas');
$form['owner_pnr_1'] = $pnr;
$saved = ksj_save($form);
$pnrSaved = (string) ($saved['owners'][0]['personnummer'] ?? '');
ksj($pnrSaved !== '', 'Spara skriver personnumret');
ksj(jeab_fastighet_write_contact($id, ['fastighetsbeteckning' => 'Stockholm 9:9']) === true, 'fastighetsskrivningen går igenom');
$fromReminder = jeab_reminder_save_contact_customer($id, ['name' => 'Kim Sparad', 'street' => 'Spargatan 8', 'city' => 'Solna', 'postal' => '171 65']);
ksj(is_array($fromReminder) && (string) ($fromReminder['street'] ?? '') === 'Spargatan 8', 'påminnelsens adress sparas på kontakten');
$form['street'] = 'Spargatan 8';
$form['postal_code'] = '17165';
$form['city'] = 'Solna';
$form['owner_name_1'] = 'Kim Sparad';
$form['owner_pnr_1'] = '';
$form['fastighetsbeteckning'] = 'Stockholm 9:9';
ksj_save($form);
$committed = jeab_contacts_sole_pnr_commit_hint(['id' => $id], ['name' => 'Kim Sparad', 'personnummer' => $pnr]);
$committedPnr = (string) ($committed['owners'][0]['personnummer'] ?? '');
ksj($committedPnr !== '', 'personnummer via commit sparas');
$got = jeab_kontakt_get($id);
ksj(
    is_array($got)
    && (string) ($got['ovrigt'] ?? '') === 'autospar övrigt'
    && (string) ($got['street'] ?? '') === 'Spargatan 8'
    && (string) ($got['fastighetsbeteckning'] ?? '') === 'Stockholm 9:9'
    && (string) ($got['owners'][0]['personnummer'] ?? '') === $committedPnr,
    'flagga 0 har övrigt, ägare, adress, fastighet och personnummer'
);

$inbox = jeab_inkorg_contact_save([
    'name' => 'Caj Inkorg',
    'emails' => 'caj.inkorg@example.test',
    'mobile' => '0707654321',
    'notes' => 'inkorg övrigt',
    'address' => 'Inkorgsgatan 1',
    'town' => 'Sollentuna',
]);
$inboxId = (string) ($inbox['id'] ?? '');
ksj($inboxId !== '' && str_contains((string) file_get_contents($inboxPath), 'inkorg övrigt'), 'inkorgskontakten sparas i JSON');

$blockedEmail = 'inkorg+test-sol4@example.test';
$now = jeab_now();
ksj_plant([
    'id' => 'kt_same_mail',
    'street' => 'Solgatan 4',
    'street_key' => 'solgatan 4',
    'postal_code' => '17740',
    'city' => 'Järfälla',
    'fastighetsbeteckning' => '',
    'kind' => 'person',
    'owners' => [[
        'name' => 'Sol Test',
        'personnummer' => '',
        'contact' => true,
        'email' => $blockedEmail,
        'phone' => '0701000001',
    ]],
    'created_at' => $now,
    'updated_at' => $now,
]);
$same = ksj_save([
    'id' => 'kt_same_mail',
    'street' => 'Solgatan 4',
    'postal_code' => '17740',
    'city' => 'Järfälla',
    'owner_name_1' => 'Sol Test',
    'contact_person' => '1',
    'email' => $blockedEmail,
    'phone' => '0701000001',
    'ovrigt' => 'övrigt trots spärrad adress',
]);
ksj((string) ($same['ovrigt'] ?? '') === 'övrigt trots spärrad adress', 'oförändrad spärrad adress stoppar inte övrigt');
ksj_expect(static function () use ($blockedEmail): void {
    jeab_kontakt_save_input([
        'id' => '',
        'street' => 'Solgatan 9',
        'postal_code' => '17740',
        'city' => 'Järfälla',
        'owner_name_1' => 'Ny Test',
        'contact_person' => '1',
        'email' => $blockedEmail,
        'phone' => '0701000002',
        'ovrigt' => 'ny',
    ]);
}, 'ny kontakt med testadressen stoppas');
ksj_expect(static function () use ($id, $blockedEmail): void {
    jeab_kontakt_save_input([
        'id' => $id,
        'street' => 'Spargatan 8',
        'postal_code' => '17165',
        'city' => 'Solna',
        'fastighetsbeteckning' => 'Stockholm 9:9',
        'owner_name_1' => 'Kim Sparad',
        'owner_name_2' => 'Bo Ny',
        'contact_person' => '1',
        'email' => $blockedEmail,
        'phone' => '0701112233',
        'ovrigt' => 'autospar övrigt',
    ]);
}, 'byte till testadressen stoppas utan is_test');

ksj_plant([
    'id' => 'kt_is_test',
    'street' => 'Provgatan 2',
    'street_key' => 'provgatan 2',
    'postal_code' => '17740',
    'city' => 'Järfälla',
    'fastighetsbeteckning' => '',
    'kind' => 'person',
    'is_test' => true,
    'owners' => [[
        'name' => 'Prov Test',
        'personnummer' => '',
        'contact' => true,
        'email' => 'prov@example.test',
        'phone' => '0701000003',
    ]],
    'created_at' => $now,
    'updated_at' => $now,
]);
$testSaved = ksj_save([
    'id' => 'kt_is_test',
    'street' => 'Provgatan 2',
    'postal_code' => '17740',
    'city' => 'Järfälla',
    'owner_name_1' => 'Prov Test',
    'contact_person' => '1',
    'email' => $blockedEmail,
    'phone' => '0701000003',
    'ovrigt' => 'test övrigt',
]);
ksj((string) ($testSaved['ovrigt'] ?? '') === 'test övrigt' && jeab_kontakt_row_email($testSaved) === $blockedEmail, 'is_test får jeab-plus-adressen');
ksj_expect(static function (): void {
    jeab_kontakt_save_input([
        'id' => 'kt_is_test',
        'street' => 'Provgatan 2',
        'postal_code' => '17740',
        'city' => 'Järfälla',
        'owner_name_1' => 'Prov Test',
        'contact_person' => '1',
        'email' => 'inkorg@example.test',
        'phone' => '0701000003',
        'ovrigt' => 'test övrigt',
    ]);
}, 'jeab utan plus stoppas även på is_test');

ksj_flag('1');
ksj(jeab_legacy_json_writes() === true, 'flaggan är på');
$form['owner_pnr_1'] = $committedPnr;
$form['ovrigt'] = 'båda övrigt';
$form['owner_name_2'] = 'Bo Båda';
$form['street'] = 'Bådagatan 5';
$form['postal_code'] = '123 45';
$form['city'] = 'Teststad';
$form['fastighetsbeteckning'] = 'Teststad 4:4';
$form['email'] = 'kim.kund@example.test';
$saved = ksj_save($form);
ksj((string) ($saved['ovrigt'] ?? '') === 'båda övrigt', 'flagga 1 sparar övrigt');
ksj((string) ($saved['owners'][1]['name'] ?? '') === 'Bo Båda', 'flagga 1 sparar ägare');
ksj((string) ($saved['street'] ?? '') === 'Bådagatan 5' && (string) ($saved['postal_code'] ?? '') === '12345', 'flagga 1 sparar adressen');
ksj((string) ($saved['fastighetsbeteckning'] ?? '') === 'Teststad 4:4', 'flagga 1 sparar fastighetsbeteckning');
ksj((string) ($saved['owners'][0]['personnummer'] ?? '') === $committedPnr, 'flagga 1 behåller personnumret');
ksj(jeab_fastighet_write_contact($id, ['fastighetsbeteckning' => 'Teststad 8:8']) === true, 'fastighetsskrivningen med flagga 1 går igenom');
$fromReminder = jeab_reminder_save_contact_customer($id, ['street' => 'Paminngatan 2', 'city' => 'Teststad', 'postal' => '123 45']);
ksj(is_array($fromReminder) && (string) ($fromReminder['street'] ?? '') === 'Paminngatan 2', 'påminnelsens adress sparas med flagga 1');
$inbox = jeab_inkorg_contact_save([
    'id' => $inboxId,
    'name' => 'Caj Inkorg',
    'emails' => 'caj.inkorg@example.test',
    'mobile' => '0707654321',
    'notes' => 'inkorg båda',
    'address' => 'Inkorgsgatan 2',
    'town' => 'Teststad',
]);
ksj((string) ($inbox['notes'] ?? '') === 'inkorg båda' && str_contains((string) file_get_contents($inboxPath), 'Inkorgsgatan 2'), 'inkorgskontakten sparas med flagga 1');
$again = jeab_kontakt_get($id);
ksj(is_array($again) && (string) ($again['ovrigt'] ?? '') === 'båda övrigt' && (string) ($again['fastighetsbeteckning'] ?? '') === 'Teststad 8:8', 'flagga 1 går att läsa tillbaka');

echo "kontakter legacy json save ok\n";
