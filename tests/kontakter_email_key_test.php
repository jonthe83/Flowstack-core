<?php

declare(strict_types=1);

/**
 * PR C: e-post är kontaktens nyckel. SQLite, påhittade personer, inga utskick.
 * Normalisering, mejl före gata, aldrig bara adress, dubblett-dry-run, merge (write=1) och unikt index.
 */

$tmp = sys_get_temp_dir() . '/jeab-email-key-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
putenv('DATA_BACKEND=mysql');
putenv('CONTACTS_SOLE=0');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/inkorg_contacts.php';
require_once dirname(__DIR__) . '/src/kontakter_email_key.php';

function ek(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

// Normalisering på ett ställe.
ek(jeab_kontakt_email('  Anna.Prov@Example.TEST ') === 'anna.prov@example.test', 'trim och gemener');
ek(jeab_kontakt_email('Anna Prov <Anna@Example.test>') === 'anna@example.test', 'namn <adress> skalas');
ek(jeab_kontakt_email('mailto:anna@example.test') === 'anna@example.test', 'mailto: skalas');
ek(jeab_kontakt_email('') === '' && jeab_kontakt_email('anna@@x') === '' && jeab_kontakt_email('an na@x.se') === '' && jeab_kontakt_email('@x.se') === '' && jeab_kontakt_email('anna@') === '', 'tomt och ogiltigt blir tomt');
ek(jeab_contact_email_key('B@X.se') === 'b@x.se', 'alias jeab_contact_email_key');

// Mejl före gata: samma gata på en namnlös kontakt ska inte vinna över mejlträffen (Ekholm-fallet).
$street = jeab_kontakt_street_key('Provgatan 7');
$a = jeab_kontakt_blank(['name' => '', 'street' => 'Provgatan 7', 'street_key' => $street, 'postal_code' => '19592', 'city' => 'Märsta']);
$b = jeab_kontakt_blank(['name' => 'Mattias Ekprov', 'email' => 'mattias.ekprov@example.test', 'street' => 'Annan väg 1', 'street_key' => jeab_kontakt_street_key('Annan väg 1'), 'postal_code' => '19592', 'city' => 'Märsta']);
$set = [$a['id'] => $a, $b['id'] => $b];
$hit = jeab_kontakt_find($set, $street, '19592', 'Mattias.Ekprov@example.test', '', true, 'Mattias Ekprov', 'Märsta', false, true);
ek($hit === $b['id'], 'mejlet vinner över gatan');
$hit = jeab_kontakt_find($set, $street, '19592', 'caroline.ekprov@example.test', '', true, '', 'Märsta');
ek($hit === null, 'bara adressen räcker aldrig');
ek(jeab_kontakt_address_reuse_conflict($a, 'Caroline Ekprov', 'caroline.ekprov@example.test', ''), 'namnlös kontakt på samma gata är en konflikt');

// Dubbletter i SQL.
$store = jeab_store();
ek($store instanceof JeabPdoStore, 'sqlite-lagret finns');
$pdo = $store->pdo();
jeab_ensure_contacts_tables($pdo, 'sqlite');
$ids = jeab_kontakter_mutate(static function (array &$contacts): array {
    $out = [];
    foreach ([
        ['Sara Dupprov', 'sara.dupprov@example.test', 'Dupgatan 1'],
        ['Sara Dupprov', 'Sara.Dupprov@example.test ', 'Dupgatan 1'],
        ['Kalle Husprov', 'hus.prov@example.test', 'Husvägen 2'],
        ['Lena Husprov', 'hus.prov@example.test', 'Husvägen 2'],
        ['Ensam Prov', 'ensam.prov@example.test', 'Ensamväg 3'],
        ['Olle Ordprov', 'ord.prov@example.test', 'Ordväg 4'],
        ['Olle Ordprov', 'ord.prov@example.test', 'Ordväg 4'],
        ['Nils Snoprov', 'sno.prov@example.test', 'Snoväg 5'],
        ['Nils Snoprov', 'sno.prov@example.test', 'Snoväg 5'],
        ['Klar Prov', 'klar.prov@example.test', 'Klarväg 6'],
        ['Klar Prov', 'klar.prov@example.test', 'Klarväg 6'],
        ['Pay Prov', 'pay.prov@example.test', 'Payväg 7'],
        ['Pay Prov', 'pay.prov@example.test', 'Payväg 7'],
    ] as [$n, $e, $s]) {
        $row = jeab_kontakt_blank(['name' => $n, 'email' => $e, 'street' => $s, 'street_key' => jeab_kontakt_street_key($s), 'postal_code' => '17676', 'city' => 'Järfälla']);
        $contacts[$row['id']] = $row;
        $out[] = $row['id'];
    }
    return $out;
});
$pdo->exec("INSERT INTO reminder_lists (name, created_at) VALUES ('Prov', '2026-01-01 00:00:00')");
$pdo->prepare("INSERT INTO reminders (list_id, title, contact_id, created_at, updated_at) VALUES (1, 'Ring', ?, ?, ?)")
    ->execute([$ids[2], date('Y-m-d H:i:s', time() - 3 * 86400), date('Y-m-d H:i:s', time() - 3 * 86400)]);
// ord: order via purchases.contact_id. sno: snoozad öppen påminnelse. klar: bara slutförd påminnelse. pay: kontakt-id i orderns payload.
$old = date('Y-m-d H:i:s', time() - 400 * 86400);
$pdo->prepare("INSERT INTO reminders (list_id, title, contact_id, due_at, created_at, updated_at) VALUES (1, 'Snooz', ?, ?, ?, ?)")
    ->execute([$ids[7], date('Y-m-d H:i:s', time() + 2 * 86400), $old, $old]);
$pdo->prepare("INSERT INTO reminders (list_id, title, contact_id, completed_at, created_at, updated_at) VALUES (1, 'Klar', ?, ?, ?, ?)")
    ->execute([$ids[9], $old, $old, $old]);
if (function_exists('jeab_purchases_ensure_columns')) {
    jeab_purchases_ensure_columns($pdo, 'sqlite');
}
$pcols = array_column($pdo->query('PRAGMA table_info(purchases)')->fetchAll(PDO::FETCH_ASSOC), 'name');
if (!in_array('contact_id', $pcols, true)) {
    $pdo->exec("ALTER TABLE purchases ADD COLUMN contact_id TEXT NOT NULL DEFAULT ''");
}
$pdo->prepare("INSERT INTO purchases (reminder_id, payload, updated_at, contact_id) VALUES (901, '{}', ?, ?)")->execute([$old, $ids[5]]);
$pdo->prepare("INSERT INTO purchases (reminder_id, payload, updated_at, contact_id) VALUES (902, ?, ?, '')")->execute([json_encode(['contact_id' => $ids[12]]), $old]);
// Bumpat contacts.updated_at gör inte gruppen aktiv.
$pdo->exec("UPDATE contacts SET updated_at = '" . date('Y-m-d H:i:s') . "'");
$countBefore = (int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn();

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
ek(str_contains($hook, "'kontakter.email_dupes'") && str_contains($hook, 'jeab_email_key_dispatch'), 'hook.php registrerar hookarna');
ek(in_array('kontakter_email_key.php', jeab_bootstrap_legacy_files(), true), 'lazy bootstrap laddar modulen');
$_SERVER['HTTP_AUTHORIZATION'] = '';
ek((jeab_email_key_dispatch('kontakter.email_dupes', [])['status'] ?? 0) === 401, 'utan token ger 401');

$d = jeab_email_key_dupes(['write' => 1]);
ek(!empty($d['ok']) && $d['written'] === false, 'dry-run skriver inget även med write=1');
ek($d['counts']['groups'] === 6 && $d['counts']['contacts'] === 12 && $d['counts']['households'] === 1, 'sex grupper, tolv kontakter, ett hushåll');
$byEmail = [];
foreach ($d['groups'] as $g) {
    $byEmail[$g['email']] = $g;
}
ek(($byEmail['sara.dupprov@example.test']['class'] ?? '') === 'trolig samma person', 'samma namn: trolig samma person');
ek(($byEmail['hus.prov@example.test']['class'] ?? '') === 'hushåll (olika namn)', 'olika namn: hushåll');
ek(!empty($byEmail['hus.prov@example.test']['active']) && $d['groups'][0]['email'] === 'hus.prov@example.test', 'aktiv grupp (påminnelse) först');
$m = array_values(array_filter($byEmail['hus.prov@example.test']['contacts'], static fn(array $c): bool => $c['id'] === $ids[2]))[0];
ek($m['reminders'] === 1 && $m['active'] === true && $m['address'] !== '', 'påminnelse och adress per kontakt');
ek((int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn() === $countBefore, 'dry-run ändrade inga rader');
$c5 = array_values(array_filter($byEmail['ord.prov@example.test']['contacts'], static fn(array $c): bool => $c['id'] === $ids[5]))[0];
ek($c5['orders'] === 1 && $byEmail['ord.prov@example.test']['mergeable'] === false && in_array('order', $byEmail['ord.prov@example.test']['blockers'], true), 'order via contact_id blockerar');
$c12 = array_values(array_filter($byEmail['pay.prov@example.test']['contacts'], static fn(array $c): bool => $c['id'] === $ids[12]))[0];
ek($c12['orders'] === 1 && $byEmail['pay.prov@example.test']['mergeable'] === false, 'order via payload blockerar');
$c7 = array_values(array_filter($byEmail['sno.prov@example.test']['contacts'], static fn(array $c): bool => $c['id'] === $ids[7]))[0];
ek($c7['reminders_open'] === 1 && $c7['reminders_snoozed'] === 1 && $byEmail['sno.prov@example.test']['mergeable'] === false, 'snoozad öppen påminnelse blockerar och visas separat');
ek($byEmail['klar.prov@example.test']['mergeable'] === true && $byEmail['klar.prov@example.test']['active'] === false, 'slutförd gammal påminnelse: inte aktiv, kan slås ihop');
ek($byEmail['sara.dupprov@example.test']['active'] === false, 'bumpat contacts.updated_at gör inte gruppen aktiv');
ek($byEmail['hus.prov@example.test']['mergeable'] === false, 'öppen påminnelse blockerar');

// Unikt index: inte aktivt, vägrar med dubbletter.
$ix = jeab_email_key_unique_index([]);
ek(!empty($ix['ok']) && $ix['exists'] === false && $ix['written'] === false, 'indexet finns inte och dry skriver inget');
$ixw = jeab_email_key_unique_index(['write' => 1]);
ek(empty($ixw['ok']) && $ixw['exists'] === false, 'write vägrar så länge dubbletter finns');

// Merge.
$dry = jeab_email_key_merge(['groups' => [['email' => 'sara.dupprov@example.test', 'keep' => $ids[0], 'merge' => [$ids[1]]]]]);
ek(!empty($dry['ok']) && $dry['dry'] === true && $dry['would_merge'] === 1, 'merge utan write=1 är torr');
$hh = jeab_email_key_merge(['write' => 1, 'groups' => [['email' => 'hus.prov@example.test', 'keep' => $ids[2], 'merge' => [$ids[3]]]]]);
ek(empty($hh['ok']) && $hh['written'] === false, 'hushåll kräver household_ok');
$bad = jeab_email_key_merge(['write' => 1, 'groups' => [['email' => 'sara.dupprov@example.test', 'keep' => $ids[0], 'merge' => [$ids[4]]]]]);
ek(empty($bad['ok']), 'id utanför gruppen vägras');
ek((int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn() === $countBefore, 'vägrade körningar ändrade inget');
$guard = jeab_email_key_merge(['groups' => [
    ['email' => 'ord.prov@example.test', 'keep' => $ids[5], 'merge' => [$ids[6]]],
    ['email' => 'sno.prov@example.test', 'keep' => $ids[8], 'merge' => [$ids[7]]],
    ['email' => 'pay.prov@example.test', 'keep' => $ids[11], 'merge' => [$ids[12]]],
    ['email' => 'klar.prov@example.test', 'keep' => $ids[9], 'merge' => [$ids[10]]],
]]);
ek(!empty($guard['ok']) && $guard['would_merge_groups'] === ['klar.prov@example.test'] && count($guard['left']) === 3, 'dry: bara klar-gruppen godkänd, tre lämnas');
$reasons = array_column($guard['left'], 'reason', 'email');
ek(str_contains($reasons['ord.prov@example.test'], 'order') && str_contains($reasons['sno.prov@example.test'], 'aktiv påminnelse') && str_contains($reasons['pay.prov@example.test'], 'order'), 'tydliga skäl per grupp');
$gw = jeab_email_key_merge(['write' => 1, 'groups' => [['email' => 'ord.prov@example.test', 'keep' => $ids[5], 'merge' => [$ids[6]]]]]);
ek(!empty($gw['ok']) && ($gw['merged'] ?? -1) === 0 && $gw['written'] === false && count($gw['left']) === 1, 'write med bara blockerade grupper ändrar inget');
ek((int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn() === $countBefore, 'blockerade grupper orörda');
$jsonBefore = glob($tmp . '/*.json') ?: [];
$w = jeab_email_key_merge(['write' => 1, 'groups' => [['email' => 'sara.dupprov@example.test', 'keep' => $ids[0], 'merge' => [$ids[1]]]]]);
if (!empty($w['ok'])) {
    ek($w['merged'] === 1 && $w['counts_after']['contacts'] === $w['counts_before']['contacts'] - 1, 'radantal före/efter');
    ek($w['counts_after']['reminders'] === $w['counts_before']['reminders'] && $w['counts_after']['orders'] === $w['counts_before']['orders'], 'påminnelser och ordrar oförändrade');
    ek($w['merged_groups'] === ['sara.dupprov@example.test'], 'merged_groups listar gruppen');
    ek(is_file($tmp . '/backups/' . $w['backup']) && $w['backup_zip'] !== '', 'backup och före-bild finns');
    foreach ($jsonBefore as $f) {
        ek(is_file($f), 'JSON-filen ligger kvar: ' . basename($f));
    }
} else {
    ek(str_contains((string) ($w['error'] ?? ''), 'Backup') || ($w['error'] ?? '') === 'sql_store_required', 'write stoppas före ändring: ' . (string) ($w['error'] ?? ''));
    ek((int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn() === $countBefore, 'inget ändrat');
}

echo "klart\n";
