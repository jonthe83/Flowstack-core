<?php

declare(strict_types=1);

/**
 * "Skapa påminnelse" från ett Inkorg-ärende: Namn, E-post och Telefon kommer från ärendets
 * avsändare. En kontakt används bara när den matchar på e-post eller telefon, aldrig på
 * adressen ensam. jeab_kontakt_find_or_create länkar inte på adressen ensam till en kontakt
 * med ett annat namn eller en annan e-post.
 *
 * Live-felet: ärendet från en kund pekade på en kontakt med en annan persons namn på samma
 * adress (adressträff i inflödet fyllde i kundens e-post på grannens kontakt). Formuläret
 * visade då fel Namn och tom Telefon. Påhittade personer, bara tmp-data, inget mejl.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-rem-sender-' . bin2hex(random_bytes(4));
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
        'mysql' => ['host' => '127.0.0.1', 'name' => 'jeab_test', 'user' => 'jeab_test', 'pass' => 'jeab-test-pass', 'charset' => 'utf8mb4'],
    ],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");

function rsp(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function rsp_digits(string $v): string
{
    return preg_replace('/\D+/', '', $v) ?? '';
}

require $root . '/src/bootstrap.php';
require_once $root . '/src/layout.php';
if (function_exists('jeab_bootstrap_load_page_modules')) {
    jeab_bootstrap_load_page_modules();
}
if (!jeab_settings_put(jeab_pdo_connect_sqlite(), 'sqlite', 'inkorg_storage', 'sql')) {
    rsp(false, 'sql-flaggan går inte att sätta');
}
$ixStore = jeab_inkorg_pdo_store();
rsp($ixStore instanceof JeabPdoInkorgStore, 'SQL-inkorgen är öppen i tmp');
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$GLOBALS['_RSP_SENT'] = 0;
$GLOBALS['JEAB_GRAPH_SEND'] = static function (): array {
    $GLOBALS['_RSP_SENT']++;

    return ['ok' => true];
};
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (): array {
    $GLOBALS['_RSP_SENT']++;

    return ['ok' => true];
};
$store = jeab_store();
$_SERVER['REQUEST_METHOD'] = 'GET';

/** @param array<string, mixed> $lead */
function rsp_thread(JeabPdoInkorgStore $ix, string $tid, string $name, string $email, string $contactId, array $lead): void
{
    $ix->upsertMessage($tid . '-m1', [
        'id' => $tid . '-m1',
        'thread_id' => $tid,
        'from' => $email,
        'from_name' => $name,
        'to' => 'info@jarfalla.example.test',
        'subject' => 'Offertförfrågan solceller',
        'body' => "Hej, jag vill ha en offert.\n\nMvh\n{$name}\n" . (string) ($lead['address'] ?? '') . "\nTel " . (string) ($lead['phone'] ?? ''),
        'received_at' => '2026-10-28T08:00:00+00:00',
        'source' => 'graph',
        'labels' => ['type' => 'Lead', 'product' => 'sol'],
    ]);
    $row = [
        'id' => $tid,
        'customer_name' => $name,
        'customer_email' => $email,
        'lead_customer' => $lead + ['name' => $name, 'email' => $email],
        'status' => 'open',
        'tags' => ['sol'],
        'company' => 'jarfalla',
        'created_at' => '2026-10-28T08:00:00+00:00',
        'updated_at' => '2026-10-28T08:00:00+00:00',
    ];
    if ($contactId !== '') {
        $row['contact_id'] = $contactId;
    }
    $ix->upsertThread($tid, $row);
}

// --- 1) Adressen ensam länkar inte en annan person (find_or_create). ---
$granne = jeab_kontakt_find_or_create([
    'name' => 'Mattias Provgranne',
    'address' => 'Provbo 134, 195 92 Märsta',
    'postal_code' => '19592',
    'city' => 'Märsta',
], false);
rsp(is_array($granne) && (string) ($granne['id'] ?? '') !== '', 'grannens kontakt (utan e-post) skapas i tmp');
$granneId = (string) $granne['id'];

$cara = jeab_kontakt_find_or_create([
    'name' => 'Carin Provsén',
    'email' => 'carin.provsen@example.test',
    'phone' => '0705556677',
    'address' => 'Provbo 134, 195 92 Märsta',
    'postal_code' => '19592',
    'city' => 'Märsta',
    'source' => 'inkorg',
], false, true, false);
$caraId = is_array($cara) ? (string) ($cara['id'] ?? '') : '';
rsp($caraId !== '' && $caraId !== $granneId, 'samma adress, annat namn och e-post: ingen länk till grannen (fick ' . $caraId . ')');
$granneNow = jeab_kontakt_get($granneId);
rsp(is_array($granneNow) && jeab_kontakt_row_email($granneNow) === '', 'grannens kontakt får inte kundens e-post');

