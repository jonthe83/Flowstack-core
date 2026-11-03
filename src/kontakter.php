<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/idnr.php';
require_once __DIR__ . '/contact_secondary.php';
require_once __DIR__ . '/kontakt_modules.php';

/**
 * Kontakter (steg 1). En kontakt är en adress med en eller två ägare.
 * Andra poster lagrar contact_id. Med CONTACTS_SOLE=1 läses kundfälten därifrån.
 * Utan flaggan läses de egna kopiorna som förut.
 * Personnummer skrivs bara i den här filen på servern, aldrig i logg eller hooksvar.
 */

function jeab_kontakter_path(): string
{
    return jeab_data_dir() . '/kontakter.json';
}

/**
 * Nyckel för registret i den här requesten. JSON-läget tar med filens
 * storlek och mtime så en skrivning i samma sekund inte lämnar den gamla listan.
 */
function jeab_kontakter_mem_key(?JeabPdoContactStore $sql): string
{
    $req = (string) ($_SERVER['REQUEST_TIME_FLOAT'] ?? '0');
    if ($sql instanceof JeabPdoContactStore) {
        return 'sql|' . $req;
    }
    $path = jeab_kontakter_path();
    clearstatcache(true, $path);
    $size = is_file($path) ? (int) filesize($path) : 0;
    $mtime = is_file($path) ? (int) filemtime($path) : 0;

    return 'json|' . $req . '|' . $mtime . '|' . $size;
}

/** @return array<string, array<string, mixed>> */
function jeab_kontakter_load(): array
{
    $sql = function_exists('jeab_kontakter_pdo_store') ? jeab_kontakter_pdo_store() : null;
    $key = jeab_kontakter_mem_key($sql instanceof JeabPdoContactStore ? $sql : null);
    if (
        isset($GLOBALS['_JEAB_KONTAKTER_MEM'], $GLOBALS['_JEAB_KONTAKTER_MEM_KEY'])
        && is_array($GLOBALS['_JEAB_KONTAKTER_MEM'])
        && $GLOBALS['_JEAB_KONTAKTER_MEM_KEY'] === $key
    ) {
        return $GLOBALS['_JEAB_KONTAKTER_MEM'];
    }
    $GLOBALS['_JEAB_KONTAKTER_LOADS'] = (int) ($GLOBALS['_JEAB_KONTAKTER_LOADS'] ?? 0) + 1;
    $hook = $GLOBALS['_JEAB_KONTAKTER_LOAD_HOOK'] ?? null;
    if ($hook instanceof Closure) {
        $hook();
    }
    if (!$sql instanceof JeabPdoContactStore) {
        if (!empty($GLOBALS['_JEAB_STORE_OPENING'])) {
            return [];
        }
        if (function_exists('jeab_kontakter_sql_fail')) {
            jeab_kontakter_sql_fail();
        }
        throw new RuntimeException('Kontaktregistret kan inte sparas.');
    }
    $rows = $sql->sqlAll();
    $GLOBALS['_JEAB_KONTAKTER_MEM'] = $rows;
    $GLOBALS['_JEAB_KONTAKTER_MEM_KEY'] = $key;

    return $rows;
}

/** Högst så många kontakter får en beslutad sammanslagning eller skräpradering ta bort per anrop. */
const JEAB_KONTAKTER_DECIDED_REMOVE_MAX = 50;
/** Från den här storleken gäller även taket 5 % av registret. Mindre register får ta bort de beslutade raderna. */
const JEAB_KONTAKTER_DECIDED_REMOVE_LARGE = 200;
const JEAB_KONTAKTER_DECIDED_REMOVE_SHARE = 0.05;

/** Ryms en beslutad radering av $removed kontakter i ett register med $before rader? */
function jeab_kontakter_decided_remove_ok(int $before, int $removed): bool
{
    if ($removed <= 0) {
        return true;
    }
    if ($removed > JEAB_KONTAKTER_DECIDED_REMOVE_MAX) {
        return false;
    }
    if ($before >= JEAB_KONTAKTER_DECIDED_REMOVE_LARGE && $removed > (int) floor($before * JEAB_KONTAKTER_DECIDED_REMOVE_SHARE)) {
        return false;
    }

    return true;
}

/**
 * Beslutad radering (sammanslagning, skräpkontakter) med tak.
 * $fn körs på en kopia. Över taket sparas ingenting, felloggen får en rad
 * och svaret är null. Annars sparas kopian och $fn:s svar returneras.
 *
 * @param callable(array<string, array<string, mixed>>): mixed $fn
 */
function jeab_kontakter_decided_remove(callable $fn, string $surface): mixed
{
    $GLOBALS['_JEAB_KONTAKTER_DECIDED_BLOCKED'] = null;

    return jeab_kontakter_mutate(static function (array &$contacts) use ($fn, $surface): mixed {
        $copy = $contacts;
        $out = $fn($copy);
        $before = count($contacts);
        $removed = count(array_diff_key($contacts, $copy));
        if (!jeab_kontakter_decided_remove_ok($before, $removed)) {
            $GLOBALS['_JEAB_KONTAKTER_DECIDED_BLOCKED'] = ['surface' => $surface, 'before' => $before, 'removed' => $removed];
            if (function_exists('jeab_log_app_error')) {
                jeab_log_app_error($surface, 'Radering över taket avbruten: ' . $removed . ' av ' . $before . ' kontakter');
            }

            return null;
        }
        $contacts = $copy;

        return $out;
    }, true);
}

/**
 * @param callable(array<string, array<string, mixed>>): mixed $fn
 */
function jeab_kontakter_mutate(callable $fn, bool $allowMass = false): mixed
{
    unset($GLOBALS['_JEAB_KONTAKTER_MEM'], $GLOBALS['_JEAB_KONTAKTER_MEM_KEY']);
    $sql = function_exists('jeab_kontakter_pdo_store') ? jeab_kontakter_pdo_store() : null;
    if (!$sql instanceof JeabPdoContactStore) {
        if (!empty($GLOBALS['_JEAB_STORE_OPENING'])) {
            throw new RuntimeException('Kontaktregistret kan inte sparas.');
        }
        if (function_exists('jeab_kontakter_sql_fail')) {
            jeab_kontakter_sql_fail();
        }
        throw new RuntimeException('Kontaktregistret kan inte sparas.');
    }
    $result = $sql->mutate(static function (array &$contacts) use ($fn): mixed {
        $result = $fn($contacts);
        foreach ($contacts as $id => $row) {
            if (is_array($row)) {
                $contacts[$id] = jeab_idnr_normalize_row($row);
            }
        }
        return $result;
    }, $allowMass);
    unset($GLOBALS['_JEAB_KONTAKTER_MEM'], $GLOBALS['_JEAB_KONTAKTER_MEM_KEY']);
    if (function_exists('jeab_contacts_sole_cache_forget')) {
        jeab_contacts_sole_cache_forget(null);
    }

    return $result;
}

function jeab_kontakt_fold_text(string $value): string
{
    return jeab_norm_fold($value);
}

/**
 * Postnummer i en text.
 * «17770 9» (fem siffror och en lös siffra) blir 17770.
 * Ett hopklistrat eller annars trasigt nummer blir tomt och ogiltigt, och får inte användas som nyckel.
 *
 * @return array{postal:string,invalid:bool,offset:int,length:int}
 */
function jeab_kontakt_postal_scan(string $raw): array
{
    $empty = ['postal' => '', 'invalid' => false, 'offset' => -1, 'length' => 0];
    if ($raw === '') {
        return $empty;
    }
    if (preg_match('/(?<!\d)(\d{3})\s?(\d{2})(?:\s+\d)?(?!\d)/u', $raw, $m, PREG_OFFSET_CAPTURE) === 1) {
        return [
            'postal' => $m[1][0] . $m[2][0],
            'invalid' => false,
            'offset' => (int) $m[0][1],
            'length' => strlen($m[0][0]),
        ];
    }
    if (preg_match('/(?<!\d)\d{6,}(?!\d)/u', $raw, $m, PREG_OFFSET_CAPTURE) === 1) {
        return [
            'postal' => '',
            'invalid' => true,
            'offset' => (int) $m[0][1],
            'length' => strlen($m[0][0]),
        ];
    }
    return $empty;
}

function jeab_kontakt_postal(string $raw): string
{
    $found = jeab_kontakt_postal_scan($raw);
    return $found['invalid'] ? '' : $found['postal'];
}

/** Tar bort «, Sverige» och liknande landssvans från en ort. */
function jeab_kontakt_city_name(string $city): string
{
    $city = trim(preg_replace('/\s+/u', ' ', $city) ?? $city);
    $prev = null;
    while ($city !== '' && $city !== $prev) {
        $prev = $city;
        $city = preg_replace('/\s*,?\s*(?:sverige|sweden|suede|suède)\s*$/iu', '', $city) ?? $city;
        $city = preg_replace('/\s*,\s*se\s*$/iu', '', $city) ?? $city;
        $city = preg_replace('/\s+kommun\s*$/iu', '', $city) ?? $city;
        $city = trim($city, " ,");
    }
    if ($city === '') {
        return '';
    }
    $canon = jeab_kontakt_place_canon($city);
    if ($canon !== '') {
        return $canon;
    }
    return mb_convert_case($city, MB_CASE_TITLE, 'UTF-8');
}

/** Kontakter-sidan med co=alla visar varje rad, oavsett bolag. */
function jeab_kontakter_all_offices(): bool
{
    $raw = function_exists('mb_strtolower')
        ? mb_strtolower(trim((string) ($_GET['co'] ?? '')), 'UTF-8')
        : strtolower(trim((string) ($_GET['co'] ?? '')));

    if (!in_array($raw, ['alla', 'all'], true)) {
        return false;
    }
    if (!function_exists('jeab_actor_companies')) {
        return true;
    }
    $actor = jeab_actor_companies();
    if ($actor === null) {
        return true;
    }

    return in_array('jarfalla', $actor, true) && in_array('malmo', $actor, true);
}

/**
 * Förkortning med en känd ort. Hbg blir Helsingborg, inte förkortningen själv.
 */
function jeab_kontakt_place_label(string $token): string
{
    return jeab_kontakt_place_canon($token);
}

/**
 * Svenska kommuner och vanliga orter, plus förkortningar som har en hel ort.
 *
 * @return array<string, string>
 */
function jeab_kontakt_places(): array
{
    static $map = null;
    if (is_array($map)) {
        return $map;
    }
    $names = [
        'Ale', 'Alingsås', 'Alvesta', 'Aneby', 'Arboga', 'Arjeplog', 'Arvidsjaur', 'Arvika', 'Askersund', 'Avesta',
        'Bengtsfors', 'Berg', 'Bjurholm', 'Bjuv', 'Boden', 'Bollebygd', 'Bollnäs', 'Borgholm', 'Borlänge', 'Borås',
        'Botkyrka', 'Boxholm', 'Bromölla', 'Bräcke', 'Burlöv', 'Båstad', 'Dals-Ed', 'Danderyd', 'Degerfors', 'Dorotea',
        'Eda', 'Ekerö', 'Eksjö', 'Emmaboda', 'Enköping', 'Eskilstuna', 'Eslöv', 'Essunga', 'Fagersta', 'Falkenberg',
        'Falköping', 'Falun', 'Färgelanda', 'Filipstad', 'Finspång', 'Flen', 'Forshaga', 'Gagnef', 'Gällivare', 'Gävle',
        'Gislaved', 'Gnesta', 'Gnosjö', 'Göteborg', 'Götene', 'Gotland', 'Grästorp', 'Grums', 'Gullspång', 'Habo',
        'Hagfors', 'Hallsberg', 'Hallstahammar', 'Halmstad', 'Hammarö', 'Haninge', 'Haparanda', 'Heby', 'Hedemora',
        'Helsingborg', 'Herrljunga', 'Hjo', 'Hofors', 'Huddinge', 'Hudiksvall', 'Hultsfred', 'Hylte', 'Håbo',
        'Hällefors', 'Härjedalen', 'Härnösand', 'Härryda', 'Hässleholm', 'Höganäs', 'Högsby', 'Höör', 'Hörby',
        'Jokkmokk', 'Järfälla', 'Jönköping', 'Kalix', 'Kalmar', 'Karlsborg', 'Karlshamn', 'Karlskoga', 'Karlskrona',
        'Karlstad', 'Katrineholm', 'Kil', 'Kinda', 'Kiruna', 'Klippan', 'Knivsta', 'Kramfors', 'Kristianstad',
        'Kristinehamn', 'Krokom', 'Kumla', 'Kungsbacka', 'Kungsör', 'Kungälv', 'Köping', 'Laholm', 'Landskrona',
        'Laxå', 'Lekeberg', 'Leksand', 'Lerum', 'Lessebo', 'Lidingö', 'Lidköping', 'Lilla Edet', 'Lindesberg',
        'Linköping', 'Ljungby', 'Ljusdal', 'Ljusnarsberg', 'Lomma', 'Ludvika', 'Luleå', 'Lund', 'Lycksele', 'Lysekil',
        'Malmö', 'Malung-Sälen', 'Malå', 'Mariestad', 'Mark', 'Markaryd', 'Mellerud', 'Mjölby', 'Mora', 'Motala',
        'Mullsjö', 'Munkedal', 'Munkfors', 'Mölndal', 'Mönsterås', 'Mörbylånga', 'Nacka', 'Nora', 'Norberg',
        'Nordanstig', 'Nordmaling', 'Norrköping', 'Norrtälje', 'Norsjö', 'Nybro', 'Nyköping', 'Nykvarn', 'Nynäshamn',
        'Nässjö', 'Ockelbo', 'Olofström', 'Orsa', 'Orust', 'Osby', 'Oskarshamn', 'Ovanåker', 'Oxelösund', 'Pajala',
        'Partille', 'Perstorp', 'Piteå', 'Ragunda', 'Robertsfors', 'Ronneby', 'Rättvik', 'Sala', 'Salem', 'Sandviken',
        'Sigtuna', 'Simrishamn', 'Sjöbo', 'Skara', 'Skellefteå', 'Skinnskatteberg', 'Skurup', 'Skövde', 'Smedjebacken',
        'Sollefteå', 'Sollentuna', 'Solna', 'Sorsele', 'Sotenäs', 'Staffanstorp', 'Stenungsund', 'Stockholm', 'Storfors',
        'Storuman', 'Strängnäs', 'Strömstad', 'Strömsund', 'Sundbyberg', 'Sundsvall', 'Sunne', 'Surahammar', 'Svalöv',
        'Svedala', 'Svenljunga', 'Säffle', 'Säter', 'Sävsjö', 'Söderhamn', 'Söderköping', 'Södertälje', 'Sölvesborg',
        'Tanum', 'Tibro', 'Tidaholm', 'Tierp', 'Timrå', 'Tingsryd', 'Tjörn', 'Tomelilla', 'Torsby', 'Torsås', 'Tranemo',
        'Tranås', 'Trelleborg', 'Trollhättan', 'Trosa', 'Tyresö', 'Täby', 'Uddevalla', 'Ulricehamn', 'Umeå', 'Upplands-Bro',
        'Upplands Väsby', 'Uppsala', 'Uppvidinge', 'Vadstena', 'Vaggeryd', 'Valdemarsvik', 'Vallentuna', 'Vansbro', 'Vara',
        'Varberg', 'Vaxholm', 'Vellinge', 'Vetlanda', 'Vilhelmina', 'Vimmerby', 'Vindeln', 'Vingåker', 'Vårgårda',
        'Vänersborg', 'Vännäs', 'Värmdö', 'Värnamo', 'Västerås', 'Växjö', 'Ydre', 'Ystad', 'Åmål', 'Ånge', 'Åre',
        'Årjäng', 'Åsele', 'Åstorp', 'Åtvidaberg', 'Älmhult', 'Älvdalen', 'Älvkarleby', 'Älvsbyn', 'Ängelholm',
        'Öckerö', 'Ödeshög', 'Örebro', 'Örkelljunga', 'Örnsköldsvik', 'Östersund', 'Österåker', 'Östhammar', 'Östra Göinge',
        'Överkalix', 'Övertorneå',
    ];
    $map = [
        'hbg' => 'Helsingborg',
        'sthlm' => 'Stockholm',
        'gbg' => 'Göteborg',
        'mlm' => 'Malmö',
    ];
    foreach ($names as $name) {
        $fold = jeab_kontakt_fold_text($name);
        if ($fold !== '') {
            $map[$fold] = $name;
        }
    }
    return $map;
}

/** Känd ort i platslistan. Tom sträng om texten inte är en ort. Hela ortnamnet, inte förkortningen. */
function jeab_kontakt_place_canon(string $text): string
{
    $fold = jeab_kontakt_fold_text(trim($text, " \t."));
    if ($fold === '') {
        return '';
    }
    $places = jeab_kontakt_places();
    return $places[$fold] ?? '';
}

/** Bara förkortningarna Hbg, Sthlm, Gbg och Mlm. En kommun i listan är inte en förkortning. */
function jeab_kontakt_place_abbrev(string $token): string
{
    $fold = jeab_kontakt_fold_text(trim($token, " \t."));
    return match ($fold) {
        'hbg' => 'Helsingborg',
        'sthlm' => 'Stockholm',
        'gbg' => 'Göteborg',
        'mlm' => 'Malmö',
        default => '',
    };
}

/**
 * Orten som redan står i kontaktens adress eller postort. Träffar bara exakt samma ord.
 */
function jeab_kontakt_known_city_match(string $text, string $knownCity): string
{
    $fold = jeab_kontakt_fold_text(trim($text, " \t."));
    $knownFold = jeab_kontakt_fold_text($knownCity);
    if ($fold === '' || $knownFold === '' || $fold !== $knownFold) {
        return '';
    }
    $canon = jeab_kontakt_place_canon($knownCity);
    return $canon !== '' ? $canon : trim($knownCity);
}

/**
 * «Efternamn, Förnamn» blir «Förnamn Efternamn». Efter vändningen skalas inget mer av.
 * Hela platslistan används bara efter « / » eller efter ett andra komma.
 * Ett avslutande ord utan skiljetecken lämnar namnet bara om det är Hbg/Sthlm/Gbg/Mlm
 * eller exakt samma ort som redan står i adressen. En kommun i listan räcker inte,
 * och resultatet får inte bli ett ensamt förnamn (förkortningarna är undantaget).
 * Ett dubbelt förnamn lämnar hela namnet: «Anna Maria Lund» när postorten är Lund
 * behåller Lund som efternamn. «Anna Berg Sala» skalas fortfarande, för Berg är en ort.
 * Körs innan skräp- och namnfilter.
 *
 * @return array{name:string,city:string}
 */
function jeab_kontakt_normalize_person_name(string $name, string $knownCity = ''): array
{
    $name = trim($name);
    $name = preg_replace('/^[\s"\'`«»“”‘’]+|[\s"\'`«»“”‘’]+$/u', '', $name) ?? $name;
    $name = trim($name);
    $original = $name;
    $listedPlace = static function (string $text) use ($knownCity): string {
        $canon = jeab_kontakt_place_canon($text);
        return $canon !== '' ? $canon : jeab_kontakt_known_city_match($text, $knownCity);
    };
    $words = static fn (string $text): int => count(jeab_kontakt_name_tokens($text));
    if (preg_match('/^(.+?)\s+\/\s+(.+)$/u', $name, $m) === 1) {
        $left = trim((string) $m[1], " \t\"'`«»“”‘’");
        $right = trim((string) $m[2], " \t\"'`«»“”‘’");
        $place = $listedPlace($right);
        if ($left !== '' && $place !== '' && $words($left) >= 2) {
            return ['name' => $left, 'city' => $place];
        }
        return ['name' => $original, 'city' => ''];
    }
    $parts = preg_split('/\s*,\s*/u', $name) ?: [];
    $parts = array_values(array_filter(array_map(static function (string $part): string {
        return trim($part, " \t\"'`«»“”‘’");
    }, $parts), static fn (string $part): bool => $part !== ''));
    if (count($parts) >= 3) {
        $place = $listedPlace(implode(', ', array_slice($parts, 2)));
        $left = $parts[0];
        $right = $parts[1];
        $flipped = ($left !== '' && $right !== '' && preg_match('/\d/u', $left . $right) !== 1)
            ? trim($right . ' ' . $left)
            : '';
        if ($place !== '' && $flipped !== '' && $words($flipped) >= 2) {
            return ['name' => $flipped, 'city' => $place];
        }
        return ['name' => $original, 'city' => ''];
    }
    if (count($parts) === 2) {
        $abbrev = jeab_kontakt_place_abbrev($parts[1]);
        if ($abbrev !== '' && $parts[0] !== '') {
            return ['name' => $parts[0], 'city' => $abbrev];
        }
        $left = $parts[0];
        $right = $parts[1];
        if ($left !== '' && $right !== '' && preg_match('/\d/u', $left . $right) !== 1) {
            return ['name' => trim($right . ' ' . $left), 'city' => ''];
        }
        return ['name' => $original, 'city' => ''];
    }
    if (preg_match('/^(.*\S)\s+(\S+)\.?$/u', $name, $m) === 1) {
        $bare = trim((string) $m[1]);
        $token = (string) $m[2];
        $abbrev = jeab_kontakt_place_abbrev($token);
        if ($abbrev !== '' && $bare !== '') {
            return ['name' => $bare, 'city' => $abbrev];
        }
        $known = jeab_kontakt_known_city_match($token, $knownCity);
        if ($known !== '' && $bare !== '' && $words($bare) >= 2) {
            if (jeab_kontakt_double_first_name($bare)) {
                return ['name' => trim($name), 'city' => ''];
            }
            return ['name' => $bare, 'city' => $known];
        }
    }
    return ['name' => trim($name), 'city' => ''];
}

function jeab_kontakt_strip_country(string $raw): string
{
    $raw = trim($raw);
    $prev = null;
    while ($raw !== '' && $raw !== $prev) {
        $prev = $raw;
        $raw = preg_replace('/\s*,?\s*(?:sverige|sweden|suede|suède)\s*$/iu', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*,\s*se\s*$/iu', '', $raw) ?? $raw;
        $raw = trim($raw, " ,");
    }
    return $raw;
}

/**
 * Lägenhet eller våning efter husnumret. Tom sträng om svansen inte är en enhet.
 * «lgh 1101», «lägenhet 1101», «3 tr» och «vån 2» blir kvar i nyckeln.
 */
function jeab_kontakt_street_unit(string $tail): string
{
    $tail = trim($tail);
    if ($tail === '') {
        return '';
    }
    $kind = '';
    $num = '';
    if (preg_match('/^(\d+)\s*(trappa|tr|vaning|van|lgh|lagenhet)(?![a-z])/u', $tail, $m) === 1) {
        $kind = (string) $m[2];
        $num = (string) $m[1];
    } elseif (preg_match('/^(lgh|lagenhet|trappa|tr|nb|bv|vaning|van)(?![a-z])\s*(\d+\s*[a-z]?)?/u', $tail, $m) === 1) {
        $kind = (string) $m[1];
        $num = (string) ($m[2] ?? '');
    } else {
        return '';
    }
    $kind = match ($kind) {
        'lagenhet' => 'lgh',
        'trappa', 'tr' => 'tr',
        'vaning', 'van' => 'van',
        default => $kind,
    };
    $num = preg_replace('/\s+/u', '', $num) ?? '';
    return $num !== '' ? $kind . ' ' . $num : $kind;
}

/**
 * Gata + nummer + lägenhet eller våning.
 * Mellanslag och bindestreck inne i gatunamnet ignoreras
 * («Ljusterö huvuds väg 23» = «Ljusteröhuvudsväg 23»).
 * «12a» och «12 a» är samma nyckel. «12» och «12 a» är inte det.
 * «lgh 1101» och «lgh 1102» är inte samma nyckel. «tr» och «vån» stannar i nyckeln.
 * Utan nummer blir nyckeln tom (då gäller e-post eller telefon).
 */
function jeab_kontakt_street_key(string $streetLine): string
{
    $text = jeab_kontakt_fold_text($streetLine);
    $text = str_replace([',', ';'], ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    if (preg_match('/^(.+?)\s+(\d+)\s*([a-z])?(?:\s+(.+))?$/u', $text, $m) !== 1) {
        return '';
    }
    $name = preg_replace('/[^a-z0-9]+/u', '', (string) $m[1]) ?? '';
    if ($name === '') {
        return '';
    }
    $base = $name . ' ' . $m[2] . ($m[3] ?? '');
    $tail = trim((string) ($m[4] ?? ''));
    if ($tail === '') {
        return $base;
    }
    $unit = jeab_kontakt_street_unit($tail);
    if ($unit === '') {
        return '';
    }
    return $base . ' ' . $unit;
}

/** Nyckel från gatutexten. Sparad nyckel används bara när texten saknar nummer. */
function jeab_kontakt_effective_street_key(string $street, string $storedKey = ''): string
{
    $fromStreet = jeab_kontakt_street_key($street);
    if ($fromStreet !== '') {
        return $fromStreet;
    }
    $storedKey = trim($storedKey);
    if ($storedKey === '') {
        return '';
    }
    $fromStored = jeab_kontakt_street_key($storedKey);
    return $fromStored !== '' ? $fromStored : $storedKey;
}

/**
 * Samma gata och nummer räcker inte. Samma postnummer eller samma ort krävs.
 * Saknat postnummer på ena sidan och olika eller saknad ort är ingen träff.
 */
function jeab_kontakt_place_confirmed(string $postalA, string $cityA, string $postalB, string $cityB): bool
{
    $postalA = jeab_kontakt_postal($postalA);
    $postalB = jeab_kontakt_postal($postalB);
    if ($postalA !== '' && $postalA === $postalB) {
        return true;
    }
    $town = static function (string $city): string {
        if (function_exists('jeab_kontakt_city_key')) {
            return jeab_kontakt_city_key($city);
        }
        return jeab_kontakt_fold_text(jeab_kontakt_city_name($city));
    };
    $townA = $town($cityA);
    $townB = $town($cityB);
    return $townA !== '' && $townA === $townB;
}

/** Samma adressnyckel även om en sparad nyckel fortfarande har mellanslag i gatunamnet. */
function jeab_kontakt_street_keys_equal(string $a, string $b): bool
{
    if ($a === '' || $b === '') {
        return false;
    }
    if ($a === $b) {
        return true;
    }
    $left = jeab_kontakt_street_key($a);
    $right = jeab_kontakt_street_key($b);
    return $left !== '' && $left === $right;
}

/**
 * @return array{street:string,street_key:string,postal:string,city:string,postal_invalid:bool}
 */
/** «Ingen adress», «Saknar adress», «-» och liknande platshållare är en tom adress. */
function jeab_kontakt_address_placeholder(string $raw): bool
{
    $t = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw), 'UTF-8');
    $t = trim($t, " .,:;-–—");

    return $t === '' || preg_match('/^(ingen|saknar|utan|okänd|okand)( (gatu)?adress)?$|^(adress )?(saknas|okänd|okand)$|^n\/?a$/u', $t) === 1;
}

function jeab_kontakt_parse_address(string $raw): array
{
    if (jeab_kontakt_address_placeholder($raw)) {
        $raw = '';
    }
    $raw = trim(preg_replace('/\s+/u', ' ', str_replace(["\r", "\n"], ' ', $raw)) ?? $raw);
    $raw = jeab_kontakt_strip_country($raw);
    $street = $raw;
    $postal = '';
    $city = '';
    $invalid = false;
    $scan = jeab_kontakt_postal_scan($raw);
    if ($scan['offset'] >= 0) {
        $street = trim(substr($raw, 0, $scan['offset']), " ,");
        $city = trim(substr($raw, $scan['offset'] + $scan['length']), " ,");
        if ($scan['invalid']) {
            $invalid = true;
        } else {
            $postal = $scan['postal'];
        }
    }
    if ($city === '' && str_contains($street, ',')) {
        $parts = array_values(array_filter(array_map('trim', explode(',', $street)), static fn(string $p): bool => $p !== ''));
        $last = $parts !== [] ? (string) $parts[count($parts) - 1] : '';
        if ($last !== '' && preg_match('/\d/u', $last) !== 1 && count($parts) > 1) {
            array_pop($parts);
            $city = $last;
            $street = implode(', ', $parts);
        }
    }
    return [
        'street' => $street,
        'street_key' => jeab_kontakt_street_key($street),
        'postal' => $postal,
        'city' => jeab_kontakt_city_name($city),
        'postal_invalid' => $invalid,
    ];
}

/**
 * Den enda normaliseringen av e-post som kontaktnyckel. Trim, gemener, «Namn <a@b.se>» och
 * mailto: skalas av. Tomt eller ogiltigt (inget eller flera @, blanktecken, tom lokal del
 * eller domän) blir ''. Tom sträng är aldrig en nyckel. Kontakter utan mejl matchas vidare
 * på telefon och namn (jeab_kontakt_find) och har ingen unik nyckel.
 */
function jeab_kontakt_email(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/<([^<>]+)>\s*$/u', $raw, $m) === 1) {
        $raw = trim($m[1]);
    }
    if (stripos($raw, 'mailto:') === 0) {
        $raw = trim(substr($raw, 7));
    }
    $raw = jeab_norm_email($raw);
    if (substr_count($raw, '@') !== 1 || preg_match('/\s/u', $raw) === 1) {
        return '';
    }
    [$local, $domain] = explode('@', $raw, 2);
    if ($local === '' || $domain === '') {
        return '';
    }
    return $raw;
}

/** Alias med det nya namnet. Samma regel som jeab_kontakt_email. */
function jeab_contact_email_key(string $raw): string
{
    return jeab_kontakt_email($raw);
}

/**
 * Alla normaliserade mejl på en kontaktrad (ägare, email, email2, email3).
 *
 * @param array<string, mixed> $row
 * @return list<string>
 */
function jeab_kontakt_row_email_keys(array $row): array
{
    $out = [];
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (is_array($owner)) {
            $out[] = jeab_kontakt_email((string) ($owner['email'] ?? ''));
        }
    }
    foreach (['email', 'email2', 'email3'] as $col) {
        $out[] = jeab_kontakt_email((string) ($row[$col] ?? ''));
    }
    $out = array_values(array_unique(array_filter($out, static fn(string $e): bool => $e !== '')));
    return $out;
}

/**
 * Namnet är en e-postadress. Gäller både konsumentpost och en privat domän.
 * En sådan sträng är aldrig ett varumärke eller ett bolag.
 */
function jeab_kontakt_name_is_email(string $name): bool
{
    $name = trim($name);
    if ($name === '' || !str_contains($name, '@')) {
        return false;
    }
    if (filter_var($name, FILTER_VALIDATE_EMAIL) !== false) {
        return true;
    }
    return preg_match('/\s/u', $name) !== 1;
}

/**
 * Förnamnsdelen har mer än ett ord och inget av orden är en ort.
 * «Anna Maria» är ett dubbelt förnamn. «Anna Berg» är förnamn och efternamn.
 */
function jeab_kontakt_double_first_name(string $name): bool
{
    $tokens = jeab_kontakt_name_tokens($name);
    if (count($tokens) < 2) {
        return false;
    }
    foreach ($tokens as $token) {
        if (jeab_kontakt_place_canon($token) !== '') {
            return false;
        }
    }
    return true;
}

function jeab_kontakt_phone(string $raw): string
{
    return jeab_norm_phone($raw);
}

/**
 * Egna adresser, plattformar, Fortnox och kurs-/nyhetsbrevsavsändare är aldrig en kontaktnyckel.
 * Samma filter som inkorgen, plus domänerna som torrkörningen slog ihop på.
 */
function jeab_kontakt_address_blocked(string $email): bool
{
    $email = jeab_kontakt_email($email);
    if ($email === '' || !str_contains($email, '@')) {
        return false;
    }
    if (function_exists('jeab_inkorg_address_blocked') && jeab_inkorg_address_blocked($email)) {
        return true;
    }
    $dom = mb_strtolower((string) substr(strrchr($email, '@') ?: '', 1));
    $blocked = [
        'jarfalla.example.test', 'malmo.example.test', 'flowstack.example.test', 'mail.grokbot.com', 'kalkyl.jarfalla.example.test',
        'wix-forms.com', 'wix.com', 'stores-emails.com',
        'leadkit.com', 'nettbureau.no', 'nettbureau.se',
        'weply.se', 'weply.dk',
        'drip.se', 'getdrip.com', 'drip.com',
        'hemsol.se', 'mandrillapp.com',
        'fortnox.se',
        'mailchimp.com', 'list-manage.com', 'mcsv.net',
        'sendgrid.net', 'sendgrid.com',
        'teachable.com', 'kajabi.com', 'thinkific.com', 'learnworlds.com',
        'substack.com', 'beehiiv.com',
        'mailerlite.com', 'convertkit.com', 'klaviyo.com',
        'campaignmonitor.com', 'createsend.com', 'cmail1.com',
        'constantcontact.com',
        'getresponse.com', 'activecampaign.com',
        'hubspot.com', 'hubspotemail.net',
        'mailgun.org', 'mailgun.com',
    ];
    foreach ($blocked as $domain) {
        if ($dom === $domain || str_ends_with($dom, '.' . $domain)) {
            return true;
        }
    }
    if (str_contains($dom, 'crm.wix')) {
        return true;
    }
    $local = (string) strstr($email, '@', true);
    return preg_match('/^(?:newsletter|nyhetsbrev|kurs|course|courses|utbildning|unsubscribe)(?:[.+_-]|$)/', $local) === 1;
}

/** Visningsnamn som hör till en plattform, inte till en kund. «Elias på HemSol» ingår. */
function jeab_kontakt_platform_name(string $name): bool
{
    $name = trim($name);
    if ($name === '') {
        return false;
    }
    if (function_exists('jeab_auto_unresolved_name') && jeab_auto_unresolved_name($name)) {
        return true;
    }
    $fold = jeab_kontakt_fold_text($name);
    if (preg_match('/\b(?:pa|via|fran|from)\s+(?:hemsol|wix|leadkit|nettbureau|weply|drip|mandrill|fortnox|offerta|mailchimp|sendgrid)\b/u', $fold) === 1) {
        return true;
    }
    if (preg_match('/\b(?:hemsol|leadkit|weply|wix|nettbureau|mandrill|mailchimp|sendgrid|drip|fortnox|offerta)\b/u', $fold) === 1) {
        return true;
    }
    return preg_match('/^(?:hemsol|wix(?:\s+forms?)?|leadkit|nettbureau|weply|drip|mandrill|fortnox|offerta|mailchimp|sendgrid|formul[aä]r|formular|form|crm|nyhetsbrev|newsletter|kurs|course|courses|utbildning)(?:\s|$)/u', $fold) === 1;
}

