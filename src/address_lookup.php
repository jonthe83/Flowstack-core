<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/idnr.php';
/**
 * NEW — internapp include. Do not replace live rot/index.php.
 *
 * Live Order search is LantmaterietAPI in rot/data_scraping.php on the
 * server (same class rot/action_page.php uses). The file is not in git.
 * This include moved from rot/ to src/ on 2026-09-23, so the class is
 * looked up in rot/ first (see jeab_rot_data_scraping_candidates()).
 * Do not add Lantmäteriet/LMV keys or a second HTTP API. Do not deploy
 * git rot/index.php over live Order.
 */

/**
 * Where LantmaterietAPI may live. First existing file wins.
 *
 * @return list<string>
 */
function jeab_rot_data_scraping_candidates(): array
{
    if (isset($GLOBALS['_JEAB_ROT_DATA_SCRAPING_CANDIDATES']) && is_array($GLOBALS['_JEAB_ROT_DATA_SCRAPING_CANDIDATES'])) {
        return array_values(array_map('strval', $GLOBALS['_JEAB_ROT_DATA_SCRAPING_CANDIDATES']));
    }
    $out = [];
    if (defined('JEAB_ROOT')) {
        $out[] = rtrim((string) JEAB_ROOT, '/') . '/rot/data_scraping.php';
    }
    $out[] = dirname(__DIR__) . '/rot/data_scraping.php';
    $out[] = __DIR__ . '/data_scraping.php';
    return array_values(array_unique($out));
}

function jeab_rot_data_scraping_file(): string
{
    foreach (jeab_rot_data_scraping_candidates() as $file) {
        if (is_file($file)) {
            return $file;
        }
    }
    return '';
}

/** Personnummer, långa sifferserier, mejl och telefon tas bort innan loggning. */
function jeab_rot_lookup_mask(string $text): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    $text = preg_replace('/(?<!\d)(?:\d{6}|\d{8})[-+]?\d{4}(?!\d)/', '[pnr]', $text) ?? $text;
    $text = preg_replace('/\d{6,}/', '[siffror]', $text) ?? $text;
    if (function_exists('jeab_log_mask_text')) {
        $text = jeab_log_mask_text($text);
    }
    if (strlen($text) > 160) {
        $text = (function_exists('mb_substr') ? mb_substr($text, 0, 160, 'UTF-8') : substr($text, 0, 160)) . '…';
    }
    return $text;
}

/** Uppslagsfel till fellogg (surface lookup). Aldrig adress eller personnummer i klartext. */
function jeab_rot_lookup_log(string $reason, string $detail = ''): void
{
    if (preg_match('/^fel\b/u', $reason) === 1) {
        $GLOBALS['_JEAB_ROT_LOOKUP_FAILED'] = 'fel';
    } elseif (str_contains($reason, 'saknas') && PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
        $GLOBALS['_JEAB_ROT_LOOKUP_FAILED'] = 'saknas';
    }
    if (!function_exists('jeab_log_app_error')) {
        return;
    }
    $message = 'Lantmäteriet-uppslag: ' . $reason;
    $detail = jeab_rot_lookup_mask($detail);
    if ($detail !== '') {
        $message .= ' (' . $detail . ')';
    }
    try {
        jeab_log_app_error('lookup', $message);
    } catch (Throwable) {
    }
}

function jeab_rot_lookup_empty(): array
{
    return [
        'fastighetsbeteckning' => '',
        'address' => '',
        'owners' => [],
        'postnummer' => '',
        'ort' => '',
    ];
}

function jeab_rot_postnummer_digits(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/\b(\d{3})\s?(\d{2})\b/u', $raw, $m) === 1) {
        return $m[1] . $m[2];
    }
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    return strlen($digits) === 5 ? $digits : '';
}

function jeab_rot_pick_scalar(array $raw, array $keys): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $raw) || is_array($raw[$key])) {
            continue;
        }
        $value = trim((string) $raw[$key]);
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

/**
 * Postnummer och postort från samma uppslag som fastighetsbeteckning.
 * Egna fält i svaret vinner. Saknas de läses de ur den sammansatta adressen.
 *
 * @return array{postnummer:string,ort:string}
 */
function jeab_rot_extract_postort(array $raw, string $composed = ''): array
{
    $nodes = [$raw];
    foreach (['address', 'adress', 'Adress', 'belagenhetsadress', 'belägenhetsadress', 'adressplats'] as $key) {
        if (isset($raw[$key]) && is_array($raw[$key])) {
            $nodes[] = $raw[$key];
        }
    }
    $zip = '';
    $ort = '';
    foreach ($nodes as $node) {
        if ($zip === '') {
            $zip = jeab_rot_postnummer_digits(jeab_rot_pick_scalar($node, [
                'postnummer', 'postnr', 'zip', 'postalCode', 'postal_code', 'ZipCode', 'zipcode',
            ]));
        }
        if ($ort === '') {
            $ort = jeab_rot_pick_scalar($node, ['postort', 'ort', 'city', 'City']);
        }
    }
    $blobs = [];
    foreach (['matchedAddress', 'address', 'adress', 'Adress', 'belagenhetsadress', 'belägenhetsadress'] as $key) {
        if (!isset($raw[$key]) || is_array($raw[$key])) {
            continue;
        }
        $one = trim((string) $raw[$key]);
        if ($one !== '') {
            $blobs[] = $one;
        }
    }
    if ($composed !== '') {
        $blobs[] = $composed;
    }
    foreach ($blobs as $blob) {
        if ($zip !== '' && $ort !== '') {
            break;
        }
        $blob = trim(preg_replace('/\s+/u', ' ', $blob) ?? $blob);
        if (preg_match('/^(.*?)\s*,?\s*(\d{3})\s?(\d{2})\s+(\S.*)$/u', $blob, $m) === 1) {
            if ($zip === '') {
                $zip = $m[2] . $m[3];
            }
            if ($ort === '') {
                $ort = trim($m[4]);
            }
            continue;
        }
        if ($zip === '' && preg_match('/\b(\d{3})\s?(\d{2})\b/u', $blob, $m) === 1) {
            $zip = $m[1] . $m[2];
        }
    }
    return ['postnummer' => $zip, 'ort' => $ort];
}

function jeab_rot_format_pnr(string $pnr): string
{
    return jeab_pnr_normalize($pnr);
}

function jeab_rot_format_andel(mixed $raw): string
{
    if (is_array($raw)) {
        if (array_key_exists('taljare', $raw) || array_key_exists('namnare', $raw) || array_key_exists('täljare', $raw) || array_key_exists('nämnare', $raw)) {
            $num = trim((string) ($raw['taljare'] ?? $raw['täljare'] ?? ''));
            $den = trim((string) ($raw['namnare'] ?? $raw['nämnare'] ?? ''));
            if ($num !== '' && $den !== '') {
                return $num . '/' . $den;
            }
            return $num !== '' ? $num : $den;
        }
        $nested = $raw['andel'] ?? $raw['share'] ?? $raw['Andel'] ?? null;
        if ($nested !== null && $nested !== $raw) {
            return jeab_rot_format_andel($nested);
        }
        return '';
    }
    return trim((string) $raw);
}

function jeab_rot_format_beteckning(string $fast): string
{
    $fast = trim($fast);
    if ($fast === '') {
        return '';
    }
    return function_exists('mb_strtoupper') ? mb_strtoupper($fast, 'UTF-8') : strtoupper($fast);
}

function jeab_rot_format_display_address(string $address): string
{
    $address = trim(preg_replace('/\s+/', ' ', $address) ?? $address);
    if ($address === '' || str_contains($address, ',')) {
        return $address;
    }
    if (preg_match('/^(.+?\s+\d+\w*)\s+(\d{3}\s+\d{2}\s+.+)$/u', $address, $m)) {
        return $m[1] . ', ' . $m[2];
    }
    return $address;
}

function jeab_rot_compose_address(array $raw): string
{
    foreach (['address', 'adress', 'Adress', 'belagenhetsadress', 'belägenhetsadress', 'adressplats'] as $key) {
        if (!array_key_exists($key, $raw)) {
            continue;
        }
        if (is_array($raw[$key])) {
            $nested = jeab_rot_compose_address($raw[$key]);
            if ($nested !== '') {
                return $nested;
            }
            continue;
        }
        $one = trim((string) $raw[$key]);
        if ($one !== '') {
            return jeab_rot_format_display_address($one);
        }
    }
    $street = trim((string) ($raw['gata'] ?? $raw['street'] ?? $raw['adressrad'] ?? $raw['adresslinje'] ?? ''));
    $nr = trim((string) ($raw['nummer'] ?? $raw['houseNumber'] ?? $raw['husnr'] ?? ''));
    $line = trim($street . ($nr !== '' ? ' ' . $nr : ''));
    if (function_exists('jeab_address_repair_number')) {
        $line = jeab_address_repair_number($line);
    }
    $zip = trim((string) ($raw['postnummer'] ?? $raw['postnr'] ?? $raw['zip'] ?? $raw['postalCode'] ?? ''));
    $city = trim((string) ($raw['postort'] ?? $raw['ort'] ?? $raw['city'] ?? $raw['kommun'] ?? ''));
    $tail = trim($zip . ($city !== '' ? ' ' . $city : ''));
    if ($line !== '' && $tail !== '') {
        return $line . ', ' . $tail;
    }
    return jeab_rot_format_display_address($line !== '' ? $line : $tail);
}

