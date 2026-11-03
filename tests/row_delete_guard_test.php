<?php

declare(strict_types=1);

/**
 * Inkorgskontakter och anteckningar skriver bara ändrade rader.
 * Tom lista raderar inget. Radering större än 5 % eller 20 rader vägras.
 * kontakter.dedupe och kontakter.prune kräver allow_mass=1 för den raderingen.
 * Påhittad data. Inget skickas.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-row-guard-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$log = $tmp . '/error.log';
ini_set('error_log', $log);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function rg(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function rg_log(): string
{
    $path = ini_get('error_log');
    return is_string($path) && is_file($path) ? (string) file_get_contents($path) : '';
}

/** @return array<string, mixed> */
function rg_contact(string $id, string $name, string $street, string $email): array
{
    $row = jeab_kontakt_blank([
        'name' => $name,
        'email' => $email,
        'street' => $street,
        'street_key' => jeab_kontakt_street_key($street),
        'postal_code' => '11122',
        'city' => 'Teststad',
    ]);
    $row['id'] = $id;

    return $row;
}

$pdo = jeab_contact_store_pdo();
rg($pdo instanceof PDO, 'sqlite är öppen');
rg(jeab_settings_put($pdo, 'sqlite', 'kontakter_storage', 'sql'), 'sql-flaggan går att sätta');

$inbox = new JeabPdoInboxContactStore($pdo);
$inboxRows = [];
for ($i = 1; $i <= 22; $i++) {
    $id = sprintf('in_%02d', $i);
    $inboxRows[$id] = [
        'id' => $id,
        'name' => 'Inkorg ' . $i,
        'email' => $id . '@example.test',
        'updated_at' => '2026-10-01 08:00:00',
    ];
    $inbox->upsert($id, $inboxRows[$id]);
}
$inboxRaw = [];
foreach ($pdo->query('SELECT id, row_json FROM inkorg_contacts') as $dbRow) {
    $inboxRaw[(string) $dbRow['id']] = (string) $dbRow['row_json'];
}
$inbox->replaceAll([]);
rg($inbox->count() === 22, 'tom inkorgslista raderar inget');
rg(str_contains(rg_log(), 'Inkorgskontakter: tom lista'), 'tom inkorgslista loggas');

$keptInbox = $inboxRows;
unset($keptInbox['in_01'], $keptInbox['in_02']);
$inbox->replaceAll($keptInbox);
rg($inbox->count() === 22, 'två inkorgsrader är mer än 5 procent och raderas inte');
rg(str_contains(rg_log(), 'Inkorgskontakter: radering vägras'), 'vägrad inkorgsradering loggas');

$oneInbox = $inboxRows;
unset($oneInbox['in_22']);
$oneInbox['in_01']['name'] = 'Ändrad inkorg';
$inbox->replaceAll($oneInbox);
rg($inbox->count() === 21 && !isset($inbox->sqlAll()['in_22']), 'en inkorgsrad under spärren raderas');
$inboxAfter = [];
foreach ($pdo->query('SELECT id, row_json FROM inkorg_contacts') as $dbRow) {
    $inboxAfter[(string) $dbRow['id']] = (string) $dbRow['row_json'];
}
$inboxSame = 0;
foreach ($inboxRaw as $id => $json) {
    if ($id === 'in_22' || $id === 'in_01') {
        continue;
    }
    if (($inboxAfter[$id] ?? '') === $json) {
        $inboxSame++;
    }
}
rg($inboxSame === 20, 'övriga inkorgsrader skrivs inte om');
$inboxChanged = json_decode((string) ($inboxAfter['in_01'] ?? ''), true);
rg(is_array($inboxChanged) && (string) ($inboxChanged['name'] ?? '') === 'Ändrad inkorg', 'den ändrade inkorgsraden är sparad');

jeab_notes_store_ensure($pdo);
jeab_notes_store_put_state($pdo, 1, 1);
$noteRows = [];
for ($i = 1; $i <= 22; $i++) {
    $noteRows[] = [
        'id' => $i,
        'title' => 'Anteckning ' . $i,
        'body' => 'Påhittad rad.',
        'section' => 'faltnoteringar',
    ];
    $payload = jeab_notes_store_payload($noteRows[$i - 1]);
    if (!is_string($payload)) {
        rg(false, 'anteckningen kan kodas');
    }
    jeab_notes_store_upsert($pdo, $i, (string) $payload);
}
$noteRaw = [];
foreach ($pdo->query('SELECT id, payload FROM notes') as $dbRow) {
    $noteRaw[(int) $dbRow['id']] = (string) $dbRow['payload'];
}
jeab_notes_store_replace($pdo, ['next_id' => 23, 'notes' => []]);
rg((int) $pdo->query('SELECT COUNT(*) FROM notes')->fetchColumn() === 22, 'tom anteckningslista raderar inget');
rg(str_contains(rg_log(), 'Anteckningar: tom lista'), 'tom anteckningslista loggas');

