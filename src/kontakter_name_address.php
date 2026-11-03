<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Namn och adress från kontakten, med ifyllnad från inkorg och Wix.
 * Hook kontakter.name_address_backfill. dry=1 är standard.
 * limit (50 om den saknas, högst 200) och after_id eller offset tar en sida.
 * contact_ids (kommaseparerad) begränsar dry=0 till de kontakterna.
 * Svaret har next_after_id. dry=0 kan skicka med samma batch_id till nästa sida.
 * kontakter.name_address_explain läser källor och varför ett fält inte fylldes.
 * kontakter.find söker på namn. En körning skriver en sammanfattning i felloggen.
 * undo=1 och batch_id återställer bara namn, gata, postnummer och ort.
 * Inget skickas. Ordrar, bokningar och påminnelser lämnas länkade.
 */

/**
 * Bolag som får behålla ort och postnummer.
 * Malmö på ett Järfälla-lead ska inte nollas som «utanför området».
 */
function jeab_name_address_company_for_place(string $company, string $postal, string $city): string
{
    $company = function_exists('jeab_address_company') ? (string) jeab_address_company($company) : trim($company);
    $postal = trim($postal);
    $city = trim($city);
    if (!function_exists('jeab_address_postal_kind') || !function_exists('jeab_address_city_kind')) {
        return $company;
    }
    $matches = static function (string $candidate) use ($postal, $city): bool {
        if ($candidate === '') {
            return false;
        }
        if ($postal !== '' && jeab_address_postal_kind($candidate, $postal) === 'inside') {
            return true;
        }

        return $postal === '' && $city !== '' && jeab_address_city_kind($candidate, $city) === 'inside';
    };
    if ($matches($company)) {
        return $company;
    }
    foreach (['malmo', 'jarfalla'] as $candidate) {
        if ($matches($candidate)) {
            return $candidate;
        }
    }

    return $company;
}

/**
 * «Kund – Elbilsladdare» är en produkttitel, inte en gata.
 */
function jeab_name_address_product_title(string $value): bool
{
    $value = trim($value);

    return $value !== '' && preg_match('/^\s*kund\s*[–—\-]\s*\S/iu', $value) === 1;
}

/**
 * Ett ensamt förnamn: en versal och sedan bara gemener.
 * Formen räcker när inget fullständigt namn finns, även om den liknar e-postens lokaldel.
 */
function jeab_name_address_given_name(string $name): bool
{
    $name = trim($name);
    if (preg_match('/^\p{Lu}\p{Ll}{1,11}$/u', $name) !== 1) {
        return false;
    }
    if (preg_match('/[aeiouyåäöé]/iu', $name) !== 1) {
        return false;
    }
    $fold = function_exists('jeab_kontakt_fold_text')
        ? jeab_kontakt_fold_text($name)
        : (function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name));
    if (in_array($fold, ['test', 'testing', 'tester', 'demo', 'fejk', 'fake', 'kund', 'namn', 'name'], true)) {
        return false;
    }
    if (function_exists('jeab_contacts_sole_name_is_placeholder') && jeab_contacts_sole_name_is_placeholder($name)) {
        return false;
    }
    if (jeab_name_address_locality_canon($name) !== '') {
        return false;
    }

    return true;
}

/**
 * @param list<string> $emails
 */
function jeab_name_address_name_real(string $name, array $emails = []): bool
{
    $name = trim($name);
    if ($name === '') {
        return false;
    }
    if (jeab_name_address_given_name($name)) {
        if (function_exists('jeab_kontakt_platform_name') && jeab_kontakt_platform_name($name)) {
            return false;
        }

        return true;
    }
    if (function_exists('jeab_contacts_sole_name_is_real') && !jeab_contacts_sole_name_is_real($name, $emails)) {
        return false;
    }
    if (function_exists('jeab_kontakt_platform_name') && jeab_kontakt_platform_name($name)) {
        return false;
    }
    if (function_exists('jeab_contacts_sole_name_is_real')) {
        return true;
    }
    if (str_contains($name, '@')) {
        return false;
    }
    $fold = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);

    return !in_array($fold, ['kund', 'kundnamn', 'namn', 'name', 'customer', 'okänd', 'okand', 'unknown'], true);
}

/**
 * «och ort:» och liknande är en avbruten etikett, inte en gata eller ort.
 */
function jeab_name_address_label_fragment(string $value): bool
{
    $value = trim($value);
    if ($value === '') {
        return false;
    }
    $fold = function_exists('jeab_kontakt_fold_text')
        ? jeab_kontakt_fold_text($value)
        : (function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value));
    $fold = trim($fold, " \t.,;:!-–—");
    $fold = preg_replace('/\s+/u', ' ', $fold) ?? $fold;

    return in_array($fold, [
        'och ort', 'och adress', 'och address', 'och gata',
        'adress och ort', 'ort och adress', 'adress/ort', 'ort/adress',
        'adress', 'address', 'gata', 'gatuadress', 'postadress', 'street',
        'ort', 'postort', 'stad', 'city', 'kommun',
    ], true);
}

/** Tar bort avslutande skiljetecken och parenteser som «(Se 4)». */
function jeab_name_address_strip_city_junk(string $city): string
{
    $city = trim(preg_replace('/\s+/u', ' ', $city) ?? $city);
    $prev = null;
    while ($city !== '' && $city !== $prev) {
        $prev = $city;
        $city = preg_replace('/\s*\([^)]{0,40}\)\s*$/u', '', $city) ?? $city;
        $city = preg_replace('/\s*\[[^\]]{0,40}\]\s*$/u', '', $city) ?? $city;
        $city = trim($city, " \t.,;:!-–—");
    }

    return $city;
}

/**
 * Kända svenska orter: kommuner, Malmö- och Järfälla-orter, Hjärup.
 *
 * @return array<string, string>
 */
function jeab_name_address_locality_map(): array
{
    static $map = null;
    if (is_array($map)) {
        return $map;
    }
    $map = function_exists('jeab_kontakt_places') ? jeab_kontakt_places() : [];
    if (function_exists('jeab_address_region_cities')) {
        foreach (['malmo', 'jarfalla'] as $company) {
            foreach (jeab_address_region_cities($company) as $fold => $name) {
                if ($fold !== '' && $name !== '') {
                    $map[$fold] = $name;
                }
            }
        }
    }
    $map['hjarup'] = 'Hjärup';

    return $map;
}

function jeab_name_address_locality_canon(string $text): string
{
    $text = jeab_name_address_strip_city_junk($text);
    if ($text === '' || jeab_name_address_label_fragment($text)) {
        return '';
    }
    $fold = function_exists('jeab_kontakt_fold_text')
        ? jeab_kontakt_fold_text($text)
        : (function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text));

    return jeab_name_address_locality_map()[$fold] ?? '';
}

function jeab_name_address_country_name(string $fold): bool
{
    static $names = [
        'the netherlands' => true, 'netherlands' => true, 'nederland' => true, 'nederlanderna' => true, 'holland' => true,
        'sverige' => true, 'sweden' => true, 'norge' => true, 'norway' => true, 'danmark' => true, 'denmark' => true,
        'finland' => true, 'tyskland' => true, 'germany' => true, 'belgien' => true, 'belgium' => true,
        'frankrike' => true, 'france' => true, 'spanien' => true, 'spain' => true, 'italien' => true, 'italy' => true,
        'polen' => true, 'poland' => true, 'england' => true, 'storbritannien' => true, 'united kingdom' => true,
        'uk' => true, 'usa' => true, 'united states' => true, 'skane' => true,
    ];

    return isset($names[$fold]);
}

/** Tangentbordsskräp: ord på minst tre bokstäver utan svensk vokal. */
function jeab_name_address_keyboard_junk(string $text): bool
{
    $fold = function_exists('jeab_kontakt_fold_text')
        ? jeab_kontakt_fold_text($text)
        : (function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text));
    if ($fold === '') {
        return false;
    }
    preg_match_all('/\p{L}{3,}/u', $fold, $found);
    $tokens = $found[0] ?? [];
    if ($tokens === []) {
        $letters = preg_replace('/[^\p{L}]+/u', '', $fold) ?? '';

        return strlen($letters) >= 4 && preg_match('/[aeiouy]/u', $letters) !== 1;
    }
    foreach ($tokens as $token) {
        if (preg_match('/[aeiouy]/u', (string) $token) === 1) {
            return false;
        }
    }

    return true;
}

/** Postnummer som pekar säkert på en ort. Breda bolagsintervall används inte. */
function jeab_name_address_postal_city(string $postal): string
{
    $digits = preg_replace('/\D+/', '', $postal) ?? '';
    if (strlen($digits) !== 5) {
        return '';
    }
    $n = (int) $digits;
    $ranges = [
        [20010, 21899, 'Malmö'],
        [22000, 22799, 'Lund'],
        [25000, 25799, 'Helsingborg'],
        [17600, 17799, 'Järfälla'],
        [10000, 11899, 'Stockholm'],
    ];
    foreach ($ranges as [$from, $to, $city]) {
        if ($n >= $from && $n <= $to) {
            return $city;
        }
    }

    return '';
}

function jeab_name_address_same_fold(string $a, string $b): bool
{
    if ($a === '' || $b === '') {
        return false;
    }
    if (!function_exists('jeab_kontakt_fold_text')) {
        return strcasecmp($a, $b) === 0;
    }

    return jeab_kontakt_fold_text($a) === jeab_kontakt_fold_text($b);
}

/**
 * Ort som får skrivas. Länder, siffror, Test och tangentbordsskräp blir tomma.
 * En utskriven postort behålls, även när den saknas i kartan och postnumret
 * pekar på en annan ort. Intervallet används bara när orten är tom eller avvisad.
 */
function jeab_name_address_accept_city(string $city, string $postal = ''): string
{
    $stripped = jeab_name_address_strip_city_junk($city);
    if ($stripped === '' || jeab_name_address_label_fragment($stripped)) {
        $stripped = '';
    }
    $fold = $stripped === ''
        ? ''
        : (function_exists('jeab_kontakt_fold_text')
            ? jeab_kontakt_fold_text($stripped)
            : (function_exists('mb_strtolower') ? mb_strtolower($stripped, 'UTF-8') : strtolower($stripped)));
    $digits = preg_replace('/[\s,;.]+/u', '', $stripped) ?? '';
    $rejected = $stripped === ''
        || preg_match('/^\d+$/u', $digits) === 1
        || jeab_name_address_country_name($fold)
        || in_array($fold, ['test', 'testing', 'tester'], true)
        || jeab_name_address_keyboard_junk($stripped);
    $canon = $rejected ? '' : jeab_name_address_locality_canon($stripped);
    if ($canon !== '') {
        return $canon;
    }
    if (!$rejected) {
        return function_exists('jeab_kontakt_city_name') ? jeab_kontakt_city_name($stripped) : $stripped;
    }

    return jeab_name_address_postal_city($postal);
}

function jeab_name_address_clean_city(string $city): string
{
    return jeab_name_address_accept_city($city, '');
}

/**
 * empty, placeholder, real eller unknown.
 * unknown är text som inte är skräp och inte en känd ort. Den skrivs inte över.
 */
function jeab_name_address_city_kind(string $city): string
{
    $city = trim($city);
    if ($city === '') {
        return 'empty';
    }
    if (jeab_name_address_label_fragment($city)) {
        return 'placeholder';
    }
    $stripped = jeab_name_address_strip_city_junk($city);
    if ($stripped === '' || jeab_name_address_label_fragment($stripped)) {
        return 'placeholder';
    }
    $fold = function_exists('jeab_kontakt_fold_text')
        ? jeab_kontakt_fold_text($stripped)
        : (function_exists('mb_strtolower') ? mb_strtolower($stripped, 'UTF-8') : strtolower($stripped));
    $digits = preg_replace('/\s+/u', '', $stripped) ?? '';
    if (preg_match('/^\d+$/u', $digits) === 1 || jeab_name_address_country_name($fold) || in_array($fold, ['test', 'testing', 'tester'], true) || jeab_name_address_keyboard_junk($stripped)) {
        return 'placeholder';
    }
    $canon = jeab_name_address_locality_canon($stripped);
    if ($canon !== '') {
        return $stripped === trim($city) ? 'real' : 'placeholder';
    }

    return 'unknown';
}

/**
 * Kommun, postort i kartan, eller Hjärup. Tom sträng när texten inte är en ort.
 */
function jeab_name_address_town_canon(string $text): string
{
    $text = jeab_name_address_strip_city_junk($text);
    if ($text === '' || jeab_name_address_label_fragment($text)) {
        return '';
    }
    $known = jeab_name_address_locality_canon($text);
    if ($known !== '') {
        return $known;
    }
    $fold = function_exists('jeab_kontakt_fold_text')
        ? jeab_kontakt_fold_text($text)
        : (function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text));
    if (function_exists('jeab_rot_postal_town_map') && isset(jeab_rot_postal_town_map()[$fold])) {
        return function_exists('jeab_kontakt_city_name') ? jeab_kontakt_city_name($text) : $text;
    }
    if (function_exists('jeab_address_region_cities')) {
        foreach (['malmo', 'jarfalla'] as $company) {
            $regions = jeab_address_region_cities($company);
            if (isset($regions[$fold])) {
                return (string) $regions[$fold];
            }
        }
    }

    return '';
}

/** Tar bort parenteser och våningsnoteringar som «4tr». Lägenhetsnoteringar får stå kvar. */
function jeab_name_address_prepare_street(string $street): string
{
    $street = trim(preg_replace('/\s+/u', ' ', $street) ?? $street);
    $prev = null;
    while ($street !== '' && $street !== $prev) {
        $prev = $street;
        $street = preg_replace('/\s*\([^)]*\)/u', ' ', $street) ?? $street;
        $street = preg_replace('/\s*\[[^\]]*\]/u', ' ', $street) ?? $street;
        $street = preg_replace('/\b\d{1,2}\s*tr(?:appa)?\b/iu', ' ', $street) ?? $street;
        $street = preg_replace('/\btr(?:appa)?\s*\d{1,2}\b/iu', ' ', $street) ?? $street;
        $street = trim(preg_replace('/\s+/u', ' ', $street) ?? $street, " \t.,;:-–—");
        $street = preg_replace('/^och\b[\s,;:]*/iu', '', $street) ?? $street;
    }

    return $street;
}

