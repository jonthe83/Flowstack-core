<?php

declare(strict_types=1);

/**
 * Lantmäteriet-uppslaget: LantmaterietAPI laddas från rot/data_scraping.php
 * (filen flyttade inte med till src/ 2026-09-23), fel loggas maskerat,
 * och ägarmatchningen tar en försiktig reserv vid avvikande stavning.
 */

$tmp = sys_get_temp_dir() . '/jeab-lookup-owner-' . getmypid();
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

function lo_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function lo_log_lines(string $dir): array
{
    $file = $dir . '/app-errors.jsonl';
    if (!is_file($file)) {
        return [];
    }
    $out = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $row = json_decode($line, true);
        if (is_array($row)) {
            $out[] = $row;
        }
    }
    return $out;
}

// --- Sökväg till LantmaterietAPI ---
$candidates = jeab_rot_data_scraping_candidates();
$root = rtrim((string) JEAB_ROOT, '/');
lo_assert(($candidates[0] ?? '') === $root . '/rot/data_scraping.php', 'första kandidaten är JEAB_ROOT/rot/data_scraping.php');
lo_assert(in_array(dirname(__DIR__) . '/rot/data_scraping.php', $candidates, true), 'dirname(src)/rot/data_scraping.php finns med');
lo_assert(end($candidates) === dirname(__DIR__) . '/src/data_scraping.php', 'gamla src/-sökvägen finns kvar sist');

// Ingen fil, ingen klass: null och en lookup-rad i felloggen.
$GLOBALS['_JEAB_ROT_DATA_SCRAPING_CANDIDATES'] = [$tmp . '/saknas/data_scraping.php'];
lo_assert(jeab_rot_data_scraping_file() === '', 'saknad fil ger tom sökväg');
lo_assert(jeab_rot_class_address_search('Testgatan 1 Teststad') === null, 'saknad klass ger null (reservanrop får försöka)');
$lines = lo_log_lines($tmp);
$last = end($lines) ?: [];
lo_assert(($last['surface'] ?? '') === 'lookup' && str_contains((string) ($last['message'] ?? ''), 'LantmaterietAPI saknas'), 'saknad klass loggas under lookup');

// Stubbfil i en rot/-katalog laddas (inkluderad från src/).
$rotDir = $tmp . '/rot';
@mkdir($rotDir, 0775, true);
file_put_contents($rotDir . '/data_scraping.php', <<<'PHP'
<?php
class LantmaterietAPI {
    public $data = [];
    public function search($addressText, $uuid = '') {
        if (str_contains($addressText, 'Kastar')) {
            throw new Exception('API error 401: {"pnr":"19700101-1234","x":"198001011234"}');
        }
        if (str_contains($addressText, 'Brf')) {
            $this->data = ['fastighetsbeteckning' => 'TESTBY 9:9', 'owners' => []];
            return false;
        }
        if (str_contains($addressText, 'Ingen')) {
            return false;
        }
        $this->data = [
            'fastighetsbeteckning' => 'Testby 1:2',
            'matchedAddress' => 'Teststad Testgatan 1',
            'owners' => [
                ['name' => 'Evangelia Maria Testou', 'personnummer' => '197001011234', 'address' => 'Testgatan 1, 123 45 Teststad', 'andel' => '1/2'],
                ['name' => 'Nikos Papatest', 'personnummer' => '196801011238', 'address' => 'Testgatan 1, 123 45 Teststad', 'andel' => '1/2'],
            ],
        ];
        return true;
    }
}
?>
PHP);
$GLOBALS['_JEAB_ROT_DATA_SCRAPING_CANDIDATES'] = [$tmp . '/saknas/data_scraping.php', $rotDir . '/data_scraping.php'];
lo_assert(jeab_rot_data_scraping_file() === $rotDir . '/data_scraping.php', 'första befintliga kandidaten väljs');
$found = jeab_rot_class_address_search('Testgatan 1 Teststad');
lo_assert(class_exists('LantmaterietAPI', false), 'klassen laddades från rot/');
lo_assert(is_array($found) && ($found['fastighetsbeteckning'] ?? '') === 'Testby 1:2', 'träff ger fastighetsbeteckning');
$norm = jeab_rot_lookup_address('Testgatan 1, Teststad');
lo_assert(count($norm['owners'] ?? []) === 2 && ($norm['owners'][0]['personnummer'] ?? '') === '19700101-1234', 'jeab_rot_lookup_address normaliserar ägare och personnummer');

