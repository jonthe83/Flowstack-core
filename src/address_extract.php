<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Ort och postnummer från formulär eller mejl till kontakt och påminnelse.
 * En känd ort utanför bolagets område godkänns aldrig automatiskt.
 * Källor: formulär, geokod inom området, Grok-webbsök, sedan samma
 * Lantmäteriet-uppslag som ordrar.lm_lookup. LM är nödfall, högst 1 av 10.
 */

const JEAB_ADDRESS_LM_RATIO = 10;
const JEAB_ADDRESS_LM_DAILY_MAX = 20;

/** @return array{city:string,postal:string,source:string,certain:bool,review:bool,label:string,trace:list<string>,lm_locked:bool} */
function jeab_address_extract_empty(): array
{
    return [
        'city' => '',
        'postal' => '',
        'source' => '',
        'certain' => false,
        'review' => false,
        'label' => '',
        'trace' => [],
        'lm_locked' => false,
    ];
}

function jeab_address_extract_network(): bool
{
    if (array_key_exists('_JEAB_ADDRESS_EXTRACT_NETWORK', $GLOBALS)) {
        return (bool) $GLOBALS['_JEAB_ADDRESS_EXTRACT_NETWORK'];
    }
    if (PHP_SAPI === 'cli') {
        return false;
    }
    if (function_exists('jeab_ordrar_external_live') && !jeab_ordrar_external_live()) {
        return false;
    }
    return true;
}

function jeab_address_fold(string $value): string
{
    $value = function_exists('jeab_kontakt_city_name') ? jeab_kontakt_city_name($value) : trim($value);
    if (function_exists('jeab_kontakt_fold_text')) {
        return jeab_kontakt_fold_text($value);
    }
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function jeab_address_company(string $raw): string
{
    if (!function_exists('jeab_normalize_company')) {
        return '';
    }
    return jeab_normalize_company($raw) ?? '';
}

/** @return list<array{0:int,1:int}> */
function jeab_address_postal_ranges(string $company): array
{
    if ($company === 'malmo') {
        return [[20000, 29999]];
    }
    if ($company === 'jarfalla') {
        return [
            [10000, 19999],
            [61000, 61499],
            [61600, 61999],
            [63000, 64999],
            [69000, 71999],
            [72000, 75999],
            [81400, 81999],
        ];
    }
    return [];
}

/** @return array<string, string> fold => visningsnamn */
function jeab_address_region_cities(string $company): array
{
    static $cache = [];
    if (isset($cache[$company])) {
        return $cache[$company];
    }
    $names = $company === 'malmo'
        ? [
            'Bjuv', 'Bromölla', 'Burlöv', 'Båstad', 'Eslöv', 'Helsingborg', 'Hässleholm', 'Höganäs', 'Hörby', 'Höör',
            'Klippan', 'Kristianstad', 'Kävlinge', 'Landskrona', 'Lomma', 'Lund', 'Malmö', 'Osby', 'Perstorp',
            'Simrishamn', 'Sjöbo', 'Skurup', 'Staffanstorp', 'Svalöv', 'Svedala', 'Tomelilla', 'Trelleborg',
            'Vellinge', 'Ystad', 'Åstorp', 'Ängelholm', 'Örkelljunga', 'Östra Göinge', 'Limhamn', 'Oxie',
            'Bunkeflostrand', 'Klagshamn', 'Hyllie', 'Arlöv', 'Åkarp',
        ]
        : ($company === 'jarfalla' ? [
            'Botkyrka', 'Danderyd', 'Ekerö', 'Haninge', 'Huddinge', 'Järfälla', 'Lidingö', 'Nacka', 'Norrtälje',
            'Nykvarn', 'Nynäshamn', 'Salem', 'Sigtuna', 'Sollentuna', 'Solna', 'Stockholm', 'Sundbyberg',
            'Södertälje', 'Tyresö', 'Täby', 'Upplands-Bro', 'Upplands Väsby', 'Vallentuna', 'Vaxholm', 'Värmdö',
            'Österåker', 'Jakobsberg', 'Kallhäll', 'Viksjö', 'Stäket', 'Bromma', 'Farsta', 'Hägersten',
            'Johanneshov', 'Årsta', 'Enskede', 'Skärholmen', 'Kista', 'Spånga', 'Vällingby', 'Hässelby',
            'Bagarmossen', 'Bandhagen', 'Älvsjö', 'Sköndal', 'Tumba', 'Tullinge', 'Alby', 'Fittja', 'Norsborg',
            'Hallunda', 'Flemingsberg', 'Skogås', 'Trångsund', 'Segeltorp', 'Handen', 'Vendelsö', 'Jordbro',
            'Västerhaninge', 'Saltsjöbaden', 'Fisksätra', 'Boo', 'Gustavsberg', 'Åkersberga', 'Näsbypark',
            'Viggbyholm', 'Djursholm', 'Stocksund', 'Enebyberg', 'Helenelund', 'Häggvik', 'Rotebro', 'Märsta',
            'Rosersberg', 'Rönninge', 'Pershagen', 'Järna', 'Enköping', 'Heby', 'Håbo', 'Knivsta', 'Tierp',
            'Uppsala', 'Älvkarleby', 'Östhammar', 'Bålsta', 'Arboga', 'Fagersta', 'Hallstahammar', 'Kungsör',
            'Köping', 'Norberg', 'Sala', 'Skinnskatteberg', 'Surahammar', 'Västerås', 'Eskilstuna', 'Flen',
            'Gnesta', 'Katrineholm', 'Nyköping', 'Oxelösund', 'Strängnäs', 'Trosa', 'Vingåker', 'Askersund',
            'Degerfors', 'Hallsberg', 'Hällefors', 'Karlskoga', 'Kumla', 'Laxå', 'Lekeberg', 'Lindesberg',
            'Ljusnarsberg', 'Nora', 'Örebro',
        ] : []);
    $out = [];
    foreach ($names as $name) {
        $out[jeab_address_fold($name)] = $name;
    }
    $cache[$company] = $out;
    return $out;
}

/** @return list<string> orter, postnummer eller intervall från config och SQL */
function jeab_address_extra_tokens(string $company): array
{
    $tokens = [];
    if (function_exists('jeab_config')) {
        $cfg = jeab_config();
        $block = is_array($cfg['address_extract']['extra'][$company] ?? null) ? $cfg['address_extract']['extra'][$company] : [];
        foreach ($block as $item) {
            $text = trim((string) $item);
            if ($text !== '') {
                $tokens[] = $text;
            }
        }
    }
    $pdo = jeab_address_extract_pdo();
    if ($pdo instanceof PDO && function_exists('jeab_settings_value')) {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        $raw = jeab_settings_value($pdo, $driver, 'address_extract_extra_' . $company);
        if (is_string($raw) && $raw !== '') {
            foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $item) {
                $text = trim((string) $item);
                if ($text !== '') {
                    $tokens[] = $text;
                }
            }
        }
    }
    return $tokens;
}

