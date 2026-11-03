<?php

declare(strict_types=1);

/**
 * En sammanslagen order tömmer inte ett fält bara för att nyckeln plockas bort.
 * Sparvägarna sätter null, så fältet verkligen rensas.
 * Påhittad data. Inget skickas.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-ordrar-clear-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
$_SERVER['REQUEST_METHOD'] = 'GET';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function oc(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$rotDir = $tmp . '/rot';
mkdir($rotDir, 0775, true);
file_put_contents($rotDir . '/data_scraping.php', <<<'PHP'
<?php
class LantmaterietAPI {
    public $data = [];
    public function search($addressText, $uuid = '') {
        return false;
    }
}
PHP);
$GLOBALS['_JEAB_ROT_DATA_SCRAPING_CANDIDATES'] = [$rotDir . '/data_scraping.php'];

$pdo = jeab_purchases_store_pdo();
oc($pdo instanceof PDO, 'sqlite är öppen');
oc(jeab_settings_put($pdo, 'sqlite', 'purchases_storage', 'sql'), 'orderflaggan går att sätta');
jeab_ordrar_external_live(false);

$source = [
    'personnummer' => [
        'src' => 'lookup',
        'value' => '8001011234',
        'address' => 'Testgatan 1',
        'at' => '2026-01-01 00:00:00',
    ],
];
jeab_purchase_put([
    'reminder_id' => 61,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Ada Exempel',
    'huvudsokande' => 'Ada Exempel',
    'personnummer' => '19800101-1234',
    'fastighetsbeteckning' => 'TESTEN 1:1',
    'address' => 'Testgatan 1, 111 22 Teststad',
    'huvudsokande_address' => 'Testgatan 1, 111 22 Teststad',
    'postnummer' => '11122',
    'ort' => 'Teststad',
    'email' => 'ada@example.test',
    'identity_filled_at' => '2026-01-01 08:00:00',
    'lm_lookup_error' => 'gammalt fel',
    'identity_source' => $source,
    'updated_at' => '2026-01-01 08:00:00',
]);

$kept = jeab_purchase_put(['reminder_id' => 61, 'name' => 'Ada Exempel']);
oc(is_array($kept) && (string) ($kept['lm_lookup_error'] ?? '') === 'gammalt fel', 'ett utelämnat fält ligger kvar');
oc((string) ($kept['identity_filled_at'] ?? '') === '2026-01-01 08:00:00', 'tidsstämpeln ligger kvar när den inte skickas med');
oc(is_array($kept['identity_source'] ?? null), 'ursprunget ligger kvar när det inte skickas med');

$clearedSource = jeab_ordrar_mark_identity_source(jeab_purchase_get(61), 'personnummer', '');
oc(array_key_exists('identity_source', $clearedSource) && $clearedSource['identity_source'] === null, 'tom markering blir null i raden');
$storedSource = jeab_purchase_put($clearedSource);
oc(is_array($storedSource) && array_key_exists('identity_source', $storedSource) && $storedSource['identity_source'] === null, 'ursprunget töms i lagret');
oc((string) ($storedSource['personnummer'] ?? '') === '19800101-1234', 'personnumret lämnas när bara markeringen tas bort');

$savedObjekt = jeab_ordrar_save_objekt(['id' => 61, 'email' => 'ada@example.test']);
oc(is_array($savedObjekt) && array_key_exists('lm_lookup_error', $savedObjekt) && $savedObjekt['lm_lookup_error'] === null, 'objekt-sparningen tömmer uppslagsfelet');
oc((string) ($savedObjekt['identity_filled_at'] ?? '') === '2026-01-01 08:00:00', 'objekt-sparningen lämnar tidsstämpeln när adressen är oförändrad');
oc((string) ($savedObjekt['name'] ?? '') === 'Ada Exempel', 'objekt-sparningen lämnar namnet');

jeab_ordrar_external_live(true);
$before = jeab_purchase_get(61);
$moved = $before;
$moved['address'] = 'Annangatan 2, 111 22 Teststad';
$moved['huvudsokande_address'] = 'Annangatan 2, 111 22 Teststad';
$moved['gata'] = 'Annangatan 2';
$relocked = jeab_ordrar_relookup_after_address_change($before, $moved, null);
oc(array_key_exists('identity_filled_at', $relocked) && $relocked['identity_filled_at'] === null, 'nytt uppslag efter adressändring tar bort tidsstämpeln');
$storedMoved = jeab_purchase_put($relocked);
oc(is_array($storedMoved) && array_key_exists('identity_filled_at', $storedMoved) && $storedMoved['identity_filled_at'] === null, 'tidsstämpeln är tömd i lagret');
oc((string) ($storedMoved['fastighetsbeteckning'] ?? '') === 'TESTEN 1:1', 'fastigheten lämnas när uppslaget är tomt');
oc((string) ($storedMoved['name'] ?? '') === 'Ada Exempel', 'namnet lämnas');
jeab_ordrar_external_live(false);

echo "ok\n";
