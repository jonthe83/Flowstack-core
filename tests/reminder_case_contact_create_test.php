<?php

declare(strict_types=1);

/**
 * Påminnelse från ett inkorgsärende och från en kontakt.
 * Kundfälten läses från Kontakter och lagras inte på påminnelsen.
 * Ingen offert och inget mejl. Påhittade kunder.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-case-rem-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'test-incoming-token',
    'outgoing_webhook_url' => '',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
        'mysql' => [
            'host' => '127.0.0.1',
            'name' => 'jeab_test',
            'user' => 'jeab_test',
            'pass' => 'jeab-test-pass',
            'charset' => 'utf8mb4',
        ],
    ],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/layout.php';

if (!jeab_settings_put(jeab_pdo_connect_sqlite(), 'sqlite', 'inkorg_storage', 'sql')) {
    fwrite(STDERR, "FAIL sql-flaggan går inte att sätta\n");
    exit(1);
}
$ixStore = jeab_inkorg_pdo_store();
if (!$ixStore instanceof JeabPdoInkorgStore) {
    fwrite(STDERR, "FAIL SQL-lagret är inte öppet\n");
    exit(1);
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function rc(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$GLOBALS['_CASE_SENT'] = 0;
$GLOBALS['JEAB_GRAPH_SEND'] = static function (): array {
    $GLOBALS['_CASE_SENT']++;

    return ['ok' => true];
};
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (): array {
    $GLOBALS['_CASE_SENT']++;

    return ['ok' => true];
};

$store = jeab_store();
$_SESSION['jeab_email'] = 'saljare3@jarfalla.example.test';
$_SERVER['REQUEST_METHOD'] = 'GET';

$ada = jeab_kontakt_find_or_create([
    'name' => 'Ada Fall',
    'email' => 'ada.fall@example.test',
    'phone' => '0701234567',
    'address' => 'Fallgatan 4, 177 31 Järfälla',
    'city' => 'Järfälla',
    'postal_code' => '17731',
    'company' => 'jarfalla',
], false);
rc(is_array($ada) && (string) ($ada['id'] ?? '') !== '', 'Järfälla-kontakten skapas');
$adaId = (string) $ada['id'];
$adaSnap = jeab_reminder_contact_snapshot(jeab_kontakt_get($adaId));
rc($adaSnap['name'] === 'Ada Fall' && $adaSnap['email'] === 'ada.fall@example.test', 'ögonblicksbilden tar namn och e-post från kontakten');
rc($adaSnap['postal'] === '17731' && $adaSnap['address'] !== '', 'ögonblicksbilden tar postnummer och adress från kontakten');

$bo = jeab_kontakt_find_or_create([
    'name' => 'Bo Malm',
    'email' => 'bo.malm@example.test',
    'phone' => '0707654321',
    'address' => 'Malmgatan 2, 211 20 Malmö',
    'city' => 'Malmö',
    'postal_code' => '21120',
    'company' => 'malmo',
], false);
rc(is_array($bo) && (string) ($bo['company'] ?? '') === 'malmo', 'Malmö-kontakten hör till Malmö');
$boId = (string) $bo['id'];

$ixStore->upsertThread('case-ada', [
    'id' => 'case-ada',
    'contact_id' => $adaId,
    'customer_name' => 'Trad Kopia',
    'customer_email' => 'kopia.ada@example.test',
    'status' => 'open',
    'tags' => ['batteri'],
    'assignee' => 'Elin',
    'company' => 'jarfalla',
    'created_at' => '2026-10-24T08:00:00+00:00',
    'updated_at' => '2026-10-24T08:00:00+00:00',
]);
$ixStore->upsertThread('case-kim', [
    'id' => 'case-kim',
    'contact_id' => $adaId,
    'customer_name' => 'Trad Kopia',
    'customer_email' => 'kopia.ada@example.test',
    'status' => 'open',
    'tags' => ['laddbox'],
    'assignee' => 'Kim',
    'company' => 'jarfalla',
    'created_at' => '2026-10-24T09:00:00+00:00',
    'updated_at' => '2026-10-24T09:00:00+00:00',
]);

$prefill = jeab_reminder_case_prefill('case-ada');
rc($prefill['name'] === 'Ada Fall' && $prefill['email'] === $adaSnap['email'], 'ärendet fyller namn och e-post från kontakten');
rc($prefill['address'] === $adaSnap['address'] && $prefill['postal'] === '17731', 'ärendet fyller adress och postnummer från kontakten');
rc($prefill['city'] === $adaSnap['city'] && $prefill['phone'] === $adaSnap['phone'], 'ärendet fyller ort och telefon från kontakten');
rc($prefill['contact_id'] === $adaId && $prefill['product'] === 'batteri', 'ärendet pekar på kontakten och produkt batteri');
rc($prefill['assignee'] === 'Elin' && $prefill['company'] === 'jarfalla', 'ansvarig är ärendets Elin på Järfälla');
rc($prefill['name'] !== 'Trad Kopia' && $prefill['reminder_id'] === 0, 'trådkopian används inte och ingen påminnelse finns än');
rc(!in_array('Kim', jeab_company_names('jarfalla'), true) && in_array('Elin', jeab_company_names('jarfalla'), true), 'Järfälla-listan har Elin och inte Kim');

$_SESSION['jeab_email'] = 'saljare4@jarfalla.example.test';
unset($_SESSION['jeab_user'], $_SESSION['jeab_user_via'], $GLOBALS['_JEAB_LOGIN_IDENTITY']);
$kimPrefill = jeab_reminder_case_prefill('case-kim');
rc($kimPrefill['assignee'] === 'Elin' && $kimPrefill['assignee'] !== 'Kim', 'Malmö-personal filtreras bort från ett Järfälla-ärende');
rc($kimPrefill['product'] === 'laddbox', 'elbilsladdare läses från ärendets etikett');

$openLink = jeab_inkorg_case_reminder_link('case-ada', 'jarfalla');
rc($openLink['id'] === 0 && $openLink['label'] === 'Skapa påminnelse' && str_contains($openLink['href'], 'ny=1') && str_contains($openLink['href'], 'case-ada'), 'knappen skapar när ärendet saknar påminnelse');
$anchor = jeab_inkorg_case_reminder_anchor('case-ada', 'jarfalla', 'ix-chip');
rc(str_contains($anchor, 'ix-chip') && str_contains($anchor, 'Skapa påminnelse'), 'chip-länken säger Skapa påminnelse');

$_GET = ['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'today', 'tag' => 'none', 'who' => 'all', 'ny' => '1', 'thread' => 'case-ada'];
$view = 'today';
$counts = ['today' => 0, 'scheduled' => 0, 'all' => 0, 'waiting' => 0, 'done' => 0];
$visible = [];
$search = '';
$dialog = require dirname(__DIR__) . '/src/pages/reminders.php';
rc(is_string($dialog) && str_contains($dialog, 'Skapa påminnelse') && str_contains($dialog, 'name="from_case"'), 'formuläret från ärendet är förifyllt');
rc(str_contains($dialog, 'value="Ada Fall"') && str_contains($dialog, 'value="17731"') && str_contains($dialog, 'value="batteri" checked'), 'namn, postnummer och produkt är ifyllda');
rc(str_contains($dialog, 'value="Elin" checked') && !str_contains($dialog, 'Trad Kopia') && !str_contains($dialog, 'kopia.ada@example.test'), 'Elin är vald och trådkopian syns inte');
rc(str_contains($dialog, 'name="ort"') && str_contains($dialog, 'name="postal"'), 'ort och postnummer finns i formuläret');

$_GET = ['p' => 'kontakter', 'co' => 'jarfalla', 'id' => $adaId];
$kt = require dirname(__DIR__) . '/src/pages/kontakter.php';
rc(is_string($kt), 'kontaktsidan ritas');
$btnAt = strpos($kt, 'data-kt-reminder');
$ovrAt = strpos($kt, '>Övrigt<');
rc($btnAt !== false && $ovrAt !== false && $btnAt < $ovrAt, 'Skapa påminnelse ligger på kontaktkortet, före Övrigt');
$dlgAt = strpos($kt, 'id="kt-reminder"');
$dlgEnd = strpos($kt, '</dialog>', $dlgAt === false ? 0 : $dlgAt);
$ktDlg = $dlgAt !== false && $dlgEnd !== false ? substr($kt, $dlgAt, $dlgEnd - $dlgAt) : '';
rc(str_contains($ktDlg, 'Skapa påminnelse') && str_contains($ktDlg, 'name="product"') && str_contains($ktDlg, 'name="due_at"') && str_contains($ktDlg, 'name="notes"'), 'kontaktformuläret har produkt, datum och anteckning');
rc(str_contains($ktDlg, 'value="Elin" checked') && str_contains($ktDlg, 'name="from_contact"') && str_contains($ktDlg, 'value="' . $adaId . '"'), 'inloggad Elin är förvald och kontakten följer med');
rc(!str_contains($ktDlg, 'value="Kim"') && !str_contains($ktDlg, 'value="Sam W"'), 'Malmö-personal syns inte på en Järfälla-kontakt');

$_GET = ['p' => 'kontakter', 'co' => 'malmo', 'id' => $boId];
$ktMalmo = require dirname(__DIR__) . '/src/pages/kontakter.php';
$malmoAt = strpos((string) $ktMalmo, 'id="kt-reminder"');
$malmoEnd = strpos((string) $ktMalmo, '</dialog>', $malmoAt === false ? 0 : $malmoAt);
$malmoDlg = $malmoAt !== false && $malmoEnd !== false ? substr((string) $ktMalmo, $malmoAt, $malmoEnd - $malmoAt) : '';
rc(str_contains($malmoDlg, 'value="Kim"') && str_contains($malmoDlg, 'value="Sam W"'), 'Malmö-kontakten visar Malmö-personal');
rc(!str_contains($malmoDlg, 'value="Elin"') && !str_contains($malmoDlg, 'value="Roberto"') && !str_contains($malmoDlg, 'value="Kim" checked') && !str_contains($malmoDlg, 'value="Sam W" checked'), 'inloggad Järfälla-personal blir inte förvald i Malmö');

$_GET = ['p' => 'kontakter', 'co' => 'jarfalla', 'ny' => '1'];
$ktNew = require dirname(__DIR__) . '/src/pages/kontakter.php';
rc(is_string($ktNew) && !str_contains($ktNew, '<button type="button" class="btn" data-kt-reminder>'), 'ny kontakt har ingen Skapa påminnelse');

$beforeContacts = count(jeab_kontakter_load());
$made = jeab_reminder_create_action($store, [
    'ui' => '1',
    'from_case' => '1',
    'thread_id' => 'case-ada',
    'contact_id' => $adaId,
    'product' => 'batteri',
    'who' => 'Elin',
    'company' => 'jarfalla',
    'title' => 'Trad Kopia',
    'customer' => 'Trad Kopia',
    'email' => 'kopia.ada@example.test',
    'address' => 'Annan gata 1',
    'notes' => 'Ring Ada.',
    'due_at' => '2026-11-02',
]);
$madeId = (int) ($made['id'] ?? 0);
rc($madeId > 0 && empty($made['reused']), 'ärendet skapar en påminnelse');
rc(trim((string) ($made['customer_name'] ?? '')) === '' && trim((string) ($made['email'] ?? '')) === '' && trim((string) ($made['postal'] ?? '')) === '', 'påminnelsen lagrar inte namn, e-post eller postnummer');
$madeShown = jeab_reminder_shown_customer($made);
rc($madeShown['name'] === 'Ada Fall' && $madeShown['email'] === 'ada.fall@example.test' && $madeShown['postal'] === '17731', 'visningen läser namn, e-post och postnummer från kontakten');
rc((string) ($made['contact_id'] ?? '') === $adaId, 'påminnelsen är kopplad till kontakten');
rc((string) ($made['thread_id'] ?? '') === 'case-ada', 'påminnelsen är kopplad till ärendet');
rc(str_contains((string) ($made['title'] ?? ''), 'Batteri') && !str_contains((string) ($made['title'] ?? ''), 'Trad Kopia'), 'rubriken är produktens, inte trådkopian');
rc((string) ($made['person'] ?? '') === 'Elin' || in_array('Elin', (array) ($made['assignees'] ?? []), true), 'ansvarig är Elin');
rc(str_starts_with((string) ($made['due_at'] ?? ''), '2026-11-02'), 'datumet sparas');
rc(str_contains((string) ($made['note'] ?? ''), 'Ring Ada.') && str_contains((string) ($made['note'] ?? ''), 'mail:'), 'anteckning och mail-länk skrivs, utan utskick');
rc((int) $GLOBALS['_CASE_SENT'] === 0 && count(jeab_kontakter_load()) === $beforeContacts, 'inget mejl och ingen ny kontakt');
rc(!str_contains((string) ($made['note'] ?? ''), 'Offert'), 'ingen offert antecknas');

$again = jeab_reminder_create_action($store, [
    'ui' => '1',
    'from_case' => '1',
    'thread_id' => 'case-ada',
    'product' => 'sol',
    'who' => 'Roberto',
    'company' => 'jarfalla',
    'title' => 'Ada Fall – Solceller med batteri',
    'customer' => 'Ada Fall',
]);
rc(!empty($again['reused']) && (int) ($again['id'] ?? 0) === $madeId, 'samma ärende öppnar den befintliga påminnelsen');
rc(count(jeab_list_reminders($store, true)) === 1 && (int) $GLOBALS['_CASE_SENT'] === 0, 'ingen dubblett och inget mejl');
$afterLink = jeab_inkorg_case_reminder_link('case-ada', 'jarfalla');
rc($afterLink['label'] === 'Påminnelse' && $afterLink['id'] === $madeId && str_contains($afterLink['href'], 'open=' . $madeId), 'knappen öppnar påminnelsen när den finns');
$_GET = ['ny' => '1', 'thread' => 'case-ada', 'p' => 'reminders'];
$existingUrl = jeab_reminder_ny_existing_url();
rc($existingUrl !== '' && str_contains($existingUrl, 'open=' . $madeId), 'direktlänk till nytt formulär öppnar den som finns');

$_SESSION['jeab_email'] = 'saljare4@jarfalla.example.test';
$fromContact = jeab_reminder_create_action($store, [
    'ui' => '1',
    'from_contact' => '1',
    'contact_id' => $adaId,
    'product' => 'laddbox',
    'notes' => 'Bara en not.',
    'due_at' => '2026-11-03',
    'company' => 'malmo',
    'who' => '',
    'customer' => 'Någon Annan',
    'email' => 'annan@example.test',
]);
$fromId = (int) ($fromContact['id'] ?? 0);
rc($fromId > 0 && $fromId !== $madeId, 'kontakten får en egen påminnelse');
rc((string) ($fromContact['contact_id'] ?? '') === $adaId && trim((string) ($fromContact['customer_name'] ?? '')) === '', 'kontaktpåminnelsen pekar på kontakten och lagrar inget namn');
rc(jeab_reminder_shown_customer($fromContact)['name'] === 'Ada Fall', 'kontaktpåminnelsen visar kontakten');
rc(str_contains((string) ($fromContact['title'] ?? ''), 'Elbilsladdare'), 'produkt blir elbilsladdare');
rc((string) ($fromContact['company'] ?? '') === 'jarfalla', 'kontorets bolag följer kontakten, inte det postade');
rc((string) ($fromContact['person'] ?? '') === 'Elin', 'tom ansvarig blir den inloggade på samma kontor');
rc(str_contains((string) ($fromContact['note'] ?? ''), 'Bara en not.') && !str_contains((string) ($fromContact['note'] ?? ''), 'mail:'), 'anteckningen sparas utan mail-länk');
rc(trim((string) ($fromContact['thread_id'] ?? '')) === '' && (int) $GLOBALS['_CASE_SENT'] === 0, 'ingen tråd och inget mejl');
rc(str_starts_with((string) ($fromContact['due_at'] ?? ''), '2026-11-03'), 'kontaktens datum sparas');

$nBefore = count(jeab_list_reminders($store, true));
$blocked = null;
try {
    jeab_reminder_create_action($store, [
        'ui' => '1',
        'from_contact' => '1',
        'contact_id' => $adaId,
        'product' => 'batteri',
        'who' => 'Sam W',
        'company' => 'malmo',
        'title' => 'Ada Fall',
        'customer' => 'Ada Fall',
    ]);
} catch (InvalidArgumentException $e) {
    $blocked = $e->getMessage();
}
rc($blocked !== null && str_contains((string) $blocked, 'Okänd ansvarig') && count(jeab_list_reminders($store, true)) === $nBefore, 'personal från andra kontoret skapar inget');

$_SESSION['jeab_email'] = 'saljare4@jarfalla.example.test';
$malmoMade = jeab_reminder_create_action($store, [
    'ui' => '1',
    'from_contact' => '1',
    'contact_id' => $boId,
    'product' => 'sol',
    'notes' => 'Malmö-not.',
    'company' => 'jarfalla',
    'who' => '',
    'title' => 'Bo Malm',
    'customer' => 'Bo Malm',
]);
rc((string) ($malmoMade['company'] ?? '') === 'malmo' && trim((string) ($malmoMade['person'] ?? '')) === '', 'Malmö-kontakt utan personal på kontoret får ingen Järfälla-ansvarig');
rc(trim((string) ($malmoMade['customer_name'] ?? '')) === '' && jeab_reminder_shown_customer($malmoMade)['name'] === 'Bo Malm' && (int) $GLOBALS['_CASE_SENT'] === 0, 'Malmö-påminnelsen visar kontakten och skickar inget mejl');

$missing = null;
try {
    jeab_reminder_create_action($store, [
        'ui' => '1',
        'from_case' => '1',
        'thread_id' => 'case-saknas',
        'product' => 'batteri',
        'title' => 'Trad Kopia',
        'customer' => 'Trad Kopia',
    ]);
} catch (InvalidArgumentException $e) {
    $missing = $e->getMessage();
}
rc($missing !== null && str_contains((string) $missing, 'Kontakten saknas'), 'ärende utan kontakt skapar inget av trådkopian');

$inkorgSrc = (string) file_get_contents(dirname(__DIR__) . '/src/pages/inkorg.php');
$kontaktSrc = (string) file_get_contents(dirname(__DIR__) . '/src/pages/kontakter.php');
rc(str_contains($inkorgSrc, 'jeab_inkorg_case_reminder_anchor') && !str_contains($inkorgSrc, '+ Påminnelse'), 'inkorgen använder knappen');
rc(str_contains($kontaktSrc, 'data-kt-reminder') && str_contains($kontaktSrc, 'Skapa påminnelse'), 'kontaktsidan har knappen');

$http = sys_get_temp_dir() . '/jeab-case-http-' . bin2hex(random_bytes(3));
mkdir($http, 0775, true);
file_put_contents($http . '/index.html', $anchor . "\n" . $ktDlg);
$sock = stream_socket_server('tcp://127.0.0.1:0');
rc(is_resource($sock), 'en lokal port är ledig');
$name = stream_socket_get_name($sock, false);
fclose($sock);
$port = (int) substr((string) strrchr((string) $name, ':'), 1);
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $http],
    [1 => ['file', $http . '/server.log', 'a'], 2 => ['file', $http . '/server.log', 'a']],
    $pipes
);
rc(is_resource($server), 'php-servern startade');
$ready = false;
for ($i = 0; $i < 20; $i++) {
    $probe = @file_get_contents('http://127.0.0.1:' . $port . '/index.html');
    if (is_string($probe) && str_contains($probe, 'Skapa påminnelse')) {
        $ready = true;
        break;
    }
    usleep(100000);
}
rc($ready, 'sidan svarar');
$bodyFile = $http . '/body.html';
$code = trim((string) shell_exec('curl -sS -o ' . escapeshellarg($bodyFile) . ' -w %{http_code} ' . escapeshellarg('http://127.0.0.1:' . $port . '/index.html')));
$body = (string) file_get_contents($bodyFile);
if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}
rc($code === '200' && str_contains($body, 'Skapa påminnelse') && str_contains($body, 'name="product"') && str_contains($body, 'value="Elin"'), 'curl ser knappen, produkt och ansvarig, fick ' . $code);

echo "Alla tester gick igenom\n";
