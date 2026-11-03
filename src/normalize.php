<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Gemensam normalisering av telefon, adressnyckel, personnummer och e-post.
 * Ett fält, en funktion. Jämförelse och lagring ska gå via de här formerna.
 *
 * Personnummer loggas aldrig i klartext. Använd jeab_mask_pnr().
 */

/**
 * Gemener och diakriter på ett sätt. å/ä → a, ö → o, övriga accenter tas bort.
 * Används av adressnyckeln och av de äldre vikt-hjälparna.
 */
function jeab_norm_fold(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    if (class_exists('Normalizer')) {
        $decomposed = Normalizer::normalize($value, Normalizer::FORM_D);
        if (is_string($decomposed)) {
            $stripped = preg_replace('/\p{M}+/u', '', $decomposed);
            if (is_string($stripped)) {
                $value = $stripped;
            }
        }
    }
    $value = strtr($value, [
        'å' => 'a', 'ä' => 'a', 'ö' => 'o', 'ø' => 'o', 'æ' => 'ae',
        'é' => 'e', 'ü' => 'u', 'á' => 'a', 'à' => 'a', 'è' => 'e',
    ]);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value);
}

/** Städning av id-nummer: blanksteg borta, alla streckvarianter blir '-'. */
function jeab_norm_id_clean(string $raw): string
{
    $s = preg_replace('/\p{Pd}/u', '-', $raw) ?? $raw;
    $s = str_replace(["\u{2212}", "\u{00AD}", "\u{FE63}"], '-', $s);
    $s = preg_replace('/[\s\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}\x{FEFF}]+/u', '', $s) ?? $s;
    return preg_replace('/-{2,}/', '-', $s) ?? $s;
}

/**
 * Svenskt nummer till +46 och 7–10 siffror, t.ex. +46701234567.
 * 07x, 0046, +46, mellanslag och bindestreck. Ett extra 0 efter landskoden tas bort.
 * Ogiltigt eller utländskt nummer ger tom sträng.
 */
function jeab_norm_phone(string $s): string
{
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    $d = preg_replace('/[^\d+]/', '', $s) ?? '';
    if ($d === '') {
        return '';
    }
    if (str_starts_with($d, '0046')) {
        $d = '+46' . substr($d, 4);
    } elseif (str_starts_with($d, '+46')) {
        // redan landskod
    } elseif (str_starts_with($d, '46') && strlen($d) >= 9 && strlen($d) <= 12) {
        $d = '+' . $d;
    } elseif (str_starts_with($d, '0')) {
        $d = '+46' . substr($d, 1);
    }
    if (str_starts_with($d, '+460')) {
        $d = '+46' . substr($d, 4);
    }
    return preg_match('/^\+46\d{7,10}$/', $d) === 1 ? $d : '';
}

/**
 * Svenskt nummer till E.164. Bygger på jeab_norm_phone.
 * 0701234567, 070-123 45 67, +46701234567 och 0046701234567 blir +46701234567.
 * Ogiltigt ger tom sträng.
 */
function jeab_norm_phone_e164(string $s): string
{
    return jeab_norm_phone($s);
}

/** Mellanslag och streck som syns i mejl blir vanliga tecken. Siffrorna rörs inte. */
function jeab_phone_ascii(string $raw): string
{
    $raw = str_replace(["\u{00A0}", "\u{202F}", "\u{2007}", "\u{2009}", "\u{200A}", "\u{FEFF}"], ' ', $raw);
    $raw = str_replace(["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2212}", "\u{00AD}"], '-', $raw);

    return $raw;
}

/**
 * Första svenska numret i texten: 0…, +46 eller 0046, med mellanslag, streck, snedstreck och parentes.
 * Mobil (+467 och åtta siffror) vinner över ett riktnummer i samma text. Ogiltigt ger tomt.
 */
function jeab_phone_swedish_span(string $raw): string
{
    $raw = jeab_phone_ascii($raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match_all('/(?<!\d)(?:\+\s*46|00\s*46|0)(?:[\s\-\/\(\)]*\d){7,12}(?!\d)/u', $raw, $all) < 1) {
        return '';
    }
    $fallback = '';
    foreach ($all[0] as $hit) {
        $hit = trim((string) $hit);
        $norm = jeab_norm_phone($hit);
        if ($norm === '') {
            continue;
        }
        if (preg_match('/^\+467\d{8}$/', $norm) === 1) {
            return $hit;
        }
        if ($fallback === '') {
            $fallback = $hit;
        }
    }

    return $fallback;
}

/**
 * Adressnyckel. Samma nyckel betyder samma kontakt.
 * Gatuordet skrivs ihop (mellanslag och bindestreck i namnet ignoreras),
 * husnummer och bokstav behålls (43A → 43a), lägenhetsnummer behålls.
 * Postnummer ingår när det finns, annars orten. Ingen gata med nummer → tomt.
 * «vägen»/«v.» förkortas inte: det görs inte någon annanstans.
 */
