<?php

declare(strict_types=1);

/**
 * privat/flags.json för STEG4_CUTOVER, CONTACTS_SOLE och LEGACY_JSON_WRITES.
 * Kodstandard: cutover och contacts_sole på, legacy av. Filen vinner.
 * Vitlistan, atomär skrivning och confirm. Påhittad data. Inga personnummer.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$home = sys_get_temp_dir() . '/jeab-flags-file-' . bin2hex(random_bytes(4));
$root = $home . '/public_html';
$data = $root . '/data';
$privat = $home . '/privat';
mkdir($data . '/secrets', 0700, true);
$GLOBALS['_JEAB_APP_ROOT'] = $root;
$GLOBALS['_JEAB_DATA_DIR'] = $data;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $data;
putenv('JEAB_APP_ROOT=' . $root);
putenv('JEAB_DISABLE_SQLITE=0');
putenv('STEG4_CUTOVER');
putenv('CONTACTS_SOLE');
putenv('LEGACY_JSON_WRITES');
putenv('DATA_BACKEND');
unset(
    $_ENV['STEG4_CUTOVER'],
    $_ENV['CONTACTS_SOLE'],
    $_ENV['LEGACY_JSON_WRITES'],
    $_ENV['DATA_BACKEND'],
    $GLOBALS['_JEAB_STEG4_CUTOVER'],
    $GLOBALS['_JEAB_CONTACTS_SOLE'],
    $GLOBALS['_JEAB_LEGACY_JSON_WRITES'],
    $GLOBALS['_JEAB_CONTACTS_SOLE_STORED']
);
file_put_contents($data . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $data . '/app.sqlite',
        'json_path' => $data . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';

function fl(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function fl_clear_env(): void
{
    putenv('STEG4_CUTOVER');
    putenv('CONTACTS_SOLE');
    putenv('LEGACY_JSON_WRITES');
    unset($_ENV['STEG4_CUTOVER'], $_ENV['CONTACTS_SOLE'], $_ENV['LEGACY_JSON_WRITES']);
    unset($GLOBALS['_JEAB_STEG4_CUTOVER'], $GLOBALS['_JEAB_CONTACTS_SOLE'], $GLOBALS['_JEAB_LEGACY_JSON_WRITES'], $GLOBALS['_JEAB_CONTACTS_SOLE_STORED']);
}

/** @return list<string> */
function fl_tmps(string $privat): array
{
    $found = glob($privat . '/*.tmp') ?: [];
    $found = array_merge($found, glob($privat . '/flags.json.*.tmp') ?: []);

    return $found;
}

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
$flagSrc = (string) file_get_contents(dirname(__DIR__) . '/src/flags.php');
fl(str_contains($hook, "=== 'flags.get'") && str_contains($hook, 'jeab_flags_get_dispatch'), 'hook tar flags.get');
fl(str_contains($hook, "=== 'flags.set'") && str_contains($hook, 'jeab_flags_set_dispatch'), 'hook tar flags.set');
fl(str_contains($flagSrc, 'rename(') && str_contains($flagSrc, '.tmp'), 'skrivningen använder tempfil och rename');
fl(!str_contains((string) file_get_contents(dirname(__DIR__) . '/src/shield.php'), 'flags.json'), 'shield.php är orörd av flaggfilen');

