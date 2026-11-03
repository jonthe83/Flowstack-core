<?php

declare(strict_types=1);

/** inkorg_contacts under kontaktspärren: locked() skriver inget och svaret visar det. Inga kunduppgifter. */

$tmp = sys_get_temp_dir() . '/jeab-ic-frozen-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function icf(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

putenv('CONTACTS_SOLE');
putenv('CONTACTS_STRICT');
unset($GLOBALS['_JEAB_CONTACTS_STRICT'], $GLOBALS['_JEAB_CONTACTS_SOLE']);
icf(jeab_inkorg_contacts_write_frozen() === false, 'spärren av: inkorg_contacts skrivs');
$GLOBALS['_JEAB_CONTACTS_STRICT'] = true;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
icf(jeab_inkorg_contacts_write_frozen() === true, 'spärren på: inkorg_contacts fryst');

$r = jeab_inkorg_contacts_frozen_result(['id' => 'c-1'], ['c-1' => []]);
icf(($r['frozen'] ?? false) === true, 'array-svar får frozen=true');
icf(jeab_inkorg_contacts_frozen_result(3, []) === 0, 'räkning blir 0 när inget raderades');
icf(jeab_inkorg_contacts_frozen_result('c-ny', []) === null, 'nytt id som aldrig sparades blir null');
icf(jeab_inkorg_contacts_frozen_result('c-1', ['c-1' => []]) === 'c-1', 'befintligt id lämnas');
icf((int) ($GLOBALS['_JEAB_INKORG_CONTACTS_FROZEN'] ?? 0) === 4, 'varje stoppad skrivning räknas');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$box = new JeabPdoInboxContactStore($pdo);
$out = $box->locked(static fn (array $d): array => [['saved' => 1], ['contacts' => ['c-x' => ['name' => 'Test', 'emails' => []]]]]);
icf(($out['frozen'] ?? false) === true, 'locked() under spärren svarar frozen');
icf($box->sqlAll() === [], 'locked() under spärren skrev ingen rad');

$src = (string) file_get_contents(dirname(__DIR__) . '/src/kontakt_store.php');
$at = strpos($src, 'function jeab_inkorg_contacts_write_frozen');
$doc = strrpos(substr($src, 0, (int) $at), '/**');
icf(str_contains(substr($src, (int) $doc, (int) $at - (int) $doc), 'Kontaktspärren på'), 'funktionen har sin egen docblock');
echo "klart\n";
