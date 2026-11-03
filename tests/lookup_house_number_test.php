<?php

declare(strict_types=1);

/**
 * Husnummer jämförs hela. 6 är inte 66, 6A eller 16.
 * Valt förslag slås upp på objektidentitet, ett anrop.
 * Inget personnummer i svaret eller i den här filen.
 */

$tmp = sys_get_temp_dir() . '/jeab-lookup-nr-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/address_lookup.php';

function hn_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function hn_prop(string $id, string $number, string $letter, string $postal, string $name, string $fast): array
{
    $house = $number . $letter;
    return [
        'objektidentitet' => $id,
        'fastighetsbeteckning' => $fast,
        'matchedAddress' => 'Teststigen ' . $house,
        'postnummer' => $postal,
        'postort' => 'Teststad',
        'owners' => [
            ['name' => $name, 'address' => 'Teststigen ' . $house . ', ' . substr($postal, 0, 3) . ' ' . substr($postal, 3) . ' Teststad'],
        ],
    ];
}

$rotDir = $tmp . '/rot';
@mkdir($rotDir, 0775, true);
file_put_contents($rotDir . '/data_scraping.php', <<<'PHP'
<?php
function hn_stub_prop(string $id, string $number, string $letter, string $postal, string $name, string $fast): array
{
    $house = $number . $letter;
    $zip = substr($postal, 0, 3) . ' ' . substr($postal, 3);
    return [
        'objektidentitet' => $id,
        'fastighetsbeteckning' => $fast,
        'matchedAddress' => 'Teststigen ' . $house,
        'postnummer' => $postal,
        'postort' => 'Teststad',
        'owners' => [
            ['name' => $name, 'address' => 'Teststigen ' . $house . ', ' . $zip . ' Teststad'],
        ],
    ];
}
class LantmaterietAPI {
    public $data = [];
    public function search($addressText, $uuid = '') {
        $GLOBALS['HN_CALLS'][] = ['q' => (string) $addressText, 'id' => (string) $uuid];
        $postal = '19251';
        $rows = [
            hn_stub_prop('id-66', '66', '', $postal, 'Bo Två', 'TESTBY 1:66'),
            hn_stub_prop('id-6a', '6', 'A', $postal, 'Cia Tre', 'TESTBY 1:7'),
            hn_stub_prop('id-16', '16', '', $postal, 'Dan Fyra', 'TESTBY 1:16'),
        ];
        if (empty($GLOBALS['HN_OMIT_6'])) {
            $rows[] = hn_stub_prop('id-6', '6', '', $postal, 'Ada En', 'TESTBY 1:6');
        }
        if ((string) $uuid === 'id-6') {
            foreach ($rows as $row) {
                if ($row['objektidentitet'] === 'id-6') {
                    return $row;
                }
            }
        }
        if (!empty($GLOBALS['HN_COLLAPSE_66'])) {
            return $rows[0];
        }
        return $rows;
    }
}
PHP);
$GLOBALS['_JEAB_ROT_DATA_SCRAPING_CANDIDATES'] = [$rotDir . '/data_scraping.php'];
$GLOBALS['HN_CALLS'] = [];
$GLOBALS['HN_OMIT_6'] = false;
$GLOBALS['HN_COLLAPSE_66'] = false;
jeab_ordrar_external_live(true);

function hn_calls(): array
{
    $rows = $GLOBALS['HN_CALLS'] ?? [];
    return is_array($rows) ? array_values($rows) : [];
}

function hn_reset(): void
{
    $GLOBALS['HN_CALLS'] = [];
}