function jeab_rot_format_lagfart(mixed $raw): string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $raw, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    if (preg_match('/^(\d{4})(\d{2})(\d{2})/', preg_replace('/\D+/', '', $raw) ?? '', $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    try {
        return (new DateTimeImmutable($raw))->format('Y-m-d');
    } catch (Exception) {
        return $raw;
    }
}

/**
 * Postort som inte är kommun (Bromma är stadsdel i Stockholm).
 * Samma karta som kontakter när den är laddad, annars den här listan.
 *
 * @return array<string, string>
 */
function jeab_rot_postal_town_map(): array
{
    static $map = null;
    if (is_array($map)) {
        return $map;
    }
    $pairs = [
        'Jakobsberg' => 'Järfälla', 'Kallhäll' => 'Järfälla', 'Viksjö' => 'Järfälla', 'Stäket' => 'Järfälla',
        'Bromma' => 'Stockholm', 'Farsta' => 'Stockholm', 'Hägersten' => 'Stockholm', 'Johanneshov' => 'Stockholm',
        'Årsta' => 'Stockholm', 'Enskede' => 'Stockholm', 'Skärholmen' => 'Stockholm', 'Kista' => 'Stockholm',
        'Spånga' => 'Stockholm', 'Vällingby' => 'Stockholm', 'Hässelby' => 'Stockholm', 'Bagarmossen' => 'Stockholm',
        'Bandhagen' => 'Stockholm', 'Älvsjö' => 'Stockholm', 'Sköndal' => 'Stockholm',
        'Tumba' => 'Botkyrka', 'Tullinge' => 'Botkyrka', 'Alby' => 'Botkyrka', 'Fittja' => 'Botkyrka',
        'Norsborg' => 'Botkyrka', 'Hallunda' => 'Botkyrka',
        'Flemingsberg' => 'Huddinge', 'Skogås' => 'Huddinge', 'Trångsund' => 'Huddinge', 'Segeltorp' => 'Huddinge',
        'Handen' => 'Haninge', 'Vendelsö' => 'Haninge', 'Jordbro' => 'Haninge', 'Västerhaninge' => 'Haninge',
        'Saltsjöbaden' => 'Nacka', 'Fisksätra' => 'Nacka', 'Boo' => 'Nacka',
        'Gustavsberg' => 'Värmdö', 'Åkersberga' => 'Österåker',
        'Näsbypark' => 'Täby', 'Viggbyholm' => 'Täby',
        'Djursholm' => 'Danderyd', 'Stocksund' => 'Danderyd', 'Enebyberg' => 'Danderyd',
        'Helenelund' => 'Sollentuna', 'Häggvik' => 'Sollentuna', 'Rotebro' => 'Sollentuna',
        'Märsta' => 'Sigtuna', 'Rosersberg' => 'Sigtuna', 'Steningehöjden' => 'Sigtuna',
        'Rönninge' => 'Salem', 'Pershagen' => 'Södertälje', 'Järna' => 'Södertälje',
        'Limhamn' => 'Malmö', 'Oxie' => 'Malmö', 'Bunkeflostrand' => 'Malmö', 'Klagshamn' => 'Malmö', 'Hyllie' => 'Malmö',
        'Arlöv' => 'Burlöv', 'Åkarp' => 'Burlöv',
    ];
    $map = [];
    foreach ($pairs as $town => $municipality) {
        $key = jeab_rot_place_key($town);
        if ($key !== '') {
            $map[$key] = $municipality;
        }
    }
    if (function_exists('jeab_kontakt_postal_towns')) {
        foreach (jeab_kontakt_postal_towns() as $town => $municipality) {
            $key = jeab_rot_place_key((string) $town);
            $municipality = trim((string) $municipality);
            if ($key !== '' && $municipality !== '') {
                $map[$key] = $municipality;
            }
        }
    }
    return $map;
}

function jeab_rot_place_key(string $place): string
{
    $place = trim(preg_replace('/\s+/u', ' ', $place) ?? $place);
    $place = preg_replace('/\s+kommun$/iu', '', $place) ?? $place;
    $place = trim($place, " ,");
    if ($place === '') {
        return '';
    }
    if (function_exists('jeab_norm_fold')) {
        return jeab_norm_fold($place);
    }
    return function_exists('mb_strtolower') ? mb_strtolower($place, 'UTF-8') : strtolower($place);
}

/** Kommun när postorten är en stadsdel. Tom sträng när orten redan är kommunen. */
function jeab_rot_postal_municipality(string $place): string
{
    $key = jeab_rot_place_key($place);
    if ($key === '') {
        return '';
    }
    $municipality = trim((string) (jeab_rot_postal_town_map()[$key] ?? ''));
    if ($municipality === '' || jeab_rot_place_key($municipality) === $key) {
        return '';
    }
    return $municipality;
}

/**
 * Gata med husnummer, och resten som postort.
 *
 * @return array{street:string,place:string}
 */
function jeab_rot_split_street_place(string $query): array
{
    $query = str_replace([',', ';'], ' ', $query);
    $query = trim(preg_replace('/\s+/u', ' ', $query) ?? $query);
    $tokens = $query === '' ? [] : (preg_split('/\s+/u', $query) ?: []);
    $numAt = -1;
    foreach ($tokens as $i => $token) {
        if (preg_match('/\d/u', (string) $token) === 1) {
            $numAt = (int) $i;
            break;
        }
    }
    if ($numAt < 0) {
        return ['street' => $query, 'place' => ''];
    }
    $end = $numAt;
    $next = (string) ($tokens[$numAt + 1] ?? '');
    if ($next !== '' && preg_match('/^\p{L}$/u', $next) === 1) {
        $end = $numAt + 1;
    }
    return [
        'street' => implode(' ', array_slice($tokens, 0, $end + 1)),
        'place' => implode(' ', array_slice($tokens, $end + 1)),
    ];
}

/** Samma gata och husnummer, med kommun i stället för stadsdel. Tom när det inte behövs. */
function jeab_rot_municipality_query(string $query): string
{
    $parts = jeab_rot_split_street_place($query);
    if ($parts['street'] === '' || $parts['place'] === '') {
        return '';
    }
    $municipality = '';
    $chunks = preg_split('/\s+/u', $parts['place']) ?: [];
    $candidates = array_merge([$parts['place']], $chunks);
    foreach ($candidates as $candidate) {
        $municipality = jeab_rot_postal_municipality((string) $candidate);
        if ($municipality !== '') {
            break;
        }
    }
    if ($municipality === '') {
        return '';
    }
    $alt = jeab_rot_prepare_query($parts['street'] . ' ' . $municipality);
    if ($alt === '' || jeab_rot_place_key($alt) === jeab_rot_place_key($query)) {
        return '';
    }
    return $alt;
}

/**
 * @return list<string>
 */
function jeab_rot_lookup_queries(string $query): array
{
    $out = [];
    $push = static function (string $alt) use (&$out): void {
        $alt = trim($alt);
        if ($alt === '' || in_array($alt, $out, true)) {
            return;
        }
        $out[] = $alt;
    };
    $push($query);
    $parts = jeab_rot_split_street_place($query);
    $street = trim($parts['street'], " ,");
    // Steningehöjden ligger i Märsta. Lantmäteriet träffar huset på den orten,
    // inte på områdesnamnet och inte alltid på «gata kommun» utan komma.
    if ($street !== '' && jeab_rot_place_key($parts['place']) === jeab_rot_place_key('Steningehöjden')) {
        $push($street . ', Märsta');
    }
    $municipality = jeab_rot_municipality_query($query);
    $push($municipality);
    if ($street !== '' && $municipality !== '') {
        $town = '';
        $chunks = preg_split('/\s+/u', $parts['place']) ?: [];
        foreach (array_merge([$parts['place']], $chunks) as $candidate) {
            $town = jeab_rot_postal_municipality((string) $candidate);
            if ($town !== '') {
                break;
            }
        }
        if ($town !== '') {
            $push($street . ', ' . $town);
        }
    }

    return $out;
}

function jeab_rot_lookup_needs_fallback(array $norm): bool
{
    return trim((string) ($norm['fastighetsbeteckning'] ?? '')) === '';
}

/**
 * Två godkända träffar på samma hus. Beteckning och ägare från den tunna
 * träffen lämnas kvar när reservsökningen har det som saknades.
 *
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 * @return array<string, mixed>
 */
function jeab_rot_merge_accepted(array $a, array $b): array
{
    $primary = jeab_rot_lookup_rank($b) >= jeab_rot_lookup_rank($a) ? $b : $a;
    $secondary = $primary === $b ? $a : $b;
    $fastA = trim((string) ($a['fastighetsbeteckning'] ?? ''));
    $fastB = trim((string) ($b['fastighetsbeteckning'] ?? ''));
    $sameFast = $fastA === '' || $fastB === '' || $fastA === $fastB;
    $owners = $sameFast
        ? jeab_rot_dedupe_owners(array_merge(
            is_array($a['owners'] ?? null) ? $a['owners'] : [],
            is_array($b['owners'] ?? null) ? $b['owners'] : []
        ))
        : jeab_rot_dedupe_owners(is_array($primary['owners'] ?? null) ? $primary['owners'] : []);
    if (trim((string) ($primary['fastighetsbeteckning'] ?? '')) === '') {
        $primary['fastighetsbeteckning'] = (string) ($secondary['fastighetsbeteckning'] ?? '');
    }
    $primary['owners'] = $owners;
    foreach (['address', 'postnummer', 'ort', 'fetched_address', 'objektidentitet'] as $key) {
        if (trim((string) ($primary[$key] ?? '')) === '') {
            $primary[$key] = (string) ($secondary[$key] ?? '');
        }
    }
    $primary['lookup_exact'] = true;

    return $primary;
}

function jeab_rot_lookup_rank(array $norm): int
{
    $rank = trim((string) ($norm['fastighetsbeteckning'] ?? '')) !== '' ? 10 : 0;
    foreach (is_array($norm['owners'] ?? null) ? $norm['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        if (trim((string) ($owner['name'] ?? '')) !== '') {
            $rank += 1;
        }
        if (trim((string) ($owner['personnummer'] ?? '')) !== '') {
            $rank += 3;
        }
    }
    return $rank;
}

/** Beteckning från toppfält eller nästlad registerpost. Kastar inte när värdet är en array. */
function jeab_rot_extract_beteckning(array $raw, int $depth = 0): string
{
    if ($depth > 4) {
        return '';
    }
    foreach (['fastighetsbeteckning', 'beteckning', 'propertyDesignation', 'PropertyDesignation', 'registerbeteckning', 'beteckningstext'] as $key) {
        if (!array_key_exists($key, $raw)) {
            continue;
        }
        if (is_array($raw[$key])) {
            $inner = jeab_rot_extract_beteckning($raw[$key], $depth + 1);
            if ($inner !== '') {
                return $inner;
            }
            continue;
        }
        $value = trim((string) $raw[$key]);
        if ($value !== '') {
            return jeab_rot_format_beteckning($value);
        }
    }
    if (isset($raw['fastighet']) && is_string($raw['fastighet'])) {
        $value = trim($raw['fastighet']);
        if ($value !== '') {
            return jeab_rot_format_beteckning($value);
        }
    }
    foreach (['fastighet', 'registerenhet', 'fastighetsreferens', 'registerenhetsreferens', 'property', 'result', 'data'] as $key) {
        if (!isset($raw[$key]) || !is_array($raw[$key]) || $raw[$key] === $raw) {
            continue;
        }
        $inner = jeab_rot_extract_beteckning($raw[$key], $depth + 1);
        if ($inner !== '') {
            return $inner;
        }
    }
    $composed = jeab_rot_compose_beteckning($raw);
    if ($composed !== '') {
        return $composed;
    }
    $features = $raw['features'] ?? null;
    if (is_array($features) && count($features) === 1) {
        $feature = $features[0] ?? null;
        if (is_array($feature)) {
            $props = $feature['properties'] ?? $feature;
            if (is_array($props) && $props !== $raw) {
                return jeab_rot_extract_beteckning($props, $depth + 1);
            }
        }
    }
    return '';
}

/** Beteckning av kommun, trakt och block:enhet när svaret inte har en färdig text. */
function jeab_rot_compose_beteckning(array $raw): string
{
    $trakt = jeab_rot_pick_scalar($raw, ['trakt', 'traktnamn', 'traktNamn']);
    $block = jeab_rot_pick_scalar($raw, ['block', 'blocknummer']);
    $enhet = jeab_rot_pick_scalar($raw, ['enhet', 'enhetsnummer']);
    if ($trakt === '' || ($block === '' && $enhet === '')) {
        return '';
    }
    $unit = $block !== '' && $enhet !== '' ? $block . ':' . $enhet : ($enhet !== '' ? $enhet : $block);
    $name = trim($trakt . ' ' . $unit);
    $kommun = jeab_rot_pick_scalar($raw, ['kommun', 'kommunnamn', 'kommunNamn']);
    if ($kommun !== '' && jeab_rot_place_key($kommun) !== jeab_rot_place_key($trakt)) {
        $name = trim($kommun . ' ' . $name);
    }

    return jeab_rot_format_beteckning($name);
}

function jeab_rot_is_owner_node(array $raw): bool
{
    foreach (['name', 'namn', 'fullName', 'full_name', 'personnummer', 'pnr', 'Personnummer', 'person', 'identitet', 'fornamn', 'förnamn', 'efternamn', 'identitetsnummer'] as $key) {
        if (array_key_exists($key, $raw)) {
            return true;
        }
    }
    return false;
}

/**
 * @return list<mixed>
 */
function jeab_rot_owner_rows(mixed $raw): array
{
    if (!is_array($raw) || $raw === []) {
        return [];
    }
    if (jeab_rot_is_owner_node($raw)) {
        return [$raw];
    }
    $out = [];
    foreach ($raw as $item) {
        if (is_array($item) || (is_string($item) && trim($item) !== '')) {
            $out[] = $item;
        }
    }
    return $out;
}

function jeab_rot_owner_name(array $raw, int $depth = 0): string
{
    if ($depth > 3) {
        return '';
    }
    $name = trim((string) ($raw['name'] ?? $raw['namn'] ?? $raw['fullName'] ?? $raw['full_name'] ?? ''));
    if ($name === '') {
        $first = trim((string) ($raw['fornamn'] ?? $raw['förnamn'] ?? $raw['firstName'] ?? ''));
        $last = trim((string) ($raw['efternamn'] ?? $raw['lastName'] ?? ''));
        $name = trim($first . ' ' . $last);
    }
    if ($name === '') {
        foreach (['person', 'identitet', 'identity'] as $key) {
            if (isset($raw[$key]) && is_array($raw[$key])) {
                $name = jeab_rot_owner_name($raw[$key], $depth + 1);
                if ($name !== '') {
                    break;
                }
            }
        }
    }
    return $name;
}

function jeab_rot_owner_pnr(array $raw, int $depth = 0): string
{
    if ($depth > 3) {
        return '';
    }
    foreach (['personnummer', 'pnr', 'ssn', 'Personnummer', 'identitetsnummer', 'idNummer', 'personId', 'personIdentityNumber', 'fodelsenummer', 'personidentitet', 'personIdentitet', 'identitetsbeteckning', 'idnummer', 'personnr'] as $key) {
        if (!array_key_exists($key, $raw) || is_array($raw[$key])) {
            continue;
        }
        $pnr = jeab_rot_format_pnr(trim((string) $raw[$key]));
        if ($pnr !== '') {
            return $pnr;
        }
    }
    foreach (['person', 'identitet', 'identity', 'fysiskPerson', 'juridiskPerson', 'agare'] as $key) {
        if (isset($raw[$key]) && is_array($raw[$key])) {
            $pnr = jeab_rot_owner_pnr($raw[$key], $depth + 1);
            if ($pnr !== '') {
                return $pnr;
            }
        }
    }
    return '';
}

function jeab_rot_lookup_owner(mixed $raw): ?array
{
    if (!is_array($raw)) {
        $name = trim((string) $raw);
        if ($name === '') {
            return null;
        }
        return ['name' => $name, 'personnummer' => ''];
    }
    $name = jeab_rot_owner_name($raw);
    $pnr = jeab_rot_owner_pnr($raw);
    if ($name === '' && $pnr === '') {
        return null;
    }
    $andel = jeab_rot_format_andel($raw['andel'] ?? $raw['share'] ?? $raw['Andel'] ?? '');
    $lagfart = jeab_rot_format_lagfart(
        $raw['inskrivningsdag']
        ?? $raw['lagfart']
        ?? $raw['lagfart_datum']
        ?? $raw['lagfartdatum']
        ?? $raw['Lagfart datum']
        ?? ''
    );
    return [
        'name' => $name,
        'personnummer' => $pnr,
        'address' => jeab_rot_compose_address($raw),
        'andel' => $andel,
        'lagfart' => $lagfart,
    ];
}

function jeab_rot_is_list(array $raw): bool
{
    if ($raw === []) {
        return false;
    }
    $i = 0;
    foreach (array_keys($raw) as $key) {
        if ($key !== $i) {
            return false;
        }
        $i++;
    }
    return true;
}

function jeab_rot_owner_has_nested_list(array $raw): bool
{
    foreach (['owners', 'agare', 'ägare', 'persons', 'personer', 'lagfarnaAgare', 'lagfartshavare', 'lagfart', 'lagfarter', 'tomtratt', 'tomträtt', 'tomtratter', 'tomträtter', 'innehavare', 'aktagare', 'delagare'] as $key) {
        if (isset($raw[$key]) && is_array($raw[$key])) {
            return true;
        }
    }
    return false;
}

/** @param array<string, mixed> $owner @param array<string, mixed> $parent */
function jeab_rot_owner_inherit(array $owner, array $parent): array
{
    if (trim((string) ($owner['andel'] ?? '')) === '') {
        $owner['andel'] = jeab_rot_format_andel($parent['andel'] ?? $parent['share'] ?? $parent['Andel'] ?? '');
    }
    if (trim((string) ($owner['lagfart'] ?? '')) === '') {
        $owner['lagfart'] = jeab_rot_format_lagfart(
            $parent['inskrivningsdag'] ?? $parent['lagfart'] ?? $parent['lagfart_datum'] ?? $parent['lagfartdatum'] ?? ''
        );
    }
    if (trim((string) ($owner['address'] ?? '')) === '') {
        $owner['address'] = jeab_rot_compose_address($parent);
    }
    $owner['personnummer'] = (string) ($owner['personnummer'] ?? '');
    return $owner;
}

/**
 * @param list<array<string, mixed>> $owners
 * @return list<array<string, mixed>>
 */
function jeab_rot_dedupe_owners(array $owners): array
{
    $out = [];
    $byPnr = [];
    $byName = [];
    foreach ($owners as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $name = trim((string) ($owner['name'] ?? ''));
        $pnr = trim((string) ($owner['personnummer'] ?? ''));
        if ($name === '' && $pnr === '') {
            continue;
        }
        $digits = preg_replace('/\D+/', '', $pnr) ?? '';
        $fold = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        if ($digits !== '' && isset($byPnr[$digits])) {
            $i = $byPnr[$digits];
            if (trim((string) ($out[$i]['name'] ?? '')) === '' && $name !== '') {
                $out[$i]['name'] = $name;
            }
            if (trim((string) ($out[$i]['personnummer'] ?? '')) === '') {
                $out[$i]['personnummer'] = $pnr;
            }
            continue;
        }
        if ($fold !== '' && isset($byName[$fold])) {
            $i = $byName[$fold];
            if (trim((string) ($out[$i]['personnummer'] ?? '')) === '' && $pnr !== '') {
                $out[$i]['personnummer'] = $pnr;
                if ($digits !== '') {
                    $byPnr[$digits] = $i;
                }
            }
            continue;
        }
        $owner['name'] = $name;
        $owner['personnummer'] = $pnr;
        $i = count($out);
        $out[] = $owner;
        if ($digits !== '') {
            $byPnr[$digits] = $i;
        }
        if ($fold !== '') {
            $byName[$fold] = $i;
        }
    }
    return $out;
}

/**
 * Alla ägare: varje lagfart, tomträtt och nästlad ägarlista. Inte bara den första.
 *
 * @return list<array<string, mixed>>
 */
function jeab_rot_collect_owners(array $raw, int $depth = 0, string $rootFast = ''): array
{
    if ($depth > 5) {
        return [];
    }
    if ($depth === 0) {
        $rootFast = jeab_rot_extract_beteckning($raw);
    } elseif ($rootFast !== '') {
        $here = jeab_rot_extract_beteckning($raw);
        if ($here !== '' && $here !== $rootFast) {
            return [];
        }
    }
    $owners = [];
    foreach (['owners', 'agare', 'ägare', 'persons', 'personer', 'lagfarnaAgare', 'lagfartshavare', 'lagfart', 'lagfarter', 'tomtratt', 'tomträtt', 'tomtratter', 'tomträtter', 'innehavare', 'aktagare', 'delagare'] as $key) {
        if (!isset($raw[$key]) || !is_array($raw[$key])) {
            continue;
        }
        $owners = array_merge($owners, jeab_rot_owners_from_container($raw[$key], $raw, $depth, $rootFast));
    }
    foreach (['inskrivning', 'rattigheter', 'rättigheter'] as $key) {
        if (!isset($raw[$key]) || !is_array($raw[$key]) || $raw[$key] === $raw) {
            continue;
        }
        $owners = array_merge($owners, jeab_rot_collect_owners($raw[$key], $depth + 1, $rootFast));
    }
    return $owners;
}

/**
 * @param array<string, mixed> $parent
 * @return list<array<string, mixed>>
 */
function jeab_rot_owners_from_container(array $node, array $parent, int $depth, string $rootFast = ''): array
{
    if ($rootFast !== '') {
        $here = jeab_rot_extract_beteckning($node);
        if ($here !== '' && $here !== $rootFast) {
            return [];
        }
    }
    if (jeab_rot_is_owner_node($node) && !jeab_rot_owner_has_nested_list($node)) {
        $owner = jeab_rot_lookup_owner($node);
        return $owner !== null ? [jeab_rot_owner_inherit($owner, $parent)] : [];
    }
    if (!jeab_rot_is_list($node)) {
        $nested = jeab_rot_collect_owners($node, $depth + 1, $rootFast);
        $out = [];
        foreach ($nested as $owner) {
            $out[] = jeab_rot_owner_inherit($owner, $node);
        }
        if ($out !== []) {
            return $out;
        }
        if (jeab_rot_is_owner_node($node)) {
            $owner = jeab_rot_lookup_owner($node);
            return $owner !== null ? [jeab_rot_owner_inherit($owner, $parent)] : [];
        }
        return [];
    }
    $out = [];
    foreach ($node as $item) {
        if (!is_array($item)) {
            continue;
        }
        $itemFast = $rootFast !== '' ? jeab_rot_extract_beteckning($item) : '';
        if ($rootFast !== '' && $itemFast !== '' && $itemFast !== $rootFast) {
            continue;
        }
        if (jeab_rot_is_owner_node($item) && !jeab_rot_owner_has_nested_list($item)) {
            $owner = jeab_rot_lookup_owner($item);
            if ($owner !== null) {
                $out[] = jeab_rot_owner_inherit($owner, $parent);
            }
            continue;
        }
        $nested = jeab_rot_collect_owners($item, $depth + 1, $rootFast);
        if ($nested === []) {
            $owner = jeab_rot_lookup_owner($item);
            if ($owner !== null) {
                $out[] = jeab_rot_owner_inherit($owner, $item);
            }
            continue;
        }
        foreach ($nested as $owner) {
            $out[] = jeab_rot_owner_inherit($owner, $item);
        }
    }
    return $out;
}

/**
 * Ägare tas bara från den valda beteckningen.
 * En rad utan beteckning räknas bara när den saknar egen adress och objekt-id
 * (lagfart/tomträtt till samma fastighet).
 *
 * @param list<array<string, mixed>> $parts
 */
function jeab_rot_merge_lookup_list(array $parts, string $wantFast = ''): array
{
    $merged = jeab_rot_lookup_empty();
    $chosen = trim($wantFast);
    if ($chosen !== '') {
        $chosen = jeab_rot_format_beteckning($chosen);
    }
    if ($chosen === '') {
        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }
            $fast = jeab_rot_extract_beteckning($part);
            if ($fast !== '') {
                $chosen = $fast;
                break;
            }
        }
    }
    $owners = [];
    foreach ($parts as $part) {
        if (!is_array($part)) {
            continue;
        }
        $one = jeab_rot_normalize_lookup_object($part);
        $fast = trim((string) ($one['fastighetsbeteckning'] ?? ''));
        $same = $chosen === '' || $fast === $chosen;
        $supplement = $fast === ''
            && jeab_rot_candidate_address($part) === ''
            && jeab_rot_candidate_id($part) === '';
        $takeOwners = $chosen === '' || $same || $supplement;
        if ($chosen !== '' && $fast === '' && !$supplement) {
            $takeOwners = false;
        }
        if (!$takeOwners && !($same || $supplement)) {
            continue;
        }
        if ($merged['fastighetsbeteckning'] === '' && $fast !== '' && ($chosen === '' || $fast === $chosen)) {
            $merged['fastighetsbeteckning'] = $fast;
        }
        if ($same || $supplement) {
            if ($merged['address'] === '' && trim((string) $one['address']) !== '') {
                $merged['address'] = $one['address'];
            }
            if ($merged['postnummer'] === '' && trim((string) $one['postnummer']) !== '') {
                $merged['postnummer'] = $one['postnummer'];
            }
            if ($merged['ort'] === '' && trim((string) $one['ort']) !== '') {
                $merged['ort'] = $one['ort'];
            }
        }
        if (!$takeOwners) {
            continue;
        }
        foreach (is_array($one['owners'] ?? null) ? $one['owners'] : [] as $owner) {
            if (is_array($owner)) {
                $owners[] = $owner;
            }
        }
    }
    $merged['owners'] = jeab_rot_dedupe_owners($owners);
    return $merged;
}