function jeab_name_address_street_suffix(string $word): bool
{
    return preg_match('/(?:gatan|gata|vägen|väg|gränden|gränd|allén|allé|allen|leden|led|stigen|stig|backen|backe|torget|torg|platsen|plats|plan|kajen|kaj|stranden|strand|esplanaden|esplanad|boulevarden|boulevard|bron|bro|holmen|holme|gången)$/iu', $word) === 1;
}

/** Ort, land eller region utan gatändelse är inget gatunamn. */
function jeab_name_address_street_head_blocked(string $street): bool
{
    if (preg_match('/^(.+?)\s+\d{1,4}(?!\d)/u', $street, $m) !== 1) {
        return false;
    }
    $words = preg_split('/\s+/u', trim((string) $m[1])) ?: [];
    if ($words === []) {
        return false;
    }
    foreach ($words as $word) {
        if (jeab_name_address_street_suffix((string) $word)) {
            return false;
        }
    }
    $head = trim((string) $m[1]);
    $fold = function_exists('jeab_kontakt_fold_text')
        ? jeab_kontakt_fold_text($head)
        : (function_exists('mb_strtolower') ? mb_strtolower($head, 'UTF-8') : strtolower($head));

    return jeab_name_address_country_name($fold) || jeab_name_address_locality_canon($head) !== '';
}

/** Gatan ska börja med ett ord och ha ett husnummer. Ett postnummer ensamt räcker inte. */
function jeab_name_address_has_house_number(string $street): bool
{
    $street = jeab_name_address_prepare_street($street);
    if ($street === '' || jeab_name_address_label_fragment($street) || jeab_name_address_product_title($street)) {
        return false;
    }
    if (preg_match('/^\p{L}[\p{L}\.\'’\-]*(?:\s+\p{L}[\p{L}\.\'’\-]*)*\s+\d{1,4}(?!\d)/u', $street) !== 1) {
        return false;
    }

    return !jeab_name_address_street_head_blocked($street);
}

/**
 * empty, product_title, placeholder, real eller unknown.
 * unknown och real är stängda: de töms inte och skrivs inte över.
 * En text utan någon siffra är en platshållare och får bytas mot en numrerad gata.
 */
function jeab_name_address_street_kind(string $street): string
{
    $street = trim($street);
    if ($street === '') {
        return 'empty';
    }
    if (jeab_name_address_product_title($street)) {
        return 'product_title';
    }
    if (jeab_name_address_label_fragment($street) || str_contains($street, '@')) {
        return 'placeholder';
    }
    $prepared = jeab_name_address_prepare_street($street);
    if ($prepared !== '' && jeab_name_address_has_house_number($prepared)) {
        return 'real';
    }
    if (preg_match('/\d/u', $street) !== 1) {
        return 'placeholder';
    }

    return 'unknown';
}

function jeab_name_address_gap_open(string $gap): bool
{
    return in_array($gap, ['empty', 'placeholder', 'product_title', 'email'], true);
}

/**
 * @param array<string, mixed> $input
 * @return list<string>
 */
function jeab_name_address_asked_ids(array $input): array
{
    $ids = [];
    $raw = $input['contact_ids'] ?? '';
    $parts = is_array($raw) ? $raw : preg_split('/\s*,\s*/', (string) $raw);
    foreach (is_array($parts) ? $parts : [] as $part) {
        $id = trim((string) $part);
        if ($id !== '') {
            $ids[$id] = true;
        }
    }
    $one = trim((string) ($input['contact_id'] ?? ''));
    if ($one !== '') {
        $ids[$one] = true;
    }

    return array_keys($ids);
}

/** Test, demo, fejk, tangentbordsskräp och «hämta bil» hoppas över om de inte listas. */
function jeab_name_address_test_name(string $name): bool
{
    $fold = function_exists('jeab_kontakt_fold_text')
        ? jeab_kontakt_fold_text($name)
        : (function_exists('mb_strtolower') ? mb_strtolower(trim($name), 'UTF-8') : strtolower(trim($name)));
    $fold = preg_replace('/\s+/u', ' ', trim($fold)) ?? trim($fold);
    if ($fold === '') {
        return false;
    }
    if (preg_match('/(?:^|\s)(?:test|demo|fejk|fake)(?:\s|$)/u', $fold) === 1) {
        return true;
    }
    if (str_contains($fold, 'hamta bil')) {
        return true;
    }

    return jeab_name_address_keyboard_junk($name);
}

/**
 * Känd ort efter husnumret flyttas till ort när orten är tom.
 *
 * @return array{street:string,city:string}
 */
function jeab_name_address_split_town(string $street): array
{
    $street = trim(preg_replace('/\s+/u', ' ', $street) ?? $street);
    $keep = ['street' => $street, 'city' => ''];
    if (preg_match('/^(.*?\p{L}[\p{L}\.\'’\-]*\s+\d{1,4}\s*[a-zA-Z]?)(?:\s+)(.+)$/u', $street, $m) !== 1) {
        return $keep;
    }
    $head = trim((string) $m[1]);
    $tail = trim((string) $m[2]);
    if ($head === '' || $tail === '' || preg_match('/^(?:lgh|lägenhet|lagenhet|trappa|tr|vån|van|nb|bv)\b/iu', $tail) === 1) {
        return $keep;
    }
    $words = preg_split('/\s+/u', $tail) ?: [];
    $max = min(3, count($words));
    for ($n = $max; $n >= 1; $n--) {
        $chunk = implode(' ', array_slice($words, -$n));
        $canon = jeab_name_address_town_canon($chunk);
        if ($canon === '') {
            continue;
        }
        $rest = trim(implode(' ', array_slice($words, 0, count($words) - $n)));
        if ($rest !== '') {
            continue;
        }

        return ['street' => $head, 'city' => $canon];
    }

    return $keep;
}

/**
 * Gata, postnummer och ort som inkorg, Wix och backfill får spara.
 * Gata utan husnummer hoppas över. Etikettrester är tomma.
 *
 * @return array{street:string,postal:string,city:string}
 */
function jeab_name_address_normalize_place(string $street, string $postal, string $city): array
{
    if (jeab_name_address_label_fragment($street) || jeab_name_address_product_title($street)) {
        $street = '';
    }
    $city = jeab_name_address_clean_city($city);
    $postal = preg_replace('/\s+/', '', trim($postal)) ?? '';
    if ($postal !== '' && function_exists('jeab_kontakt_postal')) {
        $postal = jeab_kontakt_postal($postal);
    }
    if ($street !== '' && function_exists('jeab_address_parts')) {
        $parts = jeab_address_parts($street);
        $parsedStreet = trim((string) ($parts['street'] ?? ''));
        if (jeab_name_address_label_fragment($parsedStreet) || jeab_name_address_product_title($parsedStreet)) {
            $parsedStreet = '';
        }
        if ($postal === '' && trim((string) ($parts['postal'] ?? '')) !== '') {
            $postal = preg_replace('/\s+/', '', (string) $parts['postal']) ?? '';
            if (function_exists('jeab_kontakt_postal')) {
                $postal = jeab_kontakt_postal($postal);
            }
        }
        if ($city === '') {
            $city = jeab_name_address_clean_city((string) ($parts['city'] ?? ''));
        }
        $street = $parsedStreet;
    }
    if ($street !== '') {
        $street = jeab_name_address_prepare_street($street);
        $split = jeab_name_address_split_town($street);
        if ($split['city'] !== '') {
            $same = $city !== '' && jeab_name_address_same_fold($city, $split['city']);
            if ($city === '' || $same) {
                $street = $split['street'];
                if ($city === '') {
                    $city = $split['city'];
                }
            }
        }
    }
    $street = jeab_name_address_prepare_street($street);
    if (!jeab_name_address_has_house_number($street)) {
        $street = '';
    }
    $city = jeab_name_address_accept_city($city, $postal);

    return ['street' => $street, 'postal' => $postal, 'city' => $city];
}

function jeab_name_address_street_real(string $value): bool
{
    $value = trim($value);
    if ($value === '' || jeab_name_address_product_title($value) || jeab_name_address_label_fragment($value) || str_contains($value, '@')) {
        return false;
    }
    if (function_exists('jeab_contacts_sole_name_is_placeholder') && jeab_contacts_sole_name_is_placeholder($value)) {
        return false;
    }

    return jeab_name_address_has_house_number($value);
}

/**
 * Kontaktens riktiga namn vinner. Annars det första riktiga reservnamnet.
 * Saknas ett riktigt namn behålls e-posten, så ett kort utan namn fortfarande visar adressen.
 *
 * @param list<string> $fallbacks
 * @param list<string> $emails
 */
function jeab_name_address_pick_name(string $contactName, array $fallbacks, array $emails = []): string
{
    $contactName = trim($contactName);
    if (jeab_name_address_name_real($contactName, $emails)) {
        return $contactName;
    }
    $full = '';
    $single = '';
    foreach ($fallbacks as $name) {
        $name = trim((string) $name);
        if ($name === '' || !jeab_name_address_name_real($name, $emails)) {
            continue;
        }
        if ($full === '' && preg_match('/\s/u', $name) === 1) {
            $full = $name;
        }
        if ($single === '') {
            $single = $name;
        }
    }
    if ($full !== '') {
        return $full;
    }
    if ($single !== '') {
        return $single;
    }
    if ($contactName !== '') {
        return $contactName;
    }
    foreach ($fallbacks as $name) {
        $name = trim((string) $name);
        if ($name !== '' && str_contains($name, '@')) {
            return $name;
        }
    }

    return '';
}

/**
 * @param array<string, mixed> $view
 * @param array<string, mixed> $raw
 * @return array<string, mixed>
 */
function jeab_name_address_finish_shown(array $view, array $raw): array
{
    $strictLinked = function_exists('jeab_contacts_strict') && jeab_contacts_strict()
        && function_exists('jeab_contacts_sole') && jeab_contacts_sole()
        && !empty($view['linked']);
    $emails = [
        (string) ($view['email'] ?? ''),
        (string) ($raw['email'] ?? ''),
    ];
    $fallbacks = [
        (string) ($raw['customer_name'] ?? ''),
        (string) ($raw['name'] ?? ''),
        (string) ($raw['huvudsokande'] ?? ''),
    ];
    $title = trim((string) ($raw['title'] ?? ''));
    if (preg_match('/^(.+?)\s*[–—\-]\s+\S/u', $title, $titleName) === 1) {
        $fallbacks[] = trim((string) $titleName[1]);
    }
    if (!$strictLinked) {
        $view['name'] = jeab_name_address_pick_name((string) ($view['name'] ?? ''), $fallbacks, $emails);
    }

    $street = trim((string) ($view['street'] ?? ''));
    $display = trim((string) ($view['display'] ?? ''));
    $streetBad = !jeab_name_address_street_real($street);
    $displayBad = $display === '' || !jeab_name_address_street_real($display) || jeab_name_address_product_title($display);
    if ($strictLinked) {
        if (jeab_name_address_product_title((string) ($view['street'] ?? ''))) {
            $view['street'] = '';
        }
        if (jeab_name_address_product_title((string) ($view['display'] ?? ''))) {
            $view['display'] = '';
        }

        return $view;
    }
    if (!$streetBad && !$displayBad) {
        return $view;
    }
    $rawAddress = trim((string) ($raw['address'] ?? ''));
    $parts = function_exists('jeab_address_parts')
        ? jeab_address_parts($rawAddress)
        : ['street' => $rawAddress, 'postal' => '', 'city' => '', 'display' => $rawAddress];
    $rawStreet = trim((string) ($parts['street'] ?? ''));
    if (!jeab_name_address_street_real($rawStreet)) {
        $rawStreet = '';
    }
    if ($streetBad) {
        $view['street'] = $rawStreet;
    }
    if (trim((string) ($view['postal'] ?? '')) === '' && trim((string) ($parts['postal'] ?? '')) !== '') {
        $view['postal'] = (string) $parts['postal'];
    }
    if (trim((string) ($view['city'] ?? '')) === '' && trim((string) ($parts['city'] ?? '')) !== '') {
        $view['city'] = (string) $parts['city'];
    }
    if ($displayBad || $streetBad) {
        $streetNow = trim((string) ($view['street'] ?? ''));
        $cityNow = trim((string) ($view['city'] ?? ''));
        $postalNow = trim((string) ($view['postal'] ?? ''));
        $tail = trim($postalNow . ($cityNow !== '' ? ' ' . $cityNow : ''));
        if (jeab_name_address_street_real($streetNow) && $tail !== '') {
            $view['display'] = $streetNow . ', ' . $tail;
        } elseif (jeab_name_address_street_real($streetNow)) {
            $view['display'] = $streetNow;
        } elseif ($tail !== '' && !jeab_name_address_product_title($tail)) {
            $view['display'] = $tail;
        } else {
            $view['display'] = '';
        }
    }
    if (jeab_name_address_product_title((string) ($view['street'] ?? ''))) {
        $view['street'] = '';
    }
    if (jeab_name_address_product_title((string) ($view['display'] ?? ''))) {
        $view['display'] = '';
    }
    $streetNow = trim((string) ($view['street'] ?? ''));
    $cityNow = trim((string) ($view['city'] ?? ''));
    if ($streetNow !== '' && !jeab_name_address_has_house_number($streetNow)) {
        $town = jeab_name_address_town_canon($streetNow);
        $sameTown = $town !== '' && ($cityNow === '' || (function_exists('jeab_kontakt_fold_text') && jeab_kontakt_fold_text($cityNow) === jeab_kontakt_fold_text($town)));
        if ($sameTown) {
            if ($cityNow === '') {
                $view['city'] = $town;
                $cityNow = $town;
            }
            $view['street'] = '';
            $postalNow = trim((string) ($view['postal'] ?? ''));
            $view['display'] = trim($postalNow . ($cityNow !== '' ? ' ' . $cityNow : ''));
        }
    }

    return $view;
}

/**
 * @return array{street:string,input:string,shut:string}
 */
