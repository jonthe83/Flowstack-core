<?php

declare(strict_types=1);

/**
 * kontakter.save och ordersynken återanvänder bara kontakten som sparas.
 * Ett orelaterat dubblettpar och dess påminnelser ligger kvar.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-save-reuse-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
session_save_path($tmp);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
putenv('CONTACTS_SOLE=0');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
jeab_ordrar_external_live(false);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = [];

function sr(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$secretPnr = (static function (): string {
    $nine = '900101123';
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $n = (int) $nine[$i];
        if ($i % 2 === 0) {
            $n *= 2;
            if ($n > 9) {
                $n -= 9;
            }
        }
        $sum += $n;
    }
    $check = (10 - ($sum % 10)) % 10;

    return '19900101-123' . $check;
})();

$store = jeab_store();
sr($store instanceof JeabPdoStore, 'sqlite-lagret finns');
jeab_ensure_contacts_tables($store->pdo(), 'sqlite');

$seed = jeab_kontakter_mutate(static function (array &$contacts) use ($secretPnr): array {
    $left = jeab_kontakt_blank([
        'name' => 'Uno Dublett',
        'email' => 'uno.dublett@example.test',
        'phone' => '0703002001',
        'street' => 'Dubbelgatan 1',
        'street_key' => jeab_kontakt_street_key('Dubbelgatan 1'),
        'postal_code' => '11122',
        'city' => 'Teststad',
        'personnummer' => $secretPnr,
    ]);
    $left['ovrigt'] = 'par-vanster';
    $right = jeab_kontakt_blank([
        'name' => 'Uno Dublett',
        'email' => 'uno.dublett@example.test',
        'phone' => '0703002002',
        'personnummer' => $secretPnr,
    ]);
    $right['ovrigt'] = 'par-hoger';
    $mia = jeab_kontakt_blank([
        'name' => 'Mia Mall',
        'email' => 'mia.mall@example.test',
        'phone' => '0703002009',
        'street' => 'Nyagatan 8',
        'street_key' => jeab_kontakt_street_key('Nyagatan 8'),
        'postal_code' => '33344',
        'city' => 'Teststad',
    ]);
    $mia['ovrigt'] = 'mia-fore';
    $lea = jeab_kontakt_blank([
        'name' => 'Lea Lin',
        'email' => 'lea.lin@example.test',
        'phone' => '0703002011',
    ]);
    $lea['ovrigt'] = 'lea-fore';
    $contacts[(string) $left['id']] = $left;
    $contacts[(string) $right['id']] = $right;
    $contacts[(string) $mia['id']] = $mia;
    $contacts[(string) $lea['id']] = $lea;

    return [
        'left' => (string) $left['id'],
        'right' => (string) $right['id'],
        'mia' => (string) $mia['id'],
        'lea' => (string) $lea['id'],
    ];
});
sr(is_array($seed), 'kontakterna finns');
$leftId = (string) $seed['left'];
$rightId = (string) $seed['right'];
$miaId = (string) $seed['mia'];
$leaId = (string) $seed['lea'];

$plan = jeab_kontakt_dedupe_plan(jeab_kontakter_load(), $store);
$pairWouldMerge = false;
foreach ($plan['merges'] as $merge) {
    $ids = [(string) ($merge['from'] ?? ''), (string) ($merge['to'] ?? '')];
    sort($ids);
    $want = [$leftId, $rightId];
    sort($want);
    if ($ids === $want && (string) ($merge['rule'] ?? '') === 'email') {
        $pairWouldMerge = true;
    }
}
sr($pairWouldMerge, 'paret är en e-postdubblett som kontakter.dedupe skulle slå ihop');

$now = jeab_now();
$insert = static function (string $title, string $name, string $email, string $phone, string $address) use ($store, $now): array {
    return $store->insertReminder([
        'title' => $title,
        'customer_name' => $name,
        'email' => $email,
        'phone' => $phone,
        'address' => $address,
        'company' => 'jarfalla',
        'due_at' => '2026-10-24 09:00:00',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
};
$leftReminder = $insert('Vänster', 'Uno Dublett', 'uno.dublett@example.test', '0703002001', 'Dubbelgatan 1');
$rightReminder = $insert('Höger', 'Uno Dublett', 'uno.dublett@example.test', '0703002002', '');
$miaReminder = $insert('Mia', 'Mia Mall', 'mia.mall@example.test', '0703002009', 'Nyagatan 8');
$leaReminder = $insert('Lea', 'Lea Lin', 'lea.lin@example.test', '0703002011', '');
$store->pdo()->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute([$leftId, (int) $leftReminder['id']]);
$store->pdo()->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute([$rightId, (int) $rightReminder['id']]);
$store->pdo()->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute([$miaId, (int) $miaReminder['id']]);
$store->pdo()->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute([$leaId, (int) $leaReminder['id']]);

$pairIntact = static function (string $step) use ($store, $leftId, $rightId, $leftReminder, $rightReminder): void {
    $loaded = jeab_kontakter_load();
    sr(isset($loaded[$leftId], $loaded[$rightId]), $step . ': dubblettparet ligger kvar');
    sr((string) ($loaded[$leftId]['ovrigt'] ?? '') === 'par-vanster' && (string) ($loaded[$rightId]['ovrigt'] ?? '') === 'par-hoger', $step . ': parets övrigt är orört');
    sr((string) ($store->getReminder((int) $leftReminder['id'])['contact_id'] ?? '') === $leftId, $step . ': vänster påminnelse pekar kvar');
    sr((string) ($store->getReminder((int) $rightReminder['id'])['contact_id'] ?? '') === $rightId, $step . ': höger påminnelse pekar kvar');
};

$savedMia = jeab_kontakt_save_input([
    'id' => $miaId,
    'street' => 'Nyagatan 8',
    'postal_code' => '33344',
    'city' => 'Teststad',
    'owner_name_1' => 'Mia Mall',
    'email' => 'mia.mall@example.test',
    'phone' => '0703002009',
    'contact_person' => '1',
    'ovrigt' => 'mia-sparad',
]);
sr((string) ($savedMia['id'] ?? '') === $miaId, 'kontakter.save behåller Mias id');
sr((string) (jeab_kontakt_get($miaId)['ovrigt'] ?? '') === 'mia-sparad', 'kontakter.save skriver Mias övrigt');
$pairIntact('efter kontakter.save');
sr((string) ($store->getReminder((int) $miaReminder['id'])['contact_id'] ?? '') === $miaId, 'Mias påminnelse pekar kvar efter sparningen');

$synced = jeab_ordrar_sync_lm_contact_apply([
    'huvudsokande' => 'Mia Mall',
    'fastighetsbeteckning' => 'TESTSTAD MIA 1:1',
    'gata' => 'Nyagatan 8',
    'ort' => 'Teststad',
    'postnummer' => '33344',
], $miaId, (array) jeab_kontakt_get($miaId));
sr($synced === true, 'ordersynken skriver fastigheten');
sr((string) (jeab_kontakt_get($miaId)['fastighetsbeteckning'] ?? '') === 'TESTSTAD MIA 1:1', 'fastigheten hamnar på Mia');
$pairIntact('efter ordrar.objekt-synken');
sr((string) ($store->getReminder((int) $miaReminder['id'])['contact_id'] ?? '') === $miaId, 'Mias påminnelse pekar kvar efter synken');
sr(isset(jeab_kontakter_load()[$leaId]), 'Lea ligger kvar tills hennes egen sparning');

$savedLea = jeab_kontakt_save_input([
    'street' => 'Leagatan 3',
    'postal_code' => '44455',
    'city' => 'Teststad',
    'owner_name_1' => 'Lea Lin',
    'email' => 'lea.lin@example.test',
    'phone' => '0703002011',
    'contact_person' => '1',
    'ovrigt' => 'lea-sparad',
]);
$leaKept = (string) ($savedLea['id'] ?? '');
sr($leaKept !== '' && $leaKept !== $leaId && !isset(jeab_kontakter_load()[$leaId]), 'sparningen återanvänder Lea och tar bort dubbletten');
sr((string) ($store->getReminder((int) $leaReminder['id'])['contact_id'] ?? '') === $leaKept, 'Leas påminnelse följer med kontakten som sparades');
$pairIntact('efter Leas sparning');

$storedPnr = '';
foreach ((array) (jeab_kontakt_get($leftId)['owners'] ?? []) as $owner) {
    if (is_array($owner) && trim((string) ($owner['personnummer'] ?? '')) !== '') {
        $storedPnr = (string) $owner['personnummer'];
    }
}
sr($storedPnr !== '', 'personnumret finns på paret');
$logPath = $tmp . '/kontakter-dedupe.log';
$log = is_file($logPath) ? (string) file_get_contents($logPath) : '';
sr($log !== '' && !str_contains($log, $secretPnr) && !str_contains($log, $storedPnr), 'loggen innehåller inte personnummer');
$lines = array_values(array_filter(explode("\n", $log), static fn (string $line): bool => trim($line) !== ''));
sr(count($lines) === 1, 'bara Leas återanvändning loggas');
$row = json_decode($lines[0], true);
sr(
    is_array($row)
    && ($row['from'] ?? '') === $leaId
    && ($row['to'] ?? '') === $leaKept
    && ($row['rule'] ?? '') === 'email'
    && ($row['caller'] ?? '') === 'save_input'
    && !isset($row['personnummer']),
    'loggraden har from, to, regel och caller save_input'
);
sr(!str_contains($log, $leftId) && !str_contains($log, $rightId), 'paret skrivs inte i loggen');

echo "kontakter_save_reuse_test klar\n";