$twoNotes = $noteRows;
unset($twoNotes[0], $twoNotes[1]);
jeab_notes_store_replace($pdo, ['next_id' => 23, 'notes' => array_values($twoNotes)]);
rg((int) $pdo->query('SELECT COUNT(*) FROM notes')->fetchColumn() === 22, 'två anteckningar är mer än 5 procent och raderas inte');
rg(str_contains(rg_log(), 'Anteckningar: radering vägras'), 'vägrad anteckningsradering loggas');

$oneNote = $noteRows;
unset($oneNote[21]);
$oneNote[0]['title'] = 'Ändrad anteckning';
jeab_notes_store_replace($pdo, ['next_id' => 23, 'notes' => array_values($oneNote)]);
$noteLeft = (int) $pdo->query('SELECT COUNT(*) FROM notes')->fetchColumn();
$noteGone = $pdo->prepare('SELECT id FROM notes WHERE id = ?');
$noteGone->execute([22]);
rg($noteLeft === 21 && $noteGone->fetchColumn() === false, 'en anteckning under spärren raderas');
$noteAfter = [];
foreach ($pdo->query('SELECT id, payload FROM notes') as $dbRow) {
    $noteAfter[(int) $dbRow['id']] = (string) $dbRow['payload'];
}
$noteSame = 0;
foreach ($noteRaw as $id => $payload) {
    if ($id === 22 || $id === 1) {
        continue;
    }
    if (($noteAfter[$id] ?? '') === $payload) {
        $noteSame++;
    }
}
rg($noteSame === 20, 'övriga anteckningar skrivs inte om');
$noteChanged = json_decode((string) ($noteAfter[1] ?? ''), true);
rg(is_array($noteChanged) && (string) ($noteChanged['title'] ?? '') === 'Ändrad anteckning', 'den ändrade anteckningen är sparad');

$storeSrc = (string) file_get_contents(dirname(__DIR__) . '/src/kontakt_store.php');
$inboxStart = (int) strpos($storeSrc, 'class JeabPdoInboxContactStore');
$inboxSrc = $inboxStart >= 0 ? substr($storeSrc, $inboxStart) : '';
rg($inboxSrc !== '' && !str_contains($inboxSrc, 'NOT IN') && !preg_match('/DELETE FROM inkorg_contacts\s*[\'"]/', $inboxSrc), 'inkorgskontakter har ingen DELETE utan WHERE och ingen NOT IN');
rg(str_contains($inboxSrc, 'DELETE FROM inkorg_contacts WHERE id = ?'), 'inkorgskontakter raderas rad för rad');
$notesSrc = (string) file_get_contents(dirname(__DIR__) . '/src/notes_store.php');
rg(!str_contains($notesSrc, 'NOT IN') && !preg_match('/DELETE FROM notes\s*[\'"]/', $notesSrc), 'anteckningar har ingen DELETE utan WHERE och ingen NOT IN');
rg(str_contains($notesSrc, 'DELETE FROM notes WHERE id = ?'), 'anteckningar raderas rad för rad');

$registry = new JeabPdoContactStore($pdo);
$keepers = [];
for ($i = 1; $i <= 30; $i++) {
    $id = sprintf('kt_keep_%02d', $i);
    $keepers[$id] = rg_contact($id, 'Kund ' . $i, 'Hållgatan ' . $i, $id . '@example.test');
}
jeab_kontakter_mutate(static function (array &$contacts) use ($keepers): void {
    foreach ($keepers as $id => $row) {
        $contacts[$id] = $row;
    }
});
$smallId = 'kt_news_small';
$small = rg_contact($smallId, 'Nyhetsbrev Liten', 'Brevgatan 1', 'nyhetsbrev-liten@example.test');
jeab_kontakter_mutate(static function (array &$contacts) use ($smallId, $small): void {
    $contacts[$smallId] = $small;
});
$beforeSmall = count(jeab_kontakter_load());
$store = jeab_store();
rg($store instanceof JeabStore, 'lagret är öppet');
$drySmall = jeab_kontakter_prune([], $store);
$smallHit = false;
foreach ((array) ($drySmall['contacts'] ?? []) as $candidate) {
    if (is_array($candidate) && (string) ($candidate['id'] ?? '') === $smallId) {
        $smallHit = true;
    }
}
rg(($drySmall['dry'] ?? false) === true && $smallHit && (int) ($drySmall['would_remove'] ?? 0) >= 1 && ($drySmall['blocked'] ?? true) === false, 'prune dry=1 räknar en liten radering och den är inte spärrad');
rg(isset(jeab_kontakter_load()[$smallId]), 'torrkörningen lämnar kontakten');
$wetSmall = jeab_kontakter_prune(['dry' => 0], $store);
rg(($wetSmall['ok'] ?? false) === true && !isset(jeab_kontakter_load()[$smallId]) && count(jeab_kontakter_load()) === $beforeSmall - 1, 'en prune under spärren genomförs utan allow_mass');