function jeab_rot_normalize_lookup(mixed $raw): array
{
    $empty = jeab_rot_lookup_empty();
    if (is_object($raw)) {
        $raw = json_decode(json_encode($raw), true);
    }
    if (!is_array($raw) || $raw === []) {
        return $empty;
    }
    if (jeab_rot_is_list($raw)) {
        if (jeab_rot_list_is_same_property($raw)) {
            return jeab_rot_merge_lookup_list($raw);
        }
        return $empty;
    }
    return jeab_rot_normalize_lookup_object($raw);
}

/** @param array<string, mixed> $raw */
function jeab_rot_normalize_lookup_object(array $raw): array
{
    $fast = jeab_rot_extract_beteckning($raw);
    $owners = jeab_rot_dedupe_owners(jeab_rot_collect_owners($raw));
    $addr = jeab_rot_compose_address($raw);
    if ($addr === '') {
        foreach ($owners as $owner) {
            $addr = trim((string) ($owner['address'] ?? ''));
            if ($addr !== '') {
                break;
            }
        }
    }
    if ($addr !== '') {
        foreach ($owners as $i => $owner) {
            if (trim((string) ($owner['address'] ?? '')) === '') {
                $owners[$i]['address'] = $addr;
            }
        }
    }
    if ($fast === '' && $owners === []) {
        $nested = $raw['result'] ?? $raw['data'] ?? $raw['property'] ?? null;
        if (is_array($nested) && $nested !== $raw) {
            return jeab_rot_normalize_lookup($nested);
        }
    }
    $post = jeab_rot_extract_postort($raw, $addr);
    return [
        'fastighetsbeteckning' => $fast,
        'address' => $addr,
        'owners' => $owners,
        'postnummer' => $post['postnummer'],
        'ort' => $post['ort'],
    ];
}