hn_assert(jeab_rot_lm_cache_key('Teststigen 6, 192 51 Teststad') !== jeab_rot_lm_cache_key('Teststigen 66, 192 51 Teststad'), 'cache-nyckel skiljer 6 och 66');
hn_assert(jeab_rot_lm_cache_key('Teststigen 6, 192 51 Teststad') !== jeab_rot_lm_cache_key('Teststigen 6A, 192 51 Teststad'), 'cache-nyckel skiljer 6 och 6A');
hn_assert(jeab_rot_lm_cache_key('Teststigen 6, 192 51 Teststad') !== jeab_rot_lm_cache_key('Teststigen 16, 192 51 Teststad'), 'cache-nyckel skiljer 6 och 16');
hn_assert(jeab_rot_address_exact('Teststigen 6, 192 51 Teststad', 'Teststigen 66, 192 51 Teststad') === false, '6 är inte exakt 66');
hn_assert(jeab_rot_address_exact('Teststigen 6, 192 51 Teststad', 'Teststigen 6A, 192 51 Teststad') === false, '6 är inte exakt 6A');
hn_assert(jeab_rot_address_exact('Teststigen 6, 192 51 Teststad', 'Teststigen 16, 192 51 Teststad') === false, '6 är inte exakt 16');
hn_assert(jeab_rot_address_exact('Teststigen 6, 192 51 Teststad', 'Teststigen 6, 111 11 Annanstad') === false, 'samma nummer i annat postnummer är inte exakt');
$otherTown = jeab_rot_pick_lookup([
    hn_prop('id-6-annan', '6', '', '11111', 'Eva Fem', 'TESTBY 9:9'),
    hn_prop('id-6', '6', '', '19251', 'Ada En', 'TESTBY 1:6'),
], 'Teststigen 6, 192 51 Teststad', '');
hn_assert(!empty($otherTown['accepted']) && ($otherTown['norm']['fastighetsbeteckning'] ?? '') === 'TESTBY 1:6', 'postnummer väljer rätt 6');

$listed = jeab_rot_normalize_lookup([
    hn_prop('id-66', '66', '', '19251', 'Bo Två', 'TESTBY 1:66'),
    hn_prop('id-6', '6', '', '19251', 'Ada En', 'TESTBY 1:6'),
]);
hn_assert(trim((string) ($listed['fastighetsbeteckning'] ?? '')) === '', 'en lista tar inte första fastigheten');

$query = 'Teststigen 6, 192 51 Teststad';
$hit = jeab_rot_lookup_address($query);
$calls = hn_calls();
hn_assert(count($calls) === 1, 'fritext med flera träffar är ett anrop, fick ' . count($calls));
hn_assert(($calls[0]['id'] ?? 'x') === '', 'fritext skickar inte ett påhittat id');
hn_assert(($hit['fastighetsbeteckning'] ?? '') === 'TESTBY 1:6', '6 väljer TESTBY 1:6');
hn_assert(($hit['owners'][0]['name'] ?? '') === 'Ada En', '6 väljer Ada En');
hn_assert(!empty($hit['lookup_exact']), '6 är en exakt träff');
hn_assert(str_contains((string) ($hit['fetched_address'] ?? ''), 'Teststigen 6'), 'hämtad adress visas för 6');
hn_assert(!str_contains((string) ($hit['fetched_address'] ?? ''), '66'), 'hämtad adress för 6 innehåller inte 66');
$trace = json_encode($GLOBALS['_JEAB_ROT_LOOKUP_TRACE'] ?? []);
hn_assert(is_string($trace) && preg_match('/(?<!\d)(?:\d{6}|\d{8})[-+]?\d{4}(?!\d)/', $trace) !== 1, 'spåret har inget personnummer');

hn_reset();
$sixA = jeab_rot_lookup_address('Teststigen 6A, 192 51 Teststad');
hn_assert(count(hn_calls()) === 1, '6A är ett anrop');
hn_assert(($sixA['owners'][0]['name'] ?? '') === 'Cia Tre', '6A väljer Cia Tre');
hn_assert(str_contains((string) ($sixA['fetched_address'] ?? ''), '6A') || str_contains((string) ($sixA['fetched_address'] ?? ''), '6a'), 'hämtad adress visar 6A');

hn_reset();
$sixteen = jeab_rot_lookup_address('Teststigen 16, 192 51 Teststad');
hn_assert(count(hn_calls()) === 1, '16 är ett anrop');
hn_assert(($sixteen['owners'][0]['name'] ?? '') === 'Dan Fyra', '16 väljer Dan Fyra');
hn_assert(($sixteen['fastighetsbeteckning'] ?? '') === 'TESTBY 1:16', '16 väljer sin beteckning');

hn_reset();
$byId = jeab_rot_lookup_address($query, 'id-6');
$idCalls = hn_calls();
hn_assert(count($idCalls) === 1, 'valt förslag är ett anrop, fick ' . count($idCalls));
hn_assert(($idCalls[0]['id'] ?? '') === 'id-6', 'valt förslag skickar objektidentiteten');
hn_assert(($byId['owners'][0]['name'] ?? '') === 'Ada En', 'id-6 hämtar Ada En');
hn_assert(str_contains(jeab_rot_fetched_address_text($byId), 'Hämtad adress'), 'exakt träff visar hämtad adress');