$caraSave = jeab_kontakt_find_or_create([
    'name' => 'Carin Provsén',
    'email' => 'carin.provsen@example.test',
    'address' => 'Provbo 134, 195 92 Märsta',
    'postal_code' => '19592',
    'city' => 'Märsta',
], false, true, true);
rsp(is_array($caraSave) && (string) ($caraSave['id'] ?? '') === $caraId, 'samma e-post på samma adress återanvänder kundens egen kontakt');

$annan = jeab_kontakt_find_or_create([
    'name' => 'Petra Provhus',
    'address' => 'Provbo 134, 195 92 Märsta',
    'postal_code' => '19592',
    'city' => 'Märsta',
], false);
$annanId = is_array($annan) ? (string) ($annan['id'] ?? '') : '';
rsp($annanId !== '' && $annanId !== $granneId && $annanId !== $caraId, 'samma adress, annat namn utan e-post: egen kontakt');

$sammaPerson = jeab_kontakt_find_or_create([
    'name' => 'Mattias Provgranne',
    'address' => 'Provbo 134, 195 92 Märsta',
    'postal_code' => '19592',
    'city' => 'Märsta',
], false);
rsp(is_array($sammaPerson) && (string) ($sammaPerson['id'] ?? '') === $granneId, 'samma namn på samma adress återanvänds som förut');

// --- 2) Live-fallet: ärendet pekar på en kontakt med annat namn men kundens e-post. ---
$blandad = jeab_kontakt_find_or_create([
    'name' => 'Mattias Blandsson',
    'email' => 'carola.blandad@example.test',
    'address' => 'Blandbo 134, 195 92 Märsta',
    'postal_code' => '19592',
    'city' => 'Märsta',
], false);
$blandadId = (string) ($blandad['id'] ?? '');
rsp($blandadId !== '', 'kontakt med grannens namn och kundens e-post finns (som live)');
rsp_thread($ixStore, 'rsp-live', 'Carola Blandad', 'carola.blandad@example.test', $blandadId, [
    'phone' => '0701234567',
    'address' => 'Blandbo 134, 195 92 Märsta',
]);
$pre = jeab_reminder_case_prefill('rsp-live');
rsp($pre['name'] === 'Carola Blandad', 'Namn kommer från ärendets avsändare, inte kontakten (fick ' . $pre['name'] . ')');
rsp($pre['email'] === 'carola.blandad@example.test', 'E-post från avsändaren');
rsp(rsp_digits($pre['phone']) !== '' && str_ends_with(rsp_digits($pre['phone']), '701234567'), 'Telefon från ärendets signatur/lead (fick "' . $pre['phone'] . '")');
rsp(str_contains($pre['address'], 'Blandbo 134'), 'Adress finns kvar');
rsp($pre['contact_id'] === $blandadId, 'kontakten matchar på e-post och behålls som contact_id');

// --- 3) Ärendet pekar på en kontakt som bara delar adressen: kontakten används inte. ---
rsp_thread($ixStore, 'rsp-addr', 'Carin Provsén', 'carin.provsen2@example.test', $granneId, [
    'phone' => '0708889900',
    'address' => 'Provbo 134, 195 92 Märsta',
]);
$pre = jeab_reminder_case_prefill('rsp-addr');
rsp($pre['name'] === 'Carin Provsén', 'adresskontakt: Namn från avsändaren (fick ' . $pre['name'] . ')');
rsp($pre['contact_id'] === '', 'adresskontakt utan e-post/telefon-träff används inte (contact_id tomt, fick ' . $pre['contact_id'] . ')');
rsp(str_ends_with(rsp_digits($pre['phone']), '708889900') && $pre['email'] === 'carin.provsen2@example.test', 'adresskontakt: Telefon och E-post från ärendet');

$_SESSION['jeab_email'] = 'saljare4@jarfalla.example.test';
$made = jeab_reminder_create_action($store, [
    'ui' => '1',
    'from_case' => '1',
    'thread_id' => 'rsp-addr',
    'contact_id' => '',
    'product' => 'sol',
    'customer' => 'Carin Provsén',
    'email' => 'carin.provsen2@example.test',
    'phone' => '0708889900',
    'title' => 'Provbo 134',
    'postal' => '19592',
    'ort' => 'Märsta',
    'who' => 'Elin',
    'company' => 'jarfalla',
]);
$madeCid = trim((string) ($made['contact_id'] ?? ''));
rsp((int) ($made['id'] ?? 0) > 0 && $madeCid !== '' && $madeCid !== $granneId, 'sparad påminnelse kopplas inte till adresskontakten (fick ' . $madeCid . ')');
rsp((string) ($made['customer_name'] ?? '') === 'Carin Provsén', 'sparad påminnelse har avsändarens namn');
$granneNow = jeab_kontakt_get($granneId);
rsp(is_array($granneNow) && jeab_kontakt_row_email($granneNow) === '', 'grannens kontakt är orörd efter sparning');
rsp((int) $GLOBALS['_RSP_SENT'] === 0, 'inget mejl skickas');

echo "OK  påminnelse-från-ärende-avsändare\n";