function jeab_rot_prepare_query(string $query): string
{
    if (function_exists('jeab_ordrar_lookup_free_text')) {
        return jeab_ordrar_lookup_free_text($query);
    }
    $query = trim(preg_replace('/\s*,\s*/u', ' ', $query) ?? $query);
    return trim(preg_replace('/\s+/u', ' ', $query) ?? $query);
}

function jeab_rot_looks_like_raw_suggestion(string $text): bool
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if ($text === '' || str_contains($text, ',') || strlen($text) > 160) {
        return false;
    }
    $tokens = preg_split('/\s+/u', $text) ?: [];
    $hasDupCity = count($tokens) >= 3
        && (function_exists('mb_strtolower')
            ? mb_strtolower((string) $tokens[0], 'UTF-8') === mb_strtolower((string) $tokens[1], 'UTF-8')
            : strcasecmp((string) $tokens[0], (string) $tokens[1]) === 0);
    $hasStreet = (bool) preg_match('/(vägen|gatan|väg|gränd|torg|plan|stig|allén|allé|backe|leden)\s+\d/iu', $text);
    return $hasDupCity && $hasStreet;
}

function jeab_rot_lookup_query(string $query): string
{
    $query = jeab_rot_prepare_query($query);
    if ($query === '' || jeab_rot_looks_like_raw_suggestion($query)) {
        return $query;
    }
    if (function_exists('jeab_reminder_strip_postnummer')) {
        $query = jeab_reminder_strip_postnummer($query);
    }
    return jeab_rot_prepare_query($query);
}

function jeab_rot_title_case_sv(string $text): string
{
    return preg_replace_callback('/[A-ZÅÄÖa-zåäö]+/u', static function (array $m): string {
        $word = $m[0];
        if (preg_match('/^\d/u', $word)) {
            return $word;
        }
        if (function_exists('mb_substr') && function_exists('mb_strtoupper') && function_exists('mb_strtolower')) {
            return mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8')
                . mb_strtolower(mb_substr($word, 1, null, 'UTF-8'), 'UTF-8');
        }
        return strtoupper($word[0] ?? '') . strtolower(substr($word, 1));
    }, $text) ?? $text;
}

function jeab_rot_format_suggestion_label(string $raw): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
    if ($text === '') {
        return '';
    }
    $tokens = preg_split('/\s+/u', $text) ?: [];
    $dedup = [];
    foreach ($tokens as $token) {
        $prev = $dedup !== [] ? (string) $dedup[array_key_last($dedup)] : '';
        $same = function_exists('mb_strtolower')
            ? mb_strtolower($prev, 'UTF-8') === mb_strtolower($token, 'UTF-8')
            : strcasecmp($prev, $token) === 0;
        if ($dedup === [] || !$same) {
            $dedup[] = $token;
        }
    }
    $text = implode(' ', $dedup);
    $postnr = '';
    if (preg_match('/\b(\d{3})\s?(\d{2})\b/u', $text, $post)) {
        $postnr = $post[1] . ' ' . $post[2];
        $text = trim((string) preg_replace('/' . preg_quote($post[0], '/') . '/u', ' ', $text));
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
    }
    $street = '';
    if (preg_match('/([A-ZÅÄÖa-zåäö][\wåäö.-]*(?:vägen|gatan|väg|gränd|torg|plan|stig|allén|allé|backe|leden)\s+\d+[A-Za-z]?)/iu', $text, $m)) {
        $street = trim((string) preg_replace('/\s+/u', ' ', $m[1]));
        $text = trim((string) preg_replace('/' . preg_quote($m[1], '/') . '/u', ' ', $text));
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
    }
    $text = trim((string) preg_replace('/[,\s]+/u', ' ', $text));
    $cityParts = [];
    $seenCity = [];
    foreach (preg_split('/\s+/u', $text) ?: [] as $word) {
        if ($word === '') {
            continue;
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($word, 'UTF-8') : strtolower($word);
        if (isset($seenCity[$key])) {
            continue;
        }
        $seenCity[$key] = true;
        $cityParts[] = $word;
    }
    $city = jeab_rot_title_case_sv(implode(' ', $cityParts));
    $line = jeab_rot_title_case_sv($street);
    $tail = trim($postnr . ($city !== '' ? ' ' . $city : ''));
    if ($line !== '' && $tail !== '') {
        return $line . ', ' . $tail;
    }
    return $line !== '' ? $line : jeab_rot_format_display_address($raw);
}

function jeab_rot_extract_suggestions(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $rows = $raw['addressSuggestions'] ?? $raw['suggestions'] ?? $raw['adresser'] ?? null;
    $out = [];
    $seen = [];
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $rawAddr = '';
            $id = '';
            if (is_string($row)) {
                $rawAddr = trim($row);
            } elseif (is_array($row)) {
                $rawAddr = trim((string) ($row['adress'] ?? $row['address'] ?? $row['matchedAddress'] ?? ''));
                $id = trim((string) ($row['objektidentitet'] ?? $row['uuid'] ?? $row['id'] ?? ''));
            }
            if ($rawAddr === '') {
                continue;
            }
            $key = function_exists('mb_strtolower') ? mb_strtolower($rawAddr, 'UTF-8') : strtolower($rawAddr);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'raw' => $rawAddr,
                'label' => jeab_rot_format_suggestion_label($rawAddr),
                'id' => $id,
            ];
        }
    }
    if ($out === []) {
        $matched = trim((string) ($raw['matchedAddress'] ?? $raw['address'] ?? ''));
        if ($matched !== '') {
            $out[] = [
                'raw' => $matched,
                'label' => jeab_rot_format_suggestion_label($matched),
                'id' => trim((string) ($raw['uuid'] ?? '')),
            ];
        }
    }
    return $out;
}

function jeab_rot_http_status(array $headers): int
{
    $status = 0;
    foreach ($headers as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $m) === 1) {
            $status = (int) $m[1];
        }
    }
    return $status;
}

function jeab_rot_external_blocked(): bool
{
    if (!empty($GLOBALS['_JEAB_BEKRAFTA_LOOKUP'])) {
        return false;
    }

    return function_exists('jeab_page_blocks_external') && jeab_page_blocks_external();
}

function jeab_rot_kalkyl_address_search(string $address, bool $log = true): ?array
{
    if (jeab_rot_external_blocked()) {
        return null;
    }
    if (function_exists('jeab_kontakt_quiet') && jeab_kontakt_quiet()) {
        jeab_kontakt_side_effect_note('rot');
        return null;
    }
    $address = trim($address);
    if ($address === '') {
        return null;
    }
    if (function_exists('jeab_pdo_release_if_idle')) {
        jeab_pdo_release_if_idle();
    }
    $url = 'https://kalkyl.jarfalla.example.test/rot/action_page.php?address=' . rawurlencode($address);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 15,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\nUser-Agent: JEAB-Order\r\n",
        ],
    ]);
    $http_response_header = [];
    if (function_exists('jeab_demo_outbound') && jeab_demo_outbound('address', $url)) {
        return null;
    }
    $json = @file_get_contents($url, false, $ctx);
    $status = jeab_rot_http_status(is_array($http_response_header) ? $http_response_header : []);
    if (!$log) {
        $data = is_string($json) && $json !== '' ? json_decode($json, true) : null;
        return is_array($data) ? $data : null;
    }
    if (!is_string($json) || $json === '') {
        jeab_rot_lookup_log('reservanropet svarade inte', 'HTTP ' . ($status > 0 ? (string) $status : 'saknas'));
        return null;
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        jeab_rot_lookup_log('reservanropet gav inte JSON', 'HTTP ' . ($status > 0 ? (string) $status : 'saknas'));
        return null;
    }
    if ($status >= 400) {
        jeab_rot_lookup_log('reservanropet gav fel', 'HTTP ' . (string) $status);
    }
    $err = is_string($data['error'] ?? null) ? trim((string) $data['error']) : '';
    if ($err !== '' && $err !== 'not_found') {
        jeab_rot_lookup_log('reservanropet gav fel', $err);
    }
    return $data;
}

/**
 * null = klassen saknas eller kastade fel (reservanrop får försöka).
 * [] = klassen körde men hittade ingen fastighet.
 */