$news = [];
for ($i = 1; $i <= 3; $i++) {
    $id = sprintf('kt_news_%02d', $i);
    $news[$id] = rg_contact($id, 'Nyhetsbrev ' . $i, 'Brevgatan ' . (10 + $i), 'nyhetsbrev-' . $i . '@example.test');
}
jeab_kontakter_mutate(static function (array &$contacts) use ($news): void {
    foreach ($news as $id => $row) {
        $contacts[$id] = $row;
    }
});
$beforeBlocked = jeab_kontakter_load();
$dryBlocked = jeab_kontakter_prune([], $store);
rg(($dryBlocked['ok'] ?? false) === true && ($dryBlocked['blocked'] ?? false) === true && (int) ($dryBlocked['would_remove'] ?? 0) === 3 && (int) ($dryBlocked['removed'] ?? 1) === 0, 'prune dry=1 rapporterar antalet som skulle spärras');
rg(jeab_kontakter_load() === $beforeBlocked, 'spärrad torrkörning skriver inget');
$wetBlocked = jeab_kontakter_prune(['dry' => 0], $store);
rg(($wetBlocked['ok'] ?? true) === false && str_contains((string) ($wetBlocked['error'] ?? ''), 'allow_mass=1') && str_contains((string) ($wetBlocked['error'] ?? ''), '3 av'), 'prune utan allow_mass ger ett tydligt fel');
rg(jeab_kontakter_load() === $beforeBlocked, 'spärrad prune raderar inget');
$wetMass = jeab_kontakter_prune(['dry' => 0, 'allow_mass' => 1], $store);
rg(($wetMass['ok'] ?? false) === true && (int) ($wetMass['removed'] ?? 0) === 3 && !isset(jeab_kontakter_load()['kt_news_01']) && isset(jeab_kontakter_load()['kt_keep_01']), 'prune allow_mass=1 tar bort de spärrade raderna');

$pairA = rg_contact('kt_pair_a', 'Ada Par', 'Parvägen 4', 'ada.par@example.test');
$pairB = rg_contact('kt_pair_b', 'Ada Par', 'Parvägen 4', 'ada.par.tv@example.test');
jeab_kontakter_mutate(static function (array &$contacts) use ($pairA, $pairB): void {
    $contacts['kt_pair_a'] = $pairA;
    $contacts['kt_pair_b'] = $pairB;
});
$dedupeDry = jeab_kontakter_dedupe([]);
rg(($dedupeDry['dry'] ?? false) === true && (int) ($dedupeDry['counts']['would_remove'] ?? 0) === 1 && ($dedupeDry['blocked'] ?? true) === false, 'dedupe dry=1 räknar en hopslagning under spärren');
$beforePair = count(jeab_kontakter_load());
$dedupeWet = jeab_kontakter_dedupe(['dry' => 0]);
rg(($dedupeWet['ok'] ?? false) === true && count(jeab_kontakter_load()) === $beforePair - 1, 'en hopslagning under spärren genomförs utan allow_mass');

$extra = [];
for ($i = 1; $i <= 3; $i++) {
    $street = 'Massvägen ' . $i;
    $extra['kt_mass_a_' . $i] = rg_contact('kt_mass_a_' . $i, 'Bo Mass ' . $i, $street, 'bo.mass.' . $i . '.a@example.test');
    $extra['kt_mass_b_' . $i] = rg_contact('kt_mass_b_' . $i, 'Bo Mass ' . $i, $street, 'bo.mass.' . $i . '.b@example.test');
}
jeab_kontakter_mutate(static function (array &$contacts) use ($extra): void {
    foreach ($extra as $id => $row) {
        $contacts[$id] = $row;
    }
});
$beforeMass = count(jeab_kontakter_load());
$massDry = jeab_kontakter_dedupe([]);
rg(($massDry['ok'] ?? false) === true && ($massDry['blocked'] ?? false) === true && (int) ($massDry['counts']['would_remove'] ?? 0) >= 3 && count(jeab_kontakter_load()) === $beforeMass, 'dedupe dry=1 rapporterar en spärrad hopslagning');
$massWet = jeab_kontakter_dedupe(['dry' => '0']);
rg(($massWet['ok'] ?? true) === false && str_contains((string) ($massWet['error'] ?? ''), 'allow_mass=1') && count(jeab_kontakter_load()) === $beforeMass, 'dedupe utan allow_mass ger ett tydligt fel och raderar inget');
$massOk = jeab_kontakter_dedupe(['dry' => 0, 'allow_mass' => '1']);
rg(($massOk['ok'] ?? false) === true && count(jeab_kontakter_load()) < $beforeMass && isset(jeab_kontakter_load()['kt_keep_30']), 'dedupe allow_mass=1 genomför den spärrade hopslagningen');
$beforeWipe = count(jeab_kontakter_load());
jeab_kontakter_mutate(static function (array &$contacts): void {
    $contacts = [];
}, true);
rg($beforeWipe > 0 && count(jeab_kontakter_load()) === 0, 'allow_mass=1 får ta bort hela listan när kroken ber om det');

echo "ok\n";