function jeab_address_postal_digits(string $raw): string
{
    if (function_exists('jeab_kontakt_postal')) {
        $postal = jeab_kontakt_postal($raw);
        if ($postal !== '') {
            return $postal;
        }
    }
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    return preg_match('/^\d{5}$/', $digits) === 1 ? $digits : '';
}

/** inside, outside eller unknown. Tom sträng är empty. */
function jeab_address_postal_kind(string $company, string $postal): string
{
    $company = jeab_address_company($company);
    $digits = jeab_address_postal_digits($postal);
    if ($company === '' || $digits === '') {
        return $digits === '' ? 'empty' : 'unknown';
    }
    $n = (int) $digits;
    foreach (jeab_address_postal_ranges($company) as $range) {
        if ($n >= $range[0] && $n <= $range[1]) {
            return 'inside';
        }
    }
    foreach (jeab_address_extra_tokens($company) as $token) {
        if (preg_match('/^(\d{5})\s*-\s*(\d{5})$/', $token, $m) === 1) {
            if ($n >= (int) $m[1] && $n <= (int) $m[2]) {
                return 'inside';
            }
            continue;
        }
        if (jeab_address_postal_digits($token) === $digits) {
            return 'inside';
        }
    }
    return 'outside';
}

/** inside, outside, unknown eller empty. Okänd text är inte en ort utanför området. */
function jeab_address_city_kind(string $company, string $city): string
{
    $company = jeab_address_company($company);
    $clean = function_exists('jeab_kontakt_city_name') ? jeab_kontakt_city_name($city) : trim($city);
    if ($company === '' || $clean === '') {
        return $clean === '' ? 'empty' : 'unknown';
    }
    $fold = jeab_address_fold($clean);
    $regions = jeab_address_region_cities($company);
    if (isset($regions[$fold])) {
        return 'inside';
    }
    if (function_exists('jeab_kontakt_postal_towns') && function_exists('jeab_kontakt_city_fold')) {
        $mun = (string) (jeab_kontakt_postal_towns()[jeab_kontakt_city_fold($clean)] ?? '');
        if ($mun !== '' && isset($regions[jeab_address_fold($mun)])) {
            return 'inside';
        }
    }
    foreach (jeab_address_extra_tokens($company) as $token) {
        if (preg_match('/^\d{5}/', $token) === 1) {
            continue;
        }
        if (jeab_address_fold($token) === $fold) {
            return 'inside';
        }
    }
    $canon = function_exists('jeab_kontakt_place_canon') ? jeab_kontakt_place_canon($clean) : '';
    if ($canon !== '' || (function_exists('jeab_kontakt_postal_towns') && function_exists('jeab_kontakt_city_fold') && isset(jeab_kontakt_postal_towns()[jeab_kontakt_city_fold($clean)]))) {
        return 'outside';
    }
    return 'unknown';
}

