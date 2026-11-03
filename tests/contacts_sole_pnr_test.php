<?php

declare(strict_types=1);

/**
 * pnr-lagring och ångerlogg. Bara påhittade nummer (år 1800, finns inte).
 */

$tmp = sys_get_temp_dir() . '/jeab-sole-pnr-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('CONTACTS_SOLE');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function pnr_ok(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$fake = '18000101-0000';
pnr_ok(jeab_contacts_sole_pnr_store_value($fake) === '180001010000', 'lagring ger 12 siffror');
pnr_ok(jeab_contacts_sole_pnr_store_value('180001010000') === '180001010000', 'redan 12 siffror oförändrad');
pnr_ok(jeab_contacts_sole_pnr_format('180001010000') === $fake, 'visning ger ÅÅÅÅMMDD-NNNN');
pnr_ok(jeab_contacts_sole_pnr_store_value('•••• 0000') === '•••• 0000', 'maskerat rörs inte');

putenv('JEAB_PNR_HMAC_KEY');
$noKey = jeab_contacts_sole_undo_scrub(['personnummer' => $fake, 'name' => 'Test']);
pnr_ok(!array_key_exists('personnummer', $noKey) && !array_key_exists('pnr_hmac', $noKey), 'utan nyckel utelämnas fältet');

putenv('JEAB_PNR_HMAC_KEY=test-key-only');
$scrub = jeab_contacts_sole_undo_scrub([
    'personnummer' => $fake,
    'owners' => [['name' => 'Test', 'personnummer' => $fake]],
    'owner_pnr' => [$fake],
]);
$json = json_encode($scrub);
pnr_ok(is_string($json) && !str_contains($json, '18000101') && !str_contains($json, '0101-0000'), 'inget klartext-pnr i ångerloggen');
pnr_ok(($scrub['pnr_hmac'] ?? '') === hash_hmac('sha256', '180001010000', 'test-key-only'), 'HMAC på normaliserat nummer');
pnr_ok(jeab_contacts_sole_pnr_hmac($fake) === jeab_contacts_sole_pnr_hmac('180001010000'), 'HMAC oberoende av format');
putenv('JEAB_PNR_HMAC_KEY');

$pnrHome = sys_get_temp_dir() . '/jeab-pnr-home-' . bin2hex(random_bytes(3));
$pnrWeb = $pnrHome . '/public_html';
mkdir($pnrHome . '/privat', 0700, true);
mkdir($pnrWeb . '/private', 0700, true);
file_put_contents($pnrWeb . '/private/pnr-hmac.key', "web-root-key\n");
file_put_contents($pnrHome . '/privat/pnr-hmac.key', "outside-key\n");
$GLOBALS['_JEAB_APP_ROOT'] = $pnrWeb;
pnr_ok(jeab_contacts_sole_pnr_hmac_key() === 'outside-key', 'nyckeln läses från privat/ utanför webbroten');
putenv('JEAB_PNR_HMAC_KEY=env-wins');
pnr_ok(jeab_contacts_sole_pnr_hmac_key() === 'env-wins', 'env vinner över filen');
putenv('JEAB_PNR_HMAC_KEY');
unset($GLOBALS['_JEAB_APP_ROOT']);
pnr_ok(jeab_contacts_sole_pnr_hmac_key() === '' || !str_contains(jeab_contacts_sole_pnr_hmac_key(), 'web-root-key'), 'public_html/private/pnr-hmac.key läses inte');

echo "contacts sole pnr: ok\n";
