<?php

declare(strict_types=1);

/**
 * Ny påminnelse från tråd fyller namn, adress, telefon och e-post från Kontakter.
 * Påhittade namn. Ingen riktig kund.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-rem-thread-create-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'test-incoming-token',
    'outgoing_webhook_url' => 'https://hooks.example.test/jeab',
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

$pdo = jeab_pdo_connect_sqlite();
if (!jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql')) {
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

function tc(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$contact = jeab_kontakt_find_or_create([
    'name' => 'Ada Trad',
    'email' => 'ada.trad@example.test',
    'phone' => '0701111111',
    'address' => 'Tradgatan 12, 123 45 Teststad',
    'city' => 'Teststad',
    'postal_code' => '12345',
], false);
tc(is_array($contact) && (string) ($contact['id'] ?? '') !== '', 'kontakten skapas');
$contactId = (string) $contact['id'];
$wantPhone = jeab_kontakt_row_phone($contact);
$wantEmail = jeab_kontakt_row_email($contact);
tc($wantEmail === 'ada.trad@example.test' && $wantPhone !== '', 'kontakten har e-post och telefon');

$byId = jeab_reminder_thread_create_customer([
    'contact_id' => $contactId,
    'customer_name' => 'Trådnamn Kopia',
    'customer_email' => 'trad.kopia@example.test',
]);
tc($byId['name'] === 'Ada Trad', 'id: namnet kommer från kontakten');
tc($byId['address'] === 'Tradgatan 12', 'id: adressen kommer från kontakten');
tc($byId['phone'] === $wantPhone, 'id: telefonen kommer från kontakten');
tc($byId['email'] === $wantEmail, 'id: e-post kommer från kontakten');
tc($byId['name'] !== 'Trådnamn Kopia', 'id: trådens namnkopia används inte');

$byMail = jeab_reminder_thread_create_customer([
    'contact_id' => '',
    'customer_name' => 'Trådnamn Kopia',
    'customer_email' => 'ada.trad@example.test',
]);
tc($byMail['name'] === 'Ada Trad' && $byMail['address'] === 'Tradgatan 12', 'e-post: namn och adress från kontakten');
tc($byMail['phone'] === $wantPhone && $byMail['email'] === $wantEmail, 'e-post: telefon och e-post från kontakten');

$byLead = jeab_reminder_thread_create_customer([
    'customer_name' => 'Trådnamn Kopia',
    'lead_customer' => ['email' => 'ada.trad@example.test', 'name' => 'Lead Kopia'],
]);
tc($byLead['name'] === 'Ada Trad' && $byLead['email'] === $wantEmail, 'lead-e-post hittar kontakten');

$missing = jeab_reminder_thread_create_customer([
    'customer_name' => 'Trådnamn Kopia',
    'customer_email' => 'saknas.trad@example.test',
]);
tc($missing === ['name' => '', 'address' => '', 'phone' => '', 'email' => ''], 'utan kontakt lämnas fälten tomma');

$pageSrc = (string) file_get_contents(dirname(__DIR__) . '/src/pages/reminders.php');
tc(str_contains($pageSrc, 'jeab_reminder_case_prefill'), 'sidan läser kontakten via hjälparen');
tc(str_contains($pageSrc, 'value="<?= e($nyAddress) ?>"') && str_contains($pageSrc, 'value="<?= e($nyPhone) ?>"') && str_contains($pageSrc, 'value="<?= e($nyEmail) ?>"'), 'formuläret fyller adress, telefon och e-post');

$_GET = ['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'today', 'tag' => 'none', 'who' => 'all', 'ny' => '1', 'thread' => 'trad-n36'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SESSION['jeab_email'] = 'agenter@flowstack.example.test';
$ixStore->upsertThread('trad-n36', [
    'id' => 'trad-n36',
    'contact_id' => $contactId,
    'customer_name' => 'Trådnamn Kopia',
    'customer_email' => 'trad.kopia@example.test',
    'status' => 'open',
    'tags' => [],
    'company' => 'jarfalla',
    'updated_at' => '2026-10-24T08:00:00+00:00',
]);
$view = 'today';
$counts = ['today' => 0, 'scheduled' => 0, 'all' => 0, 'waiting' => 0, 'done' => 0];
$visible = [];
$search = '';
$html = require dirname(__DIR__) . '/src/pages/reminders.php';
tc(is_string($html) && str_contains($html, 'name="thread_id"'), 'Från tråd öppnar formuläret');
tc(str_contains($html, 'value="Ada Trad"'), 'sidan fyller namnet från kontakten');
tc(str_contains($html, 'value="Tradgatan 12"'), 'sidan fyller adressen från kontakten');
tc(str_contains($html, 'value="' . e($wantPhone) . '"') || str_contains($html, 'value="' . htmlspecialchars($wantPhone, ENT_QUOTES) . '"'), 'sidan fyller telefonen från kontakten');
tc(str_contains($html, 'value="ada.trad@example.test"'), 'sidan fyller e-post från kontakten');
tc(!str_contains($html, 'Trådnamn Kopia') && !str_contains($html, 'trad.kopia@example.test'), 'trådens kopior visas inte');

echo "Alla tester gick igenom\n";