function jeab_address_city_title(string $city): string
{
    $city = trim(preg_replace('/\s+/u', ' ', $city) ?? $city);
    if ($city === '') {
        return '';
    }
    $parts = preg_split('/([\s-]+)/u', $city, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
    $out = '';
    foreach ($parts as $part) {
        if ($part === '' || preg_match('/^[\s-]+$/u', $part) === 1) {
            $out .= $part;
            continue;
        }
        $first = function_exists('mb_substr') ? mb_substr($part, 0, 1, 'UTF-8') : substr($part, 0, 1);
        $rest = function_exists('mb_substr') ? mb_substr($part, 1, null, 'UTF-8') : substr($part, 1);
        $upper = function_exists('mb_strtoupper') ? mb_strtoupper($first, 'UTF-8') : strtoupper($first);
        $lower = function_exists('mb_strtolower') ? mb_strtolower($rest, 'UTF-8') : strtolower($rest);
        $out .= $upper . $lower;
    }
    return $out;
}

function jeab_address_city_label(string $company, string $city): string
{
    $company = jeab_address_company($company);
    $clean = function_exists('jeab_kontakt_city_name') ? jeab_kontakt_city_name($city) : trim($city);
    $clean = jeab_address_city_title($clean);
    if ($clean === '') {
        return '';
    }
    $regions = jeab_address_region_cities($company);
    $fold = jeab_address_fold($clean);
    if (isset($regions[$fold])) {
        return $regions[$fold];
    }
    if (function_exists('jeab_kontakt_place_canon')) {
        $canon = jeab_kontakt_place_canon($clean);
        if ($canon !== '') {
            return $canon;
        }
    }
    return $clean;
}

/** @return array{0:float,1:float,2:float,3:float} xmin, ymin, xmax, ymax */
function jeab_address_bbox(string $company): array
{
    if ($company === 'malmo') {
        return [12.4, 55.3, 14.6, 56.55];
    }
    return [14.4, 58.55, 19.5, 60.85];
}

function jeab_address_in_bbox(string $company, float $lon, float $lat): bool
{
    [$xmin, $ymin, $xmax, $ymax] = jeab_address_bbox($company);
    return $lon >= $xmin && $lon <= $xmax && $lat >= $ymin && $lat <= $ymax;
}

/**
 * @param array<string, mixed> $in
 * @return array{city:string,postal:string,source:string,certain:bool,review:bool,label:string,trace:list<string>,lm_locked:bool}
 */
function jeab_address_extract(array $in): array
{
    $company = jeab_address_company((string) ($in['company'] ?? ''));
    $street = trim((string) ($in['street'] ?? ''));
    $name = trim((string) ($in['name'] ?? ''));
    $cityIn = trim((string) ($in['city'] ?? ''));
    $postalIn = jeab_address_postal_digits((string) ($in['postal'] ?? ''));
    $threadId = trim((string) ($in['thread_id'] ?? ''));
    $out = jeab_address_extract_empty();
    if ($company === '') {
        $out['city'] = $cityIn;
        $out['postal'] = $postalIn;
        return $out;
    }
    jeab_address_quota_begin();
    $trace = ['form'];
    $cityKind = jeab_address_city_kind($company, $cityIn);
    $postalKind = jeab_address_postal_kind($company, $postalIn);
    $sawOutside = $cityKind === 'outside' || $postalKind === 'outside';
    $formCity = '';
    if ($cityKind === 'inside') {
        $formCity = jeab_address_city_label($company, $cityIn);
    } elseif ($cityKind === 'unknown') {
        $formCity = function_exists('jeab_kontakt_city_name') ? jeab_kontakt_city_name($cityIn) : $cityIn;
    }
    $formPostal = $postalKind === 'inside' ? $postalIn : '';
    if ($formCity !== '' && $formPostal !== '') {
        return jeab_address_extract_hit($out, $formCity, $formPostal, 'form', $trace, $threadId, false, $company);
    }

    $sawCandidate = false;
    $trace[] = 'geocode';
    $geo = jeab_address_geocode($company, $street, $formCity, $formPostal);
    if (!empty($geo['candidate'])) {
        $sawCandidate = true;
    }
    if (!empty($geo['certain']) && ($formCity === '' || jeab_address_fold($formCity) === jeab_address_fold((string) $geo['city']))) {
        $geoCity = $formCity !== '' ? $formCity : (string) $geo['city'];
        return jeab_address_extract_hit($out, $geoCity, (string) $geo['postal'], $formCity !== '' ? 'form' : 'geocode', $trace, $threadId, false, $company);
    }
    if ($formCity !== '' && $formPostal === '' && (string) ($geo['postal'] ?? '') !== '' && jeab_address_postal_kind($company, (string) $geo['postal']) === 'inside' && ((string) ($geo['city'] ?? '') === '' || jeab_address_fold((string) $geo['city']) === jeab_address_fold($formCity))) {
        return jeab_address_extract_hit($out, $formCity, jeab_address_postal_digits((string) $geo['postal']), 'form', $trace, $threadId, false, $company);
    }
    if ($formPostal !== '' && $formCity === '' && (string) ($geo['city'] ?? '') !== '' && jeab_address_city_kind($company, (string) $geo['city']) === 'inside') {
        $zip = (string) ($geo['postal'] ?? '') !== '' ? jeab_address_postal_digits((string) $geo['postal']) : $formPostal;
        if ($zip === '' || jeab_address_postal_kind($company, $zip) === 'inside') {
            return jeab_address_extract_hit($out, jeab_address_city_label($company, (string) $geo['city']), $zip !== '' ? $zip : $formPostal, 'geocode', $trace, $threadId, false, $company);
        }
    }
    if ($formCity !== '' && $formPostal !== '') {
        return jeab_address_extract_hit($out, $formCity, $formPostal, 'form', $trace, $threadId, false, $company);
    }
    if ($formPostal !== '' && $formCity === '' && !$sawCandidate) {
        return jeab_address_extract_hit($out, '', $formPostal, 'form', $trace, $threadId, false, $company);
    }
    if ($formCity !== '' && $formPostal === '' && !$sawCandidate) {
        return jeab_address_extract_hit($out, $formCity, '', 'form', $trace, $threadId, false, $company);
    }

    $trace[] = 'grok';
    $grok = jeab_address_grok($company, $name, $street);
    if (!empty($grok['candidate'])) {
        $sawCandidate = true;
    }
    if (!empty($grok['certain']) && ($formCity === '' || jeab_address_fold($formCity) === jeab_address_fold((string) $grok['city']))) {
        $grokCity = $formCity !== '' ? $formCity : (string) $grok['city'];
        return jeab_address_extract_hit($out, $grokCity, (string) $grok['postal'], 'grok', $trace, $threadId, false, $company);
    }

    $needLm = $street !== '' && ($sawOutside || $sawCandidate);
    if (!$needLm) {
        if ($formCity !== '' || $formPostal !== '') {
            return jeab_address_extract_hit($out, $formCity, $formPostal, 'form', $trace, $threadId, false, $company);
        }
        $out['trace'] = $trace;
        $GLOBALS['_JEAB_ADDRESS_EXTRACT_LAST'] = $out;
        return $out;
    }
    if (!jeab_address_quota_take_lm()) {
        $trace[] = 'lm_locked';
        return jeab_address_extract_hit($out, '', '', '', $trace, $threadId, true, $company);
    }
    $trace[] = 'lm';
    $lm = jeab_address_lm_call($street, $name);
    $lmCity = (string) ($lm['city'] ?? '');
    $lmPostal = jeab_address_postal_digits((string) ($lm['postal'] ?? ''));
    $lmInside = !empty($lm['matched'])
        && jeab_address_city_kind($company, $lmCity) === 'inside'
        && jeab_address_postal_kind($company, $lmPostal) === 'inside';
    if ($lmInside) {
        return jeab_address_extract_hit($out, jeab_address_city_label($company, $lmCity), $lmPostal, 'lm', $trace, $threadId, false, $company);
    }
    return jeab_address_extract_hit($out, '', '', '', $trace, $threadId, true, $company);
}

/**
 * @param array{city:string,postal:string,source:string,certain:bool,review:bool,label:string,trace:list<string>,lm_locked:bool} $out
 * @param list<string> $trace
 * @return array{city:string,postal:string,source:string,certain:bool,review:bool,label:string,trace:list<string>,lm_locked:bool}
 */
function jeab_address_extract_hit(array $out, string $city, string $postal, string $source, array $trace, string $threadId, bool $review, string $company = ''): array
{
    $company = jeab_address_company($company);
    if (!$review && $company !== '') {
        if ($city !== '') {
            $city = jeab_address_city_label($company, $city);
        }
        if ($postal !== '') {
            $postal = jeab_address_postal_digits($postal);
        }
        $cityOutside = $city !== '' && jeab_address_city_kind($company, $city) === 'outside';
        $postalOutside = $postal !== '' && jeab_address_postal_kind($company, $postal) === 'outside';
        if ($cityOutside || $postalOutside) {
            $review = true;
            $city = '';
            $postal = '';
            $source = '';
        }
    }
    $out['city'] = $review ? '' : $city;
    $out['postal'] = $review ? '' : $postal;
    $out['source'] = $review ? '' : $source;
    $out['certain'] = !$review && ($city !== '' || $postal !== '');
    $out['review'] = $review;
    $out['label'] = $review ? 'Behöver granskas' : '';
    $out['trace'] = $trace;
    $out['lm_locked'] = in_array('lm_locked', $trace, true);
    $GLOBALS['_JEAB_ADDRESS_EXTRACT_LAST'] = $out;
    if ($review && $threadId !== '') {
        jeab_address_mark_review($threadId);
    }
    return $out;
}

/** @return array{certain:bool,candidate:bool,city:string,postal:string} */
function jeab_address_geocode(string $company, string $street, string $city, string $postal): array
{
    $empty = ['certain' => false, 'candidate' => false, 'city' => '', 'postal' => ''];
    $street = trim($street);
    if ($street === '') {
        return $empty;
    }
    $stub = $GLOBALS['_JEAB_ADDRESS_GEOCODE'] ?? null;
    $rows = [];
    if (is_callable($stub)) {
        $got = $stub($street, $company);
        $rows = is_array($got) && array_is_list($got) ? $got : (is_array($got) ? [$got] : []);
    } elseif (jeab_address_extract_network()) {
        $rows = jeab_address_geocode_live($company, trim($street . ' ' . $postal . ' ' . $city));
    }
    $best = null;
    $cities = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $hitCity = trim((string) ($row['city'] ?? ''));
        $hitPostal = jeab_address_postal_digits((string) ($row['postal'] ?? ''));
        $score = (float) ($row['score'] ?? 0);
        $lon = isset($row['lon']) ? (float) $row['lon'] : null;
        $lat = isset($row['lat']) ? (float) $row['lat'] : null;
        if ($lon !== null && $lat !== null && ($lon !== 0.0 || $lat !== 0.0) && !jeab_address_in_bbox($company, $lon, $lat)) {
            continue;
        }
        if (jeab_address_city_kind($company, $hitCity) !== 'inside' || jeab_address_postal_kind($company, $hitPostal) !== 'inside') {
            continue;
        }
        $cities[jeab_address_fold($hitCity)] = true;
        if ($best === null || $score > (float) $best['score']) {
            $best = ['score' => $score, 'city' => jeab_address_city_label($company, $hitCity), 'postal' => $hitPostal];
        }
    }
    if ($best === null) {
        return $empty;
    }
    $certain = (float) $best['score'] >= 80 && count($cities) === 1;
    return [
        'certain' => $certain,
        'candidate' => true,
        'city' => (string) $best['city'],
        'postal' => (string) $best['postal'],
    ];
}

