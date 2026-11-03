<?php

declare(strict_types=1);

/**
 * Flottarestigen 6: Manuell order, ordrar.lm_lookup, set_fastighet och
 * «Hämta fastighetsinfo igen» tar samma två lagfartsägare.
 * En cachad träff med tre ägare och en annan fastighet i svaret får inte följa med.
 */

$tmp = sys_get_temp_dir() . '/jeab-lm-same-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
$_ENV['JEAB_DISABLE_SQLITE'] = '1';

require dirname(__DIR__) . '/src/bootstrap.php';

function same_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function same_pnr(string $tail): string
{
    return '19700101' . $tail;
}

$rotDir = $tmp . '/rot';
@mkdir($rotDir, 0775, true);
file_put_contents($rotDir . '/data_scraping.php', <<<'PHP'
<?php
class LantmaterietAPI {
    public $data = [];
    public function search($addressText, $uuid = '') {
        $GLOBALS['SAME_LM_CALLS'][] = (string) $addressText . "\t" . (string) $uuid;
        $t = (string) $addressText;
        if (stripos($t, 'Flottarestigen') === false || !preg_match('/\b6\b/u', $t)) {
            return false;
        }
        $this->data = [
            [
                'fastighetsbeteckning' => 'Sollentuna Lertavlan 4',
                'matchedAddress' => 'Flottarestigen 6, Sollentuna',
                'postnummer' => '19251',
                'postort' => 'Sollentuna',
                'owners' => [
                    ['name' => 'Wouter van der Wijngaart', 'personnummer' => '197001011111', 'address' => 'Flottarestigen 6, Sollentuna', 'andel' => '1/2', 'lagfart' => '2006-06-19'],
                    ['name' => 'Saija Marja Metsola', 'personnummer' => '197001012222', 'address' => 'Flottarestigen 6, Sollentuna', 'andel' => '1/2', 'lagfart' => '2006-06-19'],
                ],
            ],
            [
                'fastighetsbeteckning' => 'Sollentuna Annan 9',
                'matchedAddress' => 'Annanstigen 2, Sollentuna',
                'postnummer' => '19251',
                'postort' => 'Sollentuna',
                'owners' => [
                    ['name' => 'Paulina Brangstrup', 'personnummer' => '197001013333', 'address' => 'Annanstigen 2, Sollentuna', 'andel' => '1/1', 'lagfart' => '2011-01-01'],
                    ['name' => 'Rikard Karlander', 'personnummer' => '197001014444', 'address' => 'Annanstigen 2, Sollentuna', 'andel' => '1/1', 'lagfart' => '2011-01-01'],
                ],
            ],
        ];
        return true;
    }
}
PHP);
$GLOBALS['_JEAB_ROT_DATA_SCRAPING_CANDIDATES'] = [$rotDir . '/data_scraping.php'];
$GLOBALS['SAME_LM_CALLS'] = [];

$store = jeab_store();
$GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
$purchasePdo = new PDO('sqlite:' . $tmp . '/purchases.sqlite');
$purchasePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_PURCHASES_PDO'] = $purchasePdo;
jeab_purchases_store_ensure($purchasePdo);
$undoPdo = new PDO('sqlite::memory:');
$undoPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$undoPdo->exec('CREATE TABLE settings ("key" TEXT PRIMARY KEY, value TEXT NOT NULL)');
$GLOBALS['_JEAB_FASTIGHET_UNDO_PDO'] = $undoPdo;
jeab_ordrar_external_live(true);

require_once dirname(__DIR__) . '/src/address_lookup.php';

$address = 'Flottarestigen 6, Sollentuna';
$query = jeab_ordrar_lookup_query(null, ['address' => $address, 'ort' => 'Sollentuna']);
same_assert($query !== '' && str_contains($query, 'Flottarestigen 6'), 'orderns adress blir sökfrågan, fick ' . $query);