/** Kurs och nyhetsbrev är inte en person. Bolag (AB, BRF, HB) är en företagskontakt, inte ett hopp. */
function jeab_kontakt_nonperson_name(string $name): bool
{
    $fold = jeab_kontakt_fold_text($name);
    if ($fold === '') {
        return false;
    }
    return preg_match('/utbildning|kompetens|onlinestudier/u', $fold) === 1;
}

/** BRF, AB, HB, bostadsrättsförening eller samfällighet. Ordet ska vara ett eget ord. */
function jeab_kontakt_company_name(string $name): bool
{
    if (jeab_kontakt_name_is_email($name)) {
        return false;
    }
    $fold = jeab_kontakt_fold_text($name);
    if ($fold === '') {
        return false;
    }
    if (str_contains($fold, 'bostadsrattsforening') || str_contains($fold, 'samfallighet')) {
        return true;
    }
    foreach (jeab_kontakt_name_tokens($name) as $token) {
        if (in_array($token, ['brf', 'ab', 'hb', 'samfallighet'], true)) {
            return true;
        }
    }
    return false;
}

/**
 * Bolag utan person: AB/HB, produktord eller ett hopskrivet varumärke.
 * Samfällighet och BRF är kunder. En e-postadress är inte ett bolag.
 */
function jeab_kontakt_obvious_company_name(string $name): bool
{
    $name = trim($name);
    if ($name === '' || jeab_kontakt_name_is_email($name) || jeab_kontakt_customer_company_name($name)) {
        return false;
    }
    if (jeab_kontakt_company_name($name)) {
        return true;
    }
    $fold = jeab_kontakt_fold_text($name);
    if ($fold !== '' && !preg_match('/\s/u', $name) && preg_match('/\p{Ll}\p{Lu}/u', $name) === 1) {
        foreach (['solar', 'erbjud', 'vaxel', 'inverter', 'montage'] as $stem) {
            if (str_contains($fold, $stem)) {
                return true;
            }
        }
    }
    $tokens = jeab_kontakt_name_tokens($name);
    if ($tokens === []) {
        return false;
    }
    $words = ['vaxelriktare', 'inverter', 'montage', 'solar', 'erbjudande', 'erbjudanden'];
    foreach ($tokens as $token) {
        $hit = false;
        foreach ($words as $word) {
            if ($token === $word || str_contains($token, $word)) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            return false;
        }
    }
    return true;
}

/** Samfällighet och BRF är en kund, inte en leverantör. AB och HB räknas inte hit. */
function jeab_kontakt_customer_company_name(string $name): bool
{
    $fold = jeab_kontakt_fold_text($name);
    if ($fold === '') {
        return false;
    }
    if (str_contains($fold, 'bostadsrattsforening') || str_contains($fold, 'samfallighet')) {
        return true;
    }
    foreach (jeab_kontakt_name_tokens($name) as $token) {
        if (in_array($token, ['brf', 'samfallighet'], true)) {
            return true;
        }
    }
    return false;
}

/** Längsta bolagsnamnet bland posterna. Tomt när ingen ägare är ett bolag. */
function jeab_kontakt_company_label(array $members): string
{
    $best = '';
    $consider = static function (string $name) use (&$best): void {
        $name = trim($name);
        if ($name === '' || !jeab_kontakt_company_name($name)) {
            return;
        }
        if ($best === '' || mb_strlen($name) > mb_strlen($best)) {
            $best = $name;
        }
    };
    foreach ($members as $member) {
        if (!is_array($member)) {
            continue;
        }
        $consider((string) ($member['name'] ?? ''));
        foreach ((array) ($member['extra_owners'] ?? []) as $extra) {
            if (is_array($extra)) {
                $consider((string) ($extra['name'] ?? ''));
            }
        }
    }
    return $best;
}

/** «Box 123» och postbox är ingen gatuadress. */
function jeab_kontakt_is_po_box(string $street): bool
{
    $fold = jeab_kontakt_fold_text($street);
    $fold = str_replace(['.', ','], ' ', $fold);
    $fold = preg_replace('/\s+/u', ' ', trim($fold)) ?? trim($fold);
    return $fold !== '' && preg_match('/\b(?:box|postbox|postfack|pobox)\s*\d+/u', $fold) === 1;
}

/**
 * Apostrof, bindestreck och annan skiljetecken jämförs som mellanslag.
 * «O'C», «O C» och «O-C» blir samma teckenföljd.
 *
 * @return list<string>
 */
function jeab_kontakt_name_tokens(string $name): array
{
    $folded = jeab_kontakt_fold_text($name);
    $folded = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $folded) ?? $folded;
    $out = [];
    foreach (preg_split('/\s+/u', trim($folded)) ?: [] as $word) {
        $token = preg_replace('/[^a-z0-9]+/u', '', (string) $word) ?? '';
        if ($token !== '') {
            $out[] = $token;
        }
    }
    return $out;
}

function jeab_kontakt_name_longer(string $candidate, string $current): bool
{
    $next = jeab_kontakt_name_tokens($candidate);
    $have = jeab_kontakt_name_tokens($current);
    if (count($next) !== count($have)) {
        return count($next) > count($have);
    }
    return mb_strlen(trim($candidate)) > mb_strlen(trim($current));
}

function jeab_kontakt_pnr_digits(string $raw): string
{
    return preg_replace('/\D+/', '', $raw) ?? '';
}

function jeab_kontakt_test_email(string $email): bool
{
    $email = jeab_kontakt_email($email);
    if ($email === '') {
        return false;
    }
    if (function_exists('jeab_auto_test_email') && jeab_auto_test_email($email)) {
        return true;
    }
    $local = (string) strstr($email, '@', true);
    $domain = (string) substr(strrchr($email, '@') ?: '', 1);
    if (in_array($domain, ['example.com', 'example.org', 'example.net', 'example.se', 'test.com', 'test.se'], true)) {
        return true;
    }
    return preg_match('/^(?:test|testar|testing|testkund)(?:[.+_-]|$)/', $local) === 1;
}

function jeab_kontakt_has_test_word(string $value): bool
{
    $fold = jeab_kontakt_fold_text($value);
    return $fold !== '' && str_contains($fold, 'test');
}

/** «gatan 5» och «gatan vägen 5» saknar ett riktigt gatunamn. Ordens mellanrum räknas, inte den hopslagna nyckeln. */
function jeab_kontakt_street_placeholder(string $street): bool
{
    $text = jeab_kontakt_fold_text($street);
    $text = str_replace([',', ';', '.'], ' ', $text);
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    $name = preg_replace('/\s+\d+\s*[a-z]?(?:\s+(?:lgh|tr|nb|bv|van).*)?$/u', '', $text) ?? $text;
    $name = trim($name);
    if ($name === '') {
        return false;
    }
    $words = array_values(array_filter(preg_split('/\s+/u', $name) ?: [], static fn(string $word): bool => $word !== ''));
    if ($words === []) {
        return false;
    }
    foreach ($words as $word) {
        if (!in_array($word, ['gata', 'gatan', 'vag', 'vagen', 'road', 'street'], true)) {
            return false;
        }
    }
    return true;
}

/**
 * «t.», «t. 2.», «l.», «f.», «0.» och «1.» är testdata.
 * Ett ensamt förnamn utan punkt, till exempel «U» eller «Bo», kan vara en initial.
 */
function jeab_kontakt_name_is_junk(string $name): bool
{
    $folded = jeab_kontakt_fold_text($name);
    $compact = preg_replace('/\s+/u', '', $folded) ?? '';
    if ($compact !== '' && preg_match('/^[a-z0-9]{1,2}\.+$/u', $compact) === 1) {
        return true;
    }
    if ($compact !== '' && preg_match('/^[0-9]{1,2}$/u', $compact) === 1) {
        return true;
    }
    $tokens = jeab_kontakt_name_tokens($name);
    if ($tokens === []) {
        return false;
    }
    if (count($tokens) === 1 && $tokens[0] === 't') {
        return true;
    }
    return count($tokens) === 2 && strlen($tokens[0]) === 1 && preg_match('/^\d+$/', $tokens[1]) === 1;
}

/** Testbokningar: «T S A/B/C» och «T B A/B/C». */
function jeab_kontakt_name_is_test_booking(string $name): bool
{
    $tokens = jeab_kontakt_name_tokens($name);
    if (count($tokens) !== 3 || $tokens[0] !== 't' || !in_array($tokens[1], ['s', 'b'], true)) {
        return false;
    }
    return in_array($tokens[2], ['a', 'b', 'c'], true);
}

function jeab_kontakt_token_has_vowel(string $token): bool
{
    return preg_match('/[aeiouy]/', $token) === 1;
}

/** Tangentbordsgång på en rad, till exempel «asdf», «qwer» och «sdfg». Kortare än fyra tecken räknas inte. */
function jeab_kontakt_token_is_keyboard(string $token): bool
{
    if (strlen($token) < 4) {
        return false;
    }
    $rows = ['qwertyuiop', 'asdfghjkl', 'zxcvbnm', 'poiuytrewq', 'lkjhgfdsa', 'mnbvcxz'];
    foreach ($rows as $row) {
        if (str_contains($row, $token)) {
            return true;
        }
    }
    return false;
}

/**
 * Lång tangentbordsmos, till exempel «qwrtzpkxvbn» och «asdgasdg».
 * Ett vanligt efternamn med vokaler lämnas kvar.
 */
function jeab_kontakt_token_is_keyboard_mash(string $token): bool
{
    if (strlen($token) < 8 || preg_match('/[^a-z]/', $token) === 1) {
        return false;
    }
    if (jeab_kontakt_token_is_keyboard($token)) {
        return true;
    }
    if (preg_match('/^(.{3,6})\1+$/', $token) === 1) {
        return true;
    }
    $rows = ['qwertyuiop', 'asdfghjkl', 'zxcvbnm'];
    $pos = [];
    foreach ($rows as $ri => $row) {
        $n = strlen($row);
        for ($i = 0; $i < $n; $i++) {
            $pos[$row[$i]] = [$ri, $i];
        }
    }
    $adjacent = 0;
    $pairs = 0;
    $len = strlen($token);
    for ($i = 1; $i < $len; $i++) {
        $left = $pos[$token[$i - 1]] ?? null;
        $right = $pos[$token[$i]] ?? null;
        if ($left === null || $right === null) {
            continue;
        }
        $pairs++;
        $sameRow = $left[0] === $right[0] && abs($left[1] - $right[1]) <= 1;
        if ($sameRow) {
            $adjacent++;
        }
    }
    if ($pairs >= 7 && ($adjacent / $pairs) >= 0.55) {
        return true;
    }
    $vowels = preg_match_all('/[aeiouy]/', $token);
    return $len >= 10 && is_int($vowels) && ($vowels * 5) < $len;
}

/** Platshållare som sol, batteri, laddare, fjärde, namn2. Bara hela ordet. */
function jeab_kontakt_token_is_placeholder(string $token): bool
{
    if (in_array($token, [
        'kund', 'kundnamn', 'test', 'hej', 'testperson', 'testkund',
        'fornamn', 'efternamn', 'namn', 'xxx', 'abc',
        'asdf', 'qwer', 'qwerty', 'sdfg', 'zxcv',
        'sol', 'batteri', 'batterier', 'laddare', 'laddbox', 'fjarde',
        'solcell', 'solceller', 'inverter', 'produkt', 'produkter', 'asdgasdg',
    ], true)) {
        return true;
    }
    return preg_match('/^(?:namn|test)\d+$/', $token) === 1;
}

/**
 * Kort nonsens som «Ff har»: ett upprepat tecken och inget ord som ser ut som ett namn.
 * «Bo Ek» och «Ng Li» lämnas kvar. Initialer («S N») lämnas kvar.
 *
 * @param list<string> $tokens
 */
function jeab_kontakt_name_is_short_nonsense(array $tokens): bool
{
    if ($tokens === [] || count($tokens) > 4) {
        return false;
    }
    $letters = 0;
    $suspicious = false;
    foreach ($tokens as $token) {
        $letters += strlen($token);
        if (strlen($token) >= 2 && preg_match('/^(.)\1+$/', $token) === 1) {
            $suspicious = true;
        }
    }
    if (!$suspicious || $letters > 8) {
        return false;
    }
    $fillers = ['har', 'hej', 'test', 'xxx', 'foo', 'bar', 'baz', 'abc', 'kund', 'namn', 'asdf', 'qwer', 'sdfg'];
    foreach ($tokens as $token) {
        if (strlen($token) === 1 || in_array($token, $fillers, true)) {
            continue;
        }
        if (jeab_kontakt_token_has_vowel($token) && preg_match('/^(.)\1+$/', $token) !== 1) {
            return false;
        }
    }
    return true;
}

/**
 * Platshållare och tangentbordsskräp. Hellre släppa igenom ett kort riktigt namn än att kasta det.
 * «Kund», «Kundnamn», «Test», «hej», «sdfg», «asdf», «qwer», «Ff har».
 */
function jeab_kontakt_name_is_garbage(string $name): bool
{
    $name = jeab_kontakt_normalize_person_name($name)['name'];
    if (jeab_kontakt_name_is_junk($name)) {
        return true;
    }
    $tokens = jeab_kontakt_name_tokens($name);
    if ($tokens === []) {
        return false;
    }
    $onlyPlaceholder = true;
    foreach ($tokens as $token) {
        if (!jeab_kontakt_token_is_placeholder($token)) {
            $onlyPlaceholder = false;
            break;
        }
    }
    if ($onlyPlaceholder) {
        return true;
    }
    foreach ($tokens as $token) {
        if (jeab_kontakt_token_is_keyboard($token) || jeab_kontakt_token_is_keyboard_mash($token)) {
            return true;
        }
        if (strlen($token) >= 4 && !jeab_kontakt_token_has_vowel($token)) {
            return true;
        }
    }
    return jeab_kontakt_name_is_short_nonsense($tokens);
}

/** @param array<string, mixed> $item */
function jeab_kontakt_source_is_test(array $item): bool
{
    $norm = jeab_kontakt_normalize_person_name((string) ($item['name'] ?? ''), trim((string) ($item['city'] ?? '')));
    $item['name'] = $norm['name'];
    if (jeab_kontakt_test_email((string) ($item['email'] ?? ''))) {
        return true;
    }
    if (jeab_kontakt_has_test_word((string) ($item['street'] ?? '')) || jeab_kontakt_has_test_word((string) ($item['name'] ?? ''))) {
        return true;
    }
    foreach ((array) ($item['extra_owners'] ?? []) as $extra) {
        if (is_array($extra) && jeab_kontakt_has_test_word((string) ($extra['name'] ?? ''))) {
            return true;
        }
    }
    $name = (string) ($item['name'] ?? '');
    if (jeab_kontakt_street_placeholder((string) ($item['street'] ?? ''))) {
        return true;
    }
    if (jeab_kontakt_name_is_garbage($name) || jeab_kontakt_name_is_test_booking($name)) {
        return true;
    }
    foreach ((array) ($item['extra_owners'] ?? []) as $extra) {
        if (!is_array($extra)) {
            continue;
        }
        $extraName = (string) ($extra['name'] ?? '');
        if (jeab_kontakt_name_is_garbage($extraName) || jeab_kontakt_name_is_test_booking($extraName)) {
            return true;
        }
    }
    return false;
}

/** Standardlista. Config `kontakter.blocked_phones` lägger till fler, den här ligger kvar. */
function jeab_kontakt_default_blocked_phones(): array
{
    return ['+46700000003'];
}

/** Bolagets och personalens nummer, normaliserade till E.164. De är aldrig en kontaktnyckel. */
function jeab_kontakt_own_phones(): array
{
    $nums = [];
    $add = static function (string $raw) use (&$nums): void {
        $norm = jeab_kontakt_phone($raw);
        if ($norm !== '') {
            $nums[$norm] = true;
        }
    };
    foreach (jeab_kontakt_default_blocked_phones() as $phone) {
        $add($phone);
    }
    if (function_exists('jeab_meab_confirm_sms_from')) {
        $add(jeab_meab_confirm_sms_from());
    }
    if (function_exists('jeab_suresms_config')) {
        $sms = jeab_suresms_config();
        foreach (['from', 'from_malmo', 'sender', 'phone', 'telefon'] as $key) {
            if (isset($sms[$key]) && is_string($sms[$key])) {
                $add($sms[$key]);
            }
        }
    }
    $cfg = function_exists('jeab_config') ? jeab_config() : [];
    $kontakter = is_array($cfg['kontakter'] ?? null) ? $cfg['kontakter'] : [];
    $listedPhones = $kontakter['blocked_phones'] ?? null;
    if (is_array($listedPhones)) {
        foreach ($listedPhones as $row) {
            if (is_string($row)) {
                $add($row);
            }
        }
    }
    foreach (['sms', 'suresms', 'settings'] as $section) {
        $block = $cfg[$section] ?? null;
        if (!is_array($block)) {
            continue;
        }
        foreach (['from', 'from_malmo', 'sender', 'phone', 'telefon', 'mobil'] as $key) {
            if (isset($block[$key]) && is_string($block[$key])) {
                $add($block[$key]);
            }
        }
    }
    foreach (['people', 'staff', 'users'] as $section) {
        $rows = $cfg[$section] ?? null;
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (['phone', 'telefon', 'mobile', 'mobil'] as $key) {
                if (isset($row[$key]) && is_string($row[$key])) {
                    $add($row[$key]);
                }
            }
        }
    }
    return array_keys($nums);
}

function jeab_kontakt_phone_blocked(string $phone): bool
{
    $phone = jeab_kontakt_phone($phone);
    if ($phone === '') {
        return false;
    }
    return in_array($phone, jeab_kontakt_own_phones(), true);
}

/** @param array<string, mixed> $item @return list<string> */
function jeab_kontakt_record_names(array $item): array
{
    $names = [];
    $push = static function (string $name) use (&$names): void {
        $name = trim($name);
        if ($name !== '') {
            $names[] = $name;
        }
    };
    $push((string) ($item['name'] ?? ''));
    foreach ((array) ($item['extra_owners'] ?? []) as $extra) {
        if (is_array($extra)) {
            $push((string) ($extra['name'] ?? ''));
        }
    }
    return $names;
}

/**
 * Antal olika personer enligt namnregeln. Samma person i flera stavningar räknas en gång.
 *
 * @param list<string> $names
 */
function jeab_kontakt_distinct_people(array $names): int
{
    $reps = [];
    foreach ($names as $name) {
        $name = trim($name);
        if ($name === '' || jeab_kontakt_name_tokens($name) === []) {
            continue;
        }
        $joined = false;
        foreach ($reps as $i => $rep) {
            if (jeab_kontakt_names_same($name, $rep)) {
                if (jeab_kontakt_name_longer($name, $rep)) {
                    $reps[$i] = $name;
                }
                $joined = true;
                break;
            }
        }
        if (!$joined) {
            $reps[] = $name;
        }
    }
    return count($reps);
}

/**
 * E-post eller telefon på fler än två personer som inte är samma enligt namnregeln
 * är en delad kanal. Den används inte som nyckel.
 *
 * @param list<array<string, mixed>> $items
 * @return array{phones:list<string>,emails:list<string>}
 */
function jeab_kontakt_shared_channels(array $items): array
{
    $own = array_fill_keys(jeab_kontakt_own_phones(), true);
    $phones = [];
    $emails = [];
    foreach ($items as $item) {
        $names = jeab_kontakt_record_names($item);
        if ($names === []) {
            continue;
        }
        $phone = jeab_kontakt_phone((string) ($item['phone'] ?? ''));
        if ($phone !== '' && !isset($own[$phone])) {
            foreach ($names as $name) {
                $phones[$phone][] = $name;
            }
        }
        $email = jeab_kontakt_email((string) ($item['email'] ?? ''));
        if ($email !== '' && !jeab_kontakt_address_blocked($email)) {
            foreach ($names as $name) {
                $emails[$email][] = $name;
            }
        }
    }
    $outPhones = [];
    foreach ($phones as $phone => $names) {
        if (jeab_kontakt_distinct_people($names) > 2) {
            $outPhones[] = (string) $phone;
        }
    }
    $outEmails = [];
    foreach ($emails as $email => $names) {
        if (jeab_kontakt_distinct_people($names) > 2) {
            $outEmails[] = (string) $email;
        }
    }
    return ['phones' => $outPhones, 'emails' => $outEmails];
}

/**
 * Ett nummer på fler än två olika personer är en växel, inte en person.
 *
 * @param list<array<string, mixed>> $items
 * @return list<string>
 */
function jeab_kontakt_shared_phones(array $items): array
{
    return jeab_kontakt_shared_channels($items)['phones'];
}

/** Dimvägen 36 och 38 i Järfälla. Config `kontakter.internal_addresses` lägger till fler. */
function jeab_kontakt_default_internal_addresses(): array
{
    return [
        'Dimvägen 36, Järfälla',
        'Dimvägen 38, Järfälla',
    ];
}

/** @return list<array{street_key:string,city:string}> */
function jeab_kontakt_internal_places(): array
{
    $places = [];
    $cfg = function_exists('jeab_config') ? jeab_config() : [];
    $blobs = jeab_kontakt_default_internal_addresses();
    foreach (['company', 'settings'] as $section) {
        $block = $cfg[$section] ?? null;
        if (!is_array($block)) {
            continue;
        }
        foreach (['address', 'adress', 'street', 'gata'] as $key) {
            if (isset($block[$key]) && is_string($block[$key]) && trim($block[$key]) !== '') {
                $blobs[] = $block[$key];
            }
        }
    }
    $kontakter = is_array($cfg['kontakter'] ?? null) ? $cfg['kontakter'] : [];
    foreach (['address', 'adress', 'office_address', 'company_address'] as $key) {
        if (isset($kontakter[$key]) && is_string($kontakter[$key]) && trim($kontakter[$key]) !== '') {
            $blobs[] = $kontakter[$key];
        }
    }
    $listed = $kontakter['internal_addresses'] ?? null;
    if (is_array($listed)) {
        foreach ($listed as $row) {
            if (is_string($row) && trim($row) !== '') {
                $blobs[] = $row;
            } elseif (is_array($row)) {
                $street = trim((string) ($row['street'] ?? $row['address'] ?? $row['adress'] ?? ''));
                if ($street !== '') {
                    $blobs[] = $street;
                }
            }
        }
    }
    foreach ($blobs as $blob) {
        $parsed = jeab_kontakt_parse_address($blob);
        if ($parsed['street_key'] === '') {
            continue;
        }
        $places[] = [
            'street_key' => $parsed['street_key'],
            'city' => jeab_kontakt_fold_text($parsed['city']),
        ];
    }
    return $places;
}

function jeab_kontakt_is_internal_place(string $streetKey, string $city): bool
{
    if ($streetKey === '') {
        return false;
    }
    $cityFold = jeab_kontakt_fold_text(jeab_kontakt_city_name($city));
    foreach (jeab_kontakt_internal_places() as $place) {
        if (!jeab_kontakt_street_keys_equal($place['street_key'], $streetKey)) {
            continue;
        }
        $placeCity = (string) $place['city'];
        if ($placeCity === '' || $cityFold === '' || $placeCity === $cityFold) {
            return true;
        }
    }
    return false;
}

/**
 * Personal från config (users, staff, people).
 * Förnamn och efternamn måste båda stämma. Ett ensamt förnamn räcker bara
 * tillsammans med samma e-post eller telefon som personalen.
 *
 * @return list<array{name:string,email:string,phone:string}>
 */
function jeab_kontakt_staff_directory(): array
{
    $cfg = function_exists('jeab_config') ? jeab_config() : [];
    $out = [];
    foreach (['users', 'staff', 'people'] as $section) {
        $rows = $cfg[$section] ?? null;
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $name = trim(trim((string) ($row['first_name'] ?? $row['firstname'] ?? '')) . ' ' . trim((string) ($row['last_name'] ?? $row['lastname'] ?? '')));
            }
            $email = jeab_kontakt_email((string) ($row['email'] ?? ''));
            $phone = '';
            foreach (['phone', 'telefon', 'mobile', 'mobil'] as $key) {
                if (!isset($row[$key]) || !is_string($row[$key])) {
                    continue;
                }
                $phone = jeab_kontakt_phone($row[$key]);
                if ($phone !== '') {
                    break;
                }
            }
            if ($name === '' && $email === '' && $phone === '') {
                continue;
            }
            $out[] = ['name' => $name, 'email' => $email, 'phone' => $phone];
        }
    }
    return $out;
}

/** @param array<string, mixed> $item */
function jeab_kontakt_source_is_staff(array $item): bool
{
    $names = [];
    $push = static function (string $name) use (&$names): void {
        $name = trim($name);
        if ($name !== '') {
            $names[] = $name;
        }
    };
    $push((string) ($item['name'] ?? ''));
    $push((string) ($item['customer_name'] ?? ''));
    if ($names === []) {
        return false;
    }
    $email = jeab_kontakt_email((string) ($item['email'] ?? ''));
    $phone = jeab_kontakt_phone((string) ($item['phone'] ?? ''));
    foreach (jeab_kontakt_staff_directory() as $staff) {
        $staffTokens = jeab_kontakt_name_tokens((string) $staff['name']);
        $staffFirst = $staffTokens[0] ?? '';
        $staffLast = count($staffTokens) >= 2 ? $staffTokens[count($staffTokens) - 1] : '';
        foreach ($names as $name) {
            $tokens = jeab_kontakt_name_tokens($name);
            if ($tokens === [] || $staffFirst === '' || $tokens[0] !== $staffFirst) {
                continue;
            }
            if ($staffLast !== '' && count($tokens) >= 2 && $tokens[count($tokens) - 1] === $staffLast) {
                return true;
            }
            $emailHit = $email !== '' && (string) $staff['email'] !== '' && $email === (string) $staff['email'];
            $phoneHit = $phone !== '' && (string) $staff['phone'] !== '' && $phone === (string) $staff['phone'];
            if ($emailHit || $phoneHit) {
                return true;
            }
        }
    }
    return false;
}

/** Husnummer 999, eller en gata som uppenbart är påhittad. */
function jeab_kontakt_address_is_fake(array $item): bool
{
    $street = (string) ($item['street'] ?? '');
    $streetKey = (string) ($item['street_key'] ?? '');
    if ($streetKey === '') {
        $streetKey = jeab_kontakt_street_key($street);
    }
    if (preg_match('/\s999[a-z]?$/u', $streetKey) === 1) {
        return true;
    }
    $fold = jeab_kontakt_fold_text($street);
    $fold = str_replace([',', ';', '.'], ' ', $fold);
    $fold = trim(preg_replace('/\s+/u', ' ', $fold) ?? $fold);
    if ($fold === '') {
        return false;
    }
    return preg_match('/(?:^|\s)(?:fejk\w*|fake\w*|pahittad\w*|hittepa\w*)(?:\s|$)/u', $fold) === 1;
}

/** «Ladda batterier med …», «Möte», «Utbildning», «Mässa». Ett personnamn lämnas kvar. */
function jeab_kontakt_event_name(string $name): bool
{
    $fold = jeab_kontakt_fold_text($name);
    $fold = trim(preg_replace('/\s+/u', ' ', $fold) ?? $fold);
    if ($fold === '') {
        return false;
    }
    if (preg_match('/^ladda batterier med\b/u', $fold) === 1) {
        return true;
    }
    return preg_match('/^(?:mote|utbildning|massa)(?:\s|$)/u', $fold) === 1;
}

/** «lagret», «filmning» eller «internt» i ett namn eller en titel. */
function jeab_kontakt_text_is_internal(string $text): bool
{
    $fold = jeab_kontakt_fold_text($text);
    if ($fold === '') {
        return false;
    }
    foreach (['lagret', 'filmning', 'internt'] as $word) {
        if (str_contains($fold, $word)) {
            return true;
        }
    }
    return false;
}

/** @return list<string> */
function jeab_kontakt_internal_job_types(): array
{
    $types = ['internt', 'filmning', 'lager', 'lagret'];
    $cfg = function_exists('jeab_config') ? jeab_config() : [];
    $kontakter = is_array($cfg['kontakter'] ?? null) ? $cfg['kontakter'] : [];
    $listed = $kontakter['internal_job_types'] ?? null;
    if (is_array($listed)) {
        foreach ($listed as $row) {
            if (!is_string($row)) {
                continue;
            }
            $fold = jeab_kontakt_fold_text($row);
            if ($fold !== '') {
                $types[] = $fold;
            }
        }
    }
    return array_values(array_unique($types));
}

function jeab_kontakt_job_is_internal(string $job): bool
{
    $fold = jeab_kontakt_fold_text($job);
    if ($fold === '') {
        return false;
    }
    if (jeab_kontakt_text_is_internal($fold)) {
        return true;
    }
    return in_array($fold, jeab_kontakt_internal_job_types(), true);
}

/** @param array<string, mixed> $item */
function jeab_kontakt_source_internal(array $item): bool
{
    foreach (['name', 'title'] as $field) {
        if (jeab_kontakt_text_is_internal((string) ($item[$field] ?? ''))) {
            return true;
        }
    }
    if (jeab_kontakt_job_is_internal((string) ($item['job_type'] ?? ''))) {
        return true;
    }
    $streetKey = (string) ($item['street_key'] ?? '');
    if ($streetKey === '') {
        $streetKey = jeab_kontakt_street_key((string) ($item['street'] ?? ''));
    }
    return jeab_kontakt_is_internal_place($streetKey, (string) ($item['city'] ?? ''));
}

/**
 * Plattform utan kundkanal, kursavsändare, bolag på AB och boxadress hoppas över helt.
 *
 * @param array<string, mixed> $raw
 * @param array<string, mixed> $clean
 */
function jeab_kontakt_source_skip_nonperson(array $raw, array $clean): bool
{
    $rawName = trim((string) ($raw['name'] ?? ''));
    if (jeab_kontakt_nonperson_name($rawName)) {
        return true;
    }
    foreach ([(string) ($clean['street'] ?? ''), (string) ($raw['street'] ?? '')] as $street) {
        if (jeab_kontakt_is_po_box($street)) {
            return true;
        }
    }
    if (jeab_kontakt_platform_name($rawName) && (string) ($clean['email'] ?? '') === '' && (string) ($clean['phone'] ?? '') === '') {
        return true;
    }
    return false;
}

/** @param array<string, mixed> $item */
function jeab_kontakt_sanitize_source(array $item): array
{
    $item['city'] = jeab_kontakt_city_name((string) ($item['city'] ?? ''));
    $email = jeab_kontakt_email((string) ($item['email'] ?? ''));
    $item['email'] = jeab_kontakt_address_blocked($email) ? '' : $email;
    $item['phone'] = jeab_kontakt_phone((string) ($item['phone'] ?? ''));
    $name = trim((string) ($item['name'] ?? ''));
    $item['name'] = (jeab_kontakt_platform_name($name) || jeab_kontakt_nonperson_name($name)) ? '' : $name;
    $extras = [];
    foreach ((array) ($item['extra_owners'] ?? []) as $extra) {
        if (!is_array($extra)) {
            continue;
        }
        $extraName = trim((string) ($extra['name'] ?? ''));
        if ($extraName === '' || jeab_kontakt_platform_name($extraName) || jeab_kontakt_nonperson_name($extraName)) {
            continue;
        }
        $extra['name'] = $extraName;
        $extras[] = $extra;
    }
    $item['extra_owners'] = $extras;
    return $item;
}

/** @param array<string, mixed> $item */
function jeab_kontakt_source_usable(array $item): bool
{
    return (string) ($item['street_key'] ?? '') !== ''
        || (string) ($item['email'] ?? '') !== ''
        || (string) ($item['phone'] ?? '') !== ''
        || trim((string) ($item['name'] ?? '')) !== '';
}

/**
 * @param list<array<string, mixed>> $items
 * @return array{items:list<array<string,mixed>>,skipped:int,skipped_internal:int,skipped_internal_sources:list<array{kind:string,id:string}>,skipped_nonperson:int,skipped_shared_phone:int,skipped_shared_email:int,unlinked:list<array{kind:string,id:string,reason:string}>}
 */
function jeab_kontakt_prepare_sources(array $items): array
{
    $pending = [];
    $skipped = 0;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $norm = jeab_kontakt_normalize_person_name((string) ($item['name'] ?? ''), trim((string) ($item['city'] ?? '')));
        $item['name'] = $norm['name'];
        if ($norm['city'] !== '' && trim((string) ($item['city'] ?? '')) === '') {
            $item['city'] = jeab_kontakt_city_name($norm['city']);
        }
        if (jeab_kontakt_source_is_test($item)) {
            $skipped++;
            continue;
        }
        $pending[] = $item;
    }
    $rows = [];
    foreach ($pending as $item) {
        $rows[] = ['raw' => $item, 'clean' => jeab_kontakt_sanitize_source($item)];
    }
    $shared = jeab_kontakt_shared_channels(array_map(static fn(array $row): array => $row['clean'], $rows));
    $blockedPhones = array_fill_keys(array_merge(jeab_kontakt_own_phones(), $shared['phones']), true);
    $sharedEmails = array_fill_keys($shared['emails'], true);
    $kept = [];
    $skippedInternal = 0;
    $skippedInternalSources = [];
    $skippedNonperson = 0;
    $skippedSharedPhone = 0;
    $skippedSharedEmail = 0;
    $unlinked = [];
    foreach ($rows as $row) {
        $clean = $row['clean'];
        $raw = is_array($row['raw'] ?? null) ? $row['raw'] : [];
        $phone = (string) ($clean['phone'] ?? '');
        if ($phone !== '' && isset($blockedPhones[$phone])) {
            $clean['phone'] = '';
            $skippedSharedPhone++;
        }
        $email = (string) ($clean['email'] ?? '');
        if ($email !== '' && isset($sharedEmails[$email])) {
            $clean['email'] = '';
            $skippedSharedEmail++;
        }
        if (jeab_kontakt_source_is_staff($raw) || jeab_kontakt_event_name((string) ($raw['name'] ?? '')) || jeab_kontakt_address_is_fake($clean) || jeab_kontakt_source_internal($clean)) {
            $skippedInternal++;
            $label = trim((string) ($raw['name'] ?? ''));
            if ($label === '') {
                $label = trim((string) ($raw['title'] ?? ''));
            }
            if (preg_match('/\d{6,}/', $label) === 1) {
                $label = '';
            }
            $skippedInternalSources[] = [
                'kind' => (string) ($raw['kind'] ?? ''),
                'id' => (string) ($raw['id'] ?? ''),
                'name' => $label,
            ];
            continue;
        }
        if (jeab_kontakt_source_skip_nonperson($row['raw'], $clean)) {
            $skippedNonperson++;
            continue;
        }
        if (!jeab_kontakt_source_usable($clean)) {
            $unlinked[] = [
                'kind' => (string) ($clean['kind'] ?? ''),
                'id' => (string) ($clean['id'] ?? ''),
                'reason' => 'tom',
            ];
            continue;
        }
        $kept[] = $clean;
    }
    return [
        'items' => $kept,
        'skipped' => $skipped,
        'skipped_internal' => $skippedInternal,
        'skipped_internal_sources' => $skippedInternalSources,
        'skipped_nonperson' => $skippedNonperson,
        'skipped_shared_phone' => $skippedSharedPhone,
        'skipped_shared_email' => $skippedSharedEmail,
        'unlinked' => $unlinked,
    ];
}