/** @return list<array<string, mixed>> */
function jeab_address_geocode_live(string $company, string $address): array
{
    [$xmin, $ymin, $xmax, $ymax] = jeab_address_bbox($company);
    $url = 'https://geocode.arcgis.com/arcgis/rest/services/World/GeocodeServer/findAddressCandidates?' . http_build_query([
        'singleLine' => $address,
        'f' => 'json',
        'maxLocations' => 3,
        'outSR' => 4326,
        'countryCode' => 'SE',
        'outFields' => 'Postal,City,Region',
        'searchExtent' => $xmin . ',' . $ymin . ',' . $xmax . ',' . $ymax,
    ], '', '&', PHP_QUERY_RFC3986);
    $json = function_exists('jeab_ordrar_http_json') ? jeab_ordrar_http_json($url, 6) : null;
    if (!is_array($json)) {
        return [];
    }
    $out = [];
    foreach (is_array($json['candidates'] ?? null) ? $json['candidates'] : [] as $cand) {
        if (!is_array($cand)) {
            continue;
        }
        $loc = is_array($cand['location'] ?? null) ? $cand['location'] : [];
        $attr = is_array($cand['attributes'] ?? null) ? $cand['attributes'] : [];
        $out[] = [
            'score' => (float) ($cand['score'] ?? 0),
            'lon' => isset($loc['x']) ? (float) $loc['x'] : 0.0,
            'lat' => isset($loc['y']) ? (float) $loc['y'] : 0.0,
            'postal' => (string) ($attr['Postal'] ?? $attr['postal'] ?? ''),
            'city' => (string) ($attr['City'] ?? $attr['city'] ?? ''),
        ];
    }
    return $out;
}

