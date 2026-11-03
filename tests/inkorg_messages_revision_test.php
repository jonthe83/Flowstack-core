<?php

declare(strict_types=1);

/**
 * P2-23: messagesRevision tar MAX(updated_at) så en uppdatering utan ny
 * received_at eller COUNT ger ny revision. Påhittat mejl. Ingen kunddata.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-p223-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'inkorg' => ['storage' => 'sql'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function p223(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$pdo = jeab_pdo_connect_sqlite();
p223(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan går att sätta');
$store = jeab_inkorg_pdo_store();
p223($store instanceof JeabPdoInkorgStore, 'SQL-lagret är öppet');

$cols = [];
foreach ($pdo->query('PRAGMA table_info(inkorg_messages)') as $col) {
    if (is_array($col) && (string) ($col['name'] ?? '') !== '') {
        $cols[] = (string) $col['name'];
    }
}
p223(in_array('updated_at', $cols, true), 'inkorg_messages har updated_at');

$src = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg_store.php');
$fn = strstr($src, 'function messagesRevision');
$fn = is_string($fn) ? substr($fn, 0, (int) strpos($fn, 'function threadsRevision')) : '';
p223($fn !== '' && str_contains($fn, 'MAX(received_at)') && str_contains($fn, 'MAX(updated_at)'), 'revisionen är COUNT, MAX(received_at) och MAX(updated_at)');

$msg = [
    'id' => 'm-p223',
    'thread_id' => 't-p223',
    'from' => 'ada.p223@example.test',
    'subject' => 'Första',
    'body' => 'Hej första',
    'received_at' => '2026-10-01T10:00:00+00:00',
];
$store->upsertMessage('m-p223', $msg);
$store->upsertThread('t-p223', [
    'id' => 't-p223',
    'status' => 'open',
    'tags' => [],
    'updated_at' => '2026-10-01T10:00:00+00:00',
]);

$rev1 = $store->messagesRevision();
p223(str_contains($rev1, ':'), 'första revisionen har delar');
$pdo->prepare('UPDATE inkorg_messages SET updated_at = ? WHERE id = ?')->execute(['2020-01-01 00:00:00', 'm-p223']);
$revOld = $store->messagesRevision();
p223($revOld !== $rev1 && str_contains($revOld, '2020-01-01'), 'tillbakarullad updated_at syns i revisionen');

$msg['body'] = 'Hej uppdaterad, samma received_at';
$store->upsertMessage('m-p223', $msg);
$rev2 = $store->messagesRevision();
p223($rev2 !== $revOld, 'uppdatering utan ny received_at ger ny revision');
p223(!str_contains($rev2, '2020-01-01 00:00:00'), 'ny updated_at ersätter den gamla i revisionen');

$countPart = explode(':', $rev2)[0] ?? '';
$oldCount = explode(':', $revOld)[0] ?? '';
p223($countPart === $oldCount && $countPart === '1', 'COUNT är oförändrad när samma mejl skrivs om');

$stamp = date('Y-m-d H:i:s');
$pdo->prepare('UPDATE inkorg_messages SET updated_at = ? WHERE id = ?')->execute([$stamp, 'm-p223']);
$sameBefore = $store->messagesRevision();
$store->upsertMessage('m-p223', $msg);
$sameAfter = $store->messagesRevision();
p223($sameBefore !== $sameAfter, 'samma sekund och samma text ger ny revision');
p223(str_contains($sameAfter, ':r'), 'revisionen har skrivräknare');

echo "OK\n";