function jeab_norm_street_key(string $street, string $postcode = '', string $town = ''): string
{
    $text = jeab_norm_fold($street);
    $text = str_replace([',', ';'], ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    if (preg_match('/^(.+?)\s+(\d+)\s*([a-z])?(.*)$/u', $text, $m) !== 1) {
        return '';
    }
    $name = preg_replace('/[^a-z0-9]+/u', '', (string) $m[1]) ?? '';
    if ($name === '') {
        return '';
    }
    $tail = trim((string) ($m[4] ?? ''));
    $flat = '';
    if ($tail !== '') {
        if (preg_match('/^(?:lgh|lagenhet)\b(.*)$/u', $tail, $fm) === 1) {
            $rest = trim((string) $fm[1], " .");
            if (preg_match('/^(?:nr\s*)?(\d+[a-z]?)/u', $rest, $nm) === 1) {
                $flat = $nm[1];
            }
        } elseif (preg_match('/^(?:tr|nb|bv|van)/u', $tail) !== 1) {
            return '';
        }
    }
    $part = $name . ' ' . $m[2] . ($m[3] ?? '');
    if ($flat !== '') {
        $part .= ' lgh ' . $flat;
    }
    $postal = jeab_norm_postal($postcode);
    if ($postal !== '') {
        return $part . '|' . $postal;
    }
    $place = jeab_norm_place($town);
    return $place !== '' ? $part . '|' . $place : $part;
}

/** Fem siffror, eller tomt. */
function jeab_norm_postal(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/(?<!\d)(\d{3})\s?(\d{2})(?!\d)/', $raw, $m) === 1) {
        return $m[1] . $m[2];
    }
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    return strlen($digits) === 5 ? $digits : '';
}

function jeab_norm_place(string $town): string
{
    $place = jeab_norm_fold($town);
    $place = preg_replace('/(?:\s*,\s*|\s+)(?:kommun|sverige|sweden)$/u', '', $place) ?? $place;
    return trim($place);
}

/**
 * En ensam bokstav direkt efter husnumret skrivs ihop och blir versal.
 * «38 A», «38 a» och «38a» blir «38A». Två eller fler bokstäver lämnas,
 * så «38 BRO» är oförändrat. «12 B lgh 1101» blir «12B lgh 1101».
 * Övriga mellanslag rörs inte.
 */
function jeab_norm_street_letter(string $street): string
{
    if ($street === '') {
        return '';
    }
    $out = preg_replace_callback(
        '/(\d+)\s*(\p{L})(?!\p{L})/u',
        static function (array $m): string {
            $letter = (string) $m[2];
            $upper = function_exists('mb_strtoupper') ? mb_strtoupper($letter, 'UTF-8') : strtoupper($letter);

            return $m[1] . $upper;
        },
        $street
    );

    return is_string($out) ? $out : $street;
}

/** Gemener, trim, hopslagna mellanslag. Bokstavsregeln bara på gata. */
function jeab_contact_address_part(string $value, bool $street): string
{
    if ($street) {
        $value = jeab_norm_street_letter($value);
    }
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

    return trim($value);
}

/**
 * Kontaktnyckel: gata + ort. Tom om något av dem saknas.
 * Samma gata och samma ort är samma kontakt. Samma gata och annan ort är en annan.
 * Ingen å/ä/ö-vikning och ingen borttagning av «kommun».
 */
function jeab_contact_address_key(string $street, string $city): string
{
    $street = jeab_contact_address_part($street, true);
    $city = jeab_contact_address_part($city, false);
    if ($street === '' || $city === '') {
        return '';
    }

    return $street . '|' . $city;
}

/**
 * Stödnyckel gata + postnummer utan mellanslag. Tom om något saknas.
 * Används bara när ort saknas, inte som primär kontaktnyckel.
 */
function jeab_contact_address_postal_key(string $street, string $postal): string
{
    $street = jeab_contact_address_part($street, true);
    $postal = function_exists('jeab_norm_postal') ? jeab_norm_postal($postal) : '';
    if ($street === '' || $postal === '') {
        return '';
    }

    return $street . '|' . $postal;
}

/**
 * Sätter gatans sparade form och räknar om street_key när texten ändras.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_contact_apply_street_letter(array $row): array
{
    if (!isset($row['street']) || !is_string($row['street'])) {
        return $row;
    }
    $next = jeab_norm_street_letter($row['street']);
    if ($next === $row['street']) {
        return $row;
    }
    $row['street'] = $next;
    if (function_exists('jeab_kontakt_street_key')) {
        $row['street_key'] = jeab_kontakt_street_key($next);
    }

    return $row;
}

/**
 * 12 siffror ÅÅÅÅMMDDNNNN utan bindestreck, med sekel och Luhn.
 * Samordningsnummer (dag + 60) godkänns. Ogiltigt ger tom sträng.
 * Logga inte returvärdet. Använd jeab_mask_pnr().
 */
