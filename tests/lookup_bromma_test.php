<?php

declare(strict_types=1);

/**
 * Lantmäteriet: postort som är en stadsdel (Bromma) ska sökas om mot kommunen,
 * och nästlad beteckning/identitet ska läsas ut. Påhittade namn och siffror.
 * Inget riktigt nätverksanrop. Personnummer skrivs inte ut.
 */

$tmp = sys_get_temp_dir() . '/jeab-lookup-bromma-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('JEAB_WEBHOOK_CAPTURE=1');

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/address_lookup.php';

function lm_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/** Påhittad sifferserie, aldrig utskriven. */
function lm_digits(): string
{
    return implode('', ['19', '70', '01', '01', '12', '34']);
}

function lm_norm_digits(): string
{
    return jeab_pnr_normalize(lm_digits());
}

$nested = jeab_rot_normalize_lookup([
    'fastighet' => ['beteckning' => 'Testköping Exemplet 3:3'],
    'owners' => [
        'namn' => 'Ada Exempel',
        'person' => ['identitetsnummer' => lm_digits()],
    ],
]);
lm_assert(($nested['fastighetsbeteckning'] ?? '') === 'TESTKÖPING EXEMPLET 3:3', 'nästlad beteckning läses ut');
lm_assert(count($nested['owners'] ?? []) === 1, 'en ägare som objekt, inte som fältlista');
lm_assert(($nested['owners'][0]['name'] ?? '') === 'Ada Exempel', 'namn läses från nästlad ägare');
lm_assert(($nested['owners'][0]['personnummer'] ?? '') === lm_norm_digits(), 'identitetsnummer normaliseras');

$register = jeab_rot_normalize_lookup([
    'registerenhet' => ['beteckning' => 'Testköping Exemplet 4:4'],
    'agare' => [
        ['förnamn' => 'Bo', 'efternamn' => 'Exempel', 'person' => ['personnummer' => lm_digits()]],
    ],
]);
lm_assert(($register['fastighetsbeteckning'] ?? '') === 'TESTKÖPING EXEMPLET 4:4', 'registerenhet.beteckning läses');
lm_assert(($register['owners'][0]['name'] ?? '') === 'Bo Exempel', 'förnamn och efternamn slås ihop');
lm_assert(($register['owners'][0]['personnummer'] ?? '') === lm_norm_digits(), 'personnummer under person läses');

lm_assert(jeab_rot_postal_municipality('Bromma') === 'Stockholm', 'Bromma är stadsdel i Stockholm');
lm_assert(jeab_rot_postal_municipality('Stockholm') === '', 'kommunen själv ger ingen reservort');
lm_assert(jeab_rot_postal_municipality('Örebro') === '', 'ort som är kommun ger ingen reservort');
lm_assert(jeab_rot_municipality_query('Åkerhielmsgatan 20 Bromma') === 'Åkerhielmsgatan 20 Stockholm', 'reservsökning byter bara postort mot kommun');
lm_assert(jeab_rot_municipality_query('Åkerhielmsgatan 20') === '', 'gata utan ort söks inte om');
lm_assert(jeab_rot_municipality_query('Vanliggatan 4 Örebro') === '', 'kommun som postort söks inte om');
$parts = jeab_rot_split_street_place('Åkerhielmsgatan 20 A Bromma');
lm_assert($parts['street'] === 'Åkerhielmsgatan 20 A' && $parts['place'] === 'Bromma', 'bokstav behålls när den finns, och tas inte på utan');
lm_assert(jeab_rot_split_street_place('Åkerhielmsgatan 20 Bromma')['street'] === 'Åkerhielmsgatan 20', 'husnummer utan bokstav lämnas utan bokstav');