function jeab_name_address_card_lines(string $street, string $display, string $title): array
{
    if (jeab_name_address_product_title($street)) {
        $street = '';
    }
    $input = $display;
    if (jeab_name_address_product_title($input)) {
        $input = '';
    }
    if ($input === '' && !jeab_name_address_product_title($title)) {
        $input = $title;
    }
    if (jeab_name_address_product_title($input)) {
        $input = '';
    }
    $shut = $street !== '' ? $street : $input;
    if ($shut === '' && !jeab_name_address_product_title($title)) {
        $shut = $title;
    }
    if (jeab_name_address_product_title($shut)) {
        $shut = '';
    }

    return ['street' => $street, 'input' => $input, 'shut' => $shut];
}

/**
 * Byt bara platshållaren efter «Kund:». Övrig text på raden får stå kvar.
 */
function jeab_name_address_note_header(string $note, string $realName): string
{
    $realName = trim($realName);
    if ($realName === '' || !jeab_name_address_name_real($realName, [])) {
        return $note;
    }
    $lines = preg_split("/\r\n|\n|\r/", $note) ?: [];
    foreach ($lines as $i => $line) {
        if (preg_match('/^(\s*Kund\s*:\s*)(.*)$/iu', (string) $line, $m) !== 1) {
            continue;
        }
        $rest = (string) $m[2];
        $namePart = trim((string) (preg_split('/\s*[·•|]\s*/u', trim($rest))[0] ?? ''));
        if ($namePart !== '' && jeab_name_address_name_real($namePart, [])) {
            continue;
        }
        if ($namePart === '') {
            $lines[$i] = $m[1] . $realName;
            continue;
        }
        $pos = strpos($rest, $namePart);
        if ($pos === false) {
            $lines[$i] = $m[1] . $realName;
            continue;
        }
        $lines[$i] = $m[1] . substr($rest, 0, $pos) . $realName . substr($rest, $pos + strlen($namePart));
    }

    return implode("\n", $lines);
}

/**
 * Sparat ordernamn, även när visningen redan tömt kopian.
 *
 * @param array<string, mixed> $row
 * @return list<string>
 */
function jeab_name_address_order_names(array $row): array
{
    $names = function_exists('jeab_contacts_sole_row_stored_names')
        ? jeab_contacts_sole_row_stored_names($row)
        : [];
    $id = (int) ($row['reminder_id'] ?? 0);
    if ($id <= 0) {
        $id = (int) ($row['id'] ?? 0);
    }
    if ($id > 0 && function_exists('jeab_purchase_get')) {
        try {
            $fresh = jeab_purchase_get($id);
        } catch (Throwable) {
            $fresh = null;
        }
        if (is_array($fresh) && function_exists('jeab_contacts_sole_row_stored_names')) {
            foreach (jeab_contacts_sole_row_stored_names($fresh) as $name) {
                $names[] = $name;
            }
        }
    }

    return $names;
}

/**
 * @param array<string, mixed> $bag
 * @return array{name:string,street:string,postal:string,city:string}
 */
function jeab_name_address_bag(array $bag): array
{
    $flat = [];
    $walk = static function (mixed $value, string $label) use (&$flat, &$walk): void {
        if (is_array($value)) {
            $lab = trim((string) ($value['label'] ?? $value['name'] ?? $value['key'] ?? ''));
            $val = $value['value'] ?? $value['val'] ?? $value['text'] ?? null;
            if ($lab !== '' && (is_string($val) || is_numeric($val))) {
                $flat[] = [$lab, trim((string) $val)];
            }
            foreach ($value as $key => $child) {
                if (is_string($key)) {
                    $walk($child, $key);
                } elseif (is_array($child)) {
                    $walk($child, $label);
                }
            }

            return;
        }
        if ($label !== '' && (is_string($value) || is_numeric($value))) {
            $flat[] = [$label, trim((string) $value)];
        }
    };
    $walk($bag, '');
    $name = '';
    $first = '';
    $last = '';
    $street = '';
    $postal = '';
    $city = '';
    foreach ($flat as [$label, $val]) {
        if ($val === '') {
            continue;
        }
        $fold = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
        if (preg_match('/förnamn|fornamn|first.?name/u', $fold) === 1) {
            $first = $first !== '' ? $first : $val;
        } elseif (preg_match('/efternamn|last.?name/u', $fold) === 1) {
            $last = $last !== '' ? $last : $val;
        } elseif ($name === '' && preg_match('/\bnamn\b|\bname\b|kontaktperson/u', $fold) === 1) {
            $name = $val;
        } elseif ($postal === '' && preg_match('/postnummer|postnr|postal|zip/u', $fold) === 1) {
            $postal = $val;
        } elseif ($city === '' && preg_match('/\bort\b|postort|\bstad\b|\bcity\b|kommun/u', $fold) === 1) {
            $city = $val;
        } elseif ($street === '' && preg_match('/adress|address|gata|street/u', $fold) === 1) {
            $street = $val;
        }
    }
    if ($name === '') {
        $name = trim($first . ' ' . $last);
    }
    if (!jeab_name_address_name_real($name, [])) {
        $name = '';
    }
    $place = jeab_name_address_normalize_place($street, $postal, $city);

    return [
        'name' => $name,
        'street' => $place['street'],
        'postal' => $place['postal'],
        'city' => $place['city'],
    ];
}

/**
 * @param array<string, mixed> $msg
 * @param array<string, mixed> $cust
 * @param array<string, mixed> $place
 * @return array{name:string,address:string,street:string,postal_code:string,city:string}
 */
function jeab_name_address_contact_hint_from_lead(array $msg, array $cust, array $place = []): array
{
    $emails = [(string) ($cust['email'] ?? ''), (string) ($msg['from'] ?? '')];
    $own = function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address((string) ($msg['from'] ?? ''));
    $name = '';
    $candidates = [(string) ($cust['name'] ?? '')];
    if (!$own) {
        $candidates[] = (string) ($msg['from_name'] ?? '');
    }
    foreach ($candidates as $cand) {
        if (jeab_name_address_name_real($cand, $emails)) {
            $name = trim($cand);
            break;
        }
    }
    $address = trim((string) ($cust['address'] ?? ''));
    if (jeab_name_address_product_title($address) || jeab_name_address_label_fragment($address)) {
        $address = '';
    }
    $postal = trim((string) ($place['postal'] ?? ''));
    $city = trim((string) ($place['ort'] ?? $place['city'] ?? ''));
    $normalized = jeab_name_address_normalize_place($address, $postal, $city);
    $street = $normalized['street'];
    $postal = $normalized['postal'];
    $city = $normalized['city'];
    foreach (['payload', 'form', 'wix', 'fields', 'data'] as $key) {
        if (!is_array($msg[$key] ?? null)) {
            continue;
        }
        $bag = jeab_name_address_bag($msg[$key]);
        if ($name === '' && jeab_name_address_name_real($bag['name'], $emails)) {
            $name = $bag['name'];
        }
        if ($street === '' && $bag['street'] !== '') {
            $street = $bag['street'];
        }
        if ($postal === '' && $bag['postal'] !== '') {
            $postal = $bag['postal'];
        }
        if ($city === '' && $bag['city'] !== '') {
            $city = $bag['city'];
        }
    }

    $place = jeab_name_address_normalize_place($street, $postal, $city);

    return [
        'name' => $name,
        'address' => $place['street'],
        'street' => $place['street'],
        'postal_code' => $place['postal'],
        'city' => $place['city'],
    ];
}

function jeab_name_address_stamp(string $raw): int
{
    $raw = trim($raw);
    if ($raw === '') {
        return 0;
    }
    $at = strtotime($raw);

    return $at === false ? 0 : $at;
}

/**
 * @param array<string, mixed> $msg
 * @return array{at:int,name:string,street:string,postal:string,city:string,source:string}
 */
function jeab_name_address_fact_from_message(array $msg): array
{
    $at = 0;
    foreach (['received_at', 'date', 'created_at', 'updated_at'] as $key) {
        $at = jeab_name_address_stamp((string) ($msg[$key] ?? ''));
        if ($at > 0) {
            break;
        }
    }
    $probe = $msg;
    if (function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address((string) ($msg['from'] ?? ''))) {
        $probe['from_name'] = '';
    }
    $cust = function_exists('jeab_lead_customer')
        ? jeab_lead_customer($probe)
        : ['name' => '', 'email' => '', 'address' => ''];
    if (!is_array($cust)) {
        $cust = ['name' => '', 'email' => '', 'address' => ''];
    }
    $hint = jeab_name_address_contact_hint_from_lead($probe, $cust, []);
    $hs = jeab_name_address_hs_place($probe);
    $name = (string) $hint['name'];
    if ($name === '' && jeab_name_address_name_real($hs['name'], [(string) ($probe['from'] ?? '')])) {
        $name = $hs['name'];
    }
    $street = (string) $hint['street'];
    $postal = (string) $hint['postal_code'];
    $city = (string) $hint['city'];
    if ($street === '' && $hs['street'] !== '') {
        $street = $hs['street'];
    }
    if ($postal === '' && $hs['postal'] !== '') {
        $postal = $hs['postal'];
    }
    if ($city === '' && $hs['city'] !== '') {
        $city = $hs['city'];
    }
    $source = jeab_name_address_message_source($probe);

    return [
        'at' => $at,
        'name' => $name,
        'street' => $street,
        'postal' => $postal,
        'city' => $city,
        'source' => $source,
    ];
}

/**
 * Help Scout-kund som ligger kvar på tråden eller mejlet.
 *
 * @param array<string, mixed> $row
 * @return array{name:string,street:string,postal:string,city:string}
 */
function jeab_name_address_hs_place(array $row): array
{
    $empty = ['name' => '', 'street' => '', 'postal' => '', 'city' => ''];
    $first = trim((string) ($row['hs_name_first'] ?? ''));
    $last = trim((string) ($row['hs_name_last'] ?? ''));
    $name = trim((string) ($row['hs_name_display'] ?? ''));
    if ($name === '') {
        $name = trim($first . ' ' . $last);
    }
    $street = '';
    $postal = '';
    $city = '';
    foreach (['customer', 'primaryCustomer', 'hs_customer'] as $key) {
        if (!is_array($row[$key] ?? null)) {
            continue;
        }
        $customer = $row[$key];
        if ($name === '') {
            $firstName = trim((string) ($customer['firstName'] ?? $customer['first'] ?? ''));
            $lastName = trim((string) ($customer['lastName'] ?? $customer['last'] ?? ''));
            $name = trim((string) ($customer['name'] ?? ''));
            if ($name === '') {
                $name = trim($firstName . ' ' . $lastName);
            }
        }
        $addr = is_array($customer['address'] ?? null) ? $customer['address'] : [];
        $lines = $addr['lines'] ?? null;
        if (is_array($lines)) {
            $streetRaw = trim(implode(' ', array_map('strval', $lines)));
        } elseif (is_string($lines) && trim($lines) !== '') {
            $streetRaw = trim($lines);
        } else {
            $streetRaw = trim((string) ($customer['street'] ?? ''));
            if ($streetRaw === '' && is_string($customer['address'] ?? null)) {
                $streetRaw = trim((string) $customer['address']);
            }
        }
        $norm = jeab_name_address_normalize_place(
            $streetRaw,
            (string) ($addr['postalCode'] ?? $addr['postal_code'] ?? $customer['postal_code'] ?? $customer['postal'] ?? ''),
            (string) ($addr['city'] ?? $customer['city'] ?? $customer['ort'] ?? '')
        );
        if ($street === '' && $norm['street'] !== '') {
            $street = $norm['street'];
        }
        if ($postal === '' && $norm['postal'] !== '') {
            $postal = $norm['postal'];
        }
        if ($city === '' && $norm['city'] !== '') {
            $city = $norm['city'];
        }
    }
    if ($name !== '' && !jeab_name_address_name_real($name, [(string) ($row['from'] ?? '')])) {
        $name = '';
    }

    return $name === '' && $street === '' && $postal === '' && $city === ''
        ? $empty
        : ['name' => $name, 'street' => $street, 'postal' => $postal, 'city' => $city];
}

function jeab_name_address_message_source(array $msg): string
{
    $source = strtolower(trim((string) ($msg['source'] ?? '') . ' ' . (string) ($msg['import_source'] ?? '')));
    if (str_contains($source, 'helpscout') || str_contains($source, 'malmo_hs') || str_contains($source, 'malmo-hs')) {
        return 'helpscout';
    }

    return 'inkorg';
}

/**
 * @param array<string, mixed> $thread
 * @return list<array{at:int,name:string,street:string,postal:string,city:string,source:string}>
 */
function jeab_name_address_facts_from_thread(array $thread): array
{
    $facts = [];
    $lead = is_array($thread['lead_customer'] ?? null) ? $thread['lead_customer'] : [];
    if (!function_exists('jeab_contacts_inbox_first_text')) {
        require_once __DIR__ . '/contacts_strict.php';
    }
    $contactName = jeab_contacts_inbox_contact_name($thread);
    $contactEmail = jeab_contacts_inbox_contact_email($thread);
    if ($lead !== [] || $contactName !== '' || $contactEmail !== '') {
        $hintLead = $lead;
        $hintName = jeab_contacts_inbox_first_text($contactName, (string) ($hintLead['name'] ?? ''), (string) ($thread['customer_name'] ?? ''));
        $hintEmail = jeab_contacts_inbox_first_text($contactEmail, (string) ($hintLead['email'] ?? ''), (string) ($thread['customer_email'] ?? ''));
        if ($hintName !== '') {
            $hintLead['name'] = $hintName;
        }
        if ($hintEmail !== '') {
            $hintLead['email'] = $hintEmail;
        }
        $hint = jeab_name_address_contact_hint_from_lead(
            ['from' => $hintEmail, 'from_name' => $hintName],
            $hintLead,
            ['postal' => (string) ($hintLead['postal_code'] ?? $hintLead['postal'] ?? ''), 'ort' => (string) ($hintLead['city'] ?? $hintLead['ort'] ?? '')]
        );
        $facts[] = [
            'at' => 0,
            'name' => (string) $hint['name'],
            'street' => (string) $hint['street'],
            'postal' => (string) $hint['postal_code'],
            'city' => (string) $hint['city'],
            'source' => 'wix',
        ];
    }
    $customerName = jeab_contacts_inbox_first_text($contactName, trim((string) ($thread['customer_name'] ?? '')));
    $customerEmail = jeab_contacts_inbox_first_text($contactEmail, (string) ($thread['customer_email'] ?? ''));
    if (jeab_name_address_name_real($customerName, [$customerEmail])) {
        $facts[] = [
            'at' => 0,
            'name' => $customerName,
            'street' => '',
            'postal' => '',
            'city' => '',
            'source' => 'inkorg',
        ];
    }
    foreach ((array) ($thread['messages'] ?? []) as $msg) {
        if (!is_array($msg)) {
            continue;
        }
        $facts[] = jeab_name_address_fact_from_message($msg);
    }
    $hs = jeab_name_address_hs_place($thread);
    if ($hs['name'] !== '' || $hs['street'] !== '' || $hs['postal'] !== '' || $hs['city'] !== '') {
        $facts[] = [
            'at' => 0,
            'name' => $hs['name'],
            'street' => $hs['street'],
            'postal' => $hs['postal'],
            'city' => $hs['city'],
            'source' => jeab_name_address_message_source($thread),
        ];
    }

    return $facts;
}

