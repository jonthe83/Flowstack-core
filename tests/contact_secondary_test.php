<?php

declare(strict_types=1);

/**
 * En kontakt kan ha två sekundära adresser. De matchar inkommande mejl.
 * Utskick och frysta avsändningar använder bara den primära. Kontakter slås inte ihop.
 */

$tmp = sys_get_temp_dir() . '/jeab-contact-secondary-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/inkorg_contacts.php';
require_once dirname(__DIR__) . '/src/inkorg_contact_create.php';
require_once dirname(__DIR__) . '/src/contacts_link_backfill.php';

function sec(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$raw = new PDO('sqlite::memory:');
$raw->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$raw->exec("CREATE TABLE contacts (id TEXT PRIMARY KEY, row_json TEXT NOT NULL DEFAULT '{}', owner_email TEXT NOT NULL DEFAULT '')");
jeab_ensure_contact_secondary_columns($raw, 'sqlite');
jeab_ensure_contact_secondary_columns($raw, 'sqlite');
$names = [];
foreach ($raw->query('PRAGMA table_info(contacts)')->fetchAll(PDO::FETCH_ASSOC) as $col) {
    $names[] = (string) ($col['name'] ?? '');
}
sec(in_array('email2', $names, true) && in_array('email3', $names, true), 'migrationen lägger email2 och email3');
$indexes = $raw->query("SELECT name FROM sqlite_master WHERE type = 'index'")->fetchAll(PDO::FETCH_COLUMN);
sec(in_array('contacts_email2', $indexes, true) && in_array('contacts_email3', $indexes, true), 'indexen skapas en gång');

$base = [
    'street' => 'Familjegatan 1',
    'postal_code' => '17731',
    'city' => 'Järfälla',
    'owner_name_1' => 'Ada Familj',
    'contact_person' => '1',
    'email' => '  Ada@Example.TEST ',
    'email2' => '  Familj@Example.TEST ',
    'email3' => 'Ny@Example.TEST',
    'phone' => '0701111111',
    'company' => 'jarfalla',
];
$saved = jeab_kontakt_save_input($base);
$ada = (string) ($saved['id'] ?? '');
sec($ada !== '', 'kontakten sparas');
sec(jeab_kontakt_row_email($saved) === 'ada@example.test', 'primär adress är gemener och trimmad');
sec(jeab_kontakt_secondary_pair($saved) === ['familj@example.test', 'ny@example.test'], 'sekundära adresser normaliseras');
sec(!in_array('familj@example.test', jeab_kontakt_row_emails($saved), true), 'primärlistan innehåller inte sekundäradressen');

$again = jeab_kontakt_save_input([
    'id' => $ada,
    'street' => 'Familjegatan 1',
    'postal_code' => '17731',
    'city' => 'Järfälla',
    'owner_name_1' => 'Ada Familj',
    'contact_person' => '1',
    'email' => 'ada@example.test',
    'phone' => '0701111111',
    'company' => 'jarfalla',
]);
sec(jeab_kontakt_secondary_pair($again) === ['familj@example.test', 'ny@example.test'], 'sparning utan fälten lämnar sekundäradresserna');

$dup = false;
try {
    jeab_kontakt_save_input([
        'street' => 'Annanvagen 2',
        'postal_code' => '21120',
        'city' => 'Malmö',
        'owner_name_1' => 'Bo Annan',
        'contact_person' => '1',
        'email' => 'bo@example.test',
        'email2' => 'Familj@Example.TEST',
        'company' => 'malmo',
    ]);
} catch (InvalidArgumentException $e) {
    $dup = str_contains($e->getMessage(), 'E-postadressen används redan av en annan kontakt.');
}
sec($dup, 'upptagen sekundär adress avvisas');
$primaryTaken = false;
try {
    jeab_kontakt_save_input([
        'street' => 'Annanvagen 4',
        'postal_code' => '21120',
        'city' => 'Malmö',
        'owner_name_1' => 'Bo Annan',
        'contact_person' => '1',
        'email' => 'familj@example.test',
        'company' => 'malmo',
    ]);
} catch (InvalidArgumentException $e) {
    $primaryTaken = str_contains($e->getMessage(), 'E-postadressen används redan av en annan kontakt.');
}
sec($primaryTaken, 'primär adress som redan är sekundär avvisas');
$twins = false;
try {
    jeab_kontakt_save_input([
        'id' => $ada,
        'street' => 'Familjegatan 1',
        'postal_code' => '17731',
        'city' => 'Järfälla',
        'owner_name_1' => 'Ada Familj',
        'contact_person' => '1',
        'email' => 'ada@example.test',
        'email2' => 'ett@example.test',
        'email3' => 'Ett@Example.TEST',
        'company' => 'jarfalla',
    ]);
} catch (InvalidArgumentException $e) {
    $twins = str_contains($e->getMessage(), 'Sekundär e-post 2 är samma som sekundär e-post 1.');
}
sec($twins, 'de två sekundära fälten får inte vara samma adress');
sec(jeab_kontakt_get($ada) !== null && count(jeab_kontakter_load()) === 1, 'avvisningen skapar ingen andra kontakt');

$bad = false;
try {
    jeab_kontakt_save_input([
        'id' => $ada,
        'street' => 'Familjegatan 1',
        'postal_code' => '17731',
        'city' => 'Järfälla',
        'owner_name_1' => 'Ada Familj',
        'contact_person' => '1',
        'email' => 'ada@example.test',
        'email2' => 'inte-en-adress',
        'company' => 'jarfalla',
    ]);
} catch (InvalidArgumentException $e) {
    $bad = str_contains($e->getMessage(), 'Sekundär e-post 1 är inte en giltig adress.');
}
sec($bad, 'ogiltig sekundär adress avvisas');
sec(jeab_kontakt_secondary_pair(jeab_kontakt_get($ada) ?: []) === ['familj@example.test', 'ny@example.test'], 'ogiltig sparning skriver inte över');

$same = false;
try {
    jeab_kontakt_save_input([
        'id' => $ada,
        'street' => 'Familjegatan 1',
        'postal_code' => '17731',
        'city' => 'Järfälla',
        'owner_name_1' => 'Ada Familj',
        'contact_person' => '1',
        'email' => 'ada@example.test',
        'email2' => 'ada@example.test',
        'company' => 'jarfalla',
    ]);
} catch (InvalidArgumentException $e) {
    $same = str_contains($e->getMessage(), 'samma som en adress som redan finns på kontakten');
}
sec($same, 'sekundär adress får inte vara den primära');

$_GET = ['p' => 'kontakter', 'id' => $ada, 'co' => 'jarfalla'];
$store = jeab_store();
$html = (string) (require dirname(__DIR__) . '/src/pages/kontakter.php');
sec(str_contains($html, 'E-post 2') && str_contains($html, 'E-post 3'), 'formuläret har båda fälten');
sec(str_contains($html, 'name="email2"') && str_contains($html, 'value="familj@example.test"'), 'fält 1 visar den sparade adressen');
sec(str_contains($html, 'name="email3"') && str_contains($html, 'value="ny@example.test"'), 'fält 2 visar den sparade adressen');
sec(str_contains($html, 'inputmode="email"') && str_contains($html, 'maxlength="191"'), 'fälten valideras som e-post');
$css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/paminnelser.css');
sec(str_contains($css, 'html body.page-reminders .reminder-create .field input') && str_contains($css, 'width: 100%'), 'samma fält är full bredd på mobil och dator');
file_put_contents($tmp . '/form.html', $html);

$beforeStreet = (string) (jeab_kontakt_get($ada)['street'] ?? '');
$reused = jeab_kontakt_find_or_create([
    'name' => 'Kim Familj',
    'email' => 'NY@example.test',
    'phone' => '0709999999',
    'street' => 'Heltannat 9',
    'postal_code' => '11122',
    'city' => 'Stockholm',
], true, false, false);
sec(is_array($reused) && (string) ($reused['id'] ?? '') === $ada, 'skapande träffar sekundär adressen');
sec(count(jeab_kontakter_load()) === 1, 'skapandet lägger ingen ny kontakt');
sec((string) (jeab_kontakt_get($ada)['street'] ?? '') === $beforeStreet, 'träffen skriver inte om kontakten');

$plan = jeab_kontakt_dedupe_plan(jeab_kontakter_load(), null);
sec(($plan['merges'] ?? []) === [] || (is_array($plan['merges'] ?? null) && $plan['merges'] === []), 'dedupe slår inte ihop kontakten');
$dupHit = jeab_kontakt_dedupe_match_contact(jeab_kontakter_load(), [
    'street' => 'Heltannat 9',
    'city' => 'Stockholm',
    'owners' => [['name' => 'Kim Familj', 'contact' => true, 'email' => 'familj@example.test', 'phone' => '']],
]);
sec($dupHit === $ada, 'kontakter dup jämför sekundär adressen');
$dupOtherWay = jeab_kontakt_dedupe_match_contact(jeab_kontakter_load(), [
    'street' => 'Heltannat 9',
    'city' => 'Stockholm',
    'email2' => 'ada@example.test',
    'owners' => [['name' => 'Kim Familj', 'contact' => true, 'email' => 'kim@example.test', 'phone' => '']],
]);
sec($dupOtherWay === $ada, 'kontakter dup jämför en ny sekundär mot primär');
$gapDup = jeab_contacts_gap_duplicate_email([
    'id' => 'kt_annan',
    'street' => 'Heltannat 9',
    'city' => 'Stockholm',
], 'familj@example.test');
sec($gapDup === $ada, 'dubblettluckan ser sekundär adressen på en annan adress');

$leadId = jeab_lead_reparse_find_contact([
    'email' => 'Familj@Example.TEST',
    'name' => 'Kim Familj',
    'city' => 'Stockholm',
    'address' => 'Heltannat 9',
]);
sec($leadId === $ada, 'lead-uppslaget träffar sekundär adressen');

$outcomeContacts = jeab_kontakter_load();
$outcome = jeab_contacts_sole_property_outcome([
    'email' => 'ny@example.test',
    'name' => 'Någon Annan',
    'address' => 'Bortagatan 3, 11122 Stockholm',
], $outcomeContacts);
sec($outcome === 'have' && count($outcomeContacts) === 1, 'lead-utfallet länkar och skapar inte');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE contacts (id TEXT PRIMARY KEY, owner_email TEXT NOT NULL DEFAULT '', owner_phone TEXT NOT NULL DEFAULT '', email2 TEXT NOT NULL DEFAULT '', email3 TEXT NOT NULL DEFAULT '', row_json TEXT NOT NULL DEFAULT '{}')");
$pdo->exec("CREATE TABLE contact_emails (email TEXT NOT NULL, contact_id TEXT NOT NULL)");
$pdo->exec("CREATE TABLE inkorg_contacts (id TEXT PRIMARY KEY, row_json TEXT NOT NULL, email TEXT NOT NULL DEFAULT '', mobile TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL, contact_id TEXT NOT NULL DEFAULT '')");
$pdo->exec("CREATE TABLE reminders (id INTEGER PRIMARY KEY, email TEXT, phone TEXT, contact_id TEXT, due_at TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
$stamp = '2026-10-20 10:00:00';
$json = json_encode([
    'id' => 'kt_ada',
    'email2' => 'json@example.test',
    'owners' => [['name' => 'Ada Familj', 'email' => 'ada@example.test', 'contact' => true]],
], JSON_UNESCAPED_UNICODE);
$pdo->prepare('INSERT INTO contacts (id, owner_email, email2, email3, row_json) VALUES (?, ?, ?, ?, ?)')
    ->execute(['kt_ada', 'ada@example.test', 'familj@example.test', '', $json]);
$pdo->prepare('INSERT INTO inkorg_contacts (id, row_json, updated_at) VALUES (?, ?, ?)')->execute([
    'c-sec',
    json_encode(['id' => 'c-sec', 'name' => 'Kim', 'emails' => ['Familj@Example.TEST']]),
    $stamp,
]);
$pdo->prepare('INSERT INTO inkorg_contacts (id, row_json, updated_at) VALUES (?, ?, ?)')->execute([
    'c-json',
    json_encode(['id' => 'c-json', 'name' => 'Json', 'emails' => ['JSON@example.test']]),
    $stamp,
]);
$now = strtotime('2026-10-30 12:00:00');
$dry = jeab_inkorg_contact_link_run($pdo, ['_now' => $now, 'create' => '0']);
sec((int) ($dry['by_email'] ?? 0) === 2 && (int) ($dry['created'] ?? 0) === 0, 'inkorgslänken träffar kolumn och json');
$wet = jeab_inkorg_contact_link_run($pdo, ['_now' => $now, 'write' => '1', 'create' => '0']);
$linked = json_decode((string) $pdo->query("SELECT row_json FROM inkorg_contacts WHERE id = 'c-sec'")->fetchColumn(), true);
$linkedJson = json_decode((string) $pdo->query("SELECT row_json FROM inkorg_contacts WHERE id = 'c-json'")->fetchColumn(), true);
$contactStill = $pdo->query("SELECT owner_email, email2, email3 FROM contacts WHERE id = 'kt_ada'")->fetch(PDO::FETCH_ASSOC);
sec(is_array($linked) && ($linked['contact_id'] ?? '') === 'kt_ada', 'mejlet från sekundär adressen länkas');
sec(is_array($linkedJson) && ($linkedJson['contact_id'] ?? '') === 'kt_ada', 'json-adressen länkas till samma kontakt');
sec((int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn() === 1, 'länken slår inte ihop och skapar inte');
sec(is_array($contactStill) && ($contactStill['owner_email'] ?? '') === 'ada@example.test' && ($contactStill['email2'] ?? '') === 'familj@example.test', 'kontaktens adresser är orörda');

$bf = new PDO('sqlite::memory:');
$bf->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$bf->exec("CREATE TABLE contacts (id TEXT PRIMARY KEY, row_json TEXT NOT NULL, owner_email TEXT NOT NULL DEFAULT '', owner_phone TEXT NOT NULL DEFAULT '', company TEXT NOT NULL DEFAULT '', email2 TEXT NOT NULL DEFAULT '', email3 TEXT NOT NULL DEFAULT '')");
$bfJson = json_encode([
    'id' => 'kt_bf',
    'company' => 'jarfalla',
    'email3' => 'tredje@example.test',
    'owners' => [['name' => 'Bo', 'email' => 'bo@example.test', 'phone' => '', 'contact' => true]],
], JSON_UNESCAPED_UNICODE);
$bf->prepare('INSERT INTO contacts (id, row_json, owner_email, company, email2) VALUES (?, ?, ?, ?, ?)')
    ->execute(['kt_bf', $bfJson, 'bo@example.test', 'jarfalla', 'andra@example.test']);
[$emailMap] = jeab_contacts_link_backfill_contacts($bf);
$jarfalla = is_array($emailMap['jarfalla'] ?? null) ? $emailMap['jarfalla'] : [];
sec(isset($jarfalla['andra@example.test']['kt_bf']) && isset($jarfalla['tredje@example.test']['kt_bf']) && isset($jarfalla['bo@example.test']['kt_bf']), 'backfill-matchern ser primär och sekundär');
$bf->exec("ALTER TABLE contacts ADD COLUMN secondary_email TEXT NOT NULL DEFAULT ''");
$bf->prepare('INSERT INTO contacts (id, row_json, owner_email, company, secondary_email) VALUES (?, ?, ?, ?, ?)')
    ->execute(['kt_alt', json_encode(['id' => 'kt_alt', 'company' => 'jarfalla', 'owners' => [['email' => 'alt@example.test']]], JSON_UNESCAPED_UNICODE), 'alt@example.test', 'jarfalla', 'sekundar@example.test']);
[$emailMapAlt] = jeab_contacts_link_backfill_contacts($bf);
$jarfallaAlt = is_array($emailMapAlt['jarfalla'] ?? null) ? $emailMapAlt['jarfalla'] : [];
sec(isset($jarfallaAlt['sekundar@example.test']['kt_alt']), 'backfill ser sekundär kolumn via katalogen');

$gapPdo = new PDO('sqlite::memory:');
$gapPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$gapPdo->exec("CREATE TABLE inkorg_list (id TEXT PRIMARY KEY, company TEXT NOT NULL DEFAULT '', customer_email TEXT NOT NULL DEFAULT '', contact_id TEXT NOT NULL DEFAULT '', row_json TEXT NOT NULL)");
$gapPdo->exec("CREATE TABLE inkorg_threads (id TEXT PRIMARY KEY, row_json TEXT NOT NULL)");
$gapPdo->exec("CREATE TABLE inkorg_contacts (id TEXT PRIMARY KEY, row_json TEXT NOT NULL, email TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL, contact_id TEXT NOT NULL DEFAULT '')");
$gapId = 'ink_sec_' . bin2hex(random_bytes(4));
$gapRow = json_encode([
    'id' => $gapId,
    'list_type' => 'Ny lead',
    'customer_email' => 'familj@example.test',
    'contact_id' => '',
    'lead_customer' => ['name' => 'Kim Familj', 'email' => 'familj@example.test', 'phone' => ''],
], JSON_UNESCAPED_UNICODE);
$gapPdo->prepare('INSERT INTO inkorg_list (id, company, customer_email, contact_id, row_json) VALUES (?, ?, ?, ?, ?)')
    ->execute([$gapId, 'jarfalla', 'familj@example.test', '', $gapRow]);
$gapPdo->prepare('INSERT INTO inkorg_threads (id, row_json) VALUES (?, ?)')->execute([$gapId, $gapRow]);
$nBefore = count(jeab_kontakter_load());
$dir = jeab_inkorg_contact_create_email_directory($gapPdo);
$adaMails = is_array($dir[$ada]['emails'] ?? null) ? $dir[$ada]['emails'] : [];
sec(isset($adaMails['ada@example.test']) && isset($adaMails['familj@example.test']) && isset($adaMails['ny@example.test']), 'katalogen tar med primär och sekundär adress');
$created = jeab_inkorg_contact_create_run($gapPdo, ['dry' => 0]);
$afterCreate = json_decode((string) $gapPdo->query('SELECT row_json FROM inkorg_list WHERE id = ' . $gapPdo->quote($gapId))->fetchColumn(), true);
$dupReason = false;
foreach (is_array($created['uncertain'] ?? null) ? $created['uncertain'] : [] as $item) {
    if (is_array($item) && (string) ($item['id'] ?? '') === $gapId && (string) ($item['reason'] ?? '') === 'dup_existing_email') {
        $dupReason = true;
    }
}
sec((int) ($created['created'] ?? 0) === 0 && $dupReason && is_array($afterCreate) && trim((string) ($afterCreate['contact_id'] ?? '')) === '', 'inkorg_contact_create lämnar sekundär adressen som osäker dubblett');
sec(count(jeab_kontakter_load()) === $nBefore, 'inkorg_contact_create skapar ingen kopia');
require_once dirname(__DIR__) . '/src/contacts_link_by_email.php';
$byEmail = jeab_contacts_link_by_email_index($gapPdo);
sec(isset($byEmail['jarfalla']['familj@example.test'][$ada]), 'länken via e-post ser sekundär adressen');
$adaAfter = jeab_kontakt_get($ada) ?: [];
sec((string) ($adaAfter['street'] ?? '') === $beforeStreet && jeab_kontakt_row_email($adaAfter) === 'ada@example.test', 'länken ändrar inte kontakten');

$frozen = ['frozen' => true, 'to' => 'ada@example.test', 'body' => 'offert redan skickad'];
$frozenCopy = $frozen;
$mailed = jeab_kontakt_save_input([
    'id' => $ada,
    'street' => 'Familjegatan 1',
    'postal_code' => '17731',
    'city' => 'Järfälla',
    'owner_name_1' => 'Ada Familj',
    'contact_person' => '1',
    'email' => 'ada@example.test',
    'email2' => 'familj@example.test',
    'email3' => 'flytt@example.test',
    'company' => 'jarfalla',
]);
sec($frozen === $frozenCopy, 'fryst utskick rörs inte av sparningen');
sec(jeab_quote_invoice_recipient($mailed, $frozen) === 'ada@example.test', 'fryst offert och faktura behåller mottagaren');
sec(jeab_quote_invoice_recipient($mailed) === 'ada@example.test', 'nytt utskick går till primär adress');
sec(jeab_kontakt_outgoing_email($mailed) === 'ada@example.test', 'utgående mejl ignorerar sekundär adress');
sec(jeab_kontakt_secondary_pair($mailed) === ['familj@example.test', 'flytt@example.test'], 'sekundär 2 kan bytas');

echo "contact secondary ok\n";