/** @return array{certain:bool,candidate:bool,city:string,postal:string} */
function jeab_address_grok(string $company, string $name, string $street): array
{
    $empty = ['certain' => false, 'candidate' => false, 'city' => '', 'postal' => ''];
    if (trim($street) === '' && trim($name) === '') {
        return $empty;
    }
    $stub = $GLOBALS['_JEAB_ADDRESS_GROK'] ?? null;
    $hit = [];
    if (is_callable($stub)) {
        $got = $stub($name, $street, $company);
        $hit = is_array($got) ? $got : [];
    } elseif (jeab_address_extract_network()) {
        $hit = jeab_address_grok_live($company, $name, $street);
    }
    $city = trim((string) ($hit['city'] ?? ''));
    $postal = jeab_address_postal_digits((string) ($hit['postal'] ?? ''));
    $confidence = (float) ($hit['confidence'] ?? 0);
    if (jeab_address_city_kind($company, $city) !== 'inside' || jeab_address_postal_kind($company, $postal) !== 'inside') {
        return $empty;
    }
    $certain = $confidence >= 0.8;
    return [
        'certain' => $certain,
        'candidate' => true,
        'city' => jeab_address_city_label($company, $city),
        'postal' => $postal,
    ];
}

/** @return array<string, mixed> */
function jeab_address_grok_live(string $company, string $name, string $street): array
{
    if (function_exists('jeab_page_blocks_external') && jeab_page_blocks_external()) {
        return [];
    }
    if (!function_exists('jeab_inkorg_llm_config')) {
        return [];
    }
    $cfg = jeab_inkorg_llm_config();
    $key = is_array($cfg) ? trim((string) ($cfg['key'] ?? '')) : '';
    if ($key === '') {
        return [];
    }
    $area = $company === 'malmo' ? 'Skåne' : 'Stockholm, Uppsala, Västmanland, Södermanland eller Örebro';
    $prompt = 'Sök som hitta.se eller mrkoll.se efter personen och gatuadressen, bara i ' . $area
        . '. Svara bara med JSON {"postal":"fem siffror","city":"ort","confidence":0.0}. Gissa inte. confidence under 0.8 om du är osäker.';
    $body = json_encode([
        'model' => 'grok-4-fast',
        'input' => $prompt . "\nNamn: " . $name . "\nGata: " . $street,
        'tools' => [['type' => 'web_search']],
    ], JSON_UNESCAPED_UNICODE);
    if (!is_string($body)) {
        return [];
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'timeout' => 12,
        'ignore_errors' => true,
        'header' => "Content-Type: application/json\r\nAuthorization: Bearer " . $key . "\r\n",
        'content' => $body,
    ]]);
    if (function_exists('jeab_demo_outbound') && jeab_demo_outbound('xai', 'responses')) {
        return [];
    }
    // Anslutningen ligger kvar: anropet skriver eller en databasrad sparas efteråt.
    $raw = @file_get_contents('https://api.x.ai/v1/responses', false, $ctx);
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    $text = jeab_address_grok_text(is_array($decoded) ? $decoded : []);
    if (preg_match('/\{.*\}/s', $text, $m) !== 1) {
        return [];
    }
    $hit = json_decode($m[0], true);
    return is_array($hit) ? $hit : [];
}

/** @param array<string, mixed> $decoded */
function jeab_address_grok_text(array $decoded): string
{
    $chunks = [];
    foreach (is_array($decoded['output'] ?? null) ? $decoded['output'] : [] as $item) {
        if (!is_array($item)) {
            continue;
        }
        foreach (is_array($item['content'] ?? null) ? $item['content'] : [] as $part) {
            if (is_array($part) && isset($part['text'])) {
                $chunks[] = (string) $part['text'];
            }
        }
    }
    if ($chunks === [] && isset($decoded['output_text'])) {
        $chunks[] = (string) $decoded['output_text'];
    }
    return implode("\n", $chunks);
}