function jeab_kontakt_lookup_budget(): float
{
    $override = $GLOBALS['_JEAB_KONTAKT_LOOKUP_BUDGET'] ?? null;
    if (is_int($override) || is_float($override) || (is_string($override) && is_numeric($override))) {
        return (float) $override;
    }
    return 2.0;
}

/**
 * Fyller postnummer där det saknas. Samma gata i olika orter slås inte ihop av uppslaget.
 * Budgeten gäller hela körningen. Träffar cachas på gata och ort.
 *
 * @param list<array<string, mixed>> $items
 * @return list<array<string, mixed>>
 */
function jeab_kontakt_enrich_missing_postal(array $items): array
{
    $deadline = microtime(true) + jeab_kontakt_lookup_budget();
    $cache = [];
    foreach ($items as $i => $item) {
        if (!empty($item['postal_invalid']) || (string) ($item['street_key'] ?? '') === '' || (string) ($item['postal'] ?? '') !== '') {
            continue;
        }
        $cacheKey = (string) $item['street_key'] . '|' . jeab_kontakt_fold_text((string) ($item['city'] ?? ''));
        if (!array_key_exists($cacheKey, $cache)) {
            if (microtime(true) >= $deadline) {
                continue;
            }
            $cache[$cacheKey] = jeab_kontakt_lookup_postort((string) ($item['street'] ?? ''), '');
        }
        $found = $cache[$cacheKey];
        if (!is_array($found) || (string) ($found['postal_code'] ?? '') === '') {
            continue;
        }
        $foundCity = jeab_kontakt_city_name((string) ($found['city'] ?? ''));
        $haveCity = jeab_kontakt_city_name((string) ($item['city'] ?? ''));
        if ($haveCity !== '' && $foundCity !== '' && jeab_kontakt_fold_text($haveCity) !== jeab_kontakt_fold_text($foundCity)) {
            continue;
        }
        $items[$i]['postal'] = (string) $found['postal_code'];
        if ($haveCity === '' && $foundCity !== '') {
            $items[$i]['city'] = $foundCity;
        }
        if (trim((string) ($item['fastighet'] ?? '')) === '' && trim((string) ($found['fastighetsbeteckning'] ?? '')) !== '') {
            $items[$i]['fastighet'] = trim((string) $found['fastighetsbeteckning']);
        }
    }
    return $items;
}

function jeab_kontakt_pnr_valid(string $raw): bool
{
    return jeab_norm_pnr($raw) !== '';
}

function jeab_kontakt_pnr_mask(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if (strlen($digits) === 12) {
        $digits = substr($digits, 2);
    }
    if (strlen($digits) < 6) {
        return '';
    }
    return substr($digits, 0, 6) . '-****';
}

function jeab_kontakt_pnr_is_masked(string $raw): bool
{
    return str_contains($raw, '•') || str_contains($raw, '*');
}

function jeab_kontakt_pnr_store(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '' || jeab_kontakt_pnr_is_masked($raw)) {
        return '';
    }
    $digits = jeab_norm_pnr($raw);
    if ($digits === '') {
        return '';
    }
    return substr($digits, 0, 8) . '-' . substr($digits, 8);
}

/**
 * @return array{postal_code:string,city:string,fastighetsbeteckning:string}
 */
function jeab_kontakt_lookup_postort(string $street, string $postal = ''): array
{
    $empty = ['postal_code' => '', 'city' => '', 'fastighetsbeteckning' => ''];
    $street = trim($street);
    if ($street === '') {
        return $empty;
    }
    try {
        $stub = $GLOBALS['_JEAB_KONTAKT_POSTORT_LOOKUP'] ?? null;
        if (is_callable($stub)) {
            $found = $stub($street, $postal);
            return jeab_kontakt_lookup_shape(is_array($found) ? $found : []);
        }
        $provider = 'rot';
        if (function_exists('jeab_config')) {
            $cfg = jeab_config();
            $block = is_array($cfg['kontakter'] ?? null) ? $cfg['kontakter'] : [];
            $provider = trim((string) ($block['lookup_provider'] ?? 'rot'));
            if ($provider === 'off') {
                return $empty;
            }
            if ($provider === 'static') {
                $map = is_array($block['lookup_static'] ?? null) ? $block['lookup_static'] : [];
                $key = jeab_kontakt_street_key($street);
                $hit = $key !== '' && is_array($map[$key] ?? null) ? $map[$key] : [];
                return jeab_kontakt_lookup_shape($hit);
            }
        }
        if ($provider !== 'rot') {
            return $empty;
        }
        $file = __DIR__ . '/address_lookup.php';
        if (is_file($file)) {
            require_once $file;
        }
        if (!function_exists('jeab_rot_data_scraping_file') || !function_exists('jeab_rot_class_address_search')) {
            return $empty;
        }
        if (jeab_rot_data_scraping_file() === '' && !class_exists('LantmaterietAPI', false)) {
            return $empty;
        }
        $query = trim($street . ($postal !== '' ? ' ' . $postal : ''));
        $raw = jeab_rot_class_address_search($query);
        if (!is_array($raw) || $raw === []) {
            return $empty;
        }
        $norm = [];
        if (function_exists('jeab_rot_pick_lookup') && function_exists('jeab_rot_lookup_rows')) {
            $pick = jeab_rot_pick_lookup(jeab_rot_lookup_rows($raw), $query, '');
            if (empty($pick['accepted']) || !is_array($pick['norm'] ?? null)) {
                return $empty;
            }
            $norm = $pick['norm'];
        } elseif (function_exists('jeab_rot_normalize_lookup')) {
            $norm = jeab_rot_normalize_lookup($raw);
        }
        if (function_exists('jeab_ordrar_lookup_from_rot')) {
            $norm = jeab_ordrar_lookup_from_rot($norm);
        }
        if (empty($norm['lookup_exact'])) {
            return $empty;
        }
        return jeab_kontakt_lookup_shape([
            'postal_code' => (string) ($norm['postnummer'] ?? $norm['postal_code'] ?? ''),
            'city' => (string) ($norm['ort'] ?? $norm['city'] ?? ''),
            'fastighetsbeteckning' => (string) ($norm['fastighetsbeteckning'] ?? ''),
        ]);
    } catch (Throwable) {
        return $empty;
    }
}

/** @param array<string, mixed> $found */
function jeab_kontakt_lookup_shape(array $found): array
{
    return [
        'postal_code' => jeab_kontakt_postal((string) ($found['postal_code'] ?? $found['postnummer'] ?? '')),
        'city' => trim((string) ($found['city'] ?? $found['ort'] ?? '')),
        'fastighetsbeteckning' => trim((string) ($found['fastighetsbeteckning'] ?? '')),
    ];
}

/**
 * Träff på normaliserat mejl, före gata. Samma spärrar som tidigare mejlträffar
 * (namn och kanal-adress) om inte $emailDespiteAddress. Flera kontakter med samma mejl
 * (hushåll) slås aldrig ihop här: den vars namn stämmer väljs, annars den första,
 * och gruppen loggas för manuell granskning (kontakter.email_dupes).
 *
 * @param array<string, array<string, mixed>> $contacts
 */
function jeab_kontakt_find_by_email(array $contacts, string $email, string $streetKey, string $postal, string $city, string $name, bool $emailDespiteAddress): ?string
{
    $email = jeab_kontakt_email($email);
    if ($email === '' || jeab_kontakt_address_blocked($email)) {
        return null;
    }
    $candidate = ['id' => 'ny', 'street' => '', 'street_key' => $streetKey, 'postal_code' => $postal, 'city' => $city];
    $hits = [];
    foreach ($contacts as $id => $row) {
        if (!is_array($row) || !in_array($email, jeab_kontakt_row_email_keys($row), true)) {
            continue;
        }
        if (!$emailDespiteAddress && !jeab_kontakt_row_channel_ok($row, $name)) {
            continue;
        }
        if (!$emailDespiteAddress && function_exists('jeab_kontakt_channel_address_block')) {
            $block = jeab_kontakt_channel_address_block($candidate, $row, 'email');
            if ($block !== '') {
                if (function_exists('jeab_kontakt_merge_skip_log')) {
                    jeab_kontakt_merge_skip_log('email', 'ny', (string) $id, $block);
                }
                continue;
            }
        }
        $hits[] = (string) $id;
    }
    if ($hits === []) {
        return null;
    }
    if (count($hits) > 1) {
        if (function_exists('jeab_kontakt_merge_skip_log')) {
            jeab_kontakt_merge_skip_log('email', $hits[0], $hits[1], 'delat mejl, granska');
        }
        if (trim($name) !== '') {
            foreach ($hits as $id) {
                if (jeab_kontakt_party_names_agree($name, jeab_kontakt_row_name($contacts[$id]))) {
                    return $id;
                }
            }
        }
    }
    return $hits[0];
}

/**
 * @param array<string, array<string, mixed>> $contacts
 */
function jeab_kontakt_find(array $contacts, string $streetKey, string $postal, string $email, string $phone, bool $allowStreet = true, string $name = '', string $city = '', bool $requirePostal = false, bool $emailDespiteAddress = false): ?string
{
    if (!$requirePostal && !empty($GLOBALS['_JEAB_LEAD_REQUIRE_POSTAL'])) {
        $requirePostal = true;
    }
    // Mejl är nyckeln och prövas först. Gatan avgör aldrig ensam (Ekholm-fallet).
    $email = jeab_kontakt_email($email);
    $byEmail = jeab_kontakt_find_by_email($contacts, $email, $streetKey, $postal, $city, $name, $emailDespiteAddress);
    if ($byEmail !== null) {
        return $byEmail;
    }
    if ($allowStreet && $streetKey !== '') {
        $hits = [];
        foreach ($contacts as $id => $row) {
            if (!is_array($row)) {
                continue;
            }
            $haveKey = jeab_kontakt_effective_street_key((string) ($row['street'] ?? ''), (string) ($row['street_key'] ?? ''));
            if (!jeab_kontakt_street_keys_equal($haveKey, $streetKey)) {
                continue;
            }
            $havePostal = (string) ($row['postal_code'] ?? '');
            $haveCity = (string) ($row['city'] ?? '');
            $wantPostal = jeab_kontakt_postal($postal);
            $havePostalNorm = jeab_kontakt_postal($havePostal);
            if ($requirePostal) {
                if ($wantPostal === '' || $havePostalNorm === '' || $wantPostal !== $havePostalNorm) {
                    continue;
                }
            } else {
                if ($wantPostal !== '' && $havePostalNorm !== '' && $wantPostal !== $havePostalNorm) {
                    if (function_exists('jeab_kontakt_merge_skip_log')) {
                        jeab_kontakt_merge_skip_log('address', 'ny', (string) $id, 'olika adress');
                    }
                    continue;
                }
                if (!jeab_kontakt_place_confirmed($postal, $city, $havePostal, $haveCity)) {
                    continue;
                }
            }
            if (jeab_kontakt_address_reuse_conflict($row, $name, $email, $phone)) {
                if (function_exists('jeab_kontakt_merge_skip_log')) {
                    jeab_kontakt_merge_skip_log('address', 'ny', (string) $id, 'annan person');
                }
                continue;
            }
            $hits[] = (string) $id;
        }
        if ($hits !== []) {
            if ($postal !== '') {
                foreach ($hits as $id) {
                    if ((string) ($contacts[$id]['postal_code'] ?? '') === $postal) {
                        return $id;
                    }
                }
            }
            if (count($hits) === 1) {
                return $hits[0];
            }
            // Flera adresser: fortsätt med e-post och telefon. Samma mejl ska återanvändas
            // även när gatan också finns på en annan kontakt.
        }
    }
    $channelCandidate = [
        'id' => 'ny',
        'street' => '',
        'street_key' => $streetKey,
        'postal_code' => $postal,
        'city' => $city,
    ];
    if ($email !== '' && !jeab_kontakt_address_blocked($email)) {
        foreach ($contacts as $id => $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowEmail = jeab_kontakt_row_email($row);
            if ($rowEmail === '' || jeab_kontakt_address_blocked($rowEmail) || $rowEmail !== $email) {
                continue;
            }
            if (!$emailDespiteAddress && !jeab_kontakt_row_channel_ok($row, $name)) {
                continue;
            }
            if (!$emailDespiteAddress && function_exists('jeab_kontakt_channel_address_block')) {
                $block = jeab_kontakt_channel_address_block($channelCandidate, $row, 'email');
                if ($block !== '') {
                    jeab_kontakt_merge_skip_log('email', 'ny', (string) $id, $block);
                    continue;
                }
            }
            return (string) $id;
        }
    }
    $wantPhone = jeab_kontakt_phone($phone);
    if ($wantPhone !== '' && !jeab_kontakt_phone_blocked($wantPhone)) {
        foreach ($contacts as $id => $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowPhone = jeab_kontakt_row_phone($row);
            if ($rowPhone === '' || jeab_kontakt_phone_blocked($rowPhone) || $rowPhone !== $wantPhone || !jeab_kontakt_row_channel_ok($row, $name) || !jeab_kontakt_party_names_agree($name, jeab_kontakt_row_name($row))) {
                continue;
            }
            if (function_exists('jeab_kontakt_channel_address_block')) {
                $block = jeab_kontakt_channel_address_block($channelCandidate, $row, 'phone');
                if ($block !== '') {
                    jeab_kontakt_merge_skip_log('phone', 'ny', (string) $id, $block);
                    continue;
                }
            }
            $rowEmail = jeab_kontakt_row_email($row);
            if (jeab_kontakt_phone_reuse_name_block($email, $rowEmail, $name, jeab_kontakt_row_name($row))) {
                continue;
            }
            return (string) $id;
        }
    }
    return null;
}

/**
 * Bara adressen stämmer: en annan person på samma adress blir inte samma kontakt.
 * Konflikt när kontakten har en annan e-post, eller ett namn som inte stämmer med namnet in.
 * Samma e-post eller telefon är ingen konflikt.
 *
 * @param array<string, mixed> $row
 */
function jeab_kontakt_address_reuse_conflict(array $row, string $name, string $email, string $phone): bool
{
    $email = jeab_kontakt_email($email);
    $emails = function_exists('jeab_kontakt_row_emails') ? jeab_kontakt_row_emails($row) : [];
    $one = jeab_kontakt_row_email($row);
    if ($one !== '' && !in_array($one, $emails, true)) {
        $emails[] = $one;
    }
    $emails = array_values(array_filter($emails, static fn($e): bool => is_string($e) && $e !== '' && !jeab_kontakt_address_blocked($e)));
    if ($email !== '' && in_array($email, $emails, true)) {
        return false;
    }
    $wantPhone = jeab_kontakt_phone($phone);
    if ($wantPhone !== '' && !jeab_kontakt_phone_blocked($wantPhone) && jeab_kontakt_row_phone($row) === $wantPhone) {
        return false;
    }
    if ($email !== '' && !jeab_kontakt_address_blocked($email) && $emails !== []) {
        return true;
    }
    $name = trim($name);
    if ($name === '' || jeab_kontakt_name_is_garbage($name) || jeab_kontakt_name_is_email($name)) {
        // Inget mejl, ingen telefon och inget namn som stämmer: bara adressen. Det räcker inte.
        return true;
    }
    $names = [];
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        $n = is_array($owner) ? trim((string) ($owner['name'] ?? '')) : '';
        if ($n !== '' && !jeab_kontakt_name_is_garbage($n) && !jeab_kontakt_name_is_email($n)) {
            $names[] = $n;
        }
    }
    $rowName = jeab_kontakt_row_name($row);
    if ($rowName !== '' && !jeab_kontakt_name_is_garbage($rowName) && !jeab_kontakt_name_is_email($rowName)) {
        $names[] = $rowName;
    }
    if ($names === []) {
        return true;
    }
    foreach ($names as $n) {
        if (jeab_kontakt_party_names_agree($name, $n)) {
            return false;
        }
    }

    return true;
}

/**
 * Samma telefon med olika e-post återanvänds när adressen saknas, om båda namnen är personer.
 * Platshållaren Kund och en e-postadress som namn räknas inte.
 */
function jeab_kontakt_phone_reuse_name_block(string $email, string $rowEmail, string $name, string $rowName): bool
{
    if ($email === '' || $rowEmail === '' || $email === $rowEmail) {
        return false;
    }
    if (jeab_kontakt_name_is_garbage($name) || jeab_kontakt_name_is_garbage($rowName)) {
        return true;
    }

    return jeab_kontakt_name_is_email($name) || jeab_kontakt_name_is_email($rowName);
}

/**
 * Okänd ort. Samma gata och nummer återanvänds bara när e-post eller telefon stämmer.
 * Annars blir det en egen kontakt, som link_audit listar.
 *
 * @param array<string, array<string, mixed>> $contacts
 */
function jeab_kontakt_find_same_street_channel(array $contacts, string $streetKey, string $email, string $phone, string $postal = '', string $street = ''): ?string
{
    $streetKey = trim($streetKey);
    $postal = jeab_kontakt_postal($postal);
    $email = jeab_kontakt_email($email);
    if ($email === '' || jeab_kontakt_address_blocked($email)) {
        $email = '';
    }
    $phone = jeab_kontakt_phone($phone);
    if ($phone === '' || jeab_kontakt_phone_blocked($phone)) {
        $phone = '';
    }
    if ($streetKey === '' || ($email === '' && $phone === '')) {
        return null;
    }
    $ranked = [];
    foreach ($contacts as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        $have = jeab_kontakt_effective_street_key((string) ($row['street'] ?? ''), (string) ($row['street_key'] ?? ''));
        if (!jeab_kontakt_street_keys_equal($have, $streetKey)) {
            continue;
        }
        $rowEmail = jeab_kontakt_row_email($row);
        $rowPhone = jeab_kontakt_row_phone($row);
        $emailHit = $email !== '' && $rowEmail !== '' && $rowEmail === $email;
        $phoneHit = $phone !== '' && $rowPhone !== '' && $rowPhone === $phone;
        if (!$emailHit && !$phoneHit) {
            continue;
        }
        if (function_exists('jeab_kontakt_channel_address_block')) {
            $candidate = [
                'id' => 'ny',
                'street' => $street,
                'street_key' => $streetKey,
                'postal_code' => $postal,
            ];
            $block = jeab_kontakt_channel_address_block($candidate, $row, $emailHit ? 'email' : 'phone');
            if ($block !== '') {
                jeab_kontakt_merge_skip_log($emailHit ? 'email' : 'phone', 'ny', (string) $id, $block);
                continue;
            }
        }
        $ranked[] = [
            'id' => (string) $id,
            'rank' => ($emailHit ? 2 : 0) + ($phoneHit ? 1 : 0),
        ];
    }
    if ($ranked === []) {
        return null;
    }
    usort($ranked, static function (array $left, array $right): int {
        if ($left['rank'] !== $right['rank']) {
            return $right['rank'] <=> $left['rank'];
        }
        return strcmp((string) $left['id'], (string) $right['id']);
    });
    return (string) $ranked[0]['id'];
}

/**
 * Namn utan gata, e-post och telefon. Träffar bara kontakter som fanns före körningen
 * och som själva saknar gata och kanal, så två nya namn inte slås ihop i samma körning.
 *
 * @param array<string, array<string, mixed>> $contacts
 * @param array<string, true> $priorIds
 * @return list<string>
 */
function jeab_kontakt_bare_name_hits(array $contacts, array $priorIds, string $name, string $streetKey, string $email, string $phone): array
{
    if ($streetKey !== '' || $email !== '' || $phone !== '') {
        return [];
    }
    $want = jeab_kontakt_name_tokens($name);
    if ($want === []) {
        return [];
    }
    $hits = [];
    foreach ($contacts as $id => $row) {
        $id = (string) $id;
        if (!isset($priorIds[$id]) || !is_array($row)) {
            continue;
        }
        if (trim((string) ($row['street_key'] ?? '')) !== '') {
            continue;
        }
        if (jeab_kontakt_row_email($row) !== '' || jeab_kontakt_row_phone($row) !== '') {
            continue;
        }
        $ownerName = '';
        foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
            if (!is_array($owner)) {
                continue;
            }
            $candidate = trim((string) ($owner['name'] ?? ''));
            if ($candidate === '') {
                continue;
            }
            if ($ownerName === '' || !empty($owner['contact'])) {
                $ownerName = $candidate;
            }
        }
        if ($ownerName !== '' && jeab_kontakt_name_tokens($ownerName) === $want) {
            $hits[] = $id;
        }
    }
    return $hits;
}

/**
 * Samma person, eller ett namn som saknas. Olika namn är inte samma kund.
 * Samma regel som jeab_inkorg_party_names_agree: ett namn får rymmas i det andra.
 */
function jeab_kontakt_party_names_agree(string $a, string $b): bool
{
    if (function_exists('jeab_inkorg_party_names_agree')) {
        return jeab_inkorg_party_names_agree($a, $b);
    }
    $norm = static function (string $name): string {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name) ?? $name;

        return trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    };
    $a = $norm($a);
    $b = $norm($b);
    if ($a === '' || $b === '') {
        return true;
    }

    return $a === $b || str_contains($a, $b) || str_contains($b, $a);
}

/** mail.grokbot.com är kundens adress på ett testlead. Övriga spärrade domäner är det inte. */
function jeab_kontakt_test_email_kept(string $email): bool
{
    $email = jeab_kontakt_email($email);
    if ($email === '' || !str_contains($email, '@')) {
        return false;
    }
    $dom = mb_strtolower((string) substr(strrchr($email, '@') ?: '', 1));

    return $dom === 'mail.grokbot.com' || str_ends_with($dom, '.mail.grokbot.com');
}

/** jeab+…@mail.grokbot.com. jeab@ utan plus är den egna inkorgen. */
function jeab_kontakt_known_test_address(string $email): bool
{
    $email = jeab_kontakt_email($email);
    if ($email === '' || !str_contains($email, '@')) {
        return false;
    }
    $local = (string) strstr($email, '@', true);
    $dom = mb_strtolower((string) substr(strrchr($email, '@') ?: '', 1));
    if ($dom !== 'mail.grokbot.com' && !str_ends_with($dom, '.mail.grokbot.com')) {
        return false;
    }

    return preg_match('/^jeab\+.+/', $local) === 1;
}

/**
 * Testlead enligt jeab_inkorg_msg_is_test_lead. Ett sådant lead får en egen kontakt.
 *
 * @param array<string, mixed> $hint
 */
function jeab_kontakt_hint_is_test_lead(array $hint): bool
{
    if (!empty($hint['test_lead'])) {
        return true;
    }
    $email = trim((string) ($hint['email'] ?? ''));
    $name = trim((string) ($hint['name'] ?? ''));
    $phone = trim((string) ($hint['phone'] ?? ''));
    $thread = [];
    $tid = trim((string) ($hint['thread_id'] ?? ''));
    if ($tid !== '' && function_exists('jeab_inkorg_thread_meta')) {
        try {
            $meta = jeab_inkorg_thread_meta($tid);
        } catch (Throwable) {
            $meta = null;
        }
        if (is_array($meta)) {
            $thread = $meta;
        }
    }
    $msg = [
        'from' => $email,
        'from_name' => $name,
        'customer_email' => $email,
        'customer_name' => $name,
        'phone' => $phone,
        'subject' => (string) ($hint['subject'] ?? ''),
        'body' => (string) ($hint['body'] ?? ''),
        'labels' => ['is_test' => !empty($hint['is_test'])],
    ];
    if (function_exists('jeab_inkorg_msg_is_test_lead')) {
        return jeab_inkorg_msg_is_test_lead($msg, $thread);
    }
    if (function_exists('jeab_test_lead_fields')) {
        return !empty(jeab_test_lead_fields($msg, $thread)['test']);
    }

    return false;
}

/** En träff på bara e-post eller telefon kräver samma person eller samma efternamn. */
function jeab_kontakt_row_channel_ok(array $row, string $name): bool
{
    $name = trim($name);
    if ($name === '') {
        return true;
    }
    $have = [];
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $ownerName = trim((string) ($owner['name'] ?? ''));
        if ($ownerName !== '') {
            $have[] = $ownerName;
        }
    }
    if ($have === []) {
        return true;
    }
    foreach ($have as $ownerName) {
        if (jeab_kontakt_names_channel_compatible($name, $ownerName)) {
            return true;
        }
    }
    return false;
}

/**
 * E-post för den ägare som är kontaktperson.
 * Finns flaggan contact används den ägaren även när mejlet är tomt.
 * Saknas flaggan används första icke-tomma mejlet, samma person som kontakter-sidan visar.
 *
 * @param array<string, mixed> $row
 */
function jeab_kontakt_row_email(array $row): string
{
    $fallback = '';
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $email = jeab_kontakt_email((string) ($owner['email'] ?? ''));
        if (!empty($owner['contact'])) {
            return $email;
        }
        if ($fallback === '' && $email !== '') {
            $fallback = $email;
        }
    }
    if ($fallback === '') {
        $fallback = jeab_kontakt_email((string) ($row['email'] ?? ''));
    }

    return $fallback;
}

/**
 * Telefon för den ägare som är kontaktperson.
 * Finns flaggan contact används den ägaren även när telefonen är tom.
 * Saknas flaggan används första icke-tomma telefonen.
 *
 * @param array<string, mixed> $row
 */
function jeab_kontakt_row_phone(array $row): string
{
    $fallback = '';
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $phone = jeab_kontakt_phone((string) ($owner['phone'] ?? ''));
        if (!empty($owner['contact'])) {
            return $phone;
        }
        if ($fallback === '' && $phone !== '') {
            $fallback = $phone;
        }
    }
    if ($fallback === '') {
        $fallback = jeab_kontakt_phone((string) ($row['phone'] ?? ''));
    }
    if ($fallback === '') {
        $fallback = jeab_kontakt_phone((string) ($row['phone2'] ?? ''));
    }

    return $fallback;
}

/** Namn på kontaktpersonen. Saknas flaggan används första ägaren som har ett namn. */
function jeab_kontakt_row_name(array $row): string
{
    $fallback = '';
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $name = trim((string) ($owner['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        if ($fallback === '') {
            $fallback = $name;
        }
        if (!empty($owner['contact'])) {
            return $name;
        }
    }
    return $fallback;
}

function jeab_kontakt_new_id(): string
{
    return 'kt_' . bin2hex(random_bytes(8));
}

/**
 * @param array<string, mixed> $hint
 * @return array<string, mixed>
 */
function jeab_kontakt_blank(array $hint): array
{
    $now = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    $name = trim((string) ($hint['name'] ?? ''));
    $pnr = jeab_kontakt_pnr_store((string) ($hint['personnummer'] ?? ''));
    $owner = [
        'name' => $name,
        'personnummer' => $pnr,
        'contact' => true,
        'email' => jeab_kontakt_email((string) ($hint['email'] ?? '')),
        'phone' => jeab_kontakt_phone((string) ($hint['phone'] ?? '')),
    ];
    $row = [
        'id' => jeab_kontakt_new_id(),
        'street' => jeab_kontakt_address_placeholder((string) ($hint['street'] ?? '')) ? '' : trim((string) ($hint['street'] ?? '')),
        'street_key' => jeab_kontakt_address_placeholder((string) ($hint['street'] ?? '')) ? '' : (string) ($hint['street_key'] ?? ''),
        'postal_code' => jeab_kontakt_postal((string) ($hint['postal_code'] ?? '')),
        'city' => trim((string) ($hint['city'] ?? '')),
        'fastighetsbeteckning' => trim((string) ($hint['fastighetsbeteckning'] ?? '')),
        'owners' => [$owner],
        'kind' => (string) ($hint['kind'] ?? 'person') === 'company' ? 'company' : 'person',
        'ovrigt' => '',
        'created_at' => $now,
        'updated_at' => $now,
    ];
    $office = function_exists('jeab_address_company') ? jeab_address_company((string) ($hint['company'] ?? $hint['bolag'] ?? $hint['co'] ?? '')) : '';
    if ($office !== '') {
        $row['company'] = $office;
    }
    $phone2 = jeab_kontakt_phone((string) ($hint['phone2'] ?? ''));
    if ($phone2 !== '') {
        $row['phone2'] = $phone2;
    }
    if (!empty($hint['name_missing'])) {
        $row['name_missing'] = true;
    }
    $source = trim((string) ($hint['source'] ?? ''));
    if ($source !== '') {
        $row['source'] = $source;
    }
    return $row;
}

/**
 * Fyll bara tomma adressfält. Rör inte namn, e-post eller telefon på en träff.
 *
 * @param array<string, mixed> $row
 * @param array<string, mixed> $hint
 * @return array<string, mixed>
 */
function jeab_kontakt_fill_blanks(array $row, array $hint): array
{
    $streetNow = trim((string) ($row['street'] ?? ''));
    $cityNow = trim((string) ($row['city'] ?? ''));
    $postalNow = trim((string) ($row['postal_code'] ?? ''));
    $hintStreet = trim((string) ($hint['street'] ?? ''));
    $hintPostal = trim((string) ($hint['postal_code'] ?? ''));
    $postalForCity = $hintPostal !== '' ? $hintPostal : $postalNow;
    if (function_exists('jeab_name_address_prepare_street')) {
        $hintStreet = jeab_name_address_prepare_street($hintStreet);
    }
    if ($hintStreet !== '' && function_exists('jeab_name_address_street_real') && !jeab_name_address_street_real($hintStreet)) {
        $hintStreet = '';
    }
    $hintCity = trim((string) ($hint['city'] ?? ''));
    if (function_exists('jeab_name_address_accept_city')) {
        $hintCity = jeab_name_address_accept_city($hintCity, $postalForCity);
    } elseif (function_exists('jeab_name_address_clean_city')) {
        $hintCity = jeab_name_address_clean_city($hintCity);
    }
    $streetKind = function_exists('jeab_name_address_street_kind') ? jeab_name_address_street_kind($streetNow) : ($streetNow === '' ? 'empty' : 'real');
    $cityKind = function_exists('jeab_name_address_city_kind') ? jeab_name_address_city_kind($cityNow) : ($cityNow === '' ? 'empty' : 'real');
    if ($streetKind === 'real' && function_exists('jeab_name_address_prepare_street')) {
        $prepared = jeab_name_address_prepare_street($streetNow);
        if ($prepared !== '' && $prepared !== $streetNow && jeab_name_address_street_kind($prepared) === 'real') {
            $row['street'] = $prepared;
            $row['street_key'] = function_exists('jeab_kontakt_street_key') ? jeab_kontakt_street_key($prepared) : (string) ($row['street_key'] ?? '');
            $streetNow = $prepared;
        }
    }
    if ($cityKind === 'placeholder' && function_exists('jeab_name_address_accept_city')) {
        $cleaned = jeab_name_address_accept_city($cityNow, $postalForCity);
        if ($cleaned !== '') {
            $row['city'] = $cleaned;
            $cityNow = $cleaned;
            $cityKind = jeab_name_address_city_kind($cleaned);
        }
    }
    $streetOpen = in_array($streetKind, ['empty', 'placeholder', 'product_title'], true);
    $cityOpen = in_array($cityKind, ['empty', 'placeholder'], true);
    if ($streetOpen && $streetNow !== '' && $hintStreet !== '') {
        $row['street'] = '';
        $row['street_key'] = '';
    }
    if ($cityOpen && $cityNow !== '' && $hintCity !== '' && $hintCity !== $cityNow) {
        $row['city'] = '';
    }
    $hint['street'] = $hintStreet;
    $hint['city'] = $hintCity;
    if ($hintStreet !== '' && function_exists('jeab_kontakt_street_key')) {
        $hint['street_key'] = jeab_kontakt_street_key($hintStreet);
    }
    foreach (['street' => 'street', 'postal_code' => 'postal_code', 'city' => 'city', 'fastighetsbeteckning' => 'fastighetsbeteckning', 'street_key' => 'street_key'] as $field => $from) {
        if (($field === 'street' || $field === 'street_key') && !$streetOpen) {
            continue;
        }
        if ($field === 'city' && !$cityOpen) {
            continue;
        }
        $incoming = trim((string) ($hint[$from] ?? ''));
        if ($field === 'street' && $incoming !== '' && function_exists('jeab_name_address_street_real') && !jeab_name_address_street_real($incoming)) {
            continue;
        }
        if ($field === 'city' && function_exists('jeab_name_address_accept_city')) {
            $incoming = jeab_name_address_accept_city($incoming, $postalForCity);
        }
        if (trim((string) ($row[$field] ?? '')) === '' && $incoming !== '') {
            $row[$field] = $incoming;
        }
    }
    if (trim((string) ($row['postal_code'] ?? '')) !== '') {
        $row['postal_code'] = jeab_kontakt_postal((string) $row['postal_code']);
    }

    return $row;
}

/**
 * Fyller bara tomt namn, tom e-post och tom telefon på kontaktpersonen.
 *
 * @param array<string, mixed> $row
 * @param array<string, mixed> $hint
 * @return array<string, mixed>
 */
function jeab_kontakt_fill_owner_blanks(array $row, array $hint): array
{
    $name = trim((string) ($hint['name'] ?? ''));
    $email = jeab_kontakt_email((string) ($hint['email'] ?? ''));
    if (jeab_kontakt_address_blocked($email) && !(jeab_kontakt_hint_is_test_lead($hint) && jeab_kontakt_test_email_kept($email))) {
        $email = '';
    }
    $phone = jeab_kontakt_phone((string) ($hint['phone'] ?? ''));
    if (jeab_kontakt_phone_blocked($phone)) {
        $phone = '';
    }
    $existingName = jeab_kontakt_row_name($row);
    $nameEmails = [];
    if ($email !== '') {
        $nameEmails[] = $email;
    }
    $rowEmail = jeab_kontakt_row_email($row);
    if ($rowEmail !== '') {
        $nameEmails[] = $rowEmail;
    }
    $existingReal = function_exists('jeab_name_address_name_real')
        ? jeab_name_address_name_real($existingName, $nameEmails)
        : $existingName !== '';
    $hintReal = function_exists('jeab_name_address_name_real')
        ? jeab_name_address_name_real($name, $nameEmails)
        : $name !== '';
    if ($existingReal && $name !== '' && !jeab_kontakt_party_names_agree($existingName, $name)) {
        return $row;
    }
    if (!$existingReal && $existingName !== '' && $name !== '' && !$hintReal && !jeab_kontakt_party_names_agree($existingName, $name)) {
        return $row;
    }
    if ($name === '' && $email === '' && $phone === '') {
        return $row;
    }
    $owners = [];
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (is_array($owner)) {
            $owners[] = $owner;
        }
    }
    $index = 0;
    $found = false;
    foreach ($owners as $i => $owner) {
        if (!empty($owner['contact'])) {
            $index = $i;
            $found = true;
            break;
        }
    }
    if ($owners === []) {
        $owners[] = ['name' => '', 'personnummer' => '', 'contact' => true, 'email' => '', 'phone' => ''];
    } elseif (!$found) {
        $index = 0;
    }
    $changed = false;
    $ownerName = trim((string) ($owners[$index]['name'] ?? ''));
    $ownerReal = function_exists('jeab_name_address_name_real')
        ? jeab_name_address_name_real($ownerName, $nameEmails)
        : $ownerName !== '';
    if ($name !== '' && ($ownerName === '' || (!$ownerReal && $hintReal))) {
        $owners[$index]['name'] = $name;
        $changed = true;
    }
    if (trim((string) ($owners[$index]['email'] ?? '')) === '' && $email !== '') {
        $owners[$index]['email'] = $email;
        $changed = true;
    }
    if (trim((string) ($owners[$index]['phone'] ?? '')) === '' && $phone !== '') {
        $owners[$index]['phone'] = $phone;
        $changed = true;
    }
    if (!$changed) {
        return $row;
    }
    $owners[$index]['contact'] = true;
    $row['owners'] = $owners;
    return $row;
}