function jeab_rot_class_address_search(string $address, string $objektId = ''): ?array
{
    if (jeab_rot_external_blocked()) {
        if (function_exists('jeab_external_enqueue') && trim($address) !== '') {
            jeab_external_enqueue('lantmateriet', ['address' => $address]);
        }
        return null;
    }
    if (function_exists('jeab_kontakt_quiet') && jeab_kontakt_quiet()) {
        jeab_kontakt_side_effect_note('rot');
        return null;
    }
    $file = jeab_rot_data_scraping_file();
    if ($file === '' && !class_exists('LantmaterietAPI', false)) {
        jeab_rot_lookup_log('LantmaterietAPI saknas', 'rot/data_scraping.php hittades inte');
        return null;
    }
    if ($file !== '' && !class_exists('LantmaterietAPI', false)) {
        require_once $file;
    }
    if (!class_exists('LantmaterietAPI', false)) {
        jeab_rot_lookup_log('LantmaterietAPI saknas', 'klassen finns inte i data_scraping.php');
        return null;
    }
    $done = (int) ($GLOBALS['_JEAB_LM_SEARCH_CALLS'] ?? 0);
    $budget = $GLOBALS['_JEAB_LM_CALL_BUDGET'] ?? null;
    if ($budget !== null && $done >= (int) $budget) {
        return [];
    }
    $GLOBALS['_JEAB_LM_SEARCH_CALLS'] = $done + 1;
    if (function_exists('jeab_pdo_release_if_idle')) {
        jeab_pdo_release_if_idle();
    }
    $api = new LantmaterietAPI();
    $found = false;
    try {
        $objektId = trim($objektId);
        $method = new ReflectionMethod($api, 'search');
        if ($method->getNumberOfParameters() >= 2 && $objektId !== '') {
            $found = $api->search($address, $objektId);
        } elseif ($method->getNumberOfRequiredParameters() >= 2) {
            $found = $api->search($address, bin2hex(random_bytes(8)));
        } else {
            $found = $api->search($address);
        }
    } catch (Throwable $e) {
        jeab_rot_lookup_log('fel från Lantmäteriet', get_class($e) . ': ' . $e->getMessage());
        return null;
    }
    if (is_array($found)) {
        return $found;
    }
    $data = $api->data ?? null;
    if (is_object($data)) {
        $data = json_decode(json_encode($data), true);
    }
    if (!$found) {
        // Fastighet utan privatpersoner som ägare (t.ex. brf) ger false men har beteckning.
        if (is_array($data) && jeab_rot_extract_beteckning($data) !== '') {
            return $data;
        }
        // Klassen körde utan fel men hittade inget: tom träff, inget reservanrop.
        return [];
    }
    return is_array($data) ? $data : null;
}

/**
 * Same class and search live Order already uses.
 * Sibling data_scraping.php exists next to this file on the server.
 * If the class file is missing (internapp without the kalkyl sibling),
 * use the same action_page.php endpoint the live search already exposes.
 */
function jeab_rot_live_address_search(string $address, string $objektId = '')
{
    if (function_exists('jeab_kontakt_quiet') && jeab_kontakt_quiet()) {
        jeab_kontakt_side_effect_note('rot');
        return null;
    }
    $address = trim($address);
    $objektId = trim($objektId);
    if ($address === '') {
        return null;
    }
    try {
        $found = jeab_rot_class_address_search($address, $objektId);
    } catch (Throwable) {
        $found = null;
    }
    if (is_array($found) || $objektId !== '') {
        return $found;
    }
    try {
        return jeab_rot_kalkyl_address_search($address);
    } catch (Throwable) {
        return null;
    }
}

function jeab_rot_suggest_addresses(string $query): array
{
    $query = jeab_rot_prepare_query($query);
    $len = function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') : strlen($query);
    if ($query === '' || $len < 3 || preg_match('/\d+\s*:\s*\d+/', $query)) {
        return [];
    }
    try {
        $raw = jeab_rot_live_address_search($query);
    } catch (Throwable) {
        $raw = null;
    }
    $out = jeab_rot_extract_suggestions($raw);
    if (count($out) > 1) {
        return $out;
    }
    try {
        $kalkyl = jeab_rot_kalkyl_address_search($query, false);
    } catch (Throwable) {
        $kalkyl = null;
    }
    $fromKalkyl = jeab_rot_extract_suggestions($kalkyl);
    return count($fromKalkyl) > count($out) ? $fromKalkyl : $out;
}

function jeab_rot_fold(string $text): string
{
    if (function_exists('jeab_norm_fold')) {
        return jeab_norm_fold($text);
    }
    $text = trim($text);
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

/**
 * Husnummer är hela siffergruppen. «6» är inte «66», «16» eller «6A».
 *
 * @return array{words:list<string>,street:string,number:string,letter:string,postal:string}
 */
function jeab_rot_address_identity(string $text): array
{
    $empty = ['words' => [], 'street' => '', 'number' => '', 'letter' => '', 'postal' => ''];
    $postal = jeab_rot_postnummer_digits($text);
    $stripped = $text;
    if ($postal !== '') {
        $stripped = preg_replace(
            '/\b' . substr($postal, 0, 3) . '\s?' . substr($postal, 3) . '\b/u',
            ' ',
            $stripped
        ) ?? $stripped;
    }
    $stripped = trim(preg_replace('/\s+/u', ' ', str_replace([',', ';'], ' ', $stripped)) ?? $stripped);
    if ($stripped === '') {
        $empty['postal'] = $postal;
        return $empty;
    }
    $parts = jeab_rot_split_street_place($stripped);
    $line = trim($parts['street'] !== '' ? $parts['street'] : $stripped);
    if (preg_match('/^(.*\D)?(\d+)\s*(\p{L})?$/u', $line, $m) !== 1) {
        $empty['postal'] = $postal;
        return $empty;
    }
    $number = ltrim((string) $m[2], '0');
    if ($number === '') {
        $number = '0';
    }
    $words = [];
    foreach (preg_split('/\s+/u', str_replace(['-', '–'], ' ', trim((string) ($m[1] ?? '')))) ?: [] as $word) {
        $word = preg_replace('/[^\p{L}\p{N}]+/u', '', jeab_rot_fold((string) $word)) ?? '';
        if ($word !== '') {
            $words[] = $word;
        }
    }
    return [
        'words' => $words,
        'street' => implode(' ', $words),
        'number' => $number,
        'letter' => jeab_rot_fold(trim((string) ($m[3] ?? ''))),
        'postal' => $postal,
    ];
}

/** @param list<string> $want @param list<string> $have */
function jeab_rot_street_words_match(array $want, array $have): bool
{
    if ($want === [] || $have === []) {
        return false;
    }
    if ($want === $have) {
        return true;
    }
    $wantCount = count($want);
    $haveCount = count($have);
    if ($haveCount >= $wantCount) {
        return array_slice($have, -$wantCount) === $want;
    }
    return array_slice($want, -$haveCount) === $have;
}

/** Samma gata, hela husnumret, samma bokstav, och samma postnummer när båda har ett. */
function jeab_rot_address_exact(string $want, string $have, bool $postalOptional = false): bool
{
    $left = jeab_rot_address_identity($want);
    $right = jeab_rot_address_identity($have);
    if ($left['number'] === '' || $right['number'] === '' || $left['words'] === [] || $right['words'] === []) {
        return false;
    }
    if ($left['number'] !== $right['number'] || $left['letter'] !== $right['letter']) {
        return false;
    }
    if (!jeab_rot_street_words_match($left['words'], $right['words'])) {
        return false;
    }
    if (!$postalOptional && $left['postal'] !== '' && $right['postal'] === '') {
        return false;
    }
    if ($left['postal'] !== '' && $right['postal'] !== '' && $left['postal'] !== $right['postal']) {
        return false;
    }
    return true;
}

/** Cache-nyckel med hela husnumret. «6» och «66» delar inte nyckel. */
function jeab_rot_lm_cache_key(string $address, string $objektId = ''): string
{
    $id = jeab_rot_address_identity($address);
    $objektId = trim($objektId);
    if ($id['street'] === '' || $id['number'] === '') {
        return 'raw:' . jeab_rot_fold($address) . '|' . $objektId;
    }
    return $id['street'] . '|' . $id['number'] . '|' . $id['letter'] . '|' . $id['postal'] . '|' . $objektId;
}

function jeab_rot_candidate_id(array $row): string
{
    foreach (['objektidentitet', 'uuid', 'objektId', 'id'] as $key) {
        if (!array_key_exists($key, $row) || is_array($row[$key])) {
            continue;
        }
        $value = trim((string) $row[$key]);
        if ($value === '' || preg_match('/^(?:\d{6}|\d{8})[-+]?\d{4}$/', $value) === 1) {
            continue;
        }
        return $value;
    }
    return '';
}

/** @param array<string, mixed> $row */
function jeab_rot_property_copy(array $row): array
{
    unset(
        $row['addressSuggestions'],
        $row['suggestions'],
        $row['adresser'],
        $row['features'],
        $row['owners'],
        $row['agare'],
        $row['ägare'],
        $row['lagfart'],
        $row['tomtratt'],
        $row['tomträtt']
    );
    return $row;
}

/**
 * Varje adressrad på fastigheten. Ägarnas hemadresser räknas inte.
 *
 * @param array<string, mixed> $row
 * @return list<string>
 */
function jeab_rot_property_lines(array $row): array
{
    $copy = jeab_rot_property_copy($row);
    $lines = [];
    $add = static function (string $line) use (&$lines): void {
        $line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);
        if ($line !== '' && !in_array($line, $lines, true)) {
            $lines[] = $line;
        }
    };
    $pull = static function (array $node) use (&$pull, $add): void {
        foreach (['matchedAddress', 'adress', 'address', 'Adress', 'belagenhetsadress', 'belägenhetsadress', 'adressplats'] as $key) {
            if (!isset($node[$key])) {
                continue;
            }
            if (is_array($node[$key])) {
                $nested = jeab_rot_compose_address($node[$key]);
                if ($nested !== '') {
                    $add($nested);
                }
                $pull($node[$key]);
                continue;
            }
            $add((string) $node[$key]);
        }
    };
    $pull($copy);
    $street = trim((string) ($copy['gata'] ?? $copy['street'] ?? $copy['adressrad'] ?? $copy['adresslinje'] ?? ''));
    $nr = trim((string) ($copy['nummer'] ?? $copy['houseNumber'] ?? $copy['husnr'] ?? $copy['adressplatsnummer'] ?? ''));
    $letter = trim((string) ($copy['bokstav'] ?? $copy['husnummerbokstav'] ?? $copy['letter'] ?? ''));
    if ($nr !== '' || $letter !== '') {
        $add(trim($street . ' ' . $nr . $letter));
    }
    $composed = jeab_rot_compose_address($copy);
    if ($composed !== '') {
        $add($composed);
    }
    return $lines;
}

/**
 * Husnummer, bokstav och postnummer från fastighetens egna fält.
 * Ett fält som säger 6 och ett som säger 66 blir två identiteter.
 *
 * @param array<string, mixed> $row
 * @return list<array{words:list<string>,street:string,number:string,letter:string,postal:string}>
 */
function jeab_rot_property_identities(array $row): array
{
    $copy = jeab_rot_property_copy($row);
    $postal = jeab_rot_postnummer_digits(jeab_rot_pick_scalar($copy, [
        'postnummer', 'postnr', 'zip', 'postalCode', 'postal_code',
    ]));
    $out = [];
    foreach (jeab_rot_property_lines($row) as $line) {
        $id = jeab_rot_address_identity($line);
        if ($id['number'] === '') {
            continue;
        }
        if ($id['postal'] !== '' && $postal !== '' && $id['postal'] !== $postal) {
            $alt = $id;
            $alt['postal'] = $postal;
            $out[] = $id;
            $out[] = $alt;
            continue;
        }
        if ($id['postal'] === '' && $postal !== '') {
            $id['postal'] = $postal;
        }
        $out[] = $id;
    }
    return $out;
}

/**
 * Samma gata, och varje husnummer och bokstav på raden är exakt frågan.
 * Postnummer ska stämma när frågan har ett och svaret har ett. Närmaste träff tas inte.
 *
 * @param array<string, mixed> $row
 */