hn_reset();
$GLOBALS['HN_OMIT_6'] = true;
$miss = jeab_rot_lookup_address($query);
hn_assert(count(hn_calls()) === 1, 'utan exakt 6 är det fortfarande ett anrop');
hn_assert(trim((string) ($miss['fastighetsbeteckning'] ?? '')) === '', 'utan exakt träff väljs ingen fastighet');
hn_assert(($miss['owners'] ?? []) === [], 'utan exakt träff följer inga ägare med');
hn_assert(empty($miss['lookup_exact']), 'utan exakt träff är valet inte automatiskt');
hn_assert(str_contains((string) ($miss['fetched_address'] ?? ''), '66'), 'svaret visar adressen som faktiskt kom tillbaka');
hn_assert(str_contains(jeab_rot_fetched_address_text($miss), 'Ingen fastighet valdes'), 'texten säger att inget valdes');
hn_assert(count(is_array($miss['choices'] ?? null) ? $miss['choices'] : []) >= 3, 'användaren får adresserna att välja bland');
$choiceDump = json_encode($miss['choices'] ?? []);
hn_assert(is_string($choiceDump) && !str_contains($choiceDump, 'Ada En') && !str_contains($choiceDump, 'Bo Två'), 'valen innehåller inga ägarnamn');

hn_reset();
$order = jeab_ordrar_confirm_suggest([
    'address' => $query,
    'huvudsokande' => '',
    'personnummer' => '',
    'fastighetsbeteckning' => '',
], null);
hn_assert(count(hn_calls()) === 1, 'ny order slår upp adressen en gång, fick ' . count(hn_calls()));
hn_assert(trim((string) ($order['fastighetsbeteckning'] ?? '')) === '', 'ny order tar inte beteckningen från 66');
hn_assert(trim((string) ($order['huvudsokande'] ?? '')) !== 'Bo Två', 'ny order tar inte ägaren på 66');
hn_assert(empty($order['lookup_exact']), 'ny order markerar att träffen inte var exakt');
hn_reset();
$GLOBALS['HN_OMIT_6'] = false;
$GLOBALS['HN_COLLAPSE_66'] = false;
$otherPostal = jeab_ordrar_confirm_suggest([
    'address' => 'Teststigen 6',
    'postnummer' => '11111',
    'huvudsokande' => '',
    'personnummer' => '',
    'fastighetsbeteckning' => '',
], null);
hn_assert(trim((string) ($otherPostal['fastighetsbeteckning'] ?? '')) === '', 'annat postnummer ger ingen beteckning');
hn_assert(empty($otherPostal['lookup_exact']), 'annat postnummer är inte exakt');
hn_assert(str_contains((string) ($order['fetched_address'] ?? ''), '66'), 'ordern visar adressen som hämtades');
$public = jeab_ordrar_lm_lookup_public($order, $GLOBALS['_JEAB_ROT_LOOKUP_TRACE'] ?? [], true, 1, false);
$publicJson = json_encode($public, JSON_UNESCAPED_UNICODE);
hn_assert(is_string($publicJson) && preg_match('/(?<!\d)(?:\d{6}|\d{8})[-+]?\d{4}(?!\d)/', $publicJson) !== 1, 'lm_lookup-svaret har inget personnummer');
hn_assert(($public['exact'] ?? 1) === 0 && str_contains((string) ($public['fetched_address'] ?? ''), '66'), 'lm_lookup visar hämtad adress och att den inte valdes');

hn_reset();
$GLOBALS['HN_OMIT_6'] = false;
$GLOBALS['HN_COLLAPSE_66'] = true;
$collapsed = jeab_rot_lookup_address($query);
hn_assert(count(hn_calls()) === 1, 'ett redan hopslaget svar är ett anrop');
hn_assert(trim((string) ($collapsed['fastighetsbeteckning'] ?? '')) === '', 'hopslaget 66 väljs inte för fråga 6');
hn_assert(str_contains((string) ($collapsed['fetched_address'] ?? ''), '66'), 'hopslaget svar visar 66');

