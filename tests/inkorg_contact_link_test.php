<?php

declare(strict_types=1);

/** data.inkorg_contact_link: dry skriver inget, write länkar entydiga träffar, läsvägen faller tillbaka. */

$tmp = sys_get_temp_dir() . '/jeab-contact-link-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/inkorg_contacts.php';

function cl(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE contacts (id TEXT PRIMARY KEY, owner_email TEXT NOT NULL DEFAULT '', owner_phone TEXT NOT NULL DEFAULT '')");
$pdo->exec("CREATE TABLE contact_emails (email TEXT NOT NULL, contact_id TEXT NOT NULL)");
$pdo->exec("CREATE TABLE inkorg_contacts (id TEXT PRIMARY KEY, row_json TEXT NOT NULL, email TEXT NOT NULL DEFAULT '', mobile TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL)");
$pdo->exec("CREATE TABLE reminders (id INTEGER PRIMARY KEY, email TEXT, phone TEXT, contact_id TEXT, due_at TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");

$now = strtotime('2026-10-30 12:00:00');
$fresh = '2026-10-20 10:00:00';
$old = '2025-01-01 10:00:00';
$pdo->exec("INSERT INTO contacts VALUES ('kt_a','anna@x.se',''),('kt_b','','+46701111111'),('kt_c','dup@x.se',''),('kt_d','dup@x.se',''),('kt_r','','')");
$pdo->exec("INSERT INTO contact_emails VALUES ('anna2@x.se','kt_a')");
$pdo->exec("INSERT INTO reminders (email, phone, contact_id, created_at, updated_at) VALUES ('rem@x.se','','kt_r','$fresh','$fresh')");
$add = static function (string $id, array $row, string $at) use ($pdo): void {
    $pdo->prepare('INSERT INTO inkorg_contacts (id, row_json, updated_at) VALUES (?, ?, ?)')
        ->execute([$id, json_encode(['id' => $id] + $row), $at]);
};
$add('c-email', ['name' => 'Anna', 'emails' => ['Anna2@X.se']], $fresh);
$add('c-phone', ['name' => 'Bo', 'emails' => [], 'mobile' => '070-111 11 11'], $old);
$add('c-dup', ['name' => 'Dup', 'emails' => ['dup@x.se']], $fresh);
$add('c-rem', ['name' => 'Rem', 'emails' => ['rem@x.se']], $old);
$add('c-new', ['name' => 'Ny', 'emails' => ['ny@x.se']], $fresh);
$add('c-oldnew', ['name' => 'Gammal', 'emails' => ['gammal@x.se']], $old);
$add('c-linked', ['name' => 'Klar', 'emails' => ['anna@x.se'], 'contact_id' => 'kt_a'], $fresh);

$dry = jeab_inkorg_contact_link_run($pdo, ['_now' => $now]);
cl($dry['dry'] === true && $dry['written'] === false, 'dry är standard');
cl($dry['by_email'] === 1 && $dry['by_phone'] === 1 && $dry['by_reminder'] === 1, 'matchar e-post, telefon och påminnelse');
cl($dry['ambiguous'] === 1 && $dry['already'] === 1, 'tvetydig länkas inte, befintlig länk räknas');
cl($dry['unmatched_active'] === 1 && $dry['unmatched_inactive'] === 1 && $dry['would_create'] === 1, 'bara aktiv omatchad skapas');
cl($dry['active'] === 5 && $dry['active_reminder'] === 5, 'aktiv via rad eller påminnelse');
cl(!jeab_inkorg_contact_link_has_column($pdo), 'dry gör ingen DDL');
$untouched = (string) $pdo->query("SELECT row_json FROM inkorg_contacts WHERE id = 'c-email'")->fetchColumn();
cl(!str_contains($untouched, 'contact_id'), 'dry skriver inget');

$w = jeab_inkorg_contact_link_run($pdo, ['_now' => $now, 'write' => '1', 'create' => '0']);
cl($w['linked'] === 3 && $w['backup'] !== '' && is_file($tmp . '/backups/' . $w['backup']), 'write länkar och sparar före-bild');
$row = json_decode((string) $pdo->query("SELECT row_json FROM inkorg_contacts WHERE id = 'c-phone'")->fetchColumn(), true);
cl(($row['contact_id'] ?? '') === 'kt_b' && ($row['name'] ?? '') === 'Bo', 'row_json får contact_id, kopian finns kvar');
cl((string) $pdo->query("SELECT contact_id FROM inkorg_contacts WHERE id = 'c-rem'")->fetchColumn() === 'kt_r', 'kolumnen contact_id fylls');
cl((string) $pdo->query("SELECT updated_at FROM inkorg_contacts WHERE id = 'c-phone'")->fetchColumn() === $old, 'updated_at rörs inte');
cl((int) $pdo->query('SELECT COUNT(*) FROM inkorg_contacts')->fetchColumn() === 7, 'inget raderas');
$again = jeab_inkorg_contact_link_run($pdo, ['_now' => $now, 'write' => '1', 'create' => '0']);
cl($again['linked'] === 0 && $again['already'] === 4, 'idempotent');

$plain = ['id' => 'c-x', 'name' => 'Egen', 'emails' => ['egen@x.se'], 'mobile' => '+46702222222'];
cl(jeab_inkorg_contact_linked_view($plain) === $plain, 'utan contact_id: radens kopia');
$missing = $plain + ['contact_id' => 'kt_finnsinte'];
cl(jeab_inkorg_contact_linked_view($missing) === $missing, 'okänd kontakt: radens kopia');

$made = jeab_kontakt_find_or_create(['name' => 'Central Person', 'email' => 'central@x.se', 'phone' => '0703333333', 'address' => 'Storgatan 1, 17541 Järfälla'], false, true, false);
$cid = is_array($made) ? (string) ($made['id'] ?? '') : '';
cl($cid !== '', 'kontakt skapad i registret');
$view = jeab_inkorg_contact_linked_view(['id' => 'c-y', 'name' => 'Gammalt namn', 'emails' => ['egen@x.se'], 'mobile' => '', 'address' => 'Kvar 2', 'town' => '', 'notes' => 'anteckning', 'contact_id' => $cid]);
cl($view['name'] === 'Central Person' && $view['mobile'] === '+46703333333', 'via contact_id: kontaktens namn och mobil');
cl(in_array('central@x.se', $view['emails'], true) && in_array('egen@x.se', $view['emails'], true), 'e-post är unionen');
cl($view['id'] === 'c-y' && $view['notes'] === 'anteckning' && !empty($view['linked']), 'radens id och anteckning behålls');

echo "inkorg_contact_link_test: klart\n";