$manual = jeab_lm_lookup_property($query);
$manualNames = [];
foreach (is_array($manual['owners'] ?? null) ? $manual['owners'] : [] as $owner) {
    if (is_array($owner)) {
        $manualNames[] = trim((string) ($owner['name'] ?? ''));
    }
}
same_assert(($manual['fastighetsbeteckning'] ?? '') === 'SOLLENTUNA LERTAVLAN 4', 'Manuell order väljer Lertavlan 4');
same_assert($manualNames === ['Wouter van der Wijngaart', 'Saija Marja Metsola'], 'Manuell order har de två lagfartsägarna, fick ' . implode(', ', $manualNames));
same_assert((string) ($manual['owners'][0]['andel'] ?? '') === '1/2' && (string) ($manual['owners'][1]['andel'] ?? '') === '1/2', 'andelarna är 1/2');
same_assert((string) ($manual['owners'][0]['lagfart'] ?? '') === '2006-06-19' && (string) ($manual['owners'][1]['lagfart'] ?? '') === '2006-06-19', 'lagfarten är 2006-06-19');
same_assert(!in_array('Paulina Brangstrup', $manualNames, true) && !in_array('Rikard Karlander', $manualNames, true), 'den andra fastigheten lämnar inga ägare');

jeab_rot_lm_cache_put($query, [
    'fastighetsbeteckning' => 'SOLLENTUNA LERTAVLAN 4',
    'address' => 'Flottarestigen 6, Sollentuna',
    'owners' => [
        ['name' => 'Wouter van der Wijngaart', 'personnummer' => same_pnr('1111'), 'andel' => '1/2', 'lagfart' => '2006-06-19'],
        ['name' => 'Paulina Brangstrup', 'personnummer' => same_pnr('3333'), 'andel' => '1/1', 'lagfart' => '2011-01-01'],
        ['name' => 'Rikard Karlander', 'personnummer' => same_pnr('4444'), 'andel' => '1/1', 'lagfart' => '2011-01-01'],
    ],
    'postnummer' => '19251',
    'ort' => 'Sollentuna',
], false, '');

$mark = jeab_ordrar_identity_value(['fastighetsbeteckning' => 'SOLLENTUNA LERTAVLAN 4'], 'fastighetsbeteckning');
jeab_purchase_put([
    'reminder_id' => 20,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Wouter van der Wijngaart',
    'huvudsokande' => 'Wouter van der Wijngaart',
    'personnummer' => jeab_ordrar_format_pnr(same_pnr('1111')),
    'fastighetsbeteckning' => 'SOLLENTUNA LERTAVLAN 4',
    'objektidentitet' => 'obj-lertavlan',
    'address' => $address,
    'ort' => 'Sollentuna',
    'owners' => [
        ['name' => 'Wouter van der Wijngaart', 'personnummer' => jeab_ordrar_format_pnr(same_pnr('1111')), 'address' => $address, 'andel' => '1/2', 'lagfart' => '2006-06-19'],
        ['name' => 'Paulina Brangstrup', 'personnummer' => jeab_ordrar_format_pnr(same_pnr('3333')), 'address' => $address, 'andel' => '1/1', 'lagfart' => ''],
        ['name' => 'Rikard Karlander', 'personnummer' => jeab_ordrar_format_pnr(same_pnr('4444')), 'address' => $address, 'andel' => '1/1', 'lagfart' => ''],
    ],
    'medsokande' => [],
    'pris' => 15000,
    'steps' => [],
    'identity_source' => ['fastighetsbeteckning' => ['src' => 'manual', 'value' => $mark, 'address' => '', 'at' => '2026-10-01']],
]);