fl(jeab_cutover_enabled() === true, 'STEG4_CUTOVER är på utan källa');
fl(jeab_contacts_sole() === true && jeab_flag('CONTACTS_SOLE') === true, 'CONTACTS_SOLE är på utan källa');
fl(jeab_legacy_json_writes() === false, 'LEGACY_JSON_WRITES är av utan källa');
fl(jeab_flag('STEG4_CUTOVER') === true && jeab_flag_resolve('STEG4_CUTOVER')['source'] === 'default', 'cutover-källan är standard på');
fl(jeab_flag_resolve('CONTACTS_SOLE')['source'] === 'default' && jeab_flag('CONTACTS_SOLE') === true, 'contacts_sole-källan är standard på');
fl(jeab_flag_resolve('LEGACY_JSON_WRITES')['source'] === 'default' && jeab_flag('LEGACY_JSON_WRITES') === false, 'legacy-källan är standard av');
fl(jeab_flag('DATA_BACKEND') === false && jeab_flag_resolve('DATA_BACKEND')['source'] === 'default', 'okänt namn läses inte');
fl(jeab_flag_known('OFFER_SEND_ONE_ENABLED'), 'OFFER_SEND_ONE_ENABLED är en känd flagga');
fl(jeab_flag('OFFER_SEND_ONE_ENABLED') === false && jeab_flag_resolve('OFFER_SEND_ONE_ENABLED')['source'] === 'default', 'OFFER_SEND_ONE_ENABLED är av som standard');

mkdir($privat, 0700, true);
file_put_contents($privat . '/flags.json', json_encode([
    'STEG4_CUTOVER' => 0,
    'CONTACTS_SOLE' => 0,
    'LEGACY_JSON_WRITES' => 1,
], JSON_UNESCAPED_UNICODE) . "\n");
fl(jeab_cutover_enabled() === false && jeab_flag_resolve('STEG4_CUTOVER')['source'] === 'file', 'filen 0 slår av cutover');
fl(jeab_contacts_sole() === false && jeab_flag('CONTACTS_SOLE') === false && jeab_flag_resolve('CONTACTS_SOLE')['source'] === 'file', 'filen 0 slår av contacts_sole');
fl(jeab_legacy_json_writes() === true && jeab_flag_resolve('LEGACY_JSON_WRITES')['source'] === 'file', 'filen 1 slår på legacy');
@unlink($privat . '/flags.json');
fl(jeab_cutover_enabled() === true && jeab_flag_resolve('STEG4_CUTOVER')['source'] === 'default', 'borttagen fil ger cutover-standard');
fl(jeab_contacts_sole() === true && jeab_flag_resolve('CONTACTS_SOLE')['source'] === 'default', 'borttagen fil ger contacts_sole-standard');
fl(jeab_legacy_json_writes() === false && jeab_flag_resolve('LEGACY_JSON_WRITES')['source'] === 'default', 'borttagen fil ger legacy-standard');

putenv('STEG4_CUTOVER=true');
fl(jeab_cutover_enabled() === true && jeab_flag_resolve('STEG4_CUTOVER')['source'] === 'default', 'STEG4_CUTOVER=true räknas inte');
putenv('STEG4_CUTOVER=1');
fl(jeab_cutover_enabled() === true && jeab_flag_resolve('STEG4_CUTOVER')['source'] === 'env', 'STEG4_CUTOVER=1 slår på');
putenv('STEG4_CUTOVER=0');
fl(jeab_cutover_enabled() === false, 'STEG4_CUTOVER=0 slår av');
fl_clear_env();
putenv('LEGACY_JSON_WRITES=off');
$_ENV['LEGACY_JSON_WRITES'] = 'off';
fl(jeab_legacy_json_writes() === false, 'env off slår av legacy');
putenv('LEGACY_JSON_WRITES=on');
$_ENV['LEGACY_JSON_WRITES'] = 'on';
fl(jeab_legacy_json_writes() === true, 'env on slår på legacy');
fl_clear_env();

file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-flags-file']));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = '';

$denied = jeab_flags_set_dispatch(['name' => 'STEG4_CUTOVER', 'value' => 1, 'dry' => 0]);
fl(($denied['status'] ?? 0) === 401 && empty($denied['ok']) && ($denied['written'] ?? true) === false && !is_file($privat . '/flags.json'), 'flags.set utan token skriver inte');
$deniedGet = jeab_flags_get_dispatch([]);
fl(($deniedGet['status'] ?? 0) === 401 && empty($deniedGet['ok']), 'flags.get utan token svarar 401');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-flags-file';