/**
 * Nyast icke-tomt värde per fält.
 *
 * @param list<array{at:int,name:string,street:string,postal:string,city:string,source:string}> $facts
 * @return array{name:string,street:string,postal:string,city:string,address:string,source:string}
 */
function jeab_name_address_merge_facts(array $facts): array
{
    usort($facts, static function (array $a, array $b): int {
        return ((int) ($b['at'] ?? 0)) <=> ((int) ($a['at'] ?? 0));
    });
    $pick = ['name' => '', 'street' => '', 'postal' => '', 'city' => '', 'source' => ''];
    foreach ($facts as $fact) {
        foreach (['name', 'street', 'postal', 'city'] as $field) {
            $value = trim((string) ($fact[$field] ?? ''));
            if ($pick[$field] !== '' || $value === '') {
                continue;
            }
            if ($field === 'name' && !jeab_name_address_name_real($value, [])) {
                continue;
            }
            if ($field === 'street' && !jeab_name_address_street_real($value)) {
                continue;
            }
            $pick[$field] = $value;
            if ($pick['source'] === '') {
                $pick['source'] = (string) ($fact['source'] ?? 'inkorg');
            }
        }
    }
    foreach ($facts as $fact) {
        $value = trim((string) ($fact['name'] ?? ''));
        if ($value === '' || preg_match('/\s/u', $value) !== 1 || !jeab_name_address_name_real($value, [])) {
            continue;
        }
        $pick['name'] = $value;
        break;
    }

    return [
        'name' => $pick['name'],
        'street' => $pick['street'],
        'postal' => $pick['postal'],
        'city' => $pick['city'],
        'address' => $pick['street'],
        'source' => $pick['source'],
    ];
}

/**
 * @return array{name:string,address:string,street:string,postal:string,city:string,source:string}
 */
function jeab_name_address_from_thread(string $tid): array
{
    $empty = ['name' => '', 'address' => '', 'street' => '', 'postal' => '', 'city' => '', 'source' => ''];
    $tid = trim($tid);
    if ($tid === '' || !function_exists('jeab_auto_thread_by_id')) {
        return $empty;
    }
    $thread = jeab_auto_thread_by_id($tid);
    if (!is_array($thread)) {
        return $empty;
    }
    $merged = jeab_name_address_merge_facts(jeab_name_address_facts_from_thread($thread));

    return [
        'name' => $merged['name'],
        'address' => $merged['address'],
        'street' => $merged['street'],
        'postal' => $merged['postal'],
        'city' => $merged['city'],
        'source' => $merged['source'],
    ];
}

/**
 * @param array<string, mixed> $place
 * @return array<string, mixed>|null
 */