$suspects = jeab_rot_lm_cache_suspects([
    ['query' => 'Teststigen 6, 192 51 Teststad', 'address' => 'Teststigen 66, 192 51 Teststad'],
    ['query' => 'Teststigen 6, 192 51 Teststad', 'address' => 'Teststigen 6A, 192 51 Teststad'],
    ['query' => 'Teststigen 6, 192 51 Teststad', 'address' => 'Teststigen 16, 192 51 Teststad'],
    ['query' => 'Teststigen 6, 192 51 Teststad', 'address' => 'Teststigen 6, 192 51 Teststad'],
]);
hn_assert(($suspects['count'] ?? 0) === 3, 'tre cachade svar är fel husnummer, fick ' . (string) ($suspects['count'] ?? 0));
$kinds = array_map(static fn(array $row): string => (string) ($row['kind'] ?? ''), $suspects['items'] ?? []);
hn_assert(in_array('prefix', $kinds, true) && in_array('bokstav', $kinds, true) && in_array('annat nummer', $kinds, true), 'prefix, bokstav och annat nummer räknas');
$suspectJson = json_encode($suspects, JSON_UNESCAPED_UNICODE);
hn_assert(is_string($suspectJson) && !str_contains($suspectJson, 'Teststigen') && preg_match('/(?<!\d)(?:\d{6}|\d{8})[-+]?\d{4}(?!\d)/', $suspectJson) !== 1, 'misstänkta svar har varken adress eller personnummer');

$scan = jeab_rot_lm_cache_scan(dirname(__DIR__));
$scanJson = json_encode($scan, JSON_UNESCAPED_UNICODE);
hn_assert(is_int($scan['count'] ?? null), 'genomsökningen ger ett antal');
hn_assert(is_string($scanJson) && preg_match('/(?<!\d)(?:\d{6}|\d{8})[-+]?\d{4}(?!\d)/', $scanJson) !== 1, 'genomsökningen skriver inget personnummer');
echo 'SCAN ' . (int) $scan['count'] . "\n";

$echo66 = jeab_rot_pick_lookup([[
    'matchedAddress' => 'Teststigen 6',
    'gata' => 'Teststigen',
    'nummer' => '66',
    'postnummer' => '19251',
    'postort' => 'Teststad',
    'fastighetsbeteckning' => 'TESTBY 1:66',
    'owners' => [['name' => 'Bo Två', 'address' => 'Teststigen 66, 192 51 Teststad']],
]], $query, '');
hn_assert(empty($echo66['accepted']), 'ekad fråga 6 med registerfält 66 är inte exakt');
hn_assert(trim((string) ($echo66['norm']['fastighetsbeteckning'] ?? '')) === '', 'ekot tar inte beteckningen för 66');
hn_assert(str_contains((string) ($echo66['fetched_address'] ?? ''), '66'), 'svaret visar 66 när fälten säger olika');

$echo6a = jeab_rot_pick_lookup([[
    'matchedAddress' => 'Teststigen 6',
    'gata' => 'Teststigen',
    'nummer' => '6',
    'bokstav' => 'A',
    'postnummer' => '19251',
    'postort' => 'Teststad',
    'fastighetsbeteckning' => 'TESTBY 1:7',
]], 'Teststigen 6, 192 51 Teststad', '');
hn_assert(empty($echo6a['accepted']), '6 är inte exakt 6A när bokstaven står i ett eget fält');
hn_assert(str_contains((string) ($echo6a['fetched_address'] ?? ''), '6A') || str_contains((string) ($echo6a['fetched_address'] ?? ''), '6a'), 'svaret visar 6A');

$blank = jeab_rot_pick_lookup([
    ['fastighetsbeteckning' => 'TESTBY 1:66', 'owners' => [['name' => 'Bo Två']]],
], $query, '');
hn_assert(!empty($blank['accepted']) && ($blank['norm']['fastighetsbeteckning'] ?? '') === 'TESTBY 1:66', 'en ensam rad utan adressfält kan fortfarande användas');
hn_assert(empty($echo66['accepted']) && empty($echo6a['accepted']), 'så fort husnumret finns måste det vara exakt');

hn_reset();
$GLOBALS['HN_OMIT_6'] = false;
$GLOBALS['HN_COLLAPSE_66'] = true;
$contactMiss = jeab_kontakt_lookup_postort('Teststigen 6', '19251');
hn_assert(trim((string) ($contactMiss['fastighetsbeteckning'] ?? '')) === '', 'kontakten tar inte beteckningen för 66');
$GLOBALS['HN_COLLAPSE_66'] = false;
hn_reset();
$contactHit = jeab_kontakt_lookup_postort('Teststigen 6', '19251');
hn_assert(($contactHit['fastighetsbeteckning'] ?? '') === 'TESTBY 1:6', 'kontakten tar 6 när det är exakt');
hn_reset();
$contactLetter = jeab_kontakt_lookup_postort('Teststigen 6A', '19251');
hn_assert(($contactLetter['fastighetsbeteckning'] ?? '') === 'TESTBY 1:7', 'kontakten tar 6A när frågan är 6A');