/**
 * Samma uppslag som ordrar.lm_lookup (jeab_ordrar_confirm_suggest → jeab_ordrar_address_lookup).
 *
 * @return array{matched:bool,city:string,postal:string}
 */
function jeab_address_lm_call(string $street, string $name): array
{
    $empty = ['matched' => false, 'city' => '', 'postal' => ''];
    $stub = $GLOBALS['_JEAB_ADDRESS_LM'] ?? null;
    if (is_callable($stub)) {
        $got = $stub($street, $name);
        if (!is_array($got)) {
            return $empty;
        }
        return [
            'matched' => !empty($got['matched']),
            'city' => trim((string) ($got['city'] ?? '')),
            'postal' => jeab_address_postal_digits((string) ($got['postal'] ?? '')),
        ];
    }
    if (!jeab_address_extract_network() || !function_exists('jeab_ordrar_confirm_suggest')) {
        return $empty;
    }
    try {
        $suggest = jeab_ordrar_confirm_suggest(
            [],
            ['customer_name' => $name, 'address' => $street, 'title' => ''],
            $street
        );
    } catch (Throwable) {
        return $empty;
    }
    if (!is_array($suggest)) {
        return $empty;
    }
    return [
        'matched' => !empty($suggest['name_matched']),
        'city' => trim((string) ($suggest['ort'] ?? '')),
        'postal' => jeab_address_postal_digits((string) ($suggest['postnummer'] ?? '')),
    ];
}

function jeab_address_mark_review(string $threadId): void
{
    $threadId = trim($threadId);
    if ($threadId === '' || !function_exists('jeab_inkorg_messages') || !function_exists('jeab_inkorg_update')) {
        return;
    }
    foreach (jeab_inkorg_messages() as $message) {
        if (!is_array($message)) {
            continue;
        }
        $id = (string) ($message['id'] ?? '');
        $tid = (string) ($message['thread_id'] ?? '');
        if ($id !== $threadId && $tid !== $threadId) {
            continue;
        }
        jeab_inkorg_update($id, static function (array $row): array {
            $labels = is_array($row['labels'] ?? null) ? $row['labels'] : [];
            if (function_exists('jeab_inkorg_form_offer_sure') && jeab_inkorg_form_offer_sure($row, $labels) !== '') {
                return $row;
            }
            $labels['needs_review'] = true;
            $labels['reason'] = 'Behöver granskas';
            $row['labels'] = $labels;
            return $row;
        });
    }
}

function jeab_address_extract_pdo(): ?PDO
{
    if (!function_exists('jeab_contact_store_pdo')) {
        return null;
    }
    try {
        $pdo = jeab_contact_store_pdo();
    } catch (Throwable) {
        return null;
    }
    return $pdo instanceof PDO ? $pdo : null;
}

function jeab_address_quota_day(): string
{
    $over = $GLOBALS['_JEAB_ADDRESS_QUOTA_DAY'] ?? '';
    if (is_string($over) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $over) === 1) {
        return $over;
    }
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
}

