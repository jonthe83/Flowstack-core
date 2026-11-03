<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Dubblettregler för kontakter.
 * Samma normaliserade adress (gata, nummer, ort) eller samma e-post blir en kontakt.
 * Osäkra fall listas och slås inte ihop. Personnummer skrivs inte i loggen.
 * Sammanslagning och import anropar inte Fortnox, skriver inte under /rot/
 * och skickar inte mejl, SMS, offert eller auto_quote.
 */

function jeab_kontakt_quiet(): bool
{
    return (int) ($GLOBALS['_JEAB_KONTAKT_QUIET'] ?? 0) > 0;
}

function jeab_kontakt_quiet_enter(): void
{
    $GLOBALS['_JEAB_KONTAKT_QUIET'] = (int) ($GLOBALS['_JEAB_KONTAKT_QUIET'] ?? 0) + 1;
}

function jeab_kontakt_quiet_leave(): void
{
    $n = (int) ($GLOBALS['_JEAB_KONTAKT_QUIET'] ?? 0) - 1;
    if ($n <= 0) {
        unset($GLOBALS['_JEAB_KONTAKT_QUIET']);
        return;
    }
    $GLOBALS['_JEAB_KONTAKT_QUIET'] = $n;
}

function jeab_kontakt_quiet_call(callable $fn): mixed
{
    jeab_kontakt_quiet_enter();
    try {
        return $fn();
    } finally {
        jeab_kontakt_quiet_leave();
    }
}

function jeab_kontakt_side_effect_note(string $kind): void
{
    $bag = $GLOBALS['_JEAB_KONTAKT_SIDE_EFFECTS'] ?? [];
    if (!is_array($bag)) {
        $bag = [];
    }
    $bag[] = $kind;
    $GLOBALS['_JEAB_KONTAKT_SIDE_EFFECTS'] = $bag;
}

/** @return array<string, string> */
function jeab_kontakt_external_ref_keys(): array
{
    return [
        'fortnox_customer' => 'Fortnox-kund',
        'fortnox_customer_number' => 'Fortnox-kund',
        'fortnox_kundnummer' => 'Fortnox-kund',
        'CustomerNumber' => 'Fortnox-kund',
        'rot_link' => 'ROT',
        'rot_url' => 'ROT',
        'rot_ansokan' => 'ROT',
        'rot_application' => 'ROT',
    ];
}

/**
 * Text till Övrigt. Själva Fortnox-kunden och ROT-ärendet lämnas orörda.
 *
 * @param array<string, mixed> $row
 * @return list<string>
 */
function jeab_kontakt_external_ref_labels(array $row): array
{
    $out = [];
    $seen = [];
    foreach (jeab_kontakt_external_ref_keys() as $key => $label) {
        if (!array_key_exists($key, $row) || is_array($row[$key])) {
            continue;
        }
        $value = trim((string) $row[$key]);
        if ($value === '') {
            continue;
        }
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) >= 10 && preg_match('/\d{6}-?\d{4}/', $value) === 1) {
            continue;
        }
        $text = $label . ' ' . $value;
        if (isset($seen[$text])) {
            continue;
        }
        $seen[$text] = true;
        $out[] = $text;
    }
    return $out;
}

/**
 * Kopiera inte Fortnox- eller ROT-pekare från kontakten som tas bort.
 *
 * @param array<string, mixed> $keep
 * @param array<string, mixed> $before
 * @return array<string, mixed>
 */
function jeab_kontakt_preserve_external_refs(array $keep, array $before): array
{
    foreach (array_keys(jeab_kontakt_external_ref_keys()) as $key) {
        if (array_key_exists($key, $before)) {
            $keep[$key] = $before[$key];
        } else {
            unset($keep[$key]);
        }
    }
    return $keep;
}

/** Postort som inte heter som kommunen. Nyckel är vikt text. */
function jeab_kontakt_postal_towns(): array
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
        $key = jeab_kontakt_city_fold($town);
        if ($key !== '') {
            $map[$key] = $municipality;
        }
    }
    if (function_exists('jeab_config')) {
        $extra = jeab_config()['kontakter']['postal_towns'] ?? [];
        if (is_array($extra)) {
            foreach ($extra as $town => $municipality) {
                $key = jeab_kontakt_city_fold((string) $town);
                $municipality = trim((string) $municipality);
                if ($key !== '' && $municipality !== '') {
                    $map[$key] = $municipality;
                }
            }
        }
    }
    return $map;
}

function jeab_kontakt_city_fold(string $city): string
{
    $city = jeab_kontakt_city_name($city);
    $fold = jeab_kontakt_fold_text($city);
    $fold = str_replace(['-', '–', '—'], ' ', $fold);
    $fold = preg_replace('/\s+/u', ' ', trim($fold)) ?? trim($fold);
    return $fold;
}

/** Kommunnamn. Postort och «kommun» blir samma nyckel. Tom ort ger tom nyckel. */
function jeab_kontakt_city_key(string $city): string
{
    $fold = jeab_kontakt_city_fold($city);
    if ($fold === '') {
        return '';
    }
    $canon = jeab_kontakt_place_canon(str_replace('-', ' ', jeab_kontakt_city_name($city)));
    if ($canon === '') {
        $spaced = str_replace('-', ' ', jeab_kontakt_city_name($city));
        $canon = jeab_kontakt_place_canon($spaced);
    }
    $base = $canon !== '' ? $canon : jeab_kontakt_city_name($city);
    $key = jeab_kontakt_city_fold($base);
    $towns = jeab_kontakt_postal_towns();
    if (isset($towns[$key])) {
        $key = jeab_kontakt_city_fold($towns[$key]);
    } elseif (isset($towns[$fold])) {
        $key = jeab_kontakt_city_fold($towns[$fold]);
    }
    return $key;
}

/**
 * Gata, husnummer, bokstav och lägenhet.
 * «väg»/«vägen», «gata»/«gatan», förkortningar, bindestreck och «12A»/«12 a» delar nyckel.
 *
 * @return array{street_key:string,number:string,letter:string,apartment:string}
 */