function jeab_norm_pnr(string $s): string
{
    $digits = jeab_norm_pnr_digits($s);
    if (strlen($digits) !== 12) {
        return '';
    }
    $year = (int) substr($digits, 0, 4);
    $month = (int) substr($digits, 4, 2);
    $day = (int) substr($digits, 6, 2);
    if ($month < 1 || $month > 12) {
        return '';
    }
    $realDay = $day > 60 ? $day - 60 : $day;
    if ($realDay < 1 || !checkdate($month, $realDay, $year)) {
        return '';
    }
    if (!jeab_norm_pnr_luhn(substr($digits, 2))) {
        return '';
    }
    return $digits;
}

/**
 * 12 siffror med sekel, utan datum- eller Luhn-kontroll.
 * Fortnox och objektformuläret behöver formen även när kontrollsiffran inte stämmer.
 */
function jeab_norm_pnr_digits(string $raw): string
{
    $clean = function_exists('jeab_idnr_clean') ? jeab_idnr_clean($raw) : jeab_norm_id_clean($raw);
    $digits = preg_replace('/\D+/', '', $clean) ?? '';
    if (strlen($digits) === 10) {
        $yy = (int) substr($digits, 0, 2);
        $nowYy = (int) (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('y');
        $century = $yy > $nowYy ? 19 : 20;
        if (str_contains($clean, '+')) {
            $century--;
        }
        $digits = $century . $digits;
    }
    return strlen($digits) === 12 ? $digits : '';
}

function jeab_norm_pnr_luhn(string $ten): bool
{
    if (strlen($ten) !== 10 || preg_match('/^\d{10}$/', $ten) !== 1) {
        return false;
    }
    $sum = 0;
    for ($i = 0; $i < 10; $i++) {
        $n = (int) $ten[$i];
        if ($i % 2 === 0) {
            $n *= 2;
            if ($n > 9) {
                $n -= 9;
            }
        }
        $sum += $n;
    }
    return $sum % 10 === 0;
}

/**
 * Visning och logg: bara de fyra sista siffrorna. Färre än fyra siffror ger tomt.
 * Skriv aldrig hela personnumret i logg, feltext eller hook-svar.
 */
function jeab_mask_pnr(string $s): string
{
    $digits = preg_replace('/\D+/', '', $s) ?? '';
    if (strlen($digits) < 4) {
        return '';
    }
    return '••••••••-' . substr($digits, -4);
}

/** Trim och gemener. Tom in blir tom ut. Kräver inte @. */
function jeab_norm_email(string $s): string
{
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

/**
 * Gatunummer som klistrats ihop med gatan, och samma nummer en gång till.
 * «Riksdalervägen3 3» blir «Riksdalervägen 3». Postnummer på fem siffror rörs inte.
 * åäö behålls. Används vid inläsning och vid visning, utan att skriva om lagrad data.
 */
function jeab_address_repair_number(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    $stuck = preg_replace('/(\p{L})(\d{1,4}\p{L}?)(\s+\2)\b/u', '$1 $2', $text);
    if (is_string($stuck)) {
        $text = $stuck;
    }
    $split = preg_replace('/(\p{L})(\d{1,4}\p{L}?)(?!\d)/u', '$1 $2', $text);
    return is_string($split) ? $split : $text;
}

/**
 * Ledande bindeord och fyllnadsord som fastnat framför gatan («och, Dimvägen 38»).
 * Bara vid inläsning av en ny adress. Lagrade rader rörs inte.
 * «Allégatan», «Sommarvägen» och «All 12» lämnas, eftersom ordet där är gatan.
 */
function jeab_address_strip_leading_filler(string $raw): string
{
    $raw = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
    if ($raw === '') {
        return '';
    }
    $words = 'och|eller|and|samt|men|att|som|med|all|allt|alla|the|plus|ja|nej';
    $wordRe = '/^(?:' . $words . ')\b\s*[,;]?\s*/iu';
    $punctRe = '/^[,;.\s]+/u';
    $prev = null;
    while ($raw !== '' && $raw !== $prev) {
        $prev = $raw;
        $next = preg_replace($wordRe, '', $raw, 1);
        $strippedWord = is_string($next) && $next !== $raw;
        if (!$strippedWord) {
            $next = preg_replace($punctRe, '', $raw);
        }
        if (!is_string($next) || $next === $raw) {
            break;
        }
        $next = trim($next);
        if ($strippedWord && $next !== '' && preg_match('/\p{L}/u', $next) !== 1) {
            return $raw;
        }
        $raw = $next;
    }

    return $raw;
}
