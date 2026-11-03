<?php

declare(strict_types=1);

/**
 * ordrar.slutfor efter att PDO släppts före Fortnox och Lantmäteriet.
 * Anslutningen ska gå att använda igen, och produkttyp ska vara obligatorisk.
 * Påhittad data. Inget personnummer i klartext.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-slutfor-release-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST'] = 'flowstack.example.test';
$_GET['p'] = 'ordrar';

class LantmaterietAPI
{
    public mixed $data = null;

    public function search(string $address, string $objektId = ''): array
    {
        unset($address, $objektId);
        $GLOBALS['lm_calls'] = (int) ($GLOBALS['lm_calls'] ?? 0) + 1;
        if (function_exists('jeab_pdo_release')) {
            jeab_pdo_release();
        }

        return [];
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/ordrar_v3.php';

function sf_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
jeab_ordrar_external_live(true);
$store = jeab_store();
sf_ok($store instanceof JeabPdoStore, 'ordrar använder JeabPdoStore');

$prop = new ReflectionProperty(JeabPdoStore::class, 'pdo');
$type = $prop->getType();
sf_ok($type instanceof ReflectionNamedType && $type->getName() === 'PDO' && $type->allowsNull(), 'JeabPdoStore::$pdo är ?PDO');
$store->disconnect();
sf_ok($prop->isInitialized($store) && $prop->getValue($store) === null, 'disconnect sätter null i stället för att unsetta');
sf_ok((int) $store->pdo()->query('SELECT 1')->fetchColumn() === 1, 'getter öppnar poolen när pdo är null');

$bare = new JeabPdoStore();
sf_ok((int) $bare->pdo()->query('SELECT 1')->fetchColumn() === 1, 'konstruktor utan pdo öppnar poolen vid första läsning');

$idNr = '1980' . '0101' . '0000';
$reminder = jeab_create_reminder($store, 'Ada Slutfor', '2026-11-02 00:00:00', 'test', null, [
    'customer' => 'Ada Slutfor',
    'company' => 'jarfalla',
    'address' => 'Testgatan 1, Järfälla',
]);
$id = (int) $reminder['id'];
jeab_purchase_put([
    'reminder_id' => $id,
    'company' => 'jarfalla',
    'kind' => '',
    'customer' => 'Ada Slutfor',
    'address' => 'Testgatan 1, Järfälla',
    'steps' => [],
]);

try {
    jeab_ordrar_save_questions(['id' => $id, 'pris' => '100']);
    sf_ok(false, 'saknad produkt ska avvisas');
} catch (InvalidArgumentException $e) {
    sf_ok(str_contains($e->getMessage(), 'Elbilsladdare'), 'servern kräver produkttyp');
}

$emptyForm = jeab_ordrar_v3_bekrafta_form(jeab_purchase_get($id), 'jarfalla', $id, $reminder);
sf_ok(substr_count($emptyForm['html'], 'name="kind"') === 3, 'tre produktval i Bekräfta');
sf_ok(substr_count($emptyForm['html'], 'required') >= 3 && str_contains($emptyForm['html'], 'aria-required="true"'), 'produktvalen är required');

$held = jeab_store();
jeab_pdo_release();
$GLOBALS['lm_calls'] = 0;
$GLOBALS['_JEAB_FORTNOX_CREATE'] = static function (array $row): array {
    jeab_pdo_release();
    $api = new LantmaterietAPI();
    $api->search('Testgatan 1');
    $row['fortnox_order'] = '4501';
    $row['updated_at'] = jeab_now();

    return jeab_purchase_put($row);
};

$done = jeab_ordrar_slutfor($held, [
    'id' => $id,
    'kind' => 'laddbox',
    'pris' => '12 500',
    'install_what' => 'Laddbox',
    'huvudsokande' => 'Ada Slutfor',
    'huvudsokande_personnummer' => $idNr,
    'fastighetsbeteckning' => 'TESTBY 1:1',
    'huvudsokande_address' => 'Testgatan 1, Järfälla',
    'owner_name' => ['Ada Slutfor'],
    'owner_pnr' => [''],
    'owner_address' => ['Testgatan 1, Järfälla'],
    'owner_forslag' => [],
    'payouts_form' => '1',
]);
sf_ok((int) ($GLOBALS['lm_calls'] ?? 0) >= 1, 'Lantmäteriet-mocken kördes efter att anslutningen släppts');
sf_ok((int) ($done['booking_id'] ?? 0) > 0, 'slutför bokar efter släppt PDO');
sf_ok((string) ($done['fortnox_order'] ?? '') === '4501', 'Fortnox-mocken skrev ordernummer');
$again = jeab_purchase_get($id);
sf_ok(is_array($again) && (string) ($again['fortnox_order'] ?? '') === '4501' && (int) ($again['booking_id'] ?? 0) > 0, 'bokning och ordernummer ligger kvar i databasen');
sf_ok((int) ($again['pris'] ?? 0) === 12500, 'att betala sparades i slutför');

$v3 = (string) file_get_contents(dirname(__DIR__) . '/assets/js/ordrar-v3.js');
sf_ok(str_contains($v3, "showErr('bekrafta', 'Välj produkt.')"), 'klienten stoppar slutför utan produkt');

echo "ordrar_slutfor_release_test ok\n";