function jeab_kontakt_address_parts(string $street): array
{
    $empty = ['street_key' => '', 'number' => '', 'letter' => '', 'apartment' => ''];
    $text = jeab_kontakt_fold_text($street);
    if ($text === '') {
        return $empty;
    }
    $text = preg_replace('/([a-z])v\./u', '$1 vagen ', $text) ?? $text;
    $text = preg_replace('/([a-z])g\./u', '$1 gata ', $text) ?? $text;
    $text = str_replace([',', ';', '.', '–', '—'], ' ', $text);
    $text = str_replace('-', ' ', $text);
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    $apartment = '';
    if (preg_match('/(?:^|\s)(?:lgh|lagenhet)\s*(\d+\s*[a-z]?)\b/u', $text, $m) === 1) {
        $apartment = preg_replace('/\s+/u', '', (string) $m[1]) ?? (string) $m[1];
        $text = trim(str_replace($m[0], ' ', $text));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    }
    if (preg_match('/^(.+?)\s+(\d+)\s*([a-z])?$/u', $text, $m) !== 1) {
        return $empty;
    }
    $tokens = preg_split('/\s+/u', trim((string) $m[1])) ?: [];
    $tokens = array_values(array_filter($tokens, static fn(string $token): bool => $token !== ''));
    if ($tokens === []) {
        return $empty;
    }
    $last = (string) array_pop($tokens);
    $type = '';
    if (in_array($last, ['g', 'gt', 'gata', 'gatan'], true)) {
        $type = 'gata';
    } elseif (in_array($last, ['v', 'vg', 'vag', 'vagen'], true)) {
        $type = 'vag';
    } elseif (str_ends_with($last, 'gatan') && strlen($last) > 5) {
        $type = 'gata';
        $tokens[] = substr($last, 0, -5);
    } elseif (str_ends_with($last, 'gata') && strlen($last) > 4) {
        $type = 'gata';
        $tokens[] = substr($last, 0, -4);
    } elseif (str_ends_with($last, 'vagen') && strlen($last) > 5) {
        $type = 'vag';
        $tokens[] = substr($last, 0, -5);
    } elseif (str_ends_with($last, 'vag') && strlen($last) > 3) {
        $type = 'vag';
        $tokens[] = substr($last, 0, -3);
    } else {
        $tokens[] = $last;
    }
    $stem = '';
    foreach ($tokens as $token) {
        $stem .= preg_replace('/[^a-z0-9]+/u', '', $token) ?? '';
    }
    if ($stem === '') {
        return $empty;
    }
    $letter = (string) ($m[3] ?? '');
    return [
        'street_key' => $stem . $type . ' ' . $m[2] . $letter,
        'number' => (string) $m[2],
        'letter' => $letter,
        'apartment' => $apartment,
    ];
}

/**
 * @return array{street_key:string,number:string,letter:string,apartment:string,city:string,key:string}
 */
function jeab_kontakt_address_identity(string $street, string $city): array
{
    $parts = jeab_kontakt_address_parts($street);
    $cityKey = jeab_kontakt_city_key($city);
    $key = ($parts['street_key'] !== '' && $cityKey !== '') ? $parts['street_key'] . '|' . $cityKey : '';
    return $parts + ['city' => $cityKey, 'key' => $key];
}

/** @param array<string, mixed> $row */
function jeab_kontakt_row_identity(array $row): array
{
    return jeab_kontakt_address_identity((string) ($row['street'] ?? ''), (string) ($row['city'] ?? ''));
}

/**
 * Gata och postnummer. Tom när endera saknas.
 * Väg/vägen och 12A/12 a delar nyckel. Ort ingår inte.
 *
 * @param array<string, mixed> $row
 */
function jeab_kontakt_channel_address_key(array $row): string
{
    $street = trim((string) ($row['street'] ?? ''));
    $stored = trim((string) ($row['street_key'] ?? ''));
    $streetKey = '';
    if ($street !== '') {
        $streetKey = (string) (jeab_kontakt_address_parts($street)['street_key'] ?? '');
    }
    if ($streetKey === '' && $stored !== '') {
        $streetKey = (string) (jeab_kontakt_address_parts($stored)['street_key'] ?? '');
    }
    if ($streetKey === '') {
        $streetKey = jeab_kontakt_effective_street_key($street, $stored);
    }
    $postal = function_exists('jeab_kontakt_postal') ? jeab_kontakt_postal((string) ($row['postal_code'] ?? '')) : '';
    if ($streetKey === '' || $postal === '') {
        return '';
    }

    return $streetKey . '|' . $postal;
}

/**
 * Tom sträng när paret får slås ihop.
 * Bara när båda har gata och postnummer och de skiljer sig. Saknas adressen på ena sidan är det ingen konflikt.
 *
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 */
function jeab_kontakt_channel_address_block(array $a, array $b, string $rule): string
{
    unset($rule);
    $left = jeab_kontakt_channel_address_key($a);
    $right = jeab_kontakt_channel_address_key($b);
    if ($left !== '' && $right !== '' && $left !== $right) {
        return 'olika adress';
    }

    return '';
}

/**
 * Håller felloggen tyst medan en torr körning anropar find.
 * Objektet lever tills anroparen släpper variabeln.
 */
final class JeabKontaktMergeSkipGuard
{
    public function __construct()
    {
        $GLOBALS['_JEAB_KONTAKT_SKIP_LOG_DRY'] = (int) ($GLOBALS['_JEAB_KONTAKT_SKIP_LOG_DRY'] ?? 0) + 1;
    }

    public function __destruct()
    {
        $n = (int) ($GLOBALS['_JEAB_KONTAKT_SKIP_LOG_DRY'] ?? 0) - 1;
        if ($n <= 0) {
            unset($GLOBALS['_JEAB_KONTAKT_SKIP_LOG_DRY']);

            return;
        }
        $GLOBALS['_JEAB_KONTAKT_SKIP_LOG_DRY'] = $n;
    }
}

function jeab_kontakt_merge_skip_guard(bool $suppress): ?JeabKontaktMergeSkipGuard
{
    return $suppress ? new JeabKontaktMergeSkipGuard() : null;
}

function jeab_kontakt_merge_skip_log(string $rule, string $leftId, string $rightId, string $reason): void
{
    if ((int) ($GLOBALS['_JEAB_KONTAKT_SKIP_LOG_DRY'] ?? 0) > 0) {
        return;
    }
    $leftId = trim($leftId);
    $rightId = trim($rightId);
    $reason = trim($reason);
    if ($reason === '') {
        return;
    }
    if ($leftId === '') {
        $leftId = 'ny';
    }
    if ($rightId === '') {
        $rightId = 'ny';
    }
    $ids = [$leftId, $rightId];
    sort($ids, SORT_STRING);
    $sig = $rule . '|' . $ids[0] . '|' . $ids[1] . '|' . $reason;
    if (!isset($GLOBALS['_JEAB_KONTAKT_MERGE_SKIP']) || !is_array($GLOBALS['_JEAB_KONTAKT_MERGE_SKIP'])) {
        $GLOBALS['_JEAB_KONTAKT_MERGE_SKIP'] = [];
    }
    if (isset($GLOBALS['_JEAB_KONTAKT_MERGE_SKIP'][$sig])) {
        return;
    }
    $GLOBALS['_JEAB_KONTAKT_MERGE_SKIP'][$sig] = true;
    if (!function_exists('jeab_log_app_error')) {
        return;
    }
    jeab_log_app_error(
        'kontakter',
        'Hopslagning hoppades över. ' . $rule . '. ' . $reason . '. ' . $ids[0] . ' ' . $ids[1] . '.',
        [
            'level' => 'info',
            'no_webhook' => true,
            'skip_request' => true,
            'dedupe_seconds' => 86400,
            'fingerprint' => hash('sha256', 'kontakt_merge_skip|' . $sig),
        ]
    );
}

function jeab_kontakt_email_is_staff_or_platform(string $email): bool
{
    $email = jeab_kontakt_email($email);
    if ($email === '') {
        return false;
    }
    if (jeab_kontakt_address_blocked($email)) {
        return true;
    }
    foreach (jeab_kontakt_staff_directory() as $staff) {
        if ((string) ($staff['email'] ?? '') !== '' && (string) $staff['email'] === $email) {
            return true;
        }
    }
    $local = (string) strstr($email, '@', true);
    return preg_match('/^(?:noreply|no-reply|donotreply|info|support|admin|personal|staff)(?:[.+_-]|$)/', $local) === 1;
}