function jeab_rot_row_address_exact(string $query, array $row, bool $postalOptional = false): bool
{
    $want = jeab_rot_address_identity($query);
    if ($want['number'] === '' || $want['words'] === []) {
        return false;
    }
    $idents = jeab_rot_property_identities($row);
    if ($idents === []) {
        return false;
    }
    $streetOk = false;
    $sawPostal = false;
    foreach ($idents as $id) {
        if ($id['number'] !== $want['number'] || $id['letter'] !== $want['letter']) {
            return false;
        }
        if ($id['words'] !== []) {
            if (!jeab_rot_street_words_match($want['words'], $id['words'])) {
                return false;
            }
            $streetOk = true;
        }
        if ($id['postal'] !== '') {
            $sawPostal = true;
            if ($want['postal'] !== '' && $id['postal'] !== $want['postal']) {
                return false;
            }
        }
    }
    if (!$streetOk) {
        return false;
    }
    if (!$postalOptional && $want['postal'] !== '' && !$sawPostal) {
        return false;
    }
    return true;
}

function jeab_rot_candidate_address(array $row): string
{
    $copy = jeab_rot_property_copy($row);
    $lines = jeab_rot_property_lines($copy);
    $best = '';
    $bestScore = null;
    $bestId = null;
    $streetText = '';
    foreach ($lines as $line) {
        $id = jeab_rot_address_identity($line);
        if ($streetText === '' && $id['words'] !== []) {
            if (preg_match('/^(.*\D)\d+\s*\p{L}?$/u', trim(str_replace([',', ';'], ' ', preg_replace('/\b\d{3}\s?\d{2}\b/u', ' ', $line) ?? $line)), $m) === 1) {
                $streetText = trim((string) $m[1]);
            }
        }
        if ($id['number'] === '') {
            continue;
        }
        $score = [strlen($id['number']), $id['letter'] !== '' ? 1 : 0, $id['words'] !== [] ? 1 : 0, strlen($line)];
        if ($bestScore === null || $score > $bestScore) {
            $best = $line;
            $bestScore = $score;
            $bestId = $id;
        }
    }
    if (is_array($bestId) && $bestId['words'] === [] && $streetText !== '') {
        $best = trim($streetText . ' ' . $bestId['number'] . $bestId['letter']);
    }
    if ($best === '') {
        $best = jeab_rot_compose_address($copy);
    }
    $addr = jeab_rot_format_display_address($best);
    $post = jeab_rot_extract_postort($copy, $addr);
    if ($post['postnummer'] !== '' && jeab_rot_postnummer_digits($addr) === '') {
        $pretty = substr($post['postnummer'], 0, 3) . ' ' . substr($post['postnummer'], 3);
        $addr = trim($addr . ' ' . $pretty . ($post['ort'] !== '' ? ' ' . $post['ort'] : ''));
    }
    return trim($addr);
}

/**
 * Samma fastighet när listan inte har mer än en adress, en beteckning och ett objekt-id.
 * Tomma fält räknas inte som en ny identitet (lagfart/tomträtt utan egen adress).
 *
 * @param list<mixed> $raw
 */
function jeab_rot_list_is_same_property(array $raw): bool
{
    $addresses = [];
    $names = [];
    $ids = [];
    foreach ($raw as $item) {
        if (!is_array($item)) {
            continue;
        }
        $addr = jeab_rot_candidate_address($item);
        if ($addr !== '') {
            $id = jeab_rot_address_identity($addr);
            if ($id['street'] !== '' && $id['number'] !== '') {
                $key = $id['street'] . '|' . $id['number'] . '|' . $id['letter'] . '|' . $id['postal'];
            } else {
                $key = 'raw:' . jeab_rot_fold($addr);
            }
            $addresses[$key] = true;
        }
        $fast = jeab_rot_extract_beteckning($item);
        if ($fast !== '') {
            $names[$fast] = true;
        }
        $oid = jeab_rot_candidate_id($item);
        if ($oid !== '') {
            $ids[$oid] = true;
        }
    }
    return count($addresses) <= 1 && count($names) <= 1 && count($ids) <= 1;
}

function jeab_rot_list_has_several_addresses(array $raw): bool
{
    $count = 0;
    foreach ($raw as $item) {
        if (!is_array($item)) {
            continue;
        }
        if (jeab_rot_candidate_address($item) !== '' || jeab_rot_extract_beteckning($item) !== '') {
            $count++;
        }
        if ($count > 1) {
            return true;
        }
    }
    return false;
}

/** @return list<array<string, mixed>> */
function jeab_rot_lookup_rows(mixed $raw): array
{
    if (!is_array($raw) || $raw === []) {
        return [];
    }
    if (isset($raw['features']) && is_array($raw['features']) && array_is_list($raw['features']) && count($raw['features']) > 1) {
        $rows = [];
        foreach ($raw['features'] as $feature) {
            if (!is_array($feature)) {
                continue;
            }
            $props = $feature['properties'] ?? $feature;
            if (is_array($props)) {
                $rows[] = $props;
            }
        }
        return $rows;
    }
    if (array_is_list($raw)) {
        $rows = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $rows[] = $item;
            }
        }
        return $rows;
    }
    $rows = [];
    $suggestions = $raw['addressSuggestions'] ?? $raw['suggestions'] ?? $raw['adresser'] ?? null;
    if (is_array($suggestions)) {
        foreach ($suggestions as $item) {
            if (is_string($item) && trim($item) !== '') {
                $rows[] = ['address' => trim($item)];
            } elseif (is_array($item)) {
                $rows[] = $item;
            }
        }
    }
    $self = $raw;
    unset($self['addressSuggestions'], $self['suggestions'], $self['adresser'], $self['features']);
    if ($rows === [] || jeab_rot_candidate_address($self) !== '' || jeab_rot_extract_beteckning($self) !== '') {
        $rows[] = $self;
    }
    return $rows;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array{accepted:bool,had_rows:bool,norm:array,fetched_address:string,choices:list<array{id:string,address:string}>}
 */
function jeab_rot_pick_lookup(array $rows, string $query, string $wantId, string $wantFast = ''): array
{
    $wantId = trim($wantId);
    $prepared = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $copy = $row;
        unset($copy['addressSuggestions'], $copy['suggestions'], $copy['adresser'], $copy['features']);
        $address = jeab_rot_candidate_address($copy);
        $norm = jeab_rot_normalize_lookup($copy);
        if ($address === '') {
            $address = trim((string) ($norm['address'] ?? ''));
        }
        $prepared[] = [
            'id' => jeab_rot_candidate_id($copy),
            'address' => $address,
            'norm' => $norm,
            'raw' => $copy,
        ];
    }
    $choices = [];
    $seen = [];
    foreach ($prepared as $item) {
        $label = jeab_rot_format_display_address($item['address']);
        if ($label === '') {
            continue;
        }
        $key = jeab_rot_fold($label) . '|' . $item['id'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $choices[] = ['id' => $item['id'], 'address' => $label];
    }
    $fetched = $choices[0]['address'] ?? '';
    $pool = $prepared;
    if ($wantId !== '') {
        $byId = [];
        foreach ($prepared as $item) {
            if ($item['id'] !== '' && $item['id'] === $wantId) {
                $byId[] = $item;
            }
        }
        if ($byId !== []) {
            $pool = $byId;
            $fetched = jeab_rot_format_display_address($byId[0]['address']);
        }
    }
    $exact = [];
    $relaxPostal = jeab_rot_format_beteckning($wantFast) !== '';
    foreach ($pool as $item) {
        $idHit = $wantId !== '' && $item['id'] !== '' && $item['id'] === $wantId;
        $raw = is_array($item['raw'] ?? null) ? $item['raw'] : [];
        if ($idHit && $item['address'] === '' && jeab_rot_property_identities($raw) === []) {
            $exact[] = $item;
            continue;
        }
        if (jeab_rot_row_address_exact($query, $raw, $idHit || $relaxPostal)) {
            $exact[] = $item;
        }
    }
    $groups = [];
    foreach ($exact as $item) {
        $fast = trim((string) ($item['norm']['fastighetsbeteckning'] ?? ''));
        $groups[$fast !== '' ? $fast : '_'][] = $item;
    }
    $named = array_values(array_filter(array_keys($groups), static fn(string $key): bool => $key !== '_'));
    if (count($named) > 1) {
        $want = jeab_rot_format_beteckning($wantFast);
        $keep = '';
        if ($want !== '') {
            foreach ($named as $name) {
                if (jeab_rot_format_beteckning($name) === $want) {
                    $keep = $name;
                    break;
                }
            }
        }
        if ($keep !== '') {
            $exact = is_array($groups[$keep] ?? null) ? $groups[$keep] : [];
            $named = [$keep];
            $groups = [$keep => $exact];
        } else {
            $properties = [];
            foreach ($named as $fast) {
                $parts = [];
                foreach ($groups[$fast] as $item) {
                    if (is_array($item['raw'] ?? null)) {
                        $parts[] = $item['raw'];
                    }
                }
                $norm = $parts === [] ? jeab_rot_lookup_empty() : jeab_rot_merge_lookup_list($parts, $fast);
                if (trim((string) ($norm['fastighetsbeteckning'] ?? '')) === '') {
                    $norm['fastighetsbeteckning'] = $fast;
                }
                $properties[] = $norm;
            }
            return [
                'accepted' => false,
                'had_rows' => $prepared !== [],
                'norm' => jeab_rot_lookup_empty(),
                'fetched_address' => $fetched,
                'choices' => $choices,
                'properties' => $properties,
            ];
        }
    }
    if ($exact === [] && count($prepared) === 1) {
        $only = $prepared[0];
        $hasFast = trim((string) ($only['norm']['fastighetsbeteckning'] ?? '')) !== '';
        $hasOwners = is_array($only['norm']['owners'] ?? null) && $only['norm']['owners'] !== [];
        if ($only['address'] === '' && ($hasFast || $hasOwners) && jeab_rot_property_identities(is_array($only['raw'] ?? null) ? $only['raw'] : []) === []) {
            $exact[] = $only;
        }
    }
    if ($exact === []) {
        return [
            'accepted' => false,
            'had_rows' => $prepared !== [],
            'norm' => jeab_rot_lookup_empty(),
            'fetched_address' => $fetched,
            'choices' => $choices,
        ];
    }
    $best = $exact[0];
    $bestRank = -1;
    foreach ($exact as $item) {
        $rank = jeab_rot_lookup_rank($item['norm']);
        if ($rank > $bestRank) {
            $best = $item;
            $bestRank = $rank;
        }
    }
    $anchorFast = $named[0] ?? '';
    $anchorIds = [];
    $exactAt = [];
    foreach ($prepared as $i => $item) {
        foreach ($exact as $ex) {
            if ($ex['id'] !== $item['id'] || $ex['address'] !== $item['address'] || $ex['norm'] !== $item['norm']) {
                continue;
            }
            $exactAt[$i] = true;
            if ($item['id'] !== '') {
                $anchorIds[$item['id']] = true;
            }
            break;
        }
    }
    $partIndexes = array_keys($exactAt);
    foreach ($prepared as $i => $item) {
        if (isset($exactAt[$i])) {
            continue;
        }
        $fast = trim((string) ($item['norm']['fastighetsbeteckning'] ?? ''));
        if ($anchorFast !== '' && $fast !== '' && $fast === $anchorFast) {
            $partIndexes[] = $i;
            continue;
        }
        $differentFast = $fast !== '' && ($anchorFast === '' || $fast !== $anchorFast);
        $differentId = $item['id'] !== '' && ($anchorIds === [] || !isset($anchorIds[$item['id']]));
        if ($item['address'] === '' && !$differentFast && !$differentId) {
            $partIndexes[] = $i;
        }
    }
    $parts = [];
    foreach ($partIndexes as $i) {
        $raw = $prepared[$i]['raw'] ?? null;
        if (is_array($raw)) {
            $parts[] = $raw;
        }
    }
    $norm = $parts === [] ? $best['norm'] : jeab_rot_merge_lookup_list($parts, $anchorFast);
    $want = jeab_rot_format_beteckning($wantFast);
    $got = jeab_rot_format_beteckning((string) ($norm['fastighetsbeteckning'] ?? ''));
    if ($want !== '' && ($got === '' || $got !== $want)) {
        return [
            'accepted' => false,
            'had_rows' => $prepared !== [],
            'norm' => jeab_rot_lookup_empty(),
            'fetched_address' => $fetched,
            'choices' => $choices,
        ];
    }
    $norm['fetched_address'] = jeab_rot_format_display_address($best['address']);
    $norm['lookup_exact'] = true;
    $norm['choices'] = [];
    $norm['objektidentitet'] = $best['id'];
    return [
        'accepted' => true,
        'had_rows' => true,
        'norm' => $norm,
        'fetched_address' => $norm['fetched_address'],
        'choices' => [],
    ];
}

