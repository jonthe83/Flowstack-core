<?php

declare(strict_types=1);

/**
 * reminder.set_owner, reminder.set_address och reminder.set_email.
 * dry=1 visar före/efter och skriver inget. dry=0 skriver ägare, adress eller
 * kontaktens e-post och en SQL-audit med gamla värden. Ingen JSON-fil. Ingenting skickas.
 * Påhittade kunder.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-rem-admin-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ra(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$GLOBALS['_ADMIN_SENT'] = 0;
$GLOBALS['JEAB_GRAPH_SEND'] = static function (): array {
    $GLOBALS['_ADMIN_SENT']++;
    return ['ok' => true];
};

ra(jeab_driver() === 'sqlite', 'testet använder sqlite');
$store = jeab_store();

$contact = jeab_kontakt_find_or_create([
    'name' => 'Ada Prov',
    'email' => 'ada.prov.admin@example.test',
    'address' => 'Gamla vägen 1',
    'postal_code' => '11122',
    'city' => 'Gamleby',
], false);
ra(is_array($contact) && (string) ($contact['id'] ?? '') !== '', 'kontakten skapas');
$contactId = (string) $contact['id'];

$created = jeab_create_reminder($store, 'Ada Prov – batteri', '2026-10-22 09:00:00', 'ursprung', null, [
    'customer' => 'Ada Prov',
    'email' => 'ada.prov.admin@example.test',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'address' => 'Provgatan 12',
    'ort' => 'Provstad',
    'postal' => '12345',
    'tags' => ['admin'],
    'thread_id' => 'thread-admin-1',
    'contact_id' => $contactId,
]);
$id = (int) ($created['id'] ?? 0);
ra($id > 0, 'påminnelsen skapas');
jeab_reminder_save_contact_customer($contactId, [
    'street' => 'Gamla vägen 1',
    'postal' => '11122',
    'city' => 'Gamleby',
]);
$row = jeab_get_reminder($store, $id);
ra(is_array($row), 'raden går att läsa');
ra((string) ($row['contact_id'] ?? '') === $contactId, 'kontakten är kopplad');
ra((string) ($row['thread_id'] ?? '') === 'thread-admin-1', 'tråden är kopplad');
$links = [
    'contact_id' => (string) ($row['contact_id'] ?? ''),
    'thread_id' => (string) ($row['thread_id'] ?? ''),
    'booking_id' => (int) ($row['booking_id'] ?? 0),
    'list_id' => (int) ($row['list_id'] ?? 0),
];
$createdAt = (string) ($row['created_at'] ?? '');

$got = jeab_inkorg_hook(['action' => 'inbox.reminder_get', 'id' => $id]);
ra(!empty($got['ok']) && ($got['title'] ?? '') === 'Ada Prov – batteri', 'reminder_get ger titeln');
ra(($got['tags'] ?? []) === ['admin'], 'reminder_get ger taggar');
ra(is_array($got['address'] ?? null) && ($got['address']['street'] ?? '') === 'Gamla vägen 1' && ($got['address']['zip'] ?? '') === '11122' && ($got['address']['city'] ?? '') === 'Gamleby', 'reminder_get ger kontaktens adress');
ra((int) ($got['list_id'] ?? 0) === $links['list_id'] && $links['list_id'] > 0, 'reminder_get ger list_id');
ra((string) ($got['created'] ?? '') === $createdAt && $createdAt !== '', 'reminder_get ger created');
ra(($got['owner'] ?? '') === 'Roberto', 'reminder_get ger ägaren');

$denied = jeab_reminder_admin_dispatch($store, ['action' => 'reminder.set_owner', 'id' => $id, 'owner' => 'Elin']);
ra(($denied['ok'] ?? true) === false && (int) ($denied['status'] ?? 0) === 401, 'hooken kräver inkorgs-token');

$missing = jeab_reminder_set_owner($store, ['owner' => 'Elin']);
ra(($missing['ok'] ?? true) === false && (int) ($missing['status'] ?? 0) === 422, 'utan id blir det fel');

$unknown = jeab_reminder_set_owner($store, ['id' => $id, 'owner' => 'Zorro', 'dry' => 0]);
ra(($unknown['ok'] ?? true) === false && str_contains((string) ($unknown['error'] ?? ''), 'personalistan'), 'okänd ägare skrivs inte');
ra((string) (jeab_get_reminder($store, $id)['person'] ?? '') === 'Roberto', 'okänd ägare lämnar raden');

$vague = jeab_reminder_set_owner($store, ['id' => $id, 'owner' => 'Sam', 'dry' => 0]);
ra(($vague['ok'] ?? true) === false && str_contains((string) ($vague['error'] ?? ''), 'entydig'), 'tvetydig ägare skrivs inte');

$dryOwner = jeab_reminder_set_owner($store, ['id' => $id, 'owner' => 'sam l']);
ra(($dryOwner['ok'] ?? false) === true && (int) ($dryOwner['dry'] ?? 0) === 1 && (int) ($dryOwner['written'] ?? 1) === 0, 'dry saknas är torr och skriver ingen ägare');
ra(($dryOwner['before'] ?? '') === 'Roberto' && ($dryOwner['after'] ?? '') === 'Sam L', 'dry=1 visar ägare före och efter');
ra(($dryOwner['would_send'] ?? true) === false, 'ägarbytet skickar inget');
ra((string) ($dryOwner['contact_id'] ?? '') === $contactId && (string) ($dryOwner['thread_id'] ?? '') === 'thread-admin-1', 'dry visar länkarna');
$still = jeab_get_reminder($store, $id);
ra((string) ($still['person'] ?? '') === 'Roberto' && !str_contains((string) ($still['note'] ?? ''), 'Sam L'), 'dry=1 lämnar ägare och logg');
ra(jeab_reminder_admin_audit_list() === [], 'dry=1 skriver ingen audit');

$wetOwner = jeab_reminder_set_owner($store, ['id' => $id, 'owner' => 'SamL', 'dry' => 0]);
$owned = jeab_get_reminder($store, $id);
ra(($wetOwner['ok'] ?? false) === true && (int) ($wetOwner['dry'] ?? 1) === 0 && (int) ($wetOwner['written'] ?? 0) === 1, 'dry=0 skriver ägaren');
ra(($wetOwner['before'] ?? '') === 'Roberto' && ($wetOwner['after'] ?? '') === 'Sam L', 'svaret visar gammal och ny ägare');
ra((string) ($owned['person'] ?? '') === 'SamL', 'personfältet blir personalens id');
ra(
    (string) ($owned['contact_id'] ?? '') === $links['contact_id']
    && (string) ($owned['thread_id'] ?? '') === $links['thread_id']
    && (int) ($owned['booking_id'] ?? 0) === $links['booking_id']
    && (int) ($owned['list_id'] ?? 0) === $links['list_id'],
    'ägarbytet ändrar inga länkar'
);
ra((string) ($owned['address'] ?? '') === 'Provgatan 12' && (string) ($owned['postal'] ?? '') === '12345', 'ägarbytet lämnar adressen');
$ownerNote = (string) ($owned['note'] ?? '');
ra(str_contains($ownerNote, 'Ägare: Roberto → Sam L') && str_contains($ownerNote, 'ursprung'), 'loggen får gammalt och nytt värde');
$contactAfterOwner = jeab_kontakt_get($contactId);
ra((string) ($contactAfterOwner['street'] ?? '') === 'Gamla vägen 1', 'ägarbytet lämnar kontakten');

$ownerAudit = jeab_reminder_admin_audit_list();
ra(count($ownerAudit) === 1 && ($ownerAudit[0]['action'] ?? '') === 'set_owner', 'en auditrad för ägaren');
ra((int) ($ownerAudit[0]['reminder_id'] ?? 0) === $id && ($ownerAudit[0]['old_owner'] ?? '') === 'Roberto' && ($ownerAudit[0]['new_owner'] ?? '') === 'Sam L', 'auditen har gammalt och nytt namn');
ra((int) ($ownerAudit[0]['undone'] ?? 1) === 0, 'auditraden är inte ångrad');

$sameOwner = jeab_reminder_set_owner($store, ['id' => $id, 'owner' => 'Sam L', 'dry' => 0]);
ra((int) ($sameOwner['written'] ?? 1) === 0 && count(jeab_reminder_admin_audit_list()) === 1, 'samma ägare skrivs inte om');

$dryAddress = jeab_reminder_set_address($store, [
    'id' => $id,
    'street' => ' Nygatan  4 ',
    'zip' => '211 22',
    'city' => 'nystad',
    'dry' => 1,
]);
ra(($dryAddress['ok'] ?? false) === true && (int) ($dryAddress['dry'] ?? 0) === 1 && (int) ($dryAddress['written'] ?? 1) === 0, 'adressens dry=1 skriver inget');
ra(($dryAddress['before']['street'] ?? '') === 'Provgatan 12' && ($dryAddress['before']['zip'] ?? '') === '12345' && ($dryAddress['before']['city'] ?? '') === 'Provstad', 'dry visar påminnelsens gamla adress');
ra(($dryAddress['after']['street'] ?? '') === 'Nygatan 4' && ($dryAddress['after']['zip'] ?? '') === '21122' && ($dryAddress['after']['city'] ?? '') === 'Nystad', 'dry visar den nya adressen');
ra(($dryAddress['contact_before']['street'] ?? '') === 'Gamla vägen 1' && ($dryAddress['contact_after']['street'] ?? '') === 'Nygatan 4', 'dry visar kontaktens före och efter');
ra(($dryAddress['would_send'] ?? true) === false, 'adressbytet skickar inget');
ra((string) (jeab_get_reminder($store, $id)['address'] ?? '') === 'Provgatan 12', 'dry lämnar påminnelsens adress');
ra((string) (jeab_kontakt_get($contactId)['street'] ?? '') === 'Gamla vägen 1', 'dry lämnar kontaktens gata');

$badZip = jeab_reminder_set_address($store, ['id' => $id, 'street' => 'Nygatan 4', 'zip' => 'abc', 'city' => 'Nystad', 'dry' => 0]);
ra(($badZip['ok'] ?? true) === false && str_contains((string) ($badZip['error'] ?? ''), 'postnummer'), 'ogiltigt postnummer skrivs inte');

$wetAddress = jeab_reminder_set_address($store, [
    'id' => $id,
    'street' => 'Nygatan 4',
    'zip' => '21122',
    'city' => 'Nystad',
    'dry' => 0,
]);
$moved = jeab_get_reminder($store, $id);
$movedContact = jeab_kontakt_get($contactId);
ra(($wetAddress['ok'] ?? false) === true && (int) ($wetAddress['written'] ?? 0) === 1, 'dry=0 skriver adressen');
ra((string) ($moved['address'] ?? '') === 'Nygatan 4' && (string) ($moved['postal'] ?? '') === '21122' && (string) ($moved['ort'] ?? '') === 'Nystad', 'påminnelsen får gata, postnummer och ort');
ra((string) ($movedContact['street'] ?? '') === 'Nygatan 4' && (string) ($movedContact['postal_code'] ?? '') === '21122' && (string) ($movedContact['city'] ?? '') === 'Nystad', 'kontakten får samma adress');
ra(jeab_kontakt_row_email($movedContact) === 'ada.prov.admin@example.test' && (string) ($moved['person'] ?? '') === 'SamL', 'e-post och ägare ligger kvar');
ra((string) ($moved['contact_id'] ?? '') === $contactId && (string) ($moved['thread_id'] ?? '') === 'thread-admin-1', 'adressbytet byter inte länk');
$addressNote = (string) ($moved['note'] ?? '');
ra(str_contains($addressNote, 'Provgatan 12') && str_contains($addressNote, 'Nygatan 4') && str_contains($addressNote, 'Gamla vägen 1'), 'loggen har gamla adresser');
ra(str_contains($addressNote, 'Ägare: Roberto → Sam L'), 'ägarloggen ligger kvar');

$addressAudit = jeab_reminder_admin_audit_list();
ra(count($addressAudit) === 2 && ($addressAudit[1]['action'] ?? '') === 'set_address', 'en auditrad för adressen');
ra(
    ($addressAudit[1]['old_street'] ?? '') === 'Provgatan 12'
    && ($addressAudit[1]['old_zip'] ?? '') === '12345'
    && ($addressAudit[1]['old_city'] ?? '') === 'Provstad'
    && ($addressAudit[1]['new_street'] ?? '') === 'Nygatan 4'
    && ($addressAudit[1]['new_zip'] ?? '') === '21122'
    && ($addressAudit[1]['new_city'] ?? '') === 'Nystad'
    && ($addressAudit[1]['old_contact_street'] ?? '') === 'Gamla vägen 1'
    && ($addressAudit[1]['contact_id'] ?? '') === $contactId,
    'auditen har gamla och nya adressfält'
);

$againAddress = jeab_reminder_set_address($store, [
    'id' => $id,
    'street' => 'Nygatan 4',
    'zip' => '211 22',
    'city' => 'Nystad',
    'dry' => 0,
]);
ra((int) ($againAddress['written'] ?? 1) === 0 && count(jeab_reminder_admin_audit_list()) === 2, 'samma adress skrivs inte om');

$seen = jeab_inkorg_hook(['action' => 'inbox.reminder_get', 'id' => $id]);
ra(($seen['address']['street'] ?? '') === 'Nygatan 4' && ($seen['address']['zip'] ?? '') === '21122' && ($seen['address']['city'] ?? '') === 'Nystad', 'reminder_get visar den nya adressen');
ra(($seen['owner'] ?? '') === 'SamL' && ($seen['title'] ?? '') === 'Ada Prov – batteri' && ($seen['tags'] ?? []) === ['admin'], 'reminder_get behåller titel, taggar och ägare');
$seenLog = implode("\n", (array) ($seen['log'] ?? []));
ra(str_contains($seenLog, 'Roberto') && str_contains($seenLog, 'Nygatan 4'), 'reminder_get tar med loggraderna');

$solo = $store->insertReminder([
    'title' => 'Utan kontakt',
    'customer_name' => 'Bo Prov',
    'address' => 'Ensamgatan 2',
    'ort' => 'Enstad',
    'postal' => '33344',
    'company' => 'jarfalla',
    'person' => 'Elin',
    'note' => 'ensam',
    'due_at' => '2026-10-22 10:00:00',
    'created_at' => jeab_now(),
    'updated_at' => jeab_now(),
]);
$soloId = (int) ($solo['id'] ?? 0);
$soloDry = jeab_reminder_set_address($store, [
    'id' => $soloId,
    'street' => 'Andragatan 8',
    'zip' => '44455',
    'city' => 'Andrad',
]);
ra((int) ($soloDry['dry'] ?? 0) === 1 && array_key_exists('contact_before', $soloDry) && $soloDry['contact_before'] === null && (int) ($soloDry['written'] ?? 1) === 0, 'utan kontakt är dry torr och har ingen kontaktrad');
$soloWet = jeab_reminder_set_address($store, [
    'id' => $soloId,
    'street' => 'Andragatan 8',
    'zip' => '44455',
    'city' => 'Andrad',
    'dry' => 0,
]);
$soloFresh = jeab_get_reminder($store, $soloId);
ra((int) ($soloWet['written'] ?? 0) === 1 && (string) ($soloFresh['address'] ?? '') === 'Andragatan 8' && (string) ($soloFresh['postal'] ?? '') === '44455', 'utan kontakt skrivs bara påminnelsen');
ra((string) ($soloFresh['person'] ?? '') === 'Elin' && trim((string) ($soloFresh['contact_id'] ?? '')) === '', 'utan kontakt skapas ingen länk');
$soloGet = jeab_inkorg_hook(['action' => 'inbox.reminder_get', 'id' => $soloId]);
ra(($soloGet['address']['street'] ?? '') === 'Andragatan 8' && ($soloGet['address']['city'] ?? '') === 'Andrad' && ($soloGet['title'] ?? '') === 'Utan kontakt', 'reminder_get läser den egna adressen');

$ownEmail = (string) (jeab_get_reminder($store, $id)['email'] ?? '');
$dryEmail = jeab_reminder_set_email($store, ['id' => $id, 'email' => 'Ny.Kund@Example.Test']);
ra(($dryEmail['ok'] ?? false) === true && (int) ($dryEmail['dry'] ?? 0) === 1 && (int) ($dryEmail['written'] ?? 1) === 0, 'e-postens dry saknas är torr och skriver inget');
ra(($dryEmail['before'] ?? '') === 'ada.prov.admin@example.test' && ($dryEmail['after'] ?? '') === 'ny.kund@example.test', 'dry visar kontaktens gamla och nya e-post');
ra(($dryEmail['would_send'] ?? true) === false && (string) ($dryEmail['contact_id'] ?? '') === $contactId, 'e-postbytet skickar inget och visar kontakten');
ra(jeab_kontakt_row_email(jeab_kontakt_get($contactId)) === 'ada.prov.admin@example.test', 'dry lämnar kontaktens e-post');
ra(!str_contains((string) (jeab_get_reminder($store, $id)['note'] ?? ''), 'E-post:'), 'dry skriver ingen e-postrad');

$badEmail = jeab_reminder_set_email($store, ['id' => $id, 'email' => 'inte-en-adress', 'dry' => 0]);
ra(($badEmail['ok'] ?? true) === false && (int) ($badEmail['status'] ?? 0) === 422 && str_contains((string) ($badEmail['error'] ?? ''), 'ogiltig'), 'ogiltig e-post skrivs inte');
$missingEmail = jeab_reminder_set_email($store, ['id' => $id, 'dry' => 0]);
ra(($missingEmail['ok'] ?? true) === false && (int) ($missingEmail['status'] ?? 0) === 422, 'utan e-post blir det fel');
$emailDenied = jeab_reminder_admin_dispatch($store, ['action' => 'reminder.set_email', 'id' => $id, 'email' => 'ny.kund@example.test', 'dry' => 0]);
ra(($emailDenied['ok'] ?? true) === false && (int) ($emailDenied['status'] ?? 0) === 401, 'e-posthooken kräver inkorgs-token');
$soloEmail = jeab_reminder_set_email($store, ['id' => $soloId, 'email' => 'bo@example.test', 'dry' => 0]);
ra(($soloEmail['ok'] ?? true) === false && (int) ($soloEmail['status'] ?? 0) === 404 && str_contains((string) ($soloEmail['error'] ?? ''), 'kontakten'), 'utan kontakt skrivs ingen e-post');
ra(!str_contains((string) (jeab_get_reminder($store, $soloId)['note'] ?? ''), 'E-post:'), 'saknad kontakt lämnar loggen');

jeab_reminder_save_contact_customer($contactId, ['email' => '']);
$cleared = jeab_kontakt_get($contactId);
ra(jeab_kontakt_row_email($cleared) === '', 'kontakten kan tömmas inför skrivningen');
$wetEmail = jeab_reminder_set_email($store, ['id' => $id, 'email' => 'ny.kund@example.test', 'dry' => 0]);
$emailed = jeab_get_reminder($store, $id);
$emailedContact = jeab_kontakt_get($contactId);
ra(($wetEmail['ok'] ?? false) === true && (int) ($wetEmail['written'] ?? 0) === 1 && (int) ($wetEmail['dry'] ?? 1) === 0, 'dry=0 skriver e-posten');
ra(($wetEmail['before'] ?? 'x') === '' && ($wetEmail['after'] ?? '') === 'ny.kund@example.test', 'svaret visar tomt före och ny efter');
ra(jeab_kontakt_row_email($emailedContact) === 'ny.kund@example.test', 'kontakten får den nya e-posten');
ra((string) ($emailed['email'] ?? '') === $ownEmail, 'påminnelsens eget e-postfält ligger kvar');
ra(
    (string) ($emailed['contact_id'] ?? '') === $links['contact_id']
    && (string) ($emailed['thread_id'] ?? '') === $links['thread_id']
    && (int) ($emailed['booking_id'] ?? 0) === $links['booking_id']
    && (int) ($emailed['list_id'] ?? 0) === $links['list_id'],
    'e-postbytet ändrar inga länkar'
);
$emailNote = (string) ($emailed['note'] ?? '');
ra(str_contains($emailNote, 'E-post: (tom) → ny.kund@example.test'), 'loggen har den gamla tomma adressen och den nya');
ra(str_contains($emailNote, 'Ägare: Roberto → Sam L'), 'ägarloggen ligger kvar efter e-postbytet');

$emailAudit = jeab_reminder_admin_audit_list();
$emailRow = $emailAudit[count($emailAudit) - 1] ?? [];
ra(($emailRow['action'] ?? '') === 'set_email' && (int) ($emailRow['reminder_id'] ?? 0) === $id, 'en auditrad för e-posten');
ra(($emailRow['old_email'] ?? 'x') === '' && ($emailRow['new_email'] ?? '') === 'ny.kund@example.test' && ($emailRow['contact_id'] ?? '') === $contactId, 'auditen har gammal och ny e-post');
ra((int) ($emailRow['undone'] ?? 1) === 0, 'e-postens auditrad är inte ångrad');

$sameEmail = jeab_reminder_set_email($store, ['id' => $id, 'email' => 'NY.KUND@example.test', 'dry' => 0]);
ra((int) ($sameEmail['written'] ?? 1) === 0 && count(jeab_reminder_admin_audit_list()) === count($emailAudit), 'samma e-post skrivs inte om');
ra(jeab_kontakt_row_email(jeab_kontakt_get($contactId)) === 'ny.kund@example.test', 'samma e-post lämnar kontakten');

$pdo = $store instanceof JeabPdoStore ? $store->pdo() : null;
ra($pdo instanceof PDO, 'sqlite-handtaget finns');
$info = $pdo->query('PRAGMA table_info(reminder_admin_audit)')->fetchAll();
$names = array_map(static fn (array $col): string => (string) ($col['name'] ?? ''), is_array($info) ? $info : []);
ra(in_array('old_owner', $names, true) && in_array('new_street', $names, true) && in_array('old_contact_city', $names, true) && in_array('old_email', $names, true) && in_array('new_email', $names, true), 'audittabellen har skalära kolumner');
ra(!in_array('payload', $names, true) && !in_array('json', $names, true) && !in_array('line', $names, true), 'audittabellen har ingen json-kolumn');
ra(!is_file($tmp . '/reminder-admin.json') && !is_file($tmp . '/reminder-admin.jsonl'), 'ingen json-fil för auditen');
ra((int) $GLOBALS['_ADMIN_SENT'] === 0, 'inget mejl skickades');

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
ra(str_contains($hook, "=== 'reminder.set_owner'") && str_contains($hook, "=== 'reminder.set_address'") && str_contains($hook, "=== 'reminder.set_email'") && str_contains($hook, 'jeab_reminder_admin_dispatch'), 'hook.php tar ägare, adress och e-post');
$src = (string) file_get_contents(dirname(__DIR__) . '/src/reminder_admin.php');
ra(str_contains($src, 'jeab_reminder_admin_audit') && !str_contains($src, 'jeab_graph_send') && !str_contains($src, 'jeab_send_mail'), 'skrivningen auditloggas och skickar inget');

$db = (string) file_get_contents(dirname(__DIR__) . '/src/db.php');
$flagAt = strpos($db, "reminder_admin_audit_v1");
$createAt = strpos($db, 'CREATE TABLE IF NOT EXISTS reminder_admin_audit');
ra($flagAt !== false && $createAt !== false && $flagAt < $createAt && str_contains($db, 'static $done'), 'flaggan och minnet ligger före CREATE');
ra((int) ($GLOBALS['_JEAB_REMINDER_ADMIN_AUDIT_DDL'] ?? 0) === 1, 'DDL körs en gång i processen');
ra(jeab_settings_value($pdo, 'sqlite', 'reminder_admin_audit_v1') === '1', 'settings-flaggan reminder_admin_audit_v1 är satt');

$again = (int) ($GLOBALS['_JEAB_REMINDER_ADMIN_AUDIT_DDL'] ?? 0);
jeab_reminder_admin_audit_pdo();
jeab_ensure_reminder_admin_audit($pdo, 'sqlite');
ra((int) ($GLOBALS['_JEAB_REMINDER_ADMIN_AUDIT_DDL'] ?? 0) === $again, 'samma process hoppar över DDL');

$worker = $tmp . '/audit-flag.php';
file_put_contents($worker, "<?php\n\$GLOBALS['_JEAB_DATA_DIR'] = getenv('JEAB_DATA');\nrequire getenv('JEAB_ROOT') . '/src/bootstrap.php';\n\$store = jeab_store();\n\$pdo = \$store instanceof JeabPdoStore ? \$store->pdo() : null;\nif (!\$pdo instanceof PDO) { fwrite(STDERR, \"no pdo\\n\"); exit(1); }\njeab_ensure_reminder_admin_audit(\$pdo, 'sqlite');\njeab_ensure_reminder_admin_audit(\$pdo, 'sqlite');\n\$flag = jeab_settings_value(\$pdo, 'sqlite', 'reminder_admin_audit_v1');\n\$ddl = (int) (\$GLOBALS['_JEAB_REMINDER_ADMIN_AUDIT_DDL'] ?? 0);\necho \$flag . ' ' . \$ddl . \"\\n\";\n");
$proc = proc_open(
    [PHP_BINARY, $worker],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    null,
    ['JEAB_DATA' => $tmp, 'JEAB_ROOT' => dirname(__DIR__)]
);
ra(is_resource($proc), 'ny process startar');
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($proc);
ra($exit === 0 && trim((string) $out) === '1 0', 'ny process med flaggan kör ingen DDL, fick ' . trim((string) $out) . ' ' . trim((string) $err));

echo "reminder admin hooks ok\n";