/** @param array<string, mixed> $row */
function jeab_kontakt_row_is_brf(array $row): bool
{
    $names = jeab_kontakt_row_owner_names($row);
    $top = trim((string) ($row['name'] ?? ''));
    if ($top !== '') {
        $names[] = $top;
    }
    foreach ($names as $name) {
        if (jeab_kontakt_customer_company_name($name)) {
            return true;
        }
    }
    return false;
}

/** @param array<string, mixed> $row @return list<string> */
function jeab_kontakt_row_owner_names(array $row): array
{
    $names = [];
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $name = trim((string) ($owner['name'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }
    if ($names === []) {
        $top = trim((string) ($row['name'] ?? ''));
        if ($top !== '') {
            $names[] = $top;
        }
    }
    return $names;
}

/** @param array<string, mixed> $row @return list<string> */
function jeab_kontakt_row_emails(array $row): array
{
    $out = [];
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $email = jeab_kontakt_email((string) ($owner['email'] ?? ''));
        if ($email !== '' && !isset($out[$email])) {
            $out[$email] = true;
        }
    }
    if ($out === []) {
        $email = jeab_kontakt_email((string) ($row['email'] ?? ''));
        if ($email !== '') {
            $out[$email] = true;
        }
    }
    return array_keys($out);
}

/**
 * Tom sträng betyder att paret får slås ihop.
 *
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 */
function jeab_kontakt_dedupe_pair_block(array $a, array $b, string $rule): string
{
    $left = jeab_kontakt_row_identity($a);
    $right = jeab_kontakt_row_identity($b);
    $aptA = (string) $left['apartment'];
    $aptB = (string) $right['apartment'];
    if ($aptA !== '' && $aptB !== '' && $aptA !== $aptB) {
        return 'olika lägenhetsnummer';
    }
    if ($rule === 'address' && (($aptA === '') !== ($aptB === ''))) {
        return 'lägenhetsnummer bara på en kontakt';
    }
    $brfA = jeab_kontakt_row_is_brf($a);
    $brfB = jeab_kontakt_row_is_brf($b);
    if ($brfA !== $brfB || ($brfA && $brfB)) {
        return 'brf eller samfällighet';
    }
    if ($rule === 'address') {
        $names = array_merge(jeab_kontakt_row_owner_names($a), jeab_kontakt_row_owner_names($b));
        if (jeab_kontakt_distinct_people($names) > 2) {
            return 'fler än två personer';
        }
    }
    if ($rule === 'email') {
        foreach (array_intersect(jeab_kontakt_row_emails($a), jeab_kontakt_row_emails($b)) as $email) {
            if (jeab_kontakt_email_is_staff_or_platform($email)) {
                return 'personal eller plattform';
            }
        }
    }
    $addressBlock = jeab_kontakt_channel_address_block($a, $b, $rule);
    if ($addressBlock !== '') {
        jeab_kontakt_merge_skip_log(
            $rule,
            trim((string) ($a['id'] ?? '')),
            trim((string) ($b['id'] ?? '')),
            $addressBlock
        );

        return $addressBlock;
    }

    return '';
}

function jeab_kontakt_dedupe_stamp(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $ts = strtotime($value);
    return $ts === false ? 0 : $ts;
}

/**
 * Senaste order, bokning, påminnelse eller Help Scout-ärende.
 * Utan sådan aktivitet används kontaktens eget datum.
 *
 * @param array<string, array<string, mixed>> $contacts
 * @return array<string, int>
 */
function jeab_kontakt_activity_index(array $contacts, ?JeabStore $store): array
{
    $best = [];
    $seen = [];
    $bump = static function (string $id, string $value) use (&$best, &$seen): void {
        if ($id === '') {
            return;
        }
        $ts = jeab_kontakt_dedupe_stamp($value);
        if ($ts <= 0) {
            return;
        }
        $seen[$id] = true;
        if ($ts > ($best[$id] ?? 0)) {
            $best[$id] = $ts;
        }
    };
    if ($store instanceof JeabStore) {
        foreach ($store->listReminders(true) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['contact_id'] ?? ''));
            $bump($id, (string) ($row['updated_at'] ?? ''));
            $bump($id, (string) ($row['created_at'] ?? ''));
            $bump($id, (string) ($row['due_at'] ?? ''));
        }
        foreach ($store->listAllBookings() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['contact_id'] ?? ''));
            $bump($id, (string) ($row['starts_at'] ?? ''));
            $bump($id, (string) ($row['updated_at'] ?? ''));
            $bump($id, (string) ($row['created_at'] ?? ''));
        }
    }
    if (function_exists('jeab_purchase_all') && (!function_exists('jeab_purchase_sql_live') || jeab_purchase_sql_live() instanceof PDO)) {
        foreach (jeab_purchase_all() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['contact_id'] ?? ''));
            $bump($id, (string) ($row['updated_at'] ?? ''));
            $bump($id, (string) ($row['created_at'] ?? ''));
            $bump($id, (string) ($row['closed_at'] ?? ''));
        }
    }
    if (function_exists('jeab_hs_import_index')) {
        foreach (jeab_hs_import_index()['by_id'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $bump(trim((string) ($row['contact_id'] ?? '')), (string) ($row['updated_at'] ?? ''));
        }
    }
    $inboxLive = function_exists('jeab_inkorg_sql_store_live') ? jeab_inkorg_sql_store_live() : null;
    if ($inboxLive !== null && function_exists('jeab_inkorg_threads_meta')) {
        foreach (jeab_inkorg_threads_meta() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $bump(trim((string) ($row['contact_id'] ?? '')), (string) ($row['updated_at'] ?? ''));
        }
    }
    foreach ($contacts as $id => $row) {
        $id = (string) $id;
        if (!is_array($row) || isset($seen[$id])) {
            continue;
        }
        $bump($id, (string) ($row['updated_at'] ?? ''));
        $bump($id, (string) ($row['created_at'] ?? ''));
    }
    return $best;
}

/**
 * @param array<string, array<string, mixed>> $contacts
 * @param array<string, int> $activity
 * @param list<string> $ids
 */
function jeab_kontakt_dedupe_winner(array $contacts, array $activity, array $ids): string
{
    $winner = (string) $ids[0];
    foreach ($ids as $id) {
        $id = (string) $id;
        $have = $activity[$winner] ?? 0;
        $next = $activity[$id] ?? 0;
        if ($next > $have) {
            $winner = $id;
            continue;
        }
        if ($next < $have) {
            continue;
        }
        $winnerStreet = trim((string) ($contacts[$winner]['street_key'] ?? ''));
        $nextStreet = trim((string) ($contacts[$id]['street_key'] ?? ''));
        if ($winnerStreet === '' && $nextStreet !== '') {
            $winner = $id;
        }
    }
    return $winner;
}

/**
 * @param array<string, string> $parent
 */
function jeab_kontakt_dedupe_find(array &$parent, string $id): string
{
    if (($parent[$id] ?? $id) !== $id) {
        $parent[$id] = jeab_kontakt_dedupe_find($parent, $parent[$id]);
    }
    return $parent[$id] ?? $id;
}

/**
 * @param array<string, array<string, mixed>> $contacts
 * @return array{merges:list<array{from:string,to:string,rule:string,reason:string}>,uncertain:list<array{ids:list<string>,rule:string,reason:string}>,needs_review:list<array{ids:list<string>,rule:string,reason:string}>}
 */