/**
 * Påminnelseväg: fyll tom adress, och anteckna en annan adress i Övrigt.
 * En träff på e-post eller telefon skriver inte över gata, postnummer eller ort.
 *
 * @param array<string, mixed> $hint
 * @return array<string, mixed>|null
 */
function jeab_kontakt_note_reminder_address(string $contactId, int $reminderId, array $hint): ?array
{
    $contactId = trim($contactId);
    if ($contactId === '' || $reminderId <= 0) {
        return null;
    }
    if (jeab_kontakt_address_placeholder((string) ($hint['street'] ?? ''))) {
        $hint['street'] = '';
    }
    $parsed = jeab_kontakt_parse_address((string) ($hint['address'] ?? ''));
    if ($parsed['street'] === '' && trim((string) ($hint['street'] ?? '')) !== '') {
        $parsed['street'] = trim((string) $hint['street']);
        $parsed['street_key'] = jeab_kontakt_street_key($parsed['street']);
    }
    if ($parsed['postal'] === '' && trim((string) ($hint['postal_code'] ?? '')) !== '') {
        $scan = jeab_kontakt_postal_scan((string) $hint['postal_code']);
        if (!$scan['invalid'] && $scan['postal'] !== '') {
            $parsed['postal'] = $scan['postal'];
        }
    }
    $city = $parsed['city'] !== ''
        ? jeab_kontakt_city_name($parsed['city'])
        : jeab_kontakt_city_name(trim((string) ($hint['city'] ?? '')));
    $street = trim((string) $parsed['street']);
    $street = preg_replace('/\s*,?\s*(?:sverige|sweden|suede|suède)\s*$/iu', '', $street) ?? $street;
    $street = trim($street, " ,");
    $place = [
        'street' => $street,
        'street_key' => (string) ($parsed['street_key'] ?? ''),
        'postal_code' => (string) ($parsed['postal'] ?? ''),
        'city' => $city,
        'name' => trim((string) ($hint['name'] ?? '')),
        'email' => (string) ($hint['email'] ?? ''),
        'phone' => (string) ($hint['phone'] ?? ''),
    ];
    $saved = jeab_kontakter_mutate(function (array &$contacts) use ($contactId, $reminderId, $place, $street, $city): ?array {
        if (!isset($contacts[$contactId]) || !is_array($contacts[$contactId])) {
            return null;
        }
        $row = $contacts[$contactId];
        $hadStreet = trim((string) ($row['street'] ?? '')) !== '';
        $haveKey = jeab_kontakt_effective_street_key((string) ($row['street'] ?? ''), (string) ($row['street_key'] ?? ''));
        $wantKey = (string) ($place['street_key'] ?? '');
        if ($wantKey === '' && $street !== '') {
            $wantKey = jeab_kontakt_street_key($street);
        }
        $sameStreet = $hadStreet && $street !== '' && jeab_kontakt_street_keys_equal($haveKey, $wantKey);
        $haveCity = jeab_kontakt_city_name((string) ($row['city'] ?? ''));
        $cityDiffers = $haveCity !== '' && $city !== '' && jeab_kontakt_city_key($haveCity) !== jeab_kontakt_city_key($city);
        $otherAddress = $hadStreet && $street !== '' && (!$sameStreet || $cityDiffers);
        if (!$otherAddress) {
            $row = jeab_kontakt_fill_blanks($row, $place);
        }
        $row = jeab_kontakt_fill_owner_blanks($row, $place);
        if ($otherAddress && function_exists('jeab_kontakt_append_ovrigt')) {
            $lineCity = $city;
            $line = 'Annan adress från påminnelse ' . $reminderId . ': ' . $street;
            if ($lineCity !== '') {
                $line .= ', ' . $lineCity;
            }
            $row['ovrigt'] = jeab_kontakt_append_ovrigt((string) ($row['ovrigt'] ?? ''), $line);
        }
        $row['id'] = $contactId;
        $row['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
        $contacts[$contactId] = $row;
        return $row;
    });
    return is_array($saved) ? $saved : null;
}

/**
 * Vilken regel som träffade den här raden, i samma ordning som jeab_kontakt_find.
 * Tom sträng när raden inte är en e-post-, telefon- eller adressträff.
 *
 * @param array<string, mixed> $row
 */
function jeab_kontakt_reuse_rule(array $row, string $streetKey, string $postal, string $email, string $phone, string $name, string $city, bool $allowStreet): string
{
    if ($allowStreet && $streetKey !== '') {
        $haveKey = jeab_kontakt_effective_street_key((string) ($row['street'] ?? ''), (string) ($row['street_key'] ?? ''));
        if (jeab_kontakt_street_keys_equal($haveKey, $streetKey)) {
            $havePostal = (string) ($row['postal_code'] ?? '');
            $haveCity = (string) ($row['city'] ?? '');
            $wantPostal = jeab_kontakt_postal($postal);
            $havePostalNorm = jeab_kontakt_postal($havePostal);
            $postalConflict = $wantPostal !== '' && $havePostalNorm !== '' && $wantPostal !== $havePostalNorm;
            if (!$postalConflict && jeab_kontakt_place_confirmed($postal, $city, $havePostal, $haveCity)) {
                return 'address';
            }
        }
    }
    $channelCandidate = [
        'id' => 'ny',
        'street' => '',
        'street_key' => $streetKey,
        'postal_code' => $postal,
        'city' => $city,
    ];
    if ($email !== '' && !jeab_kontakt_address_blocked($email)) {
        $rowEmail = jeab_kontakt_row_email($row);
        if ($rowEmail !== '' && !jeab_kontakt_address_blocked($rowEmail) && $rowEmail === $email && jeab_kontakt_row_channel_ok($row, $name)) {
            $block = function_exists('jeab_kontakt_channel_address_block')
                ? jeab_kontakt_channel_address_block($channelCandidate, $row, 'email')
                : '';
            if ($block === '') {
                return 'email';
            }
        }
    }
    $wantPhone = jeab_kontakt_phone($phone);
    if ($wantPhone !== '' && !jeab_kontakt_phone_blocked($wantPhone)) {
        $rowPhone = jeab_kontakt_row_phone($row);
        $rowEmail = jeab_kontakt_row_email($row);
        if (
            $rowPhone !== ''
            && !jeab_kontakt_phone_blocked($rowPhone)
            && $rowPhone === $wantPhone
            && jeab_kontakt_row_channel_ok($row, $name)
            && jeab_kontakt_party_names_agree($name, jeab_kontakt_row_name($row))
        ) {
            $block = function_exists('jeab_kontakt_channel_address_block')
                ? jeab_kontakt_channel_address_block($channelCandidate, $row, 'phone')
                : '';
            if ($block === '' && !jeab_kontakt_phone_reuse_name_block($email, $rowEmail, $name, jeab_kontakt_row_name($row))) {
                return 'phone';
            }
        }
    }

    return '';
}

function jeab_kontakt_reuse_caller(): string
{
    $skip = [
        'jeab_kontakt_reuse_caller' => true,
        'jeab_kontakt_find_or_create' => true,
        'jeab_kontakt_dedupe_log' => true,
    ];
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16) as $frame) {
        $fn = (string) ($frame['function'] ?? '');
        if ($fn === '' || isset($skip[$fn]) || str_contains($fn, '{closure')) {
            continue;
        }
        if (str_starts_with($fn, 'jeab_')) {
            return $fn;
        }
    }

    return 'jeab_kontakt_find_or_create';
}

/**
 * Skapar eller återanvänder en kontakt.
 * En träff på e-post eller telefon följer adresspärren: två satta adresser som skiljer sig blir inte samma kontakt.
 * Anropet rör bara den här kontakten. Andra dubbletter slås ihop via kontakter.dedupe.
 *
 * @param array<string, mixed> $hint name, email, phone, address, personnummer, city, postal_code, fastighetsbeteckning
 * @param bool $dedupe false hoppar över den extra träffen som kan fästa hinten på en annan kontakt. Automatiska anrop skickar false och slår inte ihop kontakter.
 * @return array<string, mixed>|null
 */
function jeab_kontakt_find_or_create(array $hint, bool $lookup = true, bool $dedupe = true, bool $fillBlanksOnly = false): ?array
{
    $parsed = jeab_kontakt_parse_address((string) ($hint['address'] ?? ''));
    if ($parsed['street'] === '' && trim((string) ($hint['street'] ?? '')) !== '') {
        $parsed['street'] = trim((string) $hint['street']);
        $parsed['street_key'] = jeab_kontakt_street_key($parsed['street']);
    }
    if ($parsed['postal'] === '' && trim((string) ($hint['postal_code'] ?? '')) !== '') {
        $scan = jeab_kontakt_postal_scan((string) $hint['postal_code']);
        if ($scan['invalid']) {
            $parsed['postal_invalid'] = true;
        } elseif ($scan['postal'] !== '') {
            $parsed['postal'] = $scan['postal'];
        }
    }
    if ($parsed['city'] === '') {
        $parsed['city'] = jeab_kontakt_city_name(trim((string) ($hint['city'] ?? '')));
    } else {
        $parsed['city'] = jeab_kontakt_city_name($parsed['city']);
    }
    $email = jeab_kontakt_email((string) ($hint['email'] ?? ''));
    $testHint = $hint;
    $testHint['email'] = $email;
    $testLead = jeab_kontakt_hint_is_test_lead($testHint);
    if (jeab_kontakt_address_blocked($email) && !($testLead && jeab_kontakt_test_email_kept($email))) {
        $email = '';
    }
    $phone = jeab_kontakt_phone((string) ($hint['phone'] ?? ''));
    if (jeab_kontakt_phone_blocked($phone)) {
        $phone = '';
    }
    $norm = jeab_kontakt_normalize_person_name(trim((string) ($hint['name'] ?? '')), $parsed['city']);
    $name = $norm['name'];
    if ($parsed['city'] === '' && $norm['city'] !== '') {
        $parsed['city'] = jeab_kontakt_city_name($norm['city']);
    }
    if (jeab_kontakt_text_is_internal($name) || jeab_kontakt_is_internal_place($parsed['street_key'], $parsed['city']) || jeab_kontakt_is_po_box($parsed['street']) || jeab_kontakt_nonperson_name($name)) {
        return null;
    }
    if (jeab_kontakt_platform_name($name)) {
        $name = '';
    }
    if ($parsed['street_key'] === '' && $email === '' && $phone === '' && $name === '') {
        return null;
    }
    if (!empty($parsed['postal_invalid'])) {
        $parsed['street_key'] = '';
    }
    $extractCompany = function_exists('jeab_address_company') ? jeab_address_company((string) ($hint['company'] ?? '')) : '';
    $givenPostal = (string) $parsed['postal'];
    $givenCity = (string) $parsed['city'];
    $cityKind = $extractCompany !== '' && function_exists('jeab_address_city_kind') ? jeab_address_city_kind($extractCompany, (string) $parsed['city']) : 'unknown';
    $postalKind = $extractCompany !== '' && function_exists('jeab_address_postal_kind') ? jeab_address_postal_kind($extractCompany, (string) $parsed['postal']) : 'unknown';
    if ($lookup && $extractCompany !== '' && $parsed['street'] !== '' && function_exists('jeab_address_extract') && (
        $parsed['city'] === ''
        || $cityKind === 'outside'
        || ($cityKind === 'inside' && ($parsed['postal'] === '' || $postalKind === 'outside'))
    )) {
        $extracted = jeab_address_extract([
            'company' => $extractCompany,
            'street' => (string) $parsed['street'],
            'city' => (string) $parsed['city'],
            'postal' => (string) $parsed['postal'],
            'name' => $name,
            'thread_id' => (string) ($hint['thread_id'] ?? ''),
        ]);
        if (!empty($extracted['review'])) {
            $hint['address_review'] = true;
        } elseif (!empty($extracted['certain'])) {
            $lookPostal = (string) ($extracted['postal'] ?? '');
            $lookCity = (string) ($extracted['city'] ?? '');
            $normPostal = static function (string $value): string {
                return jeab_kontakt_postal($value);
            };
            $foldCity = static function (string $value): string {
                if (function_exists('jeab_kontakt_city_key')) {
                    return jeab_kontakt_city_key($value);
                }

                return function_exists('jeab_lead_town_key') ? jeab_lead_town_key($value) : mb_strtolower(trim($value));
            };
            $postalConflict = $givenPostal !== '' && $lookPostal !== '' && $normPostal($givenPostal) !== $normPostal($lookPostal);
            $cityConflict = $givenCity !== '' && $lookCity !== '' && $foldCity($givenCity) !== '' && $foldCity($givenCity) !== $foldCity($lookCity);
            if (!$postalConflict && !$cityConflict) {
                if ($parsed['postal'] === '' && $lookPostal !== '') {
                    $parsed['postal'] = $lookPostal;
                }
                if ($parsed['city'] === '' && $lookCity !== '') {
                    $parsed['city'] = $lookCity;
                }
            }
        }
    }
    $legacyLookup = is_callable($GLOBALS['_JEAB_KONTAKT_POSTORT_LOOKUP'] ?? null);
    if (!$legacyLookup && function_exists('jeab_config')) {
        $cfg = jeab_config();
        $block = is_array($cfg['kontakter'] ?? null) ? $cfg['kontakter'] : [];
        $legacyLookup = trim((string) ($block['lookup_provider'] ?? 'rot')) === 'static';
    }
    if ($lookup && $legacyLookup && empty($parsed['postal_invalid']) && ($parsed['postal'] === '' || $parsed['city'] === '' || trim((string) ($hint['fastighetsbeteckning'] ?? '')) === '') && $parsed['street'] !== '') {
        $found = jeab_kontakt_lookup_postort($parsed['street'], $parsed['postal']);
        if ($parsed['postal'] === '' && $found['postal_code'] !== '') {
            $parsed['postal'] = $found['postal_code'];
        }
        if ($parsed['city'] === '' && $found['city'] !== '') {
            $parsed['city'] = $found['city'];
        }
        if (trim((string) ($hint['fastighetsbeteckning'] ?? '')) === '' && $found['fastighetsbeteckning'] !== '') {
            $hint['fastighetsbeteckning'] = $found['fastighetsbeteckning'];
        }
    }
    if ($extractCompany !== '' && $parsed['city'] !== '' && function_exists('jeab_address_city_kind') && jeab_address_city_kind($extractCompany, (string) $parsed['city']) === 'outside') {
        $parsed['city'] = '';
        if (function_exists('jeab_address_postal_kind') && jeab_address_postal_kind($extractCompany, (string) $parsed['postal']) !== 'inside') {
            $parsed['postal'] = '';
        }
        $hint['address_review'] = true;
    } elseif ($parsed['city'] !== '') {
        unset($hint['address_review']);
    }
    $hintOut = [
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'personnummer' => (string) ($hint['personnummer'] ?? ''),
        'street' => $parsed['street'],
        'street_key' => $parsed['street_key'],
        'postal_code' => $parsed['postal'],
        'city' => $parsed['city'],
        'fastighetsbeteckning' => trim((string) ($hint['fastighetsbeteckning'] ?? '')),
        'kind' => jeab_kontakt_company_name($name) ? 'company' : 'person',
        'address_review' => !empty($hint['address_review']),
        'company' => $extractCompany,
        'test_lead' => $testLead,
    ];
    // $dedupe false: bara e-post, telefon och adressuppslag. Ingen extra hopslagningsträff.
    $allowStreet = empty($parsed['postal_invalid']);
    $requirePostal = !empty($hint['require_postal']) || !empty($GLOBALS['_JEAB_LEAD_REQUIRE_POSTAL']);
    $emailDespiteAddress = !empty($hint['email_link']);
    $reusedId = '';
    $reusedRule = '';
    $row = jeab_kontakter_mutate(function (array &$contacts) use ($hintOut, $email, $phone, $allowStreet, $fillBlanksOnly, $extractCompany, $testLead, $requirePostal, $emailDespiteAddress, $dedupe, &$reusedId, &$reusedRule): ?array {
        $id = null;
        $matchedRule = '';
        if ($email !== '' && function_exists('jeab_kontakt_secondary_holder')) {
            $secId = jeab_kontakt_secondary_holder($contacts, $email);
            if ($secId !== '' && isset($contacts[$secId]) && is_array($contacts[$secId])) {
                $reusedId = $secId;
                $reusedRule = 'secondary';
                $contacts[$secId]['id'] = $secId;

                return $contacts[$secId];
            }
        }
        if ($testLead) {
            if ($email !== '') {
                foreach ($contacts as $cid => $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $rowEmail = jeab_kontakt_row_email($row);
                    if ($rowEmail === '' || $rowEmail !== $email) {
                        continue;
                    }
                    if (!$emailDespiteAddress && !jeab_kontakt_party_names_agree((string) $hintOut['name'], jeab_kontakt_row_name($row))) {
                        continue;
                    }
                    if (!$emailDespiteAddress && function_exists('jeab_kontakt_channel_address_block')) {
                        $candidate = [
                            'id' => 'ny',
                            'street' => (string) $hintOut['street'],
                            'street_key' => (string) $hintOut['street_key'],
                            'postal_code' => (string) $hintOut['postal_code'],
                            'city' => (string) $hintOut['city'],
                        ];
                        $block = jeab_kontakt_channel_address_block($candidate, $row, 'email');
                        if ($block !== '') {
                            jeab_kontakt_merge_skip_log('email', 'ny', (string) $cid, $block);
                            continue;
                        }
                    }
                    $id = (string) $cid;
                    $matchedRule = 'email';
                    break;
                }
            }
        } else {
            $id = jeab_kontakt_find($contacts, (string) $hintOut['street_key'], (string) $hintOut['postal_code'], $email, $phone, $allowStreet, (string) $hintOut['name'], (string) $hintOut['city'], $requirePostal, $emailDespiteAddress);
            if ($id !== null && isset($contacts[$id]) && is_array($contacts[$id])) {
                $matchedRule = jeab_kontakt_reuse_rule($contacts[$id], (string) $hintOut['street_key'], (string) $hintOut['postal_code'], $email, $phone, (string) $hintOut['name'], (string) $hintOut['city'], $allowStreet);
            }
        }
        if ($id === null && $testLead && $phone !== '') {
            foreach ($contacts as $cid => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rowPhone = jeab_kontakt_row_phone($row);
                $rowEmail = jeab_kontakt_row_email($row);
                $rowIsTest = function_exists('jeab_test_lead_email_reason') && jeab_test_lead_email_reason($rowEmail) !== '';
                if ($rowPhone === '' || $rowPhone !== $phone || !$rowIsTest || !jeab_kontakt_row_channel_ok($row, (string) $hintOut['name']) || !jeab_kontakt_party_names_agree((string) $hintOut['name'], jeab_kontakt_row_name($row))) {
                    continue;
                }
                if (function_exists('jeab_kontakt_channel_address_block')) {
                    $candidate = [
                        'id' => 'ny',
                        'street' => (string) $hintOut['street'],
                        'street_key' => (string) $hintOut['street_key'],
                        'postal_code' => (string) $hintOut['postal_code'],
                        'city' => (string) $hintOut['city'],
                    ];
                    $block = jeab_kontakt_channel_address_block($candidate, $row, 'phone');
                    if ($block !== '') {
                        jeab_kontakt_merge_skip_log('phone', 'ny', (string) $cid, $block);
                        continue;
                    }
                }
                if (jeab_kontakt_phone_reuse_name_block($email, $rowEmail, (string) $hintOut['name'], jeab_kontakt_row_name($row))) {
                    continue;
                }
                $id = (string) $cid;
                $matchedRule = 'phone';
                break;
            }
        }
        if ($id === null && $dedupe && !$testLead && function_exists('jeab_kontakt_dedupe_match_contact')) {
            $virtual = [
                'street' => (string) $hintOut['street'],
                'street_key' => (string) $hintOut['street_key'],
                'postal_code' => (string) $hintOut['postal_code'],
                'city' => (string) $hintOut['city'],
                'kind' => (string) $hintOut['kind'],
                'owners' => [[
                    'name' => (string) $hintOut['name'],
                    'personnummer' => '',
                    'contact' => true,
                    'email' => $email,
                    'phone' => $phone,
                ]],
            ];
            $id = jeab_kontakt_dedupe_match_contact($contacts, $virtual);
            if ($id !== null && isset($contacts[$id]) && is_array($contacts[$id])) {
                $haveEmails = function_exists('jeab_kontakt_row_emails') ? jeab_kontakt_row_emails($contacts[$id]) : [];
                $matchedRule = ($email !== '' && in_array($email, $haveEmails, true)) ? 'email' : 'address';
                if ($matchedRule === 'address' && jeab_kontakt_address_reuse_conflict($contacts[$id], (string) $hintOut['name'], $email, $phone)) {
                    jeab_kontakt_merge_skip_log('address', 'ny', (string) $id, 'annan person');
                    $id = null;
                    $matchedRule = '';
                }
                if ($requirePostal && $matchedRule === 'address') {
                    $havePostal = jeab_kontakt_postal((string) ($contacts[$id]['postal_code'] ?? ''));
                    $wantPostal = jeab_kontakt_postal((string) $hintOut['postal_code']);
                    if ($havePostal === '' || $wantPostal === '' || $havePostal !== $wantPostal) {
                        $id = null;
                        $matchedRule = '';
                    }
                }
            }
        }
        if ($id !== null && isset($contacts[$id]) && is_array($contacts[$id])) {
            $reusedId = (string) $id;
            $reusedRule = $matchedRule !== '' ? $matchedRule : 'address';
            if ($fillBlanksOnly) {
                $contacts[$id] = jeab_kontakt_fill_owner_blanks($contacts[$id], $hintOut);
            } else {
                $contacts[$id] = jeab_kontakt_fill_blanks($contacts[$id], $hintOut);
                $contacts[$id] = jeab_kontakt_fill_owner_blanks($contacts[$id], $hintOut);
                if (function_exists('jeab_kontakt_take_latest_address')) {
                    $contacts[$id] = jeab_kontakt_take_latest_address($contacts[$id], $hintOut);
                }
            }
            $contacts[$id]['id'] = $id;
            if (!empty($hintOut['address_review'])) {
                $contacts[$id]['address_review'] = true;
            }
            if ($extractCompany !== '' && function_exists('jeab_address_company') && jeab_address_company((string) ($contacts[$id]['company'] ?? '')) === '') {
                $contacts[$id]['company'] = $extractCompany;
            }
            $made = $contacts[$id];
        } else {
            // E-post räcker för en ny kontakt. Utan e-post krävs namn som förut.
            if (trim((string) $hintOut['name']) === '' && $email === '') {
                return null;
            }
            if ((string) $hintOut['street_key'] === '' && $email === '' && $phone === '') {
                return null;
            }
            $made = jeab_kontakt_blank($hintOut);
            if (!empty($hintOut['address_review'])) {
                $made['address_review'] = true;
            }
            $contacts[$made['id']] = $made;
            $reusedId = '';
            $reusedRule = '';
        }
        return $made;
    });
    if (is_array($row) && $reusedId !== '' && $reusedRule !== '' && function_exists('jeab_kontakt_dedupe_log')) {
        jeab_kontakt_dedupe_log('ny', $reusedId, $reusedRule, 'återanvänd', jeab_kontakt_reuse_caller());
    }
    if (is_array($row) && function_exists('jeab_contacts_sole_cache_forget')) {
        jeab_contacts_sole_cache_forget(null);
    }
    return is_array($row) ? $row : null;
}

function jeab_kontakt_stamp_reminder(JeabStore $store, int $id, string $contactId): bool
{
    if ($id <= 0 || $contactId === '') {
        return false;
    }
    $row = $store->getReminder($id);
    $existing = trim((string) ($row['contact_id'] ?? ''));
    if (!$row || ($existing !== '' && $existing !== $contactId)) {
        return false;
    }
    if ($existing === $contactId) {
        return true;
    }
    if ($store instanceof JeabPdoStore) {
        $store->pdo()->prepare('UPDATE reminders SET contact_id = ? WHERE id = ? AND (contact_id IS NULL OR contact_id = \'\')')->execute([$contactId, $id]);
        return true;
    }
    $store->saveReminder($id, ['contact_id' => $contactId]);
    return true;
}

function jeab_kontakt_stamp_booking(JeabStore $store, int $id, string $contactId): bool
{
    if ($id <= 0 || $contactId === '') {
        return false;
    }
    $row = $store->getBooking($id);
    if (!$row || (string) ($row['contact_id'] ?? '') !== '') {
        return false;
    }
    if ($store instanceof JeabPdoStore) {
        $store->pdo()->prepare('UPDATE bookings SET contact_id = ? WHERE id = ? AND (contact_id IS NULL OR contact_id = \'\')')->execute([$contactId, $id]);
        return true;
    }
    $store->saveBooking($id, ['contact_id' => $contactId]);
    return true;
}

/** @param array<string, mixed> $order */
function jeab_kontakt_order_is_closed(array $order): bool
{
    if (!empty($order['allt_klart'])) {
        return true;
    }
    return trim((string) ($order['closed_at'] ?? '')) !== '';
}

/** Sätter contact_id på en öppen order via radlås. Skriver inte om övriga ordrar. */
function jeab_kontakt_stamp_order(int $reminderId, string $contactId): bool
{
    if ($reminderId <= 0 || $contactId === '' || !function_exists('jeab_purchase_patch')) {
        return false;
    }
    $changed = false;
    $result = jeab_purchase_patch($reminderId, function (array $row) use ($contactId, &$changed): ?array {
        if (jeab_kontakt_order_is_closed($row) || (string) ($row['contact_id'] ?? '') !== '') {
            return null;
        }
        $row['contact_id'] = $contactId;
        $changed = true;
        return $row;
    });
    return $changed && is_array($result);
}

/** Sätter contact_id på en inkorgskontakt. Övriga fält lämnas. Redan satt id rörs inte. */
function jeab_kontakt_stamp_inbox(string $id, string $contactId): bool
{
    $id = trim($id);
    $contactId = trim($contactId);
    if ($id === '' || $contactId === '') {
        return false;
    }
    if (function_exists('jeab_inkorg_contacts_archived') && jeab_inkorg_contacts_archived()) {
        return false;
    }
    if (!function_exists('jeab_inkorg_contacts') || !function_exists('jeab_inkorg_locked')) {
        return false;
    }
    $row = jeab_inkorg_contacts()[$id] ?? null;
    if (!is_array($row) || trim((string) ($row['contact_id'] ?? '')) !== '') {
        return false;
    }
    $wrote = false;
    jeab_kontakt_inbox_locked(static function (?array $data) use ($id, $contactId, &$wrote): array {
        $data = is_array($data) ? $data : ['contacts' => []];
        $contacts = is_array($data['contacts'] ?? null) ? $data['contacts'] : [];
        if (!isset($contacts[$id]) || !is_array($contacts[$id]) || trim((string) ($contacts[$id]['contact_id'] ?? '')) !== '') {
            return [false, null];
        }
        $contacts[$id]['contact_id'] = $contactId;
        $data['contacts'] = $contacts;
        $wrote = true;
        return [true, $data];
    });
    return $wrote;
}

/** Tar bara bort contact_id. Övriga fält, tider och stängda ordrar lämnas orörda. */
function jeab_kontakt_clear_reminder(JeabStore $store, int $id): bool
{
    if ($id <= 0) {
        return false;
    }
    if (function_exists('jeab_contacts_strict_assert_link')) {
        jeab_contacts_strict_assert_link('reminder', '');
    }
    $row = $store->getReminder($id);
    if (!$row || trim((string) ($row['contact_id'] ?? '')) === '') {
        return false;
    }
    if ($store instanceof JeabPdoStore) {
        $store->pdo()->prepare('UPDATE reminders SET contact_id = NULL WHERE id = ?')->execute([$id]);
        return true;
    }
    $store->saveReminder($id, ['contact_id' => null]);
    return true;
}

/** Tar bara bort contact_id. Bokningens tid och egna fält lämnas orörda. */
function jeab_kontakt_clear_booking(JeabStore $store, int $id): bool
{
    if ($id <= 0) {
        return false;
    }
    if (function_exists('jeab_contacts_strict_assert_link')) {
        jeab_contacts_strict_assert_link('booking', '');
    }
    $row = $store->getBooking($id);
    if (!$row || trim((string) ($row['contact_id'] ?? '')) === '') {
        return false;
    }
    if ($store instanceof JeabPdoStore) {
        $store->pdo()->prepare('UPDATE bookings SET contact_id = NULL WHERE id = ?')->execute([$id]);
        return true;
    }
    $store->saveBooking($id, ['contact_id' => null]);
    return true;
}

/** Tar bara bort contact_id, även på en stängd order. Övriga orderfält rörs inte. */
function jeab_kontakt_clear_order(int $reminderId): bool
{
    if ($reminderId <= 0 || !function_exists('jeab_purchase_patch')) {
        return false;
    }
    if (function_exists('jeab_contacts_strict_assert_link')) {
        jeab_contacts_strict_assert_link('order', '');
    }
    $changed = false;
    $result = jeab_purchase_patch($reminderId, function (array $row) use (&$changed): ?array {
        if (trim((string) ($row['contact_id'] ?? '')) === '') {
            return null;
        }
        $row['contact_id'] = '';
        $changed = true;
        return $row;
    });
    return $changed && is_array($result);
}

/**
 * Fyller tom gata, postnummer och ort på en redan kopplad kontakt.
 * Namn, e-post och telefon lämnas. Ingenting skrivs om inget fattas.
 *
 * @param array<string, mixed> $existing
 * @param array<string, mixed> $hint
 * @param array<string, mixed> $reminder
 * @return array<string, mixed>
 */
function jeab_kontakt_fill_reminder_place(string $id, array $existing, array $hint, array $reminder): array
{
    $address = trim((string) ($hint['address'] ?? ''));
    if ($address === '') {
        $address = trim((string) ($reminder['address'] ?? ''));
    }
    $parsed = jeab_kontakt_parse_address($address);
    $city = $parsed['city'] !== ''
        ? jeab_kontakt_city_name($parsed['city'])
        : jeab_kontakt_city_name(trim((string) ($hint['city'] ?? $reminder['ort'] ?? '')));
    $postal = $parsed['postal'] !== ''
        ? $parsed['postal']
        : jeab_kontakt_postal((string) ($hint['postal_code'] ?? $reminder['postal'] ?? ''));
    $place = [
        'street' => $parsed['street'],
        'street_key' => $parsed['street_key'],
        'postal_code' => $postal,
        'city' => $city,
        'fastighetsbeteckning' => trim((string) ($hint['fastighetsbeteckning'] ?? '')),
    ];
    $hadStreet = trim((string) ($existing['street'] ?? '')) !== '';
    $haveKey = jeab_kontakt_effective_street_key((string) ($existing['street'] ?? ''), (string) ($existing['street_key'] ?? ''));
    $wantKey = (string) ($place['street_key'] ?? '');
    $sameStreet = $hadStreet && $wantKey !== '' && jeab_kontakt_street_keys_equal($haveKey, $wantKey);
    $haveCity = jeab_kontakt_city_name((string) ($existing['city'] ?? ''));
    $cityDiffers = $haveCity !== '' && $city !== '' && jeab_kontakt_city_key($haveCity) !== jeab_kontakt_city_key($city);
    if ($hadStreet && trim((string) ($place['street'] ?? '')) !== '' && (!$sameStreet || $cityDiffers)) {
        return $existing;
    }
    $filled = jeab_kontakt_fill_blanks($existing, $place);
    $same = (string) ($filled['street'] ?? '') === (string) ($existing['street'] ?? '')
        && (string) ($filled['street_key'] ?? '') === (string) ($existing['street_key'] ?? '')
        && (string) ($filled['postal_code'] ?? '') === (string) ($existing['postal_code'] ?? '')
        && (string) ($filled['city'] ?? '') === (string) ($existing['city'] ?? '')
        && (string) ($filled['fastighetsbeteckning'] ?? '') === (string) ($existing['fastighetsbeteckning'] ?? '');
    if ($same) {
        return $existing;
    }
    $saved = jeab_kontakter_mutate(function (array &$contacts) use ($id, $filled): ?array {
        if (!isset($contacts[$id]) || !is_array($contacts[$id])) {
            return null;
        }
        $contacts[$id] = array_merge($contacts[$id], [
            'street' => (string) ($filled['street'] ?? ''),
            'street_key' => (string) ($filled['street_key'] ?? ''),
            'postal_code' => (string) ($filled['postal_code'] ?? ''),
            'city' => (string) ($filled['city'] ?? ''),
            'fastighetsbeteckning' => (string) ($filled['fastighetsbeteckning'] ?? ''),
            'updated_at' => function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s'),
        ]);
        return $contacts[$id];
    });
    return is_array($saved) ? $saved : $filled;
}