$dry = jeab_flags_set_dispatch(['name' => 'STEG4_CUTOVER', 'value' => 0]);
fl(!empty($dry['ok']) && ($dry['dry'] ?? false) === true && ($dry['written'] ?? true) === false && ($dry['old'] ?? 0) === 1 && ($dry['new'] ?? 1) === 0 && !is_file($privat . '/flags.json'), 'utelämnad dry skriver inte');

$bad = jeab_flags_set_dispatch(['name' => 'DATA_BACKEND', 'value' => 1, 'dry' => 0]);
fl(empty($bad['ok']) && ($bad['status'] ?? 0) === 400 && ($bad['written'] ?? true) === false && !is_file($privat . '/flags.json'), 'vitlistan stoppar okänt namn');
$badValue = jeab_flags_set_dispatch(['name' => 'CONTACTS_SOLE', 'value' => 2, 'dry' => 0]);
fl(empty($badValue['ok']) && ($badValue['written'] ?? true) === false && !is_file($privat . '/flags.json'), 'value utanför 0/1 skriver inte');

if (!is_dir($privat)) {
    mkdir($privat, 0700, true);
}
file_put_contents($privat . '/flags.json', json_encode([
    'STEG4_CUTOVER' => 0,
    'CONTACTS_SOLE' => 0,
    'LEGACY_JSON_WRITES' => 1,
    'DATA_BACKEND' => 1,
], JSON_UNESCAPED_UNICODE));
fl(jeab_cutover_enabled() === false && jeab_flag_resolve('STEG4_CUTOVER')['source'] === 'file', 'filen slår av cutover');
fl(jeab_contacts_sole() === false && jeab_flag_resolve('CONTACTS_SOLE')['source'] === 'file', 'filen slår av contacts_sole');
fl(jeab_legacy_json_writes() === true && jeab_flag_resolve('LEGACY_JSON_WRITES')['source'] === 'file', 'filen slår på legacy');
fl(jeab_flag('DATA_BACKEND') === false, 'okänd nyckel i filen ignoreras');

putenv('STEG4_CUTOVER=1');
fl(jeab_cutover_enabled() === true && jeab_flag_resolve('STEG4_CUTOVER')['source'] === 'env', 'env vinner över filen');
$GLOBALS['_JEAB_STEG4_CUTOVER'] = false;
fl(jeab_cutover_enabled() === false && jeab_flag_resolve('STEG4_CUTOVER')['source'] === 'globals', 'GLOBALS vinner över env och fil');
unset($GLOBALS['_JEAB_STEG4_CUTOVER']);
putenv('STEG4_CUTOVER');
unset($_ENV['STEG4_CUTOVER']);
fl(jeab_cutover_enabled() === false && jeab_flag_resolve('STEG4_CUTOVER')['source'] === 'file', 'utan env gäller filen igen');