function jeab_kontakt_dedupe_plan(array $contacts, ?JeabStore $store = null): array
{
    $rows = [];
    foreach ($contacts as $id => $row) {
        if (is_array($row)) {
            $row['id'] = (string) ($row['id'] ?? $id);
            $rows[(string) $id] = $row;
        }
    }
    $activity = jeab_kontakt_activity_index($rows, $store);
    $merges = [];
    $uncertain = [];
    $needsReview = [];
    $parent = [];
    foreach (array_keys($rows) as $id) {
        $parent[$id] = $id;
    }
    $noteUncertain = static function (array $ids, string $rule, string $reason) use (&$uncertain): void {
        $ids = array_values(array_unique(array_filter($ids, static fn(string $id): bool => $id !== '')));
        sort($ids);
        if (count($ids) < 2 || $reason === '') {
            return;
        }
        $uncertain[] = ['ids' => $ids, 'rule' => $rule, 'reason' => $reason];
    };
    $noteReview = static function (array $ids, string $reason) use (&$needsReview): void {
        $ids = array_values(array_unique(array_filter($ids, static fn(string $id): bool => $id !== '')));
        sort($ids);
        if (count($ids) < 2 || $reason === '') {
            return;
        }
        $needsReview[] = ['ids' => $ids, 'rule' => 'address', 'reason' => $reason];
    };
    $buckets = [];
    foreach ($rows as $id => $row) {
        $identity = jeab_kontakt_row_identity($row);
        if ($identity['key'] === '') {
            continue;
        }
        $buckets[$identity['key']][] = $id;
    }
    foreach ($buckets as $ids) {
        $n = count($ids);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $block = jeab_kontakt_dedupe_pair_block($rows[$ids[$i]], $rows[$ids[$j]], 'address');
                if ($block !== '') {
                    $noteUncertain([(string) $ids[$i], (string) $ids[$j]], 'address', $block);
                    continue;
                }
                $left = jeab_kontakt_dedupe_find($parent, (string) $ids[$i]);
                $right = jeab_kontakt_dedupe_find($parent, (string) $ids[$j]);
                if ($left !== $right) {
                    $parent[$right] = $left;
                }
            }
        }
    }
    $clusters = [];
    $grouped = [];
    foreach ($buckets as $ids) {
        foreach ($ids as $id) {
            $grouped[(string) $id] = true;
        }
    }
    foreach (array_keys($grouped) as $id) {
        $clusters[jeab_kontakt_dedupe_find($parent, (string) $id)][] = (string) $id;
    }
    $consumed = [];
    foreach ($clusters as $members) {
        if (count($members) < 2) {
            continue;
        }
        $winner = jeab_kontakt_dedupe_winner($rows, $activity, $members);
        foreach ($members as $id) {
            if ($id === $winner) {
                continue;
            }
            $merges[] = ['from' => $id, 'to' => $winner, 'rule' => 'address', 'reason' => 'samma adress'];
            $consumed[$id] = true;
        }
    }
    $emailBuckets = [];
    foreach ($rows as $id => $row) {
        if (isset($consumed[$id])) {
            continue;
        }
        foreach (jeab_kontakt_row_emails($row) as $email) {
            if (jeab_kontakt_email_is_staff_or_platform($email)) {
                $emailBuckets['staff:' . $email][] = $id;
                continue;
            }
            $emailBuckets[$email][] = $id;
        }
    }
    foreach ($emailBuckets as $key => $ids) {
        $ids = array_values(array_unique($ids));
        if (count($ids) < 2) {
            continue;
        }
        if (str_starts_with((string) $key, 'staff:')) {
            $noteUncertain($ids, 'email', 'personal eller plattform');
            continue;
        }
        $emailParent = [];
        foreach ($ids as $id) {
            $emailParent[$id] = $id;
        }
        $n = count($ids);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $block = jeab_kontakt_dedupe_pair_block($rows[$ids[$i]], $rows[$ids[$j]], 'email');
                if ($block !== '') {
                    $noteUncertain([(string) $ids[$i], (string) $ids[$j]], 'email', $block);
                    continue;
                }
                $left = jeab_kontakt_dedupe_find($emailParent, (string) $ids[$i]);
                $right = jeab_kontakt_dedupe_find($emailParent, (string) $ids[$j]);
                if ($left !== $right) {
                    $emailParent[$right] = $left;
                }
            }
        }
        $emailClusters = [];
        foreach ($ids as $id) {
            $emailClusters[jeab_kontakt_dedupe_find($emailParent, (string) $id)][] = (string) $id;
        }
        foreach ($emailClusters as $members) {
            if (count($members) < 2) {
                continue;
            }
            $winner = jeab_kontakt_dedupe_winner($rows, $activity, $members);
            foreach ($members as $id) {
                if ($id === $winner || isset($consumed[$id])) {
                    continue;
                }
                $merges[] = ['from' => $id, 'to' => $winner, 'rule' => 'email', 'reason' => 'samma e-post'];
                $consumed[$id] = true;
            }
        }
    }
    $locBuckets = [];
    foreach ($rows as $id => $row) {
        if (isset($consumed[$id])) {
            continue;
        }
        $loc = jeab_kontakt_effective_street_key((string) ($row['street'] ?? ''), (string) ($row['street_key'] ?? ''));
        if ($loc === '') {
            continue;
        }
        $locBuckets[$loc][] = $id;
    }
    foreach ($locBuckets as $ids) {
        $n = count($ids);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $left = $rows[$ids[$i]];
                $right = $rows[$ids[$j]];
                $postalA = (string) ($left['postal_code'] ?? '');
                $postalB = (string) ($right['postal_code'] ?? '');
                $cityA = (string) ($left['city'] ?? '');
                $cityB = (string) ($right['city'] ?? '');
                if (jeab_kontakt_place_confirmed($postalA, $cityA, $postalB, $cityB)) {
                    continue;
                }
                if (jeab_kontakt_postal($postalA) !== '' && jeab_kontakt_postal($postalB) !== '') {
                    continue;
                }
                $townA = jeab_kontakt_city_key($cityA);
                $townB = jeab_kontakt_city_key($cityB);
                $reason = ($townA !== '' && $townB !== '' && $townA !== $townB)
                    ? 'saknar postnummer och orten skiljer'
                    : 'saknar postnummer och ort';
                $noteReview([(string) $ids[$i], (string) $ids[$j]], $reason);
            }
        }
    }
    return [
        'merges' => $merges,
        'uncertain' => jeab_kontakt_dedupe_unique_uncertain($uncertain),
        'needs_review' => jeab_kontakt_dedupe_unique_uncertain($needsReview),
    ];
}

/**
 * @param list<array{ids:list<string>,rule:string,reason:string}> $rows
 * @return list<array{ids:list<string>,rule:string,reason:string}>
 */
function jeab_kontakt_dedupe_unique_uncertain(array $rows): array
{
    $out = [];
    $seen = [];
    foreach ($rows as $row) {
        $ids = $row['ids'];
        sort($ids);
        $sig = $row['rule'] . '|' . $row['reason'] . '|' . implode(',', $ids);
        if (isset($seen[$sig])) {
            continue;
        }
        $seen[$sig] = true;
        $out[] = ['ids' => $ids, 'rule' => $row['rule'], 'reason' => $row['reason']];
    }
    return $out;
}