$GLOBALS['SAME_LM_CALLS'] = [];
$lm = jeab_ordrar_lm_lookup_run($store, ['order_id' => 20, 'dry' => 1]);
$lmNames = [];
foreach (is_array($lm['owners'] ?? null) ? $lm['owners'] : [] as $owner) {
    if (is_array($owner)) {
        $lmNames[] = trim((string) ($owner['name'] ?? ''));
    }
}
$calls = implode('|', $GLOBALS['SAME_LM_CALLS']);
same_assert($calls !== '' && str_contains($calls, 'Flottarestigen'), 'lm_lookup söker adressen och går förbi cachen, fick ' . $calls);
same_assert(!str_contains($calls, 'obj-lertavlan'), 'lm_lookup skickar inte sparat objekt-id');
same_assert((string) ($lm['fastighetsbeteckning'] ?? '') === 'SOLLENTUNA LERTAVLAN 4', 'lm_lookup behåller Lertavlan 4');
same_assert((int) ($lm['owner_count'] ?? 0) === 2 && $lmNames === $manualNames, 'lm_lookup har samma två ägare som Manuell order, fick ' . implode(', ', $lmNames));
same_assert((string) ($lm['owners'][0]['andel'] ?? '') === '1/2' && (string) ($lm['owners'][1]['andel'] ?? '') === '1/2', 'lm_lookup visar andelarna');
$lmJson = (string) json_encode($lm, JSON_UNESCAPED_UNICODE);
same_assert(!str_contains($lmJson, 'personnummer') && !str_contains($lmJson, '19700101'), 'lm_lookup lämnar inget personnummer');

$preview = jeab_ordrar_set_fastighet_run($store, [
    'order_id' => 20,
    'fastighetsbeteckning' => 'SOLLENTUNA LERTAVLAN 4',
    'refetch_owners' => 1,
]);
$previewNames = [];
foreach (is_array($preview['owners'] ?? null) ? $preview['owners'] : [] as $owner) {
    if (is_array($owner)) {
        $previewNames[] = trim((string) ($owner['name'] ?? ''));
    }
}
same_assert((int) ($preview['lookup_empty'] ?? 1) === 0 && $previewNames === $manualNames, 'set_fastighet hämtar samma två ägare, fick ' . implode(', ', $previewNames));
same_assert(!str_contains((string) json_encode($preview, JSON_UNESCAPED_UNICODE), '19700101'), 'set_fastighet lämnar inget personnummer');

$button = jeab_order_fastighet_refresh($store, ['order_id' => 20, 'dry' => 0]);
same_assert(($button['ok'] ?? false) === true && (int) ($button['written'] ?? 0) === 1, 'Hämta fastighetsinfo igen skriver');
$saved = (array) jeab_purchase_get(20);
$savedNames = [];
foreach (is_array($saved['owners'] ?? null) ? $saved['owners'] : [] as $owner) {
    if (is_array($owner) && trim((string) ($owner['name'] ?? '')) !== '') {
        $savedNames[] = trim((string) $owner['name']);
    }
}
same_assert($savedNames === $manualNames, 'knappen ersätter de tre cachade ägarna, fick ' . implode(', ', $savedNames));
same_assert((string) ($saved['fastighetsbeteckning'] ?? '') === 'SOLLENTUNA LERTAVLAN 4', 'beteckningen ligger kvar');
same_assert(!str_contains((string) json_encode($button, JSON_UNESCAPED_UNICODE), '19700101'), 'knappens svar saknar personnummer');

$written = jeab_ordrar_lm_lookup_run($store, ['order_id' => 20, 'dry' => 0]);
same_assert(($written['ok'] ?? false) === true, 'lm_lookup dry=0 anropar sync');
$src = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar.php');
$start = strpos($src, 'function jeab_ordrar_lm_lookup_run(');
$end = strpos($src, 'function jeab_ordrar_lm_lookup_dispatch(');
$body = $start !== false && $end !== false ? substr($src, $start, $end - $start) : '';
same_assert(str_contains($body, 'jeab_ordrar_sync_lookup_owners('), 'lm_lookup skriver via sync_lookup_owners');
same_assert(str_contains($body, "'_JEAB_LM_CACHE_BYPASS'"), 'lm_lookup går förbi cachen');

echo "ordrar lm same lookup: ok\n";