function jeab_rot_fetched_address_text(array $found): string
{
    $fetched = trim((string) ($found['fetched_address'] ?? ''));
    if ($fetched === '') {
        return '';
    }
    $fetched = jeab_rot_format_display_address($fetched);
    if (!empty($found['lookup_exact'])) {
        return 'Hämtad adress: ' . $fetched;
    }
    return 'Lantmäteriet svarade med ' . $fetched . '. Ingen fastighet valdes.';
}

function jeab_rot_lookup_choice_text(array $found): string
{
    if (!empty($found['lookup_exact'])) {
        return '';
    }
    $lines = [];
    foreach (is_array($found['choices'] ?? null) ? $found['choices'] : [] as $choice) {
        if (!is_array($choice)) {
            continue;
        }
        $label = jeab_rot_format_display_address(trim((string) ($choice['address'] ?? '')));
        if ($label !== '' && !in_array($label, $lines, true)) {
            $lines[] = $label;
        }
    }
    if ($lines === []) {
        return '';
    }
    return 'Välj adress: ' . implode('; ', $lines);
}

/**
 * Poster där sparat svar inte är samma husnummer som frågan.
 * Bara nummer och typ, ingen adress och inget personnummer.
 *
 * @param list<array<string, mixed>> $entries
 * @return array{count:int,items:list<array{kind:string,query_number:string,fetched_number:string,query_letter:string,fetched_letter:string}>}
 */
function jeab_rot_lm_cache_suspects(array $entries): array
{
    $items = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $query = trim((string) ($entry['query'] ?? $entry['q'] ?? ''));
        $fetched = trim((string) ($entry['address'] ?? $entry['fetched_address'] ?? $entry['matchedAddress'] ?? ''));
        if ($query === '' || $fetched === '' || jeab_rot_address_exact($query, $fetched)) {
            continue;
        }
        $left = jeab_rot_address_identity($query);
        $right = jeab_rot_address_identity($fetched);
        if ($left['number'] === '' || $right['number'] === '' || !jeab_rot_street_words_match($left['words'], $right['words'])) {
            continue;
        }
        $kind = 'annat nummer';
        if ($left['number'] === $right['number'] && $left['letter'] !== $right['letter']) {
            $kind = 'bokstav';
        } elseif ($left['number'] !== $right['number'] && (str_starts_with($right['number'], $left['number']) || str_starts_with($left['number'], $right['number']))) {
            $kind = 'prefix';
        }
        $items[] = [
            'kind' => $kind,
            'query_number' => $left['number'],
            'fetched_number' => $right['number'],
            'query_letter' => $left['letter'],
            'fetched_letter' => $right['letter'],
        ];
    }
    return ['count' => count($items), 'items' => $items];
}

/**
 * @return array{count:int,items:list<array{kind:string,query_number:string,fetched_number:string,query_letter:string,fetched_letter:string}>}
 */
function jeab_rot_lm_cache_scan(?string $root = null): array
{
    $root = $root ?? (defined('JEAB_ROOT') ? (string) JEAB_ROOT : dirname(__DIR__));
    $entries = [];
    foreach ([$root . '/data', $root . '/rot'] as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            if (!str_ends_with($path, '.json') || $file->getSize() > 2000000) {
                continue;
            }
            $lower = strtolower($path);
            if (str_contains($lower, 'secret') || str_contains($lower, 'password') || str_contains($lower, 'nylas') || str_contains($lower, 'fortnox') || str_contains($lower, 'purchase')) {
                continue;
            }
            $raw = @file_get_contents($path);
            if (!is_string($raw) || (!str_contains($raw, 'matchedAddress') && !str_contains($raw, 'addressSuggestions') && !str_contains($raw, 'adresser'))) {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }
            $entries = array_merge($entries, jeab_rot_lm_cache_pairs($decoded));
        }
    }
    return jeab_rot_lm_cache_suspects($entries);
}

/** @return list<array{query:string,address:string}> */
function jeab_rot_lm_cache_pairs(mixed $node): array
{
    if (!is_array($node)) {
        return [];
    }
    $out = [];
    $query = trim((string) ($node['query'] ?? $node['q'] ?? $node['addressText'] ?? ''));
    $address = trim((string) ($node['matchedAddress'] ?? $node['fetched_address'] ?? ''));
    if ($query !== '' && $address !== '') {
        $out[] = ['query' => $query, 'address' => $address];
    }
    foreach ($node as $value) {
        if (is_array($value)) {
            $out = array_merge($out, jeab_rot_lm_cache_pairs($value));
        }
    }
    return $out;
}

/**
 * Flaggan på: fastighetsbeteckning och alla ägare hamnar på kontakten.
 * Själva uppslaget skriver inget.
 *
 * @param array<string, mixed> $order
 */
function jeab_rot_sole_keep_owners(array $order): void
{
    if (!empty($GLOBALS['_JEAB_LM_OWNER_REPLACE'])) {
        return;
    }
    if (!function_exists('jeab_contacts_sole_divert_property')) {
        return;
    }
    try {
        jeab_contacts_sole_divert_property($order);
    } catch (Throwable) {
    }
}

/**
 * Samma uppslag som Manuell order. $wantFast behåller den beteckningen
 * när adressen träffar flera fastigheter. Tom $wantFast är Manuell order.
 *
 * @return array<string, mixed>
 */
function jeab_lm_lookup_property(string $address, string $objektId = '', string $wantFast = ''): array
{
    return jeab_rot_lookup_address($address, $objektId, $wantFast);
}

function jeab_rot_lookup_address(string $address, string $objektId = '', string $wantFast = ''): array
{
    if (function_exists('jeab_kontakt_quiet') && jeab_kontakt_quiet()) {
        jeab_kontakt_side_effect_note('rot');
        return jeab_rot_lookup_empty();
    }
    $original = trim($address);
    $objektId = trim($objektId);
    $address = jeab_rot_lookup_query($original);
    if ($address === '' || preg_match('/\d+\s*:\s*\d+/', $address)) {
        $GLOBALS['_JEAB_ROT_LOOKUP_TRACE'] = [];
        return jeab_rot_lookup_empty();
    }
    $matchQuery = jeab_rot_address_identity($original)['number'] !== '' ? $original : $address;
    $best = jeab_rot_lookup_empty();
    $bestRank = -1;
    $trace = [];
    $rejectedFetched = '';
    $rejectedChoices = [];
    $rejectedProperties = [];
    $queries = $objektId !== '' ? [$address] : jeab_rot_lookup_queries($address);
    foreach ($queries as $query) {
        try {
            $raw = jeab_rot_live_address_search($query, $objektId);
        } catch (Throwable) {
            $raw = null;
        }
        $rows = jeab_rot_lookup_rows($raw);
        $pick = jeab_rot_pick_lookup($rows, $matchQuery, $objektId, $wantFast);
        $norm = $pick['accepted'] ? $pick['norm'] : jeab_rot_lookup_empty();
        $choiceAddresses = [];
        foreach (is_array($pick['choices'] ?? null) ? $pick['choices'] : [] as $choice) {
            if (!is_array($choice)) {
                continue;
            }
            $label = trim((string) ($choice['address'] ?? ''));
            if ($label !== '') {
                $choiceAddresses[] = $label;
            }
            if (count($choiceAddresses) >= 6) {
                break;
            }
        }
        $trace[] = [
            'query' => $query,
            'accepted' => !empty($pick['accepted']) ? 1 : 0,
            'fastighet' => trim((string) ($norm['fastighetsbeteckning'] ?? '')) !== '' ? 1 : 0,
            'beteckning' => trim((string) ($norm['fastighetsbeteckning'] ?? '')),
            'owners' => count(is_array($norm['owners'] ?? null) ? $norm['owners'] : []),
            'rows' => count($rows),
            'fetched' => trim((string) ($pick['fetched_address'] ?? '')),
            'choices' => count(is_array($pick['choices'] ?? null) ? $pick['choices'] : []),
            'choice_addresses' => $choiceAddresses,
            'pnr' => jeab_rot_lookup_has_pnr($norm) ? 1 : 0,
        ];
        if ($pick['accepted']) {
            $best = $bestRank < 0 ? $norm : jeab_rot_merge_accepted($best, $norm);
            $bestRank = jeab_rot_lookup_rank($best);
            if ($objektId !== '' || !jeab_rot_lookup_needs_fallback($best)) {
                break;
            }
            continue;
        }
        if ($rejectedFetched === '' && $pick['fetched_address'] !== '') {
            $rejectedFetched = $pick['fetched_address'];
            $rejectedChoices = $pick['choices'];
        }
        if ($rejectedProperties === [] && is_array($pick['properties'] ?? null) && count($pick['properties']) > 1) {
            $rejectedProperties = $pick['properties'];
        }
        // Förslag som inte är exakt husnummer ska inte stoppa reservsökningen
        // mot postort och kommun när beteckningen fortfarande saknas.
        if ($objektId !== '' || ($bestRank >= 0 && !jeab_rot_lookup_needs_fallback($best))) {
            break;
        }
    }
    $GLOBALS['_JEAB_ROT_LOOKUP_TRACE'] = $trace;
    if ($bestRank < 0) {
        $empty = jeab_rot_lookup_empty();
        $empty['fetched_address'] = $rejectedFetched;
        $empty['lookup_exact'] = false;
        $empty['choices'] = $rejectedChoices;
        if ($rejectedProperties !== []) {
            $empty['properties'] = $rejectedProperties;
        }
        return $empty;
    }
    $best['lookup_exact'] = true;
    if (trim((string) ($best['fetched_address'] ?? '')) === '') {
        $best['fetched_address'] = trim((string) ($best['address'] ?? ''));
    }
    $best['choices'] = [];
    return $best;
}

function jeab_rot_lookup_has_pnr(array $norm): bool
{
    foreach (is_array($norm['owners'] ?? null) ? $norm['owners'] : [] as $owner) {
        if (is_array($owner) && trim((string) ($owner['personnummer'] ?? '')) !== '') {
            return true;
        }
    }
    return false;
}

/** Cacheversion. Äldre poster, eller poster där någon ägare saknar personnummer, kan ogiltigförklaras. */
const JEAB_LM_CACHE_PARSER = 2;

