<?php

declare(strict_types=1);

/**
 * contacts.field_source är skrivskyddad. Den säger om visat namn, mejl,
 * telefon och adress kommer från kontakt, kopia eller snapshot, med hash
 * och match. Ingen klartext och inget personnummer i svaret.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-field-source-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
$_ENV['DATA_BACKEND'] = 'mysql';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function fs(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$redacted = hash('sha256', 'redacted-pnr');
$pnr = jeab_contacts_field_source_classify('198001011234', '', '198001011234', '', false, 'name');
fs($pnr['hash'] === $redacted && $pnr['source'] === 'copy' && $pnr['match'] === 0, 'personnummer hashas som konstant');
$fromContact = jeab_contacts_field_source_classify('Ada Kontakt', 'Ada Kontakt', 'Kopia Ada', 'Fryst Ada', true, 'name');
fs($fromContact['source'] === 'contact' && $fromContact['match'] === 1 && $fromContact['hash'] === hash('sha256', 'ada kontakt'), 'lika visning och kontakt blir contact');
$fromSnap = jeab_contacts_field_source_classify('Fryst Ada', '', 'Fryst Ada', 'Fryst Ada', true, 'name');
fs($fromSnap['source'] === 'snapshot' && $fromSnap['match'] === 0, 'fryst kopia utan kontakt blir snapshot');
$fromCopy = jeab_contacts_field_source_classify('Kopia Ada', 'Ada Kontakt', 'Kopia Ada', '', false, 'name');
fs($fromCopy['source'] === 'copy' && $fromCopy['match'] === 0, 'avvikande kopia blir copy och match 0');

$store = jeab_store();
fs($store instanceof JeabPdoStore, 'sqlite-lagret finns');
$pdo = $store->pdo();
jeab_ensure_contacts_tables($pdo, 'sqlite');
jeab_ensure_contact_id_columns($pdo, 'sqlite');
jeab_purchases_store_ensure($pdo);
jeab_ensure_inkorg_tables($pdo, 'sqlite');
fs(jeab_contacts_sole(), 'kontakten är källan i visningen');

$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-field-source']));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = jeab_contacts_field_source_dispatch(['action' => 'contacts.field_source', 'kind' => 'reminder', 'id' => '1']);
fs(($denied['status'] ?? 0) === 401 && ($denied['sent'] ?? 1) === 0 && ($denied['webhook'] ?? 1) === 0, 'utan token ger 401');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-field-source';

$src = (string) file_get_contents(dirname(__DIR__) . '/src/contacts_field_source.php');
$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
fs(str_contains($hookSrc, "'contacts.field_source'"), 'hook.php registrerar contacts.field_source');
fs(!str_contains($src, '->upsert') && !str_contains($src, 'jeab_kontakter_mutate'), 'kroken skriver inte kontakter');

$now = jeab_now();
$contact = jeab_kontakt_find_or_create([
    'name' => 'Ada Kontakt',
    'email' => 'ada.kontakt@example.test',
    'phone' => '0701000901',
    'address' => 'Kontaktgatan 1, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
fs(is_array($contact), 'kontakten finns');
$contactId = (string) $contact['id'];
$before = jeab_kontakt_fetch($contactId);
$reminder = $store->insertReminder([
    'title' => 'Källa',
    'customer_name' => 'Kopia Ada',
    'email' => 'kopia.ada@example.test',
    'phone' => '0701000902',
    'address' => 'Kopiagatan 1, Felort',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 09:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
fs(jeab_kontakt_stamp_reminder($store, (int) $reminder['id'], $contactId), 'påminnelsen länkas');
$report = jeab_contacts_field_source_dispatch([
    'action' => 'contacts.field_source',
    'kind' => 'reminder',
    'id' => (string) $reminder['id'],
]);
$json = json_encode($report, JSON_UNESCAPED_UNICODE);
fs(($report['ok'] ?? false) === true && ($report['contact_id'] ?? '') === $contactId, 'påminnelsen pekar ut kontakten');
fs(($report['fields']['name']['source'] ?? '') === 'contact' && ($report['fields']['name']['match'] ?? 0) === 1, 'namnet kommer från kontakten');
fs(($report['fields']['email']['source'] ?? '') === 'contact' && ($report['fields']['phone']['source'] ?? '') === 'contact', 'mejl och telefon kommer från kontakten');
fs(is_string($json) && !str_contains($json, 'Ada Kontakt') && !str_contains($json, 'kopia.ada') && !str_contains($json, '0701000901') && !str_contains($json, '+46701000901'), 'svaret har ingen klartext');
$after = jeab_kontakt_fetch($contactId);
fs($after === $before, 'läsningen ändrar inte kontakten');

$customer = $store->insertCustomer('Kopia Bok', $now);
$booking = $store->insertBooking([
    'customer_id' => $customer['id'],
    'customer_name' => 'Kopia Bok',
    'email' => 'kopia.bok@example.test',
    'phone' => '0701000903',
    'address' => 'Bokgatan 2',
    'assignees' => ['Sam L'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => '2026-10-31 09:00:00',
    'ends_at' => '2026-10-31 10:00:00',
    'status' => 'booked',
    'contact_id' => $contactId,
    'created_at' => $now,
    'updated_at' => $now,
]);
$bookingReport = jeab_contacts_field_source_dispatch([
    'kind' => 'booking',
    'id' => (string) ($booking['id'] ?? 0),
]);
fs(($bookingReport['ok'] ?? false) === true && ($bookingReport['fields']['name']['source'] ?? '') === 'contact', 'bokningens namn kommer från kontakten');

$orderId = (int) $reminder['id'];
$payload = [
    'reminder_id' => $orderId,
    'contact_id' => $contactId,
    'customer_name' => 'Kopia Order',
    'email' => 'kopia.order@example.test',
    'phone' => '0701000904',
    'address' => 'Ordergatan 3',
];
$pdo->prepare('INSERT INTO purchases (reminder_id, payload, updated_at, contact_id, contact_indexed) VALUES (?, ?, ?, ?, 1)')->execute([
    $orderId,
    json_encode($payload, JSON_UNESCAPED_UNICODE),
    $now,
    $contactId,
]);
$orderReport = jeab_contacts_field_source_dispatch(['kind' => 'order', 'order_id' => (string) $orderId]);
fs(($orderReport['ok'] ?? false) === true && ($orderReport['contact_id'] ?? '') === $contactId && ($orderReport['fields']['email']['source'] ?? '') === 'contact', 'orderns mejl kommer från kontakten');

$thread = [
    'id' => 'thr-field',
    'created_at' => $now,
    'updated_at' => $now,
    'status' => 'open',
    'customer_name' => 'Kopia Trad',
    'customer_email' => 'kopia.trad@example.test',
    'phone' => '0701000905',
    'address' => 'Tradgatan 4',
    'contact_id' => $contactId,
    'tags' => [],
    'snapshots' => [
        'offert' => [
            'name' => 'Fryst Trad',
            'email' => 'fryst.trad@example.test',
            'phone' => '0701000906',
            'address' => 'Frystgatan 5',
            'owners' => [],
            'at' => $now,
            'source' => 'test',
        ],
    ],
];
(new JeabPdoInkorgStore($pdo))->upsertThread('thr-field', $thread);
$threadReport = jeab_contacts_field_source_dispatch(['kind' => 'inkorg', 'id' => 'thr-field']);
$threadJson = json_encode($threadReport, JSON_UNESCAPED_UNICODE);
fs(($threadReport['ok'] ?? false) === true && ($threadReport['contact_id'] ?? '') === $contactId, 'tråden pekar ut kontakten');
fs(($threadReport['fields']['name']['source'] ?? '') === 'contact', 'trådens namn kommer från kontakten när den är ifylld');
fs(is_string($threadJson) && !str_contains($threadJson, 'Fryst Trad') && !str_contains($threadJson, 'Kopia Trad'), 'trådsvaret har ingen klartext');

$loose = $store->insertReminder([
    'title' => 'Pnr',
    'customer_name' => '198001011234',
    'email' => '',
    'phone' => '',
    'address' => '',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 10:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$pnrReport = jeab_contacts_field_source_dispatch(['kind' => 'reminder', 'id' => (string) $loose['id']]);
$pnrJson = json_encode($pnrReport, JSON_UNESCAPED_UNICODE);
fs(($pnrReport['fields']['name']['hash'] ?? '') === $redacted, 'visat personnummer lämnar inte sitt fingeravtryck');
fs(is_string($pnrJson) && !str_contains($pnrJson, '198001011234'), 'svaret innehåller inte personnumret');
fs(!isset($pnrReport['personnummer']) && !isset($pnrReport['pnr']), 'svaret har inga personnummerfält');

echo "OK  contacts.field_source\n";