$rotDir = $tmp . '/rot';
@mkdir($rotDir, 0775, true);
$stub = <<<'PHP'
<?php
class LantmaterietAPI {
    public $data = [];
    public function search($addressText, $uuid = '') {
        $GLOBALS['LM_BROMMA_CALLS'][] = (string) $addressText;
        $t = (string) $addressText;
        $digits = $GLOBALS['LM_BROMMA_DIGITS'];
        if (str_contains($t, 'Nestgatan')) {
            $this->data = [
                'fastighet' => ['beteckning' => 'Testköping Exemplet 3:3'],
                'owners' => [
                    'namn' => 'Ada Exempel',
                    'person' => ['identitetsnummer' => $digits],
                ],
            ];
            return true;
        }
        if (str_contains($t, 'Samfallighetsvägen')) {
            $this->data = [
                'fastighet' => ['beteckning' => 'Testköping Samf 1:1'],
                'owners' => [],
            ];
            return false;
        }
        if (str_contains($t, 'Åkerhielmsgatan') && str_contains($t, 'Stockholm')) {
            $this->data = [
                'fastighetsbeteckning' => 'Stockholm Åkerhielm 1:12',
                'matchedAddress' => 'Åkerhielmsgatan 20',
                'postnummer' => '11122',
                'postort' => 'Bromma',
                'owners' => [
                    ['name' => 'Ada Exempel', 'personnummer' => $digits, 'address' => 'Åkerhielmsgatan 20'],
                ],
            ];
            return true;
        }
        if (str_contains($t, 'Åkerhielmsgatan') && str_contains($t, 'Bromma')) {
            $this->data = [
                'matchedAddress' => 'Åkerhielmsgatan 20 Bromma',
                'owners' => [
                    ['namn' => 'Ada Exempel'],
                ],
            ];
            return true;
        }
        if (str_contains($t, 'Vanliggatan')) {
            $this->data = [
                'fastighetsbeteckning' => 'Örebro Vanlig 1:1',
                'owners' => [
                    ['name' => 'Bo Exempel', 'personnummer' => $digits],
                ],
            ];
            return true;
        }
        return false;
    }
}
PHP;
file_put_contents($rotDir . '/data_scraping.php', $stub);
$GLOBALS['_JEAB_ROT_DATA_SCRAPING_CANDIDATES'] = [$rotDir . '/data_scraping.php'];
$GLOBALS['LM_BROMMA_DIGITS'] = lm_digits();
$GLOBALS['LM_BROMMA_CALLS'] = [];
jeab_ordrar_external_live(true);

$calls = static function (): array {
    $rows = $GLOBALS['LM_BROMMA_CALLS'] ?? [];
    return is_array($rows) ? array_values($rows) : [];
};

$thin = jeab_rot_lookup_address('Åkerhielmsgatan 20, Bromma');
$afterThin = $calls();
lm_assert(count($afterThin) === 2, 'tunn träff på stadsdel söks om en gång, fick ' . count($afterThin));
lm_assert(str_contains($afterThin[0], 'Åkerhielmsgatan') && str_contains($afterThin[0], 'Bromma'), 'första sökningen behåller å och postorten');
lm_assert(!str_contains($afterThin[0], 'Stockholm'), 'första sökningen använder inte kommunen');
lm_assert($afterThin[1] === 'Åkerhielmsgatan 20 Stockholm', 'reservsökningen är gatan 20 utan bokstav mot Stockholm');
lm_assert(($thin['fastighetsbeteckning'] ?? '') === 'STOCKHOLM ÅKERHIELM 1:12', 'reservsökningen fyller fastighetsbeteckning');
lm_assert(count($thin['owners'] ?? []) === 1 && ($thin['owners'][0]['personnummer'] ?? '') === lm_norm_digits(), 'reservsökningen fyller personnummer');
$trace = $GLOBALS['_JEAB_ROT_LOOKUP_TRACE'] ?? [];
$traceJson = json_encode($trace, JSON_UNESCAPED_UNICODE);
lm_assert(is_string($traceJson) && !str_contains($traceJson, lm_digits()) && !str_contains($traceJson, lm_norm_digits()), 'spåret innehåller inget personnummer');

$mark = count($afterThin);
$nestedHit = jeab_rot_lookup_address('Nestgatan 2, Bromma');
$afterNest = $calls();
lm_assert(count($afterNest) === $mark + 1, 'nästlad beteckning i första svaret söks inte om');
lm_assert(($nestedHit['fastighetsbeteckning'] ?? '') === 'TESTKÖPING EXEMPLET 3:3', 'uppslag läser nästlad beteckning');
lm_assert(($nestedHit['owners'][0]['personnummer'] ?? '') === lm_norm_digits(), 'uppslag läser nästlat identitetsnummer');

$mark = count($afterNest);
$plain = jeab_rot_lookup_address('Vanliggatan 4, Örebro');
lm_assert(count($calls()) === $mark + 1, 'adress där postort är kommun söks en gång');
lm_assert(($plain['fastighetsbeteckning'] ?? '') === 'ÖREBRO VANLIG 1:1', 'vanlig adress får beteckning direkt');