$brf = jeab_rot_class_address_search('Brfgatan 2');
lo_assert(is_array($brf) && ($brf['fastighetsbeteckning'] ?? '') === 'TESTBY 9:9', 'fastighet utan privatägare behåller beteckningen');
lo_assert(jeab_rot_class_address_search('Ingengatan 3') === [], 'ren miss ger [] (inget reservanrop)');

$before = count(lo_log_lines($tmp));
lo_assert(jeab_rot_class_address_search('Kastargatan 4') === null, 'undantag ger null');
$lines = lo_log_lines($tmp);
lo_assert(count($lines) === $before + 1, 'undantaget loggades en gång');
$msg = (string) (end($lines)['message'] ?? '');
lo_assert(($lines[count($lines) - 1]['surface'] ?? '') === 'lookup' && str_contains($msg, 'API error 401'), 'undantaget loggas med HTTP-status');
lo_assert(!preg_match('/\d{6,}/', $msg) && !str_contains($msg, 'Kastargatan'), 'loggen saknar personnummer, sifferserier och adress');
lo_assert(jeab_rot_lookup_mask('19700101-1234 och 700101-1234 och 197001011234') === '[pnr] och [pnr] och [pnr]', 'maskning av alla pnr-format');
lo_assert(jeab_rot_http_status(['HTTP/1.1 302 Found', 'Location: x', 'HTTP/1.1 403 Forbidden']) === 403, 'sista HTTP-statusen läses');
unset($GLOBALS['_JEAB_ROT_DATA_SCRAPING_CANDIDATES']);

// --- Ägarmatchning ---
lo_assert(jeab_ordrar_name_fold('Åsa Ödén') === 'asa oden', 'diakritiska tecken viks');
lo_assert(jeab_ordrar_owner_fits_customer('Evangelia Maria Testou', 'evangelia testou'), 'förnamn bland förnamnen + samma efternamn passar');
lo_assert(jeab_ordrar_owner_fits_customer('Per Erik Daniel Lemkér', 'Daniel Lemker'), 'tilltalsnamn i mitten och accent passar');
lo_assert(jeab_ordrar_owner_fits_customer('Anna-Karin Lund', 'Karin Lund'), 'del av bindestrecksnamn passar');
lo_assert(!jeab_ordrar_owner_fits_customer('Evangelia Maria Testou', 'Evangelia Papatest'), 'annat efternamn passar aldrig');
lo_assert(!jeab_ordrar_owner_fits_customer('Maria Testou', 'Evangelia Testou'), 'förnamn saknas bland förnamnen');
lo_assert(!jeab_ordrar_owner_fits_customer('Testou', 'Evangelia Testou'), 'ägare utan förnamn passar inte');

$owners = [
    ['name' => 'Evangelia Maria Testou', 'personnummer' => '19700101-1234'],
    ['name' => 'Nikos Papatest', 'personnummer' => '19680101-1238'],
];
$hit = jeab_ordrar_find_owner($owners, 'Evangelia Testou');
lo_assert($hit['name'] === 'Evangelia Maria Testou' && $hit['personnummer'] === '19700101-1234', 'find_owner faller tillbaka på efternamn + förnamn');
lo_assert(jeab_ordrar_find_owner($owners, 'Nikos Papatest')['personnummer'] === '19680101-1238', 'exakt namn fungerar som förut');
lo_assert(jeab_ordrar_find_owner($owners, 'Valja Testou')['name'] === '', 'smeknamn med två ägare ger ingen träff');
lo_assert(jeab_ordrar_find_owner($owners, 'Evangelia Andersson')['name'] === '', 'annat efternamn ger ingen träff');
lo_assert(jeab_ordrar_match_owner($owners, 'Evangelia Testou')['personnummer'] === '19700101-1234', 'match_owner: förnamn först som förut');
$mid = [['name' => 'Per Erik Daniel Lemker', 'personnummer' => '19800101-1230'], ['name' => 'Lisa Lemker', 'personnummer' => '19820101-1235']];
lo_assert(jeab_ordrar_match_owner($mid, 'Daniel Lemker')['personnummer'] === '19800101-1230', 'match_owner: tilltalsnamn i mitten med samma efternamn');
lo_assert(jeab_ordrar_match_owner($mid, 'Kalle Lemker')['name'] === '', 'match_owner: två ägare med samma efternamn och fel förnamn ger ingen träff');