function jeab_address_quota_ensure(PDO $pdo): void
{
    static $ready = [];
    $key = spl_object_id($pdo);
    if (isset($ready[$key])) {
        return;
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS address_extract_quota (
                day VARCHAR(10) NOT NULL,
                lookups INT NOT NULL DEFAULT 0,
                lm_calls INT NOT NULL DEFAULT 0,
                PRIMARY KEY (day)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS address_extract_audit (
                id BIGINT NOT NULL AUTO_INCREMENT,
                contact_id VARCHAR(64) NOT NULL,
                company VARCHAR(16) NOT NULL,
                before_city VARCHAR(80) NOT NULL,
                before_postal VARCHAR(16) NOT NULL,
                after_city VARCHAR(80) NOT NULL,
                after_postal VARCHAR(16) NOT NULL,
                source VARCHAR(16) NOT NULL,
                review TINYINT NOT NULL DEFAULT 0,
                created_at VARCHAR(32) NOT NULL,
                PRIMARY KEY (id),
                KEY address_extract_audit_day (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } else {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS address_extract_quota (
                day TEXT PRIMARY KEY,
                lookups INTEGER NOT NULL DEFAULT 0,
                lm_calls INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS address_extract_audit (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                contact_id TEXT NOT NULL,
                company TEXT NOT NULL,
                before_city TEXT NOT NULL,
                before_postal TEXT NOT NULL,
                after_city TEXT NOT NULL,
                after_postal TEXT NOT NULL,
                source TEXT NOT NULL,
                review INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )'
        );
    }
    $ready[$key] = true;
}

/** @return array{lookups:int,lm_calls:int} */
function jeab_address_quota_begin(): array
{
    $day = jeab_address_quota_day();
    $pdo = jeab_address_extract_pdo();
    if (!$pdo instanceof PDO) {
        $mem = $GLOBALS['_JEAB_ADDRESS_QUOTA_MEM'][$day] ?? ['lookups' => 0, 'lm_calls' => 0];
        $mem['lookups'] = (int) $mem['lookups'] + 1;
        $GLOBALS['_JEAB_ADDRESS_QUOTA_MEM'][$day] = $mem;
        return $mem;
    }
    jeab_address_quota_ensure($pdo);
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    if ($driver === 'mysql') {
        $sql = 'INSERT INTO address_extract_quota (day, lookups, lm_calls) VALUES (?, 1, 0)
            ON DUPLICATE KEY UPDATE lookups = lookups + 1';
    } else {
        $sql = 'INSERT INTO address_extract_quota (day, lookups, lm_calls) VALUES (?, 1, 0)
            ON CONFLICT(day) DO UPDATE SET lookups = lookups + 1';
    }
    $pdo->prepare($sql)->execute([$day]);
    $stmt = $pdo->prepare('SELECT lookups, lm_calls FROM address_extract_quota WHERE day = ?');
    $stmt->execute([$day]);
    $row = $stmt->fetch();
    return [
        'lookups' => (int) ($row['lookups'] ?? 0),
        'lm_calls' => (int) ($row['lm_calls'] ?? 0),
    ];
}

function jeab_address_lm_daily_max(): int
{
    $over = $GLOBALS['_JEAB_ADDRESS_LM_DAILY_MAX'] ?? null;
    if (is_int($over) || (is_string($over) && is_numeric($over))) {
        return max(0, (int) $over);
    }
    return JEAB_ADDRESS_LM_DAILY_MAX;
}

function jeab_address_lm_ratio(): int
{
    $over = $GLOBALS['_JEAB_ADDRESS_LM_RATIO'] ?? null;
    if (is_int($over) || (is_string($over) && is_numeric($over))) {
        return max(1, (int) $over);
    }
    return JEAB_ADDRESS_LM_RATIO;
}

/** True om det här anropet fick använda LM. Kvoten låser när 1 av 10 överskrids, plus dagsgräns. */
function jeab_address_quota_take_lm(): bool
{
    $day = jeab_address_quota_day();
    $max = jeab_address_lm_daily_max();
    $ratio = jeab_address_lm_ratio();
    $pdo = jeab_address_extract_pdo();
    if (!$pdo instanceof PDO) {
        $mem = $GLOBALS['_JEAB_ADDRESS_QUOTA_MEM'][$day] ?? ['lookups' => 0, 'lm_calls' => 0];
        $lookups = (int) ($mem['lookups'] ?? 0);
        $lm = (int) ($mem['lm_calls'] ?? 0);
        if ($lm >= $max || ($lm + 1) * $ratio > $lookups) {
            return false;
        }
        $mem['lm_calls'] = $lm + 1;
        $GLOBALS['_JEAB_ADDRESS_QUOTA_MEM'][$day] = $mem;
        return true;
    }
    jeab_address_quota_ensure($pdo);
    $stmt = $pdo->prepare(
        'UPDATE address_extract_quota
         SET lm_calls = lm_calls + 1
         WHERE day = ? AND lm_calls < ? AND (lm_calls + 1) * ? <= lookups'
    );
    $stmt->execute([$day, $max, $ratio]);
    return $stmt->rowCount() === 1;
}

/** @return array{lookups:int,lm_calls:int} */
function jeab_address_quota_read(): array
{
    $day = jeab_address_quota_day();
    $pdo = jeab_address_extract_pdo();
    if (!$pdo instanceof PDO) {
        $mem = $GLOBALS['_JEAB_ADDRESS_QUOTA_MEM'][$day] ?? ['lookups' => 0, 'lm_calls' => 0];
        return ['lookups' => (int) $mem['lookups'], 'lm_calls' => (int) $mem['lm_calls']];
    }
    jeab_address_quota_ensure($pdo);
    $stmt = $pdo->prepare('SELECT lookups, lm_calls FROM address_extract_quota WHERE day = ?');
    $stmt->execute([$day]);
    $row = $stmt->fetch();
    return [
        'lookups' => (int) ($row['lookups'] ?? 0),
        'lm_calls' => (int) ($row['lm_calls'] ?? 0),
    ];
}

/**
 * Bolag på kontakten. jeab/jarfalla och meab/malmo är samma kontor.
 * Fältet kan heta company, bolag, brand, office eller co.
 *
 * @param array<string, mixed> $row
 * @param array<string, true> $linked
 * @return array<string, true>
 */
function jeab_address_row_offices(array $row, array $linked = []): array
{
    $offices = [];
    foreach (['company', 'bolag', 'brand', 'office', 'co'] as $key) {
        $one = jeab_address_company((string) ($row[$key] ?? ''));
        if ($one !== '') {
            $offices[$one] = true;
        }
    }
    foreach ($linked as $raw => $flag) {
        unset($flag);
        $one = jeab_address_company((string) $raw);
        if ($one !== '') {
            $offices[$one] = true;
        }
    }
    return $offices;
}

/**
 * Kopplade påminnelser, ordrar och bokningar. Saknat bolag blir inte Järfälla.
 *
 * @return array<string, array<string, true>>
 */
function jeab_address_linked_offices(): array
{
    $map = [];
    $add = static function (array &$map, string $id, string $raw): void {
        $id = trim($id);
        $company = jeab_address_company($raw);
        if ($id === '' || $company === '') {
            return;
        }
        $map[$id][$company] = true;
    };
    $pdo = jeab_address_extract_pdo();
    if ($pdo instanceof PDO) {
        foreach (['reminders', 'bookings'] as $table) {
            try {
                $found = $pdo->query('SELECT contact_id, company FROM ' . $table)->fetchAll();
            } catch (Throwable) {
                continue;
            }
            foreach ($found as $row) {
                if (is_array($row)) {
                    $add($map, (string) ($row['contact_id'] ?? ''), (string) ($row['company'] ?? ''));
                }
            }
        }
    }
    if (function_exists('jeab_purchase_all')) {
        foreach (jeab_purchase_all() as $row) {
            if (is_array($row)) {
                $add($map, (string) ($row['contact_id'] ?? ''), (string) ($row['company'] ?? ''));
            }
        }
    }
    return $map;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_address_recheck(array $input): array
{
    $company = jeab_address_company((string) ($input['co'] ?? $input['company'] ?? ''));
    if ($company === '') {
        return ['ok' => false, 'error' => 'ange co', 'status' => 422];
    }
    $dry = true;
    if (array_key_exists('dry', $input)) {
        $value = $input['dry'];
        $dry = !($value === false || $value === 0 || $value === '0' || $value === 0.0);
    }
    $limit = (int) ($input['limit'] ?? 50);
    if ($limit < 1) {
        $limit = 50;
    }
    if ($limit > 500) {
        $limit = 500;
    }
    if (!function_exists('jeab_contact_store')) {
        return ['ok' => false, 'error' => 'Kontakter ligger inte i SQL.', 'status' => 409];
    }
    $store = jeab_contact_store();
    if (!$store instanceof JeabPdoContactStore) {
        return ['ok' => false, 'error' => 'Kontakter ligger inte i SQL.', 'status' => 409];
    }
    $rows = $store->sqlAll();
    $linked = jeab_address_linked_offices();
    $matched = [];
    foreach ($rows as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        $offices = jeab_address_row_offices($row, $linked[(string) $id] ?? []);
        if (!isset($offices[$company])) {
            continue;
        }
        $city = jeab_address_city_label($company, (string) ($row['city'] ?? ''));
        if (jeab_address_city_kind($company, $city) !== 'outside') {
            continue;
        }
        $matched[(string) $id] = $row;
    }
    $by = [];
    $ids = array_slice(array_keys($matched), 0, $limit);
    foreach ($ids as $id) {
        $label = jeab_address_city_label($company, (string) ($matched[$id]['city'] ?? ''));
        $by[$label] = ($by[$label] ?? 0) + 1;
    }
    ksort($by);
    $updated = 0;
    $review = 0;
    if (!$dry) {
        foreach ($ids as $id) {
            $row = $matched[$id];
            $beforeCity = trim((string) ($row['city'] ?? ''));
            $beforePostal = jeab_address_postal_digits((string) ($row['postal_code'] ?? ''));
            $name = '';
            foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
                if (is_array($owner) && trim((string) ($owner['name'] ?? '')) !== '') {
                    $name = trim((string) $owner['name']);
                    break;
                }
            }
            $result = jeab_address_extract([
                'company' => $company,
                'street' => (string) ($row['street'] ?? ''),
                'city' => $beforeCity,
                'postal' => $beforePostal,
                'name' => $name,
                'thread_id' => (string) ($row['thread_id'] ?? ''),
            ]);
            $afterCity = jeab_address_city_label($company, (string) ($result['city'] ?? ''));
            $afterPostal = jeab_address_postal_digits((string) ($result['postal'] ?? ''));
            $rejected = !empty($result['review'])
                || ($afterCity !== '' && jeab_address_city_kind($company, $afterCity) === 'outside')
                || ($afterPostal !== '' && jeab_address_postal_kind($company, $afterPostal) === 'outside');
            if ($rejected) {
                $afterCity = '';
                $afterPostal = '';
                $row['city'] = '';
                $row['postal_code'] = '';
                $row['address_review'] = true;
                $review++;
            } else {
                $row['city'] = $afterCity;
                $row['postal_code'] = $afterPostal;
                unset($row['address_review']);
            }
            $row['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
            $store->upsert($id, $row);
            jeab_address_audit($id, $company, jeab_address_city_label($company, $beforeCity), $beforePostal, $afterCity, $afterPostal, $rejected ? '' : (string) $result['source'], $rejected);
            $updated++;
            jeab_address_recheck_mark_threads($id);
        }
    }
    return [
        'ok' => true,
        'action' => 'contacts.address_recheck',
        'dry' => $dry ? 1 : 0,
        'co' => $company,
        'count' => count($ids),
        'matched' => count($matched),
        'by_ort' => $by,
        'updated' => $dry ? 0 : $updated,
        'review' => $dry ? 0 : $review,
        'limit' => $limit,
        'status' => 200,
    ];
}

function jeab_address_recheck_mark_threads(string $contactId): void
{
    if ($contactId === '' || !function_exists('jeab_store')) {
        return;
    }
    try {
        $app = jeab_store();
        if (!method_exists($app, 'listReminders')) {
            return;
        }
        foreach ($app->listReminders(true) as $reminder) {
            if (!is_array($reminder) || (string) ($reminder['contact_id'] ?? '') !== $contactId) {
                continue;
            }
            $thread = trim((string) ($reminder['thread_id'] ?? ''));
            if ($thread !== '') {
                jeab_address_mark_review($thread);
            }
        }
    } catch (Throwable) {
    }
}

function jeab_address_audit(string $contactId, string $company, string $beforeCity, string $beforePostal, string $afterCity, string $afterPostal, string $source, bool $review): void
{
    $pdo = jeab_address_extract_pdo();
    if (!$pdo instanceof PDO) {
        return;
    }
    jeab_address_quota_ensure($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO address_extract_audit (contact_id, company, before_city, before_postal, after_city, after_postal, source, review, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $contactId,
        $company,
        $beforeCity,
        $beforePostal,
        $afterCity,
        $afterPostal,
        $source,
        $review ? 1 : 0,
        function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s'),
    ]);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_address_recheck_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    return jeab_contacts_address_recheck($input);
}
