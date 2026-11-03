<?php

declare(strict_types=1);

/**
 * Ny påminnelse sparas även när contact_emails saknas.
 * contacts_schema_v1 kan redan vara ok, och tick är den som skapar tabellen.
 * Ett kontorsklick och en POST kör ingen DDL.
 * Påhittad kund. Inget mejl.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-rem-mails-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'outgoing_webhook_url' => '',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';

function rme(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function rme_emails_exist(PDO $pdo): bool
{
    $name = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'contact_emails'")->fetchColumn();

    return $name === 'contact_emails';
}

$store = jeab_store();
rme($store instanceof JeabPdoStore, 'sqlite är öppen');
$pdo = $store->pdo();
rme(jeab_settings_value($pdo, 'sqlite', 'contacts_schema_v1') === 'ok', 'kontaktschemat är redan ok');
$pdo->exec('DROP TABLE IF EXISTS contact_emails');
jeab_contact_emails_table_exists($pdo, false);
rme(rme_emails_exist($pdo) === false, 'contact_emails är borta');

unset(
    $GLOBALS['_JEAB_TICK'],
    $GLOBALS['_JEAB_MIGRATE_RUNS'],
    $GLOBALS['_JEAB_SCHEMA_DDL'],
    $GLOBALS['_JEAB_SCHEMA_MIGRATING'],
    $GLOBALS['_JEAB_PAGE_REQUEST']
);
$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
$_GET = ['p' => 'reminders', 'v' => 'today', 'tag' => 'none', 'co' => 'jarfalla', 'who' => 'all'];
$_SESSION['jeab_company'] = 'jarfalla';
jeab_settings_put($pdo, 'sqlite', 'kontakter_storage', 'sql');
jeab_settings_cache_forget($pdo, 'sqlite', 'kontakter_storage');
rme(jeab_kontakter_storage_mode() === 'sql', 'kontakter skrivs i SQL');
rme(jeab_kontakter_use_sql() === true, 'sparvägen använder SQL-lagret');

jeab_ensure_contacts_tables($pdo, 'sqlite');
jeab_ensure_contact_emails($pdo, 'sqlite');
rme(rme_emails_exist($pdo) === false, 'POST skapar inte contact_emails');
rme(jeab_contact_emails_ddl_allowed() === false, 'ett kontorsklick får inte skapa tabellen');

$payload = [
    'ui' => '1',
    'title' => 'Sollentunavägen 101B',
    'customer' => 'Yange Wang',
    'phone' => '+46738303189',
    'email' => 'younger.w@gmail.com',
    'notes' => '16 panels and 15kwh Sigenstor Neo in Sollentuna.',
    'due_at' => '',
    'who' => 'Sam L',
    'company' => 'jarfalla',
];
$created = jeab_reminder_create_action($store, $payload);
$id = (int) ($created['id'] ?? 0);
rme($id > 0, 'påminnelsen får ett id');
rme((string) ($created['phone'] ?? '') === '+46738303189', 'telefonen sparas som +46738303189');
rme((string) ($created['customer_name'] ?? '') === 'Yange Wang', 'namnet sparas');
$contactId = trim((string) ($created['contact_id'] ?? ''));
rme($contactId !== '', 'kontakten kopplas utan contact_emails');
$again = $store->getReminder($id);
rme(is_array($again) && (int) ($again['id'] ?? 0) === $id, 'raden ligger kvar i databasen');
rme(rme_emails_exist($pdo) === false, 'sparningen skapade inte tabellen');

$fromContact = jeab_reminder_create_action($store, [
    'ui' => '1',
    'from_contact' => '1',
    'contact_id' => $contactId,
    'company' => 'jarfalla',
    'who' => 'Sam L',
    'product' => 'okand',
    'notes' => 'Från kontakten.',
]);
$fromId = (int) ($fromContact['id'] ?? 0);
rme($fromId > 0 && $fromId !== $id, 'påminnelse från kontakt sparas');
rme(trim((string) ($fromContact['contact_id'] ?? '')) === $contactId, 'påminnelsen från kontakten behåller contact_id');

$found = jeab_contact_emails_find(['younger.w@gmail.com']);
rme(isset($found['younger.w@gmail.com']), 'e-postuppslag läser owner_email när tabellen saknas');
$clause = jeab_kontakt_search_clause('younger.w@gmail.com', false);
rme(!str_contains($clause['sql'], 'contact_emails'), 'e-postsök utan tabellen läser bara owner_email');

$GLOBALS['_JEAB_TICK'] = true;
jeab_ensure_contact_emails($pdo, 'sqlite');
rme(rme_emails_exist($pdo) === true, 'tick skapar contact_emails även när kontaktschemat redan är ok');

echo "reminder save missing contact_emails ok\n";