function jeab_kontakt_phone_display(string $phone): string
{
    $phone = jeab_kontakt_phone($phone);
    if (preg_match('/^\+46(\d{8,10})$/', $phone, $m) === 1) {
        $local = '0' . $m[1];
        if (strlen($local) >= 4) {
            return substr($local, 0, 3) . '-' . substr($local, 3);
        }
        return $local;
    }
    return trim($phone);
}

/** @param array<string, mixed> $row */
function jeab_kontakt_removal_line(array $row, ?string $date = null): string
{
    $date = $date ?? substr((function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s')), 0, 10);
    $names = jeab_kontakt_row_owner_names($row);
    $parts = [];
    if ($names !== []) {
        $parts[] = implode(' och ', $names);
    }
    $street = trim((string) ($row['street'] ?? ''));
    if ($street !== '') {
        $parts[] = $street;
    }
    $city = trim((string) ($row['city'] ?? ''));
    if ($city !== '') {
        $parts[] = $city;
    }
    $email = jeab_kontakt_row_email($row);
    if ($email !== '') {
        $parts[] = $email;
    }
    $phone = jeab_kontakt_phone_display(jeab_kontakt_row_phone($row));
    if ($phone !== '') {
        $parts[] = $phone;
    }
    foreach (jeab_kontakt_external_ref_labels($row) as $ref) {
        $parts[] = $ref;
    }
    if ($parts === []) {
        return '';
    }
    return $date . ' sammanslagen: ' . implode(', ', $parts);
}

function jeab_kontakt_append_ovrigt(string $current, string $extra): string
{
    $current = trim($current);
    $extra = trim($extra);
    if ($extra === '') {
        return $current;
    }
    if ($current === '') {
        return $extra;
    }
    if (str_contains($current, $extra)) {
        return $current;
    }
    return $current . "\n" . $extra;
}

/**
 * @param array<string, mixed> $keep
 * @param array<string, mixed> $drop
 * @return array<string, mixed>
 */
function jeab_kontakt_merge_rows(array $keep, array $drop, string $rule): array
{
    $before = $keep;
    $history = jeab_kontakt_append_ovrigt((string) ($keep['ovrigt'] ?? ''), (string) ($drop['ovrigt'] ?? ''));
    $line = jeab_kontakt_removal_line($drop);
    $keep['ovrigt'] = jeab_kontakt_append_ovrigt($history, $line);
    if (trim((string) ($keep['street_key'] ?? '')) === '' && trim((string) ($drop['street_key'] ?? '')) !== '') {
        foreach (['street', 'street_key', 'postal_code', 'city', 'fastighetsbeteckning'] as $field) {
            if (trim((string) ($keep[$field] ?? '')) === '') {
                $keep[$field] = (string) ($drop[$field] ?? '');
            }
        }
    }
    if ($rule === 'address') {
        $keep = jeab_kontakt_merge_address_owners($keep, $drop);
    } else {
        $keep = jeab_kontakt_fill_contact_channel($keep, $drop);
    }
    $keep = jeab_kontakt_keep_personnummer($keep, $drop);
    $keep = jeab_kontakt_preserve_external_refs($keep, $before);
    $keep['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    return $keep;
}

/**
 * Personnumret stannar på kontakten. Det skrivs inte i Övrigt och inte i loggen.
 *
 * @param array<string, mixed> $keep
 * @param array<string, mixed> $drop
 * @return array<string, mixed>
 */
function jeab_kontakt_keep_personnummer(array $keep, array $drop): array
{
    $owners = [];
    foreach (is_array($keep['owners'] ?? null) ? $keep['owners'] : [] as $owner) {
        if (is_array($owner)) {
            $owners[] = $owner;
        }
    }
    if ($owners === []) {
        $owners = [['name' => '', 'personnummer' => '', 'contact' => true, 'email' => '', 'phone' => '']];
    }
    foreach (is_array($drop['owners'] ?? null) ? $drop['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $pnr = trim((string) ($owner['personnummer'] ?? ''));
        if ($pnr === '') {
            continue;
        }
        $name = trim((string) ($owner['name'] ?? ''));
        $placed = false;
        foreach ($owners as $i => $have) {
            $haveName = trim((string) ($have['name'] ?? ''));
            $same = $name !== '' && $haveName !== '' && function_exists('jeab_kontakt_names_same') && jeab_kontakt_names_same($name, $haveName);
            if (!$same) {
                continue;
            }
            if (trim((string) ($have['personnummer'] ?? '')) === '') {
                $owners[$i]['personnummer'] = $pnr;
            }
            $placed = true;
            break;
        }
        if ($placed || count($owners) >= 2) {
            continue;
        }
        $email = jeab_kontakt_email((string) ($owner['email'] ?? ''));
        if ($email !== '' && jeab_kontakt_email_is_staff_or_platform($email)) {
            $email = '';
        }
        $owners[] = [
            'name' => $name,
            'personnummer' => $pnr,
            'contact' => false,
            'email' => $email,
            'phone' => '',
        ];
    }
    $keep['owners'] = $owners;
    return $keep;
}

/**
 * @param array<string, mixed> $keep
 * @param array<string, mixed> $drop
 * @return array<string, mixed>
 */
function jeab_kontakt_merge_address_owners(array $keep, array $drop): array
{
    $owners = [];
    foreach (is_array($keep['owners'] ?? null) ? $keep['owners'] : [] as $owner) {
        if (is_array($owner)) {
            $owners[] = $owner;
        }
    }
    if ($owners === []) {
        $owners = [['name' => '', 'personnummer' => '', 'contact' => true, 'email' => '', 'phone' => '']];
    }
    foreach (is_array($drop['owners'] ?? null) ? $drop['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $name = trim((string) ($owner['name'] ?? ''));
        $pnr = trim((string) ($owner['personnummer'] ?? ''));
        $placed = false;
        foreach ($owners as $i => $have) {
            $haveName = trim((string) ($have['name'] ?? ''));
            if ($name !== '' && $haveName !== '' && function_exists('jeab_kontakt_names_same') && jeab_kontakt_names_same($name, $haveName)) {
                if ($pnr !== '' && trim((string) ($have['personnummer'] ?? '')) === '') {
                    $owners[$i]['personnummer'] = $pnr;
                }
                $placed = true;
                break;
            }
        }
        if ($placed || $name === '') {
            continue;
        }
        if (count($owners) >= 2) {
            continue;
        }
        $email = jeab_kontakt_email((string) ($owner['email'] ?? ''));
        if ($email !== '' && jeab_kontakt_email_is_staff_or_platform($email)) {
            $email = '';
        }
        $owners[] = [
            'name' => $name,
            'personnummer' => $pnr,
            'contact' => false,
            'email' => $email,
            'phone' => '',
        ];
    }
    $keep['owners'] = $owners;
    return jeab_kontakt_fill_contact_channel($keep, $drop);
}

/**
 * @param array<string, mixed> $keep
 * @param array<string, mixed> $drop
 * @return array<string, mixed>
 */
function jeab_kontakt_fill_contact_channel(array $keep, array $drop): array
{
    $owners = is_array($keep['owners'] ?? null) ? array_values($keep['owners']) : [];
    if ($owners === [] || !is_array($owners[0])) {
        return $keep;
    }
    $slot = 0;
    foreach ($owners as $i => $owner) {
        if (is_array($owner) && !empty($owner['contact'])) {
            $slot = $i;
            break;
        }
    }
    $email = jeab_kontakt_email((string) ($owners[$slot]['email'] ?? ''));
    $phone = jeab_kontakt_phone((string) ($owners[$slot]['phone'] ?? ''));
    if ($email === '') {
        $email = jeab_kontakt_row_email($drop);
        if ($email !== '' && !jeab_kontakt_email_is_staff_or_platform($email)) {
            $owners[$slot]['email'] = $email;
        }
    }
    if ($phone === '') {
        $phone = jeab_kontakt_row_phone($drop);
        if ($phone !== '') {
            $owners[$slot]['phone'] = $phone;
        }
    }
    $keep['owners'] = $owners;
    return $keep;
}

/**
 * Slår ihop i minnet. Returnerar de hopslagningar som gjordes.
 *
 * @param array<string, array<string, mixed>> $contacts
 * @return list<array{from:string,to:string,rule:string,reason:string}>
 */
function jeab_kontakt_dedupe_fold(array &$contacts, ?JeabStore $store = null): array
{
    $plan = jeab_kontakt_dedupe_plan($contacts, $store);
    foreach ($plan['merges'] as $merge) {
        $from = (string) $merge['from'];
        $to = (string) $merge['to'];
        if (!isset($contacts[$from], $contacts[$to]) || !is_array($contacts[$from]) || !is_array($contacts[$to])) {
            continue;
        }
        $rule = (string) $merge['rule'];
        $block = jeab_kontakt_channel_address_block($contacts[$to], $contacts[$from], $rule);
        if ($block !== '') {
            jeab_kontakt_merge_skip_log($rule, $from, $to, $block);
            continue;
        }
        $contacts[$to] = jeab_kontakt_merge_rows($contacts[$to], $contacts[$from], (string) $merge['rule']);
        $contacts[$to]['id'] = $to;
        unset($contacts[$from]);
    }
    return $plan['merges'];
}

/**
 * @param array<string, array<string, mixed>> $contacts
 */
/** Testkontakt får inte bli träff för en riktig kund. */
function jeab_kontakt_match_blocks_test(array $candidate, array $row): bool
{
    if (!function_exists('jeab_inkorg_contact_is_test') || !jeab_inkorg_contact_is_test($row)) {
        return false;
    }
    if (jeab_inkorg_contact_is_test($candidate)) {
        return false;
    }
    $sawReal = false;
    foreach (jeab_kontakt_row_emails($candidate) as $email) {
        if (jeab_kontakt_email_is_staff_or_platform($email)) {
            continue;
        }
        if (function_exists('jeab_inkorg_email_is_test') && jeab_inkorg_email_is_test($email)) {
            continue;
        }
        $sawReal = true;
        break;
    }
    if ($sawReal) {
        return true;
    }
    if (jeab_kontakt_row_emails($candidate) !== []) {
        return false;
    }
    foreach (jeab_kontakt_row_owner_names($candidate) as $name) {
        if (function_exists('jeab_auto_test_name') && jeab_auto_test_name($name)) {
            return false;
        }
        if (trim($name) !== '') {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, array<string, mixed>> $contacts
 * @param list<string> $hits
 */
function jeab_kontakt_dedupe_pick(array $contacts, array $hits): ?string
{
    $hits = array_values(array_unique($hits));
    if ($hits === []) {
        return null;
    }
    if (count($hits) === 1) {
        return $hits[0];
    }
    $activity = jeab_kontakt_activity_index($contacts, function_exists('jeab_store') ? jeab_store() : null);

    return jeab_kontakt_dedupe_winner($contacts, $activity, $hits);
}

function jeab_kontakt_dedupe_match_contact(array $contacts, array $candidate): ?string
{
    $emails = [];
    $candidateEmails = function_exists('jeab_kontakt_dup_emails') ? jeab_kontakt_dup_emails($candidate) : jeab_kontakt_row_emails($candidate);
    foreach ($candidateEmails as $email) {
        if (!jeab_kontakt_email_is_staff_or_platform($email)) {
            $emails[] = $email;
        }
    }
    $emailHits = [];
    foreach ($emails as $email) {
        foreach ($contacts as $id => $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowEmails = function_exists('jeab_kontakt_dup_emails') ? jeab_kontakt_dup_emails($row) : jeab_kontakt_row_emails($row);
            if (!in_array($email, $rowEmails, true)) {
                continue;
            }
            $secondaryOnly = false;
            if (function_exists('jeab_kontakt_secondary_list')) {
                $rowSecondary = in_array($email, jeab_kontakt_secondary_list($row), true)
                    && !in_array($email, jeab_kontakt_row_emails($row), true);
                $candidateSecondary = in_array($email, jeab_kontakt_secondary_list($candidate), true)
                    && !in_array($email, jeab_kontakt_row_emails($candidate), true);
                $secondaryOnly = $rowSecondary || $candidateSecondary;
            }
            if (!$secondaryOnly && jeab_kontakt_dedupe_pair_block($candidate, $row, 'email') !== '') {
                continue;
            }
            if (jeab_kontakt_match_blocks_test($candidate, $row)) {
                continue;
            }
            $emailHits[] = (string) $id;
        }
    }
    if ($emailHits !== []) {
        return jeab_kontakt_dedupe_pick($contacts, $emailHits);
    }
    $hits = [];
    $left = jeab_kontakt_row_identity($candidate);
    if ($left['key'] !== '') {
        foreach ($contacts as $id => $row) {
            if (!is_array($row)) {
                continue;
            }
            $right = jeab_kontakt_row_identity($row);
            if ($right['key'] === '' || $left['key'] !== $right['key']) {
                continue;
            }
            if (jeab_kontakt_dedupe_pair_block($candidate, $row, 'address') !== '') {
                continue;
            }
            if (jeab_kontakt_match_blocks_test($candidate, $row)) {
                continue;
            }
            if ($emails !== []) {
                $rowEmails = [];
                $have = function_exists('jeab_kontakt_dup_emails') ? jeab_kontakt_dup_emails($row) : jeab_kontakt_row_emails($row);
                foreach ($have as $email) {
                    if (!jeab_kontakt_email_is_staff_or_platform($email)) {
                        $rowEmails[] = $email;
                    }
                }
                if ($rowEmails !== [] && array_intersect($emails, $rowEmails) === []) {
                    continue;
                }
            }
            $hits[] = (string) $id;
        }
    }

    return jeab_kontakt_dedupe_pick($contacts, $hits);
}

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed> $hint
 * @return array<string, mixed>
 */
function jeab_kontakt_take_latest_address(array $row, array $hint): array
{
    $street = trim((string) ($hint['street'] ?? ''));
    $city = trim((string) ($hint['city'] ?? ''));
    if ($street === '') {
        return $row;
    }
    $next = jeab_kontakt_address_identity($street, $city !== '' ? $city : (string) ($row['city'] ?? ''));
    $have = jeab_kontakt_row_identity($row);
    if ($next['key'] === '' || $next['key'] === $have['key']) {
        return $row;
    }
    if ($next['apartment'] !== $have['apartment']) {
        return $row;
    }
    if ($have['key'] !== '') {
        $previous = $row;
        $previous['owners'] = $row['owners'] ?? [];
        $line = jeab_kontakt_removal_line($previous);
        $line = str_replace(' sammanslagen: ', ' tidigare: ', $line);
        $row['ovrigt'] = jeab_kontakt_append_ovrigt((string) ($row['ovrigt'] ?? ''), $line);
    }
    $row['street'] = $street;
    $row['street_key'] = (string) ($hint['street_key'] ?? jeab_kontakt_street_key($street));
    if (trim((string) ($hint['postal_code'] ?? '')) !== '') {
        $row['postal_code'] = jeab_kontakt_postal((string) $hint['postal_code']);
    }
    if ($city !== '') {
        $row['city'] = $city;
    }
    $row['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    return $row;
}

function jeab_kontakt_dedupe_log(string $from, string $to, string $rule, string $reason, string $caller = ''): void
{
    if ($from === '' || $to === '' || $from === $to) {
        return;
    }
    $dir = function_exists('jeab_data_dir') ? jeab_data_dir() : '';
    if ($dir === '') {
        return;
    }
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }
    $payload = [
        'time' => function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s'),
        'from' => $from,
        'to' => $to,
        'rule' => $rule,
        'reason' => $reason,
    ];
    $caller = trim($caller);
    if ($caller !== '') {
        $payload['caller'] = $caller;
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        return;
    }
    @file_put_contents($dir . '/kontakter-dedupe.log', $json . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Senaste raderna i kontakter-dedupe.log. Bara tid, id, regel och anropare.
 *
 * @return list<array<string, string>>
 */
function jeab_kontakter_dedupe_log_lines(int $limit): array
{
    $limit = max(1, min(200, $limit));
    $dir = function_exists('jeab_data_dir') ? jeab_data_dir() : '';
    $path = $dir !== '' ? $dir . '/kontakter-dedupe.log' : '';
    if ($path === '' || !is_file($path)) {
        return [];
    }
    $raw = file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($raw)) {
        return [];
    }
    $rows = [];
    foreach ($raw as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            continue;
        }
        $public = [];
        foreach (['time', 'from', 'to', 'rule', 'reason', 'caller'] as $key) {
            if (!array_key_exists($key, $decoded)) {
                continue;
            }
            $value = $decoded[$key];
            if (!is_string($value) && !is_int($value)) {
                continue;
            }
            $public[$key] = (string) $value;
        }
        if ($public === []) {
            continue;
        }
        $rows[] = $public;
    }
    if (count($rows) > $limit) {
        $rows = array_slice($rows, -$limit);
    }

    return array_values($rows);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_dedupe_log_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $limit = array_key_exists('limit', $input) ? (int) $input['limit'] : 50;
    if ($limit < 1) {
        $limit = 1;
    }
    if ($limit > 200) {
        $limit = 200;
    }

    return [
        'ok' => true,
        'limit' => $limit,
        'lines' => jeab_kontakter_dedupe_log_lines($limit),
        'status' => 200,
    ];
}

/**
 * Pekar om lokala contact_id och loggar. Returnerar antalet flyttade länkar.
 *
 * @param list<array{from:string,to:string,rule:string,reason:string}> $merges
 */
function jeab_kontakt_dedupe_finish(array $merges, ?JeabStore $store = null): int
{
    if ($merges === []) {
        return 0;
    }
    return (int) jeab_kontakt_quiet_call(static function () use ($merges, $store): int {
        $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
        $links = 0;
        $live = function_exists('jeab_kontakter_load') ? jeab_kontakter_load() : [];
        foreach ($merges as $merge) {
            $from = (string) ($merge['from'] ?? '');
            $to = (string) ($merge['to'] ?? '');
            if ($from === '' || $to === '' || $from === $to) {
                continue;
            }
            $rule = (string) ($merge['rule'] ?? '');
            if (isset($live[$from], $live[$to]) && is_array($live[$from]) && is_array($live[$to])) {
                $block = jeab_kontakt_channel_address_block($live[$to], $live[$from], $rule);
                if ($block !== '') {
                    jeab_kontakt_merge_skip_log($rule, $from, $to, $block);
                    continue;
                }
            }
            $links += jeab_kontakt_repoint($store instanceof JeabStore ? $store : null, $from, $to);
            jeab_kontakt_dedupe_log($from, $to, (string) ($merge['rule'] ?? ''), (string) ($merge['reason'] ?? ''));
        }
        return $links;
    });
}

function jeab_kontakt_repoint(?JeabStore $store, string $from, string $to): int
{
    if ($from === '' || $to === '' || $from === $to) {
        return 0;
    }
    $links = 0;
    if ($store instanceof JeabStore) {
        foreach ($store->listReminders(true) as $row) {
            if (!is_array($row) || (string) ($row['contact_id'] ?? '') !== $from) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if (class_exists('JeabPdoStore') && $store instanceof JeabPdoStore) {
                $store->pdo()->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute([$to, $id]);
            } else {
                $store->saveReminder($id, ['contact_id' => $to]);
            }
            $links++;
        }
        foreach ($store->listAllBookings() as $row) {
            if (!is_array($row) || (string) ($row['contact_id'] ?? '') !== $from) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if (class_exists('JeabPdoStore') && $store instanceof JeabPdoStore) {
                $store->pdo()->prepare('UPDATE bookings SET contact_id = ? WHERE id = ?')->execute([$to, $id]);
            } else {
                $store->saveBooking($id, ['contact_id' => $to]);
            }
            $links++;
        }
    }
    if (function_exists('jeab_purchase_all') && function_exists('jeab_purchase_save_all')) {
        $all = jeab_purchase_all();
        $changed = false;
        foreach ($all as $key => $row) {
            if (!is_array($row) || (string) ($row['contact_id'] ?? '') !== $from) {
                continue;
            }
            $row['contact_id'] = $to;
            $all[$key] = $row;
            $changed = true;
            $links++;
        }
        if ($changed) {
            jeab_purchase_save_all($all);
        }
    }
    $links += jeab_kontakt_repoint_threads($from, $to);
    $links += jeab_kontakt_repoint_hs($from, $to);
    $links += jeab_kontakt_repoint_inbox_contacts($from, $to);
    $links += jeab_kontakt_repoint_notes($from, $to);
    return $links;
}

function jeab_kontakt_repoint_threads(string $from, string $to): int
{
    if (!function_exists('jeab_inkorg_threads_meta') || !function_exists('jeab_inkorg_thread_write')) {
        return 0;
    }
    $links = 0;
    foreach (jeab_inkorg_threads_meta() as $tid => $row) {
        if (!is_array($row) || (string) ($row['contact_id'] ?? '') !== $from) {
            continue;
        }
        jeab_inkorg_thread_write((string) $tid, static function (array $thread) use ($to): array {
            $thread['contact_id'] = $to;
            return $thread;
        });
        $links++;
    }
    return $links;
}

function jeab_kontakt_repoint_hs(string $from, string $to): int
{
    if (!function_exists('jeab_hs_import_index') || !function_exists('jeab_hs_import_read_conv')) {
        return 0;
    }
    $links = 0;
    foreach (jeab_hs_import_index()['by_id'] as $tid => $row) {
        if (!is_array($row) || (string) ($row['contact_id'] ?? '') !== $from) {
            continue;
        }
        $conv = jeab_hs_import_read_conv((string) $tid);
        if (!is_array($conv)) {
            continue;
        }
        $conv['contact_id'] = $to;
        $meta = is_array($conv['meta'] ?? null) ? $conv['meta'] : [];
        $meta['contact_id'] = $to;
        $conv['meta'] = $meta;
        if (jeab_hs_import_save_conv($conv) && function_exists('jeab_hs_import_index_row') && function_exists('jeab_hs_import_index_append')) {
            jeab_hs_import_index_append(jeab_hs_import_index_row($conv));
            $links++;
        }
    }
    return $links;
}

function jeab_kontakt_repoint_inbox_contacts(string $from, string $to): int
{
    if (function_exists('jeab_inkorg_contacts_archived') && jeab_inkorg_contacts_archived()) {
        return 0;
    }
    if (!function_exists('jeab_kontakt_inbox_read') || !function_exists('jeab_kontakt_inbox_locked')) {
        return 0;
    }
    if (!jeab_kontakter_writes_sql_only() && !function_exists('jeab_inkorg_locked')) {
        return 0;
    }
    $rows = jeab_kontakt_inbox_read();
    if ($rows === []) {
        return 0;
    }
    $data = ['contacts' => $rows];
    $links = 0;
    foreach ($data['contacts'] as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        $hit = false;
        if ((string) ($row['contact_id'] ?? '') === $from) {
            $row['contact_id'] = $to;
            $hit = true;
        }
        $notes = (string) ($row['notes'] ?? '');
        if ($notes !== '' && str_contains($notes, $from)) {
            $row['notes'] = str_replace($from, $to, $notes);
            $hit = true;
        }
        if ($hit) {
            $links++;
        }
        $data['contacts'][$id] = $row;
    }
    if ($links === 0) {
        return 0;
    }
    jeab_kontakt_inbox_locked(static function (?array $current) use ($data): array {
        unset($current);
        return [null, $data];
    });
    return $links;
}

function jeab_kontakt_repoint_notes(string $from, string $to): int
{
    if (!function_exists('jeab_notes_load') || !function_exists('jeab_notes_mutate')) {
        return 0;
    }
    $loaded = jeab_notes_load();
    $notes = is_array($loaded['notes'] ?? null) ? $loaded['notes'] : [];
    $links = 0;
    foreach ($notes as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ((string) ($row['contact_id'] ?? '') === $from || str_contains((string) ($row['body'] ?? ''), $from) || str_contains((string) ($row['title'] ?? ''), $from)) {
            $links++;
        }
    }
    if ($links === 0) {
        return 0;
    }
    jeab_notes_mutate(static function (array &$data) use ($from, $to): null {
        foreach ($data['notes'] as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((string) ($row['contact_id'] ?? '') === $from) {
                $row['contact_id'] = $to;
            }
            $body = (string) ($row['body'] ?? '');
            if ($body !== '' && str_contains($body, $from)) {
                $row['body'] = str_replace($from, $to, $body);
            }
            $title = (string) ($row['title'] ?? '');
            if ($title !== '' && str_contains($title, $from)) {
                $row['title'] = str_replace($from, $to, $title);
            }
            $data['notes'][$i] = $row;
        }
        return null;
    });
    return $links;
}

/** @param list<array{from:string,to:string,rule:string,reason:string}> $merges */
function jeab_kontakt_dedupe_follow(string $id, array $merges): string
{
    $map = [];
    foreach ($merges as $merge) {
        $map[(string) ($merge['from'] ?? '')] = (string) ($merge['to'] ?? '');
    }
    $guard = 0;
    while (isset($map[$id]) && $map[$id] !== '' && $map[$id] !== $id && $guard < 20) {
        $id = $map[$id];
        $guard++;
    }
    return $id;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_dedupe(array $input, ?JeabStore $store = null): array
{
    return jeab_kontakt_quiet_call(static function () use ($input, $store): array {
        $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
        $dry = function_exists('jeab_kontakt_dry') ? jeab_kontakt_dry($input) : true;
        $skipLog = jeab_kontakt_merge_skip_guard($dry);
        $allowMass = function_exists('jeab_kontakt_allow_mass') ? jeab_kontakt_allow_mass($input) : false;
        $contacts = function_exists('jeab_kontakter_load') ? jeab_kontakter_load() : [];
        $plan = jeab_kontakt_dedupe_plan($contacts, $store instanceof JeabStore ? $store : null);
        $preview = $contacts;
        jeab_kontakt_dedupe_fold($preview, $store instanceof JeabStore ? $store : null);
        $drop = 0;
        foreach ($contacts as $id => $_row) {
            if (!isset($preview[$id])) {
                $drop++;
            }
        }
        $total = count($contacts);
        $blocked = function_exists('jeab_kontakter_mass_delete_blocked')
            ? jeab_kontakter_mass_delete_blocked($total, $drop)
            : false;
        if (!$dry && $blocked && !$allowMass) {
            $needsReview = is_array($plan['needs_review'] ?? null) ? $plan['needs_review'] : [];

            return [
                'ok' => false,
                'dry' => false,
                'error' => function_exists('jeab_kontakter_mass_delete_error')
                    ? jeab_kontakter_mass_delete_error($total, $drop)
                    : 'Raderingen stoppades.',
                'blocked' => true,
                'merges' => $plan['merges'],
                'uncertain' => $plan['uncertain'],
                'needs_review' => $needsReview,
                'counts' => [
                    'merges' => count($plan['merges']),
                    'uncertain' => count($plan['uncertain']),
                    'needs_review' => count($needsReview),
                    'would_remove' => $drop,
                    'removed' => 0,
                    'blocked' => 1,
                ],
            ];
        }
        $links = 0;
        if (!$dry && $plan['merges'] !== [] && function_exists('jeab_kontakter_mutate')) {
            $applied = jeab_kontakter_mutate(static function (array &$live) use ($store): array {
                return jeab_kontakt_dedupe_fold($live, $store instanceof JeabStore ? $store : null);
            }, $allowMass);
            $applied = is_array($applied) ? $applied : [];
            $links = jeab_kontakt_dedupe_finish($applied, $store instanceof JeabStore ? $store : null);
            $plan['merges'] = $applied;
        }
        $address = 0;
        $email = 0;
        foreach ($plan['merges'] as $merge) {
            $rule = (string) ($merge['rule'] ?? '');
            if ($rule === 'address') {
                $address++;
            } elseif ($rule === 'email') {
                $email++;
            }
        }
        $needsReview = is_array($plan['needs_review'] ?? null) ? $plan['needs_review'] : [];
        $counts = [
            'merges' => count($plan['merges']),
            'uncertain' => count($plan['uncertain']),
            'needs_review' => count($needsReview),
            'would_remove' => $drop,
            'blocked' => $blocked ? 1 : 0,
        ];
        if (!$dry) {
            $counts['merged_address'] = $address;
            $counts['merged_email'] = $email;
            $counts['removed'] = count($plan['merges']);
            $counts['links_moved'] = $links;
        }
        return [
            'ok' => true,
            'dry' => $dry,
            'blocked' => $blocked,
            'merges' => $plan['merges'],
            'uncertain' => $plan['uncertain'],
            'needs_review' => $needsReview,
            'counts' => $counts,
        ];
    });
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_dedupe_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $result = jeab_kontakter_dedupe($input);
    $result = function_exists('jeab_kontakt_strip_secrets') ? jeab_kontakt_strip_secrets($result) : $result;
    $result['status'] = !empty($result['ok']) ? 200 : 400;
    return $result;
}

require_once __DIR__ . '/kontakter_migrate.php';
