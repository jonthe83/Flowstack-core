<?php

declare(strict_types=1);

/**
 * Bromsrader räknas inte som mejl utan kopia. audit listar id/tabell/tid, backfill tar ids, counts läser bara.
 * Påfyllnad tar radens fält, aldrig den nuvarande kontakten.
 * Påhittade namn. Hooksvaret har inga personnummer. Inga utskick.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-snapcounts-' . bin2hex(random_bytes(4));
$private = $tmp . '/private';
$public = $tmp . '/public';
mkdir($private . '/secrets', 0775, true);
mkdir($public, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $private;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $public;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
putenv('CONTACTS_SOLE');
putenv('CONTACTS_STRICT');
unset($_ENV['CONTACTS_SOLE'], $_ENV['CONTACTS_STRICT'], $GLOBALS['_JEAB_CONTACTS_STRICT']);
file_put_contents($private . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $private . '/app.sqlite',
        'json_path' => $private . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
    'incoming_webhook_token' => 'not-the-inbox-token',
], true) . ";\n");
file_put_contents($private . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-snapshots']));
require dirname(__DIR__) . '/src/bootstrap.php';

function snap_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
snap_ok($store instanceof JeabPdoStore, 'sqlite-lagret är öppet');
$pdo = $store->pdo();
jeab_ensure_inbox_sent($pdo, 'sqlite');
$ins = $pdo->prepare('INSERT INTO inbox_sent (created_at, thread_id, via, actor, kind, outcome, channel, body_hash, from_addr, snapshot_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$ins->execute(['2026-10-31T13:05:00+01:00', '', 'auto', 'system', 'guard', 'sent', 'email', str_repeat('a', 64), '', '']);
$ins->execute(['2026-10-31T13:06:00+01:00', '', 'auto', 'system', 'guard:dup', 'duplicate', 'email', str_repeat('b', 64), '', '']);
$ins->execute(['2026-10-31T13:06:30+01:00', '', 'manual', 'hook', 'quote', 'sent', 'email', str_repeat('e', 64), '', '']);
$ins->execute(['2026-10-31T13:07:00+01:00', 'thr-snapcount-1', 'manual', 'hook', 'quote', 'sent', 'email', str_repeat('c', 64), '', '']);
$ins->execute(['2026-10-31T13:08:00+01:00', 'thr-snapcount-2', 'manual', 'hook', 'quote', 'sent', 'email', str_repeat('d', 64), '', '']);
$ids = array_map('intval', $pdo->query("SELECT id FROM inbox_sent WHERE kind NOT LIKE 'guard%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));

$audit = jeab_snapshots_handle(['action' => 'snapshots.audit']);
snap_ok((int) ($audit['missing']['mail'] ?? -1) === 3, 'bromsrader (guard*) räknas inte som mejl utan kopia');
snap_ok((int) ($audit['missing_threadless'] ?? -1) === 1, 'annan rad utan tråd räknas kvar och syns för sig');
$rows = is_array($audit['missing_rows'] ?? null) ? $audit['missing_rows'] : [];
snap_ok(count($rows) === 3, 'audit listar de saknade raderna');
snap_ok(($rows[1]['id'] ?? '') === 'mail:' . $ids[1] && ($rows[1]['table'] ?? '') === 'inbox_sent' && ($rows[1]['at'] ?? '') === '2026-10-31T13:07:00+01:00', 'raden har id, tabell och tid');
snap_ok(array_diff(array_keys($rows[0]), ['id', 'table', 'type', 'company', 'at', 'kind']) === [], 'raden har inga kundfält');

$bad = jeab_snapshots_handle(['action' => 'snapshots.backfill', 'ids' => ['nej']]);
snap_ok(($bad['ok'] ?? true) === false && ($bad['written'] ?? true) === false, 'felaktiga ids skriver inget');
$one = jeab_snapshots_handle(['action' => 'snapshots.backfill', 'ids' => ['mail:' . $ids[2]], 'dry' => '0']);
snap_ok(($one['ok'] ?? false) === true && (int) ($one['filled']['mail'] ?? 0) === 1, 'backfill med ids fyller bara den raden');
$left = jeab_snapshots_handle(['action' => 'snapshots.audit']);
snap_ok((int) ($left['missing']['mail'] ?? -1) === 2 && (($left['missing_rows'][1]['id'] ?? '') === 'mail:' . $ids[1]), 'den andra raden saknar fortfarande kopia');

$counts = jeab_snapshots_handle(['action' => 'snapshots.counts']);
snap_ok(($counts['ok'] ?? false) === true && ($counts['written'] ?? true) === false, 'counts svarar och skriver inget');
jeab_lead_party_remember('thr-snapcount-1', 'kopia@exempel.se', 'Exempel', '', 'nordsolen');
foreach (['inkorg_contacts', 'inbox_lead_party', 'log_webshop_orders', 'lead_threads', 'fullmakt_sql_verify'] as $k) {
    snap_ok(is_array($counts[$k] ?? null), 'counts har ' . $k);
}
$json = (string) json_encode($counts);
snap_ok(!str_contains($json, '@'), 'counts har inga e-postadresser');
snap_ok(str_contains((string) file_get_contents(dirname(__DIR__) . '/hook.php'), "'snapshots.counts'"), 'hook.php tar snapshots.counts');

snap_ok(($counts['lead_threads']['ok'] ?? false) === true, 'lead-kopiorna gick att läsa');
$pdo->exec('DROP TABLE inbox_lead_party');
$broken = jeab_snapshots_handle(['action' => 'snapshots.counts']);
snap_ok(($broken['ok'] ?? true) === false && ($broken['lead_threads']['ok'] ?? true) === false && array_key_exists('without_lead_party', $broken['lead_threads'] ?? []) && $broken['lead_threads']['without_lead_party'] === null, 'trasig inbox_lead_party ger ok=false');

echo "OK  snapshots counts\n";