jeab_rot_lm_cache_put($query, [
    'fastighetsbeteckning' => 'TESTBY 1:66',
    'address' => 'Teststigen 66, 192 51 Teststad',
    'owners' => [],
    'postnummer' => '19251',
    'ort' => 'Teststad',
], false);
hn_assert(jeab_rot_lm_cache_get($query) === null, 'cache för 6 lämnas tillbaka bara när adressen är 6');
jeab_rot_lm_cache_forget('TESTBY 1:66', $query);
jeab_rot_lm_cache_put($query, [
    'fastighetsbeteckning' => 'TESTBY 1:6',
    'address' => 'Teststigen 6, 192 51 Teststad',
    'owners' => [],
    'postnummer' => '19251',
    'ort' => 'Teststad',
], false);
hn_assert((jeab_rot_lm_cache_get($query)['fastighetsbeteckning'] ?? '') === 'TESTBY 1:6', 'cache för exakt 6 träffar');

hn_assert(jeab_fastighet_address_hit('Flottarestigen 66, 191 41 Sollentuna', 'Flottarestigen 6') === false, 'sökningen 6 träffar inte 66');
hn_assert(jeab_fastighet_address_hit('Flottarestigen 6A, 191 41 Sollentuna', 'Flottarestigen 6') === false, 'sökningen 6 träffar inte 6A');
hn_assert(jeab_fastighet_address_hit('Flottarestigen 6, 191 41 Sollentuna', 'Flottarestigen 6') === true, 'sökningen 6 träffar 6');