/**
 * Kopplar en påminnelse som just skapats från inkorgen. Skriver bara contact_id
 * på påminnelsen. Saknat uppslag stoppar inte påminnelsen.
 *
 * @param array<string, mixed> $reminder
 * @param array<string, mixed> $hint
 */
function jeab_kontakt_attach_reminder(JeabStore $store, array $reminder, array $hint = []): ?array
{
    $id = (int) ($reminder['id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    if (function_exists('jeab_contacts_sole') && jeab_contacts_sole()) {
        if (trim((string) ($hint['city'] ?? '')) === '') {
            $hint['city'] = trim((string) ($reminder['ort'] ?? ''));
        }
        if (trim((string) ($hint['postal_code'] ?? '')) === '') {
            $hint['postal_code'] = trim((string) ($reminder['postal'] ?? ''));
        }
    }
    if ((string) ($reminder['contact_id'] ?? '') !== '') {
        $existingId = (string) $reminder['contact_id'];
        $existing = jeab_kontakter_load()[$existingId] ?? null;
        if (!is_array($existing)) {
            return null;
        }
        return jeab_kontakt_fill_reminder_place($existingId, $existing, $hint, $reminder);
    }
    try {
        $address = trim((string) ($hint['address'] ?? ''));
        if ($address === '') {
            $address = trim((string) ($reminder['address'] ?? ''));
        }
        $contact = jeab_kontakt_find_or_create([
            'name' => trim((string) ($hint['name'] ?? $reminder['customer_name'] ?? '')),
            'email' => trim((string) ($hint['email'] ?? $reminder['email'] ?? '')),
            'phone' => trim((string) ($hint['phone'] ?? $reminder['phone'] ?? '')),
            'address' => $address,
            'city' => trim((string) ($hint['city'] ?? $reminder['ort'] ?? '')),
            'postal_code' => (string) ($hint['postal_code'] ?? ''),
            'company' => (string) ($hint['company'] ?? $reminder['company'] ?? ''),
            'thread_id' => (string) ($hint['thread_id'] ?? $reminder['thread_id'] ?? ''),
            'personnummer' => (string) ($hint['personnummer'] ?? ''),
            'fastighetsbeteckning' => (string) ($hint['fastighetsbeteckning'] ?? ''),
            'require_postal' => !empty($hint['require_postal']),
        ], true, false, true);
        if (!is_array($contact) || (string) ($contact['id'] ?? '') === '') {
            return null;
        }
        jeab_kontakt_stamp_reminder($store, $id, (string) $contact['id']);
        if (function_exists('jeab_contacts_sole') && jeab_contacts_sole()) {
            $current = jeab_kontakter_load()[(string) $contact['id']] ?? null;
            if (is_array($current)) {
                $contact = jeab_kontakt_fill_reminder_place((string) $contact['id'], $current, $hint, $reminder);
            }
        }
        $noted = jeab_kontakt_note_reminder_address((string) $contact['id'], $id, [
            'name' => trim((string) ($hint['name'] ?? $reminder['customer_name'] ?? '')),
            'email' => trim((string) ($hint['email'] ?? $reminder['email'] ?? '')),
            'phone' => trim((string) ($hint['phone'] ?? $reminder['phone'] ?? '')),
            'address' => $address,
            'city' => trim((string) ($hint['city'] ?? $reminder['ort'] ?? '')),
            'postal_code' => (string) ($hint['postal_code'] ?? $reminder['postal'] ?? ''),
        ]);
        return is_array($noted) ? $noted : $contact;
    } catch (Throwable) {
        if (function_exists('jeab_log_app_error')) {
            jeab_log_app_error('kontakter', 'Kunde inte koppla kontakt till påminnelse ' . $id);
        }
        return null;
    }
}

/**
 * @return array<string, mixed>
 */
function jeab_kontakt_source_base(): array
{
    return [
        'kind' => '',
        'id' => '',
        'closed' => false,
        'contact_id' => '',
        'street' => '',
        'street_key' => '',
        'postal' => '',
        'postal_invalid' => false,
        'city' => '',
        'fastighet' => '',
        'name' => '',
        'title' => '',
        'job_type' => '',
        'email' => '',
        'phone' => '',
        'pnr' => '',
        'at' => '',
    ];
}

/** Senaste datumet bland källans egna tidsfält. */
function jeab_kontakt_source_best_at(array $row, array $keys): string
{
    $best = 0;
    $bestRaw = '';
    foreach ($keys as $key) {
        $raw = trim((string) ($row[$key] ?? ''));
        if ($raw === '') {
            continue;
        }
        $ts = strtotime($raw);
        if ($ts === false || $ts < $best) {
            continue;
        }
        $best = $ts;
        $bestRaw = $raw;
    }
    return $bestRaw;
}

/** @param array<string, mixed> $row */
function jeab_kontakt_source_from_reminder(array $row): array
{
    $parsed = jeab_kontakt_parse_address((string) ($row['address'] ?? ''));
    if ($parsed['city'] === '') {
        $parsed['city'] = jeab_kontakt_city_name(trim((string) ($row['ort'] ?? '')));
    }
    $src = jeab_kontakt_source_base();
    $src['kind'] = 'reminder';
    $src['id'] = (string) ($row['id'] ?? '');
    $src['contact_id'] = trim((string) ($row['contact_id'] ?? ''));
    $src['street'] = $parsed['street'];
    $src['street_key'] = $parsed['street_key'];
    $src['postal'] = $parsed['postal'];
    $src['postal_invalid'] = !empty($parsed['postal_invalid']);
    $src['city'] = $parsed['city'];
    $src['name'] = trim((string) ($row['customer_name'] ?? ''));
    $src['title'] = trim((string) ($row['title'] ?? ''));
    $src['email'] = jeab_kontakt_email((string) ($row['email'] ?? ''));
    $src['phone'] = jeab_kontakt_phone((string) ($row['phone'] ?? ''));
    $src['at'] = jeab_kontakt_source_best_at($row, ['updated_at', 'created_at', 'due_at']);
    return $src;
}

/** Första ifyllda texten bland fälten. */
function jeab_kontakt_first_text(array $row, array $keys): string
{
    foreach ($keys as $key) {
        $value = trim((string) ($row[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

/**
 * Adressraden först. Saknar den gatunummer används orderns egna fält
 * (street/gata, postnummer, ort) innan källan faller tillbaka på e-post.
 *
 * @param array<string, mixed> $row
 * @return array{street:string,street_key:string,postal:string,city:string,postal_invalid:bool}
 */
function jeab_kontakt_order_parsed_address(array $row): array
{
    $parsed = jeab_kontakt_parse_address((string) ($row['address'] ?? ''));
    if ($parsed['street_key'] === '') {
        $street = jeab_kontakt_first_text($row, ['street', 'gata']);
        $postal = jeab_kontakt_first_text($row, ['postal_code', 'postnummer', 'postnr']);
        $city = jeab_kontakt_first_text($row, ['city', 'ort']);
        if ($street !== '') {
            $alt = jeab_kontakt_parse_address(trim($street . ' ' . $postal . ' ' . $city));
            if ($alt['street_key'] !== '') {
                $parsed = $alt;
            }
        }
    }
    if ($parsed['postal'] === '') {
        $postalRaw = jeab_kontakt_first_text($row, ['postnummer', 'postnr', 'postal_code']);
        if ($postalRaw !== '') {
            $scan = jeab_kontakt_postal_scan($postalRaw);
            if ($scan['invalid']) {
                $parsed['postal_invalid'] = true;
            } elseif ($scan['postal'] !== '') {
                $parsed['postal'] = $scan['postal'];
            }
        }
    }
    if ($parsed['city'] === '') {
        $parsed['city'] = jeab_kontakt_city_name(jeab_kontakt_first_text($row, ['city', 'ort']));
    }
    return $parsed;
}

/** @param array<string, mixed> $row */
function jeab_kontakt_source_from_order(array $row): array
{
    $parsed = jeab_kontakt_order_parsed_address($row);
    $name = trim((string) ($row['name'] ?? ''));
    $pnr = jeab_kontakt_pnr_store((string) ($row['personnummer'] ?? ''));
    $owners = is_array($row['owners'] ?? null) ? $row['owners'] : [];
    if ($name === '' && isset($owners[0]) && is_array($owners[0])) {
        $name = trim((string) ($owners[0]['name'] ?? ''));
    }
    if ($pnr === '' && isset($owners[0]) && is_array($owners[0])) {
        $pnr = jeab_kontakt_pnr_store((string) ($owners[0]['personnummer'] ?? ''));
    }
    $src = jeab_kontakt_source_base();
    $src['kind'] = 'order';
    $src['id'] = (string) ($row['reminder_id'] ?? '');
    $src['closed'] = jeab_kontakt_order_is_closed($row);
    $src['contact_id'] = trim((string) ($row['contact_id'] ?? ''));
    $src['street'] = $parsed['street'];
    $src['street_key'] = $parsed['street_key'];
    $src['postal'] = $parsed['postal'];
    $src['postal_invalid'] = !empty($parsed['postal_invalid']);
    $src['city'] = $parsed['city'];
    $src['fastighet'] = trim((string) ($row['fastighetsbeteckning'] ?? ''));
    $src['name'] = $name;
    $src['email'] = jeab_kontakt_email((string) ($row['email'] ?? ''));
    $src['phone'] = jeab_kontakt_phone((string) ($row['phone'] ?? ''));
    $src['pnr'] = $pnr;
    $src['extra_owners'] = [];
    foreach ($owners as $i => $owner) {
        if ($i === 0 || !is_array($owner)) {
            continue;
        }
        $extraName = trim((string) ($owner['name'] ?? ''));
        if ($extraName === '') {
            continue;
        }
        $src['extra_owners'][] = [
            'name' => $extraName,
            'pnr' => jeab_kontakt_pnr_store((string) ($owner['personnummer'] ?? '')),
        ];
    }
    $src['at'] = jeab_kontakt_source_best_at($row, ['updated_at', 'created_at', 'closed_at']);
    return $src;
}

/** @param array<string, mixed> $row */
function jeab_kontakt_source_from_booking(array $row): array
{
    $parsed = jeab_kontakt_parse_address((string) ($row['address'] ?? ''));
    $src = jeab_kontakt_source_base();
    $src['kind'] = 'booking';
    $src['id'] = (string) ($row['id'] ?? '');
    $src['contact_id'] = trim((string) ($row['contact_id'] ?? ''));
    $src['street'] = $parsed['street'];
    $src['street_key'] = $parsed['street_key'];
    $src['postal'] = $parsed['postal'];
    $src['postal_invalid'] = !empty($parsed['postal_invalid']);
    $src['city'] = $parsed['city'];
    $src['name'] = trim((string) ($row['customer_name'] ?? ''));
    $src['job_type'] = trim((string) ($row['job_type'] ?? ''));
    $src['email'] = jeab_kontakt_email((string) ($row['email'] ?? ''));
    $src['phone'] = jeab_kontakt_phone((string) ($row['phone'] ?? ''));
    $src['at'] = jeab_kontakt_source_best_at($row, ['starts_at', 'updated_at', 'created_at']);
    return $src;
}

/** @param array<string, mixed> $row */
function jeab_kontakt_source_from_inbox(string $id, array $row): array
{
    $parsed = jeab_kontakt_parse_address((string) ($row['address'] ?? ''));
    if ($parsed['city'] === '') {
        $parsed['city'] = jeab_kontakt_city_name(trim((string) ($row['town'] ?? '')));
    }
    $email = '';
    foreach ((array) ($row['emails'] ?? []) as $one) {
        $email = jeab_kontakt_email((string) $one);
        if ($email === '') {
            continue;
        }
        if (jeab_kontakt_address_blocked($email) || (function_exists('jeab_lead_partner_address') && jeab_lead_partner_address($email))) {
            $email = '';
            continue;
        }
        break;
    }
    $src = jeab_kontakt_source_base();
    $src['kind'] = 'inbox';
    $src['id'] = $id;
    $src['street'] = $parsed['street'];
    $src['street_key'] = $parsed['street_key'];
    $src['postal'] = $parsed['postal'];
    $src['postal_invalid'] = !empty($parsed['postal_invalid']);
    $src['city'] = $parsed['city'];
    $src['name'] = trim((string) ($row['name'] ?? ''));
    $src['email'] = $email;
    $src['phone'] = jeab_kontakt_phone((string) ($row['mobile'] ?? ''));
    $src['contact_id'] = trim((string) ($row['contact_id'] ?? ''));
    $src['at'] = jeab_kontakt_source_best_at($row, ['updated_at', 'created_at']);
    return $src;
}

/** @return list<array<string, mixed>> */
function jeab_kontakt_collect_sources(JeabStore $store): array
{
    $items = [];
    foreach ($store->listReminders(true) as $row) {
        if (is_array($row)) {
            $items[] = jeab_kontakt_source_from_reminder($row);
        }
    }
    if (function_exists('jeab_purchase_all')) {
        foreach (jeab_purchase_all() as $row) {
            if (is_array($row)) {
                $items[] = jeab_kontakt_source_from_order($row);
            }
        }
    }
    foreach ($store->listAllBookings() as $row) {
        if (is_array($row)) {
            $items[] = jeab_kontakt_source_from_booking($row);
        }
    }
    if (function_exists('jeab_inkorg_contacts')) {
        foreach (jeab_inkorg_contacts() as $id => $row) {
            if (is_array($row)) {
                $items[] = jeab_kontakt_source_from_inbox((string) $id, $row);
            }
        }
    }
    return $items;
}

/**
 * Samma person när efternamnet är lika och den enas förnamn är en delmängd av den andras.
 * «S N» och «K F S N», «J K» och «E J K».
 * Dubbelt efternamn: «D O» och «D O C» när förnamnen är lika och det kortare efternamnet
 * finns bland det längres efternamn.
 * Ett ensamt förnamn («U» mot «U H») avgörs inte här.
 * Versaler och diakriter spelar ingen roll.
 * Har båda personnummer avgör numret, inte namnet. Det sköts av anroparen.
 */
function jeab_kontakt_names_same(string $a, string $b): bool
{
    $left = jeab_kontakt_name_tokens($a);
    $right = jeab_kontakt_name_tokens($b);
    if ($left === [] || $right === []) {
        return false;
    }
    if ($left === $right) {
        return true;
    }
    if (count($left) < 2 || count($right) < 2) {
        return false;
    }
    $leftCopy = $left;
    $rightCopy = $right;
    $leftSurname = array_pop($leftCopy);
    $rightSurname = array_pop($rightCopy);
    if ($leftSurname === $rightSurname && $leftCopy !== [] && $rightCopy !== []) {
        $short = count($leftCopy) <= count($rightCopy) ? $leftCopy : $rightCopy;
        $long = count($leftCopy) <= count($rightCopy) ? $rightCopy : $leftCopy;
        $counts = array_count_values($long);
        $subset = true;
        foreach ($short as $token) {
            if (($counts[$token] ?? 0) < 1) {
                $subset = false;
                break;
            }
            $counts[$token]--;
        }
        if ($subset) {
            return true;
        }
    }
    return jeab_kontakt_double_surname_same($left, $right);
}

/** @param list<string> $left @param list<string> $right */
function jeab_kontakt_double_surname_same(array $left, array $right): bool
{
    if (count($left) < 2 || count($right) < 2 || count($left) === count($right)) {
        return false;
    }
    $short = count($left) < count($right) ? $left : $right;
    $long = count($left) < count($right) ? $right : $left;
    $shortSurname = array_pop($short);
    if ($short === []) {
        return false;
    }
    if (array_slice($long, 0, count($short)) !== $short) {
        return false;
    }
    return in_array($shortSurname, array_slice($long, count($short)), true);
}

/** «U» och «U H». Bara förnamnet, och bara när anroparen redan vet att adress eller kanal är samma. */
function jeab_kontakt_firstname_only_same(string $a, string $b): bool
{
    $left = jeab_kontakt_name_tokens($a);
    $right = jeab_kontakt_name_tokens($b);
    if (count($left) === 1 && count($right) >= 2) {
        return $left[0] === $right[0];
    }
    if (count($right) === 1 && count($left) >= 2) {
        return $right[0] === $left[0];
    }
    return false;
}

function jeab_kontakt_group_place(string $key): string
{
    if (str_starts_with($key, 'email:') || str_starts_with($key, 'phone:')) {
        return 'channel';
    }
    if (str_starts_with($key, 'name:') || str_starts_with($key, 'loose:')) {
        return 'name';
    }
    return 'address';
}

function jeab_kontakt_surname(string $name): string
{
    $tokens = jeab_kontakt_name_tokens($name);
    if (count($tokens) < 2) {
        return '';
    }
    return $tokens[count($tokens) - 1];
}

/**
 * Samma person, eller samma efternamn. Olika efternamn på en kanal slås inte ihop.
 * Tomt namn är inte ett skäl att vägra; anroparen har redan samma kanal.
 */
function jeab_kontakt_names_channel_compatible(string $a, string $b): bool
{
    $a = trim($a);
    $b = trim($b);
    if ($a === '' || $b === '') {
        return true;
    }
    if (jeab_kontakt_names_same($a, $b) || jeab_kontakt_firstname_only_same($a, $b)) {
        return true;
    }
    $left = jeab_kontakt_surname($a);
    $right = jeab_kontakt_surname($b);
    return $left !== '' && $left === $right;
}

/** @param array<string, mixed> $left @param array<string, mixed> $right */
function jeab_kontakt_records_channel_compatible(array $left, array $right): bool
{
    $leftNames = jeab_kontakt_record_names($left);
    $rightNames = jeab_kontakt_record_names($right);
    if ($leftNames === [] || $rightNames === []) {
        return true;
    }
    foreach ($leftNames as $a) {
        foreach ($rightNames as $b) {
            if (!jeab_kontakt_names_channel_compatible($a, $b)) {
                return false;
            }
        }
    }
    return true;
}

/**
 * @param list<array<string, mixed>> $members
 * @return array{owners:list<array<string,mixed>>,flagged:list<array<string,mixed>>,conflicts:list<array<string,mixed>>,rejected_sources:list<string>}
 */
function jeab_kontakt_owners_from_members(array $members, string $addressKey, string $place = 'address'): array
{
    $owners = [];
    $flagged = [];
    $conflicts = [];
    $pending = [];
    $accepted = [];
    $rejected = [];
    $giveChannel = static function (int $i, string $email, string $phone, string $source) use (&$owners, &$conflicts, $addressKey): void {
        if ($email !== '' && $owners[$i]['email'] !== '' && $email !== $owners[$i]['email']) {
            $conflicts[] = ['address_key' => $addressKey, 'kind' => 'extra_email', 'name' => (string) $owners[$i]['name'], 'email' => $email, 'source' => $source];
        } elseif ($email !== '' && $owners[$i]['email'] === '') {
            $owners[$i]['email'] = $email;
        }
        if ($phone !== '' && $owners[$i]['phone'] !== '' && $phone !== $owners[$i]['phone']) {
            $conflicts[] = ['address_key' => $addressKey, 'kind' => 'extra_phone', 'name' => (string) $owners[$i]['name'], 'phone' => $phone, 'source' => $source];
        } elseif ($phone !== '' && $owners[$i]['phone'] === '') {
            $owners[$i]['phone'] = $phone;
        }
    };
    $add = static function (string $name, string $pnr, string $email, string $phone, string $source) use (&$owners, &$flagged, &$conflicts, &$pending, &$accepted, &$rejected, $addressKey, $place, $giveChannel): void {
        $name = trim($name);
        if (jeab_kontakt_platform_name($name) || jeab_kontakt_nonperson_name($name)) {
            $name = '';
        }
        if (jeab_kontakt_address_blocked($email)) {
            $email = '';
        }
        if (jeab_kontakt_phone_blocked($phone)) {
            $phone = '';
        }
        if ($name === '' && $email === '' && $phone === '') {
            return;
        }
        if ($name === '') {
            $pending[] = ['email' => $email, 'phone' => $phone, 'source' => $source];
            $accepted[$source] = true;
            return;
        }
        foreach ($owners as $i => $owner) {
            $ownerPnr = (string) $owner['pnr'];
            $bothPnr = $pnr !== '' && $ownerPnr !== '';
            if ($bothPnr && jeab_kontakt_pnr_digits($pnr) !== jeab_kontakt_pnr_digits($ownerPnr)) {
                continue;
            }
            $samePnr = $bothPnr && jeab_kontakt_pnr_digits($pnr) === jeab_kontakt_pnr_digits($ownerPnr);
            $sameName = (string) $owner['name'] !== '' && jeab_kontakt_names_same($name, (string) $owner['name']);
            $sameFirst = ($place === 'address' || $place === 'channel')
                && (string) $owner['name'] !== ''
                && jeab_kontakt_firstname_only_same($name, (string) $owner['name']);
            if (!$samePnr && !$sameName && !$sameFirst) {
                continue;
            }
            if (jeab_kontakt_name_longer($name, (string) $owner['name'])) {
                $owners[$i]['name'] = $name;
            }
            if ($ownerPnr === '' && $pnr !== '') {
                $owners[$i]['pnr'] = $pnr;
            }
            $giveChannel($i, $email, $phone, $source);
            $accepted[$source] = true;
            return;
        }
        if ($place === 'channel') {
            foreach ($owners as $owner) {
                if (!jeab_kontakt_names_channel_compatible($name, (string) $owner['name'])) {
                    $conflicts[] = ['address_key' => $addressKey, 'kind' => 'surname_split', 'name' => $name, 'source' => $source];
                    $rejected[$source] = true;
                    return;
                }
            }
        }
        if (count($owners) >= 2) {
            $flagged[] = ['name' => $name, 'source' => $source, 'reason' => 'max två ägare'];
            $conflicts[] = ['address_key' => $addressKey, 'kind' => 'extra_owner', 'name' => $name, 'source' => $source];
            $accepted[$source] = true;
            return;
        }
        $owners[] = ['name' => $name, 'pnr' => $pnr, 'email' => $email, 'phone' => $phone, 'source' => $source];
        $accepted[$source] = true;
    };
    foreach ($members as $member) {
        $source = (string) $member['kind'] . ':' . (string) $member['id'];
        $add((string) $member['name'], (string) ($member['pnr'] ?? ''), (string) $member['email'], (string) $member['phone'], $source);
        foreach ((array) ($member['extra_owners'] ?? []) as $extra) {
            if (!is_array($extra)) {
                continue;
            }
            $add((string) ($extra['name'] ?? ''), (string) ($extra['pnr'] ?? ''), '', '', $source);
        }
    }
    if (count($owners) === 1) {
        foreach ($pending as $channel) {
            $giveChannel(0, (string) $channel['email'], (string) $channel['phone'], (string) $channel['source']);
        }
    }
    $owners = array_values(array_filter($owners, static fn(array $owner): bool => trim((string) ($owner['name'] ?? '')) !== ''));
    $contactAt = 0;
    foreach ($owners as $i => $owner) {
        if ($owner['email'] !== '' || $owner['phone'] !== '') {
            $contactAt = $i;
            break;
        }
    }
    $stored = [];
    foreach ($owners as $i => $owner) {
        $isContact = $i === $contactAt;
        $stored[] = [
            'name' => (string) $owner['name'],
            'personnummer' => (string) $owner['pnr'],
            'contact' => $isContact,
            'email' => (string) $owner['email'],
            'phone' => (string) $owner['phone'],
        ];
        if (!$isContact && ((string) $owner['email'] !== '' || (string) $owner['phone'] !== '')) {
            $conflicts[] = [
                'address_key' => $addressKey,
                'kind' => 'owner_channel',
                'name' => (string) $owner['name'],
                'email' => (string) $owner['email'],
                'phone' => (string) $owner['phone'],
                'source' => (string) $owner['source'],
            ];
        }
    }
    $rejectedSources = [];
    foreach ($rejected as $source => $_) {
        if (!isset($accepted[$source])) {
            $rejectedSources[] = (string) $source;
        }
    }
    return ['owners' => $stored, 'flagged' => $flagged, 'conflicts' => $conflicts, 'rejected_sources' => $rejectedSources];
}

/** @param list<array<string, mixed>> $members */
function jeab_kontakt_members_city(array $members): string
{
    foreach ($members as $member) {
        $city = jeab_kontakt_city_name((string) ($member['city'] ?? ''));
        if ($city !== '') {
            return $city;
        }
    }
    return '';
}

/**
 * @param list<array<string, mixed>> $items
 * @return list<array{key:string,members:list<array<string,mixed>>,conflicts:list<array<string,mixed>>,needs_postnr:bool}>
 */
function jeab_kontakt_group_sources(array $items): array
{
    $byStreet = [];
    $loose = [];
    foreach ($items as $item) {
        if ((string) $item['street_key'] !== '') {
            $byStreet[(string) $item['street_key']][] = $item;
        } else {
            $loose[] = $item;
        }
    }
    $groups = [];
    $openGroup = static function (string $key, array $members, bool $needsPostnr) use (&$groups): void {
        $groups[$key] = [
            'key' => $key,
            'members' => $members,
            'conflicts' => [],
            'needs_postnr' => $needsPostnr,
            'city' => jeab_kontakt_members_city($members),
        ];
    };
    foreach ($byStreet as $streetKey => $members) {
        $withPostal = [];
        $noPostal = [];
        foreach ($members as $member) {
            if (!empty($member['postal_invalid'])) {
                $key = $streetKey . '|ogiltigt:' . (string) $member['kind'] . ':' . (string) $member['id'];
                $openGroup($key, [$member], true);
                $groups[$key]['postal_invalid'] = true;
                continue;
            }
            if ((string) ($member['postal'] ?? '') !== '') {
                $withPostal[] = $member;
            } else {
                $noPostal[] = $member;
            }
        }
        foreach ($withPostal as $member) {
            $key = $streetKey . '|' . (string) $member['postal'];
            if (!isset($groups[$key])) {
                $openGroup($key, [], false);
            }
            $groups[$key]['members'][] = $member;
            $city = jeab_kontakt_city_name((string) ($member['city'] ?? ''));
            if ($city !== '' && (string) ($groups[$key]['city'] ?? '') === '') {
                $groups[$key]['city'] = $city;
            }
        }
        $cityBuckets = [];
        foreach ($noPostal as $member) {
            $cityFold = jeab_kontakt_fold_text(jeab_kontakt_city_name((string) ($member['city'] ?? '')));
            $cityBuckets[$cityFold][] = $member;
        }
        foreach ($cityBuckets as $cityFold => $bucket) {
            $placed = false;
            if ($cityFold === '') {
                foreach ($bucket as $member) {
                    $key = $streetKey . '|saknar:' . (string) ($member['kind'] ?? '') . ':' . (string) ($member['id'] ?? '');
                    if (!isset($groups[$key])) {
                        $openGroup($key, [$member], true);
                    }
                }
                continue;
            }
            $hits = [];
            foreach ($groups as $key => $group) {
                if (!str_starts_with((string) $key, $streetKey . '|') || !empty($group['needs_postnr'])) {
                    continue;
                }
                $groupCity = jeab_kontakt_fold_text(jeab_kontakt_city_name((string) ($group['city'] ?? '')));
                if ($groupCity === '' || $groupCity !== $cityFold) {
                    continue;
                }
                $hits[] = (string) $key;
            }
            if (count($hits) === 1) {
                $key = $hits[0];
                $groups[$key]['members'] = array_merge($groups[$key]['members'], $bucket);
                if ((string) ($groups[$key]['city'] ?? '') === '') {
                    $groups[$key]['city'] = jeab_kontakt_members_city($bucket);
                }
                $placed = true;
            }
            if ($placed) {
                continue;
            }
            $key = $streetKey . '|' . ($cityFold !== '' ? 'ort:' . $cityFold : '');
            if (isset($groups[$key])) {
                $groups[$key]['members'] = array_merge($groups[$key]['members'], $bucket);
                $groups[$key]['needs_postnr'] = true;
                continue;
            }
            $openGroup($key, $bucket, true);
        }
    }
    foreach ($loose as $item) {
        $matched = [];
        foreach ($groups as $key => $group) {
            $addressGroup = jeab_kontakt_group_has_address($group);
            foreach ($group['members'] as $member) {
                $emailHit = $item['email'] !== ''
                    && !jeab_kontakt_address_blocked((string) $item['email'])
                    && !jeab_kontakt_address_blocked((string) $member['email'])
                    && $item['email'] === $member['email'];
                $phoneHit = $item['phone'] !== ''
                    && !jeab_kontakt_phone_blocked((string) $item['phone'])
                    && !jeab_kontakt_phone_blocked((string) $member['phone'])
                    && $item['phone'] === $member['phone'];
                if (!$emailHit && !$phoneHit) {
                    continue;
                }
                // Efternamnsregeln gäller bara en grupp som enbart hålls ihop av e-post eller telefon.
                if ($addressGroup || jeab_kontakt_records_channel_compatible($item, $member)) {
                    $matched[$key] = true;
                    break;
                }
            }
        }
        if (count($matched) === 1) {
            $key = (string) array_key_first($matched);
            $groups[$key]['members'][] = $item;
            continue;
        }
        if (count($matched) > 1) {
            $channel = (string) $item['email'];
            if ($channel === '' || jeab_kontakt_address_blocked($channel)) {
                $channel = (string) $item['phone'];
                if (jeab_kontakt_phone_blocked($channel)) {
                    $channel = '';
                }
            }
            $itemKey = 'loose:' . ($channel !== '' ? $channel : (string) $item['kind'] . ':' . (string) $item['id']);
            $groups[$itemKey] = [
                'key' => $itemKey,
                'members' => [$item],
                'conflicts' => [[
                    'address_key' => $itemKey,
                    'kind' => 'ambiguous_channel',
                    'name' => (string) $item['name'],
                    'source' => $item['kind'] . ':' . $item['id'],
                    'channel' => $channel,
                    'matches' => array_keys($matched),
                ]],
                'needs_postnr' => false,
            ];
            continue;
        }
        $emailKey = (string) $item['email'];
        if (jeab_kontakt_address_blocked($emailKey)) {
            $emailKey = '';
        }
        $phoneKey = (string) $item['phone'];
        if (jeab_kontakt_phone_blocked($phoneKey)) {
            $phoneKey = '';
        }
        $itemKey = $emailKey !== '' ? 'email:' . $emailKey : ($phoneKey !== '' ? 'phone:' . $phoneKey : 'name:' . jeab_kontakt_fold_text((string) $item['name']) . ':' . $item['kind'] . ':' . $item['id']);
        if (!isset($groups[$itemKey])) {
            $groups[$itemKey] = ['key' => $itemKey, 'members' => [], 'conflicts' => [], 'needs_postnr' => false];
        }
        $groups[$itemKey]['members'][] = $item;
    }
    $split = [];
    foreach ($groups as $group) {
        $key = (string) ($group['key'] ?? '');
        if (!str_starts_with($key, 'email:') && !str_starts_with($key, 'phone:')) {
            $split[] = $group;
            continue;
        }
        $parts = jeab_kontakt_split_channel_members((array) ($group['members'] ?? []));
        if (count($parts) <= 1) {
            $split[] = $group;
            continue;
        }
        foreach ($parts as $i => $members) {
            $partKey = $key;
            if ($i > 0) {
                $first = $members[0] ?? [];
                $partKey = 'loose:' . (string) ($first['kind'] ?? 'post') . ':' . (string) ($first['id'] ?? $i);
            }
            $split[] = [
                'key' => $partKey,
                'members' => $members,
                'conflicts' => $group['conflicts'] ?? [],
                'needs_postnr' => !empty($group['needs_postnr']),
                'city' => jeab_kontakt_members_city($members),
            ];
        }
    }
    $seen = [];
    foreach ($split as $group) {
        foreach ((array) ($group['members'] ?? []) as $member) {
            if (!is_array($member)) {
                continue;
            }
            $seen[(string) ($member['kind'] ?? '') . ':' . (string) ($member['id'] ?? '')] = true;
        }
    }
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = (string) ($item['kind'] ?? '') . ':' . (string) ($item['id'] ?? '');
        if (isset($seen[$id])) {
            continue;
        }
        $key = 'loose:' . $id;
        $split[] = [
            'key' => $key,
            'members' => [$item],
            'conflicts' => [[
                'address_key' => $key,
                'kind' => 'unplaced',
                'name' => (string) ($item['name'] ?? ''),
                'source' => $id,
            ]],
            'needs_postnr' => false,
            'city' => jeab_kontakt_members_city([$item]),
        ];
        $seen[$id] = true;
    }
    return $split;
}

/** En grupp med gatunyckel är en adress, även om en lös källa senare hänger på via e-post eller telefon. */
function jeab_kontakt_group_has_address(array $group): bool
{
    foreach ((array) ($group['members'] ?? []) as $member) {
        if (is_array($member) && (string) ($member['street_key'] ?? '') !== '') {
            return true;
        }
    }
    $key = (string) ($group['key'] ?? '');
    return $key !== ''
        && !str_starts_with($key, 'email:')
        && !str_starts_with($key, 'phone:')
        && !str_starts_with($key, 'name:')
        && !str_starts_with($key, 'loose:');
}

/**
 * En grupp som bara hålls ihop av e-post eller telefon delas när efternamnen skiljer sig.
 *
 * @param list<array<string, mixed>> $members
 * @return list<list<array<string, mixed>>>
 */
function jeab_kontakt_split_channel_members(array $members): array
{
    $buckets = [];
    foreach ($members as $member) {
        if (!is_array($member)) {
            continue;
        }
        $placed = false;
        foreach ($buckets as $i => $bucket) {
            $ok = true;
            foreach ($bucket as $have) {
                if (!jeab_kontakt_records_channel_compatible($member, $have)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $buckets[$i][] = $member;
                $placed = true;
                break;
            }
        }
        if (!$placed) {
            $buckets[] = [$member];
        }
    }
    return $buckets;
}

/**
 * En källa som efternamnsregeln stöter bort från en e-post- eller telefon-grupp
 * får en egen grupp. Den får inte försvinna.
 *
 * @param list<array<string, mixed>> $groups
 * @return list<array<string, mixed>>
 */
function jeab_kontakt_expand_channel_splits(array $groups): array
{
    $out = [];
    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }
        $key = (string) ($group['key'] ?? '');
        if (jeab_kontakt_group_place($key) !== 'channel') {
            $out[] = $group;
            continue;
        }
        $members = [];
        foreach ((array) ($group['members'] ?? []) as $member) {
            if (is_array($member)) {
                $members[] = $member;
            }
        }
        $built = jeab_kontakt_owners_from_members($members, $key, 'channel');
        $rejected = array_fill_keys((array) ($built['rejected_sources'] ?? []), true);
        if ($rejected === []) {
            $out[] = $group;
            continue;
        }
        $stay = [];
        $leave = [];
        foreach ($members as $member) {
            $src = (string) ($member['kind'] ?? '') . ':' . (string) ($member['id'] ?? '');
            if (isset($rejected[$src])) {
                $leave[] = $member;
            } else {
                $stay[] = $member;
            }
        }
        if ($leave === []) {
            $out[] = $group;
            continue;
        }
        if ($stay !== []) {
            $group['members'] = $stay;
            $out[] = $group;
        }
        foreach ($leave as $member) {
            $partKey = 'loose:' . (string) ($member['kind'] ?? 'post') . ':' . (string) ($member['id'] ?? '');
            $source = (string) ($member['kind'] ?? '') . ':' . (string) ($member['id'] ?? '');
            $out[] = [
                'key' => $partKey,
                'members' => [$member],
                'conflicts' => [[
                    'address_key' => $partKey,
                    'kind' => 'surname_split',
                    'name' => (string) ($member['name'] ?? ''),
                    'source' => $source,
                ]],
                'needs_postnr' => false,
                'city' => jeab_kontakt_members_city([$member]),
            ];
        }
    }
    return $out;
}

/** @param array<string, mixed> $member */
function jeab_kontakt_member_key(array $member): string
{
    return (string) ($member['kind'] ?? '') . ':' . (string) ($member['id'] ?? '');
}

function jeab_kontakt_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $value = $input['dry'];
    return !($value === false || $value === 0 || $value === '0' || $value === 'false');
}

function jeab_kontakt_allow_mass(array $input): bool
{
    if (!array_key_exists('allow_mass', $input)) {
        return false;
    }
    $value = $input['allow_mass'];

    return $value === true || $value === 1 || $value === '1';
}

function jeab_kontakter_mass_delete_blocked(int $total, int $drop): bool
{
    if ($drop <= 0) {
        return false;
    }

    return $drop > 20 || ($total > 0 && ($drop / $total) > 0.05);
}

function jeab_kontakter_mass_delete_error(int $total, int $drop): string
{
    return 'Raderingen stoppades: ' . $drop . ' av ' . $total . ' kontakter. Skicka allow_mass=1 för att ta bort dem.';
}

/**
 * @param array<string, mixed> $value
 * @return array<string, mixed>
 */
function jeab_kontakt_strip_secrets(array $value): array
{
    $drop = ['personnummer', 'pnr', 'SocialSecurityNumber', 'OrganisationNumber'];
    $out = [];
    foreach ($value as $key => $item) {
        if (in_array((string) $key, $drop, true)) {
            $out['personnummer_finns'] = trim((string) $item) !== '';
            continue;
        }
        if (is_array($item)) {
            $out[$key] = jeab_kontakt_strip_secrets($item);
            continue;
        }
        $out[$key] = $item;
    }
    return $out;
}

function jeab_kontakt_skip_conflicts(array $input): bool
{
    if (!array_key_exists('skip_conflicts', $input)) {
        return true;
    }
    $value = $input['skip_conflicts'];
    return !($value === false || $value === 0 || $value === '0' || $value === 'false');
}

/** @return list<string>|null */
function jeab_kontakt_only_kinds(array $input): ?array
{
    if (!array_key_exists('only_kinds', $input)) {
        return null;
    }
    $raw = $input['only_kinds'];
    if (is_string($raw)) {
        $raw = explode(',', $raw);
    }
    if (!is_array($raw)) {
        return null;
    }
    $out = [];
    foreach ($raw as $kind) {
        $kind = trim((string) $kind);
        if ($kind !== '') {
            $out[] = $kind;
        }
    }
    return $out;
}

function jeab_kontakt_kind_selected(?array $onlyKinds, string $kind): bool
{
    return $onlyKinds === null || in_array($kind, $onlyKinds, true);
}

function jeab_kontakt_member_time(array $member): int
{
    $raw = trim((string) ($member['at'] ?? ''));
    if ($raw === '') {
        return 0;
    }
    $ts = strtotime($raw);
    return $ts === false ? 0 : $ts;
}

/**
 * Samma gata, nummer och ort. Postnummer får skilja. Tom ort matchar inte.
 *
 * @param array<string, array<string, mixed>> $contacts
 */
function jeab_kontakt_find_same_town(array $contacts, string $streetKey, string $city): ?string
{
    $streetKey = trim($streetKey);
    $cityFold = jeab_kontakt_fold_text(jeab_kontakt_city_name($city));
    if ($streetKey === '' || $cityFold === '') {
        return null;
    }
    foreach ($contacts as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        $have = jeab_kontakt_effective_street_key((string) ($row['street'] ?? ''), (string) ($row['street_key'] ?? ''));
        if (!jeab_kontakt_street_keys_equal($have, $streetKey)) {
            continue;
        }
        $haveCity = jeab_kontakt_fold_text(jeab_kontakt_city_name((string) ($row['city'] ?? '')));
        if ($haveCity === $cityFold) {
            return (string) $id;
        }
    }
    return null;
}

/**
 * @param array<string, array<string, mixed>> $placed
 * @param list<string> $keys
 * @return array<string, mixed>|null
 */
function jeab_kontakt_pick_newest_placed(array $placed, array $keys): ?array
{
    $best = null;
    $bestTs = -1;
    foreach ($keys as $key) {
        $key = (string) $key;
        if (!isset($placed[$key]) || !is_array($placed[$key])) {
            continue;
        }
        $row = $placed[$key];
        if (trim((string) ($row['contact_id'] ?? '')) === '') {
            continue;
        }
        $ts = (int) ($row['newest'] ?? 0);
        if ($best === null || $ts > $bestTs) {
            $bestTs = $ts;
            $row['key'] = $key;
            $best = $row;
        }
    }
    return $best;
}

/** @param array<string, mixed> $meta @param array<string, mixed> $target */
function jeab_kontakt_other_place_line(array $meta, array $target): string
{
    $ts = (int) ($meta['newest'] ?? 0);
    $date = $ts > 0
        ? date('Y-m-d', $ts)
        : substr((function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s')), 0, 10);
    $street = trim((string) ($meta['street'] ?? ''));
    $tail = trim(trim((string) ($meta['postal'] ?? '')) . ' ' . trim((string) ($meta['city'] ?? '')));
    $place = $street;
    if ($tail !== '') {
        $place = $place === '' ? $tail : $place . ', ' . $tail;
    }
    $parts = [];
    if ($place !== '') {
        $parts[] = $place;
    }
    $email = trim((string) ($meta['email'] ?? ''));
    $phone = trim((string) ($meta['phone'] ?? ''));
    if ($email !== '' && $email !== trim((string) ($target['email'] ?? ''))) {
        $parts[] = 'E-post: ' . $email;
    }
    if ($phone !== '' && $phone !== trim((string) ($target['phone'] ?? ''))) {
        $parts[] = 'Tel: ' . $phone;
    }
    if ($parts === []) {
        return '';
    }
    return $date . ' ' . implode(', ', $parts);
}

/**
 * Ägare 2 behåller e-post och telefon. Tomma fält fylls. Ifyllda fält rörs inte.
 *
 * @param array<string, mixed> $row
 * @param list<array<string, mixed>> $owners
 * @return array<string, mixed>
 */
function jeab_kontakt_keep_owner_channels(array $row, array $owners): array
{
    $stored = [];
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (is_array($owner)) {
            $stored[] = $owner;
        }
    }
    foreach ($owners as $owner) {
        if (!is_array($owner) || !empty($owner['contact'])) {
            continue;
        }
        $name = trim((string) ($owner['name'] ?? ''));
        $email = trim((string) ($owner['email'] ?? ''));
        $phone = trim((string) ($owner['phone'] ?? ''));
        if ($name === '' || ($email === '' && $phone === '')) {
            continue;
        }
        $found = false;
        foreach ($stored as $i => $have) {
            if (trim((string) ($have['name'] ?? '')) !== $name) {
                continue;
            }
            $found = true;
            if (trim((string) ($have['email'] ?? '')) === '' && $email !== '') {
                $stored[$i]['email'] = $email;
            }
            if (trim((string) ($have['phone'] ?? '')) === '' && $phone !== '') {
                $stored[$i]['phone'] = $phone;
            }
            break;
        }
        if ($found || count($stored) >= 2) {
            continue;
        }
        $stored[] = [
            'name' => $name,
            'personnummer' => (string) ($owner['personnummer'] ?? ''),
            'contact' => false,
            'email' => $email,
            'phone' => $phone,
        ];
    }
    $row['owners'] = $stored;
    return $row;
}

/** @param array<string, mixed> $value @return array<string, mixed> */
function jeab_kontakt_mask_backup(array $value): array
{
    $drop = ['password', 'secret', 'token', 'api_key', 'apikey'];
    $mask = ['personnummer', 'pnr', 'SocialSecurityNumber', 'OrganisationNumber'];
    $out = [];
    foreach ($value as $key => $item) {
        $name = (string) $key;
        if (in_array($name, $drop, true)) {
            continue;
        }
        if (in_array($name, $mask, true)) {
            $out[$name] = is_string($item) ? jeab_kontakt_pnr_mask($item) : '';
            continue;
        }
        if (is_array($item)) {
            $out[$key] = jeab_kontakt_mask_backup($item);
            continue;
        }
        $out[$key] = $item;
    }
    return $out;
}

/**
 * @param array<int|string, mixed> $rows
 * @return list<array{id:string,contact_id:string}>
 */
function jeab_kontakt_backup_pairs(array $rows, string $idKey): array
{
    $out = [];
    foreach ($rows as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        $rid = $idKey !== '' ? trim((string) ($row[$idKey] ?? '')) : '';
        if ($rid === '') {
            $rid = trim((string) $id);
        }
        $out[] = [
            'id' => $rid,
            'contact_id' => trim((string) ($row['contact_id'] ?? '')),
        ];
    }
    return $out;
}

/**
 * Filnamn i datakatalogen, eller tom sträng om kopian inte gick att skriva.
 * När LEGACY_JSON_WRITES är av och kontakterna är i SQL-läge skrivs ingen
 * JSON-fil. Då tas en SQL-snapshot i samma databas och id:t returneras.
 * Tom sträng betyder att skrivningen ska nekas.
 */
function jeab_kontakter_backup_snapshot(JeabStore $store): string
{
    if (function_exists('jeab_legacy_json_skip') && jeab_legacy_json_skip('contacts')) {
        return function_exists('jeab_kontakter_sql_snapshot_take')
            ? jeab_kontakter_sql_snapshot_take($store)
            : '';
    }
    $dir = jeab_data_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return '';
    }
    $stamp = date('Ymd-His');
    $name = 'kontakter.bak-' . $stamp . '.json';
    $path = $dir . '/' . $name;
    $n = 2;
    while (is_file($path)) {
        $name = 'kontakter.bak-' . $stamp . '-' . $n . '.json';
        $path = $dir . '/' . $name;
        $n++;
    }
    $contacts = [];
    foreach (jeab_kontakter_load() as $id => $row) {
        if (is_array($row)) {
            $contacts[(string) $id] = jeab_kontakt_mask_backup($row);
        }
    }
    $orders = function_exists('jeab_purchase_all') ? jeab_purchase_all() : [];
    $inbox = function_exists('jeab_inkorg_contacts') ? jeab_inkorg_contacts() : [];
    $payload = [
        'contacts' => $contacts,
        'reminders' => jeab_kontakt_backup_pairs($store->listReminders(true), 'id'),
        'orders' => jeab_kontakt_backup_pairs(is_array($orders) ? $orders : [], 'reminder_id'),
        'bookings' => jeab_kontakt_backup_pairs($store->listAllBookings(), 'id'),
        'inbox_contacts' => jeab_kontakt_backup_pairs(is_array($inbox) ? $inbox : [], ''),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($path, $json) === false) {
        return '';
    }
    @chmod($path, 0600);
    return $name;
}

/** @param array<string, mixed> $row */
function jeab_kontakt_patch_saved(string $contactId, array $row): void
{
    jeab_kontakter_mutate(static function (array &$contacts) use ($contactId, $row): void {
        if (!isset($contacts[$contactId]) || !is_array($contacts[$contactId])) {
            return;
        }
        $contacts[$contactId]['ovrigt'] = (string) ($row['ovrigt'] ?? '');
        if (isset($row['owners']) && is_array($row['owners'])) {
            $contacts[$contactId]['owners'] = $row['owners'];
        }
    });
}

/**
 * Hook kontakter.link. dry=1 är standard och skriver inget.
 * dry=0 skapar kontakter och sätter contact_id. Källposternas egna fält, stängda ordrar och bokningstider rörs inte.
 * En grupp som bara består av inkorgskontakter får contact_id på inkorgskontakten, så nästa torrkörning inte föreslår create.
 * created är antalet skapade rader före den tysta dubblettstädningen. created_net är raderna som finns kvar.
 * skip_conflicts=1 håller unplaced och olösta ambiguous_channel. Körningen är tyst mot Fortnox, SMS, mejl och offert.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
/** Första och sista namnet. Mellannamn ingår inte. */
function jeab_kontakt_name_first_last_same(string $a, string $b): bool
{
    $left = jeab_kontakt_name_tokens($a);
    $right = jeab_kontakt_name_tokens($b);
    if (count($left) < 2 || count($right) < 2) {
        return false;
    }

    return $left[0] === $right[0] && $left[count($left) - 1] === $right[count($right) - 1];
}

/** @param array<string, mixed> $group @return list<string> */
function jeab_kontakt_report_owner_names(array $group): array
{
    $names = [];
    foreach ((array) ($group['owners'] ?? []) as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $name = trim((string) ($owner['name'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }

    return $names;
}

/** @param array<string, mixed> $left @param array<string, mixed> $right */
function jeab_kontakt_report_same_people(array $left, array $right): bool
{
    $a = jeab_kontakt_report_owner_names($left);
    $b = jeab_kontakt_report_owner_names($right);
    if ($a === [] || $b === []) {
        return false;
    }
    $covers = static function (array $need, array $have): bool {
        foreach ($need as $name) {
            $hit = false;
            foreach ($have as $other) {
                if (jeab_kontakt_name_first_last_same($name, $other)) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                return false;
            }
        }

        return true;
    };

    return $covers($a, $b) && $covers($b, $a);
}

/**
 * Samma kontakt, eller samma person på samma gata, när exakt en grupp saknar fastighet.
 * Två olika fastigheter lämnas. Länkarna slås ihop.
 *
 * @param array<string, mixed> $left
 * @param array<string, mixed> $right
 */
function jeab_kontakt_report_groups_same(array $left, array $right): bool
{
    $fastA = trim((string) ($left['fastighetsbeteckning'] ?? ''));
    $fastB = trim((string) ($right['fastighetsbeteckning'] ?? ''));
    if ($fastA !== '' && $fastB !== '' && strcasecmp($fastA, $fastB) !== 0) {
        return false;
    }
    if ($fastA === '' && $fastB === '') {
        return false;
    }
    $idA = trim((string) ($left['contact_id'] ?? ''));
    $idB = trim((string) ($right['contact_id'] ?? ''));
    if ($idA !== '' && $idA === $idB) {
        return true;
    }
    if (!jeab_kontakt_report_same_people($left, $right)) {
        return false;
    }
    $streetA = jeab_kontakt_street_key((string) ($left['street'] ?? ''));
    $streetB = jeab_kontakt_street_key((string) ($right['street'] ?? ''));
    if ($streetA === '' || $streetB === '' || !jeab_kontakt_street_keys_equal($streetA, $streetB)) {
        return false;
    }

    return true;
}

/**
 * @param list<array<string, mixed>> $groups
 * @return array<string, mixed>
 */
function jeab_kontakt_report_merge_groups(array $groups): array
{
    $base = $groups[0];
    foreach (array_slice($groups, 1) as $group) {
        $fast = trim((string) ($group['fastighetsbeteckning'] ?? ''));
        $baseFast = trim((string) ($base['fastighetsbeteckning'] ?? ''));
        if ($baseFast === '' && $fast !== '') {
            $base['fastighetsbeteckning'] = $fast;
            foreach (['street', 'postal_code', 'city', 'address_key'] as $key) {
                $value = trim((string) ($group[$key] ?? ''));
                if ($value !== '') {
                    $base[$key] = $value;
                }
            }
        }
        $id = trim((string) ($group['contact_id'] ?? ''));
        if (trim((string) ($base['contact_id'] ?? '')) === '' && $id !== '') {
            $base['contact_id'] = $id;
            $base['action'] = (string) ($group['action'] ?? 'reuse');
        }
        foreach (['reminders', 'orders', 'orders_skipped_closed', 'bookings', 'inbox_contacts'] as $key) {
            $have = [];
            foreach (array_merge((array) ($base[$key] ?? []), (array) ($group[$key] ?? [])) as $memberId) {
                $memberId = (string) $memberId;
                if ($memberId !== '' && !in_array($memberId, $have, true)) {
                    $have[] = $memberId;
                }
            }
            $base[$key] = $have;
        }
        $owners = [];
        foreach (array_merge((array) ($base['owners'] ?? []), (array) ($group['owners'] ?? [])) as $owner) {
            if (!is_array($owner)) {
                continue;
            }
            $name = trim((string) ($owner['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $placed = false;
            foreach ($owners as $i => $have) {
                if (!jeab_kontakt_name_first_last_same($name, (string) ($have['name'] ?? ''))) {
                    continue;
                }
                if (jeab_kontakt_name_longer($name, (string) ($have['name'] ?? ''))) {
                    $owners[$i]['name'] = $name;
                }
                if (empty($owners[$i]['personnummer_finns']) && !empty($owner['personnummer_finns'])) {
                    $owners[$i]['personnummer_finns'] = true;
                }
                $placed = true;
                break;
            }
            if (!$placed && count($owners) < 2) {
                $owners[] = $owner;
            }
        }
        if ($owners !== []) {
            $base['owners'] = $owners;
        }
    }
    if (trim((string) ($base['contact_id'] ?? '')) !== '' && (string) ($base['action'] ?? '') === 'create') {
        $base['action'] = 'reuse';
    }
    if (trim((string) ($base['postal_code'] ?? '')) !== '') {
        $base['needs_postnr'] = false;
    }

    return $base;
}

/**
 * link_report visar samma kontakt en gång. Källornas id följer med.
 *
 * @param list<array<string, mixed>> $groups
 * @return list<array<string, mixed>>
 */
function jeab_kontakt_fold_link_report_groups(array $groups): array
{
    $items = [];
    foreach ($groups as $group) {
        if (is_array($group)) {
            $items[] = $group;
        }
    }
    $count = count($items);
    if ($count < 2) {
        return $items;
    }
    $parent = range(0, $count - 1);
    $find = static function (int $index) use (&$parent, &$find): int {
        if ($parent[$index] !== $index) {
            $parent[$index] = $find($parent[$index]);
        }

        return $parent[$index];
    };
    for ($i = 0; $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            if (jeab_kontakt_report_groups_same($items[$i], $items[$j])) {
                $parent[$find($j)] = $find($i);
            }
        }
    }
    $buckets = [];
    for ($i = 0; $i < $count; $i++) {
        $buckets[$find($i)][] = $items[$i];
    }
    $out = [];
    foreach ($buckets as $bucket) {
        $out[] = jeab_kontakt_report_merge_groups($bucket);
    }

    return $out;
}

function jeab_kontakter_link(array $input, ?JeabStore $store = null, ?array $groupOverride = null): array
{
    $skipLog = function_exists('jeab_kontakt_merge_skip_guard') ? jeab_kontakt_merge_skip_guard(jeab_kontakt_dry($input)) : null;
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabStore) {
        return ['ok' => false, 'error' => 'Ingen lagring.'];
    }
    $run = static function () use ($input, $store, $groupOverride): array {
        return jeab_kontakter_link_inner($input, $store, $groupOverride);
    };
    if (function_exists('jeab_kontakt_quiet_call')) {
        $result = jeab_kontakt_quiet_call($run);
        return is_array($result) ? $result : ['ok' => false, 'error' => 'Ingen lagring.'];
    }
    return $run();
}

/**
 * @param array<string, mixed> $input
 * @param list<array<string, mixed>>|null $groupOverride
 * @return array<string, mixed>
 */
function jeab_kontakter_link_inner(array $input, JeabStore $store, ?array $groupOverride = null): array
{
    $dry = jeab_kontakt_dry($input);
    $skipConflicts = jeab_kontakt_skip_conflicts($input);
    $onlyKinds = jeab_kontakt_only_kinds($input);
    $collected = jeab_kontakt_collect_sources($store);
    $sourceCount = 0;
    foreach ($collected as $collectedItem) {
        if (is_array($collectedItem)) {
            $sourceCount++;
        }
    }
    $prepared = jeab_kontakt_prepare_sources($collected);
    $skipped = (int) $prepared['skipped'];
    $skippedInternal = (int) ($prepared['skipped_internal'] ?? 0);
    $skippedNonperson = (int) ($prepared['skipped_nonperson'] ?? 0);
    $skippedSharedPhone = (int) ($prepared['skipped_shared_phone'] ?? 0);
    $skippedSharedEmail = (int) ($prepared['skipped_shared_email'] ?? 0);
    $unlinked = [];
    foreach ((array) ($prepared['unlinked'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $unlinked[] = [
            'kind' => (string) ($row['kind'] ?? ''),
            'id' => (string) ($row['id'] ?? ''),
            'reason' => (string) ($row['reason'] ?? 'tom'),
        ];
    }
    $groups = is_array($groupOverride)
        ? $groupOverride
        : jeab_kontakt_expand_channel_splits(jeab_kontakt_group_sources(jeab_kontakt_enrich_missing_postal($prepared['items'])));
    $existing = jeab_kontakter_load();
    $priorIds = [];
    foreach ($existing as $priorId => $priorRow) {
        if (is_array($priorRow)) {
            $priorIds[(string) $priorId] = true;
        }
    }
    $skippedInternalSources = [];
    foreach ((array) ($prepared['skipped_internal_sources'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $skippedInternalSources[] = [
            'kind' => (string) ($row['kind'] ?? ''),
            'id' => (string) ($row['id'] ?? ''),
            'name' => trim((string) ($row['name'] ?? '')),
        ];
    }
    $report = [];
    $conflicts = [];
    $created = 0;
    $linked = ['reminders' => 0, 'orders' => 0, 'bookings' => 0];
    $ovrigtBefore = [];
    foreach ($existing as $priorId => $priorRow) {
        if (is_array($priorRow)) {
            $ovrigtBefore[(string) $priorId] = jeab_kontakt_ovrigt($priorRow);
        }
    }
    $nonpersonKeys = [];
    $unplacedRows = [];
    $placed = [];
    $backupFile = null;
    if (!$dry) {
        $backupFile = jeab_kontakter_backup_snapshot($store);
        if ($backupFile === '') {
            return ['ok' => false, 'error' => 'Kunde inte spara säkerhetskopia.', 'dry' => false];
        }
    }
    foreach ($groups as $group) {
        $members = $group['members'];
        if ($members === []) {
            continue;
        }
        $streetKey = '';
        $postal = '';
        $street = '';
        $city = '';
        $fastighet = '';
        foreach ($members as $member) {
            if ($streetKey === '' && (string) $member['street_key'] !== '') {
                $streetKey = (string) $member['street_key'];
                $street = (string) $member['street'];
            }
            if ($postal === '' && (string) $member['postal'] !== '') {
                $postal = (string) $member['postal'];
            }
            if ($city === '' && (string) $member['city'] !== '') {
                $city = (string) $member['city'];
            }
            if ($fastighet === '' && (string) $member['fastighet'] !== '') {
                $fastighet = (string) $member['fastighet'];
            }
        }
        if (!empty($group['postal_invalid'])) {
            $streetKey = '';
        }
        $built = jeab_kontakt_owners_from_members($members, (string) $group['key'], jeab_kontakt_group_place((string) $group['key']));
        $namedOwners = [];
        foreach ($built['owners'] as $owner) {
            if (trim((string) ($owner['name'] ?? '')) !== '') {
                $namedOwners[] = $owner;
            }
        }
        if ($namedOwners === []) {
            $skippedNonperson += count($members);
            foreach ($members as $member) {
                if (is_array($member)) {
                    $nonpersonKeys[jeab_kontakt_member_key($member)] = true;
                }
            }
            continue;
        }
        $hasNamedContact = false;
        foreach ($namedOwners as $owner) {
            if (!empty($owner['contact'])) {
                $hasNamedContact = true;
                break;
            }
        }
        if (!$hasNamedContact) {
            $namedOwners[0]['contact'] = true;
        }
        $kind = 'person';
        $company = jeab_kontakt_company_label($members);
        if ($company !== '') {
            $email = '';
            $phone = '';
            $pnr = '';
            foreach ($namedOwners as $owner) {
                if ($email === '' && (string) ($owner['email'] ?? '') !== '') {
                    $email = (string) $owner['email'];
                }
                if ($phone === '' && (string) ($owner['phone'] ?? '') !== '') {
                    $phone = (string) $owner['phone'];
                }
                if ($pnr === '' && jeab_kontakt_company_name((string) ($owner['name'] ?? ''))) {
                    $pnr = (string) ($owner['personnummer'] ?? '');
                }
            }
            foreach ($members as $member) {
                if ($email === '') {
                    $email = (string) ($member['email'] ?? '');
                }
                if ($phone === '') {
                    $phone = (string) ($member['phone'] ?? '');
                }
            }
            $namedOwners = [[
                'name' => $company,
                'personnummer' => $pnr,
                'contact' => true,
                'email' => $email,
                'phone' => $phone,
            ]];
            $kind = 'company';
        }
        $built['owners'] = $namedOwners;
        $groupConflicts = array_merge($group['conflicts'], $built['conflicts']);
        $matchName = '';
        foreach ($built['owners'] as $owner) {
            if ($matchName === '' || !empty($owner['contact'])) {
                $matchName = (string) ($owner['name'] ?? '');
            }
        }
        $pinned = [];
        foreach ($members as $member) {
            $have = trim((string) ($member['contact_id'] ?? ''));
            if ($have !== '' && isset($existing[$have]) && is_array($existing[$have])) {
                $pinned[$have] = true;
            }
        }
        $contactId = null;
        $action = 'create';
        if (count($pinned) === 1) {
            $contactId = (string) array_key_first($pinned);
            $action = 'reuse';
        } elseif (count($pinned) > 1) {
            $action = 'conflict';
            foreach (array_keys($pinned) as $pinnedId) {
                $groupConflicts[] = [
                    'address_key' => (string) $group['key'],
                    'kind' => 'already_linked',
                    'name' => $matchName,
                    'source' => (string) $pinnedId,
                ];
            }
        } else {
            $contactId = jeab_kontakt_find(
                $existing,
                $streetKey,
                $postal,
                jeab_kontakt_group_email($members),
                jeab_kontakt_group_phone($members),
                empty($group['postal_invalid']),
                $matchName,
                $city
            );
            if ($contactId === null) {
                $bareHits = jeab_kontakt_bare_name_hits(
                    $existing,
                    $priorIds,
                    $matchName,
                    $streetKey,
                    jeab_kontakt_group_email($members),
                    jeab_kontakt_group_phone($members)
                );
                if (count($bareHits) === 1) {
                    $contactId = $bareHits[0];
                } elseif (count($bareHits) > 1) {
                    $action = 'conflict';
                }
            }
            if ($action !== 'conflict') {
                $action = $contactId !== null ? 'reuse' : 'create';
            }
        }
        $ids = ['reminders' => [], 'orders' => [], 'orders_skipped_closed' => [], 'bookings' => [], 'inbox_contacts' => []];
        foreach ($members as $member) {
            $bucket = match ((string) $member['kind']) {
                'reminder' => 'reminders',
                'order' => !empty($member['closed']) ? 'orders_skipped_closed' : 'orders',
                'booking' => 'bookings',
                'inbox' => 'inbox_contacts',
                default => '',
            };
            if ($bucket === '') {
                continue;
            }
            $ids[$bucket][] = (string) $member['id'];
            if ((string) $member['contact_id'] !== '' && $contactId !== null && (string) $member['contact_id'] !== $contactId) {
                $groupConflicts[] = [
                    'address_key' => (string) $group['key'],
                    'kind' => 'already_linked',
                    'name' => (string) $member['name'],
                    'source' => $member['kind'] . ':' . $member['id'],
                ];
            }
        }
        $noteLines = [];
        $conflictKinds = [];
        foreach ($groupConflicts as $conflictRow) {
            if (is_array($conflictRow)) {
                $conflictKinds[(string) ($conflictRow['kind'] ?? '')] = true;
            }
        }
        $keepChannels = jeab_kontakt_kind_selected($onlyKinds, 'owner_channel');
        if (isset($conflictKinds['unplaced'])) {
            foreach ($members as $member) {
                if (!is_array($member)) {
                    continue;
                }
                $unplacedRows[] = [
                    'kind' => (string) ($member['kind'] ?? ''),
                    'id' => (string) ($member['id'] ?? ''),
                    'reason' => 'unplaced',
                ];
            }
            $action = 'held';
            $contactId = null;
        } elseif (isset($conflictKinds['ambiguous_channel']) && jeab_kontakt_kind_selected($onlyKinds, 'ambiguous_channel')) {
            $matchKeys = [];
            foreach ($groupConflicts as $conflictRow) {
                if (!is_array($conflictRow) || (string) ($conflictRow['kind'] ?? '') !== 'ambiguous_channel') {
                    continue;
                }
                foreach ((array) ($conflictRow['matches'] ?? []) as $matchKey) {
                    $matchKey = (string) $matchKey;
                    if ($matchKey !== '') {
                        $matchKeys[$matchKey] = true;
                    }
                }
            }
            $picked = jeab_kontakt_pick_newest_placed($placed, array_keys($matchKeys));
            if (is_array($picked) && (string) ($picked['contact_id'] ?? '') !== '') {
                $contactId = (string) $picked['contact_id'];
                $action = 'reuse';
                foreach (array_keys($matchKeys) as $matchKey) {
                    if ($matchKey === (string) ($picked['key'] ?? '')) {
                        continue;
                    }
                    $line = jeab_kontakt_other_place_line($placed[$matchKey] ?? [], $picked);
                    if ($line !== '') {
                        $noteLines[] = $line;
                    }
                }
            } elseif ($skipConflicts) {
                $action = 'held';
                $contactId = null;
            }
        }
        if ($action === 'create') {
            $sameTown = jeab_kontakt_find_same_town($existing, $streetKey, $city);
            if ($sameTown !== null) {
                $contactId = $sameTown;
                $action = 'reuse';
            }
        }
        if ($action === 'create' && jeab_kontakt_fold_text(jeab_kontakt_city_name($city)) === '') {
            // Utan postnummer räcker inte samma telefon. E-post på gatan får fortfarande träffa.
            $channelPhone = !empty($group['needs_postnr']) ? '' : jeab_kontakt_group_phone($members);
            $channelHit = jeab_kontakt_find_same_street_channel(
                $existing,
                $streetKey,
                jeab_kontakt_group_email($members),
                $channelPhone,
                $postal,
                $street
            );
            if ($channelHit !== null) {
                $contactId = $channelHit;
                $action = 'reuse';
            }
        }
        if (jeab_kontakt_kind_selected($onlyKinds, 'extra_email') || jeab_kontakt_kind_selected($onlyKinds, 'extra_phone')) {
            foreach ($groupConflicts as $conflictRow) {
                if (!is_array($conflictRow)) {
                    continue;
                }
                $conflictKind = (string) ($conflictRow['kind'] ?? '');
                if ($conflictKind === 'extra_email' && jeab_kontakt_kind_selected($onlyKinds, 'extra_email')) {
                    $extraEmail = trim((string) ($conflictRow['email'] ?? ''));
                    if ($extraEmail !== '') {
                        $noteLines[] = 'E-post: ' . $extraEmail;
                    }
                }
                if ($conflictKind === 'extra_phone' && jeab_kontakt_kind_selected($onlyKinds, 'extra_phone')) {
                    $extraPhone = trim((string) ($conflictRow['phone'] ?? ''));
                    if ($extraPhone !== '') {
                        $noteLines[] = 'Tel: ' . $extraPhone;
                    }
                }
            }
        }
        if (!$dry && $action === 'create') {
            $contactOwner = null;
            $otherOwner = null;
            foreach ($built['owners'] as $owner) {
                if ($contactOwner === null && !empty($owner['contact'])) {
                    $contactOwner = $owner;
                    continue;
                }
                if ($otherOwner === null) {
                    $otherOwner = $owner;
                }
            }
            if ($contactOwner === null) {
                $contactOwner = $built['owners'][0] ?? ['name' => '', 'personnummer' => '', 'email' => '', 'phone' => ''];
            }
            $row = jeab_kontakt_blank([
                'name' => (string) ($contactOwner['name'] ?? ''),
                'email' => (string) ($contactOwner['email'] ?? ''),
                'phone' => (string) ($contactOwner['phone'] ?? ''),
                'personnummer' => (string) ($contactOwner['personnummer'] ?? ''),
                'street' => $street,
                'street_key' => $streetKey,
                'postal_code' => $postal,
                'city' => $city,
                'fastighetsbeteckning' => $fastighet,
                'kind' => $kind,
                'source' => 'link',
            ]);
            if ($kind !== 'company' && is_array($otherOwner)) {
                $row['owners'][] = [
                    'name' => (string) ($otherOwner['name'] ?? ''),
                    'personnummer' => (string) ($otherOwner['personnummer'] ?? ''),
                    'contact' => false,
                    'email' => $keepChannels ? (string) ($otherOwner['email'] ?? '') : '',
                    'phone' => $keepChannels ? (string) ($otherOwner['phone'] ?? '') : '',
                ];
            }
            $contactId = (string) $row['id'];
            jeab_kontakter_mutate(function (array &$contacts) use ($row): void {
                $contacts[(string) $row['id']] = $row;
            });
            $existing[$contactId] = $row;
            $created++;
        } elseif (!$dry && $contactId !== null && isset($existing[$contactId]) && is_array($existing[$contactId])) {
            $filled = jeab_kontakt_fill_blanks($existing[$contactId], [
                'street' => $street,
                'street_key' => $streetKey,
                'postal_code' => $postal,
                'city' => $city,
                'fastighetsbeteckning' => $fastighet,
            ]);
            if ($filled !== $existing[$contactId]) {
                $existing[$contactId] = $filled;
                jeab_kontakter_mutate(function (array &$contacts) use ($contactId, $filled): void {
                    if (isset($contacts[$contactId]) && is_array($contacts[$contactId])) {
                        $contacts[$contactId] = jeab_kontakt_fill_blanks($contacts[$contactId], $filled);
                    }
                });
            }
        }
        if (!$dry && $action !== 'held' && $contactId !== null && isset($existing[$contactId]) && is_array($existing[$contactId])) {
            $row = $existing[$contactId];
            $changed = false;
            if ($keepChannels) {
                $nextOwners = jeab_kontakt_keep_owner_channels($row, $built['owners']);
                if ($nextOwners !== $row) {
                    $row = $nextOwners;
                    $changed = true;
                }
            }
            foreach (array_values(array_unique($noteLines)) as $line) {
                $line = trim((string) $line);
                $before = jeab_kontakt_ovrigt($row);
                if ($line === '' || ($before !== '' && str_contains($before, $line))) {
                    continue;
                }
                $row['ovrigt'] = function_exists('jeab_kontakt_append_ovrigt')
                    ? jeab_kontakt_append_ovrigt($before, $line)
                    : ($before === '' ? $line : $before . "\n" . $line);
                $changed = true;
            }
            if ($changed) {
                $existing[$contactId] = $row;
                jeab_kontakt_patch_saved($contactId, $row);
            }
        }
        $inboxOnly = $members !== [];
        foreach ($members as $member) {
            if (!is_array($member) || (string) ($member['kind'] ?? '') !== 'inbox') {
                $inboxOnly = false;
                break;
            }
        }
        if (!$dry && $action !== 'held' && $contactId !== null) {
            foreach ($members as $member) {
                if ((string) $member['contact_id'] !== '') {
                    continue;
                }
                $mid = (int) $member['id'];
                if ($member['kind'] === 'reminder' && jeab_kontakt_stamp_reminder($store, $mid, $contactId)) {
                    $linked['reminders']++;
                } elseif ($member['kind'] === 'order' && empty($member['closed']) && jeab_kontakt_stamp_order($mid, $contactId)) {
                    $linked['orders']++;
                } elseif ($member['kind'] === 'booking' && jeab_kontakt_stamp_booking($store, $mid, $contactId)) {
                    $linked['bookings']++;
                } elseif ($inboxOnly && $member['kind'] === 'inbox') {
                    jeab_kontakt_stamp_inbox((string) $member['id'], $contactId);
                }
            }
        }
        $newest = 0;
        foreach ($members as $member) {
            if (is_array($member)) {
                $newest = max($newest, jeab_kontakt_member_time($member));
            }
        }
        $placed[(string) $group['key']] = [
            'contact_id' => $action === 'held' ? '' : (string) ($contactId ?? ''),
            'newest' => $newest,
            'street' => $street,
            'postal' => $postal,
            'city' => $city,
            'email' => jeab_kontakt_group_email($members),
            'phone' => jeab_kontakt_group_phone($members),
        ];
        foreach ($groupConflicts as $conflict) {
            if (!is_array($conflict)) {
                continue;
            }
            if ((string) ($conflict['kind'] ?? '') === 'unplaced') {
                $conflicts[] = [
                    'address_key' => (string) ($conflict['address_key'] ?? $group['key']),
                    'kind' => 'unplaced',
                    'source' => (string) ($conflict['source'] ?? ''),
                ];
                continue;
            }
            $conflicts[] = $conflict;
        }
        $report[] = [
            'address_key' => (string) $group['key'],
            'street' => $street,
            'postal_code' => $postal,
            'city' => $city,
            'fastighetsbeteckning' => $fastighet,
            'contact_id' => $contactId,
            'action' => $action,
            'kind' => $kind,
            'owners' => array_map(static function (array $owner): array {
                return [
                    'name' => (string) ($owner['name'] ?? ''),
                    'contact' => !empty($owner['contact']),
                    'email' => (string) ($owner['email'] ?? ''),
                    'phone' => (string) ($owner['phone'] ?? ''),
                    'personnummer_finns' => trim((string) ($owner['personnummer'] ?? '')) !== '',
                ];
            }, $built['owners']),
            'flagged' => $built['flagged'],
            'reminders' => $ids['reminders'],
            'orders' => $ids['orders'],
            'orders_skipped_closed' => $ids['orders_skipped_closed'],
            'bookings' => $ids['bookings'],
            'inbox_contacts' => $ids['inbox_contacts'],
            'needs_postnr' => !empty($group['needs_postnr']),
        ];
    }
    $hits = [];
    foreach ($report as $group) {
        foreach (['reminders' => 'reminder', 'orders' => 'order', 'orders_skipped_closed' => 'order', 'bookings' => 'booking', 'inbox_contacts' => 'inbox'] as $bucket => $kind) {
            foreach ((array) ($group[$bucket] ?? []) as $id) {
                $hits[$kind . ':' . (string) $id] = ($hits[$kind . ':' . (string) $id] ?? 0) + 1;
            }
        }
    }
    $linkedSources = 0;
    foreach ((array) ($prepared['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $key = jeab_kontakt_member_key($item);
        if (($hits[$key] ?? 0) >= 1) {
            $linkedSources++;
            continue;
        }
        if (isset($nonpersonKeys[$key])) {
            continue;
        }
        $unlinked[] = [
            'kind' => (string) ($item['kind'] ?? ''),
            'id' => (string) ($item['id'] ?? ''),
            'reason' => 'osorterad',
        ];
    }
    $balanceOk = $sourceCount === $linkedSources + $skipped + $skippedInternal + $skippedNonperson + count($unlinked);
    $unplaced = [];
    $seenUnplaced = [];
    foreach ($unplacedRows as $row) {
        $key = (string) ($row['kind'] ?? '') . ':' . (string) ($row['id'] ?? '');
        if (isset($seenUnplaced[$key])) {
            continue;
        }
        $seenUnplaced[$key] = true;
        $unplaced[] = [
            'kind' => (string) ($row['kind'] ?? ''),
            'id' => (string) ($row['id'] ?? ''),
            'reason' => 'unplaced',
        ];
    }
    if (!$dry && function_exists('jeab_kontakt_dedupe_fold')) {
        // Hopslagningen är beslutad. Taket i jeab_kontakter_decided_remove gäller
        // i stället för den allmänna spärren; över taket tas ingenting bort.
        $folded = jeab_kontakter_decided_remove(static function (array &$contacts) use ($store): array {
            return jeab_kontakt_dedupe_fold($contacts, $store);
        }, 'kontakter.dedupe');
        if (is_array($folded) && $folded !== []) {
            jeab_kontakt_dedupe_finish($folded, $store);
        }
    }
    $createdNet = 0;
    $ovrigtLines = 0;
    $ovrigtContacts = 0;
    if (!$dry) {
        foreach (jeab_kontakter_load() as $afterId => $afterRow) {
            if (!is_array($afterRow)) {
                continue;
            }
            $afterId = (string) $afterId;
            if (!isset($priorIds[$afterId])) {
                $createdNet++;
            }
            $addedLines = jeab_kontakt_ovrigt_added_lines($ovrigtBefore[$afterId] ?? '', jeab_kontakt_ovrigt($afterRow));
            if ($addedLines > 0) {
                $ovrigtLines += $addedLines;
                $ovrigtContacts++;
            }
        }
    }
    return jeab_kontakt_strip_secrets([
        'ok' => true,
        'dry' => $dry,
        'sources' => $sourceCount,
        'linked_sources' => $linkedSources,
        'skipped' => $skipped,
        'skipped_internal' => $skippedInternal,
        'skipped_internal_sources' => $skippedInternalSources,
        'skipped_nonperson' => $skippedNonperson,
        'skipped_shared_phone' => $skippedSharedPhone,
        'skipped_shared_email' => $skippedSharedEmail,
        'unlinked' => $unlinked,
        'unplaced' => $unplaced,
        'backup_file' => $backupFile,
        'balance_ok' => $balanceOk,
        'groups' => jeab_kontakt_fold_link_report_groups($report),
        'conflicts' => $conflicts,
        'company' => jeab_kontakter_company_review($store),
        'created' => $dry ? 0 : $created,
        'created_net' => $dry ? 0 : $createdNet,
        'ovrigt_lines' => $dry ? 0 : $ovrigtLines,
        'ovrigt_contacts' => $dry ? 0 : $ovrigtContacts,
        'linked' => $dry ? ['reminders' => 0, 'orders' => 0, 'bookings' => 0] : $linked,
    ]);
}

/** @param list<array<string, mixed>> $members */
function jeab_kontakt_group_email(array $members): string
{
    $pick = static function (array $member, bool $looseOnly) : string {
        if ($looseOnly && (string) ($member['street_key'] ?? '') !== '') {
            return '';
        }
        $email = (string) ($member['email'] ?? '');
        if ($email === '' || jeab_kontakt_address_blocked($email)) {
            return '';
        }
        return $email;
    };
    foreach ($members as $member) {
        $email = $pick($member, true);
        if ($email !== '') {
            return $email;
        }
    }
    foreach ($members as $member) {
        $email = $pick($member, false);
        if ($email !== '') {
            return $email;
        }
    }
    return '';
}

/** @param list<array<string, mixed>> $members */
function jeab_kontakt_group_phone(array $members): string
{
    $pick = static function (array $member, bool $looseOnly): string {
        if ($looseOnly && (string) ($member['street_key'] ?? '') !== '') {
            return '';
        }
        $phone = (string) ($member['phone'] ?? '');
        if ($phone === '' || jeab_kontakt_phone_blocked($phone)) {
            return '';
        }
        return $phone;
    };
    foreach ($members as $member) {
        $phone = $pick($member, true);
        if ($phone !== '') {
            return $phone;
        }
    }
    foreach ($members as $member) {
        $phone = $pick($member, false);
        if ($phone !== '') {
            return $phone;
        }
    }
    return '';
}

/**
 * Samma token som inbox.gp_probe. Anropas från hook.php.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_link_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $result = jeab_kontakter_link($input);
    $result['status'] = !empty($result['ok']) ? 200 : 400;
    return $result;
}

/**
 * Samma svar som torrkörning av kontakter.link. Skriver aldrig.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_link_report_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $input['dry'] = 1;
    $result = jeab_kontakter_link($input);
    $result['status'] = !empty($result['ok']) ? 200 : 400;
    return $result;
}

/** Filnamn från kontakter.bak-ögonblicksbilden. Ingen sökväg. */
function jeab_kontakt_backup_filename(string $name): bool
{
    if ($name === '' || $name !== basename($name)) {
        return false;
    }
    if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0") || str_contains($name, '..')) {
        return false;
    }
    return preg_match('/^kontakter\.bak-\d{8}-\d{6}(?:-\d+)?\.json$/', $name) === 1;
}

/**
 * Hook kontakter.link_audit. Bara läsning. Listar id.
 * orphans: ingen källa pekar på kontakten.
 * shared_unknown_town: samma gata och nummer, och minst en saknar ort.
 * backup: filnamn i datakatalogen. new_since_backup är id som tillkommit sedan kopian.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_link_audit(array $input, ?JeabStore $store = null): array
{
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabStore) {
        return ['ok' => false, 'error' => 'Ingen lagring.'];
    }
    $contacts = jeab_kontakter_load();
    $pointed = jeab_kontakt_pointed_ids($store);
    $orphans = [];
    $byStreet = [];
    foreach ($contacts as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string) ($row['id'] ?? $id);
        if ($id === '') {
            continue;
        }
        if (!isset($pointed[$id])) {
            $orphans[] = $id;
        }
        $streetKey = jeab_kontakt_effective_street_key((string) ($row['street'] ?? ''), (string) ($row['street_key'] ?? ''));
        if ($streetKey === '') {
            continue;
        }
        $byStreet[$streetKey][] = [
            'id' => $id,
            'unknown' => jeab_kontakt_fold_text(jeab_kontakt_city_name((string) ($row['city'] ?? ''))) === '',
        ];
    }
    sort($orphans);
    $shared = [];
    foreach ($byStreet as $bucket) {
        if (count($bucket) < 2) {
            continue;
        }
        $unknown = false;
        foreach ($bucket as $item) {
            if (!empty($item['unknown'])) {
                $unknown = true;
                break;
            }
        }
        if (!$unknown) {
            continue;
        }
        foreach ($bucket as $item) {
            $shared[(string) $item['id']] = true;
        }
    }
    $sharedIds = array_keys($shared);
    sort($sharedIds);
    $out = [
        'ok' => true,
        'orphans' => $orphans,
        'shared_unknown_town' => $sharedIds,
    ];
    if (!array_key_exists('backup', $input)) {
        return $out;
    }
    $backup = trim((string) $input['backup']);
    if (!jeab_kontakt_backup_filename($backup)) {
        return ['ok' => false, 'error' => 'Ogiltigt filnamn.'];
    }
    $dir = jeab_data_dir();
    $realDir = realpath($dir);
    $path = $dir . '/' . $backup;
    $realPath = realpath($path);
    if ($realDir === false || $realPath === false || !is_file($realPath) || dirname($realPath) !== $realDir || basename($realPath) !== $backup) {
        return ['ok' => false, 'error' => 'Kopian saknas.'];
    }
    $payload = json_decode((string) file_get_contents($realPath), true);
    $previous = [];
    $saved = is_array($payload) && is_array($payload['contacts'] ?? null) ? $payload['contacts'] : [];
    foreach ($saved as $id => $row) {
        $savedId = is_array($row) ? trim((string) ($row['id'] ?? '')) : '';
        if ($savedId === '') {
            $savedId = trim((string) $id);
        }
        if ($savedId !== '') {
            $previous[$savedId] = true;
        }
    }
    $fresh = [];
    foreach ($contacts as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string) ($row['id'] ?? $id);
        if ($id !== '' && !isset($previous[$id])) {
            $fresh[] = $id;
        }
    }
    sort($fresh);
    $out['backup'] = $backup;
    $out['new_since_backup'] = $fresh;
    return $out;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_link_audit_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $result = jeab_kontakter_link_audit($input);
    $result['status'] = !empty($result['ok']) ? 200 : 400;
    return $result;
}

/** Skräp, testdata eller personal. Samma personalregel som kopplingen. */
function jeab_kontakt_source_is_disposable(array $item): bool
{
    return jeab_kontakt_source_is_test($item) || jeab_kontakt_source_is_staff($item);
}

/** Personnummer på kontakten själv. En sådan kontakt tas aldrig bort. */
function jeab_kontakt_row_has_personnummer(array $row): bool
{
    if (trim((string) ($row['personnummer'] ?? '')) !== '') {
        return true;
    }
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (is_array($owner) && trim((string) ($owner['personnummer'] ?? '')) !== '') {
            return true;
        }
    }
    return false;
}

/** Kontakten själv är personal enligt samma regel som en källa. */
function jeab_kontakt_row_is_staff(array $row): bool
{
    $item = ['name' => '', 'email' => '', 'phone' => ''];
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $name = trim((string) ($owner['name'] ?? ''));
        if ($item['name'] === '' || !empty($owner['contact'])) {
            $item['name'] = $name;
            $item['email'] = (string) ($owner['email'] ?? '');
            $item['phone'] = (string) ($owner['phone'] ?? '');
        }
    }
    return $item['name'] !== '' && jeab_kontakt_source_is_staff($item);
}

/**
 * Samma hopp som kopplingen lägger i skipped_internal:
 * personal, händelsenamn, fejkadress eller intern plats.
 */
function jeab_kontakt_source_is_skipped_internal(array $item): bool
{
    if (jeab_kontakt_source_is_staff($item) || jeab_kontakt_event_name((string) ($item['name'] ?? ''))) {
        return true;
    }
    $clean = jeab_kontakt_sanitize_source($item);
    return jeab_kontakt_address_is_fake($clean) || jeab_kontakt_source_internal($clean);
}

/** @return list<string> */
function jeab_kontakt_configured_domains(string $key): array
{
    $cfg = function_exists('jeab_helpscout_config') ? jeab_helpscout_config() : [];
    $raw = $cfg[$key] ?? [];
    if (!is_array($raw) && function_exists('jeab_config')) {
        $app = jeab_config();
        $raw = $app[$key] ?? ($app['kontakter'][$key] ?? []);
    }
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $dom) {
        $dom = mb_strtolower(trim((string) $dom));
        $dom = ltrim($dom, '@');
        if ($dom !== '' && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $dom) === 1) {
            $out[] = $dom;
        }
    }
    return array_values(array_unique($out));
}

function jeab_kontakt_email_domain(string $email): string
{
    $email = jeab_kontakt_email($email);
    if (!str_contains($email, '@')) {
        return '';
    }
    return mb_strtolower((string) substr(strrchr($email, '@') ?: '', 1));
}

function jeab_kontakt_domain_matches(string $domain, array $list): bool
{
    foreach ($list as $blocked) {
        if ($domain === $blocked || str_ends_with($domain, '.' . $blocked)) {
            return true;
        }
    }
    return false;
}

/** Demo Energi Järfälla, Demo Energi Malmö och formuläravsändare. En e-postadress är inget av det. */
function jeab_kontakt_brand_or_form_name(string $name): bool
{
    if (jeab_kontakt_name_is_email($name)) {
        return false;
    }
    $fold = jeab_kontakt_fold_text($name);
    if ($fold === '') {
        return false;
    }
    if (preg_match('/\b(?:jarfalla|malmo)\s+energi\b/u', $fold) === 1) {
        return true;
    }
    return jeab_kontakt_platform_name($name);
}

/**
 * Personal som bara ska ligga kvar när en riktig kund är kopplad.
 * Förnamn och efternamn från config, plus de korta personalnamnen.
 */
function jeab_kontakt_named_staff(string $name): bool
{
    $name = trim($name);
    if ($name === '') {
        return false;
    }
    if (jeab_kontakt_source_is_staff(['name' => $name, 'email' => '', 'phone' => ''])) {
        return true;
    }
    $fold = jeab_kontakt_fold_text($name);
    if ($fold === 'sam l') {
        return true;
    }
    $tokens = jeab_kontakt_name_tokens($name);
    return in_array('demo', $tokens, true) || in_array('Demo', $tokens, true);
}

/**
 * Leverantör, nyhetsbrev, eget varumärke, formulär eller intern händelse.
 * Tom sträng när kontakten kan vara en kund.
 */
function jeab_kontakt_noncustomer_reason(array $row): string
{
    $email = jeab_kontakt_row_email($row);
    $domain = jeab_kontakt_email_domain($email);
    $local = str_contains($email, '@') ? (string) strstr($email, '@', true) : '';
    $hay = $domain . ' ' . $local;
    if ($hay !== ' ' && preg_match('/newsletter|nyhetsbrev|noreply|no-reply|donotreply|do-not-reply/', $hay) === 1) {
        return 'newsletter';
    }
    $blocked = array_merge(
        jeab_kontakt_configured_domains('import_supplier_domains'),
        jeab_kontakt_configured_domains('kontakter_exclude_domains')
    );
    if ($domain !== '' && jeab_kontakt_domain_matches($domain, $blocked)) {
        return 'supplier';
    }
    $name = '';
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $candidate = trim((string) ($owner['name'] ?? ''));
        if ($candidate !== '' && ($name === '' || !empty($owner['contact']))) {
            $name = $candidate;
        }
    }
    if (jeab_kontakt_brand_or_form_name($name)) {
        return 'brand';
    }
    if (jeab_kontakt_event_name($name) || jeab_kontakt_text_is_internal($name)) {
        return 'event';
    }
    if ($local !== '' && preg_match('/products|sam/', $local) === 1) {
        return 'test';
    }
    return '';
}

/**
 * Påminnelse, order eller bokning som hör till en riktig kund.
 * Skräp, test och personal räknas inte.
 *
 * @param list<array<string, mixed>> $sources
 */
function jeab_kontakt_sources_have_real_customer(array $sources): bool
{
    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }
        if (!in_array((string) ($source['kind'] ?? ''), ['reminder', 'order', 'booking'], true)) {
            continue;
        }
        if (jeab_kontakt_source_is_disposable($source) || jeab_kontakt_source_is_skipped_internal($source)) {
            continue;
        }
        return true;
    }
    return false;
}

/**
 * @param list<array<string, mixed>> $sources
 */
function jeab_kontakt_sources_are_internal(array $sources): bool
{
    if ($sources === []) {
        return true;
    }
    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }
        $kind = (string) ($source['kind'] ?? '');
        if (in_array($kind, ['reminder', 'order', 'booking'], true)) {
            if (!jeab_kontakt_source_is_disposable($source) && !jeab_kontakt_source_is_skipped_internal($source)) {
                return false;
            }
            continue;
        }
        if ($kind === 'inbox') {
            $internal = jeab_kontakt_source_is_staff($source)
                || jeab_kontakt_source_is_skipped_internal($source)
                || jeab_kontakt_brand_or_form_name((string) ($source['name'] ?? ''))
                || jeab_kontakt_event_name((string) ($source['name'] ?? ''))
                || jeab_kontakt_text_is_internal((string) ($source['name'] ?? ''));
            if (!$internal) {
                return false;
            }
        }
    }
    return true;
}

/** Visningsnamnet på kontaktpersonen. Tomt när ingen ägare har namn. */
function jeab_kontakt_row_display_name(array $row): string
{
    $name = '';
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $candidate = trim((string) ($owner['name'] ?? ''));
        if ($candidate !== '' && ($name === '' || !empty($owner['contact']))) {
            $name = $candidate;
        }
    }
    return $name;
}

/** Varje ägarnamn är ett bolag. En person, eller bara en e-postadress, räknas inte. */
function jeab_kontakt_row_company_without_person(array $row): bool
{
    $saw = false;
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $name = trim((string) ($owner['name'] ?? ''));
        if ($name === '' || jeab_kontakt_name_is_email($name)) {
            continue;
        }
        if (jeab_kontakt_obvious_company_name($name)) {
            $saw = true;
            continue;
        }
        return false;
    }
    return $saw;
}

/**
 * Kontakt-id som ett importerat Help Scout-ärende, en inkorgstråd,
 * en påminnelse, en order eller en bokning pekar på.
 *
 * @return array<string, true>
 */
function jeab_kontakt_pointed_ids(JeabStore $store): array
{
    $ids = [];
    $add = static function (string $id) use (&$ids): void {
        $id = trim($id);
        if ($id !== '') {
            $ids[$id] = true;
        }
    };
    foreach (jeab_kontakt_collect_sources($store) as $item) {
        if (is_array($item)) {
            $add((string) ($item['contact_id'] ?? ''));
        }
    }
    if (function_exists('jeab_inkorg_threads')) {
        foreach (jeab_inkorg_threads() as $thread) {
            if (is_array($thread)) {
                $add((string) ($thread['contact_id'] ?? ''));
            }
        }
    }
    if (function_exists('jeab_hs_import_index')) {
        foreach (jeab_hs_import_index()['by_id'] as $row) {
            if (is_array($row) && empty($row['deleted'])) {
                $add((string) ($row['contact_id'] ?? ''));
            }
        }
    }
    return $ids;
}

/**
 * Bolag utan person som något redan pekar på. De lämnas kvar och listas här.
 *
 * @return list<array{id:string,reason:string}>
 */
function jeab_kontakter_company_review(JeabStore $store): array
{
    $pointed = jeab_kontakt_pointed_ids($store);
    $out = [];
    foreach (jeab_kontakter_load() as $id => $row) {
        $id = (string) $id;
        if (!is_array($row) || !isset($pointed[$id]) || !jeab_kontakt_row_company_without_person($row)) {
            continue;
        }
        $out[] = ['id' => $id, 'reason' => 'company?'];
    }
    return $out;
}

/**
 * Kontakter vars alla källor är skräp, test, personal eller bara skipped_internal.
 * Leverantörer, nyhetsbrev, egna varumärken, formulär, interna händelser och
 * bolag utan person föreslås när inget pekar på dem och de saknar personnummer.
 * Ett importerat ärende, en inkorgstråd, en påminnelse, en order eller en bokning
 * som pekar på kontakten lämnar den kvar, oavsett skäl.
 *
 * @return list<array{id:string,name:string,sources:list<array{kind:string,id:string}>,reason:string}>
 */
function jeab_kontakter_prune_candidates(JeabStore $store): array
{
    $byContact = [];
    foreach (jeab_kontakt_collect_sources($store) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $cid = trim((string) ($item['contact_id'] ?? ''));
        if ($cid === '') {
            continue;
        }
        $byContact[$cid][] = $item;
    }
    $pointed = jeab_kontakt_pointed_ids($store);
    $out = [];
    foreach (jeab_kontakter_load() as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string) $id;
        if (jeab_kontakt_row_has_personnummer($row) || isset($pointed[$id])) {
            continue;
        }
        $sources = $byContact[$id] ?? [];
        $listed = [];
        $disposableOnly = $sources !== [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            if (!jeab_kontakt_source_is_disposable($source) && !jeab_kontakt_source_is_skipped_internal($source)) {
                $disposableOnly = false;
            }
            $listed[] = [
                'kind' => (string) ($source['kind'] ?? ''),
                'id' => (string) ($source['id'] ?? ''),
            ];
        }
        $name = jeab_kontakt_row_display_name($row);
        $reason = jeab_kontakt_noncustomer_reason($row);
        if ($reason === '' && jeab_kontakt_row_company_without_person($row)) {
            $reason = 'company';
        }
        $staff = jeab_kontakt_named_staff($name) || jeab_kontakt_row_is_staff($row);
        $staffInternal = $staff && jeab_kontakt_sources_are_internal($sources);
        $sourcelessStaff = $sources === [] && $staff;
        if (!$disposableOnly && $reason === '' && !$staffInternal && !$sourcelessStaff) {
            continue;
        }
        if ($sources === [] && !$staff && $reason === '') {
            continue;
        }
        if (jeab_kontakt_name_is_email($name)) {
            $name = '';
        }
        $out[] = ['id' => $id, 'name' => $name, 'sources' => $listed, 'reason' => $reason !== '' ? $reason : ($staff ? 'staff' : 'junk')];
    }
    return $out;
}

