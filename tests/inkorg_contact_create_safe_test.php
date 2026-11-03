<?php

declare(strict_types=1);

/**
 * data.inkorg_contact_create ska vara säker att köra på riktigt:
 * dubblettkontroll utan att skriva, only_ids, ångerfil och ingen sammanslagning.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-contact-safe-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp . '/public';
mkdir($tmp . '/public', 0775, true);
putenv('JEAB_DISABLE_SQLITE=0');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/inkorg_contact_create.php';

function safe(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$pdo = jeab_store()->pdo();
safe($pdo instanceof PDO, 'sqlite är öppen');
$pdo->exec('CREATE TABLE IF NOT EXISTS inkorg_list (
    id TEXT PRIMARY KEY,
    company TEXT NOT NULL DEFAULT \'\',
    customer_email TEXT NOT NULL DEFAULT \'\',
    list_at TEXT NOT NULL DEFAULT \'\',
    list_status TEXT NOT NULL DEFAULT \'\',
    contact_id TEXT NOT NULL DEFAULT \'\',
    row_json TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE IF NOT EXISTS inkorg_threads (
    id TEXT PRIMARY KEY,
    status TEXT NOT NULL DEFAULT \'open\',
    updated_at TEXT,
    row_json TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE IF NOT EXISTS inkorg_contacts (
    id TEXT PRIMARY KEY,
    row_json TEXT NOT NULL,
    email TEXT NOT NULL DEFAULT \'\',
    mobile TEXT NOT NULL DEFAULT \'\',
    updated_at TEXT NOT NULL,
    contact_id TEXT NOT NULL DEFAULT \'\'
)');
$pdo->exec('CREATE TABLE IF NOT EXISTS contact_emails (
    email TEXT NOT NULL,
    contact_id TEXT NOT NULL,
    PRIMARY KEY (email, contact_id)
)');

$seed = jeab_kontakt_create_unmerged([
    'name' => 'Bo Dup',
    'email' => 'primary@example.test',
    'phone' => '070-111 22 33',
    'street' => 'Storgatan 12',
    'postal_code' => '17770',
    'city' => 'Jarfalla',
    'source' => 'seed',
]);
safe(is_array($seed) && ($seed['id'] ?? '') !== '', 'befintlig kontakt finns');
$seedId = (string) $seed['id'];
$pdo->prepare('INSERT INTO contact_emails (email, contact_id) VALUES (?, ?)')->execute(['andra@example.test', $seedId]);
$seedRow = static function () use ($seedId): array {
    unset($GLOBALS['_JEAB_KONTAKTER_MEM'], $GLOBALS['_JEAB_KONTAKTER_MEM_KEY']);
    $row = jeab_kontakt_fetch($seedId);

    return is_array($row) ? $row : [];
};
$seedIdentity = static function () use ($seedRow): array {
    $row = $seedRow();
    $owner = is_array($row['owners'][0] ?? null) ? $row['owners'][0] : [];

    return [
        'id' => (string) ($row['id'] ?? ''),
        'email' => (string) ($owner['email'] ?? ''),
        'phone' => (string) ($owner['phone'] ?? ''),
        'street' => (string) ($row['street'] ?? ''),
        'postal' => (string) ($row['postal_code'] ?? ''),
        'source' => (string) ($row['source'] ?? ''),
    ];
};
$seedBefore = json_encode($seedRow(), JSON_UNESCAPED_UNICODE);
$seedSame = $seedIdentity();
safe($seedBefore !== '' && $seedBefore !== '[]' && !str_contains(strtolower((string) $seedBefore), 'andra@example.test'), 'sekundär e-post ligger bara i contact_emails');

$putList = $pdo->prepare('INSERT INTO inkorg_list (id, company, customer_email, list_at, list_status, contact_id, row_json) VALUES (?, ?, ?, ?, ?, ?, ?)');
$putThread = $pdo->prepare('INSERT INTO inkorg_threads (id, status, updated_at, row_json) VALUES (?, ?, ?, ?)');
$ids = [];
$store = static function (string $key, string $company, array $lead) use ($putList, $putThread, &$ids): string {
    $id = 'ink_' . bin2hex(random_bytes(8));
    $ids[$key] = $id;
    $row = [
        'id' => $id,
        'company' => $company,
        'list_type' => 'Ny lead',
        'customer_email' => (string) ($lead['email'] ?? ''),
        'customer_name' => (string) ($lead['name'] ?? ''),
        'contact_id' => '',
        'lead_customer' => $lead,
    ];
    $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $putList->execute([$id, $company, (string) ($lead['email'] ?? ''), '2026-10-01T10:00:00+02:00', 'open', '', $json]);
    $putThread->execute([$id, 'open', '2026-10-01T10:00:00+02:00', $json]);

    return $id;
};
$store('email', 'jarfalla', ['name' => 'Sekund Mejl', 'email' => 'Andra@Example.TEST', 'phone' => '070-222 33 44', 'address' => '']);
$store('phone', 'jarfalla', ['name' => 'Telefon Dup', 'email' => 'telefon.dup@example.test', 'phone' => '0046 70 111 22 33', 'address' => '']);
$store('address', 'malmo', ['name' => 'Adress Dup', 'email' => 'adress.dup@example.test', 'phone' => '070-333 44 55', 'address' => 'Storgatan 12, 177 70 Järfälla']);
$store('twin-a', 'malmo', ['name' => 'Tvilling A', 'email' => 'tvilling@example.test', 'phone' => '070-444 55 66', 'address' => '']);
$store('twin-b', 'malmo', ['name' => 'Tvilling B', 'email' => 'Tvilling@Example.TEST', 'phone' => '070-444 55 77', 'address' => '']);
$store('clean-a', 'jarfalla', ['name' => 'Ren Ett', 'email' => 'ren.ett@example.test', 'phone' => '070-555 66 77', 'address' => '']);
$store('clean-b', 'malmo', ['name' => 'Ren Två', 'email' => 'ren.tva@example.test', 'phone' => '070-555 66 88', 'address' => '']);
$store('other-postal', 'malmo', ['name' => 'Annat Postnr', 'email' => 'annat.postnr@example.test', 'phone' => '070-555 66 99', 'address' => 'Storgatan 12, 211 20']);

$pii = [
    'primary@example.test',
    'andra@example.test',
    'Andra@Example.TEST',
    'tvilling@example.test',
    'Bo Dup',
    'Sekund Mejl',
    'Storgatan',
    '070-111',
    '46701112233',
    '17770',
    '177 70',
    '211 20',
];
$noPii = static function (string $encoded) use ($pii): bool {
    $lower = strtolower($encoded);
    foreach ($pii as $needle) {
        if (str_contains($lower, strtolower($needle))) {
            return false;
        }
    }

    return true;
};

$dry = jeab_inkorg_contact_create_run($pdo, []);
$encoded = json_encode($dry, JSON_UNESCAPED_UNICODE);
safe(($dry['dry'] ?? false) === true && (int) ($dry['created'] ?? 1) === 0 && (int) ($dry['sent'] ?? 1) === 0, 'torr körning skapar inget och skickar inget');
safe(is_string($encoded) && $noPii($encoded), 'svaret har bara id och orsak');
safe(json_encode($seedRow(), JSON_UNESCAPED_UNICODE) === $seedBefore, 'dubblettkontrollen skriver inte i registret');
$undoDir = $tmp . '/.private/inkorg-contact-create';
safe(!is_dir($undoDir) || glob($undoDir . '/*.json') === [], 'torr körning skriver ingen ångerfil');

$reasons = [];
foreach ((array) ($dry['uncertain'] ?? []) as $row) {
    if (is_array($row)) {
        $reasons[(string) ($row['id'] ?? '')] = (string) ($row['reason'] ?? '');
    }
}
safe(($reasons[$ids['email']] ?? '') === 'dup_existing_email', 'sekundär e-post mot registret är osäker');
safe(($reasons[$ids['phone']] ?? '') === 'dup_existing_phone', 'svenskt telefonnummer normaliseras mot registret');
safe(($reasons[$ids['address']] ?? '') === 'dup_existing_address', 'adress och postnummer mot registret är osäkert');
safe(($reasons[$ids['twin-a']] ?? '') === 'dup_candidate' && ($reasons[$ids['twin-b']] ?? '') === 'dup_candidate', 'två kandidater med samma e-post är osäkra');
$clean = (array) ($dry['clean_ids'] ?? []);
safe(in_array($ids['clean-a'], $clean, true) && in_array($ids['clean-b'], $clean, true) && in_array($ids['other-postal'], $clean, true), 'rena id och annan postadress är rena');
safe(!in_array($ids['email'], $clean, true) && !in_array($ids['twin-a'], $clean, true), 'osäkra id ligger inte bland de rena');
safe((int) ($dry['clean_count'] ?? 0) === 3 && (int) ($dry['uncertain_count'] ?? 0) === 5 && (int) ($dry['would_create'] ?? 0) === 3, 'skarp körning utan only_ids skulle bara skapa de rena');
$companies = is_array($dry['companies'] ?? null) ? $dry['companies'] : [];
$jarfalla = is_array($companies['jarfalla'] ?? null) ? $companies['jarfalla'] : [];
$malmo = is_array($companies['malmo'] ?? null) ? $companies['malmo'] : [];
safe((int) ($jarfalla['clean_count'] ?? -1) === 1 && (int) ($jarfalla['uncertain_count'] ?? -1) === 2, 'Järfälla räknas för sig');
safe((int) ($malmo['clean_count'] ?? -1) === 2 && (int) ($malmo['uncertain_count'] ?? -1) === 3, 'Malmö räknas för sig');

$preview = jeab_inkorg_contact_create_run($pdo, ['only_ids' => [$ids['clean-a']]]);
safe((int) ($preview['would_create'] ?? 0) === 1 && (int) ($preview['clean_count'] ?? 0) === 3 && (int) ($preview['created'] ?? 1) === 0, 'only_ids i torr körning begränsar det som skulle skrivas');

$one = jeab_inkorg_contact_create_run($pdo, ['dry' => 0, 'only_ids' => [$ids['clean-a'], 'inte-ett-id']]);
safe((int) ($one['created'] ?? 0) === 1 && (int) ($one['sent'] ?? 1) === 0, 'only_ids skapar exakt det rena id:t');
$cleanB = (string) $pdo->query('SELECT contact_id FROM inkorg_list WHERE id = ' . $pdo->quote($ids['clean-b']))->fetchColumn();
$emailRow = (string) $pdo->query('SELECT contact_id FROM inkorg_list WHERE id = ' . $pdo->quote($ids['email']))->fetchColumn();
safe($cleanB === '' && $emailRow === '', 'övriga rena och osäkra lämnas');
$undoId = (string) ($one['undo_id'] ?? '');
$undoPath = $undoDir . '/' . $undoId . '.json';
$undoRaw = is_file($undoPath) ? (string) file_get_contents($undoPath) : '';
$undoFile = json_decode($undoRaw, true);
safe(preg_match('/\Auc_[a-f0-9]{16}\z/', $undoId) === 1 && is_array($undoFile), 'ångerfilen ligger under data/.private');
safe($noPii($undoRaw), 'ångerfilen har id, inte personuppgifter');
$madeId = (string) ($undoFile['contacts'][0]['id'] ?? '');
safe($madeId !== '' && $madeId !== $seedId && str_starts_with($madeId, 'kt_'), 'ångerfilen pekar på den nya kontakten');
$linkedTables = array_column((array) ($undoFile['links'] ?? []), 'table');
safe(in_array('inkorg_list', $linkedTables, true) && in_array('inkorg_threads', $linkedTables, true), 'ångerfilen har de rader som kopplats');

$undoDry = jeab_inkorg_contact_create_undo_run($pdo, ['undo_id' => $undoId]);
safe(($undoDry['dry'] ?? false) === true && (int) ($undoDry['deleted'] ?? 1) === 0 && (int) ($undoDry['would_delete'] ?? 0) === 1, 'ångra är torr som standard');
unset($GLOBALS['_JEAB_KONTAKTER_MEM'], $GLOBALS['_JEAB_KONTAKTER_MEM_KEY']);
safe(jeab_kontakt_fetch($madeId) !== null, 'torr ångring tar inte bort kontakten');

$undoWet = jeab_inkorg_contact_create_undo_run($pdo, ['undo_id' => $undoId, 'dry' => 0]);
safe((int) ($undoWet['deleted'] ?? 0) === 1 && (int) ($undoWet['unlinked'] ?? 0) >= 1 && (int) ($undoWet['sent'] ?? 1) === 0, 'ångra tar bort kontakten och kopplar loss');
unset($GLOBALS['_JEAB_KONTAKTER_MEM'], $GLOBALS['_JEAB_KONTAKTER_MEM_KEY']);
safe(jeab_kontakt_fetch($madeId) === null, 'kontakten är borta');
safe((string) $pdo->query('SELECT contact_id FROM inkorg_list WHERE id = ' . $pdo->quote($ids['clean-a']))->fetchColumn() === '', 'ärendet är okopplat');
safe($seedIdentity() === $seedSame, 'ångra rör inte den befintliga kontakten');

$none = jeab_inkorg_contact_create_run($pdo, ['dry' => 0, 'only_ids' => []]);
safe((int) ($none['created'] ?? 1) === 0, 'tom only_ids skapar inget');

$wet = jeab_inkorg_contact_create_run($pdo, ['dry' => 0]);
safe((int) ($wet['created'] ?? 0) === 3 && (int) ($wet['sent'] ?? 1) === 0, 'utan only_ids skapas bara de rena');
foreach (['email', 'phone', 'address', 'twin-a', 'twin-b'] as $key) {
    $linkedId = (string) $pdo->query('SELECT contact_id FROM inkorg_list WHERE id = ' . $pdo->quote($ids[$key]))->fetchColumn();
    safe($linkedId === '', 'osäker kandidat skapas inte: ' . $key);
}
safe($seedIdentity() === $seedSame, 'befintlig kontakt slås inte ihop');
$batchUndo = (string) ($wet['undo_id'] ?? '');
$batchPath = $undoDir . '/' . $batchUndo . '.json';
$batch = json_decode((string) file_get_contents($batchPath), true);
safe(is_array($batch) && count((array) ($batch['contacts'] ?? [])) === 3, 'ångerfilen har de tre nya kontakterna');
$touched = (string) ($batch['contacts'][0]['id'] ?? '');
jeab_kontakter_mutate(static function (array &$contacts) use ($touched): void {
    if (isset($contacts[$touched]) && is_array($contacts[$touched])) {
        $contacts[$touched]['updated_at'] = '2099-01-01 00:00:00';
        $contacts[$touched]['ovrigt'] = 'ändrad';
    }
}, true);
$undoTouched = jeab_inkorg_contact_create_undo_run($pdo, ['undo_id' => $batchUndo, 'dry' => 0]);
safe((int) ($undoTouched['skipped_touched'] ?? 0) === 1 && (int) ($undoTouched['deleted'] ?? 0) === 2, 'ändrad kontakt lämnas, orörda tas bort');
unset($GLOBALS['_JEAB_KONTAKTER_MEM'], $GLOBALS['_JEAB_KONTAKTER_MEM_KEY']);
safe(is_array(jeab_kontakt_fetch($touched)), 'ändrad kontakt finns kvar');
$touchedInbox = '';
foreach ((array) ($batch['links'] ?? []) as $link) {
    if (is_array($link) && (string) ($link['contact_id'] ?? '') === $touched && (string) ($link['table'] ?? '') === 'inkorg_list') {
        $touchedInbox = (string) ($link['id'] ?? '');
    }
}
safe($touchedInbox !== '' && (string) $pdo->query('SELECT contact_id FROM inkorg_list WHERE id = ' . $pdo->quote($touchedInbox))->fetchColumn() === $touched, 'ändrad kontakt förblir kopplad');
safe($seedIdentity() === $seedSame, 'ångra av körningen lämnar registret i övrigt');

$twin = jeab_inkorg_contact_create_run($pdo, ['dry' => 0, 'only_ids' => [$ids['twin-a']]]);
safe((int) ($twin['created'] ?? 0) === 1, 'only_ids får skapa en utpekad osäker kandidat');
$twinId = (string) $pdo->query('SELECT contact_id FROM inkorg_list WHERE id = ' . $pdo->quote($ids['twin-a']))->fetchColumn();
$twinOther = (string) $pdo->query('SELECT contact_id FROM inkorg_list WHERE id = ' . $pdo->quote($ids['twin-b']))->fetchColumn();
safe($twinId !== '' && $twinId !== $seedId && $twinOther === '', 'tvillingen får en egen kontakt och den andra lämnas');
safe($seedIdentity() === $seedSame, 'utpekad dubblett slås inte ihop med registret');

$seedSnap = jeab_inkorg_contact_create_contact_snapshot($pdo, $seedId);
safe(is_array($seedSnap), 'ögonblicksbild av befintlig kontakt');
$foreignId = 'uc_' . bin2hex(random_bytes(8));
$foreignPath = $undoDir . '/' . $foreignId . '.json';
file_put_contents($foreignPath, json_encode([
    'undo_id' => $foreignId,
    'contacts' => [$seedSnap],
    'links' => [],
], JSON_UNESCAPED_UNICODE));
$foreign = jeab_inkorg_contact_create_undo_run($pdo, ['undo_id' => $foreignId, 'dry' => 0]);
safe((int) ($foreign['deleted'] ?? 1) === 0 && (int) ($foreign['skipped_foreign'] ?? 0) === 1, 'ångra tar inte bort en kontakt som körningen inte skapade');
safe($seedIdentity() === $seedSame, 'registret har kvar den kontakten');

$missing = jeab_inkorg_contact_create_undo_run($pdo, []);
safe(($missing['ok'] ?? true) === false && ($missing['dry'] ?? false) === true, 'ångra utan id är torr och gör inget');
$denied = jeab_inkorg_contact_create_undo_dispatch(['undo_id' => $undoId, 'dry' => 0]);
safe(($denied['ok'] ?? true) === false && ($denied['error'] ?? '') === 'Fel token.', 'ångra utan token skrivs inte');
$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
safe(str_contains($hook, 'data.inkorg_contact_create_undo'), 'hooken tar emot ångra');
$src = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg_contact_create.php');
foreach (['jeab_kontakt_merge', 'jeab_kontakt_dedupe_fold', 'jeab_system_mail', 'jeab_sms_send', 'jeab_inkorg_send'] as $needle) {
    safe(!str_contains($src, $needle), 'modulen anropar inte ' . $needle);
}

echo "inkorg_contact_create_safe_test ok\n";