$GLOBALS['_JEAB_PURCHASE_LIST'] = [
    '11' => [
        'reminder_id' => 11,
        'address' => 'Flottarestigen 6, 191 41 Sollentuna',
        'postnummer' => '19141',
        'fastighetsbeteckning' => 'SOLNA FLOTTAREN 1:66',
        'owners' => [['name' => 'Bo Två', 'address' => 'Flottarestigen 66, 191 41 Sollentuna']],
    ],
    '12' => [
        'reminder_id' => 12,
        'address' => 'Flottarestigen 6, 191 41 Sollentuna',
        'postnummer' => '19141',
        'fastighetsbeteckning' => 'SOLNA FLOTTAREN 1:7',
        'owners' => [['name' => 'Cia Tre', 'address' => 'Flottarestigen 6A, 191 41 Sollentuna']],
    ],
    '13' => [
        'reminder_id' => 13,
        'address' => 'Flottarestigen 6, 191 41 Sollentuna',
        'postnummer' => '19141',
        'fastighetsbeteckning' => 'SOLNA FLOTTAREN 1:6',
        'owners' => [['name' => 'Ada En', 'address' => 'Flottarestigen 6, 191 41 Sollentuna']],
    ],
    '14' => [
        'reminder_id' => 14,
        'address' => 'Flottarestigen 6, 191 41 Sollentuna',
        'fastighetsbeteckning' => '',
        'owners' => [['name' => 'Bo Två', 'address' => 'Flottarestigen 66, 191 41 Sollentuna']],
    ],
    '15' => [
        'reminder_id' => 15,
        'address' => 'Flottarestigen 6, 191 41 Sollentuna',
        'postnummer' => '19141',
        'fastighetsbeteckning' => 'SOLNA FLOTTAREN 1:8',
        'owners' => [['name' => 'Eva Fem', 'address' => 'Flottarestigen 6, 111 11 Annanstad']],
    ],
];
$contactRows = [
    'kt_bad' => [
        'id' => 'kt_bad',
        'street' => 'Flottarestigen 6',
        'postal_code' => '19141',
        'city' => 'Sollentuna',
        'fastighetsbeteckning' => 'SOLNA FLOTTAREN 1:66',
        'owners' => [['name' => 'Bo Två', 'address' => 'Flottarestigen 66, 191 41 Sollentuna']],
    ],
    'kt_letter' => [
        'id' => 'kt_letter',
        'street' => 'Flottarestigen 6',
        'postal_code' => '19141',
        'city' => 'Sollentuna',
        'fastighetsbeteckning' => 'SOLNA FLOTTAREN 1:7',
        'owners' => [['name' => 'Cia Tre', 'address' => 'Flottarestigen 6A, 191 41 Sollentuna']],
    ],
    'kt_ok' => [
        'id' => 'kt_ok',
        'street' => 'Flottarestigen 6',
        'postal_code' => '19141',
        'city' => 'Sollentuna',
        'fastighetsbeteckning' => 'SOLNA FLOTTAREN 1:6',
        'owners' => [['name' => 'Ada En', 'address' => 'Flottarestigen 6, 191 41 Sollentuna']],
    ],
];
jeab_kontakter_mutate(static function (array &$contacts) use ($contactRows): void {
    foreach ($contactRows as $id => $row) {
        $contacts[$id] = $row;
    }
}, true);
$beforeContacts = jeab_kontakter_load();
$beforeOrders = $GLOBALS['_JEAB_PURCHASE_LIST'];
$mismatch = jeab_lm_mismatch_scan(['dry' => 0]);
hn_assert(($mismatch['action'] ?? '') === 'lm.mismatch_scan', 'hooksvaret heter lm.mismatch_scan');
hn_assert(($mismatch['dry'] ?? 0) === 1 && ($mismatch['read_only'] ?? 0) === 1, 'skanningen är torr och skriver inte');
hn_assert($GLOBALS['_JEAB_PURCHASE_LIST'] === $beforeOrders, 'ordrarna är oförändrade');
hn_assert(jeab_kontakter_load() === $beforeContacts, 'kontakterna är oförändrade');
$orderIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), is_array($mismatch['orders'] ?? null) ? $mismatch['orders'] : []);
$contactIds = array_map(static fn(array $row): string => (string) ($row['id'] ?? ''), is_array($mismatch['contacts'] ?? null) ? $mismatch['contacts'] : []);
sort($orderIds);
sort($contactIds);
hn_assert($orderIds === [11, 12, 15], 'order 6/66, 6/6A och fel postnummer listas, exakt 6 och rad utan beteckning gör det inte');
$listedIds = $mismatch['ids'] ?? [];
$listedIds = is_array($listedIds) ? $listedIds : [];
sort($listedIds);
hn_assert($listedIds === [11, 12, 15, 'kt_bad', 'kt_letter'], 'id-listan har order och kontakter');
hn_assert(is_int($mismatch['cache']['count'] ?? null), 'cache-misstankar ingår');
hn_assert($contactIds === ['kt_bad', 'kt_letter'], 'kontakter med 66 och 6A listas, exakt 6 gör det inte');
$mismatchJson = json_encode($mismatch, JSON_UNESCAPED_UNICODE);
hn_assert(is_string($mismatchJson) && str_contains($mismatchJson, 'Flottarestigen 66') && str_contains($mismatchJson, 'Flottarestigen 6A'), 'listan har båda adresserna');
hn_assert(is_string($mismatchJson) && !str_contains($mismatchJson, 'Bo Två') && !str_contains($mismatchJson, 'Cia Tre') && !str_contains($mismatchJson, 'Ada En'), 'listan har inga namn');
hn_assert(is_string($mismatchJson) && preg_match('/(?<!\d)(?:\d{6}|\d{8})[-+]?\d{4}(?!\d)/', $mismatchJson) !== 1, 'listan har inget personnummer');
$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
hn_assert(str_contains($hook, "=== 'lm.mismatch_scan'") && str_contains($hook, "=== 'rot.lm_cache_suspects'"), 'hook.php tar lm.mismatch_scan och rot.lm_cache_suspects');
$alias = jeab_lm_mismatch_scan(['action' => 'rot.lm_cache_suspects', 'dry' => 0]);
hn_assert(($alias['action'] ?? '') === 'rot.lm_cache_suspects' && ($alias['dry'] ?? 0) === 1, 'rot.lm_cache_suspects är samma torra läsning');
hn_assert(($alias['ids'] ?? null) === ($mismatch['ids'] ?? null), 'båda namnen listar samma id');
$denied = jeab_lm_mismatch_scan_dispatch(['dry' => 0]);
hn_assert(($denied['ok'] ?? true) === false && ($denied['status'] ?? 0) === 401, 'utan token blir det inget svar med adresser');

echo "Husnummer: ok\n";