/** Tar bort contact_id på en inkorgskontakt om fältet finns. Övriga fält lämnas. */
function jeab_kontakt_clear_inbox(string $id): bool
{
    $id = trim($id);
    if (function_exists('jeab_inkorg_contacts_archived') && jeab_inkorg_contacts_archived()) {
        return false;
    }
    if ($id === '' || !function_exists('jeab_inkorg_contacts') || !function_exists('jeab_inkorg_locked')) {
        return false;
    }
    $row = jeab_inkorg_contacts()[$id] ?? null;
    if (!is_array($row) || trim((string) ($row['contact_id'] ?? '')) === '') {
        return false;
    }
    jeab_kontakt_inbox_locked(function (?array $data) use ($id): array {
        $data = is_array($data) ? $data : ['contacts' => []];
        $contacts = is_array($data['contacts'] ?? null) ? $data['contacts'] : [];
        if (!isset($contacts[$id]) || !is_array($contacts[$id]) || trim((string) ($contacts[$id]['contact_id'] ?? '')) === '') {
            return [false, null];
        }
        unset($contacts[$id]['contact_id']);
        $data['contacts'] = $contacts;
        return [true, $data];
    });
    return true;
}

/**
 * Hook kontakter.prune. dry=1 är standard och skriver inget.
 * dry=0 tar bort kontakten och nollar contact_id. Källposternas övriga fält rörs inte.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_prune(array $input, ?JeabStore $store = null): array
{
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabStore) {
        return ['ok' => false, 'error' => 'Ingen lagring.'];
    }
    $dry = jeab_kontakt_dry($input);
    $allowMass = jeab_kontakt_allow_mass($input);
    $candidates = jeab_kontakter_prune_candidates($store);
    $ids = [];
    foreach ($candidates as $candidate) {
        $ids[] = (string) $candidate['id'];
    }
    $total = count(jeab_kontakter_load());
    $drop = count($ids);
    $blocked = jeab_kontakter_mass_delete_blocked($total, $drop);
    $summary = [];
    foreach ($candidates as $candidate) {
        $summary[] = [
            'id' => (string) ($candidate['id'] ?? ''),
            'reason' => (string) ($candidate['reason'] ?? ''),
        ];
    }
    if (!$dry && $blocked && !$allowMass) {
        return jeab_kontakt_strip_secrets([
            'ok' => false,
            'dry' => false,
            'error' => jeab_kontakter_mass_delete_error($total, $drop),
            'blocked' => true,
            'contacts' => $candidates,
            'summary' => $summary,
            'removed' => 0,
            'would_remove' => $drop,
        ]);
    }
    $removed = 0;
    if (!$dry && $candidates !== []) {
        foreach ($candidates as $candidate) {
            foreach ($candidate['sources'] as $source) {
                $kind = (string) ($source['kind'] ?? '');
                $sid = (string) ($source['id'] ?? '');
                if ($kind === 'reminder') {
                    jeab_kontakt_clear_reminder($store, (int) $sid);
                } elseif ($kind === 'booking') {
                    jeab_kontakt_clear_booking($store, (int) $sid);
                } elseif ($kind === 'order') {
                    jeab_kontakt_clear_order((int) $sid);
                } elseif ($kind === 'inbox') {
                    jeab_kontakt_clear_inbox($sid);
                }
            }
        }
        jeab_kontakter_mutate(function (array &$contacts) use ($ids): void {
            foreach ($ids as $id) {
                unset($contacts[$id]);
            }
        }, $allowMass);
        $removed = count($ids);
    }
    return jeab_kontakt_strip_secrets([
        'ok' => true,
        'dry' => $dry,
        'blocked' => $blocked,
        'contacts' => $candidates,
        'summary' => $summary,
        'removed' => $dry ? 0 : $removed,
        'would_remove' => $drop,
    ]);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_prune_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $result = jeab_kontakter_prune($input);
    $result['status'] = !empty($result['ok']) ? 200 : 400;
    return $result;
}

/**
 * Hela personnumret till inloggad personal. Anropas bara från Visa.
 * Numret får inte hamna i logg, feltext eller sidans källkod.
 */