$store = jeab_store();
fl($store instanceof JeabPdoStore, 'sqlite är öppet');
$pdo = $store->pdo();
file_put_contents($privat . '/flags.json', "{}\n");
unset($GLOBALS['_JEAB_CONTACTS_SOLE_STORED']);
fl(jeab_settings_put($pdo, 'sqlite', JEAB_CONTACTS_SOLE_KEY, '1'), 'settings-raden sparas');
unset($GLOBALS['_JEAB_CONTACTS_SOLE_STORED']);
fl(jeab_contacts_sole() === true && jeab_flag_resolve('CONTACTS_SOLE')['source'] === 'default', 'tom fil lämnar settings-raden');
$fromSettings = jeab_flags_get_dispatch([]);
$settingsFlags = is_array($fromSettings['flags'] ?? null) ? $fromSettings['flags'] : [];
fl(
    ($settingsFlags['CONTACTS_SOLE']['value'] ?? 0) === 1 && ($settingsFlags['CONTACTS_SOLE']['source'] ?? '') === 'settings',
    'flags.get visar settings när fil och env saknas'
);
fl(($settingsFlags['LEGACY_JSON_WRITES']['value'] ?? 1) === 0 && ($settingsFlags['LEGACY_JSON_WRITES']['source'] ?? '') === 'default', 'flags.get visar legacy-standard av');
fl(($settingsFlags['STEG4_CUTOVER']['value'] ?? 0) === 1 && ($settingsFlags['STEG4_CUTOVER']['source'] ?? '') === 'default', 'flags.get visar cutover-standard på');
file_put_contents($privat . '/flags.json', json_encode(['CONTACTS_SOLE' => 0]) . "\n");
unset($GLOBALS['_JEAB_CONTACTS_SOLE_STORED']);
fl(jeab_contacts_sole() === false && jeab_flag_resolve('CONTACTS_SOLE')['source'] === 'file', 'filen 0 vinner över settings');
jeab_contacts_sole_setting_put(false);
unset($GLOBALS['_JEAB_CONTACTS_SOLE_STORED']);
@unlink($privat . '/flags.json');
fl_clear_env();
fl(jeab_cutover_enabled() === true && jeab_legacy_json_writes() === false, 'borttagen fil ger kodstandard för cutover och legacy');
fl(jeab_contacts_sole() === false && jeab_contacts_sole_stored() === '0', 'settings 0 ligger kvar när filen är borta');
$pdo->prepare('DELETE FROM settings WHERE "key" = ?')->execute([JEAB_CONTACTS_SOLE_KEY]);
unset($GLOBALS['_JEAB_CONTACTS_SOLE_STORED']);
fl(jeab_contacts_sole() === true && jeab_flag_resolve('CONTACTS_SOLE')['source'] === 'default', 'utan settings-rad gäller kodstandarden');

$legacyDry = jeab_flags_set_dispatch(['name' => 'LEGACY_JSON_WRITES', 'value' => 0, 'dry' => 0]);
fl(empty($legacyDry['ok']) && ($legacyDry['status'] ?? 0) === 400 && ($legacyDry['written'] ?? true) === false && !is_file($privat . '/flags.json') && jeab_legacy_json_writes() === false, 'legacy 0 utan confirm skriver inte');
$legacyWrong = jeab_flags_set_dispatch(['name' => 'LEGACY_JSON_WRITES', 'value' => '0', 'dry' => 0, 'confirm' => 'SAM_NO']);
fl(empty($legacyWrong['ok']) && !is_file($privat . '/flags.json') && jeab_legacy_json_writes() === false, 'fel confirm skriver inte');
$legacyPreview = jeab_flags_set_dispatch(['name' => 'LEGACY_JSON_WRITES', 'value' => 0]);
fl(empty($legacyPreview['ok']) && ($legacyPreview['written'] ?? true) === false && !is_file($privat . '/flags-audit.log'), 'dry utan confirm skriver ingen ångerrad');

$first = jeab_flags_set_dispatch(['name' => 'STEG4_CUTOVER', 'value' => 0, 'dry' => 0]);
fl(!empty($first['ok']) && ($first['written'] ?? false) === true && ($first['old'] ?? -1) === 1 && ($first['new'] ?? 1) === 0 && ($first['source'] ?? '') === 'file', 'första skrivningen är atomär och syns');
$second = jeab_flags_set_dispatch(['name' => 'CONTACTS_SOLE', 'value' => 0, 'dry' => '0']);
fl(!empty($second['ok']) && ($second['written'] ?? false) === true, 'andra skrivningen behåller filen');
$disk = json_decode((string) file_get_contents($privat . '/flags.json'), true);
fl(is_array($disk) && ($disk['STEG4_CUTOVER'] ?? 1) === 0 && ($disk['CONTACTS_SOLE'] ?? 1) === 0 && !array_key_exists('DATA_BACKEND', $disk), 'båda flaggorna finns kvar i en hel JSON-fil');
fl(fl_tmps($privat) === [], 'ingen tempfil ligger kvar');
$realFlags = realpath($privat . '/flags.json');
$realPrivat = realpath($privat);
$realRoot = realpath($root);
fl(is_string($realFlags) && is_string($realPrivat) && str_starts_with(str_replace('\\', '/', $realFlags), str_replace('\\', '/', (string) $realPrivat) . '/'), 'flags.json ligger i privat');
fl(is_string($realRoot) && is_string($realFlags) && !str_starts_with(str_replace('\\', '/', $realFlags), str_replace('\\', '/', $realRoot) . '/'), 'flags.json ligger utanför webbroten');
fl(!is_file($root . '/flags.json') && !is_file($data . '/flags.json'), 'ingen flaggfil i webbroten');