$single = [['name' => 'Evangelia Maria Testou', 'personnummer' => '19700101-1234']];
lo_assert(jeab_ordrar_fuzzy_owner($single, 'Valja Testou')['personnummer'] === '19700101-1234', 'en ensam ägare med samma efternamn godtas');
lo_assert(jeab_ordrar_fuzzy_owner($single, 'Valja Andersson')['name'] === '', 'en ensam ägare med annat efternamn godtas inte');
$twins = [['name' => 'Anna Maria Svensson', 'personnummer' => '19700101-1234'], ['name' => 'Maria Anna Svensson', 'personnummer' => '19720101-1232']];
lo_assert(jeab_ordrar_fuzzy_owner($twins, 'Anna Svensson')['name'] === '', 'två passande ägare är tvetydigt');

// confirm_suggest med manuellt huvudsökande-namn i kortform hämtar pnr.
jeab_ordrar_external_live(true);
$GLOBALS['_JEAB_ROT_DATA_SCRAPING_CANDIDATES'] = [$rotDir . '/data_scraping.php'];
$row = [
    'reminder_id' => 999001,
    'address' => 'Testgatan 1, Teststad',
    'huvudsokande' => 'Evangelia Testou',
    'huvudsokande_manual' => true,
    'personnummer' => '',
    'fastighetsbeteckning' => '',
];
$reminder = ['id' => 999001, 'customer_name' => 'Evangelia Testou', 'address' => 'Testgatan 1, Teststad', 'title' => 'Evangelia Testou'];
$suggest = jeab_ordrar_confirm_suggest($row, $reminder);
lo_assert(($suggest['personnummer'] ?? '') === '19700101-1234', 'confirm_suggest fyller personnummer från passande ägare');
lo_assert(($suggest['fastighetsbeteckning'] ?? '') === 'TESTBY 1:2', 'confirm_suggest fyller fastighetsbeteckning');
$medPnrs = array_map(static fn(array $p): string => (string) ($p['personnummer'] ?? ''), $suggest['medsokande'] ?? []);
lo_assert(!in_array('19700101-1234', $medPnrs, true) && in_array('19680101-1238', $medPnrs, true), 'huvudsökande står inte också som medsökande');
$filled = jeab_ordrar_apply_suggest($row, $suggest);
lo_assert(($filled['personnummer'] ?? '') === '19700101-1234' && ($filled['huvudsokande'] ?? '') === 'Evangelia Testou', 'apply_suggest skriver pnr och behåller manuellt namn');

$other = $row;
$other['huvudsokande'] = 'Evangelia Andersson';
$other['address'] = 'Testgatan 1, Teststad';
$reminder2 = $reminder;
$reminder2['customer_name'] = 'Evangelia Andersson';
$reminder2['title'] = 'Evangelia Andersson';
$s2 = jeab_ordrar_confirm_suggest($other, $reminder2);
lo_assert(($s2['personnummer'] ?? '') === '', 'kund med annat efternamn får inget pnr från ägarna');
unset($GLOBALS['_JEAB_ROT_DATA_SCRAPING_CANDIDATES']);

// Fortnox-ägarlistan: samma personnummer med två stavningar blir en person.
$fx = jeab_ordrar_fortnox_owners([
    'huvudsokande' => 'Evangelia Testou',
    'personnummer' => '19700101-1234',
    'owners' => [
        ['name' => 'Evangelia Maria Testou', 'personnummer' => '19700101-1234'],
        ['name' => 'Nikos Papatest', 'personnummer' => '19680101-1238'],
    ],
]);
lo_assert(count($fx) === 2 && $fx[0]['name'] === 'Evangelia Testou', 'Fortnox-ägare dubbleras inte vid samma personnummer');

echo "Alla lookup/ägarmatchningstester gick igenom.\n";