function jeab_kontakt_reveal_personnummer(string $id, string $slot): string
{
    if (!function_exists('jeab_auth_ok') || !jeab_auth_ok()) {
        throw new InvalidArgumentException('Logga in.');
    }
    $row = jeab_kontakt_get($id);
    if (!is_array($row)) {
        throw new InvalidArgumentException('Kontakten saknas.');
    }
    $index = $slot === '2' ? 1 : ($slot === '1' ? 0 : -1);
    if ($index < 0) {
        throw new InvalidArgumentException('Välj en ägare.');
    }
    $owners = array_values(is_array($row['owners'] ?? null) ? $row['owners'] : []);
    $owner = $owners[$index] ?? null;
    $pnr = is_array($owner) ? trim((string) ($owner['personnummer'] ?? '')) : '';
    if ($pnr === '') {
        throw new InvalidArgumentException('Inget personnummer sparat.');
    }
    return $pnr;
}

function jeab_kontakt_fetch(string $id): ?array
{
    $sql = function_exists('jeab_kontakter_pdo_store') ? jeab_kontakter_pdo_store() : null;
    if ($sql instanceof JeabPdoContactStore) {
        $row = $sql->get($id);
        return is_array($row) ? $row : null;
    }
    $id = trim($id);
    if ($id === '') {
        return null;
    }
    $row = jeab_kontakter_load()[$id] ?? null;
    return is_array($row) ? $row : null;
}

function jeab_kontakt_get(string $id): ?array
{
    $row = jeab_kontakt_fetch($id);
    if (!is_array($row)) {
        return null;
    }
    if (function_exists('jeab_record_company_allowed') && !jeab_record_company_allowed($row['company'] ?? null)) {
        return null;
    }

    return $row;
}

/** Länk till registret. Finns contact_id öppnas den kontakten, annars söks e-posten. */
function jeab_kontakt_page_url(string $contactId = '', string $email = ''): string
{
    $contactId = trim($contactId);
    if ($contactId !== '' && jeab_kontakt_get($contactId) !== null) {
        return jeab_url('index.php?' . http_build_query(['p' => 'kontakter', 'id' => $contactId]));
    }
    $email = trim($email);
    if ($email !== '') {
        return jeab_url('index.php?' . http_build_query(['p' => 'kontakter', 'q' => $email]));
    }
    return jeab_url('index.php?p=kontakter');
}

const JEAB_KONTAKT_PAGE = 50;

/** Synliga kopplingar på ett kort. Resten räknas, men hämtas inte. */
const JEAB_KONTAKT_LINK_CAP = 24;

/**
 * Kortet får inte äta hela skripttiden. 0,7 s till länkar och ärenden,
 * sedan ritas det som hunnit läsas.
 */
function jeab_kontakt_card_budget_begin(float $seconds = 0.7): void
{
    $GLOBALS['_JEAB_KONTAKT_CARD_DEADLINE'] = microtime(true) + max(0.05, $seconds);
}

function jeab_kontakt_card_budget_hit(): bool
{
    $deadline = $GLOBALS['_JEAB_KONTAKT_CARD_DEADLINE'] ?? null;

    return is_float($deadline) && microtime(true) >= $deadline;
}