$auditRaw = (string) file_get_contents($privat . '/flags-audit.log');
$auditLines = array_values(array_filter(explode("\n", trim($auditRaw)), static fn (string $line): bool => $line !== ''));
fl(count($auditLines) === 2, 'två ångerrader');
$audit = json_decode($auditLines[0], true);
fl(
    is_array($audit)
    && ($audit['name'] ?? '') === 'STEG4_CUTOVER'
    && ($audit['old'] ?? -1) === 1
    && ($audit['new'] ?? -1) === 0
    && preg_match('/^\d{4}-\d{2}-\d{2}T/', (string) ($audit['time'] ?? '')) === 1,
    'ångerraden har tid och gammalt/nytt värde'
);

$legacyOn = jeab_flags_set_dispatch(['name' => 'LEGACY_JSON_WRITES', 'value' => 1, 'dry' => 0]);
fl(!empty($legacyOn['ok']) && ($legacyOn['old'] ?? 1) === 0 && ($legacyOn['new'] ?? 0) === 1 && jeab_legacy_json_writes() === true, 'legacy 1 skrivs utan confirm');
$blocked = jeab_flags_set_dispatch(['name' => 'LEGACY_JSON_WRITES', 'value' => 0, 'dry' => 0]);
$held = json_decode((string) file_get_contents($privat . '/flags.json'), true);
fl(empty($blocked['ok']) && jeab_legacy_json_writes() === true && is_array($held) && ($held['LEGACY_JSON_WRITES'] ?? 0) === 1, 'legacy 0 utan confirm rör inte filen');

$confirmed = jeab_flags_set_dispatch([
    'name' => 'LEGACY_JSON_WRITES',
    'value' => 0,
    'dry' => 0,
    'confirm' => 'SAM_YES',
]);
fl(!empty($confirmed['ok']) && ($confirmed['written'] ?? false) === true && ($confirmed['old'] ?? 0) === 1 && ($confirmed['new'] ?? 1) === 0 && jeab_legacy_json_writes() === false, 'confirm släpper igenom legacy 0');
$afterLegacy = json_decode((string) file_get_contents($privat . '/flags.json'), true);
fl(is_array($afterLegacy) && ($afterLegacy['LEGACY_JSON_WRITES'] ?? 1) === 0 && ($afterLegacy['STEG4_CUTOVER'] ?? 1) === 0, 'legacy 0 ligger i samma fil');
$back = jeab_flags_set_dispatch(['name' => 'LEGACY_JSON_WRITES', 'value' => 1, 'dry' => 0]);
fl(!empty($back['ok']) && ($back['written'] ?? false) === true && jeab_legacy_json_writes() === true, 'legacy 1 kräver inte confirm');

$got = jeab_flags_get_dispatch([]);
$gotFlags = is_array($got['flags'] ?? null) ? $got['flags'] : [];
fl(!empty($got['ok']) && ($gotFlags['STEG4_CUTOVER']['value'] ?? 1) === 0 && ($gotFlags['STEG4_CUTOVER']['source'] ?? '') === 'file', 'flags.get visar cutover från filen');
fl(($gotFlags['LEGACY_JSON_WRITES']['value'] ?? 0) === 1 && ($gotFlags['LEGACY_JSON_WRITES']['source'] ?? '') === 'file', 'flags.get visar legacy från filen');
fl(($gotFlags['CONTACTS_SOLE']['source'] ?? '') === 'file' && ($gotFlags['CONTACTS_SOLE']['value'] ?? 1) === 0, 'flags.get visar contacts_sole från filen');
fl(fl_tmps($privat) === [], 'fortfarande ingen tempfil');

echo "flags_test ok\n";