function jeab_rot_lm_cache_path(): string
{
    if (!empty($GLOBALS['_JEAB_DATA_DIR']) && is_string($GLOBALS['_JEAB_DATA_DIR'])) {
        $dir = rtrim($GLOBALS['_JEAB_DATA_DIR'], '/');
    } else {
        $root = defined('JEAB_ROOT') ? JEAB_ROOT : dirname(__DIR__);
        $dir = dirname($root) . '/privat';
    }
    return $dir . '/lm-fastighet-cache.json';
}

/** @return array{by_fastighet:array<string,mixed>,by_query:array<string,string>} */
function jeab_rot_lm_cache_load(): array
{
    $path = jeab_rot_lm_cache_path();
    if (!is_file($path)) {
        return ['by_fastighet' => [], 'by_query' => []];
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        return ['by_fastighet' => [], 'by_query' => []];
    }
    $data['by_fastighet'] = is_array($data['by_fastighet'] ?? null) ? $data['by_fastighet'] : [];
    $data['by_query'] = is_array($data['by_query'] ?? null) ? $data['by_query'] : [];
    return $data;
}

function jeab_rot_lm_cache_save(array $doc): void
{
    $json = json_encode($doc, JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        return;
    }
    $path = jeab_rot_lm_cache_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }
    @file_put_contents($path, $json, LOCK_EX);
}

function jeab_rot_lm_cache_suspect(array $entry): bool
{
    if ((int) ($entry['parser'] ?? 0) < JEAB_LM_CACHE_PARSER) {
        return true;
    }
    if (!empty($entry['suspect'])) {
        return true;
    }
    $count = (int) ($entry['owner_count'] ?? 0);
    $pnr = (int) ($entry['pnr_count'] ?? 0);
    return $count > 0 && $pnr < $count;
}

/** @return array<string, mixed>|null */
function jeab_rot_lm_cache_get(string $query, string $objektId = ''): ?array
{
    if (!empty($GLOBALS['_JEAB_LM_CACHE_BYPASS'])) {
        return null;
    }
    $doc = jeab_rot_lm_cache_load();
    $key = jeab_rot_lm_cache_key($query, $objektId);
    $fast = trim((string) ($doc['by_query'][$key] ?? ''));
    if ($fast === '' || !isset($doc['by_fastighet'][$fast]) || !is_array($doc['by_fastighet'][$fast])) {
        return null;
    }
    $hit = $doc['by_fastighet'][$fast];
    $hit['fastighetsbeteckning'] = (string) ($hit['fastighetsbeteckning'] ?? $fast);
    $want = jeab_rot_address_identity($query);
    if ($want['number'] !== '') {
        $stored = trim((string) ($hit['address'] ?? ''));
        $have = jeab_rot_address_identity($stored);
        $sameStreet = $have['words'] !== [] && jeab_rot_street_words_match($want['words'], $have['words']);
        if ($stored === '' || ($sameStreet && !jeab_rot_address_exact($query, $stored))) {
            return null;
        }
    }
    return $hit;
}

/** @param array<string, mixed> $norm */
function jeab_rot_lm_cache_put(string $query, array $norm, bool $suspect, string $objektId = ''): void
{
    $fast = trim((string) ($norm['fastighetsbeteckning'] ?? ''));
    if ($fast === '') {
        return;
    }
    $owners = [];
    $pnrCount = 0;
    foreach (is_array($norm['owners'] ?? null) ? $norm['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $owners[] = $owner;
        if (trim((string) ($owner['personnummer'] ?? '')) !== '') {
            $pnrCount++;
        }
    }
    $count = count($owners);
    $requestedId = trim($objektId);
    $storedId = $requestedId !== '' ? $requestedId : trim((string) ($norm['objektidentitet'] ?? ''));
    $entry = [
        'fastighetsbeteckning' => $fast,
        'address' => (string) ($norm['address'] ?? ''),
        'owners' => $owners,
        'postnummer' => (string) ($norm['postnummer'] ?? ''),
        'ort' => (string) ($norm['ort'] ?? ''),
        'objektidentitet' => $storedId,
        'owner_count' => $count,
        'pnr_count' => $pnrCount,
        'parser' => JEAB_LM_CACHE_PARSER,
        'suspect' => $suspect || ($count > 0 && $pnrCount < $count),
    ];
    $doc = jeab_rot_lm_cache_load();
    $doc['by_fastighet'][$fast] = $entry;
    $key = jeab_rot_lm_cache_key($query, $requestedId);
    if ($key !== '') {
        $doc['by_query'][$key] = $fast;
    }
    jeab_rot_lm_cache_save($doc);
}

function jeab_rot_lm_cache_forget(string $fastighet = '', string $query = '', string $objektId = ''): void
{
    $doc = jeab_rot_lm_cache_load();
    $fast = trim($fastighet);
    $key = $query !== '' ? jeab_rot_lm_cache_key($query, $objektId) : '';
    if ($fast === '' && $key !== '') {
        $fast = trim((string) ($doc['by_query'][$key] ?? ''));
    }
    if ($fast !== '') {
        unset($doc['by_fastighet'][$fast]);
    }
    foreach ($doc['by_query'] as $saved => $id) {
        if (($fast !== '' && $id === $fast) || ($key !== '' && $saved === $key)) {
            unset($doc['by_query'][$saved]);
        }
    }
    jeab_rot_lm_cache_save($doc);
}

function jeab_lm_mismatch_join(string $line, string $postal, string $city): string
{
    $line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);
    $postal = jeab_rot_postnummer_digits($postal);
    if ($postal !== '' && jeab_rot_postnummer_digits($line) === '') {
        $pretty = substr($postal, 0, 3) . ' ' . substr($postal, 3);
        $line = trim($line . ' ' . $pretty);
    }
    $city = trim($city);
    if ($city !== '' && $line !== '' && !str_contains(jeab_rot_fold($line), jeab_rot_fold($city))) {
        $line = trim($line . ' ' . $city);
    }
    return $line;
}

/** Kundens adress på en order. */
function jeab_lm_mismatch_order_address(array $row): string
{
    $line = trim((string) ($row['address'] ?? ''));
    if ($line === '') {
        $line = trim((string) ($row['gata'] ?? ''));
    }
    if ($line === '') {
        $line = trim((string) ($row['huvudsokande_address'] ?? ''));
    }
    $postal = (string) ($row['postnummer'] ?? $row['postnr'] ?? '');
    $city = trim((string) ($row['ort'] ?? ''));
    return jeab_lm_mismatch_join($line, $postal, $city);
}

/** Kundens adress på en kontakt. */
function jeab_lm_mismatch_contact_address(array $row): string
{
    return jeab_lm_mismatch_join(
        (string) ($row['street'] ?? ''),
        (string) ($row['postal_code'] ?? $row['postnummer'] ?? ''),
        (string) ($row['city'] ?? $row['ort'] ?? '')
    );
}

/**
 * Sparad LM-adress, inte kundens egen rad och inte personnummer.
 *
 * @param array<string, mixed> $row
 * @return list<string>
 */
function jeab_lm_mismatch_lm_addresses(array $row, string $customer): array
{
    $out = [];
    $add = static function (string $line) use (&$out, $customer): void {
        $line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);
        if ($line === '' || $line === $customer) {
            return;
        }
        if (!in_array($line, $out, true)) {
            $out[] = $line;
        }
    };
    foreach (['lm_address', 'register_address', 'fastighet_address', 'matched_address'] as $key) {
        if (isset($row[$key]) && is_string($row[$key])) {
            $add($row[$key]);
        }
    }
    if (isset($row['address']) && is_string($row['address']) && trim((string) ($row['street'] ?? '')) !== '') {
        $add($row['address']);
    }
    $add((string) ($row['huvudsokande_address'] ?? ''));
    foreach (['owners', 'medsokande', 'extra_owners'] as $listKey) {
        foreach (is_array($row[$listKey] ?? null) ? $row[$listKey] : [] as $person) {
            if (is_array($person)) {
                $add((string) ($person['address'] ?? ''));
            }
        }
    }
    return $out;
}

/**
 * @param array<string, mixed> $value
 * @return array<string, mixed>
 */
function jeab_lm_mismatch_scrub(array $value): array
{
    $out = [];
    foreach ($value as $key => $item) {
        if (is_string($key) && preg_match('/person/i', $key) === 1) {
            continue;
        }
        if (is_array($item)) {
            $out[$key] = jeab_lm_mismatch_scrub($item);
            continue;
        }
        if (is_string($item)) {
            $out[$key] = preg_replace('/(?<!\d)(?:\d{6}|\d{8})[-+]?\d{4}(?!\d)/', '[pnr]', $item) ?? $item;
            continue;
        }
        $out[$key] = $item;
    }
    return $out;
}

/**
 * lm.mismatch_scan. Läser order och kontakter. Skriver inget, även om dry=0.
 * Listar id och adresser där sparad LM-adress inte har samma husnummer eller postnummer.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_lm_mismatch_scan(array $input = []): array
{
    $action = trim((string) ($input['action'] ?? ''));
    if ($action !== 'rot.lm_cache_suspects') {
        $action = 'lm.mismatch_scan';
    }
    $orders = [];
    if (function_exists('jeab_purchase_all')) {
        foreach (jeab_purchase_all() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['reminder_id'] ?? 0);
            $fast = trim((string) ($row['fastighetsbeteckning'] ?? ''));
            if ($id <= 0 || $fast === '') {
                continue;
            }
            $customer = jeab_lm_mismatch_order_address($row);
            if (jeab_rot_address_identity($customer)['number'] === '') {
                continue;
            }
            $lm = '';
            foreach (jeab_lm_mismatch_lm_addresses($row, $customer) as $have) {
                $idHave = jeab_rot_address_identity($have);
                if ($idHave['number'] === '' && $idHave['postal'] === '') {
                    continue;
                }
                if (!jeab_rot_address_exact($customer, $have)) {
                    $lm = $have;
                    break;
                }
            }
            if ($lm === '') {
                continue;
            }
            $orders[] = ['id' => $id, 'address' => $customer, 'lm_address' => $lm];
        }
    }
    $contacts = [];
    if (function_exists('jeab_kontakter_load')) {
        foreach (jeab_kontakter_load() as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? $key));
            $fast = trim((string) ($row['fastighetsbeteckning'] ?? $row['fastighet'] ?? ''));
            if ($id === '' || $fast === '') {
                continue;
            }
            $customer = jeab_lm_mismatch_contact_address($row);
            if (jeab_rot_address_identity($customer)['number'] === '') {
                continue;
            }
            $lm = '';
            foreach (jeab_lm_mismatch_lm_addresses($row, $customer) as $have) {
                $idHave = jeab_rot_address_identity($have);
                if ($idHave['number'] === '' && $idHave['postal'] === '') {
                    continue;
                }
                if (!jeab_rot_address_exact($customer, $have)) {
                    $lm = $have;
                    break;
                }
            }
            if ($lm === '') {
                continue;
            }
            $contacts[] = ['id' => $id, 'address' => $customer, 'lm_address' => $lm];
        }
    }
    $ids = [];
    foreach ($orders as $order) {
        $ids[] = $order['id'];
    }
    foreach ($contacts as $contact) {
        $ids[] = $contact['id'];
    }
    $cache = jeab_rot_lm_cache_scan();
    return jeab_lm_mismatch_scrub([
        'ok' => true,
        'action' => $action,
        'dry' => 1,
        'read_only' => 1,
        'count' => count($ids),
        'ids' => $ids,
        'orders' => $orders,
        'contacts' => $contacts,
        'cache' => $cache,
    ]);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_lm_mismatch_scan_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401, 'action' => 'lm.mismatch_scan', 'dry' => 1];
    }
    $out = jeab_lm_mismatch_scan($input);
    $out['status'] = 200;
    return $out;
}