function jeab_name_address_save_parsed_contact(string $email, string $name, array $place): ?array
{
    if (!function_exists('jeab_contacts_sole') || !jeab_contacts_sole() || !function_exists('jeab_contacts_sole_inflow_upsert')) {
        return null;
    }
    $real = jeab_name_address_name_real($name, [$email]) ? trim($name) : '';
    $normalized = jeab_name_address_normalize_place(
        (string) ($place['street'] ?? $place['address'] ?? ''),
        (string) ($place['postal'] ?? $place['postal_code'] ?? ''),
        (string) ($place['city'] ?? $place['ort'] ?? '')
    );
    $street = $normalized['street'];
    $postal = $normalized['postal'];
    $city = $normalized['city'];
    if ($real === '' && $street === '' && $postal === '' && $city === '') {
        return null;
    }
    $contactId = trim((string) ($place['contact_id'] ?? ''));
    $company = jeab_name_address_company_for_place((string) ($place['company'] ?? ''), $postal, $city);
    $hint = [
        'name' => $real,
        'email' => $email,
        'phone' => (string) ($place['phone'] ?? ''),
        'street' => $street,
        'street_key' => ($street !== '' && function_exists('jeab_kontakt_street_key')) ? jeab_kontakt_street_key($street) : '',
        'postal_code' => $postal,
        'city' => $city,
        'company' => $company,
    ];
    if ($contactId !== '' && function_exists('jeab_kontakter_mutate') && function_exists('jeab_kontakt_get') && is_array(jeab_kontakt_get($contactId))) {
        try {
            $saved = jeab_kontakter_mutate(static function (array &$contacts) use ($contactId, $hint): ?array {
                if (!isset($contacts[$contactId]) || !is_array($contacts[$contactId])) {
                    return null;
                }
                $row = jeab_kontakt_fill_blanks($contacts[$contactId], $hint);
                $row = jeab_kontakt_fill_owner_blanks($row, $hint);
                $row['id'] = $contactId;
                $row['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
                $contacts[$contactId] = $row;

                return $row;
            });
            if (is_array($saved) && function_exists('jeab_contacts_sole_cache_forget')) {
                jeab_contacts_sole_cache_forget($contactId);
            }

            return is_array($saved) ? $saved : null;
        } catch (Throwable) {
            return null;
        }
    }
    try {
        return jeab_contacts_sole_inflow_upsert([
            'name' => $real,
            'email' => $email,
            'phone' => (string) ($place['phone'] ?? ''),
            'address' => $street,
            'city' => $city,
            'postal_code' => $postal,
            'company' => $company,
        ]);
    } catch (Throwable) {
        return null;
    }
}

/**
 * @return list<array<string, mixed>>
 */
function jeab_name_address_threads_for_contact(string $contactId): array
{
    $contactId = trim($contactId);
    if ($contactId === '') {
        return [];
    }
    $index = jeab_name_address_index();
    $threads = $index['threads_by_contact'][$contactId] ?? [];

    return array_values(array_filter($threads, 'is_array'));
}

/**
 * @param array<string, mixed> $contact
 * @param list<string> $emails
 */
function jeab_name_address_field_gap(string $field, string $value, array $emails = []): string
{
    $value = trim($value);
    if ($field === 'name') {
        if ($value === '') {
            return 'empty';
        }
        if (str_contains($value, '@') || (function_exists('jeab_kontakt_name_is_email') && jeab_kontakt_name_is_email($value))) {
            return 'email';
        }
        if (function_exists('jeab_contacts_sole_name_is_placeholder') && jeab_contacts_sole_name_is_placeholder($value)) {
            return 'placeholder';
        }
        if (function_exists('jeab_contacts_sole_name_is_local_part') && jeab_contacts_sole_name_is_local_part($value, $emails)) {
            return 'email';
        }

        return '';
    }
    if ($value === '') {
        return 'empty';
    }
    if ($field === 'street') {
        $kind = jeab_name_address_street_kind($value);

        return $kind === 'real' ? '' : $kind;
    }
    if ($field === 'city') {
        $kind = jeab_name_address_city_kind($value);

        return $kind === 'real' ? '' : $kind;
    }
    if (jeab_name_address_product_title($value)) {
        return 'product_title';
    }
    if (jeab_name_address_label_fragment($value)) {
        return 'placeholder';
    }

    return '';
}

/**
 * @param array<string, mixed> $contact
 * @return array{name:string,street:string,street_key:string,postal_code:string,city:string}
 */
function jeab_name_address_slice(array $contact): array
{
    return [
        'name' => function_exists('jeab_kontakt_row_name') ? jeab_kontakt_row_name($contact) : '',
        'street' => trim((string) ($contact['street'] ?? '')),
        'street_key' => trim((string) ($contact['street_key'] ?? '')),
        'postal_code' => trim((string) ($contact['postal_code'] ?? '')),
        'city' => trim((string) ($contact['city'] ?? '')),
    ];
}

/**
 * @param array<string, mixed> $before
 * @param array<string, mixed> $after
 * @return array<string, array{from:string,to:string}>
 */
function jeab_name_address_diff(array $before, array $after, array $emails): array
{
    $changes = [];
    foreach (['name', 'street', 'postal_code', 'city'] as $field) {
        $from = trim((string) ($before[$field] ?? ''));
        $to = trim((string) ($after[$field] ?? ''));
        if ($from === $to) {
            continue;
        }
        $gapField = $field === 'postal_code' ? 'postal' : $field;
        $changes[$field] = [
            'from' => jeab_name_address_field_gap($gapField, $from, $emails),
            'to' => 'set',
        ];
    }

    return $changes;
}

/**
 * @param array{name:int,street:int,postal_code:int,city:int} $fields
 */
function jeab_name_address_log_batch(string $batch, int $applied, array $fields): void
{
    if (!function_exists('jeab_log_app_error') || $applied < 1) {
        return;
    }
    $batch = trim($batch);
    $counts = sprintf(
        'name %d, street %d, postal_code %d, city %d',
        (int) ($fields['name'] ?? 0),
        (int) ($fields['street'] ?? 0),
        (int) ($fields['postal_code'] ?? 0),
        (int) ($fields['city'] ?? 0)
    );
    $stamp = function_exists('jeab_now') ? (string) jeab_now() : date('c');
    jeab_log_app_error(
        'kontakter',
        'name_address_backfill batch ' . $batch . ' skrev ' . $applied . ' (' . $counts . ')',
        [
            'no_webhook' => true,
            'skip_request' => true,
            'dedupe_seconds' => 0,
            'fingerprint' => hash('sha256', 'name_address_backfill|batch|' . $batch . '|' . $applied . '|' . $counts . '|' . $stamp . '|' . bin2hex(random_bytes(4))),
        ]
    );
}

/**
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 */
function jeab_name_address_maps_same(array $a, array $b): bool
{
    foreach (['reminders', 'orders', 'bookings'] as $kind) {
        $left = is_array($a[$kind] ?? null) ? $a[$kind] : [];
        $right = is_array($b[$kind] ?? null) ? $b[$kind] : [];
        ksort($left);
        ksort($right);
        if ($left != $right) {
            return false;
        }
    }

    return true;
}

/**
 * @return array{counts:array<string,int>,links:array<string,mixed>}
 */
function jeab_name_address_census(JeabStore $store): array
{
    $counts = function_exists('jeab_contacts_sole_counts')
        ? jeab_contacts_sole_counts($store)
        : [
            'reminders' => count($store->listReminders(true)),
            'orders' => 0,
            'bookings' => count($store->listAllBookings()),
        ];
    $links = function_exists('jeab_contacts_sole_link_map')
        ? jeab_contacts_sole_link_map($store)
        : ['reminders' => [], 'orders' => [], 'bookings' => []];

    return ['counts' => $counts, 'links' => $links];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_name_address_backfill(array $input, ?JeabStore $store = null): array
{
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabStore) {
        return ['ok' => false, 'error' => 'Ingen lagring.', 'dry' => true, 'sent' => 0];
    }
    $run = static function () use ($input, $store): array {
        return jeab_kontakter_name_address_backfill_inner($input, $store);
    };
    if (function_exists('jeab_kontakt_quiet_call')) {
        $result = jeab_kontakt_quiet_call($run);
    } else {
        $result = $run();
    }

    return is_array($result) ? $result : ['ok' => false, 'error' => 'Ingen lagring.', 'dry' => true, 'sent' => 0];
}

function jeab_name_address_log_failure(string $message): void
{
    if (!function_exists('jeab_log_app_error')) {
        return;
    }
    $message = trim($message);
    if ($message === '') {
        $message = 'okänt fel';
    }
    jeab_log_app_error('kontakter', 'name_address_backfill avbröts: ' . $message, [
        'no_webhook' => true,
        'skip_request' => true,
        'dedupe_seconds' => 0,
    ]);
}

/**
 * @return array<string, mixed>
 */
function jeab_name_address_guard(callable $run): array
{
    try {
        $result = $run();

        return is_array($result)
            ? $result
            : ['ok' => false, 'error' => 'name_address_backfill avbröts.', 'status' => 500, 'sent' => 0, 'dry' => 1];
    } catch (Throwable $e) {
        jeab_name_address_log_failure($e::class . ': ' . $e->getMessage());

        return ['ok' => false, 'error' => 'name_address_backfill avbröts.', 'status' => 500, 'sent' => 0, 'dry' => 1];
    }
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_name_address_backfill_dispatch(array $input): array
{
    return jeab_name_address_guard(static function () use ($input): array {
        if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
            return ['ok' => false, 'error' => 'Fel token.', 'status' => 401, 'sent' => 0];
        }
        $result = jeab_kontakter_name_address_backfill($input);
        $result['status'] = !empty($result['ok']) ? 200 : (int) ($result['status'] ?? 400);
        $result['sent'] = 0;

        return $result;
    });
}

function jeab_name_address_batch_limit(array $input): int
{
    if (!array_key_exists('limit', $input) || $input['limit'] === '' || $input['limit'] === null) {
        return 50;
    }
    $limit = (int) $input['limit'];
    if ($limit < 1) {
        return 50;
    }

    return min(200, $limit);
}

function jeab_name_address_next_seq(string $batch): int
{
    $batch = trim($batch);
    if ($batch === '' || !function_exists('jeab_contacts_sole_undo_pdo')) {
        return 1;
    }
    $pdo = jeab_contacts_sole_undo_pdo();
    if (!$pdo instanceof PDO) {
        return 1;
    }
    try {
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(seq), 0) FROM contacts_sole_undo WHERE batch_id = ? AND kind = ?');
        $stmt->execute([$batch, 'name_address']);

        return ((int) $stmt->fetchColumn()) + 1;
    } catch (Throwable) {
        return 1;
    }
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_name_address_backfill_inner(array $input, JeabStore $store): array
{
    $dry = function_exists('jeab_kontakt_dry') ? jeab_kontakt_dry($input) : true;
    $undo = !empty($input['undo']) && (string) $input['undo'] !== '0';
    if ($undo) {
        return jeab_kontakter_name_address_undo($input, $store, $dry);
    }
    $asked = array_fill_keys(jeab_name_address_asked_ids($input), true);
    $listRaw = $input['contact_ids'] ?? '';
    $hasList = is_array($listRaw) ? $listRaw !== [] : trim((string) $listRaw) !== '';
    $only = $hasList ? '' : trim((string) ($input['contact_id'] ?? ''));
    $limit = jeab_name_address_batch_limit($input);
    $afterId = trim((string) ($input['after_id'] ?? ''));
    $offset = max(0, (int) ($input['offset'] ?? 0));
    $before = jeab_name_address_census($store);
    $contacts = function_exists('jeab_kontakter_load') ? jeab_kontakter_load() : [];
    $ids = [];
    $byId = [];
    foreach ($contacts as $id => $contact) {
        if (!is_array($contact)) {
            continue;
        }
        $cid = trim((string) ($contact['id'] ?? $id));
        if ($cid === '') {
            continue;
        }
        if ($asked !== [] && !isset($asked[$cid])) {
            continue;
        }
        $ids[] = $cid;
        $byId[$cid] = $contact;
    }
    sort($ids, SORT_STRING);
    if ($only !== '') {
        $page = $ids;
        $done = 1;
        $partial = 0;
    } elseif ($afterId !== '') {
        $page = [];
        foreach ($ids as $cid) {
            if (strcmp($cid, $afterId) <= 0) {
                continue;
            }
            $page[] = $cid;
            if (count($page) >= $limit) {
                break;
            }
        }
        $lastKnown = $ids === [] ? '' : $ids[count($ids) - 1];
        $done = count($page) < $limit || ($page !== [] && $page[count($page) - 1] === $lastKnown) ? 1 : 0;
        $partial = 0;
    } else {
        $page = array_slice($ids, $offset, $limit);
        $done = ($offset + count($page)) >= count($ids) ? 1 : 0;
        $partial = 0;
    }
    $index = jeab_name_address_index($store);
    $plans = [];
    $lastId = $afterId;
    $deadline = microtime(true) + 20;
    $examined = 0;
    foreach ($page as $cid) {
        if ($examined > 0 && microtime(true) > $deadline) {
            $done = 0;
            $partial = 1;
            break;
        }
        $examined++;
        $lastId = $cid;
        $contact = $byId[$cid] ?? null;
        if (!is_array($contact)) {
            continue;
        }
        $contactName = function_exists('jeab_kontakt_row_name') ? jeab_kontakt_row_name($contact) : '';
        if (!isset($asked[$cid]) && jeab_name_address_test_name($contactName)) {
            continue;
        }
        $plan = jeab_name_address_plan_contact($cid, $contact, $index);
        if ($plan === null) {
            continue;
        }
        $plans[] = $plan;
    }
    $batch = trim((string) ($input['batch_id'] ?? ''));
    $applied = 0;
    if (!$dry && $plans !== []) {
        if ($batch === '') {
            $batch = function_exists('jeab_contacts_sole_batch') ? jeab_contacts_sole_batch() : bin2hex(random_bytes(8));
        }
        $seq = jeab_name_address_next_seq($batch) - 1;
        $fieldCounts = ['name' => 0, 'street' => 0, 'postal_code' => 0, 'city' => 0];
        foreach ($plans as $plan) {
            $cid = (string) $plan['id'];
            $hint = is_array($plan['hint'] ?? null) ? $plan['hint'] : [];
            $wrote = jeab_kontakter_mutate(static function (array &$live) use ($cid, $hint): ?array {
                if (!isset($live[$cid]) || !is_array($live[$cid])) {
                    return null;
                }
                $beforeRow = $live[$cid];
                $row = jeab_kontakt_fill_blanks($beforeRow, $hint);
                $row = jeab_kontakt_fill_owner_blanks($row, $hint);
                $row = jeab_name_address_protect($beforeRow, $row);
                $row['id'] = $cid;
                $row['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
                $live[$cid] = $row;

                return $row;
            });
            if (!is_array($wrote)) {
                continue;
            }
            $afterSlice = jeab_name_address_slice($wrote);
            $beforeSlice = is_array($plan['before'] ?? null) ? $plan['before'] : [];
            if ($beforeSlice == $afterSlice) {
                continue;
            }
            $seq++;
            if (function_exists('jeab_contacts_sole_undo_add')) {
                jeab_contacts_sole_undo_add($batch, $seq, 'name_address', 'contact', $cid, $beforeSlice, $afterSlice);
            }
            foreach (array_keys(is_array($plan['changes'] ?? null) ? $plan['changes'] : []) as $field) {
                $field = (string) $field;
                if (isset($fieldCounts[$field])) {
                    $fieldCounts[$field]++;
                }
            }
            if (function_exists('jeab_contacts_sole_cache_forget')) {
                jeab_contacts_sole_cache_forget($cid);
            }
            $applied++;
        }
        if ($applied > 0) {
            jeab_name_address_log_batch($batch, $applied, $fieldCounts);
        }
    }
    $after = jeab_name_address_census($store);
    $rows = [];
    foreach ($plans as $plan) {
        $rows[] = [
            'id' => (string) $plan['id'],
            'source' => (string) ($plan['source'] ?? ''),
            'changes' => is_array($plan['changes'] ?? null) ? $plan['changes'] : [],
            'name' => (string) ($plan['proposed']['name'] ?? ''),
            'street' => (string) ($plan['proposed']['street'] ?? ''),
            'postal_code' => (string) ($plan['proposed']['postal_code'] ?? ''),
            'city' => (string) ($plan['proposed']['city'] ?? ''),
        ];
    }

    return [
        'ok' => true,
        'dry' => $dry ? 1 : 0,
        'written' => !$dry && $applied > 0,
        'sent' => 0,
        'batch_id' => $batch,
        'contacts' => $rows,
        'applied' => $dry ? 0 : $applied,
        'limit' => $only !== '' ? count($page) : $limit,
        'after_id' => $afterId,
        'next_after_id' => $lastId,
        'offset' => $offset,
        'next_offset' => $afterId !== '' ? 0 : $offset + $examined,
        'scanned' => $examined,
        'done' => $done,
        'partial' => $partial,
        'counts_same' => $before['counts'] == $after['counts'],
        'links_same' => jeab_name_address_maps_same($before['links'], $after['links']),
        'counts' => ['before' => $before['counts'], 'after' => $after['counts']],
    ];
}

/**
 * Namn och adress som redan ligger på en länkad påminnelse eller order.
 * Titeln används inte. Samma parser som inkorg och Wix.
 *
 * @param array<string, mixed> $row
 * @return list<array{at:int,name:string,street:string,postal:string,city:string,source:string}>
 */
function jeab_name_address_facts_from_linked(array $row, string $source): array
{
    $at = 0;
    foreach (['updated_at', 'created_at'] as $key) {
        $at = jeab_name_address_stamp((string) ($row[$key] ?? ''));
        if ($at > 0) {
            break;
        }
    }
    $emails = [(string) ($row['email'] ?? '')];
    $name = '';
    foreach (['customer_name', 'huvudsokande', 'name'] as $key) {
        $cand = trim((string) ($row[$key] ?? ''));
        if (jeab_name_address_name_real($cand, $emails)) {
            $name = $cand;
            break;
        }
    }
    $rawStreet = trim((string) ($row['address'] ?? $row['gata'] ?? ''));
    $place = jeab_name_address_normalize_place(
        $rawStreet,
        (string) ($row['postal'] ?? $row['postal_code'] ?? ''),
        (string) ($row['ort'] ?? $row['city'] ?? '')
    );
    $facts = [];
    if ($name !== '' || $place['street'] !== '' || $place['postal'] !== '' || $place['city'] !== '') {
        $facts[] = [
            'at' => $at,
            'name' => $name,
            'street' => $place['street'],
            'postal' => $place['postal'],
            'city' => $place['city'],
            'source' => $source,
        ];
    }
    $note = function_exists('jeab_reminder_notes_text') ? jeab_reminder_notes_text($row) : (string) ($row['note'] ?? '');
    if ($note !== '' && preg_match('/(?:^|\n)\s*(?:namn|adress|address|postnummer|ort)\s*:/iu', $note) === 1 && function_exists('jeab_lead_customer')) {
        $cust = jeab_lead_customer([
            'body_text' => $note,
            'from' => (string) ($row['email'] ?? ''),
            'from_name' => $name,
        ]);
        if (!is_array($cust)) {
            $cust = ['name' => '', 'email' => '', 'address' => ''];
        }
        $hint = jeab_name_address_contact_hint_from_lead([
            'from' => (string) ($row['email'] ?? ''),
            'from_name' => $name,
            'body_text' => $note,
        ], $cust, [
            'postal' => (string) ($row['postal'] ?? ''),
            'ort' => (string) ($row['ort'] ?? ''),
        ]);
        if ($hint['name'] !== '' || $hint['street'] !== '' || $hint['postal_code'] !== '' || $hint['city'] !== '') {
            $facts[] = [
                'at' => $at,
                'name' => (string) $hint['name'],
                'street' => (string) $hint['street'],
                'postal' => (string) $hint['postal_code'],
                'city' => (string) $hint['city'],
                'source' => $source,
            ];
        }
    }

    return $facts;
}

/**
 * Trådar, påminnelser och ordrar läses en gång. Uppslaget per kontakt är sedan en nyckel.
 *
 * @return array{
 *   threads_by_contact:array<string, list<array<string, mixed>>>,
 *   threads_by_id:array<string, array<string, mixed>>,
 *   threads_by_email:array<string, list<string>>,
 *   reminders_by_contact:array<string, list<array<string, mixed>>>,
 *   reminders_by_id:array<int, array<string, mixed>>,
 *   purchases_by_reminder:array<int, array<string, mixed>>,
 *   purchases_by_contact:array<string, list<array<string, mixed>>>
 * }
 */
function jeab_name_address_index(?JeabStore $store = null): array
{
    $threadsByContact = [];
    $threadsById = [];
    $threadsByEmail = [];
    $rememberEmail = static function (string $email, string $tid) use (&$threadsByEmail): void {
        $email = strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@') || $tid === '') {
            return;
        }
        if (function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address($email)) {
            return;
        }
        $list = $threadsByEmail[$email] ?? [];
        if (!in_array($tid, $list, true)) {
            $list[] = $tid;
        }
        $threadsByEmail[$email] = $list;
    };
    if (function_exists('jeab_inkorg_threads')) {
        $threads = [];
        $contactIds = [];
        foreach (jeab_inkorg_threads() as $thread) {
            if (!is_array($thread)) {
                continue;
            }
            $threads[] = $thread;
            $cid = trim((string) ($thread['contact_id'] ?? ''));
            if ($cid !== '') {
                $contactIds[$cid] = $cid;
            }
        }
        $loadedContacts = $contactIds !== [] && function_exists('jeab_contacts_sole_load_ids')
            ? jeab_contacts_sole_load_ids(array_values($contactIds))
            : [];
        $contactEmailCache = [];
        $contactEmail = static function (string $cid) use ($loadedContacts, &$contactEmailCache): string {
            if ($cid === '') {
                return '';
            }
            if (array_key_exists($cid, $contactEmailCache)) {
                return $contactEmailCache[$cid];
            }
            $row = $loadedContacts[$cid] ?? null;
            if (!is_array($row) && function_exists('jeab_kontakt_get')) {
                $row = jeab_kontakt_get($cid);
            }
            $email = '';
            if (is_array($row)) {
                $hidden = function_exists('jeab_record_company_allowed') && !jeab_record_company_allowed($row['company'] ?? null);
                if (!$hidden && function_exists('jeab_kontakt_row_email')) {
                    $email = trim((string) jeab_kontakt_row_email($row));
                }
            }
            $contactEmailCache[$cid] = $email;

            return $email;
        };
        foreach ($threads as $thread) {
            $tid = trim((string) ($thread['id'] ?? ''));
            if ($tid === '') {
                continue;
            }
            $threadsById[$tid] = $thread;
            $cid = trim((string) ($thread['contact_id'] ?? ''));
            if ($cid !== '') {
                $threadsByContact[$cid][] = $thread;
            }
            $rememberEmail($contactEmail($cid), $tid);
            $rememberEmail((string) ($thread['customer_email'] ?? ''), $tid);
            $lead = is_array($thread['lead_customer'] ?? null) ? $thread['lead_customer'] : [];
            $rememberEmail((string) ($lead['email'] ?? ''), $tid);
            $customer = is_array($thread['customer'] ?? null) ? $thread['customer'] : [];
            $rememberEmail((string) ($customer['email'] ?? ''), $tid);
            $primary = is_array($thread['primaryCustomer'] ?? null) ? $thread['primaryCustomer'] : [];
            $rememberEmail((string) ($primary['email'] ?? ''), $tid);
            foreach ((array) ($thread['messages'] ?? []) as $msg) {
                if (!is_array($msg)) {
                    continue;
                }
                $rememberEmail((string) ($msg['from'] ?? ''), $tid);
                $msgCustomer = is_array($msg['customer'] ?? null) ? $msg['customer'] : [];
                $rememberEmail((string) ($msgCustomer['email'] ?? ''), $tid);
            }
        }
    }
    $remindersByContact = [];
    $remindersById = [];
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if ($store instanceof JeabStore) {
        foreach ($store->listReminders(true) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $reminderId = (int) ($row['id'] ?? 0);
            if ($reminderId > 0) {
                $remindersById[$reminderId] = $row;
            }
            $cid = trim((string) ($row['contact_id'] ?? ''));
            if ($cid === '') {
                continue;
            }
            $remindersByContact[$cid][] = $row;
            $tid = trim((string) ($row['thread_id'] ?? ''));
            if ($tid === '' && function_exists('jeab_reminder_thread_from_text') && function_exists('jeab_reminder_notes_text')) {
                $tid = jeab_reminder_thread_from_text(jeab_reminder_notes_text($row));
            }
            if ($tid !== '' && isset($threadsById[$tid])) {
                $known = false;
                foreach ($threadsByContact[$cid] ?? [] as $thread) {
                    if ((string) ($thread['id'] ?? '') === $tid) {
                        $known = true;
                        break;
                    }
                }
                if (!$known) {
                    $threadsByContact[$cid][] = $threadsById[$tid];
                }
            }
            $rememberEmail((string) ($row['email'] ?? ''), $tid);
        }
    }
    $purchasesByReminder = [];
    $purchasesByContact = [];
    if (function_exists('jeab_purchase_all')) {
        try {
            $purchases = jeab_purchase_all();
        } catch (Throwable) {
            $purchases = [];
        }
        foreach ($purchases as $purchase) {
            if (!is_array($purchase)) {
                continue;
            }
            $rid = (int) ($purchase['reminder_id'] ?? 0);
            if ($rid > 0) {
                $purchasesByReminder[$rid] = $purchase;
            }
            $cid = trim((string) ($purchase['contact_id'] ?? ''));
            if ($cid !== '') {
                $purchasesByContact[$cid][] = $purchase;
            }
        }
    }

    return [
        'threads_by_contact' => $threadsByContact,
        'threads_by_id' => $threadsById,
        'threads_by_email' => $threadsByEmail,
        'reminders_by_contact' => $remindersByContact,
        'reminders_by_id' => $remindersById,
        'purchases_by_reminder' => $purchasesByReminder,
        'purchases_by_contact' => $purchasesByContact,
    ];
}

/**
 * @param array<string, mixed>|null $index
 * @param list<string> $emails
 * @return list<array{at:int,name:string,street:string,postal:string,city:string,source:string}>
 */
function jeab_name_address_facts_for_contact(string $id, ?array $index = null, array $emails = []): array
{
    if ($index === null) {
        $index = jeab_name_address_index();
    }
    $facts = [];
    $seen = [];
    $takeThread = static function (array $thread) use (&$facts, &$seen): void {
        $tid = trim((string) ($thread['id'] ?? ''));
        if ($tid !== '' && isset($seen[$tid])) {
            return;
        }
        if ($tid !== '') {
            $seen[$tid] = true;
        }
        foreach (jeab_name_address_facts_from_thread($thread) as $fact) {
            $facts[] = $fact;
        }
    };
    foreach ($index['threads_by_contact'][$id] ?? [] as $thread) {
        if (is_array($thread)) {
            $takeThread($thread);
        }
    }
    foreach ($emails as $email) {
        $email = strtolower(trim($email));
        foreach ($index['threads_by_email'][$email] ?? [] as $tid) {
            $thread = $index['threads_by_id'][$tid] ?? null;
            if (is_array($thread)) {
                $takeThread($thread);
            }
        }
    }
    $seenReminders = [];
    foreach ($index['reminders_by_contact'][$id] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rid = (int) ($row['id'] ?? 0);
        if ($rid > 0) {
            $seenReminders[$rid] = true;
        }
        foreach (jeab_name_address_facts_from_linked($row, 'paminnelse') as $fact) {
            $facts[] = $fact;
        }
    }
    foreach (jeab_name_address_contact_orders($id, $index) as $purchase) {
        $record = jeab_name_address_order_record($purchase);
        if ($record['name'] !== '' || $record['street'] !== '' || $record['postal'] !== '' || $record['city'] !== '') {
            $place = jeab_name_address_normalize_place($record['street'], $record['postal'], $record['city']);
            $facts[] = [
                'at' => jeab_name_address_stamp((string) ($purchase['updated_at'] ?? $purchase['created_at'] ?? '')),
                'name' => $record['name'],
                'street' => $place['street'],
                'postal' => $place['postal'],
                'city' => $place['city'],
                'source' => 'order',
            ];
        }
        $rid = (int) ($purchase['reminder_id'] ?? 0);
        $reminder = $rid > 0 ? ($index['reminders_by_id'][$rid] ?? null) : null;
        if (!is_array($reminder) || isset($seenReminders[$rid])) {
            continue;
        }
        $seenReminders[$rid] = true;
        foreach (jeab_name_address_facts_from_linked($reminder, 'paminnelse') as $fact) {
            $facts[] = $fact;
        }
    }

    return $facts;
}

/**
 * Ordrar som hör till kontakten: via contact_id eller via en länkad påminnelse.
 *
 * @param array<string, mixed> $index
 * @return list<array<string, mixed>>
 */
function jeab_name_address_contact_orders(string $id, array $index): array
{
    $id = trim($id);
    if ($id === '') {
        return [];
    }
    $out = [];
    $add = static function (array $purchase) use (&$out): void {
        $rid = (int) ($purchase['reminder_id'] ?? 0);
        $pid = trim((string) ($purchase['id'] ?? ''));
        $key = $rid > 0 ? 'r' . $rid : ($pid !== '' ? 'p' . $pid : '');
        if ($key === '' || isset($out[$key])) {
            return;
        }
        $out[$key] = $purchase;
    };
    foreach ($index['purchases_by_contact'][$id] ?? [] as $purchase) {
        if (is_array($purchase)) {
            $add($purchase);
        }
    }
    foreach ($index['reminders_by_contact'][$id] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rid = (int) ($row['id'] ?? 0);
        $purchase = $rid > 0 ? ($index['purchases_by_reminder'][$rid] ?? null) : null;
        if (is_array($purchase)) {
            $add($purchase);
        }
    }

    return array_values($out);
}

/**
 * Namn och adress från ordern, även när toppfälten är borttagna.
 * Första kandidaten med husnummer vinner. Annars den som har postnummer eller ort.
 *
 * @param array<string, mixed> $purchase
 * @return array{name:string,street:string,postal:string,city:string}
 */
function jeab_name_address_order_record(array $purchase): array
{
    $emails = [];
    $email = trim((string) ($purchase['email'] ?? ''));
    if ($email !== '') {
        $emails[] = $email;
    }
    $names = [];
    foreach (['customer_name', 'name', 'huvudsokande'] as $key) {
        $cand = trim((string) ($purchase[$key] ?? ''));
        if ($cand !== '') {
            $names[] = $cand;
        }
    }
    $applicant = is_array($purchase['applicant'] ?? null) ? $purchase['applicant'] : [];
    $applicantName = trim((string) ($applicant['name'] ?? ''));
    if ($applicantName !== '') {
        $names[] = $applicantName;
    }
    $applicantEmail = trim((string) ($applicant['email'] ?? ''));
    if ($applicantEmail !== '') {
        $emails[] = $applicantEmail;
    }
    foreach (['owners', 'medsokande'] as $listKey) {
        foreach ((array) ($purchase[$listKey] ?? []) as $person) {
            if (!is_array($person)) {
                continue;
            }
            $cand = trim((string) ($person['name'] ?? $person['namn'] ?? ''));
            if ($cand !== '') {
                $names[] = $cand;
            }
            $personEmail = trim((string) ($person['email'] ?? ''));
            if ($personEmail !== '') {
                $emails[] = $personEmail;
            }
        }
    }
    $best = '';
    $bestFull = false;
    foreach ($names as $cand) {
        if (!jeab_name_address_name_real($cand, $emails)) {
            continue;
        }
        $full = preg_match('/\s/u', $cand) === 1;
        if ($best === '' || ($full && !$bestFull)) {
            $best = $cand;
            $bestFull = $full;
        }
    }
    $postal = '';
    foreach (['postal', 'postal_code', 'postnummer', 'postnr'] as $key) {
        $cand = trim((string) ($purchase[$key] ?? ''));
        if ($cand !== '') {
            $postal = $cand;
            break;
        }
    }
    $city = '';
    foreach (['ort', 'city'] as $key) {
        $cand = trim((string) ($purchase[$key] ?? ''));
        if ($cand !== '') {
            $city = $cand;
            break;
        }
    }
    $cands = [];
    foreach (['address', 'huvudsokande_address', 'address_previous', 'gata', 'street'] as $key) {
        $street = trim((string) ($purchase[$key] ?? ''));
        if ($street !== '') {
            $cands[] = ['street' => $street, 'postal' => $postal, 'city' => $city];
        }
    }
    foreach (['owners', 'medsokande'] as $listKey) {
        foreach ((array) ($purchase[$listKey] ?? []) as $person) {
            if (!is_array($person)) {
                continue;
            }
            $street = trim((string) ($person['address'] ?? $person['adress'] ?? ''));
            $personPostal = '';
            foreach (['postal', 'postal_code', 'postnummer', 'postnr'] as $key) {
                $cand = trim((string) ($person[$key] ?? ''));
                if ($cand !== '') {
                    $personPostal = $cand;
                    break;
                }
            }
            $personCity = '';
            foreach (['ort', 'city'] as $key) {
                $cand = trim((string) ($person[$key] ?? ''));
                if ($cand !== '') {
                    $personCity = $cand;
                    break;
                }
            }
            if ($street === '' && $personPostal === '' && $personCity === '') {
                continue;
            }
            $cands[] = [
                'street' => $street,
                'postal' => $personPostal !== '' ? $personPostal : $postal,
                'city' => $personCity !== '' ? $personCity : $city,
            ];
        }
    }
    $fallback = ['street' => '', 'postal' => $postal, 'city' => $city];
    $fallbackScore = ($postal !== '' ? 2 : 0) + ($city !== '' ? 1 : 0);
    foreach ($cands as $cand) {
        $place = jeab_name_address_normalize_place($cand['street'], $cand['postal'], $cand['city']);
        if ($place['street'] !== '') {
            return [
                'name' => $best,
                'street' => $cand['street'],
                'postal' => $cand['postal'],
                'city' => $cand['city'],
            ];
        }
        $score = ($place['postal'] !== '' ? 2 : 0) + ($place['city'] !== '' ? 1 : 0);
        if ($score > $fallbackScore) {
            $fallback = $cand;
            $fallbackScore = $score;
        }
    }

    return [
        'name' => $best,
        'street' => $fallback['street'],
        'postal' => $fallback['postal'],
        'city' => $fallback['city'],
    ];
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_name_address_put_name(array $row, string $name): array
{
    $owners = [];
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (is_array($owner)) {
            $owners[] = $owner;
        }
    }
    if ($owners === []) {
        $row['owners'] = [['name' => $name, 'personnummer' => '', 'contact' => true, 'email' => '', 'phone' => '']];

        return $row;
    }
    $index = 0;
    foreach ($owners as $i => $owner) {
        if (!empty($owner['contact'])) {
            $index = $i;
            break;
        }
    }
    $owners[$index]['name'] = $name;
    $owners[$index]['contact'] = true;
    $row['owners'] = $owners;

    return $row;
}

/**
 * Tömmer inget fält. Skriver inte över en gata som är unknown eller real,
 * utom när en riktig gata bara tappar en våningsnotering.
 *
 * @param array<string, mixed> $before
 * @param array<string, mixed> $after
 * @return array<string, mixed>
 */
function jeab_name_address_protect(array $before, array $after): array
{
    $oldName = function_exists('jeab_kontakt_row_name') ? jeab_kontakt_row_name($before) : trim((string) ($before['name'] ?? ''));
    $newName = function_exists('jeab_kontakt_row_name') ? jeab_kontakt_row_name($after) : trim((string) ($after['name'] ?? ''));
    if ($oldName !== '' && $newName === '') {
        $after = jeab_name_address_put_name($after, $oldName);
    }
    $oldStreet = trim((string) ($before['street'] ?? ''));
    $newStreet = trim((string) ($after['street'] ?? ''));
    if ($oldStreet !== '' && $newStreet === '') {
        $after['street'] = (string) ($before['street'] ?? '');
        $after['street_key'] = (string) ($before['street_key'] ?? '');
        $newStreet = $oldStreet;
    }
    $streetKind = jeab_name_address_street_kind($oldStreet);
    if (in_array($streetKind, ['unknown', 'real'], true) && $newStreet !== $oldStreet) {
        $prepared = jeab_name_address_prepare_street($oldStreet);
        $allow = $streetKind === 'real' && $prepared !== '' && $newStreet === $prepared;
        if (!$allow) {
            $after['street'] = (string) ($before['street'] ?? '');
            $after['street_key'] = (string) ($before['street_key'] ?? '');
        }
    }
    $oldCity = trim((string) ($before['city'] ?? ''));
    $newCity = trim((string) ($after['city'] ?? ''));
    if ($oldCity !== '' && $newCity === '') {
        $after['city'] = (string) ($before['city'] ?? '');
        $newCity = $oldCity;
    }
    $cityKind = jeab_name_address_city_kind($oldCity);
    if (in_array($cityKind, ['unknown', 'real'], true) && $newCity !== $oldCity) {
        $after['city'] = (string) ($before['city'] ?? '');
    }
    $oldPostal = trim((string) ($before['postal_code'] ?? ''));
    if ($oldPostal !== '' && trim((string) ($after['postal_code'] ?? '')) === '') {
        $after['postal_code'] = (string) ($before['postal_code'] ?? '');
    }

    return $after;
}

/**
 * @param array<string, mixed> $contact
 * @param array<string, mixed>|null $index
 * @return array<string, mixed>|null
 */
function jeab_name_address_plan_contact(string $id, array $contact, ?array $index = null): ?array
{
    $emails = function_exists('jeab_contacts_sole_contact_emails')
        ? jeab_contacts_sole_contact_emails($contact)
        : [];
    $before = jeab_name_address_slice($contact);
    $nameGap = jeab_name_address_field_gap('name', $before['name'], $emails);
    $streetGap = jeab_name_address_field_gap('street', $before['street'], $emails);
    $postalGap = trim($before['postal_code']) === '' ? 'empty' : '';
    $cityGap = jeab_name_address_field_gap('city', $before['city'], $emails);
    $streetNorm = '';
    if (jeab_name_address_street_kind($before['street']) === 'real') {
        $prepared = jeab_name_address_prepare_street($before['street']);
        if ($prepared !== '' && $prepared !== trim($before['street'])) {
            $streetNorm = $prepared;
        }
    }
    $open = jeab_name_address_gap_open($nameGap)
        || jeab_name_address_gap_open($streetGap)
        || jeab_name_address_gap_open($postalGap)
        || jeab_name_address_gap_open($cityGap);
    if (!$open && $streetNorm === '') {
        return null;
    }
    $merged = jeab_name_address_merge_facts(jeab_name_address_facts_for_contact($id, $index, $emails));
    if (!jeab_name_address_gap_open($nameGap)) {
        $merged['name'] = '';
    }
    if (!jeab_name_address_gap_open($streetGap)) {
        $merged['street'] = $streetNorm;
    }
    if (!jeab_name_address_gap_open($postalGap)) {
        $merged['postal'] = '';
    }
    if (jeab_name_address_gap_open($cityGap) && $merged['city'] === '' && !jeab_name_address_has_house_number($before['street'])) {
        $town = jeab_name_address_town_canon($before['street']);
        if ($town !== '') {
            $merged['city'] = $town;
            if ($merged['source'] === '') {
                $merged['source'] = 'inkorg';
            }
        }
    }
    if (jeab_name_address_gap_open($cityGap)) {
        $postalNow = $merged['postal'] !== '' ? $merged['postal'] : $before['postal_code'];
        $seed = $merged['city'] !== '' ? $merged['city'] : $before['city'];
        $picked = jeab_name_address_accept_city($seed, $postalNow);
        if ($picked === '') {
            $picked = jeab_name_address_postal_city($postalNow);
        }
        if ($picked !== '' && $picked !== trim($before['city'])) {
            $merged['city'] = $picked;
            if ($merged['source'] === '') {
                $merged['source'] = 'inkorg';
            }
        } else {
            $merged['city'] = '';
        }
    } else {
        $merged['city'] = '';
    }
    if ($merged['name'] === '' && $merged['street'] === '' && $merged['postal'] === '' && $merged['city'] === '') {
        return null;
    }
    $hint = [
        'name' => $merged['name'],
        'email' => '',
        'phone' => '',
        'street' => $merged['street'],
        'street_key' => ($merged['street'] !== '' && function_exists('jeab_kontakt_street_key'))
            ? jeab_kontakt_street_key($merged['street'])
            : '',
        'postal_code' => $merged['postal'],
        'city' => $merged['city'],
    ];
    $next = jeab_kontakt_fill_blanks($contact, $hint);
    $next = jeab_kontakt_fill_owner_blanks($next, $hint);
    $next = jeab_name_address_protect($contact, $next);
    $after = jeab_name_address_slice($next);
    $changes = jeab_name_address_diff($before, $after, $emails);
    if ($changes === []) {
        return null;
    }

    return [
        'id' => $id,
        'source' => $merged['source'] !== '' ? $merged['source'] : 'inkorg',
        'before' => $before,
        'proposed' => $after,
        'hint' => $hint,
        'changes' => $changes,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_name_address_undo(array $input, JeabStore $store, bool $dry): array
{
    $batch = trim((string) ($input['batch_id'] ?? ''));
    if ($batch === '') {
        return ['ok' => false, 'error' => 'batch_id saknas.', 'dry' => $dry ? 1 : 0, 'sent' => 0, 'written' => false];
    }
    if (!function_exists('jeab_contacts_sole_undo_all')) {
        return ['ok' => false, 'error' => 'Ingen ångra-lista.', 'dry' => $dry ? 1 : 0, 'sent' => 0, 'written' => false];
    }
    $rows = [];
    foreach (jeab_contacts_sole_undo_all() as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ((string) ($row['batch_id'] ?? '') !== $batch || (string) ($row['kind'] ?? '') !== 'name_address' || (int) ($row['undone'] ?? 0) === 1) {
            continue;
        }
        $rows[] = $row;
    }
    if ($rows === []) {
        return ['ok' => false, 'error' => 'Ingen körning att ångra.', 'dry' => $dry ? 1 : 0, 'sent' => 0, 'batch_id' => $batch, 'written' => false];
    }
    $before = jeab_name_address_census($store);
    $report = [];
    foreach ($rows as $row) {
        $beforeSlice = json_decode((string) ($row['before_json'] ?? ''), true);
        $afterSlice = json_decode((string) ($row['after_json'] ?? ''), true);
        $report[] = [
            'id' => (string) ($row['entity_id'] ?? ''),
            'restore' => is_array($beforeSlice) ? $beforeSlice : [],
            'from' => is_array($afterSlice) ? $afterSlice : [],
        ];
    }
    $restored = 0;
    if (!$dry) {
        foreach ($rows as $row) {
            $cid = trim((string) ($row['entity_id'] ?? ''));
            $beforeSlice = json_decode((string) ($row['before_json'] ?? ''), true);
            $afterSlice = json_decode((string) ($row['after_json'] ?? ''), true);
            if ($cid === '' || !is_array($beforeSlice) || !is_array($afterSlice)) {
                continue;
            }
            $ok = jeab_kontakter_mutate(static function (array &$live) use ($cid, $beforeSlice, $afterSlice): bool {
                if (!isset($live[$cid]) || !is_array($live[$cid])) {
                    return false;
                }
                $contact = $live[$cid];
                foreach (['street', 'street_key', 'postal_code', 'city'] as $field) {
                    if (!array_key_exists($field, $beforeSlice) || !array_key_exists($field, $afterSlice)) {
                        continue;
                    }
                    if ((string) $beforeSlice[$field] === (string) $afterSlice[$field]) {
                        continue;
                    }
                    $contact[$field] = (string) $beforeSlice[$field];
                }
                if (array_key_exists('name', $beforeSlice) && (string) ($beforeSlice['name'] ?? '') !== (string) ($afterSlice['name'] ?? '')) {
                    $owners = [];
                    foreach (is_array($contact['owners'] ?? null) ? $contact['owners'] : [] as $owner) {
                        if (is_array($owner)) {
                            $owners[] = $owner;
                        }
                    }
                    $index = 0;
                    foreach ($owners as $i => $owner) {
                        if (!empty($owner['contact'])) {
                            $index = $i;
                            break;
                        }
                    }
                    if ($owners === []) {
                        $owners[] = ['name' => '', 'contact' => true, 'email' => '', 'phone' => ''];
                    }
                    $owners[$index]['name'] = (string) $beforeSlice['name'];
                    $owners[$index]['contact'] = true;
                    $contact['owners'] = $owners;
                }
                $contact['id'] = $cid;
                $contact['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
                $live[$cid] = $contact;

                return true;
            });
            if ($ok) {
                $restored++;
                if (function_exists('jeab_contacts_sole_cache_forget')) {
                    jeab_contacts_sole_cache_forget($cid);
                }
            }
        }
        $pdo = function_exists('jeab_contacts_sole_undo_pdo') ? jeab_contacts_sole_undo_pdo($store) : null;
        if ($pdo instanceof PDO) {
            $pdo->prepare('UPDATE contacts_sole_undo SET undone = 1 WHERE batch_id = ? AND kind = ?')->execute([$batch, 'name_address']);
        }
    }
    $after = jeab_name_address_census($store);

    return [
        'ok' => true,
        'dry' => $dry ? 1 : 0,
        'undo' => 1,
        'written' => !$dry && $restored > 0,
        'sent' => 0,
        'batch_id' => $batch,
        'restored' => $dry ? 0 : $restored,
        'contacts' => $report,
        'counts_same' => $before['counts'] == $after['counts'],
        'links_same' => jeab_name_address_maps_same($before['links'], $after['links']),
        'counts' => ['before' => $before['counts'], 'after' => $after['counts']],
    ];
}

function jeab_name_address_mask_pnr(string $text): string
{
    $text = preg_replace('/(?<!\d)(?:\d{6}|\d{8})[-+]?\d{4}(?!\d)/', '[pnr]', $text) ?? $text;

    return preg_replace('/\b(?:19|20)\d{6}[-+]?\d{4}\b/', '[pnr]', $text) ?? $text;
}

/**
 * @param list<array<string, string>> $sources
 * @param array{source:string,id:string,name:string,street:string,postal:string,city:string} $raw
 */
function jeab_name_address_explain_push(array &$sources, array $raw): void
{
    $norm = jeab_name_address_normalize_place($raw['street'], $raw['postal'], $raw['city']);
    $reason = [];
    if (trim($raw['street']) !== '' && $norm['street'] === '') {
        $reason[] = 'no_house_number';
    }
    if (trim($raw['city']) !== '' && $norm['city'] === '') {
        $reason[] = 'city_rejected';
    }
    if (trim($raw['name']) === '' && trim($raw['street']) === '' && trim($raw['postal']) === '' && trim($raw['city']) === '') {
        $reason[] = 'empty';
    }
    $sources[] = [
        'source' => $raw['source'],
        'id' => $raw['id'],
        'name' => jeab_name_address_mask_pnr($raw['name']),
        'street' => jeab_name_address_mask_pnr($raw['street']),
        'postal' => jeab_name_address_mask_pnr($raw['postal']),
        'city' => jeab_name_address_mask_pnr($raw['city']),
        'kept_street' => jeab_name_address_mask_pnr($norm['street']),
        'kept_postal' => jeab_name_address_mask_pnr($norm['postal']),
        'kept_city' => jeab_name_address_mask_pnr($norm['city']),
        'reason' => implode(',', $reason),
    ];
}

/**
 * @param list<array<string, string>> $sources
 * @param array<string, bool> $seen
 * @param array<string, mixed> $purchase
 */
function jeab_name_address_explain_order(array &$sources, array &$seen, array $purchase): void
{
    $rid = (int) ($purchase['reminder_id'] ?? 0);
    $oid = trim((string) ($purchase['id'] ?? ''));
    $key = $rid > 0 ? 'o:' . $rid : ($oid !== '' ? 'o:' . $oid : '');
    if ($key === '' || isset($seen[$key])) {
        return;
    }
    $seen[$key] = true;
    $record = jeab_name_address_order_record($purchase);
    jeab_name_address_explain_push($sources, [
        'source' => 'order',
        'id' => $oid !== '' ? $oid : (string) $rid,
        'name' => $record['name'],
        'street' => $record['street'],
        'postal' => $record['postal'],
        'city' => $record['city'],
    ]);
}

/**
 * @param list<string> $emails
 * @param array<string, mixed>|null $index
 * @param array<string, mixed>|null $extraReminder
 * @return list<array<string, string>>
 */
function jeab_name_address_explain_sources(string $id, array $emails, ?array $index = null, ?array $extraReminder = null): array
{
    if ($index === null) {
        $index = jeab_name_address_index();
    }
    $sources = [];
    $seen = [];
    $takeThread = static function (array $thread) use (&$sources, &$seen): void {
        $tid = trim((string) ($thread['id'] ?? ''));
        if ($tid !== '' && isset($seen['t:' . $tid])) {
            return;
        }
        if ($tid !== '') {
            $seen['t:' . $tid] = true;
        }
        $pushed = false;
        foreach (['customer', 'primaryCustomer', 'hs_customer'] as $key) {
            if (!is_array($thread[$key] ?? null)) {
                continue;
            }
            $customer = $thread[$key];
            $addr = is_array($customer['address'] ?? null) ? $customer['address'] : [];
            $lines = $addr['lines'] ?? null;
            if (is_array($lines)) {
                $street = trim(implode(' ', array_map('strval', $lines)));
            } else {
                $street = trim((string) ($lines ?? $customer['street'] ?? ''));
            }
            $name = trim((string) ($customer['name'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($customer['firstName'] ?? $customer['first'] ?? '') . ' ' . (string) ($customer['lastName'] ?? $customer['last'] ?? ''));
            }
            jeab_name_address_explain_push($sources, [
                'source' => 'helpscout',
                'id' => $tid,
                'name' => $name,
                'street' => $street,
                'postal' => (string) ($addr['postalCode'] ?? $addr['postal_code'] ?? ''),
                'city' => (string) ($addr['city'] ?? ''),
            ]);
            $pushed = true;
        }
        if ($pushed) {
            return;
        }
        $merged = jeab_name_address_merge_facts(jeab_name_address_facts_from_thread($thread));
        jeab_name_address_explain_push($sources, [
            'source' => $merged['source'] !== '' ? $merged['source'] : 'inkorg',
            'id' => $tid,
            'name' => $merged['name'],
            'street' => $merged['street'],
            'postal' => $merged['postal'],
            'city' => $merged['city'],
        ]);
    };
    if ($id !== '') {
        foreach ($index['threads_by_contact'][$id] ?? [] as $thread) {
            if (is_array($thread)) {
                $takeThread($thread);
            }
        }
    }
    foreach ($emails as $email) {
        $email = strtolower(trim($email));
        foreach ($index['threads_by_email'][$email] ?? [] as $tid) {
            $thread = $index['threads_by_id'][$tid] ?? null;
            if (is_array($thread)) {
                $takeThread($thread);
            }
        }
    }
    $pushReminder = static function (array $row) use (&$sources, &$seen, $index): void {
        $rid = (string) ($row['id'] ?? '');
        if ($rid !== '' && isset($seen['r:' . $rid])) {
            return;
        }
        if ($rid !== '') {
            $seen['r:' . $rid] = true;
        }
        $name = '';
        foreach (['customer_name', 'huvudsokande', 'name'] as $key) {
            $cand = trim((string) ($row[$key] ?? ''));
            if ($cand !== '') {
                $name = $cand;
                break;
            }
        }
        jeab_name_address_explain_push($sources, [
            'source' => 'paminnelse',
            'id' => $rid,
            'name' => $name,
            'street' => trim((string) ($row['address'] ?? $row['gata'] ?? '')),
            'postal' => (string) ($row['postal'] ?? $row['postal_code'] ?? ''),
            'city' => (string) ($row['ort'] ?? $row['city'] ?? ''),
        ]);
        $purchase = (int) $rid > 0 ? ($index['purchases_by_reminder'][(int) $rid] ?? null) : null;
        if (is_array($purchase)) {
            jeab_name_address_explain_order($sources, $seen, $purchase);
        }
    };
    $reminders = $id !== '' ? ($index['reminders_by_contact'][$id] ?? []) : [];
    if (is_array($extraReminder)) {
        $reminders[] = $extraReminder;
    }
    foreach ($reminders as $row) {
        if (is_array($row)) {
            $pushReminder($row);
        }
    }
    if ($id !== '') {
        foreach (jeab_name_address_contact_orders($id, $index) as $purchase) {
            if (!is_array($purchase)) {
                continue;
            }
            jeab_name_address_explain_order($sources, $seen, $purchase);
            $rid = (int) ($purchase['reminder_id'] ?? 0);
            $reminder = $rid > 0 ? ($index['reminders_by_id'][$rid] ?? null) : null;
            if (is_array($reminder)) {
                $pushReminder($reminder);
            }
        }
    }

    return $sources;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_name_address_explain(array $input, ?JeabStore $store = null): array
{
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    $contactId = trim((string) ($input['contact_id'] ?? ''));
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $reminderId = (int) ($input['reminder_id'] ?? 0);
    $reminder = null;
    if ($reminderId > 0 && $store instanceof JeabStore) {
        $reminder = $store->getReminder($reminderId);
        if (is_array($reminder)) {
            if ($contactId === '') {
                $contactId = trim((string) ($reminder['contact_id'] ?? ''));
            }
            if ($email === '') {
                $email = strtolower(trim((string) ($reminder['email'] ?? '')));
            }
        }
    }
    $contact = ($contactId !== '' && function_exists('jeab_kontakt_get')) ? jeab_kontakt_get($contactId) : null;
    $matches = 0;
    if (!is_array($contact) && $email !== '' && function_exists('jeab_kontakter_load')) {
        foreach (jeab_kontakter_load() as $id => $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowEmails = function_exists('jeab_contacts_sole_contact_emails') ? jeab_contacts_sole_contact_emails($row) : [];
            foreach ($rowEmails as $cand) {
                if (strtolower(trim((string) $cand)) !== $email) {
                    continue;
                }
                $matches++;
                if (!is_array($contact)) {
                    $contact = $row;
                    $contactId = trim((string) ($row['id'] ?? $id));
                }
            }
        }
    }
    if (!is_array($contact) && !is_array($reminder)) {
        return ['ok' => false, 'error' => 'Kontakten hittades inte.', 'status' => 404, 'sent' => 0];
    }
    $emails = is_array($contact) && function_exists('jeab_contacts_sole_contact_emails')
        ? jeab_contacts_sole_contact_emails($contact)
        : [];
    if ($email !== '') {
        $emails[] = $email;
    }
    $storedName = is_array($contact) && function_exists('jeab_kontakt_row_name') ? jeab_kontakt_row_name($contact) : '';
    $storedStreet = is_array($contact) ? trim((string) ($contact['street'] ?? '')) : '';
    $storedCity = is_array($contact) ? trim((string) ($contact['city'] ?? '')) : '';
    $storedPostal = is_array($contact) ? trim((string) ($contact['postal_code'] ?? '')) : '';
    $streetKind = jeab_name_address_street_kind($storedStreet);
    $cityKind = jeab_name_address_city_kind($storedCity);
    $test = jeab_name_address_test_name($storedName);
    $index = jeab_name_address_index($store instanceof JeabStore ? $store : null);
    $sources = jeab_name_address_explain_sources($contactId, $emails, $index, is_array($reminder) ? $reminder : null);
    $plan = is_array($contact) ? jeab_name_address_plan_contact($contactId, $contact, $index) : null;
    $why = [];
    if (!is_array($contact)) {
        $why[] = 'Ingen kontakt är kopplad.';
    }
    if ($test) {
        $why[] = 'Testkontakt hoppas över tills id anges i contact_id eller contact_ids.';
    }
    if ($matches > 1) {
        $why[] = 'Flera kontakter har samma e-post. Första träffen visas.';
    }
    foreach ($sources as $source) {
        if (($source['kept_street'] ?? '') !== '' && in_array($streetKind, ['unknown', 'real'], true) && $source['kept_street'] !== $storedStreet) {
            $why[] = 'Gata från ' . $source['source'] . ' ' . $source['id'] . ' skrevs inte över. Sparad gata är ' . $streetKind . '.';
        }
        if (($source['reason'] ?? '') !== '' && ($source['reason'] ?? '') !== 'empty') {
            $why[] = $source['source'] . ' ' . $source['id'] . ': ' . $source['reason'] . '.';
        }
    }
    if ($plan === null && $why === []) {
        $why[] = 'Inget fält kunde fyllas.';
    }
    $why = array_slice($why, 0, 20);
    $proposed = is_array($plan) && is_array($plan['proposed'] ?? null) ? $plan['proposed'] : [];

    return [
        'ok' => true,
        'sent' => 0,
        'contact_id' => $contactId,
        'reminder_id' => $reminderId,
        'matches' => $matches,
        'skipped' => $test ? 'test' : '',
        'stored' => [
            'name_kind' => jeab_name_address_field_gap('name', $storedName, $emails) === '' ? 'real' : jeab_name_address_field_gap('name', $storedName, $emails),
            'street_kind' => $streetKind,
            'city_kind' => $cityKind,
            'postal' => $storedPostal === '' ? 'empty' : 'set',
        ],
        'sources' => $sources,
        'plan' => $plan === null ? null : [
            'source' => (string) ($plan['source'] ?? ''),
            'changes' => is_array($plan['changes'] ?? null) ? $plan['changes'] : [],
            'name' => jeab_name_address_mask_pnr((string) ($proposed['name'] ?? '')),
            'street' => jeab_name_address_mask_pnr((string) ($proposed['street'] ?? '')),
            'postal_code' => jeab_name_address_mask_pnr((string) ($proposed['postal_code'] ?? '')),
            'city' => jeab_name_address_mask_pnr((string) ($proposed['city'] ?? '')),
        ],
        'why' => $why,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_name_address_explain_dispatch(array $input): array
{
    return jeab_name_address_guard(static function () use ($input): array {
        if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
            return ['ok' => false, 'error' => 'Fel token.', 'status' => 401, 'sent' => 0];
        }
        $result = jeab_kontakter_name_address_explain($input);
        $result['status'] = !empty($result['ok']) ? 200 : (int) ($result['status'] ?? 400);
        $result['sent'] = 0;

        return $result;
    });
}

/**
 * Söker kontakter på namn. Minst tre tecken. Högst tjugo träffar.
 * Svaret har id, namn, e-post, gata och ort. Inget personnummer.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_find(array $input): array
{
    $name = trim((string) ($input['name'] ?? $input['q'] ?? ''));
    $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
    if ($length < 3) {
        return ['ok' => false, 'error' => 'Namnet behöver minst tre tecken.', 'status' => 400, 'sent' => 0, 'contacts' => []];
    }
    $needle = function_exists('jeab_kontakt_fold_text')
        ? jeab_kontakt_fold_text($name)
        : (function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name));
    $hits = [];
    $rows = function_exists('jeab_kontakter_load') ? jeab_kontakter_load() : [];
    foreach ($rows as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        $rowName = function_exists('jeab_kontakt_row_name') ? jeab_kontakt_row_name($row) : trim((string) ($row['name'] ?? ''));
        $fold = function_exists('jeab_kontakt_fold_text')
            ? jeab_kontakt_fold_text($rowName)
            : (function_exists('mb_strtolower') ? mb_strtolower($rowName, 'UTF-8') : strtolower($rowName));
        if ($fold === '' || !str_contains($fold, $needle)) {
            continue;
        }
        $email = function_exists('jeab_kontakt_row_email') ? jeab_kontakt_row_email($row) : '';
        $hits[] = [
            'id' => trim((string) ($row['id'] ?? $id)),
            'name' => jeab_name_address_mask_pnr($rowName),
            'email' => jeab_name_address_mask_pnr($email),
            'street' => jeab_name_address_mask_pnr(trim((string) ($row['street'] ?? ''))),
            'city' => jeab_name_address_mask_pnr(trim((string) ($row['city'] ?? ''))),
            'sort' => $fold,
        ];
    }
    usort($hits, static function (array $a, array $b): int {
        $byName = strcmp((string) $a['sort'], (string) $b['sort']);
        if ($byName !== 0) {
            return $byName;
        }

        return strcmp((string) $a['id'], (string) $b['id']);
    });
    $contacts = [];
    foreach (array_slice($hits, 0, 20) as $hit) {
        unset($hit['sort']);
        $contacts[] = $hit;
    }

    return ['ok' => true, 'sent' => 0, 'contacts' => $contacts];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_find_dispatch(array $input): array
{
    return jeab_name_address_guard(static function () use ($input): array {
        if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
            return ['ok' => false, 'error' => 'Fel token.', 'status' => 401, 'sent' => 0, 'contacts' => []];
        }
        $result = jeab_kontakter_find($input);
        $result['status'] = !empty($result['ok']) ? 200 : (int) ($result['status'] ?? 400);
        $result['sent'] = 0;

        return $result;
    });
}

/**
 * Ren rad behöver inte normalize_place. «och» först, ort som bara är siffror
 * eller komma, och postnummer som redan står i gata eller ort är orena.
 */
function jeab_kontakt_display_place_clean(string $street, string $postal, string $city): bool
{
    if ($street !== '') {
        if (strpbrk($street, ",([@") !== false
            || str_contains($street, '  ')
            || str_contains($street, "\t")
            || str_contains($street, "\n")
            || str_contains($street, "\r")
            || str_contains($street, "\u{00A0}")) {
            return false;
        }
        if (trim($street, ".,;:-–—") !== $street) {
            return false;
        }
        if (preg_match('/^och\b|\p{L}\d|(?<!\d)\d{3}\s?\d{2}(?!\d)|\b\d{1,2}\s*tr(?:appa)?\b|\btr(?:appa)?\s*\d{1,2}\b|\d\s+\S/iu', $street) === 1) {
            return false;
        }
    }
    if ($city !== '') {
        if (strpbrk($city, '([') !== false || preg_match('/^[\d\s,;.]+$/u', $city) === 1) {
            return false;
        }
        if (preg_match('/(?<!\d)\d{3}\s?\d{2}(?!\d)/', $city) === 1) {
            return false;
        }
    }
    $norm = '';
    if ($postal !== '') {
        $norm = function_exists('jeab_kontakt_postal') ? jeab_kontakt_postal($postal) : (preg_replace('/\D+/', '', $postal) ?? '');
        if (strlen($norm) === 5) {
            $spaced = substr($norm, 0, 3) . ' ' . substr($norm, 3);
            if (($street !== '' && (str_contains($street, $norm) || str_contains($street, $spaced)))
                || ($city !== '' && (str_contains($city, $norm) || str_contains($city, $spaced)))) {
                return false;
            }
        }
    }
    if ($city === '' || $norm === '' || jeab_name_address_postal_city($norm) === '') {
        return true;
    }
    static $skip = null;
    if ($skip === null) {
        $skip = array_fill_keys([
            'the netherlands', 'netherlands', 'nederland', 'nederlanderna', 'holland',
            'sverige', 'sweden', 'norge', 'norway', 'danmark', 'denmark',
            'finland', 'tyskland', 'germany', 'belgien', 'belgium',
            'frankrike', 'france', 'spanien', 'spain', 'italien', 'italy',
            'polen', 'poland', 'england', 'storbritannien', 'united kingdom',
            'uk', 'usa', 'united states', 'skane', 'skåne',
            'test', 'testing', 'tester',
            'och ort', 'och adress', 'och address', 'och gata',
            'adress och ort', 'ort och adress', 'adress/ort', 'ort/adress',
            'adress', 'address', 'gata', 'gatuadress', 'postadress', 'street',
            'ort', 'postort', 'stad', 'city', 'kommun',
        ], true);
    }
    $lower = function_exists('mb_strtolower') ? mb_strtolower($city, 'UTF-8') : strtolower($city);
    if (isset($skip[$lower]) || preg_match('/[aeiouyåäöé]/iu', $city) !== 1) {
        return false;
    }

    return true;
}

/**
 * Gata och ort när normalize_place inte skulle skriva om raden.
 * Tom ort fylls från det uppslag som redan finns, annars postnumret.
 *
 * @return array{street:string,city:string}
 */
function jeab_kontakt_display_place_simple(string $street, string $postal, string $city): array
{
    if ($city !== '' && function_exists('jeab_kontakt_city_name')) {
        $city = jeab_kontakt_city_name($city);
    }
    if ($city === '') {
        $norm = function_exists('jeab_kontakt_postal') ? jeab_kontakt_postal($postal) : '';
        $digits = preg_replace('/\D+/', '', $norm !== '' ? $norm : $postal) ?? '';
        if (strlen($digits) === 5) {
            $fromPostal = jeab_name_address_postal_city($digits);
            if ($fromPostal !== '') {
                $city = function_exists('jeab_kontakt_city_name') ? jeab_kontakt_city_name($fromPostal) : $fromPostal;
            } else {
                $city = substr($digits, 0, 3) . ' ' . substr($digits, 3);
            }
        }
    }

    return ['street' => $street, 'city' => $city];
}

/**
 * Gata och ort som visas i Kontakter. Rättar visningen av trasiga rader
 * (dubbla postnummer, «och,» först) utan att skriva i raden. Saknas ort
 * visas postnumret. Orten gissas inte. Rena rader räknas utan normalize_place.
 *
 * @return array{street:string,city:string}
 */
function jeab_kontakt_display_place(array $row): array
{
    $rawStreet = trim((string) ($row['street'] ?? ''));
    $postal = trim((string) ($row['postal_code'] ?? ''));
    $rawCity = trim((string) ($row['city'] ?? ''));
    if (jeab_kontakt_display_place_clean($rawStreet, $postal, $rawCity)) {
        return jeab_kontakt_display_place_simple($rawStreet, $postal, $rawCity);
    }

    $place = jeab_name_address_normalize_place($rawStreet, $postal, $rawCity);
    $street = $place['street'] !== '' ? $place['street'] : jeab_name_address_prepare_street($rawStreet);
    $city = $place['city'];
    if ($city === '' && $rawCity !== '' && preg_match('/^[\d\s,;.]+$/u', $rawCity) !== 1) {
        $city = $rawCity;
    }

    if ($city !== '' && function_exists('jeab_kontakt_city_name')) {
        $city = jeab_kontakt_city_name($city);
    }
    if ($city === '') {
        $digits = preg_replace('/\D+/', '', $place['postal'] !== '' ? $place['postal'] : $postal) ?? '';
        if (strlen($digits) === 5) {
            $city = substr($digits, 0, 3) . ' ' . substr($digits, 3);
        }
    }

    return ['street' => $street, 'city' => $city];
}