/** Avbryter en lång läsning på MySQL/MariaDB. SQLite ignorerar det. */
function jeab_pdo_read_budget(PDO $pdo, int $ms): void
{
    if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        return;
    }
    $ms = max(50, $ms);
    try {
        $pdo->exec('SET SESSION MAX_EXECUTION_TIME=' . $ms);
    } catch (Throwable) {
    }
    try {
        $pdo->exec('SET SESSION max_statement_time=' . max(1, (int) ceil($ms / 1000)));
    } catch (Throwable) {
    }
}

function jeab_pdo_read_budget_clear(PDO $pdo): void
{
    if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        return;
    }
    try {
        $pdo->exec('SET SESSION MAX_EXECUTION_TIME=0');
    } catch (Throwable) {
    }
    try {
        $pdo->exec('SET SESSION max_statement_time=0');
    } catch (Throwable) {
    }
}

function jeab_kontakt_has_address(array $row): bool
{
    return trim((string) ($row['street'] ?? '')) !== '';
}

/** Svensk bokstavsordning (…z å ä ö). Tom gata sorteras sist av anroparen. */
function jeab_kontakt_sort_key(string $text): string
{
    $text = trim($text);
    if (function_exists('mb_strtolower')) {
        $text = mb_strtolower($text, 'UTF-8');
    } else {
        $text = strtolower($text);
    }
    $text = strtr($text, [
        'å' => '{',
        'ä' => '|',
        'ö' => '}',
        'é' => 'e',
        'ü' => 'u',
    ]);
    $padded = preg_replace_callback('/\d+/', static function (array $m): string {
        return str_pad($m[0], 8, '0', STR_PAD_LEFT);
    }, $text);
    return is_string($padded) ? $padded : $text;
}

function jeab_kontakt_list_name(array $row): string
{
    $fallback = '';
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $name = trim((string) ($owner['name'] ?? ''));
        if ($name === '' || (str_contains($name, '@') && jeab_kontakt_name_is_email($name))) {
            continue;
        }
        if (!empty($owner['contact'])) {
            return $name;
        }
        if ($fallback === '') {
            $fallback = $name;
        }
    }
    return $fallback;
}

/**
 * Diskret radtext: företag, namn som saknas, konflikt. Inte chips.
 * $listName är redan uträknat visningsnamn, så listan inte slår upp det igen.
 */
function jeab_kontakt_list_marks(array $row, ?string $listName = null): string
{
    $marks = [];
    if ((string) ($row['kind'] ?? '') === 'company') {
        $marks[] = 'Företag';
    }
    if (!empty($row['name_missing']) && ($listName ?? jeab_kontakt_list_name($row)) === '') {
        $marks[] = 'namn saknas';
    }
    if (!empty($row['conflict'])) {
        $marks[] = 'granska';
    }
    return implode(' · ', $marks);
}

/** Nya rader i Övrigt. Text som redan fanns räknas inte. */
function jeab_kontakt_ovrigt_added_lines(string $before, string $after): int
{
    $before = trim($before);
    $after = trim($after);
    if ($after === '' || $after === $before) {
        return 0;
    }
    $extra = $after;
    if ($before !== '' && str_starts_with($after, $before)) {
        $extra = trim(substr($after, strlen($before)));
    }
    if ($extra === '') {
        return 0;
    }
    $parts = preg_split("/\r\n|\n|\r/", $extra) ?: [];
    $count = 0;
    foreach ($parts as $part) {
        if (trim((string) $part) !== '') {
            $count++;
        }
    }
    return $count;
}

/** Fritext «Övrigt». Nyckeln är ovrigt; note/extra läses om en annan gren hann skriva dit. */
function jeab_kontakt_ovrigt(array $row): string
{
    foreach (['ovrigt', 'övrigt', 'note', 'notes', 'extra'] as $key) {
        $text = trim((string) ($row[$key] ?? ''));
        if ($text !== '') {
            return $text;
        }
    }
    return '';
}

function jeab_kontakt_search_hay(array $row): string
{
    $parts = [
        (string) ($row['street'] ?? ''),
        (string) ($row['postal_code'] ?? ''),
        (string) ($row['city'] ?? ''),
        (string) ($row['fastighetsbeteckning'] ?? ''),
        jeab_kontakt_row_email($row),
        jeab_kontakt_row_phone($row),
        jeab_kontakt_ovrigt($row),
    ];
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (is_array($owner)) {
            $parts[] = (string) ($owner['name'] ?? '');
        }
    }
    return jeab_kontakt_fold_text(implode(' ', $parts));
}

function jeab_kontakt_matches_query(array $row, string $query): bool
{
    if ($query === '') {
        return true;
    }
    $id = (string) ($row['id'] ?? '');
    if ($id !== '' && ($query === $id || $query === jeab_kontakt_fold_text($id))) {
        return true;
    }
    $hay = jeab_kontakt_search_hay($row);
    $tight = str_replace(' ', '', $query);
    return str_contains($hay, $query) || ($tight !== '' && str_contains($hay, $tight));
}

/** Söksträngen är ett helt kontakt-id (kt_…), inte ett prefix. */
function jeab_kontakt_exact_id(string $query): string
{
    $folded = jeab_kontakt_fold_text($query);
    if ($folded === '' || preg_match('/^kt_[a-z0-9]+$/', $folded) !== 1) {
        return '';
    }
    foreach ([$folded, trim($query)] as $candidate) {
        if ($candidate === '') {
            continue;
        }
        $row = jeab_kontakt_get($candidate);
        if (is_array($row) && (string) ($row['id'] ?? '') !== '') {
            return (string) $row['id'];
        }
    }
    return '';
}

/**
 * Bolag från kontaktens eget fält plus kopplade påminnelser, ordrar och bokningar.
 * En kontakt utan känt bolag syns i båda, så importen inte försvinner.
 *
 * @return array<string, array<string, true>>
 */
function jeab_kontakter_office_map(?JeabStore $store): array
{
    $map = [];
    if (!$store instanceof JeabStore) {
        return $map;
    }
    $add = static function (array &$map, string $id, ?string $company): void {
        $id = trim($id);
        $company = jeab_normalize_company($company);
        if ($id === '' || $company === null) {
            return;
        }
        $map[$id][$company] = true;
    };
    foreach ($store->listReminders(true) as $row) {
        if (is_array($row)) {
            $add($map, (string) ($row['contact_id'] ?? ''), function_exists('jeab_reminder_company') ? jeab_reminder_company($row) : (string) ($row['company'] ?? ''));
        }
    }
    if (function_exists('jeab_purchase_all') && (!function_exists('jeab_purchase_sql_live') || jeab_purchase_sql_live() instanceof PDO)) {
        foreach (jeab_purchase_all() as $row) {
            if (is_array($row)) {
                $add($map, (string) ($row['contact_id'] ?? ''), (string) ($row['company'] ?? ''));
            }
        }
    }
    foreach ($store->listAllBookings() as $row) {
        if (is_array($row)) {
            $company = function_exists('jeab_booking_company') ? jeab_booking_company($row) : (string) ($row['company'] ?? '');
            $add($map, (string) ($row['contact_id'] ?? ''), $company);
        }
    }
    return $map;
}

/**
 * @param array<string, true> $linked
 */
function jeab_kontakt_matches_office(array $row, ?string $office, array $linked): bool
{
    $office = jeab_normalize_company($office);
    if ($office === null) {
        return true;
    }
    $known = $linked;
    $stored = jeab_normalize_company($row['company'] ?? null);
    if ($stored !== null) {
        $known[$stored] = true;
    }
    if ($known === []) {
        return true;
    }
    return isset($known[$office]);
}

/**
 * Svensk sortering. Nyckel och visningsnamn räknas en gång per rad, inte i varje jämförelse.
 *
 * @param array<string, array<string, mixed>> $rows
 * @param array<string, string> $names Förberäknat resultat från jeab_kontakt_list_name, nycklat som $rows.
 * @return array<string, array<string, mixed>>
 */
function jeab_kontakter_sort_rows(array $rows, array $names = []): array
{
    $rank = [];
    foreach ($rows as $id => $row) {
        $row = is_array($row) ? $row : [];
        $missing = !jeab_kontakt_has_address($row);
        if ($missing) {
            $text = array_key_exists($id, $names) ? (string) $names[$id] : jeab_kontakt_list_name($row);
        } else {
            $text = (string) ($row['street'] ?? '');
        }
        $rank[$id] = [
            $missing ? 1 : 0,
            jeab_kontakt_sort_key($text),
            jeab_kontakt_sort_key((string) ($row['city'] ?? '')),
            (string) ($row['id'] ?? $id),
        ];
    }
    uksort($rows, static function ($a, $b) use ($rank): int {
        $left = $rank[$a];
        $right = $rank[$b];
        if ($left[0] !== $right[0]) {
            return $left[0] <=> $right[0];
        }
        $cmp = strcmp($left[1], $right[1]);
        if ($cmp !== 0) {
            return $cmp;
        }
        $cmp = strcmp($left[2], $right[2]);
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp($left[3], $right[3]);
    });

    return $rows;
}

/**
 * @param array<string, mixed> $row
 * @return array{id:string,street:string,city:string,name:string,missing:bool,marks:string,hay:string}
 */
function jeab_kontakt_catalog_row(string $id, array $row, ?string $listName = null): array
{
    $place = jeab_kontakt_display_place($row);
    $name = $listName ?? jeab_kontakt_list_name($row);
    $hay = jeab_kontakt_fold_text(implode(' ', [
        $place['street'],
        $place['city'],
        $name,
        (string) ($row['postal_code'] ?? ''),
        function_exists('jeab_kontakt_row_email') ? jeab_kontakt_row_email($row) : '',
        function_exists('jeab_kontakt_row_phone') ? jeab_kontakt_row_phone($row) : '',
    ]));
    if (strlen($hay) > 160) {
        $hay = substr($hay, 0, 160);
    }

    return [
        'id' => $id,
        'street' => $place['street'],
        'city' => $place['city'],
        'name' => $name,
        'missing' => !jeab_kontakt_has_address($row),
        'marks' => jeab_kontakt_list_marks($row, $name),
        'hay' => $hay,
    ];
}

/**
 * Lista för sidan: bolag, chip, sök, svensk sortering, en sida åt gången.
 *
 * @return array{
 *   rows: array<string, array<string, mixed>>,
 *   total: int,
 *   next: int|null,
 *   counts: array{alla:int,adress:int,saknas:int},
 *   catalog: list<array{id:string,street:string,city:string,name:string,missing:bool,marks:string,hay:string}>
 * }
 */
/**
 * @param array{
 *   rows: array<string, array<string, mixed>>,
 *   total: int,
 *   next: int|null,
 *   counts: array{alla:int,adress:int,saknas:int},
 *   catalog: list<array{id:string,street:string,city:string,name:string,missing:bool,marks:string,hay:string}>,
 *   paged?: bool
 * } $view
 * @return array{
 *   rows: array<string, array<string, mixed>>,
 *   total: int,
 *   next: int|null,
 *   counts: array{alla:int,adress:int,saknas:int},
 *   catalog: list<array{id:string,street:string,city:string,name:string,missing:bool,marks:string,hay:string}>,
 *   paged?: bool
 * }
 */
function jeab_kontakter_reveal(array $view, string $query, string $pinId): array
{
    $ids = [];
    $exact = jeab_kontakt_exact_id($query);
    if ($exact !== '') {
        $ids[] = $exact;
    }
    $pinId = trim($pinId);
    if ($pinId !== '' && !in_array($pinId, $ids, true)) {
        $ids[] = $pinId;
    }
    foreach ($ids as $id) {
        if (isset($view['rows'][$id])) {
            continue;
        }
        $row = jeab_kontakt_get($id);
        if (!is_array($row)) {
            continue;
        }
        $row['id'] = (string) ($row['id'] ?? $id);
        $view['rows'] = [$id => $row] + $view['rows'];
        $view['total'] = (int) ($view['total'] ?? 0) + 1;
        $known = false;
        foreach ($view['catalog'] as $item) {
            if ((string) ($item['id'] ?? '') === $id) {
                $known = true;
                break;
            }
        }
        if (!$known) {
            $view['catalog'][] = jeab_kontakt_catalog_row($id, $row);
        }
    }
    return $view;
}

function jeab_kontakter_view(?JeabStore $store, string $query, string $filter, ?string $office, int $limit, string $pinId = ''): array
{
    $rawQuery = trim($query);
    $pinId = trim($pinId);
    $query = jeab_kontakt_fold_text($query);
    if (!in_array($filter, ['alla', 'adress', 'saknas'], true)) {
        $filter = 'alla';
    }
    $office = jeab_normalize_company($office);
    $limit = max(1, $limit);
    // PHP_INT_MAX var signalen som läste hela registret. Listan är alltid en sida.
    if ($limit >= 100000) {
        $limit = 99999;
    }
    $sql = function_exists('jeab_kontakter_pdo_store') ? jeab_kontakter_pdo_store() : null;
    if ($sql instanceof JeabPdoContactStore && jeab_kontakter_sql_is_sole_source()) {
        $page = $sql->page($query, $filter, $office, $limit);
        $total = (int) $page['total'];
        $rows = $page['rows'];
        $catalog = [];
        foreach ($rows as $id => $row) {
            $catalog[] = jeab_kontakt_catalog_row((string) $id, $row);
        }
        return jeab_kontakter_reveal([
            'rows' => $rows,
            'total' => $total,
            'next' => $limit < $total ? $limit : null,
            'counts' => $page['counts'],
            'catalog' => $catalog,
            'paged' => true,
        ], $rawQuery, $pinId);
    }
    $officeMap = jeab_kontakter_office_map($store);
    $inOffice = [];
    foreach (jeab_kontakter_load() as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = (string) $id;
        if (!jeab_kontakt_matches_office($row, $office, $officeMap[$key] ?? [])) {
            continue;
        }
        $row['id'] = (string) ($row['id'] ?? $key);
        $inOffice[$key] = $row;
    }
    $counts = ['alla' => count($inOffice), 'adress' => 0, 'saknas' => 0];
    $names = [];
    foreach ($inOffice as $key => $row) {
        if (jeab_kontakt_has_address($row)) {
            $counts['adress']++;
        } else {
            $counts['saknas']++;
        }
        $names[$key] = jeab_kontakt_list_name($row);
    }
    $sortedOffice = jeab_kontakter_sort_rows($inOffice, $names);
    $matched = [];
    $catalog = [];
    foreach ($sortedOffice as $id => $row) {
        $catalog[] = jeab_kontakt_catalog_row((string) $id, $row, $names[$id] ?? null);
        $has = jeab_kontakt_has_address($row);
        if ($filter === 'adress' && !$has) {
            continue;
        }
        if ($filter === 'saknas' && $has) {
            continue;
        }
        if (!jeab_kontakt_matches_query($row, $query)) {
            continue;
        }
        $matched[(string) $id] = $row;
    }
    $total = count($matched);
    if ($limit < $total) {
        $matched = array_slice($matched, 0, $limit, true);
        $next = $limit;
    } else {
        $next = null;
    }
    return jeab_kontakter_reveal([
        'rows' => $matched,
        'total' => $total,
        'next' => $next,
        'counts' => $counts,
        'catalog' => $catalog,
    ], $rawQuery, $pinId);
}

/** @return array<string, array<string, mixed>> */
function jeab_kontakter_sorted(?string $query = null): array
{
    $limit = 99999;
    if (function_exists('jeab_kontakter_sql_is_sole_source') && jeab_kontakter_sql_is_sole_source()) {
        $limit = JEAB_KONTAKT_PAGE;
    }
    $view = jeab_kontakter_view(null, (string) $query, 'alla', null, $limit);
    return $view['rows'];
}

/**
 * Kopplade påminnelser, ordrar och bokningar. Högst JEAB_KONTAKT_LINK_CAP rader
 * läses. counts är hela träffen. partial när tidsbudgeten eller en fråga tar slut.
 *
 * @return array{reminders:list<array<string,mixed>>,orders:list<array<string,mixed>>,bookings:list<array<string,mixed>>,counts:array{reminders:int,orders:int,bookings:int},partial:bool}
 */
function jeab_kontakt_links(JeabStore $store, string $id): array
{
    $cap = JEAB_KONTAKT_LINK_CAP;
    $out = [
        'reminders' => [],
        'orders' => [],
        'bookings' => [],
        'counts' => ['reminders' => 0, 'orders' => 0, 'bookings' => 0],
        'partial' => false,
    ];
    if ($id === '') {
        return $out;
    }
    $stop = static function () use (&$out): bool {
        if (!jeab_kontakt_card_budget_hit()) {
            return false;
        }
        $out['partial'] = true;

        return true;
    };
    if ($store instanceof JeabPdoStore) {
        if ($stop()) {
            return $out;
        }
        try {
            $n = $store->pdo()->prepare('SELECT COUNT(*) FROM reminders WHERE contact_id = ?');
            $n->execute([$id]);
            $out['counts']['reminders'] = (int) $n->fetchColumn();
            $stmt = $store->pdo()->prepare(
                'SELECT ' . jeab_reminder_list_columns(true, $store->pdo()) . ' FROM reminders WHERE contact_id = ? ORDER BY updated_at DESC, id DESC LIMIT ' . $cap
            );
            $stmt->execute([$id]);
            foreach ($stmt->fetchAll() as $row) {
                if (is_array($row)) {
                    $out['reminders'][] = jeab_hydrate_reminder($row);
                }
            }
        } catch (Throwable) {
            $out['partial'] = true;
        }
        if (!$stop()) {
            try {
                $n = $store->pdo()->prepare('SELECT COUNT(*) FROM bookings WHERE contact_id = ?');
                $n->execute([$id]);
                $out['counts']['bookings'] = (int) $n->fetchColumn();
                $book = $store->pdo()->prepare(
                    'SELECT id, customer_id, ' . jeab_copy_select($store->pdo(), 'bookings', jeab_copy_columns('bookings')) . ', notes, assignee, assignees, company, job_type, starts_at, ends_at, status, contact_id, created_at, updated_at FROM bookings WHERE contact_id = ? ORDER BY starts_at DESC, id DESC LIMIT ' . $cap
                );
                $book->execute([$id]);
                foreach ($book->fetchAll() as $row) {
                    if (is_array($row)) {
                        $out['bookings'][] = jeab_hydrate_booking($row);
                    }
                }
            } catch (Throwable) {
                $out['partial'] = true;
            }
        }
    } else {
        $seenReminders = 0;
        foreach ($store->listReminders(true) as $row) {
            if (!is_array($row) || (string) ($row['contact_id'] ?? '') !== $id) {
                continue;
            }
            $out['counts']['reminders']++;
            if ($seenReminders < $cap) {
                $out['reminders'][] = $row;
                $seenReminders++;
            }
        }
    }
    if (!$stop() && function_exists('jeab_purchase_for_contact') && function_exists('jeab_purchase_sql_pdo') && jeab_purchase_sql_pdo() instanceof PDO) {
        try {
            $pdo = jeab_purchase_sql_pdo();
            $n = $pdo->prepare('SELECT COUNT(*) FROM purchases WHERE contact_id = ?');
            $n->execute([mb_substr($id, 0, 64)]);
            $out['counts']['orders'] = (int) $n->fetchColumn();
        } catch (Throwable) {
            $out['partial'] = true;
        }
        if (!$stop()) {
            try {
                foreach (jeab_purchase_for_contact($id, $cap) as $row) {
                    if (is_array($row)) {
                        $out['orders'][] = $row;
                    }
                }
            } catch (Throwable) {
                $out['partial'] = true;
            }
        }
    } elseif (!$stop() && function_exists('jeab_purchase_all') && (!function_exists('jeab_purchase_sql_live') || jeab_purchase_sql_live() instanceof PDO)) {
        $seenOrders = 0;
        foreach (jeab_purchase_all() as $row) {
            if (!is_array($row) || (string) ($row['contact_id'] ?? '') !== $id) {
                continue;
            }
            $out['counts']['orders']++;
            if ($seenOrders < $cap) {
                $out['orders'][] = $row;
                $seenOrders++;
            }
        }
    }
    if (!$store instanceof JeabPdoStore && !$stop()) {
        $seenBooks = 0;
        foreach ($store->listAllBookings() as $row) {
            if (!is_array($row) || (string) ($row['contact_id'] ?? '') !== $id) {
                continue;
            }
            $out['counts']['bookings']++;
            if ($seenBooks < $cap) {
                $out['bookings'][] = $row;
                $seenBooks++;
            }
        }
    }

    return $out;
}

/**
 * En annan kontakt med samma e-post eller telefon, under adresspärren.
 * Träffar inte den rad som sparas, och inte enbart samma gata.
 *
 * @param array<string, array<string, mixed>> $contacts
 * @param array<string, mixed> $saved
 * @return array{id:string,rule:string}|null
 */
function jeab_kontakt_save_reuse_match(array $contacts, array $saved): ?array
{
    $self = (string) ($saved['id'] ?? '');
    $email = function_exists('jeab_kontakt_row_email') ? jeab_kontakt_row_email($saved) : '';
    $phone = function_exists('jeab_kontakt_row_phone') ? jeab_kontakt_row_phone($saved) : '';
    $name = function_exists('jeab_kontakt_row_name') ? jeab_kontakt_row_name($saved) : '';
    $streetKey = (string) ($saved['street_key'] ?? '');
    if ($streetKey === '' && trim((string) ($saved['street'] ?? '')) !== '') {
        $streetKey = jeab_kontakt_street_key((string) $saved['street']);
    }
    $postal = (string) ($saved['postal_code'] ?? '');
    $city = (string) ($saved['city'] ?? '');
    foreach ($contacts as $id => $other) {
        if (!is_array($other) || (string) $id === '' || (string) $id === $self) {
            continue;
        }
        $rule = jeab_kontakt_reuse_rule($other, $streetKey, $postal, $email, $phone, $name, $city, false);
        if ($rule === 'email' || $rule === 'phone') {
            return ['id' => (string) $id, 'rule' => $rule];
        }
    }

    return null;
}

/**
 * Sparar kontaktformuläret. Återanvänder bara den kontakt som sparas när e-post eller telefon matchar.
 * Andra dubbletter slås ihop via kontakter.dedupe.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakt_save_input(array $input): array
{
    $id = trim((string) ($input['id'] ?? ''));
    $raw = $id !== '' ? jeab_kontakt_fetch($id) : null;
    if (is_array($raw) && function_exists('jeab_record_company_allowed') && !jeab_record_company_allowed($raw['company'] ?? null)) {
        throw new InvalidArgumentException('Inte behörig.');
    }
    $postedCompany = jeab_normalize_company($input['company'] ?? null);
    if ($postedCompany !== null && function_exists('jeab_company_allowed') && !jeab_company_allowed($postedCompany)) {
        throw new InvalidArgumentException('Inte behörig.');
    }
    $existing = is_array($raw) ? $raw : null;
    $streetRaw = trim((string) ($input['street'] ?? ''));
    $street = jeab_kontakt_address_placeholder($streetRaw) ? '' : jeab_norm_street_letter($streetRaw);
    $postedEmail = jeab_kontakt_email((string) ($input['email'] ?? ''));
    // E-post räcker. Gata krävs bara när kontakten saknar e-post.
    if ($street === '' && $postedEmail === '') {
        throw new InvalidArgumentException('Skriv e-post eller gata och nummer.');
    }
    $postal = jeab_kontakt_postal((string) ($input['postal_code'] ?? ''));
    $postedPostal = trim((string) ($input['postal_code'] ?? ''));
    if ($postedPostal !== '' && $postal === '') {
        throw new InvalidArgumentException('Postnumret ska vara fem siffror.');
    }
    $city = jeab_kontakt_city_name(trim((string) ($input['city'] ?? '')));
    $fastighet = trim((string) ($input['fastighetsbeteckning'] ?? ''));
    $email = jeab_kontakt_email((string) ($input['email'] ?? ''));
    $prevEmail = is_array($existing) && function_exists('jeab_kontakt_row_email')
        ? jeab_kontakt_email(jeab_kontakt_row_email($existing))
        : '';
    $emailSame = is_array($existing) && $email === $prevEmail;
    $testOk = is_array($existing) && !empty($existing['is_test']) && jeab_kontakt_known_test_address($email);
    if (jeab_kontakt_address_blocked($email) && !$emailSame && !$testOk) {
        throw new InvalidArgumentException('E-postadressen tillhör inte en kund.');
    }
    $phone = jeab_kontakt_phone((string) ($input['phone'] ?? ''));
    $contactSlot = (string) ($input['contact_person'] ?? '1');
    if (!in_array($contactSlot, ['1', '2'], true)) {
        $contactSlot = '1';
    }
    $prevOwners = is_array($existing['owners'] ?? null) ? array_values($existing['owners']) : [];
    $now = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    $inputStamp = trim((string) ($input['owner_stamp'] ?? ''));
    if ($inputStamp !== 'lantmateriet') {
        $inputStamp = 'kontakter';
    }
    $autosave = ($input['kt_autosave'] ?? null) === true
        || ($input['kt_autosave'] ?? null) === 1
        || (string) ($input['kt_autosave'] ?? '') === '1'
        || (string) ($input['kt_autosave'] ?? '') === 'true';
    $owners = [];
    foreach (['1', '2'] as $slot) {
        $name = trim((string) ($input['owner_name_' . $slot] ?? ''));
        $postedPnr = trim((string) ($input['owner_pnr_' . $slot] ?? ''));
        $prev = is_array($prevOwners[(int) $slot - 1] ?? null) ? $prevOwners[(int) $slot - 1] : [];
        $prevName = trim((string) ($prev['name'] ?? ''));
        $prevPnr = trim((string) ($prev['personnummer'] ?? ''));
        $isContact = $contactSlot === $slot;
        // Formuläret har ett mejl och en telefon, bara på kontaktpersonen.
        // Den andra ägaren har inga sådana fält, så de sparade värdena ska ligga kvar.
        $keptEmail = $isContact ? $email : trim((string) ($prev['email'] ?? ''));
        $keptPhone = $isContact ? $phone : trim((string) ($prev['phone'] ?? ''));
        // Autospar tar bort personnummer ur POST. Ett tomt namn får inte slänga ägaren.
        if ($autosave && $name === '' && ($prevName !== '' || $prevPnr !== '')) {
            $name = $prevName;
            $pnr = $prevPnr;
        } else {
            if ($name === '' && $postedPnr === '') {
                continue;
            }
            if ($name === '') {
                throw new InvalidArgumentException('Skriv namn på ägaren.');
            }
            if ($autosave) {
                $pnr = $prevPnr;
            } elseif ($postedPnr === '') {
                $pnr = '';
            } elseif (jeab_kontakt_pnr_is_masked($postedPnr)) {
                $pnr = $prevPnr;
            } elseif (!jeab_kontakt_pnr_valid($postedPnr)) {
                throw new InvalidArgumentException('Personnumret är ogiltigt.');
            } elseif (function_exists('jeab_contacts_sole') && jeab_contacts_sole() && function_exists('jeab_contacts_sole_pnr_store_value')) {
                $pnr = jeab_contacts_sole_pnr_store_value($postedPnr);
            } else {
                $pnr = jeab_pnr_normalize($postedPnr);
            }
        }
        $ownerRow = [
            'name' => $name,
            'personnummer' => $pnr,
            'contact' => $isContact,
            'email' => $keptEmail,
            'phone' => $keptPhone,
        ];
        $savedAt = trim((string) ($prev['saved_at'] ?? ''));
        if ($inputStamp !== 'lantmateriet') {
            $savedAt = $now;
        }
        if ($savedAt !== '') {
            $ownerRow['saved_at'] = $savedAt;
        }
        $owners[] = $ownerRow;
    }
    // Autospar skickar bara två platser. Ägare utanför dem ska inte försvinna.
    if ($autosave) {
        $extraCount = count($prevOwners);
        for ($extra = 2; $extra < $extraCount; $extra++) {
            if (is_array($prevOwners[$extra])) {
                $owners[] = $prevOwners[$extra];
            }
        }
    }
    if ($owners === [] && $email !== '') {
        // Bara e-post: en kontaktperson utan namn.
        $owners[] = ['name' => '', 'personnummer' => '', 'contact' => true, 'email' => $email, 'phone' => $phone, 'saved_at' => $now];
    }
    if ($owners === []) {
        throw new InvalidArgumentException('Skriv minst en ägare.');
    }
    if (!$autosave && count($owners) > 2) {
        throw new InvalidArgumentException('En adress kan ha högst två ägare.');
    }
    $hasContact = false;
    foreach ($owners as $owner) {
        if (!empty($owner['contact'])) {
            $hasContact = true;
        }
    }
    if (!$hasContact) {
        $owners[0]['contact'] = true;
        $owners[0]['email'] = $email;
        $owners[0]['phone'] = $phone;
    }
    $kind = 'person';
    foreach ($owners as $owner) {
        if (jeab_kontakt_company_name((string) ($owner['name'] ?? ''))) {
            $kind = 'company';
            break;
        }
    }
    $ovrigt = array_key_exists('ovrigt', $input)
        ? trim((string) $input['ovrigt'])
        : jeab_kontakt_ovrigt(is_array($existing) ? $existing : []);
    if (function_exists('mb_substr')) {
        $ovrigt = mb_substr($ovrigt, 0, 4000, 'UTF-8');
    } else {
        $ovrigt = substr($ovrigt, 0, 4000);
    }
    $office = jeab_normalize_company(is_array($existing) ? ($existing['company'] ?? null) : null);
    if ($office === null) {
        $office = jeab_normalize_company($input['company'] ?? null);
        if ($office === null && function_exists('jeab_selected_company')) {
            $office = jeab_selected_company();
        }
    }
    $row = [
        'id' => $existing ? (string) ($existing['id'] ?? $id) : jeab_kontakt_new_id(),
        'street' => $street,
        'street_key' => jeab_kontakt_street_key($street),
        'postal_code' => $postal,
        'city' => $city,
        'fastighetsbeteckning' => $fastighet,
        'kind' => $kind,
        'owners' => $owners,
        'created_at' => (string) ($existing['created_at'] ?? $now),
        'updated_at' => $now,
        'owner_stamp' => $inputStamp,
    ];
    if (is_array($existing)) {
        foreach ($existing as $key => $value) {
            if (!array_key_exists((string) $key, $row)) {
                $row[(string) $key] = $value;
            }
        }
    }
    if ($office !== null) {
        $row['company'] = $office;
    }
    if ($ovrigt !== '') {
        $row['ovrigt'] = $ovrigt;
    } else {
        unset($row['ovrigt']);
    }
    $secondaryContacts = jeab_kontakter_load();
    $pair = jeab_kontakt_secondary_from_input(
        $input,
        is_array($existing) ? $existing : null,
        $email,
        $owners,
        $secondaryContacts,
        (string) $row['id']
    );
    if ($pair[0] !== '' || $pair[1] !== '' || array_key_exists('email2', $input) || array_key_exists('email3', $input)) {
        $row['email2'] = $pair[0];
        $row['email3'] = $pair[1];
    }
    if (array_key_exists('phone2', $input)) {
        $row['phone2'] = jeab_kontakt_phone((string) $input['phone2']);
    } elseif (isset($row['phone2'])) {
        $row['phone2'] = jeab_kontakt_phone((string) $row['phone2']);
    }
    $applied = [];
    $preview = jeab_kontakter_load();
    $preview[(string) $row['id']] = $row;
    $willMerge = jeab_kontakt_save_reuse_match($preview, $preview[(string) $row['id']]) !== null;
    $saved = jeab_kontakter_mutate(function (array &$contacts) use ($row, &$applied): array {
        $id = (string) $row['id'];
        $contacts[$id] = $row;
        $match = jeab_kontakt_save_reuse_match($contacts, $contacts[$id]);
        if ($match !== null && isset($contacts[$match['id']]) && is_array($contacts[$match['id']]) && function_exists('jeab_kontakt_merge_rows')) {
            $from = (string) $match['id'];
            $contacts[$id] = jeab_kontakt_merge_rows($contacts[$id], $contacts[$from], (string) $match['rule']);
            $contacts[$id]['id'] = $id;
            unset($contacts[$from]);
            $applied = [[
                'from' => $from,
                'to' => $id,
                'rule' => (string) $match['rule'],
                'reason' => 'återanvänd',
            ]];
        }

        return $contacts[$id];
    }, $willMerge);
    if ($applied !== [] && function_exists('jeab_kontakt_repoint') && function_exists('jeab_kontakt_dedupe_log')) {
        $merge = $applied[0];
        $from = (string) ($merge['from'] ?? '');
        $to = (string) ($merge['to'] ?? '');
        $store = function_exists('jeab_store') ? jeab_store() : null;
        $move = static function () use ($store, $from, $to): void {
            jeab_kontakt_repoint($store instanceof JeabStore ? $store : null, $from, $to);
        };
        if (function_exists('jeab_kontakt_quiet_call')) {
            jeab_kontakt_quiet_call($move);
        } else {
            $move();
        }
        jeab_kontakt_dedupe_log($from, $to, (string) ($merge['rule'] ?? ''), 'återanvänd', 'save_input');
    }
    return is_array($saved) ? $saved : $row;
}

function jeab_kontakt_delete(string $id): void
{
    $id = trim($id);
    if ($id === '' || jeab_kontakt_get($id) === null) {
        throw new InvalidArgumentException('Kontakten finns inte.');
    }
    // Spärren mot massradering (över 5 %, över 20 rader, eller tom lista)
    // gäller en ofullständig ögonblicksbild. Här tas ett enda känt id bort.
    jeab_kontakter_mutate(function (array &$contacts) use ($id): void {
        unset($contacts[$id]);
    }, true);
}

/** @param array<string, mixed> $row */
function jeab_kontakt_public(array $row): array
{
    $copy = $row;
    $owners = [];
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $owner['personnummer'] = jeab_kontakt_pnr_mask((string) ($owner['personnummer'] ?? ''));
        $owners[] = $owner;
    }
    $copy['owners'] = $owners;
    return $copy;
}

require_once __DIR__ . '/kontakt_store.php';
require_once __DIR__ . '/kontakter_sql_migrate.php';
require_once __DIR__ . '/kontakter_dedupe.php';
require_once __DIR__ . '/kontakter_conflicts.php';
require_once __DIR__ . '/kontakter_export.php';