$sam = jeab_rot_class_address_search('Samfallighetsvägen 1 Testköping');
lm_assert(is_array($sam) && jeab_rot_extract_beteckning($sam) === 'TESTKÖPING SAMF 1:1', 'träff utan ägare behåller nästlad beteckning');

$id = 880221;
$purchasePdo = new PDO('sqlite:' . $tmp . '/purchases.sqlite');
$purchasePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_PURCHASES_PDO'] = $purchasePdo;
jeab_purchases_store_ensure($purchasePdo);
jeab_purchase_put([
    'reminder_id' => $id,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Ada Exempel',
    'huvudsokande' => 'Ada Exempel',
    'address' => 'Åkerhielmsgatan 20, Bromma',
    'ort' => 'Bromma',
    'personnummer' => '',
    'fastighetsbeteckning' => '',
]);
$denied = jeab_ordrar_lm_lookup_dispatch(jeab_store(), ['order_id' => $id, 'dry' => 1]);
lm_assert(($denied['ok'] ?? true) === false && ($denied['status'] ?? 0) === 401, 'hooken kräver inkorgs-token');

$dry = jeab_ordrar_lm_lookup_run(jeab_store(), ['order_id' => $id, 'dry' => 1]);
$dryJson = json_encode($dry, JSON_UNESCAPED_UNICODE);
lm_assert(($dry['ok'] ?? false) === true && ($dry['dry'] ?? 0) === 1, 'dry=1 är standardläget när det skickas');
lm_assert(($dry['applied'] ?? true) === false, 'dry=1 skriver inget');
lm_assert(($dry['fastighet_found'] ?? false) === true, 'dry=1 visar att beteckning hittades');
lm_assert(($dry['fastighetsbeteckning'] ?? '') === 'STOCKHOLM ÅKERHIELM 1:12', 'dry=1 visar beteckningen');
lm_assert(($dry['owner_count'] ?? 0) === 1 && ($dry['pnr_found'] ?? false) === true, 'dry=1 visar ägarantal och att personnummer fanns');
lm_assert((string) ($dry['owners'][0]['name'] ?? '') === 'Ada Exempel', 'dry=1 visar ägarnamnet');
lm_assert(!array_key_exists('personnummer', (array) ($dry['owners'][0] ?? [])), 'dry=1 har inget personnummerfält');
lm_assert(($dry['fallback_query'] ?? '') === 'Åkerhielmsgatan 20 Stockholm', 'dry=1 visar reservsökningen');
lm_assert(is_string($dryJson) && !str_contains($dryJson, lm_digits()) && !str_contains($dryJson, lm_norm_digits()), 'dry=1-svaret saknar personnummer');
lm_assert(is_string($dryJson) && preg_match('/(?<!\d)(?:\d{6}|\d{8})[-+]?\d{4}(?!\d)/', $dryJson) !== 1, 'dry=1-svaret har inget personnummerformat');
$still = jeab_purchase_get($id);
lm_assert(trim((string) ($still['fastighetsbeteckning'] ?? '')) === '', 'dry=1 lämnar orderns beteckning tom');

$wet = jeab_ordrar_lm_lookup_run(jeab_store(), ['order_id' => $id, 'dry' => 0]);
$wetJson = json_encode($wet, JSON_UNESCAPED_UNICODE);
$saved = jeab_purchase_get($id);
$savedDigits = preg_replace('/\D+/', '', (string) ($saved['personnummer'] ?? '')) ?? '';
lm_assert(($wet['applied'] ?? false) === true && ($wet['dry'] ?? 1) === 0, 'dry=0 skriver uppslaget');
lm_assert(($saved['fastighetsbeteckning'] ?? '') === 'STOCKHOLM ÅKERHIELM 1:12', 'dry=0 sparar beteckningen');
lm_assert(strlen($savedDigits) === 12, 'dry=0 sparar personnummer på ordern');
lm_assert(is_string($wetJson) && !str_contains($wetJson, $savedDigits) && !str_contains($wetJson, lm_digits()), 'dry=0-svaret saknar personnummer');

$missing = jeab_ordrar_lm_lookup_run(jeab_store(), ['dry' => 1]);
lm_assert(($missing['ok'] ?? true) === false, 'utan order_id blir det fel');

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
lm_assert(str_contains($hook, "=== 'ordrar.lm_lookup'") && str_contains($hook, 'jeab_ordrar_lm_lookup_dispatch'), 'hook.php tar ordrar.lm_lookup');

echo "Alla Bromma/LM-tester gick igenom.\n";
